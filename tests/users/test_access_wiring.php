<?php
/*
| That the doors actually call the lock.
|
| The bug was never that the rule was wrong. It was that login and User
| Management each had their own idea of it, and nothing noticed when they
| stopped agreeing. A test of accountStatusAllowsAccess() alone would have
| passed throughout.
|
| So this reads the real files and checks that each door calls the shared
| function and none of them carries its own copy of the rule.
*/
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../includes/account_access.php';

$root = __DIR__ . '/../../';

function sourceOf(string $path): string
{
    $source = file_get_contents($path);

    return $source === false ? '' : $source;
}

/* ----------------------------------------------------------- the login page */

$login = sourceOf($root . 'accounts/acc_log_in.php');

t_ok($login !== '', 'the login page is readable');
t_ok(str_contains($login, 'accountStatusAllowsAccess'),
    'the login page asks the shared rule');
t_ok(!str_contains($login, "=== 'inactive'"),
    "and no longer refuses only the word 'inactive'");

/*
| The status check must come after the password is verified, or anyone can
| learn which accounts exist and are disabled without a password.
*/
$verifyAt = strpos($login, 'password_verify(');
$statusAt = strpos($login, 'accountStatusAllowsAccess');

t_ok($verifyAt !== false && $statusAt !== false && $statusAt > $verifyAt,
    'and asks it only after the password is verified');

/* ------------------------------------------------------ the per-request guard */

$init = sourceOf($root . 'init.php');

t_ok(str_contains($init, "require_once __DIR__ . '/includes/account_access.php'"),
    'init.php loads the rule for every page');
t_ok(str_contains($init, 'userAccountAllowsAccess'),
    'and requireRole re-checks the account on each request');

$guardAt = strpos($init, 'userAccountAllowsAccess');
$roleAt = strpos($init, 'function requireRole');

t_ok($guardAt !== false && $roleAt !== false && $guardAt > $roleAt,
    'the re-check lives inside requireRole, where every protected page passes');

t_ok(str_contains($init, 'session_destroy'),
    'and a deactivated session is destroyed, not merely redirected');

/* --------------------------------------------------- the deactivation endpoint */

$endpoint = sourceOf($root . 'admin/ajax_update_user.php');

t_ok(str_contains($endpoint, 'deactivationReasonProblem'),
    'the endpoint validates the reason on the server');
t_ok(!str_contains($endpoint, 'if ($reason === \'\') {'),
    'and no longer settles for "not empty"');

/*
| Whatever the endpoint writes for a deactivated user must be a value the rule
| refuses. This is the exact pair that disagreed: 'disabled' written here,
| 'inactive' refused there.
*/
preg_match_all("/\\\$allowedStatuses\s*=\s*\[([^\]]*)\]/", $endpoint, $matches);

t_ok(!empty($matches[1]), 'the endpoint names the statuses it will write');

$written = array_map(
    static fn (string $value): string => trim($value, " '\""),
    explode(',', $matches[1][0])
);

t_ok(in_array('active', $written, true), 'one of them is active');

foreach ($written as $status) {

    if ($status === '' || $status === 'active') {
        continue;
    }

    t_ok(!accountStatusAllowsAccess($status),
        "the status '{$status}' this endpoint writes is refused by the rule");
}

/* ------------------------------------------------------------- the dialog */

$page = sourceOf($root . 'admin/user_management.php');

t_ok(str_contains($page, 'Swal.fire'), 'deactivation asks for confirmation');
t_ok(str_contains($page, 'showCancelButton'), 'with a way to back out');
t_ok(str_contains($page, 'swal-reason'), 'and requires a reason');
t_ok(!str_contains($page, '/^[^a-zA-Z0-9\s]+$/'),
    'the old only-special-characters check is gone');
t_ok(str_contains($page, "\\p{L}\\p{N} .,\\-'()"),
    'and the dialog uses the same character rule as the server');

/* --------------------------------------------- nobody is locked out by surprise */

$result = $conn->query("SELECT DISTINCT status FROM users");
$surprises = [];

while ($row = $result->fetch_assoc()) {

    $status = (string) $row['status'];

    if (!accountStatusAllowsAccess($status) && strtolower(trim($status)) !== 'disabled') {
        $surprises[] = $status;
    }
}

t_same([], $surprises,
    'no live account carries a status that is neither active nor disabled');

t_done();
