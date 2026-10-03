<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/factory.php';
require_once __DIR__ . '/../../includes/chatbot/engine.php';
require_once __DIR__ . '/../../includes/chatbot/chat/tool_runner.php';

register_shutdown_function(function () use ($conn) { testCleanup($conn); });

$companyId = testMakeCompany($conn, 'Attendance Co', 2);
$otherId = testMakeCompany($conn, 'Attendance Other Co', 2);

$ctx = ['company_id' => $companyId, 'user_id' => 1, 'employee_id' => null, 'role' => 'admin'];

$conn->query("INSERT INTO employees (company_id, first_name, last_name, employment_status)
              VALUES ({$companyId}, 'Ana', 'Cruz', 'Official Employee')");
$ana = (int) $conn->insert_id;

$conn->query("INSERT INTO employees (company_id, first_name, last_name, employment_status)
              VALUES ({$otherId}, 'Ana', 'Reyes', 'Official Employee')");
$otherAna = (int) $conn->insert_id;

$conn->query("INSERT INTO attendance (employee_id, attendance_date, time_in, status, late_minutes, company_id)
              VALUES ({$ana}, CURDATE(), CONCAT(CURDATE(), ' 08:20:00'), 'Late', 20, {$companyId})");
$conn->query("INSERT INTO attendance (employee_id, attendance_date, time_in, status, late_minutes, company_id)
              VALUES ({$ana}, DATE_SUB(CURDATE(), INTERVAL 1 DAY),
                      CONCAT(DATE_SUB(CURDATE(), INTERVAL 1 DAY), ' 07:55:00'), 'Present', 0, {$companyId})");

/* The other company's employee is late every day -- none of it may show. */
$conn->query("INSERT INTO attendance (employee_id, attendance_date, time_in, status, late_minutes, company_id)
              VALUES ({$otherAna}, CURDATE(), CONCAT(CURDATE(), ' 09:45:00'), 'Late', 105, {$otherId})");

$result = chatRunTool($conn, $ctx, 'attendance_summary', ['period' => 'this_month']);

t_ok($result['ok'], 'attendance_summary runs');
$json = json_encode($result);
t_ok(str_contains($json, 'Ana Cruz'), 'it names our employee');
t_ok(!str_contains($json, 'Ana Reyes'), "and not the other company's");
t_ok(!str_contains($json, '105'), "nor the other company's late minutes");

/* Review Focus 4: a name that belongs to another company. */
$result = chatRunTool($conn, $ctx, 'attendance_detail',
    ['employee' => 'Reyes', 'period' => 'this_month']);
t_ok($result['ok'], 'asking about an outside name is not an error');
t_same([], $result['rows'], 'it simply finds nobody');

/*
| A custom range, not 'this_month': the rows are seeded for today and
| yesterday, and on the first of a month yesterday is in the previous one --
| so this assertion failed once a month for reasons that had nothing to do
| with what it is testing.
*/
$result = chatRunTool($conn, $ctx, 'attendance_detail', [
    'employee' => 'Cruz',
    'period' => 'custom',
    'from' => date('Y-m-d', strtotime('-7 days')),
    'to' => date('Y-m-d'),
]);
t_ok(count($result['rows']) >= 2, 'our own employee has their days listed');
t_ok(str_contains(json_encode($result), '08:20'), 'with the time they clocked in');

/* A cashier may not ask about the company's attendance. */
$cashierCtx = ['company_id' => $companyId, 'user_id' => 1, 'employee_id' => $ana, 'role' => 'cashier'];
t_ok(!chatRunTool($conn, $cashierCtx, 'attendance_summary', ['period' => 'this_month'])['ok'],
    'a cashier cannot read company attendance');
t_ok(!chatRunTool($conn, $cashierCtx, 'attendance_detail',
    ['employee' => 'Cruz', 'period' => 'this_month'])['ok'],
    "nor a colleague's attendance detail");

t_done();
