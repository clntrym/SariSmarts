<?php
/*
| Files that survive a deploy.
|
| Render's free instances have no persistent disk. Anything written to the
| filesystem at runtime lives until the next deploy or the next time the
| container spins down, and then it is gone -- while the database row that
| names it stays behind, pointing at nothing.
|
| That is what the subscribe page was showing:
|
|   "That file is no longer on record."   the agreement the business was
|                                         issued; realpath() returned false
|                                         because the PDF had been wiped
|
| The row was intact, the path was right, the file had simply ceased to
| exist. On XAMPP this never happens, because XAMPP's disk is a disk.
|
| So the bytes go where the rest of the record already goes. The path stays
| exactly as it is -- an opaque key on company_contracts and
| platform_settings -- and the store is keyed by it, so nothing that reads or
| writes a contract has to learn a new shape.
*/
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../platform/includes/platform_files.php';

$conn = $GLOBALS['conn'];

$path = 'uploads/company_contracts/USERTEST_' . bin2hex(random_bytes(6)) . '.pdf';

/* Removed however this ends, including a fatal. */
register_shutdown_function(static function () use ($conn, &$path) {
    @platformFileForget($conn, $path);
});

/* ------------------------------------------------------- store and read */

t_ok(platformFileRead($conn, $path) === null, 'a path with no row reads as nothing');

$bytes = "%PDF-1.4\n" . random_bytes(2048) . "\0\xff\xfe binary \0 safe\n%%EOF";

t_ok(platformFileStore($conn, $path, $bytes, 'application/pdf', 'Agreement.pdf'),
    'the bytes are stored');

$back = platformFileRead($conn, $path);

t_ok(is_array($back), 'and read back');
t_same($bytes, $back['bytes'] ?? null,
    'byte for byte -- nulls and high bytes included, which a text column would have eaten');
t_same('application/pdf', $back['mime'] ?? null, 'with its type');
t_same('Agreement.pdf', $back['name'] ?? null, 'and the name it was uploaded under');

/* ------------------------------------------------------------ replacing */

$second = "%PDF-1.7\n" . random_bytes(512) . "\n%%EOF";

t_ok(platformFileStore($conn, $path, $second, 'application/pdf', 'Signed.pdf'),
    'a second store at the same path is allowed');
t_same($second, platformFileRead($conn, $path)['bytes'] ?? null,
    'and replaces rather than duplicating -- the path is the identity');

/* --------------------------------------------------------------- a real size */

/*
| A signed agreement may be up to 5 MB, which is the number that matters:
| a blob larger than the server's max_allowed_packet fails at the wire, not
| in PHP, and would do it only in production on a real contract.
*/
$large = random_bytes(5 * 1024 * 1024);

t_ok(platformFileStore($conn, $path, $large, 'application/pdf', 'Large.pdf'),
    'a full 5 MB file -- the largest the page accepts -- stores');
t_same(strlen($large), strlen((string) (platformFileRead($conn, $path)['bytes'] ?? '')),
    'and comes back whole');

/* ---------------------------------------------------------------- forget */

t_ok(platformFileForget($conn, $path), 'a stored file can be removed');
t_ok(platformFileRead($conn, $path) === null, 'and is gone afterwards');

/* -------------------------------------------------- the contract pages use it */

$root = dirname(__DIR__, 2);

$contracts = (string) file_get_contents($root . '/platform/includes/company_contracts.php');

t_ok(str_contains($contracts, 'platformFileStore'),
    'an uploaded signed contract is stored durably, not only on the disk');
t_ok(str_contains($contracts, 'platformFileRead'),
    'and served from there, so a deploy does not lose it');

$settings = (string) file_get_contents($root . '/platform/superAdmin/settings.php');

t_ok(str_contains($settings, 'platformFileStore'),
    'the blank template the Super Admin uploads is stored durably too');

/* ------------------------------- a row pointing at a file that is gone */

/*
| The agreement a business was issued before this change points at a path
| whose PDF Render has already wiped. The row is not empty, so
| fillMissingTemplate() left it alone and the business went on being shown
| "That file is no longer on record" with no way to proceed.
|
| "An agreement that HAS a copy keeps it" still holds. A row whose copy
| cannot be retrieved anywhere does not have one.
*/
require_once __DIR__ . '/../../platform/includes/company_contracts.php';

$settingsRow = $conn->query("
    SELECT setting_id, contract_template, contract_template_name
    FROM platform_settings ORDER BY setting_id LIMIT 1
")->fetch_assoc();

if ($settingsRow) {

    $livePath = 'uploads/company_contracts/USERTEST_live_' . bin2hex(random_bytes(6)) . '.pdf';
    $deadPath = 'uploads/company_contracts/USERTEST_dead_' . bin2hex(random_bytes(6)) . '.pdf';

    $wasTemplate = $settingsRow['contract_template'];
    $wasName = $settingsRow['contract_template_name'];

    register_shutdown_function(static function () use ($conn, $settingsRow, $wasTemplate, $wasName, $livePath) {
        $restore = $conn->prepare("
            UPDATE platform_settings SET contract_template = ?, contract_template_name = ?
            WHERE setting_id = ?
        ");
        $restore->bind_param("ssi", $wasTemplate, $wasName, $settingsRow['setting_id']);
        $restore->execute();
        $restore->close();
        @platformFileForget($conn, $livePath);
    });

    platformFileStore($conn, $livePath, "%PDF-1.4\nlive\n%%EOF", 'application/pdf', 'Current.pdf');

    $set = $conn->prepare("
        UPDATE platform_settings SET contract_template = ?, contract_template_name = 'Current.pdf'
        WHERE setting_id = ?
    ");
    $set->bind_param("si", $livePath, $settingsRow['setting_id']);
    $set->execute();
    $set->close();

    /* A contract row carrying a path nothing can serve. */
    $repaired = fillMissingTemplate($conn, [
        'contract_id' => 0,
        'issued_template' => $deadPath,
    ]);

    t_same($livePath, $repaired['issued_template'] ?? null,
        'an agreement whose file is gone is re-issued the current template');

    /* And one whose file is there is left exactly as it was. */
    $kept = fillMissingTemplate($conn, [
        'contract_id' => 0,
        'issued_template' => $livePath,
    ]);

    t_same($livePath, $kept['issued_template'] ?? null,
        'an agreement whose file is still there keeps it, as it always did');
}

/* ------------------------------------- an upload failure says which one */

/*
| "The file could not be uploaded." covered seven different PHP upload
| errors, including the two that are about the server rather than the file:
| no temporary directory, and a failed write. On a host where those are the
| likely causes, a message that cannot name them sends somebody looking at
| their PDF.
*/
t_same('The file is larger than the server accepts.', uploadErrorMessage(UPLOAD_ERR_INI_SIZE),
    'too large for php.ini is named');
t_same('The file was only partly uploaded. Please try again.', uploadErrorMessage(UPLOAD_ERR_PARTIAL),
    'a partial upload is named');
t_ok(str_contains(uploadErrorMessage(UPLOAD_ERR_NO_TMP_DIR), 'temporary folder'),
    'a missing temp folder is named as a server fault, not the file');
t_ok(str_contains(uploadErrorMessage(UPLOAD_ERR_CANT_WRITE), 'could not write'),
    'and so is a failed write');
t_ok(str_contains(uploadErrorMessage(99), '99'),
    'an unknown code still carries the code, so it can be looked up');

t_done();
