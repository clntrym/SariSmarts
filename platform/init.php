<?php
// init.php

// Start session
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Include database config
require_once __DIR__ . '/config.php';

/*
| The rule for who may use the system, shared with the tenant app rather than
| copied into it.
|
| Copying is what caused the bug this closes: SariSmart's User Management
| deactivated a user by writing 'disabled', the platform writes 'inactive',
| and the login page refused only 'inactive'. Three files, two spellings, one
| idea -- and deactivating a tenant user did nothing at all. The rule now
| lives in one function that both apps call.
*/
$accountAccessFile = is_file(__DIR__ . '/../includes/account_access.php')
    ? __DIR__ . '/../includes/account_access.php'
    : __DIR__ . '/../SariSmarts/includes/account_access.php';
require_once $accountAccessFile;

// Set default timezone
date_default_timezone_set('Asia/Manila');

// Optional helper functions
if (!function_exists('isLoggedIn')) {
    function isLoggedIn()
    {
        return isset($_SESSION['user_id']);
    }
}

if (!function_exists('redirectIfLoggedIn')) {
    function redirectIfLoggedIn($roleDashboard = '')
    {
        if (isLoggedIn()) {
            if (!empty($roleDashboard)) {
                header("Location: $roleDashboard");
            } else {
                header("Location: index.php");
            }
            exit();
        }
    }
}

if (!function_exists('flash')) {
    function flash($name = '', $message = '', $class = 'alert alert-info')
    {
        if (!empty($name)) {
            if (!empty($message)) {
                $_SESSION[$name] = $message;
                $_SESSION[$name . '_class'] = $class;
            } elseif (!empty($_SESSION[$name])) {
                echo '<div class="' . $_SESSION[$name . '_class'] . '">' . $_SESSION[$name] . '</div>';
                unset($_SESSION[$name]);
                unset($_SESSION[$name . '_class']);
            }
        }
    }
}

if (!function_exists('redirectTo')) {
    function redirectTo($url)
    {
        header("Location: $url");
        exit();
    }
}

if (!function_exists('requireRole')) {
    function requireRole($allowedRoles = [])
    {

        if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
            $_SESSION['session_expired'] = "Please log in to access this page.";
            header("Location: /SariSmarts/accounts/acc_log_in.php");
            exit();
        }

        /*
        | A deactivated operator stops here, on their very next click.
        |
        | Platform staff sign in through the tenant login page, so the login
        | door already refuses them -- but only at sign-in. Without this, an
        | operator deactivated while they are working keeps every page open
        | until they choose to sign out, which is not what Deactivate means.
        |
        | One lookup on the primary key, on pages that already query.
        */
        global $conn;

        if ($conn instanceof mysqli
            && !userAccountAllowsAccess($conn, (int) $_SESSION['user_id'])) {

            $_SESSION = [];
            session_destroy();
            session_start();
            $_SESSION['login_error'] = accountAccessMessage(null);

            header("Location: /SariSmarts/accounts/acc_log_in.php");
            exit();
        }

        $userRole = strtolower($_SESSION['role']);
        $allowedRoles = array_map('strtolower', (array) $allowedRoles);

        if (!in_array($userRole, $allowedRoles)) {

            switch ($userRole) {

                case "admin":
                    header("Location: /SariSmarts/admin/dashboard.php");
                    break;

                case "hr":
                    header("Location: /SariSmarts/hr/dashboard.php");
                    break;

                case "finance":
                    header("Location: /SariSmarts/finance/dashboard.php");
                    exit();

                case "inventory":
                    header("Location: /SariSmarts/inventory/dashboard.php");
                    exit();

                case "cashier":
                    header("Location: /SariSmarts/cashier/pointofsales.php");
                    exit();

                case "super admin":
                    header("Location: /platform/superAdmin/dashboard.php");
                    exit();

                default:
                    header("Location: /SariSmarts/accounts/acc_log_in.php");
                    break;
            }

            exit();
        }
    }
}
?>