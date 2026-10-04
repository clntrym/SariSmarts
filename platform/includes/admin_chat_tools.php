<?php
/*
|--------------------------------------------------------------------------
| WHAT THE PLATFORM'S ASSISTANT MAY READ
|--------------------------------------------------------------------------
|
| The tenant assistant answers about one company and proves it by binding
| company_id into every query. This one is the mirror image: it answers
| ACROSS companies, because the operator's job is the platform, not a shop.
|
| That makes the role gate the entire safety story. A tenant reaching these
| tools would read every other business on RetailCore in a single question --
| their revenue, their owner's email, their approval status. So the catalogue
| is closed to everything except the platform's own roles, and it is closed
| twice: once when the catalogue is built, and again in the runner, because a
| request names a tool and nothing stops it naming one it was not offered.
|
| The second rule is carried over on purpose. The tenant assistant may not see
| salaries; RetailCore's own staff get the same treatment. platform_employees
| is readable for headcount and never for what anybody earns.
|
| Every query here is a SELECT. Nothing in this file writes.
*/

require_once __DIR__ . '/platform_roles.php';

const ADMIN_CHAT_ROW_CAP = 40;

/**
 * The tools a platform role may use.
 *
 * A role that is not a platform role gets an empty catalogue -- not a
 * narrower one, an empty one. There is no tenant question this file answers.
 */
function adminChatToolsFor(string $role): array
{
    $role = strtolower(trim($role));
    $known = platformRoles();

    if (!array_key_exists($role, $known)) {
        return [];
    }

    $all = adminChatTools();
    $allowed = [];

    foreach ($all as $name => $tool) {
        if (in_array($role, $tool['roles'], true)) {
            $allowed[$name] = $tool;
        }
    }

    return $allowed;
}

/**
 * The catalogue.
 *
 * 'roles' uses the slugs from platform_roles.php, deliberately not 'hr' or
 * 'finance' -- those are tenant roles and reusing them here is exactly the
 * mistake that registry exists to prevent.
 */
function adminChatTools(): array
{
    $everyone = ['super admin', 'marketing hr', 'platform finance'];

    return [

        'platform_summary' => [
            'roles' => $everyone,
            'description' => 'How many businesses are on the platform, by status, '
                . 'and how many signed up recently.',
            'input' => [],
            'handler' => 'adminChatPlatformSummary',
        ],

        'company_list' => [
            'roles' => ['super admin', 'marketing hr'],
            'description' => 'The businesses on the platform, newest first, with '
                . 'their status and plan. Filter by status to see only those '
                . 'waiting for review.',
            'input' => [
                'status' => [
                    'type' => 'enum',
                    'values' => ['all', 'Pending', 'Active', 'Rejected', 'Suspended', 'Inactive'],
                ],
                'limit' => ['type' => 'int', 'min' => 1, 'max' => ADMIN_CHAT_ROW_CAP],
            ],
            'handler' => 'adminChatCompanyList',
        ],

        'review_queue' => [
            'roles' => ['super admin'],
            'description' => 'Businesses waiting for approval, oldest first, with '
                . 'how long they have been waiting.',
            'input' => [],
            'handler' => 'adminChatReviewQueue',
        ],

        'revenue_summary' => [
            'roles' => ['super admin', 'platform finance'],
            'description' => 'Subscription revenue: what is being paid, by plan and '
                . 'by billing cycle, and how much is unpaid.',
            'input' => [],
            'handler' => 'adminChatRevenueSummary',
        ],

        'subscriptions_expiring' => [
            'roles' => ['super admin', 'platform finance'],
            'description' => 'Subscriptions expiring within a number of days, so '
                . 'renewals can be chased before they lapse.',
            'input' => [
                'days' => ['type' => 'int', 'min' => 1, 'max' => 180],
            ],
            'handler' => 'adminChatSubscriptionsExpiring',
        ],

        'plan_breakdown' => [
            'roles' => $everyone,
            'description' => 'How many businesses are on each plan.',
            'input' => [],
            'handler' => 'adminChatPlanBreakdown',
        ],

        'lead_pipeline' => [
            'roles' => ['super admin', 'marketing hr'],
            'description' => 'Marketing leads by stage, and the most recent ones, '
                . 'so follow-up can be prioritised.',
            'input' => [
                'limit' => ['type' => 'int', 'min' => 1, 'max' => ADMIN_CHAT_ROW_CAP],
            ],
            'handler' => 'adminChatLeadPipeline',
        ],

        'support_queue' => [
            'roles' => ['super admin', 'marketing hr'],
            'description' => 'Support tickets that are still open, oldest first.',
            'input' => [],
            'handler' => 'adminChatSupportQueue',
        ],

        'landing_chat_activity' => [
            'roles' => ['super admin', 'marketing hr'],
            'description' => 'How many visitors used the website chat recently and '
                . 'how many of them left contact details.',
            'input' => [
                'days' => ['type' => 'int', 'min' => 1, 'max' => 90],
            ],
            'handler' => 'adminChatLandingActivity',
        ],

        'platform_headcount' => [
            'roles' => ['super admin', 'marketing hr'],
            'description' => 'RetailCore\'s own staff: how many, by status. '
                . 'Headcount only.',
            'input' => [],
            'handler' => 'adminChatPlatformHeadcount',
        ],
    ];
}

/**
 * Run one tool, if this role is allowed it.
 *
 * The gate is applied again here on purpose. The catalogue decides what a
 * role is OFFERED; a request decides what it ASKS for, and nothing stops it
 * asking for something it was never shown.
 */
function adminChatRunTool(mysqli $conn, string $role, string $name, array $input): array
{
    $allowed = adminChatToolsFor($role);

    if (!isset($allowed[$name])) {
        return ['ok' => false, 'error' => 'No such tool.', 'columns' => [], 'rows' => []];
    }

    $tool = $allowed[$name];
    $clean = [];

    foreach ($tool['input'] as $param => $spec) {

        $value = $input[$param] ?? null;

        if ($value === null || $value === '') {
            continue;
        }

        if ($spec['type'] === 'enum') {
            if (!in_array($value, $spec['values'], true)) {
                return ['ok' => false, 'error' => $param . ' is not one of the allowed values.',
                        'columns' => [], 'rows' => []];
            }
            $clean[$param] = $value;
        } elseif ($spec['type'] === 'int') {
            $n = (int) $value;
            $clean[$param] = max($spec['min'], min($spec['max'], $n));
        } else {
            $clean[$param] = (string) $value;
        }
    }

    try {
        return ($tool['handler'])($conn, $clean) + ['ok' => true];
    } catch (Throwable $error) {
        error_log('admin chat tool ' . $name . ': ' . $error->getMessage());

        return ['ok' => false, 'error' => 'That could not be read right now.',
                'columns' => [], 'rows' => []];
    }
}

/* ------------------------------------------------------------------ tools */

function adminChatPlatformSummary(mysqli $conn, array $input): array
{
    $r = $conn->query("
        SELECT
            COUNT(*) AS total,
            COALESCE(SUM(status = 'Active'), 0) AS active,
            COALESCE(SUM(status = 'Pending'), 0) AS pending,
            COALESCE(SUM(status = 'Rejected'), 0) AS rejected,
            COALESCE(SUM(status = 'Suspended'), 0) AS suspended,
            COALESCE(SUM(created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)), 0) AS new_30_days
        FROM company
    ");

    $row = $r->fetch_assoc();

    return [
        'columns' => array_keys($row),
        'rows' => [array_values($row)],
        'truncated' => false,
    ];
}

function adminChatCompanyList(mysqli $conn, array $input): array
{
    $limit = (int) ($input['limit'] ?? 20);
    $status = (string) ($input['status'] ?? 'all');

    $sql = "
        SELECT c.company_name, c.owner_name, c.status, c.city,
               COALESCE(p.plan_name, '-') AS plan,
               DATE(c.created_at) AS joined
        FROM company c
        LEFT JOIN company_subscriptions s ON s.company_id = c.company_id
        LEFT JOIN subscription_plans p ON p.plan_id = s.plan_id
    ";

    if ($status !== 'all') {
        $sql .= " WHERE c.status = ? ";
    }

    $sql .= " GROUP BY c.company_id ORDER BY c.created_at DESC LIMIT ?";

    $stmt = $conn->prepare($sql);

    if ($status !== 'all') {
        $stmt->bind_param("si", $status, $limit);
    } else {
        $stmt->bind_param("i", $limit);
    }

    return adminChatRows($stmt, $limit);
}

function adminChatReviewQueue(mysqli $conn, array $input): array
{
    $stmt = $conn->prepare("
        SELECT company_name, owner_name, email, city,
               DATE(submitted_at) AS submitted,
               DATEDIFF(NOW(), submitted_at) AS days_waiting
        FROM company
        WHERE status = 'Pending'
        ORDER BY submitted_at ASC
        LIMIT ?
    ");

    $cap = ADMIN_CHAT_ROW_CAP;
    $stmt->bind_param("i", $cap);

    return adminChatRows($stmt, $cap);
}

function adminChatRevenueSummary(mysqli $conn, array $input): array
{
    $stmt = $conn->prepare("
        SELECT COALESCE(p.plan_name, '-') AS plan,
               s.billing_cycle,
               COUNT(*) AS subscriptions,
               COALESCE(SUM(s.amount), 0) AS total_amount,
               COALESCE(SUM(s.payment_status = 'Paid'), 0) AS paid,
               COALESCE(SUM(s.payment_status <> 'Paid'), 0) AS unpaid
        FROM company_subscriptions s
        LEFT JOIN subscription_plans p ON p.plan_id = s.plan_id
        WHERE s.status IN ('Active', 'Trial')
        GROUP BY s.plan_id, s.billing_cycle
        ORDER BY total_amount DESC
        LIMIT ?
    ");

    $cap = ADMIN_CHAT_ROW_CAP;
    $stmt->bind_param("i", $cap);

    return adminChatRows($stmt, $cap);
}

function adminChatSubscriptionsExpiring(mysqli $conn, array $input): array
{
    $days = (int) ($input['days'] ?? 30);

    $stmt = $conn->prepare("
        SELECT c.company_name,
               COALESCE(p.plan_name, '-') AS plan,
               s.billing_cycle,
               s.amount,
               DATE(s.expiry_date) AS expires,
               DATEDIFF(s.expiry_date, CURDATE()) AS days_left
        FROM company_subscriptions s
        JOIN company c ON c.company_id = s.company_id
        LEFT JOIN subscription_plans p ON p.plan_id = s.plan_id
        WHERE s.status IN ('Active', 'Trial')
          AND s.expiry_date IS NOT NULL
          AND s.expiry_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
        ORDER BY s.expiry_date ASC
        LIMIT ?
    ");

    $cap = ADMIN_CHAT_ROW_CAP;
    $stmt->bind_param("ii", $days, $cap);

    return adminChatRows($stmt, $cap);
}

function adminChatPlanBreakdown(mysqli $conn, array $input): array
{
    $stmt = $conn->prepare("
        SELECT p.plan_name,
               COUNT(s.subscription_id) AS businesses,
               COALESCE(SUM(s.status IN ('Active', 'Trial')), 0) AS active
        FROM subscription_plans p
        LEFT JOIN company_subscriptions s ON s.plan_id = p.plan_id
        GROUP BY p.plan_id
        ORDER BY p.plan_order ASC
        LIMIT ?
    ");

    $cap = ADMIN_CHAT_ROW_CAP;
    $stmt->bind_param("i", $cap);

    return adminChatRows($stmt, $cap);
}

function adminChatLeadPipeline(mysqli $conn, array $input): array
{
    $limit = (int) ($input['limit'] ?? 20);

    $stmt = $conn->prepare("
        SELECT business_name, contact_name, contact_email, city,
               stage, source, DATE(created_at) AS created
        FROM marketing_leads
        ORDER BY created_at DESC
        LIMIT ?
    ");

    $stmt->bind_param("i", $limit);

    return adminChatRows($stmt, $limit);
}

function adminChatSupportQueue(mysqli $conn, array $input): array
{
    $stmt = $conn->prepare("
        SELECT ticket_code, contact_name, subject, category, priority, status,
               DATEDIFF(NOW(), created_at) AS days_open
        FROM support_tickets
        WHERE status <> 'Resolved' AND status <> 'Closed'
        ORDER BY created_at ASC
        LIMIT ?
    ");

    $cap = ADMIN_CHAT_ROW_CAP;
    $stmt->bind_param("i", $cap);

    return adminChatRows($stmt, $cap);
}

function adminChatLandingActivity(mysqli $conn, array $input): array
{
    $days = (int) ($input['days'] ?? 7);

    $stmt = $conn->prepare("
        SELECT COUNT(*) AS conversations,
               COALESCE(SUM(message_count), 0) AS messages,
               COALESCE(SUM(lead_id IS NOT NULL), 0) AS left_contact_details
        FROM landing_chats
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
    ");

    $stmt->bind_param("i", $days);

    return adminChatRows($stmt, 1);
}

function adminChatPlatformHeadcount(mysqli $conn, array $input): array
{
    /* Headcount, by status. No pay column is read here, by the same rule that
       keeps salaries away from the tenant assistant. */
    $stmt = $conn->prepare("
        SELECT status, COUNT(*) AS people
        FROM platform_employees
        GROUP BY status
        ORDER BY people DESC
        LIMIT ?
    ");

    $cap = ADMIN_CHAT_ROW_CAP;
    $stmt->bind_param("i", $cap);

    return adminChatRows($stmt, $cap);
}

/**
 * A prepared statement's result, as columns and rows.
 */
function adminChatRows(mysqli_stmt $stmt, int $limit): array
{
    $stmt->execute();
    $result = $stmt->get_result();

    $columns = [];
    $rows = [];

    while ($row = $result->fetch_assoc()) {

        if ($columns === []) {
            $columns = array_keys($row);
        }

        $rows[] = array_map(static fn($v): string => (string) $v, array_values($row));
    }

    $stmt->close();

    return [
        'columns' => $columns,
        'rows' => $rows,
        'truncated' => count($rows) >= $limit,
    ];
}
