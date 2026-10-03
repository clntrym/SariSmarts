<?php
/*
| HR advisories.
|
| The check and its evidence come from the SAME query, so the figures shown
| are the figures the condition was judged on.
*/

/**
 * People left recently and nothing is posted to replace them.
 *
 * This is the suggestion the owner asked for by name. It stops as soon as a
 * posting is published -- advice that keeps repeating after the work is done
 * is noise, and noise is how an assistant gets ignored.
 */
function chatbotAdviseHiring(mysqli $conn, array $ctx): ?array
{
    $stmt = $conn->prepare("
        SELECT
            (SELECT COUNT(*) FROM employees e
              WHERE e.company_id = ?
                AND e.employment_status = 'Archived'
                AND e.archived_at IS NOT NULL
                AND e.archived_at >= DATE_SUB(NOW(), INTERVAL ? DAY)) AS departures,
            (SELECT COUNT(*) FROM job j
              WHERE j.company_id = ? AND j.status = 'Published') AS open_postings
    ");
    $days = ADVICE_RECENT_DEPARTURE_DAYS;
    $stmt->bind_param("iii", $ctx['company_id'], $days, $ctx['company_id']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $departures = (int) $row['departures'];
    $postings = (int) $row['open_postings'];

    if ($departures === 0 || $postings > 0) {
        return null;
    }

    return [
        'message' => $departures . ' staff left recently and no job posting is open. '
            . 'Consider posting a vacancy.',
        'evidence' => [
            ['Staff who left', (string) $departures],
            ['Open job postings', (string) $postings],
            ['Looking back', ADVICE_RECENT_DEPARTURE_DAYS . ' days'],
        ],
    ];
}

/**
 * Applicants left at the same stage for longer than a week.
 */
function chatbotAdviseStaleApplicants(mysqli $conn, array $ctx): ?array
{
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS stale,
               COALESCE(MAX(DATEDIFF(NOW(), applied_at)), 0) AS oldest_days
        FROM applications
        WHERE company_id = ?
          AND status = 'Pending'
          AND applied_at < DATE_SUB(NOW(), INTERVAL ? DAY)
    ");
    $days = ADVICE_APPLICANT_STALE_DAYS;
    $stmt->bind_param("ii", $ctx['company_id'], $days);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $stale = (int) $row['stale'];

    if ($stale === 0) {
        return null;
    }

    return [
        'message' => $stale . ' applicant(s) have been waiting for review for more than a week.',
        'evidence' => [
            ['Waiting longer than a week', (string) $stale],
            ['Longest wait', (int) $row['oldest_days'] . ' days'],
        ],
    ];
}

/**
 * Leave requests HR has not acted on.
 */
function chatbotAdviseLeaveWaiting(mysqli $conn, array $ctx): ?array
{
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS waiting,
               COALESCE(MAX(DATEDIFF(NOW(), created_at)), 0) AS oldest_days,
               COALESCE(SUM(start_date <= CURDATE()), 0) AS already_started
        FROM leave_requests
        WHERE company_id = ? AND hr_status = 'Pending'
    ");
    $stmt->bind_param("i", $ctx['company_id']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $waiting = (int) $row['waiting'];

    if ($waiting === 0) {
        return null;
    }

    return [
        'message' => $waiting . ' leave request(s) are waiting for HR.',
        'evidence' => [
            ['Waiting', (string) $waiting],
            ['Oldest', (int) $row['oldest_days'] . ' days'],
            ['Already started', (string) (int) $row['already_started']],
        ],
    ];
}

/**
 * Active employees who cannot be contacted or scheduled.
 */
function chatbotAdviseIncompleteRecords(mysqli $conn, array $ctx): ?array
{
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS incomplete,
               COALESCE(SUM(COALESCE(email, '') = ''), 0) AS no_email,
               COALESCE(SUM(COALESCE(phone, '') = ''), 0) AS no_phone,
               COALESCE(SUM(branch_id IS NULL), 0) AS no_branch
        FROM employees
        WHERE company_id = ?
          AND employment_status <> 'Archived'
          AND (COALESCE(email, '') = '' OR COALESCE(phone, '') = '' OR branch_id IS NULL)
    ");
    $stmt->bind_param("i", $ctx['company_id']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $incomplete = (int) $row['incomplete'];

    if ($incomplete === 0) {
        return null;
    }

    return [
        'message' => $incomplete . ' employee record(s) are missing contact details or a branch.',
        'evidence' => [
            ['Records to complete', (string) $incomplete],
            ['No email', (string) (int) $row['no_email']],
            ['No phone', (string) (int) $row['no_phone']],
            ['No branch', (string) (int) $row['no_branch']],
        ],
    ];
}
