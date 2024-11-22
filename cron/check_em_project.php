<?php
namespace Stanford\REDCapChatBot;
/** @var \Stanford\REDCapChatBot\REDCapChatBot $module */

$startTS = microtime(true);

// Check for "cron" query parameter and cast to boolean for safety
$isCron = isset($_GET['cron']) ? filter_var($_GET['cron'], FILTER_VALIDATE_BOOLEAN) : false;

// Call the checkEMProject function with the determined mode
$module->checkEMProject($isCron);

$module->emLog("checkEMProject() page time: " . (microtime(true) - $startTS));
