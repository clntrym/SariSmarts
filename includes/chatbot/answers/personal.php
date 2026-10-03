<?php
/*
| Answers about the asker's own records.
|
| Both statements bind company_id and the asker's own employee_id. Either one
| alone would be a leak: company_id without employee_id shows a colleague's
| record, employee_id without company_id trusts an id from another tenant.
*/

function chatbotMyAttendanceToday(mysqli $conn, array $ctx, string $question): array
{
    $stmt = $conn->prepare("
        SELECT time_in, time_out, status, late_minutes
        FROM attendance
        WHERE company_id = ? AND employee_id = ? AND attendance_date = CURDATE()
        LIMIT 1
    ");
    $stmt->bind_param("ii", $ctx['company_id'], $ctx['employee_id']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return [
            'title' => 'My attendance today',
            'lines' => [],
            'table' => null,
            'link' => null,
            'note' => 'No attendance recorded for today yet.',
        ];
    }

    /* time_in and time_out are DATETIMEs; the date is today's, so showing the
       whole stamp would repeat what the question already said. */
    $clock = static function (?string $stamp, string $blank): string {
        if ($stamp === null || $stamp === '' || str_starts_with($stamp, '0000')) {
            return $blank;
        }

        $time = strtotime($stamp);

        return $time === false ? $blank : date('H:i', $time);
    };

    return [
        'title' => 'My attendance today',
        'lines' => [
            ['Time in', $clock($row['time_in'] ?? null, '—')],
            ['Time out', $clock($row['time_out'] ?? null, 'Not yet')],
            ['Status', (string) ($row['status'] ?? '—')],
            ['Late (minutes)', (string) (int) ($row['late_minutes'] ?? 0)],
        ],
        'table' => null,
        'link' => null,
    ];
}

function chatbotMyLeaveStatus(mysqli $conn, array $ctx, string $question): array
{
    $stmt = $conn->prepare("
        SELECT leave_type, start_date, end_date, hr_status, admin_status
        FROM leave_requests
        WHERE company_id = ? AND employee_id = ?
        ORDER BY created_at DESC
        LIMIT 5
    ");
    $stmt->bind_param("ii", $ctx['company_id'], $ctx['employee_id']);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];

    while ($row = $result->fetch_assoc()) {
        $rows[] = [
            $row['leave_type'],
            $row['start_date'] . ' — ' . $row['end_date'],
            'HR: ' . $row['hr_status'] . ' / Admin: ' . $row['admin_status'],
        ];
    }

    $stmt->close();

    return [
        'title' => 'My leave requests',
        'lines' => [],
        'table' => $rows
            ? ['columns' => ['Type', 'Dates', 'Status'], 'rows' => $rows]
            : null,
        'link' => null,
        'note' => $rows ? null : 'You have no leave requests on record.',
    ];
}
