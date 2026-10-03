<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/factory.php';
require_once __DIR__ . '/../../includes/chatbot/engine.php';
require_once __DIR__ . '/../../includes/chatbot/chat/tool_runner.php';

register_shutdown_function(function () use ($conn) { testCleanup($conn); });

$companyId = testMakeCompany($conn, 'Leave Co', 2);
$otherId = testMakeCompany($conn, 'Leave Other Co', 2);
$ctx = ['company_id' => $companyId, 'user_id' => 1, 'employee_id' => null, 'role' => 'admin'];

$conn->query("INSERT INTO employees (company_id, first_name, last_name, employment_status)
              VALUES ({$companyId}, 'Ana', 'Cruz', 'Official Employee')");
$ana = (int) $conn->insert_id;

$conn->query("INSERT INTO employees (company_id, first_name, last_name, employment_status)
              VALUES ({$otherId}, 'Outside', 'Person', 'Official Employee')");
$outsider = (int) $conn->insert_id;

$conn->query("INSERT INTO leave_requests (employee_id, leave_type, duration, start_date, end_date, reason, hr_status, admin_status, company_id)
              VALUES ({$ana}, 'Sick Leave', 'Whole Day', CURDATE(), CURDATE(), 'Lagnat', 'Pending', 'Pending', {$companyId})");
$conn->query("INSERT INTO leave_requests (employee_id, leave_type, duration, start_date, end_date, reason, hr_status, admin_status, company_id)
              VALUES ({$ana}, 'Vacation Leave', 'Whole Day', CURDATE(), CURDATE(), 'Bakasyon', 'Approved', 'Approved', {$companyId})");
$conn->query("INSERT INTO leave_requests (employee_id, leave_type, duration, start_date, end_date, reason, hr_status, admin_status, company_id)
              VALUES ({$outsider}, 'Emergency Leave', 'Whole Day', CURDATE(), CURDATE(), 'Sikreto', 'Pending', 'Pending', {$otherId})");

$result = chatRunTool($conn, $ctx, 'leave_requests', ['status' => 'Pending']);
t_ok($result['ok'], 'leave_requests runs');
$json = json_encode($result);
t_ok(str_contains($json, 'Sick Leave'), 'the pending request is listed');
t_ok(!str_contains($json, 'Vacation Leave'), 'the approved one is not');
t_ok(!str_contains($json, 'Emergency Leave'), "and neither is another company's");
t_ok(str_contains($json, 'Ana Cruz'), 'the employee is named');

$result = chatRunTool($conn, $ctx, 'leave_requests', ['status' => 'all']);
t_ok(str_contains(json_encode($result), 'Vacation Leave'), 'all includes the approved one');

/* The reason an employee gives is theirs; it is not part of an overview. */
t_ok(!str_contains(json_encode($result), 'Lagnat'),
    'the private reason is not exposed in the company-wide list');

$cashierCtx = ['company_id' => $companyId, 'user_id' => 1, 'employee_id' => $ana, 'role' => 'cashier'];
t_ok(!chatRunTool($conn, $cashierCtx, 'leave_requests', ['status' => 'all'])['ok'],
    "a cashier cannot read everyone's leave");

t_done();
