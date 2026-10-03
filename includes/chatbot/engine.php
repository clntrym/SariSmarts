<?php
/*
|--------------------------------------------------------------------------
| THE ENGINE
|--------------------------------------------------------------------------
|
| The only place that decides whether a question may be answered. Handlers
| assume they are already authorised; this file is why they can.
|
| Nothing here reads $_SESSION. The caller (ask.php) passes an explicit
| context array, which is what lets the whole engine be driven from the CLI
| by the test suite:
|
|     ['company_id' => int, 'user_id' => int, 'employee_id' => ?int, 'role' => string]
*/

require_once __DIR__ . '/intents.php';
require_once __DIR__ . '/understand.php';
require_once __DIR__ . '/ai_client.php';

/**
 * Whether this company's plan opens a topic.
 *
 * Deny by default: a topic with no row grants nothing. companyHasModule() in
 * init.php is deliberately permissive for navigation, where a page no plan
 * sells should stay open. The opposite is right here -- a topic missing from
 * the table is a mistake, and a mistake must close a door, not open one.
 */
function chatbotPlanAllowsTopic(mysqli $conn, int $companyId, string $topic): bool
{
    $topic = strtolower(trim($topic));

    if ($topic === '') {
        return false;
    }

    $plan = currentCompanyPlan($conn, $companyId);

    if (!$plan) {
        return false;
    }

    $planId = (int) $plan['plan_id'];

    $stmt = $conn->prepare("
        SELECT 1 FROM chatbot_topic_plans WHERE topic = ? AND plan_id = ? LIMIT 1
    ");
    $stmt->bind_param("si", $topic, $planId);
    $stmt->execute();
    $allowed = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    return $allowed;
}

/**
 * The catalog reduced to what this role, on this plan, may ask.
 *
 * Everything downstream -- matching, the AI's candidate list, the suggestion
 * buttons -- works from this reduced list, so no later step can reach an
 * intent the user was never allowed to ask.
 */
function chatbotAllowedIntents(mysqli $conn, int $companyId, string $role): array
{
    $role = strtolower(trim($role));
    $allowed = [];

    foreach (chatbotIntents() as $id => $intent) {

        if (!in_array($role, $intent['roles'], true)) {
            continue;
        }

        if (!chatbotPlanAllowsTopic($conn, $companyId, $intent['topic'])) {
            continue;
        }

        $allowed[$id] = $intent;
    }

    return $allowed;
}

/**
 * The buttons shown under the conversation.
 *
 * Drawn from the already-filtered list, so the assistant can never offer a
 * question it would then refuse.
 */
function chatbotSuggestions(array $allowedIntents, int $limit = 6): array
{
    $suggestions = [];

    foreach ($allowedIntents as $id => $intent) {

        if (count($suggestions) >= $limit) {
            break;
        }

        /* Hidden intents answer a question; they are not offered as one. */
        if (!empty($intent['hidden'])) {
            continue;
        }

        $suggestions[] = [
            'id' => $id,
            'label' => $intent['label'],
            /* A chip the user must complete (with a product name) fills the
               box instead of being sent as it stands. */
            'needs_input' => !empty($intent['needs_input']),
        ];
    }

    return $suggestions;
}

/**
 * The refusal name the browser is allowed to see.
 *
 * A role refusal and a plan refusal must look identical to the user: telling
 * them apart lets someone enumerate what a higher plan would unlock, and
 * confirms that data they may not see exists. The distinction is kept in
 * chatbot_messages, where it belongs.
 */
function chatbotPublicReason(?string $reason): string
{
    return match ($reason) {
        'denied_role', 'denied_plan', 'needs_employee' => 'denied',
        'rate_limited' => 'rate_limited',
        'auth' => 'auth',
        default => 'no_match',
    };
}

/**
 * Whether the company's subscription is active right now.
 *
 * $_SESSION['subscription_active'] is written once at sign-in, so a
 * subscription that lapses during a session would otherwise go unnoticed
 * until the next login. currentCompanyPlan() only returns a plan for an
 * Active or Trial subscription, and caches per company, so this costs one
 * query per request at most.
 */
function chatbotSubscriptionActive(mysqli $conn, int $companyId): bool
{
    return currentCompanyPlan($conn, $companyId) !== null;
}

/**
 * Why this session may not use the assistant, or null when it may.
 *
 * The rule mirrors requireRole(): an approved company that has not settled its
 * subscription cannot use the system, and that includes the chatbot. It lives
 * here as a function -- rather than inline in ask.php -- because a rule that
 * cannot be tested is a rule nobody can prove.
 *
 * @return string|null 'signed_out', 'no_subscription', or null
 */
function chatbotSessionProblem(array $session): ?string
{
    if (empty($session['user_id']) || empty($session['role'])) {
        return 'signed_out';
    }

    /* Super Admin belongs to no tenant and has nothing to ask about here. */
    if (empty($session['company_id'])) {
        return 'signed_out';
    }

    if (empty($session['subscription_active'])) {
        return 'no_subscription';
    }

    return null;
}

/**
 * The page a role should be sent to, or null when that role has no such page.
 *
 * Every role folder holds its own pages, and they are not the same: Finance
 * approves payroll on payroll_approval.php while HR prepares it on
 * payroll.php, and neither admin/ nor finance/ has a file called payroll.php
 * at all. The widget uses the href verbatim, relative to the current page, so
 * a hard-coded name is a 404 for somebody.
 */
function chatbotPageLink(array $ctx, string $page): ?array
{
    $pages = [
        'payroll' => ['hr' => ['payroll.php', 'Open Payroll'],
                      'finance' => ['payroll_approval.php', 'Open Payroll Approval']],
        'expenses' => ['finance' => ['expenses.php', 'Open Expenses']],
        'payables' => ['finance' => ['accounts_payable.php', 'Open Accounts Payable']],
        'stock_requests' => ['admin' => ['stock_requests.php', 'Open Stock Requests'],
                             'finance' => ['stock_requests.php', 'Open Stock Requests'],
                             'inventory' => ['stock_requests.php', 'Open Stock Requests']],
        'inventory' => ['admin' => ['Inventory.php', 'Open Inventory'],
                        'inventory' => ['Inventory.php', 'Open Inventory']],
        'suppliers' => ['admin' => ['suppliers.php', 'Open Suppliers'],
                        'inventory' => ['suppliers.php', 'Open Suppliers']],
        'employees' => ['hr' => ['employee_directory.php', 'Open Employee Directory']],
        'leave_approval' => ['hr' => ['approval.php', 'Open Leave Approval'],
                             'admin' => ['approval.php', 'Open Approvals']],
        'attendance' => ['hr' => ['attendance.php', 'Open Attendance']],
        'applications' => ['hr' => ['applications.php', 'Open Applications']],
        'recruitment' => ['hr' => ['recruitment.php', 'Open Recruitment']],
        'income' => ['admin' => ['income.php', 'Open Income'],
                     'finance' => ['income.php', 'Open Income']],
        'users' => ['admin' => ['user_management.php', 'Open User Management']],
    ];

    $role = strtolower(trim((string) $ctx['role']));
    $entry = $pages[$page][$role] ?? null;

    return $entry === null ? null : ['href' => $entry[0], 'label' => $entry[1]];
}

/**
 * Whether this question may be sent to the model at all.
 *
 * A local_only match is answered here and never leaves the server. Extracted
 * so the rule can be tested: it used to live only as a condition inside
 * ask.php, where nothing exercised it.
 */
function chatbotShouldConsultModel(mysqli $conn, array $ctx, string $question): bool
{
    return chatbotLocalOnlyMatch($conn, $ctx, $question) === null;
}

/**
 * The id of a local_only intent this question matches, or null.
 *
 * Payroll answers are queried and rendered on this server and never sent to a
 * model -- that is how Finance keeps them while the tool catalog has no payroll
 * tool. Without this check the conversational layer would answer first, find no
 * payroll tool, and refuse a question this system answers perfectly well.
 */
function chatbotLocalOnlyMatch(mysqli $conn, array $ctx, string $question): ?string
{
    $allowed = chatbotAllowedIntents($conn, (int) $ctx['company_id'], (string) $ctx['role']);

    $local = array_filter($allowed, static fn (array $intent): bool => !empty($intent['local_only']));

    if ($local === []) {
        return null;
    }

    return chatbotKeywordMatch($question, $local);
}

require_once __DIR__ . '/answers/pos.php';
require_once __DIR__ . '/answers/inventory.php';
require_once __DIR__ . '/answers/staff.php';
require_once __DIR__ . '/answers/personal.php';
require_once __DIR__ . '/answers/hr.php';
require_once __DIR__ . '/answers/finance.php';
require_once __DIR__ . '/answers/inventory_staff.php';
require_once __DIR__ . '/advisories.php';
require_once __DIR__ . '/advice/hr.php';
require_once __DIR__ . '/advice/admin.php';
require_once __DIR__ . '/advice/operations.php';
require_once __DIR__ . '/advice/personal.php';

/**
 * Answer one question, or explain why it cannot be answered.
 *
 * Refusals never say which gate stopped them and never confirm that the data
 * exists; the distinction is for the log, not for the user.
 */
function chatbotAnswer(mysqli $conn, array $ctx, string $question): array
{
    $allowed = chatbotAllowedIntents($conn, (int) $ctx['company_id'], (string) $ctx['role']);
    $suggestions = chatbotSuggestions($allowed);

    $catalog = chatbotIntents();
    $mine = chatbotKeywordBest($question, $allowed);
    $anyone = chatbotKeywordBest($question, $catalog);
    $matchedBy = 'keyword';

    /*
    | "Benta ko ngayong araw" is the cashier asking about themselves, even
    | though it shares every other word with the company sales question they
    | may not ask. When the question says ko / ako / my / I and the user has a
    | matching question of their own, that is the one they mean.
    */
    $ownScope = array_filter(
        $allowed,
        static fn (array $intent): bool => $intent['scope'] === 'own'
    );

    $ownBest = $ownScope === [] ? null : chatbotKeywordBest($question, $ownScope);
    $aboutThemselves = $ownBest !== null && chatbotMentionsSelf($question);

    if ($aboutThemselves) {
        $mine = $ownBest;
    }

    /*
    | Refuse when the question plainly means something this user may not ask,
    | even if a weaker intent of theirs happens to share a word. A cashier
    | asking "magkano ang benta ngayong araw" -- no "ko" -- means company
    | sales; answering their product-price intent on the strength of "magkano"
    | would turn a refusal into a confusing product search.
    */
    /*
    | The pay refusal is itself a refusal, and a clearer one than the generic
    | "I cannot answer that": it names the subject and points at the Payroll
    | pages. A cashier asking about payroll should hear it, not be escalated to
    | the generic denial just because Finance has a stronger-matching intent.
    */
    $isPayRefusal = $mine !== null && $mine['id'] === 'salary_not_available';

    /*
    | A pay question about one person is the refusal's own subject, even when a
    | payroll intent matches more words. "magkano ang sweldo ni Ana" is not a
    | request for the month's payroll run.
    */
    if (!$isPayRefusal
        && isset($allowed['salary_not_available'])
        && chatbotMentionsIndividual($question)
        && chatbotKeywordMatch($question, ['salary_not_available' => $catalog['salary_not_available']]) !== null) {

        $mine = ['id' => 'salary_not_available', 'score' => 0, 'groups' => 0];
        $isPayRefusal = true;
    }

    $refuse = !$aboutThemselves
        && !$isPayRefusal
        && $anyone !== null
        && !isset($allowed[$anyone['id']])
        && ($mine === null || $anyone['score'] > $mine['score']);

    if ($refuse) {
        $intent = $catalog[$anyone['id']];

        return [
            'ok' => false,
            'reason' => in_array((string) $ctx['role'], $intent['roles'], true)
                ? 'denied_plan'
                : 'denied_role',
            'intent' => null,
            'matched_by' => null,
            'answer' => null,
            'suggestions' => $suggestions,
        ];
    }

    /*
    | Only now the AI, and only among the intents this user may ask. It runs
    | after the refusal above so that a question plainly meaning something
    | forbidden is still refused rather than bent into a permitted intent.
    |
    | With no key, no internet or a slow reply, the keyword result stands.
    */
    /* A local_only match is decided here: the model is not consulted for it,
       which is what the flag promises. ask.php also routes around the chat
       layer; this is the half that protects chatbotAnswer itself. */
    $isLocalOnly = !empty($allowed[$mine['id'] ?? '']['local_only']);

    $understood = chatbotUnderstand(
        $question,
        $allowed,
        (!$isLocalOnly && chatbotAiWithinDailyCap($conn, $ctx)) ? 'chatbotAiIntent' : null
    );

    if ($understood['matched_by'] === 'ai' && $understood['intent'] !== null) {
        $mine = ['id' => $understood['intent'], 'score' => 0, 'groups' => 0];
        $matchedBy = 'ai';
    }

    if ($mine === null) {
        return [
            'ok' => false,
            'reason' => 'no_match',
            'intent' => null,
            'matched_by' => null,
            'answer' => null,
            'suggestions' => $suggestions,
        ];
    }

    $intentId = $mine['id'];
    $intent = $allowed[$intentId];

    /*
    | A question about an employee record needs an employee. An owner account
    | has none -- it is a user without an employees row -- so binding a null
    | employee_id would quietly report "no attendance today" to someone whose
    | real answer is "you have no employee record".
    */
    $needsEmployee = ['my_attendance_today', 'my_leave_status'];

    if (in_array($intentId, $needsEmployee, true) && empty($ctx['employee_id'])) {
        return [
            'ok' => false,
            'reason' => 'needs_employee',
            'intent' => $intentId,
            'matched_by' => $matchedBy,
            'answer' => null,
            'suggestions' => $suggestions,
        ];
    }

    if ($intent['scope'] === 'own' && empty($ctx['user_id'])) {
        return [
            'ok' => false,
            'reason' => 'needs_employee',
            'intent' => $intentId,
            'matched_by' => $matchedBy,
            'answer' => null,
            'suggestions' => $suggestions,
        ];
    }

    $answer = ($intent['handler'])($conn, $ctx, $question);

    return [
        'ok' => true,
        'reason' => null,
        'intent' => $intentId,
        'matched_by' => $matchedBy,
        'answer' => $answer,
        'suggestions' => $suggestions,
    ];
}
