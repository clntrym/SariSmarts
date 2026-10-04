<?php
/*
| The public job board.
|
| A company published a cashier vacancy. HR listed it as Published, with a
| deadline two weeks out, and the careers page said "No Open Positions".
|
| Two things made that possible, and the second is why the first went
| unnoticed for so long.
|
| The query asked:
|
|     j.application_deadline IS NULL
|     OR j.application_deadline = ''
|     OR j.application_deadline >= CURDATE()
|
| application_deadline is a DATE. A DATE is never the empty string, so that
| middle test cannot be true -- it is not a condition, it is a type error
| wearing one. MariaDB answers it with "Warning 1292: Truncated incorrect
| datetime value" and carries on. MySQL, which is what the deployed site
| runs, is entitled to refuse it outright, and the same difference between
| those two servers has produced four bugs in this project already.
|
| And the page caught any failure and rendered the empty state. A query that
| errored and a company with nothing to advertise produced the same screen,
| so there was nothing to notice -- the one view that could have reported
| the fault was built to hide it.
*/
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../platform/includes/job_board.php';

$conn = $GLOBALS['conn'];

/* A company, a branch and a job, removed however this ends. */
$made = userTestMake($conn, 'Careers', 'active');
$companyId = (int) $made['company_id'];

$conn->query("INSERT INTO branch (company_id, branch_name, barangay, opening_date)
              VALUES ({$companyId}, 'USERTEST Branch', 'Poblacion', CURDATE())");
$branchId = (int) $conn->insert_id;

$jobIds = [];

$makeJob = static function (string $title, string $status, ?string $deadline)
        use ($conn, $companyId, $branchId, &$jobIds): int {

    $stmt = $conn->prepare("
        INSERT INTO job (company_id, branch_id, job_title, department, vacancies,
                         employment_type, applications, status, interviews,
                         application_deadline, created_at)
        VALUES (?, ?, ?, 'Cashier', 2, 'Full-time', 0, ?, 0, ?, CURDATE())
    ");
    $stmt->bind_param("iisss", $companyId, $branchId, $title, $status, $deadline);
    $stmt->execute();
    $stmt->close();

    return $jobIds[] = (int) $conn->insert_id;
};

register_shutdown_function(static function () use ($conn, &$jobIds, $branchId) {
    foreach ($jobIds as $id) {
        $conn->query("DELETE FROM job WHERE job_id = " . (int) $id);
    }
    $conn->query("DELETE FROM branch WHERE branch_id = " . (int) $branchId);
});

/* ------------------------------------------------- what the board shows */

$open = $makeJob('USERTEST Cashier', 'Published', date('Y-m-d', strtotime('+14 days')));

$listed = publishedJobs($conn);
$titles = array_column($listed, 'job_title');

t_ok(in_array('USERTEST Cashier', $titles, true),
    'a published job with a deadline ahead of it is on the board');

/* The branch name comes with it, which is what the card shows. */
foreach ($listed as $row) {
    if ($row['job_title'] === 'USERTEST Cashier') {
        t_same('USERTEST Branch', $row['branch_name'] ?? null,
            'and carries its branch');
    }
}

/* ------------------------------------------------ what it does not show */

$makeJob('USERTEST Draft', 'Draft', date('Y-m-d', strtotime('+14 days')));
$makeJob('USERTEST Expired', 'Published', date('Y-m-d', strtotime('-1 day')));

$titles = array_column(publishedJobs($conn), 'job_title');

t_ok(!in_array('USERTEST Draft', $titles, true), 'a draft is not advertised');
t_ok(!in_array('USERTEST Expired', $titles, true), 'nor is a closed vacancy');

/*
| A deadline that was never set. NULL is the only way a DATE says "none" --
| the query used to also test for the empty string, which a DATE can never
| hold, and which is what MySQL was entitled to refuse.
*/
$makeJob('USERTEST Openended', 'Published', null);

$titles = array_column(publishedJobs($conn), 'job_title');

t_ok(in_array('USERTEST Openended', $titles, true),
    'a vacancy with no deadline stays open');

/* --------------------------------------- the query is clean, not merely right */

/*
| Warning 1292 is the fingerprint of the comparison that could not be true.
| Asserting on the warning rather than on the result is the point: the old
| query returned the correct rows here AND was refusable on the other
| server, so a test of the rows alone would have passed on both days.
*/
$conn->query("SELECT 1");
publishedJobs($conn);

$warnings = [];
$result = $conn->query("SHOW WARNINGS");

while ($result && $row = $result->fetch_assoc()) {
    $warnings[] = $row['Message'];
}

t_same([], $warnings,
    'the board asks nothing the server has to complain about: '
    . implode(' | ', $warnings));

/* -------------------------------------- a failure is not an empty board */

$source = (string) file_get_contents(dirname(__DIR__, 2) . '/platform/careers.php');

t_ok(!str_contains($source, "= ''"),
    'careers.php no longer compares a date to the empty string');
t_ok(str_contains($source, 'jobBoardFailed'),
    'and tells a visitor when the board could not be read, '
    . 'rather than showing them an empty one');

t_done();
