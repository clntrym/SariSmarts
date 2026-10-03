<?php
/*
| Runs the static query audit as part of the suite, so a handler that forgets
| company_id fails the tests rather than waiting for someone to run the audit
| by hand.
*/
require_once __DIR__ . '/bootstrap.php';

exec('python ' . escapeshellarg(__DIR__ . '/query_audit.py') . ' 2>&1', $output, $code);

t_same(0, $code, "query audit passes:\n    " . implode("\n    ", $output));
t_done();
