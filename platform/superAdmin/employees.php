<?php

require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../includes/platform_roles.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/name_parts.php';
require_once __DIR__ . '/../includes/platform_contracts.php';
require_once __DIR__ . '/../includes/platform_employees.php';
requirePlatformAccess('employees');


/*
|--------------------------------------------------------------------------
| EMPLOYEES
|--------------------------------------------------------------------------
|
| HR's side of the platform: RetailCore's own staff.
|
| These are not the employees table. That one belongs to tenants, where a
| row is scoped to a company and a branch, and our own people have neither.
| Keeping them apart is what stops RetailCore staff appearing inside a
| customer's HR screens.
|
| Leaving is a status with a date, not a deletion. Somebody who resigned
| still worked here, and payroll and audit questions arrive after they go.
|
*/

/*
| The form is its own page now and redirects back here when it saves, so
| what it wants to say travels in the session rather than in the response
| it no longer renders. Taken once: a message that survives a refresh
| reappears for somebody who has already read it.
*/
$alert = $_SESSION['employee_alert'] ?? null;
unset($_SESSION['employee_alert']);

/*
| From includes/platform_employees.php.
|
| These were ['Finance', 'HR'] and ['Full-time', 'Part-time'] -- narrower
| than the column, and worse, "HR" is not one of its values at all. MySQL
| takes an unknown enum value as '' rather than refusing it, so anybody
| filed under HR was filed under no department, and nothing said so.
*/
$DEPARTMENTS = employeeDepartments();
$TYPES = employeeTypes();
$STATUSES = employeeStatuses();




if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['deleteEmployee'])) {

    $employeeId = (int) ($_POST['employee_id'] ?? 0);

    $stmt = $conn->prepare("SELECT employee_code, full_name, status FROM platform_employees WHERE employee_id = ? LIMIT 1");
    $stmt->bind_param("i", $employeeId);
    $stmt->execute();
    $employee = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$employee) {

        $alert = ['icon' => 'error', 'title' => 'Not Found', 'text' => 'That employee no longer exists.'];

    } elseif ($employee['status'] !== 'Active') {

        /*
        | Somebody who has left is exactly the record HR needs to keep.
        | Deleting is only for a row entered by mistake, which is still
        | Active because nobody ever worked under it.
        */
        $alert = [
            'icon' => 'error',
            'title' => 'Keep This Record',
            'text' => 'This person has left, so the record stays. Delete is only for a row added by mistake.'
        ];

    } else {

        $stmt = $conn->prepare("DELETE FROM platform_employees WHERE employee_id = ?");
        $stmt->bind_param("i", $employeeId);

        if ($stmt->execute()) {
            $stmt->close();
            auditLog(
                $conn,
                'Employee deleted',
                'employee',
                $employeeId,
                $employee['employee_code'] . ' ' . $employee['full_name']
            );
            $alert = [
                'icon' => 'success',
                'title' => 'Employee Deleted',
                'text' => $employee['full_name'] . ' has been removed.'
            ];
        } else {
            $error = $conn->error;
            $stmt->close();
            $alert = ['icon' => 'error', 'title' => 'Delete Failed', 'text' => $error];
        }
    }
}


/*
|--------------------------------------------------------------------------
| THE CONTRACT
|--------------------------------------------------------------------------
|
| Three things happen to a contract after it is drawn up: our copy goes
| out, their signed copy comes back, and HR says whether it is acceptable.
|
| Each one checks the contract still exists and is at the right point in
| its life, because a stale tab is a request like any other.
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sendContract'])) {

    $contractId = (int) ($_POST['contract_id'] ?? 0);
    $contract = null;

    $stmt = $conn->prepare("
        SELECT c.contract_id, c.contract_number, c.status, e.full_name
        FROM platform_employee_contracts c
        JOIN platform_employees e ON e.employee_id = c.employee_id
        WHERE c.contract_id = ? LIMIT 1
    ");
    $stmt->bind_param("i", $contractId);
    $stmt->execute();
    $contract = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$contract) {

        $alert = ['icon' => 'error', 'title' => 'Not Found', 'text' => 'That contract no longer exists.'];

    } else {

        [$path, $problem] = storeContractFile(
            $_FILES['company_contract'] ?? [],
            $contract['contract_number'],
            'company'
        );

        if ($problem !== null) {

            $alert = ['icon' => 'error', 'title' => 'Upload Failed', 'text' => $problem];

        } else {

            $uploadedBy = (int) ($_SESSION['user_id'] ?? 0) ?: null;

            $stmt = $conn->prepare("
                UPDATE platform_employee_contracts
                SET company_contract = ?, status = 'Sent', uploaded_by = ?
                WHERE contract_id = ?
            ");
            $stmt->bind_param("sii", $path, $uploadedBy, $contractId);
            $stmt->execute();
            $changed = $stmt->affected_rows;
            $stmt->close();

            if ($changed === 0) {

                $alert = [
                    'icon' => 'warning',
                    'title' => 'Nothing Changed',
                    'text' => 'That contract could not be updated. Please try again.'
                ];

            } else {

                auditLog(
                    $conn,
                    'Contract sent',
                    'contract',
                    $contractId,
                    $contract['contract_number'] . ' to ' . $contract['full_name']
                );

                $alert = [
                    'icon' => 'success',
                    'title' => 'Contract Sent',
                    'text' => $contract['contract_number'] . ' is attached and marked as sent to '
                        . $contract['full_name'] . '.'
                ];
            }
        }
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['receiveSignedContract'])) {

    $contractId = (int) ($_POST['contract_id'] ?? 0);

    $stmt = $conn->prepare("
        SELECT c.contract_id, c.contract_number, c.status, c.company_contract, e.full_name
        FROM platform_employee_contracts c
        JOIN platform_employees e ON e.employee_id = c.employee_id
        WHERE c.contract_id = ? LIMIT 1
    ");
    $stmt->bind_param("i", $contractId);
    $stmt->execute();
    $contract = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$contract) {

        $alert = ['icon' => 'error', 'title' => 'Not Found', 'text' => 'That contract no longer exists.'];

    } elseif (trim((string) $contract['company_contract']) === '') {

        /*
        | A signed copy of a contract nobody was ever sent is a contract
        | somebody signed somewhere else.
        */
        $alert = [
            'icon' => 'warning',
            'title' => 'Send It First',
            'text' => 'Attach and send the company copy before recording a signed one.'
        ];

    } else {

        [$path, $problem] = storeContractFile(
            $_FILES['signed_contract'] ?? [],
            $contract['contract_number'],
            'signed'
        );

        if ($problem !== null) {

            $alert = ['icon' => 'error', 'title' => 'Upload Failed', 'text' => $problem];

        } else {

            /*
            | A new signed copy puts the review back to Pending. HR approved
            | the document they were shown, not whatever replaces it.
            */
            $stmt = $conn->prepare("
                UPDATE platform_employee_contracts
                SET signed_contract = ?, status = 'Signed',
                    hr_review = 'Pending', hr_remarks = NULL
                WHERE contract_id = ?
            ");
            $stmt->bind_param("si", $path, $contractId);
            $stmt->execute();
            $changed = $stmt->affected_rows;
            $stmt->close();

            if ($changed === 0) {

                $alert = [
                    'icon' => 'warning',
                    'title' => 'Nothing Changed',
                    'text' => 'That contract could not be updated. Please try again.'
                ];

            } else {

                auditLog(
                    $conn,
                    'Signed contract received',
                    'contract',
                    $contractId,
                    $contract['contract_number'] . ' from ' . $contract['full_name']
                );

                $alert = [
                    'icon' => 'success',
                    'title' => 'Signed Copy Recorded',
                    'text' => $contract['contract_number'] . ' is signed and waiting for HR review.'
                ];
            }
        }
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reviewContract'])) {

    $contractId = (int) ($_POST['contract_id'] ?? 0);
    $verdict = $_POST['hr_review'] ?? '';
    $remarks = trim((string) ($_POST['hr_remarks'] ?? ''));

    $stmt = $conn->prepare("
        SELECT c.contract_id, c.contract_number, c.status, c.signed_contract, e.full_name
        FROM platform_employee_contracts c
        JOIN platform_employees e ON e.employee_id = c.employee_id
        WHERE c.contract_id = ? LIMIT 1
    ");
    $stmt->bind_param("i", $contractId);
    $stmt->execute();
    $contract = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$contract) {

        $alert = ['icon' => 'error', 'title' => 'Not Found', 'text' => 'That contract no longer exists.'];

    } elseif (!in_array($verdict, ['Approved', 'Rejected'], true)) {

        $alert = [
            'icon' => 'error',
            'title' => 'Choose a Verdict',
            'text' => 'Please approve or reject the signed contract.'
        ];

    } elseif (trim((string) $contract['signed_contract']) === '') {

        $alert = [
            'icon' => 'warning',
            'title' => 'Nothing to Review',
            'text' => 'There is no signed copy on record yet.'
        ];

    } elseif ($verdict === 'Rejected' && $remarks === '') {

        /* Rejecting without saying why leaves nobody anything to act on. */
        $alert = [
            'icon' => 'error',
            'title' => 'Reason Required',
            'text' => 'Say what is wrong with the signed contract.'
        ];

    } elseif (mb_strlen($remarks) > 2000) {

        $alert = [
            'icon' => 'error',
            'title' => 'Too Long',
            'text' => 'Remarks cannot be longer than 2000 characters.'
        ];

    } else {

        $remarksValue = $remarks === '' ? null : $remarks;

        $stmt = $conn->prepare("
            UPDATE platform_employee_contracts
            SET hr_review = ?, hr_remarks = ?
            WHERE contract_id = ?
        ");
        $stmt->bind_param("ssi", $verdict, $remarksValue, $contractId);
        $stmt->execute();
        $changed = $stmt->affected_rows;
        $stmt->close();

        if ($changed === 0) {

            $alert = [
                'icon' => 'warning',
                'title' => 'Nothing Changed',
                'text' => 'That verdict was already on record.'
            ];

        } else {

            auditLog(
                $conn,
                'Contract ' . strtolower($verdict),
                'contract',
                $contractId,
                $contract['contract_number'] . ' - ' . $contract['full_name']
                . ($remarks !== '' ? ': ' . mb_substr($remarks, 0, 80) : '')
            );

            $alert = [
                'icon' => 'success',
                'title' => 'Contract ' . $verdict,
                'text' => $contract['contract_number'] . ' has been ' . strtolower($verdict) . '.'
            ];
        }
    }
}


include("sAdminHeader.php");

$employees = $conn->query("
    SELECT e.*, u.username, u.role AS login_role,
           c.contract_id, c.contract_number, c.contract_title,
           c.company_contract, c.signed_contract,
           c.status AS contract_status, c.hr_review, c.hr_remarks
    FROM platform_employees e
    LEFT JOIN users u ON u.user_id = e.user_id
    LEFT JOIN platform_employee_contracts c ON c.employee_id = e.employee_id
    ORDER BY FIELD(e.status, 'Active', 'On Leave', 'Resigned', 'Terminated'),
             e.department, e.full_name
");

$counts = $conn->query("
    SELECT
        COUNT(*) AS total,
        COALESCE(SUM(status = 'Active'), 0)   AS active,
        COALESCE(SUM(status = 'On Leave'), 0) AS on_leave,
        COALESCE(SUM(status IN ('Resigned','Terminated')), 0) AS left_count,
        COUNT(DISTINCT department) AS departments
    FROM platform_employees
")->fetch_assoc();

/* Platform logins that no employee record points at yet. */
$logins = $conn->query("
    SELECT u.user_id, u.fullname, u.username, u.role
    FROM users u
    WHERE u.company_id IS NULL
    ORDER BY u.fullname
");

?>

<div class="sa-page-head">
    <div>
        <h3 class="sa-page-title">Employees</h3>
        <p class="sa-page-sub">
            RetailCore's own staff. Tenants' employees live in their own company.
        </p>
    </div>
    <?php
    /*
    | A page, not a dialog. The record now carries personal details,
    | employment details, an optional login account and a photograph, and a
    | dialog that long stops being a dialog: it scrolls, it cannot be
    | linked to, and a mistake halfway down is hidden behind what you are
    | looking at.
    */
    ?>
    <a href="employee_form.php" class="btn sa-btn">
        <i class="bi bi-plus-lg me-1"></i> Add Employee
    </a>
</div>


<div class="row g-3 mb-4">

    <div class="col-md-3">
        <div class="sa-stat p-3 d-flex justify-content-between align-items-center">
            <div>
                <div class="sa-stat-label">Active</div>
                <div class="sa-stat-value"><?= number_format((int) $counts['active']) ?></div>
                <div class="sa-muted"><?= number_format((int) $counts['total']) ?> on record</div>
            </div>
            <div class="sa-stat-icon sa-tone-ok"><i class="bi bi-person-check"></i></div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="sa-stat p-3 d-flex justify-content-between align-items-center">
            <div>
                <div class="sa-stat-label">On Leave</div>
                <div class="sa-stat-value"><?= number_format((int) $counts['on_leave']) ?></div>
                <div class="sa-muted">Away right now</div>
            </div>
            <div class="sa-stat-icon sa-tone-warn"><i class="bi bi-person-dash"></i></div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="sa-stat p-3 d-flex justify-content-between align-items-center">
            <div>
                <div class="sa-stat-label">Departments</div>
                <div class="sa-stat-value"><?= number_format((int) $counts['departments']) ?></div>
                <div class="sa-muted">With somebody in them</div>
            </div>
            <div class="sa-stat-icon sa-tone-accent"><i class="bi bi-diagram-2"></i></div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="sa-stat p-3 d-flex justify-content-between align-items-center">
            <div>
                <div class="sa-stat-label">Left</div>
                <div class="sa-stat-value"><?= number_format((int) $counts['left_count']) ?></div>
                <div class="sa-muted">Resigned or terminated</div>
            </div>
            <div class="sa-stat-icon sa-tone-navy"><i class="bi bi-box-arrow-right"></i></div>
        </div>
    </div>

</div>


<div class="sa-panel">

    <div class="sa-panel-head">
        <span>Staff</span>
        <div class="d-flex align-items-center gap-2">
            <label class="sa-muted mb-0" for="statusFilter">Status</label>
            <select id="statusFilter" class="form-select form-select-sm" style="width:auto;" data-sa-skip>
                <option value="">All</option>
                <?php foreach ($STATUSES as $state): ?>
                    <option value="<?= htmlspecialchars($state) ?>"><?= htmlspecialchars($state) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <div class="table-responsive">

        <table id="employeeTable" class="table table-hover sa-table" style="width:100%">

            <thead>
                <tr>
                    <th class="ps-4" style="width:110px;">Code</th>
                    <th>Name</th>
                    <th style="width:170px;">Position</th>
                    <th style="width:130px;">Department</th>
                    <th style="width:120px;">Type</th>
                    <th style="width:140px;">Status</th>
                    <th style="width:140px;">Hired</th>
                    <th style="width:170px;">Contract</th>
                    <th class="pe-4" style="width:120px;">Action</th>
                </tr>
            </thead>

            <tbody>

                <?php while ($employee = $employees->fetch_assoc()): ?>

                    <?php
                    $statusBadge = 'bg-secondary';
                    if ($employee['status'] === 'Active') {
                        $statusBadge = 'bg-success';
                    } elseif ($employee['status'] === 'On Leave') {
                        $statusBadge = 'bg-warning text-dark';
                    } elseif ($employee['status'] === 'Terminated') {
                        $statusBadge = 'bg-danger';
                    }
                    ?>

                    <tr>

                        <td class="ps-4 sa-name"><?= htmlspecialchars($employee['employee_code']) ?></td>

                        <td>
                            <div class="sa-name"><?= htmlspecialchars($employee['full_name']) ?></div>
                            <div class="sa-muted"><?= htmlspecialchars($employee['work_email']) ?></div>
                            <?php if ($employee['username']): ?>
                                <div class="sa-muted">
                                    <i class="bi bi-key me-1"></i><?= htmlspecialchars($employee['username']) ?>
                                </div>
                            <?php endif; ?>
                        </td>

                        <td><?= htmlspecialchars($employee['position']) ?></td>

                        <td>
                            <span class="badge bg-light text-dark border">
                                <?= htmlspecialchars($employee['department']) ?>
                            </span>
                        </td>

                        <td class="sa-muted"><?= htmlspecialchars($employee['employment_type']) ?></td>

                        <td data-search="<?= htmlspecialchars($employee['status']) ?>">
                            <span class="badge <?= $statusBadge ?>">
                                <?= htmlspecialchars($employee['status']) ?>
                            </span>
                            <?php if ($employee['date_left']): ?>
                                <div class="sa-muted">
                                    Left <?= htmlspecialchars(date('M d, Y', strtotime($employee['date_left']))) ?>
                                </div>
                            <?php endif; ?>
                        </td>

                        <td data-order="<?= htmlspecialchars($employee['date_hired']) ?>" class="sa-muted">
                            <?= htmlspecialchars(date('M d, Y', strtotime($employee['date_hired']))) ?>
                        </td>

                        <?php
                        /*
                        | The contract column.
                        |
                        | Two facts, not one: where the document has got to,
                        | and what HR made of the signed copy. A contract can
                        | be Signed and still be waiting on review, and a
                        | rejected one is still signed.
                        |
                        | Somebody hired before contracts existed has no row.
                        | That is said plainly rather than shown as Pending,
                        | which would claim a contract that was never drawn up.
                        */
                        ?>
                        <td
                            data-search="<?= htmlspecialchars(($employee['contract_status'] ?? 'None') . ' ' . ($employee['hr_review'] ?? '')) ?>">

                            <?php if (!$employee['contract_id']): ?>

                                <span class="sa-muted">No contract</span>

                            <?php else: ?>

                                <span class="badge bg-<?= contractStatusTone($employee['contract_status']) ?>">
                                    <?= htmlspecialchars($employee['contract_status']) ?>
                                </span>

                                <?php if ($employee['contract_status'] === 'Signed'): ?>
                                    <span class="badge bg-<?= contractStatusTone($employee['hr_review']) ?> ms-1">
                                        <?= htmlspecialchars($employee['hr_review'] === 'Pending' ? 'For review' : $employee['hr_review']) ?>
                                    </span>
                                <?php endif; ?>

                                <div class="sa-muted mt-1" style="font-size:12px;">
                                    <?= htmlspecialchars($employee['contract_number']) ?>
                                </div>

                            <?php endif; ?>

                        </td>

                        <td class="pe-4">

                            <?php if ($employee['contract_id']): ?>
                                <button class="btn btn-sm sa-btn-soft btn-contract" data-contract="<?= htmlspecialchars(json_encode([
                                    'contract_id' => (int) $employee['contract_id'],
                                    'number' => $employee['contract_number'],
                                    'title' => $employee['contract_title'],
                                    'employee' => $employee['full_name'],
                                    'status' => $employee['contract_status'],
                                    'review' => $employee['hr_review'],
                                    'remarks' => $employee['hr_remarks'],
                                    'hasCompany' => trim((string) $employee['company_contract']) !== '',
                                    'hasSigned' => trim((string) $employee['signed_contract']) !== '',
                                ]), ENT_QUOTES, 'UTF-8') ?>" title="Contract">
                                    <i class="bi bi-file-earmark-text"></i>
                                </button>
                            <?php endif; ?>


                            <a href="employee_form.php?id=<?= (int) $employee['employee_id'] ?>"
                                class="btn btn-sm sa-btn-soft" title="Edit">
                                <i class="bi bi-pencil"></i>
                            </a>

                            <form method="POST" class="d-inline"
                                data-confirm="Delete <?= htmlspecialchars($employee['full_name'], ENT_QUOTES) ?>?"
                                data-confirm-text="Use this only for a row added by mistake. Somebody who left should be marked Resigned instead."
                                data-confirm-button="Delete" data-confirm-danger>
                                <input type="hidden" name="employee_id" value="<?= (int) $employee['employee_id'] ?>">
                                <button type="submit" name="deleteEmployee" class="btn btn-sm sa-btn-soft text-danger">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>

                        </td>
                    </tr>

                <?php endwhile; ?>

            </tbody>

        </table>

    </div>

</div>


<?php
/*
|--------------------------------------------------------------------------
| THE CONTRACT
|--------------------------------------------------------------------------
|
| One modal, three forms, shown a step at a time. Which step is on screen
| is decided by what the contract already has, not by a tab the reader has
| to find: a contract with nothing attached shows the send step, one that
| has gone out shows the signed-copy step, one that is back shows the
| review.
|
| Each form posts on its own and confirms on its own, so the shared guard
| in sadmin-forms.js has nothing to skip.
*/
?>
<div class="modal fade" id="contractModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow">

            <div class="modal-header">
                <div>
                    <h5 class="modal-title" id="contractTitle">Employment Contract</h5>
                    <div class="sa-muted" id="contractSub"></div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body">

                <div class="d-flex align-items-center gap-2 mb-3">
                    <span class="badge" id="contractStatusBadge"></span>
                    <span class="badge" id="contractReviewBadge"></span>
                </div>

                <div class="mb-3" id="contractFiles"></div>

                <div id="contractRemarksBox" class="alert alert-warning d-none">
                    <strong>HR remarks</strong>
                    <div id="contractRemarks"></div>
                </div>


                <!-- STEP 1 -- attach our copy and send it -->

                <form method="POST" enctype="multipart/form-data" id="sendContractForm" class="d-none"
                    data-confirm="Send this contract?"
                    data-confirm-text="The attached PDF becomes the company copy and the contract is marked as sent."
                    data-confirm-button="Send">

                    <input type="hidden" name="contract_id" id="send_contract_id">

                    <label class="form-label">Company copy <span class="text-danger">*</span></label>
                    <input type="file" name="company_contract" accept="application/pdf" required class="form-control">
                    <div class="form-text">PDF, up to 5 MB.</div>

                    <button type="submit" name="sendContract" class="btn sa-btn mt-3">
                        <i class="bi bi-send me-1"></i> Attach and mark as sent
                    </button>

                </form>


                <!-- STEP 2 -- record the signed copy that came back -->

                <form method="POST" enctype="multipart/form-data" id="signedContractForm" class="d-none"
                    data-confirm="Record the signed contract?"
                    data-confirm-text="The contract is marked as signed and goes to HR for review."
                    data-confirm-button="Record">

                    <input type="hidden" name="contract_id" id="signed_contract_id">

                    <label class="form-label">Signed copy <span class="text-danger">*</span></label>
                    <input type="file" name="signed_contract" accept="application/pdf" required class="form-control">
                    <div class="form-text">
                        PDF, up to 5 MB. Replacing a signed copy sends it back for review.
                    </div>

                    <button type="submit" name="receiveSignedContract" class="btn sa-btn mt-3">
                        <i class="bi bi-check2-square me-1"></i> Record signed copy
                    </button>

                </form>


                <!-- STEP 3 -- HR's verdict on what came back -->

                <form method="POST" id="reviewContractForm" class="d-none" data-confirm="Record this verdict?"
                    data-confirm-text="It is written on the contract and into the audit log."
                    data-confirm-button="Save verdict">

                    <input type="hidden" name="contract_id" id="review_contract_id">

                    <label class="form-label">HR review <span class="text-danger">*</span></label>
                    <select name="hr_review" id="hr_review" class="form-select" required>
                        <option value="">Choose...</option>
                        <option value="Approved">Approve</option>
                        <option value="Rejected">Reject</option>
                    </select>

                    <label class="form-label mt-3">Remarks</label>
                    <textarea name="hr_remarks" id="hr_remarks" rows="3" maxlength="2000" class="form-control"
                        placeholder="Required when rejecting."></textarea>
                    <div class="form-text">
                        Rejecting without a reason leaves nobody anything to act on.
                    </div>

                    <button type="submit" name="reviewContract" class="btn sa-btn mt-3">
                        Save verdict
                    </button>

                </form>

            </div>

        </div>
    </div>
</div>


<script>
    document.addEventListener("DOMContentLoaded", function () {

        var table = new DataTable("#employeeTable", {
            order: [],
            columnDefs: [{ orderable: false, targets: -1 }],
            language: {
                searchPlaceholder: "Search name, position or email...",
                emptyTable: "<div class=\"sa-empty\">"
                    + '<i class="bi bi-person-vcard d-block mb-2" style="font-size:28px;"></i>'
                    + "No employees on record yet."
                    + "</div>",
                zeroRecords: "No matching employees"
            }
        });

        document.getElementById("statusFilter").addEventListener("change", function () {
            table.column(5).search(this.value ? "^" + this.value + "$" : "", true, false).draw();
        });

        /* =========================================
           THE CONTRACT

           Which step is shown is decided by what the contract already has,
           so the reader is offered the one thing that can happen next
           rather than three forms to choose between.
        ========================================== */

        var contractModal = new bootstrap.Modal(document.getElementById("contractModal"));

        var tones = {
            Pending: "secondary",
            Sent: "info",
            Signed: "success",
            Approved: "success",
            Rejected: "danger"
        };

        function badge(el, text, tone) {
            el.className = "badge bg-" + (tones[tone] || "secondary");
            el.textContent = text;
            el.classList.toggle("d-none", !text);
        }

        function fileLink(id, copy, label) {
            return '<a class="btn btn-sm sa-btn-soft me-2" target="_blank" rel="noopener" href="' +
                "contract_file.php?id=" + encodeURIComponent(id) +
                "&copy=" + encodeURIComponent(copy) + '">' +
                '<i class="bi bi-file-earmark-pdf me-1"></i>' + label + "</a>";
        }

        document.addEventListener("click", function (e) {

            var btn = e.target.closest(".btn-contract");
            if (!btn) return;

            var c = JSON.parse(btn.dataset.contract);

            document.getElementById("contractTitle").textContent = c.title || "Employment Contract";
            document.getElementById("contractSub").textContent = c.number + " — " + c.employee;

            badge(document.getElementById("contractStatusBadge"), c.status, c.status);
            badge(
                document.getElementById("contractReviewBadge"),
                c.status === "Signed" ? (c.review === "Pending" ? "For review" : c.review) : "",
                c.review
            );

            /* What is already on file, if anything. */
            var links = "";
            if (c.hasCompany) links += fileLink(c.contract_id, "company", "Company copy");
            if (c.hasSigned) links += fileLink(c.contract_id, "signed", "Signed copy");

            document.getElementById("contractFiles").innerHTML =
                links || '<span class="sa-muted">Nothing attached yet.</span>';

            var remarksBox = document.getElementById("contractRemarksBox");
            document.getElementById("contractRemarks").textContent = c.remarks || "";
            remarksBox.classList.toggle("d-none", !c.remarks);

            /* One step at a time. */
            var send = document.getElementById("sendContractForm");
            var signed = document.getElementById("signedContractForm");
            var review = document.getElementById("reviewContractForm");

            send.classList.toggle("d-none", c.hasCompany);
            signed.classList.toggle("d-none", !c.hasCompany);
            review.classList.toggle("d-none", !c.hasSigned);

            document.getElementById("send_contract_id").value = c.contract_id;
            document.getElementById("signed_contract_id").value = c.contract_id;
            document.getElementById("review_contract_id").value = c.contract_id;

            document.getElementById("hr_review").value =
                c.review === "Pending" ? "" : (c.review || "");
            document.getElementById("hr_remarks").value = c.remarks || "";

            contractModal.show();
        });


        /*
        | Rejecting without a reason is refused by the server. Saying so
        | here means the reader is told before the page reloads.
        */
        var reviewSelect = document.getElementById("hr_review");
        var reviewRemarks = document.getElementById("hr_remarks");

        if (reviewSelect && reviewRemarks) {
            reviewSelect.addEventListener("change", function () {
                var rejecting = reviewSelect.value === "Rejected";
                reviewRemarks.required = rejecting;
                reviewRemarks.placeholder = rejecting
                    ? "Required. Say what is wrong with the signed contract."
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