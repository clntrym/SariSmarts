<?php
/*
| The key has to be findable on the server it runs on.
|
| Every layer looked for it in C:\xampp\. That is a Windows path, and the
| deployed site runs Linux, where there is no C: at all. So the assistant has
| never worked on Render: the bubble draws, the question posts, chatEnabled()
| finds no key and the whole thing falls silently back to keyword answers --
| which is exactly the behaviour the fallback was designed for, and exactly
| why nobody noticed.
|
| Settings now come from the environment as well as the file, with the file
| winning where both speak. Render supplies environment variables; XAMPP
| supplies a file outside the webroot. Neither host has to pretend to be the
| other.
*/
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../includes/chatbot/engine.php';
require_once __DIR__ . '/../../includes/chatbot/chat/api.php';

$conn = $GLOBALS['conn'];
$ctx = ['company_id' => 1, 'user_id' => 1, 'employee_id' => null, 'role' => 'admin'];

/* bootstrap.php points the path at a file that does not exist. */
$GLOBALS['chatbot_secrets_path'] = __DIR__ . '/no_secrets_in_tests.php';
chatbotForgetSettings();

t_same([], chatbotAiSettings(), 'with no file and no environment, there are no settings');
t_ok(!chatEnabled($conn, $ctx), 'and the chat layer is off');

/* ------------------------------------------------- the environment alone */

putenv('ANTHROPIC_API_KEY=sk-ant-from-the-environment');
putenv('CHATBOT_CHAT_ENABLED=1');
chatbotForgetSettings();

$settings = chatbotAiSettings();

t_same('sk-ant-from-the-environment', $settings['anthropic_api_key'] ?? null,
    'the key is read from the environment, as it is on Render');
t_ok(chatEnabled($conn, $ctx), 'and that is enough to switch the chat layer on');

/* The other knobs travel the same way. */
putenv('CHATBOT_CHAT_MODEL=claude-sonnet-5-5');
putenv('CHATBOT_CHAT_DAILY_CAP=7');
chatbotForgetSettings();

$settings = chatbotAiSettings();

t_same('claude-sonnet-5-5', $settings['chatbot_chat_model'] ?? null, 'the model comes too');
t_same(7, $settings['chatbot_chat_daily_cap'] ?? null, 'and the cap, as a number');

/* ------------------------------------------ the file wins over the environment */

$fixture = sys_get_temp_dir() . '/settings_sources_fixture.php';
file_put_contents($fixture, "<?php\nreturn " . var_export([
    'anthropic_api_key' => 'sk-ant-from-the-file',
    'chatbot_chat_enabled' => true,
], true) . ";\n");

register_shutdown_function(function () use ($fixture) { @unlink($fixture); });

$GLOBALS['chatbot_secrets_path'] = $fixture;
chatbotForgetSettings();

$settings = chatbotAiSettings();

t_same('sk-ant-from-the-file', $settings['anthropic_api_key'] ?? null,
    'a file on disk beats the environment: it is the more deliberate of the two');

/* A key the file does not mention still comes from the environment, so one
   can be added on the host without editing the file. */
t_same('claude-sonnet-5-5', $settings['chatbot_chat_model'] ?? null,
    'and a setting the file omits is still filled from the environment');

/* ------------------------------------------------------- the reserve provider */

putenv('GEMINI_API_KEY=AIza-reserve-key');
chatbotForgetSettings();

$settings = chatbotAiSettings();

t_same('AIza-reserve-key', $settings['gemini_api_key'] ?? null,
    'the reserve provider key travels the same road');

/* Leave the environment as it was found. */
foreach (['ANTHROPIC_API_KEY', 'CHATBOT_CHAT_ENABLED', 'CHATBOT_CHAT_MODEL',
          'CHATBOT_CHAT_DAILY_CAP', 'GEMINI_API_KEY'] as $name) {
    putenv($name);
}

t_done();
