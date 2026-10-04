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
|--------------------------------------------------------------------------
| RESTORE EMPLOYEE
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    /*
    |--------------------------------------------------------------------------
    | ARCHIVE EMPLOYEE
    |--------------------------------------------------------------------------
    */

    if ($_POST['action'] === 'archive') {

        $employee_id = isset($_POST['employee_id'])
            ? (int) $_POST['employee_id']
            : 0;

        header('Content-Type: application/json');

        if ($employee_id <= 0) {
            echo json_encode([
                'success' => false,
                'message' => 'Invalid employee.'
            ]);
            exit;
        }

        /*
        |--------------------------------------------------------------------------
        | CHECK EMPLOYEE
        |--------------------------------------------------------------------------
        */

        $stmt = $conn->prepare("
            SELECT employee_id, first_name, last_name, employment_status
            FROM employees
            WHERE employee_id = ? AND company_id = ?
            LIMIT 1
        ");

        $stmt->bind_param("ii", $employee_id, $companyId);
        $stmt->execute();

        $result = $stmt->get_result();

        if ($result->num_rows === 0) {

            echo json_encode([
                'success' => false,
                'message' => 'Employee not found.'
            ]);

            $stmt->close();
            exit;
        }

        $employee = $result->fetch_assoc();
        $stmt->close();

        /*
        |--------------------------------------------------------------------------
        | CHECK IF ALREADY ARCHIVED
        |--------------------------------------------------------------------------
        */

        if (strtolower($employee['employment_status']) === 'archived') {

            echo json_encode([
                'success' => false,
                'message' => 'This employee is already archived.'
            ]);

            exit;
        }

        /*
        |--------------------------------------------------------------------------
        | UPDATE STATUS
        |--------------------------------------------------------------------------
        */

        $new_status = 'Archived';

        $update = $conn->prepare("
            UPDATE employees
            SET employment_status = ?, archived_at = NOW()
            WHERE employee_id = ? AND company_id = ?
        ");

        $update->bind_param(
            "sii",
            $new_status,
            $employee_id,
            $companyId
        );

        if ($update->execute() && $update->affected_rows > 0) {

            echo json_encode([
                'success' => true,
                'message' =>
                    $employee['first_name'] . ' ' .
                    $employee['last_name'] .
                    ' has been archived successfully.'
            ]);

        } else {

            echo json_encode([
                'success' => false,
                'message' => 'Unable to archive the employee.'
            ]);
        }

        $update->close();
        exit;
    }


    if ($_POST['action'] === 'restore') {

        $employee_id = isset($_POST['employee_id'])
            ? (int) $_POST['employee_id']
            : 0;

        if ($employee_id <= 0) {

            $_SESSION['restore_error'] = "Invalid employee.";

        } else {

            /*
            |--------------------------------------------------------------------------
            | CHECK EMPLOYEE
            |--------------------------------------------------------------------------
            */

            $stmt = $conn->prepare("
                SELECT employee_id, first_name, last_name, employment_status
                FROM employees
                WHERE employee_id = ? AND company_id = ?
                LIMIT 1
            ");

            $stmt->bind_param("ii", $employee_id, $companyId);
            $stmt->execute();

            $result_restore = $stmt->get_result();

            if ($result_restore->num_rows === 0) {

                $_SESSION['restore_error'] = "Employee not found.";

            } else {

                $employee_restore = $result_restore->fetch_assoc();

                /*
                |--------------------------------------------------------------------------
                | CHECK IF ARCHIVED
                |--------------------------------------------------------------------------
                */

                if (strtolower($employee_restore['employment_status']) !== 'archived') {

                    $_SESSION['restore_error'] =
                        "This employee is not archived.";

                } else {

                    /*
                    |--------------------------------------------------------------------------
                    | RESTORE
                    |--------------------------------------------------------------------------
                    */

                    $new_status = 'Official Employee';

                    $update = $conn->prepare("
                        UPDATE employees
                        SET employment_status = ?, archived_at = NULL
                        WHERE employee_id = ? AND company_id = ?
                        AND employment_status = 'Archived'
                    ");

                    $update->bind_param(
                        "sii",
                        $new_status,
                        $employee_id,
                        $companyId
                    );

                    if ($update->execute() && $update->affected_rows > 0) {

                        $_SESSION['restore_success'] =
                            $employee_restore['first_name'] . ' ' .
                            $employee_restore['last_name'] .
                            " has been restored successfully.";

                    } else {

                        $_SESSION['restore_error'] =
                            "Unable to restore the employee.";
                    }

                    $update->close();
                }
            }

            $stmt->close();
        }

        /*
        |--------------------------------------------------------------------------
        | REDIRECT BACK TO ARCHIVED EMPLOYEES
        |--------------------------------------------------------------------------
        */

        header("Location: archive_employee.php");
        exit;
    }
}


/*
|--------------------------------------------------------------------------
| INCLUDE HR HEADER
|--------------------------------------------------------------------------
*/

include includeRoleHeader(__DIR__, 'hr_header.php');


/*
|--------------------------------------------------------------------------
| GET ARCHIVED EMPLOYEES
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
        e.profile_picture,
        e.employment_status,
        e.branch_id,
        e.job_id,
        e.created_at,

        b.branch_name,

        j.job_title,
        j.department

    FROM employees e

    LEFT JOIN branch b
        ON e.branch_id = b.branch_id AND b.company_id = e.company_id

    LEFT JOIN job j
        ON e.job_id = j.job_id AND j.company_id = e.company_id

    WHERE e.employment_status = 'Archived'
      AND e.company_id = " . (int) $companyId . "

    ORDER BY e.employee_id DESC
";


$result = $conn->query($sql);

if (!$result) {
    die("Database Error: " . $conn->error);
}

?>

<style>
    .page-title {
        color: #00224c;
        font-weight: 700;
    }

    .page-subtitle {
        color: #64748b;
        font-size: 14px;
    }

    .archive-container {
        background: #fff;
        border: 1px solid #dfe5ec;
        border-radius: 15px;
        overflow: hidden;
    }

    .archive-toolbar {
        padding: 16px;
        border-bottom: 1px solid #e1e6ec;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 15px;
    }

    .search-box {
        width: 360px;
        max-width: 100%;
        position: relative;
    }

    .search-box i {
        position: absolute;
        left: 14px;
        top: 50%;
        transform: translateY(-50%);
        color: #64748b;
    }

    .search-box input {
        width: 100%;
        height: 40px;
        border: 1px solid #d8e0e8;
        background: #f5f7fb;
        border-radius: 20px;
        padding: 0 15px 0 38px;
        outline: none;
        font-size: 14px;
    }

    .search-box input:focus {
        border-color: #00224c;
        background: #fff;
    }

    .archive-table {
        margin: 0;
    }

    .archive-table thead th {
        background: #f5f7fb;
        color: #29415f;
        font-size: 13px;
        font-weight: 600;
        padding: 14px 18px;
        border-bottom: 1px solid #e1e6ec;
        white-space: nowrap;
    }

    .archive-table tbody td {
        padding: 14px 18px;
        vertical-align: middle;
        border-bottom: 1px solid #e5e9ef;
        color: #00224c;
        font-size: 14px;
    }

    .archive-table tbody tr:hover {
        background: #fafbfd;
    }

    .employee-info {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .employee-avatar {
        width: 40px;
        height: 40px;
        min-width: 40px;
        border-radius: 50%;
        background: #00224c;
        color: #fff;
        display: flex;
        justify-content: center;
        align-items: center;
        font-weight: 700;
        font-size: 13px;
    }

    .employee-name {
        font-weight: 600;
        color: #00224c;
    }

    .employee-code {
        font-size: 12px;
        color: #64748b;
        margin-top: 2px;
    }

    .status-badge {
        display: inline-block;
        padding: 5px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 600;
        background: #fee2e2;
        color: #b91c1c;
        border: 1px solid #fecaca;
    }

    .btn-view {
        background: #00224c;
        color: #fff;
        border: none;
        border-radius: 20px;
        padding: 7px 14px;
        font-size: 12px;
    }

    .btn-view:hover {
        background: #fbbd23;
        color: #00224c;
    }

    .empty-row {
        text-align: center;
        padding: 60px !important;
        color: #64748b !important;
    }

    .archive-footer {
        padding: 14px 18px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        color: #64748b;
        font-size: 13px;
    }

    @media (max-width: 900px) {

        .archive-toolbar {
            flex-direction: column;
            align-items: stretch;
        }

        .search-box {
            width: 100%;
        }

        .table-responsive {
            overflow-x: auto;
        }

    }
</style>

<?php if (isset($_SESSION['restore_success'])): ?>

    <script>

        Swal.fire({

            icon: "success",

            title: "Restored",

            text: <?= json_encode($_SESSION['restore_success']) ?>,

            timer: 1800,

            showConfirmButton: false

        });

    </script>

    <?php

    unset($_SESSION['restore_success']);

endif;
?>


<?php if (isset($_SESSION['restore_error'])): ?>

    <script>

        Swal.fire({

            icon: "error",

            title: "Unable to Restore",

            text: <?= json_encode($_SESSION['restore_error']) ?>

        });

    </script>

    <?php

    unset($_SESSION['restore_error']);

endif;
?>

<div class="container-fluid py-2">

    <!-- PAGE HEADER -->
    <div class="d-flex justify-content-between align-items-center mb-4">

        <div>

            <h2 class="page-title mb-1">
                Archived Employees
            </h2>

            <p class="page-subtitle mb-0">
                Archived employee records are retained for auditing and record keeping.
            </p>

        </div>

    </div>


    <!-- ARCHIVED EMPLOYEE TABLE -->

    <div class="archive-container">

        <div class="table-responsive">

            <table class="table archive-table" id="archiveTable" style="width:100%">

                <thead>

                    <tr>

                        <th>
                            Employee
                        </th>

                        <th>
                            Department
                        </th>

                        <th>
                            Branch
                        </th>

                        <th>
                            Position
                        </th>

                        <th>
                            Date Hired
                        </th>

                        <th>
                            Status
                        </th>

                        <th class="text-end">
                            Actions
                        </th>

                    </tr>

                </thead>

                <tbody>

                    <?php if ($result->num_rows > 0): ?>

                        <?php while ($employee = $result->fetch_assoc()): ?>

                            <?php

                            $fullName =
                                trim(
                                    $employee['first_name'] . ' ' .
                                    ($employee['middle_name'] ?? '') . ' ' .
                                    $employee['last_name'] . ' ' .
                                    ($employee['suffix'] ?? '')
                                );

                            $initials =
                                strtoupper(
                                    substr($employee['first_name'], 0, 1) .
                                    substr($employee['last_name'], 0, 1)
                                );

                            ?>

                            <tr class="employee-row">

                                <!-- EMPLOYEE -->

                                <td>

                                    <div class="employee-info">

                                        <div class="employee-avatar">
                                            <?= htmlspecialchars($initials) ?>
                                        </div>

                                        <div>

                                            <div class="employee-name">
                                                <?= htmlspecialchars($fullName) ?>
                                            </div>

                                            <div class="employee-code">

                                                <?= htmlspecialchars(
                                                    $employee['employee_code'] ?? ''
                                                ) ?>

                                                <?php if (!empty($employee['email'])): ?>

                                                    ·
                                                    <?= htmlspecialchars(
                                                        $employee['email']
                                                    ) ?>

                                                <?php endif; ?>

                                            </div>

                                        </div>

                                    </div>

                                </td>


                                <!-- DEPARTMENT -->

                                <td>

                                    <?= htmlspecialchars(
                                        $employee['department'] ?? '—'
                                    ) ?>

                                </td>


                                <!-- BRANCH -->

                                <td>

                                    <?= htmlspecialchars(
                                        $employee['branch_name'] ?? '—'
                                    ) ?>

                                </td>


                                <!-- POSITION -->

                                <td>

                                    <?= htmlspecialchars(
                                        $employee['job_title'] ?? '—'
                                    ) ?>

                                </td>


                                <!-- DATE -->

                                <td>

                                    <?= !empty($employee['created_at'])
                                        ? date(
                                            'Y-m-d',
                                            strtotime($employee['created_at'])
                                        )
                                        : '—'
                                        ?>

                                </td>


                                <!-- STATUS -->

                                <td>

                                    <span class="status-badge">
                                        Archived
                                    </span>

                                </td>


                                <td class="text-end">

                                    <!-- VIEW -->
                                    <a href="employee_view.php?id=<?= (int) $employee['employee_id'] ?>"
                                        class="btn btn-view btn-sm me-1" title="View Employee">

                                        <i class="bi bi-eye me-1"></i>
                                        View

                                    </a>


                                    <!-- RESTORE -->
                                    <form method="POST" action="archive_employee.php" class="d-inline restoreForm">

                                        <input type="hidden" name="action" value="restore">

                                        <input type="hidden" name="employee_id" value="<?= (int) $employee['employee_id'] ?>">

                                        <button type="submit" class="btn btn-success btn-sm" title="Restore Employee">

                                            <i class="bi bi-arrow-counterclockwise me-1"></i>
                                            Restore

                                        </button>

                                    </form>

                                </td>

                            </tr>

                        <?php endwhile; ?>

                    <?php else: ?>

                        <tr>

                            <td colspan="7" class="empty-row">

                                <i class="bi bi-archive fs-3 d-block mb-2"></i>

                                No archived employees

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

    new DataTable("#archiveTable", {
        pageLength: 10,
        lengthChange: false,
        ordering: true,
        order: [],
        columnDefs: [{ orderable: false, targets: 6 }],
        language: {
            search: "",
            searchPlaceholder: "Search archived employees...",
            info: "Showing _START_ to _END_ of _TOTAL_",
            infoEmpty: "No records",
            zeroRecords: "No matching employees",
            emptyTable: "No archived employees",
            paginate: { previous: "Previous", next: "Next" }
        }
    });


    document.querySelectorAll(".restoreForm").forEach(function (form) {

        form.addEventListener("submit", function (event) {

            event.preventDefault();

            const currentForm = this;

            Swal.fire({

                title: "Restore Employee?",

                text: "This employee will be returned to the active employee directory.",

                icon: "question",

                showCancelButton: true,

                confirmButtonColor: "#198754",

                cancelButtonColor: "#6c757d",

                confirmButtonText: "Yes, Restore",

                cancelButtonText: "Cancel",

                reverseButtons: true

            }).then(function (result) {

                if (result.isConfirmed) {

                    currentForm.submit();

                }

            });

        });

    });

});
</script>

<?php include includeRoleFooter(__DIR__, 'hr_footer.php'); ?>