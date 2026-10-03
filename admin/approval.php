<?php

require_once('../init.php');
requireRole(['admin']);

$companyId = requireCompany();
requireModule($conn, $companyId, 'hiring', 'Hiring Approval');

require_once('../accounts/send_rejection.php');


/* =========================================================
   ADMIN APPROVE RECOMMENDATION
========================================================= */

/* =========================================================
   ADMIN APPROVE RECOMMENDATION
   FLOW:

   HR Recommended
        ↓
   Admin Approval
        ↓
   hiring_recommendations = Approved
   applications = Hired
        ↓
   employees = Pre-Employee
   employment = Pre-Employee
        ↓
   Employee Registration
========================================================= */

if (isset($_POST['approveRecommendation'])) {

    $recommendation_id = (int) ($_POST['recommendation_id'] ?? 0);

    /* =====================================================
       VALIDATE REQUEST
    ===================================================== */

    if ($recommendation_id <= 0) {

        $_SESSION['swal'] = [
            'icon' => 'error',
            'title' => 'Invalid Request',
            'text' => 'The approval request is invalid.'
        ];

        header("Location: approval.php");
        exit;
    }


    /* =====================================================
       GET RECOMMENDATION
    ===================================================== */

    $stmt = $conn->prepare("
        SELECT
            hr.recommendation_id,
            hr.application_id,
            hr.job_id,
            hr.status,

            a.first_name,
            a.middle_name,
            a.last_name,
            a.suffix,
            a.email,
            a.phone,
            a.location,
            a.status AS application_status,

            j.job_title,
            j.branch_id,
            j.vacancies,

            b.branch_name

        FROM hiring_recommendations hr

        INNER JOIN applications a
            ON hr.application_id = a.application_id AND a.company_id = hr.company_id

        INNER JOIN job j
            ON hr.job_id = j.job_id AND j.company_id = hr.company_id

        LEFT JOIN branch b
            ON j.branch_id = b.branch_id AND b.company_id = hr.company_id

        WHERE hr.recommendation_id = ? AND hr.company_id = ?

        LIMIT 1
    ");

    if (!$stmt) {

        $_SESSION['swal'] = [
            'icon' => 'error',
            'title' => 'Database Error',
            'text' => 'Unable to prepare the approval request.'
        ];

        header("Location: approval.php");
        exit;
    }

    $stmt->bind_param(
        "ii",
        $recommendation_id,
        $companyId
    );

    if (!$stmt->execute()) {

        $_SESSION['swal'] = [
            'icon' => 'error',
            'title' => 'Database Error',
            'text' => 'Unable to load the recommendation.'
        ];

        header("Location: approval.php");
        exit;
    }

    $recommendation =
        $stmt->get_result()->fetch_assoc();


    /* =====================================================
       CHECK RECOMMENDATION
    ===================================================== */

    if (!$recommendation) {

        $_SESSION['swal'] = [
            'icon' => 'error',
            'title' => 'Recommendation Not Found',
            'text' => 'The recommendation could not be found.'
        ];

        header("Location: approval.php");
        exit;
    }


    /* =====================================================
       CHECK RECOMMENDATION STATUS
    ===================================================== */

    if ($recommendation['status'] !== 'Pending') {

        $_SESSION['swal'] = [
            'icon' => 'warning',
            'title' => 'Already Processed',
            'text' => 'This recommendation has already been processed.'
        ];

        header("Location: approval.php");
        exit;
    }


    /* =====================================================
       CHECK INTERVIEW RECOMMENDATION

       Admin should only approve applicants that HR
       marked as Recommended.
    ===================================================== */

    $resultStmt = $conn->prepare("
        SELECT recommendation
        FROM interview_results
        WHERE result_id = (
            SELECT interview_result_id
            FROM hiring_recommendations
            WHERE recommendation_id = ? AND company_id = ?
            LIMIT 1
        )
        LIMIT 1
    ");

    if (!$resultStmt) {

        $_SESSION['swal'] = [
            'icon' => 'error',
            'title' => 'Database Error',
            'text' => 'Unable to verify the interview result.'
        ];

        header("Location: approval.php");
        exit;
    }

    $resultStmt->bind_param(
        "ii",
        $recommendation_id,
        $companyId
    );

    $resultStmt->execute();

    $interviewResult =
        $resultStmt->get_result()->fetch_assoc();


    if (
        !$interviewResult ||
        $interviewResult['recommendation'] !== 'Recommended'
    ) {

        $_SESSION['swal'] = [
            'icon' => 'warning',
            'title' => 'Applicant Cannot Be Approved',
            'text' => 'Only applicants recommended by HR after the interview can be approved.'
        ];

        header("Location: approval.php");
        exit;
    }


    /* =====================================================
       VARIABLES
    ===================================================== */

    $application_id =
        (int) $recommendation['application_id'];

    $job_id =
        (int) $recommendation['job_id'];

    $branch_id =
        (int) $recommendation['branch_id'];

    $vacancies =
        (int) $recommendation['vacancies'];


    /* =====================================================
       CHECK VACANCY

       IMPORTANT:

       Your `job.vacancies` represents the REMAINING
       vacancies.

       Therefore:

       vacancies > 0  = can approve
       vacancies = 0  = position full
    ===================================================== */

    if ($vacancies <= 0) {

        $_SESSION['swal'] = [
            'icon' => 'warning',
            'title' => 'No Vacancies Available',
            'text' => 'This job position has no remaining vacancies.'
        ];

        header("Location: approval.php");
        exit;
    }


    /* =====================================================
       CHECK IF EMPLOYEE ALREADY EXISTS

       Prevent duplicate employee records for the
       same application.
    ===================================================== */

    $existingEmployeeStmt = $conn->prepare("
        SELECT
            employee_id,
            employee_code,
            employment_status
        FROM employees
        WHERE application_id = ? AND company_id = ?
        LIMIT 1
    ");

    if (!$existingEmployeeStmt) {

        $_SESSION['swal'] = [
            'icon' => 'error',
            'title' => 'Database Error',
            'text' => 'Unable to check the employee record.'
        ];

        header("Location: approval.php");
        exit;
    }

    $existingEmployeeStmt->bind_param(
        "ii",
        $application_id,
        $companyId
    );

    $existingEmployeeStmt->execute();

    $existingEmployee =
        $existingEmployeeStmt
            ->get_result()
            ->fetch_assoc();


    if ($existingEmployee) {

        $_SESSION['swal'] = [
            'icon' => 'warning',
            'title' => 'Employee Already Exists',
            'text' =>
                'This applicant already has an employee record (' .
                $existingEmployee['employee_code'] .
                ').'
        ];

        header("Location: approval.php");
        exit;
    }


    /* =====================================================
       START TRANSACTION
    ===================================================== */

    $conn->begin_transaction();

    try {

        /* =================================================
           LOCK JOB ROW

           This prevents two admins from approving
           applicants at the same time and consuming
           the same vacancy.
        ================================================= */

        $lockJob = $conn->prepare("
            SELECT
                job_id,
                branch_id,
                vacancies
            FROM job
            WHERE job_id = ? AND company_id = ?
            FOR UPDATE
        ");

        if (!$lockJob) {
            throw new Exception(
                "Failed to lock the job position."
            );
        }

        $lockJob->bind_param(
            "ii",
            $job_id,
            $companyId
        );

        if (!$lockJob->execute()) {
            throw new Exception(
                "Failed to verify job vacancy."
            );
        }

        $lockedJob =
            $lockJob
                ->get_result()
                ->fetch_assoc();


        if (!$lockedJob) {
            throw new Exception(
                "The job position could not be found."
            );
        }


        $remainingVacancies =
            (int) $lockedJob['vacancies'];


        if ($remainingVacancies <= 0) {

            throw new Exception(
                "No vacancies are available for this position."
            );
        }


        $branch_id =
            (int) $lockedJob['branch_id'];


        /* =================================================
           CHECK AGAIN FOR DUPLICATE EMPLOYEE

           We check again inside the transaction.
        ================================================= */

        $duplicateStmt = $conn->prepare("
            SELECT employee_id
            FROM employees
            WHERE application_id = ? AND company_id = ?
            LIMIT 1
            FOR UPDATE
        ");

        if (!$duplicateStmt) {
            throw new Exception(
                "Failed to verify existing employee."
            );
        }

        $duplicateStmt->bind_param(
            "ii",
            $application_id,
            $companyId
        );

        if (!$duplicateStmt->execute()) {
            throw new Exception(
                "Failed to verify existing employee."
            );
        }

        $duplicate =
            $duplicateStmt
                ->get_result()
                ->fetch_assoc();


        if ($duplicate) {

            throw new Exception(
                "This applicant already has an employee record."
            );
        }


        /* =================================================
           UPDATE HIRING RECOMMENDATION
        ================================================= */

        $approve = $conn->prepare("
            UPDATE hiring_recommendations
            SET status = 'Approved'
            WHERE recommendation_id = ?
              AND status = 'Pending'
        ");

        if (!$approve) {
            throw new Exception(
                "Failed to prepare recommendation approval."
            );
        }

        $approve->bind_param(
            "i",
            $recommendation_id
        );

        if (!$approve->execute()) {

            throw new Exception(
                "Failed to approve the hiring recommendation."
            );
        }


        if ($approve->affected_rows !== 1) {

            throw new Exception(
                "The recommendation was already processed."
            );
        }


        /* =================================================
           UPDATE APPLICATION

           IMPORTANT:
           Application becomes HIRED, not Approved.

           Approved = recommendation decision
           Hired    = applicant successfully passed
                      Admin approval and became
                      a Pre-Employee.
        ================================================= */

        $applicationUpdate = $conn->prepare("
            UPDATE applications
            SET status = 'Hired'
            WHERE application_id = ?
        ");

        if (!$applicationUpdate) {
            throw new Exception(
                "Failed to prepare application update."
            );
        }

        $applicationUpdate->bind_param(
            "i",
            $application_id
        );

        if (!$applicationUpdate->execute()) {

            throw new Exception(
                "Failed to update applicant status."
            );
        }


        /* =================================================
           CREATE EMPLOYEE

           ONLY BASIC INFORMATION IS CREATED HERE.

           Employment setup will be completed later
           during Employee Registration.
        ================================================= */

        $insertEmployee = $conn->prepare("
            INSERT INTO employees (
                company_id,
                employee_code,
                application_id,
                first_name,
                middle_name,
                last_name,
                suffix,
                email,
                phone,
                location,
                branch_id,
                job_id,
                employment_status,
                profile_picture
            )
            VALUES (
                ?,
                NULL,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                'Pre-Employee',
                'default.png'
            )
        ");

        if (!$insertEmployee) {
            throw new Exception(
                "Failed to prepare employee creation."
            );
        }


        $insertEmployee->bind_param(
            "iissssssiii",
            $companyId,
            $application_id,
            $recommendation['first_name'],
            $recommendation['middle_name'],
            $recommendation['last_name'],
            $recommendation['suffix'],
            $recommendation['email'],
            $recommendation['phone'],
            $recommendation['location'],
            $branch_id,
            $job_id
        );


        if (!$insertEmployee->execute()) {

            throw new Exception(
                "Failed to create Pre-Employee: " .
                $insertEmployee->error
            );
        }


        $employee_id =
            (int) $conn->insert_id;


        if ($employee_id <= 0) {

            throw new Exception(
                "Invalid employee ID generated."
            );
        }


        /* =================================================
           GENERATE EMPLOYEE CODE

           Example:
           EMP202600044
        ================================================= */

        $employee_code =
            'EMP' .
            date('Y') .
            str_pad(
                $employee_id,
                5,
                '0',
                STR_PAD_LEFT
            );


        $updateEmployeeCode = $conn->prepare("
            UPDATE employees
            SET employee_code = ?
            WHERE employee_id = ?
        ");

        if (!$updateEmployeeCode) {
            throw new Exception(
                "Failed to prepare employee code."
            );
        }

        $updateEmployeeCode->bind_param(
            "si",
            $employee_code,
            $employee_id
        );

        if (!$updateEmployeeCode->execute()) {

            throw new Exception(
                "Failed to generate employee code."
            );
        }


        /* =================================================
           CREATE EMPLOYMENT RECORD

           At this stage:

           employment_status = Pre-Employee

           Salary, schedule, department, supervisor,
           official start date, etc. will be filled
           during Employee Registration.
        ================================================= */

        $insertEmployment = $conn->prepare("
            INSERT INTO employment (
                company_id,
                employee_id,
                job_id,
                branch_id,
                employment_status
            )
            VALUES (
                ?,
                ?,
                ?,
                ?,
                'Pre-Employee'
            )
        ");

        if (!$insertEmployment) {
            throw new Exception(
                "Failed to prepare employment record."
            );
        }

        $insertEmployment->bind_param(
            "iiii",
            $companyId,
            $employee_id,
            $job_id,
            $branch_id
        );

        if (!$insertEmployment->execute()) {

            throw new Exception(
                "Failed to create employment record: " .
                $insertEmployment->error
            );
        }


        /* =================================================
           DECREMENT REMAINING VACANCY

           Example:

           2 vacancies
              ↓
           approve applicant
              ↓
           1 vacancy
        ================================================= */

        $updateVacancy = $conn->prepare("
            UPDATE job
            SET vacancies = vacancies - 1
            WHERE job_id = ?
              AND vacancies > 0
        ");

        if (!$updateVacancy) {
            throw new Exception(
                "Failed to prepare vacancy update."
            );
        }

        $updateVacancy->bind_param(
            "i",
            $job_id
        );

        if (!$updateVacancy->execute()) {

            throw new Exception(
                "Failed to update job vacancy."
            );
        }


        if ($updateVacancy->affected_rows !== 1) {

            throw new Exception(
                "The vacancy could not be updated."
            );
        }


        /* =================================================
           COMMIT EVERYTHING
        ================================================= */

        $conn->commit();


        /* =================================================
           SUCCESS
        ================================================= */

        $_SESSION['swal'] = [
            'icon' => 'success',
            'title' => 'Applicant Approved',
            'text' =>
                $recommendation['first_name'] .
                ' has been approved and registered as a Pre-Employee.'
        ];

        header("Location: approval.php");
        exit;
    } catch (Exception $e) {

        /* =================================================
           ROLLBACK EVERYTHING
        ================================================= */

        $conn->rollback();


        $_SESSION['swal'] = [
            'icon' => 'error',
            'title' => 'Approval Failed',
            'text' => $e->getMessage()
        ];

        header("Location: approval.php");
        exit;
    }
}


/* =========================================================
   ADMIN REJECT RECOMMENDATION
========================================================= */

if (isset($_POST['rejectRecommendation'])) {

    $recommendation_id = (int) ($_POST['recommendation_id'] ?? 0);

    $rejection_reason = trim(
        $_POST['rejection_reason'] ?? ''
    );


    /* =====================================================
       VALIDATE RECOMMENDATION ID
    ===================================================== */

    if ($recommendation_id <= 0) {

        echo "
        <script>
        document.addEventListener('DOMContentLoaded', function() {

            Swal.fire({
                icon: 'error',
                title: 'Invalid Request',
                text: 'The rejection request is invalid.',
                confirmButtonColor: '#dc3545'
            });

        });
        </script>
        ";

        exit;
    }


    /* =====================================================
       VALIDATE REJECTION REASON
    ===================================================== */

    if ($rejection_reason === '') {

        echo "
        <script>
        document.addEventListener('DOMContentLoaded', function() {

            Swal.fire({
                icon: 'warning',
                title: 'Rejection Reason Required',
                text: 'Please provide a reason for rejecting this applicant.',
                confirmButtonColor: '#dc3545'
            });

        });
        </script>
        ";

        exit;
    }


    if (strlen($rejection_reason) < 5) {

        echo "
        <script>
        document.addEventListener('DOMContentLoaded', function() {

            Swal.fire({
                icon: 'warning',
                title: 'Invalid Reason',
                text: 'The rejection reason must be at least 5 characters.',
                confirmButtonColor: '#dc3545'
            });

        });
        </script>
        ";

        exit;
    }


    /* =====================================================
       GET RECOMMENDATION
    ===================================================== */

    $stmt = $conn->prepare("
        SELECT
            recommendation_id,
            application_id,
            status
        FROM hiring_recommendations
        WHERE recommendation_id = ? AND company_id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        die("Prepare failed: " . $conn->error);
    }

    $stmt->bind_param(
        "ii",
        $recommendation_id,
        $companyId
    );

    if (!$stmt->execute()) {
        die("Execute failed: " . $stmt->error);
    }

    $recommendation =
        $stmt->get_result()->fetch_assoc();


    /* =====================================================
       CHECK RECOMMENDATION
    ===================================================== */

    if (!$recommendation) {

        echo "
        <script>
        document.addEventListener('DOMContentLoaded', function() {

            Swal.fire({
                icon: 'error',
                title: 'Recommendation Not Found',
                text: 'The recommendation could not be found.',
                confirmButtonColor: '#dc3545'
            });

        });
        </script>
        ";

        exit;
    }


    /* =====================================================
       CHECK STATUS
    ===================================================== */

    if ($recommendation['status'] !== 'Pending') {

        echo "
        <script>
        document.addEventListener('DOMContentLoaded', function() {

            Swal.fire({
                icon: 'warning',
                title: 'Already Processed',
                text: 'This recommendation has already been processed.',
                confirmButtonColor: '#f39c12'
            });

        });
        </script>
        ";

        exit;
    }


    $application_id =
        (int) $recommendation['application_id'];


    /* =====================================================
       TRANSACTION
    ===================================================== */

    $conn->begin_transaction();

    try {


        /* =================================================
           REJECT RECOMMENDATION
        ================================================= */

        $reject = $conn->prepare("
            UPDATE hiring_recommendations
            SET
                status = 'Rejected',
                rejection_reason = ?
            WHERE recommendation_id = ?
        ");

        if (!$reject) {

            throw new Exception(
                "Prepare rejection failed: " . $conn->error
            );
        }


        $reject->bind_param(
            "si",
            $rejection_reason,
            $recommendation_id
        );


        if (!$reject->execute()) {

            throw new Exception(
                "Failed to reject recommendation: " .
                $reject->error
            );
        }


        /* =================================================
           UPDATE APPLICATION
        ================================================= */

        $applicationUpdate = $conn->prepare("
            UPDATE applications
            SET
                status = 'Rejected',
                rejected_by = 'Admin',
                rejection_reason = ?,
                rejected_at = NOW()
            WHERE application_id = ?
        ");


        if (!$applicationUpdate) {

            throw new Exception(
                "Prepare application update failed: " .
                $conn->error
            );
        }

        $applicationUpdate->bind_param(
            "si",
            $rejection_reason,
            $application_id
        );


        if (!$applicationUpdate->execute()) {

            throw new Exception(
                "Failed to update applicant status: " .
                $applicationUpdate->error
            );
        }


        /* =================================================
           COMMIT
        ================================================= */

        $conn->commit();


        /* =================================================
   SUCCESS
================================================= */

        $_SESSION['swal'] = [
            'icon' => 'success',
            'title' => 'Applicant Rejected',
            'text' => 'The applicant has been rejected successfully.'
        ];

        header("Location: approval.php");
        exit;
    } catch (Exception $e) {


        /* =================================================
           ROLLBACK
        ================================================= */

        $conn->rollback();


        /* =================================================
           SHOW ACTUAL ERROR
        ================================================= */

        echo "
        <script>
        document.addEventListener('DOMContentLoaded', function() {

            Swal.fire({
                icon: 'error',
                title: 'Rejection Failed',
                html: " . json_encode(
            "Unable to reject the applicant.<br><br>" .
            $e->getMessage()
        ) . ",
                confirmButtonColor: '#dc3545'
            });

        });
        </script>
        ";

        exit;
    }
}


// =====================================================
// FETCH PENDING RECRUITMENT RECOMMENDATIONS
// =====================================================

$sql = "
SELECT
    hr.recommendation_id,
    hr.application_id,
    hr.job_id ,
    hr.interview_result_id,
    hr.hr_comments,
    hr.status,
    hr.recommended_at,

    a.first_name,
    a.middle_name,
    a.last_name,
    a.suffix,
    a.email,
    a.phone,
    a.location,

    j.job_title,
    j.vacancies,

    b.branch_name,

    ir.recommendation,

    u.fullname AS hr_name

FROM hiring_recommendations hr

INNER JOIN applications a
    ON hr.application_id = a.application_id AND a.company_id = hr.company_id

INNER JOIN job j
    ON hr.job_id = j.job_id AND j.company_id = hr.company_id

LEFT JOIN branch b
    ON j.branch_id = b.branch_id AND b.company_id = hr.company_id

LEFT JOIN interview_results ir
    ON hr.interview_result_id = ir.result_id AND ir.company_id = hr.company_id

LEFT JOIN users u
    ON hr.recommended_by = u.user_id

WHERE hr.status = 'Pending' AND hr.company_id = " . (int) $companyId . "

ORDER BY hr.recommended_at DESC
";

$result = mysqli_query($conn, $sql);

if (!$result) {
    die("Recruitment Approval Query Error: " . mysqli_error($conn));
}


include('admin_header.php');

?>

<style>
    #approvalTable {
        width: 100% !important;
    }
</style>

<div class="container-fluid py-4">

    <!-- PAGE HEADER -->
    <div class="mb-4">

        <h1 class="fw-bold mb-1" style="color:#00224c;">
            Approval Management
        </h1>

        <p class="text-muted mb-0">
            Review and approve requests submitted by HR.
        </p>

    </div>


    <!-- RECRUITMENT APPROVAL -->
    <div class="card border-0 shadow-sm rounded-4">

        <div class="card-body p-4">

            <div class="d-flex justify-content-between align-items-center mb-4">

                <div>

                    <h5 class="fw-bold mb-1">
                        Recruitment Approval
                    </h5>

                    <small class="text-muted">
                        Applicants recommended by HR
                    </small>

                </div>

                <span class="badge rounded-pill bg-warning text-dark">
                    Pending Approval
                </span>

            </div>


            <div class="table-responsive">

                <table id="approvalTable" class="table table-hover align-middle">

                    <thead class="table-light">

                        <tr>

                            <th>Applicant</th>

                            <th>Position</th>

                            <th>Branch</th>

                            <th>Interview</th>

                            <th>HR Remarks</th>

                            <th>Status</th>

                            <th class="text-center">
                                Action
                            </th>

                        </tr>

                    </thead>


                    <tbody>

                        <?php if (mysqli_num_rows($result) > 0): ?>

                            <?php while ($row = mysqli_fetch_assoc($result)): ?>

                                <?php

                                $nameParts = [];

                                if (!empty($row['first_name'])) {
                                    $nameParts[] = $row['first_name'];
                                }

                                if (!empty($row['middle_name'])) {
                                    $nameParts[] = $row['middle_name'];
                                }

                                if (!empty($row['last_name'])) {
                                    $nameParts[] = $row['last_name'];
                                }

                                if (!empty($row['suffix'])) {
                                    $nameParts[] = $row['suffix'];
                                }

                                $fullName = implode(' ', $nameParts);

                                ?>

                                <tr>

                                    <!-- APPLICANT -->

                                    <td>

                                        <div class="fw-semibold">

                                            <?= htmlspecialchars($fullName) ?>

                                        </div>

                                        <small class="text-muted">

                                            <?= htmlspecialchars($row['email']) ?>

                                        </small>

                                    </td>


                                    <!-- POSITION -->

                                    <td>

                                        <?= htmlspecialchars(
                                            $row['job_title']
                                        ) ?>

                                    </td>


                                    <!-- BRANCH -->

                                    <td>

                                        <?= htmlspecialchars(
                                            $row['branch_name'] ?? '-'
                                        ) ?>

                                    </td>


                                    <!-- INTERVIEW -->

                                    <td>

                                        <?php if (
                                            $row['recommendation'] === 'Recommended'
                                        ): ?>

                                            <span class="badge bg-success">

                                                <i class="bi bi-check-circle me-1"></i>

                                                Recommended

                                            </span>

                                        <?php else: ?>

                                            <span class="badge bg-danger">

                                                Not Recommended

                                            </span>

                                        <?php endif; ?>

                                    </td>


                                    <!-- HR REMARKS -->

                                    <td>

                                        <span class="text-muted">

                                            <?= !empty($row['hr_comments'])
                                                ? htmlspecialchars($row['hr_comments'])
                                                : '-'
                                                ?>

                                        </span>

                                    </td>


                                    <!-- STATUS -->

                                    <td>

                                        <span class="badge bg-warning text-dark">

                                            <?= htmlspecialchars(
                                                $row['status']
                                            ) ?>

                                        </span>

                                    </td>


                                    <!-- ACTION -->

                                    <td class="text-center">

                                        <button type="button" class="btn btn-primary btn-sm rounded-pill viewBtn"
                                            data-id="<?= $row['recommendation_id'] ?>" data-name="<?= htmlspecialchars(
                                                  $fullName,
                                                  ENT_QUOTES
                                              ) ?>" data-email="<?= htmlspecialchars(
                                                   $row['email'],
                                                   ENT_QUOTES
                                               ) ?>" data-phone="<?= htmlspecialchars(
                                                    $row['phone'],
                                                    ENT_QUOTES
                                                ) ?>" data-location="<?= htmlspecialchars(
                                                     $row['location'],
                                                     ENT_QUOTES
                                                 ) ?>" data-position="<?= htmlspecialchars(
                                                      $row['job_title'],
                                                      ENT_QUOTES
                                                  ) ?>" data-branch="<?= htmlspecialchars(
                                                       $row['branch_name'] ?? '-',
                                                       ENT_QUOTES
                                                   ) ?>" data-result="<?= htmlspecialchars(
                                                        $row['recommendation'] ?? '-',
                                                        ENT_QUOTES
                                                    ) ?>" data-comment="<?= htmlspecialchars(
                                                         $row['hr_comments'] ?? '',
                                                         ENT_QUOTES
                                                     ) ?>" data-hr="<?= htmlspecialchars(
                                                          $row['hr_name'] ?? '-',
                                                          ENT_QUOTES
                                                      ) ?>" data-vacancies="<?= (int) $row['vacancies'] ?>">

                                            <i class="bi bi-eye me-1"></i>

                                            Review

                                        </button>

                                    </td>

                                </tr>

                            <?php endwhile; ?>

                        <?php else: ?>

                            <tr>

                                <td colspan="7" class="text-center py-5">

                                    <i class="bi bi-inbox fs-1 text-muted"></i>

                                    <div class="mt-2 text-muted">

                                        No pending recruitment approvals.

                                    </div>

                                </td>

                            </tr>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </div>

    </div>

</div>

<!-- ==========================================
     RECRUITMENT REVIEW MODAL
========================================== -->

<div class="modal fade" id="reviewModal" tabindex="-1" aria-hidden="true">

    <div class="modal-dialog modal-lg modal-dialog-centered">

        <div class="modal-content border-0 rounded-4 shadow">

            <!-- HEADER -->
            <div class="modal-header border-0 px-4 pt-4">

                <div>

                    <h4 class="fw-bold mb-1" style="color:#00224c;">
                        Recruitment Review
                    </h4>

                    <small class="text-muted">
                        Review the applicant recommendation submitted by HR.
                    </small>

                </div>

                <button type="button" class="btn-close" data-bs-dismiss="modal">
                </button>

            </div>


            <!-- BODY -->
            <div class="modal-body px-4">

                <!-- Applicant -->
                <div class="card border-0 bg-light rounded-4 mb-4">

                    <div class="card-body p-4">

                        <div class="d-flex align-items-center">

                            <div class="rounded-circle d-flex align-items-center justify-content-center me-3" style="
                                    width:65px;
                                    height:65px;
                                    background:#00224c;
                                    color:white;
                                ">

                                <i class="bi bi-person-fill fs-3"></i>

                            </div>

                            <div>

                                <h4 class="fw-bold mb-1" id="reviewName">
                                </h4>

                                <div class="text-muted" id="reviewEmail">
                                </div>

                            </div>

                        </div>

                    </div>

                </div>


                <!-- Applicant Information -->
                <h6 class="fw-bold mb-3" style="color:#00224c;">

                    Applicant Information

                </h6>

                <div class="row g-3 mb-4">

                    <div class="col-md-6">

                        <div class="border rounded-3 p-3">

                            <small class="text-muted d-block">
                                Contact
                            </small>

                            <span class="fw-semibold" id="reviewPhone">
                            </span>

                        </div>

                    </div>


                    <div class="col-md-6">

                        <div class="border rounded-3 p-3">

                            <small class="text-muted d-block">
                                Location
                            </small>

                            <span class="fw-semibold" id="reviewLocation">
                            </span>

                        </div>

                    </div>


                    <div class="col-md-6">

                        <div class="border rounded-3 p-3">

                            <small class="text-muted d-block">
                                Applied Position
                            </small>

                            <span class="fw-semibold" id="reviewPosition">
                            </span>

                        </div>

                    </div>


                    <div class="col-md-6">

                        <div class="border rounded-3 p-3">

                            <small class="text-muted d-block">
                                Branch
                            </small>

                            <span class="fw-semibold" id="reviewBranch">
                            </span>

                        </div>

                    </div>

                </div>


                <!-- HR Recommendation -->
                <h6 class="fw-bold mb-3" style="color:#00224c;">

                    HR Recommendation

                </h6>

                <div class="card border rounded-4 mb-3">

                    <div class="card-body p-4">

                        <div class="row g-3">

                            <div class="col-md-6">

                                <small class="text-muted d-block mb-1">
                                    Interview Result
                                </small>

                                <span id="reviewResult"></span>

                            </div>


                            <div class="col-md-6">

                                <small class="text-muted d-block mb-1">
                                    Recommended By
                                </small>

                                <span class="fw-semibold" id="reviewHR">
                                </span>

                            </div>


                            <div class="col-12">

                                <small class="text-muted d-block mb-1">
                                    HR Remarks
                                </small>

                                <div class="bg-light rounded-3 p-3" id="reviewComment">
                                </div>

                            </div>

                        </div>

                    </div>

                </div>


                <!-- Vacancy -->
                <div class="alert alert-light border rounded-3 d-flex align-items-center mb-4">

                    <i class="bi bi-briefcase-fill me-2 text-primary"></i>

                    <div>

                        <strong>Available Vacancies:</strong>

                        <span id="reviewVacancies" class="fw-bold">
                        </span>

                    </div>

                </div>


                <!-- Confirmation -->
                <div class="form-check border rounded-3 p-3 mb-3">

                    <input class="form-check-input ms-0 me-2" type="checkbox" id="approveConfirmation">

                    <label class="form-check-label" for="approveConfirmation">

                        I confirm that I have reviewed the HR recommendation
                        and approve this applicant to proceed to
                        Employee Onboarding as a Pre-Employee.

                    </label>

                </div>


                <!-- Hidden Form -->
                <form method="POST" action="" id="approvalForm">

                    <input type="hidden" name="recommendation_id" id="reviewRecommendationId">

                    <input type="hidden" name="approve" value="1">

                </form>


                <!-- ACTIONS -->
                <div class="d-flex gap-2 mt-4">

                    <button type="button" class="btn btn-light border rounded-pill px-4" data-bs-dismiss="modal">

                        Cancel

                    </button>

                    <button type="button" class="btn btn-danger rounded-pill px-4 ms-auto" id="rejectApplicantBtn">

                        <i class="bi bi-x-circle me-1"></i>

                        Reject

                    </button>

                    <button type="button" class="btn btn-success rounded-pill px-4" id="approveRecommendationBtn"
                        disabled>

                        <i class="bi bi-check-circle me-1"></i>

                        Approve Applicant

                    </button>

                </div>

            </div>

        </div>

    </div>

</div>


<!-- =========================================================
     DATATABLE
========================================================= -->





<script>
    document.addEventListener("DOMContentLoaded", function () {


        /* =====================================================
           DATATABLE
        ===================================================== */

        if (typeof DataTable !== "undefined") {

            new DataTable("#approvalTable", {

                pageLength: 8,

                lengthChange: false,

                searching: true,

                ordering: true,

                paging: true,

                info: true,

                order: [],

                columnDefs: [

                    {
                        orderable: false,
                        targets: 6
                    }

                ],

                language: {

                    search: "",

                    searchPlaceholder: "Search applicant, position, branch...",

                    info: "Showing _END_ of _TOTAL_",

                    infoEmpty: "Showing 0 of 0",

                    zeroRecords: "No pending recruitment approvals",

                    emptyTable: "No pending recruitment approvals",

                    paginate: {

                        first: "«",
                        previous: "‹",
                        next: "›",
                        last: "»"

                    }

                }

            });

        }


        /* =====================================================
           MODAL
        ===================================================== */

        const reviewModalElement =
            document.getElementById("reviewModal");

        const reviewModal =
            new bootstrap.Modal(reviewModalElement);


        /* =====================================================
           OPEN REVIEW MODAL
        ===================================================== */

        document.querySelectorAll(".viewBtn").forEach(function (button) {

            button.addEventListener("click", function () {

                const recommendationId =
                    this.dataset.id || "";

                const name =
                    this.dataset.name || "-";

                const email =
                    this.dataset.email || "-";

                const phone =
                    this.dataset.phone || "-";

                const location =
                    this.dataset.location || "-";

                const position =
                    this.dataset.position || "-";

                const branch =
                    this.dataset.branch || "-";

                const result =
                    this.dataset.result || "-";

                const comment =
                    this.dataset.comment || "-";

                const hr =
                    this.dataset.hr || "-";

                const vacancies =
                    this.dataset.vacancies || "0";


                /* =================================================
                   DISPLAY APPLICANT INFORMATION
                ================================================= */

                document.getElementById("reviewName").textContent =
                    name;

                document.getElementById("reviewEmail").textContent =
                    email;

                document.getElementById("reviewPhone").textContent =
                    phone;

                document.getElementById("reviewLocation").textContent =
                    location;

                document.getElementById("reviewPosition").textContent =
                    position;

                document.getElementById("reviewBranch").textContent =
                    branch;

                document.getElementById("reviewHR").textContent =
                    hr;

                document.getElementById("reviewComment").textContent =
                    comment;

                document.getElementById("reviewVacancies").textContent =
                    vacancies;


                /* =================================================
                   INTERVIEW RESULT
                ================================================= */

                const resultElement =
                    document.getElementById("reviewResult");

                if (result === "Recommended") {

                    resultElement.innerHTML = `
                    <span class="badge bg-success">
                        <i class="bi bi-check-circle me-1"></i>
                        Recommended
                    </span>
                `;

                } else {

                    resultElement.innerHTML = `
                    <span class="badge bg-danger">
                        <i class="bi bi-x-circle me-1"></i>
                        ${result}
                    </span>
                `;

                }


                /* =================================================
                   SET RECOMMENDATION ID
                ================================================= */

                document.getElementById(
                    "reviewRecommendationId"
                ).value = recommendationId;


                /* =================================================
                   RESET APPROVAL CHECKBOX
                ================================================= */

                document.getElementById(
                    "approveConfirmation"
                ).checked = false;


                document.getElementById(
                    "approveRecommendationBtn"
                ).disabled = true;


                /* =================================================
                   SHOW MODAL
                ================================================= */

                reviewModal.show();

            });

        });


        /* =====================================================
           APPROVAL CHECKBOX
        ===================================================== */

        document.getElementById(
            "approveConfirmation"
        ).addEventListener("change", function () {

            document.getElementById(
                "approveRecommendationBtn"
            ).disabled = !this.checked;

        });


        /* =====================================================
           APPROVE APPLICANT
        ===================================================== */

        document.getElementById(
            "approveRecommendationBtn"
        ).addEventListener("click", function () {

            const recommendationId =
                document.getElementById(
                    "reviewRecommendationId"
                ).value;


            const vacancies =
                parseInt(
                    document.getElementById(
                        "reviewVacancies"
                    ).textContent
                ) || 0;


            /* =================================================
               CHECK VACANCY
            ================================================= */

            if (vacancies <= 0) {

                Swal.fire({

                    icon: "warning",

                    title: "No Vacancies Available",

                    text: "This job position is already full. " +
                        "The applicant cannot be approved.",

                    confirmButtonColor: "#f39c12"

                });

                return;
            }


            /* =================================================
               CONFIRM APPROVAL
            ================================================= */

            Swal.fire({

                icon: "question",

                title: "Approve Applicant?",

                html: `
                <p class="mb-2">
                    This applicant will be approved and
                    converted into a
                    <strong>Pre-Employee</strong>.
                </p>

                <small class="text-muted">
                    Employment details such as salary and
                    starting date will be completed later
                    during Employee Onboarding.
                </small>
            `,

                showCancelButton: true,

                confirmButtonText: "Yes, Approve",

                cancelButtonText: "Cancel",

                confirmButtonColor: "#198754",

                cancelButtonColor: "#6c757d"

            }).then(function (result) {

                if (!result.isConfirmed) {
                    return;
                }


                /* =================================================
                   CREATE APPROVAL FORM
                ================================================= */

                const form =
                    document.createElement("form");

                form.method = "POST";

                form.action = "approval.php";


                /* Action */

                const action =
                    document.createElement("input");

                action.type = "hidden";

                action.name = "approveRecommendation";

                action.value = "1";


                /* Recommendation ID */

                const id =
                    document.createElement("input");

                id.type = "hidden";

                id.name = "recommendation_id";

                id.value = recommendationId;


                form.appendChild(action);

                form.appendChild(id);

                document.body.appendChild(form);

                form.submit();

            });

        });


        /* =====================================================
   REJECT APPLICANT
===================================================== */

        document.getElementById("rejectApplicantBtn").addEventListener("click", function () {

            const recommendationId =
                document.getElementById("reviewRecommendationId").value;


            /* =====================================================
               HIDE BOOTSTRAP REVIEW MODAL FIRST
               This prevents Bootstrap focus trap from blocking
               the SweetAlert textarea.
            ===================================================== */

            reviewModal.hide();


            /* =====================================================
               WAIT FOR BOOTSTRAP MODAL TO FULLY HIDE
            ===================================================== */

            setTimeout(function () {

                Swal.fire({

                    icon: "warning",

                    title: "Reject Applicant?",

                    text: "Please provide the reason for rejecting this applicant.",

                    input: "textarea",

                    inputPlaceholder: "Enter rejection reason...",

                    inputAttributes: {
                        "aria-label": "Rejection reason",
                        "maxlength": "1000"
                    },

                    showCancelButton: true,

                    confirmButtonText: "Reject Applicant",

                    cancelButtonText: "Cancel",

                    confirmButtonColor: "#dc3545",

                    cancelButtonColor: "#6c757d",

                    reverseButtons: true,

                    allowOutsideClick: false,

                    allowEscapeKey: true,

                    focusConfirm: false,

                    /* =================================================
                       AUTO FOCUS TEXTAREA
                    ================================================= */

                    didOpen: function () {

                        const textarea =
                            Swal.getInput();

                        if (textarea) {

                            textarea.focus();

                        }

                    },

                    /* =================================================
                       VALIDATE REASON
                    ================================================= */

                    inputValidator: function (value) {

                        if (!value || value.trim() === "") {

                            return "Please provide a reason for rejection.";

                        }

                        if (value.trim().length < 5) {

                            return "Rejection reason must be at least 5 characters.";

                        }

                        return null;

                    }

                }).then(function (result) {


                    /* =================================================
                       CANCEL
                    ================================================= */

                    if (!result.isConfirmed) {

                        reviewModal.show();

                        return;

                    }


                    /* =================================================
                       GET REASON
                    ================================================= */

                    const rejectionReason =
                        result.value.trim();


                    /* =================================================
                       CREATE FORM
                    ================================================= */

                    const form =
                        document.createElement("form");

                    form.method = "POST";

                    form.action = "approval.php";


                    /* =================================================
                       RECOMMENDATION ID
                    ================================================= */

                    const id =
                        document.createElement("input");

                    id.type = "hidden";

                    id.name = "recommendation_id";

                    id.value = recommendationId;


                    /* =================================================
                       REJECT ACTION
                    ================================================= */

                    const action =
                        document.createElement("input");

                    action.type = "hidden";

                    action.name = "rejectRecommendation";

                    action.value = "1";


                    /* =================================================
                       REJECTION REASON
                    ================================================= */

                    const reason =
                        document.createElement("input");

                    reason.type = "hidden";

                    reason.name = "rejection_reason";

                    reason.value = rejectionReason;


                    /* =================================================
                       APPEND FORM
                    ================================================= */

                    form.appendChild(id);

                    form.appendChild(action);

                    form.appendChild(reason);


                    document.body.appendChild(form);


                    /* =================================================
                       SUBMIT
                    ================================================= */

                    form.submit();

                });

            }, 300);

        });

    });
</script>

<?php include('admin_footer.php'); ?>