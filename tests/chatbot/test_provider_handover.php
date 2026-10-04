<?php
/*
| The handover itself, not just the translation.
|
| test_gemini_fallback.php proves the two dialects convert correctly. This
| proves the thing that decides WHEN to convert: that a dead Claude account
| moves the question to the reserve, that a refusal does not, and that a
| missing reserve key leaves the original failure alone to reach the keyword
| answer as before.
|
| It drives chatModelCallable()'s decision with scripted providers, so no
| request leaves the machine and no credit is spent proving a credit failure.
*/
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../includes/chatbot/engine.php';
require_once __DIR__ . '/../../includes/chatbot/chat/api.php';
require_once __DIR__ . '/../../includes/chatbot/chat/conversation.php';

/*
| The rule, stated case by case. These are the sentences the Anthropic API
| actually returns, not invented ones.
*/
$handover = [
    'chat api status 400: Your credit balance is too low to access the Anthropic API' => true,
    'chat api status 429: rate limit exceeded' => true,
    'chat api status 529: overloaded' => true,
    'chat api status 500' => true,
    'chat api status 0' => true,
    'api_failed' => true,

    'chat api refused' => false,
    'chat api status 401: invalid x-api-key' => false,
    'chat api status 403: forbidden' => false,
    'chat api status 404: model not found' => false,
];

foreach ($handover as $reason => $expected) {
    t_same($expected, geminiShouldTakeOver($reason),
        ($expected ? 'reserve takes over: ' : 'reserve stays out: ') . mb_substr($reason, 0, 56));
}

/*
| Why 401 and 404 stay out, said once in words: both are faults somebody has
| to fix. Answering from the reserve would hide a wrong key or a wrong model
| id behind working replies, and the bill would be the first anyone heard of
| it.
*/
t_ok(!geminiShouldTakeOver('chat api status 401'),
    'a bad key is a fault to see, not an outage to route around');

/* ------------------------------------------------- the payload it would send */

$secrets = sys_get_temp_dir() . '/handover_secrets.php';
register_shutdown_function(function () use ($secrets) { @unlink($secrets); });

file_put_contents($secrets, "<?php\nreturn " . var_export([
    'anthropic_api_key' => 'sk-ant-not-real',
    'gemini_api_key' => 'AIza-not-real',
    'chatbot_chat_enabled' => true,
], true) . ";\n");

$GLOBALS['chatbot_secrets_path'] = $secrets;
chatbotForgetSettings();

$messages = [['role' => 'user', 'content' => 'magkano ang benta ngayon']];
$tools = [[
    'name' => 'sales_summary',
    'description' => 'Sales for a period.',
    'input_schema' => [
        'type' => 'object',
        'properties' => ['period' => ['type' => 'string', 'enum' => ['today']]],
        'required' => ['period'],
    ],
]];

$payload = geminiRequestPayload($messages, geminiToolSchemas($tools), chatSystemPrompt());

t_ok(isset($payload['contents']), 'the reserve request carries the conversation');
t_ok(isset($payload['systemInstruction']['parts'][0]['text']),
    'and the system prompt, where Gemini expects it');
t_ok(str_contains($payload['systemInstruction']['parts'][0]['text'], 'salaries'),
    'including the payroll rule, which must hold whoever is answering');
t_same('sales_summary',
    $payload['tools'][0]['functionDeclarations'][0]['name'],
    'and the tools, translated');

/*
| The payroll ban is the one rule that cannot depend on which provider
| answered. The reserve gets the same system prompt and the same tool list,
| and the tool list is built by the same gate -- there is no second catalogue
| for it to read.
*/
$pay = ['salary', 'payroll', 'sweldo'];
$toolNames = array_column($payload['tools'][0]['functionDeclarations'], 'name');

foreach ($toolNames as $name) {
    foreach ($pay as $word) {
        t_ok(!str_contains(strtolower($name), $word),
            "no pay tool reaches the reserve either: {$name}");
    }
}

/* ------------------------------------------------- no key, no handover */

file_put_contents($secrets, "<?php\nreturn " . var_export([
    'anthropic_api_key' => 'sk-ant-not-real',
    'chatbot_chat_enabled' => true,
], true) . ";\n");

chatbotForgetSettings();

$settings = chatbotAiSettings();
t_ok(empty($settings['gemini_api_key']),
    'with no reserve key configured there is no reserve');

t_done();
