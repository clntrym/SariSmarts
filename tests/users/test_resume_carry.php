<?php
/*
| The resume an applicant attached, where HR looks for it later.
|
| Somebody applied for a job and attached a CV. In Employee Registration ->
| Documents, the Resume / CV card said "Missing" and offered to upload one.
|
| Three things are wrong on that path, and they hide each other.
|
| ONE: applications.resume holds a bare filename -- "1759_cv.pdf" -- while
| the file sits in uploads/resume/. The card renders it straight into an
| href, so even a resume that IS on file links to /hr/1759_cv.pdf, which is
| nowhere. Every other document in the system stores its folder.
|
| TWO: uploads/resume/ is in .gitignore, so it is not in the deploy, and
| Render keeps no disk between deploys anyway. The row survives; the PDF
| does not.
|
| THREE: the card asks one question -- is the column empty? -- and answers
| both "nobody attached a CV" and "the CV is gone" with the same word. That
| is the same fault as the careers board reporting a failed query as an
| empty one, and it is why nobody could tell which had happened.
*/
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../includes/stored_files.php';
require_once __DIR__ . '/../../includes/resume_file.php';

$conn = $GLOBALS['conn'];

/* ------------------------------------------------- the path, not the name */

t_same('uploads/resume/1759_cv.pdf', resumePath('1759_cv.pdf'),
    'a bare filename, as the old rows hold it, resolves to where the file lives');
t_same('uploads/resume/1759_cv.pdf', resumePath('uploads/resume/1759_cv.pdf'),
    'and a full path is left alone, so new rows and old ones read the same');
t_same('', resumePath(''), 'nothing is nothing');
t_same('', resumePath(null), 'and so is nothing at all');

/*
| A name that tries to climb out. The value reaches an href and a file
| lookup, and it came from the applicant's own upload.
*/
t_same('', resumePath('../../config.php'), 'a name that climbs is refused');
t_same('', resumePath('uploads/resume/../../config.php'), 'however it is spelled');

/* ------------------------------------------- stored where a deploy cannot reach */

$path = 'uploads/resume/USERTEST_' . bin2hex(random_bytes(6)) . '.pdf';

register_shutdown_function(static function () use ($conn, $path) {
    @platformFileForget($conn, $path);
});

$pdf = "%PDF-1.4\n" . random_bytes(512) . "\n%%EOF";

t_ok(platformFileStore($conn, $path, $pdf, 'application/pdf', 'cv.pdf'),
    'a resume can be stored');
t_same($pdf, platformFileRead($conn, $path)['bytes'] ?? null, 'and read back whole');

/* ------------------------------------------- the three states are distinct */

/*
| "Missing" meant two things. They want different actions from the HR
| officer in front of it -- ask the applicant, or stop looking -- so they
| are different answers.
*/
t_same('none', resumeState($conn, '', __DIR__),
    'no resume attached is its own answer');
t_same('stored', resumeState($conn, $path, dirname(__DIR__, 2)),
    'a resume on file is another');
t_same('lost', resumeState($conn, 'uploads/resume/gone_' . bin2hex(random_bytes(4)) . '.pdf',
    dirname(__DIR__, 2)),
    'and a resume that was attached and is no longer there is a third');

/* ------------------------------------------------------ apply.php stores it */

$root = dirname(__DIR__, 2);

$apply = (string) preg_replace(['~/\*.*?\*/~s', '~(?<!:)//[^\n]*~'],
    '', (string) file_get_contents($root . '/accounts/apply.php'));

t_ok(str_contains($apply, 'platformFileStore'),
    'an attached resume is kept where a deploy cannot reach it');
t_ok(str_contains($apply, 'resumePath('),
    'and recorded as a path rather than a bare name');

/* -------------------------------------------------- and something serves it */

t_ok(is_file($root . '/resume_file.php'),
    'there is one place that serves a stored resume');

$server = (string) file_get_contents($root . '/resume_file.php');

t_ok(str_contains($server, 'requireCompany'), 'which asks which company is looking');
t_ok(str_contains($server, 'company_id'),
    'and matches the resume against that company, not against the URL');

/* ------------------------------------- the card no longer says Missing for both */

$registration = (string) file_get_contents($root . '/hr/employee_registration.php');

t_ok(str_contains($registration, 'resumeState'),
    'the Resume card asks which of the three states it is in');
t_ok(!str_contains($registration, 'htmlspecialchars($selectedEmployee[\'resume\']) ?>"'),
    'and no longer puts a bare filename straight into an href');

t_done();
