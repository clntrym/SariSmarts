<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/factory.php';
require_once __DIR__ . '/../../includes/chatbot/engine.php';
require_once __DIR__ . '/../../includes/chatbot/chat/tool_runner.php';

register_shutdown_function(function () use ($conn) { testCleanup($conn); });

$companyId = testMakeCompany($conn, 'Phase2 Tools Co', 2);

/* The matrix, as tool names, for the three new roles. */
$matrix = [
    'hr' => [
        'staff_list' => true, 'attendance_summary' => true, 'attendance_detail' => true,
        'leave_requests' => true, 'recruitment_summary' => true,
        'sales_summary' => false, 'payment_mix' => false, 'stock_list' => false,
        'stock_requests' => false, 'finance_expenses' => false, 'finance_payables' => false,
    ],
    'finance' => [
        'finance_expenses' => true, 'finance_payables' => true, 'stock_requests' => true,
        'sales_summary' => false, 'staff_list' => false, 'attendance_summary' => false,
        'recruitment_summary' => false, 'leave_requests' => false, 'stock_list' => false,
    ],
    'inventory' => [
        'stock_list' => true, 'product_lookup' => true, 'stock_requests' => true,
        'sales_summary' => false, 'staff_list' => false, 'attendance_summary' => false,
        'finance_expenses' => false, 'recruitment_summary' => false,
    ],
];

foreach ($matrix as $role => $expectations) {
    $tools = chatToolsFor($conn, ['company_id' => $companyId, 'user_id' => 1,
                                  'employee_id' => 1, 'role' => $role]);

    foreach ($expectations as $tool => $mayUse) {
        t_same($mayUse, isset($tools[$tool]),
            "{$role} " . ($mayUse ? 'gets' : 'does NOT get') . " {$tool}");
    }
}

/* No tool anywhere touches pay -- including the new finance ones. */
foreach (chatTools() as $name => $tool) {
    $text = mb_strtolower(json_encode($tool));

    foreach (['payroll', 'salary', 'net_pay', 'deduction'] as $word) {
        t_ok(!str_contains($text, $word), "{$name} does not mention {$word}");
    }
}

/* The finance tools return this company's own figures. */
$ctx = ['company_id' => $companyId, 'user_id' => 1, 'employee_id' => null, 'role' => 'finance'];

$conn->query("INSERT INTO expenses (expense_code, expense_date, category, vendor, description, amount, payment_method, company_id)
              VALUES ('EXP-P2', CURDATE(), 'Utilities', 'Meralco', 'Power', 1500.00, 'Cash', {$companyId})");

$other = testMakeCompany($conn, 'Phase2 Other Co', 2);
$conn->query("INSERT INTO expenses (expense_code, expense_date, category, vendor, description, amount, payment_method, company_id)
              VALUES ('EXP-OTHER', CURDATE(), 'Utilities', 'Meralco', 'Power', 8888.00, 'Cash', {$other})");

$result = chatRunTool($conn, $ctx, 'finance_expenses', ['period' => 'this_month']);
t_ok($result['ok'], 'finance_expenses runs');
t_ok(str_contains(json_encode($result), '1500.00'), 'our expense is there');
t_ok(!str_contains(json_encode($result), '8888.00'), "another company's is not");

/* A payable is born from a stock request: both foreign keys must exist. */
$conn->query("INSERT INTO stock_requests (request_code, total_price, reason, status, company_id)
              VALUES ('SR-P2', 5000.00, 'restock', 'Received', {$companyId})");
$requestId = (int) $conn->insert_id;

$conn->query("INSERT INTO stock_request_items (request_id, item_description, vendor, quantity, unit_price, total_price, company_id)
              VALUES ({$requestId}, 'Rice', 'Supplier A', 10, 500.00, 5000.00, {$companyId})");
$itemId = (int) $conn->insert_id;

$conn->query("INSERT INTO accounts_payable (request_id, item_id, invoice_no, po_number, supplier,
                                            category, description, amount, paid_amount, due_date, status, company_id)
              VALUES ({$requestId}, {$itemId}, 'INV-P2', 'PO-P2', 'Supplier A', 'Stock', 'Delivery',
                      5000.00, 1000.00, DATE_ADD(CURDATE(), INTERVAL 7 DAY), 'Partial', {$companyId})");

$result = chatRunTool($conn, $ctx, 'finance_payables', ['status' => 'unpaid']);
t_ok($result['ok'], 'finance_payables runs');
t_ok(str_contains(json_encode($result), 'Supplier A'), 'the supplier is named');

/*
| Review Focus 5: Retail Starter buys pos, inventory, staff and reports. So a
| Starter HR would pass the topic gate for staff_list -- what stops it is that
| Starter does not sell an HR SEAT at all, so no such account exists to ask.
| Assert exactly that, rather than pretending the topic gate does work it does
| not do.
*/
$starter = testMakeCompany($conn, 'Phase2 Starter', 1);

$starterTopics = ['pos', 'inventory', 'staff', 'reports'];

foreach (['hr', 'finance', 'inventory'] as $role) {

    $tools = chatToolsFor($conn, ['company_id' => $starter, 'user_id' => 1,
                                  'employee_id' => 1, 'role' => $role]);

    foreach ($tools as $name => $tool) {
        t_ok(in_array($tool['topic'], $starterTopics, true),
            "a Starter {$role} reaches only topics Starter bought, not {$name}");
    }
}

/* The topics Starter did NOT buy stay shut for every role. */
foreach (['hr', 'finance', 'admin'] as $role) {

    $tools = chatToolsFor($conn, ['company_id' => $starter, 'user_id' => 1,
                                  'employee_id' => 1, 'role' => $role]);

    foreach (['finance_expenses', 'finance_payables', 'attendance_summary',
              'leave_requests', 'recruitment_summary', 'branch_list'] as $shut) {
        t_ok(!isset($tools[$shut]), "a Starter {$role} cannot reach {$shut}");
    }
}

foreach (['hr', 'finance', 'inventory'] as $role) {
    t_ok(!in_array($role, companyPlanRoles($conn, $starter), true),
        "Starter does not sell a {$role} seat, so no such account can ask");
}

t_done();
