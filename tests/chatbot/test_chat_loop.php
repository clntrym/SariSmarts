<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/factory.php';
require_once __DIR__ . '/../../includes/chatbot/engine.php';
require_once __DIR__ . '/../../includes/chatbot/chat/conversation.php';

register_shutdown_function(function () use ($conn) { testCleanup($conn); });

$companyId = testMakeCompany($conn, 'Loop Co', 2);
$ctx = ['company_id' => $companyId, 'user_id' => 1, 'employee_id' => null, 'role' => 'admin'];

$conn->query("INSERT INTO sales (company_id, total_amount, tax_amount, cash_received, change_amount, payment_method)
              VALUES ({$companyId}, 250.00, 0.00, 250.00, 0.00, 'Cash')");

/* A scripted model: each call returns the next canned reply. */
function scriptedModel(array $script): callable
{
    $calls = 0;

    return function (array $messages, array $tools) use ($script, &$calls): array {
        $reply = $script[$calls] ?? ['text' => 'No more script.', 'tool_calls' => []];
        $calls++;

        return $reply;
    };
}

/* One tool call, then an answer. */
$model = scriptedModel([
    ['text' => null, 'tool_calls' => [
        ['id' => 't1', 'name' => 'sales_summary', 'input' => ['period' => 'today']]]],
    ['text' => 'Ang benta ninyo ngayong araw ay 250.00 sa isang transaksyon.',
     'tool_calls' => []],
]);

$result = chatConverse($conn, $ctx, 'magkano ang benta ngayon', [], $model);

t_ok($result['ok'], 'a normal exchange succeeds');
t_same(['sales_summary'], $result['tools_used'], 'the tool it used is recorded');
t_same(1, count($result['tables']), 'the tool result is kept as a table for the user');
t_same('250.00', $result['tables'][0]['rows'][0][0], 'and the table holds the real figure');
t_ok(str_contains($result['text'], '250.00'), 'the prose is returned');

/* Review Focus 1: a tool that does not exist, and one this user may not use. */
$model = scriptedModel([
    ['text' => null, 'tool_calls' => [
        ['id' => 't1', 'name' => 'read_payroll', 'input' => []]]],
    ['text' => 'Wala akong makuhang datos para diyan.', 'tool_calls' => []],
]);

$result = chatConverse($conn, $ctx, 'magkano ang sweldo ni Ana', [], $model);
t_ok($result['ok'], 'an unknown tool does not crash the exchange');
t_same([], $result['tools_used'], 'and nothing is recorded as used');

$cashierCtx = ['company_id' => $companyId, 'user_id' => 1, 'employee_id' => null, 'role' => 'cashier'];
$model = scriptedModel([
    ['text' => null, 'tool_calls' => [
        ['id' => 't1', 'name' => 'sales_summary', 'input' => ['period' => 'today']]]],
    ['text' => 'Hindi ko ito makukuha.', 'tool_calls' => []],
]);

$result = chatConverse($conn, $cashierCtx, 'magkano ang benta ng tindahan', [], $model);
t_same([], $result['tools_used'], 'a cashier cannot reach the sales tool through the model');
t_ok(!str_contains(json_encode($result), '250.00'), 'and no figure reaches them');

/* Limits: a model that never stops calling tools. */
$greedy = function (array $messages, array $tools): array {
    return ['text' => null, 'tool_calls' => [
        ['id' => 'x', 'name' => 'sales_summary', 'input' => ['period' => 'today']]]];
};

$result = chatConverse($conn, $ctx, 'paulit-ulit', [], $greedy);
t_ok($result['rounds'] <= CHAT_MAX_ROUNDS, 'the loop stops at the round limit');

/* The tool limit needs a reply carrying MORE calls than the limit: with one
   call per reply the round limit alone keeps the count down, so the assertion
   would pass whether or not the tool limit existed. */
$manyAtOnce = function (array $messages, array $tools): array {
    $calls = [];

    for ($i = 0; $i < CHAT_MAX_TOOL_CALLS + 7; $i++) {
        $calls[] = ['id' => "m{$i}", 'name' => 'sales_summary',
                    'input' => ['period' => 'today']];
    }

    return ['text' => null, 'tool_calls' => $calls];
};

$result = chatConverse($conn, $ctx, 'lahat sabay', [], $manyAtOnce);
t_same(CHAT_MAX_TOOL_CALLS, count($result['tools_used']),
    'a single reply cannot run more tools than the limit');

/* A model that throws: the caller gets a clean failure, not an exception. */
$broken = function (array $messages, array $tools): array {
    throw new RuntimeException('Could not resolve host');
};

$result = chatConverse($conn, $ctx, 'magkano ang benta ngayon', [], $broken);
t_ok(!$result['ok'], 'an API failure is reported as a failure');
t_same('api_failed', $result['reason'], 'with a reason the caller can act on');

/* A model that answers, but slowly enough to blow the whole-question budget. */
$slow = function (array $messages, array $tools): array {
    sleep(CHAT_DEADLINE_SECONDS + 1);

    return ['text' => 'Sorry for the wait.', 'tool_calls' => []];
};

$started = microtime(true);
$result = chatConverse($conn, $ctx, 'magkano ang benta ngayon', [], $slow);
$elapsed = microtime(true) - $started;

t_ok(!$result['ok'], 'a question that blows the deadline is abandoned');
t_same('timeout', $result['reason'], 'with a timeout reason');
t_ok($elapsed < (CHAT_DEADLINE_SECONDS * 2),
    'and the loop does not keep going afterwards (' . round($elapsed) . 's)');

t_done();
