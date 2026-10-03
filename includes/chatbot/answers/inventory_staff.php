<?php
/*
| Inventory Staff answers.
|
| Their matrix says supplier information is view only, so these answers name
| suppliers and show what is running out: reordering, paying and editing all
| stay on their own pages.
*/

function chatbotInvLowStock(mysqli $conn, array $ctx, string $question): array
{
    $stmt = $conn->prepare("
        SELECT p.product_name, i.quantity, i.reorder_level,
               COALESCE(s.supplier_name, 'No supplier') AS supplier
        FROM inventory i
        JOIN products p ON p.product_id = i.product_id AND p.company_id = i.company_id
        LEFT JOIN suppliers s ON s.supplier_id = p.supplier_id AND s.company_id = p.company_id
        WHERE i.company_id = ?
          AND i.quantity > 0
          AND i.quantity <= COALESCE(i.reorder_level, 5)
        ORDER BY i.quantity
        LIMIT 20
    ");
    $stmt->bind_param("i", $ctx['company_id']);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];

    while ($row = $result->fetch_assoc()) {
        $rows[] = [$row['product_name'], (string) (int) $row['quantity'],
                   (string) (int) ($row['reorder_level'] ?? 5), $row['supplier']];
    }

    $stmt->close();

    return [
        'title' => 'Low stock',
        'lines' => [],
        'table' => $rows
            ? ['columns' => ['Product', 'On hand', 'Reorder level', 'Supplier'], 'rows' => $rows]
            : null,
        'link' => chatbotPageLink($ctx, 'inventory'),
        'note' => $rows ? null : 'No product is at or below its reorder level right now.',
    ];
}

function chatbotInvOutOfStock(mysqli $conn, array $ctx, string $question): array
{
    $stmt = $conn->prepare("
        SELECT p.product_name, COALESCE(s.supplier_name, 'No supplier') AS supplier
        FROM inventory i
        JOIN products p ON p.product_id = i.product_id AND p.company_id = i.company_id
        LEFT JOIN suppliers s ON s.supplier_id = p.supplier_id AND s.company_id = p.company_id
        WHERE i.company_id = ? AND i.quantity <= 0
        ORDER BY p.product_name
        LIMIT 20
    ");
    $stmt->bind_param("i", $ctx['company_id']);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];

    while ($row = $result->fetch_assoc()) {
        $rows[] = [$row['product_name'], $row['supplier']];
    }

    $stmt->close();

    return [
        'title' => 'Out of stock',
        'lines' => [],
        'table' => $rows ? ['columns' => ['Product', 'Supplier'], 'rows' => $rows] : null,
        'link' => chatbotPageLink($ctx, 'inventory'),
        'note' => $rows ? null : 'No product is out of stock right now.',
    ];
}

function chatbotInvStockRequests(mysqli $conn, array $ctx, string $question): array
{
    $stmt = $conn->prepare("
        SELECT request_code, status, total_price, reason
        FROM stock_requests
        WHERE company_id = ?
        ORDER BY created_at DESC
        LIMIT 20
    ");
    $stmt->bind_param("i", $ctx['company_id']);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];

    while ($row = $result->fetch_assoc()) {
        $rows[] = [$row['request_code'], $row['status'],
                   chatbotPeso((float) $row['total_price']), $row['reason']];
    }

    $stmt->close();

    return [
        'title' => 'Stock requests',
        'lines' => [],
        'table' => $rows
            ? ['columns' => ['Request', 'Status', 'Amount', 'Reason'], 'rows' => $rows]
            : null,
        'link' => chatbotPageLink($ctx, 'stock_requests'),
        'note' => $rows ? null : 'There is no stock request yet.',
    ];
}

function chatbotInvSuppliers(mysqli $conn, array $ctx, string $question): array
{
    $stmt = $conn->prepare("
        SELECT s.supplier_name, s.contact_email, COUNT(p.product_id) AS products
        FROM suppliers s
        LEFT JOIN products p ON p.supplier_id = s.supplier_id AND p.company_id = s.company_id
        WHERE s.company_id = ?
        GROUP BY s.supplier_id, s.supplier_name, s.contact_email
        ORDER BY s.supplier_name
        LIMIT 20
    ");
    $stmt->bind_param("i", $ctx['company_id']);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];

    while ($row = $result->fetch_assoc()) {
        $rows[] = [$row['supplier_name'], (string) $row['contact_email'],
                   (string) (int) $row['products']];
    }

    $stmt->close();

    return [
        'title' => 'Suppliers',
        'lines' => [],
        'table' => $rows
            ? ['columns' => ['Supplier', 'Contact', 'Products'], 'rows' => $rows]
            : null,
        'link' => chatbotPageLink($ctx, 'suppliers'),
        'note' => $rows ? null : 'No supplier has been added yet.',
    ];
}
