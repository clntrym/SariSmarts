<?php

require_once __DIR__ . "/init.php";
require_once __DIR__ . "/includes/paymongo.php";
require_once __DIR__ . "/includes/company_contracts.php";

/*
|--------------------------------------------------------------------------
| AVAIL A SUBSCRIPTION
|--------------------------------------------------------------------------
|
| Where an approved business chooses its plan. This sits between approval
| and access:
|
|   Approved  -> the owner picks a plan here
|   Pending   -> recorded, waiting for payment to be settled
|   Active    -> activated by the Super Admin, sign-in opens
|
| An approved owner cannot sign in yet - the login gate stops them - so
| this page carries its own email + password check, the same way
| resubmit.php does. It never unlocks the app, only this form.
|
*/

$errors = [];
$company = null;
$existingSubscription = null;
$done = false;

$plans = [];
$planResult = $conn->query("
    SELECT plan_id, plan_name, tagline, description, monthly_price, yearly_price,
           price_label, badge, max_branches, max_users, button_text
    FROM subscription_plans
    WHERE status = 'Active'
    ORDER BY plan_order, plan_id
");
while ($row = $planResult->fetch_assoc()) {
    $plans[$row['plan_id']] = $row;
}


function loadApprovedCompany($conn, $email, $password)
{
    $stmt = $conn->prepare("
        SELECT u.user_id, u.password, u.company_id, u.fullname, c.*
        FROM users u
        INNER JOIN company c ON c.company_id = u.company_id
        WHERE u.email = ?
        LIMIT 1
    ");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row || !password_verify($password, $row['password'])) {
        return null;
    }

    return $row;
}


/*
| An owner arriving straight from login is already authenticated, so the
| credential step is skipped entirely and the plan picker opens for them.
| The email + password form below is only for someone who followed the link
| from an email without a session.
*/
$signedInOwner = false;

if (!empty($_SESSION['user_id']) && !empty($_SESSION['company_id'])) {

    $stmt = $conn->prepare("
        SELECT u.user_id, u.company_id, u.fullname, c.*
        FROM users u
        INNER JOIN company c ON c.company_id = u.company_id
        WHERE u.user_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $sessionCompany = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($sessionCompany && in_array($sessionCompany['status'], ['Approved', 'Active'], true)) {
        $company = $sessionCompany;
        $signedInOwner = true;
    }
}


/*
| An owner who signed in with their email and password below.
|
| That sign-in used to hold for the one POST it arrived on and nothing
| else. Every link on this page is a GET carrying no password, so
| "Download the agreement" was an anonymous request: the page did not know
| who was asking, skipped the download handler, and the browser saved the
| HTML as subscribe.php. The upload and the Pay button had the same hole.
|
| So the sign-in is remembered -- under its own key, not user_id and
| company_id. Those two are what the tenant app reads, and this is not a
| sign-in to the tenant app: the account is still inactive and must stay
| shut out of it. This key says one thing only, that this business may use
| this page.
*/
if (!$signedInOwner && !empty($_SESSION['subscribe_company_id'])) {

    $stmt = $conn->prepare("
        SELECT u.user_id, u.company_id, u.fullname, c.*
        FROM users u
        INNER JOIN company c ON c.company_id = u.company_id
        WHERE u.company_id = ? AND u.role = 'admin'
        LIMIT 1
    ");
    $stmt->bind_param("i", $_SESSION['subscribe_company_id']);
    $stmt->execute();
    $remembered = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    /* Checked again on every request: an application that stopped being
       approved stops being able to subscribe, session or no session. */
    if ($remembered && in_array($remembered['status'], ['Approved', 'Active'], true)) {
        $company = $remembered;
    } else {
        unset($_SESSION['subscribe_company_id']);
    }
}


/*
| An owner who followed the approval email carries a token instead. Their
| account cannot sign in yet -- the login gate holds until the subscription is
| paid for -- so asking them for a password here was asking for something they
| could not use. The token is proof enough for this page, and for this page
| only: it never opens the app.
*/
$tokenOwner = false;
$approvalRef = trim((string) ($_GET['ref'] ?? $_POST['ref'] ?? ''));

if (!$signedInOwner && $approvalRef !== '') {

    $stmt = $conn->prepare("
        SELECT c.*
        FROM company c
        WHERE c.approval_token = ?
          AND c.approval_token_expires_at IS NOT NULL
          AND c.approval_token_expires_at > NOW()
          AND c.status IN ('Approved', 'Active')
        LIMIT 1
    ");
    $stmt->bind_param("s", $approvalRef);
    $stmt->execute();
    $tokenCompany = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($tokenCompany) {
        $company = $tokenCompany;
        $tokenOwner = true;
    } else {
        $errors['auth'] = 'That link has expired. Sign in below to continue, or ask us to send a new one.';
    }
}

// A password sign-in, which is also where the remembered session is set.
if (!$company && !$signedInOwner && !$tokenOwner && $_SERVER['REQUEST_METHOD'] === 'POST') {

    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    $company = loadApprovedCompany($conn, $email, $password);

    if (!$company) {

        $errors['auth'] = 'Email or password is incorrect.';

    } elseif ($company['status'] === 'Pending') {

        $errors['auth'] = 'Your application is still under review. We will email you once it is decided.';
        $company = null;

    } elseif ($company['status'] === 'Rejected') {

        $errors['auth'] = 'Your application needs changes before you can subscribe. Please update it first.';
        $company = null;

    } elseif (!in_array($company['status'], ['Approved', 'Active'], true)) {

        $errors['auth'] = 'This account cannot subscribe right now. Please contact support.';
        $company = null;

    } else {

        /*
        | Remembered, so the links on the page that follows work. Without
        | this the sign-in held for this one POST and every GET after it --
        | the agreement download among them -- arrived anonymous.
        */
        $_SESSION['subscribe_company_id'] = (int) $company['company_id'];

        $loadSubscriptionFor = $company['company_id'];
    }
}

if ($company && !isset($loadSubscriptionFor)) {
    $loadSubscriptionFor = $company['company_id'];
}

if ($company && isset($loadSubscriptionFor)) {

        $stmt = $conn->prepare("
            SELECT cs.*, sp.plan_name
            FROM company_subscriptions cs
            LEFT JOIN subscription_plans sp ON sp.plan_id = cs.plan_id
            WHERE cs.company_id = ?
            ORDER BY cs.subscription_id DESC
            LIMIT 1
        ");
        $stmt->bind_param("i", $loadSubscriptionFor);
        $stmt->execute();
        $existingSubscription = $stmt->get_result()->fetch_assoc();
        $stmt->close();
}


/*
| What they picked during registration. register.php records it as a Pending
| subscription, so by the time approval lands there is already an answer to
| "which plan?" -- the page confirms it rather than asking again.
|
| "?change=1" is how the Change plan link opts back into the full picker.
*/
$chosenPlan = null;
$showConfirmation = false;

if ($company
    && $existingSubscription
    && $existingSubscription['status'] === 'Pending'
    && isset($plans[$existingSubscription['plan_id']])
    && empty($_GET['change'])
    && $_SERVER['REQUEST_METHOD'] !== 'POST') {

    $chosenPlan = $plans[$existingSubscription['plan_id']];
    $showConfirmation = true;
}


/*
|--------------------------------------------------------------------------
| THE SERVICE AGREEMENT
|--------------------------------------------------------------------------
|
| The business downloads what it was issued, signs it in its own time, and
| uploads the signed copy back. Both of those act on $company, which is
| whichever business just proved who it is -- by session, by the link in
| the approval email, or by email and password above.
|
| So a business can only ever reach its own agreement. No contract id is
| ever read from the request; the row is found from the company.
|
| Both sit above the layout include: a download needs unsent headers, and
| an upload redirects.
*/

$companyContract = $company ? contractForCompany($conn, (int) $company['company_id']) : null;

/* An agreement issued before any template existed is given one now. */
$companyContract = fillMissingTemplate($conn, $companyContract);


if ($company && isset($_GET['contract'])) {

    if (!$companyContract) {
        http_response_code(404);
        exit('There is no agreement on record for your business yet.');
    }

    $which = $_GET['contract'] === 'signed' ? 'signed_contract' : 'issued_template';

    /*
    | The business is here to download, sign and send back, so the file is
    | saved rather than opened in the viewer. "?view=1" asks for a preview
    | instead, which is what the "View what you sent" link uses.
    */
    $disposition = empty($_GET['view']) ? 'attachment' : 'inline';

    sendContractFile(
        $companyContract[$which] ?? null,
        $companyContract['contract_number'] . '_'
            . ($which === 'signed' ? 'signed' : 'agreement') . '.pdf',
        $disposition
    );
}


if ($company && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['uploadSignedContract'])) {

    if (!$companyContract) {

        $errors['contract'] = 'There is no agreement on record for your business yet.';

    } else {

        [$path, $problem] = storeCompanyContractFile(
            $_FILES['signed_contract'] ?? [],
            $companyContract['contract_number'],
            'signed'
        );

        if ($problem !== null) {

            $errors['contract'] = $problem;

        } else {

            /*
            | A replacement goes back to Pending. The Super Admin accepted
            | the document they were shown, not whatever replaces it.
            */
            $stmt = $conn->prepare("
                UPDATE company_contracts
                SET signed_contract = ?, signed_at = NOW(), status = 'Signed',
                    review = 'Pending', review_remarks = NULL,
                    reviewed_by = NULL, reviewed_at = NULL
                WHERE contract_id = ? AND company_id = ?
            ");
            $stmt->bind_param("sii", $path, $companyContract['contract_id'], $company['company_id']);
            $stmt->execute();
            $saved = $stmt->affected_rows;
            $stmt->close();

            if ($saved === 0) {
                $errors['contract'] = 'Your agreement could not be updated. Please try again.';
            } else {
                $_SESSION['contract_uploaded'] = $companyContract['contract_number'];
                header('Location: subscribe.php');
                exit;
            }
        }

        /* The row is read again further down, after every handler has run,
           so the page shows what is true now. Nothing to re-read here. */
    }
}

/*
|--------------------------------------------------------------------------
| RECORD THE CHOSEN PLAN
|--------------------------------------------------------------------------
*/

if ($company && isset($_POST['choosePlan'])) {

    $planId = (int) ($_POST['plan_id'] ?? 0);
    $billingCycle = ($_POST['billing_cycle'] ?? 'Monthly') === 'Yearly' ? 'Yearly' : 'Monthly';

    if (!isset($plans[$planId])) {

        $errors['plan_id'] = 'Please choose a plan.';

    } elseif ($existingSubscription
        && in_array($existingSubscription['status'], ['Active', 'Trial'], true)) {

        $errors['plan_id'] = 'You already have an active subscription.';

    } else {

        $plan = $plans[$planId];

        $amount = $billingCycle === 'Yearly'
            ? (float) $plan['yearly_price']
            : (float) $plan['monthly_price'];

        $startDate = date('Y-m-d');
        $expiryDate = $billingCycle === 'Yearly'
            ? date('Y-m-d', strtotime('+1 year'))
            : date('Y-m-d', strtotime('+1 month'));

        $conn->begin_transaction();

        try {

            $note = 'Plan chosen by the owner. Awaiting payment.';

            if ($existingSubscription) {

                // Replace the earlier Pending choice rather than stacking rows.
                $stmt = $conn->prepare("
                    UPDATE company_subscriptions
                    SET plan_id = ?, billing_cycle = ?, amount = ?, start_date = ?,
                        expiry_date = ?, payment_status = 'Pending', status = 'Pending', notes = ?
                    WHERE subscription_id = ?
                ");
                $stmt->bind_param(
                    "isdsssi",
                    $planId, $billingCycle, $amount, $startDate, $expiryDate, $note,
                    $existingSubscription['subscription_id']
                );
                $stmt->execute();
                $subscriptionId = (int) $existingSubscription['subscription_id'];
                $stmt->close();

            } else {

                $stmt = $conn->prepare("
                    INSERT INTO company_subscriptions
                        (company_id, plan_id, billing_cycle, amount, start_date, expiry_date,
                         payment_status, status, notes)
                    VALUES (?, ?, ?, ?, ?, ?, 'Pending', 'Pending', ?)
                ");
                $stmt->bind_param(
                    "iisdsss",
                    $company['company_id'], $planId, $billingCycle, $amount,
                    $startDate, $expiryDate, $note
                );
                $stmt->execute();
                $subscriptionId = $stmt->insert_id;
                $stmt->close();
            }

            $stmt = $conn->prepare("
                INSERT INTO subscription_history (subscription_id, action, remarks)
                VALUES (?, 'Plan Selected', ?)
            ");
            $historyNote = $plan['plan_name'] . ' (' . $billingCycle . ') chosen by '
                . $company['fullname'] . '. Awaiting payment.';
            $stmt->bind_param("is", $subscriptionId, $historyNote);
            $stmt->execute();
            $stmt->close();

            /*
            | Availing a plan is agreeing to terms, so the agreement is
            | issued here and nowhere else. It goes out with the choice
            | rather than after payment: the business signs what it is
            | buying, not what it has already bought.
            |
            | Inside the transaction. A subscription recorded without its
            | agreement would leave somebody with nothing to sign and no
            | sign of why.
            */
            issueCompanyContract($conn, (int) $company['company_id'], (int) $subscriptionId);

            $conn->commit();

            /*
            |--------------------------------------------------------------
            | HAND OFF TO PAYMONGO
            |--------------------------------------------------------------
            |
            | Enterprise is quoted, not priced, so it stays on the manual
            | path - there is no amount to charge yet. Everything else goes
            | straight to a PayMongo Checkout Session, and the subscription
            | is only activated once PayMongo confirms the payment in
            | subscribe_callback.php.
            |
            */

            /*
            | Choosing a plan no longer hands straight off to PayMongo.
            |
            | The agreement comes first: the business signs what it is
            | buying, and our team accepts the signed copy, before any money
            | is asked for. subscriptionReadiness() is what decides that, and
            | the Pay button further down is the only way to payment now.
            |
            | Enterprise is quoted rather than priced, so it has no amount to
            | charge either way and stays on the manual path.
            */
            $done = [
                'plan' => $plan,
                'cycle' => $billingCycle,
                'amount' => $amount,
                'mode' => $amount <= 0 ? 'quote' : 'agreement',
            ];


        } catch (Throwable $e) {

            $conn->rollback();
            $errors['general'] = 'Could not save your choice. Please try again.';
        }
    }
}


/*
|--------------------------------------------------------------------------
| THE STATE OF PLAY
|--------------------------------------------------------------------------
|
| Read once, after every handler above has had its turn, so the page and
| the payment gate are both looking at what is true now rather than what
| was true when the request arrived.
*/
$companyContract = $company ? contractForCompany($conn, (int) $company['company_id']) : null;

/* An agreement issued before any template existed is given one now. */
$companyContract = fillMissingTemplate($conn, $companyContract);

$readiness = subscriptionReadiness($conn, $company, $existingSubscription, $companyContract);


/*
|--------------------------------------------------------------------------
| PAY
|--------------------------------------------------------------------------
|
| The only way to PayMongo. It used to be the tail of "confirm your plan",
| which meant the business reached the payment page before it had seen the
| agreement, let alone signed it.
|
| The gate is checked here and not only on the button that leads here. A
| button can be hidden; a POST can still be sent.
*/
if ($company && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['payNow'])) {

    if (!$readiness['ready']) {

        /* Name the first thing outstanding rather than refusing blankly. */
        $blocker = 'Something is still outstanding.';

        foreach ($readiness['steps'] as $step) {
            if (!$step['done']) {
                $blocker = $step['detail'] !== '' ? $step['detail'] : $step['label'] . ' is not done yet.';
                break;
            }
        }

        $errors['general'] = $blocker;

    } elseif ((float) ($existingSubscription['amount'] ?? 0) <= 0) {

        /* Enterprise is quoted, so there is no amount to charge yet. */
        $errors['general'] = 'This plan is quoted rather than priced. Our team will contact you.';

    } else {

        $plan = $plans[$existingSubscription['plan_id']] ?? null;
        $amount = (float) $existingSubscription['amount'];
        $billingCycle = $existingSubscription['billing_cycle'];

        try {

            $token = bin2hex(random_bytes(20));

            $storeToken = $conn->prepare("
                INSERT INTO paymongo_sessions (company_id, token, created_at)
                VALUES (?, ?, NOW())
            ");
            $storeToken->bind_param("is", $company['company_id'], $token);
            $storeToken->execute();
            $storeToken->close();

            $baseUrl = platformBaseUrl();

            $checkout = paymongoRequest('POST', '/checkout_sessions', [
                'data' => [
                    'attributes' => [
                        'send_email_receipt' => true,
                        'show_line_items' => true,
                        'line_items' => [[
                            'name' => ($plan['plan_name'] ?? 'Subscription') . ' (' . $billingCycle . ')',
                            'amount' => (int) round($amount * 100),
                            'currency' => 'PHP',
                            'quantity' => 1,
                        ]],
                        'payment_method_types' => ['gcash', 'card', 'paymaya'],
                        'description' => 'SariSmart subscription for ' . $company['company_name'],
                        'success_url' => $baseUrl . '/subscribe_callback.php?ref=' . $token,
                        'cancel_url' => $baseUrl . '/subscribe.php',
                        'reference_number' => $token,
                    ],
                ],
            ]);

            $attributes = $checkout['body']['data']['attributes'] ?? [];
            $checkoutUrl = $attributes['checkout_url'] ?? null;
            $sessionId = $checkout['body']['data']['id'] ?? null;

            if ($checkout['code'] >= 400 || !$checkoutUrl) {
                $apiMessage = $checkout['body']['errors'][0]['detail']
                    ?? 'PayMongo did not return a checkout URL.';
                throw new RuntimeException($apiMessage);
            }

            /* What PayMongo gave back, so the callback can find this session
               again by our own token. */
            $fillIn = $conn->prepare("
                UPDATE paymongo_sessions
                SET session_id = ?, checkout_url = ?
                WHERE token = ? AND company_id = ?
            ");
            $fillIn->bind_param("sssi", $sessionId, $checkoutUrl, $token, $company['company_id']);
            $fillIn->execute();
            $fillIn->close();

            header('Location: ' . $checkoutUrl);
            exit;

        } catch (Throwable $paymentError) {

            /* Nothing is lost: the agreement and the plan are already saved,
               so they can simply try again. */
            $errors['general'] = 'We could not open the payment page: '
                . $paymentError->getMessage();
        }
    }
}


include("header.php");

?>

<style>
    .sub-wrap { background:#f5f7fb; padding:48px 0; }
    .sub-card { max-width:960px; margin:0 auto; background:#fff; border-radius:20px;
                box-shadow:0 12px 40px rgba(0,0,0,.08); overflow:hidden; }
    .sub-head { background:#00224c; color:#fff; padding:32px 40px; }
    .sub-head h1 { font-size:24px; font-weight:700; margin:0; }
    .sub-head p { color:#cbd5e1; margin:8px 0 0; }
    .sub-body { padding:32px 40px 40px; }
    .confirm-card { border:1px solid #dfe5ec; border-radius:16px; padding:26px; background:#fff;
                    box-shadow:0 3px 14px rgba(0,34,76,.07); max-width:640px; }
    .confirm-label { font-size:12px; text-transform:uppercase; letter-spacing:.04em;
                     color:#718096; font-weight:600; margin-bottom:4px; }
    .confirm-plan { font-size:1.6rem; font-weight:700; color:#00224c; line-height:1.2; }
    .confirm-price { font-size:1.6rem; font-weight:700; color:#00224c; line-height:1.2; }
    .change-plan { color:#00224c; font-weight:600; font-size:14px; }

    .plan-option { border:2px solid #e2e8f0; border-radius:16px; padding:22px; cursor:pointer;
                   height:100%; transition:.2s; }
    .plan-option:hover { border-color:#94a3b8; }
    .plan-option input { display:none; }
    .plan-option.selected { border-color:#00224c; background:#f8fafc; }
    .plan-price { font-size:24px; font-weight:700; color:#00224c; }
    .cycle-toggle .btn-check:checked + .btn { background:#00224c; color:#fff; border-color:#00224c; }
</style>


<div class="sub-wrap">

    <div class="sub-card">

        <div class="sub-head">
            <h1><?= $showConfirmation ? 'Confirm your subscription' : 'Choose your subscription' ?></h1>
            <p>
                <?php if ($showConfirmation): ?>
                    <?= htmlspecialchars($company['company_name'] ?? 'Your business') ?> is approved.
                    Confirm the plan you chose and settle the payment to open the system.
                <?php elseif ($signedInOwner): ?>
                    Welcome, <?= htmlspecialchars($company['fullname'] ?? '') ?>.
                    <?= htmlspecialchars($company['company_name'] ?? 'Your business') ?> is approved -
                    activate a plan to open the system.
                <?php else: ?>
                    Your business is approved. Pick the plan that fits your operation.
                <?php endif; ?>
            </p>
        </div>

        <div class="sub-body">

            <?php
            /*
            |------------------------------------------------------------------
            | THE SERVICE AGREEMENT
            |------------------------------------------------------------------
            |
            | Shown above everything else once an agreement has been issued,
            | because it is the one thing the business still has to do. It
            | stays on screen through every later visit until it is signed and
            | accepted, which is what stops it being a notice somebody saw
            | once and closed.
            |
            | Three states, three different things to say:
            |
            |   Issued    here it is, sign it and send it back
            |   Signed    we have it, we are reading it
            |   Rejected  here is what was wrong, send another
            */
            ?>
            <?php if ($company && $companyContract): ?>

                <?php
                $agreementDone = $companyContract['status'] === 'Signed'
                    && $companyContract['review'] === 'Approved';
                ?>

                <div class="border rounded-4 p-4 mb-4"
                    style="background:<?= $agreementDone ? '#f0fdf4' : '#f8fafc' ?>;">

                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                        <h5 class="fw-bold mb-0" style="color:#00224c;">
                            <i class="bi bi-file-earmark-text me-2"></i>Service Agreement
                        </h5>
                        <span class="badge bg-<?= $agreementDone ? 'success'
                            : ($companyContract['review'] === 'Rejected' ? 'danger'
                                : ($companyContract['status'] === 'Signed' ? 'info' : 'secondary')) ?>">
                            <?= $agreementDone ? 'Accepted'
                                : ($companyContract['review'] === 'Rejected' ? 'Needs a new copy'
                                    : ($companyContract['status'] === 'Signed' ? 'Under review' : 'Waiting for your signature')) ?>
                        </span>
                    </div>

                    <div class="text-muted small mb-3">
                        <?= htmlspecialchars($companyContract['contract_number']) ?>
                    </div>

                    <?php
                    /*
                    | The whole path, not only the next obstacle.
                    |
                    | Somebody waiting wants to know where they are and what
                    | is left, and a page that shows one step at a time makes
                    | that impossible to see.
                    */
                    ?>
                    <ol class="list-unstyled mb-3">
                        <?php foreach ($readiness['steps'] as $step): ?>
                            <li class="d-flex align-items-start gap-2 mb-1">
                                <i class="bi bi-<?= $step['done'] ? 'check-circle-fill text-success' : 'circle text-muted' ?>"
                                    style="margin-top:3px;"></i>
                                <span>
                                    <span class="<?= $step['done'] ? 'text-muted' : 'fw-semibold' ?>"
                                        style="<?= $step['done'] ? '' : 'color:#00224c;' ?>">
                                        <?= htmlspecialchars($step['label']) ?>
                                    </span>
                                    <?php if (!$step['done'] && $step['detail'] !== ''): ?>
                                        <div class="text-muted small"><?= htmlspecialchars($step['detail']) ?></div>
                                    <?php endif; ?>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ol>

                    <?php if (!empty($_SESSION['contract_uploaded'])): ?>
                        <div class="alert alert-success">
                            Your signed agreement has been sent to our team.
                        </div>
                        <?php unset($_SESSION['contract_uploaded']); ?>
                    <?php endif; ?>

                    <?php if (!empty($errors['contract'])): ?>
                        <div class="alert alert-danger"><?= htmlspecialchars($errors['contract']) ?></div>
                    <?php endif; ?>

                    <?php if ($companyContract['review'] === 'Rejected'): ?>
                        <div class="alert alert-warning">
                            <strong>Our team could not accept the copy you sent.</strong>
                            <?php if (trim((string) $companyContract['review_remarks']) !== ''): ?>
                                <div class="mt-1"><?= nl2br(htmlspecialchars($companyContract['review_remarks'])) ?></div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>


                    <?php if ($agreementDone): ?>

                        <p class="text-muted mb-3">
                            Your signed agreement has been accepted.
                        </p>

                        <a href="subscribe.php?contract=signed&amp;view=1" target="_blank" rel="noopener"
                            class="btn btn-sm mb-3" style="background:#e2e8f0;color:#00224c;">
                            <i class="bi bi-file-earmark-pdf me-1"></i> View your signed copy
                        </a>

                        <?php
                        /*
                        | Payment, and only here.
                        |
                        | The button appears when subscriptionReadiness() says
                        | every step is done. The handler checks the same thing
                        | again, because hiding a button does not stop a POST.
                        */
                        $amountDue = (float) ($existingSubscription['amount'] ?? 0);
                        ?>

                        <?php if ($readiness['ready'] && $amountDue > 0): ?>

                            <hr class="my-3">

                            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                                <div>
                                    <div class="fw-bold" style="color:#00224c;">Ready to pay</div>
                                    <div class="text-muted small">
                                        &#8369;<?= number_format($amountDue, 2) ?>
                                        &mdash; <?= htmlspecialchars($existingSubscription['billing_cycle']) ?>
                                    </div>
                                </div>

                                <form method="POST" class="mb-0"
                                    data-guard
                                    data-confirm-title="Pay now?"
                                    data-confirm-text="You will be taken to PayMongo to pay by GCash, Maya or card."
                                    data-confirm-ok="Yes, pay now">
                                    <button type="submit" name="payNow" class="btn text-white"
                                        style="background:#00224c;">
                                        <i class="bi bi-credit-card me-1"></i> Pay subscription fee
                                    </button>
                                </form>
                            </div>

                        <?php elseif ($amountDue <= 0): ?>

                            <div class="alert alert-secondary mb-0">
                                This plan is quoted rather than priced. Our team will contact you
                                to agree on the fee and settle payment.
                            </div>

                        <?php endif; ?>

                    <?php else: ?>

                        <p class="text-muted mb-3">
                            Download the agreement, sign it, and send the signed copy back here.
                            Our team reads it before your subscription is switched on.
                        </p>

                        <?php if (trim((string) $companyContract['issued_template']) !== ''): ?>
                            <a href="subscribe.php?contract=blank" target="_blank" rel="noopener"
                                class="btn btn-sm mb-3" style="background:#e2e8f0;color:#00224c;">
                                <i class="bi bi-download me-1"></i> Download the agreement
                            </a>
                        <?php else: ?>
                            <div class="alert alert-secondary">
                                Your agreement is being prepared. Our team will send it shortly.
                            </div>
                        <?php endif; ?>

                        <?php if ($companyContract['status'] === 'Signed'
                            && $companyContract['review'] === 'Pending'): ?>

                            <div class="alert alert-info mb-3">
                                We have your signed copy and are reading it now.
                                <a href="subscribe.php?contract=signed&amp;view=1" target="_blank" rel="noopener">
                                    View what you sent
                                </a>
                            </div>

                        <?php endif; ?>

                        <form method="POST" enctype="multipart/form-data" id="signedAgreementForm"
                            data-guard
                            data-confirm-title="Send your signed agreement?"
                            data-confirm-text="Our team reads it before your subscription is switched on."
                            data-confirm-ok="Yes, send it">

                            <label class="form-label">
                                <?= $companyContract['status'] === 'Signed'
                                    ? 'Send a different copy' : 'Signed copy' ?>
                                <span class="text-danger">*</span>
                            </label>

                            <input type="file" name="signed_contract" accept="application/pdf" required
                                class="form-control">
                            <div class="form-text">PDF, up to 5 MB.</div>

                            <button type="submit" name="uploadSignedContract"
                                class="btn text-white mt-3" style="background:#00224c;">
                                <i class="bi bi-upload me-1"></i> Send signed agreement
                            </button>

                        </form>

                    <?php endif; ?>

                </div>

            <?php endif; ?>


            <?php if ($done): ?>

                <div class="text-center py-4">

                    <?php if (($done['mode'] ?? '') === 'payment_failed'): ?>

                        <div style="font-size:56px;color:#dc3545;"><i class="bi bi-exclamation-triangle"></i></div>

                        <h3 class="fw-bold mt-3" style="color:#00224c;">Payment could not be started</h3>

                        <p class="text-muted mb-4">
                            Your choice of <strong><?= htmlspecialchars($done['plan']['plan_name']) ?></strong>
                            is saved, but we could not open the payment page.
                        </p>

                        <div class="alert alert-danger text-start mx-auto" style="max-width:460px;">
                            <?= htmlspecialchars($done['message'] ?? '') ?>
                        </div>

                        <a href="subscribe.php" class="btn text-white" style="background:#00224c;">Try again</a>

                    <?php else: ?>

                        <div style="font-size:56px;color:#198754;"><i class="bi bi-check-circle"></i></div>

                        <h3 class="fw-bold mt-3" style="color:#00224c;">
                            <?= htmlspecialchars($done['plan']['plan_name']) ?> selected
                        </h3>

                        <p class="text-muted mb-4">
                            This plan is quoted rather than priced, so our team will contact you
                            to agree on pricing and settle payment.
                        </p>

                        <div class="alert alert-secondary text-start mx-auto" style="max-width:460px;">
                            <strong>What happens next</strong>
                            <ol class="mb-0 mt-2 ps-3">
                                <li>We agree on your pricing.</li>
                                <li>Your subscription is activated.</li>
                                <li>You can sign in and start using the system.</li>
                            </ol>
                        </div>

                    <?php endif; ?>

                    <?php if ($signedInOwner): ?>
                        <a href="accounts/acc_log_out.php" class="btn text-white mt-3" style="background:#00224c;">
                            Sign out
                        </a>
                    <?php else: ?>
                        <a href="index.php" class="btn text-white mt-3" style="background:#00224c;">
                            Back to Home
                        </a>
                    <?php endif; ?>

                </div>

            <?php elseif (!$company): ?>

                <?php if (!empty($errors['auth'])): ?>
                    <div class="alert alert-warning"><?= htmlspecialchars($errors['auth']) ?></div>
                <?php endif; ?>

                <p class="text-muted">
                    Sign in with the email and password you registered with.
                </p>

                <form method="POST" class="mt-3" style="max-width:420px;">

                    <input type="hidden" name="ref" value="<?= htmlspecialchars($approvalRef) ?>">

                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control"
                            value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Password</label>
                        <input type="password" name="password" class="form-control" required>
                    </div>

                    <button type="submit" class="btn text-white w-100 py-2" style="background:#00224c;">
                        Continue
                    </button>

                </form>

            <?php else: ?>

                <?php if (!empty($errors['general'])): ?>
                    <div class="alert alert-danger"><?= htmlspecialchars($errors['general']) ?></div>
                <?php endif; ?>

                <?php if ($existingSubscription
                    && in_array($existingSubscription['status'], ['Active', 'Trial'], true)): ?>

                    <div class="alert alert-success">
                        <strong><?= htmlspecialchars($existingSubscription['plan_name']) ?></strong>
                        is already active until
                        <?= htmlspecialchars(date('M d, Y', strtotime($existingSubscription['expiry_date']))) ?>.
                        You can sign in to the system now.
                    </div>

                    <a href="/SariSmarts/accounts/acc_log_in.php" class="btn text-white"
                        style="background:#00224c;">Sign In</a>

                <?php else: ?>

                    <?php if ($showConfirmation): ?>

                        <div class="confirm-card">

                            <div class="confirm-label">The plan you chose</div>

                            <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">

                                <div>
                                    <div class="confirm-plan"><?= htmlspecialchars($chosenPlan['plan_name']) ?></div>
                                    <div class="text-muted"><?= htmlspecialchars($chosenPlan['tagline'] ?? '') ?></div>
                                </div>

                                <div class="text-end">
                                    <div class="confirm-price">
                                        &#8369;<?= number_format((float) $existingSubscription['amount'], 2) ?>
                                    </div>
                                    <div class="text-muted small">
                                        <?= htmlspecialchars($existingSubscription['billing_cycle']) ?>
                                    </div>
                                </div>

                            </div>

                            <div class="row g-3 mt-1">
                                <div class="col-6 col-md-4">
                                    <div class="confirm-label">Business</div>
                                    <div class="fw-semibold"><?= htmlspecialchars($company['company_name']) ?></div>
                                </div>
                                <div class="col-6 col-md-4">
                                    <div class="confirm-label">Branches</div>
                                    <div class="fw-semibold">
                                        <?= (int) $chosenPlan['max_branches'] >= 999999
                                            ? 'Unlimited'
                                            : number_format((int) $chosenPlan['max_branches']) ?>
                                    </div>
                                </div>
                                <div class="col-6 col-md-4">
                                    <div class="confirm-label">Users</div>
                                    <div class="fw-semibold">
                                        <?= (int) $chosenPlan['max_users'] >= 9999
                                            ? 'Unlimited'
                                            : number_format((int) $chosenPlan['max_users']) ?>
                                    </div>
                                </div>
                            </div>

                            <?php
                            /*
                            | Guarded by assets/js/public-form-guard.js. Nothing
                            | here needs checking - every field is hidden and set
                            | by us - but the button commits the business to a
                            | paid plan, so it asks first.
                            */
                            ?>
                            <form method="POST" class="mt-4"
                                data-guard
                                data-confirm-title="Proceed to payment?"
                                data-confirm-text="We will record this plan and contact you to settle payment. Your access is activated once it is settled."
                                data-confirm-ok="Yes, proceed">

                                <input type="hidden" name="ref" value="<?= htmlspecialchars($approvalRef) ?>">
                                <input type="hidden" name="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                                <input type="hidden" name="password" value="<?= htmlspecialchars($_POST['password'] ?? '') ?>">
                                <input type="hidden" name="plan_id" value="<?= (int) $chosenPlan['plan_id'] ?>">
                                <input type="hidden" name="billing_cycle"
                                    value="<?= htmlspecialchars($existingSubscription['billing_cycle']) ?>">

                                <button type="submit" name="choosePlan" class="btn text-white w-100 py-2"
                                    style="background:#00224c;">
                                    Proceed to Payment
                                </button>

                            </form>

                            <div class="text-center mt-3">
                                <a class="change-plan"
                                    href="?change=1<?= $approvalRef !== '' ? '&amp;ref=' . urlencode($approvalRef) : '' ?>">
                                    Change plan
                                </a>
                            </div>

                        </div>

                    <?php elseif ($existingSubscription && $existingSubscription['status'] === 'Pending'): ?>
                        <div class="alert alert-info">
                            You previously chose <strong><?= htmlspecialchars($existingSubscription['plan_name']) ?></strong>,
                            which is awaiting payment. Choosing again replaces it.
                        </div>
                    <?php endif; ?>

                    <?php if (isset($errors['plan_id'])): ?>
                        <div class="alert alert-warning"><?= htmlspecialchars($errors['plan_id']) ?></div>
                    <?php endif; ?>

                    <?php
                    /*
                    | The picker is the alternative to the confirmation card, not a
                    | companion to it. Showing both would ask the owner to choose a
                    | plan directly underneath the plan they were just asked to
                    | confirm. "Change plan" is what brings it back.
                    */
                    ?>
                    <?php if (!$showConfirmation): ?>

                    <?php if ($signedInOwner): ?>
                    <div class="modal fade" id="planPickerModal" tabindex="-1" aria-hidden="true">
                      <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
                        <div class="modal-content" style="border-radius:18px;">
                          <div class="modal-header" style="background:#00224c;color:#fff;">
                            <div>
                              <h5 class="modal-title fw-bold">Activate your subscription</h5>
                              <small style="color:#cbd5e1;">
                                <?= htmlspecialchars($company['company_name'] ?? '') ?> is approved.
                                Choose a plan to open the system.
                              </small>
                            </div>
                          </div>
                          <div class="modal-body p-4">
                    <?php endif; ?>

                    <?php
                    /*
                    | Guarded by assets/js/public-form-guard.js: it makes sure a
                    | plan was actually picked, then asks before committing.
                    */
                    ?>
                    <form method="POST" id="planForm"
                        data-guard
                        data-confirm-title="Confirm this plan?"
                        data-confirm-text="We will record your choice and contact you to settle payment. Your access is activated once it is settled."
                        data-confirm-ok="Yes, confirm">

                        <?php if (!$signedInOwner): ?>
                            <input type="hidden" name="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                            <input type="hidden" name="password" value="<?= htmlspecialchars($_POST['password'] ?? '') ?>">
                        <?php endif; ?>

                        <div class="d-flex justify-content-center mb-4">
                            <div class="btn-group cycle-toggle" role="group">
                                <input type="radio" class="btn-check" name="billing_cycle" id="cycleMonthly"
                                    value="Monthly" checked>
                                <label class="btn btn-outline-secondary px-4" for="cycleMonthly">Monthly</label>

                                <input type="radio" class="btn-check" name="billing_cycle" id="cycleYearly"
                                    value="Yearly">
                                <label class="btn btn-outline-secondary px-4" for="cycleYearly">Yearly</label>
                            </div>
                        </div>

                        <div class="row g-3">

                            <?php foreach ($plans as $plan): ?>

                                <div class="col-md-4">

                                    <label class="plan-option d-block">

                                        <?php
                                        /*
                                        | required on every option: the guard
                                        | reads the group, not the one input, so
                                        | it reports "choose a plan" once.
                                        */
                                        ?>
                                        <input type="radio" name="plan_id" required
                                            title="Please choose a plan."
                                            value="<?= (int) $plan['plan_id'] ?>">

                                        <?php if ($plan['badge'] !== 'None' && $plan['badge'] !== ''): ?>
                                            <span class="badge bg-primary mb-2"><?= htmlspecialchars($plan['badge']) ?></span>
                                        <?php endif; ?>

                                        <div class="fw-bold fs-5" style="color:#00224c;">
                                            <?= htmlspecialchars($plan['plan_name']) ?>
                                        </div>

                                        <div class="text-muted small mb-3">
                                            <?= htmlspecialchars($plan['tagline'] ?? '') ?>
                                        </div>

                                        <div class="plan-price">
                                            <?php if (!empty($plan['price_label'])): ?>
                                                <?= htmlspecialchars($plan['price_label']) ?>
                                            <?php else: ?>
                                                <span class="price-monthly">
                                                    &#8369;<?= number_format((float) $plan['monthly_price']) ?>
                                                    <span class="text-muted" style="font-size:13px;font-weight:400;">/mo</span>
                                                </span>
                                                <span class="price-yearly d-none">
                                                    &#8369;<?= number_format((float) $plan['yearly_price']) ?>
                                                    <span class="text-muted" style="font-size:13px;font-weight:400;">/yr</span>
                                                </span>
                                            <?php endif; ?>
                                        </div>

                                        <div class="text-muted small mt-3">
                                            <?= (int) $plan['max_branches'] >= 999999
                                                ? 'Unlimited branches'
                                                : number_format((int) $plan['max_branches']) . ' branch(es)' ?>
                                            <br>
                                            <?= (int) $plan['max_users'] >= 9999
                                                ? 'Unlimited users'
                                                : number_format((int) $plan['max_users']) . ' users' ?>
                                        </div>

                                    </label>

                                </div>

                            <?php endforeach; ?>

                        </div>

                        <button type="submit" name="choosePlan" class="btn text-white w-100 mt-4 py-3"
                            style="background:#00224c;">
                            Confirm Plan
                        </button>

                        <p class="text-muted small text-center mt-3 mb-0">
                            You are not charged on this page. Our team will contact you to settle payment,
                            then your access is activated.
                        </p>

                    </form>

                    <?php if ($signedInOwner): ?>
                          </div>
                          <div class="modal-footer justify-content-between">
                            <a href="accounts/acc_log_out.php" class="btn btn-light border">Sign out</a>
                            <span class="text-muted small">
                              You cannot use the system until a subscription is active.
                            </span>
                          </div>
                        </div>
                      </div>
                    </div>
                    <?php endif; ?>

                    <?php endif; ?>

                <?php endif; ?>

            <?php endif; ?>

        </div>

    </div>

</div>


<script>
    document.addEventListener("DOMContentLoaded", function () {

        // An owner sent here straight from login has not asked for this page,
        // so the plan picker announces itself as a modal rather than sitting
        // quietly on a page they did not choose to visit.
        var picker = document.getElementById("planPickerModal");

        if (picker) {
            new bootstrap.Modal(picker, { backdrop: "static", keyboard: false }).show();
        }

        document.querySelectorAll(".plan-option").forEach(function (option) {
            option.addEventListener("click", function () {
                document.querySelectorAll(".plan-option").forEach(function (o) {
                    o.classList.remove("selected");
                });
                option.classList.add("selected");
            });
        });

        function updatePrices() {
            var yearly = document.getElementById("cycleYearly");
            var showYearly = yearly && yearly.checked;

            document.querySelectorAll(".price-monthly").forEach(function (el) {
                el.classList.toggle("d-none", showYearly);
            });
            document.querySelectorAll(".price-yearly").forEach(function (el) {
                el.classList.toggle("d-none", !showYearly);
            });
        }

        document.querySelectorAll("input[name='billing_cycle']").forEach(function (input) {
            input.addEventListener("change", updatePrices);
        });

        updatePrices();

    });
</script>

<!--
    The same guard register.php and resubmit.php use: a plan has to be picked,
    and the commitment is confirmed before it is sent.
-->
<script src="<?= $BASE_URL ?>/assets/js/public-form-guard.js"></script>

<?php include("footer.php"); ?>
