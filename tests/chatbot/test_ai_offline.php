<?php
/*
| The failure path, driven for real: a key IS configured, and the API cannot be
| reached.
|
| test_ai_fallback.php proves the decision logic with an injected callable.
| This one proves the thing that actually happens in a shop when the internet
| drops -- curl fails, chatbotAiIntent returns null, and the assistant answers
| anyway.
*/
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/factory.php';
require_once __DIR__ . '/../../includes/chatbot/engine.php';

register_shutdown_function(function () use ($conn) { testCleanup($conn); });

/* A secrets file of our own, so no real key is read or written. */
$secrets = sys_get_temp_dir() . '/chatbot_test_secrets.php';

file_put_contents($secrets, "<?php\nreturn " . var_export([
    'anthropic_api_key' => 'sk-ant-not-a-real-key',
    'chatbot_ai_enabled' => true,
    /* A host that cannot resolve: the shop's internet is down. */
    'chatbot_ai_endpoint' => 'https://api.invalid.test/v1/messages',
], true) . ";\n");

$GLOBALS['chatbot_secrets_path'] = $secrets;

register_shutdown_function(function () use ($secrets) { @unlink($secrets); });

$settings = chatbotAiSettings();
t_ok(!empty($settings['chatbot_ai_enabled']), 'the test key is configured and enabled');

$intents = chatbotIntents();

$started = microtime(true);
$picked = chatbotAiIntent('magkano ang benta ngayong araw', $intents);
$elapsed = microtime(true) - $started;

t_same(null, $picked, 'an unreachable API returns null rather than an error');
t_ok($elapsed < 10, 'it gives up quickly instead of hanging the request (' . round($elapsed, 2) . 's)');

/* And the assistant still answers, through keywords. */
$companyId = testMakeCompany($conn, 'Offline Co', 2);
$conn->query("INSERT INTO sales (company_id, total_amount, tax_amount, cash_received, change_amount, payment_method)
              VALUES ({$companyId}, 77.00, 0.00, 100.00, 23.00, 'Cash')");

$ctx = ['company_id' => $companyId, 'user_id' => 1, 'employee_id' => null, 'role' => 'admin'];

$result = chatbotAnswer($conn, $ctx, 'magkano ang benta ngayong araw');

t_ok($result['ok'], 'the assistant still answers with the API unreachable');
t_same('keyword', $result['matched_by'], 'and says it answered from keywords');
t_ok(str_contains(json_encode($result['answer']), '77.00'), 'the figure is the real one');



/*
| What actually leaves the server. The spec promises the question and the
| allowed labels, and nothing belonging to the company -- so read the prompt
| line by line and prove every line is one of those.
*/
$allowed = chatbotAllowedIntents($conn, $companyId, 'admin');
$prompt = chatbotAiPrompt('magkano ang benta ni Juan Dela Cruz', $allowed);

$expected = [];

foreach ($allowed as $id => $intent) {
    $expected[] = $id . ' = ' . $intent['label'];
}

$boilerplate = [
    '',
    'Choose which one of these questions the user is asking.',
    'User question: magkano ang benta ni Juan Dela Cruz',
    'Answer with the id alone, or the word none. No other words.',
];

$unexpected = array_diff(explode("\n", $prompt), $expected, $boilerplate);

t_same([], array_values($unexpected),
    'the prompt carries only the question and the allowed ids and labels');
t_ok(!str_contains($prompt, '77.00'), 'no figure from the company reaches the API');
t_ok(!str_contains($prompt, 'CHATBOT-TEST'), 'the company name does not reach the API');
t_ok(str_contains($prompt, 'sales_today = '), 'the allowed ids and labels are what it chooses from');

t_done();
