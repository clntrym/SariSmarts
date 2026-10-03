<?php

/*
 * REPORTS - EMPLOYEE
 *
 * This page is new. The employee sidebar's Reports link pointed at
 * ../admin/reports.php, which begins with requireRole(['admin']). An employee
 * clicking Reports was bounced by the role switch in requireRole() - and
 * because that switch has no 'employee' case, they fell through to its default
 * branch, which sends the browser to the login page. Clicking Reports logged
 * them out.
 *
 * SCOPE
 *   One employee's own records and nothing else: their attendance, their
 *   leave, their overtime and undertime, their payslips. Every query is
 *   filtered by employee_id as well as company_id, so this page cannot show
 *   one employee another's pay.
 *
 * NO BRANCH FILTER, NO EXPORT OF OTHER PEOPLE
 *   There is one branch here - the employee's own - so a picker would do
 *   nothing. The CSV export carries only their own rows, which is the point:
 *   a payslip history is a thing people need to be able to hand to a landlord
 *   or a lender.
 */

require_once("../init.php");
requireRole(['employee']);

$companyId = requireCompany();

require_once(__DIR__ . "/../includes/report_kit.php");

$range = reportRange();

$companyName = (string) reportValue(
    $conn,
    "SELECT company_name FROM company WHERE company_id = ?",
    [$companyId],
    'i',
    'Business'
);

/*
| Who this is, read from the database rather than the session.
|
| The session's employee_id was written at sign-in; the join below also
| confirms the employees row still belongs to this company, so a stale or
| tampered session cannot reach another company's employee.
*/
$me = reportRows($conn, "
    SELECT e.employee_id, e.employee_code,
           CONCAT(e.first_name, ' ', e.last_name) AS employee,
           e.email, e.employment_status,
           j.job_title, j.department, b.branch_name
    FROM users u
    INNER JOIN employees e
            ON e.employee_id = u.employee_id AND e.company_id = u.company_id
    LEFT JOIN job j ON j.job_id = e.job_id AND j.company_id = e.company_id
    LEFT JOIN branch b ON b.branch_id = e.branch_id AND b.company_id = e.company_id
    WHERE u.user_id = ? AND u.company_id = ?
    LIMIT 1
", [(int) $_SESSION['user_id'], $companyId], 'ii');

/*
| A login with no employees row behind it.
|
| This happens when an account is created before the staff record, and it is
| worth saying so plainly: five empty tabs would read as "you have no
| attendance" when the truth is "we cannot tell which employee you are".
*/
if (!$me) {

    include("employee_header.php");
    reportStyles();
    ?>
    <div class="container-fluid py-1 rpt-page">
        <div class="rpt-head">
            <div>
                <h1 class="rpt-title">My Reports</h1>
                <p class="rpt-blurb">Your attendance, leave and payslips.</p>
            </div>
        </div>
        <div class="card rpt-card">
            <div class="card-body">
                <div class="rpt-empty">
                    <i class="bi bi-person-exclamation"></i>
                    <p class="rpt-empty-title">Your login is not linked to a staff record yet</p>
                    <p class="rpt-empty-text">
                        Your reports are built from your employee record, and this
                        account has not been attached to one.
                        <br>Ask HR to link your login to your employee profile, and
                        everything here will fill in.
                    </p>
                </div>
            </div>
        </div>
    </div>
    <?php
    include("employee_footer.php");
    exit;
}

$me = $me[0];
$employeeId = (int) $me['employee_id'];

$reports = [];


/* ===================================================================
 * 1. MY ATTENDANCE
 * =================================================================== */

$params = [$companyId, $employeeId];
$types = 'ii';
$clause = reportDateClause($range, 'a.attendance_date', $params, $types);

$attendanceRows = reportRows($conn, "
    SELECT a.attendance_date, a.time_in, a.time_out,
           a.late_minutes, a.overtime_hours, a.status
    FROM attendance a
    WHERE a.company_id = ? AND a.employee_id = ?$clause
    ORDER BY a.attendance_date DESC
", $params, $types);

$mix = [];
$lateMinutes = 0;
$overtimeHours = 0.0;
$present = 0;
$hoursWorked = 0.0;

foreach ($attendanceRows as $index => $row) {

    $label = $row['status'] !== '' ? $row['status'] : 'Unspecified';
    $mix[$label] = ($mix[$label] ?? 0) + 1;

    $lateMinutes += (int) $row['late_minutes'];
    $overtimeHours += (float) $row['overtime_hours'];

    if (in_array($row['status'], ['Present', 'Late', 'Half Day'], true)) {
        $present++;
    }

    /*
    | Hours on the clock, worked out from the two stamps - useful to the
    | person whose hours they are, and not stored as a column anywhere.
    |
    | time_in and time_out are DATETIME, carrying their own date. Prefixing
    | attendance_date onto them produced "2026-10-03 2026-10-03 08:22:00",
    | which strtotime refuses, and every row showed 0.00 hours.
    */
    $worked = null;

    if (reportHasDate($row['time_in']) && reportHasDate($row['time_out'])) {

        $in = strtotime((string) $row['time_in']);
        $out = strtotime((string) $row['time_out']);

        if ($in !== false && $out !== false && $out > $in) {
            $worked = round(($out - $in) / 3600, 2);
            $hoursWorked += $worked;
        }
    }

    $attendanceRows[$index]['hours'] = $worked;
}

$attendanceRate = count($attendanceRows) ? ($present / count($attendanceRows)) * 100 : 0;

$reports[] = [
    'id'      => 'attendance',
    'label'   => 'My Attendance',
    'icon'    => 'bi-calendar-check',
    'blurb'   => 'Every day logged against your record in this period.',
    'empty'   => 'No attendance was logged for you in this period.',
    'kpis'    => [
        ['label' => 'Days logged', 'value' => reportNumber(count($attendanceRows)),
         'icon' => 'bi-calendar3', 'tone' => 'primary'],
        ['label' => 'Attendance', 'value' => number_format($attendanceRate, 1) . '%',
         'icon' => 'bi-person-check', 'tone' => $attendanceRate >= 90 ? 'success' : 'warning',
         'hint' => $present . ' of ' . count($attendanceRows) . ' days'],
        ['label' => 'Hours on the clock', 'value' => reportNumber($hoursWorked, 1),
         'icon' => 'bi-stopwatch', 'tone' => 'info'],
        ['label' => 'Late', 'value' => reportNumber($lateMinutes) . ' min',
         'icon' => 'bi-clock-history', 'tone' => $lateMinutes ? 'warning' : 'success',
         'hint' => ($mix['Late'] ?? 0) . ' late arrival(s)'],
    ],
    'visuals' => [
        ['title' => 'How my days were logged', 'span' => 5,
         'body' => reportDonut($mix, [
             'Present' => '#198754', 'Late' => '#ffc107', 'Absent' => '#dc3545',
             'Half Day' => '#20c997', 'Leave' => '#0d6efd',
         ])],
        ['title' => 'My hours, day by day', 'span' => 7,
         'body' => reportBars(reportBucket($attendanceRows, 'attendance_date', 'hours'),
             'number', 'primary')],
    ],
    'columns' => [
        ['head' => 'Date',     'key' => 'attendance_date', 'type' => 'date'],
        ['head' => 'Time in',  'key' => 'time_in',         'type' => 'time'],
        ['head' => 'Time out', 'key' => 'time_out',        'type' => 'time'],
        ['head' => 'Hours',    'key' => 'hours',           'type' => 'number', 'places' => 2],
        ['head' => 'Late min', 'key' => 'late_minutes',    'type' => 'number'],
        ['head' => 'OT hrs',   'key' => 'overtime_hours',  'type' => 'number', 'places' => 1],
        ['head' => 'Status',   'key' => 'status',          'type' => 'badge'],
    ],
    'totals'  => [
        'hours'          => $hoursWorked,
        'late_minutes'   => $lateMinutes,
        'overtime_hours' => $overtimeHours,
    ],
    'rows'    => $attendanceRows,
];


/* ===================================================================
 * 2. MY LEAVE
 * =================================================================== */

$params = [$companyId, $employeeId];
$types = 'ii';
$clause = reportDateClause($range, 'l.created_at', $params, $types);

$leaveRows = reportRows($conn, "
    SELECT l.leave_type, l.duration, l.start_date, l.end_date, l.reason,
           l.hr_status, l.admin_status, l.hr_remarks, l.admin_remarks, l.created_at
    FROM leave_requests l
    WHERE l.company_id = ? AND l.employee_id = ?$clause
    ORDER BY l.created_at DESC
", $params, $types);

$leaveStages = [];
$approvedLeave = 0;
$pendingLeave = 0;

foreach ($leaveRows as $index => $row) {

    $hrStatus = (string) ($row['hr_status'] ?? '');
    $adminStatus = (string) ($row['admin_status'] ?? '');

    /*
    | Said as the person waiting for an answer would ask it.
    |
    | Two status columns showing "Pending" and "Pending" tell an employee
    | nothing about who is holding their request or whether it is still alive.
    */
    if ($hrStatus === 'Rejected') {
        $stage = 'Declined by HR';
    } elseif ($adminStatus === 'Rejected') {
        $stage = 'Declined by Admin';
    } elseif ($adminStatus === 'Approved') {
        $stage = 'Approved';
        $approvedLeave++;
    } elseif ($hrStatus === 'Approved') {
        $stage = 'Waiting for Admin';
        $pendingLeave++;
    } else {
        $stage = 'Waiting for HR';
        $pendingLeave++;
    }

    $leaveRows[$index]['stage'] = $stage;

    /* The reason it was declined, which is the thing the employee wants. */
    $note = '';
    if ($hrStatus === 'Rejected' && !empty($row['hr_remarks'])) {
        $note = $row['hr_remarks'];
    } elseif ($adminStatus === 'Rejected' && !empty($row['admin_remarks'])) {
        $note = $row['admin_remarks'];
    }
    $leaveRows[$index]['note'] = $note;

    $label = $row['leave_type'] !== '' ? $row['leave_type'] : 'Unspecified';
    $leaveStages[$label] = ($leaveStages[$label] ?? 0) + 1;
}

$reports[] = [
    'id'      => 'leave',
    'label'   => 'My Leave',
    'icon'    => 'bi-calendar-x',
    'blurb'   => 'Leave you filed in this period, and where each request stands.',
    'empty'   => 'You have not filed for leave in this period.',
    'kpis'    => [
        ['label' => 'Requests filed', 'value' => reportNumber(count($leaveRows)),
         'icon' => 'bi-calendar-x', 'tone' => 'primary'],
        ['label' => 'Approved', 'value' => reportNumber($approvedLeave),
         'icon' => 'bi-check2-circle', 'tone' => 'success'],
        ['label' => 'Still waiting', 'value' => reportNumber($pendingLeave),
         'icon' => 'bi-hourglass-split', 'tone' => $pendingLeave ? 'warning' : 'success'],
        ['label' => 'Declined',
         'value' => reportNumber(count($leaveRows) - $approvedLeave - $pendingLeave),
         'icon' => 'bi-x-circle', 'tone' => 'secondary'],
    ],
    'visuals' => [
        ['title' => 'Why I asked', 'span' => 7,
         'body' => reportBars($leaveStages, 'number', 'primary')],
        ['title' => 'Where my requests stand', 'span' => 5,
         'body' => reportDonut(array_count_values(array_column($leaveRows, 'stage')), [
             'Approved' => '#198754', 'Waiting for HR' => '#ffc107',
             'Waiting for Admin' => '#0dcaf0', 'Declined by HR' => '#dc3545',
             'Declined by Admin' => '#b02a37',
         ])],
    ],
    'columns' => [
        ['head' => 'Filed',    'key' => 'created_at', 'type' => 'datetime'],
        ['head' => 'Type',     'key' => 'leave_type'],
        ['head' => 'Duration', 'key' => 'duration'],
        ['head' => 'From',     'key' => 'start_date', 'type' => 'date'],
        ['head' => 'To',       'key' => 'end_date',   'type' => 'date'],
        ['head' => 'My reason', 'key' => 'reason',    'type' => 'wrap'],
        ['head' => 'Stage',    'key' => 'stage',      'type' => 'badge',
         'badges' => ['Approved' => 'success', 'Waiting for HR' => 'warning',
                      'Waiting for Admin' => 'info', 'Declined by HR' => 'danger',
                      'Declined by Admin' => 'danger']],
        ['head' => 'If declined, why', 'key' => 'note', 'type' => 'wrap'],
    ],
    'rows'    => $leaveRows,
];


/* ===================================================================
 * 3. MY OVERTIME
 * =================================================================== */

$params = [$companyId, $employeeId];
$types = 'ii';
$clause = reportDateClause($range, 'o.created_at', $params, $types);

$overtimeRows = reportRows($conn, "
    SELECT o.requested_hours, o.approved_hours, o.reason, o.status,
           o.rejection_reason, o.created_at
    FROM overtime_requests o
    WHERE o.company_id = ? AND o.employee_id = ?$clause
    ORDER BY o.created_at DESC
", $params, $types);

$otAsked = array_sum(array_column($overtimeRows, 'requested_hours'));
$otApproved = array_sum(array_column($overtimeRows, 'approved_hours'));
$otStanding = [];

foreach ($overtimeRows as $row) {
    $label = $row['status'] !== '' ? $row['status'] : 'Pending';
    $otStanding[$label] = ($otStanding[$label] ?? 0) + 1;
}

$reports[] = [
    'id'      => 'overtime',
    'label'   => 'My Overtime',
    'icon'    => 'bi-clock-history',
    'blurb'   => 'Overtime you filed, and how many hours were granted.',
    'empty'   => 'You have not filed for overtime in this period.',
    'kpis'    => [
        ['label' => 'Requests filed', 'value' => reportNumber(count($overtimeRows)),
         'icon' => 'bi-clock-history', 'tone' => 'primary'],
        ['label' => 'Hours I asked for', 'value' => reportNumber($otAsked, 1),
         'icon' => 'bi-hourglass', 'tone' => 'info'],
        ['label' => 'Hours granted', 'value' => reportNumber($otApproved, 1),
         'icon' => 'bi-check2-circle', 'tone' => 'success',
         'hint' => $otAsked > 0
             ? number_format(($otApproved / $otAsked) * 100, 0) . '% of what I asked'
             : null],
        ['label' => 'Still waiting', 'value' => reportNumber($otStanding['Pending'] ?? 0),
         'icon' => 'bi-hourglass-split',
         'tone' => ($otStanding['Pending'] ?? 0) ? 'warning' : 'success'],
    ],
    'visuals' => [
        ['title' => 'Where my requests stand', 'span' => 12,
         'body' => reportDonut($otStanding)],
    ],
    'columns' => [
        ['head' => 'Filed',     'key' => 'created_at',      'type' => 'datetime'],
        ['head' => 'I asked',   'key' => 'requested_hours', 'type' => 'number', 'places' => 1],
        ['head' => 'Granted',   'key' => 'approved_hours',  'type' => 'number', 'places' => 1],
        ['head' => 'My reason', 'key' => 'reason',          'type' => 'wrap'],
        ['head' => 'Status',    'key' => 'status',          'type' => 'badge'],
        ['head' => 'If declined, why', 'key' => 'rejection_reason', 'type' => 'wrap'],
    ],
    'totals'  => [
        'requested_hours' => $otAsked,
        'approved_hours'  => $otApproved,
    ],
    'rows'    => $overtimeRows,
];


/* ===================================================================
 * 4. MY UNDERTIME
 * =================================================================== */

$params = [$companyId, $employeeId];
$types = 'ii';
$clause = reportDateClause($range, 'u.created_at', $params, $types);

$undertimeRows = reportRows($conn, "
    SELECT u.request_date, u.hours, u.reason, u.status,
           u.rejection_reason, u.created_at
    FROM undertime_requests u
    WHERE u.company_id = ? AND u.employee_id = ?$clause
    ORDER BY u.created_at DESC
", $params, $types);

$utHours = array_sum(array_column($undertimeRows, 'hours'));
$utStanding = [];

foreach ($undertimeRows as $row) {
    $label = $row['status'] !== '' ? $row['status'] : 'Pending';
    $utStanding[$label] = ($utStanding[$label] ?? 0) + 1;
}

$reports[] = [
    'id'      => 'undertime',
    'label'   => 'My Undertime',
    'icon'    => 'bi-clock',
    'blurb'   => 'Times you asked to leave early, and what was decided.',
    'empty'   => 'You have not filed for undertime in this period.',
    'kpis'    => [
        ['label' => 'Requests filed', 'value' => reportNumber(count($undertimeRows)),
         'icon' => 'bi-clock', 'tone' => 'primary'],
        ['label' => 'Hours', 'value' => reportNumber($utHours, 1),
         'icon' => 'bi-hourglass', 'tone' => 'warning'],
        ['label' => 'Approved', 'value' => reportNumber($utStanding['Approved'] ?? 0),
         'icon' => 'bi-check2-circle', 'tone' => 'success'],
        ['label' => 'Still waiting', 'value' => reportNumber($utStanding['Pending'] ?? 0),
         'icon' => 'bi-hourglass-split',
         'tone' => ($utStanding['Pending'] ?? 0) ? 'warning' : 'success'],
    ],
    'visuals' => [
        ['title' => 'Where my requests stand', 'span' => 12,
         'body' => reportDonut($utStanding)],
    ],
    'columns' => [
        ['head' => 'Filed',     'key' => 'created_at',   'type' => 'datetime'],
        ['head' => 'For',       'key' => 'request_date', 'type' => 'date'],
        ['head' => 'Hours',     'key' => 'hours',        'type' => 'number', 'places' => 1],
        ['head' => 'My reason', 'key' => 'reason',       'type' => 'wrap'],
        ['head' => 'Status',    'key' => 'status',       'type' => 'badge'],
        ['head' => 'If declined, why', 'key' => 'rejection_reason', 'type' => 'wrap'],
    ],
    'totals'  => ['hours' => $utHours],
    'rows'    => $undertimeRows,
];


/* ===================================================================
 * 5. MY PAYSLIPS
 * =================================================================== */

$params = [$companyId, $employeeId];
$types = 'ii';
$clause = reportDateClause($range, 'p.payroll_period_end', $params, $types);

$payrollRows = reportRows($conn, "
    SELECT p.payroll_period_start, p.payroll_period_end, p.working_days,
           p.basic_pay, p.overtime_pay, p.gross_pay,
           p.late_deduction, p.undertime_deduction, p.absent_deduction,
           p.sss, p.philhealth, p.pagibig,
           p.total_deduction, p.net_pay, p.status
    FROM payroll p
    WHERE p.company_id = ? AND p.employee_id = ?$clause
    ORDER BY p.payroll_period_end DESC
", $params, $types);

$takeHome = array_sum(array_column($payrollRows, 'net_pay'));
$deducted = array_sum(array_column($payrollRows, 'total_deduction'));

$byPeriod = [];
foreach ($payrollRows as $row) {
    $byPeriod[reportWhen($row['payroll_period_end'])] = (float) $row['net_pay'];
}

$reports[] = [
    'id'      => 'payslips',
    'label'   => 'My Payslips',
    'icon'    => 'bi-cash-stack',
    'blurb'   => 'Your own pay, period by period, with every deduction itemised.',
    'empty'   => 'No payslip of yours falls in this period.',
    'kpis'    => [
        ['label' => 'Take-home pay', 'value' => reportPeso($takeHome),
         'icon' => 'bi-wallet2', 'tone' => 'success'],
        ['label' => 'Payslips', 'value' => reportNumber(count($payrollRows)),
         'icon' => 'bi-file-earmark-text', 'tone' => 'primary'],
        ['label' => 'Deducted', 'value' => reportPeso($deducted),
         'icon' => 'bi-dash-circle', 'tone' => 'warning'],
        ['label' => 'Average per period',
         'value' => reportPeso(count($payrollRows) ? $takeHome / count($payrollRows) : 0),
         'icon' => 'bi-calculator', 'tone' => 'info'],
    ],
    'visuals' => [
        ['title' => 'My take-home pay by period', 'span' => 7,
         'body' => reportBars(array_slice($byPeriod, 0, 12, true), 'peso', 'success')],
        ['title' => 'What was deducted', 'span' => 5,
         'body' => reportDonut([
             'SSS'        => array_sum(array_column($payrollRows, 'sss')),
             'PhilHealth' => array_sum(array_column($payrollRows, 'philhealth')),
             'Pag-IBIG'   => array_sum(array_column($payrollRows, 'pagibig')),
             'Late'       => array_sum(array_column($payrollRows, 'late_deduction')),
             'Undertime'  => array_sum(array_column($payrollRows, 'undertime_deduction')),
             'Absences'   => array_sum(array_column($payrollRows, 'absent_deduction')),
         ])],
    ],
    'columns' => [
        ['head' => 'Period from', 'key' => 'payroll_period_start', 'type' => 'date'],
        ['head' => 'Period to',   'key' => 'payroll_period_end',   'type' => 'date'],
        ['head' => 'Days',        'key' => 'working_days',       'type' => 'number'],
        ['head' => 'Basic',       'key' => 'basic_pay',          'type' => 'peso'],
        ['head' => 'Overtime',    'key' => 'overtime_pay',       'type' => 'peso'],
        ['head' => 'Gross',       'key' => 'gross_pay',          'type' => 'peso'],
        ['head' => 'Late',        'key' => 'late_deduction',     'type' => 'peso'],
        ['head' => 'Undertime',   'key' => 'undertime_deduction', 'type' => 'peso'],
        ['head' => 'Absences',    'key' => 'absent_deduction',   'type' => 'peso'],
        ['head' => 'SSS',         'key' => 'sss',                'type' => 'peso'],
        ['head' => 'PhilHealth',  'key' => 'philhealth',         'type' => 'peso'],
        ['head' => 'Pag-IBIG',    'key' => 'pagibig',            'type' => 'peso'],
        ['head' => 'Deductions',  'key' => 'total_deduction',    'type' => 'peso'],
        ['head' => 'Take home',   'key' => 'net_pay',            'type' => 'peso'],
        ['head' => 'Status',      'key' => 'status',             'type' => 'badge'],
    ],
    'totals'  => [
        'gross_pay'       => array_sum(array_column($payrollRows, 'gross_pay')),
        'total_deduction' => $deducted,
        'net_pay'         => $takeHome,
    ],
    'rows'    => $payrollRows,
];


if (!empty($_GET['export'])) {
    reportExportCsv($reports, (string) $_GET['export'], $range, $companyName);
}

include("employee_header.php");

$who = $me['employee'];
if (!empty($me['job_title'])) {
    $who .= ' - ' . $me['job_title'];
}
if (!empty($me['branch_name'])) {
    $who .= ', ' . $me['branch_name'];
}

renderReportsPage([
    'title'   => 'My Reports',
    'blurb'   => $who,
    'range'   => $range,
    'note'    => 'These are your own records only. Nobody else\'s attendance or pay appears here, and nothing here is visible to your colleagues.',
    'reports' => $reports,
]);

include("employee_footer.php");
