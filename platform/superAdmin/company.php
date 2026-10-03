<?php

require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../includes/platform_roles.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/company_contracts.php';
requirePlatformAccess('company');


/*
|--------------------------------------------------------------------------
| SAVE COMPANY (create or update)
|--------------------------------------------------------------------------
*/

$alert = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['saveCompany'])) {

    $companyId    = (int) ($_POST['company_id'] ?? 0);
    $companyName  = trim($_POST['company_name'] ?? '');
    $ownerName    = trim($_POST['owner_name'] ?? '');
    $businessType = trim($_POST['business_type'] ?? 'Retail Store');
    $email        = trim($_POST['email'] ?? '');
    $phone        = trim($_POST['phone'] ?? '');
    $tin          = trim($_POST['tin_number'] ?? '');
    $address      = trim($_POST['address'] ?? '');
    $city         = trim($_POST['city'] ?? '');
    $province     = trim($_POST['province'] ?? '');
    $postal       = trim($_POST['postal_code'] ?? '');
    $status       = trim($_POST['status'] ?? 'Pending');

    $allowedTypes    = ['Retail Store', 'Wholesale', 'Supermarket', 'Convenience Store', 'Franchise', 'Other'];
    $allowedStatuses = ['Pending', 'Active', 'Suspended', 'Inactive'];

    if ($companyName === '' || $ownerName === '') {
        $alert = ['icon' => 'error', 'title' => 'Missing Details', 'text' => 'Company name and owner name are required.'];
    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $alert = ['icon' => 'error', 'title' => 'Invalid Email', 'text' => 'Please enter a valid email address.'];
    } elseif (!in_array($businessType, $allowedTypes, true)) {
        $alert = ['icon' => 'error', 'title' => 'Invalid Business Type', 'text' => 'Please choose a valid business type.'];
    } elseif (!in_array($status, $allowedStatuses, true)) {
        $alert = ['icon' => 'error', 'title' => 'Invalid Status', 'text' => 'Please choose a valid status.'];
    } elseif ($companyId > 0) {

        $stmt = $conn->prepare("
            UPDATE company SET
                company_name = ?, business_type = ?, owner_name = ?, email = ?,
                phone = ?, tin_number = ?, address = ?, city = ?, province = ?,
                postal_code = ?, status = ?
            WHERE company_id = ?
        ");
        $stmt->bind_param(
            "sssssssssssi",
            $companyName, $businessType, $ownerName, $email, $phone, $tin,
            $address, $city, $province, $postal, $status, $companyId
        );

        $alert = $stmt->execute()
            ? ['icon' => 'success', 'title' => 'Company Updated', 'text' => $companyName . ' has been updated.']
            : ['icon' => 'error', 'title' => 'Update Failed', 'text' => $conn->error];

        $stmt->close();

    } else {

        // COMP-1001, COMP-1002, ... continuing from the highest existing code.
        $codeRow = $conn->query("
            SELECT MAX(CAST(SUBSTRING(company_code, 6) AS UNSIGNED)) AS max_code
            FROM company WHERE company_code LIKE 'COMP-%'
        ")->fetch_assoc();

        $companyCode = 'COMP-' . (max(1000, (int) ($codeRow['max_code'] ?? 1000)) + 1);

        $stmt = $conn->prepare("
            INSERT INTO company
                (company_code, company_name, business_type, owner_name, email,
                 phone, tin_number, address, city, province, postal_code, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param(
            "ssssssssssss",
            $companyCode, $companyName, $businessType, $ownerName, $email,
            $phone, $tin, $address, $city, $province, $postal, $status
        );

        $alert = $stmt->execute()
            ? ['icon' => 'success', 'title' => 'Company Added', 'text' => $companyName . ' was created as ' . $companyCode . '.']
            : ['icon' => 'error', 'title' => 'Save Failed', 'text' => $conn->error];

        $stmt->close();
    }
}


include("sAdminHeader.php");


/*
|--------------------------------------------------------------------------
| ACCEPT OR REFUSE A SIGNED AGREEMENT
|--------------------------------------------------------------------------
|
| The business uploads its signed copy on subscribe.php. This is the other
| end of that: somebody here reads it and says whether it stands.
|
| Refusing without saying why leaves the business with a rejected contract
| and nothing to act on, so a reason is required.
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reviewAgreement'])) {

    $contractId = (int) ($_POST['contract_id'] ?? 0);
    $verdict = $_POST['review'] ?? '';
    $remarks = trim((string) ($_POST['review_remarks'] ?? ''));

    $stmt = $conn->prepare("
        SELECT c.contract_id, c.contract_number, c.signed_contract, co.company_name
        FROM company_contracts c
        JOIN company co ON co.company_id = c.company_id
        WHERE c.contract_id = ? LIMIT 1
    ");
    $stmt->bind_param("i", $contractId);
    $stmt->execute();
    $agreement = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$agreement) {

        $alert = ['icon' => 'error', 'title' => 'Not Found',
                  'text' => 'That agreement no longer exists.'];

    } elseif (!in_array($verdict, ['Approved', 'Rejected'], true)) {

        $alert = ['icon' => 'error', 'title' => 'Choose a Verdict',
                  'text' => 'Please accept or refuse the signed agreement.'];

    } elseif (trim((string) $agreement['signed_contract']) === '') {

        $alert = ['icon' => 'warning', 'title' => 'Nothing to Review',
                  'text' => 'This business has not sent a signed copy yet.'];

    } elseif ($verdict === 'Rejected' && $remarks === '') {

        $alert = ['icon' => 'error', 'title' => 'Reason Required',
                  'text' => 'Say what is wrong with it. The business is shown this.'];

    } elseif (mb_strlen($remarks) > 2000) {

        $alert = ['icon' => 'error', 'title' => 'Too Long',
                  'text' => 'Remarks cannot be longer than 2000 characters.'];

    } else {

        $remarksValue = $remarks === '' ? null : $remarks;
        $reviewer = (int) ($_SESSION['user_id'] ?? 0) ?: null;

        $stmt = $conn->prepare("
            UPDATE company_contracts
            SET review = ?, review_remarks = ?, reviewed_by = ?, reviewed_at = NOW()
            WHERE contract_id = ?
        ");
        $stmt->bind_param("ssii", $verdict, $remarksValue, $reviewer, $contractId);
        $stmt->execute();
        $changed = $stmt->affected_rows;
        $stmt->close();

        if ($changed === 0) {

            $alert = ['icon' => 'warning', 'title' => 'Nothing Changed',
                      'text' => 'That verdict was already on record.'];

        } else {

            auditLog($conn, 'Agreement ' . strtolower($verdict), 'contract', $contractId,
                     $agreement['contract_number'] . ' - ' . $agreement['company_name']
                     . ($remarks !== '' ? ': ' . mb_substr($remarks, 0, 80) : ''));

            $alert = ['icon' => 'success', 'title' => 'Agreement ' . $verdict,
                      'text' => $agreement['contract_number'] . ' has been '
                                . strtolower($verdict) . '.'];
        }
    }
}


/*
|--------------------------------------------------------------------------
| LOAD COMPANIES
|--------------------------------------------------------------------------
|
| Each row carries its latest subscription plus how many users, branches
| and employees belong to it - counts that only became possible once
| company_id was added to those tables.
|
*/

$companies = [];

$result = $conn->query("
    SELECT
        c.*,
        sp.plan_name,
        cs.status AS subscription_status,
        cs.expiry_date,
        (SELECT COUNT(*) FROM users     u WHERE u.company_id = c.company_id) AS user_count,
        (SELECT COUNT(*) FROM branch    b WHERE b.company_id = c.company_id) AS branch_count,
        (SELECT COUNT(*) FROM employees e WHERE e.company_id = c.company_id) AS employee_count,
        cc.contract_id,
        cc.contract_number,
        cc.issued_template,
        cc.signed_contract,
        cc.status AS contract_status,
        cc.review AS contract_review,
        cc.review_remarks AS contract_remarks
    FROM company c
    LEFT JOIN company_subscriptions cs
        ON cs.subscription_id = (
            SELECT subscription_id FROM company_subscriptions
            WHERE company_id = c.company_id
            ORDER BY expiry_date DESC LIMIT 1
        )
    LEFT JOIN subscription_plans sp ON sp.plan_id = cs.plan_id
    LEFT JOIN company_contracts cc
        ON cc.contract_id = (
            SELECT contract_id FROM company_contracts
            WHERE company_id = c.company_id
            ORDER BY contract_id DESC LIMIT 1
        )
    ORDER BY c.company_id
");

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $companies[] = $row;
    }
}

$today = date('Y-m-d');

$totalCompanies   = count($companies);
$activeCompanies  = 0;
$pendingCompanies = 0;
$expiredCompanies = 0;

foreach ($companies as $c) {

    if ($c['status'] === 'Active') {
        $activeCompanies++;
    }

    if ($c['status'] === 'Pending') {
        $pendingCompanies++;
    }

    $noLiveSub = $c['subscription_status'] === null
        || in_array($c['subscription_status'], ['Expired', 'Cancelled'], true)
        || (!empty($c['expiry_date']) && $c['expiry_date'] < $today);

    if ($noLiveSub) {
        $expiredCompanies++;
    }
}

?>

<div class="sa-page-head">

    <div>
        <h3 class="sa-page-title">Companies</h3>
        <p class="sa-page-sub">Tenants subscribed to the platform.</p>
    </div>

    <button class="btn sa-btn" id="btnAddCompany">
        <i class="bi bi-plus-lg me-1"></i> Add Company
    </button>

</div>


<div class="row g-3 mb-4">

    <div class="col-md-3">
        <div class="sa-stat p-3 d-flex justify-content-between align-items-center">
            <div>
                <div class="text-muted small">Total Companies</div>
                <div class="sa-stat-value"><?= number_format($totalCompanies) ?></div>
            </div>
            <div class="sa-stat-icon sa-tone-navy"><i class="bi bi-buildings"></i></div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="sa-stat p-3 d-flex justify-content-between align-items-center">
            <div>
                <div class="text-muted small">Active</div>
                <div class="sa-stat-value"><?= number_format($activeCompanies) ?></div>
            </div>
            <div class="sa-stat-icon sa-tone-ok"><i class="bi bi-check-circle"></i></div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="sa-stat p-3 d-flex justify-content-between align-items-center">
            <div>
                <div class="text-muted small">Pending</div>
                <div class="sa-stat-value"><?= number_format($pendingCompanies) ?></div>
            </div>
            <div class="sa-stat-icon sa-tone-warn"><i class="bi bi-hourglass-split"></i></div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="sa-stat p-3 d-flex justify-content-between align-items-center">
            <div>
                <div class="text-muted small">No Live Subscription</div>
                <div class="sa-stat-value"><?= number_format($expiredCompanies) ?></div>
            </div>
            <div class="sa-stat-icon sa-tone-bad"><i class="bi bi-exclamation-triangle"></i></div>
        </div>
    </div>

</div>


<div class="sa-panel">
    <div class="p-0">
        <div class="table-responsive">

            <table id="companyTable" class="table table-hover mb-0 sa-table" style="width:100%">

                <thead>
                    <tr>
                        <th>Company</th>
                        <th>Owner</th>
                        <th>Business Type</th>
                        <th>Contact</th>
                        <th>Plan</th>
                        <th>Subscription</th>
                        <th class="text-center">Users</th>
                        <th class="text-center">Branches</th>
                        <th>Status</th>
                        <th style="width:150px;">Agreement</th>
                        <th style="width:110px;">Action</th>
                    </tr>
                </thead>

                <tbody>

                    <?php foreach ($companies as $c): ?>

                        <?php
                        $noLiveSub = $c['subscription_status'] === null
                            || in_array($c['subscription_status'], ['Expired', 'Cancelled'], true)
                            || (!empty($c['expiry_date']) && $c['expiry_date'] < $today);

                        $statusBadge = match ($c['status']) {
                            'Active'    => 'bg-success',
                            'Pending'   => 'bg-warning text-dark',
                            'Suspended' => 'bg-danger',
                            default     => 'bg-secondary',
                        };
                        ?>

                        <tr>

                            <td>
                                <div class="sa-name"><?= htmlspecialchars($c['company_name']) ?></div>
                                <div class="sa-muted"><?= htmlspecialchars($c['company_code'] ?? '') ?></div>
                            </td>

                            <td><?= htmlspecialchars($c['owner_name']) ?></td>

                            <td><?= htmlspecialchars($c['business_type'] ?? '') ?></td>

                            <td>
                                <div><?= htmlspecialchars($c['email'] ?: '-') ?></div>
                                <div class="text-muted small"><?= htmlspecialchars($c['phone'] ?: '-') ?></div>
                            </td>

                            <td><?= htmlspecialchars($c['plan_name'] ?: 'No plan') ?></td>

                            <td>
                                <?php if ($c['subscription_status'] === null): ?>
                                    <span class="badge bg-secondary">None</span>
                                <?php elseif ($noLiveSub): ?>
                                    <span class="badge bg-danger">Expired</span>
                                    <div class="text-muted small">
                                        <?= htmlspecialchars(date('M d, Y', strtotime($c['expiry_date']))) ?>
                                    </div>
                                <?php else: ?>
                                    <span class="badge bg-success"><?= htmlspecialchars($c['subscription_status']) ?></span>
                                    <div class="text-muted small">
                                        until <?= htmlspecialchars(date('M d, Y', strtotime($c['expiry_date']))) ?>
                                    </div>
                                <?php endif; ?>
                            </td>

                            <td class="text-center"><?= number_format((int) $c['user_count']) ?></td>

                            <td class="text-center"><?= number_format((int) $c['branch_count']) ?></td>

                            <td><span class="badge <?= $statusBadge ?>"><?= htmlspecialchars($c['status']) ?></span></td>

                            <?php
                            /*
                            | The service agreement.
                            |
                            | "Signed" and the verdict are two facts, not one:
                            | a refused agreement is still signed, and a signed
                            | one can still be waiting to be read.
                            */
                            ?>
                            <td data-search="<?= htmlspecialchars(($c['contract_status'] ?? 'None') . ' ' . ($c['contract_review'] ?? '')) ?>">

                                <?php if (!$c['contract_id']): ?>

                                    <span class="sa-muted">None</span>

                                <?php elseif ($c['contract_status'] !== 'Signed'): ?>

                                    <span class="badge bg-secondary">Awaiting signature</span>

                                <?php elseif ($c['contract_review'] === 'Approved'): ?>

                                    <span class="badge bg-success">Accepted</span>

                                <?php elseif ($c['contract_review'] === 'Rejected'): ?>

                                    <span class="badge bg-danger">Refused</span>

                                <?php else: ?>

                                    <span class="badge bg-info">For review</span>

                                <?php endif; ?>

                                <?php if ($c['contract_id']): ?>
                                    <div class="sa-muted mt-1" style="font-size:12px;">
                                        <?= htmlspecialchars($c['contract_number']) ?>
                                    </div>
                                <?php endif; ?>

                            </td>

                            <td>
                                <button type="button" class="btn btn-sm sa-btn-soft btn-edit-company"
                                    data-company="<?= htmlspecialchars(json_encode($c), ENT_QUOTES, 'UTF-8') ?>">
                                    <i class="bi bi-pencil"></i>
                                </button>

                                <?php if ($c['contract_id']): ?>
                                    <button type="button" class="btn btn-sm sa-btn-soft btn-agreement"
                                        data-agreement="<?= htmlspecialchars(json_encode([
                                            'contract_id' => (int) $c['contract_id'],
                                            'number' => $c['contract_number'],
                                            'company' => $c['company_name'],
                                            'status' => $c['contract_status'],
                                            'review' => $c['contract_review'],
                                            'remarks' => $c['contract_remarks'],
                                            'hasIssued' => trim((string) $c['issued_template']) !== '',
                                            'hasSigned' => trim((string) $c['signed_contract']) !== '',
                                        ]), ENT_QUOTES, 'UTF-8') ?>"
                                        title="Service agreement">
                                        <i class="bi bi-file-earmark-text"></i>
                                    </button>
                                <?php endif; ?>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                </tbody>

            </table>

        </div>
    </div>
</div>


<!-- =========================================================
     ADD / EDIT COMPANY
========================================================== -->

<div class="modal fade" id="companyModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">

            <form method="POST" data-confirm="Save this company?"
                data-confirm-text="Check the status field before saving - suspending or deactivating a company locks its staff out of the system."
                data-confirm-button="Save">

                <div class="modal-header">
                    <h5 class="modal-title" id="companyModalTitle">Add Company</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">

                    <input type="hidden" name="company_id" id="company_id" value="0">

                    <div class="row g-3">

                        <div class="col-md-6">
                            <label class="form-label">Company Name <span class="text-danger">*</span></label>
                            <input type="text" name="company_name" id="company_name" class="form-control" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Owner Name <span class="text-danger">*</span></label>
                            <input type="text" name="owner_name" id="owner_name" class="form-control" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Business Type</label>
                            <select name="business_type" id="business_type" class="form-select">
                                <option>Retail Store</option>
                                <option>Wholesale</option>
                                <option>Supermarket</option>
                                <option>Convenience Store</option>
                                <option>Franchise</option>
                                <option>Other</option>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Status</label>
                            <select name="status" id="status" class="form-select">
                                <option>Pending</option>
                                <option>Active</option>
                                <option>Suspended</option>
                                <option>Inactive</option>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" id="email" class="form-control">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Phone</label>
                            <input type="text" name="phone" id="phone" class="form-control">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">TIN Number</label>
                            <input type="text" name="tin_number" id="tin_number" class="form-control">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">City</label>
                            <input type="text" name="city" id="city" class="form-control">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Province</label>
                            <input type="text" name="province" id="province" class="form-control">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Postal Code</label>
                            <input type="text" name="postal_code" id="postal_code" class="form-control">
                        </div>

                        <div class="col-12">
                            <label class="form-label">Address</label>
                            <textarea name="address" id="address" class="form-control" rows="2"></textarea>
                        </div>

                    </div>

                </div>

                <div class="modal-footer">
                    <button type="button" class="btn sa-btn-soft" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="saveCompany" class="btn sa-btn">
                        Save Company
                    </button>
                </div>

            </form>

        </div>
    </div>
</div>


<?php
/*
| The service agreement, from this end.
|
| Read what the business sent, then accept it or refuse it. The verdict
| form only appears once there is a signed copy to have a verdict about.
*/
?>
<div class="modal fade" id="agreementModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow">

            <div class="modal-header">
                <div>
                    <h5 class="modal-title">Service Agreement</h5>
                    <div class="sa-muted" id="agreementSub"></div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body">

                <div class="mb-3">
                    <span class="badge" id="agreementBadge"></span>
                </div>

                <div class="mb-3" id="agreementFiles"></div>

                <div id="agreementRemarksBox" class="alert alert-warning d-none">
                    <strong>What you told them</strong>
                    <div id="agreementRemarks"></div>
                </div>

                <div id="agreementWaiting" class="alert alert-secondary d-none">
                    This business has not sent a signed copy yet. There is nothing to
                    review until they do.
                </div>

                <form method="POST" id="agreementReviewForm" class="d-none"
                    data-confirm="Record this verdict?"
                    data-confirm-text="Refusing shows the business your reason, so write it for them to read."
                    data-confirm-button="Save verdict">

                    <input type="hidden" name="contract_id" id="agreement_contract_id">

                    <label class="form-label">Verdict <span class="text-danger">*</span></label>
                    <select name="review" id="agreement_review" class="form-select" required>
                        <option value="">Choose...</option>
                        <option value="Approved">Accept the signed agreement</option>
                        <option value="Rejected">Refuse it</option>
                    </select>

                    <label class="form-label mt-3">Reason</label>
                    <textarea name="review_remarks" id="agreement_remarks" rows="3" maxlength="2000"
                        class="form-control" placeholder="Required when refusing."></textarea>
                    <div class="form-text">The business is shown this on their subscription page.</div>

                    <button type="submit" name="reviewAgreement" class="btn sa-btn mt-3">
                        Save verdict
                    </button>

                </form>

            </div>

        </div>
    </div>
</div>


<script>
    document.addEventListener("DOMContentLoaded", function () {

        new DataTable("#companyTable", {
            order: [],
            columnDefs: [{ orderable: false, targets: -1 }],
            language: {
                searchPlaceholder: "Search companies...",
                emptyTable: "No companies yet",
                zeroRecords: "No matching companies"
            }
        });

        var modal = new bootstrap.Modal(document.getElementById("companyModal"));
        var form = document.querySelector("#companyModal form");

        document.getElementById("btnAddCompany").addEventListener("click", function () {
            form.reset();
            document.getElementById("company_id").value = "0";
            document.getElementById("companyModalTitle").textContent = "Add Company";
            modal.show();
        });

        document.addEventListener("click", function (e) {

            var btn = e.target.closest(".btn-edit-company");
            if (!btn) return;

            var c = JSON.parse(btn.dataset.company);

            document.getElementById("company_id").value = c.company_id;
            document.getElementById("company_name").value = c.company_name || "";
            document.getElementById("owner_name").value = c.owner_name || "";
            document.getElementById("business_type").value = c.business_type || "Retail Store";
            document.getElementById("status").value = c.status || "Pending";
            document.getElementById("email").value = c.email || "";
            document.getElementById("phone").value = c.phone || "";
            document.getElementById("tin_number").value = c.tin_number || "";
            document.getElementById("city").value = c.city || "";
            document.getElementById("province").value = c.province || "";
            document.getElementById("postal_code").value = c.postal_code || "";
            document.getElementById("address").value = c.address || "";

            document.getElementById("companyModalTitle").textContent = "Edit Company";
            modal.show();
        });


        /* =========================================
           THE SERVICE AGREEMENT
        ========================================== */

        var agreementModal = new bootstrap.Modal(document.getElementById("agreementModal"));

        function agreementLink(id, copy, label) {
            return '<a class="btn btn-sm sa-btn-soft me-2" target="_blank" rel="noopener" href="' +
                "company_contract_file.php?id=" + encodeURIComponent(id) +
                "&copy=" + encodeURIComponent(copy) + '">' +
                '<i class="bi bi-file-earmark-pdf me-1"></i>' + label + "</a>";
        }

        document.addEventListener("click", function (e) {

            var btn = e.target.closest(".btn-agreement");
            if (!btn) return;

            var a = JSON.parse(btn.dataset.agreement);

            document.getElementById("agreementSub").textContent = a.number + " — " + a.company;

            var badge = document.getElementById("agreementBadge");
            var tone = "secondary";
            var text = "Awaiting signature";

            if (a.status === "Signed") {
                if (a.review === "Approved") { tone = "success"; text = "Accepted"; }
                else if (a.review === "Rejected") { tone = "danger"; text = "Refused"; }
                else { tone = "info"; text = "For review"; }
            }

            badge.className = "badge bg-" + tone;
            badge.textContent = text;

            var links = "";
            if (a.hasIssued) links += agreementLink(a.contract_id, "blank", "What we issued");
            if (a.hasSigned) links += agreementLink(a.contract_id, "signed", "Their signed copy");

            document.getElementById("agreementFiles").innerHTML =
                links || '<span class="sa-muted">Nothing on file yet.</span>';

            var remarksBox = document.getElementById("agreementRemarksBox");
            document.getElementById("agreementRemarks").textContent = a.remarks || "";
            remarksBox.classList.toggle("d-none", !a.remarks);

            /* A verdict needs something to have a verdict about. */
            document.getElementById("agreementWaiting").classList.toggle("d-none", a.hasSigned);
            document.getElementById("agreementReviewForm").classList.toggle("d-none", !a.hasSigned);

            document.getElementById("agreement_contract_id").value = a.contract_id;
            document.getElementById("agreement_review").value =
                a.review === "Pending" ? "" : (a.review || "");
            document.getElementById("agreement_remarks").value = a.remarks || "";

            agreementModal.show();
        });


        /* Refusing without a reason is refused by the server; say so here. */
        var verdict = document.getElementById("agreement_review");
        var reason = document.getElementById("agreement_remarks");

        if (verdict && reason) {
            verdict.addEventListener("change", function () {
                var refusing = verdict.value === "Rejected";
                reason.required = refusing;
                reason.placeholder = refusing
                    ? "Required. The business reads this."
                    : "Optional.";
            });
        }

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
