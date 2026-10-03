<?php
require_once("../init.php");
requireRole(['hr']);

$companyId = requireCompany();

/*
|--------------------------------------------------------------------------
| APPROVE LEAVE
|--------------------------------------------------------------------------
*/
if (isset($_POST['approveLeave'])) {
    header('Content-Type: application/json');

    $id = (int) ($_POST['leave_id'] ?? 0);
    $user = (int) $_SESSION['user_id'];

    if ($id <= 0) {
        echo json_encode(["success" => false, "message" => "Invalid request."]);
        exit;
    }

    $stmt = $conn->prepare("UPDATE leave_requests SET hr_status='Approved', admin_status='Pending', hr_approved_by=?, hr_approved_at=NOW() WHERE leave_id=? AND company_id=?");
    $stmt->bind_param("iii", $user, $id, $companyId);

    echo json_encode(["success" => $stmt->execute()]);
    $stmt->close();
    exit;
}

/*
|--------------------------------------------------------------------------
| REJECT LEAVE
|--------------------------------------------------------------------------
*/
if (isset($_POST['rejectLeave'])) {
    header('Content-Type: application/json');

    $id = (int) ($_POST['leave_id'] ?? 0);
    $user = (int) $_SESSION['user_id'];
    $reason = trim($_POST['rejection_reason'] ?? '');

    if ($id <= 0) {
        echo json_encode(["success" => false, "message" => "Invalid request."]);
        exit;
    }

    if ($reason === '' || strlen($reason) < 3) {
        echo json_encode(["success" => false, "message" => "Please provide a valid rejection reason (at least 3 characters)."]);
        exit;
    }

    $stmt = $conn->prepare("UPDATE leave_requests SET hr_status='Rejected', hr_remarks=?, hr_approved_by=?, hr_approved_at=NOW() WHERE leave_id=? AND company_id=?");
    $stmt->bind_param("siii", $reason, $user, $id, $companyId);

    echo json_encode(["success" => $stmt->execute()]);
    $stmt->close();
    exit;
}

include("hr_header.php");

/*
|--------------------------------------------------------------------------
| LOAD LEAVE REQUESTS
|--------------------------------------------------------------------------
*/
$leaveQuery = $conn->prepare("
    SELECT
        l.*,
        e.employee_code,
        e.first_name,
        e.last_name,
        j.job_title,
        b.branch_name
    FROM leave_requests l
    INNER JOIN employees e ON l.employee_id = e.employee_id AND e.company_id = l.company_id
    LEFT JOIN job j ON e.job_id = j.job_id AND j.company_id = l.company_id
    LEFT JOIN branch b ON e.branch_id = b.branch_id AND b.company_id = l.company_id
    WHERE l.hr_status = 'Pending' AND l.company_id = " . (int) $companyId . "
    ORDER BY l.created_at DESC
");
$leaveQuery->execute();
$leaveResult = $leaveQuery->get_result();
?>

<style>
</style>

<div class="container-fluid py-3">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="mb-0 fw-bold" style="color: #00224c;">
                Leave Requests
            </h1>
            <p class="text-muted mb-0">
                Review and approve leave requests submitted by employees.
            </p>
        </div>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-body">
            <div class="table-responsive">
                <table id="leaveTable" class="table table-hover align-middle" style="width:100%">
                    <thead class="table-light">
                        <tr>
                            <th>Employee</th>
                            <th>Leave Type</th>
                            <th>Duration</th>
                            <th>Dates</th>
                            <th>Branch</th>
                            <th>Reason</th>
                            <th>Status</th>
                            <th width="200">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($row = $leaveResult->fetch_assoc()) { ?>
                            <tr>
                                <td>
                                    <b><?= htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) ?></b>
                                    <br>
                                    <small class="text-muted"><?= htmlspecialchars($row['employee_code']) ?></small>
                                </td>
                                <td><?= htmlspecialchars($row['leave_type']) ?></td>
                                <td><?= htmlspecialchars($row['duration']) ?></td>
                                <td>
                                    <?= date("M d, Y", strtotime($row['start_date'])) ?>
                                    -
                                    <?= date("M d, Y", strtotime($row['end_date'])) ?>
                                </td>
                                <td><?= htmlspecialchars($row['branch_name'] ?? '—') ?></td>
                                <td>
                                    <span title="<?= htmlspecialchars($row['reason'] ?? '') ?>">
                                        <?= htmlspecialchars(mb_strimwidth($row['reason'] ?? '—', 0, 40, '...')) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge bg-warning text-dark">Pending HR</span>
                                </td>
                                <td>
                                    <button class="btn btn-success btn-sm approveLeave" data-id="<?= (int) $row['leave_id'] ?>">
                                        <i class="bi bi-check-lg me-1"></i>Approve
                                    </button>
                                    <button class="btn btn-danger btn-sm rejectLeave" data-id="<?= (int) $row['leave_id'] ?>">
                                        <i class="bi bi-x-lg me-1"></i>Reject
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

<script>
document.addEventListener("DOMContentLoaded", function () {

    if (typeof DataTable !== "undefined") {
        new DataTable("#leaveTable", {
            pageLength: 10,
            lengthChange: false,
            ordering: true,
            order: [],
            columnDefs: [{ orderable: false, targets: 7 }],
            language: {
                search: "",
                searchPlaceholder: "Search employee, leave type, branch...",
                info: "Showing _START_ to _END_ of _TOTAL_",
                infoEmpty: "No records",
                zeroRecords: "No matching leave requests",
                emptyTable: "No pending leave requests",
                paginate: { previous: "Previous", next: "Next" }
            }
        });
    }

    document.querySelectorAll(".approveLeave").forEach(function (btn) {
        btn.addEventListener("click", function () {
            var leaveId = this.dataset.id;

            Swal.fire({
                title: "Approve Leave Request?",
                text: "This will forward the request to Admin for final approval.",
                icon: "question",
                showCancelButton: true,
                confirmButtonText: '<i class="bi bi-check-lg"></i> Approve',
                confirmButtonColor: "#198754"
            }).then(function (result) {
                if (result.isConfirmed) {
                    fetch(window.location.href, {
                        method: "POST",
                        headers: { "Content-Type": "application/x-www-form-urlencoded" },
                        body: "approveLeave=1&leave_id=" + leaveId
                    })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        Swal.fire({ icon: "success", title: "Approved", text: "Leave request forwarded to Admin.", timer: 1500, showConfirmButton: false })
                            .then(function () { location.reload(); });
                    });
                }
            });
        });
    });

    document.querySelectorAll(".rejectLeave").forEach(function (btn) {
        btn.addEventListener("click", function () {
            var leaveId = this.dataset.id;

            Swal.fire({
                title: "Reject Leave Request?",
                text: "Please provide a reason for rejecting this leave request.",
                input: "textarea",
                inputPlaceholder: "Enter rejection reason...",
                inputAttributes: { "maxlength": "500" },
                icon: "warning",
                showCancelButton: true,
                confirmButtonText: '<i class="bi bi-x-lg"></i> Reject',
                confirmButtonColor: "#dc3545",
                reverseButtons: true,
                inputValidator: function (value) {
                    if (!value || value.trim() === "") {
                        return "Please provide a reason for rejection.";
                    }
                    if (value.trim().length < 3) {
                        return "Reason must be at least 3 characters.";
                    }
                    return null;
                }
            }).then(function (result) {
                if (result.isConfirmed) {
                    fetch(window.location.href, {
                        method: "POST",
                        headers: { "Content-Type": "application/x-www-form-urlencoded" },
                        body: "rejectLeave=1&leave_id=" + leaveId + "&rejection_reason=" + encodeURIComponent(result.value.trim())
                    })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        Swal.fire({ icon: "success", title: "Rejected", text: "Leave request rejected.", timer: 1500, showConfirmButton: false })
                            .then(function () { location.reload(); });
                    });
                }
            });
        });
    });

});
</script>

<?php include("hr_footer.php"); ?>
