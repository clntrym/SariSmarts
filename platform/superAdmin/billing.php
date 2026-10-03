<?php

require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../includes/platform_roles.php';
require_once __DIR__ . '/../includes/audit.php';
requirePlatformAccess('billing');


/*
|--------------------------------------------------------------------------
| BILLING
|--------------------------------------------------------------------------
|
| A money view over the subscriptions that Subscription Management already
| owns. It adds no table of its own: every figure here comes from
| company_subscriptions, subscription_plans, company, subscription_history
| and paymongo_sessions.
|
| The split is deliberate. Subscription Management decides what a company
| is entitled to; this screen only answers "have they paid, and when".
| The one thing it writes is the payment state, with a note in the
| subscription history so the change is always traceable.
|
*/

$alert = null;

$PAYMENT_STATES = ['Pending', 'Paid', 'Failed'];


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['updatePayment'])) {

    $subscriptionId = (int) ($_POST['subscription_id'] ?? 0);
    $paymentStatus = $_POST['payment_status'] ?? '';
    $remarks = trim($_POST['remarks'] ?? '');

    $row = null;

    if ($subscriptionId > 0) {
        $stmt = $conn->prepare("
            SELECT cs.subscription_id, cs.company_id, cs.payment_status, c.company_name
            FROM company_subscriptions cs
            INNER JOIN company c ON c.company_id = cs.company_id
            WHERE cs.subscription_id = ?
            LIMIT 1
        ");
        $stmt->bind_param("i", $subscriptionId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }

    if (!$row) {

        $alert = ['icon' => 'error', 'title' => 'Not Found', 'text' => 'That billing record no longer exists.'];

    } elseif (!in_array($paymentStatus, $PAYMENT_STATES, true)) {

        $alert = ['icon' => 'error', 'title' => 'Missing Details', 'text' => 'Please choose a payment status.'];

    } elseif ($paymentStatus === $row['payment_status']) {

        $alert = ['icon' => 'info', 'title' => 'Nothing Changed',
                  'text' => $row['company_name'] . ' is already marked ' . $paymentStatus . '.'];

    } elseif ($remarks === '') {

        /*
        | Money moving is the one thing an operator should never be able to
        | change silently, so the note is not optional.
        */
        $alert = ['icon' => 'error', 'title' => 'Reason Required',
                  'text' => 'Please say why the payment status is changing.'];

    } else {

        $conn->begin_transaction();

        try {
            $stmt = $conn->prepare("UPDATE company_subscriptions SET payment_status = ? WHERE subscription_id = ?");
            $stmt->bind_param("si", $paymentStatus, $subscriptionId);
            $stmt->execute();
            $stmt->close();

            $action = 'Payment marked ' . $paymentStatus;
            $note = $remarks . ' (was ' . $row['payment_status'] . ')';
            $actor = (int) ($_SESSION['user_id'] ?? 0);

            $stmt = $conn->prepare("
                INSERT INTO subscription_history (subscription_id, action, remarks, created_by)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->bind_param("issi", $subscriptionId, $action, $note, $actor);
            $stmt->execute();
            $stmt->close();

            $conn->commit();

            auditLog($conn, 'Payment marked ' . $paymentStatus, 'billing', $subscriptionId,
                     $row['company_name'] . ': ' . $row['payment_status'] . ' to ' . $paymentStatus . ' - ' . $remarks);

            $alert = ['icon' => 'success', 'title' => 'Payment Updated',
                      'text' => $row['company_name'] . ' is now marked ' . $paymentStatus . '.'];

        } catch (Throwable $e) {
            $conn->rollback();
            $alert = ['icon' => 'error', 'title' => 'Update Failed', 'text' => $e->getMessage()];
        }
    }
}


include("sAdminHeader.php");


$records = [];

$result = $conn->query("
    SELECT
        cs.subscription_id, cs.company_id, cs.billing_cycle, cs.amount,
        cs.start_date, cs.expiry_date, cs.renewal_date,
        cs.payment_status, cs.status, cs.notes, cs.created_at,
        c.company_name, c.company_code, c.email,
        sp.plan_name
    FROM company_subscriptions cs
    INNER JOIN company c ON c.company_id = cs.company_id
    INNER JOIN subscription_plans sp ON sp.plan_id = cs.plan_id
    ORDER BY cs.created_at DESC, cs.subscription_id DESC
");

while ($record = $result->fetch_assoc()) {
    $record['history'] = [];
    $records[$record['subscription_id']] = $record;
}

/* One pass for the whole history instead of a query per row. */
if (count($records) > 0) {

    $history = $conn->query("
        SELECT sh.subscription_id, sh.action, sh.remarks, sh.created_at, u.fullname
        FROM subscription_history sh
        LEFT JOIN users u ON u.user_id = sh.created_by
        ORDER BY sh.created_at DESC, sh.history_id DESC
    ");

    while ($entry = $history->fetch_assoc()) {
        $id = $entry['subscription_id'];
        if (isset($records[$id]) && count($records[$id]['history']) < 8) {
            $records[$id]['history'][] = $entry;
        }
    }
}

$figures = $conn->query("
    SELECT
        COALESCE(SUM(payment_status = 'Paid'), 0)    AS paid_count,
        COALESCE(SUM(payment_status = 'Pending'), 0) AS pending_count,
        COALESCE(SUM(payment_status = 'Failed'), 0)  AS failed_count,
        COALESCE(SUM(CASE WHEN payment_status = 'Paid' THEN amount END), 0)    AS collected,
        COALESCE(SUM(CASE WHEN payment_status <> 'Paid' THEN amount END), 0)   AS outstanding
    FROM company_subscriptions
")->fetch_assoc();

$onlineCheckouts = (int) $conn->query("
    SELECT COUNT(*) n FROM paymongo_sessions WHERE consumed_at IS NOT NULL
")->fetch_assoc()['n'];

?>

<div class="sa-page-head">
    <div>
        <h3 class="sa-page-title">Billing</h3>
        <p class="sa-page-sub">
            What each company owes and whether it has been settled.
        </p>
    </div>
    <a href="<?= $BASE_URL ?>/superAdmin/subscriptionManagement.php" class="btn sa-btn-soft">
        <i class="bi bi-credit-card me-1"></i> Subscription Management
    </a>
</div>


<div class="row g-3 mb-4">

    <div class="col-md-3">
        <div class="sa-stat p-3 d-flex justify-content-between align-items-center">
            <div>
                <div class="sa-stat-label">Collected</div>
                <div class="sa-stat-value">&#8369;<?= number_format((float) $figures['collected'], 2) ?></div>
                <div class="sa-muted"><?= number_format((int) $figures['paid_count']) ?> settled</div>
            </div>
            <div class="sa-stat-icon sa-tone-ok"><i class="bi bi-cash-stack"></i></div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="sa-stat p-3 d-flex justify-content-between align-items-center">
            <div>
                <div class="sa-stat-label">Outstanding</div>
                <div class="sa-stat-value">&#8369;<?= number_format((float) $figures['outstanding'], 2) ?></div>
                <div class="sa-muted"><?= number_format((int) $figures['pending_count']) ?> awaiting payment</div>
            </div>
            <div class="sa-stat-icon sa-tone-warn"><i class="bi bi-hourglass-split"></i></div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="sa-stat p-3 d-flex justify-content-between align-items-center">
            <div>
                <div class="sa-stat-label">Failed Payments</div>
                <div class="sa-stat-value"><?= number_format((int) $figures['failed_count']) ?></div>
                <div class="sa-muted">Need following up</div>
            </div>
            <div class="sa-stat-icon sa-tone-bad"><i class="bi bi-x-octagon"></i></div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="sa-stat p-3 d-flex justify-content-between align-items-center">
            <div>
                <div class="sa-stat-label">Online Checkouts</div>
                <div class="sa-stat-value"><?= number_format($onlineCheckouts) ?></div>
                <div class="sa-muted">Completed through PayMongo</div>
            </div>
            <div class="sa-stat-icon sa-tone-accent"><i class="bi bi-globe"></i></div>
        </div>
    </div>

</div>


<div class="sa-panel">

    <div class="sa-panel-head">
        <span>Billing Records</span>
        <div class="d-flex align-items-center gap-2">
            <label class="sa-muted mb-0" for="paymentFilter">Payment</label>
            <select id="paymentFilter" class="form-select form-select-sm" style="width:auto;" data-sa-skip>
                <option value="">All</option>
                <?php foreach ($PAYMENT_STATES as $state): ?>
                    <option value="<?= htmlspecialchars($state) ?>"><?= htmlspecialchars($state) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <div class="table-responsive">

        <table id="billingTable" class="table table-hover sa-table" style="width:100%">

            <thead>
                <tr>
                    <th class="ps-4" style="width:120px;">Reference</th>
                    <th style="width:220px;">Company</th>
                    <th style="width:170px;">Plan</th>
                    <th style="width:140px;">Amount</th>
                    <th style="width:190px;">Period</th>
                    <th style="width:110px;">Payment</th>
                    <th class="pe-4" style="width:120px;">Action</th>
                </tr>
            </thead>

            <tbody>

                <?php foreach ($records as $record): ?>

                    <?php
                    $reference = 'SUB-' . str_pad((string) $record['subscription_id'], 6, '0', STR_PAD_LEFT);

                    $paymentBadge = 'bg-secondary';
                    if ($record['payment_status'] === 'Paid') {
                        $paymentBadge = 'bg-success';
                    } elseif ($record['payment_status'] === 'Pending') {
                        $paymentBadge = 'bg-warning text-dark';
                    } elseif ($record['payment_status'] === 'Failed') {
                        $paymentBadge = 'bg-danger';
                    }

                    $overdue = $record['payment_status'] !== 'Paid'
                        && !empty($record['expiry_date'])
                        && strtotime($record['expiry_date']) < strtotime(date('Y-m-d'));

                    $payload = $record;
                    $payload['reference'] = $reference;
                    ?>

                    <tr data-payment="<?= htmlspecialchars($record['payment_status']) ?>">

                        <td class="ps-4">
                            <span class="sa-name"><?= htmlspecialchars($reference) ?></span>
                            <div class="sa-muted"><?= htmlspecialchars($record['billing_cycle']) ?></div>
                        </td>

                        <td>
                            <div class="sa-name"><?= htmlspecialchars($record['company_name']) ?></div>
                            <div class="sa-muted"><?= htmlspecialchars($record['company_code'] ?? '') ?></div>
                        </td>

                        <td><?= htmlspecialchars($record['plan_name']) ?></td>

                        <td>
                            <span class="sa-name">&#8369;<?= number_format((float) $record['amount'], 2) ?></span>
                        </td>

                        <td data-order="<?= htmlspecialchars($record['expiry_date']) ?>">
                            <div><?= htmlspecialchars(date('M d, Y', strtotime($record['start_date']))) ?></div>
                            <div class="sa-muted">
                                to <?= htmlspecialchars(date('M d, Y', strtotime($record['expiry_date']))) ?>
                                <?php if ($overdue): ?>
                                    <span class="badge bg-danger ms-1">Overdue</span>
                                <?php endif; ?>
                            </div>
                        </td>

                        <td data-search="<?= htmlspecialchars($record['payment_status']) ?>">
                            <span class="badge <?= $paymentBadge ?>">
                                <?= htmlspecialchars($record['payment_status']) ?>
                            </span>
                            <div class="sa-muted"><?= htmlspecialchars($record['status']) ?></div>
                        </td>

                        <td class="pe-4">

                            <button class="btn btn-sm sa-btn-soft btn-view-billing"
                                data-record="<?= htmlspecialchars(json_encode($payload), ENT_QUOTES, 'UTF-8') ?>"
                                title="View">
                                <i class="bi bi-eye"></i>
                            </button>

                            <button class="btn btn-sm sa-btn-soft btn-pay-billing"
                                data-record="<?= htmlspecialchars(json_encode($payload), ENT_QUOTES, 'UTF-8') ?>"
                                title="Update payment">
                                <i class="bi bi-cash-coin"></i>
                            </button>

                        </td>
                    </tr>

                <?php endforeach; ?>

            </tbody>

        </table>

    </div>

</div>


<!-- ===============================
     VIEW BILLING RECORD
================================ -->

<div class="modal fade" id="billingViewModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">

            <div class="modal-header">
                <div>
                    <h5 class="modal-title" id="bv_reference">Billing Record</h5>
                    <small class="sa-muted" id="bv_company"></small>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body">

                <table class="table table-sm sa-detail mb-4">
                    <tr><th>Plan</th><td id="bv_plan"></td></tr>
                    <tr><th>Amount</th><td id="bv_amount"></td></tr>
                    <tr><th>Billing Cycle</th><td id="bv_cycle"></td></tr>
                    <tr><th>Period</th><td id="bv_period"></td></tr>
                    <tr><th>Renewal Date</th><td id="bv_renewal"></td></tr>
                    <tr><th>Payment Status</th><td id="bv_payment"></td></tr>
                    <tr><th>Subscription Status</th><td id="bv_status"></td></tr>
                    <tr><th>Billing Contact</th><td id="bv_email"></td></tr>
                    <tr><th>Notes</th><td id="bv_notes"></td></tr>
                </table>

                <div class="sa-panel-head px-0 pt-0">History</div>

                <div id="bv_history"></div>

            </div>

            <div class="modal-footer">
                <button type="button" class="btn sa-btn-soft" data-bs-dismiss="modal">Close</button>
            </div>

        </div>
    </div>
</div>


<!-- ===============================
     UPDATE PAYMENT
================================ -->

<div class="modal fade" id="paymentModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">

            <form method="POST" data-confirm="Change this payment status?"
                data-confirm-text="The change and your reason are written to the subscription history."
                data-confirm-button="Update">

                <div class="modal-header">
                    <h5 class="modal-title">Update Payment</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">

                    <input type="hidden" name="subscription_id" id="pay_subscription_id">

                    <p class="mb-3">
                        <span class="sa-name" id="pay_reference"></span>
                        <span class="sa-muted" id="pay_company"></span>
                    </p>

                    <div class="mb-3">
                        <label class="form-label sa-required">Payment Status</label>
                        <select name="payment_status" id="pay_status" class="form-select" required>
                            <?php foreach ($PAYMENT_STATES as $state): ?>
                                <option value="<?= htmlspecialchars($state) ?>"><?= htmlspecialchars($state) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-0">
                        <label class="form-label sa-required">Reason</label>
                        <textarea name="remarks" id="pay_remarks" class="form-control" rows="3"
                            minlength="5" required
                            placeholder="Bank transfer received, reference number, who confirmed it."></textarea>
                        <div class="form-text">Kept on the subscription history, so it needs to make sense later.</div>
                    </div>

                </div>

                <div class="modal-footer">
                    <button type="button" class="btn sa-btn-soft" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="updatePayment" class="btn sa-btn">Update</button>
                </div>

            </form>

        </div>
    </div>
</div>


<script>
    document.addEventListener("DOMContentLoaded", function () {

        var table = new DataTable("#billingTable", {
            order: [],
            columnDefs: [{ orderable: false, targets: -1 }],
            language: {
                searchPlaceholder: "Search company, plan or reference...",
                emptyTable: "<div class=\"sa-empty\">"
                    + '<i class="bi bi-receipt d-block mb-2" style="font-size:28px;"></i>'
                    + "No billing records yet. They appear once a company is given a subscription."
                    + "</div>",
                zeroRecords: "No matching records"
            }
        });

        /* The payment filter reads the column rather than the row attribute,
           because DataTables only keeps the cells it has rendered. */
        document.getElementById("paymentFilter").addEventListener("change", function () {
            table.column(5).search(this.value ? "^" + this.value + "$" : "", true, false).draw();
        });

        var viewModal = new bootstrap.Modal(document.getElementById("billingViewModal"));
        var payModal = new bootstrap.Modal(document.getElementById("paymentModal"));

        var peso = "\u20B1";

        function money(value) {
            return peso + Number(value).toLocaleString(undefined, {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        function longDate(value) {
            if (!value) return "Not set";
            var d = new Date(value + "T00:00:00");
            return isNaN(d) ? value : d.toLocaleDateString(undefined, {
                year: "numeric", month: "short", day: "2-digit"
            });
        }

        function text(id, value) {
            document.getElementById(id).textContent = value;
        }

        document.addEventListener("click", function (e) {

            var viewBtn = e.target.closest(".btn-view-billing");

            if (viewBtn) {
                var r = JSON.parse(viewBtn.dataset.record);

                text("bv_reference", r.reference);
                text("bv_company", r.company_name + (r.company_code ? " (" + r.company_code + ")" : ""));
                text("bv_plan", r.plan_name);
                text("bv_amount", money(r.amount));
                text("bv_cycle", r.billing_cycle);
                text("bv_period", longDate(r.start_date) + " to " + longDate(r.expiry_date));
                text("bv_renewal", longDate(r.renewal_date));
                text("bv_payment", r.payment_status);
                text("bv_status", r.status);
                text("bv_email", r.email || "Not set");
                text("bv_notes", r.notes || "None");

                var box = document.getElementById("bv_history");
                box.textContent = "";

                if (!r.history || !r.history.length) {
                    var none = document.createElement("div");
                    none.className = "sa-muted";
                    none.textContent = "Nothing recorded yet.";
                    box.appendChild(none);
                } else {
                    r.history.forEach(function (h) {
                        var item = document.createElement("div");
                        item.className = "sa-feed-item px-0";

                        var head = document.createElement("div");
                        head.className = "sa-name";
                        head.textContent = h.action || "Update";
                        item.appendChild(head);

                        if (h.remarks) {
                            var body = document.createElement("div");
                            body.className = "small text-muted";
                            body.textContent = h.remarks;
                            item.appendChild(body);
                        }

                        var when = document.createElement("div");
                        when.className = "sa-muted";
                        when.textContent = h.created_at + (h.fullname ? " by " + h.fullname : "");
                        item.appendChild(when);

                        box.appendChild(item);
                    });
                }

                viewModal.show();
                return;
            }

            var payBtn = e.target.closest(".btn-pay-billing");

            if (payBtn) {
                var p = JSON.parse(payBtn.dataset.record);

                document.getElementById("pay_subscription_id").value = p.subscription_id;
                text("pay_reference", p.reference);
                text("pay_company", " " + p.company_name);
                document.getElementById("pay_status").value = p.payment_status;
                document.getElementById("pay_remarks").value = "";

                payModal.show();
            }
        });

    });
</script>

<?php if ($alert): ?>
    <script>
        Swal.fire({
            icon: <?= json_encode($alert['icon']) ?>,
            title: <?= json_encode($alert['title']) ?>,
            text: <?= json_encode($alert['text']) ?>,
            confirmButtonColor: "#00224c"
        });
    </script>
<?php endif; ?>

<?php include("sAdminFooter.php"); ?>
