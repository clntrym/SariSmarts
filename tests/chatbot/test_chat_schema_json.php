<?php
/*
| Tool schemas as the API will actually receive them.
|
| A tool with no inputs -- company_profile -- built an empty PHP array for its
| properties, and an empty PHP array serialises to [], not {}. The API wants an
| object there and rejects the whole request:
|
|   tools.8.custom.input_schema.properties: Input should be an object
|
| One bad schema fails every request that carries it, for every role that is
| offered that tool. Nothing in the unit tests saw it, because they compared
| PHP arrays and never looked at the JSON. This test looks at the JSON.
*/
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/factory.php';
require_once __DIR__ . '/../../includes/chatbot/engine.php';
require_once __DIR__ . '/../../includes/chatbot/chat/conversation.php';

register_shutdown_function(function () use ($conn) { testCleanup($conn); });

$companyId = testMakeCompany($conn, 'Schema Json Co', 3);

/* Every role, so no tool is missed because one role never sees it. */
foreach (['admin', 'hr', 'finance', 'inventory', 'cashier', 'employee'] as $role) {

    $ctx = ['company_id' => $companyId, 'user_id' => 1, 'employee_id' => 1, 'role' => $role];
    $schemas = chatToolSchemas(chatToolsFor($conn, $ctx));

    foreach ($schemas as $schema) {

        $name = $schema['name'];
        $json = json_decode((string) json_encode($schema));

        t_ok($json->input_schema->properties instanceof stdClass,
            "{$name}: properties is a JSON object, not an array");

        t_same('object', $json->input_schema->type, "{$name}: the schema is an object");
        t_ok(trim((string) $json->description) !== '', "{$name}: it describes itself");

        /* 'required' is a list, so it must stay an array even when empty --
           the opposite rule to properties, and just as easy to get wrong. */
        if (isset($json->input_schema->required)) {
            t_ok(is_array($json->input_schema->required),
                "{$name}: required is a JSON array");
        }
    }
}

/* And the specific tool that broke it, named so a regression is obvious. */
$ctx = ['company_id' => $companyId, 'user_id' => 1, 'employee_id' => 1, 'role' => 'admin'];
$schemas = chatToolSchemas(chatToolsFor($conn, $ctx));

$byName = [];

foreach ($schemas as $schema) {
    $byName[$schema['name']] = $schema;
}

t_ok(isset($byName['company_profile']), 'company_profile is offered to an owner');
t_ok(str_contains((string) json_encode($byName['company_profile']), '"properties":{}'),
    'a tool with no inputs sends properties as {}');

t_done();
