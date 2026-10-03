<?php
require_once("../init.php");
include("acc_header.php");


$token = $_POST["token"] ?? null;
$token_hash = hash("sha256", $token);

require __DIR__ . "/../config.php";

$sql = "SELECT * FROM users WHERE reset_token_hash = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $token_hash);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

if ($user === null || strtotime($user["reset_token_expires_at"]) <= time()) {
    echo "<div class='login-card text-center'>
            <h3 class='login-title'>Invalid or Expired Link</h3>
            <p>Please request a new password reset <a href='forgot_password.php'>here</a>.</p>
          </div>";
    include("acc_footer.php");
    exit;
}

// 🔐 Validate password
if (strlen($_POST["password"]) < 8) {
    die("Password must be at least 8 characters");
}
if (!preg_match("/[a-z]/i", $_POST["password"])) {
    die("Password must contain at least one letter");
}
if (!preg_match("/[0-9]/", $_POST["password"])) {
    die("Password must contain at least one number");
}

$password_hash = password_hash($_POST["password"], PASSWORD_DEFAULT);

// ✅ Update the user password
$sql = "UPDATE users
        SET password = ?,
            reset_token_hash = NULL,
            reset_token_expires_at = NULL
        WHERE user_id = ?";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ss", $password_hash, $user["user_id"]);
$stmt->execute();

echo "<div class='login-card text-center'>
        <h3 class='login-title'>Password Updated</h3>
        <p>Your password has been successfully changed.</p>
        <a href='acc_log_in.php' class='btn btn-login mt-3 w-100'>Go to Login</a>
      </div>";

include("acc_footer.php");
?>
