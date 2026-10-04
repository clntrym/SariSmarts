<?php
/*
|--------------------------------------------------------------------------
| THE RESERVE PROVIDER
|--------------------------------------------------------------------------
|
| Google Gemini, used when Claude cannot answer.
|
| Why there is one: when the Anthropic credit runs out, every request comes
| back 400 and every question falls through to the keyword answers. That is
| safe, and it is also the end of the feature -- quietly, because the fallback
| is built to be quiet. A second provider means the assistant keeps working
| while somebody tops the account up.
|
| Why the translation lives here: the loop in conversation.php drives a
| callable and knows nothing about either provider. It must stay that way. So
| this file speaks both dialects and the loop speaks neither.
|
| The two dialects differ in every part that matters:
|
|     Claude                      Gemini
|     ------------------------    ------------------------------
|     tools[].input_schema        tools[].functionDeclarations[].parameters
|     "type": "string"            "type": "STRING"       (upper case)
|     role "assistant"            role "model"
|     tool_use block              functionCall part
|     tool_result block           functionResponse part, matched by NAME
|     system: "..."               systemInstruction: {parts:[{text}]}
|
| The last one is a trap worth naming: Claude pairs a result to a call by id,
| Gemini pairs it by the function's name. The loop works in ids, so a call
| coming back from Gemini is given one.
*/

/*
| gemini-2.0-flash was written here and is retired: the API answers 404 with
| "no longer available", naming gemini-3.8-flash as the current one. Checked
| against the live API rather than assumed, which is the only way a model id
| ever gets checked -- a wrong one is a 404 that this layer turns into a
| silent fallback, exactly as the date-suffixed Claude id did.
|
| Overridable with GEMINI_MODEL so the next retirement is an environment
| variable rather than a deployment.
*/
const GEMINI_MODEL = 'gemini-3.8-flash';
const GEMINI_ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models';
const GEMINI_TIMEOUT_MS = 15000;

/**
 * Whether a Claude failure is one the reserve should be asked to cover.
 *
 * Not every failure is. A safety refusal means the question was declined on
 * its merits, and asking a second model the same question to get a different
 * answer is working around a decision rather than around an outage. A 401
 * means the key is wrong, which is a fault somebody has to see -- answering
 * from elsewhere would hide it until the bill arrived.
 *
 * What does qualify: no credit, rate limiting, overload, and the server
 * errors. Those are "Claude cannot answer right now", which is what a reserve
 * is for.
 */
function geminiShouldTakeOver(string $failure): bool
{
    $failure = strtolower($failure);

    /* Named outright, because this is the case the reserve exists for and it
       should not depend on reading a status code correctly. */
    if (str_contains($failure, 'credit balance')
        || str_contains($failure, 'insufficient')
        || str_contains($failure, 'quota')) {
        return true;
    }

    if (str_contains($failure, 'refused')) {
        return false;
    }

    if (!preg_match('/status (\d+)/', $failure, $m)) {
        /* A connection that never arrived -- no response, no status. The
           reserve may be reachable when Anthropic is not. */
        return str_contains($failure, 'api_failed') || str_contains($failure, 'timeout');
    }

    $status = (int) $m[1];

    /*
    | Status 0 is curl's way of saying no HTTP response came back at all: DNS
    | failed, the connection was refused, the request timed out. Nothing was
    | wrong with the question, so the reserve is worth asking.
    */
    if ($status === 0) {
        return true;
    }

    /* 401 and 403 are configuration; 404 is a wrong model id. Each is a fault
       to fix, not an outage to route around. */
    if (in_array($status, [401, 403, 404], true)) {
        return false;
    }

    return $status === 400 || $status === 429 || $status >= 500;
}

/**
 * Claude's tool list, as Gemini's function declarations.
 */
function geminiToolSchemas(array $tools): array
{
    $declarations = [];

    foreach ($tools as $tool) {

        $declaration = [
            'name' => (string) ($tool['name'] ?? ''),
            'description' => (string) ($tool['description'] ?? ''),
        ];

        $schema = (array) ($tool['input_schema'] ?? []);
        $properties = (array) ($schema['properties'] ?? []);

        /*
        | A declaration whose parameters have no properties is rejected, so a
        | tool with no inputs declares none at all. company_profile and
        | branch_list are both in that position.
        */
        if ($properties !== []) {

            $converted = [];

            foreach ($properties as $name => $spec) {
                $converted[$name] = geminiProperty((array) $spec);
            }

            $declaration['parameters'] = [
                'type' => 'OBJECT',
                'properties' => $converted,
                'required' => array_values((array) ($schema['required'] ?? [])),
            ];
        }

        $declarations[] = $declaration;
    }

    /* One block holding every declaration, which is the shape the API wants --
       not one block per tool. */
    return [['functionDeclarations' => $declarations]];
}

/**
 * One property of a tool's input schema, in Gemini's vocabulary.
 */
function geminiProperty(array $spec): array
{
    $types = [
        'string' => 'STRING',
        'integer' => 'INTEGER',
        'number' => 'NUMBER',
        'boolean' => 'BOOLEAN',
        'array' => 'ARRAY',
        'object' => 'OBJECT',
    ];

    $type = strtolower((string) ($spec['type'] ?? 'string'));

    $property = ['type' => $types[$type] ?? 'STRING'];

    if (!empty($spec['description'])) {
        $property['description'] = (string) $spec['description'];
    }

    /* An enum is how a tool says "one of these and nothing else". Losing it
       would let the model invent a period name the runner then rejects. */
    if (!empty($spec['enum'])) {
        $property['enum'] = array_values((array) $spec['enum']);
    }

    return $property;
}

/**
 * The loop's transcript, as Gemini contents.
 */
function geminiContents(array $messages): array
{
    $contents = [];

    foreach ($messages as $message) {

        $content = $message['content'];

        if (!is_array($content)) {
            $contents[] = [
                'role' => $message['role'] === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => (string) $content]],
            ];
            continue;
        }

        if (isset($content['tool_results'])) {

            $parts = [];

            foreach ($content['tool_results'] as $result) {
                $parts[] = [
                    'functionResponse' => [
                        /* By name, not by id: that is how Gemini pairs a
                           response to the call it answers. */
                        'name' => (string) ($result['name'] ?? ''),
                        'response' => ['result' => (string) ($result['content'] ?? '')],
                    ],
                ];
            }

            if ($parts !== []) {
                $contents[] = ['role' => 'user', 'parts' => $parts];
            }

            continue;
        }

        $parts = [];
        $text = trim((string) ($content['text'] ?? ''));

        if ($text !== '') {
            $parts[] = ['text' => $text];
        }

        foreach (($content['tool_calls'] ?? []) as $call) {

            $args = (array) ($call['input'] ?? []);

            $parts[] = [
                'functionCall' => [
                    'name' => (string) ($call['name'] ?? ''),
                    /* A string-keyed array already serialises as an object;
                       an EMPTY one would serialise as [], which is not an
                       argument object. Only that case is cast. */
                    'args' => $args === [] ? new stdClass() : $args,
                ],
            ];
        }

        if ($parts === []) {
            continue;
        }

        $contents[] = [
            'role' => $message['role'] === 'assistant' ? 'model' : 'user',
            'parts' => $parts,
        ];
    }

    return $contents;
}

/**
 * Gemini's reply, in the shape the loop already understands.
 */
function geminiNormaliseReply(array $body): array
{
    $text = null;
    $toolCalls = [];

    $parts = $body['candidates'][0]['content']['parts'] ?? [];

    foreach ((array) $parts as $index => $part) {

        if (isset($part['text']) && trim((string) $part['text']) !== '') {
            $text = trim((string) $part['text']);
        }

        if (isset($part['functionCall'])) {
            $toolCalls[] = [
                /* The loop pairs results to calls by id and Gemini sends
                   none, so one is made. It only has to be unique within
                   this turn. */
                'id' => 'gem_' . $index . '_' . (string) ($part['functionCall']['name'] ?? ''),
                'name' => (string) ($part['functionCall']['name'] ?? ''),
                'input' => (array) ($part['functionCall']['args'] ?? []),
            ];
        }
    }

    return [
        'text' => $text,
        'tool_calls' => $toolCalls,
        'stop_reason' => (string) ($body['candidates'][0]['finishReason'] ?? ''),
    ];
}

/**
 * The request body for a Gemini turn.
 */
function geminiRequestPayload(array $messages, array $toolSchemas, string $system): array
{
    $payload = [
        'contents' => geminiContents($messages),
        'systemInstruction' => ['parts' => [['text' => $system]]],
        'generationConfig' => [
            'temperature' => 0.2,
            'maxOutputTokens' => 2048,
        ],
    ];

    if ($toolSchemas !== []) {
        $payload['tools'] = $toolSchemas;
    }

    return $payload;
}
