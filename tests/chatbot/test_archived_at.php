<?php
/*
| Archiving an employee records WHEN. Without it, "two people left recently"
| cannot be answered, and an advisory that cannot show its evidence is not
| written (spec section 6.5).
*/
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/factory.php';

register_shutdown_function(function () use ($conn) { testCleanup($conn); });

$columns = [];
$result = $conn->query("SHOW COLUMNS FROM employees");

while ($row = $result->fetch_assoc()) {
    $columns[$row['Field']] = $row['Null'];
}

t_ok(isset($columns['archived_at']), 'employees records when somebody was archived');
t_same('YES', $columns['archived_at'] ?? '', 'and it is nullable, because most are not');

/* The statement hr/archive_employee.php carries must set it. */
$source = file_get_contents(__DIR__ . '/../../hr/archive_employee.php');

preg_match_all('/UPDATE employees\s+SET(.*?)WHERE/s', $source, $matches);

t_ok(count($matches[1]) >= 2, 'the archive page still has its two updates');

$archiving = null;
$restoring = null;

foreach ($matches[1] as $clause) {
    if (str_contains($clause, 'archived_at = NOW()')) {
        $archiving = $clause;
    }

    if (str_contains($clause, 'archived_at = NULL')) {
        $restoring = $clause;
    }
}

t_ok($archiving !== null, 'archiving stamps the date');
t_ok($restoring !== null, 'and restoring clears it');

/* And it works against the real table. */
$companyId = testMakeCompany($conn, 'Archived At Co', 2);

$conn->query("INSERT INTO employees (company_id, first_name, last_name, employment_status)
              VALUES ({$companyId}, 'Ana', 'Cruz', 'Official Employee')");
$employeeId = (int) $conn->insert_id;

$conn->query("UPDATE employees SET employment_status = 'Archived', archived_at = NOW()
               WHERE employee_id = {$employeeId} AND company_id = {$companyId}");

$row = $conn->query("SELECT archived_at FROM employees
                      WHERE employee_id = {$employeeId}")->fetch_assoc();

t_ok(!empty($row['archived_at']), 'the date is stored');

t_done();
