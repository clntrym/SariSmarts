<?php
/*
|--------------------------------------------------------------------------
| WHICH SIDEBAR A PAGE WEARS
|--------------------------------------------------------------------------
|
| A page belongs to a module. The sidebar belongs to the person reading it.
| Those are different things, and this file is what keeps them apart.
|
| Recruitment lives in hr/ and says include('hr_header.php'), which is right
| for an HR officer and wrong for the owner. The owner runs the business --
| hiring is theirs too -- but an owner who opened Recruitment found their own
| menu replaced by HR's, with no way back to Inventory or Reports. The page
| had decided what they were, when the session already knew.
|
| So the page asks for "the header for whoever is reading this" and gets
| theirs. One page, one copy of the logic, two menus.
|
| An unknown role gets null rather than somebody else's menu. A page that
| cannot name its reader should show them nothing, not guess.
*/

if (!function_exists('roleChrome')) {

    /**
     * The header and footer each role uses, relative to the project root.
     */
    function roleChrome(): array
    {
        return [
            'admin' => ['admin/admin_header.php', 'admin/admin_footer.php'],
            'hr' => ['hr/hr_header.php', 'hr/hr_footer.php'],
            'finance' => ['finance/finance_header.php', 'finance/finance_footer.php'],
            'inventory' => ['inventory/inventory_header.php', 'inventory/inventory_footer.php'],
            'cashier' => ['cashier/cashier_header.php', 'cashier/cashier_footer.php'],
            'employee' => ['employee/employee_header.php', 'employee/employee_footer.php'],
        ];
    }
}

if (!function_exists('roleHeader')) {

    /**
     * The header file for a role, or null if there is none for it.
     *
     * The role defaults to the session's, because that is the only place it
     * may come from -- a page that took it from the request would let anyone
     * choose which menu, and with it which links, they were shown.
     */
    function roleHeader(?string $role = null): ?string
    {
        $role = strtolower(trim((string) ($role ?? ($_SESSION['role'] ?? ''))));

        return roleChrome()[$role][0] ?? null;
    }
}

if (!function_exists('roleFooter')) {

    function roleFooter(?string $role = null): ?string
    {
        $role = strtolower(trim((string) ($role ?? ($_SESSION['role'] ?? ''))));

        return roleChrome()[$role][1] ?? null;
    }
}

if (!function_exists('includeRoleHeader')) {

    /**
     * The path to the reader's header, for the page to include.
     *
     *     include includeRoleHeader(__DIR__, 'hr_header.php');
     *
     * It returns a path rather than doing the include itself, and that is the
     * whole reason this reads awkwardly. A header included from inside a
     * function runs in that function's scope: $conn, which every header uses
     * to ask what the plan allows, is a global and would be null there. The
     * first version did the include here and every page fatal'd on
     * "companyHasModule(): Argument #1 must be of type mysqli, null given".
     * Returning the path keeps the include at file scope, exactly where the
     * hard-coded one used to be.
     *
     * The second argument is what the page used to name outright. It is kept
     * as the fallback so a role with no chrome of its own -- or a session
     * that has lost its role -- still renders a page rather than a blank
     * screen with its stylesheet missing.
     */
    function includeRoleHeader(string $dir, string $fallback): string
    {
        return roleChromePath(roleHeader(), $dir, $fallback);
    }
}

if (!function_exists('includeRoleFooter')) {

    function includeRoleFooter(string $dir, string $fallback): string
    {
        return roleChromePath(roleFooter(), $dir, $fallback);
    }
}

if (!function_exists('roleChromePath')) {

    function roleChromePath(?string $part, string $dir, string $fallback): string
    {
        $root = dirname(__DIR__);

        if ($part !== null && is_file($root . '/' . $part)) {
            return $root . '/' . $part;
        }

        return $dir . '/' . $fallback;
    }
}
