<?php

require_once("../init.php");
requireRole(['admin']);

$companyId = requireCompany();

header('Content-Type: application/json');

/*
|--------------------------------------------------------------------------
| INPUT
|--------------------------------------------------------------------------
*/

$fullname = trim((string) ($_POST['fullname'] ?? ''));
$email = trim((string) ($_POST['email'] ?? ''));
$username = trim((string) ($_POST['username'] ?? ''));
$contact = trim((string) ($_POST['contact'] ?? ''));
$role = strtolower(trim((string) ($_POST['role'] ?? '')));
$password = (string) ($_POST['password'] ?? '');
$confirm = (string) ($_POST['confirm_password'] ?? '');
$branchId = (int) ($_POST['branch_id'] ?? 0);

/*
| The company's plan decides which roles it may hand out -- Retail Starter
| buys Admin, Cashier and Inventory Staff; Retail Professional buys all five.
| companyPlanRoles() reads that straight out of subscription_plan_roles, the
| same table platform/pricing.php advertises from.
|
| Note that no plan sells the "employee" role: acc_log_in.php has no case for
| it, so such an account would be turned away at the login screen anyway.
*/
$allowedRoles = companyPlanRoles($conn, $companyId);

function fail(string $message): void
{
    echo json_encode(["success" => false, "message" => $message]);
    exit;
}

/*
|--------------------------------------------------------------------------
| VALIDATION
|--------------------------------------------------------------------------
*/

if ($fullname === '') {
    fail("Please enter the user's full name.");
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fail("Please enter a valid email address.");
}

if (strlen($username) < 5) {
    fail("Username must be at least 5 characters.");
}

if (!preg_match('/^[A-Za-z0-9._-]+$/', $username)) {
    fail("Username may only contain letters, numbers, dots, underscores and dashes.");
}

if (!in_array($role, $allowedRoles, true)) {
    fail(
        "Your subscription plan does not include the " . roleDisplayName($role) .
        " role. Upgrade the plan to unlock it."
    );
}

if (strlen($password) < 8) {
    fail("Password must be at least 8 characters.");
}

if ($password !== $confirm) {
    fail("The two passwords do not match.");
}

if ($contact !== '' && !preg_match('/^[0-9+\s()-]{7,15}$/', $contact)) {
    fail("Please enter a valid contact number.");
}

/*
| Branch is optional by design. HR, Finance and the owner work across every
| branch rather than sitting in one, so forcing a choice would misdescribe
| them. It is only offered at all where the plan sells more than one store.
*/
if ($branchId > 0) {

    if (!companyHasModule($conn, $companyId, 'branch')) {
        fail("Your subscription plan covers a single store, so a branch cannot be assigned.");
    }

    $stmt = $conn->prepare("
        SELECT branch_name FROM branch
        WHERE branch_id = ? AND company_id = ? AND status = 'Active'
        LIMIT 1
    ");
    $stmt->bind_param("ii", $branchId, $companyId);
    $stmt->execute();
    $branchRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$branchRow) {
        fail("That branch does not belong to your company.");
    }
}

/*
|--------------------------------------------------------------------------
| UNIQUENESS
|
| Both checks are deliberately global rather than per-company:
|   - users.username carries a UNIQUE index across the whole table.
|   - acc_log_in.php looks an account up with "WHERE user_id = ? OR email = ?
|     LIMIT 1", so the same email under two companies would make one of the
|     two accounts unreachable.
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("SELECT 1 FROM users WHERE username = ? LIMIT 1");
$stmt->bind_param("s", $username);
$stmt->execute();
if ($stmt->get_result()->num_rows > 0) {
    $stmt->close();
    fail('The username "' . $username . '" is already taken.');
}
$stmt->close();

$stmt = $conn->prepare("SELECT 1 FROM users WHERE email = ? LIMIT 1");
$stmt->bind_param("s", $email);
$stmt->execute();
if ($stmt->get_result()->num_rows > 0) {
    $stmt->close();
    fail('An account already uses the email "' . $email . '".');
}
$stmt->close();

/*
|--------------------------------------------------------------------------
| PLAN SEAT LIMIT
|
| The plan's max_users was recorded at subscription time but nothing used to
| read it. This is the one place a company can grow its own seat count, so
| the ceiling is enforced here. A NULL or non-positive value means unlimited.
|--------------------------------------------------------------------------
*/

$maxUsers = null;

$stmt = $conn->prepare("
    SELECT p.max_users
    FROM company_subscriptions cs
    JOIN subscription_plans p ON p.plan_id = cs.plan_id
    WHERE cs.company_id = ?
      AND cs.status IN ('Active', 'Trial')
    ORDER BY cs.expiry_date DESC
    LIMIT 1
");
$stmt->bind_param("i", $companyId);
$stmt->execute();
if ($planRow = $stmt->get_result()->fetch_assoc()) {
    $maxUsers = $planRow['max_users'] === null ? null : (int) $planRow['max_users'];
}
$stmt->close();

if ($maxUsers !== null && $maxUsers > 0) {

    $stmt = $conn->prepare("
        SELECT COUNT(*) AS seats
        FROM users
        WHERE company_id = ? AND LOWER(status) = 'active'
    ");
    $stmt->bind_param("i", $companyId);
    $stmt->execute();
    $seats = (int) ($stmt->get_result()->fetch_assoc()['seats'] ?? 0);
    $stmt->close();

    if ($seats >= $maxUsers) {
        fail(
            "Your plan allows " . $maxUsers . " active users and you already have " .
            $seats . ". Deactivate a user or upgrade your plan to add another."
        );
    }
}

/*
|--------------------------------------------------------------------------
| CREATE
|--------------------------------------------------------------------------
*/

$hashedPassword = password_hash($password, PASSWORD_DEFAULT);
$status = 'active';
$contactValue = $contact === '' ? null : $contact;

$conn->begin_transaction();

try {

    /*
    | A branch lives on the employee record, reached through users.employee_id
    | -- there is no branch column on users, and adding one would leave two
    | places claiming to know where somebody works.
    |
    | So choosing a branch here creates the employee record the branch hangs
    | off. That also makes the Branch dropdown in the table work for this
    | account, which reads "No employee record" for anyone created without one.
    */
    $employeeId = null;

    if ($branchId > 0) {

        $parts = preg_split('/\s+/', trim($fullname));
        $parts = array_values(array_filter($parts));

        if (count($parts) > 1) {
            $lastName = array_pop($parts);
            $firstName = implode(' ', $parts);
        } else {
            $firstName = $parts[0] ?? $fullname;
            $lastName = '';
        }

        $employmentStatus = 'Active';

        $emp = $conn->prepare("
            INSERT INTO employees
                (company_id, first_name, last_name, email, phone, branch_id, employment_status)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $emp->bind_param(
            "issssis",
            $companyId,
            $firstName,
            $lastName,
            $email,
            $contactValue,
            $branchId,
            $employmentStatus
        );

        if (!$emp->execute()) {
            $emp->close();
            throw new Exception("Could not create the employee record.");
        }

        $employeeId = $conn->insert_id;
        $emp->close();
    }

    $stmt = $conn->prepare("
        INSERT INTO users
            (company_id, employee_id, username, fullname, email, contact, password, role, status)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param(
        "iisssssss",
        $companyId,
        $employeeId,
        $username,
        $fullname,
        $email,
        $contactValue,
        $hashedPassword,
        $role,
        $status
    );

    if (!$stmt->execute()) {
        $stmt->close();
        throw new Exception("Could not create the account. Please try again.");
    }

    $newUserId = $conn->insert_id;
    $stmt->close();

    $conn->commit();

} catch (Exception $e) {

    // Without this the employee record would outlive the account it belonged to.
    $conn->rollback();
    fail($e->getMessage());
}

$where = $branchId > 0
    ? " and is assigned to " . htmlspecialchars($branchRow['branch_name'])
    : "";

echo json_encode([
    "success" => true,
    "message" => htmlspecialchars($fullname) . " can now sign in with the email "
        . htmlspecialchars($email) . $where . ".",
    "user_id" => $newUserId
]);
