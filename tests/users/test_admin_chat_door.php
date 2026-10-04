<?php
/*
| The platform assistant's door and its widget.
|
| The tools test proves the catalogue refuses a tenant. This proves the two
| things around it: that the endpoint refuses one before it reaches the
| catalogue at all, and that the bubble is drawn for platform staff and for
| nobody else.
|
| It reads the files rather than making requests, because the endpoint ends
| in exit() and a test cannot follow it there. What it checks is that each
| guard is present and in the right order -- a CSRF check after the query has
| already run is not a CSRF check.
*/
require_once __DIR__ . '/bootstrap.php';

$root = __DIR__ . '/../../';

$door = (string) file_get_contents($root . 'platform/admin_chat.php');
$footer = (string) file_get_contents($root . 'platform/superAdmin/sAdminFooter.php');

t_ok($door !== '', 'the endpoint exists');
t_ok($footer !== '', 'the super admin footer exists');

/* ------------------------------------------------------------- the guards */

t_ok(str_contains($door, "REQUEST_METHOD'] !== 'POST'"), 'it answers POST only');
t_ok(str_contains($door, "empty(\$_SESSION['user_id'])"), 'it requires a signed-in user');
t_ok(str_contains($door, 'platformRoles()'), 'and a platform role');
t_ok(str_contains($door, 'hash_equals'), 'the CSRF token is compared with hash_equals');

/*
| Order matters. Each guard must come before the question is read, or it is
| guarding nothing.
*/
$roleAt = strpos($door, 'platformRoles()');
$csrfAt = strpos($door, 'hash_equals');
$questionAt = strpos($door, "payload['question']");
$converseAt = strpos($door, 'chatConverse');

t_ok($roleAt < $questionAt, 'the role is checked before the question is read');
t_ok($csrfAt < $questionAt, 'and so is the token');
t_ok($questionAt < $converseAt, 'and nothing is asked of a model before all of it');

/* The role comes from the session, never from the request. */
t_ok(str_contains($door, "\$_SESSION['role']"), 'the role is taken from the session');
t_ok(!str_contains($door, "payload['role']"), 'and never from what was posted');

/* A rate limit, because a page left open with a loop in it spends money. */
t_ok(str_contains($door, 'rate_limited'), 'there is a rate limit');

/* The conversation is stamped AND checked, not just stamped. */
t_ok(str_contains($door, 'admin_chat_user'), 'the conversation is stamped with its owner');

$stampAt = strpos($door, "admin_chat_user'] ?? 0");
t_ok($stampAt !== false && $stampAt < strpos($door, 'chatConverse'),
    'and the stamp is checked when the history is read, not only when written');

/* ------------------------------------------------------------- the widget */

t_ok(str_contains($footer, 'rcAdminChat'), 'the bubble is drawn in the shared footer');
t_ok(str_contains($footer, 'platformRole() !== null'),
    'and only for a platform role');
t_ok(str_contains($footer, 'admin_chat_csrf'), 'it carries a token');
t_ok(str_contains($footer, 'textContent'), 'answers are written with textContent');
t_ok(!str_contains($footer, 'innerHTML = data'), 'never with innerHTML');

$guardAt = strpos($footer, 'platformRole() !== null');
$markupAt = strpos($footer, 'rcAdminChat');

t_ok($guardAt < $markupAt, 'the guard wraps the markup rather than following it');

/* It posts to the one door, by an absolute path, so a page in a subfolder
   does not ask a different address. */
t_ok(str_contains($footer, '"/platform/admin_chat.php"'),
    'it posts to the endpoint at a root-absolute path');

t_done();
