<?php

require_once("../init.php");
requireRole(['admin']);

// Adjust this path if getMailer() actually lives somewhere else in your project.
require_once("../accounts/mailer.php");

header('Content-Type: application/json');

/*
|--------------------------------------------------------------------------
| CONFIG — adjust to match your real folder name for reset-password.php
|--------------------------------------------------------------------------
*/

$resetBaseUrl = "http://localhost/SariSmarts/accounts/reset-password.php";

/*
|--------------------------------------------------------------------------
| INPUT
|--------------------------------------------------------------------------
*/

$user_id = (int) ($_POST['user_id'] ?? 0);

if ($user_id <= 0) {
    echo json_encode(["success" => false, "message" => "Invalid user."]);
    exit;
}

/*
|--------------------------------------------------------------------------
| FETCH USER
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("SELECT user_id, fullname, email FROM users WHERE user_id = ? LIMIT 1");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if (!$user) {
    echo json_encode(["success" => false, "message" => "User not found."]);
    exit;
}

if (empty($user['email'])) {
    echo json_encode(["success" => false, "message" => "This user has no email address on file."]);
    exit;
}

/*
|--------------------------------------------------------------------------
| GENERATE + STORE RESET TOKEN
|--------------------------------------------------------------------------
| The raw token is emailed to the user; only its hash is stored, matching
| how reset-password.php looks the token up (hash("sha256", $token)).
|--------------------------------------------------------------------------
*/

$token = bin2hex(random_bytes(32));
$token_hash = hash("sha256", $token);
$expires_at = date("Y-m-d H:i:s", strtotime("+1 hour"));

$update = $conn->prepare("
    UPDATE users
    SET reset_token_hash = ?, reset_token_expires_at = ?
    WHERE user_id = ?
");
$update->bind_param("ssi", $token_hash, $expires_at, $user_id);

if (!$update->execute()) {
    $update->close();
    echo json_encode(["success" => false, "message" => "Failed to generate a reset token."]);
    exit;
}

$update->close();

/*
|--------------------------------------------------------------------------
| SEND EMAIL
|--------------------------------------------------------------------------
*/

$resetLink = $resetBaseUrl . "?token=" . urlencode($token);

try {

    $mail = getMailer();

    $mail->addAddress($user['email'], $user['fullname']);

    $mail->Subject = "SariSmart — Password Reset Request";

    $safeName = htmlspecialchars($user['fullname'], ENT_QUOTES);
    $safeLink = htmlspecialchars($resetLink, ENT_QUOTES);

    $mail->Body = "
        <div style='font-family:Poppins,Arial,sans-serif; color:#0A2A63;'>
            <h2 style='margin-bottom:4px;'>Password Reset Request</h2>
            <p>Hi {$safeName},</p>
            <p>An administrator initiated a password reset for your SariSmart account.
               Click the button below to set a new password. This link expires in 1 hour.</p>
            <p style='margin:24px 0;'>
                <a href='{$safeLink}'
                   style='background:#032B63; color:#fff; padding:12px 24px; border-radius:30px;
                          text-decoration:none; font-weight:600; display:inline-block;'>
                    Reset Password
                </a>
            </p>
            <p style='font-size:13px; color:#777;'>
                If you didn't request this, you can safely ignore this email —
                your password will remain unchanged.
            </p>
        </div>
    ";

    $mail->AltBody = "Reset your SariSmart password using this link (expires in 1 hour): {$resetLink}";

    $mail->send();

    echo json_encode([
        "success" => true,
        "message" => "Reset link sent to {$user['email']}."
    ]);

} catch (Exception $e) {

    echo json_encode([
        "success" => false,
        "message" => "Failed to send email. Please try again."
    ]);
}