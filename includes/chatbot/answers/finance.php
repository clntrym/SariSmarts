<?php
/*
| Finance answers.
|
| The payroll ones live HERE, on the keyword path, and are marked local_only in
| the catalog. A keyword answer is queried and rendered on this server; nothing
| about it is sent to a model. That is what lets Finance keep the payroll
| answers its matrix promises while the tool catalog still has no payroll tool
| for anyone. See the conversational spec, "Payroll: answerable, but never by
| the AI".
*/

function chatbotFinancePayrollTotal(mysqli $conn, array $ctx, string $question): array
{
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS payslips,
               COALESCE(SUM(gross_pay), 0) AS gross,
               COALESCE(SUM(total_deduction), 0) AS deductions,
               COALESCE(SUM(net_pay), 0) AS net
        FROM payroll
        WHERE company_id = ?
          AND YEAR(payroll_period_start) = YEAR(CURDATE())
          AND MONTH(payroll_period_start) = MONTH(CURDATE())
    ");
    $stmt->bind_param("i", $ctx['company_id']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return [
        'title' => 'Payroll this month',
        'lines' => [
            ['Payslips', (string) (int) $row['payslips']],
            ['Gross pay', chatbotPeso((float) $row['gross'])],
            ['Deductions', chatbotPeso((float) $row['deductions'])],
            ['Net pay', chatbotPeso((float) $row['net'])],
        ],
        'table' => null,
        'link' => chatbotPageLink($ctx, 'payroll'),
    ];
}

function chatbotFinancePayrollPending(mysqli $conn, array $ctx, string $question): array
{
    $stmt = $conn->prepare("
        SELECT CONCAT(e.first_name, ' ', e.last_name) AS employee,
               p.payroll_period_start, p.payroll_period_end, p.status, p.net_pay
        FROM payroll p
        JOIN employees e ON e.employee_id = p.employee_id AND e.company_id = p.company_id
        WHERE p.company_id = ? AND p.status = 'Pending Approval'
        ORDER BY p.created_at DESC
        LIMIT 20
    ");
    $stmt->bind_param("i", $ctx['company_id']);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];

    while ($row = $result->fetch_assoc()) {
        $rows[] = [$row['employee'],
                   $row['payroll_period_start'] . ' - ' . $row['payroll_period_end'],
                   $row['status'], chatbotPeso((float) $row['net_pay'])];
    }

    $stmt->close();

    return [
        'title' => 'Payroll awaiting approval',
        'lines' => [],
        'table' => $rows
            ? ['columns' => ['Employee', 'Period', 'Status', 'Net pay'], 'rows' => $rows]
            : null,
        'link' => chatbotPageLink($ctx, 'payroll'),
        'note' => $rows ? null : 'No payroll is waiting for approval.',
    ];
}

function chatbotFinanceExpenses(mysqli $conn, array $ctx, string $question): array
{
    /* The total is taken over EVERY row, not over the twenty listed below:
       a figure labelled Total that silently omits the 21st category is worse
       than no figure at all. */
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS entries, COALESCE(SUM(amount), 0) AS total,
               COUNT(DISTINCT category) AS categories
        FROM expenses
        WHERE company_id = ?
          AND YEAR(expense_date) = YEAR(CURDATE())
          AND MONTH(expense_date) = MONTH(CURDATE())
    ");
    $stmt->bind_param("i", $ctx['company_id']);
    $stmt->execute();
    $summary = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $stmt = $conn->prepare("
        SELECT category, COUNT(*) AS entries, COALESCE(SUM(amount), 0) AS total
        FROM expenses
        WHERE company_id = ?
          AND YEAR(expense_date) = YEAR(CURDATE())
          AND MONTH(expense_date) = MONTH(CURDATE())
        GROUP BY category
        ORDER BY total DESC
        LIMIT 20
    ");
    $stmt->bind_param("i", $ctx['company_id']);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];

    while ($row = $result->fetch_assoc()) {
        $rows[] = [$row['category'], (string) (int) $row['entries'],
                   chatbotPeso((float) $row['total'])];
    }

    $stmt->close();

    $more = (int) $summary['categories'] - count($rows);

    return [
        'title' => 'Expenses this month',
        'lines' => [
            ['Total', chatbotPeso((float) $summary['total'])],
            ['Entries', (string) (int) $summary['entries']],
        ],
        'table' => $rows
            ? ['columns' => ['Category', 'Entries', 'Amount'], 'rows' => $rows]
            : null,
        'link' => chatbotPageLink($ctx, 'expenses'),
        'note' => $rows
            ? ($more > 0 ? $more . ' more categories are in the total but not listed.' : null)
            : 'No expense has been recorded this month.',
    ];
}

function chatbotFinancePayables(mysqli $conn, array $ctx, string $question): array
{
    /* Outstanding is the whole outstanding, not the top twenty bills. */
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS bills,
               COALESCE(SUM(amount - paid_amount), 0) AS outstanding
        FROM accounts_payable
        WHERE company_id = ? AND status IN ('Pending', 'Partial')
    ");
    $stmt->bind_param("i", $ctx['company_id']);
    $stmt->execute();
    $summary = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $stmt = $conn->prepare("
        SELECT supplier, description, amount, paid_amount, due_date, status
        FROM accounts_payable
        WHERE company_id = ? AND status IN ('Pending', 'Partial')
        ORDER BY due_date
        LIMIT 20
    ");
    $stmt->bind_param("i", $ctx['company_id']);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];

    while ($row = $result->fetch_assoc()) {
        $balance = (float) $row['amount'] - (float) $row['paid_amount'];

        $rows[] = [$row['supplier'], $row['description'], chatbotPeso($balance),
                   (string) $row['due_date'], $row['status']];
    }

    $stmt->close();

    $more = (int) $summary['bills'] - count($rows);

    return [
        'title' => 'Unpaid supplier bills',
        'lines' => [
            ['Outstanding', chatbotPeso((float) $summary['outstanding'])],
            ['Bills', (string) (int) $summary['bills']],
        ],
        'table' => $rows
            ? ['columns' => ['Supplier', 'For', 'Balance', 'Due', 'Status'], 'rows' => $rows]
            : null,
        'link' => chatbotPageLink($ctx, 'payables'),
        'note' => $rows
            ? ($more > 0 ? $more . ' more bills are in the total but not listed.' : null)
            : 'Nothing is outstanding with suppliers.',
    ];
}

function chatbotFinanceStockRequests(mysqli $conn, array $ctx, string $question): array
{
    $stmt = $conn->prepare("
        SELECT request_code, total_price, reason, created_at
        FROM stock_requests
        WHERE company_id = ? AND status = 'Pending Finance'
        ORDER BY created_at
        LIMIT 20
    ");
    $stmt->bind_param("i", $ctx['company_id']);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];

    while ($row = $result->fetch_assoc()) {
        $rows[] = [$row['request_code'], chatbotPeso((float) $row['total_price']),
                   $row['reason'], (string) $row['created_at']];
    }

    $stmt->close();

    return [
        'title' => 'Stock requests for finance approval',
        'lines' => [],
        'table' => $rows
            ? ['columns' => ['Request', 'Amount', 'Reason', 'Requested'], 'rows' => $rows]
            : null,
        'link' => chatbotPageLink($ctx, 'stock_requests'),
        'note' => $rows ? null : 'No stock request is waiting for finance.',
    ];
}
