<?php
/*
|--------------------------------------------------------------------------
| THE PHOTOGRAPH TAKEN AT TIME-IN
|--------------------------------------------------------------------------
|
| These used to be files under uploads/attendance/, served by the web server
| the way any image is. They are rows now, because the folder is in
| .gitignore and so was never in the deploy, on a host that keeps no disk
| between deploys in any case -- an employee pressing Time In was told
| "Unable to save the captured photo" and their attendance was refused.
|
| Refused correctly: the photograph is the evidence that the person was
| there, and a timestamp on its own proves nothing.
|
| WHY THIS IS A PAGE AND NOT A FOLDER
|
| A folder answers everyone the same. These photographs are of named people
| at their workplace, and which company's they are has to be asked.
|
| The path arrives from the browser and is never trusted as a location. It
| is matched against the attendance rows of the company that is asking, and
| a path that belongs to no row of theirs is a 404 whether or not it exists
| -- so one business cannot read another's by guessing, and an employee
| cannot read a colleague's by editing a URL any further than their own
| company's records, which is what their own attendance page already shows
| them.
*/

require_once __DIR__ . '/init.php';

requireLogin();

$companyId = requireCompany();

$path = trim((string) ($_GET['path'] ?? ''));

/* No traversal can survive this, and nothing outside the one folder is
   servable even if a row somehow named it. */
if ($path === '' || !str_starts_with($path, 'uploads/attendance/')
    || str_contains($path, '..')) {

    http_response_code(404);
    exit('No such photograph.');
}

/*
| The row decides, not the URL. A photograph is servable only where an
| attendance record of THIS company names it.
*/
$stmt = $conn->prepare("
    SELECT 1
    FROM attendance
    WHERE company_id = ?
      AND (photo_in = ? OR photo_out = ?)
    LIMIT 1
");
$stmt->bind_param("iss", $companyId, $path, $path);
$stmt->execute();
$belongs = $stmt->get_result()->num_rows > 0;
$stmt->close();

if (!$belongs) {
    http_response_code(404);
    exit('No such photograph.');
}

require_once __DIR__ . '/includes/stored_files.php';

$stored = platformFileRead($conn, $path);

if ($stored !== null) {

    header('Content-Type: ' . ($stored['mime'] ?: 'image/jpeg'));
    header('Content-Length: ' . strlen($stored['bytes']));
    header('Cache-Control: private, max-age=3600');
    header('X-Content-Type-Options: nosniff');

    echo $stored['bytes'];
    exit;
}

/*
| Photographs taken before the store existed are still on the disk of a
| machine that has one -- every XAMPP install, and nothing on Render.
*/
$onDisk = realpath(__DIR__ . '/' . $path);
$folder = realpath(__DIR__ . '/uploads/attendance');

if ($onDisk !== false && $folder !== false && str_starts_with($onDisk, $folder)) {

    header('Content-Type: image/jpeg');
    header('Content-Length: ' . filesize($onDisk));
    header('Cache-Control: private, max-age=3600');
    header('X-Content-Type-Options: nosniff');

    readfile($onDisk);
    exit;
}

http_response_code(404);
exit('That photograph is no longer on record.');
