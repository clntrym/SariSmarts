<?php
/*
| The reserve provider.
|
| When the Claude credits run out the API answers 400 with
| "credit balance is too low", and every question from then on falls back to
| the keyword answers. That is safe and it is also the end of the feature --
| so a second provider stands behind the first.
|
| Gemini speaks a different shape. Claude takes `tools` with an
| `input_schema`, returns `tool_use` blocks and reads `tool_result` blocks;
| Gemini takes `tools[].functionDeclarations` with `parameters`, returns
| `functionCall` and reads `functionResponse`. The loop in conversation.php
| must not learn either dialect, so the translation lives here and is tested
| here -- a mistranslated tool is a model that cannot read the shop's data,
| which is the entire point of the thing.
*/
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../includes/chatbot/chat/gemini.php';

/* ------------------------------------------------- tools, Claude -> Gemini */

$claudeTools = [
    [
        'name' => 'stock_list',
        'description' => 'Products by stock state.',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'state' => ['type' => 'string', 'enum' => ['low', 'out', 'all']],
                'limit' => ['type' => 'integer'],
            ],
            'required' => ['state'],
        ],
    ],
    [
        'name' => 'company_profile',
        'description' => 'The company itself.',
        'input_schema' => ['type' => 'object', 'properties' => new stdClass(), 'required' => []],
    ],
];

$gemini = geminiToolSchemas($claudeTools);

t_same(1, count($gemini), 'every tool goes in one declarations block');
$declarations = $gemini[0]['functionDeclarations'];
t_same(2, count($declarations), 'both tools are declared');

t_same('stock_list', $declarations[0]['name'], 'the name carries over');
t_same('Products by stock state.', $declarations[0]['description'], 'and the description');
t_same('OBJECT', $declarations[0]['parameters']['type'], 'the schema type is upper case, as Gemini wants');
t_same('STRING', $declarations[0]['parameters']['properties']['state']['type'], 'and so is each property');
t_same(['low', 'out', 'all'], $declarations[0]['parameters']['properties']['state']['enum'],
    'an enum survives the trip');
t_same('INTEGER', $declarations[0]['parameters']['properties']['limit']['type'],
    'integer is INTEGER, not NUMBER -- a float would be a different tool call');
t_same(['state'], $declarations[0]['parameters']['required'], 'required survives');

/*
| A tool with no inputs. Claude is sent properties as {}; Gemini rejects a
| declaration whose parameters have no properties at all, so the whole
| parameters block is dropped instead.
*/
t_ok(!isset($declarations[1]['parameters']),
    'a tool with no inputs declares no parameters, which is what Gemini accepts');

/* ------------------------------------------- messages, Claude -> Gemini */

$messages = [
    ['role' => 'user', 'content' => 'what is low'],
    ['role' => 'assistant', 'content' => [
        'text' => 'Checking.',
        'tool_calls' => [['id' => 't1', 'name' => 'stock_list', 'input' => ['state' => 'low']]],
    ]],
    /* The name travels beside the id because Gemini pairs a response to its
       call by name, and conversation.php puts both there. */
    ['role' => 'user', 'content' => ['tool_results' => [
        ['id' => 't1', 'name' => 'stock_list',
         'content' => '{"rows":[["Rice",3]]}', 'is_error' => false],
    ]]],
];

$contents = geminiContents($messages);

t_same(3, count($contents), 'three turns in, three out');
t_same('user', $contents[0]['role'], 'the question is the user');
t_same('what is low', $contents[0]['parts'][0]['text'], 'with its text');

/* Gemini calls the assistant "model". */
t_same('model', $contents[1]['role'], 'the assistant turn is called model');
t_same('Checking.', $contents[1]['parts'][0]['text'], 'its text survives');
t_same('stock_list', $contents[1]['parts'][1]['functionCall']['name'], 'and the call becomes a functionCall');
t_same('low', $contents[1]['parts'][1]['functionCall']['args']['state'], 'with its arguments');

/* A result goes back as functionResponse, named for the tool, not the id --
   Gemini matches on the name. */
t_same('user', $contents[2]['role'], 'results come back on the user turn');
t_same('stock_list', $contents[2]['parts'][0]['functionResponse']['name'],
    'a result names the tool it answers, because Gemini matches on the name');
t_ok(str_contains(json_encode($contents[2]['parts'][0]['functionResponse']['response']), 'Rice'),
    'and carries the rows');

/* ------------------------------------------- the reply, Gemini -> Claude */

$reply = geminiNormaliseReply([
    'candidates' => [[
        'content' => ['parts' => [
            ['text' => 'Two products are low.'],
        ]],
        'finishReason' => 'STOP',
    ]],
]);

t_same('Two products are low.', $reply['text'], 'a text answer reads back');
t_same([], $reply['tool_calls'], 'with no calls');

$reply = geminiNormaliseReply([
    'candidates' => [[
        'content' => ['parts' => [
            ['functionCall' => ['name' => 'stock_list', 'args' => ['state' => 'out']]],
        ]],
    ]],
]);

t_same('stock_list', $reply['tool_calls'][0]['name'], 'a function call reads back as a tool call');
t_same('out', $reply['tool_calls'][0]['input']['state'], 'with its input');
t_ok(trim((string) $reply['tool_calls'][0]['id']) !== '',
    'and is given an id, because the loop pairs results to calls by id');

/* A refusal or an empty candidate must not look like an answer. */
$reply = geminiNormaliseReply(['candidates' => [['finishReason' => 'SAFETY']]]);
t_same(null, $reply['text'], 'a blocked answer yields no text');
t_same([], $reply['tool_calls'], 'and no calls');

t_same(null, geminiNormaliseReply([])['text'], 'so does a malformed body');

/* ------------------------------------------------------- when it is used */

t_ok(geminiShouldTakeOver('chat api status 400'), 'a 400 is a reason to try the reserve');
t_ok(geminiShouldTakeOver('chat api status 429'), 'so is being rate limited');
t_ok(geminiShouldTakeOver('chat api status 529'), 'so is the API being overloaded');
t_ok(geminiShouldTakeOver('credit balance is too low'), 'and so is running out of credit, by name');

t_ok(!geminiShouldTakeOver('chat api refused'),
    'a safety refusal is NOT: the reserve would be asked the same question and '
    . 'should give the same answer');
t_ok(!geminiShouldTakeOver('chat api status 401'),
    'nor is a bad key -- that is a configuration fault, and silently answering '
    . 'from elsewhere would hide it');

t_done();
