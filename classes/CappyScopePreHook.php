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
 *   - Tool input has no pid / project_id             → ALLOW
 *   - pid matches the current session projectId      → ALLOW
 *   - pid is non-empty but differs from current pid  → DENY
 *   - No current projectId in context (fail-closed) → DENY
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
        $pid = null;
        if (array_key_exists('pid', $input) && $input['pid'] !== '' && $input['pid'] !== null) {
            $pid = $input['pid'];
        } elseif (array_key_exists('project_id', $input) && $input['project_id'] !== '' && $input['project_id'] !== null) {
            $pid = $input['project_id'];
        }
        $currentPid = $context->projectId;

        // Diagnostic — proves the hook fires pre-tool. Goes to php_errors.log
        // (or wherever PHP error_log is configured). Remove once verified.
        error_log(sprintf(
            '[CappyScopePreHook] FIRED tool=%s requested_pid=%s current_pid=%s',
            $use->name,
            $pid === null ? '(none)' : (string) $pid,
            $currentPid === null ? '(none)' : (string) $currentPid
        ));

        // No project reference in the tool input — nothing to gate.
        if ($pid === null) {
            error_log('[CappyScopePreHook] DECISION=allow (no pid in tool input)');
            return HookResult::allow();
        }

        if (empty($currentPid)) {
            error_log('[CappyScopePreHook] DECISION=deny (no session project — fail-closed)');
            return HookResult::deny(
                'Cappy hard-scope: no current project in session context; '
                . 'cannot verify scope for tool "' . $use->name . '".'
            );
        }

        if ((int) $pid === (int) $currentPid) {
            error_log('[CappyScopePreHook] DECISION=allow (pid matches session)');
            return HookResult::allow();
        }

        error_log(sprintf(
            '[CappyScopePreHook] DECISION=deny (requested pid %s != session pid %s)',
            (string) $pid, (string) $currentPid
        ));
        return HookResult::deny(
            'Cappy hard-scope denied: tool "' . $use->name
            . '" attempted to access project ' . $pid
            . ', but Cappy is scoped to project ' . $currentPid . ' only.'
        );
    }
}