<?php

/*
|--------------------------------------------------------------------------
| AUDIT TRAIL
|--------------------------------------------------------------------------
|
| One function, called from the Super Admin write paths that change money,
| access or published content. The Audit Logs module only reads what this
| writes.
|
| Two decisions worth knowing about:
|
|   The operator's name is copied into the row, not just their id. An audit
|   trail that turns into "user 21 did this" once an account is renamed or
|   removed has lost the part that mattered.
|
|   A failure here never stops the action it was recording. Losing a log
|   line is bad; refusing to deactivate a compromised account because the
|   logger had a problem is worse.
|
*/

if (!function_exists('auditLog')) {

    function auditLog(
        mysqli $conn,
        string $action,
        string $entity,
        $entityId = null,
        ?string $summary = null
    ): void {
        try {
            $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
            $userName = trim((string) ($_SESSION['fullname'] ?? '')) ?: 'System';

            /* REMOTE_ADDR is absent on CLI, and can be an IPv6 form. */
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;

            if ($ip !== null) {
                $ip = substr($ip, 0, 45);
            }

            $entityId = $entityId === null ? null : substr((string) $entityId, 0, 60);

            $stmt = $conn->prepare("
                INSERT INTO audit_log
                    (user_id, user_name, action, entity, entity_id, summary, ip_address)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");

            if (!$stmt) {
                return;
            }

            $stmt->bind_param('issssss', $userId, $userName, $action, $entity, $entityId, $summary, $ip);
            $stmt->execute();
            $stmt->close();

        } catch (Throwable $ignored) {
            /* See the note above: the trail is best effort. */
        }
    }
}
