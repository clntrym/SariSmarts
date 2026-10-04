<?php
/*
|--------------------------------------------------------------------------
| SCHEMA DOCTOR
|--------------------------------------------------------------------------
|
| Says what the deployed database is missing, by name.
|
| Why this exists: the code deployed to Render is byte for byte the code that
| runs on XAMPP, and the pages that return 500 there run clean here. What
| differs is the database. Migrations under platform/database/ were applied to
| the development database one at a time over months; the production one was
| created from an older dump, so a page that reads a column added in August is
| a fatal error in production and perfect locally.
|
| With display_errors Off -- correctly Off, since a PHP error names tables and
| paths -- that failure is a blank page with nothing to go on. This turns it
| into a list.
|
| It is READ ONLY. It compares information_schema against the snapshot in
| schema_expected.json and prints the difference. It changes nothing.
|
| Run it where the production database is reachable -- the Render Shell:
|
|     php tools/schema_doctor.php
|
| To check the local database instead:
|
|     php tools/schema_doctor.php --local
*/

$local = in_array('--local', $argv, true);

if ($local) {
    $conn = new mysqli('localhost', 'root', '', 'sari');
    $where = 'local XAMPP';
} else {
    /* The same resolution config.php uses, so this reads whatever the app
       reads and cannot disagree with it. */
    $host = getenv('DB_HOST') ?: 'sarismarts-db.mysql.database.azure.com';
    $user = getenv('DB_USER') ?: 'sariAdmin';
    $pass = getenv('DB_PASS') ?: '';
    $name = getenv('DB_NAME') ?: 'sari';

    if ($pass === '') {
        /* config.php still carries a fallback password. Read it from there
           rather than printing or duplicating it here. */
        $configPath = __DIR__ . '/../config.php';

        if (is_readable($configPath)
            && preg_match('/\$password\s*=\s*getenv\([^)]*\)\s*\?:\s*\'([^\']+)\'/', (string) file_get_contents($configPath), $m)) {
            $pass = $m[1];
        }
    }

    $conn = new mysqli($host, $user, $pass, $name);
    $where = $host;
}

if ($conn->connect_error) {
    echo "Cannot reach the database (", $where, "): ", $conn->connect_error, "\n";
    exit(1);
}

$expectedPath = __DIR__ . '/schema_expected.json';

if (!is_readable($expectedPath)) {
    echo "schema_expected.json is missing next to this script.\n";
    exit(1);
}

$expected = json_decode((string) file_get_contents($expectedPath), true);

if (!is_array($expected)) {
    echo "schema_expected.json is not readable JSON.\n";
    exit(1);
}

/* What is actually there. */
$actual = [];
$result = $conn->query("
    SELECT TABLE_NAME, COLUMN_NAME
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
");

while ($row = $result->fetch_assoc()) {
    $actual[$row['TABLE_NAME']][] = $row['COLUMN_NAME'];
}

echo "\nSchema doctor — ", $where, "\n";
echo str_repeat('-', 64), "\n";
echo "  expected  ", count($expected), " tables\n";
echo "  found     ", count($actual), " tables\n\n";

$missingTables = [];
$missingColumns = [];

foreach ($expected as $table => $columns) {

    if (!isset($actual[$table])) {
        $missingTables[] = $table;
        continue;
    }

    $absent = array_diff($columns, $actual[$table]);

    if ($absent !== []) {
        $missingColumns[$table] = array_values($absent);
    }
}

if ($missingTables === [] && $missingColumns === []) {
    echo "  Nothing is missing. The database is not what is breaking those pages.\n\n";
    exit(0);
}

if ($missingTables !== []) {
    echo "  MISSING TABLES (", count($missingTables), ")\n\n";

    foreach ($missingTables as $table) {
        echo "    ", $table, "\n";
    }

    echo "\n";
}

if ($missingColumns !== []) {
    echo "  MISSING COLUMNS (", count($missingColumns), " tables affected)\n\n";

    foreach ($missingColumns as $table => $columns) {
        echo "    ", str_pad($table, 32), implode(', ', $columns), "\n";
    }

    echo "\n";
}

/* Extra tables are not an error -- a production database may keep things the
   development one has dropped -- but they are worth seeing. */
$extra = array_diff(array_keys($actual), array_keys($expected));

if ($extra !== []) {
    echo "  Present in production but not in the snapshot (not an error):\n    ",
         implode(', ', $extra), "\n\n";
}

echo "  Each missing piece has a migration under platform/database/.\n";
echo "  Apply the ones named above, then reload the pages that were failing.\n\n";

exit(1);
