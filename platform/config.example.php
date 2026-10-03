<?php
$BASE_URL = "/platform";

$servername = "localhost";
$username = "your_db_username";
$password = "your_db_password";
$dbname = "your_db_name";

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
$secretsFile = 'C:/xampp/private_config/sarismart_secrets.php';

$secrets = is_readable($secretsFile) ? require $secretsFile : [];

if (!defined('PAYMONGO_SECRET_KEY')) {
    define(
        'PAYMONGO_SECRET_KEY',
        $secrets['PAYMONGO_SECRET_KEY'] ?? (getenv('PAYMONGO_SECRET_KEY') ?: '')
    );
}
?>