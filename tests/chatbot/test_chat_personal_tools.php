<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/factory.php';
require_once __DIR__ . '/../../includes/chatbot/engine.php';
require_once __DIR__ . '/../../includes/chatbot/chat/tool_runner.php';

register_shutdown_function(function () use ($conn) { testCleanup($conn); });

$companyId = testMakeCompany($conn, 'Personal Tools Co', 2);
$otherId = testMakeCompany($conn, 'Personal Other Co', 2);

$conn->query("INSERT INTO employees (company_id, first_name, last_name, employment_status)
              VALUES ({$companyId}, 'Ana', 'Cruz', 'Official Employee')");
$mine = (int) $conn->insert_id;

$conn->query("INSERT INTO employees (company_id, first_name, last_name, employment_status)
              VALUES ({$companyId}, 'Ben', 'Santos', 'Official Employee')");
$colleague = (int) $conn->insert_id;

$conn->query("INSERT INTO employees (company_id, first_name, last_name, employment_status)
              VALUES ({$otherId}, 'Cely', 'Diaz', 'Official Employee')");
$outsider = (int) $conn->insert_id;

$conn->query("INSERT INTO attendance (employee_id, attendance_date, time_in, status, company_id)
              VALUES ({$mine}, CURDATE(), CONCAT(CURDATE(), ' 08:02:00'), 'Present', {$companyId})");
$conn->query("INSERT INTO attendance (employee_id, attendance_date, time_in, status, company_id)
              VALUES ({$colleague}, CURDATE(), CONCAT(CURDATE(), ' 07:30:00'), 'Present', {$companyId})");
$conn->query("INSERT INTO attendance (employee_id, attendance_date, time_in, status, company_id)
              VALUES ({$outsider}, CURDATE(), CONCAT(CURDATE(), ' 06:15:00'), 'Present', {$otherId})");

$conn->query("INSERT INTO leave_requests (employee_id, leave_type, duration, start_date, end_date, reason, hr_status, admin_status, company_id)
              VALUES ({$mine}, 'Sick Leave', 'Whole Day', CURDATE(), CURDATE(), 'Lagnat', 'Pending', 'Pending', {$companyId})");

$ctx = ['company_id' => $companyId, 'user_id' => 5,
        'employee_id' => $mine, 'role' => 'cashier'];

$result = chatRunTool($conn, $ctx, 'my_attendance', ['period' => 'this_month']);
t_ok($result['ok'], 'my_attendance runs for a cashier');
$json = json_encode($result);
t_ok(str_contains($json, '08:02'), 'it shows my own time in');
t_ok(!str_contains($json, '07:30'), "and not my colleague's");
t_ok(!str_contains($json, '06:15'), "and not another company's");

$result = chatRunTool($conn, $ctx, 'my_leave', ['status' => 'all']);
t_ok(str_contains(json_encode($result), 'Sick Leave'), 'my_leave shows my request');

/* Review Focus 1: an account with no employee record. */
$ownerCtx = ['company_id' => $companyId, 'user_id' => 9,
             'employee_id' => null, 'role' => 'employee'];
$result = chatRunTool($conn, $ownerCtx, 'my_attendance', ['period' => 'this_month']);
t_ok(!$result['ok'], 'an account with no employee record is refused');
t_ok(str_contains(strtolower((string) $result['error']), 'employee'),
    'and the model is told why');
t_same([], $result['rows'], 'with no rows at all');

/* Review Focus 2: an employee id from another company. */
$crossCtx = ['company_id' => $companyId, 'user_id' => 5,
             'employee_id' => $outsider, 'role' => 'cashier'];
$result = chatRunTool($conn, $crossCtx, 'my_attendance', ['period' => 'this_month']);
t_ok($result['ok'], 'a mismatched employee id is not an error');
t_same([], $result['rows'], 'but it finds nothing, because both keys are bound');

/* An admin has no personal tools in this stage. */
$adminCtx = ['company_id' => $companyId, 'user_id' => 1,
             'employee_id' => null, 'role' => 'admin'];
$result = chatRunTool($conn, $adminCtx, 'my_attendance', ['period' => 'this_month']);
t_ok(!$result['ok'], 'an admin does not get the personal tool');

t_done();
