<?php

require_once __DIR__ . '/../lib/utils.php';
require_once __DIR__ . '/../lib/parsers.php';

/**
 * This is the main function for the general bot.
 *
 * @param object $update The update object
 * @param UserConfigManager $user_config_manager The user config manager
 * @param Telegram $telegram The Telegram manager for the user
 * @param LLMConnector $llm The LMMConnector object
 * @param Telegram $telegram_admin The Telegram manager for the admin
 * @param GlobalConfigManager $global_config_manager The global config manager
 * @param bool $is_admin Whether the user is an admin
 * @param bool $DEBUG Whether to enable debug mode
 * @return void
 */
function run_bot($update, $user_config_manager, $telegram, $llm, $telegram_admin, $global_config_manager, $is_admin, $DEBUG) {
    if (isset($update->text)) {
        $message = $update->text;

        // Only process as arXiv if the message starts with a valid arXiv link or ID
        // $pattern = '/
        //     ^\s*                                           # start of string + optional whitespace
        //     (?:                                            # optional prefix (one of, or none)
        //         (?:https?:\/\/)?arxiv\.org\/(?:abs|pdf)\/  # arxiv URL (/abs/ or /pdf/)
        //         | (?:doi[.:])?10\.48550\/arxiv\.           # DOI (with or without "doi:" prefix)
        //         | arxiv.?                                  # bare "arXiv:" or "arXiv-"
        //     )?                                             # prefix is optional (bare ID allowed)
        //     (
        //         \d{4}\.\d{4,5}(?:v\d+)?                    # modern: YYYY.NNNN[vN]
        //         | [a-z][a-z0-9.\-]*\/\d{4}\d+(?:v\d+)? # legacy: category/YYMMNNN[vN]
        //     )
        //     (?:\.pdf)?                                     # trailing .pdf (only relevant for pdf URLs)
        //     (?!\d)                                         # boundary: not further digit
        // /ix';                                             // i = ignore case; x = allow comments
        if (preg_match('/^\s*(?:(?:https?:\/\/)?arxiv\.org\/(?:abs|pdf)\/|(?:doi[.:]\s*)?10\.48550\/arxiv\.|arxiv.?)?(\d{4}\.\d{4,5}(?:v\d+)?|[a-z][a-z0-9.\-]*\/\d{4}\d+(?:v\d+)?)(?:\.pdf)?(.*)$/is',
                $message, $matches)) {
            $arxiv_id = $matches[1];
            $user_message = trim($matches[2] ?? '');

            $telegram->send_message("Detected arXiv ID `$arxiv_id`. Processing...");

            $result = text_from_arxiv_id($arxiv_id);
            $telegram->die_if_error($result);

            assert(is_array($result));
            // Format paper information for the chat
            $paper_title = $result['title'];
            if ($paper_title !== "") {
                $paper_title = " \"$paper_title\"";
            }
            $tex_content = $result['content'];
            $stats = get_message_stats($tex_content);
            $telegram->send_message("arXiv:$arxiv_id$paper_title ({$stats['words']} words ≈ {$stats['tokens']} tokens). Send a message (or use /continue) to obtain a response.");

            // Format the message with paper content and metadata
            $message = "arXiv:$arxiv_id$paper_title\n\n```\n$tex_content\n```";
            if ($user_message !== '') {
                $message .= "\n\n$user_message";
            }
            $user_config_manager->add_message("user", $message);
            exit;
        } else if (preg_match('/^\s*(https?:\/\/[^\s]+)\s*(.*)$/is', $message, $matches) && !preg_match('/\.(jpg|jpeg|png)$/i', $matches[1])) {
            $link = $matches[1];
            $user_message = trim($matches[2] ?? '');

            $telegram->send_message("Detected link `$link`. Extracting content...");
            // Special handling for github.com repo links to get README.md if exists
            if (preg_match('#^https?://github.com/([^/]+)/([^/]+)(/tree/([^/]+)(/.*)?)?#i', $link, $githubMatch)) {
                $owner = $githubMatch[1];
                $repo = $githubMatch[2];
                $branch = isset($githubMatch[4]) && $githubMatch[4] ? $githubMatch[4] : 'main';
                $path = isset($githubMatch[5]) && $githubMatch[5] ? $githubMatch[5] : '';
                $path = trim($path, '/');
                $try_paths = [];
                if ($path !== '') {
                    $parts = explode('/', $path);
                    // Build all parent paths from deepest to root
                    for ($i = count($parts); $i >= 0; $i--) {
                        $subpath = implode('/', array_slice($parts, 0, $i));
                        $try_paths[] = ($subpath ? $subpath . '/' : '') . 'README.md';
                    }
                } else {
                    $try_paths[] = 'README.md';
                }
                // Try each possible README.md location, all on the specified branch
                foreach ($try_paths as $try_path) {
                    $raw_url = "https://raw.githubusercontent.com/$owner/$repo/$branch/$try_path";
                    $head = @get_headers($raw_url, 1);
                    if ($head && strpos($head[0], '200') !== false) {
                        $link = $raw_url;
                        break;
                    }
                }
            }
            if (preg_match('/\.(md|txt|text|csv|log)$/i', $link)) {
                // direct download of text-like file content into $content
                $content = @file_get_contents($link);
                $content || $telegram->die("Error: Failed to download text file content from the link.");
            } else {
                $content = parse_link($link);
                $telegram->die_if_error($content);
            }

            // Use get_message_stats for consistency with PDF/archive handling
            $stats = get_message_stats($content);
            $stats['words'] != 0 || $telegram->die("Error: Sorry, I couldn't extract text from the link!");

            // Reject links that are too large (parity with PDF, 42,000 words)
            if ($stats['words'] > 42000) {
                $telegram->die("Error: Content is too long ({$stats['words']} words). Maximum size is 42,000 words.");
            }

            $telegram->send_message("Link processed ({$stats['words']} words ≈ {$stats['tokens']} tokens). Send a message (or use /continue) to obtain a response.");

            // Store the content and user message for later use, but do not display it now
            $message = "Link: $link\n\n```\n$content\n```";
            if ($user_message !== '') {
                $message .= "\n\n$user_message";
            }
            $user_config_manager->add_message("user", $message);
            exit;
        }
    } else if (isset($update->photo)) {
        $chat = $user_config_manager->get_config();
        // Check if the model can see
        if (substr($chat->model, 0, 5) == "gpt-3" || substr($chat->model, 0, 7) == "o3-mini") {
            $telegram->die("Error: You can't send images if you are talking to $chat->model. Try another model.");
        }
        $file_id = end($update->photo)->file_id;
        $caption = $update->caption ?? "";
        $file_url = $telegram->get_file_url($file_id);
        $file_url != null || $telegram->die("Error: Could not get the file URL from Telegram.");

        // If caption starts with a command, move it to the beginning
        if (preg_match('/^(\/[a-zA-Z0-9_]+)(\b.*)?$/', $caption, $cmd_matches)) {
            $command = $cmd_matches[1];
            $message = "$command $file_url";
            if (!empty($cmd_matches[2])) {
                $message .= " ".$cmd_matches[2];
            }
        } else {
            $message = "$file_url $caption";
        }
    } else if (isset($update->document)) {
        $file_name = $update->document->file_name;
        $ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

        // File size check (PDF: 10MB, others: 2MB)
        $max_size = ($ext === 'pdf') ? 10 * 1024 * 1024 : 2 * 1024 * 1024;
        if (isset($update->document->file_size) && $update->document->file_size > $max_size) {
            $size_mb = round($update->document->file_size / (1024 * 1024), 1);
            $telegram->die("Error: File is too large ($size_mb MB). Maximum size is " . ($ext === 'pdf' ? "10 MB" : "2 MB") . ".");
        }

        $file_id = $update->document->file_id;
        $file_url = $telegram->get_file_url($file_id);
        $file_url != null || $telegram->die("Error: Could not get the file URL from Telegram.");
        $caption = $update->caption ?? "";
        $title = $file_name;

        if ($ext === 'pdf') {
            // Prefer arXiv TeX if filename matches arXiv ID
            $content = '';
            if (preg_match('/^(\d{4}\.\d{4,5}(?:v\d+)?)\.pdf$/i', $file_name, $matches)) {
                $arxiv_id = $matches[1];
                $telegram->send_message("PDF filename matches arXiv ID `$arxiv_id`. Fetching arXiv TeX source...");
                $result = text_from_arxiv_id($arxiv_id);
                if (has_error($result)) {
                    $telegram->send_message($result);
                } else {
                    assert(is_array($result));
                    $title = $result['title'] ?: $title;  // overwrite title
                    $content = $result['content'];
                    $label = "arXiv:$arxiv_id";
                }
            }
            if (empty($content)) {
                $telegram->send_message("Extracting text from pdf file...");
                $content = text_from_pdf($file_url);
                $telegram->die_if_error($content);
                $label = "PDF";
            }
        } else {
            $content = @file_get_contents($file_url);
            $content !== false || $telegram->die("Error: Could not download the file.");
            // Text-like and .tex handling
            if ($ext === 'tex') {
                $content = clean_tex($content);
                $label = "TeX";
            } else {
                $label = ucfirst($ext) ?: "Text";
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime_type = finfo_buffer($finfo, $content);
                finfo_close($finfo);
                if (strpos($mime_type, 'text/') !== 0 && (
                    !mb_check_encoding($content, 'UTF-8') ||
                    preg_match_all('/[^\P{C}\n\r\t]/u', $content) > 0.01 * strlen($content)
                )) $telegram->die("Error: The file you sent does not appear to be a supported text-like document.");
            }
        }
        // Common ending for both PDF and text-like files
        $stats = get_message_stats($content);
        $stats['words'] != 0 || $telegram->die("Error: Sorry, I couldn't extract text from the file! (".strlen($content).")");
        if ($stats['words'] > 42000) {
            $telegram->die("Error: Content is too long ({$stats['words']} words). Maximum size is 42,000 words.");
        }
        $telegram->send_message("$label file processed ({$stats['words']} words ≈ {$stats['tokens']} tokens). Send a message (or use /continue) to obtain a response.");
        $message = "$label: \"{$title}\"\n\n```\n$content\n```";
        if (!empty($caption)) {
            $message .= "\n\n$caption";
        }
        $user_config_manager->add_message("user", $message);
        exit;
    } else if (isset($update->voice) || isset($update->audio)) {
        // Get the file content from file_id with $telegram->get_file
        if (isset($update->voice)) {
            $file_id = $update->voice->file_id;
        } else {
            $file_id = $update->audio->file_id;
        }
        $file = $telegram->get_file($file_id);
        $file != null || $telegram->die("Error: Could not get the file from Telegram.");

        // Transcribe
        $message = $llm->asr($file);
        $telegram->die_if_error($message);

        // Send the transcription to the user
        if (isset($update->voice) && $update->voice->duration > 0) {
            if (isset($update->forward_from) || isset($update->forward_sender_name) || isset($update->forward_date)) {
                $telegram->send_message("/re $message");
                exit; // Don't automatically request a response
            } else {
                $telegram->send_message("/user $message");
            }
        } else {
            // If audio, just send the transcription
            $telegram->send_message($message);
            exit;
        }
    } else if (isset($update->document)) {
        $telegram->die("I can only process PDF or plain-text files. The file you sent doesn't appear to be a supported document type.");
    } else {
        if ($DEBUG) {
            $telegram->send_message("Unknown message: ".json_encode($update, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), false);
        }
        $telegram->die("Sorry, I don't know yet what do to this message! :/");
    }

    if ($DEBUG) {
        // $telegram->send_message("You said: ".json_encode($message, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), false);
        // exit;
        echo "You said: ".json_encode($message, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n";
    }

    $telegram->set_postprocessing($user_config_manager->is_post_processing());

    // #######################
    // ### Command parsing ###
    // #######################

    if (is_string($message)) {
        // If starts with "." or "\", it might be a typo for a command
        if ((substr($message, 0, 1) == "." && strlen($message) > 1) || (substr($message, 0, 1) == "\\")) {
            // Remove everything after the first space-like character
            $potential_command = preg_replace("/\s.*/", "", $message);
            // If it only contains word-characters, it might be a command
            $potential_command = substr($potential_command, 1);
            if (!preg_match("/[^A-Za-z0-9]/", $potential_command)) {
                $telegram->die("Did you mean the command /$potential_command ? If not, escape the first character with '\\'.\nNothing was added to the chat history.");
            }
        }
    }

    // If $message starts with /, it's a command
    if (is_string($message) && substr($message, 0, 1) == "/") {
        if ($is_admin) {
            $categories = array("Presets", "Shortcuts", "Settings", "Chat history management", "Admin", "Misc");
        } else {
            $categories = array("Presets", "Shortcuts", "Settings", "Chat history management", "Misc");
        }
        $command_manager = new CommandManager($categories);

        // Shortcuts for models
        $shortcuts_medium = array(
            "/gemma4" => "google/gemma-4-31b-it",                  #  31/ 31b  0.13/0.38
            "/mistralsmall32" => "mistralai/mistral-small-3.2-24b-instruct",  # 24/24b  0.10/0.30
            "/mistralmedium35" => "mistralai/mistral-medium-3-5",  # 128/128b  1.50/7.50
            "/mistralmedium31" => "mistralai/mistral-medium-3.1",  #           0.40/2.00
            "/mistralmedium3" => "mistralai/mistral-medium-3",     #           0.40/2.00
            "/mistrallarge3" => "mistralai/mistral-large-2512",    #  41/675b  0.50/1.50
            "/mistrallarge2" => "mistralai/mistral-large-2411",    # 123/123b  2.00/6.00
            "/qwen36plus" => "qwen/qwen3.6-plus",                  #  18/ 72b  0.50/3.00
            "/qwen3627b" => "qwen/qwen3.6-27b",                    #  27/ 27b  0.50/2.40
            "/qwen3527b" => "qwen/qwen3.5-27b",                    #  27/ 27b  0.30/2.40
            "/qwen35a17" => "qwen/qwen3.5-397b-a17b",              #  17/397b  0.40/2.40
            // "/qwen35a10" => "qwen/qwen3.5-122b-a10b",           #  10/122b  0.30/2.40
            "/glm51"  => "z-ai/glm-5.1",                           #  40/744b  1.00/3.50
            "/glm5"  => "z-ai/glm-5",                              #  40/744b  0.60/2.00
            "/glm47" => "z-ai/glm-4.7",                            #  32/355b  0.40/1.75
            "/aion20" => "aion-labs/aion-2.0",                     # v3.2 unc. 0.80/1.60
            "/deepseekr1" => "deepseek/deepseek-r1-0528",          #  37/671b
            "/deepseekv32" => "deepseek/deepseek-v3.2",            #  37/671b  0.26/0.38
            "/deepseekv3" => "deepseek/deepseek-chat-v3-0324",     #  37/671b  0.20/0.80
            "/llama4maverick" => "meta-llama/llama-4-maverick",    #  17/400b  0.15/0.60
            "/llama4scout" => "meta-llama/llama-4-scout",          #  17/109b  0.08/0.30
            "/claude46sonnet" => "anthropic/claude-sonnet-4.6",    #           3.00/15.00
            "/claude45sonnet" => "anthropic/claude-sonnet-4.5",    #           3.00/15.00
            "/claude4sonnet" => "anthropic/claude-sonnet-4.0",     #           3.00/15.00
            // "/claude46sonnet" => "claude-sonnet-4-6",              #           3.00/15.00
            // "/claude45sonnet" => "claude-sonnet-4-5",              #           3.00/15.00
            // "/claude4sonnet" => "claude-sonnet-4-0",               #           3.00/15.00
            "/gpt55" => "gpt-5.5",                                 #           5.00/30.00
            "/gpt54" => "gpt-5.4",                                 #           2.50/15.00
            "/gpt52" => "gpt-5.2",                                 #           1.75/15.00
            "/gpt51" => "gpt-5.1",                                 #           1.25/10.00
            "/gpt5"  => "gpt-5",                                   #           1.25/10.00
            "/gpt41" => "gpt-4.1",                                 #           2.00/8.00
            "/gpt4o" => "gpt-4o",                                  #           2.50/10.00
            "/o4mini" => "o4-mini-low",                            #           1.10/4.40
            "/o3" => "o3-low",                                     #           2.00/8.00
        );

        // 1T+ total parameters
        $shortcuts_large = array(
            "/ling26" => "inclusionai/ling-2.6-1t",                #  63/  1T  0.30/2.50
            "/ring26" => "inclusionai/ring-2.6-1t",                #  63/  1T  0.08/0.63
            "/deepseekv4pro" => "deepseek/deepseek-v4-pro",        #  49/1.6T  1.30/2.60
            "/mimo25pro" => "xiaomi/mimo-v2.5-pro",                #  42/  1T  0.44/0.87
            "/qwen37max" => "qwen/qwen3.7-max",                    #  22/  1T  2.50/7.50
            "/qwen36max" => "qwen/qwen3.6-max-preview",            #    /  1T  1.30/7.80
            "/kimik26" => "moonshotai/kimi-k2.6",                  #  32/  1T  0.80/3.50
            "/kimik25" => "moonshotai/kimi-k2.5",                  #  32/  1T  0.44/2.00
            "/kimik2" => "moonshotai/kimi-k2-0905",                #  32/  1T  0.40/2.00
            "/claude5fable" => "anthropic/claude-fable-5",         #          10.00/50.00
            "/claude48opus" => "anthropic/claude-opus-4.8",        #           5.00/25.00
            "/claude47opus" => "anthropic/claude-opus-4.7",        #           5.00/25.00
            "/claude46opus" => "anthropic/claude-opus-4.6",        #           5.00/25.00
            "/claude45opus" => "anthropic/claude-opus-4.5",        #           5.00/25.00
            "/claude41opus" => "anthropic/claude-opus-4.1",        #           15.00/75.00
            "/claude40opus" => "anthropic/claude-opus-4.0",        #           15.00/75.00
            // "/claude47opus" => "claude-opus-4-7",                  #          5.00/25.00
            // "/claude46opus" => "claude-opus-4-6",                  #          5.00/25.00
            // "/claude45opus" => "claude-opus-4-5",                  #          5.00/25.00
            // "/claude41opus" => "claude-opus-4-1",                  #          15.00/75.00
            // "/claude40opus" => "claude-opus-4-0",                  #          15.00/75.00
            "/gemini31pro" => "google/gemini-3.1-pro-preview",     #           2.00/12.00
            "/gemini3pro" => "google/gemini-3-pro-preview",        # no provider
            "/gemini25pro" => "google/gemini-2.5-pro",             #    ~500b  1.25/10.00
            // "/gpt45" => "gpt-4.5-preview",  # 2T ?
            // "/gpt4" => "gpt-4-0613",        # 1T ?
        );

        // <= 14b active parameters
        $shortcuts_small = array(
            "/gptoss120b" => "openai/gpt-oss-120b",              #   5/117b  0.05/0.20
            "/ibmgranite41" => "ibm-granite/granite-4.1-8b",     #   8/  8b  0.05/0.10
            "/deepseekv4flash" => "deepseek/deepseek-v4-flash",  #  13/284b  0.14/0.28
            "/mistralsmall4" => "mistralai/mistral-small-2603",  #   7/119b  0.15/0.60
            "/mercury2" => "inception/mercury-2",                # diffusion 0.25/0.75
            "/mercury" => "inception/mercury",
            "/step37flash" => "stepfun/step-3.7-flash",          #  11/196b  0.20/1.15
            "/step35flash" => "stepfun/step-3.5-flash",          #  11/196b  0.10/0.30
            "/minimaxm27" => "minimax/minimax-m2.7",             #  10/230b  0.30/1.20
            "/claude45haiku" => "claude-haiku-4-5",              #           0.25/1.25
            "/gpt41mini" => "gpt-4.1-mini",                      #           0.40/1.60
            "/gpt5mini" => "gpt-5-mini-medium",                  #           0.25/2.00
            "/gpt41nano" => "gpt-4.1-nano",                      #           0.10/0.40
            "/gpt5nano" => "gpt-5-nano-medium",                  #           0.05/0.40
            "/geminiflash3" => "google/gemini-3-flash-preview",  #           0.50/3.00
            "/geminiflash25" => "google/gemini-2.5-flash",       #       5b  0.30/2.50
            "/mistralsmallcreative" => "mistralai/mistral-small-creative",    # 24/24b  0.20/0.30
        );

        // #########################
        // ### Commands: Presets ###
        // #########################
        $get_character_description = function($new_character, $previous_descriptions="") use ($telegram, $llm, $user_config_manager) {
            # Request a character description
            $prompt = "Create a character description based on the following information given by the user: $new_character\n\nIf they mentioned multiple "
                ."characters, please generate a description for each of them, otherwise only one.\n\n"
                ."Develop a brief but vivid portrayal of each of the characters, focusing on their professions, key ideas, and worldviews. "
                ."Describe their typical way of thinking and speaking, including any unique expressions or mannerisms they might use. "
                ."Highlight their areas of expertise and any strongly held opinions or beliefs that would come across in conversation. "
                ."Consider their background and experiences that shape their perspective. The goal is to capture the essence of how "
                ."these characters would authentically express themselves in a dialogue, rather than just listing traits. "
                ."Aim for a concise description that will allow for a realistic and engaging conversation with these characters. "
                ."If the request is generic or abstract, keep the descriptions general and adaptable to various contexts without inventing names or other specific details."
                ."Don't write anything else before or after the character descriptions, only output the character descriptions.";
            $previous_descriptions = trim($previous_descriptions);
            $has_previous_characters = $previous_descriptions != "" && !str_starts_with($previous_descriptions, "This room is empty.");
            if ($has_previous_characters) {
                # ask it to append the new character description to the previous ones
                $prompt .= " For your reference, these characters are already in the room:\n\n$previous_descriptions\n\nPlease only output the "
                ."new character description in a concise format, without repeating the previous descriptions.";
            }

            $chat = $user_config_manager->get_config();
            $chat = json_decode(json_encode($chat, JSON_UNESCAPED_UNICODE));
            $chat->messages = array();
            $chat->messages[] = (object) array("role" => "user", "content" => $prompt);
            $description = $llm->message($chat);
            $telegram->die_if_error($description);
            if ($has_previous_characters) {
                # append the new character description to the previous ones
                $description = "$previous_descriptions\n\n$description";
            }
            return $description;
        };

        $default_intro = "Your task is to help and support your friend in their life. "
            ."Your voice is generally casual, kind, compassionate, and heartful. "
            ."Keep your responses concise and compact. "
            ."Don't draw conclusions before you've finished your reasoning and think carefully about the correctness of your answers. "
            ."If you are missing information that would allow you to give a much more helpful answer, "
            ."please don't provide an actual answer, but instead ask for what you'd need to know first. "
            ."If you are unsure about something, state your uncertainty and ask for clarification. "
            ."Feel free to give recommendations (actions, books, papers, etc.) that seem useful and appropriate. "
            ."If you recommend resources, please carefully ensure they actually exist. "
            ."Avoid showing warnings or information regarding your capabilities. "
            ."You can use Markdown and emojis to format and enrich your messages. "
            ."Spread love! ❤️✨";

        $reset = function($add_intro=true, $save_session=true) use ($user_config_manager, $default_intro) {
            if ($save_session)
                $user_config_manager->save_session();
            $user_config_manager->clear_messages();
            if ($add_intro) {
                # Add intro as system message
                $intro = $user_config_manager->get_intro();
                // Replace <datetime></datetime> with the current date
                $intro = preg_replace(
                    '#<datetime>.*?</datetime>#',
                    date("l, j.n.Y, g:ia"),
                    $intro
                );
                $user_config_manager->add_message("system", $intro ?: $default_intro);
            }
        };

        $invite = function($new_characters) use ($telegram, $user_config_manager, $llm, $get_character_description) {
            $chat = $user_config_manager->get_config();
            $is_conversation_room = count($chat->messages) > 1 && is_string($chat->messages[1]->content) && substr($chat->messages[1]->content, 0, 25) == "Character description(s):";
            if (!$is_conversation_room) {
                $telegram->send_message("Trying to find $new_characters...");
                # If the first message is intro, remove it and append it to "new characters" message
                if (is_string($chat->messages[0]->content)) {
                    $intro = $user_config_manager->get_intro();
                    // Strip <datetime>...</datetime> tags for comparison
                    $intro_stripped = preg_replace('#<datetime>.*?</datetime>#', '', $intro);
                    $msg0_stripped = preg_replace('#<datetime>.*?</datetime>#', '', $chat->messages[0]->content);

                    if (count($chat->messages) > 0 && $msg0_stripped === $intro_stripped) {
                        $new_characters = "$new_characters\n\nAlso add a personal assistant with a characterization following this prompt:\n```{$chat->messages[0]->content}```";
                        array_shift($chat->messages);
                        // Prepend any "assistant" message with "Personal assistant:\n"
                        foreach ($chat->messages as $message) {
                            if ($message->role == "assistant") {
                                $message->content = "Personal assistant:\n$message->content";
                            }
                        }
                    }
                }
                $previous_messages = $chat->messages;
                $chat->messages = array();
                # create new character descriptions
                $description = $get_character_description($new_characters);
                $user_config_manager->add_message("system", "You are now in a conversation room with one or more characters described below. Your role is to embody these "
                    ."character(s) and engage in dialogue with the user. Respond authentically, representing each character's unique personality, "
                    ."knowledge, and manner of speaking. Draw upon the details provided to inform responses, opinions, and overall demeanor. "
                    ."Stay true to each character's background, expertise, and worldview throughout the interaction.");
                $user_config_manager->add_message("system", "Character description(s):\n\n$description");
                $user_config_manager->add_message("system", "Conversation format:\n"
                    ."- If there's only one character, respond directly as that character.\n"
                    ."- If there are multiple characters, give every character an opportunity to respond.\n\n"
                    ."For each user message, respond as the character(s) would, incorporating their specific knowledge, opinions, and speech patterns. "
                    ."If asked about topics outside a character's expertise or experience, have them respond realistically, which may include admitting "
                    ."uncertainty or redirecting the conversation to their areas of interest.\n\n"
                    ."In multi-character scenarios, characters may interact with or respond to each other's comments if it's natural for them to do so. "
                    ."The user can address questions or comments to specific characters or to the group as a whole. Not every character has to respond every time. "
                    ."It might often be enough to just hear from one or two characters with the most relevant perspectives. Remember to maintain each character's "
                    ."unique voice and perspective throughout the entire conversation, whether it's a one-on-one dialogue or a group discussion.");
                # Ask the AI to write a short information for the user who they are talking with
                $user_config_manager->add_message("user", "Who joined the scene? Please respond with one sentence in the format \"You are now in a conversation room with ...\" with no other text before or after.");
                $joined = $llm->message($user_config_manager->get_config());
                if (has_error($joined)) {
                    // ignore $joined
                    $telegram->send_message("You are now in a conversation room with $new_characters :)");
                } else {
                    $telegram->send_message($joined);
                }
                $user_config_manager->delete_messages(1);
                foreach ($previous_messages as $message) {
                    $user_config_manager->add_message($message->role, $message->content);
                }
            } else if (substr($chat->messages[1]->content, 0, 25) == "Character description(s):") {
                $current_description = substr($chat->messages[1]->content, 25);
                $telegram->send_message("Trying to find $new_characters...");
                $description = $get_character_description($new_characters, $current_description);
                $chat->messages[1]->content = "Character description(s):\n\n$description";
                # ask AI to write a short information for the user who joined the conversation
                $user_config_manager->add_message("user", "Previous character description(s):\n\n$current_description\n\nNew character description(s):\n\n$description\n\n"
                    ."Please analyze the descriptions above and inform us: Who joined the scene? Respond with one short sentence with no other text before or after.");
                $joined = $llm->message($chat);
                if (has_error($joined)) {
                    $joined = "$new_characters joined the conversation.";
                }
                $user_config_manager->delete_messages(1);
                $telegram->send_message($joined);
            }
            # append $joined to the last message of the chat to inform the AI
            $last_message = $chat->messages[count($chat->messages) - 1];
            if ($last_message->role == "assistant") {
                $last_message->content .= "\n\n$joined";
            }
        };

        // Allow /start command to carry any other command
        if (is_string($message) && preg_match('/^\/start\s+(\S.*)$/', $message, $m)) {
            // $reset(true);
            // $hello = $user_config_manager->hello();
            // $telegram->send_message($hello);
            $message = '/'.$m[1];
        }

        // The command is /start or /reset resets the bot and sends a welcome message
        $command_manager->add_command(array("/start", "/reset", "/r"), function($command, $description) use ($reset, $invite, $user_config_manager, $telegram) {
            $reset(true);
            if ($description == "") {
                return $user_config_manager->hello();
            } else {
                $user_config_manager->delete_messages(1);
                $invite($description);
                exit;
            }
        }, "Presets", "Start a new conversation. You may provide a description who you want to talk with.");

        // The command /invite invites another character to the conversation
        $command_manager->add_command(array("/invite"), function($command, $new_character) use ($telegram, $user_config_manager, $invite) {
            $new_character != "" || $telegram->die("Please provide a description of the character you want to invite into the conversation.");
            $invite($new_character);
            exit;
        }, "Presets", "Invite another character to the conversation. Provide a description of the character with the command.");

        // The command /leave removes the a character from the conversation
        $command_manager->add_command(array("/leave"), function($command, $character) use ($telegram, $llm, $user_config_manager) {
            $character != "" || $telegram->die("Please provide the name of the character you want to remove from the conversation.");
            $chat = $user_config_manager->get_config();
            if (count($chat->messages) == 0) {
                $telegram->send_message("The room is empty.");
            } else if (count($chat->messages) == 1 || (count($chat->messages) > 1 && substr($chat->messages[1]->content, 0, 25) != "Character description(s):")) {
                $telegram->send_message("It looks like you are only chatting with the default personal assistant :) You can set up a new room with /reset or invite other characters with /invite.");
            } else if (substr($chat->messages[1]->content, 0, 25) == "Character description(s):") {
                $telegram->send_message("Asking $character to leave...");
                $current_description = substr($chat->messages[1]->content, 25);
                # request llm to remove the character description
                $user_config_manager->add_message("user", "Remove the character description of \"$character\" from the following descriptions. "
                ."Don't write anything else before or after, only output the remaining descriptions. If the requested character is not among the descriptions, just repeat "
                ."the descriptions as they are with no description removed. If there is no description left after removal, just output \"---\".\n\n$current_description");
                $new_description = $llm->message($chat);
                $user_config_manager->delete_messages(1);
                $telegram->die_if_error($new_description);
                if (abs(strlen($new_description) - strlen($current_description)) < 10) {
                    return "It seems $character is not in the conversation.";
                }
                $telegram->send_message("$character left the conversation.");
                # append "$character left the conversation." to the last message of the chat to inform the AI
                $last_message = $chat->messages[count($chat->messages) - 1];
                $last_message->content .= "\n\n$character left the conversation.";
                if ($new_description == "---") {
                    $new_description = "This room is empty. Respond only as an empty space sounds.";  # see line 145
                }
                # save the new character descriptions
                $chat->messages[1]->content = "Character description(s):\n\n$new_description";
            }
            exit;
        }, "Presets", "Remove a character from the conversation. Provide the name of the character with the command.");

        // The command /room shows the current character descriptions
        $command_manager->add_command(array("/room"), function($command, $_) use ($user_config_manager) {
            $chat = $user_config_manager->get_config();
            if (count($chat->messages) == 0)
                return "You are not in a conversation.";
            else if (count($chat->messages) == 1 || (count($chat->messages) > 1 && substr($chat->messages[1]->content, 0, 25) != "Character description(s):"))
                return "It looks like you are chatting with the default personal assistant :) You can invite other characters with the /invite command.";
            // else if (substr($chat->messages[1]->content, 0, 25) == "Character description(s):")
            return $chat->messages[1]->content;
        }, "Presets", "Show the current character descriptions.");

        // The command /responder writes a response to a given message
        $command_manager->add_command(array("/responder", "/re"), function($command, $message) use ($telegram, $user_config_manager, $llm, $reset) {
            $prompt = "Your task is to generate responses to messages sent to me, "
                ."carefully considering the context and my abilities as a human. Use a casual, calm, and kind voice. Keep your responses "
                ."concise and focus on understanding the message before responding.";
            // If the message is not empty, process the request one-time without saving the config
            if ($message != "") {
                $chat = $user_config_manager->get_config();
                $chat = json_decode(json_encode($chat, JSON_UNESCAPED_UNICODE));
                $chat->messages = [
                    ["role" => "system", "content" => $prompt],
                    ["role" => "user", "content" => $message]
                ];
                $response = $llm->message($chat);
                $telegram->die_if_error($response);
            } else {
                $reset(false);
                $user_config_manager->add_message("system", $prompt);
                $response = "Chat history reset. I am now a message responder.";
                $user_config_manager->add_message("assistant", $response);
            }
            return $response;
        }, "Presets", "Suggests responses to messages from others. Give a message with the command to preserve the previous conversation.");

        // The command /translator translates a given text
        $command_manager->add_command(array("/trans"), function($command, $text) use ($telegram, $user_config_manager, $llm, $reset) {
            $prompt = "Translate the messages sent to you into English, ensuring accuracy in grammar, verb tenses, and context. Identify the language or encoding of the text you translate from.";
            // If the text is not empty, process the request one-time without saving the config
            if ($text != "") {
                $chat = $user_config_manager->get_config();
                $chat = json_decode(json_encode($chat, JSON_UNESCAPED_UNICODE));
                $chat->messages = [
                    ["role" => "system", "content" => $prompt],
                    ["role" => "user", "content" => $text]
                ];
                $response = $llm->message($chat);
                $telegram->die_if_error($response);
            } else {
                $reset(false);
                $user_config_manager->add_message("system", $prompt);
                $response = "Chat history reset. I am now a translator.";
                $user_config_manager->add_message("assistant", $response);
            }
            return $response;
        }, "Presets", "Translate messages into English. Give the text with the command to preserve the previous conversation.");

        // The command /event converts event descriptions to an iCalendar file
        $command_manager->add_command(array("/event"), function($command, $description) use ($telegram, $user_config_manager, $llm, $reset) {
            $timezone = date("e");
            $prompt = "Extract details about events from the provided text and output an "
                ."event in iCalendar format. Try to infer the time zone from the location. Use can use the example for the timezone below as "
                ."a template. Ensure that the code is valid. Output the code only, and do NOT enclose the output in backticks. Today is ".date("l, j.n.Y").".\n\n"
                ."BEGIN:VTIMEZONE\nTZID:$timezone\nEND:VTIMEZONE";
            // If the description is not empty, process the request one-time without saving the config
            if ($description != "") {
                $chat = $user_config_manager->get_config();
                $chat = json_decode(json_encode($chat, JSON_UNESCAPED_UNICODE));
                $chat->messages = [
                    ["role" => "system", "content" => $prompt],
                    ["role" => "user", "content" => $description]
                ];
                $response = $llm->message($chat);
                $telegram->die_if_error($response);
                // If the response starts with "BEGIN:VCALENDAR", send as iCalendar
                if (preg_match('/^```\\n?BEGIN:VCALENDAR/', $response)) {
                    $response = preg_replace('/^```\\n?|```$/', '', $response);
                    $response = trim($response);
                }
                if (substr($response, 0, 15) == "BEGIN:VCALENDAR") {
                    $file_name = "event.ics";
                    $telegram->send_document($file_name, $response);
                } else {
                    $telegram->send_message($response);
                }
                exit;
            } else {
                $reset(false);
                $user_config_manager->add_message("system", $prompt);
                $mes = "Chat history reset. I am now a calendar bot. Give me an invitation or event description!";
                $user_config_manager->add_message("assistant", $mes);
                return $mes;
            }
        }, "Presets", "Converts an event description to iCalendar format. Provide a description with the command to preserve the previous conversation.");

        // The command /code is a programming assistant
        $command_manager->add_command(array("/code"), function($command, $query) use ($telegram, $user_config_manager, $llm, $reset) {
            $prompt = "You are a programming and system administration assistant. "
                ."If there is a lack of details, state your uncertainty and ask for clarification. Do not show any warnings or information "
                ."regarding your capabilities. Keep your response short and avoid unnecessary explanations. If you provide code, ensure it is valid.";
            // If the query is not empty, process the request one-time without saving the config
            if ($query != "") {
                $chat = $user_config_manager->get_config();
                $chat = json_decode(json_encode($chat, JSON_UNESCAPED_UNICODE));
                $chat->messages = [
                    ["role" => "system", "content" => $prompt],
                    ["role" => "user", "content" => $query]
                ];
                $response = $llm->message($chat);
                $telegram->die_if_error($response);
            } else {
                $reset(false);
                $user_config_manager->add_message("system", $prompt);
                $response = "Chat history reset. I will support you in writing code.";
                $user_config_manager->add_message("assistant", $response);
            }
            return $response;
        }, "Presets", "Programming assistant. Add a query to ignore and preserve the previous conversation.");

        // The command /typo is a typo and grammar assistant
        $command_manager->add_command(array("/typo"), function($command, $text) use ($telegram, $user_config_manager, $llm, $reset) {
            $prompt = "Please review the following scientific text and provide specific feedback on areas that could be improved. "
                ."Correct any typos, grammatical errors, or whatever else you notice. Do NOT repeat or write a corrected verison of the entire text. "
                ."Keep your answer concise and ensure the correctness of each suggestion.";
            // If the text is not empty, process the request one-time without saving the config
            if ($text != "") {
                $chat = $user_config_manager->get_config();
                $chat = json_decode(json_encode($chat, JSON_UNESCAPED_UNICODE));
                $chat->messages = [
                    ["role" => "system", "content" => $prompt],
                    ["role" => "user", "content" => $text]
                ];
                $response = $llm->message($chat);
                $telegram->die_if_error($response);
            } else {
                $reset(false);
                $user_config_manager->add_message("system", $prompt);
                $response = "Chat history reset. I am now a typo and grammar assistant.";
                $user_config_manager->add_message("assistant", $response);
            }
            return $response;
        }, "Presets", "Typo and grammar assistant. Provide a text to ignore and preserve the previous conversation.");

        // The command /task helps the user to break down a task and track progress
        $command_manager->add_command(array("/task"), function($command, $task) use ($telegram, $user_config_manager, $reset) {
            $task != "" || $telegram->die("Please provide a task with the command.");
            $reset(true);  // general prompt
            $prompt = "Your task is to help the user achieve the following goal: \"$task\". "
                ."Break down it into subtasks, negotiate a schedule, and provide live accountabilty at each step. "
                ."Avoid generic advice, but instead find specific, actionable steps. "
                ."As soon as the steps are clear, walk the user through them one by one to ensure they are completed. ";
            $user_config_manager->add_message("system", $prompt);
            $mes = "Let's break down your goal into manageable steps. I'll help you clarify the task, create a checklist, and keep you on track. What is the first thing you think needs to be done?";
            $user_config_manager->add_message("assistant", $mes);
            return $mes;
        }, "Presets", "Helps the user to break down a task and track immediate progress.");

        // The command /reframe helps the user find better reframing of their current stories
        $command_manager->add_command(array("/reframe"), function($command, $_) use ($user_config_manager, $reset) {
            $reset(false);
            $prompt = "The task is to lead me to find a better reframing of my current stories. For every new proposal I make, "
                    ."answer with a validation of it and end in the question \"Can you find a frame that is even more helpful?\"\n\n"
                    ."For example\n"
                    ."Me: \"They don't want to talk with me at all! Now they send me that person as a carrier just to avoid being in contact with me. I'm always alone, noone likes me.\"\n\n"
                    ."Answer: \"Great, you assessed your situation and identified someone contributing to the issue. Can you find a frame that is even more helpful?\"\n\n"
                    ."Me: \"I don't need them! They are just harmful to me and I don't need such negativity in my life. I still feel alone, but it's better without them.\"\n\n"
                    ."Answer: \"Wonderful how you assert your boundaries and aim for less pain and more positivity in your life! Can you find a frame that is even more helpful?\"\n\n"
                    ."Your responses should acknowledge the user's reframing efforts positively while consistently encouraging them to explore "
                    ."even more constructive perspectives. Keep responses supportive and focused on guiding the user toward increasingly helpful "
                    ."cognitive reframes. Always end with the key question: \"Can you find a frame that is even more helpful?\" or a similar alternative formulation.";
            $user_config_manager->add_message("system", $prompt);
            $mes = "I'll help you reframe your thoughts to find more helpful perspectives. Please share a situation or thought you'd like to reframe, and I'll guide you through the process.";
            $user_config_manager->add_message("assistant", $mes);
            return $mes;
        }, "Presets", "Start a cognitive reframing exercise to develop more helpful perspectives.");

        // Language tutors
        $language_tutors = [
            "cn" => [
                "name" => "Chinese",
                "prompt" => "你是一位友好且鼓励性的中文老师。请用自然的中文（简体字）与用户交流，但仅在解释语法、词义或文化时使用英文。"
                    ."每当用户用中文写作时，请用比用户稍高一级的中文回复，并附上带声调的拼音和英文翻译。"
                    ."温和地纠正用户的重要错误，并给出更好的表达建议。"
                    ."保持回答简短，专注于帮助用户在日常生活中使用中文。"
                    ."如果用户用英文写作，鼓励他们用中文表达，并只用英文讨论语言相关问题。"
                    ."偶尔指出常见错误并建议练习模式，帮助用户逐步建立阅读信心。",
                "greeting" => "你好！我是你的中文老师。请用中文写几句话，我会帮你学习。你今天想聊什么？\n(Hello! I'm your Chinese teacher. Write a few sentences in Chinese and I'll help you learn. What would you like to talk about today?)"
            ],
            "fi" => [
                "name" => "Finnish",
                "prompt" => "Olet ystävällinen ja kannustava suomen kielen opettaja. Keskustele käyttäjän kanssa luonnollisesti suomeksi,"
                    ."mutta käytä englantia vain selittääksesi kielioppia, sanojen merkityksiä tai kulttuurisia seikkoja. "
                    ."Vastaa suomeksi aina kun käyttäjä kirjoittaa suomeksi, ja lisää englanninkielinen käännös sekä tarvittaessa lyhyt selitys. "
                    ."Korjaa käyttäjän virheet lempeästi ja anna vinkkejä parempaan ilmaisuun. Pidä vastaukset lyhyinä ja keskity käytännölliseen kieleen, "
                    ."joka auttaa käyttäjää pärjäämään arjessa. Jos käyttäjä kirjoittaa englanniksi, rohkaise häntä siirtymään suomen kieleen.",
                "greeting" => "Hei! Olen suomen kielen opettajasi. Kirjoita vapaasti suomeksi, ja autan sinua oppimaan lisää. Mistä haluaisit keskustella tänään?"
            ],
            "fr" => [
                "name" => "French",
                "prompt" => "Tu es un professeur de français amical et encourageant. Discute naturellement en français avec l'utilisateur,"
                    ."mais utilise l'anglais uniquement pour expliquer la grammaire, le sens des mots ou des aspects culturels. "
                    ."Réponds toujours en français lorsque l'utilisateur écrit en français, et ajoute une traduction anglaise ainsi qu'une brève explication si nécessaire. "
                    ."Corrige les erreurs de l'utilisateur avec bienveillance et donne des conseils pour mieux s'exprimer. Garde les réponses courtes et concentre-toi sur le langage pratique "
                    ."qui aide l'utilisateur à se débrouiller au quotidien. Si l'utilisateur écrit en anglais, encourage-le à passer au français.",
                "greeting" => "Bonjour ! Je suis ton professeur de français. Écris librement en français et je t'aiderai à progresser. De quoi aimerais-tu parler aujourd'hui ?"
            ]
        ];

        foreach ($language_tutors as $key => $config) {
            $command_manager->add_command(
                ["/$key"],
                function($command, $message) use ($config, $user_config_manager, $telegram, $reset) {
                    $reset(false);
                    $user_config_manager->add_message("system", $config['prompt']);
                    if ($message == "") {
                        $user_config_manager->add_message("assistant", $config['greeting']);
                        $telegram->send_message($config['greeting']);
                        exit;
                    }
                    $user_config_manager->add_message("user", $message);
                },
                "Presets",
                "Start a {$config['name']} language tutoring session"
            );
        }

        // The command /shop helps with shopping decisions by analyzing product ingredients and nutrition labels
        $command_manager->add_command(array("/shop"), function($command, $description) use ($telegram, $user_config_manager, $reset) {
            $reset(false);
            $prompt = "You are my \"Shopping Decider.\"\n"
                ."I will send minimal text and/or a photo of a product's ingredient or nutrition label taken in a Finnish grocery store.\n"
                ."Apply these standing rules:\n"
                ."1. Highlight any major health concerns (e.g. very high sugar or salt, trans-fats, carcinogenic additives, high pesticide risk).\n"
                ."2. Flag ethical issues such as animal torture, exploitative labour, child labour, heavy pesticide use, high carbon footprint, or wasteful packaging if evident.\n"
                ."3. Always state the product's glycaemic index (GI), based on a well-sourced typical GI range for that food category and note the uncertainty.\n"
                ."4. Keep the response very concise. Do NOT offload reasoning to the user — no conditional chains, no fluff.\n"
                ."\n"
                ."Output—nothing else:\n"
                ."*<clear one-phrase pick or warning>*\n"
                ."Reasoning: <a few concise points or sentences>\n"
                ."\n"
                ."Example input:\n"
                ."> Pretzel or banana?"
                ."Example output:\n"
                ."*Pick the banana*"
                ."Reasoning: No additives or excessive salt, plus potassium and fiber, while pretzels are refined-flour with very high sodium and GI. Some plantation labour issues exist but still preferable to processed wheat snack. GI ≈ 50–60 (medium).";
            $user_config_manager->add_message("system", $prompt);

            if ($description != "") {
                $user_config_manager->add_message("user", $description);
            }
            else {
                $mes = "Shopping Decider ready. What decision do you need to make?";
                $user_config_manager->add_message("assistant", $mes);
                return $mes;
            }
        }, "Presets", "Helps to make quick shopping decisions.");


        // The command /anki adds a command to create an Anki flashcard from the previous text
        $command_manager->add_command(array("/anki"), function($command, $topic) use ($user_config_manager) {
            // Prompt the model to write an Anki flashcard
            $prompt = "Your task is to write an Anki flashcard. Provide a concise summary with highlights of key words or phrases. "
                      ."Use HTML, but write it in one line (since Anki automatically converts newlines into <br> tags) and avoid <div> tags."
                      ."Use <b> tags for bold text and <i> tags for italic text. Use lists if appropriate.";
            $user_config_manager->add_message("system", $prompt);
            // Create a prompt on behalf of the user
            if ($topic == "") {
                $user_message = "Please create an Anki card of this.";
            } else {
                $user_message = "Please create an Anki card about $topic.";
            }
            $user_config_manager->add_message("user", $user_message);
            // request a response from the model
        }, "Shortcuts", "Request an Anki flashcard based on the previous conversation. You can provide a message with the command to clarify the topic.");

        // The command /todo is a shortcut to extract actionable items out of the previous conversation
        $command_manager->add_command(array("/todo"), function($command, $info) use ($user_config_manager) {
            $source_str = "";
            $n_messages = count($user_config_manager->get_config()->messages);
            if ($n_messages > 1 && $info != "") {
                $source_str .= "the previous conversation and further information by the user given below";
            }
            else if ($n_messages > 1) {
                $source_str .= "the previous conversation";
            }
            else if ($info != "") {
                $source_str .= "the information by the user given below";
            }
            // Prompt the model to write a todo list
            $prompt = "Please create a consolidated list of specific, actionable items based on $source_str. "
                    ."Keep the points concise, but specific and informative. "
                    ."If there is important information missing, don't provide an actual answer, but instead ask for what you'd need to know first.";
            if ($info != "") {
                $prompt .= " Here is further information given by the user:\n\n$info";
            }
            $user_config_manager->add_message("system", $prompt);
            // request a response from the model
        }, "Shortcuts", "Extract a list of actionable items from the previous conversation. You can provide a message with the command for more information.");

        // The command /mail builds a mail based on the previous conversation
        $command_manager->add_command(array("/mail"), function($command, $topic) use ($user_config_manager) {
            // Prompt the model to write a mail
            $prompt = "Your task is to prepare a mail based on the previous conversation using the template below. "
                    ."Keep your response concise and compact. Your voice is casual and kind. "
                    ."In case the user requests a relative date, today is ".date("l, j.n").". "
                    ."Please don't write any other text than the mail itself, so it can be parsed easily. "
                    ."Use \".".$user_config_manager->get_name()." as sender name.\"\n\n"
                    ."MAIL\n"
                    ."To: [recipient]\n"
                    ."Subject: [subject]\n\n"
                    ."Body:\n[body]";
            if ($topic != "")
                $user_config_manager->add_message("user", $topic);
            $user_config_manager->add_message("system", $prompt);
            // request a response from the model
        }, "Shortcuts", "Create a mail based on the previous conversation. You can provide a message with the command to clarify the request.");

        // The command /search enables web search for a single query
        $command_manager->add_command(array("/search"), function($command, $query) use ($telegram, $user_config_manager, $llm) {
            $user_config_manager->add_message("user", $query ?: "Please perform the web search.");

            $chat = $user_config_manager->get_config();
            $response = $llm->message($chat, true);
            $telegram->die_if_error($response, $user_config_manager);

            $user_config_manager->add_message("assistant", $response);
            if (is_array($response)) { // we're storing claude websearches in all detail, so convert it here for output
                $response = text_from_claude_websearch($response, $user_config_manager->is_post_processing());
            }
            return $response;
        }, "Shortcuts", "Perform a web search based on the chat history (and optional additional query).");

        // The command /p generates a search prompt for research tools
        $command_manager->add_command(array("/p"), function($command, $query) use ($telegram, $user_config_manager, $llm) {
            // Require a query if the chat history is empty
            $config = $user_config_manager->get_config();
            if ($query == "" && count($config->messages) <= 2) {
                $telegram->die("There is no chat history yet. Please provide a query or context after /p.");
            }

            // Prompt the model to generate a search query
            $prompt = "Your task is to create an optimized search query based on the previous conversation";
            $query === "" || $prompt .= " and the following additional context: \"$query\"";
            $prompt .= ". Focus on extracting the key information needs and formulating a clear, specific query. "
                    ."If the conversation covered multiple topics, focus on the last topic discussed. "
                    ."Your query should be well-structured for AI search and research tools like Perplexity AI and Elicit. "
                    ."Be concise but comprehensive, capturing the essential research intent. "
                    ."Your response should ONLY contain the query itself, formatted like this: "
                    ."```txt\n"
                    ."<research query>\n"
                    ."```";

            if (count($config->messages) === 1 && $config->messages[0]->role === 'system' && strpos($config->model, "claude-") === 0) {
                // With Claude, do not integrate $prompt into the system message
                $user_config_manager->add_message("user", $prompt);
            } else {
                $user_config_manager->add_message("system", $prompt);
            }

            // Process the response from the model
            $response = $llm->message($config);
            $telegram->die_if_error($response, $user_config_manager);
            // Replace system call by informing the model about the query
            $user_config_manager->delete_messages(1);
            if ($query !== "") {
                $user_config_manager->add_message("user", "Search query with this additional context: $query");
            } else {
                $user_config_manager->add_message("user", "Search query based on the previous conversation.");
            }
            $user_config_manager->add_message("assistant", $response);

            // Only add "Search with" if response starts with ```
            if (strpos($response, '```') === 0) {
                $request_encoded = urlencode(explode("\n", $response)[1]);
                $google = "[Google](https://www.google.com/search?q=$request_encoded)";
                $response = "$response\n\nSearch with: [Perplexity AI](https://www.perplexity.ai/) | $google | /papers | /search";
                // TODO: Add Kagi? If I want to use Kagi and the API is out of beta
            }
            return $response;
        }, "Shortcuts", "Generate a search query based on the conversation. You can provide additional context with the command.");

        // The command /papers fetches papers from Semantic Scholar based on a search query and returns a summary
        $command_manager->add_command(array("/papers"), function($command, $query) use ($telegram, $llm, $user_config_manager, $global_config_manager, $DEBUG) {
            $config = $user_config_manager->get_config();

            // Check if there's chat history
            if (count($config->messages) > 2) {
                $context_prompt = "Based on the conversation history";
                // If query is provided, use it as additional context, otherwise use chat history only
                $query === "" || $context_prompt .= " and considering this additional context: \"$query\"";
                $context_prompt .= ", create an optimized search query for the Semantic Scholar API by following these guidelines:\n".
                                  "\n1. Extract exactly 2-4 key concepts that represent the core research question\n".
                                  "2. Use natural language phrasing to allow for terminology variations\n".
                                  "3. Separate terms with spaces (Semantic Scholar's default behavior handles term relationships well)\n".
                                  "4. For temporal filtering, use specific year ranges when relevant (e.g., 'since 2020', '2023-2024')\n".
                                  "5. For compound technical concepts:\n".
                                  "   - Keep them as single units when they represent established specialized fields (e.g., 'reinforcement learning')\n".
                                  "   - Consider separating them when the research spans multiple fields rather than their specific intersection\n".
                                  "6. Use established terminology over cutting-edge terms that might not be widely adopted in the literature yet\n".
                                  "7. When creating the query, identify whether the primary focus is on domain knowledge or methodology, and ensure the query reflects this primary focus\n".
                                  "8. Use the most specific level of terminology that accurately captures the research question while still being well-represented in academic literature\n".
                                  "\nReturn ONLY the search query with no explanations or additional text.";

                $user_config_manager->add_message("system", $context_prompt);
                $config = $user_config_manager->get_config();
                $query = $llm->message($config);
                array_pop($config->messages);  // Remove the system prompt after generating the query
                $telegram->die_if_error($query);

                $telegram->send_message("Searching for papers about: $query");
            } else if ($query === "") {
                $telegram->die("Please provide a search query after the command, like `/papers <query>`");
            }

            // Get API key from global config manager if available
            $api_key = $global_config_manager->get("SEMANTIC_SCHOLAR_API_KEY");
            $papers = $llm->semantic_scholar_search($query, 10, $api_key);
            $user_config_manager->add_message("user", "Find papers about: $query");
            $telegram->die_if_error($papers, $user_config_manager);
            if (empty($papers)) {
                $mes = "No papers found for your query.";
                $user_config_manager->add_message("assistant", $mes);
                $telegram->die($mes);
            }

            // Build the paper list message and abstracts with references in a single loop
            $abstracts = [];
            $papers_list = "";
            foreach ($papers as $i => $paper) {
                $n = $i + 1;
                $ref = $paper['url'] ? "[[$n]({$paper['url']})]" : "[$n]";
                $pdf_link = $paper['pdfUrl'] ? " ([PDF]({$paper['pdfUrl']}))" : "";
                $line = "{$ref} (*{$paper['citationCount']}* cit.) {$paper['authors']} ({$paper['year']}) *{$paper['title']}*{$pdf_link}\n\n";
                if ($paper['abstract']) {
                    $abstracts[] = "$ref {$paper['title']}\n{$paper['abstract']}";
                }
                $papers_list .= $line;
            }

            if (!empty($abstracts)) {
                // Use the existing papers list
                $abstracts_text = "Here are abstracts from several academic papers related to the search query:\n\n" .
                    "$papers_list.\n\n".implode("\n\n", $abstracts)."\n\n".
                    "Please provide a comprehensive synthesis of the key findings, methodologies, and insights from these papers. " .
                    "Consider how they relate to each other, any contradictions or consensus between them, and their significance to the field. " .
                    "When referring to specific papers, use their corresponding number in brackets (e.g., [1], [2]) as references. " .
                    "Focus on the most important contributions and insights rather than summarizing each paper separately. " .
                    "Your response should contain ONLY the synthesis itself, with no additional prefatory remarks or meta-commentary.";
                $user_config_manager->add_message("user", $abstracts_text);

                // Ask the model to summarize
                $config = $user_config_manager->get_config();
                $summary = $llm->message($config);
                $user_config_manager->delete_messages(1);  // Remove the abstracts message from the history
                $telegram->die_if_error($summary, $user_config_manager);  // Remove "Find papers about..." message

                // Add the original query as a user message to maintain conversation flow
                $response = "$summary\n\n$papers_list";
            } else {
                // Only send the paper list if there are no abstracts
                $response = "Results for: **$query**\n\n.$papers_list";
            }
            $user_config_manager->add_message("assistant", $response);
            return $response;
        }, "Shortcuts", "Search Semantic Scholar for academic papers and summarize abstracts.");

        // The command /papersupdate fetches and summarizes recent papers relevant to a given topic
        $command_manager->add_command(array("/papersupdate"), function($command, $args) use ($telegram, $user_config_manager, $llm, $shortcuts_large, $shortcuts_medium) {
            $args = explode(" ", $args, 3);
            $today = new DateTime();
            $dt = DateTime::createFromFormat('Y-m-d', $args[0]);
            if ($dt) array_shift($args);
            else $dt = $today;
            $date = $dt->format('Y-m-d');
            $dt <= $today || $telegram->die("The date provided \"$date\" is in the future. Please provide a date that is in the past.");
            $days = $args[0] ?: 7;
            $args[1] ?? $telegram->die("Please provide a category.");
            $category = $args[1];
            $topic = $args[2] ?? "general";
            if (!ctype_digit((string)$days) || (int)$days <= 0)
                $telegram->die("Please provide a positive integer for the number of days.");
            $days = (int)$days;
            // 1. Fetch latest papers from SciRate
            $max_pages = 20;
            $results = fetch_and_parse_scirate($category, $date, $days, $max_pages);
            $telegram->die_if_error($results);
            $max_pages_reached = $results['max_pages_reached'];
            $results = $results['papers'];
            // Reject if there are more than 500 papers
            $chat = $user_config_manager->get_config();
            // Paper limit for large models
            if (in_array($chat->model, array_values($shortcuts_large)) and count($results) > 500)
                $telegram->die("There are more than 500 papers in this time window. Please choose a shorter time window or a smaller model.");
            if ($max_pages_reached)
                $telegram->send_message("Warning: Only the first $max_pages pages are shown to prevent excessive requests.");

            // 2. Prepare a list of papers for the LLM
            $paper_list = [];
            foreach ($results as $arxiv_id => $paper) {
                $title = $paper['title'];
                $authors = $paper['authors'];
                $paper_list[] = "- [arXiv:$arxiv_id](https://arxiv.org/abs/$arxiv_id): \"$title\" by $authors";
            }

            // Smart phrasing for date range
            if ($date === $today->format('Y-m-d')) {
                if ($days == 1) {
                    $range_phrase = "today";
                } else if ($days == 2) {
                    $range_phrase = "since yesterday";
                } else {
                    $range_phrase = "for the last $days days";
                }
            } else {
                $interval = $today->diff($dt);
                $days_ago = $interval->days;
                $date_str = "$date ($days_ago days ago)";

                if ($days == 1) {
                    $range_phrase = "on $date_str";
                } else {
                    $prev_date = (clone $dt)->modify("-$days days")->format('Y-m-d');
                    $range_phrase = "from $prev_date until $date_str";
                }
            }

            if (empty($paper_list)) {
                $telegram->die("No new papers found on SciRate $range_phrase.");
            }
            $n_papers = count($paper_list);
            $telegram->send_message("Found $n_papers new papers on SciRate $range_phrase. Filtering for relevance for $topic...");

            $papers_text = implode("\n", $paper_list);

            // 3. Prompt the LLM to filter for relevant papers by arXiv ID only
            $filter_prompt =
               "You are an expert in $topic. Here is a list of new quantum physics papers from arXiv/SciRate $range_phrase, each on its own line in the format:\n"
                . "<arxivid>: <authors> \"<title>\"\n\n"
                . $papers_text . "\n\n"
                . "Please return only the arXiv IDs (one per line) of those that may be relevant in $topic. Do not include any other text before or after. If none are relevant, return 'NONE'.";

            $chat = (object) array(
                "model" => $chat->model,
                "temperature" => $chat->temperature,
                "messages" => [["role" => "user", "content" => $filter_prompt]]
            );
            $llm_response = $llm->message($chat);
            $telegram->die_if_error($llm_response);
            // $telegram->send_message("Raw LLM output:\n\n" . $llm_response);

            $filtered_ids = array_filter(array_map('trim', explode("\n", trim($llm_response))));
            $filtered_ids = array_filter($filtered_ids, function($id) {
                return preg_match('/^([0-9]{4}\.[0-9]{4,5}|[a-z\-]+\/[0-9]{7})$/', $id);
            });
            $filtered_ids = array_values(array_unique($filtered_ids));

            if (!empty($filtered_ids)) {
                // Build a numbered list of filtered papers
                $n_filtered = count($filtered_ids);
                $abstract_limit = 20;

                $idx = 1;
                $numbered_for_llm = "";
                $numbered_for_user = "";
                $filtered_ids_set = array_flip($filtered_ids);
                foreach ($results as $arxiv_id => $paper) {
                    if (!isset($filtered_ids_set[$arxiv_id])) {
                        continue;
                    }
                    $authors = $paper['authors'];
                    $title = $paper['title'];
                    if ($idx <= $abstract_limit) {
                        $abstract = $paper['abstract'];
                        $numbered_for_llm .= "[$idx] $authors \"$title\"\nAbstract: $abstract\n\n";
                    } else {
                        $numbered_for_llm .= "[$idx] $authors \"$title\"\n";
                    }
                    if ($idx <= 42) {
                        $numbered_for_user .= "[[$idx](https://arxiv.org/abs/$arxiv_id)] $authors \"$title\" ([pdf](https://arxiv.org/pdf/$arxiv_id.pdf))\n";
                    }
                    $idx++;
                }
                $numbered_for_user = trim($numbered_for_user);

                // Inform user how many papers remain after filtering
                $telegram->send_message("After filtering, ".($idx-1)." papers remain as relevant:\n$numbered_for_user");

                // 4. Ask the LLM for a summary referencing the numbers and abstracts for the first $abstract_limit papers
                $summary_prompt =
                    "You are an expert in $topic. Here is a numbered list of relevant papers $range_phrase. For the first $abstract_limit papers, the abstract is included; for the rest, only the title and authors are shown:\n\n"
                    ."$numbered_for_llm\n"
                    ."Important: Only use information that is explicitly present in the abstracts and titles above. Do not speculate or infer any details that are not directly stated. If an abstract does not mention a detail, do not assume or guess it.\n\n"
                    ."Please summarize any notable updates or trends based on these papers, referencing the numbers in your summary. "
                    ."Write your summary in prose, not as a list. Be concise and focus on what is new or interesting for the community."
                    ."End with a one-sentence recommendation which papers may be worth checking out.";

                $chat->messages = [["role" => "user", "content" => $summary_prompt]];
                $mes = $llm->message($chat);
                $telegram->die_if_error($mes);
            } else {
                $mes = "No relevant papers found for the selected period.";
            }
            $user_config_manager->add_message("system", "Summarize the papers $range_phrase in arXiv category $category relevant in $topic.");  // pseudo-prompt
            $user_config_manager->add_message("assistant", $mes);
            return $mes;
        }, "Shortcuts", "Fetches the latest topic-relevant papers and summarizes updates. Usage: `/papersupdate <days> <arXiv category> <topic>`.");

        $command_manager->add_command(array("/summary"), function($command, $prompt) use ($telegram, $user_config_manager, $llm) {
            $chat = $user_config_manager->get_config();
            count($chat->messages) > 3 || $telegram->die("There's not enough chat history to summarize.");
            $chat_original = json_decode(json_encode($chat, JSON_UNESCAPED_UNICODE));

            $telegram->send_message("Creating a summary of the conversation...");

            // Create a summary request to the AI
            $summary_request = "Please provide a concise summary of the conversation so far. Include key points, decisions, and important information that was discussed. Format the summary to be clear and well-organized.";
            empty($prompt) || $summary_request .= " Focus especially on: $prompt";
            $user_config_manager->add_message("user", $summary_request);
            $summary_response = $llm->message($chat);
            $telegram->die_if_error($summary_response, $user_config_manager);

            // Backup
            $user_config_manager->save_session("last", $chat_original);
            // Clear all messages, except potential initial system prompt
            $chat->messages = array_slice($chat_original->messages, 0, (int)($chat_original->messages[0]->role === "system"));

            // Add the summary as a system message
            $user_config_manager->add_message("system", $summary_response);
            $telegram->send_message("/system $summary_response");
            return "_(Previous conversation saved as 'last'. You can restore it with /restore.)_";
        }, "Chat history management", "Summarize the conversation, save current chat in 'last', and start fresh with the summary. Optional: add focus area for summary.");

        // TODO !!! Add more presets here !!!

        // ##########################
        // ### Commands: Settings ###
        // ##########################

        // The command /model shows the current model and allows to change it
        $command_manager->add_command(array_merge(
            array("/model"),
            array_keys($shortcuts_medium),
            array_keys($shortcuts_small),
            array_keys($shortcuts_large)
        ), function($command, $model) use ($telegram, $user_config_manager, $reset,
            $shortcuts_medium, $shortcuts_small, $shortcuts_large) {
            $chat = $user_config_manager->get_config();
            if (isset($shortcuts_medium[$command])) {
                $model = $shortcuts_medium[$command];
            } else if (isset($shortcuts_small[$command])) {
                $model = $shortcuts_small[$command];
            } else if (isset($shortcuts_large[$command])) {
                $model = $shortcuts_large[$command];
            }
            if ($model == "") {
                $telegram->send_message("You are currently talking to `$chat->model`.\n\n"
                ."You can change the model by providing the model name after the /model command. "
                ."The following shortcuts are available:\n\n"
                .implode("\n", array_map(function($key, $value) {
                    return "$key → `$value`";
                }, array_keys($shortcuts_medium), $shortcuts_medium))."\n\n"
                ."for some smaller and cheaper models:\n"
                .implode("\n", array_map(function($key, $value) {
                    return "$key → `$value`";
                }, array_keys($shortcuts_small), $shortcuts_small))."\n\n"
                ."for the largest and most capable models:\n"
                .implode("\n", array_map(function($key, $value) {
                    return "$key → `$value`";
                }, array_keys($shortcuts_large), $shortcuts_large))."\n\n"
                ."Other options are other [OpenRouter models](https://openrouter.ai/models), "
                ."[Anthropic models](https://docs.anthropic.com/en/docs/about-claude/models), "
                ."and [OpenAI models](https://platform.openai.com/docs/models) ([pricing](https://platform.openai.com/docs/pricing)).");
            } else if ($chat->model == $model) {
                $telegram->send_message("You are already talking to `$chat->model`.");
            } else {
                $chat->model = $model;
                $telegram->send_message("You are now talking to `$chat->model`.");
            }
            if (count($chat->messages) == 1 && $chat->messages[0]->role == "system") {  // assume intro prompt
                $reset(true, false);
            }
            exit;
        }, "Settings", "Model selection (default: `".UserConfigManager::$default_config["model"]."`)");

        // The command /temperature shows the current temperature and allows to change it
        $command_manager->add_command(array("/temperature", "/temp"), function($command, $temperature) use ($user_config_manager) {
            $chat = $user_config_manager->get_config();
            if (is_numeric($temperature)) {
                $temperature = floatval($temperature);
                if ($temperature < 0 && $temperature > 2)
                    return "Temperature must be between 0 and 2.";
                $chat->temperature = $temperature;
                return "Temperature set to $chat->temperature.";
            }
            return "Temperature is currently set to $chat->temperature. To set the temperature, you can provide a number between 0 and 2 with the command.";
        }, "Settings", "Show the current temperature or change it (default: ".UserConfigManager::$default_config["temperature"].")");

        // The command /thikingoutput fetches the last thinking output
        $command_manager->add_command(array("/thinkingoutput", "/thinkingoutputraw"), function($command, $_) use ($telegram, $user_config_manager) {
            $thinking_output = $user_config_manager->get_last_thinking_output();
            $thinking_output != "" || $telegram->die("No thinking output available.");
            $telegram->send_message($thinking_output, $command == "/thinkingoutput");
            exit;
        }, "Settings", "Fetch the last thinking output");

        // The command /postprocessing prompts the bot to post-process the message
        $command_manager->add_command(array("/postprocessing"), function($command, $_) use ($user_config_manager) {
            $active = $user_config_manager->toggle_post_processing();
            return "Post processing ".($active ? "activated" : "deactivated").".";
        }, "Settings", "Post-processing of text (especially for equations)");

        // The command /auditing toggles the response auditing feature
        $command_manager->add_command(array("/auditing"), function($command, $_) use ($user_config_manager) {
            $active = $user_config_manager->toggle_auditing();
            return "Response auditing ".($active ? "activated" : "deactivated").".";
        }, "Settings", "Toggle auditing of responses against system guidance");

        // The command /name allows the user to change their name
        $command_manager->add_command(array("/name"), function($command, $name) use ($telegram, $user_config_manager) {
            $name != "" || $telegram->die("Your name is currently set to ".$user_config_manager->get_name().". To change it, provide a name with the command.");
            $user_config_manager->set_name($name);
            return "Your name has been set to $name.";
        }, "Settings", "Set your name");

        // The command /timezone allows the user to change their timezone
        $command_manager->add_command(array("/timezone"), function($command, $timezone) use ($telegram, $user_config_manager) {
            $timezone != "" || $telegram->die("Your timezone is currently set to \"".$user_config_manager->get_timezone()."\". To change it, please provide a timezone with the command, e.g. `/timezone Europe/Berlin`.", true);
            // Validate the timezone
            try {
                new DateTimeZone($timezone);
            } catch (Exception $e) {
                $telegram->die("The timezone \"$timezone\" is not valid. Please provide a valid timezone, e.g. `/timezone Europe/Berlin`.", true);
            }
            $user_config_manager->set_timezone($timezone);
            return "Your timezone has been set to \"$timezone\".";
        }, "Settings", "Set your timezone");

        // The command /lang allows the user to change their language
        $command_manager->add_command(array("/lang"), function($command, $lang) use ($telegram, $user_config_manager) {
            $lang != "" ||
                $telegram->die("Your language is currently set to \"".$user_config_manager->get_lang()."\". "
                ."To change it, provide an [ISO 639-1 language code](https://en.wikipedia.org/wiki/List_of_ISO_639-1_codes) (2 letters) with the command, e.g. \"/lang en\"."
                ."This property is only used for voice transcription.");
            // Ensure $lang is ISO 639-1
            strlen($lang) == 2 || $telegram->send_message("The language code must be [ISO 639-1](https://en.wikipedia.org/wiki/List_of_ISO_639-1_codes) (2 letters).");
            $user_config_manager->set_lang($lang);
            return "Your language has been set to \"$lang\".";
        }, "Settings", "Set your language");

        // The command /intro allows to read out or set the intro prompt
        $command_manager->add_command(array("/intro"), function($command, $message) use ($telegram, $user_config_manager, $default_intro, $llm) {
            if (empty($message)) {
                // Return overview of configured intro prompts
                $intros = $user_config_manager->get_intros();
                if (empty($intros)) {
                    $stats = get_message_stats($default_intro);
                    $telegram->die("You don't have any custom intro prompts configured. You're using the general default prompt:\n\n`/intro $default_intro`\n\n({$stats['words']} words, {$stats['tokens']} tokens)");
                }

                $overview = "Your configured intro prompts:\n\n";
                foreach ($intros as $key => $value) {
                    $stats = get_message_stats($value);
                    $first_words = substr($value, 0, 40) . (strlen($value) > 40 ? "..." : "");
                    $overview .= "*$key*: \"$first_words\" ({$stats['words']} words, {$stats['tokens']} tokens)\n";
                }
                $overview .= "\nUse `/intro <specifier>` to see the full prompts.";
                return $overview;
            }

            // unpack model and message
            $parts = explode(' ', $message, 2);
            $model = $parts[0];
            if ($model == "prefix" || $model == "default" || $llm->check_model($model)) {
                $message = $parts[1] ?? "";
            } else {
                // $telegram->die("`$model` is not a valid specifier. Please provide \"default\", \"prefix\" or a (partial) model name.", true);
                $model = "default";  // leave $message as is
            }
            $prefix_mes = "";
            if (!empty($user_config_manager->get_intro("prefix"))) {
                $prefix_mes = " with a custom prefix (read out with `/intro prefix`)";
            }
            // assert !empty($model)

            // special case for prefix and default
            if ($message == "reset" && !($model == "default" || $model == "prefix")) {
                $user_config_manager->set_intro("", $model);
                return "Your intro prompt for model `$model` has been reset.";
            }
            if ($model == "prefix" || $model == "default" || $model == "reset") {
                $is_prefix = ($model == "prefix");
                $type_label = $is_prefix ? "intro prefix" : "default intro prompt";
                $reset_action = $is_prefix ? "delete it" : "revert to the general default prompt";

                // Handle reset or set new value
                if ($message == "reset") {
                    $user_config_manager->set_intro("", $model);
                    return "Your $type_label has been reset. You can set a new $type_label by providing it as `/intro $model <your prompt>`.";
                } elseif ($message != "") {
                    $user_config_manager->set_intro($message, $model);
                    $stats = get_message_stats($message);
                    return "Your $type_label has been set ({$stats['words']} words, {$stats['tokens']} tokens).\n\nYou can change it by providing a new $type_label with the command `/intro $model <new $type_label>`. Use `/intro $model reset` to $reset_action.";
                }

                // Display current value
                $current_value = $user_config_manager->get_intro($model);
                if (empty($current_value)) {
                    $general = $is_prefix ? "" : "You are using the general default introductory system prompt$prefix_mes:\n\n`/intro $default_intro`\n\n";
                    return "You don't have a custom $type_label set. {$general}To set a custom $type_label, you can use the command `/intro $model <your prompt>`";
                } else {
                    $intro_display = "/intro $model $current_value";
                    if (strpos($intro_display, "`") === false) $intro_display = "`$intro_display`";
                    return "You've set a custom $type_label".($is_prefix ? "" : $prefix_mes).":\n\n$intro_display\n\n"
                        ."You can change it by providing a new $type_label with the command `/intro $model <new $type_label>`. Use `/intro $model reset` to $reset_action.";
                }
            }

            if (empty($message)) {
                $intro = $user_config_manager->get_intro($model);
                if (empty($intro)) {
                    $default_intro_user = $user_config_manager->get_intro("default");
                    if (empty($default_intro_user)) return "You don't have a specific intro prompt for model `$model` nor a custom default intro prompt, so you're using the general default prompt$prefix_mes:\n```\n$default_intro\n```\n\n"
                            ."To set a model-specific or default intro prompt, please provide it with the command as `/intro $model <your prompt>` or `/intro default <your prompt>`. Use `/intro $model reset` or `/intro default reset` to revert your changes.";
                    return "You don't have a specific intro prompt for model `$model`, so you're using your custom default intro prompt (read out with `/intro default`)$prefix_mes. "
                        ."To set a model-specific intro prompt, provide it with `/intro $model <your prompt>`. Use `/intro $model reset` to revert to the default intro prompt.";
                }
                $intro_display = "/intro $model $intro";
                if (strpos($intro_display, "`") === false) $intro_display = "`$intro_display`";
                return "You're using a custom intro prompt for model `$model`$prefix_mes:\n\n$intro_display\n\nYou can change it by providing a new prompt with the command as `/intro $model <new prompt>`. Use `/intro $model reset` to revert to the default prompt.";
            }
            // assert !empty($model) and !empty($message)

            $user_config_manager->set_intro($message, $model);
            $stats = get_message_stats($message);
            return "Your intro prompt for model `$model` has been updated ({$stats['words']} words, {$stats['tokens']} tokens).";
        }, "Settings", "Set your initial system prompt");

        // The command /hellos allows to read out or set the hello messages
        $command_manager->add_command(array("/hellos"), function($command, $message) use ($telegram, $user_config_manager) {
            if ($message == "") {
                $hellos = $user_config_manager->get_hellos();
                if (count($hellos) == 0) return "You have not set any hello messages yet. To set your hello messages, you can provide a list of messages with the command, for example:\n```\n/hellos\nHave a wonderful day!\nSee you later!\n```"
                        ."from which one is drawn at random at every `/reset`. Use `/hellos reset` to revert to no hello messages.";
                $message = "/hellos\n";
                foreach ($hellos as $hello) {
                    $message .= $hello."\n";
                }
                $telegram->send_message($message);
                return "You can change your hello messages by providing a list of messages with the /hellos command.\n\nFor example:\n```\n/hellos\nHave a wonderful day!\nSee you later!\n```\n\nUse `/hellos reset` to have no hello messages.";
            }
            // if message is "reset", reset the hellos
            else if ($message == "reset") {
                $user_config_manager->set_hellos(array());
                return "Your hello messages have been reset.";
            }
            // split $message by newlines and set the hellos
            $hellos = explode("\n", $message);
            // Filter out empty strings
            $hellos = array_filter($hellos, function($hello) {
                return $hello != "";
            });
            $user_config_manager->set_hellos($hellos);
            return "Your hello messages have been set to:\n\n$message";
        }, "Settings", "Set your hello messages");

        // The command /openaiapikey allows the user to set their custom OpenAI API key
        $command_manager->add_command(array("/openaiapikey"), function($command, $key) use ($telegram, $user_config_manager) {
            $key != "" || $telegram->die("Provide an API key with the command, e.g. `/openaiapikey abc123`.");
            $user_config_manager->set_openai_api_key($key);
            return "Your new OpenAI API key has been set.";
        }, "Settings", "Set your OpenAI own API key");

        // The command /anthropicapikey allows the user to set their custom Anthropic API key
        $command_manager->add_command(array("/anthropicapikey"), function($command, $key) use ($telegram, $user_config_manager) {
            $key != "" || $telegram->die("Provide an API key with the command, e.g. `/anthropicapikey abc123`.");
            $user_config_manager->set_anthropic_api_key($key);
            return "Your new Anthropic API key has been set.";
        }, "Settings", "Set your Anthropic API key");

        // The command /openrouterapikey allows the user to set their custom OpenRouter API key
        $command_manager->add_command(array("/openrouterapikey"), function($command, $key) use ($telegram, $user_config_manager) {
            $key != "" || $telegram->die("Provide an API key with the command, e.g. `/openrouterapikey abc123`.");
            $user_config_manager->set_openrouter_api_key($key);
            return "Your new OpenRouter API key has been set.";
        }, "Settings", "Set your OpenRouter API key");

        // ###############################
        // ### Chat history management ###
        // ###############################

        // The command /clear clears the chat history
        $command_manager->add_command(array("/d", "/delall", "/clear"), function($command, $_) use ($telegram, $user_config_manager) {
            $user_config_manager->clear_messages();
            return "Chat history cleared.";
        }, "Chat history management", "Clear the internal chat history");

        // The command /delete deletes the last n messages, or the last message if no number is provided
        $command_manager->add_command(array("/del"), function($command, $n) use ($telegram, $user_config_manager) {
            $n = $n ?: 1;
            is_numeric($n) || $telegram->die("Please provide a number of messages to delete.");
            $n = intval($n);
            $n > 0 || $telegram->die("You can only delete a positive number of messages.");
            $n_messages = count($user_config_manager->get_config()->messages);
            $n = $user_config_manager->delete_messages($n);
            if ($n == 0)
                return "There are no messages to delete.";
            else if ($n == $n_messages)
                return "All $n messages deleted.";
            return "Deleted the last $n messages.";
        }, "Chat history management", "Delete the last message from the internal chat history. You can delete multiple messages by adding a number (e.g. \"/del 3\" to delete the last 3 messages).");

        // The command /user adds a user message to the chat history
        $command_manager->add_command(array("/user", "/u"), function($command, $message) use ($telegram, $user_config_manager) {
            $message != "" || $telegram->die("Please provide a message to add.");
            $user_config_manager->add_message("user", $message);
            return "Added user message to chat history.";
        }, "Chat history management", "Add a message with \"user\" role to the internal chat history");

        // The command /assistant adds an assistant message to the chat history
        $command_manager->add_command(array("/assistant", "/a"), function($command, $message) use ($telegram, $user_config_manager) {
            $message != "" || $telegram->die("Please provide a message to add.");
            $user_config_manager->add_message("assistant", $message);
            return "Added assistant message to chat history.";
        }, "Chat history management", "Add a message with \"assistant\" role to the internal chat history");

        // The command /system adds a system message to the chat history
        $command_manager->add_command(array("/system", "/s"), function($command, $message) use ($telegram, $user_config_manager) {
            $message != "" || $telegram->die("Please provide a message to add.");
            $user_config_manager->add_message("system", $message);
            return "Added system message to chat history.";
        }, "Chat history management", "Add a message with \"system\" role to the internal chat history");

        // The command /save saves the chat history to a given session name
        $command_manager->add_command(array("/save"), function($command, $session) use ($telegram, $user_config_manager) {
            $session != "" || $telegram->die("Please provide a session name with the command.");
            $config = $user_config_manager->get_config();
            $user_config_manager->save_session($session, $config);
            $stats = get_message_stats($config->messages);
            return "Chat history saved as `$session` ({$stats['messages']} messages, {$stats['words']} words ≈ {$stats['tokens']} tokens)";
        }, "Chat history management", "Save the chat history to a session");

        // The commands /restore and /load load a saved session
        $command_manager->add_command(array("/restore", "/load"), function($command, $session) use ($telegram, $user_config_manager) {
            $session = $session ?: "last";
            // Load the session
            $new = $user_config_manager->get_session($session);
            $new !== null || $telegram->die("Session `$session` not found. Use command /sessions to see available sessions. Chat history not changed.");
            $user_config_manager->save_session();
            $user_config_manager->save_config($new);
            if ($command == "/restore")
                $user_config_manager->delete_session($session);
            $stats = get_message_stats($new->messages);
            return "Session `$session` loaded ({$stats['messages']} messages, {$stats['words']} words ≈ {$stats['tokens']} tokens). You are talking to `$new->model`.";
        }, "Chat history management", "Load a saved session (/restore deletes it after restoring, while /load keeps it)");

        // The command /sessions lists all available sessions
        $command_manager->add_command(array("/sessions"), function($command, $_) use ($user_config_manager) {
            $sessions = $user_config_manager->get_sessions();
            $message = "Available sessions:\n";
            // Print session names and number of messages
            foreach ($sessions as $name => $config) {
                $stats = get_message_stats($config->messages);
                $message .= "- `$name` ({$stats['messages']} messages, {$stats['words']} words ≈ {$stats['tokens']} tokens)\n";
            }
            // Add stats of the current session
            $stats = get_message_stats($user_config_manager->get_config()->messages);
            $message .= "\nCurrent session: {$stats['messages']} messages, {$stats['words']} words ≈ {$stats['tokens']} tokens\n";
            return $message;
        }, "Chat history management", "List all available sessions");

        // The command /drop deletes a session
        $command_manager->add_command(array("/drop"), function($command, $session) use ($telegram, $user_config_manager) {
            $session != "" || $telegram->die("Please provide a session name with the command.");
            if ($user_config_manager->delete_session($session))
                return "Session `$session` deleted.";
            return "Session `$session` not found.";
        }, "Chat history management", "Delete a session");

        // The command /remove_appendix removes the appendix section from the previous arXiv extraction message if present
        $command_manager->add_command(array("/remove_appendix"), function($command, $query) use ($telegram, $user_config_manager) {
            $config = $user_config_manager->get_config();
            $messages = $config->messages;
            empty($messages) && $telegram->die("No previous messages found.");
            // Only allow if the last message is an arXiv extraction message
            $last_msg = end($messages)->content;
            if (!is_string($last_msg) || !preg_match('/^arXiv:\d{4}\.\d{4,5}/s', $last_msg)) {
                $telegram->die("The last message is not an arXiv extraction.");
            }
            // Remove appendix if present
            $filtered = preg_replace('/\\\\begin\s*{\s*appendix(?:es)?\s*}.*?\\\\end\s*{\s*appendix(?:es)?\s*}/is', '', $last_msg, -1, $count1);
            $filtered = preg_replace('/\\\\appendix\b.*$/is', '', $filtered, -1, $count2);
            $messages[count($messages) - 1]->content = $filtered;
            return $count1 || $count2 ? "Appendix section removed." : "No appendix section found.";
        }, "Chat history management", "Remove appendix section from previous arXiv extraction.");

        if (!$is_admin) {
            // The command /usage allows the user to see their usage statistics
            $command_manager->add_command(array("/usage"), function($command, $month) use ($telegram, $user_config_manager) {
                $month = $month ?: date("ym");

                preg_match("/^[0-9]{4}$/", $month) || $telegram->die("Please provide a month in the format \"YYMM\".");
                $usage = get_usage_string($user_config_manager, $month, true);
                return "Your usage statistics for $month:\n\n$usage";
            }, "Chat history management", "Show your usage statistics");
        }

        if ($is_admin) {
            // #######################
            // ### Commands: Admin ###
            // #######################

            // The command /addusermh adds a user to the list of authorized users
            $command_manager->add_command(array("/adduser"), function($command, $username) use ($telegram, $global_config_manager) {
                ($username != "" && $username[0] == "@") || $telegram->die("Please provide a username to add.");
                $username = substr($username, 1);
                if ($global_config_manager->is_allowed_user($username, "general")) {
                    $telegram->die("User @$username is already in the list of authorized users.");
                }
                $global_config_manager->add_allowed_user($username, "general");
                return "Added user @$username to the list of authorized users.";
            }, "Admin", "Add a user to access the bot (by username)");

            // The command /removeuser removes a user from the list of authorized users
            $command_manager->add_command(array("/removeuser"), function($command, $username) use ($telegram, $global_config_manager) {
                $username != "" || $telegram->die("Please provide a username to remove.");
                $username = trim($username);
                if ($username[0] == "@") {
                    $username = substr($username, 1);
                }
                if (!$global_config_manager->is_allowed_user($username, "general")) {
                    return "User @$username is not in the list of authorized users.";
                }
                try {
                    $global_config_manager->remove_allowed_user($username, "general");
                } catch (Exception $e) {
                    $telegram->die("Error: ".json_encode($e, JSON_UNESCAPED_UNICODE));
                }
                return "Removed user @$username from the list of authorized users.";
            }, "Admin", "Remove a user from access to the bot (by username)");

            // The command /listusers lists all users authorized, by category
            $command_manager->add_command(array("/listusers"), function($command, $_) use ($telegram, $global_config_manager) {
                $categories = $global_config_manager->get_categories();
                $message = "Lists of authorized users, by category:\n";
                foreach ($categories as $category) {
                    $message .= "\n*$category*:\n";
                    $users = $global_config_manager->get_allowed_users($category);
                    if (count($users) == 0) {
                        $message .= "No users authorized for this category.\n";
                    } else {
                        foreach ($users as $user) {
                            $message .= "@$user\n";
                        }
                    }
                }
                return $message;
            }, "Admin", "List all users authorized, by category");

            // The command /jobs lists all jobs
            $command_manager->add_command(array("/jobs"), function($command, $arg) use ($telegram, $global_config_manager) {
                $jobs = $global_config_manager->get_jobs();
                if ($arg == "on" || $arg == "off") {
                    // Set all jobs to active or inactive
                    foreach ($jobs as $job) {
                        $job->status = $arg == "on" ? "active" : "inactive";
                    }
                    $global_config_manager->save_jobs($jobs);
                    return "All jobs successfully set \"$arg\".";
                } else if ($arg != "") {
                    // Toggle all jobs with name $arg
                    $cnt = 0;
                    foreach ($jobs as $job) {
                        if ($job->name == $arg) {
                            $job->status = $job->status == "active" ? "inactive" : "active";
                            $cnt++;
                        }
                    }
                    $global_config_manager->save_jobs($jobs);
                    if ($cnt == 0) return "No jobs with name \"$arg\" found.";
                    return "$cnt jobs successfully toggled.";
                } else {
                    // List all jobs
                    $message = "List of jobs:";
                    foreach ($jobs as $job) {
                        $message .= "\n\n".json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                    }
                    $telegram->send_message($message, false);
                }
                exit;
            }, "Admin", "Job management. Use \"/jobs <name>\" to toggle all jobs with name <name>, \"/jobs on\" to set all jobs to active, and \"/jobs off\" to set all jobs to inactive. No argument lists all jobs.");

            // The command /usage prints the usage statistics of all users for a given month
            $command_manager->add_command(array("/usage"), function($command, $month) use ($telegram, $global_config_manager) {
                // If monthstring is not in format "ym", send an error message
                $month = $month ?: date("ym");

                preg_match("/^[0-9]{4}$/", $month) || $telegram->die("Please provide a month in the format \"YYMM\".");
                $chatids = $global_config_manager->get_chatids();
                $message = "Usage statistics for month $month:\n";
                foreach ($chatids as $chatid) {
                    // Add a line for each user: @username (chatid): prompt + completion = total tokens
                    $user = new UserConfigManager($chatid);
                    // add username
                    $username = $user->get_username();
                    $message .= '- ';
                    if (empty($username))
                        $message .= "$chatid ";
                    else
                        $message .= "@$username ";
                    // add usage info
                    $message .= get_usage_string($user, $month, false)."\n";
                }
                return $message;
            }, "Admin", "Print the usage statistics of all users for a given month (default: current month)");
        }

        // ######################
        // ### Commands: Misc ###
        // ######################

        // The command /continue requests a response from the model
        $command_manager->add_command(array("/continue", "/c"), function($command, $_) {
            // request another response from the model
        }, "Misc", "Request another response");

        // The command /image requests an image from the model
        $command_manager->add_command(array("/img"), function($command, $prompt) use ($telegram, $llm, $user_config_manager) {
            $prompt != "" || $telegram->die("Please provide a prompt with command $command.");
            // If prompt is a URL, send the URL to telegram instead of requesting an image
            if (filter_var($prompt, FILTER_VALIDATE_URL)) {
                $telegram->send_image($prompt);
                exit;
            }
            $image_url = $llm->image($prompt);  // use default model in the function
            $telegram->die_if_error($image_url);
            Log::image($prompt, $image_url, $telegram->get_chat_id());
            $telegram->die_if_error($image_url);
            // Add the image to the chat history
            $user_config_manager->add_message("assistant", "$image_url $prompt");
            // Show the image to the user
            $telegram->send_image($image_url, $prompt);
            exit;
        }, "Misc", "Request an image. If the prompt starts with `dalle2` or `dalle3`, use the corresponding model (default: `dalle2`). If the prompt is a URL, show that picture instead of generating a one.");

        // The command /tts requests a text-to-speech conversion from the model
        $command_manager->add_command(array("/tts"), function($command, $prompt) use ($telegram, $llm, $user_config_manager, $DEBUG) {
            if ($prompt == "") {
                // If the last message is not a system message, use it as prompt
                $messages = $user_config_manager->get_config()->messages;
                if (count($messages) > 0 && $messages[count($messages)-1]->role != "system") {
                    $prompt = $messages[count($messages)-1]->content;
                } else {
                    $telegram->die("Please provide a prompt with command $command.");
                }
            }
            $tts_config = $user_config_manager->get_tts_config();
            $audio_data = $llm->tts($prompt, $tts_config->model, $tts_config->voice, $tts_config->speed, response_format: "opus");  // telegram only supports opus
            if ($audio_data == "") {
                $telegram->die("WTF-Error: Could not generate audio. Please try again later.");
            }
            // if audio_url starts with "Error: "
            if (has_error($audio_data)) {
                $error_message = $audio_data;
                $telegram->die($error_message);
            }
            if ($DEBUG) {
                $telegram->send_message("Generated an audio of length ".strlen($audio_data)." bytes.");
            }
            $telegram->send_voice($audio_data);
            exit;
        }, "Misc", "Request a text-to-speech conversion. If no prompt is provided, use the last message.");

        // The command /dumpmessages outputs the messages in a form that could be used to recreate the chat history
        $command_manager->add_command(array("/dm", "/dmf"), function($command, $n) use ($telegram, $user_config_manager) {
            $messages = $user_config_manager->get_config()->messages;
            $n_total = count($messages);
            $n_total > 0 || $telegram->die("There are no messages to dump.");
            $n = $n ?: 1;
            is_numeric($n) || $telegram->die("Please provide a number.");
            $n = intval($n);
            $n > 0 || $telegram->die("Please provide a positive number.");
            $messages = array_slice($messages, -$n);
            // Send each message as a separate message
            foreach ($messages as $message) {
                if (is_string($message->content)) {
                    $content = $message->content;
                    $telegram->send_message("/$message->role $content", $command == "/dmf");
                } else if (is_array($message->content) && isset($message->content[0]->image_url)) {
                    $image_url = $message->content[0]->image_url->url;
                    $caption = isset($message->content[1]) ? $message->content[1]->text : "";
                    if ($command == "/dmf") {
                        $telegram->send_image($image_url, "/$message->role\n$caption");
                    } else {
                        $telegram->send_message("/$message->role $image_url\n$caption", false);
                    }
                } else if (is_array($message->content)) {
                    // Handle web search responses with citations
                    $formatted_text = text_from_claude_websearch($message->content, $user_config_manager->is_post_processing());
                    $telegram->send_message("/$message->role $formatted_text", $command == "/dmf");
                }
            }
            exit;
        }, "Misc", "Dump all messages in the chat history. You can dump only the last n messages by providing a number with the command (e.g. \"/dm 3\" to dump the last 3 messages).");

        // The command /cnt outputs the number of messages in the chat history
        $command_manager->add_command(array("/cnt"), function($command, $_) use ($telegram, $user_config_manager) {
            $messages = $user_config_manager->get_config()->messages;
            $stats = get_message_stats($messages);
            switch ($stats['messages']) {
                case 0:
                    return "There are no messages in the chat history.";
                case 1:
                    return "There is 1 message ({$stats['words']} words ≈ {$stats['tokens']} tokens) in the chat history.";
                default:
                    return "There are {$stats['messages']} messages ({$stats['words']} words ≈ {$stats['tokens']} tokens) in the chat history.";
            }
        }, "Misc", "Count the number of messages in the chat history.");

        // The command /self lets the model answer for the user and then respond to itself
        $command_manager->add_command(array("/self"), function($command, $context) use ($telegram, $user_config_manager, $llm) {
            $n = 1;
            $model = null;

            if ($context) {
                $parts = explode(' ', $context, 2);
                if (is_numeric($parts[0])) {
                    $n = intval($parts[0]);
                    if (isset($parts[1])) {
                        $model = $parts[1];
                    }
                } else {
                    $model = $parts[0];
                    if (isset($parts[1]) && is_numeric($parts[1])) {
                        $n = intval($parts[1]);
                    }
                }
            }

            (is_int($n) && 0 < $n && $n <= 12) || $telegram->die("Please provide a positive integer less than 12.");

            $chat = $user_config_manager->get_config();
            // Loop for the specified number of iterations
            for ($i = 0; $i < $n; $i++) {
                // Swap roles in a copy of $chat
                $temp_chat = json_decode(json_encode($chat));
                if ($model) {
                    $temp_chat->model = $model;
                }
                foreach ($temp_chat->messages as $msg) {
                    if ($msg->role === "user") {
                        $msg->role = "assistant";
                    } else if ($msg->role === "assistant") {
                        $msg->role = "user";
                    }
                }

                // Generate a response with swapped roles
                // $telegram->send_message("DEBUG: Last message in temp_chat has role \"".end($temp_chat->messages)->role."\": ".mb_substr(end($temp_chat->messages)->content, 0, 40)."...");
                $user_response = $llm->message($temp_chat);
                $telegram->die_if_error($user_response, $user_config_manager);
                $user_config_manager->add_message("user", $user_response);
                $telegram->send_message("/user $user_response");
                $user_config_manager->save();

                // Now get the assistant's response to the generated user message
                $assistant_response = $llm->message($chat);
                $telegram->die_if_error($assistant_response, $user_config_manager);
                $user_config_manager->add_message("assistant", $assistant_response);
                $telegram->send_message("/assistant $assistant_response");
                $user_config_manager->save();
            }
            exit;
        }, "Misc", "Let the model respond as the user and then respond to itself. Optionally specify the number of turns and/or a model (e.g. `/self 3 gpt-4.1`).");

        // ############################
        // Actually run the command!
        $response = $command_manager->run_command($message);
        if (is_string($response) && $response != "") {
            $telegram->send_message($response);
            exit;
        }
    } else {
        // If it is not a command, add the message to the chat history
        $user_config_manager->add_message("user", $message);
    }

    // #############################
    // ### Main interaction loop ###
    // #############################

    // $telegram->send_message("Sending message: ".$message);
    $chat = $user_config_manager->get_config();
    $response = $llm->message($chat);
    if ($message === "/continue" || $message === "/c") {
        $telegram->die_if_error($response);
    } else {
        $telegram->die_if_error($response, $user_config_manager);
    }

    // If the response starts with "MAIL", parse the response and build a mailto link
    if (substr($response, 0, 5) == "MAIL\n") {
        // Build a mailto link from the response
        $mailto = "https://adrianmueller.eu/mailto/";
        // Find the recipient
        $recipient = "";
        $recipient_regex = "/To: (.*)\n/";
        if (preg_match($recipient_regex, $response, $matches)) {
            $recipient = $matches[1];
            /// Might be in form "To: Name <email>"
            if (strpos($recipient, "<") !== false) {
                $recipient = substr($recipient, strpos($recipient, "<") + 1);
                $recipient = substr($recipient, 0, strpos($recipient, ">"));
            }
        }
        // Find the subject
        $subject = "";
        $subject_regex = "/Subject: (.*)\n/";
        if (preg_match($subject_regex, $response, $matches)) {
            $subject = $matches[1];
        }
        // Find the body
        $body = "";
        // $body_regex = "/Body:\n(.*)/"; // this doesn't match newlines; instead matches everything after "Body:\n"
        $body_regex = "/Body:\n((?:.|\n)*)/";
        if (preg_match($body_regex, $response, $matches)) {
            $body = $matches[1];
            $body = urlencode($body);
        }
        // Build the mailto link
        $mailto .= "?to=$recipient&subject=".urlencode($subject)."&body=$body";
        $user_config_manager->add_message("assistant", $response);
        // Add the mailto link to the response as a markdown link
        $response .= "\n\n[Send mail]($mailto)";
        $telegram->send_message($response);
    } else {
        $user_config_manager->add_message("assistant", $response);

        // Audit the response if auditing is enabled and the first message is a system message
        if ($user_config_manager->is_auditing() && isset($chat->messages[0]) && $chat->messages[0]->role === "system" &&
            isset($chat->messages[1]) && $chat->messages[1]->role !== "system") {
            $system_message = $chat->messages[0]->content;
            $attempt = 1;
            $max_attempts = 3;
            $valid_response = false;

            $chat = $user_config_manager->get_config();
            $audit_chat = (object) UserConfigManager::$default_config;
            $audit_chat->model = "gpt-4.1-mini";
            $audit_chat->temperature = 0.0;
            $audit_prompt = "You are an auditor. Your job is to check if the model's response adheres to the system guidance. "
                         ."Generally be lenient and in particular in technical contexts allow for highly technical, thought-focused language. "
                         ."Remember that being technical is in no way inherently non-compassionate. "
                         ."However, be very strict on strong negative qualifiers in the guidance, like \"NEVER\" or \"avoid\". "
                         ."ALWAYS reject preemptively agreeing phrases at the start, like \"You're right\" or \"You're absolutely right\". "
                         ."Start your response with 'YES' if it adheres, 'NO' otherwise (for parsing the decision)."
                         ."If it doesn't adhere, always provide a brief explanation of why it doesn't (never just a lonely \"NO\").";
            $audit_chat->messages = [["role" => "system", "content" => $audit_prompt]];

            while (!$valid_response && $attempt <= $max_attempts) {
                // Add question to audit chat
                $audit_question = "System guidance:\n\n```\n$system_message\n```\n\nModel response:\n\n```\n$response\n```\n\nDoes this response adhere to the system guidance?";
                $audit_chat->messages[] = ["role" => "user", "content" => $audit_question];

                // Get and record audit response
                $audit_response = $llm->message($audit_chat);
                $audit_chat->messages[] = ["role" => "assistant", "content" => $audit_response];

                if (strpos(strtoupper($audit_response), 'YES') === 0) {
                    $valid_response = true;
                    // $telegram->send_message($audit_response);
                } else if ($attempt < $max_attempts) {
                    // Log the failure
                    // $telegram->send_message("Audit failed (attempt $attempt/$max_attempts): $audit_response");
                    // Add feedback to the chat history
                    $user_config_manager->add_message("system", "Your previous response was rejected because it did not adhere to the system guidance.\n\nAuditing reponse:$audit_response\n\nPlease try again.");
                    // Get new response
                    $response = $llm->message($chat);
                    $telegram->die_if_error($response, $user_config_manager);
                    $user_config_manager->add_message("assistant", $response);
                    $attempt++;
                } else {
                    // Final attempt failed
                    $warning = "Warning: After $max_attempts attempts, the response still does not adhere to the system guidance.\n\nAuditing reponse:$audit_response";
                    $user_config_manager->add_message("system", $warning);
                    $telegram->send_message("$warning\n\nShowing the last attempt.");
                    $valid_response = true; // exit from loop
                }
            }

            // Clean up the chat history if we had failed attempts
            if ($attempt > 0) {
                if ($attempt >= $max_attempts && !$valid_response) {
                    $user_config_manager->delete_messages(($attempt-1)*2);
                    $user_config_manager->add_message("system", $warning);
                } else {
                    $user_config_manager->delete_messages(($attempt-1)*2+1);
                }
                $user_config_manager->add_message("assistant", $response);
            }
        }

        $telegram->send_message($response);
    }
}

?>
