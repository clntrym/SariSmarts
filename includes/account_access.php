<?php
/*
|--------------------------------------------------------------------------
| WHO MAY USE THE SYSTEM
|--------------------------------------------------------------------------
|
| One rule, in one place, for every door.
|
| It is here because it was in two places and they disagreed. Deactivating a
| user from User Management wrote status 'disabled'; the login page refused
| only the exact word 'inactive'. Two spellings of one idea, in two files, with
| nothing holding them together -- so a deactivated account signed in as though
| nothing had happened.
|
| The rule is stated once, as a whitelist: exactly one status opens the door
| and every other value, known or not, keeps it shut. A lock should fail
| closed.
*/

/* Only this one. Not a list to append to -- that is how the bug happened. */
const ACCOUNT_STATUS_ACTIVE = 'active';

const DEACTIVATION_REASON_MIN = 3;
const DEACTIVATION_REASON_MAX = 150;

/**
 * Whether a stored status value may use the system.
 *
 * Case and padding are ignored because the column is a free-text varchar that
 * different pages have written differently over time.
 */
function accountStatusAllowsAccess(?string $status): bool
{
    return strtolower(trim((string) $status)) === ACCOUNT_STATUS_ACTIVE;
}

/**
 * What a refused person is told.
 *
 * Deliberately the same sentence whatever the status: 'disabled',
 * 'suspended' and a typo in the column are all somebody else's business to
 * explain, and naming the internal value tells an attacker how the system
 * thinks.
 */
function accountAccessMessage(?string $status): string
{
    return 'Your account is no longer active. Please contact your administrator.';
}

/**
 * The live status of a user, read fresh.
 *
 * Fresh is the point: a session carries what was true at sign-in, and an
 * account deactivated at ten past nine must not keep working until its owner
 * chooses to sign out.
 */
function userAccountStatus(mysqli $conn, int $userId): ?string
{
    if ($userId <= 0) {
        return null;
    }

    $stmt = $conn->prepare("SELECT status FROM users WHERE user_id = ? LIMIT 1");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row === null ? null : (string) $row['status'];
}

/**
 * Whether this user, right now, may use the system.
 *
 * A user id that matches no row is refused rather than allowed: a deleted
 * account is not an active one.
 */
function userAccountAllowsAccess(mysqli $conn, int $userId): bool
{
    $status = userAccountStatus($conn, $userId);

    return $status !== null && accountStatusAllowsAccess($status);
}

/**
 * What is wrong with a deactivation reason, or null if nothing is.
 *
 * The reason is typed by an administrator, stored, and displayed back in the
 * Archive tab, so it is both a record and untrusted input. It is checked here,
 * on the server, because the browser check can simply be skipped by posting to
 * the endpoint directly -- and the browser check that existed only refused a
 * reason made ENTIRELY of punctuation, which let "Resigned!!!" and a pasted
 * <script> straight through.
 *
 * Letters, digits and spaces are allowed, plus the punctuation a real reason
 * actually needs: . , - ' ( ). Unicode letters are allowed too, so a reason
 * written in Filipino is not refused for its ñ.
 */
function deactivationReasonProblem(string $reason): ?string
{
    $reason = trim($reason);

    if ($reason === '') {
        return 'Please provide a reason for deactivation.';
    }

    $length = mb_strlen($reason);

    if ($length < DEACTIVATION_REASON_MIN) {
        return 'Reason must be at least ' . DEACTIVATION_REASON_MIN . ' characters.';
    }

    if ($length > DEACTIVATION_REASON_MAX) {
        return 'Reason must be ' . DEACTIVATION_REASON_MAX . ' characters or fewer.';
    }

    if (!preg_match("/^[\p{L}\p{N} .,\-'()]+$/u", $reason)) {
        return 'Reason may use letters, numbers, spaces and . , - \' ( ) only.';
    }

    /* Punctuation alone is not a reason, whichever punctuation it is. */
    if (!preg_match('/\p{L}/u', $reason)) {
        return 'Reason must include words, not punctuation alone.';
    }

    return null;
}
