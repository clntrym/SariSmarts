<?php
// init.php

// Start session
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Include database config
require_once __DIR__ . '/config.php';

// One rule for who may use the system, shared by the login page and the
// per-request guard below.
require_once __DIR__ . '/includes/account_access.php';

// Set default timezone
date_default_timezone_set('Asia/Manila');

/*
| What a shop starts with in its capital account.
|
| Named here rather than written into the INSERT, so the one place that
| decides it is the one place to change it. finance_capital.current_capital
| carries the same value as its column DEFAULT; if you change this, change
| that too, or a row created by something other than ensureCompanyCapital()
| will disagree.
*/
if (!defined('STARTING_CAPITAL')) {
    define('STARTING_CAPITAL', 10000.00);
}

// Optional helper functions
if (!function_exists('isLoggedIn')) {
    function isLoggedIn()
    {
        return isset($_SESSION['user_id']);
    }
}

if (!function_exists('redirectIfLoggedIn')) {
    function redirectIfLoggedIn($roleDashboard = '')
    {
        if (isLoggedIn()) {
            if (!empty($roleDashboard)) {
                header("Location: $roleDashboard");
            } else {
                header("Location: index.php");
            }
            exit();
        }
    }
}

if (!function_exists('flash')) {
    function flash($name = '', $message = '', $class = 'alert alert-info')
    {
        if (!empty($name)) {
            if (!empty($message)) {
                $_SESSION[$name] = $message;
                $_SESSION[$name . '_class'] = $class;
            } elseif (!empty($_SESSION[$name])) {
                echo '<div class="' . $_SESSION[$name . '_class'] . '">' . $_SESSION[$name] . '</div>';
                unset($_SESSION[$name]);
                unset($_SESSION[$name . '_class']);
            }
        }
    }
}

if (!function_exists('redirectTo')) {
    function redirectTo($url)
    {
        header("Location: $url");
        exit();
    }
}

/*
|--------------------------------------------------------------------------
| TENANCY
|--------------------------------------------------------------------------
|
| Every operational table carries a company_id. These helpers are the one
| place that decides which company the current request belongs to, so no
| page has to reach into $_SESSION and guess.
|
| currentCompanyId() returns null for Super Admin, who belongs to no
| tenant. requireCompany() is for pages that must never run without one —
| it stops rather than quietly querying across every company.
|
*/

if (!function_exists('currentCompanyId')) {
    function currentCompanyId(): ?int
    {
        return isset($_SESSION['company_id']) && $_SESSION['company_id'] !== null
            ? (int) $_SESSION['company_id']
            : null;
    }
}

/**
 * The name shown in the sidebar and footer.
 *
 * Each tenant sees its own business name rather than the product name.
 * Super Admin belongs to no company, and a staff member whose session
 * predates this falls back to the product name instead of a blank space.
 */
if (!function_exists('currentCompanyName')) {
    function currentCompanyName(string $fallback = 'RetailCore'): string
    {
        $name = trim((string) ($_SESSION['company_name'] ?? ''));

        return $name !== '' ? $name : $fallback;
    }
}

/**
 * The human name for a role slug, matching the wording the pricing page uses
 * so a buyer sees "HR Officer" in both places rather than "hr" in one and
 * "HR Officer" in the other.
 */
if (!function_exists('roleDisplayName')) {
    function roleDisplayName(string $role): string
    {
        $labels = [
            'admin' => 'Owner / Admin',
            'hr' => 'HR Officer',
            'finance' => 'Finance Staff',
            'inventory' => 'Inventory Staff',
            'cashier' => 'Cashier',
            'employee' => 'Employee',
        ];

        $key = strtolower(trim($role));

        return $labels[$key] ?? ucfirst($key);
    }
}

/**
 * The subscription plan a company is currently on, or null if it has none
 * active.
 *
 * Cached for the request: the sidebar alone asks about several modules while
 * it renders, and every one of those would otherwise repeat this join.
 *
 * @return array{plan_id:int,plan_order:int,plan_name:string,inherits_text:string}|null
 */
if (!function_exists('currentCompanyPlan')) {
    function currentCompanyPlan(mysqli $conn, int $companyId): ?array
    {
        static $cache = [];

        if (array_key_exists($companyId, $cache)) {
            return $cache[$companyId];
        }

        $stmt = $conn->prepare("
            SELECT p.plan_id, p.plan_order, p.plan_name, p.inherits_text
            FROM company_subscriptions cs
            JOIN subscription_plans p ON p.plan_id = cs.plan_id
            WHERE cs.company_id = ?
              AND cs.status IN ('Active', 'Trial')
            ORDER BY cs.expiry_date DESC
            LIMIT 1
        ");
        $stmt->bind_param("i", $companyId);
        $stmt->execute();
        $plan = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $cache[$companyId] = ($plan ?: null);
    }
}

/**
 * Every slug a plan grants from one of the plan-entitlement tables.
 *
 * Plans whose copy says "Includes everything in <lower plan> PLUS:" carry a
 * non-empty inherits_text, and the pricing page deliberately leaves their own
 * list short -- Enterprise names no roles at all. So an inheriting plan takes
 * the union of every plan at or below its plan_order.
 *
 * $table and $column are never caller-supplied; the two call sites below pass
 * literals, which is what keeps them safe to interpolate.
 *
 * @return string[] lowercase slugs
 */
if (!function_exists('planGrantedSlugs')) {
    function planGrantedSlugs(mysqli $conn, array $plan, string $table, string $column): array
    {
        $inherits = trim((string) ($plan['inherits_text'] ?? '')) !== '';

        if ($inherits) {
            $sql = "
                SELECT DISTINCT g.$column AS slug
                FROM $table g
                JOIN subscription_plans p ON p.plan_id = g.plan_id
                WHERE p.plan_order <= ?
                  AND g.$column IS NOT NULL AND g.$column <> ''
            ";
            $param = (int) $plan['plan_order'];
        } else {
            $sql = "
                SELECT DISTINCT g.$column AS slug
                FROM $table g
                WHERE g.plan_id = ?
                  AND g.$column IS NOT NULL AND g.$column <> ''
            ";
            $param = (int) $plan['plan_id'];
        }

        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $param);
        $stmt->execute();
        $result = $stmt->get_result();

        $slugs = [];
        while ($row = $result->fetch_assoc()) {
            $slugs[] = strtolower(trim((string) $row['slug']));
        }
        $stmt->close();

        return $slugs;
    }
}

/**
 * The role slugs a company is allowed to hand out, decided by the plan it
 * subscribed to.
 *
 * The set lives in subscription_plan_roles, which is also what
 * platform/pricing.php advertises, so what a buyer is shown on the pricing
 * page is literally what the system enforces afterwards.
 *
 * Two safety nets: a plan nobody configured falls back to every role rather
 * than locking the owner out of hiring, and 'admin' is always present so a
 * company can never lose the ability to manage itself.
 *
 * @return string[] lowercase role slugs, in a stable display order
 */
if (!function_exists('companyPlanRoles')) {
    function companyPlanRoles(mysqli $conn, int $companyId): array
    {
        $allRoles = ['admin', 'hr', 'finance', 'inventory', 'cashier'];

        $plan = currentCompanyPlan($conn, $companyId);

        if (!$plan) {
            return $allRoles;
        }

        $granted = planGrantedSlugs($conn, $plan, 'subscription_plan_roles', 'system_role');

        if (count($granted) === 0) {
            return $allRoles;
        }

        $granted[] = 'admin';

        // Intersecting against $allRoles both drops anything unrecognised and
        // gives the caller a predictable order to render.
        return array_values(array_intersect($allRoles, $granted));
    }
}

/**
 * The module slugs a company's plan opens, read from subscription_plan_features.
 *
 * @return string[] lowercase module slugs
 */
if (!function_exists('companyPlanModules')) {
    function companyPlanModules(mysqli $conn, int $companyId): array
    {
        $plan = currentCompanyPlan($conn, $companyId);

        if (!$plan) {
            return [];
        }

        return planGrantedSlugs($conn, $plan, 'subscription_plan_features', 'system_module');
    }
}

/**
 * Whether a company may open a given module.
 *
 * The rule is deliberately permissive: a module is denied ONLY when some plan
 * explicitly sells it and this company's plan is not one of them. A page that
 * no plan claims -- Dashboard, Inventory, Suppliers, Reports, Settings -- stays
 * open, so adding a page to the app never silently hides it from everybody.
 *
 * A company with no active subscription is not judged here at all; the
 * paywall in requireRole() has already turned it away.
 */
if (!function_exists('companyHasModule')) {
    function companyHasModule(mysqli $conn, int $companyId, string $module): bool
    {
        static $gated = null;

        $module = strtolower(trim($module));

        if ($gated === null) {
            $gated = [];

            $result = $conn->query("
                SELECT DISTINCT system_module
                FROM subscription_plan_features
                WHERE system_module IS NOT NULL AND system_module <> ''
            ");

            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $gated[] = strtolower(trim((string) $row['system_module']));
                }
            }
        }

        // Nobody sells it, so nobody is denied it.
        if (!in_array($module, $gated, true)) {
            return true;
        }

        return in_array($module, companyPlanModules($conn, $companyId), true);
    }
}

/**
 * Stop a request for a module the company's plan does not include.
 *
 * Hiding a link in the sidebar is presentation; this is the part that holds
 * when somebody types the URL.
 */
if (!function_exists('requireModule')) {
    function requireModule(mysqli $conn, int $companyId, string $module, string $label = ''): void
    {
        if (companyHasModule($conn, $companyId, $module)) {
            return;
        }

        $plan = currentCompanyPlan($conn, $companyId);
        $planName = $plan['plan_name'] ?? 'current';
        $what = $label !== '' ? $label : $module;

        http_response_code(403);
        exit(
            '<!doctype html><meta charset="utf-8">'
            . '<div style="font:15px/1.6 system-ui,sans-serif;max-width:520px;margin:12vh auto;padding:0 24px;color:#00224c">'
            . '<h2 style="margin:0 0 8px">' . htmlspecialchars($what) . ' is not part of your plan</h2>'
            . '<p style="color:#5b6b82;margin:0 0 20px">Your ' . htmlspecialchars($planName)
            . ' subscription does not include this module. Upgrade the plan to unlock it.</p>'
            . '<a href="/admin/dashboard.php" style="color:#00224c;font-weight:600">Back to dashboard</a>'
            . '</div>'
        );
    }
}

/**
 * Stop a request for a module belonging to a role the company's plan does
 * not sell.
 *
 * Retail Starter hands out Owner/Admin, Cashier and Inventory Staff. It has
 * no HR and no Finance, so HRMS and Finance are not modules its owner is
 * choosing not to use -- they are not part of what they bought.
 *
 * Separate from requireModule(), which asks about subscription_plan_features
 * and governs things like hiring approval. This asks about
 * subscription_plan_roles: whether the company may have such a person at
 * all. The HR pages admit an admin by role -- requireRole(['hr', 'admin'])
 * -- and role says nothing about the plan, so without this an owner on the
 * smallest plan could open Payroll by typing its address.
 *
 * Hiding the sidebar entry is presentation. This is the part that holds.
 */
if (!function_exists('requirePlanRole')) {
    function requirePlanRole(mysqli $conn, int $companyId, string $role, string $label = ''): void
    {
        $role = strtolower(trim($role));

        if (in_array($role, companyPlanRoles($conn, $companyId), true)) {
            return;
        }

        $plan = currentCompanyPlan($conn, $companyId);
        $planName = $plan['plan_name'] ?? 'current';
        $what = $label !== '' ? $label : ucfirst($role);

        http_response_code(403);
        exit(
            '<!doctype html><meta charset="utf-8">'
            . '<div style="font:15px/1.6 system-ui,sans-serif;max-width:520px;margin:12vh auto;padding:0 24px;color:#00224c">'
            . '<h2 style="margin:0 0 8px">' . htmlspecialchars($what) . ' is not part of your plan</h2>'
            . '<p style="color:#5b6b82;margin:0 0 20px">The ' . htmlspecialchars($planName)
            . ' plan does not include this department, so there is nothing here to manage. '
            . 'Upgrade the plan to add it.</p>'
            . '<a href="/admin/dashboard.php" style="color:#00224c;font-weight:600">Back to dashboard</a>'
            . '</div>'
        );
    }
}

/**
 * How many branches the company's plan allows, or null for no ceiling.
 *
 * A NULL or non-positive max_branches means unlimited, matching how
 * max_users is read in admin/ajax_add_user.php. A company with no active
 * subscription gets null here; the paywall in requireRole() has already
 * turned it away, so this is not the place to judge it.
 */
if (!function_exists('companyBranchLimit')) {
    function companyBranchLimit(mysqli $conn, int $companyId): ?int
    {
        $stmt = $conn->prepare("
            SELECT p.max_branches
            FROM company_subscriptions cs
            JOIN subscription_plans p ON p.plan_id = cs.plan_id
            WHERE cs.company_id = ?
              AND cs.status IN ('Active', 'Trial')
            ORDER BY cs.expiry_date DESC
            LIMIT 1
        ");
        $stmt->bind_param("i", $companyId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row || $row['max_branches'] === null) {
            return null;
        }

        $max = (int) $row['max_branches'];

        return $max > 0 ? $max : null;
    }
}

/**
 * Why this company cannot open another branch, or null when it can.
 *
 * The plan sells branches as a number - "1 Branch", "Up to 10 Branches" -
 * and that number was recorded at subscription time and then read by
 * nothing. A Retail Starter store on a one-branch plan opened two.
 *
 * Deliberately a count and not a module gate. admin/branch.php is the only
 * way to create a branch and registration creates none, so denying the page
 * outright would leave a Starter company unable to record even its first
 * store. The page stays open; the ceiling is what holds.
 *
 * Existing rows over the ceiling are left alone. The check is "may another
 * one be added", so a company that is already over - from before this was
 * enforced, or after a downgrade - keeps what it has and simply cannot grow.
 */
if (!function_exists('branchLimitProblem')) {
    function branchLimitProblem(mysqli $conn, int $companyId): ?string
    {
        $max = companyBranchLimit($conn, $companyId);

        if ($max === null) {
            return null;
        }

        $stmt = $conn->prepare("SELECT COUNT(*) AS n FROM branch WHERE company_id = ?");
        $stmt->bind_param("i", $companyId);
        $stmt->execute();
        $used = (int) ($stmt->get_result()->fetch_assoc()['n'] ?? 0);
        $stmt->close();

        if ($used < $max) {
            return null;
        }

        $plan = currentCompanyPlan($conn, $companyId);
        $planName = $plan['plan_name'] ?? 'Your';

        return $max === 1
            ? $planName . ' covers a single branch, and you already have one. '
                . 'Upgrade your plan to open another.'
            : $planName . ' covers ' . $max . ' branches and you already have '
                . $used . '. Upgrade your plan to open another.';
    }
}

/**
 * The company's capital row, created at zero if it has none yet.
 *
 * finance_capital used to be seeded by hand for the one business the old
 * build served. A company that registers through the platform has no such
 * row, and the first thing it hears is "Capital record not found. Please
 * contact Finance." -- advice a Retail Starter store cannot act on, because
 * the plan has no Finance seat.
 *
 * Creating it lazily means the migration that backfilled today's companies
 * does not have to be remembered for tomorrow's.
 *
 * @return array{capital_id:int,current_capital:float}
 */
if (!function_exists('ensureCompanyCapital')) {
    function ensureCompanyCapital(mysqli $conn, int $companyId): array
    {
        $read = $conn->prepare("
            SELECT capital_id, current_capital
            FROM finance_capital
            WHERE company_id = ?
            ORDER BY capital_id ASC
            LIMIT 1
        ");
        $read->bind_param("i", $companyId);
        $read->execute();
        $row = $read->get_result()->fetch_assoc();
        $read->close();

        if ($row) {
            return [
                'capital_id' => (int) $row['capital_id'],
                'current_capital' => (float) $row['current_capital'],
            ];
        }

        /*
        | A new shop opens with STARTING_CAPITAL, not with nothing.
        |
        | This used to insert 0.00 explicitly, which overrode the column's
        | own DEFAULT 10000.00 -- the schema and the code disagreed and the
        | code won. A shop therefore started unable to buy anything, and the
        | first stock request it raised was refused for want of capital.
        |
        | The opening balance is written to the ledger as well. A balance
        | that appears with no entry behind it makes the ledger stop adding
        | up, which is the one thing a ledger has to do.
        */
        $opening = STARTING_CAPITAL;

        $create = $conn->prepare("
            INSERT INTO finance_capital (company_id, current_capital) VALUES (?, ?)
        ");
        $create->bind_param("id", $companyId, $opening);
        $create->execute();
        $newId = $conn->insert_id;
        $create->close();

        $ledger = $conn->prepare("
            INSERT INTO capital_ledger
                (company_id, type, reference_id, reference_code,
                 amount, balance_after, description)
            VALUES (?, 'Capital Added', NULL, 'OPENING', ?, ?, ?)
        ");
        $note = 'Opening capital when the shop was set up.';
        $ledger->bind_param("idds", $companyId, $opening, $opening, $note);
        $ledger->execute();
        $ledger->close();

        return ['capital_id' => (int) $newId, 'current_capital' => (float) $opening];
    }
}

/**
 * The company's POS tax rate, creating the row at 0.00 if it has none.
 *
 * cashier/pointofsales.php has always read the `tax` table and nothing has
 * ever written to it, so a company that registers through the platform is
 * charged 0% with no way to change it. This is the same lazy bootstrap
 * ensureCompanyCapital() performs, for the same reason: the migration that
 * backfilled today's companies should not have to be remembered tomorrow.
 *
 * 0.00 is deliberate. Defaulting a shop to 12% would start charging its
 * customers VAT on its behalf.
 *
 * @return array{id:int,tax_rate:float}
 */
if (!function_exists('ensureCompanyTaxRate')) {
    function ensureCompanyTaxRate(mysqli $conn, int $companyId): array
    {
        $read = $conn->prepare("SELECT id, tax_rate FROM tax WHERE company_id = ? ORDER BY id ASC LIMIT 1");
        $read->bind_param("i", $companyId);
        $read->execute();
        $row = $read->get_result()->fetch_assoc();
        $read->close();

        if ($row) {
            return ['id' => (int) $row['id'], 'tax_rate' => (float) $row['tax_rate']];
        }

        $create = $conn->prepare("INSERT INTO tax (company_id, tax_rate) VALUES (?, 0.00)");
        $create->bind_param("i", $companyId);
        $create->execute();
        $newId = $conn->insert_id;
        $create->close();

        return ['id' => (int) $newId, 'tax_rate' => 0.0];
    }
}

/**
 * The company's departments, seeded on first use if it has none.
 *
 * hr/recruitment.php builds its Department dropdown from this table, and
 * nothing has ever written to it -- so Create Job Posting offered an empty
 * list. Same gap finance_capital and tax had, and the same lazy fix, so a
 * company that registers tomorrow is not left with the empty dropdown the
 * backfill migration already cleared for today's.
 *
 * @return array<int,array{department_id:int,department_name:string}>
 */
if (!function_exists('ensureCompanyDepartments')) {
    function ensureCompanyDepartments(mysqli $conn, int $companyId): array
    {
        $read = $conn->prepare("
            SELECT department_id, department_name
            FROM department
            WHERE company_id = ?
            ORDER BY department_name ASC
        ");
        $read->bind_param("i", $companyId);
        $read->execute();
        $rows = $read->get_result()->fetch_all(MYSQLI_ASSOC);
        $read->close();

        if (count($rows) > 0) {
            return $rows;
        }

        $seed = $conn->prepare("
            INSERT INTO department (company_id, department_name) VALUES (?, ?)
        ");

        foreach (['Cashier', 'Finance', 'HR', 'Inventory'] as $name) {
            $seed->bind_param("is", $companyId, $name);
            $seed->execute();
        }

        $seed->close();

        return ensureCompanyDepartments($conn, $companyId);
    }
}

/**
 * The company's government contribution rates, seeded on first use.
 *
 * hr/hr_settings.php renders and edits these, and nothing ever wrote a row --
 * so the screen showed an empty table. Same lazy bootstrap as the departments,
 * tax rate and capital, for the same reason: a company registering tomorrow
 * should not depend on a migration that ran today.
 *
 * The employee shares are the published statutory rates. Withholding tax
 * starts at 0.00 because it comes from BIR brackets rather than a flat
 * percentage, and guessing one would put a wrong number on real payslips.
 */
if (!function_exists('ensureCompanyContributions')) {
    function ensureCompanyContributions(mysqli $conn, int $companyId): void
    {
        $check = $conn->prepare("SELECT 1 FROM government_contributions WHERE company_id = ? LIMIT 1");
        $check->bind_param("i", $companyId);
        $check->execute();
        $exists = $check->get_result()->num_rows > 0;
        $check->close();

        if ($exists) {
            return;
        }

        $seed = $conn->prepare("
            INSERT INTO government_contributions (company_id, contribution_name, deduction_rate)
            VALUES (?, ?, ?)
        ");

        foreach ([
            'SSS' => 4.50,
            'PhilHealth' => 2.50,
            'Pag-IBIG' => 2.00,
            'Withholding Tax' => 0.00,
        ] as $name => $rate) {
            $seed->bind_param("isd", $companyId, $name, $rate);
            $seed->execute();
        }

        $seed->close();
    }
}

if (!function_exists('requireCompany')) {
    function requireCompany(): int
    {
        $companyId = currentCompanyId();

        if ($companyId === null) {
            http_response_code(403);
            exit('This page requires a company account.');
        }

        return $companyId;
    }
}

if (!function_exists('requireRole')) {
    function requireRole($allowedRoles = [])
    {

        if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
            $_SESSION['session_expired'] = "Please log in to access this page.";
            header("Location: /acc_log_in");
            exit();
        }

        /*
        | Deactivation has to bite now, not at the next sign-in.
        |
        | The session carries what was true when the person signed in, so
        | without this an account deactivated at ten past nine keeps working
        | all day -- and the Deactivate dialog's promise that the user "will
        | lose system access immediately" is simply false. The check lives
        | here for the same reason the subscription check below does: a URL
        | typed straight into the bar must not walk past it.
        |
        | It is one lookup on the primary key, on a page that is already
        | querying the database.
        */
        global $conn;

        if ($conn instanceof mysqli
            && !userAccountAllowsAccess($conn, (int) $_SESSION['user_id'])) {

            $_SESSION = [];
            session_destroy();
            session_start();
            $_SESSION['login_error'] = accountAccessMessage(null);

            header("Location: /acc_log_in");
            exit();
        }

        /*
        | An approved business can sign in, but it cannot use the system until
        | its subscription is active. The check lives here rather than only at
        | login, because otherwise the owner could simply type a dashboard URL
        | and walk straight past the paywall.
        |
        | Super Admin runs the platform and belongs to no company, so it is
        | exempt.
        */
        if (!empty($_SESSION['company_id']) && empty($_SESSION['subscription_active'])) {
            header("Location: /platform/subscribe.php");
            exit();
        }

        $userRole = strtolower($_SESSION['role']);
        $allowedRoles = array_map('strtolower', (array) $allowedRoles);

        if (!in_array($userRole, $allowedRoles)) {

            switch ($userRole) {

                case "admin":
                    header("Location: /admin/dashboard.php");
                    break;

                case "hr":
                    header("Location: /hr/dashboard.php");
                    break;

                case "finance":
                    header("Location: /finance/dashboard.php");
                    exit();

                case "inventory":
                    header("Location: /inventory/dashboard.php");
                    exit();

                case "cashier":
                    header("Location: /cashier/pointofsales.php");
                    exit();

                case "super admin":
                    header("Location: /platform/superAdmin/dashboard.php");
                    exit();

                default:
                    header("Location: /acc_log_in");
                    break;
            }

            exit();
        }
    }
}
?>