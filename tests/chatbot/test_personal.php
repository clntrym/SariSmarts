<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/factory.php';
require_once __DIR__ . '/../../includes/chatbot/engine.php';

register_shutdown_function(function () use ($conn) { testCleanup($conn); });

$companyId = testMakeCompany($conn, 'Personal Co', 2);

$conn->query("INSERT INTO employees (company_id, first_name, last_name, employment_status)
              VALUES ({$companyId}, 'Ana', 'Cruz', 'Regular')");
$mine = (int) $conn->insert_id;

$conn->query("INSERT INTO employees (company_id, first_name, last_name, employment_status)
              VALUES ({$companyId}, 'Ben', 'Santos', 'Regular')");
$other = (int) $conn->insert_id;

/* time_in is a DATETIME, not a TIME: a bare '08:02:00' stores as zeroes. */
$conn->query("INSERT INTO attendance (employee_id, attendance_date, time_in, status, company_id)
              VALUES ({$mine}, CURDATE(), CONCAT(CURDATE(), ' 08:02:00'), 'Present', {$companyId})");
$conn->query("INSERT INTO attendance (employee_id, attendance_date, time_in, status, company_id)
              VALUES ({$other}, CURDATE(), CONCAT(CURDATE(), ' 07:45:00'), 'Present', {$companyId})");

$conn->query("INSERT INTO leave_requests (employee_id, leave_type, duration, start_date, end_date, reason, hr_status, admin_status, company_id)
              VALUES ({$mine}, 'Sick Leave', 'Whole Day', CURDATE(), CURDATE(), 'Lagnat', 'Pending', 'Pending', {$companyId})");

$ctx = ['company_id' => $companyId, 'user_id' => 1,
        'employee_id' => $mine, 'role' => 'employee'];

$result = chatbotAnswer($conn, $ctx, 'anong oras ang time in ko');
t_ok($result['ok'], 'own attendance answered');
$json = json_encode($result['answer']);
t_ok(str_contains($json, '08:02'), 'shows my time in');
t_ok(!str_contains($json, '07:45'), "does not show another employee's time in");

$result = chatbotAnswer($conn, $ctx, 'ano na ang leave ko');
t_ok($result['ok'], 'own leave answered');
t_ok(str_contains(json_encode($result['answer']), 'Sick Leave'), 'shows my leave request');

/* Review Focus 2: an account with no employee row */
$ownerCtx = ['company_id' => $companyId, 'user_id' => 1,
             'employee_id' => null, 'role' => 'employee'];
$result = chatbotAnswer($conn, $ownerCtx, 'anong oras ang time in ko');
t_ok(!$result['ok'], 'a user with no employee record is refused, not answered');
t_same('needs_employee', $result['reason'], 'and the reason says why');
t_ok($result['suggestions'] !== [], 'they are still offered something they can ask');

t_done();
