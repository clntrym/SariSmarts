<?php
/*
| The Super Admin's assistant, and the wall around it.
|
| The tenant assistant answers about one company and proves it by binding
| company_id into every query. This one is the opposite: it answers ACROSS
| companies -- how many signed up, what they pay, which are waiting for
| review -- because that is the operator's job.
|
| Which makes the gate the whole safety story. A tenant reaching these tools
| would read every other business on the platform in one question. So the
| catalogue is closed to every role except the platform's own, and that is
| asserted here before anything else.
|
| The second rule is carried over deliberately: no pay. The tenant assistant
| may not see salaries, and RetailCore's own staff get the same treatment --
| platform_employees is readable for headcount and not for what anyone earns.
*/
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../platform/includes/admin_chat_tools.php';

$conn = $GLOBALS['conn'];

/* ------------------------------------------------------------- the gate */

foreach (['admin', 'hr', 'finance', 'inventory', 'cashier', 'employee', '', 'owner'] as $role) {
    t_same([], adminChatToolsFor($role),
        "a tenant role reaches no platform tool: '{$role}'");
}

$superAdmin = adminChatToolsFor('super admin');
t_ok(count($superAdmin) >= 6, 'the Super Admin reaches the catalogue');

/* The narrower platform roles get only what their job needs. */
$finance = adminChatToolsFor('platform finance');
$marketing = adminChatToolsFor('marketing hr');

t_ok(isset($finance['revenue_summary']), 'platform finance reaches revenue');
t_ok(!isset($finance['lead_pipeline']), 'and not the sales pipeline');

t_ok(isset($marketing['lead_pipeline']), 'marketing & hr reaches the pipeline');
t_ok(!isset($marketing['revenue_summary']), 'and not revenue');

/* Casing and padding are not a way past the gate. */
t_ok(count(adminChatToolsFor('  Super Admin  ')) >= 6, 'the gate trims and lowercases');
t_same([], adminChatToolsFor('Super'), 'a near-miss role is still refused');

/* ----------------------------------------------------------- no pay data */

foreach (adminChatToolsFor('super admin') as $name => $tool) {

    $text = strtolower($name . ' ' . $tool['description'] . ' ' . json_encode($tool['input']));

    foreach (['salary', 'payroll', 'sweldo', 'wage', 'compensation'] as $word) {
        t_ok(!str_contains($text, $word),
            "no platform tool offers pay data: {$name} / {$word}");
    }
}

/* ------------------------------------------------------- the queries run */

foreach (adminChatToolsFor('super admin') as $name => $tool) {

    t_ok(function_exists($tool['handler']), "{$name} has a handler");

    $input = [];

    foreach ($tool['input'] as $param => $spec) {
        if (!empty($spec['required'])) {
            $input[$param] = $spec['type'] === 'enum' ? $spec['values'][0] : 'x';
        }
    }

    $result = adminChatRunTool($conn, 'super admin', $name, $input);

    t_ok($result['ok'], "{$name} runs without error");
    t_ok(is_array($result['columns']), "{$name} names its columns");
    t_ok(is_array($result['rows']), "{$name} returns rows");
}

/* An unknown tool is refused rather than guessed at. */
$result = adminChatRunTool($conn, 'super admin', 'drop_everything', []);
t_ok(!$result['ok'], 'an unknown tool is refused');

/* And the gate holds at the runner, not only in the catalogue -- a request
   naming a real tool with the wrong role must still be refused. */
$result = adminChatRunTool($conn, 'admin', 'company_list', []);
t_ok(!$result['ok'], 'a tenant naming a real platform tool is still refused');

$result = adminChatRunTool($conn, 'cashier', 'revenue_summary', []);
t_ok(!$result['ok'], 'and so is a cashier');

t_done();
