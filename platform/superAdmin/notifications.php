<?php

require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../includes/platform_roles.php';
require_once __DIR__ . '/../includes/audit.php';
requirePlatformAccess('notifications');


/*
|--------------------------------------------------------------------------
| NOTIFICATIONS
|--------------------------------------------------------------------------
|
| Announcements the platform sends to tenants: maintenance windows, policy
| changes, a new marketplace app.
|
| A notice is written as a Draft and stays invisible until it is Published,
| so nothing reaches customers by half-finished accident. Archived is the
| way to take one down without destroying the record of having sent it;
| deleting is for a draft that was never sent.
|
*/

$alert = null;

$AUDIENCES = ['All Companies', 'Single Company', 'By Plan'];
$SEVERITIES = ['Info', 'Warning', 'Critical'];
$STATUSES = ['Draft', 'Published', 'Archived'];


/* Returns the first problem as a sentence, or null when the notice is usable. */
function notificationProblem(mysqli $conn, array $post, array $audiences, array $severities, array $statuses): ?string
{
    if (trim($post['title'] ?? '') === '') {
        return 'Title is required.';
    }

    if (strlen(trim($post['body'] ?? '')) < 10) {
        return 'Please write at least 10 characters of message.';
    }

    $audience = $post['audience'] ?? '';

    if (!in_array($audience, $audiences, true)) {
        return 'Please choose an audience.';
    }

    if ($audience === 'Single Company') {
        $companyId = (int) ($post['company_id'] ?? 0);

        if ($companyId <= 0) {
            return 'Please choose the company this goes to.';
        }

        $found = $conn->query("SELECT company_id FROM company WHERE company_id = " . $companyId . " LIMIT 1");

        if (!$found || $found->num_rows === 0) {
            return 'That company no longer exists.';
        }
    }

    if ($audience === 'By Plan') {
        $planId = (int) ($post['plan_id'] ?? 0);

        if ($planId <= 0) {
            return 'Please choose the plan this goes to.';
        }

        $found = $conn->query("SELECT plan_id FROM subscription_plans WHERE plan_id = " . $planId . " LIMIT 1");

        if (!$found || $found->num_rows === 0) {
            return 'That plan no longer exists.';
        }
    }

    if (!in_array($post['severity'] ?? '', $severities, true)) {
        return 'Please choose a severity.';
    }

    if (!in_array($post['status'] ?? '', $statuses, true)) {
        return 'Please choose a status.';
    }

    $publishAt = trim($post['publish_at'] ?? '');
    $expiresAt = trim($post['expires_at'] ?? '');

    foreach (['Show from' => $publishAt, 'Hide after' => $expiresAt] as $label => $value) {
        if ($value !== '' && strtotime($value) === false) {
            return $label . ' must be a real date and time.';
        }
    }

    if ($publishAt !== '' && $expiresAt !== '' && strtotime($expiresAt) <= strtotime($publishAt)) {
        return 'Hide after must come later than show from.';
    }

    return null;
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['saveNotification'])) {

    $notificationId = (int) ($_POST['notification_id'] ?? 0);
    $problem = notificationProblem($conn, $_POST, $AUDIENCES, $SEVERITIES, $STATUSES);

    /* An UPDATE that matches nothing still succeeds, so an edit of a notice
       somebody else deleted has to be caught here. */
    if ($problem === null && $notificationId > 0
        && !$conn->query("SELECT notification_id FROM platform_notifications WHERE notification_id = " . $notificationId . " LIMIT 1")->num_rows) {
        $problem = 'That notice no longer exists.';
    }

    if ($problem !== null) {

        $alert = ['icon' => 'error', 'title' => 'Missing Details', 'text' => $problem];

    } else {

        $title = trim($_POST['title']);
        $body = trim($_POST['body']);
        $audience = $_POST['audience'];
        $severity = $_POST['severity'];
        $status = $_POST['status'];

        /* Only the chosen audience keeps its target; the other is cleared
           so a notice can never carry a stale company or plan. */
        $companyId = $audience === 'Single Company' ? (int) $_POST['company_id'] : null;
        $planId = $audience === 'By Plan' ? (int) $_POST['plan_id'] : null;

        $publishAt = trim($_POST['publish_at'] ?? '');
        $expiresAt = trim($_POST['expires_at'] ?? '');
        $publishAt = $publishAt === '' ? null : date('Y-m-d H:i:s', strtotime($publishAt));
        $expiresAt = $expiresAt === '' ? null : date('Y-m-d H:i:s', strtotime($expiresAt));

        $actor = (int) ($_SESSION['user_id'] ?? 0);

        if ($notificationId > 0) {
            $stmt = $conn->prepare("
                UPDATE platform_notifications SET
                    title = ?, body = ?, audience = ?, company_id = ?, plan_id = ?,
                    severity = ?, status = ?, publish_at = ?, expires_at = ?
                WHERE notification_id = ?
            ");
            $stmt->bind_param(
                "sssiissssi",
                $title, $body, $audience, $companyId, $planId,
                $severity, $status, $publishAt, $expiresAt, $notificationId
            );
            $done = 'updated';
        } else {
            $stmt = $conn->prepare("
                INSERT INTO platform_notifications
                    (title, body, audience, company_id, plan_id, severity, status,
                     publish_at, expires_at, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param(
                "sssiissssi",
                $title, $body, $audience, $companyId, $planId,
                $severity, $status, $publishAt, $expiresAt, $actor
            );
            $done = 'created';
        }

        if ($stmt->execute()) {
            $savedId = $notificationId > 0 ? $notificationId : (int) $conn->insert_id;
            $stmt->close();

            auditLog($conn, 'Notification ' . $done, 'notification', $savedId,
                     $title . ' (' . $status . ', ' . $audience . ')');

            $alert = ['icon' => 'success', 'title' => 'Notice Saved',
                      'text' => $title . ' has been ' . $done . ' as ' . $status . '.'];
        } else {
            $error = $conn->error;
            $stmt->close();
            $alert = ['icon' => 'error', 'title' => 'Save Failed', 'text' => $error];
        }
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['setStatus'])) {

    $notificationId = (int) ($_POST['notification_id'] ?? 0);
    $status = $_POST['status'] ?? '';

    $notice = null;

    if ($notificationId > 0) {
        $stmt = $conn->prepare("SELECT notification_id, title, status FROM platform_notifications WHERE notification_id = ? LIMIT 1");
        $stmt->bind_param("i", $notificationId);
        $stmt->execute();
        $notice = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }

    if (!$notice) {
        $alert = ['icon' => 'error', 'title' => 'Not Found', 'text' => 'That notice no longer exists.'];
    } elseif (!in_array($status, $STATUSES, true)) {
        $alert = ['icon' => 'error', 'title' => 'Missing Details', 'text' => 'Please choose a status.'];
    } else {
        $stmt = $conn->prepare("UPDATE platform_notifications SET status = ? WHERE notification_id = ?");
        $stmt->bind_param("si", $status, $notificationId);

        if ($stmt->execute()) {
            $stmt->close();
            auditLog($conn, 'Notification ' . strtolower($status), 'notification', $notificationId,
                     $notice['title'] . ' moved from ' . $notice['status'] . ' to ' . $status);
            $alert = ['icon' => 'success', 'title' => 'Status Changed',
                      'text' => $notice['title'] . ' is now ' . $status . '.'];
        } else {
            $error = $conn->error;
            $stmt->close();
            $alert = ['icon' => 'error', 'title' => 'Update Failed', 'text' => $error];
        }
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['deleteNotification'])) {

    $notificationId = (int) ($_POST['notification_id'] ?? 0);

    $stmt = $conn->prepare("SELECT title, status FROM platform_notifications WHERE notification_id = ? LIMIT 1");
    $stmt->bind_param("i", $notificationId);
    $stmt->execute();
    $notice = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$notice) {

        $alert = ['icon' => 'error', 'title' => 'Not Found', 'text' => 'That notice no longer exists.'];

    } elseif ($notice['status'] === 'Published') {

        /* Taking a live notice off the wall is Archive, not Delete. */
        $alert = ['icon' => 'error', 'title' => 'Still Published',
                  'text' => 'Archive it first. A notice customers have seen should keep its record.'];

    } else {

        $stmt = $conn->prepare("DELETE FROM platform_notifications WHERE notification_id = ?");
        $stmt->bind_param("i", $notificationId);

        if ($stmt->execute()) {
            $stmt->close();
            auditLog($conn, 'Notification deleted', 'notification', $notificationId, $notice['title']);
            $alert = ['icon' => 'success', 'title' => 'Notice Deleted', 'text' => 'The draft has been removed.'];
        } else {
            $error = $conn->error;
            $stmt->close();
            $alert = ['icon' => 'error', 'title' => 'Delete Failed', 'text' => $error];
        }
    }
}


include("sAdminHeader.php");

$notices = $conn->query("
    SELECT n.*, c.company_name, sp.plan_name, u.fullname AS author
    FROM platform_notifications n
    LEFT JOIN company c ON c.company_id = n.company_id
    LEFT JOIN subscription_plans sp ON sp.plan_id = n.plan_id
    LEFT JOIN users u ON u.user_id = n.created_by
    ORDER BY FIELD(n.status, 'Published', 'Draft', 'Archived'), n.updated_at DESC
");

$counts = $conn->query("
    SELECT
        COALESCE(SUM(status = 'Published'), 0) AS published,
        COALESCE(SUM(status = 'Draft'), 0)     AS drafts,
        COALESCE(SUM(status = 'Archived'), 0)  AS archived,
        COALESCE(SUM(status = 'Published' AND expires_at IS NOT NULL AND expires_at < NOW()), 0) AS expired
    FROM platform_notifications
")->fetch_assoc();

$companies = $conn->query("SELECT company_id, company_name, company_code FROM company ORDER BY company_name");
$plans = $conn->query("SELECT plan_id, plan_name FROM subscription_plans ORDER BY plan_order, plan_id");

?>

<div class="sa-page-head">
    <div>
        <h3 class="sa-page-title">Notifications</h3>
        <p class="sa-page-sub">
            Announcements sent to tenants. A notice stays a draft until you publish it.
        </p>
    </div>
    <button class="btn sa-btn" id="btnAddNotice">
        <i class="bi bi-plus-lg me-1"></i> New Notice
    </button>
</div>


<div class="row g-3 mb-4">

    <div class="col-md-3">
        <div class="sa-stat p-3 d-flex justify-content-between align-items-center">
            <div>
                <div class="sa-stat-label">Published</div>
                <div class="sa-stat-value"><?= number_format((int) $counts['published']) ?></div>
                <div class="sa-muted">Visible to tenants</div>
            </div>
            <div class="sa-stat-icon sa-tone-ok"><i class="bi bi-megaphone"></i></div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="sa-stat p-3 d-flex justify-content-between align-items-center">
            <div>
                <div class="sa-stat-label">Drafts</div>
                <div class="sa-stat-value"><?= number_format((int) $counts['drafts']) ?></div>
                <div class="sa-muted">Not sent yet</div>
            </div>
            <div class="sa-stat-icon sa-tone-warn"><i class="bi bi-pencil-square"></i></div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="sa-stat p-3 d-flex justify-content-between align-items-center">
            <div>
                <div class="sa-stat-label">Past Their End Date</div>
                <div class="sa-stat-value"><?= number_format((int) $counts['expired']) ?></div>
                <div class="sa-muted">Published but expired</div>
            </div>
            <div class="sa-stat-icon sa-tone-bad"><i class="bi bi-calendar-x"></i></div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="sa-stat p-3 d-flex justify-content-between align-items-center">
            <div>
                <div class="sa-stat-label">Archived</div>
                <div class="sa-stat-value"><?= number_format((int) $counts['archived']) ?></div>
                <div class="sa-muted">Taken down</div>
            </div>
            <div class="sa-stat-icon sa-tone-navy"><i class="bi bi-archive"></i></div>
        </div>
    </div>

</div>


<div class="sa-panel">

    <div class="sa-panel-head">
        <span>Notices</span>
        <div class="d-flex align-items-center gap-2">
            <label class="sa-muted mb-0" for="noticeFilter">Status</label>
            <select id="noticeFilter" class="form-select form-select-sm" style="width:auto;" data-sa-skip>
                <option value="">All</option>
                <?php foreach ($STATUSES as $state): ?>
                    <option value="<?= htmlspecialchars($state) ?>"><?= htmlspecialchars($state) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <div class="table-responsive">

        <table id="noticeTable" class="table table-hover sa-table" style="width:100%">

            <thead>
                <tr>
                    <th class="ps-4">Notice</th>
                    <th style="width:210px;">Audience</th>
                    <th style="width:110px;">Severity</th>
                    <th style="width:190px;">Window</th>
                    <th style="width:120px;">Status</th>
                    <th class="pe-4" style="width:160px;">Action</th>
                </tr>
            </thead>

            <tbody>

                <?php while ($notice = $notices->fetch_assoc()): ?>

                    <?php
                    $severityBadge = 'bg-light text-dark border';
                    if ($notice['severity'] === 'Warning') {
                        $severityBadge = 'bg-warning text-dark';
                    } elseif ($notice['severity'] === 'Critical') {
                        $severityBadge = 'bg-danger';
                    }

                    $statusBadge = 'bg-secondary';
                    if ($notice['status'] === 'Published') {
                        $statusBadge = 'bg-success';
                    } elseif ($notice['status'] === 'Draft') {
                        $statusBadge = 'bg-warning text-dark';
                    }

                    if ($notice['audience'] === 'Single Company') {
                        $target = $notice['company_name'] ?: 'Company removed';
                    } elseif ($notice['audience'] === 'By Plan') {
                        $target = $notice['plan_name'] ?: 'Plan removed';
                    } else {
                        $target = 'Everyone';
                    }

                    $expired = $notice['status'] === 'Published'
                        && !empty($notice['expires_at'])
                        && strtotime($notice['expires_at']) < time();
                    ?>

                    <tr>

                        <td class="ps-4">
                            <div class="sa-name"><?= htmlspecialchars($notice['title']) ?></div>
                            <div class="sa-muted">
                                <?= htmlspecialchars(mb_strimwidth($notice['body'], 0, 90, '...')) ?>
                            </div>
                        </td>

                        <td>
                            <div><?= htmlspecialchars($notice['audience']) ?></div>
                            <div class="sa-muted"><?= htmlspecialchars($target) ?></div>
                        </td>

                        <td>
                            <span class="badge <?= $severityBadge ?>">
                                <?= htmlspecialchars($notice['severity']) ?>
                            </span>
                        </td>

                        <td>
                            <div class="sa-muted">
                                From <?= $notice['publish_at']
                                    ? htmlspecialchars(date('M d, Y H:i', strtotime($notice['publish_at'])))
                                    : 'immediately' ?>
                            </div>
                            <div class="sa-muted">
                                Until <?= $notice['expires_at']
                                    ? htmlspecialchars(date('M d, Y H:i', strtotime($notice['expires_at'])))
                                    : 'removed' ?>
                                <?php if ($expired): ?>
                                    <span class="badge bg-danger ms-1">Expired</span>
                                <?php endif; ?>
                            </div>
                        </td>

                        <td data-search="<?= htmlspecialchars($notice['status']) ?>">
                            <span class="badge <?= $statusBadge ?>">
                                <?= htmlspecialchars($notice['status']) ?>
                            </span>
                        </td>

                        <td class="pe-4">

                            <button class="btn btn-sm sa-btn-soft btn-edit-notice"
                                data-notice="<?= htmlspecialchars(json_encode($notice), ENT_QUOTES, 'UTF-8') ?>"
                                title="Edit">
                                <i class="bi bi-pencil"></i>
                            </button>

                            <?php if ($notice['status'] !== 'Published'): ?>
                                <form method="POST" class="d-inline"
                                    data-confirm="Publish <?= htmlspecialchars($notice['title'], ENT_QUOTES) ?>?"
                                    data-confirm-text="It becomes visible to <?= htmlspecialchars(strtolower($target), ENT_QUOTES) ?> straight away."
                                    data-confirm-button="Publish">
                                    <input type="hidden" name="notification_id" value="<?= (int) $notice['notification_id'] ?>">
                                    <input type="hidden" name="status" value="Published">
                                    <button type="submit" name="setStatus" class="btn btn-sm sa-btn-soft text-success"
                                        title="Publish">
                                        <i class="bi bi-send"></i>
                                    </button>
                                </form>
                            <?php else: ?>
                                <form method="POST" class="d-inline"
                                    data-confirm="Archive <?= htmlspecialchars($notice['title'], ENT_QUOTES) ?>?"
                                    data-confirm-text="Tenants stop seeing it, and the record is kept."
                                    data-confirm-button="Archive">
                                    <input type="hidden" name="notification_id" value="<?= (int) $notice['notification_id'] ?>">
                                    <input type="hidden" name="status" value="Archived">
                                    <button type="submit" name="setStatus" class="btn btn-sm sa-btn-soft"
                                        title="Archive">
                                        <i class="bi bi-archive"></i>
                                    </button>
                                </form>
                            <?php endif; ?>

                            <form method="POST" class="d-inline"
                                data-confirm="Delete <?= htmlspecialchars($notice['title'], ENT_QUOTES) ?>?"
                                data-confirm-text="Only a notice that was never published can be deleted."
                                data-confirm-button="Delete" data-confirm-danger>
                                <input type="hidden" name="notification_id" value="<?= (int) $notice['notification_id'] ?>">
                                <button type="submit" name="deleteNotification" class="btn btn-sm sa-btn-soft text-danger"
                                    title="Delete">
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


<div class="modal fade" id="noticeModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">

            <form method="POST" data-confirm="Save this notice?"
                data-confirm-text="Saving it as Published sends it to tenants immediately."
                data-confirm-button="Save">

                <div class="modal-header">
                    <h5 class="modal-title" id="noticeModalTitle">New Notice</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">

                    <input type="hidden" name="notification_id" id="notification_id" value="0">

                    <div class="row g-3">

                        <div class="col-12">
                            <label class="form-label sa-required">Title</label>
                            <input type="text" name="title" id="notice_title" class="form-control"
                                maxlength="190" required>
                        </div>

                        <div class="col-12">
                            <label class="form-label sa-required">Message</label>
                            <textarea name="body" id="notice_body" class="form-control" rows="4"
                                minlength="10" required></textarea>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label sa-required">Audience</label>
                            <select name="audience" id="notice_audience" class="form-select" required>
                                <?php foreach ($AUDIENCES as $audience): ?>
                                    <option value="<?= htmlspecialchars($audience) ?>"><?= htmlspecialchars($audience) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-8 d-none" id="companyPicker">
                            <label class="form-label sa-required">Company</label>
                            <select name="company_id" id="notice_company" class="form-select">
                                <option value="0">Choose a company</option>
                                <?php while ($company = $companies->fetch_assoc()): ?>
                                    <option value="<?= (int) $company['company_id'] ?>">
                                        <?= htmlspecialchars($company['company_name']) ?>
                                        <?= $company['company_code'] ? '(' . htmlspecialchars($company['company_code']) . ')' : '' ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>

                        <div class="col-md-8 d-none" id="planPicker">
                            <label class="form-label sa-required">Plan</label>
                            <select name="plan_id" id="notice_plan" class="form-select">
                                <option value="0">Choose a plan</option>
                                <?php while ($plan = $plans->fetch_assoc()): ?>
                                    <option value="<?= (int) $plan['plan_id'] ?>">
                                        <?= htmlspecialchars($plan['plan_name']) ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label sa-required">Severity</label>
                            <select name="severity" id="notice_severity" class="form-select" required>
                                <?php foreach ($SEVERITIES as $severity): ?>
                                    <option value="<?= htmlspecialchars($severity) ?>"><?= htmlspecialchars($severity) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label sa-required">Status</label>
                            <select name="status" id="notice_status" class="form-select" required>
                                <?php foreach ($STATUSES as $state): ?>
                                    <option value="<?= htmlspecialchars($state) ?>"><?= htmlspecialchars($state) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Show From</label>
                            <input type="datetime-local" name="publish_at" id="notice_publish" class="form-control">
                            <div class="form-text">Leave empty to show immediately.</div>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Hide After</label>
                            <input type="datetime-local" name="expires_at" id="notice_expires" class="form-control">
                            <div class="form-text">Leave empty to keep until archived.</div>
                        </div>

                    </div>

                </div>

                <div class="modal-footer">
                    <button type="button" class="btn sa-btn-soft" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="saveNotification" class="btn sa-btn">Save</button>
                </div>

            </form>

        </div>
    </div>
</div>


<script>
    document.addEventListener("DOMContentLoaded", function () {

        var table = new DataTable("#noticeTable", {
            order: [],
            columnDefs: [{ orderable: false, targets: -1 }],
            language: {
                searchPlaceholder: "Search notices...",
                emptyTable: "<div class=\"sa-empty\">"
                    + '<i class="bi bi-megaphone d-block mb-2" style="font-size:28px;"></i>'
                    + "No notices yet. Write the first one when you have something to tell tenants."
                    + "</div>",
                zeroRecords: "No matching notices"
            }
        });

        document.getElementById("noticeFilter").addEventListener("change", function () {
            table.column(4).search(this.value ? "^" + this.value + "$" : "", true, false).draw();
        });

        var modal = new bootstrap.Modal(document.getElementById("noticeModal"));
        var form = document.querySelector("#noticeModal form");

        var audience = document.getElementById("notice_audience");
        var companyPicker = document.getElementById("companyPicker");
        var planPicker = document.getElementById("planPicker");
        var companySelect = document.getElementById("notice_company");
        var planSelect = document.getElementById("notice_plan");

        function val(id, v) { document.getElementById(id).value = v; }

        /* A hidden field can never be filled in, so required follows the
           picker that is actually on screen. */
        function syncAudience() {
            var value = audience.value;

            companyPicker.classList.toggle("d-none", value !== "Single Company");
            planPicker.classList.toggle("d-none", value !== "By Plan");

            if (value === "Single Company") {
                companySelect.setAttribute("required", "required");
            } else {
                companySelect.removeAttribute("required");
            }

            if (value === "By Plan") {
                planSelect.setAttribute("required", "required");
            } else {
                planSelect.removeAttribute("required");
            }
        }

        audience.addEventListener("change", syncAudience);

        document.getElementById("btnAddNotice").addEventListener("click", function () {
            form.reset();
            val("notification_id", "0");
            document.getElementById("noticeModalTitle").textContent = "New Notice";
            syncAudience();
            modal.show();
        });

        document.addEventListener("click", function (e) {

            var btn = e.target.closest(".btn-edit-notice");
            if (!btn) return;

            var n = JSON.parse(btn.dataset.notice);

            val("notification_id", n.notification_id);
            val("notice_title", n.title);
            val("notice_body", n.body);
            val("notice_audience", n.audience);
            val("notice_company", n.company_id || "0");
            val("notice_plan", n.plan_id || "0");
            val("notice_severity", n.severity);
            val("notice_status", n.status);

            /* datetime-local wants "YYYY-MM-DDTHH:MM"; MySQL hands over a space. */
            val("notice_publish", n.publish_at ? n.publish_at.replace(" ", "T").slice(0, 16) : "");
            val("notice_expires", n.expires_at ? n.expires_at.replace(" ", "T").slice(0, 16) : "");

            syncAudience();

            document.getElementById("noticeModalTitle").textContent = "Edit Notice";
            modal.show();
        });

        syncAudience();

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
