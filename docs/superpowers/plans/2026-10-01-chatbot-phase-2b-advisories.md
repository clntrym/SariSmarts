# Chatbot Phase 2B — Advisories

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** When a user asks what needs attention, the assistant names real work waiting in that company's data — starting with the HR hiring suggestion the owner asked for by name — and links to the page where the work is done, without doing it.

**Architecture:** An advisory is a named, read-only check: a company-scoped `SELECT` that returns either nothing or a message plus the figures that triggered it. A catalog holds them with a topic and a role list, gated exactly like intents. One new intent, `what_needs_attention`, runs every advisory this user may see and composes the answer. No badge, no pop-up, no unprompted notification: advisories appear only when asked.

**Tech Stack:** PHP 8.2.12 (XAMPP), MariaDB 10.4, mysqli. Tests: the CLI harness in `tests/chatbot/bootstrap.php`, plus `tests/chatbot/query_audit.py`.

**Spec:** `docs/superpowers/specs/2026-09-29-sarismarts-chatbot-design.md` §6.5 ("Advisories — the suggestions the bot makes"), §1 non-goals (read-only; no unprompted notifications)

## Global Constraints

- PHP `C:/xampp/php/php.exe`; MySQL `C:/xampp/mysql/bin/mysql.exe -uroot sari`.
- Context array is exactly `['company_id' => int, 'user_id' => int, 'employee_id' => ?int, 'role' => string]`.
- Every advisory check is a prepared `SELECT` with `company_id = ?` bound and its columns named. `tests/chatbot/query_audit.py` scans `includes/chatbot/` and fails on a write or a missing company filter, so the files land inside that scope on purpose.
- **An advisory never writes.** No INSERT, UPDATE or DELETE anywhere in this plan.
- **An advisory appears only when its condition is true**, and always carries the figures that triggered it. An advisory that cannot show its numbers is not written (spec §6.5).
- Links use `chatbotPageLink($ctx, '<page>')` from Phase 2A — never a hard-coded href, because the page differs per role and some roles have none.
- Thresholds live in one place: `includes/chatbot/advisories.php`, as named constants.
- Live status vocabularies (from the writing code, not guessed): `stock_requests.status` `Pending Finance` / `Pending Admin` / `Admin Approved` / `Received`; `payroll.status` `Pending Approval`; `applications.status` `Pending`; `accounts_payable.status` `Pending` / `Partial`; `employees.employment_status` `Archived` for someone who has left; `job.status` `Published`.
- Not a git repository: each task ends with a *Checkpoint* (full suite + audit).

## Review Focus

1. **A company with nothing wrong** — asking "what needs attention" must say so plainly, not invent work or return an empty card. Test in Task 1.
2. **An advisory whose figures are zero after the check ran** — the check and the evidence must come from the same query, so a count of 3 can never be shown beside an empty list. Test in Task 2.
3. **A role seeing an advisory its matrix denies** — a cashier must never be told what payroll is pending. Test in Task 6.
4. **A plan that does not include the topic** — a Retail Starter owner must not be offered a recruitment or payroll advisory. Test in Task 6.
5. **Two companies with identical-looking situations** — every advisory counts only its own company, and an advisory that aggregates across them is caught by the figure, not by a name. Test in Task 6.

---

### Task 1: The mechanism, and the hiring advisory

**A gap this advisory exposes.** "Two people left recently" is not answerable
from the data: `employees` has `created_at` and `date_of_birth` and nothing
else, and `hr/archive_employee.php:95-99` sets `employment_status = 'Archived'`
without recording when. Counting archived employees with no date would make the
advisory fire forever after the first resignation, which is how an assistant
gets ignored. So this task adds `employees.archived_at` and stamps it where the
app archives and restores — the same shape as the `sales.created_by` gap in
Phase 1.

**Files:**
- Create: `platform/database/employees_archived_at.sql`
- Modify: `hr/archive_employee.php:95-108` and `:190-205`
- Create: `includes/chatbot/advisories.php`
- Create: `includes/chatbot/advice/hr.php`
- Modify: `includes/chatbot/intents.php` (add `what_needs_attention`)
- Modify: `includes/chatbot/engine.php` (require both; add the handler)
- Test: `tests/chatbot/test_advisories.php`
- Test: `tests/chatbot/test_archived_at.php`

**Interfaces:**
- Produces:
  - `chatbotAdvisories(): array` — id => `['topic'=>string,'roles'=>string[],'scope'=>'company'|'own','page'=>?string,'check'=>string]`
  - `chatbotAdvisoriesFor(mysqli $conn, array $ctx): array` — the catalog filtered by role and plan
  - `chatbotRunAdvisories(mysqli $conn, array $ctx): array` — list of `['id'=>string,'message'=>string,'evidence'=>array<array{0:string,1:string}>,'link'=>?array]`
  - `chatbotWhatNeedsAttention(mysqli $conn, array $ctx, string $question): array` — the answer shape
  - Check functions `(mysqli $conn, array $ctx): ?array` returning `['message'=>string,'evidence'=>array]` or null
  - `chatbotAdviseHiring` — the first one

- [ ] **Step 0: The archived_at column, first**

`tests/chatbot/test_archived_at.php`:

```php
<?php
/*
| Archiving an employee records WHEN. Without it, "two people left recently"
| cannot be answered, and an advisory that cannot show its evidence is not
| written (spec section 6.5).
*/
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/factory.php';

register_shutdown_function(function () use ($conn) { testCleanup($conn); });

$columns = [];
$result = $conn->query("SHOW COLUMNS FROM employees");

while ($row = $result->fetch_assoc()) {
    $columns[$row['Field']] = $row['Null'];
}

t_ok(isset($columns['archived_at']), 'employees records when somebody was archived');
t_same('YES', $columns['archived_at'] ?? '', 'and it is nullable, because most are not');

/* The statement hr/archive_employee.php carries must set it. */
$source = file_get_contents(__DIR__ . '/../../hr/archive_employee.php');

preg_match_all('/UPDATE employees\s+SET(.*?)WHERE/s', $source, $matches);

t_ok(count($matches[1]) >= 2, 'the archive page still has its two updates');

$archiving = null;
$restoring = null;

foreach ($matches[1] as $clause) {
    if (str_contains($clause, 'archived_at = NOW()')) {
        $archiving = $clause;
    }

    if (str_contains($clause, 'archived_at = NULL')) {
        $restoring = $clause;
    }
}

t_ok($archiving !== null, 'archiving stamps the date');
t_ok($restoring !== null, 'and restoring clears it');

/* And it works against the real table. */
$companyId = testMakeCompany($conn, 'Archived At Co', 2);

$conn->query("INSERT INTO employees (company_id, first_name, last_name, employment_status)
              VALUES ({$companyId}, 'Ana', 'Cruz', 'Official Employee')");
$employeeId = (int) $conn->insert_id;

$conn->query("UPDATE employees SET employment_status = 'Archived', archived_at = NOW()
               WHERE employee_id = {$employeeId} AND company_id = {$companyId}");

$row = $conn->query("SELECT archived_at FROM employees
                      WHERE employee_id = {$employeeId}")->fetch_assoc();

t_ok(!empty($row['archived_at']), 'the date is stored');

t_done();
```

Run: `C:/xampp/php/php.exe tests/chatbot/test_archived_at.php`
Expected: FAIL — there is no `archived_at` column.

`platform/database/employees_archived_at.sql`:

```sql
/*
| When somebody left.
|
| hr/archive_employee.php set employment_status = 'Archived' and recorded no
| date, so "two people left this month" could not be answered -- and an
| advisory that cannot show its evidence is not worth having.
|
| Nullable: almost every row is somebody who has not left.
*/
ALTER TABLE employees
    ADD COLUMN archived_at DATETIME NULL AFTER employment_status;
```

Run: `C:/xampp/mysql/bin/mysql.exe -uroot sari < platform/database/employees_archived_at.sql`

Then in `hr/archive_employee.php`, the archiving update (around line 95):

```php
        $update = $conn->prepare("
            UPDATE employees
            SET employment_status = ?, archived_at = NOW()
            WHERE employee_id = ? AND company_id = ?
        ");
```

and the restoring update (around line 190):

```php
                    $update = $conn->prepare("
                        UPDATE employees
                        SET employment_status = ?, archived_at = NULL
                        WHERE employee_id = ? AND company_id = ?
                        AND employment_status = 'Archived'
                    ");
```

Run the test again: expected PASS. Then lint the page — it is live HR code:
`C:/xampp/php/php.exe -l hr/archive_employee.php`

- [ ] **Step 1: Write the failing test**

`tests/chatbot/test_advisories.php`:

```php
<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/factory.php';
require_once __DIR__ . '/../../includes/chatbot/engine.php';

register_shutdown_function(function () use ($conn) { testCleanup($conn); });

$companyId = testMakeCompany($conn, 'Advisory Co', 2);
$hrCtx = ['company_id' => $companyId, 'user_id' => 1, 'employee_id' => null, 'role' => 'hr'];

/* Review Focus 1: a company with nothing wrong. */
$result = chatbotAnswer($conn, $hrCtx, 'what needs my attention');

t_same('what_needs_attention', $result['intent'], 'the question is understood');
t_ok($result['ok'], 'and answered');
t_same([], $result['answer']['table']['rows'] ?? [], 'with no invented work');
t_ok(str_contains(mb_strtolower((string) $result['answer']['note']), 'nothing'),
    'it says plainly that nothing needs attention');

/* The Tagalog form reaches it too. */
t_same('what_needs_attention',
    chatbotAnswer($conn, $hrCtx, 'may dapat ba akong asikasuhin')['intent'],
    'the Tagalog question reaches the same answer');

/* Now somebody leaves, and no posting is open. */
$conn->query("INSERT INTO employees (company_id, first_name, last_name, employment_status, archived_at)
              VALUES ({$companyId}, 'Ana', 'Cruz', 'Archived', DATE_SUB(NOW(), INTERVAL 10 DAY))");
$conn->query("INSERT INTO employees (company_id, first_name, last_name, employment_status, archived_at)
              VALUES ({$companyId}, 'Ben', 'Santos', 'Archived', DATE_SUB(NOW(), INTERVAL 20 DAY))");

/* Somebody who left two years ago is not a reason to hire today. */
$conn->query("INSERT INTO employees (company_id, first_name, last_name, employment_status, archived_at)
              VALUES ({$companyId}, 'Old', 'Leaver', 'Archived', DATE_SUB(NOW(), INTERVAL 800 DAY))");

$advice = chatbotRunAdvisories($conn, $hrCtx);
$hiring = null;

foreach ($advice as $entry) {
    if ($entry['id'] === 'hiring_needed') {
        $hiring = $entry;
    }
}

t_ok($hiring !== null, 'the hiring advisory fires when people left and nothing is posted');
t_ok(str_contains($hiring['message'], '2'),
    'the message names how many left recently, not the one from two years ago');

/* Spec section 6.5: every advisory carries the figures that triggered it. */
t_ok($hiring['evidence'] !== [], 'it carries its evidence');

foreach ($hiring['evidence'] as [$label, $value]) {
    t_ok($label !== '' && $value !== '', 'each piece of evidence is a label and a figure');
}

/* It suggests; it does not act. */
t_ok(isset($hiring['link']['href']), 'it links to where the work is done');
t_ok(file_exists(__DIR__ . '/../../hr/' . $hiring['link']['href']),
    'and that page exists for HR');

/* Once a posting is open, the advice stops. */
$conn->query("INSERT INTO branch (company_id, branch_name, complete_address, province, city, status)
              VALUES ({$companyId}, 'Main', '1 St', 'Cavite', 'Imus', 'Active')");
$branchId = (int) $conn->insert_id;

$conn->query("INSERT INTO job (job_title, department, branch_id, vacancies, employment_type, status, application_deadline, company_id)
              VALUES ('Cashier', 'Cashier', {$branchId}, 1, 'Full Time', 'Published',
                      DATE_ADD(CURDATE(), INTERVAL 30 DAY), {$companyId})");

$ids = array_column(chatbotRunAdvisories($conn, $hrCtx), 'id');
t_ok(!in_array('hiring_needed', $ids, true),
    'with a posting open, the assistant stops suggesting one');

/* And the answer now shows the advice as rows, with the evidence visible. */
$conn->query("UPDATE job SET status = 'Draft' WHERE company_id = {$companyId}");
$answer = chatbotAnswer($conn, $hrCtx, 'what needs my attention')['answer'];

t_ok(count($answer['table']['rows'] ?? []) >= 1, 'the answer lists the advice');
t_ok(str_contains(json_encode($answer), 'hiring') || str_contains(json_encode($answer), 'posting'),
    'and names the hiring suggestion');

/* Nothing anywhere writes. */
$stmt = $conn->prepare("SELECT COUNT(*) AS jobs FROM job WHERE company_id = ?");
$stmt->bind_param("i", $companyId);
$stmt->execute();
t_same(1, (int) $stmt->get_result()->fetch_assoc()['jobs'],
    'asking for advice created no job posting');
$stmt->close();

t_done();
```

- [ ] **Step 2: Run it and watch it fail**

Run: `C:/xampp/php/php.exe tests/chatbot/test_advisories.php`
Expected: FAIL — `what_needs_attention` is not an intent; `chatbotRunAdvisories()` is undefined.

- [ ] **Step 3: Write the catalog and the runner**

`includes/chatbot/advisories.php`:

```php
<?php
/*
|--------------------------------------------------------------------------
| ADVISORIES
|--------------------------------------------------------------------------
|
| A recommendation drawn from the company's own data: "two people left this
| month and no job posting is open". Shown only when asked -- there is no
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
```

- [ ] **Step 4: Write the hiring advisory**

`includes/chatbot/advice/hr.php`:

```php
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
```

- [ ] **Step 5: Add the intent and wire it up**

In `includes/chatbot/intents.php`, after `salary_not_available`:

```php
        'what_needs_attention' => [
            'topic' => 'staff',
            'roles' => ['admin', 'hr', 'finance', 'inventory', 'cashier', 'employee'],
            'scope' => 'company',
            'label' => 'What needs attention',
            'keywords' => [
                ['needs attention', 'need attention', 'dapat kong gawin',
                 'dapat ba akong asikasuhin', 'asikasuhin', 'to do', 'pending items',
                 'anything for me', 'what should i do'],
            ],
            'handler' => 'chatbotWhatNeedsAttention',
        ],
```

In `includes/chatbot/engine.php`, beside the other requires:

```php
require_once __DIR__ . '/advisories.php';
require_once __DIR__ . '/advice/hr.php';
```

- [ ] **Step 6: Run the test**

Run: `C:/xampp/php/php.exe tests/chatbot/test_advisories.php`
Expected: PASS.

- [ ] **Step 7: Checkpoint**

Run: `C:/xampp/php/php.exe tests/chatbot/run_all.php` and `python tests/chatbot/query_audit.py` — expect `ALL TESTS PASSED` and `0 problem(s)`. The audit is what proves the advisory reads and never writes.

---

### Task 2: The rest of HR's advisories

**Files:**
- Modify: `includes/chatbot/advisories.php` (three entries)
- Modify: `includes/chatbot/advice/hr.php`
- Test: `tests/chatbot/test_advisories_hr.php`

**Interfaces:**
- Produces: `chatbotAdviseStaleApplicants`, `chatbotAdviseLeaveWaiting`, `chatbotAdviseIncompleteRecords`, each `(mysqli $conn, array $ctx): ?array`.

- [ ] **Step 1: Write the failing test**

`tests/chatbot/test_advisories_hr.php`:

```php
<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/factory.php';
require_once __DIR__ . '/../../includes/chatbot/engine.php';

register_shutdown_function(function () use ($conn) { testCleanup($conn); });

$companyId = testMakeCompany($conn, 'Advisory HR Co', 2);
$ctx = ['company_id' => $companyId, 'user_id' => 1, 'employee_id' => null, 'role' => 'hr'];

$conn->query("INSERT INTO branch (company_id, branch_name, complete_address, province, city, status)
              VALUES ({$companyId}, 'Main', '1 St', 'Cavite', 'Imus', 'Active')");
$branchId = (int) $conn->insert_id;

$conn->query("INSERT INTO employees (company_id, first_name, last_name, branch_id, employment_status, email, phone)
              VALUES ({$companyId}, 'Ana', 'Cruz', {$branchId}, 'Official Employee', 'a@test.local', '0917')");
$ana = (int) $conn->insert_id;

function adviceById(array $advice, string $id): ?array
{
    foreach ($advice as $entry) {
        if ($entry['id'] === $id) {
            return $entry;
        }
    }

    return null;
}

/* Nothing is wrong yet. */
t_same(null, adviceById(chatbotRunAdvisories($conn, $ctx), 'applicants_waiting'),
    'no applicant advice when there are no applicants');
t_same(null, adviceById(chatbotRunAdvisories($conn, $ctx), 'leave_waiting'),
    'no leave advice when nothing is pending');
t_same(null, adviceById(chatbotRunAdvisories($conn, $ctx), 'incomplete_records'),
    'no records advice when every record is complete');

/* An applicant nobody has moved for ten days. */
$conn->query("INSERT INTO job (job_title, department, branch_id, vacancies, employment_type, status, application_deadline, company_id)
              VALUES ('Cashier', 'Cashier', {$branchId}, 1, 'Full Time', 'Published',
                      DATE_ADD(CURDATE(), INTERVAL 30 DAY), {$companyId})");
$jobId = (int) $conn->insert_id;

$conn->query("INSERT INTO applications (job_id, first_name, last_name, email, status, applied_at, company_id)
              VALUES ({$jobId}, 'Juan', 'Dela Cruz', 'j@test.local', 'Pending',
                      DATE_SUB(NOW(), INTERVAL 10 DAY), {$companyId})");

/* And one who applied today, who is not stale. */
$conn->query("INSERT INTO applications (job_id, first_name, last_name, email, status, applied_at, company_id)
              VALUES ({$jobId}, 'Maria', 'Santos', 'm@test.local', 'Pending', NOW(), {$companyId})");

$advice = adviceById(chatbotRunAdvisories($conn, $ctx), 'applicants_waiting');
t_ok($advice !== null, 'the stale applicant is flagged');

/* Review Focus 2: the figure shown is the figure judged. */
$evidence = [];

foreach ($advice['evidence'] as [$label, $value]) {
    $evidence[$label] = $value;
}

t_same('1', $evidence['Waiting longer than a week'] ?? null,
    'one applicant is stale, not both');

/* Leave waiting for HR. */
$conn->query("INSERT INTO leave_requests (employee_id, leave_type, duration, start_date, end_date, reason, hr_status, admin_status, company_id)
              VALUES ({$ana}, 'Sick Leave', 'Whole Day', CURDATE(), CURDATE(), 'r', 'Pending', 'Pending', {$companyId})");

$advice = adviceById(chatbotRunAdvisories($conn, $ctx), 'leave_waiting');
t_ok($advice !== null, 'the pending leave is flagged');
t_ok(str_contains($advice['message'], '1'), 'and counted');

/* An employee with no contact details. */
$conn->query("INSERT INTO employees (company_id, first_name, last_name, employment_status)
              VALUES ({$companyId}, 'Ben', 'Santos', 'Official Employee')");

$advice = adviceById(chatbotRunAdvisories($conn, $ctx), 'incomplete_records');
t_ok($advice !== null, 'the incomplete record is flagged');

/* Every advisory links somewhere HR can actually go. */
foreach (chatbotRunAdvisories($conn, $ctx) as $entry) {
    t_ok(!empty($entry['link']['href']), "{$entry['id']} links somewhere");
    t_ok(file_exists(__DIR__ . '/../../hr/' . $entry['link']['href']),
        "{$entry['id']} links to a page that exists for HR");
    t_ok($entry['evidence'] !== [], "{$entry['id']} carries its evidence");
}

t_done();
```

- [ ] **Step 2: Run it and watch it fail**

Run: `C:/xampp/php/php.exe tests/chatbot/test_advisories_hr.php`
Expected: FAIL — the three advisories do not exist.

- [ ] **Step 3: Add the three entries**

In `chatbotAdvisories()`:

```php
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
```

- [ ] **Step 4: Write the three checks**

Append to `includes/chatbot/advice/hr.php`:

```php
/**
 * Applicants left at the same stage for longer than a week.
 */
function chatbotAdviseStaleApplicants(mysqli $conn, array $ctx): ?array
{
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS stale, MIN(DATEDIFF(NOW(), applied_at)) AS newest_days,
               MAX(DATEDIFF(NOW(), applied_at)) AS oldest_days
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
```

- [ ] **Step 5: Run the test**

Run: `C:/xampp/php/php.exe tests/chatbot/test_advisories_hr.php`
Expected: PASS.

- [ ] **Step 6: Checkpoint**

Run the suite and the audit.

---

### Task 3: The owner's advisories

**Files:**
- Modify: `includes/chatbot/advisories.php`
- Create: `includes/chatbot/advice/admin.php`
- Modify: `includes/chatbot/engine.php` (require)
- Test: `tests/chatbot/test_advisories_admin.php`

**Interfaces:**
- Produces: `chatbotAdviseApprovalsWaiting`, `chatbotAdviseLowStock`, `chatbotAdviseLowCapital`, `chatbotAdviseSubscription`, each `(mysqli $conn, array $ctx): ?array`.

- [ ] **Step 1: Write the failing test**

`tests/chatbot/test_advisories_admin.php`:

```php
<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/factory.php';
require_once __DIR__ . '/../../includes/chatbot/engine.php';

register_shutdown_function(function () use ($conn) { testCleanup($conn); });

$companyId = testMakeCompany($conn, 'Advisory Admin Co', 2);
$ctx = ['company_id' => $companyId, 'user_id' => 1, 'employee_id' => null, 'role' => 'admin'];

function adminAdvice(mysqli $conn, array $ctx, string $id): ?array
{
    foreach (chatbotRunAdvisories($conn, $ctx) as $entry) {
        if ($entry['id'] === $id) {
            return $entry;
        }
    }

    return null;
}

/* A stock request waiting for the owner. */
t_same(null, adminAdvice($conn, $ctx, 'approvals_waiting'), 'nothing waiting yet');

$conn->query("INSERT INTO stock_requests (request_code, total_price, reason, status, company_id)
              VALUES ('SR-ADV', 4000.00, 'restock', 'Pending Admin', {$companyId})");

$advice = adminAdvice($conn, $ctx, 'approvals_waiting');
t_ok($advice !== null, 'the owner is told an approval is waiting');
t_ok(str_contains(json_encode($advice['evidence']), '4,000.00')
     || str_contains(json_encode($advice['evidence']), '4000'),
    'with the amount at stake');

/* Products below their reorder level. */
$conn->query("INSERT INTO categories (category_name, company_id) VALUES ('C', {$companyId})");
$categoryId = (int) $conn->insert_id;
$conn->query("INSERT INTO products (product_name, category_id, company_id)
              VALUES ('Lucky Me', {$categoryId}, {$companyId})");
$productId = (int) $conn->insert_id;
$conn->query("INSERT INTO inventory (product_id, quantity, purchase_cost, selling_price, reorder_level, company_id)
              VALUES ({$productId}, 1, 9.00, 15.00, 5, {$companyId})");

$advice = adminAdvice($conn, $ctx, 'stock_low');
t_ok($advice !== null, 'the owner is told stock is running out');
t_ok(str_contains($advice['message'], '1'), 'and how many products');

/* Capital running low. */
$conn->query("INSERT INTO finance_capital (current_capital, company_id)
              VALUES (500.00, {$companyId})");

$advice = adminAdvice($conn, $ctx, 'capital_low');
t_ok($advice !== null, 'the owner is told capital is low');

/* A subscription about to renew. */
$conn->query("UPDATE company_subscriptions
                 SET expiry_date = DATE_ADD(CURDATE(), INTERVAL 5 DAY)
               WHERE company_id = {$companyId}");

$advice = adminAdvice($conn, $ctx, 'subscription_due');
t_ok($advice !== null, 'the owner is told the subscription is due');
t_ok(str_contains(json_encode($advice['evidence']), '5'), 'with how many days are left');

/* Everything the owner is shown links to a page that exists for them. */
foreach (chatbotRunAdvisories($conn, $ctx) as $entry) {

    if ($entry['link'] === null) {
        continue;
    }

    t_ok(file_exists(__DIR__ . '/../../admin/' . $entry['link']['href']),
        "{$entry['id']} links to a page that exists for the owner");
}

t_done();
```

- [ ] **Step 2: Run it and watch it fail**

Run: `C:/xampp/php/php.exe tests/chatbot/test_advisories_admin.php`
Expected: FAIL — none of the four advisories exist.

- [ ] **Step 3: Add the entries**

```php
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
```

- [ ] **Step 4: Write the four checks**

`includes/chatbot/advice/admin.php`:

```php
<?php
/*
| The owner's advisories: what is waiting on them, and what is running out.
*/

function chatbotAdviseApprovalsWaiting(mysqli $conn, array $ctx): ?array
{
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS waiting,
               COALESCE(SUM(total_price), 0) AS amount,
               COALESCE(MAX(DATEDIFF(NOW(), created_at)), 0) AS oldest_days
        FROM stock_requests
        WHERE company_id = ? AND status = 'Pending Admin'
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
        'message' => $waiting . ' stock request(s) are waiting for your approval.',
        'evidence' => [
            ['Waiting', (string) $waiting],
            ['Amount', chatbotPeso((float) $row['amount'])],
            ['Oldest', (int) $row['oldest_days'] . ' days'],
        ],
    ];
}

function chatbotAdviseLowStock(mysqli $conn, array $ctx): ?array
{
    $stmt = $conn->prepare("
        SELECT
            COALESCE(SUM(i.quantity > 0 AND i.quantity <= COALESCE(i.reorder_level, 5)), 0) AS low,
            COALESCE(SUM(i.quantity <= 0), 0) AS out
        FROM inventory i
        WHERE i.company_id = ?
    ");
    $stmt->bind_param("i", $ctx['company_id']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $low = (int) $row['low'];
    $out = (int) $row['out'];

    if ($low === 0 && $out === 0) {
        return null;
    }

    return [
        'message' => ($low + $out) . ' product(s) need restocking.',
        'evidence' => [
            ['At or below reorder level', (string) $low],
            ['Out of stock', (string) $out],
        ],
    ];
}

function chatbotAdviseLowCapital(mysqli $conn, array $ctx): ?array
{
    $stmt = $conn->prepare("
        SELECT current_capital
        FROM finance_capital
        WHERE company_id = ?
        ORDER BY updated_at DESC
        LIMIT 1
    ");
    $stmt->bind_param("i", $ctx['company_id']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return null;
    }

    $capital = (float) $row['current_capital'];

    if ($capital > ADVICE_LOW_CAPITAL) {
        return null;
    }

    return [
        'message' => 'Your capital is running low.',
        'evidence' => [
            ['Capital', chatbotPeso($capital)],
            ['Warning level', chatbotPeso(ADVICE_LOW_CAPITAL)],
        ],
    ];
}

function chatbotAdviseSubscription(mysqli $conn, array $ctx): ?array
{
    $stmt = $conn->prepare("
        SELECT expiry_date, DATEDIFF(expiry_date, CURDATE()) AS days_left
        FROM company_subscriptions
        WHERE company_id = ? AND status IN ('Active', 'Trial')
        ORDER BY expiry_date DESC
        LIMIT 1
    ");
    $stmt->bind_param("i", $ctx['company_id']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return null;
    }

    $daysLeft = (int) $row['days_left'];

    if ($daysLeft > ADVICE_SUBSCRIPTION_DAYS || $daysLeft < 0) {
        return null;
    }

    return [
        'message' => 'Your subscription renews soon.',
        'evidence' => [
            ['Days left', (string) $daysLeft],
            ['Renews on', (string) $row['expiry_date']],
        ],
    ];
}
```

Add the require in `engine.php`.

- [ ] **Step 5: Run the test**

Run: `C:/xampp/php/php.exe tests/chatbot/test_advisories_admin.php`
Expected: PASS.

- [ ] **Step 6: Checkpoint**

Run the suite and the audit.

---

### Task 4: Finance and Inventory advisories

**Files:**
- Modify: `includes/chatbot/advisories.php`
- Create: `includes/chatbot/advice/operations.php`
- Modify: `includes/chatbot/engine.php` (require)
- Test: `tests/chatbot/test_advisories_ops.php`

**Interfaces:**
- Produces: `chatbotAdvisePayrollWaiting`, `chatbotAdviseFinanceRequests`, `chatbotAdvisePayablesDue`, `chatbotAdviseDeliveriesReady`, each `(mysqli $conn, array $ctx): ?array`.

- [ ] **Step 1: Write the failing test**

`tests/chatbot/test_advisories_ops.php`:

```php
<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/factory.php';
require_once __DIR__ . '/../../includes/chatbot/engine.php';

register_shutdown_function(function () use ($conn) { testCleanup($conn); });

$companyId = testMakeCompany($conn, 'Advisory Ops Co', 2);
$financeCtx = ['company_id' => $companyId, 'user_id' => 1, 'employee_id' => null, 'role' => 'finance'];
$invCtx = ['company_id' => $companyId, 'user_id' => 1, 'employee_id' => null, 'role' => 'inventory'];

function opsAdvice(mysqli $conn, array $ctx, string $id): ?array
{
    foreach (chatbotRunAdvisories($conn, $ctx) as $entry) {
        if ($entry['id'] === $id) {
            return $entry;
        }
    }

    return null;
}

$conn->query("INSERT INTO employees (company_id, first_name, last_name, employment_status)
              VALUES ({$companyId}, 'Ana', 'Cruz', 'Official Employee')");
$ana = (int) $conn->insert_id;

/* Payroll waiting for Finance. */
$conn->query("INSERT INTO payroll (employee_id, payroll_period_start, payroll_period_end, working_days,
                                   basic_pay, overtime_pay, gross_pay, late_deduction, undertime_deduction,
                                   absent_deduction, sss, philhealth, pagibig, total_deduction, net_pay,
                                   status, company_id)
              VALUES ({$ana}, DATE_FORMAT(CURDATE(), '%Y-%m-01'), LAST_DAY(CURDATE()), 22,
                      9000, 0, 9000, 0, 0, 0, 0, 0, 0, 0, 9000, 'Pending Approval', {$companyId})");

$advice = opsAdvice($conn, $financeCtx, 'payroll_waiting');
t_ok($advice !== null, 'finance is told payroll is waiting');
t_ok(str_contains($advice['message'], '1'), 'and how many payslips');

/* A stock request for finance, and a payable falling due. */
$conn->query("INSERT INTO stock_requests (request_code, total_price, reason, status, company_id)
              VALUES ('SR-OPS', 2500.00, 'restock', 'Pending Finance', {$companyId})");
$requestId = (int) $conn->insert_id;

$conn->query("INSERT INTO stock_request_items (request_id, item_description, vendor, quantity, unit_price, total_price, company_id)
              VALUES ({$requestId}, 'Rice', 'V', 5, 500.00, 2500.00, {$companyId})");
$itemId = (int) $conn->insert_id;

$conn->query("INSERT INTO accounts_payable (request_id, item_id, invoice_no, po_number, supplier,
                                            category, description, amount, paid_amount, due_date, status, company_id)
              VALUES ({$requestId}, {$itemId}, 'INV-OPS', 'PO-OPS', 'Supplier A', 'Stock', 'd',
                      2500.00, 0.00, DATE_ADD(CURDATE(), INTERVAL 3 DAY), 'Pending', {$companyId})");

t_ok(opsAdvice($conn, $financeCtx, 'finance_requests') !== null,
    'finance is told a stock request needs them');

$advice = opsAdvice($conn, $financeCtx, 'payables_due');
t_ok($advice !== null, 'finance is told a bill falls due soon');
t_ok(str_contains(json_encode($advice['evidence']), '2,500.00'), 'with the amount');

/* A delivery the inventory staff can receive. */
$conn->query("INSERT INTO stock_requests (request_code, total_price, reason, status, company_id)
              VALUES ('SR-RECV', 1000.00, 'restock', 'Admin Approved', {$companyId})");

$advice = opsAdvice($conn, $invCtx, 'deliveries_ready');
t_ok($advice !== null, 'inventory is told a delivery is ready to receive');

/* Review Focus 3: the matrix holds. A cashier is told nothing about payroll. */
$cashierCtx = ['company_id' => $companyId, 'user_id' => 1, 'employee_id' => $ana, 'role' => 'cashier'];
$ids = array_column(chatbotRunAdvisories($conn, $cashierCtx), 'id');

foreach (['payroll_waiting', 'finance_requests', 'payables_due', 'approvals_waiting'] as $forbidden) {
    t_ok(!in_array($forbidden, $ids, true), "a cashier is never shown {$forbidden}");
}

/* And inventory is told nothing about money. */
$ids = array_column(chatbotRunAdvisories($conn, $invCtx), 'id');

foreach (['payroll_waiting', 'payables_due', 'capital_low'] as $forbidden) {
    t_ok(!in_array($forbidden, $ids, true), "inventory is never shown {$forbidden}");
}

t_done();
```

- [ ] **Step 2: Run it and watch it fail**

Run: `C:/xampp/php/php.exe tests/chatbot/test_advisories_ops.php`
Expected: FAIL — the four advisories do not exist.

- [ ] **Step 3: Add the entries**

```php
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
```

- [ ] **Step 4: Write the four checks**

`includes/chatbot/advice/operations.php`:

```php
<?php
/*
| Finance and Inventory advisories: money waiting to move, and goods waiting
| to arrive.
|
| chatbotAdvisePayrollWaiting reads the payroll table. That is allowed here for
| the same reason the Finance keyword answers are: this file is under
| includes/chatbot/, not under chat/tools, so nothing it reads is ever sent to
| a model. See the conversational spec, "Payroll: answerable, but never by the
| AI".
*/

function chatbotAdvisePayrollWaiting(mysqli $conn, array $ctx): ?array
{
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS waiting,
               COALESCE(MAX(DATEDIFF(NOW(), created_at)), 0) AS oldest_days
        FROM payroll
        WHERE company_id = ? AND status = 'Pending Approval'
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
        'message' => $waiting . ' payslip(s) are waiting for approval.',
        'evidence' => [
            ['Waiting', (string) $waiting],
            ['Oldest', (int) $row['oldest_days'] . ' days'],
        ],
    ];
}

function chatbotAdviseFinanceRequests(mysqli $conn, array $ctx): ?array
{
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS waiting, COALESCE(SUM(total_price), 0) AS amount
        FROM stock_requests
        WHERE company_id = ? AND status = 'Pending Finance'
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
        'message' => $waiting . ' stock request(s) are waiting for finance.',
        'evidence' => [
            ['Waiting', (string) $waiting],
            ['Amount', chatbotPeso((float) $row['amount'])],
        ],
    ];
}

function chatbotAdvisePayablesDue(mysqli $conn, array $ctx): ?array
{
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS due,
               COALESCE(SUM(amount - paid_amount), 0) AS balance,
               COALESCE(SUM(due_date < CURDATE()), 0) AS overdue
        FROM accounts_payable
        WHERE company_id = ?
          AND status IN ('Pending', 'Partial')
          AND due_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
    ");
    $days = ADVICE_PAYABLE_DUE_DAYS;
    $stmt->bind_param("ii", $ctx['company_id'], $days);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $due = (int) $row['due'];

    if ($due === 0) {
        return null;
    }

    return [
        'message' => $due . ' supplier bill(s) fall due within a week.',
        'evidence' => [
            ['Falling due', (string) $due],
            ['Already overdue', (string) (int) $row['overdue']],
            ['Amount', chatbotPeso((float) $row['balance'])],
        ],
    ];
}

function chatbotAdviseDeliveriesReady(mysqli $conn, array $ctx): ?array
{
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS ready, COALESCE(SUM(total_price), 0) AS amount
        FROM stock_requests
        WHERE company_id = ? AND status = 'Admin Approved'
    ");
    $stmt->bind_param("i", $ctx['company_id']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $ready = (int) $row['ready'];

    if ($ready === 0) {
        return null;
    }

    return [
        'message' => $ready . ' approved stock request(s) are ready to receive.',
        'evidence' => [
            ['Ready to receive', (string) $ready],
            ['Amount', chatbotPeso((float) $row['amount'])],
        ],
    ];
}
```

Add the require in `engine.php`.

- [ ] **Step 5: Run the test**

Run: `C:/xampp/php/php.exe tests/chatbot/test_advisories_ops.php`
Expected: PASS.

- [ ] **Step 6: Checkpoint**

Run the suite and the audit.

---

### Task 5: The personal advisories

**Files:**
- Modify: `includes/chatbot/advisories.php`
- Create: `includes/chatbot/advice/personal.php`
- Modify: `includes/chatbot/engine.php` (require)
- Test: `tests/chatbot/test_advisories_personal.php`

**Interfaces:**
- Produces: `chatbotAdviseMissingTimeOut`, `chatbotAdviseMyLeavePending`, each `(mysqli $conn, array $ctx): ?array`.

- [ ] **Step 1: Write the failing test**

`tests/chatbot/test_advisories_personal.php`:

```php
<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/factory.php';
require_once __DIR__ . '/../../includes/chatbot/engine.php';

register_shutdown_function(function () use ($conn) { testCleanup($conn); });

$companyId = testMakeCompany($conn, 'Advisory Personal Co', 2);

$conn->query("INSERT INTO employees (company_id, first_name, last_name, employment_status)
              VALUES ({$companyId}, 'Ana', 'Cruz', 'Official Employee')");
$mine = (int) $conn->insert_id;

$conn->query("INSERT INTO employees (company_id, first_name, last_name, employment_status)
              VALUES ({$companyId}, 'Ben', 'Santos', 'Official Employee')");
$colleague = (int) $conn->insert_id;

$ctx = ['company_id' => $companyId, 'user_id' => 5, 'employee_id' => $mine, 'role' => 'cashier'];

function personalAdvice(mysqli $conn, array $ctx, string $id): ?array
{
    foreach (chatbotRunAdvisories($conn, $ctx) as $entry) {
        if ($entry['id'] === $id) {
            return $entry;
        }
    }

    return null;
}

t_same(null, personalAdvice($conn, $ctx, 'my_time_out_missing'),
    'nothing to say before anyone clocks in');

/* I clocked in and never out; my colleague did both. */
$conn->query("INSERT INTO attendance (employee_id, attendance_date, time_in, status, company_id)
              VALUES ({$mine}, CURDATE(), CONCAT(CURDATE(), ' 08:00:00'), 'Present', {$companyId})");
$conn->query("INSERT INTO attendance (employee_id, attendance_date, time_in, time_out, status, company_id)
              VALUES ({$colleague}, CURDATE(), CONCAT(CURDATE(), ' 08:00:00'),
                      CONCAT(CURDATE(), ' 17:00:00'), 'Present', {$companyId})");

$advice = personalAdvice($conn, $ctx, 'my_time_out_missing');
t_ok($advice !== null, 'I am reminded that my time out is missing');
t_ok(str_contains(json_encode($advice['evidence']), '08:00'), 'with the time I clocked in');

/* My colleague is not reminded about mine. */
$colleagueCtx = ['company_id' => $companyId, 'user_id' => 6,
                 'employee_id' => $colleague, 'role' => 'cashier'];
t_same(null, personalAdvice($conn, $colleagueCtx, 'my_time_out_missing'),
    "a colleague is not shown my missing time out");

/* My own pending leave. */
$conn->query("INSERT INTO leave_requests (employee_id, leave_type, duration, start_date, end_date, reason, hr_status, admin_status, company_id)
              VALUES ({$mine}, 'Sick Leave', 'Whole Day', CURDATE(), CURDATE(), 'r', 'Pending', 'Pending', {$companyId})");

t_ok(personalAdvice($conn, $ctx, 'my_leave_pending') !== null,
    'I am told my leave is still pending');
t_same(null, personalAdvice($conn, $colleagueCtx, 'my_leave_pending'),
    'and my colleague is not');

/* An account with no employee record is shown no personal advice at all. */
$ownerCtx = ['company_id' => $companyId, 'user_id' => 9, 'employee_id' => null, 'role' => 'employee'];
$ids = array_column(chatbotRunAdvisories($conn, $ownerCtx), 'id');

t_ok(!in_array('my_time_out_missing', $ids, true),
    'an account with no employee record gets no personal advice');
t_ok(!in_array('my_leave_pending', $ids, true), 'neither kind');

t_done();
```

- [ ] **Step 2: Run it and watch it fail**

Run: `C:/xampp/php/php.exe tests/chatbot/test_advisories_personal.php`
Expected: FAIL — neither advisory exists.

- [ ] **Step 3: Add the entries**

```php
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
```

- [ ] **Step 4: Write the two checks**

`includes/chatbot/advice/personal.php`:

```php
<?php
/*
| The asker's own advisories.
|
| Both bind company_id AND the asker's employee_id: a reminder about somebody
| else's timesheet is not a reminder, it is a leak.
*/

function chatbotAdviseMissingTimeOut(mysqli $conn, array $ctx): ?array
{
    $stmt = $conn->prepare("
        SELECT TIME(time_in) AS time_in
        FROM attendance
        WHERE company_id = ? AND employee_id = ?
          AND attendance_date = CURDATE()
          AND time_in IS NOT NULL
          AND time_out IS NULL
        LIMIT 1
    ");
    $stmt->bind_param("ii", $ctx['company_id'], $ctx['employee_id']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return null;
    }

    return [
        'message' => 'You have not timed out today.',
        'evidence' => [
            ['Timed in at', (string) $row['time_in']],
            ['Timed out', 'Not yet'],
        ],
    ];
}

function chatbotAdviseMyLeavePending(mysqli $conn, array $ctx): ?array
{
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS pending,
               COALESCE(MAX(DATEDIFF(NOW(), created_at)), 0) AS oldest_days
        FROM leave_requests
        WHERE company_id = ? AND employee_id = ?
          AND (hr_status = 'Pending' OR admin_status = 'Pending')
    ");
    $stmt->bind_param("ii", $ctx['company_id'], $ctx['employee_id']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $pending = (int) $row['pending'];

    if ($pending === 0) {
        return null;
    }

    return [
        'message' => 'Your leave request is still pending.',
        'evidence' => [
            ['Pending requests', (string) $pending],
            ['Waiting', (int) $row['oldest_days'] . ' days'],
        ],
    ];
}
```

Add the require in `engine.php`.

- [ ] **Step 5: Run the test**

Run: `C:/xampp/php/php.exe tests/chatbot/test_advisories_personal.php`
Expected: PASS.

- [ ] **Step 6: Checkpoint**

Run the suite and the audit.

---

### Task 6: The matrix, the plan gate, and the tenant boundary

**Files:**
- Test: `tests/chatbot/test_advisories_matrix.php`
- Modify: `tests/chatbot/test_chat_isolation.php`

- [ ] **Step 1: Write the matrix and plan test**

`tests/chatbot/test_advisories_matrix.php`:

```php
<?php
/*
| Who may be shown what, and on which plan. An advisory is not a way around
| the matrix.
*/
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/factory.php';
require_once __DIR__ . '/../../includes/chatbot/engine.php';

register_shutdown_function(function () use ($conn) { testCleanup($conn); });

$professional = testMakeCompany($conn, 'Advisory Matrix Pro', 2);
$starter = testMakeCompany($conn, 'Advisory Matrix Starter', 1);

$matrix = [
    'hr' => ['hiring_needed' => true, 'applicants_waiting' => true,
             'leave_waiting' => true, 'incomplete_records' => true,
             'payroll_waiting' => false, 'payables_due' => false,
             'approvals_waiting' => false, 'capital_low' => false],
    'finance' => ['payroll_waiting' => true, 'finance_requests' => true,
                  'payables_due' => true,
                  'hiring_needed' => false, 'applicants_waiting' => false,
                  'incomplete_records' => false, 'stock_low' => false],
    'inventory' => ['stock_low' => true, 'deliveries_ready' => true,
                    'payroll_waiting' => false, 'payables_due' => false,
                    'hiring_needed' => false, 'capital_low' => false],
    'cashier' => ['my_time_out_missing' => true, 'my_leave_pending' => true,
                  'payroll_waiting' => false, 'stock_low' => false,
                  'hiring_needed' => false, 'approvals_waiting' => false],
    'employee' => ['my_time_out_missing' => true, 'my_leave_pending' => true,
                   'stock_low' => false, 'leave_waiting' => false],
    'admin' => ['approvals_waiting' => true, 'stock_low' => true,
                'capital_low' => true, 'subscription_due' => true,
                'hiring_needed' => true, 'payroll_waiting' => true,
                'incomplete_records' => false, 'my_time_out_missing' => false],
];

foreach ($matrix as $role => $expectations) {

    $ctx = ['company_id' => $professional, 'user_id' => 1,
            'employee_id' => 1, 'role' => $role];
    $allowed = chatbotAdvisoriesFor($conn, $ctx);

    foreach ($expectations as $advisory => $maySee) {
        t_same($maySee, isset($allowed[$advisory]),
            "{$role} " . ($maySee ? 'may' : 'may NOT') . " be shown {$advisory}");
    }
}

/* Review Focus 4: Retail Starter bought pos, inventory, staff and reports. */
$starterAdmin = ['company_id' => $starter, 'user_id' => 1,
                 'employee_id' => 1, 'role' => 'admin'];
$allowed = chatbotAdvisoriesFor($conn, $starterAdmin);

foreach (['hiring_needed', 'applicants_waiting', 'leave_waiting',
          'payroll_waiting', 'payables_due', 'finance_requests', 'capital_low'] as $advisory) {
    t_ok(!isset($allowed[$advisory]), "a Starter owner is not shown {$advisory}");
}

foreach (['approvals_waiting', 'stock_low', 'subscription_due'] as $advisory) {
    t_ok(isset($allowed[$advisory]), "but keeps {$advisory}, which their plan covers");
}

/* Every advisory names a check that exists, and a page that resolves. */
$folders = ['admin' => 'admin', 'hr' => 'hr', 'finance' => 'finance',
            'inventory' => 'inventory', 'cashier' => 'cashier', 'employee' => 'employee'];

foreach (chatbotAdvisories() as $id => $advisory) {
    t_ok(function_exists($advisory['check']), "{$id}'s check is a real function");
    t_ok(in_array($advisory['scope'], ['company', 'own'], true), "{$id} has a valid scope");
    t_ok($advisory['roles'] !== [], "{$id} names at least one role");

    foreach ($advisory['roles'] as $role) {

        if ($advisory['page'] === null) {
            continue;
        }

        $link = chatbotPageLink(['role' => $role], $advisory['page']);

        if ($link === null) {
            continue;
        }

        t_ok(file_exists(__DIR__ . '/../../' . $folders[$role] . '/' . $link['href']),
            "{$id} links to a page that exists for {$role}");
    }
}

t_done();
```

- [ ] **Step 2: Run it**

Run: `C:/xampp/php/php.exe tests/chatbot/test_advisories_matrix.php`
Expected: PASS. If a role/plan row fails, fix the catalog's `roles` or `topic` — never the assertion.

- [ ] **Step 3: Two companies, the same situation**

Append to `tests/chatbot/test_advisories_matrix.php`, before `t_done()`:

```php
/*
| Review Focus 5: both companies are given the SAME situation, so an advisory
| that counts across them shows up as a doubled figure -- there is no foreign
| name to look for.
*/
$alpha = testMakeCompany($conn, 'Advisory Alpha', 2);
$beta = testMakeCompany($conn, 'Advisory Beta', 2);

foreach ([$alpha, $beta] as $companyId) {

    $conn->query("INSERT INTO branch (company_id, branch_name, complete_address, province, city, status)
                  VALUES ({$companyId}, 'Main', '1 St', 'Cavite', 'Imus', 'Active')");
    $branchId = (int) $conn->insert_id;

    $conn->query("INSERT INTO job (job_title, department, branch_id, vacancies, employment_type, status, application_deadline, company_id)
                  VALUES ('Cashier', 'Cashier', {$branchId}, 1, 'Full Time', 'Published',
                          DATE_ADD(CURDATE(), INTERVAL 30 DAY), {$companyId})");
    $jobId = (int) $conn->insert_id;

    /* One stale applicant each. */
    $conn->query("INSERT INTO applications (job_id, first_name, last_name, email, status, applied_at, company_id)
                  VALUES ({$jobId}, 'A', 'Applicant', 'a@test.local', 'Pending',
                          DATE_SUB(NOW(), INTERVAL 30 DAY), {$companyId})");

    /* One product below its reorder level each. */
    $conn->query("INSERT INTO categories (category_name, company_id) VALUES ('C', {$companyId})");
    $categoryId = (int) $conn->insert_id;
    $conn->query("INSERT INTO products (product_name, category_id, company_id)
                  VALUES ('P', {$categoryId}, {$companyId})");
    $productId = (int) $conn->insert_id;
    $conn->query("INSERT INTO inventory (product_id, quantity, purchase_cost, selling_price, reorder_level, company_id)
                  VALUES ({$productId}, 1, 1.00, 2.00, 5, {$companyId})");
}

foreach ([$alpha, $beta] as $companyId) {

    $hrCtx = ['company_id' => $companyId, 'user_id' => 1, 'employee_id' => null, 'role' => 'hr'];
    $invCtx = ['company_id' => $companyId, 'user_id' => 1, 'employee_id' => null, 'role' => 'inventory'];

    foreach ([[$hrCtx, 'applicants_waiting', 'Waiting longer than a week'],
              [$invCtx, 'stock_low', 'At or below reorder level']] as [$ctx, $id, $label]) {

        $found = null;

        foreach (chatbotRunAdvisories($conn, $ctx) as $entry) {
            if ($entry['id'] === $id) {
                $found = $entry;
            }
        }

        t_ok($found !== null, "{$id} fires for company {$companyId}");

        foreach ($found['evidence'] as [$evidenceLabel, $value]) {
            if ($evidenceLabel === $label) {
                t_same('1', $value, "{$id}: {$label} counts one company's work, not two");
            }
        }
    }
}

```

- [ ] **Step 4: Run the isolation test**

Run: `C:/xampp/php/php.exe tests/chatbot/test_chat_isolation.php`
Expected: PASS.

- [ ] **Step 5: Prove an advisory can fail**

Temporarily change `chatbotAdviseStaleApplicants`'s `WHERE company_id = ?` to
`WHERE ? IS NOT NULL`. Run the isolation test: expected FAIL on the doubled
count. Run the audit: expected 1 problem. **Restore** and re-run both.

- [ ] **Step 6: Full verification**

Run:
```bash
C:/xampp/php/php.exe tests/chatbot/run_all.php
python tests/chatbot/query_audit.py
C:/xampp/mysql/bin/mysql.exe -uroot -N sari -e "SELECT COUNT(*) FROM company WHERE company_name LIKE 'CHATBOT-TEST %';"
```
Expected: `ALL TESTS PASSED`, `0 problem(s)`, `0`.

- [ ] **Step 7: The owner's browser pass**

Sign in as HR and ask "what needs my attention" — expect either the suggestions
with their figures, or a plain "nothing needs your attention right now". Archive
an employee, close every posting, and ask again: the hiring suggestion appears
with the number of people who left. Publish a posting and ask once more: it is
gone. Nothing in the database changes at any point.

---

## What Phase 2B leaves out

- Any unprompted notification: no badge, no pop-up, no email. Spec §1 non-goals.
- Any advisory that acts. Every one of them links to a page where a person does
  the work.
- Retail Enterprise's cross-branch advisories.
