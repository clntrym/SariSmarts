<?php
require_once("../init.php");
requireRole(['hr']);

$companyId = requireCompany();


/*======================================
LOAD PAYROLL
======================================*/

if (isset($_POST['loadPayroll'])) {

    header("Content-Type: application/json");

    $employeeID = (int) $_POST['employee_id'];

    $payroll = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT
        p.*,
        e.employee_code,
        e.first_name,
        e.last_name,
        j.department,
        j.job_title
    FROM payroll p

    INNER JOIN employees ey
        ON e.employee_id=p.employee_id

    LEFT JOIN job j
        ON j.job_id=e.job_id

    WHERE p.employee_id='$employeeID'

    ORDER BY p.payroll_id DESC

    LIMIT 1
    "));

    if (!$payroll) {

        echo json_encode([
            "success" => false,
            "message" => "Payroll not found."
        ]);

        exit;
    }

    echo json_encode([
        "success" => true,
        "payroll" => $payroll
    ]);

    exit;
}


/*======================================
GENERATE PAYROLL
======================================*/

if (isset($_POST['generatePayroll'])) {

    $dateFrom = date('Y-m-01');
    $dateTo = date('Y-m-15');

    $employeesToGenerate = mysqli_query($conn, "
    SELECT
        e.employee_id,
        emp.salary
    FROM employees e
    INNER JOIN employment emp
        ON emp.employee_id=e.employee_id AND emp.company_id=e.company_id
    WHERE emp.employment_status='Official Employee'
      AND e.company_id=" . (int) $companyId . "
    ");

    while ($emp = mysqli_fetch_assoc($employeesToGenerate)) {

        $employeeID = $emp['employee_id'];
        $dailyRate = $emp['salary'];

        // Check kung meron nang payroll ngayong cutoff

        $check = mysqli_query($conn, "
        SELECT payroll_id
        FROM payroll
        WHERE employee_id='$employeeID'
        AND company_id=" . (int) $companyId . "
        AND payroll_period_start='$dateFrom'
        AND payroll_period_end='$dateTo'
        ");

        if (mysqli_num_rows($check) > 0) {
            continue;
        }

        // Attendance

        $attendance = mysqli_fetch_assoc(mysqli_query($conn, "
        SELECT

        COUNT(
        CASE
        WHEN status='Present'
        OR status='Late'
        THEN 1 END
        ) working_days,

        COUNT(
        CASE
        WHEN status='Absent'
        THEN 1 END
        ) absent_days,

        COALESCE(SUM(late_minutes),0) late_minutes,

        COALESCE(SUM(undertime_minutes),0) undertime_minutes,

        COALESCE(SUM(overtime_hours),0) overtime_hours

        FROM attendance

        WHERE employee_id='$employeeID'

        AND company_id=" . (int) $companyId . "

        AND attendance_date
        BETWEEN '$dateFrom'
        AND '$dateTo'
        "));
        $hourlyRate = $dailyRate / 8;
        $minuteRate = $hourlyRate / 60;

        $workingDays = $attendance['working_days'];
        $absentDays = $attendance['absent_days'];

        $lateMinutes = $attendance['late_minutes'];
        $undertimeMinutes = $attendance['undertime_minutes'];
        $overtimeHours = $attendance['overtime_hours'];

        $basicPay = $workingDays * $dailyRate;

        $overtimePay = $hourlyRate * $overtimeHours * 1.25;

        $grossPay = $basicPay + $overtimePay;

        $lateDeduction = $lateMinutes * $minuteRate;

        $undertimeDeduction = $undertimeMinutes * $minuteRate;

        $absentDeduction = $absentDays * $dailyRate;

        $sss = $grossPay * .045;

        $philhealth = $grossPay * .025;

        $pagibig = $grossPay * .02;

        if ($pagibig > 100) {
            $pagibig = 100;
        }

        $totalDeduction =

            $lateDeduction +

            $undertimeDeduction +

            $absentDeduction +

            $sss +

            $philhealth +

            $pagibig;

        $netPay = $grossPay - $totalDeduction;

        mysqli_query($conn, "
        INSERT INTO payroll(

        company_id,

        employee_id,

        payroll_period_start,

        payroll_period_end,

        working_days,

        basic_pay,

        overtime_pay,

        gross_pay,

        late_deduction,

        undertime_deduction,

        absent_deduction,

        sss,

        philhealth,

        pagibig,

        total_deduction,

        net_pay,

        status

        )

        VALUES(

        " . (int) $companyId . ",

        '$employeeID',

        '$dateFrom',

        '$dateTo',

        '$workingDays',

        '$basicPay',

        '$overtimePay',

        '$grossPay',

        '$lateDeduction',

        '$undertimeDeduction',

        '$absentDeduction',

        '$sss',

        '$philhealth',

        '$pagibig',

        '$totalDeduction',

        '$netPay',

        'Draft'

        )
        ");
    }

    echo "<script>

    alert('Payroll generated successfully.');

    window.location='payroll.php';

    </script>";

    exit;
}


/*======================================
SUBMIT ALL DRAFT PAYROLL FOR APPROVAL
======================================*/

if (isset($_POST['submitAllPayroll'])) {

    header("Content-Type: application/json");

    $dateFrom = date('Y-m-01');
    $dateTo = date('Y-m-15');

    $stmt = $conn->prepare("
        UPDATE payroll
        SET status = 'Pending Approval'
        WHERE payroll_period_start = ?
          AND payroll_period_end = ?
          AND company_id = ?
          AND status = 'Draft'
    ");

    $stmt->bind_param("ssi", $dateFrom, $dateTo, $companyId);
    $stmt->execute();

    $submittedCount = $stmt->affected_rows;

    $stmt->close();

    if ($submittedCount > 0) {

        echo json_encode([
            "success" => true,
            "message" => $submittedCount . " payroll record(s) submitted for approval."
        ]);

    } else {

        echo json_encode([
            "success" => false,
            "message" => "There are no draft payroll records to submit for the current cutoff."
        ]);
    }

    exit;
}


/*======================================
APPROVE PAYROLL
======================================*/

if (isset($_POST['approvePayroll'])) {

    header("Content-Type: application/json");

    $payrollID = (int) ($_POST['payroll_id'] ?? 0);

    $approverID = (int) (
        $_SESSION['user_id']
        ?? $_SESSION['id']
        ?? 0
    );

    $approverID = $approverID > 0 ? $approverID : null;

    if ($payrollID <= 0) {

        echo json_encode([
            "success" => false,
            "message" => "Invalid payroll record."
        ]);

        exit;
    }

    $stmt = $conn->prepare("
        UPDATE payroll
        SET
            status = 'Approved',
            approved_by = ?
        WHERE payroll_id = ?
          AND company_id = ?
          AND status = 'Pending Approval'
    ");

    $stmt->bind_param("iii", $approverID, $payrollID, $companyId);
    $stmt->execute();

    if ($stmt->affected_rows > 0) {

        echo json_encode([
            "success" => true,
            "message" => "Payroll approved successfully."
        ]);

    } else {

        echo json_encode([
            "success" => false,
            "message" => "This payroll is no longer pending approval."
        ]);
    }

    $stmt->close();

    exit;
}


/*======================================
UPDATE PAYROLL
======================================*/

if (isset($_POST['updatePayroll'])) {

    header("Content-Type: application/json");

    $payrollID = (int) $_POST['payroll_id'];


    $basic = $_POST['basic_pay'];
    $overtime = $_POST['overtime_pay'];
    $gross = $_POST['gross_pay'];

    $late = $_POST['late'];
    $undertime = $_POST['undertime'];
    $absent = $_POST['absent'];

    $sss = $_POST['sss'];
    $philhealth = $_POST['philhealth'];
    $pagibig = $_POST['pagibig'];

    $total = $_POST['total_deduction'];
    $net = $_POST['net_pay'];

    $reason = mysqli_real_escape_string($conn, $_POST['reason']);

    $update = mysqli_query($conn, "
    UPDATE payroll SET

    basic_pay='$basic',
    overtime_pay='$overtime',
    gross_pay='$gross',

    late_deduction='$late',
    undertime_deduction='$undertime',
    absent_deduction='$absent',

    sss='$sss',
    philhealth='$philhealth',
    pagibig='$pagibig',

    total_deduction='$total',
    net_pay='$net'

    WHERE payroll_id='$payrollID'
    AND company_id=" . (int) $companyId . "
    ");

    if ($update) {

        echo json_encode([
            "success" => true,
            "message" => "Payroll updated successfully."
        ]);
    } else {

        echo json_encode([
            "success" => false,
            "message" => mysqli_error($conn)
        ]);
    }

    exit;
}

include("hr_header.php");


/*======================================
LOAD EMPLOYEES + LATEST PAYROLL
(Branch dropped entirely — no longer needed)
======================================*/

$employees = mysqli_query($conn, "
SELECT

e.employee_id,
e.employee_code,
e.first_name,
e.last_name,

j.department,
j.job_title,

emp.salary,

p.payroll_id,
p.payroll_period_start,
p.payroll_period_end,
p.basic_pay,
p.overtime_pay,
p.gross_pay,
p.late_deduction,
p.undertime_deduction,
p.absent_deduction,
p.sss,
p.philhealth,
p.pagibig,
p.total_deduction,
p.net_pay,
p.status

FROM employees e

INNER JOIN employment emp
ON emp.employee_id=e.employee_id AND emp.company_id=e.company_id

LEFT JOIN job j
ON j.job_id=e.job_id AND j.company_id=e.company_id

LEFT JOIN payroll p
ON p.payroll_id=(

SELECT payroll_id

FROM payroll

WHERE employee_id=e.employee_id AND company_id=e.company_id

ORDER BY payroll_id DESC

LIMIT 1

)

WHERE emp.employment_status='Official Employee'

AND e.company_id=" . (int) $companyId . "

ORDER BY e.first_name, e.last_name
");

$payrollRows = [];

while ($row = mysqli_fetch_assoc($employees)) {
    $payrollRows[] = $row;
}

?>


<style>
    body {
        background: #f4f7fb;
    }

    .payroll-card {
        border: none;
        border-radius: 18px;
        box-shadow: 0 5px 18px rgba(0, 0, 0, .08);
    }

    .table thead {
        background: #00224c;
        color: #fff;
    }

    .table th {
        padding: 15px;
        font-weight: 600;
        white-space: nowrap;
    }

    .table td {
        padding: 15px;
        vertical-align: middle;
        white-space: nowrap;
    }

    .search-box {
        border-radius: 12px;
        height: 45px;
    }

    .btn-main {
        background: #00224c;
        color: #fff;
        border: none;
    }

    .btn-main:hover {
        background: #01356f;
        color: white;
    }

    .badge-draft {
        background: #e5e7eb;
        color: #374151;
    }

    .page-title {
        color: #00224c;
        font-weight: 700;
    }

    .employee-name {
        font-weight: 600;
        color: #00224c;
    }

    .employee-code {
        font-size: 12px;
        color: #718096;
    }

    .amount-deduction {
        color: #dc3545;
    }

    #payrollModal .modal-body {
        background: #f5f5f5;
    }

    #payrollModal .border {
        background: #fff;
        border: 2px solid #222 !important;
    }

    #payrollModal table td,
    #payrollModal table th {
        padding: 4px 0;
    }

    @media print {

        body * {
            visibility: hidden;
        }

        #payrollModal,
        #payrollModal * {
            visibility: visible;
        }

        #payrollModal {
            position: absolute;
            left: 0;
            top: 0;
            width: 100%;
        }

        .modal-header,
        .modal-footer {
            display: none;
        }

        .modal-content {
            border: none;
            box-shadow: none;
        }
    }
</style>

<div class="container-fluid py-4">

    <div class="d-flex justify-content-between align-items-center mb-4">

        <div class="d-flex justify-content-between align-items-center mb-2">
            <div>
                <h1 class=" mb-0 fw-bold" style="color: #00224c;">
                    Payroll Management
                </h1>
                <p class="text-muted mb-0">
                    Manage employee payroll records.
                </p>
            </div>
        </div>

        <div>

            <form method="POST" class="d-inline">

                <button class="btn btn-success" name="generatePayroll">

                    <i class="bi bi-calculator"></i>

                    Generate Payroll

                </button>

            </form>

            <button class="btn btn-main" id="submitAll">
                <i class="bi bi-send"></i>
                Submit All
            </button>

        </div>

    </div>

    <div class="card payroll-card mb-4">

        <div class="card-body">

            <div class="row g-3">

                <div class="col-md-6">

                    <input type="text" class="form-control search-box" id="payrollSearch"
                        placeholder="Search employee code or name...">

                </div>

                <div class="col-md-3">

                    <select class="form-select search-box" id="statusFilter">

                        <option value="">All Status</option>
                        <option value="Not Generated">Not Generated</option>
                        <option value="Draft">Draft</option>
                        <option value="Pending Approval">Pending Approval</option>
                        <option value="Approved">Approved</option>
                        <option value="Returned">Returned</option>
                        <option value="Released">Released</option>

                    </select>

                </div>

            </div>

        </div>

    </div>

    <div class="card payroll-card">

        <div class="card-body p-0">

            <div class="table-responsive">

                <table id="payrollTable" class="table table-hover mb-0" style="width:100%">

                    <thead>

                        <tr>

                            <th>Employee</th>
                            <th>Department</th>
                            <th>Position</th>
                            <th>Period</th>
                            <th class="text-end">Basic Pay</th>
                            <th class="text-end">Overtime</th>
                            <th class="text-end">Gross Pay</th>
                            <th class="text-end">Late</th>
                            <th class="text-end">Undertime</th>
                            <th class="text-end">Absent</th>
                            <th class="text-end">SSS</th>
                            <th class="text-end">PhilHealth</th>
                            <th class="text-end">Pag-IBIG</th>
                            <th class="text-end">Total Ded.</th>
                            <th class="text-end">Net Pay</th>
                            <th>Status</th>
                            <th width="170">Actions</th>

                        </tr>

                    </thead>

                    <tbody id="payrollTableBody">

                        <?php foreach ($payrollRows as $row): ?>

                            <?php

                            $hasPayroll = !empty($row['payroll_id']);

                            $status = $hasPayroll
                                ? ($row['status'] ?? 'Draft')
                                : 'Not Generated';

                            $period = $hasPayroll
                                ? date('M d', strtotime($row['payroll_period_start']))
                                . ' – '
                                . date('M d, Y', strtotime($row['payroll_period_end']))
                                : '-';

                            $searchText = strtolower(
                                $row['employee_code']
                                . ' '
                                . $row['first_name']
                                . ' '
                                . $row['last_name']
                                . ' '
                                . ($row['department'] ?? '')
                                . ' '
                                . ($row['job_title'] ?? '')
                            );

                            ?>

                            <tr class="payroll-row" data-search="<?= htmlspecialchars($searchText, ENT_QUOTES) ?>"
                                data-status="<?= htmlspecialchars($status, ENT_QUOTES) ?>">

                                <!-- EMPLOYEE -->
                                <td>
                                    <div class="employee-name">
                                        <?= htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) ?>
                                    </div>
                                    <div class="employee-code">
                                        <?= htmlspecialchars($row['employee_code']) ?>
                                    </div>
                                </td>

                                <!-- DEPARTMENT -->
                                <td><?= htmlspecialchars($row['department'] ?? '-') ?></td>

                                <!-- POSITION -->
                                <td><?= htmlspecialchars($row['job_title'] ?? '-') ?></td>

                                <!-- PERIOD -->
                                <td><?= htmlspecialchars($period) ?></td>

                                <!-- BASIC PAY -->
                                <td class="text-end">
                                    <?= $hasPayroll ? '₱' . number_format((float) $row['basic_pay'], 2) : '-' ?>
                                </td>

                                <!-- OVERTIME -->
                                <td class="text-end">
                                    <?= $hasPayroll ? '₱' . number_format((float) $row['overtime_pay'], 2) : '-' ?>
                                </td>

                                <!-- GROSS PAY -->
                                <td class="text-end fw-semibold">
                                    <?= $hasPayroll ? '₱' . number_format((float) $row['gross_pay'], 2) : '-' ?>
                                </td>

                                <!-- LATE -->
                                <td class="text-end amount-deduction">
                                    <?= $hasPayroll ? '₱' . number_format((float) $row['late_deduction'], 2) : '-' ?>
                                </td>

                                <!-- UNDERTIME -->
                                <td class="text-end amount-deduction">
                                    <?= $hasPayroll ? '₱' . number_format((float) $row['undertime_deduction'], 2) : '-' ?>
                                </td>

                                <!-- ABSENT -->
                                <td class="text-end amount-deduction">
                                    <?= $hasPayroll ? '₱' . number_format((float) $row['absent_deduction'], 2) : '-' ?>
                                </td>

                                <!-- SSS -->
                                <td class="text-end">
                                    <?= $hasPayroll ? '₱' . number_format((float) $row['sss'], 2) : '-' ?>
                                </td>

                                <!-- PHILHEALTH -->
                                <td class="text-end">
                                    <?= $hasPayroll ? '₱' . number_format((float) $row['philhealth'], 2) : '-' ?>
                                </td>

                                <!-- PAG-IBIG -->
                                <td class="text-end">
                                    <?= $hasPayroll ? '₱' . number_format((float) $row['pagibig'], 2) : '-' ?>
                                </td>

                                <!-- TOTAL DEDUCTION -->
                                <td class="text-end amount-deduction fw-semibold">
                                    <?= $hasPayroll ? '₱' . number_format((float) $row['total_deduction'], 2) : '-' ?>
                                </td>

                                <!-- NET PAY -->
                                <td class="text-end fw-bold" style="color:#00224c;">
                                    <?= $hasPayroll ? '₱' . number_format((float) $row['net_pay'], 2) : '-' ?>
                                </td>

                                <!-- STATUS -->
                                <td>
                                    <?php
                                    $badgeClass = match ($status) {
                                        'Draft' => 'bg-primary',
                                        'Pending Approval' => 'bg-warning text-dark',
                                        'Approved' => 'bg-success',
                                        'Returned' => 'bg-danger',
                                        'Released' => 'bg-dark',
                                        default => 'bg-secondary'
                                    };
                                    ?>
                                    <span class="badge <?= $badgeClass ?>">
                                        <?= htmlspecialchars($status) ?>
                                    </span>
                                </td>

                                <!-- ACTIONS -->
                                <td>

                                    <?php if (!$hasPayroll): ?>

                                        <button class="btn btn-secondary btn-sm" disabled>
                                            No Payroll
                                        </button>

                                    <?php else: ?>

                                        <button class="btn btn-primary btn-sm viewPayroll"
                                            data-id="<?= (int) $row['employee_id'] ?>">
                                            View
                                        </button>

                                        <?php if (in_array($status, ['Draft', 'Pending Approval', 'Returned'])): ?>

                                            <button class="btn btn-warning btn-sm editPayroll"
                                                data-id="<?= (int) $row['employee_id'] ?>"
                                                data-payroll-id="<?= (int) $row['payroll_id'] ?>">
                                                Edit
                                            </button>

                                        <?php endif; ?>

                                        <?php if ($status === 'Pending Approval'): ?>

                                            <button class="btn btn-success btn-sm approvePayroll"
                                                data-payroll-id="<?= (int) $row['payroll_id'] ?>">
                                                Approve
                                            </button>

                                        <?php endif; ?>

                                    <?php endif; ?>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                        <?php if (empty($payrollRows)): ?>

                            <tr>
                                <td colspan="17" class="text-center text-muted py-5">
                                    No employees found.
                                </td>
                            </tr>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>


            <!-- =====================================================
                 FOOTER / PAGINATION
            ====================================================== -->

            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 px-3 py-3 border-top">

                <div id="payrollTableInfo" class="text-muted small">
                    Showing 0 of 0
                </div>

                <div class="d-flex align-items-center gap-2">

                    <button type="button" id="payrollPrev" class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-chevron-left"></i>
                    </button>

                    <span id="payrollPageInfo" class="text-muted small" style="min-width:80px; text-align:center;">
                        Page 1 of 1
                    </span>

                    <button type="button" id="payrollNext" class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-chevron-right"></i>
                    </button>

                </div>

            </div>

        </div>

    </div>

</div>

<!-- Payslip Modal -->
<div class="modal fade" id="payrollModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">

            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title">
                    Payslip Details
                </h5>
                <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body">

                <div class="border p-4" style="font-family:Courier New, monospace;">

                    <div class="text-center mb-3">
                        <h4 class="fw-bold mb-0">SariSmart</h4>
                        <h5 class="mb-3">PAYSLIP DETAILS</h5>
                    </div>

                    <table class="table table-borderless table-sm mb-3">
                        <tr>
                            <td width="180"><b>Employee</b></td>
                            <td id="employeeCode"></td>
                        </tr>

                        <tr>
                            <td><b>Name</b></td>
                            <td id="employeeName"></td>
                        </tr>

                        <tr>
                            <td><b>Department</b></td>
                            <td id="department"></td>
                        </tr>

                        <tr>
                            <td><b>Position</b></td>
                            <td id="jobTitle"></td>
                        </tr>

                        <tr>
                            <td><b>Payroll Period</b></td>
                            <td id="period"></td>
                        </tr>

                        <tr>
                            <td><b>Working Days</b></td>
                            <td id="workingDays"></td>
                        </tr>
                        <tr>
                            <td><b>Status</b></td>
                            <td>
                                <span id="status" class="badge bg-primary"></span>
                            </td>
                        </tr>
                    </table>

                    <hr>

                    <h6 class="fw-bold">EARNINGS</h6>

                    <table class="table table-borderless table-sm">

                        <tr>
                            <td>Basic Pay</td>
                            <td class="text-end" id="basicPay"></td>
                        </tr>

                        <tr>
                            <td>Overtime</td>
                            <td class="text-end" id="overtimePay"></td>
                        </tr>

                        <tr class="border-top">
                            <th>Gross Pay</th>
                            <th class="text-end" id="grossPay"></th>
                        </tr>

                    </table>

                    <hr>

                    <h6 class="fw-bold">DEDUCTIONS</h6>

                    <table class="table table-borderless table-sm">

                        <tr>
                            <td>Late</td>
                            <td class="text-end" id="late"></td>
                        </tr>

                        <tr>
                            <td>Undertime</td>
                            <td class="text-end" id="undertime"></td>
                        </tr>

                        <tr>
                            <td>Absent</td>
                            <td class="text-end" id="absent"></td>
                        </tr>

                        <tr>
                            <td>SSS</td>
                            <td class="text-end" id="sss"></td>
                        </tr>

                        <tr>
                            <td>PhilHealth</td>
                            <td class="text-end" id="philhealth"></td>
                        </tr>

                        <tr>
                            <td>Pag-IBIG</td>
                            <td class="text-end" id="pagibig"></td>
                        </tr>

                        <tr class="border-top">
                            <th>Total Deduction</th>
                            <th class="text-end" id="deduction"></th>
                        </tr>

                    </table>

                    <hr>

                    <div class="d-flex justify-content-between align-items-center">

                        <h4 class="fw-bold mb-0">
                            NET PAY
                        </h4>

                        <h3 class="text-success fw-bold mb-0" id="netPay">
                        </h3>

                    </div>

                </div>

            </div>

            <div class="modal-footer">

                <button class="btn btn-success" onclick="window.print();">

                    <i class="bi bi-printer"></i>

                    Print Payslip

                </button>

            </div>

        </div>
    </div>
</div>

<!-- Edit Payroll Modal -->
<div class="modal fade" id="editPayrollModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">

            <div class="modal-header bg-warning">
                <h5 class="modal-title fw-bold">
                    <i class="bi bi-pencil-square me-2"></i>Edit Payroll
                </h5>
                <button class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body">
                <input type="hidden" id="editPayrollId">

                <div class="mb-3 p-3 bg-light rounded">
                    <div class="row">
                        <div class="col-md-6">
                            <small class="text-muted">Employee</small>
                            <div class="fw-bold" id="editEmployeeName"></div>
                        </div>
                        <div class="col-md-3">
                            <small class="text-muted">Period</small>
                            <div class="fw-bold" id="editPeriod"></div>
                        </div>
                        <div class="col-md-3">
                            <small class="text-muted">Status</small>
                            <div><span id="editStatus" class="badge"></span></div>
                        </div>
                    </div>
                </div>

                <h6 class="fw-bold text-success mt-3">Earnings</h6>
                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Basic Pay</label>
                        <input type="number" step="0.01" class="form-control" id="editBasicPay">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Overtime Pay</label>
                        <input type="number" step="0.01" class="form-control" id="editOvertimePay">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Gross Pay</label>
                        <input type="number" step="0.01" class="form-control bg-light" id="editGrossPay" readonly>
                    </div>
                </div>

                <h6 class="fw-bold text-danger">Deductions</h6>
                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Late</label>
                        <input type="number" step="0.01" class="form-control" id="editLate">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Undertime</label>
                        <input type="number" step="0.01" class="form-control" id="editUndertime">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Absent</label>
                        <input type="number" step="0.01" class="form-control" id="editAbsent">
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">SSS</label>
                        <input type="number" step="0.01" class="form-control" id="editSSS">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">PhilHealth</label>
                        <input type="number" step="0.01" class="form-control" id="editPhilHealth">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Pag-IBIG</label>
                        <input type="number" step="0.01" class="form-control" id="editPagibig">
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Total Deduction</label>
                        <input type="number" step="0.01" class="form-control bg-light" id="editTotalDeduction" readonly>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Net Pay</label>
                        <input type="number" step="0.01" class="form-control bg-light fw-bold" id="editNetPay" readonly>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-semibold">Reason for Edit</label>
                    <textarea class="form-control" id="editReason" rows="2"
                        placeholder="Why is this payroll being edited?"></textarea>
                </div>
            </div>

            <div class="modal-footer">
                <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-warning fw-bold" id="saveEditPayroll">
                    <i class="bi bi-check-lg me-1"></i>Save Changes
                </button>
            </div>

        </div>
    </div>
</div>

<script>

    document.addEventListener("DOMContentLoaded", function () {


        /*
        |--------------------------------------------------------------------------
        | DATATABLE
        |--------------------------------------------------------------------------
        */

        if (typeof DataTable !== "undefined") {
            new DataTable("#payrollTable", {
                pageLength: 10,
                lengthChange: false,
                ordering: true,
                order: [],
                scrollX: true,
                columnDefs: [{ orderable: false, targets: 16 }],
                language: {
                    search: "",
                    searchPlaceholder: "Search employee, department, status...",
                    info: "Showing _START_ to _END_ of _TOTAL_",
                    infoEmpty: "No records",
                    zeroRecords: "No matching payroll records",
                    emptyTable: "No payroll records",
                    paginate: { previous: "Previous", next: "Next" }
                }
            });
        }


        /*
        |--------------------------------------------------------------------------
        | EDIT PAYROLL — auto-calculate totals
        |--------------------------------------------------------------------------
        */

        function recalcEditPayroll() {
            var basic = parseFloat(document.getElementById("editBasicPay").value) || 0;
            var overtime = parseFloat(document.getElementById("editOvertimePay").value) || 0;
            var gross = basic + overtime;
            document.getElementById("editGrossPay").value = gross.toFixed(2);

            var late = parseFloat(document.getElementById("editLate").value) || 0;
            var undertime = parseFloat(document.getElementById("editUndertime").value) || 0;
            var absent = parseFloat(document.getElementById("editAbsent").value) || 0;
            var sss = parseFloat(document.getElementById("editSSS").value) || 0;
            var phil = parseFloat(document.getElementById("editPhilHealth").value) || 0;
            var pagibig = parseFloat(document.getElementById("editPagibig").value) || 0;

            var totalDed = late + undertime + absent + sss + phil + pagibig;
            document.getElementById("editTotalDeduction").value = totalDed.toFixed(2);
            document.getElementById("editNetPay").value = (gross - totalDed).toFixed(2);
        }

        document.querySelectorAll("#editBasicPay,#editOvertimePay,#editLate,#editUndertime,#editAbsent,#editSSS,#editPhilHealth,#editPagibig")
            .forEach(function (input) {
                input.addEventListener("input", recalcEditPayroll);
            });


        /*
        |--------------------------------------------------------------------------
        | EDIT PAYROLL — load data into modal
        |--------------------------------------------------------------------------
        */

        document.querySelectorAll(".editPayroll").forEach(function (btn) {
            btn.addEventListener("click", function () {
                var employeeID = this.dataset.id;
                var payrollID = this.dataset.payrollId;

                fetch(window.location.href, {
                    method: "POST",
                    headers: { "Content-Type": "application/x-www-form-urlencoded" },
                    body: "loadPayroll=1&employee_id=" + employeeID
                })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (!data.success) {
                            Swal.fire("Error", data.message, "error");
                            return;
                        }

                        var p = data.payroll;

                        document.getElementById("editPayrollId").value = p.payroll_id;
                        document.getElementById("editEmployeeName").textContent = p.first_name + " " + p.last_name + " (" + p.employee_code + ")";
                        document.getElementById("editPeriod").textContent =
                            new Date(p.payroll_period_start).toLocaleDateString("en-US", { month: "short", day: "numeric" }) +
                            " - " +
                            new Date(p.payroll_period_end).toLocaleDateString("en-US", { month: "short", day: "numeric" });

                        var statusEl = document.getElementById("editStatus");
                        statusEl.textContent = p.status;
                        statusEl.className = "badge " + (
                            p.status === "Draft" ? "bg-primary" :
                                p.status === "Pending Approval" ? "bg-warning text-dark" :
                                    p.status === "Returned" ? "bg-danger" : "bg-secondary"
                        );

                        document.getElementById("editBasicPay").value = parseFloat(p.basic_pay).toFixed(2);
                        document.getElementById("editOvertimePay").value = parseFloat(p.overtime_pay).toFixed(2);
                        document.getElementById("editGrossPay").value = parseFloat(p.gross_pay).toFixed(2);
                        document.getElementById("editLate").value = parseFloat(p.late_deduction).toFixed(2);
                        document.getElementById("editUndertime").value = parseFloat(p.undertime_deduction).toFixed(2);
                        document.getElementById("editAbsent").value = parseFloat(p.absent_deduction).toFixed(2);
                        document.getElementById("editSSS").value = parseFloat(p.sss).toFixed(2);
                        document.getElementById("editPhilHealth").value = parseFloat(p.philhealth).toFixed(2);
                        document.getElementById("editPagibig").value = parseFloat(p.pagibig).toFixed(2);
                        document.getElementById("editTotalDeduction").value = parseFloat(p.total_deduction).toFixed(2);
                        document.getElementById("editNetPay").value = parseFloat(p.net_pay).toFixed(2);
                        document.getElementById("editReason").value = "";

                        new bootstrap.Modal(document.getElementById("editPayrollModal")).show();
                    });
            });
        });


        /*
        |--------------------------------------------------------------------------
        | SAVE EDIT PAYROLL
        |--------------------------------------------------------------------------
        */

        document.getElementById("saveEditPayroll").addEventListener("click", function () {
            var reason = document.getElementById("editReason").value.trim();

            if (!reason || /^\s+$/.test(reason)) {
                Swal.fire("Required", "Please provide a reason for editing (cannot be empty or spaces only).", "warning");
                return;
            }

            if (/^[^a-zA-Z0-9\s]+$/.test(reason)) {
                Swal.fire("Invalid", "Reason cannot contain only special characters.", "warning");
                return;
            }

            var numFields = ["editBasicPay", "editOvertimePay", "editLate", "editUndertime", "editAbsent", "editSSS", "editPhilHealth", "editPagibig"];
            for (var i = 0; i < numFields.length; i++) {
                var val = document.getElementById(numFields[i]).value;
                if (val === "" || isNaN(parseFloat(val)) || parseFloat(val) < 0) {
                    Swal.fire("Invalid", "All payroll amounts must be valid non-negative numbers.", "warning");
                    return;
                }
            }

            var body =
                "updatePayroll=1" +
                "&payroll_id=" + encodeURIComponent(document.getElementById("editPayrollId").value) +
                "&basic_pay=" + encodeURIComponent(document.getElementById("editBasicPay").value) +
                "&overtime_pay=" + encodeURIComponent(document.getElementById("editOvertimePay").value) +
                "&gross_pay=" + encodeURIComponent(document.getElementById("editGrossPay").value) +
                "&late=" + encodeURIComponent(document.getElementById("editLate").value) +
                "&undertime=" + encodeURIComponent(document.getElementById("editUndertime").value) +
                "&absent=" + encodeURIComponent(document.getElementById("editAbsent").value) +
                "&sss=" + encodeURIComponent(document.getElementById("editSSS").value) +
                "&philhealth=" + encodeURIComponent(document.getElementById("editPhilHealth").value) +
                "&pagibig=" + encodeURIComponent(document.getElementById("editPagibig").value) +
                "&total_deduction=" + encodeURIComponent(document.getElementById("editTotalDeduction").value) +
                "&net_pay=" + encodeURIComponent(document.getElementById("editNetPay").value) +
                "&reason=" + encodeURIComponent(reason);

            fetch(window.location.href, {
                method: "POST",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                body: body
            })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.success) {
                        bootstrap.Modal.getInstance(document.getElementById("editPayrollModal")).hide();
                        Swal.fire({ icon: "success", title: "Saved", text: data.message, timer: 1500, showConfirmButton: false })
                            .then(function () { location.reload(); });
                    } else {
                        Swal.fire("Error", data.message, "error");
                    }
                })
                .catch(function () {
                    Swal.fire("Error", "Something went wrong.", "error");
                });
        });


        /*
        |--------------------------------------------------------------------------
        | VIEW PAYSLIP
        |--------------------------------------------------------------------------
        */

        document.querySelectorAll(".viewPayroll").forEach(function (btn) {

            btn.addEventListener("click", function () {

                let employeeID = this.dataset.id;

                fetch(window.location.href, {

                    method: "POST",

                    headers: {
                        "Content-Type": "application/x-www-form-urlencoded"
                    },

                    body: "loadPayroll=1&employee_id=" + employeeID

                })

                    .then(response => response.json())

                    .then(function (data) {

                        if (!data.success) {

                            alert(data.message);

                            return;

                        }

                        let p = data.payroll;

                        document.getElementById("employeeCode").innerText = p.employee_code;

                        document.getElementById("employeeName").innerText =
                            p.first_name + " " + p.last_name;

                        document.getElementById("department").innerText = p.department || "-";

                        document.getElementById("jobTitle").innerText = p.job_title || "-";

                        document.getElementById("period").innerText =
                            new Date(p.payroll_period_start).toLocaleDateString("en-US", {
                                month: "short",
                                day: "numeric"
                            }) +
                            " - " +
                            new Date(p.payroll_period_end).toLocaleDateString("en-US", {
                                month: "short",
                                day: "numeric"
                            });

                        document.getElementById("workingDays").innerText =
                            p.working_days;

                        document.getElementById("basicPay").innerText =
                            "₱" + Number(p.basic_pay).toLocaleString();

                        document.getElementById("overtimePay").innerText =
                            "₱" + Number(p.overtime_pay).toLocaleString();

                        document.getElementById("grossPay").innerText =
                            "₱" + Number(p.gross_pay).toLocaleString();

                        document.getElementById("late").innerText =
                            "₱" + Number(p.late_deduction).toLocaleString();

                        document.getElementById("undertime").innerText =
                            "₱" + Number(p.undertime_deduction).toLocaleString();

                        document.getElementById("absent").innerText =
                            "₱" + Number(p.absent_deduction).toLocaleString();

                        document.getElementById("sss").innerText =
                            "₱" + Number(p.sss).toLocaleString();

                        document.getElementById("philhealth").innerText =
                            "₱" + Number(p.philhealth).toLocaleString();

                        document.getElementById("pagibig").innerText =
                            "₱" + Number(p.pagibig).toLocaleString();

                        document.getElementById("deduction").innerText =
                            "₱" + Number(p.total_deduction).toLocaleString();

                        document.getElementById("netPay").innerText =
                            "₱" + Number(p.net_pay).toLocaleString();

                        let status = document.getElementById("status");

                        status.innerText = p.status;

                        status.className = "badge";

                        if (p.status == "Draft") {
                            status.classList.add("bg-primary");
                        } else if (p.status == "Pending Approval") {
                            status.classList.add("bg-warning", "text-dark");
                        } else if (p.status == "Approved") {
                            status.classList.add("bg-success");
                        } else if (p.status == "Returned") {
                            status.classList.add("bg-danger");
                        } else if (p.status == "Released") {
                            status.classList.add("bg-dark");
                        } else {
                            status.classList.add("bg-secondary");
                        }

                        let modal = new bootstrap.Modal(
                            document.getElementById("payrollModal")
                        );

                        modal.show();

                    });

            });

        });


        /*
        |--------------------------------------------------------------------------
        | APPROVE PAYROLL
        |--------------------------------------------------------------------------
        */

        document.querySelectorAll(".approvePayroll").forEach(function (btn) {

            btn.addEventListener("click", function () {

                const payrollId = this.dataset.payrollId;

                Swal.fire({
                    title: "Approve Payroll?",
                    text: "This payroll record will be marked as Approved.",
                    icon: "question",
                    showCancelButton: true,
                    confirmButtonText: "Yes, Approve",
                    cancelButtonText: "Cancel",
                    confirmButtonColor: "#00224c",
                    cancelButtonColor: "#6c757d"
                }).then(function (result) {

                    if (!result.isConfirmed) {
                        return;
                    }

                    fetch(window.location.href, {
                        method: "POST",
                        headers: {
                            "Content-Type": "application/x-www-form-urlencoded"
                        },
                        body: "approvePayroll=1&payroll_id=" + encodeURIComponent(payrollId)
                    })
                        .then(response => response.json())
                        .then(function (data) {

                            if (data.success) {

                                Swal.fire({
                                    icon: "success",
                                    title: "Approved",
                                    text: data.message,
                                    timer: 1500,
                                    showConfirmButton: false
                                }).then(function () {
                                    location.reload();
                                });

                            } else {

                                Swal.fire({
                                    icon: "error",
                                    title: "Unable to Approve",
                                    text: data.message
                                });

                            }

                        })
                        .catch(function (error) {
                            console.error(error);
                            Swal.fire({
                                icon: "error",
                                title: "Error",
                                text: "Something went wrong while approving the payroll."
                            });
                        });

                });

            });

        });


        /*
        |--------------------------------------------------------------------------
        | SUBMIT ALL DRAFT PAYROLL
        |--------------------------------------------------------------------------
        */

        document.getElementById("submitAll").addEventListener("click", function () {

            Swal.fire({
                title: "Submit All Draft Payroll?",
                text: "All draft payroll records for the current cutoff will be submitted for approval.",
                icon: "question",
                showCancelButton: true,
                confirmButtonText: "Yes, Submit All",
                cancelButtonText: "Cancel",
                confirmButtonColor: "#00224c",
                cancelButtonColor: "#6c757d"
            }).then(function (result) {

                if (!result.isConfirmed) {
                    return;
                }

                fetch(window.location.href, {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/x-www-form-urlencoded"
                    },
                    body: "submitAllPayroll=1"
                })
                    .then(response => response.json())
                    .then(function (data) {

                        if (data.success) {

                            Swal.fire({
                                icon: "success",
                                title: "Submitted",
                                text: data.message,
                                timer: 1500,
                                showConfirmButton: false
                            }).then(function () {
                                location.reload();
                            });

                        } else {

                            Swal.fire({
                                icon: "warning",
                                title: "Nothing to Submit",
                                text: data.message
                            });

                        }

                    })
                    .catch(function (error) {
                        console.error(error);
                        Swal.fire({
                            icon: "error",
                            title: "Error",
                            text: "Something went wrong while submitting payroll."
                        });
                    });

            });

        });


        /*
        |--------------------------------------------------------------------------
        | DATA TABLE — SEARCH / FILTER / PAGINATION
        |--------------------------------------------------------------------------
        */

        const searchInput = document.getElementById("payrollSearch");
        const statusFilter = document.getElementById("statusFilter");
        const tableBody = document.getElementById("payrollTableBody");
        const rows = Array.from(document.querySelectorAll(".payroll-row"));

        const info = document.getElementById("payrollTableInfo");
        const pageInfo = document.getElementById("payrollPageInfo");
        const previousButton = document.getElementById("payrollPrev");
        const nextButton = document.getElementById("payrollNext");

        let currentPage = 1;
        const rowsPerPage = 10;


        function getFilteredRows() {

            const search = searchInput.value.toLowerCase().trim();
            const status = statusFilter.value;

            return rows.filter(function (row) {

                const rowSearch = row.dataset.search.toLowerCase();
                const rowStatus = row.dataset.status;

                const matchesSearch = search === "" || rowSearch.includes(search);
                const matchesStatus = status === "" || rowStatus === status;

                return matchesSearch && matchesStatus;

            });

        }


        function renderTable() {

            const filteredRows = getFilteredRows();
            const totalRows = filteredRows.length;

            const totalPages = Math.max(1, Math.ceil(totalRows / rowsPerPage));

            if (currentPage > totalPages) {
                currentPage = totalPages;
            }

            const start = (currentPage - 1) * rowsPerPage;
            const end = Math.min(start + rowsPerPage, totalRows);

            rows.forEach(function (row) {
                row.style.display = "none";
            });

            filteredRows.slice(start, end).forEach(function (row) {
                row.style.display = "";
            });

            if (totalRows === 0) {
                info.innerText = "Showing 0 of 0";
            } else {
                info.innerText = "Showing " + (start + 1) + "–" + end + " of " + totalRows;
            }

            pageInfo.innerText = "Page " + currentPage + " of " + totalPages;

            previousButton.disabled = currentPage <= 1;
            nextButton.disabled = currentPage >= totalPages;


            let emptyFilterRow = document.getElementById("emptyFilterRow");

            if (totalRows === 0 && rows.length > 0) {

                if (!emptyFilterRow) {

                    emptyFilterRow = document.createElement("tr");
                    emptyFilterRow.id = "emptyFilterRow";

                    emptyFilterRow.innerHTML =
                        '<td colspan="17" class="text-center text-muted py-5">No matching payroll records. Try changing your search or filters.</td>';

                    tableBody.appendChild(emptyFilterRow);

                }

                emptyFilterRow.style.display = "";

            } else if (emptyFilterRow) {

                emptyFilterRow.style.display = "none";

            }

        }


        searchInput.addEventListener("input", function () {
            currentPage = 1;
            renderTable();
        });

        statusFilter.addEventListener("change", function () {
            currentPage = 1;
            renderTable();
        });

        previousButton.addEventListener("click", function () {
            if (currentPage > 1) {
                currentPage--;
                renderTable();
            }
        });

        nextButton.addEventListener("click", function () {

            const totalRows = getFilteredRows().length;
            const totalPages = Math.max(1, Math.ceil(totalRows / rowsPerPage));

            if (currentPage < totalPages) {
                currentPage++;
                renderTable();
            }

        });


        renderTable();

    });

</script>

<?php include("hr_footer.php"); ?>