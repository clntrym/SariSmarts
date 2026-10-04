<?php
/*
|--------------------------------------------------------------------------
| THE SIGNED-IN USER'S REGISTERED FACE
|--------------------------------------------------------------------------
|
| Returns the face descriptor belonging to whoever is signed in, so the
| attendance page can compare it against the camera before recording a time
| in or time out.
|
| It returns only the asker's own descriptor. The user id comes from the
| session and from nowhere else, so no request can ask for somebody else's
| face by changing what it sends.
|
| This is the HR copy of a file the other roles already had. It differs from
| inventory/verify_face.php in two ways on purpose:
|
|   - no display_errors. That file turns it on, which in production prints
|     table and column names into the response.
|   - a prepared statement rather than the session value interpolated into
|     the SQL. The value is an integer from the session and was never
|     reachable by an attacker, but a query built by concatenation is a
|     pattern worth not copying forward.
*/

require_once("../init.php");
requireRole(['hr']);

header("Content-Type: application/json; charset=utf-8");

$stmt = $conn->prepare("
    SELECT eb.face_descriptor
    FROM users u
    JOIN employees e
        ON u.employee_id = e.employee_id
    JOIN employee_biometrics eb
        ON e.employee_id = eb.employee_id
       AND eb.biometric_type = 'Face'
    WHERE u.user_id = ?
    LIMIT 1
");

$userId = (int) $_SESSION['user_id'];
$stmt->bind_param("i", $userId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row || empty($row['face_descriptor'])) {
    echo json_encode([
        "success" => false,
        "message" => "No registered face."
    ]);
    exit;
}

echo json_encode([
    "success" => true,
    "face_descriptor" => $row['face_descriptor']
]);
