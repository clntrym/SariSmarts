<?php
/*
|--------------------------------------------------------------------------
| THE TOOL RUNNER
|--------------------------------------------------------------------------
|
| Decides which tools exist for this user, describes them to the model, and
| validates and runs them. The model never sees a tool the user may not use,
| and every call is checked again here before it runs -- being told about a
| tool is not permission to call it.
*/

/* Required explicitly rather than relying on the caller having loaded them:
   chatbotPlanAllowsTopic() is the plan gate, chatbotNormalise() is what keeps
   % and _ out of a LIKE parameter. */
require_once __DIR__ . '/../engine.php';
require_once __DIR__ . '/tools.php';
require_once __DIR__ . '/tools/sales.php';
require_once __DIR__ . '/tools/inventory.php';
require_once __DIR__ . '/tools/people.php';
require_once __DIR__ . '/tools/attendance.php';
require_once __DIR__ . '/tools/personal.php';
require_once __DIR__ . '/tools/leave.php';
require_once __DIR__ . '/tools/recruitment.php';
require_once __DIR__ . '/tools/store.php';
require_once __DIR__ . '/tools/finance.php';

const CHAT_TOOL_ROW_CAP = 50;

/**
 * A free-text name or product turned into a safe LIKE term.
 *
 * Normalising it away was wrong twice over: "???" became an empty string and
 * matched every row, while "O'Brien" and "Dela Cruz-Santos" lost the very
 * characters that identify them and matched nothing. Here the text is kept as
 * typed and only the LIKE wildcards are escaped.
 *
 * Returns null when there is nothing left to search for, and the caller then
 * returns no rows rather than all of them.
 */
function chatLikeTerm(string $text): ?string
{
    $text = trim($text);

    if ($text === '') {
        return null;
    }

    $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $text);

    /* A term of nothing but wildcards and spaces is not a search. */
    if (trim(str_replace(['\\%', '\\_'], '', $escaped)) === '') {
        return null;
    }

    return '%' . $escaped . '%';
}

/**
 * The catalog reduced to what this role, on this plan, may use.
 */
function chatToolsFor(mysqli $conn, array $ctx): array
{
    $role = strtolower(trim((string) $ctx['role']));
    $allowed = [];

    foreach (chatTools() as $name => $tool) {

        if (!in_array($role, $tool['roles'], true)) {
            continue;
        }

        if (!chatbotPlanAllowsTopic($conn, (int) $ctx['company_id'], $tool['topic'])) {
            continue;
        }

        $allowed[$name] = $tool;
    }

    return $allowed;
}

/**
 * The tool list as the model is given it.
 */
function chatToolSchemas(array $tools): array
{
    $schemas = [];

    foreach ($tools as $name => $tool) {

        $properties = [];
        $required = [];

        foreach ($tool['input'] as $param => $spec) {

            $property = match ($spec['type']) {
                'enum' => ['type' => 'string', 'enum' => $spec['values']],
                'date' => ['type' => 'string', 'description' => 'Date as YYYY-MM-DD'],
                'int' => ['type' => 'integer'],
                default => ['type' => 'string'],
            };

            $properties[$param] = $property;

            if (!empty($spec['required'])) {
                $required[] = $param;
            }
        }

        $schemas[] = [
            'name' => $name,
            'description' => $tool['description'],
            'input_schema' => [
                'type' => 'object',
                /*
                | A tool with no inputs has an empty properties array, and an
                | empty PHP array serialises to [] rather than {}. The API
                | rejects the entire request over it -- so one input-less tool
                | in the list kills every question the role can ask. Casting
                | the empty case is the whole fix.
                */
                'properties' => $properties === [] ? new stdClass() : $properties,
                /* 'required' is a list, so it stays an array when empty. */
                'required' => $required,
            ],
        ];
    }

    return $schemas;
}

/**
 * Run one tool call from the model.
 *
 * Being told about a tool is not permission to call it: the gate is checked
 * again here, because the model's request is just text and text can name
 * anything. A refusal returns a message for the model, never a PHP error and
 * never a figure.
 *
 * @return array{ok: bool, error: ?string, columns: string[], rows: array, truncated: bool}
 */
function chatRunTool(mysqli $conn, array $ctx, string $name, array $input): array
{
    $refusal = static fn (string $message): array => [
        'ok' => false, 'error' => $message,
        'columns' => [], 'rows' => [], 'truncated' => false,
    ];

    $available = chatToolsFor($conn, $ctx);

    if (!isset($available[$name])) {
        return $refusal("There is no tool called {$name} available to you.");
    }

    $tool = $available[$name];

    /*
    | A question about the asker's own records needs a record to point at. An
    | Owner/Admin account has no employees row, so binding a null employee id
    | would quietly answer "no attendance" to someone whose real answer is
    | "your account is not an employee".
    */
    if ($tool['scope'] === 'own' && empty($ctx['employee_id'])) {
        return $refusal('This account has no employee record, so there is nothing personal to show.');
    }
    $checked = chatValidateInput($tool, $input);

    if (!$checked['ok']) {
        return $refusal((string) $checked['error']);
    }

    try {
        $result = ($tool['handler'])($conn, $ctx, $checked['values']);
    } catch (Throwable $error) {
        error_log('chat tool ' . $name . ': ' . $error->getMessage());

        return $refusal('That lookup failed. Try a different question.');
    }

    /*
    | Truncation is judged against what was asked for, not against the cap.
    | Handlers fetch one row beyond the limit so more-rows-exist is detectable;
    | that sentinel row is dropped here and never reaches the model, which
    | would otherwise receive one more row than it requested.
    */
    $rows = $result['rows'];
    $wanted = min((int) ($checked['values']['limit'] ?? CHAT_TOOL_ROW_CAP), CHAT_TOOL_ROW_CAP);
    $truncated = count($rows) > $wanted;

    return [
        'ok' => true,
        'error' => null,
        'columns' => $result['columns'],
        'rows' => $truncated ? array_slice($rows, 0, $wanted) : $rows,
        'truncated' => $truncated,
    ];
}

/**
 * Check what the model asked for before any of it reaches a query.
 *
 * The model is not a trusted caller: it can invent a parameter, a date format
 * or a limit. Everything is checked against the tool's own declaration, and
 * anything not declared is dropped rather than passed along.
 *
 * @return array{ok: bool, error: ?string, values: array}
 */
function chatValidateInput(array $tool, array $input): array
{
    $values = [];

    foreach ($tool['input'] as $param => $spec) {

        $given = $input[$param] ?? null;
        $missing = $given === null || $given === '';

        if ($missing) {
            if (!empty($spec['required'])) {
                return ['ok' => false, 'error' => "Missing required parameter: {$param}.",
                        'values' => []];
            }

            continue;
        }

        switch ($spec['type']) {

            case 'enum':
                if (!in_array($given, $spec['values'], true)) {
                    return ['ok' => false,
                            'error' => "Invalid {$param}. Allowed: " . implode(', ', $spec['values']) . '.',
                            'values' => []];
                }
                $values[$param] = $given;
                break;

            case 'date':
                $date = DateTime::createFromFormat('Y-m-d', (string) $given);

                if (!$date || $date->format('Y-m-d') !== (string) $given) {
                    return ['ok' => false, 'error' => "Invalid {$param}. Use YYYY-MM-DD.",
                            'values' => []];
                }
                $values[$param] = (string) $given;
                break;

            case 'int':
                if (!is_numeric($given)) {
                    return ['ok' => false, 'error' => "Invalid {$param}. Expected a number.",
                            'values' => []];
                }

                $number = (int) $given;
                $min = $spec['min'] ?? 1;
                $max = $spec['max'] ?? CHAT_TOOL_ROW_CAP;

                if ($number < $min || $number > $max) {
                    return ['ok' => false, 'error' => "Invalid {$param}. Use {$min} to {$max}.",
                            'values' => []];
                }
                $values[$param] = $number;
                break;

            default:
                $text = trim((string) $given);
                $max = $spec['max'] ?? 200;

                if ($text === '' || mb_strlen($text) > $max) {
                    return ['ok' => false, 'error' => "Invalid {$param}. Give 1 to {$max} characters.",
                            'values' => []];
                }
                $values[$param] = $text;
                break;
        }
    }

    /* A custom period is meaningless without both ends, and a backwards range
       is a mistake worth naming rather than quietly returning nothing. */
    if (($values['period'] ?? null) === 'custom') {

        if (empty($values['from']) || empty($values['to'])) {
            return ['ok' => false,
                    'error' => 'A custom period needs both from and to as YYYY-MM-DD.',
                    'values' => []];
        }

        if ($values['from'] > $values['to']) {
            return ['ok' => false, 'error' => 'The from date is later than the to date.',
                    'values' => []];
        }
    }

    return ['ok' => true, 'error' => null, 'values' => $values];
}

/**
 * A period name turned into two dates, so every tool filters the same way.
 *
 * @return array{from: string, to: string}
 */
function chatResolvePeriod(string $period, ?string $from, ?string $to): array
{
    $today = date('Y-m-d');

    return match ($period) {
        'today' => ['from' => $today, 'to' => $today],
        'yesterday' => ['from' => date('Y-m-d', strtotime('-1 day')),
                        'to' => date('Y-m-d', strtotime('-1 day'))],
        'this_week' => ['from' => date('Y-m-d', strtotime('monday this week')), 'to' => $today],
        'last_week' => ['from' => date('Y-m-d', strtotime('monday last week')),
                        'to' => date('Y-m-d', strtotime('sunday last week'))],
        'this_month' => ['from' => date('Y-m-01'), 'to' => $today],
        'last_month' => ['from' => date('Y-m-01', strtotime('first day of last month')),
                         'to' => date('Y-m-t', strtotime('last day of last month'))],
        default => ['from' => (string) $from, 'to' => (string) $to],
    };
}
