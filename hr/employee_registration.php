<?php

require_once('../init.php');
requireRole(['hr', 'admin']);
/*
| The owner reaches this too.
|
| The module belongs to hr; the business belongs to the owner, so they see
| everything. The header and footer are chosen by who is reading rather than
| named outright -- an owner who opened this page used to find their own menu
| replaced by this role's, with no way back to the rest of their system.
*/
require_once __DIR__ . '/../includes/role_chrome.php';


$companyId = requireCompany();
include includeRoleHeader(__DIR__, 'hr_header.php');

/* =========================================================
   COMPLETE EMPLOYEE REGISTRATION
   ========================================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['complete_registration'])
) {

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    $uploadedFiles = [];
    $createdFiles = [];

    try {

        /* =====================================================
           START TRANSACTION
        ===================================================== */

        mysqli_begin_transaction($conn);


        /* =====================================================
           1. GET BASIC FORM DATA
        ===================================================== */

        $employeeId = (int) ($_POST['employee_id'] ?? 0);

        if ($employeeId <= 0) {
            throw new Exception('Invalid employee ID.');
        }


        /* =====================================================
           2. EMPLOYMENT INFORMATION
        ===================================================== */

        $jobLevel = trim($_POST['job_level'] ?? '');

        /*
        | Re-checked below against this company. Narrowing the dropdown fixes
        | what is offered; it does not stop another company's user_id being
        | posted straight at the form.
        */
        $supervisorId = !empty($_POST['supervisor_id'])
            ? (int) $_POST['supervisor_id']
            : null;

        $shift = trim($_POST['shift'] ?? '');

        $workingDays = trim(
            $_POST['working_days'] ?? ''
        );

        $restDay = trim(
            $_POST['rest_day'] ?? ''
        );

        $salary = (float) (
            $_POST['salary'] ?? 0
        );

        $salaryType = trim(
            $_POST['salary_type'] ?? ''
        );

        $payFrequency = trim(
            $_POST['pay_frequency'] ?? ''
        );

        $officialStartDate =
            trim($_POST['official_start_date'] ?? '');


        /* =====================================================
           3. EMPLOYEE ACCOUNT
        ===================================================== */

        $username = trim(
            $_POST['username'] ?? ''
        );

        $password = $_POST['password'] ?? '';

        $passwordConfirm =
            $_POST['password_confirm'] ?? '';

        $employeeRole =
            trim($_POST['employee_role'] ?? '');


        /* =====================================================
           4. BASIC VALIDATION
        ===================================================== */

        if ($employeeId <= 0) {
            throw new Exception(
                'Invalid employee.'
            );
        }


        if ($jobLevel === '') {
            throw new Exception(
                'Please select the employee job level.'
            );
        }


        if ($shift === '') {
            throw new Exception(
                'Please select the employee shift.'
            );
        }


        if ($workingDays === '') {
            throw new Exception(
                'Please specify the employee working days.'
            );
        }


        if ($restDay === '') {
            throw new Exception(
                'Please select the employee rest day.'
            );
        }


        if ($salary <= 0) {
            throw new Exception(
                'Please enter a valid basic salary.'
            );
        }


        if ($salaryType === '') {
            throw new Exception(
                'Please select the salary type.'
            );
        }


        if ($payFrequency === '') {
            throw new Exception(
                'Please select the pay frequency.'
            );
        }


        if ($officialStartDate === '') {
            throw new Exception(
                'Please select the official start date.'
            );
        }


        /*
        | Narrowing the dropdown decides what is offered, not what can be
        | submitted -- a user_id from another company can still be posted
        | straight at the form, so ownership is confirmed here.
        */
        if ($supervisorId !== null) {

            $supCheck = $conn->prepare("
                SELECT 1 FROM users
                WHERE user_id = ? AND company_id = ? AND status = 'active'
                LIMIT 1
            ");
            $supCheck->bind_param("ii", $supervisorId, $companyId);
            $supCheck->execute();
            $supOk = $supCheck->get_result()->num_rows === 1;
            $supCheck->close();

            if (!$supOk) {
                throw new Exception(
                    'The selected supervisor is not part of your company.'
                );
            }
        }


        /* =====================================================
           5. ACCOUNT VALIDATION
        ===================================================== */

        if ($username === '') {
            throw new Exception(
                'Please enter a username.'
            );
        }


        if (strlen($username) < 5) {
            throw new Exception(
                'Username must contain at least 5 characters.'
            );
        }


        if ($password === '') {
            throw new Exception(
                'Please enter a password.'
            );
        }


        if (strlen($password) < 8) {
            throw new Exception(
                'Password must contain at least 8 characters.'
            );
        }


        if ($password !== $passwordConfirm) {
            throw new Exception(
                'Password and confirm password do not match.'
            );
        }


        if ($employeeRole === '') {
            throw new Exception(
                'Please select an employee role.'
            );
        }


        /* =====================================================
           6. LOAD EMPLOYEE
        ===================================================== */

        $stmt = mysqli_prepare($conn, "
            SELECT
                employee_id,
                employee_code,
                application_id,
                first_name,
                middle_name,
                last_name,
                suffix,
                email,
                phone,
                branch_id,
                job_id,
                employment_status,
                profile_picture
            FROM employees
            WHERE employee_id = ? AND company_id = ?
            LIMIT 1
            FOR UPDATE
        ");

        /*
        | Two placeholders, and only $employeeId was ever passed. company_id
        | was added to the WHERE clause for tenant isolation without its
        | argument, so this threw on every attempt and no pre-employee could
        | finish registration: "The number of elements in the type definition
        | string must match the number of bind variables".
        */
        mysqli_stmt_bind_param(
            $stmt,
            "ii",
            $employeeId,
            $companyId
        );

        mysqli_stmt_execute($stmt);

        $result = mysqli_stmt_get_result($stmt);

        $employee = mysqli_fetch_assoc($result);

        mysqli_stmt_close($stmt);


        if (!$employee) {
            throw new Exception(
                'Employee record not found.'
            );
        }


        /* =====================================================
           7. MAKE SURE EMPLOYEE IS STILL PRE-EMPLOYEE
        ===================================================== */

        if (
            $employee['employment_status']
            !== 'Pre-Employee'
        ) {
            throw new Exception(
                'This employee is no longer a Pre-Employee.'
            );
        }



        /* =====================================================
           9. CHECK USERNAME
        ===================================================== */

        $stmt = mysqli_prepare($conn, "
            SELECT user_id
            FROM users
            WHERE username = ? AND company_id = ?
            LIMIT 1
        ");

        mysqli_stmt_bind_param(
            $stmt,
            "si",
            $username,
            $companyId
        );

        mysqli_stmt_execute($stmt);

        $result = mysqli_stmt_get_result($stmt);

        $existingUser = mysqli_fetch_assoc($result);

        mysqli_stmt_close($stmt);


        if ($existingUser) {
            throw new Exception(
                'The username "' .
                htmlspecialchars($username) .
                '" is already in use.'
            );
        }


        /* =====================================================
           10. CHECK EMPLOYEE ACCOUNT
        ===================================================== */

        $stmt = mysqli_prepare($conn, "
            SELECT user_id
            FROM users
            WHERE employee_id = ? AND company_id = ?
            LIMIT 1
        ");

        mysqli_stmt_bind_param(
            $stmt,
            "ii",
            $employeeId,
            $companyId
        );

        mysqli_stmt_execute($stmt);

        $result = mysqli_stmt_get_result($stmt);

        $existingEmployeeAccount =
            mysqli_fetch_assoc($result);

        mysqli_stmt_close($stmt);


        if ($existingEmployeeAccount) {
            throw new Exception(
                'This employee already has a user account.'
            );
        }


        /* =====================================================
           11. UPDATE EMPLOYMENT
        ===================================================== */

        $stmt = mysqli_prepare($conn, "
            UPDATE employment
            SET
                job_level = ?,
                supervisor_id = ?,
                shift = ?,
                working_days = ?,
                rest_day = ?,
                salary = ?,
                salary_type = ?,
                pay_frequency = ?,
                employment_status = 'Official Employee',
                official_start_date = ?
            WHERE employee_id = ?
        ");

        mysqli_stmt_bind_param(
            $stmt,
            "sisssdsssi",
            $jobLevel,
            $supervisorId,
            $shift,
            $workingDays,
            $restDay,
            $salary,
            $salaryType,
            $payFrequency,
            $officialStartDate,
            $employeeId
        );

        mysqli_stmt_execute($stmt);

        mysqli_stmt_close($stmt);


        /* =====================================================
           12. UPDATE EMPLOYEE STATUS
        ===================================================== */

        $stmt = mysqli_prepare($conn, "
            UPDATE employees
            SET
                employment_status = 'Official Employee'
            WHERE employee_id = ?
        ");

        mysqli_stmt_bind_param(
            $stmt,
            "i",
            $employeeId
        );

        mysqli_stmt_execute($stmt);

        mysqli_stmt_close($stmt);


        /* =====================================================
           13. CREATE EMPLOYEE USER ACCOUNT
        ===================================================== */

        $fullname = trim(
            $employee['first_name'] . ' ' .
            ($employee['middle_name'] ?? '') . ' ' .
            $employee['last_name'] . ' ' .
            ($employee['suffix'] ?? '')
        );

        $hashedPassword =
            password_hash(
                $password,
                PASSWORD_DEFAULT
            );

        $userStatus = 'active';

        $stmt = mysqli_prepare($conn, "
            INSERT INTO users
            (
                company_id,
                employee_id,
                username,
                fullname,
                email,
                contact,
                password,
                role,
                status
            )
            VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        mysqli_stmt_bind_param(
            $stmt,
            "iisssssss",
            $companyId,
            $employeeId,
            $username,
            $fullname,
            $employee['email'],
            $employee['phone'],
            $hashedPassword,
            $employeeRole,
            $userStatus
        );

        mysqli_stmt_execute($stmt);

        $newUserId = mysqli_insert_id($conn);

        mysqli_stmt_close($stmt);


        /* =====================================================
           14. GOVERNMENT IDS
           ===================================================== */

        $governmentIds = [
            'SSS' => [
                'field' => 'sss_number'
            ],
            'PhilHealth' => [
                'field' => 'philhealth_number'
            ],
            'Pag-IBIG' => [
                'field' => 'pagibig_number'
            ],
            'TIN' => [
                'field' => 'tin_number'
            ],
            'National ID' => [
                'field' => 'national_id_number'
            ],
            'Passport' => [
                'field' => 'passport_number'
            ],
            'Driver License' => [
                'field' => 'driver_license_number'
            ]
        ];


        /*
        | The SSS / PhilHealth / Pag-IBIG / TIN files are posted once but saved
        | twice: here as the government ID proof, and again in section 15 as an
        | employee document. PHP's temp upload can only be moved once, so the
        | second move_uploaded_file() always failed ("Failed to upload Pag-IBIG
        | Document.") and rolled the whole registration back. Each moved file is
        | recorded here so section 15 copies it instead.
        */
        $movedGovernmentUploads = [];


        foreach (
            $governmentIds as $idType => $idInfo
        ) {

            $idNumber = trim(
                $_POST[$idInfo['field']] ?? ''
            );


            /* Corresponding uploaded document */
            $documentField = match ($idType) {

                'SSS' =>
                'sss_document',

                'PhilHealth' =>
                'philhealth_document',

                'Pag-IBIG' =>
                'pagibig_document',

                'TIN' =>
                'tin_document',

                default =>
                null
            };


            $documentPath = null;


            /* =============================================
               UPLOAD GOVERNMENT DOCUMENT
            ============================================= */

            if (
                $documentField !== null
                && isset(
                $_FILES[$documentField]
            )
                && $_FILES[$documentField]['error']
                === UPLOAD_ERR_OK
            ) {

                $file = $_FILES[$documentField];

                $allowedExtensions = [
                    'pdf',
                    'jpg',
                    'jpeg',
                    'png'
                ];

                $extension = strtolower(
                    pathinfo(
                        $file['name'],
                        PATHINFO_EXTENSION
                    )
                );


                if (
                    !in_array(
                        $extension,
                        $allowedExtensions,
                        true
                    )
                ) {
                    throw new Exception(
                        'Invalid file type for ' .
                        $idType .
                        ' document.'
                    );
                }


                if ($file['size'] > 10 * 1024 * 1024) {
                    throw new Exception(
                        $idType .
                        ' document is larger than 10MB.'
                    );
                }


                $uploadDirectory =
                    __DIR__ .
                    '/uploads/employee_government_ids/';


                if (
                    !is_dir(
                        $uploadDirectory
                    )
                ) {
                    if (
                        !mkdir(
                            $uploadDirectory,
                            0755,
                            true
                        )
                    ) {
                        throw new Exception(
                            'Unable to create government ID upload directory.'
                        );
                    }
                }


                $safeType = preg_replace(
                    '/[^A-Za-z0-9_-]/',
                    '_',
                    strtolower($idType)
                );


                $newFileName =
                    'EMP-' .
                    $employeeId .
                    '-' .
                    $safeType .
                    '-' .
                    bin2hex(
                        random_bytes(5)
                    ) .
                    '.' .
                    $extension;


                $destination =
                    $uploadDirectory .
                    $newFileName;


                if (
                    !move_uploaded_file(
                        $file['tmp_name'],
                        $destination
                    )
                ) {
                    throw new Exception(
                        'Failed to upload ' .
                        $idType .
                        ' document.'
                    );
                }


                $documentPath =
                    'uploads/employee_government_ids/' .
                    $newFileName;


                $createdFiles[] =
                    $destination;


                $movedGovernmentUploads[$documentField] =
                    $destination;
            }


            /* =============================================
               STATUS
            ============================================= */

            if (
                $idNumber !== ''
                || $documentPath !== null
            ) {
                $governmentStatus =
                    'Submitted';
            } else {
                $governmentStatus =
                    'Missing';
            }


            /* =============================================
               CHECK EXISTING GOVERNMENT ID
            ============================================= */

            $stmt = mysqli_prepare($conn, "
                SELECT government_id
                FROM employee_government_ids
                WHERE employee_id = ?
                  AND id_type = ?
                  AND company_id = ?
                LIMIT 1
            ");

            mysqli_stmt_bind_param(
                $stmt,
                "isi",
                $employeeId,
                $idType,
                $companyId
            );

            mysqli_stmt_execute($stmt);

            $result =
                mysqli_stmt_get_result($stmt);

            $existingGovernmentId =
                mysqli_fetch_assoc($result);

            mysqli_stmt_close($stmt);


            if ($existingGovernmentId) {

                $governmentId =
                    (int) $existingGovernmentId[
                        'government_id'
                    ];


                $stmt = mysqli_prepare($conn, "
                    UPDATE employee_government_ids
                    SET
                        id_number = ?,
                        document_path = ?,
                        status = ?
                    WHERE government_id = ?
                ");

                mysqli_stmt_bind_param(
                    $stmt,
                    "sssi",
                    $idNumber,
                    $documentPath,
                    $governmentStatus,
                    $governmentId
                );

                mysqli_stmt_execute($stmt);

                mysqli_stmt_close($stmt);

            } else {

                $stmt = mysqli_prepare($conn, "
                    INSERT INTO employee_government_ids
                    (
                        company_id,
                        employee_id,
                        id_type,
                        id_number,
                        document_path,
                        status
                    )
                    VALUES
                    (?, ?, ?, ?, ?, ?)
                ");

                mysqli_stmt_bind_param(
                    $stmt,
                    "iissss",
                    $companyId,
                    $employeeId,
                    $idType,
                    $idNumber,
                    $documentPath,
                    $governmentStatus
                );

                mysqli_stmt_execute($stmt);

                mysqli_stmt_close($stmt);
            }
        }


        /* =====================================================
           15. EMPLOYEE DOCUMENTS
           ===================================================== */

        $documents = [

            'resume' => [
                'name' => 'Resume / CV',
                'type' => 'Resume'
            ],

            'birth_certificate' => [
                'name' => 'Birth Certificate',
                'type' => 'Personal Document'
            ],

            'medical_certificate' => [
                'name' => 'Medical Certificate',
                'type' => 'Medical'
            ],

            'nbi_clearance' => [
                'name' => 'NBI Clearance',
                'type' => 'Clearance'
            ],

            'police_clearance' => [
                'name' => 'Police Clearance',
                'type' => 'Clearance'
            ],

            'employment_contract' => [
                'name' => 'Employment Contract',
                'type' => 'Employment'
            ],

            'sss_document' => [
                'name' => 'SSS Document',
                'type' => 'Government ID'
            ],

            'philhealth_document' => [
                'name' => 'PhilHealth Document',
                'type' => 'Government ID'
            ],

            'pagibig_document' => [
                'name' => 'Pag-IBIG Document',
                'type' => 'Government ID'
            ],

            'tin_document' => [
                'name' => 'TIN Document',
                'type' => 'Government ID'
            ]
        ];


        foreach (
            $documents as $fieldName => $documentInfo
        ) {

            $documentPath = null;


            /* =============================================
               UPLOAD FILE
            ============================================= */

            if (
                isset($_FILES[$fieldName])
                && $_FILES[$fieldName]['error']
                === UPLOAD_ERR_OK
            ) {

                $file = $_FILES[$fieldName];

                $allowedExtensions = [
                    'pdf',
                    'jpg',
                    'jpeg',
                    'png',
                    'doc',
                    'docx'
                ];

                $extension = strtolower(
                    pathinfo(
                        $file['name'],
                        PATHINFO_EXTENSION
                    )
                );


                if (
                    !in_array(
                        $extension,
                        $allowedExtensions,
                        true
                    )
                ) {
                    throw new Exception(
                        'Invalid file type for ' .
                        $documentInfo['name'] .
                        '.'
                    );
                }


                if ($file['size'] > 10 * 1024 * 1024) {
                    throw new Exception(
                        $documentInfo['name'] .
                        ' is larger than 10MB.'
                    );
                }


                $uploadDirectory =
                    __DIR__ .
                    '/uploads/employee_documents/';


                if (
                    !is_dir(
                        $uploadDirectory
                    )
                ) {
                    if (
                        !mkdir(
                            $uploadDirectory,
                            0755,
                            true
                        )
                    ) {
                        throw new Exception(
                            'Unable to create employee document upload directory.'
                        );
                    }
                }


                $safeName = preg_replace(
                    '/[^A-Za-z0-9_-]/',
                    '_',
                    strtolower(
                        str_replace(
                            ' ',
                            '_',
                            $documentInfo['name']
                        )
                    )
                );


                $newFileName =
                    'EMP-' .
                    $employeeId .
                    '-' .
                    $safeName .
                    '-' .
                    bin2hex(
                        random_bytes(5)
                    ) .
                    '.' .
                    $extension;


                $destination =
                    $uploadDirectory .
                    $newFileName;


                $stored = isset($movedGovernmentUploads[$fieldName])
                    ? copy(
                        $movedGovernmentUploads[$fieldName],
                        $destination
                    )
                    : move_uploaded_file(
                        $file['tmp_name'],
                        $destination
                    );


                if (!$stored) {
                    throw new Exception(
                        'Failed to upload ' .
                        $documentInfo['name'] .
                        '.'
                    );
                }


                $documentPath =
                    'uploads/employee_documents/' .
                    $newFileName;


                $createdFiles[] =
                    $destination;
            }


            /* =============================================
               RESUME FROM APPLICATION
               ============================================= */

            if (
                $fieldName === 'resume'
                && $documentPath === null
                && !empty(
                $employee['application_id']
            )
            ) {

                $stmt = mysqli_prepare($conn, "
                    SELECT resume
                    FROM applications
                    WHERE application_id = ? AND company_id = ?
                    LIMIT 1
                ");

                $applicationId =
                    (int) $employee['application_id'];

                mysqli_stmt_bind_param(
                    $stmt,
                    "ii",
                    $applicationId,
                    $companyId
                );

                mysqli_stmt_execute($stmt);

                $result =
                    mysqli_stmt_get_result($stmt);

                $application =
                    mysqli_fetch_assoc($result);

                mysqli_stmt_close($stmt);


                if (
                    $application
                    && !empty($application['resume'])
                ) {
                    $documentPath =
                        $application['resume'];
                }
            }


            /* =============================================
               STATUS
            ============================================= */

            $documentStatus =
                $documentPath !== null
                ? 'Uploaded'
                : 'Missing';


            /* =============================================
               CHECK EXISTING DOCUMENT
            ============================================= */

            $stmt = mysqli_prepare($conn, "
                SELECT document_id
                FROM employee_documents
                WHERE employee_id = ?
                  AND document_name = ?
                  AND company_id = ?
                LIMIT 1
            ");

            mysqli_stmt_bind_param(
                $stmt,
                "isi",
                $employeeId,
                $documentInfo['name'],
                $companyId
            );

            mysqli_stmt_execute($stmt);

            $result =
                mysqli_stmt_get_result($stmt);

            $existingDocument =
                mysqli_fetch_assoc($result);

            mysqli_stmt_close($stmt);


            if ($existingDocument) {

                $documentId =
                    (int) $existingDocument[
                        'document_id'
                    ];


                $stmt = mysqli_prepare($conn, "
                    UPDATE employee_documents
                    SET
                        document_type = ?,
                        file_path = ?,
                        status = ?,
                        uploaded_at = CASE
                            WHEN ? IS NOT NULL
                            THEN NOW()
                            ELSE uploaded_at
                        END
                    WHERE document_id = ?
                ");

                mysqli_stmt_bind_param(
                    $stmt,
                    "ssssi",
                    $documentInfo['type'],
                    $documentPath,
                    $documentStatus,
                    $documentPath,
                    $documentId
                );

                mysqli_stmt_execute($stmt);

                mysqli_stmt_close($stmt);

            } else {

                $uploadedAt =
                    $documentPath !== null
                    ? date('Y-m-d H:i:s')
                    : null;


                $stmt = mysqli_prepare($conn, "
                    INSERT INTO employee_documents
                    (
                        company_id,
                        employee_id,
                        document_name,
                        document_type,
                        file_path,
                        status,
                        uploaded_at
                    )
                    VALUES
                    (?, ?, ?, ?, ?, ?, ?)
                ");

                mysqli_stmt_bind_param(
                    $stmt,
                    "iisssss",
                    $companyId,
                    $employeeId,
                    $documentInfo['name'],
                    $documentInfo['type'],
                    $documentPath,
                    $documentStatus,
                    $uploadedAt
                );

                mysqli_stmt_execute($stmt);

                mysqli_stmt_close($stmt);
            }
        }


        /* =====================================================
           16. FACE BIOMETRIC
           ===================================================== */

        $faceRegistered =
            (int) (
                $_POST['face_registered'] ?? 0
            );

        $faceImage =
            $_POST['face_image'] ?? '';

        $faceDescriptor =
            trim($_POST['face_descriptor'] ?? '');


        if (
            $faceRegistered === 1
            && $faceDescriptor !== ''
        ) {

            /* =============================================
               VALIDATE FACE DESCRIPTOR
               (this is what attendance time in/out uses
               to match the employee's live face)
            ============================================= */

            $decodedDescriptor = json_decode(
                $faceDescriptor,
                true
            );

            if (
                !is_array($decodedDescriptor)
                || count($decodedDescriptor) === 0
            ) {
                throw new Exception(
                    'Invalid face descriptor data.'
                );
            }


            /* =============================================
               VALIDATE BASE64 IMAGE (OPTIONAL PREVIEW ONLY)
            ============================================= */

            $imageBinary = null;

            if ($faceImage !== '') {

                if (
                    !preg_match(
                        '/^data:image\/(jpeg|jpg|png);base64,/',
                        $faceImage,
                        $matches
                    )
                ) {
                    throw new Exception(
                        'Invalid face image data.'
                    );
                }


                $imageType = strtolower(
                    $matches[1]
                );


                $base64Image = preg_replace(
                    '/^data:image\/(jpeg|jpg|png);base64,/',
                    '',
                    $faceImage
                );


                $imageBinary =
                    base64_decode(
                        $base64Image,
                        true
                    );


                if ($imageBinary === false) {
                    throw new Exception(
                        'Unable to decode face image.'
                    );
                }
            }


            /* =============================================
               SAVE OPTIONAL PREVIEW IMAGE
            ============================================= */

            $facePath = null;

            if ($imageBinary !== null) {

                $faceDirectory =
                    __DIR__ .
                    '/uploads/employee_biometrics/';


                if (
                    !is_dir(
                        $faceDirectory
                    )
                ) {
                    if (
                        !mkdir(
                            $faceDirectory,
                            0755,
                            true
                        )
                    ) {
                        throw new Exception(
                            'Unable to create biometric upload directory.'
                        );
                    }
                }


                $faceFileName =
                    'EMP-' .
                    $employeeId .
                    '-FACE-' .
                    bin2hex(
                        random_bytes(5)
                    ) .
                    '.jpg';


                $faceDestination =
                    $faceDirectory .
                    $faceFileName;


                if (
                    file_put_contents(
                        $faceDestination,
                        $imageBinary
                    ) === false
                ) {
                    throw new Exception(
                        'Failed to save employee face image.'
                    );
                }


                $createdFiles[] =
                    $faceDestination;


                $facePath =
                    'uploads/employee_biometrics/' .
                    $faceFileName;
            }


            /* =============================================
               CHECK EXISTING BIOMETRIC
            ============================================= */

            $stmt = mysqli_prepare($conn, "
                SELECT biometric_id
                FROM employee_biometrics
                WHERE employee_id = ?
                  AND biometric_type = 'Face'
                  AND company_id = ?
                LIMIT 1
            ");

            mysqli_stmt_bind_param(
                $stmt,
                "ii",
                $employeeId,
                $companyId
            );

            mysqli_stmt_execute($stmt);

            $result =
                mysqli_stmt_get_result($stmt);

            $existingBiometric =
                mysqli_fetch_assoc($result);

            mysqli_stmt_close($stmt);


            $capturedAngles = 1;
            $biometricStatus = 'Captured';
            $capturedAt = date(
                'Y-m-d H:i:s'
            );


            if ($existingBiometric) {

                $biometricId =
                    (int) $existingBiometric[
                        'biometric_id'
                    ];


                $stmt = mysqli_prepare($conn, "
                    UPDATE employee_biometrics
                    SET
                        biometric_type = 'Face',
                        front_image = ?,
                        face_descriptor = ?,
                        captured_angles = ?,
                        status = ?,
                        captured_by = ?,
                        captured_at = ?
                    WHERE biometric_id = ?
                ");

                mysqli_stmt_bind_param(
                    $stmt,
                    "ssisisi",
                    $facePath,
                    $faceDescriptor,
                    $capturedAngles,
                    $biometricStatus,
                    $newUserId,
                    $capturedAt,
                    $biometricId
                );

                mysqli_stmt_execute($stmt);

                mysqli_stmt_close($stmt);

            } else {

                $stmt = mysqli_prepare($conn, "
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
                    VALUES
                    (?, ?, 'Face', ?, ?, ?, ?, ?, ?)
                ");

                /*
                | company_id was added to the column list for tenant isolation
                | but not to VALUES or the bind, leaving nine columns for eight
                | values: "Column count doesn't match value count at row 1".
                */
                mysqli_stmt_bind_param(
                    $stmt,
                    "iissisis",
                    $companyId,
                    $employeeId,
                    $facePath,
                    $faceDescriptor,
                    $capturedAngles,
                    $biometricStatus,
                    $newUserId,
                    $capturedAt
                );

                mysqli_stmt_execute($stmt);

                mysqli_stmt_close($stmt);
            }

        } else {

            /* =============================================
               NO FACE CAPTURED
            ============================================= */

            $stmt = mysqli_prepare($conn, "
                SELECT biometric_id
                FROM employee_biometrics
                WHERE employee_id = ?
                  AND biometric_type = 'Face'
                  AND company_id = ?
                LIMIT 1
            ");

            mysqli_stmt_bind_param(
                $stmt,
                "ii",
                $employeeId,
                $companyId
            );

            mysqli_stmt_execute($stmt);

            $result =
                mysqli_stmt_get_result($stmt);

            $existingBiometric =
                mysqli_fetch_assoc($result);

            mysqli_stmt_close($stmt);


            if (!$existingBiometric) {

                $stmt = mysqli_prepare($conn, "
                    INSERT INTO employee_biometrics
                    (
                        company_id,
                        employee_id,
                        biometric_type,
                        captured_angles,
                        status
                    )
                    VALUES
                    (?, ?, 'Face', 0, 'Pending')
                ");

                mysqli_stmt_bind_param(
                    $stmt,
                    "ii",
                    $companyId,
                    $employeeId
                );

                mysqli_stmt_execute($stmt);

                mysqli_stmt_close($stmt);
            }
        }


        /* =====================================================
           17. CREATE EMPLOYEE CONTRACT
           ===================================================== */

        $contractNumber =
            'CON-' .
            date('Ymd') .
            '-' .
            str_pad(
                $employeeId,
                5,
                '0',
                STR_PAD_LEFT
            );


        /*
         * Make sure generated contract number
         * is unique.
         */

        $stmt = mysqli_prepare($conn, "
            SELECT contract_id
            FROM employee_contracts
            WHERE contract_number = ? AND company_id = ?
            LIMIT 1
        ");

        mysqli_stmt_bind_param(
            $stmt,
            "si",
            $contractNumber,
            $companyId
        );

        mysqli_stmt_execute($stmt);

        $result =
            mysqli_stmt_get_result($stmt);

        $existingContract =
            mysqli_fetch_assoc($result);

        mysqli_stmt_close($stmt);


        if ($existingContract) {

            $contractNumber =
                'CON-' .
                date('Ymd') .
                '-' .
                $employeeId .
                '-' .
                bin2hex(
                    random_bytes(3)
                );
        }


        $contractTitle =
            'Employment Contract - ' .
            $fullname;


        /* =============================================
           GET CONTRACT FILE
        ============================================= */

        $companyContract = null;


        $stmt = mysqli_prepare($conn, "
            SELECT file_path
            FROM employee_documents
            WHERE employee_id = ?
              AND document_name = 'Employment Contract'
            LIMIT 1
        ");

        mysqli_stmt_bind_param(
            $stmt,
            "i",
            $employeeId
        );

        mysqli_stmt_execute($stmt);

        $result =
            mysqli_stmt_get_result($stmt);

        $contractDocument =
            mysqli_fetch_assoc($result);

        mysqli_stmt_close($stmt);


        if (
            $contractDocument
            && !empty(
            $contractDocument['file_path']
        )
        ) {
            $companyContract =
                $contractDocument['file_path'];
        }


        /* =============================================
           CHECK EXISTING CONTRACT
        ============================================= */

        $stmt = mysqli_prepare($conn, "
            SELECT contract_id
            FROM employee_contracts
            WHERE employee_id = ? AND company_id = ?
            LIMIT 1
        ");

        mysqli_stmt_bind_param(
            $stmt,
            "ii",
            $employeeId,
            $companyId
        );

        mysqli_stmt_execute($stmt);

        $result =
            mysqli_stmt_get_result($stmt);

        $existingContractRecord =
            mysqli_fetch_assoc($result);

        mysqli_stmt_close($stmt);


        if ($existingContractRecord) {

            $contractId =
                (int) $existingContractRecord[
                    'contract_id'
                ];


            $stmt = mysqli_prepare($conn, "
                UPDATE employee_contracts
                SET
                    contract_number = ?,
                    contract_title = ?,
                    company_contract = ?,
                    status = 'Pending',
                    uploaded_by = ?,
                    hr_review = 'Pending'
                WHERE contract_id = ?
            ");

            mysqli_stmt_bind_param(
                $stmt,
                "sssii",
                $contractNumber,
                $contractTitle,
                $companyContract,
                $newUserId,
                $contractId
            );

            mysqli_stmt_execute($stmt);

            mysqli_stmt_close($stmt);

        } else {

            $stmt = mysqli_prepare($conn, "
                INSERT INTO employee_contracts
                (
                    company_id,
                    employee_id,
                    contract_number,
                    contract_title,
                    company_contract,
                    status,
                    uploaded_by,
                    hr_review
                )
                VALUES
                (?, ?, ?, ?, ?, 'Pending', ?, 'Pending')
            ");

            /*
            | employee_contracts.company_id is NOT NULL with a foreign key to
            | company, and this insert never supplied it. It was the next
            | statement in line to fail once the biometrics insert was fixed.
            */
            mysqli_stmt_bind_param(
                $stmt,
                "iisssi",
                $companyId,
                $employeeId,
                $contractNumber,
                $contractTitle,
                $companyContract,
                $newUserId
            );

            mysqli_stmt_execute($stmt);

            mysqli_stmt_close($stmt);
        }


        /* =====================================================
           18. COMMIT EVERYTHING
           ===================================================== */

        mysqli_commit($conn);


        /* =====================================================
           19. SUCCESS
           ===================================================== */

        echo "
        <script>
        document.addEventListener('DOMContentLoaded', function() {

            Swal.fire({
                icon: 'success',
                title: 'Registration Complete',
                html: `
                    <div class=\"text-center\">
                        <p class=\"mb-2\">
                            <strong>"
            . htmlspecialchars($fullname)
            . "</strong>
                            has been successfully registered.
                        </p>

                        <p class=\"text-muted small mb-0\">
                            Employee status: Official Employee
                        </p>
                    </div>
                `,
                confirmButtonText: 'Done',
                confirmButtonColor: '#00224c',
                allowOutsideClick: false
            }).then(function() {

                window.location.href =
                    'employee_registration.php';

            });

        });
        </script>
        ";

        exit;


    } catch (Throwable $e) {

        /* =====================================================
           ROLLBACK DATABASE
        ===================================================== */

        try {
            mysqli_rollback($conn);
        } catch (Throwable $rollbackError) {
            // Ignore rollback errors
        }


        /* =====================================================
           REMOVE FILES CREATED DURING FAILED TRANSACTION
        ===================================================== */

        foreach (
            $createdFiles as $createdFile
        ) {

            if (
                is_string($createdFile)
                && file_exists($createdFile)
            ) {
                @unlink($createdFile);
            }
        }


        /* =====================================================
           ERROR MESSAGE
        ===================================================== */

        $errorMessage =
            $e->getMessage();


        echo "
        <script>
        document.addEventListener('DOMContentLoaded', function() {

            Swal.fire({
                icon: 'error',
                title: 'Registration Failed',
                text: "
            . json_encode($errorMessage)
            . ",
                confirmButtonText: 'OK',
                confirmButtonColor: '#00224c'
            });

        });
        </script>
        ";

        exit;
    }
}


$selectedEmployee = null;
$application = null;

$selectedEmployeeId = isset($_GET['employee_id'])
    ? (int) $_GET['employee_id']
    : 0;

$step = isset($_GET['step'])
    ? (int) $_GET['step']
    : 1;

if ($step < 1 || $step > 7) {
    $step = 1;
}


/* =========================================================
   LOAD EMPLOYEE + JOB POSTING + BRANCH
========================================================= */

if ($selectedEmployeeId > 0) {

    $stmt = mysqli_prepare($conn, "
        SELECT
            e.employee_id,
            e.employee_code,
            e.application_id,
            e.first_name,
            e.middle_name,
            e.last_name,
            e.suffix,
            e.email,
            e.phone,
            e.branch_id AS employee_branch_id,
            e.job_id AS employee_job_id,
            e.employment_status,

            j.job_id,
            j.job_title,
            j.department,
            j.employment_type AS job_employment_type,
            j.branch_id AS job_branch_id,

            b.branch_id,
            b.branch_name,
            b.operating_hours,
            b.opening_time,
            b.closing_time,

            a.resume

        FROM employees e

        INNER JOIN job j
            ON e.job_id = j.job_id

        INNER JOIN branch b
            ON j.branch_id = b.branch_id

        LEFT JOIN applications a
            ON e.application_id = a.application_id

        WHERE e.employee_id = ?
          AND e.company_id = ?
          AND e.employment_status = 'Pre-Employee'

        LIMIT 1
    ");

    mysqli_stmt_bind_param(
        $stmt,
        "ii",
        $selectedEmployeeId,
        $companyId
    );

    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);

    $selectedEmployee = mysqli_fetch_assoc($result);

    mysqli_stmt_close($stmt);

    /* =========================================================
   LOAD APPLICATION VERIFICATION
    ========================================================= */

    if ($selectedEmployee && !empty($selectedEmployee['application_id'])) {

        $stmt = mysqli_prepare($conn, "
            SELECT
                application_id,
                status,
                email_verified,
                verified_at
            FROM applications
            WHERE application_id = ? AND company_id = ?
            LIMIT 1
        ");

        mysqli_stmt_bind_param(
            $stmt,
            "ii",
            $selectedEmployee['application_id'],
            $companyId
        );

        mysqli_stmt_execute($stmt);

        $result = mysqli_stmt_get_result($stmt);

        $application = mysqli_fetch_assoc($result);

        mysqli_stmt_close($stmt);
    }
}

/*
|--------------------------------------------------------------------------
| LOAD PRE-EMPLOYEES
|--------------------------------------------------------------------------
*/

$preEmployees = [];

$query = mysqli_query($conn, "
    SELECT
        e.employee_id,
        e.employee_code,
        e.application_id,
        e.first_name,
        e.middle_name,
        e.last_name,
        e.suffix,
        e.email,
        e.phone,
        e.branch_id AS employee_branch_id,
        e.job_id AS employee_job_id,
        e.employment_status,

        j.job_id,
        j.job_title,
        j.department,
        j.employment_type AS job_employment_type,
        j.branch_id AS job_branch_id,

        b.branch_id,
        b.branch_name,
        b.operating_hours,
        b.opening_time,
        b.closing_time,

        hr.recommended_at AS approved_at,

        a.resume

    FROM employees e

    LEFT JOIN job j
        ON e.job_id = j.job_id

    LEFT JOIN branch b
        ON e.branch_id = b.branch_id

    LEFT JOIN hiring_recommendations hr
        ON e.application_id = hr.application_id

    LEFT JOIN applications a
        ON e.application_id = a.application_id

    WHERE e.employment_status = 'Pre-Employee'
      AND e.company_id = " . (int) $companyId . "

    ORDER BY hr.recommended_at DESC, e.employee_id DESC
");

while ($row = mysqli_fetch_assoc($query)) {
    $preEmployees[] = $row;
}

?>
<style>
    .nav-pills .nav-link {

        color: #00224c;
        border: 1px solid #00224c;
        margin-right: 8px;
    }

    .nav-pills .nav-link.active {

        background: #00224c;
        border-color: #00224c;
    }

    .card {

        border-radius: 15px;
    }

    .table th {

        white-space: nowrap;
    }

    /* Employee list scrolling */
    .pe-employee-scroll {
        max-height: 500px;
        overflow-y: auto;
        overflow-x: hidden;
        padding: 2px 6px 8px 2px;
    }

    /* Clean scrollbar */
    .pe-employee-scroll::-webkit-scrollbar {
        width: 6px;
    }

    .pe-employee-scroll::-webkit-scrollbar-track {
        background: #f1f5f9;
        border-radius: 10px;
    }

    .pe-employee-scroll::-webkit-scrollbar-thumb {
        background: #cbd5e1;
        border-radius: 10px;
    }

    /* Hover */
    .pe-employee-list .card:hover {
        border-color: #00224c !important;
        transform: translateY(-1px);
        box-shadow: 0 5px 14px rgba(0, 34, 76, .10) !important;
    }


    /* Mobile */
    @media (max-width: 767.98px) {

        .pe-employee-scroll {
            max-height: 600px;
        }

        .pe-employee-list .card {
            min-height: 72px !important;
        }

        .pe-employee-list .badge {
            font-size: 9px !important;
            padding: 4px 7px !important;
        }

    }

    /* =========================================================
   REGISTRATION STEPS
========================================================= */

    .registration-steps {
        width: 100%;
    }

    .registration-step {
        position: relative;
        text-align: center;
        cursor: pointer;

        transition:
            transform .25s ease,
            color .25s ease;
    }


    /* =========================
   STEP CIRCLE
========================= */

    .step-circle {
        width: 42px;
        height: 42px;

        margin: 0 auto;

        border-radius: 50%;

        display: flex;
        align-items: center;
        justify-content: center;

        background: #f1f5f9;
        color: #64748b;

        font-size: 14px;
        font-weight: 700;

        transition:
            all .25s ease;
    }


    /* =========================
   STEP LABEL
========================= */

    .step-label {
        margin-top: 9px;

        color: #64748b;

        font-size: 14px;
        font-weight: 400;

        transition:
            all .25s ease;
    }


    /* =========================
   HOVER
========================= */

    .registration-step:hover {
        transform: translateY(-5px);
    }

    .registration-step:hover .step-circle {
        transform: scale(1.08);

        background: #e8eef6;
        color: #00224c;

        box-shadow:
            0 6px 15px rgba(0, 34, 76, .12);
    }

    .registration-step:hover .step-label {
        color: #00224c;
        font-weight: 600;
    }


    /* =========================
   ACTIVE STEP
========================= */

    .registration-step.active {
        transform: translateY(-2px);
    }

    .registration-step.active .step-circle {
        background: #00224c;
        color: #ffffff;

        box-shadow:
            0 6px 15px rgba(0, 34, 76, .20);
    }

    .registration-step.active .step-label {
        color: #00224c;
        font-weight: 600;
    }


    /* =========================
   COMPLETED STEP
========================= */

    .registration-step.completed .step-circle {
        background: #00224c;
        color: #ffffff;
    }

    .registration-step.completed .step-label {
        color: #00224c;
        font-weight: 500;
    }


    /* =========================
   COMPLETED HOVER
========================= */

    .registration-step.completed:hover .step-circle {
        transform: scale(1.08);
    }


    /* =========================
   MOBILE
========================= */

    @media (max-width: 767.98px) {

        .step-circle {
            width: 36px;
            height: 36px;

            font-size: 12px;
        }

        .step-label {
            font-size: 11px;
        }

    }
</style>
<div class="container-fluid py-1">

    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-2">
        <div>
            <h2 class="fw-bold mb-1" style="color: #00224c;">
                Employee Registration
            </h2>

            <p class="text-muted mb-0">
                Select an approved applicant to convert into an official employee record.
            </p>
        </div>
    </div>
    <!-- =========================================================
        PRE-EMPLOYEE LIST
    ========================================================= -->
    <?php if (!$selectedEmployee): ?>
        <div class="pe-employee-list pe-employee-scroll">
            <div class="row g-2">
                <?php foreach ($preEmployees as $employee): ?>
                    <?php
                    $firstName = $employee['first_name'] ?? '';
                    $lastName = $employee['last_name'] ?? '';

                    $fullName = trim(
                        $firstName . ' ' . $lastName
                    );

                    $initials = strtoupper(
                        substr($firstName, 0, 1) .
                        substr($lastName, 0, 1)
                    );

                    ?>

                    <div class="col-12 col-md-6">

                        <a href="?employee_id=<?= (int) $employee['employee_id'] ?>" class="text-decoration-none d-block">

                            <div class="card border shadow-sm rounded-3 h-100" style="
                                min-height:78px;
                                transition:all .15s ease;
                            ">

                                <div class="card-body py-2 px-3">

                                    <div class="d-flex align-items-center h-100">

                                        <!-- Avatar -->
                                        <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0"
                                            style="
                                            width:44px;
                                            height:44px;
                                            background:#00224c;
                                            color:#ffffff;
                                            font-size:14px;
                                            font-weight:700;
                                        ">
                                            <?= htmlspecialchars($initials) ?>
                                        </div>


                                        <!-- Employee Details -->
                                        <div class="ms-3 flex-grow-1" style="min-width:0;">

                                            <!-- Name -->
                                            <div class="fw-bold text-dark text-truncate" style="font-size:15px;">
                                                <?= htmlspecialchars($fullName) ?>
                                            </div>


                                            <!-- Position / Branch / Approved -->
                                            <div class="text-muted text-truncate" style="
                                                font-size:12px;
                                                line-height:1.4;
                                            ">

                                                <?= htmlspecialchars(
                                                    $employee['job_title']
                                                    ?? 'No Position'
                                                ) ?>

                                                &middot;

                                                <?= htmlspecialchars(
                                                    $employee['branch_name']
                                                    ?? 'No Branch'
                                                ) ?>

                                                <?php if (!empty($employee['approved_at'])): ?>

                                                    &middot; approved

                                                    <?= date(
                                                        'Y-m-d',
                                                        strtotime(
                                                            $employee['approved_at']
                                                        )
                                                    ) ?>

                                                <?php endif; ?>

                                            </div>

                                        </div>


                                        <!-- Status -->
                                        <span class="badge rounded-pill flex-shrink-0 ms-2" style="
                                            background:#fff7df;
                                            color:#a16207;
                                            border:1px solid #f4cf72;
                                            font-size:10px;
                                            font-weight:600;
                                            padding:5px 9px;
                                        ">
                                            Pre-Employee
                                        </span>

                                    </div>

                                </div>

                            </div>

                        </a>

                    </div>

                <?php endforeach; ?>

            </div>

        </div>

    <?php endif; ?>

    <?php if ($selectedEmployee): ?>

        <?php
        $firstName = $selectedEmployee['first_name'] ?? '';
        $lastName = $selectedEmployee['last_name'] ?? '';

        $fullName = trim(
            $firstName . ' ' . $lastName
        );

        $initials = strtoupper(
            substr($firstName, 0, 1) .
            substr($lastName, 0, 1)
        );
        ?>


        <!-- =========================================
            ONBOARDING HEADER
        ========================================== -->

        <div class="d-flex align-items-center mb-4">

            <a href="employee_registration.php" class="btn btn-light border rounded-3 me-3">
                <i class="bi bi-arrow-left"></i>
            </a>

            <div>

                <h4 class="fw-bold mb-1" style="color:#00224c;">
                    Employee Registration
                </h4>

                <p class="text-muted mb-0 small">
                    Complete the employee onboarding process.
                </p>

            </div>

        </div>


        <!-- =========================================
            EMPLOYEE HEADER CARD
        ========================================== -->

        <div class="card border-0 shadow-sm rounded-4 mb-3">

            <div class="card-body p-4">

                <div class="d-flex align-items-center">

                    <!-- Avatar -->

                    <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="
                        width:50px;
                        height:50px;
                        background:#00224c;
                        color:white;
                        font-size:15px;
                        font-weight:700;
                    ">
                        <?= htmlspecialchars($initials) ?>
                    </div>


                    <!-- Employee -->

                    <div class="ms-3">

                        <h5 class="fw-bold mb-1" style="color:#00224c;">
                            <?= htmlspecialchars($fullName) ?>
                        </h5>

                        <div class="text-muted small">

                            <?= htmlspecialchars(
                                $selectedEmployee['job_title']
                                ?? 'No Position'
                            ) ?>

                            &middot;

                            <?= htmlspecialchars(
                                $selectedEmployee['branch_name']
                                ?? 'No Branch'
                            ) ?>

                        </div>

                    </div>


                    <!-- Status -->

                    <span class="badge rounded-pill ms-auto" style="
                        background:#fff7df;
                        color:#a16207;
                        border:1px solid #f4cf72;
                        padding:7px 12px;
                    ">
                        Pre-Employee
                    </span>

                </div>

            </div>

        </div>


        <!-- =========================================
            REGISTRATION STEPS
        ========================================== -->

        <div class="card border-0 shadow-sm rounded-4">

            <div class="card-body p-4">

                <!-- Step Navigation -->

                <div class="row g-2 mb-4">

                    <?php
                    $steps = [
                        1 => 'Applicant Info',
                        2 => 'Employment Setup',
                        3 => 'Government IDs',
                        4 => 'Documents',
                        5 => 'Face Registration',
                        6 => 'Employee Account',
                        7 => 'Review & Complete'
                    ];
                    ?>

                    <div class="row g-2 mb-4 registration-steps">

                        <?php foreach ($steps as $number => $label): ?>

                            <?php
                            $isActive = ($number === $step);
                            $isCompleted = ($number < $step);
                            ?>

                            <div class="col">

                                <div class="registration-step
                <?= $isActive ? 'active' : '' ?>
                <?= $isCompleted ? 'completed' : '' ?>" data-step="<?= $number ?>">

                                    <div class="step-circle">

                                        <?php if ($isCompleted): ?>

                                            <i class="bi bi-check"></i>

                                        <?php else: ?>

                                            <?= $number ?>

                                        <?php endif; ?>

                                    </div>

                                    <div class="step-label">
                                        <?= htmlspecialchars($label) ?>
                                    </div>

                                </div>

                            </div>

                        <?php endforeach; ?>

                    </div>

                </div>
                <form id="employeeRegistrationForm" method="POST" enctype="multipart/form-data" action="">
                    <input type="hidden" name="employee_id" value="<?= (int) $selectedEmployeeId ?>">
                    <input type="hidden" name="complete_registration" value="1">

                    <div id="registrationStep1" class="registration-content-step <?= $step != 1 ? 'd-none' : '' ?>">
                        <!-- =========================================
                            STEP 1 — APPLICANT INFORMATION
                        ========================================== -->

                        <div class="border rounded-4 p-4" style="background:#fff;">

                            <div class="mb-4">

                                <span class="small fw-bold" style="color:#00224c;">
                                    STEP 1 OF 7
                                </span>

                                <h5 class="fw-bold mt-1 mb-1" style="color:#00224c;">
                                    Applicant Information
                                </h5>

                                <p class="text-muted small mb-0">
                                    Review the approved applicant information.
                                </p>

                            </div>


                            <div class="row g-3">

                                <!-- Full Name -->
                                <div class="col-md-6">

                                    <label class="form-label small text-muted">
                                        Full Name
                                    </label>

                                    <input type="text" class="form-control" value="<?= htmlspecialchars($fullName) ?>"
                                        readonly>

                                </div>


                                <!-- Email -->
                                <div class="col-md-6">

                                    <label class="form-label small text-muted">
                                        Email
                                    </label>

                                    <input type="text" class="form-control" value="<?= htmlspecialchars(
                                        $selectedEmployee['email'] ?? '-'
                                    ) ?>" readonly>

                                </div>


                                <!-- Contact -->
                                <div class="col-md-6">

                                    <label class="form-label small text-muted">
                                        Contact
                                    </label>

                                    <input type="text" class="form-control" value="<?= htmlspecialchars(
                                        $selectedEmployee['phone'] ?? '-'
                                    ) ?>" readonly>

                                </div>


                                <!-- Position -->
                                <div class="col-md-6">

                                    <label class="form-label small text-muted">
                                        Applied Position
                                    </label>

                                    <input type="text" class="form-control" value="<?= htmlspecialchars(
                                        $selectedEmployee['job_title'] ?? '-'
                                    ) ?>" readonly>

                                </div>

                            </div>


                            <!-- Continue -->
                            <div class="d-flex justify-content-end mt-4">

                                <button type="button" class="btn text-white px-4 rounded-3" style="background:#00224c;"
                                    onclick="goToStep(2)">

                                    Continue

                                    <i class="bi bi-arrow-right ms-1"></i>

                                </button>

                            </div>

                        </div>

                    </div>
                    <div id="registrationStep2" class="registration-content-step <?= $step != 2 ? 'd-none' : '' ?>">

                        <!-- =========================================================
                            STEP 2 — EMPLOYMENT SETUP
                        ========================================================= -->

                        <div class="employment-setup-wrapper">

                            <div class="row g-4">

                                <!-- =====================================================
                                LEFT — ASSIGNMENT
                            ====================================================== -->

                                <div class="col-xl-6">

                                    <div class="card border shadow-sm rounded-4 h-100">

                                        <!-- HEADER -->
                                        <div class="card-header bg-white border-bottom py-3 px-4">

                                            <h6 class="fw-bold mb-0" style="color:#1e293b;">
                                                Assignment
                                            </h6>

                                        </div>


                                        <!-- BODY -->
                                        <div class="card-body p-4">

                                            <div class="row g-3">

                                                <!-- BRANCH -->
                                                <div class="col-md-6">

                                                    <label class="form-label small fw-semibold text-secondary">
                                                        Branch
                                                    </label>

                                                    <input type="text" class="form-control bg-light" value="<?= htmlspecialchars(
                                                        $selectedEmployee['branch_name'] ?? '-'
                                                    ) ?>" readonly>

                                                    <input type="hidden" name="branch_id" value="<?= (int) (
                                                        $selectedEmployee['branch_id']
                                                        ?? $selectedEmployee['employee_branch_id']
                                                        ?? 0
                                                    ) ?>">

                                                </div>


                                                <!-- DEPARTMENT -->
                                                <div class="col-md-6">

                                                    <label class="form-label small fw-semibold text-secondary">
                                                        Department
                                                    </label>

                                                    <input type="text" class="form-control bg-light" value="<?= htmlspecialchars(
                                                        $selectedEmployee['department'] ?? '-'
                                                    ) ?>" readonly>

                                                    <input type="hidden" name="department" value="<?= htmlspecialchars(
                                                        $selectedEmployee['department'] ?? ''
                                                    ) ?>">

                                                </div>


                                                <!-- POSITION -->
                                                <div class="col-md-6">

                                                    <label class="form-label small fw-semibold text-secondary">
                                                        Position
                                                    </label>

                                                    <input type="text" class="form-control bg-light" value="<?= htmlspecialchars(
                                                        $selectedEmployee['job_title'] ?? '-'
                                                    ) ?>" readonly>

                                                    <input type="hidden" name="job_id" value="<?= (int) (
                                                        $selectedEmployee['job_id']
                                                        ?? $selectedEmployee['employee_job_id']
                                                        ?? 0
                                                    ) ?>">

                                                </div>


                                                <!-- JOB LEVEL -->
                                                <div class="col-md-6">

                                                    <label class="form-label small fw-semibold text-secondary">
                                                        Job Level
                                                    </label>

                                                    <select name="job_level" id="jobLevel" class="form-select">

                                                        <option value="">
                                                            Select Job Level
                                                        </option>

                                                        <option value="Rank & File">
                                                            Rank & File
                                                        </option>

                                                        <option value="Senior Staff">
                                                            Senior Staff
                                                        </option>

                                                        <option value="Supervisor">
                                                            Supervisor
                                                        </option>

                                                        <option value="Manager">
                                                            Manager
                                                        </option>

                                                    </select>

                                                </div>


                                                <!-- IMMEDIATE SUPERVISOR -->
                                                <div class="col-md-6">

                                                    <label class="form-label small fw-semibold text-secondary">
                                                        Immediate Supervisor
                                                    </label>

                                                    <select name="supervisor_id" id="supervisorId" class="form-select">

                                                        <option value="">
                                                            Select Supervisor
                                                        </option>

                                                        <?php

                                                        /*
                                                        | Scoped to this company. Without the
                                                        | company_id condition the list held every
                                                        | active account on the platform -- other
                                                        | businesses' staff, and the Super Admin,
                                                        | all offered as supervisors here.
                                                        */
                                                        $supervisors = mysqli_query(
                                                            $conn,
                                                            "
                                                            SELECT
                                                                user_id,
                                                                fullname
                                                            FROM users
                                                            WHERE status = 'active'
                                                              AND company_id = " . (int) $companyId . "
                                                            ORDER BY fullname ASC
                                                            "
                                                        );

                                                        while (
                                                            $supervisor = mysqli_fetch_assoc(
                                                                $supervisors
                                                            )
                                                        ):
                                                            ?>

                                                            <option value="<?= (int) $supervisor['user_id'] ?>">

                                                                <?= htmlspecialchars(
                                                                    $supervisor['fullname']
                                                                ) ?>

                                                            </option>

                                                        <?php endwhile; ?>

                                                    </select>

                                                </div>


                                                <!-- EMPLOYMENT TYPE -->
                                                <div class="col-md-6">

                                                    <label class="form-label small fw-semibold text-secondary">
                                                        Employment Type
                                                    </label>

                                                    <input type="text" class="form-control bg-light" value="<?= htmlspecialchars(
                                                        $selectedEmployee['job_employment_type']
                                                        ?? '-'
                                                    ) ?>" readonly>

                                                    <input type="hidden" name="employment_type" id="employmentType" value="<?= htmlspecialchars(
                                                        $selectedEmployee['job_employment_type']
                                                        ?? ''
                                                    ) ?>">

                                                </div>

                                            </div>

                                        </div>

                                    </div>

                                </div>


                                <!-- =====================================================
                            RIGHT — SCHEDULE & SALARY
                        ====================================================== -->

                                <div class="col-xl-6">

                                    <div class="card border shadow-sm rounded-4 h-100">

                                        <!-- HEADER -->
                                        <div class="card-header bg-white border-bottom py-3 px-4">

                                            <h6 class="fw-bold mb-0" style="color:#1e293b;">
                                                Schedule & Salary
                                            </h6>

                                        </div>


                                        <!-- BODY -->
                                        <div class="card-body p-4">

                                            <div class="row g-3">

                                                <!-- SHIFT -->
                                                <div class="col-md-6">

                                                    <label class="form-label small fw-semibold text-secondary">
                                                        Shift
                                                    </label>

                                                    <select name="shift" id="shift" class="form-select">

                                                        <option value="">
                                                            Select Shift
                                                        </option>

                                                        <option value="Morning Shift">
                                                            Morning Shift
                                                        </option>

                                                        <option value="Mid Shift">
                                                            Mid Shift
                                                        </option>

                                                        <option value="Closing Shift">
                                                            Closing Shift
                                                        </option>

                                                        <option value="Graveyard">
                                                            Graveyard
                                                        </option>

                                                    </select>

                                                    <div id="shiftHelp" class="form-text">

                                                        Shift selection is available for applicable part-time employees.

                                                    </div>

                                                </div>


                                                <!-- WORKING DAYS -->
                                                <div class="col-md-6">

                                                    <label class="form-label small fw-semibold text-secondary">
                                                        Working Days
                                                    </label>

                                                    <input type="text" name="working_days" id="workingDays"
                                                        class="form-control" placeholder="Example: Mon-Sat">

                                                </div>


                                                <!-- TIME IN -->
                                                <div class="col-md-6">

                                                    <label class="form-label small fw-semibold text-secondary">
                                                        Time In
                                                    </label>

                                                    <input type="time" name="time_in" id="timeIn"
                                                        class="form-control bg-light" value="<?= htmlspecialchars(
                                                            $selectedEmployee['opening_time']
                                                            ?? ''
                                                        ) ?>" readonly>

                                                    <div class="form-text">
                                                        Based on branch operating hours.
                                                    </div>

                                                </div>


                                                <!-- TIME OUT -->
                                                <div class="col-md-6">

                                                    <label class="form-label small fw-semibold text-secondary">
                                                        Time Out
                                                    </label>

                                                    <input type="time" name="time_out" id="timeOut"
                                                        class="form-control bg-light" value="<?= htmlspecialchars(
                                                            $selectedEmployee['closing_time']
                                                            ?? ''
                                                        ) ?>" readonly>

                                                    <div class="form-text">
                                                        Based on branch operating hours.
                                                    </div>

                                                </div>


                                                <!-- BREAK -->
                                                <div class="col-md-6">

                                                    <label class="form-label small fw-semibold text-secondary">
                                                        Break
                                                    </label>

                                                    <input type="text" class="form-control bg-light" value="1 Hour"
                                                        readonly>

                                                    <input type="hidden" name="break_duration" value="60">

                                                    <div class="form-text">
                                                        Standard break duration.
                                                    </div>

                                                </div>


                                                <!-- REST DAY -->
                                                <div class="col-md-6">

                                                    <label class="form-label small fw-semibold text-secondary">
                                                        Rest Day
                                                    </label>

                                                    <select name="rest_day" id="restDay" class="form-select">

                                                        <option value="">
                                                            Select Rest Day
                                                        </option>

                                                        <option value="Monday">
                                                            Monday
                                                        </option>

                                                        <option value="Tuesday">
                                                            Tuesday
                                                        </option>

                                                        <option value="Wednesday">
                                                            Wednesday
                                                        </option>

                                                        <option value="Thursday">
                                                            Thursday
                                                        </option>

                                                        <option value="Friday">
                                                            Friday
                                                        </option>

                                                        <option value="Saturday">
                                                            Saturday
                                                        </option>

                                                        <option value="Sunday">
                                                            Sunday
                                                        </option>

                                                    </select>

                                                </div>


                                                <!-- OFFICIAL START DATE -->
                                                <div class="col-md-6">

                                                    <label class="form-label small fw-semibold text-secondary">
                                                        Official Start Date
                                                    </label>

                                                    <input type="date" name="official_start_date" id="officialStartDate"
                                                        class="form-control">

                                                </div>


                                                <!-- BASIC SALARY -->
                                                <div class="col-md-6">

                                                    <label class="form-label small fw-semibold text-secondary">
                                                        Basic Salary (₱)
                                                    </label>

                                                    <input type="number" name="salary" id="salary" class="form-control"
                                                        min="0" step="0.01" placeholder="Enter salary">

                                                </div>


                                                <!-- SALARY TYPE -->
                                                <div class="col-md-6">

                                                    <label class="form-label small fw-semibold text-secondary">
                                                        Salary Type
                                                    </label>

                                                    <select name="salary_type" id="salaryType" class="form-select">

                                                        <option value="">
                                                            Select Salary Type
                                                        </option>

                                                        <option value="Monthly">
                                                            Monthly
                                                        </option>

                                                        <option value="Daily">
                                                            Daily
                                                        </option>

                                                        <option value="Hourly">
                                                            Hourly
                                                        </option>

                                                    </select>

                                                </div>


                                                <!-- PAY FREQUENCY -->
                                                <div class="col-md-6">

                                                    <label class="form-label small fw-semibold text-secondary">
                                                        Pay Frequency
                                                    </label>

                                                    <select name="pay_frequency" id="payFrequency" class="form-select">

                                                        <option value="">
                                                            Select Pay Frequency
                                                        </option>

                                                        <option value="Semi-Monthly">
                                                            Semi-Monthly
                                                        </option>

                                                        <option value="Weekly">
                                                            Weekly
                                                        </option>

                                                        <option value="Monthly">
                                                            Monthly
                                                        </option>

                                                    </select>

                                                </div>

                                            </div>


                                            <!-- SALARY NOTE -->
                                            <div class="alert alert-light border rounded-3 mt-4 mb-0">

                                                <div class="small text-muted">

                                                    <strong style="color:#00224c;">
                                                        Salary Reference
                                                    </strong>

                                                    <br>

                                                    Salary is a reference only. Payroll Management
                                                    performs the actual payroll computations,
                                                    deductions, taxes, and statutory contributions.

                                                </div>

                                            </div>

                                        </div>

                                    </div>

                                </div>

                            </div>


                            <!-- =====================================================
                            NAVIGATION
                        ====================================================== -->

                            <div class="d-flex justify-content-between align-items-center mt-4">

                                <!-- BACK -->
                                <button type="button" class="btn btn-light border rounded-3 px-4" onclick="goToStep(1)">

                                    <i class="bi bi-arrow-left me-1"></i>

                                    Back

                                </button>


                                <!-- CONTINUE -->
                                <button type="button" class="btn text-white px-4 rounded-3" style="background:#00224c;"
                                    onclick="validateStep2()">

                                    Continue

                                    <i class="bi bi-arrow-right ms-1"></i>
                                </button>

                            </div>

                        </div>

                    </div>
                    <div id="registrationStep3" class="registration-content-step d-none">

                        <!-- =========================================
        STEP 3 — GOVERNMENT IDENTIFICATION
    ========================================== -->

                        <div class="card border shadow-sm rounded-4">

                            <!-- HEADER -->
                            <div class="card-header bg-white border-bottom py-3 px-4">

                                <h6 class="fw-bold mb-0" style="color:#1e293b;">
                                    Government Identification
                                </h6>

                            </div>


                            <!-- BODY -->
                            <div class="card-body p-4">

                                <div class="row g-3">

                                    <!-- =====================================
                    SSS
                ====================================== -->
                                    <div class="col-md-6">

                                        <div class="border rounded-4 p-3 h-100">

                                            <div class="d-flex justify-content-between align-items-center mb-2">

                                                <label class="form-label fw-semibold mb-0">
                                                    SSS
                                                </label>

                                                <span id="sssStatus" class="badge rounded-pill bg-light text-secondary">
                                                    Missing
                                                </span>

                                            </div>

                                            <div class="d-flex gap-2">

                                                <input type="text" name="sss_number" id="sssNumber"
                                                    class="form-control bg-light" placeholder="SSS number">

                                                <button type="button" class="btn btn-light border px-3"
                                                    onclick="verifyGovernmentId('sss')">

                                                    Verify

                                                </button>

                                            </div>

                                        </div>

                                    </div>


                                    <!-- =====================================
                    PHILHEALTH
                ====================================== -->
                                    <div class="col-md-6">

                                        <div class="border rounded-4 p-3 h-100">

                                            <div class="d-flex justify-content-between align-items-center mb-2">

                                                <label class="form-label fw-semibold mb-0">
                                                    PhilHealth
                                                </label>

                                                <span id="philhealthStatus"
                                                    class="badge rounded-pill bg-light text-secondary">
                                                    Missing
                                                </span>

                                            </div>

                                            <div class="d-flex gap-2">

                                                <input type="text" name="philhealth_number" id="philhealthNumber"
                                                    class="form-control bg-light" placeholder="PhilHealth number">

                                                <button type="button" class="btn btn-light border px-3"
                                                    onclick="verifyGovernmentId('philhealth')">

                                                    Verify

                                                </button>

                                            </div>

                                        </div>

                                    </div>


                                    <!-- =====================================
                    PAG-IBIG
                ====================================== -->
                                    <div class="col-md-6">

                                        <div class="border rounded-4 p-3 h-100">

                                            <div class="d-flex justify-content-between align-items-center mb-2">

                                                <label class="form-label fw-semibold mb-0">
                                                    Pag-IBIG
                                                </label>

                                                <span id="pagibigStatus" class="badge rounded-pill bg-light text-secondary">
                                                    Missing
                                                </span>

                                            </div>

                                            <div class="d-flex gap-2">

                                                <input type="text" name="pagibig_number" id="pagibigNumber"
                                                    class="form-control bg-light" placeholder="Pag-IBIG number">

                                                <button type="button" class="btn btn-light border px-3"
                                                    onclick="verifyGovernmentId('pagibig')">

                                                    Verify

                                                </button>

                                            </div>

                                        </div>

                                    </div>


                                    <!-- =====================================
                    TIN
                ====================================== -->
                                    <div class="col-md-6">

                                        <div class="border rounded-4 p-3 h-100">

                                            <div class="d-flex justify-content-between align-items-center mb-2">

                                                <label class="form-label fw-semibold mb-0">
                                                    TIN
                                                </label>

                                                <span id="tinStatus" class="badge rounded-pill bg-light text-secondary">
                                                    Missing
                                                </span>

                                            </div>

                                            <div class="d-flex gap-2">

                                                <input type="text" name="tin_number" id="tinNumber"
                                                    class="form-control bg-light" placeholder="TIN number">

                                                <button type="button" class="btn btn-light border px-3"
                                                    onclick="verifyGovernmentId('tin')">

                                                    Verify

                                                </button>

                                            </div>

                                        </div>

                                    </div>


                                    <!-- =====================================
                    NATIONAL ID
                ====================================== -->
                                    <div class="col-md-6">

                                        <div class="border rounded-4 p-3 h-100">

                                            <div class="d-flex justify-content-between align-items-center mb-2">

                                                <label class="form-label fw-semibold mb-0">
                                                    National ID
                                                </label>

                                                <span id="nationalIdStatus"
                                                    class="badge rounded-pill bg-light text-secondary">
                                                    Missing
                                                </span>

                                            </div>

                                            <div class="d-flex gap-2">

                                                <input type="text" name="national_id_number" id="nationalIdNumber"
                                                    class="form-control bg-light" placeholder="National ID number">

                                                <button type="button" class="btn btn-light border px-3"
                                                    onclick="verifyGovernmentId('nationalId')">

                                                    Verify

                                                </button>

                                            </div>

                                        </div>

                                    </div>


                                    <!-- =====================================
                    PASSPORT
                ====================================== -->
                                    <div class="col-md-6">

                                        <div class="border rounded-4 p-3 h-100">

                                            <div class="d-flex justify-content-between align-items-center mb-2">

                                                <label class="form-label fw-semibold mb-0">
                                                    Passport
                                                </label>

                                                <span id="passportStatus"
                                                    class="badge rounded-pill bg-light text-secondary">
                                                    Missing
                                                </span>

                                            </div>

                                            <div class="d-flex gap-2">

                                                <input type="text" name="passport_number" id="passportNumber"
                                                    class="form-control bg-light" placeholder="Passport number">

                                                <button type="button" class="btn btn-light border px-3"
                                                    onclick="verifyGovernmentId('passport')">

                                                    Verify

                                                </button>

                                            </div>

                                        </div>

                                    </div>


                                    <!-- =====================================
                    DRIVER LICENSE
                ====================================== -->
                                    <div class="col-md-6">

                                        <div class="border rounded-4 p-3 h-100">

                                            <div class="d-flex justify-content-between align-items-center mb-2">

                                                <label class="form-label fw-semibold mb-0">
                                                    Driver License
                                                </label>

                                                <span id="driverLicenseStatus"
                                                    class="badge rounded-pill bg-light text-secondary">
                                                    Missing
                                                </span>

                                            </div>

                                            <div class="d-flex gap-2">

                                                <input type="text" name="driver_license_number" id="driverLicenseNumber"
                                                    class="form-control bg-light" placeholder="Driver License number">

                                                <button type="button" class="btn btn-light border px-3"
                                                    onclick="verifyGovernmentId('driverLicense')">

                                                    Verify

                                                </button>

                                            </div>

                                        </div>

                                    </div>

                                </div>


                                <!-- =========================================
                ID COUNTER
            ========================================== -->

                                <div class="mt-3">

                                    <small class="text-muted">

                                        <span id="governmentIdCount">0</span>
                                        of 7 IDs captured.

                                    </small>

                                </div>

                            </div>

                        </div>


                        <!-- =========================================
        NAVIGATION
    ========================================== -->

                        <div class="d-flex justify-content-between align-items-center mt-4">

                            <!-- BACK -->

                            <button type="button" class="btn btn-light border rounded-3 px-4" onclick="goToStep(2)">

                                <i class="bi bi-arrow-left me-1"></i>

                                Back

                            </button>


                            <!-- CONTINUE -->

                            <button type="button" class="btn text-white px-4 rounded-3" style="background:#00224c;"
                                onclick="continueGovernmentIds()">

                                Continue

                                <i class="bi bi-arrow-right ms-1"></i>

                            </button>

                        </div>

                    </div>
                    <div id="registrationStep4" class="registration-content-step d-none">

                        <!-- =========================================================
         STEP 4 — DOCUMENTS
    ========================================================== -->

                        <div class="card border shadow-sm rounded-4">

                            <!-- HEADER -->
                            <div class="card-header bg-white border-bottom py-3 px-4">

                                <div class="d-flex align-items-center justify-content-between">

                                    <div>

                                        <h6 class="fw-bold mb-1" style="color:#1e293b;">
                                            Document Verification
                                        </h6>

                                        <small class="text-muted">
                                            Upload and verify the employee's required documents.
                                        </small>

                                    </div>

                                </div>

                            </div>


                            <!-- BODY -->
                            <div class="card-body p-4">

                                <div class="row g-3">


                                    <!-- =====================================================
                     1. RESUME / CV
                     AUTOMATIC FROM APPLICATION — VIEW ONLY, NO RE-UPLOAD
                ====================================================== -->

                                    <div class="col-md-4">

                                        <div class="border rounded-4 p-3 h-100">

                                            <div class="d-flex justify-content-between align-items-center mb-3">

                                                <span class="fw-semibold">
                                                    Resume / CV
                                                </span>

                                                <?php
                                                /*
                                                | Three states, not two.
                                                |
                                                | This asked whether the column was empty and
                                                | answered both "nobody attached a CV" and "the
                                                | CV is gone" with the word Missing. Those want
                                                | different things done -- ask the applicant, or
                                                | stop looking -- and somebody spent an evening
                                                | hunting for a file that had been wiped with
                                                | the disk it sat on.
                                                */
                                                require_once __DIR__ . '/../includes/resume_file.php';

                                                $resumeState = resumeState(
                                                    $conn,
                                                    $selectedEmployee['resume'] ?? '',
                                                    dirname(__DIR__)
                                                );

                                                $resumeLink = resumePath($selectedEmployee['resume'] ?? '');
                                                ?>

                                                <?php if ($resumeState === 'stored'): ?>

                                                    <span class="badge rounded-pill bg-success-subtle text-success">
                                                        Verified
                                                    </span>

                                                <?php elseif ($resumeState === 'lost'): ?>

                                                    <span class="badge rounded-pill bg-warning-subtle text-warning-emphasis">
                                                        File lost
                                                    </span>

                                                <?php else: ?>

                                                    <span class="badge rounded-pill bg-light text-secondary">
                                                        Not attached
                                                    </span>

                                                <?php endif; ?>

                                            </div>


                                            <?php if ($resumeState === 'stored'): ?>

                                                <div class="d-flex gap-2">

                                                    <!--
                                                        Through resume_file.php, which asks which
                                                        company is reading. The column used to be
                                                        rendered straight into this href as a bare
                                                        filename, so the link resolved to /hr/ and
                                                        404ed even when the CV was on file.
                                                    -->
                                                    <a href="/resume_file.php?path=<?= urlencode($resumeLink) ?>"
                                                        target="_blank" class="btn btn-sm btn-light border">

                                                        <i class="bi bi-eye me-1"></i>
                                                        View Resume

                                                    </a>

                                                </div>

                                                <div class="form-text mb-0 mt-1">
                                                    Already on file from the applicant's application. No need to upload again.
                                                </div>

                                            <?php elseif ($resumeState === 'lost'): ?>

                                                <div class="form-text mb-0">
                                                    This applicant attached a CV, but the file is no
                                                    longer on record. Ask them to send it again and
                                                    upload it below.
                                                </div>

                                                <div class="d-flex gap-2 mt-2">

                                                    <input type="file" name="resume" class="d-none" id="resumeUpload"
                                                        accept=".pdf,.doc,.docx">

                                                    <label for="resumeUpload" class="btn btn-sm btn-light border">

                                                        <i class="bi bi-upload me-1"></i>
                                                        Upload

                                                    </label>

                                                </div>

                                            <?php else: ?>

                                                <div class="d-flex gap-2">

                                                    <input type="file" name="resume" class="d-none" id="resumeUpload"
                                                        accept=".pdf,.doc,.docx">

                                                    <label for="resumeUpload" class="btn btn-sm btn-light border">

                                                        <i class="bi bi-upload me-1"></i>
                                                        Upload

                                                    </label>

                                                </div>

                                            <?php endif; ?>

                                        </div>

                                    </div>



                                    <!-- =====================================================
                     2. BIRTH CERTIFICATE
                ====================================================== -->

                                    <div class="col-md-4">

                                        <div class="border rounded-4 p-3 h-100">

                                            <div class="d-flex justify-content-between align-items-center mb-3">

                                                <span class="fw-semibold">
                                                    Birth Certificate
                                                </span>

                                                <span id="statusBirthCertificate"
                                                    class="badge rounded-pill bg-light text-secondary">
                                                    Missing
                                                </span>

                                            </div>

                                            <div class="d-flex gap-2">

                                                <input type="file" name="birth_certificate"
                                                    class="form-control form-control-sm" accept=".pdf,.jpg,.jpeg,.png">

                                                <button type="button" class="btn btn-sm btn-success"
                                                    onclick="verifyDocument('Birth Certificate', 'statusBirthCertificate')">

                                                    Verify

                                                </button>

                                            </div>

                                        </div>

                                    </div>



                                    <!-- =====================================================
                     3. MEDICAL CERTIFICATE
                ====================================================== -->

                                    <div class="col-md-4">

                                        <div class="border rounded-4 p-3 h-100">

                                            <div class="d-flex justify-content-between align-items-center mb-3">

                                                <span class="fw-semibold">
                                                    Medical Certificate
                                                </span>

                                                <span id="statusMedicalCertificate"
                                                    class="badge rounded-pill bg-light text-secondary">
                                                    Missing
                                                </span>

                                            </div>

                                            <div class="d-flex gap-2">

                                                <input type="file" name="medical_certificate"
                                                    class="form-control form-control-sm" accept=".pdf,.jpg,.jpeg,.png">

                                                <button type="button" class="btn btn-sm btn-success"
                                                    onclick="verifyDocument('Medical Certificate', 'statusMedicalCertificate')">

                                                    Verify

                                                </button>

                                            </div>

                                        </div>

                                    </div>



                                    <!-- =====================================================
                     4. NBI CLEARANCE
                ====================================================== -->

                                    <div class="col-md-4">

                                        <div class="border rounded-4 p-3 h-100">

                                            <div class="d-flex justify-content-between align-items-center mb-3">

                                                <span class="fw-semibold">
                                                    NBI Clearance
                                                </span>

                                                <span id="statusNBIClearance"
                                                    class="badge rounded-pill bg-light text-secondary">
                                                    Missing
                                                </span>

                                            </div>

                                            <div class="d-flex gap-2">

                                                <input type="file" name="nbi_clearance" class="form-control form-control-sm"
                                                    accept=".pdf,.jpg,.jpeg,.png">

                                                <button type="button" class="btn btn-sm btn-success"
                                                    onclick="verifyDocument('NBI Clearance', 'statusNBIClearance')">

                                                    Verify

                                                </button>

                                            </div>

                                        </div>

                                    </div>



                                    <!-- =====================================================
                     5. POLICE CLEARANCE
                ====================================================== -->

                                    <div class="col-md-4">

                                        <div class="border rounded-4 p-3 h-100">

                                            <div class="d-flex justify-content-between align-items-center mb-3">

                                                <span class="fw-semibold">
                                                    Police Clearance
                                                </span>

                                                <span id="statusPoliceClearance"
                                                    class="badge rounded-pill bg-light text-secondary">
                                                    Missing
                                                </span>

                                            </div>

                                            <div class="d-flex gap-2">

                                                <input type="file" name="police_clearance"
                                                    class="form-control form-control-sm" accept=".pdf,.jpg,.jpeg,.png">

                                                <button type="button" class="btn btn-sm btn-success"
                                                    onclick="verifyDocument('Police Clearance', 'statusPoliceClearance')">

                                                    Verify

                                                </button>

                                            </div>

                                        </div>

                                    </div>



                                    <!-- =====================================================
                     6. EMPLOYMENT CONTRACT
                ====================================================== -->

                                    <div class="col-md-4">

                                        <div class="border rounded-4 p-3 h-100">

                                            <div class="d-flex justify-content-between align-items-center mb-3">

                                                <span class="fw-semibold">
                                                    Employment Contract
                                                </span>

                                                <span id="statusEmploymentContract"
                                                    class="badge rounded-pill bg-light text-secondary">
                                                    Missing
                                                </span>

                                            </div>

                                            <div class="d-flex gap-2">

                                                <input type="file" name="employment_contract"
                                                    class="form-control form-control-sm" accept=".pdf,.jpg,.jpeg,.png">

                                                <button type="button" class="btn btn-sm btn-success"
                                                    onclick="verifyDocument('Employment Contract', 'statusEmploymentContract')">

                                                    Verify

                                                </button>

                                                <a href="employee_contract_print.php?employee_id=<?= (int) $selectedEmployeeId ?>"
                                                    target="_blank" class="btn btn-sm btn-outline-primary text-nowrap">

                                                    <i class="bi bi-printer me-1"></i>
                                                    Print Contract PDF

                                                </a>

                                            </div>

                                        </div>

                                    </div>



                                    <!-- =====================================================
                     7. SSS DOCUMENT
                ====================================================== -->

                                    <div class="col-md-4">

                                        <div class="border rounded-4 p-3 h-100">

                                            <div class="d-flex justify-content-between align-items-center mb-3">

                                                <span class="fw-semibold">
                                                    SSS Document
                                                </span>

                                                <span id="statusSSSDocument"
                                                    class="badge rounded-pill bg-light text-secondary">
                                                    Missing
                                                </span>

                                            </div>

                                            <div class="d-flex gap-2">

                                                <input type="file" name="sss_document" class="form-control form-control-sm"
                                                    accept=".pdf,.jpg,.jpeg,.png">

                                                <button type="button" class="btn btn-sm btn-success"
                                                    onclick="verifyDocument('SSS Document', 'statusSSSDocument')">

                                                    Verify

                                                </button>

                                            </div>

                                        </div>

                                    </div>



                                    <!-- =====================================================
                     8. PHILHEALTH DOCUMENT
                ====================================================== -->

                                    <div class="col-md-4">

                                        <div class="border rounded-4 p-3 h-100">

                                            <div class="d-flex justify-content-between align-items-center mb-3">

                                                <span class="fw-semibold">
                                                    PhilHealth Document
                                                </span>

                                                <span id="statusPhilHealthDocument"
                                                    class="badge rounded-pill bg-light text-secondary">
                                                    Missing
                                                </span>

                                            </div>

                                            <div class="d-flex gap-2">

                                                <input type="file" name="philhealth_document"
                                                    class="form-control form-control-sm" accept=".pdf,.jpg,.jpeg,.png">

                                                <button type="button" class="btn btn-sm btn-success"
                                                    onclick="verifyDocument('PhilHealth Document', 'statusPhilHealthDocument')">

                                                    Verify

                                                </button>

                                            </div>

                                        </div>

                                    </div>



                                    <!-- =====================================================
                     9. PAG-IBIG DOCUMENT
                ====================================================== -->

                                    <div class="col-md-4">

                                        <div class="border rounded-4 p-3 h-100">

                                            <div class="d-flex justify-content-between align-items-center mb-3">

                                                <span class="fw-semibold">
                                                    Pag-IBIG Document
                                                </span>

                                                <span id="statusPagibigDocument"
                                                    class="badge rounded-pill bg-light text-secondary">
                                                    Missing
                                                </span>

                                            </div>

                                            <div class="d-flex gap-2">

                                                <input type="file" name="pagibig_document"
                                                    class="form-control form-control-sm" accept=".pdf,.jpg,.jpeg,.png">

                                                <button type="button" class="btn btn-sm btn-success"
                                                    onclick="verifyDocument('Pag-IBIG Document', 'statusPagibigDocument')">

                                                    Verify

                                                </button>

                                            </div>

                                        </div>

                                    </div>



                                    <!-- =====================================================
                     10. TIN DOCUMENT
                ====================================================== -->

                                    <div class="col-md-4">

                                        <div class="border rounded-4 p-3 h-100">

                                            <div class="d-flex justify-content-between align-items-center mb-3">

                                                <span class="fw-semibold">
                                                    TIN Document
                                                </span>

                                                <span id="statusTINDocument"
                                                    class="badge rounded-pill bg-light text-secondary">
                                                    Missing
                                                </span>

                                            </div>

                                            <div class="d-flex gap-2">

                                                <input type="file" name="tin_document" class="form-control form-control-sm"
                                                    accept=".pdf,.jpg,.jpeg,.png">

                                                <button type="button" class="btn btn-sm btn-success"
                                                    onclick="verifyDocument('TIN Document', 'statusTINDocument')">

                                                    Verify

                                                </button>

                                            </div>

                                        </div>

                                    </div>

                                </div>


                                <!-- =====================================================
                 DOCUMENT COUNTER
            ====================================================== -->

                                <div class="mt-3">

                                    <small class="text-muted">

                                        <span id="verifiedDocumentCount">1</span>
                                        of 10 documents verified.

                                    </small>

                                </div>

                            </div>

                        </div>



                        <!-- =========================================================
         NAVIGATION
    ========================================================== -->

                        <div class="d-flex justify-content-between align-items-center mt-4">

                            <!-- BACK -->

                            <button type="button" class="btn btn-light border rounded-3 px-4" onclick="goToStep(3)">

                                <i class="bi bi-arrow-left me-1"></i>

                                Back

                            </button>


                            <!-- CONTINUE -->

                            <button type="button" class="btn text-white px-4 rounded-3" style="background:#00224c;"
                                onclick="goToStep(5)">

                                Continue

                                <i class="bi bi-arrow-right ms-1"></i>

                            </button>

                        </div>

                    </div>
                    <div id="registrationStep5" class="registration-content-step d-none">

                        <div class="border rounded-4 p-4 bg-white">

                            <!-- Header -->
                            <div class="mb-4">
                                <span class="small fw-bold" style="color:#00224c;">
                                    STEP 5 OF 7
                                </span>

                                <h5 class="fw-bold mt-1 mb-1" style="color:#00224c;">
                                    Face Registration
                                </h5>

                                <p class="text-muted small mb-0">
                                    Register the employee's face for identity verification and attendance purposes.
                                </p>
                            </div>


                            <div class="row g-4">

                                <!-- CAMERA -->
                                <div class="col-lg-7">

                                    <div class="card border rounded-4 h-100">

                                        <div class="card-header bg-white border-bottom">
                                            <h6 class="fw-bold mb-0">
                                                Face Capture
                                            </h6>
                                        </div>

                                        <div class="card-body">

                                            <!-- Camera Preview -->
                                            <div id="cameraContainer"
                                                class="bg-dark rounded-4 d-flex align-items-center justify-content-center"
                                                style="height:350px; overflow:hidden;">

                                                <video id="faceCamera" class="w-100 h-100" autoplay playsinline
                                                    style="object-fit:cover;">
                                                </video>

                                                <div id="cameraPlaceholder"
                                                    class="text-white text-center position-absolute d-none">

                                                    <i class="bi bi-camera fs-1"></i>

                                                    <div class="mt-2">
                                                        Camera is not available.
                                                    </div>

                                                </div>

                                            </div>


                                            <!-- Camera Status -->
                                            <div class="text-center mt-3">

                                                <span id="cameraStatus" class="badge rounded-pill bg-secondary">

                                                    Camera not started

                                                </span>

                                            </div>


                                            <!-- Camera Buttons -->
                                            <div class="d-flex justify-content-center gap-2 mt-3">

                                                <button type="button" id="startCameraBtn"
                                                    class="btn text-white rounded-3 px-4" style="background:#00224c;">

                                                    <i class="bi bi-camera me-1"></i>
                                                    Start Camera

                                                </button>


                                                <button type="button" id="captureFaceBtn"
                                                    class="btn btn-success rounded-3 px-4" disabled>

                                                    <i class="bi bi-person-bounding-box me-1"></i>
                                                    Capture Face

                                                </button>

                                            </div>

                                        </div>

                                    </div>

                                </div>


                                <!-- REGISTRATION INFORMATION -->
                                <div class="col-lg-5">

                                    <div class="card border rounded-4 h-100">

                                        <div class="card-header bg-white border-bottom">

                                            <h6 class="fw-bold mb-0">
                                                Registration Status
                                            </h6>

                                        </div>


                                        <div class="card-body">

                                            <!-- Employee -->
                                            <div class="mb-4">

                                                <div class="small text-muted">
                                                    Employee
                                                </div>

                                                <div class="fw-bold">
                                                    <?= htmlspecialchars($fullName) ?>
                                                </div>

                                            </div>


                                            <!-- Status -->
                                            <div class="mb-4">

                                                <div class="small text-muted mb-1">
                                                    Face Registration
                                                </div>

                                                <span id="faceRegistrationStatus" class="badge rounded-pill bg-secondary">

                                                    Not Registered

                                                </span>

                                            </div>


                                            <!-- Captured Image -->
                                            <div class="mb-3">

                                                <div class="small text-muted mb-2">
                                                    Captured Face
                                                </div>

                                                <div id="capturedFaceContainer"
                                                    class="border rounded-3 bg-light d-flex align-items-center justify-content-center"
                                                    style="height:180px;">

                                                    <div id="noFaceCaptured" class="text-center text-muted">

                                                        <i class="bi bi-person-circle fs-1"></i>

                                                        <div class="small mt-2">
                                                            No face captured
                                                        </div>

                                                    </div>


                                                    <img id="capturedFaceImage" src="" alt="Captured Face"
                                                        class="img-fluid rounded-3 d-none" style="max-height:170px;">

                                                </div>

                                            </div>


                                            <!-- Instruction -->
                                            <div class="alert alert-light border rounded-3 mb-0">

                                                <div class="small">

                                                    <strong style="color:#00224c;">
                                                        Instructions
                                                    </strong>

                                                    <ul class="mb-0 mt-2 ps-3">

                                                        <li>
                                                            Make sure the employee is facing the camera.
                                                        </li>

                                                        <li>
                                                            Ensure the face is clearly visible.
                                                        </li>

                                                        <li>
                                                            Avoid excessive lighting or darkness.
                                                        </li>

                                                        <li>
                                                            Capture only when the employee is ready.
                                                        </li>

                                                    </ul>

                                                </div>

                                            </div>

                                        </div>

                                    </div>

                                </div>

                            </div>


                            <!-- Hidden Face Data -->
                            <input type="hidden" name="face_registered" id="faceRegistered" value="0">

                            <input type="hidden" name="face_image" id="faceImage">

                            <input type="hidden" name="face_descriptor" id="faceDescriptorInput">


                            <!-- Navigation -->
                            <div class="d-flex justify-content-between align-items-center mt-4">

                                <button type="button" class="btn btn-light border rounded-3 px-4" onclick="goToStep(4)">

                                    <i class="bi bi-arrow-left me-1"></i>
                                    Back

                                </button>


                                <button type="button" class="btn text-white px-4 rounded-3" style="background:#00224c;"
                                    onclick="goToStep(6)">

                                    Continue
                                    <i class="bi bi-arrow-right ms-1"></i>

                                </button>

                            </div>

                        </div>

                    </div>
                    <div id="registrationStep6" class="registration-content-step d-none">
                        <!-- =========================================================
                            STEP 6 — EMPLOYEE ACCOUNT
                        ========================================================= -->
                        <div class="border rounded-4 p-4 bg-white">

                            <!-- HEADER -->
                            <div class="mb-4">

                                <span class="small fw-bold" style="color:#00224c;">

                                    STEP 6 OF 7

                                </span>

                                <h5 class="fw-bold mt-1 mb-1" style="color:#00224c;">

                                    Employee Account

                                </h5>

                                <p class="text-muted small mb-0">

                                    Create the employee's account for accessing the RetailCore platform.

                                </p>

                            </div>


                            <div class="row g-4">

                                <!-- =====================================================
                ACCOUNT INFORMATION
            ====================================================== -->

                                <div class="col-lg-7">

                                    <div class="card border shadow-sm rounded-4 h-100">

                                        <div class="card-header bg-white border-bottom py-3 px-4">

                                            <h6 class="fw-bold mb-0">

                                                Account Information

                                            </h6>

                                        </div>


                                        <div class="card-body p-4">

                                            <div class="row g-3">


                                                <!-- EMPLOYEE CODE -->

                                                <div class="col-md-6">

                                                    <label class="form-label small fw-semibold text-secondary">

                                                        Employee ID

                                                    </label>

                                                    <input type="text" class="form-control bg-light" value="<?= htmlspecialchars(
                                                        $selectedEmployee['employee_code'] ?? ''
                                                    ) ?>" readonly>

                                                </div>


                                                <!-- EMAIL -->

                                                <div class="col-md-6">

                                                    <label class="form-label small fw-semibold text-secondary">

                                                        Email

                                                    </label>

                                                    <input type="email" id="employeeEmail" name="employee_email"
                                                        class="form-control" value="<?= htmlspecialchars(
                                                            $selectedEmployee['email'] ?? ''
                                                        ) ?>" readonly>

                                                </div>


                                                <!-- USERNAME -->

                                                <div class="col-12">

                                                    <label class="form-label small fw-semibold text-secondary">

                                                        Username

                                                    </label>

                                                    <input type="text" id="employeeUsername" name="username"
                                                        class="form-control" placeholder="Enter username"
                                                        autocomplete="off">

                                                    <div class="form-text">

                                                        Username will be used to log in to the RetailCore platform.

                                                    </div>

                                                </div>


                                                <!-- PASSWORD -->

                                                <div class="col-md-6">

                                                    <label class="form-label small fw-semibold text-secondary">

                                                        Password

                                                    </label>

                                                    <div class="input-group">

                                                        <input type="password" id="employeePassword" name="password"
                                                            class="form-control" placeholder="Enter password"
                                                            autocomplete="new-password">

                                                        <button type="button" class="btn btn-outline-secondary"
                                                            onclick="toggleEmployeePassword()">

                                                            <i id="passwordIcon" class="bi bi-eye">
                                                            </i>

                                                        </button>

                                                    </div>

                                                </div>


                                                <!-- CONFIRM PASSWORD -->

                                                <div class="col-md-6">

                                                    <label class="form-label small fw-semibold text-secondary">

                                                        Confirm Password

                                                    </label>

                                                    <input type="password" id="employeePasswordConfirm"
                                                        name="password_confirm" class="form-control"
                                                        placeholder="Confirm password" autocomplete="new-password">

                                                </div>


                                                <!-- ROLE (READ-ONLY, BASED ON APPLIED POSITION) -->

                                                <div class="col-md-6">

                                                    <label class="form-label small fw-semibold text-secondary">

                                                        Employee Role

                                                    </label>

                                                    <input type="text" class="form-control bg-light" value="<?= htmlspecialchars(
                                                        $selectedEmployee['job_title'] ?? '-'
                                                    ) ?>" readonly>

                                                    <input type="hidden" name="employee_role" id="employeeRole" value="<?= htmlspecialchars(
                                                        $selectedEmployee['job_title'] ?? ''
                                                    ) ?>">

                                                    <div class="form-text">

                                                        Role is automatically set to the position the employee applied for.

                                                    </div>

                                                </div>


                                                <!-- ACCOUNT STATUS -->

                                                <div class="col-md-6">

                                                    <label class="form-label small fw-semibold text-secondary">

                                                        Account Status

                                                    </label>

                                                    <input type="text" class="form-control bg-light" value="Active"
                                                        readonly>

                                                    <input type="hidden" name="account_status" value="active">

                                                </div>

                                            </div>

                                        </div>

                                    </div>

                                </div>


                                <!-- =====================================================
                ACCOUNT SUMMARY
            ====================================================== -->

                                <div class="col-lg-5">

                                    <div class="card border shadow-sm rounded-4 h-100">

                                        <div class="card-header bg-white border-bottom py-3 px-4">

                                            <h6 class="fw-bold mb-0">

                                                Account Summary

                                            </h6>

                                        </div>


                                        <div class="card-body p-4">

                                            <!-- EMPLOYEE -->

                                            <div class="mb-4">

                                                <div class="small text-muted">
                                                    Employee
                                                </div>

                                                <div class="fw-bold" style="color:#00224c;">

                                                    <?= htmlspecialchars($fullName) ?>

                                                </div>

                                            </div>


                                            <!-- POSITION -->

                                            <div class="mb-4">

                                                <div class="small text-muted">
                                                    Position
                                                </div>

                                                <div class="fw-semibold">

                                                    <?= htmlspecialchars(
                                                        $selectedEmployee['job_title']
                                                        ?? '-'
                                                    ) ?>

                                                </div>

                                            </div>


                                            <!-- BRANCH -->

                                            <div class="mb-4">

                                                <div class="small text-muted">
                                                    Branch
                                                </div>

                                                <div class="fw-semibold">

                                                    <?= htmlspecialchars(
                                                        $selectedEmployee['branch_name']
                                                        ?? '-'
                                                    ) ?>

                                                </div>

                                            </div>


                                            <!-- ROLE -->

                                            <div class="mb-4">

                                                <div class="small text-muted">
                                                    Assigned Role
                                                </div>

                                                <span id="rolePreview"
                                                    class="badge rounded-pill <?= !empty($selectedEmployee['job_title']) ? 'bg-success' : 'bg-secondary' ?>">

                                                    <?= htmlspecialchars(
                                                        $selectedEmployee['job_title']
                                                        ?? 'Not Selected'
                                                    ) ?>

                                                </span>

                                            </div>


                                            <!-- SECURITY NOTICE -->

                                            <div class="alert alert-light border rounded-3 mb-0">

                                                <div class="small">

                                                    <strong style="color:#00224c;">

                                                        Account Security

                                                    </strong>

                                                    <p class="text-muted mb-0 mt-1">

                                                        The employee account should only be used
                                                        by the assigned employee. Login credentials
                                                        must be kept confidential.

                                                    </p>

                                                </div>

                                            </div>

                                        </div>

                                    </div>

                                </div>

                            </div>


                            <!-- =====================================================
            NAVIGATION
        ====================================================== -->

                            <div class="d-flex justify-content-between align-items-center mt-4">

                                <!-- BACK -->

                                <button type="button" class="btn btn-light border rounded-3 px-4" onclick="goToStep(5)">

                                    <i class="bi bi-arrow-left me-1"></i>

                                    Back

                                </button>


                                <!-- CONTINUE -->

                                <button type="button" class="btn text-white px-4 rounded-3" style="background:#00224c;"
                                    onclick="validateEmployeeAccount()">

                                    Continue

                                    <i class="bi bi-arrow-right ms-1"></i>

                                </button>

                            </div>

                        </div>

                    </div>
                    <div id="registrationStep7" class="registration-content-step d-none">
                        <!-- =========================================================
                            STEP 7 — REVIEW & COMPLETE
                        ========================================================= -->
                        <div class="border rounded-4 p-4 bg-white">

                            <!-- HEADER -->
                            <div class="mb-4">

                                <span class="small fw-bold" style="color:#00224c;">

                                    STEP 7 OF 7

                                </span>

                                <h5 class="fw-bold mt-1 mb-1" style="color:#00224c;">

                                    Review & Complete

                                </h5>

                                <p class="text-muted small mb-0">

                                    Review all employee registration information before completing
                                    the registration.

                                </p>

                            </div>


                            <!-- =====================================================
            EMPLOYEE INFORMATION
        ====================================================== -->

                            <div class="card border shadow-sm rounded-4 mb-4">

                                <div class="card-header bg-white border-bottom py-3 px-4">

                                    <div class="d-flex justify-content-between align-items-center">

                                        <h6 class="fw-bold mb-0">

                                            Employee Information

                                        </h6>

                                        <span class="badge rounded-pill bg-success">

                                            Ready

                                        </span>

                                    </div>

                                </div>


                                <div class="card-body p-4">

                                    <div class="row g-3">

                                        <!-- NAME -->

                                        <div class="col-md-6">

                                            <div class="small text-muted">
                                                Full Name
                                            </div>

                                            <div class="fw-semibold">

                                                <?= htmlspecialchars($fullName) ?>

                                            </div>

                                        </div>


                                        <!-- EMPLOYEE CODE -->

                                        <div class="col-md-6">

                                            <div class="small text-muted">
                                                Employee ID
                                            </div>

                                            <div class="fw-semibold">

                                                <?= htmlspecialchars(
                                                    $selectedEmployee['employee_code'] ?? '-'
                                                ) ?>

                                            </div>

                                        </div>


                                        <!-- EMAIL -->

                                        <div class="col-md-6">

                                            <div class="small text-muted">
                                                Email
                                            </div>

                                            <div class="fw-semibold">

                                                <?= htmlspecialchars(
                                                    $selectedEmployee['email'] ?? '-'
                                                ) ?>

                                            </div>

                                        </div>


                                        <!-- PHONE -->

                                        <div class="col-md-6">

                                            <div class="small text-muted">
                                                Contact
                                            </div>

                                            <div class="fw-semibold">

                                                <?= htmlspecialchars(
                                                    $selectedEmployee['phone'] ?? '-'
                                                ) ?>

                                            </div>

                                        </div>


                                        <!-- POSITION -->

                                        <div class="col-md-6">

                                            <div class="small text-muted">
                                                Position
                                            </div>

                                            <div class="fw-semibold">

                                                <?= htmlspecialchars(
                                                    $selectedEmployee['job_title'] ?? '-'
                                                ) ?>

                                            </div>

                                        </div>


                                        <!-- BRANCH -->

                                        <div class="col-md-6">

                                            <div class="small text-muted">
                                                Branch
                                            </div>

                                            <div class="fw-semibold">

                                                <?= htmlspecialchars(
                                                    $selectedEmployee['branch_name'] ?? '-'
                                                ) ?>

                                            </div>

                                        </div>

                                    </div>

                                </div>

                            </div>


                            <!-- =====================================================
            EMPLOYMENT SUMMARY
        ====================================================== -->

                            <div class="card border shadow-sm rounded-4 mb-4">

                                <div class="card-header bg-white border-bottom py-3 px-4">

                                    <h6 class="fw-bold mb-0">

                                        Employment Setup

                                    </h6>

                                </div>


                                <div class="card-body p-4">

                                    <div class="row g-3">

                                        <!-- DEPARTMENT -->

                                        <div class="col-md-4">

                                            <div class="small text-muted">
                                                Department
                                            </div>

                                            <div class="fw-semibold" id="reviewDepartment">

                                                <?= htmlspecialchars(
                                                    $selectedEmployee['department'] ?? '-'
                                                ) ?>

                                            </div>

                                        </div>


                                        <!-- JOB LEVEL -->

                                        <div class="col-md-4">

                                            <div class="small text-muted">
                                                Job Level
                                            </div>

                                            <div class="fw-semibold" id="reviewJobLevel">

                                                Not Selected

                                            </div>

                                        </div>


                                        <!-- EMPLOYMENT TYPE -->

                                        <div class="col-md-4">

                                            <div class="small text-muted">
                                                Employment Type
                                            </div>

                                            <div class="fw-semibold">

                                                <?= htmlspecialchars(
                                                    $selectedEmployee['job_employment_type']
                                                    ?? '-'
                                                ) ?>

                                            </div>

                                        </div>


                                        <!-- SUPERVISOR -->

                                        <div class="col-md-4">

                                            <div class="small text-muted">
                                                Immediate Supervisor
                                            </div>

                                            <div class="fw-semibold" id="reviewSupervisor">

                                                Not Selected

                                            </div>

                                        </div>


                                        <!-- SHIFT -->

                                        <div class="col-md-4">

                                            <div class="small text-muted">
                                                Shift
                                            </div>

                                            <div class="fw-semibold" id="reviewShift">

                                                Not Selected

                                            </div>

                                        </div>


                                        <!-- WORKING DAYS -->

                                        <div class="col-md-4">

                                            <div class="small text-muted">
                                                Working Days
                                            </div>

                                            <div class="fw-semibold" id="reviewWorkingDays">

                                                Not Selected

                                            </div>

                                        </div>


                                        <!-- START DATE -->

                                        <div class="col-md-4">

                                            <div class="small text-muted">
                                                Official Start Date
                                            </div>

                                            <div class="fw-semibold" id="reviewStartDate">

                                                Not Selected

                                            </div>

                                        </div>


                                        <!-- REST DAY -->

                                        <div class="col-md-4">

                                            <div class="small text-muted">
                                                Rest Day
                                            </div>

                                            <div class="fw-semibold" id="reviewRestDay">

                                                Not Selected

                                            </div>

                                        </div>


                                        <!-- TIME -->

                                        <div class="col-md-4">

                                            <div class="small text-muted">
                                                Working Hours
                                            </div>

                                            <div class="fw-semibold">

                                                <?= htmlspecialchars(
                                                    $selectedEmployee['opening_time']
                                                    ?? '-'
                                                ) ?>

                                                -

                                                <?= htmlspecialchars(
                                                    $selectedEmployee['closing_time']
                                                    ?? '-'
                                                ) ?>

                                            </div>

                                        </div>

                                    </div>

                                </div>

                            </div>


                            <!-- =====================================================
            SALARY SUMMARY
        ====================================================== -->

                            <div class="card border shadow-sm rounded-4 mb-4">

                                <div class="card-header bg-white border-bottom py-3 px-4">

                                    <h6 class="fw-bold mb-0">

                                        Salary & Payroll

                                    </h6>

                                </div>


                                <div class="card-body p-4">

                                    <div class="row g-3">

                                        <div class="col-md-4">

                                            <div class="small text-muted">
                                                Basic Salary
                                            </div>

                                            <div class="fw-bold" style="color:#00224c;" id="reviewSalary">

                                                ₱0.00

                                            </div>

                                        </div>


                                        <div class="col-md-4">

                                            <div class="small text-muted">
                                                Salary Type
                                            </div>

                                            <div class="fw-semibold" id="reviewSalaryType">

                                                Not Selected

                                            </div>

                                        </div>


                                        <div class="col-md-4">

                                            <div class="small text-muted">
                                                Pay Frequency
                                            </div>

                                            <div class="fw-semibold" id="reviewPayFrequency">

                                                Not Selected

                                            </div>

                                        </div>

                                    </div>

                                </div>

                            </div>


                            <!-- =====================================================
            ACCOUNT SUMMARY
        ====================================================== -->

                            <div class="card border shadow-sm rounded-4 mb-4">

                                <div class="card-header bg-white border-bottom py-3 px-4">

                                    <h6 class="fw-bold mb-0">

                                        Employee Account

                                    </h6>

                                </div>


                                <div class="card-body p-4">

                                    <div class="row g-3">

                                        <div class="col-md-6">

                                            <div class="small text-muted">
                                                Username
                                            </div>

                                            <div class="fw-semibold" id="reviewUsername">

                                                Not Provided

                                            </div>

                                        </div>


                                        <div class="col-md-6">

                                            <div class="small text-muted">
                                                Employee Role
                                            </div>

                                            <div class="fw-semibold" id="reviewRole">

                                                Not Selected

                                            </div>

                                        </div>


                                        <div class="col-md-6">

                                            <div class="small text-muted">
                                                Account Status
                                            </div>

                                            <span class="badge rounded-pill bg-success">

                                                Active

                                            </span>

                                        </div>

                                    </div>

                                </div>

                            </div>


                            <!-- =====================================================
            FINAL NOTICE
        ====================================================== -->

                            <div class="alert alert-warning border rounded-3">

                                <div class="d-flex">

                                    <i class="bi bi-exclamation-triangle-fill me-2"></i>

                                    <div class="small">

                                        <strong>Final Confirmation</strong>

                                        <div class="mt-1">

                                            Please make sure that all employee information
                                            is correct. Completing this registration will
                                            convert the employee from
                                            <strong>Pre-Employee</strong> to
                                            <strong>Official Employee</strong>.

                                        </div>

                                    </div>

                                </div>

                            </div>


                            <!-- =====================================================
            NAVIGATION
        ====================================================== -->

                            <div class="d-flex justify-content-between align-items-center mt-4">

                                <button type="button" class="btn btn-light border rounded-3 px-4" onclick="goToStep(6)">

                                    <i class="bi bi-arrow-left me-1"></i>

                                    Back

                                </button>


                                <button type="button" class="btn text-white px-4 rounded-3" style="background:#00224c;"
                                    onclick="completeRegistration()">

                                    <i class="bi bi-check-circle me-1"></i>

                                    Complete Registration

                                </button>

                            </div>

                        </div>

                    </div>
                </form>


            </div>

        </div>

    <?php endif; ?>

</div>

<script src="../assets/js/face-api.min.js"></script>

<?php include includeRoleFooter(__DIR__, 'hr_footer.php'); ?>

<script>
    let currentStep = 1;

    /* =========================================================
       FACE-API MODELS (used for Face Registration step)
    ========================================================= */

    let faceModelsReady = (async function () {
        try {
            await faceapi.nets.tinyFaceDetector.loadFromUri("../models");
            await faceapi.nets.faceLandmark68Net.loadFromUri("../models");
            await faceapi.nets.faceRecognitionNet.loadFromUri("../models");
            console.log("✅ Face AI Loaded");
        } catch (err) {
            console.error("Failed to load face-api models", err);
        }
    })();

    /* =========================================================
       GO TO STEP
    ========================================================= */
    function goToStep(step) {

        if (step < 1 || step > 7) {
            return;
        }

        currentStep = step;

        updateStepNavigation();

        showStep(step);


        /* LOAD REVIEW DATA */

        if (step === 7) {

            loadReviewData();

        }

    }


    /* =========================================================
       SHOW STEP
    ========================================================= */

    function showStep(step) {

        document.querySelectorAll('.registration-content-step')
            .forEach(function (section) {

                section.classList.add('d-none');

            });


        const target = document.getElementById(
            'registrationStep' + step
        );


        if (target) {

            target.classList.remove('d-none');

        }

    }


    /* =========================================================
       UPDATE TOP STEP NAVIGATION
    ========================================================= */

    function updateStepNavigation() {

        document.querySelectorAll('.registration-step')
            .forEach(function (item) {

                const stepNumber = parseInt(
                    item.dataset.step
                );


                item.classList.remove(
                    'active',
                    'completed'
                );


                if (stepNumber === currentStep) {

                    item.classList.add('active');

                } else if (stepNumber < currentStep) {

                    item.classList.add('completed');

                }

            });

    }


    /* =========================================================
       INITIALIZE
    ========================================================= */

    document.addEventListener(
        'DOMContentLoaded',
        function () {

            showStep(<?= $step ?>);
            updateStepNavigation();

        }
    );

    document.querySelectorAll('.registration-step').forEach(function (item) {

        item.addEventListener('click', function () {

            const stepNumber = parseInt(
                this.dataset.step
            );

            goToStep(stepNumber);

        });

    });

    /* =========================================================
    STEP 2 VALIDATION
    ========================================================= */

    function validateStep2() {

        const jobLevel = document.getElementById('jobLevel').value.trim();
        const supervisor = document.getElementById('supervisorId').value.trim();

        const shift = document.getElementById('shift').value.trim();
        const workingDays = document.getElementById('workingDays').value.trim();
        const restDay = document.getElementById('restDay').value.trim();

        const officialStartDate =
            document.getElementById('officialStartDate').value.trim();

        const salary =
            document.getElementById('salary').value.trim();

        const salaryType =
            document.getElementById('salaryType').value.trim();

        const payFrequency =
            document.getElementById('payFrequency').value.trim();


        /* =====================================================
           JOB LEVEL
        ===================================================== */

        if (jobLevel === '') {

            Swal.fire({
                icon: 'warning',
                title: 'Incomplete Information',
                text: 'Please select a Job Level.',
                confirmButtonColor: '#00224c'
            });

            document.getElementById('jobLevel').focus();

            return false;
        }


        /* =====================================================
           SUPERVISOR
        ===================================================== */

        if (supervisor === '') {

            Swal.fire({
                icon: 'warning',
                title: 'Incomplete Information',
                text: 'Please select an Immediate Supervisor.',
                confirmButtonColor: '#00224c'
            });

            document.getElementById('supervisorId').focus();

            return false;
        }


        /* =====================================================
           SHIFT
        ===================================================== */

        if (shift === '') {

            Swal.fire({
                icon: 'warning',
                title: 'Incomplete Information',
                text: 'Please select a Shift.',
                confirmButtonColor: '#00224c'
            });

            document.getElementById('shift').focus();

            return false;
        }


        /* =====================================================
           WORKING DAYS
        ===================================================== */

        if (workingDays === '') {

            Swal.fire({
                icon: 'warning',
                title: 'Incomplete Information',
                text: 'Please enter the Working Days.',
                confirmButtonColor: '#00224c'
            });

            document.getElementById('workingDays').focus();

            return false;
        }


        /* =====================================================
           REST DAY
        ===================================================== */

        if (restDay === '') {

            Swal.fire({
                icon: 'warning',
                title: 'Incomplete Information',
                text: 'Please select a Rest Day.',
                confirmButtonColor: '#00224c'
            });

            document.getElementById('restDay').focus();

            return false;
        }


        /* =====================================================
           OFFICIAL START DATE
        ===================================================== */

        if (officialStartDate === '') {

            Swal.fire({
                icon: 'warning',
                title: 'Incomplete Information',
                text: 'Please select the Official Start Date.',
                confirmButtonColor: '#00224c'
            });

            document.getElementById('officialStartDate').focus();

            return false;
        }


        /* =====================================================
           SALARY
        ===================================================== */

        if (salary === '') {

            Swal.fire({
                icon: 'warning',
                title: 'Incomplete Information',
                text: 'Please enter the Basic Salary.',
                confirmButtonColor: '#00224c'
            });

            document.getElementById('salary').focus();

            return false;
        }


        if (parseFloat(salary) <= 0) {

            Swal.fire({
                icon: 'warning',
                title: 'Invalid Salary',
                text: 'Basic Salary must be greater than zero.',
                confirmButtonColor: '#00224c'
            });

            document.getElementById('salary').focus();

            return false;
        }


        /* =====================================================
           SALARY TYPE
        ===================================================== */

        if (salaryType === '') {

            Swal.fire({
                icon: 'warning',
                title: 'Incomplete Information',
                text: 'Please select a Salary Type.',
                confirmButtonColor: '#00224c'
            });

            document.getElementById('salaryType').focus();

            return false;
        }


        /* =====================================================
           PAY FREQUENCY
        ===================================================== */

        if (payFrequency === '') {

            Swal.fire({
                icon: 'warning',
                title: 'Incomplete Information',
                text: 'Please select a Pay Frequency.',
                confirmButtonColor: '#00224c'
            });

            document.getElementById('payFrequency').focus();

            return false;
        }


        /* =====================================================
           ALL VALID
        ===================================================== */

        goToStep(3);

        return true;
    }

    /* =========================================================
       STEP 3 — GOVERNMENT IDS
    ========================================================= */

    const governmentIds = {
        sss: false,
        philhealth: false,
        pagibig: false,
        tin: false,
        nationalId: false,
        passport: false,
        driverLicense: false
    };


    /* =========================================================
       VERIFY GOVERNMENT ID
    ========================================================= */

    function verifyGovernmentId(type) {

        const inputMap = {
            sss: 'sssNumber',
            philhealth: 'philhealthNumber',
            pagibig: 'pagibigNumber',
            tin: 'tinNumber',
            nationalId: 'nationalIdNumber',
            passport: 'passportNumber',
            driverLicense: 'driverLicenseNumber'
        };


        const statusMap = {
            sss: 'sssStatus',
            philhealth: 'philhealthStatus',
            pagibig: 'pagibigStatus',
            tin: 'tinStatus',
            nationalId: 'nationalIdStatus',
            passport: 'passportStatus',
            driverLicense: 'driverLicenseStatus'
        };


        const input = document.getElementById(inputMap[type]);

        const status = document.getElementById(statusMap[type]);


        if (!input || !status) {
            return;
        }


        const value = input.value.trim();


        /* =========================================
           VALIDATION
        ========================================= */

        if (value === '') {

            Swal.fire({
                icon: 'warning',
                title: 'Required',
                text: 'Please enter the ID number first.',
                confirmButtonColor: '#00224c'
            });

            input.focus();

            return;
        }


        /* =========================================
           VERIFY
        ========================================= */

        Swal.fire({
            icon: 'question',
            title: 'Verify ID?',
            text: 'Are you sure this government ID is correct?',
            showCancelButton: true,
            confirmButtonText: 'Yes, Verify',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#00224c'
        }).then((result) => {

            if (!result.isConfirmed) {
                return;
            }


            /* Mark as verified */

            governmentIds[type] = true;


            /* Change badge */

            status.className =
                'badge rounded-pill bg-success-subtle text-success border border-success-subtle';

            status.textContent = 'Verified';


            /* Disable input */

            input.readOnly = true;


            /* Update count */

            updateGovernmentIdCount();


            Swal.fire({
                icon: 'success',
                title: 'Verified',
                text: 'Government ID has been verified.',
                confirmButtonColor: '#00224c',
                timer: 1500,
                showConfirmButton: false
            });

        });

    }


    /* =========================================================
       UPDATE ID COUNT
    ========================================================= */

    function updateGovernmentIdCount() {

        const count = Object.values(governmentIds)
            .filter(Boolean)
            .length;


        const counter =
            document.getElementById('governmentIdCount');


        if (counter) {
            counter.textContent = count;
        }

    }


    /* =========================================================
       CONTINUE STEP 3
    ========================================================= */

    function continueGovernmentIds() {

        const count = Object.values(governmentIds)
            .filter(Boolean)
            .length;


        /* =========================================
           REQUIRE AT LEAST ONE ID
        ========================================= */

        if (count === 0) {

            Swal.fire({
                icon: 'warning',
                title: 'Government ID Required',
                text: 'Please capture and verify at least one government ID before continuing.',
                confirmButtonColor: '#00224c'
            });

            return;
        }


        /* =========================================
           CONFIRM CONTINUE
        ========================================= */

        Swal.fire({
            icon: 'question',
            title: 'Continue to Documents?',
            text: 'The government identification step has been completed.',
            showCancelButton: true,
            confirmButtonText: 'Continue',
            cancelButtonText: 'Stay Here',
            confirmButtonColor: '#00224c'
        }).then((result) => {

            if (result.isConfirmed) {

                goToStep(4);

            }

        });

    }

    /* DOCUMENT VERIFICATION
    |
    | Verify used to only repaint the badge -- it never looked at the file
    | input, so HR could mark a document "Verified" with nothing attached and
    | the "x of 10 documents verified" count believed it.
    |
    | A document can now be verified only when a file is actually chosen, of a
    | type and size the server will accept (the same allowlist and 10 MB limit
    | the save handler enforces). Choosing, replacing or clearing a file drops
    | the badge back to Uploaded / Missing, so a verification never carries
    | over to a file nobody looked at.
    |
    | The Resume card is untouched: its file comes from the application and
    | its badge has no status id.
    */
    var DOCUMENT_MAX_BYTES = 10 * 1024 * 1024;
    var DOCUMENT_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'];

    function documentCardParts(statusId) {
        var status = document.getElementById(statusId);
        var card = status ? status.closest('.border.rounded-4') : null;
        var input = card ? card.querySelector('input[type="file"]') : null;
        return { status: status, card: card, input: input };
    }

    function setDocumentBadge(status, state) {
        if (!status) return;

        status.classList.remove(
            'bg-light', 'text-secondary',
            'bg-warning-subtle', 'text-warning',
            'bg-success-subtle', 'text-success'
        );

        if (state === 'verified') {
            status.classList.add('bg-success-subtle', 'text-success');
            status.textContent = 'Verified';
        } else if (state === 'uploaded') {
            status.classList.add('bg-warning-subtle', 'text-warning');
            status.textContent = 'Uploaded';
        } else {
            status.classList.add('bg-light', 'text-secondary');
            status.textContent = 'Missing';
        }
    }

    function documentNotify(icon, title, text) {
        if (window.Swal) {
            return Swal.fire({ icon: icon, title: title, text: text, confirmButtonColor: '#00224c' });
        }
        alert(title + '\n\n' + text);
        return Promise.resolve();
    }

    function rejectDocumentFile(parts, title, text) {
        parts.input.value = '';
        setDocumentBadge(parts.status, 'missing');
        updateVerifiedDocumentCount();
        documentNotify('error', title, text);
    }

    function verifyDocument(documentName, statusId) {

        var parts = documentCardParts(statusId);
        var file = parts.input && parts.input.files && parts.input.files[0];

        if (!file) {
            documentNotify(
                'warning',
                'No File Uploaded',
                'Upload the ' + documentName + ' before verifying it.'
            );
            return;
        }

        var extension = (file.name.split('.').pop() || '').toLowerCase();
        var allowed = DOCUMENT_EXTENSIONS;

        // Government ID cards only accept PDF / JPG / PNG on the server.
        if (parts.input.accept) {
            allowed = parts.input.accept.split(',').map(function (item) {
                return item.trim().replace(/^\./, '').toLowerCase();
            }).filter(Boolean);
        }

        if (allowed.indexOf(extension) === -1) {
            rejectDocumentFile(parts, 'Invalid File Type',
                documentName + ' must be a ' +
                allowed.map(function (item) { return item.toUpperCase(); }).join(', ') +
                ' file.');
            return;
        }

        if (file.size > DOCUMENT_MAX_BYTES) {
            rejectDocumentFile(parts, 'File Too Large',
                documentName + ' is larger than 10 MB.');
            return;
        }

        var markVerified = function () {
            // The file could have been swapped while the dialog was open.
            if (!parts.input.files || parts.input.files[0] !== file) {
                return;
            }

            setDocumentBadge(parts.status, 'verified');
            updateVerifiedDocumentCount();
            documentNotify('success', 'Verified!', documentName + ' has been verified.');
        };

        if (!window.Swal) {
            if (confirm('Verify ' + documentName + ' (' + file.name + ')?')) {
                markVerified();
            }
            return;
        }

        Swal.fire({
            title: 'Verify Document?',
            text: 'Verify ' + documentName + ' (' + file.name + ')?',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Yes, Verify',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#00224c'
        }).then(function (result) {
            if (result.isConfirmed) {
                markVerified();
            }
        });

    }

    // Delegated, so it works regardless of when this script runs relative to the markup.
    document.addEventListener('change', function (event) {

        var input = event.target;

        if (!input.matches || !input.matches('#registrationStep4 input[type="file"]')) {
            return;
        }

        var card = input.closest('.border.rounded-4');
        var status = card ? card.querySelector('.badge[id^="status"]') : null;

        if (!status) {
            return;
        }

        setDocumentBadge(status, input.files && input.files.length ? 'uploaded' : 'missing');
        updateVerifiedDocumentCount();
    });


    function updateVerifiedDocumentCount() {

        const verifiedStatuses = document.querySelectorAll(
            '#registrationStep4 .badge.bg-success-subtle'
        );

        let count = verifiedStatuses.length;

        document.getElementById(
            'verifiedDocumentCount'
        ).textContent = count;

    }
    /* =========================================================
   STEP 5 — FACE REGISTRATION
========================================================= */

    let faceStream = null;


    /* START CAMERA */
    document.getElementById('startCameraBtn')?.addEventListener(
        'click',
        async function () {

            const video = document.getElementById('faceCamera');
            const status = document.getElementById('cameraStatus');
            const captureBtn = document.getElementById('captureFaceBtn');

            try {

                faceStream = await navigator.mediaDevices.getUserMedia({
                    video: {
                        facingMode: "user"
                    },
                    audio: false
                });

                video.srcObject = faceStream;

                status.className = 'badge rounded-pill bg-success';
                status.textContent = 'Camera Ready';

                captureBtn.disabled = false;

                Swal.fire({
                    icon: 'success',
                    title: 'Camera Ready',
                    text: 'The camera is ready for face capture.',
                    timer: 1500,
                    showConfirmButton: false
                });

            } catch (error) {

                console.error(error);

                status.className = 'badge rounded-pill bg-danger';
                status.textContent = 'Camera Unavailable';

                Swal.fire({
                    icon: 'error',
                    title: 'Camera Error',
                    text: 'Unable to access the camera. Please allow camera permission and try again.'
                });

            }

        }
    );


    /* CAPTURE FACE */
    document.getElementById('captureFaceBtn')?.addEventListener(
        'click',
        async function () {

            const video = document.getElementById('faceCamera');
            const captureBtn = document.getElementById('captureFaceBtn');

            if (!video.videoWidth || !video.videoHeight) {

                Swal.fire({
                    icon: 'warning',
                    title: 'Camera Not Ready',
                    text: 'Please start the camera first.'
                });

                return;
            }


            captureBtn.disabled = true;

            const originalLabel = captureBtn.innerHTML;

            captureBtn.innerHTML =
                '<i class="bi bi-hourglass-split me-1"></i> Detecting...';


            /* Make sure face-api models are loaded */

            await faceModelsReady;


            const detection = await faceapi
                .detectSingleFace(
                    video,
                    new faceapi.TinyFaceDetectorOptions()
                )
                .withFaceLandmarks()
                .withFaceDescriptor();


            if (!detection) {

                Swal.fire({
                    icon: 'warning',
                    title: 'No Face Detected',
                    text: 'Please position the employee\'s face clearly in front of the camera and try again.'
                });

                captureBtn.disabled = false;
                captureBtn.innerHTML = originalLabel;

                return;
            }


            /* Snapshot image (for HR preview / record only) */

            const canvas = document.createElement('canvas');

            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;

            const context = canvas.getContext('2d');

            context.drawImage(
                video,
                0,
                0,
                canvas.width,
                canvas.height
            );


            const imageData = canvas.toDataURL('image/jpeg', 0.90);


            /* Show captured image */

            const capturedImage =
                document.getElementById('capturedFaceImage');

            const noFace =
                document.getElementById('noFaceCaptured');

            capturedImage.src = imageData;

            capturedImage.classList.remove('d-none');

            noFace.classList.add('d-none');


            /* Update status */

            const registrationStatus =
                document.getElementById('faceRegistrationStatus');

            registrationStatus.className =
                'badge rounded-pill bg-success';

            registrationStatus.textContent =
                'Registered';


            /* Hidden fields */

            document.getElementById('faceRegistered').value = '1';

            document.getElementById('faceImage').value = imageData;

            document.getElementById('faceDescriptorInput').value =
                JSON.stringify(Array.from(detection.descriptor));


            /* Stop camera */

            if (faceStream) {

                faceStream.getTracks().forEach(
                    track => track.stop()
                );

                faceStream = null;

            }


            document.getElementById('cameraStatus').className =
                'badge rounded-pill bg-success';

            document.getElementById('cameraStatus').textContent =
                'Face Captured';


            captureBtn.innerHTML = originalLabel;

            captureBtn.disabled =
                true;


            Swal.fire({
                icon: 'success',
                title: 'Face Captured',
                text: 'The employee face has been registered successfully.',
                timer: 1800,
                showConfirmButton: false
            });

        }
    );

    /* =========================================================
   STEP 6 — EMPLOYEE ACCOUNT
========================================================= */

    function toggleEmployeePassword() {

        const password =
            document.getElementById('employeePassword');

        const confirmPassword =
            document.getElementById('employeePasswordConfirm');

        const icon =
            document.getElementById('passwordIcon');


        if (password.type === 'password') {

            password.type = 'text';

            confirmPassword.type = 'text';

            icon.className = 'bi bi-eye-slash';

        } else {

            password.type = 'password';

            confirmPassword.type = 'password';

            icon.className = 'bi bi-eye';

        }

    }


    /* =========================================================
       NOTE: Employee Role is no longer an editable <select>.
       It is fixed to the applicant's job title and rendered
       read-only (see #rolePreview and the hidden #employeeRole
       input in Step 6), so no "change" listener is needed here.
    ========================================================= */


    /* =========================================================
       VALIDATE EMPLOYEE ACCOUNT
    ========================================================= */

    function validateEmployeeAccount() {

        const username =
            document.getElementById(
                'employeeUsername'
            ).value.trim();


        const password =
            document.getElementById(
                'employeePassword'
            ).value;


        const confirmPassword =
            document.getElementById(
                'employeePasswordConfirm'
            ).value;


        const role =
            document.getElementById(
                'employeeRole'
            ).value;


        /* USERNAME */

        if (!username) {

            Swal.fire({
                icon: 'warning',
                title: 'Username Required',
                text: 'Please enter a username for the employee.',
                confirmButtonColor: '#00224c'
            });

            return;
        }


        if (username.length < 5) {

            Swal.fire({
                icon: 'warning',
                title: 'Invalid Username',
                text: 'Username must contain at least 5 characters.',
                confirmButtonColor: '#00224c'
            });

            return;
        }


        /* PASSWORD */

        if (!password) {

            Swal.fire({
                icon: 'warning',
                title: 'Password Required',
                text: 'Please enter a password.',
                confirmButtonColor: '#00224c'
            });

            return;
        }


        if (password.length < 8) {

            Swal.fire({
                icon: 'warning',
                title: 'Weak Password',
                text: 'Password must contain at least 8 characters.',
                confirmButtonColor: '#00224c'
            });

            return;
        }


        /* CONFIRM PASSWORD */

        if (!confirmPassword) {

            Swal.fire({
                icon: 'warning',
                title: 'Confirm Password',
                text: 'Please confirm the employee password.',
                confirmButtonColor: '#00224c'
            });

            return;
        }


        if (password !== confirmPassword) {

            Swal.fire({
                icon: 'error',
                title: 'Passwords Do Not Match',
                text: 'The password and confirmation password must be the same.',
                confirmButtonColor: '#00224c'
            });

            return;
        }


        /* ROLE */

        if (!role) {

            Swal.fire({
                icon: 'warning',
                title: 'Employee Role Missing',
                text: 'This employee has no applied position on file, so a role could not be assigned automatically.',
                confirmButtonColor: '#00224c'
            });

            return;
        }


        /* SUCCESS */

        Swal.fire({
            icon: 'success',
            title: 'Account Setup Complete',
            text: 'Employee account information is ready.',
            confirmButtonColor: '#00224c',
            timer: 1500,
            showConfirmButton: false
        }).then(function () {

            goToStep(7);

        });

    }
    /* =========================================================
   STEP 7 — LOAD REVIEW DATA
========================================================= */

    function loadReviewData() {

        const jobLevel =
            document.getElementById('jobLevel');

        const supervisor =
            document.getElementById('supervisorId');

        const shift =
            document.getElementById('shift');

        const workingDays =
            document.getElementById('workingDays');

        const startDate =
            document.getElementById('officialStartDate');

        const restDay =
            document.getElementById('restDay');

        const salary =
            document.getElementById('salary');

        const salaryType =
            document.getElementById('salaryType');

        const payFrequency =
            document.getElementById('payFrequency');

        const username =
            document.getElementById('employeeUsername');

        const role =
            document.getElementById('employeeRole');


        /* JOB LEVEL */

        document.getElementById('reviewJobLevel').textContent =
            jobLevel?.value || 'Not Selected';


        /* SUPERVISOR */

        if (supervisor && supervisor.value) {

            document.getElementById('reviewSupervisor').textContent =
                supervisor.options[supervisor.selectedIndex].text;

        } else {

            document.getElementById('reviewSupervisor').textContent =
                'Not Selected';

        }


        /* SHIFT */

        document.getElementById('reviewShift').textContent =
            shift?.value || 'Not Selected';


        /* WORKING DAYS */

        document.getElementById('reviewWorkingDays').textContent =
            workingDays?.value || 'Not Selected';


        /* START DATE */

        document.getElementById('reviewStartDate').textContent =
            startDate?.value || 'Not Selected';


        /* REST DAY */

        document.getElementById('reviewRestDay').textContent =
            restDay?.value || 'Not Selected';


        /* SALARY */

        if (salary && salary.value) {

            const amount =
                parseFloat(salary.value);

            document.getElementById('reviewSalary').textContent =
                '₱' + amount.toLocaleString(
                    'en-PH', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                }
                );

        } else {

            document.getElementById('reviewSalary').textContent =
                '₱0.00';

        }


        /* SALARY TYPE */

        document.getElementById('reviewSalaryType').textContent =
            salaryType?.value || 'Not Selected';


        /* PAY FREQUENCY */

        document.getElementById('reviewPayFrequency').textContent =
            payFrequency?.value || 'Not Selected';


        /* USERNAME */

        document.getElementById('reviewUsername').textContent =
            username?.value || 'Not Provided';


        /* ROLE — now a fixed hidden input (job title), not a <select> */

        document.getElementById('reviewRole').textContent =
            role?.value || 'Not Selected';

    }

    function completeRegistration() {

        Swal.fire({
            icon: 'question',
            title: 'Complete Registration?',
            html: `
            <div class="text-muted">

                You are about to register
                <strong><?= htmlspecialchars($fullName ?? '') ?></strong>
                as an <strong>Official Employee</strong>.

                <br><br>

                Please make sure that all information
                is correct before continuing.

            </div>
        `,

            showCancelButton: true,

            confirmButtonText: '<i class="bi bi-check-circle me-1"></i> Complete Registration',

            cancelButtonText: 'Review Again',

            confirmButtonColor: '#00224c',

            cancelButtonColor: '#6c757d'

        }).then(function (result) {

            if (result.isConfirmed) {

                const form =
                    document.getElementById('employeeRegistrationForm');

                form.submit();

            }

        });
    }
</script>