<?php

require_once("../init.php");
requireRole(['admin']);

$companyId = requireCompany();

/*
| No plan check here, unlike the other pages in the Finance menu.
|
| Tax briefly carried requirePlanRole($conn, $companyId, 'finance'), because
| it sat inside the Finance dropdown and everything in there was gated
| together. That was wrong: a sari-sari store on Retail Starter has no
| Finance staff and still files with the BIR. The obligation comes from
| being a business, not from having bought a Finance seat.
|
| So Tax is its own sidebar entry now rather than an item in that menu, and
| any owner reaches it on any plan. requireRole(['admin']) above is the
| whole of the rule: it is the owner's page.
*/
$MODULE_HEADER = __DIR__ . "/admin_header.php";
$MODULE_FOOTER = __DIR__ . "/admin_footer.php";

require __DIR__ . "/../includes/tax.php";
