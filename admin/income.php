<?php

require_once("../init.php");
requireRole(['admin']);

$companyId = requireCompany();

$MODULE_HEADER = __DIR__ . "/admin_header.php";
$MODULE_FOOTER = __DIR__ . "/admin_footer.php";

require __DIR__ . "/../includes/income.php";
