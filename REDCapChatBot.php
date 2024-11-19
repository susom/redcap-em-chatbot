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

    private $system_context;
    private $entityFactory;
    private $tokenizer;

    public function __construct() {
        parent::__construct();
    }

    public function redcap_every_page_top($project_id) {
        $this->emDebug("project_id!!", $project_id);

        try {
            preg_match('/redcap_v[\d\.].*\/index\.php/m', $_SERVER['SCRIPT_NAME'], $matches, PREG_OFFSET_CAPTURE);
            if (strpos($_SERVER['SCRIPT_NAME'], 'ProjectSetup') !== false || !empty($matches)) {
                $this->injectIntegrationUI();
            }
        } catch (\Exception $e) {
            \REDCap::logEvent('Exception initiating REDCap Chatbot.', $e->getMessage());
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
        $this->emdebug("injectIntegrationUI() End");
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
                if ($html !== '') {
                    $assets[] = $html;
                }
            }
        }

        return $assets;
    }

    public function sanitizeInput($payload): array|string {
        $sanitizedPayload = array();

        if (is_array($payload)) {
            foreach ($payload as $message) {
                if (
                    isset($message['role']) && is_string($message['role']) &&
                    isset($message['content']) && is_string($message['content'])
                ) {
                    $sanitizedRole = filter_var($message['role'], FILTER_SANITIZE_STRING);
                    $sanitizedContent = filter_var($message['content'], FILTER_SANITIZE_STRING);

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
            return json_encode(["error" => "Project information is unavailable."]);
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


    public function redcap_module_ajax($action, $payload, $project_id, $record, $instrument, $event_id, $repeat_instance,
                                       $survey_hash, $response_id, $survey_queue_hash, $page, $page_full, $user_id, $group_id) {
        switch ($action) {
            case "callAI":
                $messages = $this->sanitizeInput($payload);

                //DON'T WASTE LOGGING ON SYSTEM DATA INJECT IT HERE NOT ON THE INJECT JS
                $initial_system_context = $this->getSystemSetting('chatbot_system_context');
                if(!empty($initial_system_context)){
                    $messages = $this->appendSystemContext($messages, $initial_system_context);
                }

                //ADD IN PROJECT DICTIONARY!!
                $current_project_context = $this->getProjectContext();
                if(!empty($current_project_context)){
                    // Format the context string
                    $current_project_context = "Project Metadata:\n" . $current_project_context;
                    $messages = $this->appendSystemContext($messages, $current_project_context);
                }

                // Add REDCap actions list to the system context
                $actions_list_json = $this->getProjectSetting('redcap_actions_list');
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


                $this->emDebug("initial general system context and project Metadata", $messages);


                //FIND AND INJECT RAG TOO
                // Example: Inject RAG context into messages
                $redcapRAGInstance = $this->getRedcapRAGInstance();
                if ($redcapRAGInstance) {
                    try {
                        $relevantDocs = $redcapRAGInstance->getRelevantDocuments($messages);
                        if (!empty($relevantDocs)) {
                            $contentArray = array_column($relevantDocs, 'content');
                            $ragContext = implode("\n\n", $contentArray);
                            $messages = $this->appendSystemContext($messages, $ragContext);
                        }
                    } catch (\Exception $e) {
                        $this->emError("Error retrieving relevant documents from RedcapRAG: " . $e->getMessage());
                    }
                } else {
                    $this->emDebug("RedcapRAG is not available; skipping RAG context injection.");
                }


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
                 $this->getUrl('cron/check_rssd_completions.php', true, true)
                ,$this->getUrl('cron/check_community_portal.php', true, true)
                ,$this->getUrl('cron/check_med_wiki.php', true, true)
            ); //has to be page

            foreach($urls as $url){
                $client 	= new \GuzzleHttp\Client();
                $response 	= $client->request('GET', $url, array(\GuzzleHttp\RequestOptions::SYNCHRONOUS => true));
                $this->emDebug("running cron for $url on project $project_id");
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

        // Implement RSSD completions check logic here
        $title = "RSSD Completion Data";
        $content = "Example content from RSSD"; // Replace with actual fetched content
        $this->getRedcapRAGInstance()->storeDocument($title, $content);
    }

    /**
     * Checks updates from the Community Portal and updates the context database.
     */
    private function checkCommunityPortal() {
        return;

        // Implement Community Portal check logic here
        $title = "Community Portal Updates";
        $content = "Example content from the Community Portal"; // Replace with actual fetched content
        $this->getRedcapRAGInstance()->storeDocument($title, $content);
    }

    /**
     * Checks updates from the MedWiki system and updates the context database.
     */
    private function checkMedWiki() {
        return;

        // Implement MedWiki check logic here
        $title = "MedWiki Updates";
        $content = "Example content from MedWiki"; // Replace with actual fetched content
        $this->getRedcapRAGInstance()->storeDocument($title, $content);
    }
}
?>
