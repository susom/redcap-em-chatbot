<?php
namespace Stanford\REDCapChatBot;
/** @var \Stanford\REDCapChatBot\REDCapChatBot $module */

$startTS = microtime(true);

$module->checkMedWiki();

$module->emLog("checkMedWiki() page time : " . microtime(true) - $startTS );
