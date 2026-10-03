<?php
/*
| Spec section 6 of the Phase 1 design, as a table: every tick and every cross
| the six roles are promised, now that all six have questions.
*/
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/factory.php';
require_once __DIR__ . '/../../includes/chatbot/engine.php';

register_shutdown_function(function () use ($conn) { testCleanup($conn); });

$professional = testMakeCompany($conn, 'Phase2 Matrix Pro', 2);
$starter = testMakeCompany($conn, 'Phase2 Matrix Starter', 1);

$matrix = [
    'hr' => [
        'hr_headcount' => true, 'hr_pending_leave' => true, 'hr_attendance_today' => true,
        'hr_applicants' => true, 'hr_open_jobs' => true, 'hr_incomplete_records' => true,
        /* The matrix grants HR payroll information relevant to HR. */
        'finance_payroll_total' => true,
        'sales_today' => false, 'low_stock' => false, 'finance_expenses' => false,
        'inv_suppliers' => false, 'finance_payroll_pending' => false,
    ],
    'finance' => [
        'finance_payroll_total' => true, 'finance_payroll_pending' => true,
        'finance_expenses' => true, 'finance_payables' => true,
        'finance_stock_requests' => true,
        'hr_applicants' => false, 'hr_open_jobs' => false, 'sales_today' => false,
        'low_stock' => false, 'inv_suppliers' => false,
    ],
    'inventory' => [
        'inv_low_stock' => true, 'inv_out_of_stock' => true, 'inv_stock_requests' => true,
        'inv_suppliers' => true,
        'sales_today' => false, 'finance_payroll_total' => false, 'hr_headcount' => false,
        'staff_count' => false,
    ],
    /* "Owner / Admin -- everything the plan allows", spec section 6. The two
       exceptions are the HR and Inventory twins of answers Phase 1 already
       gives them: hr_headcount, inv_low_stock and inv_out_of_stock. */
    'admin' => [
        'sales_today' => true, 'low_stock' => true, 'staff_count' => true,
        'finance_payroll_total' => true, 'finance_expenses' => true,
        'hr_applicants' => true, 'hr_open_jobs' => true, 'hr_pending_leave' => true,
        'hr_attendance_today' => true, 'hr_incomplete_records' => true,
        'inv_suppliers' => true, 'inv_stock_requests' => true,
        'hr_headcount' => false, 'inv_low_stock' => false, 'inv_out_of_stock' => false,
    ],
    'cashier' => [
        'product_price' => true, 'my_sales_today' => true,
        'finance_payroll_total' => false, 'hr_headcount' => false, 'inv_low_stock' => false,
    ],
    'employee' => [
        'my_attendance_today' => true, 'my_leave_status' => true,
        'hr_headcount' => false, 'finance_expenses' => false, 'inv_low_stock' => false,
    ],
];

foreach ($matrix as $role => $expectations) {
    $allowed = chatbotAllowedIntents($conn, $professional, $role);

    foreach ($expectations as $intent => $mayAsk) {
        t_same($mayAsk, isset($allowed[$intent]),
            "{$role} " . ($mayAsk ? 'may' : 'may NOT') . " ask {$intent}");
    }
}

/* Every role gets the pay refusal, because every role can ask about pay. */
foreach (['admin', 'hr', 'finance', 'inventory', 'cashier', 'employee'] as $role) {
    $allowed = chatbotAllowedIntents($conn, $professional, $role);
    t_ok(isset($allowed['salary_not_available']), "{$role} gets the pay refusal");
}

/*
| Review Focus 5: Retail Starter buys pos, inventory, staff and reports -- not
| hrms, leave, attendance, recruitment, payroll or finance. And it sells only
| the Admin and Cashier seats, which is what keeps the other roles theoretical
| there.
*/
$starterTopics = ['pos', 'inventory', 'staff', 'reports'];

foreach (['hr', 'finance', 'inventory'] as $role) {
    $allowed = chatbotAllowedIntents($conn, $starter, $role);

    foreach ($allowed as $intent => $spec) {
        t_ok(in_array($spec['topic'], $starterTopics, true),
            "a Starter {$role} reaches only topics Starter bought, not {$intent}");
    }

    t_ok(!in_array($role, companyPlanRoles($conn, $starter), true),
        "Starter does not sell a {$role} seat, so no such account can ask");
}

$starterAdmin = chatbotAllowedIntents($conn, $starter, 'admin');

foreach (['finance_payroll_total', 'finance_payroll_pending', 'finance_expenses',
          'finance_payables', 'finance_stock_requests'] as $intent) {
    t_ok(!isset($starterAdmin[$intent]), "a Starter admin is refused {$intent}");
}

/* Every handler named in the catalog exists. */
foreach (chatbotIntents() as $id => $intent) {
    t_ok(function_exists($intent['handler']), "{$id}'s handler is a real function");
}

/* And every suggestion chip still round-trips to its own intent. */
foreach (['admin', 'hr', 'finance', 'inventory', 'cashier', 'employee'] as $role) {

    $ctx = ['company_id' => $professional, 'user_id' => 1, 'employee_id' => 1, 'role' => $role];
    $allowed = chatbotAllowedIntents($conn, $professional, $role);

    foreach (chatbotSuggestions($allowed, 20) as $suggestion) {

        if (!empty($allowed[$suggestion['id']]['needs_input'])) {
            continue;
        }

        $result = chatbotAnswer($conn, $ctx, $suggestion['label']);
        t_same($suggestion['id'], $result['intent'],
            "{$role}: chip \"{$suggestion['label']}\" reaches its own intent");
    }
}

t_done();
