<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/factory.php';
require_once __DIR__ . '/../../includes/chatbot/engine.php';

register_shutdown_function(function () use ($conn) { testCleanup($conn); });

$companyId = testMakeCompany($conn, 'Inv Intents Co', 2);
$ctx = ['company_id' => $companyId, 'user_id' => 1, 'employee_id' => null, 'role' => 'inventory'];

$conn->query("INSERT INTO categories (category_name, company_id) VALUES ('Noodles', {$companyId})");
$categoryId = (int) $conn->insert_id;

$conn->query("INSERT INTO suppliers (supplier_name, contact_email, company_id)
              VALUES ('Best Supplier', 'best@test.local', {$companyId})");
$supplierId = (int) $conn->insert_id;

$conn->query("INSERT INTO products (product_name, category_id, supplier_id, company_id)
              VALUES ('Lucky Me', {$categoryId}, {$supplierId}, {$companyId})");
$low = (int) $conn->insert_id;
$conn->query("INSERT INTO inventory (product_id, quantity, purchase_cost, selling_price, reorder_level, company_id)
              VALUES ({$low}, 2, 9.00, 15.00, 5, {$companyId})");

$conn->query("INSERT INTO products (product_name, category_id, company_id)
              VALUES ('Coke Mismo', {$categoryId}, {$companyId})");
$out = (int) $conn->insert_id;
$conn->query("INSERT INTO inventory (product_id, quantity, purchase_cost, selling_price, reorder_level, company_id)
              VALUES ({$out}, 0, 15.00, 20.00, 5, {$companyId})");

$conn->query("INSERT INTO stock_requests (request_code, total_price, reason, status, company_id)
              VALUES ('SR-INV-1', 1200.00, 'restock', 'Pending Finance', {$companyId})");

$cases = [
    'which products are low in stock' => 'inv_low_stock',
    'anong produkto ang mababa ang stock' => 'inv_low_stock',
    'what products are out of stock' => 'inv_out_of_stock',
    'show pending stock requests' => 'inv_stock_requests',
    'who are our suppliers' => 'inv_suppliers',
];

foreach ($cases as $question => $intent) {
    $result = chatbotAnswer($conn, $ctx, $question);
    t_same($intent, $result['intent'], "inventory: \"{$question}\"");
    t_ok($result['ok'], "inventory: \"{$question}\" is answered");
}

$json = json_encode(chatbotAnswer($conn, $ctx, 'which products are low in stock')['answer']);
t_ok(str_contains($json, 'Lucky Me'), 'the low product is named');
t_ok(str_contains($json, 'Best Supplier'), 'with the supplier to reorder from');

/* Review Focus 4: their matrix says view only, and no pay, no POS. */
$json = json_encode(chatbotAnswer($conn, $ctx, 'who are our suppliers')['answer']);
t_ok(!str_contains(mb_strtolower($json), 'delete'), 'the supplier answer offers no action');

foreach (['magkano ang benta ngayong araw', 'how many employees do we have',
          'show this month expenses'] as $question) {
    $result = chatbotAnswer($conn, $ctx, $question);
    t_ok(!$result['ok'], "inventory is refused: \"{$question}\"");
}

$result = chatbotAnswer($conn, $ctx, 'how much is the salary of Ana');
t_same('salary_not_available', $result['intent'], 'inventory asking about salary gets the plain answer');

t_done();
