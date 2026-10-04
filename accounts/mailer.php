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
    $secretsFile = 'C:/xampp/private_config/sarismart_secrets.php';

    return is_readable($secretsFile) ? require $secretsFile : [];
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

    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
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
