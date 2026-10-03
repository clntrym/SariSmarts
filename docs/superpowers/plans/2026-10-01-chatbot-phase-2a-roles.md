# Chatbot Phase 2A — HR, Finance and Inventory roles

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the HR Officer, Finance Staff and Inventory Staff the assistant their matrix promises — including Finance's payroll answers, which stay on the keyword path and never reach the model.

**Architecture:** Two layers already exist and are extended, not rebuilt. The keyword catalog (`includes/chatbot/intents.php` + `answers/`) gains the fixed questions each role needs, including the payroll ones marked `local_only`. The tool catalog (`includes/chatbot/chat/tools.php` + `chat/tools/`) gains the non-pay tools for those roles. `ask.php` learns to answer a `local_only` match from Phase 1 without consulting the model.

**Tech Stack:** PHP 8.2.12 (XAMPP), MariaDB 10.4, mysqli. Tests: the CLI harness in `tests/chatbot/bootstrap.php`, plus `tests/chatbot/query_audit.py`.

**Spec:** `docs/superpowers/specs/2026-09-29-sarismarts-chatbot-design.md` §6 (role matrix) and `docs/superpowers/specs/2026-09-30-sarismarts-chatbot-conversational-design.md` §3 (tool catalog, and "Payroll: answerable, but never by the AI")

## Global Constraints

- PHP `C:/xampp/php/php.exe`; MySQL `C:/xampp/mysql/bin/mysql.exe -uroot sari`; `$conn` from `config.php` via `init.php`.
- Context array is exactly `['company_id' => int, 'user_id' => int, 'employee_id' => ?int, 'role' => string]`.
- Every statement: prepared `SELECT`, `company_id = ?` bound, columns named, and for tools `LIMIT min($limit, CHAT_TOOL_ROW_CAP) + 1` so the runner can detect truncation.
- **No file under `includes/chatbot/chat/tools*` may contain** `payroll`, `salary`, `basic_pay`, `gross_pay`, `net_pay`, `overtime_pay`, `pay_frequency`, `late_deduction`, `undertime_deduction`, `absent_deduction`, `total_deduction`, `deduction_rate`. The audit enforces it. Payroll handlers therefore live in `includes/chatbot/answers/`, which the scan does not cover — that is the whole point of the split.
- Role slugs as stored: `admin`, `hr`, `finance`, `inventory`, `cashier`, `employee` (the `users.role` column also holds capitalised spellings; `ask.php` lowercases before use).
- Plan topics already seeded: `pos`, `inventory`, `staff`, `reports`, `hrms`, `recruitment`, `attendance`, `leave`, `payroll`, `finance`, `branch`, `cross_branch`.
- Live status vocabularies, read from the writing code — not guessed:
  - `payroll.status`: ENUM `Draft`, `Pending Approval`, `Approved`, `Returned`, `Released`
  - `accounts_payable.status`: ENUM `Pending`, `Partial`, `Paid`
  - `stock_requests.status`: ENUM `Pending Finance`, `Finance Approved`, `Finance Rejected`, `Pending Admin`, `Admin Approved`, `Admin Rejected`, `Received`, `Cancelled`
  - `applications.status`: `Pending`, `Interview`, `Interview Result`, `Recommended`, `Rejected`, `Hired`
  - `employees.employment_status`: `Pre-Employee`, `Official Employee`, `Archived`
- Not a git repository: each task ends with a *Checkpoint* (full suite + audit).
- Suite: `C:/xampp/php/php.exe tests/chatbot/run_all.php`; audit: `python tests/chatbot/query_audit.py`.

## Review Focus

1. **A payroll question asked while the conversational layer is ON** — it must be answered locally by the keyword path, not refused by a model that has no payroll tool. Test in Task 5.
2. **An HR officer asking a POS or finance question** — the matrix marks both ✗ for HR; a near-miss phrase must not slip through on a shared word. Test in Task 6.
3. **Finance asking for recruitment detail** — the matrix allows Finance only what payroll needs; applicant names and stages are HR's. Test in Task 6.
4. **Inventory Staff asking about employee salary or POS takings** — both ✗; and their supplier view is "view only". Test in Task 3.
5. **A Starter company with only Admin and Cashier seats** — none of these three roles exists there, and none of the new topics is on its plan; nothing may become reachable by adding roles to the catalog. Test in Task 6.

---

### Task 1: HR — the keyword questions

**Files:**
- Modify: `includes/chatbot/intents.php` (add six HR intents)
- Create: `includes/chatbot/answers/hr.php`
- Modify: `includes/chatbot/engine.php` (require the new answers file)
- Test: `tests/chatbot/test_hr_intents.php`

**Interfaces:**
- Consumes: `chatbotAnswer()`, `chatbotAllowedIntents()`.
- Produces, each `(mysqli $conn, array $ctx, string $question): array` returning the answer shape `['title','lines','table','link','note']`:
  `chatbotHrHeadcount`, `chatbotHrPendingLeave`, `chatbotHrAttendanceToday`, `chatbotHrApplicants`, `chatbotHrOpenJobs`, `chatbotHrIncompleteRecords`.

- [ ] **Step 1: Write the failing test**

`tests/chatbot/test_hr_intents.php`:

```php
<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/factory.php';
require_once __DIR__ . '/../../includes/chatbot/engine.php';

register_shutdown_function(function () use ($conn) { testCleanup($conn); });

$companyId = testMakeCompany($conn, 'HR Intents Co', 2);
$otherId = testMakeCompany($conn, 'HR Intents Other', 2);
$ctx = ['company_id' => $companyId, 'user_id' => 1, 'employee_id' => null, 'role' => 'hr'];

$conn->query("INSERT INTO branch (company_id, branch_name, complete_address, province, city, status)
              VALUES ({$companyId}, 'Main', '1 St', 'Cavite', 'Imus', 'Active')");
$branchId = (int) $conn->insert_id;

$conn->query("INSERT INTO employees (company_id, first_name, last_name, branch_id, employment_status, email, phone)
              VALUES ({$companyId}, 'Ana', 'Cruz', {$branchId}, 'Official Employee', 'ana@test.local', '09170000001')");
$ana = (int) $conn->insert_id;

/* Missing email and phone: an incomplete record. */
$conn->query("INSERT INTO employees (company_id, first_name, last_name, employment_status)
              VALUES ({$companyId}, 'Ben', 'Santos', 'Official Employee')");

$conn->query("INSERT INTO employees (company_id, first_name, last_name, employment_status)
              VALUES ({$otherId}, 'Outside', 'Person', 'Official Employee')");

$conn->query("INSERT INTO leave_requests (employee_id, leave_type, duration, start_date, end_date, reason, hr_status, admin_status, company_id)
              VALUES ({$ana}, 'Sick Leave', 'Whole Day', CURDATE(), CURDATE(), 'Lagnat', 'Pending', 'Pending', {$companyId})");

$conn->query("INSERT INTO attendance (employee_id, attendance_date, time_in, status, late_minutes, company_id)
              VALUES ({$ana}, CURDATE(), CONCAT(CURDATE(), ' 08:30:00'), 'Late', 30, {$companyId})");

$conn->query("INSERT INTO job (job_title, department, branch_id, vacancies, employment_type, status, application_deadline, company_id)
              VALUES ('Cashier', 'Cashier', {$branchId}, 2, 'Full Time', 'Published',
                      DATE_ADD(CURDATE(), INTERVAL 30 DAY), {$companyId})");
$jobId = (int) $conn->insert_id;

$conn->query("INSERT INTO applications (job_id, first_name, last_name, email, status, company_id)
              VALUES ({$jobId}, 'Juan', 'Dela Cruz', 'juan@test.local', 'Interview', {$companyId})");

$cases = [
    'ilan ang empleyado natin' => 'hr_headcount',
    'how many employees do we have' => 'hr_headcount',
    'which employees have pending leave requests' => 'hr_pending_leave',
    'sino ang may pending leave' => 'hr_pending_leave',
    'who is late today' => 'hr_attendance_today',
    'sino ang late ngayon' => 'hr_attendance_today',
    'how many applicants do we have' => 'hr_applicants',
    'ilan ang nag apply' => 'hr_applicants',
    'what job postings are open' => 'hr_open_jobs',
    'show employees with incomplete records' => 'hr_incomplete_records',
];

foreach ($cases as $question => $intent) {
    $result = chatbotAnswer($conn, $ctx, $question);
    t_same($intent, $result['intent'], "hr: \"{$question}\"");
    t_ok($result['ok'], "hr: \"{$question}\" is answered");
}

/* The answers are this company's own. */
$json = json_encode(chatbotAnswer($conn, $ctx, 'sino ang may pending leave')['answer']);
t_ok(str_contains($json, 'Ana Cruz'), 'the pending leave names our employee');
t_ok(!str_contains($json, 'Outside'), "and never another company's");

$json = json_encode(chatbotAnswer($conn, $ctx, 'show employees with incomplete records')['answer']);
t_ok(str_contains($json, 'Ben'), 'the incomplete record is found');
t_ok(!str_contains($json, 'Ana'), 'and the complete one is not listed');

$json = json_encode(chatbotAnswer($conn, $ctx, 'how many applicants do we have')['answer']);
t_ok(str_contains($json, 'Juan'), 'the applicant is named');

/* Review Focus 2: HR may not ask POS or finance questions. */
foreach (['magkano ang benta ngayong araw', 'show this month expenses',
          'which products are low in stock'] as $question) {
    $result = chatbotAnswer($conn, $ctx, $question);
    t_ok(!$result['ok'], "hr is refused: \"{$question}\"");
}

/* And the one subject nobody gets. */
$result = chatbotAnswer($conn, $ctx, 'how much is the salary of Ana');
t_same('salary_not_available', $result['intent'], 'hr asking about salary gets the plain answer');

t_done();
```

- [ ] **Step 2: Run it and watch it fail**

Run: `C:/xampp/php/php.exe tests/chatbot/test_hr_intents.php`
Expected: FAIL — every intent assertion returns null; HR currently has no intents.

- [ ] **Step 3: Add the six HR intents**

In `chatbotIntents()`, after the `salary_not_available` entry:

```php
        'hr_headcount' => [
            'topic' => 'hrms',
            'roles' => ['hr'],
            'scope' => 'company',
            'label' => 'Employee count',
            'keywords' => [
                ['ilan', 'how many', 'bilang', 'count', 'headcount'],
                ['empleyado', 'employee', 'employees', 'staff', 'tauhan'],
            ],
            'handler' => 'chatbotHrHeadcount',
        ],

        'hr_pending_leave' => [
            'topic' => 'leave',
            'roles' => ['hr'],
            'scope' => 'company',
            'label' => 'Pending leave requests',
            'keywords' => [
                ['pending', 'naghihintay', 'hindi pa aprubado', 'for approval'],
                ['leave', 'bakasyon', 'day off'],
            ],
            'handler' => 'chatbotHrPendingLeave',
        ],

        'hr_attendance_today' => [
            'topic' => 'attendance',
            'roles' => ['hr'],
            'scope' => 'company',
            'label' => 'Attendance today',
            'keywords' => [
                ['late', 'absent', 'attendance', 'pasok', 'present'],
                ['today', 'ngayon', 'ngayong araw'],
            ],
            'handler' => 'chatbotHrAttendanceToday',
        ],

        'hr_applicants' => [
            'topic' => 'recruitment',
            'roles' => ['hr'],
            'scope' => 'company',
            'label' => 'Applicants',
            'keywords' => [
                ['applicant', 'applicants', 'nag apply', 'nag-apply', 'aplikante',
                 'shortlisted', 'interview'],
            ],
            'handler' => 'chatbotHrApplicants',
        ],

        'hr_open_jobs' => [
            'topic' => 'recruitment',
            'roles' => ['hr'],
            'scope' => 'company',
            'label' => 'Open job postings',
            'keywords' => [
                ['job posting', 'job postings', 'hiring', 'bakante', 'vacancy',
                 'vacancies', 'open position'],
            ],
            'handler' => 'chatbotHrOpenJobs',
        ],

        'hr_incomplete_records' => [
            'topic' => 'hrms',
            'roles' => ['hr'],
            'scope' => 'company',
            'label' => 'Incomplete employee records',
            'keywords' => [
                ['incomplete', 'kulang', 'missing', 'walang'],
                ['record', 'records', 'detalye', 'information', 'employee'],
            ],
            'handler' => 'chatbotHrIncompleteRecords',
        ],
```

- [ ] **Step 4: Write the handlers**

`includes/chatbot/answers/hr.php`:

```php
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
            SUM(employment_status = 'Official Employee') AS official,
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
            ['Official employees', (string) (int) $row['official']],
            ['Still in onboarding', (string) (int) $row['pre_employee']],
            ['Archived', (string) (int) $row['archived']],
        ],
        'table' => null,
        'link' => ['href' => 'employee_directory.php', 'label' => 'Open Employee Directory'],
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
        'link' => ['href' => 'approval.php', 'label' => 'Open Leave Approval'],
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
            SUM(a.status = 'Half Day') AS half_day,
            COALESCE(SUM(a.late_minutes), 0) AS late_minutes
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
        'link' => ['href' => 'attendance.php', 'label' => 'Open Attendance'],
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
        'link' => ['href' => 'applications.php', 'label' => 'Open Applications'],
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
        'link' => ['href' => 'recruitment.php', 'label' => 'Open Recruitment'],
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
        'link' => ['href' => 'employee_directory.php', 'label' => 'Open Employee Directory'],
        'note' => $rows ? null : 'Every active employee record has contact details and a branch.',
    ];
}
```

Add the require beside the others in `includes/chatbot/engine.php`:

```php
require_once __DIR__ . '/answers/hr.php';
```

- [ ] **Step 5: Run the test**

Run: `C:/xampp/php/php.exe tests/chatbot/test_hr_intents.php`
Expected: PASS.

- [ ] **Step 6: Checkpoint**

Run: `C:/xampp/php/php.exe tests/chatbot/run_all.php` and `python tests/chatbot/query_audit.py` — expect `ALL TESTS PASSED` and `0 problem(s)`.

---

### Task 2: Finance — including the payroll answers that stay local

**Files:**
- Modify: `includes/chatbot/intents.php` (add five Finance intents; two are `local_only`)
- Create: `includes/chatbot/answers/finance.php`
- Modify: `includes/chatbot/engine.php` (require)
- Test: `tests/chatbot/test_finance_intents.php`

**Interfaces:**
- Produces: `chatbotFinancePayrollTotal`, `chatbotFinancePayrollPending`, `chatbotFinanceExpenses`, `chatbotFinancePayables`, `chatbotFinanceStockRequests`, each `(mysqli $conn, array $ctx, string $question): array`.
- Produces the catalog flag `'local_only' => true` on the two payroll intents, consumed by Task 5.

- [ ] **Step 1: Write the failing test**

`tests/chatbot/test_finance_intents.php`:

```php
<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/factory.php';
require_once __DIR__ . '/../../includes/chatbot/engine.php';

register_shutdown_function(function () use ($conn) { testCleanup($conn); });

$companyId = testMakeCompany($conn, 'Finance Intents Co', 2);
$otherId = testMakeCompany($conn, 'Finance Intents Other', 2);
$ctx = ['company_id' => $companyId, 'user_id' => 1, 'employee_id' => null, 'role' => 'finance'];

$conn->query("INSERT INTO employees (company_id, first_name, last_name, employment_status)
              VALUES ({$companyId}, 'Ana', 'Cruz', 'Official Employee')");
$ana = (int) $conn->insert_id;

$conn->query("INSERT INTO employees (company_id, first_name, last_name, employment_status)
              VALUES ({$otherId}, 'Outside', 'Person', 'Official Employee')");
$outsider = (int) $conn->insert_id;

$conn->query("INSERT INTO payroll (employee_id, payroll_period_start, payroll_period_end, working_days,
                                   basic_pay, overtime_pay, gross_pay, late_deduction, undertime_deduction,
                                   absent_deduction, sss, philhealth, pagibig, total_deduction, net_pay,
                                   status, company_id)
              VALUES ({$ana}, DATE_FORMAT(CURDATE(), '%Y-%m-01'), LAST_DAY(CURDATE()), 22,
                      12000, 0, 12000, 0, 0, 0, 500, 300, 200, 1000, 11000,
                      'Pending Approval', {$companyId})");

$conn->query("INSERT INTO payroll (employee_id, payroll_period_start, payroll_period_end, working_days,
                                   basic_pay, overtime_pay, gross_pay, late_deduction, undertime_deduction,
                                   absent_deduction, sss, philhealth, pagibig, total_deduction, net_pay,
                                   status, company_id)
              VALUES ({$outsider}, DATE_FORMAT(CURDATE(), '%Y-%m-01'), LAST_DAY(CURDATE()), 22,
                      99999, 0, 99999, 0, 0, 0, 0, 0, 0, 0, 99999,
                      'Pending Approval', {$otherId})");

$conn->query("INSERT INTO expenses (expense_code, expense_date, category, vendor, description, amount, payment_method, company_id)
              VALUES ('EXP-T1', CURDATE(), 'Utilities', 'Meralco', 'Electricity', 2500.00, 'Cash', {$companyId})");

$conn->query("INSERT INTO accounts_payable (supplier, category, description, amount, paid_amount, due_date, status, company_id)
              VALUES ('Supplier A', 'Stock', 'Delivery', 5000.00, 1000.00,
                      DATE_ADD(CURDATE(), INTERVAL 7 DAY), 'Partial', {$companyId})");

$conn->query("INSERT INTO stock_requests (request_code, total_price, reason, status, company_id)
              VALUES ('SR-FIN-1', 3000.00, 'restock', 'Pending Finance', {$companyId})");

$cases = [
    'how much is the payroll this month' => 'finance_payroll_total',
    'magkano ang payroll ngayong buwan' => 'finance_payroll_total',
    'which payrolls are pending approval' => 'finance_payroll_pending',
    'show this month expenses' => 'finance_expenses',
    'magkano ang gastos ngayong buwan' => 'finance_expenses',
    'what do we owe our suppliers' => 'finance_payables',
    'which stock requests need finance approval' => 'finance_stock_requests',
];

foreach ($cases as $question => $intent) {
    $result = chatbotAnswer($conn, $ctx, $question);
    t_same($intent, $result['intent'], "finance: \"{$question}\"");
    t_ok($result['ok'], "finance: \"{$question}\" is answered");
}

/* Payroll figures are this company's own. */
$json = json_encode(chatbotAnswer($conn, $ctx, 'how much is the payroll this month')['answer']);
t_ok(str_contains($json, '11,000.00'), 'the net pay total is ours');
t_ok(!str_contains($json, '99,999'), "and never the other company's");

/* The two payroll intents never go to the model. */
t_ok(!empty(chatbotIntents()['finance_payroll_total']['local_only']),
    'the payroll total is marked local_only');
t_ok(!empty(chatbotIntents()['finance_payroll_pending']['local_only']),
    'so is the pending payroll list');

foreach (chatbotIntents() as $id => $intent) {
    if (in_array($intent['topic'], ['pos', 'inventory'], true)) {
        t_ok(empty($intent['local_only']), "{$id} does not need to be local_only");
    }
}

/* Review Focus 3: Finance may not read recruitment detail. */
foreach (['how many applicants do we have', 'what job postings are open',
          'which products are low in stock', 'magkano ang benta ngayong araw'] as $question) {
    $result = chatbotAnswer($conn, $ctx, $question);
    t_ok(!$result['ok'], "finance is refused: \"{$question}\"");
}

t_done();
```

- [ ] **Step 2: Run it and watch it fail**

Run: `C:/xampp/php/php.exe tests/chatbot/test_finance_intents.php`
Expected: FAIL — no Finance intents exist.

- [ ] **Step 3: Teach the pay refusal the plural, then add the five Finance intents**

First, in the existing `salary_not_available` entry in `includes/chatbot/intents.php`,
add `'payrolls'` to its keyword group so "which payrolls…" is recognised as a pay
question by the roles that have no payroll intent:

```php
                ['salary', 'salaries', 'sweldo', 'suweldo', 'sahod', 'payroll',
                 'payrolls', 'payslip', 'pay of', 'deduction', 'deductions'],
```

Finance still reaches its own answers: `finance_payroll_pending` matches two
keyword groups against that question and the refusal matches one, so the
stronger match wins. A cashier, who has no payroll intent, gets the refusal.

Now add the five Finance intents:

```php
        'finance_payroll_total' => [
            'topic' => 'payroll',
            'roles' => ['finance', 'admin'],
            'scope' => 'company',
            'local_only' => true,
            'label' => 'Payroll this month',
            'keywords' => [
                ['payroll', 'sweldo', 'suweldo', 'sahod'],
                ['total', 'kabuuan', 'magkano', 'how much', 'this month', 'ngayong buwan'],
            ],
            'handler' => 'chatbotFinancePayrollTotal',
        ],

        'finance_payroll_pending' => [
            'topic' => 'payroll',
            'roles' => ['finance', 'admin'],
            'scope' => 'company',
            'local_only' => true,
            'label' => 'Payroll awaiting approval',
            'keywords' => [
                /* 'payrolls' too: "which payrolls are pending approval" is the
                   question the matrix itself uses as its example, and the
                   singular does not match it. */
                ['payroll', 'payrolls', 'sweldo', 'payslip'],
                ['pending', 'approval', 'aprubahan', 'waiting', 'naghihintay'],
            ],
            'handler' => 'chatbotFinancePayrollPending',
        ],

        'finance_expenses' => [
            'topic' => 'finance',
            'roles' => ['finance', 'admin'],
            'scope' => 'company',
            'label' => 'Expenses this month',
            'keywords' => [
                ['expense', 'expenses', 'gastos', 'spending'],
            ],
            'handler' => 'chatbotFinanceExpenses',
        ],

        'finance_payables' => [
            'topic' => 'finance',
            'roles' => ['finance', 'admin'],
            'scope' => 'company',
            'label' => 'Unpaid supplier bills',
            'keywords' => [
                /* 'supplier bills' as well as the singular: the chip's own label
                   is "Unpaid supplier bills", and a label that does not match
                   its own keywords is a button that refuses itself. */
                ['owe', 'payable', 'payables', 'utang namin', 'babayaran',
                 'supplier bill', 'supplier bills'],
            ],
            'handler' => 'chatbotFinancePayables',
        ],

        'finance_stock_requests' => [
            'topic' => 'finance',
            'roles' => ['finance', 'admin'],
            'scope' => 'company',
            'label' => 'Stock requests for finance approval',
            'keywords' => [
                ['stock request', 'stock requests', 'request'],
                ['finance', 'approval', 'aprubahan', 'pending'],
            ],
            'handler' => 'chatbotFinanceStockRequests',
        ],
```

- [ ] **Step 4: Write the handlers**

`includes/chatbot/answers/finance.php`:

```php
<?php
/*
| Finance answers.
|
| The payroll ones live HERE, on the keyword path, and are marked local_only in
| the catalog. A keyword answer is queried and rendered on this server; nothing
| about it is sent to a model. That is what lets Finance keep the payroll
| answers its matrix promises while the tool catalog still has no payroll tool
| for anyone. See the conversational spec, "Payroll: answerable, but never by
| the AI".
*/

function chatbotFinancePayrollTotal(mysqli $conn, array $ctx, string $question): array
{
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS payslips,
               COALESCE(SUM(gross_pay), 0) AS gross,
               COALESCE(SUM(total_deduction), 0) AS deductions,
               COALESCE(SUM(net_pay), 0) AS net
        FROM payroll
        WHERE company_id = ?
          AND YEAR(payroll_period_start) = YEAR(CURDATE())
          AND MONTH(payroll_period_start) = MONTH(CURDATE())
    ");
    $stmt->bind_param("i", $ctx['company_id']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return [
        'title' => 'Payroll this month',
        'lines' => [
            ['Payslips', (string) (int) $row['payslips']],
            ['Gross pay', chatbotPeso((float) $row['gross'])],
            ['Deductions', chatbotPeso((float) $row['deductions'])],
            ['Net pay', chatbotPeso((float) $row['net'])],
        ],
        'table' => null,
        'link' => ['href' => 'payroll.php', 'label' => 'Open Payroll'],
    ];
}

function chatbotFinancePayrollPending(mysqli $conn, array $ctx, string $question): array
{
    $stmt = $conn->prepare("
        SELECT CONCAT(e.first_name, ' ', e.last_name) AS employee,
               p.payroll_period_start, p.payroll_period_end, p.status, p.net_pay
        FROM payroll p
        JOIN employees e ON e.employee_id = p.employee_id AND e.company_id = p.company_id
        WHERE p.company_id = ? AND p.status IN ('Pending Approval', 'Returned')
        ORDER BY p.created_at DESC
        LIMIT 20
    ");
    $stmt->bind_param("i", $ctx['company_id']);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];

    while ($row = $result->fetch_assoc()) {
        $rows[] = [$row['employee'],
                   $row['payroll_period_start'] . ' - ' . $row['payroll_period_end'],
                   $row['status'], chatbotPeso((float) $row['net_pay'])];
    }

    $stmt->close();

    return [
        'title' => 'Payroll awaiting approval',
        'lines' => [],
        'table' => $rows
            ? ['columns' => ['Employee', 'Period', 'Status', 'Net pay'], 'rows' => $rows]
            : null,
        'link' => ['href' => 'payroll.php', 'label' => 'Open Payroll'],
        'note' => $rows ? null : 'No payroll is waiting for approval.',
    ];
}

function chatbotFinanceExpenses(mysqli $conn, array $ctx, string $question): array
{
    $stmt = $conn->prepare("
        SELECT category, COUNT(*) AS entries, COALESCE(SUM(amount), 0) AS total
        FROM expenses
        WHERE company_id = ?
          AND YEAR(expense_date) = YEAR(CURDATE())
          AND MONTH(expense_date) = MONTH(CURDATE())
        GROUP BY category
        ORDER BY total DESC
        LIMIT 20
    ");
    $stmt->bind_param("i", $ctx['company_id']);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];
    $total = 0.0;

    while ($row = $result->fetch_assoc()) {
        $total += (float) $row['total'];
        $rows[] = [$row['category'], (string) (int) $row['entries'],
                   chatbotPeso((float) $row['total'])];
    }

    $stmt->close();

    return [
        'title' => 'Expenses this month',
        'lines' => [['Total', chatbotPeso($total)]],
        'table' => $rows
            ? ['columns' => ['Category', 'Entries', 'Amount'], 'rows' => $rows]
            : null,
        'link' => ['href' => 'expenses.php', 'label' => 'Open Expenses'],
        'note' => $rows ? null : 'No expense has been recorded this month.',
    ];
}

function chatbotFinancePayables(mysqli $conn, array $ctx, string $question): array
{
    $stmt = $conn->prepare("
        SELECT supplier, description, amount, paid_amount, due_date, status
        FROM accounts_payable
        WHERE company_id = ? AND status IN ('Pending', 'Partial')
        ORDER BY due_date
        LIMIT 20
    ");
    $stmt->bind_param("i", $ctx['company_id']);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];
    $outstanding = 0.0;

    while ($row = $result->fetch_assoc()) {
        $balance = (float) $row['amount'] - (float) $row['paid_amount'];
        $outstanding += $balance;

        $rows[] = [$row['supplier'], $row['description'], chatbotPeso($balance),
                   (string) $row['due_date'], $row['status']];
    }

    $stmt->close();

    return [
        'title' => 'Unpaid supplier bills',
        'lines' => [['Outstanding', chatbotPeso($outstanding)]],
        'table' => $rows
            ? ['columns' => ['Supplier', 'For', 'Balance', 'Due', 'Status'], 'rows' => $rows]
            : null,
        'link' => ['href' => 'accounts_payable.php', 'label' => 'Open Accounts Payable'],
        'note' => $rows ? null : 'Nothing is outstanding with suppliers.',
    ];
}

function chatbotFinanceStockRequests(mysqli $conn, array $ctx, string $question): array
{
    $stmt = $conn->prepare("
        SELECT request_code, total_price, reason, status, created_at
        FROM stock_requests
        WHERE company_id = ? AND status = 'Pending Finance'
        ORDER BY created_at
        LIMIT 20
    ");
    $stmt->bind_param("i", $ctx['company_id']);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];

    while ($row = $result->fetch_assoc()) {
        $rows[] = [$row['request_code'], chatbotPeso((float) $row['total_price']),
                   $row['reason'], (string) $row['created_at']];
    }

    $stmt->close();

    return [
        'title' => 'Stock requests for finance approval',
        'lines' => [],
        'table' => $rows
            ? ['columns' => ['Request', 'Amount', 'Reason', 'Requested'], 'rows' => $rows]
            : null,
        'link' => ['href' => 'stock_requests.php', 'label' => 'Open Stock Requests'],
        'note' => $rows ? null : 'No stock request is waiting for finance.',
    ];
}
```

Add the require in `engine.php`.

- [ ] **Step 5: Run the test**

Run: `C:/xampp/php/php.exe tests/chatbot/test_finance_intents.php`
Expected: PASS.

- [ ] **Step 6: Checkpoint**

Run the suite and the audit. The audit must still report `0 problem(s)`: the pay words are in `answers/finance.php`, which the pay scan does not cover, and every statement there carries `company_id = ?`.

---

### Task 3: Inventory Staff — the keyword questions

**Files:**
- Modify: `includes/chatbot/intents.php` (add four Inventory intents)
- Create: `includes/chatbot/answers/inventory_staff.php`
- Modify: `includes/chatbot/engine.php` (require)
- Test: `tests/chatbot/test_inventory_intents.php`

**Interfaces:**
- Produces: `chatbotInvLowStock`, `chatbotInvOutOfStock`, `chatbotInvStockRequests`, `chatbotInvSuppliers`, each `(mysqli $conn, array $ctx, string $question): array`.

- [ ] **Step 1: Write the failing test**

`tests/chatbot/test_inventory_intents.php`:

```php
<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/factory.php';
require_once __DIR__ . '/../../includes/chatbot/engine.php';

register_shutdown_function(function () use ($conn) { testCleanup($conn); });

$companyId = testMakeCompany($conn, 'Inv Intents Co', 2);
$ctx = ['company_id' => $companyId, 'user_id' => 1, 'employee_id' => null, 'role' => 'inventory'];

$conn->query("INSERT INTO categories (category_name, company_id) VALUES ('Noodles', {$companyId})");
$categoryId = (int) $conn->insert_id;

$conn->query("INSERT INTO suppliers (supplier_name, contact_email, company_id)
              VALUES ('Best Supplier', 'best@test.local', {$companyId})");
$supplierId = (int) $conn->insert_id;

$conn->query("INSERT INTO products (product_name, category_id, supplier_id, company_id)
              VALUES ('Lucky Me', {$categoryId}, {$supplierId}, {$companyId})");
$low = (int) $conn->insert_id;
$conn->query("INSERT INTO inventory (product_id, quantity, purchase_cost, selling_price, reorder_level, company_id)
              VALUES ({$low}, 2, 9.00, 15.00, 5, {$companyId})");

$conn->query("INSERT INTO products (product_name, category_id, company_id)
              VALUES ('Coke Mismo', {$categoryId}, {$companyId})");
$out = (int) $conn->insert_id;
$conn->query("INSERT INTO inventory (product_id, quantity, purchase_cost, selling_price, reorder_level, company_id)
              VALUES ({$out}, 0, 15.00, 20.00, 5, {$companyId})");

$conn->query("INSERT INTO stock_requests (request_code, total_price, reason, status, company_id)
              VALUES ('SR-INV-1', 1200.00, 'restock', 'Pending Finance', {$companyId})");

$cases = [
    'which products are low in stock' => 'inv_low_stock',
    'anong produkto ang mababa ang stock' => 'inv_low_stock',
    'what products are out of stock' => 'inv_out_of_stock',
    'show pending stock requests' => 'inv_stock_requests',
    'who are our suppliers' => 'inv_suppliers',
];

foreach ($cases as $question => $intent) {
    $result = chatbotAnswer($conn, $ctx, $question);
    t_same($intent, $result['intent'], "inventory: \"{$question}\"");
    t_ok($result['ok'], "inventory: \"{$question}\" is answered");
}

$json = json_encode(chatbotAnswer($conn, $ctx, 'which products are low in stock')['answer']);
t_ok(str_contains($json, 'Lucky Me'), 'the low product is named');
t_ok(str_contains($json, 'Best Supplier'), 'with the supplier to reorder from');

/* Review Focus 4: their matrix says view only, and no pay, no POS. */
$json = json_encode(chatbotAnswer($conn, $ctx, 'who are our suppliers')['answer']);
t_ok(!str_contains(mb_strtolower($json), 'delete'), 'the supplier answer offers no action');

foreach (['magkano ang benta ngayong araw', 'how many employees do we have',
          'show this month expenses'] as $question) {
    $result = chatbotAnswer($conn, $ctx, $question);
    t_ok(!$result['ok'], "inventory is refused: \"{$question}\"");
}

$result = chatbotAnswer($conn, $ctx, 'how much is the salary of Ana');
t_same('salary_not_available', $result['intent'], 'inventory asking about salary gets the plain answer');

t_done();
```

- [ ] **Step 2: Run it and watch it fail**

Run: `C:/xampp/php/php.exe tests/chatbot/test_inventory_intents.php`
Expected: FAIL — no Inventory intents exist.

- [ ] **Step 3: Add the four intents**

```php
        'inv_low_stock' => [
            'topic' => 'inventory',
            'roles' => ['inventory'],
            'scope' => 'company',
            'label' => 'Low stock',
            'keywords' => [
                ['low', 'mababa', 'mababang', 'kulang', 'konti', 'reorder'],
                ['stock', 'stocks', 'inventory', 'produkto', 'paninda', 'products'],
            ],
            'handler' => 'chatbotInvLowStock',
        ],

        'inv_out_of_stock' => [
            'topic' => 'inventory',
            'roles' => ['inventory'],
            'scope' => 'company',
            'label' => 'Out of stock',
            'keywords' => [
                ['out of stock', 'ubos', 'wala nang stock', 'naubos', 'sold out'],
            ],
            'handler' => 'chatbotInvOutOfStock',
        ],

        'inv_stock_requests' => [
            'topic' => 'inventory',
            'roles' => ['inventory'],
            'scope' => 'company',
            'label' => 'Stock requests',
            'keywords' => [
                ['stock request', 'stock requests', 'request', 'requests', 'hiling'],
            ],
            'handler' => 'chatbotInvStockRequests',
        ],

        'inv_suppliers' => [
            'topic' => 'inventory',
            'roles' => ['inventory'],
            'scope' => 'company',
            'label' => 'Suppliers',
            'keywords' => [
                ['supplier', 'suppliers', 'tagatustos', 'vendor', 'vendors'],
            ],
            'handler' => 'chatbotInvSuppliers',
        ],
```

- [ ] **Step 4: Write the handlers**

`includes/chatbot/answers/inventory_staff.php`:

```php
<?php
/*
| Inventory Staff answers.
|
| Their matrix says supplier information is view only, so these answers name
| suppliers and show what is owed to nobody: reordering, paying and editing all
| stay on their own pages.
*/

function chatbotInvLowStock(mysqli $conn, array $ctx, string $question): array
{
    $stmt = $conn->prepare("
        SELECT p.product_name, i.quantity, i.reorder_level,
               COALESCE(s.supplier_name, 'No supplier') AS supplier
        FROM inventory i
        JOIN products p ON p.product_id = i.product_id AND p.company_id = i.company_id
        LEFT JOIN suppliers s ON s.supplier_id = p.supplier_id AND s.company_id = p.company_id
        WHERE i.company_id = ?
          AND i.quantity > 0
          AND i.quantity <= COALESCE(i.reorder_level, 5)
        ORDER BY i.quantity
        LIMIT 20
    ");
    $stmt->bind_param("i", $ctx['company_id']);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];

    while ($row = $result->fetch_assoc()) {
        $rows[] = [$row['product_name'], (string) (int) $row['quantity'],
                   (string) (int) ($row['reorder_level'] ?? 5), $row['supplier']];
    }

    $stmt->close();

    return [
        'title' => 'Low stock',
        'lines' => [],
        'table' => $rows
            ? ['columns' => ['Product', 'On hand', 'Reorder level', 'Supplier'], 'rows' => $rows]
            : null,
        'link' => ['href' => 'inventory.php', 'label' => 'Open Inventory'],
        'note' => $rows ? null : 'No product is at or below its reorder level right now.',
    ];
}

function chatbotInvOutOfStock(mysqli $conn, array $ctx, string $question): array
{
    $stmt = $conn->prepare("
        SELECT p.product_name, COALESCE(s.supplier_name, 'No supplier') AS supplier
        FROM inventory i
        JOIN products p ON p.product_id = i.product_id AND p.company_id = i.company_id
        LEFT JOIN suppliers s ON s.supplier_id = p.supplier_id AND s.company_id = p.company_id
        WHERE i.company_id = ? AND i.quantity <= 0
        ORDER BY p.product_name
        LIMIT 20
    ");
    $stmt->bind_param("i", $ctx['company_id']);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];

    while ($row = $result->fetch_assoc()) {
        $rows[] = [$row['product_name'], $row['supplier']];
    }

    $stmt->close();

    return [
        'title' => 'Out of stock',
        'lines' => [],
        'table' => $rows ? ['columns' => ['Product', 'Supplier'], 'rows' => $rows] : null,
        'link' => ['href' => 'inventory.php', 'label' => 'Open Inventory'],
        'note' => $rows ? null : 'No product is out of stock right now.',
    ];
}

function chatbotInvStockRequests(mysqli $conn, array $ctx, string $question): array
{
    $stmt = $conn->prepare("
        SELECT request_code, status, total_price, reason, created_at
        FROM stock_requests
        WHERE company_id = ?
        ORDER BY created_at DESC
        LIMIT 20
    ");
    $stmt->bind_param("i", $ctx['company_id']);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];

    while ($row = $result->fetch_assoc()) {
        $rows[] = [$row['request_code'], $row['status'],
                   chatbotPeso((float) $row['total_price']), $row['reason']];
    }

    $stmt->close();

    return [
        'title' => 'Stock requests',
        'lines' => [],
        'table' => $rows
            ? ['columns' => ['Request', 'Status', 'Amount', 'Reason'], 'rows' => $rows]
            : null,
        'link' => ['href' => 'stock_requests.php', 'label' => 'Open Stock Requests'],
        'note' => $rows ? null : 'There is no stock request yet.',
    ];
}

function chatbotInvSuppliers(mysqli $conn, array $ctx, string $question): array
{
    $stmt = $conn->prepare("
        SELECT s.supplier_name, s.contact_email,
               COUNT(p.product_id) AS products
        FROM suppliers s
        LEFT JOIN products p ON p.supplier_id = s.supplier_id AND p.company_id = s.company_id
        WHERE s.company_id = ?
        GROUP BY s.supplier_id, s.supplier_name, s.contact_email
        ORDER BY s.supplier_name
        LIMIT 20
    ");
    $stmt->bind_param("i", $ctx['company_id']);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];

    while ($row = $result->fetch_assoc()) {
        $rows[] = [$row['supplier_name'], (string) $row['contact_email'],
                   (string) (int) $row['products']];
    }

    $stmt->close();

    return [
        'title' => 'Suppliers',
        'lines' => [],
        'table' => $rows
            ? ['columns' => ['Supplier', 'Contact', 'Products'], 'rows' => $rows]
            : null,
        'link' => ['href' => 'suppliers.php', 'label' => 'Open Suppliers'],
        'note' => $rows ? null : 'No supplier has been added yet.',
    ];
}
```

Add the require in `engine.php`.

- [ ] **Step 5: Run the test**

Run: `C:/xampp/php/php.exe tests/chatbot/test_inventory_intents.php`
Expected: PASS.

- [ ] **Step 6: Checkpoint**

Run the suite and the audit.

---

### Task 4: The three roles reach the conversational tools

**Files:**
- Modify: `includes/chatbot/chat/tools.php` (extend `roles` on existing tools; add `finance_expenses` and `finance_payables` tools)
- Create: `includes/chatbot/chat/tools/finance.php`
- Modify: `includes/chatbot/chat/tool_runner.php` (require)
- Test: `tests/chatbot/test_chat_phase2_tools.php`

**Interfaces:**
- Produces: `chatToolFinanceExpenses(mysqli $conn, array $ctx, array $in): array`, `chatToolFinancePayables(mysqli $conn, array $ctx, array $in): array`; and the role lists on the existing tools extended per the matrix.

- [ ] **Step 1: Write the failing test**

`tests/chatbot/test_chat_phase2_tools.php`:

```php
<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/factory.php';
require_once __DIR__ . '/../../includes/chatbot/engine.php';
require_once __DIR__ . '/../../includes/chatbot/chat/tool_runner.php';

register_shutdown_function(function () use ($conn) { testCleanup($conn); });

$companyId = testMakeCompany($conn, 'Phase2 Tools Co', 2);

/* The matrix, as tool names, for the three new roles. */
$matrix = [
    'hr' => [
        'staff_list' => true, 'attendance_summary' => true, 'attendance_detail' => true,
        'leave_requests' => true, 'recruitment_summary' => true,
        'sales_summary' => false, 'payment_mix' => false, 'stock_list' => false,
        'stock_requests' => false, 'finance_expenses' => false, 'finance_payables' => false,
    ],
    'finance' => [
        'finance_expenses' => true, 'finance_payables' => true, 'stock_requests' => true,
        'sales_summary' => false, 'staff_list' => false, 'attendance_summary' => false,
        'recruitment_summary' => false, 'leave_requests' => false, 'stock_list' => false,
    ],
    'inventory' => [
        'stock_list' => true, 'product_lookup' => true, 'stock_requests' => true,
        'sales_summary' => false, 'staff_list' => false, 'attendance_summary' => false,
        'finance_expenses' => false, 'recruitment_summary' => false,
    ],
];

foreach ($matrix as $role => $expectations) {
    $tools = chatToolsFor($conn, ['company_id' => $companyId, 'user_id' => 1,
                                  'employee_id' => 1, 'role' => $role]);

    foreach ($expectations as $tool => $mayUse) {
        t_same($mayUse, isset($tools[$tool]),
            "{$role} " . ($mayUse ? 'gets' : 'does NOT get') . " {$tool}");
    }
}

/* No tool anywhere touches pay -- including the new finance ones. */
foreach (chatTools() as $name => $tool) {
    $text = mb_strtolower(json_encode($tool));

    foreach (['payroll', 'salary', 'net_pay', 'deduction'] as $word) {
        t_ok(!str_contains($text, $word), "{$name} does not mention {$word}");
    }
}

/* The finance tools return this company's own figures. */
$ctx = ['company_id' => $companyId, 'user_id' => 1, 'employee_id' => null, 'role' => 'finance'];

$conn->query("INSERT INTO expenses (expense_code, expense_date, category, vendor, description, amount, payment_method, company_id)
              VALUES ('EXP-P2', CURDATE(), 'Utilities', 'Meralco', 'Power', 1500.00, 'Cash', {$companyId})");

$other = testMakeCompany($conn, 'Phase2 Other Co', 2);
$conn->query("INSERT INTO expenses (expense_code, expense_date, category, vendor, description, amount, payment_method, company_id)
              VALUES ('EXP-OTHER', CURDATE(), 'Utilities', 'Meralco', 'Power', 8888.00, 'Cash', {$other})");

$result = chatRunTool($conn, $ctx, 'finance_expenses', ['period' => 'this_month']);
t_ok($result['ok'], 'finance_expenses runs');
t_ok(str_contains(json_encode($result), '1500.00'), 'our expense is there');
t_ok(!str_contains(json_encode($result), '8888.00'), "another company's is not");

$conn->query("INSERT INTO accounts_payable (supplier, category, description, amount, paid_amount, due_date, status, company_id)
              VALUES ('Supplier A', 'Stock', 'Delivery', 5000.00, 1000.00,
                      DATE_ADD(CURDATE(), INTERVAL 7 DAY), 'Partial', {$companyId})");

$result = chatRunTool($conn, $ctx, 'finance_payables', ['status' => 'unpaid']);
t_ok($result['ok'], 'finance_payables runs');
t_ok(str_contains(json_encode($result), 'Supplier A'), 'the supplier is named');

/* Review Focus 5: a Starter company has none of these seats or topics. */
$starter = testMakeCompany($conn, 'Phase2 Starter', 1);

foreach (['hr', 'finance', 'inventory'] as $role) {
    $tools = chatToolsFor($conn, ['company_id' => $starter, 'user_id' => 1,
                                  'employee_id' => 1, 'role' => $role]);
    t_same([], $tools, "a Starter {$role} reaches no tools");
}

t_done();
```

- [ ] **Step 2: Run it and watch it fail**

Run: `C:/xampp/php/php.exe tests/chatbot/test_chat_phase2_tools.php`
Expected: FAIL — HR/Finance/Inventory are in no tool's `roles`.

- [ ] **Step 3: Extend the role lists**

In `includes/chatbot/chat/tools.php`, each of these entries currently reads
`'roles' => ['admin', 'cashier'],` or `'roles' => ['admin'],`. Replace the
`roles` line inside each named entry — and nowhere else, since several entries
share the same text:

| Entry | Was | Becomes |
|---|---|---|
| `stock_list` | `['admin', 'cashier']` | `['admin', 'cashier', 'inventory']` |
| `product_lookup` | `['admin', 'cashier']` | `['admin', 'cashier', 'inventory']` |
| `stock_requests` | `['admin']` | `['admin', 'inventory', 'finance']` |
| `staff_list` | `['admin']` | `['admin', 'hr']` |
| `attendance_summary` | `['admin']` | `['admin', 'hr']` |
| `attendance_detail` | `['admin']` | `['admin', 'hr']` |
| `leave_requests` | `['admin']` | `['admin', 'hr']` |
| `recruitment_summary` | `['admin']` | `['admin', 'hr']` |

`company_profile`, `branch_list` and the four sales tools keep the roles they
have. Because the `roles` line is identical in several entries, make each edit
by anchoring on the entry's own `'handler' => '...'` line or its
`'description'`, and confirm afterwards with:

```bash
C:/xampp/php/php.exe -r "require 'C:/xampp/htdocs/SariSmarts/includes/chatbot/chat/tools.php';
foreach (chatTools() as \$name => \$tool) { echo \$name . ': ' . implode(',', \$tool['roles']) . PHP_EOL; }"
```

Expected: exactly the pairs in the table above, and the other six unchanged.

- [ ] **Step 4: Add the two finance tools**

In `chatTools()`:

```php
        'finance_expenses' => [
            'topic' => 'finance',
            'roles' => ['admin', 'finance'],
            'scope' => 'company',
            'description' => 'Expenses for a period, grouped by category, with the '
                . 'total. Use for "what did we spend on", "magkano ang gastos".',
            'input' => [
                'period' => [
                    'type' => 'enum',
                    'values' => ['today', 'yesterday', 'this_week', 'last_week',
                                 'this_month', 'last_month', 'custom'],
                    'required' => true,
                ],
                'from' => ['type' => 'date'],
                'to' => ['type' => 'date'],
                'limit' => ['type' => 'int', 'min' => 1, 'max' => 50],
            ],
            'handler' => 'chatToolFinanceExpenses',
        ],

        'finance_payables' => [
            'topic' => 'finance',
            'roles' => ['admin', 'finance'],
            'scope' => 'company',
            'description' => 'What the business owes suppliers: each bill, what is '
                . 'still unpaid, and when it falls due.',
            'input' => [
                'status' => [
                    'type' => 'enum',
                    'values' => ['unpaid', 'Pending', 'Partial', 'Paid', 'all'],
                    'required' => true,
                ],
                'limit' => ['type' => 'int', 'min' => 1, 'max' => 50],
            ],
            'handler' => 'chatToolFinancePayables',
        ],
```

`includes/chatbot/chat/tools/finance.php`:

```php
<?php
/*
| Finance tools: money that moves between the business and other people.
|
| Money that moves between the business and its OWN staff -- payroll -- has no
| tool here and never will. Those answers exist on the keyword path, where the
| figures are rendered on this server and never sent to a model.
*/

function chatToolFinanceExpenses(mysqli $conn, array $ctx, array $in): array
{
    $range = chatResolvePeriod($in['period'], $in['from'] ?? null, $in['to'] ?? null);
    $limit = min((int) ($in['limit'] ?? CHAT_TOOL_ROW_CAP), CHAT_TOOL_ROW_CAP) + 1;

    $stmt = $conn->prepare("
        SELECT category, COUNT(*) AS entries, COALESCE(SUM(amount), 0) AS total
        FROM expenses
        WHERE company_id = ? AND expense_date BETWEEN ? AND ?
        GROUP BY category
        ORDER BY total DESC
        LIMIT ?
    ");
    $stmt->bind_param("issi", $ctx['company_id'], $range['from'], $range['to'], $limit);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];

    while ($row = $result->fetch_assoc()) {
        $rows[] = [$row['category'], (string) (int) $row['entries'],
                   number_format((float) $row['total'], 2, '.', '')];
    }

    $stmt->close();

    return ['columns' => ['category', 'entries', 'amount'], 'rows' => $rows];
}

function chatToolFinancePayables(mysqli $conn, array $ctx, array $in): array
{
    $limit = min((int) ($in['limit'] ?? CHAT_TOOL_ROW_CAP), CHAT_TOOL_ROW_CAP) + 1;
    $status = (string) $in['status'];

    $sql = "
        SELECT supplier, description, amount, paid_amount,
               (amount - paid_amount) AS balance, due_date, status
        FROM accounts_payable
        WHERE company_id = ?
    ";

    if ($status === 'unpaid') {
        $sql .= " AND status IN ('Pending', 'Partial') ";
    } elseif ($status !== 'all') {
        $sql .= " AND status = ? ";
    }

    $sql .= " ORDER BY due_date LIMIT ? ";

    $stmt = $conn->prepare($sql);

    if ($status === 'unpaid' || $status === 'all') {
        $stmt->bind_param("ii", $ctx['company_id'], $limit);
    } else {
        $stmt->bind_param("isi", $ctx['company_id'], $status, $limit);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];

    while ($row = $result->fetch_assoc()) {
        $rows[] = [
            $row['supplier'],
            $row['description'],
            number_format((float) $row['amount'], 2, '.', ''),
            number_format((float) $row['balance'], 2, '.', ''),
            (string) $row['due_date'],
            $row['status'],
        ];
    }

    $stmt->close();

    return ['columns' => ['supplier', 'description', 'amount', 'balance', 'due_date', 'status'],
            'rows' => $rows];
}
```

Add the require in `tool_runner.php`.

- [ ] **Step 5: Run the test**

Run: `C:/xampp/php/php.exe tests/chatbot/test_chat_phase2_tools.php`
Expected: PASS.

- [ ] **Step 6: Checkpoint**

Run the suite and the audit — the audit is the guard that `chat/tools/finance.php` contains no pay word.

---

### Task 5: Payroll questions never reach the model

**Files:**
- Modify: `includes/chatbot/engine.php` (add `chatbotLocalOnlyMatch()`)
- Modify: `includes/chatbot/ask.php` (skip the chat layer for a `local_only` match)
- Test: `tests/chatbot/test_local_only.php`

**Interfaces:**
- Produces: `chatbotLocalOnlyMatch(mysqli $conn, array $ctx, string $question): ?string` — the intent id when the question matches a `local_only` intent this user may ask, else null.

- [ ] **Step 1: Write the failing test**

`tests/chatbot/test_local_only.php`:

```php
<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/factory.php';
require_once __DIR__ . '/../../includes/chatbot/engine.php';

register_shutdown_function(function () use ($conn) { testCleanup($conn); });

$companyId = testMakeCompany($conn, 'Local Only Co', 2);

$financeCtx = ['company_id' => $companyId, 'user_id' => 1,
               'employee_id' => null, 'role' => 'finance'];
$cashierCtx = ['company_id' => $companyId, 'user_id' => 1,
               'employee_id' => 1, 'role' => 'cashier'];

/* Review Focus 1: a payroll question belongs to the keyword path. */
t_same('finance_payroll_total',
    chatbotLocalOnlyMatch($conn, $financeCtx, 'how much is the payroll this month'),
    'finance asking about payroll is answered locally');

t_same('finance_payroll_pending',
    chatbotLocalOnlyMatch($conn, $financeCtx, 'which payrolls are pending approval'),
    'and so is the approval queue');

/* A question with no local_only match goes to the model as usual. */
t_same(null, chatbotLocalOnlyMatch($conn, $financeCtx, 'show this month expenses'),
    'an expenses question is not held back');
t_same(null, chatbotLocalOnlyMatch($conn, $financeCtx, 'what did we spend on utilities'),
    'nor is an open-ended one');

/* A role without the payroll intents is not held back either -- it simply has
   no such intent, and the model may answer what it can. */
t_same(null, chatbotLocalOnlyMatch($conn, $cashierCtx, 'how much is the payroll this month'),
    'a cashier has no payroll intent to hold back');

/* The refusal intent is itself local: there is nothing to ask a model about. */
t_ok(chatbotLocalOnlyMatch($conn, $cashierCtx, 'magkano ang sweldo ko') !== null,
    'a pay question from any role is answered locally');

t_done();
```

- [ ] **Step 2: Run it and watch it fail**

Run: `C:/xampp/php/php.exe tests/chatbot/test_local_only.php`
Expected: FAIL — `chatbotLocalOnlyMatch()` is undefined.

- [ ] **Step 3: Mark the refusal intent local too**

In `includes/chatbot/intents.php`, add to the `salary_not_available` entry:

```php
            'local_only' => true,
```

- [ ] **Step 4: Write the helper**

Add to `includes/chatbot/engine.php`:

```php
/**
 * The id of a local_only intent this question matches, or null.
 *
 * Payroll answers are queried and rendered on this server and never sent to a
 * model -- that is how Finance keeps them while the tool catalog has no payroll
 * tool. Without this check the conversational layer would answer first, find no
 * payroll tool, and refuse a question this system answers perfectly well.
 */
function chatbotLocalOnlyMatch(mysqli $conn, array $ctx, string $question): ?string
{
    $allowed = chatbotAllowedIntents($conn, (int) $ctx['company_id'], (string) $ctx['role']);

    $local = array_filter($allowed, static fn (array $intent): bool => !empty($intent['local_only']));

    if ($local === []) {
        return null;
    }

    return chatbotKeywordMatch($question, $local);
}
```

- [ ] **Step 5: Use it in ask.php**

In `includes/chatbot/ask.php`, change the chat-layer condition:

```php
if (chatEnabled($conn, $ctx) && chatWithinDailyCap($conn, $ctx)
    && chatbotLocalOnlyMatch($conn, $ctx, $question) === null) {
```

- [ ] **Step 6: Run the test**

Run: `C:/xampp/php/php.exe tests/chatbot/test_local_only.php`
Expected: PASS.

- [ ] **Step 7: Checkpoint**

Run the suite and the audit; lint `ask.php`:
`C:/xampp/php/php.exe -l includes/chatbot/ask.php`

---

### Task 6: The whole matrix, and the plan gate

**Files:**
- Test: `tests/chatbot/test_phase2_matrix.php`

**Interfaces:**
- Consumes: the full intent catalog and tool catalog.

- [ ] **Step 1: Write the matrix test**

`tests/chatbot/test_phase2_matrix.php`:

```php
<?php
/*
| Spec section 6 of the Phase 1 design, as a table: every tick and every cross
| the six roles are promised, now that all six have questions.
*/
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/factory.php';
require_once __DIR__ . '/../../includes/chatbot/engine.php';

register_shutdown_function(function () use ($conn) { testCleanup($conn); });

$professional = testMakeCompany($conn, 'Phase2 Matrix Pro', 2);
$starter = testMakeCompany($conn, 'Phase2 Matrix Starter', 1);

$matrix = [
    'hr' => [
        'hr_headcount' => true, 'hr_pending_leave' => true, 'hr_attendance_today' => true,
        'hr_applicants' => true, 'hr_open_jobs' => true, 'hr_incomplete_records' => true,
        'sales_today' => false, 'low_stock' => false, 'finance_expenses' => false,
        'finance_payroll_total' => false, 'inv_suppliers' => false,
    ],
    'finance' => [
        'finance_payroll_total' => true, 'finance_payroll_pending' => true,
        'finance_expenses' => true, 'finance_payables' => true,
        'finance_stock_requests' => true,
        'hr_applicants' => false, 'hr_open_jobs' => false, 'sales_today' => false,
        'low_stock' => false, 'inv_suppliers' => false,
    ],
    'inventory' => [
        'inv_low_stock' => true, 'inv_out_of_stock' => true, 'inv_stock_requests' => true,
        'inv_suppliers' => true,
        'sales_today' => false, 'finance_payroll_total' => false, 'hr_headcount' => false,
        'staff_count' => false,
    ],
    'admin' => [
        'sales_today' => true, 'low_stock' => true, 'staff_count' => true,
        'finance_payroll_total' => true, 'finance_expenses' => true,
        'hr_applicants' => false, 'inv_suppliers' => false,
    ],
    'cashier' => [
        'product_price' => true, 'my_sales_today' => true,
        'finance_payroll_total' => false, 'hr_headcount' => false, 'inv_low_stock' => false,
    ],
    'employee' => [
        'my_attendance_today' => true, 'my_leave_status' => true,
        'hr_headcount' => false, 'finance_expenses' => false, 'inv_low_stock' => false,
    ],
];

foreach ($matrix as $role => $expectations) {
    $allowed = chatbotAllowedIntents($conn, $professional, $role);

    foreach ($expectations as $intent => $mayAsk) {
        t_same($mayAsk, isset($allowed[$intent]),
            "{$role} " . ($mayAsk ? 'may' : 'may NOT') . " ask {$intent}");
    }
}

/* Every role gets the pay refusal, because every role can ask about pay. */
foreach (['admin', 'hr', 'finance', 'inventory', 'cashier', 'employee'] as $role) {
    $allowed = chatbotAllowedIntents($conn, $professional, $role);
    t_ok(isset($allowed['salary_not_available']), "{$role} gets the pay refusal");
}

/*
| Review Focus 5: Retail Starter buys pos, inventory, staff and reports -- not
| hrms, leave, attendance, recruitment, payroll or finance. So HR and Finance
| reach nothing there beyond the pay refusal, which is a staff-topic intent
| every role gets.
|
| The Inventory role is a different case worth being precise about: Starter
| DOES buy the inventory topic, so its inventory intents pass the plan gate.
| What Starter does not sell is the Inventory SEAT -- subscription_plan_roles
| grants it only admin and cashier -- so no such account exists to ask. The
| assertion below states exactly that, rather than pretending the topic gate
| does work it does not do.
*/
foreach (['hr', 'finance'] as $role) {
    $allowed = chatbotAllowedIntents($conn, $starter, $role);

    foreach (array_keys($allowed) as $intent) {
        t_same('staff', $allowed[$intent]['topic'],
            "a Starter {$role} reaches only the pay refusal, not {$intent}");
    }
}

t_ok(!in_array('inventory', companyPlanRoles($conn, $starter), true),
    'Starter does not sell an Inventory seat, so no such account can ask');
t_ok(!in_array('hr', companyPlanRoles($conn, $starter), true),
    'nor an HR seat');
t_ok(!in_array('finance', companyPlanRoles($conn, $starter), true),
    'nor a Finance seat');

$starterAdmin = chatbotAllowedIntents($conn, $starter, 'admin');

foreach (['finance_payroll_total', 'finance_expenses', 'finance_payables'] as $intent) {
    t_ok(!isset($starterAdmin[$intent]), "a Starter admin is refused {$intent}");
}

/* Every handler named in the catalog exists. */
foreach (chatbotIntents() as $id => $intent) {
    t_ok(function_exists($intent['handler']), "{$id}'s handler is a real function");
}

/* And every suggestion chip still round-trips to its own intent. */
$companyId = $professional;

foreach (['admin', 'hr', 'finance', 'inventory', 'cashier', 'employee'] as $role) {

    $ctx = ['company_id' => $companyId, 'user_id' => 1, 'employee_id' => 1, 'role' => $role];
    $allowed = chatbotAllowedIntents($conn, $companyId, $role);

    foreach (chatbotSuggestions($allowed, 20) as $suggestion) {

        if (!empty($allowed[$suggestion['id']]['needs_input'])) {
            continue;
        }

        $result = chatbotAnswer($conn, $ctx, $suggestion['label']);
        t_same($suggestion['id'], $result['intent'],
            "{$role}: chip \"{$suggestion['label']}\" reaches its own intent");
    }
}

t_done();
```

- [ ] **Step 2: Run it**

Run: `C:/xampp/php/php.exe tests/chatbot/test_phase2_matrix.php`
Expected: PASS. If a chip fails to round-trip, add the missing word to that intent's keyword group — never weaken the assertion.

- [ ] **Step 3: Cross-company proof for the new answers**

Add to `tests/chatbot/test_isolation.php`, inside the per-company loop, after the existing assertions:

```php
    /* The Phase 2 answers, asked as each role that may ask them. */
    foreach ([['hr', 'sino ang may pending leave'],
              ['hr', 'how many applicants do we have'],
              ['finance', 'how much is the payroll this month'],
              ['finance', 'show this month expenses'],
              ['inventory', 'which products are low in stock']] as [$role, $question]) {

        $roleCtx = ['company_id' => $companyId, 'user_id' => 1,
                    'employee_id' => null, 'role' => $role];

        $json = json_encode(chatbotAnswer($conn, $roleCtx, $question));

        t_ok(!str_contains($json, $theirs . 'ChatProduct'),
            "{$mine}: {$role} \"{$question}\" does not name {$theirs}'s things");
        t_ok(!str_contains($json, $theirs === 'Alpha' ? '1111.11' : '2222.22'),
            "{$mine}: {$role} \"{$question}\" does not show {$theirs}'s figures");
    }
```

- [ ] **Step 4: Run the isolation test**

Run: `C:/xampp/php/php.exe tests/chatbot/test_isolation.php`
Expected: PASS.

- [ ] **Step 5: Prove it can fail**

Temporarily change `chatbotHrPendingLeave`'s `WHERE l.company_id = ?` to
`WHERE ? IS NOT NULL`. Run the isolation test: expected FAIL. Run the audit:
expected 1 problem. **Restore**, re-run both: PASS and `0 problem(s)`.

- [ ] **Step 6: Full verification**

Run:
```bash
C:/xampp/php/php.exe tests/chatbot/run_all.php
python tests/chatbot/query_audit.py
C:/xampp/mysql/bin/mysql.exe -uroot -N sari -e "SELECT COUNT(*) FROM company WHERE company_name LIKE 'CHATBOT-TEST %';"
```
Expected: `ALL TESTS PASSED`, `0 problem(s)`, `0`.

---

### Task 7: The widget reaches the three new roles

**Files:**
- Modify: `hr/hr_header.php`, `finance/finance_header.php`, `inventory/inventory_header.php`
- Test: `tests/chatbot/test_widget_headers.php`

**Interfaces:**
- Consumes: `includes/chatbot/widget.php`.

- [ ] **Step 1: Write the failing test**

`tests/chatbot/test_widget_headers.php`:

```php
<?php
/*
| The assistant is only useful where it is drawn. Phase 1 put it in three
| headers; Phase 2 gives three more roles questions to ask.
*/
require_once __DIR__ . '/bootstrap.php';

$headers = [
    'admin/admin_header.php',
    'cashier/cashier_header.php',
    'employee/employee_header.php',
    'hr/hr_header.php',
    'finance/finance_header.php',
    'inventory/inventory_header.php',
];

foreach ($headers as $header) {
    $source = file_get_contents(__DIR__ . '/../../' . $header);

    t_ok(str_contains($source, 'chatbot/widget.php'),
        "{$header} includes the assistant");
}

t_done();
```

- [ ] **Step 2: Run it and watch it fail**

Run: `C:/xampp/php/php.exe tests/chatbot/test_widget_headers.php`
Expected: FAIL for the three new headers.

- [ ] **Step 3: Add the include**

Append to each of `hr/hr_header.php`, `finance/finance_header.php` and
`inventory/inventory_header.php` (preserve CRLF; these files are CRLF like the
first three):

```php
<?php
/* The assistant. One line here puts it on every page this header serves. */
include __DIR__ . '/../includes/chatbot/widget.php';
?>
```

- [ ] **Step 4: Run the test and lint**

Run:
```bash
C:/xampp/php/php.exe tests/chatbot/test_widget_headers.php
C:/xampp/php/php.exe -l hr/hr_header.php
C:/xampp/php/php.exe -l finance/finance_header.php
C:/xampp/php/php.exe -l inventory/inventory_header.php
```
Expected: PASS, and no syntax errors.

- [ ] **Step 5: Checkpoint**

Run the suite and the audit.

- [ ] **Step 6: The owner's browser pass**

Sign in as HR: the bubble appears; the chips read Employee count, Pending leave
requests, Attendance today, Applicants, Open job postings, Incomplete employee
records; each answers. As Finance: Payroll this month answers with figures.
As Inventory: Low stock answers. In each, ask "how much is the salary of
<someone>" and expect the plain refusal.

---

## What Phase 2A leaves out

- The advisories, including the HR hiring suggestion — Phase 2B, its own plan.
- Cross-branch and enterprise reporting — Retail Enterprise, later.
- Any tool or answer that writes. Nothing here creates, approves or edits.
