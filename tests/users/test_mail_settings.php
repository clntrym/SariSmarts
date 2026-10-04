<?php
/*
| The mailer has to find its credentials on the server it runs on.
|
| Reported: no email arrives from the deployed site -- not the Super Admin's
| approval of a subscription application, not anything else.
|
| getMailerConfig() read C:/xampp/private_config/sarismart_secrets.php. That
| is a Windows path, Render runs Linux, and there is no C: there. So $config
| came back empty, PHPMailer was handed an empty username and an empty
| password, SMTP authentication failed, and every send threw. The throw IS
| logged -- which is the only reason this was findable at all -- but nobody
| reads a log until something is missing, and what was missing was an email
| nobody knew to expect.
|
| Same shape as the API key, which could not be found for the same reason.
| The fix is the same: read the environment too, with the file winning where
| both speak.
*/
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../includes/mail_settings.php';

$root = __DIR__ . '/../../';

/* ------------------------------------------------------------- the reader */

/* Nothing configured anywhere. */
foreach (['MAIL_HOST', 'MAIL_PORT', 'MAIL_USERNAME', 'MAIL_PASSWORD', 'MAIL_FROM_NAME'] as $name) {
    putenv($name);
}

$GLOBALS['mail_settings_path'] = __DIR__ . '/no_mail_secrets_in_tests.php';
mailSettingsForget();

$settings = mailSettings();

t_same('', (string) ($settings['MAIL_USERNAME'] ?? ''), 'with nothing set, there is no username');
t_ok(!mailSettingsReady(), 'and the mailer reports itself not configured');

/* The environment alone, which is all Render has. */
putenv('MAIL_USERNAME=store@example.com');
putenv('MAIL_PASSWORD=an-app-password');
mailSettingsForget();

$settings = mailSettings();

t_same('store@example.com', $settings['MAIL_USERNAME'] ?? null,
    'the username is read from the environment, as it is on Render');
t_same('an-app-password', $settings['MAIL_PASSWORD'] ?? null, 'and the password');
t_ok(mailSettingsReady(), 'and that is enough to send with');

/* The host and port have sensible defaults; the account does not. */
t_same('smtp.gmail.com', $settings['MAIL_HOST'] ?? null, 'the host defaults to Gmail');
t_same(587, $settings['MAIL_PORT'] ?? null, 'the port defaults to 587, as a number');

putenv('MAIL_HOST=smtp.mailgun.org');
putenv('MAIL_PORT=2525');
mailSettingsForget();

$settings = mailSettings();

t_same('smtp.mailgun.org', $settings['MAIL_HOST'] ?? null, 'another host can be named');
t_same(2525, $settings['MAIL_PORT'] ?? null, 'and another port, still as a number');

/* The file wins, because putting credentials in a file outside the webroot
   is the more deliberate of the two acts. */
$fixture = sys_get_temp_dir() . '/mail_settings_fixture.php';
file_put_contents($fixture, "<?php\nreturn " . var_export([
    'MAIL_USERNAME' => 'from-the-file@example.com',
], true) . ";\n");

register_shutdown_function(function () use ($fixture) { @unlink($fixture); });

$GLOBALS['mail_settings_path'] = $fixture;
mailSettingsForget();

$settings = mailSettings();

t_same('from-the-file@example.com', $settings['MAIL_USERNAME'] ?? null,
    'a file on disk beats the environment');
t_same('an-app-password', $settings['MAIL_PASSWORD'] ?? null,
    'and a key the file omits still comes from the environment');

foreach (['MAIL_HOST', 'MAIL_PORT', 'MAIL_USERNAME', 'MAIL_PASSWORD'] as $name) {
    putenv($name);
}

/* ------------------------------------------------- both mailers use it */

foreach (['accounts/mailer.php', 'platform/accounts/mailer.php'] as $rel) {

    $source = (string) @file_get_contents($root . $rel);

    t_ok($source !== '', "{$rel} is readable");
    t_ok(str_contains($source, 'mailSettings('),
        "{$rel} asks the shared reader for its credentials");
    t_ok(!str_contains($source, "'C:/xampp/private_config/sarismart_secrets.php'"),
        "{$rel} no longer names a path that exists on one machine");
}

t_done();
