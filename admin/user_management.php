<?php
require_once("../init.php");
requireRole(['admin']);

$companyId = requireCompany();
include("admin_header.php");

/*
|--------------------------------------------------------------------------
| FETCH FILTER OPTIONS
|--------------------------------------------------------------------------
*/

/*
| The roles this company's plan entitles it to. The inline Role dropdowns used
| to be built from whatever roles its users already had, which meant the list
| grew or shrank by accident and offered nothing a plan upgrade unlocked.
*/
$planRoles = companyPlanRoles($conn, $companyId);

$planName = '';
$planStmt = $conn->prepare("
    SELECT p.plan_name
    FROM company_subscriptions cs
    JOIN subscription_plans p ON p.plan_id = cs.plan_id
    WHERE cs.company_id = ?
      AND cs.status IN ('Active', 'Trial')
    ORDER BY cs.expiry_date DESC
    LIMIT 1
");
$planStmt->bind_param("i", $companyId);
$planStmt->execute();
if ($planRow = $planStmt->get_result()->fetch_assoc()) {
    $planName = (string) $planRow['plan_name'];
}
$planStmt->close();

/*
| Branch is only offered where the plan sells more than one store. On a
| single-store plan there is nothing to choose between.
*/
$canAssignBranch = companyHasModule($conn, $companyId, 'branch');

$roles = $planRoles;

/*
|--------------------------------------------------------------------------
| FETCH BRANCHES
|--------------------------------------------------------------------------
*/

$branches = [];
$branchResult = $conn->query("
    SELECT branch_id, branch_name
    FROM branch
    WHERE status = 'Active' AND company_id = " . (int) $companyId . "
    ORDER BY branch_name ASC
");
if ($branchResult) {
    while ($row = $branchResult->fetch_assoc()) {
        $branches[] = $row;
    }
}

/*
|--------------------------------------------------------------------------
| FETCH USERS (active + archived separately)
|--------------------------------------------------------------------------
*/

$activeUsers = [];
$archivedUsers = [];

$userQuery = $conn->query("
    SELECT
        u.user_id,
        u.fullname,
        u.email,
        u.role,
        u.status,
        u.employee_id,
        u.deactivation_reason,
        u.deactivated_at,
        e.branch_id,
        b.branch_name
    FROM users u
    LEFT JOIN employees e ON u.employee_id = e.employee_id AND e.company_id = u.company_id
    LEFT JOIN branch b ON e.branch_id = b.branch_id AND b.company_id = u.company_id
    WHERE u.company_id = " . (int) $companyId . "
    ORDER BY u.fullname ASC
");

if ($userQuery) {
    while ($row = $userQuery->fetch_assoc()) {
        if (strtolower((string) $row['status']) === 'active') {
            $activeUsers[] = $row;
        } else {
            $archivedUsers[] = $row;
        }
    }
}

$avatarPalette = ['#00224c', '#f59e0b', '#16a34a', '#dc2626', '#7c3aed', '#0ea5e9', '#eab308'];

function initialsFromName(string $fullname): string
{
    $parts = preg_split('/\s+/', trim($fullname));
    $parts = array_filter($parts);
    $parts = array_values($parts);

    if (count($parts) === 0) return '?';
    if (count($parts) === 1) return strtoupper(substr($parts[0], 0, 2));

    return strtoupper(substr($parts[0], 0, 1) . substr($parts[count($parts) - 1], 0, 1));
}
?>

<!-- jsPDF + autoTable for PDF export -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.2/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.4/jspdf.plugin.autotable.min.js"></script>
<!-- SheetJS for Excel export -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<!-- html2canvas for Image export -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>

<style>
    .users-page { color: #00224c; }

    .users-header { margin-bottom: 18px; }
    .users-header h2 { font-weight: 700; color: #00224c; margin-bottom: 4px; }
    .users-header p { color: #718096; margin: 0; }

    .users-card {
        border: 1px solid #dfe5ec;
        border-radius: 15px;
        background: #fff;
        box-shadow: 0 3px 12px rgba(0, 34, 76, 0.06);
    }

    .users-card-header {
        padding: 15px 20px;
        border-bottom: 1px solid #e5e9ef;
    }

    .search-box { max-width: 360px; }

    .search-box .form-control {
        padding-left: 42px;
        border-radius: 30px;
        min-height: 43px;
        border: 1px solid #dce3eb;
        background-color: #f8fafc;
    }

    .search-box .form-control:focus {
        border-color: #00224c;
        box-shadow: 0 0 0 0.15rem rgba(0, 34, 76, 0.08);
    }

    .search-icon {
        position: absolute;
        left: 15px;
        top: 50%;
        transform: translateY(-50%);
        color: #718096;
        z-index: 2;
    }

    /* Add user */
    .btn-add-user {
        border: none;
        background: #00224c;
        color: #fff;
        border-radius: 30px;
        padding: 10px 20px;
        font-weight: 600;
        font-size: 14px;
    }

    .btn-add-user:hover,
    .btn-add-user:focus {
        background: #fbbd23;
        color: #00224c;
    }

    #addUserModal .modal-content { border-radius: 16px; border: none; }
    #addUserModal .modal-header { border-bottom: 1px solid #e6ebf2; }
    #addUserModal .modal-title { font-weight: 700; color: #00224c; }
    #addUserModal .form-label { font-weight: 600; font-size: 13px; color: #00224c; }
    #addUserModal .form-control,
    #addUserModal .form-select { border-radius: 10px; border: 1px solid #dce3eb; padding: 10px 12px; }
    #addUserModal .form-text { font-size: 12px; }

    /* Export dropdown */
    .export-dropdown .btn-export {
        border: 1px solid #dce3eb;
        background: #fff;
        color: #00224c;
        border-radius: 30px;
        padding: 9px 18px;
        font-weight: 600;
        font-size: 14px;
    }

    .export-dropdown .btn-export:hover,
    .export-dropdown .btn-export:focus {
        background: #f4f7fb;
    }

    .export-dropdown .dropdown-menu {
        border-radius: 12px;
        border: 1px solid #dfe5ec;
        box-shadow: 0 6px 20px rgba(0,34,76,.1);
        padding: 6px;
        min-width: 180px;
    }

    .export-dropdown .dropdown-item {
        border-radius: 8px;
        padding: 10px 14px;
        font-size: 13.5px;
        font-weight: 500;
        color: #00224c;
    }

    .export-dropdown .dropdown-item:hover {
        background: #f4f7fb;
    }

    .export-dropdown .dropdown-item i {
        width: 22px;
        text-align: center;
    }

    /* Tabs */
    .tab-pills {
        display: flex;
        gap: 4px;
        background: #f1f5f9;
        border-radius: 12px;
        padding: 4px;
    }

    .tab-pill {
        border: none;
        background: transparent;
        color: #64748b;
        font-weight: 600;
        font-size: 13.5px;
        padding: 8px 20px;
        border-radius: 10px;
        cursor: pointer;
        transition: all .2s;
        position: relative;
    }

    .tab-pill:hover { color: #00224c; }

    .tab-pill.active {
        background: #fff;
        color: #00224c;
        box-shadow: 0 1px 4px rgba(0,34,76,.1);
    }

    .tab-pill .badge {
        font-size: 11px;
        padding: 2px 7px;
        border-radius: 20px;
        margin-left: 6px;
        font-weight: 600;
    }

    /* Table */
    .users-table thead th {
        font-size: 12px;
        letter-spacing: .03em;
        white-space: nowrap;
        color: #718096;
    }

    .users-table tbody td {
        font-size: 13px;
        color: #00224c;
        vertical-align: middle;
    }

    .user-name { font-weight: 600; color: #00224c; white-space: nowrap; }
    .user-email { font-size: 12px; color: #718096; }

    .avatar-circle {
        width: 42px;
        height: 42px;
        min-width: 42px;
        border-radius: 50%;
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 13px;
        font-weight: 700;
        flex-shrink: 0;
    }

    .inline-select {
        border: 1px solid #dce3eb;
        border-radius: 10px;
        min-height: 38px;
        font-size: 13px;
        color: #00224c;
        background-color: #f8fafc;
        min-width: 150px;
    }

    .inline-select:disabled { background-color: #f1f5f9; color: #94a3b8; }

    .inline-select:focus {
        border-color: #00224c;
        box-shadow: 0 0 0 0.15rem rgba(0, 34, 76, 0.08);
    }

    .access-badge {
        display: inline-block;
        padding: 5px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 600;
        white-space: nowrap;
    }

    .access-enabled { background: #e8f8ed; color: #138a42; border: 1px solid #b9e8c8; }
    .access-disabled { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }

    .btn-reset-pw {
        border-radius: 30px;
        border: 1px solid #dce3eb;
        background: #fff;
        color: #00224c;
        font-weight: 600;
        font-size: 12.5px;
        padding: 6px 14px;
    }

    .btn-reset-pw:hover { background: #f4f7fb; }

    .btn-deactivate {
        border-radius: 30px;
        border: none;
        background: #dc2626;
        color: #fff;
        font-weight: 600;
        font-size: 12.5px;
        padding: 6px 14px;
    }

    .btn-deactivate:hover { background: #b91c1c; color: #fff; }

    .btn-reactivate {
        border-radius: 30px;
        border: none;
        background: #16a34a;
        color: #fff;
        font-weight: 600;
        font-size: 12.5px;
        padding: 6px 14px;
    }

    .btn-reactivate:hover { background: #128040; color: #fff; }

    .empty-state {
        padding: 60px 20px;
        text-align: center;
        color: #718096;
    }

    .empty-state i { font-size: 42px; margin-bottom: 12px; color: #cbd5e1; }

    .reason-text {
        font-size: 12.5px;
        color: #64748b;
        max-width: 220px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .reason-text:hover {
        white-space: normal;
        overflow: visible;
        cursor: help;
    }

    .deactivated-date {
        font-size: 11px;
        color: #94a3b8;
    }

    @media(max-width:768px) {
        .search-box { max-width: 100%; width: 100%; }
        .tab-pills { width: 100%; }
        .tab-pill { flex: 1; text-align: center; padding: 8px 10px; font-size: 12.5px; }
    }
</style>

<div class="container-fluid py-3 users-page">

    <div class="users-header">
        <h2>User Management</h2>
        <p>Manage system accounts, roles, branch assignment, and access.</p>
    </div>

    <!-- TABS -->
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">
        <div class="tab-pills">
            <button class="tab-pill active" data-tab="active">
                <i class="bi bi-people-fill me-1"></i>Active Users
                <span class="badge bg-primary"><?= count($activeUsers) ?></span>
            </button>
            <button class="tab-pill" data-tab="archive">
                <i class="bi bi-archive me-1"></i>Archive
                <span class="badge bg-secondary"><?= count($archivedUsers) ?></span>
            </button>
        </div>

        <button type="button" class="btn-add-user" data-bs-toggle="modal" data-bs-target="#addUserModal">
            <i class="bi bi-person-plus-fill me-1"></i> Add User
        </button>
    </div>

    <!-- ======================== ACTIVE USERS TAB ======================== -->
    <div id="tab-active" class="tab-content-panel">
        <div class="users-card">
            <div class="users-card-header">
                <div class="d-flex justify-content-end align-items-center flex-wrap gap-3">
                    <div class="dropdown export-dropdown">
                        <button class="btn-export dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-download me-1"></i> Export
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item export-action" href="#" data-type="pdf"><i class="bi bi-file-earmark-pdf text-danger me-2"></i>Export as PDF</a></li>
                            <li><a class="dropdown-item export-action" href="#" data-type="excel"><i class="bi bi-file-earmark-excel text-success me-2"></i>Export as Excel</a></li>
                            <li><a class="dropdown-item export-action" href="#" data-type="image"><i class="bi bi-file-earmark-image text-info me-2"></i>Export as Image</a></li>
                        </ul>
                    </div>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 users-table" style="width:100%" id="activeTable">
                    <thead class="table-light">
                        <tr>
                            <th class="text-uppercase fw-semibold ps-4">User</th>
                            <th class="text-uppercase fw-semibold">Role</th>
                            <th class="text-uppercase fw-semibold">Branch</th>
                            <th class="text-uppercase fw-semibold">Access</th>
                            <th class="text-uppercase fw-semibold text-end pe-4">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="activeTableBody">
                        <?php if (count($activeUsers) > 0): ?>
                            <?php foreach ($activeUsers as $user): ?>
                                <?php
                                $initials = initialsFromName($user['fullname']);
                                $avatarColor = $avatarPalette[$user['user_id'] % count($avatarPalette)];
                                $branchLabel = $user['branch_name'] ?? null;
                                $hasEmployee = !empty($user['employee_id']);
                                $searchText = strtolower($user['fullname'] . ' ' . $user['email'] . ' ' . $user['role'] . ' ' . ($branchLabel ?? ''));
                                ?>
                                <tr class="user-row" data-search="<?= htmlspecialchars($searchText, ENT_QUOTES) ?>">
                                    <td class="ps-4">
                                        <div class="d-flex align-items-center gap-3">
                                            <div class="avatar-circle" style="background: <?= $avatarColor ?>;">
                                                <?= htmlspecialchars($initials) ?>
                                            </div>
                                            <div style="min-width:0;">
                                                <div class="user-name"><?= htmlspecialchars($user['fullname']) ?></div>
                                                <div class="user-email text-truncate" style="max-width:260px;">
                                                    <?= htmlspecialchars($user['email']) ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>

                                    <td>
                                        <?php
                                        /*
                                        | An account may already hold a role the plan no longer covers -- after
                                        | a downgrade, say. Keep it in the list so the dropdown still shows
                                        | what the user actually is, but mark it as outside the plan.
                                        */
                                        $currentRole = strtolower(trim((string) $user['role']));
                                        $offlist = $currentRole !== '' && !in_array($currentRole, $roles, true);
                                        ?>
                                        <select class="form-select inline-select role-select"
                                            data-user-id="<?= (int) $user['user_id'] ?>">
                                            <?php if ($offlist): ?>
                                                <option value="<?= htmlspecialchars($currentRole) ?>" selected>
                                                    <?= htmlspecialchars(roleDisplayName($currentRole)) ?> (not in plan)
                                                </option>
                                            <?php endif; ?>
                                            <?php foreach ($roles as $roleOption): ?>
                                                <option value="<?= htmlspecialchars($roleOption) ?>"
                                                    <?= (strcasecmp($roleOption, $currentRole) === 0) ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars(roleDisplayName($roleOption)) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>

                                    <td>
                                        <?php if ($hasEmployee): ?>
                                            <select class="form-select inline-select branch-select"
                                                data-user-id="<?= (int) $user['user_id'] ?>">
                                                <option value="">Unassigned</option>
                                                <?php foreach ($branches as $branch): ?>
                                                    <option value="<?= (int) $branch['branch_id'] ?>"
                                                        <?= ((int) $branch['branch_id'] === (int) $user['branch_id']) ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($branch['branch_name']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        <?php else: ?>
                                            <select class="form-select inline-select" disabled title="No linked employee record">
                                                <option>No employee record</option>
                                            </select>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <span class="access-badge access-enabled">Enabled</span>
                                    </td>

                                    <td class="text-end pe-4">
                                        <div class="d-flex justify-content-end gap-2">
                                            <button type="button" class="btn-reset-pw resetPasswordBtn"
                                                data-user-id="<?= (int) $user['user_id'] ?>"
                                                data-user-name="<?= htmlspecialchars($user['fullname'], ENT_QUOTES) ?>">
                                                <i class="bi bi-key me-1"></i>Reset
                                            </button>
                                            <button type="button" class="btn-deactivate deactivateBtn"
                                                data-user-id="<?= (int) $user['user_id'] ?>"
                                                data-user-name="<?= htmlspecialchars($user['fullname'], ENT_QUOTES) ?>">
                                                <i class="bi bi-slash-circle me-1"></i>Deactivate
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5">
                                    <div class="empty-state">
                                        <i class="bi bi-people"></i>
                                        <h5>No active users</h5>
                                        <p class="mb-0">There are currently no active user accounts.</p>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        </div>
    </div>

    <!-- ======================== ARCHIVE TAB ======================== -->
    <div id="tab-archive" class="tab-content-panel" style="display:none;">
        <div class="users-card">

            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 users-table" style="width:100%" id="archiveTable">
                    <thead class="table-light">
                        <tr>
                            <th class="text-uppercase fw-semibold ps-4">User</th>
                            <th class="text-uppercase fw-semibold">Role</th>
                            <th class="text-uppercase fw-semibold">Reason for Deactivation</th>
                            <th class="text-uppercase fw-semibold">Deactivated</th>
                            <th class="text-uppercase fw-semibold text-end pe-4">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="archiveTableBody">
                        <?php if (count($archivedUsers) > 0): ?>
                            <?php foreach ($archivedUsers as $user): ?>
                                <?php
                                $initials = initialsFromName($user['fullname']);
                                $avatarColor = $avatarPalette[$user['user_id'] % count($avatarPalette)];
                                $searchText = strtolower($user['fullname'] . ' ' . $user['email'] . ' ' . $user['role'] . ' ' . ($user['deactivation_reason'] ?? ''));
                                ?>
                                <tr class="archive-row" data-search="<?= htmlspecialchars($searchText, ENT_QUOTES) ?>">
                                    <td class="ps-4">
                                        <div class="d-flex align-items-center gap-3">
                                            <div class="avatar-circle" style="background: <?= $avatarColor ?>; opacity:.6;">
                                                <?= htmlspecialchars($initials) ?>
                                            </div>
                                            <div style="min-width:0;">
                                                <div class="user-name" style="opacity:.7;"><?= htmlspecialchars($user['fullname']) ?></div>
                                                <div class="user-email text-truncate" style="max-width:260px;">
                                                    <?= htmlspecialchars($user['email']) ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>

                                    <td>
                                        <span class="text-muted" style="font-size:13px;"><?= htmlspecialchars($user['role']) ?></span>
                                    </td>

                                    <td>
                                        <?php if (!empty($user['deactivation_reason'])): ?>
                                            <div class="reason-text" title="<?= htmlspecialchars($user['deactivation_reason'], ENT_QUOTES) ?>">
                                                <?= htmlspecialchars($user['deactivation_reason']) ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted fst-italic" style="font-size:12px;">No reason provided</span>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <?php if (!empty($user['deactivated_at'])): ?>
                                            <div class="deactivated-date">
                                                <?= date('M d, Y', strtotime($user['deactivated_at'])) ?>
                                                <br>
                                                <?= date('h:i A', strtotime($user['deactivated_at'])) ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted fst-italic" style="font-size:12px;">—</span>
                                        <?php endif; ?>
                                    </td>

                                    <td class="text-end pe-4">
                                        <button type="button" class="btn-reactivate reactivateBtn"
                                            data-user-id="<?= (int) $user['user_id'] ?>"
                                            data-user-name="<?= htmlspecialchars($user['fullname'], ENT_QUOTES) ?>">
                                            <i class="bi bi-arrow-counterclockwise me-1"></i>Reactivate
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr id="archiveEmptyRow">
                                <td colspan="5">
                                    <div class="empty-state">
                                        <i class="bi bi-archive"></i>
                                        <h5>No archived accounts</h5>
                                        <p class="mb-0">Deactivated accounts will appear here.</p>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        </div>
    </div>
</div>

<!-- ======================== ADD USER MODAL ======================== -->
<div class="modal fade" id="addUserModal" tabindex="-1" aria-labelledby="addUserModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">

            <div class="modal-header">
                <h5 class="modal-title" id="addUserModalLabel">
                    <i class="bi bi-person-plus-fill me-2"></i>Add User
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <form id="addUserForm" novalidate>
                <div class="modal-body">

                    <div class="row g-3">

                        <div class="col-md-6">
                            <label class="form-label" for="newFullname">Full Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="newFullname" name="fullname"
                                placeholder="Juan Dela Cruz" maxlength="100" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="newEmail">Email <span class="text-danger">*</span></label>
                            <input type="email" class="form-control" id="newEmail" name="email"
                                placeholder="juan@example.com" maxlength="100" required>
                            <div class="form-text">This is what the user signs in with.</div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="newUsername">Username <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="newUsername" name="username"
                                placeholder="At least 5 characters" maxlength="100" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="newContact">Contact Number</label>
                            <input type="text" class="form-control" id="newContact" name="contact"
                                placeholder="09XXXXXXXXX" maxlength="15">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="newRole">Role <span class="text-danger">*</span></label>
                            <select class="form-select" id="newRole" name="role" required>
                                <option value="">Select a role...</option>
                                <?php foreach ($planRoles as $planRole): ?>
                                    <option value="<?= htmlspecialchars($planRole) ?>">
                                        <?= htmlspecialchars(roleDisplayName($planRole)) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">
                                Decides which dashboard the user lands on.
                                <?php if (count($planRoles) < 5): ?>
                                    <br><i class="bi bi-lock-fill me-1"></i>Your
                                    <?= htmlspecialchars($planName ?: 'current') ?> plan covers these roles only.
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if ($canAssignBranch): ?>
                            <div class="col-md-6">
                                <label class="form-label" for="newBranch">Branch</label>
                                <select class="form-select" id="newBranch" name="branch_id">
                                    <option value="">No branch (central role)</option>
                                    <?php foreach ($branches as $branch): ?>
                                        <option value="<?= (int) $branch['branch_id'] ?>">
                                            <?= htmlspecialchars($branch['branch_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">
                                    <?php if (count($branches) === 0): ?>
                                        <i class="bi bi-exclamation-triangle me-1"></i>
                                        No active branches yet. Add one under Branch first.
                                    <?php else: ?>
                                        Leave blank for HR, Finance and Admin &mdash; they work across
                                        every branch rather than sitting in one.
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="col-md-6 d-flex align-items-end">
                                <div class="form-text mb-2">
                                    <i class="bi bi-info-circle me-1"></i>
                                    Your plan covers a single store, so there is no branch to choose.
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="col-md-6">
                            <label class="form-label" for="newPassword">Temporary Password <span class="text-danger">*</span></label>
                            <input type="password" class="form-control" id="newPassword" name="password"
                                placeholder="At least 8 characters" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="newConfirmPassword">Confirm Password <span class="text-danger">*</span></label>
                            <input type="password" class="form-control" id="newConfirmPassword" name="confirm_password"
                                placeholder="Re-type the password" required>
                        </div>

                    </div>

                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="addUserSubmit">
                        <i class="bi bi-check2 me-1"></i>Create Account
                    </button>
                </div>
            </form>

        </div>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function () {

    /*
    |--------------------------------------------------------------------------
    | TABS
    |--------------------------------------------------------------------------
    */

    const tabPills = document.querySelectorAll(".tab-pill");
    const tabPanels = document.querySelectorAll(".tab-content-panel");

    tabPills.forEach(function (pill) {
        pill.addEventListener("click", function () {
            tabPills.forEach(function (p) { p.classList.remove("active"); });
            pill.classList.add("active");

            const target = pill.dataset.tab;
            tabPanels.forEach(function (panel) {
                panel.style.display = panel.id === "tab-" + target ? "" : "none";
            });

            if (target === "active") renderActiveTable();
            else renderArchiveTable();
        });
    });

    /*
    |--------------------------------------------------------------------------
    | DATATABLE INIT
    |--------------------------------------------------------------------------
    */

    var activeDT = null;
    var archiveDT = null;

    if (typeof DataTable !== "undefined") {
        var dtConfig = {
            pageLength: 10,
            lengthChange: false,
            ordering: true,
            order: [],
            columnDefs: [{ orderable: false, targets: -1 }],
            language: {
                search: "",
                searchPlaceholder: "Search users...",
                info: "Showing _START_ to _END_ of _TOTAL_",
                infoEmpty: "No records",
                zeroRecords: "No matching users",
                emptyTable: "No users found",
                paginate: { previous: "Previous", next: "Next" }
            }
        };

        activeDT = new DataTable("#activeTable", dtConfig);
        archiveDT = new DataTable("#archiveTable", Object.assign({}, dtConfig, {
            language: Object.assign({}, dtConfig.language, {
                searchPlaceholder: "Search archived users...",
                emptyTable: "No archived users"
            })
        }));
    }

    function renderActiveTable() { if (activeDT) activeDT.columns.adjust().draw(); }
    function renderArchiveTable() { if (archiveDT) archiveDT.columns.adjust().draw(); }


    /*
    |--------------------------------------------------------------------------
    | INLINE ROLE / BRANCH UPDATES
    |--------------------------------------------------------------------------
    */

    function updateUserField(userId, field, value, selectEl) {
        const previousValue = selectEl.dataset.previousValue ?? selectEl.value;

        fetch("ajax_update_user.php", {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: "user_id=" + encodeURIComponent(userId) +
                  "&field=" + encodeURIComponent(field) +
                  "&value=" + encodeURIComponent(value)
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            if (data.success) {
                selectEl.dataset.previousValue = value;
                Swal.mixin({ toast: true, position: "top-end", showConfirmButton: false, timer: 1600, timerProgressBar: true })
                    .fire({ icon: "success", title: data.message || "Updated." });
            } else {
                selectEl.value = previousValue;
                Swal.fire({ icon: "error", title: "Update Failed", text: data.message || "Please try again." });
            }
        })
        .catch(function () {
            selectEl.value = previousValue;
            Swal.fire({ icon: "error", title: "Error", text: "Something went wrong." });
        });
    }

    document.querySelectorAll(".role-select").forEach(function (sel) {
        sel.dataset.previousValue = sel.value;
        sel.addEventListener("change", function () { updateUserField(this.dataset.userId, "role", this.value, this); });
    });

    document.querySelectorAll(".branch-select").forEach(function (sel) {
        sel.dataset.previousValue = sel.value;
        sel.addEventListener("change", function () { updateUserField(this.dataset.userId, "branch", this.value, this); });
    });


    /*
    |--------------------------------------------------------------------------
    | RESET PASSWORD
    |--------------------------------------------------------------------------
    */

    document.querySelectorAll(".resetPasswordBtn").forEach(function (btn) {
        btn.addEventListener("click", function () {
            const userId = this.dataset.userId;
            const userName = this.dataset.userName;
            const originalHtml = this.innerHTML;
            const button = this;

            Swal.fire({
                title: "Send Password Reset?",
                text: "A password reset link will be emailed to " + userName + ".",
                icon: "question",
                showCancelButton: true,
                confirmButtonColor: "#00224c",
                cancelButtonColor: "#6c757d",
                confirmButtonText: "Yes, Send",
                cancelButtonText: "Cancel",
                reverseButtons: true
            }).then(function (result) {
                if (!result.isConfirmed) return;

                button.disabled = true;
                button.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

                fetch("reset_password_send.php", {
                    method: "POST",
                    headers: { "Content-Type": "application/x-www-form-urlencoded" },
                    body: "user_id=" + encodeURIComponent(userId)
                })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    button.disabled = false;
                    button.innerHTML = originalHtml;
                    if (data.success) {
                        Swal.fire({ icon: "success", title: "Email Sent", text: data.message, timer: 1800, showConfirmButton: false });
                    } else {
                        Swal.fire({ icon: "error", title: "Unable to Send", text: data.message || "Please try again." });
                    }
                })
                .catch(function () {
                    button.disabled = false;
                    button.innerHTML = originalHtml;
                    Swal.fire({ icon: "error", title: "Error", text: "Something went wrong." });
                });
            });
        });
    });


    /*
    |--------------------------------------------------------------------------
    | DEACTIVATE (with reason)
    |--------------------------------------------------------------------------
    */

    document.getElementById("activeTableBody").addEventListener("click", function (e) {
        const btn = e.target.closest(".deactivateBtn");
        if (!btn) return;

        const userId = btn.dataset.userId;
        const userName = btn.dataset.userName;

        Swal.fire({
            title: "Deactivate Account",
            html:
                '<p class="text-muted mb-3" style="font-size:14px;">You are about to deactivate <strong>' + userName + '</strong>. They will be signed out on their next action and will not be able to log in again until you reactivate them.</p>' +
                '<div class="text-start">' +
                '<label class="form-label fw-semibold" style="font-size:13px; color:#00224c;">Reason for Deactivation <span class="text-danger">*</span></label>' +
                '<textarea id="swal-reason" class="form-control" rows="3" placeholder="e.g. Resigned, Terminated, End of contract..." style="border-radius:10px; font-size:13px;"></textarea>' +
                '</div>',
            icon: "warning",
            showCancelButton: true,
            confirmButtonColor: "#dc2626",
            cancelButtonColor: "#6c757d",
            confirmButtonText: '<i class="bi bi-slash-circle me-1"></i> Deactivate',
            cancelButtonText: "Cancel",
            reverseButtons: true,
            focusConfirm: false,
            preConfirm: function () {
                /*
                | The same rule the server enforces in
                | deactivationReasonProblem(). This copy exists only so the
                | person sees the problem before the round trip -- the server
                | is what actually decides, because this can be skipped.
                |
                | The old version refused a reason made ENTIRELY of
                | punctuation, so "Resigned!!!" and a pasted <script> both
                | passed. This one allows letters, numbers, spaces and the
                | punctuation a reason really needs.
                */
                const reason = document.getElementById("swal-reason").value.trim();

                if (!reason) {
                    Swal.showValidationMessage("Please provide a reason for deactivation.");
                    return false;
                }
                if (reason.length < 3) {
                    Swal.showValidationMessage("Reason must be at least 3 characters.");
                    return false;
                }
                if (reason.length > 150) {
                    Swal.showValidationMessage("Reason must be 150 characters or fewer.");
                    return false;
                }
                if (!/^[\p{L}\p{N} .,\-'()]+$/u.test(reason)) {
                    Swal.showValidationMessage("Reason may use letters, numbers, spaces and . , - ' ( ) only.");
                    return false;
                }
                if (!/\p{L}/u.test(reason)) {
                    Swal.showValidationMessage("Reason must include words, not punctuation alone.");
                    return false;
                }
                return reason;
            }
        }).then(function (result) {
            if (!result.isConfirmed) return;

            const reason = result.value;

            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

            fetch("ajax_update_user.php", {
                method: "POST",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                body: "user_id=" + encodeURIComponent(userId) +
                      "&field=status&value=disabled" +
                      "&reason=" + encodeURIComponent(reason)
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.success) {
                    Swal.fire({
                        icon: "success",
                        title: "Account Deactivated",
                        text: userName + " has been moved to the Archive.",
                        timer: 1800,
                        showConfirmButton: false
                    }).then(function () {
                        location.reload();
                    });
                } else {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-slash-circle me-1"></i>Deactivate';
                    Swal.fire({ icon: "error", title: "Error", text: data.message || "Please try again." });
                }
            })
            .catch(function () {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-slash-circle me-1"></i>Deactivate';
                Swal.fire({ icon: "error", title: "Error", text: "Something went wrong." });
            });
        });
    });


    /*
    |--------------------------------------------------------------------------
    | REACTIVATE (from archive)
    |--------------------------------------------------------------------------
    */

    document.getElementById("archiveTableBody").addEventListener("click", function (e) {
        const btn = e.target.closest(".reactivateBtn");
        if (!btn) return;

        const userId = btn.dataset.userId;
        const userName = btn.dataset.userName;

        Swal.fire({
            title: "Reactivate Account?",
            html: '<p style="font-size:14px; color:#475569;"><strong>' + userName + '</strong> will regain access to the system.</p>',
            icon: "question",
            showCancelButton: true,
            confirmButtonColor: "#16a34a",
            cancelButtonColor: "#6c757d",
            confirmButtonText: '<i class="bi bi-arrow-counterclockwise me-1"></i> Yes, Reactivate',
            cancelButtonText: "Cancel",
            reverseButtons: true
        }).then(function (result) {
            if (!result.isConfirmed) return;

            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

            fetch("ajax_update_user.php", {
                method: "POST",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                body: "user_id=" + encodeURIComponent(userId) +
                      "&field=status&value=active"
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.success) {
                    Swal.fire({
                        icon: "success",
                        title: "Account Reactivated",
                        text: userName + " is now active again.",
                        timer: 1800,
                        showConfirmButton: false
                    }).then(function () {
                        location.reload();
                    });
                } else {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-arrow-counterclockwise me-1"></i>Reactivate';
                    Swal.fire({ icon: "error", title: "Error", text: data.message || "Please try again." });
                }
            })
            .catch(function () {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-arrow-counterclockwise me-1"></i>Reactivate';
                Swal.fire({ icon: "error", title: "Error", text: "Something went wrong." });
            });
        });
    });


    /*
    |--------------------------------------------------------------------------
    | EXPORT (PDF / Excel / Image)
    |--------------------------------------------------------------------------
    */

    function getExportData() {
        const header = ["Name", "Email", "Role", "Branch", "Access"];
        const data = [];

        activeRows.forEach(function (row) {
            const name = row.querySelector(".user-name").textContent.trim();
            const email = row.querySelector(".user-email").textContent.trim();
            const roleSelect = row.querySelector(".role-select");
            const role = roleSelect ? roleSelect.value : "";
            const branchSelect = row.querySelector(".branch-select");
            const branch = branchSelect ? branchSelect.options[branchSelect.selectedIndex].text : "No employee record";
            data.push([name, email, role, branch, "Enabled"]);
        });

        return { header: header, data: data };
    }

    document.querySelectorAll(".export-action").forEach(function (item) {
        item.addEventListener("click", function (e) {
            e.preventDefault();
            const type = this.dataset.type;
            const exportData = getExportData();
            const dateStr = new Date().toISOString().slice(0, 10);

            if (type === "pdf") {
                const { jsPDF } = window.jspdf;
                const doc = new jsPDF({ orientation: "landscape" });

                doc.setFontSize(18);
                doc.setTextColor(0, 34, 76);
                doc.text("User Management Report", 14, 20);

                doc.setFontSize(10);
                doc.setTextColor(113, 128, 150);
                doc.text("Generated: " + new Date().toLocaleString(), 14, 28);

                doc.autoTable({
                    head: [exportData.header],
                    body: exportData.data,
                    startY: 34,
                    theme: "grid",
                    headStyles: {
                        fillColor: [0, 34, 76],
                        textColor: [255, 255, 255],
                        fontStyle: "bold",
                        fontSize: 10
                    },
                    bodyStyles: { fontSize: 9 },
                    alternateRowStyles: { fillColor: [248, 250, 252] },
                    styles: { cellPadding: 4 }
                });

                doc.save("user_management_" + dateStr + ".pdf");

                Swal.mixin({ toast: true, position: "top-end", showConfirmButton: false, timer: 1600, timerProgressBar: true })
                    .fire({ icon: "success", title: "PDF exported." });
            }

            if (type === "excel") {
                var wsData = [exportData.header].concat(exportData.data);
                var ws = XLSX.utils.aoa_to_sheet(wsData);

                ws["!cols"] = [{ wch: 25 }, { wch: 30 }, { wch: 15 }, { wch: 20 }, { wch: 10 }];

                var wb = XLSX.utils.book_new();
                XLSX.utils.book_append_sheet(wb, ws, "Users");
                XLSX.writeFile(wb, "user_management_" + dateStr + ".xlsx");

                Swal.mixin({ toast: true, position: "top-end", showConfirmButton: false, timer: 1600, timerProgressBar: true })
                    .fire({ icon: "success", title: "Excel exported." });
            }

            if (type === "image") {
                var table = document.getElementById("activeTable");

                var hiddenRows = [];
                activeRows.forEach(function (r) {
                    if (r.style.display === "none") {
                        hiddenRows.push(r);
                        r.style.display = "";
                    }
                });

                html2canvas(table, { scale: 2, backgroundColor: "#ffffff" }).then(function (canvas) {
                    hiddenRows.forEach(function (r) { r.style.display = "none"; });

                    var link = document.createElement("a");
                    link.download = "user_management_" + dateStr + ".png";
                    link.href = canvas.toDataURL("image/png");
                    link.click();

                    Swal.mixin({ toast: true, position: "top-end", showConfirmButton: false, timer: 1600, timerProgressBar: true })
                        .fire({ icon: "success", title: "Image exported." });
                }).catch(function () {
                    hiddenRows.forEach(function (r) { r.style.display = "none"; });
                    Swal.fire({ icon: "error", title: "Export Failed", text: "Could not generate image." });
                });
            }
        });
    });

    /*
    |--------------------------------------------------------------------------
    | ADD USER
    |--------------------------------------------------------------------------
    */

    const addUserForm = document.getElementById("addUserForm");

    if (addUserForm) {

        const addUserSubmit = document.getElementById("addUserSubmit");
        const submitLabel = '<i class="bi bi-check2 me-1"></i>Create Account';

        addUserForm.addEventListener("submit", function (e) {
            e.preventDefault();

            const password = document.getElementById("newPassword").value;
            const confirmPw = document.getElementById("newConfirmPassword").value;

            if (password.length < 8) {
                Swal.fire({ icon: "warning", title: "Password too short", text: "Use at least 8 characters.", confirmButtonColor: "#00224c" });
                return;
            }

            if (password !== confirmPw) {
                Swal.fire({ icon: "warning", title: "Passwords don't match", text: "Re-type the password to confirm it.", confirmButtonColor: "#00224c" });
                return;
            }

            addUserSubmit.disabled = true;
            addUserSubmit.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Creating...';

            fetch("ajax_add_user.php", {
                method: "POST",
                body: new FormData(addUserForm)
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {

                if (!data.success) {
                    Swal.fire({ icon: "error", title: "Could not add user", text: data.message, confirmButtonColor: "#00224c" });
                    return;
                }

                Swal.fire({
                    icon: "success",
                    title: "User added",
                    text: data.message,
                    confirmButtonColor: "#00224c"
                }).then(function () { window.location.reload(); });
            })
            .catch(function () {
                Swal.fire({ icon: "error", title: "Network Error", text: "Could not reach the server.", confirmButtonColor: "#00224c" });
            })
            .finally(function () {
                addUserSubmit.disabled = false;
                addUserSubmit.innerHTML = submitLabel;
            });
        });

        // Start from a clean form every time the modal is reopened.
        document.getElementById("addUserModal")
            .addEventListener("hidden.bs.modal", function () { addUserForm.reset(); });
    }

});
</script>

<?php include("admin_footer.php"); ?>
