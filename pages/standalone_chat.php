<?php
/** @var \Stanford\REDCapChatBot\REDCapChatBot $module */

// Always use project context if present, fallback to system
$initial_system_context = $module->getProjectSetting('project_chatbot_system_context');
if (empty($initial_system_context)) {
    $initial_system_context = $module->getSystemSetting('chatbot_system_context');
}

$globalUsername = $_SESSION['username'];
if (!empty($globalUsername)) {
    $initial_system_context = "The current user's name is: {$globalUsername}. Please personalize your replies by addressing them directly when appropriate.\n\n" . $initial_system_context;
}
$title = $module->getProjectSetting('project_chatbot_title') ?: $module->getSystemSetting('chatbot_title') ?: null;
$intro_text = $module->getProjectSetting('project_chatbot_intro') ?: null;

// Allowed postMessage context types (comma-separated)
$allowed_types = $module->getProjectSetting('project_allowed_context_types') ?: '';

$expanded_width  = $module->getProjectSetting('project_expanded_width') ?: null;
$expanded_height = $module->getProjectSetting('project_expanded_height') ?: null;
$hide_message_meta   = $module->getProjectSetting('hide_message_meta') ?: 0;

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
        current_user: <?=json_encode($globalUsername) ?>,
        expanded_width: <?= json_encode($expanded_width) ?>,
        expanded_height: <?= json_encode($expanded_height) ?>,
        allowed_context_types: <?= json_encode(
            array_map('trim', explode(',', $allowed_types))
        ) ?>,
        hide_message_meta: <?= json_encode($hide_message_meta)?>
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
<script>
    window.parent.postMessage("cappy-loaded", "*");
</script>
</body>
</html>
