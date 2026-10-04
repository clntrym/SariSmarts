<?php

require_once __DIR__ . "/../init.php";

/*
|--------------------------------------------------------------------------
| VERIFY A COMPANY OWNER'S EMAIL
|--------------------------------------------------------------------------
|
| Activates the Owner/Admin account created by register.php. Separate from
| verify_email.php, which verifies job applicants against the
| `applications` table.
|
*/

$state = 'invalid';
$ownerName = '';

$token = trim($_GET['token'] ?? '');

if ($token !== '') {

    $stmt = $conn->prepare("
        SELECT user_id, fullname, verification_expires_at, email_verified_at
        FROM users
        WHERE verification_token = ?
        LIMIT 1
    ");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($user) {

        $ownerName = $user['fullname'];

        if ($user['email_verified_at'] !== null) {

            $state = 'already';

        } elseif (!empty($user['verification_expires_at'])
            && strtotime($user['verification_expires_at']) < time()) {

            $state = 'expired';

        } else {

            $update = $conn->prepare("
                UPDATE users
                SET status = 'active',
                    email_verified_at = NOW(),
                    verification_token = NULL,
                    verification_expires_at = NULL
                WHERE user_id = ?
            ");
            $update->bind_param("i", $user['user_id']);

            $state = $update->execute() ? 'verified' : 'error';

            $update->close();
        }
    }
}

$messages = [
    'verified' => [
        'icon'  => 'bi-check-circle-fill',
        'color' => '#198754',
        'title' => 'Email verified',
        'body'  => 'Your email is confirmed. Our team will contact you to activate your subscription.',
    ],
    'already' => [
        'icon'  => 'bi-info-circle-fill',
        'color' => '#0d6efd',
        'title' => 'Already verified',
        'body'  => 'This email has already been confirmed. You can sign in any time.',
    ],
    'expired' => [
        'icon'  => 'bi-clock-history',
        'color' => '#fbbd23',
        'title' => 'Link expired',
        'body'  => 'This verification link is more than 48 hours old. Please contact support to get a new one.',
    ],
    'invalid' => [
        'icon'  => 'bi-x-circle-fill',
        'color' => '#dc3545',
        'title' => 'Invalid link',
        'body'  => 'This verification link is not valid. Please check the link in your email.',
    ],
    'error' => [
        'icon'  => 'bi-exclamation-triangle-fill',
        'color' => '#dc3545',
        'title' => 'Something went wrong',
        'body'  => 'We could not verify your account. Please contact support.',
    ],
];

$message = $messages[$state];

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($message['title']) ?> - RetailSync</title>
    <link rel="stylesheet" href="../bootstrap-5.3.8-dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="../bootstrap-icons-1.13.1/bootstrap-icons.css">
    <style>
        body { margin:0; font-family:Arial, sans-serif; background:#f5f7fb;
               display:flex; align-items:center; justify-content:center; min-height:100vh; }
        .card-box { background:#fff; width:90%; max-width:520px; padding:48px 44px;
                    text-align:center; border-radius:20px; box-shadow:0 10px 40px rgba(0,0,0,.10); }
        .card-box h1 { font-size:24px; font-weight:700; color:#00224c; margin:18px 0 10px; }
        .card-box p { color:#64748b; line-height:1.7; margin:0 0 28px; }
    </style>
</head>

<body>

    <div class="card-box">

        <i class="bi <?= $message['icon'] ?>" style="font-size:64px;color:<?= $message['color'] ?>;"></i>

        <h1><?= htmlspecialchars($message['title']) ?></h1>

        <p>
            <?php if ($ownerName !== '' && $state === 'verified'): ?>
                Welcome, <?= htmlspecialchars($ownerName) ?>.
            <?php endif; ?>
            <?= htmlspecialchars($message['body']) ?>
        </p>

        <?php if (in_array($state, ['verified', 'already'], true)): ?>
            <a href="/accounts/acc_log_in.php" class="btn text-white px-4 py-2" style="background:#00224c;">
                Sign In
            </a>
        <?php else: ?>
            <a href="../contactUs.php" class="btn text-white px-4 py-2" style="background:#00224c;">
                Contact Support
            </a>
        <?php endif; ?>

    </div>

</body>

</html>
