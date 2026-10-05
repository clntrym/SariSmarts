<?php
/*
| What a plan does not sell, the system does not show -- or open.
|
| Retail Starter hands out three roles: Owner/Admin, Cashier and Inventory
| Staff. That is what platform/pricing.php advertises and what
| subscription_plan_roles enforces when a user is created.
|
| The admin sidebar did not ask. The RBAC work gave the owner HRMS and
| Finance dropdowns so they could reach the modules their staff use, and
| never checked whether their plan has those staff at all -- so a Retail
| Starter owner was shown Recruitment, Payroll, Accounts Payable and the
| rest, for roles they cannot create and a module they did not buy.
|
| Hiding the menu is presentation. The guard is the part that holds when
| somebody types the URL, and the HR pages admit an admin by role alone:
| requireRole(['hr', 'admin']) does not know what plan the company is on.
| Both halves are needed, and only one of them is visible.
|
| Two things the plan data already gets right, asserted here so they stay
| right: a Starter company has no finance_approval module, so a stock
| request skips Finance and goes straight to the owner, and no hiring
| module, so Hiring Approval is already hidden.
*/
require_once __DIR__ . '/bootstrap.php';

$conn = $GLOBALS['conn'];

/* ------------------------------------------------------------ the plans */

$plans = [];

$result = $conn->query("
    SELECT p.plan_name, GROUP_CONCAT(r.system_role ORDER BY r.system_role) AS roles
    FROM subscription_plans p
    LEFT JOIN subscription_plan_roles r ON r.plan_id = p.plan_id
    GROUP BY p.plan_id
");

while ($row = $result->fetch_assoc()) {
    $plans[$row['plan_name']] = array_filter(explode(',', (string) $row['roles']));
}

t_ok(isset($plans['Retail Starter']), 'Retail Starter is a plan');

t_same(['admin', 'cashier', 'inventory'], array_values($plans['Retail Starter'] ?? []),
    'and it sells exactly the three roles the pricing page advertises');

t_ok(!in_array('hr', $plans['Retail Starter'] ?? [], true),
    'no HR role, so no HRMS');
t_ok(!in_array('finance', $plans['Retail Starter'] ?? [], true),
    'no Finance role, so no Finance');

t_ok(in_array('hr', $plans['Retail Professional'] ?? [], true),
    'Retail Professional does sell HR, so this is a plan question and not a page question');

/* ------------------------------------- what a company on that plan may open */

$made = userTestMake($conn, 'StarterPlan', 'active');
$companyId = (int) $made['company_id'];

$starter = $conn->query("SELECT plan_id FROM subscription_plans WHERE plan_name = 'Retail Starter' LIMIT 1")
    ->fetch_assoc()['plan_id'] ?? 0;

$conn->query("
    INSERT INTO company_subscriptions (company_id, plan_id, status, start_date, expiry_date)
    VALUES ({$companyId}, " . (int) $starter . ", 'Active', CURDATE(), DATE_ADD(CURDATE(), INTERVAL 1 YEAR))
");

register_shutdown_function(static function () use ($conn, $companyId) {
    $conn->query("DELETE FROM company_subscriptions WHERE company_id = {$companyId}");
});

/* companyPlanRoles() returns display order, not alphabetical, so compare sets. */
$roles = companyPlanRoles($conn, $companyId);
sort($roles);

t_same(['admin', 'cashier', 'inventory'], $roles,
    'the company reads back the three roles its plan sells');

t_ok(!companyHasModule($conn, $companyId, 'finance_approval'),
    'a stock request has no Finance step to wait at, so it goes straight to the owner');
t_ok(!companyHasModule($conn, $companyId, 'hiring'),
    'and Hiring Approval is not part of this plan');

/* ------------------------------------------------- the guard, not the menu */

t_ok(function_exists('requirePlanRole'),
    'there is a guard for "your plan does not include this role\'s module"');

/* ------------------------------------------------ the sidebar asks the plan */

$root = dirname(__DIR__, 2);

$header = (string) preg_replace(['~/\*.*?\*/~s', '~(?<!:)//[^\n]*~'],
    '', (string) file_get_contents($root . '/admin/admin_header.php'));

t_ok(str_contains($header, 'companyPlanRoles'),
    'the admin sidebar asks which roles the plan sells');

foreach (['canHrms' => 'HRMS', 'canFinance' => 'Finance'] as $flag => $what) {
    t_ok(str_contains($header, '$' . $flag),
        "the {$what} section is shown only where the plan includes it");
}

/* ------------------------------------------------- hiring, both ways */

/*
| Hiring Approval was already gated when this was written -- $canHiring in
| the sidebar, requireModule() on the page -- so for a Retail Starter
| company it is already hidden and already refused. Asserted so it stays
| that way, and so that anybody who sees it on a Starter company knows to
| look at the subscription rather than at this code.
*/
t_ok(str_contains($header, '$canHiring'),
    'the sidebar hides Hiring Approval where the plan has no hiring module');

$hiringPage = (string) preg_replace(['~/\*.*?\*/~s', '~(?<!:)//[^\n]*~'],
    '', (string) file_get_contents($root . '/admin/approval.php'));

t_ok(str_contains($hiringPage, "requireModule(\$conn, \$companyId, 'hiring'"),
    'and the page refuses the address as well as hiding the link');

/* --------------------------------- and the pages behind it are guarded too */

/*
| Every page the admin reaches through those two dropdowns. Hiding the link
| is not a guard: these admit an admin by role, and role says nothing about
| the plan.
*/
$behindHrms = ['hr/recruitment.php', 'hr/employee_registration.php',
               'hr/employee_directory.php', 'hr/approval.php',
               'hr/attendance.php', 'hr/payroll.php',
               'hr/applications.php', 'hr/employee_view.php',
               'hr/archive_employee.php', 'hr/employee_contract_print.php'];

$behindFinance = ['admin/income.php', 'finance/expenses.php', 'admin/tax.php',
                  'finance/accounts_payable.php'];

foreach (['hr' => $behindHrms, 'finance' => $behindFinance] as $role => $pages) {

    foreach ($pages as $page) {

        if (!is_file($root . '/' . $page)) {
            continue;
        }

        $code = (string) preg_replace(['~/\*.*?\*/~s', '~(?<!:)//[^\n]*~'],
            '', (string) file_get_contents($root . '/' . $page));

        t_ok(str_contains($code, "requirePlanRole"),
            "{$page} refuses a company whose plan has no '{$role}'");
    }
}

t_done();
