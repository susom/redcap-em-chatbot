<?php
namespace Stanford\REDCapChatBot;
/** @var \Stanford\REDCapChatBot\REDCapChatBot $module */

$startTS = microtime(true);

$module->checkRSSDCompletions();

$module->emLog("checkRSSDCompletions() page time : " . microtime(true) - $startTS );