<?php

require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../includes/platform_roles.php';
requirePlatformAccess('reports');


/*
|--------------------------------------------------------------------------
| REPORTS
|--------------------------------------------------------------------------
|
| Read only. Every figure comes from a table another module owns, so this
| screen writes nothing and can never be the reason a number is wrong.
|
| The CSV export runs above the layout include and exits. Below it, the
| headers would already be sent and the download would arrive as a copy of
| the admin page with a spreadsheet extension.
|
*/

$today = date('Y-m-d');
$defaultFrom = date('Y-m-d', strtotime('-90 days'));

$from = trim($_GET['from'] ?? $defaultFrom);
$to = trim($_GET['to'] ?? $today);

$rangeProblem = null;

foreach (['Date from' => $from, 'Date to' => $to] as $label => $value) {
    $parsed = date_create_from_format('Y-m-d', $value);

    if (!$parsed || $parsed->format('Y-m-d') !== $value) {
        $rangeProblem = $label . ' must be a real date.';
        break;
    }
}

if ($rangeProblem === null && strtotime($from) > strtotime($to)) {
    $rangeProblem = 'The start date cannot be after the end date.';
}

/* A bad range falls back to the default window rather than querying with it. */
if ($rangeProblem !== null) {
    $from = $defaultFrom;
    $to = $today;
}

$rangeStart = $from . ' 00:00:00';
$rangeEnd = $to . ' 23:59:59';


/*
| Revenue by plan. Shared by the page and the export so the spreadsheet can
| never disagree with what is on screen.
*/
function revenueByPlan(mysqli $conn, string $start, string $end): array
{
    $stmt = $conn->prepare("
        SELECT sp.plan_name,
               COUNT(cs.subscription_id) AS subscriptions,
               COALESCE(SUM(CASE WHEN cs.payment_status = 'Paid' THEN cs.amount END), 0) AS collected,
               COALESCE(SUM(CASE WHEN cs.payment_status <> 'Paid' THEN cs.amount END), 0) AS outstanding
        FROM subscription_plans sp
        LEFT JOIN company_subscriptions cs
            ON cs.plan_id = sp.plan_id AND cs.created_at BETWEEN ? AND ?
        GROUP BY sp.plan_id, sp.plan_name
        ORDER BY collected DESC, sp.plan_name
    ");
    $stmt->bind_param("ss", $start, $end);
    $stmt->execute();

    $rows = [];
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }

    $stmt->close();

    return $rows;
}


if (isset($_GET['export'])) {

    $rows = revenueByPlan($conn, $rangeStart, $rangeEnd);

    $filename = 'revenue-by-plan-' . $from . '-to-' . $to . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');

    fputcsv($out, ['Plan', 'Subscriptions', 'Collected (PHP)', 'Outstanding (PHP)']);

    foreach ($rows as $row) {
        fputcsv($out, [
            $row['plan_name'],
            (int) $row['subscriptions'],
            number_format((float) $row['collected'], 2, '.', ''),
            number_format((float) $row['outstanding'], 2, '.', ''),
        ]);
    }

    fclose($out);
    exit;
}


include("sAdminHeader.php");


$planRows = revenueByPlan($conn, $rangeStart, $rangeEnd);

$totalCollected = 0.0;
$totalOutstanding = 0.0;

foreach ($planRows as $row) {
    $totalCollected += (float) $row['collected'];
    $totalOutstanding += (float) $row['outstanding'];
}

$headline = $conn->prepare("
    SELECT
        (SELECT COUNT(*) FROM company WHERE created_at BETWEEN ? AND ?)                AS new_companies,
        (SELECT COUNT(*) FROM company_subscriptions WHERE created_at BETWEEN ? AND ?)  AS new_subscriptions,
        (SELECT COUNT(*) FROM users WHERE join_date BETWEEN ? AND ?)                   AS new_users
");
$headline->bind_param("ssssss", $rangeStart, $rangeEnd, $rangeStart, $rangeEnd, $rangeStart, $rangeEnd);
$headline->execute();
$totals = $headline->get_result()->fetch_assoc();
$headline->close();

/* Support only exists once its migration has run. */
$hasSupport = $conn->query("SHOW TABLES LIKE 'support_tickets'")->num_rows > 0;
$ticketRows = [];
$newTickets = 0;

if ($hasSupport) {
    $stmt = $conn->prepare("
        SELECT status, COUNT(*) AS total
        FROM support_tickets
        WHERE created_at BETWEEN ? AND ?
        GROUP BY status
        ORDER BY total DESC
    ");
    $stmt->bind_param("ss", $rangeStart, $rangeEnd);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $ticketRows[] = $row;
        $newTickets += (int) $row['total'];
    }

    $stmt->close();
}

$companyRows = $conn->query("
    SELECT status, COUNT(*) AS total FROM company GROUP BY status ORDER BY total DESC
");

$paymentRows = $conn->query("
    SELECT payment_status, COUNT(*) AS total, COALESCE(SUM(amount), 0) AS value
    FROM company_subscriptions GROUP BY payment_status ORDER BY total DESC
");

$roleRows = $conn->query("
    SELECT role, COUNT(*) AS total FROM users GROUP BY role ORDER BY total DESC
");

$topCompanies = $conn->prepare("
    SELECT c.company_name, c.company_code, COUNT(cs.subscription_id) AS subscriptions,
           COALESCE(SUM(cs.amount), 0) AS value
    FROM company_subscriptions cs
    INNER JOIN company c ON c.company_id = cs.company_id
    WHERE cs.created_at BETWEEN ? AND ?
    GROUP BY c.company_id, c.company_name, c.company_code
    ORDER BY value DESC
    LIMIT 8
");
$topCompanies->bind_param("ss", $rangeStart, $rangeEnd);
$topCompanies->execute();
$topRows = $topCompanies->get_result();

?>

<div class="sa-page-head">
    <div>
        <h3 class="sa-page-title">Reports</h3>
        <p class="sa-page-sub">
            Read-only figures across companies, subscriptions, users and support.
        </p>
    </div>
    <a class="btn sa-btn-soft"
        href="<?= $BASE_URL ?>/superAdmin/reports.php?export=revenue&amp;from=<?= urlencode($from) ?>&amp;to=<?= urlencode($to) ?>">
        <i class="bi bi-download me-1"></i> Export Revenue CSV
    </a>
</div>


<div class="sa-panel mb-4">

    <div class="sa-panel-head">
        <span>Reporting Period</span>
        <span class="sa-muted">
            <?= htmlspecialchars(date('M d, Y', strtotime($from))) ?>
            to <?= htmlspecialchars(date('M d, Y', strtotime($to))) ?>
        </span>
    </div>

    <form method="GET" class="p-4">

        <div class="row g-3 align-items-end">

            <div class="col-md-4">
                <label class="form-label sa-required">From</label>
                <input type="date" name="from" class="form-control" max="<?= htmlspecialchars($today) ?>"
                    value="<?= htmlspecialchars($from) ?>" required>
            </div>

            <div class="col-md-4">
                <label class="form-label sa-required">To</label>
                <input type="date" name="to" class="form-control" max="<?= htmlspecialchars($today) ?>"
                    value="<?= htmlspecialchars($to) ?>" required>
            </div>

            <div class="col-md-4 d-flex gap-2">
                <button type="submit" class="btn sa-btn">Apply</button>
                <a href="<?= $BASE_URL ?>/superAdmin/reports.php" class="btn sa-btn-soft">Reset</a>
            </div>

        </div>

    </form>

</div>


<div class="row g-3 mb-4">

    <div class="col-md-3">
        <div class="sa-stat p-3 d-flex justify-content-between align-items-center">
            <div>
                <div class="sa-stat-label">Collected In Period</div>
                <div class="sa-stat-value">&#8369;<?= number_format($totalCollected, 2) ?></div>
                <div class="sa-muted">&#8369;<?= number_format($totalOutstanding, 2) ?> outstanding</div>
            </div>
            <div class="sa-stat-icon sa-tone-ok"><i class="bi bi-cash-stack"></i></div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="sa-stat p-3 d-flex justify-content-between align-items-center">
            <div>
                <div class="sa-stat-label">New Companies</div>
                <div class="sa-stat-value"><?= number_format((int) $totals['new_companies']) ?></div>
                <div class="sa-muted">Registered in period</div>
            </div>
            <div class="sa-stat-icon sa-tone-navy"><i class="bi bi-buildings"></i></div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="sa-stat p-3 d-flex justify-content-between align-items-center">
            <div>
                <div class="sa-stat-label">New Subscriptions</div>
                <div class="sa-stat-value"><?= number_format((int) $totals['new_subscriptions']) ?></div>
                <div class="sa-muted"><?= number_format((int) $totals['new_users']) ?> new users</div>
            </div>
            <div class="sa-stat-icon sa-tone-accent"><i class="bi bi-credit-card"></i></div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="sa-stat p-3 d-flex justify-content-between align-items-center">
            <div>
                <div class="sa-stat-label">Tickets Opened</div>
                <div class="sa-stat-value"><?= number_format($newTickets) ?></div>
                <div class="sa-muted"><?= $hasSupport ? 'In period' : 'Support not set up' ?></div>
            </div>
            <div class="sa-stat-icon sa-tone-warn"><i class="bi bi-headset"></i></div>
        </div>
    </div>

</div>


<div class="sa-panel mb-4">

    <div class="sa-panel-head">
        <span>Revenue By Plan</span>
        <span class="sa-muted">Subscriptions created in the period</span>
    </div>

    <div class="table-responsive">

        <table class="table sa-table">

            <thead>
                <tr>
                    <th class="ps-4">Plan</th>
                    <th style="width:130px;">Subscriptions</th>
                    <th style="width:160px;">Collected</th>
                    <th style="width:160px;">Outstanding</th>
                    <th class="pe-4" style="width:220px;">Share Of Collected</th>
                </tr>
            </thead>

            <tbody>

                <?php if (count($planRows) === 0): ?>
                    <tr><td colspan="5" class="sa-empty">No plans yet.</td></tr>
                <?php endif; ?>

                <?php foreach ($planRows as $row): ?>

                    <?php
                    $share = $totalCollected > 0
                        ? round(((float) $row['collected'] / $totalCollected) * 100)
                        : 0;
                    ?>

                    <tr>
                        <td class="ps-4 sa-name"><?= htmlspecialchars($row['plan_name']) ?></td>
                        <td><?= number_format((int) $row['subscriptions']) ?></td>
                        <td>&#8369;<?= number_format((float) $row['collected'], 2) ?></td>
                        <td class="text-muted">&#8369;<?= number_format((float) $row['outstanding'], 2) ?></td>
                        <td class="pe-4">
                            <div class="sa-bar mb-1"><span style="width:<?= (int) $share ?>%;"></span></div>
                            <div class="sa-muted"><?= (int) $share ?>%</div>
                        </td>
                    </tr>

                <?php endforeach; ?>

            </tbody>

        </table>

    </div>

</div>


<div class="row g-3 mb-4">

    <div class="col-lg-4">
        <div class="sa-panel h-100">
            <div class="sa-panel-head">Companies By Status</div>
            <div class="table-responsive">
                <table class="table sa-table">
                    <tbody>
                        <?php $any = false; ?>
                        <?php while ($row = $companyRows->fetch_assoc()): $any = true; ?>
                            <tr>
                                <td class="ps-4"><?= htmlspecialchars($row['status']) ?></td>
                                <td class="pe-4 text-end sa-name"><?= number_format((int) $row['total']) ?></td>
                            </tr>
                        <?php endwhile; ?>
                        <?php if (!$any): ?>
                            <tr><td class="sa-empty">No companies yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="sa-panel h-100">
            <div class="sa-panel-head">Subscriptions By Payment</div>
            <div class="table-responsive">
                <table class="table sa-table">
                    <tbody>
                        <?php $any = false; ?>
                        <?php while ($row = $paymentRows->fetch_assoc()): $any = true; ?>
                            <tr>
                                <td class="ps-4">
                                    <?= htmlspecialchars($row['payment_status']) ?>
                                    <div class="sa-muted">&#8369;<?= number_format((float) $row['value'], 2) ?></div>
                                </td>
                                <td class="pe-4 text-end sa-name"><?= number_format((int) $row['total']) ?></td>
                            </tr>
                        <?php endwhile; ?>
                        <?php if (!$any): ?>
                            <tr><td class="sa-empty">No subscriptions yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="sa-panel h-100">
            <div class="sa-panel-head">Users By Role</div>
            <div class="table-responsive">
                <table class="table sa-table">
                    <tbody>
                        <?php $any = false; ?>
                        <?php while ($row = $roleRows->fetch_assoc()): $any = true; ?>
                            <tr>
                                <td class="ps-4"><?= htmlspecialchars($row['role']) ?></td>
                                <td class="pe-4 text-end sa-name"><?= number_format((int) $row['total']) ?></td>
                            </tr>
                        <?php endwhile; ?>
                        <?php if (!$any): ?>
                            <tr><td class="sa-empty">No users yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>


<div class="row g-3">

    <div class="col-lg-7">
        <div class="sa-panel h-100">
            <div class="sa-panel-head">
                <span>Top Companies By Value</span>
                <span class="sa-muted">In period</span>
            </div>
            <div class="table-responsive">
                <table class="table sa-table">
                    <thead>
                        <tr>
                            <th class="ps-4">Company</th>
                            <th style="width:120px;">Subscriptions</th>
                            <th class="pe-4" style="width:150px;">Value</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $any = false; ?>
                        <?php while ($row = $topRows->fetch_assoc()): $any = true; ?>
                            <tr>
                                <td class="ps-4">
                                    <div class="sa-name"><?= htmlspecialchars($row['company_name']) ?></div>
                                    <div class="sa-muted"><?= htmlspecialchars($row['company_code'] ?? '') ?></div>
                                </td>
                                <td><?= number_format((int) $row['subscriptions']) ?></td>
                                <td class="pe-4 sa-name">&#8369;<?= number_format((float) $row['value'], 2) ?></td>
                            </tr>
                        <?php endwhile; ?>
                        <?php if (!$any): ?>
                            <tr><td colspan="3" class="sa-empty">Nothing in this period.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="sa-panel h-100">
            <div class="sa-panel-head">Support By Status</div>
            <div class="table-responsive">
                <table class="table sa-table">
                    <tbody>
                        <?php foreach ($ticketRows as $row): ?>
                            <tr>
                                <td class="ps-4"><?= htmlspecialchars($row['status']) ?></td>
                                <td class="pe-4 text-end sa-name"><?= number_format((int) $row['total']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (count($ticketRows) === 0): ?>
                            <tr>
                                <td class="sa-empty">
                                    <?= $hasSupport
                                        ? 'No tickets opened in this period.'
                                        : 'Run database/superadmin_modules.sql to enable support.' ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>

<?php if ($rangeProblem !== null): ?>
    <script>
        Swal.fire({
            icon: "error",
            title: "Invalid Date Range",
            text: <?= json_encode($rangeProblem . ' Showing the last 90 days instead.') ?>,
            confirmButtonColor: "#00224c"
        });
    </script>
<?php endif; ?>

<?php include("sAdminFooter.php"); ?>
