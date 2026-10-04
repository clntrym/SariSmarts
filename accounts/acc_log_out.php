<?php
require_once("../init.php");

// Save message first
$_SESSION['logout_success'] = "You have been logged out successfully.";

// Remove login data only
unset($_SESSION['user_id']);
unset($_SESSION['fullname']);
unset($_SESSION['email']);
unset($_SESSION['role']);

// Redirect to login
header("Location: /acc_log_in");
exit();