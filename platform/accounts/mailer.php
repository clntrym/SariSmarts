<?php

use PHPMailer\PHPMailer\PHPMailer;

/*
|--------------------------------------------------------------------------
| MAILER
|--------------------------------------------------------------------------
|
| The autoloader is looked up in several places because Composer's vendor
| folder only ever got installed in the main application, and where that sits
| relative to this file depends on how the project was laid out.
|
| The folder names below are folder names, not the product name: the
| repository is still called SariSmarts on disk, and these paths have to match
| what is actually there. Renaming the product did not rename the directory.
|
| Locally the two projects are siblings in htdocs, so SariSmarts is one level
| up and across. In the deployed repository the platform lives INSIDE
| SariSmarts, so the vendor folder is simply one level up -- and the sibling
| path points at a directory that does not exist. That is why every platform
| page which sends an email returned a 500 on Render while working perfectly
| on XAMPP: register.php, the verification mails, the password reset.
|
| All three layouts are listed rather than one being chosen, because the file
| cannot know which it is in, and a wrong guess is a fatal error.
|
| Credentials come from outside the web root — see
| C:\xampp\private_config\sarismart_secrets.php.
|
*/

$autoloadCandidates = [
    /* platform/vendor — if Composer is ever run inside the platform itself. */
    __DIR__ . "/../vendor/autoload.php",
    /* The deployed layout: platform sits inside the SariSmarts folder. */
    __DIR__ . "/../../vendor/autoload.php",
    /* The local layout: platform and SariSmarts are siblings in htdocs. */
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
