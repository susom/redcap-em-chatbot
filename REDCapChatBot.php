<?php
namespace Stanford\REDCapChatBot;

require 'vendor/autoload.php';
require_once "emLoggerTrait.php";
use REDCap;
use Project;
use Goutte\Client;

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
        $sysEnabled = (string)$this->getSystemSetting('enable-system-ui-injection') === '1';
        // If disabled and we're not inside a project with its own settings, stop immediately
        if (!$sysEnabled && empty($project_id)) {
            return;
        }

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
        // Check if the response is normalized (has `content`)
        if (isset($response['content'])) {
            $content = $response['content'];
            $role = $response['role'] ?? 'assistant';
        } else {
            // Handle raw responses (e.g., GPT-4o, Ada-002 pass-through)
            $content = $this->getSecureChatInstance()->extractResponseText($response);
            $role = $response['choices'][0]['message']['role'] ?? 'assistant';
        }

        // Common fields
        $id = $response['id'] ?? null;
        $model = $response['model'] ?? null;
        $usage = $response['usage'] ?? null;
        $tools_used = $response['tools_used'] ?? null; // Agent mode tool metadata

        // Return in required structure
        $formattedResponse = [
            'response' => [
                'role' => $role,
                'content' => $content
            ],
            'id' => $id,
            'model' => $model,
            'usage' => $usage
        ];

        // Include tool metadata if present (for UI indicators)
        if (!empty($tools_used)) {
            $formattedResponse['tools_used'] = $tools_used;
        }

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
    public function getREDCapProjectContext()
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


    /**
     * Get a project-level or system-level setting, with optional fallback.
     */
    public function getSetting($key, $default = null) {
        $project_val = $this->getProjectSetting($key);
        if ($project_val !== '' && (!empty($project_val) || $project_val === 0)) return $project_val;
    
        if (strpos($key, 'project-') === 0) {
            $system_key = substr($key, strlen('project-'));
            $sys_val = $this->getSystemSetting($system_key);
            if ($sys_val !== '' && (!empty($sys_val) || $sys_val === 0)) return $sys_val;
        } else {
            $sys_val = $this->getSystemSetting($key);
            if ($sys_val !== '' && (!empty($sys_val) || $sys_val === 0)) return $sys_val;
        }
        return $default;
    }
    
    public function setIfNotBlank(&$arr, $key, $value, $cast = null) {
        if ($value !== null && $value !== '') {
            $arr[$key] = ($cast === 'int') ? (int)$value
                       : (($cast === 'float') ? (float)$value : $value);
        }
    }
    

    public function redcap_module_ajax($action, $payload, $project_id=null, $record, $instrument, $event_id, $repeat_instance,
                                       $survey_hash, $response_id, $survey_queue_hash, $page, $page_full, $user_id, $group_id) {
        switch ($action) {
            case "callAI":
                $messages = $this->sanitizeInput($payload);
                $model = $this->getSetting("project-llm-model");


                // RAG project identifier
                $rag_project_identifier = $this->getSetting("project_rag_project_identifier", self::DEFAULT_PROJECT_IDENTIFIER);

                //DON'T WASTE LOGGING ON SYSTEM DATA INJECT IT HERE NOT ON THE INJECT JS
                $initial_system_context = ($this->getProjectSetting('project_chatbot_system_context')) ? $this->getProjectSetting('project_chatbot_system_context') : $this->getSystemSetting('chatbot_system_context');

                //ADD IN PROJECT DICTIONARY IF IN PROJECT CONTEXT
                if ($this->getProjectSetting('inject-project-metadata')) {
                    $current_project_context = $this->getREDCapProjectContext();
                    if (!empty($current_project_context)) {
                        $current_project_context = "Project Metadata:\n" . $current_project_context;
                        $messages = $this->appendSystemContext($messages, $current_project_context);
                    }
                }

                //FIND AND INJECT RAG TOO
                $ragContext = $this->getRedcapRAGInstance()?->getRelevantDocuments($rag_project_identifier, $messages) ?? [];
                foreach ($ragContext as $doc) {
                    $this->emDebug("GOT RAG?!", $doc);
                    $messages = $this->appendSystemContext($messages, self::RAG_CONTEXT_PREFIX . $doc['content']);
                }

                // FINALLY ADD THE SYSTEM PROMPT
                if(!empty($initial_system_context)){
                    $messages = $this->appendSystemContext($messages, $initial_system_context);
                }

                // $this->emDebug("initial general system context and project Metadata and RAG", $messages);

                // Only add params if they're set (not null/empty string)
                $override_params = ["messages" => $messages];
                $this->setIfNotBlank($override_params, "temperature", $this->getSetting("project-gpt-temperature"), 'float');
                $this->setIfNotBlank($override_params, "top_p", $this->getSetting("project-gpt-top-p"), 'float');
                $this->setIfNotBlank($override_params, "frequency_penalty", $this->getSetting("project-gpt-frequency-penalty"), 'float');
                $this->setIfNotBlank($override_params, "presence_penalty", $this->getSetting("project-gpt-presence-penalty"), 'float');
                $this->setIfNotBlank($override_params, "max_tokens", $this->getSetting("project-gpt-max-tokens"), 'int');
                $this->setIfNotBlank($override_params, "reasoning", $this->getSetting("project-reasoning-effort"));
                
                $agent_mode = (bool) $this->getSetting("agent_mode");
                if ($agent_mode) {
                    $override_params["agent_mode"] = true;
                }
                $response = $this->getSecureChatInstance()->callAI($model, $override_params, $project_id);
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
                 $this->getUrl('cron/check_em_project.php?cron=true', true, true)
                ,$this->getUrl('cron/check_rssd_completions.php?cron=true', true, true)
            //                ,$this->getUrl('cron/check_community_portal.php?cron=true', true, true)
            //                ,$this->getUrl('cron/check_med_wiki.php?cron=true', true, true)
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
     * Checks updates from the Community Portal and updates the context database.
     */
    public function checkCommunityPortal($cron = false) {
        return;
        // RAG project identifier
        $rag_project_identifier = $this->getSetting("project_rag_project_identifier", self::DEFAULT_PROJECT_IDENTIFIER);
        // Implement Community Portal check logic here
        $title = "Community Portal Updates";
        $content = "Example content from the Community Portal"; // Replace with actual fetched content
        $this->getRedcapRAGInstance()->storeDocument($rag_project_identifier, $title, $content);
    }

    /**
     * Checks updates from the MedWiki system and updates the context database.
     */
    public function checkMedWiki($cron = false) {
        $rag_project_identifier = $this->getSetting("project_rag_project_identifier", self::DEFAULT_PROJECT_IDENTIFIER);

        // File containing the HTML to parse
        $filePath = $this->getModulePath() . '/cron/rssd.txt';

        if (!file_exists($filePath)) {
            $this->emError("File rssd.txt not found in the specified directory. $filePath", $this->getModulePath());
            return;
        }

        try {
            // Load the HTML file
            $htmlContent = file_get_contents($filePath);

            // Use Symfony DOMCrawler to parse the content
            $crawler = new \Symfony\Component\DomCrawler\Crawler($htmlContent);

            // Extract the title
            $title = $crawler->filter('#title-text a')->text();

            // Extract and format the main content
            $mainContentNode = $crawler->filter('#main-content');
            $formattedContent = "";

            // Process the content in the order of appearance
            $mainContentNode->children()->each(function ($node) use (&$formattedContent) {
                if ($node->nodeName() === 'h2' || $node->nodeName() === 'h3') {
                    $formattedContent .= "## " . $node->text() . "\n\n";
                } elseif ($node->nodeName() === 'p') {
                    $formattedContent .= $node->text() . "\n\n";
                } elseif ($node->nodeName() === 'ul' || $node->nodeName() === 'ol') {
                    $node->filter('li')->each(function ($listItem) use (&$formattedContent) {
                        $formattedContent .= "- " . $listItem->text() . "\n";
                    });
                    $formattedContent .= "\n";
                }
            });

            // Extract images and their alt attributes
            $images = $mainContentNode->filter('img')->each(function ($node) {
                return [
                    'src' => $node->attr('src'),
                    'alt' => $node->attr('alt'),
                ];
            });

            // Add images to the content
            if (!empty($images)) {
                $formattedContent .= "Images:\n";
                foreach ($images as $image) {
                    $formattedContent .= "- " . ($image['alt'] ?: 'No Alt Text') . " (" . $image['src'] . ")\n";
                }
                $formattedContent .= "\n";
            }

            // Placeholder: Filter by date for cron runs
            if ($cron) {
                // TODO: Add logic to fetch only new/updated articles within the last 24 hours.
            } else {
                // TODO: Logic to fetch all available content for manual runs.
            }

            echo "<pre>";
            print_r($title);
            print_r($formattedContent);
            echo "</pre>";

            // Store the document in the context database
            //$this->getRedcapRAGInstance()->storeDocument($rag_project_identifier, $title, $formattedContent);
            $this->emLog("Stored MedWiki content: $title");

        } catch (\Exception $e) {
            $this->emError("Failed to fetch MedWiki updates: " . $e->getMessage());
        }
    }

    /**
     * Checks for completions in the RSSD system and updates the context database.
     */
    public function checkRSSDCompletions($cron = false) {
        $rag_project_identifier = $this->getSetting("project_rag_project_identifier", self::DEFAULT_PROJECT_IDENTIFIER);

        // Get API Key and other settings from EM System Settings
        $apiKey = $this->getSystemSetting('rag_atlassian_api');
        $jiraBaseURL = $this->getSystemSetting('rag_atlassian_baseurl');
        $email = $this->getSystemSetting('rag_atlassian_email');
        $jql = urlencode($this->getSystemSetting('rag_atlassian_jql'));

        if (empty($apiKey) || empty($jiraBaseURL) || empty($email)) {
            $this->emError("Missing necessary Atlassian system settings.");
            return;
        }

        try {
            $client = new \GuzzleHttp\Client();
            $startAt = 0;
            $maxResults = 50; // You can adjust this as needed
            $total = 0;

            do {
                $response = $client->request('GET', "$jiraBaseURL/rest/api/3/search?jql=$jql&fields=summary,description,comment,created&startAt=$startAt&maxResults=$maxResults", [
                    'auth' => [$email, $apiKey],
                ]);

                // Parse Jira API Response
                $tickets = json_decode($response->getBody(), true);
                $total = $tickets['total']; // Total number of tickets for the query

                if (empty($tickets['issues'])) {
                    $this->emLog("No tickets found for the specified JQL.");
                    break;
                }

                foreach ($tickets['issues'] as $ticket) {
                    $key = $ticket['key'];
                    $fields = $ticket['fields'];

                    // Filter out automatic or irrelevant tickets
                    if (stripos($fields['summary'], 'API token') !== false ||
                        stripos($fields['summary'], 'account reactivation') !== false ||
                        stripos($fields['description']['content'][0]['content'][0]['text'] ?? '', 'automatically generated') !== false) {
                        $this->emLog("Skipping irrelevant ticket: $key");
                        continue;
                    }

                    // Rehydrate the description into plain text
                    $description = $this->rehydrateContent($fields['description']);
                    $comments = !empty($fields['comment']['comments']) ? $this->rehydrateComments($fields['comment']['comments']) : '';

                    // Combine description and comments
                    $content = "Description:\n$description\n\nComments:\n$comments";

                    if (empty(trim($content))) {
                        $this->emLog("Skipping ticket with no meaningful content: $key");
                        continue;
                    }
                    $title = "Ticket: $key";
                    $dateCreated = $fields['created'];

                    // Store the document in RAG
                    $this->getRedcapRAGInstance()?->storeDocument($rag_project_identifier, $title, $content, $dateCreated);
                    $this->emLog("Stored ticket $key in RAG.");
                }

                // Increment startAt for the next page
                $startAt += $maxResults;

            } while ($startAt < $total); // Continue until all tickets are fetched
        } catch (\Exception $e) {
            $this->emError("Failed to fetch or process RSSD completions from Atlassian: " . $e->getMessage());
        }
    }

    /**
     * Rehydrate structured content into plain text.
     */
    private function rehydrateContent($content) {
        if (!isset($content['content']) || !is_array($content['content'])) {
            return '';
        }

        $text = '';
        foreach ($content['content'] as $block) {
            if (isset($block['type']) && $block['type'] === 'paragraph' && isset($block['content'])) {
                foreach ($block['content'] as $line) {
                    if (isset($line['text'])) {
                        $text .= $line['text'];
                    }
                    if (isset($line['type']) && $line['type'] === 'hardBreak') {
                        $text .= "\n";
                    }
                }
                $text .= "\n";
            }
        }

        return trim($text);
    }

    /**
     * Rehydrate Jira comments into plain text (exclude author names).
     */
    private function rehydrateComments($comments) {
        $text = '';
        foreach ($comments as $comment) {
            $created = $comment['created'] ?? '';
            $body = $this->rehydrateContent($comment['body'] ?? []);

            $text .= "Comment on $created:\n$body\n---\n";
        }

        return trim($text);
    }

    public function checkEMProject($cron = false) {
        $rag_project_identifier = $this->getSetting("project_rag_project_identifier", self::DEFAULT_PROJECT_IDENTIFIER);

        // Determine the last cron run timestamp if in cron mode
        $lastCronRun = $cron ? ($this->getSystemSetting('last_cron_run') ?? date("Y-m-d H:i:s", strtotime("-1 day"))) : null;

        // Get project ID and fields to fetch
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

            // In cron mode, skip records older than the last cron run
            $dateCreated = $fields['date_created'] ?? null;
            if ($cron && $dateCreated && $dateCreated <= $lastCronRun) {
                continue;
            }

            $module_name = $fields['module_name'];
            $module_display_name = empty(trim($fields['module_display_name'])) ? $module_name : $fields['module_display_name'];
            $module_description = trim($fields['module_description']);

            $maintenanceFee = !empty($fields['maintenance_fee']) && $fields['maintenance_fee'] !== '0';
            $content = "Module Name: {$module_display_name}\nDescription: {$module_description}\n"
                . "Maintenance Fee: " . ($maintenanceFee ? "Yes" : "No") . "\nMonthly Cost: {$fields['actual_monthly_cost']}";

            // Call the checkAndStoreDocument method from the RAG instance
            $this->getRedcapRAGInstance()?->checkAndStoreDocument($rag_project_identifier, $module_name, $content, $dateCreated);
        }

        // If in cron mode, update the last cron run timestamp
        if ($cron) {
            $this->setSystemSetting('last_cron_run', date("Y-m-d H:i:s"));
        }
    }
}
?>
