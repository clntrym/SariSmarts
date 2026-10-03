<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/factory.php';
require_once __DIR__ . '/../../includes/chatbot/engine.php';

register_shutdown_function(function () use ($conn) { testCleanup($conn); });

$companyId = testMakeCompany($conn, 'Personal Sales Co', 1);

$conn->query("INSERT INTO users (company_id, username, fullname, email, password, role, status)
              VALUES ({$companyId}, 'cash1', 'Cashier One', 'c1@test.local', 'x', 'cashier', 'active')");
$cashierOne = (int) $conn->insert_id;

$conn->query("INSERT INTO users (company_id, username, fullname, email, password, role, status)
              VALUES ({$companyId}, 'cash2', 'Cashier Two', 'c2@test.local', 'x', 'cashier', 'active')");
$cashierTwo = (int) $conn->insert_id;

$conn->query("INSERT INTO sales (company_id, total_amount, tax_amount, cash_received, change_amount, payment_method, created_by)
              VALUES ({$companyId}, 100.00, 0.00, 100.00, 0.00, 'Cash', {$cashierOne})");
$conn->query("INSERT INTO sales (company_id, total_amount, tax_amount, cash_received, change_amount, payment_method, created_by)
              VALUES ({$companyId}, 500.00, 0.00, 500.00, 0.00, 'Cash', {$cashierTwo})");
/* A sale from before this column existed. */
$conn->query("INSERT INTO sales (company_id, total_amount, tax_amount, cash_received, change_amount, payment_method)
              VALUES ({$companyId}, 999.00, 0.00, 999.00, 0.00, 'Cash')");

$ctx = ['company_id' => $companyId, 'user_id' => $cashierOne,
        'employee_id' => null, 'role' => 'cashier'];

$result = chatbotAnswer($conn, $ctx, 'how much have I sold today');
t_ok($result['ok'], 'cashier gets their own sales');

$json = json_encode($result['answer']);
t_ok(str_contains($json, '100.00'), 'own sale is counted');
t_ok(!str_contains($json, '500.00'), "another cashier's sale is not counted");
t_ok(!str_contains($json, '999.00'), 'an unattributed sale is not counted as theirs');
t_ok(str_contains(mb_strtolower($json), 'do not record who rang them up'),
    'the answer says older sales carry no cashier');

t_done();
