<?php
/*
| Follow-up questions, and the batching that makes them possible.
|
| The flow this pins down is the one a shop owner actually uses:
|
|   "May products ba tayo na paubos na?"  -> "Yes, 8 products."
|   "Ano-ano?"                            -> the names
|
| The second question runs no tool of its own. Before this, the grounding rule
| -- any figure with no tool this turn is a guess -- rejected it, and the user
| got a canned keyword answer instead of the list they asked for. The fix is
| not to weaken the rule: it is to carry the earlier tool results in the
| conversation, so the figures in the follow-up really are grounded.
*/
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/factory.php';
require_once __DIR__ . '/../../includes/chatbot/engine.php';
require_once __DIR__ . '/../../includes/chatbot/chat/conversation.php';
require_once __DIR__ . '/../../includes/chatbot/chat/history.php';

register_shutdown_function(function () use ($conn) { testCleanup($conn); });

$companyId = testMakeCompany($conn, 'Followup Co', 2);
$ctx = ['company_id' => $companyId, 'user_id' => 7, 'employee_id' => null, 'role' => 'admin'];

$conn->query("INSERT INTO categories (category_name, company_id) VALUES ('Bigas', {$companyId})");
$categoryId = (int) $conn->insert_id;

foreach ([['Rice 5kg', 2], ['Coffee 3-in-1', 1], ['Sardinas', 0]] as [$name, $qty]) {
    $conn->query("INSERT INTO products (product_name, category_id, company_id)
                  VALUES ('{$name}', {$categoryId}, {$companyId})");
    $productId = (int) $conn->insert_id;
    $conn->query("INSERT INTO inventory (product_id, quantity, purchase_cost, selling_price, reorder_level, company_id)
                  VALUES ({$productId}, {$qty}, 10.00, 15.00, 5, {$companyId})");
}

/* ---------------------------------------------------------------- batching */

/*
| A model asking for two tools at once must get both results in ONE user
| message. Several messages is what teaches a model to stop asking for more
| than one thing at a time.
*/
$seen = [];
$twoAtOnce = function (array $messages, array $tools) use (&$seen): array {
    $seen[] = $messages;

    if (count($seen) === 1) {
        return ['text' => 'Checking both.', 'tool_calls' => [
            ['id' => 't1', 'name' => 'stock_list', 'input' => ['state' => 'low']],
            ['id' => 't2', 'name' => 'stock_list', 'input' => ['state' => 'out']],
        ]];
    }

    return ['text' => 'You have 2 low and 1 out of stock.', 'tool_calls' => []];
};

$result = chatConverse($conn, $ctx, 'ano ang paubos at ano ang ubos na', [], $twoAtOnce);

t_ok($result['ok'], 'two tools in one round is answered');
t_same(2, count($result['tools_used']), 'both tools ran');

/* The transcript the model saw on its second call. */
$second = $seen[1];
$resultTurns = array_filter($second, static fn (array $m): bool =>
    is_array($m['content']) && isset($m['content']['tool_results']));

t_same(1, count($resultTurns), 'both results arrived in a single message');

$turn = array_values($resultTurns)[0];
t_same(2, count($turn['content']['tool_results']), 'and that message holds both');
t_same('t1', $turn['content']['tool_results'][0]['id'], 'the first answers the first call');
t_same('t2', $turn['content']['tool_results'][1]['id'], 'the second answers the second');

/* A failed tool is still reported, never silently dropped. */
$seen = [];
$badCall = function (array $messages, array $tools) use (&$seen): array {
    $seen[] = $messages;

    if (count($seen) === 1) {
        return ['text' => null, 'tool_calls' => [
            ['id' => 't1', 'name' => 'no_such_tool', 'input' => []],
            ['id' => 't2', 'name' => 'stock_list', 'input' => ['state' => 'low']],
        ]];
    }

    return ['text' => 'You have 2 products running low.', 'tool_calls' => []];
};

$result = chatConverse($conn, $ctx, 'ano ang paubos', [], $badCall);
t_ok($result['ok'], 'one bad call does not sink the answer');

$turn = array_values(array_filter($seen[1], static fn (array $m): bool =>
    is_array($m['content']) && isset($m['content']['tool_results'])))[0];

t_same(2, count($turn['content']['tool_results']), 'the failed call is answered too');
t_ok($turn['content']['tool_results'][0]['is_error'], 'and marked as an error');
t_ok(empty($turn['content']['tool_results'][1]['is_error']), 'the good one is not');

/* ------------------------------------------------------------- the follow-up */

/* First question: the model calls a tool and answers with a count. */
$first = function (array $messages, array $tools): array {
    static $n = 0;
    $n++;

    if ($n === 1) {
        return ['text' => null, 'tool_calls' => [
            ['id' => 't1', 'name' => 'stock_list', 'input' => ['state' => 'low']]]];
    }

    return ['text' => 'Yes. You have 2 products below their reorder level.',
            'tool_calls' => []];
};

$session = [];
$result = chatConverse($conn, $ctx, 'may paubos ba tayo', [], $first);
t_ok($result['ok'], 'the first question is answered');

chatHistoryAppend($session, 'may paubos ba tayo', $result['text'], $ctx, $result['tables']);

/*
| The history must carry the rows, not just the prose. Without them the model
| has nothing to name in the follow-up, and the grounding rule has nothing to
| accept.
*/
$history = chatHistoryGet($session, $ctx);
t_ok(str_contains(json_encode($history), 'Rice 5kg'),
    'the rows the answer was built from are kept for the next question');

/* Second question: no tool call, names the products, and must be allowed. */
$followUp = function (array $messages, array $tools): array {
    return ['text' => 'Rice 5kg (2 left) and Coffee 3-in-1 (1 left).',
            'tool_calls' => []];
};

$result = chatConverse($conn, $ctx, 'ano-ano', $history, $followUp);

t_ok($result['ok'], 'the follow-up is answered instead of falling back');
t_ok(str_contains($result['text'], 'Rice 5kg'), 'and it names the products');

/*
| The rule still bites where it should: a fresh conversation, no tool, a
| figure. That is still a guess.
*/
$result = chatConverse($conn, $ctx, 'magkano ang benta ngayon', [], $followUp);
t_ok(!$result['ok'], 'with no tool anywhere in the conversation, figures are refused');
t_same('ungrounded', $result['reason'], 'for the usual reason');

/* And history from a different user grounds nothing. */
$otherCtx = ['company_id' => $companyId, 'user_id' => 99, 'employee_id' => null, 'role' => 'admin'];
t_same([], chatHistoryGet($session, $otherCtx), 'another user inherits no history');

t_done();
