<?php
/*
| The user-access suite's bootstrap.
|
| Like the chatbot suite it tags every row it creates so cleanup can find it,
| and removes them again however the test ends.
*/

/*
| Tests use the local database. Always.
|
| config.php decides which database to open from $_SERVER['SERVER_NAME'], and
| on the command line there is no such key -- so a test process took the
| PRODUCTION branch and, while config.php still carried a literal password,
| connected to the live Azure database. Every suite run was creating and
| deleting companies there. Cleanup ran, so nothing was left behind, but a
| test that died before its shutdown handler would have left rows in
| production, and testCleanup() turns off foreign key checks to do its work.
|
| Setting SERVER_NAME here, before init.php is loaded, puts config.php on its
| local branch. The assertion below is what makes it a guarantee rather than
| an intention: if a test ever reaches a host that is not this machine, the
| run stops before it touches anything.
*/
$_SERVER['SERVER_NAME'] = 'localhost';

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../harness.php';

/* Proof, not intent: stop before the first query if this is not local. */
$probe = $conn->query("SELECT @@hostname AS host");
$host = $probe ? (string) $probe->fetch_assoc()['host'] : '';

if (!in_array(strtolower($host), ['localhost', '127.0.0.1', gethostname(), strtolower((string) gethostname())], true)) {
    fwrite(STDERR, "REFUSING TO RUN: the tests opened a database on '{$host}', "
        . "which is not this machine. They would create and delete rows there.\n");
    exit(1);
}

const USER_TEST_PREFIX = 'USERTEST ';

/**
 * A company with one user in it, at a given status.
 */
function userTestMake(mysqli $conn, string $label, string $status, string $password = 'correct-horse'): array
{
    $companyName = USER_TEST_PREFIX . $label;

    $stmt = $conn->prepare("INSERT INTO company (company_name, owner_name) VALUES (?, 'Test Owner')");
    $stmt->bind_param("s", $companyName);
    $stmt->execute();
    $companyId = (int) $conn->insert_id;
    $stmt->close();

    $email = strtolower(str_replace(' ', '', $label)) . '@usertest.invalid';
    $fullname = USER_TEST_PREFIX . $label;
    $hash = password_hash($password, PASSWORD_DEFAULT);

    $stmt = $conn->prepare("
        INSERT INTO users (company_id, fullname, email, password, role, status)
        VALUES (?, ?, ?, ?, 'admin', ?)
    ");
    $stmt->bind_param("issss", $companyId, $fullname, $email, $hash, $status);
    $stmt->execute();
    $userId = (int) $conn->insert_id;
    $stmt->close();

    return ['company_id' => $companyId, 'user_id' => $userId,
            'email' => $email, 'password' => $password];
}

function userTestCleanup(mysqli $conn): void
{
    $like = USER_TEST_PREFIX . '%';

    $stmt = $conn->prepare("DELETE FROM users WHERE fullname LIKE ?");
    $stmt->bind_param("s", $like);
    $stmt->execute();
    $stmt->close();

    /*
    | Everything hanging off the company, before the company.
    |
    | This deleted users and company and nothing else, which worked only
    | while a test created nothing else. A suite that makes a branch, or a
    | job, or a stock request leaves the company undeletable:
    |
    |     Cannot delete or update a parent row: a foreign key constraint
    |     fails (`sari`.`branch`, CONSTRAINT `fk_branch_company` ...)
    |
    | The tables are read from the schema rather than listed, for the same
    | reason wipe_company.php reads them: a hand-written list is how one
    | gets missed, and the one that gets missed is the one that breaks the
    | next suite.
    */
    $companies = [];
    $stmt = $conn->prepare("SELECT company_id FROM company WHERE company_name LIKE ?");
    $stmt->bind_param("s", $like);
    $stmt->execute();
    $rows = $stmt->get_result();

    while ($row = $rows->fetch_assoc()) {
        $companies[] = (int) $row['company_id'];
    }

    $stmt->close();

    if ($companies === []) {
        return;
    }

    $ids = implode(',', $companies);

    $tables = [];
    $result = $conn->query("
        SELECT TABLE_NAME FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND COLUMN_NAME = 'company_id'
          AND TABLE_NAME <> 'company'
    ");

    while ($result && $row = $result->fetch_assoc()) {
        $tables[] = $row['TABLE_NAME'];
    }

    /* Order cannot be right for every schema, so it is taken out of the
       question -- exactly as wipe_company.php does. */
    $conn->query("SET FOREIGN_KEY_CHECKS = 0");

    foreach ($tables as $table) {
        @$conn->query("DELETE FROM `{$table}` WHERE company_id IN ({$ids})");
    }

    @$conn->query("DELETE FROM `company` WHERE company_id IN ({$ids})");

    $conn->query("SET FOREIGN_KEY_CHECKS = 1");
}

/*
| Run however the test ends.
|
| This file's own description says the rows are "removed again however the
| test ends", and that was only true of the one suite that remembered to
| call it. Four companies from four different suites were still sitting in
| the local database, and tools/plan_status.php listed them as real
| businesses with no subscription -- a diagnostic reporting test litter as
| a finding.
|
| Registered here rather than asked of each suite, because a cleanup you
| have to remember is a cleanup that gets forgotten. A suite that calls it
| explicitly, as test_account_access.php does mid-run, still works: the
| deletes are by name prefix and run twice harmlessly.
*/
register_shutdown_function(static function () use ($conn): void {

    /*
    | Shutdown handlers run in the order they were registered, and this one
    | is registered first -- before any a suite adds for its own fixtures.
    | So it runs first, while those fixtures are still there, which is why
    | it has to be able to remove them rather than assume they are gone.
    |
    | And it must never be the thing that fails a green run: a cleanup that
    | throws turns a passing suite into a failing one, which is how this
    | was first noticed.
    */
    try {
        userTestCleanup($conn);
    } catch (Throwable $error) {
        fwrite(STDERR, "cleanup: " . $error->getMessage() . "\n");
    }
});
