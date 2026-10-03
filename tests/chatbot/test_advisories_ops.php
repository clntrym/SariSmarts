<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/factory.php';
require_once __DIR__ . '/../../includes/chatbot/engine.php';

register_shutdown_function(function () use ($conn) { testCleanup($conn); });

$companyId = testMakeCompany($conn, 'Advisory Ops Co', 2);
$financeCtx = ['company_id' => $companyId, 'user_id' => 1, 'employee_id' => null, 'role' => 'finance'];
$invCtx = ['company_id' => $companyId, 'user_id' => 1, 'employee_id' => null, 'role' => 'inventory'];
$adminCtx = ['company_id' => $companyId, 'user_id' => 1, 'employee_id' => null, 'role' => 'admin'];

function opsAdvice(mysqli $conn, array $ctx, string $id): ?array
{
    foreach (chatbotRunAdvisories($conn, $ctx) as $entry) {
        if ($entry['id'] === $id) {
            return $entry;
        }
    }

    return null;
}

$conn->query("INSERT INTO employees (company_id, first_name, last_name, employment_status)
              VALUES ({$companyId}, 'Ana', 'Cruz', 'Official Employee')");
$ana = (int) $conn->insert_id;

/* Payroll waiting for Finance. */
$conn->query("INSERT INTO payroll (employee_id, payroll_period_start, payroll_period_end, working_days,
                                   basic_pay, overtime_pay, gross_pay, late_deduction, undertime_deduction,
                                   absent_deduction, sss, philhealth, pagibig, total_deduction, net_pay,
                                   status, company_id)
              VALUES ({$ana}, DATE_FORMAT(CURDATE(), '%Y-%m-01'), LAST_DAY(CURDATE()), 22,
                      9000, 0, 9000, 0, 0, 0, 0, 0, 0, 0, 9000, 'Pending Approval', {$companyId})");

$advice = opsAdvice($conn, $financeCtx, 'payroll_waiting');
t_ok($advice !== null, 'finance is told payroll is waiting');
t_ok(str_contains($advice['message'], '1'), 'and how many payslips');

/* A stock request for finance, and a payable falling due. */
$conn->query("INSERT INTO stock_requests (request_code, total_price, reason, status, company_id)
              VALUES ('SR-OPS', 2500.00, 'restock', 'Pending Finance', {$companyId})");
$requestId = (int) $conn->insert_id;

$conn->query("INSERT INTO stock_request_items (request_id, item_description, vendor, quantity, unit_price, total_price, company_id)
              VALUES ({$requestId}, 'Rice', 'V', 5, 500.00, 2500.00, {$companyId})");
$itemId = (int) $conn->insert_id;

$conn->query("INSERT INTO accounts_payable (request_id, item_id, invoice_no, po_number, supplier,
                                            category, description, amount, paid_amount, due_date, status, company_id)
              VALUES ({$requestId}, {$itemId}, 'INV-OPS', 'PO-OPS', 'Supplier A', 'Stock', 'd',
                      2500.00, 0.00, DATE_ADD(CURDATE(), INTERVAL 3 DAY), 'Pending', {$companyId})");

t_ok(opsAdvice($conn, $financeCtx, 'finance_requests') !== null,
    'finance is told a stock request needs them');

$advice = opsAdvice($conn, $financeCtx, 'payables_due');
t_ok($advice !== null, 'finance is told a bill falls due soon');
t_ok(str_contains(json_encode($advice['evidence']), '2,500.00'), 'with the amount');

/* A delivery the inventory staff can receive. */
$conn->query("INSERT INTO stock_requests (request_code, total_price, reason, status, company_id)
              VALUES ('SR-RECV', 1000.00, 'restock', 'Admin Approved', {$companyId})");

$advice = opsAdvice($conn, $invCtx, 'deliveries_ready');
t_ok($advice !== null, 'inventory is told a delivery is ready to receive');

/* And an approval waiting on the owner. */
$conn->query("INSERT INTO stock_requests (request_code, total_price, reason, status, company_id)
              VALUES ('SR-ADM', 4000.00, 'restock', 'Pending Admin', {$companyId})");

$advice = opsAdvice($conn, $adminCtx, 'approvals_waiting');
t_ok($advice !== null, 'the owner is told an approval is waiting');
t_ok(str_contains(json_encode($advice['evidence']), '4,000.00'), 'with the amount at stake');

/* Review Focus 3: the matrix holds. A cashier is told nothing about payroll. */
$cashierCtx = ['company_id' => $companyId, 'user_id' => 1, 'employee_id' => $ana, 'role' => 'cashier'];
$ids = array_column(chatbotRunAdvisories($conn, $cashierCtx), 'id');

foreach (['payroll_waiting', 'finance_requests', 'payables_due', 'approvals_waiting'] as $forbidden) {
    t_ok(!in_array($forbidden, $ids, true), "a cashier is never shown {$forbidden}");
}

/* And inventory is told nothing about money. */
$ids = array_column(chatbotRunAdvisories($conn, $invCtx), 'id');

foreach (['payroll_waiting', 'payables_due', 'capital_low'] as $forbidden) {
    t_ok(!in_array($forbidden, $ids, true), "inventory is never shown {$forbidden}");
}

t_done();
