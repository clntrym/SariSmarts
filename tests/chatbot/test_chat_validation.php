<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../includes/chatbot/chat/tools.php';
require_once __DIR__ . '/../../includes/chatbot/chat/tool_runner.php';

$tools = chatTools();
$sales = $tools['sales_summary'];
$lookup = $tools['product_lookup'];
$top = $tools['top_products'];

/* Good input */
$result = chatValidateInput($sales, ['period' => 'today']);
t_ok($result['ok'], 'a valid enum passes');
t_same('today', $result['values']['period'], 'and the value survives');

/* Review Focus 2: junk the model can produce */
t_ok(!chatValidateInput($sales, ['period' => 'last_decade'])['ok'],
    'an enum value outside the list is rejected');
t_ok(!chatValidateInput($sales, [])['ok'], 'a missing required parameter is rejected');
t_ok(!chatValidateInput($sales, ['period' => 'custom', 'from' => 'yesterday pls',
                                 'to' => '2026-01-01'])['ok'],
    'a malformed date is rejected');
t_ok(!chatValidateInput($sales, ['period' => 'custom', 'from' => '2026-05-01',
                                 'to' => '2026-04-01'])['ok'],
    'a from date later than the to date is rejected');
t_ok(!chatValidateInput($sales, ['period' => 'custom'])['ok'],
    'custom without dates is rejected');

t_ok(!chatValidateInput($lookup, ['name' => str_repeat('x', 10000)])['ok'],
    'an absurdly long string is rejected');
t_ok(!chatValidateInput($lookup, ['name' => ''])['ok'], 'an empty name is rejected');

t_ok(!chatValidateInput($top, ['period' => 'today', 'limit' => -5])['ok'],
    'a negative limit is rejected');
t_ok(!chatValidateInput($top, ['period' => 'today', 'limit' => 100000])['ok'],
    'an enormous limit is rejected');

/* Unknown parameters are dropped rather than passed through. */
$result = chatValidateInput($sales, ['period' => 'today', 'drop_table' => 'sales']);
t_ok($result['ok'], 'an unknown parameter does not fail the call');
t_ok(!array_key_exists('drop_table', $result['values']), 'but it is not carried forward');

/* Period resolution */
$today = chatResolvePeriod('today', null, null);
t_same(date('Y-m-d'), $today['from'], 'today resolves to today');
t_same(date('Y-m-d'), $today['to'], 'both ends');

$yesterday = chatResolvePeriod('yesterday', null, null);
t_same(date('Y-m-d', strtotime('-1 day')), $yesterday['from'], 'yesterday resolves back one day');

$month = chatResolvePeriod('this_month', null, null);
t_same(date('Y-m-01'), $month['from'], 'this month starts on the first');

$custom = chatResolvePeriod('custom', '2026-03-01', '2026-03-15');
t_same('2026-03-01', $custom['from'], 'custom keeps its own dates');
t_same('2026-03-15', $custom['to'], 'both of them');

t_done();
