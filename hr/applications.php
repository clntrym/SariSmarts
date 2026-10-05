<?php

require_once("../init.php");
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
require_once("../accounts/send_interview.php");

/*
| Narrowing a dropdown decides what is offered, not what can be submitted.
| Both the supervisor and the interviewer arrive as a plain user_id in the
| POST, so ownership is confirmed here before either is stored.
*/
if (!function_exists('hrUserBelongsToCompany')) {
    function hrUserBelongsToCompany(mysqli $conn, int $userId, int $companyId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        $stmt = $conn->prepare("
            SELECT 1 FROM users
            WHERE user_id = ? AND company_id = ? AND status = 'active'
            LIMIT 1
        ");
        $stmt->bind_param("ii", $userId, $companyId);
        $stmt->execute();
        $ok = $stmt->get_result()->num_rows === 1;
        $stmt->close();

        return $ok;
    }
}

/*
|--------------------------------------------------------------------------
| RESULT NOTICE
|--------------------------------------------------------------------------
|
| The interview-result handlers answered with nothing but a
| <script>Swal.fire(...)</script> tag and no HTML at all. That is a blank
| white page until SweetAlert loads from its CDN -- and if the CDN is blocked,
| by a shielded browser or an offline machine, it stays blank. Saved or
| refused, the screen looked identical: empty.
|
| The dialog still appears where SweetAlert is available, but the page now
| carries readable HTML underneath, so a blocked CDN degrades to a plain
| message and a link back rather than a white void.
*/
if (!function_exists('applicationResultNotice')) {
    function applicationResultNotice(string $icon, string $title, string $text, string $backUrl): void
    {
        $colours = [
            'success' => '#198754',
            'warning' => '#fd7e14',
            'error' => '#dc3545',
        ];
        $colour = $colours[$icon] ?? '#00224c';

        $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $safeText = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        $safeBack = htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8');

        $jsTitle = json_encode($title);
        $jsText = json_encode($text);
        $jsIcon = json_encode($icon);
        $jsBack = json_encode($backUrl);

        echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$safeTitle}</title>
<link rel="stylesheet" href="../bootstrap-5.3.8-dist/css/bootstrap.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body style="background:#f5f7fb;">

<div class="container" style="max-width:520px;margin:14vh auto;">
    <div class="card border-0 shadow-sm" style="border-radius:16px;">
        <div class="card-body text-center p-5">
            <h4 class="fw-bold mb-2" style="color:{$colour};">{$safeTitle}</h4>
            <p class="text-muted mb-4">{$safeText}</p>
            <a href="{$safeBack}" class="btn text-white px-4" style="background:#00224c;">
                Back to Applications
            </a>
        </div>
    </div>
</div>

<script>
    if (window.Swal) {
        Swal.fire({
            icon: {$jsIcon},
            title: {$jsTitle},
            text: {$jsText},
            confirmButtonColor: "#00224c",
            allowOutsideClick: false
        }).then(function () {
            window.location.replace({$jsBack});
        });
    }
</script>

</body>
</html>
HTML;

        exit;
    }
}

require_once("../accounts/send_rejection.php");

if (isset($_POST['scheduleInterview'])) {

    $application_id = (int) ($_POST['application_id'] ?? 0);
    $job_id = (int) ($_POST['job_id'] ?? 0);

    /* ==========================================
       CHECK IF RECOMMENDATION ALREADY EXISTS
       ========================================== */

    $checkRecommendation = $conn->prepare("
        SELECT recommendation
        FROM interview_results
        WHERE application_id = ? AND company_id = ?
        AND recommendation IS NOT NULL
        AND recommendation != ''
        LIMIT 1
    ");

    $checkRecommendation->bind_param(
        "ii",
        $application_id,
        $companyId
    );

    $checkRecommendation->execute();

    $recommendationResult = $checkRecommendation->get_result();

    if ($recommendationResult->num_rows > 0) {

        echo "
        <script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>

        <script>
        document.addEventListener('DOMContentLoaded', function () {

            Swal.fire({
                icon: 'warning',
                title: 'Interview Locked',
                text: 'A recommendation has already been sent to Admin. You cannot schedule another interview for this applicant.',
                confirmButtonColor: '#0d6efd'
            }).then(function () {

                window.location.href =
                    'applications.php?job_id=" . $job_id . "';

            });

        });
        </script>
        ";

        exit;
    }


    /* ==========================================
       GET INTERVIEW DATA
       ========================================== */

    $date = trim($_POST['interview_date'] ?? '');
    $time = trim($_POST['interview_time'] ?? '');
    $type = trim($_POST['interview_type'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $meeting_link = trim($_POST['meeting_link'] ?? '');
    $interviewer = (int) ($_POST['interviewer_id'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');


    /* ==========================================
       CHECK REQUIRED FIELDS
       ========================================== */

    if (
        empty($date) ||
        empty($time) ||
        empty($type) ||
        $interviewer <= 0 ||
        !hrUserBelongsToCompany($conn, $interviewer, $companyId)
    ) {

        echo "
        <script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>

        <script>
        document.addEventListener('DOMContentLoaded', function () {

            Swal.fire({
                icon: 'warning',
                title: 'Incomplete Information',
                text: 'Please complete all required interview fields.',
                confirmButtonColor: '#0d6efd'
            });

        });
        </script>
        ";

        exit;
    }


    /* ==========================================
       CHECK IF APPLICANT ALREADY HAS INTERVIEW
       ========================================== */

    $check = $conn->prepare("
        SELECT interview_id
        FROM interviews
        WHERE application_id = ? AND company_id = ?
        LIMIT 1
    ");

    $check->bind_param(
        "ii",
        $application_id,
        $companyId
    );

    $check->execute();
    $check->store_result();


    if ($check->num_rows > 0) {

        echo "
        <script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>

        <script>
        document.addEventListener('DOMContentLoaded', function () {

            Swal.fire({
                icon: 'warning',
                title: 'Already Scheduled',
                text: 'This applicant already has an interview schedule.',
                confirmButtonColor: '#0d6efd'
            }).then(function () {

                window.location.href =
                    'applications.php?job_id=" . $job_id . "';

            });

        });
        </script>
        ";

        exit;
    }


    /* ==========================================
       INSERT INTERVIEW
       ========================================== */

    $stmt = $conn->prepare("
        INSERT INTO interviews
        (
            company_id,
            application_id,
            job_id,
            interview_date,
            interview_time,
            interview_type,
            location,
            meeting_link,
            interviewer_id,
            notes
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->bind_param(
        "iiisssssis",
        $companyId,
        $application_id,
        $job_id,
        $date,
        $time,
        $type,
        $location,
        $meeting_link,
        $interviewer,
        $notes
    );


    if ($stmt->execute()) {
        /* ==========================================
           UPDATE APPLICATION STATUS
           ========================================== */

        $updateApplication = $conn->prepare("
            UPDATE applications
            SET status = 'Interview'
            WHERE application_id = ?
        ");

        $updateApplication->bind_param(
            "i",
            $application_id
        );

        $updateApplication->execute();
        /* ==========================================
           INCREASE INTERVIEW COUNT
           ========================================== */

        $updateJob = $conn->prepare("
            UPDATE job
            SET interviews = interviews + 1
            WHERE job_id = ?
        ");

        $updateJob->bind_param(
            "i",
            $job_id
        );

        $updateJob->execute();


        /* ==========================================
           SEND INTERVIEW EMAIL
           ========================================== */

        if (isset($_POST['send_email'])) {

            $get = $conn->prepare("
                SELECT
                    CONCAT_WS(
                        ' ',
                        a.first_name,
                        NULLIF(a.middle_name, ''),
                        a.last_name,
                        NULLIF(a.suffix, '')
                    ) AS full_name,
                    a.email,
                    j.job_title,
                    b.branch_name
                FROM applications a
                JOIN job j
                    ON a.job_id = j.job_id
                JOIN branch b
                    ON j.branch_id = b.branch_id
                WHERE a.application_id = ? AND a.company_id = ?
                LIMIT 1
            ");

            $get->bind_param(
                "ii",
                $application_id,
                $companyId
            );

            $get->execute();

            $app = $get->get_result()->fetch_assoc();


            if ($app) {

                sendInterviewInvitation(
                    $app['email'],
                    $app['full_name'],
                    $app['job_title'],
                    $app['branch_name'],
                    $date,
                    $time,
                    $location
                );
            }
        }


        /* ==========================================
           SUCCESS
           ========================================== */

        echo "
        <script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>

        <script>
        document.addEventListener('DOMContentLoaded', function () {

            Swal.fire({
                icon: 'success',
                title: 'Interview Scheduled!',
                text: 'The applicant has been scheduled successfully.',
                confirmButtonColor: '#0d6efd'
            }).then(function () {

                window.location.href =
                    'applications.php?job_id=" . $job_id . "';

            });

        });
        </script>
        ";

        exit;
    } else {

        echo "
        <script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>

        <script>
        document.addEventListener('DOMContentLoaded', function () {

            Swal.fire({
                icon: 'error',
                title: 'Schedule Failed',
                text: 'Unable to schedule the interview. Please try again.',
                confirmButtonColor: '#dc3545'
            });

        });
        </script>
        ";

        exit;
    }
}

if (isset($_POST['saveResult'])) {

    // ==========================================
    // GET FORM DATA
    // ==========================================

    $application_id = (int) ($_POST['result_application_id'] ?? 0);
    $interview_id = (int) ($_POST['result_interview_id'] ?? 0);
    $job_id = (int) ($_POST['job_id'] ?? 0);

    // IMPORTANT:
    // Huwag gawing default 0 kapag walang score.
    $score = isset($_POST['score'])
        ? (int) $_POST['score']
        : -1;

    $remarks = trim($_POST['remarks'] ?? '');


    // ==========================================
    // VALIDATE BASIC INFORMATION
    // ==========================================

    if (
        $application_id <= 0 ||
        $interview_id <= 0 ||
        $job_id <= 0
    ) {

        applicationResultNotice('error', 'Invalid Request', 'Missing application, interview, or job information.', "applications.php?job_id={$job_id}");
    }


    // ==========================================
    // VALIDATE SCORE
    // ==========================================

    if ($score < 0 || $score > 100) {

        applicationResultNotice('warning', 'Invalid Score', 'Please enter an interview score from 0 to 100.', "applications.php?job_id={$job_id}");
    }


    // ==========================================
    // DETERMINE ASSESSMENT
    // ==========================================

    $recommendation = ($score >= 60)
        ? 'Recommended'
        : 'Not Recommended';


    // ==========================================
    // CHECK INTERVIEW
    // ==========================================

    $checkInterview = $conn->prepare("
        SELECT interview_id
        FROM interviews
        WHERE interview_id = ? AND company_id = ?
        AND application_id = ?
        LIMIT 1
    ");

    if (!$checkInterview) {

        die("Prepare interview check failed: " .
            htmlspecialchars($conn->error));
    }

    /*
    | The columns are (interview_id, company_id, application_id) and the
    | arguments used to arrive as (interview_id, application_id, companyId) --
    | so the application id was compared against company_id and vice versa.
    | The check never matched, and saving an interview result always ended at
    | "The selected interview does not exist for this applicant."
    */
    $checkInterview->bind_param(
        "iii",
        $interview_id,
        $companyId,
        $application_id
    );

    $checkInterview->execute();

    $checkInterview->store_result();


    if ($checkInterview->num_rows === 0) {

        $checkInterview->close();

        applicationResultNotice('warning', 'No Interview Found', 'The selected interview does not exist for this applicant.', "applications.php?job_id={$job_id}");
    }

    $checkInterview->close();


    // ==========================================
    // CHECK IF RESULT ALREADY EXISTS
    // ==========================================

    $checkResult = $conn->prepare("
        SELECT result_id
        FROM interview_results
        WHERE interview_id = ? AND company_id = ?
        LIMIT 1
    ");

    if (!$checkResult) {

        die("Prepare result check failed: " .
            htmlspecialchars($conn->error));
    }

    $checkResult->bind_param(
        "ii",
        $interview_id,
        $companyId
    );

    $checkResult->execute();

    $checkResult->store_result();


    if ($checkResult->num_rows > 0) {

        $checkResult->close();

        applicationResultNotice('warning', 'Interview Result Already Exists', 'This interview already has a saved result.', "applications.php?job_id={$job_id}");
    }

    $checkResult->close();


    // ==========================================
    // INSERT INTERVIEW RESULT
    // ==========================================

    $stmt = $conn->prepare("
        INSERT INTO interview_results
        (
            company_id,
            application_id,
            interview_id,
            score,
            remarks,
            recommendation
        )
        VALUES (?, ?, ?, ?, ?, ?)
    ");

    if (!$stmt) {

        die("Prepare INSERT failed: " .
            htmlspecialchars($conn->error));
    }


    // application_id = integer
    // interview_id   = integer
    // score          = integer
    // remarks        = string
    // recommendation = string

    $stmt->bind_param(
        "iiiiss",
        $companyId,
        $application_id,
        $interview_id,
        $score,
        $remarks,
        $recommendation
    );


    // ==========================================
    // EXECUTE INSERT
    // ==========================================

    if (!$stmt->execute()) {

        $error = htmlspecialchars(
            $stmt->error,
            ENT_QUOTES,
            'UTF-8'
        );

        $stmt->close();

        echo "
        <script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>

        <script>
        Swal.fire({
            icon: 'error',
            title: 'Failed to Save Interview Result',
            html: `
                <div>
                    <p>Database error occurred.</p>
                    <small class='text-danger'>
                        {$error}
                    </small>
                </div>
            `,
            confirmButtonColor: '#dc3545'
        });
        </script>
        ";

        exit;
    }


    // ==========================================
    // VERIFY INSERTED RESULT
    // ==========================================

    $new_result_id = $stmt->insert_id;

    $stmt->close();


    if ($new_result_id <= 0) {

        applicationResultNotice('error', 'Save Verification Failed', 'The interview result was not properly inserted.', "applications.php?job_id={$job_id}");
    }


    // ==========================================
    // UPDATE APPLICATION STATUS
    // ==========================================

    $updateApplication = $conn->prepare("
        UPDATE applications
        SET status = 'Interview Result'
        WHERE application_id = ?
    ");

    if (!$updateApplication) {

        die("Prepare UPDATE failed: " .
            htmlspecialchars($conn->error));
    }

    $updateApplication->bind_param(
        "i",
        $application_id
    );

    if (!$updateApplication->execute()) {

        $error = htmlspecialchars(
            $updateApplication->error,
            ENT_QUOTES,
            'UTF-8'
        );

        $updateApplication->close();

        die("Application status update failed: " . $error);
    }

    $updateApplication->close();


    // ==========================================
    // SUCCESS
    // ==========================================

    applicationResultNotice(
        'success',
        'Interview Result Saved',
        "Score {$score}/100 - {$recommendation}. The result has been recorded.",
        "applications.php?job_id={$job_id}"
    );
}

if (isset($_POST['recommendHiring'])) {

    $application_id = (int) $_POST['recommend_application_id'];
    $job_id = (int) $_POST['job_id'];

    $comments = trim($_POST['recommendation_comments'] ?? '');

    $recommended_by = $_SESSION['user_id'];

    // =====================================
    // CHECK DUPLICATE RECOMMENDATION
    // =====================================

    $check = $conn->prepare("
        SELECT recommendation_id
        FROM hiring_recommendations
        WHERE application_id = ? AND company_id = ?
    ");

    $check->bind_param("ii", $application_id, $companyId);
    $check->execute();
    $check->store_result();

    if ($check->num_rows > 0) {

        echo "
        <script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>

        <script>
        document.addEventListener('DOMContentLoaded', function() {

            Swal.fire({
                icon: 'warning',
                title: 'Already Submitted',
                text: 'This applicant has already been recommended to the Administrator.',
                confirmButtonColor: '#0d6efd'
            }).then(() => {

                window.location = 'applications.php?job_id=$job_id';

            });

        });
        </script>
        ";

        exit;
    }

    // =====================================
    // GET INTERVIEW RESULT
    // =====================================

    $resultQuery = $conn->prepare("
        SELECT result_id
        FROM interview_results
        WHERE application_id = ? AND company_id = ?
        LIMIT 1
    ");

    $resultQuery->bind_param("ii", $application_id, $companyId);
    $resultQuery->execute();

    $result = $resultQuery->get_result()->fetch_assoc();

    if (!$result) {

        echo "
        <script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>

        <script>
        document.addEventListener('DOMContentLoaded', function() {

            Swal.fire({
                icon: 'error',
                title: 'Interview Result Missing',
                text: 'Please save the interview result first.',
                confirmButtonColor: '#dc3545'
            }).then(() => {

                window.location = 'applications.php?job_id=$job_id';

            });

        });
        </script>
        ";

        exit;
    }

    $result_id = (int) $result['result_id'];

    // =====================================
    // INSERT HR RECOMMENDATION
    // =====================================

    $stmt = $conn->prepare("
        INSERT INTO hiring_recommendations
        (
            company_id,
            application_id,
            job_id,
            interview_result_id,
            hr_comments,
            recommended_by,
            status,
            recommended_at
        )
        VALUES
        (
            ?,?,?,?,?,?,
            'Pending',
            NOW()
        )
    ");

    $stmt->bind_param(
        "iiiisi",
        $companyId,
        $application_id,
        $job_id,
        $result_id,
        $comments,
        $recommended_by
    );

    if ($stmt->execute()) {

        // =====================================
        // UPDATE APPLICATION STATUS
        // =====================================

        $update = $conn->prepare("
            UPDATE applications
            SET status = 'Recommended'
            WHERE application_id = ?
        ");

        $update->bind_param("i", $application_id);
        $update->execute();

        echo "
        <script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>

        <script>
        document.addEventListener('DOMContentLoaded', function() {

            Swal.fire({
                icon: 'success',
                title: 'Recommendation Submitted',
                text: 'The applicant has been recommended to the Administrator for approval.',
                confirmButtonColor: '#198754'
            }).then(() => {

                window.location = 'applications.php?job_id=$job_id';

            });

        });
        </script>
        ";

        exit;
    } else {

        $error = htmlspecialchars($stmt->error, ENT_QUOTES, 'UTF-8');

        echo "
        <script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>

        <script>
        document.addEventListener('DOMContentLoaded', function() {

            Swal.fire({
                icon: 'error',
                title: 'Database Error',
                text: '$error',
                confirmButtonColor: '#dc3545'
            });

        });
        </script>
        ";

        exit;
    }
}

if (isset($_POST['rejectApplicant'])) {

    $application_id = (int) ($_POST['application_id'] ?? 0);
    $job_id = (int) ($_POST['job_id'] ?? 0);

    $rejection_reason = trim(
        $_POST['rejection_reason'] ?? ''
    );


    // =====================================
    // VALIDATE REJECTION REASON
    // =====================================

    if ($application_id <= 0 || $job_id <= 0) {

        echo "
        <script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
        <script>
        Swal.fire({
            icon: 'error',
            title: 'Invalid Request',
            text: 'Invalid applicant information.',
            confirmButtonColor: '#dc3545'
        }).then(() => {
            window.location = 'applications.php?job_id=$job_id';
        });
        </script>
        ";

        exit;
    }


    if ($rejection_reason === '') {

        echo "
        <script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
        <script>
        Swal.fire({
            icon: 'warning',
            title: 'Reason Required',
            text: 'Please provide a reason for rejecting this applicant.',
            confirmButtonColor: '#dc3545'
        }).then(() => {
            window.location = 'applications.php?job_id=$job_id';
        });
        </script>
        ";

        exit;
    }


    // =====================================
    // GET APPLICANT INFORMATION
    // =====================================

    $get = $conn->prepare("
        SELECT
            CONCAT_WS(
                ' ',
                a.first_name,
                NULLIF(a.middle_name, ''),
                a.last_name,
                NULLIF(a.suffix, '')
            ) AS full_name,
            a.email,
            j.job_title,
            b.branch_name
        FROM applications a
        INNER JOIN job j
            ON a.job_id = j.job_id
        INNER JOIN branch b
            ON j.branch_id = b.branch_id
        WHERE a.application_id = ?
        LIMIT 1
    ");

    $get->bind_param(
        "i",
        $application_id
    );

    $get->execute();

    $app = $get->get_result()->fetch_assoc();


    if (!$app) {

        echo "
        <script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
        <script>
        Swal.fire({
            icon: 'error',
            title: 'Applicant Not Found',
            text: 'The applicant record could not be found.',
            confirmButtonColor: '#dc3545'
        }).then(() => {
            window.location = 'applications.php?job_id=$job_id';
        });
        </script>
        ";

        exit;
    }


    // =====================================
    // SAVE HR REJECTION
    // =====================================

    $update = $conn->prepare("
        UPDATE applications
        SET
            status = 'Rejected',
            rejected_by = 'HR',
            rejection_reason = ?,
            rejected_at = NOW()
        WHERE application_id = ?
    ");

    $update->bind_param(
        "si",
        $rejection_reason,
        $application_id
    );


    if (!$update->execute()) {

        $error = htmlspecialchars(
            $update->error,
            ENT_QUOTES,
            'UTF-8'
        );

        echo "
        <script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
        <script>
        Swal.fire({
            icon: 'error',
            title: 'Database Error',
            text: '$error',
            confirmButtonColor: '#dc3545'
        });
        </script>
        ";

        exit;
    }


    // =====================================
    // SEND REJECTION EMAIL
    // =====================================

    sendRejectionEmail(
        $app['email'],
        $app['full_name'],
        $app['job_title'],
        $app['branch_name']
    );


    // =====================================
    // SUCCESS MESSAGE
    // =====================================

    echo "
    <script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
    <script>
    document.addEventListener('DOMContentLoaded', function () {

        Swal.fire({
            icon: 'success',
            title: 'Applicant Rejected',
            text: 'The applicant has been rejected and the reason has been recorded.',
            confirmButtonColor: '#dc3545'
        }).then(() => {

            window.location = 'applications.php?job_id=$job_id';

        });

    });
    </script>
    ";

    exit;
}

$job_id = intval($_POST['job_id'] ?? $_GET['job_id'] ?? 0);

// Job Information
$jobStmt = $conn->prepare("
SELECT
    j.job_title,
    b.branch_name,
    b.complete_address,
    j.vacancies,
    j.salary_min,
    j.salary_max,
    COUNT(a.application_id) AS total_applicants
FROM job j
INNER JOIN branch b
ON j.branch_id = b.branch_id
LEFT JOIN applications a
ON j.job_id = a.job_id
WHERE j.job_id = ? AND j.company_id = ?
GROUP BY j.job_id
");

$jobStmt->bind_param("ii", $job_id, $companyId);
$jobStmt->execute();
$jobInfo = $jobStmt->get_result()->fetch_assoc();

$search = trim($_GET['search'] ?? "");

$sql = "
SELECT
    a.*,

    CONCAT_WS(
        ' ',
        a.first_name,
        NULLIF(a.middle_name, ''),
        a.last_name,
        NULLIF(a.suffix, '')
    ) AS full_name,

    j.job_title,
    b.branch_name,

    i.interview_id,
    i.interview_date,
    i.interview_time,

    ir.result_id,
    ir.score,
    ir.recommendation,
    /* ==========================================
    PREVIOUS REJECTION HISTORY
    ========================================== */

    (
        SELECT GROUP_CONCAT(
            CONCAT(
                j2.job_title,
                '|||',
                COALESCE(r.rejected_by, ''),
                '|||',
                COALESCE(r.rejection_reason, ''),
                '|||',
                COALESCE(r.rejected_at, '')
            )
            ORDER BY r.rejected_at DESC
            SEPARATOR ';;;'
        )
        FROM applications r
        INNER JOIN job j2
            ON r.job_id = j2.job_id
        WHERE r.email = a.email
        AND r.company_id = a.company_id
        AND r.status = 'Rejected'
        AND r.rejection_reason IS NOT NULL
        AND r.rejection_reason != ''
    ) AS rejection_history

FROM applications a

INNER JOIN job j
    ON a.job_id = j.job_id

INNER JOIN branch b
    ON j.branch_id = b.branch_id

LEFT JOIN interviews i
ON i.application_id = a.application_id AND i.company_id = a.company_id

LEFT JOIN interview_results ir
 ON ir.application_id = a.application_id AND ir.company_id = a.company_id

WHERE a.job_id = ?
AND a.company_id = ?
AND a.email_verified = '1'
";

if ($search != '') {
    $sql .= "
    AND (
        CONCAT_WS(
        ' ',
        a.first_name,
        NULLIF(a.middle_name, ''),
        a.last_name,
        NULLIF(a.suffix, '')
    ) LIKE ?
        OR a.email LIKE ?
        OR a.phone LIKE ?
        OR a.status LIKE ?
    )";
}

$sql .= " ORDER BY a.applied_at DESC";

$stmt = $conn->prepare($sql);
if ($search != '') {
    $keyword = "%$search%";
    $stmt->bind_param(
        "iissss",
        $job_id,
        $companyId,
        $keyword,
        $keyword,
        $keyword,
        $keyword
    );
} else {
    $stmt->bind_param("ii", $job_id, $companyId);
}
$stmt->execute();
$result = $stmt->get_result();


include includeRoleHeader(__DIR__, 'hr_header.php');

?>

<div class="container-fluid py-1">
    <div class="mb-4">
        <a href="recruitment.php" class="btn btn-primary rounded-pill px-4 mb-4">
            <i class="bi bi-arrow-left"></i>
            Back to Recruitment
        </a>
        <h2 class="fw-bold mb-1" style="color: #00224c;">
            <?= htmlspecialchars($jobInfo['job_title']) ?>
            -
            <?= htmlspecialchars($jobInfo['branch_name']) ?>
        </h2>
        <p class="text-muted mb-0">
            <strong>Applications Received:</strong>
            <?= $jobInfo['total_applicants'] ?>
            &nbsp;|&nbsp;
            <strong>Vacancies:</strong>
            <?= $jobInfo['vacancies'] ?>
            &nbsp;|&nbsp;
            <strong>Salary:</strong>
            <?php
            if ($jobInfo['salary_min'] && $jobInfo['salary_max']) {
                echo "₱" . number_format($jobInfo['salary_min'], 2) .
                    " - ₱" . number_format($jobInfo['salary_max'], 2);
            } else {
                echo "Not Specified";
            }
            ?>
        </p>
    </div>
    <div class="row g-4">
        <!-- LEFT -->
        <div class="col-lg-12">
            <div class="card shadow-sm border-2 rounded-4">
                <div class="card-body">
                    <form action="" method="GET" id="searchForm" class="mb-3">
                        <input type="hidden" name="job_id" value="<?= $job_id ?>">
                        <div class="row">
                            <div class="col-md-12">
                                <div class="input-group">
                                    <input type="search" id="search" class="form-control rounded-3" name="search"
                                        value="<?php echo htmlspecialchars($search); ?>" placeholder="Search ...">
                                    <button class="btn btn-primary" type="submit">
                                        <i class="bi bi-search"></i>
                                    </button>
                                    <?php if ($search != '') { ?>
                                        <a href="applications.php?job_id=<?= $job_id ?>" class="btn btn-secondary">
                                            Reset
                                        </a>
                                    <?php } ?>
                                </div>
                            </div>
                        </div>
                    </form>
                    <div class="table-container">
                        <table id="applicantsTable" class="table align-middle" style="width:100%">
                            <thead>
                                <tr>
                                    <th>Applicant</th>
                                    <th>Email</th>
                                    <th>Contact</th>
                                    <th>Resume</th>
                                    <th>Applied</th>
                                    <th>Status</th>
                                    <th class="text-center">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($row = $result->fetch_assoc()) { ?>
                                    <tr>
                                        <td><?= htmlspecialchars($row['full_name']) ?></td>
                                        <td><?= htmlspecialchars($row['email']) ?></td>
                                        <td><?= htmlspecialchars($row['phone']) ?></td>
                                        <td><a href="../uploads/resume/<?= $row['resume'] ?>" target="_blank">
                                                <?= $row['resume'] ?> </a></td>
                                        <td><?= date("Y-m-d", strtotime($row['applied_at'])) ?></td>
                                        <td>
                                            <?php
                                            $badge = "secondary";
                                            switch ($row['status']) {
                                                case "Pending":
                                                    $badge = "warning text-dark";
                                                    break;
                                                case "Interview":
                                                    $badge = "primary";
                                                    break;
                                                case "Recommended":
                                                    $badge = "success";
                                                    break;
                                                case "Rejected":
                                                    $badge = "danger";
                                                    break;
                                                case "Hired":
                                                    $badge = "dark";
                                                    break;
                                            }
                                            ?>
                                            <span class="badge rounded-pill bg-<?= $badge ?>">
                                                <?= $row['status'] ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($row['status'] != "Rejected" && $row['status'] != "Hired") { ?>

                                                <div class="dropdown">

                                                    <button class="btn btn-primary btn-sm dropdown-toggle rounded-pill"
                                                        type="button" data-bs-toggle="dropdown" aria-expanded="false">

                                                        <i class="bi bi-list"></i> Actions

                                                    </button>

                                                    <ul class="dropdown-menu dropdown-menu-end shadow">

                                                        <!-- Profile -->
                                                        <li>
                                                            <button class="dropdown-item viewProfileBtn" data-bs-toggle="modal"
                                                                data-bs-target="#profileModal"
                                                                data-appid="<?= $row['application_id'] ?>" data-name="<?= htmlspecialchars(
                                                                      $row['first_name'] .
                                                                      (!empty($row['middle_name']) ? ' ' . $row['middle_name'] : '') .
                                                                      ' ' . $row['last_name'] .
                                                                      (!empty($row['suffix']) ? ' ' . $row['suffix'] : ''),
                                                                      ENT_QUOTES
                                                                  ) ?>"
                                                                data-email="<?= htmlspecialchars($row['email'] ?? '', ENT_QUOTES) ?>"
                                                                data-phone="<?= htmlspecialchars($row['phone'] ?? '', ENT_QUOTES) ?>"
                                                                data-location="<?= htmlspecialchars($row['location'] ?? '', ENT_QUOTES) ?>"
                                                                data-status="<?= htmlspecialchars($row['status'] ?? '', ENT_QUOTES) ?>"
                                                                data-job="<?= htmlspecialchars($jobInfo['job_title'] ?? '', ENT_QUOTES) ?>"
                                                                data-branch="<?= htmlspecialchars($jobInfo['branch_name'] ?? '', ENT_QUOTES) ?>"
                                                                data-applied="<?= date(
                                                                    'F d, Y',
                                                                    strtotime($row['applied_at'])
                                                                ) ?>"
                                                                data-resume="<?= htmlspecialchars($row['resume'] ?? '', ENT_QUOTES) ?>"
                                                                data-cover="<?= htmlspecialchars(
                                                                    $row['cover_note'] ?? '',
                                                                    ENT_QUOTES
                                                                ) ?>" data-rejection-history="<?= htmlspecialchars(
                                                                     $row['rejection_history'] ?? '[]',
                                                                     ENT_QUOTES,
                                                                     'UTF-8'
                                                                 ) ?>">

                                                                <i class="bi bi-person me-2"></i>
                                                                View Profile

                                                            </button>
                                                        </li>

                                                        <!-- Schedule -->
                                                        <!-- Schedule -->
                                                        <?php if (!empty($row['recommendation'])) { ?>

                                                            <!-- LOCKED AFTER RECOMMENDATION -->
                                                            <li>
                                                                <span class="dropdown-item text-muted"
                                                                    title="Cannot schedule another interview after recommendation has been sent to Admin.">

                                                                    <i class="bi bi-lock me-2"></i>
                                                                    Schedule Interview

                                                                </span>
                                                            </li>

                                                        <?php } else { ?>

                                                            <!-- AVAILABLE -->
                                                            <li>
                                                                <button class="dropdown-item" data-bs-toggle="modal"
                                                                    data-bs-target="#scheduleModal"
                                                                    data-id="<?= (int) $row['application_id'] ?>" data-name="<?= htmlspecialchars(
                                                                           $row['full_name'] ?? '',
                                                                           ENT_QUOTES,
                                                                           'UTF-8'
                                                                       ) ?>" data-email="<?= htmlspecialchars(
                                                                            $row['email'] ?? '',
                                                                            ENT_QUOTES,
                                                                            'UTF-8'
                                                                        ) ?>" data-branch-address="<?= htmlspecialchars(
                                                                             $jobInfo['complete_address'] ?? '',
                                                                             ENT_QUOTES,
                                                                             'UTF-8'
                                                                         ) ?>">

                                                                    <i class="bi bi-calendar-event me-2"></i>
                                                                    Schedule Interview

                                                                </button>
                                                            </li>

                                                        <?php } ?>

                                                        <!-- Result -->
                                                        <?php if (!empty($row['interview_id'])) { ?>

                                                            <li>
                                                                <button class="dropdown-item" data-bs-toggle="modal"
                                                                    data-bs-target="#resultModal"
                                                                    data-id="<?= (int) $row['application_id'] ?>"
                                                                    data-interview-id="<?= (int) ($row['interview_id'] ?? 0) ?>"
                                                                    data-name="<?= htmlspecialchars(
                                                                        $row['full_name'] ?? '',
                                                                        ENT_QUOTES,
                                                                        'UTF-8'
                                                                    ) ?>" data-job="<?= htmlspecialchars(
                                                                         $jobInfo['job_title'] ?? '',
                                                                         ENT_QUOTES,
                                                                         'UTF-8'
                                                                     ) ?>">

                                                                    <i class="bi bi-clipboard-check me-2"></i>
                                                                    Interview Result

                                                                </button>
                                                            </li>

                                                        <?php } else { ?>

                                                            <li>
                                                                <span class="dropdown-item text-muted">
                                                                    <i class="bi bi-lock me-2"></i>
                                                                    Interview Result
                                                                </span>
                                                            </li>

                                                        <?php } ?>

                                                        <!-- Recommend -->
                                                        <li>
                                                            <button class="dropdown-item text-success" data-bs-toggle="modal"
                                                                data-bs-target="#recommendModal"
                                                                data-id="<?= (int) $row['application_id'] ?>" data-name="<?= htmlspecialchars(
                                                                       $row['full_name'] ?? '',
                                                                       ENT_QUOTES,
                                                                       'UTF-8'
                                                                   ) ?>" data-job="<?= htmlspecialchars(
                                                                        $jobInfo['job_title'] ?? '',
                                                                        ENT_QUOTES,
                                                                        'UTF-8'
                                                                    ) ?>" data-branch="<?= htmlspecialchars(
                                                                         $jobInfo['branch_name'] ?? '',
                                                                         ENT_QUOTES,
                                                                         'UTF-8'
                                                                     ) ?>" data-score="<?= htmlspecialchars(
                                                                          $row['score'] ?? '',
                                                                          ENT_QUOTES,
                                                                          'UTF-8'
                                                                      ) ?>">

                                                                <i class="bi bi-hand-thumbs-up me-2"></i>
                                                                Recommend Applicant

                                                            </button>
                                                        </li>

                                                        <li>
                                                            <hr class="dropdown-divider">
                                                        </li>

                                                        <!-- Reject -->
                                                        <li>
                                                            <form method="POST" class="rejectForm">

                                                                <input type="hidden" name="rejectApplicant" value="1">
                                                                <input type="hidden" name="application_id"
                                                                    value="<?= $row['application_id'] ?>">
                                                                <input type="hidden" name="job_id" value="<?= $job_id ?>">

                                                                <button type="submit" class="dropdown-item text-danger">

                                                                    <i class="bi bi-x-circle me-2"></i>
                                                                    Reject Applicant

                                                                </button>

                                                            </form>
                                                        </li>

                                                    </ul>

                                                </div>

                                            <?php } else { ?>

                                                <button class="dropdown-item viewProfileBtn" data-bs-toggle="modal"
                                                    data-bs-target="#profileModal" data-appid="<?= $row['application_id'] ?>"
                                                    data-name="<?= htmlspecialchars(
                                                        $row['first_name'] .
                                                        (!empty($row['middle_name']) ? ' ' . $row['middle_name'] : '') .
                                                        ' ' . $row['last_name'] .
                                                        (!empty($row['suffix']) ? ' ' . $row['suffix'] : ''),
                                                        ENT_QUOTES
                                                    ) ?>"
                                                    data-email="<?= htmlspecialchars($row['email'] ?? '', ENT_QUOTES) ?>"
                                                    data-phone="<?= htmlspecialchars($row['phone'] ?? '', ENT_QUOTES) ?>"
                                                    data-location="<?= htmlspecialchars($row['location'] ?? '', ENT_QUOTES) ?>"
                                                    data-status="<?= htmlspecialchars($row['status'] ?? '', ENT_QUOTES) ?>"
                                                    data-job="<?= htmlspecialchars($jobInfo['job_title'] ?? '', ENT_QUOTES) ?>"
                                                    data-branch="<?= htmlspecialchars($jobInfo['branch_name'] ?? '', ENT_QUOTES) ?>"
                                                    data-applied="<?= date(
                                                        'F d, Y',
                                                        strtotime($row['applied_at'])
                                                    ) ?>"
                                                    data-resume="<?= htmlspecialchars($row['resume'] ?? '', ENT_QUOTES) ?>"
                                                    data-cover="<?= htmlspecialchars(
                                                        $row['cover_note'] ?? '',
                                                        ENT_QUOTES
                                                    ) ?>" data-rejection-history="<?= htmlspecialchars(
                                                         $row['rejection_history'] ?? '[]',
                                                         ENT_QUOTES,
                                                         'UTF-8'
                                                     ) ?>">

                                                    <i class="bi bi-person me-2"></i>
                                                    View

                                                </button>

                                            <?php } ?>
                                        </td>
                                    </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="profileModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content rounded-4">
            <div class="modal-header">
                <h4 class="fw-bold text-primary">
                    Applicant Profile
                </h4>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <h3 class="fw-bold text-primary mb-1" id="profileName"> </h3>
                <p class="text-muted mb-4">
                    <span id="profileApplication"></span>
                    •
                    Applied for
                    <strong id="profileJob"></strong>
                    •
                    <span id="profileBranch"></span>
                </p>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="fw-bold"> Email </label>
                        <div id="profileEmail"></div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="fw-bold"> Contact Number </label>
                        <div id="profilePhone"></div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="fw-bold">Location</label>
                        <div id="profileLocation"></div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="fw-bold">Applied Date</label>
                        <div id="profileApplied"></div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="fw-bold">Status</label>
                        <div id="profileStatus"></div>
                    </div>
                    <div class="col-12 mb-4">
                        <label class="fw-bold">Cover Letter / Cover Note</label>
                        <div id="profileCover" class="border rounded-3 p-3 bg-light" style="min-height:120px;"></div>
                    </div>
                    <!-- ==========================================
                        REJECTION INFORMATION
                    ========================================== -->

                    <div id="profileRejectionSection" class="mt-4" style="display: none;">

                        <div class="accordion" id="rejectionHistoryAccordion">

                            <div class="accordion-item border-0 rounded-4 overflow-hidden shadow-sm">

                                <h2 class="accordion-header">

                                    <button class="accordion-button collapsed fw-bold text-danger" type="button"
                                        data-bs-toggle="collapse" data-bs-target="#rejectionHistoryCollapse"
                                        aria-expanded="false" aria-controls="rejectionHistoryCollapse">

                                        <i class="bi bi-x-circle-fill me-2"></i>

                                        Application Rejection History

                                        <span id="rejectionHistoryCount" class="badge bg-danger ms-2">
                                            0
                                        </span>

                                    </button>

                                </h2>

                                <div id="rejectionHistoryCollapse" class="accordion-collapse collapse"
                                    data-bs-parent="#rejectionHistoryAccordion">

                                    <div id="rejectionHistoryContainer" class="accordion-body bg-light">
                                    </div>

                                </div>

                            </div>

                        </div>

                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <a id="resumeLink" target="_blank" class="btn btn-outline-primary rounded-pill">
                    <i class="bi bi-download"></i>
                    Download Resume
                </a>
                <button onclick="window.print()" class="btn btn-outline-secondary rounded-pill">
                    <i class="bi bi-printer"></i>
                    Print
                </button>
                <button class="btn btn-primary rounded-pill" data-bs-dismiss="modal">
                    Close
                </button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="scheduleModal" tabindex="-1">

    <div class="modal-dialog modal-lg modal-dialog-centered">

        <div class="modal-content rounded-4">

            <form method="POST" id="scheduleInterviewForm">

                <div class="modal-header">

                    <h4 class="modal-title fw-bold text-primary">
                        Schedule Interview
                    </h4>

                    <button type="button" class="btn-close" data-bs-dismiss="modal">
                    </button>

                </div>

                <div class="modal-body">

                    <input type="hidden" name="application_id" id="schedule_application_id">

                    <input type="hidden" name="job_id" value="<?= (int) $job_id ?>">

                    <div class="row g-3">

                        <!-- Applicant -->
                        <div class="col-md-6">

                            <label class="fw-bold">
                                Applicant
                            </label>

                            <input type="text" id="schedule_name" class="form-control" readonly>

                        </div>

                        <!-- Email -->
                        <div class="col-md-6">

                            <label class="fw-bold">
                                Email
                            </label>

                            <input type="text" id="schedule_email" class="form-control" readonly>

                        </div>

                        <!-- Job -->
                        <div class="col-md-6">

                            <label class="fw-bold">
                                Job Position
                            </label>

                            <input type="text" value="<?= htmlspecialchars(
                                $jobInfo['job_title'] ?? '',
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>" class="form-control" readonly>

                        </div>

                        <!-- Branch -->
                        <div class="col-md-6">

                            <label class="fw-bold">
                                Branch
                            </label>

                            <input type="text" value="<?= htmlspecialchars(
                                $jobInfo['branch_name'] ?? '',
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>" class="form-control" readonly>

                        </div>

                        <!-- Interview Date -->
                        <div class="col-md-6">

                            <label class="fw-bold">
                                Interview Date
                            </label>

                            <input type="date" name="interview_date" id="interview_date" class="form-control" required>

                            <div id="dateError" class="text-danger small mt-1 d-none">
                            </div>

                        </div>

                        <!-- Interview Time -->
                        <div class="col-md-6">

                            <label class="fw-bold">
                                Interview Time
                            </label>

                            <input type="time" name="interview_time" id="interview_time" class="form-control"
                                min="08:00" max="16:00" step="1800" required>

                            <div class="form-text">
                                Available interview start times: 8:00 AM to 4:00 PM.
                                Office hours end at 5:00 PM.
                            </div>

                            <div id="timeError" class="text-danger small mt-1 d-none">
                            </div>

                        </div>

                        <!-- Interview Type -->
                        <div class="col-md-6">

                            <label class="fw-bold">
                                Interview Type
                            </label>

                            <select name="interview_type" id="interview_type" class="form-select" required>

                                <option value="">
                                    Select
                                </option>

                                <option value="Face-to-Face">
                                    Face-to-Face
                                </option>

                                <option value="Online">
                                    Online
                                </option>

                                <option value="Phone">
                                    Phone
                                </option>

                            </select>

                        </div>

                        <!-- Interviewer -->
                        <div class="col-md-6">

                            <label class="fw-bold">
                                Assign Interviewer
                            </label>

                            <select name="interviewer_id" class="form-select" required>

                                <option value="">
                                    Select HR
                                </option>

                                <?php

                                /*
                                | Scoped to this company -- this listed every
                                | HR account on the platform, so one business
                                | could book another business's officer as the
                                | interviewer.
                                */
                                $hrQuery = mysqli_query($conn, "
                                    SELECT user_id, fullname
                                    FROM users
                                    WHERE role = 'hr'
                                      AND company_id = " . (int) $companyId . "
                                    ORDER BY fullname
                                ");

                                while ($hr = mysqli_fetch_assoc($hrQuery)) {

                                    ?>

                                    <option value="<?= (int) $hr['user_id'] ?>">
                                        <?= htmlspecialchars(
                                            $hr['fullname'],
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    </option>

                                <?php } ?>

                            </select>

                        </div>


                        <!-- ================================
                             FACE-TO-FACE LOCATION
                        ================================= -->

                        <div class="col-md-12 d-none" id="locationContainer">

                            <label class="fw-bold">
                                Interview Location
                            </label>

                            <input type="text" name="location" id="interview_location" class="form-control" readonly>

                            <div class="form-text">
                                Automatically set to the selected branch location.
                            </div>

                        </div>


                        <!-- ================================
                             ONLINE MEETING LINK
                        ================================= -->

                        <div class="col-md-12 d-none" id="meetingLinkContainer">

                            <label class="fw-bold">
                                Meeting Link (Optional)
                            </label>

                            <input type="url" name="meeting_link" id="meeting_link" class="form-control"
                                placeholder="https://meet.google.com/...">

                            <div class="form-text">
                                Enter a valid meeting URL.
                            </div>

                            <div id="meetingLinkError" class="text-danger small mt-1 d-none">
                            </div>

                        </div>


                        <!-- Notes -->
                        <div class="col-md-12">

                            <label class="fw-bold">
                                Notes
                            </label>

                            <textarea name="notes" rows="4" class="form-control"></textarea>

                        </div>


                        <!-- Email -->
                        <div class="col-md-12">

                            <div class="form-check">

                                <input class="form-check-input" type="checkbox" name="send_email" value="1" checked>

                                <label class="form-check-label">
                                    Send Interview Invitation via Email
                                </label>

                            </div>

                        </div>

                    </div>

                </div>

                <div class="modal-footer">

                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">

                        Cancel

                    </button>

                    <button type="submit" name="scheduleInterview" class="btn btn-primary">

                        <i class="bi bi-calendar-check"></i>

                        Schedule Interview

                    </button>

                </div>

            </form>

        </div>

    </div>

</div>

<div class="modal fade" id="resultModal" tabindex="-1">

    <div class="modal-dialog modal-lg modal-dialog-centered">

        <div class="modal-content rounded-4 shadow">

            <form method="POST" id="interviewResultForm">
                <input type="hidden" name="saveResult" value="1">

                <div class="modal-header">

                    <h4 class="modal-title fw-bold text-primary">

                        <i class="bi bi-clipboard-check me-2"></i>

                        Interview Result

                    </h4>

                    <button type="button" class="btn-close" data-bs-dismiss="modal">
                    </button>

                </div>


                <div class="modal-body">

                    <!-- Application ID -->
                    <input type="hidden" name="result_application_id" id="result_application_id">


                    <!-- Interview ID -->
                    <input type="hidden" name="result_interview_id" id="result_interview_id">


                    <!-- Job ID -->
                    <input type="hidden" name="job_id" value="<?= (int) $job_id ?>">




                    <div class="row g-3">


                        <!-- Applicant -->

                        <div class="col-md-6">

                            <label class="fw-bold">
                                Applicant
                            </label>

                            <input type="text" id="result_name" class="form-control" readonly>

                        </div>


                        <!-- Position -->

                        <div class="col-md-6">

                            <label class="fw-bold">
                                Position
                            </label>

                            <input type="text" id="result_job" class="form-control" readonly>

                        </div>


                        <!-- Score -->

                        <div class="col-md-6">

                            <label class="fw-bold">
                                Interview Score (0-100)
                            </label>

                            <input type="number" min="0" max="100" name="score" id="score" class="form-control"
                                required>

                            <div class="form-text">
                                60 and above = Recommended.
                                Below 60 = Not Recommended.
                            </div>

                        </div>


                        <!-- Recommendation -->

                        <div class="col-md-6">

                            <label class="fw-bold">
                                Assessment
                            </label>

                            <input type="text" id="recommendation" class="form-control" readonly
                                placeholder="Enter score first">

                        </div>


                        <!-- Remarks -->

                        <div class="col-12">

                            <label class="fw-bold">
                                Remarks
                            </label>

                            <textarea name="remarks" rows="5" class="form-control"
                                placeholder="Write interview remarks here..."></textarea>

                        </div>

                    </div>

                </div>


                <div class="modal-footer">

                    <button class="btn btn-secondary rounded-pill" data-bs-dismiss="modal" type="button">

                        Cancel

                    </button>


                    <button class="btn btn-success rounded-pill" type="submit" name="saveResult" id="saveResultBtn">

                        <i class="bi bi-check-circle me-1"></i>

                        Save Result

                    </button>

                </div>

            </form>

        </div>

    </div>

</div>

<!-- Recommend for Hiring Modal -->
<div class="modal fade" id="recommendModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content rounded-4 shadow">
            <form method="POST">
                <div class="modal-header">
                    <h4 class="fw-bold text-primary mb-0">
                        <i class="bi bi-hand-thumbs-up me-2"></i>
                        Recommend for Hiring
                    </h4>
                    <button class="btn-close" data-bs-dismiss="modal">
                    </button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="recommend_application_id" id="recommend_application_id">
                    <input type="hidden" name="job_id" value="<?= $job_id ?>">
                    <!-- Applicant Summary -->
                    <div class="rounded-4 bg-light p-4 mb-4">
                        <div class="row">
                            <div class="col-md-3 mb-3">
                                <small class="text-muted">Applicant</small>
                                <h5 id="recommend_name" class="mb-0 fw-semibold"></h5>
                            </div>
                            <div class="col-md-3 mb-3">
                                <small class="text-muted">Position</small>
                                <h5 id="recommend_job" class="mb-0 fw-semibold"></h5>
                            </div>
                            <div class="col-md-3 mb-3">
                                <small class="text-muted">Branch</small>
                                <h5 id="recommend_branch" class="mb-0 fw-semibold"></h5>
                            </div>
                            <div class="col-md-3 mb-3">
                                <small class="text-muted">Interview Score</small>
                                <h5 id="recommend_score" class="mb-0 fw-semibold text-success"></h5>
                            </div>
                        </div>
                    </div>
                    <div id="recommendScoreWarning" class="alert alert-warning rounded-4 d-none">

                        <div class="d-flex align-items-start">

                            <i class="bi bi-exclamation-triangle-fill fs-5 me-3"></i>

                            <div>

                                <strong>Low Interview Score</strong>

                                <div class="small mt-1">

                                    The applicant received an interview score below
                                    <strong>60</strong> and is currently assessed as
                                    <strong>Not Recommended</strong>.

                                    However, you may still submit this applicant to the
                                    <strong>Administrator</strong> for final review.

                                </div>

                            </div>

                        </div>

                    </div>
                    <div class="row">
                        <div class="col-12">
                            <label class="fw-bold">HR Recommendation Comments</label>
                            <textarea name="recommendation_comments" rows="5" class="form-control"
                                placeholder="Write your recommendation..."></textarea>
                        </div>
                    </div>
                    <div class="alert alert-warning rounded-4 mt-4 mb-0">
                        <i class="bi bi-info-circle-fill me-2"></i>
                        This recommendation will be forwarded to the
                        <strong>Administrator</strong> for approval.
                        The applicant will only become an employee after
                        the Administrator approves the recommendation.
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal" type="button">
                        Cancel
                    </button>
                    <button class="btn btn-success rounded-pill px-4" name="recommendHiring" type="submit">
                        <i class="bi bi-send-check me-1"></i>
                        Submit to Administrator
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>



<script>
    function escapeHtml(value) {

        return String(value ?? "")
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }
    const profileModal = document.getElementById("profileModal");

    profileModal.addEventListener("show.bs.modal", function (event) {

        const btn = event.relatedTarget;
        console.log("PROFILE BUTTON:", btn);

        // ==========================================
        // BASIC APPLICANT INFORMATION
        // ==========================================

        document.getElementById("profileName").innerHTML =
            btn.dataset.name || "-";

        document.getElementById("profileApplication").innerHTML =
            "APP-" + (btn.dataset.appid || "-");

        document.getElementById("profileJob").innerHTML =
            btn.dataset.job || "-";

        document.getElementById("profileBranch").innerHTML =
            btn.dataset.branch || "-";

        document.getElementById("profileEmail").innerHTML =
            btn.dataset.email || "-";

        document.getElementById("profilePhone").innerHTML =
            btn.dataset.phone || "-";

        document.getElementById("profileLocation").innerHTML =
            btn.dataset.location || "-";

        document.getElementById("profileApplied").innerHTML =
            btn.dataset.applied || "-";

        document.getElementById("profileStatus").innerHTML =
            btn.dataset.status || "-";


        // ==========================================
        // COVER NOTE
        // ==========================================

        document.getElementById("profileCover").innerHTML =
            btn.dataset.cover ?
                btn.dataset.cover :
                "<em>No cover note submitted.</em>";


        // ==========================================
        // RESUME
        // ==========================================

        document.getElementById("resumeLink").href =
            "../uploads/resume/" + (btn.dataset.resume || "");


        // ==========================================
        // PREVIOUS REJECTION HISTORY
        // ==========================================

        const rejectionSection =
            document.getElementById("profileRejectionSection");

        const rejectionContainer =
            document.getElementById("rejectionHistoryContainer");

        const rejectionCount =
            document.getElementById("rejectionHistoryCount");


        // ==========================================
        // RESET MODAL
        // ==========================================

        rejectionSection.style.display = "none";
        rejectionContainer.innerHTML = "";
        rejectionCount.textContent = "0";


        // ==========================================
        // GET GROUP_CONCAT DATA
        // ==========================================

        const rejectionHistoryRaw =
            btn.dataset.rejectionHistory || "";

        console.log(
            "RAW REJECTION HISTORY:",
            rejectionHistoryRaw
        );


        // ==========================================
        // PARSE HISTORY
        // ==========================================

        let rejectionHistory = [];

        if (rejectionHistoryRaw.trim() !== "") {

            rejectionHistory =
                rejectionHistoryRaw
                    .split(";;;")
                    .map(function (item) {

                        const parts = item.split("|||");

                        return {

                            job_title: parts[0] || "-",

                            rejected_by: parts[1] || "-",

                            rejection_reason: parts[2] || "-",

                            rejected_at: parts[3] || "-"

                        };

                    });

        }


        console.log(
            "PARSED REJECTION HISTORY:",
            rejectionHistory
        );


        // ==========================================
        // DISPLAY HISTORY
        // ==========================================

        if (
            Array.isArray(rejectionHistory) &&
            rejectionHistory.length > 0
        ) {

            rejectionSection.style.display = "block";

            rejectionCount.textContent =
                rejectionHistory.length;


            rejectionHistory.forEach(function (rejection, index) {

                const jobTitle =
                    rejection.job_title || "-";

                const rejectedBy =
                    rejection.rejected_by || "-";

                const reason =
                    rejection.rejection_reason || "-";

                let rejectedAt =
                    rejection.rejected_at || "-";


                // ==========================================
                // FORMAT DATE
                // ==========================================

                if (
                    rejectedAt !== "-" &&
                    rejectedAt !== ""
                ) {

                    const date =
                        new Date(
                            rejectedAt.replace(" ", "T")
                        );

                    if (!isNaN(date.getTime())) {

                        rejectedAt =
                            date.toLocaleString(
                                "en-US", {
                                year: "numeric",
                                month: "long",
                                day: "numeric",
                                hour: "numeric",
                                minute: "2-digit",
                                hour12: true
                            }
                            );

                    }

                }


                // ==========================================
                // BADGE
                // ==========================================

                let rejectedByBadge =
                    "bg-secondary";

                if (
                    rejectedBy.toLowerCase() === "hr"
                ) {

                    rejectedByBadge =
                        "bg-warning text-dark";

                } else if (
                    rejectedBy.toLowerCase() === "admin" ||
                    rejectedBy.toLowerCase() === "administrator"
                ) {

                    rejectedByBadge =
                        "bg-danger";

                }


                // ==========================================
                // CREATE ITEM
                // ==========================================

                const item =
                    document.createElement("div");

                item.className =
                    "bg-white border rounded-4 p-3 mb-3";


                item.innerHTML = `

            <div class="d-flex justify-content-between align-items-start mb-3">

                <div>

                    <div class="fw-bold text-primary">
                        ${escapeHtml(jobTitle)}
                    </div>

                    <small class="text-muted">
                        Rejection #${index + 1}
                    </small>

                </div>

                <span class="badge ${rejectedByBadge}">
                    ${escapeHtml(rejectedBy)}
                </span>

            </div>


            <div class="mb-3">

                <small class="text-muted d-block">
                    Rejection Reason
                </small>

                <div class="fw-semibold">
                    ${escapeHtml(reason)}
                </div>

            </div>


            <div>

                <small class="text-muted d-block">
                    Rejected At
                </small>

                <div class="fw-semibold">
                    ${escapeHtml(rejectedAt)}
                </div>

            </div>

        `;


                rejectionContainer.appendChild(item);

            });


        } else {

            rejectionSection.style.display = "none";

        }


    });

    const scheduleModal = document.getElementById("scheduleModal");

    const interviewType =
        document.getElementById("interview_type");

    const locationContainer =
        document.getElementById("locationContainer");

    const meetingLinkContainer =
        document.getElementById("meetingLinkContainer");

    const interviewLocation =
        document.getElementById("interview_location");

    const meetingLink =
        document.getElementById("meeting_link");

    const meetingLinkError =
        document.getElementById("meetingLinkError");

    const scheduleForm =
        document.getElementById("scheduleInterviewForm");


    // ======================================================
    // OPEN SCHEDULE MODAL
    // ======================================================

    scheduleModal.addEventListener("show.bs.modal", function (event) {

        const btn = event.relatedTarget;

        document.getElementById("schedule_application_id").value =
            btn.dataset.id || "";

        document.getElementById("schedule_name").value =
            btn.dataset.name || "";

        document.getElementById("schedule_email").value =
            btn.dataset.email || "";

        // Get branch address from button
        interviewLocation.value =
            btn.dataset.branchAddress || "";

        // Reset interview type
        interviewType.value = "";

        locationContainer.classList.add("d-none");
        meetingLinkContainer.classList.add("d-none");

        meetingLink.value = "";

        meetingLinkError.textContent = "";
        meetingLinkError.classList.add("d-none");

    });


    // ======================================================
    // INTERVIEW TYPE CHANGE
    // ======================================================

    interviewType.addEventListener("change", function () {

        const type = this.value;


        // Reset everything first
        locationContainer.classList.add("d-none");
        meetingLinkContainer.classList.add("d-none");

        interviewLocation.value = "";
        meetingLink.value = "";

        meetingLinkError.textContent = "";
        meetingLinkError.classList.add("d-none");


        // ================================================
        // FACE-TO-FACE
        // ================================================

        if (type === "Face-to-Face") {

            locationContainer.classList.remove("d-none");

            // Get branch address from Schedule button
            const activeButton =
                document.querySelector(
                    '[data-bs-target="#scheduleModal"][data-id="' +
                    document.getElementById("schedule_application_id").value +
                    '"]'
                );

            if (activeButton) {

                interviewLocation.value =
                    activeButton.dataset.branchAddress || "";

            }

        }


        // ================================================
        // ONLINE
        // ================================================
        else if (type === "Online") {

            meetingLinkContainer.classList.remove("d-none");

            meetingLink.focus();

        }

    });


    // ======================================================
    // MEETING LINK VALIDATION
    // ======================================================

    meetingLink.addEventListener("input", function () {

        const value = this.value.trim();

        meetingLinkError.textContent = "";
        meetingLinkError.classList.add("d-none");

        // Optional
        if (value === "") {
            return;
        }

        try {

            const url = new URL(value);

            if (
                url.protocol !== "http:" &&
                url.protocol !== "https:"
            ) {

                throw new Error();

            }

        } catch (error) {

            meetingLinkError.textContent =
                "Please enter a valid meeting link starting with https://";

            meetingLinkError.classList.remove("d-none");

        }

    });


    // ======================================================
    // FORM VALIDATION
    // ======================================================

    scheduleForm.addEventListener("submit", function (event) {

        const type = interviewType.value;

        let valid = true;


        // ================================================
        // ONLINE LINK VALIDATION
        // ================================================

        if (type === "Online") {

            const link =
                meetingLink.value.trim();

            // Optional, so blank is allowed
            if (link !== "") {

                try {

                    const url = new URL(link);

                    if (
                        url.protocol !== "http:" &&
                        url.protocol !== "https:"
                    ) {
                        throw new Error();
                    }

                } catch (error) {

                    event.preventDefault();

                    meetingLinkError.textContent =
                        "Please enter a valid meeting URL.";

                    meetingLinkError.classList.remove("d-none");

                    meetingLink.focus();

                    valid = false;
                }

            }

        }


        // ================================================
        // FACE-TO-FACE LOCATION
        // ================================================

        if (type === "Face-to-Face") {

            if (interviewLocation.value.trim() === "") {

                event.preventDefault();

                alert(
                    "Interview Location is required for Face-to-Face interviews."
                );

                valid = false;

            }

        }


        // ================================================
        // PHONE
        // ================================================

        if (type === "Phone") {

            // Clear unnecessary values
            interviewLocation.value = "";
            meetingLink.value = "";

        }


        return valid;

    });

    document.addEventListener("DOMContentLoaded", function () {

        /* ==========================================================
           INTERVIEW RESULT
        ========================================================== */

        const resultForm = document.getElementById("interviewResultForm");
        const resultModal = document.getElementById("resultModal");

        const scoreInput = document.getElementById("score");
        const recommendationInput = document.getElementById("recommendation");

        let confirmedSubmit = false;


        /* ==========================================================
           OPEN INTERVIEW RESULT MODAL
        ========================================================== */

        if (resultModal) {

            resultModal.addEventListener("show.bs.modal", function (event) {

                const button = event.relatedTarget;

                if (!button) {
                    return;
                }

                document.getElementById("result_application_id").value =
                    button.dataset.id || "";

                document.getElementById("result_interview_id").value =
                    button.dataset.interviewId || "";

                document.getElementById("result_name").value =
                    button.dataset.name || "";

                document.getElementById("result_job").value =
                    button.dataset.job || "";


                /* Reset fields */

                if (scoreInput) {
                    scoreInput.value = "";
                }

                if (recommendationInput) {

                    recommendationInput.value = "";

                    recommendationInput.classList.remove(
                        "border-success",
                        "border-warning",
                        "text-success",
                        "text-warning"
                    );
                }


                const remarksInput =
                    resultForm.querySelector(
                        'textarea[name="remarks"]'
                    );

                if (remarksInput) {
                    remarksInput.value = "";
                }


                /* Reset submit flag */

                confirmedSubmit = false;

            });

        }


        /* ==========================================================
           SCORE → RECOMMENDATION
        ========================================================== */

        if (scoreInput && recommendationInput) {

            scoreInput.addEventListener("input", function () {

                const value = this.value.trim();


                /* No score */

                if (value === "") {

                    recommendationInput.value = "";

                    recommendationInput.classList.remove(
                        "border-success",
                        "border-warning",
                        "text-success",
                        "text-warning"
                    );

                    return;
                }


                const score = parseInt(value, 10);


                /* Invalid */

                if (
                    isNaN(score) ||
                    score < 0 ||
                    score > 100
                ) {

                    recommendationInput.value =
                        "Invalid Score";

                    recommendationInput.classList.remove(
                        "border-success",
                        "text-success"
                    );

                    recommendationInput.classList.add(
                        "border-warning",
                        "text-warning"
                    );

                    return;
                }


                /* ==================================================
                   60+ = Recommended
                   Below 60 = Not Recommended
                ================================================== */

                if (score >= 60) {

                    recommendationInput.value =
                        "Recommended";

                    recommendationInput.classList.remove(
                        "border-warning",
                        "text-warning"
                    );

                    recommendationInput.classList.add(
                        "border-success",
                        "text-success"
                    );

                } else {

                    recommendationInput.value =
                        "Not Recommended";

                    recommendationInput.classList.remove(
                        "border-success",
                        "text-success"
                    );

                    recommendationInput.classList.add(
                        "border-warning",
                        "text-warning"
                    );

                }

            });

        }


        /* ==========================================================
           SAVE INTERVIEW RESULT
        ========================================================== */

        if (resultForm) {

            resultForm.addEventListener("submit", function (e) {

                /*
                 * IMPORTANT:
                 *
                 * If the user already confirmed the SweetAlert,
                 * allow the form to submit normally to PHP.
                 *
                 * This prevents the SweetAlert from opening again.
                 */

                if (confirmedSubmit === true) {

                    return;
                }


                /* Stop normal submit temporarily */

                e.preventDefault();


                const applicant =
                    document.getElementById("result_name").value ||
                    "Applicant";

                const score =
                    scoreInput ? scoreInput.value.trim() : "";

                const recommendation =
                    recommendationInput ?
                        recommendationInput.value || "Not Set" :
                        "Not Set";


                /* ==================================================
                   SCORE REQUIRED
                ================================================== */

                if (score === "") {

                    Swal.fire({
                        icon: "warning",
                        title: "Score Required",
                        text: "Please enter the interview score first.",
                        confirmButtonColor: "#00224C"
                    });

                    return;
                }


                /* ==================================================
                   SCORE VALIDATION
                ================================================== */

                const numericScore =
                    parseInt(score, 10);


                if (
                    isNaN(numericScore) ||
                    numericScore < 0 ||
                    numericScore > 100
                ) {

                    Swal.fire({
                        icon: "warning",
                        title: "Invalid Score",
                        text: "Interview score must be between 0 and 100.",
                        confirmButtonColor: "#00224C"
                    });

                    return;
                }


                /* ==================================================
                   CONFIRM SAVE
                ================================================== */

                Swal.fire({

                    icon: "question",

                    title: "Save Interview Result?",

                    html: `
                    <div class="text-start">

                        <p class="mb-3">
                            Are you sure you want to save this interview result?
                        </p>

                        <div class="bg-light rounded-3 p-3">

                            <div class="mb-2">

                                <small class="text-muted d-block">
                                    Applicant
                                </small>

                                <strong>
                                    ${applicant}
                                </strong>

                            </div>

                            <div class="mb-2">

                                <small class="text-muted d-block">
                                    Interview Score
                                </small>

                                <strong>
                                    ${numericScore}/100
                                </strong>

                            </div>

                            <div>

                                <small class="text-muted d-block">
                                    Assessment
                                </small>

                                <strong>
                                    ${recommendation}
                                </strong>

                            </div>

                        </div>

                    </div>
                `,

                    showCancelButton: true,

                    confirmButtonText: '<i class="bi bi-check-circle me-1"></i> Yes, Save Result',

                    cancelButtonText: "Cancel",

                    confirmButtonColor: "#198754",

                    cancelButtonColor: "#6c757d",

                    reverseButtons: true,

                    allowOutsideClick: false

                }).then(function (result) {

                    if (!result.isConfirmed) {
                        return;
                    }


                    /* ==================================================
                       USER CONFIRMED
                    ================================================== */

                    confirmedSubmit = true;


                    const saveButton =
                        document.getElementById("saveResultBtn");


                    if (saveButton) {

                        saveButton.disabled = true;

                        saveButton.innerHTML = `
                        <span class="spinner-border spinner-border-sm me-1"></span>
                        Saving...
                    `;

                    }


                    /*
                     * Submit the form normally.
                     *
                     * Because confirmedSubmit = true,
                     * the submit event will NOT open SweetAlert again.
                     */

                    resultForm.submit();

                });

            });

        }


        /* ==========================================================
           RECOMMEND APPLICANT MODAL
        ========================================================== */

        const recommendModal = document.getElementById("recommendModal");


        if (recommendModal) {

            recommendModal.addEventListener(
                "show.bs.modal",
                function (event) {

                    const btn = event.relatedTarget;

                    if (!btn) {
                        return;
                    }


                    const applicationId =
                        btn.dataset.id || "";

                    const name =
                        btn.dataset.name || "";

                    const job =
                        btn.dataset.job || "";

                    const branch =
                        btn.dataset.branch || "";

                    const score =
                        btn.dataset.score || "";


                    document.getElementById(
                        "recommend_application_id"
                    ).value = applicationId;


                    document.getElementById(
                        "recommend_name"
                    ).textContent = name;


                    document.getElementById(
                        "recommend_job"
                    ).textContent = job;


                    document.getElementById(
                        "recommend_branch"
                    ).textContent = branch;


                    const scoreElement =
                        document.getElementById("recommend_score");

                    const warningElement =
                        document.getElementById("recommendScoreWarning");


                    /* ==================================================
                       SCORE EXISTS
                    ================================================== */

                    if (score !== "") {

                        scoreElement.textContent =
                            score + "/100";


                        const numericScore =
                            parseInt(score, 10);


                        if (numericScore >= 60) {

                            scoreElement.classList.remove(
                                "text-warning",
                                "text-danger"
                            );

                            scoreElement.classList.add(
                                "text-success"
                            );


                            if (warningElement) {

                                warningElement.classList.add(
                                    "d-none"
                                );

                            }

                        } else {

                            scoreElement.classList.remove(
                                "text-success",
                                "text-danger"
                            );

                            scoreElement.classList.add(
                                "text-warning"
                            );


                            if (warningElement) {

                                warningElement.classList.remove(
                                    "d-none"
                                );

                            }

                        }

                    } else {

                        scoreElement.textContent =
                            "No score available";


                        scoreElement.classList.remove(
                            "text-success",
                            "text-warning"
                        );

                        scoreElement.classList.add(
                            "text-danger"
                        );


                        if (warningElement) {

                            warningElement.classList.remove(
                                "d-none"
                            );

                        }

                    }

                }
            );

        }

    });
    // const recommendModal = document.getElementById("recommendModal");

    // if (recommendModal) {

    //     recommendModal.addEventListener("show.bs.modal", function (e) {

    //         const btn = e.relatedTarget;

    //         const applicationId =
    //             btn.dataset.id || "";

    //         const name =
    //             btn.dataset.name || "";

    //         const job =
    //             btn.dataset.job || "";

    //         const branch =
    //             btn.dataset.branch || "";

    //         const score =
    //             btn.dataset.score || "";


    //         document.getElementById("recommend_application_id").value =
    //             applicationId;

    //         document.getElementById("recommend_name").textContent =
    //             name;

    //         document.getElementById("recommend_job").textContent =
    //             job;

    //         document.getElementById("recommend_branch").textContent =
    //             branch;


    //         const scoreElement =
    //             document.getElementById("recommend_score");

    //         const warningElement =
    //             document.getElementById("recommendScoreWarning");


    //         if (score !== "") {

    //             scoreElement.textContent =
    //                 score + "/100";

    //             const numericScore =
    //                 parseInt(score, 10);


    //             if (numericScore >= 60) {

    //                 scoreElement.classList.remove(
    //                     "text-warning",
    //                     "text-danger"
    //                 );

    //                 scoreElement.classList.add(
    //                     "text-success"
    //                 );


    //                 if (warningElement) {
    //                     warningElement.classList.add("d-none");
    //                 }

    //             } else {

    //                 scoreElement.classList.remove(
    //                     "text-success"
    //                 );

    //                 scoreElement.classList.add(
    //                     "text-warning"
    //                 );


    //                 if (warningElement) {
    //                     warningElement.classList.remove("d-none");
    //                 }

    //             }

    //         } else {

    //             scoreElement.textContent =
    //                 "No score available";

    //             scoreElement.classList.remove(
    //                 "text-success",
    //                 "text-warning"
    //             );

    //             scoreElement.classList.add(
    //                 "text-danger"
    //             );


    //             if (warningElement) {
    //                 warningElement.classList.remove("d-none");
    //             }

    //         }

    //     });

    // }

    document.querySelectorAll(".rejectForm").forEach(function (form) {

        form.addEventListener("submit", function (e) {

            e.preventDefault();

            const currentForm = this;

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

                didOpen: function () {

                    const textarea = Swal.getInput();

                    if (textarea) {
                        textarea.focus();
                    }

                },

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

                if (!result.isConfirmed) {
                    return;
                }

                // Create hidden input
                const reasonInput = document.createElement("input");

                reasonInput.type = "hidden";

                reasonInput.name = "rejection_reason";

                reasonInput.value = result.value.trim();

                // Add reason to form
                currentForm.appendChild(reasonInput);

                // Submit form
                currentForm.submit();

            });

        });

    });

    // const scoreInput = document.getElementById("score");
    // const recommendationInput = document.getElementById("recommendation");

    // scoreInput.addEventListener("input", function() {

    //     const score = parseInt(this.value);

    //     if (isNaN(score)) {

    //         recommendationInput.value = "";

    //         return;
    //     }

    //     if (score >= 60) {

    //         recommendationInput.value = "Recommended";

    //         recommendationInput.classList.remove(
    //             "border-warning",
    //             "text-warning"
    //         );

    //         recommendationInput.classList.add(
    //             "border-success",
    //             "text-success"
    //         );

    //     } else {

    //         recommendationInput.value = "Not Recommended";

    //         recommendationInput.classList.remove(
    //             "border-success",
    //             "text-success"
    //         );

    //         recommendationInput.classList.add(
    //             "border-warning",
    //             "text-warning"
    //         );

    //     }

    // });
    document.addEventListener("DOMContentLoaded", function () {
        if (document.getElementById("applicantsTable")) {
            new DataTable("#applicantsTable", {
                pageLength: 10,
                lengthChange: false,
                ordering: true,
                order: [],
                // This page already has its own server-side search form above
                // the table, so the built-in one is left out.
                layout: { topStart: null, topEnd: null },
                columnDefs: [{ orderable: false, targets: 6 }],
                language: {
                    search: "",
                    searchPlaceholder: "Search applicant...",
                    info: "Showing _START_ to _END_ of _TOTAL_",
                    infoEmpty: "No records",
                    zeroRecords: "No matching applicants",
                    emptyTable: "No applicants found",
                    paginate: { previous: "Previous", next: "Next" }
                }
            });
        }
    });
</script>




<?php include includeRoleFooter(__DIR__, 'hr_footer.php'); ?>