<?php

/*
 * The shared engine behind every role's Reports page.
 *
 * WHY THIS EXISTS
 *   Reports used to be three hand-written pages - admin (502 lines), hr (818)
 *   and cashier (391) - and admin and hr were near-copies of each other, the
 *   same seven HR tables rendered twice. Three roles had no page at all:
 *   finance, inventory and employee sidebars all pointed at admin/reports.php,
 *   which opens with requireRole(['admin']), so clicking Reports bounced them
 *   straight back to their own dashboard. Six roles, three pages, two of them
 *   duplicates, three links that could never work.
 *
 *   So a role page here does not write HTML or build a filter form. It declares
 *   what its reports ARE - a label, some KPIs, a column spec, rows - and this
 *   file renders, filters, totals, exports and prints them the same way for
 *   everyone. Adding a report is adding an array entry.
 *
 * WHAT A ROLE PAGE OWES
 *   Only the queries. Every one of them must carry company_id: this file
 *   cannot add that for you, and reportBranchOptions() is here precisely
 *   because the hand-written version in hr/reports.php forgot it and listed
 *   every company's branches in the filter.
 *
 * NO CHART LIBRARY
 *   The visuals are CSS bars and one inline SVG donut. cashier/reports.php
 *   pulls Chart.js off a CDN, which means its charts vanish when the laptop
 *   running this XAMPP has no internet - and a <canvas> prints badly even when
 *   it loads. Server-rendered markup always draws and always prints.
 */

if (!defined('REPORT_KIT')) {
    define('REPORT_KIT', '1.0');


    /* ===================================================================
     * DATE RANGE
     * =================================================================== */

    /*
    | The presets people actually ask for, in the order they ask for them.
    |
    | A reports page whose only control is two empty date boxes makes the
    | commonest question - "how did we do this month" - into typing.
    */
    function reportPresets(): array
    {
        return [
            'today'      => 'Today',
            'yesterday'  => 'Yesterday',
            '7days'      => 'Last 7 days',
            '30days'     => 'Last 30 days',
            'month'      => 'This month',
            'lastmonth'  => 'Last month',
            'year'       => 'This year',
            'all'        => 'All time',
            'custom'     => 'Custom range',
        ];
    }

    /*
    | Resolves whatever is in the query string into a usable from/to pair.
    |
    | Returns 'from' and 'to' as Y-m-d strings, or null for an open end. The
    | caller never parses a date itself, so a report cannot disagree with the
    | heading above it about which days it covers.
    */
    function reportRange(): array
    {
        $presets = reportPresets();

        $id = (string) ($_GET['range'] ?? '30days');
        if (!isset($presets[$id])) {
            $id = '30days';
        }

        $today = new DateTimeImmutable('today');
        $from = null;
        $to = null;

        switch ($id) {

            case 'today':
                $from = $to = $today;
                break;

            case 'yesterday':
                $from = $to = $today->modify('-1 day');
                break;

            case '7days':
                $from = $today->modify('-6 days');
                $to = $today;
                break;

            case '30days':
                $from = $today->modify('-29 days');
                $to = $today;
                break;

            case 'month':
                $from = $today->modify('first day of this month');
                $to = $today;
                break;

            case 'lastmonth':
                $from = $today->modify('first day of last month');
                $to = $today->modify('last day of last month');
                break;

            case 'year':
                $from = $today->modify('first day of January');
                $to = $today;
                break;

            case 'all':
                break;

            case 'custom':
                $from = reportParseDate($_GET['date_from'] ?? '');
                $to = reportParseDate($_GET['date_to'] ?? '');

                /* Backwards dates are a slip, not a request for no rows. */
                if ($from && $to && $from > $to) {
                    $swap = $from;
                    $from = $to;
                    $to = $swap;
                }
                break;
        }

        return [
            'id'    => $id,
            'label' => $presets[$id],
            'from'  => $from ? $from->format('Y-m-d') : null,
            'to'    => $to ? $to->format('Y-m-d') : null,
        ];
    }

    /*
    | A date only counts if it is real. "2026-02-31" parses in strtotime and
    | quietly becomes March 3rd, which would make a report cover days its
    | heading says it does not.
    */
    function reportParseDate(string $value): ?DateTimeImmutable
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if (!$parsed || $parsed->format('Y-m-d') !== $value) {
            return null;
        }

        return $parsed;
    }

    /*
    | Appends "AND <column> BETWEEN ..." for whichever ends of the range are
    | set, growing the bind lists as it goes.
    |
    | Every report filters by date and every one of them got it slightly
    | wrong on its own before - some compared a DATETIME column to a date
    | without DATE(), so rows timestamped in the afternoon of the last day
    | fell outside "up to today".
    */
    function reportDateClause(array $range, string $column, array &$params, string &$types): string
    {
        $sql = '';

        if ($range['from'] !== null) {
            $sql .= " AND DATE($column) >= ?";
            $params[] = $range['from'];
            $types .= 's';
        }

        if ($range['to'] !== null) {
            $sql .= " AND DATE($column) <= ?";
            $params[] = $range['to'];
            $types .= 's';
        }

        return $sql;
    }

    /* The range spelled out, for the heading and the printed page. */
    function reportRangeText(array $range): string
    {
        if ($range['from'] === null && $range['to'] === null) {
            return 'All records to date';
        }

        if ($range['from'] !== null && $range['from'] === $range['to']) {
            return reportWhen($range['from']);
        }

        if ($range['from'] === null) {
            return 'Up to ' . reportWhen($range['to']);
        }

        if ($range['to'] === null) {
            return reportWhen($range['from']) . ' onwards';
        }

        return reportWhen($range['from']) . ' to ' . reportWhen($range['to']);
    }


    /* ===================================================================
     * QUERYING
     * =================================================================== */

    /*
    | Runs a scoped query and hands back plain arrays.
    |
    | Arrays rather than a mysqli_result because every report is consumed
    | twice - once as HTML, once as CSV - and a result set can only be walked
    | once. The old pages could not export for exactly this reason.
    */
    function reportRows(mysqli $conn, string $sql, array $params = [], string $types = ''): array
    {
        $stmt = $conn->prepare($sql);

        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }

        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $rows;
    }

    /* One number out of one row, with a fallback when there are no rows. */
    function reportValue(mysqli $conn, string $sql, array $params = [], string $types = '', $default = 0)
    {
        $rows = reportRows($conn, $sql, $params, $types);

        if (!$rows) {
            return $default;
        }

        $first = reset($rows[0]);

        return $first === null ? $default : $first;
    }

    /*
    | The branch filter's options, scoped.
    |
    | hr/reports.php built this list with "SELECT branch_id, branch_name FROM
    | branch ORDER BY branch_name" and no company_id, so HR saw the branch
    | names of every other business on the platform - and picking one returned
    | nothing, because the reports themselves ARE scoped. A leak and a dead
    | filter from one missing clause.
    */
    function reportBranchOptions(mysqli $conn, int $companyId): array
    {
        return reportRows(
            $conn,
            "SELECT branch_id, branch_name
             FROM branch
             WHERE company_id = ?
             ORDER BY branch_name ASC",
            [$companyId],
            'i'
        );
    }

    /* The branch picked, as an int, or 0 for "all". */
    function reportBranchFilter(): int
    {
        return (int) ($_GET['branch'] ?? 0);
    }


    /* ===================================================================
     * FORMATTING
     * =================================================================== */

    /*
    | The minus goes in front of the peso sign, not between it and the digits.
    | number_format puts it where the number is, which renders a cost line on
    | the profit and loss statement as "P-18,100.00" - readable as a negative
    | only on a second look, on the one page where every reader is scanning
    | for the sign.
    */
    function reportPeso($amount): string
    {
        $amount = round((float) $amount, 2);

        /* round() first, or a value of -0.004 prints as "-P0.00" - a negative
           zero, which reads as a mistake in the report. */
        return ($amount < 0 ? '-' : '') . '&#8369;' . number_format(abs($amount), 2);
    }

    function reportNumber($value, int $places = 0): string
    {
        return number_format((float) $value, $places);
    }

    /*
    | Is there really a date here?
    |
    | Shared by reportWhen and reportClock, because they had this test
    | separately and reportClock's was weaker: it only checked empty(), so a
    | MySQL zero date reached strtotime and came back as "12:00 AM". An
    | attendance row with no clock-in then read as though the person had
    | arrived at midnight - a plausible wrong answer, which is worse than a
    | dash. attendance.time_in is a DATETIME and NO_ZERO_DATE is only a
    | warning outside strict mode, so zero dates are genuinely in there.
    */
    function reportHasDate($value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        $value = trim((string) $value);

        return $value !== ''
            && $value !== '0000-00-00'
            && $value !== '0000-00-00 00:00:00'
            && strncmp($value, '0000-00-00', 10) !== 0;
    }

    /* A date the way a Filipino owner reads one, and a dash when absent. */
    function reportWhen($value, bool $withTime = false): string
    {
        if (!reportHasDate($value)) {
            return '&mdash;';
        }

        $stamp = strtotime((string) $value);

        if ($stamp === false) {
            return htmlspecialchars((string) $value);
        }

        return date($withTime ? 'M d, Y g:i A' : 'M d, Y', $stamp);
    }

    function reportClock($value): string
    {
        if (!reportHasDate($value)) {
            return '&mdash;';
        }

        $stamp = strtotime((string) $value);

        return $stamp === false ? htmlspecialchars((string) $value) : date('g:i A', $stamp);
    }

    /*
    | A status pill. Unknown values still render, in grey, with the text
    | intact - a report must never swallow a status it was not told about.
    */
    function reportBadge($value, array $map = []): string
    {
        $value = (string) ($value ?? '');

        if ($value === '') {
            return '<span class="text-muted">&mdash;</span>';
        }

        $tone = $map[$value] ?? 'secondary';
        $dark = in_array($tone, ['warning', 'info', 'light'], true) ? ' text-dark' : '';

        return '<span class="badge bg-' . $tone . $dark . '">'
            . htmlspecialchars($value) . '</span>';
    }

    /* The tones used across every report, so a status looks the same everywhere. */
    function reportTones(): array
    {
        return [
            'Paid' => 'success', 'Unpaid' => 'danger', 'Partial' => 'warning',
            'Overdue' => 'danger', 'Pending' => 'warning', 'Approved' => 'success',
            'Rejected' => 'danger', 'Cancelled' => 'secondary', 'Completed' => 'success',
            'Received' => 'success', 'Active' => 'success', 'Inactive' => 'secondary',
            'Present' => 'success', 'Late' => 'warning', 'Absent' => 'danger',
            'Half Day' => 'info', 'Leave' => 'primary', 'On Leave' => 'primary',
            'Hired' => 'success', 'Interview' => 'primary', 'Recommended' => 'info',
            'Not Recommended' => 'danger', 'Regular' => 'success',
            'Probationary' => 'warning', 'Contractual' => 'info', 'Part-Time' => 'secondary',
            'Official Employee' => 'success', 'Pre-Employee' => 'warning',
            'Resigned' => 'danger', 'Terminated' => 'dark',
            'Pending Finance' => 'warning', 'Pending Admin' => 'warning',
            'Finance Approved' => 'info', 'Admin Approved' => 'info',
            'Cash' => 'success', 'GCash' => 'primary', 'Utang' => 'warning',
        ];
    }

    /*
    | Turns one row and one column spec into the cell's HTML.
    |
    | Shared with the CSV writer through reportCell()'s plain-text twin below,
    | so an exported column can never drift from the one on screen.
    */
    function reportCell(array $row, array $col): string
    {
        $value = $row[$col['key']] ?? null;

        switch ($col['type'] ?? 'text') {

            case 'peso':
                return reportPeso($value);

            /*
            | A null number is unknown, not zero.
            |
            | An absent day has no hours on the clock and an unsold product has
            | no days of cover; both came out as "0", which reads as a measured
            | zero. A real 0 still prints as 0.
            */
            case 'number':
                return $value === null || $value === ''
                    ? '<span class="text-muted">&mdash;</span>'
                    : reportNumber($value, $col['places'] ?? 0);

            case 'date':
                return reportWhen($value);

            case 'datetime':
                return reportWhen($value, true);

            case 'time':
                return reportClock($value);

            case 'badge':
                return reportBadge($value, ($col['badges'] ?? []) + reportTones());

            case 'raw':
                return (string) $value;

            case 'wrap':
                if ($value === null || $value === '') {
                    return '<span class="text-muted">&mdash;</span>';
                }
                return nl2br(htmlspecialchars((string) $value));

            default:
                if ($value === null || $value === '') {
                    return '<span class="text-muted">&mdash;</span>';
                }
                return htmlspecialchars((string) $value);
        }
    }

    /* The same cell as text, for CSV: no markup, no entities, no pesos sign. */
    function reportCellText(array $row, array $col): string
    {
        $value = $row[$col['key']] ?? null;

        switch ($col['type'] ?? 'text') {

            case 'peso':
                return number_format((float) $value, 2, '.', '');

            /* Empty, not 0: the screen shows a dash for an unknown number, and
               a spreadsheet that averages a column must not count it as a
               measured zero. */
            case 'number':
                return $value === null || $value === ''
                    ? ''
                    : number_format((float) $value, $col['places'] ?? 0, '.', '');

            /* reportHasDate, not a truthiness test: "0000-00-00 00:00:00" is
               truthy and would be exported as 1970 or as midnight. */
            case 'date':
                return reportHasDate($value) ? date('Y-m-d', strtotime((string) $value)) : '';

            case 'datetime':
                return reportHasDate($value) ? date('Y-m-d H:i', strtotime((string) $value)) : '';

            case 'time':
                return reportHasDate($value) ? date('H:i', strtotime((string) $value)) : '';

            case 'raw':
                return trim(html_entity_decode(strip_tags((string) $value), ENT_QUOTES, 'UTF-8'));

            default:
                return (string) ($value ?? '');
        }
    }


    /* ===================================================================
     * CSV EXPORT
     * =================================================================== */

    /*
    | Streams one report as CSV and stops the request.
    |
    | Called before any header include, because a single byte of HTML before
    | these headers turns the download into a broken file. The role pages all
    | build their reports first and render second for this reason.
    |
    | The BOM is there so Excel on a Philippine Windows box opens the file as
    | UTF-8 instead of mangling every n-tilde in a supplier or employee name.
    */
    function reportExportCsv(array $reports, string $id, array $range, string $companyName): void
    {
        $report = null;

        foreach ($reports as $candidate) {
            if ($candidate['id'] === $id) {
                $report = $candidate;
                break;
            }
        }

        if ($report === null) {
            http_response_code(404);
            exit('No such report.');
        }

        $slug = preg_replace('/[^A-Za-z0-9]+/', '_', $companyName . ' ' . $report['label']);
        $slug = trim((string) $slug, '_');
        $name = $slug . '_' . date('Ymd') . '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Cache-Control: no-store');

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");

        /* Who, what and when, so a file found later still explains itself. */
        fputcsv($out, [$companyName]);
        fputcsv($out, [$report['label'] . ' report']);
        fputcsv($out, ['Period', strip_tags(reportRangeText($range))]);
        fputcsv($out, ['Generated', date('Y-m-d H:i')]);
        fputcsv($out, []);

        $columns = $report['columns'];

        fputcsv($out, array_map(static function (array $col) {
            return $col['head'];
        }, $columns));

        foreach ($report['rows'] as $row) {
            fputcsv($out, array_map(static function (array $col) use ($row) {
                return reportCellText($row, $col);
            }, $columns));
        }

        /* The totals belong in the file too, or the numbers have to be redone. */
        if (!empty($report['totals'])) {
            fputcsv($out, []);
            $line = [];
            foreach ($columns as $index => $col) {
                if (isset($report['totals'][$col['key']])) {
                    $line[] = reportCellText($report['totals'], $col);
                } else {
                    $line[] = $index === 0 ? 'TOTAL' : '';
                }
            }
            fputcsv($out, $line);
        }

        fclose($out);
        exit;
    }


    /* ===================================================================
     * VISUALS
     * =================================================================== */

    /*
    | Rolls rows up into chart buckets by date, picking the grain from how
    | much ground the range covers.
    |
    | A year's worth of sales is 365 bars nobody can read, so past a fortnight
    | the buckets become months. The grain is chosen from the data rather than
    | from the preset, because a custom range can be any length at all.
    */
    function reportBucket(array $rows, string $dateKey, string $valueKey, int $maxBars = 14): array
    {
        $days = [];

        foreach ($rows as $row) {

            if (empty($row[$dateKey])) {
                continue;
            }

            $stamp = strtotime((string) $row[$dateKey]);

            if ($stamp === false) {
                continue;
            }

            $day = date('Y-m-d', $stamp);
            $days[$day] = ($days[$day] ?? 0) + (float) ($row[$valueKey] ?? 0);
        }

        if (!$days) {
            return [];
        }

        ksort($days);

        if (count($days) <= $maxBars) {
            $out = [];
            foreach ($days as $day => $value) {
                $out[date('M d', strtotime($day))] = $value;
            }
            return $out;
        }

        /* Too many days: group by month, and if even the months overflow,
           keep the most recent ones - the old end of a long range is the
           part nobody is looking at. */
        $months = [];

        foreach ($days as $day => $value) {
            $key = date('M Y', strtotime($day));
            $months[$key] = ($months[$key] ?? 0) + $value;
        }

        if (count($months) > $maxBars) {
            $months = array_slice($months, -$maxBars, null, true);
        }

        return $months;
    }

    /*
    | A horizontal bar list, in CSS.
    |
    | $data is label => number. Bars rather than a chart library: these scale
    | to any width, survive a laptop with no internet, and come out of the
    | printer looking like what was on screen.
    */
    function reportBars(array $data, string $format = 'number', string $tone = 'primary'): string
    {
        $data = array_filter($data, static function ($value) {
            return (float) $value != 0.0;
        });

        if (!$data) {
            return '<p class="text-muted small mb-0">Nothing to chart for this period.</p>';
        }

        $max = max(array_map('abs', array_map('floatval', $data)));
        $html = '<div class="rpt-bars">';

        foreach ($data as $label => $value) {

            $width = $max > 0 ? max(2, (abs((float) $value) / $max) * 100) : 2;
            $shown = $format === 'peso' ? reportPeso($value) : reportNumber($value);

            $html .= '<div class="rpt-bar-row">'
                . '<div class="rpt-bar-label" title="' . htmlspecialchars((string) $label) . '">'
                . htmlspecialchars((string) $label) . '</div>'
                . '<div class="rpt-bar-track">'
                . '<div class="rpt-bar-fill bg-' . $tone . '" style="width:'
                . number_format($width, 2, '.', '') . '%"></div>'
                . '</div>'
                . '<div class="rpt-bar-value">' . $shown . '</div>'
                . '</div>';
        }

        return $html . '</div>';
    }

    /*
    | A donut, as inline SVG, built from stroke-dasharray arcs.
    |
    | $data is label => count. Used where the question is "what share" -
    | payment mix, utang standing - and a bar list reads worse than a ring.
    */
    function reportDonut(array $data, array $tones = []): string
    {
        $data = array_filter($data, static function ($value) {
            return (float) $value > 0;
        });

        if (!$data) {
            return '<p class="text-muted small mb-0">Nothing to chart for this period.</p>';
        }

        $palette = ['#0d6efd', '#198754', '#ffc107', '#dc3545', '#6f42c1', '#20c997', '#fd7e14'];
        $total = array_sum($data);

        /* r chosen so the circumference is a round 100: each slice's
           dasharray is then just its percentage, with no arithmetic. */
        $radius = 15.9154943092;
        $offset = 25.0;

        $arcs = '';
        $legend = '';
        $index = 0;

        foreach ($data as $label => $value) {

            $share = ($value / $total) * 100;
            $colour = $tones[$label] ?? $palette[$index % count($palette)];

            $arcs .= '<circle class="rpt-donut-arc" cx="21" cy="21" r="' . $radius . '"'
                . ' fill="transparent" stroke="' . $colour . '" stroke-width="5"'
                . ' stroke-dasharray="' . number_format($share, 3, '.', '') . ' '
                . number_format(100 - $share, 3, '.', '') . '"'
                . ' stroke-dashoffset="' . number_format($offset, 3, '.', '') . '"></circle>';

            $legend .= '<li><span class="rpt-dot" style="background:' . $colour . '"></span>'
                . '<span class="rpt-legend-label">' . htmlspecialchars((string) $label) . '</span>'
                . '<span class="rpt-legend-value">' . reportNumber($value)
                . ' <small class="text-muted">(' . number_format($share, 1) . '%)</small></span></li>';

            /* Each arc starts where the last one ended, counter-clockwise
               from twelve o'clock. */
            $offset -= $share;
            if ($offset < 0) {
                $offset += 100;
            }

            $index++;
        }

        return '<div class="rpt-donut-wrap">'
            . '<svg viewBox="0 0 42 42" class="rpt-donut" role="img" aria-label="Share of total">'
            . '<circle cx="21" cy="21" r="' . $radius . '" fill="transparent"'
            . ' stroke="#eef1f5" stroke-width="5"></circle>'
            . $arcs
            . '</svg>'
            . '<ul class="rpt-legend">' . $legend . '</ul>'
            . '</div>';
    }


    /* ===================================================================
     * RENDERING
     * =================================================================== */

    /*
    | Renders the whole page: heading, filter bar, tabs, and for each report
    | its KPIs, visual and table.
    |
    | $config keys:
    |   title    heading
    |   blurb    one line under it
    |   range    the array from reportRange()
    |   branches optional - the options from reportBranchOptions(); a branch
    |            picker only appears when the reports can actually honour it
    |   note     optional caveat shown under the filter bar
    |   reports  the report definitions
    */
    function renderReportsPage(array $config): void
    {
        $range = $config['range'];
        $reports = $config['reports'];
        $branches = $config['branches'] ?? [];
        $branchId = reportBranchFilter();

        reportStyles();
        ?>

        <div class="container-fluid py-1 rpt-page">

            <div class="rpt-head">
                <div>
                    <h1 class="rpt-title"><?= htmlspecialchars($config['title'] ?? 'Reports') ?></h1>
                    <p class="rpt-blurb"><?= htmlspecialchars($config['blurb'] ?? '') ?></p>
                </div>
                <div class="rpt-head-actions">
                    <span class="rpt-period">
                        <i class="bi bi-calendar3"></i>
                        <?= reportRangeText($range) ?>
                    </span>
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="rptPrint">
                        <i class="bi bi-printer"></i> Print
                    </button>
                    <a class="btn btn-sm text-white rpt-export" id="rptExport"
                       href="#" style="background:#00224C;">
                        <i class="bi bi-filetype-csv"></i> Export CSV
                    </a>
                </div>
            </div>

            <!-- FILTERS -->
            <div class="card rpt-card rpt-filter mb-3">
                <div class="card-body">
                    <form method="GET" id="rptFilter">
                        <div class="row g-3 align-items-end">

                            <div class="col-lg-3 col-md-4">
                                <label class="form-label">Period</label>
                                <select class="form-select" name="range" id="rptRange">
                                    <?php foreach (reportPresets() as $id => $label) { ?>
                                        <option value="<?= $id ?>"
                                            <?= $range['id'] === $id ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($label) ?>
                                        </option>
                                    <?php } ?>
                                </select>
                            </div>

                            <div class="col-lg-2 col-md-4 rpt-custom">
                                <label class="form-label">From</label>
                                <input type="date" class="form-control" name="date_from"
                                       value="<?= htmlspecialchars($_GET['date_from'] ?? '') ?>">
                            </div>

                            <div class="col-lg-2 col-md-4 rpt-custom">
                                <label class="form-label">To</label>
                                <input type="date" class="form-control" name="date_to"
                                       value="<?= htmlspecialchars($_GET['date_to'] ?? '') ?>">
                            </div>

                            <?php if ($branches) { ?>
                                <div class="col-lg-3 col-md-6">
                                    <label class="form-label">Branch</label>
                                    <select class="form-select" name="branch">
                                        <option value="0">All branches</option>
                                        <?php foreach ($branches as $b) { ?>
                                            <option value="<?= (int) $b['branch_id'] ?>"
                                                <?= $branchId === (int) $b['branch_id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($b['branch_name']) ?>
                                            </option>
                                        <?php } ?>
                                    </select>
                                </div>
                            <?php } ?>

                            <div class="col-lg-2 col-md-6">
                                <button class="btn w-100 text-white" style="background:#00224C;">
                                    <i class="bi bi-arrow-repeat"></i> Apply
                                </button>
                            </div>
                        </div>

                        <?php if (!empty($config['note'])) { ?>
                            <p class="rpt-note">
                                <i class="bi bi-info-circle"></i>
                                <?= htmlspecialchars($config['note']) ?>
                            </p>
                        <?php } ?>
                    </form>
                </div>
            </div>

            <!-- TABS -->
            <ul class="nav rpt-tabs" id="rptTabs" role="tablist">
                <?php foreach ($reports as $i => $report) { ?>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link <?= $i === 0 ? 'active' : '' ?>"
                                data-bs-toggle="tab"
                                data-report="<?= htmlspecialchars($report['id']) ?>"
                                data-bs-target="#rpt-<?= htmlspecialchars($report['id']) ?>"
                                type="button" role="tab">
                            <i class="bi <?= htmlspecialchars($report['icon'] ?? 'bi-table') ?>"></i>
                            <?= htmlspecialchars($report['label']) ?>
                            <span class="rpt-count"><?= count($report['rows']) ?></span>
                        </button>
                    </li>
                <?php } ?>
            </ul>

            <div class="tab-content">
                <?php foreach ($reports as $i => $report) { ?>
                    <div class="tab-pane fade <?= $i === 0 ? 'show active' : '' ?>"
                         id="rpt-<?= htmlspecialchars($report['id']) ?>" role="tabpanel">
                        <?php renderReport($report, $range); ?>
                    </div>
                <?php } ?>
            </div>
        </div>

        <?php
        reportScripts($reports);
    }

    /* One report: its KPI strip, its visual, its table. */
    function renderReport(array $report, array $range): void
    {
        ?>
        <!-- Only shown on paper, where the browser prints no heading of its own. -->
        <div class="rpt-print-head">
            <strong><?= htmlspecialchars($report['label']) ?> report</strong>
            <span><?= reportRangeText($range) ?></span>
        </div>

        <?php if (!empty($report['kpis'])) { ?>
            <div class="rpt-kpis">
                <?php foreach ($report['kpis'] as $kpi) { ?>
                    <div class="rpt-kpi">
                        <div class="rpt-kpi-icon bg-<?= htmlspecialchars($kpi['tone'] ?? 'primary') ?>-subtle
                                    text-<?= htmlspecialchars($kpi['tone'] ?? 'primary') ?>">
                            <i class="bi <?= htmlspecialchars($kpi['icon'] ?? 'bi-graph-up') ?>"></i>
                        </div>
                        <div class="rpt-kpi-body">
                            <span class="rpt-kpi-label"><?= htmlspecialchars($kpi['label']) ?></span>
                            <span class="rpt-kpi-value"><?= $kpi['value'] ?></span>
                            <?php if (!empty($kpi['hint'])) { ?>
                                <span class="rpt-kpi-hint"><?= htmlspecialchars($kpi['hint']) ?></span>
                            <?php } ?>
                        </div>
                    </div>
                <?php } ?>
            </div>
        <?php } ?>

        <?php if (!empty($report['visuals'])) { ?>
            <div class="row g-3 mb-3">
                <?php foreach ($report['visuals'] as $visual) { ?>
                    <div class="col-lg-<?= (int) ($visual['span'] ?? 6) ?>">
                        <div class="card rpt-card h-100">
                            <div class="card-body">
                                <h2 class="rpt-panel-title"><?= htmlspecialchars($visual['title']) ?></h2>
                                <?= $visual['body'] ?>
                            </div>
                        </div>
                    </div>
                <?php } ?>
            </div>
        <?php } ?>

        <div class="card rpt-card">
            <div class="card-body">

                <div class="rpt-panel-head">
                    <div>
                        <h2 class="rpt-panel-title"><?= htmlspecialchars($report['label']) ?></h2>
                        <?php if (!empty($report['blurb'])) { ?>
                            <p class="rpt-panel-blurb"><?= htmlspecialchars($report['blurb']) ?></p>
                        <?php } ?>
                    </div>
                </div>

                <?php if (!$report['rows']) { ?>

                    <!-- An empty report has to say WHY it is empty. A bare
                         "No data available" leaves the reader unsure whether
                         the business had a quiet month or the page is broken. -->
                    <?php
                    /*
                    | A report that ignores the period must not blame the
                    | period for being empty.
                    |
                    | Stock, Workforce and Suppliers show today's position, and
                    | their blurbs say so - but the empty state read "Nothing in
                    | Stock for this period / Covering Sep 04 to Oct 03", which
                    | flatly contradicts that and invites the reader to widen a
                    | range that was never being applied.
                    */
                    $timeless = !empty($report['timeless']);
                    ?>
                    <div class="rpt-empty">
                        <i class="bi <?= htmlspecialchars($report['icon'] ?? 'bi-inbox') ?>"></i>
                        <!-- Phrased around the label rather than "No <label>
                             records", which reads as "No requests records" for
                             any tab whose name is already plural. -->
                        <p class="rpt-empty-title">
                            Nothing in <?= htmlspecialchars($report['label']) ?>
                            <?= $timeless ? 'yet' : 'for this period' ?>
                        </p>
                        <p class="rpt-empty-text">
                            <?= htmlspecialchars($report['empty'] ?? 'Nothing was recorded here yet.') ?>
                            <?php if ($timeless) { ?>
                                <br>This report shows today's position, so the period above
                                would not change it.
                            <?php } else { ?>
                                <br>Covering <?= reportRangeText($range) ?>.
                            <?php } ?>
                        </p>
                    </div>

                <?php } else { ?>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle rpt-table"
                               id="rptTable-<?= htmlspecialchars($report['id']) ?>" style="width:100%">
                            <thead>
                                <tr>
                                    <th class="rpt-rownum">#</th>
                                    <?php foreach ($report['columns'] as $col) { ?>
                                        <th class="<?= reportColClass($col) ?>">
                                            <?= htmlspecialchars($col['head']) ?>
                                        </th>
                                    <?php } ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $n = 1; foreach ($report['rows'] as $row) { ?>
                                    <tr>
                                        <td class="rpt-rownum"><?= $n++ ?></td>
                                        <?php foreach ($report['columns'] as $col) { ?>
                                            <td class="<?= reportColClass($col) ?>">
                                                <?= reportCell($row, $col) ?>
                                            </td>
                                        <?php } ?>
                                    </tr>
                                <?php } ?>
                            </tbody>
                            <?php if (!empty($report['totals'])) { ?>
                                <tfoot>
                                    <tr>
                                        <th class="rpt-rownum"></th>
                                        <?php $first = true; foreach ($report['columns'] as $col) {
                                            $has = isset($report['totals'][$col['key']]); ?>
                                            <th class="<?= reportColClass($col) ?>">
                                                <?php if ($has) {
                                                    echo reportCell($report['totals'], $col);
                                                } elseif ($first) {
                                                    echo 'Total';
                                                } ?>
                                            </th>
                                        <?php $first = false; } ?>
                                    </tr>
                                </tfoot>
                            <?php } ?>
                        </table>
                    </div>

                <?php } ?>
            </div>
        </div>
        <?php
    }

    /* Money and counts read correctly only when right-aligned. */
    function reportColClass(array $col): string
    {
        $type = $col['type'] ?? 'text';

        if (in_array($type, ['peso', 'number'], true)) {
            return 'rpt-num';
        }

        if ($type === 'wrap') {
            return 'rpt-wrap';
        }

        return '';
    }


    /* ===================================================================
     * ASSETS
     * =================================================================== */

    function reportStyles(): void
    {
        ?>
        <style>
        .rpt-page { --rpt-navy: #00224C; }

        .rpt-head {
            display: flex; flex-wrap: wrap; gap: 12px;
            align-items: flex-start; justify-content: space-between;
            margin-bottom: 18px;
        }
        .rpt-title { color: var(--rpt-navy); font-weight: 700; font-size: 1.75rem; margin: 0; }
        .rpt-blurb { color: #6c757d; margin: 2px 0 0; font-size: .9rem; }
        .rpt-head-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .rpt-period {
            background: #eef2f7; color: #334155; border-radius: 999px;
            padding: 6px 14px; font-size: .82rem; font-weight: 600; white-space: nowrap;
        }

        .rpt-card { border: 1px solid #e9edf2; border-radius: 14px; box-shadow: 0 2px 10px rgba(16,32,63,.04); }
        .rpt-filter .form-label { font-weight: 600; font-size: .82rem; color: #475569; margin-bottom: 4px; }
        .rpt-note { margin: 14px 0 0; font-size: .82rem; color: #64748b; }

        /* TABS */
        .rpt-tabs { gap: 6px; border: 0; margin-bottom: 16px; flex-wrap: wrap; }
        .rpt-tabs .nav-link {
            border: 1px solid #e3e8ef; border-radius: 10px; background: #fff;
            color: #475569; font-weight: 600; font-size: .86rem;
            padding: 8px 14px; display: flex; align-items: center; gap: 7px;
        }
        .rpt-tabs .nav-link:hover { border-color: #c7d2e1; color: var(--rpt-navy); }
        .rpt-tabs .nav-link.active { background: var(--rpt-navy); border-color: var(--rpt-navy); color: #fff; }
        .rpt-count {
            background: #eef2f7; color: #475569; border-radius: 999px;
            padding: 1px 8px; font-size: .72rem; font-weight: 700;
        }
        .rpt-tabs .nav-link.active .rpt-count { background: rgba(255,255,255,.22); color: #fff; }

        /* KPIs */
        .rpt-kpis {
            display: grid; gap: 12px; margin-bottom: 16px;
            grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
        }
        .rpt-kpi {
            background: #fff; border: 1px solid #e9edf2; border-radius: 14px;
            padding: 14px 16px; display: flex; gap: 12px; align-items: center;
            box-shadow: 0 2px 10px rgba(16,32,63,.04);
        }
        .rpt-kpi-icon {
            width: 42px; height: 42px; border-radius: 12px; flex: 0 0 42px;
            display: grid; place-items: center; font-size: 1.15rem;
        }
        .rpt-kpi-body { display: flex; flex-direction: column; min-width: 0; }
        .rpt-kpi-label {
            font-size: .72rem; font-weight: 700; letter-spacing: .04em;
            text-transform: uppercase; color: #94a3b8;
        }
        .rpt-kpi-value { font-size: 1.3rem; font-weight: 700; color: #0f2747; line-height: 1.2; }
        .rpt-kpi-hint { font-size: .74rem; color: #94a3b8; }

        /* PANELS */
        .rpt-panel-head { display: flex; justify-content: space-between; gap: 12px; margin-bottom: 12px; }
        .rpt-panel-title { font-size: 1rem; font-weight: 700; color: var(--rpt-navy); margin: 0; }
        .rpt-panel-blurb { font-size: .82rem; color: #6c757d; margin: 2px 0 0; }

        /* BARS */
        .rpt-bars { display: flex; flex-direction: column; gap: 9px; }
        .rpt-bar-row { display: grid; grid-template-columns: minmax(80px, 1.3fr) 2.4fr auto; gap: 10px; align-items: center; }
        .rpt-bar-label {
            font-size: .82rem; color: #334155; font-weight: 600;
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }
        .rpt-bar-track { background: #eef2f7; border-radius: 999px; height: 9px; overflow: hidden; }
        .rpt-bar-fill { height: 100%; border-radius: 999px; }
        .rpt-bar-value { font-size: .82rem; font-weight: 700; color: #0f2747; white-space: nowrap; }

        /* DONUT */
        .rpt-donut-wrap { display: flex; gap: 18px; align-items: center; flex-wrap: wrap; }
        .rpt-donut { width: 132px; height: 132px; flex: 0 0 132px; transform: rotate(-90deg); }
        .rpt-donut-arc { transition: none; }
        .rpt-legend { list-style: none; margin: 0; padding: 0; flex: 1 1 160px; min-width: 150px; }
        .rpt-legend li { display: flex; align-items: center; gap: 8px; font-size: .84rem; padding: 3px 0; }
        .rpt-dot { width: 10px; height: 10px; border-radius: 3px; flex: 0 0 10px; }
        .rpt-legend-label { color: #334155; font-weight: 600; }
        .rpt-legend-value { margin-left: auto; font-weight: 700; color: #0f2747; }

        /* TABLE */
        .rpt-table thead th {
            background: #f7f9fc; color: #475569; font-size: .76rem; font-weight: 700;
            letter-spacing: .03em; text-transform: uppercase; border-bottom: 1px solid #e9edf2;
            white-space: nowrap;
        }
        .rpt-table tbody td { font-size: .87rem; color: #1f2d3d; vertical-align: middle; }
        .rpt-table tfoot th {
            background: #f7f9fc; color: #0f2747; font-size: .86rem;
            border-top: 2px solid #dfe5ee;
        }
        .rpt-num { text-align: right; }
        .rpt-rownum { width: 46px; color: #94a3b8; }
        .rpt-wrap { max-width: 260px; white-space: normal; }
        .rpt-page .dt-search input { border: 1px solid #e3e8ef; border-radius: 9px; padding: 6px 12px; }
        .rpt-page .dt-search { margin-bottom: 12px; }

        /* EMPTY */
        .rpt-empty { text-align: center; padding: 42px 18px; color: #94a3b8; }
        .rpt-empty i { font-size: 2.4rem; opacity: .55; }
        .rpt-empty-title { font-weight: 700; color: #475569; margin: 12px 0 4px; }
        .rpt-empty-text { font-size: .85rem; margin: 0; }

        /* PRINT -- the sidebar, the top bar and every control come off the
           page, and the one open report prints on its own. */
        .rpt-print-head { display: none; }
        @media print {
            .sidebar, .top-navbar, .rpt-filter, .rpt-tabs,
            .rpt-head-actions, .dt-search, .dt-paging, .dt-info, .dt-length { display: none !important; }
            .main-content, .main-content.expanded { margin: 0 !important; width: 100% !important; }
            .rpt-print-head {
                display: flex; justify-content: space-between; align-items: baseline;
                border-bottom: 2px solid #00224C; padding-bottom: 6px; margin-bottom: 14px;
                font-size: 11pt;
            }
            .rpt-card, .rpt-kpi { box-shadow: none !important; border-color: #ccc !important; }
            .rpt-table tbody td, .rpt-table thead th { font-size: 8.5pt; }
            .rpt-table thead { display: table-header-group; }
            .rpt-table tr { page-break-inside: avoid; }
            .rpt-kpis { grid-template-columns: repeat(4, 1fr); }
            a[href]:after { content: none !important; }
        }
        </style>
        <?php
    }

    function reportScripts(array $reports): void
    {
        $ids = array_map(static function (array $r) {
            return ['id' => $r['id'], 'rows' => count($r['rows'])];
        }, $reports);
        ?>
        <script>
        document.addEventListener("DOMContentLoaded", function () {

            var reports = <?= json_encode($ids) ?>;
            var tables = {};

            var config = {
                pageLength: 15,
                lengthChange: false,
                order: [],
                scrollX: true,
                language: {
                    search: "",
                    searchPlaceholder: "Search this report...",
                    info: "Showing _START_ to _END_ of _TOTAL_",
                    infoEmpty: "No records",
                    zeroRecords: "Nothing matches that search",
                    paginate: { previous: "Prev", next: "Next" }
                }
            };

            /* A table is only built once its tab has been opened: six hidden
               tables measured at load get their column widths wrong, which is
               what made the old pages jump when a tab was clicked. */
            function build(id) {
                if (tables[id]) return;
                var el = document.getElementById("rptTable-" + id);
                if (!el) return;                 // an empty report has no table
                tables[id] = new DataTable(el, config);
            }

            reports.forEach(function (r) {
                var pane = document.getElementById("rpt-" + r.id);
                if (pane && pane.classList.contains("active")) build(r.id);
            });

            var tabs = document.getElementById("rptTabs");

            tabs.addEventListener("shown.bs.tab", function (event) {
                var id = event.target.getAttribute("data-report");
                build(id);
                if (tables[id]) tables[id].columns.adjust();
                syncExport();
            });

            /* EXPORT -- the button follows the open tab, and carries the
               filters with it, so the file matches what is on screen. */
            var exportLink = document.getElementById("rptExport");

            function syncExport() {
                var active = tabs.querySelector(".nav-link.active");
                if (!active || !exportLink) return;

                var params = new URLSearchParams(window.location.search);
                params.set("export", active.getAttribute("data-report"));
                exportLink.setAttribute("href", "?" + params.toString());

                var id = active.getAttribute("data-report");
                var empty = reports.some(function (r) { return r.id === id && r.rows === 0; });
                exportLink.classList.toggle("disabled", empty);
            }

            syncExport();

            /* CUSTOM RANGE -- the two date boxes only matter for one preset,
               so they are dimmed otherwise instead of inviting a value that
               would be ignored. */
            var rangeSelect = document.getElementById("rptRange");
            var customCols = document.querySelectorAll(".rpt-custom");

            function syncCustom() {
                var custom = rangeSelect.value === "custom";
                customCols.forEach(function (col) {
                    col.style.opacity = custom ? "1" : ".45";
                    var input = col.querySelector("input");
                    if (input) input.disabled = !custom;
                });
            }

            rangeSelect.addEventListener("change", function () {
                syncCustom();
                if (rangeSelect.value !== "custom") {
                    document.getElementById("rptFilter").submit();
                }
            });

            syncCustom();

            /* PRINT -- paging is lifted first, or the printout silently stops
               at fifteen rows while the heading claims the whole period. */
            var printing = {};

            function liftPaging() {
                Object.keys(tables).forEach(function (id) {
                    printing[id] = tables[id].page.len();
                    tables[id].page.len(-1).draw(false);
                });
            }

            function restorePaging() {
                Object.keys(printing).forEach(function (id) {
                    if (tables[id]) tables[id].page.len(printing[id]).draw(false);
                });
                printing = {};
            }

            window.addEventListener("beforeprint", liftPaging);
            window.addEventListener("afterprint", restorePaging);

            var printButton = document.getElementById("rptPrint");

            if (printButton) {
                printButton.addEventListener("click", function () {
                    liftPaging();
                    window.print();
                    restorePaging();
                });
            }
        });
        </script>
        <?php
    }
}
