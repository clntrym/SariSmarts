<?php
/*
|--------------------------------------------------------------------------
| THE PUBLIC JOB BOARD
|--------------------------------------------------------------------------
|
| Every vacancy a company has published, for careers.php to show.
|
| It is a function rather than a query inside the page because the page
| could not tell two situations apart, and a visitor could not either:
|
|   nobody is hiring              an empty board, correctly
|   the query failed              an empty board, incorrectly
|
| careers.php caught any failure and rendered the empty state, so a company
| that had just published a cashier vacancy was shown "No Open Positions"
| and had no reason to think anything was broken. The one screen that could
| have reported the fault was built to hide it.
|
| So this throws, and the page says which of the two it is.
|
| THE QUERY THAT CAUSED IT
|
| The condition used to read:
|
|     j.application_deadline IS NULL
|     OR j.application_deadline = ''
|     OR j.application_deadline >= CURDATE()
|
| application_deadline is a DATE, and a DATE is never the empty string. That
| middle test cannot be true; it is not a condition, it is a type error in
| the shape of one. MariaDB answers it with "Warning 1292: Truncated
| incorrect datetime value" and carries on, which is why it worked on XAMPP
| for as long as anybody looked. MySQL, which the deployed site runs, is
| entitled to refuse it -- and the same difference between those two servers
| has already produced four bugs in this project.
|
| NULL is how a DATE says "no deadline". That is the whole of it.
*/

if (!function_exists('publishedJobs')) {

    /**
     * @return array<int, array<string, mixed>>
     *
     * @throws RuntimeException when the board cannot be read
     */
    function publishedJobs(mysqli $conn): array
    {
        $result = $conn->query("
            SELECT
                j.*,
                b.branch_name
            FROM job j
            INNER JOIN branch b
                ON j.branch_id = b.branch_id
            WHERE j.status = 'Published'
              AND (j.application_deadline IS NULL
                   OR j.application_deadline >= CURDATE())
            ORDER BY j.created_at DESC
        ");

        if ($result === false) {
            throw new RuntimeException($conn->error ?: 'The job board could not be read.');
        }

        $jobs = [];

        while ($row = $result->fetch_assoc()) {
            $jobs[] = $row;
        }

        return $jobs;
    }
}
