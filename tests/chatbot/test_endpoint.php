<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/factory.php';
require_once __DIR__ . '/../../includes/chatbot/engine.php';
require_once __DIR__ . '/../../includes/chatbot/log.php';

register_shutdown_function(function () use ($conn) { testCleanup($conn); });

$companyId = testMakeCompany($conn, 'Endpoint Co', 1);

$conn->query("INSERT INTO users (company_id, username, fullname, email, password, role, status)
              VALUES ({$companyId}, 'admin1', 'Admin One', 'a1@test.local', 'x', 'admin', 'active')");
$userId = (int) $conn->insert_id;

$ctx = ['company_id' => $companyId, 'user_id' => $userId,
        'employee_id' => null, 'role' => 'admin'];

/* Logging */
chatbotLog($conn, $ctx, 'magkano ang benta', 'sales_today', 'keyword', 'answered');

$stmt = $conn->prepare("SELECT question, intent_id, matched_by, outcome
                        FROM chatbot_messages WHERE company_id = ?");
$stmt->bind_param("i", $companyId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

t_same('sales_today', $row['intent_id'], 'the log records the intent');
t_same('answered', $row['outcome'], 'the log records the outcome');
t_same('keyword', $row['matched_by'], 'the log records which path matched');

/* Review Focus 3: a hostile, oversized question */
$monster = str_repeat('A', 5000) . " <script>alert(1)</script> '; DROP TABLE sales; -- 🙂";
$result = chatbotAnswer($conn, $ctx, $monster);
t_ok(is_array($result), 'an oversized hostile question returns a result, not a crash');

chatbotLog($conn, $ctx, $monster, null, null, 'no_match');

$stmt = $conn->prepare("SELECT CHAR_LENGTH(question) AS len FROM chatbot_messages
                        WHERE company_id = ? ORDER BY message_id DESC LIMIT 1");
$stmt->bind_param("i", $companyId);
$stmt->execute();
$len = (int) $stmt->get_result()->fetch_assoc()['len'];
$stmt->close();

t_ok($len <= 500, 'a huge question is truncated before storage');

$stmt = $conn->prepare("SELECT COUNT(*) AS total FROM sales WHERE company_id = ?");
$stmt->bind_param("i", $companyId);
$stmt->execute();
t_same(0, (int) $stmt->get_result()->fetch_assoc()['total'],
    'the sales table still exists and is untouched');
$stmt->close();

/*
| Rate limit: exactly 19 questions in the last minute is under it, 20 is not.
| The two logs above are cleared first so the count is the one being tested --
| the plan's version left them in and asked for 19 more, which is 21.
*/
$stmt = $conn->prepare("DELETE FROM chatbot_messages WHERE company_id = ?");
$stmt->bind_param("i", $companyId);
$stmt->execute();
$stmt->close();

for ($i = 0; $i < 19; $i++) {
    chatbotLog($conn, $ctx, "q{$i}", null, null, 'no_match');
}

t_ok(!chatbotRateLimited($conn, $ctx), '19 questions in a minute is allowed');

chatbotLog($conn, $ctx, 'one more', null, null, 'no_match');

t_ok(chatbotRateLimited($conn, $ctx), 'the 20th trips the limit');

/* Review Focus 5: a session whose subscription lapsed after sign-in */
$live = ['user_id' => $userId, 'role' => 'admin',
         'company_id' => $companyId, 'subscription_active' => 1];

t_same(null, chatbotSessionProblem($live), 'a paid, signed-in session may ask');

$lapsed = $live;
$lapsed['subscription_active'] = 0;
t_same('no_subscription', chatbotSessionProblem($lapsed),
    'a lapsed subscription is refused, matching requireRole()');

$signedOut = $live;
unset($signedOut['user_id']);
t_same('signed_out', chatbotSessionProblem($signedOut), 'no user in session is refused');

$noCompany = $live;
unset($noCompany['company_id']);
t_same('signed_out', chatbotSessionProblem($noCompany),
    'a session with no company (Super Admin) is refused');

t_done();
