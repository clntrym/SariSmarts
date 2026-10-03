<?php
require_once __DIR__ . '/bootstrap.php';

function columnsOf(mysqli $conn, string $table): array
{
    $columns = [];

    /* mysqli throws in PHP 8.2, so a missing table must be caught here --
       otherwise the run dies instead of reporting a failed assertion. */
    try {
        $result = $conn->query("SHOW COLUMNS FROM `{$table}`");
    } catch (mysqli_sql_exception $error) {
        return $columns;
    }

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

try {
    $result = $conn->query("SELECT topic, plan_id FROM chatbot_topic_plans");

    while ($row = $result->fetch_assoc()) {
        $seeded[(int) $row['plan_id']][] = $row['topic'];
    }
} catch (mysqli_sql_exception $error) {
    /* No table yet: every grant assertion below fails, which is the point. */
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
