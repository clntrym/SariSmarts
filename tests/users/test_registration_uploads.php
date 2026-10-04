<?php
/*
| Registration cannot lose the owner's certificates.
|
| The sequence reported, in order:
|
|   1. DTI and BIR files are chosen. The reader fills in the number, the
|      dates, the OCN. "Read from your file: ..." -- it worked.
|   2. Submit. Both files come back "The file could not be saved. Please try
|      again." and both inputs are empty again.
|   3. Choose the same files again. Now: "Nothing could be read from that
|      image. Please type the details in yourself."
|
| Two separate faults wearing one coat.
|
| The first is where the files are put. REGISTRATION_STAGING_ROOT resolves to
| the grandparent of platform/ -- which was outside the web root when the two
| projects were siblings in htdocs, and is /var/www on Render, a directory
| Apache cannot write to: the Dockerfile only hands www-data /var/www/html.
| mkdir() failed, nobody checked, move_uploaded_file() failed, and the owner
| was told to try again at something that could never work.
|
| The second is the message. The reader fills a field only when it is empty,
| which is right -- it must not overwrite a correction the owner typed. But
| after a failed submit every field is already filled from the POST, so
| nothing gets written and the code reports that nothing could be READ. It
| read the certificate perfectly. It just had nowhere to put the answer.
*/
require_once __DIR__ . '/bootstrap.php';

$root = __DIR__ . '/../../';

/* ------------------------------------------------- where the files are put */

$register = (string) file_get_contents($root . 'platform/register.php');

/*
| The location is chosen by a function, not computed from a relative path.
| __DIR__ . '/../../pending_uploads' meant C:\xampp when the two projects
| were siblings and /var/www on Render, where Apache owns only
| /var/www/html -- the same expression, two different places, one of them
| unwritable.
*/
t_ok(!str_contains($register, "'/../../pending_uploads'"),
    'the staging root is not a relative climb out of the application');

t_ok(!str_contains($register, 'REGISTRATION_STAGING_ROOT'),
    'and the constant that was that climb is gone');

/*
| Wherever it points, the directory has to be creatable and writable by the
| process serving the page. This proves it here rather than discovering it
| from an owner losing their certificates.
*/
require_once $root . 'platform/register_paths.php';

$dir = registrationStagingRoot();

t_ok($dir !== '', 'there is a staging root');
t_ok(is_dir($dir) || @mkdir($dir, 0775, true), "the staging root can be created: {$dir}");
t_ok(is_writable($dir), 'and written to');

/* A file really lands there and comes back. */
$probe = $dir . '/probe_' . bin2hex(random_bytes(4)) . '.txt';
t_ok(file_put_contents($probe, 'x') !== false, 'a file can be written into it');
t_ok(is_file($probe), 'and read back');
@unlink($probe);

/*
| It must not be servable, and this is checked strictly because the first fix
| got it wrong. Landing on C:\xampp\htdocs\pending_uploads was writable and
| also fetchable at /pending_uploads/<token>/dti_document.jpg -- a DTI and a
| BIR certificate carry a TIN and a registered address, and the token is the
| only thing between them and anyone who guesses it.
*/
t_ok(function_exists('registrationPathIsPublic'),
    'there is a check for whether a path is reachable by URL');

t_ok(!registrationPathIsPublic($dir),
    'the staging root is outside the document root');

/* Under a real request, where DOCUMENT_ROOT is set, it must still hold. */
$remembered = $_SERVER['DOCUMENT_ROOT'] ?? null;
$_SERVER['DOCUMENT_ROOT'] = realpath($root) ?: $root;

t_ok(registrationPathIsPublic(rtrim($root, '/\\') . DIRECTORY_SEPARATOR . 'uploads'),
    'a folder inside the application is correctly seen as public');

t_ok(!registrationPathIsPublic($dir),
    'and the staging root is still not, with a document root to compare against');

if ($remembered === null) {
    unset($_SERVER['DOCUMENT_ROOT']);
} else {
    $_SERVER['DOCUMENT_ROOT'] = $remembered;
}

/* ---------------------------------------------------- a failure is reported */

t_ok(str_contains($register, 'registrationStagingRoot()'),
    'register.php asks one function where to stage, rather than building a path');

/*
| mkdir() was called and its result thrown away. A staging directory that
| could not be created has to say so, because the next thing that happens is
| a move into it.
*/
preg_match('/function registrationStagingDir.*?\n}/s', $register, $m);
$body = $m[0] ?? '';

t_ok(str_contains($body, 'mkdir'), 'the staging directory is created on demand');
t_ok(str_contains($body, 'is_dir') && str_contains($body, 'RuntimeException'),
    'and a failure to create it is raised, not ignored');

/* --------------------------------------------------- the reader tells the truth */

$ocr = (string) file_get_contents($root . 'platform/assets/js/permit-ocr.js');

t_ok(str_contains($ocr, 'alreadyFilled') || str_contains($ocr, 'already'),
    'the reader distinguishes "nothing was read" from "the fields were already filled"');

t_ok(str_contains($ocr, 'setIfEmpty'),
    'it still refuses to overwrite what the owner typed');

/*
| The exact sentence that was wrong. It may still appear -- an unreadable
| photograph is a real case -- but it must not be the only outcome when the
| fields happen to be full.
*/
$sentenceAt = strpos($ocr, 'Nothing could be read');
$branchAt = strpos($ocr, 'alreadyFilled');

t_ok($branchAt !== false && $sentenceAt !== false,
    'both outcomes exist in the reader');

t_done();
