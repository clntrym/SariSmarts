<?php
/*
| Sales tools.
|
| Every statement is a prepared SELECT with company_id bound and its columns
| named -- no SELECT *, so a column added to sales later cannot arrive here
| unnoticed.
*/

function chatToolSalesSummary(mysqli $conn, array $ctx, array $in): array
{
    $range = chatResolvePeriod($in['period'], $in['from'] ?? null, $in['to'] ?? null);

    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(total_amount), 0) AS total,
               COUNT(*) AS transactions,
               COALESCE(AVG(total_amount), 0) AS average
        FROM sales
        WHERE company_id = ? AND DATE(sale_date) BETWEEN ? AND ?
    ");
    $stmt->bind_param("iss", $ctx['company_id'], $range['from'], $range['to']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return [
        'columns' => ['total_sales', 'transactions', 'average_sale', 'from', 'to'],
        'rows' => [[
            number_format((float) $row['total'], 2, '.', ''),
            (string) (int) $row['transactions'],
            number_format((float) $row['average'], 2, '.', ''),
            $range['from'],
            $range['to'],
        ]],
    ];
}

function chatToolSalesByDay(mysqli $conn, array $ctx, array $in): array
{
    $stmt = $conn->prepare("
        SELECT DATE(sale_date) AS day,
               COALESCE(SUM(total_amount), 0) AS total,
               COUNT(*) AS transactions
        FROM sales
        WHERE company_id = ? AND DATE(sale_date) BETWEEN ? AND ?
        GROUP BY DATE(sale_date)
        ORDER BY day
        LIMIT 51
    ");
    $stmt->bind_param("iss", $ctx['company_id'], $in['from'], $in['to']);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];

    while ($row = $result->fetch_assoc()) {
        $rows[] = [
            $row['day'],
            number_format((float) $row['total'], 2, '.', ''),
            (string) (int) $row['transactions'],
        ];
    }

    $stmt->close();

    return ['columns' => ['day', 'total_sales', 'transactions'], 'rows' => $rows];
}

function chatToolTopProducts(mysqli $conn, array $ctx, array $in): array
{
    $range = chatResolvePeriod($in['period'], $in['from'] ?? null, $in['to'] ?? null);

    /* One beyond the cap, so truncation is visible to the runner. */
    $limit = min((int) ($in['limit'] ?? 10), CHAT_TOOL_ROW_CAP) + 1;

    $stmt = $conn->prepare("
        SELECT p.product_name,
               SUM(si.quantity) AS sold,
               SUM(si.quantity * si.selling_price) AS revenue
        FROM sale_items si
        JOIN sales s ON s.sale_id = si.sale_id AND s.company_id = si.company_id
        JOIN products p ON p.product_id = si.product_id AND p.company_id = si.company_id
        WHERE si.company_id = ? AND DATE(s.sale_date) BETWEEN ? AND ?
        GROUP BY p.product_id, p.product_name
        ORDER BY sold DESC
        LIMIT ?
    ");
    $stmt->bind_param("issi", $ctx['company_id'], $range['from'], $range['to'], $limit);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];

    while ($row = $result->fetch_assoc()) {
        $rows[] = [
            $row['product_name'],
            (string) (int) $row['sold'],
            number_format((float) $row['revenue'], 2, '.', ''),
        ];
    }

    $stmt->close();

    return ['columns' => ['product', 'quantity_sold', 'revenue'], 'rows' => $rows];
}

function chatToolPaymentMix(mysqli $conn, array $ctx, array $in): array
{
    $range = chatResolvePeriod($in['period'], $in['from'] ?? null, $in['to'] ?? null);

    $stmt = $conn->prepare("
        SELECT payment_method,
               COALESCE(SUM(total_amount), 0) AS total,
               COUNT(*) AS transactions
        FROM sales
        WHERE company_id = ? AND DATE(sale_date) BETWEEN ? AND ?
        GROUP BY payment_method
        ORDER BY total DESC
    ");
    $stmt->bind_param("iss", $ctx['company_id'], $range['from'], $range['to']);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];

    while ($row = $result->fetch_assoc()) {
        $rows[] = [
            $row['payment_method'],
            number_format((float) $row['total'], 2, '.', ''),
            (string) (int) $row['transactions'],
        ];
    }

    $stmt->close();

    return ['columns' => ['payment_method', 'total_sales', 'transactions'], 'rows' => $rows];
}
