<?php

require_once("../init.php");
requireRole(['hr']);

header("Content-Type: application/json");

/*
=========================================================
EMPLOYEE SELF-SERVICE ATTENDANCE (TIME IN / TIME OUT)
=========================================================
|
| Called from attendance.php's captureAttendance(). The
| logged-in user IS the employee, so employee_id comes
| from the session, not the form. GPS radius against the
| employee's branch is enforced here.
|
*/

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


/*
=========================================================
BASIC INPUT
=========================================================
*/

$type = $_POST['type'] ?? '';

if (!in_array($type, ['IN', 'OUT'], true)) {
    echo json_encode([
        "success" => false,
        "message" => "Invalid attendance type."
    ]);
    exit;
}

$latitude = $_POST['latitude'] ?? '';
$longitude = $_POST['longitude'] ?? '';

if ($latitude === '' || $longitude === '') {
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


/*
=========================================================
RESOLVE EMPLOYEE FROM SESSION
=========================================================
*/

$user_id = $_SESSION['user_id'];

$userStmt = mysqli_prepare($conn, "
    SELECT employee_id
    FROM users
    WHERE user_id = ?
    LIMIT 1
");

mysqli_stmt_bind_param($userStmt, "i", $user_id);
mysqli_stmt_execute($userStmt);
$userResult = mysqli_stmt_get_result($userStmt);
$user = mysqli_fetch_assoc($userResult);
mysqli_stmt_close($userStmt);

if (!$user || empty($user['employee_id'])) {
    echo json_encode([
        "success" => false,
        "message" => "Employee record not found."
    ]);
    exit;
}

$employee_id = (int) $user['employee_id'];


/*
=========================================================
IMAGE
=========================================================
*/

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

if ($data === false) {
    echo json_encode([
        "success" => false,
        "message" => "Unable to decode image."
    ]);
    exit;
}

$filename = $type . "_" . $employee_id . "_" . date("YmdHis") . ".jpg";
$filepath = "../uploads/attendance/" . $filename;

if (file_put_contents($filepath, $data) === false) {
    echo json_encode([
        "success" => false,
        "message" => "Unable to save the captured photo."
    ]);
    exit;
}

$photoPath = "uploads/attendance/" . $filename;


/*
=========================================================
GPS RADIUS CHECK (branch location)
=========================================================
*/

$branchStmt = mysqli_prepare($conn, "
    SELECT
        e.branch_id,
        b.latitude,
        b.longitude,
        b.allowed_radius
    FROM employees e
    JOIN branch b ON e.branch_id = b.branch_id
    WHERE e.employee_id = ?
    LIMIT 1
");

mysqli_stmt_bind_param($branchStmt, "i", $employee_id);
mysqli_stmt_execute($branchStmt);
$branchResult = mysqli_stmt_get_result($branchStmt);
$branch = mysqli_fetch_assoc($branchResult);
mysqli_stmt_close($branchStmt);

if ($branch) {

    $distance = distanceMeters(
        $latitude,
        $longitude,
        $branch['latitude'],
        $branch['longitude']
    );

    if ($distance > $branch['allowed_radius']) {

        @unlink($filepath);

        echo json_encode([
            "success" => false,
            "message" =>
                "Outside company premises.\n\n" .
                "Distance: " . round($distance, 2) . " meters\n" .
                "Allowed Radius: " . $branch['allowed_radius'] . " meters"
        ]);
        exit;
    }
}


/*
=========================================================
ATTENDANCE POLICY (late / overtime rules)
=========================================================
*/

$today = date("Y-m-d");

$companyId = requireCompany();

$policyStmt = mysqli_prepare($conn, "
    SELECT ap.*
    FROM attendance_policy ap
    JOIN employees e ON e.branch_id = ap.branch_id AND e.company_id = ap.company_id
    WHERE e.employee_id = ? AND e.company_id = ?
    LIMIT 1
");

mysqli_stmt_bind_param($policyStmt, "ii", $employee_id, $companyId);
mysqli_stmt_execute($policyStmt);
$policyResult = mysqli_stmt_get_result($policyStmt);
$policy = mysqli_fetch_assoc($policyResult);
mysqli_stmt_close($policyStmt);


/*
=========================================================
TIME IN
=========================================================
*/

if ($type === "IN") {

    $checkStmt = mysqli_prepare($conn, "
        SELECT attendance_id
        FROM attendance
        WHERE employee_id = ?
        AND attendance_date = ?
        LIMIT 1
    ");

    mysqli_stmt_bind_param($checkStmt, "is", $employee_id, $today);
    mysqli_stmt_execute($checkStmt);
    $checkResult = mysqli_stmt_get_result($checkStmt);

    if (mysqli_num_rows($checkResult) > 0) {
        mysqli_stmt_close($checkStmt);
        @unlink($filepath);
        echo json_encode([
            "success" => false,
            "message" => "Already timed in today."
        ]);
        exit;
    }

    mysqli_stmt_close($checkStmt);

    $lateMinutes = 0;
    $status = "Present";

    if ($policy) {

        $startTime = strtotime(date("Y-m-d") . " " . $policy['start_time']);
        $currentTime = time();

        if ($currentTime > $startTime) {

            $lateMinutes = floor(($currentTime - $startTime) / 60);

            if ($lateMinutes <= $policy['grace_minutes']) {
                $lateMinutes = 0;
            } else {
                $status = "Late";
            }
        }
    }

    $insertStmt = mysqli_prepare($conn, "
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
        VALUES (?, ?, ?, NOW(), ?, ?, ?, ?, ?)
    ");

    mysqli_stmt_bind_param(
        $insertStmt,
        "iissssis",
        $companyId,
        $employee_id,
        $today,
        $photoPath,
        $latitude,
        $longitude,
        $lateMinutes,
        $status
    );

    if (!mysqli_stmt_execute($insertStmt)) {
        mysqli_stmt_close($insertStmt);
        @unlink($filepath);
        echo json_encode([
            "success" => false,
            "message" => "Unable to save the attendance record."
        ]);
        exit;
    }

    mysqli_stmt_close($insertStmt);

    echo json_encode([
        "success" => true,
        "message" => "Time In Successful."
    ]);
    exit;
}


/*
=========================================================
TIME OUT
=========================================================
*/

if ($type === "OUT") {

    $getStmt = mysqli_prepare($conn, "
        SELECT *
        FROM attendance
        WHERE employee_id = ?
        AND attendance_date = ?
        LIMIT 1
    ");

    mysqli_stmt_bind_param($getStmt, "is", $employee_id, $today);
    mysqli_stmt_execute($getStmt);
    $getResult = mysqli_stmt_get_result($getStmt);
    $row = mysqli_fetch_assoc($getResult);
    mysqli_stmt_close($getStmt);

    if (!$row) {
        @unlink($filepath);
        echo json_encode([
            "success" => false,
            "message" => "Please Time In first."
        ]);
        exit;
    }

    if (!empty($row['time_out'])) {
        @unlink($filepath);
        echo json_encode([
            "success" => false,
            "message" => "Already Timed Out."
        ]);
        exit;
    }

    $timeIn = strtotime($row['time_in']);
    $timeOut = time();

    $workingHours = round(($timeOut - $timeIn) / 3600, 2);

    $undertime = 0;

    /*
     * Overtime is NOT auto-calculated from the Time Out
     * timestamp anymore. Clocking out late (including
     * forgetting to time out and doing it hours/a day
     * later) must not silently turn into paid overtime.
     * Employees now file an Overtime Request afterward
     * (overtime_request.php -> overtime_requests table),
     * which HR/Admin reviews and approves before it counts.
     */
    $overtimeHours = 0;

    $status = $row['status']; // keep Present or Late from Time In

    if ($policy) {

        $scheduledOut = strtotime(date("Y-m-d") . " " . $policy['end_time']);

        if ($timeOut < $scheduledOut) {
            $undertime = floor(($scheduledOut - $timeOut) / 60);
        }
    }

    if ($workingHours < 4) {
        $status = "Half Day";
    }

    $attendanceId = (int) $row['attendance_id'];

    $updateStmt = mysqli_prepare($conn, "
        UPDATE attendance
        SET
            time_out = NOW(),
            photo_out = ?,
            latitude_out = ?,
            longitude_out = ?,
            working_hours = ?,
            undertime_minutes = ?,
            overtime_hours = ?,
            status = ?
        WHERE attendance_id = ?
    ");

    mysqli_stmt_bind_param(
        $updateStmt,
        "sssdidsi",
        $photoPath,
        $latitude,
        $longitude,
        $workingHours,
        $undertime,
        $overtimeHours,
        $status,
        $attendanceId
    );

    if (!mysqli_stmt_execute($updateStmt)) {
        mysqli_stmt_close($updateStmt);
        @unlink($filepath);
        echo json_encode([
            "success" => false,
            "message" => "Unable to update the attendance record."
        ]);
        exit;
    }

    mysqli_stmt_close($updateStmt);

    echo json_encode([
        "success" => true,
        "message" => "Time Out Successful."
    ]);
    exit;
}