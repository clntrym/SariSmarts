<?php

/*
 * REPORTS - OWNER / ADMIN
 *
 * What changed and why:
 *
 *   This page used to report seven HR tables - recruitment, employees,
 *   attendance, payroll, leave, overtime, undertime - and nothing else. It was
 *   a copy of hr/reports.php. An owner of a sari-sari store opening "Reports"
 *   could not learn what they sold today, what they earned on it, what is left
 *   on the shelf, who owes them money, or where the money went. The one thing
 *   the business is for was missing.
 *
 *   So the money comes first here: sales, margin, stock, receivables,
 *   expenses. The workforce reports stay, because the owner approves leave and
 *   signs off payroll, but they sit behind the trade.
 *
 * NO BRANCH FILTER ON THIS PAGE
 *   sales, sale_items, inventory and expenses carry no branch_id - only the HR
 *   tables reach a branch, through employees. A branch picker here would
 *   quietly change nothing on five of the nine tabs, which is worse than not
 *   offering one. hr/reports.php keeps its picker because every table there
 *   can honour it.
 */

require_once("../init.php");
requireRole(['admin']);

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

$tones = reportTones();
$reports = [];


/* ===================================================================
 * 1. SALES
 * =================================================================== */

$params = [$companyId];
$types = 'i';
$clause = reportDateClause($range, 's.sale_date', $params, $types);

$salesRows = reportRows($conn, "
    SELECT s.sale_id, s.sale_date, s.total_amount, s.tax_amount,
           s.payment_method, s.payment_reference,
           COALESCE(u.fullname, 'Unknown') AS cashier,
           COALESCE(SUM(si.quantity), 0) AS items
    FROM sales s
    LEFT JOIN sale_items si
           ON si.sale_id = s.sale_id AND si.company_id = s.company_id
    LEFT JOIN users u
           ON u.user_id = s.created_by AND u.company_id = s.company_id
    WHERE s.company_id = ?$clause
    GROUP BY s.sale_id, s.sale_date, s.total_amount, s.tax_amount,
             s.payment_method, s.payment_reference, u.fullname
    ORDER BY s.sale_date DESC
", $params, $types);

$revenue = 0.0;
$itemsSold = 0;
$paymentMix = [];

foreach ($salesRows as $row) {
    $revenue += (float) $row['total_amount'];
    $itemsSold += (int) $row['items'];
    $method = $row['payment_method'] !== '' ? $row['payment_method'] : 'Unspecified';
    $paymentMix[$method] = ($paymentMix[$method] ?? 0) + 1;
}

$transactions = count($salesRows);

$reports[] = [
    'id'      => 'sales',
    'label'   => 'Sales',
    'icon'    => 'bi-cash-coin',
    'blurb'   => 'Every transaction rung up in this period.',
    'empty'   => 'No sale was recorded in this period. Sales appear here as soon as a cashier completes one.',
    'kpis'    => [
        ['label' => 'Revenue', 'value' => reportPeso($revenue),
         'icon' => 'bi-graph-up-arrow', 'tone' => 'success'],
        ['label' => 'Transactions', 'value' => reportNumber($transactions),
         'icon' => 'bi-receipt', 'tone' => 'primary'],
        ['label' => 'Average sale', 'value' => reportPeso($transactions ? $revenue / $transactions : 0),
         'icon' => 'bi-basket', 'tone' => 'info'],
        ['label' => 'Items sold', 'value' => reportNumber($itemsSold),
         'icon' => 'bi-box-seam', 'tone' => 'warning'],
    ],
    'visuals' => [
        ['title' => 'Revenue over the period', 'span' => 7,
         'body' => reportBars(reportBucket($salesRows, 'sale_date', 'total_amount'), 'peso', 'success')],
        ['title' => 'How customers paid', 'span' => 5,
         'body' => reportDonut($paymentMix, [
             'Cash' => '#198754', 'GCash' => '#0d6efd', 'Utang' => '#ffc107',
         ])],
    ],
    'columns' => [
        ['head' => 'Date',      'key' => 'sale_date',         'type' => 'datetime'],
        ['head' => 'Cashier',   'key' => 'cashier'],
        ['head' => 'Items',     'key' => 'items',             'type' => 'number'],
        ['head' => 'Payment',   'key' => 'payment_method',    'type' => 'badge'],
        ['head' => 'Reference', 'key' => 'payment_reference'],
        ['head' => 'Tax',       'key' => 'tax_amount',        'type' => 'peso'],
        ['head' => 'Total',     'key' => 'total_amount',      'type' => 'peso'],
    ],
    'totals'  => [
        'items'        => $itemsSold,
        'tax_amount'   => array_sum(array_column($salesRows, 'tax_amount')),
        'total_amount' => $revenue,
    ],
    'rows'    => $salesRows,
];


/* ===================================================================
 * 2. PRODUCTS - what actually sold, and what was made on it
 * =================================================================== */

/*
| The cost side is the current purchase_cost, not the cost on the day of the
| sale, because nothing in the schema records the latter: sale_items keeps
| selling_price and no cost at all. So margin here is an estimate, and the
| note under the filter bar says so rather than letting the number pass for
| audited profit.
*/
$params = [$companyId, $companyId];
$types = 'ii';
$clause = reportDateClause($range, 's.sale_date', $params, $types);

$productRows = reportRows($conn, "
    SELECT p.product_name,
           COALESCE(c.category_name, 'Uncategorised') AS category_name,
           SUM(si.quantity) AS qty,
           SUM(si.quantity * si.selling_price) AS revenue,
           COALESCE(cost.unit_cost, 0) AS unit_cost,
           SUM(si.quantity * si.selling_price)
               - SUM(si.quantity * COALESCE(cost.unit_cost, 0)) AS margin
    FROM sale_items si
    INNER JOIN sales s
            ON s.sale_id = si.sale_id AND s.company_id = si.company_id
    INNER JOIN products p
            ON p.product_id = si.product_id AND p.company_id = si.company_id
    LEFT JOIN categories c
            ON c.category_id = p.category_id AND c.company_id = p.company_id
    LEFT JOIN (
        SELECT product_id, AVG(purchase_cost) AS unit_cost
        FROM inventory
        WHERE company_id = ?
        GROUP BY product_id
    ) cost ON cost.product_id = si.product_id
    WHERE si.company_id = ?$clause
    GROUP BY p.product_id, p.product_name, c.category_name, cost.unit_cost
    ORDER BY revenue DESC
", $params, $types);

$productRevenue = array_sum(array_column($productRows, 'revenue'));
$productMargin = array_sum(array_column($productRows, 'margin'));

$topFive = [];
foreach (array_slice($productRows, 0, 5) as $row) {
    $topFive[$row['product_name']] = (float) $row['revenue'];
}

$byCategory = [];
foreach ($productRows as $row) {
    $byCategory[$row['category_name']] = ($byCategory[$row['category_name']] ?? 0)
        + (float) $row['revenue'];
}
arsort($byCategory);

$reports[] = [
    'id'      => 'products',
    'label'   => 'Products',
    'icon'    => 'bi-box-seam',
    'blurb'   => 'What sold in this period, best first, with the estimated margin on each line.',
    'empty'   => 'Nothing was sold in this period, so there is no product to rank.',
    'kpis'    => [
        ['label' => 'Products sold', 'value' => reportNumber(count($productRows)),
         'icon' => 'bi-tags', 'tone' => 'primary'],
        ['label' => 'Product revenue', 'value' => reportPeso($productRevenue),
         'icon' => 'bi-cash-stack', 'tone' => 'success'],
        ['label' => 'Estimated margin', 'value' => reportPeso($productMargin),
         'icon' => 'bi-percent', 'tone' => $productMargin >= 0 ? 'info' : 'danger',
         'hint' => $productRevenue > 0
             ? number_format(($productMargin / $productRevenue) * 100, 1) . '% of revenue'
             : null],
        ['label' => 'Best seller', 'value' => $productRows
             ? htmlspecialchars($productRows[0]['product_name'])
             : '&mdash;',
         'icon' => 'bi-trophy', 'tone' => 'warning'],
    ],
    'visuals' => [
        ['title' => 'Top five by revenue', 'span' => 6,
         'body' => reportBars($topFive, 'peso', 'primary')],
        ['title' => 'Revenue by category', 'span' => 6,
         'body' => reportBars(array_slice($byCategory, 0, 6, true), 'peso', 'info')],
    ],
    'columns' => [
        ['head' => 'Product',      'key' => 'product_name'],
        ['head' => 'Category',     'key' => 'category_name'],
        ['head' => 'Qty sold',     'key' => 'qty',       'type' => 'number'],
        ['head' => 'Unit cost',    'key' => 'unit_cost', 'type' => 'peso'],
        ['head' => 'Revenue',      'key' => 'revenue',   'type' => 'peso'],
        ['head' => 'Est. margin',  'key' => 'margin',    'type' => 'peso'],
    ],
    'totals'  => [
        'qty'     => array_sum(array_column($productRows, 'qty')),
        'revenue' => $productRevenue,
        'margin'  => $productMargin,
    ],
    'rows'    => $productRows,
];


/* ===================================================================
 * 3. STOCK - a position, not a flow
 * =================================================================== */

/*
| Deliberately ignores the period filter. Stock is what is on the shelf right
| now; "stock between March 1 and March 31" is not a question with an answer,
| and a date-filtered stock list would read as one while meaning something
| else. The report's own blurb says it stands outside the filter.
*/
$stockRows = reportRows($conn, "
    SELECT p.product_name,
           COALESCE(c.category_name, 'Uncategorised') AS category_name,
           COALESCE(sp.supplier_name, 'No supplier') AS supplier_name,
           SUM(i.quantity) AS qty,
           MAX(i.reorder_level) AS reorder_level,
           AVG(i.purchase_cost) AS unit_cost,
           AVG(i.selling_price) AS selling_price,
           SUM(i.quantity * i.purchase_cost) AS stock_value,
           MAX(i.purchase_date) AS last_in
    FROM inventory i
    INNER JOIN products p
            ON p.product_id = i.product_id AND p.company_id = i.company_id
    LEFT JOIN categories c
            ON c.category_id = p.category_id AND c.company_id = p.company_id
    LEFT JOIN suppliers sp
            ON sp.supplier_id = p.supplier_id AND sp.company_id = p.company_id
    WHERE i.company_id = ?
    GROUP BY p.product_id, p.product_name, c.category_name, sp.supplier_name
    ORDER BY qty ASC
", [$companyId], 'i');

$stockValue = 0.0;
$outOfStock = 0;
$lowStock = 0;

foreach ($stockRows as $index => $row) {

    $qty = (int) $row['qty'];
    $reorder = (int) $row['reorder_level'];

    if ($qty <= 0) {
        $state = 'Out of stock';
        $outOfStock++;
    } elseif ($qty <= $reorder) {
        $state = 'Low';
        $lowStock++;
    } else {
        $state = 'Healthy';
    }

    $stockRows[$index]['state'] = $state;
    $stockValue += (float) $row['stock_value'];
}

$reports[] = [
    'id'      => 'stock',
    'timeless' => true,
    'label'   => 'Stock',
    'icon'    => 'bi-boxes',
    'blurb'   => 'What is on the shelf right now. This one stands outside the period filter - stock is a position, not a period.',
    'empty'   => 'No product has stock on record yet. Stock appears once a delivery is received.',
    'kpis'    => [
        ['label' => 'Stock value', 'value' => reportPeso($stockValue),
         'icon' => 'bi-safe', 'tone' => 'success', 'hint' => 'at purchase cost'],
        ['label' => 'Products tracked', 'value' => reportNumber(count($stockRows)),
         'icon' => 'bi-boxes', 'tone' => 'primary'],
        ['label' => 'Low stock', 'value' => reportNumber($lowStock),
         'icon' => 'bi-exclamation-triangle', 'tone' => 'warning',
         'hint' => $lowStock ? 'at or under reorder level' : null],
        ['label' => 'Out of stock', 'value' => reportNumber($outOfStock),
         'icon' => 'bi-x-octagon', 'tone' => $outOfStock ? 'danger' : 'secondary'],
    ],
    'visuals' => [
        ['title' => 'Needs restocking soonest', 'span' => 12,
         'body' => reportBars(
             array_slice(array_combine(
                 array_map(static function (array $r) { return $r['product_name']; },
                     array_slice($stockRows, 0, 8)),
                 array_map(static function (array $r) { return (int) $r['qty']; },
                     array_slice($stockRows, 0, 8))
             ) ?: [], 0, 8, true),
             'number',
             'danger'
         )],
    ],
    'columns' => [
        ['head' => 'Product',     'key' => 'product_name'],
        ['head' => 'Category',    'key' => 'category_name'],
        ['head' => 'Supplier',    'key' => 'supplier_name'],
        ['head' => 'On hand',     'key' => 'qty',           'type' => 'number'],
        ['head' => 'Reorder at',  'key' => 'reorder_level',  'type' => 'number'],
        ['head' => 'State',       'key' => 'state',          'type' => 'badge',
         'badges' => ['Healthy' => 'success', 'Low' => 'warning', 'Out of stock' => 'danger']],
        ['head' => 'Unit cost',   'key' => 'unit_cost',      'type' => 'peso'],
        ['head' => 'Sells at',    'key' => 'selling_price',  'type' => 'peso'],
        ['head' => 'Stock value', 'key' => 'stock_value',    'type' => 'peso'],
        ['head' => 'Last in',     'key' => 'last_in',        'type' => 'date'],
    ],
    'totals'  => [
        'qty'         => array_sum(array_column($stockRows, 'qty')),
        'stock_value' => $stockValue,
    ],
    'rows'    => $stockRows,
];


/* ===================================================================
 * 4. RECEIVABLES - utang
 * =================================================================== */

$params = [$companyId];
$types = 'i';
$clause = reportDateClause($range, 'u.date_created', $params, $types);

$utangRows = reportRows($conn, "
    SELECT u.customer_name, u.contact, u.total_amount, u.paid_amount, u.balance,
           u.date_created, u.due_date, u.status
    FROM utang u
    WHERE u.company_id = ?$clause
    ORDER BY u.balance DESC, u.due_date ASC
", $params, $types);

$owed = 0.0;
$overdueAmount = 0.0;
$overdueCount = 0;
$standing = [];
$todayStamp = strtotime('today');

foreach ($utangRows as $index => $row) {

    $balance = (float) $row['balance'];
    $owed += $balance;

    /* How late, counted here rather than trusted from status - a row can sit
       at "Unpaid" for weeks without anything rewriting it to "Overdue". */
    $age = '';

    if ($balance > 0 && !empty($row['due_date'])) {

        $due = strtotime((string) $row['due_date']);

        if ($due !== false && $due < $todayStamp) {
            $days = (int) floor(($todayStamp - $due) / 86400);
            $age = $days . ' day' . ($days === 1 ? '' : 's') . ' late';
            $overdueAmount += $balance;
            $overdueCount++;
        }
    }

    $utangRows[$index]['age'] = $age;

    $label = $row['status'] !== '' ? $row['status'] : 'Unspecified';
    $standing[$label] = ($standing[$label] ?? 0) + 1;
}

$reports[] = [
    'id'      => 'receivables',
    'label'   => 'Receivables',
    'icon'    => 'bi-journal-text',
    'blurb'   => 'Utang on the books, the largest balance first.',
    'empty'   => 'Nobody took utang in this period.',
    'kpis'    => [
        ['label' => 'Still owed', 'value' => reportPeso($owed),
         'icon' => 'bi-hourglass-split', 'tone' => $owed > 0 ? 'warning' : 'success'],
        ['label' => 'Accounts', 'value' => reportNumber(count($utangRows)),
         'icon' => 'bi-people', 'tone' => 'primary'],
        ['label' => 'Past due', 'value' => reportPeso($overdueAmount),
         'icon' => 'bi-alarm', 'tone' => $overdueAmount > 0 ? 'danger' : 'secondary',
         'hint' => $overdueCount ? $overdueCount . ' account(s)' : null],
        ['label' => 'Collected', 'value' => reportPeso(array_sum(array_column($utangRows, 'paid_amount'))),
         'icon' => 'bi-check2-circle', 'tone' => 'success'],
    ],
    'visuals' => [
        ['title' => 'Where the accounts stand', 'span' => 5,
         'body' => reportDonut($standing, [
             'Paid' => '#198754', 'Partial' => '#ffc107',
             'Unpaid' => '#fd7e14', 'Overdue' => '#dc3545',
         ])],
        ['title' => 'Biggest balances', 'span' => 7,
         'body' => reportBars(
             array_slice(array_combine(
                 array_map(static function (array $r) { return $r['customer_name']; },
                     array_slice($utangRows, 0, 7)),
                 array_map(static function (array $r) { return (float) $r['balance']; },
                     array_slice($utangRows, 0, 7))
             ) ?: [], 0, 7, true),
             'peso',
             'warning'
         )],
    ],
    'columns' => [
        ['head' => 'Customer', 'key' => 'customer_name'],
        ['head' => 'Contact',  'key' => 'contact'],
        ['head' => 'Taken',    'key' => 'date_created',  'type' => 'date'],
        ['head' => 'Due',      'key' => 'due_date',      'type' => 'date'],
        ['head' => 'Overdue',  'key' => 'age'],
        ['head' => 'Amount',   'key' => 'total_amount',  'type' => 'peso'],
        ['head' => 'Paid',     'key' => 'paid_amount',   'type' => 'peso'],
        ['head' => 'Balance',  'key' => 'balance',       'type' => 'peso'],
        ['head' => 'Status',   'key' => 'status',        'type' => 'badge'],
    ],
    'totals'  => [
        'total_amount' => array_sum(array_column($utangRows, 'total_amount')),
        'paid_amount'  => array_sum(array_column($utangRows, 'paid_amount')),
        'balance'      => $owed,
    ],
    'rows'    => $utangRows,
];


/* ===================================================================
 * 5. EXPENSES
 * =================================================================== */

$params = [$companyId];
$types = 'i';
$clause = reportDateClause($range, 'e.expense_date', $params, $types);

$expenseRows = reportRows($conn, "
    SELECT e.expense_code, e.expense_date, e.category, e.vendor, e.description,
           e.amount, e.payment_method,
           COALESCE(u.fullname, '') AS recorded_by
    FROM expenses e
    LEFT JOIN users u
           ON u.user_id = e.created_by AND u.company_id = e.company_id
    WHERE e.company_id = ?$clause
    ORDER BY e.expense_date DESC, e.expense_id DESC
", $params, $types);

$spend = array_sum(array_column($expenseRows, 'amount'));

$spendByCategory = [];
foreach ($expenseRows as $row) {
    $label = $row['category'] !== '' ? $row['category'] : 'Uncategorised';
    $spendByCategory[$label] = ($spendByCategory[$label] ?? 0) + (float) $row['amount'];
}
arsort($spendByCategory);

$reports[] = [
    'id'      => 'expenses',
    'label'   => 'Expenses',
    'icon'    => 'bi-wallet2',
    'blurb'   => 'Money that left the business in this period.',
    'empty'   => 'No expense was recorded in this period.',
    'kpis'    => [
        ['label' => 'Total spend', 'value' => reportPeso($spend),
         'icon' => 'bi-arrow-down-circle', 'tone' => 'danger'],
        ['label' => 'Entries', 'value' => reportNumber(count($expenseRows)),
         'icon' => 'bi-list-ul', 'tone' => 'primary'],
        ['label' => 'Biggest category',
         'value' => $spendByCategory ? htmlspecialchars((string) array_key_first($spendByCategory)) : '&mdash;',
         'icon' => 'bi-pie-chart', 'tone' => 'warning',
         'hint' => $spendByCategory ? strip_tags(reportPeso(reset($spendByCategory))) : null],
        ['label' => 'Revenue less spend', 'value' => reportPeso($revenue - $spend),
         'icon' => 'bi-calculator', 'tone' => ($revenue - $spend) >= 0 ? 'success' : 'danger',
         'hint' => 'before payroll and cost of goods'],
    ],
    'visuals' => [
        ['title' => 'Spend by category', 'span' => 6,
         'body' => reportBars(array_slice($spendByCategory, 0, 8, true), 'peso', 'danger')],
        ['title' => 'Spend over the period', 'span' => 6,
         'body' => reportBars(reportBucket($expenseRows, 'expense_date', 'amount'), 'peso', 'secondary')],
    ],
    'columns' => [
        ['head' => 'Code',        'key' => 'expense_code'],
        ['head' => 'Date',        'key' => 'expense_date',   'type' => 'date'],
        ['head' => 'Category',    'key' => 'category'],
        ['head' => 'Vendor',      'key' => 'vendor'],
        ['head' => 'Description', 'key' => 'description',    'type' => 'wrap'],
        ['head' => 'Paid by',     'key' => 'payment_method', 'type' => 'badge'],
        ['head' => 'Recorded by', 'key' => 'recorded_by'],
        ['head' => 'Amount',      'key' => 'amount',         'type' => 'peso'],
    ],
    'totals'  => ['amount' => $spend],
    'rows'    => $expenseRows,
];


/* ===================================================================
 * 6. PAYROLL
 * =================================================================== */

$params = [$companyId];
$types = 'i';
$clause = reportDateClause($range, 'p.payroll_period_end', $params, $types);

$payrollRows = reportRows($conn, "
    SELECT e.employee_code,
           CONCAT(e.first_name, ' ', e.last_name) AS employee,
           j.job_title, b.branch_name,
           p.payroll_period_start, p.payroll_period_end,
           p.basic_pay, p.overtime_pay, p.gross_pay,
           p.total_deduction, p.net_pay, p.status
    FROM payroll p
    INNER JOIN employees e
            ON e.employee_id = p.employee_id AND e.company_id = p.company_id
    LEFT JOIN job j ON j.job_id = e.job_id AND j.company_id = e.company_id
    LEFT JOIN branch b ON b.branch_id = e.branch_id AND b.company_id = e.company_id
    WHERE p.company_id = ?$clause
    ORDER BY p.payroll_period_end DESC, e.last_name ASC
", $params, $types);

$netPay = array_sum(array_column($payrollRows, 'net_pay'));
$grossPay = array_sum(array_column($payrollRows, 'gross_pay'));

$payrollByPeriod = [];
foreach ($payrollRows as $row) {
    $label = reportWhen($row['payroll_period_end']);
    $payrollByPeriod[$label] = ($payrollByPeriod[$label] ?? 0) + (float) $row['net_pay'];
}

$reports[] = [
    'id'      => 'payroll',
    'label'   => 'Payroll',
    'icon'    => 'bi-cash-stack',
    'blurb'   => 'What the workforce cost, by payslip.',
    'empty'   => 'No payslip falls in this period.',
    'kpis'    => [
        ['label' => 'Net payroll', 'value' => reportPeso($netPay),
         'icon' => 'bi-cash-stack', 'tone' => 'danger'],
        ['label' => 'Gross payroll', 'value' => reportPeso($grossPay),
         'icon' => 'bi-receipt-cutoff', 'tone' => 'primary'],
        ['label' => 'Payslips', 'value' => reportNumber(count($payrollRows)),
         'icon' => 'bi-file-earmark-text', 'tone' => 'info'],
        ['label' => 'Deductions',
         'value' => reportPeso(array_sum(array_column($payrollRows, 'total_deduction'))),
         'icon' => 'bi-dash-circle', 'tone' => 'warning'],
    ],
    'visuals' => [
        ['title' => 'Net payroll by period', 'span' => 12,
         'body' => reportBars(array_slice($payrollByPeriod, 0, 12, true), 'peso', 'primary')],
    ],
    'columns' => [
        ['head' => 'Employee',   'key' => 'employee'],
        ['head' => 'Code',       'key' => 'employee_code'],
        ['head' => 'Position',   'key' => 'job_title'],
        ['head' => 'Branch',     'key' => 'branch_name'],
        ['head' => 'Period to',  'key' => 'payroll_period_end', 'type' => 'date'],
        ['head' => 'Basic',      'key' => 'basic_pay',       'type' => 'peso'],
        ['head' => 'Overtime',   'key' => 'overtime_pay',    'type' => 'peso'],
        ['head' => 'Gross',      'key' => 'gross_pay',       'type' => 'peso'],
        ['head' => 'Deductions', 'key' => 'total_deduction', 'type' => 'peso'],
        ['head' => 'Net',        'key' => 'net_pay',         'type' => 'peso'],
        ['head' => 'Status',     'key' => 'status',          'type' => 'badge'],
    ],
    'totals'  => [
        'gross_pay'       => $grossPay,
        'total_deduction' => array_sum(array_column($payrollRows, 'total_deduction')),
        'net_pay'         => $netPay,
    ],
    'rows'    => $payrollRows,
];


/* ===================================================================
 * 7. WORKFORCE - also a position
 * =================================================================== */

$workforceRows = reportRows($conn, "
    SELECT e.employee_code,
           CONCAT(e.first_name, ' ', e.last_name) AS employee,
           e.email, e.phone,
           j.job_title, j.employment_type,
           b.branch_name, e.employment_status, e.created_at
    FROM employees e
    LEFT JOIN job j ON j.job_id = e.job_id AND j.company_id = e.company_id
    LEFT JOIN branch b ON b.branch_id = e.branch_id AND b.company_id = e.company_id
    WHERE e.company_id = ? AND e.archived_at IS NULL
    ORDER BY e.last_name ASC, e.first_name ASC
", [$companyId], 'i');

$byBranch = [];
$byStatus = [];

foreach ($workforceRows as $row) {
    $branchLabel = $row['branch_name'] !== null && $row['branch_name'] !== ''
        ? $row['branch_name'] : 'Unassigned';
    $byBranch[$branchLabel] = ($byBranch[$branchLabel] ?? 0) + 1;

    $statusLabel = $row['employment_status'] !== '' ? $row['employment_status'] : 'Unspecified';
    $byStatus[$statusLabel] = ($byStatus[$statusLabel] ?? 0) + 1;
}

arsort($byBranch);

$reports[] = [
    'id'      => 'workforce',
    'timeless' => true,
    'label'   => 'Workforce',
    'icon'    => 'bi-people',
    'blurb'   => 'Everyone currently on the books. Outside the period filter, like Stock - headcount is a position.',
    'empty'   => 'No employee is on record yet. Staff appear here once HR hires an applicant.',
    'kpis'    => [
        ['label' => 'Headcount', 'value' => reportNumber(count($workforceRows)),
         'icon' => 'bi-people-fill', 'tone' => 'primary'],
        ['label' => 'Branches staffed', 'value' => reportNumber(count($byBranch)),
         'icon' => 'bi-shop', 'tone' => 'info'],
        ['label' => 'Official', 'value' => reportNumber($byStatus['Official Employee'] ?? 0),
         'icon' => 'bi-patch-check', 'tone' => 'success'],
        ['label' => 'Average payroll cost',
         'value' => reportPeso(count($workforceRows) ? $netPay / count($workforceRows) : 0),
         'icon' => 'bi-person-badge', 'tone' => 'warning', 'hint' => 'per head, this period'],
    ],
    'visuals' => [
        ['title' => 'Staff per branch', 'span' => 7,
         'body' => reportBars($byBranch, 'number', 'primary')],
        ['title' => 'Employment standing', 'span' => 5,
         'body' => reportDonut($byStatus)],
    ],
    'columns' => [
        ['head' => 'Employee', 'key' => 'employee'],
        ['head' => 'Code',     'key' => 'employee_code'],
        ['head' => 'Position', 'key' => 'job_title'],
        ['head' => 'Type',     'key' => 'employment_type',   'type' => 'badge'],
        ['head' => 'Branch',   'key' => 'branch_name'],
        ['head' => 'Email',    'key' => 'email'],
        ['head' => 'Phone',    'key' => 'phone'],
        ['head' => 'Standing', 'key' => 'employment_status', 'type' => 'badge'],
        ['head' => 'On record', 'key' => 'created_at',       'type' => 'date'],
    ],
    'rows'    => $workforceRows,
];


/* ===================================================================
 * 8. REQUESTS - leave, overtime and undertime in one place
 * =================================================================== */

/*
| Three tables, one tab. The owner's question is "what is waiting for me",
| not "show me the undertime table": splitting these across three tabs - as
| the old page did - meant checking three places to answer one question.
|
| UNION ALL rather than three queries, so the result sorts as one queue.
*/
$params = [$companyId];
$types = 'i';
$leaveClause = reportDateClause($range, 'l.created_at', $params, $types);

$params[] = $companyId;
$types .= 'i';
$otClause = reportDateClause($range, 'o.created_at', $params, $types);

$params[] = $companyId;
$types .= 'i';
$utClause = reportDateClause($range, 'u.created_at', $params, $types);

$requestRows = reportRows($conn, "
    SELECT 'Leave' AS kind,
           CONCAT(e.first_name, ' ', e.last_name) AS employee,
           e.employee_code, b.branch_name,
           l.leave_type AS detail,
           CONCAT(DATE_FORMAT(l.start_date, '%b %d'), ' - ',
                  DATE_FORMAT(l.end_date, '%b %d, %Y')) AS covers,
           l.reason,
           CASE
               WHEN l.admin_status IN ('Approved', 'Rejected') THEN l.admin_status
               WHEN l.hr_status = 'Rejected' THEN 'Rejected'
               ELSE 'Pending'
           END AS status,
           CONCAT('HR: ', COALESCE(NULLIF(l.hr_status, ''), 'Pending'),
                  ' / Admin: ', COALESCE(NULLIF(l.admin_status, ''), 'Pending')) AS progress,
           l.created_at
    FROM leave_requests l
    INNER JOIN employees e
            ON e.employee_id = l.employee_id AND e.company_id = l.company_id
    LEFT JOIN branch b ON b.branch_id = e.branch_id AND b.company_id = e.company_id
    WHERE l.company_id = ?$leaveClause

    UNION ALL

    SELECT 'Overtime' AS kind,
           CONCAT(e.first_name, ' ', e.last_name), e.employee_code, b.branch_name,
           CONCAT(FORMAT(o.requested_hours, 1), ' hrs requested') AS detail,
           CASE WHEN o.approved_hours IS NULL THEN '-'
                ELSE CONCAT(FORMAT(o.approved_hours, 1), ' hrs approved') END AS covers,
           o.reason,
           COALESCE(NULLIF(o.status, ''), 'Pending'),
           COALESCE(NULLIF(o.rejection_reason, ''), '') AS progress,
           o.created_at
    FROM overtime_requests o
    INNER JOIN employees e
            ON e.employee_id = o.employee_id AND e.company_id = o.company_id
    LEFT JOIN branch b ON b.branch_id = e.branch_id AND b.company_id = e.company_id
    WHERE o.company_id = ?$otClause

    UNION ALL

    SELECT 'Undertime' AS kind,
           CONCAT(e.first_name, ' ', e.last_name), e.employee_code, b.branch_name,
           CONCAT(FORMAT(u.hours, 1), ' hrs') AS detail,
           DATE_FORMAT(u.request_date, '%b %d, %Y') AS covers,
           u.reason,
           COALESCE(NULLIF(u.status, ''), 'Pending'),
           COALESCE(NULLIF(u.rejection_reason, ''), '') AS progress,
           u.created_at
    FROM undertime_requests u
    INNER JOIN employees e
            ON e.employee_id = u.employee_id AND e.company_id = u.company_id
    LEFT JOIN branch b ON b.branch_id = e.branch_id AND b.company_id = e.company_id
    WHERE u.company_id = ?$utClause

    ORDER BY created_at DESC
", $params, $types);

$pending = 0;
$byKind = [];

foreach ($requestRows as $row) {
    if ($row['status'] === 'Pending') {
        $pending++;
    }
    $byKind[$row['kind']] = ($byKind[$row['kind']] ?? 0) + 1;
}

$reports[] = [
    'id'      => 'requests',
    'label'   => 'Requests',
    'icon'    => 'bi-inbox',
    'blurb'   => 'Leave, overtime and undertime in one queue, newest first.',
    'empty'   => 'Nobody filed a leave, overtime or undertime request in this period.',
    'kpis'    => [
        ['label' => 'Awaiting a decision', 'value' => reportNumber($pending),
         'icon' => 'bi-hourglass-split', 'tone' => $pending ? 'warning' : 'success'],
        ['label' => 'Leave', 'value' => reportNumber($byKind['Leave'] ?? 0),
         'icon' => 'bi-calendar-check', 'tone' => 'primary'],
        ['label' => 'Overtime', 'value' => reportNumber($byKind['Overtime'] ?? 0),
         'icon' => 'bi-clock-history', 'tone' => 'info'],
        ['label' => 'Undertime', 'value' => reportNumber($byKind['Undertime'] ?? 0),
         'icon' => 'bi-clock', 'tone' => 'secondary'],
    ],
    'visuals' => [
        ['title' => 'What people are asking for', 'span' => 12,
         'body' => reportDonut($byKind)],
    ],
    'columns' => [
        ['head' => 'Filed',    'key' => 'created_at',    'type' => 'datetime'],
        ['head' => 'Type',     'key' => 'kind',          'type' => 'badge',
         'badges' => ['Leave' => 'primary', 'Overtime' => 'info', 'Undertime' => 'secondary']],
        ['head' => 'Employee', 'key' => 'employee'],
        ['head' => 'Branch',   'key' => 'branch_name'],
        ['head' => 'Detail',   'key' => 'detail'],
        ['head' => 'Covers',   'key' => 'covers'],
        ['head' => 'Reason',   'key' => 'reason',        'type' => 'wrap'],
        ['head' => 'Status',   'key' => 'status',        'type' => 'badge'],
        ['head' => 'Notes',    'key' => 'progress',      'type' => 'wrap'],
    ],
    'rows'    => $requestRows,
];


/* ===================================================================
 * 9. RECRUITMENT
 * =================================================================== */

$params = [$companyId];
$types = 'i';
$clause = reportDateClause($range, 'a.applied_at', $params, $types);

$recruitRows = reportRows($conn, "
    SELECT CONCAT(a.first_name, ' ', a.last_name) AS applicant,
           a.email, a.phone, a.applied_at, a.status,
           j.job_title, b.branch_name,
           ir.score, ir.recommendation
    FROM applications a
    LEFT JOIN job j ON j.job_id = a.job_id AND j.company_id = a.company_id
    LEFT JOIN branch b ON b.branch_id = j.branch_id AND b.company_id = a.company_id
    LEFT JOIN interview_results ir
           ON ir.application_id = a.application_id AND ir.company_id = a.company_id
    WHERE a.company_id = ?$clause
    ORDER BY a.applied_at DESC
", $params, $types);

$hired = 0;
$pipeline = [];

foreach ($recruitRows as $row) {
    if ($row['status'] === 'Hired') {
        $hired++;
    }
    $label = $row['status'] !== '' ? $row['status'] : 'Unspecified';
    $pipeline[$label] = ($pipeline[$label] ?? 0) + 1;
}

$reports[] = [
    'id'      => 'recruitment',
    'label'   => 'Recruitment',
    'icon'    => 'bi-person-plus',
    'blurb'   => 'Who applied in this period and how far they got.',
    'empty'   => 'Nobody applied in this period. Applications arrive through the careers page.',
    'kpis'    => [
        ['label' => 'Applicants', 'value' => reportNumber(count($recruitRows)),
         'icon' => 'bi-person-lines-fill', 'tone' => 'primary'],
        ['label' => 'Hired', 'value' => reportNumber($hired),
         'icon' => 'bi-person-check', 'tone' => 'success'],
        ['label' => 'In progress',
         'value' => reportNumber(($pipeline['Pending'] ?? 0) + ($pipeline['Interview'] ?? 0)
             + ($pipeline['Recommended'] ?? 0)),
         'icon' => 'bi-arrow-repeat', 'tone' => 'warning'],
        ['label' => 'Hire rate',
         'value' => count($recruitRows)
             ? number_format(($hired / count($recruitRows)) * 100, 1) . '%'
             : '&mdash;',
         'icon' => 'bi-funnel', 'tone' => 'info'],
    ],
    'visuals' => [
        ['title' => 'The hiring funnel', 'span' => 12,
         'body' => reportDonut($pipeline)],
    ],
    'columns' => [
        ['head' => 'Applicant',      'key' => 'applicant'],
        ['head' => 'Applied for',    'key' => 'job_title'],
        ['head' => 'Branch',         'key' => 'branch_name'],
        ['head' => 'Email',          'key' => 'email'],
        ['head' => 'Phone',          'key' => 'phone'],
        ['head' => 'Score',          'key' => 'score',          'type' => 'number'],
        ['head' => 'Recommendation', 'key' => 'recommendation', 'type' => 'badge'],
        ['head' => 'Status',         'key' => 'status',         'type' => 'badge'],
        ['head' => 'Applied',        'key' => 'applied_at',     'type' => 'date'],
    ],
    'rows'    => $recruitRows,
];


/*
| The export runs before a single byte of HTML, or the CSV arrives with the
| page's markup wrapped around it.
*/
if (!empty($_GET['export'])) {
    reportExportCsv($reports, (string) $_GET['export'], $range, $companyName);
}

include("admin_header.php");

renderReportsPage([
    'title'   => 'Reports',
    'blurb'   => 'How ' . $companyName . ' is trading, and who is running it.',
    'range'   => $range,
    'note'    => 'Margin is estimated from the current purchase cost - the schema keeps no cost at the moment of sale. Stock and Workforce show today\'s position and ignore the period above.',
    'reports' => $reports,
]);

include("admin_footer.php");
