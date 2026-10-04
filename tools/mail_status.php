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
| It also runs in a browser, because Render's free instances have no shell
| and this is exactly the host whose mail needs explaining:
|
|     /tools/mail_status.php
|     /tools/mail_status.php?send=you@example.com
|
| In a browser it is for the Super Admin only. What it prints -- the mail
| account, the host, whether the server answers -- is a map of how the system
| reaches the outside, and a stranger should not be handed one.
|
| Credentials are never printed, only their length.
*/

require_once __DIR__ . '/../includes/mail_settings.php';

$viaBrowser = PHP_SAPI !== 'cli';

if ($viaBrowser) {

    /*
    | init.php starts the session and gives requireRole() -- but this page
    | answers in plain text and must not be redirected into an HTML login
    | screen, so the check is made here and answered in words.
    */
    require_once __DIR__ . '/../init.php';

    header('Content-Type: text/plain; charset=utf-8');

    /* No search engine, and no browser, should keep a copy of this. */
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store');

    if (strtolower(trim((string) ($_SESSION['role'] ?? ''))) !== 'super admin') {
        http_response_code(403);
        echo "This page is for the Super Admin.\n";
        echo "Sign in at /acc_log_in and open it again.\n";
        exit;
    }
}

function mailLine(string $label, bool $ok, string $detail = ''): void
{
    echo '  [', $ok ? 'OK  ' : 'FAIL', '] ', str_pad($label, 30), $detail, "\n";
}

echo "\nRetailCore mail -- configuration\n\n";

$settings = mailSettings();
$path = mailSettingsPath();

mailLine('Secrets file', is_readable($path), $path . (is_readable($path) ? '' : '  (not on this host)'));

/*
| Which way mail leaves, asked before anything else.
|
| A host with an API key does not need an SMTP password, and probing mail
| ports on it would report "every port closed" about a system whose mail
| works perfectly -- the diagnostic frightening somebody about a fault it
| created by asking the wrong question.
*/
if (mailTransportIsHttp()) {

    $key = mailApiKey();
    $from = mailFromAddress();

    mailLine('Transport', true, 'HTTP API -- Brevo, over 443');
    mailLine('BREVO_API_KEY', $key !== '', $key !== '' ? strlen($key) . ' characters' : 'empty');

    /*
    | The prefix, which is the only thing that tells the SMTP password apart
    | from an API key before the API has a chance to reject it. Both are
    | handed out by the same page on adjacent tabs; both are refused with
    | the identical "Key not found".
    */
    $kind = mailApiKeyKind($key);

    mailLine('Key kind', $kind === 'api', [
        'api' => 'xkeysib- -- a v3 API key, which is what this needs',
        'smtp' => 'xsmtpsib- -- that is the SMTP password, from the SMTP tab',
        'unknown' => 'unrecognised prefix -- an API key begins xkeysib-',
        'empty' => 'empty',
    ][$kind]);

    mailLine('Sending as', $from !== '', $from !== '' ? $from : 'empty -- set MAIL_FROM_ADDRESS');

    /*
    | What the sending domain tells receivers about relays.
    |
    | Verification with Brevo and permission from the domain are different
    | questions asked of different parties, and only the second decides
    | whether anything arrives. RetailCore sent as an ncst.edu.ph address on
    | the strength of a green "DMARC is configured" in Brevo's own panel --
    | which means the domain HAS a policy, and the policy was p=reject.
    | Every approval mail bounced with "Unauthenticated email ... is not
    | accepted due to domain's DMARC policy".
    |
    | Asked here because the answer is public, costs one DNS lookup, and
    | otherwise only shows up in the provider's logs after a real person has
    | been told their application was approved.
    */
    if ($from !== '') {

        $policy = mailSenderDmarc($from);
        $domain = mailSenderDomain($from);
        $blocked = mailPolicyBlocksRelay($policy);

        mailLine('Sender domain policy', !$blocked,
            $domain . '  DMARC p=' . $policy
            . ($blocked ? '  -- this domain refuses relays it has not authorised' : ''));

        if ($blocked) {
            echo "\n  Mail will be sent and then refused by the recipient. p={$policy}\n";
            echo "  tells every receiver not to accept mail from {$domain} unless\n";
            echo "  {$domain}'s own servers authenticated it, and Brevo is not one\n";
            echo "  of them.\n\n";
            echo "  Two ways out. Either send from a domain you control and add\n";
            echo "  Brevo's DKIM records to its DNS, or send from an address whose\n";
            echo "  domain publishes p=none -- gmail.com does. A freemail sender is\n";
            echo "  not ideal and it does arrive, which the current one does not.\n\n";
        }
    }


    if ($key === '') {
        echo "\n  MAIL_TRANSPORT asks for the API but no BREVO_API_KEY is set.\n";
        echo "  Set it in the environment, or set MAIL_TRANSPORT=smtp.\n\n";
        exit(1);
    }

    if ($kind !== 'api') {
        echo "\n  This is not a v3 API key, so the API will refuse it with\n";
        echo "  \"Key not found\" -- which reads like a revoked key and is not.\n";
        echo "  In Brevo: SMTP & API -> the API Keys tab (not the SMTP tab) ->\n";
        echo "  Generate a new API key. It begins with xkeysib-.\n\n";
        exit(1);
    }

    if ($from === '') {
        echo "\n  Nothing can be sent without a sender. Set MAIL_FROM_ADDRESS to\n";
        echo "  the address verified with Brevo -- an unverified sender is\n";
        echo "  refused, and that refusal is what you would see instead.\n\n";
        exit(1);
    }

    echo "\n  Configuration is complete.\n\nReaching the mail API\n\n";

    $probe = @fsockopen('api.brevo.com', 443, $errno, $errstr, 10);

    mailLine('TCP connection', $probe !== false,
        'api.brevo.com:443  ' . ($probe !== false ? 'open' : $errstr . ' (' . $errno . ')'));

    if ($probe) {
        fclose($probe);
    } else {
        echo "\n  This host cannot reach the API either, which is a different\n";
        echo "  fault from the SMTP block -- check outbound HTTPS.\n\n";
        exit(1);
    }

    echo "\n";

    /*
    | Past this point the SMTP checks make no sense, so the script jumps to
    | the live send -- which goes through getMailer() and therefore through
    | the same transport the real pages use.
    */
    $skipSmtpChecks = true;
} else {
    $skipSmtpChecks = false;
}

$user = (string) ($settings['MAIL_USERNAME'] ?? '');
$pass = (string) ($settings['MAIL_PASSWORD'] ?? '');

if (!$skipSmtpChecks):

mailLine('Transport', true, 'SMTP -- ' . (string) $settings['MAIL_HOST']);
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

    /*
    | One blocked port is not proof that all of them are, and guessing which
    | to try next wastes an afternoon per guess. So every port mail might
    | leave on is tried, and the host answers for itself.
    |
    | "Connection timed out" rather than "refused" is the signature of a
    | firewall dropping packets silently. A refusal would mean something
    | answered and said no; a timeout means nothing was allowed to ask.
    |
    | 443 is included as the control. If that is open and the mail ports are
    | not, the host plainly reaches the internet and is blocking SMTP
    | specifically -- which settles it, and points at an HTTP mail API as the
    | only way out.
    */
    echo "\n  Trying the other ports mail can leave on\n\n";

    $candidates = [
        [$host, 587, 'SMTP with STARTTLS -- the usual one'],
        [$host, 465, 'SMTP over SSL'],
        [$host, 25, 'SMTP, plain'],
        [$host, 2525, 'SMTP, the alternate port some providers offer'],
        ['api.resend.com', 443, 'HTTPS -- the control'],
    ];

    $anySmtp = false;

    foreach ($candidates as [$tryHost, $tryPort, $what]) {

        $probe = @fsockopen($tryHost, $tryPort, $n, $s, 6);
        $open = $probe !== false;

        if ($probe) {
            fclose($probe);
        }

        mailLine($tryHost . ':' . $tryPort, $open, $open ? $what : ($s ?: 'no answer'));

        if ($open && $tryPort !== 443) {
            $anySmtp = true;
        }
    }

    echo "\n";

    if ($anySmtp) {
        echo "  One of the mail ports is open. Set MAIL_PORT to it -- 465 also\n";
        echo "  needs MAIL_ENCRYPTION=ssl -- and run this again.\n\n";
    } else {
        echo "  Every mail port is closed and HTTPS is open, so this host\n";
        echo "  reaches the internet and blocks SMTP specifically. No password\n";
        echo "  and no port will change that. Mail has to leave over an HTTP\n";
        echo "  API, which travels on 443 like any other web request.\n\n";
    }

    exit(1);
}

$greeting = trim((string) fgets($socket, 512));
fclose($socket);

mailLine('TCP connection', true, $host . ':' . $port . '  ' . $elapsed . 's');
mailLine('Server greeting', str_starts_with($greeting, '220'), $greeting);

endif; /* !$skipSmtpChecks */

if ($viaBrowser) {
    $to = trim((string) ($_GET['send'] ?? ''));

    if ($to === '') {
        echo "  Add ?send=you@example.com to the address to prove it end to end.\n\n";
        exit(0);
    }

    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        echo "  That is not an email address.\n\n";
        exit(1);
    }
} else {
    $sendIndex = array_search('--send', $argv ?? [], true);

    if ($sendIndex === false) {
        echo "  Re-run with --send you@example.com to prove it end to end.\n\n";
        exit(0);
    }

    $to = (string) ($argv[$sendIndex + 1] ?? '');
}

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

    if ($skipSmtpChecks) {
        echo "\n  The API answered and refused. The usual cause is the sender:\n";
        echo "  Brevo only sends from an address somebody verified with them,\n";
        echo "  and MAIL_FROM_ADDRESS has to be that address. A 401 instead\n";
        echo "  means the key is wrong or revoked.\n\n";
    } else {
        echo "\n  Authentication failed is usually one of two things: the password\n";
        echo "  is the account password rather than an App Password, or the Google\n";
        echo "  account does not have 2-Step Verification on, which is what makes\n";
        echo "  App Passwords available at all.\n\n";
    }

    exit(1);
}
