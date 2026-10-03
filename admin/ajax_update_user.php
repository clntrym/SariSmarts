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

$user_id = (int) ($_POST['user_id'] ?? 0);
$field = trim($_POST['field'] ?? '');
$value = $_POST['value'] ?? '';

$allowedFields = ['role', 'branch', 'status'];

if ($user_id <= 0) {
    echo json_encode(["success" => false, "message" => "Invalid user."]);
    exit;
}

if (!in_array($field, $allowedFields, true)) {
    echo json_encode(["success" => false, "message" => "Invalid field."]);
    exit;
}

/*
|--------------------------------------------------------------------------
| PREVENT AN ADMIN FROM LOCKING THEMSELVES OUT
|--------------------------------------------------------------------------
*/

$currentUserId = (int) ($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);

if ($field === 'status' && $user_id === $currentUserId && $value !== 'active') {

    echo json_encode([
        "success" => false,
        "message" => "You can't deactivate your own account."
    ]);
    exit;
}

/*
|--------------------------------------------------------------------------
| OWNERSHIP
|
| Every write below is already scoped with "AND company_id = ?", so another
| company's account cannot be touched. What that scoping does NOT do is
| report the miss: mysqli's execute() returns true when the WHERE clause
| matches nothing, so the handler used to answer "Role updated." for a
| user_id belonging to someone else. Resolve the row once, up front, and the
| answer matches what actually happened.
|--------------------------------------------------------------------------
*/

$owner = $conn->prepare("SELECT 1 FROM users WHERE user_id = ? AND company_id = ? LIMIT 1");
$owner->bind_param("ii", $user_id, $companyId);
$owner->execute();
$ownsUser = $owner->get_result()->num_rows === 1;
$owner->close();

if (!$ownsUser) {
    echo json_encode(["success" => false, "message" => "That user is not part of your company."]);
    exit;
}

/*
|--------------------------------------------------------------------------
| ROLE
|--------------------------------------------------------------------------
*/

if ($field === 'role') {

    $role = strtolower(trim($value));

    if ($role === '') {
        echo json_encode(["success" => false, "message" => "Role cannot be empty."]);
        exit;
    }

    /*
    | Same plan ceiling the Add User form enforces. Without it a Retail
    | Starter company could simply promote an existing cashier to Finance
    | from the table and get a module it never paid for.
    */
    if (!in_array($role, companyPlanRoles($conn, $companyId), true)) {
        echo json_encode([
            "success" => false,
            "message" => "Your subscription plan does not include the " . roleDisplayName($role) .
                " role. Upgrade the plan to unlock it."
        ]);
        exit;
    }

    $stmt = $conn->prepare("UPDATE users SET role = ? WHERE user_id = ? AND company_id = ?");
    $stmt->bind_param("sii", $role, $user_id, $companyId);

    if ($stmt->execute()) {
        echo json_encode(["success" => true, "message" => "Role updated."]);
    } else {
        echo json_encode(["success" => false, "message" => "Failed to update role."]);
    }

    $stmt->close();
    exit;
}

/*
|--------------------------------------------------------------------------
| STATUS (Enable / Disable access)
|--------------------------------------------------------------------------
*/

if ($field === 'status') {

    $allowedStatuses = ['active', 'disabled'];
    $status = trim($value);

    if (!in_array($status, $allowedStatuses, true)) {
        echo json_encode(["success" => false, "message" => "Invalid status."]);
        exit;
    }

    if ($status === 'disabled') {

        /*
        | Checked here, not only in the browser: the dialog's rules can be
        | skipped entirely by posting to this endpoint. The old check was
        | "not empty", which accepted a pasted <script> tag -- and the reason
        | is displayed back in the Archive tab.
        */
        $reason = trim($_POST['reason'] ?? '');
        $problem = deactivationReasonProblem($reason);

        if ($problem !== null) {
            echo json_encode(["success" => false, "message" => $problem]);
            exit;
        }

        $stmt = $conn->prepare("UPDATE users SET status = ?, deactivation_reason = ?, deactivated_at = NOW() WHERE user_id = ? AND company_id = ?");
        $stmt->bind_param("ssii", $status, $reason, $user_id, $companyId);
    } else {
        $stmt = $conn->prepare("UPDATE users SET status = ?, deactivation_reason = NULL, deactivated_at = NULL WHERE user_id = ? AND company_id = ?");
        $stmt->bind_param("sii", $status, $user_id, $companyId);
    }

    if ($stmt->execute()) {
        echo json_encode(["success" => true, "message" => $status === 'active' ? "Account reactivated." : "Account deactivated."]);
    } else {
        echo json_encode(["success" => false, "message" => "Failed to update access."]);
    }

    $stmt->close();
    exit;
}

/*
|--------------------------------------------------------------------------
| BRANCH (updates the linked employee's branch_id, not the users table)
|--------------------------------------------------------------------------
*/

if ($field === 'branch') {

    // Find the employee_id linked to this user
    $lookup = $conn->prepare("SELECT employee_id FROM users WHERE user_id = ? AND company_id = ? LIMIT 1");
    $lookup->bind_param("ii", $user_id, $companyId);
    $lookup->execute();
    $result = $lookup->get_result();
    $row = $result->fetch_assoc();
    $lookup->close();

    if (!$row || empty($row['employee_id'])) {
        echo json_encode([
            "success" => false,
            "message" => "This user has no linked employee record, so a branch can't be assigned."
        ]);
        exit;
    }

    $employee_id = (int) $row['employee_id'];

    // Empty value = unassign
    if ($value === '') {

        $stmt = $conn->prepare("UPDATE employees SET branch_id = NULL WHERE employee_id = ? AND company_id = ?");
        $stmt->bind_param("ii", $employee_id, $companyId);

        if ($stmt->execute()) {
            echo json_encode(["success" => true, "message" => "Branch unassigned."]);
        } else {
            echo json_encode(["success" => false, "message" => "Failed to unassign branch."]);
        }

        $stmt->close();
        exit;
    }

    $branch_id = (int) $value;

    // Confirm the branch actually exists
    $branchCheck = $conn->prepare("SELECT branch_id FROM branch WHERE branch_id = ? AND company_id = ? LIMIT 1");
    $branchCheck->bind_param("ii", $branch_id, $companyId);
    $branchCheck->execute();

    if ($branchCheck->get_result()->num_rows === 0) {
        $branchCheck->close();
        echo json_encode(["success" => false, "message" => "Selected branch does not exist."]);
        exit;
    }

    $branchCheck->close();

    $stmt = $conn->prepare("UPDATE employees SET branch_id = ? WHERE employee_id = ? AND company_id = ?");
    $stmt->bind_param("iii", $branch_id, $employee_id, $companyId);

    if ($stmt->execute()) {
        echo json_encode(["success" => true, "message" => "Branch updated."]);
    } else {
        echo json_encode(["success" => false, "message" => "Failed to update branch."]);
    }

    $stmt->close();
    exit;
}