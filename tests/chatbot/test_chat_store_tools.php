<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/factory.php';
require_once __DIR__ . '/../../includes/chatbot/engine.php';
require_once __DIR__ . '/../../includes/chatbot/chat/tool_runner.php';

register_shutdown_function(function () use ($conn) { testCleanup($conn); });

$companyId = testMakeCompany($conn, 'Store Co', 2);
$otherId = testMakeCompany($conn, 'Store Other Co', 2);
$ctx = ['company_id' => $companyId, 'user_id' => 1, 'employee_id' => null, 'role' => 'admin'];

$conn->query("INSERT INTO branch (company_id, branch_name, complete_address, province, city,
                                  opening_time, closing_time, operating_hours, status)
              VALUES ({$companyId}, 'Imus Branch', '1 Test St', 'Cavite', 'Imus',
                      '08:00:00', '17:00:00', 9.00, 'Active')");

$conn->query("INSERT INTO branch (company_id, branch_name, complete_address, province, city, status)
              VALUES ({$otherId}, 'Rival Branch', '2 Other St', 'Laguna', 'Calamba', 'Active')");

$result = chatRunTool($conn, $ctx, 'branch_list', []);

t_ok($result['ok'], 'branch_list runs');
$json = json_encode($result);
t_ok(str_contains($json, 'Imus Branch'), 'our branch is listed');
t_ok(!str_contains($json, 'Rival Branch'), "another company's branch is not");
t_ok(str_contains($json, '08:00'), 'with its opening time');

$cashierCtx = ['company_id' => $companyId, 'user_id' => 1, 'employee_id' => null, 'role' => 'cashier'];
t_ok(!chatRunTool($conn, $cashierCtx, 'branch_list', [])['ok'],
    'a cashier does not get the branch list in this stage');

t_done();
