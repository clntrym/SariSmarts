<?php
// =============================================
// ENVIRONMENT DETECTION
// Switch between local (XAMPP) and production
// =============================================
$isLocal = ($_SERVER['SERVER_NAME'] === 'localhost' || $_SERVER['SERVER_NAME'] === '127.0.0.1');

$BASE_URL = "/platform";

if ($isLocal) {
    // Local XAMPP
    $servername = "localhost";
    $username   = "root";
    $password   = "";
    $dbname     = "sari";
} else {
    // Production (Azure / Render)
    $servername = getenv('DB_HOST') ?: 'sarismarts-db.mysql.database.azure.com';
    $username   = getenv('DB_USER') ?: 'sariAdmin';
    $password   = getenv('DB_PASS') ?: '@Hawarli0203';
    $dbname     = getenv('DB_NAME') ?: 'sari';
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