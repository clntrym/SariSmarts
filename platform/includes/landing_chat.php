<?php

/*
|--------------------------------------------------------------------------
| THE INQUIRY ASSISTANT ON THE PUBLIC PAGES
|--------------------------------------------------------------------------
|
| A visitor who has not signed up asks what RetailCore costs, what a plan
| includes, how to register. It answers, and when they show real interest
| it asks for their details and files them in marketing_leads.
|
| WHAT IT IS ALLOWED TO TALK ABOUT
|
| RetailCore, and nothing else. The system prompt says so and the plan data
| is handed to it as fact, so it answers from what is actually in
| subscription_plans rather than from whatever it remembers about retail
| software. A price it invented would be a price we then have to honour.
|
| WHERE THE KEY IS
|
| C:\xampp\sarismart_secrets.php, outside the webroot, the same file the
| RetailCore chatbot already reads. It is never sent to the browser and
| never written to the database.
|
| WHAT STOPS IT COSTING MONEY
|
| This endpoint is public: no sign-in, no rate limit from a session. So the
| limits are counted in the database and checked before every call -- per
| conversation, per IP per hour, and a cap for the whole day. Past any of
| them it falls back to the scripted answers, which cost nothing.
|
| WHEN THE AI IS OFF
|
| Everything still works. landingChatScripted() answers the common
| questions from the same plan data, and the lead capture is untouched. The
| assistant is less fluent and still useful.
|
*/

require_once __DIR__ . '/../init.php';

/* Verified against the API: both this and the dated id answer. The
   unsuffixed form is what the RetailCore client already uses. */
if (!defined('LANDING_CHAT_MODEL')) {
    define('LANDING_CHAT_MODEL', 'claude-haiku-4-5');
}

if (!defined('LANDING_CHAT_ENDPOINT')) {
    define('LANDING_CHAT_ENDPOINT', 'https://api.anthropic.com/v1/messages');
}

/*
| Outside the webroot. A key under htdocs is one misconfiguration away from
| being downloadable.
|
| Two names, for the same reason ai_client.php carries two: the product was
| renamed and the file on disk was not. Picking whichever exists means the
| rename can happen whenever it suits, and this chat does not quietly stop
| answering on the day it does.
*/
if (!defined('LANDING_CHAT_SECRETS')) {
    define(
        'LANDING_CHAT_SECRETS',
        is_readable('C:\\xampp\\retailcore_secrets.php')
            ? 'C:\\xampp\\retailcore_secrets.php'
            : 'C:\\xampp\\sarismart_secrets.php'
    );
}

/* A visitor asking in good faith does not need more than this. */
if (!defined('LANDING_CHAT_MAX_INPUT')) {
    define('LANDING_CHAT_MAX_INPUT', 500);
}

if (!defined('LANDING_CHAT_MAX_PER_CHAT')) {
    define('LANDING_CHAT_MAX_PER_CHAT', 30);
}

if (!defined('LANDING_CHAT_MAX_PER_IP_HOUR')) {
    define('LANDING_CHAT_MAX_PER_IP_HOUR', 40);
}

if (!defined('LANDING_CHAT_DAILY_CAP')) {
    define('LANDING_CHAT_DAILY_CAP', 300);
}


if (!function_exists('landingChatSettings')) {

    /*
    | The secrets file, read once per request.
    |
    | Overridable from PHP only, never from a request, so the offline and
    | failure paths can be exercised without a real key.
    */
    function landingChatSettings(): array
    {
        static $cache = null;

        if ($cache !== null) {
            return $cache;
        }

        $path = (string) ($GLOBALS['landing_chat_secrets_path'] ?? LANDING_CHAT_SECRETS);
        $file = is_readable($path) ? (array) require $path : [];

        /*
        | The environment as well as the file, and for the same reason the
        | tenant assistant now reads both: LANDING_CHAT_SECRETS names a path
        | under C:\xampp, the deployed site runs Linux, and so this chat has
        | never had a key in production. The bubble drew, the question posted,
        | and the answer came back from the scripted fallback every time.
        |
        | The file wins where both speak; a setting it omits is filled from
        | the environment.
        */
        /* Either layout: platform inside the main folder, or beside it. */
        $shared = is_file(__DIR__ . '/../../includes/settings_env.php')
            ? __DIR__ . '/../../includes/settings_env.php'
            : __DIR__ . '/../../SariSmarts/includes/settings_env.php';

        if (is_readable($shared)) {
            require_once $shared;
        }

        $environment = function_exists('chatbotSettingsFromEnvironment')
            ? chatbotSettingsFromEnvironment()
            : [];

        return $cache = $file + $environment;
    }
}


if (!function_exists('landingChatAiReady')) {

    /* Whether the AI path may be used at all. */
    function landingChatAiReady(): bool
    {
        $settings = landingChatSettings();

        return !empty($settings['anthropic_api_key'])
            && ($settings['landing_chat_enabled'] ?? true);
    }
}


if (!function_exists('landingChatPlans')) {

    /*
    | The plans, as facts for the model.
    |
    | Read from the same table the pricing page reads, so the assistant
    | cannot quote a price the site does not show. Only Active plans: a plan
    | nobody can buy is not an answer.
    */
    function landingChatPlans(mysqli $conn): array
    {
        $plans = [];

        $result = $conn->query("
            SELECT plan_id, plan_name, tagline, monthly_price, yearly_price,
                   price_label, max_branches, max_users
            FROM subscription_plans
            WHERE status = 'Active'
            ORDER BY plan_order, plan_id
        ");

        if (!$result) {
            return $plans;
        }

        while ($row = $result->fetch_assoc()) {

            $features = [];

            $stmt = $conn->prepare("
                SELECT feature_name FROM subscription_plan_features
                WHERE plan_id = ? ORDER BY feature_order
            ");
            $stmt->bind_param("i", $row['plan_id']);
            $stmt->execute();
            $featureRows = $stmt->get_result();

            while ($feature = $featureRows->fetch_assoc()) {
                $features[] = $feature['feature_name'];
            }

            $stmt->close();

            $row['features'] = $features;
            $plans[] = $row;
        }

        return $plans;
    }
}


if (!function_exists('landingChatSystemPrompt')) {

    /*
    | What the model is, what it knows, and what it must not do.
    |
    | The plan data is written in rather than summarised, because the one
    | thing that must never happen is a quoted price nobody sells.
    */
    function landingChatSystemPrompt(mysqli $conn): string
    {
        $lines = [];

        foreach (landingChatPlans($conn) as $plan) {

            $price = (float) $plan['monthly_price'] > 0
                ? 'PHP ' . number_format((float) $plan['monthly_price'], 2) . ' per month'
                    . ((float) $plan['yearly_price'] > 0
                        ? ', or PHP ' . number_format((float) $plan['yearly_price'], 2) . ' per year'
                        : '')
                : ($plan['price_label'] ?: 'quoted on request, not a fixed price');

            $branches = (int) $plan['max_branches'] >= 9999
                ? 'unlimited branches'
                : (int) $plan['max_branches'] . ' branch' . ((int) $plan['max_branches'] === 1 ? '' : 'es');

            $users = (int) $plan['max_users'] >= 9999
                ? 'unlimited users'
                : 'up to ' . (int) $plan['max_users'] . ' users';

            $lines[] = '- ' . $plan['plan_name'] . ': ' . $price . '. '
                . $branches . ', ' . $users . '. '
                . ($plan['tagline'] ? $plan['tagline'] . '. ' : '')
                . 'Includes: ' . implode(', ', $plan['features']) . '.';
        }

        $planText = $lines === []
            ? 'No plans are published right now. Offer to have the team get in touch.'
            : implode("\n", $lines);

        return <<<PROMPT
You are the inquiry assistant on the public website of RetailCore, a retail
management system for Philippine businesses - sari-sari stores, convenience
stores and small retail chains.

You are talking to a visitor who has not signed up. Your job is to answer
questions about RetailCore and, when someone is genuinely interested, invite
them to leave their details so the team can follow up.

THE PLANS, WHICH ARE THE ONLY PRICES YOU MAY QUOTE:

{$planText}

HOW TO SIGN UP: choose a plan on the pricing page, fill in the registration
form with the business details and the DTI and BIR certificates, wait for the
team to review it, sign the service agreement, then pay. The system opens once
payment is settled.

RULES

- Only discuss RetailCore: its plans, prices, features, and how to sign up.
  If asked about anything else, say that you can only help with RetailCore
  and offer to pass the question to the team.
- Never invent a price, a discount, a feature or a date. If it is not in the
  plan list above, say you are not sure and offer to have someone confirm.
- Never promise anything about a specific business's application. You cannot
  see accounts and should say so.
- Keep replies short - two or three sentences. This is a chat bubble, not a
  brochure.
- Write plain text only. No markdown: no asterisks for bold, no hashes, no
  bullet characters. The bubble shows exactly the characters you send, so
  **like this** appears with the asterisks still on it.
- Write plainly, in the language the visitor used. Many will write Taglish;
  match them.
- When someone asks about pricing for their own business, asks for a demo, or
  says they want to sign up, invite them to leave their name, business name
  and contact so the team can reach them.
- Never ask for a password, a card number, or any government ID number.
PROMPT;
    }
}


if (!function_exists('landingChatUsageToday')) {

    /* How many AI replies have been paid for today, across everybody. */
    function landingChatUsageToday(mysqli $conn): int
    {
        $row = $conn->query("
            SELECT COUNT(*) AS n
            FROM landing_chat_messages
            WHERE role = 'assistant'
              AND ai_model IS NOT NULL
              AND created_at >= CURDATE()
        ");

        return $row ? (int) ($row->fetch_assoc()['n'] ?? 0) : 0;
    }
}


if (!function_exists('landingChatIpUsage')) {

    /* Messages from one address in the last hour. */
    function landingChatIpUsage(mysqli $conn, string $ip): int
    {
        $stmt = $conn->prepare("
            SELECT COUNT(*) AS n
            FROM landing_chat_messages m
            JOIN landing_chats c ON c.chat_id = m.chat_id
            WHERE c.visitor_ip = ?
              AND m.role = 'visitor'
              AND m.created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ");
        $stmt->bind_param("s", $ip);
        $stmt->execute();
        $n = (int) ($stmt->get_result()->fetch_assoc()['n'] ?? 0);
        $stmt->close();

        return $n;
    }
}


if (!function_exists('landingChatAsk')) {

    /*
    | Asks the model, with the conversation so far for context.
    |
    | Returns the reply, or null on any failure at all. Every caller treats
    | null as "fall back to the scripted answer", so a timeout or a bad key
    | is a duller assistant rather than a broken page.
    |
    | @param array<int, array{role:string, body:string}> $history
    */
    function landingChatAsk(mysqli $conn, array $history, string $question): ?string
    {
        if (!landingChatAiReady()) {
            return null;
        }

        $settings = landingChatSettings();

        $messages = [];

        /* The last few turns only. A long thread costs more and adds
           little once the visitor's question is on the table. */
        foreach (array_slice($history, -8) as $turn) {
            $messages[] = [
                'role' => $turn['role'] === 'visitor' ? 'user' : 'assistant',
                'content' => $turn['body'],
            ];
        }

        $messages[] = ['role' => 'user', 'content' => $question];

        $payload = json_encode([
            'model' => (string) ($settings['landing_chat_model'] ?? LANDING_CHAT_MODEL),
            'max_tokens' => 300,
            'system' => landingChatSystemPrompt($conn),
            'messages' => $messages,
        ]);

        $curl = curl_init((string) ($settings['landing_chat_endpoint'] ?? LANDING_CHAT_ENDPOINT));

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $settings['anthropic_api_key'],
                'anthropic-version: 2023-06-01',
            ],
        ]);

        $response = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($response === false || $status !== 200) {
            return null;
        }

        $body = json_decode((string) $response, true);
        $text = trim((string) ($body['content'][0]['text'] ?? ''));

        return $text !== '' ? $text : null;
    }
}


if (!function_exists('landingChatScripted')) {

    /*
    | The answer when the AI is off, capped, or did not come back.
    |
    | Deliberately plain. It reads the same plan data, so it is never out of
    | step with the pricing page, and it ends by offering the team rather
    | than pretending to know more.
    */
    function landingChatScripted(mysqli $conn, string $question): string
    {
        $q = mb_strtolower($question);

        $asks = function (array $words) use ($q): bool {
            foreach ($words as $word) {
                if (mb_strpos($q, $word) !== false) {
                    return true;
                }
            }
            return false;
        };

        if ($asks(['price', 'presyo', 'magkano', 'cost', 'bayad', 'plan', 'subscription'])) {

            $parts = [];

            foreach (landingChatPlans($conn) as $plan) {
                $parts[] = $plan['plan_name'] . ' at '
                    . ((float) $plan['monthly_price'] > 0
                        ? 'PHP ' . number_format((float) $plan['monthly_price'], 2) . '/month'
                        : ($plan['price_label'] ?: 'a quoted price'));
            }

            return $parts === []
                ? 'Our plans are being updated. Leave your details and the team will send them to you.'
                : 'Our plans: ' . implode('; ', $parts)
                    . '. The pricing page has what each one includes. '
                    . 'Leave your details if you would like the team to walk you through them.';
        }

        if ($asks(['register', 'sign up', 'signup', 'paano', 'how do i', 'apply'])) {
            return 'Choose a plan on the pricing page, fill in the registration form with your '
                . 'business details and your DTI and BIR certificates, and our team reviews it. '
                . 'After you sign the service agreement and settle payment, your system opens.';
        }

        if ($asks(['demo', 'trial', 'try'])) {
            return 'We can arrange a walkthrough. Leave your name, business and contact number '
                . 'and the team will get in touch.';
        }

        return 'I can help with questions about RetailCore - our plans, what they include, and how '
            . 'to sign up. Leave your details and someone from the team will answer anything '
            . 'I have missed.';
    }
}


if (!function_exists('landingChatInterested')) {

    /*
    | Whether this message is the point at which to ask for contact details.
    |
    | Asked of the visitor's own words, never of the model's reply: the
    | model inviting someone to leave details is not the same as the visitor
    | wanting to.
    */
    function landingChatInterested(string $question): bool
    {
        $q = mb_strtolower($question);

        $signals = [
            'price', 'presyo', 'magkano', 'cost', 'quote', 'demo', 'trial',
            'sign up', 'signup', 'register', 'avail', 'interested', 'interesado',
            'subscribe', 'contact', 'tawag', 'call me',
        ];

        foreach ($signals as $signal) {
            if (mb_strpos($q, $signal) !== false) {
                return true;
            }
        }

        return false;
    }
}
