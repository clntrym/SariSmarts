<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/factory.php';
require_once __DIR__ . '/../../includes/chatbot/intents.php';
require_once __DIR__ . '/../../includes/chatbot/engine.php';

register_shutdown_function(function () use ($conn) { testCleanup($conn); });

$starter = testMakeCompany($conn, 'Gates Starter', 1);
$professional = testMakeCompany($conn, 'Gates Professional', 2);

/* Plan gate */
t_ok(chatbotPlanAllowsTopic($conn, $starter, 'pos'), 'Starter allows pos');
t_ok(!chatbotPlanAllowsTopic($conn, $starter, 'payroll'), 'Starter refuses payroll');
t_ok(chatbotPlanAllowsTopic($conn, $professional, 'payroll'), 'Professional allows payroll');
t_ok(!chatbotPlanAllowsTopic($conn, $professional, 'cross_branch'),
    'Professional refuses cross_branch');

/* Review Focus 1: an unseeded topic must CLOSE, not open */
t_ok(!chatbotPlanAllowsTopic($conn, $professional, 'inventoy'),
    'a mistyped topic is denied, not allowed by default');
t_ok(!chatbotPlanAllowsTopic($conn, $professional, ''),
    'an empty topic is denied');

/* Every topic the catalog names must actually be seeded, or an intent would
   be unreachable for everyone -- the other half of the same mistake. */
$seeded = [];
$result = $conn->query("SELECT DISTINCT topic FROM chatbot_topic_plans");

while ($row = $result->fetch_assoc()) {
    $seeded[] = $row['topic'];
}

foreach (chatbotIntents() as $id => $intent) {
    t_ok(in_array($intent['topic'], $seeded, true),
        "topic '{$intent['topic']}' used by {$id} is seeded");
}

/* Role gate */
$adminIntents = chatbotAllowedIntents($conn, $professional, 'admin');
$cashierIntents = chatbotAllowedIntents($conn, $professional, 'cashier');
$employeeIntents = chatbotAllowedIntents($conn, $professional, 'employee');

t_ok(isset($adminIntents['sales_today']), 'admin may ask company sales');
t_ok(!isset($cashierIntents['sales_today']), 'cashier may NOT ask company sales');
t_ok(isset($cashierIntents['my_sales_today']), 'cashier may ask their own sales');
t_ok(!isset($adminIntents['my_sales_today']), 'admin does not get the cashier intent');
t_ok(!isset($employeeIntents['low_stock']), 'employee may not ask about stock');
t_ok(isset($employeeIntents['my_attendance_today']), 'employee may ask their own attendance');

/* Plan gate applied through the filter */
$starterAdmin = chatbotAllowedIntents($conn, $starter, 'admin');
t_ok(isset($starterAdmin['sales_today']), 'Starter admin keeps POS questions');
t_ok(isset($starterAdmin['low_stock']), 'Starter admin keeps inventory questions');

/* Suggestions come from the filtered list, so they can never offer a refusal */
$suggestions = chatbotSuggestions($cashierIntents);
t_ok($suggestions !== [], 'cashier gets suggestions');

foreach ($suggestions as $suggestion) {
    t_ok(isset($cashierIntents[$suggestion['id']]),
        "suggestion {$suggestion['id']} is one the cashier may ask");
    t_ok($suggestion['label'] !== '', 'suggestion carries a label');
}

t_done();
