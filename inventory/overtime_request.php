<?php
require_once("../init.php");
requireRole(['inventory']);

$user_id = (int) $_SESSION['user_id'];

$stmtEmp = $conn->prepare("
SELECT u.employee_id, u.fullname, u.role
FROM users u
JOIN employees e ON u.employee_id=e.employee_id
WHERE u.user_id=? LIMIT 1
");
$stmtEmp->bind_param("i", $user_id);
$stmtEmp->execute();
$employee = $stmtEmp->get_result()->fetch_assoc();
$stmtEmp->close();
$employee_id = (int) $employee['employee_id'];

// =======================================
// SAVE OVERTIME REQUEST
// =======================================

if (isset($_POST['submitOvertime'])) {

    $employee_id = (int) $_POST['employee_id'];
    $work_date = trim($_POST['work_date'] ?? '');
    $requested_hours = (float) $_POST['requested_hours'];
    $reason = trim($_POST['reason'] ?? '');

    $errors = [];
    if ($work_date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $work_date)) $errors[] = "work_date";
    if ($requested_hours <= 0) $errors[] = "hours";
    if ($reason === '' || preg_match('/^\s+$/', $reason) || preg_match('/^[^a-zA-Z0-9\s]+$/', $reason)) $errors[] = "reason";

    if (!empty($errors)) {
        header("Location: overtime_request.php?error=validation");
        exit;
    }

    $stmtAtt = $conn->prepare("
        SELECT attendance_id FROM attendance
        WHERE employee_id=? AND attendance_date=? LIMIT 1
    ");
    $stmtAtt->bind_param("is", $employee_id, $work_date);
    $stmtAtt->execute();
    $attendanceRow = $stmtAtt->get_result()->fetch_assoc();
    $stmtAtt->close();

    $attendance_id = $attendanceRow ? (int) $attendanceRow['attendance_id'] : null;

    $companyId = requireCompany();

    if ($attendance_id === null) {
        $stmt = $conn->prepare("
            INSERT INTO overtime_requests (company_id, employee_id, requested_hours, reason, status, created_at)
            VALUES (?, ?, ?, ?, 'Pending', NOW())
        ");
        $stmt->bind_param("iids", $companyId, $employee_id, $requested_hours, $reason);
    } else {
        $stmt = $conn->prepare("
            INSERT INTO overtime_requests (company_id, attendance_id, employee_id, requested_hours, reason, status, created_at)
            VALUES (?, ?, ?, ?, ?, 'Pending', NOW())
        ");
        $stmt->bind_param("iiids", $companyId, $attendance_id, $employee_id, $requested_hours, $reason);
    }
    $stmt->execute();
    $stmt->close();

    header("Location: overtime_request.php?success=1");
    exit;
}

include("inventory_header.php");

$stmtHist = $conn->prepare("
    SELECT overtime_id, attendance_id, requested_hours, approved_hours, reason, rejection_reason, status, created_at
    FROM overtime_requests
    WHERE employee_id=?
    ORDER BY created_at DESC
");
$stmtHist->bind_param("i", $employee_id);
$stmtHist->execute();
$overtimeHistory = $stmtHist->get_result();
?>

<?php if (isset($_GET['success'])) { ?>
    <script>
        Swal.fire({ icon: "success", title: "Overtime Request Submitted", text: "Your overtime request has been sent to HR for review." });
    </script>
<?php } ?>

<?php if (isset($_GET['error'])) { ?>
    <script>
        Swal.fire({ icon: "warning", title: "Invalid Input", text: "Please fill in all fields correctly. Spaces-only or special-characters-only values are not allowed." });
    </script>
<?php } ?>

<style>
    .dataTables_wrapper .dataTables_filter { float:none; text-align:left; padding:18px 18px 12px; }
    .dataTables_wrapper .dataTables_filter label { width:100%; font-size:0; }
    .dataTables_wrapper .dataTables_filter input { margin-left:0!important; width:430px; max-width:100%; height:43px; border:1px solid #d9e1e8; border-radius:22px; padding:0 18px; font-size:14px; outline:none; }
    .dataTables_wrapper .dataTables_filter input:focus { border-color:#00224c; box-shadow:0 0 0 3px rgba(0,34,76,.08); }
    .dataTables_wrapper .dt-layout-row:last-child { display:flex; align-items:center; justify-content:space-between; padding:14px 18px; }
</style>

<div class="container-fluid">

    <div class="card shadow rounded-4 mb-4">
        <div class="card-header">
            <h3 class="fw-bold" style="color: #00224c;">Overtime Request</h3>
        </div>
        <div class="card-body">
            <p class="text-muted">
                Overtime is not automatically credited when you time out late.
                File a request below and HR/Admin will review and approve it.
            </p>
            <form method="POST">
                <input type="hidden" name="employee_id" value="<?= (int)$employee_id ?>">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label>Employee Name</label>
                        <input type="text" class="form-control" value="<?= htmlspecialchars($employee['fullname']) ?>" readonly>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label>Employee ID</label>
                        <input type="text" class="form-control" value="EMP-<?= str_pad($employee_id, 4, '0', STR_PAD_LEFT) ?>" readonly>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label>Date Worked</label>
                        <input type="date" name="work_date" class="form-control" required>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label>Requested Hours</label>
                        <input type="number" name="requested_hours" class="form-control" min="0.5" step="0.5" placeholder="e.g. 2.5" required>
                    </div>
                    <div class="col-12 mb-3">
                        <label>Reason</label>
                        <textarea name="reason" class="form-control" rows="4" placeholder="What did you work on during this overtime?" required></textarea>
                    </div>
                </div>
                <button type="submit" name="submitOvertime" class="btn btn-primary">Submit Overtime Request</button>
            </form>
        </div>
    </div>

    <div class="card shadow rounded-4">
        <div class="card-header">
            <h5 class="fw-bold mb-0" style="color: #00224c;">My Overtime Requests</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered table-hover align-middle" id="overtimeTable" style="width:100%">
                    <thead>
                        <tr>
                            <th>Filed</th>
                            <th>Requested</th>
                            <th>Approved</th>
                            <th>Reason</th>
                            <th>Status</th>
                            <th>Rejection Reason</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($row = $overtimeHistory->fetch_assoc()) { ?>
                            <tr>
                                <td><?= date("M d, Y", strtotime($row['created_at'])) ?></td>
                                <td><?= number_format($row['requested_hours'], 2) ?> hrs</td>
                                <td><?= $row['approved_hours'] !== null ? number_format($row['approved_hours'], 2) . ' hrs' : '-' ?></td>
                                <td><?= htmlspecialchars($row['reason']) ?></td>
                                <td>
                                    <?php
                                    switch ($row['status']) {
                                        case "Approved": echo '<span class="badge bg-success">Approved</span>'; break;
                                        case "Rejected": echo '<span class="badge bg-danger">Rejected</span>'; break;
                                        default: echo '<span class="badge bg-warning text-dark">Pending</span>';
                                    }
                                    ?>
                                </td>
                                <td><?= $row['status'] === 'Rejected' && !empty($row['rejection_reason']) ? htmlspecialchars($row['rejection_reason']) : '-' ?></td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>

<script>
new DataTable("#overtimeTable", {
    pageLength: 10,
    lengthChange: false,
    ordering: true,
    order: [],
    columnDefs: [{ orderable: false, targets: -1 }],
    language: {
        search: "",
        searchPlaceholder: "Search overtime requests...",
        info: "Showing _START_ to _END_ of _TOTAL_",
        paginate: { previous: "Previous", next: "Next" }
    }
});
</script>

<?php
$stmtHist->close();
include("inventory_footer.php");
?>