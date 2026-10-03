<?php

// =============================================
// ENVIRONMENT DETECTION
// Switch between local (XAMPP) and production
// =============================================
$isLocal = ($_SERVER['SERVER_NAME'] === 'localhost' || $_SERVER['SERVER_NAME'] === '127.0.0.1');

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
=========================================================
PAYMONGO CONFIGURATION
=========================================================
*/
/*
| The key is loaded from outside the web root so it never ends up in the
| project folder, a stray backup file, or a zip handed to a customer.
| See C:\xampp\private_config\sarismart_secrets.php.
*/

// Local XAMPP: reads from outside the web root.
// InfinityFree: reads from /home/vol{X}_Y/yourdomain/sarismart_secrets.php
//   → place the file ONE LEVEL above htdocs on the server (outside public_html).
$secretsFile = $isLocal
    ? 'C:/xampp/private_config/sarismart_secrets.php'
    : '/home/site/sarismart_secrets.php';

$secrets = is_readable($secretsFile) ? require $secretsFile : [];

define(
    'PAYMONGO_SECRET_KEY',
    $secrets['PAYMONGO_SECRET_KEY'] ?? (getenv('PAYMONGO_SECRET_KEY') ?: '')
);

?>