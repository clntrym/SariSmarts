<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/factory.php';
require_once __DIR__ . '/../../includes/chatbot/engine.php';

register_shutdown_function(function () use ($conn) { testCleanup($conn); });

$companyId = testMakeCompany($conn, 'Finance Intents Co', 2);
$otherId = testMakeCompany($conn, 'Finance Intents Other', 2);
$ctx = ['company_id' => $companyId, 'user_id' => 1, 'employee_id' => null, 'role' => 'finance'];

$conn->query("INSERT INTO employees (company_id, first_name, last_name, employment_status)
              VALUES ({$companyId}, 'Ana', 'Cruz', 'Official Employee')");
$ana = (int) $conn->insert_id;

$conn->query("INSERT INTO employees (company_id, first_name, last_name, employment_status)
              VALUES ({$otherId}, 'Outside', 'Person', 'Official Employee')");
$outsider = (int) $conn->insert_id;

$conn->query("INSERT INTO payroll (employee_id, payroll_period_start, payroll_period_end, working_days,
                                   basic_pay, overtime_pay, gross_pay, late_deduction, undertime_deduction,
                                   absent_deduction, sss, philhealth, pagibig, total_deduction, net_pay,
                                   status, company_id)
              VALUES ({$ana}, DATE_FORMAT(CURDATE(), '%Y-%m-01'), LAST_DAY(CURDATE()), 22,
                      12000, 0, 12000, 0, 0, 0, 500, 300, 200, 1000, 11000,
                      'Pending Approval', {$companyId})");

$conn->query("INSERT INTO payroll (employee_id, payroll_period_start, payroll_period_end, working_days,
                                   basic_pay, overtime_pay, gross_pay, late_deduction, undertime_deduction,
                                   absent_deduction, sss, philhealth, pagibig, total_deduction, net_pay,
                                   status, company_id)
              VALUES ({$outsider}, DATE_FORMAT(CURDATE(), '%Y-%m-01'), LAST_DAY(CURDATE()), 22,
                      99999, 0, 99999, 0, 0, 0, 0, 0, 0, 0, 99999,
                      'Pending Approval', {$otherId})");

$conn->query("INSERT INTO expenses (expense_code, expense_date, category, vendor, description, amount, payment_method, company_id)
              VALUES ('EXP-T1', CURDATE(), 'Utilities', 'Meralco', 'Electricity', 2500.00, 'Cash', {$companyId})");

$conn->query("INSERT INTO stock_requests (request_code, total_price, reason, status, company_id)
              VALUES ('SR-FIN-1', 3000.00, 'restock', 'Pending Finance', {$companyId})");
$requestId = (int) $conn->insert_id;

/* A payable is born from a stock request in this app: accounts_payable carries
   foreign keys to the request and to the line item, so the test seeds both. */
$conn->query("INSERT INTO stock_request_items (request_id, item_description, vendor, quantity, unit_price, total_price, company_id)
              VALUES ({$requestId}, 'Sacks of rice', 'Supplier A', 10, 500.00, 5000.00, {$companyId})");
$itemId = (int) $conn->insert_id;

$conn->query("INSERT INTO accounts_payable (request_id, item_id, invoice_no, po_number, supplier,
                                            category, description, amount, paid_amount, due_date, status, company_id)
              VALUES ({$requestId}, {$itemId}, 'INV-T1', 'PO-T1', 'Supplier A',
                      'Stock', 'Delivery', 5000.00, 1000.00,
                      DATE_ADD(CURDATE(), INTERVAL 7 DAY), 'Partial', {$companyId})");

$cases = [
    'how much is the payroll this month' => 'finance_payroll_total',
    'magkano ang payroll ngayong buwan' => 'finance_payroll_total',
    'which payrolls are pending approval' => 'finance_payroll_pending',
    'show this month expenses' => 'finance_expenses',
    'magkano ang gastos ngayong buwan' => 'finance_expenses',
    'what do we owe our suppliers' => 'finance_payables',
    'which stock requests need finance approval' => 'finance_stock_requests',
];

foreach ($cases as $question => $intent) {
    $result = chatbotAnswer($conn, $ctx, $question);
    t_same($intent, $result['intent'], "finance: \"{$question}\"");
    t_ok($result['ok'], "finance: \"{$question}\" is answered");
}

/* Payroll figures are this company's own. */
$json = json_encode(chatbotAnswer($conn, $ctx, 'how much is the payroll this month')['answer']);
t_ok(str_contains($json, '11,000.00'), 'the net pay total is ours');
t_ok(!str_contains($json, '99,999'), "and never the other company's");

/* The two payroll intents never go to the model. */
t_ok(!empty(chatbotIntents()['finance_payroll_total']['local_only']),
    'the payroll total is marked local_only');
t_ok(!empty(chatbotIntents()['finance_payroll_pending']['local_only']),
    'so is the pending payroll list');

foreach (chatbotIntents() as $id => $intent) {
    if (in_array($intent['topic'], ['pos', 'inventory'], true)) {
        t_ok(empty($intent['local_only']), "{$id} does not need to be local_only");
    }
}

/* Review Focus 3: Finance may not read recruitment detail. */
foreach (['how many applicants do we have', 'what job postings are open',
          'which products are low in stock', 'magkano ang benta ngayong araw'] as $question) {
    $result = chatbotAnswer($conn, $ctx, $question);
    t_ok(!$result['ok'], "finance is refused: \"{$question}\"");
}

t_done();
