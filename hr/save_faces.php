<?php

require_once("../init.php");
requireRole(['hr']);

header("Content-Type: application/json");

$data = json_decode(file_get_contents("php://input"), true);

if (!isset($data['descriptor'])) {

    echo json_encode([
        "success" => false,
        "message" => "No descriptor."
    ]);

    exit;
}

$descriptor = json_encode($data['descriptor']);

$user_id = $_SESSION['user_id'];

$get = mysqli_query($conn, "
SELECT employee_id
FROM users
WHERE user_id='$user_id'
");

$user = mysqli_fetch_assoc($get);

mysqli_query($conn, "
UPDATE employees
SET face_descriptor='" . mysqli_real_escape_string($conn, $descriptor) . "'
WHERE employee_id=" . $user['employee_id'] . "
");

echo json_encode([
    "success" => true,
    "message" => "Face Registered Successfully."
]);

?>