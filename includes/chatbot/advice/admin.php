<?php
/*
| The owner's advisories: what is waiting on them, and what is running out.
*/

function chatbotAdviseApprovalsWaiting(mysqli $conn, array $ctx): ?array
{
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS waiting,
               COALESCE(SUM(total_price), 0) AS amount,
               COALESCE(MAX(DATEDIFF(NOW(), created_at)), 0) AS oldest_days
        FROM stock_requests
        WHERE company_id = ? AND status = 'Pending Admin'
    ");
    $stmt->bind_param("i", $ctx['company_id']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $waiting = (int) $row['waiting'];

    if ($waiting === 0) {
        return null;
    }

    return [
        'message' => $waiting . ' stock request(s) are waiting for your approval.',
        'evidence' => [
            ['Waiting', (string) $waiting],
            ['Amount', chatbotPeso((float) $row['amount'])],
            ['Oldest', (int) $row['oldest_days'] . ' days'],
        ],
    ];
}

function chatbotAdviseLowStock(mysqli $conn, array $ctx): ?array
{
    $stmt = $conn->prepare("
        SELECT
            COALESCE(SUM(i.quantity > 0 AND i.quantity <= COALESCE(i.reorder_level, 5)), 0) AS low_count,
            COALESCE(SUM(i.quantity <= 0), 0) AS out_count
        FROM inventory i
        WHERE i.company_id = ?
    ");
    $stmt->bind_param("i", $ctx['company_id']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $low = (int) $row['low_count'];
    $out = (int) $row['out_count'];

    if ($low === 0 && $out === 0) {
        return null;
    }

    return [
        'message' => ($low + $out) . ' product(s) need restocking.',
        'evidence' => [
            ['At or below reorder level', (string) $low],
            ['Out of stock', (string) $out],
        ],
    ];
}

function chatbotAdviseLowCapital(mysqli $conn, array $ctx): ?array
{
    $stmt = $conn->prepare("
        SELECT current_capital
        FROM finance_capital
        WHERE company_id = ?
        ORDER BY updated_at DESC
        LIMIT 1
    ");
    $stmt->bind_param("i", $ctx['company_id']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return null;
    }

    $capital = (float) $row['current_capital'];

    if ($capital > ADVICE_LOW_CAPITAL) {
        return null;
    }

    return [
        'message' => 'Your capital is running low.',
        'evidence' => [
            ['Capital', chatbotPeso($capital)],
            ['Warning level', chatbotPeso(ADVICE_LOW_CAPITAL)],
        ],
    ];
}

function chatbotAdviseSubscription(mysqli $conn, array $ctx): ?array
{
    $stmt = $conn->prepare("
        SELECT expiry_date, DATEDIFF(expiry_date, CURDATE()) AS days_left
        FROM company_subscriptions
        WHERE company_id = ? AND status IN ('Active', 'Trial')
        ORDER BY expiry_date DESC
        LIMIT 1
    ");
    $stmt->bind_param("i", $ctx['company_id']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return null;
    }

    $daysLeft = (int) $row['days_left'];

    if ($daysLeft > ADVICE_SUBSCRIPTION_DAYS || $daysLeft < 0) {
        return null;
    }

    return [
        'message' => 'Your subscription renews soon.',
        'evidence' => [
            ['Days left', (string) $daysLeft],
            ['Renews on', (string) $row['expiry_date']],
        ],
    ];
}
