<?php
require_once('../init.php');
include('acc_header.php');
require_once("send_verification.php");
/* ===========================
   SUBMIT APPLICATION
=========================== */
if (isset($_POST['submit_application'])) {

    $job_id = $_POST['job_id'];
    $first_name = trim($_POST['first_name']);
    $middle_name = trim($_POST['middle_name']);
    $last_name = trim($_POST['last_name']);
    $suffix = trim($_POST['suffix']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);
    $location = trim($_POST['location']);
    $cover_note = trim($_POST['cover_note']);

    // CHECK REQUIRED FIELDS
    if (
        empty($first_name) || empty($last_name) ||
        empty($email) ||
        empty($phone) ||
        empty($location) ||
        empty($cover_note)
    ) {

        echo "
        <script>
        Swal.fire({
            icon:'warning',
            title:'Incomplete Form',
            text:'Please complete all required fields.'
        }).then(()=>{
            history.back();
        });
        </script>";
        exit;
    }


    // walang account ang applicant
    $applicant_id = null;


    // Upload Resume
    $resume = "";

    if (isset($_FILES['resume']) && $_FILES['resume']['error'] == 0) {

        $folder = "../uploads/resume/";

        if (!is_dir($folder)) {
            mkdir($folder, 0777, true);
        }

        $filename = time() . "_" . basename($_FILES['resume']['name']);

        /*
        | Recorded as a path, not a bare name.
        |
        | This stored "1759_cv.pdf" while the file sat in uploads/resume/,
        | and the Resume card in Employee Registration renders the column
        | straight into an href -- so a CV that WAS on file linked to
        | /hr/1759_cv.pdf, which is nowhere. Every other document in this
        | system stores its folder. Old rows keep working because
        | resumePath() accepts either.
        */
        require_once __DIR__ . "/../includes/resume_file.php";

        $resume = resumePath($filename);

        if ($resume === "") {
            die("That file name cannot be used.");
        }

        /*
        | And the bytes go to the database.
        |
        | uploads/resume/ is in .gitignore, so it is not in the deploy, and
        | the host keeps no disk between deploys in any case: the
        | application row survived and the PDF did not, which is what HR
        | was looking at when the card said Missing.
        */
        require_once __DIR__ . "/../includes/stored_files.php";

        $bytes = @file_get_contents($_FILES['resume']['tmp_name']);

        if ($bytes === false
            || !platformFileStore($conn, $resume, $bytes,
                                  "application/pdf", $filename)) {

            die("Resume upload failed.");
        }

        /* And to the disk as well, where the host keeps one. */
        @move_uploaded_file($_FILES['resume']['tmp_name'], $folder . $filename);
    }
    /*
    | Which company this application belongs to.
    |
    | This page is public -- an applicant has no session and no company of
    | their own -- so the company comes from the job being applied to. Nothing
    | supplied it before, and applications.company_id is NOT NULL with no
    | default, so on a MySQL that is not in strict mode the insert silently
    | tried company_id = 0 and the foreign key rejected the whole application:
    |
    |   Cannot add or update a child row: fk_applications_company
    |
    | Reading the job also confirms it exists and is still open, which nothing
    | checked either -- a stale or hand-typed job_id used to reach the insert.
    */
    $jobLookup = $conn->prepare("
        SELECT company_id, status
        FROM job
        WHERE job_id = ?
        LIMIT 1
    ");
    $jobLookup->bind_param("i", $job_id);
    $jobLookup->execute();
    $jobRow = $jobLookup->get_result()->fetch_assoc();
    $jobLookup->close();

    if (!$jobRow || $jobRow['status'] !== 'Published') {

        echo "
        <script>
        Swal.fire({
            icon:'error',
            title:'Position Unavailable',
            text:'This job posting is no longer open for applications.'
        }).then(()=>{
            window.location = 'apply.php';
        });
        </script>";
        exit;
    }

    $company_id = (int) $jobRow['company_id'];

    // Generate email verification token
    $verification_token = bin2hex(random_bytes(32));
    $stmt = $conn->prepare("
        INSERT INTO applications
        (
            company_id,
            job_id,
            applicant_id,
            first_name,
            middle_name,
            last_name,
            suffix,
            email,
            phone,
            location,
            cover_note,
            resume,
            verification_token,
            email_verified
        )
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ");

    /*
    | The column is enum('0','1'). "No" is not one of them, so it was being
    | stored as an empty string -- which is neither verified nor unverified,
    | and matches nothing the rest of the app looks for.
    */
    $email_verified = '0';

    $stmt->bind_param(
        "iiisssssssssss",
        $company_id,
        $job_id,
        $applicant_id,
        $first_name,
        $middle_name,
        $last_name,
        $suffix,
        $email,
        $phone,
        $location,
        $cover_note,
        $resume,
        $verification_token,
        $email_verified
    );


    if ($stmt->execute()) {

        $update = $conn->prepare("
            UPDATE job
            SET applications = applications + 1
            WHERE job_id = ? AND company_id = ?
        ");

        $update->bind_param("ii", $job_id, $company_id);

        $update->execute();

        // Applicant Full Name
        $fullname = trim(
            $first_name . " " .
            (!empty($middle_name) ? $middle_name . " " : "") .
            $last_name .
            (!empty($suffix) ? " " . $suffix : "")
        );

        // Send Verification Email
        $emailSent = sendVerificationEmail(
            $email,
            $fullname,
            $verification_token
        );

        if ($emailSent) {

            echo "
        <script>
        Swal.fire({
            icon:'success',
            title:'Application Submitted!',
            html:'Your application has been submitted successfully.<br><br><b>Please check your email to verify your application.</b>',
            confirmButtonText:'Continue'
        }).then(()=>{
            window.location='apply.php?page=success';
        });
        </script>";
        } else {

            echo "
        <script>
        Swal.fire({
            icon:'warning',
            title:'Application Saved',
            html:'Your application has been saved, but we could not send the verification email.<br>Please contact HR.',
            confirmButtonText:'OK'
        }).then(()=>{
            window.location='apply.php?page=success';
        });
        </script>";
        }

        exit;
    }
}

$page = $_GET['page'] ?? 'browse';
$job_id = isset($_GET['job_id']) ? intval($_GET['job_id']) : 0;

$selectedJob = null;

if ($job_id > 0) {
    $stmt = $conn->prepare('
        SELECT j.*, b.branch_name
        FROM job j
        INNER JOIN branch b
        ON j.branch_id=b.branch_id
        WHERE j.job_id=?
    ');

    $stmt->bind_param('i', $job_id);
    $stmt->execute();

    $selectedJob = $stmt->get_result()->fetch_assoc();

    $stmt->close();
}

$query = mysqli_query($conn, "
SELECT
    j.*,
    b.branch_name
FROM job j
INNER JOIN branch b
ON j.branch_id = b.branch_id
WHERE j.status='Published'
ORDER BY j.created_at DESC
");

?>
<style>
    body {
        /* store-bg.png has never existed in this repository, so the careers
           application page has always loaded with no background at all. It uses
           the same image the other public pages do. */
        background: url("/assets/retailcore_1st_bg.png") center center;
        background-size: cover;
        font-family: Poppins, sans-serif;
    }

    .career-wrapper {

        width: 90%;
        max-width: 1100px;

        margin: 40px auto;

        background: rgba(255, 255, 255, .95);

        border-radius: 25px;

        padding: 40px;

        box-shadow: 0 10px 40px rgba(0, 0, 0, .15);

    }

    .career-header {

        display: flex;

        align-items: center;

        justify-content: space-between;

    }

    .logo {

        width: 60px;

    }

    .career-header h3 {

        color: #002b6d;

        font-weight: 700;

        flex: 1;

        margin-left: 15px;

    }

    .back-link {

        color: #222;

        text-decoration: none;

    }

    .career-tabs {

        margin-top: 30px;

    }

    .career-tabs button {

        width: 180px;

        height: 45px;

        border-radius: 40px;

        border: 1px solid #999;

        background: #fff;

        margin-right: 15px;

    }

    .career-tabs .active {

        background: #012b63;

        color: #fff;

        border: none;

    }

    .jobs-container {

        margin-top: 30px;

        border: 1px solid #d9d9d9;

        border-radius: 15px;

        padding: 30px;

    }

    .job-card {
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        height: 100%;
        padding: 25px;
        border: 1px solid #d9d9d9;
        border-radius: 15px;
        text-decoration: none;
        background: #fff;
        transition: .3s;
    }

    .job-card:hover {
        transform: translateY(-5px);
        border-color: #002b6d;
        box-shadow: 0 10px 20px rgba(0, 0, 0, .15);
    }

    .job-card h4 {
        color: #002b6d;
        font-size: 22px;
        font-weight: 700;
        margin-bottom: 8px;
    }

    .job-card p {
        margin-bottom: 5px;
    }

    .arrow {

        font-size: 28px;

        color: #002b6d;

    }
</style>
<div class="career-wrapper">

    <div class="career-header">

        <img src="/assets/retailcore-logo.png" class="logo">

        <h3>RetailCore Careers</h3>

        <a href="/../platform/careers.php" class="back-link">
            <i class="bi bi-arrow-left"></i> Back to sign in
        </a>

    </div>

    <hr>

    <div class="career-tabs">

        <button class="<?= $page == 'browse' ? 'active' : '' ?>">
            Browse Jobs
        </button>

        <button class="<?= $page == 'apply' ? 'active' : '' ?>">
            Submit Application
        </button>

        <button class="<?= $page == 'success' ? 'active' : '' ?>">
            Status
        </button>

    </div>

    <div class="mt-4">

        <h2>Open Positions</h2>

        <p class="text-muted">
            Choose a role and start your application.
        </p>

    </div>
    <?php if ($page == 'browse') { ?>

        <div class="jobs-container">

            <?php if (mysqli_num_rows($query) > 0) { ?>

                <div class="row g-4">

                    <?php while ($job = mysqli_fetch_assoc($query)) { ?>

                        <div class="col-xl-4 col-lg-6 col-md-6 mb-4">

                            <div class="card h-100 shadow-sm border-0 rounded-4">

                                <div class="card-body">

                                    <span class="badge bg-success mb-3">
                                        <?= htmlspecialchars($job['status']); ?>
                                    </span>

                                    <h4 class="fw-bold text-primary">
                                        <?= htmlspecialchars($job['job_title']); ?>
                                    </h4>

                                    <p class="mb-2">
                                        <i class="bi bi-building me-2"></i>
                                        <?= htmlspecialchars($job['department']); ?>
                                    </p>

                                    <p class="mb-2">
                                        <i class="bi bi-geo-alt me-2"></i>
                                        <?= htmlspecialchars($job['branch_name']); ?>
                                    </p>

                                    <p class="mb-2">
                                        <i class="bi bi-briefcase me-2"></i>
                                        <?= htmlspecialchars($job['employment_type']); ?>
                                    </p>

                                    <p class="mb-2">
                                        <i class="bi bi-people me-2"></i>
                                        <?= $job['vacancies']; ?> Vacancies
                                    </p>

                                    <p class="text-muted small">
                                        Posted:
                                        <?= date('F d, Y', strtotime($job['created_at'])); ?>
                                    </p>

                                </div>

                                <div class="card-footer bg-white border-0">

                                    <button class="btn btn-primary w-100 rounded-pill viewJobBtn"
                                        data-title="<?= htmlspecialchars($job['job_title']); ?>"
                                        data-department="<?= htmlspecialchars($job['department']); ?>"
                                        data-branch="<?= htmlspecialchars($job['branch_name']); ?>"
                                        data-employment="<?= htmlspecialchars($job['employment_type']); ?>"
                                        data-vacancies="<?= $job['vacancies']; ?>"
                                        data-description="<?= htmlspecialchars($job['job_description']); ?>"
                                        data-responsibilities="<?= htmlspecialchars($job['responsibilities']); ?>"
                                        data-qualification="<?= htmlspecialchars($job['qualifications']); ?>" data-salary="<?=
                                              ($job['salary_min'] && $job['salary_max'])
                                              ? '₱' . number_format($job['salary_min']) . ' - ₱' . number_format($job['salary_max'])
                                              : 'Negotiable';
                                          ?>" data-deadline="<?= $job['application_deadline']; ?>"
                                        data-id="<?= $job['job_id']; ?>" data-bs-toggle="modal" data-bs-target="#viewJobModal">

                                        View Job

                                    </button>

                                </div>

                            </div>

                        </div>

                    <?php } ?>

                </div>

            <?php } else { ?>

                <div class="text-center py-5">

                    <div class="mb-3">
                        <i class="bi bi-briefcase-fill text-primary" style="font-size:70px;"></i>
                    </div>

                    <h3 class="fw-bold text-primary">
                        No Open Positions
                    </h3>

                    <p class="text-muted mb-4">
                        There are currently no available job openings.<br>
                        Please check again later.
                    </p>

                    <button type="button" class="btn btn-primary rounded-pill px-5" onclick="window.location.reload();">
                        <i class="bi bi-arrow-clockwise me-2"></i>
                        Refresh Jobs
                    </button>

                </div>

            <?php } ?>

        </div>

    <?php } elseif ($page == 'apply') { ?>


        <div class="jobs-container">

            <h2 class="fw-bold text-primary mb-4">
                Apply -
                <?= htmlspecialchars($selectedJob['job_title']); ?>
            </h2>

            <form method="POST" enctype="multipart/form-data" id="applicationForm">

                <input type="hidden" name="job_id" value="<?= $selectedJob['job_id']; ?>">

                <div class="row">

                    <div class="col-md-3 mb-3">
                        <label>First Name</label>
                        <input type="text" name="first_name" class="form-control" required>
                    </div>

                    <div class="col-md-3 mb-3">
                        <label>Middle Name</label>
                        <input type="text" name="middle_name" class="form-control">
                    </div>

                    <div class="col-md-3 mb-3">
                        <label>Last Name</label>
                        <input type="text" name="last_name" class="form-control" required>
                    </div>

                    <div class="col-md-3 mb-3">
                        <label>Suffix</label>
                        <select name="suffix" class="form-select">
                            <option value="">None</option>
                            <option>Jr.</option>
                            <option>Sr.</option>
                            <option>II</option>
                            <option>III</option>
                            <option>IV</option>
                        </select>
                    </div>

                    <div class="col-md-6 mb-3">

                        <label>Email</label>

                        <input type="email" name="email" class="form-control">

                    </div>

                    <div class="col-md-6 mb-3">

                        <label>Phone Number</label>

                        <input type="text" name="phone" class="form-control">

                    </div>

                    <div class="col-md-6 mb-3">

                        <label>Address</label>

                        <input type="text" name="location" class="form-control">

                    </div>

                    <div class="col-12 mb-3">

                        <label>Cover Note</label>

                        <textarea name="cover_note" rows="5" class="form-control"></textarea>

                    </div>

                    <div class="col-12 mb-4">

                        <label>Upload Resume</label>

                        <input type="file" name="resume" class="form-control" accept=".pdf,.doc,.docx">

                    </div>

                </div>

                <div class="d-flex justify-content-between">

                    <a href="/accounts/?page=browse" class="btn btn-outline-secondary">
                        Back
                    </a>

                    <button type="submit" name="submit_application" class="btn btn-primary">

                        Submit Application

                    </button>

                </div>

            </form>

        </div>

    <?php } elseif ($page == 'success') { ?>

        <div class="jobs-container text-center">

            <div class="mb-4">
                <i class="bi bi-check-circle-fill text-success" style="font-size:90px;"></i>
            </div>

            <h2 class="fw-bold text-primary">
                Application Submitted Successfully!
            </h2>

            <p class="mt-3">
                Thank you for applying to
                <strong>RetailCore</strong>.
            </p>

            <p class="text-muted">
                Your application has been received by our Human Resources Department.
            </p>

            <hr class="my-4">

            <h3 class="fw-bold text-primary mb-4">
                What's Next?
            </h3>

            <div class="row text-center">

                <div class="col-md-3">
                    <i class="bi bi-file-earmark-check fs-2 text-success"></i>
                    <p class="small mt-2">
                        HR will review your application.
                    </p>
                </div>

                <div class="col-md-3">
                    <i class="bi bi-envelope fs-2 text-primary"></i>
                    <p class="small mt-2">
                        Qualified applicants will receive an email or phone call.
                    </p>
                </div>

                <div class="col-md-3">
                    <i class="bi bi-calendar-event fs-2 text-warning"></i>
                    <p class="small mt-2">
                        Interview schedule will be sent through email.
                    </p>
                </div>

                <div class="col-md-3">
                    <i class="bi bi-telephone fs-2 text-info"></i>
                    <p class="small mt-2">
                        Keep your contact information active.
                    </p>
                </div>

            </div>

            <div class="alert alert-danger mt-4 rounded-pill">
                Please check your email (including Spam/Junk folder) for confirmation.
            </div>

            <div class="mt-4">

                <a href="/accounts/?page=browse" class="btn btn-primary rounded-pill px-5">

                    Back to Careers

                </a>

                <a href="/accounts/?page=interview" class="btn btn-outline-primary rounded-pill px-5">

                    View Application Status

                </a>

            </div>

        </div>

    <?php } ?>



</div>
<!-- JOB VIEW MODAL -->
<div class="modal fade" id="viewJobModal" tabindex="-1">

    <div class="modal-dialog modal-xl modal-dialog-centered">

        <div class="modal-content rounded-4 overflow-hidden">


            <div class="modal-header bg-primary text-white">

                <h4 class="modal-title fw-bold">
                    Job Details
                </h4>

                <button class="btn-close btn-close-white" data-bs-dismiss="modal">
                </button>

            </div>


            <div class="modal-body p-0">


                <!-- Banner -->
                <div class="position-relative">

                    <img src="/assets/careers-banner.jpg" class="w-100" style="height:320px;object-fit:cover;">


                    <div class="position-absolute top-50 start-50 translate-middle text-center text-white">

                        <span class="badge bg-warning text-dark fs-6">
                            WE ARE HIRING
                        </span>


                        <h1 class="fw-bold mt-3" id="viewTitle">
                            Job Title
                        </h1>


                        <p>
                            RetailCore Retail OS
                        </p>

                    </div>

                </div>



                <div class="p-4">


                    <div class="row text-muted mb-4">


                        <div class="col-md-6 mb-2">
                            📍
                            <span id="viewBranch">
                            </span>
                        </div>


                        <div class="col-md-6 mb-2">
                            🏢
                            <span id="viewDepartment">
                            </span>
                        </div>


                        <div class="col-md-6 mb-2">
                            💼
                            <span id="viewEmployment">
                            </span>
                        </div>


                        <div class="col-md-6 mb-2">
                            👥
                            <span id="viewVacancies">
                            </span>
                        </div>


                    </div>



                    <hr>


                    <h5 class="fw-bold">
                        About the Role
                    </h5>

                    <p id="viewDescription"></p>


                    <hr>


                    <h5 class="fw-bold">
                        Responsibilities
                    </h5>

                    <ul id="viewResponsibilities">
                    </ul>



                    <h5 class="fw-bold mt-4">
                        Qualifications
                    </h5>

                    <p id="viewQualification"></p>

                    <div class="row mt-4">

                        <div class="col-md-6">
                            <b>Salary:</b>
                            <span id="viewSalary"></span>
                        </div>


                        <div class="col-md-6">
                            <b>Application Deadline:</b>
                            <span id="viewDeadline"></span>
                        </div>

                    </div>



                    <div class="d-grid mt-4">

                        <a id="applyButton" class="btn btn-warning btn-lg rounded-pill">

                            Apply Now

                        </a>

                    </div>


                </div>


            </div>


        </div>

    </div>

</div>
<script>
    const form = document.getElementById("applicationForm");

    if (form) {

        form.addEventListener("submit", function (e) {

            let firstName = document.querySelector("[name='first_name']").value.trim();
            let lastName = document.querySelector("[name='last_name']").value.trim();
            let email = document.querySelector("[name='email']").value.trim();
            let phone = document.querySelector("[name='phone']").value.trim();
            let location = document.querySelector("[name='location']").value.trim();
            let file = document.querySelector("[name='resume']").files[0];

            if (firstName === "") {
                e.preventDefault();
                Swal.fire({
                    icon: "warning",
                    title: "First Name Required",
                    text: "Please enter your first name."
                });
                return;
            }

            if (lastName === "") {
                e.preventDefault();
                Swal.fire({
                    icon: "warning",
                    title: "Last Name Required",
                    text: "Please enter your last name."
                });
                return;
            }

            // Email
            if (email === "") {
                e.preventDefault();
                Swal.fire({
                    icon: "warning",
                    title: "Email Required",
                    text: "Please enter your email."
                });
                return;
            }

            // Email format
            let emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

            if (!emailPattern.test(email)) {
                e.preventDefault();
                Swal.fire({
                    icon: "error",
                    title: "Invalid Email",
                    text: "Please enter a valid email address."
                });
                return;
            }

            // Phone
            if (phone === "") {
                e.preventDefault();
                Swal.fire({
                    icon: "warning",
                    title: "Phone Number Required",
                    text: "Please enter your phone number."
                });
                return;
            }

            // Philippine number
            let phonePattern = /^(09\d{9}|\+639\d{9})$/;

            if (!phonePattern.test(phone)) {
                e.preventDefault();
                Swal.fire({
                    icon: "error",
                    title: "Invalid Phone Number",
                    text: "Example: 09123456789"
                });
                return;
            }

            // Location
            if (location === "") {
                e.preventDefault();
                Swal.fire({
                    icon: "warning",
                    title: "Location Required",
                    text: "Please enter your address/location."
                });
                return;
            }



            // Resume Required
            if (!file) {
                e.preventDefault();
                Swal.fire({
                    icon: "warning",
                    title: "Resume Required",
                    text: "Please upload your resume."
                });
                return;
            }

            // Allowed File Types
            let allowed = [
                "application/pdf",
                "application/msword",
                "application/vnd.openxmlformats-officedocument.wordprocessingml.document"
            ];

            if (!allowed.includes(file.type)) {
                e.preventDefault();
                Swal.fire({
                    icon: "error",
                    title: "Invalid File",
                    text: "Only PDF, DOC and DOCX files are allowed."
                });
                return;
            }

            // Optional: Maximum file size (5MB)
            // if (file.size > 5 * 1024 * 1024) {
            //     e.preventDefault();
            //     Swal.fire({
            //         icon: "error",
            //         title: "File Too Large",
            //         text: "Resume must not exceed 5MB."
            //     });
            //     return;
            // }

        });

    }
    // ===============================
    // VIEW JOB MODAL
    // ===============================

    document.querySelectorAll(".viewJobBtn").forEach(function (btn) {

        btn.addEventListener("click", function () {

            console.log(this.dataset);

            document.getElementById("viewTitle").textContent =
                this.dataset.title;

            document.getElementById("viewBranch").textContent =
                this.dataset.branch;

            document.getElementById("viewDepartment").textContent =
                this.dataset.department;

            document.getElementById("viewEmployment").textContent =
                this.dataset.employment;

            document.getElementById("viewVacancies").textContent =
                this.dataset.vacancies + " Vacancies";

            document.getElementById("viewDescription").innerHTML =
                this.dataset.description;

            document.getElementById("viewResponsibilities").innerHTML =
                this.dataset.responsibilities.replace(/\n/g, "<br>");

            document.getElementById("viewQualification").innerHTML =
                this.dataset.qualification.replace(/\n/g, "<br>");

            document.getElementById("viewSalary").textContent =
                this.dataset.salary;

            document.getElementById("viewDeadline").textContent =
                this.dataset.deadline;

            document.getElementById("applyButton").href =
                "?page=apply&job_id=" + this.dataset.id;

        });

    });
</script>