<?php

require_once("../init.php");
requireRole(['finance']);

$companyId = requireCompany();
require_once(__DIR__ . '/includes/process_ap_payment.php');

/*
|--------------------------------------------------------------------------
| PAYMONGO RETURN HANDLER
|--------------------------------------------------------------------------
| PayMongo redirects the customer's browser here after they finish (or
| abandon) the GCash / Bank Transfer checkout, with
| ?checkout_session_id=cs_xxx in the URL (PayMongo substitutes the
| {CHECKOUT_SESSION_ID} placeholder we put in success_url).
|
| The browser redirect alone is NEVER trusted as proof of payment — it's
| just a signal to come check. This re-fetches the Checkout Session from
| PayMongo's API using the secret key and only records the payment if
| PayMongo itself confirms a payment on that session has status "paid".
| This is the documented fallback verification method for setups without
| a public webhook endpoint (e.g. local/XAMPP development).
|--------------------------------------------------------------------------
*/

$allowedReturns = ['expenses.php', 'accounts_payable.php'];

$token = trim($_GET['ref'] ?? '');

if ($token === '') {

    $_SESSION['alert'] = [
        "icon" => "error",
        "title" => "Invalid Payment Session",
        "text" => "No payment reference was provided."
    ];

    header("Location: accounts_payable.php");
    exit;
}

$lookup = $conn->prepare("SELECT session_id, company_id FROM paymongo_sessions WHERE token = ? AND company_id = ? LIMIT 1");
$lookup->bind_param("si", $token, $companyId);
$lookup->execute();
$pendingRow = $lookup->get_result()->fetch_assoc();
$lookup->close();

if (!$pendingRow || empty($pendingRow['session_id'])) {

    $_SESSION['alert'] = [
        "icon" => "error",
        "title" => "Invalid Payment Session",
        "text" => "This payment session could not be found. If you were charged, contact support with reference "
            . htmlspecialchars($token) . "."
    ];

    header("Location: accounts_payable.php");
    exit;
}

$checkoutSessionId = $pendingRow['session_id'];

if (!function_exists('curl_init')) {

    $_SESSION['alert'] = [
        "icon" => "error",
        "title" => "PayMongo Unavailable",
        "text" => "The PHP cURL extension isn't enabled, so the payment couldn't be confirmed. Enable it in php.ini."
    ];

    header("Location: accounts_payable.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| RETRIEVE THE CHECKOUT SESSION FROM PAYMONGO
|--------------------------------------------------------------------------
*/

$ch = curl_init("https://api.paymongo.com/v1/checkout_sessions/" . urlencode($checkoutSessionId));

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        "Authorization: Basic " . base64_encode(PAYMONGO_SECRET_KEY . ":"),
    ],
]);

$response = curl_exec($ch);
$curlError = curl_error($ch);
curl_close($ch);

$decoded = $response ? json_decode($response, true) : null;
$session = $decoded['data']['attributes'] ?? null;

if ($curlError || !$session) {

    $_SESSION['alert'] = [
        "icon" => "error",
        "title" => "Unable to Confirm Payment",
        "text" => "Could not verify the payment with PayMongo. If you were charged, contact support with reference "
            . htmlspecialchars($checkoutSessionId) . "."
    ];

    header("Location: accounts_payable.php");
    exit;
}

$metadata = $session['metadata'] ?? [];
$returnTo = $metadata['return_to'] ?? 'accounts_payable.php';

if (!in_array($returnTo, $allowedReturns, true)) {
    $returnTo = 'accounts_payable.php';
}


/*
|--------------------------------------------------------------------------
| ONLY PROCEED IF PAYMONGO CONFIRMS A "PAID" PAYMENT ON THIS SESSION
|--------------------------------------------------------------------------
*/

$payments = $session['payments'] ?? [];
$latestPayment = $payments[0] ?? null;
$paymentStatus = $latestPayment['attributes']['status'] ?? null;

if ($paymentStatus !== 'paid') {

    $_SESSION['alert'] = [
        "icon" => "warning",
        "title" => "Payment Not Completed",
        "text" => "The PayMongo payment was not completed (status: "
            . htmlspecialchars($paymentStatus ?? 'unknown') . "). No amount was recorded."
    ];

    header("Location: {$returnTo}");
    exit;
}

// housekeeping — mark this token used now that PayMongo has confirmed payment
$markConsumed = $conn->prepare("UPDATE paymongo_sessions SET consumed_at = NOW() WHERE token = ? AND company_id = ? AND consumed_at IS NULL");
$markConsumed->bind_param("si", $token, $companyId);
$markConsumed->execute();
$markConsumed->close();

$ap_id = (int) ($metadata['ap_id'] ?? 0);
$amount_paid = (float) ($metadata['amount_paid'] ?? 0);
$payment_method = $metadata['payment_method'] ?? 'GCash';
$reference_no = trim($metadata['reference_no'] ?? '');
$notes = trim($metadata['notes'] ?? '');
$paid_by = (int) ($metadata['paid_by'] ?? 0);
$paid_by = $paid_by > 0 ? $paid_by : null;

if ($ap_id <= 0 || $amount_paid <= 0) {

    $_SESSION['alert'] = [
        "icon" => "error",
        "title" => "Payment Confirmed, But Incomplete",
        "text" => "PayMongo confirmed payment " . htmlspecialchars($checkoutSessionId)
            . ", but the invoice details were missing from the session. Please contact support."
    ];

    header("Location: {$returnTo}");
    exit;
}


/*
|--------------------------------------------------------------------------
| IDEMPOTENCY GUARD — don't double-record if this session was already
| processed (e.g. the user refreshed this callback URL)
|--------------------------------------------------------------------------
*/

$sessionRef = "PayMongo (" . $checkoutSessionId . ")";

$dupCheck = $conn->prepare("SELECT payment_id FROM ap_payments WHERE ap_id = ? AND company_id = ? AND reference_no LIKE CONCAT(?, '%') LIMIT 1");
$dupCheck->bind_param("iis", $ap_id, $companyId, $sessionRef);
$dupCheck->execute();
$alreadyRecorded = $dupCheck->get_result()->num_rows > 0;
$dupCheck->close();

if ($alreadyRecorded) {

    $_SESSION['alert'] = [
        "icon" => "info",
        "title" => "Already Recorded",
        "text" => "This PayMongo payment was already recorded earlier."
    ];

    header("Location: {$returnTo}");
    exit;
}

$combinedReference = $reference_no !== '' ? $sessionRef . " — " . $reference_no : $sessionRef;


/*
|--------------------------------------------------------------------------
| RECORD THE PAYMENT (same path Cash/Check payments go through)
|--------------------------------------------------------------------------
*/

$result = processApPayment($conn, $ap_id, $amount_paid, $payment_method, $combinedReference, $notes, $paid_by, $companyId);

if ($result['success']) {

    $_SESSION['alert'] = [
        "icon" => "success",
        "title" => "Payment Recorded",
        "html" => "Your " . htmlspecialchars($payment_method) . " payment for <b>"
            . htmlspecialchars($result['invoice_no']) . "</b> via PayMongo was successful."
            . "<br><br><a href='../{$result['relative_path']}' target='_blank' class='btn btn-sm text-white' style='background:#00224c;'>"
            . "<i class='bi bi-file-earmark-pdf me-1'></i> Download Receipt</a>",
    ];

} else {

    $_SESSION['alert'] = [
        "icon" => "error",
        "title" => "Payment Confirmed, But Not Recorded",
        "text" => "PayMongo confirmed the payment, but it could not be saved: " . $result['message']
            . " Please contact support with reference " . htmlspecialchars($checkoutSessionId) . "."
    ];
}

header("Location: {$returnTo}");
exit;