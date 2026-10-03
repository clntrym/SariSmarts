<?php

require_once("../init.php");
requireRole(['inventory']);

$companyId = requireCompany();

$MODULE_HEADER = __DIR__ . "/inventory_header.php";
$MODULE_FOOTER = __DIR__ . "/inventory_footer.php";

require __DIR__ . "/../includes/receive_deliveries.php";
