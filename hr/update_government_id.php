<?php

/*
| This endpoint had no authentication at all: session_start() and a database
| connection, nothing else. Anyone who could reach the URL -- signed in or
| not, from any company -- could overwrite and "verify" any employee's
| government IDs by posting an employee_id. Nothing in the app calls it (HR
| saves government IDs from employee_view.php), but it answers anyone who
| does.
|
| It now requires an HR session, and every query is confined to that HR
| officer's company.
*/
require_once("../init.php");

if (empty($_SESSION['user_id']) || strtolower((string) ($_SESSION['role'] ?? '')) !== 'hr') {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Not authorised.']);
    exit;
}

$companyId = currentCompanyId();

if ($companyId === null) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Not authorised.']);
    exit;
}

/* =========================================================
   UPDATE / VERIFY GOVERNMENT ID
========================================================= */

header('Content-Type: application/json; charset=utf-8');


/*
|--------------------------------------------------------------------------
| Prevent PHP warnings/notices from corrupting JSON
|--------------------------------------------------------------------------
*/
mysqli_report(MYSQLI_REPORT_OFF);


/*
|--------------------------------------------------------------------------
| ONLY POST REQUEST
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    echo json_encode([
        'success' => false,
        'message' => 'Invalid request method.'
    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| GET DATA
|--------------------------------------------------------------------------
*/

$employeeId = (int) ($_POST['employee_id'] ?? 0);

$idType = trim($_POST['id_type'] ?? '');

$idNumber = trim($_POST['id_number'] ?? '');


/*
|--------------------------------------------------------------------------
| ALLOWED GOVERNMENT IDs
|--------------------------------------------------------------------------
*/

$allowedTypes = [
    'SSS',
    'PhilHealth',
    'Pag-IBIG',
    'TIN',
    'National ID',
    'Passport',
    'Driver License'
];


/*
|--------------------------------------------------------------------------
| VALIDATE EMPLOYEE
|--------------------------------------------------------------------------
*/

if ($employeeId <= 0) {

    echo json_encode([
        'success' => false,
        'message' => 'Invalid employee ID.'
    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| VALIDATE ID TYPE
|--------------------------------------------------------------------------
*/

if (!in_array($idType, $allowedTypes, true)) {

    echo json_encode([
        'success' => false,
        'message' => 'Invalid government ID type.'
    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| VALIDATE ID NUMBER
|--------------------------------------------------------------------------
*/

if ($idNumber === '') {

    echo json_encode([
        'success' => false,
        'message' => 'Please enter the government ID number.'
    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| CHECK EMPLOYEE EXISTS
|--------------------------------------------------------------------------
*/

$employeeCheck = mysqli_prepare(
    $conn,
    "
    SELECT employee_id
    FROM employees
    WHERE employee_id = ? AND company_id = ?
    LIMIT 1
    "
);

if (!$employeeCheck) {

    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . mysqli_error($conn)
    ]);

    exit;
}

mysqli_stmt_bind_param(
    $employeeCheck,
    "ii",
    $employeeId,
    $companyId
);

mysqli_stmt_execute($employeeCheck);

$employeeResult = mysqli_stmt_get_result($employeeCheck);

if (!$employeeResult || mysqli_num_rows($employeeResult) === 0) {

    mysqli_stmt_close($employeeCheck);

    echo json_encode([
        'success' => false,
        'message' => 'Employee not found.'
    ]);

    exit;
}

mysqli_stmt_close($employeeCheck);


/*
|--------------------------------------------------------------------------
| GET CURRENT LOGGED-IN USER
|--------------------------------------------------------------------------
|
| Your project stores the logged-in user's ID in:
| $_SESSION['user_id']
|
*/

$verifiedBy = $_SESSION['user_id'] ?? null;


/*
|--------------------------------------------------------------------------
| CHECK EXISTING GOVERNMENT ID
|--------------------------------------------------------------------------
*/

$check = mysqli_prepare(
    $conn,
    "
    SELECT government_id
    FROM employee_government_ids
    WHERE employee_id = ?
      AND company_id = ?
      AND id_type = ?
    LIMIT 1
    "
);

if (!$check) {

    echo json_encode([
        'success' => false,
        'message' => 'Database error while checking government ID: '
            . mysqli_error($conn)
    ]);

    exit;
}

mysqli_stmt_bind_param(
    $check,
    "iis",
    $employeeId,
    $companyId,
    $idType
);

if (!mysqli_stmt_execute($check)) {

    $error = mysqli_stmt_error($check);

    mysqli_stmt_close($check);

    echo json_encode([
        'success' => false,
        'message' => 'Failed to check government ID: ' . $error
    ]);

    exit;
}

$result = mysqli_stmt_get_result($check);

$existing = $result
    ? mysqli_fetch_assoc($result)
    : null;

mysqli_stmt_close($check);


/*
|--------------------------------------------------------------------------
| UPDATE EXISTING RECORD
|--------------------------------------------------------------------------
*/

if ($existing) {

    $governmentId = (int) $existing['government_id'];

    /*
     * If there is a logged-in HR user, record who verified it.
     *
     * Your employee_government_ids.verified_by is INT.
     */
    if ($verifiedBy !== null && is_numeric($verifiedBy)) {

        $verifiedByInt = (int) $verifiedBy;

        $stmt = mysqli_prepare(
            $conn,
            "
            UPDATE employee_government_ids
            SET
                id_number = ?,
                status = 'Verified',
                verified_by = ?,
                verified_at = NOW(),
                remarks = NULL
            WHERE government_id = ? AND company_id = ?
            "
        );

        if (!$stmt) {

            echo json_encode([
                'success' => false,
                'message' => 'Database error: ' . mysqli_error($conn)
            ]);

            exit;
        }

        mysqli_stmt_bind_param(
            $stmt,
            "siii",
            $idNumber,
            $verifiedByInt,
            $governmentId,
            $companyId
        );

    } else {

        /*
         * If the session user ID cannot be stored,
         * still verify the ID.
         */
        $stmt = mysqli_prepare(
            $conn,
            "
            UPDATE employee_government_ids
            SET
                id_number = ?,
                status = 'Verified',
                verified_by = NULL,
                verified_at = NOW(),
                remarks = NULL
            WHERE government_id = ? AND company_id = ?
            "
        );

        if (!$stmt) {

            echo json_encode([
                'success' => false,
                'message' => 'Database error: ' . mysqli_error($conn)
            ]);

            exit;
        }

        mysqli_stmt_bind_param(
            $stmt,
            "sii",
            $idNumber,
            $governmentId,
            $companyId
        );
    }


    if (!mysqli_stmt_execute($stmt)) {

        $error = mysqli_stmt_error($stmt);

        mysqli_stmt_close($stmt);

        echo json_encode([
            'success' => false,
            'message' => 'Failed to update government ID: ' . $error
        ]);

        exit;
    }

    mysqli_stmt_close($stmt);


    /*
    |--------------------------------------------------------------------------
    | INSERT NEW RECORD
    |--------------------------------------------------------------------------
    */

} else {

    if ($verifiedBy !== null && is_numeric($verifiedBy)) {

        $verifiedByInt = (int) $verifiedBy;

        $stmt = mysqli_prepare(
            $conn,
            "
            INSERT INTO employee_government_ids
            (
                company_id,
                employee_id,
                id_type,
                id_number,
                status,
                verified_by,
                verified_at
            )
            VALUES
            (
                ?,
                ?,
                ?,
                ?,
                'Verified',
                ?,
                NOW()
            )
            "
        );

        if (!$stmt) {

            echo json_encode([
                'success' => false,
                'message' => 'Database error: ' . mysqli_error($conn)
            ]);

            exit;
        }

        mysqli_stmt_bind_param(
            $stmt,
            "iissi",
            $companyId,
            $employeeId,
            $idType,
            $idNumber,
            $verifiedByInt
        );

    } else {

        $stmt = mysqli_prepare(
            $conn,
            "
            INSERT INTO employee_government_ids
            (
                company_id,
                employee_id,
                id_type,
                id_number,
                status,
                verified_by,
                verified_at
            )
            VALUES
            (
                ?,
                ?,
                ?,
                ?,
                'Verified',
                NULL,
                NOW()
            )
            "
        );

        if (!$stmt) {

            echo json_encode([
                'success' => false,
                'message' => 'Database error: ' . mysqli_error($conn)
            ]);

            exit;
        }

        mysqli_stmt_bind_param(
            $stmt,
            "iiss",
            $companyId,
            $employeeId,
            $idType,
            $idNumber
        );
    }


    if (!mysqli_stmt_execute($stmt)) {

        $error = mysqli_stmt_error($stmt);

        mysqli_stmt_close($stmt);

        echo json_encode([
            'success' => false,
            'message' => 'Failed to save government ID: ' . $error
        ]);

        exit;
    }

    mysqli_stmt_close($stmt);
}


/*
|--------------------------------------------------------------------------
| SUCCESS RESPONSE
|--------------------------------------------------------------------------
*/

echo json_encode([
    'success' => true,
    'message' => $idType . ' has been verified successfully.',
    'id_type' => $idType,
    'id_number' => $idNumber,
    'status' => 'Verified'
]);

exit;