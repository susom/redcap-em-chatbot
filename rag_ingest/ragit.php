<?php
/** @var \Stanford\REDCapChatBot\REDCapChatBot $module */

echo "<pre>";
try {
    // Check RAG EM instance
    $rag = $module->getRedcapRAGInstance();
    if (!$rag) {
        echo "Error: Could not load RedcapRAG EM instance via Cappy.\n";
        exit;
    }

    // // For debugging: Test with Quirp
    $projectIdentifier = \Stanford\REDCapChatBot\REDCapChatBot::DEFAULT_PROJECT_IDENTIFIER;
    // $title = "The Enigmatic Festival of Quirp: Unveiling Ancient Traditions";
    // $content = "The Festival of Quirp is an ancient and enigmatic celebration that has intrigued historians and anthropologists alike. Dating back to the early 3rd century, this festival was celebrated by the secluded Quirpian community, known for their profound connection with nature and celestial phenomena. The festival's highlight is the ceremonial lighting of the 'Eternal Flame,' believed to symbolize the community's everlasting spirit and unity. Participants don intricate costumes adorned with symbols representing the sun, moon, and stars, engaging in the 'Dance of the Moons,' a ritualistic performance said to harmonize human and cosmic energies. The festival also features the 'Feast of the Elements,' where attendees partake in a communal meal consisting of locally sourced foods, honoring the earth's bounty. Despite its decline in the modern era, the Festival of Quirp remains a subject of fascination, with scholars continually uncovering new insights into its rich cultural heritage.";
    // $rag->storeDocument($projectIdentifier, $title, $content);
    // echo "Test doc (Quirp) stored.<br><br>";
    // exit;


    // Ingest all JSONs in rag_ingest dir
    $dir = __DIR__;
    $files = glob("$dir/*.json");
    foreach ($files as $file) {
        if (basename($file) === 'master_index.json') continue;
        echo "Processing: " . basename($file) . "\n";

        $json = json_decode(file_get_contents($file), true);
        if (!$json) {
            echo "  Failed to parse $file. Skipping.\n";
            continue;
        }

        $sections = $json['content']['structured_sections'] ?? [];
        $metadata = $json['metadata'] ?? [];
        $topics = $metadata['topics'] ?? [];
        $source_url = $json['source_url'] ?? '';

        // Ingest sections
        foreach ($sections as $section) {
            $title = $section['heading'] ?? 'Untitled Section';
            $content = $section['content'] ?? '';
            $level = $section['level'] ?? null;
            $meta = [
                'level' => $level,
                'topics' => $topics,
                'source_url' => $source_url,
                'file' => basename($file)
            ];
            $doc = $content . "\n\n(Metadata: " . json_encode($meta) . ")";
            $rag->storeDocument($projectIdentifier, $title, $doc);
        }

        // Ingest tables if present
        $tables = $json['content']['tables'] ?? [];
        foreach ($tables as $table) {
            $caption = $table['title'] ?? 'Table';
            $headers = implode(" | ", $table['headers'] ?? []);
            $rows = implode("\n", array_map(fn($r) => implode(" | ", $r), $table['rows'] ?? []));
            $table_content = "$caption\n$headers\n$rows";
            $meta = [
                'type' => 'table',
                'source_url' => $source_url,
                'file' => basename($file)
            ];
            $doc = $table_content . "\n\n(Metadata: " . json_encode($meta) . ")";
            $rag->storeDocument($project_identifier, $caption, $doc);
        }
        echo "  Finished: " . basename($file) . "\n";
    }
    echo "\nAll ingestion complete.<br>";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage();
}
echo "</pre>";
