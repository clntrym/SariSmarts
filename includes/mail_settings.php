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
                  'MAIL_FROM_NAME', 'MAIL_FROM_ADDRESS', 'MAIL_ENCRYPTION',
                  'MAIL_TRANSPORT', 'BREVO_API_KEY'] as $name) {

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

        /*
        | An API key is credentials too. Without this, a host that sends
        | perfectly well over HTTP would be told "nothing can be sent"
        | because it has no SMTP password -- which it does not need.
        */
        if (mailTransportIsHttp()) {
            return true;
        }

        return trim((string) ($settings['MAIL_USERNAME'] ?? '')) !== ''
            && trim((string) ($settings['MAIL_PASSWORD'] ?? '')) !== '';
    }
}

if (!function_exists('mailTransportIsHttp')) {

    /*
    | Which way mail leaves this host.
    |
    | Render's free instances answer "Connection timed out" on 587, 465, 25
    | and 2525, and open 443 without complaint: they reach the internet and
    | block SMTP specifically. No password, port or encryption setting gets a
    | message off such a host. Mail has to travel the way every other request
    | does, over HTTPS, through a provider's API.
    |
    | The presence of an API key is the signal, because a key is only ever
    | put there by somebody who wants it used. MAIL_TRANSPORT overrides it
    | both ways -- "smtp" for a machine where SMTP works and the key is only
    | in the environment for another one, "api" to insist.
    */
    function mailTransportIsHttp(): bool
    {
        $settings = mailSettings();

        $choice = strtolower(trim((string) ($settings['MAIL_TRANSPORT'] ?? '')));

        if ($choice === 'smtp') {
            return false;
        }

        if ($choice === 'api' || $choice === 'http') {
            return true;
        }

        return trim((string) ($settings['BREVO_API_KEY'] ?? '')) !== '';
    }
}

if (!function_exists('mailApiKey')) {

    function mailApiKey(): string
    {
        return trim((string) (mailSettings()['BREVO_API_KEY'] ?? ''));
    }
}

if (!function_exists('mailApiKeyKind')) {

    /*
    | Which of Brevo's two secrets this is.
    |
    | The "SMTP & API" page hands out both, on adjacent tabs, and they look
    | alike: a prefix, 64 hex characters, a dash, 16 more. The SMTP tab's
    | master password is for the SMTP relay and is rejected by the REST API
    | with "unauthorized: Key not found" -- the same sentence as a revoked
    | key, a typo, or an account that no longer exists. So the one piece of
    | evidence that separates "wrong kind" from "wrong key" is the prefix,
    | and the prefix is not a secret.
    |
    |   xkeysib-    a v3 API key        what the REST API wants
    |   xsmtpsib-   the SMTP password   right secret, wrong door
    */
    function mailApiKeyKind(string $key): string
    {
        $key = trim($key);

        if ($key === '') {
            return 'empty';
        }

        if (str_starts_with($key, 'xkeysib-')) {
            return 'api';
        }

        if (str_starts_with($key, 'xsmtpsib-')) {
            return 'smtp';
        }

        return 'unknown';
    }
}

if (!function_exists('mailSenderDomain')) {

    function mailSenderDomain(string $email): string
    {
        $at = strrpos(trim($email), '@');

        return $at === false ? '' : strtolower(trim(substr(trim($email), $at + 1)));
    }
}

if (!function_exists('mailDmarcPolicy')) {

    /*
    | The p= tag of a DMARC record: what the domain owner tells receivers to
    | do with mail that claims to be from them and cannot be authenticated.
    |
    | Absent or malformed reads as "none", which is how receivers treat it,
    | and is the permissive answer -- guessing "reject" here would warn
    | somebody away from an address that works.
    */
    function mailDmarcPolicy(string $record): string
    {
        if (!preg_match('~^\s*v\s*=\s*DMARC1~i', $record)) {
            return 'none';
        }

        if (!preg_match('~[;\s]*\bp\s*=\s*(none|quarantine|reject)~i', $record, $found)) {
            return 'none';
        }

        return strtolower($found[1]);
    }
}

if (!function_exists('mailPolicyBlocksRelay')) {

    /*
    | Whether this policy stops a provider the domain never authorised.
    |
    | quarantine counts. It does not bounce -- it delivers to the spam
    | folder, which for an approval nobody is expecting is the same as not
    | delivering, and worse, because the sender sees a success.
    */
    function mailPolicyBlocksRelay(string $policy): bool
    {
        return in_array(strtolower(trim($policy)), ['reject', 'quarantine'], true);
    }
}

if (!function_exists('mailSenderDmarc')) {

    /**
     * The published DMARC policy of the address mail is sent from.
     *
     * Looked up live, because it is the recipient's view that decides
     * whether a message arrives, and the recipient looks it up live too.
     * Returns 'none' where there is no record, no resolver, or no answer:
     * this informs a warning, and a warning that fires on a failed lookup
     * would be noise.
     */
    function mailSenderDmarc(string $email): string
    {
        $domain = mailSenderDomain($email);

        if ($domain === '' || !function_exists('dns_get_record')) {
            return 'none';
        }

        $records = @dns_get_record('_dmarc.' . $domain, DNS_TXT);

        if (!is_array($records)) {
            return 'none';
        }

        foreach ($records as $record) {

            $text = (string) ($record['txt'] ?? implode('', (array) ($record['entries'] ?? [])));

            if (preg_match('~^\s*v\s*=\s*DMARC1~i', $text)) {
                return mailDmarcPolicy($text);
            }
        }

        return 'none';
    }
}

if (!function_exists('mailFromAddress')) {

    /*
    | Who the message is from.
    |
    | Over SMTP this was always the account being authenticated as, because
    | Gmail will not let you be anybody else. An API provider will: it sends
    | from whatever address somebody verified with them, which is usually but
    | not always the same mailbox. So it is its own setting, falling back to
    | the SMTP username rather than being assumed equal to it -- a wrong
    | sender here is not an error, it is a message that silently lands in
    | spam.
    */
    function mailFromAddress(): string
    {
        $settings = mailSettings();

        $explicit = trim((string) ($settings['MAIL_FROM_ADDRESS'] ?? ''));

        return $explicit !== ''
            ? $explicit
            : trim((string) ($settings['MAIL_USERNAME'] ?? ''));
    }
}
