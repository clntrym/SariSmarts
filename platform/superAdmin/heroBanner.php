<?php

require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../includes/platform_roles.php';
requirePlatformAccess('heroBanner');


/*
|--------------------------------------------------------------------------
| HERO BANNER EDITOR
|--------------------------------------------------------------------------
|
| Edits what the homepage shows above the fold: the badge, headline,
| description, both buttons, and the four trust badges underneath.
|
*/

$alert = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['saveHero'])) {

    $badge       = trim($_POST['badge'] ?? '');
    $title       = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $btn1Text    = trim($_POST['primary_btn_text'] ?? '');
    $btn1Link    = trim($_POST['primary_btn_link'] ?? '');
    $btn2Text    = trim($_POST['secondary_btn_text'] ?? '');
    $btn2Link    = trim($_POST['secondary_btn_link'] ?? '');

    if ($badge === '' || $title === '' || $description === '') {
        $alert = ['icon' => 'error', 'title' => 'Missing Details', 'text' => 'Badge, title and description are all required.'];
    } else {

        $stmt = $conn->prepare("
            UPDATE website_hero SET
                badge = ?, title = ?, description = ?,
                primary_btn_text = ?, primary_btn_link = ?,
                secondary_btn_text = ?, secondary_btn_link = ?
            WHERE hero_id = ?
        ");

        $heroId = (int) ($_POST['hero_id'] ?? 1);
        $stmt->bind_param("sssssssi", $badge, $title, $description, $btn1Text, $btn1Link, $btn2Text, $btn2Link, $heroId);

        $alert = $stmt->execute()
            ? ['icon' => 'success', 'title' => 'Hero Updated', 'text' => 'The homepage hero has been saved.']
            : ['icon' => 'error', 'title' => 'Save Failed', 'text' => $conn->error];

        $stmt->close();
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['saveBadge'])) {

    $badgeId = (int) ($_POST['badge_id'] ?? 0);
    $order   = (int) ($_POST['badge_order'] ?? 1);
    $icon    = trim($_POST['icon'] ?? '');
    $label   = trim($_POST['label'] ?? '');
    $status  = ($_POST['status'] ?? 'Active') === 'Inactive' ? 'Inactive' : 'Active';

    if ($label === '' || $icon === '') {
        $alert = ['icon' => 'error', 'title' => 'Missing Details', 'text' => 'Icon and label are required.'];
    } elseif ($badgeId > 0 && !$conn->query("SELECT badge_id FROM website_hero_badges WHERE badge_id = " . $badgeId . " LIMIT 1")->num_rows) {

        /* An UPDATE that matches nothing still succeeds, so a row somebody
           else deleted has to be caught here. */
        $alert = ['icon' => 'error', 'title' => 'Not Found', 'text' => 'That badge no longer exists.'];

    } elseif ($badgeId > 0) {

        $stmt = $conn->prepare("UPDATE website_hero_badges SET badge_order = ?, icon = ?, label = ?, status = ? WHERE badge_id = ?");
        $stmt->bind_param("isssi", $order, $icon, $label, $status, $badgeId);
        $alert = $stmt->execute()
            ? ['icon' => 'success', 'title' => 'Badge Updated', 'text' => $label . ' has been saved.']
            : ['icon' => 'error', 'title' => 'Save Failed', 'text' => $conn->error];
        $stmt->close();

    } else {

        $stmt = $conn->prepare("INSERT INTO website_hero_badges (badge_order, icon, label, status) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("isss", $order, $icon, $label, $status);
        $alert = $stmt->execute()
            ? ['icon' => 'success', 'title' => 'Badge Added', 'text' => $label . ' has been added.']
            : ['icon' => 'error', 'title' => 'Save Failed', 'text' => $conn->error];
        $stmt->close();
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['deleteBadge'])) {

    $badgeId = (int) ($_POST['badge_id'] ?? 0);

    $stmt = $conn->prepare("DELETE FROM website_hero_badges WHERE badge_id = ?");
    $stmt->bind_param("i", $badgeId);
    $alert = $stmt->execute()
        ? ['icon' => 'success', 'title' => 'Badge Removed', 'text' => 'The trust badge has been removed.']
        : ['icon' => 'error', 'title' => 'Delete Failed', 'text' => $conn->error];
    $stmt->close();
}


include("sAdminHeader.php");

$hero = $conn->query("SELECT * FROM website_hero ORDER BY hero_id LIMIT 1")->fetch_assoc();

$badges = $conn->query("SELECT * FROM website_hero_badges ORDER BY badge_order");

?>

<div class="sa-page-head">
    <div>
        <h3 class="sa-page-title">Hero Banner</h3>
        <p class="sa-page-sub">The first thing visitors see on the homepage.</p>
    </div>
</div>


<?php if (!$hero): ?>

    <div class="alert alert-warning">
        No hero row exists yet. Run <code>database/website_seed.sql</code> to create it.
    </div>

<?php else: ?>

    <div class="row g-3">

        <div class="col-lg-7">

            <div class="sa-panel">

                <div class="sa-panel-head">Content</div>

                <form method="POST" class="p-4" data-confirm="Save the hero banner?"
                    data-confirm-text="It is the first thing visitors see on the homepage."
                    data-confirm-button="Save">

                    <input type="hidden" name="hero_id" value="<?= (int) $hero['hero_id'] ?>">

                    <div class="mb-3">
                        <label class="form-label">Badge</label>
                        <input type="text" name="badge" class="form-control" maxlength="150"
                            value="<?= htmlspecialchars($hero['badge']) ?>" required>
                        <div class="form-text">Small pill above the headline.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Headline</label>
                        <input type="text" name="title" class="form-control" maxlength="255"
                            value="<?= htmlspecialchars($hero['title']) ?>" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="3" required><?= htmlspecialchars($hero['description']) ?></textarea>
                    </div>

                    <div class="row g-3">

                        <div class="col-md-6">
                            <label class="form-label">Primary Button</label>
                            <input type="text" name="primary_btn_text" class="form-control mb-2"
                                value="<?= htmlspecialchars($hero['primary_btn_text']) ?>">
                            <input type="text" name="primary_btn_link" class="form-control"
                                value="<?= htmlspecialchars($hero['primary_btn_link']) ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Secondary Button</label>
                            <input type="text" name="secondary_btn_text" class="form-control mb-2"
                                value="<?= htmlspecialchars($hero['secondary_btn_text']) ?>">
                            <input type="text" name="secondary_btn_link" class="form-control"
                                value="<?= htmlspecialchars($hero['secondary_btn_link']) ?>">
                        </div>

                    </div>

                    <button type="submit" name="saveHero" class="btn sa-btn mt-4">
                        Save Hero
                    </button>

                </form>

            </div>

        </div>


        <div class="col-lg-5">

            <div class="sa-panel h-100">

                <div class="sa-panel-head">Preview</div>

                <div class="p-3">

                    <div class="hero-preview">
                        <span class="pv-badge">&#11088; <?= htmlspecialchars($hero['badge']) ?></span>
                        <h2><?= htmlspecialchars($hero['title']) ?></h2>
                        <p><?= htmlspecialchars($hero['description']) ?></p>
                    </div>

                    <a href="<?= $BASE_URL ?>/index.php" target="_blank" class="btn btn-light border w-100 mt-3">
                        <i class="bi bi-box-arrow-up-right me-1"></i> Open live homepage
                    </a>

                </div>

            </div>

        </div>

    </div>


    <div class="sa-panel mt-4">

        <div class="sa-panel-head d-flex justify-content-between align-items-center">
            <span>Trust Badges</span>
            <button class="btn btn-sm sa-btn" id="btnAddBadge">
                <i class="bi bi-plus-lg me-1"></i> Add Badge
            </button>
        </div>

        <div class="table-responsive">

            <table class="table sa-table">

                <thead>
                    <tr>
                        <th class="ps-4" style="width:80px;">Order</th>
                        <th style="width:200px;">Icon</th>
                        <th>Label</th>
                        <th style="width:120px;">Status</th>
                        <th class="pe-4" style="width:120px;">Action</th>
                    </tr>
                </thead>

                <tbody>

                    <?php $hasBadges = false; ?>

                    <?php while ($b = $badges->fetch_assoc()): $hasBadges = true; ?>

                        <tr>
                            <td class="ps-4"><?= (int) $b['badge_order'] ?></td>
                            <td>
                                <i class="<?= htmlspecialchars($b['icon']) ?> me-2"></i>
                                <code class="small"><?= htmlspecialchars($b['icon']) ?></code>
                            </td>
                            <td><?= htmlspecialchars($b['label']) ?></td>
                            <td>
                                <span class="badge <?= $b['status'] === 'Active' ? 'bg-success' : 'bg-secondary' ?>">
                                    <?= htmlspecialchars($b['status']) ?>
                                </span>
                            </td>
                            <td class="pe-4">

                                <button class="btn btn-sm sa-btn-soft btn-edit-badge"
                                    data-badge="<?= htmlspecialchars(json_encode($b), ENT_QUOTES, 'UTF-8') ?>">
                                    <i class="bi bi-pencil"></i>
                                </button>

                                <form method="POST" class="d-inline"
                                    data-confirm="Remove <?= htmlspecialchars($b['label'], ENT_QUOTES) ?>?"
                                    data-confirm-text="It will disappear from under the homepage hero."
                                    data-confirm-button="Remove" data-confirm-danger>
                                    <input type="hidden" name="badge_id" value="<?= (int) $b['badge_id'] ?>">
                                    <button type="submit" name="deleteBadge" class="btn btn-sm sa-btn-soft text-danger">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>

                            </td>
                        </tr>

                    <?php endwhile; ?>

                    <?php if (!$hasBadges): ?>
                        <tr><td colspan="5" class="text-center text-muted py-4">No trust badges yet.</td></tr>
                    <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>


    <div class="modal fade" id="badgeModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">

                <form method="POST" data-confirm="Save this badge?" data-confirm-button="Save">

                    <div class="modal-header">
                        <h5 class="modal-title" id="badgeModalTitle">Add Badge</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body">

                        <input type="hidden" name="badge_id" id="badge_id" value="0">

                        <div class="mb-3">
                            <label class="form-label">Order</label>
                            <input type="number" name="badge_order" id="badge_order" class="form-control" value="1" min="1">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Icon</label>
                            <input type="text" name="icon" id="icon" class="form-control" value="bi bi-check-circle" required>
                            <div class="form-text">Bootstrap Icon class, e.g. <code>bi bi-shield-check</code>.</div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Label</label>
                            <input type="text" name="label" id="label" class="form-control" required>
                        </div>

                        <div class="mb-0">
                            <label class="form-label">Status</label>
                            <select name="status" id="status" class="form-select">
                                <option>Active</option>
                                <option>Inactive</option>
                            </select>
                        </div>

                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn sa-btn-soft" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="saveBadge" class="btn sa-btn">Save</button>
                    </div>

                </form>

            </div>
        </div>
    </div>


    <script>
        document.addEventListener("DOMContentLoaded", function () {

            var modal = new bootstrap.Modal(document.getElementById("badgeModal"));
            var form = document.querySelector("#badgeModal form");

            document.getElementById("btnAddBadge").addEventListener("click", function () {
                form.reset();
                document.getElementById("badge_id").value = "0";
                document.getElementById("badgeModalTitle").textContent = "Add Badge";
                modal.show();
            });

            document.addEventListener("click", function (e) {

                var btn = e.target.closest(".btn-edit-badge");
                if (!btn) return;

                var b = JSON.parse(btn.dataset.badge);

                document.getElementById("badge_id").value = b.badge_id;
                document.getElementById("badge_order").value = b.badge_order;
                document.getElementById("icon").value = b.icon;
                document.getElementById("label").value = b.label;
                document.getElementById("status").value = b.status;

                document.getElementById("badgeModalTitle").textContent = "Edit Badge";
                modal.show();
            });

        });
    </script>

<?php endif; ?>

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
