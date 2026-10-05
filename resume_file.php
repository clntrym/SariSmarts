<?php
/*
|--------------------------------------------------------------------------
| THE RESUME AN APPLICANT ATTACHED
|--------------------------------------------------------------------------
|
| These were files under uploads/resume/, linked to directly. They are rows
| now, because that folder is in .gitignore and so was never in the deploy,
| on a host that keeps no disk between deploys in any case -- the
| application row survived and the PDF did not.
|
| WHY THIS IS A PAGE AND NOT A FOLDER
|
| A CV carries somebody's address, their phone number and their history. A
| folder answers everyone the same; this asks who is reading.
|
| The path arrives from the browser and is never trusted as a location. It
| is matched against the applications of the company that is asking, and a
| path belonging to no application of theirs is a 404 whether or not it
| exists -- so one business cannot read another's applicants by guessing.
*/

require_once __DIR__ . '/init.php';

requireLogin();

$companyId = requireCompany();

require_once __DIR__ . '/includes/resume_file.php';

$path = resumePath((string) ($_GET['path'] ?? ''));

if ($path === '') {
    http_response_code(404);
    exit('No such resume.');
}

/*
| The row decides, not the URL.
|
| Old rows hold a bare filename and new ones hold the path, so both
| spellings are matched -- otherwise every resume attached before this
| change would become unreachable the moment it became servable.
*/
$bare = basename($path);

$stmt = $conn->prepare("
    SELECT 1
    FROM applications
    WHERE company_id = ?
      AND (resume = ? OR resume = ?)
    LIMIT 1
");
$stmt->bind_param("iss", $companyId, $path, $bare);
$stmt->execute();
$belongs = $stmt->get_result()->num_rows > 0;
$stmt->close();

if (!$belongs) {
    http_response_code(404);
    exit('No such resume.');
}

require_once __DIR__ . '/includes/stored_files.php';

$stored = platformFileRead($conn, $path);

$download = ($_GET['download'] ?? '') !== '' ? 'attachment' : 'inline';

if ($stored !== null) {

    header('Content-Type: ' . ($stored['mime'] ?: 'application/pdf'));
    header('Content-Length: ' . strlen($stored['bytes']));
    header('Content-Disposition: ' . $download . '; filename="'
        . ($stored['name'] !== '' ? $stored['name'] : $bare) . '"');
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');

    echo $stored['bytes'];
    exit;
}

/* Attached before the store existed, on a host that still has the disk. */
$onDisk = realpath(__DIR__ . '/' . $path);
$folder = realpath(__DIR__ . '/uploads/resume');

if ($onDisk !== false && $folder !== false && str_starts_with($onDisk, $folder)) {

    header('Content-Type: application/pdf');
    header('Content-Length: ' . filesize($onDisk));
    header('Content-Disposition: ' . $download . '; filename="' . $bare . '"');
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');

    readfile($onDisk);
    exit;
}

http_response_code(404);
exit('That resume is no longer on record.');
