<?php
namespace Stanford\REDCapChatBot;

require 'vendor/autoload.php';
require_once "emLoggerTrait.php";

use \REDCapEntity\Entity;
use \REDCapEntity\EntityDB;
use \REDCapEntity\EntityFactory;


class REDCapChatBot extends \ExternalModules\AbstractExternalModule {

    use emLoggerTrait;
    const BUILD_FILE_DIR = 'chatbot_ui/build/static/';

    private \Stanford\SecureChatAI\SecureChatAI $secureChatInstance;

    const SecureChatInstanceModuleName = 'secure_chat_ai';

    private $system_context;
    private $entityFactory;
    private $tokenizer;

    public function __construct() {
        parent::__construct();
        $this->entityFactory = new \REDCapEntity\EntityFactory();
    }

    // Define entity types
    public function redcap_entity_types() {
        $types = [];

        $types['chatbot_contextdb'] = [
            'label' => 'Chatbot Context',
            'label_plural' => 'Chatbot Contexts',
            'icon' => 'file',
            'properties' => [
                'title' => [
                    'name' => 'Title',
                    'type' => 'text',
                    'required' => true,
                ],
                'raw_content' => [
                    'name' => 'Raw Content',
                    'type' => 'long_text',
                    'required' => true,
                ],
                'embedding_vector' => [
                    'name' => 'Embedding Vector',
                    'type' => 'long_text',
                    'required' => true,
                ],
            ],
        ];

        return $types;
    }

    // Hook to trigger entity initialization on module enablement
    public function redcap_module_system_enable($version) {
        \REDCapEntity\EntityDB::buildSchema($this->PREFIX);
    }

    public function redcap_every_page_top($project_id) {
        try {
            preg_match('/redcap_v[\d\.].*\/index\.php/m', $_SERVER['SCRIPT_NAME'], $matches, PREG_OFFSET_CAPTURE);
            if (strpos($_SERVER['SCRIPT_NAME'], 'ProjectSetup') !== false || !empty($matches)) {
                $this->system_context = $this->getSystemSetting('chatbot_system_context');
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

        $initial_system_context = $this->appendSystemContext(array(), $this->system_context);
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
//        $this->storeDocument($title, $content);
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

    public function appendSystemContext($chatMlArray, $newContext) {
        $hasSystemContext = false;
        for ($i = 0; $i < count($chatMlArray); $i++) {
            if ($chatMlArray[$i]['role'] == 'system') {
                $chatMlArray[$i]['content'] .= '\n\n ' . $newContext;
                $hasSystemContext = true;
                break;
            }
        }

        if (!$hasSystemContext) {
            array_unshift($chatMlArray, array("role" => "system", "content" => $newContext));
        }

        return $chatMlArray;
    }

    public function redcap_module_ajax($action, $payload, $project_id, $record, $instrument, $event_id, $repeat_instance,
                                       $survey_hash, $response_id, $survey_queue_hash, $page, $page_full, $user_id, $group_id) {
        switch ($action) {
            case "callAI":
                $messages = $this->sanitizeInput($payload);

                //FIND AND INJECT RAG
                $relevantDocs = $this->getRelevantDocuments($messages);
                if (!empty($relevantDocs)) {
                    $ragContext = implode("\n\n", array_column($relevantDocs, 'raw_content'));
                    $messages = $this->appendSystemContext($messages, $ragContext);
                }

                //CALL API ENDPOINT WITH AUGMENTED CHATML
                $response = $this->getSecureChatInstance()->callAI("gpt-4o",$messages);
                $result = $this->formatResponse($response);

                $this->emDebug("calling SecureChatAI.callAI()", $result);
                return json_encode($result);

            default:
                throw new Exception("Action $action is not defined");
        }
    }

    private function getEmbedding($text) {
        try {
            $result = $this->getSecureChatInstance()->callAI("ada-002", $text);
            return $result['data'][0]['embedding'];
        } catch (GuzzleException $e) {
            $this->emError("Guzzle error: " . $e->getMessage());
            return null;
        }
    }

    private function getAllEntityIds($entityType) {
        $ids = [];
        $sql = 'SELECT id FROM `redcap_entity_' . db_escape($entityType) . '`';
        $result = db_query($sql);

        while ($row = db_fetch_assoc($result)) {
            $ids[] = $row['id'];
        }

        return $ids;
    }

    // Retrieve relevant documents method
    public function getRelevantDocuments($queryArray) {
        if (!is_array($queryArray) || empty($queryArray)) {
            return null;
        }

        $lastElement = end($queryArray);

        if (!isset($lastElement['role']) || $lastElement['role'] !== 'user' || !isset($lastElement['content'])) {
            return null;
        }

        $query = $lastElement['content'];
        $queryEmbedding = $this->getEmbedding($query);

        if (!$queryEmbedding) {
            return null;
        }

        $entities = $this->entityFactory->loadInstances('chatbot_contextdb', $this->getAllEntityIds('chatbot_contextdb'));
        $documents = [];

        foreach ($entities as $entity) {
            $docEmbedding = json_decode($entity->getData()['embedding_vector'], true);
            $similarity = $this->cosineSimilarity($queryEmbedding, $docEmbedding);

            $documents[] = [
                'id' => $entity->getId(),
                'title' => $entity->getData()['title'],
                'raw_content' => $entity->getData()['raw_content'],
                'similarity' => $similarity
            ];
        }

        usort($documents, function($a, $b) {
            return $b['similarity'] <=> $a['similarity'];
        });

        return array_slice($documents, 0, 3);
    }

    // Store document method
    public function storeDocument($title, $content) {
        // Get the embedding for the content
        $embedding = $this->getEmbedding($content);
        $serialized_embedding = json_encode($embedding);

        // Store the new document with its embedding vector
        $entity = new \REDCapEntity\Entity($this->entityFactory, 'chatbot_contextdb');

        $entity->setData([
            'title' => $title,
            'raw_content' => $content,
            'embedding_vector' => $serialized_embedding
        ]);

        $entity->save();
    }

    // Cosine similarity calculation method
    private function cosineSimilarity($vec1, $vec2) {
        $dotProduct = 0;
        $normVec1 = 0;
        $normVec2 = 0;

        foreach ($vec1 as $key => $value) {
            $dotProduct += $value * ($vec2[$key] ?? 0);
            $normVec1 += $value ** 2;
        }

        foreach ($vec2 as $value) {
            $normVec2 += $value ** 2;
        }

        $normVec1 = sqrt($normVec1);
        $normVec2 = sqrt($normVec2);

        if ($normVec1 == 0 || $normVec2 == 0) {
            return 0; // Return zero if either vector norm is zero
        }

        return $dotProduct / ($normVec1 * $normVec2);
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
