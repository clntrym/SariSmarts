<?php

require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../includes/platform_roles.php';
require_once __DIR__ . '/../includes/company_contracts.php';

/*
|--------------------------------------------------------------------------
| SERVE ONE SERVICE AGREEMENT
|--------------------------------------------------------------------------
|
| uploads/company_contracts denies everything, so this is staff's only way
| in. The business has its own door in subscribe.php, which lets it reach
| nothing but its own.
|
| The caller names a contract and which of its two files, or asks for the
| blank template. They never name a path: every path is read from a row, so
| a request cannot walk out of the folder however it is spelled.
|
*/

requirePlatformAccess('company');

/* The blank, for checking what is currently being handed out. */
if (isset($_GET['template'])) {

    $template = contractTemplate($conn);

    if (!$template) {
        http_response_code(404);
        exit('No agreement template has been uploaded yet.');
    }

    sendContractFile($template['path'], 'Service Agreement.pdf');
}

$contractId = (int) ($_GET['id'] ?? 0);
$which = ($_GET['copy'] ?? '') === 'signed' ? 'signed_contract' : 'issued_template';

$stmt = $conn->prepare("
    SELECT c.contract_number, c.issued_template, c.signed_contract, co.company_name
    FROM company_contracts c
    JOIN company co ON co.company_id = c.company_id
    WHERE c.contract_id = ?
    LIMIT 1
");
$stmt->bind_param("i", $contractId);
$stmt->execute();
$contract = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$contract) {
    http_response_code(404);
    exit('That agreement could not be found.');
}

sendContractFile(
    $contract[$which] ?? null,
    $contract['contract_number'] . '_' . ($which === 'signed' ? 'signed' : 'agreement') . '.pdf'
);
