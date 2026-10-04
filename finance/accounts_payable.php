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

$allowedReturns = ['expenses.php', 'accounts_payable.php'];


/*
|--------------------------------------------------------------------------
| PAY INVOICE
|--------------------------------------------------------------------------
|
| Handles the "Pay" form submitted from either this page or expenses.php
| (the hidden `return_to` field decides where the user lands afterward).
| Validates the amount, records the payment, updates the invoice balance,
| generates a PDF receipt on disk, and logs it in ap_receipts.
|
*/

if (isset($_POST['pay_invoice'])) {

    $ap_id = (int) ($_POST['ap_id'] ?? 0);
    $amount_paid = (float) ($_POST['amount_paid'] ?? 0);
    $payment_method = trim($_POST['payment_method'] ?? '');
    $reference_no = trim($_POST['reference_no'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $return_to = trim($_POST['return_to'] ?? '');

    if (!in_array($return_to, $allowedReturns, true)) {
        $return_to = 'accounts_payable.php';
    }

    $allowedMethods = ['Cash', 'GCash', 'Check', 'Bank Transfer'];


    /*
    |--------------------------------------------------------------------------
    | VALIDATION
    |--------------------------------------------------------------------------
    */

    if ($ap_id <= 0) {

        $_SESSION['alert'] = ["icon" => "error", "title" => "Invalid Invoice", "text" => "That invoice could not be found."];
        header("Location: {$return_to}");
        exit;
    }

    if ($amount_paid <= 0) {

        $_SESSION['alert'] = ["icon" => "error", "title" => "Invalid Amount", "text" => "Amount must be greater than 0."];
        header("Location: {$return_to}");
        exit;
    }

    if (!in_array($payment_method, $allowedMethods, true)) {

        $_SESSION['alert'] = ["icon" => "error", "title" => "Invalid Payment Method", "text" => "Please select a valid payment method."];
        header("Location: {$return_to}");
        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | LOOK UP THE INVOICE (plain read — no lock yet; the actual write only
    | happens inside processApPayment(), which locks the row itself)
    |--------------------------------------------------------------------------
    */

    $apLookup = $conn->prepare("SELECT * FROM accounts_payable WHERE ap_id = ? AND company_id = ? LIMIT 1");
    $apLookup->bind_param("ii", $ap_id, $companyId);
    $apLookup->execute();
    $apRow = $apLookup->get_result()->fetch_assoc();
    $apLookup->close();

    if (!$apRow) {

        $_SESSION['alert'] = ["icon" => "error", "title" => "Invalid Invoice", "text" => "That invoice could not be found."];
        header("Location: {$return_to}");
        exit;
    }

    $remainingBalance = round((float) $apRow['amount'] - (float) $apRow['paid_amount'], 2);

    if ($apRow['status'] === 'Paid' || $remainingBalance <= 0) {

        $_SESSION['alert'] = ["icon" => "error", "title" => "Already Paid", "text" => "This invoice is already fully paid."];
        header("Location: {$return_to}");
        exit;
    }

    if ($amount_paid > $remainingBalance + 0.01) {

        $_SESSION['alert'] = [
            "icon" => "error",
            "title" => "Invalid Amount",
            "text" => "Amount exceeds the remaining balance of ₱" . number_format($remainingBalance, 2) . "."
        ];
        header("Location: {$return_to}");
        exit;
    }

    $paid_by = (int) ($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);
    $paid_by = $paid_by > 0 ? $paid_by : null;


    /*
    |--------------------------------------------------------------------------
    | GCASH / BANK TRANSFER -> PAYMONGO
    |--------------------------------------------------------------------------
    |
    | Nothing is recorded here. This only creates a PayMongo Checkout
    | Session and sends the browser to PayMongo's hosted payment page.
    | The payment only actually gets recorded once PayMongo confirms it
    | on the way back, in paymongo_callback.php — see that file.
    |
    */

    if (in_array($payment_method, ['GCash', 'Bank Transfer'], true)) {

        if (!function_exists('curl_init')) {

            $_SESSION['alert'] = [
                "icon" => "error",
                "title" => "PayMongo Unavailable",
                "text" => "The PHP cURL extension isn't enabled, so online payments can't be started. " .
                    "Enable it in php.ini, or pay this invoice with Cash/Check instead."
            ];

            header("Location: {$return_to}");
            exit;
        }

        // GCash is its own payment method type; "Bank Transfer" covers PayMongo's
        // Direct Online Banking options (customer picks their bank on PayMongo's page)
        $paymentMethodTypes = $payment_method === 'GCash'
            ? ['gcash']
            : ['dob', 'brankasbdo', 'brankaslandbank', 'brankasmetrobank'];

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $baseUrl = $scheme . '://' . $_SERVER['HTTP_HOST']
            . rtrim(str_replace('\\', '/', dirname($_SERVER['PHP_SELF'])), '/');

        /*
        |--------------------------------------------------------------------------
        | OUR OWN REFERENCE TOKEN
        |--------------------------------------------------------------------------
        | PayMongo does NOT substitute a {CHECKOUT_SESSION_ID}-style placeholder
        | into success_url — that's a Stripe convention, not a PayMongo one.
        | PayMongo's own integration guide instead says to store the Checkout
        | Session's id yourself so you can look it up later. So: generate our
        | own token, save a pending row keyed by it, put the token (not the
        | PayMongo id, which doesn't exist yet) in success_url, then fill in
        | the real session id once PayMongo hands it back below.
        |--------------------------------------------------------------------------
        */

        $token = bin2hex(random_bytes(20));

        $insertPending = $conn->prepare("
            INSERT INTO paymongo_sessions (company_id, token, created_at)
            VALUES (?, ?, NOW())
        ");
        $insertPending->bind_param("is", $companyId, $token);
        $insertPending->execute();
        $insertPending->close();

        $checkoutPayload = [
            "data" => [
                "attributes" => [
                    "send_email_receipt" => false,
                    "show_line_items" => true,
                    "line_items" => [
                        [
                            "name" => "Invoice " . $apRow['invoice_no'],
                            "amount" => (int) round($amount_paid * 100),
                            "currency" => "PHP",
                            "quantity" => 1,
                        ]
                    ],
                    "payment_method_types" => $paymentMethodTypes,
                    "description" => "Payment for " . $apRow['supplier'] . " - " . $apRow['invoice_no'],
                    "success_url" => $baseUrl . "/paymongo_callback.php?ref=" . $token,
                    "cancel_url" => $baseUrl . "/{$return_to}",
                    "reference_number" => $token,
                    "metadata" => [
                        "ap_id" => (string) $ap_id,
                        "amount_paid" => (string) $amount_paid,
                        "payment_method" => $payment_method,
                        "reference_no" => $reference_no,
                        "notes" => $notes,
                        "paid_by" => (string) ($paid_by ?? 0),
                        "return_to" => $return_to,
                    ],
                ],
            ],
        ];

        $ch = curl_init("https://api.paymongo.com/v1/checkout_sessions");

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($checkoutPayload),
            CURLOPT_HTTPHEADER => [
                "Content-Type: application/json",
                "Authorization: Basic " . base64_encode(PAYMONGO_SECRET_KEY . ":"),
            ],
        ]);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        $decoded = $response ? json_decode($response, true) : null;
        $sessionId = $decoded['data']['id'] ?? null;
        $checkoutUrl = $decoded['data']['attributes']['checkout_url'] ?? null;

        if ($curlError || !$checkoutUrl || !$sessionId) {

            $errorDetail = $decoded['errors'][0]['detail']
                ?? ($curlError ?: 'Unknown error creating the PayMongo checkout session.');

            // clean up the pending row since this session never actually got created
            $cleanup = $conn->prepare("DELETE FROM paymongo_sessions WHERE token = ?");
            $cleanup->bind_param("s", $token);
            $cleanup->execute();
            $cleanup->close();

            $_SESSION['alert'] = [
                "icon" => "error",
                "title" => "Unable to Start Payment",
                "text" => "PayMongo checkout could not be started: " . $errorDetail
            ];

            header("Location: {$return_to}");
            exit;
        }

        // now that PayMongo has given us the real session id, save it against our token
        $fillIn = $conn->prepare("
            UPDATE paymongo_sessions
            SET session_id = ?, checkout_url = ?
            WHERE token = ? AND company_id = ?
        ");
        $fillIn->bind_param("sssi", $sessionId, $checkoutUrl, $token, $companyId);
        $fillIn->execute();
        $fillIn->close();

        header("Location: " . $checkoutUrl);
        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | CASH / CHECK -> RECORD IMMEDIATELY (same helper the PayMongo callback
    | uses, so both paths produce an identical payment + receipt)
    |--------------------------------------------------------------------------
    */

    require_once(__DIR__ . '/includes/process_ap_payment.php');

    $result = processApPayment($conn, $ap_id, $amount_paid, $payment_method, $reference_no, $notes, $paid_by, $companyId);

    if ($result['success']) {

        $_SESSION['alert'] = [
            "icon" => "success",
            "title" => "Payment Recorded",
            "html" => "Payment for <b>" . htmlspecialchars($result['invoice_no']) . "</b> was recorded successfully."
                . "<br><br><a href='../{$result['relative_path']}' target='_blank' class='btn btn-sm text-white' style='background:#00224c;'>"
                . "<i class='bi bi-file-earmark-pdf me-1'></i> Download Receipt</a>",
        ];

    } else {

        $_SESSION['alert'] = [
            "icon" => "error",
            "title" => "Payment Failed",
            "text" => $result['message'],
        ];
    }

    header("Location: {$return_to}");
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
| their parent stock_requests row is at least Admin Approved. Idempotent
| (NOT EXISTS guard) — safe on every page load. Inventory items are
| untouched; they keep settling automatically via capital on Receive.
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
      AND sr.company_id = " . (int) $companyId . "
      AND NOT EXISTS (
          SELECT 1 FROM accounts_payable ap
          WHERE ap.item_id = sri.item_id AND ap.company_id = sri.company_id
      )
");


/*
|--------------------------------------------------------------------------
| LOAD ACCOUNTS PAYABLE
|--------------------------------------------------------------------------
*/

$apRows = [];

$sql = "
    SELECT
        ap.ap_id,
        ap.invoice_no,
        ap.po_number,
        ap.supplier,
        ap.category,
        ap.description,
        ap.amount,
        ap.paid_amount,
        (ap.amount - ap.paid_amount) AS remaining,
        ap.due_date,
        ap.status,
        ap.created_at
    FROM accounts_payable ap
    WHERE ap.company_id = " . (int) $companyId . "
    ORDER BY
        (ap.status != 'Paid') DESC,
        ap.due_date ASC,
        ap.created_at DESC
";

$result = $conn->query($sql);

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $apRows[] = $row;
    }
}


/*
|--------------------------------------------------------------------------
| PAYMENT HISTORY (grouped by ap_id, pre-rendered — no AJAX needed)
|--------------------------------------------------------------------------
*/

$paymentsByAp = [];

$payResult = $conn->query("
    SELECT
        p.payment_id,
        p.ap_id,
        p.amount_paid,
        p.payment_method,
        p.reference_no,
        p.paid_at,
        u.fullname AS paid_by_name,
        r.file_path AS receipt_path,
        r.receipt_code
    FROM ap_payments p
    LEFT JOIN users u ON u.user_id = p.paid_by
    LEFT JOIN ap_receipts r ON r.payment_id = p.payment_id AND r.company_id = p.company_id
    WHERE p.company_id = " . (int) $companyId . "
    ORDER BY p.paid_at DESC
");

if ($payResult) {
    while ($row = $payResult->fetch_assoc()) {
        $paymentsByAp[$row['ap_id']][] = $row;
    }
}


/*
|--------------------------------------------------------------------------
| SUMMARY
|--------------------------------------------------------------------------
*/

$totalPayable = 0;
$totalPaid = 0;
$totalRemaining = 0;
$overdueCount = 0;

$today = date('Y-m-d');

foreach ($apRows as $row) {

    $totalPayable += (float) $row['amount'];
    $totalPaid += (float) $row['paid_amount'];
    $totalRemaining += (float) $row['remaining'];

    if ($row['status'] !== 'Paid' && $row['due_date'] < $today) {
        $overdueCount++;
    }
}

$highlightApId = (int) ($_GET['ap'] ?? 0);

?>

<style>
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

    .ap-status-badge {
        display: inline-block;
        padding: 4px 12px;
        border-radius: 999px;
        font-size: 12px;
        font-weight: 600;
    }

    .ap-status-Paid {
        background: #dcfce7;
        color: #15803d;
    }

    .ap-status-Partial {
        background: #fef9c3;
        color: #a16207;
    }

    .ap-status-Pending {
        background: #fef3c7;
        color: #b45309;
    }

    .ap-status-Overdue {
        background: #fee2e2;
        color: #b91c1c;
    }

    .ap-row-highlight {
        animation: apHighlightFade 2.5s ease-out;
    }

    @keyframes apHighlightFade {
        0% {
            background-color: #fef3c7;
        }

        100% {
            background-color: transparent;
        }
    }

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
                Supplier Invoices
            </h1>

            <div class="request-subtitle">
                Purchase orders, due dates, and balances
            </div>

        </div>

        <a href="expenses.php" class="btn btn-light border" style="border-radius:10px;">
            <i class="bi bi-receipt-cutoff me-1"></i>
            Back to Expenses
        </a>

    </div>


    <!-- =========================================================
         SUMMARY CARDS
    ========================================================== -->

    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4 mb-5">

        <div class="dashboard-card bg-white p-4">
            <div class="flex items-center justify-between">
                <div>
                    <div class="text-sm text-slate-500 mb-1">Total Payable</div>
                    <div class="stat-number">₱<?= number_format($totalPayable, 2) ?></div>
                </div>
                <div class="card-icon bg-navy"><i class="bi bi-receipt-cutoff"></i></div>
            </div>
        </div>

        <div class="dashboard-card bg-white p-4">
            <div class="flex items-center justify-between">
                <div>
                    <div class="text-sm text-slate-500 mb-1">Total Paid</div>
                    <div class="stat-number">₱<?= number_format($totalPaid, 2) ?></div>
                </div>
                <div class="card-icon bg-green"><i class="bi bi-check-circle"></i></div>
            </div>
        </div>

        <div class="dashboard-card bg-white p-4">
            <div class="flex items-center justify-between">
                <div>
                    <div class="text-sm text-slate-500 mb-1">Outstanding Balance</div>
                    <div class="stat-number">₱<?= number_format($totalRemaining, 2) ?></div>
                </div>
                <div class="card-icon bg-yellow"><i class="bi bi-hourglass-split"></i></div>
            </div>
        </div>

        <div class="dashboard-card bg-white p-4">
            <div class="flex items-center justify-between">
                <div>
                    <div class="text-sm text-slate-500 mb-1">Overdue Invoices</div>
                    <div class="stat-number"><?= number_format($overdueCount) ?></div>
                </div>
                <div class="card-icon bg-red"><i class="bi bi-exclamation-triangle"></i></div>
            </div>
        </div>

    </div>


    <!-- =========================================================
         TABLE
    ========================================================== -->

    <div class="w-full rounded-xl border border-slate-200 bg-white shadow-sm overflow-hidden">

        <!-- FILTERS / EXPORT — moved onto the search row by the shared
             DataTables theme (assets/js/datatable-theme.js) -->

        <div data-dt-toolbar="apTable">

            <div class="flex flex-col lg:flex-row lg:items-center gap-3">

                <select id="apStatusFilter"
                    class="h-10 w-full lg:w-44 rounded-lg border border-slate-300 bg-white px-3 text-sm text-slate-700 focus:outline-none focus:border-[#00224c]">
                    <option value="">All Status</option>
                    <option value="Pending">Pending</option>
                    <option value="Partial">Partial</option>
                    <option value="Paid">Paid</option>
                    <option value="Overdue">Overdue</option>
                </select>

                <div class="relative">

                    <button type="button" id="apExportButton"
                        class="h-10 w-full lg:w-auto px-4 inline-flex items-center justify-center gap-2 rounded-lg border border-slate-300 bg-white text-sm font-medium text-slate-700 hover:bg-slate-50 transition">
                        <i class="bi bi-download"></i> Export <i class="bi bi-chevron-down text-xs"></i>
                    </button>

                    <div id="apExportMenu"
                        class="hidden absolute right-0 top-full mt-2 w-44 bg-white border border-slate-200 rounded-lg shadow-lg z-50 overflow-hidden">

                        <button type="button" id="apExportExcel"
                            class="w-full px-4 py-3 flex items-center gap-3 text-sm text-slate-700 hover:bg-slate-50 text-left">
                            <i class="bi bi-file-earmark-excel text-emerald-600"></i> Excel
                        </button>

                        <button type="button" id="apExportPdf"
                            class="w-full px-4 py-3 flex items-center gap-3 text-sm text-slate-700 hover:bg-slate-50 text-left">
                            <i class="bi bi-file-earmark-pdf text-red-600"></i> PDF
                        </button>

                        <button type="button" id="apPrint"
                            class="w-full px-4 py-3 flex items-center gap-3 text-sm text-slate-700 hover:bg-slate-50 text-left">
                            <i class="bi bi-printer text-[#00224c]"></i> Print
                        </button>

                    </div>

                </div>

            </div>

        </div>


        <!-- TABLE -->

        <div class="overflow-x-auto">

            <table id="apTable" class="w-full text-sm min-w-[1150px]" style="width:100%">

                <thead class="bg-white border-b border-slate-200">
                    <tr class="text-left text-sm text-slate-900">
                        <th class="px-6 py-4 font-medium whitespace-nowrap">Supplier</th>
                        <th class="px-6 py-4 font-medium whitespace-nowrap">Purchase Order</th>
                        <th class="px-6 py-4 font-medium whitespace-nowrap">Invoice No.</th>
                        <th class="px-6 py-4 font-medium whitespace-nowrap">Due Date</th>
                        <th class="px-6 py-4 font-medium whitespace-nowrap">Amount</th>
                        <th class="px-6 py-4 font-medium whitespace-nowrap">Paid Amount</th>
                        <th class="px-6 py-4 font-medium whitespace-nowrap">Remaining</th>
                        <th class="px-6 py-4 font-medium whitespace-nowrap">Status</th>
                        <th class="px-6 py-4 font-medium whitespace-nowrap">Actions</th>
                    </tr>
                </thead>

                <tbody id="apTableBody">

                    <?php if (count($apRows) > 0): ?>

                        <?php foreach ($apRows as $row): ?>

                            <?php

                            $isOverdue = ($row['status'] !== 'Paid') && ($row['due_date'] < $today);
                            $displayStatus = $isOverdue ? 'Overdue' : $row['status'];

                            $searchText = strtolower(
                                $row['invoice_no'] . ' ' .
                                $row['po_number'] . ' ' .
                                $row['supplier'] . ' ' .
                                ($row['description'] ?? '')
                            );

                            $rowPayments = $paymentsByAp[$row['ap_id']] ?? [];

                            $isHighlighted = $highlightApId > 0 && $highlightApId === (int) $row['ap_id'];

                            ?>

                            <tr id="ap-row-<?= (int) $row['ap_id'] ?>"
                                class="ap-row border-b border-slate-200 hover:bg-slate-50 transition <?= $isHighlighted ? 'ap-row-highlight' : '' ?>"
                                data-search="<?= htmlspecialchars($searchText, ENT_QUOTES) ?>"
                                data-status="<?= htmlspecialchars($displayStatus, ENT_QUOTES) ?>">

                                <td class="px-6 py-4 font-medium text-slate-900 whitespace-nowrap">
                                    <?= htmlspecialchars($row['supplier']) ?>
                                </td>

                                <td class="px-6 py-4 text-slate-600 whitespace-nowrap">
                                    <?= htmlspecialchars($row['po_number']) ?>
                                </td>

                                <td class="px-6 py-4 text-slate-600 whitespace-nowrap">
                                    <?= htmlspecialchars($row['invoice_no']) ?>
                                </td>

                                <td class="px-6 py-4 text-slate-600 whitespace-nowrap">
                                    <?= date('M d, Y', strtotime($row['due_date'])) ?>
                                </td>

                                <td class="px-6 py-4 font-semibold text-slate-900 whitespace-nowrap">
                                    ₱<?= number_format((float) $row['amount'], 2) ?>
                                </td>

                                <td class="px-6 py-4 text-slate-700 whitespace-nowrap">
                                    ₱<?= number_format((float) $row['paid_amount'], 2) ?>
                                </td>

                                <td class="px-6 py-4 font-semibold text-slate-900 whitespace-nowrap">
                                    ₱<?= number_format((float) $row['remaining'], 2) ?>
                                </td>

                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="ap-status-badge ap-status-<?= htmlspecialchars($displayStatus, ENT_QUOTES) ?>">
                                        <?= htmlspecialchars($displayStatus) ?>
                                    </span>
                                </td>

                                <td class="px-6 py-4 whitespace-nowrap">

                                    <div class="d-flex gap-2">

                                        <?php if ($row['status'] !== 'Paid'): ?>

                                            <button type="button" class="btn btn-sm text-white btn-pay-ap"
                                                style="background:#00224c;" data-ap-id="<?= (int) $row['ap_id'] ?>"
                                                data-invoice="<?= htmlspecialchars($row['invoice_no'], ENT_QUOTES) ?>"
                                                data-vendor="<?= htmlspecialchars($row['supplier'], ENT_QUOTES) ?>"
                                                data-remaining="<?= (float) $row['remaining'] ?>">
                                                Pay
                                            </button>

                                        <?php else: ?>

                                            <span class="btn btn-sm btn-light border text-success disabled">
                                                <i class="bi bi-check-lg"></i> Paid
                                            </span>

                                        <?php endif; ?>

                                        <button type="button" class="btn btn-sm btn-light border btn-ap-history"
                                            data-target="history-<?= (int) $row['ap_id'] ?>"
                                            data-invoice="<?= htmlspecialchars($row['invoice_no'], ENT_QUOTES) ?>">
                                            History
                                        </button>

                                    </div>

                                </td>

                            </tr>

                            <!-- Hidden pre-rendered history block for this invoice (no AJAX) -->
                            <template id="history-<?= (int) $row['ap_id'] ?>">
                                <?php if (count($rowPayments) === 0): ?>

                                    <div class="text-center text-slate-400 py-4">No payments recorded yet.</div>

                                <?php else: ?>

                                    <div class="table-responsive">
                                        <table class="table table-sm">
                                            <thead>
                                                <tr>
                                                    <th>Date</th>
                                                    <th>Amount</th>
                                                    <th>Method</th>
                                                    <th>Reference</th>
                                                    <th>Paid By</th>
                                                    <th>Receipt</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($rowPayments as $p): ?>
                                                    <tr>
                                                        <td><?= date('M d, Y h:i A', strtotime($p['paid_at'])) ?></td>
                                                        <td>₱<?= number_format((float) $p['amount_paid'], 2) ?></td>
                                                        <td><?= htmlspecialchars($p['payment_method']) ?></td>
                                                        <td><?= htmlspecialchars($p['reference_no'] ?: '—') ?></td>
                                                        <td><?= htmlspecialchars($p['paid_by_name'] ?: '—') ?></td>
                                                        <td>
                                                            <?php if (!empty($p['receipt_path'])): ?>
                                                                <a href="../<?= htmlspecialchars($p['receipt_path']) ?>" target="_blank">
                                                                    <?= htmlspecialchars($p['receipt_code']) ?>
                                                                </a>
                                                            <?php else: ?>
                                                                —
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>

                                <?php endif; ?>
                            </template>

                        <?php endforeach; ?>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>

</div>


<!-- =========================================================
     PAYMENT HISTORY MODAL (content injected from the hidden
     <template> blocks above — no AJAX)
========================================================= -->

<div class="modal fade" id="apHistoryModal" tabindex="-1" aria-hidden="true">

    <div class="modal-dialog modal-lg modal-dialog-centered">

        <div class="modal-content">

            <div class="modal-header">

                <div>
                    <h5 class="modal-title fw-bold"><i class="bi bi-clock-history me-2"></i>Payment History</h5>
                    <small class="text-muted" id="apHistoryInvoiceLabel">—</small>
                </div>

                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>

            </div>

            <div class="modal-body">
                <div id="apHistoryBody"></div>
            </div>

        </div>

    </div>

</div>


<!-- =========================================================
     PAY INVOICE MODAL (posts back to this page)
========================================================= -->

<div class="modal fade" id="payApModal" tabindex="-1" aria-hidden="true">

    <div class="modal-dialog modal-dialog-centered">

        <div class="modal-content">

            <div class="modal-header">

                <div>
                    <h5 class="modal-title fw-bold"><i class="bi bi-cash-coin me-2"></i>Pay Invoice</h5>
                    <small class="text-muted" id="payInvoiceLabel">—</small>
                </div>

                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>

            </div>

            <form method="POST" action="accounts_payable.php">

                <div class="modal-body">

                    <div class="bg-light rounded-3 p-3 mb-3">
                        <div class="d-flex justify-content-between small text-muted mb-1">
                            <span>Supplier</span>
                            <span id="payVendorLabel" class="text-dark fw-medium">—</span>
                        </div>
                        <div class="d-flex justify-content-between small text-muted">
                            <span>Amount Due</span>
                            <span id="payAmountDue" class="text-dark fw-bold">₱0.00</span>
                        </div>
                    </div>

                    <input type="hidden" name="ap_id" id="payApId" value="">
                    <input type="hidden" name="return_to" value="accounts_payable.php">

                    <div class="row g-3">

                        <div class="col-md-6">
                            <label class="form-label">Amount to Pay <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text">₱</span>
                                <input type="number" name="amount_paid" id="payAmountInput" class="form-control"
                                    min="0.01" step="0.01" required>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Payment Method <span class="text-danger">*</span></label>
                            <select name="payment_method" id="payMethodSelect" class="form-select" required>
                                <option value="Cash">Cash</option>
                                <option value="GCash">GCash</option>
                                <option value="Check">Check</option>
                                <option value="Bank Transfer">Bank Transfer</option>
                            </select>
                            <div id="paymongoNote" class="form-text" style="display:none;">
                                <i class="bi bi-shield-check me-1"></i>
                                You'll be sent to PayMongo's secure checkout to complete this payment.
                            </div>
                        </div>

                        <div class="col-12">
                            <label class="form-label">Reference No. <span class="text-muted">(optional)</span></label>
                            <input type="text" name="reference_no" class="form-control"
                                placeholder="Check no., transaction ref, etc.">
                        </div>

                        <div class="col-12">
                            <label class="form-label">Notes <span class="text-muted">(optional)</span></label>
                            <textarea name="notes" class="form-control" rows="2"></textarea>
                        </div>

                    </div>

                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="pay_invoice" id="payConfirmBtn" class="btn text-white px-4"
                        style="background:#00224c;">
                        <i class="bi bi-check-lg me-1"></i> Confirm Payment
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

        const apDT = new DataTable("#apTable", {
            pageLength: 10,
            lengthChange: false,
            ordering: true,
            order: [],
            columnDefs: [{ orderable: false, targets: -1 }],
            language: {
                search: "",
                searchPlaceholder: "Search accounts payable...",
                info: "Showing _START_ to _END_ of _TOTAL_",
                paginate: { previous: "Previous", next: "Next" },
                emptyTable: "No supplier invoices found"
            }
        });

        const statusFilter = document.getElementById("apStatusFilter");

        DataTable.ext.search.push(function (settings, data, dataIndex) {
            if (settings.nTable.id !== "apTable") return true;
            const selected = statusFilter.value;
            if (!selected) return true;
            const row = apDT.row(dataIndex).node();
            return row.getAttribute("data-status") === selected;
        });

        statusFilter.addEventListener("change", function () {
            apDT.draw();
        });


        /*
        |--------------------------------------------------------------------------
        | EXPORT DROPDOWN
        |--------------------------------------------------------------------------
        */

        const exportButton = document.getElementById("apExportButton");
        const exportMenu = document.getElementById("apExportMenu");

        exportButton.addEventListener("click", function (e) {
            e.stopPropagation();
            exportMenu.classList.toggle("hidden");
        });

        document.addEventListener("click", function (e) {
            if (!exportMenu.contains(e.target) && !exportButton.contains(e.target)) {
                exportMenu.classList.add("hidden");
            }
        });

        function getVisibleRows() {
            return Array.from(apDT.rows({ search: "applied" }).nodes());
        }

        function buildTableHtml() {

            let html = "<table border='1'><thead><tr>" +
                "<th>Supplier</th><th>Purchase Order</th><th>Invoice No.</th><th>Due Date</th>" +
                "<th>Amount</th><th>Paid Amount</th><th>Remaining</th><th>Status</th></tr></thead><tbody>";

            getVisibleRows().forEach(function (row) {
                const cells = row.querySelectorAll("td");
                html += "<tr>";
                cells.forEach(function (cell, i) {
                    if (i === 8) return; // skip Actions column
                    html += "<td>" + cell.innerText.trim().replace(/\s+/g, " ") + "</td>";
                });
                html += "</tr>";
            });

            html += "</tbody></table>";
            return html;

        }

        document.getElementById("apExportExcel").addEventListener("click", function () {

            exportMenu.classList.add("hidden");

            if (getVisibleRows().length === 0) {
                alert("There are no invoices to export.");
                return;
            }

            const html = "<html><head><meta charset='UTF-8'><title>Supplier Invoices</title></head><body>" +
                "<h2>RetailCore Supplier Invoices</h2><p>Generated: " + new Date().toLocaleString() + "</p>" +
                buildTableHtml() + "</body></html>";

            const blob = new Blob([html], { type: "application/vnd.ms-excel" });
            const url = URL.createObjectURL(blob);
            const link = document.createElement("a");
            link.href = url;
            link.download = "retailcore_supplier_invoices.xls";
            link.click();
            URL.revokeObjectURL(url);

        });

        function openPrintWindow() {

            if (getVisibleRows().length === 0) {
                alert("There are no invoices to print.");
                return;
            }

            const printWindow = window.open("", "", "width=1400,height=900");

            if (!printWindow) {
                alert("Please allow pop-ups for this page.");
                return;
            }

            printWindow.document.write(
                "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Supplier Invoices</title>" +
                "<style>*{box-sizing:border-box;}body{font-family:Arial,sans-serif;padding:30px;color:#111827;}" +
                "h1{margin:0;color:#00224c;font-size:24px;}p{color:#64748b;margin-top:5px;}" +
                "table{width:100%;border-collapse:collapse;margin-top:25px;font-size:11px;}" +
                "th{background:#f1f5f9;color:#0f172a;font-weight:600;}th,td{border:1px solid #cbd5e1;padding:8px;text-align:left;}" +
                "@media print{body{padding:10px;}@page{size:landscape;margin:10mm;}}</style></head><body>" +
                "<h1>RetailCore Supplier Invoices</h1><p>Generated: " + new Date().toLocaleString() + "</p>" +
                buildTableHtml() + "</body></html>"
            );

            printWindow.document.close();
            printWindow.focus();

            setTimeout(function () {
                printWindow.print();
                printWindow.close();
            }, 300);

        }

        document.getElementById("apPrint").addEventListener("click", function () {
            exportMenu.classList.add("hidden");
            openPrintWindow();
        });

        document.getElementById("apExportPdf").addEventListener("click", function () {
            exportMenu.classList.add("hidden");
            openPrintWindow();
        });


        /*
        |--------------------------------------------------------------------------
        | HISTORY MODAL — content comes from the hidden <template> next to
        | each row, no fetch/AJAX involved.
        |--------------------------------------------------------------------------
        */

        const historyModal = new bootstrap.Modal(document.getElementById("apHistoryModal"));

        document.addEventListener("click", function (event) {

            const btn = event.target.closest(".btn-ap-history");
            if (!btn) return;

            document.getElementById("apHistoryInvoiceLabel").innerText = btn.dataset.invoice || "";

            const template = document.getElementById(btn.dataset.target);
            document.getElementById("apHistoryBody").innerHTML = template
                ? template.innerHTML
                : '<div class="text-center text-slate-400 py-4">No payments recorded yet.</div>';

            historyModal.show();

        });


        /*
        |--------------------------------------------------------------------------
        | PAY MODAL
        |--------------------------------------------------------------------------
        */

        const payApModal = new bootstrap.Modal(document.getElementById("payApModal"));
        const payMethodSelect = document.getElementById("payMethodSelect");
        const paymongoNote = document.getElementById("paymongoNote");
        const payConfirmBtn = document.getElementById("payConfirmBtn");

        function updatePayButtonForMethod() {

            const isPaymongo = payMethodSelect.value === "GCash" || payMethodSelect.value === "Bank Transfer";

            paymongoNote.style.display = isPaymongo ? "block" : "none";

            payConfirmBtn.innerHTML = isPaymongo
                ? '<i class="bi bi-box-arrow-up-right me-1"></i> Proceed to PayMongo'
                : '<i class="bi bi-check-lg me-1"></i> Confirm Payment';
        }

        payMethodSelect.addEventListener("change", updatePayButtonForMethod);

        document.addEventListener("click", function (event) {

            const btn = event.target.closest(".btn-pay-ap");
            if (!btn) return;

            document.getElementById("payApId").value = btn.dataset.apId;
            document.getElementById("payInvoiceLabel").innerText = btn.dataset.invoice || "";
            document.getElementById("payVendorLabel").innerText = btn.dataset.vendor || "";

            const remaining = parseFloat(btn.dataset.remaining || "0");

            document.getElementById("payAmountDue").innerText =
                "₱" + remaining.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

            const amountInput = document.getElementById("payAmountInput");
            amountInput.max = remaining;
            amountInput.value = remaining.toFixed(2);

            payMethodSelect.value = "Cash";
            updatePayButtonForMethod();

            payApModal.show();

        });


        /*
        |--------------------------------------------------------------------------
        | SCROLL TO HIGHLIGHTED ROW (?ap=ID from expenses.php)
        |--------------------------------------------------------------------------
        */

        const highlighted = document.querySelector(".ap-row-highlight");

        if (highlighted) {
            highlighted.scrollIntoView({ behavior: "smooth", block: "center" });
        }


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