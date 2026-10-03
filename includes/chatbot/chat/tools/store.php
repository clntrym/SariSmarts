<?php
/*
| The shop itself. Named columns: branch also holds coordinates and a geofence
| radius, which belong to the attendance map rather than to a chat answer.
*/

function chatToolBranchList(mysqli $conn, array $ctx, array $in): array
{
    $stmt = $conn->prepare("
        SELECT branch_name, city, province,
               TIME(opening_time) AS opening_time,
               TIME(closing_time) AS closing_time,
               operating_hours, status
        FROM branch
        WHERE company_id = ?
        ORDER BY branch_name
        LIMIT 51
    ");
    $stmt->bind_param("i", $ctx['company_id']);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];

    while ($row = $result->fetch_assoc()) {
        $rows[] = array_map(static fn ($value): string => (string) $value, array_values($row));
    }

    $stmt->close();

    return ['columns' => ['branch', 'city', 'province', 'opens', 'closes',
                          'operating_hours', 'status'],
            'rows' => $rows];
}
