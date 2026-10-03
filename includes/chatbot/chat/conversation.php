<?php
/*
|--------------------------------------------------------------------------
| THE CONVERSATION LOOP
|--------------------------------------------------------------------------
|
| Asks the model, runs the tools it asks for, and asks again -- within hard
| limits this file enforces and the model cannot argue with.
|
| The model is injected as a callable. That is what lets the whole loop be
| tested with a scripted model: no network, no spend, and every failure path
| reachable on purpose.
*/

require_once __DIR__ . '/tool_runner.php';

const CHAT_MAX_ROUNDS = 3;
const CHAT_MAX_TOOL_CALLS = 5;

/*
| The whole question, model calls and tool queries together. Past it the
| keyword answer is better than a spinner.
|
| Three rounds at up to 15 seconds each cannot fit in 20, so a question that
| genuinely needed two tools used to time out just as the model was about to
| answer, and the user got the canned reply instead.
*/
const CHAT_DEADLINE_SECONDS = 30;

/**
 * Whether an answer is allowed to state what it states.
 *
 * A model that guesses today's takings is worse than one that declines, so an
 * answer containing figures is only returned when a tool actually ran. Prose
 * with no numbers -- "I have no tool for salaries" -- needs nothing behind it.
 */
function chatAnswerIsGrounded(string $text, array $toolsUsed, bool $priorTools = false): bool
{
    if ($toolsUsed !== []) {
        return true;
    }

    /*
    | A follow-up runs no tool of its own. "May paubos ba tayo?" calls
    | stock_list; "Ano-ano?" calls nothing and names the products from the rows
    | already in the conversation. Those figures are not guesses, and refusing
    | them is what made the assistant feel like a menu instead of an assistant.
    |
    | This is not a hole in the rule: $priorTools is true only when the
    | transcript the model just read actually carried tool results. With no
    | rows anywhere in the conversation, a figure is still a guess.
    */
    if ($priorTools) {
        return true;
    }

    /*
    | With no tool behind it, ANY figure is a guess. An earlier version only
    | caught three digits or a decimal, which let "your sales today were P50"
    | and "you sold 12 items" through -- entirely plausible fabrications for a
    | sari-sari store. The cost of the stricter rule is that a legitimate
    | figure-free refusal which happens to quote today's date falls back to the
    | Phase 1 answer, which is the safe direction to be wrong in.
    */
    return !preg_match('/\d/', $text);
}

/**
 * Whether a chat result may be shown to the user.
 *
 * Extracted so the fallback rule can be tested instead of eyeballed: every
 * failure -- timeout, api_failed, ungrounded, or an empty answer -- must send
 * the request down to the Phase 1 answer.
 */
function chatResultUsable(array $chat): bool
{
    return !empty($chat['ok']) && trim((string) ($chat['text'] ?? '')) !== '';
}

/**
 * A chat result shaped for the widget.
 *
 * The prose is the answer here, so it travels as 'prose' and is drawn as the
 * card's main text. 'note' stays what it is everywhere else -- the small grey
 * caveat line -- which is the wrong home for the thing the user asked for.
 */
function chatAnswerPayload(array $chat): array
{
    return [
        'title' => '',
        'lines' => [],
        'table' => null,
        'prose' => trim((string) ($chat['text'] ?? '')),
        'tables' => $chat['tables'] ?? [],
    ];
}

/**
 * Run one question to an answer.
 *
 * @param array    $history Prior turns: [['role' => 'user'|'assistant', 'text' => string]]
 * @param callable $model   fn(array $messages, array $toolSchemas): array
 *                          returning ['text' => ?string, 'tool_calls' => array]
 *
 * @return array{ok: bool, reason: ?string, text: ?string, tables: array,
 *               tools_used: string[], rounds: int}
 */
function chatConverse(
    mysqli $conn,
    array $ctx,
    string $question,
    array $history,
    callable $model
): array {
    $tools = chatToolsFor($conn, $ctx);
    $schemas = chatToolSchemas($tools);

    $messages = [];

    /*
    | Whether anything in this conversation was ever backed by a tool. Set from
    | the history the caller hands in, so a follow-up inherits the grounding of
    | the answer it follows.
    */
    $priorTools = false;

    foreach ($history as $turn) {

        $text = (string) $turn['text'];

        if ($turn['role'] === 'assistant' && !empty($turn['tables'])) {
            /* The rows that answer went out with, so "ano-ano?" has something
               to name. They ride on the assistant's own turn because it is the
               assistant that produced them. */
            $text .= "\n\n[rows this answer was built from: "
                . json_encode($turn['tables']) . ']';
            $priorTools = true;
        }

        $messages[] = ['role' => $turn['role'], 'content' => $text];
    }

    $messages[] = ['role' => 'user', 'content' => $question];

    $toolsUsed = [];
    $tables = [];
    $rounds = 0;
    $reply = ['text' => null, 'tool_calls' => []];
    $deadline = microtime(true) + CHAT_DEADLINE_SECONDS;

    while ($rounds < CHAT_MAX_ROUNDS) {

        if (microtime(true) > $deadline) {
            return ['ok' => false, 'reason' => 'timeout', 'text' => null,
                    'tables' => [], 'tools_used' => $toolsUsed, 'rounds' => $rounds];
        }

        $rounds++;

        try {
            $reply = $model($messages, $schemas);
        } catch (Throwable $error) {
            error_log('chat model: ' . $error->getMessage());

            return ['ok' => false, 'reason' => 'api_failed', 'text' => null,
                    'tables' => [], 'tools_used' => $toolsUsed, 'rounds' => $rounds];
        }

        /* A reply that arrived after the budget is spent is not used: the
           caller has been waiting long enough for the Phase 1 answer. */
        if (microtime(true) > $deadline) {
            return ['ok' => false, 'reason' => 'timeout', 'text' => null,
                    'tables' => [], 'tools_used' => $toolsUsed, 'rounds' => $rounds];
        }

        $calls = $reply['tool_calls'] ?? [];

        if (!$calls) {
            $text = trim((string) ($reply['text'] ?? ''));

            if (!chatAnswerIsGrounded($text, $toolsUsed, $priorTools)) {
                return ['ok' => false, 'reason' => 'ungrounded', 'text' => null,
                        'tables' => [], 'tools_used' => $toolsUsed, 'rounds' => $rounds];
            }

            return ['ok' => true, 'reason' => null, 'text' => $text,
                    'tables' => $tables, 'tools_used' => $toolsUsed, 'rounds' => $rounds];
        }

        /* The model's turn goes back in the transcript before its results. */
        $messages[] = ['role' => 'assistant', 'content' => $reply, 'is_tool_use' => true];

        /* Attempts, not successes: a refused call still costs a gate lookup,
           so a reply carrying hundreds of bad calls must be stopped too. */
        $attempts = 0;

        /*
        | Every result of this round goes into ONE user message. Sending them
        | as separate messages is what teaches a model to stop asking for more
        | than one tool at a time -- and "what sold today and what is low?" is
        | two tools or three rounds, depending on this.
        |
        | Every call gets an entry, including the ones that failed: a tool_use
        | the model never sees answered leaves it waiting for a result that
        | never comes.
        */
        $results = [];

        foreach ($calls as $call) {

            $id = (string) ($call['id'] ?? '');

            if (microtime(true) > $deadline) {
                break;
            }

            $attempts++;

            if ($attempts > CHAT_MAX_TOOL_CALLS || count($toolsUsed) >= CHAT_MAX_TOOL_CALLS) {
                $results[] = ['id' => $id, 'is_error' => true,
                              'content' => 'Tool limit reached. Answer with what you have.'];
                break;
            }

            $outcome = chatRunTool($conn, $ctx, (string) ($call['name'] ?? ''),
                (array) ($call['input'] ?? []));

            if (!$outcome['ok']) {
                $results[] = ['id' => $id, 'is_error' => true,
                              'content' => 'Tool error: ' . $outcome['error']];
                continue;
            }

            $toolsUsed[] = (string) $call['name'];

            $table = [
                'tool' => (string) $call['name'],
                'columns' => $outcome['columns'],
                'rows' => $outcome['rows'],
                'truncated' => $outcome['truncated'],
            ];

            $tables[] = $table;

            $results[] = ['id' => $id, 'is_error' => false,
                          'content' => json_encode($table)];
        }

        if ($results !== []) {
            $messages[] = ['role' => 'user', 'content' => ['tool_results' => $results]];
        }
    }

    /* Out of rounds: answer with whatever prose the last reply carried, held to
       the same grounding rule. */
    $text = trim((string) ($reply['text'] ?? ''));

    if (!chatAnswerIsGrounded($text, $toolsUsed, $priorTools)) {
        return ['ok' => false, 'reason' => 'ungrounded', 'text' => null,
                'tables' => [], 'tools_used' => $toolsUsed, 'rounds' => $rounds];
    }

    return ['ok' => true, 'reason' => 'round_limit', 'text' => $text,
            'tables' => $tables, 'tools_used' => $toolsUsed, 'rounds' => $rounds];
}
