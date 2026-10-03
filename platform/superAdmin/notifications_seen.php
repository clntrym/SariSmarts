<?php

require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../includes/platform_roles.php';

/*
|--------------------------------------------------------------------------
| MARK THE BELL AS READ
|--------------------------------------------------------------------------
|
| Its own endpoint rather than a handler on each page. The bell lives in the
| shared layout, which every module includes after its own request handling,
| so a POST to any one of them would be read by that module's handlers
| first. One file means one place, and no module has to know the bell
| exists.
|
| Any signed-in platform role may do this: it records that one person has
| looked, which is not an access decision about anything.
|
*/

requireRole(array_keys(platformRoles()));

$back = platformLanding();

/* Only ever come back to a page inside this panel. */
if (!empty($_POST['back'])) {
    $candidate = (string) $_POST['back'];

    if (preg_match('#^/platform/superAdmin/[A-Za-z_/]+\.php$#', $candidate)) {
        $back = $candidate;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $userId = (int) ($_SESSION['user_id'] ?? 0);

    $stmt = $conn->prepare("UPDATE users SET notifications_seen_at = NOW() WHERE user_id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $stmt->close();
}

header('Location: ' . $back);
exit();
