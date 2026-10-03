<?php
/*
|--------------------------------------------------------------------------
| IS THE AI LAYER ACTUALLY ON?
|--------------------------------------------------------------------------
|
| Every failure in the conversational layer falls back to the keyword answer,
| on purpose -- the assistant must never break just because the network did.
| The cost of that is a layer which can be completely dead while the chatbot
| still appears to work. This script is how you tell the difference.
|
|   C:/xampp/php/php.exe tests/chatbot/ai_status.php
|
| It prints configuration only, and never the key itself. To also make one
| real API call -- which costs a fraction of a peso and is the only proof that
| the key, the model id and the network all work:
|
|   C:/xampp/php/php.exe tests/chatbot/ai_status.php --live
*/

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/chatbot/engine.php';
require_once __DIR__ . '/../../includes/chatbot/chat/api.php';
require_once __DIR__ . '/../../includes/chatbot/chat/conversation.php';

function statusLine(string $label, bool $ok, string $detail = ''): void
{
    echo '  [', $ok ? 'OK  ' : 'FAIL', '] ', str_pad($label, 34), $detail, "\n";
}

echo "\nSariSmart AI assistant -- configuration\n\n";

$path = chatbotSecretsPath();
$found = is_readable($path);

statusLine('Secrets file', $found, $path);

if (!$found) {
    echo "\n  The file does not exist or cannot be read. Create it OUTSIDE the\n";
    echo "  web root -- anything under htdocs can be requested by a browser.\n\n";
    exit(1);
}

$settings = chatbotAiSettings();

/* Length only. The key itself is never printed, not even partially: a shared
   terminal, a screenshot or a scrollback is all it takes to leak one. */
$key = (string) ($settings['anthropic_api_key'] ?? '');
$hasKey = $key !== '';

statusLine('API key present', $hasKey, $hasKey ? strlen($key) . ' characters' : 'empty');
statusLine('Key looks like an Anthropic key', str_starts_with($key, 'sk-ant-'),
    $hasKey ? '' : 'no key to check');

$enabled = !empty($settings['chatbot_chat_enabled']);
statusLine('Conversational layer enabled', $enabled,
    $enabled ? '' : "set 'chatbot_chat_enabled' => true");

$model = (string) ($settings['chatbot_chat_model'] ?? CHAT_DEFAULT_MODEL);
$dated = (bool) preg_match('/-\d{8}$/', $model);

statusLine('Model id', !$dated, $model
    . ($dated ? '  <-- a date suffix is not a real model id' : ''));

$endpoint = (string) ($settings['chatbot_chat_endpoint'] ?? CHAT_ENDPOINT);
statusLine('Endpoint', $endpoint === CHAT_ENDPOINT, $endpoint);

$cap = (int) ($settings['chatbot_chat_daily_cap'] ?? CHAT_DAILY_CAP);
statusLine('Daily cap per company', $cap > 0, $cap . ' questions');

if (!$hasKey || !$enabled) {
    echo "\n  The assistant is running on keywords alone. It answers, but only\n";
    echo "  the questions it has canned answers for.\n\n";
    exit(1);
}

echo "\n  Configuration is complete.\n";

if (!in_array('--live', $argv, true)) {
    echo "  Re-run with --live to make one real call and prove it end to end.\n\n";
    exit(0);
}

echo "\nLive call\n\n";

$started = microtime(true);

try {
    $reply = chatModelCallable()(
        [['role' => 'user', 'content' => 'Reply with the single word: ready']],
        []
    );
} catch (Throwable $error) {
    statusLine('Live call', false, $error->getMessage());
    echo "\n  The key, the model id or the network is wrong. Until this passes,\n";
    echo "  every question silently falls back to the keyword answer.\n\n";
    exit(1);
}

$elapsed = round(microtime(true) - $started, 2);

statusLine('Live call', true, $elapsed . 's');
statusLine('Answer received', trim((string) $reply['text']) !== '',
    trim((string) ($reply['text'] ?? '')));

echo "\n  The AI layer is live.\n\n";
