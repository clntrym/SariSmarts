<?php

require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../includes/platform_roles.php';
require_once __DIR__ . '/../includes/platform_contracts.php';

/*
|--------------------------------------------------------------------------
| SERVE ONE CONTRACT FILE
|--------------------------------------------------------------------------
|
| uploads/platform_contracts denies everything, so this is the only way in.
| It asks the same question the Employees module asks before showing a
| contract at all: may this person open it?
|
| The caller names a contract and which of its two files they want. They
| never name a path. The path is read from the row, so a request cannot
| walk out of the folder however it is spelled.
|
*/

requirePlatformAccess('employees');

$contractId = (int) ($_GET['id'] ?? 0);
$which = ($_GET['copy'] ?? '') === 'signed' ? 'signed_contract' : 'company_contract';

$stmt = $conn->prepare("
    SELECT c.contract_number, c.company_contract, c.signed_contract, e.full_name
    FROM platform_employee_contracts c
    JOIN platform_employees e ON e.employee_id = c.employee_id
    WHERE c.contract_id = ?
    LIMIT 1
");
$stmt->bind_param("i", $contractId);
$stmt->execute();
$contract = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$contract) {
    http_response_code(404);
    exit('That contract could not be found.');
}

$relative = trim((string) ($contract[$which] ?? ''));

if ($relative === '') {
    http_response_code(404);
    exit('No file has been attached to that contract yet.');
}

$path = dirname(__DIR__) . '/' . $relative;

/*
| The row is ours to trust, but the file on disk is checked anyway: a path
| that no longer resolves inside the contracts folder is not served, and a
| row pointing at a file somebody removed is a 404 rather than a warning
| printed into the response.
*/
$real = realpath($path);
$base = realpath(CONTRACT_DIR);

if ($real === false || $base === false || strpos($real, $base) !== 0) {
    http_response_code(404);
    exit('That file is no longer on record.');
}

/* A readable name for the download, built from the row, never from input. */
$label = $contract['contract_number'] . '_'
    . ($which === 'signed' ? 'signed' : 'company') . '.pdf';

header('Content-Type: application/pdf');
header('Content-Length: ' . filesize($real));
header('Content-Disposition: inline; filename="' . $label . '"');

/* Nothing here belongs in a shared cache. */
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');

readfile($real);
