<?php

require_once("../init.php");
requireRole(['finance']);

$companyId = requireCompany();


/*======================================
LOAD PAYROLL (single record — payslip modal)
======================================*/

if (isset($_POST['loadPayroll'])) {

    header("Content-Type: application/json");

    $payrollID = (int) ($_POST['payroll_id'] ?? 0);

    if ($payrollID <= 0) {
        echo json_encode(["success" => false, "message" => "Invalid payroll record."]);
        exit;
    }

    $stmt = $conn->prepare("
        SELECT p.*, e.employee_code, e.first_name, e.last_name, j.department, j.job_title
        FROM payroll p
        INNER JOIN employees e ON e.employee_id = p.employee_id AND e.company_id = p.company_id
        LEFT JOIN job j ON j.job_id = e.job_id
        WHERE p.payroll_id = ? AND p.company_id = ? LIMIT 1
    ");
    $stmt->bind_param("ii", $payrollID, $companyId);
    $stmt->execute();
    $payroll = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$payroll) {
        echo json_encode(["success" => false, "message" => "Payroll record not found."]);
        exit;
    }

    echo json_encode(["success" => true, "payroll" => $payroll]);
    exit;
}


/*======================================
BATCH APPROVE PAYROLL
======================================*/

if (isset($_POST['batchApprovePayroll'])) {

    header("Content-Type: application/json");

    $ids = json_decode($_POST['payroll_ids'] ?? '[]', true);
    $approverID = (int) ($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);
    $approverID = $approverID > 0 ? $approverID : null;

    if (!is_array($ids) || count($ids) === 0) {
        echo json_encode(["success" => false, "message" => "No payroll records selected."]);
        exit;
    }

    $approved = 0;
    $stmt = $conn->prepare("
        UPDATE payroll SET status = 'Approved', approved_by = ?
        WHERE payroll_id = ? AND company_id = ? AND status = 'Pending Approval'
    ");

    foreach ($ids as $id) {
        $pid = (int) $id;
        if ($pid <= 0) continue;
        $stmt->bind_param("iii", $approverID, $pid, $companyId);
        $stmt->execute();
        if ($stmt->affected_rows > 0) $approved++;
    }
    $stmt->close();

    echo json_encode([
        "success" => $approved > 0,
        "message" => $approved > 0
            ? $approved . " payroll record(s) approved successfully."
            : "No records were approved. They may no longer be pending."
    ]);
    exit;
}


/*======================================
BATCH RETURN PAYROLL TO HR
======================================*/

if (isset($_POST['batchReturnPayroll'])) {

    header("Content-Type: application/json");

    $ids = json_decode($_POST['payroll_ids'] ?? '[]', true);
    $reason = trim($_POST['reason'] ?? '');
    $reviewerID = (int) ($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);
    $reviewerID = $reviewerID > 0 ? $reviewerID : null;

    if (!is_array($ids) || count($ids) === 0) {
        echo json_encode(["success" => false, "message" => "No payroll records selected."]);
        exit;
    }

    if ($reason === '' || strlen($reason) < 5) {
        echo json_encode(["success" => false, "message" => "Please provide a reason (at least 5 characters)."]);
        exit;
    }

    $returned = 0;
    $stmt = $conn->prepare("
        UPDATE payroll SET status = 'Returned', is_edited = 1, edit_reason = ?, edited_by = ?, edited_at = NOW()
        WHERE payroll_id = ? AND company_id = ? AND status = 'Pending Approval'
    ");

    foreach ($ids as $id) {
        $pid = (int) $id;
        if ($pid <= 0) continue;
        $stmt->bind_param("siii", $reason, $reviewerID, $pid, $companyId);
        $stmt->execute();
        if ($stmt->affected_rows > 0) $returned++;
    }
    $stmt->close();

    echo json_encode([
        "success" => $returned > 0,
        "message" => $returned > 0
            ? $returned . " payroll record(s) returned to HR for revision."
            : "No records were returned. They may no longer be pending."
    ]);
    exit;
}


include("finance_header.php");


/*======================================
LOAD ALL SUBMITTED PAYROLL
======================================*/

$payrollRows = [];

$query = $conn->query("
    SELECT
        p.payroll_id, p.employee_id, p.payroll_period_start, p.payroll_period_end,
        p.working_days, p.basic_pay, p.overtime_pay, p.gross_pay,
        p.late_deduction, p.undertime_deduction, p.absent_deduction,
        p.sss, p.philhealth, p.pagibig, p.total_deduction, p.net_pay,
        p.status, p.edit_reason,
        e.employee_code, e.first_name, e.last_name,
        j.department, j.job_title
    FROM payroll p
    INNER JOIN employees e ON e.employee_id = p.employee_id AND e.company_id = p.company_id
    LEFT JOIN job j ON j.job_id = e.job_id
    WHERE p.status <> 'Draft' AND p.company_id = " . (int) $companyId . "
    ORDER BY p.payroll_id DESC
");

if ($query) {
    while ($row = $query->fetch_assoc()) {
        $payrollRows[] = $row;
    }
}


/*======================================
SUMMARY + DEPARTMENT GROUPING
======================================*/

$pendingCount = 0; $pendingNetPay = 0;
$approvedCount = 0; $approvedNetPay = 0;
$returnedCount = 0; $releasedCount = 0;

$deptData = [];

foreach ($payrollRows as $row) {
    $netPay = (float) $row['net_pay'];
    if ($row['status'] === 'Pending Approval') { $pendingCount++; $pendingNetPay += $netPay; }
    elseif ($row['status'] === 'Approved') { $approvedCount++; $approvedNetPay += $netPay; }
    elseif ($row['status'] === 'Returned') { $returnedCount++; }
    elseif ($row['status'] === 'Released') { $releasedCount++; }

    $dept = $row['department'] ?? 'Unassigned';
    if (!isset($deptData[$dept])) {
        $deptData[$dept] = ['rows' => [], 'gross' => 0, 'deductions' => 0, 'net' => 0];
    }
    $deptData[$dept]['rows'][] = $row;
    $deptData[$dept]['gross'] += (float) $row['gross_pay'];
    $deptData[$dept]['deductions'] += (float) $row['total_deduction'];
    $deptData[$dept]['net'] += $netPay;
}
ksort($deptData);

?>

<style>
    .payroll-card { border:none; border-radius:18px; box-shadow:0 5px 18px rgba(0,0,0,.08); }
    .table thead { background:#00224c; color:#fff; }
    .table th { padding:15px; font-weight:600; white-space:nowrap; }
    .table td { padding:15px; vertical-align:middle; white-space:nowrap; }
    .search-box { border-radius:12px; height:45px; }
    .employee-name { font-weight:600; color:#00224c; }
    .employee-code { font-size:12px; color:#718096; }
    .amount-deduction { color:#dc3545; }

    .dashboard-card { border:none; border-radius:18px; transition:.3s; overflow:hidden; box-shadow:0 10px 25px rgba(0,0,0,.05); background:#fff; }
    .dashboard-card:hover { transform:translateY(-4px); }
    .card-icon { width:55px; height:55px; border-radius:15px; display:flex; align-items:center; justify-content:center; font-size:24px; color:#fff; }
    .bg-navy { background:#00224c; } .bg-yellow { background:#fbbd23; } .bg-green { background:#198754; } .bg-red { background:#dc3545; }
    .stat-number { font-size:26px; font-weight:700; color:#00224c; }
    .stat-sub { font-size:12px; color:#718096; }

    #payrollModal .modal-body { background:#f5f5f5; }
    #payrollModal .border { background:#fff; border:2px solid #222 !important; }
    #payrollModal table td, #payrollModal table th { padding:4px 0; }

    @media print {
        body * { visibility:hidden; }
        #payrollModal, #payrollModal * { visibility:visible; }
        #payrollModal { position:absolute; left:0; top:0; width:100%; }
        .modal-header, .modal-footer { display:none; }
        .modal-content { border:none; box-shadow:none; }
    }

    /* Tabs */
    #payrollViewTabs .nav-link { color:#475569; border:none; border-bottom:3px solid transparent; padding:10px 20px; }
    #payrollViewTabs .nav-link.active { color:#00224c; border-bottom-color:#00224c; background:none; font-weight:700; }
    #payrollViewTabs .nav-link:hover { color:#00224c; }

    /* Batch action bar */
    .batch-action-bar { position:fixed; bottom:0; left:0; right:0; background:#00224c; padding:14px 30px; z-index:1040; box-shadow:0 -4px 20px rgba(0,0,0,.25); }
    .payroll-check:checked, #selectAllPayroll:checked { background-color:#00224c; border-color:#00224c; }

    /* Department accordion */
    .dept-card { background:#fff; border:none; border-radius:18px; box-shadow:0 5px 18px rgba(0,0,0,.08); overflow:hidden; margin-bottom:16px; }
    .dept-header { padding:16px 24px; cursor:pointer; display:flex; justify-content:space-between; align-items:center; transition:background .2s; }
    .dept-header:hover { background:#f8fafc; }
    .dept-header .chevron { transition:transform .3s; font-size:18px; color:#64748b; }
    .dept-header.open .chevron { transform:rotate(180deg); }
    .dept-body { display:none; border-top:1px solid #e2e8f0; }
    .dept-body.show { display:block; }

    /* DataTable */
    .dataTables_wrapper .dataTables_filter { float:none; text-align:left; padding:18px 18px 12px; }
    .dataTables_wrapper .dataTables_filter label { width:100%; font-size:0; }
    .dataTables_wrapper .dataTables_filter input { margin-left:0!important; width:430px; max-width:100%; height:43px; border:1px solid #d9e1e8; border-radius:22px; padding:0 18px; font-size:14px; outline:none; }
    .dataTables_wrapper .dataTables_filter input:focus { border-color:#00224c; box-shadow:0 0 0 3px rgba(0,34,76,.08); }
    .dataTables_wrapper .dt-layout-row:last-child { display:flex; align-items:center; justify-content:space-between; padding:14px 18px; }
</style>

<div class="container-fluid py-4">

    <div class="mb-4">
        <h1 class="mb-0 fw-bold" style="color:#00224c;">Payroll Approval</h1>
        <p class="text-muted mb-0">Review payroll submitted by HR before it's approved for release.</p>
    </div>

    <!-- Summary Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="dashboard-card p-3">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-muted small mb-1">Pending Approval</div>
                        <div class="stat-number"><?= number_format($pendingCount) ?></div>
                        <div class="stat-sub">₱<?= number_format($pendingNetPay, 2) ?> net pay</div>
                    </div>
                    <div class="card-icon bg-yellow"><i class="bi bi-hourglass-split"></i></div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="dashboard-card p-3">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-muted small mb-1">Approved</div>
                        <div class="stat-number"><?= number_format($approvedCount) ?></div>
                        <div class="stat-sub">₱<?= number_format($approvedNetPay, 2) ?> net pay</div>
                    </div>
                    <div class="card-icon bg-green"><i class="bi bi-check-circle"></i></div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="dashboard-card p-3">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-muted small mb-1">Returned</div>
                        <div class="stat-number"><?= number_format($returnedCount) ?></div>
                        <div class="stat-sub">sent back to HR</div>
                    </div>
                    <div class="card-icon bg-red"><i class="bi bi-arrow-counterclockwise"></i></div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="dashboard-card p-3">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-muted small mb-1">Released</div>
                        <div class="stat-number"><?= number_format($releasedCount) ?></div>
                        <div class="stat-sub">fully processed</div>
                    </div>
                    <div class="card-icon bg-navy"><i class="bi bi-send-check"></i></div>
                </div>
            </div>
        </div>
    </div>


    <!-- View Tabs -->
    <ul class="nav nav-tabs mb-4" id="payrollViewTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active fw-semibold" id="allRecordsTab" data-bs-toggle="tab" data-bs-target="#allRecordsPane" type="button" role="tab">
                <i class="bi bi-table me-1"></i> All Records
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link fw-semibold" id="byDeptTab" data-bs-toggle="tab" data-bs-target="#byDeptPane" type="button" role="tab">
                <i class="bi bi-building me-1"></i> By Department
            </button>
        </li>
    </ul>

    <div class="tab-content">

    <!-- ==================== ALL RECORDS TAB ==================== -->
    <div class="tab-pane fade show active" id="allRecordsPane" role="tabpanel">

        <!-- Moved onto the search row by the shared DataTables theme -->
        <div data-dt-toolbar="payrollTable">
            <select class="form-select" id="statusFilter" style="width:200px;">
                <option value="">All Status</option>
                <option value="Pending Approval">Pending Approval</option>
                <option value="Approved">Approved</option>
                <option value="Returned">Returned</option>
                <option value="Released">Released</option>
            </select>
        </div>

        <div class="card payroll-card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table id="payrollTable" class="table table-hover mb-0" style="width:100%">
                        <thead>
                            <tr>
                                <th class="text-center" style="width:45px;">
                                    <input type="checkbox" id="selectAllPayroll" class="form-check-input" title="Select All Pending">
                                </th>
                                <th>Employee</th>
                                <th>Department</th>
                                <th>Position</th>
                                <th>Period</th>
                                <th class="text-end">Gross Pay</th>
                                <th class="text-end">Total Ded.</th>
                                <th class="text-end">Net Pay</th>
                                <th>Status</th>
                                <th width="80">Action</th>
                            </tr>
                        </thead>
                        <tbody id="payrollTableBody">
                            <?php foreach ($payrollRows as $row): ?>
                                <?php
                                $status = $row['status'];
                                $period = date('M d', strtotime($row['payroll_period_start']))
                                    . ' – ' . date('M d, Y', strtotime($row['payroll_period_end']));
                                $searchText = strtolower($row['employee_code'] . ' ' . $row['first_name'] . ' ' . $row['last_name'] . ' ' . ($row['department'] ?? '') . ' ' . ($row['job_title'] ?? ''));
                                $badgeClass = match ($status) {
                                    'Pending Approval' => 'bg-warning text-dark', 'Approved' => 'bg-success',
                                    'Returned' => 'bg-danger', 'Released' => 'bg-dark', default => 'bg-secondary'
                                };
                                ?>
                                <tr class="payroll-row"
                                    data-search="<?= htmlspecialchars($searchText, ENT_QUOTES) ?>"
                                    data-status="<?= htmlspecialchars($status, ENT_QUOTES) ?>"
                                    data-payroll-id="<?= (int) $row['payroll_id'] ?>">
                                    <td class="text-center">
                                        <?php if ($status === 'Pending Approval'): ?>
                                            <input type="checkbox" class="form-check-input payroll-check" value="<?= (int) $row['payroll_id'] ?>">
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="employee-name"><?= htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) ?></div>
                                        <div class="employee-code"><?= htmlspecialchars($row['employee_code']) ?></div>
                                    </td>
                                    <td><?= htmlspecialchars($row['department'] ?? '-') ?></td>
                                    <td><?= htmlspecialchars($row['job_title'] ?? '-') ?></td>
                                    <td><?= htmlspecialchars($period) ?></td>
                                    <td class="text-end fw-semibold">₱<?= number_format((float) $row['gross_pay'], 2) ?></td>
                                    <td class="text-end amount-deduction fw-semibold">₱<?= number_format((float) $row['total_deduction'], 2) ?></td>
                                    <td class="text-end fw-bold" style="color:#00224c;">₱<?= number_format((float) $row['net_pay'], 2) ?></td>
                                    <td><span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($status) ?></span></td>
                                    <td>
                                        <button class="btn btn-primary btn-sm viewPayroll" data-payroll-id="<?= (int) $row['payroll_id'] ?>">View</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div><!-- end allRecordsPane -->


    <!-- ==================== BY DEPARTMENT TAB ==================== -->
    <div class="tab-pane fade" id="byDeptPane" role="tabpanel">

        <?php if (empty($deptData)): ?>
            <div class="text-center py-5 text-muted">
                <i class="bi bi-building fs-1 d-block mb-2"></i>
                No payroll records found.
            </div>
        <?php else: ?>

            <?php $dIdx = 0; foreach ($deptData as $deptName => $dInfo): $dIdx++; ?>

                <div class="dept-card">
                    <div class="dept-header" data-dept="<?= $dIdx ?>">
                        <div>
                            <span class="fw-bold" style="color:#00224c; font-size:16px;">
                                <i class="bi bi-building me-2"></i><?= htmlspecialchars($deptName) ?>
                            </span>
                            <span class="badge bg-secondary ms-2"><?= count($dInfo['rows']) ?> employee(s)</span>
                        </div>
                        <div class="d-flex align-items-center gap-3">
                            <div class="text-end">
                                <span class="d-block fw-bold" style="color:#00224c;">Net: ₱<?= number_format($dInfo['net'], 2) ?></span>
                                <small class="text-muted">Gross: ₱<?= number_format($dInfo['gross'], 2) ?> &middot; Ded: ₱<?= number_format($dInfo['deductions'], 2) ?></small>
                            </div>
                            <i class="bi bi-chevron-down chevron"></i>
                        </div>
                    </div>
                    <div class="dept-body" id="deptBody<?= $dIdx ?>">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th class="px-4">Employee</th>
                                        <th>Position</th>
                                        <th>Period</th>
                                        <th class="text-end">Gross Pay</th>
                                        <th class="text-end">Deductions</th>
                                        <th class="text-end">Net Pay</th>
                                        <th>Status</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($dInfo['rows'] as $dr):
                                        $dStatus = $dr['status'];
                                        $dBadge = match ($dStatus) {
                                            'Pending Approval' => 'bg-warning text-dark', 'Approved' => 'bg-success',
                                            'Returned' => 'bg-danger', 'Released' => 'bg-dark', default => 'bg-secondary'
                                        };
                                        $dPeriod = date('M d', strtotime($dr['payroll_period_start']))
                                            . ' – ' . date('M d, Y', strtotime($dr['payroll_period_end']));
                                    ?>
                                        <tr>
                                            <td class="px-4">
                                                <div class="employee-name"><?= htmlspecialchars($dr['first_name'] . ' ' . $dr['last_name']) ?></div>
                                                <div class="employee-code"><?= htmlspecialchars($dr['employee_code']) ?></div>
                                            </td>
                                            <td><?= htmlspecialchars($dr['job_title'] ?? '-') ?></td>
                                            <td><?= htmlspecialchars($dPeriod) ?></td>
                                            <td class="text-end fw-semibold">₱<?= number_format((float) $dr['gross_pay'], 2) ?></td>
                                            <td class="text-end amount-deduction fw-semibold">₱<?= number_format((float) $dr['total_deduction'], 2) ?></td>
                                            <td class="text-end fw-bold" style="color:#00224c;">₱<?= number_format((float) $dr['net_pay'], 2) ?></td>
                                            <td><span class="badge <?= $dBadge ?>"><?= htmlspecialchars($dStatus) ?></span></td>
                                            <td><button class="btn btn-primary btn-sm viewPayroll" data-payroll-id="<?= (int) $dr['payroll_id'] ?>">View</button></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            <?php endforeach; ?>

        <?php endif; ?>

    </div><!-- end byDeptPane -->

    </div><!-- end tab-content -->

</div>


<!-- Floating Action Bar -->
<div id="batchActionBar" class="batch-action-bar" style="display:none;">
    <div class="d-flex align-items-center gap-3 flex-wrap">
        <span class="fw-semibold text-white">
            <span id="selectedCount">0</span> payroll record(s) selected
        </span>
        <button type="button" class="btn btn-success btn-sm" id="batchApproveBtn">
            <i class="bi bi-check-circle me-1"></i> Approve Selected
        </button>
        <button type="button" class="btn btn-outline-light btn-sm" id="batchReturnBtn">
            <i class="bi bi-arrow-counterclockwise me-1"></i> Return Selected
        </button>
        <button type="button" class="btn btn-light btn-sm" id="unselectAllBtn">
            Unselect All
        </button>
    </div>
</div>


<!-- Payslip Modal -->
<div class="modal fade" id="payrollModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title">Payslip Details</h5>
                <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="border p-4" style="font-family:Courier New, monospace;">
                    <div class="text-center mb-3">
                        <h4 class="fw-bold mb-0">SariSmart</h4>
                        <h5 class="mb-3">PAYSLIP DETAILS</h5>
                    </div>
                    <table class="table table-borderless table-sm mb-3">
                        <tr><td width="180"><b>Employee</b></td><td id="employeeCode"></td></tr>
                        <tr><td><b>Name</b></td><td id="employeeName"></td></tr>
                        <tr><td><b>Department</b></td><td id="department"></td></tr>
                        <tr><td><b>Position</b></td><td id="jobTitle"></td></tr>
                        <tr><td><b>Payroll Period</b></td><td id="period"></td></tr>
                        <tr><td><b>Working Days</b></td><td id="workingDays"></td></tr>
                        <tr><td><b>Status</b></td><td><span id="status" class="badge bg-primary"></span></td></tr>
                        <tr id="returnReasonRow" style="display:none;"><td><b>Return Reason</b></td><td id="returnReason" class="text-danger"></td></tr>
                    </table>
                    <hr>
                    <h6 class="fw-bold">EARNINGS</h6>
                    <table class="table table-borderless table-sm">
                        <tr><td>Basic Pay</td><td class="text-end" id="basicPay"></td></tr>
                        <tr><td>Overtime</td><td class="text-end" id="overtimePay"></td></tr>
                        <tr class="border-top"><th>Gross Pay</th><th class="text-end" id="grossPay"></th></tr>
                    </table>
                    <hr>
                    <h6 class="fw-bold">DEDUCTIONS</h6>
                    <table class="table table-borderless table-sm">
                        <tr><td>Late</td><td class="text-end" id="late"></td></tr>
                        <tr><td>Undertime</td><td class="text-end" id="undertime"></td></tr>
                        <tr><td>Absent</td><td class="text-end" id="absent"></td></tr>
                        <tr><td>SSS</td><td class="text-end" id="sss"></td></tr>
                        <tr><td>PhilHealth</td><td class="text-end" id="philhealth"></td></tr>
                        <tr><td>Pag-IBIG</td><td class="text-end" id="pagibig"></td></tr>
                        <tr class="border-top"><th>Total Deduction</th><th class="text-end" id="deduction"></th></tr>
                    </table>
                    <hr>
                    <div class="d-flex justify-content-between align-items-center">
                        <h4 class="fw-bold mb-0">NET PAY</h4>
                        <h3 class="text-success fw-bold mb-0" id="netPay"></h3>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-success" onclick="window.print();">
                    <i class="bi bi-printer"></i> Print Payslip
                </button>
            </div>
        </div>
    </div>
</div>


<!-- Return Reason Modal -->
<div class="modal fade" id="returnReasonModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" style="color:#00224c;">Return Payroll to HR</h5>
                <button class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <label class="form-label fw-semibold">Reason for Return <span class="text-danger">*</span></label>
                <textarea id="returnReasonInput" class="form-control" rows="4" maxlength="500"
                    placeholder="Explain what needs to be corrected before this can be approved..."></textarea>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="confirmReturnBtn">
                    <i class="bi bi-arrow-counterclockwise me-1"></i> Return to HR
                </button>
            </div>
        </div>
    </div>
</div>


<script>
document.addEventListener("DOMContentLoaded", function() {

    /*
    |--------------------------------------------------------------------------
    | VIEW PAYSLIP
    |--------------------------------------------------------------------------
    */

    document.addEventListener("click", function(e) {
        var btn = e.target.closest(".viewPayroll");
        if (!btn) return;

        var payrollId = btn.dataset.payrollId;

        fetch(window.location.href, {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: "loadPayroll=1&payroll_id=" + payrollId
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.success) { alert(data.message); return; }

            var p = data.payroll;
            document.getElementById("employeeCode").innerText = p.employee_code;
            document.getElementById("employeeName").innerText = p.first_name + " " + p.last_name;
            document.getElementById("department").innerText = p.department || "-";
            document.getElementById("jobTitle").innerText = p.job_title || "-";
            document.getElementById("period").innerText =
                new Date(p.payroll_period_start).toLocaleDateString("en-US", {month:"short",day:"numeric"}) +
                " - " + new Date(p.payroll_period_end).toLocaleDateString("en-US", {month:"short",day:"numeric"});
            document.getElementById("workingDays").innerText = p.working_days;

            document.getElementById("basicPay").innerText = "₱" + Number(p.basic_pay).toLocaleString();
            document.getElementById("overtimePay").innerText = "₱" + Number(p.overtime_pay).toLocaleString();
            document.getElementById("grossPay").innerText = "₱" + Number(p.gross_pay).toLocaleString();
            document.getElementById("late").innerText = "₱" + Number(p.late_deduction).toLocaleString();
            document.getElementById("undertime").innerText = "₱" + Number(p.undertime_deduction).toLocaleString();
            document.getElementById("absent").innerText = "₱" + Number(p.absent_deduction).toLocaleString();
            document.getElementById("sss").innerText = "₱" + Number(p.sss).toLocaleString();
            document.getElementById("philhealth").innerText = "₱" + Number(p.philhealth).toLocaleString();
            document.getElementById("pagibig").innerText = "₱" + Number(p.pagibig).toLocaleString();
            document.getElementById("deduction").innerText = "₱" + Number(p.total_deduction).toLocaleString();
            document.getElementById("netPay").innerText = "₱" + Number(p.net_pay).toLocaleString();

            var returnRow = document.getElementById("returnReasonRow");
            if (p.status === "Returned" && p.edit_reason) {
                document.getElementById("returnReason").innerText = p.edit_reason;
                returnRow.style.display = "";
            } else {
                returnRow.style.display = "none";
            }

            var statusEl = document.getElementById("status");
            statusEl.innerText = p.status;
            statusEl.className = "badge";
            if (p.status == "Pending Approval") statusEl.classList.add("bg-warning","text-dark");
            else if (p.status == "Approved") statusEl.classList.add("bg-success");
            else if (p.status == "Returned") statusEl.classList.add("bg-danger");
            else if (p.status == "Released") statusEl.classList.add("bg-dark");
            else statusEl.classList.add("bg-secondary");

            new bootstrap.Modal(document.getElementById("payrollModal")).show();
        });
    });


    /*
    |--------------------------------------------------------------------------
    | DEPARTMENT ACCORDION — pure JS toggle (no Bootstrap collapse)
    |--------------------------------------------------------------------------
    */

    document.querySelectorAll(".dept-header").forEach(function(header) {
        header.addEventListener("click", function(e) {
            if (e.target.closest(".viewPayroll")) return;
            var deptId = this.getAttribute("data-dept");
            var body = document.getElementById("deptBody" + deptId);
            var isOpen = body.classList.contains("show");

            if (isOpen) {
                body.classList.remove("show");
                this.classList.remove("open");
            } else {
                body.classList.add("show");
                this.classList.add("open");
            }
        });
    });


    /*
    |--------------------------------------------------------------------------
    | DATA TABLE INIT
    |--------------------------------------------------------------------------
    */

    var statusFilter = document.getElementById("statusFilter");

    DataTable.ext.search.push(function(settings, data, dataIndex) {
        if (settings.nTable.id !== "payrollTable") return true;
        var status = statusFilter.value;
        if (status === "") return true;
        var row = settings.aoData[dataIndex].nTr;
        return row.getAttribute("data-status") === status;
    });

    var payrollDT = new DataTable("#payrollTable", {
        pageLength: 10,
        lengthChange: false,
        ordering: true,
        order: [],
        columnDefs: [{ orderable: false, targets: [0, -1] }],
        language: {
            search: "",
            searchPlaceholder: "Search payroll...",
            info: "Showing _START_ to _END_ of _TOTAL_",
            paginate: { previous: "Previous", next: "Next" },
            emptyTable: "No payroll records found",
            zeroRecords: "No matching payroll records"
        }
    });

    statusFilter.addEventListener("change", function() { payrollDT.draw(); });


    /*
    |--------------------------------------------------------------------------
    | BATCH SELECT / APPROVE / RETURN
    |--------------------------------------------------------------------------
    */

    var actionBar = document.getElementById("batchActionBar");
    var selectedCountEl = document.getElementById("selectedCount");
    var selectAllCb = document.getElementById("selectAllPayroll");

    function getCheckedIds() {
        var checked = [];
        document.querySelectorAll(".payroll-check:checked").forEach(function(cb) { checked.push(cb.value); });
        return checked;
    }

    function updateActionBar() {
        var ids = getCheckedIds();
        actionBar.style.display = ids.length > 0 ? "" : "none";
        selectedCountEl.textContent = ids.length;
        var allVisible = document.querySelectorAll(".payroll-check");
        selectAllCb.checked = allVisible.length > 0 && document.querySelectorAll(".payroll-check:checked").length === allVisible.length;
    }

    document.getElementById("payrollTable").addEventListener("change", function(e) {
        if (e.target.classList.contains("payroll-check")) updateActionBar();
    });

    selectAllCb.addEventListener("change", function() {
        var checked = this.checked;
        document.querySelectorAll(".payroll-check").forEach(function(cb) { cb.checked = checked; });
        updateActionBar();
    });

    document.getElementById("unselectAllBtn").addEventListener("click", function() {
        document.querySelectorAll(".payroll-check").forEach(function(cb) { cb.checked = false; });
        selectAllCb.checked = false;
        updateActionBar();
    });

    document.getElementById("batchApproveBtn").addEventListener("click", function() {
        var ids = getCheckedIds();
        if (ids.length === 0) return;

        Swal.fire({
            title: "Approve " + ids.length + " Payroll Record(s)?",
            text: "All selected records will be marked as Approved.",
            icon: "question",
            showCancelButton: true,
            confirmButtonText: "Yes, Approve All",
            cancelButtonText: "Cancel",
            confirmButtonColor: "#00224c",
            cancelButtonColor: "#6c757d"
        }).then(function(result) {
            if (!result.isConfirmed) return;

            fetch(window.location.href, {
                method: "POST",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                body: "batchApprovePayroll=1&payroll_ids=" + encodeURIComponent(JSON.stringify(ids))
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    Swal.fire({ icon: "success", title: "Approved", text: data.message, timer: 1500, showConfirmButton: false })
                        .then(function() { location.reload(); });
                } else {
                    Swal.fire({ icon: "error", title: "Unable to Approve", text: data.message });
                }
            })
            .catch(function() { Swal.fire({ icon: "error", title: "Error", text: "Something went wrong." }); });
        });
    });

    var returnReasonModalEl = document.getElementById("returnReasonModal");
    var returnReasonModal = new bootstrap.Modal(returnReasonModalEl);
    var returnReasonInput = document.getElementById("returnReasonInput");

    document.getElementById("batchReturnBtn").addEventListener("click", function() {
        if (getCheckedIds().length === 0) return;
        returnReasonInput.value = "";
        returnReasonModal.show();
    });

    document.getElementById("confirmReturnBtn").addEventListener("click", function() {
        var ids = getCheckedIds();
        var reason = returnReasonInput.value.trim();

        if (reason.length < 5) {
            Swal.fire({ icon: "warning", title: "Reason Required", text: "Please provide at least 5 characters.", confirmButtonColor: "#00224c" });
            return;
        }

        fetch(window.location.href, {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: "batchReturnPayroll=1&payroll_ids=" + encodeURIComponent(JSON.stringify(ids)) + "&reason=" + encodeURIComponent(reason)
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            returnReasonModal.hide();
            if (data.success) {
                Swal.fire({ icon: "success", title: "Returned to HR", text: data.message, timer: 1500, showConfirmButton: false })
                    .then(function() { location.reload(); });
            } else {
                Swal.fire({ icon: "error", title: "Unable to Return", text: data.message });
            }
        })
        .catch(function() { returnReasonModal.hide(); Swal.fire({ icon: "error", title: "Error", text: "Something went wrong." }); });
    });

});
</script>

<?php include("finance_footer.php"); ?>
