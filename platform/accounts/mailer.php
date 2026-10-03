<?php

use PHPMailer\PHPMailer\PHPMailer;

/*
|--------------------------------------------------------------------------
| MAILER
|--------------------------------------------------------------------------
|
| The autoloader is looked up in both projects because Composer's vendor
| folder only ever got installed under SariSmarts. This file previously
| required platform/vendor/autoload.php, which does not exist, so every
| email the platform tried to send died on a fatal error.
|
| Credentials come from outside the web root — see
| C:\xampp\private_config\sarismart_secrets.php.
|
*/

$autoloadCandidates = [
    __DIR__ . "/../vendor/autoload.php",
    __DIR__ . "/../../SariSmarts/vendor/autoload.php",
];

$autoloaderFound = false;

foreach ($autoloadCandidates as $autoloadPath) {
    if (is_readable($autoloadPath)) {
        require_once $autoloadPath;
        $autoloaderFound = true;
        break;
    }
}

if (!$autoloaderFound) {
    throw new RuntimeException(
        'PHPMailer autoloader not found. Run "composer install" so a vendor/ folder exists.'
    );
}


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

    $mail->CharSet = 'UTF-8';

    $mail->setFrom(
        $config['MAIL_USERNAME'] ?? '',
        $config['MAIL_FROM_NAME'] ?? 'RetailSync'
    );

    $mail->isHTML(true);

    return $mail;
}
