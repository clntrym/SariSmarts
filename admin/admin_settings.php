<?php
require_once("../init.php");
include("admin_header.php");

if (!isset($_SESSION['user_id'])) {
    header("Location: ../accounts/acc_log_in.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$role = ucfirst($_SESSION['role'] ?? '');

// Fetch user info safely
$stmt = $conn->prepare("SELECT user_id, fullname, email, contact, role, password FROM users WHERE user_id = ?");
$stmt->bind_param("s", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if (!$user) {
    $user = [
        'user_id' => $user_id,
        'fullname' => '',
        'email' => '',
        'contact' => '',
        'role' => $role,
        'password' => ''
    ];
}

$update_success = false;
$update_error = false;
$password_error = false;

// ===========================
// Handle Update
// ===========================
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // Detect which form submitted
    if (isset($_POST['update_account'])) {
        // 🟢 Account Info Update
        $fullname = trim($_POST['fullname'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $contact = trim($_POST['contact'] ?? '');

        if ($fullname && $email) {
            $update = $conn->prepare("UPDATE users SET fullname=?, email=?, contact=? WHERE user_id=?");
            $update->bind_param("ssss", $fullname, $email, $contact, $user_id);
            if ($update->execute())
                $update_success = true;
            else
                $update_error = true;
            $update->close();
        }

    } elseif (isset($_POST['update_password'])) {
        // 🔒 Password Update Only
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if (!password_verify($current_password, $user['password'])) {
            $password_error = "Your current password is incorrect.";
        } elseif ($new_password !== $confirm_password) {
            $password_error = "New passwords do not match.";
        } elseif (strlen($new_password) < 6) {
            $password_error = "New password must be at least 6 characters.";
        } else {
            $hashed_new_password = password_hash($new_password, PASSWORD_DEFAULT);
            $update = $conn->prepare("UPDATE users SET password=? WHERE user_id=?");
            $update->bind_param("ss", $hashed_new_password, $user_id);
            if ($update->execute())
                $update_success = true;
            else
                $update_error = true;
            $update->close();
        }
    }

    // Re-fetch updated info
    $stmt = $conn->prepare("SELECT user_id, fullname, email, contact, role, password FROM users WHERE user_id=?");
    $stmt->bind_param("s", $user_id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc() ?: $user;
    $stmt->close();
}
?>
<style>
    .card {
        border-radius: 18px;
    }

    .form-control,
    .form-select {
        border-radius: 10px;
    }

    .btn {
        border-radius: 10px;
    }

    .btn-primary {
        background: #17327D;
        border: none;
    }

    .btn-primary:hover {
        background: #11265f;
    }

    .btn-outline-primary:checked,
    .btn-check:checked+.btn {
        background: #17327D;
        border-color: #17327D;
    }

    textarea {
        resize: none;
    }

    .list-unstyled li {
        font-size: .92rem;
    }

    .table-scroll {
        max-height: 500px;
        /* adjust height */
        overflow-y: auto;
        overflow-x: auto;
    }

    .table-scroll thead th {
        position: sticky;
        top: 0;
        z-index: 10;
        background: #fff;
    }

    @media(max-width:992px) {

        .text-end {
            text-align: center !important;
        }

        .text-end .btn {
            width: 100%;
            margin-bottom: 10px;
        }

    }
</style>
<div class="container-fluid py-1">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <div>
            <h1 class=" mb-0 fw-bold" style="color: #00224c;">
                Settings
            </h1>
            <!-- <p class="text-muted mb-0">
                Manage staff accounts and roles.
            </p> -->
        </div>
    </div>

    <div class="row mb-3">
        <div class="col-md-6 mb-3">
            <div class="card card-blue shadow h-100 py-2">
                <div class="card-body">
                    <h3 class="fw-bold mb-3" style="color: #545454;">Account Settings</h3>
                    <form method="POST" id="accountForm">
                        <input type="hidden" name="update_account" value="1">


                        <div class="mb-3">
                            <label for="fullname" class="form-label">Full Name</label>
                            <input type="text" id="fullname" name="fullname" class="form-control"
                                value="<?= htmlspecialchars($user['fullname']) ?>" required>
                        </div>

                        <div class="mb-3">
                            <label for="department" class="form-label">Department</label>
                            <input type="text" id="department" name="department" class="form-control"
                                value="<?= htmlspecialchars($user['role']) ?> Department" readonly>
                        </div>

                        <div class="mb-3">
                            <label for="email" class="form-label">Email Address</label>
                            <input type="email" id="email" name="email" class="form-control"
                                value="<?= htmlspecialchars($user['email']) ?>" required>
                        </div>

                        <div class="mb-3">
                            <label for="contact" class="form-label">Contact Number</label>
                            <input type="text" id="contact" name="contact" class="form-control"
                                value="<?= htmlspecialchars($user['contact']) ?>">
                        </div>

                        <div class="d-grid gap-2    ">
                            <button type="button" id="saveAccountBtn" class="btn btn-primary px-4 py-2 rounded-3">Save
                                Changes</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-md-6 mb-3">
            <div class="card card-blue shadow py-2">
                <div class="card-body">
                    <h3 class="fw-bold mb-3" style="color: #545454;">Change Password</h3>
                    <form method="POST" id="passwordForm">
                        <input type="hidden" name="update_password" value="1">
                        <div class="password-section">
                            <div class="mb-3">
                                <label for="current_password" class="form-label">Current Password</label>
                                <div class="password-wrapper">
                                    <input type="password" id="current_password" name="current_password"
                                        class="form-control" placeholder="Enter current password">
                                    <i class="bi bi-eye-slash toggle-password" data-target="current_password"></i>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="new_password" class="form-label">New Password</label>
                                <div class="password-wrapper">
                                    <input type="password" id="new_password" name="new_password" class="form-control"
                                        placeholder="Enter new password">
                                    <i class="bi bi-eye-slash toggle-password" data-target="new_password"></i>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="confirm_password" class="form-label">Confirm New Password</label>
                                <div class="password-wrapper">
                                    <input type="password" id="confirm_password" name="confirm_password"
                                        class="form-control" placeholder="Re-enter new password">
                                    <i class="bi bi-eye-slash toggle-password" data-target="confirm_password"></i>
                                </div>
                            </div>
                        </div>

                        <div class="d-grid gap-2">
                            <button type="button" id="savePasswordBtn" class="btn btn-primary px-4 py-2 rounded-3">Save
                                Changes</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>


<!-- SweetAlert Feedback -->
<?php if ($update_success): ?>
    <script>
        Swal.fire({
            icon: 'success',
            title: 'Settings Updated!',
            text: 'Your account information has been successfully saved.',
            confirmButtonColor: '#ffc107'
        });
    </script>
<?php elseif ($update_error): ?>
    <script>
        Swal.fire({
            icon: 'error',
            title: 'Update Failed',
            text: 'Something went wrong while saving your settings.',
            confirmButtonColor: '#ffc107'
        });
    </script>
<?php elseif ($password_error): ?>
    <script>
        Swal.fire({
            icon: 'error',
            title: 'Password Update Failed',
            text: '<?= $password_error ?>',
            confirmButtonColor: '#ffc107'
        });
    </script>
<?php endif; ?>

<style>
    .password-wrapper {
        position: relative;
    }

    .password-wrapper .toggle-password {
        position: absolute;
        top: 50%;
        right: 10px;
        transform: translateY(-50%);
        cursor: pointer;
        color: #666;
    }

    .password-wrapper .toggle-password:hover {
        color: #000;
    }
</style>

<script>
    document.querySelectorAll('.toggle-password').forEach(icon => {
        icon.addEventListener('click', function () {
            const target = document.getElementById(this.dataset.target);
            const isPassword = target.type === 'password';
            target.type = isPassword ? 'text' : 'password';
            this.classList.toggle('bi-eye');
            this.classList.toggle('bi-eye-slash');
        });
    });

    // Account Settings Confirmation
    document.getElementById("saveAccountBtn").addEventListener("click", function () {

        Swal.fire({
            title: "Save Changes?",
            text: "Do you want to update your account information?",
            icon: "question",
            showCancelButton: true,
            confirmButtonColor: "#17327D",
            cancelButtonColor: "#6c757d",
            confirmButtonText: "Yes, Save",
            cancelButtonText: "Cancel"
        }).then((result) => {

            if (result.isConfirmed) {
                document.getElementById("accountForm").submit();
            }

        });

    });


    // Change Password Confirmation
    document.getElementById("savePasswordBtn").addEventListener("click", function () {

        Swal.fire({
            title: "Change Password?",
            text: "Are you sure you want to update your password?",
            icon: "warning",
            showCancelButton: true,
            confirmButtonColor: "#17327D",
            cancelButtonColor: "#6c757d",
            confirmButtonText: "Yes, Update",
            cancelButtonText: "Cancel"
        }).then((result) => {

            if (result.isConfirmed) {
                document.getElementById("passwordForm").submit();
            }

        });

    });
</script>

<?php include("admin_footer.php"); ?>