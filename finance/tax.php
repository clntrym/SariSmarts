<?php

require_once("../init.php");
requireRole(['finance']);

$companyId = requireCompany();

$MODULE_HEADER = __DIR__ . "/finance_header.php";
$MODULE_FOOTER = __DIR__ . "/finance_footer.php";

require __DIR__ . "/../includes/tax.php";
