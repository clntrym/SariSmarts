<?php

require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../includes/platform_roles.php';
requirePlatformAccess('dashboard');

include("sAdminHeader.php");

/*
| The dashboard is the one screen every team opens, so the money on it
| follows the same rule as the Billing module rather than a second one
| written here. Marketing & HR get the rest of the page.
*/
$showsMoney = platformCan('billing');


/*
|--------------------------------------------------------------------------
| PLATFORM METRICS
|--------------------------------------------------------------------------
|
| Everything here is counted from live data. Nothing is hardcoded, so an
| empty platform honestly reports zeros rather than inventing traction.
|
*/

$summary = $conn->query("
    SELECT
        (SELECT COUNT(*) FROM company) AS total_companies,
        (SELECT COUNT(*) FROM company WHERE status = 'Active') AS active_companies,
        (SELECT COUNT(*) FROM company WHERE status = 'Pending') AS pending_companies,
        (SELECT COUNT(*) FROM users WHERE company_id IS NOT NULL) AS total_users,
        (SELECT COUNT(*) FROM branch) AS total_branches,
        (SELECT COUNT(*) FROM subscription_plans WHERE status = 'Active') AS active_plans
")->fetch_assoc();


// Monthly recurring revenue: every live subscription normalised to a
// monthly figure, so yearly plans do not overstate the number.
$revenue = $conn->query("
    SELECT
        COUNT(*) AS active_subscriptions,
        SUM(CASE WHEN billing_cycle = 'Yearly' THEN amount / 12 ELSE amount END) AS mrr
    FROM company_subscriptions
    WHERE status = 'Active' AND expiry_date >= CURDATE()
")->fetch_assoc();

$expiringSoon = $conn->query("
    SELECT COUNT(*) AS n
    FROM company_subscriptions
    WHERE status = 'Active'
      AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
")->fetch_assoc();

$needsAttention = $conn->query("
    SELECT COUNT(DISTINCT c.company_id) AS n
    FROM company c
    LEFT JOIN company_subscriptions cs ON cs.company_id = c.company_id
    WHERE cs.subscription_id IS NULL
       OR cs.status IN ('Expired', 'Cancelled')
       OR cs.expiry_date < CURDATE()
")->fetch_assoc();


$planMix = $conn->query("
    SELECT sp.plan_name, sp.monthly_price,
           COUNT(cs.subscription_id) AS subscriber_count
    FROM subscription_plans sp
    LEFT JOIN company_subscriptions cs
        ON cs.plan_id = sp.plan_id
       AND cs.status = 'Active'
       AND cs.expiry_date >= CURDATE()
    WHERE sp.status = 'Active'
    GROUP BY sp.plan_id, sp.plan_name, sp.monthly_price, sp.plan_order
    ORDER BY sp.plan_order, sp.plan_id
");


$atRisk = $conn->query("
    SELECT c.company_id, c.company_name, c.company_code,
           sp.plan_name, cs.status AS subscription_status, cs.expiry_date
    FROM company c
    LEFT JOIN company_subscriptions cs
        ON cs.subscription_id = (
            SELECT subscription_id FROM company_subscriptions
            WHERE company_id = c.company_id
            ORDER BY expiry_date DESC LIMIT 1
        )
    LEFT JOIN subscription_plans sp ON sp.plan_id = cs.plan_id
    WHERE cs.subscription_id IS NULL
       OR cs.status IN ('Expired', 'Cancelled')
       OR cs.expiry_date < CURDATE()
    ORDER BY cs.expiry_date IS NULL DESC, cs.expiry_date ASC
    LIMIT 8
");


$recentActivity = $conn->query("
    SELECT sh.action, sh.remarks, sh.created_at,
           c.company_name, u.fullname AS actor
    FROM subscription_history sh
    LEFT JOIN company_subscriptions cs ON cs.subscription_id = sh.subscription_id
    LEFT JOIN company c ON c.company_id = cs.company_id
    LEFT JOIN users u ON u.user_id = sh.created_by
    ORDER BY sh.created_at DESC
    LIMIT 8
");

?>

<div class="sa-page-head">
    <div>
        <h3 class="sa-page-title">Platform Dashboard</h3>
        <p class="sa-page-sub">
            Welcome back, <?= htmlspecialchars($_SESSION['fullname'] ?? 'Super Admin') ?>.
        </p>
    </div>
</div>


<div class="row g-3 mb-4">

    <?php if ($showsMoney): ?>
    <div class="col-xl-3 col-md-6">
        <div class="sa-stat p-3 d-flex justify-content-between align-items-center">
            <div>
                <div class="text-muted small">Monthly Recurring Revenue</div>
                <div class="sa-stat-value">&#8369;<?= number_format((float) ($revenue['mrr'] ?? 0), 2) ?></div>
                <div class="sa-stat-label"><?= number_format((int) ($revenue['active_subscriptions'] ?? 0)) ?> active subscription(s)</div>
            </div>
            <div class="sa-stat-icon sa-tone-ok"><i class="bi bi-cash-stack"></i></div>
        </div>
    </div>
    <?php endif; ?>

    <div class="col-xl-3 col-md-6">
        <div class="sa-stat p-3 d-flex justify-content-between align-items-center">
            <div>
                <div class="text-muted small">Companies</div>
                <div class="sa-stat-value"><?= number_format((int) $summary['total_companies']) ?></div>
                <div class="sa-stat-label">
                    <?= number_format((int) $summary['active_companies']) ?> active &middot;
                    <?= number_format((int) $summary['pending_companies']) ?> pending
                </div>
            </div>
            <div class="sa-stat-icon sa-tone-navy"><i class="bi bi-buildings"></i></div>
        </div>
    </div>

    <div class="col-xl-3 col-md-6">
        <div class="sa-stat p-3 d-flex justify-content-between align-items-center">
            <div>
                <div class="text-muted small">Expiring in 30 Days</div>
                <div class="sa-stat-value"><?= number_format((int) $expiringSoon['n']) ?></div>
                <div class="sa-stat-label">renewals to chase</div>
            </div>
            <div class="sa-stat-icon sa-tone-warn"><i class="bi bi-hourglass-split"></i></div>
        </div>
    </div>

    <div class="col-xl-3 col-md-6">
        <div class="sa-stat p-3 d-flex justify-content-between align-items-center">
            <div>
                <div class="text-muted small">Needs Attention</div>
                <div class="sa-stat-value"><?= number_format((int) $needsAttention['n']) ?></div>
                <div class="sa-stat-label">lapsed or unsubscribed</div>
            </div>
            <div class="sa-stat-icon sa-tone-bad"><i class="bi bi-exclamation-triangle"></i></div>
        </div>
    </div>

</div>


<div class="row g-3 mb-4">

    <div class="col-md-4">
        <div class="sa-stat p-3 d-flex justify-content-between align-items-center">
            <div>
                <div class="text-muted small">Tenant Users</div>
                <div class="sa-stat-value"><?= number_format((int) $summary['total_users']) ?></div>
            </div>
            <div class="sa-stat-icon sa-tone-alt"><i class="bi bi-people"></i></div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="sa-stat p-3 d-flex justify-content-between align-items-center">
            <div>
                <div class="text-muted small">Branches</div>
                <div class="sa-stat-value"><?= number_format((int) $summary['total_branches']) ?></div>
            </div>
            <div class="sa-stat-icon sa-tone-accent"><i class="bi bi-shop"></i></div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="sa-stat p-3 d-flex justify-content-between align-items-center">
            <div>
                <div class="text-muted small">Active Plans</div>
                <div class="sa-stat-value"><?= number_format((int) $summary['active_plans']) ?></div>
            </div>
            <div class="sa-stat-icon sa-tone-warn"><i class="bi bi-layers"></i></div>
        </div>
    </div>

</div>


<div class="row g-3">

    <div class="col-lg-4">

        <div class="sa-panel h-100">

            <div class="sa-panel-head">Plan Distribution</div>

            <div class="p-3">

                <?php
                $planRows = [];
                $maxSubs = 0;

                while ($p = $planMix->fetch_assoc()) {
                    $planRows[] = $p;
                    $maxSubs = max($maxSubs, (int) $p['subscriber_count']);
                }
                ?>

                <?php if (count($planRows) === 0): ?>

                    <div class="text-muted text-center py-4">No active plans.</div>

                <?php else: ?>

                    <?php foreach ($planRows as $p): ?>

                        <?php
                        $count = (int) $p['subscriber_count'];
                        $width = $maxSubs > 0 ? round(($count / $maxSubs) * 100) : 0;
                        ?>

                        <div class="mb-3">

                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="sa-name">
                                    <?= htmlspecialchars($p['plan_name']) ?>
                                </span>
                                <span class="text-muted small"><?= $count ?></span>
                            </div>

                            <div class="sa-bar">
                                <span style="width:<?= $width ?>%;"></span>
                            </div>

                            <div class="sa-stat-label mt-1">
                                &#8369;<?= number_format((float) $p['monthly_price'], 2) ?> / month
                            </div>

                        </div>

                    <?php endforeach; ?>

                <?php endif; ?>

            </div>

        </div>

    </div>


    <div class="col-lg-8">

        <div class="sa-panel h-100">

            <div class="sa-panel-head d-flex justify-content-between align-items-center">
                <span>Companies Needing Attention</span>
                <a href="company.php" class="btn btn-sm sa-btn-soft">View all</a>
            </div>

            <div class="table-responsive">

                <table class="table sa-table">

                    <thead>
                        <tr>
                            <th class="ps-4">Company</th>
                            <th>Plan</th>
                            <th>Subscription</th>
                            <th class="pe-4">Action</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php $hasAtRisk = false; ?>

                        <?php while ($r = $atRisk->fetch_assoc()): $hasAtRisk = true; ?>

                            <tr>

                                <td class="ps-4">
                                    <div class="sa-name">
                                        <?= htmlspecialchars($r['company_name']) ?>
                                    </div>
                                    <div class="sa-stat-label"><?= htmlspecialchars($r['company_code'] ?? '') ?></div>
                                </td>

                                <td><?= htmlspecialchars($r['plan_name'] ?: 'No plan') ?></td>

                                <td>
                                    <?php if ($r['subscription_status'] === null): ?>
                                        <span class="badge bg-secondary">Never subscribed</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger">
                                            <?= htmlspecialchars($r['subscription_status'] === 'Active' ? 'Lapsed' : $r['subscription_status']) ?>
                                        </span>
                                        <div class="sa-stat-label">
                                            <?= htmlspecialchars(date('M d, Y', strtotime($r['expiry_date']))) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <td class="pe-4">
                                    <?php if (platformCan('subscriptionManagement')): ?>
                                        <a href="subscriptionManagement.php" class="btn btn-sm sa-btn">
                                            Manage
                                        </a>
                                    <?php else: ?>
                                        <span class="sa-muted">Finance handles this</span>
                                    <?php endif; ?>
                                </td>

                            </tr>

                        <?php endwhile; ?>

                        <?php if (!$hasAtRisk): ?>
                            <tr>
                                <td colspan="4" class="text-center text-muted py-4">
                                    Every company has a live subscription.
                                </td>
                            </tr>
                        <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </div>

    </div>

</div>


<div class="row g-3 mt-1">

    <div class="col-12">

        <div class="sa-panel">

            <div class="sa-panel-head">Recent Subscription Activity</div>

            <?php $hasActivity = false; ?>

            <?php while ($a = $recentActivity->fetch_assoc()): $hasActivity = true; ?>

                <div class="sa-feed-item d-flex justify-content-between align-items-start gap-3">

                    <div>
                        <div class="sa-name">
                            <?= htmlspecialchars($a['action'] ?: 'Updated') ?>
                            <span class="text-muted fw-normal">
                                &mdash; <?= htmlspecialchars($a['company_name'] ?: 'Unknown company') ?>
                            </span>
                        </div>
                        <div class="text-muted small">
                            <?= htmlspecialchars($a['remarks'] ?: '') ?>
                        </div>
                    </div>

                    <div class="text-end sa-stat-label text-nowrap">
                        <?= htmlspecialchars(date('M d, Y g:i A', strtotime($a['created_at']))) ?>
                        <?php if (!empty($a['actor'])): ?>
                            <div>by <?= htmlspecialchars($a['actor']) ?></div>
                        <?php endif; ?>
                    </div>

                </div>

            <?php endwhile; ?>

            <?php if (!$hasActivity): ?>
                <div class="text-center text-muted py-4">No subscription activity recorded yet.</div>
            <?php endif; ?>

        </div>

    </div>

</div>


<?php include("sAdminFooter.php"); ?>
