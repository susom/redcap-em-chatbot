<?php
namespace Stanford\REDCapChatBot;
/** @var \Stanford\REDCapChatBot\REDCapChatBot $module */

$startTS = microtime(true);

$module->checkCommunityPortal();

$module->emLog("checkCommunityPortal() page time : " . microtime(true) - $startTS );
