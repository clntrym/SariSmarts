<?php

/*
|--------------------------------------------------------------------------
| NOTIFICATION FEED
|--------------------------------------------------------------------------
|
| What the bell in the topbar shows.
|
| The items are derived from live rows, not copied into a notifications
| table. An application awaiting review IS the row in company; an unpaid
| subscription IS the row in company_subscriptions. Deriving them means the
| bell can never disagree with the module it links to, and an item vanishes
| by itself the moment somebody deals with it - no cleanup job, no stale
| copy pointing at a record that has moved on.
|
| This is also where the two apps meet. RetailCore and the platform run on
| the same `sari` database, so a business registering, paying or being
| approved inside RetailCore writes the same rows this file reads. Nothing
| has to be pushed or synced: the event is already here.
|
| Every item declares the module it belongs to, and the feed is filtered
| through platformCan(), so Finance is told about money, Marketing & HR
| about leads and tickets, and Super Admin about all of it. Nobody is
| notified about a screen they cannot open.
|
*/

require_once __DIR__ . '/platform_roles.php';
require_once __DIR__ . '/permits.php';


if (!function_exists('platformNow')) {

    /*
    | Now, according to the database.
    |
    | Every timestamp the bell compares - a company's submitted_at, a
    | ticket's created_at, an operator's notifications_seen_at - was written
    | by MySQL. Comparing those against PHP's time() works only while the
    | two clocks agree, and nothing enforces that: init.php sets PHP to
    | Asia/Manila while MySQL follows the system zone, so a server
    | configured differently would silently shift every relative time and
    | every unread count. Asking MySQL removes the assumption.
    */
    function platformNow(mysqli $conn): string
    {
        static $now = null;

        if ($now === null) {
            $row = $conn->query('SELECT NOW() AS n')->fetch_assoc();
            $now = $row['n'] ?? date('Y-m-d H:i:s');
        }

        return $now;
    }
}


if (!function_exists('platformFeed')) {

    /*
    | Returns at most $limit items, newest first. Each one carries:
    |
    |   module  the access key, used to decide who sees it
    |   icon    a Bootstrap Icons class
    |   tone    bad | warn | ok | accent, for the dot colour
    |   title   the headline
    |   detail  one line of context
    |   at      when this item started mattering. Always in the past, so
    |           that marking the bell read actually clears it and keeps it
    |           cleared. For a deadline that is the moment it came into
    |           view, not the deadline itself.
    |   due     optional deadline to show the reader instead of 'at'
    |   url     where acting on it starts
    */
    function platformFeed(mysqli $conn, int $limit = 12): array
    {
        $items = [];

        /* ---- Applications waiting to be reviewed ------------------------
           submitted_at is null on rows that predate it, so created_at
           stands in rather than dropping the item. */
        $sql = "
            SELECT company_id, company_name, company_code, status,
                   COALESCE(submitted_at, created_at) AS at
            FROM company
            WHERE status IN ('Pending', 'Rejected')
            ORDER BY at DESC
            LIMIT 6
        ";

        if ($result = $conn->query($sql)) {
            while ($row = $result->fetch_assoc()) {
                $pending = $row['status'] === 'Pending';

                $items[] = [
                    'module' => 'companyReview',
                    'icon' => 'bi-clipboard-check',
                    'tone' => $pending ? 'warn' : 'bad',
                    'title' => $pending ? 'Application waiting for review' : 'Application was rejected',
                    'detail' => $row['company_name'] . ($row['company_code'] ? ' (' . $row['company_code'] . ')' : ''),
                    'at' => $row['at'],
                    'url' => 'companyReview.php',
                ];
            }
        }

        /* ---- Approved but not subscribed yet ----------------------------
           These are businesses the platform has already said yes to that
           still cannot sign in. Easy to forget, and the one the customer
           feels. */
        $sql = "
            SELECT c.company_id, c.company_name, COALESCE(c.submitted_at, c.created_at) AS at
            FROM company c
            LEFT JOIN company_subscriptions cs ON cs.company_id = c.company_id
            WHERE c.status = 'Approved' AND cs.subscription_id IS NULL
            ORDER BY at DESC
            LIMIT 4
        ";

        if ($result = $conn->query($sql)) {
            while ($row = $result->fetch_assoc()) {
                $items[] = [
                    'module' => 'subscriptionManagement',
                    'icon' => 'bi-hourglass-split',
                    'tone' => 'warn',
                    'title' => 'Approved but has no subscription',
                    'detail' => $row['company_name'] . ' still cannot sign in.',
                    'at' => $row['at'],
                    'url' => 'subscriptionManagement.php',
                ];
            }
        }

        /* ---- Money that has not arrived --------------------------------- */
        $sql = "
            SELECT cs.subscription_id, cs.amount, cs.payment_status, cs.expiry_date,
                   cs.created_at AS at, c.company_name
            FROM company_subscriptions cs
            INNER JOIN company c ON c.company_id = cs.company_id
            WHERE cs.payment_status <> 'Paid'
            ORDER BY cs.created_at DESC
            LIMIT 6
        ";

        if ($result = $conn->query($sql)) {
            while ($row = $result->fetch_assoc()) {
                $failed = $row['payment_status'] === 'Failed';
                $overdue = !empty($row['expiry_date']) && strtotime($row['expiry_date']) < strtotime(date('Y-m-d'));

                $items[] = [
                    'module' => 'billing',
                    'icon' => $failed ? 'bi-x-octagon' : 'bi-receipt',
                    'tone' => ($failed || $overdue) ? 'bad' : 'warn',
                    'title' => $failed ? 'Payment failed' : ($overdue ? 'Payment overdue' : 'Payment pending'),
                    'detail' => $row['company_name'] . ' - PHP ' . number_format((float) $row['amount'], 2),
                    'at' => $row['at'],
                    'url' => 'billing.php',
                ];
            }
        }

        /* ---- Subscriptions about to lapse -------------------------------
           A deadline has no event moment, but it does have a moment it
           came into view: the day it entered the 30-day window. Dating it
           there gives a fixed point in the past, which is what lets Mark
           all as read clear it and keep it cleared. Dating it to the
           expiry instead would put it permanently ahead of any 'seen'
           stamp, so the badge would come back a second later, every
           time. */
        $sql = "
            SELECT cs.expiry_date,
                   DATE_SUB(cs.expiry_date, INTERVAL 30 DAY) AS came_into_view,
                   c.company_name
            FROM company_subscriptions cs
            INNER JOIN company c ON c.company_id = cs.company_id
            WHERE cs.status IN ('Active', 'Trial')
              AND cs.expiry_date IS NOT NULL
              AND cs.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
            ORDER BY cs.expiry_date
            LIMIT 4
        ";

        if ($result = $conn->query($sql)) {
            while ($row = $result->fetch_assoc()) {
                $days = (int) floor((strtotime($row['expiry_date']) - strtotime(date('Y-m-d'))) / 86400);

                $items[] = [
                    'module' => 'subscriptionManagement',
                    'icon' => 'bi-calendar-x',
                    'tone' => $days <= 7 ? 'bad' : 'warn',
                    'title' => 'Subscription expires in ' . $days . ' day' . ($days === 1 ? '' : 's'),
                    'detail' => $row['company_name'],
                    'at' => $row['came_into_view'] . ' 00:00:00',
                    'due' => $row['expiry_date'] . ' 00:00:00',
                    'url' => 'subscriptionManagement.php',
                ];
            }
        }

        /* ---- DTI registrations running out ------------------------------
           A DTI Certificate of Business Name Registration lasts five years,
           and a tenant trading on a lapsed one is the platform's problem as
           much as theirs. The thresholds are the ones in permits.php, so a
           badge in the bell always agrees with the badge on the record.

           Dated to the day it entered the 180-day window, like the other
           deadlines here, so marking the bell read actually clears it. */
        $sql = "
            SELECT company_id, company_name, dti_expiry_date,
                   DATE_SUB(dti_expiry_date, INTERVAL " . DTI_EXPIRING_DAYS . " DAY) AS came_into_view
            FROM company
            WHERE dti_expiry_date IS NOT NULL
              AND dti_expiry_date <= DATE_ADD(CURDATE(), INTERVAL " . DTI_EXPIRING_DAYS . " DAY)
              AND status IN ('Approved', 'Active')
            ORDER BY dti_expiry_date
            LIMIT 6
        ";

        if ($result = $conn->query($sql)) {
            while ($row = $result->fetch_assoc()) {
                $status = dtiStatus($row['dti_expiry_date'], platformNow($conn));

                $items[] = [
                    'module' => 'company',
                    'icon' => 'bi-file-earmark-x',
                    'tone' => $status['tone'] === 'ok' ? 'warn' : $status['tone'],
                    'title' => 'DTI registration: ' . $status['label'],
                    'detail' => $row['company_name'] . ' - '
                        . ($status['days'] < 0
                            ? 'lapsed ' . abs($status['days']) . ' days ago'
                            : $status['days'] . ' days left'),
                    'at' => $row['came_into_view'] . ' 00:00:00',
                    'due' => $row['dti_expiry_date'] . ' 00:00:00',
                    'url' => 'company.php',
                ];
            }
        }

        /* ---- Support tickets nobody has answered ------------------------- */
        if ($conn->query("SHOW TABLES LIKE 'support_tickets'")->num_rows) {

            $sql = "
                SELECT ticket_code, subject, priority, created_at AS at
                FROM support_tickets
                WHERE status = 'Open'
                ORDER BY FIELD(priority, 'Urgent', 'High', 'Normal', 'Low'), created_at DESC
                LIMIT 6
            ";

            if ($result = $conn->query($sql)) {
                while ($row = $result->fetch_assoc()) {
                    $urgent = $row['priority'] === 'Urgent';

                    $items[] = [
                        'module' => 'support',
                        'icon' => 'bi-headset',
                        'tone' => $urgent ? 'bad' : 'accent',
                        'title' => ($urgent ? 'Urgent ticket' : 'Ticket') . ' waiting: ' . $row['ticket_code'],
                        'detail' => $row['subject'],
                        'at' => $row['at'],
                        'url' => 'support.php',
                    ];
                }
            }
        }

        /* ---- Leads whose follow-up date has passed ----------------------- */
        if ($conn->query("SHOW TABLES LIKE 'marketing_leads'")->num_rows) {

            $sql = "
                SELECT business_name, next_action
                FROM marketing_leads
                WHERE stage NOT IN ('Won', 'Lost')
                  AND next_action IS NOT NULL
                  AND next_action < CURDATE()
                ORDER BY next_action
                LIMIT 4
            ";

            if ($result = $conn->query($sql)) {
                while ($row = $result->fetch_assoc()) {
                    $items[] = [
                        'module' => 'leads',
                        'icon' => 'bi-alarm',
                        'tone' => 'warn',
                        'title' => 'Follow-up overdue',
                        'detail' => $row['business_name'] . ' was due '
                            . date('M d', strtotime($row['next_action'])),
                        'at' => $row['next_action'] . ' 00:00:00',
                        'url' => 'leads.php',
                    ];
                }
            }
        }

        /* ---- Accounts created inside RetailCore -------------------------
           A tenant adding staff in their own app writes to the same users
           table, which is the clearest sign the two halves are connected. */
        $sql = "
            SELECT u.fullname, u.role, u.join_date AS at, c.company_name
            FROM users u
            INNER JOIN company c ON c.company_id = u.company_id
            WHERE u.join_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            ORDER BY u.join_date DESC
            LIMIT 4
        ";

        if ($result = $conn->query($sql)) {
            while ($row = $result->fetch_assoc()) {
                $items[] = [
                    'module' => 'users',
                    'icon' => 'bi-person-plus',
                    'tone' => 'ok',
                    'title' => 'New account in ' . $row['company_name'],
                    'detail' => $row['fullname'] . ' joined as ' . $row['role'] . '.',
                    'at' => $row['at'],
                    'url' => 'users.php',
                ];
            }
        }

        /* Only what this role can act on. */
        $items = array_values(array_filter($items, function (array $item): bool {
            return platformCan($item['module']);
        }));

        /*
        | Urgency first, then newest. A bell sorted purely by time puts a
        | subscription expiring in a month above an application that came in
        | this morning, which is backwards: the operator wants to see what is
        | already broken before what is merely approaching.
        */
        /*
        | Urgency first, then most recent. A bell sorted purely by time puts
        | a subscription expiring in a month above an application that came
        | in this morning, which is backwards: what is already broken beats
        | what is merely approaching.
        */
        $weight = ['bad' => 0, 'warn' => 1, 'accent' => 2, 'ok' => 3];
        $now = platformNow($conn);

        $since = function (?string $at) use ($now): int {
            return (int) strtotime($at === null || $at === '' ? $now : $at);
        };

        usort($items, function (array $a, array $b) use ($weight, $since): int {
            $byTone = ($weight[$a['tone']] ?? 9) <=> ($weight[$b['tone']] ?? 9);

            return $byTone !== 0 ? $byTone : $since($b['at']) <=> $since($a['at']);
        });

        return array_slice($items, 0, $limit);
    }
}


if (!function_exists('platformFeedIsNew')) {

    /*
    | Whether one item is newer than the operator's last look.
    |
    | 'at' is always a moment in the past, which is what makes this stable:
    | once the operator has looked, nothing already in the list can creep
    | back to unread, and a badge that clears is a badge people keep
    | reading. The $now argument is kept for callers that have the database
    | clock to hand.
    */
    function platformFeedIsNew(array $item, ?string $seenAt, ?string $now = null): bool
    {
        if ($seenAt === null || $seenAt === '') {
            return true;
        }

        $at = $item['at'] ?? null;

        if ($at === null || $at === '') {
            return $now === null || strtotime($now) > strtotime($seenAt);
        }

        return strtotime((string) $at) > strtotime($seenAt);
    }
}


if (!function_exists('platformFeedUnread')) {

    /* How many of these the operator has not seen. */
    function platformFeedUnread(array $items, ?string $seenAt, ?string $now = null): int
    {
        $unread = 0;

        foreach ($items as $item) {
            if (platformFeedIsNew($item, $seenAt, $now)) {
                $unread++;
            }
        }

        return $unread;
    }
}


if (!function_exists('platformFeedWhen')) {

    /*
    | What to print under an item. A deadline is shown as the deadline,
    | because "in 14 days" is what the reader needs; everything else is
    | shown as how long ago it happened.
    */
    function platformFeedWhen(array $item, ?string $now = null): string
    {
        return platformFeedAgo($item['due'] ?? $item['at'], $now);
    }
}


if (!function_exists('platformFeedAgo')) {

    /* "4 minutes ago" reads better than a timestamp in a dropdown. */
    function platformFeedAgo(?string $at, ?string $now = null): string
    {
        if ($at === null || $at === '') {
            return 'just now';
        }

        $clock = $now === null ? time() : (int) strtotime($now);
        $seconds = $clock - strtotime($at);

        /* A date in the future is a deadline, not an event that happened. */
        if ($seconds < 0) {
            $days = (int) ceil(-$seconds / 86400);

            return $days <= 1 ? 'due tomorrow' : 'in ' . $days . ' days';
        }

        $steps = [
            [60, 'second'],
            [3600, 'minute'],
            [86400, 'hour'],
            [604800, 'day'],
        ];

        $previous = 1;

        foreach ($steps as [$cut, $label]) {
            if ($seconds < $cut) {
                $n = (int) floor($seconds / $previous);
                return $n <= 1 ? 'just now' : $n . ' ' . $label . 's ago';
            }
            $previous = $cut;
        }

        return date('M d, Y', strtotime($at));
    }
}
