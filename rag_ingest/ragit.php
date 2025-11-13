<?php
/** @var \Stanford\REDCapChatBot\REDCapChatBot $module */
$rag = $module->getRedcapRAGInstance();
$projectIdentifier = $module->getSetting("project_rag_project_identifier");
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Cappy RAG Ingestion</title>
  <style>
    body { font-family: sans-serif; padding: 2rem; max-width: 700px; margin: auto; }
    h2 { margin-bottom: 0.25em; }
    input[type="file"] { margin: 1em 0; }
    pre.template { background: #f4f4f4; padding: 1em; white-space: pre-wrap; word-break: break-word; }
    .result { margin-top: 2em; white-space: pre-wrap; font-family: monospace; background: #f9f9f9; padding: 1em; border-left: 4px solid #ccc; }
  </style>
</head>
<body>
<h2>Cappy RAG Ingestion</h2>

<p>
  This will ingest RAG documents into the scope of this project:
</p>
<ul>
  <li><strong>Project Identifier:</strong> <code><?= htmlspecialchars($projectIdentifier) ?></code></li>
</ul>

<form method="POST" enctype="multipart/form-data">
  <input type="hidden" name="redcap_csrf_token" value="<?= $module->getCSRFToken() ?>">
  <label>Select one or more <code>.json</code> files to ingest:</label><br>
  <input type="file" name="rag_files[]" accept=".json" multiple required><br>
  <button type="submit">Upload & Ingest</button>
</form>

<h3>JSON File Template</h3>
<pre class="template">
{
  "source_url": "https://example.com/doc.html",
  "metadata": {
    "topics": ["consent", "privacy"]
  },
  "content": {
    "structured_sections": [
      {
        "heading": "Introduction",
        "level": 1,
        "content": "This is the intro text."
      }
    ],
    "tables": [
      {
        "title": "Consent Table",
        "headers": ["Item", "Response"],
        "rows": [["Q1", "Yes"], ["Q2", "No"]]
      }
    ]
  }
}
</pre>

<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['rag_files'])) {
    echo "<div class='result'><strong>Processing Uploaded Files...</strong>\n\n";

    if (!$rag) {
        echo "Error: Could not load RedcapRAG EM instance.\n";
    } else {
        $files = $_FILES['rag_files'];

        for ($i = 0; $i < count($files['name']); $i++) {
            $name = $files['name'][$i];
            $tmp = $files['tmp_name'][$i];

            echo "File: $name\n";

            $json = json_decode(file_get_contents($tmp), true);
            if (!$json) {
                echo "  ❌ Failed to parse JSON. Skipping.\n";
                continue;
            }

            $sections = $json['content']['structured_sections'] ?? [];
            $metadata = $json['metadata'] ?? [];
            $topics = $metadata['topics'] ?? [];
            $source_url = $json['source_url'] ?? '';

            foreach ($sections as $section) {
                $title = $section['heading'] ?? 'Untitled Section';
                $content = $section['content'] ?? '';
                $points = $section['points'] ?? [];
                if (!empty($points)) {
                    $content .= "\n\nPoints:\n- " . implode("\n- ", $points);
                }

                $level = $section['level'] ?? null;
                $meta = [
                    'level' => $level,
                    'topics' => $topics,
                    'source_url' => $source_url,
                    'file' => $name,
                    'links' => $metadata['links'] ?? []
                ];
                $doc = $content . "\n\n(Metadata: " . json_encode($meta) . ")";
                $rag->storeDocument($projectIdentifier, $title, $doc);
            }

            $tables = $json['content']['tables'] ?? [];
            foreach ($tables as $table) {
                $caption = $table['title'] ?? 'Table';
                $headers = implode(" | ", $table['headers'] ?? []);
                $rows = implode("\n", array_map(fn($r) => implode(" | ", $r), $table['rows'] ?? []));
                $table_content = "$caption\n$headers\n$rows";
                $meta = [
                    'type' => 'table',
                    'source_url' => $source_url,
                    'file' => $name
                ];
                $doc = $table_content . "\n\n(Metadata: " . json_encode($meta) . ")";
                $rag->storeDocument($projectIdentifier, $caption, $doc);
            }

            echo "  ✅ Finished ingesting $name\n\n";
        }
    }

    echo "All ingestion complete.</div>";
}
?>

</body>
</html>
