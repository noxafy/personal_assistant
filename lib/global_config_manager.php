<?php
// Import function "curl"
require_once __DIR__."/utils.php";

/**
 * This class manages the persistent data for all chats.
 *
 * The JSON file is located in "chats/config.json". It has the following format:
 * ```json
 * {
 *     "config": {
 *         "VAR_1": "",
 *         "VAR_2": "",
 *     },
 *     "users": {
 *         "category_1" : [],
 *         "category_2" : []
 *     }
 * }
 * ```
 */
class GlobalConfigManager {

    private $global_config_file;
    private $global_config;

    public function __construct($global_config_file = null) {
        if ($global_config_file == null) {
            $this->global_config_file = dirname(__DIR__)."/chats/config.json";
        } else {
            $this->global_config_file = $global_config_file;
        }
        $this->load();
    }

    private function load() {
        if (file_exists($this->global_config_file)) {
            $this->global_config = json_decode(file_get_contents($this->global_config_file), false);
            $this->global_config !== null || Log::die("JSON error: ".json_last_error_msg());
            $this->global_config !== false || Log::die("Could not read file: $this->global_config_file");
        }
        else {
            // Copy the template file
            if (copy(dirname($this->global_config_file)."/config_template.json", $this->global_config_file)) {
                $error_message = "Global config file not found. A new one has been created at $this->global_config_file. Please edit it and restart the assistant.";
            } else {
                $error_message = "Global config file not found. A new one could not be created at $this->global_config_file. Please create it manually and restart the assistant.";
            }
            Log::die($error_message);
        }
    }

    private function save() {
        file_put_contents($this->global_config_file, json_encode($this->global_config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Get a config value.
     *
     * @param string $key The key of the config value.
     * @return mixed The value of the config value.
     */
    public function get($key) {
        // Check first if the key exists in the config file
        if(isset($this->global_config->config->$key)) {
            $value = $this->global_config->config->$key;
            if ($value !== null && $value !== "")
                return $value;
        }
        // Fall back to getenv()
        $value = getenv($key);
        if ($value !== false) {
            return $value;
        }
        return null;
    }

    /**
     * Check if a user is allowed to use the assistant.
     *
     * @param string $username The username of the user.
     * @param string $category The category of the user.
     * @return bool True if the user is allowed to use the assistant.
     */
    public function is_allowed_user($username, $category = "general") {
        if (in_array("all", $this->global_config->users->$category))
            return true;
        if ($username == null || $username == "")
            return false;
        return in_array($username, $this->global_config->users->$category);
    }

    /**
     * Get the list of allowed users.
     *
     * @param string $category The category of the user.
     * @return array The list of users registered in the given category.
     */
    public function get_allowed_users($category = "general") {
        return $this->global_config->users->$category;
    }

    /**
     * Add a user to the list of allowed users.
     *
     * @param string $username The username of the user.
     * @param string $category The category to add the user to.
     */
    public function add_allowed_user($username, $category = "general") {
        if ($username == null || $username == "") return;
        if (!isset($this->global_config->users->$category)) return;

        $this->global_config->users->$category[] = $username;
        $this->save();
    }

    /**
     * Remove a user from the list of allowed users for the given category.
     *
     * @param string $username The username of the user.
     * @param string $category The category to remove the user from.
     */
    public function remove_allowed_user($username, $category = "general") {
        if ($username == null || $username == "") return;
        if (!isset($this->global_config->users->$category)) return;

        $this->global_config->users->$category = array_diff($this->global_config->users->$category, array($username));
        $this->save();

        # Delete the user's config
        $chatids = $this->get_chatids();
        foreach ($chatids as $chatid) {
            $user_config_manager = new UserConfigManager($chatid);
            $config = $user_config_manager->get_config();
            if ($config->username == $username) {
                $user_config_manager->delete();

                # Remove jobs with the user's chat_id
                $jobs = $this->get_jobs();
                $new_jobs = array();
                foreach ($jobs as $job) {
                    if ($job->chat_id != $chatid) {
                        $new_jobs[] = $job;
                    }
                }
                $this->save_jobs($new_jobs);
                return;
            }
        }
        # If the user's config was not found, log an error
        Log::error("Could not delete the config for user @$username because it was not found.");
    }

    /**
     * Get the list of valid categories for user groups.
     *
     * @return array The list of valid categories for user groups.
     */
    public function get_categories() {
        return array_keys((array) $this->global_config->users);
    }

    /**
     * Get the list of jobs.
     *
     * @return array The list of jobs.
     */
    public function get_jobs() {
        return $this->global_config->jobs;
    }

    /**
     * Save the list of jobs.
     *
     * @param array $jobs The list of jobs.
     */
    public function save_jobs($jobs) {
        $this->global_config->jobs = $jobs;
        $this->save();
    }

    /**
     * Add a job to the list of jobs.
     *
     * @param object $job The job to add.
     */
    public function add_job($job) {
        $this->global_config->jobs[] = $job;
        $this->save();
    }

    /**
     * Get the list of chatids
     *
     * @return array The list of chatids.
     */
    public function get_chatids() {
        // Needs to be read out for them directory __DIR__."/../chats/", where there are files in the format <chatid>.json
        $chatids = array();
        $files = scandir(__DIR__."/../chats/");
        foreach ($files as $file) {
            if (substr($file, -5) != ".json")
                continue;
            $filename = substr($file, 0, -5);
            if (is_numeric($filename)) {
                $chatids[] = $filename;
            }
        }
        return $chatids;
    }
}

?>
