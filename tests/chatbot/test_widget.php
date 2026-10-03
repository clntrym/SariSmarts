<?php
/*
| The widget is markup, so it is checked as markup: rendered with a fake
| session and read back.
|
| What matters here is not how it looks but what it refuses to do -- draw
| anything for a signed-out visitor, and put an answer on the page as HTML.
*/
require_once __DIR__ . '/bootstrap.php';

function renderWidget(array $session): string
{
    $_SESSION = $session;

    ob_start();
    include __DIR__ . '/../../includes/chatbot/widget.php';

    return (string) ob_get_clean();
}

/* Signed out: nothing at all. */
$html = renderWidget([]);
t_same('', trim($html), 'the widget draws nothing for a signed-out visitor');

/* Signed in. */
$html = renderWidget(['user_id' => 7, 'company_id' => 3, 'role' => 'admin']);

t_ok(str_contains($html, 'chatbotToggle'), 'the bubble is drawn');
t_ok(str_contains($html, 'chatbotForm'), 'the question form is drawn');
t_ok(str_contains($html, 'includes/chatbot/ask.php'), 'it posts to the endpoint');

t_ok(!empty($_SESSION['chatbot_csrf']), 'a CSRF token is put in the session');
t_ok(str_contains($html, $_SESSION['chatbot_csrf']), 'and handed to the page');

/*
| Answers carry product and employee names. Writing them with innerHTML would
| run any markup they contain; textContent shows it as text.
*/
t_ok(!str_contains($html, 'innerHTML =') && !str_contains($html, 'innerHTML='),
    'no answer content is assigned through innerHTML');
t_ok(substr_count($html, 'textContent') >= 4, 'answers are rendered with textContent');

/*
| The panel starts closed, so it cannot cover the POS screen on load. Matched
| on the panel's own tag: the stylesheet contains "overflow: hidden", so a bare
| search for "hidden" would pass even with the attribute deleted.
*/
t_ok((bool) preg_match('/<div id="chatbotPanel"[^>]*\shidden[\s>]/', $html),
    'the panel element itself carries the hidden attribute');

t_done();
