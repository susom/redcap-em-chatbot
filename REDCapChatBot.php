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
        if (e.data && e.data.type === "fullscreen-on") {
            container.classList.add("cappy-fullscreen");
        }
        if (e.data && e.data.type === "fullscreen-off") {
            container.classList.remove("cappy-fullscreen");
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

    /**
     * Unwrap an agent-mode JSON envelope ({final_answer|tool_call|thinking})
     * that may have been persisted to the audit log verbatim when the model
     * was on a non-schema adapter (e.g. Claude served through the OpenAI
     * fallback path) and the inner JSON schema response leaked through.
     * Mirrors Chat.js::unwrapAgentEnvelope so server and client agree.
     *
     * Handles three shapes:
     *  1. Single JSON envelope:  {"final_answer": "..."}
     *  2. Concatenated envelopes (some models emit both a tool_call and a
     *     final_answer in one response, separated by whitespace):
     *     {"tool_call": {...}}\n{"final_answer": "..."}
     *  3. Plain text — returned unchanged.
     *
     * @param string $content Raw assistant content from the log
     * @return string Plain text final answer, or the original string if not an envelope
     */
    private function unwrapAgentEnvelope($content)
    {
        if (!is_string($content)) return $content;
        $trimmed = trim($content);
        if ($trimmed === '' || $trimmed[0] !== '{') return $content;
        // Strip markdown code fences just in case.
        $cleaned = trim(preg_replace('/^```json\s*|\s*```$/s', '', $trimmed));

        // Collect every balanced top-level JSON object in the string. Some
        // models emit two consecutive envelopes (tool_call + final_answer)
        // concatenated; treating the whole thing as one object fails to parse.
        $envelopes = $this->extractJsonEnvelopes($cleaned);
        if (empty($envelopes)) return $content;

        // If any envelope has a final_answer/content/message, prefer the LAST
        // one that does — that's the real final response when a tool_call
        // envelope precedes it.
        foreach (array_reverse($envelopes) as $env) {
            if (isset($env['final_answer']) && is_string($env['final_answer'])) {
                return $env['final_answer'];
            }
            if (isset($env['content']) && is_string($env['content'])) {
                return $env['content'];
            }
            if (isset($env['message']) && is_string($env['message'])) {
                return $env['message'];
            }
        }
        // All envelopes were tool_call-only (or empty) — caller should have
        // skipped this message via isToolCallOnlyEnvelope. If we got here
        // anyway, return the friendly placeholder rather than the raw JSON.
        foreach ($envelopes as $env) {
            if (!empty($env['tool_call'])) {
                return "I apologize, but I wasn't able to complete that request properly. Could you try rephrasing?";
            }
        }
        return $content;
    }

    /**
     * Extract balanced top-level JSON objects from a string. Returns the
     * decoded payload of each. Skips braces inside string literals.
     *
     * @param string $s
     * @return array<int, array> Decoded envelopes (only those that parse)
     */
    private function extractJsonEnvelopes($s)
    {
        $out = [];
        $len = strlen($s);
        $i = 0;
        while ($i < $len) {
            if ($s[$i] !== '{') { $i++; continue; }
            // Walk forward, tracking brace depth and skipping chars inside strings.
            $start = $i;
            $depth = 0;
            $inString = false;
            $escape = false;
            for ($j = $i; $j < $len; $j++) {
                $c = $s[$j];
                if ($inString) {
                    if ($escape) { $escape = false; continue; }
                    if ($c === '\\') { $escape = true; continue; }
                    if ($c === '"') { $inString = false; }
                    continue;
                }
                if ($c === '"') { $inString = true; continue; }
                if ($c === '{') { $depth++; continue; }
                if ($c === '}') {
                    $depth--;
                    if ($depth === 0) {
                        $candidate = substr($s, $start, $j - $start + 1);
                        $decoded = json_decode($candidate, true);
                        if (is_array($decoded)) $out[] = $decoded;
                        $i = $j + 1;
                        continue 2;
                    }
                }
            }
            // No balanced close — give up and stop scanning.
            break;
        }
        return $out;
    }

    /**
     * Detect an agent-mode JSON envelope that contains ONLY a tool_call —
     * i.e. the model decided to invoke a tool but did not produce a final
     * answer. In multi-step agent runs these are intermediate steps whose
     * real final answer is logged in a later assistant row; on a broken run
     * (max steps / parse error) they're the only record and unwrapAgentEnvelope
     * would convert them to the "I apologize" friendly message, which is
     * misleading when a later row exists.
     *
     * Handles single AND concatenated envelopes ({"tool_call":...}\n
     * {"final_answer":...}). If ANY envelope in the string carries a
     * final_answer/content/message, this returns false.
     *
     * @param string $content Raw assistant content from the audit log
     * @return bool True if content is purely tool_call-shaped with no final answer
     */
    private function isToolCallOnlyEnvelope($content)
    {
        if (!is_string($content)) return false;
        $trimmed = trim($content);
        if ($trimmed === '' || $trimmed[0] !== '{') return false;
        $cleaned = trim(preg_replace('/^```json\s*|\s*```$/s', '', $trimmed));
        $envelopes = $this->extractJsonEnvelopes($cleaned);
        if (empty($envelopes)) return false;
        $hasToolCall = false;
        foreach ($envelopes as $env) {
            if (!empty($env['final_answer']) && is_string($env['final_answer'])) return false;
            if (!empty($env['content']) && is_string($env['content'])) return false;
            if (!empty($env['message']) && is_string($env['message'])) return false;
            if (!empty($env['tool_call'])) $hasToolCall = true;
        }
        return $hasToolCall;
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
     * Return a compact `field_name → field_label` index for ALL fields in the
     * current project. Designed to be cheap enough to inject into every chat
     * prompt so the agent can resolve natural-language concepts ("severity",
     * "consented") to the right REDCap field without a tool round-trip.
     *
     * Cached on disk with a signature so data-dictionary edits auto-invalidate.
     * For a 600-field project with ~80-char labels, output is ~50KB (~12K tokens).
     */
    public function getFieldIndex(): string
    {
        global $Proj;

        if (!$Proj) {
            return '';
        }

        $rows = [];
        foreach ($Proj->metadata as $field) {
            $name  = $field['field_name']  ?? '';
            $label = $field['field_label'] ?? ($field['element_label'] ?? '');
            if ($name === '' || $label === '') continue;
            $rows[] = $name . "\t" . $label;
        }
        if (empty($rows)) {
            return '';
        }

        // Signature-based cache: any data-dictionary edit produces a new hash
        // and a new cache file, so stale context is impossible.
        $signature = substr(md5(implode("\n", $rows)), 0, 12);
        $cacheFile = sys_get_temp_dir() . "/redcap_field_index_{$Proj->project_id}_{$signature}.txt";
        $cacheTtl  = 3600;
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < $cacheTtl)) {
            $cached = @file_get_contents($cacheFile);
            if ($cached !== false && $cached !== '') return $cached;
        }

        $body = "Field index for project {$Proj->project_id} — name → label:\n" . implode("\n", $rows);
        @file_put_contents($cacheFile, $body);
        return $body;
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
            case "cappyPage":
                // UI-driven pagination against the server-side session cache.
                // The LLM returns a reference with its table; the chat UI calls
                // this directly when the user clicks prev/next — no AI round trip.
                $config_pid = $project_id ?: $this->getSystemSetting('rexi-config-project');
                $pid = (int)($payload['pid'] ?? 0);
                if (empty($config_pid) || $pid !== (int)$config_pid) {
                    return ["error" => true, "message" => "pid mismatch — can only page within the current project."];
                }
                $reference = $payload['reference'] ?? '';
                if ($reference === '') {
                    return ["error" => true, "message" => "Missing reference."];
                }
                $toolsModule = \ExternalModules\ExternalModules::getModuleInstance('redcap_agent_record_tools');
                if (!$toolsModule) {
                    return ["error" => true, "message" => "Record tools module not available."];
                }
                $result = $toolsModule->redcap_module_api('records_search', [
                    'pid' => $pid,
                    'reference' => $reference,
                    'offset' => max(0, (int)($payload['offset'] ?? 0)),
                    'limit' => min(100, max(1, (int)($payload['limit'] ?? 20))),
                ]);
                // Only send the UI what it needs — never raw rows.
                if (!empty($result['error'])) return $result;
                return [
                    "preview_markdown" => $result['preview_markdown'] ?? '',
                    "total" => (int)($result['total_record_count'] ?? 0),
                    "offset" => (int)($result['offset'] ?? 0),
                    "limit" => (int)($result['limit'] ?? 20),
                    "truncated" => !empty($result['truncated']),
                ];

            case "rebuildSession":
                // Reconstruct a chat session from server-side logs by session_id.
                // The client only persists sessionId in sessionStorage (no message
                // content) — on page navigation it pings this endpoint to pull
                // the conversation back out of redcap_external_modules_log via
                // SecureChatAI::rehydrateProjectSession.
                $config_pid = $project_id ?: $this->getSystemSetting('rexi-config-project');
                $pid = (int)($payload['pid'] ?? 0);
                if (empty($config_pid) || $pid !== (int)$config_pid) {
                    return ["error" => true, "message" => "pid mismatch — can only rebuild within the current project."];
                }
                $session_id = (string)($payload['session_id'] ?? '');
                if ($session_id === '') {
                    return ["error" => true, "message" => "Missing session_id."];
                }
                // Reject anything that doesn't look like our client-generated session_id
                // (Date.now().toString() — digits only, up to ~16 chars). Defense-in-depth
                // so the SQL LIKE fallback can't be tricked by weird characters.
                if (!preg_match('/^\d{1,20}$/', $session_id)) {
                    return ["error" => true, "message" => "Invalid session_id."];
                }
                $scModule = \ExternalModules\ExternalModules::getModuleInstance('secure_chat_ai');
                if (!$scModule || !method_exists($scModule, 'rehydrateProjectSession')) {
                    return ["error" => true, "message" => "SecureChatAI module not available."];
                }
                // Scope rehydration to the authenticated user so a guessable
                // client-generated session_id can't be used to rebuild another
                // user's chat (possible PHI) within the same project.
                $rehydrated = $scModule->rehydrateProjectSession($session_id, $pid, $user_id);
                $raw_messages = is_array($rehydrated['messages'] ?? null) ? $rehydrated['messages'] : [];

                // Adapt backend's {role, content, turn, tools_used} shape into
                // the UI's {user_content, assistant_content, timestamp, meta, tools_used}
                // shape so the existing restore loop in Chat.js works unchanged.
                // Also filter out internal agent-loop bookkeeping that leaked into
                // the audit log (injected TOOL RESULT messages, intermediate
                // tool_call-only assistant steps) so the chat history shows real
                // user prompts and final answers, not the agent's trace.
                $turns = [];
                foreach ($raw_messages as $idx => $m) {
                    $role = $m['role'] ?? null;
                    $content = $m['content'] ?? '';

                    // Skip injected TOOL RESULT lines. The agent loop adds these
                    // as role:user messages for the next LLM step, but they are
                    // not real user prompts.
                    if ($role === 'user' && is_string($content)
                        && strncmp(ltrim($content), 'TOOL RESULT [', 13) === 0) {
                        continue;
                    }

                    // Skip assistant messages that are JSON envelopes containing
                    // ONLY a tool_call (no final_answer/content/message). These
                    // are intermediate agent-loop steps; the actual final answer
                    // — if one was reached — is logged in a later assistant row
                    // for the same turn.
                    if ($role === 'assistant' && is_string($content) && $content !== ''
                        && $this->isToolCallOnlyEnvelope($content)) {
                        continue;
                    }

                    if ($role === 'assistant' && is_string($content) && $content !== '') {
                        $content = $this->unwrapAgentEnvelope($content);
                    }
                    if ($role === 'user') {
                        $turns[] = [
                            'user_content' => $content,
                            'assistant_content' => null,
                            'timestamp' => is_numeric($m['turn'] ?? null) ? (int)$m['turn'] : ($idx + 1),
                            'meta' => null,
                            'tools_used' => null,
                        ];
                    } elseif ($role === 'assistant') {
                        $turns[] = [
                            'user_content' => null,
                            'assistant_content' => $content,
                            'timestamp' => is_numeric($m['turn'] ?? null) ? (int)$m['turn'] : ($idx + 1),
                            'meta' => null,
                            'tools_used' => !empty($m['tools_used']) ? $m['tools_used'] : null,
                        ];
                    }
                }

                return [
                    'session_id' => $session_id,
                    'messages' => $turns,
                    'total' => count($turns),
                ];

            case "callAI":
                // Release the PHP session write-lock immediately. This handler
                // does long LLM work (multi-second agent loops) and never WRITES
                // $_SESSION (verified across all three EMs). PHP holds an
                // exclusive per-session lock for the whole request, and all of a
                // user's browser tabs share one session — so without this, a
                // user's other tabs serialize behind this tab's agent loop and
                // pile up until they look "stuck". Closing here holds the lock
                // for only the few ms of framework bootstrap. Reads of $_SESSION
                // still work after close. We deliberately do NOT re-open it:
                // re-acquiring the lock at the end of the request just
                // reintroduces the contention we are removing.
                if (session_status() === PHP_SESSION_ACTIVE) {
                    session_write_close();
                }

                // If no project context, use the RExI config project for settings
                $config_pid = $project_id;

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

                // Display convention + tool usage discipline. These are the
                // failure modes that keep recurring in the test runs.
                $table_hint = "TABULAR DATA FORMAT — when returning record IDs, field values, or sample rows:\n"
                    . "ALWAYS use a GitHub-flavored markdown table. Each row MUST be on its OWN line. Example:\n"
                    . "```\n"
                    . "| record_id | name           | mrn         |\n"
                    . "| ---       | ---           | ---         |\n"
                    . "| 50        | John Smith    | MRN1000500  |\n"
                    . "| 51        | Jane Doe      | MRN1000510  |\n"
                    . "```\n"
                    . "NEVER put the whole table on a single line. NEVER use bullet points or comma-separated prose for tabular data. If the table is long, paginate with a header every ~20 rows and a one-line note like '(continued)' between sections.\n"
                    . "ALWAYS SHOW THE DATA — when a records tool returns record data, the user wants to SEE it. When the tool result contains 'preview_markdown', render it VERBATIM as the table in your response (you may add a one-line summary above it like 'Showing 20 of 432'), then offer to show more or filter further. NEVER reply with only a count plus 'which fields would you like to see?' — the preview columns were already chosen sensibly (record_id + filter fields + most-populated fields). If the user later asks for different fields, re-render from the data you already have or page the reference.\n"
                    . "\n"
                    . "TOOL USAGE DISCIPLINE — these failure modes keep recurring. Avoid them:\n"
                    . "  1. NEVER ask the user for REDCap variable/field names. Call projects.getMetadata(pid=<pid>) to find them yourself, then call records.search with the field names you discovered.\n"
                    . "  2. LARGE RESULT HANDLING — when a tool returns total_record_count > 500, the preview_markdown is too noisy to be useful and the model loses coherence trying to narrate around a huge table. Instead of dumping the preview, briefly narrate the result count (e.g. '432 records match — that's a lot to show at once') and ask the user to narrow the query by adding a filter, limiting to specific fields, or restricting to a date range. Don't pretend the answer is unknowable — the data exists, the user just needs a more specific question. If the user insists on seeing all records despite the warning, fall back to paging with reference + offset/limit (records.search(pid=70, reference=\"ref_xxxxxxxx\", offset=N, limit=M)) — there is no hard cap, only a suggestion to narrow.\n"
                    . "  3. NEVER say 'I got stuck in a loop' or apologize for the same misunderstanding. If a tool returned data you didn't expect, re-read the tool result and try a different approach. Don't make the user rephrase the same request.\n"
                    . "  4. If the user gives a hint about which fields to show (e.g. 'AGE, GENDER, RACE'), treat it as a CONCEPT, not a literal REDCap field name. Call projects.getMetadata, find the matching fields (e.g. d_legal_sex, d_race_unc, dob), and use them.\n"
                    . "  5. When a tool result includes 'reference', PREFER to page through the cache with offset/limit rather than re-running the full query. The reference is an INTERNAL SESSION HANDLE — never mention it in your response to the user. The user should not see strings like 'ref_7001308a' in the chat. Page through the data, render the result, and never echo the reference id back at the user.";
                $initial_system_context = $table_hint . (!empty($initial_system_context) ? "\n\n" . $initial_system_context : '');

                //ADD IN PROJECT DICTIONARY IF IN PROJECT CONTEXT
                // 1. Slim field index (name → label for ALL fields) — default ON.
                //    Lets the agent disambiguate "severity" → the right field
                //    without a tool round-trip. Cheap (~3-12K tokens).
                $inject_index = !empty($config_pid)
                    ? (bool)$this->getProjectSetting('inject-field-index', $config_pid)
                    : false;
                if ($inject_index) {
                    $field_index = $this->getFieldIndex();
                    if (!empty($field_index)) {
                        $messages = $this->appendSystemContext($messages, $field_index);
                    }
                }

                // 2. Full form metadata (choices/branching/validation for the
                //    CURRENT form only) — opt-in, expensive on forms with long
                //    choice lists. Off by default.
                $inject_metadata = !empty($config_pid)
                    ? (bool)$this->getProjectSetting('inject-full-form-metadata', $config_pid)
                    : false;
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

                // PHI-safe: log metadata only — never message content.
                $this->emDebug("Full message context with RAG", [
                    'message_count' => count($messages),
                    'has_rag' => !empty($ragContext),
                    'messages_meta' => array_map(function($msg) {
                        return [
                            'role' => $msg['role'],
                            'content_length' => strlen($msg['content'] ?? ''),
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

                // Internal calls (e.g. client-side compaction summary) set
                // log_turn=false so they are excluded from turn-based rehydration.
                if (array_key_exists('log_turn', $payload) && $payload['log_turn'] === false) {
                    $override_params['log_turn'] = false;
                }

                // Session lock was already released at the top of this action, so
                // the long agent loop below runs without blocking the user's
                // other tabs. (See INVARIANT there: nothing in this path writes
                // $_SESSION.)
                $response = $this->getSecureChatInstance()->callAI($model, $override_params, $config_pid, $user_id);
                $result = $this->formatResponse($response);

                // PHI-safe: log response shape only, not the content.
                $this->emDebug("calling SecureChatAI.callAI()", [
                    'response_keys' => array_keys($result ?? []),
                    'has_tools_used' => !empty($result['tools_used']),
                    'tools_used_count' => count($result['tools_used'] ?? []),
                    'model' => $result['model'] ?? null,
                    'usage' => $result['usage'] ?? null,
                ]);

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
