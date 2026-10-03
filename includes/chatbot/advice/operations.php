<?php
/*
| Finance and Inventory advisories: money waiting to move, and goods waiting
| to arrive.
|
| chatbotAdvisePayrollWaiting reads the payroll table. That is allowed here for
| the same reason the Finance keyword answers are: this file is under
| includes/chatbot/, not under chat/tools, so nothing it reads is ever sent to
| a model. See the conversational spec, "Payroll: answerable, but never by the
| AI".
*/

function chatbotAdvisePayrollWaiting(mysqli $conn, array $ctx): ?array
{
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS waiting,
               COALESCE(MAX(DATEDIFF(NOW(), created_at)), 0) AS oldest_days
        FROM payroll
        WHERE company_id = ? AND status = 'Pending Approval'
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
        'message' => $waiting . ' payslip(s) are waiting for approval.',
        'evidence' => [
            ['Waiting', (string) $waiting],
            ['Oldest', (int) $row['oldest_days'] . ' days'],
        ],
    ];
}

function chatbotAdviseFinanceRequests(mysqli $conn, array $ctx): ?array
{
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS waiting, COALESCE(SUM(total_price), 0) AS amount
        FROM stock_requests
        WHERE company_id = ? AND status = 'Pending Finance'
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
        'message' => $waiting . ' stock request(s) are waiting for finance.',
        'evidence' => [
            ['Waiting', (string) $waiting],
            ['Amount', chatbotPeso((float) $row['amount'])],
        ],
    ];
}

function chatbotAdvisePayablesDue(mysqli $conn, array $ctx): ?array
{
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS due,
               COALESCE(SUM(amount - paid_amount), 0) AS balance,
               COALESCE(SUM(due_date < CURDATE()), 0) AS overdue
        FROM accounts_payable
        WHERE company_id = ?
          AND status IN ('Pending', 'Partial')
          AND due_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
    ");
    $days = ADVICE_PAYABLE_DUE_DAYS;
    $stmt->bind_param("ii", $ctx['company_id'], $days);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $due = (int) $row['due'];

    if ($due === 0) {
        return null;
    }

    return [
        'message' => $due . ' supplier bill(s) fall due within a week.',
        'evidence' => [
            ['Falling due', (string) $due],
            ['Already overdue', (string) (int) $row['overdue']],
            ['Amount', chatbotPeso((float) $row['balance'])],
        ],
    ];
}

function chatbotAdviseDeliveriesReady(mysqli $conn, array $ctx): ?array
{
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS ready, COALESCE(SUM(total_price), 0) AS amount
        FROM stock_requests
        WHERE company_id = ? AND status = 'Admin Approved'
    ");
    $stmt->bind_param("i", $ctx['company_id']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $ready = (int) $row['ready'];

    if ($ready === 0) {
        return null;
    }

    return [
        'message' => $ready . ' approved stock request(s) are ready to receive.',
        'evidence' => [
            ['Ready to receive', (string) $ready],
            ['Amount', chatbotPeso((float) $row['amount'])],
        ],
    ];
}
