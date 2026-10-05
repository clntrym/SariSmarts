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
include includeRoleHeader(__DIR__, 'hr_header.php');

/*
| HR is centralised: one HR officer serves every branch the company runs,
| rather than belonging to one of them.
|
| This page used to read the officer's own branch out of the session and
| filter everything by it, which meant an HR account with no branch -- the
| correct state for a central role -- was turned away outright with
| "HR branch is not assigned to this account." and could not open the
| directory at all.
|
| The listing is scoped to the company now. The Branch column and the filter
| below are how an officer narrows down to one store.
*/

/*
|--------------------------------------------------------------------------
| FETCH FILTER OPTIONS
|--------------------------------------------------------------------------
*/
/* Departments */
$departments = [];
$stmt = $conn->prepare("
    SELECT DISTINCT j.department
    FROM employees e
    INNER JOIN job j ON e.job_id = j.job_id AND j.company_id = e.company_id
    WHERE e.company_id = ?
      AND e.employment_status <> 'Archived'
      AND j.department IS NOT NULL
      AND j.department <> ''
    ORDER BY j.department ASC
");

$stmt->bind_param("i", $companyId);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $departments[] = $row['department'];
}
$stmt->close();

/* Positions */
$positions = [];
$stmt = $conn->prepare("
    SELECT DISTINCT j.job_title
    FROM employees e
    INNER JOIN job j ON e.job_id = j.job_id AND j.company_id = e.company_id
    WHERE e.company_id = ?
      AND e.employment_status <> 'Archived'
      AND j.job_title IS NOT NULL
      AND j.job_title <> ''
    ORDER BY j.job_title ASC
");

$stmt->bind_param("i", $companyId);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $positions[] = $row['job_title'];
}
$stmt->close();


/* Employment Types */
$employment_types = [];
$stmt = $conn->prepare("
    SELECT DISTINCT j.employment_type
    FROM employees e
    INNER JOIN job j ON e.job_id = j.job_id AND j.company_id = e.company_id
    WHERE e.company_id = ?
      AND e.employment_status <> 'Archived'
      AND j.employment_type IS NOT NULL
      AND j.employment_type <> ''
    ORDER BY j.employment_type ASC
");

$stmt->bind_param("i", $companyId);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $employment_types[] = $row['employment_type'];
}
$stmt->close();

/* Employee Statuses */
$statuses = [];
$stmt = $conn->prepare("
    SELECT DISTINCT employment_status
    FROM employees
    WHERE company_id = ?
      AND employment_status <> 'Archived'
      AND employment_status IS NOT NULL
      AND employment_status <> ''
    ORDER BY employment_status ASC
");

$stmt->bind_param("i", $companyId);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $statuses[] = $row['employment_status'];
}
$stmt->close();

/*
|--------------------------------------------------------------------------
| FETCH EMPLOYEES
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        e.employee_id,
        e.employee_code,
        e.first_name,
        e.middle_name,
        e.last_name,
        e.suffix,
        e.email,
        e.phone,
        e.location,
        e.date_of_birth,
        e.gender,
        e.civil_status,
        e.emergency_contact_name,
        e.emergency_contact_number,
        e.emergency_contact_relationship,
        e.branch_id,
        e.job_id,
        e.employment_status,
        e.profile_picture,
        e.created_at,
        j.job_title,
        j.department,
        j.employment_type,
        b.branch_name
    FROM employees e
    LEFT JOIN job j
        ON e.job_id = j.job_id AND j.company_id = e.company_id
    LEFT JOIN branch b
        ON e.branch_id = b.branch_id AND b.company_id = e.company_id
    WHERE e.company_id = ?
      AND e.employment_status <> 'Archived'
    ORDER BY e.last_name ASC, e.first_name ASC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $companyId);
$stmt->execute();
$employees = $stmt->get_result();

/* Branches, for the filter that replaces the old hard-wired scoping. */
$branchOptions = [];
$stmt = $conn->prepare("
    SELECT branch_id, branch_name
    FROM branch
    WHERE company_id = ?
    ORDER BY branch_name ASC
");
$stmt->bind_param("i", $companyId);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $branchOptions[] = $row;
}
$stmt->close();

?>

<style>
    .employee-page {
        color: #00224c;
    }

    .directory-header {
        margin-bottom: 18px;
    }

    .directory-header h2 {
        font-weight: 700;
        color: #00224c;
        margin-bottom: 4px;
    }

    .directory-header p {
        color: #718096;
        margin: 0;
    }

    .filter-card,
    .employee-card {
        border: 1px solid #dfe5ec;
        border-radius: 15px;
        background: #fff;
        box-shadow: 0 3px 12px rgba(0, 34, 76, 0.06);
    }

    .filter-title {
        padding: 17px 20px;
        border-bottom: 1px solid #e5e9ef;
        font-weight: 600;
        color: #00224c;
    }

    .filter-body {
        padding: 18px;
    }

    .filter-label {
        font-size: 13px;
        font-weight: 500;
        color: #52627a;
        margin-bottom: 7px;
    }

    .form-select,
    .form-control {
        border: 1px solid #dce3eb;
        border-radius: 12px;
        min-height: 43px;
        color: #00224c;
        background-color: #f8fafc;
    }

    .form-select:focus,
    .form-control:focus {
        border-color: #00224c;
        box-shadow: 0 0 0 0.15rem rgba(0, 34, 76, 0.08);
    }


    .employee-card-header {
        padding: 15px 20px;
        border-bottom: 1px solid #e5e9ef;
    }

    .search-box {
        max-width: 360px;
    }

    .search-box .form-control {
        padding-left: 42px;
    }

    .search-icon {
        position: absolute;
        left: 15px;
        top: 50%;
        transform: translateY(-50%);
        color: #718096;
        z-index: 2;
    }

    /* ==========================================================
   EMPLOYEE TABLE (plain Bootstrap table — no fixed widths)
   ========================================================== */

    #employeeTable thead th {
        font-size: 12px;
        letter-spacing: .03em;
        white-space: nowrap;
    }

    #employeeTable tbody td {
        font-size: 13px;
        color: #00224c;
        vertical-align: middle;
    }

    .employee-name {
        font-weight: 600;
        color: #00224c;
        white-space: nowrap;
    }

    .employee-email {
        font-size: 12px;
        color: #718096;
    }

    .avatar {
        width: 42px;
        height: 42px;
        min-width: 42px;
        border-radius: 50%;
        background: #00224c;
        color: white;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 13px;
        font-weight: 700;
        flex-shrink: 0;
    }

    .employment-badge {
        display: inline-block;
        padding: 5px 11px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 600;
        background: #e8f8ed;
        color: #138a42;
        border: 1px solid #b9e8c8;
        white-space: nowrap;
    }

    .status-badge {
        display: inline-block;
        padding: 5px 11px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 600;
        background: #eef2f7;
        color: #00224c;
        border: 1px solid #cbd5e1;
        white-space: nowrap;
    }

    .empty-state {
        padding: 60px 20px;
        text-align: center;
        color: #718096;
    }

    .empty-state i {
        font-size: 42px;
        margin-bottom: 12px;
        color: #cbd5e1;
    }
</style>


<div class="container-fluid py-3 employee-page">
    <div class="directory-header">
        <h2>Employee Directory</h2>
        <p>
            Master source of employee data — owned by Human Resources,
            consumed by Attendance, Payroll, Finance, Admin and the Employee Portal.
        </p>
    </div>
    <div class="filter-card mb-4">
        <div class="filter-title">
            Advanced Filters
        </div>
        <div class="filter-body">
            <div class="row g-3">
                <!-- BRANCH -->
                <div class="col-lg-3 col-md-6">
                    <label class="filter-label">
                        Branch
                    </label>
                    <select id="branchFilter" class="form-select">
                        <option value="">
                            All branches
                        </option>
                        <?php foreach ($branchOptions as $branchOption): ?>
                            <option value="<?= htmlspecialchars($branchOption['branch_name']) ?>">
                                <?= htmlspecialchars($branchOption['branch_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- DEPARTMENT -->
                <div class="col-lg-3 col-md-6">
                    <label class="filter-label">
                        Department
                    </label>
                    <select id="departmentFilter" class="form-select">
                        <option value="">
                            All departments
                        </option>
                        <?php foreach ($departments as $department): ?>
                            <option value="<?= htmlspecialchars($department) ?>">
                                <?= htmlspecialchars($department) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-lg-3 col-md-6">
                    <label class="filter-label">
                        Position
                    </label>
                    <select id="positionFilter" class="form-select">
                        <option value="">
                            All positions
                        </option>
                        <?php foreach ($positions as $position): ?>
                            <option value="<?= htmlspecialchars($position) ?>">
                                <?= htmlspecialchars($position) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-lg-3 col-md-6">
                    <label class="filter-label">
                        Employment Type
                    </label>
                    <select id="employmentFilter" class="form-select">
                        <option value="">
                            All types
                        </option>
                        <?php foreach ($employment_types as $type): ?>
                            <option value="<?= htmlspecialchars($type) ?>">
                                <?= htmlspecialchars($type) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-lg-3 col-md-6">
                    <label class="filter-label">
                        Status
                    </label>
                    <select id="statusFilter" class="form-select">
                        <option value="">
                            All statuses
                        </option>
                        <?php foreach ($statuses as $status): ?>
                            <option value="<?= htmlspecialchars($status) ?>">
                                <?= htmlspecialchars($status) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
    </div>
    <div class="employee-card">
        <div class="employee-card-header">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            </div>
        </div>

        <div class="table-responsive">
            <table id="employeeTable" class="table table-hover align-middle mb-0" style="width:100%">
                <thead class="table-light">
                    <tr>
                        <th class="text-uppercase text-muted fw-semibold ps-4">
                            Employee
                        </th>

                        <th class="text-uppercase text-muted fw-semibold">
                            Department
                        </th>

                        <th class="text-uppercase text-muted fw-semibold">
                            Branch
                        </th>

                        <th class="text-uppercase text-muted fw-semibold">
                            Position
                        </th>

                        <th class="text-uppercase text-muted fw-semibold">
                            Employment
                        </th>

                        <th class="text-uppercase text-muted fw-semibold">
                            Start Date
                        </th>

                        <th class="text-uppercase text-muted fw-semibold">
                            Status
                        </th>

                        <th class="text-uppercase text-muted fw-semibold text-end pe-4">
                            Actions
                        </th>
                    </tr>
                </thead>
                <tbody id="employeeTableBody">
                    <?php if ($employees->num_rows > 0): ?>
                        <?php while ($employee = $employees->fetch_assoc()): ?>
                            <?php
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

                            $searchText = strtolower(
                                $fullName . ' ' .
                                ($employee['employee_code'] ?? '') . ' ' .
                                ($employee['email'] ?? '') . ' ' .
                                ($employee['department'] ?? '') . ' ' .
                                ($employee['job_title'] ?? '') . ' ' .
                                ($employee['employment_type'] ?? '') . ' ' .
                                ($employee['employment_status'] ?? '')
                            );
                            ?>
                            <tr class="employee-row" data-search="<?= htmlspecialchars($searchText, ENT_QUOTES) ?>"
                                data-department="<?= htmlspecialchars($employee['department'] ?? '') ?>"
                                data-position="<?= htmlspecialchars($employee['job_title'] ?? '') ?>"
                                data-employment="<?= htmlspecialchars($employee['employment_type'] ?? '') ?>"
                                data-status="<?= htmlspecialchars($employee['employment_status'] ?? '') ?>"
                                data-branch="<?= htmlspecialchars($employee['branch_name'] ?? '') ?>"
                                data-date="<?= htmlspecialchars($startDate) ?>">
                                <td class="ps-4">
                                    <div class="d-flex align-items-center gap-3">
                                        <div class="avatar">
                                            <?= htmlspecialchars($initials) ?>
                                        </div>
                                        <div style="min-width:0;">
                                            <div class="employee-name">
                                                <?= htmlspecialchars($fullName) ?>
                                            </div>
                                            <div class="employee-email text-truncate" style="max-width:260px;">
                                                <?= htmlspecialchars($employee['employee_code'] ?? '') ?>
                                                <?php if (!empty($employee['email'])): ?>
                                                    <span class="text-muted mx-1">·</span>
                                                    <?= htmlspecialchars($employee['email']) ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td class="text-muted">
                                    <?= htmlspecialchars($employee['department'] ?? '-') ?>
                                </td>
                                <td class="text-muted">
                                    <?= htmlspecialchars($employee['branch_name'] ?? '-') ?>
                                </td>
                                <td class="text-muted">
                                    <?= htmlspecialchars($employee['job_title'] ?? '-') ?>
                                </td>
                                <td>
                                    <span class="employment-badge">
                                        <?= htmlspecialchars($employee['employment_type'] ?? '-') ?>
                                    </span>
                                </td>
                                <td class="text-muted text-nowrap">
                                    <?= htmlspecialchars($startDate) ?>
                                </td>
                                <td>
                                    <span class="status-badge">
                                        <?= htmlspecialchars($employee['employment_status'] ?? '-') ?>
                                    </span>
                                </td>
                                <td class="text-end pe-4">
                                    <div class="d-flex justify-content-end gap-2">

                                        <!-- VIEW BUTTON -->
                                        <a href="employee_view.php?id=<?= (int) $employee['employee_id'] ?>"
                                            class="btn btn-sm text-white" style="background:#00224c;" title="View Employee">

                                            <i class="bi bi-eye me-1"></i>View

                                        </a>

                                        <!-- ARCHIVE BUTTON -->
                                        <button type="button" class="btn btn-sm btn-outline-danger archiveEmployee"
                                            data-id="<?= (int) $employee['employee_id'] ?>" title="Archive Employee">

                                            <i class="bi bi-archive me-1"></i>Archive

                                        </button>

                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8">
                                <div class="empty-state">
                                    <i class="bi bi-people"></i>
                                    <h5>
                                        No employees found
                                    </h5>
                                    <p class="mb-0">
                                        There are currently no active employees in your branch.
                                    </p>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>


    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function () {

    var branchFilter = document.getElementById("branchFilter");
    var departmentFilter = document.getElementById("departmentFilter");
    var positionFilter = document.getElementById("positionFilter");
    var employmentFilter = document.getElementById("employmentFilter");
    var statusFilter = document.getElementById("statusFilter");

    DataTable.ext.search.push(function (settings, data, dataIndex) {
        if (settings.nTable.id !== "employeeTable") return true;

        var row = settings.aoData[dataIndex].nTr;
        if (!row) return true;

        var branch = branchFilter.value.toLowerCase().trim();
        var dept = departmentFilter.value.toLowerCase().trim();
        var pos = positionFilter.value.toLowerCase().trim();
        var emp = employmentFilter.value.toLowerCase().trim();
        var stat = statusFilter.value.toLowerCase().trim();

        if (branch && (row.dataset.branch || "").toLowerCase() !== branch) return false;
        if (dept && (row.dataset.department || "").toLowerCase() !== dept) return false;
        if (pos && (row.dataset.position || "").toLowerCase() !== pos) return false;
        if (emp && (row.dataset.employment || "").toLowerCase() !== emp) return false;
        if (stat && (row.dataset.status || "").toLowerCase() !== stat) return false;

        return true;
    });

    var empDT = new DataTable("#employeeTable", {
        pageLength: 10,
        lengthChange: false,
        ordering: true,
        order: [],
        columnDefs: [{ orderable: false, targets: 7 }],
        language: {
            search: "",
            searchPlaceholder: "Search employee, department, position...",
            info: "Showing _START_ to _END_ of _TOTAL_",
            infoEmpty: "No records",
            zeroRecords: "No matching employees",
            emptyTable: "No employees found",
            paginate: { previous: "Previous", next: "Next" }
        }
    });

    branchFilter.addEventListener("change", function () { empDT.draw(); });
    departmentFilter.addEventListener("change", function () { empDT.draw(); });
    positionFilter.addEventListener("change", function () { empDT.draw(); });
    employmentFilter.addEventListener("change", function () { empDT.draw(); });
    statusFilter.addEventListener("change", function () { empDT.draw(); });

    document.getElementById("employeeTableBody").addEventListener("click", function (event) {
        var button = event.target.closest(".archiveEmployee");
        if (!button) return;

        var employeeId = button.dataset.id;

        Swal.fire({
            title: "Archive Employee?",
            text: "This employee will be removed from the active employee directory.",
            icon: "warning",
            showCancelButton: true,
            confirmButtonColor: "#dc3545",
            cancelButtonColor: "#6c757d",
            confirmButtonText: "Yes, Archive",
            cancelButtonText: "Cancel",
            reverseButtons: true
        }).then(function (result) {
            if (!result.isConfirmed) return;

            fetch("archive_employee.php", {
                method: "POST",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                body: "action=archive&employee_id=" + encodeURIComponent(employeeId)
            })
            .then(function (response) {
                if (!response.ok) throw new Error("Server returned an error.");
                return response.json();
            })
            .then(function (data) {
                if (data.success) {
                    Swal.fire({
                        icon: "success",
                        title: "Archived",
                        text: data.message,
                        timer: 1500,
                        showConfirmButton: false
                    }).then(function () { location.reload(); });
                } else {
                    Swal.fire({
                        icon: "error",
                        title: "Unable to Archive",
                        text: data.message || "The employee could not be archived."
                    });
                }
            })
            .catch(function (error) {
                console.error("Archive error:", error);
                Swal.fire({
                    icon: "error",
                    title: "Error",
                    text: "Something went wrong while archiving the employee."
                });
            });
        });
    });

});
</script>



<?php include includeRoleFooter(__DIR__, 'hr_footer.php'); ?>