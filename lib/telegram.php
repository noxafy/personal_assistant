<?php

require_once __DIR__."/logger.php";
require_once __DIR__."/utils.php";

/**
 * This class manages the connection to the Telegram API.
 */
class Telegram {

    private $telegram_token;
    private $chat_id;
    private $post_processing;
    private $DEBUG;
    private $RETRY_CNT = 0;
    private $MAX_RETRY = 3;

    /**
     * Create a new Telegram instance.
     *
     * @param string $telegram_token The Telegram bot token.
     * @param string $chat_id The chat ID.
     * @param bool $DEBUG Enable debug mode.
     */
    public function __construct($telegram_token, $chat_id, $DEBUG = false) {
        if (!preg_match("/^[0-9]+:[a-zA-Z0-9_-]+$/", $telegram_token)) {
            Log::die("Invalid Telegram token: ".$telegram_token);
        }
        // Check if the chat ID is valid
        if (!preg_match("/^-?[0-9]+$/", $chat_id)) {
            Log::die("Invalid chat ID: ".$chat_id);
        }
        $this->telegram_token = $telegram_token;
        $this->chat_id = $chat_id;
        $this->post_processing = false;
        $this->DEBUG = $DEBUG;
    }

    /**
     * Generic function to send a POST request to the Telegram API. To send a file, set $field_name, $file_name, *and* $file_content.
     *
     * @param string $endpoint The endpoint to send the request to.
     * @param object|array $data The data to send to the Telegram API.
     * @param array $headers (optional) The headers to send to the Telegram API.
     * @param string $field_name (optional) The name of the field with the file content.
     * @param string $file_name (optional) The name of the file to send to the Telegram API.
     * @param string $file_content (optional) The content of the file to send to the Telegram API.
     * @return object|null The response from the Telegram API or null if there was an error.
     */
    private function send($endpoint, $data, $headers = array(), $field_name = null, $file_name = null, $file_content = null) {
        $url = "https://api.telegram.org/bot".$this->telegram_token."/".$endpoint;

        $server_output = curl_post($url, $data, $headers, $field_name, $file_name, $file_content);
        // DEBUG endpoint, data, server_output, and optionally file_name and file_content
        if ($this->DEBUG) {
            if ($file_name != null) {
                Log::debug(array(
                    "interface" => "telegram",
                    "endpoint" => $endpoint,
                    "data" => $data,
                    "file_name" => $file_name,
                    "file_content" => $file_content,
                    "server_response" => $server_output,
                ));
            } else {
                Log::debug(array(
                    "interface" => "telegram",
                    "endpoint" => $endpoint,
                    "data" => $data,
                    "server_response" => $server_output,
                ));
            }
        }
        if (isset($server_output->ok) && $server_output->ok) {
            return $server_output;
        }
        // Error handling
        Log::error(array(
            "interface" => "telegram",
            "endpoint" => $endpoint,
            "server_response" => $server_output,
            "data" => $data,
        ));
        if ($endpoint == "sendMessage" || $endpoint == "editMessageText") {
            // let the function handle it
            return $server_output;
        } else if (is_string($server_output)) {
            $this->send_message($server_output, false);
        } else {
            $this->send_message("Error: [/$endpoint] ".json_encode($server_output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), false);
        }
        // echo json_encode($server_output);
        return null;
    }

    /**
     * Split a message into multiple messages if it is too long.
     *
     * @param string $message The message to split.
     * @param int $max_length The maximum length of each message.
     * @param int $max_messages The maximum number of messages to split into.
     * @return array The messages of maximum $max_length characters.
     */
    private function split_message($message, $max_length = 4096, $max_messages=10): array {
        if (strlen($message) < $max_length)
            return array($message);
        if (strlen($message) > $max_length * $max_messages) {
            return array("Error: Message too long to send (".strlen($message)." characters).");
        }

        // Split message into multiple messages via new lines
        $messages = explode("\n", $message);

        // If a message is still longer than $max_length characters, split it into multiple messages via hard cuts
        $new_messages = array();
        foreach ($messages as $message) {
            if (strlen($message) > $max_length) {
                $message_parts = str_split($message, $max_length-1);
                foreach ($message_parts as $message_part) {
                    $new_messages[] = $message_part;
                }
            } else if (strlen($message) > 0) {
                $new_messages[] = $message;
            }
        }
        $messages = $new_messages;

        // Merge messages again as long as the result is shorter than $max_length characters
        $new_messages = array();
        $new_message = "";
        foreach ($messages as $message_part) {
            if (strlen($new_message."\n".$message_part) > $max_length) {
                $new_messages[] = $new_message;
                $new_message = $message_part;
            } else {
                $new_message .= "\n".$message_part;
            }
        }
        $new_messages[] = $new_message;

        return $new_messages;
    }

    /**
     * Send a message to Telegram, or edit an existing message when $message_id is provided.
     *
     * @param string $message The message to send.
     * @param bool $is_markdown (optional) Whether the message is markdown or not. Default: true.
     * @param int|null $message_id (optional) Existing Telegram message ID to edit. If null, sends a new message.
     * @return array|null Metadata of the last resulting message:
     *   - id: int|null
     *   - len: int
     */
    public function send_message($message, $is_markdown = true, $message_id = null): ?array {
        if (empty($message) || trim($message) == "") {
            Log::error(array(
                "interface" => "telegram",
                "message" => "Empty message [$message]",
            ));
            return null;
        }

        $messages = $this->split_message($message);
        if (count($messages) > 1) {
            $last_meta = null;
            foreach ($messages as $idx => $part) {
                $part_message_id = ($idx === 0) ? $message_id : null;
                $meta = $this->send_message($part, $is_markdown, $part_message_id);
                if ($meta !== null) {
                    $last_meta = $meta;
                }
            }
            return $last_meta;
        }

        $data = (object) array(
            "chat_id" => $this->chat_id,
            "text" => $is_markdown ? $this->format_message($message, $this->post_processing) : $message,
            "disable_web_page_preview" => "true",
        );
        if ($is_markdown) {
            $data->parse_mode = $this->post_processing ? "MarkdownV2" : "Markdown";
        }

        $endpoint = "sendMessage";
        if ($message_id !== null) {
            $endpoint = "editMessageText";
            $data->message_id = $message_id;
        }

        $server_output = $this->send($endpoint, $data);
        if ($this->RETRY_CNT == $this->MAX_RETRY) {
            Log::error(array(
                "interface" => "telegram",
                "message" => "Max retry count reached for send_message.",
                "data" => $data,
                "server_response" => $server_output,
            ));
            return null;
        }

        if ($server_output != null && !is_string($server_output) && !$server_output->ok) {
            // Try again without parse mode if Telegram can't parse entities
            if (isset($server_output->description) && strpos($server_output->description, "can't parse entities") !== false) {
                if ($this->DEBUG) {
                    $message = "[DEBUG: Failed with `".json_encode($server_output->description, JSON_UNESCAPED_UNICODE)
                        ."`]\n\n".$this->format_message($message);
                }
                return $this->send_message($message, false, $message_id);
            }

            // Retry after a few seconds
            if ($this->RETRY_CNT < $this->MAX_RETRY) {
                $this->RETRY_CNT++;
                sleep(5*$this->RETRY_CNT);
                if ($this->DEBUG) {
                    $data->text = "\[Retry $this->RETRY_CNT\] $data->text";
                }
                return $this->send_message($message, $is_markdown, $message_id);
            }
            return null;
        }

        $this->RETRY_CNT = 0;

        $resolved_id = $message_id;
        if ($server_output != null && !is_string($server_output) && isset($server_output->result->message_id)) {
            $resolved_id = intval($server_output->result->message_id);
        }

        return array(
            "id" => $resolved_id,
            "len" => strlen($message),
        );
    }

    /**
     * Create a Telegram-native streaming accumulator for chunked text updates.
     *
     * It batches incoming chunks and only sends/edits a Telegram message when:
     * - at least one chunk has arrived, and
     * - the configured interval has elapsed since the first chunk in the current batch.
     *
     * The first Telegram message is created only on the first flush.
     *
     * @param float $interval_seconds Flush interval in seconds (default: 0.5).
     * @param bool $is_markdown Whether Telegram markdown formatting should be used.
     * @return object { on_chunk: callable, flush: callable }
     */
    public function create_streaming_accumulator($interval_seconds = 0.5, $is_markdown = true) {
        $stream_buffer = "";
        $stream_started_at = null;
        $message_id = null;

        $flush = function() use (&$stream_buffer, &$stream_started_at, &$message_id, $is_markdown) {
            if ($stream_buffer === "") {
                return;
            }

            $stream_started_at = null;

            $meta = $this->send_message($stream_buffer, $is_markdown, $message_id);
            $meta !== null || exit;  // if we can't send, no point in keeping accumulating
            $message_id = $meta["id"];
            $stream_buffer = substr($stream_buffer, -$meta["len"]);
        };

        $on_chunk = function($chunk) use (&$stream_buffer, &$stream_started_at, $interval_seconds, $flush) {
            if (!is_string($chunk) || $chunk === "") {
                return;
            }

            $stream_buffer .= $chunk;
            $now = microtime(true);

            if ($stream_started_at === null) {
                $stream_started_at = $now;
                return;
            }

            if (($now - $stream_started_at) >= $interval_seconds) {
                $flush();
            }
        };

        return (object) array(
            "on_chunk" => $on_chunk,
            "flush" => $flush
        );
    }

    /**
     * Send an image to Telegram.
     *
     * @param string $image The file id or URL of the image to send.
     * @param string $caption (optional) The caption of the image.
     * @return void
     */
    public function send_image($image, $caption = ""): void {
        // Check if this is a Telegram CDN URL
        if (strpos($image, 'api.telegram.org/file/bot') !== false) {
            // Download the image content
            $image_content = @file_get_contents($image);
            if ($image_content !== false) {
                // Extract the filename from the URL
                $file_name = basename(parse_url($image, PHP_URL_PATH));
                // Send the image as a file upload
                $this->send("sendPhoto", array(
                    "chat_id" => $this->chat_id,
                    "caption" => $caption
                ), [], "photo", $file_name, $image_content);
                return;
            }
            $this->send_message("Error: Failed to download image from Telegram's servers. The image might no longer be available.");
            return;
        }

        // Regular URL or file_id method
        $this->send("sendPhoto", array(
            "chat_id" => $this->chat_id,
            "photo" => $image,
            "caption" => $caption,
        ));
    }

    /**
     * Send a document to Telegram.
     * For sending via URL: "In sendDocument, sending by URL will currently only work for GIF, PDF and ZIP files."
     *
     * @param string $file_name The name of the file.
     * @param string $file_content The content of the file as binary content.
     */
    public function send_document($file_name, $file_content): void {
        $this->send("sendDocument", array(
            "chat_id" => $this->chat_id
        ), array(), "document", $file_name, $file_content);
    }

    /**
     * Send a voice message to Telegram. The file must be in OGG format encoded with OPUS codec.
     * Telegram servers will transcode the audio to ensure compatibility across all devices.
     * Maximum file size for voice messages is 50MB.
     *
     * @param string $ogg_content The binary content of the OGG file encoded with OPUS.
     * @return void
     */
    public function send_voice($ogg_content): void {
        $this->send("sendVoice", array(
            "chat_id" => $this->chat_id
        ), array(), "voice", "audio.ogg", $ogg_content);
    }

    /**
     * Get a file url from a Telegram file ID.
     *
     * @param string $file_id The file ID
     * @return string|null The file url or null if there was an error.
     */
    public function get_file_url($file_id): string|null {
        $server_output = $this->send("getFile", array(
            "file_id" => $file_id
        ));
        if ($server_output == null) {
            return null;
        }
        return "https://api.telegram.org/file/bot".$this->telegram_token."/".$server_output->result->file_path;
    }

    /**
     * Get the file content from a Telegram file ID.
     *
     * @param string $file_id The file ID
     * @return string|null The file content or null if there was an error.
     */
    public function get_file($file_id): string|null|bool {
        $file_url = $this->get_file_url($file_id);
        if ($file_url == null) {
            return null;
        }
        return file_get_contents($file_url);
    }

    /**
     * Get the chat ID.
     *
     * @return string The chat ID.
     */
    public function get_chat_id(): string {
        return $this->chat_id;
    }

    /**
     * Enable or disable message post-processing for Markdown formatting.
     * When enabled, messages are formatted using MarkdownV2 syntax with proper escaping.
     *
     * @param bool $post_processing True to enable post-processing, false to disable
     */
    public function set_postprocessing($post_processing): void {
        $this->post_processing = $post_processing;
    }

    /**
     * Send a message to the chat and terminate script execution.
     * Useful for displaying critical errors to the user before exiting.
     *
     * @param string $message The error message to display before terminating
     */
    public function die($message, $as_markdown=false): void {
        $this->send_message($message, $as_markdown);
        Log::die($message);
    }

    /**
     * Check if a message contains an error and terminate if it does.
     * This is a convenience method to check response objects and terminate execution
     * when an error is detected.
     *
     * @param string $message The message to check for errors
     * @param object $user_config_manager Optional UserConfigManager object to delete the last message from the chat history
     */
    public function die_if_error($message, $user_config_manager=null): void {
        if (has_error($message)) {
            if ($user_config_manager !== null) {
                // $user_config_manager->delete_messages(1);
                $message .= "\n\nMessage has been added to the chat history. Retry with /continue.";
            }
            $this->die($message);
        }
    }

    /**
     * Format a message for proper display in Telegram using MarkdownV2.
     * Handles various formatting tasks such as:
     * - Converting LaTeX expressions to code blocks
     * - Processing markdown syntax for proper Telegram display
     * - Escaping special characters as required by Telegram's MarkdownV2 format
     *
     * @param string $response The original message text to format
     * @param bool $postprocessing (optional) Whether to apply MarkdownV2 post-processing.
     * @return string The formatted message ready for Telegram's MarkdownV2 parser
     */
    private function format_message($response, $postprocessing=true): string {
        // $this->send_message("Original response: $response", false);
        if (!$postprocessing) {
            // Replace "* " at the start with "- "
            $response = preg_replace('/^\\* /m', '- ', $response);
            return $response;
        }

        // Remove LaTeX delimiters from code blocks
        $response = preg_replace('/```(.*?)\n\s*\$\$\s*\n(.*?)\n\s*\$\$\s*\n```/s', "```$1\n$2\n```", $response);  // Code block with $$...$$ block math
        $response = preg_replace('/```(.*?)\n\s*\$\s*\n(.*?)\n\s*\$\s*\n```/s', "```$1\n$2\n```", $response);  // Code block with $...$ inline math
        $response = preg_replace('/```(.*?)\n\s*\\\\\[\s*\n(.*?)\n\s*\\\\\]\s*\n```/s', "```$1\n$2\n```", $response);  // Code block with \[...\] display math
        $response = preg_replace('/```(.*?)\n\s*\\\\\(\s*\n(.*?)\n\s*\\\\\)\s*\n```/s', "```$1\n$2\n```", $response);  // Code block with \(...\) inline math

        // Remove backticks around LaTeX expressions
        $response = preg_replace('/`(\$\$(.*?)\$\$)`/s', "$1", $response);  // Block math $$...$$
        $response = preg_replace('/`(\$(.*?)\$)`/s', "$1", $response);   // Inline math $...$
        $response = preg_replace('/`(\\\\\[(.*?)\\\\\])`/s', "$1", $response);  // Display math \[...\]
        $response = preg_replace('/`(\\\\\((.*?)\\\\\))`/s', "$1", $response);  // Inline math \(...\)

        // For each \[ find the corresponding \] (might be on later lines) and replace both by ```
        $start = 0;
        while (($start < strlen($response) && $start = strpos($response, "\\[", $start)) !== false) {
            $end = strpos($response, "\\]", $start+2);
            if ($end === false) {
                break;
            }
            $latex = substr($response, $start, $end - $start + 2);
            $latex_new = substr($latex, 2, strlen($latex)-4);
            $latex_new = trim($latex_new);
            $response = str_replace($latex, "```\n$latex_new\n```", $response);
            $start = $end;
        }

        // For each $$ on its own line, find the corresponding $$ and replace both by ```
        // Multi-line: $$ on one line, content on next line(s), $$ on another line
        $response = preg_replace('/^\h*\$\$\h*\n(.*?)\n\h*\$\$\h*$/ms', "```\n$1\n```", $response);
        // Same line: $$content$$ where it's the only thing on the line
        $response = preg_replace('/^\h*\$\$(.*?)\$\$\h*$/m', "```\n$1\n```", $response);
        $response = preg_replace('/^\s*```\s*/', '```', $response);
        // Ensure every ``` starts on a new line
        $response = preg_replace('/(?<!\n)```/', "\n```", $response);
        // Replace "```\n\n\n" with "```\n\n"
        $response = preg_replace('/\n```\s*\n\s*\n/', "\n```\n\n", $response);

        // If a text is not already in a code block
        $response_new = "";
        $lines = explode("\n", $response);
        $is_in_code_block = False;
        for ($i = 0; $i < count($lines); $i++) {
            $line = $lines[$i];
            if (str_starts_with($line, "```")) {
                $is_in_code_block = !$is_in_code_block;
            }
            if ($is_in_code_block) {
                // Replace \ with \\
                $line = preg_replace('/\\\\/', '\\\\\\\\', $line);
                // Escape backticks
                $line = preg_replace_callback('/`([^`]+)`/', function($m) {
                    // Escape the backticks
                    return '\`' . $m[1] . '\`';
                }, $line);
            } else {
                // For each \( find the corresponding \) and replace both by `
                $line = preg_replace('/`?\\\\\( ?(.*?) ?\\\\\)`?/', '`$1`', $line);
                // Same for $ and $
                $line = preg_replace('/`?(?<!\$)\$(?!\$)(?![,\.;:!\?)\]]) ?(.*?) ?(?<!\$)\$(?!\$)`?/', '`$1`', $line);
                // Replace * preceded or followed by a digit or paranthesis (any of )(][ ) by \*
                // $line = preg_replace('/(?<=[0-9\(\)\[\]])\*(?=[0-9\(\)\[\]])/', '\\*', $line);

                // Surround all words containing underscores with backticks (python names)
                $matches = array();
                preg_match_all('/(?<!`)([^ `]+_[^ `]+)(?!`)/u', $line, $matches, PREG_OFFSET_CAPTURE);
                $offset = 0;
                foreach ($matches[0] as $match) {
                    // Don't backtick matches that contain links
                    if (preg_match('/https?:\/\//', $match[0])) {
                        continue;
                    }
                    $start = $match[1] + $offset;
                    $end = $start + strlen($match[0]);
                    $count_before = substr_count(substr($line, 0, $start), '`');
                    $count_after = substr_count(substr($line, 0, $end), '`');
                    if ($count_before % 2 == 0 && $count_after % 2 == 0) {
                        $line = substr($line, 0, $start)."`".$match[0]."`".substr($line, $end);
                        $offset += 2;
                    }
                }
                // Replace an "* " at the start of the line with "- "
                $line = preg_replace('/^\* /', '- ', $line);
                // Strip space after > blockquote marker (Telegram counts it as part of the quote)
                $line = preg_replace('/^> /', '>', $line);
                // Replace all ** outside of code blocks by *
                $line = preg_replace('/(?<!`)\*\*(.*?)(?<!`)\*\*/', '*$1*', $line);
                // Strip asterisks around heading text itself
                $line = preg_replace('/^(#+ )\*{1,2}(.*?)\*{1,2}$/', '$1$2', $line);
                // Replace headings (a line beginning with at least one #) by bold text
                $line = preg_replace('/^(#+ .*)$/', '*$1*', $line);

                $in_backticks = false;
                $line_new = "";
                for ($j = 0; $j < strlen($line); $j++) {
                    if ($line[$j] == '`') {
                        $in_backticks = !$in_backticks;
                    }
                    else if (!$in_backticks) {
                        if (markdownV2_escape($line, $j)) {
                            $line_new .= '\\';
                        }
                    } else {
                        // escape backslashes
                        if ($line[$j] == '\\') {
                            $line_new .= '\\\\';
                        }
                    }
                    $line_new .= $line[$j];
                }
                $line = $line_new;
            }
            $response_new .= "$line\n";
        }
        // remove trailing newline
        $response = substr($response_new, 0, -1);
        return $response;
    }
}

function markdownV2_escape($line, $j) {
    # see https://core.telegram.org/bots/api#markdownv2-style
    switch ($line[$j]) {
        case '!':
            // if ! is followed by [ (link), do not escape it
            if ($j < strlen($line)-6 && $line[$j+1] == "[")
                return False;
            return True;
        case '*':
            // if * is at the beginning of the line or preceded by only one * at the beginning of the line, do not escape it
            if ($j == 0 || ($j == 1 && $line[0] == "*"))
                return False;
        case '_':
        case '~':
            $char = $line[$j];

            // Temporarily mask inline code spans so we ignore them when counting special chars
            $masked_line = preg_replace_callback('/`[^`]*`/', function($m) { return str_repeat(' ', strlen($m[0])); }, $line);
            // Find all non-escaped occurrences of the character
            preg_match_all('/(?<!\\\\)' . preg_quote($char, '/') . '/', $masked_line, $matches, PREG_OFFSET_CAPTURE);

            // Extract positions and find current index
            $positions = array_column($matches[0], 1);
            $currentIndex = array_search($j, $positions, true);  // we know there is a char at $j, so this is never false

            // Check if left and right side counts are odd
            $numIndices = count($positions) - 1;
            $leftOdd = $currentIndex % 2 == 1;
            $rightOdd = ($numIndices - $currentIndex) % 2 == 1;

            // If one side has odd count and the other has even
            // If ~ is used as "approximately" (followed by optional space, optional currency symbol, digit), always escape it
            if ($char == '~' && preg_match('/^\s?\p{Sc}?\d/u', substr($line, $j + 1))) {
                return True;
            }

            if ($leftOdd xor $rightOdd) {
                return False;
            }

            // For *, if both sides have odd counts and there's one at the beginning
            if ($char == '*' && $leftOdd && $rightOdd && $positions[0] == 0) {
                return False;
            }

            // Default: escape the character
            return True;
        case '#':
            // if # is at the beginning of the line or preceded only by #s, do not escape it
            if ($j == 0 || ($j > 0 && preg_match('/^#+$/', substr($line, 0, $j))))
                return False;
            return True;
        case '[':
            // if there is a "](url)" after the [, do not escape it
            if (preg_match('/^\[[^\[\]]*\]\([^\)]+\)/', substr($line, $j)))
                return False;
            return True;
        case ']':
            // if there is a "[" somewhere before and a "(" directly after and a ")" somewhere after, do not escape it
            $is_before = preg_match('/\[[^\]]+$/', substr($line, 0, $j));
            $is_directly = $j < strlen($line)-4 && $line[$j+1] == "(";
            $is_after = preg_match('/[^\(]*\)/', substr($line, $j+2));
            if ($is_before && $is_directly && $is_after)
                return False;
            return True;
        case '(':
            // if there is a "[" somewhere before, a "]" directly before and a ")" somewhere after, do not escape it
            $is_before = preg_match('/\[[^\]]+\]$/', substr($line, 0, $j));
            $is_directly = $j > 2 && $line[$j-1] == "]";
            $is_after = preg_match('/[^\)]*\)/', substr($line, $j+1));
            if ($is_before && $is_directly && $is_after)
                return False;
            return True;
        case ')':
            // if there is a "[title](" before, do not escape it
            if ($j > 3 && preg_match('/\[[^\]]+\]\([^\)]+$/', substr($line, 0, $j)))
                return False;
            return True;
        case '>':
            // if > is at the beginning of the line or preceded by "**", do not escape it
            if ($j == 0 || ($j == 2 && $line[0] == "*" && $line[1] == "*"))
                return False;
        case '+':
        case '-':
        case '=':
        case '|':
        case '{':
        case '}':
        case '.':
            if ($j != 0 && $line[$j-1] == '\\') {
                return False;
            }
            return True;
    }
    return False;
}

?>
