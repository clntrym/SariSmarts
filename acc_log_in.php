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

        //  Check kung inactive ang account
        if (isset($row['status']) && strtolower($row['status']) === 'inactive') {
            $_SESSION['login_error'] = "Your account is inactive. Please contact the administrator.";
            header("Location: /SariSmart/accounts/acc_log_in.php");
            exit();
        }

        // Check password (hashed or plain)
        if (password_verify($password, $db_pass) || $password === $db_pass) {

            // Auto-hash if plain text
            if ($password === $db_pass) {
                $new_hashed = password_hash($password, PASSWORD_DEFAULT);
                $update = $conn->prepare("UPDATE users SET password = ? WHERE user_id = ?");
                $update->bind_param("ss", $new_hashed, $row['user_id']);
                $update->execute();
                $update->close();
            }

            // Save session values
            session_regenerate_id(true); // security
            $_SESSION['user_id'] = $row['user_id'];
            $_SESSION['fullname'] = $row['fullname'] ?? '';
            $_SESSION['email'] = $row['email'] ?? '';
            $_SESSION['role'] = $row['role'] ?? '';

            $_SESSION['login_success'] = "Welcome back, " . ($_SESSION['fullname'] ?: "User") . "!";
            // Redirect based on role
            switch (strtolower($row['role'])) {

                case "admin":
                    header("Location: /SariSmart/admin/dashboard.php");
                    exit();

                case "cashier":
                    header("Location: /SariSmart/cashier/pointofsales.php");
                    exit();

                default:
                    $_SESSION['login_error'] = "Invalid role assigned to this account.";
                    header("Location: /SariSmart/accounts/acc_log_in.php");
                    exit();
            }
        } else {
            $_SESSION['login_error'] = "Invalid Password";
            header("Location: /SariSmart/accounts/acc_log_in.php");
            exit();
        }
    } else {
        $_SESSION['login_error'] = "User ID / Email not found";
        header("Location: /SariSmart/accounts/acc_log_in.php");
        exit();
    }

    $stmt->close();
}


?>



<div class="container d-flex justify-content-center align-items-center vh-100">
    <div class="login-card text-center">
        <div class="login-logo">
            <img src="../asset/logo.png" alt="NCST Logo" class="img-fluid " style="width: 150px; height: 140px;">
        </div>
        <!-- <div class="login-title">Sari Smart</div> -->

        <!-- Role Label -->
        <p id="accountLabel" class="text-warning fw-bold mb-2 display-5" style="font-size: 1.25rem;">Account Login</p>

        <p class="mb-1">Please login in your account number or email address.</p>

        <!-- Display login error -->
        <?php if (!empty($loginError))
            echo $loginError; ?>
        <!-- Login Form -->
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
            <small class="form-text">Forgot your password? <a href="forgot_password.php">Click here</a></small>
        </div>
    </div>
</div>