<?php
/*
| ENVIRONMENT DETECTION
|
| Production is the place that was given database credentials. Nowhere else
| is.
|
| This used to read $_SERVER['SERVER_NAME'], which has two faults. On the
| command line there is no such key at all, so every CLI script -- cron jobs,
| diagnostics, the whole test suite -- took the PRODUCTION branch and, while
| config.php still carried a literal password, connected to the live database.
| And a new hostname, a preview URL or a custom domain would each have needed
| adding to the list.
|
| DB_PASS is set on Render and nowhere else, so it answers the question
| directly rather than by inference, and it answers it the same way in a
| browser request, a cron job and a shell.
*/
$isLocal = (string) getenv('DB_PASS') === '';

$BASE_URL = "/platform";

if ($isLocal) {
    // Local XAMPP
    $servername = "localhost";
    $username   = "root";
    $password   = "";
    $dbname     = "sari";
} else {
    /*
    | Production (Azure / Render).
    |
    | The password comes from the environment and from nowhere else. It used to
    | have a literal fallback here, which meant the production database
    | password was sitting in a tracked file and went to GitHub with every
    | push -- and a .gitignore entry added later does not untrack a file that
    | is already committed.
    |
    | Host, user and database name keep their defaults: they are not secrets,
    | and a missing one is a misconfiguration worth surviving. A missing
    | password is not -- failing here, loudly, is better than falling back to
    | something a stranger can read.
    */
    $servername = getenv('DB_HOST') ?: 'sarismarts-db.mysql.database.azure.com';
    $username   = getenv('DB_USER') ?: 'sariAdmin';
    $password   = getenv('DB_PASS') ?: '';
    $dbname     = getenv('DB_NAME') ?: 'sari';

    if ($password === '') {
        http_response_code(500);
        error_log('DB_PASS is not set. Set it in the Render dashboard under Environment.');
        fwrite(STDERR, "DB_PASS is not set.\n");

        /* exit(1), not die(): die() leaves the status at 0, so a script runner
           counts the dead process as a success and the failure disappears. */
        exit(1);
    }
}

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

// Without this, peso signs, em dashes and check marks get stored as "?".
$conn->set_charset("utf8mb4");

/*
| The PayMongo key lives outside the web root alongside the other secrets,
| the same way SariSmarts/config.php loads it.
*/
$secretsFile = $isLocal
    ? 'C:/xampp/private_config/sarismart_secrets.php'
    : '/home/site/sarismart_secrets.php';

$secrets = is_readable($secretsFile) ? require $secretsFile : [];

if (!defined('PAYMONGO_SECRET_KEY')) {
    define(
        'PAYMONGO_SECRET_KEY',
        $secrets['PAYMONGO_SECRET_KEY'] ?? (getenv('PAYMONGO_SECRET_KEY') ?: '')
    );
}