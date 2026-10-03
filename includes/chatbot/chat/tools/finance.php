<?php
/*
| Finance tools: money that moves between the business and other people.
|
| Money that moves between the business and its OWN staff -- payroll -- has no
| tool here and never will. Those answers exist on the keyword path, where the
| figures are rendered on this server and never sent to a model.
*/

function chatToolFinanceExpenses(mysqli $conn, array $ctx, array $in): array
{
    $range = chatResolvePeriod($in['period'], $in['from'] ?? null, $in['to'] ?? null);
    $limit = min((int) ($in['limit'] ?? CHAT_TOOL_ROW_CAP), CHAT_TOOL_ROW_CAP) + 1;

    $stmt = $conn->prepare("
        SELECT category, COUNT(*) AS entries, COALESCE(SUM(amount), 0) AS total
        FROM expenses
        WHERE company_id = ? AND expense_date BETWEEN ? AND ?
        GROUP BY category
        ORDER BY total DESC
        LIMIT ?
    ");
    $stmt->bind_param("issi", $ctx['company_id'], $range['from'], $range['to'], $limit);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];

    while ($row = $result->fetch_assoc()) {
        $rows[] = [$row['category'], (string) (int) $row['entries'],
                   number_format((float) $row['total'], 2, '.', '')];
    }

    $stmt->close();

    return ['columns' => ['category', 'entries', 'amount'], 'rows' => $rows];
}

function chatToolFinancePayables(mysqli $conn, array $ctx, array $in): array
{
    $limit = min((int) ($in['limit'] ?? CHAT_TOOL_ROW_CAP), CHAT_TOOL_ROW_CAP) + 1;
    $status = (string) $in['status'];

    $sql = "
        SELECT supplier, description, amount, paid_amount,
               (amount - paid_amount) AS balance, due_date, status
        FROM accounts_payable
        WHERE company_id = ?
    ";

    if ($status === 'unpaid') {
        $sql .= " AND status IN ('Pending', 'Partial') ";
    } elseif ($status !== 'all') {
        $sql .= " AND status = ? ";
    }

    $sql .= " ORDER BY due_date LIMIT ? ";

    $stmt = $conn->prepare($sql);

    if ($status === 'unpaid' || $status === 'all') {
        $stmt->bind_param("ii", $ctx['company_id'], $limit);
    } else {
        $stmt->bind_param("isi", $ctx['company_id'], $status, $limit);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];

    while ($row = $result->fetch_assoc()) {
        $rows[] = [
            $row['supplier'],
            $row['description'],
            number_format((float) $row['amount'], 2, '.', ''),
            number_format((float) $row['balance'], 2, '.', ''),
            (string) $row['due_date'],
            $row['status'],
        ];
    }

    $stmt->close();

    return ['columns' => ['supplier', 'description', 'amount', 'balance', 'due_date', 'status'],
            'rows' => $rows];
}
