<?php

require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../includes/platform_roles.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/company_contracts.php';
requirePlatformAccess('settings');


/*
|--------------------------------------------------------------------------
| SETTINGS
|--------------------------------------------------------------------------
|
| Platform-wide switches. Two of them decide whether the public site is
| usable at all, so both are saved from their own form with their own
| confirmation rather than being buried in a long page of fields that gets
| saved by habit.
|
| Every change is written to the audit trail with its old and new value.
|
*/

$alert = null;


/* Describes a change in a way that reads correctly a year later. */
function settingsDiff(array $before, array $after, array $labels): string
{
    $changes = [];

    foreach ($labels as $field => $label) {
        $old = (string) ($before[$field] ?? '');
        $new = (string) ($after[$field] ?? '');

        if ($old !== $new) {
            $changes[] = $label . ': "' . $old . '" to "' . $new . '"';
        }
    }

    return $changes ? implode('; ', $changes) : 'No values changed';
}


function currentSettings(mysqli $conn): ?array
{
    $row = $conn->query("SELECT * FROM platform_settings ORDER BY setting_id LIMIT 1")->fetch_assoc();
    return $row ?: null;
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['saveGeneral'])) {

    $before = currentSettings($conn);

    $name = trim($_POST['platform_name'] ?? '');
    $email = trim($_POST['support_email'] ?? '');
    $phone = trim($_POST['support_phone'] ?? '');
    $trialDays = (string) ($_POST['trial_days'] ?? '');
    $perPage = (string) ($_POST['records_per_page'] ?? '');
    $defaultPlan = (int) ($_POST['default_plan_id'] ?? 0);

    $problem = null;

    if (!$before) {
        $problem = 'No settings row yet. Run database/superadmin_operations.sql.';
    } elseif ($name === '') {
        $problem = 'Platform name is required.';
    } elseif ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $problem = 'Please enter a valid support email.';
    } elseif (!ctype_digit($trialDays) || (int) $trialDays > 365) {
        $problem = 'Trial days must be a whole number between 0 and 365.';
    } elseif (!ctype_digit($perPage) || (int) $perPage < 5 || (int) $perPage > 200) {
        $problem = 'Records per page must be between 5 and 200.';
    }

    if ($problem === null && $defaultPlan > 0) {
        $found = $conn->query("SELECT plan_id FROM subscription_plans WHERE plan_id = " . $defaultPlan . " LIMIT 1");

        if (!$found || $found->num_rows === 0) {
            $problem = 'That default plan no longer exists.';
        }
    }

    if ($problem !== null) {

        $alert = ['icon' => 'error', 'title' => 'Missing Details', 'text' => $problem];

    } else {

        $trial = (int) $trialDays;
        $rows = (int) $perPage;
        $plan = $defaultPlan > 0 ? $defaultPlan : null;

        $stmt = $conn->prepare("
            UPDATE platform_settings SET
                platform_name = ?, support_email = ?, support_phone = ?,
                trial_days = ?, records_per_page = ?, default_plan_id = ?
            WHERE setting_id = ?
        ");
        $stmt->bind_param("sssiiii", $name, $email, $phone, $trial, $rows, $plan, $before['setting_id']);

        if ($stmt->execute()) {
            $stmt->close();

            auditLog($conn, 'Settings updated', 'settings', $before['setting_id'], settingsDiff($before, [
                'platform_name' => $name,
                'support_email' => $email,
                'support_phone' => $phone,
                'trial_days' => $trial,
                'records_per_page' => $rows,
                'default_plan_id' => $plan,
            ], [
                'platform_name' => 'Platform name',
                'support_email' => 'Support email',
                'support_phone' => 'Support phone',
                'trial_days' => 'Trial days',
                'records_per_page' => 'Records per page',
                'default_plan_id' => 'Default plan',
            ]));

            $alert = ['icon' => 'success', 'title' => 'Settings Saved', 'text' => 'The general settings have been updated.'];
        } else {
            $error = $conn->error;
            $stmt->close();
            $alert = ['icon' => 'error', 'title' => 'Save Failed', 'text' => $error];
        }
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['saveAccess'])) {

    $before = currentSettings($conn);

    $registrationOpen = isset($_POST['registration_open']) ? 1 : 0;
    $maintenanceMode = isset($_POST['maintenance_mode']) ? 1 : 0;
    $maintenanceMessage = trim($_POST['maintenance_message'] ?? '');

    if (!$before) {

        $alert = ['icon' => 'error', 'title' => 'Not Set Up',
                  'text' => 'No settings row yet. Run database/superadmin_operations.sql.'];

    } elseif ($maintenanceMode === 1 && strlen($maintenanceMessage) < 10) {

        /* Turning the site off without saying why is the one thing that
           makes this switch worse than useless. */
        $alert = ['icon' => 'error', 'title' => 'Message Required',
                  'text' => 'Write at least 10 characters explaining the downtime before switching maintenance on.'];

    } else {

        $message = $maintenanceMessage === '' ? null : $maintenanceMessage;

        $stmt = $conn->prepare("
            UPDATE platform_settings SET
                registration_open = ?, maintenance_mode = ?, maintenance_message = ?
            WHERE setting_id = ?
        ");
        $stmt->bind_param("iisi", $registrationOpen, $maintenanceMode, $message, $before['setting_id']);

        if ($stmt->execute()) {
            $stmt->close();

            auditLog($conn, 'Access settings updated', 'settings', $before['setting_id'], settingsDiff($before, [
                'registration_open' => $registrationOpen,
                'maintenance_mode' => $maintenanceMode,
            ], [
                'registration_open' => 'Registration open',
                'maintenance_mode' => 'Maintenance mode',
            ]));

            $alert = ['icon' => 'success', 'title' => 'Access Saved',
                      'text' => $maintenanceMode
                          ? 'Maintenance mode is ON. The public site is closed.'
                          : 'The public site is open.'];
        } else {
            $error = $conn->error;
            $stmt->close();
            $alert = ['icon' => 'error', 'title' => 'Save Failed', 'text' => $error];
        }
    }
}


/*
|--------------------------------------------------------------------------
| THE SERVICE AGREEMENT TEMPLATE
|--------------------------------------------------------------------------
|
| The blank every business is given when it avails a subscription.
|
| Replacing it changes what the NEXT business downloads. Agreements
| already issued keep the copy they were given -- company_contracts stores
| its own path rather than pointing here -- so changing the terms today
| cannot rewrite what somebody agreed to yesterday.
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['saveContractTemplate'])) {

    $row = $conn->query("SELECT setting_id, contract_template FROM platform_settings ORDER BY setting_id LIMIT 1");
    $row = $row ? $row->fetch_assoc() : null;

    if (!$row) {

        $alert = ['icon' => 'error', 'title' => 'No Settings Row',
                  'text' => 'Run database/website_pages.sql first.'];

    } else {

        $file = $_FILES['contract_template'] ?? [];

        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {

            $alert = ['icon' => 'error', 'title' => 'Choose a File',
                      'text' => 'Please choose the agreement PDF.'];

        } elseif ($file['error'] !== UPLOAD_ERR_OK) {

            $alert = ['icon' => 'error', 'title' => 'Upload Failed',
                      'text' => 'The file could not be uploaded.'];

        } elseif ($file['size'] > COMPANY_CONTRACT_MAX_BYTES) {

            $alert = ['icon' => 'error', 'title' => 'Too Large',
                      'text' => 'The agreement must be 5 MB or smaller.'];

        } elseif ((new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) !== 'application/pdf') {

            /* Read from the bytes, not the name: a renamed script is not a PDF. */
            $alert = ['icon' => 'error', 'title' => 'PDF Only',
                      'text' => 'The agreement must be a PDF.'];

        } else {

            if (!is_dir(COMPANY_CONTRACT_DIR)) {
                mkdir(COMPANY_CONTRACT_DIR, 0777, true);
            }

            $stored = 'template_' . bin2hex(random_bytes(8)) . '.pdf';
            $target = COMPANY_CONTRACT_DIR . '/' . $stored;

            if (!move_uploaded_file($file['tmp_name'], $target)) {

                $alert = ['icon' => 'error', 'title' => 'Upload Failed',
                          'text' => 'Could not store the file.'];

            } else {

                /* The uploader's own file name, kept for display only. */
                $shownName = mb_substr(basename((string) ($file['name'] ?? 'Service Agreement.pdf')), 0, 190);
                $relative = 'uploads/company_contracts/' . $stored;

                $stmt = $conn->prepare("
                    UPDATE platform_settings
                    SET contract_template = ?, contract_template_name = ?
                    WHERE setting_id = ?
                ");
                $stmt->bind_param("ssi", $relative, $shownName, $row['setting_id']);
                $stmt->execute();
                $stmt->close();

                /*
                | The one it replaces is removed only after the new path is
                | saved, and only when no issued agreement still points at
                | it -- a business that downloaded it must still be able to.
                */
                $old = trim((string) $row['contract_template']);

                if ($old !== '' && $old !== $relative) {

                    $check = $conn->prepare("SELECT contract_id FROM company_contracts WHERE issued_template = ? LIMIT 1");
                    $check->bind_param("s", $old);
                    $check->execute();
                    $stillUsed = $check->get_result()->num_rows > 0;
                    $check->close();

                    if (!$stillUsed && is_file(dirname(__DIR__) . '/' . $old)) {
                        @unlink(dirname(__DIR__) . '/' . $old);
                    }
                }

                auditLog($conn, 'Agreement template updated', 'settings',
                         (int) $row['setting_id'], $shownName);

                $alert = ['icon' => 'success', 'title' => 'Template Saved',
                          'text' => 'New businesses will be given this agreement from now on.'];
            }
        }
    }
}


include("sAdminHeader.php");

$settings = currentSettings($conn);
$plans = $conn->query("SELECT plan_id, plan_name FROM subscription_plans ORDER BY plan_order, plan_id");

?>

<div class="sa-page-head">
    <div>
        <h3 class="sa-page-title">Settings</h3>
        <p class="sa-page-sub">
            Platform-wide switches. Every change is written to the audit trail.
        </p>
    </div>
    <a href="<?= $BASE_URL ?>/superAdmin/audit.php" class="btn sa-btn-soft">
        <i class="bi bi-journal-text me-1"></i> Audit Logs
    </a>
</div>


<?php if (!$settings): ?>

    <div class="sa-panel sa-panel-note">
        No settings row yet. Run <code>database/superadmin_operations.sql</code>.
    </div>

<?php else: ?>

    <?php if ((int) $settings['maintenance_mode'] === 1): ?>
        <div class="alert alert-danger d-flex gap-2 align-items-start">
            <i class="bi bi-cone-striped mt-1"></i>
            <div class="small">
                <strong>Maintenance mode is on.</strong>
                Visitors see your message instead of the site.
            </div>
        </div>
    <?php endif; ?>

    <?php if ((int) $settings['registration_open'] === 0): ?>
        <div class="alert alert-warning d-flex gap-2 align-items-start">
            <i class="bi bi-door-closed mt-1"></i>
            <div class="small">
                Registration is closed, so no new business can sign up.
            </div>
        </div>
    <?php endif; ?>


    <div class="sa-panel mb-4">

        <div class="sa-panel-head">
            <span>General</span>
            <span class="sa-muted">
                Last saved <?= htmlspecialchars(date('M d, Y H:i', strtotime($settings['updated_at']))) ?>
            </span>
        </div>

        <form method="POST" class="p-4" data-confirm="Save the general settings?"
            data-confirm-button="Save">

            <div class="row g-3">

                <div class="col-md-6">
                    <label class="form-label sa-required">Platform Name</label>
                    <input type="text" name="platform_name" class="form-control" maxlength="120"
                        value="<?= htmlspecialchars($settings['platform_name']) ?>" required>
                </div>

                <div class="col-md-6">
                    <label class="form-label sa-required">Support Email</label>
                    <input type="email" name="support_email" class="form-control" maxlength="150"
                        value="<?= htmlspecialchars($settings['support_email']) ?>" required>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Support Phone</label>
                    <input type="text" name="support_phone" class="form-control" maxlength="60"
                        value="<?= htmlspecialchars($settings['support_phone']) ?>">
                </div>

                <div class="col-md-6">
                    <label class="form-label">Default Plan</label>
                    <select name="default_plan_id" class="form-select">
                        <option value="0">No default</option>
                        <?php while ($plan = $plans->fetch_assoc()): ?>
                            <option value="<?= (int) $plan['plan_id'] ?>"
                                <?= (int) $plan['plan_id'] === (int) $settings['default_plan_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($plan['plan_name']) ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                    <div class="form-text">Pre-selected when a new company is registered.</div>
                </div>

                <div class="col-md-6">
                    <label class="form-label sa-required">Trial Days</label>
                    <input type="number" name="trial_days" class="form-control" min="0" max="365"
                        value="<?= (int) $settings['trial_days'] ?>" required>
                    <div class="form-text">0 to 365. Use 0 for no trial.</div>
                </div>

                <div class="col-md-6">
                    <label class="form-label sa-required">Records Per Page</label>
                    <input type="number" name="records_per_page" class="form-control" min="5" max="200"
                        value="<?= (int) $settings['records_per_page'] ?>" required>
                    <div class="form-text">Between 5 and 200.</div>
                </div>

            </div>

            <button type="submit" name="saveGeneral" class="btn sa-btn mt-3">Save General</button>

        </form>

    </div>


    <div class="sa-panel">

        <div class="sa-panel-head">
            <span>Access</span>
            <span class="sa-muted">These two decide whether the public site works</span>
        </div>

        <form method="POST" class="p-4"
            data-confirm="Change who can reach the site?"
            data-confirm-text="Maintenance mode closes the public site for everyone, and closing registration stops new sign-ups."
            data-confirm-button="Apply" data-confirm-danger>

            <div class="row g-3">

                <div class="col-md-6">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch"
                            name="registration_open" id="registration_open" value="1"
                            <?= (int) $settings['registration_open'] === 1 ? 'checked' : '' ?>>
                        <label class="form-check-label" for="registration_open">
                            Registration open
                        </label>
                    </div>
                    <div class="form-text">Off means no new business can sign up.</div>
                </div>

                <div class="col-md-6">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch"
                            name="maintenance_mode" id="maintenance_mode" value="1"
                            <?= (int) $settings['maintenance_mode'] === 1 ? 'checked' : '' ?>>
                        <label class="form-check-label" for="maintenance_mode">
                            Maintenance mode
                        </label>
                    </div>
                    <div class="form-text">On closes the public site for everyone.</div>
                </div>

                <div class="col-12">
                    <label class="form-label" id="maintenanceLabel">Maintenance Message</label>
                    <textarea name="maintenance_message" id="maintenance_message" class="form-control"
                        rows="3"><?= htmlspecialchars($settings['maintenance_message'] ?? '') ?></textarea>
                    <div class="form-text">
                        Shown to visitors while maintenance mode is on. Required before you can switch it on.
                    </div>
                </div>

            </div>

            <button type="submit" name="saveAccess" class="btn sa-btn mt-3">Apply Access</button>

        </form>

    </div>


    <?php
    /*
    | The blank agreement every new subscriber is given.
    |
    | Replacing it only changes what the NEXT business downloads. Agreements
    | already issued keep their own copy, so the terms somebody agreed to
    | last week cannot be edited out from under them.
    */
    $template = contractTemplate($conn);
    ?>

    <div class="sa-panel mt-4">

        <div class="sa-panel-head">Service Agreement</div>

        <div class="sa-panel-body">

            <p class="sa-muted">
                The agreement a business is asked to sign when it avails a subscription.
                It is issued the moment a plan is chosen, and the business sends back a
                signed copy for you to accept.
            </p>

            <?php if ($template): ?>

                <div class="alert alert-secondary d-flex flex-wrap align-items-center gap-2">
                    <i class="bi bi-file-earmark-pdf"></i>
                    <strong><?= htmlspecialchars($template['name']) ?></strong>
                    <a href="company_contract_file.php?template=1" target="_blank" rel="noopener"
                        class="btn btn-sm sa-btn-soft ms-auto">
                        View current
                    </a>
                </div>

            <?php else: ?>

                <div class="alert alert-warning">
                    No agreement has been uploaded yet. A business that subscribes is told
                    one is being prepared, and nothing is sent until you upload it here.
                </div>

            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data"
                data-confirm="<?= $template ? 'Replace the agreement?' : 'Upload this agreement?' ?>"
                data-confirm-text="Businesses that subscribe from now on are given this copy. Agreements already issued keep the copy they were given."
                data-confirm-button="<?= $template ? 'Replace' : 'Upload' ?>">

                <label class="form-label">
                    <?= $template ? 'Replace with' : 'Agreement PDF' ?>
                    <span class="text-danger">*</span>
                </label>

                <input type="file" name="contract_template" accept="application/pdf" required
                    class="form-control">
                <div class="form-text">PDF, up to 5 MB.</div>

                <button type="submit" name="saveContractTemplate" class="btn sa-btn mt-3">
                    <?= $template ? 'Replace Agreement' : 'Upload Agreement' ?>
                </button>

            </form>

        </div>

    </div>

<?php endif; ?>


<script>
    document.addEventListener("DOMContentLoaded", function () {

        var toggle = document.getElementById("maintenance_mode");
        var message = document.getElementById("maintenance_message");
        var label = document.getElementById("maintenanceLabel");

        if (!toggle || !message || !label) return;

        /* The message only has to be there when the switch is going on, so
           required follows the switch rather than being permanent. */
        function syncMaintenance() {
            if (toggle.checked) {
                message.setAttribute("required", "required");
                message.setAttribute("minlength", "10");
                label.classList.add("sa-required");
            } else {
                message.removeAttribute("required");
                message.removeAttribute("minlength");
                label.classList.remove("sa-required");
            }
        }

        toggle.addEventListener("change", syncMaintenance);
        syncMaintenance();

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
