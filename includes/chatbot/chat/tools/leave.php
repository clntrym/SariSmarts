<?php
/*
| Leave requests, company-wide.
|
| The 'reason' column is deliberately not selected: an overview of who is off
| does not need to carry why, and "Lagnat" or a family matter is the employee's
| to tell. The approval pages show it to the people who decide.
*/

function chatToolLeaveRequests(mysqli $conn, array $ctx, array $in): array
{
    $limit = min((int) ($in['limit'] ?? CHAT_TOOL_ROW_CAP), CHAT_TOOL_ROW_CAP) + 1;
    $status = (string) $in['status'];

    $sql = "
        SELECT CONCAT(e.first_name, ' ', e.last_name) AS employee,
               l.leave_type, l.duration, l.start_date, l.end_date,
               l.hr_status, l.admin_status
        FROM leave_requests l
        JOIN employees e ON e.employee_id = l.employee_id AND e.company_id = l.company_id
        WHERE l.company_id = ?
    ";

    if ($status !== 'all') {
        $sql .= " AND l.hr_status = ? ";
    }

    $sql .= " ORDER BY l.created_at DESC LIMIT ? ";

    $stmt = $conn->prepare($sql);

    if ($status === 'all') {
        $stmt->bind_param("ii", $ctx['company_id'], $limit);
    } else {
        $stmt->bind_param("isi", $ctx['company_id'], $status, $limit);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];

    while ($row = $result->fetch_assoc()) {
        $rows[] = array_map(static fn ($value): string => (string) $value, array_values($row));
    }

    $stmt->close();

    return ['columns' => ['employee', 'leave_type', 'duration', 'start_date', 'end_date',
                          'hr_status', 'admin_status'],
            'rows' => $rows];
}
