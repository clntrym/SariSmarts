<?php

require_once("../init.php");
requireRole(['employee']);

$companyId = requireCompany();

header("Content-Type: application/json");

$type = $_POST['type'];

$latitude = $_POST['latitude'] ?? '';
$longitude = $_POST['longitude'] ?? '';

if ($latitude == "" || $longitude == "") {

    echo json_encode([
        "success" => false,
        "message" => "GPS location not found."
    ]);

    exit;
}

if (!isset($_SESSION['user_id'])) {
    echo json_encode([
        "success" => false,
        "message" => "Not logged in."
    ]);
    exit;
}

// Get employee id from users table
$user_id = $_SESSION['user_id'];

$get = mysqli_query($conn, "
SELECT employee_id
FROM users
WHERE user_id='$user_id'
LIMIT 1
");


$user = mysqli_fetch_assoc($get);

if (!$user || empty($user['employee_id'])) {

    echo json_encode([
        "success" => false,
        "message" => "Employee record not found."
    ]);

    exit;
}

$employee_id = $user['employee_id'];

$image = $_POST['image'] ?? '';

if (empty($image)) {

    echo json_encode([
        "success" => false,
        "message" => "No image received."
    ]);

    exit;
}

$image = str_replace("data:image/jpeg;base64,", "", $image);
$image = str_replace(" ", "+", $image);

$data = base64_decode($image);

$prefix = ($type == "IN") ? "IN" : "OUT";

$filename = $prefix . "_" . $employee_id . "_" . date("YmdHis") . ".jpg";

$filepath = "../uploads/attendance/" . $filename;

file_put_contents($filepath, $data);

$getBranch = mysqli_query($conn, "
SELECT
    e.branch_id,
    b.latitude,
    b.longitude,
    b.allowed_radius
FROM employees e
JOIN branch b
ON e.branch_id=b.branch_id AND b.company_id=e.company_id
WHERE e.employee_id='$employee_id'
AND e.company_id=" . (int) $companyId . "
");

$branch = mysqli_fetch_assoc($getBranch);


$distance = distanceMeters(

    $latitude,
    $longitude,

    $branch['latitude'],
    $branch['longitude']

);

if ($distance > $branch['allowed_radius']) {

    echo json_encode([
        "success" => false,
        "message" =>
            "Outside company premises.\n\n" .
            "Distance: " . round($distance, 2) . " meters\n" .
            "Allowed Radius: " . $branch['allowed_radius'] . " meters"
    ]);

    exit;
}

function distanceMeters($lat1, $lon1, $lat2, $lon2)
{

    $earth = 6371000;

    $dLat = deg2rad($lat2 - $lat1);

    $dLon = deg2rad($lon2 - $lon1);

    $a =

        sin($dLat / 2) * sin($dLat / 2)

        +

        cos(deg2rad($lat1))

        *

        cos(deg2rad($lat2))

        *

        sin($dLon / 2)

        *

        sin($dLon / 2);

    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

    return $earth * $c;

}



// Prevent duplicate time in
$today = date("Y-m-d");

$getPolicy = mysqli_query($conn, "
SELECT
    ap.*
FROM attendance_policy ap
JOIN employees e
ON e.branch_id=ap.branch_id AND e.company_id=ap.company_id
WHERE e.employee_id='$employee_id'
AND e.company_id=" . (int) $companyId . "
LIMIT 1
");

$policy = mysqli_fetch_assoc($getPolicy);

$startTime = strtotime(date("Y-m-d") . " " . $policy['start_time']);
$currentTime = time();

$lateMinutes = 0;
$status = "Present";

if ($currentTime > $startTime) {

    $lateMinutes = floor(($currentTime - $startTime) / 60);

    if ($lateMinutes <= $policy['grace_minutes']) {

        $lateMinutes = 0;

    } else {

        $status = "Late";

    }

}

if ($type == "IN") {

    $check = mysqli_query($conn, "
    SELECT attendance_id
    FROM attendance
    WHERE employee_id='$employee_id'
    AND company_id=" . (int) $companyId . "
    AND attendance_date='$today'
    ");

    if (mysqli_num_rows($check) > 0) {

        echo json_encode([
            "success" => false,
            "message" => "Already timed in today."
        ]);

        exit;

    }

    mysqli_query($conn, "
    INSERT INTO attendance
    (
    company_id,
    employee_id,
    attendance_date,
    time_in,
    photo_in,
    latitude_in,
    longitude_in,
    late_minutes,
    status
    )

    VALUES(
    " . (int) $companyId . ",
    '$employee_id',
    '$today',
    NOW(),
    'uploads/attendance/$filename',
    '$latitude',
    '$longitude',
    '$lateMinutes',
    '$status'
    )
    ");

    echo json_encode([
        "success" => true,
        "message" => "Time In Successful."
    ]);

    exit;

}

if ($type == "OUT") {

    $get = mysqli_query($conn, "
    SELECT *
    FROM attendance
    WHERE employee_id='$employee_id'
    AND company_id=" . (int) $companyId . "
    AND attendance_date='$today'
    LIMIT 1
    ");

    if (mysqli_num_rows($get) == 0) {

        echo json_encode([
            "success" => false,
            "message" => "Please Time In first."
        ]);

        exit;

    }

    $row = mysqli_fetch_assoc($get);

    if ($row['time_out'] != NULL) {

        echo json_encode([
            "success" => false,
            "message" => "Already Timed Out."
        ]);

        exit;

    }

    $getPolicy = mysqli_query($conn, "
    SELECT
        ap.*
    FROM attendance_policy ap
    JOIN employees e
    ON e.branch_id = ap.branch_id AND e.company_id = ap.company_id
    WHERE e.employee_id='$employee_id'
    AND e.company_id=" . (int) $companyId . "
    LIMIT 1
    ");

    $policy = mysqli_fetch_assoc($getPolicy);

    $timeIn = strtotime($row['time_in']);
    $timeOut = time();

    $workingHours = round(($timeOut - $timeIn) / 3600, 2);

    $scheduledOut = strtotime(date("Y-m-d") . " " . $policy['end_time']);
    $undertime = 0;

    if ($timeOut < $scheduledOut) {

        $undertime = floor(($scheduledOut - $timeOut) / 60);

    }

    $overtimeHours = 0;
    $status = $row['status']; // keep Present or Late from Time In

    // Early Out
    if ($workingHours < 4) {

        $status = "Half Day";

    }

    // Overtime
    $overtimeStart = $scheduledOut + ($policy['overtime_after'] * 60);

    if ($timeOut >= $overtimeStart) {

        $overtimeHours = round(($timeOut - $scheduledOut) / 3600, 2);

    }

    mysqli_query($conn, "
    UPDATE attendance
    SET
        time_out = NOW(),
        photo_out = 'uploads/attendance/$filename',
        latitude_out = '$latitude',
        longitude_out = '$longitude',
        working_hours = '$workingHours',
        undertime_minutes = '$undertime',
        overtime_hours = '$overtimeHours',
        status = '$status'
    WHERE attendance_id = " . (int) $row['attendance_id'] . "
    AND company_id = " . (int) $companyId);

    echo json_encode([

        "success" => true,

        "message" => "Time Out Successful."

    ]);

    exit;

}


?>