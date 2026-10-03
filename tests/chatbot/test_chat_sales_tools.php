<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/factory.php';
require_once __DIR__ . '/../../includes/chatbot/engine.php';
require_once __DIR__ . '/../../includes/chatbot/chat/tool_runner.php';

register_shutdown_function(function () use ($conn) { testCleanup($conn); });

$companyId = testMakeCompany($conn, 'Sales Tools Co', 2);
$ctx = ['company_id' => $companyId, 'user_id' => 1, 'employee_id' => null, 'role' => 'admin'];

$conn->query("INSERT INTO categories (category_name, company_id) VALUES ('Cat', {$companyId})");
$categoryId = (int) $conn->insert_id;
$conn->query("INSERT INTO products (product_name, category_id, company_id)
              VALUES ('Lucky Me', {$categoryId}, {$companyId})");
$productId = (int) $conn->insert_id;

/* Two sales today, one cash and one GCash, and one 40 days ago. */
$conn->query("INSERT INTO sales (company_id, total_amount, tax_amount, cash_received, change_amount, payment_method)
              VALUES ({$companyId}, 100.00, 0.00, 100.00, 0.00, 'Cash')");
$saleId = (int) $conn->insert_id;
$conn->query("INSERT INTO sale_items (sale_id, product_id, quantity, selling_price, company_id)
              VALUES ({$saleId}, {$productId}, 4, 25.00, {$companyId})");

$conn->query("INSERT INTO sales (company_id, total_amount, tax_amount, cash_received, change_amount, payment_method)
              VALUES ({$companyId}, 50.00, 0.00, 50.00, 0.00, 'GCash')");

$conn->query("INSERT INTO sales (company_id, total_amount, tax_amount, cash_received, change_amount, payment_method, sale_date)
              VALUES ({$companyId}, 999.00, 0.00, 999.00, 0.00, 'Cash', DATE_SUB(CURDATE(), INTERVAL 40 DAY))");

/* sales_summary */
$result = chatRunTool($conn, $ctx, 'sales_summary', ['period' => 'today']);
t_ok($result['ok'], 'sales_summary runs');
t_same('150.00', $result['rows'][0][0], 'today totals only today');

$result = chatRunTool($conn, $ctx, 'sales_summary', ['period' => 'custom',
    'from' => date('Y-m-d', strtotime('-60 days')), 'to' => date('Y-m-d')]);
t_same('1149.00', $result['rows'][0][0], 'a custom range reaches the older sale');

/* sales_by_day */
$result = chatRunTool($conn, $ctx, 'sales_by_day',
    ['from' => date('Y-m-d'), 'to' => date('Y-m-d')]);
t_same(1, count($result['rows']), 'sales_by_day returns one row per day with sales');

/* top_products */
$result = chatRunTool($conn, $ctx, 'top_products', ['period' => 'today']);
t_same('Lucky Me', $result['rows'][0][0], 'top_products names the product');
t_same('4', $result['rows'][0][1], 'and the quantity sold');

/* payment_mix */
$result = chatRunTool($conn, $ctx, 'payment_mix', ['period' => 'today']);
$methods = array_column($result['rows'], 0);
t_ok(in_array('Cash', $methods, true) && in_array('GCash', $methods, true),
    'payment_mix splits by method');

/* Refusals happen in the runner, before any query. */
$result = chatRunTool($conn, $ctx, 'sales_summary', ['period' => 'last_decade']);
t_ok(!$result['ok'], 'a bad parameter is refused');
t_ok(str_contains((string) $result['error'], 'Allowed'), 'and the model is told what is allowed');

$result = chatRunTool($conn, $ctx, 'no_such_tool', []);
t_ok(!$result['ok'], 'an unknown tool is refused');

$cashierCtx = ['company_id' => $companyId, 'user_id' => 1, 'employee_id' => null, 'role' => 'cashier'];
$result = chatRunTool($conn, $cashierCtx, 'sales_summary', ['period' => 'today']);
t_ok(!$result['ok'], 'a cashier cannot run the sales tool even by naming it directly');
t_ok(!str_contains(json_encode($result), '150.00'), 'and the refusal carries no figures');

t_done();
