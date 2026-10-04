<?php
/*
|--------------------------------------------------------------------------
| THE PLATFORM ASSISTANT'S ONE DOOR
|--------------------------------------------------------------------------
|
| The only place the Super Admin chat reads $_SESSION, and the only endpoint
| it answers on.
|
| It does not call requirePlatformAccess(): that ends a request with a
| redirect, which an AJAX caller cannot read. The same check is made here and
| answered in JSON.
|
| The role comes from the session and from nowhere else, so no request can
| reach the platform catalogue by claiming to be an operator. The catalogue
| itself refuses a tenant role, and the runner refuses it again -- the
| catalogue decides what a role is offered, a request decides what it asks
| for, and nothing stops it asking for something it was never shown.
*/

require_once __DIR__ . '/init.php';
require_once __DIR__ . '/includes/platform_roles.php';
require_once __DIR__ . '/includes/admin_chat_tools.php';

/* The loop, the providers and the translator, shared with the tenant
   assistant so there is one of each to keep working. */
$shared = is_dir(__DIR__ . '/../includes/chatbot')
    ? __DIR__ . '/../includes/chatbot'
    : __DIR__ . '/../SariSmarts/includes/chatbot';

require_once $shared . '/chat/conversation.php';
require_once $shared . '/chat/api.php';

header('Content-Type: application/json; charset=utf-8');

function adminChatFail(string $reason, string $message): void
{
    echo json_encode(['ok' => false, 'reason' => $reason, 'message' => $message]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    adminChatFail('method', 'Use POST.');
}

if (empty($_SESSION['user_id']) || empty($_SESSION['role'])) {
    http_response_code(401);
    adminChatFail('auth', 'Please sign in again.');
}

$role = strtolower(trim((string) $_SESSION['role']));

/* A tenant signing in and posting here gets nothing, by name. */
if (!array_key_exists($role, platformRoles())) {
    http_response_code(403);
    adminChatFail('auth', 'This assistant is for platform staff.');
}

if (empty($_SESSION['admin_chat_csrf'])) {
    $_SESSION['admin_chat_csrf'] = bin2hex(random_bytes(16));
}

$payload = json_decode(file_get_contents('php://input') ?: '[]', true);
$payload = is_array($payload) ? $payload : [];

if (!hash_equals($_SESSION['admin_chat_csrf'], (string) ($payload['csrf_token'] ?? ''))) {
    http_response_code(403);
    adminChatFail('auth', 'Your session expired. Please refresh the page.');
}

$question = trim((string) ($payload['question'] ?? ''));
$question = mb_substr($question, 0, 500);

if ($question === '') {
    adminChatFail('empty', 'What would you like to know?');
}

/*
| A simple per-session rate limit. The operator is one person, not a crowd,
| and a page left open with a loop in it should not spend the month's budget.
*/
$now = time();
$recent = array_filter((array) ($_SESSION['admin_chat_times'] ?? []),
    static fn($t): bool => $t > $now - 60);

if (count($recent) >= 15) {
    http_response_code(429);
    adminChatFail('rate_limited', 'That is a lot of questions in one minute. Please wait a moment.');
}

$recent[] = $now;
$_SESSION['admin_chat_times'] = array_values($recent);

$ctx = [
    'company_id' => 0,
    'user_id' => (int) $_SESSION['user_id'],
    'employee_id' => null,
    'role' => $role,
];

if (!chatEnabled($conn, $ctx)) {
    adminChatFail('offline',
        'The assistant is not configured yet. An API key is needed before it can answer.');
}

/* The catalogue this role may use, in the schema shape the providers want. */
$tools = adminChatToolsFor($role);
$schemas = [];

foreach ($tools as $name => $tool) {

    $properties = [];
    $required = [];

    foreach ($tool['input'] as $param => $spec) {

        $properties[$param] = match ($spec['type']) {
            'enum' => ['type' => 'string', 'enum' => $spec['values']],
            'int' => ['type' => 'integer'],
            default => ['type' => 'string'],
        };

        if (!empty($spec['required'])) {
            $required[] = $param;
        }
    }

    $schemas[] = [
        'name' => $name,
        'description' => $tool['description'],
        'input_schema' => [
            'type' => 'object',
            /* An empty properties array serialises as [] and is rejected
               where an object is wanted -- the same trap the tenant tools
               hit. */
            'properties' => $properties === [] ? new stdClass() : $properties,
            'required' => $required,
        ],
    ];
}

/*
| The conversation so far, and only if it belongs to this operator.
|
| Signing out does not destroy the session -- acc_log_out.php unsets a few
| keys and leaves the rest -- so without this check the next person to sign
| in on the same terminal would inherit the previous one's conversation, and
| with it figures about companies their own role may not read. The tenant
| assistant had exactly this fault; it is not worth having twice.
*/
$history = (int) ($_SESSION['admin_chat_user'] ?? 0) === (int) $_SESSION['user_id']
    ? (array) ($_SESSION['admin_chat_history'] ?? [])
    : [];

try {
    $chat = chatConverse(
        $conn,
        $ctx,
        $question,
        $history,
        chatModelCallable(),
        $schemas,
        static fn(string $name, array $input): array
            => adminChatRunTool($conn, $role, $name, $input)
    );
} catch (Throwable $error) {
    error_log('admin chat: ' . $error->getMessage());
    $chat = ['ok' => false];
}

if (!chatResultUsable($chat)) {
    adminChatFail('unavailable',
        'I could not reach an answer just now. Please try again in a moment.');
}

/* Six turns, the same cap the tenant assistant keeps, stamped with the user
   so a shared terminal cannot hand one operator another's conversation. */
$history[] = ['role' => 'user', 'text' => $question];
$history[] = ['role' => 'assistant', 'text' => (string) $chat['text'],
              'tables' => $chat['tables'] ?? []];

$_SESSION['admin_chat_history'] = array_slice($history, -12);
$_SESSION['admin_chat_user'] = (int) $_SESSION['user_id'];

echo json_encode([
    'ok' => true,
    'answer' => trim((string) $chat['text']),
    'tools_used' => $chat['tools_used'] ?? [],
]);
