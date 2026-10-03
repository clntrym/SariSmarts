<?php

/*
 * REPORTS - HR
 *
 * The seven reports here are the right seven for HR and they stay. What
 * changed is everything around them:
 *
 *   THE BRANCH FILTER LEAKED. The old page built its options with
 *       SELECT branch_id, branch_name FROM branch ORDER BY branch_name
 *   and no company_id - so HR saw the branch names of every other business on
 *   the platform. Picking one of those then returned nothing, because the
 *   reports themselves WERE scoped. One missing clause made it both a leak and
 *   a dead control. reportBranchOptions() scopes it, and a hand-typed
 *   ?branch=... is checked against this company's own branches below.
 *
 *   NO EXPORT, NO PERIOD PRESETS, NO SUMMARY. Seven tables and two empty date
 *   boxes. Headcount, attendance rate and the pending queue all had to be
 *   counted by hand off the screen.
 *
 *   818 LINES, MOSTLY DUPLICATED. admin/reports.php was a near-copy of this
 *   file. Both now declare their reports and let includes/report_kit.php
 *   render them.
 *
 * THIS PAGE KEEPS ITS BRANCH FILTER
 *   Unlike the admin, inventory and finance pages: every table here reaches a
 *   branch through employees.branch_id, so the picker genuinely filters.
 */

require_once("../init.php");
requireRole(['hr']);

$companyId = requireCompany();

require_once(__DIR__ . "/../includes/report_kit.php");

$range = reportRange();
$branches = reportBranchOptions($conn, $companyId);
$branchId = reportBranchFilter();

$companyName = (string) reportValue(
    $conn,
    "SELECT company_name FROM company WHERE company_id = ?",
    [$companyId],
    'i',
    'Business'
);

/*
| A branch id from the query string is only honoured if it belongs to THIS
| company. Scoping the dropdown closes the leak; this closes the URL, which
| no dropdown can.
*/
if ($branchId !== 0) {

    $owned = false;

    foreach ($branches as $branch) {
        if ((int) $branch['branch_id'] === $branchId) {
            $owned = true;
            break;
        }
    }

    if (!$owned) {
        $branchId = 0;
    }
}

/* Appends the branch clause, if one is in force, against whichever alias of
   employees the query uses. */
$branchClause = static function (string $alias, array &$params, string &$types) use ($branchId): string {

    if ($branchId === 0) {
        return '';
    }

    $params[] = $branchId;
    $types .= 'i';

    return " AND $alias.branch_id = ?";
};

$reports = [];


/* ===================================================================
 * 1. RECRUITMENT
 * =================================================================== */

$params = [$companyId];
$types = 'i';
$clause = reportDateClause($range, 'a.applied_at', $params, $types);

/* An application reaches a branch through the job it was filed against, not
   through an employees row - it has no employee yet. */
if ($branchId !== 0) {
    $clause .= " AND j.branch_id = ?";
    $params[] = $branchId;
    $types .= 'i';
}

$recruitRows = reportRows($conn, "
    SELECT CONCAT(a.first_name, ' ', a.last_name) AS applicant,
           a.email, a.phone, a.location, a.applied_at, a.status,
           j.job_title, j.department, b.branch_name,
           ir.score, ir.recommendation
    FROM applications a
    LEFT JOIN job j ON j.job_id = a.job_id AND j.company_id = a.company_id
    LEFT JOIN branch b ON b.branch_id = j.branch_id AND b.company_id = a.company_id
    LEFT JOIN interview_results ir
           ON ir.application_id = a.application_id AND ir.company_id = a.company_id
    WHERE a.company_id = ?$clause
    ORDER BY a.applied_at DESC
", $params, $types);

$pipeline = [];
$hired = 0;
$scored = [];

foreach ($recruitRows as $row) {

    $label = $row['status'] !== '' ? $row['status'] : 'Unspecified';
    $pipeline[$label] = ($pipeline[$label] ?? 0) + 1;

    if ($row['status'] === 'Hired') {
        $hired++;
    }

    if ($row['score'] !== null && $row['score'] !== '') {
        $scored[] = (float) $row['score'];
    }
}

$reports[] = [
    'id'      => 'recruitment',
    'label'   => 'Recruitment',
    'icon'    => 'bi-person-plus',
    'blurb'   => 'Applications filed in this period and how far each one got.',
    'empty'   => 'Nobody applied in this period. Applications arrive through the careers page.',
    'kpis'    => [
        ['label' => 'Applicants', 'value' => reportNumber(count($recruitRows)),
         'icon' => 'bi-person-lines-fill', 'tone' => 'primary'],
        ['label' => 'Hired', 'value' => reportNumber($hired),
         'icon' => 'bi-person-check', 'tone' => 'success'],
        ['label' => 'Hire rate',
         'value' => count($recruitRows)
             ? number_format(($hired / count($recruitRows)) * 100, 1) . '%'
             : '&mdash;',
         'icon' => 'bi-funnel', 'tone' => 'info'],
        ['label' => 'Average score',
         'value' => $scored ? number_format(array_sum($scored) / count($scored), 1) : '&mdash;',
         'icon' => 'bi-clipboard-data', 'tone' => 'warning',
         'hint' => $scored ? count($scored) . ' interviewed' : 'nobody interviewed yet'],
    ],
    'visuals' => [
        ['title' => 'The hiring funnel', 'span' => 12,
         'body' => reportDonut($pipeline, [
             'Hired' => '#198754', 'Rejected' => '#dc3545',
             'Pending' => '#ffc107', 'Interview' => '#0d6efd', 'Recommended' => '#20c997',
         ])],
    ],
    'columns' => [
        ['head' => 'Applicant',      'key' => 'applicant'],
        ['head' => 'Applied for',    'key' => 'job_title'],
        ['head' => 'Department',     'key' => 'department'],
        ['head' => 'Branch',         'key' => 'branch_name'],
        ['head' => 'Email',          'key' => 'email'],
        ['head' => 'Phone',          'key' => 'phone'],
        ['head' => 'Location',       'key' => 'location'],
        ['head' => 'Score',          'key' => 'score',          'type' => 'number'],
        ['head' => 'Recommendation', 'key' => 'recommendation', 'type' => 'badge'],
        ['head' => 'Status',         'key' => 'status',         'type' => 'badge'],
        ['head' => 'Applied',        'key' => 'applied_at',     'type' => 'date'],
    ],
    'rows'    => $recruitRows,
];


/* ===================================================================
 * 2. EMPLOYEES
 * =================================================================== */

/*
| Headcount is a position, so this tab ignores the period and lists who is on
| the books now. The old page filtered employees by created_at, which answers
| "who was added in March" while being labelled "Employee" - a different
| question from the one HR opens the tab to ask.
*/
$params = [$companyId];
$types = 'i';
$clause = $branchClause('e', $params, $types);

$employeeRows = reportRows($conn, "
    SELECT e.employee_code,
           CONCAT(e.first_name, ' ', e.last_name) AS employee,
           e.email, e.phone, e.gender, e.civil_status,
           j.job_title, j.department, j.employment_type, j.salary_min AS salary,
           b.branch_name, e.employment_status, e.created_at
    FROM employees e
    LEFT JOIN job j ON j.job_id = e.job_id AND j.company_id = e.company_id
    LEFT JOIN branch b ON b.branch_id = e.branch_id AND b.company_id = e.company_id
    WHERE e.company_id = ? AND e.archived_at IS NULL$clause
    ORDER BY e.last_name ASC, e.first_name ASC
", $params, $types);

$byBranch = [];
$byStatus = [];
$byType = [];

foreach ($employeeRows as $row) {

    $label = $row['branch_name'] !== null && $row['branch_name'] !== ''
        ? $row['branch_name'] : 'Unassigned';
    $byBranch[$label] = ($byBranch[$label] ?? 0) + 1;

    $status = $row['employment_status'] !== '' ? $row['employment_status'] : 'Unspecified';
    $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;

    $type = $row['employment_type'] !== null && $row['employment_type'] !== ''
        ? $row['employment_type'] : 'Unspecified';
    $byType[$type] = ($byType[$type] ?? 0) + 1;
}

arsort($byBranch);

$reports[] = [
    'id'      => 'employees',
    'timeless' => true,
    'label'   => 'Employees',
    'icon'    => 'bi-people',
    'blurb'   => 'Everyone currently on the books. Outside the period filter - headcount is a position, not a period.',
    'empty'   => 'No employee is on record yet. Staff appear here once an applicant is hired.',
    'kpis'    => [
        ['label' => 'Headcount', 'value' => reportNumber(count($employeeRows)),
         'icon' => 'bi-people-fill', 'tone' => 'primary'],
        ['label' => 'Official', 'value' => reportNumber($byStatus['Official Employee'] ?? 0),
         'icon' => 'bi-patch-check', 'tone' => 'success'],
        ['label' => 'Pre-employee', 'value' => reportNumber($byStatus['Pre-Employee'] ?? 0),
         'icon' => 'bi-hourglass', 'tone' => 'warning'],
        ['label' => 'Branches staffed', 'value' => reportNumber(count($byBranch)),
         'icon' => 'bi-shop', 'tone' => 'info'],
    ],
    'visuals' => [
        ['title' => 'Staff per branch', 'span' => 5,
         'body' => reportBars($byBranch, 'number', 'primary')],
        ['title' => 'Employment standing', 'span' => 4,
         'body' => reportDonut($byStatus)],
        ['title' => 'Employment type', 'span' => 3,
         'body' => reportDonut($byType)],
    ],
    'columns' => [
        ['head' => 'Employee',   'key' => 'employee'],
        ['head' => 'Code',       'key' => 'employee_code'],
        ['head' => 'Position',   'key' => 'job_title'],
        ['head' => 'Department', 'key' => 'department'],
        ['head' => 'Type',       'key' => 'employment_type',   'type' => 'badge'],
        ['head' => 'Branch',     'key' => 'branch_name'],
        ['head' => 'Email',      'key' => 'email'],
        ['head' => 'Phone',      'key' => 'phone'],
        ['head' => 'Salary',     'key' => 'salary',            'type' => 'peso'],
        ['head' => 'Standing',   'key' => 'employment_status', 'type' => 'badge'],
        ['head' => 'On record',  'key' => 'created_at',        'type' => 'date'],
    ],
    'rows'    => $employeeRows,
];


/* ===================================================================
 * 3. ATTENDANCE
 * =================================================================== */

$params = [$companyId];
$types = 'i';
$clause = reportDateClause($range, 'a.attendance_date', $params, $types);
$clause .= $branchClause('e', $params, $types);

$attendanceRows = reportRows($conn, "
    SELECT a.attendance_date, a.time_in, a.time_out,
           a.late_minutes, a.overtime_hours, a.status,
           e.employee_code, CONCAT(e.first_name, ' ', e.last_name) AS employee,
           j.job_title, b.branch_name
    FROM attendance a
    INNER JOIN employees e
            ON e.employee_id = a.employee_id AND e.company_id = a.company_id
    LEFT JOIN job j ON j.job_id = e.job_id AND j.company_id = e.company_id
    LEFT JOIN branch b ON b.branch_id = e.branch_id AND b.company_id = e.company_id
    WHERE a.company_id = ?$clause
    ORDER BY a.attendance_date DESC, e.last_name ASC
", $params, $types);

$attendanceMix = [];
$lateMinutes = 0;
$overtimeHours = 0.0;
$present = 0;

foreach ($attendanceRows as $row) {

    $label = $row['status'] !== '' ? $row['status'] : 'Unspecified';
    $attendanceMix[$label] = ($attendanceMix[$label] ?? 0) + 1;

    $lateMinutes += (int) $row['late_minutes'];
    $overtimeHours += (float) $row['overtime_hours'];

    if (in_array($row['status'], ['Present', 'Late', 'Half Day'], true)) {
        $present++;
    }
}

$attendanceRate = count($attendanceRows) ? ($present / count($attendanceRows)) * 100 : 0;

$reports[] = [
    'id'      => 'attendance',
    'label'   => 'Attendance',
    'icon'    => 'bi-calendar-check',
    'blurb'   => 'Every logged day in this period, newest first.',
    'empty'   => 'No attendance was logged in this period.',
    'kpis'    => [
        ['label' => 'Days logged', 'value' => reportNumber(count($attendanceRows)),
         'icon' => 'bi-calendar3', 'tone' => 'primary'],
        ['label' => 'Turned up', 'value' => number_format($attendanceRate, 1) . '%',
         'icon' => 'bi-person-check', 'tone' => $attendanceRate >= 90 ? 'success' : 'warning',
         'hint' => $present . ' of ' . count($attendanceRows) . ' days'],
        ['label' => 'Absences', 'value' => reportNumber($attendanceMix['Absent'] ?? 0),
         'icon' => 'bi-person-x',
         'tone' => ($attendanceMix['Absent'] ?? 0) ? 'danger' : 'success'],
        ['label' => 'Late', 'value' => reportNumber($lateMinutes) . ' min',
         'icon' => 'bi-clock-history', 'tone' => $lateMinutes ? 'warning' : 'success',
         'hint' => ($attendanceMix['Late'] ?? 0) . ' late arrival(s)'],
    ],
    'visuals' => [
        ['title' => 'How the days were logged', 'span' => 5,
         'body' => reportDonut($attendanceMix, [
             'Present' => '#198754', 'Late' => '#ffc107', 'Absent' => '#dc3545',
             'Half Day' => '#20c997', 'Leave' => '#0d6efd',
         ])],
        ['title' => 'Overtime logged, by day', 'span' => 7,
         'body' => reportBars(
             reportBucket($attendanceRows, 'attendance_date', 'overtime_hours'),
             'number', 'info'
         )],
    ],
    'columns' => [
        ['head' => 'Date',     'key' => 'attendance_date', 'type' => 'date'],
        ['head' => 'Employee', 'key' => 'employee'],
        ['head' => 'Code',     'key' => 'employee_code'],
        ['head' => 'Position', 'key' => 'job_title'],
        ['head' => 'Branch',   'key' => 'branch_name'],
        ['head' => 'Time in',  'key' => 'time_in',         'type' => 'time'],
        ['head' => 'Time out', 'key' => 'time_out',        'type' => 'time'],
        ['head' => 'Late min', 'key' => 'late_minutes',    'type' => 'number'],
        ['head' => 'OT hrs',   'key' => 'overtime_hours',  'type' => 'number', 'places' => 1],
        ['head' => 'Status',   'key' => 'status',          'type' => 'badge'],
    ],
    'totals'  => [
        'late_minutes'   => $lateMinutes,
        'overtime_hours' => $overtimeHours,
    ],
    'rows'    => $attendanceRows,
];


/* ===================================================================
 * 4. PAYROLL
 * =================================================================== */

$params = [$companyId];
$types = 'i';
$clause = reportDateClause($range, 'p.payroll_period_end', $params, $types);
$clause .= $branchClause('e', $params, $types);

$payrollRows = reportRows($conn, "
    SELECT e.employee_code, CONCAT(e.first_name, ' ', e.last_name) AS employee,
           j.job_title, b.branch_name,
           p.payroll_period_start, p.payroll_period_end, p.working_days,
           p.basic_pay, p.overtime_pay, p.gross_pay,
           p.total_deduction, p.net_pay, p.status, p.is_edited, p.edit_reason
    FROM payroll p
    INNER JOIN employees e
            ON e.employee_id = p.employee_id AND e.company_id = p.company_id
    LEFT JOIN job j ON j.job_id = e.job_id AND j.company_id = e.company_id
    LEFT JOIN branch b ON b.branch_id = e.branch_id AND b.company_id = e.company_id
    WHERE p.company_id = ?$clause
    ORDER BY p.payroll_period_end DESC, e.last_name ASC
", $params, $types);

$netPay = array_sum(array_column($payrollRows, 'net_pay'));
$payrollStanding = [];
$edited = 0;

foreach ($payrollRows as $index => $row) {

    $label = $row['status'] !== '' ? $row['status'] : 'Unspecified';
    $payrollStanding[$label] = ($payrollStanding[$label] ?? 0) + 1;

    /* A payslip edited by hand is the one HR gets asked about, so it is
       marked rather than left to be noticed. */
    $wasEdited = !empty($row['is_edited']);
    $payrollRows[$index]['edited'] = $wasEdited ? 'Edited' : 'As computed';

    if ($wasEdited) {
        $edited++;
    }
}

$byPeriod = [];
foreach ($payrollRows as $row) {
    $label = reportWhen($row['payroll_period_end']);
    $byPeriod[$label] = ($byPeriod[$label] ?? 0) + (float) $row['net_pay'];
}

$reports[] = [
    'id'      => 'payroll',
    'label'   => 'Payroll',
    'icon'    => 'bi-cash-stack',
    'blurb'   => 'Payslips whose period ends inside this range.',
    'empty'   => 'No payslip falls in this period.',
    'kpis'    => [
        ['label' => 'Net payroll', 'value' => reportPeso($netPay),
         'icon' => 'bi-cash-stack', 'tone' => 'primary'],
        ['label' => 'Payslips', 'value' => reportNumber(count($payrollRows)),
         'icon' => 'bi-file-earmark-text', 'tone' => 'info'],
        ['label' => 'Awaiting approval',
         'value' => reportNumber($payrollStanding['Pending'] ?? 0),
         'icon' => 'bi-hourglass-split',
         'tone' => ($payrollStanding['Pending'] ?? 0) ? 'warning' : 'success'],
        ['label' => 'Edited by hand', 'value' => reportNumber($edited),
         'icon' => 'bi-pencil-square', 'tone' => $edited ? 'warning' : 'success'],
    ],
    'visuals' => [
        ['title' => 'Net payroll by period', 'span' => 7,
         'body' => reportBars(array_slice($byPeriod, 0, 12, true), 'peso', 'primary')],
        ['title' => 'Where the payslips stand', 'span' => 5,
         'body' => reportDonut($payrollStanding)],
    ],
    'columns' => [
        ['head' => 'Employee',    'key' => 'employee'],
        ['head' => 'Code',        'key' => 'employee_code'],
        ['head' => 'Position',    'key' => 'job_title'],
        ['head' => 'Branch',      'key' => 'branch_name'],
        ['head' => 'Period from', 'key' => 'payroll_period_start', 'type' => 'date'],
        ['head' => 'Period to',   'key' => 'payroll_period_end',   'type' => 'date'],
        ['head' => 'Days',        'key' => 'working_days',    'type' => 'number'],
        ['head' => 'Basic',       'key' => 'basic_pay',       'type' => 'peso'],
        ['head' => 'Overtime',    'key' => 'overtime_pay',    'type' => 'peso'],
        ['head' => 'Gross',       'key' => 'gross_pay',       'type' => 'peso'],
        ['head' => 'Deductions',  'key' => 'total_deduction', 'type' => 'peso'],
        ['head' => 'Net',         'key' => 'net_pay',         'type' => 'peso'],
        ['head' => 'Computed',    'key' => 'edited',          'type' => 'badge',
         'badges' => ['As computed' => 'success', 'Edited' => 'warning']],
        ['head' => 'Status',      'key' => 'status',          'type' => 'badge'],
    ],
    'totals'  => [
        'gross_pay'       => array_sum(array_column($payrollRows, 'gross_pay')),
        'total_deduction' => array_sum(array_column($payrollRows, 'total_deduction')),
        'net_pay'         => $netPay,
    ],
    'rows'    => $payrollRows,
];


/* ===================================================================
 * 5. LEAVE
 * =================================================================== */

$params = [$companyId];
$types = 'i';
$clause = reportDateClause($range, 'l.created_at', $params, $types);
$clause .= $branchClause('e', $params, $types);

$leaveRows = reportRows($conn, "
    SELECT l.leave_type, l.duration, l.start_date, l.end_date, l.reason,
           l.hr_status, l.admin_status, l.hr_remarks, l.admin_remarks, l.created_at,
           e.employee_code, CONCAT(e.first_name, ' ', e.last_name) AS employee,
           j.job_title, b.branch_name
    FROM leave_requests l
    INNER JOIN employees e
            ON e.employee_id = l.employee_id AND e.company_id = l.company_id
    LEFT JOIN job j ON j.job_id = e.job_id AND j.company_id = e.company_id
    LEFT JOIN branch b ON b.branch_id = e.branch_id AND b.company_id = e.company_id
    WHERE l.company_id = ?$clause
    ORDER BY l.created_at DESC
", $params, $types);

$leaveTypes = [];
$waitingHr = 0;
$waitingAdmin = 0;

foreach ($leaveRows as $index => $row) {

    $hrStatus = (string) ($row['hr_status'] ?? '');
    $adminStatus = (string) ($row['admin_status'] ?? '');

    /*
    | Two approvals, said in one column.
    |
    | The old page printed hr_status and admin_status as separate badges, both
    | reading "Pending" when nothing had happened - and Admin still reading
    | "Pending" after HR had rejected, which is misleading: an HR rejection
    | ends the request and Admin will never see it.
    */
    if ($hrStatus === 'Rejected') {
        $stage = 'Rejected by HR';
    } elseif ($adminStatus === 'Rejected') {
        $stage = 'Rejected by Admin';
    } elseif ($adminStatus === 'Approved') {
        $stage = 'Approved';
    } elseif ($hrStatus === 'Approved') {
        $stage = 'With Admin';
        $waitingAdmin++;
    } else {
        $stage = 'With HR';
        $waitingHr++;
    }

    $leaveRows[$index]['stage'] = $stage;

    $note = '';
    if ($hrStatus === 'Rejected' && !empty($row['hr_remarks'])) {
        $note = 'HR: ' . $row['hr_remarks'];
    } elseif ($adminStatus === 'Rejected' && !empty($row['admin_remarks'])) {
        $note = 'Admin: ' . $row['admin_remarks'];
    }
    $leaveRows[$index]['note'] = $note;

    $label = $row['leave_type'] !== '' ? $row['leave_type'] : 'Unspecified';
    $leaveTypes[$label] = ($leaveTypes[$label] ?? 0) + 1;
}

$leaveStageBadges = [
    'Approved' => 'success', 'With HR' => 'warning', 'With Admin' => 'info',
    'Rejected by HR' => 'danger', 'Rejected by Admin' => 'danger',
];

$reports[] = [
    'id'      => 'leave',
    'label'   => 'Leave',
    'icon'    => 'bi-calendar-x',
    'blurb'   => 'Leave filed in this period. The stage column says who is holding each one.',
    'empty'   => 'Nobody filed for leave in this period.',
    'kpis'    => [
        ['label' => 'Requests', 'value' => reportNumber(count($leaveRows)),
         'icon' => 'bi-calendar-x', 'tone' => 'primary'],
        ['label' => 'On my desk', 'value' => reportNumber($waitingHr),
         'icon' => 'bi-inbox', 'tone' => $waitingHr ? 'warning' : 'success',
         'hint' => 'waiting for HR'],
        ['label' => 'With Admin', 'value' => reportNumber($waitingAdmin),
         'icon' => 'bi-arrow-up-right-circle', 'tone' => 'info'],
        ['label' => 'Commonest type',
         'value' => $leaveTypes ? htmlspecialchars((string) array_key_first($leaveTypes)) : '&mdash;',
         'icon' => 'bi-list-stars', 'tone' => 'secondary'],
    ],
    'visuals' => [
        ['title' => 'Why people asked', 'span' => 7,
         'body' => reportBars($leaveTypes, 'number', 'primary')],
        ['title' => 'Stage', 'span' => 5,
         'body' => reportDonut(array_count_values(array_column($leaveRows, 'stage')), [
             'Approved' => '#198754', 'With HR' => '#ffc107',
             'With Admin' => '#0dcaf0', 'Rejected by HR' => '#dc3545',
             'Rejected by Admin' => '#b02a37',
         ])],
    ],
    'columns' => [
        ['head' => 'Filed',    'key' => 'created_at', 'type' => 'datetime'],
        ['head' => 'Employee', 'key' => 'employee'],
        ['head' => 'Code',     'key' => 'employee_code'],
        ['head' => 'Branch',   'key' => 'branch_name'],
        ['head' => 'Type',     'key' => 'leave_type'],
        ['head' => 'Duration', 'key' => 'duration'],
        ['head' => 'From',     'key' => 'start_date',  'type' => 'date'],
        ['head' => 'To',       'key' => 'end_date',    'type' => 'date'],
        ['head' => 'Reason',   'key' => 'reason',      'type' => 'wrap'],
        ['head' => 'Stage',    'key' => 'stage',       'type' => 'badge',
         'badges' => $leaveStageBadges],
        ['head' => 'Remarks',  'key' => 'note',        'type' => 'wrap'],
    ],
    'rows'    => $leaveRows,
];


/* ===================================================================
 * 6. OVERTIME
 * =================================================================== */

$params = [$companyId];
$types = 'i';
$clause = reportDateClause($range, 'o.created_at', $params, $types);
$clause .= $branchClause('e', $params, $types);

$overtimeRows = reportRows($conn, "
    SELECT o.requested_hours, o.approved_hours, o.reason, o.status,
           o.rejection_reason, o.created_at,
           e.employee_code, CONCAT(e.first_name, ' ', e.last_name) AS employee,
           j.job_title, b.branch_name
    FROM overtime_requests o
    INNER JOIN employees e
            ON e.employee_id = o.employee_id AND e.company_id = o.company_id
    LEFT JOIN job j ON j.job_id = e.job_id AND j.company_id = e.company_id
    LEFT JOIN branch b ON b.branch_id = e.branch_id AND b.company_id = e.company_id
    WHERE o.company_id = ?$clause
    ORDER BY o.created_at DESC
", $params, $types);

$requestedHours = array_sum(array_column($overtimeRows, 'requested_hours'));
$approvedHours = array_sum(array_column($overtimeRows, 'approved_hours'));
$overtimeStanding = [];
$byEmployeeOt = [];

foreach ($overtimeRows as $row) {

    $label = $row['status'] !== '' ? $row['status'] : 'Pending';
    $overtimeStanding[$label] = ($overtimeStanding[$label] ?? 0) + 1;

    if ($row['status'] === 'Approved') {
        $byEmployeeOt[$row['employee']] = ($byEmployeeOt[$row['employee']] ?? 0)
            + (float) $row['approved_hours'];
    }
}

arsort($byEmployeeOt);

$reports[] = [
    'id'      => 'overtime',
    'label'   => 'Overtime',
    'icon'    => 'bi-clock-history',
    'blurb'   => 'Overtime filed in this period, and how much of it was granted.',
    'empty'   => 'Nobody filed for overtime in this period.',
    'kpis'    => [
        ['label' => 'Requests', 'value' => reportNumber(count($overtimeRows)),
         'icon' => 'bi-clock-history', 'tone' => 'primary'],
        ['label' => 'Hours asked for', 'value' => reportNumber($requestedHours, 1),
         'icon' => 'bi-hourglass', 'tone' => 'info'],
        ['label' => 'Hours approved', 'value' => reportNumber($approvedHours, 1),
         'icon' => 'bi-check2-circle', 'tone' => 'success',
         'hint' => $requestedHours > 0
             ? number_format(($approvedHours / $requestedHours) * 100, 0) . '% of what was asked'
             : null],
        ['label' => 'Still pending', 'value' => reportNumber($overtimeStanding['Pending'] ?? 0),
         'icon' => 'bi-hourglass-split',
         'tone' => ($overtimeStanding['Pending'] ?? 0) ? 'warning' : 'success'],
    ],
    'visuals' => [
        ['title' => 'Approved hours by employee', 'span' => 7,
         'body' => reportBars(array_slice($byEmployeeOt, 0, 8, true), 'number', 'info')],
        ['title' => 'Where the requests stand', 'span' => 5,
         'body' => reportDonut($overtimeStanding)],
    ],
    'columns' => [
        ['head' => 'Filed',      'key' => 'created_at',       'type' => 'datetime'],
        ['head' => 'Employee',   'key' => 'employee'],
        ['head' => 'Code',       'key' => 'employee_code'],
        ['head' => 'Branch',     'key' => 'branch_name'],
        ['head' => 'Asked',      'key' => 'requested_hours',  'type' => 'number', 'places' => 1],
        ['head' => 'Approved',   'key' => 'approved_hours',   'type' => 'number', 'places' => 1],
        ['head' => 'Reason',     'key' => 'reason',           'type' => 'wrap'],
        ['head' => 'Status',     'key' => 'status',           'type' => 'badge'],
        ['head' => 'If refused', 'key' => 'rejection_reason', 'type' => 'wrap'],
    ],
    'totals'  => [
        'requested_hours' => $requestedHours,
        'approved_hours'  => $approvedHours,
    ],
    'rows'    => $overtimeRows,
];


/* ===================================================================
 * 7. UNDERTIME
 * =================================================================== */

$params = [$companyId];
$types = 'i';
$clause = reportDateClause($range, 'u.created_at', $params, $types);
$clause .= $branchClause('e', $params, $types);

$undertimeRows = reportRows($conn, "
    SELECT u.request_date, u.hours, u.reason, u.status,
           u.rejection_reason, u.created_at,
           e.employee_code, CONCAT(e.first_name, ' ', e.last_name) AS employee,
           j.job_title, b.branch_name
    FROM undertime_requests u
    INNER JOIN employees e
            ON e.employee_id = u.employee_id AND e.company_id = u.company_id
    LEFT JOIN job j ON j.job_id = e.job_id AND j.company_id = e.company_id
    LEFT JOIN branch b ON b.branch_id = e.branch_id AND b.company_id = e.company_id
    WHERE u.company_id = ?$clause
    ORDER BY u.created_at DESC
", $params, $types);

$undertimeHours = array_sum(array_column($undertimeRows, 'hours'));
$undertimeStanding = [];
$byEmployeeUt = [];

foreach ($undertimeRows as $row) {

    $label = $row['status'] !== '' ? $row['status'] : 'Pending';
    $undertimeStanding[$label] = ($undertimeStanding[$label] ?? 0) + 1;

    $byEmployeeUt[$row['employee']] = ($byEmployeeUt[$row['employee']] ?? 0)
        + (float) $row['hours'];
}

arsort($byEmployeeUt);

$reports[] = [
    'id'      => 'undertime',
    'label'   => 'Undertime',
    'icon'    => 'bi-clock',
    'blurb'   => 'Hours people asked to leave early, filed in this period.',
    'empty'   => 'Nobody filed for undertime in this period.',
    'kpis'    => [
        ['label' => 'Requests', 'value' => reportNumber(count($undertimeRows)),
         'icon' => 'bi-clock', 'tone' => 'primary'],
        ['label' => 'Hours', 'value' => reportNumber($undertimeHours, 1),
         'icon' => 'bi-hourglass', 'tone' => 'warning'],
        ['label' => 'Approved', 'value' => reportNumber($undertimeStanding['Approved'] ?? 0),
         'icon' => 'bi-check2-circle', 'tone' => 'success'],
        ['label' => 'Still pending', 'value' => reportNumber($undertimeStanding['Pending'] ?? 0),
         'icon' => 'bi-hourglass-split',
         'tone' => ($undertimeStanding['Pending'] ?? 0) ? 'warning' : 'success'],
    ],
    'visuals' => [
        ['title' => 'Hours by employee', 'span' => 7,
         'body' => reportBars(array_slice($byEmployeeUt, 0, 8, true), 'number', 'warning')],
        ['title' => 'Where the requests stand', 'span' => 5,
         'body' => reportDonut($undertimeStanding)],
    ],
    'columns' => [
        ['head' => 'Filed',      'key' => 'created_at',       'type' => 'datetime'],
        ['head' => 'Employee',   'key' => 'employee'],
        ['head' => 'Code',       'key' => 'employee_code'],
        ['head' => 'Branch',     'key' => 'branch_name'],
        ['head' => 'For',        'key' => 'request_date',     'type' => 'date'],
        ['head' => 'Hours',      'key' => 'hours',            'type' => 'number', 'places' => 1],
        ['head' => 'Reason',     'key' => 'reason',           'type' => 'wrap'],
        ['head' => 'Status',     'key' => 'status',           'type' => 'badge'],
        ['head' => 'If refused', 'key' => 'rejection_reason', 'type' => 'wrap'],
    ],
    'totals'  => ['hours' => $undertimeHours],
    'rows'    => $undertimeRows,
];


if (!empty($_GET['export'])) {
    reportExportCsv($reports, (string) $_GET['export'], $range, $companyName);
}

include("hr_header.php");

renderReportsPage([
    'title'    => 'HR Reports',
    'blurb'    => 'Hiring, headcount, attendance, pay and the request queue.',
    'range'    => $range,
    'branches' => $branches,
    'note'     => 'Employees shows who is on the books today and ignores the period above. Everything else is filtered by it.',
    'reports'  => $reports,
]);

include("hr_footer.php");
