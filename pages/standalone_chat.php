<?php
/** @var \Stanford\REDCapChatBot\REDCapChatBot $module */

// Always use project context if present, fallback to system
$initial_system_context = $module->getProjectSetting('project_chatbot_system_context');
if (empty($initial_system_context)) {
    $initial_system_context = $module->getSystemSetting('chatbot_system_context');
}

$title = $module->getProjectSetting('project_chatbot_title') ?: $module->getSystemSetting('chatbot_title') ?: null;
$intro_text = $module->getProjectSetting('project_chatbot_intro') ?: null;

// Allowed postMessage context types (comma-separated)
$allowed_types = $module->getProjectSetting('project_allowed_context_types') ?: '';

$custom_css     = $module->getProjectSetting('project_chatbot_custom_css') ?? "";
$build_files    = $module->generateAssetFiles();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title><?= htmlspecialchars($title) ?></title>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <script>
    window.cappy_project_config = {
        title: <?= json_encode($title) ?>,
        intro: <?= json_encode($intro_text) ?>,
        allowed_context_types: <?= json_encode(
            array_map('trim', explode(',', $allowed_types))
        ) ?>,
    };
    </script>
    <?php 
        foreach ($build_files as $file):  
            echo $file;
        endforeach;
        
        // Inject JSMO with the initial context data
        $module->injectJSMO($initial_system_context);
    
        if (!empty($custom_css)) {
            echo "<style>
            $custom_css
            </style>";
        }
    ?>
</head>
<body>
    <div id="chatbot_ui_container"></div>
</body>
</html>
