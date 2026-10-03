<?php

require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../includes/platform_roles.php';
require_once __DIR__ . '/../includes/audit.php';
requirePlatformAccess('support');


/*
|--------------------------------------------------------------------------
| CUSTOMER SUPPORT
|--------------------------------------------------------------------------
|
| The queue of requests coming in from tenants, and the thread of replies
| on each one.
|
| A ticket is never deleted as part of normal work: Resolved and then
| Closed are the terminal states, so the record of what was asked and what
| was answered survives. Deleting is there only for spam, and it says so.
|
| company_id is optional. Someone can call before their registration is
| approved, and that call still has to be logged somewhere.
|
*/

$alert = null;

$CATEGORIES = ['Billing', 'Technical', 'Account', 'Feature Request', 'Other'];
$PRIORITIES = ['Low', 'Normal', 'High', 'Urgent'];
$STATUSES = ['Open', 'In Progress', 'Waiting on Customer', 'Resolved', 'Closed'];


/* TCK-1001, TCK-1002, ... continuing from the highest existing code. */
function nextTicketCode(mysqli $conn): string
{
    $row = $conn->query("
        SELECT MAX(CAST(SUBSTRING(ticket_code, 5) AS UNSIGNED)) AS top
        FROM support_tickets WHERE ticket_code LIKE 'TCK-%'
    ")->fetch_assoc();

    return 'TCK-' . (max(1000, (int) ($row['top'] ?? 1000)) + 1);
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['createTicket'])) {

    $companyId = (int) ($_POST['company_id'] ?? 0);
    $name = trim($_POST['contact_name'] ?? '');
    $email = trim($_POST['contact_email'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');
    $category = $_POST['category'] ?? '';
    $priority = $_POST['priority'] ?? '';

    $problem = null;

    if ($name === '') {
        $problem = 'Contact name is required.';
    } elseif ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $problem = 'Please enter a valid contact email.';
    } elseif ($subject === '') {
        $problem = 'Subject is required.';
    } elseif (strlen($message) < 10) {
        $problem = 'Please write at least 10 characters describing the request.';
    } elseif (!in_array($category, $CATEGORIES, true)) {
        $problem = 'Please choose a category.';
    } elseif (!in_array($priority, $PRIORITIES, true)) {
        $problem = 'Please choose a priority.';
    }

    if ($problem === null && $companyId > 0) {
        $check = $conn->query("SELECT company_id FROM company WHERE company_id = " . $companyId . " LIMIT 1");
        if (!$check || $check->num_rows === 0) {
            $problem = 'That company no longer exists.';
        }
    }

    if ($problem !== null) {

        $alert = ['icon' => 'error', 'title' => 'Missing Details', 'text' => $problem];

    } else {

        $code = nextTicketCode($conn);
        $company = $companyId > 0 ? $companyId : null;

        $stmt = $conn->prepare("
            INSERT INTO support_tickets
                (ticket_code, company_id, contact_name, contact_email,
                 subject, message, category, priority, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Open')
        ");
        $stmt->bind_param("sissssss", $code, $company, $name, $email, $subject, $message, $category, $priority);

        $saved = $stmt->execute();
        $newTicketId = (int) $conn->insert_id;

        $alert = $saved
            ? ['icon' => 'success', 'title' => 'Ticket Logged', 'text' => $code . ' has been opened.']
            : ['icon' => 'error', 'title' => 'Save Failed', 'text' => $conn->error];

        $stmt->close();

        if ($saved) {
            auditLog($conn, 'Ticket logged', 'support', $newTicketId, $code . ': ' . $subject);
        }
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['replyTicket'])) {

    $ticketId = (int) ($_POST['ticket_id'] ?? 0);
    $body = trim($_POST['body'] ?? '');
    $internal = isset($_POST['is_internal']) ? 1 : 0;
    $newStatus = $_POST['status'] ?? '';

    $ticket = null;

    if ($ticketId > 0) {
        $stmt = $conn->prepare("SELECT ticket_id, ticket_code, status FROM support_tickets WHERE ticket_id = ? LIMIT 1");
        $stmt->bind_param("i", $ticketId);
        $stmt->execute();
        $ticket = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }

    if (!$ticket) {

        $alert = ['icon' => 'error', 'title' => 'Not Found', 'text' => 'That ticket no longer exists.'];

    } elseif (strlen($body) < 2) {

        $alert = ['icon' => 'error', 'title' => 'Nothing To Send', 'text' => 'Please write the reply first.'];

    } elseif (!in_array($newStatus, $STATUSES, true)) {

        $alert = ['icon' => 'error', 'title' => 'Missing Details', 'text' => 'Please choose a status.'];

    } else {

        $author = (int) ($_SESSION['user_id'] ?? 0);
        $authorName = $_SESSION['fullname'] ?? 'Super Admin';

        $conn->begin_transaction();

        try {
            $stmt = $conn->prepare("
                INSERT INTO support_ticket_replies (ticket_id, author_id, author_name, body, is_internal)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("iissi", $ticketId, $author, $authorName, $body, $internal);
            $stmt->execute();
            $stmt->close();

            /* Resolved and Closed are the only states that stamp a date. */
            if (in_array($newStatus, ['Resolved', 'Closed'], true)) {
                $stmt = $conn->prepare("
                    UPDATE support_tickets
                    SET status = ?, resolved_at = COALESCE(resolved_at, NOW())
                    WHERE ticket_id = ?
                ");
            } else {
                $stmt = $conn->prepare("
                    UPDATE support_tickets SET status = ?, resolved_at = NULL WHERE ticket_id = ?
                ");
            }
            $stmt->bind_param("si", $newStatus, $ticketId);
            $stmt->execute();
            $stmt->close();

            $conn->commit();

            $alert = ['icon' => 'success', 'title' => $internal ? 'Note Added' : 'Reply Sent',
                      'text' => $ticket['ticket_code'] . ' is now ' . $newStatus . '.'];

        } catch (Throwable $e) {
            $conn->rollback();
            $alert = ['icon' => 'error', 'title' => 'Save Failed', 'text' => $e->getMessage()];
        }
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assignTicket'])) {

    $ticketId = (int) ($_POST['ticket_id'] ?? 0);
    $priority = $_POST['priority'] ?? '';
    $assignee = (int) ($_POST['assigned_to'] ?? 0);

    if ($ticketId <= 0
        || !$conn->query("SELECT ticket_id FROM support_tickets WHERE ticket_id = " . $ticketId . " LIMIT 1")->num_rows) {
        $alert = ['icon' => 'error', 'title' => 'Not Found', 'text' => 'That ticket no longer exists.'];
    } elseif (!in_array($priority, $PRIORITIES, true)) {
        $alert = ['icon' => 'error', 'title' => 'Missing Details', 'text' => 'Please choose a priority.'];
    } else {

        $owner = $assignee > 0 ? $assignee : null;

        $stmt = $conn->prepare("UPDATE support_tickets SET priority = ?, assigned_to = ? WHERE ticket_id = ?");
        $stmt->bind_param("sii", $priority, $owner, $ticketId);
        $alert = $stmt->execute()
            ? ['icon' => 'success', 'title' => 'Ticket Updated', 'text' => 'Priority and owner have been saved.']
            : ['icon' => 'error', 'title' => 'Save Failed', 'text' => $conn->error];
        $stmt->close();
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['deleteTicket'])) {

    $ticketId = (int) ($_POST['ticket_id'] ?? 0);

    /* Replies cascade with the ticket. */
    $stmt = $conn->prepare("DELETE FROM support_tickets WHERE ticket_id = ?");
    $stmt->bind_param("i", $ticketId);
    $removed = $stmt->execute();

    $alert = $removed
        ? ['icon' => 'success', 'title' => 'Ticket Deleted', 'text' => 'The ticket and its replies are gone.']
        : ['icon' => 'error', 'title' => 'Delete Failed', 'text' => $conn->error];
    $stmt->close();

    if ($removed) {
        auditLog($conn, 'Ticket deleted', 'support', $ticketId, 'Removed as spam');
    }
}


include("sAdminHeader.php");


$tickets = [];

$result = $conn->query("
    SELECT t.*, c.company_name, c.company_code, u.fullname AS assignee_name
    FROM support_tickets t
    LEFT JOIN company c ON c.company_id = t.company_id
    LEFT JOIN users u ON u.user_id = t.assigned_to
    ORDER BY FIELD(t.status, 'Open', 'In Progress', 'Waiting on Customer', 'Resolved', 'Closed'),
             FIELD(t.priority, 'Urgent', 'High', 'Normal', 'Low'),
             t.updated_at DESC
");

while ($ticket = $result->fetch_assoc()) {
    $ticket['replies'] = [];
    $tickets[$ticket['ticket_id']] = $ticket;
}

if (count($tickets) > 0) {
    $replies = $conn->query("
        SELECT ticket_id, author_name, body, is_internal, created_at
        FROM support_ticket_replies
        ORDER BY ticket_id, created_at, reply_id
    ");

    while ($reply = $replies->fetch_assoc()) {
        if (isset($tickets[$reply['ticket_id']])) {
            $tickets[$reply['ticket_id']]['replies'][] = $reply;
        }
    }
}

$counts = $conn->query("
    SELECT
        COALESCE(SUM(status = 'Open'), 0)                 AS open_count,
        COALESCE(SUM(status = 'In Progress'), 0)          AS progress_count,
        COALESCE(SUM(status = 'Waiting on Customer'), 0)  AS waiting_count,
        COALESCE(SUM(status IN ('Resolved','Closed')), 0) AS done_count,
        COALESCE(SUM(priority = 'Urgent' AND status NOT IN ('Resolved','Closed')), 0) AS urgent_count
    FROM support_tickets
")->fetch_assoc();

$companies = $conn->query("SELECT company_id, company_name, company_code FROM company ORDER BY company_name");
$staff = $conn->query("SELECT user_id, fullname FROM users WHERE LOWER(role) = 'super admin' ORDER BY fullname");

?>

<div class="sa-page-head">
    <div>
        <h3 class="sa-page-title">Customer Support</h3>
        <p class="sa-page-sub">
            Requests from tenants, and the thread of replies on each one.
        </p>
    </div>
    <button class="btn sa-btn" id="btnLogTicket">
        <i class="bi bi-plus-lg me-1"></i> Log Ticket
    </button>
</div>


<div class="row g-3 mb-4">

    <div class="col-md-3">
        <div class="sa-stat p-3 d-flex justify-content-between align-items-center">
            <div>
                <div class="sa-stat-label">Open</div>
                <div class="sa-stat-value"><?= number_format((int) $counts['open_count']) ?></div>
                <div class="sa-muted">Not picked up yet</div>
            </div>
            <div class="sa-stat-icon sa-tone-bad"><i class="bi bi-envelope-open"></i></div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="sa-stat p-3 d-flex justify-content-between align-items-center">
            <div>
                <div class="sa-stat-label">In Progress</div>
                <div class="sa-stat-value"><?= number_format((int) $counts['progress_count']) ?></div>
                <div class="sa-muted">Being worked on</div>
            </div>
            <div class="sa-stat-icon sa-tone-warn"><i class="bi bi-tools"></i></div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="sa-stat p-3 d-flex justify-content-between align-items-center">
            <div>
                <div class="sa-stat-label">Waiting On Customer</div>
                <div class="sa-stat-value"><?= number_format((int) $counts['waiting_count']) ?></div>
                <div class="sa-muted">Ball is with them</div>
            </div>
            <div class="sa-stat-icon sa-tone-accent"><i class="bi bi-hourglass"></i></div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="sa-stat p-3 d-flex justify-content-between align-items-center">
            <div>
                <div class="sa-stat-label">Resolved</div>
                <div class="sa-stat-value"><?= number_format((int) $counts['done_count']) ?></div>
                <div class="sa-muted">Resolved or closed</div>
            </div>
            <div class="sa-stat-icon sa-tone-ok"><i class="bi bi-check2-circle"></i></div>
        </div>
    </div>

</div>


<?php if ((int) $counts['urgent_count'] > 0): ?>
    <div class="alert alert-danger d-flex gap-2 align-items-start">
        <i class="bi bi-exclamation-octagon mt-1"></i>
        <div class="small">
            <?= (int) $counts['urgent_count'] ?>
            urgent ticket<?= (int) $counts['urgent_count'] === 1 ? ' is' : 's are' ?> still open.
        </div>
    </div>
<?php endif; ?>


<div class="sa-panel">

    <div class="sa-panel-head">
        <span>Ticket Queue</span>
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

        <table id="ticketTable" class="table table-hover sa-table" style="width:100%">

            <thead>
                <tr>
                    <th class="ps-4" style="width:110px;">Ticket</th>
                    <th>Subject</th>
                    <th style="width:190px;">Company</th>
                    <th style="width:130px;">Category</th>
                    <th style="width:100px;">Priority</th>
                    <th style="width:160px;">Status</th>
                    <th style="width:150px;">Owner</th>
                    <th class="pe-4" style="width:120px;">Action</th>
                </tr>
            </thead>

            <tbody>

                <?php foreach ($tickets as $ticket): ?>

                    <?php
                    $statusBadge = 'bg-secondary';
                    if ($ticket['status'] === 'Open') {
                        $statusBadge = 'bg-danger';
                    } elseif ($ticket['status'] === 'In Progress') {
                        $statusBadge = 'bg-warning text-dark';
                    } elseif ($ticket['status'] === 'Waiting on Customer') {
                        $statusBadge = 'bg-info text-dark';
                    } elseif ($ticket['status'] === 'Resolved') {
                        $statusBadge = 'bg-success';
                    }

                    $priorityBadge = 'bg-light text-dark border';
                    if ($ticket['priority'] === 'Urgent') {
                        $priorityBadge = 'bg-danger';
                    } elseif ($ticket['priority'] === 'High') {
                        $priorityBadge = 'bg-warning text-dark';
                    }
                    ?>

                    <tr>

                        <td class="ps-4">
                            <span class="sa-name"><?= htmlspecialchars($ticket['ticket_code']) ?></span>
                            <div class="sa-muted"><?= count($ticket['replies']) ?> repl<?= count($ticket['replies']) === 1 ? 'y' : 'ies' ?></div>
                        </td>

                        <td>
                            <div class="sa-name"><?= htmlspecialchars($ticket['subject']) ?></div>
                            <div class="sa-muted"><?= htmlspecialchars($ticket['contact_name']) ?></div>
                        </td>

                        <td>
                            <?php if ($ticket['company_name']): ?>
                                <div><?= htmlspecialchars($ticket['company_name']) ?></div>
                                <div class="sa-muted"><?= htmlspecialchars($ticket['company_code'] ?? '') ?></div>
                            <?php else: ?>
                                <span class="badge bg-dark">Not a tenant yet</span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <span class="badge bg-light text-dark border">
                                <?= htmlspecialchars($ticket['category']) ?>
                            </span>
                        </td>

                        <td>
                            <span class="badge <?= $priorityBadge ?>">
                                <?= htmlspecialchars($ticket['priority']) ?>
                            </span>
                        </td>

                        <td data-order="<?= htmlspecialchars($ticket['updated_at']) ?>"
                            data-search="<?= htmlspecialchars($ticket['status']) ?>">
                            <span class="badge <?= $statusBadge ?>">
                                <?= htmlspecialchars($ticket['status']) ?>
                            </span>
                            <div class="sa-muted">
                                <?= htmlspecialchars(date('M d, Y', strtotime($ticket['updated_at']))) ?>
                            </div>
                        </td>

                        <td>
                            <?php if ($ticket['assignee_name']): ?>
                                <?= htmlspecialchars($ticket['assignee_name']) ?>
                            <?php else: ?>
                                <span class="sa-muted">Unassigned</span>
                            <?php endif; ?>
                        </td>

                        <td class="pe-4">

                            <button class="btn btn-sm sa-btn-soft btn-open-ticket"
                                data-ticket="<?= htmlspecialchars(json_encode($ticket), ENT_QUOTES, 'UTF-8') ?>"
                                title="Open thread">
                                <i class="bi bi-chat-left-text"></i>
                            </button>

                            <button class="btn btn-sm sa-btn-soft btn-assign-ticket"
                                data-ticket="<?= htmlspecialchars(json_encode($ticket), ENT_QUOTES, 'UTF-8') ?>"
                                title="Priority and owner">
                                <i class="bi bi-person-gear"></i>
                            </button>

                        </td>
                    </tr>

                <?php endforeach; ?>

            </tbody>

        </table>

    </div>

</div>


<!-- ===============================
     TICKET THREAD
================================ -->

<div class="modal fade" id="threadModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">

            <div class="modal-header">
                <div>
                    <h5 class="modal-title" id="th_code">Ticket</h5>
                    <small class="sa-muted" id="th_meta"></small>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body">

                <table class="table table-sm sa-detail mb-4">
                    <tr><th>Subject</th><td id="th_subject"></td></tr>
                    <tr><th>Contact</th><td id="th_contact"></td></tr>
                    <tr><th>Company</th><td id="th_company"></td></tr>
                    <tr><th>Category</th><td id="th_category"></td></tr>
                    <tr><th>Priority</th><td id="th_priority"></td></tr>
                    <tr><th>Status</th><td id="th_status"></td></tr>
                    <tr><th>Opened</th><td id="th_opened"></td></tr>
                </table>

                <div class="sa-panel-head px-0 pt-0">Conversation</div>

                <div id="th_thread" class="mb-4"></div>

                <form method="POST" data-confirm="Send this reply?"
                    data-confirm-text="An internal note stays with your team; anything else is the answer to the customer."
                    data-confirm-button="Send">

                    <input type="hidden" name="ticket_id" id="reply_ticket_id">

                    <div class="mb-3">
                        <label class="form-label sa-required">Reply</label>
                        <textarea name="body" id="reply_body" class="form-control" rows="4"
                            minlength="2" required></textarea>
                    </div>

                    <div class="row g-3 align-items-end">

                        <div class="col-md-6">
                            <label class="form-label sa-required">Set Status To</label>
                            <select name="status" id="reply_status" class="form-select" required>
                                <?php foreach ($STATUSES as $state): ?>
                                    <option value="<?= htmlspecialchars($state) ?>"><?= htmlspecialchars($state) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_internal"
                                    id="reply_internal" value="1">
                                <label class="form-check-label" for="reply_internal">
                                    Internal note, not sent to the customer
                                </label>
                            </div>
                        </div>

                    </div>

                    <button type="submit" name="replyTicket" class="btn sa-btn mt-3">
                        <i class="bi bi-send me-1"></i> Send Reply
                    </button>

                </form>

            </div>

            <div class="modal-footer justify-content-between">

                <form method="POST"
                    data-confirm="Delete this ticket for good?"
                    data-confirm-text="Use this only for spam. A real request should be Resolved or Closed instead, so the record survives."
                    data-confirm-button="Delete" data-confirm-danger>
                    <input type="hidden" name="ticket_id" id="delete_ticket_id">
                    <button type="submit" name="deleteTicket" class="btn btn-sm sa-btn-soft text-danger">
                        <i class="bi bi-trash me-1"></i> Delete
                    </button>
                </form>

                <button type="button" class="btn sa-btn-soft" data-bs-dismiss="modal">Close</button>

            </div>

        </div>
    </div>
</div>


<!-- ===============================
     PRIORITY AND OWNER
================================ -->

<div class="modal fade" id="assignModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">

            <form method="POST" data-confirm="Save priority and owner?" data-confirm-button="Save">

                <div class="modal-header">
                    <h5 class="modal-title">Priority And Owner</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">

                    <input type="hidden" name="ticket_id" id="assign_ticket_id">

                    <p class="mb-3">
                        <span class="sa-name" id="assign_code"></span>
                        <span class="sa-muted" id="assign_subject"></span>
                    </p>

                    <div class="mb-3">
                        <label class="form-label sa-required">Priority</label>
                        <select name="priority" id="assign_priority" class="form-select" required>
                            <?php foreach ($PRIORITIES as $level): ?>
                                <option value="<?= htmlspecialchars($level) ?>"><?= htmlspecialchars($level) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-0">
                        <label class="form-label">Owner</label>
                        <select name="assigned_to" id="assign_owner" class="form-select">
                            <option value="0">Unassigned</option>
                            <?php while ($member = $staff->fetch_assoc()): ?>
                                <option value="<?= (int) $member['user_id'] ?>">
                                    <?= htmlspecialchars($member['fullname']) ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                </div>

                <div class="modal-footer">
                    <button type="button" class="btn sa-btn-soft" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="assignTicket" class="btn sa-btn">Save</button>
                </div>

            </form>

        </div>
    </div>
</div>


<!-- ===============================
     LOG A TICKET
================================ -->

<div class="modal fade" id="logModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">

            <form method="POST" data-confirm="Log this ticket?"
                data-confirm-text="It joins the queue as Open."
                data-confirm-button="Log Ticket">

                <div class="modal-header">
                    <h5 class="modal-title">Log Ticket</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">

                    <p class="sa-muted">
                        For a request that arrived by phone or email rather than through the app.
                    </p>

                    <div class="row g-3">

                        <div class="col-md-6">
                            <label class="form-label sa-required">Contact Name</label>
                            <input type="text" name="contact_name" class="form-control" maxlength="150" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label sa-required">Contact Email</label>
                            <input type="email" name="contact_email" class="form-control" maxlength="150" required>
                        </div>

                        <div class="col-12">
                            <label class="form-label">Company</label>
                            <select name="company_id" class="form-select">
                                <option value="0">Not a tenant yet</option>
                                <?php while ($company = $companies->fetch_assoc()): ?>
                                    <option value="<?= (int) $company['company_id'] ?>">
                                        <?= htmlspecialchars($company['company_name']) ?>
                                        <?= $company['company_code'] ? '(' . htmlspecialchars($company['company_code']) . ')' : '' ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>

                        <div class="col-12">
                            <label class="form-label sa-required">Subject</label>
                            <input type="text" name="subject" class="form-control" maxlength="190" required>
                        </div>

                        <div class="col-12">
                            <label class="form-label sa-required">What Do They Need</label>
                            <textarea name="message" class="form-control" rows="4" minlength="10" required></textarea>
                            <div class="form-text">At least 10 characters, in their words where you can.</div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label sa-required">Category</label>
                            <select name="category" class="form-select" required>
                                <?php foreach ($CATEGORIES as $category): ?>
                                    <option value="<?= htmlspecialchars($category) ?>"><?= htmlspecialchars($category) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label sa-required">Priority</label>
                            <select name="priority" class="form-select" required>
                                <?php foreach ($PRIORITIES as $level): ?>
                                    <option value="<?= htmlspecialchars($level) ?>"
                                        <?= $level === 'Normal' ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($level) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                    </div>

                </div>

                <div class="modal-footer">
                    <button type="button" class="btn sa-btn-soft" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="createTicket" class="btn sa-btn">Log Ticket</button>
                </div>

            </form>

        </div>
    </div>
</div>


<script>
    document.addEventListener("DOMContentLoaded", function () {

        var table = new DataTable("#ticketTable", {
            order: [],
            columnDefs: [{ orderable: false, targets: -1 }],
            language: {
                searchPlaceholder: "Search ticket, subject or contact...",
                emptyTable: "<div class=\"sa-empty\">"
                    + '<i class="bi bi-headset d-block mb-2" style="font-size:28px;"></i>'
                    + "No tickets yet. Log the first one when a customer gets in touch."
                    + "</div>",
                zeroRecords: "No matching tickets"
            }
        });

        document.getElementById("statusFilter").addEventListener("change", function () {
            table.column(5).search(this.value ? "^" + this.value + "$" : "", true, false).draw();
        });

        var threadModal = new bootstrap.Modal(document.getElementById("threadModal"));
        var assignModal = new bootstrap.Modal(document.getElementById("assignModal"));
        var logModal = new bootstrap.Modal(document.getElementById("logModal"));

        function text(id, value) {
            document.getElementById(id).textContent = value;
        }

        document.getElementById("btnLogTicket").addEventListener("click", function () {
            document.querySelector("#logModal form").reset();
            logModal.show();
        });

        /* One bubble in the conversation. Built with textContent so a
           customer's own words can never become markup. */
        function bubble(author, body, when, internal) {
            var wrap = document.createElement("div");
            wrap.className = "sa-feed-item px-0";

            var head = document.createElement("div");
            head.className = "d-flex justify-content-between align-items-center";

            var who = document.createElement("span");
            who.className = "sa-name";
            who.textContent = author;
            head.appendChild(who);

            if (internal) {
                var tag = document.createElement("span");
                tag.className = "badge bg-dark";
                tag.textContent = "Internal note";
                head.appendChild(tag);
            }

            wrap.appendChild(head);

            var text = document.createElement("div");
            text.className = "small mt-1";
            text.style.whiteSpace = "pre-wrap";
            text.textContent = body;
            wrap.appendChild(text);

            var stamp = document.createElement("div");
            stamp.className = "sa-muted mt-1";
            stamp.textContent = when;
            wrap.appendChild(stamp);

            return wrap;
        }

        document.addEventListener("click", function (e) {

            var openBtn = e.target.closest(".btn-open-ticket");

            if (openBtn) {
                var t = JSON.parse(openBtn.dataset.ticket);

                text("th_code", t.ticket_code);
                text("th_meta", t.replies.length + (t.replies.length === 1 ? " reply" : " replies"));
                text("th_subject", t.subject);
                text("th_contact", t.contact_name + " (" + t.contact_email + ")");
                text("th_company", t.company_name || "Not a tenant yet");
                text("th_category", t.category);
                text("th_priority", t.priority);
                text("th_status", t.status);
                text("th_opened", t.created_at);

                var box = document.getElementById("th_thread");
                box.textContent = "";

                box.appendChild(bubble(t.contact_name, t.message, t.created_at, false));

                t.replies.forEach(function (r) {
                    box.appendChild(bubble(r.author_name, r.body, r.created_at, r.is_internal === "1" || r.is_internal === 1));
                });

                document.getElementById("reply_ticket_id").value = t.ticket_id;
                document.getElementById("delete_ticket_id").value = t.ticket_id;
                document.getElementById("reply_body").value = "";
                document.getElementById("reply_internal").checked = false;
                document.getElementById("reply_status").value =
                    t.status === "Open" ? "In Progress" : t.status;

                threadModal.show();
                return;
            }

            var assignBtn = e.target.closest(".btn-assign-ticket");

            if (assignBtn) {
                var a = JSON.parse(assignBtn.dataset.ticket);

                document.getElementById("assign_ticket_id").value = a.ticket_id;
                text("assign_code", a.ticket_code);
                text("assign_subject", " " + a.subject);
                document.getElementById("assign_priority").value = a.priority;
                document.getElementById("assign_owner").value = a.assigned_to || "0";

                assignModal.show();
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
