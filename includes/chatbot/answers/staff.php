<?php
/*
| Staff answers: counts and overviews only. Nothing here reads a salary, a
| document, or a disciplinary record -- those belong to the HR topics, which
| Phase 2 adds under the HR role.
*/

function chatbotStaffCount(mysqli $conn, array $ctx, string $question): array
{
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM users
        WHERE company_id = ? AND LOWER(COALESCE(status, 'active')) = 'active'
    ");
    $stmt->bind_param("i", $ctx['company_id']);
    $stmt->execute();
    $users = (int) $stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();

    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM employees
        WHERE company_id = ? AND employment_status <> 'Archived'
    ");
    $stmt->bind_param("i", $ctx['company_id']);
    $stmt->execute();
    $employees = (int) $stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();

    return [
        'title' => 'Employee count',
        'lines' => [
            ['Active user accounts', (string) $users],
            ['Recorded employees', (string) $employees],
        ],
        'table' => null,
        'link' => chatbotPageLink($ctx, 'users'),
    ];
}

/**
 * The one subject this assistant deliberately has no tool for.
 *
 * Saying so plainly is the answer. The alternative -- letting the question
 * fall through to whichever intent shares a word with it -- produced a product
 * search for "salary of Ana Cruz".
 */
function chatbotSalaryNotAvailable(mysqli $conn, array $ctx, string $question): array
{
    return [
        'title' => 'Individual pay',
        'lines' => [],
        'table' => null,
        'link' => null,
        /* True for every role. Finance and HR DO have payroll answers -- what
           nothing here answers is one person's pay. */
        'note' => 'I cannot look up an individual\'s pay. Payroll totals and '
            . 'payslips are on the Payroll pages.',
    ];
}
