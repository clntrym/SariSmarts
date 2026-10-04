<?php
/*
| Every link in every sidebar points at a file that exists, spelled exactly.
|
| Windows does not care about the case of a filename; Linux does. So a sidebar
| that says href="inventory.php" next to a file called Inventory.php works
| perfectly on XAMPP and returns 404 on Render -- and nobody finds out until a
| user clicks it in production.
|
| Three of those shipped: inventory/ linked to inventory.php (the file is
| Inventory.php), to Suppliers.php (the file is suppliers.php), and to
| employee_settings.php, which that folder does not have at all. A fourth was
| worse than a 404: hr/employee_settings.php exists but is zero bytes, so the
| link opened a blank page.
|
| This walks the real headers and checks every relative link, so the next one
| is caught here instead of by whoever clicks it.
*/
require_once __DIR__ . '/bootstrap.php';

$root = __DIR__ . '/../../';

$headers = [
    'admin/admin_header.php',
    'admin/adminss_header.php',
    'hr/hr_header.php',
    'finance/finance_header.php',
    'inventory/inventory_header.php',
    'cashier/cashier_header.php',
    'employee/employee_header.php',
];

foreach ($headers as $header) {

    $path = $root . $header;

    if (!is_file($path)) {
        t_ok(false, "{$header} exists");
        continue;
    }

    $source = (string) file_get_contents($path);

    /*
    | A link inside an HTML comment is not a menu item. Two of them -- HR's
    | employee_accounts.php and employee_settings.php -- are commented out and
    | the files behind them are empty leftovers, which is tidy rather than
    | broken. Scanning them as live links reported a bug that was not there.
    */
    $source = (string) preg_replace('/<!--.*?-->/s', '', $source);

    $folder = dirname($path) . '/';

    preg_match_all('/href="([^"#?:]+\.php)(?:\?[^"]*)?"/', $source, $matches);

    $seen = [];

    foreach ($matches[1] as $href) {

        /* Only relative links inside the same folder are this file's business;
           a root-absolute path belongs to whatever serves it. */
        if ($href === '' || $href[0] === '/' || str_contains($href, '://')) {
            continue;
        }

        if (isset($seen[$href])) {
            continue;
        }

        $seen[$href] = true;

        $target = $folder . $href;
        $label = basename($header) . ' -> ' . $href;

        if (!is_file($target)) {
            t_ok(false, "{$label}: no such file");
            continue;
        }

        /*
        | is_file() is case-insensitive on Windows, so it says yes to
        | Inventory.php when the link says inventory.php. Comparing against
        | the directory listing is what actually catches the case.
        |
        | A link may carry a directory part (../admin/reports.php), so the
        | comparison is the file's own name against its own folder.
        */
        $real = scandir(dirname($target));

        t_ok(in_array(basename($href), $real, true),
            "{$label}: spelled exactly as the file is");

        t_ok(filesize($target) > 0, "{$label}: the file is not empty");
    }
}

t_done();
