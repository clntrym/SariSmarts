<?php
/*
| POS answers.
|
| Every statement is a prepared SELECT with company_id bound -- the audit in
| tests/chatbot/query_audit.py fails the suite if one is not. Handlers return
| arrays and never build HTML: the browser renders them with textContent, so a
| product name containing markup is shown as text.
*/

function chatbotPeso(float $amount): string
{
    return '₱' . number_format($amount, 2);
}

function chatbotSalesToday(mysqli $conn, array $ctx, string $question): array
{
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS transactions, COALESCE(SUM(total_amount), 0) AS total
        FROM sales
        WHERE company_id = ? AND DATE(sale_date) = CURDATE()
    ");
    $stmt->bind_param("i", $ctx['company_id']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return [
        'title' => 'Sales today',
        'lines' => [
            ['Total', chatbotPeso((float) $row['total'])],
            ['Transactions', (string) (int) $row['transactions']],
        ],
        'table' => null,
        'link' => chatbotPageLink($ctx, 'income'),
    ];
}

function chatbotSalesMonth(mysqli $conn, array $ctx, string $question): array
{
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS transactions, COALESCE(SUM(total_amount), 0) AS total
        FROM sales
        WHERE company_id = ?
          AND YEAR(sale_date) = YEAR(CURDATE())
          AND MONTH(sale_date) = MONTH(CURDATE())
    ");
    $stmt->bind_param("i", $ctx['company_id']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return [
        'title' => 'Sales this month',
        'lines' => [
            ['Total', chatbotPeso((float) $row['total'])],
            ['Transactions', (string) (int) $row['transactions']],
        ],
        'table' => null,
        'link' => chatbotPageLink($ctx, 'income'),
    ];
}

function chatbotTopProducts(mysqli $conn, array $ctx, string $question): array
{
    $stmt = $conn->prepare("
        SELECT p.product_name, SUM(si.quantity) AS sold
        FROM sale_items si
        JOIN sales s ON s.sale_id = si.sale_id AND s.company_id = si.company_id
        JOIN products p ON p.product_id = si.product_id AND p.company_id = si.company_id
        WHERE si.company_id = ?
          AND YEAR(s.sale_date) = YEAR(CURDATE())
          AND MONTH(s.sale_date) = MONTH(CURDATE())
        GROUP BY p.product_id, p.product_name
        ORDER BY sold DESC
        LIMIT 5
    ");
    $stmt->bind_param("i", $ctx['company_id']);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];

    while ($row = $result->fetch_assoc()) {
        $rows[] = [$row['product_name'], (string) (int) $row['sold']];
    }

    $stmt->close();

    return [
        'title' => 'Top selling products this month',
        'lines' => [],
        'table' => $rows
            ? ['columns' => ['Product', 'Sold'], 'rows' => $rows]
            : null,
        'link' => null,
        'note' => $rows ? null : 'No sales recorded this month yet.',
    ];
}

function chatbotMySalesToday(mysqli $conn, array $ctx, string $question): array
{
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS transactions, COALESCE(SUM(total_amount), 0) AS total
        FROM sales
        WHERE company_id = ? AND created_by = ? AND DATE(sale_date) = CURDATE()
    ");
    $stmt->bind_param("ii", $ctx['company_id'], $ctx['user_id']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    /* Sales taken before created_by existed belong to nobody. Saying so is
       better than quietly reporting a smaller number. */
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS orphans
        FROM sales
        WHERE company_id = ? AND created_by IS NULL AND DATE(sale_date) = CURDATE()
    ");
    $stmt->bind_param("i", $ctx['company_id']);
    $stmt->execute();
    $orphans = (int) $stmt->get_result()->fetch_assoc()['orphans'];
    $stmt->close();

    return [
        'title' => 'My sales today',
        'lines' => [
            ['Total', chatbotPeso((float) $row['total'])],
            ['Transactions', (string) (int) $row['transactions']],
        ],
        'table' => null,
        'link' => null,
        'note' => $orphans > 0
            ? $orphans . ' of today\'s sales do not record who rang them up, so they are not counted here.'
            : null,
    ];
}

function chatbotProductPrice(mysqli $conn, array $ctx, string $question): array
{
    $intents = chatbotIntents();
    $name = chatbotExtractProductName($question, $intents['product_price']);

    if ($name === '') {
        return [
            'title' => 'Price of product',
            'lines' => [],
            'table' => null,
            'link' => null,
            'note' => 'Tell me which product you mean.',
        ];
    }

    $like = '%' . $name . '%';

    $stmt = $conn->prepare("
        SELECT p.product_name, i.selling_price
        FROM products p
        JOIN inventory i ON i.product_id = p.product_id AND i.company_id = p.company_id
        WHERE p.company_id = ? AND p.product_name LIKE ?
        ORDER BY p.product_name
        LIMIT 10
    ");
    $stmt->bind_param("is", $ctx['company_id'], $like);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];

    while ($row = $result->fetch_assoc()) {
        $rows[] = [$row['product_name'], chatbotPeso((float) $row['selling_price'])];
    }

    $stmt->close();

    if (!$rows) {
        return [
            'title' => 'Price of product',
            'lines' => [],
            'table' => null,
            'link' => null,
            'note' => 'No product matches "' . $name . '".',
        ];
    }

    /* One match answers directly; several are all listed rather than guessing. */
    if (count($rows) === 1) {
        return [
            'title' => $rows[0][0],
            'lines' => [['Price', $rows[0][1]]],
            'table' => null,
            'link' => null,
        ];
    }

    return [
        'title' => 'Matching products',
        'lines' => [],
        'table' => ['columns' => ['Product', 'Price'], 'rows' => $rows],
        'link' => null,
    ];
}
