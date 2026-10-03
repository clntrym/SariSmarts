<?php
/*
|--------------------------------------------------------------------------
| THE CONVERSATIONAL API CALL
|--------------------------------------------------------------------------
|
| The second and last file in the system that calls out (the first is
| ai_client.php). Everything it can go wrong with -- no key, disabled, over
| cap, unreachable, slow, malformed -- ends with the Phase 1 answer instead.
*/

require_once __DIR__ . '/../ai_client.php';

const CHAT_MODEL_TIMEOUT_MS = 15000;
const CHAT_DAILY_CAP = 100;

/*
| Model ids are complete as Anthropic publishes them. An id with a date glued
| on the end is not a real model: the request 400s, and because every failure
| in this layer falls back to the keyword answer, the whole AI layer goes dead
| without a single visible error. It did, for exactly that reason.
*/
const CHAT_DEFAULT_MODEL = 'claude-sonnet-5-5';
const CHAT_ENDPOINT = 'https://api.anthropic.com/v1/messages';

/* Room for a real answer. 1024 truncated anything longer than a short list. */
const CHAT_MAX_TOKENS = 16000;

/*
| This is a chat over a shop's own database, not a reasoning problem: the work
| is picking the right tool and reading rows back. Low effort keeps the answer
| quick and the bill small, which matters when a cashier asks twenty questions
| in a shift.
*/
const CHAT_EFFORT = 'low';

/*
| Server-side fallback. The safety classifiers can decline a request outright
| -- and a sari-sari store asking about "expired stock" or a supplier dispute
| is exactly the kind of ordinary question that can trip one. With this on, the
| API answers on another model instead of the question simply failing.
*/
const CHAT_FALLBACK_BETA = 'server-side-fallback-2026-07-01';

/**
 * What the model is told about itself before it sees the question.
 *
 * It lives here rather than in conversation.php because only the API call uses
 * it: the loop is provider-agnostic and must stay testable without it.
 */
function chatSystemPrompt(): string
{
    return "You are the assistant inside SariSmart, a system Philippine "
        . "sari-sari store owners and staff use to run their shop. The person "
        . "asking is signed in, and the tools you are given read their own "
        . "company's data and nobody else's.\n\n"

        . "Be genuinely useful, not merely accurate. When the figures support "
        . "it, say what they mean: which products are worth restocking first, "
        . "what changed since last week, what looks unusual. Answer the "
        . "question that was asked, then add the one observation a shopkeeper "
        . "would want, if there is one. Do not pad.\n\n"

        . "Call a tool whenever a question touches the shop's data, including "
        . "when you need two or three at once -- ask for them in the same turn "
        . "rather than one per round. Rows you were given earlier in this "
        . "conversation are still yours to use: a follow-up like \"ano-ano?\" "
        . "or \"why?\" should be answered from them, not refused.\n\n"

        . "Never invent a number. Every figure you state must come from a tool "
        . "result in this conversation; if no tool gives it to you, say plainly "
        . "that you could not retrieve it and, where you can, name the page "
        . "where the person will find it. Do not guess at causes either -- say "
        . "what the data shows and what it does not.\n\n"

        . "You have no tools for salaries or payroll, and you must not estimate "
        . "them. If asked, say plainly that pay is not something you can see, "
        . "and that Finance or HR handles it in the system.\n\n"

        . "You read; you never change anything. You cannot create a job "
        . "posting, approve a request, or edit a record. When the answer is "
        . "that something needs doing, say so and name the page where a person "
        . "does it.\n\n"

        . "English is the default: answer in English unless the user writes in "
        . "Tagalog or Taglish, in which case reply in the same language they "
        . "used. Keep answers short -- a shopkeeper is reading this between "
        . "customers. Today is " . date('Y-m-d') . ".";
}

function chatEnabled(mysqli $conn, array $ctx): bool
{
    $settings = chatbotAiSettings();

    return !empty($settings['chatbot_chat_enabled'])
        && !empty($settings['anthropic_api_key']);
}

/**
 * A conversational question costs more than Phase 1's one-shot routing call --
 * it carries the tool definitions, the history and the tool results -- so it
 * gets its own, smaller daily budget per company.
 */
function chatWithinDailyCap(mysqli $conn, array $ctx): bool
{
    $settings = chatbotAiSettings();
    $cap = (int) ($settings['chatbot_chat_daily_cap'] ?? CHAT_DAILY_CAP);

    if ($cap <= 0) {
        return false;
    }

    $stmt = $conn->prepare("
        SELECT COUNT(*) AS used
        FROM chatbot_messages
        WHERE company_id = ? AND matched_by = 'ai_chat' AND DATE(created_at) = CURDATE()
    ");
    $stmt->bind_param("i", $ctx['company_id']);
    $stmt->execute();
    $used = (int) $stmt->get_result()->fetch_assoc()['used'];
    $stmt->close();

    return $used < $cap;
}

/**
 * The API's reply reduced to the two things the loop cares about.
 */
function chatNormaliseReply(array $body): array
{
    $text = null;
    $toolCalls = [];

    foreach (($body['content'] ?? []) as $block) {

        if (($block['type'] ?? '') === 'text') {
            $text = trim((string) ($block['text'] ?? ''));
        }

        if (($block['type'] ?? '') === 'tool_use') {
            $toolCalls[] = [
                'id' => (string) ($block['id'] ?? ''),
                'name' => (string) ($block['name'] ?? ''),
                'input' => (array) ($block['input'] ?? []),
            ];
        }
    }

    return [
        'text' => $text,
        'tool_calls' => $toolCalls,
        /* Only ever set on a refusal, but read on every reply, so it is always
           present rather than sometimes missing. */
        'stop_reason' => (string) ($body['stop_reason'] ?? ''),
    ];
}

/**
 * The loop's internal transcript turned into the API's message shape.
 *
 * Three kinds of turn go in:
 *
 *   ['role' => 'user',      'content' => 'a question']
 *   ['role' => 'assistant', 'content' => ['text' => ?string, 'tool_calls' => [...]]]
 *   ['role' => 'user',      'content' => ['tool_results' => [...]]]
 *
 * and they come out as the content blocks the API documents. An earlier
 * version wrote them as prose -- "Called tool stock_list with {...}" -- which
 * the model had to infer its own tool use from. It answered worse for it.
 */
function chatApiMessages(array $messages): array
{
    $out = [];

    foreach ($messages as $message) {

        $role = $message['role'] === 'assistant' ? 'assistant' : 'user';
        $content = $message['content'];

        if (!is_array($content)) {
            $out[] = ['role' => $role, 'content' => (string) $content];
            continue;
        }

        if (isset($content['tool_results'])) {
            $out[] = ['role' => 'user', 'content' => chatToolResultBlocks($content['tool_results'])];
            continue;
        }

        $blocks = chatAssistantBlocks($content);

        /* A turn that carried neither text nor calls has nothing to say; sending
           an empty content array is a 400. */
        if ($blocks === []) {
            continue;
        }

        $out[] = ['role' => $role, 'content' => $blocks];
    }

    return $out;
}

/**
 * A model turn: its text, then each tool call as a tool_use block.
 */
function chatAssistantBlocks(array $content): array
{
    $blocks = [];
    $text = trim((string) ($content['text'] ?? ''));

    if ($text !== '') {
        $blocks[] = ['type' => 'text', 'text' => $text];
    }

    foreach (($content['tool_calls'] ?? []) as $call) {

        $id = (string) ($call['id'] ?? '');

        /* A call with no id cannot be answered by a tool_result, and a
           tool_use the model never gets a result for stalls the turn. */
        if ($id === '') {
            continue;
        }

        $input = (array) ($call['input'] ?? []);

        $blocks[] = [
            'type' => 'tool_use',
            'id' => $id,
            'name' => (string) ($call['name'] ?? ''),
            /* A string-keyed array already serialises as an object; an EMPTY
               one would serialise as [], which the API rejects where it wants
               an object. So only that case is cast. */
            'input' => $input === [] ? new stdClass() : $input,
        ];
    }

    return $blocks;
}

/**
 * Every result of one round, in one message.
 *
 * Splitting them across several user messages is what teaches a model to stop
 * asking for more than one tool at a time -- which is the difference between
 * "what sold today and what is low?" taking one round or three.
 */
function chatToolResultBlocks(array $results): array
{
    $blocks = [];

    foreach ($results as $result) {

        $block = [
            'type' => 'tool_result',
            'tool_use_id' => (string) ($result['id'] ?? ''),
            'content' => (string) ($result['content'] ?? ''),
        ];

        /* A failed tool is reported as failed, never dropped: a call with no
           result leaves the model waiting for one. */
        if (!empty($result['is_error'])) {
            $block['is_error'] = true;
        }

        $blocks[] = $block;
    }

    return $blocks;
}

/**
 * What a given model accepts.
 *
 * The model is a knob in the secrets file, so one day somebody will point it
 * at something cheaper -- and not every model takes the same request. Effort
 * is rejected outright by Haiku 4.5. Server-side fallback is served for only a
 * few models. Sending either to a model that does not take it is a 400, and a
 * 400 here is invisible: the question just quietly falls back to the keyword
 * answer, exactly as it did when the model id itself was wrong.
 *
 * Unknown models get the plain request. Being wrong that way costs a little
 * quality; being wrong the other way costs the entire answer.
 */
function chatModelSupports(string $model, string $feature): bool
{
    $supports = [
        /* Takes output_config.effort. */
        'effort' => [
            'claude-opus-5-5', 'claude-opus-5', 'claude-sonnet-5-5',
            'claude-sonnet-5', 'claude-fable-5-1', 'claude-fable-5',
        ],
        /* Served the "default" form of server-side fallback. */
        'fallbacks' => [
            'claude-opus-5-5', 'claude-opus-5', 'claude-sonnet-5-5',
            'claude-fable-5-1',
        ],
    ];

    return in_array($model, $supports[$feature] ?? [], true);
}

/**
 * The smallest prompt prefix this model will cache.
 *
 * Below it nothing caches, and nothing says so -- the request succeeds and the
 * bill simply stays the same. Our fixed prefix (system prompt plus tool
 * schemas) is roughly 2,100 tokens for an owner, which is above Sonnet 5.5's
 * minimum and well below Haiku 4.5's. That single fact decides whether a
 * cheaper model is actually cheaper.
 */
function chatCacheMinimumTokens(string $model): int
{
    $minimums = [
        'claude-opus-5-5' => 512,
        'claude-opus-5' => 512,
        'claude-sonnet-5-5' => 512,
        'claude-fable-5-1' => 512,
        'claude-sonnet-5' => 1024,
        'claude-opus-4-8' => 1024,
        'claude-haiku-4-5' => 4096,
    ];

    /* An unknown model is assumed to be the strictest we know of, so nobody
       plans a saving on a cache that never happens. */
    return $minimums[$model] ?? 4096;
}

/**
 * The betas this request needs, for this model.
 */
function chatRequestBetas(?string $model = null): array
{
    $model = $model ?? chatConfiguredModel();

    return chatModelSupports($model, 'fallbacks') ? [CHAT_FALLBACK_BETA] : [];
}

/**
 * The model this installation is configured to use.
 */
function chatConfiguredModel(): string
{
    $settings = chatbotAiSettings();

    return (string) ($settings['chatbot_chat_model'] ?? CHAT_DEFAULT_MODEL);
}

/**
 * The request body, built where a test can read it.
 *
 * It used to be assembled inside the curl closure, where the only way to check
 * the model id was to make a real call -- so a wrong one went unnoticed.
 */
function chatRequestPayload(array $messages, array $toolSchemas): array
{
    $settings = chatbotAiSettings();
    $model = chatConfiguredModel();

    $payload = [
        'model' => $model,
        'max_tokens' => CHAT_MAX_TOKENS,
        'system' => chatSystemPrompt(),
        'tools' => $toolSchemas,
        'messages' => chatApiMessages($messages),
    ];

    if (chatModelSupports($model, 'effort')) {
        $payload['output_config'] = ['effort' => CHAT_EFFORT];
    }

    /* Only the Claude API serves this; a self-hosted or proxied endpoint would
       reject the field, as would a model it is not offered for. */
    if (chatModelSupports($model, 'fallbacks')
        && ($settings['chatbot_chat_endpoint'] ?? CHAT_ENDPOINT) === CHAT_ENDPOINT) {
        $payload['fallbacks'] = 'default';
    }

    return $payload;
}

/**
 * The callable chatConverse() drives. It throws on any failure, which the loop
 * turns into the Phase 1 fallback.
 */
function chatModelCallable(): callable
{
    return function (array $messages, array $toolSchemas): array {

        $settings = chatbotAiSettings();

        $payload = json_encode(chatRequestPayload($messages, $toolSchemas));

        $headers = [
            'Content-Type: application/json',
            'x-api-key: ' . $settings['anthropic_api_key'],
            'anthropic-version: 2023-06-01',
        ];

        /* An empty anthropic-beta header is not the same as no header, so it
           is only added when there is a beta to name. */
        $betas = chatRequestBetas();

        if ($betas !== []) {
            $headers[] = 'anthropic-beta: ' . implode(',', $betas);
        }

        $curl = curl_init($settings['chatbot_chat_endpoint'] ?? CHAT_ENDPOINT);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT_MS => CHAT_MODEL_TIMEOUT_MS,
            CURLOPT_CONNECTTIMEOUT_MS => 2000,
            CURLOPT_HTTPHEADER => $headers,
        ]);

        $response = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($response === false || $status !== 200) {
            throw new RuntimeException('chat api status ' . $status);
        }

        $body = json_decode((string) $response, true);
        $reply = chatNormaliseReply(is_array($body) ? $body : []);

        /*
        | A refusal arrives as a perfectly good HTTP 200 with no answer in it.
        | Throwing here is what sends the question to the keyword answer rather
        | than showing the user an empty card.
        */
        if ($reply['stop_reason'] === 'refusal') {
            throw new RuntimeException('chat api refused');
        }

        return $reply;
    };
}
