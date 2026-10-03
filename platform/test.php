<?php

require_once("config.php");

if ($conn) {
    echo "Database Connected Successfully!";
} else {
    echo "Database Connection Failed!";
}

?>