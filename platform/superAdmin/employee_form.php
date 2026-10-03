<?php

require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../includes/platform_roles.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/name_parts.php';
require_once __DIR__ . '/../includes/field_rules.php';
require_once __DIR__ . '/../includes/platform_contracts.php';
require_once __DIR__ . '/../includes/platform_employees.php';
requirePlatformAccess('employees');

/*
|--------------------------------------------------------------------------
| ADD OR EDIT AN EMPLOYEE
|--------------------------------------------------------------------------
|
| A page rather than the modal it used to be. The record now carries
| personal details, employment details, an optional login account and a
| photograph, and a dialog that long stops being a dialog: it scrolls, it
| cannot be linked to, and a mistake halfway down is invisible behind the
| part you are looking at.
|
| Three things on this page write somewhere other than platform_employees:
|
|   the login account    creates a row in users and links it
|   the photograph       a file under uploads/employee_photos
|   the contract         drawn up on hire, by includes/platform_contracts.php
|
| The permissions in the sidebar are READ ONLY, and deliberately. Access on
| this platform is decided by role in includes/platform_roles.php, not per
| person: two people with the same role must be able to do the same things,
| or the role means nothing. The checkboxes show what the chosen role opens
| so somebody filling the form can see the consequence of their choice.
|
*/

const EMPLOYEE_PHOTO_MAX_BYTES = 2 * 1024 * 1024;
const EMPLOYEE_PHOTO_DIR = __DIR__ . '/../uploads/employee_photos';

/* From includes/platform_employees.php, which holds the column's own
   values so the form and the enum cannot drift apart. */
$DEPARTMENTS = employeeDepartments();
$TYPES       = employeeTypes();
$STATUSES    = employeeStatuses();
$GENDERS     = employeeGenders();

$alert = null;
$employeeId = (int) ($_GET['id'] ?? 0);

/* What the form shows. Repopulated from the submission when one is
   refused, so nothing typed is lost. */
$employee = [
    'employee_id' => 0,
    'first_name' => '', 'middle_name' => '', 'last_name' => '',
    'work_email' => '', 'contact_number' => '', 'date_of_birth' => '',
    'gender' => '', 'address' => '',
    'department' => '', 'position' => '', 'employment_type' => '',
    'date_hired' => '', 'salary' => '', 'supervisor_id' => 0,
    'status' => 'Active', 'date_left' => '',
    'user_id' => 0, 'notes' => '', 'profile_picture' => '',
];


/*
|--------------------------------------------------------------------------
| THE MODULES A ROLE OPENS
|--------------------------------------------------------------------------
|
| Read from the registry rather than listed again here, so the sidebar
| cannot drift away from what the guard actually enforces.
*/
$MODULE_LABELS = [
    'dashboard' => 'Dashboard',
    'leads' => 'Leads',
    'employees' => 'Employees',
    'support' => 'Customer Support',
    'notifications' => 'Notifications',
    'companyReview' => 'Applications',
    'company' => 'Companies',
    'marketplace' => 'Marketplace',
    'subscriptionManagement' => 'Subscriptions',
    'billing' => 'Billing',
    'reports' => 'Reports',
    'users' => 'User Management',
    'audit' => 'Audit Logs',
    'settings' => 'System Settings',
    'homePage' => 'Website: Homepage',
    'platformSection' => 'Website: Platform',
    'heroBanner' => 'Website: Hero Banner',
    'features' => 'Website: Features',
    'pricing' => 'Website: Pricing',
    'careers' => 'Website: Careers',
    'footer' => 'Website: Footer',
];

$rolePermissions = [];

foreach (platformRoles() as $slug => $role) {
    $modules = $role['modules'];
    $rolePermissions[$slug] = $modules === '*' ? array_keys($MODULE_LABELS) : $modules;
}


/**
 * Stores an uploaded photograph, or returns the problem.
 *
 * The type is read from the file's own bytes, not its name, and the stored
 * name is generated here so nothing the uploader chose builds a path.
 *
 * @return array{0: ?string, 1: ?string} [relative path, problem]
 */
function storeEmployeePhoto(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return [null, null];
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        return [null, 'The photo could not be uploaded.'];
    }

    if ($file['size'] > EMPLOYEE_PHOTO_MAX_BYTES) {
        return [null, 'The photo must be 2 MB or smaller.'];
    }

    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png'];
    $detected = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);

    if (!isset($allowed[$detected])) {
        return [null, 'The photo must be a JPG or PNG.'];
    }

    if (!is_dir(EMPLOYEE_PHOTO_DIR) && !mkdir(EMPLOYEE_PHOTO_DIR, 0777, true)) {
        return [null, 'Could not open the photo folder.'];
    }

    $name = 'emp_' . bin2hex(random_bytes(10)) . '.' . $allowed[$detected];

    if (!move_uploaded_file($file['tmp_name'], EMPLOYEE_PHOTO_DIR . '/' . $name)) {
        return [null, 'Could not store the photo.'];
    }

    return ['uploads/employee_photos/' . $name, null];
}


/**
 * Everything wrong with the submission, as a sentence, or null.
 */
function formProblem(
    mysqli $conn,
    array $post,
    array $departments,
    array $types,
    array $statuses,
    array $genders,
    int $employeeId
): ?string {

    $nameProblem = nameProblem(
        trim($post['last_name'] ?? ''),
        trim($post['first_name'] ?? ''),
        trim($post['middle_name'] ?? ''),
        150
    );

    if ($nameProblem !== null) {
        return $nameProblem;
    }

    $email = trim($post['work_email'] ?? '');

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'Please enter a valid work email.';
    }

    /* A work email is how HR and payroll find a person. */
    $stmt = $conn->prepare("SELECT employee_id FROM platform_employees WHERE work_email = ? AND employee_id <> ? LIMIT 1");
    $stmt->bind_param("si", $email, $employeeId);
    $stmt->execute();
    $taken = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    if ($taken) {
        return 'Another employee already uses that work email.';
    }

    $contact = trim($post['contact_number'] ?? '');

    if ($contact === '') {
        return 'Contact number is required.';
    }

    $contactProblem = fieldProblem($contact, 'Contact number', RULE_PHONE, true, 60, 'digits, spaces and + ( ) -');

    if ($contactProblem !== null) {
        return $contactProblem;
    }

    $dob = trim($post['date_of_birth'] ?? '');

    if ($dob !== '') {
        $parsed = date_create_from_format('Y-m-d', $dob);

        if (!$parsed || $parsed->format('Y-m-d') !== $dob) {
            return 'Date of birth must be a real date.';
        }

        /* Somebody born tomorrow is a typo, and somebody 14 cannot be hired. */
        if (strtotime($dob) > strtotime('-15 years')) {
            return 'That date of birth is too recent to be right.';
        }

        if (strtotime($dob) < strtotime('-100 years')) {
            return 'That date of birth is too far back to be right.';
        }
    }

    if (($post['gender'] ?? '') !== '' && !in_array($post['gender'], $genders, true)) {
        return 'Please choose a gender from the list.';
    }

    $address = trim($post['address'] ?? '');

    if ($address !== '') {
        $addressProblem = fieldProblem($address, 'Address', RULE_ADDRESS, false, 255,
                                       "letters, numbers, spaces and . , & ' ( ) # / -");

        if ($addressProblem !== null) {
            return $addressProblem;
        }
    }

    if (trim($post['position'] ?? '') === '') {
        return 'Position is required.';
    }

    if (!in_array($post['department'] ?? '', $departments, true)) {
        return 'Please choose a department.';
    }

    if (!in_array($post['employment_type'] ?? '', $types, true)) {
        return 'Please choose an employment type.';
    }

    $salary = trim((string) ($post['salary'] ?? ''));

    if ($salary !== '' && (!is_numeric($salary) || (float) $salary < 0)) {
        return 'Salary must be a number of zero or more.';
    }

    $supervisor = (int) ($post['supervisor_id'] ?? 0);

    if ($supervisor > 0) {

        if ($supervisor === $employeeId) {
            return 'An employee cannot be their own supervisor.';
        }

        $found = $conn->query("SELECT employee_id FROM platform_employees WHERE employee_id = " . $supervisor . " LIMIT 1");

        if (!$found || $found->num_rows === 0) {
            return 'That supervisor is no longer on record.';
        }
    }

    $status = $post['status'] ?? '';

    if (!in_array($status, $statuses, true)) {
        return 'Please choose a status.';
    }

    $hired = trim($post['date_hired'] ?? '');
    $parsed = date_create_from_format('Y-m-d', $hired);

    if (!$parsed || $parsed->format('Y-m-d') !== $hired) {
        return 'Date hired must be a real date.';
    }

    if (strtotime($hired) > strtotime(date('Y-m-d'))) {
        return 'Date hired cannot be in the future.';
    }

    if ($dob !== '' && strtotime($hired) < strtotime($dob)) {
        return 'Date hired cannot be before the date of birth.';
    }

    $left = trim($post['date_left'] ?? '');
    $leaving = in_array($status, ['Resigned', 'Terminated'], true);

    if ($leaving && $left === '') {
        return 'Please give the date they left.';
    }

    if ($left !== '') {
        $parsedLeft = date_create_from_format('Y-m-d', $left);

        if (!$parsedLeft || $parsedLeft->format('Y-m-d') !== $left) {
            return 'Date left must be a real date.';
        }

        if (strtotime($left) < strtotime($hired)) {
            return 'Date left cannot be before the date hired.';
        }
    }

    /*
    | The login account, when one is being created. The rules are the ones
    | User Management already applies, so an account made here and an
    | account made there are the same kind of thing.
    */
    if (!empty($post['create_login'])) {

        $username = trim($post['login_username'] ?? '');
        $password = (string) ($post['login_password'] ?? '');
        $role = $post['login_role'] ?? '';

        if ($username === '' || !preg_match('/^[A-Za-z0-9._@-]{4,50}$/', $username)) {
            return 'Username must be 4 to 50 characters: letters, numbers, dot, dash, underscore or @.';
        }

        if (!array_key_exists($role, platformRoles())) {
            return 'Please choose a platform role for the login account.';
        }

        if (strlen($password) < 8) {
            return 'The temporary password must be at least 8 characters.';
        }

        $stmt = $conn->prepare("SELECT user_id FROM users WHERE username = ? OR email = ? LIMIT 1");
        $stmt->bind_param("ss", $username, $email);
        $stmt->execute();
        $clash = $stmt->get_result()->num_rows > 0;
        $stmt->close();

        if ($clash) {
            return 'That username or work email is already used by an account.';
        }
    }

    return null;
}


/*
|--------------------------------------------------------------------------
| LOAD, FOR AN EDIT
|--------------------------------------------------------------------------
*/

if ($employeeId > 0 && $_SERVER['REQUEST_METHOD'] !== 'POST') {

    $stmt = $conn->prepare("SELECT * FROM platform_employees WHERE employee_id = ? LIMIT 1");
    $stmt->bind_param("i", $employeeId);
    $stmt->execute();
    $found = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$found) {
        $_SESSION['employee_alert'] = ['icon' => 'error', 'title' => 'Not Found',
                                       'text' => 'That employee no longer exists.'];
        header('Location: employees.php');
        exit;
    }

    $employee = array_merge($employee, $found);
}


/*
|--------------------------------------------------------------------------
| SAVE
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $employeeId = (int) ($_POST['employee_id'] ?? 0);

    /* Keep what was typed, whatever happens next. */
    foreach (array_keys($employee) as $field) {
        if (array_key_exists($field, $_POST)) {
            $employee[$field] = $_POST[$field];
        }
    }

    $employee['employee_id'] = $employeeId;

    $problem = formProblem($conn, $_POST, $DEPARTMENTS, $TYPES, $STATUSES, $GENDERS, $employeeId);

    if ($problem === null && $employeeId > 0) {
        $exists = $conn->query("SELECT employee_id FROM platform_employees WHERE employee_id = " . $employeeId . " LIMIT 1");

        /* An UPDATE that matches nothing still succeeds. */
        if (!$exists || $exists->num_rows === 0) {
            $problem = 'That employee no longer exists.';
        }
    }

    [$photoPath, $photoProblem] = $problem === null
        ? storeEmployeePhoto($_FILES['profile_picture'] ?? [])
        : [null, null];

    if ($problem === null && $photoProblem !== null) {
        $problem = $photoProblem;
    }

    if ($problem !== null) {

        $alert = ['icon' => 'error', 'title' => 'Check the form', 'text' => $problem];

    } else {

        $lastName   = trim($_POST['last_name']);
        $firstName  = trim($_POST['first_name']);
        $middleName = trim($_POST['middle_name'] ?? '');
        $name = composeFullName($lastName, $firstName, $middleName);

        $email      = trim($_POST['work_email']);
        $contact    = trim($_POST['contact_number']);
        $dob        = trim($_POST['date_of_birth'] ?? '') ?: null;
        $gender     = ($_POST['gender'] ?? '') !== '' ? $_POST['gender'] : null;
        $address    = trim($_POST['address'] ?? '') ?: null;
        $department = $_POST['department'];
        $position   = trim($_POST['position']);
        $type       = $_POST['employment_type'];
        $salary     = trim((string) ($_POST['salary'] ?? ''));
        $salary     = $salary === '' ? null : (float) $salary;
        $supervisor = (int) ($_POST['supervisor_id'] ?? 0) ?: null;
        $status     = $_POST['status'];
        $hired      = trim($_POST['date_hired']);
        $notes      = trim($_POST['notes'] ?? '') ?: null;
        $middleValue = $middleName === '' ? null : $middleName;

        /* Only somebody who has left carries a leaving date. */
        $left = trim($_POST['date_left'] ?? '');
        $left = in_array($status, ['Resigned', 'Terminated'], true) && $left !== '' ? $left : null;

        /* An upload replaces the old photo; no upload keeps it. */
        $photo = $photoPath ?? (trim((string) ($_POST['existing_photo'] ?? '')) ?: null);

        $conn->begin_transaction();

        try {

            /*
            | The login account, created before the employee row so the
            | employee can be linked to it in one write. If this throws,
            | nothing at all is saved.
            */
            $userId = (int) ($_POST['user_id'] ?? 0) ?: null;

            if (!empty($_POST['create_login'])) {

                $username = trim($_POST['login_username']);
                $role = $_POST['login_role'];
                $hash = password_hash((string) $_POST['login_password'], PASSWORD_DEFAULT);

                $stmt = $conn->prepare("
                    INSERT INTO users
                        (company_id, username, fullname, first_name, middle_name, last_name,
                         email, contact, password, role, status)
                    VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')
                ");
                $stmt->bind_param(
                    "sssssssss",
                    $username, $name, $firstName, $middleValue, $lastName,
                    $email, $contact, $hash, $role
                );
                $stmt->execute();
                $userId = (int) $conn->insert_id;
                $stmt->close();
            }

            if ($employeeId > 0) {

                $stmt = $conn->prepare("
                    UPDATE platform_employees SET
                        full_name = ?, first_name = ?, middle_name = ?, last_name = ?,
                        work_email = ?, contact_number = ?, date_of_birth = ?, gender = ?,
                        address = ?, department = ?, position = ?, employment_type = ?,
                        salary = ?, supervisor_id = ?, status = ?, date_hired = ?,
                        date_left = ?, user_id = ?, notes = ?, profile_picture = ?
                    WHERE employee_id = ?
                ");
                $stmt->bind_param(
                    "ssssssssssssdisssissi",
                    $name, $firstName, $middleValue, $lastName,
                    $email, $contact, $dob, $gender,
                    $address, $department, $position, $type,
                    $salary, $supervisor, $status, $hired,
                    $left, $userId, $notes, $photo, $employeeId
                );
                $stmt->execute();
                $stmt->close();

                $savedId = $employeeId;
                $code = null;
                $done = 'updated';

            } else {

                $code = nextEmployeeCode($conn);

                $stmt = $conn->prepare("
                    INSERT INTO platform_employees
                        (employee_code, full_name, first_name, middle_name, last_name,
                         work_email, contact_number, date_of_birth, gender, address,
                         department, position, employment_type, salary, supervisor_id,
                         status, date_hired, date_left, user_id, notes, profile_picture)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->bind_param(
                    "sssssssssssssdisssiss",
                    $code, $name, $firstName, $middleValue, $lastName,
                    $email, $contact, $dob, $gender, $address,
                    $department, $position, $type, $salary, $supervisor,
                    $status, $hired, $left, $userId, $notes, $photo
                );
                $stmt->execute();
                $savedId = (int) $conn->insert_id;
                $stmt->close();

                $done = 'added';
            }

            $conn->commit();

        } catch (Throwable $e) {

            $conn->rollback();

            /* The photo is already on disk; the row that would have named
               it is not, so it would be orphaned. */
            if ($photoPath !== null && is_file(dirname(__DIR__) . '/' . $photoPath)) {
                @unlink(dirname(__DIR__) . '/' . $photoPath);
            }

            $alert = ['icon' => 'error', 'title' => 'Save Failed',
                      'text' => 'Could not save this employee. Please try again.'];
            $savedId = 0;
        }

        if (!empty($savedId)) {

            auditLog($conn, 'Employee ' . $done, 'employee', $savedId,
                     $name . ' - ' . $position . ' (' . $department . ', ' . $status . ')');

            /* The contract is drawn up at the hire and only at the hire. */
            $contractNumber = null;

            if ($done === 'added') {
                $contractNumber = createContractForHire(
                    $conn, $savedId, $position, (int) ($_SESSION['user_id'] ?? 0) ?: null
                );

                if ($contractNumber !== null) {
                    auditLog($conn, 'Contract drawn up', 'contract', $savedId,
                             $contractNumber . ' for ' . $name);
                }
            }

            $_SESSION['employee_alert'] = [
                'icon' => 'success',
                'title' => 'Employee Saved',
                'text' => $name . ' has been ' . $done . ($code ? ' as ' . $code : '') . '.'
                    . ($contractNumber !== null
                        ? ' Contract ' . $contractNumber . ' is waiting to be sent.' : ''),
            ];

            header('Location: employees.php');
            exit;
        }
    }
}


include("sAdminHeader.php");

/* Everyone who could be a supervisor: on the books, and not this person. */
$supervisors = [];
$stmt = $conn->prepare("
    SELECT employee_id, full_name, position
    FROM platform_employees
    WHERE status IN ('Active', 'On Leave') AND employee_id <> ?
    ORDER BY full_name
");
$stmt->bind_param("i", $employeeId);
$stmt->execute();
$rows = $stmt->get_result();

while ($row = $rows->fetch_assoc()) {
    $supervisors[] = $row;
}

$stmt->close();

/* Platform accounts not already attached to somebody. */
$accounts = $conn->query("
    SELECT u.user_id, u.username, u.role
    FROM users u
    WHERE u.company_id IS NULL
      AND (u.user_id = " . (int) $employee['user_id'] . "
           OR u.user_id NOT IN (SELECT user_id FROM platform_employees WHERE user_id IS NOT NULL))
    ORDER BY u.username
");

$isEdit = (int) $employee['employee_id'] > 0;

$value = function (string $field) use ($employee): string {
    return htmlspecialchars((string) ($employee[$field] ?? ''));
};
?>

<style>
    .ef-card {
        background: #fff;
        border: 1px solid var(--sa-line);
        border-radius: 14px;
        margin-bottom: 20px;
        overflow: hidden;
    }

    .ef-head {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 14px 20px;
        background: #f8fafc;
        border-bottom: 1px solid var(--sa-line);
        font-weight: 700;
        color: var(--sa-navy);
    }

    .ef-head i {
        width: 30px;
        height: 30px;
        border-radius: 8px;
        background: #e8eef6;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 14px;
    }

    .ef-body {
        padding: 20px;
    }

    .ef-optional {
        font-weight: 500;
        color: #94a3b8;
        font-size: 13px;
    }

    .ef-photo {
        width: 120px;
        height: 120px;
        border-radius: 50%;
        object-fit: cover;
        background: #e2e8f0;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 48px;
        color: #94a3b8;
        margin: 0 auto 14px;
    }

    .ef-perm {
        display: flex;
        align-items: center;
        gap: 9px;
        padding: 5px 0;
        font-size: 14px;
        color: #475569;
    }

    .ef-perm i {
        font-size: 15px;
    }

    .ef-actions {
        display: flex;
        justify-content: flex-end;
        gap: 10px;
        padding-bottom: 30px;
    }
</style>


<a href="employees.php" class="sa-muted d-inline-flex align-items-center gap-2 mb-3"
    style="text-decoration:none;">
    <i class="bi bi-chevron-left"></i> Back to Employees
</a>

<div class="sa-page-head">
    <div class="d-flex align-items-center gap-3">
        <div class="ef-head-icon d-flex align-items-center justify-content-center"
            style="width:52px;height:52px;border-radius:14px;background:#e8eef6;color:#00224c;font-size:22px;">
            <i class="bi bi-person"></i>
        </div>
        <div>
            <h3 class="sa-page-title mb-0"><?= $isEdit ? 'Edit Employee' : 'Add Employee' ?></h3>
            <p class="sa-page-sub mb-0">
                <?= $isEdit
                    ? 'Update this internal employee of the SariSmart platform.'
                    : 'Create a new internal employee for the SariSmart platform.' ?>
            </p>
        </div>
    </div>
</div>


<form method="POST" enctype="multipart/form-data" id="employeeForm"
    data-confirm="<?= $isEdit ? 'Save changes to this employee?' : 'Add this employee?' ?>"
    data-confirm-text="<?= $isEdit
        ? 'The record is updated straight away.'
        : 'A contract is drawn up with the record, and any login account is created at the same time.' ?>"
    data-confirm-button="Save">

    <input type="hidden" name="employee_id" value="<?= (int) $employee['employee_id'] ?>">
    <input type="hidden" name="existing_photo" value="<?= $value('profile_picture') ?>">

    <div class="row g-4">

        <!-- ============ LEFT: the record ============ -->
        <div class="col-lg-8">

            <!-- 1. PERSONAL -->
            <div class="ef-card">
                <div class="ef-head"><i class="bi bi-people"></i> 1. Personal Information</div>
                <div class="ef-body">
                    <div class="row g-3">

                        <div class="col-md-4">
                            <label class="form-label">First Name <span class="text-danger">*</span></label>
                            <input type="text" name="first_name" class="form-control" required
                                maxlength="<?= NAME_PART_MAX ?>" pattern="<?= htmlspecialchars(NAME_PATTERN) ?>"
                                title="Letters, spaces, hyphens and apostrophes only."
                                placeholder="Enter first name" value="<?= $value('first_name') ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Middle Name</label>
                            <input type="text" name="middle_name" class="form-control"
                                maxlength="<?= NAME_PART_MAX ?>" pattern="<?= htmlspecialchars(NAME_PATTERN) ?>"
                                title="Letters, spaces, hyphens and apostrophes only."
                                placeholder="Enter middle name (optional)" value="<?= $value('middle_name') ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Last Name <span class="text-danger">*</span></label>
                            <input type="text" name="last_name" class="form-control" required
                                maxlength="<?= NAME_PART_MAX ?>" pattern="<?= htmlspecialchars(NAME_PATTERN) ?>"
                                title="Letters, spaces, hyphens and apostrophes only."
                                placeholder="Enter last name" value="<?= $value('last_name') ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Email Address <span class="text-danger">*</span></label>
                            <input type="email" name="work_email" class="form-control" required maxlength="150"
                                placeholder="employee@sarismart.com" value="<?= $value('work_email') ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Contact Number <span class="text-danger">*</span></label>
                            <input type="text" name="contact_number" class="form-control" required
                                maxlength="60" pattern="<?= htmlspecialchars(RULE_PHONE) ?>"
                                title="Digits, spaces and + ( ) - only."
                                placeholder="09XX XXX XXXX" value="<?= $value('contact_number') ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Date of Birth</label>
                            <input type="date" name="date_of_birth" class="form-control"
                                max="<?= date('Y-m-d', strtotime('-15 years')) ?>"
                                value="<?= $value('date_of_birth') ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Gender</label>
                            <select name="gender" class="form-select">
                                <option value="">Select gender</option>
                                <?php foreach ($GENDERS as $g): ?>
                                    <option value="<?= $g ?>" <?= $employee['gender'] === $g ? 'selected' : '' ?>>
                                        <?= $g ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-8">
                            <label class="form-label">Address</label>
                            <textarea name="address" rows="2" class="form-control" maxlength="255"
                                placeholder="Enter complete address"><?= $value('address') ?></textarea>
                        </div>

                    </div>
                </div>
            </div>


            <!-- 2. EMPLOYMENT -->
            <div class="ef-card">
                <div class="ef-head"><i class="bi bi-briefcase"></i> 2. Employment Details</div>
                <div class="ef-body">
                    <div class="row g-3">

                        <div class="col-md-4">
                            <label class="form-label">Department <span class="text-danger">*</span></label>
                            <select name="department" class="form-select" required>
                                <option value="">Select department</option>
                                <?php foreach ($DEPARTMENTS as $d): ?>
                                    <option value="<?= $d ?>" <?= $employee['department'] === $d ? 'selected' : '' ?>>
                                        <?= $d ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Position <span class="text-danger">*</span></label>
                            <input type="text" name="position" class="form-control" required maxlength="120"
                                placeholder="Enter position" value="<?= $value('position') ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Employment Type <span class="text-danger">*</span></label>
                            <select name="employment_type" class="form-select" required>
                                <option value="">Select employment type</option>
                                <?php foreach ($TYPES as $t): ?>
                                    <option value="<?= $t ?>" <?= $employee['employment_type'] === $t ? 'selected' : '' ?>>
                                        <?= $t ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Date Hired <span class="text-danger">*</span></label>
                            <input type="date" name="date_hired" class="form-control" required
                                max="<?= date('Y-m-d') ?>" value="<?= $value('date_hired') ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">
                                Salary <span class="ef-optional">(Optional)</span>
                            </label>
                            <input type="number" name="salary" class="form-control" min="0" step="0.01"
                                placeholder="Enter salary" value="<?= $value('salary') ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Supervisor / Manager</label>
                            <select name="supervisor_id" class="form-select">
                                <option value="0">Select supervisor</option>
                                <?php foreach ($supervisors as $s): ?>
                                    <option value="<?= (int) $s['employee_id'] ?>"
                                        <?= (int) $employee['supervisor_id'] === (int) $s['employee_id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($s['full_name']) ?> &mdash; <?= htmlspecialchars($s['position']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Status <span class="text-danger">*</span></label>
                            <select name="status" id="status" class="form-select" required>
                                <?php foreach ($STATUSES as $s): ?>
                                    <option value="<?= $s ?>" <?= $employee['status'] === $s ? 'selected' : '' ?>>
                                        <?= $s ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <?php
                        /*
                        | Shown only for somebody who has left. Asking a new
                        | hire when they stopped working here is a question
                        | with no good answer.
                        */
                        ?>
                        <div class="col-md-4" id="dateLeftWrap" hidden>
                            <label class="form-label">Date Left <span class="text-danger">*</span></label>
                            <input type="date" name="date_left" id="date_left" class="form-control"
                                value="<?= $value('date_left') ?>">
                        </div>

                    </div>
                </div>
            </div>


            <!-- 3. LOGIN -->
            <div class="ef-card">
                <div class="ef-head"><i class="bi bi-person-badge"></i> 3. Login Account</div>
                <div class="ef-body">

                    <?php if ($isEdit): ?>

                        <?php
                        /*
                        | An edit links an existing account rather than making
                        | one. Creating a second login for somebody who already
                        | has one is how duplicate accounts happen.
                        */
                        ?>
                        <label class="form-label">Linked account</label>
                        <select name="user_id" class="form-select">
                            <option value="0">No login account</option>
                            <?php while ($account = $accounts->fetch_assoc()): ?>
                                <option value="<?= (int) $account['user_id'] ?>"
                                    <?= (int) $employee['user_id'] === (int) $account['user_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($account['username']) ?>
                                    (<?= htmlspecialchars(platformRoleLabel($account['role'])) ?>)
                                </option>
                            <?php endwhile; ?>
                        </select>
                        <div class="form-text">
                            New accounts are made under User Management.
                        </div>

                    <?php else: ?>

                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" role="switch"
                                name="create_login" id="createLogin" value="1"
                                <?= !empty($_POST['create_login']) ? 'checked' : '' ?>>
                            <label class="form-check-label fw-semibold" for="createLogin">
                                Create Login Account
                            </label>
                        </div>

                        <div class="row g-3" id="loginFields" hidden>

                            <div class="col-md-4">
                                <label class="form-label">Username / Email <span class="text-danger">*</span></label>
                                <input type="text" name="login_username" id="login_username" class="form-control"
                                    maxlength="50" placeholder="Enter username or email"
                                    value="<?= htmlspecialchars((string) ($_POST['login_username'] ?? '')) ?>">
                            </div>

                            <div class="col-md-4">
                                <label class="form-label">Temporary Password <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <input type="password" name="login_password" id="login_password"
                                        class="form-control" placeholder="Enter temporary password">
                                    <button type="button" class="btn sa-btn-soft" id="togglePassword"
                                        tabindex="-1" aria-label="Show password">
                                        <i class="bi bi-eye-slash"></i>
                                    </button>
                                </div>
                                <div class="form-text">At least 8 characters.</div>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label">Role <span class="text-danger">*</span></label>
                                <select name="login_role" id="login_role" class="form-select">
                                    <option value="">Select role</option>
                                    <?php foreach (platformRoles() as $slug => $role): ?>
                                        <option value="<?= htmlspecialchars($slug) ?>"
                                            <?= ($_POST['login_role'] ?? '') === $slug ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($role['label']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                        </div>

                    <?php endif; ?>

                </div>
            </div>


            <!-- 4. NOTES -->
            <div class="ef-card">
                <div class="ef-head">
                    <i class="bi bi-journal-text"></i>
                    4. Additional Information <span class="ef-optional">(Optional)</span>
                </div>
                <div class="ef-body">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" rows="3" class="form-control"
                        placeholder="Add any additional notes about the employee..."><?= $value('notes') ?></textarea>
                </div>
            </div>

        </div>


        <!-- ============ RIGHT: role and photo ============ -->
        <div class="col-lg-4">

            <div class="ef-card">
                <div class="ef-head"><i class="bi bi-shield-check"></i> Role &amp; Permissions</div>
                <div class="ef-body">

                    <p class="sa-muted" style="font-size:13.5px;">
                        Permissions follow the role. Everybody on a role can reach the same
                        screens, which is what makes a role mean something.
                    </p>

                    <label class="form-label">Role</label>
                    <select class="form-select" id="permRole" <?= $isEdit ? 'disabled' : '' ?>>
                        <option value="">Select role</option>
                        <?php foreach (platformRoles() as $slug => $role): ?>
                            <option value="<?= htmlspecialchars($slug) ?>">
                                <?= htmlspecialchars($role['label']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <div class="fw-bold mt-3 mb-2" style="color:var(--sa-navy);">What this role opens</div>

                    <div id="permList">
                        <div class="sa-muted" style="font-size:13.5px;">
                            Choose a role to see the screens it opens.
                        </div>
                    </div>

                    <div class="alert alert-light border mt-3 mb-0" style="font-size:13px;">
                        <i class="bi bi-info-circle me-1"></i>
                        These follow the role and are not set per person. Change what a role
                        opens in <code>includes/platform_roles.php</code>.
                    </div>

                </div>
            </div>


            <div class="ef-card">
                <div class="ef-head">
                    <i class="bi bi-image"></i>
                    Profile Picture <span class="ef-optional">(Optional)</span>
                </div>
                <div class="ef-body text-center">

                    <?php if (trim((string) $employee['profile_picture']) !== ''): ?>
                        <img src="<?= $BASE_URL ?>/<?= $value('profile_picture') ?>" alt=""
                            class="ef-photo" id="photoPreview">
                    <?php else: ?>
                        <div class="ef-photo" id="photoPlaceholder"><i class="bi bi-person-fill"></i></div>
                        <img src="" alt="" class="ef-photo" id="photoPreview" hidden>
                    <?php endif; ?>

                    <input type="file" name="profile_picture" id="photoInput"
                        accept="image/jpeg,image/png" class="d-none">

                    <button type="button" class="btn sa-btn-soft" id="choosePhoto">
                        <i class="bi bi-upload me-1"></i> Choose File
                    </button>

                    <div class="sa-muted mt-2" id="photoName" style="font-size:13px;">No file chosen</div>
                    <div class="sa-muted" style="font-size:12px;">
                        Supported formats: JPG, PNG (Max 2MB)
                    </div>

                </div>
            </div>

        </div>

    </div>


    <div class="ef-actions">
        <a href="employees.php" class="btn sa-btn-soft px-4">Cancel</a>
        <button type="submit" name="saveEmployee" class="btn sa-btn px-4">
            <i class="bi bi-save me-1"></i> Save Employee
        </button>
    </div>

</form>


<script>
    document.addEventListener("DOMContentLoaded", function () {

        /* What each role opens, straight from the registry. */
        var permissions = <?= json_encode($rolePermissions, JSON_UNESCAPED_SLASHES) ?>;
        var labels = <?= json_encode($MODULE_LABELS, JSON_UNESCAPED_SLASHES) ?>;

        var permRole = document.getElementById("permRole");
        var permList = document.getElementById("permList");
        var loginRole = document.getElementById("login_role");

        function drawPermissions(slug) {

            permList.textContent = "";

            var modules = permissions[slug];

            if (!modules) {
                var hint = document.createElement("div");
                hint.className = "sa-muted";
                hint.style.fontSize = "13.5px";
                hint.textContent = "Choose a role to see the screens it opens.";
                permList.appendChild(hint);
                return;
            }

            modules.forEach(function (key) {

                var row = document.createElement("div");
                row.className = "ef-perm";

                var tick = document.createElement("i");
                tick.className = "bi bi-check-circle-fill text-success";

                var text = document.createElement("span");
                text.textContent = labels[key] || key;

                row.appendChild(tick);
                row.appendChild(text);
                permList.appendChild(row);
            });
        }

        permRole.addEventListener("change", function () {
            drawPermissions(permRole.value);

            /* The sidebar and the login role are the same choice asked twice;
               keeping them in step stops them disagreeing. */
            if (loginRole && loginRole.value !== permRole.value) {
                loginRole.value = permRole.value;
            }
        });

        if (loginRole) {
            loginRole.addEventListener("change", function () {
                permRole.value = loginRole.value;
                drawPermissions(loginRole.value);
            });

            if (loginRole.value) {
                permRole.value = loginRole.value;
                drawPermissions(loginRole.value);
            }
        }


        /* The login fields appear only when an account is being made. */
        var createLogin = document.getElementById("createLogin");
        var loginFields = document.getElementById("loginFields");

        function syncLogin() {

            if (!createLogin || !loginFields) {
                return;
            }

            var on = createLogin.checked;
            loginFields.hidden = !on;

            /* required follows what is on screen: a hidden required field
               refuses a submit and shows nothing to explain why. */
            ["login_username", "login_password", "login_role"].forEach(function (id) {
                var el = document.getElementById(id);
                if (el) el.required = on;
            });
        }

        if (createLogin) {
            createLogin.addEventListener("change", syncLogin);
            syncLogin();
        }


        /* Date left belongs only to somebody who has left. */
        var status = document.getElementById("status");
        var leftWrap = document.getElementById("dateLeftWrap");
        var dateLeft = document.getElementById("date_left");

        function syncStatus() {

            var leaving = status.value === "Resigned" || status.value === "Terminated";

            leftWrap.hidden = !leaving;
            dateLeft.required = leaving;

            if (!leaving) {
                dateLeft.value = "";
            }
        }

        status.addEventListener("change", syncStatus);
        syncStatus();


        /* The photograph, previewed before it is sent. */
        var choose = document.getElementById("choosePhoto");
        var photoInput = document.getElementById("photoInput");
        var photoName = document.getElementById("photoName");
        var preview = document.getElementById("photoPreview");
        var placeholder = document.getElementById("photoPlaceholder");

        choose.addEventListener("click", function () {
            photoInput.click();
        });

        photoInput.addEventListener("change", function () {

            var file = photoInput.files && photoInput.files[0];

            if (!file) {
                photoName.textContent = "No file chosen";
                return;
            }

            /* Said here as well as on the server, so somebody hears it
               before the upload rather than after it. */
            if (file.size > 2 * 1024 * 1024) {
                photoName.textContent = "That file is larger than 2 MB.";
                photoInput.value = "";
                return;
            }

            photoName.textContent = file.name;

            var reader = new FileReader();

            reader.onload = function (e) {
                preview.src = e.target.result;
                preview.hidden = false;
                if (placeholder) placeholder.hidden = true;
            };

            reader.readAsDataURL(file);
        });


        /* A password nobody can read is a password nobody can hand over. */
        var toggle = document.getElementById("togglePassword");
        var password = document.getElementById("login_password");

        if (toggle && password) {
            toggle.addEventListener("click", function () {
                var showing = password.type === "text";
                password.type = showing ? "password" : "text";
                toggle.querySelector("i").className = showing ? "bi bi-eye-slash" : "bi bi-eye";
            });
        }
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
