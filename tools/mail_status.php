<?php
/*
|--------------------------------------------------------------------------
| CAN THIS SERVER SEND EMAIL?
|--------------------------------------------------------------------------
|
| Every send is wrapped in a try/catch that logs and moves on, which is right
| -- a failed notification must not take down an approval -- and is also why
| nobody noticed that no email had left the deployed site at all. This asks
| the question directly.
|
|     php tools/mail_status.php
|
| Configuration only. To also attempt one real message:
|
|     php tools/mail_status.php --send you@example.com
|
| Credentials are never printed, only their length.
*/

require_once __DIR__ . '/../includes/mail_settings.php';

function mailLine(string $label, bool $ok, string $detail = ''): void
{
    echo '  [', $ok ? 'OK  ' : 'FAIL', '] ', str_pad($label, 30), $detail, "\n";
}

echo "\nRetailCore mail -- configuration\n\n";

$settings = mailSettings();
$path = mailSettingsPath();

mailLine('Secrets file', is_readable($path), $path . (is_readable($path) ? '' : '  (not on this host)'));

$user = (string) ($settings['MAIL_USERNAME'] ?? '');
$pass = (string) ($settings['MAIL_PASSWORD'] ?? '');

mailLine('MAIL_USERNAME', $user !== '', $user !== '' ? $user : 'empty');
mailLine('MAIL_PASSWORD', $pass !== '', $pass !== '' ? strlen($pass) . ' characters' : 'empty');
mailLine('MAIL_HOST', true, (string) $settings['MAIL_HOST']);
mailLine('MAIL_PORT', true, (string) $settings['MAIL_PORT']);

/*
| A Gmail App Password is sixteen characters. An account password put here
| instead will be refused with the same message as a wrong one, so it is
| worth naming the difference before anybody spends an afternoon on it.
*/
if ($pass !== '' && str_contains((string) $settings['MAIL_HOST'], 'gmail')) {
    $looksLikeAppPassword = strlen(str_replace(' ', '', $pass)) === 16;
    mailLine('Looks like an App Password', $looksLikeAppPassword,
        $looksLikeAppPassword ? '' : 'Gmail needs a 16-character App Password, not the account password');
}

if (!mailSettingsReady()) {
    echo "\n  Nothing can be sent. Set MAIL_USERNAME and MAIL_PASSWORD in the\n";
    echo "  environment -- on Render that is Settings -> Environment.\n";
    echo "\n  If they ARE set there, this host is running code from before the\n";
    echo "  mailer learned to read them. Deploy, then run this again.\n\n";
    exit(1);
}

echo "\n  Configuration is complete.\n\n";

/*
| Can this host open the connection at all?
|
| Three different faults arrive looking similar once PHPMailer wraps them,
| and they want three different things done:
|
|   - the port is unreachable     the host blocks outbound SMTP, or the
|                                 network does; no credential will help
|   - connected, auth refused     the password is wrong, or it is an account
|                                 password where Gmail wants an App Password
|   - connected and authenticated nothing is wrong with mail at all
|
| Opening a socket separates the first from the other two before PHPMailer
| is involved, which is the only way to tell them apart from a log line.
*/
echo "Reaching the mail server\n\n";

$host = (string) $settings['MAIL_HOST'];
$port = (int) $settings['MAIL_PORT'];

$started = microtime(true);
$socket = @fsockopen($host, $port, $errno, $errstr, 10);
$elapsed = round(microtime(true) - $started, 2);

if (!$socket) {
    mailLine('TCP connection', false, $host . ':' . $port . '  ' . $errstr . ' (' . $errno . ')');
    echo "\n  The connection could not be opened, so this is not about the\n";
    echo "  password. Either the host blocks outbound SMTP on this port, or\n";
    echo "  nothing is listening. Port 465 with SSL sometimes works where 587\n";
    echo "  does not; if neither does, the host blocks SMTP and mail has to go\n";
    echo "  out over an HTTP API instead.\n\n";
    exit(1);
}

$greeting = trim((string) fgets($socket, 512));
fclose($socket);

mailLine('TCP connection', true, $host . ':' . $port . '  ' . $elapsed . 's');
mailLine('Server greeting', str_starts_with($greeting, '220'), $greeting);

$sendIndex = array_search('--send', $argv, true);

if ($sendIndex === false) {
    echo "  Re-run with --send you@example.com to prove it end to end.\n\n";
    exit(0);
}

$to = (string) ($argv[$sendIndex + 1] ?? '');

if (trim($to) === '') {
    echo "  --send needs an address to send to.\n\n";
    exit(1);
}

echo "\nLive send to ", $to, "\n\n";

require_once __DIR__ . '/../accounts/mailer.php';

try {
    $mail = getMailer();

    /*
    | The conversation with the server, printed. Without it a failure is one
    | sentence that reads the same whether the password was refused or the
    | connection died halfway through STARTTLS.
    */
    $mail->SMTPDebug = 2;
    $mail->Debugoutput = static function (string $line): void {
        echo '    ', rtrim($line), "\n";
    };

    $mail->addAddress($to);
    $mail->Subject = 'RetailCore mail test';
    $mail->Body = 'If you are reading this, the server can send email.';
    $mail->send();

    echo "\n";

    mailLine('Sent', true, 'check the inbox, and the spam folder');
    echo "\n";
} catch (Throwable $error) {
    mailLine('Sent', false, $error->getMessage());
    echo "\n  Authentication failed is usually one of two things: the password\n";
    echo "  is the account password rather than an App Password, or the Google\n";
    echo "  account does not have 2-Step Verification on, which is what makes\n";
    echo "  App Passwords available at all.\n\n";
    exit(1);
}
