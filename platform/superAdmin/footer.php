<?php

require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../includes/platform_roles.php';
requirePlatformAccess('footer');


/*
|--------------------------------------------------------------------------
| FOOTER EDITOR
|--------------------------------------------------------------------------
|
| footer.php is included by every public page, so everything edited here
| shows up site-wide: the call-to-action band, the brand block, the four
| link columns and the small print beside the copyright notice.
|
*/

$alert = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['saveCta'])) {

    $badge     = trim($_POST['cta_badge'] ?? '');
    $title     = trim($_POST['cta_title'] ?? '');
    $highlight = trim($_POST['cta_title_highlight'] ?? '');
    $desc      = trim($_POST['cta_description'] ?? '');
    $btnText   = trim($_POST['cta_button_text'] ?? '');
    $btnLink   = trim($_POST['cta_button_link'] ?? '#');
    $footerId  = (int) ($_POST['footer_id'] ?? 1);

    if ($title === '' || $btnText === '') {
        $alert = ['icon' => 'error', 'title' => 'Missing Details', 'text' => 'Title and button text are required.'];
    } else {
        $stmt = $conn->prepare("
            UPDATE website_footer SET
                cta_badge = ?, cta_title = ?, cta_title_highlight = ?,
                cta_description = ?, cta_button_text = ?, cta_button_link = ?
            WHERE footer_id = ?
        ");
        $stmt->bind_param("ssssssi", $badge, $title, $highlight, $desc, $btnText, $btnLink, $footerId);
        $alert = $stmt->execute()
            ? ['icon' => 'success', 'title' => 'Call To Action Saved', 'text' => 'The banner above the footer has been updated.']
            : ['icon' => 'error', 'title' => 'Save Failed', 'text' => $conn->error];
        $stmt->close();
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['saveBrand'])) {

    $name      = trim($_POST['brand_name'] ?? '');
    $desc      = trim($_POST['brand_description'] ?? '');
    $email     = trim($_POST['contact_email'] ?? '');
    $phone     = trim($_POST['contact_phone'] ?? '');
    $copyright = trim($_POST['copyright_text'] ?? '');
    $footerId  = (int) ($_POST['footer_id'] ?? 1);

    if ($name === '') {
        $alert = ['icon' => 'error', 'title' => 'Missing Details', 'text' => 'The brand name is required.'];
    } else {
        $stmt = $conn->prepare("
            UPDATE website_footer SET
                brand_name = ?, brand_description = ?, contact_email = ?,
                contact_phone = ?, copyright_text = ?
            WHERE footer_id = ?
        ");
        $stmt->bind_param("sssssi", $name, $desc, $email, $phone, $copyright, $footerId);
        $alert = $stmt->execute()
            ? ['icon' => 'success', 'title' => 'Brand Saved', 'text' => 'The footer brand block has been updated.']
            : ['icon' => 'error', 'title' => 'Save Failed', 'text' => $conn->error];
        $stmt->close();
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['saveColumn'])) {

    $columnId = (int) ($_POST['column_id'] ?? 0);
    $order    = (int) ($_POST['column_order'] ?? 1);
    $heading  = trim($_POST['heading'] ?? '');
    $status   = ($_POST['status'] ?? 'Active') === 'Inactive' ? 'Inactive' : 'Active';

    if ($heading === '') {
        $alert = ['icon' => 'error', 'title' => 'Missing Details', 'text' => 'The column heading is required.'];
    } elseif ($columnId > 0 && !$conn->query("SELECT column_id FROM website_footer_columns WHERE column_id = " . $columnId . " LIMIT 1")->num_rows) {
        /* An UPDATE that matches nothing still succeeds, so a row
           somebody else deleted has to be caught here. */
        $alert = ['icon' => 'error', 'title' => 'Not Found', 'text' => 'That column no longer exists.'];
    } elseif ($columnId > 0) {
        $stmt = $conn->prepare("UPDATE website_footer_columns SET column_order = ?, heading = ?, status = ? WHERE column_id = ?");
        $stmt->bind_param("issi", $order, $heading, $status, $columnId);
        $alert = $stmt->execute()
            ? ['icon' => 'success', 'title' => 'Column Saved', 'text' => $heading . ' has been updated.']
            : ['icon' => 'error', 'title' => 'Save Failed', 'text' => $conn->error];
        $stmt->close();
    } else {
        $stmt = $conn->prepare("INSERT INTO website_footer_columns (column_order, heading, status) VALUES (?, ?, ?)");
        $stmt->bind_param("iss", $order, $heading, $status);
        $alert = $stmt->execute()
            ? ['icon' => 'success', 'title' => 'Column Added', 'text' => $heading . ' has been added.']
            : ['icon' => 'error', 'title' => 'Save Failed', 'text' => $conn->error];
        $stmt->close();
    }
}


/* The links table cascades on delete, so removing a column takes its
   links with it. The confirmation says so. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['deleteColumn'])) {

    $columnId = (int) ($_POST['column_id'] ?? 0);
    $stmt = $conn->prepare("DELETE FROM website_footer_columns WHERE column_id = ?");
    $stmt->bind_param("i", $columnId);
    $alert = $stmt->execute()
        ? ['icon' => 'success', 'title' => 'Column Removed', 'text' => 'The column and its links have been removed.']
        : ['icon' => 'error', 'title' => 'Delete Failed', 'text' => $conn->error];
    $stmt->close();
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['saveLink'])) {

    $linkId   = (int) ($_POST['link_id'] ?? 0);
    $columnId = (int) ($_POST['link_column_id'] ?? 0);
    $order    = (int) ($_POST['link_order'] ?? 1);
    $label    = trim($_POST['label'] ?? '');
    $url      = trim($_POST['url'] ?? '#');
    $status   = ($_POST['status'] ?? 'Active') === 'Inactive' ? 'Inactive' : 'Active';

    if ($label === '' || $columnId <= 0) {
        $alert = ['icon' => 'error', 'title' => 'Missing Details', 'text' => 'A column and a label are required.'];
    } elseif ($linkId > 0 && !$conn->query("SELECT link_id FROM website_footer_links WHERE link_id = " . $linkId . " LIMIT 1")->num_rows) {
        /* An UPDATE that matches nothing still succeeds, so a row
           somebody else deleted has to be caught here. */
        $alert = ['icon' => 'error', 'title' => 'Not Found', 'text' => 'That link no longer exists.'];
    } elseif ($linkId > 0) {
        $stmt = $conn->prepare("UPDATE website_footer_links SET column_id = ?, link_order = ?, label = ?, url = ?, status = ? WHERE link_id = ?");
        $stmt->bind_param("iisssi", $columnId, $order, $label, $url, $status, $linkId);
        $alert = $stmt->execute()
            ? ['icon' => 'success', 'title' => 'Link Saved', 'text' => $label . ' has been updated.']
            : ['icon' => 'error', 'title' => 'Save Failed', 'text' => $conn->error];
        $stmt->close();
    } else {
        $stmt = $conn->prepare("INSERT INTO website_footer_links (column_id, link_order, label, url, status) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("iisss", $columnId, $order, $label, $url, $status);
        $alert = $stmt->execute()
            ? ['icon' => 'success', 'title' => 'Link Added', 'text' => $label . ' has been added.']
            : ['icon' => 'error', 'title' => 'Save Failed', 'text' => $conn->error];
        $stmt->close();
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['deleteLink'])) {

    $linkId = (int) ($_POST['link_id'] ?? 0);
    $stmt = $conn->prepare("DELETE FROM website_footer_links WHERE link_id = ?");
    $stmt->bind_param("i", $linkId);
    $alert = $stmt->execute()
        ? ['icon' => 'success', 'title' => 'Link Removed', 'text' => 'The link has been removed.']
        : ['icon' => 'error', 'title' => 'Delete Failed', 'text' => $conn->error];
    $stmt->close();
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['saveLegal'])) {

    $legalId = (int) ($_POST['legal_id'] ?? 0);
    $order   = (int) ($_POST['legal_order'] ?? 1);
    $label   = trim($_POST['legal_label'] ?? '');
    $url     = trim($_POST['legal_url'] ?? '#');
    $status  = ($_POST['status'] ?? 'Active') === 'Inactive' ? 'Inactive' : 'Active';

    if ($label === '') {
        $alert = ['icon' => 'error', 'title' => 'Missing Details', 'text' => 'The label is required.'];
    } elseif ($legalId > 0 && !$conn->query("SELECT legal_id FROM website_footer_legal_links WHERE legal_id = " . $legalId . " LIMIT 1")->num_rows) {
        /* An UPDATE that matches nothing still succeeds, so a row
           somebody else deleted has to be caught here. */
        $alert = ['icon' => 'error', 'title' => 'Not Found', 'text' => 'That link no longer exists.'];
    } elseif ($legalId > 0) {
        $stmt = $conn->prepare("UPDATE website_footer_legal_links SET link_order = ?, label = ?, url = ?, status = ? WHERE legal_id = ?");
        $stmt->bind_param("isssi", $order, $label, $url, $status, $legalId);
        $alert = $stmt->execute()
            ? ['icon' => 'success', 'title' => 'Link Saved', 'text' => $label . ' has been updated.']
            : ['icon' => 'error', 'title' => 'Save Failed', 'text' => $conn->error];
        $stmt->close();
    } else {
        $stmt = $conn->prepare("INSERT INTO website_footer_legal_links (link_order, label, url, status) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("isss", $order, $label, $url, $status);
        $alert = $stmt->execute()
            ? ['icon' => 'success', 'title' => 'Link Added', 'text' => $label . ' has been added.']
            : ['icon' => 'error', 'title' => 'Save Failed', 'text' => $conn->error];
        $stmt->close();
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['deleteLegal'])) {

    $legalId = (int) ($_POST['legal_id'] ?? 0);
    $stmt = $conn->prepare("DELETE FROM website_footer_legal_links WHERE legal_id = ?");
    $stmt->bind_param("i", $legalId);
    $alert = $stmt->execute()
        ? ['icon' => 'success', 'title' => 'Link Removed', 'text' => 'The link has been removed.']
        : ['icon' => 'error', 'title' => 'Delete Failed', 'text' => $conn->error];
    $stmt->close();
}


include("sAdminHeader.php");

$footer = $conn->query("SELECT * FROM website_footer ORDER BY footer_id LIMIT 1")->fetch_assoc();

$columns = [];
$columnResult = $conn->query("SELECT * FROM website_footer_columns ORDER BY column_order, column_id");
while ($column = $columnResult->fetch_assoc()) {
    $column['links'] = 0;
    $columns[$column['column_id']] = $column;
}

$links = [];
$linkResult = $conn->query("SELECT * FROM website_footer_links ORDER BY column_id, link_order, link_id");
while ($link = $linkResult->fetch_assoc()) {
    $links[] = $link;
    if (isset($columns[$link['column_id']])) {
        $columns[$link['column_id']]['links']++;
    }
}

$legalLinks = $conn->query("SELECT * FROM website_footer_legal_links ORDER BY link_order, legal_id");

?>

<div class="sa-page-head">
    <div>
        <h3 class="sa-page-title">Footer</h3>
        <p class="sa-page-sub">Shown at the bottom of every public page.</p>
    </div>
    <a href="<?= $BASE_URL ?>/index.php" target="_blank" class="btn sa-btn-soft">
        <i class="bi bi-box-arrow-up-right me-1"></i> View website
    </a>
</div>


<?php if (!$footer): ?>

    <div class="sa-panel p-4 text-muted">
        No row yet - run <code>database/website_pages.sql</code>.
    </div>

<?php else: ?>

    <div class="sa-panel mb-4">

        <div class="sa-panel-head">Call To Action Band</div>

        <form method="POST" class="p-4" data-confirm="Save the call to action band?"
            data-confirm-text="It sits above the footer on every public page."
            data-confirm-button="Save">

            <input type="hidden" name="footer_id" value="<?= (int) $footer['footer_id'] ?>">

            <div class="row g-3">

                <div class="col-12">
                    <label class="form-label">Badge</label>
                    <input type="text" name="cta_badge" class="form-control"
                        value="<?= htmlspecialchars($footer['cta_badge']) ?>">
                </div>

                <div class="col-md-6">
                    <label class="form-label">Title</label>
                    <input type="text" name="cta_title" class="form-control"
                        value="<?= htmlspecialchars($footer['cta_title']) ?>" required>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Highlighted Second Line</label>
                    <input type="text" name="cta_title_highlight" class="form-control"
                        value="<?= htmlspecialchars($footer['cta_title_highlight']) ?>">
                    <div class="form-text">Printed under the title in the accent colour.</div>
                </div>

                <div class="col-12">
                    <label class="form-label">Description</label>
                    <textarea name="cta_description" class="form-control" rows="2"><?= htmlspecialchars($footer['cta_description']) ?></textarea>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Button Text</label>
                    <input type="text" name="cta_button_text" class="form-control"
                        value="<?= htmlspecialchars($footer['cta_button_text']) ?>" required>
                </div>

                <div class="col-md-8">
                    <label class="form-label">Button Link</label>
                    <input type="text" name="cta_button_link" class="form-control"
                        value="<?= htmlspecialchars($footer['cta_button_link']) ?>">
                    <div class="form-text">A page in the site root, e.g. <code>pricing.php</code>.</div>
                </div>

            </div>

            <button type="submit" name="saveCta" class="btn sa-btn mt-3">
                Save Call To Action
            </button>

        </form>

    </div>


    <div class="sa-panel mb-4">

        <div class="sa-panel-head">Brand &amp; Contact</div>

        <form method="POST" class="p-4" data-confirm="Save the footer brand block?"
            data-confirm-text="It shows at the bottom of every public page."
            data-confirm-button="Save">

            <input type="hidden" name="footer_id" value="<?= (int) $footer['footer_id'] ?>">

            <div class="row g-3">

                <div class="col-md-4">
                    <label class="form-label">Brand Name</label>
                    <input type="text" name="brand_name" class="form-control"
                        value="<?= htmlspecialchars($footer['brand_name']) ?>" required>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Email</label>
                    <input type="text" name="contact_email" class="form-control"
                        value="<?= htmlspecialchars($footer['contact_email']) ?>">
                </div>

                <div class="col-md-4">
                    <label class="form-label">Phone</label>
                    <input type="text" name="contact_phone" class="form-control"
                        value="<?= htmlspecialchars($footer['contact_phone']) ?>">
                </div>

                <div class="col-12">
                    <label class="form-label">Description</label>
                    <textarea name="brand_description" class="form-control" rows="2"><?= htmlspecialchars($footer['brand_description']) ?></textarea>
                </div>

                <div class="col-12">
                    <label class="form-label">Copyright Notice</label>
                    <input type="text" name="copyright_text" class="form-control"
                        value="<?= htmlspecialchars($footer['copyright_text']) ?>">
                    <div class="form-text">
                        <code>{year}</code> is replaced with the current year, so the notice never goes stale.
                    </div>
                </div>

            </div>

            <button type="submit" name="saveBrand" class="btn sa-btn mt-3">
                Save Brand
            </button>

        </form>

    </div>

<?php endif; ?>


<div class="sa-panel mb-4">

    <div class="sa-panel-head d-flex justify-content-between align-items-center">
        <span>Link Columns</span>
        <button class="btn btn-sm sa-btn" id="btnAddColumn">
            <i class="bi bi-plus-lg me-1"></i> Add Column
        </button>
    </div>

    <div class="table-responsive">

        <table class="table sa-table">

            <thead>
                <tr>
                    <th class="ps-4" style="width:70px;">Order</th>
                    <th>Heading</th>
                    <th style="width:110px;">Links</th>
                    <th style="width:110px;">Status</th>
                    <th class="pe-4" style="width:120px;">Action</th>
                </tr>
            </thead>

            <tbody>

                <?php if (count($columns) === 0): ?>
                    <tr><td colspan="5" class="text-center text-muted py-4">No columns yet.</td></tr>
                <?php endif; ?>

                <?php foreach ($columns as $col): ?>

                    <tr>
                        <td class="ps-4"><?= (int) $col['column_order'] ?></td>
                        <td class="sa-name"><?= htmlspecialchars($col['heading']) ?></td>
                        <td class="text-muted small"><?= (int) $col['links'] ?></td>
                        <td>
                            <span class="badge <?= $col['status'] === 'Active' ? 'bg-success' : 'bg-secondary' ?>">
                                <?= htmlspecialchars($col['status']) ?>
                            </span>
                        </td>
                        <td class="pe-4">

                            <button class="btn btn-sm sa-btn-soft btn-edit-column"
                                data-column="<?= htmlspecialchars(json_encode($col), ENT_QUOTES, 'UTF-8') ?>">
                                <i class="bi bi-pencil"></i>
                            </button>

                            <form method="POST" class="d-inline"
                                data-confirm="Remove <?= htmlspecialchars($col['heading'], ENT_QUOTES) ?>?"
                                data-confirm-text="The column and every link inside it are removed."
                                data-confirm-button="Remove" data-confirm-danger>
                                <input type="hidden" name="column_id" value="<?= (int) $col['column_id'] ?>">
                                <button type="submit" name="deleteColumn" class="btn btn-sm sa-btn-soft text-danger">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>

                        </td>
                    </tr>

                <?php endforeach; ?>

            </tbody>

        </table>

    </div>

</div>


<div class="sa-panel mb-4">

    <div class="sa-panel-head d-flex justify-content-between align-items-center">
        <span>Column Links</span>
        <button class="btn btn-sm sa-btn" id="btnAddLink"
            <?= count($columns) === 0 ? 'disabled' : '' ?>>
            <i class="bi bi-plus-lg me-1"></i> Add Link
        </button>
    </div>

    <div class="table-responsive">

        <table class="table sa-table">

            <thead>
                <tr>
                    <th class="ps-4" style="width:160px;">Column</th>
                    <th style="width:70px;">Order</th>
                    <th style="width:220px;">Label</th>
                    <th>URL</th>
                    <th style="width:110px;">Status</th>
                    <th class="pe-4" style="width:120px;">Action</th>
                </tr>
            </thead>

            <tbody>

                <?php if (count($links) === 0): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">No links yet.</td></tr>
                <?php endif; ?>

                <?php foreach ($links as $link): ?>

                    <tr>
                        <td class="ps-4 text-muted small">
                            <?= htmlspecialchars($columns[$link['column_id']]['heading'] ?? '-') ?>
                        </td>
                        <td><?= (int) $link['link_order'] ?></td>
                        <td class="sa-name"><?= htmlspecialchars($link['label']) ?></td>
                        <td class="text-muted small"><code><?= htmlspecialchars($link['url']) ?></code></td>
                        <td>
                            <span class="badge <?= $link['status'] === 'Active' ? 'bg-success' : 'bg-secondary' ?>">
                                <?= htmlspecialchars($link['status']) ?>
                            </span>
                        </td>
                        <td class="pe-4">

                            <button class="btn btn-sm sa-btn-soft btn-edit-link"
                                data-link="<?= htmlspecialchars(json_encode($link), ENT_QUOTES, 'UTF-8') ?>">
                                <i class="bi bi-pencil"></i>
                            </button>

                            <form method="POST" class="d-inline"
                                data-confirm="Remove <?= htmlspecialchars($link['label'], ENT_QUOTES) ?>?"
                                data-confirm-button="Remove" data-confirm-danger>
                                <input type="hidden" name="link_id" value="<?= (int) $link['link_id'] ?>">
                                <button type="submit" name="deleteLink" class="btn btn-sm sa-btn-soft text-danger">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>

                        </td>
                    </tr>

                <?php endforeach; ?>

            </tbody>

        </table>

    </div>

</div>


<div class="sa-panel">

    <div class="sa-panel-head d-flex justify-content-between align-items-center">
        <span>Legal Links</span>
        <button class="btn btn-sm sa-btn" id="btnAddLegal">
            <i class="bi bi-plus-lg me-1"></i> Add Link
        </button>
    </div>

    <div class="table-responsive">

        <table class="table sa-table">

            <thead>
                <tr>
                    <th class="ps-4" style="width:70px;">Order</th>
                    <th style="width:220px;">Label</th>
                    <th>URL</th>
                    <th style="width:110px;">Status</th>
                    <th class="pe-4" style="width:120px;">Action</th>
                </tr>
            </thead>

            <tbody>

                <?php $hasLegal = false; ?>

                <?php while ($l = $legalLinks->fetch_assoc()): $hasLegal = true; ?>

                    <tr>
                        <td class="ps-4"><?= (int) $l['link_order'] ?></td>
                        <td class="sa-name"><?= htmlspecialchars($l['label']) ?></td>
                        <td class="text-muted small"><code><?= htmlspecialchars($l['url']) ?></code></td>
                        <td>
                            <span class="badge <?= $l['status'] === 'Active' ? 'bg-success' : 'bg-secondary' ?>">
                                <?= htmlspecialchars($l['status']) ?>
                            </span>
                        </td>
                        <td class="pe-4">

                            <button class="btn btn-sm sa-btn-soft btn-edit-legal"
                                data-legal="<?= htmlspecialchars(json_encode($l), ENT_QUOTES, 'UTF-8') ?>">
                                <i class="bi bi-pencil"></i>
                            </button>

                            <form method="POST" class="d-inline"
                                data-confirm="Remove <?= htmlspecialchars($l['label'], ENT_QUOTES) ?>?"
                                data-confirm-button="Remove" data-confirm-danger>
                                <input type="hidden" name="legal_id" value="<?= (int) $l['legal_id'] ?>">
                                <button type="submit" name="deleteLegal" class="btn btn-sm sa-btn-soft text-danger">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>

                        </td>
                    </tr>

                <?php endwhile; ?>

                <?php if (!$hasLegal): ?>
                    <tr><td colspan="5" class="text-center text-muted py-4">No legal links yet.</td></tr>
                <?php endif; ?>

            </tbody>

        </table>

    </div>

</div>


<!-- COLUMN MODAL -->

<div class="modal fade" id="columnModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">

            <form method="POST" data-confirm="Save this column?" data-confirm-button="Save">

                <div class="modal-header">
                    <h5 class="modal-title" id="columnModalTitle">Add Column</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">

                    <input type="hidden" name="column_id" id="column_id" value="0">

                    <div class="mb-3">
                        <label class="form-label">Order</label>
                        <input type="number" name="column_order" id="column_order" class="form-control" value="1" min="1">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Heading</label>
                        <input type="text" name="heading" id="heading" class="form-control" required>
                    </div>

                    <div class="mb-0">
                        <label class="form-label">Status</label>
                        <select name="status" id="column_status" class="form-select">
                            <option>Active</option>
                            <option>Inactive</option>
                        </select>
                    </div>

                </div>

                <div class="modal-footer">
                    <button type="button" class="btn sa-btn-soft" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="saveColumn" class="btn sa-btn">Save</button>
                </div>

            </form>

        </div>
    </div>
</div>


<!-- LINK MODAL -->

<div class="modal fade" id="linkModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">

            <form method="POST" data-confirm="Save this link?" data-confirm-button="Save">

                <div class="modal-header">
                    <h5 class="modal-title" id="linkModalTitle">Add Link</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">

                    <input type="hidden" name="link_id" id="link_id" value="0">

                    <div class="mb-3">
                        <label class="form-label">Column</label>
                        <select name="link_column_id" id="link_column_id" class="form-select" required>
                            <?php foreach ($columns as $col): ?>
                                <option value="<?= (int) $col['column_id'] ?>">
                                    <?= htmlspecialchars($col['heading']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Order</label>
                        <input type="number" name="link_order" id="link_order" class="form-control" value="1" min="1">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Label</label>
                        <input type="text" name="label" id="label" class="form-control" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">URL</label>
                        <input type="text" name="url" id="url" class="form-control" value="#">
                        <div class="form-text">
                            A page in the site root such as <code>pricing.php</code>, a full
                            <code>https://</code> address, or <code>#</code> for a placeholder.
                        </div>
                    </div>

                    <div class="mb-0">
                        <label class="form-label">Status</label>
                        <select name="status" id="link_status" class="form-select">
                            <option>Active</option>
                            <option>Inactive</option>
                        </select>
                    </div>

                </div>

                <div class="modal-footer">
                    <button type="button" class="btn sa-btn-soft" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="saveLink" class="btn sa-btn">Save</button>
                </div>

            </form>

        </div>
    </div>
</div>


<!-- LEGAL LINK MODAL -->

<div class="modal fade" id="legalModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">

            <form method="POST" data-confirm="Save this legal link?" data-confirm-button="Save">

                <div class="modal-header">
                    <h5 class="modal-title" id="legalModalTitle">Add Legal Link</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">

                    <input type="hidden" name="legal_id" id="legal_id" value="0">

                    <div class="mb-3">
                        <label class="form-label">Order</label>
                        <input type="number" name="legal_order" id="legal_order" class="form-control" value="1" min="1">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Label</label>
                        <input type="text" name="legal_label" id="legal_label" class="form-control" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">URL</label>
                        <input type="text" name="legal_url" id="legal_url" class="form-control" value="#">
                    </div>

                    <div class="mb-0">
                        <label class="form-label">Status</label>
                        <select name="status" id="legal_status" class="form-select">
                            <option>Active</option>
                            <option>Inactive</option>
                        </select>
                    </div>

                </div>

                <div class="modal-footer">
                    <button type="button" class="btn sa-btn-soft" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="saveLegal" class="btn sa-btn">Save</button>
                </div>

            </form>

        </div>
    </div>
</div>


<script>
    document.addEventListener("DOMContentLoaded", function () {

        var columnModal = new bootstrap.Modal(document.getElementById("columnModal"));
        var linkModal = new bootstrap.Modal(document.getElementById("linkModal"));
        var legalModal = new bootstrap.Modal(document.getElementById("legalModal"));

        function val(id, v) { document.getElementById(id).value = v; }

        document.getElementById("btnAddColumn").addEventListener("click", function () {
            document.querySelector("#columnModal form").reset();
            val("column_id", "0");
            document.getElementById("columnModalTitle").textContent = "Add Column";
            columnModal.show();
        });

        document.getElementById("btnAddLink").addEventListener("click", function () {
            document.querySelector("#linkModal form").reset();
            val("link_id", "0");
            document.getElementById("linkModalTitle").textContent = "Add Link";
            linkModal.show();
        });

        document.getElementById("btnAddLegal").addEventListener("click", function () {
            document.querySelector("#legalModal form").reset();
            val("legal_id", "0");
            document.getElementById("legalModalTitle").textContent = "Add Legal Link";
            legalModal.show();
        });

        document.addEventListener("click", function (e) {

            var colBtn = e.target.closest(".btn-edit-column");

            if (colBtn) {
                var c = JSON.parse(colBtn.dataset.column);
                val("column_id", c.column_id);
                val("column_order", c.column_order);
                val("heading", c.heading);
                val("column_status", c.status);
                document.getElementById("columnModalTitle").textContent = "Edit Column";
                columnModal.show();
                return;
            }

            var linkBtn = e.target.closest(".btn-edit-link");

            if (linkBtn) {
                var l = JSON.parse(linkBtn.dataset.link);
                val("link_id", l.link_id);
                val("link_column_id", l.column_id);
                val("link_order", l.link_order);
                val("label", l.label);
                val("url", l.url);
                val("link_status", l.status);
                document.getElementById("linkModalTitle").textContent = "Edit Link";
                linkModal.show();
                return;
            }

            var legalBtn = e.target.closest(".btn-edit-legal");

            if (legalBtn) {
                var g = JSON.parse(legalBtn.dataset.legal);
                val("legal_id", g.legal_id);
                val("legal_order", g.link_order);
                val("legal_label", g.label);
                val("legal_url", g.url);
                val("legal_status", g.status);
                document.getElementById("legalModalTitle").textContent = "Edit Legal Link";
                legalModal.show();
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
