<?php
namespace Stanford\REDCapChatBot;

require_once "emLoggerTrait.php";

class REDCapChatBot extends \ExternalModules\AbstractExternalModule {

    use emLoggerTrait;
    const BUILD_FILE_DIR = 'chatbot_ui/build/static/';

    private \Stanford\SecureChatAI\SecureChatAI $secureChatInstance;

    //This should be "SecureChatAI in prod... but maybe different in local depending on what directory name you cloned it into"
    const SecureChatInstanceModuleName = 'SecureChatAI';

    public function __construct() {
        parent::__construct();
    }



    public function redcap_every_page_top($project_id) {
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

    public function redcap_module_ajax($action, $payload, $project_id, $record, $instrument, $event_id, $repeat_instance,
                                       $survey_hash, $response_id, $survey_queue_hash, $page, $page_full, $user_id, $group_id) {
        switch ($action) {
            case "callAI":
                $messages = $this->sanitizeInput($payload);
                $this->emDebug("hey payload secure_chat_ai", $payload, $messages);

                $response = $this->getSecureChatInstance()->callAI($messages);
                $this->emDebug("after getSecureChatInstance");

                $result = $this->formatResponse($response);

                $this->emDebug("calling SecureChatAI.callAI()", $result);
                return json_encode($result);

            default:
                throw new Exception("Action $action is not defined");
        }
    }

    /**
     * Store document embedding
     * @param string $title
     * @param string $content
     * @return void
     */
    public function storeDocumentEmbedding($title, $content) {
        $embedding = $this->generateEmbedding($content); // Generate embedding for the content
        $serialized_embedding = serialize($embedding); // Serialize the embedding

        $sql = "INSERT INTO document_embeddings (title, raw_content, embedding) VALUES (?, ?, ?)";
        $params = [$title, $content, $serialized_embedding];
        $this->query($sql, $params);
    }

    /**
     * Generate embedding for a given text
     * @param string $text
     * @return array
     */
    private function generateEmbedding($text) {
        // Placeholder for embedding generation logic using a pre-trained model
        return [];
    }

    /**
     * Retrieve relevant documents based on query
     * @param string $query
     * @return array
     */
    public function getRelevantDocuments($query) {
        $query_embedding = $this->generateEmbedding($query); // Generate embedding for the query

        $sql = "SELECT id, title, raw_content, embedding FROM document_embeddings";
        $result = $this->query($sql, []);

        $documents = [];
        while ($row = db_fetch_assoc($result)) {
            $document_embedding = unserialize($row['embedding']); // Deserialize the embedding
            $similarity = $this->cosineSimilarity($query_embedding, $document_embedding); // Calculate cosine similarity

            $documents[] = [
                'id' => $row['id'],
                'title' => $row['title'],
                'raw_content' => $row['raw_content'],
                'similarity' => $similarity
            ];
        }

        // Sort documents by similarity
        usort($documents, function($a, $b) {
            return $b['similarity'] <=> $a['similarity'];
        });

        // Return top N documents
        return array_slice($documents, 0, 5);
    }

    /**
     * Calculate cosine similarity between two vectors
     * @param array $vec1
     * @param array $vec2
     * @return float
     */
    private function cosineSimilarity($vec1, $vec2) {
        $dot_product = array_sum(array_map(fn($a, $b) => $a * $b, $vec1, $vec2));
        $magnitude1 = sqrt(array_sum(array_map(fn($x) => $x * $x, $vec1)));
        $magnitude2 = sqrt(array_sum(array_map(fn($x) => $x * $x, $vec2)));

        return $dot_product / ($magnitude1 * $magnitude2);
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


}
?>
