<?php
/*
| Writes that this server forgives and the deployed one refuses.
|
| An inventory clerk raised a stock request and got:
|
|     Column 'payment_type' cannot be null
|
| includes/stock_request_create.php did this:
|
|     $startingPayment = $hasFinanceApprover ? null : 'Capital';
|
| and bound it into payment_type, which is
| enum('Capital','Payable') NOT NULL DEFAULT 'Capital'. Writing NULL into a
| NOT NULL column is an error under STRICT_TRANS_TABLES -- which MySQL 8.4
| has on by default and this machine's MariaDB does not. So the request
| succeeded here and failed there, for every company that HAS a Finance
| approver, which is every company on a plan that includes one.
|
| It is the fifth time the two servers have disagreed in this project.
|
| WHY THIS TEST SETS sql_mode
|
| The bug cannot be reproduced locally by running the code: the local server
| forgives it. So the test makes the local server behave like the deployed
| one for the length of one connection, and the write that would be refused
| there is refused here. That is the difference between a test that would
| have caught this and one that would have gone green beside it.
*/
require_once __DIR__ . '/bootstrap.php';

$conn = $GLOBALS['conn'];

/* What the deployed server enforces, borrowed for this connection. */
$was = $conn->query("SELECT @@sql_mode AS m")->fetch_assoc()['m'];

$conn->query("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'");

register_shutdown_function(static function () use ($conn, $was) {
    @$conn->query("SET SESSION sql_mode = '" . $conn->real_escape_string($was) . "'");
});

$strict = $conn->query("SELECT @@sql_mode AS m")->fetch_assoc()['m'];

t_ok(str_contains($strict, 'STRICT_TRANS_TABLES'),
    'this connection now refuses what the deployed server refuses');

/* ------------------------------------------- the column, and what it takes */

$column = $conn->query("
    SELECT IS_NULLABLE, COLUMN_DEFAULT
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'stock_requests'
      AND COLUMN_NAME = 'payment_type'
")->fetch_assoc();

t_same('NO', $column['IS_NULLABLE'] ?? null,
    'payment_type is NOT NULL, which is why a null is an error and not a default');
/* MariaDB reports a string default already quoted; MySQL does not. */
t_same('Capital', trim((string) ($column['COLUMN_DEFAULT'] ?? ''), "'"),
    'and its default is Capital -- the same value the Finance form pre-selects');

/* ------------------------------------------------- the write itself */

$made = userTestMake($conn, 'StockReq', 'active');
$companyId = (int) $made['company_id'];
$code = 'USERTEST-' . bin2hex(random_bytes(4));

register_shutdown_function(static function () use ($conn, $code) {
    $conn->query("DELETE FROM stock_requests WHERE request_code = '"
        . $conn->real_escape_string($code) . "'");
});

$insert = static function (?string $payment) use ($conn, $companyId, $code): ?string {

    $stmt = $conn->prepare("
        INSERT INTO stock_requests
            (company_id, request_code, expense_category, reason,
             total_price, status, payment_type, created_by)
        VALUES (?, ?, 'Restocking', 'USERTEST', 100.00, 'Pending Finance', ?, 1)
    ");

    $stmt->bind_param("iss", $companyId, $code, $payment);

    try {
        $ok = $stmt->execute();
    } catch (Throwable $error) {
        $stmt->close();

        return $error->getMessage();
    }

    $problem = $ok ? null : $stmt->error;
    $stmt->close();

    return $problem;
};

/*
| The write exactly as it was. Under the deployed server's rules it is
| refused, and the inventory clerk is shown the refusal.
*/
$problem = $insert(null);

t_ok($problem !== null && str_contains($problem, 'payment_type'),
    'a null payment_type is refused, naming the column: ' . (string) $problem);

/* And the value the code now sends. */
$conn->query("DELETE FROM stock_requests WHERE request_code = '"
    . $conn->real_escape_string($code) . "'");

t_same(null, $insert('Capital'),
    'Capital is accepted, which is what a request waiting on Finance now carries');

/* ------------------------------------------------- and the code sends it */

$source = (string) preg_replace(['~/\*.*?\*/~s', '~(?<!:)//[^\n]*~'],
    '', (string) file_get_contents(dirname(__DIR__, 2) . '/includes/stock_request_create.php'));

t_ok(!preg_match('~\$startingPayment\s*=\s*\$hasFinanceApprover\s*\?\s*null~', $source),
    'the request no longer starts with no payment type at all');

t_ok(str_contains($source, "\$startingPayment = 'Capital'"),
    'it starts on Capital, the value the Finance screen already pre-selects');

t_done();
