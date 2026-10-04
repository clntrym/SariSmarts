<?php
/*
|--------------------------------------------------------------------------
| THE MAILER'S CREDENTIALS
|--------------------------------------------------------------------------
|
| One reader, for both mailers.
|
| It exists because getMailerConfig() named
| C:/xampp/private_config/sarismart_secrets.php -- a Windows path, on a site
| that runs on Linux. There is no C: on Render, so the file was never found,
| PHPMailer was handed an empty username and an empty password, SMTP
| authentication failed, and every message the deployed system tried to send
| threw: the Super Admin's approval of a subscription application, the
| verification mail, the password reset.
|
| The throw was logged. That is the only reason this was findable. But a log
| is read when somebody already suspects something, and what was missing was
| an email nobody knew to expect.
|
| Same fault, same shape and same fix as the API key: read the environment as
| well as the file, with the file winning where both speak, so a developer's
| machine keeps its secrets outside the webroot and a container gets them
| from its own settings.
|
| Gmail needs an App Password here, not the account password: a Google
| account with 2-Step Verification on refuses a plain password over SMTP, and
| refuses it with the same "authentication failed" as a wrong one.
*/

if (!function_exists('mailSettingsPath')) {

    function mailSettingsPath(): string
    {
        return (string) ($GLOBALS['mail_settings_path']
            ?? 'C:/xampp/private_config/sarismart_secrets.php');
    }
}

if (!function_exists('mailSettingsCache')) {

    function &mailSettingsCache(): array
    {
        static $cache = [];

        return $cache;
    }
}

if (!function_exists('mailSettingsForget')) {

    /* Only tests need this; a request reads the settings once. */
    function mailSettingsForget(): void
    {
        $cache = &mailSettingsCache();
        $cache = [];
    }
}

if (!function_exists('mailSettings')) {

    function mailSettings(): array
    {
        $cache = &mailSettingsCache();
        $path = mailSettingsPath();

        if (array_key_exists($path, $cache)) {
            return $cache[$path];
        }

        $file = is_readable($path) ? (array) require $path : [];

        $environment = [];

        foreach (['MAIL_HOST', 'MAIL_PORT', 'MAIL_USERNAME', 'MAIL_PASSWORD',
                  'MAIL_FROM_NAME', 'MAIL_FROM_ADDRESS'] as $name) {

            $value = getenv($name);

            if ($value === false || trim((string) $value) === '') {
                continue;
            }

            $environment[$name] = trim((string) $value);
        }

        $settings = $file + $environment;

        /*
        | Defaults for the two that have an obvious right answer. The account
        | and its password have none -- a default there would be a silent
        | attempt to send as somebody else.
        */
        $settings['MAIL_HOST'] = $settings['MAIL_HOST'] ?? 'smtp.gmail.com';

        /* A port is compared and passed as a number; "587" would travel as a
           string and PHPMailer would take it, but a settings array that lies
           about its types is a trap for the next reader. */
        $settings['MAIL_PORT'] = (int) ($settings['MAIL_PORT'] ?? 587);

        return $cache[$path] = $settings;
    }
}

if (!function_exists('mailSettingsReady')) {

    /**
     * Whether there is enough here to attempt a send.
     *
     * Worth asking before trying: PHPMailer's failure for "no credentials" is
     * the same exception as its failure for "wrong credentials", and the two
     * want very different things done about them.
     */
    function mailSettingsReady(): bool
    {
        $settings = mailSettings();

        return trim((string) ($settings['MAIL_USERNAME'] ?? '')) !== ''
            && trim((string) ($settings['MAIL_PASSWORD'] ?? '')) !== '';
    }
}
