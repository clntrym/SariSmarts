<?php
/*
|--------------------------------------------------------------------------
| ATTACH A FACE TO ONE OF YOUR OWN EMPLOYEES
|--------------------------------------------------------------------------
|
| hr/save_faces.php does nearly this, and the difference is the whole
| reason this file exists separately: that one registers the face of
| whoever is logged in, reading the employee from the session. It cannot
| touch anybody else's, so it needs no check beyond being signed in.
|
| This one is handed an employee_id by the browser. That is a different
| thing entirely. Without a company on every statement, one business could
| write a biometric onto another's staff by changing a number in a request
| -- and the owner of that staff member would never know, until somebody
| else's face opened their time-in.
|
| So the employee is looked up by id AND company, and the write names the
| company too. A request for an employee of another company is "no such
| employee", which is what it is from here.
|
| The face lands in employee_biometrics, because that is where
| cashier/attendance.php and employee/attendance.php read it from. A
| descriptor saved anywhere else would register perfectly and never be
| recognised.
*/

require_once __DIR__ . '/../init.php';

requireRole(['admin']);

header('Content-Type: application/json');

$companyId = requireCompany();

function faceReply(bool $ok, string $message, array $extra = []): never
{
    echo json_encode(['success' => $ok, 'message' => $message] + $extra);
    exit;
}

$payload = json_decode((string) file_get_contents('php://input'), true);

if (!is_array($payload)) {
    faceReply(false, 'Nothing was sent.');
}

$userId = (int) ($payload['user_id'] ?? 0);
$descriptor = $payload['descriptor'] ?? null;

if ($userId <= 0) {
    faceReply(false, 'Choose somebody first.');
}

/*
| face-api produces exactly 128 floats. Anything else is a broken capture,
| or somebody posting by hand, and storing it would mean a time-in that can
| never match -- a failure the employee meets at the door and cannot
| explain.
*/
if (!is_array($descriptor) || count($descriptor) !== 128) {
    faceReply(false, 'That capture did not produce a usable face. Try again.');
}

foreach ($descriptor as $number) {
    if (!is_int($number) && !is_float($number)) {
        faceReply(false, 'That capture did not produce a usable face. Try again.');
    }
}

/* The account, and only if it is this company's. */
$stmt = $conn->prepare("
    SELECT user_id, fullname, email, employee_id
    FROM users
    WHERE user_id = ? AND company_id = ?
    LIMIT 1
");
$stmt->bind_param("ii", $userId, $companyId);
$stmt->execute();
$account = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$account) {
    faceReply(false, 'No such staff member.');
}

$personName = trim((string) $account['fullname']);
$employeeId = (int) $account['employee_id'];

/*
| An employee row, made now if there is none.
|
| On a plan with no HR there is no onboarding, so staff exist as users and
| nothing else -- and attendance is keyed by employee_id. The row is
| created here and the account linked to it, which is the convention
| hr/my_attendance.php already set for the same situation.
*/
if ($employeeId > 0) {

    /* It exists, but prove it is this company's before writing to it. */
    $stmt = $conn->prepare("
        SELECT employee_id FROM employees
        WHERE employee_id = ? AND company_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("ii", $employeeId, $companyId);
    $stmt->execute();
    $owned = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$owned) {
        faceReply(false, 'That account is linked to an employee record of another company.');
    }

} else {

    /* "Juan Dela Cruz" -> first "Juan", last "Dela Cruz". A single word is
       all first name; last_name is NOT NULL, so it falls back to a stop
       rather than an empty string. Same split as my_attendance.php. */
    $parts = preg_split('/\s+/', $personName, 2) ?: [];
    $firstName = trim($parts[0] ?? '') !== '' ? trim($parts[0]) : 'Staff';
    $lastName = trim($parts[1] ?? '') !== '' ? trim($parts[1]) : '-';
    $email = trim((string) $account['email']);

    $stmt = $conn->prepare("
        INSERT INTO employees (company_id, first_name, last_name, email)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->bind_param("isss", $companyId, $firstName, $lastName, $email);

    if (!$stmt->execute()) {
        $problem = $stmt->error;
        $stmt->close();

        error_log('save_employee_face could not create an employee: ' . $problem);
        faceReply(false, 'An employee record could not be created: ' . $problem);
    }

    $employeeId = (int) $conn->insert_id;
    $stmt->close();

    /* Link the account to it, so every other page agrees from now on. */
    $stmt = $conn->prepare("
        UPDATE users SET employee_id = ?
        WHERE user_id = ? AND company_id = ?
    ");
    $stmt->bind_param("iii", $employeeId, $userId, $companyId);
    $stmt->execute();
    $stmt->close();
}

$json = json_encode(array_map('floatval', $descriptor));
$capturedBy = (int) ($_SESSION['user_id'] ?? 0);

/*
| One biometric row per employee. Re-registering replaces the descriptor
| rather than adding a second, so there is never a question of which of two
| faces the attendance page should believe.
*/
$stmt = $conn->prepare("
    SELECT biometric_id FROM employee_biometrics
    WHERE employee_id = ? AND company_id = ?
    LIMIT 1
");
$stmt->bind_param("ii", $employeeId, $companyId);
$stmt->execute();
$existing = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($existing) {

    $stmt = $conn->prepare("
        UPDATE employee_biometrics
        SET biometric_type = 'Face',
            face_descriptor = ?,
            captured_angles = 1,
            status = 'Captured',
            captured_by = ?,
            captured_at = NOW()
        WHERE biometric_id = ? AND company_id = ?
    ");
    $stmt->bind_param("siii", $json, $capturedBy, $existing['biometric_id'], $companyId);

} else {

    $stmt = $conn->prepare("
        INSERT INTO employee_biometrics
            (company_id, employee_id, biometric_type, face_descriptor,
             captured_angles, status, captured_by, captured_at)
        VALUES (?, ?, 'Face', ?, 1, 'Captured', ?, NOW())
    ");
    $stmt->bind_param("iisi", $companyId, $employeeId, $json, $capturedBy);
}

if (!$stmt->execute()) {
    $problem = $stmt->error;
    $stmt->close();

    error_log('save_employee_face failed: ' . $problem);
    faceReply(false, 'The face could not be saved: ' . $problem);
}

$stmt->close();

/*
| No audit entry here.
|
| I wrote auditLog() into this and it would have been a fatal error on the
| first successful capture: that function lives in platform/includes/audit.php
| and belongs to the platform, which is a separate application. Nothing on
| the tenant side calls it, and an audit_log table exists that nothing
| writes to. Giving this one page a trail that nothing else keeps would be
| inventing a convention, not following one.
*/
faceReply(true, $personName . ' can now time in by face.');
