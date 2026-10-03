<?php
/*
| Who may get in, and who may not.
|
| The bug this suite exists for: deactivating a user wrote status 'disabled',
| and the login page refused only the exact word 'inactive'. Two spellings of
| one idea, in two files, and nothing holding them together -- so a deactivated
| account logged in as if nothing had happened.
|
| The fix is not to add 'disabled' to the list. It is to let exactly one value
| through and refuse everything else, from a single function both the login
| page and the per-request guard call.
*/
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../includes/account_access.php';

register_shutdown_function(function () use ($conn) { userTestCleanup($conn); });

/* ------------------------------------------------------- the rule itself */

t_ok(accountStatusAllowsAccess('active'), 'an active account may sign in');
t_ok(accountStatusAllowsAccess('Active'), 'whatever the casing');
t_ok(accountStatusAllowsAccess('  active  '), 'and whatever the padding');

t_ok(!accountStatusAllowsAccess('disabled'),
    "'disabled' -- what Deactivate actually writes -- may not");
t_ok(!accountStatusAllowsAccess('inactive'), "nor 'inactive'");
t_ok(!accountStatusAllowsAccess(''), 'nor an empty status');
t_ok(!accountStatusAllowsAccess(null), 'nor a missing one');

/*
| The point of the rule: a status nobody has thought of yet is refused, not
| allowed. Every spelling added later is denied until somebody decides
| otherwise, which is the safe direction for a lock to fail in.
*/
foreach (['suspended', 'archived', 'locked', 'pending', 'deactivated', 'DISABLED'] as $unknown) {
    t_ok(!accountStatusAllowsAccess($unknown), "an unknown status '{$unknown}' is refused");
}

/* A refused account is told something, and never why in detail. */
$message = accountAccessMessage('disabled');
t_ok(trim($message) !== '', 'a refused account gets a message');
t_ok(!str_contains(strtolower($message), 'disabled'),
    'which does not echo the internal status word back');

/* ----------------------------------------------- against the real table */

$active = userTestMake($conn, 'Active Admin', 'active');
$disabled = userTestMake($conn, 'Disabled Admin', 'disabled');

t_ok(userAccountAllowsAccess($conn, $active['user_id']),
    'the active user reads as allowed from the database');
t_ok(!userAccountAllowsAccess($conn, $disabled['user_id']),
    'and the deactivated one does not');

t_ok(!userAccountAllowsAccess($conn, 0),
    'a user id that does not exist is refused, not allowed');

/*
| Deactivation must take effect on the next request, not the next login. The
| Deactivate dialog promises "will lose system access immediately"; this is
| what makes that true.
*/
$conn->query("UPDATE users SET status = 'disabled' WHERE user_id = {$active['user_id']}");

t_ok(!userAccountAllowsAccess($conn, $active['user_id']),
    'a user deactivated mid-session is refused on the very next check');

$conn->query("UPDATE users SET status = 'active' WHERE user_id = {$active['user_id']}");

t_ok(userAccountAllowsAccess($conn, $active['user_id']),
    'and allowed again once reactivated');

/* ------------------------------------------- the deactivation reason rule */

t_same(null, deactivationReasonProblem('Resigned'), 'a plain reason is accepted');
t_same(null, deactivationReasonProblem('End of contract - resigned'),
    'a hyphen is ordinary punctuation, not a special character');
t_same(null, deactivationReasonProblem('Resigned (personal reasons)'),
    'so are brackets');
t_same(null, deactivationReasonProblem("Terminated, per manager's request"),
    'so are a comma and an apostrophe');
t_same(null, deactivationReasonProblem('Umalis na siya noong 2026'),
    'numbers and Tagalog are fine');

t_ok(deactivationReasonProblem('') !== null, 'an empty reason is refused');
t_ok(deactivationReasonProblem('    ') !== null, 'so is whitespace only');
t_ok(deactivationReasonProblem('ab') !== null, 'so is one too short');
t_ok(deactivationReasonProblem(str_repeat('a', 200)) !== null, 'so is one too long');

/*
| The special characters. The old browser-side check only refused a reason
| made ENTIRELY of them, so "Resigned!!!" and an injected <script> both passed.
*/
foreach (['Resigned!!!', 'Resigned <script>alert(1)</script>', 'Fired @ 5pm',
          'Left #2', 'Gone 100%', 'a & b', 'path/to/blame', 'quote"here',
          'semi;colon', 'back\\slash', 'brace{}'] as $bad) {
    t_ok(deactivationReasonProblem($bad) !== null,
        'a reason containing a special character is refused: ' . $bad);
}

/* The message names the rule, so the person can fix their input. */
$problem = (string) deactivationReasonProblem('Resigned!!!');
t_ok(str_contains(strtolower($problem), 'letter') || str_contains($problem, '.'),
    'and says what is allowed instead');

t_done();
