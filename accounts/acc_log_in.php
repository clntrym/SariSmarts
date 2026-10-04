<?php
require_once("../init.php");
include("acc_header.php");

// Logout Success Alert
if (isset($_SESSION['logout_success'])) {
    ?>
    <script>
        document.addEventListener("DOMContentLoaded", function () {
            Swal.fire({
                icon: "success",
                title: "Logged Out",
                text: "<?= htmlspecialchars($_SESSION['logout_success'], ENT_QUOTES) ?>",
                timer: 2000,
                showConfirmButton: false
            });
        });
    </script>
    <?php
    unset($_SESSION['logout_success']);
}

/*
| login_error and session_expired were being set in several places but
| never rendered, so a rejected sign-in bounced back to this page with no
| explanation at all — wrong password, unknown email and an inactive
| subscription all looked identical.
*/
if (isset($_SESSION['login_error'])) {
    ?>
    <script>
        document.addEventListener("DOMContentLoaded", function () {
            Swal.fire({
                icon: "error",
                title: "Unable to Sign In",
                text: "<?= htmlspecialchars($_SESSION['login_error'], ENT_QUOTES) ?>",
                confirmButtonColor: "#00224c"
            });
        });
    </script>
    <?php
    unset($_SESSION['login_error']);
}

if (isset($_SESSION['session_expired'])) {
    ?>
    <script>
        document.addEventListener("DOMContentLoaded", function () {
            Swal.fire({
                icon: "warning",
                title: "Session Ended",
                text: "<?= htmlspecialchars($_SESSION['session_expired'], ENT_QUOTES) ?>",
                confirmButtonColor: "#00224c"
            });
        });
    </script>
    <?php
    unset($_SESSION['session_expired']);
}
?>
<?php
// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $user_id = $_POST['email'];
    $password = $_POST['password'];

    // Prepare statement to fetch user
    $stmt = $conn->prepare("SELECT * FROM users WHERE user_id = ? OR email = ? LIMIT 1");
    $stmt->bind_param("ss", $user_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows == 1) {
        $row = $result->fetch_assoc();
        $db_pass = $row['password'];

        // Every stored password is a bcrypt hash. There used to be a
        // plain-text fallback here for accounts that predated hashing;
        // it was removed once all of them were migrated, because the
        // users.password column still defaults to a literal '123456'
        // and that fallback would have accepted it.
        if (password_verify($password, $db_pass)) {

            /*
            | Only an active account may come in.
            |
            | This used to refuse the single word 'inactive' while Deactivate
            | wrote 'disabled', so deactivating a user did nothing at all. The
            | rule now lives in one function that allows exactly one value and
            | refuses every other.
            |
            | It runs AFTER the password is verified on purpose: checking it
            | first told anyone who typed an email whether that account existed
            | and was disabled, without needing the password.
            */
            if (!accountStatusAllowsAccess($row['status'] ?? null)) {
                $_SESSION['login_error'] = accountAccessMessage($row['status'] ?? null);
                header("Location: /acc_log_in");
                exit();
            }

            // Save session values
            session_regenerate_id(true); // security
            $_SESSION['user_id'] = $row['user_id'];
            $_SESSION['fullname'] = $row['fullname'] ?? '';
            $_SESSION['email'] = $row['email'] ?? '';
            $_SESSION['role'] = $row['role'] ?? '';
            $_SESSION['employee_id'] = $row['employee_id'] ?? null;
            $_SESSION['company_id'] = $row['company_id'] ?? null;
            $_SESSION['company_name'] = null;

            /*
            |--------------------------------------------------------------
            | SUBSCRIPTION GATE
            |--------------------------------------------------------------
            |
            | Staff accounts belong to a company, and that company has to
            | be active with a live subscription to get into the app.
            | Super Admin runs the platform itself and has no company, so
            | it is deliberately exempt — otherwise an expired tenant
            | could lock the operator out of the billing screens that fix
            | the very problem.
            |
            */

            if (!empty($_SESSION['company_id'])) {

                $companyStmt = $conn->prepare("
                    SELECT c.company_name, c.status, c.review_reason,
                           cs.status AS subscription_status,
                           cs.expiry_date
                    FROM company c
                    LEFT JOIN company_subscriptions cs
                        ON cs.company_id = c.company_id
                    WHERE c.company_id = ?
                    ORDER BY cs.expiry_date DESC
                    LIMIT 1
                ");

                $companyStmt->bind_param("i", $_SESSION['company_id']);
                $companyStmt->execute();
                $company = $companyStmt->get_result()->fetch_assoc();
                $companyStmt->close();

                $blockReason = null;

                if (!$company) {
                    $blockReason = "Your company account could not be found. Please contact support.";
                } else {

                    $_SESSION['company_name'] = $company['company_name'];

                    if ($company['status'] === 'Pending') {
                        $blockReason = "Your application is still under review. We will email you once it is decided.";
                    } elseif ($company['status'] === 'Rejected') {
                        $blockReason = "Your application needs changes: "
                            . ($company['review_reason'] ?: 'please check the email we sent you.');
                    } elseif ($company['status'] === 'Suspended') {
                        $blockReason = "Your company account is suspended. Please contact support.";
                    } elseif ($company['status'] === 'Inactive') {
                        $blockReason = "Your company account is inactive. Please contact support.";
                    } elseif ($company['subscription_status'] === null) {
                        $blockReason = "Your company has no subscription yet. Please contact your administrator.";
                    } elseif ($company['subscription_status'] === 'Pending') {
                        $blockReason = "Your subscription is not active yet. Our team will contact you to complete it.";
                    } elseif (in_array($company['subscription_status'], ['Expired', 'Cancelled'], true)) {
                        $blockReason = "Your company's subscription has ended. Please renew to continue.";
                    } elseif (!empty($company['expiry_date']) && $company['expiry_date'] < date('Y-m-d')) {
                        $blockReason = "Your company's subscription expired on "
                            . date('M d, Y', strtotime($company['expiry_date']))
                            . ". Please renew to continue.";
                    }
                }

                /*
                | An approved business is allowed to sign in even before it has
                | paid. requireRole() then routes it to the subscription page
                | instead of the app, so the owner subscribes themselves rather
                | than waiting for the Super Admin to do it for them.
                */
                $_SESSION['subscription_active'] = $company
                    && $company['status'] === 'Active'
                    && in_array($company['subscription_status'], ['Active', 'Trial'], true)
                    && (empty($company['expiry_date']) || $company['expiry_date'] >= date('Y-m-d'));

                if ($blockReason === null && empty($_SESSION['subscription_active'])) {
                    header("Location: /platform/subscribe.php");
                    exit();
                }

                if ($blockReason !== null) {
                    session_unset();
                    session_destroy();
                    session_start();
                    $_SESSION['login_error'] = $blockReason;
                    header("Location: " . "/accounts/acc_log_in.php");
                    exit();
                }
            }

            // Get branch assigned to the user's employee record
            $_SESSION['branch_id'] = null;

            if (!empty($row['employee_id'])) {

                $branchStmt = $conn->prepare("
                    SELECT branch_id
                    FROM employees
                    WHERE employee_id = ?
                    LIMIT 1
                ");

                $branchStmt->bind_param("i", $row['employee_id']);
                $branchStmt->execute();

                $branchResult = $branchStmt->get_result();

                if ($branchResult->num_rows === 1) {
                    $branchRow = $branchResult->fetch_assoc();
                    $_SESSION['branch_id'] = $branchRow['branch_id'];
                }

                $branchStmt->close();
            }

            $_SESSION['login_success'] = "Welcome back, " . ($_SESSION['fullname'] ?: "User") . "!";
            // Redirect based on role
            switch (strtolower($row['role'])) {

                case "admin":
                    header("Location: /admin/dashboard.php");
                    exit();

                case "hr":
                    header("Location: /hr/dashboard.php");
                    exit();

                case "finance":
                    header("Location: /finance/dashboard.php");
                    exit();

                case "inventory":
                    header("Location: /inventory/dashboard.php");
                    exit();

                case "cashier":
                    header("Location: /cashier/pointofsales.php");
                    exit();

                /*
                | Platform-side staff. The slugs are deliberately not
                | "finance" or "hr": those two cases above belong to a
                | tenant's own officers and route into /SariSmarts, so
                | reusing either name here would send platform staff into
                | a customer's app.
                |
                | Each lands on the first screen its team actually works
                | in, which is also what platformLanding() returns.
                */
                case "super admin":
                    header("Location: /platform/superAdmin/dashboard.php");
                    exit();

                case "marketing hr":
                    header("Location: /platform/superAdmin/leads.php");
                    exit();

                case "platform finance":
                    header("Location: /platform/superAdmin/billing.php");
                    exit();

                default:
                    $_SESSION['login_error'] = "Invalid role assigned to this account.";
                    header("Location: /acc_log_in");
                    exit();
            }
        } else {
            $_SESSION['login_error'] = "Invalid Password";
            header("Location: /acc_log_in");
            exit();
        }
    } else {
        $_SESSION['login_error'] = "User ID / Email not found";
        header("Location: /acc_log_in");
        exit();
    }

    $stmt->close();
}


?>

<link rel="stylesheet" href="/accounts/style.css">

<div class="login-wrapper">
    <div class="login-box">
        <img src="/assets/retailcore-logo.png" class="logo" alt="Logo">
        <h2 class="welcome-title">
            Welcome to <span>Retail</span>Core!
        </h2>
        <p class="subtitle">
            Login to access your account
        </p>
        <form method="POST">
            <div class="mb-3">
                <label class="form-label">Username</label>
                <div class="input-group">
                    <span class="input-group-text">
                        <i class="fa fa-user"></i>
                    </span>
                    <input type="text" name="email" class="form-control" placeholder="Enter User ID / Email" required>
                </div>
            </div>

            <div class="mb-4">
                <label class="form-label">Password</label>
                <div class="input-group">
                    <span class="input-group-text">
                        <i class="fa fa-lock"></i>
                    </span>
                    <input type="password" name="password" id="password" class="form-control"
                        placeholder="Enter Password" required>
                    <button class="btn" type="button" onclick="showPassword()">
                        <i class="fa fa-eye-slash" id="eye"></i>
                    </button>
                </div>
            </div>
            <div class="d-flex justify-content-between mb-4">
                <div>
                    <input type="checkbox">
                    Remember me
                </div>
                <a href="/accounts/forgot_password.php">Forgot Password?</a>
            </div>
            <button class="btn btn-login w-100">
                <i class="fa fa-arrow-right-to-bracket"></i>
                Login
            </button>
        </form>
        <p class="text-center mt-4">
            Don't have an account?
            <a href="/platform/register.php">Register your business</a>
        </p>
    </div>
    <div class="footer-section">
        <div class="footer-icons">
            <div class="feature">
                <i class="fa-solid fa-chart-column"></i>
                <p>Real-time Reports</p>
            </div>
            <div class="feature">
                <i class="fa-solid fa-network-wired"></i>
                <p>Multi-Branch Management</p>
            </div>
            <div class="feature">
                <i class="fa-solid fa-users"></i>
                <p>HR & Payroll System</p>
            </div>
        </div>
        <div class="copy">
            © 2026 RetailCore Enterprise. All rights reserved.
        </div>
    </div>
</div>

<script>

    function showPassword() {
        var x = document.getElementById("password");
        var eye = document.getElementById("eye");
        if (x.type === "password") {
            x.type = "text";
            eye.classList.remove("fa-eye-slash");
            eye.classList.add("fa-eye");
        }
        else {
            x.type = "password";
            eye.classList.remove("fa-eye");
            eye.classList.add("fa-eye-slash");
        }

    }

</script>

<!-- <div class="container d-flex justify-content-center align-items-center vh-100">
    <div class="login-card text-center">
        <div class="login-logo">
            <img src="/asset/logo.png" alt="NCST Logo" class="img-fluid " style="width: 150px; height: 140px;">
        </div>

        <p id="accountLabel" class="text-warning fw-bold mb-2 display-5" style="font-size: 1.25rem;">Account Login</p>

        <p class="mb-1">Please login in your account number or email address.</p>

        <?php if (!empty($loginError))
            echo $loginError; ?>
        <form method="POST">
            <div class="mb-3">
                <input type="text" name="email" class="form-control" placeholder="User ID / Email" required>
            </div>
            <div class="mb-3">
                <input type="password" name="password" class="form-control" placeholder="Password" required>
            </div>
            <button type="submit" class="btn btn-login w-100">Login</button>
        </form>

        <div class="mt-3">
            <small class="form-text">Forgot your password? <a href="/accounts/forgot_password.php">Click here</a></small>
        </div>
    </div>
</div> -->