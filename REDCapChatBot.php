<?php
namespace Stanford\REDCapChatBot;

require 'vendor/autoload.php';
require_once "emLoggerTrait.php";

// On-demand autoloader so SecureChatAI's hook loader can find our hook class
// without an eager require_once (which previously crashed the EM-enable path).
// Triggered the first time any code calls class_exists('Stanford\REDCapChatBot\…').
spl_autoload_register(function ($class) {
    $prefix = 'Stanford\\REDCapChatBot\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) return;
    $rel = substr($class, strlen($prefix));
    $file = __DIR__ . '/classes/' . str_replace('\\', '/', $rel) . '.php';
    if (is_file($file)) require_once $file;
});

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
        // Hide EM API actions table from non-superusers on the project API page
        if (defined('PAGE') && PAGE === 'API/project_api' && !(defined('SUPER_USER') && SUPER_USER)) {
            echo '<style>#external-modules-api-actions { display: none !important; }</style>';
        }

        $sysEnabled = (string)$this->getSystemSetting('enable-system-ui-injection') === '1';

        // System injection off and not inside a project — nothing to do
        if (!$sysEnabled && empty($project_id)) {
            return;
        }

        try {
            $this->injectIntegrationUI($project_id);
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

    public function injectIntegrationUI($project_id = null) {
        $config_pid = $project_id ?: $this->getSystemSetting('rexi-config-project');

        if (!empty($config_pid)) {
            $title             = $this->getProjectSetting('project_chatbot_title', $config_pid);
            $intro_text        = $this->getProjectSetting('project_chatbot_intro', $config_pid);
            $chat_initiator    = $this->getProjectSetting('project_chat_initiator', $config_pid) ?: '';
            $allowed_ns        = $this->getProjectSetting('project_allowed_context_namespaces', $config_pid) ?: '';
            $expanded_width    = $this->getProjectSetting('project_expanded_width', $config_pid);
            $expanded_height   = $this->getProjectSetting('project_expanded_height', $config_pid);
            $hide_message_meta = $this->getProjectSetting('hide_message_meta', $config_pid) ?: 0;
            $custom_css        = $this->getProjectSetting('project_chatbot_custom_css', $config_pid) ?? '';
        } else {
            $title = $intro_text = $allowed_ns = $expanded_width = $expanded_height = null;
            $chat_initiator = '';
            $hide_message_meta = 0;
            $custom_css = '';
        }
        if (empty($title)) $title = $this->getSystemSetting('chatbot_title');

        $globalUsername = $_SESSION['username'] ?? null;

        $cappy_url = $this->getUrl('chatbot_ui/src/assets/images/cappy.png');
        echo '<style>:root { --cappy-url: url(' . json_encode($cappy_url) . '); }</style>';
        if (!empty($custom_css)) {
            echo '<style>' . $custom_css . '</style>';
        }

        echo '<script>window.cappy_project_config = ' . json_encode([
            'pid'                        => $config_pid ?: null,
            'title'                      => $title,
            'intro'                      => $intro_text,
            'chat_initiator'             => $chat_initiator,
            'current_user'               => $globalUsername,
            'expanded_width'             => $expanded_width,
            'expanded_height'            => $expanded_height,
            'allowed_context_namespaces' => array_values(array_filter(array_map('trim', explode(',', $allowed_ns ?: '')))),
            'hide_message_meta'          => $hide_message_meta,
        ]) . ';</script>';

        $this->injectJSMO(null);
        $build_files = $this->generateAssetFiles();
        foreach ($build_files as $file) {
            echo $file;
        }
        echo '<div id="chatbot_ui_container"></div>';
        // In-page action layer (scan / highlight / fill). Loads AFTER the JSMO bridge so
        // it can wrap callAI. Same-origin — no extension needed. Gated behind the
        // "Enable Page Actions" project setting so it's fully dormant (no script, no tool
        // advertising, no page scan) unless explicitly turned on for the project.
        $pageActionsEnabled = !empty($config_pid)
            && (bool) $this->getProjectSetting('enable_page_actions', $config_pid);
        if ($pageActionsEnabled) {
            echo '<script src="' . $this->getUrl('assets/cappy-actions.js', true) . '"></script>';
        }
        echo '<script>
(function() {
    window.addEventListener("message", function(e) {
        var container = document.getElementById("chatbot_ui_container");
        if (!container) return;
        if (e.data && e.data.type === "full-screen") {
            container.classList.toggle("cappy-fullscreen");
        }
    });
})();
</script>';
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
                // Match only actual bundle files — NOT sidecars like *.js.map or
                // *.js.LICENSE.txt, which str_contains('.js') wrongly caught and
                // injected as <script type=module>, throwing MIME/parse errors.
                if (str_ends_with($file, '.js')) {
                    $assets[] = "<script type='module' crossorigin src='{$this->getUrl(self::BUILD_FILE_DIR . '/' . $folder . '/' . $file)}'></script>";
                } elseif (str_ends_with($file, '.css')) {
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
                    'role' => $message['role'] ?? '',
                    'content' => $message['content'] ?? '',
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
    public function getREDCapProjectContext($instrument = null)
    {
        global $Proj;

        // Check if the Project object is available
        if (!$Proj) {
            return false;
        }

        // **Fields** — the actual bloat. Scope to the CURRENT instrument when known:
        //  - active instrument  -> full detail (choices/branching/validation) for just that form
        //  - no instrument       -> slim name/label/type list for all fields (avoids dumping the
        //                           entire, possibly huge, dictionary into every prompt)
        $fields = [];
        foreach ($Proj->metadata as $field) {
            $formName = $field['form_name'] ?? null;
            if ($instrument && $formName !== $instrument) {
                continue;
            }
            if ($instrument) {
                $fields[] = [
                    'FieldName'      => $field['field_name'],
                    'FieldLabel'     => $field['field_label'],
                    'FieldType'      => $field['field_type'],
                    'Form'           => $formName,
                    'Choices'        => $field['element_enum'] ?? ($field['select_choices_or_calculations'] ?? ''),
                    'BranchingLogic' => $field['branching_logic'] ?? '',
                    'Validation'     => $field['element_validation_type'] ?? ($field['text_validation_type_or_show_slider_number'] ?? ''),
                ];
            } else {
                $fields[] = [
                    'FieldName'  => $field['field_name'],
                    'FieldLabel' => $field['field_label'],
                    'FieldType'  => $field['field_type'],
                    'Form'       => $formName,
                ];
            }
        }

        // Cache key carries a SIGNATURE of the scoped fields, so a data-dictionary edit
        // invalidates it immediately (the old fixed 1h TTL served stale context after edits).
        // A short mtime TTL still refreshes the project-wide bits (roles/surveys) periodically.
        $scope     = $instrument ?: 'all';
        $signature = substr(md5(json_encode($fields)), 0, 12);
        $cacheFile = sys_get_temp_dir() . "/redcap_ctx_{$Proj->project_id}_{$scope}_{$signature}.json";
        $cacheTtl  = 3600;
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < $cacheTtl)) {
            $cachedData = file_get_contents($cacheFile);
            if ($cachedData !== false && $cachedData !== '') {
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
        if ($instrument) {
            $projectMetadata['CurrentInstrument'] = $instrument;
        }

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

        // **5. Custom Project Attributes (e.g., IRB Number)**
        $projectMetadata['IRBNumber'] = $Proj->project['irb_number_field'] ?? 'N/A';

        // **6. Fields (scoped — see above)**
        $projectMetadata['Fields'] = $fields;
        $projectMetadata['FieldScope'] = $instrument
            ? "Detailed fields for the current instrument ({$instrument}). For another instrument's fields, ask the user which one."
            : "Field names only across all instruments. Open an instrument for full field detail.";

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
     * @param string $key The setting key
     * @param mixed $default Default value if setting not found
     * @param int|null $project_id Project ID to use for project settings (optional)
     */
    public function getSetting($key, $default = null, $project_id = null) {
        // Try project setting first
        if (!empty($project_id)) {
            $project_val = $this->getProjectSetting($key, $project_id);
            if ($project_val !== '' && (!empty($project_val) || $project_val === 0)) return $project_val;
        }

        // Strip project prefix (hyphen or underscore) to derive system-level key
        if (strpos($key, 'project-') === 0) {
            $system_key = substr($key, strlen('project-'));
        } elseif (strpos($key, 'project_') === 0) {
            $system_key = substr($key, strlen('project_'));
        } else {
            $system_key = $key;
        }

        $sys_val = $this->getSystemSetting($system_key);
        if ($sys_val !== '' && (!empty($sys_val) || $sys_val === 0)) return $sys_val;

        // Fallback: try exact key if derived key differed
        if ($system_key !== $key) {
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


    /**
     * Agent tool endpoint (SecureChatAI calls this directly per tools.json).
     *
     * These are Cappy FRONTEND actions: the highlight/fill happens client-side in
     * assets/cappy-actions.js from the tools_used arguments. The server only echoes
     * intent so the agent loop can continue. Requires this module's prefix
     * (redcap-em-chatbot) in SecureChatAI's *_agent_tool_em_prefixes setting.
     */
    public function redcap_module_api($action = null, $payload = [])
    {
        switch ($action) {
            case "page_highlight":
                $target = $payload['field'] ?? ($payload['control_id'] ?? null);
                return [
                    "status" => "highlighted",
                    "target" => $target,
                    "note"   => "The highlight is being drawn on the user's page."
                ];

            case "page_fill":
                return [
                    "status" => "proposed_awaiting_confirmation",
                    "field"  => $payload['field'] ?? null,
                    "note"   => "The value was proposed on the user's page; they must confirm before it is written."
                ];

            case "page_clearHighlights":
                return ["status" => "cleared"];

            default:
                return ["error" => true, "message" => "Unknown action: $action"];
        }
    }


    public function redcap_module_ajax($action, $payload, $project_id=null, $record, $instrument, $event_id, $repeat_instance,
                                       $survey_hash, $response_id, $survey_queue_hash, $page, $page_full, $user_id, $group_id) {
        switch ($action) {
            case "callAI":
                // If no project context, use the RExI config project for settings
                $config_pid = $project_id ?: $this->getSystemSetting('rexi-config-project');

                // Extract messages and session_id from payload (new structure: {messages: [], session_id: string})
                $messages = isset($payload['messages']) ? $this->sanitizeInput($payload['messages']) : $this->sanitizeInput($payload);
                $model = $this->getSetting("project-llm-model", null, $config_pid);


                //DON'T WASTE LOGGING ON SYSTEM DATA INJECT IT HERE NOT ON THE INJECT JS
                $initial_system_context = $this->getSetting('project_chatbot_system_context', null, $config_pid);
                $escalation_guidance    = $this->getSetting('project_escalation_prompt_guidance', null, $config_pid);
                if (!empty($escalation_guidance)) {
                    $initial_system_context = trim(($initial_system_context ?? '') . "\n\n" . $escalation_guidance);
                }

                // Always tell the agent which project it is operating in so tool calls with required `pid` work.
                // HARD SCOPE: Cappy must never touch or discuss any project other than the current one, even
                // when the user has REDCap rights to others (the tools honor rights, so cross-project would leak).
                if (!empty($config_pid)) {
                    $pid_context = "You are operating ONLY in REDCap project ID {$config_pid}. "
                        . "Always use {$config_pid} as the pid for every record or project tool call. "
                        . "NEVER call a tool with a different pid, and NEVER answer questions about, or return data "
                        . "from, any other project — even if the user names another project, has access to it, or "
                        . "explicitly asks you to. If the user asks about a different project, politely decline and "
                        . "explain that you can only help with the current project ({$config_pid}).";
                    $initial_system_context = $pid_context . (!empty($initial_system_context) ? "\n\n" . $initial_system_context : '');
                }

                // Display convention: when the user asks for record listings (IDs,
                // values, sample rows), always render as a GFM markdown table —
                // pipe-delimited with a header row. Inline tables render cleanly
                // in this chat UI; a bulleted or numbered list is harder to scan.
                $initial_system_context = (!empty($initial_system_context) ? $initial_system_context . "\n\n" : '')
                    . "When you return record IDs, field values, or sample rows, ALWAYS format them as a GitHub-flavored markdown table (| col1 | col2 |, header row, alignment row with ---). Do not use bullet points or comma-separated prose for tabular data."
                    . (!empty($initial_system_context) ? '' : '');

                //ADD IN PROJECT DICTIONARY IF IN PROJECT CONTEXT
                $inject_metadata = !empty($config_pid) ? $this->getProjectSetting('inject-project-metadata', $config_pid) : false;
                if ($inject_metadata) {
                    $current_project_context = $this->getREDCapProjectContext($instrument);
                    if (!empty($current_project_context)) {
                        $current_project_context = "Project Metadata:\n" . $current_project_context;
                        $messages = $this->appendSystemContext($messages, $current_project_context);
                    }
                }

                //FIND AND INJECT RAG TOO
                // Get RAG EM instance and read namespace from its project settings, fallback to system setting
                $ragInstance = $this->getRedcapRAGInstance();
                $rag_namespace = null;
                // 1. Explicit project-level override (RedcapRAG project setting)
                if ($ragInstance && !empty($config_pid)) {
                    $rag_namespace = $ragInstance->getProjectSetting('rag_target_namespace', $config_pid);
                }
                // 2. Project context default — project_{pid} (matches RAG storage default)
                if (empty($rag_namespace) && !empty($config_pid)) {
                    $rag_namespace = "project_{$config_pid}";
                }
                // 3. System-level fallback (only when no project context at all)
                if (empty($rag_namespace)) {
                    $rag_namespace = $this->getSystemSetting('rag_target_namespace');
                }
                $this->emDebug("RAG debug", [
                    'ragInstance'   => $ragInstance ? get_class($ragInstance) : 'NULL',
                    'rag_namespace' => $rag_namespace,
                    'config_pid'    => $config_pid,
                    'last_msg_role' => end($messages)['role'] ?? 'none',
                ]);
                if ($ragInstance && !empty($rag_namespace)) {
                    $ragContext = $ragInstance->getRelevantDocuments($rag_namespace, $messages) ?? [];
                } else {
                    $ragContext = [];
                }
                $this->emDebug("RAG result", ['doc_count' => count($ragContext)]);
                foreach ($ragContext as $doc) {
                    $this->emDebug("GOT RAG?!", $doc);
                    $messages = $this->appendSystemContext($messages, self::RAG_CONTEXT_PREFIX . $doc['content']);
                }

                // FINALLY ADD THE SYSTEM PROMPT
                if(!empty($initial_system_context)){
                    $messages = $this->appendSystemContext($messages, $initial_system_context);
                }

                $this->emDebug("Full message context with RAG", [
                    'message_count' => count($messages),
                    'has_rag' => !empty($ragContext),
                    'messages_preview' => array_map(function($msg) {
                        return [
                            'role' => $msg['role'],
                            'content_length' => strlen($msg['content'] ?? ''),
                            'content_preview' => substr($msg['content'] ?? '', 0, 200)
                        ];
                    }, $messages)
                ]);

                // Only add params if they're set (not null/empty string)
                $override_params = ["messages" => $messages];
                $this->setIfNotBlank($override_params, "temperature", $this->getSetting("project-gpt-temperature", null, $config_pid), 'float');
                $this->setIfNotBlank($override_params, "top_p", $this->getSetting("project-gpt-top-p", null, $config_pid), 'float');
                $this->setIfNotBlank($override_params, "frequency_penalty", $this->getSetting("project-gpt-frequency-penalty", null, $config_pid), 'float');
                $this->setIfNotBlank($override_params, "presence_penalty", $this->getSetting("project-gpt-presence-penalty", null, $config_pid), 'float');
                $this->setIfNotBlank($override_params, "max_tokens", $this->getSetting("project-gpt-max-tokens", null, $config_pid), 'int');
                $this->setIfNotBlank($override_params, "reasoning", $this->getSetting("project-reasoning-effort", null, $config_pid));

                $agent_mode = (bool) $this->getSystemSetting('agent-mode')
                    && (bool) $this->getProjectSetting('agent_mode', $config_pid);
                if ($agent_mode) {
                    $override_params["agent_mode"] = true;
                }
                
                // Pass through session_id for audit logging if provided
                if (!empty($payload['session_id'])) {
                    $override_params['session_id'] = $payload['session_id'];
                }

                $response = $this->getSecureChatInstance()->callAI($model, $override_params, $config_pid, $user_id);
                $result = $this->formatResponse($response);

                $this->emDebug("calling SecureChatAI.callAI()", $result);

                // Debug response size and RAG context to identify WAF triggers
                $json_result = json_encode($result);
                if ($json_result === false) {
                    $this->emError("JSON encoding failed", [
                        'error' => json_last_error_msg(),
                        'result_keys' => array_keys($result)
                    ]);
                    return json_encode(['error' => 'Failed to encode response']);
                }

                $this->emDebug("Response payload analysis", [
                    'response_bytes' => strlen($json_result),
                    'response_kb' => round(strlen($json_result) / 1024, 2),
                    'has_rag' => !empty($ragContext),
                    'rag_doc_count' => count($ragContext ?? []),
                    'message_count' => count($override_params['messages'] ?? []),
                    'model' => $model,
                    'contains_script_tag' => (stripos($json_result, '<script') !== false),
                    'contains_select_keyword' => (stripos($json_result, 'SELECT') !== false)
                ]);

                return $json_result;

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
                // Get global RAG instance (no project_id to avoid version mismatch issues)
                // We'll read project-specific settings from the instance later
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
