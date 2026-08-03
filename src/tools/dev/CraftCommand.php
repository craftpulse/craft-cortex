<?php

namespace craftpulse\herald\tools\dev;

use Craft;
use craft\elements\User;
use craftpulse\herald\attributes\IsDestructive;
use craftpulse\herald\attributes\IsIdempotent;
use craftpulse\herald\attributes\IsOpenWorld;
use craftpulse\herald\attributes\Title;
use craftpulse\herald\Herald;
use craftpulse\herald\tools\AbstractTool;
use craftpulse\herald\tools\ContextAwareToolInterface;
use craftpulse\herald\tools\support\ConsoleRunner;
use craftpulse\herald\tools\support\InvocationContext;
use craftpulse\herald\tools\support\Schema;
use craftpulse\herald\tools\support\SecretRedactor;
use craftpulse\herald\tools\ToolException;

/**
 * =========================================================================
 * `craft_command` tool — allowlisted Craft / Yii console-command runner.
 *
 * Dispatches a console route through Craft's internal runner — never
 * `proc_open`, `shell_exec`, `exec`, `passthru`, `popen`, or backticks.
 * The allowlist is enforced at the tool layer before dispatch: a
 * non-allowlisted command never reaches the `runAction()` call.
 *
 * Allowlist is split into two arrays per the locked
 * `allowAdminChanges` policy:
 *
 *   - `Settings::$allowedCommands`     — content-level patterns, always admitted.
 *   - `Settings::$adminLevelCommands`  — admin-level patterns, admitted ONLY
 *                                        when
 *                                        `Craft::$app->getConfig()->getGeneral()
 *                                        ->allowAdminChanges === true`.
 *
 * Allowlist precedence (highest → lowest):
 *   1. `config/herald.php` overrides (standard Craft pattern; auto-merged).
 *   2. Project config under `plugins.herald.settings.allowedCommands` /
 *      `adminLevelCommands`.
 *   3. Defaults baked into `Settings`.
 *   4. Runtime DB overrides (admin-editable, auto-expiring) layered on top
 *      of the content-level set.
 *
 * Patterns use `fnmatch()` semantics — `resave/*` matches any
 * `resave/<x>` route, `up` matches only the literal `up` command.
 *
 * Admin-changes-denied rejections still write a `herald_invocations`
 * row with `kind=tool_error` (the audit-log seam from Gate 7.5
 * audit-logs every thrown `ToolException` automatically — no manual
 * write needed). The audit trail captures both successful boundary
 * crossings and rejected boundary attempts.
 * =========================================================================
 *
 * @author CraftPulse
 * @since  5.0.0
 */
#[Title('Run Craft Command')]
#[IsDestructive]
#[IsIdempotent(false)]
#[IsOpenWorld(false)]
class CraftCommand extends AbstractTool implements ContextAwareToolInterface
{
    // Constants
    // =========================================================================

    /**
     * Permission a caller must hold before this tool is visible in
     * `tools/list` or dispatchable through `tools/call`. Declared here
     * because this class is the enforcement point: `filterFor()` reads
     * it for visibility, `execute()` re-reads it as the defence-in-depth
     * gate, and `PluginTrait::_registerHeraldPermissions()` registers it
     * from this constant so the registration and the two gates cannot
     * drift.
     *
     * Running a console route reaches Craft's own controllers, so this is
     * an operator-grade authority in its own right, separate from
     * `herald:manage-grants` (which widens *which* routes are
     * allowlisted for a user, not *whether* the user may dispatch at
     * all). A caller needs both to benefit from a temporary grant.
     *
     * @since 5.0.0
     */
    public const PERMISSION_RUN_COMMANDS = 'herald:run-commands';

    // Private Properties
    // =========================================================================

    /**
     * @var InvocationContext|null Per-invocation context injected by the
     *                             dispatcher immediately before `execute()`.
     *                             Carries the resolved calling user, whose
     *                             id scopes per-user runtime grants at the
     *                             dispatch gate. Null on call paths that
     *                             bypass the dispatcher (stdio single-process
     *                             / direct test calls) — treated as the
     *                             global-only allowlist view (no per-user
     *                             grant applies).
     */
    private ?InvocationContext $_invocationContext = null;

    // Public Methods
    // =========================================================================

    /**
     * Store the per-invocation context the dispatcher injects before
     * `execute()`. Read for the resolved user id when resolving the
     * per-user effective allowlist.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function setInvocationContext(InvocationContext $ctx): void
    {
        $this->_invocationContext = $ctx;
    }

    /**
     * @inheritdoc
     *
     * Hides the tool from `tools/list` (and refuses `tools/call` with
     * the same "Unknown tool" shape) for any caller that does not hold
     * `PERMISSION_RUN_COMMANDS`.
     *
     * A null user is the stdio path and passes: the security boundary
     * is the HTTP transport, and the stdio caller already holds a shell
     * plus the `craft` console, so gating them buys nothing (ruled
     * 2026-08-02). Admins pass through Craft's own `can()` semantics,
     * but the branch is explicit so a reconfigured permission system
     * cannot quietly lock out admins.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function filterFor(?User $user = null): bool
    {
        if ($user === null) {
            return true;
        }

        return $user->admin || $user->can(self::PERMISSION_RUN_COMMANDS);
    }

    /**
     * @inheritdoc
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public static function getName(): string
    {
        return 'craft_command';
    }

    /**
     * @inheritdoc
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public static function getDescription(): string
    {
        return 'Run an allowlisted Craft / Yii console command in-process. Required ' .
            '`command` is the route (e.g. `cache/flush-all`, `migrate/up`, ' .
            '`project-config/apply`, `resave/entries`). Optional `options` is an ' .
            'object whose keys map to the controller\'s public option properties ' .
            '(e.g. `{section: "news"}`). Use `mode: "list"` to see the active ' .
            'allowlist patterns. Returns the dispatched route, exit code, captured ' .
            'output, matched allowlist pattern, and any error.';
    }

    /**
     * @inheritdoc
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public static function getInputSchema(): array
    {
        return Schema::object([
            'mode' => Schema::string()
                ->enum(['list', 'run'])
                ->description('Optional. `list` returns the active allowlist; `run` (default) dispatches.'),
            'command' => Schema::string()
                ->description('Console route to dispatch. Required when mode=run.'),
            'options' => Schema::object()
                ->additionalProperties(true)
                ->description('Object of CLI option overrides bound to the controller properties.'),
        ])->toArray();
    }

    /**
     * @inheritdoc
     *
     * @throws ToolException
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function execute(array $arguments): array
    {
        $this->_assertMayRunCommands();

        $mode = $this->_mode($arguments) ?? 'run';
        $effective = $this->_allowlist();

        if ($mode === 'list') {
            return [
                'mode' => 'list',
                'patterns' => array_values($effective),
                'count' => count($effective),
            ];
        }

        if ($mode !== 'run') {
            throw new ToolException("Unknown mode: '{$mode}'. Allowed: list, run.");
        }

        $command = isset($arguments['command']) && is_string($arguments['command']) && $arguments['command'] !== ''
            ? $arguments['command']
            : null;
        if ($command === null) {
            throw new ToolException('`command` is required for mode=run.');
        }

        // Normalise: strip leading slashes that the LLM might tack on.
        $command = ltrim($command, '/');

        // Classify the ROUTE, not the bucket the matching pattern came
        // from. A runtime grant lands in the content-level bucket, and
        // the content-level bucket is admitted unconditionally — so
        // before this gate existed, granting `migrate/up` laundered an
        // admin-level route straight past `allowAdminChanges`. Asking
        // `Allowlist::isAdminLevelRoute()` about the resolved route
        // closes that regardless of which list admitted it.
        $this->_assertAdminChangesForRoute($command);

        // Content-level patterns admit regardless of `allowAdminChanges`;
        // admin-level patterns admit only when the host flag is true.
        // Distinguishing the two paths lets us throw a precise
        // `ToolException` that names `allowAdminChanges` as the
        // reason when the only matching pattern is admin-level and the
        // flag is off.
        $matched = $this->_matchPattern($command, $this->_contentPatterns());

        if ($matched === null) {
            $adminMatch = $this->_matchPattern($command, $this->_adminPatterns());

            if ($adminMatch !== null && !Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
                throw new ToolException(
                    "Command '{$command}' matches admin-level pattern '{$adminMatch}', but " .
                    '`allowAdminChanges` is false on this install. Admin-level routes ' .
                    '(project-config, migrations, scaffolding, schema DDL) refuse to dispatch ' .
                    'unless `allowAdminChanges = true` in `config/general.php`.',
                );
            }

            $matched = $adminMatch;
        }

        if ($matched === null) {
            throw new ToolException(
                "Command '{$command}' is not in the allowlist. Allowed patterns: " .
                implode(', ', $effective) .
                '. Edit `herald.allowedCommands` (or `herald.adminLevelCommands`) in project config or ' .
                '`config/herald.php` to extend it.',
            );
        }

        $options = $this->_options($arguments);

        $result = ConsoleRunner::run($command, $options);

        // Redact `KEY=value` / `KEY: value` secrets in captured stdout/stderr
        // before they reach the wire OR the persisted audit excerpt — default
        // allowlist routes (`mailer/test`, `utils/*`) can echo transport and
        // config secrets to stdout. Mirrors `CraftExec`'s treatment of its own
        // captured output.
        return [
            'mode' => 'run',
            'command' => $command,
            'matchedPattern' => $matched,
            'options' => $options,
            'exitCode' => $result['exitCode'],
            'output' => SecretRedactor::redactString($result['output']),
            'error' => $result['error'] !== null ? SecretRedactor::redactString($result['error']) : null,
        ];
    }

    // Private Methods
    // =========================================================================

    /**
     * Defence-in-depth permission gate, independent of the `tools/list`
     * filtering `filterFor()` performs. `filterFor()` exists for the
     * LLM's tool-selection UX; this exists for security, and both fail
     * closed. A caller that reaches `execute()` by any route other than
     * the filtered dispatcher is still refused here.
     *
     * Gates every mode, `list` included: the allowlist itself describes
     * which console routes this install will run, which is reconnaissance
     * for a caller that is not allowed to run any of them.
     *
     * No Craft identity means stdio (the trusted local transport), which
     * skips the check — same rule `PermissionedToolTrait::_assertPermission()`
     * applies, and the same ruling behind it.
     *
     * @throws ToolException when an identified caller lacks `PERMISSION_RUN_COMMANDS`.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _assertMayRunCommands(): void
    {
        $user = Craft::$app->getUser()->getIdentity();
        if ($user === null) {
            return;
        }

        if ($user->admin || $user->can(self::PERMISSION_RUN_COMMANDS)) {
            return;
        }

        throw new ToolException(sprintf(
            'permission denied: running Craft console commands requires `%s`.',
            self::PERMISSION_RUN_COMMANDS,
        ));
    }

    /**
     * Refuse an admin-level console route when the host install has
     * `allowAdminChanges` off, whichever allowlist bucket would have
     * admitted it.
     *
     * Classification comes from `Allowlist::isAdminLevelRoute()` — the
     * canonical set of routes that mutate project config, schema,
     * scaffolding or fixtures. That deliberately ignores which bucket
     * matched, because the runtime-grant surface writes into the
     * content-level bucket and the content-level bucket is admitted
     * unconditionally.
     *
     * The message names the pattern that *would* have admitted the
     * route, preferring the admin-level list so the operator sees the
     * configured pattern rather than the grant that shadowed it.
     *
     * A no-match route falls through untouched: the "not in the
     * allowlist" rejection in `execute()` is the better error for it.
     *
     * @throws ToolException when the route is admin-level and `allowAdminChanges` is false.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _assertAdminChangesForRoute(string $command): void
    {
        if (Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            return;
        }

        if (!Herald::getInstance()->allowlist->isAdminLevelRoute($command)) {
            return;
        }

        $wouldMatch = $this->_matchPattern($command, $this->_adminPatterns())
            ?? $this->_matchPattern($command, $this->_contentPatterns());

        if ($wouldMatch === null) {
            return;
        }

        throw new ToolException(
            "Command '{$command}' matches admin-level pattern '{$wouldMatch}', but " .
            '`allowAdminChanges` is false on this install. Admin-level routes ' .
            '(project-config, migrations, scaffolding, schema DDL) refuse to dispatch ' .
            'unless `allowAdminChanges = true` in `config/general.php`. A temporary ' .
            'grant cannot widen this boundary.',
        );
    }

    /**
     * Effective allowlist for the current request — the union of
     * content-level patterns, runtime overrides, and admin-level
     * patterns (when `allowAdminChanges` is true). Mirrors what
     * `Allowlist::getEffective()` exposes to the rest of the plugin
     * (notably `InitialContext.allowlist`) so the LLM-visible view
     * and the dispatch gate agree.
     *
     * @return string[]
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _allowlist(): array
    {
        $patterns = Herald::getInstance()->allowlist->getEffective($this->_callingUserId());

        return array_values(array_filter(
            $patterns,
            static fn($p): bool => is_string($p) && $p !== '',
        ));
    }

    /**
     * Content-level patterns only — `Settings::$allowedCommands` plus
     * active runtime overrides. Always admitted regardless of the
     * host's `allowAdminChanges` flag. Separating this from
     * `_allowlist()` lets `execute()` distinguish "admin-level pattern
     * blocked by `allowAdminChanges = false`" from "no matching
     * pattern at all" so the `ToolException` message can name the
     * exact cause.
     *
     * @return string[]
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _contentPatterns(): array
    {
        $settings = Herald::getInstance()->getSettings();
        $defaults = $settings->allowedCommands;
        $overridePatterns = array_map(
            static fn(array $row): string => (string) $row['pattern'],
            Herald::getInstance()->allowlist->getActiveOverrides($this->_callingUserId()),
        );

        return array_values(array_unique(array_filter(
            array_merge($defaults, $overridePatterns),
            static fn($p): bool => is_string($p) && $p !== '',
        )));
    }

    /**
     * The resolved calling user id for the current dispatch, or null when
     * no context was injected (stdio single-process / direct test call).
     * Scopes per-user runtime grants so the dispatch gate honours a grant
     * only for the user it was issued to; a null id sees only global grants.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _callingUserId(): ?int
    {
        return $this->_invocationContext?->userId;
    }

    /**
     * Admin-level patterns only — `Settings::$adminLevelCommands`. Used
     * by `execute()` to detect "this command would have matched if
     * `allowAdminChanges` were true" so the rejection message can name
     * the config flag.
     *
     * @return string[]
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _adminPatterns(): array
    {
        $patterns = Herald::getInstance()->getSettings()->adminLevelCommands;

        return array_values(array_filter(
            $patterns,
            static fn($p): bool => is_string($p) && $p !== '',
        ));
    }

    /**
     * Match the given route against the allowlist using fnmatch (glob)
     * semantics. Returns the first matching pattern or null.
     *
     * @param string[] $patterns
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _matchPattern(string $command, array $patterns): ?string
    {
        foreach ($patterns as $pattern) {
            if (fnmatch($pattern, $command)) {
                return $pattern;
            }
        }

        return null;
    }

    /**
     * Coerce the `options` argument into a string-keyed array suitable
     * for `Craft::$app->runAction($route, $params)` — Yii's controller
     * option binding wants a flat key/value map.
     *
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _options(array $arguments): array
    {
        $options = $arguments['options'] ?? [];
        if (!is_array($options)) {
            return [];
        }

        $clean = [];
        foreach ($options as $k => $v) {
            if (!is_string($k) || $k === '') {
                continue;
            }
            $clean[$k] = $v;
        }

        return $clean;
    }
}
