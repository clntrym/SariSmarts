<?php
/*
| English is the assistant's default language.
|
| Input stays bilingual on purpose: staff type Tagalog, and the keyword
| synonyms still carry it. What changes is everything the assistant SAYS --
| labels, titles, notes, the widget chrome, the endpoint's messages, and the
| instruction the model is given.
*/
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/factory.php';
require_once __DIR__ . '/../../includes/chatbot/engine.php';
require_once __DIR__ . '/../../includes/chatbot/chat/api.php';

register_shutdown_function(function () use ($conn) { testCleanup($conn); });

/* Every button the user sees. */
$expected = [
    'sales_today' => 'Sales today',
    'sales_month' => 'Sales this month',
    'top_products_month' => 'Top selling products',
    'low_stock' => 'Low stock',
    'out_of_stock' => 'Out of stock',
    'product_price' => 'Price of product',
    'product_stock' => 'Stock of product',
    'staff_count' => 'Employee count',
    'my_sales_today' => 'My sales today',
    'my_attendance_today' => 'My attendance today',
    'my_leave_status' => 'My leave status',
];

foreach ($expected as $id => $label) {
    t_same($label, chatbotIntents()[$id]['label'] ?? null, "{$id} is labelled in English");
}

/* Tagalog input must still reach the right answer -- this is a change to what
   the assistant says, not to what it understands. */
$companyId = testMakeCompany($conn, 'English Co', 2);
$ctx = ['company_id' => $companyId, 'user_id' => 1, 'employee_id' => null, 'role' => 'admin'];

$conn->query("INSERT INTO sales (company_id, total_amount, tax_amount, cash_received, change_amount, payment_method)
              VALUES ({$companyId}, 250.00, 0.00, 250.00, 0.00, 'Cash')");

foreach (['magkano ang benta ngayong araw', 'what are our sales today'] as $question) {
    $result = chatbotAnswer($conn, $ctx, $question);
    t_same('sales_today', $result['intent'], "\"{$question}\" still reaches the answer");
}

/* And the answer itself is English. */
$answer = chatbotAnswer($conn, $ctx, 'magkano ang benta ngayong araw')['answer'];
t_same('Sales today', $answer['title'], 'the answer card is titled in English');
t_same('Total', $answer['lines'][0][0], 'and so are its labels');
t_same('Transactions', $answer['lines'][1][0], 'all of them');

$answer = chatbotAnswer($conn, $ctx, 'which products are low in stock')['answer'];
t_same('Low stock', $answer['title'], 'low stock is titled in English');
t_ok(str_contains((string) $answer['note'], 'No product'), 'and its empty note is English too');

$answer = chatbotAnswer($conn, $ctx, 'magkano ang wala talaga nito')['answer'];
t_ok(str_contains((string) $answer['note'], 'No product'),
    'the not-found note is English');

/* The model is told which language to answer in. */
$prompt = chatSystemPrompt();
t_ok(str_contains($prompt, 'English'), 'the system prompt names English');
t_ok(str_contains(strtolower($prompt), 'default'), 'as the default');

/* No Tagalog left in what the user reads. */
$tagalog = ['Magtanong', 'Hindi ko kayang', 'Mag-log in muli', 'Pakisubukan',
            'Wala akong', 'ngayong araw', 'Ang dami mong', 'Narito ang mga',
            'Pakirefresh', 'Nakatali ito', 'Hindi aktibo', 'May problema',
            'Ano ang gusto', 'May iba pang'];

/*
| intents.php is deliberately not scanned: its keyword groups carry Tagalog on
| purpose, because that is what the staff type. The labels -- the only part of
| that file the user reads -- are asserted one by one above.
*/
foreach (['includes/chatbot/widget.php', 'includes/chatbot/ask.php',
          'includes/chatbot/answers/pos.php',
          'includes/chatbot/answers/inventory.php', 'includes/chatbot/answers/staff.php',
          'includes/chatbot/answers/personal.php'] as $file) {

    $source = file_get_contents(__DIR__ . '/../../' . $file);

    foreach ($tagalog as $phrase) {
        t_ok(!str_contains($source, $phrase), "{$file} no longer says \"{$phrase}\"");
    }
}

t_done();
