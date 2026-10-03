<?php
/*
| Temporary companies for tests.
|
| Every row this creates is tagged with the CHATBOT-TEST prefix in
| company_name, so testCleanup() can find and remove it and a human can spot
| leftovers instantly. Nothing here ever touches a company it did not create.
*/
const CHATBOT_TEST_PREFIX = 'CHATBOT-TEST ';

function testMakeCompany(mysqli $conn, string $name, int $planId): int
{
    $companyName = CHATBOT_TEST_PREFIX . $name;

    $stmt = $conn->prepare("
        INSERT INTO company (company_name, owner_name) VALUES (?, 'Test Owner')
    ");
    $stmt->bind_param("s", $companyName);
    $stmt->execute();
    $companyId = (int) $conn->insert_id;
    $stmt->close();

    $stmt = $conn->prepare("
        INSERT INTO company_subscriptions
            (company_id, plan_id, billing_cycle, amount, start_date, expiry_date, status)
        VALUES (?, ?, 'Monthly', 0.00, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 1 YEAR), 'Active')
    ");
    $stmt->bind_param("ii", $companyId, $planId);
    $stmt->execute();
    $stmt->close();

    return $companyId;
}

/**
 * Removes every row any test created, children first so the foreign keys
 * never block the delete.
 *
 * Registered as a shutdown function by each test, so an assertion failure --
 * or a fatal error -- still cannot leave rows behind in a live database.
 */
function testCleanup(mysqli $conn): void
{
    $ids = [];
    $like = CHATBOT_TEST_PREFIX . '%';

    $stmt = $conn->prepare("SELECT company_id FROM company WHERE company_name LIKE ?");
    $stmt->bind_param("s", $like);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $ids[] = (int) $row['company_id'];
    }

    $stmt->close();

    if (!$ids) {
        return;
    }

    /*
    | Every table carrying a company_id, read from the schema rather than
    | listed by hand: inserting a sale fires a trigger that writes
    | capital_ledger, and a hand-written list silently misses rows like that.
    |
    | Foreign key checks are off for the duration so the delete order cannot
    | matter, and back on in the finally even if a delete throws. This is
    | test-only code operating on companies it created itself.
    */
    $tables = [];
    $result = $conn->query("
        SELECT TABLE_NAME
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND COLUMN_NAME = 'company_id'
          AND TABLE_NAME <> 'company'
    ");

    while ($row = $result->fetch_assoc()) {
        $tables[] = $row['TABLE_NAME'];
    }

    $conn->query("SET FOREIGN_KEY_CHECKS = 0");

    try {
        foreach ($ids as $companyId) {
            foreach ($tables as $table) {
                $stmt = $conn->prepare("DELETE FROM `{$table}` WHERE company_id = ?");
                $stmt->bind_param("i", $companyId);
                $stmt->execute();
                $stmt->close();
            }

            $stmt = $conn->prepare("DELETE FROM company WHERE company_id = ?");
            $stmt->bind_param("i", $companyId);
            $stmt->execute();
            $stmt->close();
        }
    } finally {
        $conn->query("SET FOREIGN_KEY_CHECKS = 1");
    }
}
