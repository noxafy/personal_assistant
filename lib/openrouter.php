<?php

require_once __DIR__."/logger.php";
require_once __DIR__."/utils.php";

/**
 * This class manages the connection to the OpenRouter API.
 */
class OpenRouter {
    public $user;
    public $DEBUG;

    /**
     * Create a new OpenRouter instance.
     * 
     * @param UserConfigManager $user The user to use for the requests.
     * @param bool $DEBUG Whether to log debug messages.
     */
    public function __construct($user, $DEBUG = False) {
        $this->user = $user;
        $this->DEBUG = $DEBUG;
    }

    /**
     * Send a request to the OpenRouter API to create a chat completion.
     * 
     * @param object|array $data The data to send to the OpenRouter API.
     * @return object|string The response from OpenRouter or an error message (starts with "Error: ").
     */
    public function message($data) {
        $response = $this->send_request("chat/completions", $data);
        if (isset($response->choices)) {
            // model
            $model = $data->model;
            // Count the usages
            $month = date("ym");
            $this->user->increment("OpenRouter_".$month."_".$model."_prompt_tokens", $response->usage->prompt_tokens);
            $this->user->increment("OpenRouter_".$month."_".$model."_completion_tokens", $response->usage->completion_tokens);
            Log::debug(array(
                "interface" => "OpenRouter",
                "endpoint" => "message",
                "data" => $data,
                "response" => $response,
            ));
            return $response->choices[0]->message;
        }
        return $response;
    }

    /**
     * Send a request to the OpenRouter API.
     * 
     * @param string $endpoint The endpoint to send the request to.
     * @param object|array $data The data to send.
     * @param string $field_name (optional) The name of the field with the file content.
     * @param string $file_name (optional) The name of the file to send.
     * @param string $file_content (optional) The content of the file to send.
     * @return object|string The response object from the API or an error message (starts with "Error: ").
     */
    private function send_request($endpoint, $data, $field_name = null, $file_name = null, $file_content = null) {
        $apikey = $this->user->get_OpenRouter_api_key();
        if (!$apikey) {
            return "Error: You need to set your OpenRouter API key to talk with me. Use /Openrouterapikey to set your OpenRouter API key. "
            ."You can get your API key from https://openrouter.ai/keys. "
            ."The API key will stored securely, not be shared with anyone, and only used to generate responses for you. "
            ."The developer will not be responsible for any damage caused by using this bot.";
        }
        $url = "https://openrouter.ai/api/v1/$endpoint";
        $headers = array('Authorization: Bearer '.$apikey);

        $response = curl_post($url, $data, $headers, $field_name, $file_name, $file_content);
        if ($this->DEBUG) {
            $response_log = json_encode($response, JSON_UNESCAPED_UNICODE);
            if (strlen($response_log) > 10000) {
                $response_log = substr($response_log, 0, 10000)."...";
            }
            Log::debug(array(
                "interface" => "OpenRouter",
                "endpoint" => $endpoint,
                "data" => $data,
                "response" => $response_log,
            ));
        }

        if (isset($response->error)) {
            if (is_string($data)) {
                $data = json_decode($data);
            }
            Log::error(array(
                "interface" => "OpenRouter",
                "endpoint" => $endpoint,
                "data" => $data,
                "response" => $response,
            ));
            // OpenRouter error
            // {
            //     "error": {
            //         "message": "0.1 is not of type number - temperature",
            //         "type": "invalid_request_error",
            //         "param": null,
            //         "code": null
            //     }
            // }
            // Provider error
            // {
            //     "error": {
            //         "message": "Provider returned error",
            //         "code": 400,
            //         "metadata": {
            //             "raw": "{\"type\":\"error\",\"error\":{\"type\":\"invalid_request_error\",\"message\":\"This model does not support assistant message prefill. The conversation must end with a user message.\"},\"request_id\":\"req_XXX\"}",
            //             "provider_name": "Azure",
            //             "is_byok": false,
            //             "previous_errors": [...]
            //         }
            //     },
            //     "user_id": "user_XXX"
            // }

            // Return the error message
            if (isset($response->error->metadata->raw)) {
                $error = json_decode($response->error->metadata->raw);
                if ($error !== false && isset($error->type) && $error->type === "error") {
                    $response = $error;
                } else {
                    return 'Error: '.$response->error->metadata->raw;
                }
            }
            return 'Error: '.$response->error->message;
        }
        return $response;
    }
}
