<?php

/*
 * REPORTS - FINANCE
 *
 * This page is new. The finance sidebar's Reports link pointed at
 * ../admin/reports.php, which begins with requireRole(['admin']) - so the
 * finance officer was redirected to their own dashboard every time they
 * clicked it. The one other Reports link in the finance folder, in the unused
 * finance_headers.php, pointed at a reports.php that did not exist.
 *
 * What a finance role needs to answer:
 *   - did the business make money this period, and where did it go
 *   - what is the capital doing
 *   - what do we owe, to whom, and how late
 *   - what did we pay, and against which invoice
 *   - what did the workforce cost, statutory deductions included
 *   - what are we owed
 */

require_once("../init.php");
requireRole(['finance', 'admin']);

/*
| The owner reaches this too, and keeps their own sidebar.
|
| The first pass at this matched requireRole(['admin']) inside the
| comment at the top of the file rather than the real guard below it,
| so the page still refused the owner and rendered nothing at all.
*/
require_once __DIR__ . '/../includes/role_chrome.php';

$companyId = requireCompany();

require_once(__DIR__ . "/../includes/report_kit.php");

$range = reportRange();

$companyName = (string) reportValue(
    $conn,
    "SELECT company_name FROM company WHERE company_id = ?",
    [$companyId],
    'i',
    'Business'
);

$reports = [];


/* ===================================================================
 * THE FIGURES - gathered once, because the statement and the tabs below
 * must not be able to disagree with each other
 * =================================================================== */

$params = [$companyId];
$types = 'i';
$clause = reportDateClause($range, 'sale_date', $params, $types);

$revenue = (float) reportValue($conn, "
    SELECT COALESCE(SUM(total_amount), 0)
    FROM sales WHERE company_id = ?$clause
", $params, $types);

$taxCollected = (float) reportValue($conn, "
    SELECT COALESCE(SUM(tax_amount), 0)
    FROM sales WHERE company_id = ?$clause
", $params, $types);

/*
| Cost of goods, estimated.
|
| sale_items records what a thing SOLD for and never what it cost, so the only
| cost available is the one standing in inventory now. A price change between
| the sale and today moves this number. It is labelled "estimated" wherever it
| appears for that reason, and the gross figure beneath it inherits the word.
*/
$params = [$companyId, $companyId];
$types = 'ii';
$clause = reportDateClause($range, 's.sale_date', $params, $types);

$costOfGoods = (float) reportValue($conn, "
    SELECT COALESCE(SUM(si.quantity * COALESCE(cost.unit_cost, 0)), 0)
    FROM sale_items si
    INNER JOIN sales s
            ON s.sale_id = si.sale_id AND s.company_id = si.company_id
    LEFT JOIN (
        SELECT product_id, AVG(purchase_cost) AS unit_cost
        FROM inventory
        WHERE company_id = ?
        GROUP BY product_id
    ) cost ON cost.product_id = si.product_id
    WHERE si.company_id = ?$clause
", $params, $types);

$params = [$companyId];
$types = 'i';
$clause = reportDateClause($range, 'expense_date', $params, $types);

$operatingExpenses = (float) reportValue($conn, "
    SELECT COALESCE(SUM(amount), 0)
    FROM expenses WHERE company_id = ?$clause
", $params, $types);

$params = [$companyId];
$types = 'i';
$clause = reportDateClause($range, 'payroll_period_end', $params, $types);

$payrollCost = (float) reportValue($conn, "
    SELECT COALESCE(SUM(net_pay), 0)
    FROM payroll WHERE company_id = ?$clause
", $params, $types);

$statutory = (float) reportValue($conn, "
    SELECT COALESCE(SUM(sss + philhealth + pagibig), 0)
    FROM payroll WHERE company_id = ?$clause
", $params, $types);

$grossProfit = $revenue - $costOfGoods;
$netResult = $grossProfit - $operatingExpenses - $payrollCost;

$currentCapital = (float) reportValue($conn, "
    SELECT COALESCE(SUM(current_capital), 0)
    FROM finance_capital WHERE company_id = ?
", [$companyId], 'i');


/* ===================================================================
 * 1. PROFIT AND LOSS
 * =================================================================== */

/*
| A statement rather than a list: each row is a line of the calculation, in
| the order the calculation runs, so the net figure can be followed from the
| top of the page rather than taken on trust.
*/
$marginPercent = static function (float $part) use ($revenue): string {
    return $revenue > 0 ? number_format(($part / $revenue) * 100, 1) . '%' : '-';
};

$statementRows = [
    [
        'line'   => 'Revenue',
        'kind'   => 'Income',
        'amount' => $revenue,
        'share'  => $marginPercent($revenue),
        'note'   => 'Everything rung up at the till in this period.',
    ],
    [
        'line'   => 'Cost of goods sold',
        'kind'   => 'Cost',
        'amount' => -$costOfGoods,
        'share'  => $marginPercent($costOfGoods),
        'note'   => 'Estimated: the purchase cost standing in inventory today, not the cost on the day of sale.',
    ],
    [
        'line'   => 'Gross profit',
        'kind'   => 'Subtotal',
        'amount' => $grossProfit,
        'share'  => $marginPercent($grossProfit),
        'note'   => 'Revenue less the estimated cost of what was sold.',
    ],
    [
        'line'   => 'Operating expenses',
        'kind'   => 'Cost',
        'amount' => -$operatingExpenses,
        'share'  => $marginPercent($operatingExpenses),
        'note'   => 'Everything recorded in Expenses: rent, utilities, supplies, transport.',
    ],
    [
        'line'   => 'Payroll',
        'kind'   => 'Cost',
        'amount' => -$payrollCost,
        'share'  => $marginPercent($payrollCost),
        'note'   => 'Net pay on payslips whose period ends inside this range.',
    ],
    [
        'line'   => 'Net result',
        'kind'   => $netResult >= 0 ? 'Profit' : 'Loss',
        'amount' => $netResult,
        'share'  => $marginPercent($netResult),
        'note'   => $netResult >= 0
            ? 'What the business kept after goods, expenses and wages.'
            : 'The business spent more than it earned in this period.',
    ],
];

$reports[] = [
    'id'      => 'pnl',
    'label'   => 'Profit and Loss',
    'icon'    => 'bi-graph-up',
    'blurb'   => 'The whole period in one calculation, top to bottom.',
    'empty'   => 'Nothing to calculate for this period.',
    'kpis'    => [
        ['label' => 'Revenue', 'value' => reportPeso($revenue),
         'icon' => 'bi-graph-up-arrow', 'tone' => 'success'],
        ['label' => 'Gross profit', 'value' => reportPeso($grossProfit),
         'icon' => 'bi-percent', 'tone' => $grossProfit >= 0 ? 'info' : 'danger',
         'hint' => 'estimated, ' . $marginPercent($grossProfit) . ' of revenue'],
        ['label' => 'Total costs',
         'value' => reportPeso($costOfGoods + $operatingExpenses + $payrollCost),
         'icon' => 'bi-arrow-down-circle', 'tone' => 'warning'],
        ['label' => $netResult >= 0 ? 'Net profit' : 'Net loss',
         'value' => reportPeso($netResult),
         'icon' => $netResult >= 0 ? 'bi-piggy-bank' : 'bi-exclamation-octagon',
         'tone' => $netResult >= 0 ? 'success' : 'danger'],
    ],
    'visuals' => [
        ['title' => 'Where the revenue went', 'span' => 7,
         'body' => reportBars([
             'Cost of goods'      => $costOfGoods,
             'Operating expenses' => $operatingExpenses,
             'Payroll'            => $payrollCost,
             'Kept'               => max(0, $netResult),
         ], 'peso', 'primary')],
        ['title' => 'Share of revenue', 'span' => 5,
         'body' => reportDonut([
             'Cost of goods'      => $costOfGoods,
             'Operating expenses' => $operatingExpenses,
             'Payroll'            => $payrollCost,
             'Kept'               => max(0, $netResult),
         ], [
             'Cost of goods' => '#fd7e14', 'Operating expenses' => '#dc3545',
             'Payroll' => '#6f42c1', 'Kept' => '#198754',
         ])],
    ],
    'columns' => [
        ['head' => 'Line',             'key' => 'line'],
        ['head' => 'Type',             'key' => 'kind',   'type' => 'badge',
         'badges' => ['Income' => 'success', 'Cost' => 'danger', 'Subtotal' => 'info',
                      'Profit' => 'success', 'Loss' => 'danger']],
        ['head' => 'Amount',           'key' => 'amount', 'type' => 'peso'],
        ['head' => 'Share of revenue', 'key' => 'share'],
        ['head' => 'What this is',     'key' => 'note',   'type' => 'wrap'],
    ],
    'rows'    => $statementRows,
];


/* ===================================================================
 * 2. CAPITAL
 * =================================================================== */

$params = [$companyId];
$types = 'i';
$clause = reportDateClause($range, 'created_at', $params, $types);

$capitalRows = reportRows($conn, "
    SELECT type, reference_code, amount, balance_after, description, created_at
    FROM capital_ledger
    WHERE company_id = ?$clause
    ORDER BY created_at DESC, ledger_id DESC
", $params, $types);

$addedIn = 0.0;
$takenOut = 0.0;
$byType = [];

foreach ($capitalRows as $row) {

    $amount = (float) $row['amount'];

    if ($amount >= 0) {
        $addedIn += $amount;
    } else {
        $takenOut += abs($amount);
    }

    /* An unlisted enum value lands as '' in MySQL rather than being refused,
       so a blank type is a real possibility and is labelled, not dropped. */
    $label = $row['type'] !== '' ? $row['type'] : 'Untyped';
    $byType[$label] = ($byType[$label] ?? 0) + abs($amount);
}

arsort($byType);

$reports[] = [
    'id'      => 'capital',
    'label'   => 'Capital',
    'icon'    => 'bi-safe2',
    'blurb'   => 'Every movement in and out of capital, newest first.',
    'empty'   => 'No capital movement was recorded in this period.',
    'kpis'    => [
        ['label' => 'Capital on hand', 'value' => reportPeso($currentCapital),
         'icon' => 'bi-safe2', 'tone' => 'primary', 'hint' => 'current balance, all time'],
        ['label' => 'In this period', 'value' => reportPeso($addedIn),
         'icon' => 'bi-arrow-down-left-circle', 'tone' => 'success'],
        ['label' => 'Out this period', 'value' => reportPeso($takenOut),
         'icon' => 'bi-arrow-up-right-circle', 'tone' => 'danger'],
        ['label' => 'Net movement', 'value' => reportPeso($addedIn - $takenOut),
         'icon' => 'bi-arrow-left-right',
         'tone' => ($addedIn - $takenOut) >= 0 ? 'success' : 'warning'],
    ],
    'visuals' => [
        ['title' => 'Movement by type', 'span' => 7,
         'body' => reportBars($byType, 'peso', 'primary')],
        ['title' => 'Share of movement', 'span' => 5,
         'body' => reportDonut($byType)],
    ],
    'columns' => [
        ['head' => 'When',          'key' => 'created_at',     'type' => 'datetime'],
        ['head' => 'Type',          'key' => 'type',           'type' => 'badge',
         'badges' => ['Sale Income' => 'success', 'Stock Purchase' => 'danger',
                      'Capital Added' => 'primary']],
        ['head' => 'Reference',     'key' => 'reference_code'],
        ['head' => 'Description',   'key' => 'description',    'type' => 'wrap'],
        ['head' => 'Amount',        'key' => 'amount',         'type' => 'peso'],
        ['head' => 'Balance after', 'key' => 'balance_after',  'type' => 'peso'],
    ],
    'totals'  => ['amount' => $addedIn - $takenOut],
    'rows'    => $capitalRows,
];


/* ===================================================================
 * 3. EXPENSES
 * =================================================================== */

$params = [$companyId];
$types = 'i';
$clause = reportDateClause($range, 'e.expense_date', $params, $types);

$expenseRows = reportRows($conn, "
    SELECT e.expense_code, e.expense_date, e.category, e.vendor, e.description,
           e.amount, e.payment_method, e.receipt_path,
           COALESCE(u.fullname, '') AS recorded_by
    FROM expenses e
    LEFT JOIN users u
           ON u.user_id = e.created_by AND u.company_id = e.company_id
    WHERE e.company_id = ?$clause
    ORDER BY e.expense_date DESC, e.expense_id DESC
", $params, $types);

$byCategory = [];
$byMethod = [];
$withoutReceipt = 0;

foreach ($expenseRows as $index => $row) {

    $label = $row['category'] !== '' ? $row['category'] : 'Uncategorised';
    $byCategory[$label] = ($byCategory[$label] ?? 0) + (float) $row['amount'];

    $method = $row['payment_method'] !== '' ? $row['payment_method'] : 'Unspecified';
    $byMethod[$method] = ($byMethod[$method] ?? 0) + 1;

    /* Whether a receipt was attached is an audit question, so it is a column
       rather than something to discover by opening each entry. */
    $hasReceipt = !empty($row['receipt_path']);
    $expenseRows[$index]['receipt'] = $hasReceipt ? 'Attached' : 'Missing';

    if (!$hasReceipt) {
        $withoutReceipt++;
    }
}

arsort($byCategory);

$reports[] = [
    'id'      => 'expenses',
    'label'   => 'Expenses',
    'icon'    => 'bi-wallet2',
    'blurb'   => 'Money out, with whether a receipt is on file against each entry.',
    'empty'   => 'No expense was recorded in this period.',
    'kpis'    => [
        ['label' => 'Total spend', 'value' => reportPeso($operatingExpenses),
         'icon' => 'bi-arrow-down-circle', 'tone' => 'danger'],
        ['label' => 'Entries', 'value' => reportNumber(count($expenseRows)),
         'icon' => 'bi-list-ul', 'tone' => 'primary'],
        ['label' => 'Biggest category',
         'value' => $byCategory ? htmlspecialchars((string) array_key_first($byCategory)) : '&mdash;',
         'icon' => 'bi-pie-chart', 'tone' => 'warning',
         'hint' => $byCategory ? strip_tags(reportPeso(reset($byCategory))) : null],
        ['label' => 'Without a receipt', 'value' => reportNumber($withoutReceipt),
         'icon' => 'bi-file-earmark-x',
         'tone' => $withoutReceipt ? 'warning' : 'success'],
    ],
    'visuals' => [
        ['title' => 'Spend by category', 'span' => 7,
         'body' => reportBars(array_slice($byCategory, 0, 8, true), 'peso', 'danger')],
        ['title' => 'How it was paid', 'span' => 5,
         'body' => reportDonut($byMethod, [
             'Cash' => '#198754', 'GCash' => '#0d6efd', 'Bank Transfer' => '#6f42c1',
         ])],
    ],
    'columns' => [
        ['head' => 'Code',        'key' => 'expense_code'],
        ['head' => 'Date',        'key' => 'expense_date',   'type' => 'date'],
        ['head' => 'Category',    'key' => 'category'],
        ['head' => 'Vendor',      'key' => 'vendor'],
        ['head' => 'Description', 'key' => 'description',    'type' => 'wrap'],
        ['head' => 'Paid by',     'key' => 'payment_method', 'type' => 'badge'],
        ['head' => 'Receipt',     'key' => 'receipt',        'type' => 'badge',
         'badges' => ['Attached' => 'success', 'Missing' => 'warning']],
        ['head' => 'Recorded by', 'key' => 'recorded_by'],
        ['head' => 'Amount',      'key' => 'amount',         'type' => 'peso'],
    ],
    'totals'  => ['amount' => $operatingExpenses],
    'rows'    => $expenseRows,
];


/* ===================================================================
 * 4. PAYABLES - what we owe
 * =================================================================== */

$params = [$companyId];
$types = 'i';
$clause = reportDateClause($range, 'ap.created_at', $params, $types);

$payableRows = reportRows($conn, "
    SELECT ap.invoice_no, ap.po_number, ap.supplier, ap.category, ap.description,
           ap.amount, ap.paid_amount, ap.due_date, ap.status, ap.created_at
    FROM accounts_payable ap
    WHERE ap.company_id = ?$clause
    ORDER BY ap.due_date ASC
", $params, $types);

$owed = 0.0;
$overdueOwed = 0.0;
$overdueCount = 0;
$payableStanding = [];
$todayStamp = strtotime('today');

foreach ($payableRows as $index => $row) {

    $outstanding = (float) $row['amount'] - (float) $row['paid_amount'];
    $payableRows[$index]['outstanding'] = $outstanding;

    $owed += max(0, $outstanding);

    /* Days late, worked out here rather than read off status: a row sits at
       "Unpaid" indefinitely without anything rewriting it to "Overdue". */
    $late = '';

    if ($outstanding > 0.005 && !empty($row['due_date'])) {

        $due = strtotime((string) $row['due_date']);

        if ($due !== false && $due < $todayStamp) {
            $days = (int) floor(($todayStamp - $due) / 86400);
            $late = $days . ' day' . ($days === 1 ? '' : 's') . ' late';
            $overdueOwed += $outstanding;
            $overdueCount++;
        }
    }

    $payableRows[$index]['age'] = $late;

    $label = $row['status'] !== '' ? $row['status'] : 'Unspecified';
    $payableStanding[$label] = ($payableStanding[$label] ?? 0) + 1;
}

$bySupplier = [];
foreach ($payableRows as $row) {
    $label = $row['supplier'] !== '' ? $row['supplier'] : 'Unnamed';
    $bySupplier[$label] = ($bySupplier[$label] ?? 0) + max(0, (float) $row['outstanding']);
}
arsort($bySupplier);

$reports[] = [
    'id'      => 'payables',
    'label'   => 'Payables',
    'icon'    => 'bi-file-earmark-text',
    'blurb'   => 'Invoices on the books, soonest due first.',
    'empty'   => 'No payable was raised in this period.',
    'kpis'    => [
        ['label' => 'Outstanding', 'value' => reportPeso($owed),
         'icon' => 'bi-hourglass-split', 'tone' => $owed > 0 ? 'warning' : 'success'],
        ['label' => 'Invoices', 'value' => reportNumber(count($payableRows)),
         'icon' => 'bi-files', 'tone' => 'primary'],
        ['label' => 'Past due', 'value' => reportPeso($overdueOwed),
         'icon' => 'bi-alarm', 'tone' => $overdueOwed > 0 ? 'danger' : 'secondary',
         'hint' => $overdueCount ? $overdueCount . ' invoice(s)' : null],
        ['label' => 'Settled',
         'value' => reportPeso(array_sum(array_column($payableRows, 'paid_amount'))),
         'icon' => 'bi-check2-circle', 'tone' => 'success'],
    ],
    'visuals' => [
        ['title' => 'Outstanding by supplier', 'span' => 7,
         'body' => reportBars(array_slice($bySupplier, 0, 8, true), 'peso', 'warning')],
        ['title' => 'Where the invoices stand', 'span' => 5,
         'body' => reportDonut($payableStanding, [
             'Paid' => '#198754', 'Partial' => '#ffc107',
             'Unpaid' => '#fd7e14', 'Pending' => '#0d6efd',
         ])],
    ],
    'columns' => [
        ['head' => 'Invoice',     'key' => 'invoice_no'],
        ['head' => 'PO',          'key' => 'po_number'],
        ['head' => 'Supplier',    'key' => 'supplier'],
        ['head' => 'Category',    'key' => 'category'],
        ['head' => 'Description', 'key' => 'description',  'type' => 'wrap'],
        ['head' => 'Raised',      'key' => 'created_at',   'type' => 'date'],
        ['head' => 'Due',         'key' => 'due_date',     'type' => 'date'],
        ['head' => 'Overdue',     'key' => 'age'],
        ['head' => 'Amount',      'key' => 'amount',       'type' => 'peso'],
        ['head' => 'Paid',        'key' => 'paid_amount',  'type' => 'peso'],
        ['head' => 'Outstanding', 'key' => 'outstanding',  'type' => 'peso'],
        ['head' => 'Status',      'key' => 'status',       'type' => 'badge'],
    ],
    'totals'  => [
        'amount'      => array_sum(array_column($payableRows, 'amount')),
        'paid_amount' => array_sum(array_column($payableRows, 'paid_amount')),
        'outstanding' => $owed,
    ],
    'rows'    => $payableRows,
];


/* ===================================================================
 * 5. PAYMENTS MADE
 * =================================================================== */

$params = [$companyId];
$types = 'i';
$clause = reportDateClause($range, 'pay.paid_at', $params, $types);

$paymentRows = reportRows($conn, "
    SELECT pay.paid_at, pay.amount_paid, pay.payment_method, pay.reference_no, pay.notes,
           ap.invoice_no, ap.supplier,
           COALESCE(u.fullname, 'Unknown') AS paid_by_name
    FROM ap_payments pay
    LEFT JOIN accounts_payable ap
           ON ap.ap_id = pay.ap_id AND ap.company_id = pay.company_id
    LEFT JOIN users u
           ON u.user_id = pay.paid_by AND u.company_id = pay.company_id
    WHERE pay.company_id = ?$clause
    ORDER BY pay.paid_at DESC
", $params, $types);

$paidOut = array_sum(array_column($paymentRows, 'amount_paid'));

$paymentMethods = [];
foreach ($paymentRows as $row) {
    $label = $row['payment_method'] !== '' ? $row['payment_method'] : 'Unspecified';
    $paymentMethods[$label] = ($paymentMethods[$label] ?? 0) + 1;
}

$reports[] = [
    'id'      => 'payments',
    'label'   => 'Payments Made',
    'icon'    => 'bi-send-check',
    'blurb'   => 'Every settlement against a payable, and who made it.',
    'empty'   => 'No payment was made against a payable in this period.',
    'kpis'    => [
        ['label' => 'Paid out', 'value' => reportPeso($paidOut),
         'icon' => 'bi-send-check', 'tone' => 'danger'],
        ['label' => 'Payments', 'value' => reportNumber(count($paymentRows)),
         'icon' => 'bi-list-ol', 'tone' => 'primary'],
        ['label' => 'Average payment',
         'value' => reportPeso(count($paymentRows) ? $paidOut / count($paymentRows) : 0),
         'icon' => 'bi-calculator', 'tone' => 'info'],
        ['label' => 'Still outstanding', 'value' => reportPeso($owed),
         'icon' => 'bi-hourglass', 'tone' => $owed > 0 ? 'warning' : 'success'],
    ],
    'visuals' => [
        ['title' => 'Payments over the period', 'span' => 7,
         'body' => reportBars(reportBucket($paymentRows, 'paid_at', 'amount_paid'), 'peso', 'danger')],
        ['title' => 'How payments were made', 'span' => 5,
         'body' => reportDonut($paymentMethods, [
             'Cash' => '#198754', 'GCash' => '#0d6efd', 'Bank Transfer' => '#6f42c1',
         ])],
    ],
    'columns' => [
        ['head' => 'Paid',      'key' => 'paid_at',        'type' => 'datetime'],
        ['head' => 'Invoice',   'key' => 'invoice_no'],
        ['head' => 'Supplier',  'key' => 'supplier'],
        ['head' => 'Method',    'key' => 'payment_method', 'type' => 'badge'],
        ['head' => 'Reference', 'key' => 'reference_no'],
        ['head' => 'Notes',     'key' => 'notes',          'type' => 'wrap'],
        ['head' => 'Paid by',   'key' => 'paid_by_name'],
        ['head' => 'Amount',    'key' => 'amount_paid',    'type' => 'peso'],
    ],
    'totals'  => ['amount_paid' => $paidOut],
    'rows'    => $paymentRows,
];


/* ===================================================================
 * 6. PAYROLL COST - with the statutory split finance has to remit
 * =================================================================== */

$params = [$companyId];
$types = 'i';
$clause = reportDateClause($range, 'p.payroll_period_end', $params, $types);

$payrollRows = reportRows($conn, "
    SELECT e.employee_code,
           CONCAT(e.first_name, ' ', e.last_name) AS employee,
           j.job_title,
           p.payroll_period_start, p.payroll_period_end, p.working_days,
           p.basic_pay, p.overtime_pay, p.gross_pay,
           p.late_deduction, p.undertime_deduction, p.absent_deduction,
           p.sss, p.philhealth, p.pagibig,
           p.total_deduction, p.net_pay, p.status
    FROM payroll p
    INNER JOIN employees e
            ON e.employee_id = p.employee_id AND e.company_id = p.company_id
    LEFT JOIN job j ON j.job_id = e.job_id AND j.company_id = e.company_id
    WHERE p.company_id = ?$clause
    ORDER BY p.payroll_period_end DESC, e.last_name ASC
", $params, $types);

$sss = array_sum(array_column($payrollRows, 'sss'));
$philhealth = array_sum(array_column($payrollRows, 'philhealth'));
$pagibig = array_sum(array_column($payrollRows, 'pagibig'));

$reports[] = [
    'id'      => 'payroll',
    'label'   => 'Payroll Cost',
    'icon'    => 'bi-cash-stack',
    'blurb'   => 'Payslips in this period, with the statutory deductions that have to be remitted.',
    'empty'   => 'No payslip falls in this period.',
    'kpis'    => [
        ['label' => 'Net payroll', 'value' => reportPeso($payrollCost),
         'icon' => 'bi-cash-stack', 'tone' => 'danger'],
        ['label' => 'Gross payroll',
         'value' => reportPeso(array_sum(array_column($payrollRows, 'gross_pay'))),
         'icon' => 'bi-receipt-cutoff', 'tone' => 'primary'],
        ['label' => 'To remit', 'value' => reportPeso($statutory),
         'icon' => 'bi-bank', 'tone' => 'warning',
         'hint' => 'SSS, PhilHealth, Pag-IBIG'],
        ['label' => 'Payslips', 'value' => reportNumber(count($payrollRows)),
         'icon' => 'bi-file-earmark-text', 'tone' => 'info'],
    ],
    'visuals' => [
        ['title' => 'Statutory deductions to remit', 'span' => 7,
         'body' => reportBars([
             'SSS' => $sss, 'PhilHealth' => $philhealth, 'Pag-IBIG' => $pagibig,
         ], 'peso', 'warning')],
        ['title' => 'Share of the remittance', 'span' => 5,
         'body' => reportDonut([
             'SSS' => $sss, 'PhilHealth' => $philhealth, 'Pag-IBIG' => $pagibig,
         ])],
    ],
    'columns' => [
        ['head' => 'Employee',    'key' => 'employee'],
        ['head' => 'Code',        'key' => 'employee_code'],
        ['head' => 'Position',    'key' => 'job_title'],
        ['head' => 'Period to',   'key' => 'payroll_period_end', 'type' => 'date'],
        ['head' => 'Days',        'key' => 'working_days',    'type' => 'number'],
        ['head' => 'Basic',       'key' => 'basic_pay',       'type' => 'peso'],
        ['head' => 'Overtime',    'key' => 'overtime_pay',    'type' => 'peso'],
        ['head' => 'Gross',       'key' => 'gross_pay',       'type' => 'peso'],
        ['head' => 'SSS',         'key' => 'sss',             'type' => 'peso'],
        ['head' => 'PhilHealth',  'key' => 'philhealth',      'type' => 'peso'],
        ['head' => 'Pag-IBIG',    'key' => 'pagibig',         'type' => 'peso'],
        ['head' => 'Deductions',  'key' => 'total_deduction', 'type' => 'peso'],
        ['head' => 'Net',         'key' => 'net_pay',         'type' => 'peso'],
        ['head' => 'Status',      'key' => 'status',          'type' => 'badge'],
    ],
    'totals'  => [
        'gross_pay'       => array_sum(array_column($payrollRows, 'gross_pay')),
        'sss'             => $sss,
        'philhealth'      => $philhealth,
        'pagibig'         => $pagibig,
        'total_deduction' => array_sum(array_column($payrollRows, 'total_deduction')),
        'net_pay'         => $payrollCost,
    ],
    'rows'    => $payrollRows,
];


/* ===================================================================
 * 7. RECEIVABLES - what we are owed
 * =================================================================== */

$params = [$companyId];
$types = 'i';
$clause = reportDateClause($range, 'u.date_created', $params, $types);

$utangRows = reportRows($conn, "
    SELECT u.customer_name, u.contact, u.total_amount, u.paid_amount, u.balance,
           u.date_created, u.due_date, u.status
    FROM utang u
    WHERE u.company_id = ?$clause
    ORDER BY u.balance DESC
", $params, $types);

$receivable = 0.0;
$receivableOverdue = 0.0;
$utangStanding = [];

foreach ($utangRows as $index => $row) {

    $balance = (float) $row['balance'];
    $receivable += $balance;

    $late = '';

    if ($balance > 0 && !empty($row['due_date'])) {
        $due = strtotime((string) $row['due_date']);
        if ($due !== false && $due < $todayStamp) {
            $days = (int) floor(($todayStamp - $due) / 86400);
            $late = $days . ' day' . ($days === 1 ? '' : 's') . ' late';
            $receivableOverdue += $balance;
        }
    }

    $utangRows[$index]['age'] = $late;

    $label = $row['status'] !== '' ? $row['status'] : 'Unspecified';
    $utangStanding[$label] = ($utangStanding[$label] ?? 0) + 1;
}

$reports[] = [
    'id'      => 'receivables',
    'label'   => 'Receivables',
    'icon'    => 'bi-journal-text',
    'blurb'   => 'Utang owed to the business, largest balance first.',
    'empty'   => 'No utang was taken in this period.',
    'kpis'    => [
        ['label' => 'Owed to us', 'value' => reportPeso($receivable),
         'icon' => 'bi-hourglass-split', 'tone' => $receivable > 0 ? 'warning' : 'success'],
        ['label' => 'Accounts', 'value' => reportNumber(count($utangRows)),
         'icon' => 'bi-people', 'tone' => 'primary'],
        ['label' => 'Past due', 'value' => reportPeso($receivableOverdue),
         'icon' => 'bi-alarm', 'tone' => $receivableOverdue > 0 ? 'danger' : 'secondary'],
        ['label' => 'Net position', 'value' => reportPeso($receivable - $owed),
         'icon' => 'bi-arrow-left-right',
         'tone' => ($receivable - $owed) >= 0 ? 'success' : 'warning',
         'hint' => 'receivables less payables'],
    ],
    'visuals' => [
        ['title' => 'Where the accounts stand', 'span' => 12,
         'body' => reportDonut($utangStanding, [
             'Paid' => '#198754', 'Partial' => '#ffc107',
             'Unpaid' => '#fd7e14', 'Overdue' => '#dc3545',
         ])],
    ],
    'columns' => [
        ['head' => 'Customer', 'key' => 'customer_name'],
        ['head' => 'Contact',  'key' => 'contact'],
        ['head' => 'Taken',    'key' => 'date_created', 'type' => 'date'],
        ['head' => 'Due',      'key' => 'due_date',     'type' => 'date'],
        ['head' => 'Overdue',  'key' => 'age'],
        ['head' => 'Amount',   'key' => 'total_amount', 'type' => 'peso'],
        ['head' => 'Paid',     'key' => 'paid_amount',  'type' => 'peso'],
        ['head' => 'Balance',  'key' => 'balance',      'type' => 'peso'],
        ['head' => 'Status',   'key' => 'status',       'type' => 'badge'],
    ],
    'totals'  => [
        'total_amount' => array_sum(array_column($utangRows, 'total_amount')),
        'paid_amount'  => array_sum(array_column($utangRows, 'paid_amount')),
        'balance'      => $receivable,
    ],
    'rows'    => $utangRows,
];


if (!empty($_GET['export'])) {
    reportExportCsv($reports, (string) $_GET['export'], $range, $companyName);
}

include includeRoleHeader(__DIR__, 'finance_header.php');

renderReportsPage([
    'title'   => 'Finance Reports',
    'blurb'   => 'What came in, what went out, and what is still owed either way.',
    'range'   => $range,
    'note'    => 'Cost of goods is estimated from the purchase cost standing in inventory today - the schema records no cost at the moment of sale - so gross profit and the net result carry that estimate. Capital on hand is the running balance, not a figure for this period.',
    'reports' => $reports,
]);

include includeRoleFooter(__DIR__, 'finance_footer.php');
