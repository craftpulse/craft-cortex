<?php

namespace craftpulse\herald\services;

use yii\base\Component;

/**
 * =========================================================================
 * Capability-scope vocabulary + tool→scope authority.
 *
 * Replaces the coarse two-valued `read` / `write` OAuth scope model with
 * a capability vocabulary that spans Herald's tool surface. Every
 * registered tool maps to exactly one capability scope here; this class
 * is the single source of truth the rest of the OAuth + dispatch stack
 * reads:
 *
 *   - `ScopeRepository::SUPPORTED_SCOPES` delegates to `all()` so DCR +
 *     authorize flows accept any capability scope.
 *   - `WellKnownController` advertises `all()` under `scopes_supported`.
 *   - The consent screen renders one `describe()` line per requested
 *     scope.
 *   - `Tools::asListPayloadFor()` / `getByNameFor()` consult
 *     `grantsTool()` so a tool surfaces over HTTP only when the granted
 *     scope set covers its required scope.
 *
 * Authorization is the conjunction of three independent gates — scope
 * ∧ Craft-permission ∧ edition. This class owns the first; the existing
 * `filterFor()` contract owns the second; `shouldRegister()` /
 * `ProToolTrait` owns the third. A tool is visible / callable over HTTP
 * only when all three hold.
 *
 * Legacy resilience: a client (or a stored DCR row) that still carries
 * the pre-capability `read` / `write` scopes is expanded to the matching
 * capability cluster at grant time via `expandLegacyScopes()`, so an
 * already-issued token keeps working through the migration window.
 *
 * stdio is unaffected: stdio passes a `null` user and `null` scope set,
 * which `grantsTool()` treats as the trusted-local all-access path —
 * scope gating is an HTTP-transport concern only, mirroring the
 * transport-is-the-security-boundary rule.
 * =========================================================================
 *
 * @author CraftPulse
 * @since  5.0.0
 */
class Scopes extends Component
{
    // Constants
    // =========================================================================

    /**
     * Read access to entries, assets, categories, tags, and globals.
     *
     * @since 5.0.0
     */
    public const CONTENT_READ = 'content:read';

    /**
     * Create / update entries, categories, tags, globals, and run the
     * bulk / scaffold create-many tools. Excludes status changes
     * (publish) and deletes, which carry their own scopes.
     *
     * @since 5.0.0
     */
    public const CONTENT_WRITE = 'content:write';

    /**
     * Mutate assets and addresses — the binary / PII-adjacent content
     * surfaces that warrant a scope distinct from text content.
     *
     * @since 5.0.0
     */
    public const ASSETS_WRITE = 'assets:write';

    /**
     * Read schema — sections, entry types, fields, volumes, sites,
     * transforms, and the element-type registry.
     *
     * @since 5.0.0
     */
    public const SCHEMA_READ = 'schema:read';

    /**
     * Read system + diagnostic surfaces — config, plugins, routes,
     * GraphQL introspection, the orientation tool, skills, and the dev
     * action tools (clear-caches, resave, command, exec).
     *
     * @since 5.0.0
     */
    public const SYSTEM_READ = 'system:read';

    /**
     * Dispatch a Craft console route through the `craft_command` tool.
     *
     * Its own scope because a console route reaches Craft's own
     * controllers: `users/create`, `resave/*`, `migrate/*` and anything
     * else the operator allowlists. Folding that into `system:read`
     * (where it sat until the 2026-08-02 remediation) meant a read-only
     * OAuth grant carried arbitrary command execution, which is the
     * broadest possible capability wearing the narrowest possible label.
     *
     * Grants of this scope are still subject to the `craft_command`
     * permission gate and the command allowlist — the scope widens
     * nothing on its own.
     *
     * @since 5.0.0
     */
    public const SYSTEM_WRITE = 'system:write';

    /**
     * Read user records (subject to the per-field PII gating the
     * `users` tool already enforces).
     *
     * @since 5.0.0
     */
    public const USERS_READ = 'users:read';

    /**
     * Create / update / delete users.
     *
     * @since 5.0.0
     */
    public const USERS_WRITE = 'users:write';

    /**
     * Sentinel deny scope for tools absent from `TOOL_SCOPES`. It is NOT
     * a grantable scope — `isKnown()` rejects it, so it never appears in
     * `scopes_supported`, can't be requested at authorize / DCR, and can
     * never end up in a token's granted set. `scopeForTool()` returns it
     * for an unmapped tool so `grantsTool()` fails CLOSED: an unmapped
     * tool is denied over HTTP rather than defaulting to a read scope.
     *
     * @since 5.0.0
     */
    public const NONE = 'none';

    /**
     * Legacy coarse "read everything" scope. Expanded to every read
     * cluster at grant time so pre-capability tokens keep resolving.
     *
     * @since 5.0.0
     */
    public const LEGACY_READ = 'read';

    /**
     * Legacy coarse "write everything" scope. Expanded to every scope
     * at grant time.
     *
     * @since 5.0.0
     */
    public const LEGACY_WRITE = 'write';

    /**
     * The authoritative tool→scope map. Keyed by `ToolInterface::getName()`,
     * valued by the single capability scope the tool requires over HTTP.
     *
     * A tool absent from this map is treated as the ungrantable `NONE`
     * scope by `scopeForTool()` — fail CLOSED. An unmapped (e.g. future
     * write, or third-party) tool is denied over HTTP rather than
     * inheriting a read scope it was never deliberately granted. The
     * `mapsEveryRegisteredTool` test asserts there are no such gaps among
     * the built-in tools.
     *
     * `craft_exec` is mapped for completeness but is stdio-only at the
     * transport boundary regardless of scope — it can never be invoked
     * over HTTP no matter what scope a token carries.
     *
     * @var array<string,string>
     *
     * @since 5.0.0
     */
    public const TOOL_SCOPES = [
        // Orientation.
        'get_initial_context' => self::SYSTEM_READ,

        // Schema (read-only).
        'sections' => self::SCHEMA_READ,
        'entry_types' => self::SCHEMA_READ,
        'fields' => self::SCHEMA_READ,
        'field_types' => self::SCHEMA_READ,
        'category_groups' => self::SCHEMA_READ,
        'tag_groups' => self::SCHEMA_READ,
        'volumes_and_filesystems' => self::SCHEMA_READ,
        'sites' => self::SCHEMA_READ,
        'image_transforms' => self::SCHEMA_READ,
        'element_types' => self::SCHEMA_READ,
        'database_schema' => self::SCHEMA_READ,

        // Content (read).
        'entries' => self::CONTENT_READ,
        'assets' => self::CONTENT_READ,
        'categories' => self::CONTENT_READ,
        'tags' => self::CONTENT_READ,
        'globals' => self::CONTENT_READ,

        // Content (write).
        'entry' => self::CONTENT_WRITE,
        'category' => self::CONTENT_WRITE,
        'tag' => self::CONTENT_WRITE,
        'global_set' => self::CONTENT_WRITE,
        'bulk_entries' => self::CONTENT_WRITE,
        'scaffold_entries' => self::CONTENT_WRITE,

        // Assets / addresses (write).
        'address' => self::ASSETS_WRITE,

        // Users.
        'users' => self::USERS_WRITE,

        // Skills (Herald's own element type — author-able).
        'skill' => self::CONTENT_WRITE,

        // System & diagnostics (read).
        'system_info' => self::SYSTEM_READ,
        'config' => self::SYSTEM_READ,
        'plugins' => self::SYSTEM_READ,
        'routes' => self::SYSTEM_READ,
        'system_diagnostics' => self::SYSTEM_READ,
        'extensibility' => self::SYSTEM_READ,
        'permissions_and_groups' => self::SYSTEM_READ,
        'search_skills' => self::SYSTEM_READ,

        // GraphQL + dev actions.
        'graphql' => self::SYSTEM_READ,
        'clear_caches' => self::SYSTEM_READ,
        'resave' => self::SYSTEM_READ,

        // Console dispatch. `system:write`, never `system:read` — both
        // of these run arbitrary allowlisted code inside the Craft
        // process, so a read grant must not carry them. `craft_exec`
        // stays mapped for completeness but is refused at the HTTP
        // transport boundary regardless of the scope a token carries.
        'craft_command' => self::SYSTEM_WRITE,
        'craft_exec' => self::SYSTEM_WRITE,

        // Workflow & audit (read-or-write modes — gated to the broader
        // write cluster because their write modes mutate content).
        'drafts_and_revisions' => self::CONTENT_WRITE,
        'content_audit' => self::CONTENT_WRITE,
        'import_export' => self::CONTENT_WRITE,
    ];

    // Public Methods
    // =========================================================================

    /**
     * Every capability scope, in display order. Advertised under
     * `scopes_supported` (RFC 8414) and accepted by the scope
     * repository. Legacy `read` / `write` are intentionally NOT
     * listed — they're accepted for back-compat but not advertised.
     *
     * @return string[]
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function all(): array
    {
        return [
            self::CONTENT_READ,
            self::CONTENT_WRITE,
            self::ASSETS_WRITE,
            self::SCHEMA_READ,
            self::SYSTEM_READ,
            self::SYSTEM_WRITE,
            self::USERS_READ,
            self::USERS_WRITE,
        ];
    }

    /**
     * Whether `$identifier` is a scope the server recognises — either
     * a capability scope from `all()` or one of the two legacy coarse
     * scopes accepted for back-compat. The scope repository calls this
     * to decide whether to mint a `ScopeEntity` or return null
     * (`invalid_scope`).
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function isKnown(string $identifier): bool
    {
        if (in_array($identifier, $this->all(), true)) {
            return true;
        }
        return in_array($identifier, [self::LEGACY_READ, self::LEGACY_WRITE], true);
    }

    /**
     * The capability scope a tool requires over HTTP. Unmapped tools
     * fall back to the ungrantable `NONE` sentinel — fail CLOSED: an
     * unmapped tool is denied over HTTP (no token can carry `NONE`)
     * rather than inheriting a read scope it was never granted.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function scopeForTool(string $toolName): string
    {
        return self::TOOL_SCOPES[$toolName] ?? self::NONE;
    }

    /**
     * Whether a granted scope set covers the scope a tool requires.
     *
     * `$grantedScopes === null` is the stdio / trusted-local path —
     * scope gating is an HTTP concern only, so a null set grants every
     * tool. An explicit (possibly empty) array is the HTTP path: the
     * tool's required scope must be a member, after legacy expansion.
     *
     * @param string[]|null $grantedScopes Scopes carried by the access
     *                                     token, or null for stdio.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function grantsTool(string $toolName, ?array $grantedScopes): bool
    {
        if ($grantedScopes === null) {
            return true;
        }

        $effective = $this->expandLegacyScopes($grantedScopes);
        return in_array($this->scopeForTool($toolName), $effective, true);
    }

    /**
     * Expand any legacy coarse scopes in `$scopes` to their capability
     * clusters, leaving capability scopes untouched. `read` becomes
     * every read scope; `write` becomes every scope. De-duplicated.
     *
     * @param string[] $scopes
     * @return string[]
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function expandLegacyScopes(array $scopes): array
    {
        $expanded = [];
        foreach ($scopes as $scope) {
            if ($scope === self::LEGACY_READ) {
                $expanded = array_merge($expanded, $this->_readCluster());
                continue;
            }
            if ($scope === self::LEGACY_WRITE) {
                $expanded = array_merge($expanded, $this->all());
                continue;
            }
            $expanded[] = $scope;
        }
        return array_values(array_unique($expanded));
    }

    /**
     * Filter a requested scope list down to the scopes the server
     * recognises, preserving order and de-duplicating. Unknown
     * identifiers are dropped silently — the authorize / DCR boundary
     * is the enforcement point for outright rejection; this is the
     * persistence-side normaliser.
     *
     * @param string[] $requested
     * @return string[]
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function filterKnown(array $requested): array
    {
        $known = [];
        foreach ($requested as $scope) {
            if (is_string($scope) && $scope !== '' && $this->isKnown($scope) && !in_array($scope, $known, true)) {
                $known[] = $scope;
            }
        }
        return $known;
    }

    /**
     * Plain-English description of a capability scope for the consent
     * screen. Falls back to a generic line for legacy / unknown
     * identifiers so the consent UI never renders a blank cell.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function describe(string $scope): string
    {
        return match ($scope) {
            self::CONTENT_READ => 'Read entries, assets, categories, tags, and globals.',
            self::CONTENT_WRITE => 'Create, update, publish, and delete entries, categories, tags, and globals.',
            self::ASSETS_WRITE => 'Upload and modify assets and address records.',
            self::SCHEMA_READ => 'Read schema: sections, fields, entry types, volumes, and sites.',
            self::SYSTEM_READ => 'Read system configuration, plugins, routes, and diagnostics.',
            self::SYSTEM_WRITE => 'Run allowlisted Craft console commands.',
            self::USERS_READ => 'Read user records (subject to PII gating).',
            self::USERS_WRITE => 'Create, update, and delete users.',
            self::LEGACY_READ => 'Read content, schema, and configuration. No writes.',
            self::LEGACY_WRITE => 'Modify entries, fields, and other Craft state.',
            default => 'Additional scope.',
        };
    }

    // Private Methods
    // =========================================================================

    /**
     * The read-only capability cluster — the scopes the legacy `read`
     * scope expands to.
     *
     * @return string[]
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _readCluster(): array
    {
        return [
            self::CONTENT_READ,
            self::SCHEMA_READ,
            self::SYSTEM_READ,
            self::USERS_READ,
        ];
    }
}
