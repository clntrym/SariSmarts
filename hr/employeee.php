<?php
require_once('../init.php');
requireRole(['hr']);

$companyId = requireCompany();


// =====================================
// LOAD OFFICIAL EMPLOYEE DETAILS (AJAX)
// =====================================
if (isset($_POST['loadOfficialEmployee'])) {

    $employee_id = (int) $_POST['employee_id'];

    $query = mysqli_query($conn, "
        SELECT
            e.*,

            emp.employee_number,
            emp.employment_type,
            emp.employment_status,
            emp.hire_date,
            emp.basic_salary,

            j.job_title,

            b.branch_name

        FROM employees e

        LEFT JOIN employment emp
            ON e.employee_id = emp.employee_id

        LEFT JOIN job j
            ON e.job_id = j.job_id

        LEFT JOIN branch b
            ON e.branch_id = b.branch_id

        WHERE e.employee_id = '$employee_id' AND e.company_id = " . (int) $companyId . "

        LIMIT 1
    ");

    $employee = mysqli_fetch_assoc($query);

    echo json_encode($employee);

    exit;
}

// =============================
// LOAD EMPLOYEE DOCUMENTS (AJAX)
// =============================
if (isset($_POST['loadDocuments'])) {

    $employee_id = (int) $_POST['employee_id'];

    $sql = mysqli_query($conn, "
        SELECT *
        FROM employee_documents
        WHERE employee_id='$employee_id' AND company_id=" . (int) $companyId . "
        ORDER BY document_id ASC
    ");

    $uploaded = 0;
    $total = mysqli_num_rows($sql);

    ob_start();

    while ($doc = mysqli_fetch_assoc($sql)) {

        if (!empty($doc['file_path'])) {
            $uploaded++;
        }
        ?>

        <div class="card mb-3 shadow-sm border-0">

            <div class="card-body d-flex justify-content-between align-items-center">

                <div>

                    <h6 class="mb-1">
                        <?= htmlspecialchars($doc['document_name']) ?>
                    </h6>

                    <?php if (!empty($doc['file_path'])) { ?>

                        <span class="badge bg-success">
                            Uploaded
                        </span>

                    <?php } else { ?>

                        <span class="badge bg-warning text-dark">
                            Pending
                        </span>

                    <?php } ?>

                </div>

                <div>

                    <?php if (!empty($doc['file_path'])) { ?>

                        <a href="../<?= $doc['file_path'] ?>" target="_blank" class="btn btn-outline-primary btn-sm">

                            Preview

                        </a>

                    <?php } ?>

                    <form class="uploadForm d-inline" enctype="multipart/form-data">

                        <input type="hidden" name="uploadDocument" value="1">

                        <input type="hidden" name="document_id" value="<?= $doc['document_id'] ?>">

                        <input type="file" name="document_file" class="fileInput d-none" accept=".pdf,.jpg,.jpeg,.png">

                        <button type="button" class="btn btn-primary btn-sm uploadBtn">
                            Upload
                        </button>

                    </form>

                </div>

            </div>

        </div>

        <?php

    }

    echo json_encode([
        "html" => ob_get_clean(),
        "uploaded" => $uploaded,
        "total" => $total
    ]);

    exit;
}

// =======================================
// LOAD EMPLOYMENT CONTRACT
// =======================================

if (isset($_POST['loadContract'])) {

    $employee_id = (int) $_POST['employee_id'];

    $contract = mysqli_query($conn, "
        SELECT *
        FROM employee_contracts
        WHERE employee_id='$employee_id' AND company_id=" . (int) $companyId . "
        LIMIT 1
    ");

    $row = mysqli_fetch_assoc($contract);

    if (!$row) {

        echo "
        <div class='alert alert-warning'>
            No contract found.
        </div>
        ";

        exit;
    }

    ?>

    <table class="table">

        <tr>

            <th width="180">
                Contract No.
            </th>

            <td>
                <?= htmlspecialchars($row['contract_number']) ?>
            </td>

        </tr>

        <tr>

            <th>
                Status
            </th>

            <td>

                <span class="badge bg-warning">

                    <?= htmlspecialchars($row['status']) ?>

                </span>

            </td>

        </tr>

    </table>

    <?php

    if (!empty($row['company_contract'])) {

        ?>

        <a href="../<?= htmlspecialchars($row['company_contract']) ?>" target="_blank" class="btn btn-success">

            View Contract

        </a>

        <?php

    } else {

        ?>

        <form id="contractUploadForm" enctype="multipart/form-data">

            <input type="hidden" name="uploadContract" value="1">

            <input type="hidden" name="employee_id" value="<?= $employee_id ?>">

            <input type="file" name="contract_file" class="form-control mb-2" accept=".pdf">

            <button class="btn btn-primary">

                Upload Contract

            </button>

        </form>

        <?php

    }

    exit;
}

// =============================
// UPLOAD DOCUMENT
// =============================
if (isset($_POST['uploadDocument'])) {

    $document_id = (int) $_POST['document_id'];

    if (isset($_FILES['document_file']) && $_FILES['document_file']['error'] == 0) {

        $ext = strtolower(pathinfo($_FILES['document_file']['name'], PATHINFO_EXTENSION));

        $filename = "DOC_" . time() . "_" . rand(1000, 9999) . "." . $ext;

        $folder = "../uploads/employee_documents/";

        if (!is_dir($folder)) {
            mkdir($folder, 0777, true);
        }

        $path = $folder . $filename;

        if (move_uploaded_file($_FILES['document_file']['tmp_name'], $path)) {

            $dbPath = "uploads/employee_documents/" . $filename;

            mysqli_query($conn, "
                UPDATE employee_documents
                SET
                    file_path='$dbPath',
                    uploaded_at=NOW()
                WHERE document_id='$document_id'
            ");

            echo json_encode([
                "success" => true
            ]);

        } else {

            echo json_encode([
                "success" => false,
                "message" => "Upload failed."
            ]);

        }

    } else {

        echo json_encode([
            "success" => false,
            "message" => "No file selected."
        ]);

    }

    exit;
}

if (isset($_POST['approveRequirements'])) {

    $employee_id = (int) $_POST['employee_id'];

    mysqli_begin_transaction($conn);

    try {

        // Count requirements
        $check = mysqli_query($conn, "
            SELECT
                COUNT(*) total,
                SUM(
                    CASE
                        WHEN file_path IS NOT NULL
                        AND file_path <> ''
                        THEN 1
                        ELSE 0
                    END
                ) uploaded
            FROM employee_documents
            WHERE employee_id='$employee_id' AND company_id=" . (int) $companyId . "
        ");

        $row = mysqli_fetch_assoc($check);

        if ($row['uploaded'] != $row['total']) {
            throw new Exception("Employee requirements are not yet complete.");
        }

        mysqli_query($conn, "
            UPDATE employment
            SET employment_status='Official Employee'
            WHERE employee_id='$employee_id'
        ");

        mysqli_commit($conn);

        header("Location: employee.php?success=official");
        exit;

    } catch (Exception $e) {

        mysqli_rollback($conn);

        header("Location: employee.php?error=" . urlencode($e->getMessage()));
        exit;

    }

}

// =====================================
// UPLOAD EMPLOYMENT CONTRACT
// =====================================

if (isset($_POST['uploadContract'])) {

    $employee_id = (int) $_POST['employee_id'];

    if (
        isset($_FILES['contract_file']) &&
        $_FILES['contract_file']['error'] == 0
    ) {

        $folder = "../uploads/contracts/";

        if (!is_dir($folder)) {
            mkdir($folder, 0777, true);
        }

        $ext = strtolower(
            pathinfo(
                $_FILES['contract_file']['name'],
                PATHINFO_EXTENSION
            )
        );

        if ($ext != "pdf") {

            echo json_encode([
                "success" => false,
                "message" => "Only PDF files are allowed."
            ]);

            exit;
        }

        $filename = "CONTRACT_" .
            $employee_id .
            "_" .
            time() .
            ".pdf";

        $path = $folder . $filename;

        if (
            move_uploaded_file(
                $_FILES['contract_file']['tmp_name'],
                $path
            )
        ) {

            $dbPath = "uploads/contracts/" . $filename;

            $getEmployee = mysqli_query($conn, "
                SELECT
                    TRIM(CONCAT(first_name, ' ', COALESCE(middle_name, ''), ' ', last_name)) AS full_name,
                    email
                FROM employees
                WHERE employee_id='$employee_id' AND company_id=" . (int) $companyId . "
                LIMIT 1
                ");

            $employee = mysqli_fetch_assoc($getEmployee);

            /*
            | The lookup above is scoped to the company, but nothing used to
            | check that it found anything. An employee_id belonging to
            | another company returned no row, and the code carried on to the
            | UPDATE regardless -- which was not scoped either, so it
            | overwrote that other company's contract and emailed it to
            | whoever this form named.
            |
            | employee_id is cast to int, so this was never an injection;
            | it was a cross-tenant write reachable by anybody who could post
            | this form with somebody else's id.
            */
            if (!$employee) {

                echo json_encode([
                    "success" => false,
                    "message" => "That employee could not be found."
                ]);

                exit;
            }

            $employeeName = $employee['full_name'];
            $employeeEmail = $employee['email'];

            /* Scoped, and prepared rather than interpolated. */
            $contractStmt = $conn->prepare("
                UPDATE employee_contracts
                SET company_contract = ?,
                    status = 'Sent',
                    uploaded_by = ?,
                    uploaded_at = NOW()
                WHERE employee_id = ? AND company_id = ?
            ");

            $uploadedBy = (int) ($_SESSION['user_id'] ?? 0);

            $contractStmt->bind_param("siii", $dbPath, $uploadedBy, $employee_id, $companyId);
            $contractStmt->execute();
            $contractStmt->close();

            require_once("../accounts/send_contract.php");

            $mailSent = sendContract(
                $employeeEmail,
                $employeeName,
                "../" . $dbPath
            );

            if (!$mailSent) {

                echo json_encode([
                    "success" => false,
                    "message" => "Contract uploaded but email failed."
                ]);

                exit;
            }

            if ($mailSent) {

                echo json_encode([
                    "success" => true
                ]);
            }

        } else {

            echo json_encode([
                "success" => false,
                "message" => "Contract uploaded, but email could not be sent."
            ]);

        }

    } else {

        echo json_encode([
            "success" => false,
            "message" => "Please select a PDF."
        ]);

    }

    exit;
}

include('hr_header.php');

$sql = mysqli_query($conn, "
SELECT
    e.employee_id,
    TRIM(CONCAT(e.first_name, ' ', COALESCE(e.middle_name, ''), ' ', e.last_name)) AS full_name,
    emp.hire_date,
    emp.employment_status,

    j.job_title,
    b.branch_id,
    b.branch_name,

    hr.status AS hr_status,
    hr.recommended_at,

    COUNT(ed.document_id) AS total_documents,

    SUM(
        CASE
            WHEN ed.file_path IS NOT NULL
            AND ed.file_path <> ''
            THEN 1
            ELSE 0
        END
    ) AS uploaded_documents

FROM employment emp

INNER JOIN employees e
ON emp.employee_id = e.employee_id AND e.company_id = emp.company_id

LEFT JOIN job j
ON e.job_id = j.job_id AND j.company_id = e.company_id

LEFT JOIN branch b
ON e.branch_id = b.branch_id AND b.company_id = e.company_id

LEFT JOIN hiring_recommendations hr
ON hr.application_id = e.application_id AND hr.company_id = e.company_id

LEFT JOIN employee_documents ed
ON ed.employee_id = e.employee_id AND ed.company_id = e.company_id

WHERE emp.employment_status='Pre-Employee' AND emp.company_id=" . (int) $companyId . "

GROUP BY
    e.employee_id,
    e.first_name,
    e.middle_name,
    e.last_name,
    emp.hire_date,
    emp.employment_status,
    j.job_title,
    b.branch_id,
    b.branch_name,
    hr.status,
    hr.recommended_at

ORDER BY emp.created_at DESC
");


?>


<?php if (isset($_GET['success']) && $_GET['success'] == "official") { ?>

    <script>

        document.addEventListener("DOMContentLoaded", function () {

            Swal.fire({
                icon: 'success',
                title: 'Employee Activated',
                text: 'The employee is now an Official Employee.'
            });

        });

    </script>

<?php } ?>
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

    .dataTables_wrapper .dataTables_filter {
        float: none;
        text-align: left;
        padding: 18px 18px 12px;
    }

    .dataTables_wrapper .dataTables_filter label {
        width: 100%;
        font-size: 0;
    }

    .dataTables_wrapper .dataTables_filter input {
        margin-left: 0 !important;
        width: 430px;
        max-width: 100%;
        height: 43px;
        border: 1px solid #d9e1e8;
        border-radius: 22px;
        padding: 0 18px;
        font-size: 14px;
        outline: none;
    }

    .dataTables_wrapper .dataTables_filter input:focus {
        border-color: #00224c;
        box-shadow: 0 0 0 3px rgba(0, 34, 76, .08);
    }

    .dataTables_wrapper .dt-layout-row:last-child {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 14px 18px;
    }
</style>
<div class="container-fluid py-1">

    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-2">
        <div>
            <h2 class="fw-bold mb-1" style="color: #00224c;">Employee Management</h2>
            <p class="text-muted mb-0">
                Manage pre-employees and official employees.
            </p>
        </div>

    </div>
    <!-- Tabs -->
    <div class="employee-tabs">
        <ul class="nav nav-pills mb-4" id="employeeTabs">

            <li class="nav-item me-2">
                <button class="nav-link active rounded-pill" data-bs-toggle="tab" data-bs-target="#pre-employee">
                    Pre-Employee
                </button>
            </li>

            <li class="nav-item me-2">
                <button class="nav-link rounded-pill" data-bs-toggle="tab" data-bs-target="#official_employee">
                    Official Employee
                </button>
            </li>
        </ul>
    </div>


    <!-- Search -->
    <div class="row mb-4">

        <div class="col-md-3">
            <select id="branchFilter" class="form-select shadow-sm"
                style="border:2px solid #00224c;border-radius:10px;height:45px;color:#00224c;font-weight:600;">

                <option value="">All Branches</option>

                <?php
                $branches = mysqli_query($conn, "SELECT branch_id, branch_name FROM branch WHERE company_id=" . (int) $companyId . " ORDER BY branch_name");

                while ($b = mysqli_fetch_assoc($branches)) {
                    ?>

                    <option value="<?= $b['branch_id'] ?>">
                        <?= htmlspecialchars($b['branch_name']) ?>
                    </option>

                <?php } ?>

            </select>
        </div>

    </div>
    <div class="tab-content">
        <div class="tab-pane fade show active" id="pre-employee">
            <div class="card shadow-sm border-0">
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle employeeTable" id="preEmployeeTable" style="width:100%">
                            <thead class="table-light">
                                <tr>
                                    <th>Employee</th>
                                    <th>Position</th>
                                    <th>Branch</th>
                                    <th>Date Approved</th>
                                    <th>Requirement</th>
                                    <th>HR review</th>
                                    <th>Status</th>
                                    <th width="180">Action</th>
                                </tr>
                            </thead>
                            <tbody>

                                <?php while ($row = mysqli_fetch_assoc($sql)) { ?>
                                    <tr data-branch="<?= $row['branch_id'] ?>">
                                        <td>
                                            <?= htmlspecialchars($row['full_name']) ?>
                                        </td>
                                        <td>
                                            <?= htmlspecialchars($row['job_title']) ?>
                                        </td>

                                        <td>
                                            <?= htmlspecialchars($row['branch_name']) ?>
                                        </td>
                                        <td>
                                            <?= date("M d, Y", strtotime($row['recommended_at'])) ?>
                                        </td>
                                        <?php
                                        $total = (int) $row['total_documents'];
                                        $uploaded = (int) $row['uploaded_documents'];
                                        ?>

                                        <td>

                                            <span class="badge bg-info">

                                                <?= $uploaded ?> / <?= $total ?>

                                            </span>

                                        </td>
                                        <?php
                                        $percent = 0;
                                        if ($total > 0) {
                                            $percent = round(($uploaded / $total) * 100);
                                        }
                                        ?>

                                        <td>

                                            <div class="progress" style="height:22px;">

                                                <div class="progress-bar bg-success" style="width:<?= $percent ?>%;">

                                                    <?= $percent ?>%

                                                </div>

                                            </div>

                                        </td>

                                        <td>
                                            <?php

                                            if ($row['hr_status'] == "Approved") {
                                                echo '<span class="badge bg-success">Approved</span>';
                                            } else {
                                                echo '<span class="badge bg-warning text-dark">Pending</span>';
                                            }

                                            ?>

                                        </td>
                                        <td>
                                            <button class="btn btn-primary btn-sm viewEmployee"
                                                data-id="<?= $row['employee_id'] ?>"
                                                data-name="<?= htmlspecialchars($row['full_name']) ?>"
                                                data-position="<?= htmlspecialchars($row['job_title']) ?>"
                                                data-branch="<?= htmlspecialchars($row['branch_name']) ?>"
                                                data-date="<?= date('M d, Y', strtotime($row['recommended_at'])) ?>">

                                                View

                                            </button>
                                            <?php if ($uploaded == $total && $total > 0) { ?>

                                                <form method="POST" class="d-inline">

                                                    <input type="hidden" name="approveRequirements" value="1">
                                                    <input type="hidden" name="employee_id" value="<?= $row['employee_id'] ?>">

                                                    <button class="btn btn-success btn-sm">
                                                        Approve Requirements
                                                    </button>

                                                </form>

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

        <!-- Regularization -->
        <div class="tab-pane fade" id="official_employee">
            <div class="card shadow-sm border-0">
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle employeeTable" id="officialEmployeeTable" style="width:100%">
                            <thead class="table-light">
                                <tr>
                                    <th>Employee</th>
                                    <th>Position</th>
                                    <th>Branch</th>
                                    <th>Type</th>
                                    <th>Status</th>
                                    <th>Date Hired</th>
                                    <th width="180">Action</th>
                                </tr>
                            </thead>
                            <tbody>

                                <?php

                                $sql = mysqli_query($conn, "
                                SELECT

                                e.employee_id,
                                TRIM(CONCAT(e.first_name, ' ', COALESCE(e.middle_name, ''), ' ', e.last_name)) AS full_name,

                                emp.employment_type,
                                emp.employment_status,
                                emp.hire_date,

                                j.job_title,

                                b.branch_id,
                                b.branch_name

                                FROM employment emp

                                INNER JOIN employees e
                                ON emp.employee_id=e.employee_id AND e.company_id=emp.company_id

                                LEFT JOIN job j
                                ON e.job_id=j.job_id AND j.company_id=e.company_id

                                LEFT JOIN branch b
                                ON e.branch_id=b.branch_id AND b.company_id=e.company_id

                                WHERE emp.employment_status='Official Employee'
                                  AND emp.company_id=" . (int) $companyId . "

                                ORDER BY emp.created_at DESC
                                ");

                                while ($row = mysqli_fetch_assoc($sql)) {

                                    ?>

                                    <tr data-branch="<?= $row['branch_id'] ?>">

                                        <td>
                                            <?= htmlspecialchars($row['full_name']) ?>
                                        </td>

                                        <td>
                                            <?= htmlspecialchars($row['job_title']) ?>
                                        </td>

                                        <td>
                                            <?= htmlspecialchars($row['branch_name']) ?>
                                        </td>

                                        <td>
                                            <?= htmlspecialchars($row['employment_type']) ?>
                                        </td>

                                        <td>

                                            <span class="badge bg-success">
                                                <?= htmlspecialchars($row['employment_status']) ?>
                                            </span>

                                        </td>

                                        <td>

                                            <?= date("M d, Y", strtotime($row['hire_date'])) ?>

                                        </td>

                                        <td>
                                            <button class="btn btn-primary btn-sm officialViewBtn"
                                                data-id="<?= $row['employee_id'] ?>"
                                                data-name="<?= htmlspecialchars($row['full_name']) ?>"
                                                data-position="<?= htmlspecialchars($row['job_title']) ?>"
                                                data-branch="<?= htmlspecialchars($row['branch_name']) ?>"
                                                data-date="<?= date("M d, Y", strtotime($row['hire_date'])) ?>">
                                                View
                                            </button>
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

<div class="modal fade" id="employeeModal" tabindex="-1">

    <div class="modal-dialog modal-xl modal-dialog-centered">

        <div class="modal-content rounded-4">

            <div class="modal-header text-white" style="background-color: #00224c;">

                <div>

                    <h4 class="fw-bold mb-1">

                        Employee Profile

                    </h4>

                    <small>

                        Review submitted requirements

                    </small>

                </div>

                <button class="btn-close btn-close-white" data-bs-dismiss="modal">
                </button>

            </div>

            <div class="modal-body">

                <div class="row">

                    <div class="col-md-2 text-center">

                        <img src="../assets/images/default-profile.png" class="rounded-circle border" width="120">

                    </div>

                    <div class="col-md-6">

                        <h3 id="empName"></h3>

                        <p class="text-muted">

                            <span id="empPosition"></span>

                            •

                            <span id="empBranch"></span>

                        </p>

                        <small>

                            Date Approved :

                            <span id="empDate"></span>

                        </small>

                    </div>

                    <div class="col-md-4">

                        <div class="card shadow-sm">

                            <div class="card-body">

                                <h6>

                                    Document Progress

                                </h6>

                                <div class="progress">

                                    <div id="progressBar" class="progress-bar bg-success">

                                    </div>

                                </div>

                                <div class="mt-2 text-center">

                                    <strong id="progressText"></strong>

                                </div>

                            </div>

                        </div>

                    </div>

                </div>

                <hr>

                <div id="documentList"></div>
                <input type="hidden" id="uploadEmployeeID">

                <form id="uploadForm" enctype="multipart/form-data" style="display:none;">

                    <input type="hidden" name="uploadDocument" value="1">

                    <input type="hidden" name="document_id" id="uploadDocumentID">

                    <input type="file" id="documentFile" name="document_file" accept=".pdf,.jpg,.jpeg,.png">

                </form>
                <hr>

                <h5 class="fw-bold mb-3">
                    Employment Contract
                </h5>

                <div class="card border-0 shadow-sm">

                    <div class="card-body">

                        <div id="contractSection">

                            Loading contract...

                        </div>

                    </div>

                </div>

            </div>

        </div>

    </div>

</div>

<script>

    document.querySelectorAll(".viewEmployee").forEach(btn => {

        btn.onclick = function () {

            let employeeID = this.dataset.id;

            document.getElementById("uploadEmployeeID").value = employeeID;

            document.getElementById("empName").innerHTML = this.dataset.name;

            document.getElementById("empPosition").innerHTML = this.dataset.position;

            document.getElementById("empBranch").innerHTML = this.dataset.branch;

            document.getElementById("empDate").innerHTML = this.dataset.date;

            loadEmployeeDocuments(employeeID);

            new bootstrap.Modal(
                document.getElementById("employeeModal")
            ).show();

        };

    });

    document.querySelectorAll(".officialViewBtn").forEach(btn => {

        btn.onclick = function () {

            let employeeID = this.dataset.id;

            document.getElementById("uploadEmployeeID").value = employeeID;

            document.getElementById("empName").innerHTML = this.dataset.name;

            document.getElementById("empPosition").innerHTML = this.dataset.position;

            document.getElementById("empBranch").innerHTML = this.dataset.branch;

            document.getElementById("empDate").innerHTML = this.dataset.date;

            loadEmployeeDocuments(employeeID);

            new bootstrap.Modal(
                document.getElementById("employeeModal")
            ).show();

        };

    });

    function loadEmployeeDocuments(employeeID) {

        fetch("employee.php", {
            method: "POST",
            headers: {
                "Content-Type": "application/x-www-form-urlencoded"
            },
            body: "loadDocuments=1&employee_id=" + employeeID
        })

            .then(res => res.json())

            .then(data => {

                document.getElementById("documentList").innerHTML = data.html;

                let percent = 0;

                if (data.total > 0) {
                    percent = Math.round((data.uploaded / data.total) * 100);
                }

                document.getElementById("progressBar").style.width = percent + "%";
                document.getElementById("progressBar").innerHTML = percent + "%";

                document.getElementById("progressText").innerHTML =
                    data.uploaded + " / " + data.total + " Documents Uploaded";


                attachUploadButtons();


                fetch("employee.php", {

                    method: "POST",

                    headers: {
                        "Content-Type": "application/x-www-form-urlencoded"
                    },

                    body: "loadContract=1&employee_id=" + employeeID

                })

                    .then(r => r.text())

                    .then(html => {

                        document.getElementById("contractSection").innerHTML = html;

                        attachContractUpload();

                    });

            });

    }

    function attachUploadButtons() {

        document.querySelectorAll(".uploadBtn").forEach(btn => {

            btn.onclick = function () {

                const form = this.closest(".uploadForm");

                const input = form.querySelector(".fileInput");

                input.click();

                input.onchange = function () {

                    if (!this.files.length) return;

                    let fd = new FormData(form);

                    fetch("employee.php", {

                        method: "POST",

                        body: fd

                    })

                        .then(r => r.json())

                        .then(res => {

                            if (res.success) {

                                Swal.fire({

                                    icon: "success",

                                    title: "Document Updated"

                                });

                                loadEmployeeDocuments(
                                    document.getElementById("uploadEmployeeID").value
                                );

                            } else {

                                Swal.fire({
                                    icon: "error",
                                    text: res.message
                                });

                            }

                        });

                };

            };

        });

    }

    function attachContractUpload() {

        const form = document.getElementById("contractUploadForm");

        if (!form) return;

        form.addEventListener("submit", function (e) {

            e.preventDefault();

            let fd = new FormData(this);

            fetch("employee.php", {

                method: "POST",

                body: fd

            })

                .then(r => r.json())

                .then(res => {

                    if (res.success) {

                        Swal.fire({

                            icon: "success",

                            title: "Contract Uploaded"

                        });

                        loadEmployeeDocuments(
                            document.getElementById("uploadEmployeeID").value
                        );

                    } else {

                        Swal.fire({

                            icon: "error",

                            text: res.message

                        });

                    }

                });

        });

    }

    DataTable.ext.search.push(function(settings, data, dataIndex) {
        if (settings.nTable.id !== "preEmployeeTable" && settings.nTable.id !== "officialEmployeeTable") return true;
        var row = settings.aoData[dataIndex].nTr;
        var branch = document.getElementById("branchFilter").value;
        if (branch === "") return true;
        return row.getAttribute("data-branch") === branch;
    });

    var preDT = new DataTable("#preEmployeeTable", {
        pageLength: 10,
        lengthChange: false,
        ordering: true,
        order: [],
        columnDefs: [{ orderable: false, targets: -1 }],
        language: {
            search: "",
            searchPlaceholder: "Search employee...",
            info: "Showing _START_ to _END_ of _TOTAL_",
            paginate: { previous: "Previous", next: "Next" }
        }
    });

    var offDT;
    document.querySelectorAll('[data-bs-toggle="tab"]').forEach(function(tab) {
        tab.addEventListener("shown.bs.tab", function() {
            if (!offDT && document.getElementById("officialEmployeeTable")) {
                offDT = new DataTable("#officialEmployeeTable", {
                    pageLength: 10,
                    lengthChange: false,
                    ordering: true,
                    order: [],
                    columnDefs: [{ orderable: false, targets: -1 }],
                    language: {
                        search: "",
                        searchPlaceholder: "Search employee...",
                        info: "Showing _START_ to _END_ of _TOTAL_",
                        paginate: { previous: "Previous", next: "Next" }
                    }
                });
            }
            if (offDT) offDT.columns.adjust().draw();
            preDT.columns.adjust().draw();
        });
    });

    document.getElementById("branchFilter").addEventListener("change", function() {
        preDT.draw();
        if (offDT) offDT.draw();
    });

</script>

<?php include("hr_footer.php"); ?>