<?php
/*
| Where the links in an email point.
|
| A mail that sends and a mail that works are different things. These
| messages exist to carry one link each -- confirm your address, choose your
| plan, correct your application -- and a message whose link is wrong is
| worse than one that never arrived, because the recipient believes they
| have been told something.
|
| Two faults, both of which were live:
|
|   http://localhost/...   right on the machine it was typed on, and nowhere
|                          else. The owner is pointed at their own computer.
|
|   /accounts/...   from a platform mail. The path exists in BOTH
|                   applications; without the /platform/ prefix the owner
|                   lands on the employee-facing page of the other one,
|                   which looks up a different table and tells them their
|                   token is invalid.
|
| Neither shows up in testing on XAMPP, where localhost is in fact the site
| and both applications are a directory apart.
*/
require_once __DIR__ . '/bootstrap.php';

$root = str_replace('\\', '/', dirname(__DIR__, 2));

/*
| Code with its comments removed.
|
| The comments are where the already-fixed files explain the literal they
| used to carry, so reading them would be reading the story about the code
| rather than the code.
|
| The lookbehind is not decoration. "//" opens a line comment AND sits in
| the middle of every URL, so a pattern without it eats "//localhost/..."
| out of "http://localhost/..." -- which is exactly the string being looked
| for. The first version of this test passed for that reason, on files that
| were plainly broken.
*/
function mailLinkCode(string $path): string
{
    return (string) preg_replace(
        ['~/\*.*?\*/~s', '~(?<!:)//[^\n]*~'],
        '',
        (string) file_get_contents($path)
    );
}

/* Every file whose job is to send a message. */
$senders = array_merge(
    glob($root . '/accounts/send*.php') ?: [],
    glob($root . '/platform/accounts/send*.php') ?: [],
    [$root . '/admin/reset_password_send.php']
);

t_ok(count($senders) >= 9, 'the senders were found: ' . count($senders));

foreach ($senders as $path) {

    $name = str_replace($root . '/', '', str_replace('\\', '/', $path));
    $code = mailLinkCode($path);

    t_ok(!str_contains($code, 'http://localhost') && !str_contains($code, 'https://localhost'),
        "{$name} does not post a link to the recipient's own computer");

    /* A sender that builds a link at all builds it from the request. */
    if (preg_match('~\$\w*[Ll]ink\s*=~', $code)) {
        t_ok(str_contains($code, 'appUrl('),
            "{$name} builds its link from the address the request arrived on");
    }
}

/* ----------------------------------------- the pages those links name */

/*
| A link is only as good as the page at the end of it, and these are built
| by string concatenation, where a wrong path is not an error anywhere --
| it is a 404 for somebody who has already been told to click.
|
| Every path reached from a mail that is actually sent:
*/
foreach ([
    'platform/accounts/verify_company.php',
    'platform/subscribe.php',
    'platform/resubmit.php',
    'accounts/verify_email.php',
    'accounts/reset-password.php',
] as $page) {

    t_ok(is_file($root . '/' . $page), "{$page} is a real page to land on");
}

/*
| An earlier version of this test also demanded that every link in a
| platform sender begin with /platform/, on the reasoning that a path like
| "/accounts/verify_email.php" exists in BOTH applications and would land
| the owner in the wrong one.
|
| That turned out to be false, and worth recording rather than quietly
| dropping. The two platform files it accused -- send-password-reset.php and
| send_verification.php -- are duplicates of the main application's, down to
| the same tables and the same queries, so either path reaches a page that
| works. They are also dead: nothing includes send-password-reset.php at
| all, and sendVerificationEmail() is only ever called through the copy in
| accounts/. The test was demanding a change to code no request reaches, to
| fix a fault that does not occur.
|
| Dead senders are still a hazard -- send-password-reset.php carries
| setFrom("noreply@example.com"), which would override the verified sender
| and be refused outright by the API the day somebody wires it up. That is
| worth knowing about; it is not worth pretending it is live.
|
| So the rule worth enforcing is not about that file. It is this: a sender
| that IS reachable must not name its own From address. getMailer() sets it
| to the verified one, and an API refuses anything else -- a literal here
| would be accepted by SMTP and rejected by Brevo, which is the kind of
| difference that only shows up in production.
*/
/*
| Four senders no request reaches. Each is a duplicate of one that is
| reached, and two of them carry setFrom("noreply@example.com") -- a
| sender nobody verified, which SMTP would have delivered and the API
| refuses outright. They are listed rather than edited: changing code no
| request reaches is a change nobody can test, and deleting somebody's
| files is their decision, not this test's. The live password reset is
| admin/reset_password_send.php, which is checked below like the rest.
*/
$dead = ['accounts/send-password-reset.php',
         'platform/accounts/send-password-reset.php',
         'platform/accounts/send_verification.php'];

foreach ($senders as $path) {

    $name = str_replace($root . '/', '', str_replace('\\', '/', $path));

    if (in_array($name, $dead, true)) {
        continue;
    }

    t_ok(!preg_match('~setFrom\(\s*[\'"][^\'"]*@~', mailLinkCode($path)),
        "{$name} sends from the verified address, not one written into the page");
}

t_done();
