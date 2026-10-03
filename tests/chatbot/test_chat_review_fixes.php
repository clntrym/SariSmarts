<?php
/*
| The findings from the whole-branch review of the conversational layer, each
| pinned by a test.
*/
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/factory.php';
require_once __DIR__ . '/../../includes/chatbot/engine.php';
require_once __DIR__ . '/../../includes/chatbot/log.php';
require_once __DIR__ . '/../../includes/chatbot/chat/conversation.php';
require_once __DIR__ . '/../../includes/chatbot/chat/api.php';
require_once __DIR__ . '/../../includes/chatbot/chat/history.php';

register_shutdown_function(function () use ($conn) { testCleanup($conn); });

$companyId = testMakeCompany($conn, 'Chat Review Co', 2);
$other = testMakeCompany($conn, 'Chat Review Other', 2);

$ctx = ['company_id' => $companyId, 'user_id' => 7, 'employee_id' => null, 'role' => 'admin'];

/*
| C1: the conversation must not survive into another person's session. Logging
| out does not destroy the session in this app, so the history carries the
| previous user's answers to whoever signs in next on that browser.
*/
$session = [];
chatHistoryAppend($session, 'magkano ang benta', 'Ang benta ay 1,111.11.', $ctx);

t_same(2, count(chatHistoryGet($session, $ctx)), 'the same user keeps their own history');

$nextUser = ['company_id' => $companyId, 'user_id' => 8,
             'employee_id' => null, 'role' => 'cashier'];
t_same([], chatHistoryGet($session, $nextUser),
    'the next user on that browser inherits nothing');

$otherCompany = ['company_id' => $other, 'user_id' => 7,
                 'employee_id' => null, 'role' => 'admin'];
t_same([], chatHistoryGet($session, $otherCompany),
    'and neither does the same person signed into another company');

/*
| I5: the cap belongs on the read as well. A session that grew before the cap
| existed -- or was tampered with -- must not reach the model unbounded.
*/
$session = ['chatbot_history' => ['user_id' => 7, 'company_id' => $companyId, 'turns' => []]];

for ($i = 0; $i < 100; $i++) {
    $session['chatbot_history']['turns'][] = ['role' => 'user', 'text' => "q{$i}"];
    $session['chatbot_history']['turns'][] = ['role' => 'assistant', 'text' => "a{$i}"];
}

t_same(CHAT_HISTORY_TURNS * 2, count(chatHistoryGet($session, $ctx)),
    'an oversized history is cut when it is read, not only when it is written');

$session['chatbot_history']['turns'][] = ['text' => 'no role at all'];
$session['chatbot_history']['turns'][] = ['role' => 'user'];
$session['chatbot_history']['turns'][] = 'not even an array';

foreach (chatHistoryGet($session, $ctx) as $turn) {
    t_ok(isset($turn['role'], $turn['text']), 'every turn read back is well formed');
    t_ok(in_array($turn['role'], ['user', 'assistant'], true), 'with a known role');
}

/*
| C2: the stock request statuses must be the ones the database actually has.
| "Pending" matched nothing, so the assistant said there were none.
*/
$statuses = chatTools()['stock_requests']['input']['status']['values'];

foreach (['Pending Finance', 'Finance Approved', 'Pending Admin', 'Admin Approved',
          'Received', 'Cancelled', 'all'] as $value) {
    t_ok(in_array($value, $statuses, true), "stock_requests offers '{$value}'");
}

t_ok(!in_array('Pending', $statuses, true), "the status 'Pending' that matches nothing is gone");
t_ok(!in_array('Approved', $statuses, true), "so is 'Approved'");

$conn->query("INSERT INTO stock_requests (request_code, total_price, reason, status, company_id)
              VALUES ('SR-REVIEW-1', 100.00, 'test', 'Pending Finance', {$companyId})");

$result = chatRunTool($conn, $ctx, 'stock_requests', ['status' => 'Pending Finance']);
t_ok($result['ok'], 'a real status runs');
t_same(1, count($result['rows']), 'and finds the waiting request');

/*
| I1: truncation must be reported against what was ASKED for, not against the
| cap, and the extra row fetched to detect it must never reach the model.
*/
$conn->query("INSERT INTO categories (category_name, company_id) VALUES ('Bulk', {$companyId})");
$categoryId = (int) $conn->insert_id;

for ($i = 0; $i < 70; $i++) {
    $conn->query("INSERT INTO products (product_name, category_id, company_id)
                  VALUES ('Bulk {$i}', {$categoryId}, {$companyId})");
    $productId = (int) $conn->insert_id;
    $conn->query("INSERT INTO inventory (product_id, quantity, purchase_cost, selling_price, reorder_level, company_id)
                  VALUES ({$productId}, 1, 1.00, 2.00, 5, {$companyId})");
}

foreach ([5, 10, 49, 50] as $limit) {
    $result = chatRunTool($conn, $ctx, 'stock_list', ['state' => 'low', 'limit' => $limit]);

    t_same($limit, count($result['rows']), "stock_list limit {$limit} returns exactly that many");
    t_ok($result['truncated'], "stock_list limit {$limit} says more matched");
}

$result = chatRunTool($conn, $ctx, 'top_products', ['period' => 'this_month']);
t_ok(count($result['rows']) <= 10, 'top_products keeps to its default limit');

for ($i = 0; $i < 60; $i++) {
    $conn->query("INSERT INTO stock_requests (request_code, total_price, reason, status, company_id)
                  VALUES ('SR-BULK-{$i}', 10.00, 'bulk', 'Received', {$companyId})");
}

$result = chatRunTool($conn, $ctx, 'stock_requests', ['status' => 'all', 'limit' => 50]);
t_same(50, count($result['rows']), 'stock_requests keeps to the cap');
t_ok($result['truncated'], 'and reports that more matched');

/*
| I4: a reply carrying hundreds of tool calls must be stopped, whether the
| calls succeed or are refused.
*/
$flood = function (array $messages, array $tools): array {
    $calls = [];

    for ($i = 0; $i < 200; $i++) {
        $calls[] = ['id' => "x{$i}", 'name' => 'no_such_tool', 'input' => []];
    }

    return ['text' => null, 'tool_calls' => $calls];
};

$started = microtime(true);
$result = chatConverse($conn, $ctx, 'flood', [], $flood);
$elapsed = microtime(true) - $started;

t_ok($elapsed < 10, 'a flood of refused calls is cut short (' . round($elapsed, 1) . 's)');
t_ok($result['rounds'] <= CHAT_MAX_ROUNDS, 'and still respects the round limit');

/* And a reply with many VALID calls stops at the tool limit. */
$manyValid = function (array $messages, array $tools): array {
    $calls = [];

    for ($i = 0; $i < 12; $i++) {
        $calls[] = ['id' => "v{$i}", 'name' => 'sales_summary', 'input' => ['period' => 'today']];
    }

    return ['text' => null, 'tool_calls' => $calls];
};

$result = chatConverse($conn, $ctx, 'many', [], $manyValid);
t_same(CHAT_MAX_TOOL_CALLS, count($result['tools_used']),
    'a single reply cannot run more tools than the limit');

/*
| I7: with no tool behind it, any figure at all is a guess.
*/
t_ok(!chatAnswerIsGrounded('Ang benta ninyo ngayon ay P50.', []),
    'a small invented figure is caught too');
t_ok(!chatAnswerIsGrounded('Nakabenta kayo ng 12 items ngayon.', []),
    'so is an invented count');
t_ok(!chatAnswerIsGrounded('Tumaas ng 20 porsyento.', []), 'and an invented percentage');
t_ok(chatAnswerIsGrounded('Wala akong makitang datos para diyan.', []),
    'prose with no figures is still fine');
t_ok(chatAnswerIsGrounded('Ang benta ay 12 items.', ['sales_summary']),
    'and a tool-backed answer is unaffected');

/*
| I8: an assistant turn that carried both text and tool calls must keep the
| tool calls in the transcript.
*/
$messages = chatApiMessages([
    ['role' => 'user', 'content' => 'magkano ang benta'],
    ['role' => 'assistant', 'is_tool_use' => true, 'content' => [
        'text' => 'Let me check.',
        'tool_calls' => [['id' => 't1', 'name' => 'sales_summary',
                          'input' => ['period' => 'today']]],
    ]],
]);

t_same(2, count($messages), 'both turns survive');

/* Since the wire fix these travel as content blocks rather than prose, but the
   thing I8 was protecting is the same: the call must not be dropped. */
$blocks = $messages[1]['content'];

t_same('text', $blocks[0]['type'], 'the text it wrote alongside survives');
t_same('Let me check.', $blocks[0]['text'], 'with its words');
t_same('tool_use', $blocks[1]['type'], 'and the call is a real tool_use block');
t_same('sales_summary', $blocks[1]['name'],
    'the tool the model called is still in the transcript');

/*
| I10: whether a chat result may be shown, extracted so the fallback rule can
| be tested rather than eyeballed.
*/
t_ok(chatResultUsable(['ok' => true, 'text' => 'Ang benta ay 250.00.']),
    'a successful answer is usable');
t_ok(!chatResultUsable(['ok' => false, 'text' => 'anything']), 'a failure is not');
t_ok(!chatResultUsable(['ok' => true, 'text' => '']), 'nor is an empty answer');
t_ok(!chatResultUsable(['ok' => true, 'text' => '   ']), 'nor whitespace');
t_ok(!chatResultUsable([]), 'nor a malformed result');

/*
| I6: a chat attempt that fails has already spent API calls, so it must count
| against the daily budget.
*/
$before = chatWithinDailyCap($conn, $ctx);
chatbotLogChatAttempt($conn, $ctx, 'a question that failed');

$stmt = $conn->prepare("SELECT matched_by, outcome FROM chatbot_messages
                        WHERE company_id = ? ORDER BY message_id DESC LIMIT 1");
$stmt->bind_param("i", $companyId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

t_same('ai_chat', $row['matched_by'], 'a failed attempt is recorded as a chat call');
t_same('no_match', $row['outcome'], 'with an outcome that says it did not answer');

/*
| The prose IS the answer for a chat reply, so it must not be handed to the
| widget as a muted footnote under an empty heading.
*/
$payload = chatAnswerPayload([
    'ok' => true,
    'text' => 'Mas mataas ang benta ngayong buwan kaysa noong nakaraan.',
    'tables' => [['tool' => 'sales_summary', 'columns' => ['total_sales'],
                  'rows' => [['250.00']], 'truncated' => false]],
    'tools_used' => ['sales_summary'],
]);

t_same('Mas mataas ang benta ngayong buwan kaysa noong nakaraan.', $payload['prose'],
    "the model's answer is the card's main text");
t_same(null, $payload['note'] ?? null, 'and not the footnote reserved for caveats');
t_same(1, count($payload['tables']), 'the figures travel with it as a table');
t_same([], $payload['lines'], 'there are no label/value lines for a chat answer');

t_done();
