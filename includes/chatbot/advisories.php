<?php
/*
|--------------------------------------------------------------------------
| ADVISORIES
|--------------------------------------------------------------------------
|
| A recommendation drawn from the company's own data: "two people left
| recently and no job posting is open". Shown only when asked -- there is no
| badge and no pop-up anywhere in this feature -- and always with the figures
| that triggered it, so "why are you telling me this?" is already answered.
|
| An advisory never writes. It ends with a link to the page where the work is
| done, and a person does it.
*/

/* Thresholds, in one place. */
const ADVICE_RECENT_DEPARTURE_DAYS = 60;
const ADVICE_APPLICANT_STALE_DAYS = 7;
const ADVICE_PAYABLE_DUE_DAYS = 7;
const ADVICE_LOW_CAPITAL = 1000.00;
const ADVICE_SUBSCRIPTION_DAYS = 14;

function chatbotAdvisories(): array
{
    return [
        'hiring_needed' => [
            'topic' => 'recruitment',
            'roles' => ['hr', 'admin'],
            'scope' => 'company',
            'page' => 'recruitment',
            'check' => 'chatbotAdviseHiring',
        ],

        'applicants_waiting' => [
            'topic' => 'recruitment',
            'roles' => ['hr', 'admin'],
            'scope' => 'company',
            'page' => 'applications',
            'check' => 'chatbotAdviseStaleApplicants',
        ],

        'leave_waiting' => [
            'topic' => 'leave',
            'roles' => ['hr', 'admin'],
            'scope' => 'company',
            'page' => 'leave_approval',
            'check' => 'chatbotAdviseLeaveWaiting',
        ],

        'incomplete_records' => [
            'topic' => 'hrms',
            'roles' => ['hr'],
            'scope' => 'company',
            'page' => 'employees',
            'check' => 'chatbotAdviseIncompleteRecords',
        ],

        'approvals_waiting' => [
            'topic' => 'inventory',
            'roles' => ['admin'],
            'scope' => 'company',
            'page' => 'stock_requests',
            'check' => 'chatbotAdviseApprovalsWaiting',
        ],

        'stock_low' => [
            'topic' => 'inventory',
            'roles' => ['admin', 'inventory'],
            'scope' => 'company',
            'page' => 'inventory',
            'check' => 'chatbotAdviseLowStock',
        ],

        'capital_low' => [
            'topic' => 'finance',
            'roles' => ['admin'],
            'scope' => 'company',
            'page' => null,
            'check' => 'chatbotAdviseLowCapital',
        ],

        'subscription_due' => [
            'topic' => 'staff',
            'roles' => ['admin'],
            'scope' => 'company',
            'page' => null,
            'check' => 'chatbotAdviseSubscription',
        ],

        'payroll_waiting' => [
            'topic' => 'payroll',
            'roles' => ['finance', 'admin'],
            'scope' => 'company',
            'page' => 'payroll',
            'check' => 'chatbotAdvisePayrollWaiting',
        ],

        'finance_requests' => [
            'topic' => 'finance',
            'roles' => ['finance', 'admin'],
            'scope' => 'company',
            'page' => 'stock_requests',
            'check' => 'chatbotAdviseFinanceRequests',
        ],

        'payables_due' => [
            'topic' => 'finance',
            'roles' => ['finance', 'admin'],
            'scope' => 'company',
            'page' => 'payables',
            'check' => 'chatbotAdvisePayablesDue',
        ],

        'deliveries_ready' => [
            'topic' => 'inventory',
            'roles' => ['inventory', 'admin'],
            'scope' => 'company',
            'page' => 'stock_requests',
            'check' => 'chatbotAdviseDeliveriesReady',
        ],

        'my_time_out_missing' => [
            'topic' => 'staff',
            'roles' => ['cashier', 'employee', 'inventory', 'finance', 'hr'],
            'scope' => 'own',
            'page' => null,
            'check' => 'chatbotAdviseMissingTimeOut',
        ],

        'my_leave_pending' => [
            'topic' => 'staff',
            'roles' => ['cashier', 'employee', 'inventory', 'finance', 'hr'],
            'scope' => 'own',
            'page' => null,
            'check' => 'chatbotAdviseMyLeavePending',
        ],
    ];
}

/**
 * The advisories this role, on this plan, may be shown.
 *
 * The same two gates the intents use: being an advisory is not a way around
 * the matrix.
 */
function chatbotAdvisoriesFor(mysqli $conn, array $ctx): array
{
    $role = strtolower(trim((string) $ctx['role']));
    $allowed = [];

    foreach (chatbotAdvisories() as $id => $advisory) {

        if (!in_array($role, $advisory['roles'], true)) {
            continue;
        }

        if (!chatbotPlanAllowsTopic($conn, (int) $ctx['company_id'], $advisory['topic'])) {
            continue;
        }

        if ($advisory['scope'] === 'own' && empty($ctx['employee_id'])) {
            continue;
        }

        $allowed[$id] = $advisory;
    }

    return $allowed;
}

/**
 * Every advisory that has something to say, with its evidence.
 *
 * A check returns null when its condition is false, and the assistant then
 * says nothing about it: an advisory is never invented to appear useful.
 */
function chatbotRunAdvisories(mysqli $conn, array $ctx): array
{
    $found = [];

    foreach (chatbotAdvisoriesFor($conn, $ctx) as $id => $advisory) {

        try {
            $outcome = ($advisory['check'])($conn, $ctx);
        } catch (Throwable $error) {
            error_log('chatbot advisory ' . $id . ': ' . $error->getMessage());
            continue;
        }

        if ($outcome === null) {
            continue;
        }

        $found[] = [
            'id' => $id,
            'message' => $outcome['message'],
            'evidence' => $outcome['evidence'],
            'link' => $advisory['page'] === null
                ? null
                : chatbotPageLink($ctx, $advisory['page']),
        ];
    }

    return $found;
}

/**
 * The answer behind "what needs my attention?".
 */
function chatbotWhatNeedsAttention(mysqli $conn, array $ctx, string $question): array
{
    $advice = chatbotRunAdvisories($conn, $ctx);

    if ($advice === []) {
        return [
            'title' => 'What needs attention',
            'lines' => [],
            'table' => null,
            'link' => null,
            'note' => 'Nothing needs your attention right now.',
        ];
    }

    $rows = [];

    foreach ($advice as $entry) {

        $evidence = [];

        foreach ($entry['evidence'] as [$label, $value]) {
            $evidence[] = $label . ': ' . $value;
        }

        $rows[] = [$entry['message'], implode(' · ', $evidence),
                   $entry['link']['label'] ?? ''];
    }

    return [
        'title' => 'What needs attention',
        'lines' => [['Items', (string) count($rows)]],
        'table' => ['columns' => ['Suggestion', 'Why', 'Where'], 'rows' => $rows],
        /* The first advisory's page: one link, not a row of them. */
        'link' => $advice[0]['link'],
        'note' => 'These are suggestions. Nothing has been changed.',
    ];
}
