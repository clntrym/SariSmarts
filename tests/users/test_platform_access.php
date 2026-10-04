<?php
/*
| The platform's door, held to the same rule as the tenant app's.
|
| The platform has its own copy of requireRole(), in platform/init.php. It is
| the same shape as the one in SariSmarts and it had the same gap: nothing
| re-read the account's status, so a deactivated operator kept working until
| they chose to sign out.
|
| The two apps also spell deactivation differently -- the platform writes
| 'inactive', SariSmart's User Management writes 'disabled' -- which is exactly
| why the old login check caught one and missed the other. Both must now be
| refused by the same shared function.
*/
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../includes/account_access.php';

/*
| The platform inside the repository, not the sibling copy in htdocs.
|
| There are two: C:\xampp\htdocs\platform, which is nobody's deployment, and
| <repo>/platform, which is what Render serves. This test was written against
| the first, so it passed while saying nothing at all about the code that
| actually runs.
*/
$platform = __DIR__ . '/../../platform/';

function platformSource(string $path): string
{
    $source = @file_get_contents($path);

    return $source === false ? '' : $source;
}

$init = platformSource($platform . 'init.php');

t_ok($init !== '', 'the platform bootstrap is readable');

t_ok(str_contains($init, 'account_access.php'),
    'the platform loads the shared access rule');
t_ok(str_contains($init, 'userAccountAllowsAccess'),
    'and re-checks the account on each request');

$guardAt = strpos($init, 'userAccountAllowsAccess');
$roleAt = strpos($init, 'function requireRole');

t_ok($guardAt !== false && $roleAt !== false && $guardAt > $roleAt,
    'the re-check is inside requireRole, which every platform page calls');

t_ok(str_contains($init, 'session_destroy'),
    'and the deactivated session is destroyed, not merely redirected');

/*
| Whatever the platform writes when deactivating must be a value the shared
| rule refuses. This is the pair that disagreed.
*/
$users = platformSource($platform . 'superAdmin/users.php');

t_ok($users !== '', 'the platform user page is readable');

preg_match_all("/status\s*=\s*'([a-z ]+)'/i", $users, $matches);

$written = array_values(array_unique(array_map('strtolower', $matches[1] ?? [])));

t_ok($written !== [], 'it names the statuses it writes');
t_ok(in_array('active', $written, true), 'one of which is active');

foreach ($written as $status) {

    if ($status === 'active') {
        continue;
    }

    t_ok(!accountStatusAllowsAccess($status),
        "the status '{$status}' the platform writes is refused by the shared rule");
}

/*
| Both spellings, named explicitly. If either ever starts being allowed, this
| says so in words rather than leaving it to a regex.
*/
t_ok(!accountStatusAllowsAccess('inactive'), "the platform's 'inactive' is refused");
t_ok(!accountStatusAllowsAccess('disabled'), "and SariSmart's 'disabled' too");

/* Platform staff sign in through the tenant login page, so that one door
   already covers them -- but only because it asks the same function. */
$login = platformSource(__DIR__ . '/../../accounts/acc_log_in.php');

t_ok(str_contains($login, 'accountStatusAllowsAccess'),
    'the shared login page asks the shared rule for platform staff too');

t_done();
