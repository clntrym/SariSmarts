<?php

require_once __DIR__ . "/../init.php";

/*
|--------------------------------------------------------------------------
| SIGN OUT
|--------------------------------------------------------------------------
|
| This used to unset only user_id, fullname, email and role, which left
| company_id, company_name, employee_id and branch_id behind in the
| session. The whole session is destroyed here instead, then a fresh one
| is started purely to carry the confirmation message to the login page.
|
*/

$_SESSION = [];

// Drop the session cookie too, otherwise the same session id lives on in
// the browser after the server-side data is gone.
if (ini_get('session.use_cookies')) {

    $params = session_get_cookie_params();

    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

session_destroy();

session_start();
session_regenerate_id(true);

$_SESSION['logout_success'] = "You have been logged out successfully.";

header("Location: /acc_log_in");
exit();
