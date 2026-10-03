<?php

/*
|--------------------------------------------------------------------------
| CREATE STOCK REQUEST -- shared handler
|--------------------------------------------------------------------------
|
| Raising a stock request is done from two screens: Inventory Staff use
| inventory/stock_requests.php, and on a plan with no Inventory seat the owner
| uses admin/stock_requests.php. Both require this file so the approval
| routing and the money rules stay in one place.
|
| Expects $conn and $companyId from the caller, reads $_POST, and either
| redirects or falls through when the form was not submitted.
|
*/

if (!isset($conn, $companyId)) {
    http_response_code(403);
    exit('This page cannot be opened directly.');
}

/*
|--------------------------------------------------------------------------
| CREATE STOCK REQUEST (MULTI-ITEM)
|--------------------------------------------------------------------------
*/

if (isset($_POST['createStockRequest'])) {

    $expense_category = trim($_POST['expense_category'] ?? '');
    $category_other = trim($_POST['category_other'] ?? '');
    $reason = trim($_POST['reason'] ?? '');

    // Restocking uses real inventory products.
    // Every other category uses free-text item descriptions.
    $isRestocking = ($expense_category === 'Restocking');

    /*
    | A plan without the finance_approval module -- Retail Starter -- has no
    | Finance Staff seat, so there is nobody to approve anything and no
    | accounts_payable screen from which to settle a debt. Everything it
    | raises is paid out of capital instead.
    */
    $hasFinanceApprover = companyHasModule($conn, $companyId, 'finance_approval');

    /*
    | Who is asking, which is a different question from what the plan sells.
    |
    | Skipping the approval used to turn on the plan alone, so on Retail
    | Starter an Inventory Staff request was stamped "Admin Approved" the
    | instant it was raised -- with the requester's own id written into
    | admin_approved_by -- and it dropped straight into Receive Deliveries.
    | The owner never saw it, and the audit trail claimed they had approved
    | it.
    |
    | The intent, as the note at the top of this file says, was the case
    | where the OWNER raises the request themselves. Nobody sits above them,
    | so asking them to approve their own request proves nothing.
    |
    | Anyone else raising one waits for the owner.
    */
    $raisedByOwner = strtolower((string) ($_SESSION['role'] ?? '')) === 'admin';

    $autoApprove = !$hasFinanceApprover && $raisedByOwner;

    /*
    | PATH B settles the spend on the spot. Whether that is a deduction from
    | capital or a debt raised in Accounts Payable depends on whether the
    | company has an Accounts Payable screen at all -- not on who filled in
    | the form.
    |
    | This used to read $autoApprove, which conflated the two. Once approval
    | started depending on the requester, a non-restocking request raised by
    | Inventory Staff on Retail Starter would have been billed to a payables
    | screen their plan does not include, where it could never be settled.
    */
    $settlesFromCapital = !$hasFinanceApprover;

    /*
    | Which path a request takes.
    |
    | PATH A ends at Receive Deliveries, and that screen only handles items
    | carrying a real product_id -- receiving means putting stock on a shelf.
    | So only Restocking can go that way; anything else sent down PATH A would
    | sit at "Admin Approved" forever, never received and never paid.
    |
    | PATH B is the settle-it-now path. Normally that means Utilities, billed
    | to Accounts Payable. With no Finance approver it also takes every other
    | non-restocking category, and settles against capital rather than raising
    | a payable nobody could ever open.
    |
    | This turns on the plan, not on who is asking: a company with no Finance
    | approver has no Accounts Payable screen whoever raises the request.
    */
    if (!$hasFinanceApprover) {
        $requiresApproval = $isRestocking;
    } else {
        // Everything EXCEPT Utilities goes through Finance/Admin approval first.
        $requiresApproval = ($expense_category !== 'Utilities');
    }

    $itemProductIds = $_POST['item_product_id'] ?? [];
    $itemDescriptions = $_POST['item_description'] ?? [];
    $itemVendors = $_POST['item_vendor'] ?? [];
    $itemQuantities = $_POST['item_quantity'] ?? [];
    $itemUnitPrices = $_POST['item_unit_price'] ?? [];
    $itemMarkups = $_POST['item_markup'] ?? [];


    /*
    |--------------------------------------------------------------------------
    | BASIC VALIDATION
    |--------------------------------------------------------------------------
    */

    if ($expense_category === '') {

        $_SESSION['alert'] = [
            "icon" => "error",
            "title" => "Validation Error",
            "text" => "Please select an expense category."
        ];

        header("Location: stock_requests.php");
        exit;
    }

    if ($expense_category === 'Others' && $category_other === '') {

        $_SESSION['alert'] = [
            "icon" => "error",
            "title" => "Validation Error",
            "text" => "Please specify the expense category."
        ];

        header("Location: stock_requests.php");
        exit;
    }

    if ($reason === '') {

        $_SESSION['alert'] = [
            "icon" => "error",
            "title" => "Reason Required",
            "text" => "Please provide a reason / purpose for this request."
        ];

        header("Location: stock_requests.php");
        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | BUILD + VALIDATE ITEM ROWS
    |--------------------------------------------------------------------------
    */

    $items = [];

    // whichever array drives the row count depends on the mode
    $rowCount = $isRestocking ? count($itemProductIds) : count($itemDescriptions);

    for ($index = 0; $index < $rowCount; $index++) {

        $product_id = $isRestocking ? (int) ($itemProductIds[$index] ?? 0) : null;
        $item_description = $isRestocking ? null : trim($itemDescriptions[$index] ?? '');
        $vendor = $isRestocking ? null : trim($itemVendors[$index] ?? '');
        $quantity = (int) ($itemQuantities[$index] ?? ($isRestocking ? 0 : 1));
        $unit_price = (float) ($itemUnitPrices[$index] ?? 0);
        $profit_markup = (float) ($itemMarkups[$index] ?? 0);

        $isCompletelyEmpty = $isRestocking
            ? ($product_id <= 0 && $quantity <= 0 && $unit_price <= 0)
            : ($item_description === '' && $vendor === '' && $unit_price <= 0);

        if ($isCompletelyEmpty) {
            // skip blank rows the user didn't fill in
            continue;
        }

        if ($isRestocking && ($product_id <= 0 || $quantity <= 0 || $unit_price <= 0)) {

            $_SESSION['alert'] = [
                "icon" => "error",
                "title" => "Incomplete Item",
                "text" => "Each item needs a product, quantity, and unit cost greater than 0."
            ];

            header("Location: stock_requests.php");
            exit;
        }

        if (!$isRestocking && ($item_description === '' || $unit_price <= 0)) {

            $_SESSION['alert'] = [
                "icon" => "error",
                "title" => "Incomplete Item",
                "text" => "Each item needs a description and an amount greater than 0."
            ];

            header("Location: stock_requests.php");
            exit;
        }

        if ($profit_markup < 0) {

            $_SESSION['alert'] = [
                "icon" => "error",
                "title" => "Invalid Markup",
                "text" => "Profit markup cannot be negative."
            ];

            header("Location: stock_requests.php");
            exit;
        }

        $items[] = [
            'product_id' => $product_id,
            'item_description' => $item_description,
            'vendor' => $vendor,
            'quantity' => $quantity,
            'unit_price' => $unit_price,
            'profit_markup' => $profit_markup,
            'total_price' => $quantity * $unit_price,
        ];
    }

    if (empty($items)) {

        $_SESSION['alert'] = [
            "icon" => "error",
            "title" => "No Items",
            "text" => "Please add at least one item to the request."
        ];

        header("Location: stock_requests.php");
        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | VALIDATE PRODUCTS + SUPPLIERS (restocking only)
    |--------------------------------------------------------------------------
    */

    if ($isRestocking) {

        $productCheck = $conn->prepare("
            SELECT
                p.product_id,
                p.supplier_id
            FROM products p
            WHERE p.product_id = ? AND p.company_id = ?
            LIMIT 1
        ");

        foreach ($items as $item) {

            $productCheck->bind_param("ii", $item['product_id'], $companyId);
            $productCheck->execute();
            $result = $productCheck->get_result();

            if ($result->num_rows === 0) {

                $productCheck->close();

                $_SESSION['alert'] = [
                    "icon" => "error",
                    "title" => "Invalid Product",
                    "text" => "One of the selected products does not exist."
                ];

                header("Location: stock_requests.php");
                exit;
            }

            $productRow = $result->fetch_assoc();

            if (empty($productRow['supplier_id'])) {

                $productCheck->close();

                $_SESSION['alert'] = [
                    "icon" => "warning",
                    "title" => "Supplier Not Assigned",
                    "text" => "One of the selected products does not have a supplier assigned yet."
                ];

                header("Location: stock_requests.php");
                exit;
            }
        }

        $productCheck->close();
    }


    /*
    |--------------------------------------------------------------------------
    | GRAND TOTAL
    |--------------------------------------------------------------------------
    */

    $grand_total = 0;

    foreach ($items as $item) {
        $grand_total += $item['total_price'];
    }


    /*
    |--------------------------------------------------------------------------
    | CREATED BY
    |--------------------------------------------------------------------------
    */

    $created_by = (int) ($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);

    if ($created_by <= 0) {

        $_SESSION['alert'] = [
            "icon" => "error",
            "title" => "Session Error",
            "text" => "Unable to identify the logged-in user."
        ];

        header("Location: stock_requests.php");
        exit;
    }


    /*
    |==========================================================================
    | PATH A: NEEDS APPROVAL (Restocking, Storage Equipment, Office Supplies,
    | Equipment Maintenance, Others) -> normal Finance/Admin approval workflow.
    | Finance picks Capital vs Accounts Payable when they approve it.
    |==========================================================================
    */

    if ($requiresApproval) {

        $codeResult = $conn->query("
            SELECT request_code
            FROM stock_requests
            WHERE company_id = " . (int) $companyId . "
            ORDER BY request_id DESC
            LIMIT 1
        ");

        $nextNumber = 1;

        if ($codeResult && $codeResult->num_rows > 0) {

            $lastRequest = $codeResult->fetch_assoc()['request_code'];

            if (preg_match('/SR-(\d+)/', $lastRequest, $matches)) {
                $nextNumber = ((int) $matches[1]) + 1;
            }
        }

        $request_code = 'SR-' . str_pad($nextNumber, 3, '0', STR_PAD_LEFT);

        $conn->begin_transaction();

        try {

            if ($autoApprove) {

                /*
                | Both approval stamps are filled with the requesting admin so
                | the audit trail says plainly who authorised the spend, and
                | payment_type is pinned to Capital -- the value Finance would
                | otherwise have chosen. The request lands in Receive
                | Deliveries ready to be confirmed, which is where capital is
                | actually deducted.
                */
                $autoRemark = 'Auto-approved: raised by the owner on a plan with no Finance approver.';

                $insertHeader = $conn->prepare("
                    INSERT INTO stock_requests
                    (
                        company_id,
                        request_code,
                        expense_category,
                        category_other,
                        reason,
                        total_price,
                        status,
                        payment_type,
                        created_by,
                        finance_approved_by,
                        finance_approved_at,
                        finance_remarks,
                        admin_approved_by,
                        admin_approved_at,
                        admin_remarks
                    )
                    VALUES
                    (
                        ?,
                        ?,
                        ?,
                        ?,
                        ?,
                        ?,
                        'Admin Approved',
                        'Capital',
                        ?,
                        ?,
                        NOW(),
                        ?,
                        ?,
                        NOW(),
                        ?
                    )
                ");

                if (!$insertHeader) {
                    throw new Exception("Failed to prepare stock request: " . $conn->error);
                }

                $insertHeader->bind_param(
                    "issssdiisis",
                    $companyId,
                    $request_code,
                    $expense_category,
                    $category_other,
                    $reason,
                    $grand_total,
                    $created_by,
                    $created_by,
                    $autoRemark,
                    $created_by,
                    $autoRemark
                );

            } else {

                /*
                | Where the request starts waiting.
                |
                | With a Finance approver it starts at Finance, who picks
                | Capital or Accounts Payable, and the owner approves after.
                |
                | Without one -- Retail Starter -- there is no Finance step to
                | wait at, so it goes straight to the owner. It used to be
                | written as 'Pending Finance' in that case too, which left it
                | sitting on a screen the plan does not include: invisible to
                | the owner and impossible to approve.
                |
                | payment_type is pinned to Capital here for the same reason
                | the auto-approved path pins it: with no Accounts Payable
                | screen, capital is the only way it can ever be settled.
                */
                $startingStatus = $hasFinanceApprover ? 'Pending Finance' : 'Pending Admin';
                $startingPayment = $hasFinanceApprover ? null : 'Capital';

                $insertHeader = $conn->prepare("
                    INSERT INTO stock_requests
                    (
                        company_id,
                        request_code,
                        expense_category,
                        category_other,
                        reason,
                        total_price,
                        status,
                        payment_type,
                        created_by
                    )
                    VALUES
                    (
                        ?,
                        ?,
                        ?,
                        ?,
                        ?,
                        ?,
                        ?,
                        ?,
                        ?
                    )
                ");

                if (!$insertHeader) {
                    throw new Exception("Failed to prepare stock request: " . $conn->error);
                }

                $insertHeader->bind_param(
                    "issssdssi",
                    $companyId,
                    $request_code,
                    $expense_category,
                    $category_other,
                    $reason,
                    $grand_total,
                    $startingStatus,
                    $startingPayment,
                    $created_by
                );
            }

            if (!$insertHeader->execute()) {
                throw new Exception("Failed to create stock request: " . $insertHeader->error);
            }

            $request_id = $insertHeader->insert_id;
            $insertHeader->close();

            $insertItem = $conn->prepare("
                INSERT INTO stock_request_items
                (
                    company_id,
                    request_id,
                    product_id,
                    item_description,
                    vendor,
                    quantity,
                    unit_price,
                    profit_markup,
                    total_price
                )
                VALUES
                (
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?
                )
            ");

            if (!$insertItem) {
                throw new Exception("Failed to prepare item insertion: " . $conn->error);
            }

            foreach ($items as $item) {

                // product_id is nullable (NULL for non-restocking items)
                $productIdParam = $item['product_id'];

                $insertItem->bind_param(
                    "iiissiddd",
                    $companyId,
                    $request_id,
                    $productIdParam,
                    $item['item_description'],
                    $item['vendor'],
                    $item['quantity'],
                    $item['unit_price'],
                    $item['profit_markup'],
                    $item['total_price']
                );

                if (!$insertItem->execute()) {
                    throw new Exception("Failed to save an item: " . $insertItem->error);
                }
            }

            $insertItem->close();

            $conn->commit();

            $_SESSION['alert'] = [
                "icon" => "success",
                "title" => $autoApprove ? "Request Approved" : "Request Submitted",

                /*
                | Says who it is waiting on. "Submitted for approval" left
                | Inventory Staff with no idea whether anything more was
                | expected of them, or who to chase.
                */
                "text" => $autoApprove
                    ? "Stock request {$request_code} is approved and waiting in Receive Deliveries. Capital is deducted when you confirm receipt."
                    : ($hasFinanceApprover
                        ? "Stock request {$request_code} has been sent to Finance for approval."
                        : "Stock request {$request_code} has been sent to the owner for approval. It moves to Receive Deliveries once approved.")
            ];

        } catch (Exception $e) {

            $conn->rollback();

            $_SESSION['alert'] = [
                "icon" => "error",
                "title" => "Request Failed",
                "text" => $e->getMessage()
            ];
        }

        header("Location: stock_requests.php");
        exit;
    }


    /*
    |==========================================================================
    | PATH B: SETTLED ON SUBMISSION -> skips approval entirely.
    |    It's recorded as an already-Received stock request (so it still has
    |    a real request_id/item_id to hang off of), and how it is paid for
    |    depends on whether the plan has a Finance approver:
    |
    |    With Finance  -> Utilities only, payment_type 'Payable'. Each item is
    |                     inserted straight into `accounts_payable`, exactly
    |                     like a Finance-approved "Accounts Payable" request
    |                     that's already been received — Finance pays it
    |                     whenever from that page.
    |
    |    No Finance    -> every non-restocking category, payment_type
    |                     'Capital'. Capital is deducted here and now with a
    |                     capital_ledger entry per item, because there is no
    |                     Receive Deliveries step for items that never touch
    |                     the shelves, and no payables screen to bill.
    |
    |    No Finance/Admin approval step happens for this path either way.
    |==========================================================================
    */

    $conn->begin_transaction();

    try {

        /*
        | Paying from capital means checking there is capital to pay from.
        | The row is locked FOR UPDATE so two submissions cannot both read the
        | same balance and each decide it is affordable.
        */
        $pathBCapitalId = null;
        $pathBBalance = null;

        if ($settlesFromCapital) {

            // Guarantees the row exists so the lock below has something to take.
            ensureCompanyCapital($conn, $companyId);

            $capStmt = $conn->prepare("
                SELECT capital_id, current_capital
                FROM finance_capital
                WHERE company_id = ?
                ORDER BY capital_id ASC
                LIMIT 1
                FOR UPDATE
            ");

            if (!$capStmt) {
                throw new Exception("Unable to check available capital.");
            }

            $capStmt->bind_param("i", $companyId);
            $capStmt->execute();
            $capRow = $capStmt->get_result()->fetch_assoc();
            $capStmt->close();

            if (!$capRow) {
                throw new Exception("No capital record found for this company.");
            }

            $pathBCapitalId = (int) $capRow['capital_id'];
            $pathBBalance = (float) $capRow['current_capital'];

            if ($pathBBalance < $grand_total) {
                throw new Exception(
                    "Not enough capital. Available is PHP " . number_format($pathBBalance, 2) .
                    " but this request costs PHP " . number_format($grand_total, 2) . "."
                );
            }
        }

        // request code shares the same SR- sequence as restocking requests
        $codeResult = $conn->query("
            SELECT request_code
            FROM stock_requests
            WHERE company_id = " . (int) $companyId . "
            ORDER BY request_id DESC
            LIMIT 1
        ");

        $nextNumber = 1;

        if ($codeResult && $codeResult->num_rows > 0) {

            $lastRequest = $codeResult->fetch_assoc()['request_code'];

            if (preg_match('/SR-(\d+)/', $lastRequest, $matches)) {
                $nextNumber = ((int) $matches[1]) + 1;
            }
        }

        $request_code = 'SR-' . str_pad($nextNumber, 3, '0', STR_PAD_LEFT);

        $expenseCategoryLabel = ($expense_category === 'Others' && $category_other !== '')
            ? $category_other
            : $expense_category;

        $insertHeader = $conn->prepare("
            INSERT INTO stock_requests
            (
                company_id,
                request_code,
                expense_category,
                category_other,
                reason,
                total_price,
                status,
                payment_type,
                received_at,
                created_by
            )
            VALUES
            (
                ?, ?, ?, ?, ?, ?, 'Received', ?, NOW(), ?
            )
        ");

        if (!$insertHeader) {
            throw new Exception("Failed to prepare request: " . $conn->error);
        }

        $pathBPaymentType = $settlesFromCapital ? 'Capital' : 'Payable';

        $insertHeader->bind_param(
            "issssdsi",
            $companyId,
            $request_code,
            $expense_category,
            $category_other,
            $reason,
            $grand_total,
            $pathBPaymentType,
            $created_by
        );

        if (!$insertHeader->execute()) {
            throw new Exception("Failed to create request: " . $insertHeader->error);
        }

        $request_id = $insertHeader->insert_id;
        $insertHeader->close();

        $insertItem = $conn->prepare("
            INSERT INTO stock_request_items
            (company_id, request_id, product_id, item_description, vendor, quantity, unit_price, profit_markup, total_price)
            VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?)
        ");

        if (!$insertItem) {
            throw new Exception("Failed to prepare item insertion: " . $conn->error);
        }

        /*
        | Only one of these two is ever used, decided by $settlesFromCapital: a
        | company with a Finance approver is billed, one without pays now.
        */
        $insertAp = null;
        $insertLedger = null;

        if ($settlesFromCapital) {

            $insertLedger = $conn->prepare("
                INSERT INTO capital_ledger
                (company_id, type, reference_id, reference_code, amount, balance_after, description)
                VALUES (?, 'Stock Purchase', ?, ?, ?, ?, ?)
            ");

            if (!$insertLedger) {
                throw new Exception("Failed to prepare capital ledger entry: " . $conn->error);
            }

        } else {

            $insertAp = $conn->prepare("
                INSERT INTO accounts_payable
                (company_id, request_id, item_id, invoice_no, po_number, supplier, category, description, amount, due_date, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending')
            ");

            if (!$insertAp) {
                throw new Exception("Failed to prepare accounts payable entry: " . $conn->error);
            }
        }

        $apDueDate = date('Y-m-d', strtotime('+30 days'));

        foreach ($items as $item) {

            // fall back to the request reason if an item was left without its own description
            $description = $item['item_description'] !== '' ? $item['item_description'] : $reason;
            $vendor = $item['vendor'] !== '' ? $item['vendor'] : 'N/A';

            $insertItem->bind_param(
                "iissiddd",
                $companyId,
                $request_id,
                $description,
                $vendor,
                $item['quantity'],
                $item['unit_price'],
                $item['profit_markup'],
                $item['total_price']
            );

            if (!$insertItem->execute()) {
                throw new Exception("Failed to save an item: " . $insertItem->error);
            }

            $item_id = $insertItem->insert_id;

            if ($settlesFromCapital) {

                /*
                | Deduct the item's full cost -- quantity included. The AP
                | branch below bills unit_price only, which is how it has
                | always behaved; capital must reflect what was actually spent.
                */
                $lineCost = (float) $item['total_price'];
                $pathBBalance -= $lineCost;

                $ledgerNote = $expenseCategoryLabel . ' - ' . $description;

                $insertLedger->bind_param(
                    "iisdds",
                    $companyId,
                    $request_id,
                    $request_code,
                    $lineCost,
                    $pathBBalance,
                    $ledgerNote
                );

                if (!$insertLedger->execute()) {
                    throw new Exception("Failed to record capital ledger entry: " . $insertLedger->error);
                }

            } else {

                $invoiceNo = 'INV-' . date('Y') . '-' . str_pad((string) $item_id, 4, '0', STR_PAD_LEFT);

                $insertAp->bind_param(
                    "iiisssssds",
                    $companyId,
                    $request_id,
                    $item_id,
                    $invoiceNo,
                    $request_code,
                    $vendor,
                    $expenseCategoryLabel,
                    $description,
                    $item['unit_price'],
                    $apDueDate
                );

                if (!$insertAp->execute()) {
                    throw new Exception("Failed to record accounts payable entry: " . $insertAp->error);
                }
            }
        }

        $insertItem->close();

        if ($settlesFromCapital) {

            $insertLedger->close();

            $writeBalance = $conn->prepare("
                UPDATE finance_capital SET current_capital = ? WHERE capital_id = ?
            ");

            if (!$writeBalance) {
                throw new Exception("Unable to update capital.");
            }

            $writeBalance->bind_param("di", $pathBBalance, $pathBCapitalId);

            if (!$writeBalance->execute()) {
                $writeBalance->close();
                throw new Exception("Unable to update capital.");
            }

            $writeBalance->close();

        } else {
            $insertAp->close();
        }

        $conn->commit();

        $_SESSION['alert'] = [
            "icon" => "success",
            "title" => $settlesFromCapital ? "Paid from Capital" : "Sent to Accounts Payable",
            "text" => $settlesFromCapital
                ? "₱" . number_format($grand_total, 2) . " for " . $expenseCategoryLabel .
                  " was deducted from capital. Remaining capital is ₱" . number_format($pathBBalance, 2) . "."
                : (count($items) > 1
                    ? count($items) . " items totaling ₱" . number_format($grand_total, 2) . " under " . $expenseCategoryLabel . " have been added to Accounts Payable."
                    : "This " . $expenseCategoryLabel . " expense has been added to Accounts Payable.")
        ];

    } catch (Exception $e) {

        $conn->rollback();

        $_SESSION['alert'] = [
            "icon" => "error",
            "title" => "Request Failed",
            "text" => $e->getMessage()
        ];
    }

    header("Location: stock_requests.php");
    exit;
}


