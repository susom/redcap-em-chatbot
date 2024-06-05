<?php
namespace Stanford\REDCapChatBot;

require_once "emLoggerTrait.php";

class REDCapChatBot extends \ExternalModules\AbstractExternalModule {

    use emLoggerTrait;
    const BUILD_FILE_DIR = 'chatbot_ui/build/static/';

    protected $secureChatInstance;

    public function __construct() {
		parent::__construct();

        // Instantiate your SecureChatAI and assign to a property.
        $moduleDirectoryPrefix = "SecureChatAI";
        $this->secureChatInstance = \ExternalModules\ExternalModules::getModuleInstance($moduleDirectoryPrefix);
    }


    public function redcap_every_page_top($project_id)
    {
        try {
            // in case we are loading record homepage load its the record children if existed
            preg_match('/redcap_v[\d\.].*\/index\.php/m', $_SERVER['SCRIPT_NAME'], $matches, PREG_OFFSET_CAPTURE);
            if (strpos($_SERVER['SCRIPT_NAME'], 'ProjectSetup') !== false || !empty($matches)) {
                $this->injectIntegrationUI();
            }
        } catch (\Exception $e) {
            \REDCap::logEvent('Exception initiating REDCap Chatbot.', $e->getMessage());
        }
    }

    public function injectJSMO($data = null, $init_method = null): void
    {
        // Temporary Workaround for 14.3 bug not including External Module functions in window
        // require APP_PATH_DOCROOT . "ExternalModules/manager/templates/hooks/every_page_top.php";
        echo $this->initializeJavascriptModuleObject();
        $cmds = [
            "window.chatbot_jsmo_module = " . $this->getJavascriptModuleObjectName()
        ];
        if (!empty($data)) $cmds[] = "window.chatbot_jsmo_module.data = " . json_encode($data);
        if (!empty($init_method)) $cmds[] = "window.chatbot_jsmo_module.afterRender(chatbot_jsmo_module." . $init_method . ")";
        ?>
        <script src="<?= $this->getUrl("assets/jsmo.js", true) ?>"></script>
        <script>
            $(function () { <?php echo implode(";\n", $cmds) ?> })
        </script>
        <?php
    }

    public function injectIntegrationUI()
    {
        $this->injectJSMO(null, "InitFunction");

        $build_files = $this->generateAssetFiles();

        foreach ($build_files as $file) {
            echo $file;
        }
        echo '<div id="chatbot_ui_container"></div>';

        $this->emdebug("injectIntegrationUI() End");
    }

    /**
     * @return array
     * Scans dist directory for frontend build files for dynamic injection
     */
    public function generateAssetFiles(): array
    {
        $assetFolders = ['css', 'js', 'media']; // Add the subdirectories you want to scan
        $cwd = $this->getModulePath();
        $assets = [];

        foreach ($assetFolders as $folder) {
            $full_path = $cwd . self::BUILD_FILE_DIR . '/' . $folder;
            $dir_files = scandir($full_path);

            if (!$dir_files) {
                $this->emError("No directory files found in $full_path");
                continue;
            }

            foreach ($dir_files as $file) {
                if ($file === '.' || $file === '..') {
                    continue;
                }

                $url = $this->getUrl(self::BUILD_FILE_DIR . '/' . $folder . '/' . $file);

                $html = '';
                if (str_contains($file, '.js')) {
                    $html = "<script type='module' crossorigin src='{$url}'></script>";
                } elseif (str_contains($file, '.css')) {
                    $html = "<link rel='stylesheet' href='{$url}'>";
                }
                // Only add HTML if it's not empty (i.e., the file is a JS or CSS file)
                if ($html !== '') {
                    $assets[] = $html;
                }
            }
        }

        return $assets;
    }

    /**
     * Sanitizes user input in the action queue nested array
     * @param $payload
     * @return array|null
     */
    public function sanitizeInput($payload): array|string
    {
        // Initialize a sanitized array
        $sanitizedPayload = array();

        // Only proceed if payload is indeed an array (as expected)
        if (is_array($payload)) {
            foreach ($payload as $message) {

                // Ensure each message in the payload has necessary attributes and they are in correct type.
                if (
                    isset($message['role']) && is_string($message['role']) &&
                    isset($message['content']) && is_string($message['content'])
                ) {
                    // Further sanitization might be required based on the logic of your system;
                    // here we are using a simple built-in PHP function as an example:
                    $sanitizedRole = filter_var($message['role'], FILTER_SANITIZE_STRING);
                    $sanitizedContent = filter_var($message['content'], FILTER_SANITIZE_STRING);

                    // Add the sanitized message to the sanitizedPayload
                    $sanitizedPayload[] = array(
                        'role' => $sanitizedRole,
                        'content' => $sanitizedContent
                    );
                }
            }
        }

        return $sanitizedPayload;
    }

    public function formatResponse($response) {
        // Extract data from the response
        $content = $this->secureChatInstance->extractResponseText($response);
        $role = $response['choices'][0]['message']['role'] ?? 'assistant';
        $id = $response['id'] ?? null;
        $model = $response['model'] ?? null;
        $usage = $response['usage'] ?? null;

        // Format it into the desired output
        $formattedResponse = [
            'response' => [
                'role' => $role,
                'content' => $content
            ],
            'id' => $id,
            'model' => $model,
            'usage' => $usage
        ];

        return $formattedResponse;
    }

    /**
     * This is the primary ajax handler for JSMO calls
     * @param $action
     * @param $payload
     * @param $project_id
     * @param $record
     * @param $instrument
     * @param $event_id
     * @param $repeat_instance
     * @param $survey_hash
     * @param $response_id
     * @param $survey_queue_hash
     * @param $page
     * @param $page_full
     * @param $user_id
     * @param $group_id
     * @return array|array[]|bool
     * @throws Exception
     */
    public function redcap_module_ajax($action, $payload, $project_id, $record, $instrument, $event_id, $repeat_instance,
                                       $survey_hash, $response_id, $survey_queue_hash, $page, $page_full, $user_id, $group_id)
    {
        switch ($action) {
            case "TestAction":
                $messages = array(
                    array("role"=> "user", "content" => "tell me a mom joke")
                );
                $response   = $this->secureChatInstance->callAI($messages);
                $result     = $this->formatResponse($response);
                $this->emDebug("calling TestAction and then SecureChatAI", $result);
                return json_encode($result);

            case "callAI":
                $messages   = $this->sanitizeInput($payload);
                $response   = $this->secureChatInstance->callAI($messages);
                $result     = $this->formatResponse($response);
                $this->emDebug("calling SecureChatAI.callAI()", $result);
                return json_encode($result);

            default:
                // Action not defined
                throw new Exception ("Action $action is not defined");
        }
    }
}
