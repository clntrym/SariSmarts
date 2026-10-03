<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/factory.php';
require_once __DIR__ . '/../../includes/chatbot/engine.php';

register_shutdown_function(function () use ($conn) { testCleanup($conn); });

$companyId = testMakeCompany($conn, 'Answers Co', 2);

/* A category, two products, stock for both, and one sale today. */
$conn->query("INSERT INTO categories (category_name, company_id) VALUES ('Noodles', {$companyId})");
$categoryId = (int) $conn->insert_id;

$conn->query("INSERT INTO products (product_name, category_id, company_id)
              VALUES ('Lucky Me Pancit Canton', {$categoryId}, {$companyId})");
$productId = (int) $conn->insert_id;

$conn->query("INSERT INTO products (product_name, category_id, company_id)
              VALUES ('Coke Mismo', {$categoryId}, {$companyId})");
$emptyProductId = (int) $conn->insert_id;

$conn->query("INSERT INTO inventory (product_id, quantity, purchase_cost, selling_price, reorder_level, company_id)
              VALUES ({$productId}, 3, 10.00, 15.00, 5, {$companyId})");
$conn->query("INSERT INTO inventory (product_id, quantity, purchase_cost, selling_price, reorder_level, company_id)
              VALUES ({$emptyProductId}, 0, 15.00, 20.00, 5, {$companyId})");

$conn->query("INSERT INTO sales (company_id, total_amount, tax_amount, cash_received, change_amount, payment_method)
              VALUES ({$companyId}, 150.00, 0.00, 200.00, 50.00, 'Cash')");
$saleId = (int) $conn->insert_id;

$conn->query("INSERT INTO sale_items (sale_id, product_id, quantity, selling_price, company_id)
              VALUES ({$saleId}, {$productId}, 10, 15.00, {$companyId})");

$ctx = ['company_id' => $companyId, 'user_id' => 0, 'employee_id' => null, 'role' => 'admin'];

/* Sales */
$result = chatbotAnswer($conn, $ctx, 'magkano ang benta ngayong araw');
t_ok($result['ok'], 'sales today answered');
t_same('sales_today', $result['intent'], 'sales today intent');
t_ok(str_contains(json_encode($result['answer']), '150.00'), 'sales today shows the amount');

/* Inventory */
$result = chatbotAnswer($conn, $ctx, 'which products are low in stock');
t_ok($result['ok'], 'low stock answered');
t_ok(str_contains(json_encode($result['answer']), 'Lucky Me'),
    'low stock names the product below its reorder level');

$result = chatbotAnswer($conn, $ctx, 'anong produkto ang out of stock');
t_ok(str_contains(json_encode($result['answer']), 'Coke Mismo'), 'out of stock names the empty product');

/* Product lookups */
$result = chatbotAnswer($conn, $ctx, 'magkano ang lucky me');
t_ok($result['ok'], 'product price answered');
t_ok(str_contains(json_encode($result['answer']), '15.00'), 'product price shows the price');

/* Review Focus 4: a product that does not exist, and an ambiguous one */
$result = chatbotAnswer($conn, $ctx, 'magkano ang wala talaga nito');
t_ok($result['ok'], 'unknown product still returns an answer card, not an error');
t_ok(str_contains(mb_strtolower(json_encode($result['answer'])), 'wala'),
    'unknown product says nothing was found');
t_same([], $result['answer']['lines'], 'unknown product shows no figures');

$conn->query("INSERT INTO products (product_name, category_id, company_id)
              VALUES ('Lucky Me Beef', {$categoryId}, {$companyId})");
$secondId = (int) $conn->insert_id;
$conn->query("INSERT INTO inventory (product_id, quantity, purchase_cost, selling_price, reorder_level, company_id)
              VALUES ({$secondId}, 7, 11.00, 16.00, 5, {$companyId})");

$result = chatbotAnswer($conn, $ctx, 'magkano ang lucky me');
t_ok(count($result['answer']['table']['rows'] ?? []) >= 2,
    'an ambiguous product name lists every match instead of guessing one');

/* Staff */
$result = chatbotAnswer($conn, $ctx, 'how many employees do we have');
t_ok($result['ok'], 'staff count answered');

/* Refusals */
$cashierCtx = ['company_id' => $companyId, 'user_id' => 0, 'employee_id' => null, 'role' => 'cashier'];
$result = chatbotAnswer($conn, $cashierCtx, 'magkano ang benta ngayong araw');
t_ok(!$result['ok'], 'cashier is refused the company sales question');
t_ok($result['suggestions'] !== [], 'a refusal still offers what the cashier can ask');
t_ok(!str_contains(json_encode($result), '150.00'), 'a refusal leaks no figures');

$result = chatbotAnswer($conn, $ctx, 'what is the weather tomorrow');
t_same('no_match', $result['reason'], 'an unrelated question is a no_match');

t_done();
