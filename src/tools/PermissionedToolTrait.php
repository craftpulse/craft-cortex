<?php

namespace craftpulse\herald\tools;

use Craft;

/**
 * =========================================================================
 * Defense-in-depth permission re-check for Pro mutation tools.
 *
 * Extracted in Gate 8.4 once the Rule of 3+ was met (Entry, Category,
 * GlobalSet, Address — four direct consumers; Tag opts out with a
 * whole-tool admin gate). All Pro content tools that gate per-resource
 * permissions now share this implementation.
 *
 * Contract:
 *   - Consuming class MUST implement
 *     `_requiredPermissions(array $arguments): array` returning the
 *     list of Craft permission strings the resolved arguments imply.
 *   - Consuming class SHOULD override `_buildPermissionDeniedMessage()`
 *     to emit a tool-specific rich-format error message. The default
 *     implementation emits the bare permission string.
 *
 * Skip rules:
 *   - stdio (no user identity) → skip the check. stdio is trusted as a local transport.
 *   - Admin users → skip the check. Craft's `User::can()` already
 *     returns `true` for admins, but the explicit branch is defensive
 *     against accidental permission-system reconfiguration that flips
 *     that default.
 *
 * Sentinel handling:
 *   - The wildcard sentinel (`saveEntries:*`, `editGlobalSet:*`, etc.)
 *     is intended for `filterFor()` probing with empty arguments. It
 *     should never reach `execute()` — if it does, it signals a
 *     programmer error (the tool failed to resolve the per-resource
 *     UID before calling `_assertPermission()`). The trait throws a
 *     `ToolException` naming the sentinel rather than silently
 *     authorising the call.
 *
 * Per-tool error message format:
 *   - Tag opts out (`_requiredPermissions()` returns `[]`) — it uses a
 *     whole-tool admin gate via `_assertAdmin()` instead.
 *   - Entry, Category, GlobalSet, Address: each overrides
 *     `_buildPermissionDeniedMessage()` to emit the rich format
 *     existing tests assert on (e.g. `"permission denied: mode `update`
 *     on section `posts` requires `saveEntries:{uid}`"`).
 * =========================================================================
 *
 * @author CraftPulse
 * @since  5.0.0
 */
trait PermissionedToolTrait
{
    // Protected Methods
    // =========================================================================

    /**
     * Walk the permissions returned by `_requiredPermissions($arguments)`
     * and assert the current user holds every one. Throws a plain
     * `ToolException` on the first miss; the dispatcher renders it as a
     * successful JSON-RPC response carrying the MCP tool-error envelope
     * (`{content: [{type: text, text}], isError: true}`) — there is no
     * `-32002` code in this path.
     *
     * Skip rules (defense in depth — both stdio and admin paths
     * bypass):
     *   - stdio (`getIdentity() === null`) → trusted, return.
     *   - Admin user → permission system allows everything, return.
     *
     * A wildcard sentinel (`saveEntries:*`) reaching this method
     * signals a programmer error — the tool failed to resolve the
     * per-resource UID. The trait throws a `ToolException` rather than
     * silently authorising. Tools should resolve the per-resource UID
     * before delegating to this method.
     *
     * @param array<string,mixed> $arguments
     * @throws ToolException
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    protected function _assertPermission(array $arguments): void
    {
        $user = Craft::$app->getUser()->getIdentity();
        if ($user === null) {
            // stdio path — trusted local user. Skip the check.
            return;
        }

        if ($user->admin) {
            return;
        }

        $permissions = $this->_requiredPermissions($arguments);

        foreach ($permissions as $permission) {
            if (str_ends_with($permission, ':*')) {
                throw new ToolException(sprintf(
                    'permission check failed: wildcard sentinel `%s` reached execute(); ' .
                        'tool failed to resolve per-resource UID before calling _assertPermission().',
                    $permission,
                ));
            }

            if (!$user->can($permission)) {
                throw new ToolException($this->_buildPermissionDeniedMessage($permission, $arguments));
            }
        }
    }

    /**
     * Build the user-facing permission-denied error message. The
     * default implementation emits the bare permission string —
     * consuming tools override to surface the tool-specific rich
     * format (e.g. `"permission denied: mode `update` on section
     * `posts` requires `saveEntries:{uid}`"`).
     *
     * @param array<string,mixed> $arguments
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    protected function _buildPermissionDeniedMessage(string $missingPermission, array $arguments): string
    {
        return sprintf('permission denied: requires `%s`.', $missingPermission);
    }
}
