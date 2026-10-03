<?php

require_once("../init.php");

/* ===============================
   CHECK TOKEN
================================ */

if (!isset($_GET['token']) || empty($_GET['token'])) {

    die("
        <!DOCTYPE html>
        <html>
        <head>
            <title>Invalid Verification Link</title>
            <style>
                body {
                    margin:0;
                    font-family:Arial,sans-serif;
                    background:#F5F7FB;
                    display:flex;
                    justify-content:center;
                    align-items:center;
                    min-height:100vh;
                }

                .card {
                    background:#fff;
                    width:90%;
                    max-width:550px;
                    padding:45px;
                    text-align:center;
                    border-radius:20px;
                    box-shadow:0 10px 40px rgba(0,0,0,.10);
                }

                h1 {
                    color:#DC2626;
                }

                p {
                    color:#666;
                    line-height:1.6;
                }

                .btn {
                    display:inline-block;
                    margin-top:20px;
                    padding:13px 28px;
                    background:#00224C;
                    color:white;
                    text-decoration:none;
                    border-radius:10px;
                    font-weight:bold;
                }
            </style>
        </head>

        <body>

            <div class='card'>

                <h1>Invalid Verification Link</h1>

                <p>
                    The verification link is missing or invalid.
                </p>

                <a href='apply.php?page=browse' class='btn'>
                    Back to SariSmart Careers
                </a>

            </div>

        </body>
        </html>
    ");

    exit;
}

$token = trim($_GET['token']);


/* ===============================
   FIND APPLICATION
================================ */

$stmt = $conn->prepare("
    SELECT
        application_id,
        first_name,
        last_name,
        email,
        email_verified
    FROM applications
    WHERE verification_token = ?
    LIMIT 1
");

$stmt->bind_param("s", $token);
$stmt->execute();

$result = $stmt->get_result();


/* ===============================
   TOKEN NOT FOUND
================================ */

if ($result->num_rows === 0) {

    $stmt->close();

    die("
        <!DOCTYPE html>
        <html>
        <head>
            <title>Invalid Verification Link</title>

            <style>

                body {
                    margin:0;
                    font-family:Arial,sans-serif;
                    background:#F5F7FB;
                    display:flex;
                    justify-content:center;
                    align-items:center;
                    min-height:100vh;
                }

                .card {
                    background:#fff;
                    width:90%;
                    max-width:550px;
                    padding:45px;
                    text-align:center;
                    border-radius:20px;
                    box-shadow:0 10px 40px rgba(0,0,0,.10);
                }

                h1 {
                    color:#DC2626;
                }

                p {
                    color:#666;
                    line-height:1.6;
                }

                .btn {
                    display:inline-block;
                    margin-top:20px;
                    padding:13px 28px;
                    background:#00224C;
                    color:white;
                    text-decoration:none;
                    border-radius:10px;
                    font-weight:bold;
                }

            </style>

        </head>

        <body>

            <div class='card'>

                <h1>Invalid Verification Link</h1>

                <p>
                    This verification link is invalid or has already been used.
                </p>

                <a href='apply.php?page=browse' class='btn'>
                    Back to SariSmart Careers
                </a>

            </div>

        </body>
        </html>
    ");

    exit;
}


$application = $result->fetch_assoc();

$stmt->close();


/* ===============================
   ALREADY VERIFIED
================================ */

if ($application['email_verified'] === 'Yes') {

    ?>

    <!DOCTYPE html>
    <html lang="en">

    <head>

        <meta charset="UTF-8">

        <meta name="viewport"
              content="width=device-width, initial-scale=1.0">

        <title>Email Already Verified - SariSmart Careers</title>

        <style>

            body {
                margin:0;
                font-family:Arial,sans-serif;
                background:#F5F7FB;

                display:flex;
                justify-content:center;
                align-items:center;

                min-height:100vh;
            }

            .card {
                background:#fff;

                width:90%;
                max-width:550px;

                padding:45px;

                text-align:center;

                border-radius:20px;

                box-shadow:0 10px 40px rgba(0,0,0,.10);
            }

            .icon {
                width:90px;
                height:90px;

                margin:0 auto 25px;

                border-radius:50%;

                background:#FEF3C7;
                color:#F59E0B;

                display:flex;
                align-items:center;
                justify-content:center;

                font-size:50px;
            }

            h1 {
                color:#00224C;
                margin-bottom:15px;
            }

            p {
                color:#666;
                line-height:1.6;
            }

            .name {
                color:#00224C;
                font-weight:bold;
            }

            .btn {
                display:inline-block;

                margin-top:25px;

                padding:13px 28px;

                background:#00224C;
                color:white;

                text-decoration:none;

                border-radius:10px;

                font-weight:bold;
            }

        </style>

    </head>

    <body>

        <div class="card">

            <div class="icon">
                ✓
            </div>

            <h1>
                Email Already Verified
            </h1>

            <p>

                Hello
                <span class="name">
                    <?= htmlspecialchars(
                        $application['first_name'] . " " .
                        $application['last_name']
                    ); ?>
                </span>

            </p>

            <p>
                Your email address has already been verified.
            </p>

            <p>
                Your application is currently being processed
                by the SariSmart Human Resources Department.
            </p>

            <a href="apply.php?page=browse" class="btn">
                Back to SariSmart Careers
            </a>

        </div>

    </body>

    </html>

    <?php

    exit;
}


/* ===============================
   VERIFY EMAIL
================================ */

$update = $conn->prepare("
    UPDATE applications
    SET
        email_verified = 1,
        verified_at = NOW(),
        verification_token = NULL
    WHERE application_id = ?
");

$update->bind_param(
    "i",
    $application['application_id']
);


if (!$update->execute()) {

    $update->close();

    die("Something went wrong while verifying your email.");
}

$update->close();

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Email Verified - SariSmart Careers</title>

    <style>

        body {
            margin:0;

            font-family:Arial,sans-serif;

            background:#F5F7FB;

            display:flex;
            justify-content:center;
            align-items:center;

            min-height:100vh;
        }

        .card {

            background:white;

            width:90%;
            max-width:550px;

            padding:45px;

            text-align:center;

            border-radius:20px;

            box-shadow:0 10px 40px rgba(0,0,0,.10);

        }

        .icon {

            width:90px;
            height:90px;

            margin:0 auto 25px;

            border-radius:50%;

            background:#DCFCE7;

            color:#22C55E;

            display:flex;

            align-items:center;
            justify-content:center;

            font-size:50px;

        }

        h1 {

            color:#00224C;

            margin-bottom:15px;

        }

        p {

            color:#666;

            line-height:1.6;

        }

        .name {

            color:#00224C;

            font-weight:bold;

        }

        .btn {

            display:inline-block;

            margin-top:25px;

            padding:13px 28px;

            background:#00224C;

            color:white;

            text-decoration:none;

            border-radius:10px;

            font-weight:bold;

        }

        .btn:hover {

            background:#001936;

        }

    </style>

</head>

<body>

    <div class="card">

        <div class="icon">
            ✓
        </div>

        <h1>
            Email Verified!
        </h1>

        <p>

            Hello

            <span class="name">

                <?= htmlspecialchars(
                    $application['first_name'] . " " .
                    $application['last_name']
                ); ?>

            </span>

        </p>

        <p>
            Your email address has been successfully verified.
        </p>

        <p>
            Your application has been received by the
            <strong>SariSmart Human Resources Department</strong>.
        </p>

        <p>
            Our HR team will now review your application.
            You will receive further updates through your email.
        </p>

        <a href="apply.php?page=browse" class="btn">
            Back to SariSmart Careers
        </a>

    </div>

</body>

</html>