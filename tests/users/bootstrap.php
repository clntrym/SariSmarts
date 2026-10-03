<?php
/*
| The user-access suite's bootstrap.
|
| Like the chatbot suite it tags every row it creates so cleanup can find it,
| and removes them again however the test ends.
*/
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../harness.php';

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
