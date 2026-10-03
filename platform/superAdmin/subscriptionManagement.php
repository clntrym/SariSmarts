<?php
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../includes/platform_roles.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/plan_entitlements.php';
require_once __DIR__ . '/../config.php';

/*
| This page never had a guard of its own: it leaned on the one inside
| sAdminHeader.php, which is included further down. That was survivable
| while Super Admin was the only role, but the AJAX endpoints and every
| write handler below run before that include, so by the time the header
| checked anything the work was already done.
*/
requirePlatformAccess('subscriptionManagement');

/*
|--------------------------------------------------------------------------
| AJAX ENDPOINTS
|--------------------------------------------------------------------------
|
| These three answer with JSON and exit, so they have to run before the
| layout is included. Underneath it they were echoing their JSON after a
| full copy of the admin page, which jQuery could not parse -- the Edit
| Plan, View Plan and View Subscription modals never filled in.
|
*/

/* =====================================================
   VIEW COMPANY SUBSCRIPTION (AJAX)
===================================================== */

if (isset($_POST['viewSubscription'])) {

    $subscription_id = (int) $_POST['subscription_id'];

    /* ==========================================
       LOAD SUBSCRIPTION + COMPANY + PLAN
    ========================================== */

    $query = mysqli_query($conn, "

        SELECT

            cs.*,

            c.company_name,
            c.email,
            c.phone AS contact_number,
            c.address,

            sp.plan_name

        FROM company_subscriptions cs

        INNER JOIN company c
            ON c.company_id = cs.company_id

        INNER JOIN subscription_plans sp
            ON sp.plan_id = cs.plan_id

        WHERE cs.subscription_id='$subscription_id'

        LIMIT 1

    ");

    if (mysqli_num_rows($query) == 0) {

        echo json_encode([
            "success" => false,
            "message" => "Subscription not found."
        ]);

        exit;

    }

    $subscription = mysqli_fetch_assoc($query);

    /* ==========================================
       LOAD PLAN FEATURES
    ========================================== */

    $features = [];

    $featureQuery = mysqli_query($conn, "

        SELECT feature_name

        FROM subscription_plan_features

        WHERE plan_id='" . (int) $subscription['plan_id'] . "'

        ORDER BY feature_name ASC

    ");

    while ($feature = mysqli_fetch_assoc($featureQuery)) {

        $features[] = $feature['feature_name'];

    }

    /* ==========================================
       LOAD HISTORY
    ========================================== */

    $history = [];

    $historyQuery = mysqli_query($conn, "

        SELECT *

        FROM subscription_history

        WHERE subscription_id='$subscription_id'

        ORDER BY created_at DESC

    ");

    while ($row = mysqli_fetch_assoc($historyQuery)) {

        $history[] = $row;

    }

    /* ==========================================
       RETURN JSON
    ========================================== */

    echo json_encode([

        "success" => true,

        "subscription" => $subscription,

        "features" => $features,

        "history" => $history

    ]);

    exit;

}

/* =====================================================
   LOAD SUBSCRIPTION PLAN (AJAX)
===================================================== */

if (isset($_POST['loadPlan'])) {

    $plan_id = (int) $_POST['plan_id'];

    $plan = mysqli_fetch_assoc(mysqli_query($conn, "
        SELECT *
        FROM subscription_plans
        WHERE plan_id='$plan_id'
    "));

    $features = [];

    $featureQuery = mysqli_query($conn, "
        SELECT feature_name
        FROM subscription_plan_features
        WHERE plan_id='$plan_id'
    ");

    while ($row = mysqli_fetch_assoc($featureQuery)) {
        $features[] = $row['feature_name'];
    }

    echo json_encode([
        "plan" => $plan,
        "features" => $features
    ]);

    exit;
}

/* =====================================================
   VIEW SUBSCRIPTION PLAN (AJAX)
===================================================== */

if (isset($_POST['viewPlan'])) {

    $plan_id = (int) $_POST['plan_id'];

    $planQuery = mysqli_query($conn, "
        SELECT *
        FROM subscription_plans
        WHERE plan_id = '$plan_id'
        LIMIT 1
    ");

    if (!$planQuery || mysqli_num_rows($planQuery) == 0) {

        echo json_encode([
            "success" => false,
            "message" => "Subscription plan not found."
        ]);

        exit;
    }

    $plan = mysqli_fetch_assoc($planQuery);

    $features = [];

    $featureQuery = mysqli_query($conn, "
        SELECT feature_name
        FROM subscription_plan_features
        WHERE plan_id = '$plan_id'
        ORDER BY feature_name ASC
    ");

    while ($feature = mysqli_fetch_assoc($featureQuery)) {

        $features[] = $feature['feature_name'];

    }

    echo json_encode([

        "success" => true,

        "plan" => [

            "plan_id" => $plan['plan_id'],
            "plan_name" => $plan['plan_name'],
            "description" => $plan['description'],
            "monthly_price" => number_format($plan['monthly_price'], 2),
            "yearly_price" => number_format($plan['yearly_price'], 2),
            "max_branches" => $plan['max_branches'],
            "max_users" => $plan['max_users'],
            "trial_days" => $plan['trial_days'],
            "badge" => $plan['badge'],
            "status" => $plan['status']

        ],

        "features" => $features

    ]);

    exit;

}

include('sAdminHeader.php');
include('subscriptionModal.php');

/**
 * Keep company.status in step with its subscription.
 *
 * The login gate refuses anything that is not an Active company with a
 * live subscription, so a company approved in review stays locked out
 * until its subscription actually goes Active. Without this, paying a
 * newly approved business changed nothing they could see.
 *
 * Only the Approved <-> Active pair is touched. Suspended and Inactive
 * are deliberate operator decisions and are left alone.
 */
function syncCompanyStatusWithSubscription(mysqli $conn, int $companyId): void
{
    if ($companyId <= 0) {
        return;
    }

    $stmt = $conn->prepare("
        SELECT status FROM company_subscriptions
        WHERE company_id = ?
        ORDER BY expiry_date DESC
        LIMIT 1
    ");
    $stmt->bind_param("i", $companyId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return;
    }

    $live = in_array($row['status'], ['Active', 'Trial'], true);

    $stmt = $conn->prepare("
        UPDATE company
        SET status = ?
        WHERE company_id = ? AND status IN ('Approved', 'Active')
    ");
    $newStatus = $live ? 'Active' : 'Approved';
    $stmt->bind_param("si", $newStatus, $companyId);
    $stmt->execute();
    $stmt->close();
}

/* =====================================================
   AUTO EXPIRE SUBSCRIPTIONS
===================================================== */

$today = date("Y-m-d");

/* ==========================================
   GET ALL ACTIVE/TRIAL SUBSCRIPTIONS
========================================== */

$expiredSubscriptions = mysqli_query($conn, "

    SELECT

        subscription_id

    FROM company_subscriptions

    WHERE

        expiry_date < '$today'

        AND status IN ('Active','Trial')

");

while ($expired = mysqli_fetch_assoc($expiredSubscriptions)) {

    $subscription_id = $expired['subscription_id'];

    /* ======================================
       UPDATE STATUS
    ====================================== */

    mysqli_query($conn, "

        UPDATE company_subscriptions

        SET

            status='Expired'

        WHERE subscription_id='$subscription_id'

    ");

    /* ======================================
       SAVE HISTORY
    ====================================== */

    mysqli_query($conn, "

        INSERT INTO subscription_history(

            subscription_id,
            action,
            remarks

        )

        VALUES(

            '$subscription_id',

            'Expired',

            'Subscription expired automatically.'

        )

    ");

}

/* =====================================================
   CHANGE SUBSCRIPTION PLAN
===================================================== */

if (isset($_POST['changePlan'])) {

    $subscription_id = (int) $_POST['subscription_id'];
    $plan_id = (int) $_POST['plan_id'];

    /* =====================================
       GET CURRENT SUBSCRIPTION
    ===================================== */

    $subscription = mysqli_fetch_assoc(mysqli_query($conn, "

        SELECT *

        FROM company_subscriptions

        WHERE subscription_id='$subscription_id'

    "));

    if (!$subscription) {

        echo "

        <script>

        Swal.fire({

            icon:'error',

            title:'Subscription Not Found',

            confirmButtonColor:'#dc3545'

        });

        </script>

        ";

    } else {

        /* =====================================
           GET NEW PLAN
        ===================================== */

        $plan = mysqli_fetch_assoc(mysqli_query($conn, "

            SELECT *

            FROM subscription_plans

            WHERE plan_id='$plan_id'

        "));

        if (!$plan) {

            echo "

            <script>

            Swal.fire({

                icon:'error',

                title:'Plan Not Found',

                confirmButtonColor:'#dc3545'

            });

            </script>

            ";

        } else {

            /* =====================================
               COMPUTE NEW PRICE
            ===================================== */

            if ($subscription['billing_cycle'] == "Monthly") {

                $amount = $plan['monthly_price'];

            } else {

                $amount = $plan['yearly_price'];

            }

            /* =====================================
               UPDATE SUBSCRIPTION
            ===================================== */

            $update = mysqli_query($conn, "

                UPDATE company_subscriptions

                SET

                    plan_id='$plan_id',

                    amount='$amount'

                WHERE subscription_id='$subscription_id'

            ");

            if ($update) {
                syncCompanyStatusWithSubscription($conn, (int) ($subscription['company_id'] ?? 0));

                mysqli_query($conn, "

                    INSERT INTO subscription_history(

                        subscription_id,
                        action,
                        remarks

                    )

                    VALUES(

                        '$subscription_id',

                        'Plan Changed',

                        'Subscription plan changed by Super Admin.'

                    )

                ");

                echo "

                <script>

                Swal.fire({

                    icon:'success',

                    title:'Plan Updated',

                    text:'Subscription plan updated successfully.',

                    confirmButtonColor:'#16a34a'

                }).then(()=>{

                    window.location='subscriptionManagement.php';

                });

                </script>

                ";

            } else {

                echo "

                <script>

                Swal.fire({

                    icon:'error',

                    title:'Database Error',

                    text:'" . mysqli_error($conn) . "',

                    confirmButtonColor:'#dc3545'

                });

                </script>

                ";

            }

        }

    }

}

/* ==========================================
   SAVE SUBSCRIPTION PLAN
========================================== */

/*
| The layout is already on the page by the time a handler runs, so these
| screens report back with an inline Swal rather than the $alert pattern
| the other Super Admin pages use. planAlert() keeps that one shape.
*/
function planAlert(string $icon, string $title, string $text, ?string $redirect = null): void
{
    $colours = ['success' => '#198754', 'warning' => '#f59e0b'];

    echo '<script>Swal.fire({'
        . 'icon:' . json_encode($icon) . ','
        . 'title:' . json_encode($title) . ','
        . 'text:' . json_encode($text) . ','
        . 'confirmButtonColor:' . json_encode($colours[$icon] ?? '#dc3545')
        . '})'
        . ($redirect !== null ? '.then(function(){window.location=' . json_encode($redirect) . ';})' : '')
        . ';</script>';
}

/*
| Shared by the add and edit plan forms. Returns the first problem as a
| sentence, or null when the submission is usable.
|
| Nothing validated these before: a blank name, a price of "abc" or a
| badge outside the column's enum all went straight into the INSERT.
*/
function validatePlanInput(array $post): ?string
{
    if (trim($post['plan_name'] ?? '') === '') {
        return 'Plan name is required.';
    }

    if (trim($post['description'] ?? '') === '') {
        return 'Description is required.';
    }

    $prices = ['monthly_price' => 'Monthly price', 'yearly_price' => 'Yearly price'];

    foreach ($prices as $field => $label) {
        $value = $post[$field] ?? '';
        if ($value === '' || !is_numeric($value) || (float) $value < 0) {
            return $label . ' must be a number of zero or more.';
        }
    }

    $counts = ['max_branches' => 'Branch limit', 'max_users' => 'User limit', 'trial_days' => 'Trial days'];

    foreach ($counts as $field => $label) {
        $value = (string) ($post[$field] ?? '');
        if ($value === '' || !ctype_digit($value)) {
            return $label . ' must be a whole number of zero or more.';
        }
    }

    if (!in_array($post['badge'] ?? '', ['None', 'Most Popular', 'Recommended', 'Best Value'], true)) {
        return 'Please choose a valid badge.';
    }

    if (!in_array($post['status'] ?? '', ['Active', 'Inactive'], true)) {
        return 'Please choose a valid status.';
    }

    return null;
}

/*
| Shared by the assign and edit subscription forms. Returns the first
| problem as a sentence, or null when the submission is usable.
|
| $plan is the row already looked up by the caller, so a plan_id that
| matches nothing arrives here as null.
*/
function subscriptionInputProblem(
    mysqli $conn,
    int $subscriptionId,
    int $companyId,
    $plan,
    string $billingCycle,
    string $paymentStatus,
    string $status,
    string $startDate
): ?string {

    /* Assigning has no id yet, so only an edit is checked for one. */
    if ($subscriptionId > 0) {
        $existing = $conn->query("SELECT subscription_id FROM company_subscriptions WHERE subscription_id = " . $subscriptionId . " LIMIT 1");

        if (!$existing || $existing->num_rows === 0) {
            return 'That subscription no longer exists.';
        }
    }

    if ($companyId <= 0) {
        return 'Please choose a company.';
    }

    $company = $conn->query("SELECT company_id FROM company WHERE company_id = " . $companyId . " LIMIT 1");

    if (!$company || $company->num_rows === 0) {
        return 'That company no longer exists.';
    }

    if (!$plan) {
        return 'Please choose a subscription plan.';
    }

    if (!in_array($billingCycle, ['Monthly', 'Yearly'], true)) {
        return 'Please choose a billing cycle.';
    }

    /*
    | These two lists are the column enums, not a superset of them. An
    | earlier version also allowed Unpaid, Refunded and Suspended, which
    | the columns reject, so validation would pass and the write would
    | quietly store nothing.
    */
    if (!in_array($paymentStatus, ['Pending', 'Paid', 'Failed'], true)) {
        return 'Please choose a valid payment status.';
    }

    if (!in_array($status, ['Pending', 'Trial', 'Active', 'Expired', 'Cancelled'], true)) {
        return 'Please choose a valid subscription status.';
    }

    $parsed = date_create_from_format('Y-m-d', $startDate);

    if (!$parsed || $parsed->format('Y-m-d') !== $startDate) {
        return 'Start date must be a real date.';
    }

    return null;
}

/*
| Replaces a plan's feature list with whatever was ticked.
|
| system_module is written alongside the name, derived from it. This used to
| insert (plan_id, feature_name) only, so every save blanked the column that
| the tenant app enforces against -- which is how all 50 features came to
| hold NULL, and how Retail Starter ended up granting modules it never sold.
|
| Deriving it here rather than carrying it through the form means the
| mapping repairs itself on the next save instead of depending on a hidden
| field nobody can see is missing.
*/
function savePlanFeatures(mysqli $conn, int $planId, array $features): void
{
    $stmt = $conn->prepare("DELETE FROM subscription_plan_features WHERE plan_id = ?");
    $stmt->bind_param("i", $planId);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("
        INSERT INTO subscription_plan_features (plan_id, feature_name, system_module)
        VALUES (?, ?, ?)
    ");

    foreach ($features as $feature) {

        $feature = trim((string) $feature);

        if ($feature === '') {
            continue;
        }

        /* null for the many features that gate nothing, which is what
           companyHasModule() reads as "nobody sells it, nobody is denied". */
        $module = moduleSlugForFeature($feature);

        $stmt->bind_param("iss", $planId, $feature, $module);
        $stmt->execute();
    }

    $stmt->close();
}

if (isset($_POST['savePlan'])) {

    $problem = validatePlanInput($_POST);

    if ($problem !== null) {

        planAlert('error', 'Missing Details', $problem);

    } else {

        $stmt = $conn->prepare("
            INSERT INTO subscription_plans
                (plan_name, description, monthly_price, yearly_price,
                 max_branches, max_users, trial_days, badge, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $planName = trim($_POST['plan_name']);
        $description = trim($_POST['description']);
        $monthlyPrice = (float) $_POST['monthly_price'];
        $yearlyPrice = (float) $_POST['yearly_price'];
        $maxBranches = (int) $_POST['max_branches'];
        $maxUsers = (int) $_POST['max_users'];
        $trialDays = (int) $_POST['trial_days'];
        $badge = $_POST['badge'];
        $status = $_POST['status'];

        $stmt->bind_param(
            "ssddiiiss",
            $planName, $description, $monthlyPrice, $yearlyPrice,
            $maxBranches, $maxUsers, $trialDays, $badge, $status
        );

        if ($stmt->execute()) {

            $newPlanId = (int) $conn->insert_id;
            savePlanFeatures($conn, $newPlanId, (array) ($_POST['features'] ?? []));
            $stmt->close();

            auditLog($conn, 'Plan created', 'plan', $newPlanId,
                     $planName . ' (' . $status . ', PHP ' . number_format($monthlyPrice, 2) . '/month)');

            planAlert('success', 'Plan Added', $planName . ' has been added.', 'subscriptionManagement.php');

        } else {

            $error = $conn->error;
            $stmt->close();

            planAlert('error', 'Database Error', $error);
        }
    }

}

/* ==========================================
   UPDATE SUBSCRIPTION PLAN
========================================== */

/*
| The Edit Plan modal has always posted updatePlan, and nothing ever
| handled it: the form reloaded the page and the edits were dropped
| without a word.
*/
if (isset($_POST['updatePlan'])) {

    $planId = (int) ($_POST['plan_id'] ?? 0);
    $problem = validatePlanInput($_POST);

    if ($planId <= 0) {

        planAlert('error', 'Plan Not Found', 'That plan no longer exists.');

    } elseif ($problem !== null) {

        planAlert('error', 'Missing Details', $problem);

    } else {

        $stmt = $conn->prepare("
            UPDATE subscription_plans SET
                plan_name = ?, description = ?, monthly_price = ?, yearly_price = ?,
                max_branches = ?, max_users = ?, trial_days = ?, badge = ?, status = ?
            WHERE plan_id = ?
        ");

        $planName = trim($_POST['plan_name']);
        $description = trim($_POST['description']);
        $monthlyPrice = (float) $_POST['monthly_price'];
        $yearlyPrice = (float) $_POST['yearly_price'];
        $maxBranches = (int) $_POST['max_branches'];
        $maxUsers = (int) $_POST['max_users'];
        $trialDays = (int) $_POST['trial_days'];
        $badge = $_POST['badge'];
        $status = $_POST['status'];

        $stmt->bind_param(
            "ssddiiissi",
            $planName, $description, $monthlyPrice, $yearlyPrice,
            $maxBranches, $maxUsers, $trialDays, $badge, $status, $planId
        );

        if ($stmt->execute()) {

            savePlanFeatures($conn, $planId, (array) ($_POST['features'] ?? []));
            $stmt->close();

            auditLog($conn, 'Plan updated', 'plan', $planId,
                     $planName . ' (' . $status . ', PHP ' . number_format($monthlyPrice, 2) . '/month)');

            planAlert('success', 'Plan Updated', $planName . ' has been updated.', 'subscriptionManagement.php');

        } else {

            $error = $conn->error;
            $stmt->close();

            planAlert('error', 'Database Error', $error);
        }
    }

}

/* =====================================================
   ARCHIVE / RESTORE A PLAN

   The Archive button in the plans table did nothing at all: no name, no
   form, no data-id, no listener. It was markup that looked like a control.

   Archiving flips the plan to Inactive, which is what takes it off the
   public pricing page. It is not a delete, and a company already on the
   plan keeps it -- which is the whole reason to have this instead of a
   delete.
===================================================== */

if (isset($_POST['togglePlanStatus'])) {

    $planId = (int) ($_POST['plan_id'] ?? 0);

    $stmt = $conn->prepare("SELECT plan_name, status FROM subscription_plans WHERE plan_id = ? LIMIT 1");
    $stmt->bind_param("i", $planId);
    $stmt->execute();
    $plan = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$plan) {

        planAlert('error', 'Plan Not Found', 'That plan no longer exists.', 'subscriptionManagement.php');

    } else {

        $newStatus = $plan['status'] === 'Active' ? 'Inactive' : 'Active';

        $stmt = $conn->prepare("UPDATE subscription_plans SET status = ? WHERE plan_id = ?");
        $stmt->bind_param("si", $newStatus, $planId);
        $stmt->execute();

        /*
        | An UPDATE that matched nothing still reports success, so the row
        | count is what decides whether anything happened.
        */
        $changed = $stmt->affected_rows;
        $stmt->close();

        if ($changed === 0) {

            planAlert('warning', 'Nothing Changed', $plan['plan_name'] . ' is already ' . $newStatus . '.');

        } else {

            auditLog($conn, 'Plan ' . strtolower($newStatus === 'Inactive' ? 'archived' : 'restored'),
                     'plan', $planId, $plan['plan_name'] . ' is now ' . $newStatus);

            planAlert(
                'success',
                $newStatus === 'Inactive' ? 'Plan Archived' : 'Plan Restored',
                $newStatus === 'Inactive'
                    ? $plan['plan_name'] . ' is now Inactive and no longer appears on the pricing page. Companies already on it keep it.'
                    : $plan['plan_name'] . ' is Active again and back on the pricing page.',
                'subscriptionManagement.php'
            );
        }
    }
}


/* =====================================================
   DELETE A PLAN

   The Delete button was dead in the same way the Archive button was.

   A plan is only removable when nothing points at it. company_subscriptions
   and chatbot_topic_plans both hold it under ON DELETE RESTRICT, so the
   database would refuse anyway -- but it would refuse with a foreign key
   error, which tells the reader nothing about which company is in the way.
   These checks say it in words first.

   subscription_plan_features cascades. subscription_plan_roles has no
   foreign key at all, so its rows are removed by hand or they are orphaned.
===================================================== */

if (isset($_POST['deletePlan'])) {

    $planId = (int) ($_POST['plan_id'] ?? 0);

    $stmt = $conn->prepare("SELECT plan_name FROM subscription_plans WHERE plan_id = ? LIMIT 1");
    $stmt->bind_param("i", $planId);
    $stmt->execute();
    $plan = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$plan) {

        planAlert('error', 'Plan Not Found', 'That plan no longer exists.', 'subscriptionManagement.php');

    } else {

        $stmt = $conn->prepare("SELECT COUNT(*) AS n FROM company_subscriptions WHERE plan_id = ?");
        $stmt->bind_param("i", $planId);
        $stmt->execute();
        $inUse = (int) ($stmt->get_result()->fetch_assoc()['n'] ?? 0);
        $stmt->close();

        $stmt = $conn->prepare("SELECT COUNT(*) AS n FROM chatbot_topic_plans WHERE plan_id = ?");
        $stmt->bind_param("i", $planId);
        $stmt->execute();
        $topics = (int) ($stmt->get_result()->fetch_assoc()['n'] ?? 0);
        $stmt->close();

        if ($inUse > 0) {

            planAlert(
                'warning',
                'Plan In Use',
                $plan['plan_name'] . ' is on ' . $inUse . ' subscription'
                    . ($inUse === 1 ? '' : 's')
                    . '. Move those companies to another plan first, or archive this one instead.'
            );

        } elseif ($topics > 0) {

            planAlert(
                'warning',
                'Plan In Use',
                $plan['plan_name'] . ' is referenced by ' . $topics . ' chatbot topic'
                    . ($topics === 1 ? '' : 's') . '. Archive it instead.'
            );

        } else {

            $conn->begin_transaction();

            try {

                /* No foreign key on this one, so it would be left behind. */
                $stmt = $conn->prepare("DELETE FROM subscription_plan_roles WHERE plan_id = ?");
                $stmt->bind_param("i", $planId);
                $stmt->execute();
                $stmt->close();

                $stmt = $conn->prepare("DELETE FROM subscription_plans WHERE plan_id = ?");
                $stmt->bind_param("i", $planId);
                $stmt->execute();
                $removed = $stmt->affected_rows;
                $stmt->close();

                if ($removed === 0) {
                    throw new RuntimeException('The plan was already gone.');
                }

                $conn->commit();

                auditLog($conn, 'Plan deleted', 'plan', $planId, $plan['plan_name']);

                planAlert('success', 'Plan Deleted',
                          $plan['plan_name'] . ' has been removed.', 'subscriptionManagement.php');

            } catch (Throwable $e) {

                $conn->rollback();

                planAlert('error', 'Delete Failed',
                          'Could not delete ' . $plan['plan_name'] . '. Please try again.');
            }
        }
    }
}


/* =====================================================
   UPDATE COMPANY SUBSCRIPTION
===================================================== */

if (isset($_POST['updateSubscription'])) {

    $subscription_id = (int) ($_POST['subscription_id'] ?? 0);
    $company_id = (int) ($_POST['company_id'] ?? 0);
    $plan_id = (int) ($_POST['plan_id'] ?? 0);

    $billing_cycle = mysqli_real_escape_string($conn, $_POST['billing_cycle'] ?? '');
    $payment_status = mysqli_real_escape_string($conn, $_POST['payment_status'] ?? '');
    $status = mysqli_real_escape_string($conn, $_POST['status'] ?? '');

    $start_date = trim($_POST['start_date'] ?? '');
    $notes = mysqli_real_escape_string($conn, $_POST['notes'] ?? '');

    /* ==========================================
       GET SELECTED PLAN
    ========================================== */

    $plan = mysqli_fetch_assoc(mysqli_query($conn, "

        SELECT *

        FROM subscription_plans

        WHERE plan_id='$plan_id'

    "));

    /*
    | Nothing checked any of this before. A missing plan walked into
    | $plan['monthly_price'] on a null, and start_date went into the
    | UPDATE unescaped and unparsed.
    */
    $problem = subscriptionInputProblem($conn, $subscription_id, $company_id, $plan, $billing_cycle, $payment_status, $status, $start_date);

    if ($problem !== null) {

        planAlert('error', 'Missing Details', $problem);

    } else {

    /* ==========================================
       COMPUTE PRICE
    ========================================== */

    if ($billing_cycle == "Monthly") {

        $amount = $plan['monthly_price'];

        $expiry_date = date(
            "Y-m-d",
            strtotime($start_date . " +1 month")
        );

    } else {

        $amount = $plan['yearly_price'];

        $expiry_date = date(
            "Y-m-d",
            strtotime($start_date . " +1 year")
        );

    }

    if ($status == "Trial") {

        $expiry_date = date(
            "Y-m-d",
            strtotime($start_date . " +" . $plan['trial_days'] . " days")
        );

    }


    /* ==========================================
   UPDATE SUBSCRIPTION
========================================== */

    $update = mysqli_query($conn, "

    UPDATE company_subscriptions

    SET

        company_id      = '$company_id',
        plan_id         = '$plan_id',
        billing_cycle   = '$billing_cycle',
        amount          = '$amount',
        start_date      = '$start_date',
        expiry_date     = '$expiry_date',
        renewal_date    = '$expiry_date',
        payment_status  = '$payment_status',
        status          = '$status',
        notes           = '$notes'

    WHERE subscription_id='$subscription_id'

    ");



    if ($update) {
        syncCompanyStatusWithSubscription($conn, $company_id);

        $history = mysqli_query($conn, "

        INSERT INTO subscription_history(

            subscription_id,
            action,
            remarks

        )

        VALUES(

            '$subscription_id',
            'Updated',
            'Subscription details were updated.'

        )

    ");

        if (!$history) {

            die("History Error: " . mysqli_error($conn));

        }

        echo "

    <script>

    Swal.fire({

        icon:'success',

        title:'Subscription Updated',

        text:'Company subscription updated successfully.',

        confirmButtonColor:'#16a34a'

    }).then(()=>{

        window.location='subscriptionManagement.php';

    });

    </script>

    ";

        } else {

            /* die() used to take the whole page down mid-render, leaving a
               half-drawn layout and no way back. */
            planAlert('error', 'Update Failed', mysqli_error($conn));

        }

    }

}

/* =====================================================
   SUSPEND / CANCEL SUBSCRIPTION
===================================================== */

if (isset($_POST['cancelSubscription'])) {

    $subscription_id = (int) $_POST['subscription_id'];

    /* =====================================
       CHECK IF SUBSCRIPTION EXISTS
    ===================================== */

    $check = mysqli_query($conn, "

        SELECT *

        FROM company_subscriptions

        WHERE subscription_id='$subscription_id'

    ");

    if (mysqli_num_rows($check) == 0) {

        echo "

        <script>

        Swal.fire({

            icon:'error',

            title:'Subscription Not Found',

            text:'The selected subscription no longer exists.',

            confirmButtonColor:'#dc3545'

        });

        </script>

        ";

    } else {

        /* =====================================
           UPDATE STATUS
        ===================================== */

        $update = mysqli_query($conn, "

            UPDATE company_subscriptions

            SET

                status='Cancelled'

            WHERE subscription_id='$subscription_id'

        ");

        if ($update) {
            syncCompanyStatusWithSubscription($conn, (int) ($subscription['company_id'] ?? 0));

            /* =====================================
               SAVE HISTORY
            ===================================== */

            mysqli_query($conn, "

                INSERT INTO subscription_history(

                    subscription_id,

                    action,

                    remarks

                )

                VALUES(

                    '$subscription_id',

                    'Cancelled',

                    'Subscription cancelled by Super Admin.'

                )

            ");

            echo "

            <script>

            Swal.fire({

                icon:'success',

                title:'Subscription Cancelled',

                text:'The subscription has been cancelled successfully.',

                confirmButtonColor:'#16a34a'

            }).then(()=>{

                window.location='subscriptionManagement.php';

            });

            </script>

            ";

        } else {

            echo "

            <script>

            Swal.fire({

                icon:'error',

                title:'Database Error',

                text:'" . mysqli_error($conn) . "',

                confirmButtonColor:'#dc3545'

            });

            </script>

            ";

        }

    }

}

/* =====================================================
   RENEW COMPANY SUBSCRIPTION
===================================================== */

if (isset($_POST['renewSubscription'])) {

    $subscription_id = (int) ($_POST['subscription_id'] ?? 0);

    /* ==========================================
       GET CURRENT SUBSCRIPTION
    ========================================== */

    /*
    | This lookup used to sit below the plan price and the double-renewal
    | check, both of which already read $subscription. On a renewal it had
    | never been set, so the plan came back empty, the amount came out
    | null, and the "already renewed today" guard could never fire. The
    | guard also used a bare return, which ended the script and left the
    | page half drawn.
    */
    $subscription = mysqli_fetch_assoc(mysqli_query($conn, "

        SELECT *

        FROM company_subscriptions

        WHERE subscription_id='$subscription_id'

    "));

    $plan = $subscription ? mysqli_fetch_assoc(mysqli_query($conn, "

        SELECT *

        FROM subscription_plans

        WHERE plan_id='" . (int) $subscription['plan_id'] . "'

    ")) : null;

    $alreadyRenewedToday = $subscription
        && $subscription['renewal_date'] == date("Y-m-d")
        && $subscription['payment_status'] == "Paid";

    if (!$subscription) {

        planAlert('error', 'Subscription Not Found', 'Unable to locate the selected subscription.');

    } elseif (!$plan) {

        planAlert('error', 'Plan Not Found', 'The plan behind this subscription no longer exists.');

    } elseif ($alreadyRenewedToday) {

        planAlert('warning', 'Already Renewed', 'This subscription has already been renewed today.');

    } else {

        $amount = $subscription['billing_cycle'] == "Monthly"
            ? $plan['monthly_price']
            : $plan['yearly_price'];

        /* ==========================================
   DETERMINE RENEWAL START DATE
========================================== */

        $today = date("Y-m-d");

        /*
        If subscription is still active,
        extend from current expiry date.

        If already expired,
        start from today.
        */

        if (strtotime($subscription['expiry_date']) >= strtotime($today)) {

            $renewStart = $subscription['expiry_date'];

        } else {

            $renewStart = $today;

        }

        /* ==========================================
           COMPUTE NEW EXPIRY
        ========================================== */

        if ($subscription['billing_cycle'] == "Monthly") {

            $newExpiry = date(
                "Y-m-d",
                strtotime($renewStart . " +1 month")
            );

        } else {

            $newExpiry = date(
                "Y-m-d",
                strtotime($renewStart . " +1 year")
            );

        }

        /* ==========================================
           UPDATE SUBSCRIPTION
        ========================================== */

        $renew = mysqli_query($conn, "

        UPDATE company_subscriptions

        SET

            amount='$amount',

            renewal_date='$newExpiry',

            expiry_date='$newExpiry',

            payment_status='Paid',

            status='Active'

        WHERE subscription_id='$subscription_id'

        ");

        if ($renew) {

            mysqli_query($conn, "

                INSERT INTO subscription_history(

                    subscription_id,
                    action,
                    remarks

                )

                VALUES(

                    '$subscription_id',

                    'Renewed',

                    'Subscription renewed successfully.'

                )

            ");

            echo "

            <script>

            Swal.fire({

                icon:'success',

                title:'Subscription Renewed',

                text:'The subscription has been renewed successfully.',

                confirmButtonColor:'#16a34a'

            }).then(()=>{

                window.location='subscriptionManagement.php';

            });

            </script>

            ";

        } else {

            echo "

            <script>

            Swal.fire({

                icon:'error',

                title:'Database Error',

                text:'" . mysqli_error($conn) . "',

                confirmButtonColor:'#dc3545'

            });

            </script>

            ";

        }

    }

}


/* =====================================================
   ASSIGN COMPANY SUBSCRIPTION
===================================================== */

if (isset($_POST['assignSubscription'])) {

    $company_id = (int) ($_POST['company_id'] ?? 0);
    $plan_id = (int) ($_POST['plan_id'] ?? 0);

    $billing_cycle = mysqli_real_escape_string($conn, $_POST['billing_cycle'] ?? '');
    $status = mysqli_real_escape_string($conn, $_POST['status'] ?? '');
    $payment_status = mysqli_real_escape_string($conn, $_POST['payment_status'] ?? '');

    $start_date = trim($_POST['start_date'] ?? '');
    $notes = mysqli_real_escape_string($conn, $_POST['notes'] ?? '');

    $assignedPlan = mysqli_fetch_assoc(mysqli_query($conn, "
        SELECT * FROM subscription_plans WHERE plan_id='$plan_id'
    "));

    $problem = subscriptionInputProblem(
        $conn, 0, $company_id, $assignedPlan,
        $billing_cycle, $payment_status, $status, $start_date
    );

    /* =====================================
       CHECK ACTIVE SUBSCRIPTION
    ===================================== */

    $check = $problem === null ? mysqli_query($conn, "

        SELECT subscription_id

        FROM company_subscriptions

        WHERE company_id='$company_id'

        AND status IN('Active','Trial')

    ") : null;

    if ($problem !== null) {

        planAlert('error', 'Missing Details', $problem);

    } elseif (mysqli_num_rows($check) > 0) {

        echo "

        <script>

        Swal.fire({

            icon:'warning',

            title:'Already Assigned',

            text:'This company already has an active subscription.',

            confirmButtonColor:'#f59e0b'

        });

        </script>

        ";

    } else {

        /* =====================================
           GET PLAN PRICE
        ===================================== */

        $plan = mysqli_fetch_assoc(mysqli_query($conn, "

            SELECT *

            FROM subscription_plans

            WHERE plan_id='$plan_id'

        "));

        if ($billing_cycle == "Monthly") {

            $amount = $plan['monthly_price'];

            $expiry_date = date(
                "Y-m-d",
                strtotime($start_date . " +1 month")
            );

        } else {

            $amount = $plan['yearly_price'];

            $expiry_date = date(
                "Y-m-d",
                strtotime($start_date . " +1 year")
            );

        }

        if ($status == "Trial") {

            $expiry_date = date(
                "Y-m-d",
                strtotime($start_date . " +" . $plan['trial_days'] . " days")
            );

        }

        /* =====================================
           INSERT SUBSCRIPTION
        ===================================== */

        $save = mysqli_query($conn, "

            INSERT INTO company_subscriptions(

            company_id,
            plan_id,
            billing_cycle,
            amount,
            start_date,
            expiry_date,
            renewal_date,
            payment_status,
            status,
            notes

            )

            VALUES(

            '$company_id',
            '$plan_id',
            '$billing_cycle',
            '$amount',
            '$start_date',
            '$expiry_date',
            '$expiry_date',
            '$payment_status',
            '$status',
            '$notes'

            )

        ");

        if ($save) {

            $subscription_id = mysqli_insert_id($conn);

            syncCompanyStatusWithSubscription($conn, $company_id);

            /* =====================================
               HISTORY LOG
            ===================================== */

            mysqli_query($conn, "

                INSERT INTO subscription_history(

                subscription_id,
                action,
                remarks

                )

                VALUES(

                '$subscription_id',
                'Assigned',
                'Subscription assigned by Super Admin.'

                )

            ");

            echo "

                <script>

                Swal.fire({

                icon:'success',
                title:'Subscription Assigned!',
                text:'The company subscription has been created successfully.',
                confirmButtonColor:'#16a34a'

                }).then(()=>{

                window.location='subscriptionManagement.php';

                });

                </script>

                ";

        } else {

            echo "

                <script>

                Swal.fire({

                icon:'error',
                title:'Database Error',
                text:'" . mysqli_error($conn) . "'

                });

                </script>

                ";

        }

    }

}





$totalSubscriptions = mysqli_fetch_assoc(mysqli_query($conn, "
SELECT COUNT(*) total
FROM company_subscriptions
"))['total'];

$activeSubscriptions = mysqli_fetch_assoc(mysqli_query($conn, "
SELECT COUNT(*) total
FROM company_subscriptions
WHERE status='Active'
"))['total'];

$expiredSubscriptions = mysqli_fetch_assoc(mysqli_query($conn, "
SELECT COUNT(*) total
FROM company_subscriptions
WHERE status='Expired'
"))['total'];

$trialSubscriptions = mysqli_fetch_assoc(mysqli_query($conn, "
SELECT COUNT(*) total
FROM company_subscriptions
WHERE status='Trial'
"))['total'];

?>

<!-- ===============================
    PAGE HEADER
================================ -->

<div class="sa-page-head">

    <div>

        <h3 class="sa-page-title">
            Subscription Management
        </h3>

        <p class="sa-page-sub">
            Manage subscription plans and company subscriptions.
        </p>

    </div>

    <?php
    /*
    | No action button here.
    |
    | "Add New Plan" used to sit in the page header, above a page with two
    | tabs. It only applies to one of them, so on the Subscriptions tab it
    | was an offer to do something unrelated to what was on screen -- and it
    | duplicated the Add Plan button already sitting in the Plans panel,
    | worded and coloured differently from it.
    |
    | Each tab now carries its own action in its own panel head, beside the
    | heading that says what the action acts on: Add Plan with the plans,
    | Assign Subscription with the subscriptions.
    */
    ?>

</div>



<!-- ===============================
    DASHBOARD CARDS
================================ -->

<div class="row g-4 mb-4">

    <!-- Total Plans -->

    <div class="col-lg-3 col-md-6">

        <div class="sa-panel">

            <div class="sa-panel-body">

                <div class="d-flex justify-content-between">

                    <div>

                        <small class="text-muted">
                            Total Plans
                        </small>

                        <h2 class="fw-bold mt-2 mb-0">

                            <?php

                            $countPlan = mysqli_query($conn, "SELECT COUNT(*) total FROM subscription_plans");
                            $plan = mysqli_fetch_assoc($countPlan);

                            echo $plan['total'];

                            ?>

                        </h2>

                    </div>

                    <div class="rounded-circle bg-primary bg-opacity-10 d-flex align-items-center justify-content-center"
                        style="width:60px;height:60px;">

                        <i class="bi bi-box text-primary fs-3"></i>

                    </div>

                </div>

            </div>

        </div>

    </div>



    <!-- Active Subscriptions -->

    <div class="col-lg-3 col-md-6">

        <div class="sa-panel">

            <div class="sa-panel-body">

                <div class="d-flex justify-content-between">

                    <div>

                        <small class="text-muted">
                            Active Subscriptions
                        </small>

                        <h2 class="fw-bold mt-2 mb-0">

                            <?php

                            $active = mysqli_query($conn, "
                                SELECT COUNT(*) total
                                FROM company_subscriptions
                                WHERE status='Active'
                            ");

                            $row = mysqli_fetch_assoc($active);

                            echo $row['total'];

                            ?>

                        </h2>

                    </div>

                    <div class="rounded-circle bg-success bg-opacity-10 d-flex align-items-center justify-content-center"
                        style="width:60px;height:60px;">

                        <i class="bi bi-check-circle text-success fs-3"></i>

                    </div>

                </div>

            </div>

        </div>

    </div>



    <!-- Monthly Revenue -->

    <div class="col-lg-3 col-md-6">

        <div class="sa-panel">

            <div class="sa-panel-body">

                <div class="d-flex justify-content-between">

                    <div>

                        <small class="text-muted">
                            Monthly Revenue
                        </small>

                        <h2 class="fw-bold mt-2 mb-0">

                            &#8369;

                            <?php

                            $sales = mysqli_query($conn, "
                                SELECT SUM(sp.monthly_price) total

                                FROM company_subscriptions cs

                                INNER JOIN subscription_plans sp
                                ON sp.plan_id=cs.plan_id

                                WHERE cs.status='Active'
                            ");

                            $rev = mysqli_fetch_assoc($sales);

                            echo number_format($rev['total'] ?? 0, 2);

                            ?>

                        </h2>

                    </div>

                    <div class="rounded-circle bg-warning bg-opacity-10 d-flex align-items-center justify-content-center"
                        style="width:60px;height:60px;">

                        <i class="bi bi-cash-stack text-warning fs-3"></i>

                    </div>

                </div>

            </div>

        </div>

    </div>



    <!-- Expiring Soon -->

    <div class="col-lg-3 col-md-6">

        <div class="sa-panel">

            <div class="sa-panel-body">

                <div class="d-flex justify-content-between">

                    <div>

                        <small class="text-muted">
                            Expiring Soon
                        </small>

                        <h2 class="fw-bold mt-2 mb-0">

                            <?php

                            $expiring = mysqli_query($conn, "
                                SELECT COUNT(*) total

                                FROM company_subscriptions

                                WHERE expiry_date
                                BETWEEN CURDATE()
                                AND DATE_ADD(CURDATE(),INTERVAL 30 DAY)
                            ");

                            $expire = mysqli_fetch_assoc($expiring);

                            echo $expire['total'];

                            ?>

                        </h2>

                    </div>

                    <div class="rounded-circle bg-danger bg-opacity-10 d-flex align-items-center justify-content-center"
                        style="width:60px;height:60px;">

                        <i class="bi bi-clock-history text-danger fs-3"></i>

                    </div>

                </div>

            </div>

        </div>

    </div>

</div>



<!-- ===============================
SEARCH / FILTER
================================ -->

<div class="sa-panel mb-4">

    <div class="sa-panel-body">

        <div class="row g-3 align-items-center">

            <div class="col-lg-5">

                <div class="input-group">

                    <span class="input-group-text bg-white">

                        <i class="bi bi-search"></i>

                    </span>

                    <input type="text" class="form-control" placeholder="Search plan or company...">

                </div>

            </div>

            <div class="col-lg-3">

                <select class="form-select">

                    <option value="">All Status</option>

                    <option>Active</option>

                    <option>Trial</option>

                    <option>Expired</option>

                    <option>Cancelled</option>

                </select>

            </div>

            <div class="col-lg-2">

                <select class="form-select">

                    <option>All Plans</option>

                    <option>Starter</option>

                    <option>Professional</option>

                    <option>Enterprise</option>

                </select>

            </div>

            <div class="col-lg-2">

                <button class="btn btn-outline-secondary w-100">

                    <i class="bi bi-arrow-clockwise me-2"></i>

                    Refresh

                </button>

            </div>

        </div>
    </div>
</div>



<!-- ===============================
TABS
================================ -->

<ul class="nav nav-pills mb-4" id="subscriptionTab">

    <li class="nav-item">

        <button class="nav-link active px-4" data-bs-toggle="tab" data-bs-target="#plans">

            <i class="bi bi-box me-2"></i>

            Subscription Plans

        </button>

    </li>

    <li class="nav-item ms-2">

        <button class="nav-link px-4" data-bs-toggle="tab" data-bs-target="#companies">

            <i class="bi bi-buildings me-2"></i>

            Company Subscriptions

        </button>

    </li>

</ul>



<div class="tab-content">

    <!-- ==================================
            PART 2.2 STARTS HERE
        =================================== -->

    <div class="tab-pane fade show active" id="plans">
        <!-- ==========================================
        SUBSCRIPTION PLANS TABLE
        ========================================== -->

        <div class="sa-panel">

            <?php
            /*
            | The heading and the button are direct children of
            | .sa-panel-head, which is itself a flex row with
            | justify-content: space-between.
            |
            | They used to sit inside a second d-flex wrapper. That wrapper
            | was one flex item in the panel head, so it shrank to the width
            | of its own contents, and its space-between had no free space
            | left to push anything into. The button ended up tucked against
            | the subtitle instead of out at the right edge.
            */
            ?>
            <div class="sa-panel-head">

                <div>

                    <h5 class="sa-name mb-1">
                        Subscription Plans
                    </h5>

                    <small class="text-muted">
                        Manage all subscription plans available to companies.
                    </small>

                </div>

                <?php
                /*
                | sa-btn, the same button the rest of the Super Admin uses.
                | This was btn-success and the one beside the subscriptions
                | table was btn-primary, so the same kind of action wore a
                | different colour on each tab.
                */
                ?>
                <button class="btn sa-btn text-nowrap" data-bs-toggle="modal"
                    data-bs-target="#addPlanModal">

                    <i class="bi bi-plus-circle me-2"></i>

                    Add Plan

                </button>

            </div>

            <div class="p-0">

                <div class="table-responsive">

                    <table class="table table-hover sa-table">

                        <thead class="table-light">

                            <tr>

                                <th width="60">
                                    #
                                </th>

                                <th>
                                    Plan
                                </th>

                                <th>
                                    Monthly
                                </th>

                                <th>
                                    Yearly
                                </th>

                                <th>
                                    Branches
                                </th>

                                <th>
                                    Users
                                </th>

                                <th>
                                    Status
                                </th>

                                <th width="170">
                                    Actions
                                </th>

                            </tr>

                        </thead>

                        <tbody>

                            <?php

                            /*
                            | subscriber_count rides along so the Delete
                            | button can say what is in the way before the
                            | click, rather than after a round trip.
                            */
                            $plans = mysqli_query($conn, "
                        SELECT p.*,
                               (SELECT COUNT(*)
                                  FROM company_subscriptions cs
                                 WHERE cs.plan_id = p.plan_id) AS subscriber_count
                        FROM subscription_plans p
                        ORDER BY p.monthly_price ASC
                    ");

                            $count = 1;

                            while ($row = mysqli_fetch_assoc($plans)) {

                                ?>

                                <tr>

                                    <td>

                                        <?= $count++; ?>

                                    </td>

                                    <td>

                                        <div class="fw-semibold">

                                            <?= $row['plan_name']; ?>

                                        </div>

                                        <small class="text-muted">

                                            <?= $row['description']; ?>

                                        </small>

                                        <?php

                                        if ($row['badge'] != "None") {

                                            ?>

                                            <br>

                                            <span class="badge bg-warning text-dark mt-1">

                                                <?= $row['badge']; ?>

                                            </span>

                                        <?php } ?>

                                    </td>

                                    <td>

                                        <strong>

                                            &#8369;
                                            <?= number_format($row['monthly_price'], 2); ?>

                                        </strong>

                                    </td>

                                    <td>

                                        <?php

                                        if ($row['yearly_price'] > 0) {

                                            echo "&#8369;" . number_format($row['yearly_price'], 2);

                                        } else {

                                            echo "-";

                                        }

                                        ?>

                                    </td>

                                    <td>

                                        <?= $row['max_branches']; ?>

                                    </td>

                                    <td>

                                        <?php

                                        if ($row['max_users'] >= 999999) {

                                            echo "Unlimited";

                                        } else {

                                            echo number_format($row['max_users']);

                                        }

                                        ?>

                                    </td>

                                    <td>

                                        <?php

                                        if ($row['status'] == "Active") {

                                            ?>

                                            <span class="badge bg-success">

                                                Active

                                            </span>

                                            <?php

                                        } elseif ($row['status'] == "Inactive") {

                                            ?>

                                            <span class="badge bg-secondary">

                                                Inactive

                                            </span>

                                            <?php

                                        } else {

                                            ?>

                                            <span class="badge bg-danger">

                                                Archived

                                            </span>

                                        <?php } ?>

                                    </td>

                                    <td>

                                        <div class="btn-group">

                                            <!-- View -->

                                            <button class="btn btn-outline-primary btn-sm viewPlanBtn"
                                                data-bs-toggle="modal" data-bs-target="#viewPlanModal"
                                                data-id="<?= $row['plan_id']; ?>">

                                                <i class="bi bi-eye"></i>

                                            </button>

                                            <!-- Edit -->

                                            <button class="btn btn-outline-warning btn-sm editPlanBtn"
                                                data-bs-toggle="modal" data-bs-target="#editPlanModal"
                                                data-id="<?= $row['plan_id']; ?>">

                                                <i class="bi bi-pencil-square"></i>

                                            </button>

                                            <?php
                                            /*
                                            | Archive and Delete carried no
                                            | name, no form and no listener:
                                            | clicking either did nothing at
                                            | all. Both are wired now, and
                                            | both ask before acting.
                                            */
                                            $isActive = $row['status'] === 'Active';
                                            ?>

                                            <!-- Archive / Restore -->

                                            <button type="button"
                                                class="btn btn-outline-secondary btn-sm togglePlanBtn"
                                                data-id="<?= (int) $row['plan_id']; ?>"
                                                data-name="<?= htmlspecialchars($row['plan_name']); ?>"
                                                data-active="<?= $isActive ? '1' : '0'; ?>"
                                                title="<?= $isActive ? 'Archive this plan' : 'Restore this plan'; ?>">

                                                <i class="bi bi-<?= $isActive ? 'archive' : 'arrow-counterclockwise'; ?>"></i>

                                            </button>

                                            <!-- Delete -->

                                            <button type="button"
                                                class="btn btn-outline-danger btn-sm deletePlanBtn"
                                                data-id="<?= (int) $row['plan_id']; ?>"
                                                data-name="<?= htmlspecialchars($row['plan_name']); ?>"
                                                data-subs="<?= (int) ($row['subscriber_count'] ?? 0); ?>"
                                                title="Delete this plan">

                                                <i class="bi bi-trash"></i>

                                            </button>

                                        </div>

                                    </td>

                                </tr>

                            <?php } ?>

                        </tbody>

                    </table>

                </div>

            </div>

        </div>
    </div>

    <!-- ==========================================
        COMPANY SUBSCRIPTIONS
        ========================================== -->

    <div class="tab-pane fade" id="companies">

        <!-- ==========================================
        COMPANY SUBSCRIPTIONS
        ========================================== -->

        <div class="sa-panel">

            <?php /* Same flattening as the plans panel above. */ ?>
            <div class="sa-panel-head">

                <div>

                    <h5 class="sa-name mb-1">
                        Company Subscriptions
                    </h5>

                    <small class="text-muted">
                        Manage all company subscriptions and renewals.
                    </small>

                </div>

                <button class="btn sa-btn text-nowrap" data-bs-toggle="modal"
                    data-bs-target="#assignSubscriptionModal">

                    <i class="bi bi-plus-circle me-2"></i>

                    Assign Subscription

                </button>

            </div>

            <div class="sa-panel-body">

                <div class="row mb-4">

                    <div class="col-lg-4">

                        <div class="input-group">

                            <span class="input-group-text bg-white">
                                <i class="bi bi-search"></i>
                            </span>

                            <input type="text" class="form-control" placeholder="Search company...">

                        </div>

                    </div>

                    <div class="col-lg-3">

                        <select class="form-select">

                            <option value="">All Status</option>
                            <option>Trial</option>
                            <option>Active</option>
                            <option>Expired</option>
                            <option>Cancelled</option>

                        </select>

                    </div>

                    <div class="col-lg-3">

                        <select class="form-select">

                            <option value="">All Plans</option>

                            <?php

                            $planList = mysqli_query($conn, "
                        SELECT *
                        FROM subscription_plans
                        WHERE status='Active'
                        ORDER BY plan_name
                    ");

                            while ($plan = mysqli_fetch_assoc($planList)) {

                                ?>

                                <option>

                                    <?= $plan['plan_name']; ?>

                                </option>

                            <?php } ?>

                        </select>

                    </div>

                    <div class="col-lg-2">

                        <button class="btn btn-outline-secondary w-100">

                            <i class="bi bi-arrow-clockwise"></i>

                            Refresh

                        </button>

                    </div>

                </div>

                <div class="table-responsive">

                    <table class="table table-hover sa-table">

                        <thead class="table-light">

                            <tr>

                                <th>Company</th>

                                <th>Subscription</th>

                                <th>Start Date</th>

                                <th>Expiry Date</th>

                                <th>Payment</th>

                                <th>Status</th>

                                <th width="220">
                                    Actions
                                </th>

                            </tr>

                        </thead>

                        <tbody>

                            <?php

                            $subscription = mysqli_query($conn, "

                               SELECT

                                cs.*,

                                c.company_name,

                                sp.plan_name

                                FROM company_subscriptions cs

                                INNER JOIN company c
                                ON c.company_id = cs.company_id

                                INNER JOIN subscription_plans sp
                                ON sp.plan_id = cs.plan_id

                                ORDER BY cs.subscription_id DESC;

                            ");

                            while ($row = mysqli_fetch_assoc($subscription)) {

                                ?>

                                <tr>

                                    <td>

                                        <div class="fw-semibold">

                                            <?= $row['company_name']; ?>

                                        </div>

                                    </td>

                                    <td>

                                        <span class="badge bg-primary">

                                            <?= $row['plan_name']; ?>

                                        </span>

                                    </td>

                                    <td>

                                        <?= date('M d, Y', strtotime($row['start_date'])); ?>

                                    </td>

                                    <td>

                                        <?php

                                        $today = strtotime(date("Y-m-d"));
                                        $expiry = strtotime($row['expiry_date']);

                                        echo date("M d, Y", $expiry);

                                        $daysLeft = floor(($expiry - $today) / 86400);

                                        if ($row['status'] == "Active") {

                                            if ($daysLeft <= 7 && $daysLeft >= 0) {

                                                echo "<br>";

                                                echo "<small class='text-warning fw-semibold'>";

                                                echo "Expires in " . $daysLeft . " day(s)";

                                                echo "</small>";

                                            }

                                        }

                                        ?>

                                    </td>

                                    <td>

                                        <?php

                                        switch ($row['payment_status']) {

                                            case 'Paid':

                                                echo '<span class="badge bg-success">Paid</span>';

                                                break;

                                            case 'Pending':

                                                echo '<span class="badge bg-warning text-dark">Pending</span>';

                                                break;

                                            default:

                                                echo '<span class="badge bg-danger">Failed</span>';

                                        }

                                        ?>

                                    </td>

                                    <td>

                                        <?php

                                        $statusClass = "";

                                        switch ($row['status']) {

                                            case "Active":
                                                $statusClass = "bg-success";
                                                break;

                                            case "Trial":
                                                $statusClass = "bg-info text-dark";
                                                break;

                                            case "Expired":
                                                $statusClass = "bg-danger";
                                                break;

                                            case "Cancelled":
                                                $statusClass = "bg-secondary";
                                                break;

                                            default:
                                                $statusClass = "bg-dark";

                                        }

                                        ?>

                                        <span class="badge <?= $statusClass; ?>">

                                            <?= $row['status']; ?>

                                        </span>

                                    </td>

                                    <td>

                                        <div class="btn-group">

                                            <!-- View -->

                                            <!-- View Subscription -->

                                            <button type="button" class="btn btn-outline-primary btn-sm viewSubscriptionBtn"
                                                data-id="<?= $row['subscription_id']; ?>" data-bs-toggle="modal"
                                                data-bs-target="#viewSubscriptionModal" title="View Subscription">

                                                <i class="bi bi-eye"></i>

                                            </button>

                                            <!-- Upgrade -->

                                            <!-- Upgrade / Downgrade -->

                                            <button type="button" class="btn btn-outline-success btn-sm changePlanBtn"
                                                data-bs-toggle="modal" data-bs-target="#changePlanModal"
                                                data-id="<?= $row['subscription_id']; ?>"
                                                data-company="<?= htmlspecialchars($row['company_name']); ?>"
                                                data-plan="<?= $row['plan_id']; ?>"
                                                data-planname="<?= htmlspecialchars($row['plan_name']); ?>"
                                                data-billing="<?= $row['billing_cycle']; ?>"
                                                title="Upgrade / Downgrade Subscription">

                                                <i class="bi bi-arrow-up-circle"></i>

                                            </button>

                                            <!-- Edit -->
                                            <button class="btn btn-outline-warning btn-sm editSubscription"
                                                data-bs-toggle="modal" data-bs-target="#editSubscriptionModal"
                                                data-id="<?= $row['subscription_id']; ?>"
                                                data-company="<?= $row['company_id']; ?>"
                                                data-plan="<?= $row['plan_id']; ?>"
                                                data-billing="<?= $row['billing_cycle']; ?>"
                                                data-start="<?= $row['start_date']; ?>"
                                                data-payment="<?= $row['payment_status']; ?>"
                                                data-status="<?= $row['status']; ?>"
                                                data-notes="<?= htmlspecialchars($row['notes']); ?>"
                                                title="Edit Subscription">
                                                <i class="bi bi-pencil-square"></i>
                                            </button>

                                            <!-- Renew Subscription -->

                                            <?php if ($row['status'] != "Cancelled") { ?>

                                                <button type="button"
                                                    class="btn btn-outline-warning btn-sm renewSubscriptionBtn"
                                                    data-id="<?= $row['subscription_id']; ?>"
                                                    data-company="<?= htmlspecialchars($row['company_name']); ?>"
                                                    data-plan="<?= htmlspecialchars($row['plan_name']); ?>"
                                                    data-cycle="<?= $row['billing_cycle']; ?>" title="Renew Subscription">

                                                    <i class="bi bi-arrow-repeat"></i>

                                                </button>

                                            <?php } else { ?>

                                                <button class="btn btn-outline-secondary btn-sm" disabled
                                                    title="Cancelled Subscription">

                                                    <i class="bi bi-arrow-repeat"></i>

                                                </button>

                                            <?php } ?>

                                            <!-- Suspend -->
                                            <!-- Cancel Subscription -->

                                            <?php if ($row['status'] == "Cancelled") { ?>

                                                <button class="btn btn-outline-secondary btn-sm" disabled
                                                    title="Already Cancelled">

                                                    <i class="bi bi-pause-circle"></i>

                                                </button>

                                            <?php } else { ?>

                                                <button type="button"
                                                    class="btn btn-outline-danger btn-sm cancelSubscriptionBtn"
                                                    data-id="<?= $row['subscription_id']; ?>"
                                                    data-company="<?= htmlspecialchars($row['company_name']); ?>"
                                                    title="Cancel Subscription">

                                                    <i class="bi bi-pause-circle"></i>

                                                </button>

                                            <?php } ?>

                                        </div>

                                    </td>

                                </tr>

                            <?php } ?>

                        </tbody>

                    </table>

                </div>

            </div>

        </div>

    </div>

</div>

<!--
    These two carry no fields of their own: the Cancel and Renew buttons in
    the table fill the id in and post. Both already ask first, naming the
    company and the plan, so they opt out of the shared guard rather than
    confirming twice.
-->

<form id="cancelSubscriptionForm" method="POST" data-sa-skip>

    <input type="hidden" name="subscription_id" id="cancel_subscription_id">

    <input type="hidden" name="cancelSubscription" value="1">

</form>

<form id="renewSubscriptionForm" method="POST" data-sa-skip>

    <input type="hidden" name="subscription_id" id="renew_subscription_id">

    <input type="hidden" name="renewSubscription" value="1">

</form>

<!--
    The same arrangement for the two plan actions that used to do nothing.
    Both ask first, naming the plan and saying what the action means, so
    they opt out of the shared guard rather than confirming twice.
-->

<form id="togglePlanForm" method="POST" data-sa-skip>

    <input type="hidden" name="plan_id" id="toggle_plan_id">

    <input type="hidden" name="togglePlanStatus" value="1">

</form>

<form id="deletePlanForm" method="POST" data-sa-skip>

    <input type="hidden" name="plan_id" id="delete_plan_id">

    <input type="hidden" name="deletePlan" value="1">

</form>

<script>
    document.addEventListener("DOMContentLoaded", function () {
        /* =========================================
           REMEMBER ACTIVE TAB
        ========================================== */
        const triggerTabList = document.querySelectorAll(
            '#subscriptionTab button[data-bs-toggle="tab"]'
        );
        triggerTabList.forEach(function (trigger) {
            trigger.addEventListener("shown.bs.tab", function (event) {
                localStorage.setItem(
                    "subscriptionActiveTab",
                    event.target.getAttribute("data-bs-target")
                );
            });
        });

        const activeTab = localStorage.getItem("subscriptionActiveTab");

        if (activeTab) {
            const tab = document.querySelector(
                '#subscriptionTab button[data-bs-target="' + activeTab + '"]'
            );
            if (tab) {
                bootstrap.Tab.getOrCreateInstance(tab).show();
            }
        }

        /* =========================================
           SEARCH TABLE
        ========================================== */

        const search = document.querySelector('input[placeholder="Search plan or company..."]');

        if (search) {

            search.addEventListener("keyup", function () {

                const value = this.value.toLowerCase();

                document.querySelectorAll("tbody tr").forEach(function (row) {

                    row.style.display =
                        row.innerText.toLowerCase().includes(value)
                            ? ""
                            : "none";

                });

            });

        }


        /* =========================================
           REFRESH BUTTON
        ========================================== */

        document.querySelectorAll(".btn-outline-secondary").forEach(function (btn) {

            if (btn.innerText.includes("Refresh")) {

                btn.addEventListener("click", function () {

                    location.reload();

                });

            }

        });


        /* =========================================
           TOOLTIP
        ========================================== */

        const tooltipTriggerList = [].slice.call(
            document.querySelectorAll('[title]')
        );

        tooltipTriggerList.map(function (tooltipTriggerEl) {

            return new bootstrap.Tooltip(tooltipTriggerEl);

        });

    });
</script>

<script>
    $(document).ready(function () {

        $(".editPlanBtn").click(function () {

            let plan_id = $(this).data("id");

            $.ajax({

                url: "subscriptionManagement.php",

                type: "POST",

                dataType: "json",

                data: {

                    loadPlan: true,
                    plan_id: plan_id

                },

                success: function (response) {

                    /* ===============================
                        PLAN DETAILS
                    =============================== */

                    $("#edit_plan_id").val(response.plan.plan_id);

                    $("#edit_plan_name").val(response.plan.plan_name);

                    $("#edit_description").val(response.plan.description);

                    $("#edit_monthly_price").val(response.plan.monthly_price);

                    $("#edit_yearly_price").val(response.plan.yearly_price);

                    $("#edit_max_branches").val(response.plan.max_branches);

                    $("#edit_max_users").val(response.plan.max_users);

                    $("#edit_trial_days").val(response.plan.trial_days);

                    $("#edit_badge").val(response.plan.badge);

                    $("#edit_status").val(response.plan.status);


                    /* ===============================
                        FEATURES
                    =============================== */

                    let allFeatures = [

                        "Point of Sale",

                        "Inventory",

                        "Products",

                        "Purchasing",

                        "Suppliers",

                        "Expenses",

                        "Dashboard",

                        "Reports",

                        "Analytics",

                        "HR Management",

                        "Payroll",

                        "Attendance",

                        "Recruitment",

                        "Marketplace",

                        "Customer Loyalty",

                        "Promotions",

                        "API Access",

                        "White Label",

                        "Priority Support",

                        "Dedicated Account Manager",

                        "Custom Integrations",

                        "Unlimited Storage",

                        "SMS Notification"

                    ];

                    let html = "";

                    allFeatures.forEach(function (feature) {

                        let checked = "";

                        if (response.features.includes(feature)) {

                            checked = "checked";

                        }

                        html += `
                        <div class="form-check mb-2">

                            <input
                                class="form-check-input"
                                type="checkbox"
                                name="features[]"
                                value="${feature}"
                                ${checked}>

                            <label class="form-check-label">

                                ${feature}

                            </label>

                        </div>
                    `;

                    });

                    $("#editFeatureList").html(html);

                }

            });

        });

    });
</script>

<script>
    $(document).ready(function () {

        $(".viewPlanBtn").click(function () {

            let plan_id = $(this).data("id");

            $.ajax({

                url: "subscriptionManagement.php",

                type: "POST",

                dataType: "json",

                data: {

                    viewPlan: true,
                    plan_id: plan_id

                },

                success: function (response) {

                    if (!response.success) {

                        Swal.fire({
                            icon: 'error',
                            title: 'Oops...',
                            text: response.message,
                            confirmButtonColor: '#0d6efd'
                        });

                        return;

                    }

                    /* ===============================
                       PLAN DETAILS
                    =============================== */

                    $("#view_plan_name").text(response.plan.plan_name);

                    $("#view_description").text(response.plan.description);

                    $("#view_monthly_price").text(response.plan.monthly_price);

                    $("#view_yearly_price").text(response.plan.yearly_price);

                    $("#view_max_branches").text(response.plan.max_branches);

                    if (response.plan.max_users >= 999999) {

                        $("#view_max_users").text("Unlimited");

                    } else {

                        $("#view_max_users").text(response.plan.max_users);

                    }

                    $("#view_trial_days").text(response.plan.trial_days + " Days");

                    $("#view_badge")
                        .removeClass("bg-warning bg-primary bg-success bg-secondary")
                        .addClass("bg-warning")
                        .text(response.plan.badge);

                    /* ===============================
                       STATUS BADGE
                    =============================== */

                    let badgeClass = "bg-secondary";

                    if (response.plan.status === "Active") {

                        badgeClass = "bg-success";

                    } else if (response.plan.status === "Inactive") {

                        badgeClass = "bg-secondary";

                    } else if (response.plan.status === "Archived") {

                        badgeClass = "bg-danger";

                    }

                    $("#view_status")
                        .removeClass("bg-success bg-secondary bg-danger")
                        .addClass(badgeClass)
                        .text(response.plan.status);

                    /* ===============================
                       FEATURES
                    =============================== */

                    let html = "";

                    if (response.features.length > 0) {

                        response.features.forEach(function (feature) {

                            html += `
                            <div class="d-flex align-items-center mb-2">

                                <i class="bi bi-check-circle-fill text-success me-2"></i>

                                <span>${feature}</span>

                            </div>
                        `;

                        });

                    } else {

                        html = `
                        <div class="text-muted">

                            No features assigned.

                        </div>
                    `;

                    }

                    $("#viewFeatureList").html(html);

                },

                error: function () {

                    Swal.fire({
                        icon: 'error',
                        title: 'Server Error',
                        text: 'Unable to load subscription plan.',
                        confirmButtonColor: '#dc3545'
                    });

                }

            });

        });

    });
</script>

<script>
    $(document).ready(function () {

        $(".editSubscription").on("click", function () {

            $("#edit_subscription_id").val($(this).data("id"));
            $("#edit_company_id").val($(this).data("company"));
            $("#edit_sub_plan_id").val($(this).data("plan"));
            $("#edit_billing_cycle").val($(this).data("billing"));
            $("#edit_start_date").val($(this).data("start"));
            $("#edit_payment_status").val($(this).data("payment"));
            $("#edit_sub_status").val($(this).data("status"));
            $("#edit_notes").val($(this).data("notes"));

            console.log("Subscription ID:", $(this).data("id"));

        });

    });
</script>

<script>

    $(document).ready(function () {

        $(".cancelSubscriptionBtn").click(function () {

            let subscriptionID = $(this).data("id");
            let company = $(this).data("company");

            Swal.fire({

                icon: "warning",

                title: "Cancel Subscription?",

                html:
                    "You are about to cancel the subscription for<br><strong>" +
                    company +
                    "</strong>.<br><br>This action can be reversed later by editing the subscription.",

                showCancelButton: true,

                confirmButtonText: "Yes, Cancel",

                cancelButtonText: "No",

                confirmButtonColor: "#dc3545",

                cancelButtonColor: "#6c757d"

            }).then((result) => {

                if (result.isConfirmed) {

                    $("#cancel_subscription_id").val(subscriptionID);

                    $("#cancelSubscriptionForm").submit();

                }

            });

        });

    });

</script>

<script>

    $(document).ready(function () {

        $(".renewSubscriptionBtn").click(function () {

            let id = $(this).data("id");
            let company = $(this).data("company");
            let plan = $(this).data("plan");
            let cycle = $(this).data("cycle");

            Swal.fire({

                icon: "question",

                title: "Renew Subscription?",

                html:
                    "<strong>" + company + "</strong><br><br>" +
                    "Plan: <strong>" + plan + "</strong><br>" +
                    "Billing Cycle: <strong>" + cycle + "</strong><br><br>" +
                    "This will extend the subscription based on its billing cycle.",

                showCancelButton: true,

                confirmButtonText: "Renew",

                cancelButtonText: "Cancel",

                confirmButtonColor: "#f59e0b",

                cancelButtonColor: "#6c757d"

            }).then((result) => {

                if (result.isConfirmed) {

                    $("#renew_subscription_id").val(id);

                    $("#renewSubscriptionForm").submit();

                }

            });

        });

    });

</script>

<script>

    /* =========================================
       ARCHIVE / RESTORE A PLAN

       Archiving takes a plan off the public pricing page. It does not touch
       the companies already on it, and the dialog says so -- "archive"
       reads like a delete to most people.
    ========================================== */

    $(document).ready(function () {

        $(".togglePlanBtn").click(function () {

            let id = $(this).data("id");
            let name = $(this).data("name");
            let isActive = String($(this).data("active")) === "1";

            Swal.fire({

                icon: "question",

                title: isActive ? "Archive this plan?" : "Restore this plan?",

                html: isActive
                    ? "<strong>" + name + "</strong><br><br>" +
                      "It stops appearing on the pricing page, so nobody new can choose it.<br>" +
                      "Companies already on it keep it and are billed no differently."
                    : "<strong>" + name + "</strong><br><br>" +
                      "It goes back on the pricing page and can be chosen again.",

                showCancelButton: true,

                confirmButtonText: isActive ? "Yes, archive" : "Yes, restore",

                cancelButtonText: "No",

                confirmButtonColor: isActive ? "#6c757d" : "#198754",

                cancelButtonColor: "#6c757d",

                reverseButtons: true

            }).then((result) => {

                if (result.isConfirmed) {

                    $("#toggle_plan_id").val(id);

                    $("#togglePlanForm").submit();

                }

            });

        });

    });

</script>

<script>

    /* =========================================
       DELETE A PLAN

       The in-use check happens here and again on the server. This half only
       spares the round trip and names what is in the way; the server is
       what actually refuses, because a subscription can be created between
       this page rendering and the button being clicked.
    ========================================== */

    $(document).ready(function () {

        $(".deletePlanBtn").click(function () {

            let id = $(this).data("id");
            let name = $(this).data("name");
            let subs = parseInt($(this).data("subs"), 10) || 0;

            if (subs > 0) {

                Swal.fire({

                    icon: "warning",

                    title: "Plan in use",

                    html:
                        "<strong>" + name + "</strong> is on " + subs +
                        " subscription" + (subs === 1 ? "" : "s") + ".<br><br>" +
                        "Move those companies to another plan first, or archive " +
                        "this one so it stops being offered.",

                    confirmButtonText: "OK",

                    confirmButtonColor: "#00224c"

                });

                return;
            }

            Swal.fire({

                icon: "warning",

                title: "Delete this plan?",

                html:
                    "<strong>" + name + "</strong><br><br>" +
                    "Its features and roles go with it. This cannot be undone.<br><br>" +
                    "If you only want it off the pricing page, archive it instead.",

                showCancelButton: true,

                confirmButtonText: "Yes, delete",

                cancelButtonText: "Cancel",

                confirmButtonColor: "#dc3545",

                cancelButtonColor: "#6c757d",

                reverseButtons: true

            }).then((result) => {

                if (result.isConfirmed) {

                    $("#delete_plan_id").val(id);

                    $("#deletePlanForm").submit();

                }

            });

        });

    });

</script>

<script>

    $(document).ready(function () {

        $(".changePlanBtn").click(function () {

            let id = $(this).data("id");
            let company = $(this).data("company");
            let plan = $(this).data("plan");
            let planName = $(this).data("planname");
            let billing = $(this).data("billing");

            $("#change_subscription_id").val(id);

            $("#change_company_name").val(company);

            $("#change_billing_cycle").val(billing);

            $("#current_plan").val(planName);

            $("#change_plan_id").val(plan);

        });

    });

    $("#change_plan_id").change(function () {

        let plan = $(this).val();

        $.ajax({

            url: "subscriptionManagement.php",

            type: "POST",

            dataType: "json",

            data: {
                loadPlan: true,
                plan_id: plan
            },

            success: function (response) {

                let p = response.plan;

                let featureHTML = "";

                response.features.forEach(function (item) {

                    featureHTML += `
                    <div>
                        <i class="bi bi-check-circle-fill text-success me-2"></i>
                        ${item}
                    </div>
                `;

                });

                $("#new_price").text("\u20B1" + Number(p.monthly_price).toLocaleString());

                $("#new_branches").text(p.max_branches);

                $("#new_users").text(p.max_users);

                $("#new_trial").text(p.trial_days + " Days");

                $("#new_features").html(featureHTML);

            }

        });

    });

</script>

<script>

    $(document).ready(function () {

        $(".viewSubscriptionBtn").click(function () {

            let subscription_id = $(this).data("id");

            $.ajax({

                url: "subscriptionManagement.php",

                type: "POST",

                dataType: "json",

                data: {

                    viewSubscription: true,
                    subscription_id: subscription_id

                },

                success: function (response) {

                    if (!response.success) {

                        Swal.fire({

                            icon: "error",
                            title: "Not Found",
                            text: response.message,
                            confirmButtonColor: "#dc3545"

                        });

                        return;

                    }

                    let s = response.subscription;

                    /* ===========================
                       COMPANY
                    =========================== */

                    $("#view_company_name").text(s.company_name);

                    $("#view_company_email").text(s.email);

                    $("#view_company_phone").text(s.contact_number);

                    $("#view_company_address").text(s.address);

                    /* ===========================
                       STATUS BADGE
                    =========================== */

                    let badge = "";

                    switch (s.status) {

                        case "Active":
                            badge = '<span class="badge bg-success">Active</span>';
                            break;

                        case "Trial":
                            badge = '<span class="badge bg-info text-dark">Trial</span>';
                            break;

                        case "Expired":
                            badge = '<span class="badge bg-danger">Expired</span>';
                            break;

                        case "Cancelled":
                            badge = '<span class="badge bg-secondary">Cancelled</span>';
                            break;

                        default:
                            badge = '<span class="badge bg-dark">' + s.status + '</span>';

                    }

                    $("#view_company_status").html(badge);

                    $("#view_sub_status").html(badge);

                    /* ===========================
                       SUBSCRIPTION
                    =========================== */

                    $("#view_plan").text(s.plan_name);

                    $("#view_billing").text(s.billing_cycle);

                    $("#view_amount").text("\u20B1" + Number(s.amount).toLocaleString());

                    $("#view_start").text(s.start_date);

                    $("#view_expiry").text(s.expiry_date);

                    /* ===========================
                       FEATURES
                    =========================== */

                    let featureHTML = "";

                    if (response.features.length > 0) {

                        response.features.forEach(function (feature) {

                            featureHTML += `
                            <div class="mb-2">

                                <i class="bi bi-check-circle-fill text-success me-2"></i>

                                ${feature}

                            </div>
                        `;

                        });

                    } else {

                        featureHTML = `
                        <div class="text-muted">

                            No features assigned.

                        </div>
                    `;

                    }

                    $("#view_features").html(featureHTML);

                    /* ===========================
                       HISTORY
                    =========================== */

                    let historyHTML = "";

                    if (response.history.length > 0) {

                        response.history.forEach(function (item) {

                            historyHTML += `

                        <div class="border-start border-3 border-primary ps-3 mb-3">

                            <div class="fw-bold">

                                ${item.action}

                            </div>

                            <div>

                                ${item.remarks}

                            </div>

                            <small class="text-muted">

                                ${item.created_at}

                            </small>

                        </div>

                        `;

                        });

                    } else {

                        historyHTML = `

                        <div class="text-muted">

                            No subscription history found.

                        </div>

                    `;

                    }

                    $("#view_history").html(historyHTML);

                },

                error: function () {

                    Swal.fire({

                        icon: "error",

                        title: "Server Error",

                        text: "Unable to load subscription details.",

                        confirmButtonColor: "#dc3545"

                    });

                }

            });

        });

    });

</script>

<?php include("sAdminFooter.php"); ?>