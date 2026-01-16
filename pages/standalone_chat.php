<?php
/** @var \Stanford\REDCapChatBot\REDCapChatBot $module */

// Determine which project to use for settings:
// 1. Use current project if pid is in URL
// 2. Otherwise use the RExI config project (system setting)
// 3. If neither, skip project settings and fallback to system settings only
$current_pid = isset($_GET['pid']) ? $_GET['pid'] : null;
$config_pid = $current_pid ?: $module->getSystemSetting('rexi-config-project');

// Only fetch project settings if we have a valid config project, otherwise use system defaults
if (!empty($config_pid)) {
    $initial_system_context = $module->getProjectSetting('project_chatbot_system_context', $config_pid);
    $title = $module->getProjectSetting('project_chatbot_title', $config_pid);
    $intro_text = $module->getProjectSetting('project_chatbot_intro', $config_pid);
    $allowed_types = $module->getProjectSetting('project_allowed_context_types', $config_pid) ?: '';
    $expanded_width = $module->getProjectSetting('project_expanded_width', $config_pid);
    $expanded_height = $module->getProjectSetting('project_expanded_height', $config_pid);
    $hide_message_meta = $module->getProjectSetting('hide_message_meta', $config_pid) ?: 0;
    $custom_css = $module->getProjectSetting('project_chatbot_custom_css', $config_pid) ?? "";
} else {
    // No config project - use empty defaults (will fall back to system settings below)
    $initial_system_context = null;
    $title = null;
    $intro_text = null;
    $allowed_types = '';
    $expanded_width = null;
    $expanded_height = null;
    $hide_message_meta = 0;
    $custom_css = "";
}

// Fallback to system settings if project settings are empty
if (empty($initial_system_context)) {
    $initial_system_context = $module->getSystemSetting('chatbot_system_context');
}
if (empty($title)) {
    $title = $module->getSystemSetting('chatbot_title');
}

$globalUsername = $_SESSION['username'];
if (!empty($globalUsername)) {
    $initial_system_context = "The current user's name is: {$globalUsername}. Please personalize your replies by addressing them directly when appropriate.\n\n" . $initial_system_context;
}
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
