<?php
require_once("../init.php");
requireRole(['admin']);

$companyId = requireCompany();
requireModule($conn, $companyId, 'branch', 'Branch Management');

include("admin_header.php");

$hasError = false;
$alert = "";


/*
|--------------------------------------------------------------------------
| CREATE / UPDATE BRANCH
|--------------------------------------------------------------------------
|
| One handler for both, so the two can never disagree about what a valid
| branch is. The update path used to skip every check the create path ran --
| location, date, even the opening and closing time.
|
| Operating Hours is computed here from Opening and Closing Time and nothing
| the browser sends for it is trusted. A closing time earlier than the
| opening time is read as an overnight shift (10:00 PM to 6:00 AM is 8
| hours); identical times are refused, since they describe no shift at all.
|
| Contact number, email and branch manager are no longer collected. Update
| leaves those columns untouched, so older rows keep whatever they held.
*/
if (!isset($alert)) {
    $alert = '';
}

if (!function_exists('branchMinutes')) {
    function branchMinutes(string $time): ?int
    {
        if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)(:[0-5]\d)?$/', $time, $m)) {
            return null;
        }

        return (int) $m[1] * 60 + (int) $m[2];
    }
}

if (!function_exists('branchOperatingHours')) {
    function branchOperatingHours(string $opening, string $closing): ?float
    {
        $open = branchMinutes($opening);
        $close = branchMinutes($closing);

        if ($open === null || $close === null || $open === $close) {
            return null;
        }

        $minutes = ($close - $open + 1440) % 1440;

        return round($minutes / 60, 2);
    }
}

if (!function_exists('branchAlert')) {
    /*
    | SweetAlert comes from a CDN that a shielded browser can block, so every
    | message falls back to a plain alert rather than silently not showing.
    */
    function branchAlert(string $icon, string $title, string $text, bool $reload = false): string
    {
        $payload = json_encode(['icon' => $icon, 'title' => $title, 'text' => $text]);
        $then = $reload ? "window.location = 'branch.php';" : '';

        return "
        (function () {
            var a = {$payload};
            if (window.Swal) {
                Swal.fire({ icon: a.icon, title: a.title, text: a.text, confirmButtonColor: '#00224c' })
                    .then(function () { {$then} });
            } else {
                alert(a.title + '\\n\\n' + a.text);
                {$then}
            }
        })();
        ";
    }
}

if (isset($_POST['create_branch']) || isset($_POST['update_branch'])) {

    $isUpdate = isset($_POST['update_branch']);

    $branch_id = (int) ($_POST['branch_id'] ?? 0);
    $branch_name = trim((string) ($_POST['branch_name'] ?? ''));
    $complete_address = trim((string) ($_POST['complete_address'] ?? ''));
    $province = trim((string) ($_POST['province'] ?? ''));
    $city = trim((string) ($_POST['city'] ?? ''));
    $barangay = trim((string) ($_POST['barangay'] ?? ''));
    $latitude = trim((string) ($_POST['latitude'] ?? ''));
    $longitude = trim((string) ($_POST['longitude'] ?? ''));
    $opening_time = trim((string) ($_POST['opening_time'] ?? ''));
    $closing_time = trim((string) ($_POST['closing_time'] ?? ''));
    $opening_date = trim((string) ($_POST['opening_date'] ?? ''));
    $status = (string) ($_POST['status'] ?? '');

    $problem = null;
    $operating_hours = null;

    if ($branch_name === '') {
        $problem = ['warning', 'Branch Name Required', 'Enter a name for the branch.'];
    } elseif (mb_strlen($branch_name) > 100) {
        $problem = ['warning', 'Branch Name Too Long', 'The branch name must be 100 characters or fewer.'];
    } elseif (
        $complete_address === '' || $city === ''
        || !is_numeric($latitude) || !is_numeric($longitude)
    ) {
        $problem = ['warning', 'Location Required', 'Select the branch location on the map.'];
    } elseif ($opening_time === '' || $closing_time === '') {
        $problem = ['warning', 'Operating Time Required', 'Set both the opening time and the closing time.'];
    } elseif (branchMinutes($opening_time) === null || branchMinutes($closing_time) === null) {
        $problem = ['error', 'Invalid Time', 'Opening and closing time must be valid times.'];
    } elseif (($operating_hours = branchOperatingHours($opening_time, $closing_time)) === null) {
        $problem = ['error', 'Invalid Operating Time', 'Opening and closing time cannot be the same.'];
    } elseif ($opening_date === '' || !DateTime::createFromFormat('Y-m-d', $opening_date)) {
        $problem = ['warning', 'Opening Date Required', 'Choose the date this branch opens.'];
    } elseif (!$isUpdate && $opening_date < date('Y-m-d')) {
        // An existing branch may well have opened in the past; only a new one is held to today.
        $problem = ['error', 'Invalid Date', 'Opening date cannot be in the past.'];
    } elseif (!in_array($status, ['Active', 'Inactive'], true)) {
        $problem = ['error', 'Invalid Status', 'Choose Active or Inactive.'];
    } elseif ($isUpdate && $branch_id <= 0) {
        $problem = ['error', 'Branch Not Found', 'That branch could not be found.'];
    }

    if ($problem === null && $isUpdate) {
        $own = $conn->prepare("SELECT 1 FROM branch WHERE branch_id = ? AND company_id = ? LIMIT 1");
        $own->bind_param("ii", $branch_id, $companyId);
        $own->execute();
        if ($own->get_result()->num_rows === 0) {
            $problem = ['error', 'Branch Not Found', 'That branch is not part of your company.'];
        }
        $own->close();
    }

    /*
    | The plan's branch ceiling, checked only when creating. An update must
    | stay possible for a company already at or over its limit - otherwise a
    | downgrade would freeze the branches it still legitimately owns.
    |
    | This is the server-side half. The Add button is hidden once the limit
    | is reached, but hiding a button is presentation; this is what holds
    | when the form is posted anyway.
    */
    if ($problem === null && !$isUpdate) {
        $limitReached = branchLimitProblem($conn, $companyId);

        if ($limitReached !== null) {
            $problem = ['warning', 'Branch Limit Reached', $limitReached];
        }
    }

    if ($problem === null) {
        $nameKey = strtolower(preg_replace('/\s+/', '', $branch_name));
        $dup = $conn->prepare("
            SELECT 1 FROM branch
            WHERE REPLACE(LOWER(branch_name), ' ', '') = ?
              AND company_id = ?
              AND branch_id <> ?
            LIMIT 1
        ");
        $excludeId = $isUpdate ? $branch_id : 0;
        $dup->bind_param("sii", $nameKey, $companyId, $excludeId);
        $dup->execute();
        if ($dup->get_result()->num_rows > 0) {
            $problem = ['warning', 'Duplicate Branch', 'A branch with this name already exists.'];
        }
        $dup->close();
    }

    if ($problem !== null) {

        $alert = branchAlert($problem[0], $problem[1], $problem[2]);

    } else {

        $lat = (float) $latitude;
        $lng = (float) $longitude;

        if ($isUpdate) {

            $stmt = $conn->prepare("
                UPDATE branch
                SET branch_name = ?, complete_address = ?, province = ?, city = ?, barangay = ?,
                    latitude = ?, longitude = ?, operating_hours = ?,
                    opening_time = ?, closing_time = ?, opening_date = ?, status = ?
                WHERE branch_id = ? AND company_id = ?
            ");
            $stmt->bind_param(
                "sssssdddssssii",
                $branch_name, $complete_address, $province, $city, $barangay,
                $lat, $lng, $operating_hours,
                $opening_time, $closing_time, $opening_date, $status,
                $branch_id, $companyId
            );

        } else {

            $stmt = $conn->prepare("
                INSERT INTO branch
                    (company_id, branch_name, complete_address, province, city, barangay,
                     latitude, longitude, operating_hours,
                     opening_time, closing_time, opening_date, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param(
                "isssssdddssss",
                $companyId, $branch_name, $complete_address, $province, $city, $barangay,
                $lat, $lng, $operating_hours,
                $opening_time, $closing_time, $opening_date, $status
            );
        }

        if ($stmt->execute()) {
            $alert = $isUpdate
                ? branchAlert('success', 'Branch Updated!', 'Branch information has been updated.', true)
                : branchAlert('success', 'Branch Created!', 'The new branch has been added.', true);
        } else {
            $alert = branchAlert('error', $isUpdate ? 'Update Failed' : 'Error',
                $isUpdate ? 'Unable to update the branch.' : 'Unable to save the branch.');
        }

        $stmt->close();
    }
}

//Search

$search = "";

$search = isset($_GET['search']) ? trim($_GET['search']) : "";
$sql = "SELECT * FROM branch";
$where = ["company_id = " . (int) $companyId];
if ($search != "") {
    $search = mysqli_real_escape_string($conn, $search);
    $where[] = "(
        branch_name LIKE '%$search%'
        OR complete_address LIKE '%$search%'
        OR province LIKE '%$search%'
        OR city LIKE '%$search%'
        OR barangay LIKE '%$search%'
        OR status LIKE '%$search%'
    )";
}
if (count($where) > 0) {
    $sql .= " WHERE " . implode(" AND ", $where);
}
$sql .= " ORDER BY branch_id DESC";
$branchQuery = mysqli_query($conn, $sql);

?>

<style>
    .branch-modal {
        border-radius: 20px;
        border: none;
        overflow: hidden;
    }

    .branch-modal .modal-header {
        padding: 22px 28px 15px;
    }

    .branch-modal .modal-title {
        color: #062B63;
        font-size: 28px;
    }

    .branch-modal .modal-body {
        padding: 25px 28px;
    }

    .branch-modal label {
        font-size: 13px;
        font-weight: 600;
        color: #222;
        margin-bottom: 6px;
    }

    .branch-modal .form-control,
    .branch-modal .form-select {
        height: 42px;
        border: 1.5px solid #999;
        font-size: 14px;
    }

    .branch-modal .form-control:focus,
    .branch-modal .form-select:focus {
        border-color: #0d6efd;
        box-shadow: none;
    }

    .review-box {
        border: 1px solid #999;
        border-radius: 10px;
        padding: 15px;
        background: #fafafa;
        font-size: 13px;
        color: #555;
    }

    .branch-modal .modal-footer {
        padding: 0 28px 25px;
    }

    .branch-modal .btn-primary {
        background: #062B63;
        border: none;
        padding: 10px 30px;
    }

    .branch-modal .btn-primary:hover {
        background: #FDB515;
        color: #062B63;
    }

    .branch-modal .btn-outline-primary {
        border: 1.5px solid #062B63;
        color: #062B63;
        padding: 10px 30px;
    }

    .branch-modal .btn-outline-primary:hover {
        background: #062B63;
        color: #fff;
    }

    #branchTable {
        width: 100% !important;
    }

    /* ---------------------------------------------------------------
       TABLE
       Matches the User Management / Accounts Payable tables so the
       system reads as one product rather than a set of screens.
       --------------------------------------------------------------- */

    #branchTable thead th {
        text-transform: uppercase;
        font-size: 12px;
        letter-spacing: .03em;
        font-weight: 600;
        color: #4a5568;
        background: #f8fafc;
        border-bottom: 1px solid #e2e8f0;
        white-space: nowrap;
        padding: 14px 12px;
    }

    #branchTable tbody td {
        padding: 14px 12px;
        border-bottom: 1px solid #eef2f7;
        vertical-align: middle;
    }

    #branchTable tbody tr:hover {
        background: #f8fafc;
    }

    .branch-name {
        font-weight: 600;
        color: #00224c;
    }

    .branch-sub {
        font-size: 12px;
        color: #718096;
    }

    .branch-address {
        max-width: 320px;
        color: #4a5568;
    }

    .branch-avatar {
        width: 38px;
        height: 38px;
        border-radius: 10px;
        background: #00224c;
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 14px;
        flex: 0 0 auto;
    }

    .branch-pill {
        display: inline-block;
        border-radius: 30px;
        padding: 4px 14px;
        font-size: 12px;
        font-weight: 600;
        border: 1px solid transparent;
    }

    .branch-pill-active {
        background: #ecfdf5;
        color: #047857;
        border-color: #a7f3d0;
    }

    .branch-pill-inactive {
        background: #fef2f2;
        color: #b91c1c;
        border-color: #fecaca;
    }

    .btn-branch-edit {
        border: 1px solid #dce3eb;
        background: #fff;
        color: #00224c;
        border-radius: 30px;
        padding: 6px 16px;
        font-size: 13px;
        font-weight: 600;
    }

    .btn-branch-edit:hover {
        background: #00224c;
        color: #fff;
    }

    .branch-empty {
        padding: 46px 20px;
        text-align: center;
        color: #718096;
    }

    .branch-empty i {
        font-size: 34px;
        color: #cbd5e1;
        display: block;
        margin-bottom: 10px;
    }

    /* ---------------------------------------------------------------
       MAP MODAL

       #mapModal is opened from inside #branchModal. Bootstrap stacks the
       backdrops but leaves both modals on the same z-index, so the map
       opened underneath the branch form's backdrop -- visible, greyed
       out and impossible to click. Lifting it above resolves that; the
       hidden.bs.modal handler puts back the body class Bootstrap strips
       when the inner modal closes, which otherwise leaves the form
       behind it unable to scroll.
       --------------------------------------------------------------- */

    #mapModal {
        z-index: 1070;
    }

    #mapModal .modal-content {
        border: none;
        border-radius: 16px;
        overflow: hidden;
    }

    #mapModal .modal-header {
        background: #00224c;
        color: #fff;
        border-bottom: none;
    }

    #mapModal .modal-header .btn-close {
        filter: invert(1) grayscale(100%) brightness(200%);
    }

    #branchMap {
        height: 60vh;
        min-height: 380px;
        width: 100%;
    }

    #mapModal .map-hint {
        font-size: 12px;
        color: #718096;
        margin-top: 8px;
    }
</style>

<div class="container-fluid py-1">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <div>
            <h1 class=" mb-0 fw-bold" style="color: #00224c;">
                Branch
            </h1>
            <p class="text-muted mb-0">
                Manage company branches, locations, and branch information.
            </p>
        </div>
        <?php
        /*
        | What the plan allows, asked once for the header. The handler above
        | checks it again on submit -- this only decides what to show.
        */
        $branchLimitNote = branchLimitProblem($conn, $companyId);
        ?>
        <?php if ($branchLimitNote !== null): ?>
            <div class="text-end">
                <button class="btn btn-primary" disabled
                    title="<?= htmlspecialchars($branchLimitNote) ?>">
                    <i class="bi bi-plus-lg me-2"></i>
                    Add Branch
                </button>
                <div class="small text-muted mt-1" style="max-width:320px;">
                    <i class="bi bi-info-circle me-1"></i>
                    <?= htmlspecialchars($branchLimitNote) ?>
                </div>
            </div>
        <?php else: ?>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#branchModal">
                <i class="bi bi-plus-lg me-2"></i>
                Add Branch
            </button>
        <?php endif; ?>
    </div>
    <div class="row g-4">
        <!-- LEFT -->
        <div class="col-lg-12">
            <div class="card shadow-sm border-2 rounded-4">
                <div class="card-body">
                    <div class="table-responsive table-container">
                        <table id="branchTable" class="table align-middle">
                            <thead>
                                <tr>
                                    <th>Branch</th>
                                    <th>Location</th>
                                    <th>Hours</th>
                                    <th>Status</th>
                                    <th class="text-end">Action</th>
                                </tr>
                            </thead>
                            <tbody>

                                <?php if (mysqli_num_rows($branchQuery) > 0) { ?>

                                    <?php while ($row = mysqli_fetch_assoc($branchQuery)) { ?>

                                        <?php
                                        $branchInitials = strtoupper(substr(trim($row['branch_name']), 0, 2));
                                        $locality = array_filter([$row['barangay'], $row['city'], $row['province']]);
                                        ?>
                                        <tr>

                                            <td>
                                                <div class="d-flex align-items-center gap-3">
                                                    <div class="branch-avatar"><?= htmlspecialchars($branchInitials) ?></div>
                                                    <div style="min-width:0;">
                                                        <div class="branch-name"><?= htmlspecialchars($row['branch_name']) ?></div>
                                                    </div>
                                                </div>
                                            </td>

                                            <td>
                                                <div class="branch-address text-truncate"
                                                    title="<?= htmlspecialchars($row['complete_address']) ?>">
                                                    <?= htmlspecialchars($row['complete_address']) ?>
                                                </div>
                                                <?php if ($locality): ?>
                                                    <div class="branch-sub"><?= htmlspecialchars(implode(', ', $locality)) ?></div>
                                                <?php endif; ?>
                                            </td>

                                            <td class="text-nowrap">
                                                <?php if (!empty($row['opening_time']) && !empty($row['closing_time'])): ?>
                                                    <div class="branch-name" style="font-weight:500;">
                                                        <?= date('g:i A', strtotime($row['opening_time'])) ?>
                                                        &ndash;
                                                        <?= date('g:i A', strtotime($row['closing_time'])) ?>
                                                    </div>
                                                    <div class="branch-sub">
                                                        <i class="bi bi-clock me-1"></i><?= rtrim(rtrim(number_format((float) $row['operating_hours'], 2), '0'), '.') ?> hrs
                                                    </div>
                                                <?php else: ?>
                                                    <span class="branch-sub">Not set</span>
                                                <?php endif; ?>
                                            </td>

                                            <td>
                                                <?php if ($row['status'] == "Active") { ?>
                                                    <span class="branch-pill branch-pill-active">Active</span>
                                                <?php } else { ?>
                                                    <span class="branch-pill branch-pill-inactive">Inactive</span>
                                                <?php } ?>
                                            </td>

                                            <td class="text-end">
                                                <button type="button" class="btn-branch-edit editBranch"
                                                    data-id="<?= $row['branch_id'] ?>"
                                                    data-name="<?= htmlspecialchars($row['branch_name']) ?>"
                                                    data-address="<?= htmlspecialchars($row['complete_address']) ?>"
                                                    data-province="<?= htmlspecialchars($row['province']) ?>"
                                                    data-city="<?= htmlspecialchars($row['city']) ?>"
                                                    data-barangay="<?= htmlspecialchars($row['barangay']) ?>"
                                                    data-lat="<?= $row['latitude'] ?>" data-lng="<?= $row['longitude'] ?>"
                                                    data-opening-time="<?= htmlspecialchars($row['opening_time'] ?? '') ?>"
                                                    data-closing-time="<?= htmlspecialchars($row['closing_time'] ?? '') ?>"
                                                    data-opening="<?= $row['opening_date'] ?>"
                                                    data-status="<?= $row['status'] ?>">
                                                    <i class="bi bi-pencil me-1"></i>Edit
                                                </button>
                                            </td>

                                        </tr>

                                    <?php } ?>

                                <?php } else { ?>

                                    <tr>
                                        <td colspan="5">
                                            <div class="branch-empty">
                                                <i class="bi bi-shop"></i>
                                                <h5 class="fw-semibold" style="color:#00224c;">No branches yet</h5>
                                                <p class="mb-0">Add your first branch to see it listed here.</p>
                                            </div>
                                        </td>
                                    </tr>

                                <?php } ?>

                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="branchModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content branch-modal">
            <form method="POST" id="branchForm" novalidate>
                <input type="hidden" id="latitude" name="latitude">
                <input type="hidden" id="longitude" name="longitude">
                <input type="hidden" name="branch_id" id="branch_id">
                <div class="modal-header border-0 pb-2">
                    <h3 class="modal-title fw-bold text-primary">
                        Create New Branch
                    </h3>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <hr class="m-0">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label>Branch Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control rounded-pill" name="branch_name" maxlength="100"
                                required>
                        </div>
                        <div class="col-md-6">
                            <label>Complete Address <span class="text-danger">*</span></label>
                            <input type="text" class="form-control rounded-pill" id="complete_address"
                                name="complete_address" placeholder="Select location from map" readonly required>
                        </div>

                        <div class="col-md-6">
                            <label>Province</label>
                            <input type="text" class="form-control rounded-pill" id="province" name="province" readonly>
                        </div>

                        <div class="col-md-6">
                            <label>City <span class="text-danger">*</span></label>
                            <input type="text" class="form-control rounded-pill" id="city" name="city" readonly
                                required>
                        </div>

                        <div class="col-md-6">
                            <label>Barangay</label>
                            <input type="text" class="form-control rounded-pill" id="barangay" name="barangay" readonly>
                        </div>
                        <div class="col-md-6">
                            <button type="button" class="btn btn-outline-primary rounded-pill" data-bs-toggle="modal"
                                data-bs-target="#mapModal">
                                <i class="bi bi-geo-alt-fill"></i>
                                Select Branch Location
                            </button>
                        </div>
                        <?php
                        /*
                        | Opening and closing time are what HR's employee
                        | registration reads to fill an employee's Time In and
                        | Time Out. Operating Hours is worked out from the two,
                        | here for the owner to see and again on the server for
                        | what gets saved.
                        */
                        ?>
                        <div class="col-md-6">
                            <label>Opening Time <span class="text-danger">*</span></label>
                            <input type="time" class="form-control rounded-pill" name="opening_time" id="openingTime" required>
                            <div class="form-text ps-3">Becomes each employee's Time In.</div>
                        </div>

                        <div class="col-md-6">
                            <label>Closing Time <span class="text-danger">*</span></label>
                            <input type="time" class="form-control rounded-pill" name="closing_time" id="closingTime" required>
                            <div class="form-text ps-3">Becomes each employee's Time Out.</div>
                        </div>

                        <div class="col-md-6">
                            <label>Operating Hours</label>
                            <input type="text" class="form-control rounded-pill bg-light" id="operatingHoursDisplay"
                                placeholder="Set opening and closing time" readonly tabindex="-1">
                            <div class="form-text ps-3" id="operatingHoursNote">Calculated automatically.</div>
                        </div>
                        <div class="col-md-6">
                            <label>Opening Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control rounded-pill" name="opening_date"
                                min="<?= date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label>Status</label>
                            <select class="form-select rounded-pill" name="status">
                                <option>Active</option>
                                <option>Inactive</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <div class="review-box">
                                <strong>Review</strong>
                                <p class="mb-0 mt-1">
                                    After creation: HR can assign employees,
                                    Inventory + POS becomes active for this branch.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-outline-primary rounded-pill px-4" data-bs-dismiss="modal">
                        Cancel </button>
                    <button type="submit" id="saveBranch" name="create_branch"
                        class="btn btn-primary rounded-pill px-4">
                        Create Branch
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="mapModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    Select Branch Location
                </h5>
                <button class="btn-close" data-bs-dismiss="modal">
                </button>
            </div>

            <div class="modal-body p-0">

                <div class="p-3 border-bottom" style="background:#f8fafc;">

                    <div class="input-group">

                        <span class="input-group-text bg-white border-end-0">
                            <i class="bi bi-search text-muted"></i>
                        </span>

                        <input type="text" id="searchLocation" class="form-control border-start-0"
                            placeholder="Search a place, e.g. General Trias, Cavite">

                        <button class="btn btn-primary" id="btnSearchLocation" type="button">
                            Search
                        </button>

                    </div>

                    <div class="map-hint">
                        <i class="bi bi-info-circle me-1"></i>
                        Search for the area, then click the map to drop the pin on the exact spot.
                    </div>

                </div>

                <div id="branchMap"></div>

            </div>
        </div>
    </div>
</div>

<script src="../assets/leaflet/leaflet.js"></script>


<!-- =========================================================
     DATATABLE
========================================================= -->




<script>

    document.addEventListener("DOMContentLoaded", function () {

        if (typeof DataTable !== "undefined") {

            new DataTable("#branchTable", {

                pageLength: 8,

                lengthChange: false,

                searching: true,

                ordering: true,

                paging: true,

                info: true,

                order: [],

                columnDefs: [

                    {
                        orderable: false,
                        targets: 4
                    }

                ],

                language: {

                    search: "",

                    searchPlaceholder: "Search Branch...",

                    info: "Showing _END_ of _TOTAL_",

                    infoEmpty: "Showing 0 of 0",

                    zeroRecords: "No matching branches",

                    emptyTable: "No branches found",

                    paginate: {

                        first: "«",
                        previous: "‹",
                        next: "›",
                        last: "»"

                    }

                }

            });

        }

    });

</script>


<?php if (!empty($alert)) { ?>
    <script>
        document.addEventListener("DOMContentLoaded", function () {
            <?= $alert ?>
        });
    </script>
<?php } ?>

<script>
    let map;
    let marker;

    async function fillAddress(lat, lng) {

        document.getElementById("latitude").value = lat;
        document.getElementById("longitude").value = lng;

        try {

            const response = await fetch(
                "https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat="
                + lat +
                "&lon=" +
                lng
            );

            const data = await response.json();

            const address = data.address || {};

            document.getElementById("complete_address").value =
                data.display_name || "";

            document.getElementById("province").value =
                address.state || "";

            document.getElementById("city").value =
                address.city ||
                address.town ||
                address.municipality ||
                address.county ||
                "";

            document.getElementById("barangay").value =
                address.suburb ||
                address.village ||
                address.neighbourhood ||
                address.hamlet ||
                "";

        } catch (e) {

            Swal.fire(
                "Error",
                "Unable to retrieve address.",
                "error"
            );

        }

    }

    // Search button
    document.getElementById("btnSearchLocation").addEventListener("click", searchLocation);

    // Press Enter to search
    document.getElementById("searchLocation").addEventListener("keypress", function (e) {

        if (e.key === "Enter") {

            e.preventDefault();
            searchLocation();

        }

    });

    async function searchLocation() {

        const keyword = document.getElementById("searchLocation").value.trim();

        if (keyword === "") {

            Swal.fire(
                "Search",
                "Please enter a location.",
                "warning"
            );

            return;

        }

        const response = await fetch(
            "https://nominatim.openstreetmap.org/search?format=json&q=" +
            encodeURIComponent(keyword)
        );

        const results = await response.json();

        if (results.length === 0) {

            Swal.fire(
                "Not Found",
                "Location not found.",
                "error"
            );

            return;

        }

        const place = results[0];

        const lat = parseFloat(place.lat);
        const lon = parseFloat(place.lon);

        map.setView([lat, lon], 17);

        if (marker) {

            marker.setLatLng([lat, lon]);

        } else {

            marker = L.marker([lat, lon]).addTo(map);

        }

        fillAddress(lat, lon);

    }

    document.getElementById("mapModal").addEventListener("shown.bs.modal", function () {

        if (!map) {

            map = L.map("branchMap").setView([14.5995, 120.9842], 13);

            L.tileLayer(
                "https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png",
                {
                    maxZoom: 19,
                    attribution: '&copy; OpenStreetMap'
                }
            ).addTo(map);

            map.on("click", function (e) {

                const lat = e.latlng.lat;
                const lng = e.latlng.lng;

                if (marker) {
                    marker.setLatLng(e.latlng);
                } else {
                    marker = L.marker(e.latlng).addTo(map);
                }

                fillAddress(lat, lng);

            });

        }

        setTimeout(function () {

            map.invalidateSize();

            // If editing existing branch
            if (window.editLat && window.editLng) {

                map.setView([window.editLat, window.editLng], 17);

                if (marker) {
                    marker.setLatLng([window.editLat, window.editLng]);
                } else {
                    marker = L.marker([window.editLat, window.editLng]).addTo(map);
                }

            }

        }, 300);

    });

    const mapModal = document.getElementById("mapModal");

    /*
    | The map opens from inside the branch form, and Bootstrap stacks it on
    | top rather than closing what is underneath. Both backdrops land on the
    | same z-index though, so the map arrived behind the form's backdrop --
    | visible, greyed out, and impossible to click. The newest backdrop is
    | pushed underneath this modal and above the one below it.
    */
    mapModal.addEventListener("shown.bs.modal", function () {

        const backdrops = document.querySelectorAll(".modal-backdrop");

        if (backdrops.length > 1) {
            backdrops[backdrops.length - 1].style.zIndex = "1065";
        }

    });

    mapModal.addEventListener("hidden.bs.modal", function () {

        /*
        | The branch form was never closed, so re-opening it here built a
        | second Modal instance over the live one and left an extra backdrop
        | behind -- the dark screen that swallowed every click afterwards.
        |
        | Closing any modal strips .modal-open from the body, which is the
        | only thing that genuinely needs putting back while the form is
        | still open underneath.
        */
        if (document.querySelector(".modal.show")) {
            document.body.classList.add("modal-open");
        }

    });

    const branchModal = document.getElementById("branchModal");

    branchModal.addEventListener("hidden.bs.modal", function () {

        // Scoped to this modal's form, not whichever <form> happens to come first.
        document.getElementById("branchForm").reset();
        updateOperatingHours();

        document.getElementById("branch_id").value = "";

        document.getElementById("latitude").value = "";
        document.getElementById("longitude").value = "";

        document.querySelector(".modal-title").innerHTML = "Create New Branch";

        document.getElementById("saveBranch").innerHTML = "Create Branch";

        document.getElementById("saveBranch").name = "create_branch";
        window.editLat = null;
        window.editLng = null;

        if (marker) {
            map.removeLayer(marker);
            marker = null;
        }

    });

    document.querySelectorAll(".editBranch").forEach(function (btn) {

        btn.addEventListener("click", function () {

            document.getElementById("branch_id").value = this.dataset.id;

            document.querySelector("[name='branch_name']").value = this.dataset.name;

            document.getElementById("complete_address").value = this.dataset.address;
            document.getElementById("province").value = this.dataset.province;
            document.getElementById("city").value = this.dataset.city;
            document.getElementById("barangay").value = this.dataset.barangay;

            document.getElementById("latitude").value = this.dataset.lat;
            document.getElementById("longitude").value = this.dataset.lng;

            /*
            | The times were never copied into the form on Edit, so saving any
            | edit sent them blank and wiped the branch's Time In / Time Out.
            | <input type="time"> wants HH:MM; the database gives HH:MM:SS.
            */
            document.getElementById("openingTime").value = (this.dataset.openingTime || "").slice(0, 5);
            document.getElementById("closingTime").value = (this.dataset.closingTime || "").slice(0, 5);
            updateOperatingHours();

            document.querySelector("[name='opening_date']").value = this.dataset.opening;
            document.querySelector("[name='status']").value = this.dataset.status;

            document.querySelector(".modal-title").innerHTML = "Edit Branch";
            document.getElementById("saveBranch").innerHTML = "Update Branch";
            document.getElementById("saveBranch").name = "update_branch";

            // Save coordinates for map
            window.editLat = parseFloat(this.dataset.lat);
            window.editLng = parseFloat(this.dataset.lng);

            /*
            | getOrCreateInstance rather than a fresh Modal each click: a new
            | instance every time leaves the earlier ones bound to the same
            | element, and they each keep their own idea of whether it is open.
            */
            bootstrap.Modal.getOrCreateInstance(document.getElementById("branchModal")).show();

        });

    });

</script>
<script>
    /*
    | Operating hours, client-side validation, and a confirmation step.
    |
    | Mirrors branchOperatingHours() on the server: a closing time earlier
    | than the opening time is an overnight shift; identical times are no
    | shift. The server repeats every check -- this is for immediate feedback.
    */
    function branchMinutes(value) {
        var m = /^(\d{2}):(\d{2})/.exec(value || "");
        return m ? parseInt(m[1], 10) * 60 + parseInt(m[2], 10) : null;
    }

    function branchHours(opening, closing) {
        var open = branchMinutes(opening);
        var close = branchMinutes(closing);
        if (open === null || close === null || open === close) return null;
        return Math.round(((close - open + 1440) % 1440) / 60 * 100) / 100;
    }

    function branchTimeLabel(value) {
        var mins = branchMinutes(value);
        if (mins === null) return "";
        var h = Math.floor(mins / 60), m = mins % 60;
        var suffix = h >= 12 ? "PM" : "AM";
        var h12 = h % 12 === 0 ? 12 : h % 12;
        return h12 + ":" + (m < 10 ? "0" : "") + m + " " + suffix;
    }

    function updateOperatingHours() {
        var opening = document.getElementById("openingTime").value;
        var closing = document.getElementById("closingTime").value;
        var display = document.getElementById("operatingHoursDisplay");
        var note = document.getElementById("operatingHoursNote");

        if (!opening || !closing) {
            display.value = "";
            note.textContent = "Calculated automatically.";
            note.classList.remove("text-danger");
            return;
        }

        var hours = branchHours(opening, closing);

        if (hours === null) {
            display.value = "";
            note.textContent = "Opening and closing time cannot be the same.";
            note.classList.add("text-danger");
            return;
        }

        display.value = hours + (hours === 1 ? " hour" : " hours");
        note.textContent = branchMinutes(closing) < branchMinutes(opening)
            ? "Overnight: closes the next day."
            : "Calculated automatically.";
        note.classList.remove("text-danger");
    }

    function branchNotify(icon, title, text) {
        if (window.Swal) {
            return Swal.fire({ icon: icon, title: title, text: text, confirmButtonColor: "#00224c" });
        }
        alert(title + "\n\n" + text);
        return Promise.resolve();
    }

    document.addEventListener("DOMContentLoaded", function () {

        var form = document.getElementById("branchForm");
        if (!form) return;

        document.getElementById("openingTime").addEventListener("input", updateOperatingHours);
        document.getElementById("closingTime").addEventListener("input", updateOperatingHours);
        updateOperatingHours();

        form.addEventListener("submit", function (event) {

            if (form.dataset.confirmed === "1") {
                return;
            }

            event.preventDefault();

            var get = function (name) {
                var el = form.querySelector("[name='" + name + "']");
                return el ? String(el.value).trim() : "";
            };

            var isUpdate = document.getElementById("saveBranch").name === "update_branch";
            var opening = get("opening_time");
            var closing = get("closing_time");
            var today = new Date();
            var todayStr = today.getFullYear() + "-" + String(today.getMonth() + 1).padStart(2, "0")
                + "-" + String(today.getDate()).padStart(2, "0");

            var problem = null;

            if (!get("branch_name")) {
                problem = ["warning", "Branch Name Required", "Enter a name for the branch."];
            } else if (get("branch_name").length > 100) {
                problem = ["warning", "Branch Name Too Long", "The branch name must be 100 characters or fewer."];
            } else if (!get("complete_address") || !get("city") || !get("latitude") || !get("longitude")) {
                problem = ["warning", "Location Required", "Select the branch location on the map."];
            } else if (!opening || !closing) {
                problem = ["warning", "Operating Time Required", "Set both the opening time and the closing time."];
            } else if (branchHours(opening, closing) === null) {
                problem = ["error", "Invalid Operating Time", "Opening and closing time cannot be the same."];
            } else if (!get("opening_date")) {
                problem = ["warning", "Opening Date Required", "Choose the date this branch opens."];
            } else if (!isUpdate && get("opening_date") < todayStr) {
                problem = ["error", "Invalid Date", "Opening date cannot be in the past."];
            }

            if (problem) {
                branchNotify(problem[0], problem[1], problem[2]);
                return;
            }

            var hours = branchHours(opening, closing);
            var summary = get("branch_name") + " - " + get("city")
                + "\n" + branchTimeLabel(opening) + " to " + branchTimeLabel(closing)
                + " (" + hours + (hours === 1 ? " hour" : " hours") + ")"
                + "\nStatus: " + get("status");

            var proceed = function () {
                // Submitting from script drops the clicked button's name, which is
                // what tells the server create from update -- so it is carried here.
                var button = document.getElementById("saveBranch");
                var marker = document.createElement("input");
                marker.type = "hidden";
                marker.name = button.name;
                marker.value = "1";
                form.appendChild(marker);

                form.dataset.confirmed = "1";
                button.disabled = true;
                form.submit();
            };

            if (window.Swal) {
                Swal.fire({
                    icon: "question",
                    title: isUpdate ? "Save changes to this branch?" : "Create this branch?",
                    // The branch name is typed by the user, so it is escaped before
                    // it goes anywhere near innerHTML.
                    html: summary
                        .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
                        .replace(/"/g, "&quot;").replace(/'/g, "&#39;")
                        .replace(/\n/g, "<br>"),
                    showCancelButton: true,
                    confirmButtonText: isUpdate ? "Yes, save changes" : "Yes, create branch",
                    cancelButtonText: "Review again",
                    confirmButtonColor: "#00224c",
                    reverseButtons: true
                }).then(function (result) {
                    if (result.isConfirmed) proceed();
                });
            } else if (confirm((isUpdate ? "Save changes to this branch?" : "Create this branch?") + "\n\n" + summary)) {
                proceed();
            }
        });
    });
</script>

<?php include("admin_footer.php"); ?>