<?php

require_once("../init.php");
requireRole(['admin']);

$companyId = requireCompany();

/*
| And whether the plan has this department at all.
|
| requireRole() above admits an admin, and role says nothing about the
| plan: Retail Starter sells Owner/Admin, Cashier and Inventory Staff, so
| an owner on it has no Finance people and no Finance to manage. Hiding the
| sidebar entry is presentation; this is what holds when the address is
| typed.
*/
requirePlanRole($conn, $companyId, 'finance', 'Finance');
$MODULE_HEADER = __DIR__ . "/admin_header.php";
$MODULE_FOOTER = __DIR__ . "/admin_footer.php";

require __DIR__ . "/../includes/tax.php";
