<?php
namespace Stanford\REDCapChatBot;
/** @var \Stanford\REDCapChatBot\REDCapChatBot $module */

$startTS = microtime(true);

// Check for "cron" query parameter and cast to boolean for safety
$isCron = isset($_GET['cron']) ? filter_var($_GET['cron'], FILTER_VALIDATE_BOOLEAN) : false;

$module->checkRSSDCompletions($isCron);

$module->emLog("checkRSSDCompletions() page time : " . microtime(true) - $startTS );
