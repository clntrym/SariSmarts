<?php
/*
| The asker's own advisories.
|
| Both bind company_id AND the asker's employee_id: a reminder about somebody
| else's timesheet is not a reminder, it is a leak.
*/

function chatbotAdviseMissingTimeOut(mysqli $conn, array $ctx): ?array
{
    $stmt = $conn->prepare("
        SELECT TIME(time_in) AS time_in
        FROM attendance
        WHERE company_id = ? AND employee_id = ?
          AND attendance_date = CURDATE()
          AND time_in IS NOT NULL
          AND time_out IS NULL
        LIMIT 1
    ");
    $stmt->bind_param("ii", $ctx['company_id'], $ctx['employee_id']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return null;
    }

    return [
        'message' => 'You have not timed out today.',
        'evidence' => [
            ['Timed in at', (string) $row['time_in']],
            ['Timed out', 'Not yet'],
        ],
    ];
}

function chatbotAdviseMyLeavePending(mysqli $conn, array $ctx): ?array
{
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS pending,
               COALESCE(MAX(DATEDIFF(NOW(), created_at)), 0) AS oldest_days
        FROM leave_requests
        WHERE company_id = ? AND employee_id = ?
          AND (hr_status = 'Pending' OR admin_status = 'Pending')
    ");
    $stmt->bind_param("ii", $ctx['company_id'], $ctx['employee_id']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $pending = (int) $row['pending'];

    if ($pending === 0) {
        return null;
    }

    return [
        'message' => 'Your leave request is still pending.',
        'evidence' => [
            ['Pending requests', (string) $pending],
            ['Waiting', (int) $row['oldest_days'] . ' days'],
        ],
    ];
}
