# Conversational Assistant — Stage A2 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add the nine people-and-store tools to the conversational assistant — staff, attendance, leave, recruitment, branch and company profile — without any of them touching a salary or a payroll row.

**Architecture:** Stage A1's catalog, runner and loop are unchanged; this adds tool definitions and handlers, plus one new gate: an own-scope tool is refused when the session carries no `employee_id`. Every handler is a prepared, company-scoped `SELECT` with named columns, capped at 50 rows with a sentinel row for truncation.

**Tech Stack:** PHP 8.2.12 (XAMPP), MariaDB 10.4, mysqli. Tests are the CLI harness in `tests/chatbot/bootstrap.php` plus the Python audit.

**Spec:** `docs/superpowers/specs/2026-09-30-sarismarts-chatbot-conversational-design.md` §3 (Stage A2 table), §8

## Global Constraints

- Context array is exactly `['company_id' => int, 'user_id' => int, 'employee_id' => ?int, 'role' => string]`.
- Every statement: prepared `SELECT`, `company_id = ?` bound, columns named (never `SELECT *`), `LIMIT min($limit, CHAT_TOOL_ROW_CAP) + 1` so the runner can detect truncation and drop the sentinel.
- `own`-scope tools bind the asker's `employee_id` **as well as** `company_id`.
- **No tool may contain:** `payroll`, `salary`, `basic_pay`, `gross_pay`, `net_pay`, `overtime_pay`, `pay_frequency`, `late_deduction`, `undertime_deduction`, `absent_deduction`, `total_deduction`, `deduction_rate`. This bans `job.salary_min` / `job.salary_max` and `employment.salary` by name; the audit enforces it for files under `includes/chatbot/chat/tools`.
- Status vocabularies, read from the code that writes them (not guessed):
  - `employees.employment_status`: `Pre-Employee`, `Official Employee`, `Archived`
  - `job.status`: `Published`, `Draft`
  - `applications.status`: `Pending`, `Interview`, `Interview Result`, `Recommended`, `Rejected`, `Hired`
  - `attendance.status`: ENUM `Present`, `Late`, `Absent`, `Half Day`
  - `leave_requests.hr_status` / `admin_status`: ENUM `Pending`, `Approved`, `Rejected`
  - `branch.status`: `Active`
- Plan topics already seeded: `staff`, `attendance`, `leave`, `recruitment`, `branch` (Starter has `staff` only among these).
- The project is not a git repository: each task ends with a *Checkpoint* (full suite + audit) instead of a commit.
- Suite: `C:/xampp/php/php.exe tests/chatbot/run_all.php`; audit: `python tests/chatbot/query_audit.py`.

## Review Focus

1. **An own-scope tool called by an account with no employee record** — an Owner/Admin has no `employees` row; the tool must be refused, never run with a NULL employee id. Test in Task 3.
2. **A session whose `employee_id` belongs to another company** — binding the employee id alone would cross the tenant boundary; both keys must be bound. Test in Task 3.
3. **`recruitment_summary` and the advertised pay range** — `job.salary_min` / `salary_max` sit in the same table the tool reads; they must never be selected, and the audit must fail if they are. Test in Task 5.
4. **`attendance_detail` naming an employee of another company** — the employee name comes from the model as free text; the lookup must stay inside the asker's company. Test in Task 2.
5. **A Retail Starter company reaching people tools it did not buy** — Starter has `staff` but not `attendance`, `leave`, `recruitment` or `branch`; the personal tools (under `staff`) must still work for its cashier. Test in Task 7.

---

### Task 1: Staff list and company profile

**Files:**
- Create: `includes/chatbot/chat/tools/people.php`
- Modify: `includes/chatbot/chat/tools.php` (add `staff_list`, `company_profile`)
- Modify: `includes/chatbot/chat/tool_runner.php` (require the new file)
- Test: `tests/chatbot/test_chat_people_tools.php`

**Interfaces:**
- Consumes: `chatRunTool()`, `CHAT_TOOL_ROW_CAP`.
- Produces: `chatToolStaffList(mysqli $conn, array $ctx, array $in): array`, `chatToolCompanyProfile(mysqli $conn, array $ctx, array $in): array`, each returning `['columns'=>string[],'rows'=>array<array<string>>]`.

- [ ] **Step 1: Write the failing test**

`tests/chatbot/test_chat_people_tools.php`:

```php
<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/factory.php';
require_once __DIR__ . '/../../includes/chatbot/engine.php';
require_once __DIR__ . '/../../includes/chatbot/chat/tool_runner.php';

register_shutdown_function(function () use ($conn) { testCleanup($conn); });

$companyId = testMakeCompany($conn, 'People Co', 2);
$ctx = ['company_id' => $companyId, 'user_id' => 1, 'employee_id' => null, 'role' => 'admin'];

$conn->query("INSERT INTO branch (company_id, branch_name, complete_address, province, city, status)
              VALUES ({$companyId}, 'Main Branch', '1 Test St', 'Cavite', 'Imus', 'Active')");
$branchId = (int) $conn->insert_id;

$conn->query("INSERT INTO employees (company_id, first_name, last_name, branch_id, employment_status)
              VALUES ({$companyId}, 'Ana', 'Cruz', {$branchId}, 'Official Employee')");
$anaId = (int) $conn->insert_id;

$conn->query("INSERT INTO employees (company_id, first_name, last_name, employment_status)
              VALUES ({$companyId}, 'Ben', 'Santos', 'Archived')");

$conn->query("INSERT INTO users (company_id, employee_id, username, fullname, email, password, role, status)
              VALUES ({$companyId}, {$anaId}, 'ana', 'Ana Cruz', 'ana@test.local', 'x', 'Cashier', 'active')");

/* A salary that must never appear in any answer. */
$conn->query("INSERT INTO employment (employee_id, employment_type, employment_status, salary, salary_type, company_id)
              VALUES ({$anaId}, 'Full Time', 'Regular', 25000.00, 'Monthly', {$companyId})");

$result = chatRunTool($conn, $ctx, 'staff_list', ['state' => 'active']);

t_ok($result['ok'], 'staff_list runs');
t_ok(str_contains(json_encode($result), 'Ana'), 'it lists the active employee');
t_ok(!str_contains(json_encode($result), 'Ben'), 'and leaves out the archived one');
t_ok(str_contains(json_encode($result), 'Main Branch'), 'with the branch they belong to');

/* The whole point of the owner's decision. */
t_ok(!str_contains(json_encode($result), '25000'), 'no salary figure appears');

foreach ($result['columns'] as $column) {
    t_ok(!str_contains(strtolower($column), 'salary'), "column {$column} is not a salary");
    t_ok(!str_contains(strtolower($column), 'pay'), "column {$column} is not a pay field");
}

$result = chatRunTool($conn, $ctx, 'staff_list', ['state' => 'all']);
t_ok(str_contains(json_encode($result), 'Ben'), 'all includes the archived employee');

/* company_profile */
$result = chatRunTool($conn, $ctx, 'company_profile', []);
t_ok($result['ok'], 'company_profile runs');

$json = json_encode($result);
t_ok(str_contains($json, 'CHATBOT-TEST People Co'), 'it names the business');
t_ok(str_contains($json, 'Retail Professional'), 'and the plan they are on');

/* The company table also holds the approval token, the TIN and the paths to
   the uploaded permits. Asserting the exact column list is what keeps them
   out -- a "does not contain" check would pass on a column nobody noticed. */
t_same(['business', 'city', 'province', 'business_size', 'plan', 'branches',
        'active_staff'], $result['columns'],
    'it returns exactly the columns meant for a chat answer');

/* The cashier may not ask either of these in A2. */
$cashierCtx = ['company_id' => $companyId, 'user_id' => 1, 'employee_id' => $anaId, 'role' => 'cashier'];
t_ok(!chatRunTool($conn, $cashierCtx, 'staff_list', ['state' => 'active'])['ok'],
    'a cashier cannot list the staff');
t_ok(!chatRunTool($conn, $cashierCtx, 'company_profile', [])['ok'],
    'nor read the company profile');

t_done();
```

- [ ] **Step 2: Run it and watch it fail**

Run: `C:/xampp/php/php.exe tests/chatbot/test_chat_people_tools.php`
Expected: FAIL — "There is no tool called staff_list available to you."

- [ ] **Step 3: Add the two catalog entries**

In `includes/chatbot/chat/tools.php`, before the closing `];` of `chatTools()`:

```php
        'staff_list' => [
            'topic' => 'staff',
            'roles' => ['admin'],
            'scope' => 'company',
            'description' => 'The people on the team: name, role, branch and whether '
                . 'they are active. Use for "how many staff", "who works at which '
                . 'branch", "sino ang mga empleyado".',
            'input' => [
                'state' => [
                    'type' => 'enum',
                    'values' => ['active', 'all'],
                    'required' => true,
                ],
                'limit' => ['type' => 'int', 'min' => 1, 'max' => 50],
            ],
            'handler' => 'chatToolStaffList',
        ],

        'company_profile' => [
            'topic' => 'staff',
            'roles' => ['admin'],
            'scope' => 'company',
            'description' => 'The business itself: name, city, province, subscription '
                . 'plan and number of branches.',
            'input' => [],
            'handler' => 'chatToolCompanyProfile',
        ],
```

- [ ] **Step 4: Write the handlers**

`includes/chatbot/chat/tools/people.php`:

```php
<?php
/*
| People tools.
|
| Columns are named one by one, and none of them is pay. employment.salary sits
| one join away from everything here, so the rule is enforced by never writing
| the join -- and by the audit, which fails this file if the word appears.
*/

function chatToolStaffList(mysqli $conn, array $ctx, array $in): array
{
    $limit = min((int) ($in['limit'] ?? CHAT_TOOL_ROW_CAP), CHAT_TOOL_ROW_CAP) + 1;

    /* Built from a validated enum, never from model text. */
    $condition = $in['state'] === 'active'
        ? "e.employment_status <> 'Archived'"
        : '1 = 1';

    $stmt = $conn->prepare("
        SELECT CONCAT(e.first_name, ' ', e.last_name) AS employee,
               e.employment_status,
               COALESCE(b.branch_name, 'No branch') AS branch,
               COALESCE(u.role, 'No account') AS system_role
        FROM employees e
        LEFT JOIN branch b ON b.branch_id = e.branch_id AND b.company_id = e.company_id
        LEFT JOIN users u ON u.employee_id = e.employee_id AND u.company_id = e.company_id
        WHERE e.company_id = ? AND {$condition}
        ORDER BY e.last_name, e.first_name
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

    return ['columns' => ['employee', 'employment_status', 'branch', 'system_role'],
            'rows' => $rows];
}

function chatToolCompanyProfile(mysqli $conn, array $ctx, array $in): array
{
    /* Named columns only: this table also holds the approval token, the TIN and
       the uploaded permit paths, none of which belong in a chat answer. */
    $stmt = $conn->prepare("
        SELECT c.company_name, c.city, c.province, c.business_size,
               COALESCE(p.plan_name, 'No active plan') AS plan_name,
               (SELECT COUNT(*) FROM branch b WHERE b.company_id = c.company_id) AS branches,
               (SELECT COUNT(*) FROM employees e
                WHERE e.company_id = c.company_id
                  AND e.employment_status <> 'Archived') AS active_staff
        FROM company c
        LEFT JOIN company_subscriptions cs
               ON cs.company_id = c.company_id AND cs.status IN ('Active', 'Trial')
        LEFT JOIN subscription_plans p ON p.plan_id = cs.plan_id
        WHERE c.company_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $ctx['company_id']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return ['columns' => [], 'rows' => []];
    }

    return [
        'columns' => ['business', 'city', 'province', 'business_size', 'plan',
                      'branches', 'active_staff'],
        'rows' => [array_map(static fn ($value): string => (string) $value, array_values($row))],
    ];
}
```

Add beside the other requires in `tool_runner.php`:

```php
require_once __DIR__ . '/tools/people.php';
```

- [ ] **Step 5: Run the test**

Run: `C:/xampp/php/php.exe tests/chatbot/test_chat_people_tools.php`
Expected: PASS.

- [ ] **Step 6: Checkpoint**

Run: `C:/xampp/php/php.exe tests/chatbot/run_all.php` and `python tests/chatbot/query_audit.py` — expect `ALL TESTS PASSED` and `0 problem(s)`.

---

### Task 2: Attendance — company-wide

**Files:**
- Create: `includes/chatbot/chat/tools/attendance.php`
- Modify: `includes/chatbot/chat/tools.php` (add `attendance_summary`, `attendance_detail`)
- Modify: `includes/chatbot/chat/tool_runner.php` (require)
- Test: `tests/chatbot/test_chat_attendance_tools.php`

**Interfaces:**
- Consumes: `chatResolvePeriod()`, `chatbotNormalise()`.
- Produces: `chatToolAttendanceSummary(mysqli $conn, array $ctx, array $in): array`, `chatToolAttendanceDetail(mysqli $conn, array $ctx, array $in): array`.

- [ ] **Step 1: Write the failing test**

`tests/chatbot/test_chat_attendance_tools.php`:

```php
<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/factory.php';
require_once __DIR__ . '/../../includes/chatbot/engine.php';
require_once __DIR__ . '/../../includes/chatbot/chat/tool_runner.php';

register_shutdown_function(function () use ($conn) { testCleanup($conn); });

$companyId = testMakeCompany($conn, 'Attendance Co', 2);
$otherId = testMakeCompany($conn, 'Attendance Other Co', 2);

$ctx = ['company_id' => $companyId, 'user_id' => 1, 'employee_id' => null, 'role' => 'admin'];

$conn->query("INSERT INTO employees (company_id, first_name, last_name, employment_status)
              VALUES ({$companyId}, 'Ana', 'Cruz', 'Official Employee')");
$ana = (int) $conn->insert_id;

$conn->query("INSERT INTO employees (company_id, first_name, last_name, employment_status)
              VALUES ({$otherId}, 'Ana', 'Reyes', 'Official Employee')");
$otherAna = (int) $conn->insert_id;

$conn->query("INSERT INTO attendance (employee_id, attendance_date, time_in, status, late_minutes, company_id)
              VALUES ({$ana}, CURDATE(), CONCAT(CURDATE(), ' 08:20:00'), 'Late', 20, {$companyId})");
$conn->query("INSERT INTO attendance (employee_id, attendance_date, time_in, status, late_minutes, company_id)
              VALUES ({$ana}, DATE_SUB(CURDATE(), INTERVAL 1 DAY),
                      CONCAT(DATE_SUB(CURDATE(), INTERVAL 1 DAY), ' 07:55:00'), 'Present', 0, {$companyId})");

/* The other company's employee is late every day -- none of it may show. */
$conn->query("INSERT INTO attendance (employee_id, attendance_date, time_in, status, late_minutes, company_id)
              VALUES ({$otherAna}, CURDATE(), CONCAT(CURDATE(), ' 09:45:00'), 'Late', 105, {$otherId})");

$result = chatRunTool($conn, $ctx, 'attendance_summary', ['period' => 'this_month']);

t_ok($result['ok'], 'attendance_summary runs');
$json = json_encode($result);
t_ok(str_contains($json, 'Ana Cruz'), 'it names our employee');
t_ok(!str_contains($json, 'Ana Reyes'), "and not the other company's");
t_ok(!str_contains($json, '105'), "nor the other company's late minutes");

/* Review Focus 4: a name that belongs to another company. */
$result = chatRunTool($conn, $ctx, 'attendance_detail',
    ['employee' => 'Reyes', 'period' => 'this_month']);
t_ok($result['ok'], 'asking about an outside name is not an error');
t_same([], $result['rows'], 'it simply finds nobody');

$result = chatRunTool($conn, $ctx, 'attendance_detail',
    ['employee' => 'Cruz', 'period' => 'this_month']);
t_ok(count($result['rows']) >= 2, 'our own employee has their days listed');
t_ok(str_contains(json_encode($result), '08:20'), 'with the time they clocked in');

/* A cashier may not ask about the company's attendance. */
$cashierCtx = ['company_id' => $companyId, 'user_id' => 1, 'employee_id' => $ana, 'role' => 'cashier'];
t_ok(!chatRunTool($conn, $cashierCtx, 'attendance_summary', ['period' => 'this_month'])['ok'],
    'a cashier cannot read company attendance');
t_ok(!chatRunTool($conn, $cashierCtx, 'attendance_detail',
    ['employee' => 'Cruz', 'period' => 'this_month'])['ok'],
    'nor a colleague\'s attendance detail');

t_done();
```

- [ ] **Step 2: Run it and watch it fail**

Run: `C:/xampp/php/php.exe tests/chatbot/test_chat_attendance_tools.php`
Expected: FAIL — no tool called `attendance_summary`.

- [ ] **Step 3: Add the catalog entries**

In `chatTools()`:

```php
        'attendance_summary' => [
            'topic' => 'attendance',
            'roles' => ['admin'],
            'scope' => 'company',
            'description' => 'Per-employee attendance counts for a period: days present, '
                . 'late, absent and total late minutes. Use for "sino ang madalas '
                . 'ma-late", "ilan ang absent".',
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
            'handler' => 'chatToolAttendanceSummary',
        ],

        'attendance_detail' => [
            'topic' => 'attendance',
            'roles' => ['admin'],
            'scope' => 'company',
            'description' => 'One employee\'s day-by-day time in and time out for a '
                . 'period. Give part of their name.',
            'input' => [
                'employee' => ['type' => 'string', 'required' => true, 'max' => 100],
                'period' => [
                    'type' => 'enum',
                    'values' => ['today', 'yesterday', 'this_week', 'last_week',
                                 'this_month', 'last_month', 'custom'],
                    'required' => true,
                ],
                'from' => ['type' => 'date'],
                'to' => ['type' => 'date'],
            ],
            'handler' => 'chatToolAttendanceDetail',
        ],
```

- [ ] **Step 4: Write the handlers**

`includes/chatbot/chat/tools/attendance.php`:

```php
<?php
/*
| Attendance tools. Times, lateness and absence -- never what any of it is
| worth, which is payroll's business and has no tool here.
*/

function chatToolAttendanceSummary(mysqli $conn, array $ctx, array $in): array
{
    $range = chatResolvePeriod($in['period'], $in['from'] ?? null, $in['to'] ?? null);
    $limit = min((int) ($in['limit'] ?? CHAT_TOOL_ROW_CAP), CHAT_TOOL_ROW_CAP) + 1;

    $stmt = $conn->prepare("
        SELECT CONCAT(e.first_name, ' ', e.last_name) AS employee,
               SUM(a.status = 'Present') AS days_present,
               SUM(a.status = 'Late') AS days_late,
               SUM(a.status = 'Absent') AS days_absent,
               COALESCE(SUM(a.late_minutes), 0) AS late_minutes
        FROM attendance a
        JOIN employees e ON e.employee_id = a.employee_id AND e.company_id = a.company_id
        WHERE a.company_id = ? AND a.attendance_date BETWEEN ? AND ?
        GROUP BY e.employee_id, e.first_name, e.last_name
        ORDER BY late_minutes DESC, employee
        LIMIT ?
    ");
    $stmt->bind_param("issi", $ctx['company_id'], $range['from'], $range['to'], $limit);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];

    while ($row = $result->fetch_assoc()) {
        $rows[] = array_map(static fn ($value): string => (string) $value, array_values($row));
    }

    $stmt->close();

    return ['columns' => ['employee', 'days_present', 'days_late', 'days_absent',
                          'late_minutes'],
            'rows' => $rows];
}

function chatToolAttendanceDetail(mysqli $conn, array $ctx, array $in): array
{
    $range = chatResolvePeriod($in['period'], $in['from'] ?? null, $in['to'] ?? null);

    /* The name comes from the model as free text; normalised the same way
       Phase 1 does, then bound. */
    $like = '%' . chatbotNormalise((string) $in['employee']) . '%';

    $stmt = $conn->prepare("
        SELECT a.attendance_date,
               TIME(a.time_in) AS time_in,
               TIME(a.time_out) AS time_out,
               a.status,
               COALESCE(a.late_minutes, 0) AS late_minutes
        FROM attendance a
        JOIN employees e ON e.employee_id = a.employee_id AND e.company_id = a.company_id
        WHERE a.company_id = ?
          AND a.attendance_date BETWEEN ? AND ?
          AND CONCAT(e.first_name, ' ', e.last_name) LIKE ?
        ORDER BY a.attendance_date DESC
        LIMIT 51
    ");
    $stmt->bind_param("isss", $ctx['company_id'], $range['from'], $range['to'], $like);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];

    while ($row = $result->fetch_assoc()) {
        $rows[] = array_map(static fn ($value): string => (string) $value, array_values($row));
    }

    $stmt->close();

    return ['columns' => ['date', 'time_in', 'time_out', 'status', 'late_minutes'],
            'rows' => $rows];
}
```

Add the require in `tool_runner.php`:

```php
require_once __DIR__ . '/tools/attendance.php';
```

- [ ] **Step 5: Run the test**

Run: `C:/xampp/php/php.exe tests/chatbot/test_chat_attendance_tools.php`
Expected: PASS.

- [ ] **Step 6: Checkpoint**

Run the suite and the audit — expect both clean.

---

### Task 3: The personal tools, and the employee gate

**Files:**
- Create: `includes/chatbot/chat/tools/personal.php`
- Modify: `includes/chatbot/chat/tools.php` (add `my_attendance`, `my_leave`)
- Modify: `includes/chatbot/chat/tool_runner.php` (require, and refuse own-scope tools with no employee)
- Test: `tests/chatbot/test_chat_personal_tools.php`

**Interfaces:**
- Consumes: `$ctx['employee_id']`.
- Produces: `chatToolMyAttendance(mysqli $conn, array $ctx, array $in): array`, `chatToolMyLeave(mysqli $conn, array $ctx, array $in): array`, and a refusal in `chatRunTool()` when a tool's `scope` is `own` and `$ctx['employee_id']` is empty.

- [ ] **Step 1: Write the failing test**

`tests/chatbot/test_chat_personal_tools.php`:

```php
<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/factory.php';
require_once __DIR__ . '/../../includes/chatbot/engine.php';
require_once __DIR__ . '/../../includes/chatbot/chat/tool_runner.php';

register_shutdown_function(function () use ($conn) { testCleanup($conn); });

$companyId = testMakeCompany($conn, 'Personal Tools Co', 2);
$otherId = testMakeCompany($conn, 'Personal Other Co', 2);

$conn->query("INSERT INTO employees (company_id, first_name, last_name, employment_status)
              VALUES ({$companyId}, 'Ana', 'Cruz', 'Official Employee')");
$mine = (int) $conn->insert_id;

$conn->query("INSERT INTO employees (company_id, first_name, last_name, employment_status)
              VALUES ({$companyId}, 'Ben', 'Santos', 'Official Employee')");
$colleague = (int) $conn->insert_id;

$conn->query("INSERT INTO employees (company_id, first_name, last_name, employment_status)
              VALUES ({$otherId}, 'Cely', 'Diaz', 'Official Employee')");
$outsider = (int) $conn->insert_id;

$conn->query("INSERT INTO attendance (employee_id, attendance_date, time_in, status, company_id)
              VALUES ({$mine}, CURDATE(), CONCAT(CURDATE(), ' 08:02:00'), 'Present', {$companyId})");
$conn->query("INSERT INTO attendance (employee_id, attendance_date, time_in, status, company_id)
              VALUES ({$colleague}, CURDATE(), CONCAT(CURDATE(), ' 07:30:00'), 'Present', {$companyId})");
$conn->query("INSERT INTO attendance (employee_id, attendance_date, time_in, status, company_id)
              VALUES ({$outsider}, CURDATE(), CONCAT(CURDATE(), ' 06:15:00'), 'Present', {$otherId})");

$conn->query("INSERT INTO leave_requests (employee_id, leave_type, duration, start_date, end_date, reason, hr_status, admin_status, company_id)
              VALUES ({$mine}, 'Sick Leave', 'Whole Day', CURDATE(), CURDATE(), 'Lagnat', 'Pending', 'Pending', {$companyId})");

$ctx = ['company_id' => $companyId, 'user_id' => 5,
        'employee_id' => $mine, 'role' => 'cashier'];

$result = chatRunTool($conn, $ctx, 'my_attendance', ['period' => 'this_month']);
t_ok($result['ok'], 'my_attendance runs for a cashier');
$json = json_encode($result);
t_ok(str_contains($json, '08:02'), 'it shows my own time in');
t_ok(!str_contains($json, '07:30'), "and not my colleague's");
t_ok(!str_contains($json, '06:15'), "and not another company's");

$result = chatRunTool($conn, $ctx, 'my_leave', ['status' => 'all']);
t_ok(str_contains(json_encode($result), 'Sick Leave'), 'my_leave shows my request');

/* Review Focus 1: an account with no employee record. */
$ownerCtx = ['company_id' => $companyId, 'user_id' => 9,
             'employee_id' => null, 'role' => 'employee'];
$result = chatRunTool($conn, $ownerCtx, 'my_attendance', ['period' => 'this_month']);
t_ok(!$result['ok'], 'an account with no employee record is refused');
t_ok(str_contains(strtolower((string) $result['error']), 'employee'),
    'and the model is told why');
t_same([], $result['rows'], 'with no rows at all');

/* Review Focus 2: an employee id from another company. */
$crossCtx = ['company_id' => $companyId, 'user_id' => 5,
             'employee_id' => $outsider, 'role' => 'cashier'];
$result = chatRunTool($conn, $crossCtx, 'my_attendance', ['period' => 'this_month']);
t_ok($result['ok'], 'a mismatched employee id is not an error');
t_same([], $result['rows'], 'but it finds nothing, because both keys are bound');

/* An admin has no personal tools in this stage. */
$adminCtx = ['company_id' => $companyId, 'user_id' => 1,
             'employee_id' => null, 'role' => 'admin'];
$result = chatRunTool($conn, $adminCtx, 'my_attendance', ['period' => 'this_month']);
t_ok(!$result['ok'], 'an admin does not get the personal tool');

t_done();
```

- [ ] **Step 2: Run it and watch it fail**

Run: `C:/xampp/php/php.exe tests/chatbot/test_chat_personal_tools.php`
Expected: FAIL — no tool called `my_attendance`.

- [ ] **Step 3: Add the catalog entries**

```php
        'my_attendance' => [
            'topic' => 'staff',
            'roles' => ['cashier', 'employee'],
            'scope' => 'own',
            'description' => 'The asker\'s own attendance for a period: date, time in, '
                . 'time out, status.',
            'input' => [
                'period' => [
                    'type' => 'enum',
                    'values' => ['today', 'yesterday', 'this_week', 'last_week',
                                 'this_month', 'last_month', 'custom'],
                    'required' => true,
                ],
                'from' => ['type' => 'date'],
                'to' => ['type' => 'date'],
            ],
            'handler' => 'chatToolMyAttendance',
        ],

        'my_leave' => [
            'topic' => 'staff',
            'roles' => ['cashier', 'employee'],
            'scope' => 'own',
            'description' => 'The asker\'s own leave requests and where each one stands '
                . 'with HR and with the owner.',
            'input' => [
                'status' => [
                    'type' => 'enum',
                    'values' => ['Pending', 'Approved', 'Rejected', 'all'],
                    'required' => true,
                ],
            ],
            'handler' => 'chatToolMyLeave',
        ],
```

- [ ] **Step 4: Add the employee gate to the runner**

In `chatRunTool()`, immediately after the `$tool = $available[$name];` line:

```php
    /*
    | A question about the asker's own records needs a record to point at. An
    | Owner/Admin account has no employees row, so binding a null employee id
    | would quietly answer "no attendance" to someone whose real answer is
    | "your account is not an employee".
    */
    if ($tool['scope'] === 'own' && empty($ctx['employee_id'])) {
        return $refusal('This account has no employee record, so there is nothing personal to show.');
    }
```

- [ ] **Step 5: Write the handlers**

`includes/chatbot/chat/tools/personal.php`:

```php
<?php
/*
| The asker's own records.
|
| Both statements bind company_id AND the asker's employee_id. Either alone
| would be a leak: company_id without employee_id shows a colleague's record,
| employee_id without company_id trusts an id that may belong to another tenant.
*/

function chatToolMyAttendance(mysqli $conn, array $ctx, array $in): array
{
    $range = chatResolvePeriod($in['period'], $in['from'] ?? null, $in['to'] ?? null);

    $stmt = $conn->prepare("
        SELECT attendance_date,
               TIME(time_in) AS time_in,
               TIME(time_out) AS time_out,
               status,
               COALESCE(late_minutes, 0) AS late_minutes
        FROM attendance
        WHERE company_id = ? AND employee_id = ?
          AND attendance_date BETWEEN ? AND ?
        ORDER BY attendance_date DESC
        LIMIT 51
    ");
    $stmt->bind_param("iiss", $ctx['company_id'], $ctx['employee_id'],
        $range['from'], $range['to']);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];

    while ($row = $result->fetch_assoc()) {
        $rows[] = array_map(static fn ($value): string => (string) $value, array_values($row));
    }

    $stmt->close();

    return ['columns' => ['date', 'time_in', 'time_out', 'status', 'late_minutes'],
            'rows' => $rows];
}

function chatToolMyLeave(mysqli $conn, array $ctx, array $in): array
{
    $status = (string) $in['status'];

    if ($status === 'all') {
        $stmt = $conn->prepare("
            SELECT leave_type, start_date, end_date, hr_status, admin_status, created_at
            FROM leave_requests
            WHERE company_id = ? AND employee_id = ?
            ORDER BY created_at DESC
            LIMIT 51
        ");
        $stmt->bind_param("ii", $ctx['company_id'], $ctx['employee_id']);
    } else {
        $stmt = $conn->prepare("
            SELECT leave_type, start_date, end_date, hr_status, admin_status, created_at
            FROM leave_requests
            WHERE company_id = ? AND employee_id = ? AND hr_status = ?
            ORDER BY created_at DESC
            LIMIT 51
        ");
        $stmt->bind_param("iis", $ctx['company_id'], $ctx['employee_id'], $status);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];

    while ($row = $result->fetch_assoc()) {
        $rows[] = array_map(static fn ($value): string => (string) $value, array_values($row));
    }

    $stmt->close();

    return ['columns' => ['leave_type', 'start_date', 'end_date', 'hr_status',
                          'admin_status', 'requested_at'],
            'rows' => $rows];
}
```

Add the require in `tool_runner.php`:

```php
require_once __DIR__ . '/tools/personal.php';
```

- [ ] **Step 6: Run the test**

Run: `C:/xampp/php/php.exe tests/chatbot/test_chat_personal_tools.php`
Expected: PASS.

- [ ] **Step 7: Checkpoint**

Run the suite and the audit — expect both clean.

---

### Task 4: Leave requests — company-wide

**Files:**
- Create: `includes/chatbot/chat/tools/leave.php`
- Modify: `includes/chatbot/chat/tools.php` (add `leave_requests`)
- Modify: `includes/chatbot/chat/tool_runner.php` (require)
- Test: `tests/chatbot/test_chat_leave_tools.php`

**Interfaces:**
- Produces: `chatToolLeaveRequests(mysqli $conn, array $ctx, array $in): array`.

- [ ] **Step 1: Write the failing test**

`tests/chatbot/test_chat_leave_tools.php`:

```php
<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/factory.php';
require_once __DIR__ . '/../../includes/chatbot/engine.php';
require_once __DIR__ . '/../../includes/chatbot/chat/tool_runner.php';

register_shutdown_function(function () use ($conn) { testCleanup($conn); });

$companyId = testMakeCompany($conn, 'Leave Co', 2);
$otherId = testMakeCompany($conn, 'Leave Other Co', 2);
$ctx = ['company_id' => $companyId, 'user_id' => 1, 'employee_id' => null, 'role' => 'admin'];

$conn->query("INSERT INTO employees (company_id, first_name, last_name, employment_status)
              VALUES ({$companyId}, 'Ana', 'Cruz', 'Official Employee')");
$ana = (int) $conn->insert_id;

$conn->query("INSERT INTO employees (company_id, first_name, last_name, employment_status)
              VALUES ({$otherId}, 'Outside', 'Person', 'Official Employee')");
$outsider = (int) $conn->insert_id;

$conn->query("INSERT INTO leave_requests (employee_id, leave_type, duration, start_date, end_date, reason, hr_status, admin_status, company_id)
              VALUES ({$ana}, 'Sick Leave', 'Whole Day', CURDATE(), CURDATE(), 'Lagnat', 'Pending', 'Pending', {$companyId})");
$conn->query("INSERT INTO leave_requests (employee_id, leave_type, duration, start_date, end_date, reason, hr_status, admin_status, company_id)
              VALUES ({$ana}, 'Vacation Leave', 'Whole Day', CURDATE(), CURDATE(), 'Bakasyon', 'Approved', 'Approved', {$companyId})");
$conn->query("INSERT INTO leave_requests (employee_id, leave_type, duration, start_date, end_date, reason, hr_status, admin_status, company_id)
              VALUES ({$outsider}, 'Emergency Leave', 'Whole Day', CURDATE(), CURDATE(), 'Sikreto', 'Pending', 'Pending', {$otherId})");

$result = chatRunTool($conn, $ctx, 'leave_requests', ['status' => 'Pending']);
t_ok($result['ok'], 'leave_requests runs');
$json = json_encode($result);
t_ok(str_contains($json, 'Sick Leave'), 'the pending request is listed');
t_ok(!str_contains($json, 'Vacation Leave'), 'the approved one is not');
t_ok(!str_contains($json, 'Emergency Leave'), "and neither is another company's");
t_ok(str_contains($json, 'Ana Cruz'), 'the employee is named');

$result = chatRunTool($conn, $ctx, 'leave_requests', ['status' => 'all']);
t_ok(str_contains(json_encode($result), 'Vacation Leave'), 'all includes the approved one');

/* The reason an employee gives is theirs; it is not part of an overview. */
t_ok(!str_contains(json_encode($result), 'Lagnat'),
    'the private reason is not exposed in the company-wide list');

$cashierCtx = ['company_id' => $companyId, 'user_id' => 1, 'employee_id' => $ana, 'role' => 'cashier'];
t_ok(!chatRunTool($conn, $cashierCtx, 'leave_requests', ['status' => 'all'])['ok'],
    'a cashier cannot read everyone\'s leave');

t_done();
```

- [ ] **Step 2: Run it and watch it fail**

Run: `C:/xampp/php/php.exe tests/chatbot/test_chat_leave_tools.php`
Expected: FAIL — no tool called `leave_requests`.

- [ ] **Step 3: Add the catalog entry**

```php
        'leave_requests' => [
            'topic' => 'leave',
            'roles' => ['admin'],
            'scope' => 'company',
            'description' => 'Leave requests across the team and where each stands with '
                . 'HR and with the owner. Use for "sino ang may pending leave".',
            'input' => [
                'status' => [
                    'type' => 'enum',
                    'values' => ['Pending', 'Approved', 'Rejected', 'all'],
                    'required' => true,
                ],
                'limit' => ['type' => 'int', 'min' => 1, 'max' => 50],
            ],
            'handler' => 'chatToolLeaveRequests',
        ],
```

- [ ] **Step 4: Write the handler**

`includes/chatbot/chat/tools/leave.php`:

```php
<?php
/*
| Leave requests, company-wide.
|
| The 'reason' column is deliberately not selected: an overview of who is off
| does not need to carry why, and "Lagnat" or a family matter is the employee's
| to tell. The approval pages show it to the people who decide.
*/

function chatToolLeaveRequests(mysqli $conn, array $ctx, array $in): array
{
    $limit = min((int) ($in['limit'] ?? CHAT_TOOL_ROW_CAP), CHAT_TOOL_ROW_CAP) + 1;
    $status = (string) $in['status'];

    $sql = "
        SELECT CONCAT(e.first_name, ' ', e.last_name) AS employee,
               l.leave_type, l.duration, l.start_date, l.end_date,
               l.hr_status, l.admin_status
        FROM leave_requests l
        JOIN employees e ON e.employee_id = l.employee_id AND e.company_id = l.company_id
        WHERE l.company_id = ?
    ";

    if ($status !== 'all') {
        $sql .= " AND l.hr_status = ? ";
    }

    $sql .= " ORDER BY l.created_at DESC LIMIT ? ";

    $stmt = $conn->prepare($sql);

    if ($status === 'all') {
        $stmt->bind_param("ii", $ctx['company_id'], $limit);
    } else {
        $stmt->bind_param("isi", $ctx['company_id'], $status, $limit);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];

    while ($row = $result->fetch_assoc()) {
        $rows[] = array_map(static fn ($value): string => (string) $value, array_values($row));
    }

    $stmt->close();

    return ['columns' => ['employee', 'leave_type', 'duration', 'start_date', 'end_date',
                          'hr_status', 'admin_status'],
            'rows' => $rows];
}
```

Add the require in `tool_runner.php`.

- [ ] **Step 5: Run the test**

Run: `C:/xampp/php/php.exe tests/chatbot/test_chat_leave_tools.php`
Expected: PASS.

- [ ] **Step 6: Checkpoint**

Run the suite and the audit.

---

### Task 5: Recruitment, without the pay range

**Files:**
- Create: `includes/chatbot/chat/tools/recruitment.php`
- Modify: `includes/chatbot/chat/tools.php` (add `recruitment_summary`)
- Modify: `includes/chatbot/chat/tool_runner.php` (require)
- Test: `tests/chatbot/test_chat_recruitment_tools.php`

**Interfaces:**
- Produces: `chatToolRecruitmentSummary(mysqli $conn, array $ctx, array $in): array`.

- [ ] **Step 1: Write the failing test**

`tests/chatbot/test_chat_recruitment_tools.php`:

```php
<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/factory.php';
require_once __DIR__ . '/../../includes/chatbot/engine.php';
require_once __DIR__ . '/../../includes/chatbot/chat/tool_runner.php';

register_shutdown_function(function () use ($conn) { testCleanup($conn); });

$companyId = testMakeCompany($conn, 'Recruit Co', 2);
$otherId = testMakeCompany($conn, 'Recruit Other Co', 2);
$ctx = ['company_id' => $companyId, 'user_id' => 1, 'employee_id' => null, 'role' => 'admin'];

/* A posting with an advertised pay range that must never be returned. */
$conn->query("INSERT INTO job (job_title, department, vacancies, employment_type, status,
                               salary_min, salary_max, application_deadline, company_id)
              VALUES ('Cashier', 'Cashier', 2, 'Full Time', 'Published',
                      18000, 22000, DATE_ADD(CURDATE(), INTERVAL 30 DAY), {$companyId})");
$jobId = (int) $conn->insert_id;

$conn->query("INSERT INTO job (job_title, department, vacancies, employment_type, status,
                               application_deadline, company_id)
              VALUES ('Draft Role', 'Inventory', 1, 'Full Time', 'Draft',
                      DATE_ADD(CURDATE(), INTERVAL 30 DAY), {$companyId})");

$conn->query("INSERT INTO job (job_title, department, vacancies, employment_type, status,
                               application_deadline, company_id)
              VALUES ('Outside Role', 'Cashier', 1, 'Full Time', 'Published',
                      DATE_ADD(CURDATE(), INTERVAL 30 DAY), {$otherId})");

$conn->query("INSERT INTO applications (job_id, first_name, last_name, email, status, company_id)
              VALUES ({$jobId}, 'Juan', 'Dela Cruz', 'juan@test.local', 'Pending', {$companyId})");
$conn->query("INSERT INTO applications (job_id, first_name, last_name, email, status, company_id)
              VALUES ({$jobId}, 'Maria', 'Santos', 'maria@test.local', 'Interview', {$companyId})");

$result = chatRunTool($conn, $ctx, 'recruitment_summary', ['state' => 'open']);

t_ok($result['ok'], 'recruitment_summary runs');
$json = json_encode($result);

t_ok(str_contains($json, 'Cashier'), 'the published posting is listed');
t_ok(!str_contains($json, 'Draft Role'), 'a draft is not an open posting');
t_ok(!str_contains($json, 'Outside Role'), "and another company's posting never appears");
t_ok(str_contains($json, '2'), 'the applicant count is there');

/* Review Focus 3: the advertised pay range lives in the same table. */
t_ok(!str_contains($json, '18000'), 'the minimum advertised pay is not returned');
t_ok(!str_contains($json, '22000'), 'nor the maximum');

foreach ($result['columns'] as $column) {
    t_ok(!str_contains(strtolower($column), 'salary'), "column {$column} is not a salary");
}

$result = chatRunTool($conn, $ctx, 'recruitment_summary', ['state' => 'all']);
t_ok(str_contains(json_encode($result), 'Draft Role'), 'all includes drafts');

$cashierCtx = ['company_id' => $companyId, 'user_id' => 1, 'employee_id' => null, 'role' => 'cashier'];
t_ok(!chatRunTool($conn, $cashierCtx, 'recruitment_summary', ['state' => 'open'])['ok'],
    'a cashier cannot read recruitment');

t_done();
```

- [ ] **Step 2: Run it and watch it fail**

Run: `C:/xampp/php/php.exe tests/chatbot/test_chat_recruitment_tools.php`
Expected: FAIL — no tool called `recruitment_summary`.

- [ ] **Step 3: Add the catalog entry**

```php
        'recruitment_summary' => [
            'topic' => 'recruitment',
            'roles' => ['admin'],
            'scope' => 'company',
            'description' => 'Job postings with how many people applied and how many '
                . 'are at each stage. Use for "ilan ang nag-apply", "may bukas ba '
                . 'tayong hiring".',
            'input' => [
                'state' => [
                    'type' => 'enum',
                    'values' => ['open', 'all'],
                    'required' => true,
                ],
                'limit' => ['type' => 'int', 'min' => 1, 'max' => 50],
            ],
            'handler' => 'chatToolRecruitmentSummary',
        ],
```

- [ ] **Step 4: Write the handler**

`includes/chatbot/chat/tools/recruitment.php`:

```php
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
               SUM(a.status = 'Interview') AS at_interview,
               SUM(a.status = 'Hired') AS hired
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
                          'applicants', 'awaiting_review', 'at_interview', 'hired'],
            'rows' => $rows];
}
```

Add the require in `tool_runner.php`.

- [ ] **Step 5: Run the test**

Run: `C:/xampp/php/php.exe tests/chatbot/test_chat_recruitment_tools.php`
Expected: PASS.

- [ ] **Step 6: Prove the pay ban would catch this file**

Temporarily add `j.salary_min` to the SELECT in `recruitment.php`.
Run: `python tests/chatbot/query_audit.py`
Expected: a problem naming `chat/tools/recruitment.php` and `salary`.
**Remove it** and re-run: `0 problem(s)`.

- [ ] **Step 7: Checkpoint**

Run the suite and the audit.

---

### Task 6: Branches

**Files:**
- Create: `includes/chatbot/chat/tools/store.php`
- Modify: `includes/chatbot/chat/tools.php` (add `branch_list`)
- Modify: `includes/chatbot/chat/tool_runner.php` (require)
- Test: `tests/chatbot/test_chat_store_tools.php`

**Interfaces:**
- Produces: `chatToolBranchList(mysqli $conn, array $ctx, array $in): array`.

- [ ] **Step 1: Write the failing test**

`tests/chatbot/test_chat_store_tools.php`:

```php
<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/factory.php';
require_once __DIR__ . '/../../includes/chatbot/engine.php';
require_once __DIR__ . '/../../includes/chatbot/chat/tool_runner.php';

register_shutdown_function(function () use ($conn) { testCleanup($conn); });

$companyId = testMakeCompany($conn, 'Store Co', 2);
$otherId = testMakeCompany($conn, 'Store Other Co', 2);
$ctx = ['company_id' => $companyId, 'user_id' => 1, 'employee_id' => null, 'role' => 'admin'];

$conn->query("INSERT INTO branch (company_id, branch_name, complete_address, province, city,
                                  opening_time, closing_time, operating_hours, status)
              VALUES ({$companyId}, 'Imus Branch', '1 Test St', 'Cavite', 'Imus',
                      '08:00:00', '17:00:00', 9.00, 'Active')");

$conn->query("INSERT INTO branch (company_id, branch_name, complete_address, province, city, status)
              VALUES ({$otherId}, 'Rival Branch', '2 Other St', 'Laguna', 'Calamba', 'Active')");

$result = chatRunTool($conn, $ctx, 'branch_list', []);

t_ok($result['ok'], 'branch_list runs');
$json = json_encode($result);
t_ok(str_contains($json, 'Imus Branch'), 'our branch is listed');
t_ok(!str_contains($json, 'Rival Branch'), "another company's branch is not");
t_ok(str_contains($json, '08:00'), 'with its opening time');

$cashierCtx = ['company_id' => $companyId, 'user_id' => 1, 'employee_id' => null, 'role' => 'cashier'];
t_ok(!chatRunTool($conn, $cashierCtx, 'branch_list', [])['ok'],
    'a cashier does not get the branch list in this stage');

t_done();
```

- [ ] **Step 2: Run it and watch it fail**

Run: `C:/xampp/php/php.exe tests/chatbot/test_chat_store_tools.php`
Expected: FAIL — no tool called `branch_list`.

- [ ] **Step 3: Add the catalog entry**

```php
        'branch_list' => [
            'topic' => 'branch',
            'roles' => ['admin'],
            'scope' => 'company',
            'description' => 'The branches of this business: name, city, province, '
                . 'opening and closing time, and whether the branch is active.',
            'input' => [],
            'handler' => 'chatToolBranchList',
        ],
```

- [ ] **Step 4: Write the handler**

`includes/chatbot/chat/tools/store.php`:

```php
<?php
/*
| The shop itself. Named columns: branch also holds coordinates and a geofence
| radius, which belong to the attendance map rather than to a chat answer.
*/

function chatToolBranchList(mysqli $conn, array $ctx, array $in): array
{
    $stmt = $conn->prepare("
        SELECT branch_name, city, province,
               TIME(opening_time) AS opening_time,
               TIME(closing_time) AS closing_time,
               operating_hours, status
        FROM branch
        WHERE company_id = ?
        ORDER BY branch_name
        LIMIT 51
    ");
    $stmt->bind_param("i", $ctx['company_id']);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];

    while ($row = $result->fetch_assoc()) {
        $rows[] = array_map(static fn ($value): string => (string) $value, array_values($row));
    }

    $stmt->close();

    return ['columns' => ['branch', 'city', 'province', 'opens', 'closes',
                          'operating_hours', 'status'],
            'rows' => $rows];
}
```

Add the require in `tool_runner.php`.

- [ ] **Step 5: Run the test**

Run: `C:/xampp/php/php.exe tests/chatbot/test_chat_store_tools.php`
Expected: PASS.

- [ ] **Step 6: Checkpoint**

Run the suite and the audit.

---

### Task 7: The whole-stage proof

**Files:**
- Modify: `tests/chatbot/test_chat_isolation.php` (extend to the nine new tools)
- Test: `tests/chatbot/test_chat_matrix.php`

**Interfaces:**
- Consumes: every tool in the catalog.
- Produces: the tests that stand behind the role matrix and the tenant boundary for Stage A2.

- [ ] **Step 1: Write the matrix test**

`tests/chatbot/test_chat_matrix.php`:

```php
<?php
/*
| Which tools each role gets, on each plan -- spec section 3, as a table.
*/
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/factory.php';
require_once __DIR__ . '/../../includes/chatbot/engine.php';
require_once __DIR__ . '/../../includes/chatbot/chat/tool_runner.php';

register_shutdown_function(function () use ($conn) { testCleanup($conn); });

$starter = testMakeCompany($conn, 'Matrix Chat Starter', 1);
$professional = testMakeCompany($conn, 'Matrix Chat Pro', 2);

$matrix = [
    'admin' => [
        'sales_summary' => true, 'sales_by_day' => true, 'top_products' => true,
        'payment_mix' => true, 'stock_list' => true, 'product_lookup' => true,
        'stock_requests' => true, 'staff_list' => true, 'company_profile' => true,
        'attendance_summary' => true, 'attendance_detail' => true,
        'leave_requests' => true, 'recruitment_summary' => true, 'branch_list' => true,
        'my_attendance' => false, 'my_leave' => false,
    ],
    'cashier' => [
        'stock_list' => true, 'product_lookup' => true,
        'my_attendance' => true, 'my_leave' => true,
        'sales_summary' => false, 'staff_list' => false, 'attendance_summary' => false,
        'leave_requests' => false, 'recruitment_summary' => false, 'branch_list' => false,
        'company_profile' => false, 'stock_requests' => false,
    ],
    'employee' => [
        'my_attendance' => true, 'my_leave' => true,
        'stock_list' => false, 'product_lookup' => false, 'sales_summary' => false,
        'staff_list' => false, 'attendance_summary' => false,
    ],
    'hr' => [
        'staff_list' => false, 'attendance_summary' => false, 'leave_requests' => false,
        'recruitment_summary' => false, 'sales_summary' => false,
    ],
    'finance' => ['sales_summary' => false, 'leave_requests' => false],
    'inventory' => ['stock_list' => false, 'sales_summary' => false],
];

foreach ($matrix as $role => $expectations) {
    $tools = chatToolsFor($conn, ['company_id' => $professional, 'user_id' => 1,
                                  'employee_id' => 1, 'role' => $role]);

    foreach ($expectations as $tool => $mayUse) {
        t_same($mayUse, isset($tools[$tool]),
            "{$role} " . ($mayUse ? 'gets' : 'does NOT get') . " {$tool}");
    }
}

/* Review Focus 5: Retail Starter bought pos, inventory, staff and reports. */
$starterAdmin = chatToolsFor($conn, ['company_id' => $starter, 'user_id' => 1,
                                     'employee_id' => null, 'role' => 'admin']);

foreach (['sales_summary', 'stock_list', 'staff_list', 'company_profile', 'top_products'] as $tool) {
    t_ok(isset($starterAdmin[$tool]), "Starter admin keeps {$tool}");
}

foreach (['attendance_summary', 'attendance_detail', 'leave_requests',
          'recruitment_summary', 'branch_list'] as $tool) {
    t_ok(!isset($starterAdmin[$tool]), "Starter admin is refused {$tool}");
}

/* The personal tools sit under 'staff', so a Starter cashier keeps them. */
$starterCashier = chatToolsFor($conn, ['company_id' => $starter, 'user_id' => 1,
                                       'employee_id' => 5, 'role' => 'cashier']);
t_ok(isset($starterCashier['my_attendance']), 'a Starter cashier keeps their own attendance');
t_ok(isset($starterCashier['my_leave']), 'and their own leave');

/* A company with no active subscription reaches nothing. */
$lapsed = testMakeCompany($conn, 'Matrix Chat Lapsed', 2);
$conn->query("UPDATE company_subscriptions SET status = 'Expired' WHERE company_id = {$lapsed}");

t_same([], chatToolsFor($conn, ['company_id' => $lapsed, 'user_id' => 1,
                                'employee_id' => null, 'role' => 'admin']),
    'a company with no active subscription gets no tools at all');

/* Every handler the catalog names must exist, or the model would pick a tool
   that fails at runtime. */
foreach (chatTools() as $name => $tool) {
    t_ok(function_exists($tool['handler']), "{$name}'s handler is a real function");
}

t_done();
```

- [ ] **Step 2: Run it**

Run: `C:/xampp/php/php.exe tests/chatbot/test_chat_matrix.php`
Expected: PASS.

- [ ] **Step 3: Extend the isolation test**

In `tests/chatbot/test_chat_isolation.php`, extend the seeding function and the
call list. Replace the `$calls` array with:

```php
$calls = [
    ['sales_summary', ['period' => 'this_month']],
    ['sales_by_day', ['from' => date('Y-m-d', strtotime('-60 days')), 'to' => date('Y-m-d')]],
    ['top_products', ['period' => 'this_month', 'limit' => 50]],
    ['payment_mix', ['period' => 'this_month']],
    ['stock_list', ['state' => 'all', 'limit' => 50]],
    ['product_lookup', ['name' => 'chatproduct']],
    ['stock_requests', ['status' => 'all', 'limit' => 50]],
    ['staff_list', ['state' => 'all', 'limit' => 50]],
    ['company_profile', []],
    ['attendance_summary', ['period' => 'this_month', 'limit' => 50]],
    ['attendance_detail', ['employee' => 'Person', 'period' => 'this_month']],
    ['leave_requests', ['status' => 'all', 'limit' => 50]],
    ['recruitment_summary', ['state' => 'all', 'limit' => 50]],
    ['branch_list', []],
];
```

and add to `seedChatCompany()`, before its closing brace:

```php
    /* People, branch, leave and a posting, so every A2 tool has something of
       this company's to find -- and therefore something to leak. */
    $conn->query("INSERT INTO branch (company_id, branch_name, complete_address, province, city, status)
                  VALUES ({$companyId}, '{$product}Branch', '1 St', 'Cavite', 'Imus', 'Active')");

    $conn->query("INSERT INTO employees (company_id, first_name, last_name, employment_status)
                  VALUES ({$companyId}, '{$product}', 'Person', 'Official Employee')");
    $employeeId = (int) $conn->insert_id;

    $conn->query("INSERT INTO attendance (employee_id, attendance_date, time_in, status, late_minutes, company_id)
                  VALUES ({$employeeId}, CURDATE(), CONCAT(CURDATE(), ' 08:00:00'), 'Present', 0, {$companyId})");

    $conn->query("INSERT INTO leave_requests (employee_id, leave_type, duration, start_date, end_date, reason, hr_status, admin_status, company_id)
                  VALUES ({$employeeId}, '{$product}Leave', 'Whole Day', CURDATE(), CURDATE(), 'reason', 'Pending', 'Pending', {$companyId})");

    $conn->query("INSERT INTO job (job_title, department, vacancies, employment_type, status, application_deadline, company_id)
                  VALUES ('{$product}Job', 'Cashier', 1, 'Full Time', 'Published',
                          DATE_ADD(CURDATE(), INTERVAL 30 DAY), {$companyId})");
```

Then add these assertions inside the per-company loop, after the existing ones:

```php
    foreach (["{$theirs}ChatProductBranch", "{$theirs}ChatProductLeave",
              "{$theirs}ChatProductJob"] as $theirThing) {
        $everything = '';

        foreach ($calls as [$tool, $input]) {
            $everything .= json_encode(chatRunTool($conn, $ctx, $tool, $input));
        }

        t_ok(!str_contains($everything, $theirThing),
            "{$mine}: no tool returns {$theirs}'s {$theirThing}");
    }
```

- [ ] **Step 4: Run the extended isolation test**

Run: `C:/xampp/php/php.exe tests/chatbot/test_chat_isolation.php`
Expected: PASS. **A failure here is not a test to adjust.**

- [ ] **Step 5: Prove it can still fail**

Temporarily change `chatToolStaffList`'s `WHERE e.company_id = ?` to
`WHERE ? IS NOT NULL`.
Run the isolation test: expected FAIL naming the other company's person.
Run the audit: expected 1 problem.
**Restore the line** and re-run both: PASS and `0 problem(s)`.

- [ ] **Step 6: Full verification**

Run:
```bash
C:/xampp/php/php.exe tests/chatbot/run_all.php
python tests/chatbot/query_audit.py
C:/xampp/mysql/bin/mysql.exe -uroot -N sari -e "SELECT COUNT(*) FROM company WHERE company_name LIKE 'CHATBOT-TEST %';"
```
Expected: `ALL TESTS PASSED`, `0 problem(s)`, `0`.

- [ ] **Step 7: The live check (owner)**

With a real key configured, sign in as Admin and ask: "sino ang madalas ma-late
ngayong buwan?", then "may bukas ba tayong hiring?", then "magkano ang sweldo ni
<employee>" — the last must be answered with a plain statement that there is no
tool for salaries.

---

## What Stage A2 leaves out

- HR, Finance and Inventory Staff roles — they get their own tool rows in Phase 2.
- The advisories (including the HR hiring suggestion) — Phase 2.
- Any tool that writes. Nothing here creates, approves or edits a record.
