<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/factory.php';
require_once __DIR__ . '/../../includes/chatbot/engine.php';

register_shutdown_function(function () use ($conn) { testCleanup($conn); });

$companyId = testMakeCompany($conn, 'Advisory Admin Co', 2);
$ctx = ['company_id' => $companyId, 'user_id' => 1, 'employee_id' => null, 'role' => 'admin'];

function adminAdvice(mysqli $conn, array $ctx, string $id): ?array
{
    foreach (chatbotRunAdvisories($conn, $ctx) as $entry) {
        if ($entry['id'] === $id) {
            return $entry;
        }
    }

    return null;
}

/* A quiet company is told nothing. */
$ids = array_column(chatbotRunAdvisories($conn, $ctx), 'id');

foreach (['approvals_waiting', 'stock_low', 'capital_low', 'subscription_due'] as $quiet) {
    t_ok(!in_array($quiet, $ids, true), "a quiet company raises no {$quiet}");
}

/* A stock request waiting on the owner. */
$conn->query("INSERT INTO stock_requests (request_code, total_price, reason, status, company_id)
              VALUES ('SR-ADMIN', 7500.00, 'restock', 'Pending Admin', {$companyId})");

$advice = adminAdvice($conn, $ctx, 'approvals_waiting');
t_ok($advice !== null, 'the owner is told an approval is waiting');
t_ok(str_contains(json_encode($advice['evidence']), '7,500.00'), 'with the amount at stake');
t_ok($advice['link'] !== null, 'and a link to the page that does it');

/* Stock at and below the reorder level, and stock at zero. */
$conn->query("INSERT INTO categories (category_name, company_id) VALUES ('Noodles', {$companyId})");
$categoryId = (int) $conn->insert_id;

$conn->query("INSERT INTO products (product_name, category_id, company_id)
              VALUES ('Lucky Me', {$categoryId}, {$companyId})");
$lowId = (int) $conn->insert_id;
$conn->query("INSERT INTO inventory (product_id, quantity, purchase_cost, selling_price, reorder_level, company_id)
              VALUES ({$lowId}, 3, 9.00, 15.00, 5, {$companyId})");

$conn->query("INSERT INTO products (product_name, category_id, company_id)
              VALUES ('Coke Mismo', {$categoryId}, {$companyId})");
$outId = (int) $conn->insert_id;
$conn->query("INSERT INTO inventory (product_id, quantity, purchase_cost, selling_price, reorder_level, company_id)
              VALUES ({$outId}, 0, 15.00, 20.00, 5, {$companyId})");

$conn->query("INSERT INTO products (product_name, category_id, company_id)
              VALUES ('Bear Brand', {$categoryId}, {$companyId})");
$fineId = (int) $conn->insert_id;
$conn->query("INSERT INTO inventory (product_id, quantity, purchase_cost, selling_price, reorder_level, company_id)
              VALUES ({$fineId}, 80, 30.00, 40.00, 5, {$companyId})");

$advice = adminAdvice($conn, $ctx, 'stock_low');
t_ok($advice !== null, 'the owner is told stock is running out');
$evidence = json_encode($advice['evidence']);
t_ok(str_contains($evidence, '"1"'), 'one low and one out, never the healthy one');
t_ok(str_contains($advice['message'], '2'), 'two products need restocking');

/* Capital above the warning level says nothing; capital below it speaks. */
$conn->query("INSERT INTO finance_capital (current_capital, company_id)
              VALUES (50000.00, {$companyId})");
t_same(null, adminAdvice($conn, $ctx, 'capital_low'),
    'healthy capital raises nothing');

$conn->query("UPDATE finance_capital SET current_capital = 400.00 WHERE company_id = {$companyId}");
$advice = adminAdvice($conn, $ctx, 'capital_low');
t_ok($advice !== null, 'low capital is raised');
t_ok(str_contains(json_encode($advice['evidence']), '400.00'), 'with the figure');

/* A subscription a year out is not due; one next week is. */
t_same(null, adminAdvice($conn, $ctx, 'subscription_due'),
    'a subscription a year out is not due');

$conn->query("UPDATE company_subscriptions
              SET expiry_date = DATE_ADD(CURDATE(), INTERVAL 5 DAY)
              WHERE company_id = {$companyId}");
$advice = adminAdvice($conn, $ctx, 'subscription_due');
t_ok($advice !== null, 'a subscription five days out is raised');
t_ok(str_contains(json_encode($advice['evidence']), '5'), 'with the days left');

/* None of this reaches a cashier, and none of it reaches another company. */
$cashierCtx = ['company_id' => $companyId, 'user_id' => 2, 'employee_id' => null, 'role' => 'cashier'];
$ids = array_column(chatbotRunAdvisories($conn, $cashierCtx), 'id');

foreach (['approvals_waiting', 'capital_low', 'subscription_due'] as $forbidden) {
    t_ok(!in_array($forbidden, $ids, true), "a cashier is never shown {$forbidden}");
}

$otherId = testMakeCompany($conn, 'Advisory Admin Neighbour', 2);
$otherCtx = ['company_id' => $otherId, 'user_id' => 3, 'employee_id' => null, 'role' => 'admin'];
$ids = array_column(chatbotRunAdvisories($conn, $otherCtx), 'id');

foreach (['approvals_waiting', 'stock_low', 'capital_low'] as $mine) {
    t_ok(!in_array($mine, $ids, true), "the neighbouring company never sees my {$mine}");
}

t_done();
