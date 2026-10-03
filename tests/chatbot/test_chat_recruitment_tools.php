<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/factory.php';
require_once __DIR__ . '/../../includes/chatbot/engine.php';
require_once __DIR__ . '/../../includes/chatbot/chat/tool_runner.php';

register_shutdown_function(function () use ($conn) { testCleanup($conn); });

$companyId = testMakeCompany($conn, 'Recruit Co', 2);
$otherId = testMakeCompany($conn, 'Recruit Other Co', 2);
$ctx = ['company_id' => $companyId, 'user_id' => 1, 'employee_id' => null, 'role' => 'admin'];

/* job.branch_id is a foreign key, so a posting needs a branch to belong to. */
$conn->query("INSERT INTO branch (company_id, branch_name, complete_address, province, city, status)
              VALUES ({$companyId}, 'Main', '1 St', 'Cavite', 'Imus', 'Active')");
$branchId = (int) $conn->insert_id;

$conn->query("INSERT INTO branch (company_id, branch_name, complete_address, province, city, status)
              VALUES ({$otherId}, 'Their Main', '2 St', 'Laguna', 'Calamba', 'Active')");
$otherBranchId = (int) $conn->insert_id;

/* A posting with an advertised pay range that must never be returned. */
$conn->query("INSERT INTO job (job_title, department, branch_id, vacancies, employment_type, status,
                               salary_min, salary_max, application_deadline, company_id)
              VALUES ('Cashier', 'Cashier', {$branchId}, 2, 'Full Time', 'Published',
                      18000, 22000, DATE_ADD(CURDATE(), INTERVAL 30 DAY), {$companyId})");
$jobId = (int) $conn->insert_id;

$conn->query("INSERT INTO job (job_title, department, branch_id, vacancies, employment_type, status,
                               application_deadline, company_id)
              VALUES ('Draft Role', 'Inventory', {$branchId}, 1, 'Full Time', 'Draft',
                      DATE_ADD(CURDATE(), INTERVAL 30 DAY), {$companyId})");

$conn->query("INSERT INTO job (job_title, department, branch_id, vacancies, employment_type, status,
                               application_deadline, company_id)
              VALUES ('Outside Role', 'Cashier', {$otherBranchId}, 1, 'Full Time', 'Published',
                      DATE_ADD(CURDATE(), INTERVAL 30 DAY), {$otherId})");

$conn->query("INSERT INTO applications (job_id, first_name, last_name, email, status, company_id)
              VALUES ({$jobId}, 'Juan', 'Dela Cruz', 'juan@test.local', 'Pending', {$companyId})");
$conn->query("INSERT INTO applications (job_id, first_name, last_name, email, status, company_id)
              VALUES ({$jobId}, 'Maria', 'Santos', 'maria@test.local', 'Interview', {$companyId})");

$result = chatRunTool($conn, $ctx, 'recruitment_summary', ['state' => 'open']);

t_ok($result['ok'], 'recruitment_summary runs');
$json = json_encode($result);

t_ok(str_contains($json, 'Cashier'), 'the published posting is listed');
t_ok(!str_contains($json, 'Draft Role'), 'a draft is not an open posting');
t_ok(!str_contains($json, 'Outside Role'), "and another company's posting never appears");

/* Review Focus 3: the advertised pay range lives in the same table. */
t_ok(!str_contains($json, '18000'), 'the minimum advertised pay is not returned');
t_ok(!str_contains($json, '22000'), 'nor the maximum');

foreach ($result['columns'] as $column) {
    t_ok(!str_contains(strtolower($column), 'salary'), "column {$column} is not a salary");
}

$result = chatRunTool($conn, $ctx, 'recruitment_summary', ['state' => 'all']);
t_ok(str_contains(json_encode($result), 'Draft Role'), 'all includes drafts');

$cashierCtx = ['company_id' => $companyId, 'user_id' => 1, 'employee_id' => null, 'role' => 'cashier'];
t_ok(!chatRunTool($conn, $cashierCtx, 'recruitment_summary', ['state' => 'open'])['ok'],
    'a cashier cannot read recruitment');

t_done();
