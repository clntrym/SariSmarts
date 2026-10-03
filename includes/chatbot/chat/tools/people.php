<?php
/*
| People tools.
|
| Columns are named one by one, and none of them is pay. employment.salary sits
| one join away from everything here, so the rule is enforced by never writing
| the join -- and by the audit, which fails this file if the word appears.
*/

function chatToolStaffList(mysqli $conn, array $ctx, array $in): array
{
    $limit = min((int) ($in['limit'] ?? CHAT_TOOL_ROW_CAP), CHAT_TOOL_ROW_CAP) + 1;

    /* Built from a validated enum, never from model text. */
    $condition = $in['state'] === 'active'
        ? "e.employment_status <> 'Archived'"
        : '1 = 1';

    $stmt = $conn->prepare("
        SELECT CONCAT(e.first_name, ' ', e.last_name) AS employee,
               e.employment_status,
               COALESCE(b.branch_name, 'No branch') AS branch,
               COALESCE(u.role, 'No account') AS system_role
        FROM employees e
        LEFT JOIN branch b ON b.branch_id = e.branch_id AND b.company_id = e.company_id
        LEFT JOIN users u ON u.employee_id = e.employee_id AND u.company_id = e.company_id
        WHERE e.company_id = ? AND {$condition}
        ORDER BY e.last_name, e.first_name
        LIMIT ?
    ");
    $stmt->bind_param("ii", $ctx['company_id'], $limit);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];

    while ($row = $result->fetch_assoc()) {
        $rows[] = array_map(static fn ($value): string => (string) $value, array_values($row));
    }

    $stmt->close();

    return ['columns' => ['employee', 'employment_status', 'branch', 'system_role'],
            'rows' => $rows];
}

function chatToolCompanyProfile(mysqli $conn, array $ctx, array $in): array
{
    /* Named columns only: this table also holds the approval token, the TIN and
       the uploaded permit paths, none of which belong in a chat answer. */
    $stmt = $conn->prepare("
        SELECT c.company_name, c.city, c.province, c.business_size,
               COALESCE(p.plan_name, 'No active plan') AS plan_name,
               (SELECT COUNT(*) FROM branch b WHERE b.company_id = c.company_id) AS branches,
               (SELECT COUNT(*) FROM employees e
                WHERE e.company_id = c.company_id
                  AND e.employment_status <> 'Archived') AS active_staff
        FROM company c
        LEFT JOIN company_subscriptions cs
               ON cs.company_id = c.company_id AND cs.status IN ('Active', 'Trial')
        LEFT JOIN subscription_plans p ON p.plan_id = cs.plan_id
        WHERE c.company_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $ctx['company_id']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return ['columns' => [], 'rows' => []];
    }

    return [
        'columns' => ['business', 'city', 'province', 'business_size', 'plan',
                      'branches', 'active_staff'],
        'rows' => [array_map(static fn ($value): string => (string) $value, array_values($row))],
    ];
}
