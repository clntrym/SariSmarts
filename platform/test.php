<?php

require_once __DIR__ . "/config.php";

if ($conn) {
    echo "Database Connected Successfully!";
} else {
    echo "Database Connection Failed!";
}

?>