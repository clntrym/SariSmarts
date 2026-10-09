<?php

require_once("../init.php");
requireRole(['finance', 'admin']);
/*
| The owner reaches this too.
|
| The module belongs to finance; the business belongs to the owner, so they see
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
| an owner on it has no Finance people and no Finance to manage. Hiding the
| sidebar entry is presentation; this is what holds when the address is
| typed.
*/
requirePlanRole($conn, $companyId, 'finance', 'Finance');
/*
|--------------------------------------------------------------------------
| CREATE EXPENSE
|--------------------------------------------------------------------------
*/

if (isset($_POST['create_expense'])) {

    $expense_date = trim($_POST['expense_date'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $vendor = trim($_POST['vendor'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $amount = (float) ($_POST['amount'] ?? 0);
    $payment_method = trim($_POST['payment_method'] ?? '');

    $allowedPaymentMethods = ['Cash', 'GCash', 'Check', 'Bank Transfer'];


    /*
    |--------------------------------------------------------------------------
    | VALIDATION
    |--------------------------------------------------------------------------
    */

    if ($expense_date === '') {

        $_SESSION['alert'] = [
            "icon" => "error",
            "title" => "Validation Error",
            "text" => "Please select the expense date."
        ];

        header("Location: expenses.php");
        exit;
    }

    if ($category === '') {

        $_SESSION['alert'] = [
            "icon" => "error",
            "title" => "Validation Error",
            "text" => "Please enter a category."
        ];

        header("Location: expenses.php");
        exit;
    }

    if ($vendor === '') {

        $_SESSION['alert'] = [
            "icon" => "error",
            "title" => "Validation Error",
            "text" => "Please enter a vendor."
        ];

        header("Location: expenses.php");
        exit;
    }

    if ($amount <= 0) {

        $_SESSION['alert'] = [
            "icon" => "error",
            "title" => "Invalid Amount",
            "text" => "Amount must be greater than 0."
        ];

        header("Location: expenses.php");
        exit;
    }

    if (!in_array($payment_method, $allowedPaymentMethods, true)) {

        $_SESSION['alert'] = [
            "icon" => "error",
            "title" => "Invalid Payment Method",
            "text" => "Please select a valid payment method."
        ];

        header("Location: expenses.php");
        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | GENERATE EXPENSE CODE (EXP-YYYY-####)
    |--------------------------------------------------------------------------
    */

    $year = date('Y', strtotime($expense_date));

    $codeResult = $conn->query("
        SELECT expense_code
        FROM expenses
        WHERE expense_code LIKE 'EXP-{$year}-%'
          AND company_id = " . (int) $companyId . "
        ORDER BY expense_id DESC
        LIMIT 1
    ");

    $nextNumber = 1;

    if ($codeResult && $codeResult->num_rows > 0) {

        $lastCode = $codeResult->fetch_assoc()['expense_code'];

        if (preg_match('/EXP-' . $year . '-(\d+)/', $lastCode, $matches)) {
            $nextNumber = ((int) $matches[1]) + 1;
        }
    }

    $expense_code = 'EXP-' . $year . '-' . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);


    /*
    |--------------------------------------------------------------------------
    | CREATED BY
    |--------------------------------------------------------------------------
    */

    $created_by = (int) ($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);
    $created_by = $created_by > 0 ? $created_by : null;


    /*
    |--------------------------------------------------------------------------
    | INSERT
    |--------------------------------------------------------------------------
    */

    $stmt = $conn->prepare("
        INSERT INTO expenses
        (
            company_id,
            expense_code,
            expense_date,
            category,
            vendor,
            description,
            amount,
            payment_method,
            created_by
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    if (!$stmt) {

        $_SESSION['alert'] = [
            "icon" => "error",
            "title" => "Database Error",
            "text" => "Unable to prepare the expense record."
        ];

        header("Location: expenses.php");
        exit;
    }

    $stmt->bind_param(
        "isssssdsi",
        $companyId,
        $expense_code,
        $expense_date,
        $category,
        $vendor,
        $description,
        $amount,
        $payment_method,
        $created_by
    );

    if ($stmt->execute()) {

        $stmt->close();

        $_SESSION['alert'] = [
            "icon" => "success",
            "title" => "Expense Added",
            "text" => "Expense {$expense_code} has been recorded."
        ];

    } else {

        $stmt->close();

        $_SESSION['alert'] = [
            "icon" => "error",
            "title" => "Failed",
            "text" => "Unable to save the expense record."
        ];
    }

    header("Location: expenses.php");
    exit;
}


include includeRoleHeader(__DIR__, 'finance_header.php');


/*
|--------------------------------------------------------------------------
| ACCOUNTS PAYABLE SYNC
|--------------------------------------------------------------------------
|
| Non-inventory stock_request_items (product_id IS NULL — utility bills,
| services, anything without a linked product) become "payable" once
| their parent stock_requests row is at least Admin Approved. This
| INSERT is idempotent (NOT EXISTS guard) so it's safe to run on every
| page load — no cron/trigger needed. Inventory items are untouched;
| they keep flowing through the existing Received -> capital-deducted
| path further down.
|--------------------------------------------------------------------------
*/

$conn->query("
    INSERT INTO accounts_payable
        (company_id, request_id, item_id, invoice_no, po_number, supplier, category, description, amount, due_date, status)
    SELECT
        sr.company_id,
        sr.request_id,
        sri.item_id,
        CONCAT('INV-', YEAR(sr.created_at), '-', LPAD(sri.item_id, 4, '0')),
        sr.request_code,
        COALESCE(NULLIF(sri.vendor, ''), 'N/A'),
        COALESCE(NULLIF(sr.category_other, ''), sr.expense_category),
        COALESCE(NULLIF(sri.item_description, ''), sr.reason),
        sri.total_price,
        DATE_ADD(
            COALESCE(sr.admin_approved_at, sr.finance_approved_at, sr.created_at),
            INTERVAL 30 DAY
        ),
        'Pending'
    FROM stock_request_items sri
    INNER JOIN stock_requests sr ON sr.request_id = sri.request_id AND sr.company_id = sri.company_id
    WHERE sri.product_id IS NULL
      AND sr.status IN ('Admin Approved', 'Received')
      AND NOT EXISTS (
          SELECT 1 FROM accounts_payable ap
          WHERE ap.item_id = sri.item_id AND ap.company_id = sri.company_id
      )
");


/*
|--------------------------------------------------------------------------
| LOAD EXPENSES
|--------------------------------------------------------------------------
|
| Three sources feed this table:
|
| 1. Manual entries from the `expenses` table (the "New Expense" form).
|
| 2. INVENTORY line items from `stock_request_items` (product_id IS NOT
|    NULL), for requests that have reached status = 'Received' on their
|    parent `stock_requests` row — that's the point where finance_capital
|    actually gets deducted (see receive_deliveries.php), so it's the
|    point a stock request genuinely became a real expense. These are
|    fully settled the moment they appear here — no further action needed.
|
| 3. NON-INVENTORY line items (product_id IS NULL). These are NOT
|    auto-settled — they sit in `accounts_payable` (synced above) and
|    stay "Unpaid"/"Partial" until someone pays them from the dedicated
|    Accounts Payable page. Payment method shows their AP status instead
|    of a real payment method until fully paid. Paying/managing these is
|    intentionally NOT done from this page anymore — this table is
|    read-only for AP rows; the Receipt column links to the receipt PDF
|    once one exists.
|
| The `source` / `ap_*` columns below are discriminators used only to
| decide what the Payment Method / Receipt columns show — they aren't
| shown as their own table columns.
|
*/

$expenseRows = [];

$sql = "
    SELECT
        expense_code, expense_date, category, vendor, description, amount,
        payment_method, receipt_path, created_at,
        source, ap_id, ap_status, ap_paid_amount, ap_remaining, ap_receipt_path
    FROM (

        SELECT
            e.expense_code, e.expense_date, e.category, e.vendor, e.description, e.amount,
            e.payment_method, e.receipt_path, e.created_at,
            'manual' AS source, NULL AS ap_id, NULL AS ap_status,
            NULL AS ap_paid_amount, NULL AS ap_remaining, NULL AS ap_receipt_path
        FROM expenses e
        WHERE e.company_id = " . (int) $companyId . "

        UNION ALL

        SELECT
            sr.request_code AS expense_code,
            DATE(COALESCE(sr.received_at, sr.created_at)) AS expense_date,
            COALESCE(NULLIF(sr.category_other, ''), sr.expense_category) AS category,
            COALESCE(s.supplier_name, 'N/A') AS vendor,
            p.product_name AS description,
            sri.total_price AS amount,
            'Capital' AS payment_method,
            NULL AS receipt_path,
            sr.created_at,
            'stock' AS source, NULL AS ap_id, NULL AS ap_status,
            NULL AS ap_paid_amount, NULL AS ap_remaining, NULL AS ap_receipt_path
        FROM stock_requests sr
        INNER JOIN stock_request_items sri ON sri.request_id = sr.request_id AND sri.company_id = sr.company_id
        LEFT JOIN products p ON p.product_id = sri.product_id AND p.company_id = sr.company_id
        LEFT JOIN suppliers s ON s.supplier_id = p.supplier_id AND s.company_id = sr.company_id
        WHERE sr.status = 'Received'
          AND sri.product_id IS NOT NULL
          AND sr.company_id = " . (int) $companyId . "

        UNION ALL

        SELECT
            ap.invoice_no AS expense_code,
            DATE(ap.created_at) AS expense_date,
            ap.category,
            ap.supplier AS vendor,
            ap.description,
            ap.amount,
            ap.status AS payment_method,
            NULL AS receipt_path,
            ap.created_at,
            'ap' AS source,
            ap.ap_id,
            ap.status AS ap_status,
            ap.paid_amount AS ap_paid_amount,
            (ap.amount - ap.paid_amount) AS ap_remaining,
            r.file_path AS ap_receipt_path
        FROM accounts_payable ap
        LEFT JOIN (
            SELECT ap_id, company_id, MAX(receipt_id) AS latest_receipt_id
            FROM ap_receipts
            GROUP BY ap_id, company_id
        ) latest ON latest.ap_id = ap.ap_id AND latest.company_id = ap.company_id
        LEFT JOIN ap_receipts r ON r.receipt_id = latest.latest_receipt_id
        WHERE ap.company_id = " . (int) $companyId . "

    ) combined
    ORDER BY expense_date DESC, created_at DESC
";

$result = $conn->query($sql);

if ($result) {

    while ($row = $result->fetch_assoc()) {
        $expenseRows[] = $row;
    }

}


/*
|--------------------------------------------------------------------------
| DISTINCT CATEGORIES (for the filter dropdown + add-expense suggestions)
|--------------------------------------------------------------------------
*/

$categories = [];

$categoryResult = $conn->query("
    SELECT DISTINCT category FROM (
        SELECT category FROM expenses WHERE company_id = " . (int) $companyId . "
        UNION ALL
        SELECT COALESCE(NULLIF(category_other, ''), expense_category) AS category
        FROM stock_requests
        WHERE status = 'Received' AND company_id = " . (int) $companyId . "
        UNION ALL
        SELECT category FROM accounts_payable WHERE company_id = " . (int) $companyId . "
    ) combined_categories
    WHERE category IS NOT NULL
      AND category <> ''
    ORDER BY category ASC
");

if ($categoryResult) {

    while ($row = $categoryResult->fetch_assoc()) {
        $categories[] = $row['category'];
    }

}


/*
|--------------------------------------------------------------------------
| SUMMARY
|--------------------------------------------------------------------------
*/

$totalAmount = 0;
$totalTransactions = count($expenseRows);
$thisMonthAmount = 0;

$currentYearMonth = date('Y-m');

foreach ($expenseRows as $row) {

    $amount = (float) $row['amount'];

    $totalAmount += $amount;

    if (date('Y-m', strtotime($row['expense_date'])) === $currentYearMonth) {
        $thisMonthAmount += $amount;
    }
}

$averageAmount = $totalTransactions > 0
    ? $totalAmount / $totalTransactions
    : 0;

?>

<script>
    if (typeof tailwind === 'undefined') {
        document.write('<script src="https://cdn.tailwindcss.com"><\/script>');
    }
</script>

<style>
    .hidden { display: none !important; }
    .collapse.show { display: block !important; visibility: visible !important; }
    .sidebar .collapse { visibility: visible !important; }
    .sidebar .collapse:not(.show) { display: none !important; }
    .sidebar .collapse.show { display: block !important; visibility: visible !important; height: auto !important; }
    #employeeMenu .nav-link.active,
    .sidebar .nav-link.active {
        background: #fbbd23 !important;
        color: #000 !important;
        font-weight: bold !important;
    }
    .dashboard-card {
        border: none;
        border-radius: 18px;
        transition: .3s;
        overflow: hidden;
        box-shadow: 0 10px 25px rgba(0, 0, 0, .05);
    }

    .dashboard-card:hover {
        transform: translateY(-4px);
    }

    .card-icon {
        width: 55px;
        height: 55px;
        border-radius: 15px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 24px;
        color: #fff;
    }

    .bg-navy {
        background: #00224c;
    }

    .bg-yellow {
        background: #fbbd23;
    }

    .bg-green {
        background: #198754;
    }

    .bg-red {
        background: #dc3545;
    }

    .stat-number {
        font-size: 28px;
        font-weight: 700;
        color: #00224c;
    }

    .request-page {
        padding: 10px 4px 30px;
    }

    .request-title {
        color: #00224c;
        font-size: 34px;
        font-weight: 700;
        margin-bottom: 2px;
    }

    .request-subtitle {
        color: #64748b;
        margin-bottom: 20px;
    }

    .btn-new-expense {
        background: #00224c;
        color: #fff;
        border: none;
        border-radius: 10px;
        padding: 10px 18px;
        font-weight: 500;
    }

    .btn-new-expense:hover {
        background: #001a3a;
        color: #fff;
    }

    .ap-status-badge {
        display: inline-block;
        padding: 4px 12px;
        border-radius: 999px;
        font-size: 12px;
        font-weight: 600;
    }

    .ap-status-Paid { background: #dcfce7; color: #15803d; }
    .ap-status-Partial { background: #fef9c3; color: #a16207; }
    .ap-status-Pending { background: #fef3c7; color: #b45309; }

    .receipt-link {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        font-size: 13px;
        font-weight: 500;
        color: #00224c;
        text-decoration: none;
    }

    .receipt-link:hover {
        color: #001a3a;
        text-decoration: underline;
    }

    /* DataTable search styling */
    .dataTables_wrapper .dataTables_filter { float:none; text-align:left; padding:18px 18px 12px; }
    .dataTables_wrapper .dataTables_filter label { width:100%; font-size:0; }
    .dataTables_wrapper .dataTables_filter input { margin-left:0!important; width:430px; max-width:100%; height:43px; border:1px solid #d9e1e8; border-radius:22px; padding:0 18px; font-size:14px; outline:none; }
    .dataTables_wrapper .dataTables_filter input:focus { border-color:#00224c; box-shadow:0 0 0 3px rgba(0,34,76,.08); }
    .dataTables_wrapper .dt-layout-row:last-child { display:flex; align-items:center; justify-content:space-between; padding:14px 18px; }
</style>


<div class="w-full px-4 sm:px-6 lg:px-8 py-1">

    <!-- =========================================================
         PAGE HEADER
    ========================================================== -->

    <div class="d-flex justify-content-between align-items-start flex-wrap mb-0 gap-2">

        <div>

            <h1 class="request-title">
                Expenses
            </h1>

            <div class="request-subtitle">
                Expense records · includes received stock requests &amp; accounts payable · all categories
            </div>

        </div>


        <button type="button" class="btn btn-new-expense" data-bs-toggle="modal" data-bs-target="#newExpenseModal">

            <i class="bi bi-plus-lg me-1"></i>

            New Expense

        </button>

    </div>


    <!-- =========================================================
         SUMMARY CARDS
    ========================================================== -->

    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4 mb-5">


        <!-- TOTAL EXPENSES -->

        <div class="dashboard-card bg-white p-4">

            <div class="flex items-center justify-between">

                <div>

                    <div class="text-sm text-slate-500 mb-1">
                        Total Expenses
                    </div>

                    <div class="stat-number">
                        ₱<?= number_format($totalAmount, 2) ?>
                    </div>

                </div>

                <div class="card-icon bg-navy">

                    <i class="bi bi-receipt-cutoff"></i>

                </div>

            </div>

        </div>


        <!-- TRANSACTIONS -->

        <div class="dashboard-card bg-white p-4">

            <div class="flex items-center justify-between">

                <div>

                    <div class="text-sm text-slate-500 mb-1">
                        Transactions
                    </div>

                    <div class="stat-number">
                        <?= number_format($totalTransactions) ?>
                    </div>

                </div>

                <div class="card-icon bg-yellow">

                    <i class="bi bi-receipt"></i>

                </div>

            </div>

        </div>


        <!-- THIS MONTH -->

        <div class="dashboard-card bg-white p-4">

            <div class="flex items-center justify-between">

                <div>

                    <div class="text-sm text-slate-500 mb-1">
                        This Month
                    </div>

                    <div class="stat-number">
                        ₱<?= number_format($thisMonthAmount, 2) ?>
                    </div>

                </div>

                <div class="card-icon bg-green">

                    <i class="bi bi-calendar-check"></i>

                </div>

            </div>

        </div>


        <!-- AVERAGE PER EXPENSE -->

        <div class="dashboard-card bg-white p-4">

            <div class="flex items-center justify-between">

                <div>

                    <div class="text-sm text-slate-500 mb-1">
                        Average per Expense
                    </div>

                    <div class="stat-number">
                        ₱<?= number_format($averageAmount, 2) ?>
                    </div>

                </div>

                <div class="card-icon bg-red">

                    <i class="bi bi-graph-up"></i>

                </div>

            </div>

        </div>

    </div>


    <!-- =========================================================
         EXPENSE TABLE
    ========================================================== -->

    <div class="w-full rounded-xl border border-slate-200 bg-white shadow-sm overflow-hidden">


        <!-- =====================================================
             FILTERS / EXPORT — moved onto the search row by the
             shared DataTables theme (assets/js/datatable-theme.js)
        ====================================================== -->

        <div data-dt-toolbar="expenseTable">

            <div class="flex flex-col lg:flex-row lg:items-center gap-3">


                <!-- CATEGORY FILTER -->

                <select id="categoryFilter"
                    class="h-10 w-full lg:w-44 rounded-lg border border-slate-300 bg-white px-3 text-sm text-slate-700 focus:outline-none focus:border-[#00224c]">

                    <option value="">
                        All Category
                    </option>

                    <?php foreach ($categories as $category): ?>
                        <option value="<?= htmlspecialchars($category, ENT_QUOTES) ?>">
                            <?= htmlspecialchars($category) ?>
                        </option>
                    <?php endforeach; ?>

                </select>


                <!-- PAYMENT METHOD FILTER -->

                <select id="paymentFilter"
                    class="h-10 w-full lg:w-44 rounded-lg border border-slate-300 bg-white px-3 text-sm text-slate-700 focus:outline-none focus:border-[#00224c]">

                    <option value="">
                        All Payment
                    </option>

                    <option value="Cash">Cash</option>
                    <option value="GCash">GCash</option>
                    <option value="Check">Check</option>
                    <option value="Bank Transfer">Bank Transfer</option>
                    <option value="Capital">Capital (Stock Requests)</option>
                    <option value="Pending">Unpaid (Accounts Payable)</option>
                    <option value="Partial">Partially Paid (Accounts Payable)</option>
                    <option value="Paid">Paid (Accounts Payable)</option>

                </select>


                <!-- EXPORT DROPDOWN -->

                <div class="relative">

                    <button type="button" id="exportButton"
                        class="h-10 w-full lg:w-auto px-4 inline-flex items-center justify-center gap-2 rounded-lg border border-slate-300 bg-white text-sm font-medium text-slate-700 hover:bg-slate-50 transition">

                        <i class="bi bi-download"></i>

                        Export

                        <i class="bi bi-chevron-down text-xs"></i>

                    </button>


                    <!-- EXPORT MENU -->

                    <div id="exportMenu"
                        class="hidden absolute right-0 top-full mt-2 w-44 bg-white border border-slate-200 rounded-lg shadow-lg z-50 overflow-hidden">

                        <button type="button" id="exportExcel"
                            class="w-full px-4 py-3 flex items-center gap-3 text-sm text-slate-700 hover:bg-slate-50 text-left">

                            <i class="bi bi-file-earmark-excel text-emerald-600"></i>

                            Excel

                        </button>


                        <button type="button" id="exportPdf"
                            class="w-full px-4 py-3 flex items-center gap-3 text-sm text-slate-700 hover:bg-slate-50 text-left">

                            <i class="bi bi-file-earmark-pdf text-red-600"></i>

                            PDF

                        </button>


                        <button type="button" id="printExpenses"
                            class="w-full px-4 py-3 flex items-center gap-3 text-sm text-slate-700 hover:bg-slate-50 text-left">

                            <i class="bi bi-printer text-[#00224c]"></i>

                            Print

                        </button>

                    </div>

                </div>

            </div>

        </div>


        <!-- =====================================================
             TABLE
        ====================================================== -->

        <div class="overflow-x-auto">

            <table id="expenseTable" class="w-full text-sm min-w-[1200px]" style="width:100%">

                <thead class="bg-white border-b border-slate-200">

                    <tr class="text-left text-sm text-slate-900">

                        <th class="px-6 py-4 font-medium">
                            #
                        </th>

                        <th class="px-6 py-4 font-medium whitespace-nowrap">
                            Expense No.
                        </th>

                        <th class="px-6 py-4 font-medium whitespace-nowrap">
                            Date
                        </th>

                        <th class="px-6 py-4 font-medium whitespace-nowrap">
                            Category
                        </th>

                        <th class="px-6 py-4 font-medium whitespace-nowrap">
                            Vendor
                        </th>

                        <th class="px-6 py-4 font-medium whitespace-nowrap">
                            Description
                        </th>

                        <th class="px-6 py-4 font-medium whitespace-nowrap">
                            Amount
                        </th>

                        <th class="px-6 py-4 font-medium whitespace-nowrap">
                            Payment Method
                        </th>

                        <th class="px-6 py-4 font-medium whitespace-nowrap">
                            Receipt
                        </th>

                    </tr>

                </thead>


                <tbody id="expenseTableBody">

                    <?php if (count($expenseRows) > 0): ?>

                        <?php foreach ($expenseRows as $index => $row): ?>

                            <?php

                            $searchText = strtolower(
                                $row['expense_code']
                                . ' '
                                . $row['category']
                                . ' '
                                . $row['vendor']
                                . ' '
                                . ($row['description'] ?? '')
                                . ' '
                                . $row['payment_method']
                            );

                            $source = $row['source'];

                            /*
                            |--------------------------------------------------------------------------
                            | RECEIPT — manual expenses use expenses.receipt_path, paid AP invoices
                            | use the linked ap_receipts.file_path. Stock-settled rows never have one.
                            |--------------------------------------------------------------------------
                            */

                            $receiptPath = null;

                            if ($source === 'manual' && !empty($row['receipt_path'])) {
                                $receiptPath = $row['receipt_path'];
                            } elseif ($source === 'ap' && !empty($row['ap_receipt_path'])) {
                                $receiptPath = $row['ap_receipt_path'];
                            }

                            ?>

                            <tr class="expense-row border-b border-slate-200 hover:bg-slate-50 transition"
                                data-search="<?= htmlspecialchars($searchText, ENT_QUOTES) ?>"
                                data-category="<?= htmlspecialchars($row['category'], ENT_QUOTES) ?>"
                                data-payment="<?= htmlspecialchars($row['payment_method'], ENT_QUOTES) ?>">


                                <!-- NUMBER -->

                                <td class="px-6 py-4 text-slate-500 row-number">
                                    <?= $index + 1 ?>
                                </td>


                                <!-- EXPENSE NO. -->

                                <td class="px-6 py-4">

                                    <span class="font-medium text-slate-900 whitespace-nowrap">
                                        <?= htmlspecialchars($row['expense_code']) ?>
                                    </span>

                                </td>


                                <!-- DATE -->

                                <td class="px-6 py-4 text-slate-600 whitespace-nowrap">

                                    <?= date('M d, Y', strtotime($row['expense_date'])) ?>

                                </td>


                                <!-- CATEGORY -->

                                <td class="px-6 py-4 text-slate-700 whitespace-nowrap">

                                    <?= htmlspecialchars($row['category']) ?>

                                </td>


                                <!-- VENDOR -->

                                <td class="px-6 py-4 text-slate-700 whitespace-nowrap">

                                    <?= htmlspecialchars($row['vendor']) ?>

                                </td>


                                <!-- DESCRIPTION -->

                                <td class="px-6 py-4 text-slate-600">

                                    <div class="max-w-[260px] truncate" title="<?= htmlspecialchars($row['description'] ?? '') ?>">

                                        <?= htmlspecialchars($row['description'] ?: '—') ?>

                                    </div>

                                </td>


                                <!-- AMOUNT -->

                                <td class="px-6 py-4 font-semibold text-slate-900 whitespace-nowrap">

                                    ₱<?= number_format((float) $row['amount'], 2) ?>

                                </td>


                                <!-- PAYMENT METHOD -->

                                <td class="px-6 py-4 text-slate-700 whitespace-nowrap">

                                    <?php if ($source === 'ap'): ?>

                                        <span class="ap-status-badge ap-status-<?= htmlspecialchars($row['ap_status']) ?>">
                                            <?= $row['ap_status'] === 'Pending' ? 'Unpaid' : htmlspecialchars($row['ap_status']) ?>
                                        </span>

                                    <?php else: ?>

                                        <?= htmlspecialchars($row['payment_method']) ?>

                                    <?php endif; ?>

                                </td>


                                <!-- RECEIPT -->

                                <td class="px-6 py-4 whitespace-nowrap">

                                    <?php if ($receiptPath): ?>

                                        <a href="../<?= htmlspecialchars($receiptPath) ?>" target="_blank" class="receipt-link">
                                            <i class="bi bi-file-earmark-pdf text-red-600"></i>
                                            View
                                        </a>

                                    <?php else: ?>

                                        <span class="text-slate-400 text-sm">—</span>

                                    <?php endif; ?>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <tr id="emptyDatabaseRow">

                            <td colspan="9" class="px-6 py-14 text-center text-slate-500">

                                <i class="bi bi-receipt-cutoff text-4xl block mb-3"></i>

                                <p class="font-medium text-slate-700">
                                    No expense records found
                                </p>

                                <p class="text-sm mt-1">
                                    Click "New Expense" to record your first expense.
                                </p>

                            </td>

                        </tr>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>


    </div>

</div>


<!-- =========================================================
     NEW EXPENSE MODAL
========================================================= -->

<div class="modal fade" id="newExpenseModal" tabindex="-1" aria-hidden="true">

    <div class="modal-dialog modal-lg modal-dialog-centered">

        <div class="modal-content">

            <div class="modal-header">

                <div>

                    <h5 class="modal-title fw-bold">

                        <i class="bi bi-receipt-cutoff me-2"></i>

                        New Expense

                    </h5>

                    <small class="text-muted">

                        Record a new expense transaction.

                    </small>

                </div>

                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>

            </div>


            <form method="POST" action="expenses.php">

                <div class="modal-body">

                    <div class="row g-3">

                        <!-- DATE -->
                        <div class="col-md-6">

                            <label class="form-label">
                                Expense Date
                                <span class="text-danger">*</span>
                            </label>

                            <input type="date" name="expense_date" class="form-control"
                                value="<?= date('Y-m-d') ?>" required>

                        </div>


                        <!-- AMOUNT -->
                        <div class="col-md-6">

                            <label class="form-label">
                                Amount
                                <span class="text-danger">*</span>
                            </label>

                            <div class="input-group">

                                <span class="input-group-text">₱</span>

                                <input type="number" name="amount" class="form-control" min="0.01" step="0.01"
                                    placeholder="0.00" required>

                            </div>

                        </div>


                        <!-- CATEGORY -->
                        <div class="col-md-6">

                            <label class="form-label">
                                Category
                                <span class="text-danger">*</span>
                            </label>

                            <input type="text" name="category" class="form-control" list="categorySuggestions"
                                placeholder="e.g. Utilities, Rent, Marketing" required>

                            <datalist id="categorySuggestions">
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?= htmlspecialchars($category, ENT_QUOTES) ?>">
                                <?php endforeach; ?>
                            </datalist>

                        </div>


                        <!-- VENDOR -->
                        <div class="col-md-6">

                            <label class="form-label">
                                Vendor
                                <span class="text-danger">*</span>
                            </label>

                            <input type="text" name="vendor" class="form-control" placeholder="Enter vendor name"
                                required>

                        </div>


                        <!-- PAYMENT METHOD -->
                        <div class="col-md-6">

                            <label class="form-label">
                                Payment Method
                                <span class="text-danger">*</span>
                            </label>

                            <select name="payment_method" class="form-select" required>

                                <option value="">
                                    Select Payment Method
                                </option>

                                <option value="Cash">Cash</option>
                                <option value="GCash">GCash</option>
                                <option value="Check">Check</option>
                                <option value="Bank Transfer">Bank Transfer</option>

                            </select>

                        </div>


                        <!-- DESCRIPTION -->
                        <div class="col-12">

                            <label class="form-label">
                                Description
                            </label>

                            <textarea name="description" class="form-control" rows="3" maxlength="255"
                                placeholder="Optional notes about this expense..."></textarea>

                        </div>

                    </div>

                </div>


                <div class="modal-footer">

                    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">

                        Cancel

                    </button>

                    <button type="submit" name="create_expense" class="btn text-white px-4"
                        style="background:#00224c;">

                        <i class="bi bi-check-lg me-1"></i>

                        Save Expense

                    </button>

                </div>

            </form>

        </div>

    </div>

</div>


<!-- =========================================================
     JAVASCRIPT
========================================================= -->

<script>

    document.addEventListener("DOMContentLoaded", function () {


        /*
        |--------------------------------------------------------------------------
        | DATATABLE INITIALIZATION
        |--------------------------------------------------------------------------
        */

        const categoryFilter =
            document.getElementById("categoryFilter");

        const paymentFilter =
            document.getElementById("paymentFilter");

        const exportButton =
            document.getElementById("exportButton");

        const exportMenu =
            document.getElementById("exportMenu");

        const exportExcel =
            document.getElementById("exportExcel");

        const exportPdf =
            document.getElementById("exportPdf");

        const printButton =
            document.getElementById("printExpenses");


        var expenseDT = new DataTable("#expenseTable", {
            pageLength: 10,
            lengthChange: false,
            ordering: true,
            order: [],
            columnDefs: [{ orderable: false, targets: -1 }],
            language: {
                search: "",
                searchPlaceholder: "Search expenses...",
                info: "Showing _START_ to _END_ of _TOTAL_",
                paginate: { previous: "Previous", next: "Next" }
            }
        });


        /*
        |--------------------------------------------------------------------------
        | SIDEBAR FILTER -> DATATABLE CUSTOM FILTER
        |--------------------------------------------------------------------------
        */

        DataTable.ext.search.push(function (settings, data, dataIndex) {

            if (settings.nTable.id !== "expenseTable") {
                return true;
            }

            var row = settings.aoData[dataIndex].nTr;

            var selectedCategory = categoryFilter.value;
            var selectedPayment = paymentFilter.value;

            if (selectedCategory !== "") {
                var rowCategory = row.getAttribute("data-category") || "";
                if (rowCategory !== selectedCategory) {
                    return false;
                }
            }

            if (selectedPayment !== "") {
                var rowPayment = row.getAttribute("data-payment") || "";
                if (rowPayment !== selectedPayment) {
                    return false;
                }
            }

            return true;
        });

        categoryFilter.addEventListener("change", function () {
            expenseDT.draw();
        });

        paymentFilter.addEventListener("change", function () {
            expenseDT.draw();
        });


        /*
        |--------------------------------------------------------------------------
        | EXPORT DROPDOWN
        |--------------------------------------------------------------------------
        */

        exportButton.addEventListener("click", function (event) {

            event.stopPropagation();

            exportMenu.classList.toggle("hidden");

        });


        document.addEventListener("click", function (event) {

            if (
                !exportMenu.contains(event.target)
                &&
                !exportButton.contains(event.target)
            ) {

                exportMenu.classList.add("hidden");

            }

        });


        function getExportRows() {
            return Array.from(expenseDT.rows({ search: "applied" }).nodes());
        }


        /*
        |--------------------------------------------------------------------------
        | EXPORT EXCEL
        |--------------------------------------------------------------------------
        */

        exportExcel.addEventListener("click", function () {

            exportMenu.classList.add("hidden");

            const filteredRows = getExportRows();

            if (filteredRows.length === 0) {
                alert("There are no expense records to export.");
                return;
            }

            let html = `
                <html>
                <head>
                    <meta charset="UTF-8">
                    <title>RetailCore Expense Report</title>
                </head>
                <body>
                    <h2>RetailCore Expense Report</h2>
                    <p>Generated: ${new Date().toLocaleString()}</p>
                    <table border="1">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Expense No.</th>
                                <th>Date</th>
                                <th>Category</th>
                                <th>Vendor</th>
                                <th>Description</th>
                                <th>Amount</th>
                                <th>Payment Method</th>
                            </tr>
                        </thead>
                        <tbody>
            `;

            filteredRows.forEach(function (row) {

                const cells = row.querySelectorAll("td");

                html += "<tr>";

                cells.forEach(function (cell, cellIndex) {

                    // Skip the Receipt column (last, index 8) since it's a link, not data
                    if (cellIndex === 8) {
                        return;
                    }

                    html += `<td>${cell.innerText.trim().replace(/\s+/g, " ")}</td>`;

                });

                html += "</tr>";

            });

            html += `
                        </tbody>
                    </table>
                </body>
                </html>
            `;

            const blob = new Blob([html], { type: "application/vnd.ms-excel" });
            const url = URL.createObjectURL(blob);

            const link = document.createElement("a");
            link.href = url;
            link.download = "retailcore_expenses.xls";
            link.click();

            URL.revokeObjectURL(url);

        });


        /*
        |--------------------------------------------------------------------------
        | PRINT / PDF
        |--------------------------------------------------------------------------
        */

        function openPrintWindow() {

            const filteredRows = getExportRows();

            if (filteredRows.length === 0) {
                alert("There are no expense records to print.");
                return;
            }

            let tableRows = "";

            filteredRows.forEach(function (row) {

                const cells = row.querySelectorAll("td");

                tableRows += "<tr>";

                cells.forEach(function (cell, cellIndex) {

                    if (cellIndex === 8) {
                        return;
                    }

                    tableRows += `<td>${cell.innerText.trim().replace(/\s+/g, " ")}</td>`;

                });

                tableRows += "</tr>";

            });

            const printWindow = window.open("", "", "width=1400,height=900");

            if (!printWindow) {
                alert("Please allow pop-ups for this page.");
                return;
            }

            printWindow.document.write(`
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset="UTF-8">
                <title>RetailCore Expense Report</title>
                <style>
                    * { box-sizing: border-box; }
                    body { font-family: Arial, sans-serif; padding: 30px; color: #111827; }
                    h1 { margin: 0; color: #00224c; font-size: 24px; }
                    p { color: #64748b; margin-top: 5px; }
                    table { width: 100%; border-collapse: collapse; margin-top: 25px; font-size: 11px; }
                    th { background: #f1f5f9; color: #0f172a; font-weight: 600; }
                    th, td { border: 1px solid #cbd5e1; padding: 8px; text-align: left; }
                    .report-header { margin-bottom: 20px; }
                    @media print {
                        body { padding: 10px; }
                        @page { size: landscape; margin: 10mm; }
                    }
                </style>
            </head>
            <body>
                <div class="report-header">
                    <h1>RetailCore Expense Report</h1>
                    <p>Generated: ${new Date().toLocaleString()}</p>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Expense No.</th>
                            <th>Date</th>
                            <th>Category</th>
                            <th>Vendor</th>
                            <th>Description</th>
                            <th>Amount</th>
                            <th>Payment Method</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${tableRows}
                    </tbody>
                </table>
            </body>
            </html>
        `);

            printWindow.document.close();
            printWindow.focus();

            setTimeout(function () {
                printWindow.print();
                printWindow.close();
            }, 300);

        }


        printButton.addEventListener("click", function () {
            exportMenu.classList.add("hidden");
            openPrintWindow();
        });

        exportPdf.addEventListener("click", function () {
            exportMenu.classList.add("hidden");
            openPrintWindow();
        });

    });

</script>


<!-- =========================================================
     SESSION ALERT
========================================================= -->

<?php

if (isset($_SESSION['alert'])):

    $alert = $_SESSION['alert'];

    unset($_SESSION['alert']);

    ?>

    <script>

        Swal.fire({

            icon:
                <?= json_encode($alert['icon']) ?>,

            title:
                <?= json_encode($alert['title']) ?>,

            <?php if (!empty($alert['html'])): ?>
            html:
                <?= json_encode($alert['html']) ?>,
            <?php else: ?>
            text:
                <?= json_encode($alert['text'] ?? '') ?>,
            <?php endif; ?>

            confirmButtonColor:
                "#00224c"

        });

    </script>

<?php endif; ?>


<?php include includeRoleFooter(__DIR__, 'finance_footer.php'); ?>