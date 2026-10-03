<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/factory.php';
require_once __DIR__ . '/../../includes/chatbot/engine.php';

register_shutdown_function(function () use ($conn) { testCleanup($conn); });

$companyId = testMakeCompany($conn, 'Advisory Personal Co', 2);

$conn->query("INSERT INTO employees (company_id, first_name, last_name, employment_status)
              VALUES ({$companyId}, 'Ana', 'Cruz', 'Official Employee')");
$mine = (int) $conn->insert_id;

$conn->query("INSERT INTO employees (company_id, first_name, last_name, employment_status)
              VALUES ({$companyId}, 'Ben', 'Santos', 'Official Employee')");
$colleague = (int) $conn->insert_id;

$ctx = ['company_id' => $companyId, 'user_id' => 5, 'employee_id' => $mine, 'role' => 'cashier'];

function personalAdvice(mysqli $conn, array $ctx, string $id): ?array
{
    foreach (chatbotRunAdvisories($conn, $ctx) as $entry) {
        if ($entry['id'] === $id) {
            return $entry;
        }
    }

    return null;
}

t_same(null, personalAdvice($conn, $ctx, 'my_time_out_missing'),
    'nothing to say before anyone clocks in');

/* I clocked in and never out; my colleague did both. */
$conn->query("INSERT INTO attendance (employee_id, attendance_date, time_in, status, company_id)
              VALUES ({$mine}, CURDATE(), CONCAT(CURDATE(), ' 08:00:00'), 'Present', {$companyId})");
$conn->query("INSERT INTO attendance (employee_id, attendance_date, time_in, time_out, status, company_id)
              VALUES ({$colleague}, CURDATE(), CONCAT(CURDATE(), ' 08:00:00'),
                      CONCAT(CURDATE(), ' 17:00:00'), 'Present', {$companyId})");

$advice = personalAdvice($conn, $ctx, 'my_time_out_missing');
t_ok($advice !== null, 'I am reminded that my time out is missing');
t_ok(str_contains(json_encode($advice['evidence']), '08:00'), 'with the time I clocked in');

/* My colleague is not reminded about mine. */
$colleagueCtx = ['company_id' => $companyId, 'user_id' => 6,
                 'employee_id' => $colleague, 'role' => 'cashier'];
t_same(null, personalAdvice($conn, $colleagueCtx, 'my_time_out_missing'),
    'a colleague is not shown my missing time out');

/* My own pending leave. */
$conn->query("INSERT INTO leave_requests (employee_id, leave_type, duration, start_date, end_date, reason, hr_status, admin_status, company_id)
              VALUES ({$mine}, 'Sick Leave', 'Whole Day', CURDATE(), CURDATE(), 'r', 'Pending', 'Pending', {$companyId})");

t_ok(personalAdvice($conn, $ctx, 'my_leave_pending') !== null,
    'I am told my leave is still pending');
t_same(null, personalAdvice($conn, $colleagueCtx, 'my_leave_pending'),
    'and my colleague is not');

/* An account with no employee record is shown no personal advice at all. */
$ownerCtx = ['company_id' => $companyId, 'user_id' => 9, 'employee_id' => null, 'role' => 'employee'];
$ids = array_column(chatbotRunAdvisories($conn, $ownerCtx), 'id');

t_ok(!in_array('my_time_out_missing', $ids, true),
    'an account with no employee record gets no personal advice');
t_ok(!in_array('my_leave_pending', $ids, true), 'neither kind');

t_done();
