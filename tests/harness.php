<?php
/*
| A three-function test harness, shared by every suite in tests/.
|
| The project has no PHPUnit and does not justify adding one: these tests run
| from the CLI, print one line per assertion, and set the exit code so a
| run_all.php can fail the suite.
|
| It lives here rather than inside one suite's folder because a second suite
| needed it, and copying it would have let the two drift.
*/

$GLOBALS['t_pass'] = 0;
$GLOBALS['t_fail'] = 0;

function t_ok(bool $condition, string $label): void
{
    if ($condition) {
        $GLOBALS['t_pass']++;
        echo "  PASS  {$label}\n";
        return;
    }

    $GLOBALS['t_fail']++;
    echo "  FAIL  {$label}\n";
}

function t_same($expected, $actual, string $label): void
{
    $ok = $expected === $actual;

    t_ok($ok, $ok ? $label : $label
        . ' (expected ' . var_export($expected, true)
        . ', got ' . var_export($actual, true) . ')');
}

function t_done(): void
{
    echo "\n  {$GLOBALS['t_pass']} passed, {$GLOBALS['t_fail']} failed\n";
    exit($GLOBALS['t_fail'] > 0 ? 1 : 0);
}
