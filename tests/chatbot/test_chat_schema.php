<?php
require_once __DIR__ . '/bootstrap.php';

$columns = [];
$result = $conn->query("SHOW COLUMNS FROM chatbot_messages");

while ($row = $result->fetch_assoc()) {
    $columns[$row['Field']] = $row['Type'];
}

t_ok(isset($columns['tools_used']), 'chatbot_messages has tools_used');
t_ok(str_contains((string) ($columns['matched_by'] ?? ''), 'ai_chat'),
    'matched_by accepts ai_chat');

/* The column holds tool names, so it must survive a realistic list. */
t_ok(str_contains((string) ($columns['tools_used'] ?? ''), '255'),
    'tools_used is wide enough for a list of tool names');

t_done();
