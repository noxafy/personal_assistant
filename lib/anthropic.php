<?php

require_once __DIR__."/logger.php";
require_once __DIR__."/utils.php";

/**
 * This class manages the connection to the Anthropic API.
 */
class Anthropic {
    public $DEBUG;
    public $user;
    private $RETRY_CNT = 0;
    private $MAX_RETRY = 4;

     /**
      * Create a new instance.
      *
      * @param UserConfigManager $user The user to use for the requests.
      * @param bool $DEBUG Whether to enable debug mode.
      */
     public function __construct($user, $DEBUG = False) {
        $this->DEBUG = $DEBUG;
        $this->user = $user;
    }

    /**
     * Send a request to create a chat completion of the model specified in the data.
     *
     * @param object|array $data The data to send.
     * @param bool $enable_websearch Whether to enable web search (default: false).
     * @return array|string The response from Claude or an error message (starts with "Error: ").
     */
    public function claude($data, $enable_websearch = false): array|string {
        // Request a chat completion from Anthropic
        // The response has the following format:
        // $server_output = '{
        //     "content": [
        //         {
        //         "text": "Hi! My name is Claude.",
        //         "type": "text"
        //         }
        //     ],
        //     "id": "msg_013Zva2CMHLNnXjNJJKqJ2EF",
        //     "model": "claude-3-5-sonnet-20240620",
        //     "role": "assistant",
        //     "stop_reason": "end_turn",
        //     "stop_sequence": null,
        //     "type": "message",
        //     "usage": {
        //         "input_tokens": 10,
        //         "output_tokens": 25
        //     }
        // }';

        // curl https://api.anthropic.com/v1/messages \
        //     --header "x-api-key: $ANTHROPIC_API_KEY" \
        //     --header "anthropic-version: 2023-06-01" \
        //     --header "content-type: application/json" \
        //     --data \
        // '{
        //     "model": "claude-3-5-sonnet-20240620",
        //     "max_tokens": 1024,
        //     "messages": [
        //         {"role": "user", "content": "Hello, world"}
        //     ]
        // }'
        if (isset($data->thinking->budget_tokens)) {
            $data->max_tokens += $data->thinking->budget_tokens;
        }

        // Add web search tool if enabled
        if ($enable_websearch) {
            $data->tools = [["type" => "web_search_20250305", "name" => "web_search", "max_uses" => 3]];
        }

        $response = $this->send_request("messages", $data);
        if (has_error($response))
            return $response;

        // track token usage
        if (isset($response->usage)) {
            // Get a month year string
            $month = date("ym");
            // Count the usages
            $this->user->increment("anthropic_".$month."_chat_input_tokens", $response->usage->input_tokens);
            $this->user->increment("anthropic_".$month."_chat_output_tokens", $response->usage->output_tokens);

            // Track web search usage if applicable
            if (isset($response->usage->server_tool_use) && isset($response->usage->server_tool_use->web_search_requests)) {
                $this->user->increment("anthropic_".$month."_web_search_requests", $response->usage->server_tool_use->web_search_requests);
            }
        }

        if (!isset($response->content)) {
            Log::error(array(
                "interface" => "anthropic",
                "endpoint" => "messages",
                "data" => strip_long_messages($data),
                "response" => $response,
            ));
            return "Error: The response from Anthropic is not in the expected format: ".json_encode($response, JSON_UNESCAPED_UNICODE);
        }
        // if ($this->DEBUG) {
        //     $month = date("ym");
        //     return $response->content[0]->text."\n\n"."Input: ".$response->usage->input_tokens." tokens\nOutput: ".$response->usage->output_tokens." tokens"
        //     ."\nMonth out: ".$this->user->get_counters()->anthropic_2602_chat_output_tokens."\nMonth in: ".$this->user->get_counters()->anthropic_2602_chat_input_tokens;
        // }
        return $response->content;
    }

    /**
     * Send a request.
     *
     * @param string $endpoint The API endpoint.
     * @param object|array $data The data to send.
     * @return mixed The response from the API.
     */
    private function send_request($endpoint, $data) {
        $apikey = $this->user->get_anthropic_api_key();
        if (!$apikey) {
            return "Error: You need to set your Anthropic API key to talk with me. Use /anthropicapikey to set your Anthropic API key. "
            ."You can get your API key from https://console.anthropic.com/settings/keys. "
            ."The API key will stored securely, not be shared with anyone, and only used to generate responses for you. "
            ."The developer will not be responsible for any damage caused by using this bot.";
        }
        $url = "https://api.anthropic.com/v1/$endpoint";
        $headers = array('x-api-key: '.$apikey, 'anthropic-version: 2023-06-01', 'content-type: application/json');

        $response = curl_post($url, $data, $headers);
        if ($this->DEBUG) {
            $response_log = json_encode($response, JSON_UNESCAPED_UNICODE);
            if (strlen($response_log) > 10000) {
                $response_log = substr($response_log, 0, 10000)."...";
            }
            Log::debug(array(
                "interface" => "anthropic",
                "endpoint" => $endpoint,
                "data" => strip_long_messages($data),
                "response" => $response_log,
            ));
        }

        // {
        //     "type": "error",
        //     "error": {
        //         "type": "not_found_error",
        //         "message": "The requested resource could not be found."
        //     }
        // }
        if (isset($response->error)) {
            if (is_string($data)) {
                $data = json_decode($data);
            }
            Log::error(array(
                "interface" => "anthropic",
                "endpoint" => $endpoint,
                "data" => strip_long_messages($data),
                "response" => $response,
                "retry" => $this->RETRY_CNT,
            ));
            // Retry the request if the error is a temporary error
            if ($response->error->type == "overloaded_error") {
                if ($this->RETRY_CNT < $this->MAX_RETRY) {
                    $this->RETRY_CNT++;
                    sleep(5 * $this->RETRY_CNT);
                    return $this->send_request($endpoint, $data);
                }
                return 'Error: The Anthropic API is currently overloaded. Please use another /model or try again in a few minutes.';
            }
            // Return the error message
            return 'Error: '.$response->error->message.' ('.$response->error->type.')';
        }
        return $response;
    }
}
