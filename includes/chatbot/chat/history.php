<?php
/*
| The conversation so far, in the session and nowhere else.
|
| Keeping it out of the database is what lets the Phase 1 promise stand: no
| answer content is stored, so takings and employee names never acquire a
| second copy that needs its own protection.
|
| It is stamped with the user and company it belongs to, and read back only for
| them. Signing out of this app does not destroy the session -- acc_log_out.php
| unsets a few keys and leaves the rest -- so without the stamp the next person
| to sign in on a shop terminal would inherit the previous user's answers, and
| with them figures their own role is not allowed to see.
*/

const CHAT_HISTORY_TURNS = 6;
const CHAT_HISTORY_QUESTION_MAX = 500;
const CHAT_HISTORY_ANSWER_MAX = 1000;

/*
| An answer keeps the rows it was built from, so the next question can be
| "ano-ano?" instead of a whole new question. Two caps keep that from growing
| without limit: rows per table, and the serialised size of the lot.
*/
const CHAT_HISTORY_ROWS = 15;
const CHAT_HISTORY_TABLES_MAX = 4000;

/**
 * The turns belonging to this user, in this company, and nobody else.
 *
 * The cap is applied on the way out as well as on the way in: a session that
 * grew before the cap existed, or was tampered with, must not reach the model
 * unbounded. Malformed turns are dropped rather than passed along, because a
 * PHP warning printed into a JSON response breaks the whole reply.
 */
/**
 * The rows an answer is allowed to carry forward.
 *
 * Trimmed twice: each table to its first rows, then the whole set to a size
 * that cannot bloat the session or the next request. A follow-up needs enough
 * to name what was already shown, not the entire result.
 */
function chatHistoryTables(array $tables): array
{
    $kept = [];

    foreach ($tables as $table) {

        if (!is_array($table) || empty($table['rows'])) {
            continue;
        }

        $rows = array_slice((array) $table['rows'], 0, CHAT_HISTORY_ROWS);

        $candidate = $kept;
        $candidate[] = [
            'tool' => (string) ($table['tool'] ?? ''),
            'columns' => (array) ($table['columns'] ?? []),
            'rows' => $rows,
        ];

        /* Adding this table would push the set past the cap, so stop here
           rather than storing a half-serialised one. */
        if (mb_strlen((string) json_encode($candidate)) > CHAT_HISTORY_TABLES_MAX) {
            break;
        }

        $kept = $candidate;
    }

    return $kept;
}

function chatHistoryGet(array $session, array $ctx): array
{
    $stored = $session['chatbot_history'] ?? null;

    if (!is_array($stored) || !isset($stored['turns']) || !is_array($stored['turns'])) {
        return [];
    }

    if ((int) ($stored['user_id'] ?? 0) !== (int) $ctx['user_id']
        || (int) ($stored['company_id'] ?? 0) !== (int) $ctx['company_id']) {
        return [];
    }

    $turns = [];

    foreach ($stored['turns'] as $turn) {

        if (!is_array($turn) || !isset($turn['role'], $turn['text'])) {
            continue;
        }

        if (!in_array($turn['role'], ['user', 'assistant'], true)) {
            continue;
        }

        $entry = [
            'role' => $turn['role'],
            'text' => mb_substr((string) $turn['text'], 0, CHAT_HISTORY_ANSWER_MAX),
        ];

        if (!empty($turn['tables']) && is_array($turn['tables'])) {
            $entry['tables'] = $turn['tables'];
        }

        $turns[] = $entry;
    }

    $max = CHAT_HISTORY_TURNS * 2;

    return count($turns) > $max ? array_slice($turns, -$max) : $turns;
}

function chatHistoryAppend(
    array &$session,
    string $question,
    string $answer,
    array $ctx,
    array $tables = []
): void {
    $turns = chatHistoryGet($session, $ctx);

    $turns[] = ['role' => 'user',
                'text' => mb_substr(trim($question), 0, CHAT_HISTORY_QUESTION_MAX)];

    $assistant = ['role' => 'assistant',
                  'text' => mb_substr(trim($answer), 0, CHAT_HISTORY_ANSWER_MAX)];

    $kept = chatHistoryTables($tables);

    if ($kept !== []) {
        $assistant['tables'] = $kept;
    }

    $turns[] = $assistant;

    /* Keep the newest turns; a turn is a question and its answer. */
    $max = CHAT_HISTORY_TURNS * 2;

    if (count($turns) > $max) {
        $turns = array_slice($turns, -$max);
    }

    $session['chatbot_history'] = [
        'user_id' => (int) $ctx['user_id'],
        'company_id' => (int) $ctx['company_id'],
        'turns' => $turns,
    ];
}
