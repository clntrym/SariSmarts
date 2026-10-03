<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/factory.php';
require_once __DIR__ . '/../../includes/chatbot/engine.php';

register_shutdown_function(function () use ($conn) { testCleanup($conn); });

$companyId = testMakeCompany($conn, 'Advisory HR Co', 2);
$ctx = ['company_id' => $companyId, 'user_id' => 1, 'employee_id' => null, 'role' => 'hr'];

$conn->query("INSERT INTO branch (company_id, branch_name, complete_address, province, city, status)
              VALUES ({$companyId}, 'Main', '1 St', 'Cavite', 'Imus', 'Active')");
$branchId = (int) $conn->insert_id;

$conn->query("INSERT INTO employees (company_id, first_name, last_name, branch_id, employment_status, email, phone)
              VALUES ({$companyId}, 'Ana', 'Cruz', {$branchId}, 'Official Employee', 'a@test.local', '0917')");
$ana = (int) $conn->insert_id;

function adviceById(array $advice, string $id): ?array
{
    foreach ($advice as $entry) {
        if ($entry['id'] === $id) {
            return $entry;
        }
    }

    return null;
}

/* Nothing is wrong yet. */
t_same(null, adviceById(chatbotRunAdvisories($conn, $ctx), 'applicants_waiting'),
    'no applicant advice when there are no applicants');
t_same(null, adviceById(chatbotRunAdvisories($conn, $ctx), 'leave_waiting'),
    'no leave advice when nothing is pending');
t_same(null, adviceById(chatbotRunAdvisories($conn, $ctx), 'incomplete_records'),
    'no records advice when every record is complete');

/* An applicant nobody has moved for ten days. */
$conn->query("INSERT INTO job (job_title, department, branch_id, vacancies, employment_type, status, application_deadline, company_id)
              VALUES ('Cashier', 'Cashier', {$branchId}, 1, 'Full Time', 'Published',
                      DATE_ADD(CURDATE(), INTERVAL 30 DAY), {$companyId})");
$jobId = (int) $conn->insert_id;

$conn->query("INSERT INTO applications (job_id, first_name, last_name, email, status, applied_at, company_id)
              VALUES ({$jobId}, 'Juan', 'Dela Cruz', 'j@test.local', 'Pending',
                      DATE_SUB(NOW(), INTERVAL 10 DAY), {$companyId})");

/* And one who applied today, who is not stale. */
$conn->query("INSERT INTO applications (job_id, first_name, last_name, email, status, applied_at, company_id)
              VALUES ({$jobId}, 'Maria', 'Santos', 'm@test.local', 'Pending', NOW(), {$companyId})");

$advice = adviceById(chatbotRunAdvisories($conn, $ctx), 'applicants_waiting');
t_ok($advice !== null, 'the stale applicant is flagged');

/* Review Focus 2: the figure shown is the figure judged. */
$evidence = [];

foreach ($advice['evidence'] as [$label, $value]) {
    $evidence[$label] = $value;
}

t_same('1', $evidence['Waiting longer than a week'] ?? null,
    'one applicant is stale, not both');

/* Leave waiting for HR. */
$conn->query("INSERT INTO leave_requests (employee_id, leave_type, duration, start_date, end_date, reason, hr_status, admin_status, company_id)
              VALUES ({$ana}, 'Sick Leave', 'Whole Day', CURDATE(), CURDATE(), 'r', 'Pending', 'Pending', {$companyId})");

$advice = adviceById(chatbotRunAdvisories($conn, $ctx), 'leave_waiting');
t_ok($advice !== null, 'the pending leave is flagged');
t_ok(str_contains($advice['message'], '1'), 'and counted');

/* An employee with no contact details. */
$conn->query("INSERT INTO employees (company_id, first_name, last_name, employment_status)
              VALUES ({$companyId}, 'Ben', 'Santos', 'Official Employee')");

$advice = adviceById(chatbotRunAdvisories($conn, $ctx), 'incomplete_records');
t_ok($advice !== null, 'the incomplete record is flagged');

/* Every advisory links somewhere HR can actually go. */
foreach (chatbotRunAdvisories($conn, $ctx) as $entry) {
    t_ok(!empty($entry['link']['href']), "{$entry['id']} links somewhere");
    t_ok(file_exists(__DIR__ . '/../../hr/' . $entry['link']['href']),
        "{$entry['id']} links to a page that exists for HR");
    t_ok($entry['evidence'] !== [], "{$entry['id']} carries its evidence");
}

t_done();
