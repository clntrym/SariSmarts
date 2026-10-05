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

/* --------------------------- a module nobody sells is a module everybody has */

/*
| companyHasModule()'s rule: a module no plan claims is denied to nobody, so
| that adding a page never silently hides it from everybody. Right -- and it
| means a slug missing from subscription_plan_features does not fail, it
| GRANTS.
|
| That is how a Retail Starter store ended up with finance_approval. Its
| stock request went to a Finance step it has no Finance user to perform,
| and sat there: the owner's page showed it waiting on somebody who does not
| exist, and nobody could approve it. Hiring Approval stayed in the sidebar
| for the same reason.
|
| Every module the code gates on has to be sold by somebody, or the gate is
| scenery.
*/
$sold = [];
$result = $conn->query("
    SELECT DISTINCT system_module
    FROM subscription_plan_features
    WHERE system_module IS NOT NULL AND system_module <> ''
");

while ($row = $result->fetch_assoc()) {
    $sold[] = strtolower(trim((string) $row['system_module']));
}

foreach (['branch', 'hiring', 'finance_approval'] as $module) {
    t_ok(in_array($module, $sold, true),
        "'{$module}' is sold by some plan, so the gate on it means something");
}

/* And Retail Starter buys none of the three. */
foreach (['branch', 'hiring', 'finance_approval'] as $module) {
    t_ok(!companyHasModule($conn, $companyId, $module),
        "a Retail Starter company does not get '{$module}'");
}

/* ------------------------------------- the fallback, and why it is silent */

/*
| companyPlanRoles() falls back to every role when a plan grants none,
| rather than locking an owner out of hiring anybody. That is the right
| default and it is invisible, which is the problem: a database where
| plan_role_access.sql never ran has every subscription_plan_roles row
| present -- pricing.php renders them perfectly, because it reads role_name
| -- and every system_role NULL, because that is the column the migration
| adds. So every owner on every plan is quietly given everything, and the
| pricing page agrees with the sidebar only by accident.
|
| This pins the behaviour so a reader knows it is deliberate, and
| tools/plan_status.php is what says whether it is firing.
*/
$fallbackCompany = userTestMake($conn, 'NoPlanRows', 'active');
$fallbackId = (int) $fallbackCompany['company_id'];

t_same(['admin', 'hr', 'finance', 'inventory', 'cashier'],
    companyPlanRoles($conn, $fallbackId),
    'a company with no subscription falls back to every role, by design');

t_ok(is_file($root . '/tools/plan_status.php'),
    'and there is one page that says whether that fallback is firing');

$tool = (string) file_get_contents($root . '/tools/plan_status.php');

t_ok(str_contains($tool, 'super admin'),
    'which only the Super Admin may read');
t_ok(str_contains($tool, 'system_role'),
    'and which names the column whose absence causes it');
t_ok(!preg_match('~\b(INSERT|UPDATE|DELETE|ALTER|DROP)\b~i', $tool),
    'and changes nothing -- it is a diagnostic, not a migration');

/* ------------------------------------------------------------------ tax */

$tax = (string) preg_replace(['~/\*.*?\*/~s', '~(?<!:)//[^\n]*~'],
    '', (string) file_get_contents($root . '/admin/tax.php'));

t_ok(!str_contains($tax, 'requirePlanRole'),
    'Tax is not gated on having Finance staff -- every store files with the BIR');

t_ok(str_contains($tax, "requireRole(['admin'"),
    'but it is still the owner\'s page, not open to anybody');

/*
| And it is reachable on a plan with no Finance dropdown to hide it in:
| outside the $canFinance block, which is where it used to live.
*/
$financeBlock = '';

if (preg_match('~\$canFinance\):\s*\?>(.*?)<\?php endif;~s', $header, $found)) {
    $financeBlock = $found[1];
}

t_ok($financeBlock !== '', 'the Finance section was found in the sidebar');
t_ok(!str_contains($financeBlock, 'tax.php'),
    'and Tax is no longer inside it, so hiding Finance does not hide Tax');
t_ok(str_contains($header, 'tax.php'),
    'while Tax is still in the sidebar somewhere');

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

/*
| Tax is not here.
|
| It sat inside the Finance dropdown, so gating that dropdown took it away
| from Retail Starter -- and a small store still files with the BIR. Tax is
| an obligation every company has, not a function of having Finance staff,
| so it is a sidebar entry of its own and an admin reaches it on any plan.
*/
$behindFinance = ['admin/income.php', 'finance/expenses.php',
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
