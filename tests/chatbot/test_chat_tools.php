<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/factory.php';
require_once __DIR__ . '/../../includes/chatbot/engine.php';
require_once __DIR__ . '/../../includes/chatbot/chat/tools.php';
require_once __DIR__ . '/../../includes/chatbot/chat/tool_runner.php';

register_shutdown_function(function () use ($conn) { testCleanup($conn); });

$starter = testMakeCompany($conn, 'Tools Starter', 1);
$professional = testMakeCompany($conn, 'Tools Professional', 2);

/* Every entry is complete: the model is told about these, so a missing
   description or a malformed schema is a bug it cannot recover from. */
foreach (chatTools() as $name => $tool) {
    foreach (['topic', 'roles', 'scope', 'description', 'input', 'handler'] as $key) {
        t_ok(array_key_exists($key, $tool), "{$name} defines {$key}");
    }

    t_ok($tool['description'] !== '', "{$name} has a description for the model");
    t_ok(in_array($tool['scope'], ['company', 'own'], true), "{$name} has a valid scope");

    foreach ($tool['input'] as $param => $spec) {
        t_ok(isset($spec['type']), "{$name}.{$param} declares a type");
        t_ok(in_array($spec['type'], ['enum', 'date', 'string', 'int'], true),
            "{$name}.{$param} has a known type");

        if ($spec['type'] === 'enum') {
            t_ok(!empty($spec['values']), "{$name}.{$param} lists its allowed values");
        }
    }
}

/* Role gate */
$adminTools = chatToolsFor($conn, ['company_id' => $professional, 'user_id' => 1,
                                   'employee_id' => null, 'role' => 'admin']);
$cashierTools = chatToolsFor($conn, ['company_id' => $professional, 'user_id' => 1,
                                     'employee_id' => null, 'role' => 'cashier']);

t_ok(isset($adminTools['sales_summary']), 'admin gets the sales tool');
t_ok(!isset($cashierTools['sales_summary']), 'cashier does NOT get the sales tool');
t_ok(isset($cashierTools['product_lookup']), 'cashier gets the product lookup');
t_ok(isset($cashierTools['stock_list']), 'cashier gets the stock list');
t_ok(isset($cashierTools['my_attendance']), 'and their own attendance');
t_ok(isset($cashierTools['my_leave']), 'and their own leave');
t_same(['stock_list', 'product_lookup', 'my_attendance', 'my_leave'],
    array_keys($cashierTools), 'and nothing else');

/* Plan gate: Starter bought pos, inventory, staff and reports. */
$starterAdmin = chatToolsFor($conn, ['company_id' => $starter, 'user_id' => 1,
                                     'employee_id' => null, 'role' => 'admin']);
t_ok(isset($starterAdmin['sales_summary']), 'Starter admin keeps the sales tool');
t_ok(isset($starterAdmin['stock_list']), 'Starter admin keeps the stock tool');

/* Schema export: what the model actually receives. */
$schemas = chatToolSchemas($cashierTools);

t_same(count($cashierTools), count($schemas),
    'the model is told about exactly the allowed tools');

foreach ($schemas as $schema) {
    t_ok(isset($schema['name'], $schema['description'], $schema['input_schema']),
        'each schema carries a name, a description and an input schema');
    t_same('object', $schema['input_schema']['type'], 'the input schema is an object');
    t_ok(isset($cashierTools[$schema['name']]), 'and names a tool this user may use');
}

/* Nothing in the catalog may name pay data -- the audit checks the files,
   this checks the shipped catalog. */
$catalogText = mb_strtolower(json_encode(chatTools()));

foreach (['payroll', 'salary', 'net_pay', 'deduction'] as $word) {
    t_ok(!str_contains($catalogText, $word), "the catalog never mentions {$word}");
}

t_done();
