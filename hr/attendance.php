<?php
require_once("../init.php");
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
$user_id = $_SESSION['user_id'];

$empRow = $conn->prepare("SELECT employee_id FROM users WHERE user_id = ? LIMIT 1");
$empRow->bind_param("i", $user_id);
$empRow->execute();
$empResult = $empRow->get_result()->fetch_assoc();
$employee_id = $empResult['employee_id'] ?? 0;
$empRow->close();

/*
|--------------------------------------------------------------------------
| SUBMIT LEAVE REQUEST
|--------------------------------------------------------------------------
*/
if (isset($_POST['submitLeave'])) {
    header('Content-Type: application/json');

    $leave_type = trim($_POST['leave_type'] ?? '');
    $duration   = trim($_POST['duration'] ?? '');
    $start_date = trim($_POST['start_date'] ?? '');
    $end_date   = trim($_POST['end_date'] ?? '');
    $reason     = trim($_POST['reason'] ?? '');

    if ($leave_type === '' || $duration === '' || $start_date === '' || $end_date === '' || $reason === '') {
        echo json_encode(["success" => false, "message" => "All fields are required."]);
        exit;
    }

    if (preg_match('/^[^a-zA-Z0-9\s]+$/', $reason)) {
        echo json_encode(["success" => false, "message" => "Reason cannot contain only special characters."]);
        exit;
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) || !strtotime($start_date) ||
        !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date) || !strtotime($end_date)) {
        echo json_encode(["success" => false, "message" => "Invalid date format."]);
        exit;
    }

    if (strtotime($end_date) < strtotime($start_date)) {
        echo json_encode(["success" => false, "message" => "End date cannot be before start date."]);
        exit;
    }

    $stmt = $conn->prepare("INSERT INTO leave_requests (company_id, employee_id, leave_type, duration, start_date, end_date, reason) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("iisssss", $companyId, $employee_id, $leave_type, $duration, $start_date, $end_date, $reason);

    if ($stmt->execute()) {
        echo json_encode(["success" => true, "message" => "Leave request submitted."]);
    } else {
        echo json_encode(["success" => false, "message" => "Failed to submit request."]);
    }
    $stmt->close();
    exit;
}

/*
|--------------------------------------------------------------------------
| SUBMIT OVERTIME REQUEST
|--------------------------------------------------------------------------
*/
if (isset($_POST['submitOvertime'])) {
    header('Content-Type: application/json');

    $requested_hours = floatval($_POST['requested_hours'] ?? 0);
    $reason          = trim($_POST['reason'] ?? '');

    if ($requested_hours <= 0 || $reason === '') {
        echo json_encode(["success" => false, "message" => "Hours and reason are required."]);
        exit;
    }

    if (preg_match('/^[^a-zA-Z0-9\s]+$/', $reason)) {
        echo json_encode(["success" => false, "message" => "Reason cannot contain only special characters."]);
        exit;
    }

    $stmt = $conn->prepare("INSERT INTO overtime_requests (company_id, employee_id, requested_hours, reason) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("iids", $companyId, $employee_id, $requested_hours, $reason);

    if ($stmt->execute()) {
        echo json_encode(["success" => true, "message" => "Overtime request submitted."]);
    } else {
        echo json_encode(["success" => false, "message" => "Failed to submit request."]);
    }
    $stmt->close();
    exit;
}

/*
|--------------------------------------------------------------------------
| SUBMIT UNDERTIME REQUEST
|--------------------------------------------------------------------------
*/
if (isset($_POST['submitUndertime'])) {
    header('Content-Type: application/json');

    $request_date = trim($_POST['request_date'] ?? '');
    $hours        = floatval($_POST['hours'] ?? 0);
    $reason       = trim($_POST['reason'] ?? '');

    if ($request_date === '' || $hours <= 0 || $reason === '') {
        echo json_encode(["success" => false, "message" => "All fields are required."]);
        exit;
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $request_date) || !strtotime($request_date)) {
        echo json_encode(["success" => false, "message" => "Invalid date format."]);
        exit;
    }

    if (preg_match('/^[^a-zA-Z0-9\s]+$/', $reason)) {
        echo json_encode(["success" => false, "message" => "Reason cannot contain only special characters."]);
        exit;
    }

    $stmt = $conn->prepare("INSERT INTO undertime_requests (company_id, employee_id, request_date, hours, reason) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("iisds", $companyId, $employee_id, $request_date, $hours, $reason);

    if ($stmt->execute()) {
        echo json_encode(["success" => true, "message" => "Undertime request submitted."]);
    } else {
        echo json_encode(["success" => false, "message" => "Failed to submit request."]);
    }
    $stmt->close();
    exit;
}

include includeRoleHeader(__DIR__, 'hr_header.php');

/*
|--------------------------------------------------------------------------
| LOAD REQUEST HISTORY
|--------------------------------------------------------------------------
*/

$leaveHistory = $conn->prepare("SELECT leave_id AS id, 'Leave' AS type, leave_type AS subtype, start_date, end_date, reason, hr_status AS status, created_at FROM leave_requests WHERE employee_id = ? ORDER BY created_at DESC");
$leaveHistory->bind_param("i", $employee_id);
$leaveHistory->execute();
$leaveRows = $leaveHistory->get_result()->fetch_all(MYSQLI_ASSOC);
$leaveHistory->close();

$otHistory = $conn->prepare("SELECT overtime_id AS id, 'Overtime' AS type, '' AS subtype, NULL AS start_date, NULL AS end_date, reason, status, created_at FROM overtime_requests WHERE employee_id = ? ORDER BY created_at DESC");
$otHistory->bind_param("i", $employee_id);
$otHistory->execute();
$otRows = $otHistory->get_result()->fetch_all(MYSQLI_ASSOC);
$otHistory->close();

$utHistory = $conn->prepare("SELECT undertime_id AS id, 'Undertime' AS type, '' AS subtype, request_date AS start_date, request_date AS end_date, reason, status, created_at FROM undertime_requests WHERE employee_id = ? ORDER BY created_at DESC");
$utHistory->bind_param("i", $employee_id);
$utHistory->execute();
$utRows = $utHistory->get_result()->fetch_all(MYSQLI_ASSOC);
$utHistory->close();

$allRequests = array_merge($leaveRows, $otRows, $utRows);
usort($allRequests, function ($a, $b) {
    return strtotime($b['created_at']) - strtotime($a['created_at']);
});
?>

<style>
    .tr-card {
        border: none;
        border-radius: 16px;
        box-shadow: 0 2px 12px rgba(0,0,0,.06);
        transition: transform .2s, box-shadow .2s;
        cursor: pointer;
        height: 100%;
    }
    .tr-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 8px 24px rgba(0,0,0,.1);
    }
    .tr-card .card-body {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        padding: 28px 20px;
    }
    .tr-card .tr-icon {
        width: 64px;
        height: 64px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 28px;
        margin-bottom: 14px;
    }
    .tr-card-leave .tr-icon { background: #e8f5e9; color: #2e7d32; }
    .tr-card-overtime .tr-icon { background: #fff3e0; color: #e65100; }
    .tr-card-undertime .tr-icon { background: #e3f2fd; color: #1565c0; }
    .tr-card h5 { font-weight: 700; color: #00224c; margin-bottom: 4px; }
    .tr-card p { color: #6c757d; font-size: 13px; margin: 0; }

    .status-badge {
        font-size: 12px;
        padding: 4px 10px;
        border-radius: 20px;
    }
</style>

<div class="container-fluid py-3">

    <div class="mb-4">
        <h1 class="mb-0 fw-bold" style="color: #00224c;">
            <i class="bi bi-clock-history me-2"></i>Time & Request
        </h1>
        <p class="text-muted mb-0">Submit and manage your leave, overtime, and undertime requests.</p>
    </div>

    <!-- Request Cards -->
    <div class="row g-4 mb-4">
        <div class="col-md-4">
            <div class="card tr-card tr-card-leave" data-bs-toggle="modal" data-bs-target="#leaveModal">
                <div class="card-body">
                    <div class="tr-icon"><i class="bi bi-calendar-check"></i></div>
                    <h5>Leave Request</h5>
                    <p>Apply for vacation, sick, or personal leave</p>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card tr-card tr-card-overtime" data-bs-toggle="modal" data-bs-target="#overtimeModal">
                <div class="card-body">
                    <div class="tr-icon"><i class="bi bi-alarm"></i></div>
                    <h5>Overtime Request</h5>
                    <p>Request approval for additional work hours</p>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card tr-card tr-card-undertime" data-bs-toggle="modal" data-bs-target="#undertimeModal">
                <div class="card-body">
                    <div class="tr-icon"><i class="bi bi-box-arrow-left"></i></div>
                    <h5>Undertime Request</h5>
                    <p>Request to leave before your shift ends</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Request History -->
    <div class="card shadow-sm border-0">
        <div class="card-header bg-white py-3">
            <h5 class="mb-0 fw-bold" style="color: #00224c;">
                <i class="bi bi-list-check me-2"></i>My Request History
            </h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle" id="historyTable" style="width:100%">
                    <thead class="table-light">
                        <tr>
                            <th>Type</th>
                            <th>Details</th>
                            <th>Date(s)</th>
                            <th>Reason</th>
                            <th>Status</th>
                            <th>Filed On</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($allRequests as $req) {
                            $statusColor = match($req['status']) {
                                'Approved' => 'bg-success',
                                'Rejected' => 'bg-danger',
                                default    => 'bg-warning text-dark'
                            };
                            $typeColor = match($req['type']) {
                                'Leave'     => 'bg-success bg-opacity-10 text-success',
                                'Overtime'  => 'bg-warning bg-opacity-10 text-warning',
                                'Undertime' => 'bg-info bg-opacity-10 text-info',
                                default     => 'bg-secondary'
                            };
                        ?>
                            <tr>
                                <td>
                                    <span class="badge <?= $typeColor ?> status-badge"><?= htmlspecialchars($req['type']) ?></span>
                                </td>
                                <td>
                                    <?php if ($req['type'] === 'Leave') { ?>
                                        <?= htmlspecialchars($req['subtype']) ?>
                                    <?php } elseif ($req['type'] === 'Overtime') { ?>
                                        Overtime Hours
                                    <?php } else { ?>
                                        Undertime
                                    <?php } ?>
                                </td>
                                <td>
                                    <?php if ($req['start_date']) { ?>
                                        <?= date("M d, Y", strtotime($req['start_date'])) ?>
                                        <?php if ($req['end_date'] && $req['end_date'] !== $req['start_date']) { ?>
                                            - <?= date("M d, Y", strtotime($req['end_date'])) ?>
                                        <?php } ?>
                                    <?php } else { ?>
                                        —
                                    <?php } ?>
                                </td>
                                <td>
                                    <span title="<?= htmlspecialchars($req['reason'] ?? '') ?>">
                                        <?= htmlspecialchars(mb_strimwidth($req['reason'] ?? '—', 0, 40, '...')) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge <?= $statusColor ?> status-badge"><?= htmlspecialchars($req['status']) ?></span>
                                </td>
                                <td data-order="<?= strtotime($req['created_at']) ?>"><?= date("M d, Y h:i A", strtotime($req['created_at'])) ?></td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Leave Request Modal -->
<div class="modal fade" id="leaveModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header" style="background:#2e7d32; color:#fff;">
                <h5 class="modal-title fw-bold"><i class="bi bi-calendar-check me-2"></i>Leave Request</h5>
                <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Leave Type</label>
                    <select class="form-select" id="leaveType" required>
                        <option value="">Select type...</option>
                        <option>Vacation Leave</option>
                        <option>Sick Leave</option>
                        <option>Personal Leave</option>
                        <option>Emergency Leave</option>
                        <option>Maternity Leave</option>
                        <option>Paternity Leave</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Duration</label>
                    <select class="form-select" id="leaveDuration" required>
                        <option value="">Select duration...</option>
                        <option>Full Day</option>
                        <option>Half Day - AM</option>
                        <option>Half Day - PM</option>
                        <option>Multiple Days</option>
                    </select>
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-6">
                        <label class="form-label fw-semibold">Start Date</label>
                        <input type="date" class="form-control" id="leaveStart" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold">End Date</label>
                        <input type="date" class="form-control" id="leaveEnd" required>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Reason</label>
                    <textarea class="form-control" id="leaveReason" rows="3" placeholder="Why are you requesting leave?" required></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-success fw-bold" id="submitLeaveBtn">
                    <i class="bi bi-send me-1"></i>Submit
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Overtime Request Modal -->
<div class="modal fade" id="overtimeModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header" style="background:#e65100; color:#fff;">
                <h5 class="modal-title fw-bold"><i class="bi bi-alarm me-2"></i>Overtime Request</h5>
                <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Requested Hours</label>
                    <input type="number" class="form-control" id="otHours" step="0.5" min="0.5" max="8" placeholder="e.g. 2" required>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Reason</label>
                    <textarea class="form-control" id="otReason" rows="3" placeholder="Why do you need overtime?" required></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button class="btn fw-bold text-white" style="background:#e65100;" id="submitOTBtn">
                    <i class="bi bi-send me-1"></i>Submit
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Undertime Request Modal -->
<div class="modal fade" id="undertimeModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header" style="background:#1565c0; color:#fff;">
                <h5 class="modal-title fw-bold"><i class="bi bi-box-arrow-left me-2"></i>Undertime Request</h5>
                <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Date</label>
                    <input type="date" class="form-control" id="utDate" required>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Hours Undertime</label>
                    <input type="number" class="form-control" id="utHours" step="0.5" min="0.5" max="8" placeholder="e.g. 1.5" required>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Reason</label>
                    <textarea class="form-control" id="utReason" rows="3" placeholder="Why do you need to leave early?" required></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button class="btn fw-bold text-white" style="background:#1565c0;" id="submitUTBtn">
                    <i class="bi bi-send me-1"></i>Submit
                </button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function () {

    // DataTable
    if (typeof DataTable !== "undefined") {
        new DataTable("#historyTable", {
            pageLength: 10,
            lengthChange: false,
            ordering: true,
            order: [[5, "desc"]],
            language: {
                search: "",
                searchPlaceholder: "Search requests...",
                info: "Showing _START_ to _END_ of _TOTAL_",
                infoEmpty: "No records",
                zeroRecords: "No matching requests",
                emptyTable: "No requests yet",
                paginate: { previous: "Previous", next: "Next" }
            }
        });
    }

    // Validation helpers
    function isBlankOrSpaces(val) { return !val || val.trim() === ""; }
    function hasOnlySpecialChars(val) { return /^[^a-zA-Z0-9\s]+$/.test(val.trim()); }
    function isValidDate(val) { return /^\d{4}-\d{2}-\d{2}$/.test(val) && !isNaN(Date.parse(val)); }
    function isValidNumber(val) { return !isNaN(parseFloat(val)) && parseFloat(val) > 0; }

    function validate(fields) {
        for (var i = 0; i < fields.length; i++) {
            var f = fields[i];
            if (f.type === "select" && isBlankOrSpaces(f.value)) {
                Swal.fire("Required", "Please select " + f.label + ".", "warning");
                return false;
            }
            if (f.type === "text" && isBlankOrSpaces(f.value)) {
                Swal.fire("Required", f.label + " cannot be empty or spaces only.", "warning");
                return false;
            }
            if (f.type === "text" && hasOnlySpecialChars(f.value)) {
                Swal.fire("Invalid", f.label + " cannot contain only special characters.", "warning");
                return false;
            }
            if (f.type === "date" && !isValidDate(f.value)) {
                Swal.fire("Invalid", "Please enter a valid " + f.label + ".", "warning");
                return false;
            }
            if (f.type === "number" && !isValidNumber(f.value)) {
                Swal.fire("Invalid", f.label + " must be a valid positive number.", "warning");
                return false;
            }
        }
        return true;
    }

    function postRequest(body, modal) {
        return fetch(window.location.href, {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: body
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.success) {
                bootstrap.Modal.getInstance(document.getElementById(modal)).hide();
                Swal.fire({ icon: "success", title: "Submitted", text: data.message, timer: 1500, showConfirmButton: false })
                    .then(function () { location.reload(); });
            } else {
                Swal.fire("Error", data.message, "error");
            }
        })
        .catch(function () {
            Swal.fire("Error", "Something went wrong.", "error");
        });
    }

    // Leave
    document.getElementById("submitLeaveBtn").addEventListener("click", function () {
        var type = document.getElementById("leaveType").value;
        var duration = document.getElementById("leaveDuration").value;
        var start = document.getElementById("leaveStart").value;
        var end = document.getElementById("leaveEnd").value;
        var reason = document.getElementById("leaveReason").value.trim();

        if (!validate([
            { type: "select", value: type, label: "Leave Type" },
            { type: "select", value: duration, label: "Duration" },
            { type: "date", value: start, label: "Start Date" },
            { type: "date", value: end, label: "End Date" },
            { type: "text", value: reason, label: "Reason" }
        ])) return;

        if (new Date(end) < new Date(start)) {
            Swal.fire("Invalid", "End Date cannot be before Start Date.", "warning");
            return;
        }

        postRequest(
            "submitLeave=1" +
            "&leave_type=" + encodeURIComponent(type) +
            "&duration=" + encodeURIComponent(duration) +
            "&start_date=" + encodeURIComponent(start) +
            "&end_date=" + encodeURIComponent(end) +
            "&reason=" + encodeURIComponent(reason),
            "leaveModal"
        );
    });

    // Overtime
    document.getElementById("submitOTBtn").addEventListener("click", function () {
        var hours = document.getElementById("otHours").value;
        var reason = document.getElementById("otReason").value.trim();

        if (!validate([
            { type: "number", value: hours, label: "Requested Hours" },
            { type: "text", value: reason, label: "Reason" }
        ])) return;

        postRequest(
            "submitOvertime=1" +
            "&requested_hours=" + encodeURIComponent(hours) +
            "&reason=" + encodeURIComponent(reason),
            "overtimeModal"
        );
    });

    // Undertime
    document.getElementById("submitUTBtn").addEventListener("click", function () {
        var date = document.getElementById("utDate").value;
        var hours = document.getElementById("utHours").value;
        var reason = document.getElementById("utReason").value.trim();

        if (!validate([
            { type: "date", value: date, label: "Date" },
            { type: "number", value: hours, label: "Hours" },
            { type: "text", value: reason, label: "Reason" }
        ])) return;

        postRequest(
            "submitUndertime=1" +
            "&request_date=" + encodeURIComponent(date) +
            "&hours=" + encodeURIComponent(hours) +
            "&reason=" + encodeURIComponent(reason),
            "undertimeModal"
        );
    });

});
</script>

<?php include includeRoleFooter(__DIR__, 'hr_footer.php'); ?>
