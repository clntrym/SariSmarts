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

/* The refusal intent is itself local: there is nothing to ask a model about. */
t_ok(chatbotLocalOnlyMatch($conn, $cashierCtx, 'magkano ang sweldo ko') !== null,
    'a pay question from any role is answered locally');
t_ok(chatbotLocalOnlyMatch($conn, $financeCtx, 'how much is the salary of Ana') !== null,
    'including from finance, who has payroll answers but no individual-pay one');

/* An ordinary question is never held back. */
t_same(null, chatbotLocalOnlyMatch($conn, $cashierCtx, 'magkano ang lucky me'),
    'a price question goes to the model as usual');

t_done();
