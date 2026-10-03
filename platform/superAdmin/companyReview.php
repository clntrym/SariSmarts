<?php

require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../includes/platform_roles.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/permits.php';
requirePlatformAccess('companyReview');
require_once __DIR__ . '/../accounts/send_review_result.php';

/*
|--------------------------------------------------------------------------
| COMPANY APPLICATION REVIEW
|--------------------------------------------------------------------------
|
| Approve or reject a submitted business. A reason is required either way:
| an approval note gives the owner context, and a rejection has to explain
| itself so the owner knows what to fix before resubmitting.
|
| Approving does not grant access on its own - it only unlocks payment.
| Access opens when the subscription becomes Active.
|
*/

$alert = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reviewCompany'])) {

    $companyId = (int) ($_POST['company_id'] ?? 0);
    $decision  = $_POST['decision'] ?? '';
    $reason    = trim($_POST['reason'] ?? '');
    $reviewer  = (int) ($_SESSION['user_id'] ?? 0);

    if (!in_array($decision, ['Approved', 'Rejected'], true)) {

        $alert = ['icon' => 'error', 'title' => 'Invalid Decision', 'text' => 'Please choose approve or reject.'];

    } elseif ($decision === 'Rejected' && strlen($reason) < 10) {

        /*
        | Only a rejection has to be explained. An owner who is turned away
        | cannot act without knowing what to fix, so the reason is mandatory
        | there. An approval speaks for itself, and requiring a note for one
        | only taught reviewers to type something meaningless.
        */
        $alert = ['icon' => 'error', 'title' => 'Reason Required',
                  'text' => 'Please write at least 10 characters so the owner knows what to correct.'];

    } else {

        $stmt = $conn->prepare("
            SELECT company_id, company_name, owner_name, email, status
            FROM company WHERE company_id = ? LIMIT 1
        ");
        $stmt->bind_param("i", $companyId);
        $stmt->execute();
        $company = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$company) {

            $alert = ['icon' => 'error', 'title' => 'Not Found', 'text' => 'That application no longer exists.'];

        } elseif (!in_array($company['status'], ['Pending', 'Rejected'], true)) {

            $alert = ['icon' => 'error', 'title' => 'Already Processed',
                      'text' => $company['company_name'] . ' is already ' . $company['status'] . '.'];

        } else {

            $conn->begin_transaction();

            try {

                /*
                | An approved owner still cannot sign in -- the login gate holds
                | until the subscription is Active -- so the approval email has to
                | be able to identify them on its own. This token does that, the
                | same way the email verification token does, and subscribe.php
                | reads it to greet them by name with the plan they asked for
                | instead of a password form they cannot satisfy.
                |
                | A rejection gets no token: there is nothing to subscribe to yet.
                */
                $approvalToken = null;

                if ($decision === 'Approved') {
                    $approvalToken = bin2hex(random_bytes(32));
                }

                $stmt = $conn->prepare("
                    UPDATE company
                    SET status = ?, review_reason = ?, reviewed_by = ?, reviewed_at = NOW(),
                        approval_token = ?,
                        approval_token_expires_at = CASE WHEN ? IS NULL THEN NULL
                                                         ELSE DATE_ADD(NOW(), INTERVAL 7 DAY) END
                    WHERE company_id = ?
                ");
                $stmt->bind_param("ssissi", $decision, $reason, $reviewer, $approvalToken, $approvalToken, $companyId);
                $stmt->execute();
                $stmt->close();

                /*
                | Approval is what switches the owner's account on. Registration
                | leaves it inactive and sends no verification email, so without
                | this the owner would be blocked by the login gate forever --
                | acc_log_in.php turns away any user whose status is 'inactive'.
                |
                | Only on approval: a rejected application leaves the account
                | shut, and email_verified_at is stamped because a reviewer
                | reading the documents has confirmed the business more firmly
                | than a link click would have.
                */
                if ($decision === 'Approved') {

                    $stmt = $conn->prepare("
                        UPDATE users
                        SET status = 'active',
                            email_verified_at = COALESCE(email_verified_at, NOW()),
                            verification_token = NULL,
                            verification_expires_at = NULL
                        WHERE company_id = ? AND LOWER(status) <> 'active'
                    ");
                    $stmt->bind_param("i", $companyId);
                    $stmt->execute();
                    $stmt->close();
                }

                $stmt = $conn->prepare("
                    INSERT INTO company_review_history (company_id, action, reason, reviewed_by)
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->bind_param("issi", $companyId, $decision, $reason, $reviewer);
                $stmt->execute();
                $stmt->close();

                $conn->commit();

                $emailed = true;

                try {
                    sendReviewResultEmail(
                        $company['email'],
                        $company['owner_name'],
                        $company['company_name'],
                        $decision,
                        $reason,
                        $approvalToken
                    );
                } catch (Throwable $mailError) {
                    $emailed = false;
                }

                auditLog($conn, 'Application ' . strtolower($decision), 'company', $companyId,
                         $company['company_name'] . ($decision === 'Rejected' ? ' - ' . $reason : ''));

                $alert = [
                    'icon'  => 'success',
                    'title' => $decision === 'Approved' ? 'Application Approved' : 'Application Rejected',
                    'text'  => $company['company_name'] . ' has been marked ' . strtolower($decision) . '.'
                        . ($emailed ? ' The owner has been emailed.' : ' The notification email could not be sent.'),
                ];

            } catch (Throwable $e) {

                $conn->rollback();
                $alert = ['icon' => 'error', 'title' => 'Review Failed', 'text' => 'Please try again.'];
            }
        }
    }
}


include("sAdminHeader.php");

$applications = [];

$result = $conn->query("
    SELECT c.*, u.fullname AS reviewer_name,
           sp.plan_name AS requested_plan,
           cs.amount AS requested_amount
    FROM company c
    LEFT JOIN users u ON u.user_id = c.reviewed_by
    LEFT JOIN company_subscriptions cs
        ON cs.subscription_id = (
            SELECT subscription_id FROM company_subscriptions
            WHERE company_id = c.company_id
            ORDER BY subscription_id ASC LIMIT 1
        )
    LEFT JOIN subscription_plans sp ON sp.plan_id = cs.plan_id
    WHERE c.status IN ('Pending', 'Approved', 'Rejected')
    ORDER BY FIELD(c.status, 'Pending', 'Rejected', 'Approved'), c.submitted_at DESC
");

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $applications[] = $row;
    }
}

$pendingCount  = 0;
$approvedCount = 0;
$rejectedCount = 0;

foreach ($applications as $a) {
    if ($a['status'] === 'Pending') {
        $pendingCount++;
    } elseif ($a['status'] === 'Approved') {
        $approvedCount++;
    } elseif ($a['status'] === 'Rejected') {
        $rejectedCount++;
    }
}

?>

<div class="sa-page-head">
    <div>
        <h3 class="sa-page-title">Company Applications</h3>
        <p class="sa-page-sub">
            Review submitted businesses. Approving unlocks payment; access opens once the
            subscription is active.
        </p>
    </div>
</div>


<div class="row g-3 mb-4">

    <div class="col-md-4">
        <div class="sa-stat p-3 d-flex justify-content-between align-items-center">
            <div>
                <div class="text-muted small">Awaiting Review</div>
                <div class="sa-stat-value"><?= number_format($pendingCount) ?></div>
            </div>
            <div class="sa-stat-icon sa-tone-warn"><i class="bi bi-hourglass-split"></i></div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="sa-stat p-3 d-flex justify-content-between align-items-center">
            <div>
                <div class="text-muted small">Approved, Awaiting Payment</div>
                <div class="sa-stat-value"><?= number_format($approvedCount) ?></div>
            </div>
            <div class="sa-stat-icon sa-tone-ok"><i class="bi bi-check-circle"></i></div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="sa-stat p-3 d-flex justify-content-between align-items-center">
            <div>
                <div class="text-muted small">Rejected</div>
                <div class="sa-stat-value"><?= number_format($rejectedCount) ?></div>
            </div>
            <div class="sa-stat-icon sa-tone-bad"><i class="bi bi-x-circle"></i></div>
        </div>
    </div>

</div>


<div class="sa-panel">
    <div class="card-body p-0">
        <div class="table-responsive">

            <table id="reviewTable" class="table table-hover mb-0 sa-table" style="width:100%">

                <thead>
                    <tr>
                        <th>Business</th>
                        <th>Owner</th>
                        <th>Type</th>
                        <th>Size</th>
                        <th>Requested Plan</th>
                        <th class="text-center">Branches</th>
                        <th>Submitted</th>
                        <th>Status</th>
                        <th style="width:150px;">Action</th>
                    </tr>
                </thead>

                <tbody>

                    <?php foreach ($applications as $a): ?>

                        <?php
                        $statusBadge = match ($a['status']) {
                            'Pending'  => 'bg-warning text-dark',
                            'Approved' => 'bg-success',
                            'Rejected' => 'bg-danger',
                            default    => 'bg-secondary',
                        };
                        ?>

                        <tr>

                            <td>
                                <div class="sa-name">
                                    <?= htmlspecialchars($a['company_name']) ?>
                                </div>
                                <div class="sa-muted"><?= htmlspecialchars($a['company_code'] ?? '') ?></div>
                            </td>

                            <td>
                                <div><?= htmlspecialchars($a['owner_name']) ?></div>
                                <div class="sa-muted"><?= htmlspecialchars($a['email'] ?? '') ?></div>
                            </td>

                            <td><?= htmlspecialchars($a['business_type'] ?? '') ?></td>

                            <td><?= htmlspecialchars($a['business_size'] ?: '-') ?></td>

                            <td>
                                <?= htmlspecialchars($a['requested_plan'] ?: '-') ?>
                            </td>

                            <td class="text-center"><?= htmlspecialchars($a['number_of_branches'] ?: '-') ?></td>

                            <td data-order="<?= htmlspecialchars($a['submitted_at'] ?? '') ?>">
                                <?= $a['submitted_at']
                                    ? htmlspecialchars(date('M d, Y', strtotime($a['submitted_at'])))
                                    : '-' ?>
                            </td>

                            <td>
                                <span class="badge <?= $statusBadge ?>"><?= htmlspecialchars($a['status']) ?></span>
                                <?php if ($a['status'] === 'Rejected' && !empty($a['review_reason'])): ?>
                                    <div class="sa-muted" style="max-width:200px;">
                                        <?= htmlspecialchars($a['review_reason']) ?>
                                    </div>
                                <?php endif; ?>
                            </td>

                            <td>
                                <button class="btn btn-sm sa-btn btn-review"
                                    data-company="<?= htmlspecialchars(json_encode($a), ENT_QUOTES, 'UTF-8') ?>">
                                    Review
                                </button>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                </tbody>

            </table>

        </div>
    </div>
</div>


<!-- =========================================================
     REVIEW MODAL
========================================================== -->

<div class="modal fade" id="reviewModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">

            <!--
                Approve and Reject post the same form, so the question the
                shared guard asks is rewritten by whichever button was
                pressed. The wording here is the fallback, so the decision
                is still confirmed if that script never ran.
            -->
            <form method="POST" data-confirm="Record this decision?"
                data-confirm-text="The owner is emailed either way."
                data-confirm-button="Confirm">

                <div class="modal-header">
                    <div>
                        <h5 class="modal-title" id="rv_title">Review Application</h5>
                        <small class="text-muted" id="rv_code"></small>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">

                    <input type="hidden" name="company_id" id="rv_company_id">

                    <table class="table table-sm sa-detail mb-4">
                        <tbody>
                            <tr><th>Business Type</th><td id="rv_type"></td></tr>
                            <tr><th>Address</th><td id="rv_address"></td></tr>
                            <tr><th>DTI Business Name No.</th><td id="rv_dti"></td></tr>
                            <tr><th>DTI registered</th><td id="rv_dti_date"></td></tr>
                            <tr><th>DTI valid until</th><td id="rv_dti_expiry"></td></tr>
                            <tr><th>BIR TIN</th><td id="rv_tin"></td></tr>
                            <tr><th>BIR registered</th><td id="rv_bir_date"></td></tr>
                            <tr><th>BIR RDO / OCN</th><td id="rv_bir_ref"></td></tr>
                            <tr><th>Branches</th><td id="rv_branches"></td></tr>
                            <tr><th>Est. Employees</th><td id="rv_employees"></td></tr>
                            <tr><th>Asset Range</th><td id="rv_assets"></td></tr>
                            <tr><th>Business Size</th><td id="rv_size"></td></tr>
                            <tr><th>Requested Plan</th><td id="rv_plan"></td></tr>
                            <tr><th>Owner</th><td id="rv_owner"></td></tr>
                            <tr><th>Contact</th><td id="rv_contact"></td></tr>
                            <tr><th>Supporting Document</th><td id="rv_doc"></td></tr>
                        </tbody>
                    </table>

                    <div id="rv_previous" class="alert alert-secondary d-none">
                        <strong>Previous decision:</strong>
                        <span id="rv_previous_text"></span>
                    </div>

                    <div id="rv_reason_block" class="d-none">
                        <label class="form-label fw-semibold">
                            Reason for rejection <span class="text-danger">*</span>
                        </label>
                        <textarea name="reason" id="rv_reason" class="form-control" rows="3" minlength="10"
                            placeholder="Say exactly what the owner must correct before resubmitting."></textarea>
                        <div class="form-text">
                            Sent to the owner by email. Minimum 10 characters.
                        </div>
                    </div>

                </div>

                <div class="modal-footer justify-content-between">

                    <button type="button" class="btn sa-btn-soft" data-bs-dismiss="modal">Cancel</button>

                    <div class="d-flex gap-2" id="rv_decide">
                        <button type="button" class="btn btn-danger" id="rv_reject_start">
                            <i class="bi bi-x-lg me-1"></i> Reject
                        </button>
                        <button type="submit" name="reviewCompany" value="1" class="btn btn-success"
                            id="rv_approve">
                            <i class="bi bi-check-lg me-1"></i> Approve
                        </button>
                    </div>

                    <div class="d-flex gap-2 d-none" id="rv_confirm">
                        <button type="button" class="btn sa-btn-soft" id="rv_reject_back">
                            Back
                        </button>
                        <button type="submit" name="reviewCompany" value="1" class="btn btn-danger"
                            id="rv_reject_submit">
                            <i class="bi bi-x-lg me-1"></i> Confirm Rejection
                        </button>
                    </div>

                    <input type="hidden" name="decision" id="rv_decision" value="">

                </div>

            </form>

        </div>
    </div>
</div>


<script>
    document.addEventListener("DOMContentLoaded", function () {

        new DataTable("#reviewTable", {
            order: [],
            columnDefs: [{ orderable: false, targets: -1 }],
            language: {
                searchPlaceholder: "Search applications...",
                emptyTable: "No applications yet",
                zeroRecords: "No matching applications"
            }
        });

        var modal = new bootstrap.Modal(document.getElementById("reviewModal"));

        function text(id, value) {
            document.getElementById(id).textContent = value || "-";
        }

        document.addEventListener("click", function (e) {

            var btn = e.target.closest(".btn-review");
            if (!btn) return;

            var c = JSON.parse(btn.dataset.company);

            document.getElementById("rv_company_id").value = c.company_id;
            document.getElementById("rv_title").textContent = c.company_name;
            document.getElementById("rv_code").textContent = c.company_code || "";

            text("rv_type", c.business_type);
            text("rv_address", [c.address, c.city, c.province, c.postal_code].filter(Boolean).join(", "));
            text("rv_dti", c.dti_sec_registration);
            text("rv_dti_date", longDate(c.dti_registration_date));
            text("rv_tin", c.tin_number);
            text("rv_bir_date", longDate(c.bir_registration_date));
            text("rv_bir_ref", [c.bir_rdo_code, c.bir_ocn].filter(Boolean).join(" / "));

            /*
            | The expiry is never typed by anyone: it is five years from the
            | DTI registration date, worked out on the server when the
            | application was filed. Shown here with how it stands today so
            | the reviewer is not approving a certificate that has already
            | run out.
            */
            var expiryCell = document.getElementById("rv_dti_expiry");

            if (c.dti_expiry_date) {
                var status = dtiStatusFor(c.dti_expiry_date);
                expiryCell.innerHTML = longDate(c.dti_expiry_date)
                    + ' <span class="badge ' + status.badge + ' ms-1">' + status.label + "</span>"
                    + '<div class="sa-muted">' + status.detail + "</div>";
            } else {
                expiryCell.textContent = "-";
            }
            text("rv_branches", c.number_of_branches);
            text("rv_employees", c.estimated_employees);
            text("rv_assets", c.business_asset_range);
            text("rv_size", c.business_size);
            text("rv_plan", c.requested_plan);
            text("rv_owner", c.owner_name);
            text("rv_contact", [c.email, c.phone].filter(Boolean).join(" / "));

            /*
            | Registration now asks for two certificates, DTI and BIR. The
            | other two are still listed because companies registered before
            | that change have them, and a reviewer opening an old
            | application should still see everything it carried.
            |
            | supporting_document is the original single upload, kept for the
            | same reason.
            */
            var docCell = document.getElementById("rv_doc");
            var documents = [
                ["DTI certificate", c.dti_sec_document],
                ["BIR certificate (2303)", c.tin_document],
                ["Business Registration (older applications)", c.business_reg_document],
                ["Business Permit (older applications)", c.business_permit_document]
            ].filter(function (d) { return d[1]; });

            if (!documents.length && c.supporting_document) {
                documents.push(["Supporting document", c.supporting_document]);
            }

            if (documents.length) {
                docCell.innerHTML = documents.map(function (d) {
                    var url = "../" + String(d[1]).split("/").map(encodeURIComponent).join("/");
                    return '<div class="mb-1"><span class="text-muted small">' + d[0] +
                        ':</span> <a href="' + url +
                        '" target="_blank" rel="noopener">Open</a></div>';
                }).join("");
            } else {
                docCell.textContent = "-";
            }

            var previous = document.getElementById("rv_previous");
            if (c.review_reason && c.status !== "Pending") {
                document.getElementById("rv_previous_text").textContent = c.status + " - " + c.review_reason;
                previous.classList.remove("d-none");
            } else {
                previous.classList.add("d-none");
            }

            /*
            | Every open starts on the approve/reject choice, never part-way
            | through a rejection left over from the previous application.
            */
            document.getElementById("rv_reason").value = "";
            showDecisionButtons();
            document.getElementById("rv_decision").value = "";

            modal.show();
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

<script>
    /*
    | The reason belongs to a rejection only, so the field stays hidden until
    | one is actually being made. Approve submits from the first screen;
    | Reject opens the box and asks for a second, deliberate click.
    */
    /* "September 11, 2023" rather than "2023-09-11". */
    function longDate(value) {
        if (!value) return "-";

        var d = new Date(String(value).slice(0, 10) + "T00:00:00");

        return isNaN(d) ? value : d.toLocaleDateString(undefined, {
            year: "numeric", month: "long", day: "numeric"
        });
    }

    /*
    | The same thresholds the server uses, so a badge here never disagrees
    | with the monitor: expired, 90 days or less, 180 days or less, valid.
    */
    function dtiStatusFor(expiry) {
        var end = new Date(String(expiry).slice(0, 10) + "T00:00:00");
        var today = new Date();

        today.setHours(0, 0, 0, 0);

        var days = Math.round((end - today) / 86400000);

        if (days < 0) {
            return { label: "Expired", badge: "bg-danger", detail: Math.abs(days) + " days ago" };
        }

        if (days <= 90) {
            return { label: "Renewal Reminder", badge: "bg-danger", detail: days + " days left" };
        }

        if (days <= 180) {
            return { label: "Expiring Soon", badge: "bg-warning text-dark", detail: days + " days left" };
        }

        return { label: "Valid", badge: "bg-success", detail: days + " days left" };
    }

    function showDecisionButtons() {
        var block = document.getElementById("rv_reason_block");
        var decide = document.getElementById("rv_decide");
        var confirmRow = document.getElementById("rv_confirm");

        if (!block || !decide || !confirmRow) return;

        block.classList.add("d-none");
        decide.classList.remove("d-none");
        confirmRow.classList.add("d-none");
        document.getElementById("rv_decision").value = "";

        /* A hidden required field can never be filled in, so the rule only
           applies while the box is on screen. */
        document.getElementById("rv_reason").removeAttribute("required");
    }

    document.addEventListener("DOMContentLoaded", function () {

        var start = document.getElementById("rv_reject_start");
        var back = document.getElementById("rv_reject_back");
        var approve = document.getElementById("rv_approve");
        var rejectSubmit = document.getElementById("rv_reject_submit");

        /* Both buttons post the same form, so each sets the decision and
           the question the shared guard asks before it goes through. */
        function arm(form, decision, question, text, button, danger) {
            document.getElementById("rv_decision").value = decision;
            form.dataset.confirm = question;
            form.dataset.confirmText = text;
            form.dataset.confirmButton = button;

            if (danger) {
                form.dataset.confirmDanger = "";
            } else {
                delete form.dataset.confirmDanger;
            }
        }

        if (start) {
            start.addEventListener("click", function () {
                document.getElementById("rv_reason_block").classList.remove("d-none");
                document.getElementById("rv_decide").classList.add("d-none");
                document.getElementById("rv_confirm").classList.remove("d-none");
                document.getElementById("rv_reason").setAttribute("required", "required");
                document.getElementById("rv_reason").focus();
            });
        }

        if (back) {
            back.addEventListener("click", showDecisionButtons);
        }

        if (approve) {
            approve.addEventListener("click", function () {
                arm(
                    approve.form,
                    "Approved",
                    "Approve " + (document.getElementById("rv_title").textContent || "this application") + "?",
                    "The owner is emailed and can subscribe straight away.",
                    "Approve",
                    false
                );
            });
        }

        if (rejectSubmit) {
            rejectSubmit.addEventListener("click", function () {
                arm(
                    rejectSubmit.form,
                    "Rejected",
                    "Reject this application?",
                    "The reason you wrote is emailed to the owner.",
                    "Reject",
                    true
                );
            });
        }
    });
</script>
