<?php
/*
| The AI is a language layer only, and the keyword matcher is the floor it
| stands on. These tests drive every failure path without a network, by
| injecting the AI callable.
*/
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../includes/chatbot/intents.php';
require_once __DIR__ . '/../../includes/chatbot/understand.php';

$allowed = chatbotIntents();

/* The AI path is used when it answers with an allowed id. */
$result = chatbotUnderstand('ano ang kinita natin ngayon', $allowed,
    function () { return 'sales_today'; });
t_same('sales_today', $result['intent'], 'AI answer is used');
t_same('ai', $result['matched_by'], 'and is recorded as the AI path');

/* A hallucinated or out-of-list id must not be trusted. */
$result = chatbotUnderstand('kahit ano', $allowed, function () { return 'payroll_secret'; });
t_same(null, $result['intent'], 'an id outside the list is discarded');

$result = chatbotUnderstand('kahit ano', $allowed,
    function () { return 'Sure! I think you want sales_today.'; });
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

/* With no AI callable at all -- the default before a key exists. */
$result = chatbotUnderstand('magkano ang benta ngayong araw', $allowed, null);
t_same('keyword', $result['matched_by'], 'no AI configured uses keywords');

t_done();
