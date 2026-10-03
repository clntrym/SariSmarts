<?php

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../includes/platform_roles.php';
requirePlatformAccess('platformSection');


/*
|--------------------------------------------------------------------------
| PLATFORM PAGE EDITOR
|--------------------------------------------------------------------------
|
| Drives platform.php: the hero, the "how it connects" heading and the
| numbered capability cards under it.
|
| Every write runs above the sAdminHeader include. The header prints the
| whole page shell, so a handler sitting below it has already lost the
| chance to send a header and its output lands after finished HTML.
|
*/

$alert = null;


/*
| Bootstrap Icons only draw when the base "bi" class is on the element, so
| a value stored as plain "bi-upc-scan" rendered as an empty box both here
| and on the public page. Normalise on the way in.
*/
function platformIconClass($icon)
{
    $icon = trim($icon);

    if ($icon === '' || preg_match('/(^|\s)bi(\s|$)/', $icon)) {
        return $icon;
    }

    return str_starts_with($icon, 'bi-') ? 'bi ' . $icon : $icon;
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['savePlatformSection'])) {

    $heroTitle = trim($_POST['hero_title'] ?? '');
    $heroDesc  = trim($_POST['hero_description'] ?? '');
    $badge     = trim($_POST['section_badge'] ?? '');
    $title     = trim($_POST['section_title'] ?? '');
    $desc      = trim($_POST['section_description'] ?? '');
    $sectionId = (int) ($_POST['section_id'] ?? 1);

    if ($heroTitle === '' || $title === '') {
        $alert = ['icon' => 'error', 'title' => 'Missing Details', 'text' => 'Hero title and section title are required.'];
    } else {
        $stmt = $conn->prepare("
            UPDATE website_platform_section SET
                hero_title = ?, hero_description = ?,
                section_badge = ?, section_title = ?, section_description = ?
            WHERE section_id = ?
        ");
        $stmt->bind_param("sssssi", $heroTitle, $heroDesc, $badge, $title, $desc, $sectionId);
        $alert = $stmt->execute()
            ? ['icon' => 'success', 'title' => 'Section Saved', 'text' => 'The platform page heading has been updated.']
            : ['icon' => 'error', 'title' => 'Save Failed', 'text' => $conn->error];
        $stmt->close();
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['saveCard'])) {

    $order = (int) ($_POST['card_order'] ?? 1);
    $icon  = platformIconClass($_POST['icon'] ?? '');
    $title = trim($_POST['title'] ?? '');
    $desc  = trim($_POST['description'] ?? '');
    $status = ($_POST['status'] ?? 'Active') === 'Inactive' ? 'Inactive' : 'Active';

    if ($title === '' || $desc === '' || $icon === '') {
        $alert = ['icon' => 'error', 'title' => 'Missing Details', 'text' => 'Icon, title and description are required.'];
    } else {
        $stmt = $conn->prepare("
            INSERT INTO website_platform_cards (card_order, icon, title, description, status)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("issss", $order, $icon, $title, $desc, $status);
        $alert = $stmt->execute()
            ? ['icon' => 'success', 'title' => 'Card Added', 'text' => $title . ' has been added.']
            : ['icon' => 'error', 'title' => 'Save Failed', 'text' => $conn->error];
        $stmt->close();
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['updateCard'])) {

    $cardId = (int) ($_POST['card_id'] ?? 0);
    $order  = (int) ($_POST['card_order'] ?? 1);
    $icon   = platformIconClass($_POST['icon'] ?? '');
    $title  = trim($_POST['title'] ?? '');
    $desc   = trim($_POST['description'] ?? '');
    $status = ($_POST['status'] ?? 'Active') === 'Inactive' ? 'Inactive' : 'Active';

    if ($cardId <= 0
        || !$conn->query("SELECT card_id FROM website_platform_cards WHERE card_id = " . $cardId . " LIMIT 1")->num_rows) {
        /* An UPDATE that matches nothing still succeeds, so a row somebody
       else deleted has to be caught here. */
        $alert = ['icon' => 'error', 'title' => 'Card Not Found', 'text' => 'That card no longer exists.'];
    } elseif ($title === '' || $desc === '' || $icon === '') {
        $alert = ['icon' => 'error', 'title' => 'Missing Details', 'text' => 'Icon, title and description are required.'];
    } else {
        $stmt = $conn->prepare("
            UPDATE website_platform_cards SET
                card_order = ?, icon = ?, title = ?, description = ?, status = ?
            WHERE card_id = ?
        ");
        $stmt->bind_param("issssi", $order, $icon, $title, $desc, $status, $cardId);
        $alert = $stmt->execute()
            ? ['icon' => 'success', 'title' => 'Card Saved', 'text' => $title . ' has been updated.']
            : ['icon' => 'error', 'title' => 'Save Failed', 'text' => $conn->error];
        $stmt->close();
    }
}


/*
| Deleting used to hang off ?delete=<id>, which nothing linked to and which
| a link prefetch could fire on its own. It is a POST with a confirmation
| now, matching the modules editor.
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['deleteCard'])) {

    $cardId = (int) ($_POST['card_id'] ?? 0);

    $stmt = $conn->prepare("DELETE FROM website_platform_cards WHERE card_id = ?");
    $stmt->bind_param("i", $cardId);
    $alert = $stmt->execute()
        ? ['icon' => 'success', 'title' => 'Card Removed', 'text' => 'The card has been removed.']
        : ['icon' => 'error', 'title' => 'Delete Failed', 'text' => $conn->error];
    $stmt->close();
}


include('../sAdminHeader.php');
include('platformModal.php');

$section = $conn->query("SELECT * FROM website_platform_section ORDER BY section_id LIMIT 1")->fetch_assoc();
$cards   = $conn->query("SELECT * FROM website_platform_cards ORDER BY card_order ASC");

$activeCount = (int) $conn->query("SELECT COUNT(*) n FROM website_platform_cards WHERE status='Active'")->fetch_assoc()['n'];

?>

<div class="sa-page-head">
    <div>
        <h3 class="sa-page-title">Platform Page</h3>
        <p class="sa-page-sub">
            The hero and capability cards on the public platform page.
            <span class="badge bg-success ms-1"><?= $activeCount ?> active</span>
        </p>
    </div>
    <a href="<?= $BASE_URL ?>/platform.php" target="_blank" class="btn sa-btn-soft">
        <i class="bi bi-box-arrow-up-right me-1"></i> View platform page
    </a>
</div>


<div class="sa-panel mb-4">

    <div class="sa-panel-head">Section Heading</div>

    <?php if (!$section): ?>
        <div class="p-4 text-muted">No row yet - run <code>database/website_seed.sql</code>.</div>
    <?php else: ?>

        <form method="POST" class="p-4" data-confirm="Save the platform page heading?"
            data-confirm-text="The change goes live on the public page straight away."
            data-confirm-button="Save">

            <input type="hidden" name="section_id" value="<?= (int) $section['section_id'] ?>">

            <div class="row g-3">

                <div class="col-12">
                    <label class="form-label">Hero Title</label>
                    <input type="text" name="hero_title" class="form-control"
                        value="<?= htmlspecialchars($section['hero_title']) ?>" required>
                </div>

                <div class="col-12">
                    <label class="form-label">Hero Description</label>
                    <textarea name="hero_description" class="form-control" rows="2"><?= htmlspecialchars($section['hero_description']) ?></textarea>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Section Badge</label>
                    <input type="text" name="section_badge" class="form-control"
                        value="<?= htmlspecialchars($section['section_badge']) ?>">
                </div>

                <div class="col-md-8">
                    <label class="form-label">Section Title</label>
                    <input type="text" name="section_title" class="form-control"
                        value="<?= htmlspecialchars($section['section_title']) ?>" required>
                </div>

                <div class="col-12">
                    <label class="form-label">Section Description</label>
                    <textarea name="section_description" class="form-control" rows="2"><?= htmlspecialchars($section['section_description']) ?></textarea>
                </div>

            </div>

            <button type="submit" name="savePlatformSection" class="btn sa-btn mt-3">
                Save Heading
            </button>

        </form>

    <?php endif; ?>

</div>


<div class="sa-panel">

    <div class="sa-panel-head d-flex justify-content-between align-items-center">
        <span>Platform Cards</span>
        <button class="btn btn-sm sa-btn" id="btnAddCard"
            data-bs-toggle="modal" data-bs-target="#addPlatformCard">
            <i class="bi bi-plus-lg me-1"></i> Add Card
        </button>
    </div>

    <div class="table-responsive">

        <table class="table sa-table">

            <thead>
                <tr>
                    <th class="ps-4" style="width:70px;">Order</th>
                    <th style="width:70px;">Icon</th>
                    <th style="width:200px;">Title</th>
                    <th>Description</th>
                    <th style="width:110px;">Status</th>
                    <th class="pe-4" style="width:150px;">Action</th>
                </tr>
            </thead>

            <tbody>

                <?php $hasCards = false; ?>

                <?php while ($row = $cards->fetch_assoc()): $hasCards = true; ?>

                    <tr>
                        <td class="ps-4"><?= (int) $row['card_order'] ?></td>

                        <td>
                            <span class="sa-icon-tile">
                                <i class="<?= htmlspecialchars(platformIconClass($row['icon'])) ?>"></i>
                            </span>
                        </td>

                        <td class="sa-name"><?= htmlspecialchars($row['title']) ?></td>

                        <td class="text-muted small"><?= htmlspecialchars($row['description']) ?></td>

                        <td>
                            <span class="badge <?= $row['status'] === 'Active' ? 'bg-success' : 'bg-secondary' ?>">
                                <?= htmlspecialchars($row['status']) ?>
                            </span>
                        </td>

                        <td class="pe-4">

                            <button class="btn btn-sm sa-btn-soft btn-view-card"
                                data-card="<?= htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8') ?>">
                                <i class="bi bi-eye"></i>
                            </button>

                            <button class="btn btn-sm sa-btn-soft btn-edit-card"
                                data-card="<?= htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8') ?>">
                                <i class="bi bi-pencil"></i>
                            </button>

                            <form method="POST" class="d-inline"
                                data-confirm="Remove <?= htmlspecialchars($row['title'], ENT_QUOTES) ?>?"
                                data-confirm-text="It will disappear from the platform page."
                                data-confirm-button="Remove" data-confirm-danger>
                                <input type="hidden" name="card_id" value="<?= (int) $row['card_id'] ?>">
                                <button type="submit" name="deleteCard" class="btn btn-sm sa-btn-soft text-danger">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>

                        </td>
                    </tr>

                <?php endwhile; ?>

                <?php if (!$hasCards): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">No cards yet.</td></tr>
                <?php endif; ?>

            </tbody>

        </table>

    </div>

</div>


<script>
    document.addEventListener("DOMContentLoaded", function () {

        var editModal = new bootstrap.Modal(document.getElementById("editPlatformCard"));
        var viewModal = new bootstrap.Modal(document.getElementById("viewPlatformCard"));

        /* The card row already carries every field, so the view and edit
           modals fill themselves from it. The old code asked this page for
           JSON over AJAX, but the response started with the whole admin
           layout and jQuery never got past the parse. */
        document.addEventListener("click", function (e) {

            var editBtn = e.target.closest(".btn-edit-card");

            if (editBtn) {
                var c = JSON.parse(editBtn.dataset.card);

                document.getElementById("edit_card_id").value = c.card_id;
                document.getElementById("edit_order").value = c.card_order;
                document.getElementById("edit_icon").value = c.icon;
                document.getElementById("edit_title").value = c.title;
                document.getElementById("edit_description").value = c.description;
                document.getElementById("edit_status").value = c.status;

                editModal.show();
                return;
            }

            var viewBtn = e.target.closest(".btn-view-card");

            if (viewBtn) {
                var v = JSON.parse(viewBtn.dataset.card);

                document.getElementById("view_icon").className = v.icon + " fs-2";
                document.getElementById("view_order").textContent = v.card_order;
                document.getElementById("view_title").textContent = v.title;
                document.getElementById("view_description").textContent = v.description;
                document.getElementById("view_status").innerHTML = v.status === "Active"
                    ? '<span class="badge bg-success">Active</span>'
                    : '<span class="badge bg-secondary">Inactive</span>';

                viewModal.show();
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

<?php include('../sAdminFooter.php'); ?>
