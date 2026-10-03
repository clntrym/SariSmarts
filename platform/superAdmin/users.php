<?php

require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../includes/platform_roles.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/name_parts.php';
requirePlatformAccess('users');


/*
|--------------------------------------------------------------------------
| PLATFORM USERS
|--------------------------------------------------------------------------
|
| Every account across every tenant, plus the place platform staff accounts
| are created.
|
| A tenant's own staff are read-only here: their company creates and removes
| them. What this screen owns is the platform side - the Super Admin,
| Marketing & HR and Finance logins - and the power to switch any account
| off during a support call.
|
| An existing password is never shown and never editable from here. One is
| set only while creating the account; after that the person uses the
| reset-password flow, so no operator can quietly take over a colleague's
| login.
|
*/

$alert = null;


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggleStatus'])) {

    $userId    = (int) ($_POST['user_id'] ?? 0);
    $newStatus = ($_POST['new_status'] ?? '') === 'inactive' ? 'inactive' : 'active';
    $reason    = trim($_POST['reason'] ?? '');

    $currentUser = (int) ($_SESSION['user_id'] ?? 0);

    if ($userId === $currentUser) {
        $alert = ['icon' => 'error', 'title' => 'Not Allowed', 'text' => 'You cannot deactivate your own account.'];
    } elseif ($newStatus === 'inactive' && $reason === '') {
        $alert = ['icon' => 'error', 'title' => 'Reason Required', 'text' => 'Please give a reason for deactivating this account.'];
    } else {

        if ($newStatus === 'inactive') {
            $stmt = $conn->prepare("
                UPDATE users SET status = 'inactive', deactivation_reason = ?, deactivated_at = NOW()
                WHERE user_id = ?
            ");
            $stmt->bind_param("si", $reason, $userId);
        } else {
            $stmt = $conn->prepare("
                UPDATE users SET status = 'active', deactivation_reason = NULL, deactivated_at = NULL
                WHERE user_id = ?
            ");
            $stmt->bind_param("i", $userId);
        }

        $saved = $stmt->execute();

        $alert = $saved
            ? ['icon' => 'success', 'title' => 'Account Updated', 'text' => 'The account is now ' . $newStatus . '.']
            : ['icon' => 'error', 'title' => 'Update Failed', 'text' => $conn->error];

        $stmt->close();

        if ($saved) {
            auditLog($conn, 'Account ' . ($newStatus === 'inactive' ? 'deactivated' : 'reactivated'),
                     'user', $userId, $newStatus === 'inactive' ? $reason : 'Reactivated by operator');
        }
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['createStaff'])) {

    $lastName   = trim($_POST['last_name'] ?? '');
    $firstName  = trim($_POST['first_name'] ?? '');
    $middleName = trim($_POST['middle_name'] ?? '');

    $fullname = composeFullName($lastName, $firstName, $middleName);

    $username = trim($_POST['username'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $contact  = trim($_POST['contact'] ?? '');
    $role     = $_POST['role'] ?? '';
    $password = (string) ($_POST['password'] ?? '');
    $confirm  = (string) ($_POST['confirm_password'] ?? '');

    /* fullname is varchar(100), which is the limit the parts compose into. */
    $problem = nameProblem($lastName, $firstName, $middleName, 100);

    if ($problem !== null) {
        /* Already set by the name checks above. */
    } elseif ($username === '' || !preg_match('/^[A-Za-z0-9._-]{4,50}$/', $username)) {
        $problem = 'Username must be 4 to 50 characters: letters, numbers, dot, dash or underscore.';
    } elseif ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $problem = 'Please enter a valid email address.';
    } elseif (!array_key_exists($role, platformRoles())) {
        $problem = 'Please choose a platform role.';
    } elseif (strlen($password) < 8) {
        $problem = 'The password must be at least 8 characters.';
    } elseif ($password !== $confirm) {
        $problem = 'The two passwords do not match.';
    }

    if ($problem === null) {
        $stmt = $conn->prepare("SELECT user_id FROM users WHERE username = ? OR email = ? LIMIT 1");
        $stmt->bind_param("ss", $username, $email);
        $stmt->execute();
        $taken = $stmt->get_result()->num_rows > 0;
        $stmt->close();

        if ($taken) {
            $problem = 'That username or email is already in use.';
        }
    }

    if ($problem !== null) {

        $alert = ['icon' => 'error', 'title' => 'Missing Details', 'text' => $problem];

    } else {

        /*
        | company_id stays null, and that is what marks an account as
        | platform staff: the login gate only checks a company's
        | subscription when there is a company, so these accounts can
        | never be locked out by a tenant's billing.
        |
        | email_verified_at is stamped because an operator created this
        | account deliberately. There is nobody to send a confirmation to.
        */
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $contactValue = $contact === '' ? null : $contact;

        $middleValue = $middleName === '' ? null : $middleName;

        $stmt = $conn->prepare("
            INSERT INTO users
                (company_id, username, fullname, first_name, middle_name, last_name,
                 email, contact, password, role, status, email_verified_at)
            VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', NOW())
        ");
        $stmt->bind_param(
            "sssssssss",
            $username, $fullname, $firstName, $middleValue, $lastName,
            $email, $contactValue, $hash, $role
        );

        if ($stmt->execute()) {
            $newId = (int) $conn->insert_id;
            $stmt->close();

            auditLog($conn, 'Platform account created', 'user', $newId,
                     $fullname . ' (' . platformRoleLabel($role) . ', ' . $username . ')');

            $alert = ['icon' => 'success', 'title' => 'Account Created',
                      'text' => $fullname . ' can now sign in as ' . platformRoleLabel($role) . '.'];
        } else {
            $error = $conn->error;
            $stmt->close();
            $alert = ['icon' => 'error', 'title' => 'Save Failed', 'text' => $error];
        }
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['changeRole'])) {

    $userId = (int) ($_POST['user_id'] ?? 0);
    $role   = $_POST['role'] ?? '';

    $target = null;

    if ($userId > 0) {
        $stmt = $conn->prepare("SELECT user_id, fullname, role, company_id FROM users WHERE user_id = ? LIMIT 1");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $target = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }

    if (!$target) {

        $alert = ['icon' => 'error', 'title' => 'Not Found', 'text' => 'That account no longer exists.'];

    } elseif ($target['company_id'] !== null) {

        /* A tenant's roles decide what they see inside their own app. */
        $alert = ['icon' => 'error', 'title' => 'Not Allowed',
                  'text' => 'This account belongs to a company. Its role is managed there.'];

    } elseif ($userId === (int) ($_SESSION['user_id'] ?? 0)) {

        /*
        | Changing your own role is how an operator locks themselves out of
        | the one screen that could change it back.
        */
        $alert = ['icon' => 'error', 'title' => 'Not Allowed',
                  'text' => 'You cannot change your own role. Ask another Super Admin.'];

    } elseif (!array_key_exists($role, platformRoles())) {

        $alert = ['icon' => 'error', 'title' => 'Missing Details', 'text' => 'Please choose a platform role.'];

    } elseif (strtolower($target['role']) === $role) {

        $alert = ['icon' => 'info', 'title' => 'Nothing Changed',
                  'text' => $target['fullname'] . ' is already ' . platformRoleLabel($role) . '.'];

    } else {

        $stmt = $conn->prepare("UPDATE users SET role = ? WHERE user_id = ?");
        $stmt->bind_param("si", $role, $userId);

        if ($stmt->execute()) {
            $stmt->close();

            auditLog($conn, 'Platform role changed', 'user', $userId,
                     $target['fullname'] . ': ' . platformRoleLabel($target['role'])
                     . ' to ' . platformRoleLabel($role));

            $alert = ['icon' => 'success', 'title' => 'Role Changed',
                      'text' => $target['fullname'] . ' is now ' . platformRoleLabel($role) . '.'];
        } else {
            $error = $conn->error;
            $stmt->close();
            $alert = ['icon' => 'error', 'title' => 'Save Failed', 'text' => $error];
        }
    }
}


include("sAdminHeader.php");

$users = [];

$result = $conn->query("
    SELECT u.user_id, u.fullname, u.username, u.email, u.contact, u.role,
           u.status, u.join_date, u.deactivation_reason,
           c.company_name, c.company_code
    FROM users u
    LEFT JOIN company c ON c.company_id = u.company_id
    ORDER BY c.company_name IS NULL DESC, c.company_name, u.fullname
");

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }
}

$totalUsers = count($users);
$activeUsers = 0;
$inactiveUsers = 0;

foreach ($users as $u) {
    if (strtolower($u['status'] ?? '') === 'inactive') {
        $inactiveUsers++;
    } else {
        $activeUsers++;
    }
}

?>

<div class="sa-page-head">
    <div>
        <h3 class="sa-page-title">Users</h3>
        <p class="sa-page-sub">
            Platform staff and every tenant account. Tenant roles are managed by their own company.
        </p>
    </div>
    <button class="btn sa-btn" id="btnAddStaff">
        <i class="bi bi-person-plus me-1"></i> Add Platform User
    </button>
</div>


<div class="row g-3 mb-4">

    <div class="col-md-4">
        <div class="sa-stat p-3 d-flex justify-content-between align-items-center">
            <div>
                <div class="text-muted small">Total Accounts</div>
                <div class="sa-stat-value"><?= number_format($totalUsers) ?></div>
            </div>
            <div class="sa-stat-icon sa-tone-navy"><i class="bi bi-people"></i></div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="sa-stat p-3 d-flex justify-content-between align-items-center">
            <div>
                <div class="text-muted small">Active</div>
                <div class="sa-stat-value"><?= number_format($activeUsers) ?></div>
            </div>
            <div class="sa-stat-icon sa-tone-ok"><i class="bi bi-check-circle"></i></div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="sa-stat p-3 d-flex justify-content-between align-items-center">
            <div>
                <div class="text-muted small">Deactivated</div>
                <div class="sa-stat-value"><?= number_format($inactiveUsers) ?></div>
            </div>
            <div class="sa-stat-icon sa-tone-bad"><i class="bi bi-person-slash"></i></div>
        </div>
    </div>

</div>


<div class="sa-panel">
    <div class="card-body p-0">
        <div class="table-responsive">

            <table id="usersTable" class="table table-hover mb-0 sa-table" style="width:100%">

                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Company</th>
                        <th>Role</th>
                        <th>Contact</th>
                        <th>Joined</th>
                        <th>Status</th>
                        <th style="width:130px;">Action</th>
                    </tr>
                </thead>

                <tbody>

                    <?php foreach ($users as $u): ?>

                        <?php
                        $isInactive = strtolower($u['status'] ?? '') === 'inactive';
                        $isSelf = (int) $u['user_id'] === (int) ($_SESSION['user_id'] ?? 0);
                        ?>

                        <tr>

                            <td>
                                <div class="sa-name"><?= htmlspecialchars($u['fullname']) ?></div>
                                <div class="sa-muted"><?= htmlspecialchars($u['username'] ?: '') ?></div>
                            </td>

                            <td>
                                <?php if ($u['company_name']): ?>
                                    <?= htmlspecialchars($u['company_name']) ?>
                                    <div class="sa-muted"><?= htmlspecialchars($u['company_code'] ?? '') ?></div>
                                <?php else: ?>
                                    <span class="badge bg-dark">Platform</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php if ($u['company_name'] === null && array_key_exists(strtolower($u['role']), platformRoles())): ?>
                                    <span class="badge sa-btn">
                                        <?= htmlspecialchars(platformRoleLabel($u['role'])) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-light text-dark border"><?= htmlspecialchars($u['role']) ?></span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <div><?= htmlspecialchars($u['email']) ?></div>
                                <div class="sa-muted"><?= htmlspecialchars($u['contact'] ?: '') ?></div>
                            </td>

                            <td data-order="<?= htmlspecialchars($u['join_date']) ?>">
                                <?= htmlspecialchars(date('M d, Y', strtotime($u['join_date']))) ?>
                            </td>

                            <td>
                                <?php if ($isInactive): ?>
                                    <span class="badge bg-danger">Inactive</span>
                                    <?php if (!empty($u['deactivation_reason'])): ?>
                                        <div class="sa-muted"><?= htmlspecialchars($u['deactivation_reason']) ?></div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="badge bg-success">Active</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php if ($isSelf): ?>
                                    <span class="sa-muted">This is you</span>
                                <?php elseif ($isInactive): ?>
                                    <form method="POST" class="d-inline"
                                        data-confirm="Reactivate <?= htmlspecialchars($u['fullname'], ENT_QUOTES) ?>?"
                                        data-confirm-text="They will be able to log in again immediately."
                                        data-confirm-button="Reactivate">
                                        <input type="hidden" name="user_id" value="<?= (int) $u['user_id'] ?>">
                                        <input type="hidden" name="new_status" value="active">
                                        <button type="submit" name="toggleStatus" class="btn btn-sm btn-success">
                                            Reactivate
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <?php if ($u['company_name'] === null): ?>
                                        <button class="btn btn-sm sa-btn-soft btn-change-role"
                                            data-user-id="<?= (int) $u['user_id'] ?>"
                                            data-name="<?= htmlspecialchars($u['fullname'], ENT_QUOTES) ?>"
                                            data-role="<?= htmlspecialchars(strtolower($u['role']), ENT_QUOTES) ?>"
                                            title="Change team">
                                            <i class="bi bi-person-gear"></i>
                                        </button>
                                    <?php endif; ?>
                                    <button class="btn btn-sm sa-btn-soft text-danger btn-deactivate"
                                        data-user-id="<?= (int) $u['user_id'] ?>"
                                        data-name="<?= htmlspecialchars($u['fullname'], ENT_QUOTES) ?>">
                                        Deactivate
                                    </button>
                                <?php endif; ?>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                </tbody>

            </table>

        </div>
    </div>
</div>


<!-- ===============================
     ADD PLATFORM USER
================================ -->

<div class="modal fade" id="staffModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">

            <form method="POST" data-confirm="Create this platform account?"
                data-confirm-text="They will be able to sign in immediately with the password you set."
                data-confirm-button="Create">

                <div class="modal-header">
                    <h5 class="modal-title">Add Platform User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">

                    <p class="sa-muted">
                        For SariSmart staff. A tenant's own users are created by their company.
                    </p>

                    <div class="row g-3">

                        <div class="col-md-4">
                            <label class="form-label sa-required">Last Name</label>
                            <input type="text" name="last_name" class="form-control"
                                maxlength="<?= NAME_PART_MAX ?>" pattern="<?= NAME_PATTERN ?>" required
                                title="Letters, spaces, hyphens and apostrophes only.">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label sa-required">First Name</label>
                            <input type="text" name="first_name" class="form-control"
                                maxlength="<?= NAME_PART_MAX ?>" pattern="<?= NAME_PATTERN ?>" required
                                title="Letters, spaces, hyphens and apostrophes only.">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Middle Name</label>
                            <input type="text" name="middle_name" class="form-control"
                                maxlength="<?= NAME_PART_MAX ?>" pattern="<?= NAME_PATTERN ?>"
                                title="Letters, spaces, hyphens and apostrophes only.">
                            <div class="form-text">Leave empty if they have none.</div>
                        </div>

                        <div class="col-12">
                            <div class="form-text">
                                Names take letters, spaces, hyphens and apostrophes. No digits or symbols.
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label sa-required">Username</label>
                            <input type="text" name="username" class="form-control"
                                minlength="4" maxlength="50" pattern="[A-Za-z0-9._-]{4,50}" required>
                            <div class="form-text">Letters, numbers, dot, dash or underscore.</div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label sa-required">Email</label>
                            <input type="email" name="email" class="form-control" maxlength="100" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Contact Number</label>
                            <input type="text" name="contact" class="form-control" maxlength="15">
                        </div>

                        <div class="col-12">
                            <label class="form-label sa-required">Team</label>
                            <select name="role" class="form-select" required>
                                <?php foreach (platformRoles() as $slug => $definition): ?>
                                    <option value="<?= htmlspecialchars($slug) ?>">
                                        <?= htmlspecialchars($definition['label']) ?>
                                        - <?= htmlspecialchars($definition['blurb']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">
                                Decides which modules they can open. Super Admin holds all of them.
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label sa-required">Password</label>
                            <input type="password" name="password" class="form-control"
                                minlength="8" maxlength="72" required>
                            <div class="form-text">At least 8 characters.</div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label sa-required">Confirm Password</label>
                            <input type="password" name="confirm_password" class="form-control"
                                minlength="8" maxlength="72" required>
                        </div>

                    </div>

                </div>

                <div class="modal-footer">
                    <button type="button" class="btn sa-btn-soft" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="createStaff" class="btn sa-btn">Create Account</button>
                </div>

            </form>

        </div>
    </div>
</div>


<!-- ===============================
     CHANGE TEAM
================================ -->

<div class="modal fade" id="roleModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">

            <form method="POST" data-confirm="Move this person to another team?"
                data-confirm-text="It changes which modules they can open the next time they load a page."
                data-confirm-button="Change">

                <div class="modal-header">
                    <h5 class="modal-title">Change Team</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">

                    <input type="hidden" name="user_id" id="role_user_id">

                    <p class="mb-3">
                        <span class="sa-name" id="role_name"></span>
                    </p>

                    <div class="mb-0">
                        <label class="form-label sa-required">Team</label>
                        <select name="role" id="role_select" class="form-select" required>
                                <?php foreach (platformRoles() as $slug => $definition): ?>
                                    <option value="<?= htmlspecialchars($slug) ?>">
                                        <?= htmlspecialchars($definition['label']) ?>
                                        - <?= htmlspecialchars($definition['blurb']) ?>
                                    </option>
                                <?php endforeach; ?>
                        </select>
                    </div>

                </div>

                <div class="modal-footer">
                    <button type="button" class="btn sa-btn-soft" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="changeRole" class="btn sa-btn">Change</button>
                </div>

            </form>

        </div>
    </div>
</div>


<div class="modal fade" id="deactivateModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">

            <form method="POST" data-confirm="Deactivate this account?"
                data-confirm-text="They are signed out and cannot log in again until an operator reactivates them."
                data-confirm-button="Deactivate" data-confirm-danger>

                <div class="modal-header">
                    <h5 class="modal-title">Deactivate Account</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">

                    <input type="hidden" name="user_id" id="deact_user_id">
                    <input type="hidden" name="new_status" value="inactive">

                    <p class="mb-3">
                        Deactivating <strong id="deact_name"></strong>. They will not be able to log in.
                    </p>

                    <label class="form-label">Reason <span class="text-danger">*</span></label>
                    <textarea name="reason" class="form-control" rows="3" required
                        placeholder="Why is this account being deactivated?"></textarea>

                </div>

                <div class="modal-footer">
                    <button type="button" class="btn sa-btn-soft" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="toggleStatus" class="btn btn-danger">Deactivate</button>
                </div>

            </form>

        </div>
    </div>
</div>


<script>
    document.addEventListener("DOMContentLoaded", function () {

        new DataTable("#usersTable", {
            order: [],
            columnDefs: [{ orderable: false, targets: -1 }],
            language: {
                searchPlaceholder: "Search users...",
                emptyTable: "No users yet",
                zeroRecords: "No matching users"
            }
        });

        var modal = new bootstrap.Modal(document.getElementById("deactivateModal"));
        var staffModal = new bootstrap.Modal(document.getElementById("staffModal"));
        var roleModal = new bootstrap.Modal(document.getElementById("roleModal"));

        document.getElementById("btnAddStaff").addEventListener("click", function () {
            document.querySelector("#staffModal form").reset();
            staffModal.show();
        });

        document.addEventListener("click", function (e) {

            var roleBtn = e.target.closest(".btn-change-role");
            if (!roleBtn) return;

            document.getElementById("role_user_id").value = roleBtn.dataset.userId;
            document.getElementById("role_name").textContent = roleBtn.dataset.name;
            document.getElementById("role_select").value = roleBtn.dataset.role;

            roleModal.show();
        });

        document.addEventListener("click", function (e) {

            var btn = e.target.closest(".btn-deactivate");
            if (!btn) return;

            document.getElementById("deact_user_id").value = btn.dataset.userId;
            document.getElementById("deact_name").textContent = btn.dataset.name;
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
