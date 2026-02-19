<?php

// This is for debugging
$DEBUG=false;

// Log PHP errors
function customErrorHandler($errno, $errstr, $errfile, $errline) {
    $error_message = date('Y-m-d H:i:s').": [$errno] $errstr in $errfile on line $errline\n";
    file_put_contents(__DIR__.'/logs/php_errors.log', $error_message, FILE_APPEND);
    return false; // Let PHP handle the error as well
}
// error_reporting(E_ALL);
set_error_handler("customErrorHandler");

if ($DEBUG) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
}

// Set here the bot you want to use
require_once __DIR__."/bots/general.php";
// require_once __DIR__."/bots/mental_health.php";
// require_once __DIR__."/bots/voice_bot.php";

require_once __DIR__."/lib/utils.php";
require_once __DIR__."/lib/logger.php";
require_once __DIR__."/lib/telegram.php";
require_once __DIR__."/lib/LLM_connector.php";
require_once __DIR__."/lib/user_config_manager.php";
require_once __DIR__."/lib/global_config_manager.php";
require_once __DIR__."/lib/command_manager.php";

if ($DEBUG) {
    Log::set_echo_level(1);
}

// ######################
// ### Initialization ###
// ######################

// Tokens and keys
$global_config_manager = new GlobalConfigManager();
$telegram_token = $global_config_manager->get("TELEGRAM_BOT_TOKEN");
$telegram_token || Log::die("TELEGRAM_BOT_TOKEN is not set.");
$secret_token = $global_config_manager->get("TELEGRAM_BOT_SECRET");
$secret_token || Log::die("TELEGRAM_BOT_SECRET is not set.");
$chat_id_admin = $global_config_manager->get("TELEGRAM_ADMIN_CHAT_ID");
$chat_id_admin || Log::die("TELEGRAM_ADMIN_CHAT_ID is not set.");

// ##### Emergency stop #####
// Log::debug("Emergency stop");
// $telegram_admin = new Telegram($telegram_token, $chat_id_admin, $DEBUG);
// $telegram_admin->send_message("This works again.");
// exit;

// #######################
// ### Security checks ###
// #######################

// Check if the script is called with a POST request
if ($_SERVER['REQUEST_METHOD'] != 'POST') {
    exit;
}

// Security check, to know that the request comes from Telegram
// Use hash_equals for constant-time comparison to prevent timing attacks
if (!$DEBUG && !hash_equals($secret_token, $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '')) {
    http_response_code(401); // 401 Unauthorized
    exit;
}

// ##########################
// ### Message processing ###
// ##########################

// If this script is called from the Telegram webhook, the user has sent a new message in the Telegram chat
$content = file_get_contents("php://input");

// An incoming text message is in the following format:
// {
//     "update_id": 10000,
//     "message": {
//         "date": 1441645532,
//         "chat": {
//             "last_name": "Test Lastname",
//             "id": 1111111,
//             "first_name": "Test",
//             "username": "Test"
//         },
//         "message_id": 1365,
//         "from": {
//             "last_name": "Test Lastname",
//             "id": 1111111,
//             "first_name": "Test",
//             "username": "Test"
//         },
//         "text": "/start"
//     }
// }

// Append the message content to the log file
Log::info($content);

$update = json_decode($content, false);
// Ignore non-message updates
if (!isset($update->message) || !isset($update->update_id)) {
    if ($DEBUG) {
        echo "Incorrect json?\n";
    }
    exit;
}

$telegram_admin = new Telegram($telegram_token, $chat_id_admin, $DEBUG);

if ($DEBUG) {
    Log::debug($update);
    // $telegram_admin->send_message("Message received:\n\n".json_encode($update, JSON_PRETTY_PRINT), false);
}

// Avoid processing the same message twice by checking whether update_id was already processed
$update_id = $update->update_id;
// Assume this can't adversarially block future messages
if (!$DEBUG && Log::already_seen($update_id)) {
    // $telegram->send_message("Repeated message ignored (update_id: $update_id)");
    Log::info("Repeated message ignored (update_id: $update_id)");
    exit;
}
Log::update_id($update_id);

// Parse the message object
$update = $update->message;
$chat_id = $update->chat->id; // Assume that if $update->message exists, so does $update->message->chat->id
$username = $update->from->username;
$name = $update->from->first_name ?? $username;
$lang = $update->from->language_code ?? "en";

$is_admin = $chat_id == $chat_id_admin;
$DEBUG = $DEBUG && $is_admin;  // Only allow debugging for the admin
$telegram = new Telegram($telegram_token, $chat_id, $DEBUG);

// Notify the user if the script is killed by max_execution_time
register_shutdown_function(function() use ($telegram) {
    $error = error_get_last();
    if ($error && $error['type'] === E_ERROR && strpos($error['message'], 'Maximum execution time') !== false) {
        $telegram->send_message("⌛️ The request timed out. If you sent a message, use /continue to retry. If you used a command, please try it again.");
    }
});

$user_config_manager = new UserConfigManager($chat_id, $username, $name, $lang, $DEBUG);
if ($is_admin || $global_config_manager->is_allowed_user($username, "general")) {
    if (!$user_config_manager->get_openai_api_key()) {
        $user_config_manager->set_openai_api_key($global_config_manager->get("OPENAI_API_KEY"));
    }
    if (!$user_config_manager->get_anthropic_api_key()) {
        $user_config_manager->set_anthropic_api_key($global_config_manager->get("ANTHROPIC_API_KEY"));
    }
    if (!$user_config_manager->get_openrouter_api_key()) {
        $user_config_manager->set_openrouter_api_key($global_config_manager->get("OPENROUTER_API_KEY"));
    }

    $llm = new LLMConnector($user_config_manager, $DEBUG);

    // Set the time zone to give the correct time to the model
    date_default_timezone_set($user_config_manager->get_timezone());

    // Update last message time
    $user_config_manager->update_last_seen(date("Y-m-d H:i:s e"));

    try {
        run_bot($update, $user_config_manager, $telegram, $llm, $telegram_admin,
                            $global_config_manager, $is_admin, $DEBUG);
    } catch (Exception $e) {
        Log::error($e->getMessage());
        throw $e;
    }
} else {
    // if $update->text contains "chatid", send the chat_id to the user
    if (isset($update->text) && strpos($update->text, "chatid") !== false)
        $telegram->send_message("Your chat_id is: $chat_id", false);
    else
        $telegram->send_message("I'm sorry, I'm not allowed to talk with you :/", false);

    // Tell me ($chat_id_admin) that someone tried to talk to the bot
    // This could be used to spam the admin
    if ($username != null && $username != "")
        $telegram_admin->send_message("@$username tried to talk to me (chat_id: $chat_id)", false);
    else if ($name != null && $name != "")
        $telegram_admin->send_message("$name tried to talk to me (chat_id: $chat_id)", false);
    else
        $telegram_admin->send_message("Someone without username or name tried to talk to me (chat_id: $chat_id)", false);
}
?>
