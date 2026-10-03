<?php
/*
|--------------------------------------------------------------------------
| UNDERSTANDING A QUESTION
|--------------------------------------------------------------------------
|
| Keyword matching. The AI path is added on top of this file later, and this
| matcher stays as the fallback for when the API is unreachable -- which is
| why it is built and proven first.
*/

function chatbotNormalise(string $question): string
{
    $text = mb_strtolower(trim($question), 'UTF-8');
    $text = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text) ?? '';
    $text = preg_replace('/\s+/u', ' ', $text) ?? '';

    return trim($text);
}

/**
 * The id of the best-matching intent, or null when nothing matches well.
 *
 * Every keyword group must be hit for an intent to qualify, so "sales this
 * month" cannot fall through to the "today" intent. Among the qualifying
 * intents the highest number of hits wins; a tie goes to the intent with more
 * groups, which is the more specific one.
 */
function chatbotKeywordMatch(string $question, array $intents): ?string
{
    $best = chatbotKeywordBest($question, $intents);

    return $best === null ? null : $best['id'];
}

/**
 * The best match together with its score, which the engine needs to tell two
 * situations apart.
 *
 * "magkano ang benta ngayong araw" asked by a cashier matches the cashier's
 * own product-price intent on the word "magkano" alone, while matching the
 * company sales intent -- which the cashier may not ask -- far more strongly.
 * Answering the weak match turns a refusal into a confusing product search,
 * so the engine compares the two scores and refuses instead.
 *
 * @return array{id: string, score: int, groups: int}|null
 */
function chatbotKeywordBest(string $question, array $intents): ?array
{
    $normalised = chatbotNormalise($question);

    if ($normalised === '') {
        return null;
    }

    $haystack = ' ' . $normalised . ' ';

    $bestId = null;
    $bestScore = 0;
    $bestGroups = 0;

    foreach ($intents as $id => $intent) {

        $score = 0;
        $qualifies = true;

        foreach ($intent['keywords'] as $group) {

            $hits = 0;

            foreach ($group as $synonym) {
                if (str_contains($haystack, ' ' . $synonym . ' ')) {
                    $hits++;
                }
            }

            if ($hits === 0) {
                $qualifies = false;
                break;
            }

            $score += $hits;
        }

        if (!$qualifies) {
            continue;
        }

        $groups = count($intent['keywords']);

        if ($score > $bestScore || ($score === $bestScore && $groups > $bestGroups)) {
            $bestId = $id;
            $bestScore = $score;
            $bestGroups = $groups;
        }
    }

    return $bestId === null
        ? null
        : ['id' => $bestId, 'score' => $bestScore, 'groups' => $bestGroups];
}

/**
 * Whether the question is about the asker themselves.
 *
 * "Benta ko ngayong araw" and "how much have I sold" name a person, not the
 * shop. The engine uses this to give an own-scope intent precedence over a
 * company-wide one that happens to share the rest of the sentence.
 */
function chatbotMentionsSelf(string $question): bool
{
    $haystack = ' ' . chatbotNormalise($question) . ' ';

    foreach (['ko', 'ako', 'akin', 'my', 'i', 'mine', 'sarili'] as $marker) {
        if (str_contains($haystack, ' ' . $marker . ' ')) {
            return true;
        }
    }

    return false;
}

/**
 * Whether the question is about one named person, or about the asker.
 *
 * "magkano ang sweldo ni Ana" and "what is my salary" ask about an individual;
 * "magkano ang kabuuang sweldo ngayong buwan" asks about the payroll run. The
 * difference is the person marker, not the language, and it decides whether a
 * pay question is refused or answered.
 */
function chatbotMentionsIndividual(string $question): bool
{
    $haystack = ' ' . chatbotNormalise($question) . ' ';

    foreach ([' ni ', ' kay ', ' ko ', ' ako ', ' akin ', ' my ', ' mine ', ' i '] as $marker) {
        if (str_contains($haystack, $marker)) {
            return true;
        }
    }

    /* "the salary of Ana Cruz" -- of followed by something that is not a period
       word is a person, not a month. */
    if (preg_match('/\bof\s+(\w+)/i', $question, $found)) {
        $periodWords = ['this', 'last', 'the', 'these', 'those', 'our', 'all',
                        'january', 'february', 'march', 'april', 'may', 'june',
                        'july', 'august', 'september', 'october', 'november', 'december'];

        if (!in_array(mb_strtolower($found[1]), $periodWords, true)) {
            return true;
        }
    }

    return false;
}

/**
 * What is left of a question once its own keywords and filler words are
 * removed -- the product the user is asking about.
 *
 * Returned as a plain string for binding into a LIKE parameter. It is never
 * concatenated into SQL.
 */
function chatbotExtractProductName(string $question, array $intent): string
{
    $haystack = ' ' . chatbotNormalise($question) . ' ';

    foreach ($intent['keywords'] as $group) {
        foreach ($group as $synonym) {
            $haystack = str_replace(' ' . $synonym . ' ', ' ', $haystack);
        }
    }

    $fillerWords = ['ang', 'ng', 'na', 'ba', 'po', 'yung', 'iyong', 'the', 'a', 'an',
               'of', 'is', 'are', 'do', 'we', 'i', 'for', 'this', 'that', 'it',
               'what', 'ano', 'sa', 'may', 'meron', 'mo', 'ko', 'pa', 'stock',
               'produkto', 'product', 'presyo', 'price'];

    foreach ($fillerWords as $word) {
        /* Repeat until settled: "the price of the X" leaves adjacent fillers
           that a single pass would step over. */
        while (str_contains($haystack, ' ' . $word . ' ')) {
            $haystack = str_replace(' ' . $word . ' ', ' ', $haystack);
        }
    }

    return trim(preg_replace('/\s+/u', ' ', $haystack) ?? '');
}

/**
 * Map a question to an intent, preferring the AI and falling back to keywords.
 *
 * The AI is a language layer only. Whatever it returns is checked against
 * $allowedIntents -- the list already filtered by role and plan -- so the worst
 * a wrong or manipulated reply can do is pick a different question this same
 * user was already permitted to ask. It can never widen access, cross a company
 * boundary, or reach a gated topic.
 *
 * $ai is injected rather than called directly so the tests can drive every
 * failure path without a network.
 *
 * @return array{intent: ?string, matched_by: string}
 */
function chatbotUnderstand(string $question, array $allowedIntents, ?callable $ai = null): array
{
    if ($ai !== null) {
        try {
            $candidate = $ai($question, $allowedIntents);

            if (is_string($candidate) && isset($allowedIntents[$candidate])) {
                return ['intent' => $candidate, 'matched_by' => 'ai'];
            }

            /* An id outside the list, prose, or null: fall through to keywords
               rather than trusting it. */
        } catch (Throwable $error) {
            error_log('chatbot ai: ' . $error->getMessage());
        }
    }

    return [
        'intent' => chatbotKeywordMatch($question, $allowedIntents),
        'matched_by' => 'keyword',
    ];
}
