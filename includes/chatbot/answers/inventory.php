<?php
/*
| Inventory answers. Read-only, company-scoped, no adjustments: the assistant
| reports what stock is, and the inventory pages remain the only place it
| changes.
*/

function chatbotLowStock(mysqli $conn, array $ctx, string $question): array
{
    $stmt = $conn->prepare("
        SELECT p.product_name, i.quantity, i.reorder_level
        FROM inventory i
        JOIN products p ON p.product_id = i.product_id AND p.company_id = i.company_id
        WHERE i.company_id = ?
          AND i.quantity > 0
          AND i.quantity <= COALESCE(i.reorder_level, 5)
        ORDER BY i.quantity ASC
        LIMIT 20
    ");
    $stmt->bind_param("i", $ctx['company_id']);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];

    while ($row = $result->fetch_assoc()) {
        $rows[] = [
            $row['product_name'],
            (string) (int) $row['quantity'],
            (string) (int) ($row['reorder_level'] ?? 5),
        ];
    }

    $stmt->close();

    return [
        'title' => 'Low stock',
        'lines' => [],
        'table' => $rows
            ? ['columns' => ['Product', 'On hand', 'Reorder level'], 'rows' => $rows]
            : null,
        'link' => null,
        'note' => $rows ? null : 'No product is at or below its reorder level right now.',
    ];
}

function chatbotOutOfStock(mysqli $conn, array $ctx, string $question): array
{
    $stmt = $conn->prepare("
        SELECT p.product_name
        FROM inventory i
        JOIN products p ON p.product_id = i.product_id AND p.company_id = i.company_id
        WHERE i.company_id = ? AND i.quantity <= 0
        ORDER BY p.product_name
        LIMIT 20
    ");
    $stmt->bind_param("i", $ctx['company_id']);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];

    while ($row = $result->fetch_assoc()) {
        $rows[] = [$row['product_name']];
    }

    $stmt->close();

    return [
        'title' => 'Out of stock',
        'lines' => [],
        'table' => $rows ? ['columns' => ['Product'], 'rows' => $rows] : null,
        'link' => null,
        'note' => $rows ? null : 'No product is out of stock right now.',
    ];
}

function chatbotProductStock(mysqli $conn, array $ctx, string $question): array
{
    $intents = chatbotIntents();
    $name = chatbotExtractProductName($question, $intents['product_stock']);

    if ($name === '') {
        return [
            'title' => 'Stock of product',
            'lines' => [],
            'table' => null,
            'link' => null,
            'note' => 'Tell me which product you mean.',
        ];
    }

    $like = '%' . $name . '%';

    $stmt = $conn->prepare("
        SELECT p.product_name, i.quantity
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
        $rows[] = [$row['product_name'], (string) (int) $row['quantity']];
    }

    $stmt->close();

    if (!$rows) {
        return [
            'title' => 'Stock of product',
            'lines' => [],
            'table' => null,
            'link' => null,
            'note' => 'No product matches "' . $name . '".',
        ];
    }

    if (count($rows) === 1) {
        return [
            'title' => $rows[0][0],
            'lines' => [['On hand', $rows[0][1]]],
            'table' => null,
            'link' => null,
        ];
    }

    return [
        'title' => 'Matching products',
        'lines' => [],
        'table' => ['columns' => ['Product', 'On hand'], 'rows' => $rows],
        'link' => null,
    ];
}
