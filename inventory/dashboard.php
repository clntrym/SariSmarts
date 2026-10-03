<?php
require_once("../init.php");
requireRole(['inventory']);

$companyId = requireCompany();

include("inventory_header.php");

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

$inventoryName = isset($_SESSION['fullname']) && $_SESSION['fullname'] !== ''
    ? $_SESSION['fullname']
    : 'Inventory Team';


/* =========================================================
   1. TOTAL PRODUCTS
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


/* =========================================================
   2. INVENTORY VALUE + TOTAL STOCK UNITS
========================================================= */

$inventoryValue = 0.0;
$totalStockUnits = 0;

$inventoryValueResult = mysqli_query($conn, "
    SELECT
        COALESCE(SUM(quantity * purchase_cost), 0) AS value,
        COALESCE(SUM(quantity), 0) AS units
    FROM inventory
    WHERE company_id = " . (int) $companyId . "
");

if ($inventoryValueResult && ($row = mysqli_fetch_assoc($inventoryValueResult))) {
    $inventoryValue = (float) $row['value'];
    $totalStockUnits = (int) $row['units'];
}


/* =========================================================
   3. STOCK ALERTS — LOW STOCK / OUT OF STOCK
========================================================= */

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
   4. PENDING STOCK REQUESTS
========================================================= */

$pendingStockRequests = 0;

$pendingResult = mysqli_query($conn, "
    SELECT COUNT(*) AS cnt
    FROM stock_requests
    WHERE status NOT IN ('Received', 'Cancelled', 'Finance Rejected', 'Admin Rejected')
      AND company_id = " . (int) $companyId . "
");

if ($pendingResult && ($row = mysqli_fetch_assoc($pendingResult))) {
    $pendingStockRequests = (int) $row['cnt'];
}


/* =========================================================
   5. STOCK LEVELS BY CATEGORY
========================================================= */

$categoryStock = [];

$categoryResult = mysqli_query($conn, "
    SELECT
        c.category_id,
        c.category_name,
        COALESCE(SUM(i.quantity), 0) AS total_qty
    FROM categories c
    LEFT JOIN products p
        ON p.category_id = c.category_id AND p.company_id = c.company_id
    LEFT JOIN inventory i
        ON i.product_id = p.product_id AND i.company_id = c.company_id
    WHERE c.company_id = " . (int) $companyId . "
    GROUP BY c.category_id, c.category_name
    ORDER BY total_qty DESC
");

if ($categoryResult) {
    while ($row = mysqli_fetch_assoc($categoryResult)) {
        $categoryStock[] = $row;
    }
}

$categoryMax = 0;

foreach ($categoryStock as $cat) {
    if ((int) $cat['total_qty'] > $categoryMax) {
        $categoryMax = (int) $cat['total_qty'];
    }
}

if ($categoryMax <= 0) {
    $categoryMax = 1;
}


/* =========================================================
   6. STOCK ALERT DETAILS (LOW / OUT OF STOCK ITEMS)
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
    LIMIT 6
");

if ($alertItemsResult) {
    while ($row = mysqli_fetch_assoc($alertItemsResult)) {
        $stockAlertItems[] = $row;
    }
}


/* =========================================================
   7. PENDING STOCK REQUESTS (LIST)
========================================================= */

$stockRequestList = [];

$requestListResult = mysqli_query($conn, "
    SELECT
        request_code,
        reason,
        expense_category,
        total_price,
        status,
        created_at
    FROM stock_requests
    WHERE status NOT IN ('Received', 'Cancelled', 'Finance Rejected', 'Admin Rejected')
      AND company_id = " . (int) $companyId . "
    ORDER BY created_at DESC
    LIMIT 6
");

if ($requestListResult) {
    while ($row = mysqli_fetch_assoc($requestListResult)) {
        $stockRequestList[] = $row;
    }
}


/* =========================================================
   8. RECENTLY ADDED PRODUCTS
========================================================= */

$recentProducts = [];

$recentProductsResult = mysqli_query($conn, "
    SELECT
        p.product_name,
        p.created_at,
        cat.category_name,
        s.supplier_name,
        i.quantity
    FROM products p
    LEFT JOIN categories cat
        ON cat.category_id = p.category_id AND cat.company_id = p.company_id
    LEFT JOIN suppliers s
        ON s.supplier_id = p.supplier_id AND s.company_id = p.company_id
    LEFT JOIN inventory i
        ON i.product_id = p.product_id AND i.company_id = p.company_id
    WHERE p.company_id = " . (int) $companyId . "
    ORDER BY p.created_at DESC
    LIMIT 5
");

if ($recentProductsResult) {
    while ($row = mysqli_fetch_assoc($recentProductsResult)) {
        $recentProducts[] = $row;
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

    .inv-hero {
        background: linear-gradient(120deg, #00224c 0%, #013468 100%);
        border-radius: 20px;
        padding: 28px 32px;
        color: #fff;
        position: relative;
        overflow: hidden;
        box-shadow: 0 12px 30px rgba(0, 34, 76, .18);
    }

    .inv-hero::after {
        content: "";
        position: absolute;
        right: -60px;
        top: -60px;
        width: 220px;
        height: 220px;
        border-radius: 50%;
        background: rgba(251, 189, 35, .12);
    }

    .inv-hero::before {
        content: "";
        position: absolute;
        right: 60px;
        bottom: -90px;
        width: 160px;
        height: 160px;
        border-radius: 50%;
        background: rgba(255, 255, 255, .06);
    }

    .inv-hero h1 {
        font-size: 26px;
        font-weight: 700;
        margin-bottom: 4px;
        position: relative;
        z-index: 1;
    }

    .inv-hero p {
        margin: 0;
        opacity: .8;
        position: relative;
        z-index: 1;
        font-size: 14px;
    }

    .inv-hero .hero-date {
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
       CATEGORY STOCK BARS
    ===================================================== */

    .category-row {
        margin-bottom: 16px;
    }

    .category-row:last-child {
        margin-bottom: 0;
    }

    .category-row-top {
        display: flex;
        justify-content: space-between;
        font-size: 13.5px;
        margin-bottom: 6px;
    }

    .category-row-top .cat-name {
        font-weight: 500;
        color: #344054;
    }

    .category-row-top .cat-qty {
        font-weight: 700;
        color: #00224c;
    }

    .category-bar-track {
        background: #eef1f6;
        border-radius: 20px;
        height: 9px;
        overflow: hidden;
    }

    .category-bar-fill {
        height: 100%;
        border-radius: 20px;
        background: linear-gradient(90deg, #00224c 0%, #013a7d 100%);
        transition: width .5s ease;
    }


    /* =====================================================
       STOCK ALERT LIST
    ===================================================== */

    .alert-item {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 12px 4px;
        border-bottom: 1px solid #f1f3f8;
    }

    .alert-item:last-child {
        border-bottom: none;
    }

    .alert-item .alert-name {
        display: flex;
        align-items: center;
        gap: 12px;
        font-size: 13.5px;
        font-weight: 500;
        color: #344054;
    }

    .alert-icon {
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

    .inv-table {
        font-size: 13.5px;
    }

    .inv-table thead th {
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: .4px;
        color: #98a2b3;
        border-bottom: 1px solid #eef1f6;
        font-weight: 600;
        padding-bottom: 10px;
    }

    .inv-table tbody td {
        padding: 12px 0;
        border-bottom: 1px solid #f5f6fa;
        vertical-align: middle;
    }

    .inv-table tbody tr:last-child td {
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

    <div class="inv-hero d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">

        <div>
            <h1><?= htmlspecialchars($greeting) ?>, <?= htmlspecialchars($inventoryName) ?> 👋</h1>
            <p>Here's what's happening with your stock today.</p>
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

        <!-- TOTAL PRODUCTS -->
        <div class="col-xl-3 col-md-6">
            <div class="card dashboard-card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <p class="stat-label mb-2">Total Products</p>
                            <h2 class="stat-number"><?= number_format($totalProducts) ?></h2>
                            <p class="stat-sub"><?= number_format($totalStockUnits) ?> units in stock</p>
                        </div>
                        <div class="card-icon icon-navy">
                            <i class="bi bi-box-seam-fill"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- INVENTORY VALUE -->
        <div class="col-xl-3 col-md-6">
            <div class="card dashboard-card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <p class="stat-label mb-2">Inventory Value</p>
                            <h2 class="stat-number">₱<?= number_format($inventoryValue, 2) ?></h2>
                            <p class="stat-sub">At purchase cost</p>
                        </div>
                        <div class="card-icon icon-green">
                            <i class="bi bi-graph-up"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- STOCK ALERTS -->
        <div class="col-xl-3 col-md-6">
            <div class="card dashboard-card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <p class="stat-label mb-2">Stock Alerts</p>
                            <h2 class="stat-number"><?= number_format($totalStockAlerts) ?></h2>
                            <p class="stat-sub"><?= number_format($lowStockCount) ?> low ·
                                <?= number_format($outOfStockCount) ?> out</p>
                        </div>
                        <div class="card-icon icon-yellow">
                            <i class="bi bi-exclamation-triangle-fill"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- PENDING STOCK REQUESTS -->
        <div class="col-xl-3 col-md-6">
            <div class="card dashboard-card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <p class="stat-label mb-2">Pending Stock Requests</p>
                            <h2 class="stat-number"><?= number_format($pendingStockRequests) ?></h2>
                            <p class="stat-sub">Awaiting approval / delivery</p>
                        </div>
                        <div class="card-icon icon-red">
                            <i class="bi bi-truck"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>


    <div class="row mt-4 g-4">

        <!-- =========================================================
            STOCK LEVELS BY CATEGORY
        ========================================================= -->

        <div class="col-lg-7">
            <div class="card section-card h-100">
                <div class="card-body p-4">

                    <h5 class="section-title mb-4">Stock Levels by Category</h5>

                    <?php if (empty($categoryStock)): ?>

                        <div class="empty-state">
                            No categories found.
                        </div>

                    <?php else: ?>

                        <?php foreach ($categoryStock as $cat): ?>

                            <?php
                            $qty = (int) $cat['total_qty'];
                            $barWidth = max(3, (int) round(($qty / $categoryMax) * 100));
                            ?>

                            <div class="category-row">
                                <div class="category-row-top">
                                    <span class="cat-name"><?= htmlspecialchars($cat['category_name']) ?></span>
                                    <span class="cat-qty"><?= number_format($qty) ?> units</span>
                                </div>
                                <div class="category-bar-track">
                                    <div class="category-bar-fill" style="width: <?= $barWidth ?>%;"></div>
                                </div>
                            </div>

                        <?php endforeach; ?>

                    <?php endif; ?>

                </div>
            </div>
        </div>


        <!-- =========================================================
            STOCK ALERTS DETAIL
        ========================================================= -->

        <div class="col-lg-5">
            <div class="card section-card h-100">
                <div class="card-body p-4">

                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h5 class="section-title mb-0">Needs Restocking</h5>
                        <span class="badge rounded-pill text-white" style="background:#00224c;">
                            <?= number_format($totalStockAlerts) ?>
                        </span>
                    </div>

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

                            <div class="alert-item">
                                <span class="alert-name">
                                    <span class="alert-icon <?= $isOut ? 'icon-red' : 'icon-yellow' ?>">
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
                                <span
                                    class="badge-status <?= $isOut ? 'bg-danger-subtle text-danger-emphasis' : 'bg-warning-subtle text-warning-emphasis' ?>">
                                    <?= $isOut ? 'Out of stock' : $qty . ' left' ?>
                                </span>
                            </div>

                        <?php endforeach; ?>

                    <?php endif; ?>

                </div>
            </div>
        </div>

    </div>


    <div class="row mt-4 g-4">

        <!-- =========================================================
            PENDING STOCK REQUESTS
        ========================================================= -->

        <div class="col-lg-7">
            <div class="card section-card h-100">
                <div class="card-body p-4">

                    <h5 class="section-title mb-4">Pending Stock Requests</h5>

                    <?php if (empty($stockRequestList)): ?>

                        <div class="empty-state">
                            No pending stock requests.
                        </div>

                    <?php else: ?>

                        <div class="table-responsive">
                            <table class="table inv-table mb-0">
                                <thead>
                                    <tr>
                                        <th>Request</th>
                                        <th>Reason</th>
                                        <th>Status</th>
                                        <th class="text-end">Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($stockRequestList as $request): ?>

                                        <?php
                                        $statusClass = match ($request['status']) {
                                            'Pending Finance', 'Pending Admin' => 'bg-warning-subtle text-warning-emphasis',
                                            'Finance Approved', 'Admin Approved' => 'bg-success-subtle text-success-emphasis',
                                            default => 'bg-secondary-subtle text-secondary-emphasis'
                                        };
                                        ?>

                                        <tr>
                                            <td>
                                                <div class="fw-semibold" style="color:#00224c;">
                                                    <?= htmlspecialchars($request['request_code']) ?>
                                                </div>
                                                <small class="text-muted">
                                                    <?= date('M j, Y', strtotime($request['created_at'])) ?>
                                                </small>
                                            </td>
                                            <td>
                                                <?= htmlspecialchars($request['reason'] ?: $request['expense_category']) ?>
                                            </td>
                                            <td>
                                                <span class="badge-status <?= $statusClass ?>">
                                                    <?= htmlspecialchars($request['status']) ?>
                                                </span>
                                            </td>
                                            <td class="text-end fw-semibold">
                                                ₱<?= number_format((float) $request['total_price'], 2) ?>
                                            </td>
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
            RECENTLY ADDED PRODUCTS
        ========================================================= -->

        <div class="col-lg-5">
            <div class="card section-card h-100">
                <div class="card-body p-4">

                    <h5 class="section-title mb-4">Recently Added Products</h5>

                    <?php if (empty($recentProducts)): ?>

                        <div class="empty-state">
                            No products yet.
                        </div>

                    <?php else: ?>

                        <?php foreach ($recentProducts as $product): ?>

                            <div class="alert-item">
                                <span class="alert-name">
                                    <span class="alert-icon icon-navy">
                                        <i class="bi bi-box-seam"></i>
                                    </span>
                                    <span>
                                        <?= htmlspecialchars($product['product_name']) ?>
                                        <br>
                                        <small class="text-muted">
                                            <?= htmlspecialchars($product['category_name'] ?? 'Uncategorized') ?>
                                            <?php if (!empty($product['supplier_name'])): ?>
                                                · <?= htmlspecialchars($product['supplier_name']) ?>
                                            <?php endif; ?>
                                        </small>
                                    </span>
                                </span>
                                <span class="text-muted small">
                                    <?= number_format((int) ($product['quantity'] ?? 0)) ?> in stock
                                </span>
                            </div>

                        <?php endforeach; ?>

                    <?php endif; ?>

                </div>
            </div>
        </div>

    </div>

</div>


<?php include("inventory_footer.php"); ?>