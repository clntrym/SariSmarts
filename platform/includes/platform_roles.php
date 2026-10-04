<?php

/*
|--------------------------------------------------------------------------
| PLATFORM ROLES
|--------------------------------------------------------------------------
|
| Who on the RetailCore side can open which module. One file, so the answer
| to "can this person do that" lives in exactly one place and a change to
| the split is a change to the array below.
|
| The slugs are deliberately not "finance" or "hr". Those are already
| tenant roles: a company's own finance officer and HR officer sign in with
| them and are routed into /SariSmarts. Reusing either name here would send
| platform staff into a tenant app, or worse, let a tenant's HR officer
| through this door. The stored slug is unique; the label people read comes
| from this registry, not from the column.
|
| Super Admin holds every module. The other two are narrower so the day to
| day work spreads out, but nothing here takes anything away from Super
| Admin.
|
*/

if (!function_exists('platformRoles')) {

    function platformRoles(): array
    {
        /*
        | Module keys are page file names without .php. A module that is not
        | listed for a role is closed to that role.
        */

        $website = [
            'homePage', 'platformSection', 'heroBanner', 'features',
            'pricing', 'careers', 'footer',
        ];

        return [

            'super admin' => [
                'label' => 'Super Admin',
                'blurb' => 'Pamamahala ng RETAILCORE platform',
                'landing' => 'dashboard.php',
                /*
                | Everything, including the modules the other roles own.
                | Companies, subscriptions, the platform itself and access.
                */
                'modules' => '*',
            ],

            'marketing hr' => [
                'label' => 'Marketing & HR',
                'blurb' => 'Pagpapatakbo ng RETAILCORE people & sales',
                'landing' => 'leads.php',
                /*
                | Marketing owns customers and leads, and with them the
                | public pages and announcements that sell the product. HR
                | owns RetailCore's own employees. Support is here because the
                | people answering customers are the people who know them.
                */
                'modules' => array_merge([
                    'dashboard', 'leads', 'employees', 'support',
                    'notifications',
                ], $website),
            ],

            'platform finance' => [
                'label' => 'Finance',
                'blurb' => 'Pera ng RETAILCORE',
                'landing' => 'billing.php',
                /*
                | Subscriptions, billing, payments and revenue. Company
                | records stay with Super Admin; billing already shows the
                | company behind each amount.
                */
                'modules' => [
                    'dashboard', 'subscriptionManagement', 'billing', 'reports',
                ],
            ],

        ];
    }
}


if (!function_exists('platformRole')) {

    /* The current user's registry entry, or null if they are not platform staff. */
    function platformRole(): ?array
    {
        $slug = strtolower(trim((string) ($_SESSION['role'] ?? '')));
        $roles = platformRoles();

        return $roles[$slug] ?? null;
    }
}


if (!function_exists('platformRoleLabel')) {

    function platformRoleLabel(?string $slug = null): string
    {
        $slug = strtolower(trim((string) ($slug ?? $_SESSION['role'] ?? '')));
        $roles = platformRoles();

        return $roles[$slug]['label'] ?? 'Staff';
    }
}


if (!function_exists('platformCan')) {

    /* Whether the signed-in user may open a module. */
    function platformCan(string $module): bool
    {
        $role = platformRole();

        if ($role === null) {
            return false;
        }

        if ($role['modules'] === '*') {
            return true;
        }

        return in_array($module, $role['modules'], true);
    }
}


if (!function_exists('platformLanding')) {

    /* Where this role belongs when they have nowhere else to be. */
    function platformLanding(): string
    {
        $role = platformRole();

        return '/platform/superAdmin/' . ($role['landing'] ?? 'dashboard.php');
    }
}


if (!function_exists('requirePlatformAccess')) {

    /*
    | The guard every module opens with. It must run before any output: a
    | refusal redirects, and a redirect after the first byte is no refusal
    | at all.
    */
    function requirePlatformAccess(string $module): void
    {
        /*
        | requireRole() handles the two cases that are not about this
        | module: nobody is signed in, and the person signed in belongs to
        | a tenant app rather than here. It redirects in both.
        */
        requireRole(array_keys(platformRoles()));

        if (platformCan($module)) {
            return;
        }

        $_SESSION['platform_denied'] = 'That area belongs to another team. '
            . 'You are signed in as ' . platformRoleLabel() . '.';

        header('Location: ' . platformLanding());
        exit();
    }
}
