<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/factory.php';
require_once __DIR__ . '/../../includes/chatbot/engine.php';
require_once __DIR__ . '/../../includes/chatbot/chat/conversation.php';

register_shutdown_function(function () use ($conn) { testCleanup($conn); });

$companyId = testMakeCompany($conn, 'Grounding Co', 2);
$ctx = ['company_id' => $companyId, 'user_id' => 1, 'employee_id' => null, 'role' => 'admin'];

/* The rule itself */
t_ok(!chatAnswerIsGrounded('Ang benta ninyo ngayon ay 50,000.', []),
    'figures with no tool behind them are not grounded');
t_ok(chatAnswerIsGrounded('Ang benta ninyo ngayon ay 50,000.', ['sales_summary']),
    'the same answer is grounded once a tool ran');
t_ok(chatAnswerIsGrounded('Wala akong makitang datos para diyan.', []),
    'an answer with no figures needs no tool');
t_ok(chatAnswerIsGrounded('Walang tools para sa sweldo at payroll.', []),
    'a refusal is grounded even though it has no tool call');

/* End to end: a model that invents a figure without calling anything. */
$liar = function (array $messages, array $tools): array {
    return ['text' => 'Ang benta ninyong araw na ito ay 50,000.00.', 'tool_calls' => []];
};

$result = chatConverse($conn, $ctx, 'magkano ang benta ngayon', [], $liar);

t_ok(!$result['ok'], 'an invented figure is not returned to the user');
t_same('ungrounded', $result['reason'], 'and the reason says why');
t_ok(!str_contains(json_encode($result), '50,000'), 'the invented figure is dropped');

/* A model that calls a tool and then answers is unaffected. */
$conn->query("INSERT INTO sales (company_id, total_amount, tax_amount, cash_received, change_amount, payment_method)
              VALUES ({$companyId}, 250.00, 0.00, 250.00, 0.00, 'Cash')");

$calls = 0;
$honest = function (array $messages, array $tools) use (&$calls): array {
    $calls++;

    if ($calls === 1) {
        return ['text' => null, 'tool_calls' => [
            ['id' => 't1', 'name' => 'sales_summary', 'input' => ['period' => 'today']]]];
    }

    return ['text' => 'Ang benta ninyo ngayon ay 250.00.', 'tool_calls' => []];
};

$result = chatConverse($conn, $ctx, 'magkano ang benta ngayon', [], $honest);
t_ok($result['ok'], 'a tool-backed answer is returned');

t_done();
