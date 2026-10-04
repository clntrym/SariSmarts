<?php
/*
|--------------------------------------------------------------------------
| THE ONLY OUTBOUND CALL IN THE SYSTEM
|--------------------------------------------------------------------------
|
| It sends the question and the labels of the intents this user may already
| ask. It never sends a record, a figure, or a company name. What comes back is
| checked by the caller against that same list, so a wrong or manipulated reply
| cannot widen anyone's access.
|
| Every failure returns null, and the keyword matcher answers instead. The
| assistant does not stop working because a shop lost its internet.
*/

/*
| The routing layer maps a question onto one keyword intent and returns at most
| 20 tokens, so the cheapest model is the right one here. The id carries no date
| suffix: a suffixed id is not a real model, the call 400s, and -- because this
| layer falls back silently like every other -- nothing says so. It was wrong
| here for exactly as long as it was wrong in the conversational layer.
*/
const CHATBOT_AI_MODEL = 'claude-haiku-4-5';

/* Milliseconds, so 2.5 seconds is actually 2.5 seconds: CURLOPT_TIMEOUT is
   whole seconds and would have truncated it to 2. */
const CHATBOT_AI_TIMEOUT_MS = 2500;
const CHATBOT_AI_ENDPOINT = 'https://api.anthropic.com/v1/messages';
const CHATBOT_AI_DAILY_CAP = 200;

/*
| Outside the webroot, like the registration staging folder: a key inside
| htdocs is one misconfiguration away from being downloadable.
|
| Two names, because the product was renamed and the file on disk was not.
| The new name is preferred and the old one still works, so the rename can
| happen whenever it is convenient and nothing breaks on either side of it --
| the alternative is an edit that silently switches the AI layer off until
| somebody notices the key is "missing".
*/
const CHATBOT_SECRETS = 'C:\\xampp\\retailcore_secrets.php';
const CHATBOT_SECRETS_LEGACY = 'C:\\xampp\\sarismart_secrets.php';

/**
 * Where the key lives. Overridable only from PHP itself, never from a request,
 * so the offline and failure paths can be tested without a real key.
 */
function chatbotSecretsPath(): string
{
    $override = $GLOBALS['chatbot_secrets_path'] ?? null;

    if ($override !== null) {
        return (string) $override;
    }

    /* The new name wins when it is there; the old one keeps working until
       somebody renames the file. */
    return is_readable(CHATBOT_SECRETS) ? CHATBOT_SECRETS : CHATBOT_SECRETS_LEGACY;
}

/**
 * The read settings, held by reference so they can be dropped again.
 *
 * Reading the secrets file on every call would put a disk hit in front of
 * every question; caching it forever makes the settings untestable. This is
 * the middle: cached, and clearable.
 */
function &chatbotSettingsCache(): array
{
    static $cache = [];

    return $cache;
}

function chatbotAiSettings(): array
{
    $cache = &chatbotSettingsCache();

    $path = chatbotSecretsPath();

    if (!array_key_exists($path, $cache)) {
        $cache[$path] = is_readable($path) ? (array) require $path : [];
    }

    return $cache[$path];
}

/**
 * Forget what was read, so the next call reads the file again.
 *
 * Only tests need this: a live request reads the settings once and is done.
 */
function chatbotForgetSettings(): void
{
    $cache = &chatbotSettingsCache();
    $cache = [];
}

/**
 * Whether this company has any of today's AI budget left.
 *
 * Each question makes at most one API call, so counting today's questions
 * bounds the spend. Without this, a page left open or a script pointed at the
 * endpoint turns into a bill: the per-minute rate limit alone allows 28,800
 * questions a day per user.
 *
 * Past the cap the assistant keeps working -- on keywords, for free.
 */
function chatbotAiWithinDailyCap(mysqli $conn, array $ctx): bool
{
    $settings = chatbotAiSettings();
    $cap = (int) ($settings['chatbot_ai_daily_cap'] ?? CHATBOT_AI_DAILY_CAP);

    if ($cap <= 0) {
        return false;
    }

    $stmt = $conn->prepare("
        SELECT COUNT(*) AS asked
        FROM chatbot_messages
        WHERE company_id = ? AND DATE(created_at) = CURDATE()
    ");
    $stmt->bind_param("i", $ctx['company_id']);
    $stmt->execute();
    $asked = (int) $stmt->get_result()->fetch_assoc()['asked'];
    $stmt->close();

    return $asked < $cap;
}

/**
 * Everything that leaves this server: the question, and the ids and labels of
 * the intents this user may already ask.
 *
 * Built as its own function so a test can read exactly what is sent, rather
 * than trusting a promise that no company data goes out.
 */
function chatbotAiPrompt(string $question, array $allowedIntents): string
{
    $menu = [];

    foreach ($allowedIntents as $id => $intent) {
        $menu[] = $id . ' = ' . $intent['label'];
    }

    return "Choose which one of these questions the user is asking.\n\n"
        . implode("\n", $menu)
        . "\n\nUser question: " . mb_substr($question, 0, 500)
        . "\n\nAnswer with the id alone, or the word none. No other words.";
}

/**
 * The id of the intent the model believes the question means, or null.
 *
 * Returning null is the normal, safe outcome for every failure: no key,
 * switched off, no internet, a timeout, an HTTP error, or a reply that is not
 * one of the ids we sent.
 */
function chatbotAiIntent(string $question, array $allowedIntents): ?string
{
    $settings = chatbotAiSettings();

    if (empty($settings['chatbot_ai_enabled']) || empty($settings['anthropic_api_key'])) {
        return null;
    }

    if ($allowedIntents === []) {
        return null;
    }

    $payload = json_encode([
        'model' => CHATBOT_AI_MODEL,
        'max_tokens' => 20,
        'messages' => [[
            'role' => 'user',
            'content' => chatbotAiPrompt($question, $allowedIntents),
        ]],
    ]);

    $endpoint = (string) ($settings['chatbot_ai_endpoint'] ?? CHATBOT_AI_ENDPOINT);

    $curl = curl_init($endpoint);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT_MS => CHATBOT_AI_TIMEOUT_MS,
        CURLOPT_CONNECTTIMEOUT_MS => 2000,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-api-key: ' . $settings['anthropic_api_key'],
            'anthropic-version: 2023-06-01',
        ],
    ]);

    $response = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($response === false || $status !== 200) {
        return null;
    }

    $body = json_decode((string) $response, true);
    $text = trim((string) ($body['content'][0]['text'] ?? ''));

    return isset($allowedIntents[$text]) ? $text : null;
}
