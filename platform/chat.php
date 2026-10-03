<?php

require_once __DIR__ . '/includes/landing_chat.php';

/*
|--------------------------------------------------------------------------
| THE PUBLIC CHAT ENDPOINT
|--------------------------------------------------------------------------
|
| Open to anybody, which is the whole difficulty: every request past here
| can cost money at a paid API. So the limits are counted in the database
| and checked before the call, not after.
|
| Two actions:
|
|   ask   a question, answered
|   lead  the visitor's details, filed in marketing_leads
|
| The browser holds a session_token that it was given on its first
| question. That token is the only thing tying a visitor to their own
| conversation, so it is random, long, and never derived from anything
| guessable.
|
| Everything returns JSON, including failures. A visitor who hits a limit
| still gets a civil sentence rather than a blank bubble.
|
*/

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

/* The widget posts JSON; nothing here reads a form. */
$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    $input = [];
}

$action = (string) ($input['action'] ?? 'ask');
$token = (string) ($input['token'] ?? '');

$ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');


/**
 * One JSON reply, and stop.
 */
function chatReply(array $data): void
{
    echo json_encode($data);
    exit;
}


/**
 * The conversation this token names, or a new one.
 *
 * A token that matches nothing is treated as absent rather than refused:
 * a visitor whose row was cleaned up should get a fresh conversation, not
 * an error they cannot act on.
 */
function chatSession(mysqli $conn, string $token, string $ip): array
{
    if ($token !== '') {

        $stmt = $conn->prepare("
            SELECT chat_id, session_token, message_count, lead_id
            FROM landing_chats WHERE session_token = ? LIMIT 1
        ");
        $stmt->bind_param("s", $token);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row) {
            return $row;
        }
    }

    $fresh = bin2hex(random_bytes(20));

    $stmt = $conn->prepare("
        INSERT INTO landing_chats (session_token, visitor_ip) VALUES (?, ?)
    ");
    $stmt->bind_param("ss", $fresh, $ip);
    $stmt->execute();
    $chatId = $conn->insert_id;
    $stmt->close();

    return [
        'chat_id' => $chatId,
        'session_token' => $fresh,
        'message_count' => 0,
        'lead_id' => null,
    ];
}


/**
 * Writes one turn to the thread.
 */
function chatRecord(mysqli $conn, int $chatId, string $role, string $body, ?string $model = null): void
{
    $stmt = $conn->prepare("
        INSERT INTO landing_chat_messages (chat_id, role, body, ai_model)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->bind_param("isss", $chatId, $role, $body, $model);
    $stmt->execute();
    $stmt->close();

    if ($role === 'visitor') {
        $bump = $conn->prepare("
            UPDATE landing_chats SET message_count = message_count + 1 WHERE chat_id = ?
        ");
        $bump->bind_param("i", $chatId);
        $bump->execute();
        $bump->close();
    }
}


/*
|--------------------------------------------------------------------------
| ASK
|--------------------------------------------------------------------------
*/

if ($action === 'ask') {

    $question = trim((string) ($input['message'] ?? ''));

    if ($question === '') {
        chatReply(['ok' => false, 'reply' => 'Ask me anything about SariSmart.']);
    }

    if (mb_strlen($question) > LANDING_CHAT_MAX_INPUT) {
        chatReply([
            'ok' => false,
            'reply' => 'That is a long one. Could you shorten it to a sentence or two?',
        ]);
    }

    /* Per address, before anything is written: an abusive caller should not
       be able to fill the table either. */
    if (landingChatIpUsage($conn, $ip) >= LANDING_CHAT_MAX_PER_IP_HOUR) {
        chatReply([
            'ok' => false,
            'reply' => 'You have asked a lot in a short time. Please try again later, or leave '
                . 'your details and the team will get in touch.',
        ]);
    }

    $session = chatSession($conn, $token, $ip);
    $chatId = (int) $session['chat_id'];

    if ((int) $session['message_count'] >= LANDING_CHAT_MAX_PER_CHAT) {
        chatReply([
            'ok' => false,
            'token' => $session['session_token'],
            'reply' => 'We have covered a lot here. Leave your details and someone from the team '
                . 'will pick it up properly.',
            'askForLead' => true,
        ]);
    }

    chatRecord($conn, $chatId, 'visitor', $question);

    /* The thread so far, for context. */
    $history = [];

    $stmt = $conn->prepare("
        SELECT role, body FROM landing_chat_messages
        WHERE chat_id = ? AND message_id < (SELECT MAX(message_id) FROM landing_chat_messages WHERE chat_id = ?)
        ORDER BY message_id
    ");
    $stmt->bind_param("ii", $chatId, $chatId);
    $stmt->execute();
    $rows = $stmt->get_result();

    while ($row = $rows->fetch_assoc()) {
        $history[] = $row;
    }

    $stmt->close();

    /*
    | The AI path, if it is on and the day's budget is not spent. Past the
    | cap everything still works; it just answers from the script.
    */
    $model = null;
    $reply = null;

    if (landingChatAiReady() && landingChatUsageToday($conn) < LANDING_CHAT_DAILY_CAP) {

        $reply = landingChatAsk($conn, $history, $question);

        if ($reply !== null) {
            $settings = landingChatSettings();
            $model = (string) ($settings['landing_chat_model'] ?? LANDING_CHAT_MODEL);
        }
    }

    if ($reply === null) {
        $reply = landingChatScripted($conn, $question);
    }

    chatRecord($conn, $chatId, 'assistant', $reply, $model);

    chatReply([
        'ok' => true,
        'token' => $session['session_token'],
        'reply' => $reply,

        /* Asked of the visitor's own words, and only while they have not
           already left their details. */
        'askForLead' => $session['lead_id'] === null && landingChatInterested($question),
    ]);
}


/*
|--------------------------------------------------------------------------
| LEAD
|--------------------------------------------------------------------------
|
| The details the visitor chose to leave. It lands in the same queue the
| Leads module already works through: source Website, stage New.
*/

if ($action === 'lead') {

    $session = chatSession($conn, $token, $ip);
    $chatId = (int) $session['chat_id'];

    if ($session['lead_id'] !== null) {
        chatReply([
            'ok' => true,
            'token' => $session['session_token'],
            'reply' => 'We already have your details - the team will be in touch shortly.',
        ]);
    }

    $name = trim((string) ($input['name'] ?? ''));
    $business = trim((string) ($input['business'] ?? ''));
    $email = trim((string) ($input['email'] ?? ''));
    $phone = trim((string) ($input['phone'] ?? ''));

    /*
    | Enough to ring them back, and no more. The chat is not the
    | registration form; a visitor who has to fill in six boxes closes it.
    */
    if ($name === '' || $business === '') {
        chatReply(['ok' => false, 'reply' => 'Please give your name and your business name.']);
    }

    if ($email === '' && $phone === '') {
        chatReply(['ok' => false, 'reply' => 'Please leave an email address or a phone number.']);
    }

    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        chatReply(['ok' => false, 'reply' => 'That email address does not look right.']);
    }

    foreach (['name' => $name, 'business' => $business, 'email' => $email, 'phone' => $phone] as $value) {
        if (mb_strlen($value) > 150) {
            chatReply(['ok' => false, 'reply' => 'One of those is too long. Please shorten it.']);
        }
    }

    /*
    | Which plan they were asking about, taken from what they actually
    | typed. Guessing wrong here sends the team in with the wrong pitch, so
    | anything unclear stays "Not Sure".
    */
    $interest = 'Not Sure';

    $thread = $conn->prepare("
        SELECT body FROM landing_chat_messages
        WHERE chat_id = ? AND role = 'visitor' ORDER BY message_id
    ");
    $thread->bind_param("i", $chatId);
    $thread->execute();
    $said = '';
    $rows = $thread->get_result();

    while ($row = $rows->fetch_assoc()) {
        $said .= ' ' . mb_strtolower($row['body']);
    }

    $thread->close();

    if (mb_strpos($said, 'enterprise') !== false) {
        $interest = 'Retail Enterprise';
    } elseif (mb_strpos($said, 'professional') !== false) {
        $interest = 'Retail Professional';
    } elseif (mb_strpos($said, 'starter') !== false) {
        $interest = 'Retail Starter';
    }

    $emailValue = $email !== '' ? $email : null;
    $phoneValue = $phone !== '' ? $phone : null;
    $note = 'Raised through the website assistant. The conversation is on the lead.';

    $stmt = $conn->prepare("
        INSERT INTO marketing_leads
            (business_name, contact_name, contact_email, contact_phone,
             source, interest, stage, notes)
        VALUES (?, ?, ?, ?, 'Website', ?, 'New', ?)
    ");
    $stmt->bind_param("ssssss", $business, $name, $emailValue, $phoneValue, $interest, $note);
    $stmt->execute();
    $leadId = $conn->insert_id;
    $stmt->close();

    $link = $conn->prepare("UPDATE landing_chats SET lead_id = ? WHERE chat_id = ?");
    $link->bind_param("ii", $leadId, $chatId);
    $link->execute();
    $link->close();

    chatReply([
        'ok' => true,
        'token' => $session['session_token'],
        'reply' => 'Thank you, ' . $name . '. The team will be in touch shortly.',
    ]);
}


chatReply(['ok' => false, 'reply' => 'That request was not understood.']);
