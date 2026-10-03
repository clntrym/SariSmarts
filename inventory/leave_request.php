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
$employee_id = $employee['employee_id'];

// =======================================
// SAVE LEAVE REQUEST
// =======================================

if (isset($_POST['submitLeave'])) {

    $employee_id = (int) $_POST['employee_id'];
    $leave_type = trim($_POST['leave_type'] ?? '');
    $duration = trim($_POST['duration'] ?? '');
    $start_date = trim($_POST['start_date'] ?? '');
    $end_date = trim($_POST['end_date'] ?? '');
    $reason = trim($_POST['reason'] ?? '');

    $errors = [];
    if ($leave_type === '' || preg_match('/^[^a-zA-Z0-9\s]+$/', $leave_type)) $errors[] = "leave_type";
    if ($duration === '') $errors[] = "duration";
    if ($start_date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date)) $errors[] = "start_date";
    if ($end_date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) $errors[] = "end_date";
    if ($reason === '' || preg_match('/^\s+$/', $reason) || preg_match('/^[^a-zA-Z0-9\s]+$/', $reason)) $errors[] = "reason";

    if (!empty($errors)) {
        header("Location: leave_request.php?error=validation");
        exit;
    }

    $attachment = "";

    if (
        isset($_FILES['attachment']) &&
        $_FILES['attachment']['error'] == 0
    ) {
        $folder = "../uploads/leave/";
        if (!is_dir($folder)) {
            mkdir($folder, 0777, true);
        }
        $ext = strtolower(pathinfo($_FILES['attachment']['name'], PATHINFO_EXTENSION));
        $filename = "LEAVE_" . $employee_id . "_" . time() . "." . $ext;
        move_uploaded_file($_FILES['attachment']['tmp_name'], $folder . $filename);
        $attachment = "uploads/leave/" . $filename;
    }

    $companyId = requireCompany();

    $stmt = $conn->prepare("
        INSERT INTO leave_requests
        (company_id, employee_id, leave_type, duration, start_date, end_date, reason, attachment, hr_status, admin_status, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Pending', 'Pending', NOW())
    ");
    $stmt->bind_param("iissssss", $companyId, $employee_id, $leave_type, $duration, $start_date, $end_date, $reason, $attachment);
    $stmt->execute();
    $stmt->close();

    header("Location: leave_request.php?success=1");
    exit;
}

include("inventory_header.php");
?>

<?php if (isset($_GET['success'])) { ?>
    <script>
        Swal.fire({ icon: "success", title: "Leave Request Submitted", text: "Your leave request has been sent to HR for review." });
    </script>
<?php } ?>

<?php if (isset($_GET['error']) && $_GET['error'] === 'validation') { ?>
    <script>
        Swal.fire({ icon: "warning", title: "Invalid Input", text: "Please fill in all fields correctly. Spaces-only or special-characters-only values are not allowed." });
    </script>
<?php } ?>

<div class="container-fluid">

    <div class="card shadow rounded-4">

        <div class="card-header">
            <h3 class="fw-bold" style="color: #00224c;">
                Leave Request
            </h3>
        </div>

        <div class="card-body">

            <form method="POST" enctype="multipart/form-data">

                <input type="hidden" name="employee_id" value="<?= (int)$employee_id ?>">

                <div class="row">

                    <div class="col-md-6 mb-3">
                        <label>Employee Name</label>
                        <input type="text" class="form-control" value="<?= htmlspecialchars($employee['fullname']) ?>" readonly>
                    </div>

                    <div class="col-md-6 mb-3">
                        <label>Employee ID</label>
                        <input type="text" class="form-control"
                            value="EMP-<?= str_pad($employee_id, 4, '0', STR_PAD_LEFT) ?>" readonly>
                    </div>

                    <div class="col-md-6 mb-3">
                        <label>Leave Type</label>
                        <select name="leave_type" class="form-select" required>
                            <option value="">Select Leave</option>
                            <option>Vacation Leave</option>
                            <option>Sick Leave</option>
                            <option>Emergency Leave</option>
                            <option>Maternity/Paternity Leave</option>
                        </select>
                    </div>

                    <div class="col-md-6 mb-3">
                        <label>Duration</label>
                        <select name="duration" class="form-select" required>
                            <option>Full Day</option>
                            <option>Half Day (Morning)</option>
                            <option>Half Day (Afternoon)</option>
                        </select>
                    </div>

                    <div class="col-md-6 mb-3">
                        <label>Start Date</label>
                        <input type="date" name="start_date" class="form-control" required>
                    </div>

                    <div class="col-md-6 mb-3">
                        <label>End Date</label>
                        <input type="date" name="end_date" class="form-control" required>
                    </div>

                    <div class="col-12 mb-3">
                        <label>Reason</label>
                        <textarea name="reason" class="form-control" rows="4" required></textarea>
                    </div>

                    <div class="col-12 mb-3">
                        <label>Attachment (Optional)</label>
                        <input type="file" name="attachment" class="form-control">
                    </div>

                </div>

                <button type="submit" name="submitLeave" class="btn btn-primary">
                    Submit Leave Request
                </button>

            </form>

        </div>

    </div>

</div>

<!-- Leave History -->
<?php
$historyStmt = $conn->prepare("
    SELECT leave_id, leave_type, duration, start_date, end_date, reason,
           hr_status, admin_status, hr_remarks, admin_remarks, created_at
    FROM leave_requests
    WHERE employee_id = ?
    ORDER BY created_at DESC
");
$historyStmt->bind_param("i", $employee_id);
$historyStmt->execute();
$leaveHistory = $historyStmt->get_result();
?>

<style>
    .dataTables_wrapper .dataTables_filter { float:none; text-align:left; padding:18px 18px 12px; }
    .dataTables_wrapper .dataTables_filter label { width:100%; font-size:0; }
    .dataTables_wrapper .dataTables_filter input { margin-left:0!important; width:430px; max-width:100%; height:43px; border:1px solid #d9e1e8; border-radius:22px; padding:0 18px; font-size:14px; outline:none; }
    .dataTables_wrapper .dataTables_filter input:focus { border-color:#00224c; box-shadow:0 0 0 3px rgba(0,34,76,.08); }
    .dataTables_wrapper .dt-layout-row:last-child { display:flex; align-items:center; justify-content:space-between; padding:14px 18px; }
</style>

<div class="container-fluid mt-4">
    <div class="card shadow rounded-4">
        <div class="card-header">
            <h3 class="fw-bold" style="color: #00224c;">My Leave History</h3>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table id="leaveHistoryTable" class="table table-hover mb-0" style="width:100%">
                    <thead>
                        <tr>
                            <th>Date Filed</th>
                            <th>Leave Type</th>
                            <th>Duration</th>
                            <th>Start</th>
                            <th>End</th>
                            <th>Reason</th>
                            <th>HR Status</th>
                            <th>Admin Status</th>
                            <th>Remarks</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($lh = $leaveHistory->fetch_assoc()): ?>
                            <?php
                                $hrBadge = match($lh['hr_status']) {
                                    'Approved' => 'bg-success',
                                    'Rejected' => 'bg-danger',
                                    default => 'bg-warning text-dark'
                                };
                                $adminBadge = match($lh['admin_status']) {
                                    'Approved' => 'bg-success',
                                    'Rejected' => 'bg-danger',
                                    default => 'bg-warning text-dark'
                                };
                                $remarks = '';
                                if (!empty($lh['hr_remarks'])) $remarks .= 'HR: ' . $lh['hr_remarks'];
                                if (!empty($lh['admin_remarks'])) {
                                    if ($remarks !== '') $remarks .= ' | ';
                                    $remarks .= 'Admin: ' . $lh['admin_remarks'];
                                }
                            ?>
                            <tr>
                                <td data-order="<?= htmlspecialchars($lh['created_at']) ?>">
                                    <?= date('M d, Y', strtotime($lh['created_at'])) ?>
                                </td>
                                <td><?= htmlspecialchars($lh['leave_type']) ?></td>
                                <td><?= htmlspecialchars($lh['duration']) ?></td>
                                <td data-order="<?= htmlspecialchars($lh['start_date']) ?>">
                                    <?= date('M d, Y', strtotime($lh['start_date'])) ?>
                                </td>
                                <td data-order="<?= htmlspecialchars($lh['end_date']) ?>">
                                    <?= date('M d, Y', strtotime($lh['end_date'])) ?>
                                </td>
                                <td><?= htmlspecialchars($lh['reason']) ?></td>
                                <td><span class="badge <?= $hrBadge ?>"><?= htmlspecialchars($lh['hr_status']) ?></span></td>
                                <td><span class="badge <?= $adminBadge ?>"><?= htmlspecialchars($lh['admin_status']) ?></span></td>
                                <td><?= htmlspecialchars($remarks) ?></td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php $historyStmt->close(); ?>

<script>
document.addEventListener("DOMContentLoaded", function() {
    new DataTable("#leaveHistoryTable", {
        pageLength: 10,
        lengthChange: false,
        ordering: true,
        order: [[0, "desc"]],
        language: {
            search: "",
            searchPlaceholder: "Search leave history...",
            info: "Showing _START_ to _END_ of _TOTAL_",
            paginate: { previous: "Previous", next: "Next" },
            emptyTable: "No leave requests filed yet",
            zeroRecords: "No matching records"
        }
    });
});
</script>

<?php include("inventory_footer.php"); ?>