<?php
namespace Stanford\REDCapChatBot;

require 'vendor/autoload.php';
require_once "emLoggerTrait.php";

use Phpml\FeatureExtraction\TfIdfTransformer;
use Phpml\Tokenization\WhitespaceTokenizer;
use \REDCapEntity\Entity;
use \REDCapEntity\EntityDB;
use REDCapEntity\EntityFactory;


class REDCapChatBot extends \ExternalModules\AbstractExternalModule {

    use emLoggerTrait;
    const BUILD_FILE_DIR = 'chatbot_ui/build/static/';

    private \Stanford\SecureChatAI\SecureChatAI $secureChatInstance;

    const SecureChatInstanceModuleName = 'SecureChatAI';

    private $system_context;
    private $tfIdfTransformer;
    private $tokenizer;

    public function __construct() {
        parent::__construct();
        $this->system_context = $this->getSystemSetting('chatbot_system_context');
        $this->tfIdfTransformer = new TfIdfTransformer();
        $this->tokenizer = new WhitespaceTokenizer();
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
                'tfidf_vector' => [
                    'name' => 'TF-IDF Vector',
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
                $response = $this->getSecureChatInstance()->callAI($messages);
                $result = $this->formatResponse($response);

                $this->emDebug("calling SecureChatAI.callAI()", $result);
                return json_encode($result);

            default:
                throw new Exception("Action $action is not defined");
        }
    }

    private function tokenize($text) {
        return $this->tokenizer->tokenize($text);
    }

    private function calculateIDF($allDocuments) {
        $idf = [];
        $totalDocuments = count($allDocuments);

        foreach ($allDocuments as $tokens) {
            foreach (array_unique($tokens) as $word) {
                if (!isset($idf[$word])) {
                    $idf[$word] = 0;
                }
                $idf[$word]++;
            }
        }

        foreach ($idf as $word => $count) {
            $idf[$word] = log($totalDocuments / $count);
        }

        return $idf;
    }

    private function calculateTFIDF($tokens, $idf) {
        // Step 1: Calculate Term Frequency (TF)
        $tf = array_count_values($tokens);
        $totalTokens = count($tokens);
        foreach ($tf as $word => $count) {
            $tf[$word] = $count / $totalTokens;
        }

        // Step 2: Calculate TF-IDF
        $tfidf = [];
        foreach ($tf as $word => $value) {
            $tfidf[$word] = $value * ($idf[$word] ?? 0);
        }

        return $tfidf;
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
        $queryTokens = $this->tokenize($query);

        try {
            // Check if the EntityFactory class is accessible
            if (!class_exists('REDCapEntity\EntityFactory')) {
                $this->emError("REDCapEntity\EntityFactory class does not exist.");
                return null;
            }

            // Instantiate EntityFactory
            $entityFactory = new EntityFactory();

            // Retrieve all entity IDs for IDF calculation
            $ids = $this->getAllEntityIds('chatbot_contextdb');

            // Retrieve all documents for IDF calculation using IDs
            $entities = $entityFactory->loadInstances('chatbot_contextdb', $ids);
        } catch (Exception $e) {
            $this->emError("Error retrieving entities: " . $e->getMessage());
            return null;
        } catch (Throwable $e) {
            $this->emError("Error retrieving entities (Throwable): " . $e->getMessage());
            return null;
        }

        if (empty($entities)) {
            return null;
        }

        $allDocuments = [];
        foreach ($entities as $entity) {
            $docTokens = json_decode($entity->getData()['tfidf_vector'], true);
            $allDocuments[] = $docTokens;
        }

        // Calculate IDF values
        $idf = $this->calculateIDF($allDocuments);

        // Calculate TF-IDF for the query
        $queryTFIDF = $this->calculateTFIDF($queryTokens, $idf);

        $documents = [];
        foreach ($entities as $entity) {
            $docTokens = json_decode($entity->getData()['tfidf_vector'], true);
            $similarity = $this->cosineSimilarity($queryTFIDF, $docTokens);

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
        $tokens = $this->tokenize($content);

        // Instantiate EntityFactory
        $entityFactory = new \REDCapEntity\EntityFactory();

        // Retrieve all entity IDs for IDF calculation
        $ids = $this->getAllEntityIds('chatbot_contextdb');

        // Retrieve all documents for IDF calculation using IDs
        $entities = $entityFactory->loadInstances('chatbot_contextdb', $ids);

        $allDocuments = [];
        foreach ($entities as $entity) {
            $docTokens = json_decode($entity->getData()['tfidf_vector'], true);
            $allDocuments[] = $docTokens;
        }
        $allDocuments[] = $tokens; // Include the new document

        // Calculate new IDF values
        $idf = $this->calculateIDF($allDocuments);

        // Calculate TF-IDF for the new document
        $tfidf = $this->calculateTFIDF($tokens, $idf);
        $serialized_vector = json_encode($tfidf);
        $data = [
            'title' => $title,
            'raw_content' => $content,
            'tfidf_vector' => $serialized_vector
        ];

        $this->emDebug("dos it get here?", $data);
        // Store the new document with its TF-IDF vector
        $entity = new \REDCapEntity\Entity($entityFactory, 'chatbot_contextdb');
        $entity->setData($data);
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
