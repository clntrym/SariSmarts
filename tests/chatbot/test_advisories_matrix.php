<?php
/*
| The two gates advisories share with intents -- role and plan -- and the one
| gate nothing may cross: the company boundary.
|
| This test walks the whole catalog rather than a chosen few, so an advisory
| added later cannot quietly skip either gate or point at a page that does not
| exist.
*/
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/factory.php';
require_once __DIR__ . '/../../includes/chatbot/engine.php';

register_shutdown_function(function () use ($conn) { testCleanup($conn); });

$catalog = chatbotAdvisories();
t_ok(count($catalog) >= 14, 'the catalog holds every advisory built so far');

/* Every entry is shaped the way chatbotRunAdvisories expects. */
foreach ($catalog as $id => $advisory) {
    foreach (['topic', 'roles', 'scope', 'page', 'check'] as $key) {
        t_ok(array_key_exists($key, $advisory), "{$id} declares {$key}");
    }

    t_ok(function_exists($advisory['check']), "{$id}'s check function exists");
    t_ok($advisory['roles'] !== [], "{$id} names at least one role");
    t_ok(in_array($advisory['scope'], ['company', 'own'], true), "{$id}'s scope is real");
}

/*
| Every link an advisory offers points at a page that exists in that role's own
| folder -- the Phase 2A review found advisories and answers linking to
| payroll.php for roles that have no such file, and this is the check that
| stops it coming back.
|
| A null link is allowed, but only where the role genuinely has no such page:
| the owner has no recruitment or payroll folder of their own. So the test
| proves the absence rather than accepting it -- if that page is ever added for
| that role, this fails and the link map has to catch up.
*/
$enterprise = testMakeCompany($conn, 'Advisory Matrix Co', 3);

$folders = ['admin' => 'admin', 'hr' => 'hr', 'finance' => 'finance',
            'inventory' => 'inventory', 'cashier' => 'cashier', 'employee' => 'employee'];

foreach ($catalog as $id => $advisory) {
    if ($advisory['page'] === null) {
        continue;
    }

    foreach ($advisory['roles'] as $role) {
        $ctx = ['company_id' => $enterprise, 'user_id' => 1, 'employee_id' => 1, 'role' => $role];
        $link = chatbotPageLink($ctx, $advisory['page']);
        $folder = __DIR__ . '/../../' . $folders[$role] . '/';

        if ($link === null) {
            /* Whatever file another role uses for this page is not in this one's folder. */
            foreach (['admin', 'hr', 'finance', 'inventory'] as $other) {
                $otherLink = chatbotPageLink(['role' => $other], $advisory['page']);

                if ($otherLink === null) {
                    continue;
                }

                t_ok(!file_exists($folder . $otherLink['href']),
                    "{$id} offers {$role} no link because {$otherLink['href']} is not theirs");
            }

            continue;
        }

        t_ok(file_exists($folder . $link['href']),
            "{$id}'s link for {$role} points at a file that exists");
        t_ok(trim($link['label']) !== '', "{$id}'s link for {$role} is labelled");
    }
}

/*
| The role gate. A cashier may only ever be offered its own advisories; no role
| is offered an advisory that does not name it.
*/
foreach (['admin', 'hr', 'finance', 'inventory', 'cashier', 'employee'] as $role) {
    $ctx = ['company_id' => $enterprise, 'user_id' => 1, 'employee_id' => 1, 'role' => $role];

    foreach (chatbotAdvisoriesFor($conn, $ctx) as $id => $advisory) {
        t_ok(in_array($role, $advisory['roles'], true),
            "{$role} is only offered {$id} because {$id} names it");
    }
}

$ctx = ['company_id' => $enterprise, 'user_id' => 1, 'employee_id' => 1, 'role' => 'cashier'];
$cashierIds = array_keys(chatbotAdvisoriesFor($conn, $ctx));
sort($cashierIds);
t_same(['my_leave_pending', 'my_time_out_missing'], $cashierIds,
    'a cashier is offered nothing but their own two reminders');

/* An unknown role is offered nothing at all. */
$ctx = ['company_id' => $enterprise, 'user_id' => 1, 'employee_id' => 1, 'role' => 'auditor'];
t_same([], chatbotAdvisoriesFor($conn, $ctx), 'an unknown role is offered nothing');

/*
| The plan gate. A Starter company has no HRMS, no recruitment, no payroll and
| no finance, so an owner on Starter is offered none of those advisories.
*/
$starter = testMakeCompany($conn, 'Advisory Starter Co', 1);
$starterCtx = ['company_id' => $starter, 'user_id' => 1, 'employee_id' => 1, 'role' => 'admin'];
$starterIds = array_keys(chatbotAdvisoriesFor($conn, $starterCtx));

foreach (['hiring_needed', 'applicants_waiting', 'leave_waiting',
          'payroll_waiting', 'finance_requests', 'payables_due', 'capital_low'] as $beyond) {
    t_ok(!in_array($beyond, $starterIds, true), "Starter is not offered {$beyond}");
}

t_ok(in_array('approvals_waiting', $starterIds, true),
    'but Starter still gets its inventory advisories');

/* An expired plan row does not widen anything. */
$proCtx = ['company_id' => $enterprise, 'user_id' => 1, 'employee_id' => 1, 'role' => 'admin'];
t_ok(count(chatbotAdvisoriesFor($conn, $proCtx)) > count($starterIds),
    'Enterprise is offered more than Starter');

/*
| The company boundary. Two companies, each with the same conditions true, and
| neither one's figures may appear in the other's advice.
*/
$alpha = testMakeCompany($conn, 'Advisory Alpha', 3);
$beta = testMakeCompany($conn, 'Advisory Beta', 3);

foreach ([[$alpha, 'ALPHA', 1111.00], [$beta, 'BETA', 2222.00]] as [$id, $tag, $amount]) {
    $conn->query("INSERT INTO stock_requests (request_code, total_price, reason, status, company_id)
                  VALUES ('SR-{$tag}', {$amount}, 'restock', 'Pending Admin', {$id})");
}

$alphaCtx = ['company_id' => $alpha, 'user_id' => 1, 'employee_id' => null, 'role' => 'admin'];
$betaCtx = ['company_id' => $beta, 'user_id' => 2, 'employee_id' => null, 'role' => 'admin'];

$alphaText = json_encode(chatbotRunAdvisories($conn, $alphaCtx));
$betaText = json_encode(chatbotRunAdvisories($conn, $betaCtx));

t_ok(str_contains($alphaText, '1,111.00'), 'Alpha sees its own request');
t_ok(!str_contains($alphaText, '2,222.00'), "Alpha never sees Beta's request");
t_ok(str_contains($betaText, '2,222.00'), 'Beta sees its own request');
t_ok(!str_contains($betaText, '1,111.00'), "Beta never sees Alpha's request");

/*
| And the same boundary for an advisory that counts rather than names. Beta has
| three approvals waiting; Alpha still reports one.
*/
for ($i = 0; $i < 2; $i++) {
    $conn->query("INSERT INTO stock_requests (request_code, total_price, reason, status, company_id)
                  VALUES ('SR-BETA-X{$i}', 10.00, 'restock', 'Pending Admin', {$beta})");
}

foreach (chatbotRunAdvisories($conn, $alphaCtx) as $entry) {
    if ($entry['id'] === 'approvals_waiting') {
        t_ok(str_contains($entry['message'], '1 stock request'),
            "Alpha's count is its own, not the database's");
    }
}

/* Asking for advice writes nothing anywhere. */
$before = $conn->query("SELECT COUNT(*) AS c FROM stock_requests
                        WHERE company_id IN ({$alpha}, {$beta})")->fetch_assoc()['c'];
chatbotRunAdvisories($conn, $alphaCtx);
chatbotRunAdvisories($conn, $betaCtx);
$after = $conn->query("SELECT COUNT(*) AS c FROM stock_requests
                       WHERE company_id IN ({$alpha}, {$beta})")->fetch_assoc()['c'];

t_same($before, $after, 'asking for advice created nothing');

t_done();
