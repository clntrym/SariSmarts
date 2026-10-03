<?php 
$email = $_POST["email"];

$token = bin2hex(random_bytes(16));
$token_hash = hash("sha256", $token);
$expiry = date("Y-m-d H:i:s", time() + 60 * 60 * 24 * 2);

require __DIR__ . "/../config.php";

$sql = "UPDATE users
        SET reset_token_hash = ?,
            reset_token_expires_at = ?
        WHERE email = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("sss", $token_hash, $expiry, $email);
$stmt->execute();

// ✅ Start proper HTML structure
echo "<!DOCTYPE html>
<html lang='en'>
<head>
<meta charset='UTF-8'>
<title>Password Reset</title>
<script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
</head>
<body style='background:#f9f9f9;'>";

if ($conn->affected_rows) {
    $mail = require __DIR__ . "/mailer.php";

    $mail->setFrom("noreply@example.com");
    $mail->addAddress($email);
    $mail->Subject = "Password Reset";
    $mail->Body = <<<END
    Click <a href="https://retailcore-2jlm.onrender.com/accounts/reset-password.php?token=$token">here</a> 
    to reset your password. This link will expire in 30 minutes.
    END;

    try {
        $mail->send();
        echo "
        <script>
        Swal.fire({
            icon: 'success',
            title: 'Reset Link Sent!',
            text: 'Please check your email inbox for the password reset link.',
            confirmButtonColor: '#3085d6'
        }).then(() => {
            window.location.href = 'acc_log_in.php';
        });
        </script>";
    } catch (Exception $e) {
        echo "
        <script>
        Swal.fire({
            icon: 'error',
            title: 'Email Sending Failed',
            text: 'Mailer error: " . addslashes($mail->ErrorInfo) . "',
            confirmButtonColor: '#d33'
        }).then(() => {
            window.location.href = 'forgot_password.php';
        });
        </script>";
    }
} else {
    // ❌ Email not found or failed
    echo "
    <script>
    Swal.fire({
        icon: 'error',
        title: 'Email Not Found',
        text: 'We could not find your email or failed to send the reset link.',
        confirmButtonText: 'Try Again',
        confirmButtonColor: '#d33'
    }).then(() => {
        window.location.href = 'forgot_password.php';
    });
    </script>";
}

echo "</body></html>";
?>
