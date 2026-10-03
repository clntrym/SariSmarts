<?php

/*
|--------------------------------------------------------------------------
| WHAT A PLAN ACTUALLY UNLOCKS
|--------------------------------------------------------------------------
|
| A plan sells two kinds of thing, and both are stored as display text that
| a human wrote on the pricing page:
|
|   subscription_plan_roles.role_name       "HR Officer"
|   subscription_plan_features.feature_name "Recruitment / Job Posting"
|
| The tenant app cannot enforce display text. It needs the slug the system
| actually uses - 'hr', 'hiring' - and those live in the sibling columns
| system_role and system_module.
|
| THE BUG THIS FILE EXISTS TO CLOSE
|
| Every one of those sibling columns was NULL: 8 roles, 50 features. Both
| helpers in the tenant app fail open when they find nothing -
| companyPlanRoles() returns every role, companyHasModule() denies nothing -
| so Retail Starter handed out HR Officer and every other role it never
| sold.
|
| They were NULL because savePlanFeatures() in the Super Admin deletes a
| plan's features and reinserts them with (plan_id, feature_name) only. Any
| edit to any plan wiped the mapping for that plan. Seeding the columns
| once would have lasted until the next time somebody opened the plan
| editor.
|
| So the mapping is derived from the name rather than stored by hand, and
| derived again on every save. It cannot drift out of date and it cannot be
| wiped by an edit.
|
| WHY SO FEW MODULES
|
| Three module slugs are gated anywhere in the tenant app:
|
|   hiring            admin/approval.php, the Hiring Approval screen
|   finance_approval  whether a stock request needs finance sign-off
|   branch            the Branch Management screen
|
| Mapping a feature to a slug nothing checks would be decoration. Worse,
| mapping one to a slug that IS checked, when no plan sells it, locks
| everybody out of it - companyHasModule() denies a module the moment any
| plan claims it.
|
| 'branch' is deliberately NOT mapped here. It reads like a Professional
| feature, and it is: Starter sells "1 Branch" while Professional sells
| "Multi-Branch Management". But admin/branch.php is the only way to create
| a branch at all, and registration creates none - so gating that page
| would leave a Starter company unable to ever record its single store.
| The branch entitlement is a number, not a door, and it is enforced as one
| against subscription_plans.max_branches.
|
*/


/*
| Role display name -> the slug in users.role.
|
| Keyed lowercase; lookups normalise. These five are what
| SariSmarts/init.php roleDisplayName() names, in the same words the
| pricing page uses.
*/
if (!function_exists('planRoleSlugs')) {
    function planRoleSlugs(): array
    {
        return [
            'owner / admin'   => 'admin',
            'owner/admin'     => 'admin',
            'owner'           => 'admin',
            'admin'           => 'admin',
            'hr officer'      => 'hr',
            'hr'              => 'hr',
            'finance staff'   => 'finance',
            'finance'         => 'finance',
            'inventory staff' => 'inventory',
            'inventory'       => 'inventory',
            'cashier'         => 'cashier',
        ];
    }
}


/*
| Feature display name -> a module slug the tenant app checks.
|
| Only slugs that something actually gates belong here. Anything absent
| stays NULL, which companyHasModule() reads as "nobody sells it, so nobody
| is denied it" - the permissive default that keeps a new page from
| silently vanishing for every company.
*/
if (!function_exists('planModuleSlugs')) {
    function planModuleSlugs(): array
    {
        return [
            'recruitment / job posting' => 'hiring',
            'recruitment management'    => 'hiring',
            'recruitment'               => 'hiring',
            'finance management'        => 'finance_approval',
        ];
    }
}


if (!function_exists('roleSlugForName')) {

    /* The slug for a plan's role line, or null when it names nothing known. */
    function roleSlugForName(?string $roleName): ?string
    {
        $key = strtolower(trim((string) $roleName));
        $key = preg_replace('/\s+/', ' ', $key);

        return planRoleSlugs()[$key] ?? null;
    }
}


if (!function_exists('moduleSlugForFeature')) {

    /* The gated module a plan's feature line unlocks, or null for most. */
    function moduleSlugForFeature(?string $featureName): ?string
    {
        $key = strtolower(trim((string) $featureName));
        $key = preg_replace('/\s+/', ' ', $key);

        return planModuleSlugs()[$key] ?? null;
    }
}


if (!function_exists('planEntitlementGaps')) {

    /*
    | Role lines whose name maps to no slug, which is the shape the original
    | bug had: a plan that looks configured and enforces nothing.
    |
    | The Super Admin shows this so a typo in a role name is visible as a
    | warning rather than as a company quietly getting every role.
    |
    | @return array<int, array{plan_id:int, plan_name:string, role_name:string}>
    */
    function planEntitlementGaps(mysqli $conn): array
    {
        $gaps = [];

        $result = $conn->query("
            SELECT r.plan_id, p.plan_name, r.role_name
            FROM subscription_plan_roles r
            JOIN subscription_plans p ON p.plan_id = r.plan_id
            WHERE r.system_role IS NULL OR r.system_role = ''
            ORDER BY r.plan_id, r.role_order
        ");

        if (!$result) {
            return $gaps;
        }

        while ($row = $result->fetch_assoc()) {
            $gaps[] = [
                'plan_id'   => (int) $row['plan_id'],
                'plan_name' => (string) $row['plan_name'],
                'role_name' => (string) $row['role_name'],
            ];
        }

        return $gaps;
    }
}
