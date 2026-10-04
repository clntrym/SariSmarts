<?php
/*
| The chatbot suite's bootstrap: the shared harness, plus the one thing only
| this suite needs -- isolation from the live API.
*/

/*
| Tests use the local database. Always.
|
| config.php decides which database to open from $_SERVER['SERVER_NAME'], and
| on the command line there is no such key -- so a test process took the
| PRODUCTION branch and, while config.php still carried a literal password,
| connected to the live Azure database. Every suite run was creating and
| deleting companies there. Cleanup ran, so nothing was left behind, but a
| test that died before its shutdown handler would have left rows in
| production, and testCleanup() turns off foreign key checks to do its work.
|
| Setting SERVER_NAME here, before init.php is loaded, puts config.php on its
| local branch. The assertion below is what makes it a guarantee rather than
| an intention: if a test ever reaches a host that is not this machine, the
| run stops before it touches anything.
*/
$_SERVER['SERVER_NAME'] = 'localhost';

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../harness.php';

/* Proof, not intent: stop before the first query if this is not local. */
$probe = $conn->query("SELECT @@hostname AS host");
$host = $probe ? (string) $probe->fetch_assoc()['host'] : '';

if (!in_array(strtolower($host), ['localhost', '127.0.0.1', gethostname(), strtolower((string) gethostname())], true)) {
    fwrite(STDERR, "REFUSING TO RUN: the tests opened a database on '{$host}', "
        . "which is not this machine. They would create and delete rows there.\n");
    exit(1);
}

/*
| No test may reach the live API.
|
| The secrets file lives at a fixed path outside the webroot, so the moment a
| real key was installed there every test process started reading it -- and the
| tests that exercise keyword routing began consulting the model instead. Two
| costs, both bad: the suite became nondeterministic (the same chip resolved to
| a different intent between runs), and every run spent real money.
|
| Pointing the path at a file that does not exist gives every test the offline
| behaviour it was written for. A test that wants the AI paths sets this global
| to a fixture of its own, as test_chat_api.php and test_chat_wire.php do --
| after this line, so it wins.
*/
$GLOBALS['chatbot_secrets_path'] = __DIR__ . '/no_secrets_in_tests.php';
