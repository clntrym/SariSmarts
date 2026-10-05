<?php
/*
|--------------------------------------------------------------------------
| WHAT EACH PLAN ACTUALLY SELLS, ON THIS DATABASE
|--------------------------------------------------------------------------
|
| companyPlanRoles() has a deliberate safety net: a plan that grants no
| roles falls back to every role, rather than locking an owner out of hiring
| anybody. companyHasModule() has the matching one: a module no plan claims
| is denied to nobody.
|
| Both are right, and both are silent. A production database missing its
| subscription_plan_roles rows looks exactly like a working one -- except
| that every company, on every plan, is quietly given everything: the Retail
| Starter owner sees HRMS and Finance in their sidebar and HR Officer in
| their role dropdown, and nothing anywhere says why.
|
| The code enforces the plan. This says whether the plan says anything.
|
|     php tools/plan_status.php
|     php tools/plan_status.php --local
|
| It also runs in a browser, because Render's free instances have no shell:
|
|     /tools/plan_status.php
|
| Super Admin only there -- what it prints is the commercial shape of the
| product. It is READ ONLY and changes nothing.
*/

$viaBrowser = PHP_SAPI !== 'cli';

if ($viaBrowser) {

    require_once __DIR__ . '/../init.php';

    header('Content-Type: text/plain; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store');

    if (strtolower(trim((string) ($_SESSION['role'] ?? ''))) !== 'super admin') {
        http_response_code(403);
        echo "This page is for the Super Admin.\n";
        exit;
    }

    $where = 'this deployment';

} else {

    mysqli_report(MYSQLI_REPORT_OFF);

    if (in_array('--local', $argv, true)) {
        $conn = new mysqli('localhost', 'root', '', 'sari');
        $where = 'local XAMPP';
    } else {
        $conn = mysqli_init();
        $conn->options(MYSQLI_OPT_CONNECT_TIMEOUT, 20);
        @$conn->real_connect(
            getenv('DB_HOST') ?: 'sarismarts-db.mysql.database.azure.com',
            getenv('DB_USER') ?: 'sariAdmin',
            (string) getenv('DB_PASS'),
            getenv('DB_NAME') ?: 'sari',
            3306
        );
        $where = 'production';
    }

    if ($conn->connect_error) {
        echo "cannot reach {$where}: {$conn->connect_error}\n";
        exit(1);
    }

    $conn->set_charset('utf8mb4');
}

function planLine(string $label, bool $ok, string $detail = ''): void
{
    echo '  [', $ok ? 'OK  ' : 'WARN', '] ', str_pad($label, 24), $detail, "\n";
}

echo "\nRetailCore plans -- ", $where, "\n\n";

/* ------------------------------------------------------------- the plans */

$plans = $conn->query("
    SELECT plan_id, plan_name, max_branches, max_users
    FROM subscription_plans
    ORDER BY plan_id
");

if (!$plans || $plans->num_rows === 0) {
    echo "  There are no subscription plans on this database at all.\n";
    echo "  Nothing can be sold and every company falls back to everything.\n\n";
    exit(1);
}

$anyRoles = false;
$problems = [];

while ($plan = $plans->fetch_assoc()) {

    $id = (int) $plan['plan_id'];

    echo '  ', $plan['plan_name'], "\n";

    /* Roles. */
    $roles = [];
    $r = $conn->query("
        SELECT system_role FROM subscription_plan_roles
        WHERE plan_id = {$id} AND system_role IS NOT NULL AND system_role <> ''
        ORDER BY system_role
    ");

    while ($r && $row = $r->fetch_assoc()) {
        $roles[] = strtolower(trim((string) $row['system_role']));
    }

    if ($roles !== []) {
        $anyRoles = true;
    }

    /*
    | Rows with no slug are the likely shape of the fault, not missing rows.
    |
    | subscription_plan_roles has always carried role_name, a marketing
    | label that platform/pricing.php renders -- "HR Officer", "Cashier".
    | plan_role_access.sql added system_role beside it, which is what
    | RetailCore authorises against. A database where that migration never
    | ran has every row present and every slug NULL: the pricing page is
    | perfect and the system grants everything.
    */
    $labelled = 0;
    $r = $conn->query("SELECT COUNT(*) AS n FROM subscription_plan_roles WHERE plan_id = {$id}");

    if ($r) {
        $labelled = (int) $r->fetch_assoc()['n'];
    }

    planLine('roles sold', $roles !== [],
        $roles !== []
            ? implode(', ', $roles)
            : ($labelled > 0
                ? 'NONE -- ' . $labelled . ' row(s) exist with no system_role slug'
                : 'NONE -- no rows at all'));

    if ($roles === []) {
        $problems[] = $plan['plan_name'] . ' grants no roles, so its owners see HRMS, '
            . 'Finance and every other department whether or not they bought them.'
            . ($labelled > 0
                ? ' Its ' . $labelled . ' row(s) are there but carry no system_role:'
                    . ' plan_role_access.sql has not been run here.'
                : '');
    }

    /* Modules. */
    $modules = [];
    $r = $conn->query("
        SELECT system_module FROM subscription_plan_features
        WHERE plan_id = {$id} AND system_module IS NOT NULL AND system_module <> ''
        ORDER BY system_module
    ");

    while ($r && $row = $r->fetch_assoc()) {
        $modules[] = strtolower(trim((string) $row['system_module']));
    }

    planLine('modules sold', true, $modules !== [] ? implode(', ', $modules) : '(none)');

    /* Ceilings. */
    $branches = $plan['max_branches'];
    $users = $plan['max_users'];

    planLine('max branches', $branches !== null && (int) $branches > 0,
        $branches === null ? 'NULL -- unlimited' : (string) $branches);

    planLine('max users', $users !== null && (int) $users > 0,
        $users === null ? 'NULL -- unlimited' : (string) $users);

    if ($branches === null) {
        $problems[] = $plan['plan_name'] . ' has no branch ceiling, so its owners '
            . 'can open as many branches as they like.';
    }

    echo "\n";
}

/* --------------------------------------------- what companies are on them */

echo "Companies, and what their plan gives them\n\n";

$companies = $conn->query("
    SELECT c.company_id, c.company_name, p.plan_name, cs.status
    FROM company c
    LEFT JOIN company_subscriptions cs
        ON cs.company_id = c.company_id AND cs.status IN ('Active', 'Trial')
    LEFT JOIN subscription_plans p ON p.plan_id = cs.plan_id
    ORDER BY c.company_id
");

if (!$companies || $companies->num_rows === 0) {
    echo "  (no companies)\n\n";
} else {

    while ($row = $companies->fetch_assoc()) {

        $onPlan = $row['plan_name'] !== null;

        planLine((string) $row['company_id'], $onPlan,
            str_pad(mb_substr((string) $row['company_name'], 0, 24), 26)
            . ($onPlan
                ? $row['plan_name'] . ' (' . $row['status'] . ')'
                : 'NO ACTIVE SUBSCRIPTION -- falls back to every role'));
    }

    echo "\n";
}

/* ------------------------------------------------------------- the verdict */

if (!$anyRoles) {
    echo "  No plan on this database grants any role.\n";
    echo "  That is why every owner sees every module: companyPlanRoles()\n";
    echo "  falls back to all five rather than locking somebody out of\n";
    echo "  hiring, and the fallback is silent by design.\n\n";
    echo "  Fix it by running these against this database, in order:\n";
    echo "    platform/database/plan_role_access.sql     (adds system_role)\n";
    echo "    platform/database/enterprise_plan_roles.sql\n\n";
    exit(1);
}

if ($problems) {
    echo "  Worth looking at\n\n";

    foreach ($problems as $problem) {
        echo "    - ", $problem, "\n";
    }

    echo "\n";
    exit(1);
}

echo "  Every plan sells a named set of roles and has a branch ceiling.\n\n";
