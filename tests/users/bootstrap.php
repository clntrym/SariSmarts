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

    $stmt = $conn->prepare("DELETE FROM company WHERE company_name LIKE ?");
    $stmt->bind_param("s", $like);
    $stmt->execute();
    $stmt->close();
}
