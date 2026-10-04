<?php
/*
| Buttons that lead where the person who sees them may not go.
|
| An admin opens HRMS -> Recruitment, which they are allowed, and clicks
| "Application" on a job posting. hr/applications.php guards itself with
| requireRole(['hr']), so the admin is bounced to the dashboard -- no
| message, no explanation, just somewhere else.
|
| That is worse than the button not being there. A button is a promise. It
| was rendered to somebody the page knew was an admin, and then refused by
| the next page for being an admin.
|
| The RBAC work gave the admin the pages named in the module list. What it
| did not do is follow the links out of those pages, so every page they
| reach is open and everything one click further is a trapdoor. This test
| walks that edge: for each page an admin may open, every HR page it links
| to must also admit an admin.
|
| It reads the guards rather than making requests, because the alternative
| is a browser session per role per page, and the guard is the whole of the
| rule -- requireRole() is the only thing that decides.
*/
require_once __DIR__ . '/bootstrap.php';

$root = str_replace('\\', '/', dirname(__DIR__, 2));

/**
 * The roles a page admits, or null where it has no guard at all.
 */
function pageRoles(string $path): ?array
{
    $source = (string) file_get_contents($path);

    /*
    | Comments stripped first. Two edits in this project have already landed
    | on a requireRole() inside a comment rather than the real guard, and a
    | test that reads the prose is a test that reports the wrong thing.
    */
    $code = (string) preg_replace(['~/\*.*?\*/~s', '~(?<!:)//[^\n]*~'], '', $source);

    if (!preg_match("~requireRole\(\s*\[([^\]]*)\]~", $code, $found)) {
        return null;
    }

    preg_match_all("~['\"]([a-z ]+)['\"]~i", $found[1], $roles);

    return array_map('strtolower', $roles[1]);
}

$pages = glob($root . '/hr/*.php') ?: [];

t_ok(count($pages) > 10, 'the HR pages were found: ' . count($pages));

/* Which of them an admin may open. */
$openToAdmin = [];

foreach ($pages as $path) {

    $name = basename($path);
    $roles = pageRoles($path);

    if ($roles !== null && in_array('admin', $roles, true)) {
        $openToAdmin[$name] = $path;
    }
}

t_ok($openToAdmin !== [], 'an admin can open some of them: ' . count($openToAdmin));

/* ------------------------------------------- follow the links out of them */

$deadEnds = [];

foreach ($openToAdmin as $name => $path) {

    $code = (string) preg_replace(['~/\*.*?\*/~s', '~(?<!:)//[^\n]*~'],
        '', (string) file_get_contents($path));

    /*
    | Every HR page named anywhere in this one -- an href, a form action, a
    | JS redirect. The filename is enough: these all sit in one folder.
    */
    if (!preg_match_all('~([a-z_]+\.php)~i', $code, $found)) {
        continue;
    }

    foreach (array_unique($found[1]) as $target) {

        $targetPath = $root . '/hr/' . $target;

        /* Only pages that exist here, and not the page itself. */
        if ($target === $name || !is_file($targetPath)) {
            continue;
        }

        /*
        | hr_header.php guards itself and is named by every page in the
        | folder, but naming it is not reaching it: includeRoleHeader()
        | hands an admin the admin header instead, which is the whole point
        | of includes/role_chrome.php. A page that goes through it is fine.
        | A page that writes include("hr_header.php") is not -- it is
        | refused before it renders a line, whatever its own guard says.
        */
        if ($target === 'hr_header.php' && str_contains($code, 'includeRoleHeader')) {
            continue;
        }

        $roles = pageRoles($targetPath);

        /* A partial with no guard runs inside its includer and decides
           nothing of its own. */
        if ($roles === null) {
            continue;
        }

        if (!in_array('admin', $roles, true)) {
            $deadEnds[] = $name . ' -> ' . $target
                . '  (admits ' . implode(', ', $roles) . ')';
        }
    }
}

sort($deadEnds);

t_same([], $deadEnds,
    "every page an admin can reach leads only to pages that admit them:\n    "
    . implode("\n    ", $deadEnds));

/* ---------------------------------------------- the header admits them too */

/*
| hr_header.php stays HR-only on purpose -- it is the HR person's own
| chrome, with their sidebar and their menu. The admin keeps theirs, which
| is what the user asked for when the RBAC work was done.
|
| So the rule is not "open the header to admins". It is that any page an
| admin can open must choose its chrome by the reader's role rather than
| assume HR's.
*/
foreach ($openToAdmin as $name => $path) {

    $code = (string) preg_replace(['~/\*.*?\*/~s', '~(?<!:)//[^\n]*~'],
        '', (string) file_get_contents($path));

    if (!str_contains($code, 'hr_header.php')) {
        continue;
    }

    t_ok(str_contains($code, 'includeRoleHeader'),
        "{$name} picks its header by the reader's role, not HR's");
}

t_done();
