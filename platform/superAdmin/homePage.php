<?php

require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../includes/platform_roles.php';
requirePlatformAccess('homePage');


/*
|--------------------------------------------------------------------------
| HOMEPAGE SECTIONS EDITOR
|--------------------------------------------------------------------------
|
| Covers the two homepage sections that sit between the hero and the core
| modules: "Why SariSmart" (heading plus its four highlight cards) and the
| Unique Selling Point band.
|
*/

$alert = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['saveWhy'])) {

    $badge = trim($_POST['badge'] ?? '');
    $title = trim($_POST['title'] ?? '');
    $desc  = trim($_POST['description'] ?? '');

    if ($badge === '' || $title === '' || $desc === '') {
        $alert = ['icon' => 'error', 'title' => 'Missing Details', 'text' => 'All three fields are required.'];
    } else {
        $stmt = $conn->prepare("UPDATE website_why_section SET badge = ?, title = ?, description = ? WHERE section_id = ?");
        $id = (int) ($_POST['section_id'] ?? 1);
        $stmt->bind_param("sssi", $badge, $title, $desc, $id);
        $alert = $stmt->execute()
            ? ['icon' => 'success', 'title' => 'Section Saved', 'text' => 'The Why section has been updated.']
            : ['icon' => 'error', 'title' => 'Save Failed', 'text' => $conn->error];
        $stmt->close();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['saveUsp'])) {

    $badge = trim($_POST['usp_badge'] ?? '');
    $title = trim($_POST['usp_title'] ?? '');
    $desc  = trim($_POST['usp_description'] ?? '');

    if ($badge === '' || $title === '' || $desc === '') {
        $alert = ['icon' => 'error', 'title' => 'Missing Details', 'text' => 'All three fields are required.'];
    } else {
        $stmt = $conn->prepare("UPDATE website_usp_section SET badge = ?, title = ?, description = ? WHERE section_id = ?");
        $id = (int) ($_POST['usp_section_id'] ?? 1);
        $stmt->bind_param("sssi", $badge, $title, $desc, $id);
        $alert = $stmt->execute()
            ? ['icon' => 'success', 'title' => 'Section Saved', 'text' => 'The selling point section has been updated.']
            : ['icon' => 'error', 'title' => 'Save Failed', 'text' => $conn->error];
        $stmt->close();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['saveCard'])) {

    $cardId = (int) ($_POST['card_id'] ?? 0);
    $order  = (int) ($_POST['card_order'] ?? 1);
    $icon   = trim($_POST['icon'] ?? '');
    $title  = trim($_POST['card_title'] ?? '');
    $status = ($_POST['card_status'] ?? 'Active') === 'Inactive' ? 'Inactive' : 'Active';

    if ($icon === '' || $title === '') {
        $alert = ['icon' => 'error', 'title' => 'Missing Details', 'text' => 'Icon and title are required.'];
    } elseif ($cardId > 0 && !$conn->query("SELECT card_id FROM website_why_cards WHERE card_id = " . $cardId . " LIMIT 1")->num_rows) {
        /* An UPDATE that matches nothing still succeeds, so a row somebody
       else deleted has to be caught here. */
        $alert = ['icon' => 'error', 'title' => 'Not Found', 'text' => 'That card no longer exists.'];
    } elseif ($cardId > 0) {
        $stmt = $conn->prepare("UPDATE website_why_cards SET card_order = ?, icon = ?, title = ?, status = ? WHERE card_id = ?");
        $stmt->bind_param("isssi", $order, $icon, $title, $status, $cardId);
        $alert = $stmt->execute()
            ? ['icon' => 'success', 'title' => 'Card Saved', 'text' => $title . ' has been updated.']
            : ['icon' => 'error', 'title' => 'Save Failed', 'text' => $conn->error];
        $stmt->close();
    } else {
        $stmt = $conn->prepare("INSERT INTO website_why_cards (card_order, icon, title, status) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("isss", $order, $icon, $title, $status);
        $alert = $stmt->execute()
            ? ['icon' => 'success', 'title' => 'Card Added', 'text' => $title . ' has been added.']
            : ['icon' => 'error', 'title' => 'Save Failed', 'text' => $conn->error];
        $stmt->close();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['deleteCard'])) {

    $cardId = (int) ($_POST['card_id'] ?? 0);
    $stmt = $conn->prepare("DELETE FROM website_why_cards WHERE card_id = ?");
    $stmt->bind_param("i", $cardId);
    $alert = $stmt->execute()
        ? ['icon' => 'success', 'title' => 'Card Removed', 'text' => 'The highlight card has been removed.']
        : ['icon' => 'error', 'title' => 'Delete Failed', 'text' => $conn->error];
    $stmt->close();
}


include("sAdminHeader.php");

$why   = $conn->query("SELECT * FROM website_why_section ORDER BY section_id LIMIT 1")->fetch_assoc();
$usp   = $conn->query("SELECT * FROM website_usp_section ORDER BY section_id LIMIT 1")->fetch_assoc();
$cards = $conn->query("SELECT * FROM website_why_cards ORDER BY card_order");

?>

<div class="sa-page-head">
    <div>
        <h3 class="sa-page-title">Homepage Sections</h3>
        <p class="sa-page-sub">The "Why" block and the multi-branch selling point.</p>
    </div>
    <a href="<?= $BASE_URL ?>/index.php" target="_blank" class="btn sa-btn-soft">
        <i class="bi bi-box-arrow-up-right me-1"></i> View homepage
    </a>
</div>


<div class="row g-3">

    <div class="col-lg-6">

        <div class="sa-panel h-100">

            <div class="sa-panel-head">Why SariSmart</div>

            <?php if (!$why): ?>
                <div class="p-4 text-muted">No row yet - run <code>database/website_seed.sql</code>.</div>
            <?php else: ?>

                <form method="POST" class="p-4" data-confirm="Save the Why section?"
                    data-confirm-text="The change goes live on the homepage straight away."
                    data-confirm-button="Save">

                    <input type="hidden" name="section_id" value="<?= (int) $why['section_id'] ?>">

                    <div class="mb-3">
                        <label class="form-label">Badge</label>
                        <input type="text" name="badge" class="form-control" value="<?= htmlspecialchars($why['badge']) ?>" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Title</label>
                        <input type="text" name="title" class="form-control" value="<?= htmlspecialchars($why['title']) ?>" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="4" required><?= htmlspecialchars($why['description']) ?></textarea>
                    </div>

                    <button type="submit" name="saveWhy" class="btn sa-btn">Save Section</button>

                </form>

            <?php endif; ?>

        </div>

    </div>


    <div class="col-lg-6">

        <div class="sa-panel h-100">

            <div class="sa-panel-head">Unique Selling Point</div>

            <?php if (!$usp): ?>
                <div class="p-4 text-muted">No row yet - run <code>database/website_seed.sql</code>.</div>
            <?php else: ?>

                <form method="POST" class="p-4" data-confirm="Save the selling point section?"
                    data-confirm-text="The change goes live on the homepage straight away."
                    data-confirm-button="Save">

                    <input type="hidden" name="usp_section_id" value="<?= (int) $usp['section_id'] ?>">

                    <div class="mb-3">
                        <label class="form-label">Badge</label>
                        <input type="text" name="usp_badge" class="form-control" value="<?= htmlspecialchars($usp['badge']) ?>" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Title</label>
                        <input type="text" name="usp_title" class="form-control" value="<?= htmlspecialchars($usp['title']) ?>" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="usp_description" class="form-control" rows="4" required><?= htmlspecialchars($usp['description']) ?></textarea>
                        <div class="form-text">
                            The branch hierarchy diagram below this text is drawn by the page itself.
                        </div>
                    </div>

                    <button type="submit" name="saveUsp" class="btn sa-btn">Save Section</button>

                </form>

            <?php endif; ?>

        </div>

    </div>

</div>


<div class="sa-panel mt-4">

    <div class="sa-panel-head d-flex justify-content-between align-items-center">
        <span>Why Highlight Cards</span>
        <button class="btn btn-sm sa-btn" id="btnAddCard">
            <i class="bi bi-plus-lg me-1"></i> Add Card
        </button>
    </div>

    <div class="table-responsive">

        <table class="table sa-table">

            <thead>
                <tr>
                    <th class="ps-4" style="width:80px;">Order</th>
                    <th style="width:220px;">Icon</th>
                    <th>Title</th>
                    <th style="width:120px;">Status</th>
                    <th class="pe-4" style="width:120px;">Action</th>
                </tr>
            </thead>

            <tbody>

                <?php $hasCards = false; ?>

                <?php while ($c = $cards->fetch_assoc()): $hasCards = true; ?>

                    <tr>
                        <td class="ps-4"><?= (int) $c['card_order'] ?></td>
                        <td>
                            <i class="<?= htmlspecialchars($c['icon']) ?> me-2"></i>
                            <code class="small"><?= htmlspecialchars($c['icon']) ?></code>
                        </td>
                        <td><?= htmlspecialchars($c['title']) ?></td>
                        <td>
                            <span class="badge <?= $c['status'] === 'Active' ? 'bg-success' : 'bg-secondary' ?>">
                                <?= htmlspecialchars($c['status']) ?>
                            </span>
                        </td>
                        <td class="pe-4">

                            <button class="btn btn-sm sa-btn-soft btn-edit-card"
                                data-card="<?= htmlspecialchars(json_encode($c), ENT_QUOTES, 'UTF-8') ?>">
                                <i class="bi bi-pencil"></i>
                            </button>

                            <form method="POST" class="d-inline"
                                data-confirm="Remove <?= htmlspecialchars($c['title'], ENT_QUOTES) ?>?"
                                data-confirm-text="It will disappear from the Why section on the homepage."
                                data-confirm-button="Remove" data-confirm-danger>
                                <input type="hidden" name="card_id" value="<?= (int) $c['card_id'] ?>">
                                <button type="submit" name="deleteCard" class="btn btn-sm sa-btn-soft text-danger">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>

                        </td>
                    </tr>

                <?php endwhile; ?>

                <?php if (!$hasCards): ?>
                    <tr><td colspan="5" class="text-center text-muted py-4">No highlight cards yet.</td></tr>
                <?php endif; ?>

            </tbody>

        </table>

    </div>

</div>


<div class="modal fade" id="cardModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">

            <form method="POST" data-confirm="Save this highlight card?" data-confirm-button="Save">

                <div class="modal-header">
                    <h5 class="modal-title" id="cardModalTitle">Add Card</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">

                    <input type="hidden" name="card_id" id="card_id" value="0">

                    <div class="mb-3">
                        <label class="form-label">Order</label>
                        <input type="number" name="card_order" id="card_order" class="form-control" value="1" min="1">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Icon</label>
                        <input type="text" name="icon" id="icon" class="form-control" value="bi bi-boxes" required>
                        <div class="form-text">Bootstrap Icon class, e.g. <code>bi bi-shield-check</code>.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Title</label>
                        <input type="text" name="card_title" id="card_title" class="form-control" required>
                    </div>

                    <div class="mb-0">
                        <label class="form-label">Status</label>
                        <select name="card_status" id="card_status" class="form-select">
                            <option>Active</option>
                            <option>Inactive</option>
                        </select>
                    </div>

                </div>

                <div class="modal-footer">
                    <button type="button" class="btn sa-btn-soft" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="saveCard" class="btn sa-btn">Save</button>
                </div>

            </form>

        </div>
    </div>
</div>


<script>
    document.addEventListener("DOMContentLoaded", function () {

        var modal = new bootstrap.Modal(document.getElementById("cardModal"));
        var form = document.querySelector("#cardModal form");

        document.getElementById("btnAddCard").addEventListener("click", function () {
            form.reset();
            document.getElementById("card_id").value = "0";
            document.getElementById("cardModalTitle").textContent = "Add Card";
            modal.show();
        });

        document.addEventListener("click", function (e) {

            var btn = e.target.closest(".btn-edit-card");
            if (!btn) return;

            var c = JSON.parse(btn.dataset.card);

            document.getElementById("card_id").value = c.card_id;
            document.getElementById("card_order").value = c.card_order;
            document.getElementById("icon").value = c.icon;
            document.getElementById("card_title").value = c.title;
            document.getElementById("card_status").value = c.status;

            document.getElementById("cardModalTitle").textContent = "Edit Card";
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
