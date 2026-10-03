<?php
/*
| A question about pay must be answered by saying there is no tool for it.
|
| It was being answered by the product lookup -- "how much is the salary of Ana
| Cruz" shares "how much is" with the price question -- so the owner's one
| excluded subject came back as a confusing product card instead of a plain
| "I cannot look that up". The conversational layer already says it plainly;
| the keyword path must agree.
*/
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/factory.php';
require_once __DIR__ . '/../../includes/chatbot/engine.php';

register_shutdown_function(function () use ($conn) { testCleanup($conn); });

$companyId = testMakeCompany($conn, 'Salary Question Co', 2);

$conn->query("INSERT INTO categories (category_name, company_id) VALUES ('Cat', {$companyId})");
$categoryId = (int) $conn->insert_id;
$conn->query("INSERT INTO products (product_name, category_id, company_id)
              VALUES ('Lucky Me', {$categoryId}, {$companyId})");
$productId = (int) $conn->insert_id;
$conn->query("INSERT INTO inventory (product_id, quantity, purchase_cost, selling_price, reorder_level, company_id)
              VALUES ({$productId}, 5, 10.00, 15.00, 5, {$companyId})");

/*
| An individual's pay: nothing in this system answers it, for anybody. The
| payroll RUN is a different question -- Finance and the owner have answers for
| that (see test_finance_intents.php), and the roles below do not.
*/
$questions = [
    'how much is the salary of Ana Cruz',
    'magkano ang sweldo ni Ana',
    'what is my salary',
    'how much is the pay of the cashier',
];

foreach (['admin', 'cashier', 'employee'] as $role) {

    $ctx = ['company_id' => $companyId, 'user_id' => 1, 'employee_id' => 1, 'role' => $role];

    foreach ($questions as $question) {
        $result = chatbotAnswer($conn, $ctx, $question);

        t_same('salary_not_available', $result['intent'],
            "{$role}: \"{$question}\" is answered as a pay question");

        $text = mb_strtolower(json_encode($result['answer']));
        t_ok(str_contains($text, 'salaries') || str_contains($text, 'payroll'),
            "{$role}: and the answer says so plainly");
        t_ok(!str_contains($text, 'lucky me'),
            "{$role}: it is not turned into a product lookup");
    }
}

/*
| The payroll run: answered for the roles the matrix gives it, refused for the
| rest -- and refused with the pay message, which names the subject, rather
| than the generic "I cannot answer that".
*/
foreach (['admin', 'finance', 'hr'] as $role) {
    $ctx = ['company_id' => $companyId, 'user_id' => 1, 'employee_id' => 1, 'role' => $role];
    $result = chatbotAnswer($conn, $ctx, 'show me the payroll for this month');

    t_same('finance_payroll_total', $result['intent'],
        "{$role}: the payroll run is answered, not refused");
}

foreach (['cashier', 'employee', 'inventory'] as $role) {
    $ctx = ['company_id' => $companyId, 'user_id' => 1, 'employee_id' => 1, 'role' => $role];
    $result = chatbotAnswer($conn, $ctx, 'show me the payroll for this month');

    t_same('salary_not_available', $result['intent'],
        "{$role}: has no payroll answer and hears why");
}

/* The ordinary price question is untouched. */
$ctx = ['company_id' => $companyId, 'user_id' => 1, 'employee_id' => 1, 'role' => 'cashier'];

$result = chatbotAnswer($conn, $ctx, 'how much is lucky me');
t_same('product_price', $result['intent'], 'a real price question still reaches the price answer');
t_ok(str_contains(json_encode($result['answer']), '15.00'), 'with the price');

$result = chatbotAnswer($conn, $ctx, 'magkano ang lucky me');
t_same('product_price', $result['intent'], 'and so does the Tagalog form');

/* It is an answer, not a suggestion: nobody needs a button for what the
   assistant cannot do. */
foreach (['admin', 'cashier', 'employee'] as $role) {
    $allowed = chatbotAllowedIntents($conn, $companyId, $role);
    $suggestions = chatbotSuggestions($allowed);

    foreach ($suggestions as $suggestion) {
        t_ok($suggestion['id'] !== 'salary_not_available',
            "{$role} is not offered a chip for the pay refusal");
    }
}

t_done();
