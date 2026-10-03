<?php

/*
|--------------------------------------------------------------------------
| EMPLOYMENT CONTRACTS FOR SARISMART'S OWN STAFF
|--------------------------------------------------------------------------
|
| A contract exists only once somebody is hired. In the Employees module
| the hire IS the moment the record is created, so the contract is drawn up
| with it and nowhere else.
|
| The life of one:
|
|   Pending   drawn up when the person was hired; nothing attached yet
|   Sent      our copy is attached and has gone to them
|   Signed    their signed copy is back
|
| and alongside that, HR's verdict on what came back:
|
|   Pending   not looked at yet
|   Approved  HR accepts the signed copy
|   Rejected  something is wrong with it; hr_remarks says what
|
| The two are separate on purpose. A contract can be Signed and still be
| waiting on review, and a rejected one is still signed -- collapsing them
| into a single status would lose that.
|
| WHERE THE FILES LIVE
|
| uploads/platform_contracts, which carries an .htaccess that denies
| everything. They are served by superAdmin/contract_file.php, which checks
| the reader may open the Employees module. The stored name is random, so a
| server that ignores .htaccess still cannot be guessed at.
|
*/

if (!defined('CONTRACT_MAX_BYTES')) {
    define('CONTRACT_MAX_BYTES', 5 * 1024 * 1024);
}

if (!defined('CONTRACT_DIR')) {
    define('CONTRACT_DIR', dirname(__DIR__) . '/uploads/platform_contracts');
}


if (!function_exists('contractAllowedTypes')) {

    /*
    | Detected type => the extension we give the stored file.
    |
    | A contract is a document. Images are not on the list: a photograph of
    | a contract is not a contract, and allowing them invites exactly that.
    */
    function contractAllowedTypes(): array
    {
        return [
            'application/pdf' => 'pdf',
        ];
    }
}


if (!function_exists('nextContractNumber')) {

    /*
    | CON-YYYYMMDD-00042, built from the employee's own id.
    |
    | employee_id is an AUTO_INCREMENT and this table serves one platform,
    | so the number cannot collide and needs no counter of its own.
    */
    function nextContractNumber(int $employeeId): string
    {
        return 'CON-' . date('Ymd') . '-' . str_pad((string) $employeeId, 5, '0', STR_PAD_LEFT);
    }
}


if (!function_exists('createContractForHire')) {

    /*
    | Draws up the contract for somebody just hired.
    |
    | Returns the contract number, or null when one already exists -- which
    | is not a failure. Saving the employee again must not produce a second
    | contract, and the caller only announces a number when there is a new
    | one to announce.
    */
    function createContractForHire(
        mysqli $conn,
        int $employeeId,
        string $position,
        ?int $uploadedBy = null
    ): ?string {

        $check = $conn->prepare("
            SELECT contract_id FROM platform_employee_contracts
            WHERE employee_id = ? LIMIT 1
        ");
        $check->bind_param("i", $employeeId);
        $check->execute();
        $exists = $check->get_result()->num_rows > 0;
        $check->close();

        if ($exists) {
            return null;
        }

        $number = nextContractNumber($employeeId);
        $title = trim($position) !== ''
            ? trim($position) . ' Employment Contract'
            : 'Employment Contract';

        $stmt = $conn->prepare("
            INSERT INTO platform_employee_contracts
                (employee_id, contract_number, contract_title, uploaded_by)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->bind_param("issi", $employeeId, $number, $title, $uploadedBy);
        $stmt->execute();
        $stmt->close();

        return $number;
    }
}


if (!function_exists('contractForEmployee')) {

    /* The contract on record for one employee, or null. */
    function contractForEmployee(mysqli $conn, int $employeeId): ?array
    {
        $stmt = $conn->prepare("
            SELECT * FROM platform_employee_contracts
            WHERE employee_id = ? LIMIT 1
        ");
        $stmt->bind_param("i", $employeeId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }
}


if (!function_exists('storeContractFile')) {

    /*
    | Moves an uploaded contract into place and returns its path, or a
    | problem as a sentence.
    |
    | The type is read from the file's own bytes with finfo, not from its
    | name or the browser's claim, so renaming a script to .pdf does not get
    | it past. The stored name is generated here; nothing the uploader chose
    | is used to build a path.
    |
    | @return array{0: ?string, 1: ?string} [relative path, problem]
    */
    function storeContractFile(array $file, string $contractNumber, string $kind): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return [null, 'Please choose the contract file.'];
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            return [null, 'The file could not be uploaded.'];
        }

        if ($file['size'] > CONTRACT_MAX_BYTES) {
            return [null, 'The file is larger than 5 MB.'];
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detected = $finfo->file($file['tmp_name']);
        $allowed = contractAllowedTypes();

        if (!isset($allowed[$detected])) {
            return [null, 'A contract must be a PDF.'];
        }

        if (!is_dir(CONTRACT_DIR) && !mkdir(CONTRACT_DIR, 0777, true)) {
            return [null, 'Could not open the contracts folder.'];
        }

        $name = $contractNumber . '_' . $kind . '_'
            . bin2hex(random_bytes(8)) . '.' . $allowed[$detected];

        if (!move_uploaded_file($file['tmp_name'], CONTRACT_DIR . '/' . $name)) {
            return [null, 'Could not store the file.'];
        }

        return ['uploads/platform_contracts/' . $name, null];
    }
}


if (!function_exists('contractStatusTone')) {

    /* The badge colour for a status, so the table and the modal agree. */
    function contractStatusTone(string $status): string
    {
        $tones = [
            'Pending'  => 'secondary',
            'Sent'     => 'info',
            'Signed'   => 'success',
            'Approved' => 'success',
            'Rejected' => 'danger',
        ];

        return $tones[$status] ?? 'secondary';
    }
}
