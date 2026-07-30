<?php
namespace Stanford\REDCapChatBot;

// SecureChatAI may not be loaded yet at EM boot — load its interfaces
// directly. Look in common install locations: modules-local/ (sibling),
// modules/ (legacy), or anywhere on disk via the EM config registry.
// Try each candidate; first hit wins.
if (!interface_exists('Stanford\SecureChatAI\PreToolUseHook')) {
    $candidates = [];
    // Sibling modules-local/ (dev/staging layout)
    foreach (glob(dirname(__DIR__, 2) . '/secure_chat_ai_*/classes') as $d) $candidates[] = $d;
    // Sibling modules/ (prod layout, may be a symlink)
    foreach (glob(dirname(__DIR__, 2) . '/../modules/secure_chat_ai_*/classes') as $d) $candidates[] = $d;
    foreach (glob(dirname(__DIR__, 3) . '/modules/secure_chat_ai_*/classes') as $d) $candidates[] = $d;
    foreach ($candidates as $scaClasses) {
        if (is_file($scaClasses . '/HookInterface.php')) {
            require_once $scaClasses . '/HookInterface.php';
            require_once $scaClasses . '/HookResult.php';
            require_once $scaClasses . '/ToolUse.php';
            require_once $scaClasses . '/ToolContext.php';
            require_once $scaClasses . '/AbortController.php';
            break;
        }
    }
}

use Stanford\SecureChatAI\HookResult;
use Stanford\SecureChatAI\PreToolUseHook;
use Stanford\SecureChatAI\ToolContext;
use Stanford\SecureChatAI\ToolUse;

/**
 * Deterministic cross-project scope enforcement for Cappy.
 *
 * The "HARD SCOPE" string injected into the system prompt tells the model
 * to stay in the current project, but prompts aren't enforcement. This hook
 * runs BEFORE every tool execution in SecureChatAI's agent loop and denies
 * any tool call whose input references a different project — so a jailbroken
 * model, a distracted model, or any code path that bypasses the prompt
 * still can't fetch cross-project data.
 *
 * Rules:
 *   - Tool input has no pid / project_id             → DENY (fail-closed)
 *   - pid matches the current project                 → ALLOW
 *   - pid is non-empty but differs from current pid  → DENY
 *   - No current project determined (fail-closed)    → DENY
 *
 * Current project resolution (priority order, first non-null wins):
 *   1. $Proj->project_id (REDCap's canonical global; set by bootstrap from session)
 *   2. $this->framework->getProjectId() (EM framework method, also session-derived)
 *   3. $context->projectId (transitively trusted fallback from SecureChatAI)
 *
 * We deliberately do NOT trust the tool input's pid as the source of "current
 * project" — that would make the scope check self-referential and useless.
 *
 * Register by adding the FQCN below to SecureChatAI's
 *   system-settings:  pre_tool_use_hooks
 *   project-settings: project_pre_tool_use_hooks
 * via Control Center. Comma-separate multiple hooks.
 *
 *   \Stanford\REDCapChatBot\CappyScopePreHook
 */
class CappyScopePreHook implements PreToolUseHook
{
    public function handle(ToolUse $use, ToolContext $context): HookResult
    {
        $input = is_array($use->input) ? $use->input : [];

        // What the TOOL is asking to access.
        $requestedPid = null;
        if (array_key_exists('pid', $input) && $input['pid'] !== '' && $input['pid'] !== null) {
            $requestedPid = $input['pid'];
        } elseif (array_key_exists('project_id', $input) && $input['project_id'] !== '' && $input['project_id'] !== null) {
            $requestedPid = $input['project_id'];
        }

        // What the CURRENT REDCap session is scoped to — resolved from REDCap's
        // own trusted sources, NOT from the tool context. Falls through if
        // each layer is unavailable.
        global $Proj;
        $currentPid = null;
        if (isset($Proj) && is_object($Proj) && !empty($Proj->project_id)) {
            $currentPid = (int)$Proj->project_id;
        } elseif (method_exists($this, 'framework') && $this->framework && method_exists($this->framework, 'getProjectId')) {
            $currentPid = (int)$this->framework->getProjectId();
        } elseif (!empty($context->projectId)) {
            // Last-resort fallback: $context->projectId is populated by
            // SecureChatAI from REDCap's framework, so it's still
            // session-derived — just transitively rather than directly.
            $currentPid = (int)$context->projectId;
        }

        // Fail-closed on both ends: a tool with no pid reference would let
        // the model call system-wide tools like projects.search to discover
        // other projects, defeating the whole point of the scope check.
        // Better to block legitimate tools than leak.
        if ($requestedPid === null) {
            error_log(sprintf(
                '[CappyScopePreHook] DENY tool=%s reason=no_pid_in_input',
                $use->name
            ));
            return HookResult::deny(
                'Cappy hard-scope: tool "' . $use->name
                . '" has no pid/project_id in its input; '
                . 'refusing to run without explicit project scope.'
            );
        }

        // Fail-closed: if we can't determine the current project, deny.
        if (empty($currentPid)) {
            error_log(sprintf(
                '[CappyScopePreHook] DENY tool=%s requested_pid=%s reason=no_current_project',
                $use->name, (string)$requestedPid
            ));
            return HookResult::deny(
                'Cappy hard-scope: cannot determine the current project; '
                . 'refusing tool "' . $use->name . '" until scope is established.'
            );
        }

        // The actual scope check.
        if ((int)$requestedPid === $currentPid) {
            return HookResult::allow();
        }

        error_log(sprintf(
            '[CappyScopePreHook] DENY tool=%s requested_pid=%s current_pid=%d',
            $use->name, (string)$requestedPid, $currentPid
        ));
        return HookResult::deny(
            'Cappy hard-scope denied: tool "' . $use->name
            . '" attempted to access project ' . (string)$requestedPid
            . ', but Cappy is scoped to project ' . $currentPid . ' only.'
        );
    }
}