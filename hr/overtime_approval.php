<?php
require_once("../init.php");
requireRole(['hr']);

$companyId = requireCompany();

/*
|--------------------------------------------------------------------------
| APPROVE OVERTIME
|--------------------------------------------------------------------------
*/
if (isset($_POST['approveOvertime'])) {
    header('Content-Type: application/json');

    $id = (int) ($_POST['overtime_id'] ?? 0);
    $approved_hours = floatval($_POST['approved_hours'] ?? 0);

    if ($id <= 0 || $approved_hours <= 0) {
        echo json_encode(["success" => false, "message" => "Invalid request."]);
        exit;
    }

    $stmt = $conn->prepare("UPDATE overtime_requests SET status='Approved', approved_hours=? WHERE overtime_id=? AND company_id=?");
    $stmt->bind_param("dii", $approved_hours, $id, $companyId);
    echo json_encode(["success" => $stmt->execute()]);
    $stmt->close();
    exit;
}

/*
|--------------------------------------------------------------------------
| REJECT OVERTIME
|--------------------------------------------------------------------------
*/
if (isset($_POST['rejectOvertime'])) {
    header('Content-Type: application/json');

    $id = (int) ($_POST['overtime_id'] ?? 0);
    $reason = trim($_POST['rejection_reason'] ?? '');

    if ($id <= 0) {
        echo json_encode(["success" => false, "message" => "Invalid request."]);
        exit;
    }

    if ($reason === '' || strlen($reason) < 3) {
        echo json_encode(["success" => false, "message" => "Please provide a valid rejection reason (at least 3 characters)."]);
        exit;
    }

    $stmt = $conn->prepare("UPDATE overtime_requests SET status='Rejected', rejection_reason=? WHERE overtime_id=? AND company_id=?");
    $stmt->bind_param("sii", $reason, $id, $companyId);
    echo json_encode(["success" => $stmt->execute()]);
    $stmt->close();
    exit;
}

include("hr_header.php");

/*
|--------------------------------------------------------------------------
| LOAD OVERTIME REQUESTS
|--------------------------------------------------------------------------
*/
$overtimeQuery = $conn->prepare("
    SELECT
        o.*,
        e.employee_code,
        e.first_name,
        e.last_name,
        j.job_title,
        b.branch_name
    FROM overtime_requests o
    INNER JOIN employees e ON o.employee_id = e.employee_id AND e.company_id = o.company_id
    LEFT JOIN job j ON e.job_id = j.job_id AND j.company_id = o.company_id
    LEFT JOIN branch b ON e.branch_id = b.branch_id AND b.company_id = o.company_id
    WHERE o.status = 'Pending' AND o.company_id = " . (int) $companyId . "
    ORDER BY o.created_at DESC
");
$overtimeQuery->execute();
$overtimeResult = $overtimeQuery->get_result();
?>

<style>
</style>

<div class="container-fluid py-3">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="mb-0 fw-bold" style="color: #00224c;">
                Overtime Requests
            </h1>
            <p class="text-muted mb-0">
                Review and approve overtime requests submitted by employees.
            </p>
        </div>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-body">
            <div class="table-responsive">
                <table id="otTable" class="table table-hover align-middle" style="width:100%">
                    <thead class="table-light">
                        <tr>
                            <th>Employee</th>
                            <th>Position</th>
                            <th>Branch</th>
                            <th>Requested Hours</th>
                            <th>Reason</th>
                            <th>Date Filed</th>
                            <th>Status</th>
                            <th width="220">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($row = $overtimeResult->fetch_assoc()) { ?>
                            <tr>
                                <td>
                                    <b><?= htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) ?></b>
                                    <br>
                                    <small class="text-muted"><?= htmlspecialchars($row['employee_code']) ?></small>
                                </td>
                                <td><?= htmlspecialchars($row['job_title'] ?? '—') ?></td>
                                <td><?= htmlspecialchars($row['branch_name'] ?? '—') ?></td>
                                <td>
                                    <span class="badge bg-info text-dark fs-6">
                                        <?= number_format($row['requested_hours'], 1) ?> hrs
                                    </span>
                                </td>
                                <td>
                                    <span title="<?= htmlspecialchars($row['reason'] ?? '') ?>">
                                        <?= htmlspecialchars(mb_strimwidth($row['reason'] ?? '—', 0, 50, '...')) ?>
                                    </span>
                                </td>
                                <td><?= date("M d, Y h:i A", strtotime($row['created_at'])) ?></td>
                                <td>
                                    <span class="badge bg-warning text-dark">Pending</span>
                                </td>
                                <td>
                                    <button class="btn btn-success btn-sm approveOT"
                                            data-id="<?= (int) $row['overtime_id'] ?>"
                                            data-hours="<?= htmlspecialchars($row['requested_hours']) ?>">
                                        <i class="bi bi-check-lg me-1"></i>Approve
                                    </button>
                                    <button class="btn btn-danger btn-sm rejectOT"
                                            data-id="<?= (int) $row['overtime_id'] ?>">
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
        new DataTable("#otTable", {
            pageLength: 10,
            lengthChange: false,
            ordering: true,
            order: [],
            columnDefs: [{ orderable: false, targets: 7 }],
            language: {
                search: "",
                searchPlaceholder: "Search employee, position, branch...",
                info: "Showing _START_ to _END_ of _TOTAL_",
                infoEmpty: "No records",
                zeroRecords: "No matching overtime requests",
                emptyTable: "No pending overtime requests",
                paginate: { previous: "Previous", next: "Next" }
            }
        });
    }

    document.querySelectorAll(".approveOT").forEach(function (btn) {
        btn.addEventListener("click", function () {
            var otId = this.dataset.id;
            var reqHours = this.dataset.hours;

            Swal.fire({
                title: "Approve Overtime?",
                html: '<label class="form-label fw-semibold">Approved Hours</label>' +
                      '<input type="number" id="swalApprovedHours" class="swal2-input" step="0.5" min="0.5" value="' + reqHours + '">',
                icon: "question",
                showCancelButton: true,
                confirmButtonText: '<i class="bi bi-check-lg"></i> Approve',
                confirmButtonColor: "#198754",
                preConfirm: function () {
                    var hours = parseFloat(document.getElementById("swalApprovedHours").value);
                    if (!hours || hours <= 0) {
                        Swal.showValidationMessage("Please enter valid approved hours.");
                        return false;
                    }
                    return hours;
                }
            }).then(function (result) {
                if (result.isConfirmed) {
                    fetch(window.location.href, {
                        method: "POST",
                        headers: { "Content-Type": "application/x-www-form-urlencoded" },
                        body: "approveOvertime=1&overtime_id=" + otId + "&approved_hours=" + result.value
                    })
                    .then(function (r) { return r.json(); })
                    .then(function () {
                        Swal.fire({ icon: "success", title: "Approved", text: "Overtime request approved.", timer: 1500, showConfirmButton: false })
                            .then(function () { location.reload(); });
                    });
                }
            });
        });
    });

    document.querySelectorAll(".rejectOT").forEach(function (btn) {
        btn.addEventListener("click", function () {
            var otId = this.dataset.id;

            Swal.fire({
                title: "Reject Overtime Request?",
                text: "Please provide a reason for rejecting this request.",
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
                        body: "rejectOvertime=1&overtime_id=" + otId + "&rejection_reason=" + encodeURIComponent(result.value.trim())
                    })
                    .then(function (r) { return r.json(); })
                    .then(function () {
                        Swal.fire({ icon: "success", title: "Rejected", text: "Overtime request rejected.", timer: 1500, showConfirmButton: false })
                            .then(function () { location.reload(); });
                    });
                }
            });
        });
    });

});
</script>

<?php include("hr_footer.php"); ?>
