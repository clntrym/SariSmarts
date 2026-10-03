<?php

require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../includes/platform_roles.php';
require_once __DIR__ . '/../includes/audit.php';
requirePlatformAccess('leads');


/*
|--------------------------------------------------------------------------
| LEADS
|--------------------------------------------------------------------------
|
| Marketing's side of the platform: businesses that have shown interest but
| are not tenants yet.
|
| A lead only reaches Won by being linked to the company it became, so
| "how many leads turned into customers" is answerable from the data rather
| than from someone's memory. Lost needs a reason for the same purpose.
|
| This is the pipeline, not the customer list. Once a lead is Won, the
| company itself belongs to the Business modules.
|
*/

$alert = null;

$SOURCES = ['Website', 'Referral', 'Walk-in', 'Phone', 'Event', 'Social Media', 'Other'];
$INTERESTS = ['Retail Starter', 'Retail Professional', 'Retail Enterprise', 'Not Sure'];
$STAGES = ['New', 'Contacted', 'Demo Booked', 'Proposal Sent', 'Won', 'Lost'];


function leadProblem(mysqli $conn, array $post, array $sources, array $interests, array $stages): ?string
{
    if (trim($post['business_name'] ?? '') === '') {
        return 'Business name is required.';
    }

    if (trim($post['contact_name'] ?? '') === '') {
        return 'Contact name is required.';
    }

    $email = trim($post['contact_email'] ?? '');

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'Please enter a valid contact email.';
    }

    $branches = (string) ($post['branches'] ?? '');

    if ($branches === '' || !ctype_digit($branches) || (int) $branches < 1) {
        return 'Branches must be a whole number of one or more.';
    }

    if (!in_array($post['source'] ?? '', $sources, true)) {
        return 'Please choose where this lead came from.';
    }

    if (!in_array($post['interest'] ?? '', $interests, true)) {
        return 'Please choose the plan they are interested in.';
    }

    $stage = $post['stage'] ?? '';

    if (!in_array($stage, $stages, true)) {
        return 'Please choose a stage.';
    }

    /*
    | The two terminal stages have to say what happened. Won without the
    | company it became, and Lost without a reason, are the entries that
    | make a pipeline report useless a month later.
    */
    if ($stage === 'Won') {
        $companyId = (int) ($post['company_id'] ?? 0);

        if ($companyId <= 0) {
            return 'A won lead has to be linked to the company it became.';
        }

        $found = $conn->query("SELECT company_id FROM company WHERE company_id = " . $companyId . " LIMIT 1");

        if (!$found || $found->num_rows === 0) {
            return 'That company no longer exists.';
        }
    }

    if ($stage === 'Lost' && strlen(trim($post['lost_reason'] ?? '')) < 5) {
        return 'Please say in a few words why this lead was lost.';
    }

    $nextAction = trim($post['next_action'] ?? '');

    if ($nextAction !== '') {
        $parsed = date_create_from_format('Y-m-d', $nextAction);

        if (!$parsed || $parsed->format('Y-m-d') !== $nextAction) {
            return 'Next action must be a real date.';
        }
    }

    return null;
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['saveLead'])) {

    $leadId = (int) ($_POST['lead_id'] ?? 0);
    $problem = leadProblem($conn, $_POST, $SOURCES, $INTERESTS, $STAGES);

    if ($problem === null && $leadId > 0
        && !$conn->query("SELECT lead_id FROM marketing_leads WHERE lead_id = " . $leadId . " LIMIT 1")->num_rows) {
        /* An UPDATE that matches nothing still succeeds. */
        $problem = 'That lead no longer exists.';
    }

    if ($problem !== null) {

        $alert = ['icon' => 'error', 'title' => 'Missing Details', 'text' => $problem];

    } else {

        $business = trim($_POST['business_name']);
        $contact = trim($_POST['contact_name']);
        $email = trim($_POST['contact_email']);
        $phone = trim($_POST['contact_phone'] ?? '');
        $city = trim($_POST['city'] ?? '');
        $branches = (int) $_POST['branches'];
        $source = $_POST['source'];
        $interest = $_POST['interest'];
        $stage = $_POST['stage'];
        $notes = trim($_POST['notes'] ?? '');
        $nextAction = trim($_POST['next_action'] ?? '');

        /* Each field belongs to one stage only, so the other is cleared and
           a reopened lead cannot keep a stale reason or company. */
        $companyId = $stage === 'Won' ? (int) $_POST['company_id'] : null;
        $lostReason = $stage === 'Lost' ? trim($_POST['lost_reason']) : null;

        $owner = (int) ($_SESSION['user_id'] ?? 0) ?: null;
        $phone = $phone === '' ? null : $phone;
        $city = $city === '' ? null : $city;
        $notes = $notes === '' ? null : $notes;
        $nextAction = $nextAction === '' ? null : $nextAction;

        if ($leadId > 0) {
            $stmt = $conn->prepare("
                UPDATE marketing_leads SET
                    business_name = ?, contact_name = ?, contact_email = ?, contact_phone = ?,
                    city = ?, branches = ?, source = ?, interest = ?, stage = ?,
                    company_id = ?, lost_reason = ?, notes = ?, next_action = ?
                WHERE lead_id = ?
            ");
            $stmt->bind_param(
                "sssssisssisssi",
                $business, $contact, $email, $phone, $city, $branches,
                $source, $interest, $stage, $companyId, $lostReason, $notes, $nextAction, $leadId
            );
            $done = 'updated';
        } else {
            $stmt = $conn->prepare("
                INSERT INTO marketing_leads
                    (business_name, contact_name, contact_email, contact_phone, city, branches,
                     source, interest, stage, company_id, lost_reason, notes, next_action, owner_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param(
                "sssssisssisssi",
                $business, $contact, $email, $phone, $city, $branches,
                $source, $interest, $stage, $companyId, $lostReason, $notes, $nextAction, $owner
            );
            $done = 'added';
        }

        if ($stmt->execute()) {
            $savedId = $leadId > 0 ? $leadId : (int) $conn->insert_id;
            $stmt->close();

            auditLog($conn, 'Lead ' . $done, 'lead', $savedId, $business . ' (' . $stage . ')');

            $alert = ['icon' => 'success', 'title' => 'Lead Saved',
                      'text' => $business . ' has been ' . $done . '.'];
        } else {
            $error = $conn->error;
            $stmt->close();
            $alert = ['icon' => 'error', 'title' => 'Save Failed', 'text' => $error];
        }
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['deleteLead'])) {

    $leadId = (int) ($_POST['lead_id'] ?? 0);

    $stmt = $conn->prepare("SELECT business_name, stage FROM marketing_leads WHERE lead_id = ? LIMIT 1");
    $stmt->bind_param("i", $leadId);
    $stmt->execute();
    $lead = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$lead) {

        $alert = ['icon' => 'error', 'title' => 'Not Found', 'text' => 'That lead no longer exists.'];

    } elseif ($lead['stage'] === 'Won') {

        /* A won lead is the record of how a customer arrived. */
        $alert = ['icon' => 'error', 'title' => 'Cannot Delete',
                  'text' => 'This lead became a customer. Keep it so the pipeline still adds up.'];

    } else {

        $stmt = $conn->prepare("DELETE FROM marketing_leads WHERE lead_id = ?");
        $stmt->bind_param("i", $leadId);

        if ($stmt->execute()) {
            $stmt->close();
            auditLog($conn, 'Lead deleted', 'lead', $leadId, $lead['business_name']);
            $alert = ['icon' => 'success', 'title' => 'Lead Deleted',
                      'text' => $lead['business_name'] . ' has been removed.'];
        } else {
            $error = $conn->error;
            $stmt->close();
            $alert = ['icon' => 'error', 'title' => 'Delete Failed', 'text' => $error];
        }
    }
}


include("sAdminHeader.php");

/*
| chat_id rides along so a lead raised by the website assistant can show
| what was actually said. A lead that says only "wants Retail Professional"
| tells whoever rings them nothing.
*/
$leads = $conn->query("
    SELECT l.*, u.fullname AS owner_name, c.company_name,
           ch.chat_id
    FROM marketing_leads l
    LEFT JOIN users u ON u.user_id = l.owner_id
    LEFT JOIN company c ON c.company_id = l.company_id
    LEFT JOIN landing_chats ch ON ch.lead_id = l.lead_id
    ORDER BY FIELD(l.stage, 'New', 'Contacted', 'Demo Booked', 'Proposal Sent', 'Won', 'Lost'),
             l.updated_at DESC
");


/*
| Every conversation behind a lead, read in one query rather than one per
| row, and handed to the browser with the rest of the lead.
*/
$leadChats = [];

$chatRows = $conn->query("
    SELECT c.lead_id, m.role, m.body, m.created_at
    FROM landing_chats c
    JOIN landing_chat_messages m ON m.chat_id = c.chat_id
    WHERE c.lead_id IS NOT NULL
    ORDER BY c.lead_id, m.message_id
");

if ($chatRows) {
    while ($row = $chatRows->fetch_assoc()) {
        $leadChats[(int) $row['lead_id']][] = [
            'role' => $row['role'],
            'body' => $row['body'],
            'at' => $row['created_at'],
        ];
    }
}

$counts = $conn->query("
    SELECT
        COUNT(*) AS total,
        COALESCE(SUM(stage NOT IN ('Won','Lost')), 0) AS open_leads,
        COALESCE(SUM(stage = 'Won'), 0)  AS won,
        COALESCE(SUM(stage = 'Lost'), 0) AS lost,
        COALESCE(SUM(stage NOT IN ('Won','Lost') AND next_action IS NOT NULL AND next_action < CURDATE()), 0) AS overdue
    FROM marketing_leads
")->fetch_assoc();

$decided = (int) $counts['won'] + (int) $counts['lost'];
$winRate = $decided > 0 ? round(((int) $counts['won'] / $decided) * 100) : 0;

$companies = $conn->query("SELECT company_id, company_name, company_code FROM company ORDER BY company_name");

?>

<div class="sa-page-head">
    <div>
        <h3 class="sa-page-title">Leads</h3>
        <p class="sa-page-sub">
            Businesses that have shown interest but are not tenants yet.
        </p>
    </div>
    <button class="btn sa-btn" id="btnAddLead">
        <i class="bi bi-plus-lg me-1"></i> Add Lead
    </button>
</div>


<div class="row g-3 mb-4">

    <div class="col-md-3">
        <div class="sa-stat p-3 d-flex justify-content-between align-items-center">
            <div>
                <div class="sa-stat-label">In The Pipeline</div>
                <div class="sa-stat-value"><?= number_format((int) $counts['open_leads']) ?></div>
                <div class="sa-muted"><?= number_format((int) $counts['total']) ?> in total</div>
            </div>
            <div class="sa-stat-icon sa-tone-accent"><i class="bi bi-person-lines-fill"></i></div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="sa-stat p-3 d-flex justify-content-between align-items-center">
            <div>
                <div class="sa-stat-label">Won</div>
                <div class="sa-stat-value"><?= number_format((int) $counts['won']) ?></div>
                <div class="sa-muted">Became customers</div>
            </div>
            <div class="sa-stat-icon sa-tone-ok"><i class="bi bi-trophy"></i></div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="sa-stat p-3 d-flex justify-content-between align-items-center">
            <div>
                <div class="sa-stat-label">Win Rate</div>
                <div class="sa-stat-value"><?= $winRate ?>%</div>
                <div class="sa-muted">Of <?= number_format($decided) ?> decided</div>
            </div>
            <div class="sa-stat-icon sa-tone-navy"><i class="bi bi-graph-up-arrow"></i></div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="sa-stat p-3 d-flex justify-content-between align-items-center">
            <div>
                <div class="sa-stat-label">Follow Up Overdue</div>
                <div class="sa-stat-value"><?= number_format((int) $counts['overdue']) ?></div>
                <div class="sa-muted">Next action has passed</div>
            </div>
            <div class="sa-stat-icon sa-tone-bad"><i class="bi bi-alarm"></i></div>
        </div>
    </div>

</div>


<?php if ((int) $counts['overdue'] > 0): ?>
    <div class="alert alert-warning d-flex gap-2 align-items-start">
        <i class="bi bi-alarm mt-1"></i>
        <div class="small">
            <?= (int) $counts['overdue'] ?>
            lead<?= (int) $counts['overdue'] === 1 ? ' has' : 's have' ?>
            a follow-up date that has already passed.
        </div>
    </div>
<?php endif; ?>


<div class="sa-panel">

    <div class="sa-panel-head">
        <span>Pipeline</span>
        <div class="d-flex align-items-center gap-2">
            <label class="sa-muted mb-0" for="stageFilter">Stage</label>
            <select id="stageFilter" class="form-select form-select-sm" style="width:auto;" data-sa-skip>
                <option value="">All</option>
                <?php foreach ($STAGES as $stage): ?>
                    <option value="<?= htmlspecialchars($stage) ?>"><?= htmlspecialchars($stage) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <div class="table-responsive">

        <table id="leadTable" class="table table-hover sa-table" style="width:100%">

            <thead>
                <tr>
                    <th class="ps-4">Business</th>
                    <th style="width:190px;">Contact</th>
                    <th style="width:130px;">Source</th>
                    <th style="width:170px;">Interest</th>
                    <th style="width:150px;">Stage</th>
                    <th style="width:150px;">Next Action</th>
                    <th class="pe-4" style="width:120px;">Action</th>
                </tr>
            </thead>

            <tbody>

                <?php while ($lead = $leads->fetch_assoc()): ?>

                    <?php
                    $stageBadge = 'bg-light text-dark border';
                    if ($lead['stage'] === 'Won') {
                        $stageBadge = 'bg-success';
                    } elseif ($lead['stage'] === 'Lost') {
                        $stageBadge = 'bg-secondary';
                    } elseif ($lead['stage'] === 'Proposal Sent') {
                        $stageBadge = 'bg-primary';
                    } elseif ($lead['stage'] === 'Demo Booked') {
                        $stageBadge = 'bg-info text-dark';
                    }

                    $overdue = !in_array($lead['stage'], ['Won', 'Lost'], true)
                        && !empty($lead['next_action'])
                        && strtotime($lead['next_action']) < strtotime(date('Y-m-d'));
                    ?>

                    <tr>

                        <td class="ps-4">
                            <div class="sa-name"><?= htmlspecialchars($lead['business_name']) ?></div>
                            <div class="sa-muted">
                                <?= (int) $lead['branches'] ?> branch<?= (int) $lead['branches'] === 1 ? '' : 'es' ?>
                                <?= $lead['city'] ? ' - ' . htmlspecialchars($lead['city']) : '' ?>
                            </div>
                        </td>

                        <td>
                            <div><?= htmlspecialchars($lead['contact_name']) ?></div>
                            <div class="sa-muted"><?= htmlspecialchars($lead['contact_email']) ?></div>
                        </td>

                        <td>
                            <span class="badge bg-light text-dark border">
                                <?= htmlspecialchars($lead['source']) ?>
                            </span>
                        </td>

                        <td><?= htmlspecialchars($lead['interest']) ?></td>

                        <td data-search="<?= htmlspecialchars($lead['stage']) ?>">
                            <span class="badge <?= $stageBadge ?>">
                                <?= htmlspecialchars($lead['stage']) ?>
                            </span>
                            <?php if ($lead['stage'] === 'Won' && $lead['company_name']): ?>
                                <div class="sa-muted"><?= htmlspecialchars($lead['company_name']) ?></div>
                            <?php elseif ($lead['stage'] === 'Lost' && $lead['lost_reason']): ?>
                                <div class="sa-muted"><?= htmlspecialchars($lead['lost_reason']) ?></div>
                            <?php endif; ?>
                        </td>

                        <td data-order="<?= htmlspecialchars($lead['next_action'] ?? '') ?>">
                            <?php if ($lead['next_action']): ?>
                                <div class="<?= $overdue ? 'text-danger' : '' ?>">
                                    <?= htmlspecialchars(date('M d, Y', strtotime($lead['next_action']))) ?>
                                </div>
                                <?php if ($overdue): ?>
                                    <span class="badge bg-danger">Overdue</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="sa-muted">Not set</span>
                            <?php endif; ?>
                            <div class="sa-muted"><?= htmlspecialchars($lead['owner_name'] ?? 'Unassigned') ?></div>
                        </td>

                        <td class="pe-4">

                            <button class="btn btn-sm sa-btn-soft btn-edit-lead"
                                data-lead="<?= htmlspecialchars(json_encode($lead), ENT_QUOTES, 'UTF-8') ?>"
                                title="Edit">
                                <i class="bi bi-pencil"></i>
                            </button>

                            <?php if (!empty($leadChats[(int) $lead['lead_id']])): ?>
                                <?php
                                /*
                                | What they actually asked, for whoever rings
                                | them. The thread is already on the page, so
                                | opening it costs no round trip.
                                */
                                ?>
                                <button class="btn btn-sm sa-btn-soft btn-lead-chat"
                                    data-chat="<?= htmlspecialchars(json_encode([
                                        'business' => $lead['business_name'],
                                        'contact' => $lead['contact_name'],
                                        'thread' => $leadChats[(int) $lead['lead_id']],
                                    ]), ENT_QUOTES, 'UTF-8') ?>"
                                    title="What they asked">
                                    <i class="bi bi-chat-dots"></i>
                                </button>
                            <?php endif; ?>

                            <form method="POST" class="d-inline"
                                data-confirm="Delete <?= htmlspecialchars($lead['business_name'], ENT_QUOTES) ?>?"
                                data-confirm-text="A lost lead can go; a won one is kept so the pipeline still adds up."
                                data-confirm-button="Delete" data-confirm-danger>
                                <input type="hidden" name="lead_id" value="<?= (int) $lead['lead_id'] ?>">
                                <button type="submit" name="deleteLead" class="btn btn-sm sa-btn-soft text-danger">
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


<div class="modal fade" id="leadModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">

            <form method="POST" data-confirm="Save this lead?" data-confirm-button="Save">

                <div class="modal-header">
                    <h5 class="modal-title" id="leadModalTitle">Add Lead</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">

                    <input type="hidden" name="lead_id" id="lead_id" value="0">

                    <div class="row g-3">

                        <div class="col-md-8">
                            <label class="form-label sa-required">Business Name</label>
                            <input type="text" name="business_name" id="business_name" class="form-control"
                                maxlength="190" required>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label sa-required">Branches</label>
                            <input type="number" name="branches" id="branches" class="form-control"
                                min="1" value="1" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label sa-required">Contact Name</label>
                            <input type="text" name="contact_name" id="contact_name" class="form-control"
                                maxlength="150" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label sa-required">Contact Email</label>
                            <input type="email" name="contact_email" id="contact_email" class="form-control"
                                maxlength="150" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Contact Phone</label>
                            <input type="text" name="contact_phone" id="contact_phone" class="form-control"
                                maxlength="60">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">City</label>
                            <input type="text" name="city" id="city" class="form-control" maxlength="120">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label sa-required">Source</label>
                            <select name="source" id="source" class="form-select" required>
                                <?php foreach ($SOURCES as $source): ?>
                                    <option value="<?= htmlspecialchars($source) ?>"><?= htmlspecialchars($source) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label sa-required">Interested In</label>
                            <select name="interest" id="interest" class="form-select" required>
                                <?php foreach ($INTERESTS as $interest): ?>
                                    <option value="<?= htmlspecialchars($interest) ?>"><?= htmlspecialchars($interest) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label sa-required">Stage</label>
                            <select name="stage" id="stage" class="form-select" required>
                                <?php foreach ($STAGES as $stage): ?>
                                    <option value="<?= htmlspecialchars($stage) ?>"><?= htmlspecialchars($stage) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12 d-none" id="wonPicker">
                            <label class="form-label sa-required">Became Which Company</label>
                            <select name="company_id" id="company_id" class="form-select">
                                <option value="0">Choose the company</option>
                                <?php while ($company = $companies->fetch_assoc()): ?>
                                    <option value="<?= (int) $company['company_id'] ?>">
                                        <?= htmlspecialchars($company['company_name']) ?>
                                        <?= $company['company_code'] ? '(' . htmlspecialchars($company['company_code']) . ')' : '' ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                            <div class="form-text">Links the lead to the tenant it turned into.</div>
                        </div>

                        <div class="col-12 d-none" id="lostPicker">
                            <label class="form-label sa-required">Why Was It Lost</label>
                            <input type="text" name="lost_reason" id="lost_reason" class="form-control"
                                maxlength="255" placeholder="Price, chose a competitor, went quiet.">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Next Action</label>
                            <input type="date" name="next_action" id="next_action" class="form-control">
                            <div class="form-text">When to follow up.</div>
                        </div>

                        <div class="col-12">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" id="notes" class="form-control" rows="3"></textarea>
                        </div>

                    </div>

                </div>

                <div class="modal-footer">
                    <button type="button" class="btn sa-btn-soft" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="saveLead" class="btn sa-btn">Save</button>
                </div>

            </form>

        </div>
    </div>
</div>


<?php
/*
| What the visitor asked the website assistant.
|
| Read only. The thread is what happened; there is nothing here to change,
| and a conversation somebody could edit would be worth less than one they
| could not.
*/
?>
<div class="modal fade" id="leadChatModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow">

            <div class="modal-header">
                <div>
                    <h5 class="modal-title">What they asked</h5>
                    <div class="sa-muted" id="leadChatWho"></div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body" id="leadChatThread" style="background:#f8fafc;"></div>

        </div>
    </div>
</div>


<style>
    .lc-turn {
        display: flex;
        margin-bottom: 10px;
    }

    .lc-visitor {
        justify-content: flex-end;
    }

    .lc-body {
        max-width: 78%;
        padding: 10px 13px;
        border-radius: 14px;
        font-size: 14px;
        line-height: 1.5;
        white-space: pre-wrap;
        word-break: break-word;
    }

    .lc-assistant .lc-body {
        background: #fff;
        border: 1px solid #e2e8f0;
        color: #1f2937;
    }

    .lc-visitor .lc-body {
        background: #00224c;
        color: #fff;
    }

    .lc-when {
        font-size: 11px;
        color: #94a3b8;
        margin-top: 3px;
    }
</style>


<script>
    document.addEventListener("DOMContentLoaded", function () {

        var table = new DataTable("#leadTable", {
            order: [],
            columnDefs: [{ orderable: false, targets: -1 }],
            language: {
                searchPlaceholder: "Search business, contact or city...",
                emptyTable: "<div class=\"sa-empty\">"
                    + '<i class="bi bi-person-lines-fill d-block mb-2" style="font-size:28px;"></i>'
                    + "No leads yet. Add the first business that gets in touch."
                    + "</div>",
                zeroRecords: "No matching leads"
            }
        });

        document.getElementById("stageFilter").addEventListener("change", function () {
            table.column(4).search(this.value ? "^" + this.value + "$" : "", true, false).draw();
        });

        var modal = new bootstrap.Modal(document.getElementById("leadModal"));
        var form = document.querySelector("#leadModal form");

        var stage = document.getElementById("stage");
        var wonPicker = document.getElementById("wonPicker");
        var lostPicker = document.getElementById("lostPicker");
        var companySelect = document.getElementById("company_id");
        var lostReason = document.getElementById("lost_reason");

        function val(id, v) { document.getElementById(id).value = v; }

        /* A hidden field can never be filled in, so required follows the
           stage that is actually on screen. */
        function syncStage() {
            var value = stage.value;

            wonPicker.classList.toggle("d-none", value !== "Won");
            lostPicker.classList.toggle("d-none", value !== "Lost");

            if (value === "Won") {
                companySelect.setAttribute("required", "required");
            } else {
                companySelect.removeAttribute("required");
            }

            if (value === "Lost") {
                lostReason.setAttribute("required", "required");
                lostReason.setAttribute("minlength", "5");
            } else {
                lostReason.removeAttribute("required");
                lostReason.removeAttribute("minlength");
            }
        }

        stage.addEventListener("change", syncStage);

        document.getElementById("btnAddLead").addEventListener("click", function () {
            form.reset();
            val("lead_id", "0");
            document.getElementById("leadModalTitle").textContent = "Add Lead";
            syncStage();
            modal.show();
        });

        document.addEventListener("click", function (e) {

            var btn = e.target.closest(".btn-edit-lead");
            if (!btn) return;

            var l = JSON.parse(btn.dataset.lead);

            val("lead_id", l.lead_id);
            val("business_name", l.business_name);
            val("branches", l.branches);
            val("contact_name", l.contact_name);
            val("contact_email", l.contact_email);
            val("contact_phone", l.contact_phone || "");
            val("city", l.city || "");
            val("source", l.source);
            val("interest", l.interest);
            val("stage", l.stage);
            val("company_id", l.company_id || "0");
            val("lost_reason", l.lost_reason || "");
            val("next_action", l.next_action || "");
            val("notes", l.notes || "");

            syncStage();

            document.getElementById("leadModalTitle").textContent = "Edit Lead";
            modal.show();
        });

        syncStage();


        /* =========================================
           THE CONVERSATION BEHIND A LEAD

           Every turn is inserted as text, never as HTML. Half of it was
           typed by a stranger on the public site and the other half came
           from a language model, so neither half is ours to trust.
        ========================================== */

        var chatModal = new bootstrap.Modal(document.getElementById("leadChatModal"));
        var thread = document.getElementById("leadChatThread");

        document.addEventListener("click", function (e) {

            var btn = e.target.closest(".btn-lead-chat");
            if (!btn) return;

            var data = JSON.parse(btn.dataset.chat);

            document.getElementById("leadChatWho").textContent =
                data.business + (data.contact ? " - " + data.contact : "");

            thread.textContent = "";

            data.thread.forEach(function (turn) {

                var row = document.createElement("div");
                row.className = "lc-turn lc-" + turn.role;

                var wrap = document.createElement("div");

                var body = document.createElement("div");
                body.className = "lc-body";
                body.textContent = turn.body;

                var when = document.createElement("div");
                when.className = "lc-when";
                when.textContent = turn.at;

                wrap.appendChild(body);
                wrap.appendChild(when);
                row.appendChild(wrap);
                thread.appendChild(row);
            });

            chatModal.show();
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
