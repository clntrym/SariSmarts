<?php
/*
| What the owner can reach, and what they see when they get there.
|
| The owner runs the business, so every module belongs to them: hiring,
| payroll, expenses, the till. Those pages live in the role folders that own
| them -- hr/, finance/, cashier/ -- and each refused anybody but that role.
|
| Opening them to the owner is half the job. The other half is the sidebar.
| A page in hr/ includes hr_header.php, so an owner who opened Recruitment
| would have found their own menu replaced by HR's, with no way back to
| Inventory or Reports. One page, two menus, chosen by who is reading it.
|
| This asserts both halves, and asserts that opening the door for the owner
| did not open it for anybody else.
*/
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../includes/role_chrome.php';

$root = __DIR__ . '/../../';

/**
 * A file's code, with its comments removed.
 *
 * Two of the pages below open with a comment that QUOTES
 * requireRole(['admin']) while explaining a past bug. A search that reads
 * comments finds the quotation first and reports a guard that is not there --
 * which is exactly how the edit to those files went wrong, and would have
 * been exactly how this test missed it.
 */
function codeOnly(string $path): string
{
    $source = (string) @file_get_contents($path);

    $source = (string) preg_replace('#/\*.*?\*/#s', '', $source);
    $source = (string) preg_replace('#^\s*//.*$#m', '', $source);

    return $source;
}

/* The modules the owner was asked to have, and where each one lives. */
$modules = [
    'hr/recruitment.php' => 'hr',
    'hr/employee_registration.php' => 'hr',
    'hr/employee_directory.php' => 'hr',
    'hr/approval.php' => 'hr',
    'hr/attendance.php' => 'hr',
    'hr/payroll.php' => 'hr',
    'finance/expenses.php' => 'finance',
    'finance/accounts_payable.php' => 'finance',
    'cashier/pointofsales.php' => 'cashier',

    /*
    | These two are in the list because the first attempt missed them. Each
    | begins with a comment that quotes requireRole(['admin']) while
    | explaining a past bug, and the edit matched the quotation rather than
    | the guard below it -- so the page went on refusing the owner and
    | rendered nothing at all. A comment is not code, and a search that
    | cannot tell the difference needs a test that can.
    */
    'finance/reports.php' => 'finance',
    'inventory/reports.php' => 'inventory',
];

/* ------------------------------------------------- the owner may reach them */

foreach ($modules as $page => $owner) {

    $source = codeOnly($root . $page);

    t_ok($source !== '', "{$page} exists");

    preg_match('/requireRole\(\[([^\]]*)\]/', $source, $m);
    $roles = strtolower(str_replace([' ', "'", '"'], '', $m[1] ?? ''));

    t_ok(str_contains($roles, 'admin'), "{$page} admits the owner");
    t_ok(str_contains($roles, $owner), "{$page} still admits {$owner}");
}

/* --------------------------------------------- and nobody else came with them */

foreach ($modules as $page => $owner) {

    $source = codeOnly($root . $page);
    preg_match('/requireRole\(\[([^\]]*)\]/', $source, $m);
    $roles = array_map(
        static fn(string $r): string => trim(strtolower($r), " '\""),
        explode(',', $m[1] ?? '')
    );
    $roles = array_filter($roles);

    sort($roles);
    $expected = [$owner, 'admin'];
    sort($expected);

    t_same($expected, array_values($roles),
        "{$page} admits exactly the owner and {$owner}, nobody else");
}

/* ------------------------------------------------ the chrome follows the role */

t_ok(function_exists('roleHeader'), 'there is one place that picks the header');
t_ok(function_exists('roleFooter'), 'and the footer');

$cases = [
    'admin' => 'admin/admin_header.php',
    'hr' => 'hr/hr_header.php',
    'finance' => 'finance/finance_header.php',
    'inventory' => 'inventory/inventory_header.php',
    'cashier' => 'cashier/cashier_header.php',
    'employee' => 'employee/employee_header.php',
];

foreach ($cases as $role => $expected) {
    t_same($expected, roleHeader($role), "a {$role} is given their own header");
    t_ok(is_file($root . roleHeader($role)), "and that file exists");
    t_ok(is_file($root . roleFooter($role)), "so does the footer");
}

/* An unknown role is given nothing rather than somebody else's menu. */
t_same(null, roleHeader('auditor'), 'an unknown role gets no header');
t_same(null, roleHeader(''), 'nor does an empty one');

/* Casing and padding are not a way to the wrong menu. */
t_same('admin/admin_header.php', roleHeader('  ADMIN  '), 'the choice trims and lowercases');

/* --------------------------------------------- the pages actually use it */

foreach (array_keys($modules) as $page) {

    $source = codeOnly($root . $page);

    t_ok(str_contains($source, 'includeRoleHeader('),
        "{$page} picks its header by role rather than naming one");
    t_ok(str_contains($source, 'includeRoleFooter('),
        "{$page} does the same for the footer");
    t_ok(str_contains($source, 'role_chrome.php'),
        "{$page} loads the file that makes that choice");
}

t_done();
