<?php
/*
| The asker's own records.
|
| Both statements bind company_id AND the asker's employee_id. Either alone
| would be a leak: company_id without employee_id shows a colleague's record,
| employee_id without company_id trusts an id that may belong to another tenant.
*/

function chatToolMyAttendance(mysqli $conn, array $ctx, array $in): array
{
    $range = chatResolvePeriod($in['period'], $in['from'] ?? null, $in['to'] ?? null);

    $stmt = $conn->prepare("
        SELECT attendance_date,
               TIME(time_in) AS time_in,
               TIME(time_out) AS time_out,
               status,
               COALESCE(late_minutes, 0) AS late_minutes
        FROM attendance
        WHERE company_id = ? AND employee_id = ?
          AND attendance_date BETWEEN ? AND ?
        ORDER BY attendance_date DESC
        LIMIT 51
    ");
    $stmt->bind_param("iiss", $ctx['company_id'], $ctx['employee_id'],
        $range['from'], $range['to']);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];

    while ($row = $result->fetch_assoc()) {
        $rows[] = array_map(static fn ($value): string => (string) $value, array_values($row));
    }

    $stmt->close();

    return ['columns' => ['date', 'time_in', 'time_out', 'status', 'late_minutes'],
            'rows' => $rows];
}

function chatToolMyLeave(mysqli $conn, array $ctx, array $in): array
{
    $status = (string) $in['status'];

    if ($status === 'all') {
        $stmt = $conn->prepare("
            SELECT leave_type, start_date, end_date, hr_status, admin_status, created_at
            FROM leave_requests
            WHERE company_id = ? AND employee_id = ?
            ORDER BY created_at DESC
            LIMIT 51
        ");
        $stmt->bind_param("ii", $ctx['company_id'], $ctx['employee_id']);
    } else {
        $stmt = $conn->prepare("
            SELECT leave_type, start_date, end_date, hr_status, admin_status, created_at
            FROM leave_requests
            WHERE company_id = ? AND employee_id = ? AND hr_status = ?
            ORDER BY created_at DESC
            LIMIT 51
        ");
        $stmt->bind_param("iis", $ctx['company_id'], $ctx['employee_id'], $status);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];

    while ($row = $result->fetch_assoc()) {
        $rows[] = array_map(static fn ($value): string => (string) $value, array_values($row));
    }

    $stmt->close();

    return ['columns' => ['leave_type', 'start_date', 'end_date', 'hr_status',
                          'admin_status', 'requested_at'],
            'rows' => $rows];
}
