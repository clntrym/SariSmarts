<?php
/*
| The assistant is only useful where it is drawn. Phase 1 put it in three
| headers; Phase 2 gives three more roles questions to ask.
*/
require_once __DIR__ . '/bootstrap.php';

$headers = [
    'admin/admin_header.php',
    'cashier/cashier_header.php',
    'employee/employee_header.php',
    'hr/hr_header.php',
    'finance/finance_header.php',
    'inventory/inventory_header.php',
];

foreach ($headers as $header) {
    $source = file_get_contents(__DIR__ . '/../../' . $header);

    t_ok(str_contains($source, 'chatbot/widget.php'),
        "{$header} includes the assistant");
}

t_done();
