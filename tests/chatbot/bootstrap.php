<?php
/*
| The chatbot suite's bootstrap: the shared harness, plus the one thing only
| this suite needs -- isolation from the live API.
*/
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../harness.php';

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
