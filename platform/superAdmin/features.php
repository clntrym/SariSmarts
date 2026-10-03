<?php

require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../includes/platform_roles.php';
requirePlatformAccess('features');


/*
|--------------------------------------------------------------------------
| CORE MODULES EDITOR
|--------------------------------------------------------------------------
|
| Drives both the "core modules" grid on the homepage and the full-width
| features page, which read from the same two tables.
|
*/

$alert = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['saveSection'])) {

    $badge = trim($_POST['badge'] ?? '');
    $title = trim($_POST['title'] ?? '');
    $desc  = trim($_POST['description'] ?? '');

    if ($badge === '' || $title === '') {
        $alert = ['icon' => 'error', 'title' => 'Missing Details', 'text' => 'Badge and title are required.'];
    } else {
        $stmt = $conn->prepare("UPDATE website_modules_section SET badge = ?, title = ?, description = ? WHERE section_id = ?");
        $id = (int) ($_POST['section_id'] ?? 1);
        $stmt->bind_param("sssi", $badge, $title, $desc, $id);
        $alert = $stmt->execute()
            ? ['icon' => 'success', 'title' => 'Section Saved', 'text' => 'The modules heading has been updated.']
            : ['icon' => 'error', 'title' => 'Save Failed', 'text' => $conn->error];
        $stmt->close();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['saveModule'])) {

    $moduleId = (int) ($_POST['module_id'] ?? 0);
    $order    = (int) ($_POST['module_order'] ?? 1);
    $name     = trim($_POST['module_name'] ?? '');
    $desc     = trim($_POST['module_description'] ?? '');
    $icon     = trim($_POST['icon'] ?? '');
    $iconBg   = trim($_POST['icon_bg'] ?? 'bg-sky-100');
    $iconCol  = trim($_POST['icon_color'] ?? 'text-sky-600');
    $status   = ($_POST['status'] ?? 'Active') === 'Inactive' ? 'Inactive' : 'Active';

    if ($name === '' || $desc === '' || $icon === '') {
        $alert = ['icon' => 'error', 'title' => 'Missing Details', 'text' => 'Name, description and icon are required.'];
    } elseif ($moduleId > 0 && !$conn->query("SELECT module_id FROM website_modules WHERE module_id = " . $moduleId . " LIMIT 1")->num_rows) {
        /* An UPDATE that matches nothing still succeeds, so a row somebody
       else deleted has to be caught here. */
        $alert = ['icon' => 'error', 'title' => 'Not Found', 'text' => 'That module no longer exists.'];
    } elseif ($moduleId > 0) {
        $stmt = $conn->prepare("
            UPDATE website_modules SET
                module_order = ?, module_name = ?, module_description = ?,
                icon = ?, icon_bg = ?, icon_color = ?, status = ?
            WHERE module_id = ?
        ");
        $stmt->bind_param("issssssi", $order, $name, $desc, $icon, $iconBg, $iconCol, $status, $moduleId);
        $alert = $stmt->execute()
            ? ['icon' => 'success', 'title' => 'Module Saved', 'text' => $name . ' has been updated.']
            : ['icon' => 'error', 'title' => 'Save Failed', 'text' => $conn->error];
        $stmt->close();
    } else {
        $stmt = $conn->prepare("
            INSERT INTO website_modules
                (module_order, module_name, module_description, icon, icon_bg, icon_color, status)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("issssss", $order, $name, $desc, $icon, $iconBg, $iconCol, $status);
        $alert = $stmt->execute()
            ? ['icon' => 'success', 'title' => 'Module Added', 'text' => $name . ' has been added.']
            : ['icon' => 'error', 'title' => 'Save Failed', 'text' => $conn->error];
        $stmt->close();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['deleteModule'])) {

    $moduleId = (int) ($_POST['module_id'] ?? 0);
    $stmt = $conn->prepare("DELETE FROM website_modules WHERE module_id = ?");
    $stmt->bind_param("i", $moduleId);
    $alert = $stmt->execute()
        ? ['icon' => 'success', 'title' => 'Module Removed', 'text' => 'The module has been removed.']
        : ['icon' => 'error', 'title' => 'Delete Failed', 'text' => $conn->error];
    $stmt->close();
}


include("sAdminHeader.php");

$section = $conn->query("SELECT * FROM website_modules_section ORDER BY section_id LIMIT 1")->fetch_assoc();
$modules = $conn->query("SELECT * FROM website_modules ORDER BY module_order");

$activeCount = (int) $conn->query("SELECT COUNT(*) n FROM website_modules WHERE status='Active'")->fetch_assoc()['n'];

?>

<div class="sa-page-head">
    <div>
        <h3 class="sa-page-title">Core Modules</h3>
        <p class="sa-page-sub">
            Shown on the homepage and the features page.
            <span class="badge bg-success ms-1"><?= $activeCount ?> active</span>
        </p>
    </div>
    <a href="<?= $BASE_URL ?>/features.php" target="_blank" class="btn sa-btn-soft">
        <i class="bi bi-box-arrow-up-right me-1"></i> View features page
    </a>
</div>


<div class="sa-panel mb-4">

    <div class="sa-panel-head">Section Heading</div>

    <?php if (!$section): ?>
        <div class="p-4 text-muted">No row yet - run <code>database/website_seed.sql</code>.</div>
    <?php else: ?>

        <form method="POST" class="p-4" data-confirm="Save the modules heading?"
            data-confirm-text="It shows on the homepage and the features page."
            data-confirm-button="Save">

            <input type="hidden" name="section_id" value="<?= (int) $section['section_id'] ?>">

            <div class="row g-3">

                <div class="col-md-4">
                    <label class="form-label">Badge</label>
                    <input type="text" name="badge" class="form-control" value="<?= htmlspecialchars($section['badge']) ?>" required>
                </div>

                <div class="col-md-8">
                    <label class="form-label">Title</label>
                    <input type="text" name="title" class="form-control" value="<?= htmlspecialchars($section['title']) ?>" required>
                </div>

                <div class="col-12">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-control" rows="2"><?= htmlspecialchars($section['description']) ?></textarea>
                </div>

            </div>

            <button type="submit" name="saveSection" class="btn sa-btn mt-3">
                Save Heading
            </button>

        </form>

    <?php endif; ?>

</div>


<div class="sa-panel">

    <div class="sa-panel-head d-flex justify-content-between align-items-center">
        <span>Modules</span>
        <button class="btn btn-sm sa-btn" id="btnAddModule">
            <i class="bi bi-plus-lg me-1"></i> Add Module
        </button>
    </div>

    <div class="table-responsive">

        <table class="table sa-table">

            <thead>
                <tr>
                    <th class="ps-4" style="width:70px;">Order</th>
                    <th style="width:70px;">Icon</th>
                    <th style="width:200px;">Name</th>
                    <th>Description</th>
                    <th style="width:110px;">Status</th>
                    <th class="pe-4" style="width:120px;">Action</th>
                </tr>
            </thead>

            <tbody>

                <?php $hasModules = false; ?>

                <?php while ($m = $modules->fetch_assoc()): $hasModules = true; ?>

                    <tr>
                        <td class="ps-4"><?= (int) $m['module_order'] ?></td>

                        <td>
                            <span class="sa-icon-tile <?= htmlspecialchars($m['icon_bg']) ?> <?= htmlspecialchars($m['icon_color']) ?>">
                                <i class="<?= htmlspecialchars($m['icon']) ?>"></i>
                            </span>
                        </td>

                        <td class="sa-name"><?= htmlspecialchars($m['module_name']) ?></td>

                        <td class="text-muted small"><?= htmlspecialchars($m['module_description']) ?></td>

                        <td>
                            <span class="badge <?= $m['status'] === 'Active' ? 'bg-success' : 'bg-secondary' ?>">
                                <?= htmlspecialchars($m['status']) ?>
                            </span>
                        </td>

                        <td class="pe-4">

                            <button class="btn btn-sm sa-btn-soft btn-edit-module"
                                data-module="<?= htmlspecialchars(json_encode($m), ENT_QUOTES, 'UTF-8') ?>">
                                <i class="bi bi-pencil"></i>
                            </button>

                            <form method="POST" class="d-inline"
                                data-confirm="Remove <?= htmlspecialchars($m['module_name'], ENT_QUOTES) ?>?"
                                data-confirm-text="It will disappear from the homepage and features page."
                                data-confirm-button="Remove" data-confirm-danger>
                                <input type="hidden" name="module_id" value="<?= (int) $m['module_id'] ?>">
                                <button type="submit" name="deleteModule" class="btn btn-sm sa-btn-soft text-danger">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>

                        </td>
                    </tr>

                <?php endwhile; ?>

                <?php if (!$hasModules): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">No modules yet.</td></tr>
                <?php endif; ?>

            </tbody>

        </table>

    </div>

</div>


<div class="modal fade" id="moduleModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">

            <form method="POST" data-confirm="Save this module?" data-confirm-button="Save">

                <div class="modal-header">
                    <h5 class="modal-title" id="moduleModalTitle">Add Module</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">

                    <input type="hidden" name="module_id" id="module_id" value="0">

                    <div class="row g-3">

                        <div class="col-md-3">
                            <label class="form-label">Order</label>
                            <input type="number" name="module_order" id="module_order" class="form-control" value="1" min="1">
                        </div>

                        <div class="col-md-9">
                            <label class="form-label">Module Name</label>
                            <input type="text" name="module_name" id="module_name" class="form-control" required>
                        </div>

                        <div class="col-12">
                            <label class="form-label">Description</label>
                            <textarea name="module_description" id="module_description" class="form-control" rows="2" required></textarea>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Icon</label>
                            <input type="text" name="icon" id="icon" class="form-control" value="bi bi-box-seam" required>
                            <div class="form-text"><code>bi bi-...</code></div>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Icon Background</label>
                            <input type="text" name="icon_bg" id="icon_bg" class="form-control" value="bg-sky-100">
                            <div class="form-text">Tailwind class</div>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Icon Color</label>
                            <input type="text" name="icon_color" id="icon_color" class="form-control" value="text-sky-600">
                            <div class="form-text">Tailwind class</div>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Status</label>
                            <select name="status" id="status" class="form-select">
                                <option>Active</option>
                                <option>Inactive</option>
                            </select>
                        </div>

                    </div>

                </div>

                <div class="modal-footer">
                    <button type="button" class="btn sa-btn-soft" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="saveModule" class="btn sa-btn">Save</button>
                </div>

            </form>

        </div>
    </div>
</div>


<script>
    document.addEventListener("DOMContentLoaded", function () {

        var modal = new bootstrap.Modal(document.getElementById("moduleModal"));
        var form = document.querySelector("#moduleModal form");

        document.getElementById("btnAddModule").addEventListener("click", function () {
            form.reset();
            document.getElementById("module_id").value = "0";
            document.getElementById("moduleModalTitle").textContent = "Add Module";
            modal.show();
        });

        document.addEventListener("click", function (e) {

            var btn = e.target.closest(".btn-edit-module");
            if (!btn) return;

            var m = JSON.parse(btn.dataset.module);

            document.getElementById("module_id").value = m.module_id;
            document.getElementById("module_order").value = m.module_order;
            document.getElementById("module_name").value = m.module_name;
            document.getElementById("module_description").value = m.module_description;
            document.getElementById("icon").value = m.icon;
            document.getElementById("icon_bg").value = m.icon_bg;
            document.getElementById("icon_color").value = m.icon_color;
            document.getElementById("status").value = m.status;

            document.getElementById("moduleModalTitle").textContent = "Edit Module";
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
