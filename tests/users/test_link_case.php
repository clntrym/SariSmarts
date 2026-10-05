<?php
/*
| Links whose spelling matches the file only on Windows.
|
| The Inventory entry in the sidebar pointed at
|
|     /inventory/inventory.php
|
| and the file is inventory/Inventory.php, with a capital I. Windows does
| not care, so XAMPP served it for as long as anybody looked. Linux does,
| so the deployed site answered with Apache's own 404 -- not the
| application's, because the application was never reached.
|
| admin/Inventory.php then redirects to "inventory.php" after every save it
| performs, sixteen times over, each one a dead end on the live site.
|
| It is the third version of the same shape in this project: a path correct
| on the machine it was typed on. "http://localhost/..." in the mail,
| "../RETAILCORE/..." on the Apply button, and now a capital letter. None of
| them fail where they are written, and none are caught by anything that
| runs locally -- which is why they want a test rather than attention.
|
| The rule: a link naming a file must name it the way the file is named.
|
| WHY THE PATH IS RESOLVED RATHER THAN THE NAME COMPARED
|
| Both inventory/Inventory.php and includes/chatbot/chat/tools/inventory.php
| exist, spelled differently, and both are correct. A test that compared
| basenames could only either accuse the second or excuse the first. So each
| reference is resolved against the directory it was written in, the way the
| server resolves it, and compared against the real path.
*/
require_once __DIR__ . '/bootstrap.php';

$root = str_replace('\\', '/', dirname(__DIR__, 2));

/**
 * "a/b/../c.php" as "a/c.php". The server does this; so must we.
 */
function normalisePath(string $path): string
{
    $out = [];

    foreach (explode('/', str_replace('\\', '/', $path)) as $part) {

        if ($part === '' || $part === '.') {
            continue;
        }

        if ($part === '..') {
            array_pop($out);
            continue;
        }

        $out[] = $part;
    }

    return implode('/', $out);
}

/**
 * Every file under $dir, as paths relative to it.
 *
 * @return array<int, string>
 */
function everyFile(string $dir, string $base = ''): array
{
    $found = [];

    foreach (scandir($dir) ?: [] as $entry) {

        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $relative = $base === '' ? $entry : $base . '/' . $entry;

        if (is_dir($dir . '/' . $entry)) {

            /* Somebody else's code, and far more of it than ours. */
            if (!in_array($entry, ['vendor', '.git', 'node_modules'], true)) {
                $found = array_merge($found, everyFile($dir . '/' . $entry, $relative));
            }

            continue;
        }

        $found[] = $relative;
    }

    return $found;
}

$files = everyFile($root);

/* The real spelling of every PHP file, found by its lowercase path. */
$real = [];

foreach ($files as $relative) {
    if (str_ends_with(strtolower($relative), '.php')) {
        $real[strtolower($relative)] = $relative;
    }
}

t_ok(count($real) > 100, 'the application was walked: ' . count($real) . ' PHP files');

t_same('inventory/Inventory.php', $real['inventory/inventory.php'] ?? null,
    'Inventory.php really is spelled with a capital I');
t_same('includes/chatbot/chat/tools/inventory.php',
    $real['includes/chatbot/chat/tools/inventory.php'] ?? null,
    'and a different inventory.php is spelled without one, which is why paths matter');

/* ----------------------------------------------- every link, by spelling */

$wrong = [];

foreach ($files as $relative) {

    if (!preg_match('~\.(php|js)$~i', $relative) || str_starts_with($relative, 'tests/')) {
        continue;
    }

    /* Comments are prose about links, not links. */
    $code = (string) preg_replace(['~/\*.*?\*/~s', '~(?<!:)//[^\n]*~'],
        '', (string) file_get_contents($root . '/' . $relative));

    /*
    | Any .php path, wherever it sits. Not anchored to a quote: the
    | redirects that caused this read header("Location: inventory.php"),
    | where the path is in the middle of the string, and an anchored
    | pattern found none of the sixteen.
    */
    if (!preg_match_all('~[A-Za-z0-9_][A-Za-z0-9_./-]*\.php~', $code, $found)) {
        continue;
    }

    $from = dirname($relative);
    $from = $from === '.' ? '' : $from;

    foreach (array_unique($found[0]) as $target) {

        /* Root-absolute, or relative to the file that names it. */
        $candidate = str_starts_with($target, '/')
            ? normalisePath($target)
            : normalisePath($from . '/' . $target);

        $key = strtolower($candidate);

        /*
        | Only paths the application actually has. Anything else is a
        | pattern, a generated name, or a file somewhere this cannot resolve,
        | and the test has nothing to say about it.
        */
        if (!isset($real[$key])) {
            continue;
        }

        if ($real[$key] !== $candidate) {
            $wrong[] = $relative . '  names "' . $target
                . '", but the file is "' . basename($real[$key]) . '"';
        }
    }
}

$wrong = array_values(array_unique($wrong));
sort($wrong);

t_same([], $wrong,
    "every link spells the file the way the file is spelled:\n    "
    . implode("\n    ", $wrong));

t_done();
