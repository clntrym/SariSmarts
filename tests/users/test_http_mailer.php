<?php
/*
| Sending mail where SMTP cannot go.
|
| The deployed host answers "Connection timed out" on 587, 465, 25 and 2525,
| and opens 443 without complaint. It reaches the internet and blocks SMTP
| specifically, so no password, no port and no encryption setting will ever
| get a message out of it. Mail has to travel the way every other request
| does.
|
| The shape of the fix matters as much as the fix. Roughly a dozen files call
| getMailer(), set a subject and a body, add an address and call send(). None
| of them should have to know that the message now leaves over HTTPS instead
| of a socket, so the HTTP sender IS a PHPMailer -- same methods, same
| properties -- with one method replaced.
*/
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../includes/mail_settings.php';
require_once __DIR__ . '/../../includes/http_mailer.php';

/* ------------------------------------------------- it is still a PHPMailer */

$mail = new HttpApiMailer();

t_ok($mail instanceof PHPMailer\PHPMailer\PHPMailer,
    'the HTTP sender is a PHPMailer, so no caller has to change');

t_ok(method_exists($mail, 'addAddress'), 'it takes an address the same way');
t_ok(method_exists($mail, 'send'), 'and is sent the same way');

/* ------------------------------------------------------ what it would post */

$mail->setFrom('store@example.com', 'RetailCore');
$mail->addAddress('owner@example.com', 'Nenita Bautista');
$mail->Subject = 'Your application was approved';
$mail->Body = '<p>Welcome to RetailCore.</p>';

$payload = $mail->brevoPayload();

t_same('store@example.com', $payload['sender']['email'] ?? null, 'the sender carries over');
t_same('RetailCore', $payload['sender']['name'] ?? null, 'with its name');
t_same('owner@example.com', $payload['to'][0]['email'] ?? null, 'and the recipient');
t_same('Nenita Bautista', $payload['to'][0]['name'] ?? null, 'with theirs');
t_same('Your application was approved', $payload['subject'] ?? null, 'the subject survives');
t_ok(str_contains((string) ($payload['htmlContent'] ?? ''), 'Welcome to RetailCore'),
    'and the body');

/* More than one recipient, because some messages have several. */
$mail->addAddress('second@example.com');
$payload = $mail->brevoPayload();

t_same(2, count($payload['to'] ?? []), 'every recipient is carried');
t_same('second@example.com', $payload['to'][1]['email'] ?? null, 'including the second');

/* A reply-to, where one is set. */
$mail->addReplyTo('support@example.com', 'Support');
$payload = $mail->brevoPayload();

t_same('support@example.com', $payload['replyTo']['email'] ?? null, 'a reply-to is carried');

/* A plain-text alternative, where the body is HTML. */
$mail->AltBody = 'Welcome to RetailCore.';
$payload = $mail->brevoPayload();

t_same('Welcome to RetailCore.', $payload['textContent'] ?? null,
    'the plain-text alternative travels too, for clients that want it');

/* ------------------------------------------------- when it is used at all */

$GLOBALS['mail_settings_path'] = __DIR__ . '/no_mail_secrets_in_tests.php';

foreach (['MAIL_USERNAME', 'MAIL_PASSWORD', 'BREVO_API_KEY', 'MAIL_TRANSPORT'] as $name) {
    putenv($name);
}

mailSettingsForget();
t_ok(!mailTransportIsHttp(), 'with no API key, mail goes by SMTP as before');

putenv('BREVO_API_KEY=xkeysib-not-a-real-key');
mailSettingsForget();

t_ok(mailTransportIsHttp(), 'an API key is enough to switch to HTTP');

/*
| And it can be forced off, for a host where SMTP works and the key is only
| there for another environment.
*/
putenv('MAIL_TRANSPORT=smtp');
mailSettingsForget();

t_ok(!mailTransportIsHttp(), 'MAIL_TRANSPORT=smtp overrides the key');

putenv('MAIL_TRANSPORT');
mailSettingsForget();

/* ----------------------------------------------- the sender address matters */

/*
| Brevo sends from an address somebody verified with them. The Gmail account
| the SMTP path used is the obvious default, and is wrong the moment the
| verified sender is a different one -- so it is its own setting, falling
| back to the SMTP username rather than being assumed equal to it.
*/
putenv('MAIL_USERNAME=store@example.com');
mailSettingsForget();

t_same('store@example.com', mailFromAddress(),
    'the from address falls back to the SMTP username');

putenv('MAIL_FROM_ADDRESS=verified@example.com');
mailSettingsForget();

t_same('verified@example.com', mailFromAddress(),
    'and is its own setting when the verified sender differs');

foreach (['MAIL_USERNAME', 'MAIL_FROM_ADDRESS', 'BREVO_API_KEY'] as $name) {
    putenv($name);
}

/* ------------------------------------------------------ send() routes here */

/*
| The payload being right proves nothing if send() still opens a socket.
| PHPMailer dispatches postSend() on its $Mailer name, and the whole design
| rests on that name reaching brevoSend() -- so it is worth proving, and can
| be proved without a key or a network: with no key, brevoSend() is the only
| thing that throws that particular sentence.
*/
putenv('BREVO_API_KEY');
mailSettingsForget();

$outgoing = new HttpApiMailer();
$outgoing->setFrom('store@example.com', 'RetailCore');
$outgoing->addAddress('owner@example.com');
$outgoing->Subject = 'Routing';
$outgoing->Body = 'Body';

try {
    $outgoing->send();
    t_ok(false, 'send() should not have succeeded with no key');
} catch (Throwable $error) {
    t_ok(str_contains($error->getMessage(), 'BREVO_API_KEY'),
        'send() reaches brevoSend(), which says what is missing: ' . $error->getMessage());
}

/*
| And preSend() still runs in front of it -- a message with no recipient is
| refused by PHPMailer's own validation, not by the API after a round trip.
*/
$noOne = new HttpApiMailer();
$noOne->setFrom('store@example.com', 'RetailCore');
$noOne->Subject = 'Nobody';
$noOne->Body = 'Body';

try {
    $noOne->send();
    t_ok(false, 'a message with no recipient should be refused');
} catch (Throwable $error) {
    t_ok(str_contains(strtolower($error->getMessage()), 'recipient'),
        'PHPMailer still validates before the transport runs');
}

/* A plain-text message travels as text, not as HTML with visible markup. */
$plain = new HttpApiMailer();
$plain->setFrom('store@example.com', 'RetailCore');
$plain->addAddress('owner@example.com');
$plain->isHTML(false);
$plain->Subject = 'Plain';
$plain->Body = 'No <b>markup</b> intended';

$payload = $plain->brevoPayload();

t_same('No <b>markup</b> intended', $payload['textContent'] ?? null,
    'isHTML(false) sends the body as text');
t_ok(!isset($payload['htmlContent']),
    'and not also as HTML, where its angle brackets would show as markup');

/* --------------------------------------------- the two secrets that look alike */

/*
| Brevo's "SMTP & API" page hands out two secrets on adjacent tabs, shaped
| the same -- prefix, 64 hex, dash, 16 more -- and the API refuses the wrong
| one with "unauthorized: Key not found", the identical sentence it uses for
| a revoked key. The deployed site hit exactly this: a 90-character secret
| that was the SMTP password, where a 89-character API key belonged.
|
| The prefix is the only thing that separates them before the round trip,
| and it is not a secret, so it can be printed.
*/
$sixtyFour = str_repeat('a', 64);
$sixteen = str_repeat('b', 16);

t_same('api', mailApiKeyKind('xkeysib-' . $sixtyFour . '-' . $sixteen),
    'an API key is recognised');
t_same('smtp', mailApiKeyKind('xsmtpsib-' . $sixtyFour . '-' . $sixteen),
    'and the SMTP password is named as such, not merely rejected');
t_same('empty', mailApiKeyKind('   '), 'nothing is nothing');
t_same('unknown', mailApiKeyKind('some-other-secret'), 'anything else is unknown');

/* Surrounding space is a copy-paste artefact, not a different key. */
t_same('api', mailApiKeyKind("  xkeysib-{$sixtyFour}-{$sixteen}\n"),
    'whitespace around a pasted key does not change what it is');

/* ------------------------------------------------- both mailers can use it */

$root = __DIR__ . '/../../';

foreach (['accounts/mailer.php', 'platform/accounts/mailer.php'] as $rel) {

    $source = (string) file_get_contents($root . $rel);

    t_ok(str_contains($source, 'mailTransportIsHttp'),
        "{$rel} chooses its transport rather than assuming SMTP");
    t_ok(str_contains($source, 'HttpApiMailer'),
        "{$rel} can return the HTTP sender");
}

t_done();
