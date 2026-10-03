<?php
/*
| The role and plan matrix from the spec, asserted one cell at a time.
|
| Every tick and every cross in section 6 of the design that Phase 1 can reach
| is a line here.
*/
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/factory.php';
require_once __DIR__ . '/../../includes/chatbot/engine.php';

register_shutdown_function(function () use ($conn) { testCleanup($conn); });

$starter = testMakeCompany($conn, 'Matrix Starter', 1);
$professional = testMakeCompany($conn, 'Matrix Professional', 2);

$matrix = [
    'admin' => [
        'sales_today' => true, 'sales_month' => true, 'top_products_month' => true,
        'low_stock' => true, 'out_of_stock' => true, 'product_price' => true,
        'product_stock' => true, 'staff_count' => true,
        'my_sales_today' => false, 'my_attendance_today' => false, 'my_leave_status' => false,
    ],
    'cashier' => [
        'product_price' => true, 'product_stock' => true, 'my_sales_today' => true,
        'my_attendance_today' => true, 'my_leave_status' => true,
        'sales_today' => false, 'sales_month' => false, 'top_products_month' => false,
        'low_stock' => false, 'out_of_stock' => false, 'staff_count' => false,
    ],
    'employee' => [
        'my_attendance_today' => true, 'my_leave_status' => true,
        'sales_today' => false, 'product_price' => false, 'low_stock' => false,
        'staff_count' => false, 'my_sales_today' => false,
    ],
    /* HR, Finance and Inventory get their own questions in Phase 2 and 3.
       Until then they must reach none of these. */
    'hr' => [
        'sales_today' => false, 'product_price' => false, 'low_stock' => false,
        'my_sales_today' => false, 'staff_count' => false,
    ],
    'finance' => [
        'sales_today' => false, 'low_stock' => false, 'my_sales_today' => false,
        'product_price' => false,
    ],
    'inventory' => [
        'sales_today' => false, 'my_sales_today' => false, 'staff_count' => false,
    ],
];

foreach ($matrix as $role => $expectations) {
    $allowed = chatbotAllowedIntents($conn, $professional, $role);

    foreach ($expectations as $intentId => $mayAsk) {
        t_same($mayAsk, isset($allowed[$intentId]),
            "{$role} " . ($mayAsk ? 'may' : 'may NOT') . " ask {$intentId}");
    }
}

/* Plan gate: Starter keeps the four topics it bought and nothing more. */
$starterAdmin = chatbotAllowedIntents($conn, $starter, 'admin');

foreach (['sales_today', 'low_stock', 'staff_count', 'top_products_month'] as $intentId) {
    t_ok(isset($starterAdmin[$intentId]), "Starter admin keeps {$intentId}");
}

foreach (chatbotIntents() as $intentId => $intent) {
    if (in_array($intent['topic'], ['hrms', 'payroll', 'finance', 'recruitment', 'cross_branch'], true)) {
        t_ok(!isset($starterAdmin[$intentId]),
            "Starter admin is refused {$intentId} ({$intent['topic']})");
    }
}

/* A company with no active subscription is refused everything. */
$lapsed = testMakeCompany($conn, 'Matrix Lapsed', 2);
$conn->query("UPDATE company_subscriptions SET status = 'Expired' WHERE company_id = {$lapsed}");

t_same([], chatbotAllowedIntents($conn, $lapsed, 'admin'),
    'a company with no active subscription may ask nothing');

t_done();
