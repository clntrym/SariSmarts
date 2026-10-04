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

$alert = '';

$branchResult = mysqli_query($conn, "
SELECT branch_id, branch_name
FROM branch
WHERE status='Active' AND company_id=" . (int) $companyId . "
ORDER BY branch_name
");

/*
| Seeded on first use rather than queried into an empty dropdown -- the table
| had no rows for anybody, so Create Job Posting listed no departments at all.
*/
$departments = ensureCompanyDepartments($conn, $companyId);


if (isset($_POST['publish_job']) || isset($_POST['save_draft']) || isset($_POST['update_job'])) {
    $branch_id = intval($_POST['branch_id']);
    $job_title = trim($_POST['job_title']);
    $department = trim($_POST['department']);
    $vacancies = intval($_POST['number_vacancies']);
    $employment_type = trim($_POST['employment_type']);

    $salary_min = isset($_POST['salary_min']) && $_POST['salary_min'] !== ''
        ? (int) $_POST['salary_min']
        : null;

    $salary_max = isset($_POST['salary_max']) && $_POST['salary_max'] !== ''
        ? (int) $_POST['salary_max']
        : null;
    $application_deadline = $_POST['deadline'];
    $job_description = trim($_POST['job_description']);
    $responsibilities = trim($_POST['responsibilities']);
    $qualifications = trim($_POST['qualifications']);
    $isEdit = !empty($_POST['job_id']);
    if (isset($_POST['publish_job'])) {
        $status = 'Published';
    } elseif (isset($_POST['save_draft'])) {
        $status = 'Draft';
    }

    $hasError = false;

    // ===========================
    // Required Fields
    // ===========================

    if (isset($_POST['publish_job'])) {
        if (
            empty($job_title) ||
            empty($department) ||
            empty($branch_id) ||
            empty($vacancies) ||
            empty($application_deadline)
        ) {
            $hasError = true;

            $alert = "
            Swal.fire({
                icon:'error',
                title:'Missing Information',
                text:'Please complete all required fields.'
            });
            ";
        }
    }

    // ===========================
    // Deadline
    // ===========================

    $today = new DateTime(date('Y-m-d'));

    $minDeadline = (clone $today)->modify('+7 days');
    $maxDeadline = (clone $today)->modify('+4 months');

    $deadline = new DateTime($application_deadline);

    if (!$hasError) {
        $deadline = new DateTime($application_deadline);

        if ($deadline < $minDeadline) {
            $hasError = true;

            $alert = "
        Swal.fire({
            icon:'error',
            title:'Invalid Deadline',
            text:'Application deadline must be at least 1 week from today.'
        });
        ";
        } elseif ($deadline > $maxDeadline) {
            $hasError = true;

            $alert = "
        Swal.fire({
            icon:'error',
            title:'Invalid Deadline',
            text:'Application deadline cannot exceed 4 months from today.'
        });
        ";
        }
    }

    // ===========================
    // Vacancies
    // ===========================

    if (!$hasError) {
        if (!filter_var($vacancies, FILTER_VALIDATE_INT) || $vacancies <= 0) {
            $hasError = true;

            $alert = "
        Swal.fire({
            icon:'error',
            title:'Invalid Vacancies',
            text:'Vacancies must be a whole number greater than zero.'
        });
        ";
        }
    }
    // ===========================
    // Salary Validation
    // ===========================

    if (!$hasError) {
        if ($salary_min !== null) {
            if ($salary_min < 1 || $salary_min > 50000) {
                $hasError = true;

                $alert = "
            Swal.fire({
                icon:'error',
                title:'Invalid Minimum Salary',
                text:'Minimum salary must be between ₱1 and ₱50,000.'
            });
            ";
            }
        }

        if (!$hasError && $salary_max !== null) {
            if ($salary_max < 1 || $salary_max > 50000) {
                $hasError = true;

                $alert = "
            Swal.fire({
                icon:'error',
                title:'Invalid Maximum Salary',
                text:'Maximum salary must be between ₱1 and ₱50,000.'
            });
            ";
            }
        }

        if (
            !$hasError &&
            $salary_min !== null &&
            $salary_max !== null &&
            $salary_min > $salary_max
        ) {
            $hasError = true;

            $alert = "
        Swal.fire({
            icon:'error',
            title:'Invalid Salary Range',
            text:'Minimum salary cannot be greater than maximum salary.'
        });
        ";
        }
    }

    // ===========================
    // Duplicate Job
    // ===========================

    if (!$hasError) {
        $jobCheck = strtolower(
            preg_replace('/\s+/', '', $job_title)
        );

        if ($isEdit) {
            $job_id = intval($_POST['job_id']);

            $stmt = $conn->prepare("
            SELECT job_id
            FROM job
            WHERE
            REPLACE(LOWER(job_title),' ','')=?
            AND branch_id=?
            AND department=?
            AND job_id<>?
            AND company_id=?
            ");

            $stmt->bind_param(
                'sisii',
                $jobCheck,
                $branch_id,
                $department,
                $job_id,
                $companyId
            );
        } else {
            $stmt = $conn->prepare("
    SELECT job_id
    FROM job
    WHERE
        REPLACE(LOWER(job_title),' ','')=?
        AND branch_id=?
        AND department=?
        AND company_id=?
    ");
            $stmt->bind_param(
                'sisi',
                $jobCheck,
                $branch_id,
                $department,
                $companyId
            );
        }

        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows > 0) {
            $hasError = true;

            $alert = "
            Swal.fire({
                icon:'warning',
                title:'Duplicate Job',
                text:'This job already exists for this branch.'
            });
            ";
        }

        $stmt->close();
    }

    // ===========================
    // INSERT
    // ===========================

    if (!$hasError) {
        if (!empty($_POST['job_id']) > 0) {
            // ===========================
            // UPDATE JOB
            // ===========================

            $job_id = intval($_POST['job_id']);

            $stmt = $conn->prepare('
UPDATE job
SET
    branch_id=?,
    job_title=?,
    department=?,
    vacancies=?,
    employment_type=?,
    responsibilities=?,
    qualifications=?,
    salary_min=?,
    salary_max=?,
    application_deadline=?,
    job_description=?,
    status=?
WHERE job_id=? AND company_id=?
');


            $stmt->bind_param(
                "ississsddsssii",
                $branch_id,
                $job_title,
                $department,
                $vacancies,
                $employment_type,
                $responsibilities,
                $qualifications,
                $salary_min,
                $salary_max,
                $application_deadline,
                $job_description,
                $status,
                $job_id,
                $companyId
            );

            if ($stmt->execute()) {
                $alert = "
            Swal.fire({
                icon:'success',
                title:'Updated!',
                text:'Job updated successfully.'
            }).then(()=>{
                location='recruitment.php';
            });
            ";
            }

            $stmt->close();
        } else {
            // ===========================
            // INSERT JOB
            // ===========================

            $stmt = $conn->prepare('
            INSERT INTO job(
                company_id,
                branch_id,
                job_title,
                department,
                vacancies,
                employment_type,
                salary_min,
                salary_max,
                application_deadline,
                job_description,
                responsibilities,
                qualifications,
                status
            )
            VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)
        ');

            $stmt->bind_param(
                'iississssssss',
                $companyId,
                $branch_id,
                $job_title,
                $department,
                $vacancies,
                $employment_type,
                $salary_min,
                $salary_max,
                $application_deadline,
                $job_description,
                $responsibilities,
                $qualifications,
                $status
            );

            if ($stmt->execute()) {
                if (isset($_POST['publish_job'])) {
                    $title = 'Job Published!';
                    $message = 'The job posting is now visible to applicants.';
                } else {
                    $title = 'Draft Saved!';
                    $message = 'The job has been saved as a draft.';
                }

                $alert = "
            Swal.fire({
                icon:'success',
                title:'$title',
                text:'$message'
            }).then(()=>{
                location='recruitment.php';
            });
            ";
            }

            $stmt->close();
        }
    }
}

// Search

$search = '';

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$sql = '
SELECT
    j.*,
    b.branch_name
FROM job j
LEFT JOIN branch b
ON j.branch_id = b.branch_id AND b.company_id = j.company_id
';
$where = ["j.company_id = " . (int) $companyId];
if ($search != '') {
    $search = mysqli_real_escape_string($conn, $search);
    $where[] = "(
        job_title LIKE '%$search%'
        OR department LIKE '%$search%'
        OR employment_type LIKE '%$search%'
        OR education_requirement LIKE '%$search%'
    )";
}
if (count($where) > 0) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY job_id DESC';
$jobQuery = mysqli_query($conn, $sql);

?>
<style>
    .branch-modal {
        height: 90vh;
        max-height: 90vh;
    }

    .branch-modal .modal-body {
        overflow-y: auto;
        max-height: calc(90vh - 140px);
    }

    .branch-modal .card {

        margin-bottom: 15px;

    }

    #jobModal .modal-content {
        max-height: 90vh;
    }

    #jobModal .modal-body {
        overflow-y: auto;
        max-height: calc(90vh - 150px);
    }
</style>
<div class="container-fluid py-1">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <div>
            <h1 class=" mb-0 fw-bold" style="color: #00224c;">
                Recuitment Management
            </h1>
            <p class="text-muted mb-0">
                Manage all job postings, applicants, interviews, hiring recommendations, and recruitment workflow..
            </p>
        </div>
        <div>
            <button class="btn btn-outline-secondary me-2" id="viewDraftBtn">
                <i class="bi bi-file-earmark-text me-1"></i>
                View Drafts
            </button>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#jobModal">
                <i class="bi bi-plus-lg me-2"></i>
                Create Job Posting
            </button>
        </div>
    </div>
    <div class="row g-4">
        <!-- LEFT -->
        <div class="col-lg-12">
            <div class="card shadow-sm border-2 rounded-4">
                <div class="card-body">
                    <div class="table-responsive table-container">
                        <table id="recruitmentTable" class="table align-middle" style="width:100%">
                            <thead>
                                <tr>
                                    <th>Job title</th>
                                    <th>Department</th>
                                    <th>Branch</th>
                                    <th>Vacancies</th>
                                    <th>Application</th>
                                    <th>Interviews</th>
                                    <th>Status</th>
                                    <th>Posted</th>
                                    <th>Deadline</th>
                                    <th class="text-center">Action</th>
                                </tr>
                            </thead>
                            <tbody id="jobTable">
                                <?php if (mysqli_num_rows($jobQuery) > 0) { ?>
                                    <?php while ($row = mysqli_fetch_assoc($jobQuery)) { ?>
                                        <tr>
                                            <td><?= htmlspecialchars($row['job_title']) ?></td>
                                            <td><?= htmlspecialchars($row['department']) ?></td>
                                            <td><?= htmlspecialchars($row['branch_name']) ?></td>
                                            <td><?= htmlspecialchars($row['vacancies']) ?></td>
                                            <td><?= htmlspecialchars($row['applications']) ?></td>
                                            <td><?= htmlspecialchars($row['interviews']) ?></td>
                                            <td><?= htmlspecialchars($row['status']) ?></td>
                                            <td><?= htmlspecialchars($row['created_at']) ?></td>
                                            <td><?= htmlspecialchars($row['application_deadline']) ?></td>
                                            <td class="text-center">
                                                <button type="button" class="btn btn-sm btn-warning editJobBtn"
                                                    data-id="<?= $row['job_id']; ?>"
                                                    data-title="<?= htmlspecialchars($row['job_title']); ?>"
                                                    data-department="<?= htmlspecialchars($row['department']); ?>"
                                                    data-branch="<?= $row['branch_id']; ?>"
                                                    data-vacancies="<?= $row['vacancies']; ?>"
                                                    data-employment="<?= htmlspecialchars($row['employment_type']); ?>"
                                                    data-salarymin="<?= $row['salary_min']; ?>"
                                                    data-salarymax="<?= $row['salary_max']; ?>"
                                                    data-deadline="<?= $row['application_deadline']; ?>"
                                                    data-description="<?= htmlspecialchars($row['job_description']); ?>"
                                                    data-responsibilities="<?= htmlspecialchars($row['responsibilities']); ?>"
                                                    data-qualifications="<?= htmlspecialchars($row['qualifications']); ?>"
                                                    data-status="<?= $row['status']; ?>" data-bs-toggle="modal"
                                                    data-bs-target="#jobModal">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                                <a href="applications.php?job_id=<?= $row['job_id']; ?>"
                                                    class="btn btn-sm btn-primary">
                                                    Application
                                                </a>
                                            </td>

                                        </tr>

                                    <?php } ?>

                                <?php } else { ?>

                                    <tr>
                                        <td colspan="6" class="text-center text-muted">
                                            No branches found.
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
<div class="modal fade" id="jobModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content branch-modal overflow-hidden">
            <form method="POST">
                <input type="hidden" name="job_id" id="job_id" value="">
                <div class="modal-header border-0">

                    <div>

                        <h3 class="fw-bold text-primary mb-1">

                            <i class="bi bi-briefcase-fill me-2"></i>

                            Create Job Posting

                        </h3>

                        <small class="text-muted">

                            Create and publish a professional hiring advertisement.

                        </small>

                    </div>

                    <button class="btn-close" data-bs-dismiss="modal"></button>

                </div>
                <hr class="m-0">
                <div class="modal-body">

                    <div class="row">

                        <!-- ========================= -->
                        <!-- LEFT SIDE -->
                        <!-- ========================= -->

                        <div class="col-lg-5">

                            <!-- Job Information -->
                            <div class="card shadow-sm border-0 rounded-4 mb-3">

                                <div class="card-header bg-primary text-white rounded-top-4">

                                    <h5 class="mb-0">
                                        <i class="bi bi-briefcase-fill me-2"></i>
                                        Job Information
                                    </h5>

                                </div>

                                <div class="card-body">

                                    <!-- Job Title -->
                                    <div class="mb-3">
                                        <label class="form-label fw-semibold">
                                            Job Title
                                        </label>

                                        <input type="text" class="form-control" id="job_title" name="job_title"
                                            placeholder="e.g. Cashier..." required>
                                    </div>

                                    <!-- Department -->
                                    <div class="mb-3">
                                        <label class="form-label fw-semibold">
                                            Department
                                        </label>

                                        <select class="form-select" id="department" name="department">

                                            <option value="">Select Department</option>

                                            <?php foreach ($departments as $dept): ?>

                                                <option value="<?= htmlspecialchars($dept['department_name']) ?>">

                                                    <?= htmlspecialchars($dept['department_name']) ?>

                                                </option>

                                            <?php endforeach; ?>

                                        </select>
                                    </div>

                                    <!-- Branch -->
                                    <div class="mb-3">

                                        <label class="form-label fw-semibold">

                                            Branch

                                        </label>

                                        <select class="form-select" id="branch_id" name="branch_id">

                                            <option value="">

                                                Select Branch

                                            </option>

                                            <?php

                                            mysqli_data_seek($branchResult, 0);

                                            while ($branch = mysqli_fetch_assoc($branchResult)) {

                                                ?>

                                                <option value="<?= $branch['branch_id']; ?>">

                                                    <?= $branch['branch_name']; ?>

                                                </option>

                                            <?php } ?>

                                        </select>

                                    </div>

                                    <div class="row">

                                        <div class="col-md-6">

                                            <label class="form-label fw-semibold">

                                                Vacancies

                                            </label>

                                            <input type="number" class="form-control" id="number_vacancies"
                                                name="number_vacancies">

                                        </div>

                                        <div class="col-md-6">

                                            <label class="form-label fw-semibold">

                                                Employment Type

                                            </label>

                                            <select class="form-select" id="employment_type" name="employment_type">

                                                <option value="Full-time">
                                                    Full-time
                                                </option>

                                                <option value="Part-time">
                                                    Part-time
                                                </option>


                                            </select>

                                        </div>
                                        <div class="row mt-3">

                                            <div class="col-md-6">

                                                <label class="form-label fw-semibold">
                                                    Minimum Salary
                                                </label>

                                                <input type="number" class="form-control" id="salary_min"
                                                    name="salary_min" placeholder="₱ Minimum Salary">

                                            </div>


                                            <div class="col-md-6">

                                                <label class="form-label fw-semibold">
                                                    Maximum Salary
                                                </label>

                                                <input type="number" class="form-control" id="salary_max"
                                                    name="salary_max" placeholder="₱ Maximum Salary">

                                            </div>

                                        </div>


                                        <div class="mb-3 mt-3">

                                            <label class="form-label fw-semibold">
                                                Application Deadline
                                            </label>

                                            <input type="date" class="form-control" id="deadline" name="deadline">

                                        </div>

                                    </div>

                                </div>

                            </div>

                            <!-- Job Description -->
                            <div class="card shadow-sm border-0 rounded-4">

                                <div class="card-header bg-warning rounded-top-4">

                                    <h5 class="mb-0">

                                        <i class="bi bi-file-earmark-text me-2"></i>

                                        Job Description

                                    </h5>

                                </div>

                                <div class="card-body">

                                    <!-- About -->
                                    <div class="mb-3">

                                        <label class="form-label fw-semibold">

                                            About the Role

                                        </label>

                                        <textarea class="form-control" rows="4" id="job_description"
                                            name="job_description" placeholder="Describe the job..."></textarea>

                                    </div>

                                    <!-- Responsibilities -->

                                    <div class="mb-3">

                                        <label class="form-label fw-semibold">

                                            Key Responsibilities

                                        </label>

                                        <textarea class="form-control" rows="4" id="responsibilities"
                                            name="responsibilities" placeholder="Example:
                                • Develop web applications
                                • Maintain the system
                                • Coordinate with the team"></textarea>

                                    </div>

                                    <!-- Qualifications -->

                                    <div class="mb-3">

                                        <label class="form-label fw-semibold">

                                            Qualifications

                                        </label>

                                        <textarea class="form-control" rows="4" id="qualifications"
                                            name="qualifications" placeholder="Example:
                                • BSIT Graduate
                                • 2 Years Experience
                                • PHP & MySQL"></textarea>

                                    </div>



                                </div>

                            </div>

                        </div>

                        <!-- ========================= -->
                        <!-- RIGHT SIDE -->
                        <!-- ========================= -->

                        <div class="col-lg-7">

                            <div class="card shadow border-0 rounded-4">

                                <div class="card-header bg-dark text-white rounded-top-4">

                                    <h5 class="mb-0">

                                        <i class="bi bi-eye-fill me-2"></i>

                                        Live Job Preview

                                    </h5>

                                </div>

                                <div class="card-body p-0">

                                    <!-- Company Banner -->
                                    <div class="position-relative">

                                        <img src="../assets/careers-banner.jpg" class="img-fluid rounded-top"
                                            style="height:220px;width:100%;object-fit:cover;">

                                        <div
                                            class="position-absolute top-50 start-50 translate-middle text-center text-white">

                                            <span class="badge bg-warning text-dark fs-6 mb-3">
                                                WE ARE HIRING
                                            </span>

                                            <h2 class="fw-bold mb-2">

                                                <span id="previewTitle">

                                                    Job Title

                                                </span>

                                            </h2>

                                            <p class="mb-0">

                                                RetailCore Retail OS

                                            </p>

                                        </div>

                                    </div>

                                    <!-- Job Details -->

                                    <div class="p-4">

                                        <div class="row text-muted mb-4">

                                            <div class="col-md-6 mb-2">

                                                📍
                                                <span id="previewBranch">
                                                    Branch
                                                </span>

                                            </div>

                                            <div class="col-md-6 mb-2">

                                                🏢
                                                <span id="previewDepartment">
                                                    Department
                                                </span>

                                            </div>

                                            <div class="col-md-6 mb-2">

                                                💼
                                                <span id="previewEmployment">
                                                    Employment Type
                                                </span>

                                            </div>

                                            <div class="col-md-6 mb-2">

                                                👥
                                                <span id="previewVacancies">
                                                    0 Vacancy
                                                </span>

                                            </div>

                                        </div>

                                        <hr>

                                        <h5 class="fw-bold">

                                            About the Role

                                        </h5>

                                        <p id="previewDescription">

                                            The job description will appear here.

                                        </p>

                                        <hr>

                                        <h5 class="fw-bold">

                                            Responsibilities

                                        </h5>

                                        <div id="previewResponsibilities" class="text-muted">

                                            No responsibilities yet.

                                        </div>

                                        <hr>

                                        <h5 class="fw-bold">

                                            Qualifications

                                        </h5>

                                        <div id="previewQualifications" class="text-muted">

                                            No qualifications yet.

                                        </div>

                                        <hr>

                                        <div class="row">

                                            <div class="col-md-6">

                                                <strong>Salary</strong>

                                                <p id="previewSalary">

                                                    Negotiable

                                                </p>

                                            </div>

                                            <div class="col-md-6">

                                                <strong>Application Deadline</strong>

                                                <p id="previewDeadline">

                                                    --

                                                </p>

                                            </div>

                                        </div>

                                        <div class="d-grid mt-4">

                                            <button class="btn btn-warning btn-lg rounded-pill" disabled>

                                                Apply Now

                                            </button>

                                        </div>

                                    </div>

                                </div>

                            </div>

                        </div>

                    </div>

                </div>

                <div class="modal-footer border-0 bg-white sticky-bottom">
                    <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">
                        Cancel
                    </button>
                    <button type="submit" name="save_draft" class="btn btn-warning rounded-pill px-4">
                        Save Draft
                    </button>
                    <button type="submit" id="saveBtn" name="publish_job" class="btn btn-primary">
                        Publish Job
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if (!empty($alert)) { ?>
    <script>
        document.addEventListener("DOMContentLoaded", function () {
            <?= $alert ?>
        });
    </script>
<?php } ?>

<script>
    document.querySelectorAll(".editJobBtn").forEach(function (btn) {

        btn.addEventListener("click", function () {

            document.getElementById("job_id").value = this.dataset.id;

            document.getElementById("job_title").value =
                this.dataset.title;

            document.getElementById("department").value =
                this.dataset.department;

            document.getElementById("branch_id").value =
                this.dataset.branch;

            document.getElementById("number_vacancies").value =
                this.dataset.vacancies;

            let employment = this.dataset.employment;

            document.getElementById("employment_type").value = employment;

            document.getElementById("salary_min").value =
                this.dataset.salarymin;

            document.getElementById("salary_max").value =
                this.dataset.salarymax;

            document.getElementById("deadline").value =
                this.dataset.deadline;

            document.getElementById("job_description").value =
                this.dataset.description;


            document.getElementById("responsibilities").value =
                this.dataset.responsibilities;


            document.getElementById("qualifications").value =
                this.dataset.qualifications;


            updatePreview();


            document.querySelector(".modal-title").innerHTML =
                "Edit Job";


        });

    });

    document.querySelector("[data-bs-target='#jobModal']").addEventListener("click", function () {

        document.querySelector("#jobModal form").reset();

        document.querySelector(".modal-title").innerHTML = "Create Job Posting";

        document.getElementById("publishBtn").classList.remove("d-none");
        document.getElementById("updateBtn").classList.add("d-none");

    });

    document.querySelector("[data-bs-target='#jobModal']").addEventListener("click", function () {

        document.querySelector("#jobModal form").reset();

        document.getElementById("job_id").value = "";

        document.querySelector(".modal-title").innerHTML = "Create Job Posting";

    });

    // DataTable
    var recruitDT = null;
    if (typeof DataTable !== "undefined") {
        recruitDT = new DataTable("#recruitmentTable", {
            pageLength: 10,
            lengthChange: false,
            ordering: true,
            order: [],
            columnDefs: [{ orderable: false, targets: 9 }],
            language: {
                search: "",
                searchPlaceholder: "Search job, department, branch...",
                info: "Showing _START_ to _END_ of _TOTAL_",
                infoEmpty: "No records",
                zeroRecords: "No matching job postings",
                emptyTable: "No job postings found",
                paginate: { previous: "Previous", next: "Next" }
            }
        });
    }

    document.querySelector("#jobModal form").addEventListener("submit", function (e) {

        let jobTitle = document.getElementById("job_title").value.trim();
        let department = document.getElementById("department").value;
        let branch = document.getElementById("branch_id").value;
        let vacancies = document.getElementById("number_vacancies").value;
        let salaryMin = document.getElementById("salary_min").value;
        let salaryMax = document.getElementById("salary_max").value;
        let deadline = document.getElementById("deadline").value;

        // Required Fields
        if (
            jobTitle === "" ||
            department === "" ||
            branch === "" ||
            vacancies === "" ||
            deadline === ""
        ) {
            e.preventDefault();

            Swal.fire({
                icon: "error",
                title: "Missing Information",
                text: "Please complete all required fields."
            });

            return;
        }

        // Space-only check
        if (/^\s+$/.test(jobTitle)) {
            e.preventDefault();
            Swal.fire({ icon: "error", title: "Invalid Job Title", text: "Job title cannot be only spaces." });
            return;
        }

        // Special characters only check
        if (/^[^a-zA-Z0-9\s]+$/.test(jobTitle)) {
            e.preventDefault();
            Swal.fire({ icon: "error", title: "Invalid Job Title", text: "Job title cannot contain only special characters." });
            return;
        }

        // Job title validation
        if (jobTitle.length < 3) {
            e.preventDefault();

            Swal.fire({
                icon: "error",
                title: "Invalid Job Title",
                text: "Job title must contain at least 3 characters."
            });

            return;
        }

        // Vacancies
        const vacancyCount = Number(vacancies);

        if (
            isNaN(vacancyCount) ||
            !Number.isInteger(vacancyCount) ||
            vacancyCount <= 0
        ) {
            e.preventDefault();

            Swal.fire({
                icon: "error",
                title: "Invalid Vacancies",
                text: "Vacancies must be a whole number greater than zero."
            });

            return;
        }

        // Salary validation
        if (salaryMin !== "") {

            if (parseInt(salaryMin) < 1 || parseInt(salaryMin) > 50000) {

                e.preventDefault();

                Swal.fire({
                    icon: "error",
                    title: "Invalid Minimum Salary",
                    text: "Minimum salary must be between ₱1 and ₱50,000."
                });

                return;
            }

        }

        if (salaryMax !== "") {

            if (parseInt(salaryMax) < 1 || parseInt(salaryMax) > 50000) {

                e.preventDefault();

                Swal.fire({
                    icon: "error",
                    title: "Invalid Maximum Salary",
                    text: "Maximum salary must be between ₱1 and ₱50,000."
                });

                return;
            }

        }

        if (salaryMin !== "" && salaryMax !== "") {

            if (parseInt(salaryMin) > parseInt(salaryMax)) {

                e.preventDefault();

                Swal.fire({
                    icon: "error",
                    title: "Invalid Salary Range",
                    text: "Minimum salary cannot be greater than maximum salary."
                });

                return;
            }

        }

        // Deadline validation
        let today = new Date();

        let minDate = new Date();
        minDate.setDate(today.getDate() + 7);

        let maxDate = new Date();
        maxDate.setMonth(today.getMonth() + 4);

        let selectedDate = new Date(deadline);

        if (selectedDate < minDate) {

            e.preventDefault();

            Swal.fire({
                icon: "error",
                title: "Invalid Deadline",
                text: "Deadline must be at least 7 days from today."
            });

            return;
        }

        if (selectedDate > maxDate) {

            e.preventDefault();

            Swal.fire({
                icon: "error",
                title: "Invalid Deadline",
                text: "Deadline cannot exceed 4 months from today."
            });

            return;
        }

    });

    // ===============================
    // VIEW DRAFTS TOGGLE
    // ===============================

    (function () {
        var showingDrafts = false;
        var draftBtn = document.getElementById("viewDraftBtn");

        if (draftBtn) {
            draftBtn.addEventListener("click", function () {
                showingDrafts = !showingDrafts;

                if (recruitDT) {
                    recruitDT.column(6).search(showingDrafts ? "Draft" : "").draw();
                }

                if (showingDrafts) {
                    draftBtn.classList.remove("btn-outline-secondary");
                    draftBtn.classList.add("btn-primary");
                    draftBtn.innerHTML = '<i class="bi bi-list-ul me-1"></i> Show All';
                } else {
                    draftBtn.classList.remove("btn-primary");
                    draftBtn.classList.add("btn-outline-secondary");
                    draftBtn.innerHTML = '<i class="bi bi-file-earmark-text me-1"></i> View Drafts';
                }
            });
        }
    })();

    // ===============================
    // LIVE JOB PREVIEW
    // ===============================

    function updatePreview() {

        document.getElementById("previewTitle").innerHTML =
            document.getElementById("job_title").value || "Job Title";


        let department = document.getElementById("department");

        document.getElementById("previewDepartment").innerHTML =
            department.value ? department.options[department.selectedIndex].text : "Department";


        let branch = document.getElementById("branch_id");

        document.getElementById("previewBranch").innerHTML =
            branch.value ? branch.options[branch.selectedIndex].text : "Branch";


        document.getElementById("previewEmployment").innerHTML =
            document.getElementById("employment_type").value;


        let vacancy = document.getElementById("number_vacancies").value;

        document.getElementById("previewVacancies").innerHTML =
            vacancy == "" ? "0 Vacancy" : vacancy + " Vacancies";


        document.getElementById("previewDescription").innerHTML =
            document.getElementById("job_description").value ||
            "The job description will appear here.";


        // RESPONSIBILITIES FIX
        let responsibilities = document.getElementById("responsibilities").value;

        document.getElementById("previewResponsibilities").innerHTML =
            responsibilities ?
                responsibilities.replace(/\n/g, "<br>") :
                "No responsibilities yet.";


        // QUALIFICATIONS FIX
        let qualifications = document.getElementById("qualifications").value;

        document.getElementById("previewQualifications").innerHTML =
            qualifications ?
                qualifications.replace(/\n/g, "<br>") :
                "No qualifications yet.";


        let min = document.getElementById("salary_min").value;

        let max = document.getElementById("salary_max").value;


        if (min && max) {

            document.getElementById("previewSalary").innerHTML =
                "₱" + Number(min).toLocaleString() +
                " - ₱" +
                Number(max).toLocaleString();

        } else {

            document.getElementById("previewSalary").innerHTML =
                "Negotiable";

        }


        document.getElementById("previewDeadline").innerHTML =
            document.getElementById("deadline").value || "--";

    }
    document.querySelectorAll(

        "#job_title,#department,#branch_id,#employment_type,#number_vacancies,#salary_min,#salary_max,#deadline,#job_description,#responsibilities,#qualifications"

    )
        .forEach(function (input) {

            input.addEventListener("input", updatePreview);

            input.addEventListener("change", updatePreview);

        });

    document.getElementById("previewResponsibilities").innerHTML =
        document.getElementById("responsibilities").value
            .replace(/\n/g, "<br>") ||
        "No responsibilities yet.";

    document.getElementById("previewQualifications").innerHTML =
        document.getElementById("qualifications").value
            .replace(/\n/g, "<br>") ||
        "No qualifications yet.";
</script>
<?php include includeRoleFooter(__DIR__, 'hr_footer.php'); ?>