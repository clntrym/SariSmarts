<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/factory.php';
require_once __DIR__ . '/../../includes/chatbot/engine.php';

register_shutdown_function(function () use ($conn) { testCleanup($conn); });

$companyId = testMakeCompany($conn, 'Advisory Co', 2);
$hrCtx = ['company_id' => $companyId, 'user_id' => 1, 'employee_id' => null, 'role' => 'hr'];

/* Review Focus 1: a company with nothing wrong. */
$result = chatbotAnswer($conn, $hrCtx, 'what needs my attention');

t_same('what_needs_attention', $result['intent'], 'the question is understood');
t_ok($result['ok'], 'and answered');
t_same([], $result['answer']['table']['rows'] ?? [], 'with no invented work');
t_ok(str_contains(mb_strtolower((string) $result['answer']['note']), 'nothing'),
    'it says plainly that nothing needs attention');

/* The Tagalog form reaches it too. */
t_same('what_needs_attention',
    chatbotAnswer($conn, $hrCtx, 'may dapat ba akong asikasuhin')['intent'],
    'the Tagalog question reaches the same answer');

/* Now somebody leaves, and no posting is open. */
$conn->query("INSERT INTO employees (company_id, first_name, last_name, employment_status, archived_at)
              VALUES ({$companyId}, 'Ana', 'Cruz', 'Archived', DATE_SUB(NOW(), INTERVAL 10 DAY))");
$conn->query("INSERT INTO employees (company_id, first_name, last_name, employment_status, archived_at)
              VALUES ({$companyId}, 'Ben', 'Santos', 'Archived', DATE_SUB(NOW(), INTERVAL 20 DAY))");

/* Somebody who left two years ago is not a reason to hire today. */
$conn->query("INSERT INTO employees (company_id, first_name, last_name, employment_status, archived_at)
              VALUES ({$companyId}, 'Old', 'Leaver', 'Archived', DATE_SUB(NOW(), INTERVAL 800 DAY))");

$advice = chatbotRunAdvisories($conn, $hrCtx);
$hiring = null;

foreach ($advice as $entry) {
    if ($entry['id'] === 'hiring_needed') {
        $hiring = $entry;
    }
}

t_ok($hiring !== null, 'the hiring advisory fires when people left and nothing is posted');
t_ok(str_contains($hiring['message'], '2'),
    'the message names how many left recently, not the one from two years ago');

/* Spec section 6.5: every advisory carries the figures that triggered it. */
t_ok($hiring['evidence'] !== [], 'it carries its evidence');

foreach ($hiring['evidence'] as [$label, $value]) {
    t_ok($label !== '' && $value !== '', 'each piece of evidence is a label and a figure');
}

/* It suggests; it does not act. */
t_ok(isset($hiring['link']['href']), 'it links to where the work is done');
t_ok(file_exists(__DIR__ . '/../../hr/' . $hiring['link']['href']),
    'and that page exists for HR');

/* Once a posting is open, the advice stops. */
$conn->query("INSERT INTO branch (company_id, branch_name, complete_address, province, city, status)
              VALUES ({$companyId}, 'Main', '1 St', 'Cavite', 'Imus', 'Active')");
$branchId = (int) $conn->insert_id;

$conn->query("INSERT INTO job (job_title, department, branch_id, vacancies, employment_type, status, application_deadline, company_id)
              VALUES ('Cashier', 'Cashier', {$branchId}, 1, 'Full Time', 'Published',
                      DATE_ADD(CURDATE(), INTERVAL 30 DAY), {$companyId})");

$ids = array_column(chatbotRunAdvisories($conn, $hrCtx), 'id');
t_ok(!in_array('hiring_needed', $ids, true),
    'with a posting open, the assistant stops suggesting one');

/* And the answer now shows the advice as rows, with the evidence visible. */
$conn->query("UPDATE job SET status = 'Draft' WHERE company_id = {$companyId}");
$answer = chatbotAnswer($conn, $hrCtx, 'what needs my attention')['answer'];

t_ok(count($answer['table']['rows'] ?? []) >= 1, 'the answer lists the advice');
t_ok(str_contains(json_encode($answer), 'hiring') || str_contains(json_encode($answer), 'posting'),
    'and names the hiring suggestion');

/* Nothing anywhere writes. */
$stmt = $conn->prepare("SELECT COUNT(*) AS jobs FROM job WHERE company_id = ?");
$stmt->bind_param("i", $companyId);
$stmt->execute();
t_same(1, (int) $stmt->get_result()->fetch_assoc()['jobs'],
    'asking for advice created no job posting');
$stmt->close();

t_done();
