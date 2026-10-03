<?php
/*
| The findings from the whole-branch review of Stage A2 and the English pass.
*/
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/factory.php';
require_once __DIR__ . '/../../includes/chatbot/engine.php';
require_once __DIR__ . '/../../includes/chatbot/chat/tool_runner.php';

register_shutdown_function(function () use ($conn) { testCleanup($conn); });

$companyId = testMakeCompany($conn, 'A2 Review Co', 2);
$otherId = testMakeCompany($conn, 'A2 Review Other Co', 2);
$ctx = ['company_id' => $companyId, 'user_id' => 1, 'employee_id' => null, 'role' => 'admin'];

/*
| CRITICAL 1: the English scan was a blacklist of phrases somebody thought of,
| and it missed "Buksan ang Income" in the answer every sales question returns.
| Scan every string literal instead.
*/
$tagalogWords = ['ang', 'ng', 'mga', 'ko', 'mo', 'ninyo', 'namin', 'kayo', 'ako',
                 'wala', 'walang', 'hindi', 'ito', 'iyan', 'pakisubukan', 'paki',
                 'buksan', 'magtanong', 'benta', 'presyo', 'produkto', 'empleyado',
                 'ngayon', 'araw', 'buwan', 'bilang', 'kabuuan', 'sagot', 'tanong'];

/*
| Only the files whose strings the USER reads. The matcher's synonyms
| (understand.php, intents.php) and the tool descriptions the MODEL reads
| (chat/tools.php) carry Tagalog on purpose -- that is how the assistant keeps
| understanding a Tagalog question while answering in English.
*/
$files = array_merge(
    [__DIR__ . '/../../includes/chatbot/widget.php',
     __DIR__ . '/../../includes/chatbot/ask.php'],
    glob(__DIR__ . '/../../includes/chatbot/answers/*.php')
);

foreach ($files as $file) {
    $name = basename($file);
    $source = file_get_contents($file);
    $source = preg_replace('#/\*.*?\*/#s', ' ', $source);
    $source = preg_replace('#(?m)//.*$#', ' ', $source);

    preg_match_all('/\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)"/s', $source, $matches);

    foreach (array_merge($matches[1], $matches[2]) as $literal) {

        $words = preg_split('/[^a-z]+/', mb_strtolower($literal), -1, PREG_SPLIT_NO_EMPTY);

        foreach ($words as $word) {
            if (in_array($word, $tagalogWords, true)) {
                t_ok(false, "{$name} still says Tagalog in a string: \"" . mb_substr($literal, 0, 45) . '"');
                continue 2;
            }
        }
    }

    t_ok(true, "{$name} has no Tagalog in any string it can show");
}

/*
| CRITICAL 2: the isolation test could not fail for tools whose rows carry no
| company-distinguishing value. Counts and times must be checked exactly.
*/
$conn->query("INSERT INTO branch (company_id, branch_name, complete_address, province, city, status)
              VALUES ({$companyId}, 'Ours', '1 St', 'Cavite', 'Imus', 'Active')");
$ourBranch = (int) $conn->insert_id;

$conn->query("INSERT INTO branch (company_id, branch_name, complete_address, province, city, status)
              VALUES ({$otherId}, 'Theirs A', '2 St', 'Laguna', 'Calamba', 'Active')");
$theirBranch = (int) $conn->insert_id;
$conn->query("INSERT INTO branch (company_id, branch_name, complete_address, province, city, status)
              VALUES ({$otherId}, 'Theirs B', '3 St', 'Laguna', 'Calamba', 'Active')");

$conn->query("INSERT INTO employees (company_id, first_name, last_name, branch_id, employment_status)
              VALUES ({$companyId}, 'Ana', 'Cruz', {$ourBranch}, 'Official Employee')");
$ana = (int) $conn->insert_id;

foreach (['Bea', 'Cora', 'Dina'] as $name) {
    $conn->query("INSERT INTO employees (company_id, first_name, last_name, branch_id, employment_status)
                  VALUES ({$otherId}, '{$name}', 'Theirs', {$theirBranch}, 'Official Employee')");
    $theirEmployee = (int) $conn->insert_id;
    $conn->query("INSERT INTO attendance (employee_id, attendance_date, time_in, status, company_id)
                  VALUES ({$theirEmployee}, CURDATE(), CONCAT(CURDATE(), ' 06:11:00'), 'Present', {$otherId})");
}

$conn->query("INSERT INTO attendance (employee_id, attendance_date, time_in, status, company_id)
              VALUES ({$ana}, CURDATE(), CONCAT(CURDATE(), ' 08:22:00'), 'Present', {$companyId})");

/* company_profile's counts are our own, not both companies added up. */
$profile = chatRunTool($conn, $ctx, 'company_profile', []);
t_same('1', $profile['rows'][0][5], 'company_profile counts only our branches');
t_same('1', $profile['rows'][0][6], 'and only our staff');

/* attendance_detail returns no name, so the count and the time are the proof. */
$detail = chatRunTool($conn, $ctx, 'attendance_detail',
    ['employee' => 'Theirs', 'period' => 'this_month']);
t_same([], $detail['rows'], "attendance_detail finds nobody from another company");

$detail = chatRunTool($conn, $ctx, 'attendance_detail',
    ['employee' => 'Cruz', 'period' => 'this_month']);
t_same(1, count($detail['rows']), 'and exactly our own employee\'s days');
t_ok(str_contains(json_encode($detail), '08:22'), 'with our time');
t_ok(!str_contains(json_encode($detail), '06:11'), 'never theirs');

/* my_leave across companies -- it was untested. */
$conn->query("INSERT INTO leave_requests (employee_id, leave_type, duration, start_date, end_date, reason, hr_status, admin_status, company_id)
              VALUES ({$ana}, 'Sick Leave', 'Whole Day', CURDATE(), CURDATE(), 'reason', 'Pending', 'Pending', {$companyId})");

$conn->query("INSERT INTO employees (company_id, first_name, last_name, employment_status)
              VALUES ({$otherId}, 'Outsider', 'Person', 'Official Employee')");
$outsider = (int) $conn->insert_id;
$conn->query("INSERT INTO leave_requests (employee_id, leave_type, duration, start_date, end_date, reason, hr_status, admin_status, company_id)
              VALUES ({$outsider}, 'Their Leave', 'Whole Day', CURDATE(), CURDATE(), 'reason', 'Pending', 'Pending', {$otherId})");

$crossCtx = ['company_id' => $companyId, 'user_id' => 5,
             'employee_id' => $outsider, 'role' => 'cashier'];
$result = chatRunTool($conn, $crossCtx, 'my_leave', ['status' => 'all']);
t_same([], $result['rows'], 'my_leave with an outside employee id returns nothing');

$mineCtx = ['company_id' => $companyId, 'user_id' => 5,
            'employee_id' => $ana, 'role' => 'cashier'];
$result = chatRunTool($conn, $mineCtx, 'my_leave', ['status' => 'all']);
t_same(1, count($result['rows']), 'and my own leave is still there');
t_ok(!str_contains(json_encode($result), 'Their Leave'), "never the other company's");

/*
| IMPORTANT 3: a name of punctuation must not return the whole company, and a
| name WITH punctuation must still be found.
*/
$conn->query("INSERT INTO employees (company_id, first_name, last_name, employment_status)
              VALUES ({$companyId}, \"Mary O'Brien\", 'Dela Cruz-Santos', 'Official Employee')");
$obrien = (int) $conn->insert_id;
$conn->query("INSERT INTO attendance (employee_id, attendance_date, time_in, status, company_id)
              VALUES ({$obrien}, CURDATE(), CONCAT(CURDATE(), ' 09:09:00'), 'Present', {$companyId})");

$result = chatRunTool($conn, $ctx, 'attendance_detail',
    ['employee' => '???', 'period' => 'this_month']);
t_same([], $result['rows'], 'a name of pure punctuation matches nobody, not everybody');

$result = chatRunTool($conn, $ctx, 'attendance_detail',
    ['employee' => "O'Brien", 'period' => 'this_month']);
t_same(1, count($result['rows']), 'a name with an apostrophe is findable');

$result = chatRunTool($conn, $ctx, 'attendance_detail',
    ['employee' => 'Dela Cruz-Santos', 'period' => 'this_month']);
t_same(1, count($result['rows']), 'and so is a hyphenated surname');

$result = chatRunTool($conn, $ctx, 'attendance_detail',
    ['employee' => '%', 'period' => 'this_month']);
t_same([], $result['rows'], 'a lone wildcard is not a name');

/* IMPORTANT 4: Half Day is a real attendance status and must be counted. */
$conn->query("INSERT INTO attendance (employee_id, attendance_date, time_in, status, company_id)
              VALUES ({$ana}, DATE_SUB(CURDATE(), INTERVAL 1 DAY),
                      CONCAT(DATE_SUB(CURDATE(), INTERVAL 1 DAY), ' 08:00:00'), 'Half Day', {$companyId})");

/* A custom range: the half day is seeded for yesterday, which on the first
   of a month falls outside 'this_month'. */
$summary = chatRunTool($conn, $ctx, 'attendance_summary', [
    'period' => 'custom',
    'from' => date('Y-m-d', strtotime('-7 days')),
    'to' => date('Y-m-d'),
]);
t_ok(in_array('days_half', $summary['columns'], true), 'attendance_summary counts half days');

$row = null;

foreach ($summary['rows'] as $candidate) {
    if ($candidate[0] === 'Ana Cruz') {
        $row = $candidate;
    }
}

t_ok($row !== null, 'our employee is in the summary');
$half = array_search('days_half', $summary['columns'], true);
t_same('1', $row[$half], 'and the half day is not lost');

/* IMPORTANT 5: every applicant stage is accounted for. */
$conn->query("INSERT INTO job (job_title, department, branch_id, vacancies, employment_type, status, application_deadline, company_id)
              VALUES ('Cashier', 'Cashier', {$ourBranch}, 1, 'Full Time', 'Published',
                      DATE_ADD(CURDATE(), INTERVAL 30 DAY), {$companyId})");
$jobId = (int) $conn->insert_id;

foreach (['Pending', 'Interview', 'Interview Result', 'Recommended', 'Hired', 'Rejected'] as $i => $status) {
    $conn->query("INSERT INTO applications (job_id, first_name, last_name, email, status, company_id)
                  VALUES ({$jobId}, 'App{$i}', 'Test', 'app{$i}@test.local', '{$status}', {$companyId})");
}

$recruit = chatRunTool($conn, $ctx, 'recruitment_summary', ['state' => 'open']);
$columns = array_flip($recruit['columns']);
$row = $recruit['rows'][0];

$stages = 0;

foreach (['awaiting_review', 'at_interview', 'recommended', 'hired', 'rejected'] as $stage) {
    t_ok(isset($columns[$stage]), "recruitment_summary reports {$stage}");
    $stages += (int) $row[$columns[$stage]];
}

t_same((int) $row[$columns['applicants']], $stages,
    'the stage counts add up to the applicant total');

/* IMPORTANT 7 and 8: truncation, for the A2 tools and for stock_requests. */
for ($i = 0; $i < 60; $i++) {
    $conn->query("INSERT INTO employees (company_id, first_name, last_name, employment_status)
                  VALUES ({$companyId}, 'Bulk{$i}', 'Staff', 'Official Employee')");
    $conn->query("INSERT INTO stock_requests (request_code, total_price, reason, status, company_id)
                  VALUES ('SR-A2-{$i}', 10.00, 'bulk', 'Received', {$companyId})");
}

foreach ([5, 25, 50] as $limit) {
    $result = chatRunTool($conn, $ctx, 'staff_list', ['state' => 'all', 'limit' => $limit]);
    t_same($limit, count($result['rows']), "staff_list limit {$limit} returns that many");
    t_ok($result['truncated'], "staff_list limit {$limit} says more matched");
}

$result = chatRunTool($conn, $ctx, 'stock_requests', ['status' => 'all']);
t_ok(count($result['rows']) <= CHAT_TOOL_ROW_CAP,
    'stock_requests with no limit never exceeds the cap');
t_ok($result['truncated'], 'and says more matched');

/* IMPORTANT 9: one definition of an employee across the assistant. */
$phase1 = chatbotAnswer($conn, $ctx, 'how many employees do we have')['answer'];
$profile = chatRunTool($conn, $ctx, 'company_profile', []);

t_same($profile['rows'][0][6], $phase1['lines'][1][1],
    'the Phase 1 count and the A2 profile agree on how many staff there are');

/* IMPORTANT 10: a Starter cashier can actually RUN their personal tools. */
$starter = testMakeCompany($conn, 'A2 Review Starter', 1);
$conn->query("INSERT INTO employees (company_id, first_name, last_name, employment_status)
              VALUES ({$starter}, 'Cash', 'Ier', 'Official Employee')");
$starterEmployee = (int) $conn->insert_id;
$conn->query("INSERT INTO attendance (employee_id, attendance_date, time_in, status, company_id)
              VALUES ({$starterEmployee}, CURDATE(), CONCAT(CURDATE(), ' 07:07:00'), 'Present', {$starter})");

$starterCtx = ['company_id' => $starter, 'user_id' => 2,
               'employee_id' => $starterEmployee, 'role' => 'cashier'];

$result = chatRunTool($conn, $starterCtx, 'my_attendance', ['period' => 'this_month']);
t_ok($result['ok'], 'a Starter cashier can run their own attendance');
t_ok(str_contains(json_encode($result), '07:07'), 'and it returns their day');

$result = chatRunTool($conn, $starterCtx, 'attendance_summary', ['period' => 'this_month']);
t_ok(!$result['ok'], 'but not the company-wide attendance their plan lacks');

t_done();
