<?php

require_once('../init.php');
requireRole(['hr', 'admin']);

/*
| The owner reaches this too.
|
| The module belongs to hr; the business belongs to the owner, so they see
| everything. They already had the page that links HERE -- an admin who
| clicked the button on it was bounced to the dashboard with no message,
| which is worse than the button not existing: it was rendered for them and
| then refused for being them.
|
| The header and footer are chosen by who is reading rather than named
| outright, so the owner keeps their own menu instead of finding it
| replaced by this role's with no way back.
*/
require_once __DIR__ . '/../includes/role_chrome.php';

$companyId = requireCompany();

/*
| And whether the plan has this department at all.
|
| requireRole() above admits an admin, and role says nothing about the
| plan: Retail Starter sells Owner/Admin, Cashier and Inventory Staff, so
| an owner on it has no HR people and no HRMS to manage. Hiding the
| sidebar entry is presentation; this is what holds when the address is
| typed.
*/
requirePlanRole($conn, $companyId, 'hr', 'HRMS');
/*
| HR is centralised -- one officer serves every branch rather than belonging
| to one. This page used to read the officer's own branch from the session and
| refuse to open without it, which locked out exactly the accounts that are
| supposed to be central.
|
| Every check below keeps its company_id condition; that is the tenant
| boundary and it stays. Only the branch narrowing is gone.
*/

/*
|--------------------------------------------------------------------------
| UPLOAD EMPLOYEE DOCUMENT
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['upload_employee_document'])
) {

    header('Content-Type: application/json');

    $employee_id = (int) ($_POST['employee_id'] ?? 0);
    $document_type = trim($_POST['document_type'] ?? '');
    $document_name = trim($_POST['document_name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $edit_reason = trim($_POST['edit_reason'] ?? '');

    /*
    |--------------------------------------------------------------------------
    | BASIC VALIDATION
    |--------------------------------------------------------------------------
    */

    if ($employee_id <= 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid employee.'
        ]);
        exit;
    }

    if ($document_type === '') {
        echo json_encode([
            'success' => false,
            'message' => 'Please select a document type.'
        ]);
        exit;
    }

    if ($document_type === 'Other' && $document_name === '') {
        echo json_encode([
            'success' => false,
            'message' => 'Please enter the document name.'
        ]);
        exit;
    }

    if ($edit_reason === '' || strlen($edit_reason) < 5) {
        echo json_encode([
            'success' => false,
            'message' => 'Please provide a valid reason for uploading the document.'
        ]);
        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | CHECK FILE
    |--------------------------------------------------------------------------
    */

    if (
        !isset($_FILES['document_file'])
        || $_FILES['document_file']['error'] !== UPLOAD_ERR_OK
    ) {

        echo json_encode([
            'success' => false,
            'message' => 'Please select a valid document file.'
        ]);
        exit;
    }

    $file = $_FILES['document_file'];

    /*
    |--------------------------------------------------------------------------
    | FILE SIZE
    |--------------------------------------------------------------------------
    */

    $maxFileSize = 5 * 1024 * 1024; // 5 MB

    if ($file['size'] > $maxFileSize) {

        echo json_encode([
            'success' => false,
            'message' => 'File size must not exceed 5 MB.'
        ]);
        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | FILE TYPE
    |--------------------------------------------------------------------------
    */

    $allowedExtensions = [
        'pdf',
        'jpg',
        'jpeg',
        'png'
    ];

    $extension = strtolower(
        pathinfo($file['name'], PATHINFO_EXTENSION)
    );

    if (!in_array($extension, $allowedExtensions, true)) {

        echo json_encode([
            'success' => false,
            'message' => 'Invalid file format. Only PDF, JPG, JPEG, and PNG are allowed.'
        ]);
        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | VERIFY EMPLOYEE
    |--------------------------------------------------------------------------
    */

    $employeeCheck = $conn->prepare("
        SELECT employee_id
        FROM employees
        WHERE employee_id = ? AND company_id = ?
        LIMIT 1
    ");

    $employeeCheck->bind_param("ii", $employee_id, $companyId);

    $employeeCheck->execute();

    $employeeResult = $employeeCheck->get_result();

    if ($employeeResult->num_rows === 0) {

        $employeeCheck->close();

        echo json_encode([
            'success' => false,
            'message' => 'Employee not found or you do not have permission to upload documents for this employee.'
        ]);
        exit;
    }

    $employeeCheck->close();

    /*
    |--------------------------------------------------------------------------
    | DOCUMENT NAME
    |--------------------------------------------------------------------------
    */

    if ($document_type !== 'Other') {
        $document_name = $document_type;
    }

    /*
    |--------------------------------------------------------------------------
    | CREATE UPLOAD DIRECTORY
    |--------------------------------------------------------------------------
    */

    $uploadDirectory = "../uploads/employee_documents/";

    if (!is_dir($uploadDirectory)) {

        if (!mkdir($uploadDirectory, 0755, true)) {

            echo json_encode([
                'success' => false,
                'message' => 'Unable to create document upload directory.'
            ]);
            exit;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | CREATE UNIQUE FILE NAME
    |--------------------------------------------------------------------------
    */

    $safeFileName =
        'EMP_' .
        $employee_id .
        '_' .
        time() .
        '_' .
        bin2hex(random_bytes(5)) .
        '.' .
        $extension;

    $targetPath =
        $uploadDirectory .
        $safeFileName;

    /*
    |--------------------------------------------------------------------------
    | MOVE FILE
    |--------------------------------------------------------------------------
    */

    if (
        !move_uploaded_file(
            $file['tmp_name'],
            $targetPath
        )
    ) {

        echo json_encode([
            'success' => false,
            'message' => 'Failed to upload the document.'
        ]);
        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | DATABASE PATH
    |--------------------------------------------------------------------------
    */

    $databasePath =
        "uploads/employee_documents/" .
        $safeFileName;

    /*
    |--------------------------------------------------------------------------
    | INSERT DOCUMENT
    |--------------------------------------------------------------------------
    */

    $documentStmt = $conn->prepare("
        INSERT INTO employee_documents
        (
            company_id,
            employee_id,
            document_name,
            file_path,
            uploaded_at,
            created_at
        )
        VALUES
        (
            ?,
            ?,
            ?,
            ?,
            NOW(),
            NOW()
        )
    ");

    if (!$documentStmt) {

        unlink($targetPath);

        echo json_encode([
            'success' => false,
            'message' => 'Unable to prepare document record.'
        ]);
        exit;
    }

    $documentStmt->bind_param(
        "iiss",
        $companyId,
        $employee_id,
        $document_name,
        $databasePath
    );

    if (!$documentStmt->execute()) {

        $documentStmt->close();

        unlink($targetPath);

        echo json_encode([
            'success' => false,
            'message' => 'Unable to save the document record.'
        ]);
        exit;
    }

    $documentStmt->close();

    /*
    |--------------------------------------------------------------------------
    | SUCCESS
    |--------------------------------------------------------------------------
    */

    echo json_encode([
        'success' => true,
        'message' => 'Employee document uploaded successfully.'
    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| UPDATE PERSONAL INFORMATION
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['update_personal_information'])
) {

    header('Content-Type: application/json');

    $employee_id = (int) ($_POST['employee_id'] ?? 0);

    $first_name = trim($_POST['first_name'] ?? '');
    $middle_name = trim($_POST['middle_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $suffix = trim($_POST['suffix'] ?? '');
    $date_of_birth = trim($_POST['date_of_birth'] ?? '');
    $gender = trim($_POST['gender'] ?? '');
    $civil_status = trim($_POST['civil_status'] ?? '');
    $edit_reason = trim($_POST['edit_reason'] ?? '');

    /*
    |--------------------------------------------------------------------------
    | VALIDATION
    |--------------------------------------------------------------------------
    */

    if ($employee_id <= 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid employee.'
        ]);
        exit;
    }

    if ($first_name === '') {
        echo json_encode([
            'success' => false,
            'message' => 'First name is required.'
        ]);
        exit;
    }

    if ($last_name === '') {
        echo json_encode([
            'success' => false,
            'message' => 'Last name is required.'
        ]);
        exit;
    }

    if ($edit_reason === '' || strlen($edit_reason) < 5) {
        echo json_encode([
            'success' => false,
            'message' => 'Please provide a valid reason for this edit.'
        ]);
        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | VERIFY EMPLOYEE BELONGS TO THIS HR BRANCH
    |--------------------------------------------------------------------------
    */

    $employeeCheck = $conn->prepare("
        SELECT employee_id
        FROM employees
        WHERE employee_id = ? AND company_id = ?
        LIMIT 1
    ");

    $employeeCheck->bind_param("ii", $employee_id, $companyId);
    $employeeCheck->execute();
    $employeeResult = $employeeCheck->get_result();

    if ($employeeResult->num_rows === 0) {

        $employeeCheck->close();

        echo json_encode([
            'success' => false,
            'message' => 'Employee not found or you do not have permission to edit this employee.'
        ]);
        exit;
    }

    $employeeCheck->close();

    /*
    |--------------------------------------------------------------------------
    | NULLABLE FIELDS
    |--------------------------------------------------------------------------
    */

    $middle_name = $middle_name !== '' ? $middle_name : null;
    $suffix = $suffix !== '' ? $suffix : null;
    $date_of_birth = $date_of_birth !== '' ? $date_of_birth : null;
    $gender = $gender !== '' ? $gender : null;
    $civil_status = $civil_status !== '' ? $civil_status : null;

    /*
    |--------------------------------------------------------------------------
    | UPDATE
    |--------------------------------------------------------------------------
    */

    $updateStmt = $conn->prepare("
        UPDATE employees
        SET
            first_name = ?,
            middle_name = ?,
            last_name = ?,
            suffix = ?,
            date_of_birth = ?,
            gender = ?,
            civil_status = ?
        WHERE employee_id = ?
    ");

    if (!$updateStmt) {
        echo json_encode([
            'success' => false,
            'message' => 'Unable to prepare the update.'
        ]);
        exit;
    }

    $updateStmt->bind_param(
        "sssssssi",
        $first_name,
        $middle_name,
        $last_name,
        $suffix,
        $date_of_birth,
        $gender,
        $civil_status,
        $employee_id
    );

    if (!$updateStmt->execute()) {

        $updateStmt->close();

        echo json_encode([
            'success' => false,
            'message' => 'Unable to save personal information.'
        ]);
        exit;
    }

    $updateStmt->close();

    echo json_encode([
        'success' => true,
        'message' => 'Personal information has been updated.'
    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| UPDATE GOVERNMENT IDS
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['update_government_ids'])
) {

    header('Content-Type: application/json');

    $employee_id = (int) ($_POST['employee_id'] ?? 0);
    $edit_reason = trim($_POST['edit_reason'] ?? '');
    $government = $_POST['government'] ?? [];

    $allowedTypes = [
        'SSS',
        'PhilHealth',
        'Pag-IBIG',
        'TIN',
        'National ID',
        'Passport',
        'Driver License'
    ];

    $allowedStatuses = [
        'Missing',
        'Submitted',
        'Verified',
        'Rejected'
    ];

    /*
    |--------------------------------------------------------------------------
    | VALIDATION
    |--------------------------------------------------------------------------
    */

    if ($employee_id <= 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid employee.'
        ]);
        exit;
    }

    if ($edit_reason === '' || strlen($edit_reason) < 5) {
        echo json_encode([
            'success' => false,
            'message' => 'Please provide a valid reason for this edit.'
        ]);
        exit;
    }

    if (!is_array($government) || empty($government)) {
        echo json_encode([
            'success' => false,
            'message' => 'No government ID information was submitted.'
        ]);
        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | VERIFY EMPLOYEE BELONGS TO THIS HR BRANCH
    |--------------------------------------------------------------------------
    */

    $employeeCheck = $conn->prepare("
        SELECT employee_id
        FROM employees
        WHERE employee_id = ? AND company_id = ?
        LIMIT 1
    ");

    $employeeCheck->bind_param("ii", $employee_id, $companyId);
    $employeeCheck->execute();
    $employeeResult = $employeeCheck->get_result();

    if ($employeeResult->num_rows === 0) {

        $employeeCheck->close();

        echo json_encode([
            'success' => false,
            'message' => 'Employee not found or you do not have permission to edit this employee.'
        ]);
        exit;
    }

    $employeeCheck->close();

    $verifier_id = (int) ($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);
    $verifier_id = $verifier_id > 0 ? $verifier_id : null;

    foreach ($allowedTypes as $idType) {

        if (!isset($government[$idType])) {
            continue;
        }

        $entry = $government[$idType];

        $idNumber = trim($entry['id_number'] ?? '');
        $status = trim($entry['status'] ?? 'Missing');
        $remarks = trim($entry['remarks'] ?? '');

        if (!in_array($status, $allowedStatuses, true)) {
            $status = 'Missing';
        }

        $idNumber = $idNumber !== '' ? $idNumber : null;
        $remarks = $remarks !== '' ? $remarks : null;

        $verifiedAt = ($status === 'Verified') ? date('Y-m-d H:i:s') : null;
        $verifiedBy = ($status === 'Verified') ? $verifier_id : null;

        /*
        |--------------------------------------------------------------------------
        | CHECK EXISTING RECORD
        |--------------------------------------------------------------------------
        */

        $checkStmt = $conn->prepare("
            SELECT government_id
            FROM employee_government_ids
            WHERE employee_id = ? AND company_id = ?
              AND id_type = ?
            LIMIT 1
        ");

        $checkStmt->bind_param("iis", $employee_id, $companyId, $idType);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();
        $existing = $checkResult->fetch_assoc();
        $checkStmt->close();

        if ($existing) {

            $governmentId = (int) $existing['government_id'];

            $updateStmt = $conn->prepare("
                UPDATE employee_government_ids
                SET
                    id_number = ?,
                    status = ?,
                    remarks = ?,
                    verified_by = ?,
                    verified_at = ?
                WHERE government_id = ?
            ");

            $updateStmt->bind_param(
                "sssisi",
                $idNumber,
                $status,
                $remarks,
                $verifiedBy,
                $verifiedAt,
                $governmentId
            );

            $updateStmt->execute();
            $updateStmt->close();

        } else {

            $insertStmt = $conn->prepare("
                INSERT INTO employee_government_ids
                (
                    company_id,
                    employee_id,
                    id_type,
                    id_number,
                    status,
                    remarks,
                    verified_by,
                    verified_at
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $insertStmt->bind_param(
                "iissssis",
                $companyId,
                $employee_id,
                $idType,
                $idNumber,
                $status,
                $remarks,
                $verifiedBy,
                $verifiedAt
            );

            $insertStmt->execute();
            $insertStmt->close();
        }
    }

    echo json_encode([
        'success' => true,
        'message' => 'Government ID information has been updated.'
    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| UPDATE FACE BIOMETRIC
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['update_face_biometric'])
) {

    header('Content-Type: application/json');

    $employee_id = (int) ($_POST['employee_id'] ?? 0);
    $face_descriptor = trim($_POST['face_descriptor'] ?? '');
    $face_image = $_POST['face_image'] ?? '';
    $edit_reason = trim($_POST['edit_reason'] ?? '');

    /*
    |--------------------------------------------------------------------------
    | VALIDATION
    |--------------------------------------------------------------------------
    */

    if ($employee_id <= 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid employee.'
        ]);
        exit;
    }

    if ($face_descriptor === '') {
        echo json_encode([
            'success' => false,
            'message' => 'No face was captured. Please try again.'
        ]);
        exit;
    }

    $decodedDescriptor = json_decode($face_descriptor, true);

    if (!is_array($decodedDescriptor) || empty($decodedDescriptor)) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid face data.'
        ]);
        exit;
    }

    if ($edit_reason === '' || strlen($edit_reason) < 5) {
        echo json_encode([
            'success' => false,
            'message' => 'Please provide a valid reason for this update.'
        ]);
        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | VERIFY EMPLOYEE BELONGS TO THIS HR BRANCH
    |--------------------------------------------------------------------------
    */

    $employeeCheck = $conn->prepare("
        SELECT employee_id
        FROM employees
        WHERE employee_id = ? AND company_id = ?
        LIMIT 1
    ");

    $employeeCheck->bind_param("ii", $employee_id, $companyId);
    $employeeCheck->execute();
    $employeeResult = $employeeCheck->get_result();

    if ($employeeResult->num_rows === 0) {

        $employeeCheck->close();

        echo json_encode([
            'success' => false,
            'message' => 'Employee not found or you do not have permission to edit this employee.'
        ]);
        exit;
    }

    $employeeCheck->close();

    /*
    |--------------------------------------------------------------------------
    | OPTIONAL PREVIEW IMAGE
    |--------------------------------------------------------------------------
    */

    $facePath = null;

    if ($face_image !== '') {

        if (
            preg_match(
                '/^data:image\/(jpeg|jpg|png);base64,/',
                $face_image,
                $matches
            )
        ) {

            $base64Image = preg_replace(
                '/^data:image\/(jpeg|jpg|png);base64,/',
                '',
                $face_image
            );

            $imageBinary = base64_decode($base64Image, true);

            if ($imageBinary !== false) {

                $faceDirectory = "../uploads/employee_biometrics/";

                if (!is_dir($faceDirectory)) {
                    mkdir($faceDirectory, 0755, true);
                }

                $faceFileName =
                    'EMP-' . $employee_id . '-FACE-' .
                    bin2hex(random_bytes(5)) . '.jpg';

                $faceDestination = $faceDirectory . $faceFileName;

                if (file_put_contents($faceDestination, $imageBinary) !== false) {
                    $facePath = 'uploads/employee_biometrics/' . $faceFileName;
                }
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | CHECK EXISTING BIOMETRIC RECORD
    |--------------------------------------------------------------------------
    */

    $verifier_id = (int) ($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);
    $verifier_id = $verifier_id > 0 ? $verifier_id : null;

    $capturedAt = date('Y-m-d H:i:s');

    $checkStmt = $conn->prepare("
        SELECT biometric_id
        FROM employee_biometrics
        WHERE employee_id = ? AND company_id = ?
          AND biometric_type = 'Face'
        LIMIT 1
    ");

    $checkStmt->bind_param("ii", $employee_id, $companyId);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    $existing = $checkResult->fetch_assoc();
    $checkStmt->close();

    if ($existing) {

        $biometricId = (int) $existing['biometric_id'];

        if ($facePath !== null) {

            $updateStmt = $conn->prepare("
                UPDATE employee_biometrics
                SET
                    biometric_type = 'Face',
                    front_image = ?,
                    face_descriptor = ?,
                    captured_angles = 1,
                    status = 'Captured',
                    captured_by = ?,
                    captured_at = ?
                WHERE biometric_id = ?
            ");

            $updateStmt->bind_param(
                "ssisi",
                $facePath,
                $face_descriptor,
                $verifier_id,
                $capturedAt,
                $biometricId
            );

        } else {

            $updateStmt = $conn->prepare("
                UPDATE employee_biometrics
                SET
                    biometric_type = 'Face',
                    face_descriptor = ?,
                    captured_angles = 1,
                    status = 'Captured',
                    captured_by = ?,
                    captured_at = ?
                WHERE biometric_id = ?
            ");

            $updateStmt->bind_param(
                "sisi",
                $face_descriptor,
                $verifier_id,
                $capturedAt,
                $biometricId
            );
        }

        if (!$updateStmt->execute()) {

            $updateStmt->close();

            echo json_encode([
                'success' => false,
                'message' => 'Unable to update face data.'
            ]);
            exit;
        }

        $updateStmt->close();

    } else {

        $insertStmt = $conn->prepare("
            INSERT INTO employee_biometrics
            (
                company_id,
                employee_id,
                biometric_type,
                front_image,
                face_descriptor,
                captured_angles,
                status,
                captured_by,
                captured_at
            )
            VALUES (?, ?, 'Face', ?, ?, 1, 'Captured', ?, ?)
        ");

        $insertStmt->bind_param(
            "iissis",
            $companyId,
            $employee_id,
            $facePath,
            $face_descriptor,
            $verifier_id,
            $capturedAt
        );

        if (!$insertStmt->execute()) {

            $insertStmt->close();

            echo json_encode([
                'success' => false,
                'message' => 'Unable to save face data.'
            ]);
            exit;
        }

        $insertStmt->close();
    }

    echo json_encode([
        'success' => true,
        'message' => 'Face registration has been updated.'
    ]);

    exit;
}


include includeRoleHeader(__DIR__, 'hr_header.php');



$employee_id = isset($_GET['id'])
    ? (int) $_GET['id']
    : 0;

if ($employee_id <= 0) {
    die("Invalid employee.");
}

$activeTab = $_GET['tab'] ?? 'overview';

/*
|--------------------------------------------------------------------------
| GET EMPLOYEE
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT
        e.*,
        j.job_title,
        j.department,
        j.employment_type,
        j.salary_min,
        j.salary_max,
        b.branch_name
    FROM employees e
    LEFT JOIN job j
        ON e.job_id = j.job_id AND j.company_id = e.company_id
    LEFT JOIN branch b
        ON e.branch_id = b.branch_id AND b.company_id = e.company_id
    WHERE e.employee_id = ?
      AND e.company_id = ?
    LIMIT 1
");


$stmt->bind_param(
    "ii",
    $employee_id,
    $companyId
);

$stmt->execute();
$result = $stmt->get_result();
$employee = $result->fetch_assoc();
$stmt->close();

/* =========================================================
   EMPLOYEE DOCUMENTS
   Based on employee_documents table
========================================================= */

$documents = [];

$stmtDocuments = $conn->prepare("
    SELECT
        document_id,
        employee_id,
        document_name,
        file_path,
        uploaded_at,
        created_at
    FROM employee_documents
    WHERE employee_id = ? AND company_id = ?
    ORDER BY document_id ASC
");

$stmtDocuments->bind_param("ii", $employee_id, $companyId);
$stmtDocuments->execute();
$resultDocuments = $stmtDocuments->get_result();

while ($document = $resultDocuments->fetch_assoc()) {
    $documents[] = $document;
}

$stmtDocuments->close();

/* =========================================================
   ATTENDANCE RECORDS
========================================================= */

$attendanceRecords = [];

$stmtAttendance = $conn->prepare("
    SELECT
        attendance_id,
        attendance_date,
        time_in,
        time_out,
        late_minutes,
        undertime_minutes,
        overtime_hours,
        working_hours,
        status
    FROM attendance
    WHERE employee_id = ? AND company_id = ?
    ORDER BY attendance_date DESC, attendance_id DESC
");

$stmtAttendance->bind_param("ii", $employee_id, $companyId);
$stmtAttendance->execute();
$resultAttendance = $stmtAttendance->get_result();

while ($attendanceRow = $resultAttendance->fetch_assoc()) {
    $attendanceRecords[] = $attendanceRow;
}

$stmtAttendance->close();

$attendanceExportFileName =
    'attendance_' .
    preg_replace(
        '/[^A-Za-z0-9_-]/',
        '_',
        (string) ($employee['employee_code'] ?? $employee_id)
    ) .
    '.csv';

/* =========================================================
   FACE BIOMETRIC
========================================================= */

$faceBiometric = null;

$stmtFace = $conn->prepare("
    SELECT
        biometric_id,
        front_image,
        face_descriptor,
        status,
        captured_at
    FROM employee_biometrics
    WHERE employee_id = ? AND company_id = ?
      AND biometric_type = 'Face'
    LIMIT 1
");

$stmtFace->bind_param("ii", $employee_id, $companyId);
$stmtFace->execute();
$resultFace = $stmtFace->get_result();
$faceBiometric = $resultFace->fetch_assoc();
$stmtFace->close();

$faceRegistered = !empty($faceBiometric['face_descriptor']);

/* =========================================================
   GOVERNMENT IDS
========================================================= */

$governmentTypes = [
    'SSS',
    'PhilHealth',
    'Pag-IBIG',
    'TIN',
    'National ID',
    'Passport',
    'Driver License'
];

$governmentIds = [];

foreach ($governmentTypes as $type) {
    $governmentIds[$type] = [
        'government_id' => null,
        'employee_id' => $employee_id,
        'id_type' => $type,
        'id_number' => '',
        'status' => 'Missing',
        'verified_by' => null,
        'verified_at' => null,
        'remarks' => null
    ];
}

$govStmt = mysqli_prepare(
    $conn,
    "
    SELECT
        government_id,
        employee_id,
        id_type,
        id_number,
        status,
        verified_by,
        verified_at,
        remarks
    FROM employee_government_ids
    WHERE employee_id = ? AND company_id = ?
    ORDER BY government_id ASC
    "
);

if ($govStmt) {
    mysqli_stmt_bind_param(
        $govStmt,
        "ii",
        $employee_id,
        $companyId
    );
    mysqli_stmt_execute($govStmt);
    $govResult = mysqli_stmt_get_result($govStmt);

    if ($govResult) {
        while ($row = mysqli_fetch_assoc($govResult)) {
            if (isset($governmentIds[$row['id_type']])) {
                $governmentIds[$row['id_type']] = $row;
            }
        }
    }
    mysqli_stmt_close($govStmt);
}

if (!$employee) {

    die("Employee not found or you do not have permission to view this employee.");
}


$fullName = trim(
    $employee['first_name'] . ' ' .
    ($employee['middle_name'] ?? '') . ' ' .
    $employee['last_name'] . ' ' .
    ($employee['suffix'] ?? '')
);


$initials =
    strtoupper(substr($employee['first_name'], 0, 1)) .
    strtoupper(substr($employee['last_name'], 0, 1));

$startDate = !empty($employee['created_at'])
    ? date('Y-m-d', strtotime($employee['created_at']))
    : '-';

?>

<style>
    body {
        background: #f4f7fb;
    }

    .employee-view {
        color: #00224c;
    }

    .back-link {
        color: #52627a;
        text-decoration: none;
        font-size: 14px;
        display: inline-flex;
        gap: 7px;
        align-items: center;
        margin-bottom: 22px;
    }

    .back-link:hover {
        color: #00224c;
    }

    .profile-header {
        background: white;
        border-radius: 18px;
        overflow: hidden;
        box-shadow: 0 3px 14px rgba(0, 34, 76, .08);
        border: 1px solid #dfe5ec;
        margin-bottom: 18px;
    }

    .profile-cover {
        height: 105px;
        background: #00224c;
    }

    .profile-content {
        padding: 0 24px 20px;
        position: relative;
    }

    .profile-avatar {
        width: 88px;
        height: 88px;
        border-radius: 17px;
        background: #00224c;
        border: 4px solid white;
        color: white;
        font-size: 27px;
        font-weight: 700;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-top: -45px;
        position: relative;
    }

    .profile-name {
        margin-top: -70px;
        margin-left: 110px;
        min-height: 70px;
        padding-top: 12px;
    }

    .profile-name h2 {
        font-weight: 700;
        margin-bottom: 2px;
        color: #00224c;
    }

    .profile-subtitle {
        color: #52627a;
        font-size: 14px;
    }

    .tag {
        display: inline-block;
        padding: 5px 11px;
        border-radius: 20px;
        font-size: 12px;
        margin-right: 5px;
        margin-top: 7px;
    }

    .tag-branch {
        background: #eef2f7;
        border: 1px solid #cbd5e1;
    }

    .tag-green {
        background: #e7f8ed;
        border: 1px solid #b8e7c7;
        color: #138a42;
    }

    .tag-yellow {
        background: #fff4d8;
        border: 1px solid #f2d37e;
        color: #966600;
    }

    .action-bar {
        margin-top: 20px;
        display: flex;
        flex-wrap: wrap;
        gap: 9px;
    }

    .action-btn {
        border-radius: 20px;
        border: 1px solid #dbe2ea;
        background: white;
        color: #00224c;
        padding: 9px 16px;
        text-decoration: none;
        font-size: 13px;
        display: inline-flex;
        gap: 7px;
        align-items: center;
    }

    .action-btn:hover {
        background: #f5f7fa;
        color: #00224c;
    }

    .archive-btn {
        background: #ff3838;
        border-color: #ff3838;
        color: white;
    }

    .archive-btn:hover {
        background: #dc2626;
        color: white;
    }

    .info-card {
        background: white;
        border: 1px solid #dfe5ec;
        border-radius: 15px;
        box-shadow: 0 3px 12px rgba(0, 34, 76, .05);
        overflow: hidden;
        margin-bottom: 20px;
    }

    .info-card-header {
        padding: 17px 20px;
        border-bottom: 1px solid #e5e9ef;
        font-weight: 600;
    }

    .info-card-body {
        padding: 20px;
    }

    .info-box {
        background: #f7f9fb;
        border-radius: 14px;
        padding: 14px 15px;
        height: 100%;
    }

    .info-label {
        font-size: 10px;
        text-transform: uppercase;
        letter-spacing: .5px;
        color: #718096;
        margin-bottom: 5px;
    }

    .info-value {
        font-size: 14px;
        color: #00224c;
        font-weight: 500;
    }

    .compliance-item {
        padding: 8px 0;
        color: #00224c;
        font-size: 13px;
    }

    .compliance-item i {
        color: #20a957;
        margin-right: 8px;
    }

    /* =========================================================
   GOVERNMENT ID CARDS
========================================================= */

    .government-card {
        background: #fff;
        border: 1px solid #dfe5ec;
        border-radius: 14px;
        padding: 20px;
        height: 100%;
        box-shadow: 0 2px 8px rgba(0, 34, 76, 0.04);
        transition: 0.2s ease;
    }

    .government-card:hover {
        border-color: #cbd5e1;
        box-shadow: 0 4px 12px rgba(0, 34, 76, 0.08);
    }

    .government-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 14px;
    }

    .government-title {
        font-size: 15px;
        font-weight: 700;
        color: #00224c;
    }

    .gov-number {
        font-family: monospace;
        font-size: 14px;
        color: #00224c;
        margin-bottom: 10px;
    }

    .gov-date {
        font-size: 12px;
        color: #718096;
    }

    .gov-status {
        display: inline-block;
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 600;
    }

    .gov-verified {
        background: #e8f8ed;
        color: #138a42;
        border: 1px solid #b9e8c8;
    }

    .gov-pending {
        background: #fff4d6;
        color: #a16207;
        border: 1px solid #f4cf72;
    }

    .gov-rejected {
        background: #fdeaea;
        color: #c0392b;
        border: 1px solid #f4c1bd;
    }

    .gov-missing {
        background: #eef2f7;
        color: #64748b;
        border: 1px solid #dbe2ea;
    }

    /* =========================================
   EMPLOYEE TABS
========================================= */

    .tabs-card {
        display: flex;
        align-items: center;
        gap: 4px;
        padding: 5px;
        margin-bottom: 20px;

        background: #ffffff;
        border: 1px solid #dfe5ec;
        border-radius: 30px;

        box-shadow: 0 3px 12px rgba(0, 34, 76, 0.06);

        overflow-x: auto;
        white-space: nowrap;
    }

    .tab-item {
        display: inline-flex;
        align-items: center;
        justify-content: center;

        padding: 9px 15px;

        border-radius: 22px;

        color: #52627a;
        text-decoration: none;

        font-size: 13px;
        font-weight: 500;

        transition: all 0.2s ease;
    }

    .tab-item:hover {
        color: #00224c;
        background: #f5f7fa;
    }

    .tab-item.active {
        background: #00224c;
        color: #ffffff;
        font-weight: 600;
    }

    .section-header h5 {
        color: #00224c;
    }

    .document-card {
        background: #ffffff;
        border: 1px solid #dfe5ec;
        border-radius: 14px;
        padding: 18px;
        height: 100%;
        box-shadow: 0 3px 10px rgba(0, 34, 76, 0.06);
        transition: 0.2s ease;
    }

    .document-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 16px rgba(0, 34, 76, 0.10);
    }

    .document-card-top {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 15px;
    }

    .document-icon {
        width: 38px;
        height: 38px;
        border-radius: 9px;
        background: #f1f5f9;
        color: #00224c;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 19px;
    }

    .document-status {
        padding: 5px 11px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 600;
    }

    .document-status.uploaded {
        background: #e8f8ed;
        color: #138a42;
        border: 1px solid #b9e8c8;
    }

    .document-status.missing {
        background: #eef2f7;
        color: #64748b;
        border: 1px solid #dbe2ea;
    }

    .document-name {
        color: #00224c;
        font-size: 15px;
        font-weight: 600;
        margin-bottom: 7px;
    }

    .document-date {
        color: #64748b;
        font-size: 12px;
        margin-bottom: 15px;
    }

    .document-actions {
        display: flex;
        gap: 8px;
    }

    .document-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 6px 12px;
        border: 1px solid #dbe2ea;
        border-radius: 20px;
        background: #fff;
        color: #00224c;
        font-size: 12px;
        text-decoration: none;
        transition: 0.2s;
    }

    .document-btn:hover {
        background: #00224c;
        color: #fff;
        border-color: #00224c;
    }

    .document-btn.disabled {
        color: #94a3b8;
        background: #f8fafc;
        cursor: not-allowed;
    }

    .empty-documents {
        text-align: center;
        padding: 60px 20px;
        color: #64748b;
    }

    .empty-documents i {
        display: block;
        font-size: 45px;
        margin-bottom: 12px;
        color: #cbd5e1;
    }

    /* =========================================
   TAB PANELS
========================================= */

    .tab-panel {
        display: none;
    }

    .tab-panel.active {
        display: block;
    }

    /* =========================================
   EDIT ACTION DROPDOWN
========================================= */

    #employeeEditDropdown .dropdown-toggle::after {
        margin-left: 6px;
    }

    #employeeEditDropdown .dropdown-menu {
        min-width: 220px;
        padding: 6px;
        border: 1px solid #dfe5ec;
        border-radius: 12px;
    }

    #employeeEditDropdown .dropdown-item {
        color: #00224c;
        font-size: 13px;
        padding: 9px 11px;
        border-radius: 8px;
    }

    #employeeEditDropdown .dropdown-item:hover {
        background: #f5f7fa;
        color: #00224c;
    }

    #employeeEditDropdown .dropdown-item i {
        width: 18px;
        text-align: center;
    }

    /* =========================================
   ATTENDANCE PRINT
========================================= */

    @media print {
        body * {
            visibility: hidden;
        }

        #attendanceRecords,
        #attendanceRecords * {
            visibility: visible;
        }

        #attendanceRecords {
            display: block !important;
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            padding: 0;
        }

        #attendanceRecords .info-card {
            border: none;
            box-shadow: none;
        }

        #attendanceRecords .info-card-header,
        #attendanceToolbar {
            display: none !important;
        }
    }
</style>


<div class="container-fluid py-3 employee-view">

    <!-- BACK -->
    <a href="employee_directory.php" class="back-link">
        <i class="bi bi-arrow-left"></i>
        Employee Directory
    </a>


    <!-- PROFILE HEADER -->
    <div class="profile-header">
        <div class="profile-cover"></div>
        <div class="profile-content">
            <div class="profile-avatar">
                <?= htmlspecialchars($initials) ?>
            </div>
            <div class="profile-name">
                <h2>
                    <?= htmlspecialchars($fullName) ?>
                </h2>
                <div class="profile-subtitle">
                    <?= htmlspecialchars($employee['employee_code'] ?? '') ?>
                    ·
                    <?= htmlspecialchars($employee['job_title'] ?? '-') ?>
                    ·
                    <?= htmlspecialchars($employee['department'] ?? '-') ?>
                </div>
            </div>


            <!-- TAGS -->
            <div>
                <span class="tag tag-branch">
                    <?= htmlspecialchars($employee['branch_name'] ?? '-') ?>
                </span>
                <span class="tag tag-green">
                    <?= htmlspecialchars($employee['employment_status'] ?? '-') ?>
                </span>
                <span class="tag tag-green">
                    <?= htmlspecialchars($employee['employment_type'] ?? '-') ?>
                </span>
                <span class="tag tag-yellow">
                    <?php
                    if (!empty($employee['created_at'])) {
                        $created =
                            new DateTime($employee['created_at']);
                        $today =
                            new DateTime();
                        $interval =
                            $created->diff($today);
                        echo $interval->y . " yrs " .
                            $interval->m . " mos";
                    } else {
                        echo "-";
                    }
                    ?>
                    service
                </span>
            </div>
            <!-- ACTIONS -->
            <div class="action-bar">

                <!-- DYNAMIC EDIT DROPDOWN -->
                <div class="dropdown" id="employeeEditDropdown">

                    <button type="button" class="action-btn dropdown-toggle" id="editActionButton"
                        data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-pencil-square me-1"></i>
                        Edit
                    </button>

                    <ul class="dropdown-menu shadow-sm" id="employeeEditMenu">

                        <!-- PERSONAL INFORMATION -->
                        <li class="tab-action-item" data-action-for="personalInformation">
                            <button type="button" class="dropdown-item" data-bs-toggle="modal"
                                data-bs-target="#editPersonalInformationModal">
                                <i class="bi bi-person me-2"></i>
                                Edit Personal Information
                            </button>
                        </li>


                        <!-- GOVERNMENT IDS -->
                        <li class="tab-action-item" data-action-for="governmentIds">
                            <button type="button" class="dropdown-item" data-bs-toggle="modal"
                                data-bs-target="#editGovernmentIdsModal">
                                <i class="bi bi-person-vcard me-2"></i>
                                Edit Government IDs
                            </button>
                        </li>


                        <!-- DOCUMENTS -->
                        <li class="tab-action-item" data-action-for="documents">
                            <button type="button" class="dropdown-item" data-bs-toggle="modal"
                                data-bs-target="#uploadDocumentModal">
                                <i class="bi bi-upload me-2"></i>
                                Upload Document
                            </button>
                        </li>


                        <!-- DISCIPLINARY RECORDS -->
                        <li class="tab-action-item" data-action-for="disciplinaryRecords">
                            <button type="button" class="dropdown-item" data-bs-toggle="modal"
                                data-bs-target="#addIncidentModal">
                                <i class="bi bi-plus-lg me-2"></i>
                                Add Incident
                            </button>
                        </li>

                        <li class="tab-action-item" data-action-for="disciplinaryRecords">
                            <button type="button" class="dropdown-item">
                                <i class="bi bi-folder2-open me-2"></i>
                                View Records
                            </button>
                        </li>


                        <!-- MOVEMENT HISTORY -->
                        <li class="tab-action-item" data-action-for="movementHistory">
                            <button type="button" class="dropdown-item" data-bs-toggle="modal"
                                data-bs-target="#addMovementModal">
                                <i class="bi bi-plus-lg me-2"></i>
                                Add Movement
                            </button>
                        </li>

                    </ul>

                </div>


                <!-- ARCHIVE - ALWAYS VISIBLE -->
                <button type="button" class="action-btn archive-btn" id="archiveEmployee"
                    data-id="<?= (int) $employee['employee_id'] ?>">
                    <i class="bi bi-archive me-1"></i>
                    Archive
                </button>

            </div>
        </div>
    </div>


    <!-- TABS -->
    <div class="tabs-card d-flex align-items-center">
        <a href="#" class="tab-item active" data-tab="overview">
            Overview
        </a>
        <a href="#" class="tab-item" data-tab="personalInformation">
            Personal Information
        </a>
        <a href="#" class="tab-item" data-tab="governmentIds">
            Government IDs
        </a>
        <a href="#" class="tab-item" data-tab="documents">
            Documents
        </a>
        <a href="#" class="tab-item" data-tab="attendanceRecords">
            Attendance
        </a>
        <a href="#" class="tab-item" data-tab="faceRecognition">
            Face Recognition
        </a>
        <a href="#" class="tab-item" data-tab="disciplinaryRecords">
            Disciplinary Records
        </a>
        <a href="#" class="tab-item" data-tab="movementHistory">
            Movement History
        </a>
        <a href="#" class="tab-item" data-tab="timeline">
            Timeline
        </a>
        <a href="#" class="tab-item" data-tab="activityLogs">
            Activity Logs
        </a>

    </div>

    <div class="employee-tab-content">
        <!-- OVERVIEW -->
        <div class="tab-panel active" id="overview">
            <div class="row g-3">
                <div class="col-lg-8">
                    <div class="info-card">
                        <div class="info-card-header">
                            Employment Snapshot
                        </div>
                        <div class="info-card-body">
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <div class="info-box">
                                        <div class="info-label">
                                            Employee Number
                                        </div>
                                        <div class="info-value">
                                            <?= htmlspecialchars($employee['employee_code'] ?? '-') ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="info-box">
                                        <div class="info-label">
                                            Branch
                                        </div>
                                        <div class="info-value">
                                            <?= htmlspecialchars($employee['branch_name'] ?? '-') ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="info-box">
                                        <div class="info-label">
                                            Department
                                        </div>
                                        <div class="info-value">
                                            <?= htmlspecialchars($employee['department'] ?? '-') ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="info-box">
                                        <div class="info-label">
                                            Position
                                        </div>
                                        <div class="info-value">
                                            <?= htmlspecialchars($employee['job_title'] ?? '-') ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="info-box">
                                        <div class="info-label">
                                            Employment Status
                                        </div>
                                        <div class="info-value">
                                            <?= htmlspecialchars($employee['employment_status'] ?? '-') ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="info-box">
                                        <div class="info-label">
                                            Employment Type
                                        </div>
                                        <div class="info-value">
                                            <?= htmlspecialchars($employee['employment_type'] ?? '-') ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="info-box">
                                        <div class="info-label">
                                            Official Start Date
                                        </div>
                                        <div class="info-value">
                                            <?= htmlspecialchars($startDate) ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="info-box">
                                        <div class="info-label">
                                            Salary Reference
                                        </div>
                                        <div class="info-value">
                                            <?php
                                            if (
                                                $employee['salary_min'] !== null ||
                                                $employee['salary_max'] !== null
                                            ) {
                                                echo '₱ ';
                                                if ($employee['salary_min'] !== null) {
                                                    echo number_format(
                                                        $employee['salary_min'],
                                                        2
                                                    );
                                                }
                                                if (
                                                    $employee['salary_min'] !== null &&
                                                    $employee['salary_max'] !== null
                                                ) {
                                                    echo ' - ';
                                                }
                                                if ($employee['salary_max'] !== null) {
                                                    echo '₱ ' .
                                                        number_format(
                                                            $employee['salary_max'],
                                                            2
                                                        );
                                                }
                                            } else {
                                                echo '-';
                                            }
                                            ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-4">
                    <!-- COMPLIANCE -->
                    <div class="info-card">
                        <div class="info-card-header">
                            Employee Information
                        </div>
                        <div class="info-card-body">
                            <div class="compliance-item">
                                <i class="bi bi-check-circle"></i>
                                Employee record available
                            </div>
                            <div class="compliance-item">
                                <i class="bi bi-check-circle"></i>
                                Branch assignment available
                            </div>
                            <div class="compliance-item">
                                <i class="bi bi-check-circle"></i>
                                Job assignment available
                            </div>
                        </div>
                    </div>

                    <!-- CONSUMING MODULES -->
                    <div class="info-card">
                        <div class="info-card-header">
                            Consuming Modules
                        </div>
                        <div class="info-card-body">
                            <div class="row g-2">
                                <div class="col-6">
                                    <div class="info-box">
                                        Attendance
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="info-box">
                                        Payroll
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="info-box">
                                        Finance
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="info-box">
                                        Admin Portal
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="info-box">
                                        Employee Portal
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- PERSONAL INFORMATION -->
        <div class="tab-panel" id="personalInformation">
            <div class="info-card">
                <div class="info-card-header d-flex align-items-center">
                    Personal Information

                </div>
                <div class="info-card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="info-box">
                                <div class="info-label">
                                    Full Name
                                </div>
                                <div class="info-value">
                                    <?= htmlspecialchars($fullName) ?>
                                </div>
                            </div>
                        </div>


                        <div class="col-md-3">

                            <div class="info-box">

                                <div class="info-label">
                                    Date of Birth
                                </div>

                                <div class="info-value">
                                    <?= htmlspecialchars($employee['date_of_birth'] ?? '-') ?>
                                </div>

                            </div>

                        </div>


                        <div class="col-md-3">

                            <div class="info-box">

                                <div class="info-label">
                                    Gender
                                </div>

                                <div class="info-value">
                                    <?= htmlspecialchars($employee['gender'] ?? '-') ?>
                                </div>

                            </div>

                        </div>


                        <div class="col-md-4">

                            <div class="info-box">

                                <div class="info-label">
                                    Civil Status
                                </div>

                                <div class="info-value">
                                    <?= htmlspecialchars($employee['civil_status'] ?? '-') ?>
                                </div>

                            </div>

                        </div>

                    </div>

                </div>

            </div>


            <!-- CONTACT INFORMATION -->
            <div class="info-card" id="contactInformation">

                <div class="info-card-header">
                    Contact Information
                </div>

                <div class="info-card-body">

                    <div class="row g-3">

                        <div class="col-md-6">

                            <div class="info-box">

                                <div class="info-label">
                                    Email
                                </div>

                                <div class="info-value">
                                    <?= htmlspecialchars($employee['email'] ?? '-') ?>
                                </div>

                            </div>

                        </div>


                        <div class="col-md-6">

                            <div class="info-box">

                                <div class="info-label">
                                    Phone
                                </div>

                                <div class="info-value">
                                    <?= htmlspecialchars($employee['phone'] ?? '-') ?>
                                </div>

                            </div>

                        </div>


                        <div class="col-md-12">

                            <div class="info-box">

                                <div class="info-label">
                                    Location
                                </div>

                                <div class="info-value">
                                    <?= htmlspecialchars($employee['location'] ?? '-') ?>
                                </div>

                            </div>

                        </div>

                    </div>

                </div>

            </div>


            <!-- EMERGENCY CONTACT -->
            <div class="info-card" id="emergencyContact">

                <div class="info-card-header">
                    Emergency Contact
                </div>

                <div class="info-card-body">

                    <div class="row g-3">

                        <div class="col-md-4">

                            <div class="info-box">

                                <div class="info-label">
                                    Contact Name
                                </div>

                                <div class="info-value">
                                    <?= htmlspecialchars($employee['emergency_contact_name'] ?? '-') ?>
                                </div>

                            </div>

                        </div>


                        <div class="col-md-4">

                            <div class="info-box">

                                <div class="info-label">
                                    Contact Number
                                </div>

                                <div class="info-value">
                                    <?= htmlspecialchars($employee['emergency_contact_number'] ?? '-') ?>
                                </div>

                            </div>

                        </div>


                        <div class="col-md-4">

                            <div class="info-box">

                                <div class="info-label">
                                    Relationship
                                </div>

                                <div class="info-value">
                                    <?= htmlspecialchars($employee['emergency_contact_relationship'] ?? '-') ?>
                                </div>

                            </div>

                        </div>

                    </div>

                </div>

            </div>
        </div>

        <!-- GOVERNMENT IDs -->
        <div class="tab-panel" id="governmentIds">
            <div class="info-card">
                <div class="info-card-header">
                    Government Identification
                </div>
                <div class="info-card-body">
                    <div class="row g-3">
                        <?php foreach ($governmentTypes as $idType): ?>
                            <?php
                            $gov = $governmentIds[$idType] ?? null;
                            $idNumber = $gov['id_number'] ?? '';
                            $status = $gov['status'] ?? 'Missing';
                            if ($status === 'Verified') {
                                $badgeClass = 'gov-verified';
                            } elseif ($status === 'Submitted') {
                                $badgeClass = 'gov-pending';
                            } elseif ($status === 'Rejected') {
                                $badgeClass = 'gov-rejected';
                            } else {
                                $badgeClass = 'gov-missing';
                            }
                            ?>
                            <div class="col-md-6 col-xl-4">
                                <div class="government-card">
                                    <div class="government-header">
                                        <h6 class="government-title">
                                            <?= htmlspecialchars($idType) ?>
                                        </h6>
                                        <span class="gov-status <?= $badgeClass ?>">
                                            <?= htmlspecialchars($status) ?>
                                        </span>
                                    </div>
                                    <div class="gov-number">
                                        <?php if (!empty($idNumber)): ?>
                                            <?= htmlspecialchars($idNumber) ?>
                                        <?php else: ?>
                                            <span class="text-muted">
                                                Not provided
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="gov-date">
                                        <?php if ($status === 'Verified' && !empty($gov['verified_at'])): ?>
                                            Verified:
                                            <?= date('Y-m-d', strtotime($gov['verified_at'])) ?>
                                        <?php else: ?>
                                            Not verified
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- DOCUMENTS -->
        <div class="tab-panel" id="documents">
            <div class="info-card">
                <div class="info-card-header">
                    Documents
                </div>

                <div class="info-card-body">
                    <div class="row g-3">
                        <?php if (!empty($documents)): ?>
                            <?php foreach ($documents as $document): ?>
                                <?php
                                $hasFile = !empty($document['file_path']);
                                $uploadedDate = !empty($document['uploaded_at'])
                                    ? date('Y-m-d', strtotime($document['uploaded_at']))
                                    : '—';
                                $filePath = $document['file_path'] ?? '';
                                $fileUrl = $hasFile
                                    ? htmlspecialchars($filePath)
                                    : '#';
                                ?>
                                <div class="col-lg-4 col-md-6">
                                    <div class="document-card">
                                        <div class="document-card-top">
                                            <div class="document-icon">
                                                <i class="bi bi-file-earmark-text"></i>
                                            </div>
                                            <span class="document-status <?= $hasFile ? 'uploaded' : 'missing' ?>">
                                                <?php if ($hasFile): ?>
                                                    <i class="bi bi-check-circle me-1"></i>
                                                    Uploaded
                                                <?php else: ?>
                                                    <i class="bi bi-dash-circle me-1"></i>
                                                    Missing
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                        <div class="document-name">
                                            <?= htmlspecialchars($document['document_name']) ?>
                                        </div>
                                        <div class="document-date">
                                            <?php if ($hasFile): ?>
                                                Uploaded
                                                <?= htmlspecialchars($uploadedDate) ?>
                                            <?php else: ?>
                                                Not uploaded
                                            <?php endif; ?>
                                        </div>
                                        <div class="document-actions">
                                            <?php if ($hasFile): ?>
                                                <a href="<?= $fileUrl ?>" target="_blank" class="document-btn">
                                                    <i class="bi bi-eye me-1"></i>
                                                    Preview
                                                </a>
                                                <a href="<?= $fileUrl ?>" download class="document-btn">
                                                    <i class="bi bi-download me-1"></i>
                                                    Download
                                                </a>
                                            <?php else: ?>
                                                <button type="button" class="document-btn disabled" disabled>
                                                    <i class="bi bi-eye me-1"></i>
                                                    Preview
                                                </button>
                                                <button type="button" class="document-btn disabled" disabled>
                                                    <i class="bi bi-download me-1"></i>
                                                    Download
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="col-12">
                                <div class="empty-documents">
                                    <i class="bi bi-folder2-open"></i>
                                    <h5>No documents found</h5>
                                    <p>There are no documents associated with this employee.</p>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

        </div>

        <!-- ATTENDANCE RECORDS -->
        <div class="tab-panel" id="attendanceRecords">
            <div class="info-card">
                <div class="info-card-header d-flex align-items-center justify-content-between flex-wrap gap-2">

                    <span>Attendance Records</span>

                    <?php if (!empty($attendanceRecords)): ?>
                        <div class="d-flex gap-2" id="attendanceToolbar">
                            <button type="button" class="action-btn" id="printAttendanceBtn">
                                <i class="bi bi-printer"></i>
                                Print
                            </button>
                            <button type="button" class="action-btn" id="exportAttendanceBtn">
                                <i class="bi bi-download"></i>
                                Export CSV
                            </button>
                        </div>
                    <?php endif; ?>

                </div>

                <div class="info-card-body">

                    <?php if (!empty($attendanceRecords)): ?>

                        <div id="attendancePrintArea">

                            <div class="d-none d-print-block mb-3">
                                <h5 class="fw-bold mb-0" style="color:#00224c;">
                                    <?= htmlspecialchars($fullName) ?>
                                </h5>
                                <div class="text-muted small">
                                    EMP-<?= htmlspecialchars($employee['employee_code'] ?? '') ?>
                                    &middot;
                                    Attendance Records
                                    &middot;
                                    Printed <?= date('Y-m-d') ?>
                                </div>
                            </div>

                            <div class="table-responsive">
                                <table class="table table-bordered table-hover align-middle" id="attendanceTable">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Time In</th>
                                            <th>Time Out</th>
                                            <th>Working Hours</th>
                                            <th>Late (mins)</th>
                                            <th>Undertime (mins)</th>
                                            <th>Overtime (hrs)</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($attendanceRecords as $record): ?>
                                            <?php
                                            $rowStatus = $record['status'] ?? '-';

                                            $rowStatusClass = match ($rowStatus) {
                                                'Present' => 'bg-success-subtle text-success',
                                                'Late' => 'bg-warning-subtle text-warning',
                                                'Half Day' => 'bg-info-subtle text-info',
                                                'Absent' => 'bg-danger-subtle text-danger',
                                                default => 'bg-secondary-subtle text-secondary'
                                            };
                                            ?>
                                            <tr>
                                                <td>
                                                    <?= htmlspecialchars(
                                                        date('M d, Y', strtotime($record['attendance_date']))
                                                    ) ?>
                                                </td>
                                                <td>
                                                    <?= $record['time_in']
                                                        ? htmlspecialchars(date('h:i A', strtotime($record['time_in'])))
                                                        : '-' ?>
                                                </td>
                                                <td>
                                                    <?= $record['time_out']
                                                        ? htmlspecialchars(date('h:i A', strtotime($record['time_out'])))
                                                        : '-' ?>
                                                </td>
                                                <td>
                                                    <?= number_format((float) ($record['working_hours'] ?? 0), 2) ?>
                                                </td>
                                                <td>
                                                    <?= (int) ($record['late_minutes'] ?? 0) ?>
                                                </td>
                                                <td>
                                                    <?= (int) ($record['undertime_minutes'] ?? 0) ?>
                                                </td>
                                                <td>
                                                    <?= number_format((float) ($record['overtime_hours'] ?? 0), 2) ?>
                                                </td>
                                                <td>
                                                    <span class="badge <?= $rowStatusClass ?> rounded-pill px-3">
                                                        <?= htmlspecialchars($rowStatus) ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                        </div>

                    <?php else: ?>

                        <div class="empty-documents">
                            <i class="bi bi-calendar3"></i>
                            <h5>No attendance records</h5>
                            <p>This employee has no recorded time in/out entries yet.</p>
                        </div>

                    <?php endif; ?>

                </div>
            </div>
        </div>

        <!-- FACE RECOGNITION -->
        <div class="tab-panel" id="faceRecognition">
            <div class="info-card">
                <div class="info-card-header">
                    Face Recognition
                </div>

                <div class="info-card-body">

                    <div class="row g-3">

                        <!-- STATUS -->
                        <div class="col-lg-4">
                            <div class="info-box h-100">

                                <div class="info-label">
                                    Registration Status
                                </div>

                                <?php if ($faceRegistered): ?>
                                    <span class="gov-status gov-verified d-inline-flex align-items-center gap-1 mb-2">
                                        <i class="bi bi-check-circle"></i>
                                        Registered
                                    </span>
                                <?php else: ?>
                                    <span class="gov-status gov-missing d-inline-flex align-items-center gap-1 mb-2">
                                        <i class="bi bi-dash-circle"></i>
                                        Not Registered
                                    </span>
                                <?php endif; ?>

                                <?php if (!empty($faceBiometric['captured_at'])): ?>
                                    <div class="gov-date">
                                        Last updated:
                                        <?= htmlspecialchars(
                                            date('Y-m-d h:i A', strtotime($faceBiometric['captured_at']))
                                        ) ?>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($faceBiometric['front_image'])): ?>
                                    <div class="mt-3">
                                        <img src="../<?= htmlspecialchars($faceBiometric['front_image']) ?>"
                                            class="rounded-3 border" style="width:100%;max-width:160px;object-fit:cover;">
                                    </div>
                                <?php endif; ?>

                            </div>
                        </div>

                        <!-- CAPTURE -->
                        <div class="col-lg-8">
                            <div class="border rounded-4 p-3 h-100" style="border-color:#dfe5ec !important;">

                                <div class="row g-3">

                                    <!-- CAMERA -->
                                    <div class="col-md-6">

                                        <div class="bg-dark rounded-4 d-flex align-items-center justify-content-center"
                                            style="height:220px;overflow:hidden;">

                                            <video id="faceCamera" class="w-100 h-100" autoplay playsinline
                                                style="object-fit:cover;"></video>

                                        </div>

                                        <div class="text-center mt-2">
                                            <span id="faceCameraStatus" class="badge rounded-pill bg-secondary">
                                                Camera not started
                                            </span>
                                        </div>

                                        <div class="d-flex gap-2 mt-3">

                                            <button type="button" id="startFaceCameraBtn"
                                                class="btn btn-sm text-white flex-fill" style="background:#00224c;">
                                                <i class="bi bi-camera me-1"></i>
                                                Start Camera
                                            </button>

                                            <button type="button" id="captureFaceBtn"
                                                class="btn btn-sm btn-success flex-fill" disabled>
                                                <i class="bi bi-person-bounding-box me-1"></i>
                                                Capture Face
                                            </button>

                                        </div>

                                    </div>

                                    <!-- REASON + SAVE -->
                                    <div class="col-md-6">

                                        <div class="mb-2">
                                            <span id="faceCaptureBadge" class="badge rounded-pill bg-secondary">
                                                No new capture yet
                                            </span>
                                        </div>

                                        <div class="mb-3">

                                            <label class="form-label small fw-semibold text-secondary">
                                                Reason for Update
                                                <span class="text-danger">*</span>
                                            </label>

                                            <textarea id="faceEditReason" rows="4" maxlength="500"
                                                class="form-control rounded-3"
                                                placeholder="Explain why this employee's face is being registered or updated..."></textarea>

                                        </div>

                                        <button type="button" id="saveFaceRegistrationBtn" class="btn rounded-3 w-100"
                                            style="background:#00224c;color:#fff;" disabled>
                                            <i class="bi bi-check2-circle me-1"></i>
                                            Save Face Registration
                                        </button>

                                    </div>

                                </div>

                            </div>
                        </div>

                    </div>

                </div>
            </div>
        </div>

        <!-- DISCIPLINARY RECORDS -->
        <div class="tab-panel" id="disciplinaryRecords">
            <div class="info-card">
                <div class="info-card-body">
                    <div class="empty-documents">
                        <i class="bi bi-shield-exclamation"></i>
                        <h5>No disciplinary records</h5>
                        <p>This employee has no recorded incidents. Use "Add Incident" from the Edit menu to log one.
                        </p>
                    </div>
                </div>
            </div>
        </div>


        <!-- MOVEMENT HISTORY -->
        <div class="tab-panel" id="movementHistory">
            <div class="info-card">
                <div class="info-card-body">
                    <div class="empty-documents">
                        <i class="bi bi-signpost-2"></i>
                        <h5>No movement history</h5>
                        <p>Transfers, promotions, and reassignments will appear here once recorded.</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- TIMELINE -->
        <div class="tab-panel" id="timeline">
            <div class="info-card">
                <div class="info-card-body">
                    <div class="empty-documents">
                        <i class="bi bi-clock-history"></i>
                        <h5>No timeline events yet</h5>
                        <p>Key milestones in this employee's record will be shown here.</p>
                    </div>
                </div>
            </div>
        </div>


        <!-- ACTIVITY LOGS -->
        <div class="tab-panel" id="activityLogs">
            <div class="info-card">
                <div class="info-card-body">
                    <div class="empty-documents">
                        <i class="bi bi-list-check"></i>
                        <h5>No activity logged</h5>
                        <p>Changes made to this employee's record will be tracked here.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<!-- EDIT PERSONAL INFORMATION MODAL -->
<div class="modal fade" id="editPersonalInformationModal" tabindex="-1" aria-hidden="true">

    <div class="modal-dialog modal-lg modal-dialog-centered">

        <div class="modal-content border-0 shadow-lg rounded-4">

            <form id="editPersonalInformationForm">

                <!-- HEADER -->
                <div class="modal-header px-4 py-3">

                    <div>

                        <h5 class="modal-title fw-bold" style="color:#00224c;">

                            <i class="bi bi-person me-2"></i>
                            Edit Personal Information

                        </h5>

                        <small class="text-muted">
                            Update the employee's personal information.
                        </small>

                    </div>

                    <button type="button" class="btn-close" data-bs-dismiss="modal">
                    </button>

                </div>


                <!-- BODY -->
                <div class="modal-body p-4">

                    <input type="hidden" name="employee_id" value="<?= (int) $employee_id ?>">
                    <input type="hidden" name="update_personal_information" value="1">


                    <div class="row g-3">

                        <!-- FIRST NAME -->
                        <div class="col-md-6">

                            <label class="form-label fw-semibold">
                                First Name
                                <span class="text-danger">*</span>
                            </label>

                            <input type="text" name="first_name"
                                value="<?= htmlspecialchars($employee['first_name'] ?? '') ?>" maxlength="100" required
                                class="form-control rounded-3">

                        </div>


                        <!-- MIDDLE NAME -->
                        <div class="col-md-6">

                            <label class="form-label fw-semibold">
                                Middle Name
                            </label>

                            <input type="text" name="middle_name"
                                value="<?= htmlspecialchars($employee['middle_name'] ?? '') ?>" maxlength="100"
                                class="form-control rounded-3">

                        </div>


                        <!-- LAST NAME -->
                        <div class="col-md-6">

                            <label class="form-label fw-semibold">
                                Last Name
                                <span class="text-danger">*</span>
                            </label>

                            <input type="text" name="last_name"
                                value="<?= htmlspecialchars($employee['last_name'] ?? '') ?>" maxlength="100" required
                                class="form-control rounded-3">

                        </div>


                        <!-- SUFFIX -->
                        <div class="col-md-6">

                            <label class="form-label fw-semibold">
                                Suffix
                            </label>

                            <input type="text" name="suffix" value="<?= htmlspecialchars($employee['suffix'] ?? '') ?>"
                                maxlength="20" class="form-control rounded-3">

                        </div>


                        <!-- DATE OF BIRTH -->
                        <div class="col-md-4">

                            <label class="form-label fw-semibold">
                                Date of Birth
                            </label>

                            <input type="date" name="date_of_birth"
                                value="<?= htmlspecialchars($employee['date_of_birth'] ?? '') ?>"
                                class="form-control rounded-3">

                        </div>


                        <!-- GENDER -->
                        <div class="col-md-4">

                            <label class="form-label fw-semibold">
                                Gender
                            </label>

                            <select name="gender" class="form-select rounded-3">

                                <option value="">
                                    Select Gender
                                </option>

                                <option value="Male" <?= ($employee['gender'] ?? '') === 'Male'
                                    ? 'selected'
                                    : '' ?>>
                                    Male
                                </option>

                                <option value="Female" <?= ($employee['gender'] ?? '') === 'Female'
                                    ? 'selected'
                                    : '' ?>>
                                    Female
                                </option>

                            </select>

                        </div>


                        <!-- CIVIL STATUS -->
                        <div class="col-md-4">

                            <label class="form-label fw-semibold">
                                Civil Status
                            </label>

                            <select name="civil_status" class="form-select rounded-3">

                                <option value="">
                                    Select Civil Status
                                </option>

                                <?php foreach (
                                    ['Single', 'Married', 'Widowed', 'Separated']
                                    as $civil
                                ): ?>

                                    <option value="<?= htmlspecialchars($civil) ?>" <?= ($employee['civil_status'] ?? '') === $civil
                                          ? 'selected'
                                          : '' ?>>

                                        <?= htmlspecialchars($civil) ?>

                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>

                    </div>


                    <!-- REASON -->
                    <div class="mt-4 p-3 rounded-3 bg-warning-subtle">

                        <label class="form-label fw-semibold">

                            Reason for Edit
                            <span class="text-danger">*</span>

                        </label>

                        <textarea name="edit_reason" id="personalEditReason" rows="3" maxlength="500" required
                            class="form-control rounded-3"
                            placeholder="Explain why this information is being changed..."></textarea>

                        <small class="text-muted">
                            Required for audit and change tracking.
                        </small>

                    </div>

                </div>


                <!-- FOOTER -->
                <div class="modal-footer px-4 py-3">

                    <button type="button" class="btn btn-light border rounded-3 px-4" data-bs-dismiss="modal">

                        Cancel

                    </button>

                    <button type="submit" id="savePersonalInfoButton" class="btn rounded-3 px-4"
                        style="background:#00224c;color:white;">

                        <i class="bi bi-check2-circle me-1"></i>
                        Save Changes

                    </button>

                </div>

            </form>

        </div>

    </div>

</div>
<!-- EDIT GOVERNMENT IDS MODAL -->
<div class="modal fade" id="editGovernmentIdsModal" tabindex="-1" aria-hidden="true">

    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">

        <div class="modal-content border-0 shadow-lg rounded-4">

            <form id="editGovernmentIdsForm">

                <!-- HEADER -->
                <div class="modal-header px-4 py-3">

                    <div>

                        <h5 class="modal-title fw-bold" style="color:#00224c;">

                            <i class="bi bi-person-vcard me-2"></i>
                            Edit Government IDs

                        </h5>

                        <small class="text-muted">
                            Update government identification records and verification status.
                        </small>

                    </div>

                    <button type="button" class="btn-close" data-bs-dismiss="modal">
                    </button>

                </div>


                <!-- BODY -->
                <div class="modal-body bg-light p-4">

                    <input type="hidden" name="employee_id" value="<?= (int) $employee_id ?>">
                    <input type="hidden" name="update_government_ids" value="1">


                    <div class="row g-3">

                        <?php foreach ($governmentTypes as $idType): ?>

                            <?php
                            $gov = $governmentIds[$idType] ?? [];

                            $status = $gov['status'] ?? 'Missing';
                            ?>

                            <div class="col-md-6 col-xl-4">

                                <div class="bg-white border rounded-4 p-3 h-100">

                                    <!-- ID HEADER -->
                                    <div class="d-flex justify-content-between align-items-center mb-3">

                                        <div class="fw-semibold" style="color:#00224c;">

                                            <?= htmlspecialchars($idType) ?>

                                        </div>

                                        <?php
                                        $badgeClass = match ($status) {
                                            'Verified'
                                            => 'bg-success-subtle text-success',

                                            'Submitted'
                                            => 'bg-primary-subtle text-primary',

                                            'Rejected'
                                            => 'bg-danger-subtle text-danger',

                                            default
                                            => 'bg-secondary-subtle text-secondary'
                                        };
                                        ?>

                                        <span class="badge <?= $badgeClass ?> rounded-pill px-3">

                                            <?= htmlspecialchars($status) ?>

                                        </span>

                                    </div>


                                    <!-- ID TYPE -->
                                    <input type="hidden" name="government[<?= htmlspecialchars($idType) ?>][id_type]"
                                        value="<?= htmlspecialchars($idType) ?>">


                                    <!-- ID NUMBER -->
                                    <div class="mb-3">

                                        <label class="form-label small fw-semibold text-secondary">

                                            ID Number

                                        </label>

                                        <input type="text" name="government[<?= htmlspecialchars($idType) ?>][id_number]"
                                            value="<?= htmlspecialchars($gov['id_number'] ?? '') ?>" maxlength="100"
                                            class="form-control rounded-3">

                                    </div>


                                    <!-- STATUS -->
                                    <div class="mb-3">

                                        <label class="form-label small fw-semibold text-secondary">

                                            Status

                                        </label>

                                        <select name="government[<?= htmlspecialchars($idType) ?>][status]"
                                            class="form-select rounded-3">

                                            <?php foreach (
                                                ['Missing', 'Submitted', 'Verified', 'Rejected']
                                                as $statusOption
                                            ): ?>

                                                <option value="<?= htmlspecialchars($statusOption) ?>"
                                                    <?= $status === $statusOption
                                                        ? 'selected'
                                                        : '' ?>>

                                                    <?= htmlspecialchars($statusOption) ?>

                                                </option>

                                            <?php endforeach; ?>

                                        </select>

                                    </div>


                                    <!-- REMARKS -->
                                    <div>

                                        <label class="form-label small fw-semibold text-secondary">

                                            Remarks

                                        </label>

                                        <textarea name="government[<?= htmlspecialchars($idType) ?>][remarks]" rows="2"
                                            maxlength="255"
                                            class="form-control rounded-3"><?= htmlspecialchars($gov['remarks'] ?? '') ?></textarea>

                                    </div>

                                </div>

                            </div>

                        <?php endforeach; ?>

                    </div>


                    <!-- REASON -->
                    <div class="mt-4 bg-warning-subtle border border-warning-subtle rounded-4 p-4">

                        <div class="mb-3">

                            <div class="fw-semibold text-dark">

                                <i class="bi bi-shield-check text-warning me-1"></i>

                                Reason for Edit

                                <span class="text-danger">*</span>

                            </div>

                            <small class="text-muted">

                                Required for audit and change tracking.

                            </small>

                        </div>


                        <textarea id="governmentEditReason" name="edit_reason" rows="3" maxlength="500" required
                            class="form-control rounded-3"
                            placeholder="Explain why the government ID information is being changed..."></textarea>

                    </div>

                </div>


                <!-- FOOTER -->
                <div class="modal-footer bg-white px-4 py-3">

                    <button type="button" class="btn btn-light border rounded-3 px-4" data-bs-dismiss="modal">

                        Cancel

                    </button>


                    <button type="submit" id="saveGovernmentIdChanges" class="btn rounded-3 px-4"
                        style="background:#00224c;color:#fff;">

                        <i class="bi bi-check2-circle me-1"></i>

                        Save Changes

                    </button>

                </div>

            </form>

        </div>

    </div>

</div>
<!-- UPLOAD DOCUMENT MODAL -->
<div class="modal fade" id="uploadDocumentModal" tabindex="-1" aria-hidden="true">

    <div class="modal-dialog modal-lg modal-dialog-centered">

        <div class="modal-content border-0 shadow-lg rounded-4">

            <form id="uploadDocumentForm" enctype="multipart/form-data" method="POST">
                <input type="hidden" name="upload_employee_document" value="1">

                <input type="hidden" name="employee_id" value="<?= (int) $employee_id ?>">

                <!-- HEADER -->
                <div class="modal-header px-4 py-3">

                    <div>

                        <h5 class="modal-title fw-bold" style="color:#00224c;">

                            <i class="bi bi-upload me-2"></i>

                            Upload Employee Document

                        </h5>

                        <small class="text-muted">

                            Upload and attach a document to this employee's record.

                        </small>

                    </div>

                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>

                </div>


                <!-- BODY -->
                <div class="modal-body p-4">

                    <!-- DOCUMENT TYPE -->
                    <div class="mb-4">

                        <label class="form-label fw-semibold">

                            Document Type

                            <span class="text-danger">*</span>

                        </label>

                        <select name="document_type" id="documentType" class="form-select rounded-3" required>

                            <option value="">
                                Select Document Type
                            </option>

                            <option value="Birth Certificate">
                                Birth Certificate
                            </option>

                            <option value="Resume">
                                Resume
                            </option>

                            <option value="Employment Contract">
                                Employment Contract
                            </option>

                            <option value="NBI Clearance">
                                NBI Clearance
                            </option>

                            <option value="Police Clearance">
                                Police Clearance
                            </option>

                            <option value="Medical Certificate">
                                Medical Certificate
                            </option>

                            <option value="Training Certificate">
                                Training Certificate
                            </option>

                            <option value="Other">
                                Other
                            </option>

                        </select>

                    </div>


                    <!-- OTHER DOCUMENT NAME -->
                    <div class="mb-4 d-none" id="otherDocumentContainer">

                        <label class="form-label fw-semibold">

                            Document Name

                            <span class="text-danger">*</span>

                        </label>

                        <input type="text" name="document_name" id="documentName" maxlength="150"
                            class="form-control rounded-3" placeholder="Enter document name">

                    </div>


                    <!-- FILE -->
                    <div class="mb-4">

                        <label class="form-label fw-semibold">

                            Select File

                            <span class="text-danger">*</span>

                        </label>

                        <input type="file" name="document_file" id="documentFile" class="form-control rounded-3"
                            accept=".pdf,.jpg,.jpeg,.png" required>

                        <small class="text-muted">

                            Allowed formats: PDF, JPG, JPEG, PNG. Maximum file size: 5 MB.

                        </small>

                    </div>


                    <!-- DESCRIPTION -->
                    <div class="mb-4">

                        <label class="form-label fw-semibold">

                            Description

                        </label>

                        <textarea name="description" rows="3" maxlength="500" class="form-control rounded-3"
                            placeholder="Optional description..."></textarea>

                    </div>


                    <!-- UPLOAD NOTE -->
                    <div class="p-3 rounded-3" style="
                            background:#f8fafc;
                            border:1px solid #dfe5ec;
                        ">

                        <div class="d-flex gap-2">

                            <i class="bi bi-info-circle" style="color:#00224c;"></i>

                            <small class="text-muted">

                                Make sure the uploaded document belongs
                                to this employee and contains the correct
                                information before saving.

                            </small>

                        </div>

                    </div>


                    <!-- REASON -->
                    <div class="mt-4 p-4 rounded-4 bg-warning-subtle">

                        <div class="mb-3">

                            <div class="fw-semibold text-dark">

                                <i class="bi bi-shield-check text-warning me-1"></i>

                                Reason for Upload

                                <span class="text-danger">*</span>

                            </div>

                            <small class="text-muted">

                                Required for audit and document tracking.

                            </small>

                        </div>


                        <textarea name="edit_reason" id="documentEditReason" rows="3" maxlength="500" required
                            class="form-control rounded-3"
                            placeholder="Explain why this document is being uploaded..."></textarea>

                    </div>

                </div>


                <!-- FOOTER -->
                <div class="modal-footer px-4 py-3">

                    <button type="button" class="btn btn-light border rounded-3 px-4" data-bs-dismiss="modal">

                        Cancel

                    </button>


                    <button type="submit" id="uploadDocumentButton" class="btn rounded-3 px-4" style="
                            background:#00224c;
                            color:#ffffff;
                        ">

                        <i class="bi bi-upload me-1"></i>

                        Upload Document

                    </button>

                </div>

            </form>

        </div>

    </div>

</div>

<script>
    document.getElementById("archiveEmployee")
        .addEventListener("click", function () {
            const employeeId =
                this.dataset.id;
            Swal.fire({
                title: "Archive Employee?",
                text: "This employee will be removed from the active employee directory.",
                icon: "warning",
                showCancelButton: true,
                confirmButtonColor: "#dc3545",
                cancelButtonColor: "#6c757d",
                confirmButtonText: "Yes, Archive"
            }).then(function (result) {
                if (!result.isConfirmed) {
                    return;
                }
                fetch("archive_employee.php", {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/x-www-form-urlencoded"
                    },
                    body: "employee_id=" +
                        encodeURIComponent(employeeId)
                })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            Swal.fire({
                                icon: "success",
                                title: "Archived",
                                text: data.message,
                                timer: 1500,
                                showConfirmButton: false
                            }).then(() => {
                                window.location.href =
                                    "employee_directory.php";
                            });
                        } else {
                            Swal.fire({
                                icon: "error",
                                title: "Unable to Archive",
                                text: data.message
                            });
                        }
                    })
                    .catch(error => {
                        console.error(error);
                        Swal.fire({
                            icon: "error",
                            title: "Error",
                            text: "Something went wrong."
                        });
                    });
            });
        });
</script>

<script>
    /*
    |--------------------------------------------------------------------------
    | EDIT PERSONAL INFORMATION
    |--------------------------------------------------------------------------
    */

    document.addEventListener("DOMContentLoaded", function () {

        const personalForm =
            document.getElementById("editPersonalInformationForm");

        const saveButton =
            document.getElementById("savePersonalInfoButton");

        if (!personalForm) {
            return;
        }

        personalForm.addEventListener("submit", function (event) {

            event.preventDefault();

            const reason =
                document
                    .getElementById("personalEditReason")
                    .value
                    .trim();

            if (reason.length < 5) {

                Swal.fire({
                    icon: "warning",
                    title: "Reason Required",
                    text: "Please provide at least 5 characters for the reason for edit.",
                    confirmButtonColor: "#00224c"
                });

                return;
            }

            Swal.fire({
                title: "Save Changes?",
                text: "The employee's personal information will be updated.",
                icon: "question",
                showCancelButton: true,
                confirmButtonText: "Yes, Save",
                cancelButtonText: "Cancel",
                confirmButtonColor: "#00224c",
                cancelButtonColor: "#6c757d"
            }).then(function (result) {

                if (!result.isConfirmed) {
                    return;
                }

                const formData =
                    new FormData(personalForm);

                saveButton.disabled = true;

                saveButton.innerHTML =
                    '<span class="spinner-border spinner-border-sm me-1"></span>Saving...';

                fetch(window.location.href, {
                    method: "POST",
                    body: formData
                })
                    .then(async response => {

                        const text = await response.text();

                        let data;

                        try {
                            data = JSON.parse(text);
                        } catch (error) {
                            throw new Error("The server returned an invalid response.");
                        }

                        return data;
                    })
                    .then(data => {

                        if (data.success) {

                            Swal.fire({
                                icon: "success",
                                title: "Saved",
                                text: data.message || "Personal information has been updated.",
                                timer: 1500,
                                showConfirmButton: false
                            }).then(function () {
                                location.reload();
                            });

                        } else {

                            Swal.fire({
                                icon: "error",
                                title: "Unable to Save",
                                text: data.message || "Personal information could not be updated.",
                                confirmButtonColor: "#00224c"
                            });

                            saveButton.disabled = false;

                            saveButton.innerHTML =
                                '<i class="bi bi-check2-circle me-1"></i>Save Changes';
                        }

                    })
                    .catch(error => {

                        console.error(error);

                        Swal.fire({
                            icon: "error",
                            title: "Server Error",
                            text: error.message,
                            confirmButtonColor: "#00224c"
                        });

                        saveButton.disabled = false;

                        saveButton.innerHTML =
                            '<i class="bi bi-check2-circle me-1"></i>Save Changes';
                    });

            });

        });

    });
</script>

<script>
    /*
    |--------------------------------------------------------------------------
    | EDIT GOVERNMENT IDS
    |--------------------------------------------------------------------------
    */

    document.addEventListener("DOMContentLoaded", function () {

        const governmentForm =
            document.getElementById("editGovernmentIdsForm");

        const saveButton =
            document.getElementById("saveGovernmentIdChanges");

        if (!governmentForm) {
            return;
        }


        governmentForm.addEventListener("submit", function (event) {

            event.preventDefault();


            const reason =
                document
                    .getElementById("governmentEditReason")
                    .value
                    .trim();


            if (reason.length < 5) {

                Swal.fire({
                    icon: "warning",
                    title: "Reason Required",
                    text: "Please provide at least 5 characters for the reason for edit.",
                    confirmButtonColor: "#00224c"
                });

                return;

            }


            const formData =
                new FormData(governmentForm);


            saveButton.disabled = true;

            saveButton.innerHTML =
                '<span class="spinner-border spinner-border-sm me-1"></span>Saving...';


            fetch(window.location.href, {

                method: "POST",

                body: formData

            })

                .then(async response => {

                    const text =
                        await response.text();


                    let data;

                    try {

                        data =
                            JSON.parse(text);

                    } catch (error) {

                        throw new Error(
                            "The server returned an invalid response."
                        );

                    }


                    return data;

                })

                .then(data => {

                    if (data.success) {

                        Swal.fire({

                            icon: "success",

                            title: "Government IDs Updated",

                            text: data.message ||
                                "Government ID information has been updated.",

                            timer: 1500,

                            showConfirmButton: false

                        }).then(function () {

                            location.reload();

                        });

                    } else {

                        Swal.fire({

                            icon: "error",

                            title: "Unable to Update",

                            text: data.message ||
                                "Government ID information could not be updated.",

                            confirmButtonColor: "#00224c"

                        });

                        saveButton.disabled = false;

                        saveButton.innerHTML =
                            '<i class="bi bi-check2-circle me-1"></i>Save Changes';

                    }

                })

                .catch(error => {

                    console.error(error);

                    Swal.fire({

                        icon: "error",

                        title: "Server Error",

                        text: error.message,

                        confirmButtonColor: "#00224c"

                    });

                    saveButton.disabled = false;

                    saveButton.innerHTML =
                        '<i class="bi bi-check2-circle me-1"></i>Save Changes';

                });

        });

    });
</script>
<script>
    document.addEventListener("DOMContentLoaded", function () {

        const documentType =
            document.getElementById("documentType");

        const otherContainer =
            document.getElementById("otherDocumentContainer");

        const documentName =
            document.getElementById("documentName");

        const uploadForm =
            document.getElementById("uploadDocumentForm");

        const uploadButton =
            document.getElementById("uploadDocumentButton");


        /* =====================================================
           DOCUMENT TYPE
        ===================================================== */

        if (documentType) {

            documentType.addEventListener("change", function () {

                if (this.value === "Other") {

                    otherContainer.classList.remove("d-none");

                    documentName.required = true;

                } else {

                    otherContainer.classList.add("d-none");

                    documentName.required = false;

                    documentName.value = "";

                }

            });

        }


        /* =====================================================
           UPLOAD FORM
        ===================================================== */

        if (uploadForm) {

            uploadForm.addEventListener("submit", function (event) {

                event.preventDefault();


                /* ---------------------------------------------
                   FILE
                --------------------------------------------- */

                const fileInput =
                    document.getElementById("documentFile");

                const file =
                    fileInput.files[0];


                if (!file) {

                    Swal.fire({
                        icon: "warning",
                        title: "File Required",
                        text: "Please select a document file.",
                        confirmButtonColor: "#00224c"
                    });

                    return;
                }


                /* ---------------------------------------------
                   FILE SIZE
                   Server also validates this.
                --------------------------------------------- */

                const maxFileSize =
                    5 * 1024 * 1024;

                if (file.size > maxFileSize) {

                    Swal.fire({
                        icon: "warning",
                        title: "File Too Large",
                        text: "File size must not exceed 5 MB.",
                        confirmButtonColor: "#00224c"
                    });

                    return;
                }


                /* ---------------------------------------------
                   REASON
                --------------------------------------------- */

                const reason =
                    document
                        .getElementById("documentEditReason")
                        .value
                        .trim();


                if (reason.length < 5) {

                    Swal.fire({
                        icon: "warning",
                        title: "Reason Required",
                        text: "Please provide at least 5 characters for the reason for uploading the document.",
                        confirmButtonColor: "#00224c"
                    });

                    return;
                }


                /* ---------------------------------------------
                   CONFIRM
                --------------------------------------------- */

                Swal.fire({

                    title: "Upload Document?",

                    text: "This document will be attached to the employee record.",

                    icon: "question",

                    showCancelButton: true,

                    confirmButtonText: "Yes, Upload",

                    cancelButtonText: "Cancel",

                    confirmButtonColor: "#00224c",

                    cancelButtonColor: "#6c757d"

                }).then(function (result) {

                    if (!result.isConfirmed) {
                        return;
                    }


                    /* -----------------------------------------
                       FORM DATA
                    ----------------------------------------- */

                    const formData =
                        new FormData(uploadForm);


                    /* -----------------------------------------
                       BUTTON LOADING
                    ----------------------------------------- */

                    uploadButton.disabled = true;

                    uploadButton.innerHTML =
                        '<span class="spinner-border spinner-border-sm me-1"></span>' +
                        'Uploading...';


                    /* -----------------------------------------
                       SEND TO CURRENT FILE
                    ----------------------------------------- */

                    fetch(window.location.href, {

                        method: "POST",

                        body: formData

                    })

                        .then(async function (response) {

                            const text =
                                await response.text();


                            let data;

                            try {

                                data =
                                    JSON.parse(text);

                            } catch (error) {

                                console.error(
                                    "Invalid JSON:",
                                    text
                                );

                                throw new Error(
                                    "The server returned an invalid response."
                                );
                            }


                            return data;

                        })

                        .then(function (data) {

                            if (data.success) {

                                Swal.fire({

                                    icon: "success",

                                    title: "Document Uploaded",

                                    text: data.message ||
                                        "Employee document uploaded successfully.",

                                    timer: 1500,

                                    showConfirmButton: false

                                }).then(function () {

                                    location.reload();

                                });

                            } else {

                                Swal.fire({

                                    icon: "error",

                                    title: "Upload Failed",

                                    text: data.message ||
                                        "Unable to upload the document.",

                                    confirmButtonColor: "#00224c"

                                });


                                uploadButton.disabled = false;

                                uploadButton.innerHTML =
                                    '<i class="bi bi-upload me-1"></i>' +
                                    'Upload Document';

                            }

                        })

                        .catch(function (error) {

                            console.error(
                                "Document Upload Error:",
                                error
                            );


                            Swal.fire({

                                icon: "error",

                                title: "Server Error",

                                text: error.message,

                                confirmButtonColor: "#00224c"

                            });


                            uploadButton.disabled = false;

                            uploadButton.innerHTML =
                                '<i class="bi bi-upload me-1"></i>' +
                                'Upload Document';

                        });

                });

            });

        }

    });
</script>
<script>
    document.addEventListener("DOMContentLoaded", function () {

        const tabs = document.querySelectorAll(".tab-item");
        const panels = document.querySelectorAll(".tab-panel");

        const editDropdown = document.getElementById("employeeEditDropdown");
        const editMenu = document.getElementById("employeeEditMenu");

        const actionItems = document.querySelectorAll(".tab-action-item");


        /*
        |--------------------------------------------------------------------------
        | UPDATE EDIT DROPDOWN
        |--------------------------------------------------------------------------
        */

        function updateEditDropdown(activeTab) {

            let visibleItems = 0;

            actionItems.forEach(function (item) {

                const actionFor = item.getAttribute("data-action-for");

                if (actionFor === activeTab) {

                    item.classList.remove("d-none");

                    visibleItems++;

                } else {

                    item.classList.add("d-none");

                }

            });


            /*
            |--------------------------------------------------------------------------
            | HIDE EDIT BUTTON IF CURRENT TAB HAS NO ACTION
            |--------------------------------------------------------------------------
            */

            if (visibleItems === 0) {

                editDropdown.classList.add("d-none");

            } else {

                editDropdown.classList.remove("d-none");

            }

        }


        /*
        |--------------------------------------------------------------------------
        | SWITCH TAB
        |--------------------------------------------------------------------------
        */

        function activateTab(targetTab) {

            /*
            |--------------------------------------------------------------------------
            | REMOVE ACTIVE STATE
            |--------------------------------------------------------------------------
            */

            tabs.forEach(function (item) {

                item.classList.remove("active");

            });


            panels.forEach(function (panel) {

                panel.classList.remove("active");

            });


            /*
            |--------------------------------------------------------------------------
            | ACTIVATE SELECTED TAB
            |--------------------------------------------------------------------------
            */

            const selectedTab = document.querySelector(
                '.tab-item[data-tab="' + targetTab + '"]'
            );

            const selectedPanel = document.getElementById(targetTab);


            if (selectedTab) {

                selectedTab.classList.add("active");

            }


            if (selectedPanel) {

                selectedPanel.classList.add("active");

            }


            /*
            |--------------------------------------------------------------------------
            | UPDATE EDIT DROPDOWN
            |--------------------------------------------------------------------------
            */

            updateEditDropdown(targetTab);

        }


        /*
        |--------------------------------------------------------------------------
        | TAB CLICK
        |--------------------------------------------------------------------------
        */

        tabs.forEach(function (tab) {

            tab.addEventListener("click", function (event) {

                event.preventDefault();

                const targetTab =
                    this.getAttribute("data-tab");

                activateTab(targetTab);

            });

        });


        /*
        |--------------------------------------------------------------------------
        | INITIAL TAB
        |--------------------------------------------------------------------------
        */

        const initialTab =
            "<?= htmlspecialchars($activeTab) ?>";

        activateTab(
            document.querySelector(
                '.tab-item[data-tab="' + initialTab + '"]'
            ) ?
                initialTab :
                "overview"
        );

    });
</script>


<script src="../assets/js/face-api.min.js"></script>

<script>
    document.addEventListener("DOMContentLoaded", function () {

        const startBtn = document.getElementById("startFaceCameraBtn");
        const captureBtn = document.getElementById("captureFaceBtn");
        const saveBtn = document.getElementById("saveFaceRegistrationBtn");

        if (!startBtn || !captureBtn || !saveBtn) {
            return;
        }

        const video = document.getElementById("faceCamera");
        const cameraStatus = document.getElementById("faceCameraStatus");
        const captureBadge = document.getElementById("faceCaptureBadge");
        const reasonField = document.getElementById("faceEditReason");

        let faceStream = null;
        let capturedDescriptor = null;
        let capturedImage = null;

        /*
        |--------------------------------------------------------------------------
        | LOAD FACE-API MODELS (once)
        |--------------------------------------------------------------------------
        */

        const faceModelsReady = (async function () {
            try {
                await faceapi.nets.tinyFaceDetector.loadFromUri("../models");
                await faceapi.nets.faceLandmark68Net.loadFromUri("../models");
                await faceapi.nets.faceRecognitionNet.loadFromUri("../models");
            } catch (err) {
                console.error("Failed to load face-api models", err);
            }
        })();


        /*
        |--------------------------------------------------------------------------
        | START CAMERA
        |--------------------------------------------------------------------------
        */

        startBtn.addEventListener("click", async function () {

            try {

                faceStream = await navigator.mediaDevices.getUserMedia({
                    video: { facingMode: "user" },
                    audio: false
                });

                video.srcObject = faceStream;

                cameraStatus.className = "badge rounded-pill bg-success";
                cameraStatus.textContent = "Camera ready";

                captureBtn.disabled = false;

            } catch (error) {

                console.error(error);

                cameraStatus.className = "badge rounded-pill bg-danger";
                cameraStatus.textContent = "Camera unavailable";

                Swal.fire({
                    icon: "error",
                    title: "Camera Error",
                    text: "Unable to access the camera. Please allow camera permission and try again.",
                    confirmButtonColor: "#00224c"
                });
            }

        });


        /*
        |--------------------------------------------------------------------------
        | CAPTURE FACE
        |--------------------------------------------------------------------------
        */

        captureBtn.addEventListener("click", async function () {

            if (!video.videoWidth || !video.videoHeight) {

                Swal.fire({
                    icon: "warning",
                    title: "Camera Not Ready",
                    text: "Please start the camera first.",
                    confirmButtonColor: "#00224c"
                });

                return;
            }

            captureBtn.disabled = true;

            const originalLabel = captureBtn.innerHTML;

            captureBtn.innerHTML =
                '<span class="spinner-border spinner-border-sm me-1"></span>Detecting...';

            await faceModelsReady;

            const detection = await faceapi
                .detectSingleFace(video, new faceapi.TinyFaceDetectorOptions())
                .withFaceLandmarks()
                .withFaceDescriptor();

            if (!detection) {

                Swal.fire({
                    icon: "warning",
                    title: "No Face Detected",
                    text: "Please position the employee's face clearly in front of the camera and try again.",
                    confirmButtonColor: "#00224c"
                });

                captureBtn.disabled = false;
                captureBtn.innerHTML = originalLabel;

                return;
            }

            // Snapshot for preview / record only.
            const canvas = document.createElement("canvas");
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            canvas.getContext("2d").drawImage(video, 0, 0, canvas.width, canvas.height);

            capturedImage = canvas.toDataURL("image/jpeg", 0.9);
            capturedDescriptor = JSON.stringify(Array.from(detection.descriptor));

            captureBadge.className = "badge rounded-pill bg-success";
            captureBadge.textContent = "Face captured — ready to save";

            saveBtn.disabled = false;

            captureBtn.innerHTML = originalLabel;

            // Release the camera now that we have what we need.
            if (faceStream) {
                faceStream.getTracks().forEach(track => track.stop());
                faceStream = null;
            }

            video.srcObject = null;

            cameraStatus.className = "badge rounded-pill bg-success";
            cameraStatus.textContent = "Face captured";

            captureBtn.disabled = true;

        });


        /*
        |--------------------------------------------------------------------------
        | SAVE FACE REGISTRATION
        |--------------------------------------------------------------------------
        */

        saveBtn.addEventListener("click", function () {

            if (!capturedDescriptor) {

                Swal.fire({
                    icon: "warning",
                    title: "No Face Captured",
                    text: "Please capture a face before saving.",
                    confirmButtonColor: "#00224c"
                });

                return;
            }

            const reason = reasonField.value.trim();

            if (reason.length < 5) {

                Swal.fire({
                    icon: "warning",
                    title: "Reason Required",
                    text: "Please provide at least 5 characters for the reason for this update.",
                    confirmButtonColor: "#00224c"
                });

                return;
            }

            Swal.fire({
                title: "Save Face Registration?",
                text: "This will update the employee's registered face used for attendance verification.",
                icon: "question",
                showCancelButton: true,
                confirmButtonText: "Yes, Save",
                cancelButtonText: "Cancel",
                confirmButtonColor: "#00224c",
                cancelButtonColor: "#6c757d"
            }).then(function (result) {

                if (!result.isConfirmed) {
                    return;
                }

                const formData = new FormData();

                formData.append("update_face_biometric", "1");
                formData.append("employee_id", "<?= (int) $employee_id ?>");
                formData.append("face_descriptor", capturedDescriptor);
                formData.append("face_image", capturedImage || "");
                formData.append("edit_reason", reason);

                saveBtn.disabled = true;

                saveBtn.innerHTML =
                    '<span class="spinner-border spinner-border-sm me-1"></span>Saving...';

                fetch(window.location.href, {
                    method: "POST",
                    body: formData
                })
                    .then(async response => {

                        const text = await response.text();

                        let data;

                        try {
                            data = JSON.parse(text);
                        } catch (error) {
                            throw new Error("The server returned an invalid response.");
                        }

                        return data;
                    })
                    .then(data => {

                        if (data.success) {

                            Swal.fire({
                                icon: "success",
                                title: "Saved",
                                text: data.message || "Face registration has been updated.",
                                timer: 1500,
                                showConfirmButton: false
                            }).then(function () {
                                location.reload();
                            });

                        } else {

                            Swal.fire({
                                icon: "error",
                                title: "Unable to Save",
                                text: data.message || "Face registration could not be updated.",
                                confirmButtonColor: "#00224c"
                            });

                            saveBtn.disabled = false;

                            saveBtn.innerHTML =
                                '<i class="bi bi-check2-circle me-1"></i>Save Face Registration';
                        }

                    })
                    .catch(error => {

                        console.error(error);

                        Swal.fire({
                            icon: "error",
                            title: "Server Error",
                            text: error.message,
                            confirmButtonColor: "#00224c"
                        });

                        saveBtn.disabled = false;

                        saveBtn.innerHTML =
                            '<i class="bi bi-check2-circle me-1"></i>Save Face Registration';
                    });

            });

        });

    });
</script>

<script>
    document.addEventListener("DOMContentLoaded", function () {

        /*
        |--------------------------------------------------------------------------
        | PRINT ATTENDANCE
        |--------------------------------------------------------------------------
        */

        const printBtn = document.getElementById("printAttendanceBtn");

        if (printBtn) {

            printBtn.addEventListener("click", function () {
                window.print();
            });

        }


        /*
        |--------------------------------------------------------------------------
        | EXPORT ATTENDANCE (CSV)
        |--------------------------------------------------------------------------
        */

        const exportBtn = document.getElementById("exportAttendanceBtn");

        if (exportBtn) {

            exportBtn.addEventListener("click", function () {

                const table = document.getElementById("attendanceTable");

                if (!table) {
                    return;
                }

                const rows = Array.from(table.querySelectorAll("tr"));

                const csvLines = rows.map(function (row) {

                    const cells = Array.from(row.querySelectorAll("th, td"));

                    return cells.map(function (cell) {

                        let text = cell.innerText.trim().replace(/\s+/g, " ");
                        text = text.replace(/"/g, '""');

                        return '"' + text + '"';

                    }).join(",");

                });

                const csvContent = csvLines.join("\r\n");

                // BOM prefix so Excel opens the UTF-8 file correctly.
                const blob = new Blob(
                    ["\uFEFF" + csvContent],
                    { type: "text/csv;charset=utf-8;" }
                );

                const url = URL.createObjectURL(blob);

                const link = document.createElement("a");

                link.href = url;
                link.download = "<?= htmlspecialchars($attendanceExportFileName) ?>";

                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);

                URL.revokeObjectURL(url);

            });

        }

    });
</script>

<script>
document.addEventListener("DOMContentLoaded", function () {
    if (document.getElementById("attendanceTable") && document.querySelectorAll("#attendanceTable tbody tr").length > 0) {
        new DataTable("#attendanceTable", {
            pageLength: 10,
            lengthChange: false,
            ordering: true,
            order: [[0, "desc"]],
            language: {
                search: "",
                searchPlaceholder: "Search attendance...",
                info: "Showing _START_ to _END_ of _TOTAL_",
                infoEmpty: "No records",
                zeroRecords: "No matching records",
                emptyTable: "No attendance records",
                paginate: { previous: "Previous", next: "Next" }
            }
        });
    }
});
</script>

<?php include includeRoleFooter(__DIR__, 'hr_footer.php'); ?>