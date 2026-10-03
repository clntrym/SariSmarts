<?php
/*
| Which tools each role gets, on each plan -- spec section 3, as a table.
*/
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/factory.php';
require_once __DIR__ . '/../../includes/chatbot/engine.php';
require_once __DIR__ . '/../../includes/chatbot/chat/tool_runner.php';

register_shutdown_function(function () use ($conn) { testCleanup($conn); });

$starter = testMakeCompany($conn, 'Matrix Chat Starter', 1);
$professional = testMakeCompany($conn, 'Matrix Chat Pro', 2);

$matrix = [
    'admin' => [
        'sales_summary' => true, 'sales_by_day' => true, 'top_products' => true,
        'payment_mix' => true, 'stock_list' => true, 'product_lookup' => true,
        'stock_requests' => true, 'staff_list' => true, 'company_profile' => true,
        'attendance_summary' => true, 'attendance_detail' => true,
        'leave_requests' => true, 'recruitment_summary' => true, 'branch_list' => true,
        'my_attendance' => false, 'my_leave' => false,
    ],
    'cashier' => [
        'stock_list' => true, 'product_lookup' => true,
        'my_attendance' => true, 'my_leave' => true,
        'sales_summary' => false, 'staff_list' => false, 'attendance_summary' => false,
        'leave_requests' => false, 'recruitment_summary' => false, 'branch_list' => false,
        'company_profile' => false, 'stock_requests' => false,
    ],
    'employee' => [
        'my_attendance' => true, 'my_leave' => true,
        'stock_list' => false, 'product_lookup' => false, 'sales_summary' => false,
        'staff_list' => false, 'attendance_summary' => false,
    ],
    /* Phase 2A gave these three roles their own tools; before it, they had
       none. The crosses that remain are the matrix's own. */
    'hr' => [
        'staff_list' => true, 'attendance_summary' => true, 'leave_requests' => true,
        'recruitment_summary' => true,
        'sales_summary' => false, 'stock_list' => false, 'finance_expenses' => false,
    ],
    'finance' => [
        'finance_expenses' => true, 'finance_payables' => true, 'stock_requests' => true,
        'sales_summary' => false, 'leave_requests' => false, 'staff_list' => false,
    ],
    'inventory' => [
        'stock_list' => true, 'product_lookup' => true, 'stock_requests' => true,
        'sales_summary' => false, 'staff_list' => false, 'finance_expenses' => false,
    ],
];

foreach ($matrix as $role => $expectations) {
    $tools = chatToolsFor($conn, ['company_id' => $professional, 'user_id' => 1,
                                  'employee_id' => 1, 'role' => $role]);

    foreach ($expectations as $tool => $mayUse) {
        t_same($mayUse, isset($tools[$tool]),
            "{$role} " . ($mayUse ? 'gets' : 'does NOT get') . " {$tool}");
    }
}

/* Review Focus 5: Retail Starter bought pos, inventory, staff and reports. */
$starterAdmin = chatToolsFor($conn, ['company_id' => $starter, 'user_id' => 1,
                                     'employee_id' => null, 'role' => 'admin']);

foreach (['sales_summary', 'stock_list', 'staff_list', 'company_profile', 'top_products'] as $tool) {
    t_ok(isset($starterAdmin[$tool]), "Starter admin keeps {$tool}");
}

foreach (['attendance_summary', 'attendance_detail', 'leave_requests',
          'recruitment_summary', 'branch_list'] as $tool) {
    t_ok(!isset($starterAdmin[$tool]), "Starter admin is refused {$tool}");
}

/* The personal tools sit under 'staff', so a Starter cashier keeps them. */
$starterCashier = chatToolsFor($conn, ['company_id' => $starter, 'user_id' => 1,
                                       'employee_id' => 5, 'role' => 'cashier']);
t_ok(isset($starterCashier['my_attendance']), 'a Starter cashier keeps their own attendance');
t_ok(isset($starterCashier['my_leave']), 'and their own leave');

/* A company with no active subscription reaches nothing. */
$lapsed = testMakeCompany($conn, 'Matrix Chat Lapsed', 2);
$conn->query("UPDATE company_subscriptions SET status = 'Expired' WHERE company_id = {$lapsed}");

t_same([], chatToolsFor($conn, ['company_id' => $lapsed, 'user_id' => 1,
                                'employee_id' => null, 'role' => 'admin']),
    'a company with no active subscription gets no tools at all');

/* Every handler the catalog names must exist, or the model would pick a tool
   that fails at runtime. */
foreach (chatTools() as $name => $tool) {
    t_ok(function_exists($tool['handler']), "{$name}'s handler is a real function");
}

t_done();
