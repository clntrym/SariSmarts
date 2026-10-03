<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/factory.php';
require_once __DIR__ . '/../../includes/chatbot/engine.php';

register_shutdown_function(function () use ($conn) { testCleanup($conn); });

$companyId = testMakeCompany($conn, 'HR Intents Co', 2);
$otherId = testMakeCompany($conn, 'HR Intents Other', 2);
$ctx = ['company_id' => $companyId, 'user_id' => 1, 'employee_id' => null, 'role' => 'hr'];

$conn->query("INSERT INTO branch (company_id, branch_name, complete_address, province, city, status)
              VALUES ({$companyId}, 'Main', '1 St', 'Cavite', 'Imus', 'Active')");
$branchId = (int) $conn->insert_id;

$conn->query("INSERT INTO employees (company_id, first_name, last_name, branch_id, employment_status, email, phone)
              VALUES ({$companyId}, 'Ana', 'Cruz', {$branchId}, 'Official Employee', 'ana@test.local', '09170000001')");
$ana = (int) $conn->insert_id;

/* Missing email and phone: an incomplete record. */
$conn->query("INSERT INTO employees (company_id, first_name, last_name, employment_status)
              VALUES ({$companyId}, 'Ben', 'Santos', 'Official Employee')");

$conn->query("INSERT INTO employees (company_id, first_name, last_name, employment_status)
              VALUES ({$otherId}, 'Outside', 'Person', 'Official Employee')");

$conn->query("INSERT INTO leave_requests (employee_id, leave_type, duration, start_date, end_date, reason, hr_status, admin_status, company_id)
              VALUES ({$ana}, 'Sick Leave', 'Whole Day', CURDATE(), CURDATE(), 'Lagnat', 'Pending', 'Pending', {$companyId})");

$conn->query("INSERT INTO attendance (employee_id, attendance_date, time_in, status, late_minutes, company_id)
              VALUES ({$ana}, CURDATE(), CONCAT(CURDATE(), ' 08:30:00'), 'Late', 30, {$companyId})");

$conn->query("INSERT INTO job (job_title, department, branch_id, vacancies, employment_type, status, application_deadline, company_id)
              VALUES ('Cashier', 'Cashier', {$branchId}, 2, 'Full Time', 'Published',
                      DATE_ADD(CURDATE(), INTERVAL 30 DAY), {$companyId})");
$jobId = (int) $conn->insert_id;

$conn->query("INSERT INTO applications (job_id, first_name, last_name, email, status, company_id)
              VALUES ({$jobId}, 'Juan', 'Dela Cruz', 'juan@test.local', 'Interview', {$companyId})");

$cases = [
    'ilan ang empleyado natin' => 'hr_headcount',
    'how many employees do we have' => 'hr_headcount',
    'which employees have pending leave requests' => 'hr_pending_leave',
    'sino ang may pending leave' => 'hr_pending_leave',
    'who is late today' => 'hr_attendance_today',
    'sino ang late ngayon' => 'hr_attendance_today',
    'how many applicants do we have' => 'hr_applicants',
    'ilan ang nag apply' => 'hr_applicants',
    'what job postings are open' => 'hr_open_jobs',
    'show employees with incomplete records' => 'hr_incomplete_records',
];

foreach ($cases as $question => $intent) {
    $result = chatbotAnswer($conn, $ctx, $question);
    t_same($intent, $result['intent'], "hr: \"{$question}\"");
    t_ok($result['ok'], "hr: \"{$question}\" is answered");
}

/* The answers are this company's own. */
$json = json_encode(chatbotAnswer($conn, $ctx, 'sino ang may pending leave')['answer']);
t_ok(str_contains($json, 'Ana Cruz'), 'the pending leave names our employee');
t_ok(!str_contains($json, 'Outside'), "and never another company's");

$json = json_encode(chatbotAnswer($conn, $ctx, 'show employees with incomplete records')['answer']);
t_ok(str_contains($json, 'Ben'), 'the incomplete record is found');
t_ok(!str_contains($json, 'Ana'), 'and the complete one is not listed');

$json = json_encode(chatbotAnswer($conn, $ctx, 'how many applicants do we have')['answer']);
t_ok(str_contains($json, 'Juan'), 'the applicant is named');

/* Review Focus 2: HR may not ask POS or finance questions. */
foreach (['magkano ang benta ngayong araw', 'show this month expenses',
          'which products are low in stock'] as $question) {
    $result = chatbotAnswer($conn, $ctx, $question);
    t_ok(!$result['ok'], "hr is refused: \"{$question}\"");
}

/* And the one subject nobody gets. */
$result = chatbotAnswer($conn, $ctx, 'how much is the salary of Ana');
t_same('salary_not_available', $result['intent'], 'hr asking about salary gets the plain answer');

t_done();
