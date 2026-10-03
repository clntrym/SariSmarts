<?php

require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../includes/platform_roles.php';
require_once __DIR__ . '/../includes/audit.php';
requirePlatformAccess('marketplace');


/*
|--------------------------------------------------------------------------
| MARKETPLACE
|--------------------------------------------------------------------------
|
| The catalogue of paid add-ons sold on top of a subscription. pricing.php
| already tells customers that "Marketplace apps are billed separately per
| branch", so the price here is per branch per billing cycle.
|
| Nothing reaches a customer until an app is Active, which is why a new one
| starts Inactive.
|
*/

$alert = null;

$CATEGORIES = ['Operations', 'Sales', 'Insights', 'Workforce', 'Finance', 'Integrations'];
$CYCLES = ['Monthly', 'Yearly', 'One-time'];


/* Returns the first problem as a sentence, or null when the app is usable. */
function marketplaceAppProblem(array $post, array $categories, array $cycles): ?string
{
    if (trim($post['app_name'] ?? '') === '') {
        return 'App name is required.';
    }

    if (trim($post['short_description'] ?? '') === '') {
        return 'Short description is required.';
    }

    if (!in_array($post['category'] ?? '', $categories, true)) {
        return 'Please choose a category.';
    }

    if (!in_array($post['billing_cycle'] ?? '', $cycles, true)) {
        return 'Please choose a billing cycle.';
    }

    $price = $post['price_per_branch'] ?? '';

    if ($price === '' || !is_numeric($price) || (float) $price < 0) {
        return 'Price per branch must be a number of zero or more.';
    }

    $order = (string) ($post['app_order'] ?? '');

    if ($order === '' || !ctype_digit($order)) {
        return 'Display order must be a whole number.';
    }

    if (trim($post['icon'] ?? '') === '') {
        return 'Icon is required.';
    }

    if (!in_array($post['status'] ?? '', ['Active', 'Inactive'], true)) {
        return 'Please choose a valid status.';
    }

    return null;
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['saveApp'])) {

    $appId = (int) ($_POST['app_id'] ?? 0);
    $problem = marketplaceAppProblem($_POST, $CATEGORIES, $CYCLES);

    /* An UPDATE that matches nothing still succeeds, so an edit of an app
       somebody else deleted has to be caught here. */
    if ($problem === null && $appId > 0
        && !$conn->query("SELECT app_id FROM marketplace_apps WHERE app_id = " . $appId . " LIMIT 1")->num_rows) {
        $problem = 'That app no longer exists.';
    }

    if ($problem !== null) {

        $alert = ['icon' => 'error', 'title' => 'Missing Details', 'text' => $problem];

    } else {

        $order = (int) $_POST['app_order'];
        $name = trim($_POST['app_name']);
        $category = $_POST['category'];
        $short = trim($_POST['short_description']);
        $long = trim($_POST['description'] ?? '');
        $icon = trim($_POST['icon']);
        $iconBg = trim($_POST['icon_bg'] ?? 'bg-sky-100');
        $iconColor = trim($_POST['icon_color'] ?? 'text-sky-600');
        $price = (float) $_POST['price_per_branch'];
        $cycle = $_POST['billing_cycle'];
        $status = $_POST['status'];

        if ($appId > 0) {
            $stmt = $conn->prepare("
                UPDATE marketplace_apps SET
                    app_order = ?, app_name = ?, category = ?, short_description = ?,
                    description = ?, icon = ?, icon_bg = ?, icon_color = ?,
                    price_per_branch = ?, billing_cycle = ?, status = ?
                WHERE app_id = ?
            ");
            $stmt->bind_param(
                "isssssssdssi",
                $order, $name, $category, $short, $long, $icon, $iconBg, $iconColor,
                $price, $cycle, $status, $appId
            );
            $done = 'updated';
        } else {
            $stmt = $conn->prepare("
                INSERT INTO marketplace_apps
                    (app_order, app_name, category, short_description, description,
                     icon, icon_bg, icon_color, price_per_branch, billing_cycle, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param(
                "isssssssdss",
                $order, $name, $category, $short, $long, $icon, $iconBg, $iconColor,
                $price, $cycle, $status
            );
            $done = 'added';
        }

        $saved = $stmt->execute();
        $savedId = $appId > 0 ? $appId : (int) $conn->insert_id;

        $alert = $saved
            ? ['icon' => 'success', 'title' => 'App Saved', 'text' => $name . ' has been ' . $done . '.']
            : ['icon' => 'error', 'title' => 'Save Failed', 'text' => $conn->error];

        $stmt->close();

        if ($saved) {
            auditLog($conn, 'Marketplace app ' . $done, 'marketplace', $savedId,
                     $name . ' (' . $status . ', PHP ' . number_format($price, 2) . ' ' . $cycle . ')');
        }
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['deleteApp'])) {

    $appId = (int) ($_POST['app_id'] ?? 0);

    $doomed = $conn->query("SELECT app_name FROM marketplace_apps WHERE app_id = " . $appId . " LIMIT 1");
    $doomedName = $doomed && $doomed->num_rows ? $doomed->fetch_assoc()['app_name'] : 'Unknown app';

    $stmt = $conn->prepare("DELETE FROM marketplace_apps WHERE app_id = ?");
    $stmt->bind_param("i", $appId);
    $removed = $stmt->execute();

    $alert = $removed
        ? ['icon' => 'success', 'title' => 'App Removed', 'text' => 'The app has been removed from the catalogue.']
        : ['icon' => 'error', 'title' => 'Delete Failed', 'text' => $conn->error];
    $stmt->close();

    if ($removed) {
        auditLog($conn, 'Marketplace app deleted', 'marketplace', $appId, $doomedName);
    }
}


include("sAdminHeader.php");

$apps = $conn->query("SELECT * FROM marketplace_apps ORDER BY app_order, app_id");

$totals = $conn->query("
    SELECT
        COUNT(*) AS total,
        SUM(status = 'Active') AS live,
        COUNT(DISTINCT category) AS categories,
        SUM(status = 'Active' AND price_per_branch = 0) AS unpriced
    FROM marketplace_apps
")->fetch_assoc();

?>

<div class="sa-page-head">
    <div>
        <h3 class="sa-page-title">Marketplace</h3>
        <p class="sa-page-sub">
            Paid add-ons sold on top of a subscription, billed per branch.
        </p>
    </div>
    <button class="btn sa-btn" id="btnAddApp">
        <i class="bi bi-plus-lg me-1"></i> Add App
    </button>
</div>


<div class="row g-3 mb-4">

    <div class="col-md-4">
        <div class="sa-stat p-3 d-flex justify-content-between align-items-center">
            <div>
                <div class="sa-stat-label">Apps In Catalogue</div>
                <div class="sa-stat-value"><?= number_format((int) $totals['total']) ?></div>
            </div>
            <div class="sa-stat-icon sa-tone-navy"><i class="bi bi-grid-3x3-gap"></i></div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="sa-stat p-3 d-flex justify-content-between align-items-center">
            <div>
                <div class="sa-stat-label">Live To Customers</div>
                <div class="sa-stat-value"><?= number_format((int) $totals['live']) ?></div>
            </div>
            <div class="sa-stat-icon sa-tone-ok"><i class="bi bi-broadcast"></i></div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="sa-stat p-3 d-flex justify-content-between align-items-center">
            <div>
                <div class="sa-stat-label">Categories</div>
                <div class="sa-stat-value"><?= number_format((int) $totals['categories']) ?></div>
            </div>
            <div class="sa-stat-icon sa-tone-accent"><i class="bi bi-tags"></i></div>
        </div>
    </div>

</div>


<?php if ((int) $totals['unpriced'] > 0): ?>
    <div class="alert alert-warning d-flex gap-2 align-items-start">
        <i class="bi bi-exclamation-triangle mt-1"></i>
        <div class="small">
            <?= (int) $totals['unpriced'] ?> live app<?= (int) $totals['unpriced'] === 1 ? ' has' : 's have' ?>
            no price set, so <?= (int) $totals['unpriced'] === 1 ? 'it' : 'they' ?> would be billed at zero.
        </div>
    </div>
<?php endif; ?>


<div class="sa-panel">

    <div class="sa-panel-head">
        <span>App Catalogue</span>
        <span class="sa-muted">A new app starts Inactive until it is priced and reviewed.</span>
    </div>

    <div class="table-responsive">

        <table class="table sa-table">

            <thead>
                <tr>
                    <th class="ps-4" style="width:70px;">Order</th>
                    <th style="width:70px;">Icon</th>
                    <th style="width:200px;">App</th>
                    <th>Description</th>
                    <th style="width:130px;">Category</th>
                    <th style="width:150px;">Price / Branch</th>
                    <th style="width:110px;">Status</th>
                    <th class="pe-4" style="width:120px;">Action</th>
                </tr>
            </thead>

            <tbody>

                <?php $hasApps = false; ?>

                <?php while ($app = $apps->fetch_assoc()): $hasApps = true; ?>

                    <tr>
                        <td class="ps-4"><?= (int) $app['app_order'] ?></td>

                        <td>
                            <span class="sa-icon-tile">
                                <i class="<?= htmlspecialchars($app['icon']) ?>"></i>
                            </span>
                        </td>

                        <td class="sa-name"><?= htmlspecialchars($app['app_name']) ?></td>

                        <td class="text-muted small"><?= htmlspecialchars($app['short_description']) ?></td>

                        <td>
                            <span class="badge bg-light text-dark border">
                                <?= htmlspecialchars($app['category']) ?>
                            </span>
                        </td>

                        <td>
                            <?php if ((float) $app['price_per_branch'] > 0): ?>
                                <span class="sa-name">&#8369;<?= number_format((float) $app['price_per_branch'], 2) ?></span>
                                <div class="sa-muted"><?= htmlspecialchars($app['billing_cycle']) ?></div>
                            <?php else: ?>
                                <span class="text-muted">Not priced</span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <span class="badge <?= $app['status'] === 'Active' ? 'bg-success' : 'bg-secondary' ?>">
                                <?= htmlspecialchars($app['status']) ?>
                            </span>
                        </td>

                        <td class="pe-4">

                            <button class="btn btn-sm sa-btn-soft btn-edit-app"
                                data-app="<?= htmlspecialchars(json_encode($app), ENT_QUOTES, 'UTF-8') ?>">
                                <i class="bi bi-pencil"></i>
                            </button>

                            <form method="POST" class="d-inline"
                                data-confirm="Remove <?= htmlspecialchars($app['app_name'], ENT_QUOTES) ?>?"
                                data-confirm-text="It disappears from the catalogue for every company."
                                data-confirm-button="Remove" data-confirm-danger>
                                <input type="hidden" name="app_id" value="<?= (int) $app['app_id'] ?>">
                                <button type="submit" name="deleteApp" class="btn btn-sm sa-btn-soft text-danger">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>

                        </td>
                    </tr>

                <?php endwhile; ?>

                <?php if (!$hasApps): ?>
                    <tr>
                        <td colspan="8" class="sa-empty">
                            <i class="bi bi-puzzle d-block mb-2" style="font-size:28px;"></i>
                            No apps yet. Add the first one to start the catalogue.
                        </td>
                    </tr>
                <?php endif; ?>

            </tbody>

        </table>

    </div>

</div>


<div class="modal fade" id="appModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">

            <form method="POST" data-confirm="Save this app?"
                data-confirm-text="An Active app is visible to every company straight away."
                data-confirm-button="Save">

                <div class="modal-header">
                    <h5 class="modal-title" id="appModalTitle">Add App</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">

                    <input type="hidden" name="app_id" id="app_id" value="0">

                    <div class="row g-3">

                        <div class="col-md-3">
                            <label class="form-label sa-required">Order</label>
                            <input type="number" name="app_order" id="app_order" class="form-control"
                                value="1" min="0" required>
                        </div>

                        <div class="col-md-9">
                            <label class="form-label sa-required">App Name</label>
                            <input type="text" name="app_name" id="app_name" class="form-control"
                                maxlength="150" required>
                        </div>

                        <div class="col-12">
                            <label class="form-label sa-required">Short Description</label>
                            <input type="text" name="short_description" id="short_description"
                                class="form-control" maxlength="255" required>
                            <div class="form-text">One line, shown in the catalogue row.</div>
                        </div>

                        <div class="col-12">
                            <label class="form-label">Full Description</label>
                            <textarea name="description" id="description" class="form-control" rows="3"></textarea>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label sa-required">Category</label>
                            <select name="category" id="category" class="form-select" required>
                                <?php foreach ($CATEGORIES as $category): ?>
                                    <option value="<?= htmlspecialchars($category) ?>">
                                        <?= htmlspecialchars($category) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label sa-required">Price Per Branch</label>
                            <div class="input-group">
                                <span class="input-group-text">&#8369;</span>
                                <input type="number" step="0.01" min="0" name="price_per_branch"
                                    id="price_per_branch" class="form-control" value="0.00" required>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label sa-required">Billing Cycle</label>
                            <select name="billing_cycle" id="billing_cycle" class="form-select" required>
                                <?php foreach ($CYCLES as $cycle): ?>
                                    <option value="<?= htmlspecialchars($cycle) ?>">
                                        <?= htmlspecialchars($cycle) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label sa-required">Icon</label>
                            <input type="text" name="icon" id="icon" class="form-control"
                                value="bi bi-puzzle" required>
                            <div class="form-text"><code>bi bi-...</code></div>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Icon Background</label>
                            <input type="text" name="icon_bg" id="icon_bg" class="form-control" value="bg-sky-100">
                            <div class="form-text">Tailwind class</div>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Icon Color</label>
                            <input type="text" name="icon_color" id="icon_color" class="form-control"
                                value="text-sky-600">
                            <div class="form-text">Tailwind class</div>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label sa-required">Status</label>
                            <select name="status" id="status" class="form-select" required>
                                <option>Inactive</option>
                                <option>Active</option>
                            </select>
                            <div class="form-text">Active makes it visible to customers.</div>
                        </div>

                    </div>

                </div>

                <div class="modal-footer">
                    <button type="button" class="btn sa-btn-soft" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="saveApp" class="btn sa-btn">Save</button>
                </div>

            </form>

        </div>
    </div>
</div>


<script>
    document.addEventListener("DOMContentLoaded", function () {

        var modal = new bootstrap.Modal(document.getElementById("appModal"));
        var form = document.querySelector("#appModal form");

        function val(id, v) { document.getElementById(id).value = v; }

        document.getElementById("btnAddApp").addEventListener("click", function () {
            form.reset();
            val("app_id", "0");
            document.getElementById("appModalTitle").textContent = "Add App";
            modal.show();
        });

        document.addEventListener("click", function (e) {

            var btn = e.target.closest(".btn-edit-app");
            if (!btn) return;

            var a = JSON.parse(btn.dataset.app);

            val("app_id", a.app_id);
            val("app_order", a.app_order);
            val("app_name", a.app_name);
            val("short_description", a.short_description);
            val("description", a.description || "");
            val("category", a.category);
            val("price_per_branch", a.price_per_branch);
            val("billing_cycle", a.billing_cycle);
            val("icon", a.icon);
            val("icon_bg", a.icon_bg);
            val("icon_color", a.icon_color);
            val("status", a.status);

            document.getElementById("appModalTitle").textContent = "Edit App";
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
