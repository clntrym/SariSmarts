<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once("../init.php");
requireRole(['hr']);

header("Content-Type: application/json");

$user_id = $_SESSION['user_id'];

$get = mysqli_query($conn, "
SELECT eb.face_descriptor
FROM users u
JOIN employees e
    ON u.employee_id = e.employee_id
JOIN employee_biometrics eb
    ON e.employee_id = eb.employee_id
   AND eb.biometric_type = 'Face'
WHERE u.user_id = '$user_id'
LIMIT 1
");

if (mysqli_num_rows($get) == 0) {

    echo json_encode([
        "success" => false,
        "message" => "No registered face."
    ]);

    exit;
}

$row = mysqli_fetch_assoc($get);

if (empty($row['face_descriptor'])) {

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

?>