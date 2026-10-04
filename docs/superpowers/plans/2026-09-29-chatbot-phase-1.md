# RetailCore Chatbot — Phase 1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A working in-system assistant that answers POS, Inventory, Staff and basic report questions from the signed-in company's own data for Admin, Cashier and Employee — keyword-matched, with an AI understanding layer that falls back to keywords whenever the API is unreachable.

**Architecture:** One HTTP entry point (`includes/chatbot/ask.php`) reads the session and hands an explicit context array to one engine. The engine filters a data-only intent catalog by role and plan, maps the question to an intent, and calls a handler that only ever runs a prepared `SELECT` with `company_id` bound. Handlers return arrays; the browser renders them as text.

**Tech Stack:** PHP 8.2.12 (XAMPP), MariaDB 10.4, mysqli, Bootstrap 5.3.8, vanilla JS. No new Composer packages. Tests are plain PHP CLI scripts (no framework is installed) plus one Python static audit, matching the audit scripts already used in this project.

**Spec:** `docs/superpowers/specs/2026-09-29-retailcore-chatbot-design.md`

## Global Constraints

- PHP 8.2.12, MariaDB 10.4, Windows/XAMPP. PHP binary: `C:/xampp/php/php.exe`. MySQL client: `C:/xampp/mysql/bin/mysql.exe -uroot sari`.
- Database name is `sari` (see `config.php`). `$conn` is a `mysqli` object from `config.php`, included by `init.php`.
- Every statement under `includes/chatbot/` is a prepared `SELECT` carrying `company_id = ?`. The only write in the whole feature is the `INSERT` into `chatbot_messages` inside `ask.php`.
- `$_SESSION` is read **only** in `ask.php`. Every other function receives an explicit context array with exactly these keys: `['company_id' => int, 'user_id' => int, 'employee_id' => ?int, 'role' => string]`. This is what makes the engine testable from the CLI.
- Handlers never echo, never build HTML, never call out to the network.
- Plan gating is **deny by default**: a topic with no row in `chatbot_topic_plans` is refused for every plan.
- AI model id: `claude-haiku-4-5-20251001`. Timeout 2.5 seconds. Key read from `C:\xampp\sarismart_secrets.php`, outside the webroot.
- Existing project files use CRLF. When editing an existing file, preserve CRLF; new files under `includes/chatbot/` and `tests/` use LF.
- **This project is not a git repository.** Every task therefore ends with a *Checkpoint* step (run the full suite) instead of a commit. Running `git init` in `c:\xampp\htdocs\RetailCore` first is recommended, so each checkpoint can also be a commit; the plan works either way.
- Tests live in `tests/` with an `.htaccess` that denies web access, because the project root is inside `htdocs`.

## Review Focus

1. **A topic named in the catalog but never seeded** — `product_stock` mistyped as `inventoy` must **close** the intent, never open it to every plan. Test in Task 3.
2. **A personal (`own`) intent asked by a user with no `employee_id`** — an Owner/Admin account has no employee row; the attendance and leave handlers must refuse cleanly instead of binding NULL and reporting "no records". Test in Task 7.
3. **A hostile or oversized question** — 5,000 characters, `<script>`, `'; DROP TABLE`, emoji. Must be matched or rejected without a PHP warning, stored truncated, and rendered as text. Test in Task 8.
4. **A product question naming something that does not exist, or matching many products** — must say so, not show an empty card and not silently answer about the first row. Test in Task 4.
5. **A session whose company's subscription has lapsed since sign-in** — the endpoint must refuse, matching the paywall in `requireRole()`. Test in Task 8.

---

### Task 1: Migrations and the test harness

**Files:**
- Create: `platform/database/chatbot_messages.sql`
- Create: `platform/database/chatbot_topic_plans.sql`
- Create: `tests/.htaccess`
- Create: `tests/chatbot/bootstrap.php`
- Create: `tests/chatbot/run_all.php`
- Test: `tests/chatbot/test_schema.php`

**Interfaces:**
- Consumes: nothing.
- Produces: tables `chatbot_messages` and `chatbot_topic_plans`; test helpers `t_ok(bool $condition, string $label): void`, `t_same($expected, $actual, string $label): void`, `t_done(): void`; runner `tests/chatbot/run_all.php`.

- [ ] **Step 1: Write the harness**

`tests/.htaccess`:

```apache
# Tests sit inside htdocs; nothing here may be fetched over HTTP.
Require all denied
```

`tests/chatbot/bootstrap.php`:

```php
<?php
/*
| A three-function test harness. The project has no PHPUnit and this feature
| does not justify adding one: these tests run from the CLI, print one line
| per assertion, and set the exit code so run_all.php can fail the suite.
*/
require_once __DIR__ . '/../../init.php';

$GLOBALS['t_pass'] = 0;
$GLOBALS['t_fail'] = 0;

function t_ok(bool $condition, string $label): void
{
    if ($condition) {
        $GLOBALS['t_pass']++;
        echo "  PASS  {$label}\n";
        return;
    }

    $GLOBALS['t_fail']++;
    echo "  FAIL  {$label}\n";
}

function t_same($expected, $actual, string $label): void
{
    $ok = $expected === $actual;

    t_ok($ok, $ok ? $label : $label
        . ' (expected ' . var_export($expected, true)
        . ', got ' . var_export($actual, true) . ')');
}

function t_done(): void
{
    echo "\n  {$GLOBALS['t_pass']} passed, {$GLOBALS['t_fail']} failed\n";
    exit($GLOBALS['t_fail'] > 0 ? 1 : 0);
}
```

`tests/chatbot/run_all.php`:

```php
<?php
/* Runs each test file in its own PHP process so one fatal error cannot hide
   the rest of the suite. */
$files = glob(__DIR__ . '/test_*.php');
sort($files);

$failed = [];

foreach ($files as $file) {
    echo "\n=== " . basename($file) . " ===\n";
    passthru('"' . PHP_BINARY . '" ' . escapeshellarg($file), $code);

    if ($code !== 0) {
        $failed[] = basename($file);
    }
}

echo "\n" . ($failed ? 'FAILED: ' . implode(', ', $failed) : 'ALL TESTS PASSED') . "\n";
exit($failed ? 1 : 0);
```

- [ ] **Step 2: Write the failing schema test**

`tests/chatbot/test_schema.php`:

```php
<?php
require_once __DIR__ . '/bootstrap.php';

function columnsOf(mysqli $conn, string $table): array
{
    $columns = [];
    $result = $conn->query("SHOW COLUMNS FROM `{$table}`");

    if (!$result) {
        return $columns;
    }

    while ($row = $result->fetch_assoc()) {
        $columns[] = $row['Field'];
    }

    return $columns;
}

$messages = columnsOf($conn, 'chatbot_messages');

foreach (['message_id', 'company_id', 'user_id', 'role', 'question',
          'intent_id', 'matched_by', 'outcome', 'created_at'] as $column) {
    t_ok(in_array($column, $messages, true), "chatbot_messages has {$column}");
}

$topics = columnsOf($conn, 'chatbot_topic_plans');

foreach (['topic', 'plan_id'] as $column) {
    t_ok(in_array($column, $topics, true), "chatbot_topic_plans has {$column}");
}

/* The seeded matrix, straight out of spec section 5. */
$seeded = [];
$result = $conn->query("SELECT topic, plan_id FROM chatbot_topic_plans");

while ($row = $result->fetch_assoc()) {
    $seeded[(int) $row['plan_id']][] = $row['topic'];
}

foreach (['pos', 'inventory', 'staff', 'reports'] as $topic) {
    t_ok(in_array($topic, $seeded[1] ?? [], true), "Starter grants {$topic}");
}

foreach (['hrms', 'payroll', 'finance', 'recruitment', 'cross_branch'] as $topic) {
    t_ok(!in_array($topic, $seeded[1] ?? [], true), "Starter does NOT grant {$topic}");
}

foreach (['pos', 'inventory', 'staff', 'reports', 'hrms', 'recruitment',
          'attendance', 'leave', 'payroll', 'finance', 'branch'] as $topic) {
    t_ok(in_array($topic, $seeded[2] ?? [], true), "Professional grants {$topic}");
}

t_ok(!in_array('cross_branch', $seeded[2] ?? [], true),
    'Professional does NOT grant cross_branch');
t_ok(in_array('cross_branch', $seeded[3] ?? [], true),
    'Enterprise grants cross_branch');

t_done();
```

- [ ] **Step 3: Run it and watch it fail**

Run: `C:/xampp/php/php.exe tests/chatbot/test_schema.php`
Expected: FAIL — `SHOW COLUMNS` finds no such tables, so every assertion fails.

- [ ] **Step 4: Write the migrations**

`platform/database/chatbot_messages.sql`:

```sql
/*
| What each user asked the assistant, and what happened to it.
|
| The question is stored; the answer is not. An answer can hold salaries and
| takings, and a second copy of those is a second thing to protect. The
| history view re-runs the intent instead, which keeps figures current and
| re-applies every access check.
|
| no_match rows are the record of what staff expected the assistant to know.
*/
CREATE TABLE IF NOT EXISTS chatbot_messages (
    message_id  INT(11) NOT NULL AUTO_INCREMENT,
    company_id  INT(11) NOT NULL,
    user_id     INT(11) NOT NULL,
    role        VARCHAR(20) NOT NULL,
    question    VARCHAR(500) NOT NULL,
    intent_id   VARCHAR(60) NULL,
    matched_by  ENUM('keyword','ai') NULL,
    outcome     ENUM('answered','no_match','denied_role','denied_plan') NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (message_id),
    KEY idx_chatbot_messages_user (company_id, user_id, created_at),
    CONSTRAINT fk_chatbot_messages_company
        FOREIGN KEY (company_id) REFERENCES company(company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

`platform/database/chatbot_topic_plans.sql`:

```sql
/*
| Which chatbot topics each plan opens.
|
| Deliberately NOT subscription_plan_features: that table is the bullet list
| the public pricing page prints, and the existing module slugs were attached
| to marketing rows that already existed. Twelve chatbot topics have no such
| rows, so seeding them there would add twelve bullets to every pricing card.
|
| Every plan is seeded explicitly rather than relying on inherits_text, so the
| grant a company gets is the row that is actually there -- one table to read
| when asking "why can this plan see payroll?".
*/
CREATE TABLE IF NOT EXISTS chatbot_topic_plans (
    topic   VARCHAR(40) NOT NULL,
    plan_id INT(11) NOT NULL,
    PRIMARY KEY (topic, plan_id),
    CONSTRAINT fk_chatbot_topic_plans_plan
        FOREIGN KEY (plan_id) REFERENCES subscription_plans(plan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO chatbot_topic_plans (topic, plan_id) VALUES
    /* Retail Starter */
    ('pos', 1), ('inventory', 1), ('staff', 1), ('reports', 1),

    /* Retail Professional */
    ('pos', 2), ('inventory', 2), ('staff', 2), ('reports', 2),
    ('hrms', 2), ('recruitment', 2), ('attendance', 2), ('leave', 2),
    ('payroll', 2), ('finance', 2), ('branch', 2),

    /* Retail Enterprise */
    ('pos', 3), ('inventory', 3), ('staff', 3), ('reports', 3),
    ('hrms', 3), ('recruitment', 3), ('attendance', 3), ('leave', 3),
    ('payroll', 3), ('finance', 3), ('branch', 3), ('cross_branch', 3);
```

- [ ] **Step 5: Apply the migrations**

Run:
```bash
C:/xampp/mysql/bin/mysql.exe -uroot sari < platform/database/chatbot_messages.sql
C:/xampp/mysql/bin/mysql.exe -uroot sari < platform/database/chatbot_topic_plans.sql
```

- [ ] **Step 6: Run the test again**

Run: `C:/xampp/php/php.exe tests/chatbot/run_all.php`
Expected: PASS, every assertion.

- [ ] **Step 7: Checkpoint**

Run the suite once more and confirm `ALL TESTS PASSED`. If git was initialised: `git add tests platform/database && git commit -m "feat(chatbot): schema and test harness"`.

---

### Task 2: Intent catalog and keyword matcher

**Files:**
- Create: `includes/chatbot/intents.php`
- Create: `includes/chatbot/understand.php`
- Test: `tests/chatbot/test_matching.php`

**Interfaces:**
- Consumes: the harness from Task 1.
- Produces:
  - `chatbotIntents(): array` — id => `['topic'=>string,'roles'=>string[],'scope'=>'company'|'own','label'=>string,'keywords'=>array<array<string>>,'handler'=>string]`
  - `chatbotNormalise(string $question): string`
  - `chatbotKeywordMatch(string $question, array $intents): ?string`
  - `chatbotExtractProductName(string $question, array $intent): string`

- [ ] **Step 1: Write the failing test**

`tests/chatbot/test_matching.php`:

```php
<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../includes/chatbot/intents.php';
require_once __DIR__ . '/../../includes/chatbot/understand.php';

$intents = chatbotIntents();

/* Normalising */
t_same('magkano ang benta ngayon', chatbotNormalise('  Magkano ang BENTA ngayon??  '),
    'normalise lowercases, strips punctuation, collapses spaces');
t_same('', chatbotNormalise('   '), 'blank question normalises to empty');

/* English and Tagalog reach the same intent */
t_same('sales_today', chatbotKeywordMatch('What are our total sales today?', $intents),
    'English sales today');
t_same('sales_today', chatbotKeywordMatch('magkano ang benta ngayong araw', $intents),
    'Tagalog sales today');

/* The specific intent wins over the looser one */
t_same('sales_month', chatbotKeywordMatch('how much were our sales this month', $intents),
    'this month does not fall through to today');

t_same('low_stock', chatbotKeywordMatch('which products are low in stock', $intents),
    'low stock');
t_same('out_of_stock', chatbotKeywordMatch('anong produkto ang out of stock', $intents),
    'multi-word synonym matches');
t_same('staff_count', chatbotKeywordMatch('how many employees do we have', $intents),
    'staff count');
t_same('my_sales_today', chatbotKeywordMatch('how much have I sold today', $intents),
    'personal sales is its own intent');

/* Nothing sensible must not be forced into an intent */
t_same(null, chatbotKeywordMatch('what is the weather tomorrow', $intents),
    'unrelated question matches nothing');
t_same(null, chatbotKeywordMatch('', $intents), 'empty question matches nothing');

/* Product name extraction */
t_same('lucky me', chatbotExtractProductName('magkano ang lucky me', $intents['product_price']),
    'extracts the product name out of a price question');
t_same('coke mismo', chatbotExtractProductName('what is the price of Coke Mismo?', $intents['product_price']),
    'extracts the product name from the English phrasing');

/* Catalog shape: every entry is complete and internally consistent */
foreach ($intents as $id => $intent) {
    foreach (['topic', 'roles', 'scope', 'label', 'keywords', 'handler'] as $key) {
        t_ok(array_key_exists($key, $intent), "{$id} defines {$key}");
    }

    t_ok(in_array($intent['scope'], ['company', 'own'], true), "{$id} has a valid scope");
    t_ok($intent['keywords'] !== [], "{$id} has at least one keyword group");
    t_ok($intent['roles'] !== [], "{$id} names at least one role");
}

t_done();
```

- [ ] **Step 2: Run it and watch it fail**

Run: `C:/xampp/php/php.exe tests/chatbot/test_matching.php`
Expected: FAIL — `chatbotIntents()` is undefined.

- [ ] **Step 3: Write the catalog**

`includes/chatbot/intents.php`:

```php
<?php
/*
|--------------------------------------------------------------------------
| THE CATALOG
|--------------------------------------------------------------------------
|
| Every question the assistant can answer, who may ask it, and which plan
| topic it belongs to. Data only -- no queries, no logic. The role matrix in
| the spec is enforced by the 'roles' key here and nowhere else, so there is
| one list to read when asking "can a cashier see this?".
|
| A personal question is always its own entry ('scope' => 'own'), never the
| same entry with a wider scope for another role.
|
| Keyword groups are ANDed; the synonyms inside a group are ORed. A synonym
| may be a phrase ("out of stock"), which is matched as a phrase.
*/
function chatbotIntents(): array
{
    return [

        'sales_today' => [
            'topic' => 'pos',
            'roles' => ['admin'],
            'scope' => 'company',
            'label' => 'Benta ngayong araw',
            'keywords' => [
                ['benta', 'sales', 'kita', 'sold', 'nabenta'],
                ['ngayon', 'ngayong araw', 'today', 'araw'],
            ],
            'handler' => 'chatbotSalesToday',
        ],

        'sales_month' => [
            'topic' => 'pos',
            'roles' => ['admin'],
            'scope' => 'company',
            'label' => 'Benta ngayong buwan',
            'keywords' => [
                ['benta', 'sales', 'kita', 'sold', 'nabenta'],
                ['buwan', 'month', 'ngayong buwan', 'this month'],
            ],
            'handler' => 'chatbotSalesMonth',
        ],

        'top_products_month' => [
            'topic' => 'reports',
            'roles' => ['admin'],
            'scope' => 'company',
            'label' => 'Pinakamabentang produkto',
            'keywords' => [
                ['top', 'pinakamabenta', 'best selling', 'bestseller', 'mabenta'],
                ['produkto', 'product', 'products', 'item', 'items'],
            ],
            'handler' => 'chatbotTopProducts',
        ],

        'low_stock' => [
            'topic' => 'inventory',
            'roles' => ['admin'],
            'scope' => 'company',
            'label' => 'Mababang stock',
            'keywords' => [
                ['low', 'mababa', 'kulang', 'konti', 'reorder'],
                ['stock', 'stocks', 'inventory', 'produkto', 'paninda'],
            ],
            'handler' => 'chatbotLowStock',
        ],

        'out_of_stock' => [
            'topic' => 'inventory',
            'roles' => ['admin'],
            'scope' => 'company',
            'label' => 'Ubos na produkto',
            'keywords' => [
                ['out of stock', 'ubos', 'wala nang stock', 'zero', 'naubos'],
            ],
            'handler' => 'chatbotOutOfStock',
        ],

        'product_price' => [
            'topic' => 'pos',
            'roles' => ['admin', 'cashier'],
            'scope' => 'company',
            'label' => 'Presyo ng produkto',
            'keywords' => [
                ['magkano', 'presyo', 'price', 'how much is', 'cost'],
            ],
            'handler' => 'chatbotProductPrice',
        ],

        'product_stock' => [
            'topic' => 'inventory',
            'roles' => ['admin', 'cashier'],
            'scope' => 'company',
            'label' => 'May stock pa ba?',
            'keywords' => [
                ['may stock', 'meron pa', 'in stock', 'available', 'ilan pa'],
            ],
            'handler' => 'chatbotProductStock',
        ],

        'staff_count' => [
            'topic' => 'staff',
            'roles' => ['admin'],
            'scope' => 'company',
            'label' => 'Bilang ng empleyado',
            'keywords' => [
                ['ilan', 'how many', 'bilang', 'count'],
                ['empleyado', 'employee', 'employees', 'staff', 'tauhan'],
            ],
            'handler' => 'chatbotStaffCount',
        ],

        'my_sales_today' => [
            'topic' => 'pos',
            'roles' => ['cashier'],
            'scope' => 'own',
            'label' => 'Benta ko ngayong araw',
            'keywords' => [
                ['ako', 'ko', 'i', 'my', 'my own'],
                ['benta', 'sales', 'sold', 'nabenta'],
            ],
            'handler' => 'chatbotMySalesToday',
        ],

        /*
        | Personal attendance and leave sit under 'staff', not 'attendance'.
        | The attendance topic covers the company-wide HR views that Retail
        | Starter does not buy -- but a Starter cashier still clocks in, and
        | must be able to ask about their own time records.
        */
        'my_attendance_today' => [
            'topic' => 'staff',
            'roles' => ['cashier', 'employee'],
            'scope' => 'own',
            'label' => 'Attendance ko ngayon',
            'keywords' => [
                ['attendance', 'time in', 'time out', 'pasok', 'oras ko'],
            ],
            'handler' => 'chatbotMyAttendanceToday',
        ],

        'my_leave_status' => [
            'topic' => 'staff',
            'roles' => ['cashier', 'employee'],
            'scope' => 'own',
            'label' => 'Status ng leave ko',
            'keywords' => [
                ['leave', 'bakasyon', 'day off', 'absent request'],
            ],
            'handler' => 'chatbotMyLeaveStatus',
        ],
    ];
}
```

- [ ] **Step 4: Write the matcher**

`includes/chatbot/understand.php`:

```php
<?php
/*
|--------------------------------------------------------------------------
| UNDERSTANDING A QUESTION
|--------------------------------------------------------------------------
|
| Keyword matching only, at this stage. Task 10 adds the AI path on top of
| this file, and this matcher stays as the fallback for when the API is
| unreachable -- which is why it is built and proven first.
*/

function chatbotNormalise(string $question): string
{
    $text = mb_strtolower(trim($question), 'UTF-8');
    $text = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text) ?? '';
    $text = preg_replace('/\s+/u', ' ', $text) ?? '';

    return trim($text);
}

/**
 * The id of the best-matching intent, or null when nothing matches well.
 *
 * Every keyword group must be hit for an intent to qualify, so "sales this
 * month" cannot fall through to the "today" intent. Among the qualifying
 * intents the highest number of hits wins; a tie goes to the intent with more
 * groups, which is the more specific one.
 */
function chatbotKeywordMatch(string $question, array $intents): ?string
{
    $normalised = chatbotNormalise($question);

    if ($normalised === '') {
        return null;
    }

    $haystack = ' ' . $normalised . ' ';

    $bestId = null;
    $bestScore = 0;
    $bestGroups = 0;

    foreach ($intents as $id => $intent) {

        $score = 0;
        $qualifies = true;

        foreach ($intent['keywords'] as $group) {

            $hits = 0;

            foreach ($group as $synonym) {
                if (str_contains($haystack, ' ' . $synonym . ' ')) {
                    $hits++;
                }
            }

            if ($hits === 0) {
                $qualifies = false;
                break;
            }

            $score += $hits;
        }

        if (!$qualifies) {
            continue;
        }

        $groups = count($intent['keywords']);

        if ($score > $bestScore || ($score === $bestScore && $groups > $bestGroups)) {
            $bestId = $id;
            $bestScore = $score;
            $bestGroups = $groups;
        }
    }

    return $bestId;
}

/**
 * What is left of a question once its own keywords and filler words are
 * removed -- the product the user is asking about.
 *
 * Returned as a plain string for binding into a LIKE parameter. It is never
 * concatenated into SQL.
 */
function chatbotExtractProductName(string $question, array $intent): string
{
    $normalised = chatbotNormalise($question);
    $haystack = ' ' . $normalised . ' ';

    foreach ($intent['keywords'] as $group) {
        foreach ($group as $synonym) {
            $haystack = str_replace(' ' . $synonym . ' ', ' ', $haystack);
        }
    }

    $filler = ['ang', 'ng', 'na', 'ba', 'po', 'yung', 'iyong', 'the', 'a', 'an',
               'of', 'is', 'are', 'do', 'we', 'i', 'for', 'this', 'that', 'it',
               'what', 'ano', 'sa', 'may', 'meron'];

    foreach ($filler as $word) {
        $haystack = str_replace(' ' . $word . ' ', ' ', $haystack);
    }

    return trim(preg_replace('/\s+/u', ' ', $haystack) ?? '');
}
```

- [ ] **Step 5: Run the test**

Run: `C:/xampp/php/php.exe tests/chatbot/test_matching.php`
Expected: PASS. If `product_price` extraction returns a stray filler word, add it to `$filler` — do not weaken the assertion.

- [ ] **Step 6: Checkpoint**

Run: `C:/xampp/php/php.exe tests/chatbot/run_all.php` — expect `ALL TESTS PASSED`.

---

### Task 3: The gates — role, plan, and the suggestion list

**Files:**
- Create: `includes/chatbot/engine.php` (gates and filtering only; dispatch arrives in Task 4)
- Test: `tests/chatbot/test_gates.php`
- Test helper: `tests/chatbot/factory.php`

**Interfaces:**
- Consumes: `chatbotIntents()` from Task 2.
- Produces:
  - `chatbotPlanAllowsTopic(mysqli $conn, int $companyId, string $topic): bool`
  - `chatbotAllowedIntents(mysqli $conn, int $companyId, string $role): array` — same shape as the catalog, filtered
  - `chatbotSuggestions(array $allowedIntents, int $limit = 6): array` — list of `['id'=>string,'label'=>string]`
  - Factory helpers `testMakeCompany(mysqli $conn, string $name, int $planId): int` and `testCleanup(mysqli $conn): void`

- [ ] **Step 1: Write the factory**

`tests/chatbot/factory.php`:

```php
<?php
/*
| Temporary companies for tests. Every row this creates is tagged with the
| CHATBOT-TEST prefix in company_name so testCleanup() can find and remove
| it, and so a human can spot leftovers instantly. Nothing here ever touches
| a company it did not create.
*/
const CHATBOT_TEST_PREFIX = 'CHATBOT-TEST ';

function testMakeCompany(mysqli $conn, string $name, int $planId): int
{
    $companyName = CHATBOT_TEST_PREFIX . $name;

    $stmt = $conn->prepare("
        INSERT INTO company (company_name, owner_name) VALUES (?, 'Test Owner')
    ");
    $stmt->bind_param("s", $companyName);
    $stmt->execute();
    $companyId = (int) $conn->insert_id;
    $stmt->close();

    $stmt = $conn->prepare("
        INSERT INTO company_subscriptions
            (company_id, plan_id, billing_cycle, amount, start_date, expiry_date, status)
        VALUES (?, ?, 'Monthly', 0.00, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 1 YEAR), 'Active')
    ");
    $stmt->bind_param("ii", $companyId, $planId);
    $stmt->execute();
    $stmt->close();

    return $companyId;
}

/**
 * Removes every row any test created, children first so the foreign keys
 * never block the delete. Run it in a shutdown function so an assertion
 * failure cannot leave rows behind.
 */
function testCleanup(mysqli $conn): void
{
    $ids = [];
    $like = CHATBOT_TEST_PREFIX . '%';

    $stmt = $conn->prepare("SELECT company_id FROM company WHERE company_name LIKE ?");
    $stmt->bind_param("s", $like);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $ids[] = (int) $row['company_id'];
    }

    $stmt->close();

    foreach ($ids as $companyId) {
        foreach (['chatbot_messages', 'sale_items', 'sales', 'inventory',
                  'products', 'categories', 'attendance', 'leave_requests',
                  'users', 'employees', 'company_subscriptions'] as $table) {
            $stmt = $conn->prepare("DELETE FROM `{$table}` WHERE company_id = ?");
            $stmt->bind_param("i", $companyId);
            $stmt->execute();
            $stmt->close();
        }

        $stmt = $conn->prepare("DELETE FROM company WHERE company_id = ?");
        $stmt->bind_param("i", $companyId);
        $stmt->execute();
        $stmt->close();
    }
}
```

- [ ] **Step 2: Write the failing gate test**

`tests/chatbot/test_gates.php`:

```php
<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/factory.php';
require_once __DIR__ . '/../../includes/chatbot/intents.php';
require_once __DIR__ . '/../../includes/chatbot/engine.php';

register_shutdown_function(function () use ($conn) { testCleanup($conn); });

$starter = testMakeCompany($conn, 'Gates Starter', 1);
$professional = testMakeCompany($conn, 'Gates Professional', 2);

/* Plan gate */
t_ok(chatbotPlanAllowsTopic($conn, $starter, 'pos'), 'Starter allows pos');
t_ok(!chatbotPlanAllowsTopic($conn, $starter, 'payroll'), 'Starter refuses payroll');
t_ok(chatbotPlanAllowsTopic($conn, $professional, 'payroll'), 'Professional allows payroll');
t_ok(!chatbotPlanAllowsTopic($conn, $professional, 'cross_branch'),
    'Professional refuses cross_branch');

/* Review Focus 1: an unseeded topic must CLOSE, not open */
t_ok(!chatbotPlanAllowsTopic($conn, $professional, 'inventoy'),
    'a mistyped topic is denied, not allowed by default');
t_ok(!chatbotPlanAllowsTopic($conn, $professional, ''),
    'an empty topic is denied');

/* Every topic the catalog names must actually be seeded, or an intent would
   be unreachable for everyone -- the other half of the same mistake. */
$seeded = [];
$result = $conn->query("SELECT DISTINCT topic FROM chatbot_topic_plans");

while ($row = $result->fetch_assoc()) {
    $seeded[] = $row['topic'];
}

foreach (chatbotIntents() as $id => $intent) {
    t_ok(in_array($intent['topic'], $seeded, true),
        "topic '{$intent['topic']}' used by {$id} is seeded");
}

/* Role gate */
$adminIntents = chatbotAllowedIntents($conn, $professional, 'admin');
$cashierIntents = chatbotAllowedIntents($conn, $professional, 'cashier');
$employeeIntents = chatbotAllowedIntents($conn, $professional, 'employee');

t_ok(isset($adminIntents['sales_today']), 'admin may ask company sales');
t_ok(!isset($cashierIntents['sales_today']), 'cashier may NOT ask company sales');
t_ok(isset($cashierIntents['my_sales_today']), 'cashier may ask their own sales');
t_ok(!isset($adminIntents['my_sales_today']), 'admin does not get the cashier intent');
t_ok(!isset($employeeIntents['low_stock']), 'employee may not ask about stock');
t_ok(isset($employeeIntents['my_attendance_today']), 'employee may ask their own attendance');

/* Plan gate applied through the filter */
$starterAdmin = chatbotAllowedIntents($conn, $starter, 'admin');
t_ok(isset($starterAdmin['sales_today']), 'Starter admin keeps POS questions');
t_ok(isset($starterAdmin['low_stock']), 'Starter admin keeps inventory questions');

/* Suggestions come from the filtered list, so they can never offer a refusal */
$suggestions = chatbotSuggestions($cashierIntents);
t_ok($suggestions !== [], 'cashier gets suggestions');

foreach ($suggestions as $suggestion) {
    t_ok(isset($cashierIntents[$suggestion['id']]),
        "suggestion {$suggestion['id']} is one the cashier may ask");
    t_ok($suggestion['label'] !== '', 'suggestion carries a label');
}

t_done();
```

- [ ] **Step 3: Run it and watch it fail**

Run: `C:/xampp/php/php.exe tests/chatbot/test_gates.php`
Expected: FAIL — `chatbotPlanAllowsTopic()` is undefined.

- [ ] **Step 4: Write the gates**

`includes/chatbot/engine.php`:

```php
<?php
/*
|--------------------------------------------------------------------------
| THE ENGINE
|--------------------------------------------------------------------------
|
| The only place that decides whether a question may be answered. Handlers
| assume they are already authorised; this file is why they can.
|
| Nothing here reads $_SESSION. The caller (ask.php) passes an explicit
| context array, which is what lets the whole engine be driven from the CLI
| by the test suite.
*/

require_once __DIR__ . '/intents.php';
require_once __DIR__ . '/understand.php';

/**
 * Whether this company's plan opens a topic.
 *
 * Deny by default: a topic with no row grants nothing. companyHasModule()
 * in init.php is deliberately permissive for navigation, where a page no
 * plan sells should stay open. The opposite is right here -- a topic missing
 * from the table is a mistake, and a mistake must close a door, not open one.
 */
function chatbotPlanAllowsTopic(mysqli $conn, int $companyId, string $topic): bool
{
    $topic = strtolower(trim($topic));

    if ($topic === '') {
        return false;
    }

    $plan = currentCompanyPlan($conn, $companyId);

    if (!$plan) {
        return false;
    }

    $planId = (int) $plan['plan_id'];

    $stmt = $conn->prepare("
        SELECT 1 FROM chatbot_topic_plans WHERE topic = ? AND plan_id = ? LIMIT 1
    ");
    $stmt->bind_param("si", $topic, $planId);
    $stmt->execute();
    $allowed = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    return $allowed;
}

/**
 * The catalog reduced to what this role, on this plan, may ask.
 *
 * Everything downstream -- matching, the AI's candidate list, the suggestion
 * buttons -- works from this reduced list, so no later step can reach an
 * intent the user was never allowed to ask.
 */
function chatbotAllowedIntents(mysqli $conn, int $companyId, string $role): array
{
    $role = strtolower(trim($role));
    $allowed = [];

    foreach (chatbotIntents() as $id => $intent) {

        if (!in_array($role, $intent['roles'], true)) {
            continue;
        }

        if (!chatbotPlanAllowsTopic($conn, $companyId, $intent['topic'])) {
            continue;
        }

        $allowed[$id] = $intent;
    }

    return $allowed;
}

function chatbotSuggestions(array $allowedIntents, int $limit = 6): array
{
    $suggestions = [];

    foreach ($allowedIntents as $id => $intent) {

        if (count($suggestions) >= $limit) {
            break;
        }

        $suggestions[] = ['id' => $id, 'label' => $intent['label']];
    }

    return $suggestions;
}
```

- [ ] **Step 5: Run the test**

Run: `C:/xampp/php/php.exe tests/chatbot/test_gates.php`
Expected: PASS.

- [ ] **Step 6: Confirm no test rows survived**

Run:
```bash
C:/xampp/mysql/bin/mysql.exe -uroot -N sari -e "SELECT COUNT(*) FROM company WHERE company_name LIKE 'CHATBOT-TEST %';"
```
Expected: `0`. If not, the shutdown cleanup is broken — fix it before continuing, because every later task creates test companies too.

- [ ] **Step 7: Checkpoint**

Run: `C:/xampp/php/php.exe tests/chatbot/run_all.php` — expect `ALL TESTS PASSED`.

---

### Task 4: Company-wide answers, and dispatch

**Files:**
- Create: `includes/chatbot/answers/pos.php`
- Create: `includes/chatbot/answers/inventory.php`
- Create: `includes/chatbot/answers/staff.php`
- Modify: `includes/chatbot/engine.php` (add `chatbotAnswer()`)
- Test: `tests/chatbot/test_answers.php`

**Interfaces:**
- Consumes: `chatbotAllowedIntents()`, `chatbotKeywordMatch()`, `chatbotExtractProductName()`.
- Produces:
  - `chatbotAnswer(mysqli $conn, array $ctx, string $question): array` returning
    `['ok'=>bool, 'reason'=>?string, 'intent'=>?string, 'matched_by'=>?string, 'answer'=>?array, 'suggestions'=>array]`
    where `reason` is one of `no_match`, `denied_role`, `denied_plan`, `needs_employee`.
  - Handlers, each `(mysqli $conn, array $ctx, string $question): array`:
    `chatbotSalesToday`, `chatbotSalesMonth`, `chatbotTopProducts`, `chatbotProductPrice`,
    `chatbotLowStock`, `chatbotOutOfStock`, `chatbotProductStock`, `chatbotStaffCount`.
  - Answer array shape: `['title'=>string, 'lines'=>array<array{0:string,1:string}>, 'table'=>?array{columns:string[],rows:array<string[]>}, 'link'=>?array{href:string,label:string}, 'note'=>?string]` — `note` carries "nothing found" and caveat text. Every key except `title` may be absent or null, and the widget must render accordingly.

- [ ] **Step 1: Write the failing test**

`tests/chatbot/test_answers.php`:

```php
<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/factory.php';
require_once __DIR__ . '/../../includes/chatbot/engine.php';

register_shutdown_function(function () use ($conn) { testCleanup($conn); });

$companyId = testMakeCompany($conn, 'Answers Co', 2);

/* A category, two products, stock for both, and one sale today. */
$conn->query("INSERT INTO categories (category_name, company_id) VALUES ('Noodles', {$companyId})");
$categoryId = (int) $conn->insert_id;

$conn->query("INSERT INTO products (product_name, category_id, company_id)
              VALUES ('Lucky Me Pancit Canton', {$categoryId}, {$companyId})");
$productId = (int) $conn->insert_id;

$conn->query("INSERT INTO products (product_name, category_id, company_id)
              VALUES ('Coke Mismo', {$categoryId}, {$companyId})");
$emptyProductId = (int) $conn->insert_id;

$conn->query("INSERT INTO inventory (product_id, quantity, purchase_cost, selling_price, reorder_level, company_id)
              VALUES ({$productId}, 3, 10.00, 15.00, 5, {$companyId})");
$conn->query("INSERT INTO inventory (product_id, quantity, purchase_cost, selling_price, reorder_level, company_id)
              VALUES ({$emptyProductId}, 0, 15.00, 20.00, 5, {$companyId})");

$conn->query("INSERT INTO sales (company_id, total_amount, tax_amount, cash_received, change_amount, payment_method)
              VALUES ({$companyId}, 150.00, 0.00, 200.00, 50.00, 'Cash')");
$saleId = (int) $conn->insert_id;

$conn->query("INSERT INTO sale_items (sale_id, product_id, quantity, selling_price, company_id)
              VALUES ({$saleId}, {$productId}, 10, 15.00, {$companyId})");

$ctx = ['company_id' => $companyId, 'user_id' => 0, 'employee_id' => null, 'role' => 'admin'];

/* Sales */
$result = chatbotAnswer($conn, $ctx, 'magkano ang benta ngayong araw');
t_ok($result['ok'], 'sales today answered');
t_same('sales_today', $result['intent'], 'sales today intent');
t_ok(str_contains(json_encode($result['answer']), '150.00'), 'sales today shows the amount');

/* Inventory */
$result = chatbotAnswer($conn, $ctx, 'which products are low in stock');
t_ok($result['ok'], 'low stock answered');
t_ok(str_contains(json_encode($result['answer']), 'Lucky Me'),
    'low stock names the product below its reorder level');

$result = chatbotAnswer($conn, $ctx, 'anong produkto ang out of stock');
t_ok(str_contains(json_encode($result['answer']), 'Coke Mismo'), 'out of stock names the empty product');

/* Product lookups */
$result = chatbotAnswer($conn, $ctx, 'magkano ang lucky me');
t_ok($result['ok'], 'product price answered');
t_ok(str_contains(json_encode($result['answer']), '15.00'), 'product price shows the price');

/* Review Focus 4: a product that does not exist, and an ambiguous one */
$result = chatbotAnswer($conn, $ctx, 'magkano ang wala talaga nito');
t_ok($result['ok'], 'unknown product still returns an answer card, not an error');
t_ok(str_contains(mb_strtolower(json_encode($result['answer'])), 'wala'),
    'unknown product says nothing was found');
t_same([], $result['answer']['lines'], 'unknown product shows no figures');

$conn->query("INSERT INTO products (product_name, category_id, company_id)
              VALUES ('Lucky Me Beef', {$categoryId}, {$companyId})");
$secondId = (int) $conn->insert_id;
$conn->query("INSERT INTO inventory (product_id, quantity, purchase_cost, selling_price, reorder_level, company_id)
              VALUES ({$secondId}, 7, 11.00, 16.00, 5, {$companyId})");

$result = chatbotAnswer($conn, $ctx, 'magkano ang lucky me');
t_ok(count($result['answer']['table']['rows'] ?? []) >= 2,
    'an ambiguous product name lists every match instead of guessing one');

/* Staff */
$result = chatbotAnswer($conn, $ctx, 'how many employees do we have');
t_ok($result['ok'], 'staff count answered');

/* Refusals */
$cashierCtx = ['company_id' => $companyId, 'user_id' => 0, 'employee_id' => null, 'role' => 'cashier'];
$result = chatbotAnswer($conn, $cashierCtx, 'magkano ang benta ngayong araw');
t_ok(!$result['ok'], 'cashier is refused the company sales question');
t_ok($result['suggestions'] !== [], 'a refusal still offers what the cashier can ask');
t_ok(!str_contains(json_encode($result), '150.00'), 'a refusal leaks no figures');

$result = chatbotAnswer($conn, $ctx, 'what is the weather tomorrow');
t_same('no_match', $result['reason'], 'an unrelated question is a no_match');

t_done();
```

- [ ] **Step 2: Run it and watch it fail**

Run: `C:/xampp/php/php.exe tests/chatbot/test_answers.php`
Expected: FAIL — `chatbotAnswer()` is undefined.

- [ ] **Step 3: Write the POS answers**

`includes/chatbot/answers/pos.php`:

```php
<?php
/*
| POS answers. Every statement is a prepared SELECT with company_id bound --
| the audit in Task 5 fails the build if one is not.
*/

function chatbotPeso(float $amount): string
{
    return '₱' . number_format($amount, 2);
}

function chatbotSalesToday(mysqli $conn, array $ctx, string $question): array
{
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS transactions, COALESCE(SUM(total_amount), 0) AS total
        FROM sales
        WHERE company_id = ? AND DATE(sale_date) = CURDATE()
    ");
    $stmt->bind_param("i", $ctx['company_id']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return [
        'title' => 'Benta ngayong araw',
        'lines' => [
            ['Kabuuan', chatbotPeso((float) $row['total'])],
            ['Transaksyon', (string) (int) $row['transactions']],
        ],
        'table' => null,
        'link' => ['href' => 'income.php', 'label' => 'Buksan ang Income'],
    ];
}

function chatbotSalesMonth(mysqli $conn, array $ctx, string $question): array
{
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS transactions, COALESCE(SUM(total_amount), 0) AS total
        FROM sales
        WHERE company_id = ?
          AND YEAR(sale_date) = YEAR(CURDATE())
          AND MONTH(sale_date) = MONTH(CURDATE())
    ");
    $stmt->bind_param("i", $ctx['company_id']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return [
        'title' => 'Benta ngayong buwan',
        'lines' => [
            ['Kabuuan', chatbotPeso((float) $row['total'])],
            ['Transaksyon', (string) (int) $row['transactions']],
        ],
        'table' => null,
        'link' => ['href' => 'income.php', 'label' => 'Buksan ang Income'],
    ];
}

function chatbotTopProducts(mysqli $conn, array $ctx, string $question): array
{
    $stmt = $conn->prepare("
        SELECT p.product_name, SUM(si.quantity) AS sold
        FROM sale_items si
        JOIN sales s ON s.sale_id = si.sale_id AND s.company_id = si.company_id
        JOIN products p ON p.product_id = si.product_id AND p.company_id = si.company_id
        WHERE si.company_id = ?
          AND YEAR(s.sale_date) = YEAR(CURDATE())
          AND MONTH(s.sale_date) = MONTH(CURDATE())
        GROUP BY p.product_id, p.product_name
        ORDER BY sold DESC
        LIMIT 5
    ");
    $stmt->bind_param("i", $ctx['company_id']);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];

    while ($row = $result->fetch_assoc()) {
        $rows[] = [$row['product_name'], (string) (int) $row['sold']];
    }

    $stmt->close();

    return [
        'title' => 'Pinakamabentang produkto ngayong buwan',
        'lines' => [],
        'table' => $rows
            ? ['columns' => ['Produkto', 'Nabenta'], 'rows' => $rows]
            : null,
        'link' => null,
        'note' => $rows ? null : 'Wala pang naitalang benta ngayong buwan.',
    ];
}

function chatbotProductPrice(mysqli $conn, array $ctx, string $question): array
{
    $intents = chatbotIntents();
    $name = chatbotExtractProductName($question, $intents['product_price']);

    if ($name === '') {
        return [
            'title' => 'Presyo ng produkto',
            'lines' => [],
            'table' => null,
            'link' => null,
            'note' => 'Pakisabi kung anong produkto ang tinatanong mo.',
        ];
    }

    $like = '%' . $name . '%';

    $stmt = $conn->prepare("
        SELECT p.product_name, i.selling_price
        FROM products p
        JOIN inventory i ON i.product_id = p.product_id AND i.company_id = p.company_id
        WHERE p.company_id = ? AND p.product_name LIKE ?
        ORDER BY p.product_name
        LIMIT 10
    ");
    $stmt->bind_param("is", $ctx['company_id'], $like);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];

    while ($row = $result->fetch_assoc()) {
        $rows[] = [$row['product_name'], chatbotPeso((float) $row['selling_price'])];
    }

    $stmt->close();

    if (!$rows) {
        return [
            'title' => 'Presyo ng produkto',
            'lines' => [],
            'table' => null,
            'link' => null,
            'note' => 'Wala akong nakitang produktong tugma sa "' . $name . '".',
        ];
    }

    /* One match answers directly; several are all listed rather than guessing. */
    if (count($rows) === 1) {
        return [
            'title' => $rows[0][0],
            'lines' => [['Presyo', $rows[0][1]]],
            'table' => null,
            'link' => null,
        ];
    }

    return [
        'title' => 'Mga tugmang produkto',
        'lines' => [],
        'table' => ['columns' => ['Produkto', 'Presyo'], 'rows' => $rows],
        'link' => null,
    ];
}
```

- [ ] **Step 4: Write the inventory and staff answers**

`includes/chatbot/answers/inventory.php`:

```php
<?php

function chatbotLowStock(mysqli $conn, array $ctx, string $question): array
{
    $stmt = $conn->prepare("
        SELECT p.product_name, i.quantity, i.reorder_level
        FROM inventory i
        JOIN products p ON p.product_id = i.product_id AND p.company_id = i.company_id
        WHERE i.company_id = ?
          AND i.quantity > 0
          AND i.quantity <= COALESCE(i.reorder_level, 5)
        ORDER BY i.quantity ASC
        LIMIT 20
    ");
    $stmt->bind_param("i", $ctx['company_id']);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];

    while ($row = $result->fetch_assoc()) {
        $rows[] = [
            $row['product_name'],
            (string) (int) $row['quantity'],
            (string) (int) ($row['reorder_level'] ?? 5),
        ];
    }

    $stmt->close();

    return [
        'title' => 'Mababang stock',
        'lines' => [],
        'table' => $rows
            ? ['columns' => ['Produkto', 'Natitira', 'Reorder level'], 'rows' => $rows]
            : null,
        'link' => null,
        'note' => $rows ? null : 'Walang produktong mababa sa reorder level ngayon.',
    ];
}

function chatbotOutOfStock(mysqli $conn, array $ctx, string $question): array
{
    $stmt = $conn->prepare("
        SELECT p.product_name
        FROM inventory i
        JOIN products p ON p.product_id = i.product_id AND p.company_id = i.company_id
        WHERE i.company_id = ? AND i.quantity <= 0
        ORDER BY p.product_name
        LIMIT 20
    ");
    $stmt->bind_param("i", $ctx['company_id']);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];

    while ($row = $result->fetch_assoc()) {
        $rows[] = [$row['product_name']];
    }

    $stmt->close();

    return [
        'title' => 'Ubos na produkto',
        'lines' => [],
        'table' => $rows ? ['columns' => ['Produkto'], 'rows' => $rows] : null,
        'link' => null,
        'note' => $rows ? null : 'Walang produktong ubos ngayon.',
    ];
}

function chatbotProductStock(mysqli $conn, array $ctx, string $question): array
{
    $intents = chatbotIntents();
    $name = chatbotExtractProductName($question, $intents['product_stock']);

    if ($name === '') {
        return [
            'title' => 'Stock ng produkto',
            'lines' => [],
            'table' => null,
            'link' => null,
            'note' => 'Pakisabi kung anong produkto ang tinatanong mo.',
        ];
    }

    $like = '%' . $name . '%';

    $stmt = $conn->prepare("
        SELECT p.product_name, i.quantity
        FROM products p
        JOIN inventory i ON i.product_id = p.product_id AND i.company_id = p.company_id
        WHERE p.company_id = ? AND p.product_name LIKE ?
        ORDER BY p.product_name
        LIMIT 10
    ");
    $stmt->bind_param("is", $ctx['company_id'], $like);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];

    while ($row = $result->fetch_assoc()) {
        $rows[] = [$row['product_name'], (string) (int) $row['quantity']];
    }

    $stmt->close();

    if (!$rows) {
        return [
            'title' => 'Stock ng produkto',
            'lines' => [],
            'table' => null,
            'link' => null,
            'note' => 'Wala akong nakitang produktong tugma sa "' . $name . '".',
        ];
    }

    if (count($rows) === 1) {
        return [
            'title' => $rows[0][0],
            'lines' => [['Natitirang stock', $rows[0][1]]],
            'table' => null,
            'link' => null,
        ];
    }

    return [
        'title' => 'Mga tugmang produkto',
        'lines' => [],
        'table' => ['columns' => ['Produkto', 'Natitira'], 'rows' => $rows],
        'link' => null,
    ];
}
```

`includes/chatbot/answers/staff.php`:

```php
<?php

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
        WHERE company_id = ? AND employment_status <> 'Pre-Employee'
    ");
    $stmt->bind_param("i", $ctx['company_id']);
    $stmt->execute();
    $employees = (int) $stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();

    return [
        'title' => 'Bilang ng empleyado',
        'lines' => [
            ['Aktibong user account', (string) $users],
            ['Naitalang empleyado', (string) $employees],
        ],
        'table' => null,
        'link' => ['href' => 'user_management.php', 'label' => 'Buksan ang User Management'],
    ];
}
```

- [ ] **Step 5: Add dispatch to the engine**

Append to `includes/chatbot/engine.php`:

```php
require_once __DIR__ . '/answers/pos.php';
require_once __DIR__ . '/answers/inventory.php';
require_once __DIR__ . '/answers/staff.php';

/**
 * Answer one question, or explain why it cannot be answered.
 *
 * Refusals never say which gate stopped them and never confirm that the data
 * exists; the distinction is for the log, not for the user.
 */
function chatbotAnswer(mysqli $conn, array $ctx, string $question): array
{
    $allowed = chatbotAllowedIntents($conn, (int) $ctx['company_id'], (string) $ctx['role']);
    $suggestions = chatbotSuggestions($allowed);

    $intentId = chatbotKeywordMatch($question, $allowed);
    $matchedBy = 'keyword';

    if ($intentId === null) {

        /* Was it a real question this user simply may not ask? The answer is
           the same to them either way; the reason is recorded for us. */
        $everywhere = chatbotKeywordMatch($question, chatbotIntents());
        $reason = 'no_match';

        if ($everywhere !== null) {
            $intent = chatbotIntents()[$everywhere];
            $reason = in_array((string) $ctx['role'], $intent['roles'], true)
                ? 'denied_plan'
                : 'denied_role';
        }

        return [
            'ok' => false,
            'reason' => $reason,
            'intent' => null,
            'matched_by' => null,
            'answer' => null,
            'suggestions' => $suggestions,
        ];
    }

    $intent = $allowed[$intentId];

    /* A personal question needs a person. An owner account has no employee
       row, so this is a real case, not a defensive nicety. */
    if ($intent['scope'] === 'own' && empty($ctx['employee_id']) && empty($ctx['user_id'])) {
        return [
            'ok' => false,
            'reason' => 'needs_employee',
            'intent' => $intentId,
            'matched_by' => $matchedBy,
            'answer' => null,
            'suggestions' => $suggestions,
        ];
    }

    $answer = ($intent['handler'])($conn, $ctx, $question);

    return [
        'ok' => true,
        'reason' => null,
        'intent' => $intentId,
        'matched_by' => $matchedBy,
        'answer' => $answer,
        'suggestions' => $suggestions,
    ];
}
```

- [ ] **Step 6: Run the test**

Run: `C:/xampp/php/php.exe tests/chatbot/test_answers.php`
Expected: PASS.

- [ ] **Step 7: Checkpoint**

Run: `C:/xampp/php/php.exe tests/chatbot/run_all.php` — expect `ALL TESTS PASSED`, and re-run the leftover check from Task 3 Step 6.

---

### Task 5: The query audit — read-only and company-scoped, proven mechanically

**Files:**
- Create: `tests/chatbot/query_audit.py`
- Test: `tests/chatbot/test_audit.php` (runs the audit and fails the suite with it)

**Interfaces:**
- Consumes: the files created in Task 4.
- Produces: `python tests/chatbot/query_audit.py` exiting non-zero on any violation.

- [ ] **Step 1: Write the audit**

`tests/chatbot/query_audit.py`:

```python
# -*- coding: utf-8 -*-
"""
Every SQL statement under includes/chatbot/ must be a company-scoped SELECT.

This is the mechanical half of the tenant-isolation promise: a reviewer can
miss a missing company_id, this cannot. It also refuses any outbound network
call outside ai_client.php, which is the only file allowed to make one.
"""
import io
import os
import re
import sys

ROOT = os.path.join(os.path.dirname(__file__), '..', '..', 'includes', 'chatbot')
SQL = re.compile(r'(?:prepare|query)\s*\(\s*(?P<q>["\'])(?P<sql>.*?)(?P=q)', re.S)
WRITES = ('insert ', 'update ', 'delete ', 'drop ', 'alter ', 'truncate ')
NETWORK = ('curl_', "file_get_contents('http", 'file_get_contents("http', 'fsockopen', 'stream_socket_client')

# ask.php writes exactly one table: the chatbot's own log.
WRITE_EXEMPT = {('ask.php', 'chatbot_messages')}

problems = []

for folder, _dirs, files in os.walk(ROOT):
    for name in sorted(files):
        if not name.endswith('.php'):
            continue

        path = os.path.join(folder, name)
        source = io.open(path, encoding='utf-8').read()
        rel = os.path.relpath(path, ROOT).replace('\\', '/')

        for call in NETWORK:
            if call in source and rel != 'ai_client.php':
                problems.append('%s: outbound call (%s) outside ai_client.php' % (rel, call))

        for match in SQL.finditer(source):
            sql = ' '.join(match.group('sql').split())
            low = sql.lower()

            if low.startswith(WRITES):
                table = next((t for f, t in WRITE_EXEMPT if f == name and t in low), None)
                if table is None:
                    problems.append('%s: write statement -- %s' % (rel, sql[:70]))
                continue

            if not low.startswith('select') and not low.startswith('show'):
                continue

            if 'company_id = ?' not in low:
                problems.append('%s: SELECT without company_id = ? -- %s' % (rel, sql[:70]))

print('%d problem(s)' % len(problems))

for problem in problems:
    print('  ' + problem)

sys.exit(1 if problems else 0)
```

- [ ] **Step 2: Run it and confirm it passes on honest code**

Run: `python tests/chatbot/query_audit.py`
Expected: `0 problem(s)`, exit code 0.

- [ ] **Step 3: Prove the audit actually catches a violation**

Temporarily add this line to `includes/chatbot/answers/staff.php`:

```php
$bad = $conn->prepare("SELECT product_name FROM products LIMIT 1");
```

Run: `python tests/chatbot/query_audit.py`
Expected: `1 problem(s)` naming `answers/staff.php`. **Then delete the line and re-run** — expect `0 problem(s)`. An audit never proven to fail is not evidence.

- [ ] **Step 4: Wire it into the suite**

`tests/chatbot/test_audit.php`:

```php
<?php
require_once __DIR__ . '/bootstrap.php';

exec('python ' . escapeshellarg(__DIR__ . '/query_audit.py') . ' 2>&1', $output, $code);

t_same(0, $code, "query audit passes:\n    " . implode("\n    ", $output));
t_done();
```

- [ ] **Step 5: Checkpoint**

Run: `C:/xampp/php/php.exe tests/chatbot/run_all.php` — expect `ALL TESTS PASSED`.

---

### Task 6: Recording who made a sale, and the cashier's own sales

**Files:**
- Create: `platform/database/sales_created_by.sql`
- Modify: `cashier/pointofsales.php:123-147`
- Modify: `includes/chatbot/answers/pos.php` (add `chatbotMySalesToday`)
- Test: `tests/chatbot/test_personal_sales.php`

**Interfaces:**
- Consumes: `$ctx['user_id']`.
- Produces: column `sales.created_by`; handler `chatbotMySalesToday(mysqli $conn, array $ctx, string $question): array`.

- [ ] **Step 1: Write the failing test**

`tests/chatbot/test_personal_sales.php`:

```php
<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/factory.php';
require_once __DIR__ . '/../../includes/chatbot/engine.php';

register_shutdown_function(function () use ($conn) { testCleanup($conn); });

$companyId = testMakeCompany($conn, 'Personal Sales Co', 1);

$conn->query("INSERT INTO users (company_id, username, fullname, email, password, role, status)
              VALUES ({$companyId}, 'cash1', 'Cashier One', 'c1@test.local', 'x', 'cashier', 'active')");
$cashierOne = (int) $conn->insert_id;

$conn->query("INSERT INTO users (company_id, username, fullname, email, password, role, status)
              VALUES ({$companyId}, 'cash2', 'Cashier Two', 'c2@test.local', 'x', 'cashier', 'active')");
$cashierTwo = (int) $conn->insert_id;

$conn->query("INSERT INTO sales (company_id, total_amount, tax_amount, cash_received, change_amount, payment_method, created_by)
              VALUES ({$companyId}, 100.00, 0.00, 100.00, 0.00, 'Cash', {$cashierOne})");
$conn->query("INSERT INTO sales (company_id, total_amount, tax_amount, cash_received, change_amount, payment_method, created_by)
              VALUES ({$companyId}, 500.00, 0.00, 500.00, 0.00, 'Cash', {$cashierTwo})");
/* A sale from before this column existed. */
$conn->query("INSERT INTO sales (company_id, total_amount, tax_amount, cash_received, change_amount, payment_method)
              VALUES ({$companyId}, 999.00, 0.00, 999.00, 0.00, 'Cash')");

$ctx = ['company_id' => $companyId, 'user_id' => $cashierOne,
        'employee_id' => null, 'role' => 'cashier'];

$result = chatbotAnswer($conn, $ctx, 'how much have I sold today');
t_ok($result['ok'], 'cashier gets their own sales');

$json = json_encode($result['answer']);
t_ok(str_contains($json, '100.00'), 'own sale is counted');
t_ok(!str_contains($json, '500.00'), "another cashier's sale is not counted");
t_ok(!str_contains($json, '999.00'), 'an unattributed sale is not counted as theirs');
t_ok(str_contains(mb_strtolower($json), 'hindi pa naitatala'),
    'the answer says older sales carry no cashier');

t_done();
```

- [ ] **Step 2: Run it and watch it fail**

Run: `C:/xampp/php/php.exe tests/chatbot/test_personal_sales.php`
Expected: FAIL — `Unknown column 'created_by' in 'field list'`.

- [ ] **Step 3: Add the column**

`platform/database/sales_created_by.sql`:

```sql
/*
| Who rang up a sale.
|
| The Cashier assistant promises "how much have I sold today?", but sales
| recorded only amounts, method, date and company -- never the person. The
| column is nullable because every sale already taken has no cashier to name,
| and those rows must keep working.
*/
ALTER TABLE sales
    ADD COLUMN created_by INT(11) NULL AFTER company_id,
    ADD CONSTRAINT fk_sales_created_by
        FOREIGN KEY (created_by) REFERENCES users(user_id);
```

Run: `C:/xampp/mysql/bin/mysql.exe -uroot sari < platform/database/sales_created_by.sql`

- [ ] **Step 4: Record it at the point of sale**

In `cashier/pointofsales.php`, replace lines 123-147 (preserve CRLF):

```php
        $stmt = mysqli_prepare($conn, '
            INSERT INTO sales
            (
                company_id,
                created_by,
                total_amount,
                tax_amount,
                cash_received,
                change_amount,
                payment_method,
                payment_reference
            )
            VALUES (?,?,?,?,?,?,?,?)
        ');

        /* Who rang it up. Null only if the session somehow carries no user,
           which the paywall and requireRole() already prevent. */
        $recordedBy = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;

        mysqli_stmt_bind_param(
            $stmt,
            'iiddddss',
            $companyId,
            $recordedBy,
            $grandTotal,
            $taxAmount,
            $cash,
            $change,
            $paymentMethod,
            $paymentReference
        );
```

- [ ] **Step 5: Write the handler**

Append to `includes/chatbot/answers/pos.php`:

```php
function chatbotMySalesToday(mysqli $conn, array $ctx, string $question): array
{
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS transactions, COALESCE(SUM(total_amount), 0) AS total
        FROM sales
        WHERE company_id = ? AND created_by = ? AND DATE(sale_date) = CURDATE()
    ");
    $stmt->bind_param("ii", $ctx['company_id'], $ctx['user_id']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    /* Sales taken before created_by existed belong to nobody. Saying so is
       better than quietly reporting a smaller number. */
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS orphans
        FROM sales
        WHERE company_id = ? AND created_by IS NULL AND DATE(sale_date) = CURDATE()
    ");
    $stmt->bind_param("i", $ctx['company_id']);
    $stmt->execute();
    $orphans = (int) $stmt->get_result()->fetch_assoc()['orphans'];
    $stmt->close();

    return [
        'title' => 'Benta ko ngayong araw',
        'lines' => [
            ['Kabuuan', chatbotPeso((float) $row['total'])],
            ['Transaksyon', (string) (int) $row['transactions']],
        ],
        'table' => null,
        'link' => null,
        'note' => $orphans > 0
            ? $orphans . ' na benta ngayong araw ang hindi pa naitatala kung sino ang nag-proseso, kaya hindi kasama.'
            : null,
    ];
}
```

- [ ] **Step 6: Run the test**

Run: `C:/xampp/php/php.exe tests/chatbot/test_personal_sales.php`
Expected: PASS.

- [ ] **Step 7: Prove the POS still records a sale**

Sign in as a cashier in the browser, take one small test sale, then run:

```bash
C:/xampp/mysql/bin/mysql.exe -uroot -N sari -e "SELECT sale_id, created_by, total_amount FROM sales ORDER BY sale_id DESC LIMIT 1;"
```
Expected: the new row carries the cashier's `user_id`, not NULL. This touches live POS code — do not skip this step.

- [ ] **Step 8: Checkpoint**

Run: `C:/xampp/php/php.exe tests/chatbot/run_all.php` — expect `ALL TESTS PASSED`.

---

### Task 7: Personal attendance and leave

**Files:**
- Create: `includes/chatbot/answers/personal.php`
- Modify: `includes/chatbot/engine.php` (require the new file)
- Test: `tests/chatbot/test_personal.php`

**Interfaces:**
- Consumes: `$ctx['employee_id']`.
- Produces: `chatbotMyAttendanceToday(mysqli $conn, array $ctx, string $question): array`, `chatbotMyLeaveStatus(mysqli $conn, array $ctx, string $question): array`.

- [ ] **Step 1: Write the failing test**

`tests/chatbot/test_personal.php`:

```php
<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/factory.php';
require_once __DIR__ . '/../../includes/chatbot/engine.php';

register_shutdown_function(function () use ($conn) { testCleanup($conn); });

$companyId = testMakeCompany($conn, 'Personal Co', 2);

$conn->query("INSERT INTO employees (company_id, first_name, last_name, employment_status)
              VALUES ({$companyId}, 'Ana', 'Cruz', 'Regular')");
$mine = (int) $conn->insert_id;

$conn->query("INSERT INTO employees (company_id, first_name, last_name, employment_status)
              VALUES ({$companyId}, 'Ben', 'Santos', 'Regular')");
$other = (int) $conn->insert_id;

$conn->query("INSERT INTO attendance (employee_id, attendance_date, time_in, status, company_id)
              VALUES ({$mine}, CURDATE(), '08:02:00', 'Present', {$companyId})");
$conn->query("INSERT INTO attendance (employee_id, attendance_date, time_in, status, company_id)
              VALUES ({$other}, CURDATE(), '07:45:00', 'Present', {$companyId})");

$conn->query("INSERT INTO leave_requests (employee_id, leave_type, duration, start_date, end_date, reason, hr_status, admin_status, company_id)
              VALUES ({$mine}, 'Sick Leave', 'Whole Day', CURDATE(), CURDATE(), 'Lagnat', 'Pending', 'Pending', {$companyId})");

$ctx = ['company_id' => $companyId, 'user_id' => 1,
        'employee_id' => $mine, 'role' => 'employee'];

$result = chatbotAnswer($conn, $ctx, 'anong oras ang time in ko');
t_ok($result['ok'], 'own attendance answered');
$json = json_encode($result['answer']);
t_ok(str_contains($json, '08:02'), 'shows my time in');
t_ok(!str_contains($json, '07:45'), "does not show another employee's time in");

$result = chatbotAnswer($conn, $ctx, 'ano na ang leave ko');
t_ok($result['ok'], 'own leave answered');
t_ok(str_contains(json_encode($result['answer']), 'Sick Leave'), 'shows my leave request');

/* Review Focus 2: an account with no employee row */
$ownerCtx = ['company_id' => $companyId, 'user_id' => 1,
             'employee_id' => null, 'role' => 'employee'];
$result = chatbotAnswer($conn, $ownerCtx, 'anong oras ang time in ko');
t_ok(!$result['ok'], 'a user with no employee record is refused, not answered');
t_same('needs_employee', $result['reason'], 'and the reason says why');
t_ok($result['suggestions'] !== [], 'they are still offered something they can ask');

t_done();
```

- [ ] **Step 2: Run it and watch it fail**

Run: `C:/xampp/php/php.exe tests/chatbot/test_personal.php`
Expected: FAIL — `chatbotMyAttendanceToday()` is undefined.

- [ ] **Step 3: Tighten the personal-scope guard**

In `includes/chatbot/engine.php`, replace the `scope === 'own'` guard written in Task 4 with the employee-aware version:

```php
    /* A personal question needs a person. An owner account has no employee
       row, so this is a real case, not a defensive nicety. */
    $needsEmployee = ['my_attendance_today', 'my_leave_status'];

    if (in_array($intentId, $needsEmployee, true) && empty($ctx['employee_id'])) {
        return [
            'ok' => false,
            'reason' => 'needs_employee',
            'intent' => $intentId,
            'matched_by' => $matchedBy,
            'answer' => null,
            'suggestions' => $suggestions,
        ];
    }

    if ($intent['scope'] === 'own' && empty($ctx['user_id'])) {
        return [
            'ok' => false,
            'reason' => 'needs_employee',
            'intent' => $intentId,
            'matched_by' => $matchedBy,
            'answer' => null,
            'suggestions' => $suggestions,
        ];
    }
```

- [ ] **Step 4: Write the handlers**

`includes/chatbot/answers/personal.php`:

```php
<?php
/*
| Answers about the asker's own records. Both statements bind company_id and
| the asker's own employee_id -- one without the other would be a leak.
*/

function chatbotMyAttendanceToday(mysqli $conn, array $ctx, string $question): array
{
    $stmt = $conn->prepare("
        SELECT time_in, time_out, status, late_minutes
        FROM attendance
        WHERE company_id = ? AND employee_id = ? AND attendance_date = CURDATE()
        LIMIT 1
    ");
    $stmt->bind_param("ii", $ctx['company_id'], $ctx['employee_id']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return [
            'title' => 'Attendance ko ngayon',
            'lines' => [],
            'table' => null,
            'link' => null,
            'note' => 'Wala pang naitalang attendance para ngayong araw.',
        ];
    }

    return [
        'title' => 'Attendance ko ngayon',
        'lines' => [
            ['Time in', (string) ($row['time_in'] ?? '—')],
            ['Time out', (string) ($row['time_out'] ?? 'Wala pa')],
            ['Status', (string) ($row['status'] ?? '—')],
            ['Late (minuto)', (string) (int) ($row['late_minutes'] ?? 0)],
        ],
        'table' => null,
        'link' => null,
    ];
}

function chatbotMyLeaveStatus(mysqli $conn, array $ctx, string $question): array
{
    $stmt = $conn->prepare("
        SELECT leave_type, start_date, end_date, hr_status, admin_status
        FROM leave_requests
        WHERE company_id = ? AND employee_id = ?
        ORDER BY created_at DESC
        LIMIT 5
    ");
    $stmt->bind_param("ii", $ctx['company_id'], $ctx['employee_id']);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];

    while ($row = $result->fetch_assoc()) {
        $rows[] = [
            $row['leave_type'],
            $row['start_date'] . ' — ' . $row['end_date'],
            'HR: ' . $row['hr_status'] . ' / Admin: ' . $row['admin_status'],
        ];
    }

    $stmt->close();

    return [
        'title' => 'Mga leave request ko',
        'lines' => [],
        'table' => $rows
            ? ['columns' => ['Uri', 'Petsa', 'Status'], 'rows' => $rows]
            : null,
        'link' => null,
        'note' => $rows ? null : 'Wala kang naitalang leave request.',
    ];
}
```

Add to the requires at the top of the dispatch section in `engine.php`:

```php
require_once __DIR__ . '/answers/personal.php';
```

- [ ] **Step 5: Run the test**

Run: `C:/xampp/php/php.exe tests/chatbot/test_personal.php`
Expected: PASS.

- [ ] **Step 6: Checkpoint**

Run: `C:/xampp/php/php.exe tests/chatbot/run_all.php` — expect `ALL TESTS PASSED`.

---

### Task 8: The endpoint

**Files:**
- Create: `includes/chatbot/ask.php`
- Create: `includes/chatbot/log.php`
- Modify: `includes/chatbot/engine.php` (add `chatbotSessionProblem()`)
- Test: `tests/chatbot/test_endpoint.php`

**Interfaces:**
- Consumes: `chatbotAnswer()`.
- Produces: `POST includes/chatbot/ask.php` returning the JSON contract in spec §7; `chatbotLog(mysqli $conn, array $ctx, string $question, ?string $intentId, ?string $matchedBy, string $outcome): void`; `chatbotRateLimited(mysqli $conn, array $ctx): bool`; `chatbotSessionProblem(array $session): ?string`.

- [ ] **Step 1: Write the failing test**

`tests/chatbot/test_endpoint.php` — tests the endpoint's units directly (the HTTP pass is Step 6):

```php
<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/factory.php';
require_once __DIR__ . '/../../includes/chatbot/engine.php';
require_once __DIR__ . '/../../includes/chatbot/log.php';

register_shutdown_function(function () use ($conn) { testCleanup($conn); });

$companyId = testMakeCompany($conn, 'Endpoint Co', 1);

$conn->query("INSERT INTO users (company_id, username, fullname, email, password, role, status)
              VALUES ({$companyId}, 'admin1', 'Admin One', 'a1@test.local', 'x', 'admin', 'active')");
$userId = (int) $conn->insert_id;

$ctx = ['company_id' => $companyId, 'user_id' => $userId,
        'employee_id' => null, 'role' => 'admin'];

/* Logging */
chatbotLog($conn, $ctx, 'magkano ang benta', 'sales_today', 'keyword', 'answered');

$stmt = $conn->prepare("SELECT question, intent_id, matched_by, outcome
                        FROM chatbot_messages WHERE company_id = ?");
$stmt->bind_param("i", $companyId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

t_same('sales_today', $row['intent_id'], 'the log records the intent');
t_same('answered', $row['outcome'], 'the log records the outcome');

/* Review Focus 3: a hostile, oversized question */
$monster = str_repeat('A', 5000) . " <script>alert(1)</script> '; DROP TABLE sales; -- 🙂";
$result = chatbotAnswer($conn, $ctx, $monster);
t_ok(is_array($result), 'an oversized hostile question returns a result, not a crash');

chatbotLog($conn, $ctx, $monster, null, null, 'no_match');

$stmt = $conn->prepare("SELECT CHAR_LENGTH(question) AS len FROM chatbot_messages
                        WHERE company_id = ? ORDER BY message_id DESC LIMIT 1");
$stmt->bind_param("i", $companyId);
$stmt->execute();
$len = (int) $stmt->get_result()->fetch_assoc()['len'];
$stmt->close();

t_ok($len <= 500, 'a huge question is truncated before storage');

$stmt = $conn->prepare("SELECT COUNT(*) AS total FROM sales WHERE company_id = ?");
$stmt->bind_param("i", $companyId);
$stmt->execute();
t_same(0, (int) $stmt->get_result()->fetch_assoc()['total'], 'the sales table still exists and is untouched');
$stmt->close();

/* Rate limit: the 20th is allowed, the 21st is not */
for ($i = 0; $i < 19; $i++) {
    chatbotLog($conn, $ctx, "q{$i}", null, null, 'no_match');
}

t_ok(!chatbotRateLimited($conn, $ctx), 'under the limit is allowed');

chatbotLog($conn, $ctx, 'one more', null, null, 'no_match');

t_ok(chatbotRateLimited($conn, $ctx), 'over the limit is refused');

/* Review Focus 5: a session whose subscription lapsed after sign-in */
$live = ['user_id' => $userId, 'role' => 'admin',
         'company_id' => $companyId, 'subscription_active' => 1];

t_same(null, chatbotSessionProblem($live), 'a paid, signed-in session may ask');

$lapsed = $live;
$lapsed['subscription_active'] = 0;
t_same('no_subscription', chatbotSessionProblem($lapsed),
    'a lapsed subscription is refused, matching requireRole()');

$signedOut = $live;
unset($signedOut['user_id']);
t_same('signed_out', chatbotSessionProblem($signedOut), 'no user in session is refused');

$noCompany = $live;
unset($noCompany['company_id']);
t_same('signed_out', chatbotSessionProblem($noCompany),
    'a session with no company (Super Admin) is refused');

t_done();
```

- [ ] **Step 2: Run it and watch it fail**

Run: `C:/xampp/php/php.exe tests/chatbot/test_endpoint.php`
Expected: FAIL — `includes/chatbot/log.php` does not exist.

- [ ] **Step 3: Write the log and rate limit**

`includes/chatbot/log.php`:

```php
<?php
/*
| The question is stored; the answer never is. An answer can hold salaries and
| takings, and a second copy of those is a second thing to protect.
*/

const CHATBOT_QUESTION_MAX = 500;
const CHATBOT_RATE_LIMIT = 20;

function chatbotLog(
    mysqli $conn,
    array $ctx,
    string $question,
    ?string $intentId,
    ?string $matchedBy,
    string $outcome
): void {
    $question = mb_substr(trim($question), 0, CHATBOT_QUESTION_MAX);

    $stmt = $conn->prepare("
        INSERT INTO chatbot_messages
            (company_id, user_id, role, question, intent_id, matched_by, outcome)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param(
        "iisssss",
        $ctx['company_id'],
        $ctx['user_id'],
        $ctx['role'],
        $question,
        $intentId,
        $matchedBy,
        $outcome
    );
    $stmt->execute();
    $stmt->close();
}

/**
 * Twenty questions a minute is far more than a person types and far less than
 * a script needs to pull a database out one answer at a time.
 */
function chatbotRateLimited(mysqli $conn, array $ctx): bool
{
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS asked
        FROM chatbot_messages
        WHERE company_id = ? AND user_id = ?
          AND created_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)
    ");
    $stmt->bind_param("ii", $ctx['company_id'], $ctx['user_id']);
    $stmt->execute();
    $asked = (int) $stmt->get_result()->fetch_assoc()['asked'];
    $stmt->close();

    return $asked >= CHATBOT_RATE_LIMIT;
}
```

- [ ] **Step 4: Write the endpoint**

`includes/chatbot/ask.php`:

```php
<?php
/*
|--------------------------------------------------------------------------
| THE ONE DOOR
|--------------------------------------------------------------------------
|
| The only place the chatbot reads $_SESSION, and the only place it writes.
|
| It does not call requireRole() or requireCompany(): those end a request with
| a redirect or a plain-text 403, which an AJAX caller cannot read. The same
| checks are made here and answered in JSON.
*/

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/engine.php';
require_once __DIR__ . '/log.php';

header('Content-Type: application/json; charset=utf-8');

function chatbotFail(string $reason, string $message, array $suggestions = []): void
{
    echo json_encode([
        'ok' => false,
        'reason' => $reason,
        'message' => $message,
        'suggestions' => $suggestions,
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    chatbotFail('method', 'Use POST.');
}

$problem = chatbotSessionProblem($_SESSION);

if ($problem === 'signed_out') {
    http_response_code(401);
    chatbotFail('auth', 'Mag-log in muli para magpatuloy.');
}

if ($problem === 'no_subscription') {
    http_response_code(403);
    chatbotFail('auth', 'Hindi aktibo ang subscription ng inyong negosyo.');
}

if (empty($_SESSION['chatbot_csrf'])) {
    $_SESSION['chatbot_csrf'] = bin2hex(random_bytes(16));
}

$payload = json_decode(file_get_contents('php://input') ?: '[]', true);
$payload = is_array($payload) ? $payload : [];

if (!hash_equals($_SESSION['chatbot_csrf'], (string) ($payload['csrf_token'] ?? ''))) {
    http_response_code(403);
    chatbotFail('auth', 'Nag-expire ang session. Pakirefresh ang pahina.');
}

$ctx = [
    'company_id' => (int) $_SESSION['company_id'],
    'user_id' => (int) $_SESSION['user_id'],
    'employee_id' => isset($_SESSION['employee_id']) ? (int) $_SESSION['employee_id'] : null,
    'role' => strtolower((string) $_SESSION['role']),
];

$question = trim((string) ($payload['question'] ?? ''));

if ($question === '') {
    chatbotFail('no_match', 'Ano ang gusto mong itanong?',
        chatbotSuggestions(chatbotAllowedIntents($conn, $ctx['company_id'], $ctx['role'])));
}

if (chatbotRateLimited($conn, $ctx)) {
    http_response_code(429);
    chatbotFail('rate_limited', 'Ang dami mong tanong sa isang minuto. Pakisubukan ulit mamaya.');
}

try {
    $result = chatbotAnswer($conn, $ctx, $question);
} catch (Throwable $error) {
    /* Detail to the log, never to the browser. */
    error_log('chatbot: ' . $error->getMessage());
    http_response_code(500);
    chatbotFail('error', 'May problema sa pagkuha ng sagot. Pakisubukan ulit.');
}

/* chatbot_messages.outcome is an ENUM of four values. needs_employee is a
   refusal, not one of them, so it is logged as no_match. */
$outcome = $result['ok']
    ? 'answered'
    : (in_array($result['reason'], ['denied_role', 'denied_plan'], true)
        ? $result['reason']
        : 'no_match');

chatbotLog($conn, $ctx, $question, $result['intent'], $result['matched_by'], $outcome);

if (!$result['ok']) {
    echo json_encode([
        'ok' => false,
        'reason' => $result['reason'],
        'message' => $result['reason'] === 'needs_employee'
            ? 'Nakatali ito sa record ng empleyado, at walang nakatalang empleyado sa account mo.'
            : 'Hindi ko kayang sagutin iyan. Narito ang mga kaya kong sagutin:',
        'suggestions' => $result['suggestions'],
    ]);
    exit;
}

echo json_encode([
    'ok' => true,
    'intent' => $result['intent'],
    'answer' => $result['answer'],
    'suggestions' => $result['suggestions'],
]);
```

Then add the session gate to `includes/chatbot/engine.php`, so the rule can be
tested without driving a browser session:

```php
/**
 * Why this session may not use the assistant, or null when it may.
 *
 * The rule mirrors requireRole(): an approved company that has not settled
 * its subscription cannot use the system, and that includes the chatbot. It
 * lives here as a function -- rather than inline in ask.php -- because a rule
 * that cannot be tested is a rule nobody can prove.
 *
 * @return 'signed_out'|'no_subscription'|null
 */
function chatbotSessionProblem(array $session): ?string
{
    if (empty($session['user_id']) || empty($session['role'])) {
        return 'signed_out';
    }

    if (empty($session['company_id'])) {
        return 'signed_out';
    }

    if (empty($session['subscription_active'])) {
        return 'no_subscription';
    }

    return null;
}
```

- [ ] **Step 5: Run the test**

Run: `C:/xampp/php/php.exe tests/chatbot/test_endpoint.php`
Expected: PASS.

- [ ] **Step 6: Prove the HTTP contract by hand**

With no session, run:

```bash
curl -s -i -X POST -H "Content-Type: application/json" -d "{\"question\":\"benta ngayon\"}" http://localhost/SariSmarts/includes/chatbot/ask.php
```
Expected: `HTTP/1.1 401` and `{"ok":false,"reason":"auth",...}` — JSON, not a redirect and not HTML.

- [ ] **Step 7: Checkpoint**

Run: `C:/xampp/php/php.exe tests/chatbot/run_all.php` and `python tests/chatbot/query_audit.py` — expect a pass from both (the audit's write exemption covers `ask.php` writing `chatbot_messages`).

---

### Task 9: The floating widget

**Files:**
- Create: `includes/chatbot/widget.php`
- Modify: `admin/admin_header.php` (before the closing of the header's page-content div)
- Modify: `cashier/cashier_header.php`
- Modify: `employee/employee_header.php`
- Test: manual, described below

**Interfaces:**
- Consumes: `POST ../includes/chatbot/ask.php`, `$_SESSION['chatbot_csrf']`.
- Produces: the bubble UI on every page that includes one of the three headers.

- [ ] **Step 1: Write the widget**

`includes/chatbot/widget.php`:

```php
<?php
/*
| The floating assistant. Included once per role header, so it rides along on
| every page without each page knowing about it.
|
| Answers are rendered with textContent, never innerHTML: a product or
| employee name containing markup is shown as text, not run as script.
*/

if (empty($_SESSION['user_id']) || empty($_SESSION['company_id'])) {
    return;
}

if (empty($_SESSION['chatbot_csrf'])) {
    $_SESSION['chatbot_csrf'] = bin2hex(random_bytes(16));
}
?>

<div id="chatbotRoot" class="chatbot-root">
    <button type="button" id="chatbotToggle" class="chatbot-bubble" aria-label="Assistant">
        <i class="bi bi-chat-dots-fill"></i>
    </button>

    <div id="chatbotPanel" class="chatbot-panel shadow" hidden>
        <div class="chatbot-head">
            <span class="fw-semibold">Assistant</span>
            <button type="button" id="chatbotClose" class="btn-close btn-close-white"></button>
        </div>

        <div id="chatbotLog" class="chatbot-log"></div>
        <div id="chatbotChips" class="chatbot-chips"></div>

        <form id="chatbotForm" class="chatbot-form">
            <input type="text" id="chatbotInput" class="form-control form-control-sm"
                   placeholder="Magtanong..." autocomplete="off" maxlength="500">
            <button class="btn btn-primary btn-sm" type="submit">
                <i class="bi bi-send"></i>
            </button>
        </form>
    </div>
</div>

<style>
    .chatbot-root { position: fixed; right: 20px; bottom: 20px; z-index: 1080; }
    .chatbot-bubble { width: 54px; height: 54px; border-radius: 50%; border: 0;
        background: #0d6efd; color: #fff; font-size: 1.4rem; box-shadow: 0 6px 18px rgba(0,0,0,.2); }
    .chatbot-panel { position: absolute; right: 0; bottom: 66px; width: min(360px, calc(100vw - 40px));
        max-height: 70vh; background: #fff; border-radius: 16px; display: flex; flex-direction: column; overflow: hidden; }
    .chatbot-head { background: #0d6efd; color: #fff; padding: 10px 14px;
        display: flex; align-items: center; justify-content: space-between; }
    .chatbot-log { flex: 1; overflow-y: auto; padding: 12px; font-size: .875rem; }
    .chatbot-msg { margin-bottom: 10px; }
    .chatbot-msg.me { text-align: right; color: #0d6efd; }
    .chatbot-card { background: #f6f8fb; border-radius: 12px; padding: 10px; }
    .chatbot-card table { width: 100%; font-size: .8rem; }
    .chatbot-chips { padding: 0 12px 8px; display: flex; flex-wrap: wrap; gap: 6px; }
    .chatbot-chip { border: 1px solid #cfd8e3; background: #fff; border-radius: 999px;
        padding: 3px 10px; font-size: .75rem; cursor: pointer; }
    .chatbot-form { display: flex; gap: 6px; padding: 10px; border-top: 1px solid #e9eef5; }
</style>

<script>
(function () {
    const token = <?= json_encode($_SESSION['chatbot_csrf']) ?>;
    const endpoint = '<?= str_repeat('../', 1) ?>includes/chatbot/ask.php';

    const panel = document.getElementById('chatbotPanel');
    const log = document.getElementById('chatbotLog');
    const chips = document.getElementById('chatbotChips');
    const form = document.getElementById('chatbotForm');
    const input = document.getElementById('chatbotInput');

    document.getElementById('chatbotToggle').addEventListener('click', function () {
        panel.hidden = !panel.hidden;
        if (!panel.hidden) { input.focus(); }
    });

    document.getElementById('chatbotClose').addEventListener('click', function () {
        panel.hidden = true;
    });

    function bubble(text, mine) {
        const div = document.createElement('div');
        div.className = 'chatbot-msg' + (mine ? ' me' : '');
        div.textContent = text;
        log.appendChild(div);
        log.scrollTop = log.scrollHeight;
        return div;
    }

    function renderAnswer(answer) {
        const card = document.createElement('div');
        card.className = 'chatbot-card';

        const title = document.createElement('div');
        title.className = 'fw-semibold mb-1';
        title.textContent = answer.title || '';
        card.appendChild(title);

        (answer.lines || []).forEach(function (line) {
            const row = document.createElement('div');
            row.className = 'd-flex justify-content-between';

            const label = document.createElement('span');
            label.textContent = line[0];

            const value = document.createElement('span');
            value.className = 'fw-semibold';
            value.textContent = line[1];

            row.appendChild(label);
            row.appendChild(value);
            card.appendChild(row);
        });

        if (answer.table) {
            const table = document.createElement('table');
            const head = document.createElement('tr');

            answer.table.columns.forEach(function (column) {
                const th = document.createElement('th');
                th.textContent = column;
                head.appendChild(th);
            });

            table.appendChild(head);

            answer.table.rows.forEach(function (row) {
                const tr = document.createElement('tr');
                row.forEach(function (cell) {
                    const td = document.createElement('td');
                    td.textContent = cell;
                    tr.appendChild(td);
                });
                table.appendChild(tr);
            });

            card.appendChild(table);
        }

        if (answer.note) {
            const note = document.createElement('div');
            note.className = 'text-muted small mt-1';
            note.textContent = answer.note;
            card.appendChild(note);
        }

        if (answer.link) {
            const link = document.createElement('a');
            link.className = 'small d-inline-block mt-1';
            link.href = answer.link.href;
            link.textContent = answer.link.label;
            card.appendChild(link);
        }

        const wrap = document.createElement('div');
        wrap.className = 'chatbot-msg';
        wrap.appendChild(card);
        log.appendChild(wrap);
        log.scrollTop = log.scrollHeight;
    }

    function renderChips(suggestions) {
        chips.innerHTML = '';

        (suggestions || []).forEach(function (suggestion) {
            const chip = document.createElement('button');
            chip.type = 'button';
            chip.className = 'chatbot-chip';
            chip.textContent = suggestion.label;
            chip.addEventListener('click', function () { ask(suggestion.label); });
            chips.appendChild(chip);
        });
    }

    function ask(question) {
        bubble(question, true);
        input.value = '';

        fetch(endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ question: question, csrf_token: token })
        })
        .then(function (response) { return response.json(); })
        .then(function (data) {
            if (data.ok) {
                renderAnswer(data.answer);
            } else {
                bubble(data.message || 'Hindi ko kayang sagutin iyan.', false);
            }
            renderChips(data.suggestions);
        })
        .catch(function () {
            bubble('Hindi ako makakonekta ngayon. Pakisubukan ulit.', false);
        });
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        const question = input.value.trim();
        if (question !== '') { ask(question); }
    });

    /* An empty question returns the suggestion list and nothing else, so the
       panel opens already showing what this user may ask. */
    function loadSuggestions() {
        fetch(endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ question: '', csrf_token: token })
        })
        .then(function (response) { return response.json(); })
        .then(function (data) { renderChips(data.suggestions); })
        .catch(function () { /* The chips are a convenience; typing still works. */ });
    }

    loadSuggestions();
})();
</script>
```

Note: `ask('')` posts an empty question; the endpoint answers with `reason: no_match` plus suggestions, and the widget prints the prompt. Change `ask('')` to only fetch suggestions without printing the empty user bubble by guarding `bubble()` when the question is empty.

- [ ] **Step 2: Include it in the three headers**

At the end of `admin/admin_header.php`, `cashier/cashier_header.php` and `employee/employee_header.php`, after the existing markup and before any closing PHP tag, add (preserve CRLF):

```php
<?php include __DIR__ . '/../includes/chatbot/widget.php'; ?>
```

- [ ] **Step 3: Check it by hand in the browser**

Sign in as Admin. On the dashboard: the bubble appears bottom-right; opening it shows suggestion chips; clicking "Benta ngayong araw" returns a card with the figure; typing "how many employees do we have" answers; typing "asdfgh" returns the "cannot answer" message plus chips.

Sign in as Cashier: chips differ, and "magkano ang benta ngayong araw" is refused while "how much have I sold today" answers.

- [ ] **Step 4: Confirm nothing else on the page broke**

Open the POS page as the cashier and take one test sale. The widget must not intercept the POS keyboard shortcuts or cover the payment button on a 1366×768 screen. If it covers anything, adjust `bottom`/`right` in the widget CSS only — do not edit POS styles.

- [ ] **Step 5: Checkpoint**

Run: `C:/xampp/php/php.exe tests/chatbot/run_all.php` — expect `ALL TESTS PASSED`.

---

### Task 10: The AI understanding layer and its fallback

**Files:**
- Create: `includes/chatbot/ai_client.php`
- Create: `C:\xampp\sarismart_secrets.php` (outside the webroot; not in the project)
- Modify: `includes/chatbot/understand.php` (add `chatbotUnderstand()`)
- Modify: `includes/chatbot/engine.php` (call `chatbotUnderstand()` instead of `chatbotKeywordMatch()`)
- Test: `tests/chatbot/test_ai_fallback.php`

**Interfaces:**
- Consumes: `chatbotKeywordMatch()`, the allowed-intent list.
- Produces:
  - `chatbotAiIntent(string $question, array $allowedIntents): ?string`
  - `chatbotUnderstand(string $question, array $allowedIntents, ?callable $ai = null): array` returning `['intent' => ?string, 'matched_by' => 'keyword'|'ai']`

- [ ] **Step 1: Write the failing test**

`tests/chatbot/test_ai_fallback.php`:

```php
<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../includes/chatbot/intents.php';
require_once __DIR__ . '/../../includes/chatbot/understand.php';

$allowed = chatbotIntents();

/* The AI path is used when it answers with an allowed id. */
$result = chatbotUnderstand('ano ang kinita natin ngayon', $allowed,
    function () { return 'sales_today'; });
t_same('sales_today', $result['intent'], 'AI answer is used');
t_same('ai', $result['matched_by'], 'and is recorded as the AI path');

/* Review Focus: a hallucinated or out-of-list id must not be trusted. */
$result = chatbotUnderstand('kahit ano', $allowed, function () { return 'payroll_secret'; });
t_same(null, $result['intent'], 'an id outside the list is discarded');

$result = chatbotUnderstand('kahit ano', $allowed, function () { return 'Sure! I think you want sales_today.'; });
t_same(null, $result['intent'], 'prose is discarded');

/* An intent the user may not ask must not be reachable through the AI. */
$cashierOnly = ['my_sales_today' => $allowed['my_sales_today']];
$result = chatbotUnderstand('magkano ang benta ng buong tindahan', $cashierOnly,
    function () { return 'sales_today'; });
t_same(null, $result['intent'], 'the AI cannot name an intent outside the allowed list');

/* Fallback: the AI throws (no internet, timeout, HTTP error). */
$result = chatbotUnderstand('magkano ang benta ngayong araw', $allowed,
    function () { throw new RuntimeException('Could not resolve host'); });
t_same('sales_today', $result['intent'], 'a failed AI call falls back to keywords');
t_same('keyword', $result['matched_by'], 'and is recorded as the keyword path');

/* Fallback: the AI returns null (no key, switched off, over the cap). */
$result = chatbotUnderstand('magkano ang benta ngayong araw', $allowed,
    function () { return null; });
t_same('sales_today', $result['intent'], 'no AI available still answers');

/* With no AI callable at all -- the Phase 1 default before a key exists. */
$result = chatbotUnderstand('magkano ang benta ngayong araw', $allowed, null);
t_same('keyword', $result['matched_by'], 'no AI configured uses keywords');

t_done();
```

- [ ] **Step 2: Run it and watch it fail**

Run: `C:/xampp/php/php.exe tests/chatbot/test_ai_fallback.php`
Expected: FAIL — `chatbotUnderstand()` is undefined.

- [ ] **Step 3: Write `chatbotUnderstand()`**

Append to `includes/chatbot/understand.php`:

```php
/**
 * Map a question to an intent, preferring the AI and falling back to keywords.
 *
 * The AI is a language layer only. Whatever it returns is checked against
 * $allowedIntents -- the list already filtered by role and plan -- so the worst
 * a wrong or manipulated reply can do is pick a different question this same
 * user was already permitted to ask.
 *
 * $ai is injected rather than called directly so the tests can drive every
 * failure path without a network.
 *
 * @return array{intent: ?string, matched_by: string}
 */
function chatbotUnderstand(string $question, array $allowedIntents, ?callable $ai = null): array
{
    if ($ai !== null) {
        try {
            $candidate = $ai($question, $allowedIntents);

            if (is_string($candidate) && isset($allowedIntents[$candidate])) {
                return ['intent' => $candidate, 'matched_by' => 'ai'];
            }

            /* An id outside the list, prose, or null: fall through to keywords
               rather than trusting it. */
        } catch (Throwable $error) {
            error_log('chatbot ai: ' . $error->getMessage());
        }
    }

    return [
        'intent' => chatbotKeywordMatch($question, $allowedIntents),
        'matched_by' => 'keyword',
    ];
}
```

- [ ] **Step 4: Run the test**

Run: `C:/xampp/php/php.exe tests/chatbot/test_ai_fallback.php`
Expected: PASS.

- [ ] **Step 5: Write the API client**

`C:\xampp\sarismart_secrets.php` (outside the webroot, not in the project, never committed):

```php
<?php
return [
    'anthropic_api_key' => 'sk-ant-REPLACE-ME',
    'chatbot_ai_enabled' => true,
    'chatbot_ai_daily_cap' => 200,
];
```

`includes/chatbot/ai_client.php`:

```php
<?php
/*
| The only file in the system that makes an outbound call.
|
| It sends the question and the labels of the intents this user may already
| ask. It never sends a record, a figure, or a company name. What comes back
| is checked by the caller against that same list.
*/

const CHATBOT_AI_MODEL = 'claude-haiku-4-5-20251001';
const CHATBOT_AI_TIMEOUT = 2.5;
const CHATBOT_SECRETS = 'C:\\xampp\\sarismart_secrets.php';

function chatbotAiSettings(): array
{
    static $settings = null;

    if ($settings === null) {
        $settings = is_readable(CHATBOT_SECRETS) ? (array) require CHATBOT_SECRETS : [];
    }

    return $settings;
}

/**
 * The id of the intent the model believes the question means, or null.
 *
 * Returning null is the normal, safe outcome for every failure: no key,
 * switched off, over the cap, no internet, a timeout, an HTTP error, or a
 * reply that is not one of the ids we sent. The caller then uses keywords.
 */
function chatbotAiIntent(string $question, array $allowedIntents): ?string
{
    $settings = chatbotAiSettings();

    if (empty($settings['chatbot_ai_enabled']) || empty($settings['anthropic_api_key'])) {
        return null;
    }

    if ($allowedIntents === []) {
        return null;
    }

    $menu = [];

    foreach ($allowedIntents as $id => $intent) {
        $menu[] = $id . ' = ' . $intent['label'];
    }

    $prompt = "Choose which one of these questions the user is asking.\n\n"
        . implode("\n", $menu)
        . "\n\nUser question: " . mb_substr($question, 0, 500)
        . "\n\nAnswer with the id alone, or the word none. No other words.";

    $payload = json_encode([
        'model' => CHATBOT_AI_MODEL,
        'max_tokens' => 20,
        'messages' => [['role' => 'user', 'content' => $prompt]],
    ]);

    $curl = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => CHATBOT_AI_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-api-key: ' . $settings['anthropic_api_key'],
            'anthropic-version: 2023-06-01',
        ],
    ]);

    $response = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($response === false || $status !== 200) {
        return null;
    }

    $body = json_decode((string) $response, true);
    $text = trim((string) ($body['content'][0]['text'] ?? ''));

    return isset($allowedIntents[$text]) ? $text : null;
}
```

- [ ] **Step 6: Wire it into the engine**

In `includes/chatbot/engine.php`, add the require beside the others:

```php
require_once __DIR__ . '/ai_client.php';
```

and replace the matching line inside `chatbotAnswer()`:

```php
    $understood = chatbotUnderstand($question, $allowed, 'chatbotAiIntent');
    $intentId = $understood['intent'];
    $matchedBy = $understood['matched_by'];
```

- [ ] **Step 7: Prove the offline path for real**

With **no** `C:\xampp\sarismart_secrets.php` present (rename it if it exists), run the whole suite:

Run: `C:/xampp/php/php.exe tests/chatbot/run_all.php`
Expected: `ALL TESTS PASSED` — every question still answered, no warning printed.

Then create the secrets file with a deliberately wrong key and an unreachable host (change `api.anthropic.com` to `api.invalid.test` temporarily) and run the suite again. Expected: same result, plus entries in the PHP error log. **Restore the host afterwards.**

- [ ] **Step 8: Run the audit**

Run: `python tests/chatbot/query_audit.py`
Expected: `0 problem(s)` — `ai_client.php` is the one file allowed to call out.

- [ ] **Step 9: Checkpoint**

Run the suite and the audit together; both must pass.

---

### Task 11: The isolation proof

**Files:**
- Test: `tests/chatbot/test_isolation.php`
- Test: `tests/chatbot/test_matrix.php`

**Interfaces:**
- Consumes: everything built so far.
- Produces: the two tests that stand behind the promise made to the user.

- [ ] **Step 1: Write the cross-company test**

`tests/chatbot/test_isolation.php`:

```php
<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/factory.php';
require_once __DIR__ . '/../../includes/chatbot/engine.php';

register_shutdown_function(function () use ($conn) { testCleanup($conn); });

/* Two companies, deliberately similar so a leak would look plausible. */
$alpha = testMakeCompany($conn, 'Alpha', 2);
$beta = testMakeCompany($conn, 'Beta', 2);

function seedCompany(mysqli $conn, int $companyId, string $product, float $price, float $sale): int
{
    $conn->query("INSERT INTO categories (category_name, company_id) VALUES ('Cat', {$companyId})");
    $categoryId = (int) $conn->insert_id;

    $conn->query("INSERT INTO products (product_name, category_id, company_id)
                  VALUES ('{$product}', {$categoryId}, {$companyId})");
    $productId = (int) $conn->insert_id;

    $conn->query("INSERT INTO inventory (product_id, quantity, purchase_cost, selling_price, reorder_level, company_id)
                  VALUES ({$productId}, 2, 1.00, {$price}, 5, {$companyId})");

    $conn->query("INSERT INTO sales (company_id, total_amount, tax_amount, cash_received, change_amount, payment_method)
                  VALUES ({$companyId}, {$sale}, 0.00, {$sale}, 0.00, 'Cash')");

    return $productId;
}

seedCompany($conn, $alpha, 'AlphaOnlyProduct', 11.11, 1111.11);
seedCompany($conn, $beta, 'BetaOnlyProduct', 22.22, 2222.22);

$questions = [
    'magkano ang benta ngayong araw',
    'magkano ang benta ngayong buwan',
    'which products are low in stock',
    'anong produkto ang out of stock',
    'magkano ang alphaonlyproduct',
    'magkano ang betaonlyproduct',
    'how many employees do we have',
    'pinakamabentang produkto',
];

foreach ([[$alpha, 'Alpha', 'Beta'], [$beta, 'Beta', 'Alpha']] as [$companyId, $mine, $theirs]) {

    $ctx = ['company_id' => $companyId, 'user_id' => 1, 'employee_id' => null, 'role' => 'admin'];

    foreach ($questions as $question) {
        $result = chatbotAnswer($conn, $ctx, $question);
        $json = json_encode($result);

        t_ok(!str_contains($json, $theirs . 'OnlyProduct'),
            "{$mine}: \"{$question}\" does not name {$theirs}'s product");
        t_ok(!str_contains($json, $theirs === 'Alpha' ? '1111.11' : '2222.22'),
            "{$mine}: \"{$question}\" does not show {$theirs}'s takings");
        t_ok(!str_contains($json, $theirs === 'Alpha' ? '11.11' : '22.22'),
            "{$mine}: \"{$question}\" does not show {$theirs}'s price");
    }
}

t_done();
```

- [ ] **Step 2: Run it**

Run: `C:/xampp/php/php.exe tests/chatbot/test_isolation.php`
Expected: PASS. **A failure here is not a test to adjust — it is the leak the whole design exists to prevent.**

- [ ] **Step 3: Write the matrix test**

`tests/chatbot/test_matrix.php`:

```php
<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/factory.php';
require_once __DIR__ . '/../../includes/chatbot/engine.php';

register_shutdown_function(function () use ($conn) { testCleanup($conn); });

$starter = testMakeCompany($conn, 'Matrix Starter', 1);
$professional = testMakeCompany($conn, 'Matrix Professional', 2);

/* Spec section 6, as a table: role => [intent => may ask?] */
$matrix = [
    'admin' => [
        'sales_today' => true, 'sales_month' => true, 'top_products_month' => true,
        'low_stock' => true, 'out_of_stock' => true, 'product_price' => true,
        'product_stock' => true, 'staff_count' => true,
        'my_sales_today' => false, 'my_attendance_today' => false, 'my_leave_status' => false,
    ],
    'cashier' => [
        'product_price' => true, 'product_stock' => true, 'my_sales_today' => true,
        'my_attendance_today' => true, 'my_leave_status' => true,
        'sales_today' => false, 'sales_month' => false, 'top_products_month' => false,
        'low_stock' => false, 'out_of_stock' => false, 'staff_count' => false,
    ],
    'employee' => [
        'my_attendance_today' => true, 'my_leave_status' => true,
        'sales_today' => false, 'product_price' => false, 'low_stock' => false,
        'staff_count' => false, 'my_sales_today' => false,
    ],
    'hr' => [
        'sales_today' => false, 'product_price' => false, 'low_stock' => false,
        'my_sales_today' => false,
    ],
    'finance' => [
        'sales_today' => false, 'low_stock' => false, 'my_sales_today' => false,
    ],
    'inventory' => [
        'sales_today' => false, 'my_sales_today' => false,
    ],
];

foreach ($matrix as $role => $expectations) {
    $allowed = chatbotAllowedIntents($conn, $professional, $role);

    foreach ($expectations as $intentId => $mayAsk) {
        t_same($mayAsk, isset($allowed[$intentId]),
            "{$role} " . ($mayAsk ? 'may' : 'may NOT') . " ask {$intentId}");
    }
}

/* Plan gate: Starter keeps the four topics it bought and nothing more. */
$starterAdmin = chatbotAllowedIntents($conn, $starter, 'admin');

foreach (['sales_today', 'low_stock', 'staff_count', 'top_products_month'] as $intentId) {
    t_ok(isset($starterAdmin[$intentId]), "Starter admin keeps {$intentId}");
}

foreach (chatbotIntents() as $intentId => $intent) {
    if (in_array($intent['topic'], ['hrms', 'payroll', 'finance', 'recruitment', 'cross_branch'], true)) {
        t_ok(!isset($starterAdmin[$intentId]),
            "Starter admin is refused {$intentId} ({$intent['topic']})");
    }
}

t_done();
```

- [ ] **Step 4: Run the whole suite and the audit**

Run:
```bash
C:/xampp/php/php.exe tests/chatbot/run_all.php
python tests/chatbot/query_audit.py
```
Expected: `ALL TESTS PASSED` and `0 problem(s)`.

- [ ] **Step 5: Confirm the database is clean**

Run:
```bash
C:/xampp/mysql/bin/mysql.exe -uroot -N sari -e "SELECT COUNT(*) FROM company WHERE company_name LIKE 'CHATBOT-TEST %';"
```
Expected: `0`.

- [ ] **Step 6: Checkpoint**

Phase 1 is complete: Admin, Cashier and Employee have a working assistant, every answer is company-scoped and proven so, and the AI layer degrades to keywords whenever it cannot be reached.

---

## What Phase 1 deliberately leaves out

- HR, Finance and Inventory Staff intents (Phase 2 and 3), and their advisories.
- The advisory mechanism itself, including the HR hiring suggestion (Phase 2).
- The full-page view and history (Phase 3).
- Any write action. Nothing in this plan creates, approves or edits a record, apart from the assistant's own log.
