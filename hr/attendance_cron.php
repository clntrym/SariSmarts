<?php

/*
|--------------------------------------------------------------------------
| DAILY ABSENT MARKER
|--------------------------------------------------------------------------
|
| Marks every official employee who has no attendance row for today as
| Absent. Runs across all companies, which is correct for a scheduled job,
| but each row it writes is stamped with the employee's own company so the
| record still belongs to exactly one tenant.
|
| Two problems this file used to have:
|
|   1. It filtered on `employee_status`, a column that does not exist —
|      the employees table has `employment_status`. The query silently
|      returned nothing, so the job never marked anybody absent.
|
|   2. It had no access check at all while sitting under the web root, so
|      anyone who knew the URL could trigger it. It now refuses browser
|      requests and only runs from the command line or with the shared
|      token below.
|
*/

require_once(__DIR__ . "/../init.php");

$secretsFile = 'C:/xampp/private_config/sarismart_secrets.php';
$secrets = is_readable($secretsFile) ? require $secretsFile : [];
$cronToken = $secrets['CRON_TOKEN'] ?? '';

$isCli = (php_sapi_name() === 'cli');
$hasToken = isset($_GET['token']) && hash_equals($cronToken, (string) $_GET['token']);

if (!$isCli && !$hasToken) {
    http_response_code(403);
    exit('This job is not publicly accessible.');
}

$today = date("Y-m-d");

$getEmployees = $conn->prepare("
    SELECT employee_id, company_id
    FROM employees
    WHERE employment_status = 'Official Employee'
      AND company_id IS NOT NULL
");
$getEmployees->execute();
$employees = $getEmployees->get_result();

$check = $conn->prepare("
    SELECT attendance_id
    FROM attendance
    WHERE employee_id = ?
      AND company_id = ?
      AND attendance_date = ?
    LIMIT 1
");

$insert = $conn->prepare("
    INSERT INTO attendance
    (company_id, employee_id, attendance_date, status, remarks)
    VALUES (?, ?, ?, 'Absent', 'No Time In')
");

$marked = 0;

while ($emp = $employees->fetch_assoc()) {

    $employeeId = (int) $emp['employee_id'];
    $companyId = (int) $emp['company_id'];

    $check->bind_param("iis", $employeeId, $companyId, $today);
    $check->execute();

    if ($check->get_result()->num_rows === 0) {
        $insert->bind_param("iis", $companyId, $employeeId, $today);
        $insert->execute();
        $marked++;
    }
}

$check->close();
$insert->close();
$getEmployees->close();

echo "Attendance generated. Marked absent: " . $marked;
