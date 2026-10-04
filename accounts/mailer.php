<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . "/../vendor/autoload.php";

/*
|--------------------------------------------------------------------------
| MAILER
|--------------------------------------------------------------------------
|
| Credentials come from outside the web root - see
| C:\xampp\private_config\sarismart_secrets.php - which is where they already
| were. This file carried its own copy of the Gmail address and app password
| as literals, so the live password sat in the project folder: in every zip
| handed to anyone, and about to be in a public git history, where removing it
| later would not remove it from the history.
|
| platform/accounts/mailer.php has read them from the secrets file all along.
| This is the same arrangement, so there is one place to change a password and
| one place to keep it out of.
|
*/

function getMailerConfig()
{
    /*
    | The shared reader, which looks at the environment as well as the file.
    |
    | This named C:/xampp/private_config/sarismart_secrets.php outright. That
    | is a Windows path on a site that runs on Linux, so on Render the file
    | was never found, PHPMailer got an empty username and password, and
    | every message the system tried to send failed authentication -- the
    | Super Admin's approval mail among them.
    */
    $shared = is_file(__DIR__ . '/../includes/mail_settings.php')
        ? __DIR__ . '/../includes/mail_settings.php'
        : __DIR__ . '/../../includes/mail_settings.php';

    if (is_readable($shared)) {
        require_once $shared;
    }

    return function_exists('mailSettings') ? mailSettings() : [];
}


function getMailer()
{
    $config = getMailerConfig();

    $mail = new PHPMailer(true);

    $mail->isSMTP();
    $mail->Host = $config['MAIL_HOST'] ?? 'smtp.gmail.com';
    $mail->SMTPAuth = true;

    $mail->Username = $config['MAIL_USERNAME'] ?? '';
    $mail->Password = $config['MAIL_PASSWORD'] ?? '';

    /*
    | STARTTLS on 587, SSL on 465. Set MAIL_ENCRYPTION=ssl with MAIL_PORT=465
    | where a host blocks 587 -- the pair has to move together, and sending
    | STARTTLS to an SSL port hangs rather than failing, which is a bad
    | afternoon.
    */
    $encryption = strtolower(trim((string) ($config["MAIL_ENCRYPTION"] ?? "")));

    if ($encryption === "") {
        $encryption = ((int) ($config["MAIL_PORT"] ?? 587)) === 465 ? "ssl" : "tls";
    }

    $mail->SMTPSecure = $encryption === "ssl"
        ? PHPMailer::ENCRYPTION_SMTPS
        : PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = $config['MAIL_PORT'] ?? 587;

    /* Without this, a n-tilde in an employee's name arrives as "?". */
    $mail->CharSet = 'UTF-8';

    /*
    | "RetailCore HR" rather than the platform's MAIL_FROM_NAME: mail from this
    | side of the system is HR correspondence - interview invitations, contracts,
    | credentials - and the name on it is not a secret, so it stays here.
    */
    $mail->setFrom(
        $config['MAIL_USERNAME'] ?? '',
        'RetailCore HR'
    );

    $mail->isHTML(true);

    return $mail;
}
