<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/factory.php';
require_once __DIR__ . '/../../includes/chatbot/engine.php';
require_once __DIR__ . '/../../includes/chatbot/chat/tool_runner.php';

register_shutdown_function(function () use ($conn) { testCleanup($conn); });

$companyId = testMakeCompany($conn, 'Inv Tools Co', 2);
$ctx = ['company_id' => $companyId, 'user_id' => 1, 'employee_id' => null, 'role' => 'admin'];
$cashierCtx = ['company_id' => $companyId, 'user_id' => 1, 'employee_id' => null, 'role' => 'cashier'];

$conn->query("INSERT INTO categories (category_name, company_id) VALUES ('Noodles', {$companyId})");
$categoryId = (int) $conn->insert_id;

$conn->query("INSERT INTO suppliers (supplier_name, contact_email, company_id)
              VALUES ('Secret Supplier', 'sup@test.local', {$companyId})");
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

/* stock_list */
$result = chatRunTool($conn, $ctx, 'stock_list', ['state' => 'low']);
t_ok($result['ok'], 'stock_list runs');
t_same('Lucky Me', $result['rows'][0][0], 'low lists the product below its reorder level');

$result = chatRunTool($conn, $ctx, 'stock_list', ['state' => 'out']);
t_same('Coke Mismo', $result['rows'][0][0], 'out lists the empty product');

/* The cashier sees quantity only -- no cost, no supplier. */
$cashierResult = chatRunTool($conn, $cashierCtx, 'stock_list', ['state' => 'all']);
t_ok($cashierResult['ok'], 'the cashier may run stock_list');
t_ok(!in_array('purchase_cost', $cashierResult['columns'], true),
    'the cashier is not given the purchase cost');
t_ok(!str_contains(json_encode($cashierResult), '9.00'), 'and the cost is not in the rows');

$adminResult = chatRunTool($conn, $ctx, 'stock_list', ['state' => 'all']);
t_ok(in_array('purchase_cost', $adminResult['columns'], true),
    'the admin does get the purchase cost');

/* product_lookup */
$result = chatRunTool($conn, $ctx, 'product_lookup', ['name' => 'lucky']);
t_same('Lucky Me', $result['rows'][0][0], 'product_lookup finds by partial name');

$result = chatRunTool($conn, $ctx, 'product_lookup', ['name' => 'no such thing']);
t_ok($result['ok'], 'a product that does not exist is not an error');
t_same([], $result['rows'], 'it is simply no rows');

$cashierLookup = chatRunTool($conn, $cashierCtx, 'product_lookup', ['name' => 'lucky']);
t_ok(!str_contains(json_encode($cashierLookup), 'Secret Supplier'),
    'the cashier lookup does not reveal the supplier');

/* Review Focus 3: more rows than the cap */
for ($i = 0; $i < 60; $i++) {
    $conn->query("INSERT INTO products (product_name, category_id, company_id)
                  VALUES ('Bulk Item {$i}', {$categoryId}, {$companyId})");
    $bulkId = (int) $conn->insert_id;
    $conn->query("INSERT INTO inventory (product_id, quantity, purchase_cost, selling_price, reorder_level, company_id)
                  VALUES ({$bulkId}, 1, 1.00, 2.00, 5, {$companyId})");
}

/* Checked at several limits, not only at 50: an earlier version fetched
   cap + 1 and compared against the cap, so truncation was invisible for every
   smaller limit and the extra row was handed to the model. */
foreach ([5, 25, 49, 50] as $limit) {
    $result = chatRunTool($conn, $ctx, 'stock_list', ['state' => 'low', 'limit' => $limit]);

    t_same($limit, count($result['rows']), "limit {$limit} returns exactly that many rows");
    t_ok($result['truncated'], "limit {$limit} is marked truncated when more matched");
}

$result = chatRunTool($conn, $ctx, 'stock_list', ['state' => 'low']);
t_same(CHAT_TOOL_ROW_CAP, count($result['rows']), 'with no limit the cap applies');
t_ok($result['truncated'],
    'and is marked truncated, so the model cannot report it as the whole list');

t_done();
