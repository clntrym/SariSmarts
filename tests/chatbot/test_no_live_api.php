<?php
/*
| The suite must never reach the live API.
|
| This is not a style rule. The secrets file sits at a fixed path outside the
| webroot, so once a real key was installed there, every test process read it
| and the keyword-routing tests quietly began consulting the model. The suite
| went nondeterministic -- the chip "Stock requests" resolved to
| inv_stock_requests on one run and finance_stock_requests on the next -- and
| every run spent money.
|
| bootstrap.php redirects the path to a file that does not exist. This test is
| what stops that being removed by someone who does not know why it is there.
*/
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../includes/chatbot/engine.php';

$path = chatbotSecretsPath();

t_ok($path !== CHATBOT_SECRETS,
    'the harness does not read the installation secrets file');
t_ok(!is_readable($path), 'and the path it does read holds nothing');

$settings = chatbotAiSettings();

t_same([], $settings, 'so a test sees no settings at all');
t_ok(empty($settings['anthropic_api_key']), 'and no key');

/*
| The two gates that decide whether a question costs money. Both must be shut
| for a plain test, whatever is installed on the machine running it.
*/
$conn = $GLOBALS['conn'];
$ctx = ['company_id' => 1, 'user_id' => 1, 'employee_id' => null, 'role' => 'admin'];

require_once __DIR__ . '/../../includes/chatbot/chat/api.php';

t_ok(!chatEnabled($conn, $ctx), 'the conversational layer is off in tests');

/* The routing layer gates on the same two settings, inside chatbotAiIntent.
   With no key it returns null without touching the network -- which is what
   made keyword routing deterministic again. */
t_same(null, chatbotAiIntent('magkano ang benta', ['pos_sales_today' => []]),
    'and the Phase 1 AI routing never leaves the machine');

/* Neither layer may carry a model id with a date glued on. */
t_ok(!preg_match('/-\d{8}$/', CHATBOT_AI_MODEL),
    'the routing model id is a real one');
t_ok(!preg_match('/-\d{8}$/', CHAT_DEFAULT_MODEL),
    'and so is the conversational one');

/* A test may still opt in, for its own fixture. That must keep working. */
$fixture = sys_get_temp_dir() . '/no_live_api_fixture.php';
file_put_contents($fixture, "<?php\nreturn " . var_export([
    'anthropic_api_key' => 'sk-ant-not-a-real-key',
    'chatbot_chat_enabled' => true,
], true) . ";\n");

register_shutdown_function(function () use ($fixture) { @unlink($fixture); });

$GLOBALS['chatbot_secrets_path'] = $fixture;
chatbotForgetSettings();

t_ok(chatEnabled($conn, $ctx), 'a test that wants the AI paths can still have them');

t_done();
