<?php
/*
| Links that name the folder the project happened to sit in.
|
| The Apply button on the careers page pointed at:
|
|     ../RETAILCORE/accounts/apply.php?page=apply&job_id=1
|
| which is correct on exactly one computer -- the one where the project was
| checked out into a folder called RETAILCORE -- and a 404 everywhere else,
| including the deployed site, where the application is served from the root
| and there is no such folder at all.
|
| The same mistake as "http://localhost/..." in the mail, and it fails the
| same way: nothing errors, nothing is logged, the page renders perfectly,
| and only the person who clicks finds out. Somebody browsing jobs reached
| Apache's own 404 and left.
|
| A path inside the application is root-absolute -- "/accounts/apply.php" --
| which is right under XAMPP and right on Render. That is the convention the
| rest of the codebase follows; this is the test that keeps it.
*/
require_once __DIR__ . '/bootstrap.php';

$root = str_replace('\\', '/', dirname(__DIR__, 2));

/*
| The folder names this project has been checked out under. SariSmarts is
| the repository's own name on disk, which is why renaming the product did
| not remove it from these strings.
*/
$folders = ['RETAILCORE', 'RetailCore', 'SariSmarts', 'SariSmart'];

$files = [];

foreach (['php', 'js'] as $extension) {
    foreach ([$root, $root . '/accounts', $root . '/admin', $root . '/hr',
              $root . '/platform', $root . '/platform/accounts',
              $root . '/platform/superAdmin', $root . '/platform/assets/js',
              $root . '/includes', $root . '/assets/js'] as $directory) {

        foreach (glob($directory . '/*.' . $extension) ?: [] as $path) {
            $files[] = $path;
        }
    }
}

t_ok(count($files) > 50, 'there are files to check: ' . count($files));

$offenders = [];

foreach ($files as $path) {

    $name = str_replace($root . '/', '', str_replace('\\', '/', $path));

    /*
    | Block comments are blanked rather than removed, so the line numbers
    | still point at the file. A line-by-line skip cannot do this: the
    | middle lines of a /* ... *\/ block start with nothing in particular,
    | and this very test was failing on a comment written to explain the
    | bug it had just caught.
    */
    $source = (string) file_get_contents($path);

    $source = (string) preg_replace_callback(
        '~/\*.*?\*/~s',
        static fn (array $m): string => str_repeat("\n", substr_count($m[0], "\n")),
        $source
    );

    $lines = explode("\n", $source);

    foreach ($lines as $number => $line) {

        /*
        | A filesystem path is not a link. __DIR__ . '/../../SariSmarts/...'
        | is how the platform finds the main application's includes when the
        | two sit side by side rather than nested, and it is correct -- the
        | folder really is called that on disk.
        */
        if (str_contains($line, '__DIR__') || str_contains($line, 'dirname(')) {
            continue;
        }

        /*
        | And a comment is not a link either. These files explain the literals
        | they used to carry -- app_url.php exists precisely because of this
        | class of fault and quotes an example of it -- so a test that reads
        | the prose finds the bug it was told about rather than one that is
        | still there.
        */
        $start = ltrim($line);

        if ($start === '' || preg_match('~^(\||\*|//|#|/\*)~', $start)) {
            continue;
        }

        foreach ($folders as $folder) {

            /* Only where it is being used as a URL segment. */
            if (preg_match('~(\.\./|"/|\'/|=/)' . preg_quote($folder, '~') . '/~', $line)) {
                $offenders[] = $name . ':' . ($number + 1) . '  ' . trim($line);
                break;
            }
        }
    }
}

t_same([], $offenders,
    "no link names the folder the project sits in:\n    " . implode("\n    ", $offenders));

/* ------------------------------------------- the apply page is reachable */

/*
| The link is only right if something is there. Only the main application
| has an apply page -- platform/accounts/apply.php does not exist, which is
| why two links in platform/accounts/verify_email.php are 404s of their own.
| That file is one of the dead duplicates listed in test_mail_links.php.
*/
t_ok(is_file($root . '/accounts/apply.php'),
    'the apply page the careers board links to is real');

$careers = (string) file_get_contents($root . '/platform/careers.php');

/*
| The quote matters. Without it this passes on
| "../RETAILCORE/accounts/apply.php", which contains "/accounts/apply.php"
| as a substring -- the assertion would have been green on the broken link
| it was written to catch.
*/
t_ok(str_contains($careers, '"/accounts/apply.php'),
    'and the careers board points at it by a root-absolute path');

t_done();
