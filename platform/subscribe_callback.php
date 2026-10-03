<?php

require_once __DIR__ . "/init.php";
require_once __DIR__ . "/includes/paymongo.php";

/*
|--------------------------------------------------------------------------
| SUBSCRIPTION PAYMENT RETURN HANDLER
|--------------------------------------------------------------------------
|
| PayMongo sends the customer's browser back here after they finish (or
| abandon) checkout, carrying the token we generated in subscribe.php.
|
| The redirect itself is never treated as proof of payment - anyone can
| type this URL. It is only a signal to go and ask PayMongo. The Checkout
| Session is re-fetched from the API with the secret key, and the
| subscription is activated only if PayMongo itself reports a payment with
| status "paid".
|
*/

$state = 'error';
$message = 'We could not confirm your payment.';
$planName = '';

$token = trim($_GET['ref'] ?? '');


try {

    if ($token === '') {
        throw new RuntimeException('This payment link is missing its reference.');
    }

    /*
    | Find the session by our own token. It also tells us which company
    | started the checkout, so nothing depends on who is browsing.
    */
    $lookup = $conn->prepare("
        SELECT token, session_id, company_id, consumed_at
        FROM paymongo_sessions
        WHERE token = ?
        LIMIT 1
    ");
    $lookup->bind_param("s", $token);
    $lookup->execute();
    $pending = $lookup->get_result()->fetch_assoc();
    $lookup->close();

    if (!$pending || empty($pending['session_id'])) {
        throw new RuntimeException('This payment reference is not recognised.');
    }

    $companyId = (int) $pending['company_id'];

    // Refreshing this page must not activate anything twice.
    if ($pending['consumed_at'] !== null) {
        $state = 'already';
        $message = 'This payment was already confirmed. You can sign in now.';
        throw new LogicException('already handled');
    }


    /*
    |--------------------------------------------------------------------------
    | ASK PAYMONGO WHAT ACTUALLY HAPPENED
    |--------------------------------------------------------------------------
    */

    $result = paymongoRequest('GET', '/checkout_sessions/' . $pending['session_id']);

    if ($result['code'] >= 400) {
        throw new RuntimeException('PayMongo could not be reached to verify this payment.');
    }

    $session = $result['body']['data']['attributes'] ?? [];
    $payments = $session['payments'] ?? [];
    $paymentStatus = $payments[0]['attributes']['status'] ?? null;

    if ($paymentStatus !== 'paid') {
        $state = 'unpaid';
        $message = 'The payment was not completed (status: ' . ($paymentStatus ?? 'unknown') . '). '
            . 'Nothing was charged, and your plan is still waiting.';
        throw new LogicException('not paid');
    }


    /*
    |--------------------------------------------------------------------------
    | ACTIVATE
    |--------------------------------------------------------------------------
    */

    $conn->begin_transaction();

    try {

        $subStmt = $conn->prepare("
            SELECT cs.subscription_id, cs.billing_cycle, sp.plan_name
            FROM company_subscriptions cs
            LEFT JOIN subscription_plans sp ON sp.plan_id = cs.plan_id
            WHERE cs.company_id = ?
            ORDER BY cs.subscription_id DESC
            LIMIT 1
        ");
        $subStmt->bind_param("i", $companyId);
        $subStmt->execute();
        $subscription = $subStmt->get_result()->fetch_assoc();
        $subStmt->close();

        if (!$subscription) {
            throw new RuntimeException('No subscription was found for this company.');
        }

        $planName = $subscription['plan_name'] ?? '';

        // The paid period starts now, not when the plan was first chosen.
        $expiry = $subscription['billing_cycle'] === 'Yearly'
            ? date('Y-m-d', strtotime('+1 year'))
            : date('Y-m-d', strtotime('+1 month'));

        $activate = $conn->prepare("
            UPDATE company_subscriptions
            SET status = 'Active',
                payment_status = 'Paid',
                start_date = CURDATE(),
                expiry_date = ?,
                renewal_date = ?,
                notes = CONCAT(IFNULL(notes, ''), ' | Paid online via PayMongo.')
            WHERE subscription_id = ? AND company_id = ?
        ");
        $activate->bind_param("ssii", $expiry, $expiry, $subscription['subscription_id'], $companyId);
        $activate->execute();
        $activate->close();

        // An Approved company becomes Active now that it has paid. Suspended
        // and Inactive are operator decisions and are left alone.
        $activateCompany = $conn->prepare("
            UPDATE company SET status = 'Active'
            WHERE company_id = ? AND status IN ('Approved', 'Active')
        ");
        $activateCompany->bind_param("i", $companyId);
        $activateCompany->execute();
        $activateCompany->close();

        $history = $conn->prepare("
            INSERT INTO subscription_history (subscription_id, action, remarks)
            VALUES (?, 'Activated', ?)
        ");
        $note = 'Payment confirmed by PayMongo (session ' . $pending['session_id'] . ').';
        $history->bind_param("is", $subscription['subscription_id'], $note);
        $history->execute();
        $history->close();

        // Burn the token so a refresh cannot run any of this again.
        $consume = $conn->prepare("
            UPDATE paymongo_sessions
            SET consumed_at = NOW()
            WHERE token = ? AND company_id = ? AND consumed_at IS NULL
        ");
        $consume->bind_param("si", $token, $companyId);
        $consume->execute();
        $consume->close();

        $conn->commit();

        // The signed-in owner's session was created before payment, so it
        // still says "no live subscription". Refresh that here rather than
        // making them sign out and back in.
        if (!empty($_SESSION['company_id']) && (int) $_SESSION['company_id'] === $companyId) {
            $_SESSION['subscription_active'] = true;
        }

        $state = 'paid';

    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }

} catch (LogicException $expected) {
    // $state and $message are already set for these.
} catch (Throwable $e) {
    $state = 'error';
    $message = $e->getMessage();
}


$screens = [
    'paid' => [
        'icon' => 'bi-check-circle-fill', 'color' => '#198754',
        'title' => 'Payment confirmed',
    ],
    'already' => [
        'icon' => 'bi-info-circle-fill', 'color' => '#0d6efd',
        'title' => 'Already confirmed',
    ],
    'unpaid' => [
        'icon' => 'bi-x-circle-fill', 'color' => '#fbbd23',
        'title' => 'Payment not completed',
    ],
    'error' => [
        'icon' => 'bi-exclamation-triangle-fill', 'color' => '#dc3545',
        'title' => 'Something went wrong',
    ],
];

$screen = $screens[$state];

include("header.php");

?>

<div style="background:#f5f7fb;padding:64px 0;">

    <div style="max-width:560px;margin:0 auto;background:#fff;border-radius:20px;
                box-shadow:0 12px 40px rgba(0,0,0,.08);padding:48px 44px;text-align:center;">

        <i class="bi <?= $screen['icon'] ?>" style="font-size:64px;color:<?= $screen['color'] ?>;"></i>

        <h2 style="font-size:24px;font-weight:700;color:#00224c;margin:18px 0 12px;">
            <?= htmlspecialchars($screen['title']) ?>
        </h2>

        <?php if ($state === 'paid'): ?>

            <p class="text-muted">
                <?php if ($planName !== ''): ?>
                    Your <strong><?= htmlspecialchars($planName) ?></strong> subscription is now active.
                <?php else: ?>
                    Your subscription is now active.
                <?php endif; ?>
                You can sign in and start using the system.
            </p>

            <a href="/SariSmarts/accounts/acc_log_in.php" class="btn text-white px-4 py-2 mt-2"
                style="background:#00224c;">
                Sign In
            </a>

        <?php elseif ($state === 'already'): ?>

            <p class="text-muted"><?= htmlspecialchars($message) ?></p>

            <a href="/SariSmarts/accounts/acc_log_in.php" class="btn text-white px-4 py-2 mt-2"
                style="background:#00224c;">
                Sign In
            </a>

        <?php else: ?>

            <p class="text-muted"><?= htmlspecialchars($message) ?></p>

            <div class="d-flex gap-2 justify-content-center mt-3 flex-wrap">
                <a href="subscribe.php" class="btn text-white px-4 py-2" style="background:#00224c;">
                    Back to Plans
                </a>
                <a href="contactUs.php" class="btn btn-light border px-4 py-2">Contact Support</a>
            </div>

        <?php endif; ?>

    </div>

</div>

<?php include("footer.php"); ?>
