<?php

require_once("../init.php");
requireRole(['admin']);

$companyId = requireCompany();

/*
| The owner raises stock requests from this page on a plan with no Inventory
| Staff seat. Same handler the inventory page uses, so the approval routing
| and the money rules cannot drift apart.
*/
require __DIR__ . "/../includes/stock_request_create.php";

/*
| With no Finance approver in the plan there is nothing on this page to
| approve -- every request the owner raises is booked as approved already --
| so the screen presents itself as a register rather than an approval queue.
*/
$stockAutoApproves = !companyHasModule($conn, $companyId, 'finance_approval');

/*
| Capital top-up.
|
| Spending capital was always possible; putting it in was not. finance_capital
| had no writer at all outside the two places that subtract from it, which is
| survivable while a Finance officer can be asked to sort it out, and fatal on
| a plan that has no Finance officer. So the owner gets the control here, on
| the page where the money is spent.
*/
if ($stockAutoApproves && isset($_POST['addCapital'])) {

    $addAmount = (float) ($_POST['capital_amount'] ?? 0);
    $addNote = trim((string) ($_POST['capital_note'] ?? ''));

    if ($addAmount <= 0) {

        $_SESSION['alert'] = [
            "icon" => "error",
            "title" => "Invalid Amount",
            "text" => "Enter an amount greater than zero."
        ];

    } else {

        $conn->begin_transaction();

        try {

            $capital = ensureCompanyCapital($conn, $companyId);
            $newBalance = $capital['current_capital'] + $addAmount;

            $bump = $conn->prepare("UPDATE finance_capital SET current_capital = ? WHERE capital_id = ? AND company_id = ?");
            $bump->bind_param("dii", $newBalance, $capital['capital_id'], $companyId);

            if (!$bump->execute()) {
                $bump->close();
                throw new Exception("Unable to update capital.");
            }

            $bump->close();

            $ledgerNote = $addNote !== '' ? $addNote : 'Capital added by the owner';

            $logIt = $conn->prepare("
                INSERT INTO capital_ledger
                (company_id, type, reference_id, reference_code, amount, balance_after, description)
                VALUES (?, 'Capital Added', NULL, NULL, ?, ?, ?)
            ");
            $logIt->bind_param("idds", $companyId, $addAmount, $newBalance, $ledgerNote);

            if (!$logIt->execute()) {
                $logIt->close();
                throw new Exception("Unable to record the capital entry.");
            }

            $logIt->close();
            $conn->commit();

            $_SESSION['alert'] = [
                "icon" => "success",
                "title" => "Capital Added",
                "text" => "Capital is now PHP " . number_format($newBalance, 2) . "."
            ];

        } catch (Exception $e) {

            $conn->rollback();

            $_SESSION['alert'] = [
                "icon" => "error",
                "title" => "Failed",
                "text" => $e->getMessage()
            ];
        }
    }

    header("Location: stock_requests.php");
    exit;
}

$stockCapital = $stockAutoApproves ? ensureCompanyCapital($conn, $companyId) : null;

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
    | ONLY PENDING ADMIN REQUESTS CAN BE APPROVED
    |--------------------------------------------------------------------------
    |
    | Note: stock_requests no longer carries product_id/quantity/unit_price/
    | profit_markup directly — those live per-item in stock_request_items
    | (a request can now hold multiple items). We only need the columns
    | that still exist on stock_requests itself here.
    |
    */

    $check = $conn->prepare("
        SELECT
            request_id,
            request_code,
            total_price,
            reason
        FROM stock_requests
        WHERE request_id = ?
        AND company_id = ?
        AND status = 'Pending Admin'
        LIMIT 1
    ");

    $check->bind_param("ii", $request_id, $companyId);
    $check->execute();

    $result = $check->get_result();

    if ($result->num_rows === 0) {

        $check->close();

        $_SESSION['alert'] = [
            "icon" => "warning",
            "title" => "Request Unavailable",
            "text" => "This request has already been processed or is not yet approved by Finance."
        ];

        header("Location: stock_requests.php");
        exit;
    }

    $check->close();


    /*
    |--------------------------------------------------------------------------
    | GET ADMIN USER ID
    |--------------------------------------------------------------------------
    */

    $admin_id = (int) ($_SESSION['user_id'] ?? 0);


    /*
    |--------------------------------------------------------------------------
    | APPROVE
    |--------------------------------------------------------------------------
    */

    $stmt = $conn->prepare("
        UPDATE stock_requests
        SET
            status = 'Admin Approved',
            admin_approved_by = NULLIF(?, 0),
            admin_approved_at = NOW()
        WHERE request_id = ?
        AND company_id = ?
        AND status = 'Pending Admin'
    ");

    $stmt->bind_param(
        "iii",
        $admin_id,
        $request_id,
        $companyId
    );

    if ($stmt->execute() && $stmt->affected_rows > 0) {

        $_SESSION['alert'] = [
            "icon" => "success",
            "title" => "Request Approved",
            "text" => "Stock request has been approved by Admin. It is now ready for receiving."
        ];

    } else {

        $_SESSION['alert'] = [
            "icon" => "error",
            "title" => "Approval Failed",
            "text" => "Unable to approve the stock request."
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

    $admin_remarks = trim(
        $_POST['admin_remarks'] ?? ''
    );

    if ($request_id <= 0) {

        $_SESSION['alert'] = [
            "icon" => "error",
            "title" => "Invalid Request",
            "text" => "Invalid stock request."
        ];

        header("Location: stock_requests.php");
        exit;
    }

    if ($admin_remarks === '') {

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
    | GET ADMIN USER ID
    |--------------------------------------------------------------------------
    */

    $admin_id = (int) ($_SESSION['user_id'] ?? 0);


    /*
    |--------------------------------------------------------------------------
    | REJECT ONLY PENDING ADMIN REQUESTS
    |--------------------------------------------------------------------------
    */

    $stmt = $conn->prepare("
        UPDATE stock_requests
        SET
            status = 'Admin Rejected',
            admin_approved_by = NULLIF(?, 0),
            admin_approved_at = NOW(),
            admin_remarks = ?
        WHERE request_id = ?
        AND company_id = ?
        AND status = 'Pending Admin'
    ");

    $stmt->bind_param(
        "isii",
        $admin_id,
        $admin_remarks,
        $request_id,
        $companyId
    );

    if (
        $stmt->execute() &&
        $stmt->affected_rows > 0
    ) {

        $_SESSION['alert'] = [
            "icon" => "success",
            "title" => "Request Rejected",
            "text" => "The stock request has been rejected by Admin."
        ];

    } else {

        $_SESSION['alert'] = [
            "icon" => "error",
            "title" => "Rejection Failed",
            "text" => "Unable to reject the stock request. It may have already been processed."
        ];
    }

    $stmt->close();

    header("Location: stock_requests.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| GET STOCK REQUESTS
|--------------------------------------------------------------------------
|
| We display all requests so Admin can see the complete history.
| Only "Pending Admin" requests can be approved/rejected.
|
| A request can now hold MULTIPLE items (stock_requests no longer has its
| own product_id/quantity/unit_price/profit_markup — those live per-item
| in stock_request_items, and an item can be non-inventory, e.g. a
| utility bill with no product_id). So this list query aggregates at the
| request level (item count only), and the full per-item breakdown is
| loaded separately below and handed to the review modal via JSON.
|
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
        sr.finance_approved_at,
        sr.finance_remarks,
        sr.admin_approved_at,
        sr.admin_remarks,
        sr.created_at,
        sr.updated_at,
        COUNT(sri.item_id) AS item_count
    FROM stock_requests sr
    LEFT JOIN stock_request_items sri ON sri.request_id = sr.request_id AND sri.company_id = sr.company_id
    WHERE sr.company_id = " . (int) $companyId . "
    GROUP BY sr.request_id
    ORDER BY sr.request_id DESC
");

if ($query) {

    while ($row = $query->fetch_assoc()) {

        $requests[] = $row;

    }
}


/*
|--------------------------------------------------------------------------
| GET LINE ITEMS FOR EVERY REQUEST (grouped by request_id in PHP)
|--------------------------------------------------------------------------
|
| Used for the "Items" summary column and the review modal's item
| breakdown. product_name/supplier_name are only present when the item
| is linked to a real product; non-inventory items (product_id IS NULL)
| fall back to item_description/vendor.
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
    LEFT JOIN products p ON p.product_id = sri.product_id AND p.company_id = sri.company_id
    LEFT JOIN suppliers s ON s.supplier_id = p.supplier_id AND s.company_id = sri.company_id
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
| HEADER
|--------------------------------------------------------------------------
*/

include("admin_header.php");

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


    /* TABLE */

    .request-table-card {
        background: #fff;
        border: 1px solid #dce3ea;
        border-radius: 14px;
        overflow: hidden;
        box-shadow: 0 2px 8px rgba(0, 34, 76, .05);
    }

    #adminRequestTable {
        width: 100% !important;
    }

    #adminRequestTable thead th {

        background: #f4f7fa;
        color: #53657a;

        font-size: 14px;
        font-weight: 600;

        padding: 16px 18px;

        border-bottom: 1px solid #dce3ea;

        white-space: nowrap;
    }

    #adminRequestTable tbody td {

        padding: 16px 18px;

        vertical-align: middle;

        border-bottom: 1px solid #e3e8ee;

        color: #19324d;

        font-size: 14px;
    }

    #adminRequestTable tbody tr:hover {
        background: #fafcff;
    }


    /* REQUEST CODE */

    .request-code {
        font-weight: 600;
        color: #00224c;
    }


    /* ITEMS SUMMARY */

    .items-count {
        font-weight: 600;
        color: #00224c;
    }

    .items-summary {
        font-size: 12px;
        color: #8290a0;
        margin-top: 3px;
        max-width: 220px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }


    /* STATUS */

    .status-badge {

        display: inline-flex;

        padding: 5px 12px;

        border-radius: 999px;

        font-size: 12px;

        font-weight: 600;

        white-space: nowrap;
    }

    .status-pending-admin {

        background: #fef3c7;
        color: #b45309;
        border: 1px solid #fde68a;

    }

    .status-approved {

        background: #dcfce7;
        color: #15803d;
        border: 1px solid #bbf7d0;

    }

    .status-rejected {

        background: #fee2e2;
        color: #dc2626;
        border: 1px solid #fecaca;

    }

    .status-finance {

        background: #dbeafe;
        color: #1d4ed8;
        border: 1px solid #bfdbfe;

    }

    .status-received {

        background: #e0e7ff;
        color: #4338ca;
        border: 1px solid #c7d2fe;

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

        box-shadow:
            0 0 0 3px rgba(0, 34, 76, .08);
    }


    /* ITEM BREAKDOWN TABLE (inside modal) */

    #review_items_table {
        font-size: 13px;
    }

    #review_items_table th {
        background: #f4f7fa;
        color: #53657a;
        font-weight: 600;
        white-space: nowrap;
    }

    #review_items_table td {
        vertical-align: middle;
    }

    .item-name {
        font-weight: 600;
        color: #00224c;
    }

    .item-vendor {
        font-size: 11px;
        color: #8290a0;
    }


    /* APPROVAL INFO */

    .finance-approved-box {

        background: #eff6ff;

        border: 1px solid #bfdbfe;

        border-radius: 10px;

        padding: 12px;

        font-size: 13px;

        color: #1e40af;
    }


    /* MOBILE */

    @media(max-width:768px) {

        .request-title {
            font-size: 28px;
        }

    }
</style>


<div class="container-fluid request-page">

    <!-- HEADER -->

    <div class="mb-3 d-flex flex-wrap justify-content-between align-items-start gap-3">

        <div>
            <h1 class="request-title">
                <?= $stockAutoApproves ? 'Stock Requests' : 'Stock Request Approval' ?>
            </h1>

            <div class="request-subtitle">
                <?php if ($stockAutoApproves): ?>
                    Raise a request and it is approved straight away, then confirm it in
                    Receive Deliveries -- that is when capital is deducted.
                <?php else: ?>
                    Review Finance-approved stock requests before final Admin approval.
                <?php endif; ?>
            </div>
        </div>

        <div class="d-flex align-items-center gap-2">

            <?php if ($stockAutoApproves): ?>
                <div class="capital-chip">
                    <span>Capital</span>
                    <strong>&#8369;<?= number_format($stockCapital['current_capital'], 2) ?></strong>
                    <button type="button" class="btn-add-capital" data-bs-toggle="modal"
                        data-bs-target="#addCapitalModal" title="Add capital">
                        <i class="bi bi-plus-lg"></i>
                    </button>
                </div>
            <?php endif; ?>

            <button type="button" class="btn-new-request" data-bs-toggle="modal"
                data-bs-target="#newStockRequestModal">
                <i class="bi bi-plus-lg me-1"></i> New Stock Request
            </button>

        </div>

    </div>

    <?php if ($stockAutoApproves && $stockCapital['current_capital'] <= 0): ?>
        <div class="alert alert-warning d-flex align-items-start gap-2" role="alert">
            <i class="bi bi-exclamation-triangle-fill mt-1"></i>
            <div>
                <strong>Your capital is empty.</strong>
                A stock request is paid out of capital, so add some before raising one --
                otherwise it will be refused for insufficient funds.
            </div>
        </div>
    <?php endif; ?>


    <!-- TABLE -->

    <div class="request-table-card">

        <table id="adminRequestTable" class="table mb-0">

            <thead>

                <tr>

                    <th>
                        Ref
                    </th>

                    <th>
                        Items
                    </th>

                    <th>
                        Category
                    </th>

                    <th>
                        Total Cost
                    </th>

                    <th>
                        Reason
                    </th>

                    <th>
                        Date
                    </th>

                    <th>
                        Status
                    </th>

                    <th class="text-center">
                        Action
                    </th>

                </tr>

            </thead>


            <tbody>

                <?php foreach ($requests as $request): ?>

                        <?php

                        $status =
                            $request['status'];


                        if ($status === 'Pending Admin') {

                            $statusClass =
                                'status-pending-admin';

                        } elseif (
                            $status === 'Admin Approved'
                        ) {

                            $statusClass =
                                'status-approved';

                        } elseif (
                            $status === 'Admin Rejected' ||
                            $status === 'Finance Rejected'
                        ) {

                            $statusClass =
                                'status-rejected';

                        } elseif (
                            $status === 'Finance Approved'
                        ) {

                            $statusClass =
                                'status-finance';

                        } elseif (
                            $status === 'Received'
                        ) {

                            $statusClass =
                                'status-received';

                        } else {

                            $statusClass =
                                'status-pending-admin';

                        }


                        $category =
                            !empty($request['category_other'])
                            ? $request['category_other']
                            : $request['expense_category'];


                        $items =
                            $itemsByRequest[$request['request_id']] ?? [];


                        $itemNames = array_map(function ($item) {

                            return !empty($item['product_name'])
                                ? $item['product_name']
                                : ($item['item_description'] ?: 'Item');

                        }, $items);


                        $itemsSummary =
                            implode(', ', $itemNames);


                        // Payload for the review modal's item breakdown table
                        $itemsPayload = json_encode(array_map(function ($item) {

                            return [
                                'name' => !empty($item['product_name'])
                                    ? $item['product_name']
                                    : ($item['item_description'] ?: 'Item'),
                                'vendor' => $item['supplier_name'] ?: ($item['vendor'] ?: ''),
                                'quantity' => (int) $item['quantity'],
                                'unit_price' => (float) $item['unit_price'],
                                'markup' => (float) $item['profit_markup'],
                                'total_price' => (float) $item['total_price'],
                            ];

                        }, $items));

                        ?>


                        <tr>

                            <!-- REF -->

                            <td>

                                <span class="request-code">

                                    <?= htmlspecialchars(
                                        $request['request_code']
                                    ) ?>

                                </span>

                            </td>


                            <!-- ITEMS -->

                            <td>

                                <div class="items-count">

                                    <?= (int) $request['item_count'] ?>
                                    item<?= (int) $request['item_count'] === 1 ? '' : 's' ?>

                                </div>


                                <?php if ($itemsSummary !== ''): ?>

                                        <div class="items-summary" title="<?= htmlspecialchars($itemsSummary) ?>">

                                            <?= htmlspecialchars($itemsSummary) ?>

                                        </div>

                                <?php endif; ?>

                            </td>


                            <!-- CATEGORY -->

                            <td>

                                <?= htmlspecialchars($category) ?>

                            </td>


                            <!-- TOTAL -->

                            <td class="fw-semibold">

                                ₱
                                <?= number_format(
                                    (float) $request['total_price'],
                                    2
                                ) ?>

                            </td>


                            <!-- REASON -->

                            <td>

                                <span title="<?= htmlspecialchars(
                                    $request['reason']
                                ) ?>">

                                    <?= htmlspecialchars(
                                        mb_strimwidth(
                                            $request['reason'],
                                            0,
                                            35,
                                            '...'
                                        )
                                    ) ?>

                                </span>

                            </td>


                            <!-- DATE -->

                            <td>

                                <?= date(
                                    'Y-m-d',
                                    strtotime(
                                        $request['created_at']
                                    )
                                ) ?>

                            </td>


                            <!-- STATUS -->

                            <td>

                                <span class="status-badge <?= $statusClass ?>">

                                    <?= htmlspecialchars(
                                        $status
                                    ) ?>

                                </span>

                            </td>


                            <!-- ACTION -->

                            <td class="text-center">

                                <button type="button" class="btn-view" title="Review Request"
                                    data-id="<?= (int) $request['request_id'] ?>"
                                    data-ref="<?= htmlspecialchars($request['request_code'], ENT_QUOTES) ?>"
                                    data-category="<?= htmlspecialchars($category, ENT_QUOTES) ?>"
                                    data-total="<?= (float) $request['total_price'] ?>"
                                    data-reason="<?= htmlspecialchars($request['reason'], ENT_QUOTES) ?>"
                                    data-status="<?= htmlspecialchars($request['status'], ENT_QUOTES) ?>"
                                    data-finance-remarks="<?= htmlspecialchars($request['finance_remarks'] ?? '', ENT_QUOTES) ?>"
                                    data-items="<?= htmlspecialchars($itemsPayload, ENT_QUOTES) ?>"
                                    data-bs-toggle="modal" data-bs-target="#reviewRequestModal">

                                    <i class="bi bi-eye"></i>

                                </button>

                            </td>

                        </tr>

                <?php endforeach; ?>


                <?php if (count($requests) === 0): ?>

                        <tr>

                            <td colspan="8" class="text-center py-5 text-muted">

                                <i class="bi bi-inbox fs-1 d-block mb-2"></i>

                                No stock requests found.

                            </td>

                        </tr>

                <?php endif; ?>

            </tbody>

        </table>

    </div>

</div>


<!-- =========================================================
     REVIEW MODAL
========================================================= -->

<div class="modal fade" id="reviewRequestModal" tabindex="-1">

    <div class="modal-dialog modal-dialog-centered modal-lg">

        <div class="modal-content">

            <div class="modal-header">

                <div>

                    <h5 class="modal-title fw-bold">

                        <i class="bi bi-clipboard-check me-2"></i>

                        Review Stock Request

                    </h5>

                    <small class="text-muted">

                        Final validation by Admin.

                    </small>

                </div>


                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>

            </div>


            <div class="modal-body">

                <div class="row g-3">


                    <!-- REQUEST -->

                    <div class="col-6">

                        <label class="form-label">
                            Request
                        </label>

                        <input type="text" id="review_ref" class="form-control" readonly>

                    </div>


                    <!-- CATEGORY -->

                    <div class="col-6">

                        <label class="form-label">
                            Category
                        </label>

                        <input type="text" id="review_category" class="form-control" readonly>

                    </div>


                    <!-- ITEM BREAKDOWN -->

                    <div class="col-12">

                        <label class="form-label">
                            Items
                        </label>

                        <div class="table-responsive border rounded-3">

                            <table id="review_items_table" class="table table-sm mb-0">

                                <thead>
                                    <tr>
                                        <th>Item</th>
                                        <th>Qty</th>
                                        <th>Unit Price</th>
                                        <th>Markup</th>
                                        <th>Total</th>
                                    </tr>
                                </thead>

                                <tbody id="review_items_body">
                                    <!-- populated by JS -->
                                </tbody>

                            </table>

                        </div>

                    </div>


                    <!-- TOTAL -->

                    <div class="col-6">

                        <label class="form-label">
                            Total Cost
                        </label>

                        <input type="text" id="review_total" class="form-control fw-semibold" readonly>

                    </div>


                    <!-- FINANCE STATUS -->

                    <div class="col-12">

                        <div id="financeApprovedBox" class="finance-approved-box">

                            <i class="bi bi-check-circle me-1"></i>

                            Finance has approved this request.

                        </div>

                    </div>


                    <!-- REASON -->

                    <div class="col-12">

                        <label class="form-label">
                            Request Reason
                        </label>

                        <textarea id="review_reason" class="form-control" rows="3" readonly></textarea>

                    </div>

                </div>

            </div>


            <!-- FOOTER -->

            <div class="modal-footer">


                <!-- CLOSE -->

                <button type="button" class="btn btn-light border" data-bs-dismiss="modal">

                    Close

                </button>


                <!-- REJECT -->

                <button type="button" class="btn btn-danger" id="openRejectButton">

                    <i class="bi bi-x-lg me-1"></i>

                    Reject

                </button>


                <!-- APPROVE -->

                <form method="POST" id="approveForm">

                    <input type="hidden" name="request_id" id="approve_request_id">


                    <button type="submit" name="approveRequest" id="approveButton" class="btn text-white"
                        style="background:#00224c;">

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

                        <i class="bi bi-x-circle me-2 text-danger"></i>

                        Reject Stock Request

                    </h5>


                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>

                </div>


                <div class="modal-body">

                    <label class="form-label">

                        Reason for Rejection

                        <span class="text-danger">
                            *
                        </span>

                    </label>


                    <textarea name="admin_remarks" class="form-control" rows="4" maxlength="500"
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
     DATATABLE
========================================================= -->





<script>

    document.addEventListener(
        "DOMContentLoaded",
        function () {


            /*
            |--------------------------------------------------------------------------
            | REVIEW BUTTON
            |--------------------------------------------------------------------------
            */

            document
                .querySelectorAll(".btn-view")
                .forEach(function (button) {

                    button.addEventListener(
                        "click",
                        function () {

                            const status =
                                this.dataset.status;


                            document.getElementById(
                                "review_ref"
                            ).value =
                                this.dataset.ref;


                            document.getElementById(
                                "review_category"
                            ).value =
                                this.dataset.category;


                            document.getElementById(
                                "review_total"
                            ).value =
                                "₱" +
                                Number(
                                    this.dataset.total
                                ).toLocaleString(
                                    undefined,
                                    {
                                        minimumFractionDigits: 2
                                    }
                                );


                            document.getElementById(
                                "review_reason"
                            ).value =
                                this.dataset.reason;


                            document.getElementById(
                                "approve_request_id"
                            ).value =
                                this.dataset.id;


                            document.getElementById(
                                "reject_request_id"
                            ).value =
                                this.dataset.id;


                            /*
                            |--------------------------------------------------------------------------
                            | ITEM BREAKDOWN
                            |--------------------------------------------------------------------------
                            */

                            const itemsBody =
                                document.getElementById(
                                    "review_items_body"
                                );

                            itemsBody.innerHTML = "";

                            let items = [];

                            try {
                                items = JSON.parse(this.dataset.items || "[]");
                            } catch (e) {
                                items = [];
                            }

                            if (items.length === 0) {

                                itemsBody.innerHTML =
                                    '<tr><td colspan="5" class="text-center text-muted py-3">No line items found.</td></tr>';

                            } else {

                                items.forEach(function (item) {

                                    const row = document.createElement("tr");

                                    const nameCell =
                                        '<div class="item-name">' + item.name + '</div>' +
                                        (item.vendor ? '<div class="item-vendor">' + item.vendor + '</div>' : '');

                                    row.innerHTML =
                                        '<td>' + nameCell + '</td>' +
                                        '<td>' + Number(item.quantity).toLocaleString() + '</td>' +
                                        '<td>₱' + Number(item.unit_price).toLocaleString(undefined, { minimumFractionDigits: 2 }) + '</td>' +
                                        '<td>' + Number(item.markup).toFixed(2) + '%</td>' +
                                        '<td class="fw-semibold">₱' + Number(item.total_price).toLocaleString(undefined, { minimumFractionDigits: 2 }) + '</td>';

                                    itemsBody.appendChild(row);

                                });

                            }


                            /*
                            |--------------------------------------------------------------------------
                            | ONLY PENDING ADMIN CAN BE PROCESSED
                            |--------------------------------------------------------------------------
                            */

                            const approveButton =
                                document.getElementById(
                                    "approveButton"
                                );

                            const rejectButton =
                                document.getElementById(
                                    "openRejectButton"
                                );


                            if (
                                status !==
                                "Pending Admin"
                            ) {

                                approveButton.disabled =
                                    true;

                                rejectButton.disabled =
                                    true;

                            } else {

                                approveButton.disabled =
                                    false;

                                rejectButton.disabled =
                                    false;

                            }

                        }
                    );

                });


            /*
            |--------------------------------------------------------------------------
            | OPEN REJECT MODAL
            |--------------------------------------------------------------------------
            */

            const openRejectButton =
                document.getElementById(
                    "openRejectButton"
                );


            if (openRejectButton) {

                openRejectButton.addEventListener(
                    "click",
                    function () {

                        const reviewModalElement =
                            document.getElementById(
                                "reviewRequestModal"
                            );


                        const reviewModal =
                            bootstrap.Modal.getInstance(
                                reviewModalElement
                            );


                        if (reviewModal) {
                            reviewModal.hide();
                        }


                        setTimeout(
                            function () {

                                const rejectModal =
                                    new bootstrap.Modal(
                                        document.getElementById(
                                            "rejectRequestModal"
                                        )
                                    );


                                rejectModal.show();

                            },
                            250
                        );

                    }
                );

            }


            /*
            |--------------------------------------------------------------------------
            | DATATABLE
            |--------------------------------------------------------------------------
            */

            if (
                typeof DataTable !==
                "undefined"
            ) {

                new DataTable(
                    "#adminRequestTable",
                    {

                        pageLength: 8,

                        lengthChange: false,

                        searching: true,

                        ordering: true,

                        paging: true,

                        info: true,

                        order: [
                            [0, "desc"]
                        ],

                        columnDefs: [

                            {
                                orderable: false,
                                targets: 7
                            }

                        ],

                        language: {

                            search: "",

                            searchPlaceholder:
                                "Search stock request...",

                            info:
                                "Showing _END_ of _TOTAL_",

                            infoEmpty:
                                "Showing 0 of 0",

                            zeroRecords:
                                "No stock requests found",

                            emptyTable:
                                "No stock requests found",

                            paginate: {

                                first: "«",

                                previous: "‹",

                                next: "›",

                                last: "»"

                            }

                        }

                    }
                );

            }

        }
    );

</script>


<!-- =========================================================
     SESSION ALERT
========================================================= -->

<?php

if (isset($_SESSION['alert'])):

    $alert =
        $_SESSION['alert'];

    unset($_SESSION['alert']);

    ?>

        <script>

            Swal.fire({

                icon:
                    <?= json_encode(
                        $alert['icon']
                    ) ?>,

                title:
                    <?= json_encode(
                        $alert['title']
                    ) ?>,

                text:
                    <?= json_encode(
                        $alert['text']
                    ) ?>,

                confirmButtonColor:
                    "#00224c"

            });

        </script>

<?php endif; ?>


<?php if ($stockAutoApproves): ?>

    <style>
        .capital-chip {
            display: flex;
            align-items: center;
            gap: 10px;
            border: 1px solid #dce3eb;
            border-radius: 30px;
            padding: 8px 8px 8px 18px;
            background: #fff;
        }

        .capital-chip span {
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: .04em;
            color: #718096;
            font-weight: 600;
        }

        .capital-chip strong {
            color: #00224c;
            font-size: 16px;
        }

        .btn-add-capital {
            border: none;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background: #00224c;
            color: #fff;
            line-height: 1;
        }

        .btn-add-capital:hover {
            background: #fbbd23;
            color: #00224c;
        }
    </style>

    <div class="modal fade" id="addCapitalModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="border:none;border-radius:16px;">

                <div class="modal-header">
                    <h5 class="modal-title" style="font-weight:700;color:#00224c;">
                        <i class="bi bi-cash-stack me-2"></i>Add Capital
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <form method="POST" action="stock_requests.php">
                    <div class="modal-body">

                        <p class="text-muted" style="font-size:14px;">
                            Current capital is
                            <strong>&#8369;<?= number_format($stockCapital['current_capital'], 2) ?></strong>.
                            This is the money stock requests are paid from.
                        </p>

                        <label class="form-label" style="font-weight:600;font-size:13px;color:#00224c;"
                            for="capitalAmount">Amount to add <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" min="0.01" class="form-control" id="capitalAmount"
                            name="capital_amount" placeholder="0.00" required
                            style="border-radius:10px;border:1px solid #dce3eb;padding:10px 12px;">

                        <label class="form-label mt-3" style="font-weight:600;font-size:13px;color:#00224c;"
                            for="capitalNote">Note</label>
                        <input type="text" class="form-control" id="capitalNote" name="capital_note"
                            maxlength="200" placeholder="e.g. Owner's cash deposit"
                            style="border-radius:10px;border:1px solid #dce3eb;padding:10px 12px;">

                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="addCapital" class="btn btn-primary">
                            <i class="bi bi-check2 me-1"></i>Add Capital
                        </button>
                    </div>
                </form>

            </div>
        </div>
    </div>

<?php endif; ?>

<?php require __DIR__ . "/../includes/stock_request_form.php"; ?>

<?php include("admin_footer.php"); ?>