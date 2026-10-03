<?php
/*
| Recruitment.
|
| The job table also carries the advertised pay range. It is not selected here
| and must not be: the owner's rule is that this assistant has no tool that
| returns pay, and "it is public in the posting anyway" is not the same as
| "this assistant may hand it out". The audit fails this file if the words
| appear at all.
*/

function chatToolRecruitmentSummary(mysqli $conn, array $ctx, array $in): array
{
    $limit = min((int) ($in['limit'] ?? CHAT_TOOL_ROW_CAP), CHAT_TOOL_ROW_CAP) + 1;

    /* Built from a validated enum, never from model text. */
    $condition = $in['state'] === 'open' ? "j.status = 'Published'" : '1 = 1';

    $stmt = $conn->prepare("
        SELECT j.job_title, j.department, j.vacancies, j.status, j.application_deadline,
               COUNT(a.application_id) AS applicants,
               SUM(a.status = 'Pending') AS awaiting_review,
               SUM(a.status IN ('Interview', 'Interview Result')) AS at_interview,
               SUM(a.status = 'Recommended') AS recommended,
               SUM(a.status = 'Hired') AS hired,
               SUM(a.status = 'Rejected') AS rejected
        FROM job j
        LEFT JOIN applications a
               ON a.job_id = j.job_id AND a.company_id = j.company_id
        WHERE j.company_id = ? AND {$condition}
        GROUP BY j.job_id, j.job_title, j.department, j.vacancies, j.status,
                 j.application_deadline
        ORDER BY j.created_at DESC
        LIMIT ?
    ");
    $stmt->bind_param("ii", $ctx['company_id'], $limit);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];

    while ($row = $result->fetch_assoc()) {
        $rows[] = array_map(static fn ($value): string => (string) $value, array_values($row));
    }

    $stmt->close();

    return ['columns' => ['job_title', 'department', 'vacancies', 'status', 'deadline',
                          'applicants', 'awaiting_review', 'at_interview', 'recommended',
                          'hired', 'rejected'],
            'rows' => $rows];
}
