<?php
require_once("../init.php");
requireRole(['hr']);

$companyId = requireCompany();

include("hr_header.php");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/* =========================================================
   GREETING
========================================================= */

$hour = (int) date('G');

if ($hour < 12) {
    $greeting = 'Good morning';
} elseif ($hour < 18) {
    $greeting = 'Good afternoon';
} else {
    $greeting = 'Good evening';
}

$hrName = isset($_SESSION['fullname']) && $_SESSION['fullname'] !== ''
    ? $_SESSION['fullname']
    : 'HR Team';

$today = date('Y-m-d');

/* =========================================================
   1. EMPLOYEES (ACTIVE WORKFORCE)
========================================================= */

$totalEmployees = 0;

$empResult = mysqli_query($conn, "
    SELECT COUNT(*) AS cnt
    FROM employees
    WHERE employment_status = 'Official Employee'
      AND company_id = " . (int) $companyId . "
");

if ($empResult && ($row = mysqli_fetch_assoc($empResult))) {
    $totalEmployees = (int) $row['cnt'];
}


/* =========================================================
   2. ATTENDANCE TODAY
========================================================= */

$attendanceToday = 0;

$stmt = mysqli_prepare($conn, "
    SELECT COUNT(DISTINCT employee_id) AS cnt
    FROM attendance
    WHERE attendance_date = ? AND company_id = ?
");

mysqli_stmt_bind_param($stmt, "si", $today, $companyId);
mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);

if ($row = mysqli_fetch_assoc($result)) {
    $attendanceToday = (int) $row['cnt'];
}

mysqli_stmt_close($stmt);


/* =========================================================
   3. PENDING PAYROLL (BATCHES NOT YET RELEASED)
========================================================= */

$pendingPayrollBatches = 0;

$payrollPendingResult = mysqli_query($conn, "
    SELECT COUNT(*) AS cnt
    FROM payroll
    WHERE status IN ('Draft', 'Pending Approval', 'Returned')
      AND company_id = " . (int) $companyId . "
");

if ($payrollPendingResult && ($row = mysqli_fetch_assoc($payrollPendingResult))) {
    $pendingPayrollBatches = (int) $row['cnt'];
}


/* =========================================================
   4. PENDING APPROVALS BREAKDOWN
========================================================= */

/* Recruitment — applications awaiting a hiring decision */

$pendingRecruitment = 0;

$recruitmentResult = mysqli_query($conn, "
    SELECT COUNT(*) AS cnt
    FROM applications
    WHERE status = 'Pending'
      AND company_id = " . (int) $companyId . "
");

if ($recruitmentResult && ($row = mysqli_fetch_assoc($recruitmentResult))) {
    $pendingRecruitment = (int) $row['cnt'];
}


/* Payroll — batches waiting for HR approval */

$pendingPayrollApproval = 0;

$payrollApprovalResult = mysqli_query($conn, "
    SELECT COUNT(*) AS cnt
    FROM payroll
    WHERE status = 'Pending Approval'
      AND company_id = " . (int) $companyId . "
");

if ($payrollApprovalResult && ($row = mysqli_fetch_assoc($payrollApprovalResult))) {
    $pendingPayrollApproval = (int) $row['cnt'];
}


/* Leave Requests — awaiting HR review */

$pendingLeave = 0;

$leaveResult = mysqli_query($conn, "
    SELECT COUNT(*) AS cnt
    FROM leave_requests
    WHERE hr_status = 'Pending'
      AND company_id = " . (int) $companyId . "
");

if ($leaveResult && ($row = mysqli_fetch_assoc($leaveResult))) {
    $pendingLeave = (int) $row['cnt'];
}


/* Regularization — official employees still on probationary
   status with no regularization date on file yet. There is no
   dedicated "regularization request" table, so this is an
   approximation of who is due for a regularization review. */

$pendingRegularization = 0;

$regularizationResult = mysqli_query($conn, "
    SELECT COUNT(*) AS cnt
    FROM employment
    WHERE employment_status = 'Official Employee'
      AND company_id = " . (int) $companyId . "
      AND employment_type = 'Probationary'
      AND regularization_date IS NULL
");

if ($regularizationResult && ($row = mysqli_fetch_assoc($regularizationResult))) {
    $pendingRegularization = (int) $row['cnt'];
}

$totalPendingApprovals =
    $pendingRecruitment +
    $pendingPayrollApproval +
    $pendingLeave +
    $pendingRegularization;


/* =========================================================
   5. PAYROLL SUMMARY (LATEST PAYROLL PERIOD)
========================================================= */

$periodStart = null;
$periodEnd = null;

$periodResult = mysqli_query($conn, "
    SELECT payroll_period_start, payroll_period_end
    FROM payroll
    WHERE company_id = " . (int) $companyId . "
    ORDER BY payroll_period_end DESC
    LIMIT 1
");

if ($periodResult && ($period = mysqli_fetch_assoc($periodResult))) {
    $periodStart = $period['payroll_period_start'];
    $periodEnd = $period['payroll_period_end'];
}

$grossPayroll = 0.0;
$netPayroll = 0.0;
$totalDeductions = 0.0;
$payrollTotalCount = 0;
$payrollApprovedCount = 0;
$percent = 0;

if ($periodStart && $periodEnd) {

    $stmt = mysqli_prepare($conn, "
        SELECT
            COALESCE(SUM(gross_pay), 0) AS gross,
            COALESCE(SUM(net_pay), 0) AS net,
            COALESCE(SUM(total_deduction), 0) AS deductions,
            COUNT(*) AS total_count,
            SUM(CASE WHEN status IN ('Approved', 'Released') THEN 1 ELSE 0 END) AS approved_count
        FROM payroll
        WHERE payroll_period_start = ?
          AND payroll_period_end = ?
          AND company_id = ?
    ");

    mysqli_stmt_bind_param($stmt, "ssi", $periodStart, $periodEnd, $companyId);
    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);
    $summary = mysqli_fetch_assoc($result);

    mysqli_stmt_close($stmt);

    if ($summary) {
        $grossPayroll = (float) $summary['gross'];
        $netPayroll = (float) $summary['net'];
        $totalDeductions = (float) $summary['deductions'];
        $payrollTotalCount = (int) $summary['total_count'];
        $payrollApprovedCount = (int) $summary['approved_count'];
    }

    if ($payrollTotalCount > 0) {
        $percent = (int) round(($payrollApprovedCount / $payrollTotalCount) * 100);
    }
}

$periodLabel = ($periodStart && $periodEnd)
    ? date('M j', strtotime($periodStart)) . ' - ' . date('M j, Y', strtotime($periodEnd))
    : 'No payroll runs yet';

?>

<style>
    body {
        background: #f4f6fb;
        font-family: 'Poppins', sans-serif;
    }

    /* =====================================================
       HERO / WELCOME BANNER
    ===================================================== */

    .hr-hero {
        background: linear-gradient(120deg, #00224c 0%, #013468 100%);
        border-radius: 20px;
        padding: 28px 32px;
        color: #fff;
        position: relative;
        overflow: hidden;
        box-shadow: 0 12px 30px rgba(0, 34, 76, .18);
    }

    .hr-hero::after {
        content: "";
        position: absolute;
        right: -60px;
        top: -60px;
        width: 220px;
        height: 220px;
        border-radius: 50%;
        background: rgba(251, 189, 35, .12);
    }

    .hr-hero::before {
        content: "";
        position: absolute;
        right: 60px;
        bottom: -90px;
        width: 160px;
        height: 160px;
        border-radius: 50%;
        background: rgba(255, 255, 255, .06);
    }

    .hr-hero h1 {
        font-size: 26px;
        font-weight: 700;
        margin-bottom: 4px;
        position: relative;
        z-index: 1;
    }

    .hr-hero p {
        margin: 0;
        opacity: .8;
        position: relative;
        z-index: 1;
        font-size: 14px;
    }

    .hr-hero .hero-date {
        position: relative;
        z-index: 1;
        background: rgba(255, 255, 255, .12);
        border: 1px solid rgba(255, 255, 255, .18);
        border-radius: 12px;
        padding: 10px 18px;
        font-size: 13px;
        font-weight: 500;
        backdrop-filter: blur(4px);
    }


    /* =====================================================
       KPI CARDS
    ===================================================== */

    .dashboard-card {
        border: none;
        border-radius: 18px;
        transition: transform .25s ease, box-shadow .25s ease;
        overflow: hidden;
        box-shadow: 0 8px 22px rgba(17, 24, 39, .06);
        background: #fff;
    }

    .dashboard-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 16px 32px rgba(17, 24, 39, .10);
    }

    .card-icon {
        width: 54px;
        height: 54px;
        border-radius: 14px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 22px;
    }

    .icon-navy {
        background: rgba(0, 34, 76, .10);
        color: #00224c;
    }

    .icon-green {
        background: rgba(25, 135, 84, .10);
        color: #198754;
    }

    .icon-yellow {
        background: rgba(251, 189, 35, .18);
        color: #a6740a;
    }

    .icon-red {
        background: rgba(220, 53, 69, .10);
        color: #dc3545;
    }

    .stat-label {
        font-size: 13px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: .4px;
        color: #8a94a6;
        margin-bottom: 6px;
    }

    .stat-number {
        font-size: 30px;
        font-weight: 700;
        color: #101828;
        line-height: 1;
    }

    .stat-sub {
        font-size: 12px;
        color: #98a2b3;
        margin-top: 6px;
    }


    /* =====================================================
       SECTION CARDS
    ===================================================== */

    .section-card {
        border: none;
        border-radius: 18px;
        box-shadow: 0 8px 22px rgba(17, 24, 39, .06);
        background: #fff;
    }

    .section-title {
        font-size: 16px;
        font-weight: 700;
        color: #101828;
    }

    .section-sub {
        font-size: 12.5px;
        color: #98a2b3;
    }


    /* =====================================================
       PAYROLL SUMMARY
    ===================================================== */

    .payroll-figure {
        font-size: 24px;
        font-weight: 700;
    }

    .payroll-figure-label {
        font-size: 12.5px;
        color: #98a2b3;
        margin-top: 2px;
    }

    .progress {
        background: #eef1f6;
    }

    .progress-bar {
        background: #00224c;
        border-radius: 20px;
        transition: width .4s ease;
    }


    /* =====================================================
       PENDING APPROVALS LIST
    ===================================================== */

    .approval-item {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 13px 4px;
        border-bottom: 1px solid #f1f3f8;
    }

    .approval-item:last-child {
        border-bottom: none;
    }

    .approval-item .approval-name {
        display: flex;
        align-items: center;
        gap: 12px;
        font-size: 14px;
        font-weight: 500;
        color: #344054;
    }

    .approval-icon {
        width: 36px;
        height: 36px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 15px;
        flex-shrink: 0;
    }
</style>

<div class="container-fluid py-1">

    <!-- =========================================================
        HERO / WELCOME BANNER
    ========================================================= -->

    <div class="hr-hero d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">

        <div>
            <h1><?= htmlspecialchars($greeting) ?>, <?= htmlspecialchars($hrName) ?> 👋</h1>
            <p>Here's what's happening across your workforce today.</p>
        </div>

        <div class="hero-date">
            <i class="bi bi-calendar3 me-1"></i>
            <?= date('l, F j, Y') ?>
        </div>

    </div>


    <!-- =========================================================
        KPI CARDS
    ========================================================= -->

    <div class="row g-4">

        <!-- EMPLOYEES -->
        <div class="col-xl-3 col-md-6">
            <div class="card dashboard-card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <p class="stat-label mb-2">Employees</p>
                            <h2 class="stat-number"><?= number_format($totalEmployees) ?></h2>
                            <p class="stat-sub">Active workforce</p>
                        </div>
                        <div class="card-icon icon-navy">
                            <i class="bi bi-people-fill"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ATTENDANCE -->
        <div class="col-xl-3 col-md-6">
            <div class="card dashboard-card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <p class="stat-label mb-2">Attendance Today</p>
                            <h2 class="stat-number"><?= number_format($attendanceToday) ?></h2>
                            <p class="stat-sub">Employees checked in</p>
                        </div>
                        <div class="card-icon icon-green">
                            <i class="bi bi-calendar-check-fill"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- PAYROLL -->
        <div class="col-xl-3 col-md-6">
            <div class="card dashboard-card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <p class="stat-label mb-2">Pending Payroll</p>
                            <h2 class="stat-number"><?= number_format($pendingPayrollBatches) ?></h2>
                            <p class="stat-sub">Batches awaiting release</p>
                        </div>
                        <div class="card-icon icon-yellow">
                            <i class="bi bi-cash-stack"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- APPROVALS -->
        <div class="col-xl-3 col-md-6">
            <div class="card dashboard-card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <p class="stat-label mb-2">Pending Approvals</p>
                            <h2 class="stat-number"><?= number_format($totalPendingApprovals) ?></h2>
                            <p class="stat-sub">Needs your action</p>
                        </div>
                        <div class="card-icon icon-red">
                            <i class="bi bi-patch-check-fill"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>


    <div class="row mt-4 g-4">

        <!-- =========================================================
            PAYROLL SUMMARY
        ========================================================= -->

        <div class="col-lg-8">
            <div class="card section-card h-100">
                <div class="card-body p-4">

                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <div>
                            <h5 class="section-title mb-1">Payroll Summary</h5>
                            <p class="section-sub mb-0"><?= htmlspecialchars($periodLabel) ?></p>
                        </div>
                    </div>

                    <div class="row text-center">

                        <div class="col-md-4 mb-4">
                            <h3 class="payroll-figure text-success">
                                ₱<?= number_format($grossPayroll, 2) ?>
                            </h3>
                            <small class="payroll-figure-label">Gross Payroll</small>
                        </div>

                        <div class="col-md-4 mb-4">
                            <h3 class="payroll-figure" style="color:#00224c;">
                                ₱<?= number_format($netPayroll, 2) ?>
                            </h3>
                            <small class="payroll-figure-label">Net Payroll</small>
                        </div>

                        <div class="col-md-4 mb-4">
                            <h3 class="payroll-figure text-danger">
                                ₱<?= number_format($totalDeductions, 2) ?>
                            </h3>
                            <small class="payroll-figure-label">Total Deductions</small>
                        </div>

                    </div>

                    <hr>

                    <h6 class="fw-bold mb-3">Payroll Progress</h6>

                    <div class="progress" style="height:12px; border-radius:20px;">
                        <div class="progress-bar" style="width: <?= $percent ?>%;"></div>
                    </div>

                    <div class="d-flex justify-content-between mt-2">
                        <small class="text-muted">
                            <?= $payrollApprovedCount ?> of <?= $payrollTotalCount ?> Approved
                        </small>
                        <small class="text-muted">
                            <?= $percent ?>%
                        </small>
                    </div>

                </div>
            </div>
        </div>


        <!-- =========================================================
            PENDING APPROVALS
        ========================================================= -->

        <div class="col-lg-4">
            <div class="card section-card h-100">
                <div class="card-body p-4">

                    <h5 class="section-title mb-4">Pending Approvals</h5>

                    <div class="approval-list">

                        <div class="approval-item">
                            <span class="approval-name">
                                <span class="approval-icon icon-yellow">
                                    <i class="bi bi-person-plus-fill"></i>
                                </span>
                                Recruitment
                            </span>
                            <span class="badge bg-warning rounded-pill">
                                <?= number_format($pendingRecruitment) ?>
                            </span>
                        </div>

                        <div class="approval-item">
                            <span class="approval-name">
                                <span class="approval-icon icon-navy">
                                    <i class="bi bi-cash-coin"></i>
                                </span>
                                Payroll
                            </span>
                            <span class="badge bg-primary rounded-pill">
                                <?= number_format($pendingPayrollApproval) ?>
                            </span>
                        </div>

                        <div class="approval-item">
                            <span class="approval-name">
                                <span class="approval-icon icon-green">
                                    <i class="bi bi-airplane-fill"></i>
                                </span>
                                Leave Requests
                            </span>
                            <span class="badge bg-success rounded-pill">
                                <?= number_format($pendingLeave) ?>
                            </span>
                        </div>

                        <div class="approval-item">
                            <span class="approval-name">
                                <span class="approval-icon" style="background:rgba(0,34,76,.10); color:#00224c;">
                                    <i class="bi bi-patch-check-fill"></i>
                                </span>
                                Regularization
                            </span>
                            <span class="badge rounded-pill text-white" style="background:#00224c;">
                                <?= number_format($pendingRegularization) ?>
                            </span>
                        </div>

                    </div>

                </div>
            </div>
        </div>

    </div>

</div>


<?php include("hr_footer.php"); ?>