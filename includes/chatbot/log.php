<?php
/*
| The assistant's own record: what was asked, and what happened to it.
|
| The question is stored; the answer never is. An answer can hold salaries and
| takings, and a second copy of those is a second thing to protect. The history
| view re-runs the intent instead, which keeps figures current and re-applies
| every access check.
|
| no_match rows are the record of what staff expected the assistant to know,
| and are what decides which question is worth adding next.
*/

const CHATBOT_QUESTION_MAX = 500;
const CHATBOT_RATE_LIMIT = 20;

/**
 * A question cut to the length that will be stored.
 *
 * Applied before the question is answered, not only before it is logged: an
 * answer quotes the product name back ("Wala akong nakitang produkto..."), so
 * an unbounded question came back unbounded.
 */
function chatbotBoundQuestion(string $question): string
{
    return mb_substr(trim($question), 0, CHATBOT_QUESTION_MAX);
}

function chatbotLog(
    mysqli $conn,
    array $ctx,
    string $question,
    ?string $intentId,
    ?string $matchedBy,
    string $outcome
): void {
    $question = mb_substr(trim($question), 0, CHATBOT_QUESTION_MAX);

    $stmt = $conn->prepare("
        INSERT INTO chatbot_messages
            (company_id, user_id, role, question, intent_id, matched_by, outcome)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param(
        "iisssss",
        $ctx['company_id'],
        $ctx['user_id'],
        $ctx['role'],
        $question,
        $intentId,
        $matchedBy,
        $outcome
    );
    $stmt->execute();
    $stmt->close();
}

/**
 * A conversational question: the question, and the NAMES of the tools that
 * answered it. Never the tool results, never the model's answer.
 */
function chatbotLogChat(mysqli $conn, array $ctx, string $question, array $toolsUsed): void
{
    $question = chatbotBoundQuestion($question);
    $tools = mb_substr(implode(',', $toolsUsed), 0, 255);

    $stmt = $conn->prepare("
        INSERT INTO chatbot_messages
            (company_id, user_id, role, question, intent_id, matched_by, tools_used, outcome)
        VALUES (?, ?, ?, ?, NULL, 'ai_chat', ?, 'answered')
    ");
    $stmt->bind_param(
        "iisss",
        $ctx['company_id'],
        $ctx['user_id'],
        $ctx['role'],
        $question,
        $tools
    );
    $stmt->execute();
    $stmt->close();
}

/**
 * A conversational question that did NOT produce an answer.
 *
 * It still spent one to three real API calls -- a timeout, an API error, or an
 * answer discarded for stating figures no tool backed. Without this row the
 * daily budget counted only successes, so a model that kept failing spent
 * without limit. The Phase 1 answer that follows logs its own row; two rows
 * for one question is the honest record: one attempt, one answer.
 */
function chatbotLogChatAttempt(mysqli $conn, array $ctx, string $question): void
{
    $question = chatbotBoundQuestion($question);

    $stmt = $conn->prepare("
        INSERT INTO chatbot_messages
            (company_id, user_id, role, question, intent_id, matched_by, tools_used, outcome)
        VALUES (?, ?, ?, ?, NULL, 'ai_chat', NULL, 'no_match')
    ");
    $stmt->bind_param(
        "iiss",
        $ctx['company_id'],
        $ctx['user_id'],
        $ctx['role'],
        $question
    );
    $stmt->execute();
    $stmt->close();
}

/**
 * Twenty questions a minute is far more than a person types, and far less than
 * a script needs to pull a database out one answer at a time.
 */
function chatbotRateLimited(mysqli $conn, array $ctx): bool
{
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS asked
        FROM chatbot_messages
        WHERE company_id = ? AND user_id = ?
          AND created_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)
    ");
    $stmt->bind_param("ii", $ctx['company_id'], $ctx['user_id']);
    $stmt->execute();
    $asked = (int) $stmt->get_result()->fetch_assoc()['asked'];
    $stmt->close();

    return $asked >= CHATBOT_RATE_LIMIT;
}
