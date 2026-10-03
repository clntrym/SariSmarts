<?php
/*
| HR answers.
|
| People, their time, their leave and the hiring pipeline -- never what any of
| them is paid. Pay belongs to the Finance answers, and to no tool at all.
*/

function chatbotHrHeadcount(mysqli $conn, array $ctx, string $question): array
{
    $stmt = $conn->prepare("
        SELECT
            SUM(employment_status <> 'Archived') AS active,
            SUM(employment_status = 'Pre-Employee') AS pre_employee,
            SUM(employment_status = 'Archived') AS archived
        FROM employees
        WHERE company_id = ?
    ");
    $stmt->bind_param("i", $ctx['company_id']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return [
        'title' => 'Employee count',
        'lines' => [
            /* "not archived", the same rule hr/employee_directory.php uses.
               Counting only 'Official Employee' lost everyone the Admin user
               page created, because that writes 'Active'. */
            ['Active employees', (string) (int) $row['active']],
            ['Still in onboarding', (string) (int) $row['pre_employee']],
            ['Archived', (string) (int) $row['archived']],
        ],
        'table' => null,
        'link' => chatbotPageLink($ctx, 'employees'),
    ];
}

function chatbotHrPendingLeave(mysqli $conn, array $ctx, string $question): array
{
    $stmt = $conn->prepare("
        SELECT CONCAT(e.first_name, ' ', e.last_name) AS employee,
               l.leave_type, l.start_date, l.end_date, l.hr_status
        FROM leave_requests l
        JOIN employees e ON e.employee_id = l.employee_id AND e.company_id = l.company_id
        WHERE l.company_id = ? AND l.hr_status = 'Pending'
        ORDER BY l.created_at DESC
        LIMIT 20
    ");
    $stmt->bind_param("i", $ctx['company_id']);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];

    while ($row = $result->fetch_assoc()) {
        $rows[] = [$row['employee'], $row['leave_type'],
                   $row['start_date'] . ' - ' . $row['end_date'], $row['hr_status']];
    }

    $stmt->close();

    return [
        'title' => 'Pending leave requests',
        'lines' => [],
        'table' => $rows
            ? ['columns' => ['Employee', 'Type', 'Dates', 'HR status'], 'rows' => $rows]
            : null,
        'link' => chatbotPageLink($ctx, 'leave_approval'),
        'note' => $rows ? null : 'No leave request is waiting for HR right now.',
    ];
}

function chatbotHrAttendanceToday(mysqli $conn, array $ctx, string $question): array
{
    $stmt = $conn->prepare("
        SELECT
            SUM(a.status = 'Present') AS present,
            SUM(a.status = 'Late') AS late,
            SUM(a.status = 'Absent') AS absent,
            SUM(a.status = 'Half Day') AS half_day
        FROM attendance a
        WHERE a.company_id = ? AND a.attendance_date = CURDATE()
    ");
    $stmt->bind_param("i", $ctx['company_id']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $stmt = $conn->prepare("
        SELECT CONCAT(e.first_name, ' ', e.last_name) AS employee,
               TIME(a.time_in) AS time_in,
               COALESCE(a.late_minutes, 0) AS late_minutes
        FROM attendance a
        JOIN employees e ON e.employee_id = a.employee_id AND e.company_id = a.company_id
        WHERE a.company_id = ? AND a.attendance_date = CURDATE() AND a.status = 'Late'
        ORDER BY a.late_minutes DESC
        LIMIT 10
    ");
    $stmt->bind_param("i", $ctx['company_id']);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];

    while ($late = $result->fetch_assoc()) {
        $rows[] = [$late['employee'], (string) $late['time_in'],
                   (string) (int) $late['late_minutes']];
    }

    $stmt->close();

    return [
        'title' => 'Attendance today',
        'lines' => [
            ['Present', (string) (int) $row['present']],
            ['Late', (string) (int) $row['late']],
            ['Absent', (string) (int) $row['absent']],
            ['Half day', (string) (int) $row['half_day']],
        ],
        'table' => $rows
            ? ['columns' => ['Employee', 'Time in', 'Late (minutes)'], 'rows' => $rows]
            : null,
        'link' => chatbotPageLink($ctx, 'attendance'),
    ];
}

function chatbotHrApplicants(mysqli $conn, array $ctx, string $question): array
{
    $stmt = $conn->prepare("
        SELECT CONCAT(a.first_name, ' ', a.last_name) AS applicant,
               j.job_title, a.status, DATE(a.applied_at) AS applied
        FROM applications a
        JOIN job j ON j.job_id = a.job_id AND j.company_id = a.company_id
        WHERE a.company_id = ?
        ORDER BY a.applied_at DESC
        LIMIT 20
    ");
    $stmt->bind_param("i", $ctx['company_id']);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];

    while ($row = $result->fetch_assoc()) {
        $rows[] = [$row['applicant'], $row['job_title'], $row['status'], (string) $row['applied']];
    }

    $stmt->close();

    return [
        'title' => 'Applicants',
        'lines' => [['Total', (string) count($rows)]],
        'table' => $rows
            ? ['columns' => ['Applicant', 'Applied for', 'Stage', 'Date'], 'rows' => $rows]
            : null,
        'link' => chatbotPageLink($ctx, 'applications'),
        'note' => $rows ? null : 'Nobody has applied yet.',
    ];
}

function chatbotHrOpenJobs(mysqli $conn, array $ctx, string $question): array
{
    $stmt = $conn->prepare("
        SELECT j.job_title, j.department, j.vacancies, j.application_deadline,
               COUNT(a.application_id) AS applicants
        FROM job j
        LEFT JOIN applications a ON a.job_id = j.job_id AND a.company_id = j.company_id
        WHERE j.company_id = ? AND j.status = 'Published'
        GROUP BY j.job_id, j.job_title, j.department, j.vacancies, j.application_deadline
        ORDER BY j.created_at DESC
        LIMIT 20
    ");
    $stmt->bind_param("i", $ctx['company_id']);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];

    while ($row = $result->fetch_assoc()) {
        $rows[] = [$row['job_title'], $row['department'], (string) (int) $row['vacancies'],
                   (string) $row['application_deadline'], (string) (int) $row['applicants']];
    }

    $stmt->close();

    return [
        'title' => 'Open job postings',
        'lines' => [],
        'table' => $rows
            ? ['columns' => ['Position', 'Department', 'Vacancies', 'Deadline', 'Applicants'],
               'rows' => $rows]
            : null,
        'link' => chatbotPageLink($ctx, 'recruitment'),
        'note' => $rows ? null : 'There is no published job posting right now.',
    ];
}

function chatbotHrIncompleteRecords(mysqli $conn, array $ctx, string $question): array
{
    /* What HR chases: somebody who is employed but cannot be contacted, or has
       no branch to be scheduled at. */
    $stmt = $conn->prepare("
        SELECT CONCAT(e.first_name, ' ', e.last_name) AS employee,
               CASE WHEN COALESCE(e.email, '') = '' THEN 'Missing' ELSE 'Yes' END AS has_email,
               CASE WHEN COALESCE(e.phone, '') = '' THEN 'Missing' ELSE 'Yes' END AS has_phone,
               CASE WHEN e.branch_id IS NULL THEN 'Missing' ELSE 'Yes' END AS has_branch
        FROM employees e
        WHERE e.company_id = ?
          AND e.employment_status <> 'Archived'
          AND (COALESCE(e.email, '') = '' OR COALESCE(e.phone, '') = '' OR e.branch_id IS NULL)
        ORDER BY e.last_name, e.first_name
        LIMIT 20
    ");
    $stmt->bind_param("i", $ctx['company_id']);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];

    while ($row = $result->fetch_assoc()) {
        $rows[] = [$row['employee'], $row['has_email'], $row['has_phone'], $row['has_branch']];
    }

    $stmt->close();

    return [
        'title' => 'Incomplete employee records',
        'lines' => [],
        'table' => $rows
            ? ['columns' => ['Employee', 'Email', 'Phone', 'Branch'], 'rows' => $rows]
            : null,
        'link' => chatbotPageLink($ctx, 'employees'),
        'note' => $rows ? null : 'Every active employee record has contact details and a branch.',
    ];
}
