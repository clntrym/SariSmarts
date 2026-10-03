<?php

require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../includes/platform_roles.php';
requirePlatformAccess('audit');


/*
|--------------------------------------------------------------------------
| AUDIT LOGS
|--------------------------------------------------------------------------
|
| Who changed what, and when. Written by auditLog() in includes/audit.php
| from the Super Admin write paths that touch money, access or published
| content.
|
| This module has no write path of its own, on purpose. A trail an operator
| can edit or clear from the screen it is displayed on answers nothing, so
| there is no delete button here and no purge. Trimming old rows is a
| database job, done deliberately.
|
*/

$today = date('Y-m-d');
$defaultFrom = date('Y-m-d', strtotime('-30 days'));

$from = trim($_GET['from'] ?? $defaultFrom);
$to = trim($_GET['to'] ?? $today);
$entity = trim($_GET['entity'] ?? '');

$problem = null;

foreach (['Date from' => $from, 'Date to' => $to] as $label => $value) {
    $parsed = date_create_from_format('Y-m-d', $value);

    if (!$parsed || $parsed->format('Y-m-d') !== $value) {
        $problem = $label . ' must be a real date.';
        break;
    }
}

if ($problem === null && strtotime($from) > strtotime($to)) {
    $problem = 'The start date cannot be after the end date.';
}

if ($problem !== null) {
    $from = $defaultFrom;
    $to = $today;
}

$rangeStart = $from . ' 00:00:00';
$rangeEnd = $to . ' 23:59:59';


include("sAdminHeader.php");

$entities = $conn->query("SELECT DISTINCT entity FROM audit_log ORDER BY entity");

/* The entity filter is matched against what the table actually holds
   rather than a list written here, so a typed value cannot reach SQL. */
$knownEntities = [];
$entityOptions = [];

while ($row = $entities->fetch_assoc()) {
    $knownEntities[] = $row['entity'];
    $entityOptions[] = $row['entity'];
}

if ($entity !== '' && !in_array($entity, $knownEntities, true)) {
    $entity = '';
}

if ($entity === '') {
    $stmt = $conn->prepare("
        SELECT * FROM audit_log
        WHERE created_at BETWEEN ? AND ?
        ORDER BY created_at DESC, log_id DESC
        LIMIT 500
    ");
    $stmt->bind_param("ss", $rangeStart, $rangeEnd);
} else {
    $stmt = $conn->prepare("
        SELECT * FROM audit_log
        WHERE created_at BETWEEN ? AND ? AND entity = ?
        ORDER BY created_at DESC, log_id DESC
        LIMIT 500
    ");
    $stmt->bind_param("sss", $rangeStart, $rangeEnd, $entity);
}

$stmt->execute();
$entries = $stmt->get_result();

$summary = $conn->query("
    SELECT
        COUNT(*) AS total,
        COALESCE(SUM(DATE(created_at) = CURDATE()), 0) AS today,
        COUNT(DISTINCT user_id) AS actors,
        COUNT(DISTINCT entity) AS entities
    FROM audit_log
")->fetch_assoc();

?>

<div class="sa-page-head">
    <div>
        <h3 class="sa-page-title">Audit Logs</h3>
        <p class="sa-page-sub">
            Who changed what, and when. This screen only reads.
        </p>
    </div>
</div>


<div class="row g-3 mb-4">

    <div class="col-md-3">
        <div class="sa-stat p-3 d-flex justify-content-between align-items-center">
            <div>
                <div class="sa-stat-label">Entries Today</div>
                <div class="sa-stat-value"><?= number_format((int) $summary['today']) ?></div>
                <div class="sa-muted"><?= number_format((int) $summary['total']) ?> in total</div>
            </div>
            <div class="sa-stat-icon sa-tone-navy"><i class="bi bi-journal-text"></i></div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="sa-stat p-3 d-flex justify-content-between align-items-center">
            <div>
                <div class="sa-stat-label">Operators</div>
                <div class="sa-stat-value"><?= number_format((int) $summary['actors']) ?></div>
                <div class="sa-muted">Accounts that made a change</div>
            </div>
            <div class="sa-stat-icon sa-tone-accent"><i class="bi bi-person-badge"></i></div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="sa-stat p-3 d-flex justify-content-between align-items-center">
            <div>
                <div class="sa-stat-label">Areas Covered</div>
                <div class="sa-stat-value"><?= number_format((int) $summary['entities']) ?></div>
                <div class="sa-muted">Kinds of record</div>
            </div>
            <div class="sa-stat-icon sa-tone-ok"><i class="bi bi-diagram-3"></i></div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="sa-stat p-3 d-flex justify-content-between align-items-center">
            <div>
                <div class="sa-stat-label">Showing</div>
                <div class="sa-stat-value"><?= number_format($entries->num_rows) ?></div>
                <div class="sa-muted">Newest 500 in range</div>
            </div>
            <div class="sa-stat-icon sa-tone-warn"><i class="bi bi-funnel"></i></div>
        </div>
    </div>

</div>


<div class="sa-panel mb-4">

    <div class="sa-panel-head">
        <span>Filter</span>
        <span class="sa-muted">
            <?= htmlspecialchars(date('M d, Y', strtotime($from))) ?>
            to <?= htmlspecialchars(date('M d, Y', strtotime($to))) ?>
        </span>
    </div>

    <form method="GET" class="p-4">

        <div class="row g-3 align-items-end">

            <div class="col-md-3">
                <label class="form-label sa-required">From</label>
                <input type="date" name="from" class="form-control" max="<?= htmlspecialchars($today) ?>"
                    value="<?= htmlspecialchars($from) ?>" required>
            </div>

            <div class="col-md-3">
                <label class="form-label sa-required">To</label>
                <input type="date" name="to" class="form-control" max="<?= htmlspecialchars($today) ?>"
                    value="<?= htmlspecialchars($to) ?>" required>
            </div>

            <div class="col-md-3">
                <label class="form-label">Area</label>
                <select name="entity" class="form-select">
                    <option value="">All areas</option>
                    <?php foreach ($entityOptions as $option): ?>
                        <option value="<?= htmlspecialchars($option) ?>"
                            <?= $option === $entity ? 'selected' : '' ?>>
                            <?= htmlspecialchars(ucfirst($option)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-3 d-flex gap-2">
                <button type="submit" class="btn sa-btn">Apply</button>
                <a href="<?= $BASE_URL ?>/superAdmin/audit.php" class="btn sa-btn-soft">Reset</a>
            </div>

        </div>

    </form>

</div>


<div class="sa-panel">

    <div class="sa-panel-head">
        <span>Trail</span>
        <span class="sa-muted">Newest first</span>
    </div>

    <div class="table-responsive">

        <table id="auditTable" class="table table-hover sa-table" style="width:100%">

            <thead>
                <tr>
                    <th class="ps-4" style="width:170px;">When</th>
                    <th style="width:180px;">Operator</th>
                    <th style="width:210px;">Action</th>
                    <th style="width:140px;">Area</th>
                    <th>Detail</th>
                    <th class="pe-4" style="width:130px;">From</th>
                </tr>
            </thead>

            <tbody>

                <?php while ($entry = $entries->fetch_assoc()): ?>

                    <tr>

                        <td class="ps-4" data-order="<?= htmlspecialchars($entry['created_at']) ?>">
                            <div class="sa-name">
                                <?= htmlspecialchars(date('M d, Y', strtotime($entry['created_at']))) ?>
                            </div>
                            <div class="sa-muted">
                                <?= htmlspecialchars(date('H:i:s', strtotime($entry['created_at']))) ?>
                            </div>
                        </td>

                        <td><?= htmlspecialchars($entry['user_name']) ?></td>

                        <td><?= htmlspecialchars($entry['action']) ?></td>

                        <td>
                            <span class="badge bg-light text-dark border">
                                <?= htmlspecialchars($entry['entity']) ?>
                            </span>
                            <?php if ($entry['entity_id'] !== null && $entry['entity_id'] !== ''): ?>
                                <div class="sa-muted">#<?= htmlspecialchars($entry['entity_id']) ?></div>
                            <?php endif; ?>
                        </td>

                        <td class="text-muted small"><?= htmlspecialchars($entry['summary'] ?? '') ?></td>

                        <td class="pe-4 sa-muted"><?= htmlspecialchars($entry['ip_address'] ?? 'Local') ?></td>

                    </tr>

                <?php endwhile; ?>

            </tbody>

        </table>

    </div>

</div>


<script>
    document.addEventListener("DOMContentLoaded", function () {

        new DataTable("#auditTable", {
            order: [],
            language: {
                searchPlaceholder: "Search operator, action or detail...",
                emptyTable: "<div class=\"sa-empty\">"
                    + '<i class="bi bi-journal-text d-block mb-2" style="font-size:28px;"></i>'
                    + "Nothing recorded in this range yet. Entries appear as operators make changes."
                    + "</div>",
                zeroRecords: "No matching entries"
            }
        });

    });
</script>

<?php if ($problem !== null): ?>
    <script>
        Swal.fire({
            icon: "error",
            title: "Invalid Date Range",
            text: <?= json_encode($problem . ' Showing the last 30 days instead.') ?>,
            confirmButtonColor: "#00224c"
        });
    </script>
<?php endif; ?>

<?php include("sAdminFooter.php"); ?>
