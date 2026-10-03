<?php
require_once('../init.php');
requireRole(['cashier']);

/*
=========================================================
PAYMONGO HELPER
=========================================================
*/

function paymongoRequest($method, $endpoint, $data = null)
{
    $ch = curl_init("https://api.paymongo.com/v1" . $endpoint);

    $headers = [
        "Authorization: Basic " . base64_encode(PAYMONGO_SECRET_KEY . ":"),
        "Content-Type: application/json",
        "Accept: application/json"
    ];

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    if ($data !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new Exception("PayMongo connection error: " . $error);
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode($response, true);

    return ['code' => $httpCode, 'body' => $decoded];
}

function paymongoErrorMessage($result, $fallback = 'PayMongo request failed.')
{
    return $result['body']['errors'][0]['detail'] ?? $fallback;
}


/*
=========================================================
SHARED: RECOMPUTE TOTALS FROM CART (server-side, never trust client totals)
=========================================================
*/

/*
| A brand-new company has no tax row yet, so this returns 0 rather than
| reading whatever rate another company happens to have configured.
*/
function getCompanyTaxRate($conn, $companyId)
{
    $stmt = mysqli_prepare($conn, 'SELECT tax_rate FROM tax WHERE company_id = ? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 'i', $companyId);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    return $row ? (float) $row['tax_rate'] : 0.0;
}

function computeCartTotals($conn, $cart)
{
    $taxRate = getCompanyTaxRate($conn, requireCompany());

    $subtotal = 0;

    foreach ($cart as $item) {
        $subtotal += $item['price'] * $item['qty'];
    }

    $taxAmount = round(($subtotal * $taxRate) / 100, 2);
    $grandTotal = round($subtotal + $taxAmount);

    return [
        'subtotal' => $subtotal,
        'tax' => $taxAmount,
        'total' => $grandTotal
    ];
}


/*
=========================================================
SHARED: FINALIZE A SALE (insert sale + items, deduct inventory)
=========================================================
|
| Used by both the Cash flow (complete_sale) and the GCash
| flow (check_gcash_status, once PayMongo confirms payment).
| Keeping this in one place means both flows validate stock
| and deduct inventory identically.
|
*/

function finalizeSale(
    $conn,
    $cart,
    $taxAmount,
    $grandTotal,
    $cash,
    $change,
    $paymentMethod,
    $paymentReference = null
) {

    mysqli_begin_transaction($conn);

    try {

        $companyId = requireCompany();

        $stmt = mysqli_prepare($conn, '
            INSERT INTO sales
            (
                company_id,
                created_by,
                total_amount,
                tax_amount,
                cash_received,
                change_amount,
                payment_method,
                payment_reference
            )
            VALUES (?,?,?,?,?,?,?,?)
        ');

        /* Who rang it up. Without this the cashier cannot be told what they
           themselves sold, because the row names only the company. */
        $recordedBy = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;

        mysqli_stmt_bind_param(
            $stmt,
            'iiddddss',
            $companyId,
            $recordedBy,
            $grandTotal,
            $taxAmount,
            $cash,
            $change,
            $paymentMethod,
            $paymentReference
        );

        mysqli_stmt_execute($stmt);
        $sale_id = mysqli_insert_id($conn);
        mysqli_stmt_close($stmt);

        foreach ($cart as $item) {

            $product_id = (int) $item['id'];
            $qty = (int) $item['qty'];
            $price = (float) $item['price'];

            // CHECK STOCK (row-locked, prepared statement)
            $stockStmt = mysqli_prepare($conn, '
                SELECT quantity
                FROM inventory
                WHERE product_id = ? AND company_id = ?
                FOR UPDATE
            ');
            mysqli_stmt_bind_param($stockStmt, 'ii', $product_id, $companyId);
            mysqli_stmt_execute($stockStmt);
            $stockResult = mysqli_stmt_get_result($stockStmt);
            $stock = mysqli_fetch_assoc($stockResult);
            mysqli_stmt_close($stockStmt);

            if (!$stock || $stock['quantity'] < $qty) {
                throw new Exception('Not enough stock.');
            }

            // INSERT ITEM
            $itemStmt = mysqli_prepare($conn, '
                INSERT INTO sale_items
                (company_id, sale_id, product_id, quantity, selling_price)
                VALUES (?,?,?,?,?)
            ');
            mysqli_stmt_bind_param(
                $itemStmt,
                'iiiid',
                $companyId,
                $sale_id,
                $product_id,
                $qty,
                $price
            );
            mysqli_stmt_execute($itemStmt);
            mysqli_stmt_close($itemStmt);

            // DEDUCT INVENTORY
            $deductStmt = mysqli_prepare($conn, '
                UPDATE inventory
                SET quantity = quantity - ?
                WHERE product_id = ? AND company_id = ?
            ');
            mysqli_stmt_bind_param($deductStmt, 'iii', $qty, $product_id, $companyId);
            mysqli_stmt_execute($deductStmt);
            mysqli_stmt_close($deductStmt);
        }

        mysqli_commit($conn);

        return [
            'status' => 'success',
            'sale_id' => $sale_id
        ];

    } catch (Exception $e) {

        mysqli_rollback($conn);

        return [
            'status' => 'error',
            'message' => $e->getMessage()
        ];
    }
}


/*
=========================================================
COMPLETE SALE — CASH ONLY
=========================================================
|
| GCash no longer completes through this endpoint. It must
| go through create_gcash_intent + check_gcash_status below,
| so a sale can never be recorded as "paid via GCash" without
| PayMongo actually confirming the payment.
|
*/

if (isset($_POST['complete_sale'])) {
    header('Content-Type: application/json');

    $cart = json_decode($_POST['cart'], true);
    $cash = floatval($_POST['cash']);

    if (empty($cart)) {
        echo json_encode(['status' => 'error', 'message' => 'Cart is empty.']);
        exit;
    }

    $totals = computeCartTotals($conn, $cart);
    $subtotal = $totals['subtotal'];
    $taxAmount = $totals['tax'];
    $grandTotal = $totals['total'];
    $change = $cash - $grandTotal;

    if ($cash < $grandTotal) {
        echo json_encode(['status' => 'error', 'message' => 'Insufficient cash.']);
        exit;
    }

    $result = finalizeSale(
        $conn,
        $cart,
        $taxAmount,
        $grandTotal,
        $cash,
        $change,
        'Cash',
        null
    );

    if ($result['status'] === 'success') {
        echo json_encode([
            'status' => 'success',
            'subtotal' => $subtotal,
            'tax' => $taxAmount,
            'total' => $grandTotal,
            'change' => $change
        ]);
    } else {
        echo json_encode($result);
    }

    exit;
}


/*
=========================================================
CREATE GCASH PAYMENT INTENT
=========================================================
|
| Called when the cashier chooses GCash. Creates a Payment
| Intent + gcash Payment Method, attaches them, and returns
| the checkout URL PayMongo gives back — which we render as
| a QR code for the customer to scan with their phone.
|
| The cart + computed totals are stashed in the session
| keyed by the Payment Intent id, so the eventual sale is
| finalized from server-trusted numbers, not whatever the
| browser sends back later.
|
*/

if (isset($_POST['create_gcash_intent'])) {
    header('Content-Type: application/json');

    $cart = json_decode($_POST['cart'], true);

    if (empty($cart)) {
        echo json_encode(['status' => 'error', 'message' => 'Cart is empty.']);
        exit;
    }

    $totals = computeCartTotals($conn, $cart);
    $subtotal = $totals['subtotal'];
    $taxAmount = $totals['tax'];
    $grandTotal = $totals['total'];

    if ($grandTotal < 1) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid amount.']);
        exit;
    }

    $amountCentavos = (int) round($grandTotal * 100);

    // PayMongo return_url — the customer lands here on THEIR phone
    // after authorizing in the GCash app. It doesn't drive the POS
    // screen; we confirm the sale by polling the intent status instead.
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $baseUrl = $protocol . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']);
    $returnUrl = $baseUrl . '/gcash_redirect.php';

    try {

        // 1. CREATE PAYMENT INTENT
        $intentResult = paymongoRequest('POST', '/payment_intents', [
            'data' => [
                'attributes' => [
                    'amount' => $amountCentavos,
                    'currency' => 'PHP',
                    'capture_type' => 'automatic',
                    'description' => 'SariSmart POS Sale',
                    'payment_method_allowed' => ['gcash']
                ]
            ]
        ]);

        if ($intentResult['code'] !== 200 || !isset($intentResult['body']['data']['id'])) {
            echo json_encode([
                'status' => 'error',
                'message' => paymongoErrorMessage($intentResult, 'Unable to create payment intent.')
            ]);
            exit;
        }

        $intentId = $intentResult['body']['data']['id'];
        $clientKey = $intentResult['body']['data']['attributes']['client_key'];

        // 2. CREATE GCASH PAYMENT METHOD
        $methodResult = paymongoRequest('POST', '/payment_methods', [
            'data' => [
                'attributes' => [
                    'type' => 'gcash'
                ]
            ]
        ]);

        if ($methodResult['code'] !== 200 || !isset($methodResult['body']['data']['id'])) {
            echo json_encode([
                'status' => 'error',
                'message' => paymongoErrorMessage($methodResult, 'Unable to create GCash payment method.')
            ]);
            exit;
        }

        $paymentMethodId = $methodResult['body']['data']['id'];

        // 3. ATTACH PAYMENT METHOD TO INTENT
        $attachResult = paymongoRequest('POST', "/payment_intents/{$intentId}/attach", [
            'data' => [
                'attributes' => [
                    'payment_method' => $paymentMethodId,
                    'client_key' => $clientKey,
                    'return_url' => $returnUrl
                ]
            ]
        ]);

        if ($attachResult['code'] !== 200) {
            echo json_encode([
                'status' => 'error',
                'message' => paymongoErrorMessage($attachResult, 'Unable to start GCash payment.')
            ]);
            exit;
        }

        $attributes = $attachResult['body']['data']['attributes'];
        $status = $attributes['status'];

        if ($status !== 'awaiting_next_action') {

            // Unexpected for a fresh e-wallet attach — surface whatever PayMongo says
            $message = $attributes['last_payment_error']['detail']
                ?? "Unexpected payment status: {$status}";

            echo json_encode(['status' => 'error', 'message' => $message]);
            exit;
        }

        $checkoutUrl = $attributes['next_action']['redirect']['url'] ?? null;

        if (!$checkoutUrl) {
            echo json_encode(['status' => 'error', 'message' => 'No checkout URL returned by PayMongo.']);
            exit;
        }

        // Stash the trusted cart + totals for when the intent succeeds
        $_SESSION['pending_gcash'][$intentId] = [
            'cart' => $cart,
            'subtotal' => $subtotal,
            'tax' => $taxAmount,
            'total' => $grandTotal,
            'created_at' => time()
        ];

        echo json_encode([
            'status' => 'success',
            'intent_id' => $intentId,
            'checkout_url' => $checkoutUrl,
            'total' => $grandTotal
        ]);
        exit;

    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}


/*
=========================================================
CHECK GCASH STATUS (polled by the frontend every few seconds)
=========================================================
*/

if (isset($_POST['check_gcash_status'])) {
    header('Content-Type: application/json');

    $intentId = trim($_POST['intent_id'] ?? '');

    if ($intentId === '' || !isset($_SESSION['pending_gcash'][$intentId])) {
        echo json_encode(['status' => 'error', 'message' => 'Unknown or already-processed payment.']);
        exit;
    }

    $pending = $_SESSION['pending_gcash'][$intentId];

    try {

        $result = paymongoRequest('GET', "/payment_intents/{$intentId}");

        if ($result['code'] !== 200) {
            echo json_encode([
                'status' => 'error',
                'message' => paymongoErrorMessage($result, 'Unable to check payment status.')
            ]);
            exit;
        }

        $status = $result['body']['data']['attributes']['status'];

        if ($status === 'succeeded') {

            $finalized = finalizeSale(
                $conn,
                $pending['cart'],
                $pending['tax'],
                $pending['total'],
                $pending['total'], // cash_received = total (no change for GCash)
                0,                 // change
                'GCash',
                $intentId
            );

            unset($_SESSION['pending_gcash'][$intentId]);

            if ($finalized['status'] === 'success') {
                echo json_encode([
                    'status' => 'success',
                    'subtotal' => $pending['subtotal'],
                    'tax' => $pending['tax'],
                    'total' => $pending['total'],
                    'change' => 0
                ]);
            } else {
                // Payment succeeded but stock ran out between intent creation and now.
                // The money has been captured — this needs manual reconciliation/refund.
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Payment received, but the sale could not be recorded: '
                        . $finalized['message']
                        . '. Please contact an administrator — this payment needs manual reconciliation.'
                ]);
            }

            exit;
        }

        if ($status === 'awaiting_payment_method') {

            $lastError = $result['body']['data']['attributes']['last_payment_error']['detail']
                ?? 'Payment was not completed.';

            unset($_SESSION['pending_gcash'][$intentId]);

            echo json_encode(['status' => 'failed', 'message' => $lastError]);
            exit;
        }

        // Still awaiting_next_action / processing
        echo json_encode(['status' => 'pending']);
        exit;

    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}


$companyId = requireCompany();

// LOAD CATEGORIES
$categoryStmt = mysqli_prepare(
    $conn,
    'SELECT *
    FROM categories
    WHERE company_id = ?
    ORDER BY category_name'
);
mysqli_stmt_bind_param($categoryStmt, 'i', $companyId);
mysqli_stmt_execute($categoryStmt);
$categoryResult = mysqli_stmt_get_result($categoryStmt);

// LOAD PRODUCTS
// Every joined table is filtered as well, so a product can never be paired
// with another company's stock row or category.
$productStmt = mysqli_prepare($conn, '
SELECT
p.product_id,
p.product_name,
p.description,
p.image,
c.category_name,
i.quantity,
i.selling_price
FROM products p
INNER JOIN inventory i
ON p.product_id = i.product_id AND i.company_id = p.company_id
INNER JOIN categories c
ON p.category_id = c.category_id AND c.company_id = p.company_id
WHERE i.quantity > 0 AND p.company_id = ?
ORDER BY p.product_name ASC
');
mysqli_stmt_bind_param($productStmt, 'i', $companyId);
mysqli_stmt_execute($productStmt);
$productResult = mysqli_stmt_get_result($productStmt);

// LOAD TAX
$taxRate = getCompanyTaxRate($conn, $companyId);

include('cashier_header.php');
?>

<link rel="stylesheet" href="pos.css">

<div class="container-fluid">
    <div class="row g-3">
        <div class="col-lg-8">
            <div class="pos-panel p-3">
                <div class="input-group mb-3">
                    <span class="input-group-text bg-white">
                        <i class="bi bi-search"></i>
                    </span>
                    <input type="text" id="searchProduct" class="form-control search-box"
                        placeholder="Search products or scan barcode...">
                </div>
                <div class="category-scroll mb-3">
                    <button type="button" class="category-btn active" data-category="All"> All </button>
                    <?php while ($cat = mysqli_fetch_assoc($categoryResult)) { ?>
                        <button type="button" class="category-btn" data-category="<?= $cat['category_name']; ?>">
                            <?= htmlspecialchars($cat['category_name']); ?>
                        </button>
                    <?php } ?>
                </div>
                <div class="product-list">
                    <div class="row g-3" id="productContainer">
                        <?php while ($row = mysqli_fetch_assoc($productResult)) { ?>
                            <div class="col-md-4 product-wrapper" data-category="<?= $row['category_name']; ?>">
                                <div class="card product-card p-2 product-item" data-id="<?= $row['product_id']; ?>"
                                    data-name="<?= htmlspecialchars($row['product_name']); ?>"
                                    data-price="<?= $row['selling_price']; ?>" data-stock="<?= $row['quantity']; ?>">
                                    <div class="product-image">
                                        <?php
                                        if (!empty($row['image']) && file_exists('../uploads/products/' . $row['image'])) {
                                            ?>
                                            <img src="../uploads/products/<?= $row['image']; ?>">
                                            <?php
                                        } else {
                                            ?>
                                            <i class="bi bi-box fs-1 text-secondary"></i>
                                        <?php } ?>
                                    </div>
                                    <div class="card-body">
                                        <h6> <?= htmlspecialchars($row['product_name']); ?> </h6>
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div class="price"> ₱<?= number_format($row['selling_price']); ?> </div>
                                            <span class="stock"> x<?= $row['quantity']; ?> </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php } ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="pos-card h-100 p-3">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="fw-bold">
                        <i class="bi bi-cart3 text-primary"></i>
                        Current Sale
                    </h5>
                    <span class="badge bg-light text-dark" id="itemCount">
                        0 Items
                    </span>
                </div>
                <hr>
                <div id="cartItems" style="height:380px;overflow-y:auto;">
                    <div class="text-center text-muted mt-5">
                        Tap a product to start.
                    </div>
                </div>
                <hr>
                <div class="d-flex justify-content-between mb-2">
                    <span>Subtotal</span>
                    <strong id="subtotal">₱0</strong>
                </div>
                <!-- <div class="d-flex justify-content-between mb-2">
                    <span> Tax (<?= $taxRate ?>%)</span>
                    <strong id="taxAmount">₱0.00</strong>
                </div> -->
                <hr>
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h4 class="fw-bold mb-0">Total</h4>
                    <h2 id="total" class="fw-bold text-primary mb-0"> ₱0 </h2>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-4">
                        <button id="cashBtn" class="btn btn-primary w-100">
                            <i class="bi bi-cash"></i>
                            Cash
                        </button>
                    </div>
                    <div class="col-4">
                        <button id="gcashBtn" type="button" class="btn btn-primary-success w-100">
                            <i class="bi bi-qr-code-scan"></i>
                            GCash
                        </button>
                    </div>
                </div>
                <!-- CASH PAYMENT -->
                <div id="cashSection">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <span>Cash</span>
                        <input type="number" id="cash" class="form-control text-end" style="width:120px" value="0"
                            min="0" step="1">
                    </div>
                    <div class="d-flex justify-content-between mb-3">
                        <span>Change</span>
                        <strong id="change">₱0</strong>
                    </div>
                    <button id="completeSale" class="btn btn-primary w-100">
                        <i class="bi bi-receipt"></i>
                        Complete Sale
                    </button>
                </div>
                <!-- GCASH PAYMENT -->
                <div id="gcashSection" style="display:none;">
                    <div class="alert alert-success text-center mb-3">
                        <i class="bi bi-phone fs-2"></i>
                        <br>
                        <strong>GCash Payment</strong>
                        <br>
                        Amount:
                        <h4 id="gcashAmountText">₱0</h4>
                    </div>
                    <button id="gcashSale" class="btn btn-primary w-100">
                        <i class="bi bi-qr-code-scan"></i>
                        Proceed to GCash
                    </button>
                </div>
                <input type="hidden" id="cartData">
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="gcashModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title">
                    <i class="bi bi-qr-code-scan"></i>
                    GCash Payment
                </h5>
            </div>
            <div class="modal-body text-center">

                <div id="gcashLoading">
                    <div class="spinner-border text-success mb-3" role="status"></div>
                    <p class="text-muted">Starting GCash payment...</p>
                </div>

                <div id="gcashQRWrapper" style="display:none;">
                    <div id="gcashQRCode" class="d-flex justify-content-center mb-3"></div>
                    <h3 id="gcashAmount" class="fw-bold text-success">₱0</h3>
                    <p class="text-muted">Ask the customer to scan this QR code with their phone camera, then
                        authorize the payment in GCash.</p>
                    <div class="alert alert-warning" id="gcashWaitingAlert">
                        <span class="spinner-border spinner-border-sm me-2"></span>
                        Waiting for payment...
                    </div>
                    <h1 id="gcashTimer" class="display-5 fw-bold text-danger">300</h1>
                    <small>seconds remaining</small>
                </div>

            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" id="gcashCancelBtn" data-bs-dismiss="modal">Cancel</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="receiptModal">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Receipt</h5>
            </div>
            <div class="modal-body" id="receiptBody"></div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button class="btn btn-primary" onclick="printReceipt()">Print</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>

<?php
include('posJS.php');
include('cashier_footer.php');
?>