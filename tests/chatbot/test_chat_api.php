<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/factory.php';
require_once __DIR__ . '/../../includes/chatbot/engine.php';
require_once __DIR__ . '/../../includes/chatbot/log.php';
require_once __DIR__ . '/../../includes/chatbot/chat/api.php';

register_shutdown_function(function () use ($conn) { testCleanup($conn); });

$companyId = testMakeCompany($conn, 'Api Co', 2);
$ctx = ['company_id' => $companyId, 'user_id' => 1, 'employee_id' => null, 'role' => 'admin'];

/* With no secrets file at all, the layer is simply off. */
$GLOBALS['chatbot_secrets_path'] = sys_get_temp_dir() . '/definitely_not_here.php';
t_ok(!chatEnabled($conn, $ctx), 'with no key configured, the chat layer is off');

/* Now a key, pointed at an unreachable host. */
$secrets = sys_get_temp_dir() . '/chat_api_secrets.php';
file_put_contents($secrets, "<?php\nreturn " . var_export([
    'anthropic_api_key' => 'sk-ant-not-a-real-key',
    'chatbot_chat_enabled' => true,
    'chatbot_chat_daily_cap' => 2,
    'chatbot_chat_model' => 'claude-haiku-4-5-20251001',
    'chatbot_chat_endpoint' => 'https://api.invalid.test/v1/messages',
], true) . ";\n");

$GLOBALS['chatbot_secrets_path'] = $secrets;
register_shutdown_function(function () use ($secrets) { @unlink($secrets); });

t_ok(chatEnabled($conn, $ctx), 'with a key and the flag on, the layer is available');

/* The daily cap */
t_ok(chatWithinDailyCap($conn, $ctx), 'under the cap');
chatbotLog($conn, $ctx, 'q1', null, 'ai_chat', 'answered');
chatbotLog($conn, $ctx, 'q2', null, 'ai_chat', 'answered');
t_ok(!chatWithinDailyCap($conn, $ctx), 'at the cap, the chat layer stands down');

/* The real call, against a host that cannot resolve. */
$model = chatModelCallable();
$threw = false;
$started = microtime(true);

try {
    $model([['role' => 'user', 'content' => 'hi']], []);
} catch (Throwable $error) {
    $threw = true;
}

$elapsed = microtime(true) - $started;

t_ok($threw, 'an unreachable API throws, which the loop turns into a fallback');
t_ok($elapsed < 15, 'and it gives up quickly (' . round($elapsed, 2) . 's)');

/* Reply normalisation: text, tool calls, and rubbish. */
$reply = chatNormaliseReply(['content' => [['type' => 'text', 'text' => 'Hello']]]);
t_same('Hello', $reply['text'], 'a text reply is read');
t_same([], $reply['tool_calls'], 'with no tool calls');

$reply = chatNormaliseReply(['content' => [
    ['type' => 'text', 'text' => 'Checking'],
    ['type' => 'tool_use', 'id' => 'a1', 'name' => 'sales_summary',
     'input' => ['period' => 'today']],
]]);
t_same('sales_summary', $reply['tool_calls'][0]['name'], 'a tool call is read');
t_same('today', $reply['tool_calls'][0]['input']['period'], 'with its input');

$reply = chatNormaliseReply(['nonsense' => true]);
t_same(null, $reply['text'], 'a malformed body yields no text');
t_same([], $reply['tool_calls'], 'and no tool calls');

t_done();
