<?php
/*
| Switching the model must not silently kill the AI layer.
|
| 'chatbot_chat_model' is a knob in the secrets file, so somebody will one day
| set it to a cheaper model. Not every model accepts the same request: effort
| is rejected outright by Haiku 4.5, and server-side fallback is only served for
| a handful of models. Sending either to a model that does not take it is a 400
| -- and a 400 here is invisible, because every failure falls back to the
| keyword answer.
|
| That is exactly how the layer was dead before: one wrong field, no error
| anybody saw. So the payload asks the model what it accepts.
*/
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../includes/chatbot/engine.php';
require_once __DIR__ . '/../../includes/chatbot/chat/api.php';

$secrets = sys_get_temp_dir() . '/chat_switch_secrets.php';
register_shutdown_function(function () use ($secrets) { @unlink($secrets); });
$GLOBALS['chatbot_secrets_path'] = $secrets;

function payloadFor(string $model): array
{
    global $secrets;

    file_put_contents($secrets, "<?php\nreturn " . var_export([
        'anthropic_api_key' => 'sk-ant-not-a-real-key',
        'chatbot_chat_enabled' => true,
        'chatbot_chat_model' => $model,
    ], true) . ";\n");

    /* chatbotAiSettings() caches per path, so the cache must go too. */
    chatbotForgetSettings();

    return chatRequestPayload([['role' => 'user', 'content' => 'hi']], []);
}

/* The model we chose takes both. */
$payload = payloadFor('claude-sonnet-5-5');
t_same('low', $payload['output_config']['effort'] ?? null, 'Sonnet 5.5 takes effort');
t_same('default', $payload['fallbacks'] ?? null, 'and server-side fallback');
t_ok(in_array(CHAT_FALLBACK_BETA, chatRequestBetas('claude-sonnet-5-5'), true),
    'so the beta is sent for it');

/* Haiku 4.5 rejects effort, and is not served server-side fallback. */
$payload = payloadFor('claude-haiku-4-5');
t_ok(!isset($payload['output_config']),
    'Haiku 4.5 is sent no effort, which it would reject');
t_ok(!isset($payload['fallbacks']),
    'and no fallbacks, which it is not served');
t_same([], chatRequestBetas('claude-haiku-4-5'),
    'and no betas it has no use for');

/* The rest of the request is the same whichever model it is. */
t_same(CHAT_MAX_TOKENS, $payload['max_tokens'], 'max_tokens is unchanged');
t_ok(isset($payload['system'], $payload['tools'], $payload['messages']),
    'and so are the system prompt, the tools and the messages');

/* Opus 5.5 takes both, like Sonnet 5.5. */
$payload = payloadFor('claude-opus-5-5');
t_same('low', $payload['output_config']['effort'] ?? null, 'Opus 5.5 takes effort');
t_same('default', $payload['fallbacks'] ?? null, 'and fallback');

/*
| An unknown model gets the plain request. Being wrong in this direction costs
| a little quality; being wrong the other way costs the whole answer.
*/
$payload = payloadFor('some-model-we-have-never-heard-of');
t_ok(!isset($payload['output_config']), 'an unknown model is sent no effort');
t_ok(!isset($payload['fallbacks']), 'and no fallbacks');

/* The capability check itself. */
t_ok(chatModelSupports('claude-sonnet-5-5', 'effort'), 'Sonnet 5.5 supports effort');
t_ok(!chatModelSupports('claude-haiku-4-5', 'effort'), 'Haiku 4.5 does not');
t_ok(chatModelSupports('claude-opus-5-5', 'fallbacks'), 'Opus 5.5 supports fallback');
t_ok(!chatModelSupports('claude-haiku-4-5', 'fallbacks'), 'Haiku 4.5 does not');

/*
| Prompt caching has a minimum prefix that differs per model, and a prefix
| below it does not cache -- silently, with no error. Ours is around 2,100
| tokens for an owner, which caches on Sonnet 5.5 and does not on Haiku 4.5.
| Anyone reasoning about cost needs this to be stated, not rediscovered.
*/
t_same(512, chatCacheMinimumTokens('claude-sonnet-5-5'), 'Sonnet 5.5 caches from 512 tokens');
t_same(4096, chatCacheMinimumTokens('claude-haiku-4-5'), 'Haiku 4.5 needs 4096');

t_done();
