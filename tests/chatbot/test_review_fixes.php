<?php
/*
| The findings from the whole-branch review, each pinned by a test.
*/
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/factory.php';
require_once __DIR__ . '/../../includes/chatbot/engine.php';
require_once __DIR__ . '/../../includes/chatbot/log.php';

register_shutdown_function(function () use ($conn) { testCleanup($conn); });

$companyId = testMakeCompany($conn, 'Review Co', 2);

$conn->query("INSERT INTO users (company_id, username, fullname, email, password, role, status)
              VALUES ({$companyId}, 'rev1', 'Rev One', 'rev@test.local', 'x', 'cashier', 'active')");
$userId = (int) $conn->insert_id;

$ctx = ['company_id' => $companyId, 'user_id' => $userId,
        'employee_id' => null, 'role' => 'cashier'];

/*
| Finding 3: the browser must not learn WHICH gate refused. Spec section 4:
| a role refusal and a plan refusal look the same to the user; only the log
| keeps them apart.
*/
t_same('denied', chatbotPublicReason('denied_role'), 'a role refusal reads as "denied"');
t_same('denied', chatbotPublicReason('denied_plan'), 'a plan refusal reads the same');
t_same('denied', chatbotPublicReason('needs_employee'), 'so does a missing employee record');
t_same('no_match', chatbotPublicReason('no_match'), 'not understood stays its own answer');
t_same('rate_limited', chatbotPublicReason('rate_limited'), 'rate limiting is unchanged');

/*
| Finding 7: a subscription that lapsed after sign-in. The session flag is
| written once at login, so the endpoint must ask the database.
*/
t_ok(chatbotSubscriptionActive($conn, $companyId), 'an active subscription passes');

/*
| A company whose subscription is no longer active, with a session that still
| says it is -- which is exactly what a session carries after the plan lapses,
| because the flag is written once at sign-in.
|
| Built as its own company rather than by expiring the one above:
| currentCompanyPlan() caches per company for the life of the request, which is
| correct in production (one check per request) but would hide the change here.
*/
$lapsed = testMakeCompany($conn, 'Review Lapsed Co', 2);
$conn->query("UPDATE company_subscriptions SET status = 'Expired' WHERE company_id = {$lapsed}");

$staleSession = ['user_id' => 1, 'role' => 'admin',
                 'company_id' => $lapsed, 'subscription_active' => 1];

t_same(null, chatbotSessionProblem($staleSession),
    'the session alone still believes the subscription is active');
t_ok(!chatbotSubscriptionActive($conn, $lapsed),
    'but the database says it lapsed, and that is what the endpoint asks');

/*
| Finding 8: a 5,000-character question was echoed back whole inside the
| answer. The endpoint bounds it before answering, not only before logging.
*/
$monster = str_repeat('A', 5000);
t_same(CHATBOT_QUESTION_MAX, mb_strlen(chatbotBoundQuestion($monster)),
    'a huge question is cut to the stored length before it is answered');
t_same('magkano ang lucky me', chatbotBoundQuestion('  magkano ang lucky me  '),
    'an ordinary question is untouched apart from trimming');

$answer = chatbotAnswer($conn, $ctx, chatbotBoundQuestion('magkano ang ' . $monster))['answer'];
t_ok(mb_strlen($answer['note'] ?? '') < 700, 'and the answer cannot echo megabytes back');

/*
| Finding 6: with a key installed, every question is a paid call. Spec
| section 3.5 promises a per-company daily cap so cost cannot run away.
*/
$secrets = sys_get_temp_dir() . '/chatbot_cap_secrets.php';
file_put_contents($secrets, "<?php\nreturn " . var_export([
    'anthropic_api_key' => 'sk-ant-not-a-real-key',
    'chatbot_ai_enabled' => true,
    'chatbot_ai_daily_cap' => 3,
    'chatbot_ai_endpoint' => 'https://api.invalid.test/v1/messages',
], true) . ";\n");

$GLOBALS['chatbot_secrets_path'] = $secrets;
register_shutdown_function(function () use ($secrets) { @unlink($secrets); });

t_ok(chatbotAiWithinDailyCap($conn, $ctx), 'under the cap, the AI may be consulted');

for ($i = 0; $i < 3; $i++) {
    chatbotLog($conn, $ctx, "capped {$i}", null, 'keyword', 'no_match');
}

t_ok(!chatbotAiWithinDailyCap($conn, $ctx),
    'past the cap, the AI is not consulted and keywords answer instead');

/* The cap counts one company's questions, not the whole system's. */
$other = testMakeCompany($conn, 'Review Other Co', 2);
$otherCtx = ['company_id' => $other, 'user_id' => 1, 'employee_id' => null, 'role' => 'admin'];
t_ok(chatbotAiWithinDailyCap($conn, $otherCtx), 'another company has its own budget');

/* Finding minor: the timeout the spec states. */
t_same(2500, CHATBOT_AI_TIMEOUT_MS, 'the API call gives up at 2.5 seconds, as specified');

t_done();
