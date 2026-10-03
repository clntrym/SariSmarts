<?php

require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../includes/platform_roles.php';
requirePlatformAccess('careers');


/*
|--------------------------------------------------------------------------
| CAREERS PAGE EDITOR
|--------------------------------------------------------------------------
|
| The job cards on careers.php come from the job table, which the tenant
| HR screens own. What this page edits is the copy around them: the hero,
| the heading above the listings, and the message shown when nothing is
| open.
|
*/

$alert = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['saveCareers'])) {

    $heroBadge = trim($_POST['hero_badge'] ?? '');
    $heroTitle = trim($_POST['hero_title'] ?? '');
    $heroDesc  = trim($_POST['hero_description'] ?? '');
    $jobsBadge = trim($_POST['jobs_badge'] ?? '');
    $jobsTitle = trim($_POST['jobs_title'] ?? '');
    $jobsDesc  = trim($_POST['jobs_description'] ?? '');
    $emptyTitle = trim($_POST['empty_title'] ?? '');
    $emptyDesc  = trim($_POST['empty_description'] ?? '');
    $sectionId  = (int) ($_POST['section_id'] ?? 1);

    if ($heroTitle === '' || $jobsTitle === '' || $emptyTitle === '') {
        $alert = ['icon' => 'error', 'title' => 'Missing Details', 'text' => 'The three titles are required.'];
    } else {
        $stmt = $conn->prepare("
            UPDATE website_careers_section SET
                hero_badge = ?, hero_title = ?, hero_description = ?,
                jobs_badge = ?, jobs_title = ?, jobs_description = ?,
                empty_title = ?, empty_description = ?
            WHERE section_id = ?
        ");
        $stmt->bind_param(
            "ssssssssi",
            $heroBadge, $heroTitle, $heroDesc,
            $jobsBadge, $jobsTitle, $jobsDesc,
            $emptyTitle, $emptyDesc, $sectionId
        );
        $alert = $stmt->execute()
            ? ['icon' => 'success', 'title' => 'Careers Saved', 'text' => 'The careers page copy has been updated.']
            : ['icon' => 'error', 'title' => 'Save Failed', 'text' => $conn->error];
        $stmt->close();
    }
}


include("sAdminHeader.php");

$careers = $conn->query("SELECT * FROM website_careers_section ORDER BY section_id LIMIT 1")->fetch_assoc();

$openJobs = (int) $conn->query("
    SELECT COUNT(*) n FROM job
    WHERE status = 'Published'
      AND (application_deadline IS NULL OR application_deadline = '' OR application_deadline >= CURDATE())
")->fetch_assoc()['n'];

?>

<div class="sa-page-head">
    <div>
        <h3 class="sa-page-title">Careers Page</h3>
        <p class="sa-page-sub">
            The copy around the job listings.
            <span class="badge bg-success ms-1"><?= $openJobs ?> open position<?= $openJobs === 1 ? '' : 's' ?></span>
        </p>
    </div>
    <a href="<?= $BASE_URL ?>/careers.php" target="_blank" class="btn sa-btn-soft">
        <i class="bi bi-box-arrow-up-right me-1"></i> View careers page
    </a>
</div>


<?php if (!$careers): ?>

    <div class="sa-panel p-4 text-muted">
        No row yet - run <code>database/website_pages.sql</code>.
    </div>

<?php else: ?>

    <form method="POST" data-confirm="Save the careers page copy?"
        data-confirm-text="The change goes live on the careers page straight away."
        data-confirm-button="Save">

        <input type="hidden" name="section_id" value="<?= (int) $careers['section_id'] ?>">

        <div class="sa-panel mb-4">

            <div class="sa-panel-head">Hero</div>

            <div class="p-4">

                <div class="row g-3">

                    <div class="col-md-4">
                        <label class="form-label">Badge</label>
                        <input type="text" name="hero_badge" class="form-control"
                            value="<?= htmlspecialchars($careers['hero_badge']) ?>">
                    </div>

                    <div class="col-md-8">
                        <label class="form-label">Title</label>
                        <input type="text" name="hero_title" class="form-control"
                            value="<?= htmlspecialchars($careers['hero_title']) ?>" required>
                    </div>

                    <div class="col-12">
                        <label class="form-label">Description</label>
                        <textarea name="hero_description" class="form-control" rows="2"><?= htmlspecialchars($careers['hero_description']) ?></textarea>
                    </div>

                </div>

            </div>

        </div>


        <div class="sa-panel mb-4">

            <div class="sa-panel-head">Open Positions Heading</div>

            <div class="p-4">

                <div class="row g-3">

                    <div class="col-md-4">
                        <label class="form-label">Badge</label>
                        <input type="text" name="jobs_badge" class="form-control"
                            value="<?= htmlspecialchars($careers['jobs_badge']) ?>">
                    </div>

                    <div class="col-md-8">
                        <label class="form-label">Title</label>
                        <input type="text" name="jobs_title" class="form-control"
                            value="<?= htmlspecialchars($careers['jobs_title']) ?>" required>
                    </div>

                    <div class="col-12">
                        <label class="form-label">Description</label>
                        <textarea name="jobs_description" class="form-control" rows="2"><?= htmlspecialchars($careers['jobs_description']) ?></textarea>
                    </div>

                </div>

            </div>

        </div>


        <div class="sa-panel mb-4">

            <div class="sa-panel-head">When Nothing Is Open</div>

            <div class="p-4">

                <p class="text-muted small">
                    Shown in place of the job grid whenever no published vacancy is still
                    inside its application deadline.
                </p>

                <div class="row g-3">

                    <div class="col-12">
                        <label class="form-label">Title</label>
                        <input type="text" name="empty_title" class="form-control"
                            value="<?= htmlspecialchars($careers['empty_title']) ?>" required>
                    </div>

                    <div class="col-12">
                        <label class="form-label">Description</label>
                        <textarea name="empty_description" class="form-control" rows="2"><?= htmlspecialchars($careers['empty_description']) ?></textarea>
                    </div>

                </div>

            </div>

        </div>

        <button type="submit" name="saveCareers" class="btn sa-btn">
            Save Careers Page
        </button>

    </form>

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
