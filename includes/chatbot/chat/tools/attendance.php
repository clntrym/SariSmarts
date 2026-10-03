<?php
/*
| Attendance tools. Times, lateness and absence -- never what any of it is
| worth, which is payroll's business and has no tool here.
*/

function chatToolAttendanceSummary(mysqli $conn, array $ctx, array $in): array
{
    $range = chatResolvePeriod($in['period'], $in['from'] ?? null, $in['to'] ?? null);
    $limit = min((int) ($in['limit'] ?? CHAT_TOOL_ROW_CAP), CHAT_TOOL_ROW_CAP) + 1;

    $stmt = $conn->prepare("
        SELECT CONCAT(e.first_name, ' ', e.last_name) AS employee,
               SUM(a.status = 'Present') AS days_present,
               SUM(a.status = 'Late') AS days_late,
               SUM(a.status = 'Absent') AS days_absent,
               SUM(a.status = 'Half Day') AS days_half,
               COALESCE(SUM(a.late_minutes), 0) AS late_minutes
        FROM attendance a
        JOIN employees e ON e.employee_id = a.employee_id AND e.company_id = a.company_id
        WHERE a.company_id = ? AND a.attendance_date BETWEEN ? AND ?
        GROUP BY e.employee_id, e.first_name, e.last_name
        ORDER BY late_minutes DESC, employee
        LIMIT ?
    ");
    $stmt->bind_param("issi", $ctx['company_id'], $range['from'], $range['to'], $limit);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];

    while ($row = $result->fetch_assoc()) {
        $rows[] = array_map(static fn ($value): string => (string) $value, array_values($row));
    }

    $stmt->close();

    return ['columns' => ['employee', 'days_present', 'days_late', 'days_absent',
                          'days_half', 'late_minutes'],
            'rows' => $rows];
}

function chatToolAttendanceDetail(mysqli $conn, array $ctx, array $in): array
{
    $range = chatResolvePeriod($in['period'], $in['from'] ?? null, $in['to'] ?? null);

    /* The name comes from the model as free text: kept as typed, wildcards
       escaped, and nothing found when there is nothing to search for. */
    $like = chatLikeTerm((string) $in['employee']);

    if ($like === null) {
        return ['columns' => ['date', 'time_in', 'time_out', 'status', 'late_minutes'],
                'rows' => []];
    }

    $stmt = $conn->prepare("
        SELECT a.attendance_date,
               TIME(a.time_in) AS time_in,
               TIME(a.time_out) AS time_out,
               a.status,
               COALESCE(a.late_minutes, 0) AS late_minutes
        FROM attendance a
        JOIN employees e ON e.employee_id = a.employee_id AND e.company_id = a.company_id
        WHERE a.company_id = ?
          AND a.attendance_date BETWEEN ? AND ?
          AND CONCAT(e.first_name, ' ', e.last_name) LIKE ?
        ORDER BY a.attendance_date DESC
        LIMIT 51
    ");
    $stmt->bind_param("isss", $ctx['company_id'], $range['from'], $range['to'], $like);
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
