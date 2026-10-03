<?php

require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../includes/platform_roles.php';
requirePlatformAccess('pricing');


/*
|--------------------------------------------------------------------------
| PRICING PAGE EDITOR
|--------------------------------------------------------------------------
|
| The plan cards on pricing.php are built from subscription_plans and are
| edited under Subscription, not here. This page owns the rest of the
| page: the hero, the plan comparison table, the "what's included" grid
| and the FAQ.
|
*/

$alert = null;


/* One cell of the comparison table. 'yes' and 'no' are drawn as a check
   and a dash; anything else is printed as written. */
function compareCell($value)
{
    $value = trim($value);

    if (strcasecmp($value, 'yes') === 0) {
        return '<span class="text-success"><i class="bi bi-check-lg"></i></span>';
    }

    if ($value === '' || strcasecmp($value, 'no') === 0) {
        return '<span class="text-muted">&mdash;</span>';
    }

    return htmlspecialchars($value);
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['saveSection'])) {

    $heroBadge = trim($_POST['hero_badge'] ?? '');
    $heroTitle = trim($_POST['hero_title'] ?? '');
    $heroDesc  = trim($_POST['hero_description'] ?? '');
    $cmpBadge  = trim($_POST['compare_badge'] ?? '');
    $cmpTitle  = trim($_POST['compare_title'] ?? '');
    $col1      = trim($_POST['compare_col1'] ?? '');
    $col2      = trim($_POST['compare_col2'] ?? '');
    $col3      = trim($_POST['compare_col3'] ?? '');
    $cmpNote   = trim($_POST['compare_note'] ?? '');
    $faqBadge  = trim($_POST['faq_badge'] ?? '');
    $faqTitle  = trim($_POST['faq_title'] ?? '');
    $sectionId = (int) ($_POST['section_id'] ?? 1);

    if ($heroTitle === '' || $cmpTitle === '' || $col1 === '' || $col2 === '' || $col3 === '') {
        $alert = ['icon' => 'error', 'title' => 'Missing Details', 'text' => 'Hero title, comparison title and the three column headers are required.'];
    } else {
        $stmt = $conn->prepare("
            UPDATE website_pricing_section SET
                hero_badge = ?, hero_title = ?, hero_description = ?,
                compare_badge = ?, compare_title = ?,
                compare_col1 = ?, compare_col2 = ?, compare_col3 = ?,
                compare_note = ?, faq_badge = ?, faq_title = ?
            WHERE section_id = ?
        ");
        $stmt->bind_param(
            "sssssssssssi",
            $heroBadge, $heroTitle, $heroDesc,
            $cmpBadge, $cmpTitle, $col1, $col2, $col3,
            $cmpNote, $faqBadge, $faqTitle, $sectionId
        );
        $alert = $stmt->execute()
            ? ['icon' => 'success', 'title' => 'Section Saved', 'text' => 'The pricing page headings have been updated.']
            : ['icon' => 'error', 'title' => 'Save Failed', 'text' => $conn->error];
        $stmt->close();
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['saveRow'])) {

    $rowId  = (int) ($_POST['row_id'] ?? 0);
    $order  = (int) ($_POST['row_order'] ?? 1);
    $label  = trim($_POST['feature_label'] ?? '');
    $col1   = trim($_POST['col1_value'] ?? 'no');
    $col2   = trim($_POST['col2_value'] ?? 'no');
    $col3   = trim($_POST['col3_value'] ?? 'no');
    $status = ($_POST['status'] ?? 'Active') === 'Inactive' ? 'Inactive' : 'Active';

    if ($label === '') {
        $alert = ['icon' => 'error', 'title' => 'Missing Details', 'text' => 'The feature label is required.'];
    } elseif ($rowId > 0 && !$conn->query("SELECT row_id FROM website_pricing_compare WHERE row_id = " . $rowId . " LIMIT 1")->num_rows) {
        /* An UPDATE that matches nothing still succeeds, so a row
           somebody else deleted has to be caught here. */
        $alert = ['icon' => 'error', 'title' => 'Not Found', 'text' => 'That comparison row no longer exists.'];
    } elseif ($rowId > 0) {
        $stmt = $conn->prepare("
            UPDATE website_pricing_compare SET
                row_order = ?, feature_label = ?, col1_value = ?, col2_value = ?, col3_value = ?, status = ?
            WHERE row_id = ?
        ");
        $stmt->bind_param("isssssi", $order, $label, $col1, $col2, $col3, $status, $rowId);
        $alert = $stmt->execute()
            ? ['icon' => 'success', 'title' => 'Row Saved', 'text' => $label . ' has been updated.']
            : ['icon' => 'error', 'title' => 'Save Failed', 'text' => $conn->error];
        $stmt->close();
    } else {
        $stmt = $conn->prepare("
            INSERT INTO website_pricing_compare (row_order, feature_label, col1_value, col2_value, col3_value, status)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("isssss", $order, $label, $col1, $col2, $col3, $status);
        $alert = $stmt->execute()
            ? ['icon' => 'success', 'title' => 'Row Added', 'text' => $label . ' has been added.']
            : ['icon' => 'error', 'title' => 'Save Failed', 'text' => $conn->error];
        $stmt->close();
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['deleteRow'])) {

    $rowId = (int) ($_POST['row_id'] ?? 0);
    $stmt = $conn->prepare("DELETE FROM website_pricing_compare WHERE row_id = ?");
    $stmt->bind_param("i", $rowId);
    $alert = $stmt->execute()
        ? ['icon' => 'success', 'title' => 'Row Removed', 'text' => 'The comparison row has been removed.']
        : ['icon' => 'error', 'title' => 'Delete Failed', 'text' => $conn->error];
    $stmt->close();
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['saveFaq'])) {

    $faqId    = (int) ($_POST['faq_id'] ?? 0);
    $order    = (int) ($_POST['faq_order'] ?? 1);
    $question = trim($_POST['question'] ?? '');
    $answer   = trim($_POST['answer'] ?? '');
    $status   = ($_POST['status'] ?? 'Active') === 'Inactive' ? 'Inactive' : 'Active';

    if ($question === '' || $answer === '') {
        $alert = ['icon' => 'error', 'title' => 'Missing Details', 'text' => 'Question and answer are required.'];
    } elseif ($faqId > 0 && !$conn->query("SELECT faq_id FROM website_pricing_faq WHERE faq_id = " . $faqId . " LIMIT 1")->num_rows) {
        /* An UPDATE that matches nothing still succeeds, so a row
           somebody else deleted has to be caught here. */
        $alert = ['icon' => 'error', 'title' => 'Not Found', 'text' => 'That question no longer exists.'];
    } elseif ($faqId > 0) {
        $stmt = $conn->prepare("UPDATE website_pricing_faq SET faq_order = ?, question = ?, answer = ?, status = ? WHERE faq_id = ?");
        $stmt->bind_param("isssi", $order, $question, $answer, $status, $faqId);
        $alert = $stmt->execute()
            ? ['icon' => 'success', 'title' => 'Question Saved', 'text' => 'The question has been updated.']
            : ['icon' => 'error', 'title' => 'Save Failed', 'text' => $conn->error];
        $stmt->close();
    } else {
        $stmt = $conn->prepare("INSERT INTO website_pricing_faq (faq_order, question, answer, status) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("isss", $order, $question, $answer, $status);
        $alert = $stmt->execute()
            ? ['icon' => 'success', 'title' => 'Question Added', 'text' => 'The question has been added.']
            : ['icon' => 'error', 'title' => 'Save Failed', 'text' => $conn->error];
        $stmt->close();
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['deleteFaq'])) {

    $faqId = (int) ($_POST['faq_id'] ?? 0);
    $stmt = $conn->prepare("DELETE FROM website_pricing_faq WHERE faq_id = ?");
    $stmt->bind_param("i", $faqId);
    $alert = $stmt->execute()
        ? ['icon' => 'success', 'title' => 'Question Removed', 'text' => 'The question has been removed.']
        : ['icon' => 'error', 'title' => 'Delete Failed', 'text' => $conn->error];
    $stmt->close();
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['saveIncludedSection'])) {

    $badge = trim($_POST['inc_badge'] ?? '');
    $title = trim($_POST['inc_title'] ?? '');
    $desc  = trim($_POST['inc_description'] ?? '');
    $id    = (int) ($_POST['inc_section_id'] ?? 1);

    if ($title === '') {
        $alert = ['icon' => 'error', 'title' => 'Missing Details', 'text' => 'The title is required.'];
    } else {
        $stmt = $conn->prepare("UPDATE website_included_section SET badge = ?, title = ?, description = ? WHERE section_id = ?");
        $stmt->bind_param("sssi", $badge, $title, $desc, $id);
        $alert = $stmt->execute()
            ? ['icon' => 'success', 'title' => 'Section Saved', 'text' => 'The included section heading has been updated.']
            : ['icon' => 'error', 'title' => 'Save Failed', 'text' => $conn->error];
        $stmt->close();
    }
}


/*
| The bullet list under each "what's included" card lives in its own
| table. The form edits it as one line per feature, so a save replaces
| that card's rows rather than trying to diff them.
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['saveIncludedCard'])) {

    $cardId  = (int) ($_POST['card_id'] ?? 0);
    $order   = (int) ($_POST['card_order'] ?? 1);
    $icon    = trim($_POST['icon'] ?? '');
    $iconBg  = trim($_POST['icon_bg'] ?? 'bg-sky-100');
    $iconCol = trim($_POST['icon_color'] ?? 'text-sky-600');
    $title   = trim($_POST['card_title'] ?? '');
    $desc    = trim($_POST['card_description'] ?? '');
    $status  = ($_POST['status'] ?? 'Active') === 'Inactive' ? 'Inactive' : 'Active';

    $features = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $_POST['features'] ?? '')), function ($f) {
        return $f !== '';
    }));

    if ($title === '' || $desc === '' || $icon === '') {
        $alert = ['icon' => 'error', 'title' => 'Missing Details', 'text' => 'Icon, title and description are required.'];
    } elseif ($cardId > 0 && !$conn->query("SELECT card_id FROM website_included_cards WHERE card_id = " . $cardId . " LIMIT 1")->num_rows) {
        /* An UPDATE that matches nothing still succeeds, so a row
           somebody else deleted has to be caught here. */
        $alert = ['icon' => 'error', 'title' => 'Not Found', 'text' => 'That card no longer exists.'];
    } else {

        $ok = false;

        if ($cardId > 0) {
            $stmt = $conn->prepare("
                UPDATE website_included_cards SET
                    card_order = ?, icon = ?, icon_bg = ?, icon_color = ?,
                    title = ?, description = ?, status = ?
                WHERE card_id = ?
            ");
            $stmt->bind_param("issssssi", $order, $icon, $iconBg, $iconCol, $title, $desc, $status, $cardId);
            $ok = $stmt->execute();
            $stmt->close();
        } else {
            $stmt = $conn->prepare("
                INSERT INTO website_included_cards (card_order, icon, icon_bg, icon_color, title, description, status)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("issssss", $order, $icon, $iconBg, $iconCol, $title, $desc, $status);
            $ok = $stmt->execute();
            $cardId = $conn->insert_id;
            $stmt->close();
        }

        if ($ok) {

            $stmt = $conn->prepare("DELETE FROM website_included_features WHERE card_id = ?");
            $stmt->bind_param("i", $cardId);
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare("INSERT INTO website_included_features (card_id, feature_order, feature_name) VALUES (?, ?, ?)");
            foreach ($features as $i => $feature) {
                $position = $i + 1;
                $stmt->bind_param("iis", $cardId, $position, $feature);
                $stmt->execute();
            }
            $stmt->close();

            $alert = ['icon' => 'success', 'title' => 'Card Saved', 'text' => $title . ' has been saved.'];
        } else {
            $alert = ['icon' => 'error', 'title' => 'Save Failed', 'text' => $conn->error];
        }
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['deleteIncludedCard'])) {

    $cardId = (int) ($_POST['card_id'] ?? 0);

    $stmt = $conn->prepare("DELETE FROM website_included_features WHERE card_id = ?");
    $stmt->bind_param("i", $cardId);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("DELETE FROM website_included_cards WHERE card_id = ?");
    $stmt->bind_param("i", $cardId);
    $alert = $stmt->execute()
        ? ['icon' => 'success', 'title' => 'Card Removed', 'text' => 'The card has been removed.']
        : ['icon' => 'error', 'title' => 'Delete Failed', 'text' => $conn->error];
    $stmt->close();
}


include("sAdminHeader.php");

$section    = $conn->query("SELECT * FROM website_pricing_section ORDER BY section_id LIMIT 1")->fetch_assoc();
$compare    = $conn->query("SELECT * FROM website_pricing_compare ORDER BY row_order, row_id");
$faqs       = $conn->query("SELECT * FROM website_pricing_faq ORDER BY faq_order, faq_id");
$incSection = $conn->query("SELECT * FROM website_included_section ORDER BY section_id LIMIT 1")->fetch_assoc();

$incCards = [];
$cardResult = $conn->query("SELECT * FROM website_included_cards ORDER BY card_order, card_id");
while ($card = $cardResult->fetch_assoc()) {
    $card['features'] = [];
    $incCards[$card['card_id']] = $card;
}

if (count($incCards) > 0) {
    $featureResult = $conn->query("SELECT card_id, feature_name FROM website_included_features ORDER BY card_id, feature_order");
    while ($feature = $featureResult->fetch_assoc()) {
        if (isset($incCards[$feature['card_id']])) {
            $incCards[$feature['card_id']]['features'][] = $feature['feature_name'];
        }
    }
}

$activePlans = (int) $conn->query("SELECT COUNT(*) n FROM subscription_plans WHERE status='Active'")->fetch_assoc()['n'];

?>

<div class="sa-page-head">
    <div>
        <h3 class="sa-page-title">Pricing Page</h3>
        <p class="sa-page-sub">
            Hero, comparison table, included grid and FAQ.
            <span class="badge bg-success ms-1"><?= $activePlans ?> active plan<?= $activePlans === 1 ? '' : 's' ?></span>
        </p>
    </div>
    <a href="<?= $BASE_URL ?>/pricing.php" target="_blank" class="btn sa-btn-soft">
        <i class="bi bi-box-arrow-up-right me-1"></i> View pricing page
    </a>
</div>


<div class="alert alert-light border d-flex gap-2 align-items-start">
    <i class="bi bi-info-circle mt-1 sa-name"></i>
    <div class="small text-muted">
        The plan cards themselves - names, prices, features and roles - come from
        <a href="<?= $BASE_URL ?>/superAdmin/subscriptionManagement.php">Subscription</a>.
        Nothing on this page changes them.
    </div>
</div>


<div class="sa-panel mb-4">

    <div class="sa-panel-head">Page Headings</div>

    <?php if (!$section): ?>
        <div class="p-4 text-muted">No row yet - run <code>database/website_pages.sql</code>.</div>
    <?php else: ?>

        <form method="POST" class="p-4" data-confirm="Save the pricing page headings?"
            data-confirm-text="The change goes live on the pricing page straight away."
            data-confirm-button="Save">

            <input type="hidden" name="section_id" value="<?= (int) $section['section_id'] ?>">

            <div class="row g-3">

                <div class="col-md-4">
                    <label class="form-label">Hero Badge</label>
                    <input type="text" name="hero_badge" class="form-control"
                        value="<?= htmlspecialchars($section['hero_badge']) ?>">
                </div>

                <div class="col-md-8">
                    <label class="form-label">Hero Title</label>
                    <input type="text" name="hero_title" class="form-control"
                        value="<?= htmlspecialchars($section['hero_title']) ?>" required>
                </div>

                <div class="col-12">
                    <label class="form-label">Hero Description</label>
                    <textarea name="hero_description" class="form-control" rows="2"><?= htmlspecialchars($section['hero_description']) ?></textarea>
                </div>

                <div class="col-12"><hr class="my-1"></div>

                <div class="col-md-4">
                    <label class="form-label">Comparison Badge</label>
                    <input type="text" name="compare_badge" class="form-control"
                        value="<?= htmlspecialchars($section['compare_badge']) ?>">
                </div>

                <div class="col-md-8">
                    <label class="form-label">Comparison Title</label>
                    <input type="text" name="compare_title" class="form-control"
                        value="<?= htmlspecialchars($section['compare_title']) ?>" required>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Column 1 Header</label>
                    <input type="text" name="compare_col1" class="form-control"
                        value="<?= htmlspecialchars($section['compare_col1']) ?>" required>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Column 2 Header</label>
                    <input type="text" name="compare_col2" class="form-control"
                        value="<?= htmlspecialchars($section['compare_col2']) ?>" required>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Column 3 Header</label>
                    <input type="text" name="compare_col3" class="form-control"
                        value="<?= htmlspecialchars($section['compare_col3']) ?>" required>
                </div>

                <div class="col-12">
                    <label class="form-label">Note Under The Table</label>
                    <textarea name="compare_note" class="form-control" rows="2"><?= htmlspecialchars($section['compare_note']) ?></textarea>
                </div>

                <div class="col-12"><hr class="my-1"></div>

                <div class="col-md-4">
                    <label class="form-label">FAQ Badge</label>
                    <input type="text" name="faq_badge" class="form-control"
                        value="<?= htmlspecialchars($section['faq_badge']) ?>">
                </div>

                <div class="col-md-8">
                    <label class="form-label">FAQ Title</label>
                    <input type="text" name="faq_title" class="form-control"
                        value="<?= htmlspecialchars($section['faq_title']) ?>">
                </div>

            </div>

            <button type="submit" name="saveSection" class="btn sa-btn mt-3">
                Save Headings
            </button>

        </form>

    <?php endif; ?>

</div>


<div class="sa-panel mb-4">

    <div class="sa-panel-head d-flex justify-content-between align-items-center">
        <span>Plan Comparison Rows</span>
        <button class="btn btn-sm sa-btn" id="btnAddRow">
            <i class="bi bi-plus-lg me-1"></i> Add Row
        </button>
    </div>

    <div class="table-responsive">

        <table class="table sa-table">

            <thead>
                <tr>
                    <th class="ps-4" style="width:70px;">Order</th>
                    <th>Feature</th>
                    <th class="text-center"><?= htmlspecialchars($section['compare_col1'] ?? 'Column 1') ?></th>
                    <th class="text-center"><?= htmlspecialchars($section['compare_col2'] ?? 'Column 2') ?></th>
                    <th class="text-center"><?= htmlspecialchars($section['compare_col3'] ?? 'Column 3') ?></th>
                    <th style="width:110px;">Status</th>
                    <th class="pe-4" style="width:120px;">Action</th>
                </tr>
            </thead>

            <tbody>

                <?php $hasRows = false; ?>

                <?php while ($r = $compare->fetch_assoc()): $hasRows = true; ?>

                    <tr>
                        <td class="ps-4"><?= (int) $r['row_order'] ?></td>
                        <td class="sa-name"><?= htmlspecialchars($r['feature_label']) ?></td>
                        <td class="text-center"><?= compareCell($r['col1_value']) ?></td>
                        <td class="text-center"><?= compareCell($r['col2_value']) ?></td>
                        <td class="text-center"><?= compareCell($r['col3_value']) ?></td>
                        <td>
                            <span class="badge <?= $r['status'] === 'Active' ? 'bg-success' : 'bg-secondary' ?>">
                                <?= htmlspecialchars($r['status']) ?>
                            </span>
                        </td>
                        <td class="pe-4">

                            <button class="btn btn-sm sa-btn-soft btn-edit-row"
                                data-row="<?= htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8') ?>">
                                <i class="bi bi-pencil"></i>
                            </button>

                            <form method="POST" class="d-inline"
                                data-confirm="Remove <?= htmlspecialchars($r['feature_label'], ENT_QUOTES) ?>?"
                                data-confirm-text="The row disappears from the comparison table."
                                data-confirm-button="Remove" data-confirm-danger>
                                <input type="hidden" name="row_id" value="<?= (int) $r['row_id'] ?>">
                                <button type="submit" name="deleteRow" class="btn btn-sm sa-btn-soft text-danger">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>

                        </td>
                    </tr>

                <?php endwhile; ?>

                <?php if (!$hasRows): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">No comparison rows yet.</td></tr>
                <?php endif; ?>

            </tbody>

        </table>

    </div>

</div>


<div class="sa-panel mb-4">

    <div class="sa-panel-head">What's Included - Heading</div>

    <?php if (!$incSection): ?>
        <div class="p-4 text-muted">No row yet - run <code>database/website_content.sql</code>.</div>
    <?php else: ?>

        <form method="POST" class="p-4" data-confirm="Save the included section heading?"
            data-confirm-button="Save">

            <input type="hidden" name="inc_section_id" value="<?= (int) $incSection['section_id'] ?>">

            <div class="row g-3">

                <div class="col-md-4">
                    <label class="form-label">Badge</label>
                    <input type="text" name="inc_badge" class="form-control"
                        value="<?= htmlspecialchars($incSection['badge']) ?>">
                </div>

                <div class="col-md-8">
                    <label class="form-label">Title</label>
                    <input type="text" name="inc_title" class="form-control"
                        value="<?= htmlspecialchars($incSection['title']) ?>" required>
                </div>

                <div class="col-12">
                    <label class="form-label">Description</label>
                    <textarea name="inc_description" class="form-control" rows="2"><?= htmlspecialchars($incSection['description'] ?? '') ?></textarea>
                </div>

            </div>

            <button type="submit" name="saveIncludedSection" class="btn sa-btn mt-3">
                Save Heading
            </button>

        </form>

    <?php endif; ?>

</div>


<div class="sa-panel mb-4">

    <div class="sa-panel-head d-flex justify-content-between align-items-center">
        <span>What's Included - Cards</span>
        <button class="btn btn-sm sa-btn" id="btnAddIncCard">
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
                    <th>Features</th>
                    <th style="width:110px;">Status</th>
                    <th class="pe-4" style="width:120px;">Action</th>
                </tr>
            </thead>

            <tbody>

                <?php if (count($incCards) === 0): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">No cards yet.</td></tr>
                <?php endif; ?>

                <?php foreach ($incCards as $c): ?>

                    <tr>
                        <td class="ps-4"><?= (int) $c['card_order'] ?></td>

                        <td>
                            <span class="sa-icon-tile <?= htmlspecialchars($c['icon_bg']) ?> <?= htmlspecialchars($c['icon_color']) ?>">
                                <i class="<?= htmlspecialchars($c['icon']) ?>"></i>
                            </span>
                        </td>

                        <td class="sa-name"><?= htmlspecialchars($c['title']) ?></td>

                        <td class="text-muted small"><?= count($c['features']) ?> bullet<?= count($c['features']) === 1 ? '' : 's' ?></td>

                        <td>
                            <span class="badge <?= $c['status'] === 'Active' ? 'bg-success' : 'bg-secondary' ?>">
                                <?= htmlspecialchars($c['status']) ?>
                            </span>
                        </td>

                        <td class="pe-4">

                            <button class="btn btn-sm sa-btn-soft btn-edit-inc"
                                data-card="<?= htmlspecialchars(json_encode($c), ENT_QUOTES, 'UTF-8') ?>">
                                <i class="bi bi-pencil"></i>
                            </button>

                            <form method="POST" class="d-inline"
                                data-confirm="Remove <?= htmlspecialchars($c['title'], ENT_QUOTES) ?>?"
                                data-confirm-text="The card and all of its bullets are removed."
                                data-confirm-button="Remove" data-confirm-danger>
                                <input type="hidden" name="card_id" value="<?= (int) $c['card_id'] ?>">
                                <button type="submit" name="deleteIncludedCard" class="btn btn-sm sa-btn-soft text-danger">
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
        <span>FAQ</span>
        <button class="btn btn-sm sa-btn" id="btnAddFaq">
            <i class="bi bi-plus-lg me-1"></i> Add Question
        </button>
    </div>

    <div class="table-responsive">

        <table class="table sa-table">

            <thead>
                <tr>
                    <th class="ps-4" style="width:70px;">Order</th>
                    <th style="width:320px;">Question</th>
                    <th>Answer</th>
                    <th style="width:110px;">Status</th>
                    <th class="pe-4" style="width:120px;">Action</th>
                </tr>
            </thead>

            <tbody>

                <?php $hasFaqs = false; ?>

                <?php while ($f = $faqs->fetch_assoc()): $hasFaqs = true; ?>

                    <tr>
                        <td class="ps-4"><?= (int) $f['faq_order'] ?></td>
                        <td class="sa-name"><?= htmlspecialchars($f['question']) ?></td>
                        <td class="text-muted small"><?= htmlspecialchars($f['answer']) ?></td>
                        <td>
                            <span class="badge <?= $f['status'] === 'Active' ? 'bg-success' : 'bg-secondary' ?>">
                                <?= htmlspecialchars($f['status']) ?>
                            </span>
                        </td>
                        <td class="pe-4">

                            <button class="btn btn-sm sa-btn-soft btn-edit-faq"
                                data-faq="<?= htmlspecialchars(json_encode($f), ENT_QUOTES, 'UTF-8') ?>">
                                <i class="bi bi-pencil"></i>
                            </button>

                            <form method="POST" class="d-inline"
                                data-confirm="Remove this question?"
                                data-confirm-text="It disappears from the FAQ on the pricing page."
                                data-confirm-button="Remove" data-confirm-danger>
                                <input type="hidden" name="faq_id" value="<?= (int) $f['faq_id'] ?>">
                                <button type="submit" name="deleteFaq" class="btn btn-sm sa-btn-soft text-danger">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>

                        </td>
                    </tr>

                <?php endwhile; ?>

                <?php if (!$hasFaqs): ?>
                    <tr><td colspan="5" class="text-center text-muted py-4">No questions yet.</td></tr>
                <?php endif; ?>

            </tbody>

        </table>

    </div>

</div>


<!-- COMPARISON ROW MODAL -->

<div class="modal fade" id="rowModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">

            <form method="POST" data-confirm="Save this comparison row?" data-confirm-button="Save">

                <div class="modal-header">
                    <h5 class="modal-title" id="rowModalTitle">Add Row</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">

                    <input type="hidden" name="row_id" id="row_id" value="0">

                    <div class="row g-3">

                        <div class="col-md-3">
                            <label class="form-label">Order</label>
                            <input type="number" name="row_order" id="row_order" class="form-control" value="1" min="1">
                        </div>

                        <div class="col-md-9">
                            <label class="form-label">Feature</label>
                            <input type="text" name="feature_label" id="feature_label" class="form-control" required>
                        </div>

                        <div class="col-12">
                            <div class="form-text mb-2">
                                Each cell takes <code>yes</code> for a check mark, <code>no</code> for a dash,
                                or any other text to print it as written (e.g. <code>Up to 10</code>).
                            </div>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label"><?= htmlspecialchars($section['compare_col1'] ?? 'Column 1') ?></label>
                            <input type="text" name="col1_value" id="col1_value" class="form-control" value="no">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label"><?= htmlspecialchars($section['compare_col2'] ?? 'Column 2') ?></label>
                            <input type="text" name="col2_value" id="col2_value" class="form-control" value="no">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label"><?= htmlspecialchars($section['compare_col3'] ?? 'Column 3') ?></label>
                            <input type="text" name="col3_value" id="col3_value" class="form-control" value="no">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Status</label>
                            <select name="status" id="row_status" class="form-select">
                                <option>Active</option>
                                <option>Inactive</option>
                            </select>
                        </div>

                    </div>

                </div>

                <div class="modal-footer">
                    <button type="button" class="btn sa-btn-soft" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="saveRow" class="btn sa-btn">Save</button>
                </div>

            </form>

        </div>
    </div>
</div>


<!-- INCLUDED CARD MODAL -->

<div class="modal fade" id="incModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">

            <form method="POST" data-confirm="Save this card?"
                data-confirm-text="Saving replaces the whole bullet list for this card."
                data-confirm-button="Save">

                <div class="modal-header">
                    <h5 class="modal-title" id="incModalTitle">Add Card</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">

                    <input type="hidden" name="card_id" id="inc_card_id" value="0">

                    <div class="row g-3">

                        <div class="col-md-3">
                            <label class="form-label">Order</label>
                            <input type="number" name="card_order" id="inc_order" class="form-control" value="1" min="1">
                        </div>

                        <div class="col-md-9">
                            <label class="form-label">Title</label>
                            <input type="text" name="card_title" id="inc_title" class="form-control" required>
                        </div>

                        <div class="col-12">
                            <label class="form-label">Description</label>
                            <textarea name="card_description" id="inc_description" class="form-control" rows="2" required></textarea>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Icon</label>
                            <input type="text" name="icon" id="inc_icon" class="form-control" value="bi bi-box-seam" required>
                            <div class="form-text"><code>bi bi-...</code></div>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Icon Background</label>
                            <input type="text" name="icon_bg" id="inc_icon_bg" class="form-control" value="bg-sky-100">
                            <div class="form-text">Tailwind class</div>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Icon Color</label>
                            <input type="text" name="icon_color" id="inc_icon_color" class="form-control" value="text-sky-600">
                            <div class="form-text">Tailwind class</div>
                        </div>

                        <div class="col-12">
                            <label class="form-label">Bullets</label>
                            <textarea name="features" id="inc_features" class="form-control" rows="5"></textarea>
                            <div class="form-text">One bullet per line. Saving replaces the whole list.</div>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Status</label>
                            <select name="status" id="inc_status" class="form-select">
                                <option>Active</option>
                                <option>Inactive</option>
                            </select>
                        </div>

                    </div>

                </div>

                <div class="modal-footer">
                    <button type="button" class="btn sa-btn-soft" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="saveIncludedCard" class="btn sa-btn">Save</button>
                </div>

            </form>

        </div>
    </div>
</div>


<!-- FAQ MODAL -->

<div class="modal fade" id="faqModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">

            <form method="POST" data-confirm="Save this question?" data-confirm-button="Save">

                <div class="modal-header">
                    <h5 class="modal-title" id="faqModalTitle">Add Question</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">

                    <input type="hidden" name="faq_id" id="faq_id" value="0">

                    <div class="row g-3">

                        <div class="col-md-3">
                            <label class="form-label">Order</label>
                            <input type="number" name="faq_order" id="faq_order" class="form-control" value="1" min="1">
                        </div>

                        <div class="col-md-9">
                            <label class="form-label">Question</label>
                            <input type="text" name="question" id="faq_question" class="form-control" required>
                        </div>

                        <div class="col-12">
                            <label class="form-label">Answer</label>
                            <textarea name="answer" id="faq_answer" class="form-control" rows="4" required></textarea>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Status</label>
                            <select name="status" id="faq_status" class="form-select">
                                <option>Active</option>
                                <option>Inactive</option>
                            </select>
                        </div>

                    </div>

                </div>

                <div class="modal-footer">
                    <button type="button" class="btn sa-btn-soft" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="saveFaq" class="btn sa-btn">Save</button>
                </div>

            </form>

        </div>
    </div>
</div>


<script>
    document.addEventListener("DOMContentLoaded", function () {

        var rowModal = new bootstrap.Modal(document.getElementById("rowModal"));
        var incModal = new bootstrap.Modal(document.getElementById("incModal"));
        var faqModal = new bootstrap.Modal(document.getElementById("faqModal"));

        function val(id, v) { document.getElementById(id).value = v; }

        document.getElementById("btnAddRow").addEventListener("click", function () {
            document.querySelector("#rowModal form").reset();
            val("row_id", "0");
            document.getElementById("rowModalTitle").textContent = "Add Row";
            rowModal.show();
        });

        document.getElementById("btnAddIncCard").addEventListener("click", function () {
            document.querySelector("#incModal form").reset();
            val("inc_card_id", "0");
            document.getElementById("incModalTitle").textContent = "Add Card";
            incModal.show();
        });

        document.getElementById("btnAddFaq").addEventListener("click", function () {
            document.querySelector("#faqModal form").reset();
            val("faq_id", "0");
            document.getElementById("faqModalTitle").textContent = "Add Question";
            faqModal.show();
        });

        document.addEventListener("click", function (e) {

            var rowBtn = e.target.closest(".btn-edit-row");

            if (rowBtn) {
                var r = JSON.parse(rowBtn.dataset.row);
                val("row_id", r.row_id);
                val("row_order", r.row_order);
                val("feature_label", r.feature_label);
                val("col1_value", r.col1_value);
                val("col2_value", r.col2_value);
                val("col3_value", r.col3_value);
                val("row_status", r.status);
                document.getElementById("rowModalTitle").textContent = "Edit Row";
                rowModal.show();
                return;
            }

            var incBtn = e.target.closest(".btn-edit-inc");

            if (incBtn) {
                var c = JSON.parse(incBtn.dataset.card);
                val("inc_card_id", c.card_id);
                val("inc_order", c.card_order);
                val("inc_title", c.title);
                val("inc_description", c.description);
                val("inc_icon", c.icon);
                val("inc_icon_bg", c.icon_bg);
                val("inc_icon_color", c.icon_color);
                val("inc_features", (c.features || []).join("\n"));
                val("inc_status", c.status);
                document.getElementById("incModalTitle").textContent = "Edit Card";
                incModal.show();
                return;
            }

            var faqBtn = e.target.closest(".btn-edit-faq");

            if (faqBtn) {
                var f = JSON.parse(faqBtn.dataset.faq);
                val("faq_id", f.faq_id);
                val("faq_order", f.faq_order);
                val("faq_question", f.question);
                val("faq_answer", f.answer);
                val("faq_status", f.status);
                document.getElementById("faqModalTitle").textContent = "Edit Question";
                faqModal.show();
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
