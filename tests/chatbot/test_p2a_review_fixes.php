<?php
/*
| The findings from the whole-branch review of Phase 2A.
*/
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/factory.php';
require_once __DIR__ . '/../../includes/chatbot/engine.php';

register_shutdown_function(function () use ($conn) { testCleanup($conn); });

$companyId = testMakeCompany($conn, 'P2A Review Co', 2);

$financeCtx = ['company_id' => $companyId, 'user_id' => 1, 'employee_id' => null, 'role' => 'finance'];
$hrCtx = ['company_id' => $companyId, 'user_id' => 1, 'employee_id' => null, 'role' => 'hr'];
$adminCtx = ['company_id' => $companyId, 'user_id' => 1, 'employee_id' => null, 'role' => 'admin'];
$invCtx = ['company_id' => $companyId, 'user_id' => 1, 'employee_id' => null, 'role' => 'inventory'];

$conn->query("INSERT INTO employees (company_id, first_name, last_name, employment_status)
              VALUES ({$companyId}, 'Ana', 'Cruz', 'Official Employee')");
$ana = (int) $conn->insert_id;

/*
| C2: admin/ajax_add_user.php writes 'Active' as an employment status, so a
| headcount that buckets only the three documented values loses every employee
| the Admin user page created.
*/
foreach (['Active', 'Active', 'Archived'] as $status) {
    $conn->query("INSERT INTO employees (company_id, first_name, last_name, employment_status)
                  VALUES ({$companyId}, 'Staff', '{$status}', '{$status}')");
}

$answer = chatbotAnswer($conn, $hrCtx, 'how many employees do we have')['answer'];
$counts = [];

foreach ($answer['lines'] as [$label, $value]) {
    $counts[$label] = (int) $value;
}

t_same(3, $counts['Active employees'] ?? -1,
    'the headcount counts every employee who is not archived');
t_same(1, $counts['Archived'] ?? -1, 'and reports the archived separately');

/* It must agree with the Phase 1 count, which links to the same page. */
$phase1 = chatbotAnswer($conn, $adminCtx, 'how many employees do we have')['answer'];
t_same((string) ($counts['Active employees'] ?? ''), $phase1['lines'][1][1],
    'HR and the owner are told the same number');

/*
| C1: a total computed over a LIMITed result is simply wrong. Twenty-five
| expense categories and twenty-five unpaid bills.
*/
for ($i = 0; $i < 25; $i++) {
    $conn->query("INSERT INTO expenses (expense_code, expense_date, category, vendor, description, amount, payment_method, company_id)
                  VALUES ('EXP-R{$i}', CURDATE(), 'Cat{$i}', 'V', 'd', 100.00, 'Cash', {$companyId})");

    $conn->query("INSERT INTO stock_requests (request_code, total_price, reason, status, company_id)
                  VALUES ('SR-R{$i}', 1000.00, 'r', 'Received', {$companyId})");
    $requestId = (int) $conn->insert_id;

    $conn->query("INSERT INTO stock_request_items (request_id, item_description, vendor, quantity, unit_price, total_price, company_id)
                  VALUES ({$requestId}, 'item', 'V', 1, 1000.00, 1000.00, {$companyId})");
    $itemId = (int) $conn->insert_id;

    $conn->query("INSERT INTO accounts_payable (request_id, item_id, invoice_no, po_number, supplier,
                                                category, description, amount, paid_amount, due_date, status, company_id)
                  VALUES ({$requestId}, {$itemId}, 'INV-R{$i}', 'PO-R{$i}', 'Supplier {$i}', 'Stock', 'd',
                          1000.00, 0.00, DATE_ADD(CURDATE(), INTERVAL 7 DAY), 'Pending', {$companyId})");
}

$answer = chatbotAnswer($conn, $financeCtx, 'show this month expenses')['answer'];
t_same('₱2,500.00', $answer['lines'][0][1], 'the expense total covers every category, not the first twenty');
t_ok(str_contains((string) ($answer['note'] ?? ''), 'more'),
    'and the answer says the list is cut');

$answer = chatbotAnswer($conn, $financeCtx, 'what do we owe our suppliers')['answer'];
t_same('₱25,000.00', $answer['lines'][0][1], 'the outstanding total covers every bill');
t_ok(str_contains((string) ($answer['note'] ?? ''), 'more'), 'and says the list is cut');

/*
| C3: the matrix gives the owner everything their plan allows. Phase 2A took
| the HR and Inventory questions away from them -- and turned "I did not
| understand" into a flat refusal.
*/
$ownerQuestions = [
    'how many applicants do we have' => 'hr_applicants',
    'what job postings are open' => 'hr_open_jobs',
    'sino ang may pending leave' => 'hr_pending_leave',
    'who is late today' => 'hr_attendance_today',
    'show employees with incomplete records' => 'hr_incomplete_records',
    'who are our suppliers' => 'inv_suppliers',
];

foreach ($ownerQuestions as $question => $intent) {
    $result = chatbotAnswer($conn, $adminCtx, $question);
    t_same($intent, $result['intent'], "the owner may ask: \"{$question}\"");
}

/* Inventory Staff's matrix grants stock levels, which needs the product look-ups. */
foreach (['may stock pa ba ang lucky me' => 'product_stock',
          'magkano ang lucky me' => 'product_price'] as $question => $intent) {
    $result = chatbotAnswer($conn, $invCtx, $question);
    t_same($intent, $result['intent'], "inventory may ask: \"{$question}\"");
}

/* Finance's matrix uses "Show pending budget requests" as its own example. */
$result = chatbotAnswer($conn, $financeCtx, 'show pending budget requests');
t_same('finance_stock_requests', $result['intent'], 'finance may ask about budget requests');

/*
| I1: the refusal must not tell Finance and HR that the system has no payroll
| access, when they have payroll answers. It is about an INDIVIDUAL's pay.
*/
$answer = chatbotAnswer($conn, $financeCtx, 'how much is the salary of Ana Cruz')['answer'];
$note = mb_strtolower((string) ($answer['note'] ?? ''));

t_ok(str_contains($note, 'individual') || str_contains($note, "one person"),
    'the refusal is about an individual, not about the whole subject');
t_ok(!str_contains($note, 'no access to salaries or payroll'),
    'it no longer claims the system has no payroll access at all');

/* And the aggregate question works in Tagalog for the roles that have it. */
foreach (['magkano ang kabuuang sweldo ngayong buwan',
          'ano ang total ng sahod ngayong buwan'] as $question) {
    $result = chatbotAnswer($conn, $financeCtx, $question);
    t_same('finance_payroll_total', $result['intent'], "finance: \"{$question}\"");
}

/* while an individual's pay still refuses, in either language */
foreach (['magkano ang sweldo ni Ana', 'what is my salary',
          'how much is the salary of Ana Cruz'] as $question) {
    $result = chatbotAnswer($conn, $financeCtx, $question);
    t_same('salary_not_available', $result['intent'], "finance: \"{$question}\" is refused");
}

/* HR's matrix grants payroll information relevant to HR. */
$result = chatbotAnswer($conn, $hrCtx, 'how much is the payroll this month');
t_same('finance_payroll_total', $result['intent'], 'hr may see the payroll total');

/* I3: 'Returned' is not awaiting approval -- the dashboards count only one. */
$conn->query("INSERT INTO payroll (employee_id, payroll_period_start, payroll_period_end, working_days,
                                   basic_pay, overtime_pay, gross_pay, late_deduction, undertime_deduction,
                                   absent_deduction, sss, philhealth, pagibig, total_deduction, net_pay,
                                   status, company_id)
              VALUES ({$ana}, DATE_FORMAT(CURDATE(), '%Y-%m-01'), LAST_DAY(CURDATE()), 22,
                      2000, 0, 2000, 0, 0, 0, 0, 0, 0, 0, 2000, 'Pending Approval', {$companyId})");
$conn->query("INSERT INTO payroll (employee_id, payroll_period_start, payroll_period_end, working_days,
                                   basic_pay, overtime_pay, gross_pay, late_deduction, undertime_deduction,
                                   absent_deduction, sss, philhealth, pagibig, total_deduction, net_pay,
                                   status, company_id)
              VALUES ({$ana}, DATE_FORMAT(CURDATE(), '%Y-%m-01'), LAST_DAY(CURDATE()), 22,
                      4000, 0, 4000, 0, 0, 0, 0, 0, 0, 0, 4000, 'Returned', {$companyId})");

$answer = chatbotAnswer($conn, $financeCtx, 'which payrolls are pending approval')['answer'];
t_same(1, count($answer['table']['rows'] ?? []),
    'only Pending Approval is awaiting approval, matching the dashboards');

/*
| I4: every link an answer offers must be a page that exists for the role that
| receives it. The widget uses the href verbatim, relative to the current page.
*/
$roleFolders = ['admin' => 'admin', 'hr' => 'hr', 'finance' => 'finance',
                'inventory' => 'inventory', 'cashier' => 'cashier', 'employee' => 'employee'];

foreach ($roleFolders as $role => $folder) {

    $ctx = ['company_id' => $companyId, 'user_id' => 1, 'employee_id' => $ana, 'role' => $role];

    foreach (chatbotAllowedIntents($conn, $companyId, $role) as $id => $intent) {

        $answer = chatbotAnswer($conn, $ctx, $intent['label'])['answer'] ?? null;

        if ($answer === null || empty($answer['link']['href'])) {
            continue;
        }

        $href = $answer['link']['href'];
        $path = __DIR__ . '/../../' . $folder . '/' . $href;

        t_ok(file_exists($path), "{$role}: {$id} links to {$href}, which exists");
    }
}

/* I2 and I6: a local_only question is decided before the model is consulted. */
t_ok(!chatbotShouldConsultModel($conn, $financeCtx, 'how much is the payroll this month'),
    'a payroll question never reaches the model');
t_ok(!chatbotShouldConsultModel($conn, $financeCtx, 'magkano ang sweldo ni Ana'),
    'nor does an individual-pay question');
t_ok(chatbotShouldConsultModel($conn, $financeCtx, 'show this month expenses'),
    'an ordinary question does');

t_done();
