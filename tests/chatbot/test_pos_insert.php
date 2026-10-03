<?php
/*
| The POS sale INSERT, taken from cashier/pointofsales.php itself and executed
| against a throwaway company.
|
| Adding created_by changed live selling code. Reading the diff proves the
| columns look right; this proves the statement the page actually carries still
| prepares, still binds, and still records a sale -- with the cashier on it.
*/
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/factory.php';

register_shutdown_function(function () use ($conn) { testCleanup($conn); });

$source = file_get_contents(__DIR__ . '/../../cashier/pointofsales.php');

preg_match('/INSERT INTO sales\s*\((.*?)\)\s*VALUES\s*\((.*?)\)/s', $source, $match, PREG_OFFSET_CAPTURE);

t_ok(!empty($match), 'the POS sale INSERT was found in pointofsales.php');

/* Offsets matter: the file holds several prepared statements, and the bind
   for this one is the first that follows the sales INSERT. */
$insertEnd = (int) $match[0][1] + strlen($match[0][0]);
$match = [$match[0][0], $match[1][0], $match[2][0]];

$columns = array_values(array_filter(array_map('trim', explode(',', $match[1] ?? ''))));
$placeholders = array_values(array_filter(array_map('trim', explode(',', $match[2] ?? ''))));

t_same(count($columns), count($placeholders),
    'the POS insert binds one placeholder per column');
t_ok(in_array('created_by', $columns, true), 'the POS insert records created_by');

/* The bind type string the page passes for THIS statement. */
preg_match(
    "/mysqli_stmt_bind_param\(\s*\\\$stmt,\s*'([a-z]+)',/",
    substr($source, $insertEnd),
    $types
);

t_same(count($columns), strlen($types[1] ?? ''),
    'the bind type string has one letter per column');

/* Now run it for real. */
$companyId = testMakeCompany($conn, 'POS Insert Co', 1);

$conn->query("INSERT INTO users (company_id, username, fullname, email, password, role, status)
              VALUES ({$companyId}, 'posman', 'POS Man', 'pos@test.local', 'x', 'cashier', 'active')");
$userId = (int) $conn->insert_id;

$sql = 'INSERT INTO sales (' . $match[1] . ') VALUES (' . $match[2] . ')';
$stmt = $conn->prepare($sql);

t_ok($stmt !== false, 'the statement the POS page carries still prepares');

$total = 123.45;
$tax = 0.00;
$cash = 200.00;
$change = 76.55;
$method = 'Cash';
$reference = null;

$stmt->bind_param($types[1], $companyId, $userId, $total, $tax, $cash, $change, $method, $reference);
$stmt->execute();
$saleId = (int) $conn->insert_id;
$stmt->close();

t_ok($saleId > 0, 'a sale was recorded');

$check = $conn->prepare("SELECT created_by, total_amount FROM sales
                         WHERE company_id = ? AND sale_id = ?");
$check->bind_param("ii", $companyId, $saleId);
$check->execute();
$row = $check->get_result()->fetch_assoc();
$check->close();

t_same($userId, (int) $row['created_by'], 'the sale carries the cashier who rang it up');
t_same('123.45', $row['total_amount'], 'the amount was stored unchanged');

t_done();
