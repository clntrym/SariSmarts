<?php

/*
|--------------------------------------------------------------------------
| RECEIVE DELIVERIES -- shared page body
|--------------------------------------------------------------------------
|
| Confirming a delivery is the step that moves money: it deducts
| finance_capital, writes capital_ledger rows, or -- for a Payable request --
| raises accounts_payable, and only then adds the stock.
|
| Two roles need that screen. Retail Professional has Inventory Staff who do
| the receiving; Retail Starter has no such seat, so its admin does it. Rather
| than keep two copies of the money-touching code in step, both entry points
| are thin wrappers around this file:
|
|     inventory/receive_deliveries.php  requireRole(['inventory'])
|     admin/receive_deliveries.php      requireRole(['admin'])
|
| The caller authenticates, sets $companyId, and names its own chrome in
| $MODULE_HEADER / $MODULE_FOOTER before requiring this. Both callers sit one
| directory below the webroot, so every "../" path and every same-file
| redirect below resolves identically from either.
|
*/

if (!isset($companyId, $MODULE_HEADER, $MODULE_FOOTER)) {
    http_response_code(403);
    exit('This page cannot be opened directly.');
}


/*
|--------------------------------------------------------------------------
| CONFIRM STOCK RECEIPT
|--------------------------------------------------------------------------
| A request can now hold MULTIPLE items (multiple products), so
| receiving loops over every item in stock_request_items instead
| of reading a single product off the stock_requests header.
|
| Finance chooses a payment_type when approving the request:
|   - 'Capital'  -> capital is checked/locked and deducted here, exactly
|                    like before, with a capital_ledger entry per item.
|   - 'Payable'  -> capital is left untouched entirely; each item is
|                    instead inserted into accounts_payable so Finance
|                    can pay it later from accounts_payable.php.
|
| Either way, the inventory upsert (stock actually landing in the
| warehouse) happens the same for every item.
|--------------------------------------------------------------------------
*/

if (isset($_POST['confirmReceipt'])) {

    $request_id = (int) ($_POST['request_id'] ?? 0);

    if ($request_id <= 0) {

        $_SESSION['alert'] = [
            "icon" => "error",
            "title" => "Invalid Request",
            "text" => "Invalid stock request."
        ];

        header("Location: receive_deliveries.php");
        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | GET INVENTORY USER
    |--------------------------------------------------------------------------
    */

    $inventory_user_id = (int) (
        $_SESSION['user_id']
        ?? $_SESSION['id']
        ?? 0
    );


    /*
    |--------------------------------------------------------------------------
    | GET ADMIN-APPROVED REQUEST HEADER
    |--------------------------------------------------------------------------
    */

    $stmt = $conn->prepare("
        SELECT
            request_id,
            request_code,
            status,
            payment_type,
            expense_category,
            category_other
        FROM stock_requests
        WHERE request_id = ?
        AND company_id = ?
        AND status = 'Admin Approved'
        LIMIT 1
    ");

    if (!$stmt) {

        $_SESSION['alert'] = [
            "icon" => "error",
            "title" => "Database Error",
            "text" => "Unable to prepare stock receiving."
        ];

        header("Location: receive_deliveries.php");
        exit;
    }

    $stmt->bind_param("ii", $request_id, $companyId);
    $stmt->execute();

    $result = $stmt->get_result();

    if ($result->num_rows === 0) {

        $stmt->close();

        $_SESSION['alert'] = [
            "icon" => "warning",
            "title" => "Request Unavailable",
            "text" => "This stock request has already been received or is not yet approved by Admin."
        ];

        header("Location: receive_deliveries.php");
        exit;
    }

    $request = $result->fetch_assoc();

    $stmt->close();


    /*
    |--------------------------------------------------------------------------
    | PAYMENT TYPE CHOSEN BY FINANCE AT APPROVAL TIME
    |--------------------------------------------------------------------------
    */

    $isPayable = ($request['payment_type'] === 'Payable');


    /*
    |--------------------------------------------------------------------------
    | GET ALL ITEMS FOR THIS REQUEST
    |--------------------------------------------------------------------------
    | product_id is only NULL for non-restocking expense items, which
    | never reach stock_requests in the first place — but the filter
    | is kept here as a safety net.
    |--------------------------------------------------------------------------
    */

    $itemsStmt = $conn->prepare("
        SELECT
            sri.item_id,
            sri.product_id,
            sri.quantity,
            sri.unit_price,
            sri.profit_markup,
            p.product_name,
            p.supplier_id,
            s.supplier_name
        FROM stock_request_items sri
        INNER JOIN products p
            ON p.product_id = sri.product_id AND p.company_id = sri.company_id
        LEFT JOIN suppliers s
            ON s.supplier_id = p.supplier_id AND s.company_id = sri.company_id
        WHERE sri.request_id = ?
        AND sri.company_id = ?
        AND sri.product_id IS NOT NULL
    ");

    if (!$itemsStmt) {

        $_SESSION['alert'] = [
            "icon" => "error",
            "title" => "Database Error",
            "text" => "Unable to load request items."
        ];

        header("Location: receive_deliveries.php");
        exit;
    }

    $itemsStmt->bind_param("ii", $request_id, $companyId);
    $itemsStmt->execute();

    $itemsResult = $itemsStmt->get_result();

    $items = [];

    while ($row = $itemsResult->fetch_assoc()) {
        $items[] = $row;
    }

    $itemsStmt->close();

    if (empty($items)) {

        $_SESSION['alert'] = [
            "icon" => "error",
            "title" => "No Items",
            "text" => "This request has no items to receive."
        ];

        header("Location: receive_deliveries.php");
        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | TOTAL COST OF THIS DELIVERY (recomputed from items, not the
    | header's cached total_price, to always match what's actually
    | being received)
    |--------------------------------------------------------------------------
    */

    $total_cost = 0;

    foreach ($items as $item) {
        $total_cost += ((int) $item['quantity']) * ((float) $item['unit_price']);
    }


    /*
    |--------------------------------------------------------------------------
    | START TRANSACTION
    |--------------------------------------------------------------------------
    */

    $conn->begin_transaction();

    try {


        /*
        |--------------------------------------------------------------------------
        | CHECK & LOCK AVAILABLE CAPITAL (Capital-type requests only —
        | a Payable request never touches finance_capital)
        |--------------------------------------------------------------------------
        */

        $capital_id = null;
        $running_capital = null;

        if (!$isPayable) {

            // Guarantees the row exists so the lock below has something to take.
            ensureCompanyCapital($conn, $companyId);

            $capitalStmt = $conn->prepare("
                SELECT
                    capital_id,
                    current_capital
                FROM finance_capital
                WHERE company_id = " . (int) $companyId . "
                ORDER BY capital_id ASC
                LIMIT 1
                FOR UPDATE
            ");

            if (!$capitalStmt) {
                throw new Exception("Unable to check available capital.");
            }

            $capitalStmt->execute();

            $capitalResult = $capitalStmt->get_result();

            if ($capitalResult->num_rows === 0) {

                $capitalStmt->close();

                throw new Exception("Capital record not found. Please contact Finance.");
            }

            $capitalRow = $capitalResult->fetch_assoc();

            $capital_id = (int) $capitalRow['capital_id'];
            $running_capital = (float) $capitalRow['current_capital'];

            $capitalStmt->close();


            /*
            |--------------------------------------------------------------------------
            | BLOCK IF NOT ENOUGH CAPITAL FOR THE WHOLE DELIVERY
            |--------------------------------------------------------------------------
            */

            if ($running_capital < $total_cost) {

                throw new Exception(
                    "Insufficient capital to receive this delivery. Available: ₱" .
                    number_format($running_capital, 2) .
                    ", Required: ₱" .
                    number_format($total_cost, 2)
                );
            }
        }


        /*
        |--------------------------------------------------------------------------
        | PREPARE REUSABLE STATEMENTS (one per item, looped below)
        |--------------------------------------------------------------------------
        */

        $ledgerStmt = null;
        $apStmt = null;

        if (!$isPayable) {

            $ledgerStmt = $conn->prepare("
                INSERT INTO capital_ledger
                (
                    company_id,
                    type,
                    reference_id,
                    reference_code,
                    amount,
                    balance_after,
                    description
                )
                VALUES
                (
                    ?,
                    'Stock Purchase',
                    ?,
                    ?,
                    ?,
                    ?,
                    ?
                )
            ");

            if (!$ledgerStmt) {
                throw new Exception("Unable to prepare capital ledger entry.");
            }

        } else {

            $apStmt = $conn->prepare("
                INSERT INTO accounts_payable
                (
                    company_id,
                    request_id,
                    item_id,
                    invoice_no,
                    po_number,
                    supplier,
                    category,
                    description,
                    amount,
                    due_date,
                    status
                )
                VALUES
                (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending'
                )
            ");

            if (!$apStmt) {
                throw new Exception("Unable to prepare accounts payable entry.");
            }
        }

        $inventoryCheckStmt = $conn->prepare("
            SELECT inventory_id, quantity
            FROM inventory
            WHERE product_id = ? AND company_id = ?
            LIMIT 1
            FOR UPDATE
        ");

        if (!$inventoryCheckStmt) {
            throw new Exception("Unable to check inventory.");
        }

        /*
        | company_id is redundant here -- inventory_id comes from the scoped
        | SELECT above -- and it stays anyway. A write that can only touch
        | the right company by virtue of where its id came from is one
        | refactor away from touching the wrong one.
        */
        $updateInventoryStmt = $conn->prepare("
            UPDATE inventory
            SET
                quantity = ?,
                purchase_cost = ?,
                profit_markup = ?,
                selling_price = ?,
                purchase_date = CURDATE()
            WHERE inventory_id = ? AND company_id = ?
        ");

        if (!$updateInventoryStmt) {
            throw new Exception("Unable to update inventory.");
        }

        $insertInventoryStmt = $conn->prepare("
            INSERT INTO inventory
            (
                company_id,
                product_id,
                quantity,
                purchase_cost,
                profit_markup,
                selling_price,
                reorder_level,
                purchase_date
            )
            VALUES
            (
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                5,
                CURDATE()
            )
        ");

        if (!$insertInventoryStmt) {
            throw new Exception("Unable to create inventory record.");
        }


        /*
        |--------------------------------------------------------------------------
        | ACCOUNTS PAYABLE HELPERS (only used when $isPayable is true)
        |--------------------------------------------------------------------------
        */

        $apCategory = !empty($request['category_other'])
            ? $request['category_other']
            : ($request['expense_category'] ?? 'Restocking');

        $apDueDate = date('Y-m-d', strtotime('+30 days'));


        /*
        |--------------------------------------------------------------------------
        | LOOP OVER EVERY ITEM IN THE REQUEST
        |--------------------------------------------------------------------------
        */

        $receivedSummary = [];

        foreach ($items as $item) {

            $product_id = (int) $item['product_id'];
            $quantity = (int) $item['quantity'];
            $purchase_cost = (float) $item['unit_price'];
            $profit_markup = (float) $item['profit_markup'];

            $item_cost = $quantity * $purchase_cost;

            $selling_price = $purchase_cost + ($purchase_cost * ($profit_markup / 100));


            if (!$isPayable) {

                /*
                |--------------------------------------------------------------------------
                | CAPITAL: DEDUCT CAPITAL FOR THIS ITEM + LOG LEDGER ENTRY
                |--------------------------------------------------------------------------
                */

                $running_capital -= $item_cost;

                $ledgerDescription = "Stock received: " . $item['product_name'] . " (x" . $quantity . ")";

                $ledgerStmt->bind_param(
                    "iisdds",
                    $companyId,
                    $request_id,
                    $request['request_code'],
                    $item_cost,
                    $running_capital,
                    $ledgerDescription
                );

                if (!$ledgerStmt->execute()) {
                    throw new Exception("Unable to log capital transaction for " . $item['product_name'] . ".");
                }

            } else {

                /*
                |--------------------------------------------------------------------------
                | PAYABLE: INSERT INTO ACCOUNTS PAYABLE INSTEAD OF TOUCHING CAPITAL
                |--------------------------------------------------------------------------
                */

                $invoiceNo = 'INV-' . date('Y') . '-' . str_pad((string) $item['item_id'], 4, '0', STR_PAD_LEFT);
                $supplier = !empty($item['supplier_name']) ? $item['supplier_name'] : 'N/A';
                $description = $item['product_name'];
                $itemId = (int) $item['item_id'];

                $apStmt->bind_param(
                    "iiisssssds",
                    $companyId,
                    $request_id,
                    $itemId,
                    $invoiceNo,
                    $request['request_code'],
                    $supplier,
                    $apCategory,
                    $description,
                    $item_cost,
                    $apDueDate
                );

                if (!$apStmt->execute()) {
                    throw new Exception("Unable to record accounts payable entry for " . $item['product_name'] . ".");
                }
            }


            /*
            |--------------------------------------------------------------------------
            | UPSERT INVENTORY FOR THIS PRODUCT (always happens, regardless of
            | payment type — the stock is physically received either way)
            |--------------------------------------------------------------------------
            */

            $inventoryCheckStmt->bind_param("ii", $product_id, $companyId);
            $inventoryCheckStmt->execute();
            $inventoryResult = $inventoryCheckStmt->get_result();

            if ($inventoryResult->num_rows > 0) {

                $inventory = $inventoryResult->fetch_assoc();

                $inventory_id = (int) $inventory['inventory_id'];
                $new_quantity = ((int) $inventory['quantity']) + $quantity;

                $updateInventoryStmt->bind_param(
                    "idddii",
                    $new_quantity,
                    $purchase_cost,
                    $profit_markup,
                    $selling_price,
                    $inventory_id,
                    $companyId
                );

                if (!$updateInventoryStmt->execute()) {
                    throw new Exception("Unable to update inventory for " . $item['product_name'] . ".");
                }

            } else {

                $insertInventoryStmt->bind_param(
                    "iiiddd",
                    $companyId,
                    $product_id,
                    $quantity,
                    $purchase_cost,
                    $profit_markup,
                    $selling_price
                );

                if (!$insertInventoryStmt->execute()) {
                    throw new Exception("Unable to create inventory record for " . $item['product_name'] . ".");
                }
            }

            $receivedSummary[] = $item['product_name'] . " (" . number_format($quantity) . ")";
        }

        if ($ledgerStmt) {
            $ledgerStmt->close();
        }

        if ($apStmt) {
            $apStmt->close();
        }

        $inventoryCheckStmt->close();
        $updateInventoryStmt->close();
        $insertInventoryStmt->close();


        /*
        |--------------------------------------------------------------------------
        | UPDATE FINANCE CAPITAL TO ITS FINAL BALANCE (Capital-type only)
        |--------------------------------------------------------------------------
        */

        if (!$isPayable) {

            $deductCapital = $conn->prepare("
                UPDATE finance_capital
                SET current_capital = ?
                WHERE capital_id = ?
            ");

            if (!$deductCapital) {
                throw new Exception("Unable to update capital.");
            }

            $deductCapital->bind_param("di", $running_capital, $capital_id);

            if (!$deductCapital->execute()) {
                $deductCapital->close();
                throw new Exception("Unable to update capital.");
            }

            $deductCapital->close();
        }


        /*
        |--------------------------------------------------------------------------
        | MARK REQUEST AS RECEIVED
        |--------------------------------------------------------------------------
        */

        $receiveStmt = $conn->prepare("
            UPDATE stock_requests
            SET
                status = 'Received',
                received_by = NULLIF(?, 0),
                received_at = NOW()
            WHERE request_id = ? AND company_id = ?
            AND status = 'Admin Approved'
        ");

        if (!$receiveStmt) {
            throw new Exception("Unable to update stock request.");
        }

        $receiveStmt->bind_param("iii", $inventory_user_id, $request_id, $companyId);

        if (!$receiveStmt->execute() || $receiveStmt->affected_rows <= 0) {

            $receiveStmt->close();

            throw new Exception("Stock request has already been processed.");
        }

        $receiveStmt->close();


        /*
        |--------------------------------------------------------------------------
        | COMMIT
        |--------------------------------------------------------------------------
        */

        $conn->commit();

        if ($isPayable) {

            $_SESSION['alert'] = [
                "icon" => "success",
                "title" => "Stock Received",
                "text" =>
                    implode(', ', $receivedSummary) .
                    " added to inventory. ₱" .
                    number_format($total_cost, 2) .
                    " was added to Accounts Payable (due " .
                    date('M d, Y', strtotime($apDueDate)) .
                    ")."
            ];

        } else {

            $_SESSION['alert'] = [
                "icon" => "success",
                "title" => "Stock Received",
                "text" =>
                    implode(', ', $receivedSummary) .
                    " added to inventory. ₱" .
                    number_format($total_cost, 2) .
                    " was deducted from available capital."
            ];
        }

    } catch (Exception $e) {

        /*
        |--------------------------------------------------------------------------
        | ROLLBACK
        |--------------------------------------------------------------------------
        */

        $conn->rollback();

        $_SESSION['alert'] = [
            "icon" => "error",
            "title" => "Receiving Failed",
            "text" => $e->getMessage()
        ];
    }

    header("Location: receive_deliveries.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| LOAD DELIVERIES (headers, then items grouped in PHP)
|--------------------------------------------------------------------------
*/

$deliveries = [];

/*
| Scoped to the company.
|
| This listed every approved delivery on the platform. Status was the only
| filter, so one shop's Receive Deliveries showed other shops' stock
| requests -- their codes, their totals, and through the items query below
| their products and suppliers. Confirming one of those would have moved
| another company's stock and money.
|
| The EXISTS subquery is scoped as well. Matching on request_id alone would
| be correct only because the outer query is now scoped, and that is the
| kind of reasoning that quietly stops holding the next time this is
| edited.
*/
$headers = $conn->prepare("
    SELECT
        sr.request_id,
        sr.request_code,
        sr.total_price,
        sr.status,
        sr.payment_type,
        sr.created_at,
        sr.admin_approved_at,
        sr.received_at
    FROM stock_requests sr
    WHERE sr.company_id = ?
      AND sr.status IN ('Admin Approved', 'Received')
      AND EXISTS (
          SELECT 1
          FROM stock_request_items x
          WHERE x.request_id = sr.request_id
            AND x.company_id = sr.company_id
            AND x.product_id IS NOT NULL
      )
    ORDER BY
        CASE
            WHEN sr.status = 'Admin Approved' THEN 1
            ELSE 2
        END,
        sr.request_id DESC
");

if ($headers) {

    $headers->bind_param("i", $companyId);
    $headers->execute();
    $query = $headers->get_result();

    while ($row = $query->fetch_assoc()) {
        $deliveries[] = $row;
    }

    $headers->close();
}


/*
|--------------------------------------------------------------------------
| LOAD ITEMS FOR ALL VISIBLE DELIVERIES (one query, grouped in PHP)
|--------------------------------------------------------------------------
*/

$itemsByRequest = [];

if (!empty($deliveries)) {

    $requestIds = [];

    foreach ($deliveries as $delivery) {
        $requestIds[] = (int) $delivery['request_id'];
    }

    $placeholders = implode(',', array_fill(0, count($requestIds), '?'));

    /* The leading 'i' is the company, then one per request id. */
    $types = 'i' . str_repeat('i', count($requestIds));

    /*
    | Scoped by company as well as by request id, and the joins with it.
    |
    | The ids come from a query that is now company-scoped, so this would be
    | safe without it -- but a product or supplier joined without a company
    | check is exactly how the names of another shop's goods end up on this
    | page, and the id list is only one edit away from widening again.
    */
    $itemsQuery = $conn->prepare("
        SELECT
            sri.request_id,
            sri.quantity,
            p.product_name,
            s.supplier_name
        FROM stock_request_items sri
        INNER JOIN products p
            ON p.product_id = sri.product_id
           AND p.company_id = sri.company_id
        LEFT JOIN suppliers s
            ON s.supplier_id = p.supplier_id
           AND s.company_id = p.company_id
        WHERE sri.company_id = ?
          AND sri.request_id IN ($placeholders)
          AND sri.product_id IS NOT NULL
        ORDER BY sri.item_id ASC
    ");

    if ($itemsQuery) {

        // bind_param requires by-reference arguments, so build the
        // reference array explicitly rather than spreading values
        $bindParams = [$types, &$companyId];

        foreach ($requestIds as $key => $value) {
            $bindParams[] = &$requestIds[$key];
        }

        call_user_func_array([$itemsQuery, 'bind_param'], $bindParams);

        $itemsQuery->execute();
        $itemsResult = $itemsQuery->get_result();

        while ($item = $itemsResult->fetch_assoc()) {
            $itemsByRequest[$item['request_id']][] = $item;
        }

        $itemsQuery->close();
    }
}

foreach ($deliveries as &$delivery) {

    $items = $itemsByRequest[$delivery['request_id']] ?? [];

    $delivery['items'] = $items;
    $delivery['item_count'] = count($items);

    $totalQuantity = 0;
    $suppliers = [];

    foreach ($items as $item) {
        $totalQuantity += (int) $item['quantity'];
        $suppliers[$item['supplier_name'] ?: 'No Supplier'] = true;
    }

    $delivery['total_quantity'] = $totalQuantity;

    $supplierNames = array_keys($suppliers);

    $delivery['supplier_label'] = count($supplierNames) === 1
        ? $supplierNames[0]
        : (count($supplierNames) > 1 ? 'Multiple Suppliers' : 'No Supplier');

}
unset($delivery);


/*
|--------------------------------------------------------------------------
| HEADER
|--------------------------------------------------------------------------
*/

include($MODULE_HEADER);

?>

<style>
    .receive-page {
        padding: 10px 4px 40px;
        background: #f4f7fa;
        min-height: calc(100vh - 80px);
    }


    .receive-title {
        color: #00224c;
        font-size: 34px;
        font-weight: 700;
        margin-bottom: 2px;
    }


    .receive-subtitle {
        color: #64748b;
        margin-bottom: 28px;
    }


    /*
    |--------------------------------------------------------------------------
    | DELIVERY CARD
    |--------------------------------------------------------------------------
    */

    .delivery-card {

        background: #ffffff;

        border: 1px solid #dce3ea;

        border-radius: 14px;

        padding: 20px;

        box-shadow:
            0 2px 8px rgba(0, 34, 76, .05);

        height: 100%;

        transition: .2s;
    }


    .delivery-card:hover {

        transform: translateY(-2px);

        box-shadow:
            0 6px 18px rgba(0, 34, 76, .09);
    }


    /*
    |--------------------------------------------------------------------------
    | TOP
    |--------------------------------------------------------------------------
    */

    .delivery-top {

        display: flex;

        align-items: flex-start;

        justify-content: space-between;

        gap: 8px;

        margin-bottom: 16px;
    }


    .delivery-top-badges {

        display: flex;

        flex-direction: column;

        align-items: flex-end;

        gap: 6px;
    }


    .delivery-icon {

        width: 48px;
        height: 48px;

        border-radius: 14px;

        background: #e9edf2;

        color: #00224c;

        display: flex;

        align-items: center;

        justify-content: center;

        font-size: 22px;
    }


    /*
    |--------------------------------------------------------------------------
    | STATUS
    |--------------------------------------------------------------------------
    */

    .delivery-status {

        display: inline-flex;

        align-items: center;

        padding: 4px 11px;

        border-radius: 999px;

        font-size: 12px;

        font-weight: 600;
    }


    .status-arriving {

        background: #e0e7ff;

        color: #3730a3;

        border: 1px solid #c7d2fe;
    }


    .status-received {

        background: #dcfce7;

        color: #15803d;

        border: 1px solid #bbf7d0;
    }


    /*
    |--------------------------------------------------------------------------
    | PAYMENT TYPE BADGE
    |--------------------------------------------------------------------------
    */

    .payment-badge {

        display: inline-flex;

        align-items: center;

        gap: 4px;

        padding: 4px 11px;

        border-radius: 999px;

        font-size: 11px;

        font-weight: 600;
    }

    .payment-badge-capital {

        background: #e9edf2;

        color: #00224c;

        border: 1px solid #d9e0e7;
    }

    .payment-badge-payable {

        background: #f3e8ff;

        color: #7c3aed;

        border: 1px solid #e9d5ff;
    }


    /*
    |--------------------------------------------------------------------------
    | SUPPLIER
    |--------------------------------------------------------------------------
    */

    .supplier-title {

        color: #00224c;

        font-size: 16px;

        font-weight: 700;

        margin-bottom: 8px;
    }


    .delivery-info {

        color: #64748b;

        font-size: 14px;

        margin-bottom: 12px;
    }


    .delivery-info i {

        color: #64748b;
    }


    /*
    |--------------------------------------------------------------------------
    | ITEM LIST
    |--------------------------------------------------------------------------
    */

    .delivery-items {

        margin-bottom: 16px;
    }

    .delivery-item-row {

        display: flex;
        justify-content: space-between;
        gap: 8px;

        font-size: 13px;
        color: #34495e;

        padding: 4px 0;
    }

    .delivery-item-row+.delivery-item-row {
        border-top: 1px dashed #e2e8f0;
    }

    .delivery-item-name {
        flex: 1;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .delivery-item-qty {
        flex-shrink: 0;
        font-weight: 600;
        color: #00224c;
    }


    /*
    |--------------------------------------------------------------------------
    | CONFIRM BUTTON
    |--------------------------------------------------------------------------
    */

    .btn-confirm {

        width: 100%;

        border: none;

        border-radius: 999px;

        background: #00224c;

        color: #ffffff;

        padding: 8px 14px;

        font-size: 13px;

        font-weight: 600;

        transition: .2s;
    }


    .btn-confirm:hover {

        background: #00366f;

        color: #ffffff;
    }


    /*
    |--------------------------------------------------------------------------
    | RECEIVED LABEL
    |--------------------------------------------------------------------------
    */

    .received-label {

        width: 100%;

        text-align: center;

        color: #15803d;

        font-size: 13px;

        font-weight: 600;

        padding: 8px;
    }


    /*
    |--------------------------------------------------------------------------
    | EMPTY
    |--------------------------------------------------------------------------
    */

    .empty-deliveries {

        background: #ffffff;

        border: 1px solid #dce3ea;

        border-radius: 14px;

        padding: 60px 20px;

        text-align: center;

        color: #64748b;
    }


    .empty-deliveries i {

        font-size: 42px;

        color: #94a3b8;

        display: block;

        margin-bottom: 12px;
    }


    /*
    |--------------------------------------------------------------------------
    | MOBILE
    |--------------------------------------------------------------------------
    */

    @media(max-width:768px) {

        .receive-title {
            font-size: 28px;
        }

    }
</style>


<div class="container-fluid receive-page">

    <!-- HEADER -->

    <div>

        <h1 class="receive-title">
            Receive Deliveries
        </h1>

        <div class="receive-subtitle">
            Verify quantities and confirm stock updates.
        </div>

    </div>


    <?php if (count($deliveries) > 0): ?>

        <div class="row g-3">

            <?php foreach ($deliveries as $delivery): ?>

                <?php

                $isReceived = $delivery['status'] === 'Received';
                $deliveryPaymentType = $delivery['payment_type'] ?? 'Capital';
                $deliveryIsPayable = $deliveryPaymentType === 'Payable';

                ?>


                <div class="col-12 col-md-6 col-xl-4">

                    <div class="delivery-card">

                        <!-- TOP -->

                        <div class="delivery-top">

                            <div class="delivery-icon">

                                <i class="bi bi-truck"></i>

                            </div>


                            <div class="delivery-top-badges">

                                <?php if (!$isReceived): ?>

                                    <span class="delivery-status status-arriving">

                                        Admin Approved

                                    </span>

                                <?php else: ?>

                                    <span class="delivery-status status-received">

                                        Received

                                    </span>

                                <?php endif; ?>


                                <span
                                    class="payment-badge <?= $deliveryIsPayable ? 'payment-badge-payable' : 'payment-badge-capital' ?>">

                                    <i class="bi <?= $deliveryIsPayable ? 'bi-file-earmark-text' : 'bi-cash-coin' ?>"></i>

                                    <?= $deliveryIsPayable ? 'Accounts Payable' : 'Capital' ?>

                                </span>

                            </div>

                        </div>


                        <!-- SUPPLIER -->

                        <div class="supplier-title">

                            <?= htmlspecialchars($delivery['supplier_label']) ?>

                        </div>


                        <!-- INFO -->

                        <div class="delivery-info">

                            <i class="bi bi-box-seam me-1"></i>

                            <?= number_format($delivery['total_quantity']) ?>

                            items across
                            <?= (int) $delivery['item_count'] ?>
                            product<?= $delivery['item_count'] == 1 ? '' : 's' ?>

                            <span class="mx-1">
                                ·
                            </span>

                            <?php if ($isReceived): ?>

                                <?= htmlspecialchars(date('M d', strtotime($delivery['received_at']))) ?>

                            <?php else: ?>

                                <?= htmlspecialchars(date('M d', strtotime($delivery['admin_approved_at']))) ?>

                            <?php endif; ?>

                        </div>


                        <!-- ITEM LIST -->

                        <div class="delivery-items">

                            <?php foreach ($delivery['items'] as $item): ?>

                                <div class="delivery-item-row">

                                    <span class="delivery-item-name" title="<?= htmlspecialchars($item['product_name']) ?>">
                                        <?= htmlspecialchars($item['product_name']) ?>
                                    </span>

                                    <span class="delivery-item-qty">
                                        x<?= number_format((int) $item['quantity']) ?>
                                    </span>

                                </div>

                            <?php endforeach; ?>

                        </div>


                        <!-- REF / TOTAL -->

                        <div class="small text-muted mb-3">

                            Ref:
                            <?= htmlspecialchars($delivery['request_code']) ?>

                            <span class="mx-1">·</span>

                            ₱<?= number_format((float) $delivery['total_price'], 2) ?>

                        </div>


                        <?php if (!$isReceived): ?>

                            <!-- CONFIRM -->

                            <form method="POST" class="confirm-receipt-form"
                                data-payment="<?= htmlspecialchars($deliveryPaymentType, ENT_QUOTES) ?>">

                                <input type="hidden" name="request_id" value="<?= (int) $delivery['request_id'] ?>">

                                <button type="submit" name="confirmReceipt" class="btn-confirm">

                                    <i class="bi bi-check-lg me-1"></i>

                                    Confirm Received

                                </button>

                            </form>

                        <?php else: ?>

                            <div class="received-label">

                                <i class="bi bi-check-circle me-1"></i>

                                Stock Added to Inventory

                            </div>

                        <?php endif; ?>

                    </div>

                </div>

            <?php endforeach; ?>

        </div>

    <?php else: ?>

        <div class="empty-deliveries">

            <i class="bi bi-truck"></i>

            <div class="fw-semibold text-dark mb-1">

                No deliveries to receive

            </div>

            <div class="small">

                Admin-approved stock requests will appear here.

            </div>

        </div>

    <?php endif; ?>

</div>


<!-- =========================================================
     CONFIRMATION
========================================================= -->

<script>

    document.addEventListener(
        "DOMContentLoaded",
        function () {

            document
                .querySelectorAll(".confirm-receipt-form")
                .forEach(function (form) {

                    form.addEventListener(
                        "submit",
                        function (event) {

                            event.preventDefault();

                            const isPayable = form.dataset.payment === "Payable";

                            Swal.fire({

                                icon: "question",

                                title: "Confirm Stock Receipt?",

                                text: isPayable
                                    ? "The received quantities will be added to inventory. This delivery is set to Accounts Payable, so capital will NOT be deducted — an invoice will be created for Finance to pay later."
                                    : "The received quantities will be added to inventory and their cost deducted from available capital.",

                                showCancelButton: true,

                                confirmButtonText:
                                    "Yes, Confirm Receipt",

                                cancelButtonText:
                                    "Cancel",

                                confirmButtonColor:
                                    "#00224c"

                            }).then(function (result) {

                                if (result.isConfirmed) {

                                    form.submit();

                                }

                            });

                        }
                    );

                });

        }

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


<?php include($MODULE_FOOTER); ?>