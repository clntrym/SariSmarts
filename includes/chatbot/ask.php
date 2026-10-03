<?php
/*
|--------------------------------------------------------------------------
| THE ONE DOOR
|--------------------------------------------------------------------------
|
| The only place the chatbot reads $_SESSION, and the only place it writes.
|
| It does not call requireRole() or requireCompany(): those end a request with
| a redirect or a plain-text 403, which an AJAX caller cannot read. The same
| checks are made here and answered in JSON.
|
| The only thing taken from the browser is the question text. company_id comes
| from the session and nowhere else, so no request can ask about another
| company by changing what it sends.
*/

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/engine.php';
require_once __DIR__ . '/log.php';
require_once __DIR__ . '/chat/conversation.php';
require_once __DIR__ . '/chat/api.php';
require_once __DIR__ . '/chat/history.php';

header('Content-Type: application/json; charset=utf-8');

function chatbotFail(string $reason, string $message, array $suggestions = []): void
{
    echo json_encode([
        'ok' => false,
        'reason' => $reason,
        'message' => $message,
        'suggestions' => $suggestions,
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    chatbotFail('method', 'Use POST.');
}

$problem = chatbotSessionProblem($_SESSION);

if ($problem === 'signed_out') {
    http_response_code(401);
    chatbotFail('auth', 'Please sign in again to continue.');
}

if ($problem === 'no_subscription') {
    http_response_code(403);
    chatbotFail('auth', 'Your business subscription is not active.');
}

/* And again from the database: a subscription that lapsed after sign-in is
   still 'active' in the session until the next login. */
if (!chatbotSubscriptionActive($conn, (int) $_SESSION['company_id'])) {
    http_response_code(403);
    chatbotFail('auth', 'Your business subscription is not active.');
}

if (empty($_SESSION['chatbot_csrf'])) {
    $_SESSION['chatbot_csrf'] = bin2hex(random_bytes(16));
}

$payload = json_decode(file_get_contents('php://input') ?: '[]', true);
$payload = is_array($payload) ? $payload : [];

if (!hash_equals($_SESSION['chatbot_csrf'], (string) ($payload['csrf_token'] ?? ''))) {
    http_response_code(403);
    chatbotFail('auth', 'Your session expired. Please refresh the page.');
}

$ctx = [
    'company_id' => (int) $_SESSION['company_id'],
    'user_id' => (int) $_SESSION['user_id'],
    'employee_id' => isset($_SESSION['employee_id']) ? (int) $_SESSION['employee_id'] : null,
    'role' => strtolower((string) $_SESSION['role']),
];

$question = chatbotBoundQuestion((string) ($payload['question'] ?? ''));

/* An empty question is how the widget asks for its suggestion buttons. */
if ($question === '') {
    chatbotFail(
        'no_match',
        'What would you like to ask?',
        chatbotSuggestions(chatbotAllowedIntents($conn, $ctx['company_id'], $ctx['role']))
    );
}

if (chatbotRateLimited($conn, $ctx)) {
    http_response_code(429);
    chatbotFail('rate_limited', 'That is a lot of questions in one minute. Please try again shortly.');
}

/*
| The conversational layer answers when it can. Everything that can go wrong
| with it -- switched off, no key, over budget, unreachable, an invented
| figure -- falls through to the Phase 1 answer below, which needs no network.
*/
if (chatEnabled($conn, $ctx) && chatWithinDailyCap($conn, $ctx)
    && chatbotShouldConsultModel($conn, $ctx, $question)) {

    try {
        $chat = chatConverse(
            $conn,
            $ctx,
            $question,
            chatHistoryGet($_SESSION, $ctx),
            chatModelCallable()
        );
    } catch (Throwable $error) {
        error_log('chat: ' . $error->getMessage());
        $chat = ['ok' => false];
    }

    if (!chatResultUsable($chat)) {
        /* The attempt already spent API calls, so it counts against the budget
           even though the Phase 1 answer below is what the user will see. */
        chatbotLogChatAttempt($conn, $ctx, $question);
    }

    if (chatResultUsable($chat)) {

        /* The rows go into the history with the answer, so the next question
           can be "ano-ano?" and still be grounded. */
        chatHistoryAppend($_SESSION, $question, (string) $chat['text'], $ctx,
            $chat['tables'] ?? []);
        chatbotLogChat($conn, $ctx, $question, $chat['tools_used']);

        echo json_encode([
            'ok' => true,
            'intent' => 'chat',
            'answer' => chatAnswerPayload($chat),
            'suggestions' => chatbotSuggestions(
                chatbotAllowedIntents($conn, $ctx['company_id'], $ctx['role'])
            ),
        ]);
        exit;
    }
}

try {
    $result = chatbotAnswer($conn, $ctx, $question);
} catch (Throwable $error) {
    /* Detail to the log, never to the browser: an SQL error message names
       tables and columns. */
    error_log('chatbot: ' . $error->getMessage());
    http_response_code(500);
    chatbotFail('error', 'Something went wrong fetching the answer. Please try again.');
}

/* chatbot_messages.outcome is an ENUM of four values. needs_employee is a
   refusal, not one of them, so it is logged as no_match. */
$outcome = $result['ok']
    ? 'answered'
    : (in_array($result['reason'], ['denied_role', 'denied_plan'], true)
        ? $result['reason']
        : 'no_match');

chatbotLog($conn, $ctx, $question, $result['intent'], $result['matched_by'], $outcome);

if (!$result['ok']) {
    echo json_encode([
        'ok' => false,
        /* The user is never told which gate refused -- see chatbotPublicReason. */
        'reason' => chatbotPublicReason($result['reason']),
        'message' => $result['reason'] === 'needs_employee'
            ? 'This is tied to an employee record, and your account has none.'
            : 'I cannot answer that. Here is what I can answer:',
        'suggestions' => $result['suggestions'],
    ]);
    exit;
}

echo json_encode([
    'ok' => true,
    'intent' => $result['intent'],
    'answer' => $result['answer'],
    'suggestions' => $result['suggestions'],
]);
