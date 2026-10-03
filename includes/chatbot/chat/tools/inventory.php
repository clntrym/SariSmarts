<?php
/*
| Inventory tools.
|
| The Cashier's matrix says stock is "view only": they see what is on the
| shelf, not what it cost to put there or who supplies it. That is why these
| tools choose their columns from the role rather than filtering afterwards --
| a column never selected cannot leak.
*/

function chatToolStockList(mysqli $conn, array $ctx, array $in): array
{
    $isCashier = strtolower((string) $ctx['role']) === 'cashier';

    /* One row beyond what we will keep, so the runner can tell the model that
       more matched. Asking for exactly the cap makes truncation invisible. */
    $limit = min((int) ($in['limit'] ?? CHAT_TOOL_ROW_CAP), CHAT_TOOL_ROW_CAP) + 1;

    /* Built from a validated enum, never from model text. */
    $condition = match ($in['state']) {
        'low' => 'i.quantity > 0 AND i.quantity <= COALESCE(i.reorder_level, 5)',
        'out' => 'i.quantity <= 0',
        default => '1 = 1',
    };

    if ($isCashier) {
        $sql = "
            SELECT p.product_name, i.quantity, i.reorder_level
            FROM inventory i
            JOIN products p ON p.product_id = i.product_id AND p.company_id = i.company_id
            WHERE i.company_id = ? AND {$condition}
            ORDER BY i.quantity ASC
            LIMIT ?
        ";
        $columns = ['product', 'quantity', 'reorder_level'];
    } else {
        $sql = "
            SELECT p.product_name, i.quantity, i.reorder_level, i.purchase_cost, i.selling_price
            FROM inventory i
            JOIN products p ON p.product_id = i.product_id AND p.company_id = i.company_id
            WHERE i.company_id = ? AND {$condition}
            ORDER BY i.quantity ASC
            LIMIT ?
        ";
        $columns = ['product', 'quantity', 'reorder_level', 'purchase_cost', 'selling_price'];
    }

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $ctx['company_id'], $limit);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];

    while ($row = $result->fetch_assoc()) {
        $rows[] = array_map(static fn ($value): string => (string) $value, array_values($row));
    }

    $stmt->close();

    return ['columns' => $columns, 'rows' => $rows];
}

function chatToolProductLookup(mysqli $conn, array $ctx, array $in): array
{
    $isCashier = strtolower((string) $ctx['role']) === 'cashier';

    /* Kept as typed, with the LIKE wildcards escaped, so a product named
       "50% off pack" is findable and "%" alone matches nothing. */
    $like = chatLikeTerm((string) $in['name']);

    if ($like === null) {
        return ['columns' => ['product', 'selling_price', 'quantity', 'category'],
                'rows' => []];
    }

    if ($isCashier) {
        $sql = "
            SELECT p.product_name, i.selling_price, i.quantity, c.category_name
            FROM products p
            JOIN inventory i ON i.product_id = p.product_id AND i.company_id = p.company_id
            LEFT JOIN categories c ON c.category_id = p.category_id AND c.company_id = p.company_id
            WHERE p.company_id = ? AND p.product_name LIKE ?
            ORDER BY p.product_name
            LIMIT 51
        ";
        $columns = ['product', 'selling_price', 'quantity', 'category'];
    } else {
        $sql = "
            SELECT p.product_name, i.selling_price, i.quantity, c.category_name, s.supplier_name
            FROM products p
            JOIN inventory i ON i.product_id = p.product_id AND i.company_id = p.company_id
            LEFT JOIN categories c ON c.category_id = p.category_id AND c.company_id = p.company_id
            LEFT JOIN suppliers s ON s.supplier_id = p.supplier_id AND s.company_id = p.company_id
            WHERE p.company_id = ? AND p.product_name LIKE ?
            ORDER BY p.product_name
            LIMIT 51
        ";
        $columns = ['product', 'selling_price', 'quantity', 'category', 'supplier'];
    }

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("is", $ctx['company_id'], $like);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];

    while ($row = $result->fetch_assoc()) {
        $rows[] = array_map(static fn ($value): string => (string) $value, array_values($row));
    }

    $stmt->close();

    return ['columns' => $columns, 'rows' => $rows];
}

function chatToolStockRequests(mysqli $conn, array $ctx, array $in): array
{
    /* One beyond the cap, so the runner can see that more matched. */
    $limit = min((int) ($in['limit'] ?? CHAT_TOOL_ROW_CAP), CHAT_TOOL_ROW_CAP) + 1;
    $status = (string) $in['status'];

    if ($status === 'all') {
        $stmt = $conn->prepare("
            SELECT request_code, status, total_price, reason, created_at
            FROM stock_requests
            WHERE company_id = ?
            ORDER BY created_at DESC
            LIMIT ?
        ");
        $stmt->bind_param("ii", $ctx['company_id'], $limit);
    } else {
        $stmt = $conn->prepare("
            SELECT request_code, status, total_price, reason, created_at
            FROM stock_requests
            WHERE company_id = ? AND status = ?
            ORDER BY created_at DESC
            LIMIT ?
        ");
        $stmt->bind_param("isi", $ctx['company_id'], $status, $limit);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];

    while ($row = $result->fetch_assoc()) {
        $rows[] = array_map(static fn ($value): string => (string) $value, array_values($row));
    }

    $stmt->close();

    return ['columns' => ['request_code', 'status', 'total_price', 'reason', 'created_at'],
            'rows' => $rows];
}
