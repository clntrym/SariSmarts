<?php
/*
| The chips are the first thing a user touches: the panel opens showing them.
|
| Spec section 3 promises the assistant can never suggest a question it would
| then refuse. Nothing proved that, and three of eight chips were refused or
| answered nonsense. This round-trips every chip label back through the engine,
| for every role.
*/
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/factory.php';
require_once __DIR__ . '/../../includes/chatbot/engine.php';

register_shutdown_function(function () use ($conn) { testCleanup($conn); });

$companyId = testMakeCompany($conn, 'Chips Co', 2);

/* Something for the product questions to find. */
$conn->query("INSERT INTO categories (category_name, company_id) VALUES ('Cat', {$companyId})");
$categoryId = (int) $conn->insert_id;
$conn->query("INSERT INTO products (product_name, category_id, company_id)
              VALUES ('Lucky Me Pancit Canton', {$categoryId}, {$companyId})");
$productId = (int) $conn->insert_id;
$conn->query("INSERT INTO inventory (product_id, quantity, purchase_cost, selling_price, reorder_level, company_id)
              VALUES ({$productId}, 3, 10.00, 15.00, 5, {$companyId})");

$conn->query("INSERT INTO employees (company_id, first_name, last_name, employment_status)
              VALUES ({$companyId}, 'Ana', 'Cruz', 'Regular')");
$employeeId = (int) $conn->insert_id;

foreach (['admin', 'cashier', 'employee'] as $role) {

    $allowed = chatbotAllowedIntents($conn, $companyId, $role);
    $suggestions = chatbotSuggestions($allowed);

    t_ok($suggestions !== [], "{$role} is offered chips");

    foreach ($suggestions as $suggestion) {

        /* A chip that needs the user to name a product is a prompt, not a
           question: it fills the box instead of being sent. */
        if (!empty($suggestion['needs_input'])) {
            t_ok(isset($allowed[$suggestion['id']]),
                "{$role}: chip '{$suggestion['label']}' asks for a product name");
            continue;
        }

        $ctx = ['company_id' => $companyId, 'user_id' => 1,
                'employee_id' => $employeeId, 'role' => $role];

        $result = chatbotAnswer($conn, $ctx, $suggestion['label']);

        t_ok($result['ok'],
            "{$role}: chip '{$suggestion['label']}' is answered, not refused"
            . ($result['ok'] ? '' : ' (' . $result['reason'] . ')'));
        t_same($suggestion['id'], $result['intent'],
            "{$role}: chip '{$suggestion['label']}' reaches its own intent");
    }
}

/*
| The Cashier's headline question from spec section 6, in the Tagalog a
| cashier actually types. "benta ... araw" also matches the admin-only company
| sales intent, which was refusing the cashier their own figures.
*/
$cashierCtx = ['company_id' => $companyId, 'user_id' => 1,
               'employee_id' => $employeeId, 'role' => 'cashier'];

foreach ([
    'magkano ang benta ko ngayong araw',
    'Benta ko ngayong araw',
    'ilan ang nabenta ko ngayong araw',
    'how much have I sold today',
] as $question) {
    $result = chatbotAnswer($conn, $cashierCtx, $question);
    t_same('my_sales_today', $result['intent'], "cashier: \"{$question}\" is their own sales");
}

/* Without "ko", it is the company's sales -- which the cashier may not ask. */
$result = chatbotAnswer($conn, $cashierCtx, 'magkano ang benta ngayong araw');
t_ok(!$result['ok'], 'cashier: company-wide sales is still refused');

/* And the admin is unaffected. */
$adminCtx = ['company_id' => $companyId, 'user_id' => 1,
             'employee_id' => null, 'role' => 'admin'];
t_same('sales_today', chatbotAnswer($conn, $adminCtx, 'magkano ang benta ngayong araw')['intent'],
    'admin: company sales still reaches sales_today');

/* The documented Cashier stock question, in its natural Tagalog form. */
$result = chatbotAnswer($conn, $cashierCtx, 'may stock pa ba ang lucky me');
t_ok($result['ok'], 'cashier: "may stock pa ba ang lucky me" is answered');
t_ok(str_contains(json_encode($result['answer']), 'Lucky Me'), 'and it finds the product');

t_done();
