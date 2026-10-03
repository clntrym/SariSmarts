<?php
/*
| The wire format.
|
| The conversational layer used to flatten tool calls and their results into
| prose -- "Called tool stock_list with {...}" as an assistant message, the JSON
| as a user message. The API has content blocks for exactly this, and a model
| reading prose about a tool call it supposedly made is a model working blind.
|
| This test pins the shape the API actually documents: tool_use blocks on the
| assistant turn, tool_result blocks on the user turn, and every result of one
| round in a SINGLE user message -- splitting them teaches the model to stop
| asking for several tools at once.
*/
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/factory.php';
require_once __DIR__ . '/../../includes/chatbot/engine.php';
require_once __DIR__ . '/../../includes/chatbot/chat/api.php';

/* A plain question travels as a plain string. */
$messages = chatApiMessages([
    ['role' => 'user', 'content' => 'how many products are low'],
]);

t_same(1, count($messages), 'one turn in, one message out');
t_same('user', $messages[0]['role'], 'it is the user speaking');
t_same('how many products are low', $messages[0]['content'], 'and the text is untouched');

/* A model turn carrying text and two tool calls. */
$messages = chatApiMessages([
    ['role' => 'user', 'content' => 'how many are low and what sold today'],
    ['role' => 'assistant', 'content' => [
        'text' => 'Let me check both.',
        'tool_calls' => [
            ['id' => 'toolu_01', 'name' => 'stock_list', 'input' => ['state' => 'low']],
            ['id' => 'toolu_02', 'name' => 'sales_summary', 'input' => ['period' => 'today']],
        ],
    ]],
]);

$assistant = $messages[1];
t_same('assistant', $assistant['role'], 'the model turn is the assistant');
t_ok(is_array($assistant['content']), 'and it travels as content blocks');
t_same(3, count($assistant['content']), 'text plus two tool calls');

t_same('text', $assistant['content'][0]['type'], 'the text block comes first');
t_same('Let me check both.', $assistant['content'][0]['text'], 'with the text');

t_same('tool_use', $assistant['content'][1]['type'], 'then a real tool_use block');
t_same('toolu_01', $assistant['content'][1]['id'], 'carrying the id the API gave it');
t_same('stock_list', $assistant['content'][1]['name'], 'and the tool name');
t_same('low', $assistant['content'][1]['input']['state'], 'and the input, as an object');

t_same('tool_use', $assistant['content'][2]['type'], 'the second call is a block too');
t_same('toolu_02', $assistant['content'][2]['id'], 'with its own id');

/* A model turn with no text is all tool calls and no empty text block. */
$messages = chatApiMessages([
    ['role' => 'assistant', 'content' => [
        'text' => '',
        'tool_calls' => [['id' => 'toolu_03', 'name' => 'stock_list', 'input' => []]],
    ]],
]);

t_same(1, count($messages[0]['content']), 'an empty text block is not sent');
t_same('tool_use', $messages[0]['content'][0]['type'], 'only the call');

/* Both results come back in ONE user message. */
$messages = chatApiMessages([
    ['role' => 'assistant', 'content' => [
        'text' => '',
        'tool_calls' => [
            ['id' => 'toolu_01', 'name' => 'stock_list', 'input' => []],
            ['id' => 'toolu_02', 'name' => 'sales_summary', 'input' => []],
        ],
    ]],
    ['role' => 'user', 'content' => ['tool_results' => [
        ['id' => 'toolu_01', 'content' => '{"rows":[["Rice",3]]}', 'is_error' => false],
        ['id' => 'toolu_02', 'content' => 'Tool error: unknown period', 'is_error' => true],
    ]]],
]);

t_same(2, count($messages), 'two results still make one user message');

$results = $messages[1];
t_same('user', $results['role'], 'results come back as the user turn');
t_same(2, count($results['content']), 'both results in the one message');

t_same('tool_result', $results['content'][0]['type'], 'a real tool_result block');
t_same('toolu_01', $results['content'][0]['tool_use_id'], 'pointing at the call it answers');
t_ok(str_contains($results['content'][0]['content'], 'Rice'), 'carrying the rows');
t_ok(empty($results['content'][0]['is_error']), 'and not marked an error');

t_same('toolu_02', $results['content'][1]['tool_use_id'], 'the second answers its own call');
t_ok($results['content'][1]['is_error'], 'and is marked an error');

/* The request payload itself. */
$secrets = sys_get_temp_dir() . '/chat_wire_secrets.php';
file_put_contents($secrets, "<?php\nreturn " . var_export([
    'anthropic_api_key' => 'sk-ant-not-a-real-key',
    'chatbot_chat_enabled' => true,
], true) . ";\n");

$GLOBALS['chatbot_secrets_path'] = $secrets;
register_shutdown_function(function () use ($secrets) { @unlink($secrets); });

$payload = chatRequestPayload([['role' => 'user', 'content' => 'hi']], []);

/*
| Model ids are complete as published. A date-suffixed id is not a real model,
| and the request fails -- silently, because every failure here falls back to
| the keyword answer. That is how the whole AI layer can be dead without
| anybody noticing.
*/
t_ok(!preg_match('/-\d{8}$/', $payload['model']),
    'the model id carries no invented date suffix');
t_same('claude-sonnet-5-5', $payload['model'], 'and is the model we chose');

t_ok($payload['max_tokens'] >= 8000,
    'max_tokens leaves room for a real answer (' . $payload['max_tokens'] . ')');

t_same('low', $payload['output_config']['effort'],
    'effort is low: this is a chat over a shop database, not a reasoning problem');

t_same('default', $payload['fallbacks'] ?? null,
    'server-side fallback is on, so a refusal does not kill the question');

t_ok(in_array('server-side-fallback-2026-07-01', chatRequestBetas(), true),
    'and the beta that enables it is sent');

/* A refusal is a failure, so the question falls back instead of going blank. */
$reply = chatNormaliseReply(['stop_reason' => 'refusal', 'content' => []]);
t_same('refusal', $reply['stop_reason'], 'a refusal is reported as one');

$reply = chatNormaliseReply(['stop_reason' => 'end_turn',
                             'content' => [['type' => 'text', 'text' => 'Hi']]]);
t_same('end_turn', $reply['stop_reason'], 'and a normal stop is too');

t_done();
