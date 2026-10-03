<?php
require_once("../init.php");
requireRole(['admin']);

$companyId = requireCompany();

include("admin_header.php");

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

$adminName = isset($_SESSION['fullname']) && $_SESSION['fullname'] !== ''
    ? $_SESSION['fullname']
    : 'Admin';

$today = date('Y-m-d');


/* =========================================================
   1. TOTAL EMPLOYEES
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
   2. TODAY'S SALES
========================================================= */

$todaySalesTotal = 0.0;
$todaySalesCount = 0;

$stmt = mysqli_prepare($conn, "
    SELECT
        COALESCE(SUM(total_amount), 0) AS total,
        COUNT(*) AS cnt
    FROM sales
    WHERE DATE(sale_date) = ? AND company_id = ?
");

mysqli_stmt_bind_param($stmt, "si", $today, $companyId);
mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);

if ($row = mysqli_fetch_assoc($result)) {
    $todaySalesTotal = (float) $row['total'];
    $todaySalesCount = (int) $row['cnt'];
}

mysqli_stmt_close($stmt);


/* =========================================================
   3. CURRENT CAPITAL + ACCOUNTS PAYABLE
========================================================= */

$currentCapital = 0.0;

$capitalResult = mysqli_query($conn, "
    SELECT current_capital
    FROM finance_capital
    WHERE company_id = " . (int) $companyId . "
    ORDER BY capital_id ASC
    LIMIT 1
");

if ($capitalResult && ($row = mysqli_fetch_assoc($capitalResult))) {
    $currentCapital = (float) $row['current_capital'];
}

$apOutstandingAmount = 0.0;
$apOutstandingCount = 0;

$apResult = mysqli_query($conn, "
    SELECT
        COALESCE(SUM(amount - paid_amount), 0) AS balance,
        COUNT(*) AS cnt
    FROM accounts_payable
    WHERE status != 'Paid' AND company_id = " . (int) $companyId . "
");

if ($apResult && ($row = mysqli_fetch_assoc($apResult))) {
    $apOutstandingAmount = (float) $row['balance'];
    $apOutstandingCount = (int) $row['cnt'];
}


/* =========================================================
   4. INVENTORY SNAPSHOT
========================================================= */

$totalProducts = 0;

$productResult = mysqli_query($conn, "
    SELECT COUNT(*) AS cnt
    FROM products
    WHERE company_id = " . (int) $companyId . "
");

if ($productResult && ($row = mysqli_fetch_assoc($productResult))) {
    $totalProducts = (int) $row['cnt'];
}

$lowStockCount = 0;
$outOfStockCount = 0;

$alertResult = mysqli_query($conn, "
    SELECT
        SUM(CASE WHEN quantity > 0 AND quantity <= reorder_level THEN 1 ELSE 0 END) AS low_cnt,
        SUM(CASE WHEN quantity = 0 THEN 1 ELSE 0 END) AS out_cnt
    FROM inventory
    WHERE company_id = " . (int) $companyId . "
");

if ($alertResult && ($row = mysqli_fetch_assoc($alertResult))) {
    $lowStockCount = (int) $row['low_cnt'];
    $outOfStockCount = (int) $row['out_cnt'];
}

$totalStockAlerts = $lowStockCount + $outOfStockCount;


/* =========================================================
   5. PENDING APPROVALS — ACROSS ALL DEPARTMENTS
========================================================= */

$pendingRecruitment = 0;

$recruitmentResult = mysqli_query($conn, "
    SELECT COUNT(*) AS cnt
    FROM applications
    WHERE status = 'Pending' AND company_id = " . (int) $companyId . "
");

if ($recruitmentResult && ($row = mysqli_fetch_assoc($recruitmentResult))) {
    $pendingRecruitment = (int) $row['cnt'];
}

$pendingLeave = 0;

$leaveResult = mysqli_query($conn, "
    SELECT COUNT(*) AS cnt
    FROM leave_requests
    WHERE hr_status = 'Pending' AND company_id = " . (int) $companyId . "
");

if ($leaveResult && ($row = mysqli_fetch_assoc($leaveResult))) {
    $pendingLeave = (int) $row['cnt'];
}

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

$pendingPayrollApproval = 0;

$payrollPendingResult = mysqli_query($conn, "
    SELECT COUNT(*) AS cnt
    FROM payroll
    WHERE status = 'Pending Approval' AND company_id = " . (int) $companyId . "
");

if ($payrollPendingResult && ($row = mysqli_fetch_assoc($payrollPendingResult))) {
    $pendingPayrollApproval = (int) $row['cnt'];
}

$pendingAccountsPayable = 0;

$apPendingResult = mysqli_query($conn, "
    SELECT COUNT(*) AS cnt
    FROM accounts_payable
    WHERE status IN ('Pending', 'Partial') AND company_id = " . (int) $companyId . "
");

if ($apPendingResult && ($row = mysqli_fetch_assoc($apPendingResult))) {
    $pendingAccountsPayable = (int) $row['cnt'];
}

$pendingStockRequests = 0;

$stockPendingResult = mysqli_query($conn, "
    SELECT COUNT(*) AS cnt
    FROM stock_requests
    WHERE status NOT IN ('Received', 'Cancelled', 'Finance Rejected', 'Admin Rejected')
      AND company_id = " . (int) $companyId . "
");

if ($stockPendingResult && ($row = mysqli_fetch_assoc($stockPendingResult))) {
    $pendingStockRequests = (int) $row['cnt'];
}

$totalPendingApprovals =
    $pendingRecruitment +
    $pendingLeave +
    $pendingRegularization +
    $pendingPayrollApproval +
    $pendingAccountsPayable +
    $pendingStockRequests;


/* =========================================================
   6. SALES TREND — LAST 7 DAYS
========================================================= */

$salesTrend = [];

for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-{$i} days"));
    $salesTrend[$date] = [
        'label' => date('D', strtotime($date)),
        'total' => 0.0
    ];
}

$trendStart = date('Y-m-d', strtotime('-6 days'));

$stmt = mysqli_prepare($conn, "
    SELECT
        DATE(sale_date) AS sale_day,
        SUM(total_amount) AS total
    FROM sales
    WHERE DATE(sale_date) BETWEEN ? AND ? AND company_id = ?
    GROUP BY DATE(sale_date)
");

mysqli_stmt_bind_param($stmt, "ssi", $trendStart, $today, $companyId);
mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);

while ($row = mysqli_fetch_assoc($result)) {
    if (isset($salesTrend[$row['sale_day']])) {
        $salesTrend[$row['sale_day']]['total'] = (float) $row['total'];
    }
}

mysqli_stmt_close($stmt);

$weekTotal = array_sum(array_column($salesTrend, 'total'));
$trendMax = max(array_column($salesTrend, 'total'));

if ($trendMax <= 0) {
    $trendMax = 1;
}


/* =========================================================
   7. RECENT EXPENSES
========================================================= */

$recentExpenses = [];

$expenseResult = mysqli_query($conn, "
    SELECT
        expense_date,
        category,
        vendor,
        amount,
        payment_method
    FROM expenses
    WHERE company_id = " . (int) $companyId . "
    ORDER BY expense_date DESC, expense_id DESC
    LIMIT 5
");

if ($expenseResult) {
    while ($row = mysqli_fetch_assoc($expenseResult)) {
        $recentExpenses[] = $row;
    }
}


/* =========================================================
   8. LOW STOCK / OUT OF STOCK ITEMS
========================================================= */

$stockAlertItems = [];

$alertItemsResult = mysqli_query($conn, "
    SELECT
        p.product_name,
        i.quantity,
        i.reorder_level,
        cat.category_name
    FROM inventory i
    INNER JOIN products p
        ON p.product_id = i.product_id AND p.company_id = i.company_id
    LEFT JOIN categories cat
        ON cat.category_id = p.category_id AND cat.company_id = i.company_id
    WHERE i.quantity <= i.reorder_level
      AND i.company_id = " . (int) $companyId . "
    ORDER BY i.quantity ASC
    LIMIT 5
");

if ($alertItemsResult) {
    while ($row = mysqli_fetch_assoc($alertItemsResult)) {
        $stockAlertItems[] = $row;
    }
}

?>

<style>
    body {
        background: #f4f6fb;
        font-family: 'Poppins', sans-serif;
    }

    /* =====================================================
       HERO / WELCOME BANNER
    ===================================================== */

    .admin-hero {
        background: linear-gradient(120deg, #00224c 0%, #013468 100%);
        border-radius: 20px;
        padding: 28px 32px;
        color: #fff;
        position: relative;
        overflow: hidden;
        box-shadow: 0 12px 30px rgba(0, 34, 76, .18);
    }

    .admin-hero::after {
        content: "";
        position: absolute;
        right: -60px;
        top: -60px;
        width: 220px;
        height: 220px;
        border-radius: 50%;
        background: rgba(251, 189, 35, .12);
    }

    .admin-hero::before {
        content: "";
        position: absolute;
        right: 60px;
        bottom: -90px;
        width: 160px;
        height: 160px;
        border-radius: 50%;
        background: rgba(255, 255, 255, .06);
    }

    .admin-hero h1 {
        font-size: 26px;
        font-weight: 700;
        margin-bottom: 4px;
        position: relative;
        z-index: 1;
    }

    .admin-hero p {
        margin: 0;
        opacity: .8;
        position: relative;
        z-index: 1;
        font-size: 14px;
    }

    .admin-hero .hero-date {
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
        font-size: 26px;
        font-weight: 700;
        color: #101828;
        line-height: 1.1;
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
       SALES TREND (CSS-ONLY BAR CHART)
    ===================================================== */

    .trend-chart {
        display: flex;
        align-items: flex-end;
        justify-content: space-between;
        gap: 10px;
        height: 150px;
        padding-top: 10px;
    }

    .trend-bar-wrap {
        flex: 1;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: flex-end;
        height: 100%;
    }

    .trend-bar {
        width: 100%;
        max-width: 34px;
        border-radius: 8px 8px 4px 4px;
        background: linear-gradient(180deg, #00224c 0%, #013a7d 100%);
        transition: height .5s ease;
        min-height: 4px;
    }

    .trend-bar.is-today {
        background: linear-gradient(180deg, #fbbd23 0%, #e6a90f 100%);
    }

    .trend-label {
        font-size: 11px;
        color: #98a2b3;
        margin-top: 8px;
        font-weight: 600;
    }


    /* =====================================================
       DEPARTMENT SNAPSHOT CARDS
    ===================================================== */

    .dept-card {
        border-radius: 18px;
        border: 1px solid #eef1f6;
        padding: 22px;
        height: 100%;
        text-decoration: none;
        display: block;
        transition: transform .25s ease, box-shadow .25s ease, border-color .25s ease;
        background: #fff;
    }

    .dept-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 14px 28px rgba(17, 24, 39, .08);
        border-color: transparent;
    }

    .dept-card-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 18px;
    }

    .dept-card-title {
        font-size: 15px;
        font-weight: 700;
        color: #101828;
    }

    .dept-metric {
        display: flex;
        justify-content: space-between;
        align-items: center;
        font-size: 13px;
        padding: 7px 0;
        border-bottom: 1px dashed #eef1f6;
    }

    .dept-metric:last-child {
        border-bottom: none;
    }

    .dept-metric .metric-label {
        color: #667085;
    }

    .dept-metric .metric-value {
        font-weight: 700;
        color: #101828;
    }

    .dept-card-link {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        font-size: 12.5px;
        font-weight: 600;
        color: #00224c;
        margin-top: 16px;
    }


    /* =====================================================
       LIST ITEMS
    ===================================================== */

    .approval-item {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 12px 4px;
        border-bottom: 1px solid #f1f3f8;
    }

    .approval-item:last-child {
        border-bottom: none;
    }

    .approval-item .approval-name {
        display: flex;
        align-items: center;
        gap: 12px;
        font-size: 13.5px;
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

    .badge-status {
        font-size: 11px;
        font-weight: 600;
        padding: 5px 10px;
        border-radius: 30px;
        white-space: nowrap;
    }


    /* =====================================================
       TABLES
    ===================================================== */

    .adm-table {
        font-size: 13.5px;
    }

    .adm-table thead th {
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: .4px;
        color: #98a2b3;
        border-bottom: 1px solid #eef1f6;
        font-weight: 600;
        padding-bottom: 10px;
    }

    .adm-table tbody td {
        padding: 12px 0;
        border-bottom: 1px solid #f5f6fa;
        vertical-align: middle;
    }

    .adm-table tbody tr:last-child td {
        border-bottom: none;
    }

    .empty-state {
        text-align: center;
        color: #98a2b3;
        font-size: 13.5px;
        padding: 24px 0;
    }
</style>

<div class="container-fluid py-1">

    <!-- =========================================================
        HERO / WELCOME BANNER
    ========================================================= -->

    <div class="admin-hero d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">

        <div>
            <h1><?= htmlspecialchars($greeting) ?>, <?= htmlspecialchars($adminName) ?> 👋</h1>
            <p>Here's how the whole business is doing today.</p>
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
                            <p class="stat-sub"><?= number_format($attendanceToday) ?> checked in today</p>
                        </div>
                        <div class="card-icon icon-navy">
                            <i class="bi bi-people-fill"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- TODAY'S SALES -->
        <div class="col-xl-3 col-md-6">
            <div class="card dashboard-card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <p class="stat-label mb-2">Today's Sales</p>
                            <h2 class="stat-number">₱<?= number_format($todaySalesTotal, 2) ?></h2>
                            <p class="stat-sub"><?= number_format($todaySalesCount) ?> transaction<?= $todaySalesCount === 1 ? '' : 's' ?></p>
                        </div>
                        <div class="card-icon icon-green">
                            <i class="bi bi-graph-up-arrow"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- CURRENT CAPITAL -->
        <div class="col-xl-3 col-md-6">
            <div class="card dashboard-card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <p class="stat-label mb-2">Current Capital</p>
                            <h2 class="stat-number">₱<?= number_format($currentCapital, 2) ?></h2>
                            <p class="stat-sub">₱<?= number_format($apOutstandingAmount, 2) ?> payable outstanding</p>
                        </div>
                        <div class="card-icon icon-yellow">
                            <i class="bi bi-wallet2"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- PENDING APPROVALS -->
        <div class="col-xl-3 col-md-6">
            <div class="card dashboard-card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <p class="stat-label mb-2">Pending Approvals</p>
                            <h2 class="stat-number"><?= number_format($totalPendingApprovals) ?></h2>
                            <p class="stat-sub">Across all departments</p>
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
            SALES TREND
        ========================================================= -->

        <div class="col-lg-8">
            <div class="card section-card h-100">
                <div class="card-body p-4">

                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <div>
                            <h5 class="section-title mb-1">Sales Trend</h5>
                            <p class="section-sub mb-0">Last 7 days</p>
                        </div>
                        <div class="text-end">
                            <div class="fw-bold" style="color:#00224c; font-size:18px;">
                                ₱<?= number_format($weekTotal, 2) ?>
                            </div>
                            <div class="section-sub">This week</div>
                        </div>
                    </div>

                    <div class="trend-chart mt-3">

                        <?php foreach ($salesTrend as $date => $day): ?>

                                <?php
                                $barHeight = max(6, (int) round(($day['total'] / $trendMax) * 100));
                                $isToday = ($date === $today);
                                ?>

                                <div class="trend-bar-wrap" title="₱<?= number_format($day['total'], 2) ?>">
                                    <div class="trend-bar <?= $isToday ? 'is-today' : '' ?>" style="height: <?= $barHeight ?>%;"></div>
                                    <div class="trend-label"><?= htmlspecialchars($day['label']) ?></div>
                                </div>

                        <?php endforeach; ?>

                    </div>

                </div>
            </div>
        </div>


        <!-- =========================================================
            PENDING APPROVALS — ALL DEPARTMENTS
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
                                <span class="approval-icon icon-yellow">
                                    <i class="bi bi-receipt-cutoff"></i>
                                </span>
                                Accounts Payable
                            </span>
                            <span class="badge bg-warning rounded-pill">
                                <?= number_format($pendingAccountsPayable) ?>
                            </span>
                        </div>

                        <div class="approval-item">
                            <span class="approval-name">
                                <span class="approval-icon icon-red">
                                    <i class="bi bi-box-seam"></i>
                                </span>
                                Stock Requests
                            </span>
                            <span class="badge bg-danger rounded-pill">
                                <?= number_format($pendingStockRequests) ?>
                            </span>
                        </div>

                    </div>

                </div>
            </div>
        </div>

    </div>


    <!-- =========================================================
        DEPARTMENT SNAPSHOTS
    ========================================================= -->

    <div class="row mt-4 g-4">

        <div class="col-lg-4">
            <a href="../hr/dashboard.php" class="dept-card">
                <div class="dept-card-header">
                    <span class="dept-card-title">Human Resources</span>
                    <span class="card-icon icon-navy" style="width:44px; height:44px; font-size:18px;">
                        <i class="bi bi-people-fill"></i>
                    </span>
                </div>

                <div class="dept-metric">
                    <span class="metric-label">Active Employees</span>
                    <span class="metric-value"><?= number_format($totalEmployees) ?></span>
                </div>

                <div class="dept-metric">
                    <span class="metric-label">Checked In Today</span>
                    <span class="metric-value"><?= number_format($attendanceToday) ?></span>
                </div>

                <div class="dept-metric">
                    <span class="metric-label">Pending Approvals</span>
                    <span class="metric-value"><?= number_format($pendingRecruitment + $pendingLeave + $pendingRegularization) ?></span>
                </div>

                <span class="dept-card-link">
                    Open HR Dashboard <i class="bi bi-arrow-right"></i>
                </span>
            </a>
        </div>

        <div class="col-lg-4">
            <a href="../finance/dashboard.php" class="dept-card">
                <div class="dept-card-header">
                    <span class="dept-card-title">Finance</span>
                    <span class="card-icon icon-green" style="width:44px; height:44px; font-size:18px;">
                        <i class="bi bi-wallet2"></i>
                    </span>
                </div>

                <div class="dept-metric">
                    <span class="metric-label">Current Capital</span>
                    <span class="metric-value">₱<?= number_format($currentCapital, 2) ?></span>
                </div>

                <div class="dept-metric">
                    <span class="metric-label">Accounts Payable</span>
                    <span class="metric-value">₱<?= number_format($apOutstandingAmount, 2) ?></span>
                </div>

                <div class="dept-metric">
                    <span class="metric-label">Pending Approvals</span>
                    <span class="metric-value"><?= number_format($pendingPayrollApproval + $pendingAccountsPayable) ?></span>
                </div>

                <span class="dept-card-link">
                    Open Finance Dashboard <i class="bi bi-arrow-right"></i>
                </span>
            </a>
        </div>

        <div class="col-lg-4">
            <a href="../inventory/dashboard.php" class="dept-card">
                <div class="dept-card-header">
                    <span class="dept-card-title">Inventory</span>
                    <span class="card-icon icon-yellow" style="width:44px; height:44px; font-size:18px;">
                        <i class="bi bi-box-seam-fill"></i>
                    </span>
                </div>

                <div class="dept-metric">
                    <span class="metric-label">Total Products</span>
                    <span class="metric-value"><?= number_format($totalProducts) ?></span>
                </div>

                <div class="dept-metric">
                    <span class="metric-label">Stock Alerts</span>
                    <span class="metric-value"><?= number_format($totalStockAlerts) ?></span>
                </div>

                <div class="dept-metric">
                    <span class="metric-label">Pending Requests</span>
                    <span class="metric-value"><?= number_format($pendingStockRequests) ?></span>
                </div>

                <span class="dept-card-link">
                    Open Inventory Dashboard <i class="bi bi-arrow-right"></i>
                </span>
            </a>
        </div>

    </div>


    <div class="row mt-4 g-4">

        <!-- =========================================================
            RECENT EXPENSES
        ========================================================= -->

        <div class="col-lg-7">
            <div class="card section-card h-100">
                <div class="card-body p-4">

                    <h5 class="section-title mb-4">Recent Expenses</h5>

                    <?php if (empty($recentExpenses)): ?>

                            <div class="empty-state">
                                No expenses recorded yet.
                            </div>

                    <?php else: ?>

                            <div class="table-responsive">
                                <table class="table adm-table mb-0">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Vendor</th>
                                            <th>Category</th>
                                            <th>Method</th>
                                            <th class="text-end">Amount</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recentExpenses as $expense): ?>
                                                <tr>
                                                    <td><?= date('M j, Y', strtotime($expense['expense_date'])) ?></td>
                                                    <td><?= htmlspecialchars($expense['vendor']) ?></td>
                                                    <td><?= htmlspecialchars($expense['category']) ?></td>
                                                    <td><?= htmlspecialchars($expense['payment_method']) ?></td>
                                                    <td class="text-end fw-semibold">₱<?= number_format((float) $expense['amount'], 2) ?></td>
                                                </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                    <?php endif; ?>

                </div>
            </div>
        </div>


        <!-- =========================================================
            LOW STOCK / OUT OF STOCK
        ========================================================= -->

        <div class="col-lg-5">
            <div class="card section-card h-100">
                <div class="card-body p-4">

                    <h5 class="section-title mb-4">Needs Restocking</h5>

                    <?php if (empty($stockAlertItems)): ?>

                            <div class="empty-state">
                                All items are within safe stock levels.
                            </div>

                    <?php else: ?>

                            <?php foreach ($stockAlertItems as $item): ?>

                                    <?php
                                    $qty = (int) $item['quantity'];
                                    $isOut = $qty === 0;
                                    ?>

                                    <div class="approval-item">
                                        <span class="approval-name">
                                            <span class="approval-icon <?= $isOut ? 'icon-red' : 'icon-yellow' ?>">
                                                <i class="bi bi-box"></i>
                                            </span>
                                            <span>
                                                <?= htmlspecialchars($item['product_name']) ?>
                                                <br>
                                                <small class="text-muted">
                                                    <?= htmlspecialchars($item['category_name'] ?? 'Uncategorized') ?>
                                                </small>
                                            </span>
                                        </span>
                                        <span class="badge-status <?= $isOut ? 'bg-danger-subtle text-danger-emphasis' : 'bg-warning-subtle text-warning-emphasis' ?>">
                                            <?= $isOut ? 'Out of stock' : $qty . ' left' ?>
                                        </span>
                                    </div>

                            <?php endforeach; ?>

                    <?php endif; ?>

                </div>
            </div>
        </div>

    </div>

</div>


<?php include("admin_footer.php"); ?>