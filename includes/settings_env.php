<?php
/*
|--------------------------------------------------------------------------
| SETTINGS FROM THE ENVIRONMENT
|--------------------------------------------------------------------------
|
| One reader, for the two places that need API keys: the tenant assistant and
| the platform's landing chat.
|
| It exists because both looked for their key in a file under C:\xampp. That
| is a Windows path and the deployed site runs Linux, where there is no C: at
| all -- so neither has ever had a key in production. Both fall back silently
| when they cannot find one, which is correct behaviour and exactly why the
| fault went unnoticed for as long as it did.
|
| Render has environment variables and no file. XAMPP has a file outside the
| webroot and no environment. Reading both means neither host has to pretend
| to be the other, and nothing has to be edited to move between them.
|
| It is a file of its own rather than a function inside the chatbot, because
| the platform must be able to read it without loading the chatbot.
*/

if (!function_exists('chatbotSettingsFromEnvironment')) {

    function chatbotSettingsFromEnvironment(): array
    {
        $names = [
            'anthropic_api_key' => 'ANTHROPIC_API_KEY',
            'gemini_api_key' => 'GEMINI_API_KEY',
            'chatbot_chat_enabled' => 'CHATBOT_CHAT_ENABLED',
            'chatbot_chat_model' => 'CHATBOT_CHAT_MODEL',
            'chatbot_chat_endpoint' => 'CHATBOT_CHAT_ENDPOINT',
            'chatbot_chat_daily_cap' => 'CHATBOT_CHAT_DAILY_CAP',
            'chatbot_ai_enabled' => 'CHATBOT_AI_ENABLED',
            'chatbot_ai_daily_cap' => 'CHATBOT_AI_DAILY_CAP',
            'landing_chat_enabled' => 'LANDING_CHAT_ENABLED',
        ];

        $settings = [];

        foreach ($names as $key => $variable) {

            $value = getenv($variable);

            if ($value === false || trim((string) $value) === '') {
                continue;
            }

            $value = trim((string) $value);

            /*
            | A cap is compared with <, and "7" < 100 is a string comparison
            | with a surprising answer. The enabled flags are read with
            | empty(), where the strings "0" and "false" must both mean off --
            | and a non-empty "false" would otherwise mean on.
            */
            if (str_ends_with($key, '_cap')) {
                $settings[$key] = (int) $value;
            } elseif (str_ends_with($key, '_enabled')) {
                $settings[$key] = !in_array(strtolower($value), ['0', 'false', 'off', 'no'], true);
            } else {
                $settings[$key] = $value;
            }
        }

        return $settings;
    }
}
