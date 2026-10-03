<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/factory.php';
require_once __DIR__ . '/../../includes/chatbot/engine.php';
require_once __DIR__ . '/../../includes/chatbot/chat/tool_runner.php';

register_shutdown_function(function () use ($conn) { testCleanup($conn); });

$companyId = testMakeCompany($conn, 'People Co', 2);
$ctx = ['company_id' => $companyId, 'user_id' => 1, 'employee_id' => null, 'role' => 'admin'];

$conn->query("INSERT INTO branch (company_id, branch_name, complete_address, province, city, status)
              VALUES ({$companyId}, 'Main Branch', '1 Test St', 'Cavite', 'Imus', 'Active')");
$branchId = (int) $conn->insert_id;

$conn->query("INSERT INTO employees (company_id, first_name, last_name, branch_id, employment_status)
              VALUES ({$companyId}, 'Ana', 'Cruz', {$branchId}, 'Official Employee')");
$anaId = (int) $conn->insert_id;

$conn->query("INSERT INTO employees (company_id, first_name, last_name, employment_status)
              VALUES ({$companyId}, 'Ben', 'Santos', 'Archived')");

$conn->query("INSERT INTO users (company_id, employee_id, username, fullname, email, password, role, status)
              VALUES ({$companyId}, {$anaId}, 'ana', 'Ana Cruz', 'ana@test.local', 'x', 'Cashier', 'active')");

/* A salary that must never appear in any answer. */
$conn->query("INSERT INTO employment (employee_id, employment_type, employment_status, salary, salary_type, company_id)
              VALUES ({$anaId}, 'Full Time', 'Regular', 25000.00, 'Monthly', {$companyId})");

$result = chatRunTool($conn, $ctx, 'staff_list', ['state' => 'active']);

t_ok($result['ok'], 'staff_list runs');
t_ok(str_contains(json_encode($result), 'Ana'), 'it lists the active employee');
t_ok(!str_contains(json_encode($result), 'Ben'), 'and leaves out the archived one');
t_ok(str_contains(json_encode($result), 'Main Branch'), 'with the branch they belong to');

/* The whole point of the owner's decision. */
t_ok(!str_contains(json_encode($result), '25000'), 'no salary figure appears');

foreach ($result['columns'] as $column) {
    t_ok(!str_contains(strtolower($column), 'salary'), "column {$column} is not a salary");
    t_ok(!str_contains(strtolower($column), 'pay'), "column {$column} is not a pay field");
}

$result = chatRunTool($conn, $ctx, 'staff_list', ['state' => 'all']);
t_ok(str_contains(json_encode($result), 'Ben'), 'all includes the archived employee');

/* company_profile */
$result = chatRunTool($conn, $ctx, 'company_profile', []);
t_ok($result['ok'], 'company_profile runs');

$json = json_encode($result);
t_ok(str_contains($json, 'CHATBOT-TEST People Co'), 'it names the business');
t_ok(str_contains($json, 'Retail Professional'), 'and the plan they are on');

/* The company table also holds the approval token, the TIN and the paths to
   the uploaded permits. Asserting the exact column list is what keeps them
   out -- a "does not contain" check would pass on a column nobody noticed. */
t_same(['business', 'city', 'province', 'business_size', 'plan', 'branches',
        'active_staff'], $result['columns'],
    'it returns exactly the columns meant for a chat answer');

/* The cashier may not ask either of these in A2. */
$cashierCtx = ['company_id' => $companyId, 'user_id' => 1, 'employee_id' => $anaId, 'role' => 'cashier'];
t_ok(!chatRunTool($conn, $cashierCtx, 'staff_list', ['state' => 'active'])['ok'],
    'a cashier cannot list the staff');
t_ok(!chatRunTool($conn, $cashierCtx, 'company_profile', [])['ok'],
    'nor read the company profile');

t_done();
