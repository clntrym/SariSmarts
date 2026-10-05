<?php
/*
| The photograph taken at time-in.
|
| An HR employee pressed Time In and got:
|
|     Unable to save the captured photo.
|
| save_attendance.php writes the JPEG to ../uploads/attendance/, a folder
| that is in .gitignore and therefore not in the deploy, on a host that
| keeps no disk between deploys anyway. file_put_contents() returned false
| and the attendance was refused -- correctly, because the photograph is the
| evidence that the person was there, and a timestamp without it proves
| nothing.
|
| Same fault as the contract PDFs, so the same answer: the bytes go to the
| database, which is the only durable thing this deployment has. The path
| the attendance row stores is untouched and becomes the key.
|
| There are six copies of save_attendance.php -- one per role -- which is
| why this checks all of them rather than the one that was reported.
*/
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../includes/stored_files.php';

$conn = $GLOBALS['conn'];

/* ------------------------------------------- the store reaches both apps */

t_ok(function_exists('platformFileStore'),
    'the store now lives in includes/, where both applications can require it');

$path = 'uploads/attendance/USERTEST_' . bin2hex(random_bytes(6)) . '.jpg';

register_shutdown_function(static function () use ($conn, $path) {
    @platformFileForget($conn, $path);
});

/* A JPEG is binary, and starts with bytes a text column would mangle. */
$jpeg = "\xFF\xD8\xFF\xE0" . random_bytes(1024) . "\xFF\xD9";

t_ok(platformFileStore($conn, $path, $jpeg, 'image/jpeg', 'timein.jpg'),
    'a captured photo can be stored');
t_same($jpeg, platformFileRead($conn, $path)['bytes'] ?? null,
    'and comes back byte for byte');
t_same('image/jpeg', platformFileRead($conn, $path)['mime'] ?? null,
    'as an image, not as a PDF -- the browser is told what it is');

/* ----------------------------------------- every role saves the same way */

$root = dirname(__DIR__, 2);

$savers = glob($root . '/*/save_attendance.php') ?: [];

t_same(5, count($savers),
    'there are five copies of save_attendance.php, one per role: ' . count($savers));

foreach ($savers as $saver) {

    $name = str_replace($root . DIRECTORY_SEPARATOR, '', $saver);
    $code = (string) preg_replace(['~/\*.*?\*/~s', '~(?<!:)//[^\n]*~'],
        '', (string) file_get_contents($saver));

    /*
    | The store is what decides. Each saver still mirrors the JPEG to the
    | disk, because a host that has one may as well keep it -- but that
    | write is suppressed and its result ignored, so a missing folder can
    | no longer refuse an employee their time-in.
    */
    t_ok(preg_match('~if\s*\(\s*!\s*platformFileStore\(~', $code) === 1,
        "{$name} refuses the time-in only when the STORE fails");

    t_ok(!preg_match('~(?<!@)file_put_contents\(\s*\$filepath~', $code),
        "{$name} does not let a write to the disk decide anything");
}

/* ------------------------------------------------- and it can be read back */

/*
| A photograph nobody can look at is not evidence. The rows hold
| "uploads/attendance/x.jpg", which was an <img src> and is now a key, so
| something has to serve it -- and serve it only to the company it belongs
| to, which is the whole reason it is an endpoint rather than a folder.
*/
t_ok(is_file($root . '/attendance_photo.php'),
    'there is one place that serves a stored attendance photo');

$server = (string) file_get_contents($root . '/attendance_photo.php');

t_ok(str_contains($server, 'requireCompany'),
    'and it asks which company is looking');
t_ok(str_contains($server, 'company_id'),
    'and matches the photo against that company, not against the URL');

t_done();
