<?php
require_once("../init.php");
requireRole(['finance']);

$companyId = requireCompany();

include("finance_header.php");

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

$financeName = isset($_SESSION['fullname']) && $_SESSION['fullname'] !== ''
    ? $_SESSION['fullname']
    : 'Finance Team';

$today = date('Y-m-d');


/* =========================================================
   1. CURRENT CAPITAL
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
   3. ACCOUNTS PAYABLE — OUTSTANDING
========================================================= */

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
   5. SALES TREND — LAST 7 DAYS
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
   6. PENDING APPROVALS (FINANCE ACTION NEEDED)
========================================================= */

$pendingStockRequests = 0;

$stockResult = mysqli_query($conn, "
    SELECT COUNT(*) AS cnt
    FROM stock_requests
    WHERE status = 'Pending Finance' AND company_id = " . (int) $companyId . "
");

if ($stockResult && ($row = mysqli_fetch_assoc($stockResult))) {
    $pendingStockRequests = (int) $row['cnt'];
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

$pendingPayrollApproval = 0;

$payrollPendingResult = mysqli_query($conn, "
    SELECT COUNT(*) AS cnt
    FROM payroll
    WHERE status = 'Pending Approval' AND company_id = " . (int) $companyId . "
");

if ($payrollPendingResult && ($row = mysqli_fetch_assoc($payrollPendingResult))) {
    $pendingPayrollApproval = (int) $row['cnt'];
}

$totalPendingApprovals =
    $pendingStockRequests +
    $pendingAccountsPayable +
    $pendingPayrollApproval;


/* =========================================================
   7. RECENT EXPENSES
========================================================= */

$recentExpenses = [];

$expenseResult = mysqli_query($conn, "
    SELECT
        expense_code,
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
   8. ACCOUNTS PAYABLE — DUE SOON
========================================================= */

$apDueSoon = [];

$apDueResult = mysqli_query($conn, "
    SELECT
        invoice_no,
        supplier,
        amount,
        paid_amount,
        due_date,
        status
    FROM accounts_payable
    WHERE status != 'Paid' AND company_id = " . (int) $companyId . "
    ORDER BY due_date ASC
    LIMIT 5
");

if ($apDueResult) {
    while ($row = mysqli_fetch_assoc($apDueResult)) {
        $apDueSoon[] = $row;
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

    .fin-hero {
        background: linear-gradient(120deg, #00224c 0%, #013468 100%);
        border-radius: 20px;
        padding: 28px 32px;
        color: #fff;
        position: relative;
        overflow: hidden;
        box-shadow: 0 12px 30px rgba(0, 34, 76, .18);
    }

    .fin-hero::after {
        content: "";
        position: absolute;
        right: -60px;
        top: -60px;
        width: 220px;
        height: 220px;
        border-radius: 50%;
        background: rgba(251, 189, 35, .12);
    }

    .fin-hero::before {
        content: "";
        position: absolute;
        right: 60px;
        bottom: -90px;
        width: 160px;
        height: 160px;
        border-radius: 50%;
        background: rgba(255, 255, 255, .06);
    }

    .fin-hero h1 {
        font-size: 26px;
        font-weight: 700;
        margin-bottom: 4px;
        position: relative;
        z-index: 1;
    }

    .fin-hero p {
        margin: 0;
        opacity: .8;
        position: relative;
        z-index: 1;
        font-size: 14px;
    }

    .fin-hero .hero-date {
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
        position: relative;
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


    /* =====================================================
       TABLES
    ===================================================== */

    .fin-table {
        font-size: 13.5px;
    }

    .fin-table thead th {
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: .4px;
        color: #98a2b3;
        border-bottom: 1px solid #eef1f6;
        font-weight: 600;
        padding-bottom: 10px;
    }

    .fin-table tbody td {
        padding: 12px 0;
        border-bottom: 1px solid #f5f6fa;
        vertical-align: middle;
    }

    .fin-table tbody tr:last-child td {
        border-bottom: none;
    }

    .badge-status {
        font-size: 11px;
        font-weight: 600;
        padding: 5px 10px;
        border-radius: 30px;
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

    <div class="fin-hero d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">

        <div>
            <h1><?= htmlspecialchars($greeting) ?>, <?= htmlspecialchars($financeName) ?> 👋</h1>
            <p>Here's your store's financial snapshot for today.</p>
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

        <!-- CURRENT CAPITAL -->
        <div class="col-xl-3 col-md-6">
            <div class="card dashboard-card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <p class="stat-label mb-2">Current Capital</p>
                            <h2 class="stat-number">₱<?= number_format($currentCapital, 2) ?></h2>
                            <p class="stat-sub">Available business funds</p>
                        </div>
                        <div class="card-icon icon-navy">
                            <i class="bi bi-wallet2"></i>
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
                            <p class="stat-sub"><?= number_format($todaySalesCount) ?>
                                transaction<?= $todaySalesCount === 1 ? '' : 's' ?></p>
                        </div>
                        <div class="card-icon icon-green">
                            <i class="bi bi-graph-up-arrow"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ACCOUNTS PAYABLE -->
        <div class="col-xl-3 col-md-6">
            <div class="card dashboard-card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <p class="stat-label mb-2">Accounts Payable</p>
                            <h2 class="stat-number">₱<?= number_format($apOutstandingAmount, 2) ?></h2>
                            <p class="stat-sub"><?= number_format($apOutstandingCount) ?> unpaid
                                invoice<?= $apOutstandingCount === 1 ? '' : 's' ?></p>
                        </div>
                        <div class="card-icon icon-yellow">
                            <i class="bi bi-receipt"></i>
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
                                <div class="trend-bar <?= $isToday ? 'is-today' : '' ?>"
                                    style="height: <?= $barHeight ?>%;"></div>
                                <div class="trend-label"><?= htmlspecialchars($day['label']) ?></div>
                            </div>

                        <?php endforeach; ?>

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

                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h5 class="section-title mb-0">Pending Approvals</h5>
                        <span class="badge rounded-pill text-white" style="background:#00224c;">
                            <?= number_format($totalPendingApprovals) ?>
                        </span>
                    </div>

                    <div class="approval-list">

                        <div class="approval-item">
                            <span class="approval-name">
                                <span class="approval-icon icon-yellow">
                                    <i class="bi bi-box-seam"></i>
                                </span>
                                Stock Requests
                            </span>
                            <span class="badge bg-warning rounded-pill">
                                <?= number_format($pendingStockRequests) ?>
                            </span>
                        </div>

                        <div class="approval-item">
                            <span class="approval-name">
                                <span class="approval-icon icon-navy">
                                    <i class="bi bi-receipt-cutoff"></i>
                                </span>
                                Accounts Payable
                            </span>
                            <span class="badge bg-primary rounded-pill">
                                <?= number_format($pendingAccountsPayable) ?>
                            </span>
                        </div>

                        <div class="approval-item">
                            <span class="approval-name">
                                <span class="approval-icon icon-green">
                                    <i class="bi bi-cash-coin"></i>
                                </span>
                                Payroll
                            </span>
                            <span class="badge bg-success rounded-pill">
                                <?= number_format($pendingPayrollApproval) ?>
                            </span>
                        </div>

                    </div>

                </div>
            </div>
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
                            <table class="table fin-table mb-0">
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
                                            <td class="text-end fw-semibold">
                                                ₱<?= number_format((float) $expense['amount'], 2) ?></td>
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
            ACCOUNTS PAYABLE DUE SOON
        ========================================================= -->

        <div class="col-lg-5">
            <div class="card section-card h-100">
                <div class="card-body p-4">

                    <h5 class="section-title mb-4">Accounts Payable Due Soon</h5>

                    <?php if (empty($apDueSoon)): ?>

                        <div class="empty-state">
                            No outstanding payables.
                        </div>

                    <?php else: ?>

                        <?php foreach ($apDueSoon as $ap): ?>

                            <?php
                            $balance = (float) $ap['amount'] - (float) $ap['paid_amount'];
                            $isOverdue = strtotime($ap['due_date']) < strtotime($today) && $ap['status'] !== 'Paid';

                            $statusClass = match ($ap['status']) {
                                'Partial' => 'bg-warning-subtle text-warning-emphasis',
                                'Pending' => 'bg-secondary-subtle text-secondary-emphasis',
                                default => 'bg-light text-muted'
                            };
                            ?>

                            <div class="approval-item">
                                <span class="approval-name">
                                    <span class="approval-icon" style="background:rgba(0,34,76,.08); color:#00224c;">
                                        <i class="bi bi-building"></i>
                                    </span>
                                    <span>
                                        <?= htmlspecialchars($ap['supplier']) ?>
                                        <br>
                                        <small class="text-muted">
                                            <?= htmlspecialchars($ap['invoice_no']) ?> ·
                                            <span class="<?= $isOverdue ? 'text-danger fw-semibold' : '' ?>">
                                                Due <?= date('M j, Y', strtotime($ap['due_date'])) ?>
                                            </span>
                                        </small>
                                    </span>
                                </span>
                                <span class="text-end">
                                    <div class="fw-bold" style="color:#00224c;">
                                        ₱<?= number_format($balance, 2) ?>
                                    </div>
                                    <span class="badge-status <?= $statusClass ?>">
                                        <?= htmlspecialchars($ap['status']) ?>
                                    </span>
                                </span>
                            </div>

                        <?php endforeach; ?>

                    <?php endif; ?>

                </div>
            </div>
        </div>

    </div>

</div>


<?php include("finance_footer.php"); ?>