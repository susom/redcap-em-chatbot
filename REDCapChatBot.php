<?php
namespace Stanford\REDCapChatBot;

require 'vendor/autoload.php';
require_once "emLoggerTrait.php";
use REDCap;
use Project;

class REDCapChatBot extends \ExternalModules\AbstractExternalModule {

    use emLoggerTrait;
    const BUILD_FILE_DIR = 'chatbot_ui/build/static';

    private \Stanford\SecureChatAI\SecureChatAI $secureChatInstance;
    private \Stanford\RedcapRAG\RedcapRAG $redcapRAGInstance;

    const SecureChatInstanceModuleName = 'secure_chat_ai';
    const RedcapRAGInstanceModuleName = 'redcap_rag';

    const DEFAULT_PROJECT_IDENTIFIER = 'chatbot_contextdb';
    const RAG_CONTEXT_PREFIX = "RAG Data:\n\n";

    private $system_context;
    private $entityFactory;
    private $tokenizer;

    public function __construct() {
        parent::__construct();
    }

    public function redcap_every_page_top($project_id) {
        //THIS IS THE PROPER EXCLUDE LIST FOR CAPPY, comment out for now to make it function as INCLUDE so that i can limited test on prod
//        try {
//            // List of pages to exclude UI injection
//            $exclusion_list = $this->getSystemSetting("chatbot_exclude_list");
//            $excludedPages = array_map('trim', explode(",", $exclusion_list));
//
//            $currentPage = $_SERVER['SCRIPT_NAME'] ?? '';
//
//            // Inject UI unless the current page is in the exclusion list
//            foreach ($excludedPages as $excludedPage) {
//                if (strpos($currentPage, $excludedPage) !== false) {
//                    return; // Stop execution if the page is excluded
//                }
//            }
//
//            // Inject the UI
//            $this->injectIntegrationUI();
//
//        } catch (\Exception $e) {
//            \REDCap::logEvent('Exception injecting chatbot UI.', $e->getMessage());
//        }

        // TEMPORARY MAKE IT INCLUDE LIST SO I CAN LIMIT WHERE I TEST IT ON REDCAP PROD
        try {
            // List of pages to include UI injection (temporarily using the exclusion list setting)
            $exclusion_list = $this->getSystemSetting("chatbot_exclude_list"); // TODO: This is acting as an include list for now
            $excludedPages = array_map('trim', explode(",", $exclusion_list));

            $currentPage = $_SERVER['SCRIPT_NAME'] ?? '';
            $queryString = $_SERVER['QUERY_STRING'] ?? '';

            $this->emDebug($queryString, preg_match('/pid=(\d+)/', $queryString, $matches), $matches[1]);
            // Inject UI only if the current page is in the "include" list (reusing exclusion logic)
            $inject = false; // Temporary repurpose
            foreach ($excludedPages as $excludedPage) {
                if (strpos($currentPage, $excludedPage) !== false) {
                    $inject = true; // Mark for injection if included
                    break;
                }
                // Check if the query string contains a matching project ID
                if (preg_match('/pid=(\d+)/', $queryString, $matches) && $matches[1] == $excludedPage) {
                    $inject = true;
                    break;
                }
            }

            if (!$inject) {
                return; // Stop execution if the page is not in the "include" list
            }

            // Inject the UI
            $this->injectIntegrationUI();

        } catch (\Exception $e) {
            \REDCap::logEvent('Exception injecting chatbot UI.', $e->getMessage());
        }
    }

    public function injectJSMO($data = null, $init_method = null): void {
        echo $this->initializeJavascriptModuleObject();
        $cmds = [
            "window.chatbot_jsmo_module = " . $this->getJavascriptModuleObjectName()
        ];

        $initial_system_context = null;
        $data = !empty($initial_system_context) ? $initial_system_context : null;
        if (!empty($data)) $cmds[] = "window.chatbot_jsmo_module.data = " . json_encode($data);
        if (!empty($init_method)) $cmds[] = "window.chatbot_jsmo_module.afterRender(chatbot_jsmo_module." . $init_method . ")";
        ?>
        <script src="<?= $this->getUrl("assets/jsmo.js", true) ?>"></script>
        <script>
            $(function () { <?php echo implode(";\n", $cmds) ?> })
        </script>
        <?php
//        $title = "The Enigmatic Festival of Quirp: Unveiling Ancient Traditions";
//        $content = "The Festival of Quirp is an ancient and enigmatic celebration that has intrigued historians and anthropologists alike. Dating back to the early 3rd century, this festival was celebrated by the secluded Quirpian community, known for their profound connection with nature and celestial phenomena. The festival's highlight is the ceremonial lighting of the 'Eternal Flame,' believed to symbolize the community's everlasting spirit and unity. Participants don intricate costumes adorned with symbols representing the sun, moon, and stars, engaging in the 'Dance of the Moons,' a ritualistic performance said to harmonize human and cosmic energies. The festival also features the 'Feast of the Elements,' where attendees partake in a communal meal consisting of locally sourced foods, honoring the earth's bounty. Despite its decline in the modern era, the Festival of Quirp remains a subject of fascination, with scholars continually uncovering new insights into its rich cultural heritage.";
//        $this->getRedcapRAGInstance()->storeDocument($title, $content);
    }

    public function injectIntegrationUI() {
        $this->injectJSMO(null);
        $build_files = $this->generateAssetFiles();
        foreach ($build_files as $file) {
            echo $file;
        }
        echo '<div id="chatbot_ui_container"></div>';
    }

    public function generateAssetFiles(): array {
        $assetFolders = ['css', 'js', 'media'];
        $cwd = $this->getModulePath();
        $assets = [];

        foreach ($assetFolders as $folder) {
            $full_path = $cwd . self::BUILD_FILE_DIR . '/' . $folder;
            $dir_files = scandir($full_path);

            if (!$dir_files) {
                continue;
            }

            foreach ($dir_files as $file) {
                if (str_contains($file, '.js')) {
                    $assets[] = "<script type='module' crossorigin src='{$this->getUrl(self::BUILD_FILE_DIR . '/' . $folder . '/' . $file)}'></script>";
                } elseif (str_contains($file, '.css')) {
                    $assets[] = "<link rel='stylesheet' href='{$this->getUrl(self::BUILD_FILE_DIR . '/' . $folder . '/' . $file)}'>";
                }
            }
        }

        return $assets;
    }

    public function sanitizeInput($payload): array {
        $sanitized = [];
        if (is_array($payload)) {
            foreach ($payload as $message) {
                $sanitized[] = [
                    'role' => filter_var($message['role'], FILTER_SANITIZE_STRING),
                    'content' => filter_var($message['content'], FILTER_SANITIZE_STRING),
                ];
            }
        }
        return $sanitized;
    }

    public function formatResponse($response) {
        $content = $this->getSecureChatInstance()->extractResponseText($response);
        $role = $response['choices'][0]['message']['role'] ?? 'assistant';
        $id = $response['id'] ?? null;
        $model = $response['model'] ?? null;
        $usage = $response['usage'] ?? null;

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

    public function appendSystemContext($chatMlArray, $newContext) {
        $hasSystemContext = false;
        for ($i = 0; $i < count($chatMlArray); $i++) {
            if ($chatMlArray[$i]['role'] == 'system') {
                $chatMlArray[$i]['content'] .= "\n\nRAG Data:\n\n" . $newContext;
                $hasSystemContext = true;
                break;
            }
        }

        if (!$hasSystemContext) {
            array_unshift($chatMlArray, array("role" => "system", "content" => $newContext));
        }

        return $chatMlArray;
    }


    /**
     * Retrieves and formats the current REDCap project's metadata for chatbot context injection.
     *
     * @return string Formatted project context string.
     */
    public function getProjectContext()
    {
        global $Proj;

        // Check if the Project object is available
        if (!$Proj) {
            return false;
        }

        // Define cache parameters
        $cacheFile = sys_get_temp_dir() . "/redcap_project_metadata_{$Proj->project_id}.json";
        $cacheDuration = 3600; // Cache duration in seconds (e.g., 1 hour)

        // Check if cached data exists and is still valid
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < $cacheDuration)) {
            $cachedData = file_get_contents($cacheFile);
            if ($cachedData) {
                return $cachedData;
            }
        }

        // Initialize an associative array to hold project metadata
        $projectMetadata = [];

        // **1. Basic Project Information**
        $projectMetadata['ProjectTitle'] = $Proj->project['app_title'] ?? 'N/A';
        $purposeCode = $Proj->project['purpose'] ?? 0;
        $projectMetadata['ProjectPurpose'] = $purposeCode;
        $projectMetadata['CreationDate'] = $Proj->project['creation_time'] ?? 'N/A';
        $projectMetadata['ProjectStatus'] = $Proj->project['status'] ?? 'N/A';

        // **2. Instruments (Forms/Surveys)**
        $projectMetadata['Instruments'] = array_keys($Proj->forms);

        // **3. Events (For Longitudinal Projects)**
        if ($Proj->longitudinal) {
            $events = [];
            foreach ($Proj->eventInfo as $eventId => $event) {
                $events[] = [
                    'EventName' => $event['name'],
                    'EventLabel' => $event['descrip']
                ];
            }
            $projectMetadata['Events'] = $events;
        }

        // **4. Data Access Groups (If Applicable)**
        if (!empty($Proj->groups)) {
            $dataAccessGroups = [];
            foreach ($Proj->groups as $groupId => $groupName) {
                $dataAccessGroups[] = [
                    'GroupID' => $groupId,
                    'GroupName' => $groupName
                ];
            }
            $projectMetadata['DataAccessGroups'] = $dataAccessGroups;
        }

        // **5. User Roles and Permissions**
        $userRights = REDCap::getUserRights();
        $userRoles = [];
        foreach ($userRights as $username => $rights) {
            $userRoles[] = [
                'Username' => $username,
                'Role' => $rights['role'] ?? 'No Role',
                'Data Access Groups' => $rights['group_id'] ?? 'None'
            ];
        }
        $projectMetadata['UserRoles'] = $userRoles;

        // **6. Custom Project Attributes (e.g., IRB Number)**
        // Replace 'irb_number_field' with the actual field name where IRB number is stored
        // If IRB number is stored in project notes or another custom attribute, adjust accordingly
        $projectMetadata['IRBNumber'] = $Proj->project['irb_number_field'] ?? 'N/A';

        // **7. Branching Logic and Calculations (Optional)**
        // You can extract more detailed field information if needed
        $fields = [];
        foreach ($Proj->metadata as $field) {
            $fields[] = [
                'FieldName' => $field['field_name'],
                'FieldLabel' => $field['field_label'],
                'FieldType' => $field['field_type'],
                'Choices' => $field['select_choices_or_calculations'] ?? '',
                'BranchingLogic' => $field['branching_logic'] ?? '',
                'Validation' => $field['text_validation_type_or_show_slider_number'] ?? ''
            ];
        }
        $projectMetadata['Fields'] = $fields;

        // **8. Surveys Information (If Applicable)**
        if (!empty($Proj->surveys)) {
            $surveys = [];
            foreach ($Proj->surveys as $surveyId => $survey) {
                $surveys[] = [
                    'SurveyTitle' => $survey['title'],
                    'SurveyAuth' => $survey['auth'] ?? 'N/A',
                    'SurveyEmailInvitation' => $survey['email_inv'] ?? 'N/A',
                    // Add more survey-specific attributes as needed
                ];
            }
            $projectMetadata['Surveys'] = $surveys;
        }

        // **Convert the associative array to JSON**
        $json = json_encode($projectMetadata, JSON_PRETTY_PRINT);
        if (!$json) {
            return json_encode(["error" => "Failed to convert project metadata to JSON."]);
        }

        // **Cache the JSON output**
        file_put_contents($cacheFile, $json);

        return $json;
    }



    public function redcap_module_ajax($action, $payload, $project_id=null, $record, $instrument, $event_id, $repeat_instance,
                                       $survey_hash, $response_id, $survey_queue_hash, $page, $page_full, $user_id, $group_id) {
        switch ($action) {
            case "callAI":
                $messages = $this->sanitizeInput($payload);

                //DON'T WASTE LOGGING ON SYSTEM DATA INJECT IT HERE NOT ON THE INJECT JS
                $initial_system_context = $this->getSystemSetting('chatbot_system_context');
                if(!empty($initial_system_context)){
                    $messages = $this->appendSystemContext($messages, $initial_system_context);
                }

                //ADD IN PROJECT DICTIONARY IF IN PROJECT CONTEXT
                $current_project_context = $this->getProjectContext();
                if(!empty($current_project_context)){
                    // Format the context string
                    $current_project_context = "Project Metadata:\n" . $current_project_context;
                    $messages = $this->appendSystemContext($messages, $current_project_context);
                }

                //Add REDCap actions list to the system context
                $actions_list_json = $this->getSystemSetting('redcap_actions_list');
                if (!empty($actions_list_json)) {
                    $actions_list = json_decode($actions_list_json, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $actions_context = "Possible Actions:\n" . json_encode($actions_list, JSON_PRETTY_PRINT);
                        $messages = $this->appendSystemContext($messages, $actions_context);
                    }
                }

                // Inject additional instruction for action matching and sentiment analysis
                $action_matching_context = "Analyze the user's query to determine its intent, match it to the appropriate REDCap action from the provided list (only if relevant!), and determine the sentiment on a scale of 1 to 5 (where 1 is very negative and 5 is very positive). Include this in the response.";
                $messages = $this->appendSystemContext($messages, $action_matching_context);

                //FIND AND INJECT RAG TOO
                $projectIdentifier = self::DEFAULT_PROJECT_IDENTIFIER;
                $ragContext = $this->getRedcapRAGInstance()?->getRelevantDocuments($projectIdentifier, $messages) ?? [];

                foreach ($ragContext as $doc) {
                    $messages = $this->appendSystemContext($messages, self::RAG_CONTEXT_PREFIX . $doc['content']);
                }

                //$this->emDebug("initial general system context and project Metadata and RAG", $messages);

                //CALL API ENDPOINT WITH AUGMENTED CHATML
                $response = $this->getSecureChatInstance()->callAI("gpt-4o", array("messages" => $messages));
                $result = $this->formatResponse($response);

                $this->emDebug("calling SecureChatAI.callAI()", $result);
                return json_encode($result);

            default:
                throw new Exception("Action $action is not defined");
        }
    }

    /**
     * @return \Stanford\SecureChatAI\SecureChatAI
     * @throws \Exception
     */
    public function getSecureChatInstance(): \Stanford\SecureChatAI\SecureChatAI
    {
        if(empty($this->secureChatInstance)){
            $this->setSecureChatInstance(\ExternalModules\ExternalModules::getModuleInstance(self::SecureChatInstanceModuleName));
            return $this->secureChatInstance;
        }else{
            return $this->secureChatInstance;
        }
    }

    /**
     * @param \Stanford\SecureChatAI\SecureChatAI $secureChatInstance
     */
    public function setSecureChatInstance(\Stanford\SecureChatAI\SecureChatAI $secureChatInstance): void
    {
        $this->secureChatInstance = $secureChatInstance;
    }

    /**
     * Get the RedcapRAG module instance if available.
     *
     * @return \Stanford\RedcapRAG\RedcapRAG|null
     */
    public function getRedcapRAGInstance(): ?\Stanford\RedcapRAG\RedcapRAG
    {
        if (empty($this->redcapRAGInstance)) {
            try {
                $instance = \ExternalModules\ExternalModules::getModuleInstance(self::RedcapRAGInstanceModuleName);
                if ($instance) {
                    $this->setRedcapRAGInstance($instance);
                } else {
                    $this->emDebug("RedcapRAG module is not installed or enabled.");
                }
            } catch (\Exception $e) {
                $this->emError("Error loading RedcapRAG module: " . $e->getMessage());
            }
        }
        return $this->redcapRAGInstance ?? null;
    }



    /**
     * @param \Stanford\RedcapRAG\RedcapRAG $redcapRAGInstance
     */
    public function setRedcapRAGInstance(\Stanford\RedcapRAG\RedcapRAG $redcapRAGInstance): void
    {
        $this->redcapRAGInstance = $redcapRAGInstance;
    }


    /**
     * Runs daily to perform various checks and update the context database.
     */
    public function dailyCronRun() {
        try {
            $urls = array(
                 $this->getUrl('cron/check_em_project.php', true, true)
                ,$this->getUrl('cron/check_rssd_completions.php', true, true)
                ,$this->getUrl('cron/check_community_portal.php', true, true)
                ,$this->getUrl('cron/check_med_wiki.php', true, true)
            ); //has to be page

            foreach ($urls as $url) {
                try {
                    $client = new \GuzzleHttp\Client();
                    $response = $client->request('GET', $url, [\GuzzleHttp\RequestOptions::SYNCHRONOUS => true]);
                    $this->emDebug("Successfully ran cron for $url");
                } catch (\Exception $e) {
                    $this->emError("Error running cron for $url: " . $e->getMessage());
                }
            }
        } catch (\Exception $e) {
            \REDCap::logEvent('CRON JOB ERROR: ', $e->getMessage());
            Entities::createException('CRON JOB ERROR: ' . $e->getMessage());
        }
    }

    /**
     * Checks for completions in the RSSD system and updates the context database.
     */
    private function checkRSSDCompletions() {
        return;
        $projectIdentifier = self::DEFAULT_PROJECT_IDENTIFIER;
        // Implement RSSD completions check logic here
        // MY Atlassian API, TODO ADD EM Setting for this
        $title = "RSSD Completion Data";
        $content = "Example content from RSSD"; // Replace with actual fetched content
        $this->getRedcapRAGInstance()->storeDocument($projectIdentifier, $title, $content);
    }

    /**
     * Checks updates from the Community Portal and updates the context database.
     */
    private function checkCommunityPortal() {
        return;
        $projectIdentifier = self::DEFAULT_PROJECT_IDENTIFIER;
        // Implement Community Portal check logic here
        $title = "Community Portal Updates";
        $content = "Example content from the Community Portal"; // Replace with actual fetched content
        $this->getRedcapRAGInstance()->storeDocument($projectIdentifier, $title, $content);
    }

    /**
     * Checks updates from the MedWiki system and updates the context database.
     */
    private function checkMedWiki() {
        return;
        $projectIdentifier = self::DEFAULT_PROJECT_IDENTIFIER;
        // Implement MedWiki check logic here
        $title = "MedWiki Updates";
        $content = "Example content from MedWiki"; // Replace with actual fetched content
        $this->getRedcapRAGInstance()->storeDocument($projectIdentifier, $title, $content);
    }

    public function checkEMProject($catchAll = false) {
        $projectIdentifier = self::DEFAULT_PROJECT_IDENTIFIER;

        // Get last cron run timestamp or fallback to 24 hours ago
        $lastCronRun = $catchAll ? null : ($this->getSystemSetting('last_cron_run') ?? date("Y-m-d H:i:s", strtotime("-1 day")));

        // Fetch all data from the project
        $projectId = $this->getSystemSetting('rag_emtracking_pid');
        $fields = ['module_name', 'module_display_name', 'module_description', 'maintenance_fee', 'actual_monthly_cost', 'date_created'];
        $records = REDCap::getData([
            'project_id' => $projectId,
            'return_format' => 'array',
            'fields' => $fields,
            'events' => ['modules_arm_1']
        ]);

        foreach ($records as $recordId => $nested) {
            $fields = current($nested);

            // Skip records without a description
            if (empty($fields) || empty(trim($fields['module_description']))) {
                continue;
            }

            // If not in catch-all mode, skip records older than $lastCronRun
            $dateCreated = $fields['date_created'] ?? null;
            if (!$catchAll && $dateCreated && $dateCreated <= $lastCronRun) {
                continue; // Skip older records
            }

            $module_name = $fields['module_name'];
            $module_display_name = empty(trim($fields['module_display_name'])) ? $module_name : $fields['module_display_name'];
            $module_description = trim($fields['module_description']);

            $maintenanceFee = !empty($fields['maintenance_fee']) && $fields['maintenance_fee'] !== '0';
            $content = "Module Name: {$module_display_name}\nDescription: {$module_description}\n"
                . "Maintenance Fee: " . ($maintenanceFee ? "Yes" : "No") . "\nMonthly Cost: {$fields['actual_monthly_cost']}";

            // Call the checkAndStoreDocument method from the RAG instance
            $this->getRedcapRAGInstance()?->checkAndStoreDocument($projectIdentifier, $module_name, $content, $dateCreated);
        }

        // Update last cron run timestamp if not in catch-all mode
        if (!$catchAll) {
            $this->setSystemSetting('last_cron_run', date("Y-m-d H:i:s"));
        }
    }
}
?>
