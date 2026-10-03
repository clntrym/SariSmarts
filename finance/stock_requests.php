<?php

require_once("../init.php");
requireRole(['finance']);

$companyId = requireCompany();

/*
|--------------------------------------------------------------------------
| APPROVE STOCK REQUEST
|--------------------------------------------------------------------------
*/

if (isset($_POST['approveRequest'])) {

    $request_id = (int) ($_POST['request_id'] ?? 0);

    if ($request_id <= 0) {

        $_SESSION['alert'] = [
            "icon" => "error",
            "title" => "Invalid Request",
            "text" => "Invalid stock request."
        ];

        header("Location: stock_requests.php");
        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | VALIDATE PAYMENT TYPE (chosen in the review modal before approving)
    |--------------------------------------------------------------------------
    */

    $payment_type = trim($_POST['payment_type'] ?? '');

    if (!in_array($payment_type, ['Capital', 'Payable'], true)) {

        $_SESSION['alert'] = [
            "icon" => "warning",
            "title" => "Payment Type Required",
            "text" => "Please choose how this request will be paid before approving."
        ];

        header("Location: stock_requests.php");
        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | ONLY PENDING REQUESTS CAN BE APPROVED BY FINANCE
    |--------------------------------------------------------------------------
    */

    $check = $conn->prepare("
        SELECT request_id
        FROM stock_requests
        WHERE request_id = ?
          AND company_id = ?
          AND status = 'Pending Finance'
        LIMIT 1
    ");

    if (!$check) {

        $_SESSION['alert'] = [
            "icon" => "error",
            "title" => "Database Error",
            "text" => "Unable to check stock request."
        ];

        header("Location: stock_requests.php");
        exit;
    }

    $check->bind_param("ii", $request_id, $companyId);
    $check->execute();

    $result = $check->get_result();

    if ($result->num_rows === 0) {

        $check->close();

        $_SESSION['alert'] = [
            "icon" => "warning",
            "title" => "Request Unavailable",
            "text" => "This request is no longer pending or has already been processed."
        ];

        header("Location: stock_requests.php");
        exit;
    }

    $check->close();


    /*
    |--------------------------------------------------------------------------
    | FINANCE APPROVAL
    |--------------------------------------------------------------------------
    */

    $stmt = $conn->prepare("
        UPDATE stock_requests
        SET
            status = 'Pending Admin',
            finance_approved_by = ?,
            finance_approved_at = NOW(),
            payment_type = ?
        WHERE request_id = ? AND company_id = ?
        AND status = 'Pending Finance'
    ");

    if (!$stmt) {

        $_SESSION['alert'] = [
            "icon" => "error",
            "title" => "Database Error",
            "text" => "Unable to prepare approval."
        ];

        header("Location: stock_requests.php");
        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | GET LOGGED-IN FINANCE USER
    |--------------------------------------------------------------------------
    */

    $finance_user_id = (int) (
        $_SESSION['user_id']
        ?? $_SESSION['id']
        ?? 0
    );


    $stmt->bind_param(
        "isii",
        $finance_user_id,
        $payment_type,
        $request_id,
        $companyId
    );


    if ($stmt->execute() && $stmt->affected_rows > 0) {

        $_SESSION['alert'] = [
            "icon" => "success",
            "title" => "Request Approved",
            "text" =>
                "Stock request has been approved by Finance (" .
                ($payment_type === 'Payable' ? 'Accounts Payable' : 'Capital') .
                ") and forwarded to Admin."
        ];

    } else {

        $_SESSION['alert'] = [
            "icon" => "warning",
            "title" => "Request Unavailable",
            "text" => "This request is no longer pending or has already been processed."
        ];
    }

    $stmt->close();

    header("Location: stock_requests.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| REJECT STOCK REQUEST
|--------------------------------------------------------------------------
*/

if (isset($_POST['rejectRequest'])) {

    $request_id = (int) ($_POST['request_id'] ?? 0);
    $finance_remarks = trim(
        $_POST['finance_remarks'] ?? ''
    );


    /*
    |--------------------------------------------------------------------------
    | VALIDATE REQUEST ID
    |--------------------------------------------------------------------------
    */

    if ($request_id <= 0) {

        $_SESSION['alert'] = [
            "icon" => "error",
            "title" => "Invalid Request",
            "text" => "Invalid stock request."
        ];

        header("Location: stock_requests.php");
        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | VALIDATE REASON
    |--------------------------------------------------------------------------
    */

    if ($finance_remarks === '') {

        $_SESSION['alert'] = [
            "icon" => "warning",
            "title" => "Reason Required",
            "text" => "Please provide a reason for rejecting the request."
        ];

        header("Location: stock_requests.php");
        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | GET LOGGED-IN FINANCE USER
    |--------------------------------------------------------------------------
    */

    $finance_user_id = (int) (
        $_SESSION['user_id']
        ?? $_SESSION['id']
        ?? 0
    );


    /*
    |--------------------------------------------------------------------------
    | REJECT ONLY PENDING REQUESTS
    |--------------------------------------------------------------------------
    */

    $stmt = $conn->prepare("
        UPDATE stock_requests
        SET
            status = 'Finance Rejected',
            finance_approved_by = ?,
            finance_approved_at = NOW(),
            finance_remarks = ?
        WHERE request_id = ?
          AND company_id = ?
          AND status = 'Pending Finance'
    ");

    if (!$stmt) {

        $_SESSION['alert'] = [
            "icon" => "error",
            "title" => "Database Error",
            "text" => "Unable to prepare rejection."
        ];

        header("Location: stock_requests.php");
        exit;
    }


    $stmt->bind_param(
        "isii",
        $finance_user_id,
        $finance_remarks,
        $request_id,
        $companyId
    );


    if ($stmt->execute() && $stmt->affected_rows > 0) {

        $_SESSION['alert'] = [
            "icon" => "success",
            "title" => "Request Rejected",
            "text" => "The stock request has been rejected by Finance."
        ];

    } else {

        $_SESSION['alert'] = [
            "icon" => "warning",
            "title" => "Request Unavailable",
            "text" => "This request is no longer pending or has already been processed."
        ];
    }

    $stmt->close();

    header("Location: stock_requests.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| GET STOCK REQUESTS (header rows only — items now live in
| stock_request_items, one-to-many per request)
|--------------------------------------------------------------------------
*/

$requests = [];

$query = $conn->query("
    SELECT
        sr.request_id,
        sr.request_code,
        sr.total_price,
        sr.reason,
        sr.expense_category,
        sr.category_other,
        sr.status,
        sr.payment_type,
        sr.finance_approved_by,
        sr.finance_approved_at,
        sr.finance_remarks,
        sr.admin_approved_by,
        sr.admin_approved_at,
        sr.admin_remarks,
        sr.created_by,
        sr.created_at,
        sr.updated_at

    FROM stock_requests sr
    WHERE sr.company_id = " . (int) $companyId . "
    ORDER BY sr.request_id DESC
");

if ($query) {

    while ($row = $query->fetch_assoc()) {

        $requests[] = $row;

    }
}


/*
|--------------------------------------------------------------------------
| GET ITEMS FOR ALL REQUESTS (one query, grouped in PHP — avoids N+1)
|--------------------------------------------------------------------------
|
| An item's product_id can be NULL for non-inventory expenses (e.g. a
| utility bill), so vendor/name fall back to the item's own fields
| when there's no linked product.
|
*/

$itemsByRequest = [];

$itemsQuery = $conn->query("
    SELECT
        sri.item_id,
        sri.request_id,
        sri.product_id,
        sri.item_description,
        sri.vendor,
        sri.quantity,
        sri.unit_price,
        sri.profit_markup,
        sri.total_price,
        p.product_name,
        s.supplier_name

    FROM stock_request_items sri
    LEFT JOIN products p
        ON p.product_id = sri.product_id AND p.company_id = sri.company_id
    LEFT JOIN suppliers s
        ON s.supplier_id = p.supplier_id AND s.company_id = sri.company_id

    WHERE sri.company_id = " . (int) $companyId . "

    ORDER BY sri.item_id ASC
");

if ($itemsQuery) {

    while ($item = $itemsQuery->fetch_assoc()) {

        $itemsByRequest[$item['request_id']][] = $item;

    }
}


/*
|--------------------------------------------------------------------------
| ATTACH ITEMS + SUMMARY TO EACH REQUEST
|--------------------------------------------------------------------------
*/

foreach ($requests as &$request) {

    $items = $itemsByRequest[$request['request_id']] ?? [];

    $request['items'] = $items;
    $request['item_count'] = count($items);

    $names = [];

    foreach ($items as $item) {

        $displayName =
            !empty($item['item_description'])
            ? $item['item_description']
            : ($item['product_name'] ?? 'Item');

        $names[] = $displayName;
    }

    $request['item_summary'] = implode(', ', $names);

    $request['category'] =
        !empty($request['category_other'])
        ? $request['category_other']
        : ($request['expense_category'] ?? 'Restocking');

}
unset($request);


/*
|--------------------------------------------------------------------------
| GET CAPITAL SUMMARY
|--------------------------------------------------------------------------
|
| Available Capital = finance_capital.current_capital
| (starts at ₱50,000, +sales via DB trigger, -received
| stock requests via inventory/receive_deliveries.php)
|
| Used Capital = lifetime total spent on received stock
| (sum of 'Stock Purchase' entries in capital_ledger)
|
*/

$availableCapital = 0;
$usedCapital = 0;

$capitalQuery = $conn->query("
    SELECT current_capital
    FROM finance_capital
    WHERE company_id = " . (int) $companyId . "
    ORDER BY capital_id ASC
    LIMIT 1
");

if ($capitalQuery && $capitalQuery->num_rows > 0) {

    $availableCapital = (float) $capitalQuery->fetch_assoc()['current_capital'];

}

$usedCapitalQuery = $conn->query("
    SELECT COALESCE(SUM(amount), 0) AS used_capital
    FROM capital_ledger
    WHERE type = 'Stock Purchase' AND company_id = " . (int) $companyId . "
");

if ($usedCapitalQuery && $usedCapitalQuery->num_rows > 0) {

    $usedCapital = (float) $usedCapitalQuery->fetch_assoc()['used_capital'];

}


/*
|--------------------------------------------------------------------------
| HEADER
|--------------------------------------------------------------------------
*/

include("finance_header.php");

?>

<style>
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


    /* =========================================================
       CAPITAL DASHBOARD CARDS
    ========================================================= */

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


    /* REQUEST CODE */

    .request-code {
        font-weight: 600;
        color: #00224c;
    }


    /* ITEMS SUMMARY */

    .item-count {
        font-weight: 600;
        color: #00224c;
    }

    .item-summary {
        font-size: 12px;
        color: #8290a0;
        margin-top: 3px;
    }


    /* PAYMENT TYPE TAG (shown under the status badge once processed) */

    .payment-type-tag {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        font-size: 11px;
        font-weight: 600;
        margin-top: 5px;
    }

    .payment-type-capital {
        color: #00224c;
    }

    .payment-type-payable {
        color: #7c3aed;
    }


    /* STATUS */

    .status-pending {

        display: inline-flex;

        padding: 5px 12px;

        border-radius: 999px;

        background: #fef3c7;

        color: #b45309;

        border: 1px solid #fde68a;

        font-size: 12px;

        font-weight: 600;
    }

    .status-approved {
        display: inline-flex;
        padding: 5px 12px;
        border-radius: 999px;
        background: #dbeafe;
        color: #1d4ed8;
        border: 1px solid #bfdbfe;
        font-size: 12px;
        font-weight: 600;
    }

    .status-admin-approved {
        display: inline-flex;
        padding: 5px 12px;
        border-radius: 999px;
        background: #dcfce7;
        color: #15803d;
        border: 1px solid #bbf7d0;
        font-size: 12px;
        font-weight: 600;
    }

    .status-rejected {
        display: inline-flex;
        padding: 5px 12px;
        border-radius: 999px;
        background: #fee2e2;
        color: #dc2626;
        border: 1px solid #fecaca;
        font-size: 12px;
        font-weight: 600;
    }

    .status-received {
        display: inline-flex;
        padding: 5px 12px;
        border-radius: 999px;
        background: #dcfce7;
        color: #15803d;
        border: 1px solid #bbf7d0;
        font-size: 12px;
        font-weight: 600;
    }

    .status-cancelled {
        display: inline-flex;
        padding: 5px 12px;
        border-radius: 999px;
        background: #f1f5f9;
        color: #64748b;
        border: 1px solid #cbd5e1;
        font-size: 12px;
        font-weight: 600;
    }


    /* ACTION */

    .btn-view {

        width: 38px;
        height: 38px;

        border: 1px solid #d9e0e7;

        background: #fff;

        color: #00224c;

        border-radius: 9px;

        display: inline-flex;

        align-items: center;

        justify-content: center;

        transition: .2s;
    }

    .btn-view:hover {

        background: #00224c;

        color: #fff;

        border-color: #00224c;
    }


    /* MODAL */

    .modal-content {

        border: none;

        border-radius: 16px;
    }

    .modal-header {

        border-bottom: 1px solid #e5e9ef;
    }

    .modal-title {

        color: #00224c;
    }

    .form-label {

        color: #34495e;

        font-weight: 600;
    }

    .form-control {

        border-radius: 9px;

        border: 1px solid #d7dee7;

        min-height: 44px;
    }

    .form-control:focus {

        border-color: #00224c;

        box-shadow: 0 0 0 3px rgba(0, 34, 76, .08);
    }


    /* REVIEW ITEMS TABLE */

    #review_items_table th {
        font-size: 12px;
        color: #64748b;
        font-weight: 600;
        background: #f8fafc;
    }

    #review_items_table td {
        font-size: 13px;
        vertical-align: middle;
    }

    .review-item-vendor {
        font-size: 11px;
        color: #8290a0;
    }


    /* PAYMENT TYPE CHOICE (review modal) */

    .payment-type-option {
        flex: 1;
        min-width: 220px;
        border: 1px solid #d7dee7;
        border-radius: 12px;
        padding: 12px 14px;
        cursor: pointer;
        transition: .2s;
    }

    .payment-type-option:hover {
        border-color: #00224c;
        background: #f8fafc;
    }

    .payment-type-option input[type="radio"] {
        margin-top: 3px;
    }

    .payment-type-option input[type="radio"]:checked+span .payment-type-name {
        color: #00224c;
    }


    /* MOBILE */

    @media(max-width:768px) {

        .request-title {
            font-size: 28px;
        }

    }

    .dataTables_wrapper .dataTables_filter { float:none; text-align:left; padding:18px 18px 12px; }
    .dataTables_wrapper .dataTables_filter label { width:100%; font-size:0; }
    .dataTables_wrapper .dataTables_filter input { margin-left:0!important; width:430px; max-width:100%; height:43px; border:1px solid #d9e1e8; border-radius:22px; padding:0 18px; font-size:14px; outline:none; }
    .dataTables_wrapper .dataTables_filter input:focus { border-color:#00224c; box-shadow:0 0 0 3px rgba(0,34,76,.08); }
    .dataTables_wrapper .dt-layout-row:last-child { display:flex; align-items:center; justify-content:space-between; padding:14px 18px; }
</style>


<div class="container-fluid request-page">

    <!-- HEADER -->

    <div class="mb-3">

        <h1 class="request-title">
            Stock Request Approval
        </h1>

        <div class="request-subtitle">
            Review and validate stock requests before they are forwarded to Admin.
        </div>

    </div>


    <!-- =========================================================
         CAPITAL SUMMARY CARDS
    ========================================================== -->

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">


        <!-- AVAILABLE CAPITAL -->

        <div class="dashboard-card bg-white p-4">

            <div class="flex items-center justify-between">

                <div>

                    <div class="text-sm text-slate-500 mb-1">
                        Available Capital
                    </div>

                    <div class="stat-number">
                        ₱<?= number_format($availableCapital, 2) ?>
                    </div>

                </div>

                <div class="card-icon bg-navy">

                    <i class="bi bi-cash-coin"></i>

                </div>

            </div>

        </div>


        <!-- USED CAPITAL -->

        <div class="dashboard-card bg-white p-4">

            <div class="flex items-center justify-between">

                <div>

                    <div class="text-sm text-slate-500 mb-1">
                        Used Capital
                    </div>

                    <div class="stat-number">
                        ₱<?= number_format($usedCapital, 2) ?>
                    </div>

                </div>

                <div class="card-icon bg-red">

                    <i class="bi bi-hand-holding-usd"></i>

                </div>

            </div>

        </div>

    </div>


    <!-- =========================================================
         REQUEST TABLE (income.php style)
    ========================================================== -->

    <div class="w-full rounded-xl border border-slate-200 bg-white shadow-sm overflow-hidden">


        <!-- =====================================================
             SEARCH / FILTER HEADER
        ====================================================== -->

        <div data-dt-toolbar="financeRequestTable">

            <div class="flex flex-col lg:flex-row lg:items-center gap-3">


                <!-- STATUS FILTER -->

                <select id="statusFilter"
                    class="h-10 w-full lg:w-48 rounded-lg border border-slate-300 bg-white px-3 text-sm text-slate-700 focus:outline-none focus:border-[#00224c]">

                    <option value="">
                        All Status
                    </option>

                    <option value="Pending Finance">
                        Pending Finance
                    </option>

                    <option value="Pending Admin">
                        Pending Admin
                    </option>

                    <option value="Finance Rejected">
                        Finance Rejected
                    </option>

                    <option value="Admin Approved">
                        Admin Approved
                    </option>

                    <option value="Admin Rejected">
                        Admin Rejected
                    </option>

                    <option value="Received">
                        Received
                    </option>

                    <option value="Cancelled">
                        Cancelled
                    </option>

                </select>

            </div>

        </div>


        <!-- =====================================================
             TABLE
        ====================================================== -->

        <div class="overflow-x-auto">

            <table id="financeRequestTable" class="w-full text-sm min-w-[1000px]" style="width:100%">

                <thead class="bg-white border-b border-slate-200">

                    <tr class="text-left text-sm text-slate-900">

                        <th class="px-6 py-4 font-medium">
                            #
                        </th>

                        <th class="px-6 py-4 font-medium whitespace-nowrap">
                            Ref
                        </th>

                        <th class="px-6 py-4 font-medium whitespace-nowrap">
                            Items
                        </th>

                        <th class="px-6 py-4 font-medium whitespace-nowrap">
                            Category
                        </th>

                        <th class="px-6 py-4 font-medium whitespace-nowrap">
                            Total Cost
                        </th>

                        <th class="px-6 py-4 font-medium whitespace-nowrap">
                            Reason
                        </th>

                        <th class="px-6 py-4 font-medium whitespace-nowrap">
                            Date
                        </th>

                        <th class="px-6 py-4 font-medium whitespace-nowrap">
                            Status
                        </th>

                        <th class="px-6 py-4 font-medium text-center whitespace-nowrap">
                            Action
                        </th>

                    </tr>

                </thead>


                <tbody id="requestTableBody">

                    <?php if (count($requests) > 0): ?>

                        <?php foreach ($requests as $index => $request): ?>

                            <?php

                            $totalCost = (float) $request['total_price'];

                            $status = $request['status'];

                            $statusClass = 'status-pending';

                            if ($status === 'Finance Approved') {
                                $statusClass = 'status-approved';
                            } elseif ($status === 'Finance Rejected') {
                                $statusClass = 'status-rejected';
                            } elseif ($status === 'Admin Approved') {
                                $statusClass = 'status-admin-approved';
                            } elseif ($status === 'Admin Rejected') {
                                $statusClass = 'status-rejected';
                            } elseif ($status === 'Received') {
                                $statusClass = 'status-received';
                            } elseif ($status === 'Cancelled') {
                                $statusClass = 'status-cancelled';
                            }

                            $searchText = strtolower(
                                $request['request_code']
                                . ' '
                                . $request['item_summary']
                                . ' '
                                . $request['category']
                                . ' '
                                . $request['reason']
                                . ' '
                                . $status
                            );

                            /*
                            |--------------------------------------------------------------------------
                            | ITEMS PAYLOAD FOR THE REVIEW MODAL (JS reads this to build the table)
                            |--------------------------------------------------------------------------
                            */

                            $itemsForJs = [];

                            foreach ($request['items'] as $item) {

                                $itemsForJs[] = [
                                    'name' => !empty($item['item_description'])
                                        ? $item['item_description']
                                        : ($item['product_name'] ?? 'Item'),
                                    'vendor' => !empty($item['vendor'])
                                        ? $item['vendor']
                                        : ($item['supplier_name'] ?? 'N/A'),
                                    'quantity' => (int) $item['quantity'],
                                    'unit_price' => (float) $item['unit_price'],
                                    'profit_markup' => (float) $item['profit_markup'],
                                    'total_price' => (float) $item['total_price']
                                ];
                            }

                            /*
                            |--------------------------------------------------------------------------
                            | PAYMENT TYPE — only meaningful once Finance has acted on the request
                            |--------------------------------------------------------------------------
                            */

                            $hasBeenReviewed = !in_array($status, ['Pending Finance', 'Finance Rejected'], true);
                            $paymentType = $request['payment_type'] ?? 'Capital';

                            ?>

                            <tr class="request-row border-b border-slate-200 hover:bg-slate-50 transition"
                                data-search="<?= htmlspecialchars($searchText, ENT_QUOTES) ?>"
                                data-status="<?= htmlspecialchars($status, ENT_QUOTES) ?>">


                                <!-- NUMBER -->

                                <td class="px-6 py-4 text-slate-500 row-number">
                                    <?= $index + 1 ?>
                                </td>


                                <!-- REF -->

                                <td class="px-6 py-4">

                                    <span class="request-code">

                                        <?= htmlspecialchars(
                                            $request['request_code']
                                        ) ?>

                                    </span>

                                </td>


                                <!-- ITEMS -->

                                <td class="px-6 py-4">

                                    <div class="item-count">

                                        <?= (int) $request['item_count'] ?>
                                        item<?= $request['item_count'] == 1 ? '' : 's' ?>

                                    </div>

                                    <?php if (!empty($request['item_summary'])): ?>

                                        <div class="item-summary max-w-[220px] truncate"
                                            title="<?= htmlspecialchars($request['item_summary']) ?>">

                                            <?= htmlspecialchars($request['item_summary']) ?>

                                        </div>

                                    <?php endif; ?>

                                </td>


                                <!-- CATEGORY -->

                                <td class="px-6 py-4 text-slate-700 whitespace-nowrap">

                                    <?= htmlspecialchars($request['category']) ?>

                                </td>


                                <!-- TOTAL -->

                                <td class="px-6 py-4 font-semibold text-slate-900 whitespace-nowrap">

                                    ₱<?= number_format(
                                        $totalCost,
                                        2
                                    ) ?>

                                </td>


                                <!-- REASON -->

                                <td class="px-6 py-4 text-slate-700">

                                    <div class="max-w-[220px] truncate" title="<?= htmlspecialchars(
                                        $request['reason']
                                    ) ?>">

                                        <?= htmlspecialchars(
                                            $request['reason']
                                        ) ?>

                                    </div>

                                </td>


                                <!-- DATE -->

                                <td class="px-6 py-4 text-slate-600 whitespace-nowrap">

                                    <?= date(
                                        'M d, Y',
                                        strtotime(
                                            $request['created_at']
                                        )
                                    ) ?>

                                </td>


                                <!-- STATUS -->

                                <td class="px-6 py-4">

                                    <span class="<?= $statusClass ?>">
                                        <?= htmlspecialchars($status) ?>
                                    </span>

                                    <?php if ($hasBeenReviewed): ?>

                                        <div
                                            class="payment-type-tag <?= $paymentType === 'Payable' ? 'payment-type-payable' : 'payment-type-capital' ?>">

                                            <i
                                                class="bi <?= $paymentType === 'Payable' ? 'bi-file-earmark-text' : 'bi-cash-coin' ?>"></i>

                                            <?= $paymentType === 'Payable' ? 'Accounts Payable' : 'Capital' ?>

                                        </div>

                                    <?php endif; ?>

                                </td>


                                <!-- ACTION -->

                                <td class="px-6 py-4 text-center">

                                    <?php if ($status === 'Pending Finance'): ?>

                                        <button type="button" class="btn-view" title="Review Request"
                                            data-id="<?= (int) $request['request_id'] ?>"
                                            data-ref="<?= htmlspecialchars($request['request_code'], ENT_QUOTES) ?>"
                                            data-category="<?= htmlspecialchars($request['category'], ENT_QUOTES) ?>"
                                            data-total="<?= htmlspecialchars($request['total_price'], ENT_QUOTES) ?>"
                                            data-reason="<?= htmlspecialchars($request['reason'], ENT_QUOTES) ?>"
                                            data-items="<?= htmlspecialchars(json_encode($itemsForJs), ENT_QUOTES) ?>"
                                            data-bs-toggle="modal" data-bs-target="#reviewRequestModal">
                                            <i class="bi bi-eye"></i>
                                        </button>

                                    <?php else: ?>

                                        <span class="text-muted small">
                                            Processed
                                        </span>

                                    <?php endif; ?>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <tr id="emptyDatabaseRow">

                            <td colspan="9" class="px-6 py-14 text-center text-slate-500">

                                <i class="bi bi-check-circle text-4xl block mb-3"></i>

                                <p class="font-medium text-slate-700">
                                    No stock requests found
                                </p>

                                <p class="text-sm mt-1">
                                    Pending stock requests will appear here.
                                </p>

                            </td>

                        </tr>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>


        <!-- DataTable provides its own pagination -->

    </div>

</div>


<!-- =========================================================
     REVIEW MODAL
========================================================= -->

<div class="modal fade" id="reviewRequestModal" tabindex="-1">

    <div class="modal-dialog modal-lg modal-dialog-centered">

        <div class="modal-content">

            <div class="modal-header">

                <div>

                    <h5 class="modal-title fw-bold">

                        <i class="bi bi-clipboard-check me-2"></i>

                        Review Stock Request

                    </h5>

                    <small class="text-muted">

                        Validate the request before forwarding it to Admin.

                    </small>

                </div>

                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>

            </div>


            <div class="modal-body">

                <div class="row g-3">

                    <div class="col-6">

                        <label class="form-label">
                            Request
                        </label>

                        <input type="text" id="review_ref" class="form-control" readonly>

                    </div>


                    <div class="col-6">

                        <label class="form-label">
                            Category
                        </label>

                        <input type="text" id="review_category" class="form-control" readonly>

                    </div>


                    <div class="col-6">

                        <label class="form-label">
                            Total Cost
                        </label>

                        <input type="text" id="review_total" class="form-control" readonly>

                    </div>


                    <div class="col-6">

                        <label class="form-label">
                            Items
                        </label>

                        <input type="text" id="review_item_count" class="form-control" readonly>

                    </div>


                    <div class="col-12">

                        <label class="form-label">
                            Reason
                        </label>

                        <textarea id="review_reason" class="form-control" rows="2" readonly></textarea>

                    </div>


                    <!-- ITEM BREAKDOWN -->

                    <div class="col-12">

                        <label class="form-label">
                            Item Breakdown
                        </label>

                        <div class="table-responsive border rounded-3">

                            <table class="table table-sm mb-0" id="review_items_table">

                                <thead>

                                    <tr>

                                        <th>Item</th>
                                        <th class="text-end">Qty</th>
                                        <th class="text-end">Unit Price</th>
                                        <th class="text-end">Markup</th>
                                        <th class="text-end">Total</th>

                                    </tr>

                                </thead>

                                <tbody id="review_items_body">

                                    <!-- populated by JS -->

                                </tbody>

                            </table>

                        </div>

                    </div>


                    <!-- =========================================================
                         PAYMENT TYPE — how this request will be settled.
                         The radios live outside #approveForm in the DOM, but the
                         form="approveForm" attribute submits them with it anyway.
                    ========================================================== -->

                    <div class="col-12">

                        <label class="form-label">
                            Payment Type
                            <span class="text-danger">*</span>
                        </label>

                        <div class="d-flex gap-3 flex-wrap">

                            <label class="payment-type-option d-flex align-items-start gap-2">

                                <input type="radio" name="payment_type" value="Capital" form="approveForm" checked>

                                <span>
                                    <span class="d-block fw-semibold payment-type-name">
                                        Pay via Capital
                                    </span>
                                    <span class="d-block text-muted small">
                                        Capital is deducted automatically once Inventory confirms receipt.
                                    </span>
                                </span>

                            </label>

                            <label class="payment-type-option d-flex align-items-start gap-2">

                                <input type="radio" name="payment_type" value="Payable" form="approveForm">

                                <span>
                                    <span class="d-block fw-semibold payment-type-name">
                                        Accounts Payable
                                    </span>
                                    <span class="d-block text-muted small">
                                        Stock is received now; the invoice goes to Accounts Payable to pay later.
                                    </span>
                                </span>

                            </label>

                        </div>

                    </div>

                </div>

            </div>


            <div class="modal-footer">

                <!-- REJECT -->

                <button type="button" class="btn btn-danger" id="openRejectButton">

                    <i class="bi bi-x-lg me-1"></i>

                    Reject

                </button>


                <!-- APPROVE -->

                <form method="POST" id="approveForm">

                    <input type="hidden" name="request_id" id="approve_request_id">

                    <button type="submit" name="approveRequest" class="btn text-white" style="background:#00224c;">

                        <i class="bi bi-check-lg me-1"></i>

                        Approve Request

                    </button>

                </form>

            </div>

        </div>

    </div>

</div>


<!-- =========================================================
     REJECT MODAL
========================================================= -->

<div class="modal fade" id="rejectRequestModal" tabindex="-1">

    <div class="modal-dialog modal-dialog-centered">

        <div class="modal-content">

            <form method="POST">

                <input type="hidden" name="request_id" id="reject_request_id">

                <div class="modal-header">

                    <h5 class="modal-title fw-bold">

                        Reject Stock Request

                    </h5>

                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>

                </div>


                <div class="modal-body">

                    <label class="form-label">

                        Reason for Rejection
                        <span class="text-danger">*</span>

                    </label>

                    <textarea name="finance_remarks" class="form-control" rows="4" maxlength="500"
                        placeholder="Enter reason for rejecting this request..." required></textarea>

                </div>


                <div class="modal-footer">

                    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">

                        Cancel

                    </button>


                    <button type="submit" name="rejectRequest" class="btn btn-danger">

                        <i class="bi bi-x-lg me-1"></i>

                        Reject Request

                    </button>

                </div>

            </form>

        </div>

    </div>

</div>


<!-- =========================================================
     JAVASCRIPT (income.php-style search / filter / pagination)
========================================================== -->

<script>

    document.addEventListener("DOMContentLoaded", function () {


        /*
        |--------------------------------------------------------------------------
        | REVIEW BUTTON
        |--------------------------------------------------------------------------
        */

        document.querySelectorAll(".btn-view").forEach(function (button) {

            button.addEventListener("click", function () {

                document.getElementById("review_ref").value = this.dataset.ref;
                document.getElementById("review_category").value = this.dataset.category;

                document.getElementById("review_total").value =
                    "₱" + Number(this.dataset.total).toLocaleString(undefined, { minimumFractionDigits: 2 });

                document.getElementById("review_reason").value = this.dataset.reason;

                document.getElementById("approve_request_id").value = this.dataset.id;
                document.getElementById("reject_request_id").value = this.dataset.id;


                /*
                |--------------------------------------------------------------------------
                | RESET PAYMENT TYPE TO DEFAULT (Capital) EVERY TIME THE MODAL OPENS
                |--------------------------------------------------------------------------
                */

                const capitalRadio = document.querySelector('input[name="payment_type"][value="Capital"]');

                if (capitalRadio) {
                    capitalRadio.checked = true;
                }


                /*
                |--------------------------------------------------------------------------
                | ITEM BREAKDOWN
                |--------------------------------------------------------------------------
                */

                let items = [];

                try {
                    items = JSON.parse(this.dataset.items || "[]");
                } catch (error) {
                    items = [];
                }

                document.getElementById("review_item_count").value =
                    items.length + (items.length === 1 ? " item" : " items");

                const itemsBody = document.getElementById("review_items_body");

                itemsBody.innerHTML = "";

                if (items.length === 0) {

                    itemsBody.innerHTML =
                        '<tr><td colspan="5" class="text-center text-muted py-3">No items on this request.</td></tr>';

                } else {

                    items.forEach(function (item) {

                        const row = document.createElement("tr");

                        row.innerHTML = `
                            <td>
                                ${item.name}
                                <div class="review-item-vendor">${item.vendor}</div>
                            </td>
                            <td class="text-end">${item.quantity}</td>
                            <td class="text-end">₱${item.unit_price.toLocaleString(undefined, { minimumFractionDigits: 2 })}</td>
                            <td class="text-end">${item.profit_markup}%</td>
                            <td class="text-end">₱${item.total_price.toLocaleString(undefined, { minimumFractionDigits: 2 })}</td>
                        `;

                        itemsBody.appendChild(row);

                    });

                }

            });

        });


        /*
        |--------------------------------------------------------------------------
        | OPEN REJECT MODAL
        |--------------------------------------------------------------------------
        */

        document.getElementById("openRejectButton").addEventListener("click", function () {

            const reviewModal = bootstrap.Modal.getInstance(document.getElementById("reviewRequestModal"));
            reviewModal.hide();

            const rejectModal = new bootstrap.Modal(document.getElementById("rejectRequestModal"));
            rejectModal.show();

        });


        /*
        |--------------------------------------------------------------------------
        | DATA TABLE INIT
        |--------------------------------------------------------------------------
        */

        var statusFilter = document.getElementById("statusFilter");

        DataTable.ext.search.push(function(settings, data, dataIndex) {
            if (settings.nTable.id !== "financeRequestTable") return true;
            var status = statusFilter.value;
            if (status === "") return true;
            var row = settings.aoData[dataIndex].nTr;
            return row.getAttribute("data-status") === status;
        });

        var requestDT = new DataTable("#financeRequestTable", {
            pageLength: 10,
            lengthChange: false,
            ordering: true,
            order: [],
            columnDefs: [{ orderable: false, targets: -1 }],
            language: {
                search: "",
                searchPlaceholder: "Search stock requests...",
                info: "Showing _START_ to _END_ of _TOTAL_",
                paginate: { previous: "Previous", next: "Next" },
                emptyTable: "No stock requests found",
                zeroRecords: "No matching requests"
            }
        });

        statusFilter.addEventListener("change", function() { requestDT.draw(); });

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

            text:
                <?= json_encode($alert['text']) ?>,

            confirmButtonColor:
                "#00224c"

        });

    </script>

<?php endif; ?>


<?php include("finance_footer.php"); ?>