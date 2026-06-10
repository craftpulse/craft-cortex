<?php

namespace craftpulse\cortex\tools\system;

use Craft;
use craft\elements\Address as AddressElement;
use craft\elements\db\UserQuery;
use craft\elements\User as UserElement;
use craft\helpers\DateTimeHelper;
use craft\models\UserGroup;
use craftpulse\cortex\attributes\IsDestructive;
use craftpulse\cortex\attributes\IsIdempotent;
use craftpulse\cortex\attributes\Title;
use craftpulse\cortex\Cortex;
use craftpulse\cortex\mcp\Server;
use craftpulse\cortex\tools\AbstractTool;
use craftpulse\cortex\tools\ContextAwareToolInterface;
use craftpulse\cortex\tools\IdempotencyTrait;
use craftpulse\cortex\tools\PermissionedToolTrait;
use craftpulse\cortex\tools\ProToolTrait;
use craftpulse\cortex\tools\support\InvocationContext;
use craftpulse\cortex\tools\support\Schema;
use craftpulse\cortex\tools\ToolException;
use DateTimeInterface;
use Throwable;

/**
 * =========================================================================
 * `users` Pro tool — list / get / create / update / delete users with
 * PII-aware serialisation.
 *
 * Five modes dispatched off the `mode` argument:
 *
 *   - `list` — paginated user queries with `status`, `group`, `admin`,
 *     `can`, `search`, `dateCreated`, `limit`, `offset` filters. Per-
 *     result PII redaction via `_serializeUser` consults the caller.
 *   - `get` — single-user lookup by `id` / `uid` / `email` / `username`
 *     (first non-empty wins). Per-target `canView` gate. Same PII
 *     redaction as `list`.
 *   - `create` — instantiate a fresh `User`, set attributes + group
 *     diff + custom fields, save. Permission via `canRegisterUsers()`
 *     plus `assignUserGroup:{uid}` per added group; `administrateUsers`
 *     gates `active / suspended / pending`; admin caller required for
 *     `admin: true`.
 *   - `update` — load by id/uid, mutate, save. Per-target `canSave`
 *     plus an explicit "non-admin caller editing an admin target"
 *     refusal (the headline correction in `docs/plans/gate-8.5.md`
 *     locked decision 10 — `User::canSave` does NOT enforce admin-
 *     protection natively, it lives in `canDelete` and ad-hoc in
 *     `UsersController`). Sensitive-field gate
 *     (`email / username on non-self / active / suspended / pending /
 *     locked / newPassword on non-self`) requires `administrateUsers`.
 *     Admin promotion / demotion requires the caller is an admin.
 *   - `delete` — soft-delete by default; `hardDelete: true` removes
 *     the row entirely. Optional `transferContentTo: <userId>` sets
 *     `inheritorOnDelete` so the recipient inherits authored entries
 *     via `User::afterDelete()`. Per-target `canDelete` covers admin-
 *     protection natively.
 *
 * Permission contract (Gate 8.5 locked decisions):
 *   - `_requiredPermissions()` returns `['viewUsers']` — coarse gate
 *     for `filterFor()` whole-tool visibility and the trait wildcard
 *     sentinel rejection.
 *   - Per-target authorisation delegated to `Elements::canSave /
 *     canView / canDelete`.
 *   - Sensitive-field / admin / group-assignment gates layered on top
 *     of the per-target check inside each mode body.
 *
 * PII gating contract (locked decision 4 of `docs/plans/gate-8.md`,
 * fieldmap in `docs/plans/gate-8.5.md` §"PII gating spec"):
 *   - `_serializeUser($target, ?$caller)` is the PII-aware serialiser.
 *   - `email`, `unverifiedEmail`, lockout fields require `editUsers`.
 *   - `passwordResetRequired` requires `administrateUsers`.
 *   - Custom fields are intersected with `Settings::$userCustomFieldAllowlist`
 *     and never returned for handles NOT on the allowlist — even for
 *     admin callers. Defense-in-depth: Craft has no native per-field-
 *     value permission.
 *   - Credentials / verification fields (`password`,
 *     `verificationCode`, `newPassword`, etc.) are NEVER serialised.
 *
 * Group-assignment semantics:
 *   - `groupUids` is a **replacement list** — the resulting user is in
 *     exactly the supplied groups. Pre-existing groups not in the new
 *     list are removed. Each added group (not pre-existing) requires
 *     the caller to hold `assignUserGroup:{uid}` on it. Keep-as-is
 *     groups do NOT re-gate (matching
 *     `UsersController::_saveUserGroups`'s `hasNewGroups` semantics
 *     at line 2845).
 *
 * Admin-protection contract:
 *   - `update`: caller must be admin to edit an admin target (this
 *     gate lives in the tool because `User::canSave` does NOT enforce
 *     it natively — see locked decision 10).
 *   - `update`: caller must be admin to set the `admin` field (true
 *     or false).
 *   - `create`: caller must be admin to pass `admin: true`.
 *   - `delete`: covered natively by `User::canDelete` at line 1828.
 *
 * Self-edit carve-outs:
 *   - `User::canSave` returns true for self at line 1798. The
 *     sensitive-field gate excludes `email`/`username` for self-edit
 *     (mirroring `UsersController::actionSaveUser` line 1650). The
 *     elevated-session model that Craft's CP wraps around self-email
 *     and self-password mutations does not exist on the MCP HTTP
 *     transport — documented as a follow-up gap.
 *   - `newPassword` on self does NOT require `administrateUsers`
 *     (mirroring `UsersController::actionSaveUser` line 1703). Non-
 *     self `newPassword` does.
 *
 * Idempotency contract: `idempotencyKey` server-side dedup for
 * `create` and `update`. Cache prefix `cortex:users:idem:`. TTL 24h.
 * Skipped on stdio. Not exposed on `delete` (idempotent at the DB
 * level for soft-delete; a re-`delete` on a re-created user with the
 * same numeric id reuse would surprise).
 *
 * Out of scope (separate future gates):
 *   - `send_activation_email`, `send_password_reset_email` — separate
 *     Pro tools.
 *   - `impersonate` — separate Pro tool with a higher security bar.
 *   - Photo / avatar mutation — separate flow with a different
 *     security surface.
 *   - Address mutations on users — delegate to the Gate 8.4 `address`
 *     Pro tool.
 *
 * Known gaps documented for future work:
 *   - HTTP transport has no elevated-session model. Craft's CP
 *     requires elevation for email / password / admin-promotion
 *     mutations — Cortex accepts this for 8.5 and defers the
 *     elevation model to a later gate.
 *   - `inheritorOnDelete` is deprecated in Craft 5.10.0 with no
 *     replacement yet shipped. Single seam in `_delete()` — swap when
 *     the successor lands.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */
#[IsDestructive]
#[IsIdempotent(false)]
#[Title('Users — list / get / create / update / delete with PII gating')]
class Users extends AbstractTool implements ContextAwareToolInterface
{
    use IdempotencyTrait;
    use PermissionedToolTrait;
    use ProToolTrait;

    // Constants
    // =========================================================================

    /**
     * Default page size for `list` mode.
     *
     * @since 5.0.0
     */
    public const DEFAULT_LIMIT = 50;

    /**
     * Hard cap for `list` mode page size.
     *
     * @since 5.0.0
     */
    public const MAX_LIMIT = 200;

    /**
     * Idempotency cache key prefix. Consumed by `IdempotencyTrait`.
     *
     * @since 5.0.0
     */
    public const IDEMPOTENCY_CACHE_PREFIX = 'cortex:users:idem:';

    /**
     * Sensitive fields that require `administrateUsers` on `update`
     * for non-self mutations. The `email` / `username` carve-outs for
     * self-edit are handled by the gate logic — this list defines the
     * fields that GET inspected, not the absolute boundary.
     *
     * @since 5.0.0
     */
    public const SENSITIVE_FIELDS = [
        'email',
        'username',
        'active',
        'suspended',
        'pending',
        'locked',
        'newPassword',
    ];

    /**
     * Self-edit carve-outs from the sensitive-field gate — fields a
     * user can mutate on themselves without `administrateUsers`.
     * Mirrors `UsersController::actionSaveUser` line 1650 where
     * `$isCurrentUser` bypasses the email/password admin gate.
     *
     * @since 5.0.0
     */
    public const SELF_EDIT_CARVE_OUTS = ['email', 'username', 'newPassword'];

    /**
     * Status enum surfaced by `list` mode — pass-through to
     * `UserQuery::status()`. Mirrors `User::STATUS_*`.
     *
     * @since 5.0.0
     */
    public const LIST_STATUSES = ['active', 'pending', 'suspended', 'locked', 'inactive'];

    /**
     * Credential / privilege fields refused over the HTTP transport.
     *
     * Changing a password (`newPassword`), setting or changing the
     * email (`email`), or granting / modifying admin status (`admin`)
     * are exactly the operations Craft's own CP gates behind an
     * elevated (re-authenticated) session, and `email` is personally
     * identifying. The MCP HTTP transport has no elevated-session
     * layer, so these fields are refused over HTTP and are available
     * only over the trusted local stdio transport — PII and credential
     * mutations stay on the local-trust path by design.
     *
     * **Consequence — `mode=create` is stdio-only.** `email` is
     * required on create (see `getInputSchema`), and `email` is in
     * this set, so every HTTP `create` is refused. This is intentional:
     * provisioning a user (which always carries an email) is a
     * local-operator action, not a remote-agent one. `update` works
     * over HTTP for the non-refused fields (username, status flags,
     * groups, custom fields); only the credential/PII/admin fields
     * above are stdio-gated. See `docs/SECURITY.md`.
     *
     * @since 5.0.0
     */
    public const HTTP_REFUSED_FIELDS = ['newPassword', 'email', 'admin'];

    // Private Properties
    // =========================================================================

    /**
     * @var InvocationContext|null Per-invocation context injected by the
     *                             dispatcher via `setInvocationContext()`
     *                             immediately before `execute()`. Null on
     *                             call paths that bypass the dispatcher
     *                             injection — those are treated as HTTP
     *                             (fail closed) by the transport gate.
     */
    private ?InvocationContext $_invocationContext = null;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function setInvocationContext(InvocationContext $ctx): void
    {
        $this->_invocationContext = $ctx;
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public static function getName(): string
    {
        return 'users';
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public static function getDescription(): string
    {
        return 'Write tool for Craft users with PII gating. Modes: list / get / create / ' .
            'update / delete. Per-target permission gating delegated to Craft\'s native ' .
            'Elements::canSave / canView / canDelete plus a tool-layer admin-protection ' .
            'gate on update (User::canSave does NOT enforce non-admin-cannot-edit-admin ' .
            'natively — added here). Sensitive fields (email/username on non-self, ' .
            'active/suspended/pending/locked, newPassword on non-self) require ' .
            'administrateUsers. Admin promotion / demotion requires the caller is admin. ' .
            'Group assignments require assignUserGroup:{uid} on every newly added group; ' .
            'kept groups do not re-gate. PII fields (email, unverifiedEmail, lockout ' .
            'counters, passwordResetRequired) redacted per caller permission via ' .
            '_serializeUser — viewUsers callers get no email; editUsers callers get ' .
            'email + lockout; administrateUsers gets passwordResetRequired; admin gets ' .
            'all. Custom field values gated by Settings::$userCustomFieldAllowlist — ' .
            'handles NOT on the allowlist are NEVER returned, even for admin callers. ' .
            'Credentials (password, verificationCode, newPassword) NEVER returned. ' .
            'Returns the serialised user on success; on validation failure returns ' .
            '{success: false, errors: {handle: [messages]}, mode, id, uid}. Throws only ' .
            'for permission denial, missing arguments, mode misuse, admin-protection ' .
            'failures, or user-not-found. `idempotencyKey` (create / update only) caches ' .
            'the result for 24h. Pro edition only.';
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public static function getInputSchema(): array
    {
        return Schema::object([
            'mode' => Schema::string()
                ->enum(['list', 'get', 'create', 'update', 'delete'])
                ->required()
                ->description('Operation to perform.'),

            // User resolution (get / update / delete — at least one required).
            'id' => Schema::integer()->description('User id. Resolves get / update / delete. First non-empty among id / uid / email / username wins.'),
            'uid' => Schema::string()->description('User uid. Alternative to `id`.'),
            'email' => Schema::string()->description('Email. On `create`: required. On `get`: alternative lookup key (case-insensitive on MySQL, case-sensitive on Postgres — delegates to Craft\'s `getUserByUsernameOrEmail`). On `update`: mutating value; requires administrateUsers for non-self.'),
            'username' => Schema::string()->description('Username. On `get`: alternative lookup key. On `create`/`update`: mutating value; falls back to email when `useEmailAsUsername` is set; non-self update requires administrateUsers.'),

            // Name attributes.
            'firstName' => Schema::string()->description('Forename. Pass-through.'),
            'lastName' => Schema::string()->description('Surname. Pass-through.'),
            'fullName' => Schema::string()->description('Full name. When supplied alongside firstName/lastName, firstName/lastName wins per Craft\'s NameTrait semantics.'),

            // Status / role flags.
            'admin' => Schema::boolean()->description('Promote/demote admin status. Caller must be admin to set true OR false. On `create`, requires admin caller. On `update`, ANY change requires admin caller.'),
            'active' => Schema::boolean()->description('Activate user. Requires administrateUsers; without it, the field is silently ignored on `create` and refused on `update`. On `create` without administrateUsers, defaults to pending=true per Craft convention.'),
            'suspended' => Schema::boolean()->description('Suspend / unsuspend. Requires administrateUsers; silently ignored on `create` without it.'),
            'pending' => Schema::boolean()->description('Pending state. Requires administrateUsers; silently ignored on `create` without it (defaults to pending=true).'),
            'newPassword' => Schema::string()
                ->description('Set a new password. Update only; never returned. Self-edit allowed without administrateUsers (matches UsersController::actionSaveUser line 1703); non-self requires administrateUsers. Elevated-session caveat: Craft\'s CP requires elevation for self-password changes — MCP HTTP transport has no elevation today, documented as a follow-up gap.'),

            // Group membership (replacement list).
            'groupUids' => Schema::array(Schema::string())
                ->description('Replacement list of group UIDs the user should belong to. Each ADDED group (not pre-existing) requires assignUserGroup:{uid} on the caller. Kept groups do not re-gate. Pass [] to clear all groups.'),

            // Custom-field values — pass-through to Craft's setFieldValues().
            'fields' => Schema::object()
                ->additionalProperties(true)
                ->description('Custom field values keyed by handle. Forwarded verbatim to User::setFieldValues(); Craft normalises per field type. On return, only handles in Settings::$userCustomFieldAllowlist are serialised.'),

            // Delete-only knobs.
            'transferContentTo' => Schema::integer()->description('Delete only. User id who inherits the deleted user\'s authored entries (sets `inheritorOnDelete` which `User::afterDelete()` consumes). Caller needs deleteUsers on themselves; no extra permission against the recipient.'),
            'hardDelete' => Schema::boolean()->description('Delete only. When true, removes the row entirely (no restore possible). Default false.'),

            // List-mode filters.
            'status' => Schema::string()
                ->enum(self::LIST_STATUSES)
                ->description('List filter: one of `active`, `pending`, `suspended`, `locked`, `inactive`. Pass-through to UserQuery::status().'),
            'group' => Schema::any()
                ->description('List filter: group handle (string) or id (int). Pass-through to UserQuery::group().'),
            'can' => Schema::string()
                ->description('List filter: permission string. Returns users who hold this permission (admins always match). Pass-through to UserQuery::can().'),
            'search' => Schema::string()
                ->description('List filter: search query, matched against username/email/firstName/lastName via Craft\'s search service. Pass-through to UserQuery::search().'),
            'dateCreated' => Schema::any()
                ->description('List filter: date filter string with `>=`/`<=` operators (e.g. `>= 2024-01-01`). Pass-through to UserQuery::dateCreated().'),

            // List pagination.
            'limit' => Schema::integer()->minimum(1)->maximum(self::MAX_LIMIT)->description('list mode: page size. Default 50, max 200.'),
            'offset' => Schema::integer()->minimum(0)->description('list mode: offset. Default 0.'),

            // Idempotency.
            'idempotencyKey' => Schema::string()
                ->maxLength(self::IDEMPOTENCY_KEY_MAX_LENGTH)
                ->description('create / update only: server-side dedup token. Same key issued twice within 24h returns the cached envelope without re-saving. Skipped on stdio.'),
        ])->toArray();
    }

    /**
     * @inheritdoc
     *
     * Per-user visibility check. Returns `true` for stdio (trusted)
     * and for any HTTP caller with `viewUsers` permission. The per-
     * user re-check inside each mode delegates to Craft's
     * `Elements::canSave / canView / canDelete` plus the tool-layer
     * admin-protection gate.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function filterFor(?UserElement $user = null): bool
    {
        if ($user === null) {
            // stdio path — trusted local user.
            return true;
        }

        if ($user->admin) {
            return true;
        }

        return (bool) $user->can('viewUsers');
    }

    /**
     * @inheritdoc
     *
     * Users' mode-enum surface is uniform — every mode requires
     * `viewUsers` at the coarse level, so a user without it never
     * reaches `inputSchemaFor()` (filterFor hides the tool). Finer
     * per-mode gating (admin / administrateUsers / registerUsers /
     * deleteUsers) happens in `execute()`, not here, so the schema
     * surface is identical for every viewUsers caller. Distinct from
     * `drafts_and_revisions`/`content_audit`/`import_export` which
     * gate modes on permissions.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function inputSchemaFor(?UserElement $user = null): array
    {
        return static::getInputSchema();
    }

    /**
     * @inheritdoc
     *
     * @throws ToolException
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function execute(array $arguments): array
    {
        $mode = $this->_mode($arguments);
        if ($mode === null) {
            throw new ToolException('users: `mode` is required.');
        }

        if ($mode === 'create' || $mode === 'update') {
            $this->_assertTransportAllowsCredentialMutations($arguments);
        }

        return match ($mode) {
            'list' => $this->_list($arguments),
            'get' => $this->_get($arguments),
            'create' => $this->_create($arguments),
            'update' => $this->_update($arguments),
            'delete' => $this->_delete($arguments),
            default => throw new ToolException(
                "users: unknown mode `{$mode}`. Allowed: list / get / create / update / delete."
            ),
        };
    }

    // Protected Methods
    // =========================================================================

    /**
     * Users's permission gate is `viewUsers` for the coarse
     * `filterFor()` whole-tool visibility plus the in-execute() re-
     * check. Per-target / per-mode finer gates live in each mode
     * body.
     *
     * @param array<string,mixed> $arguments
     * @return string[]
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    protected function _requiredPermissions(array $arguments): array
    {
        return ['viewUsers'];
    }

    /**
     * Override `PermissionedToolTrait::_buildPermissionDeniedMessage()`
     * to emit the users-specific rich format (mode + missing
     * permission). Per-target / sensitive-field / admin-protection
     * denials emit their own messages in the mode methods.
     *
     * @param array<string,mixed> $arguments
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    protected function _buildPermissionDeniedMessage(string $missingPermission, array $arguments): string
    {
        $mode = $this->_mode($arguments) ?? '?';

        return sprintf(
            'permission denied — mode `%s` requires `%s`.',
            $mode,
            $missingPermission,
        );
    }

    // Private Methods
    // =========================================================================

    /**
     * Refuse credential / privilege mutations over the HTTP transport.
     *
     * Setting a new password (`newPassword`), changing the email
     * (`email`), or granting / modifying admin status (`admin`) are the
     * operations Craft's CP guards behind an elevated (re-authenticated)
     * session. The MCP HTTP transport has no elevated-session layer yet,
     * so these are refused over HTTP and remain available only over the
     * trusted local stdio transport.
     *
     * Keys on the real `InvocationContext::$transport` threaded from the
     * dispatcher — never inferred from a proxy such as a resolved-user
     * check. Fails closed: when no context was injected (transport
     * indeterminate) the request is treated as HTTP and refused.
     *
     * No-op when none of the guarded fields are present in the payload,
     * so non-sensitive create / update operations (custom fields, name
     * attributes, group assignment, etc.) keep working over HTTP.
     *
     * @param array<string,mixed> $arguments
     * @throws ToolException When a guarded field is present and the
     *                       resolved transport is not stdio.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _assertTransportAllowsCredentialMutations(array $arguments): void
    {
        $guarded = array_values(array_filter(
            self::HTTP_REFUSED_FIELDS,
            static fn(string $field): bool => array_key_exists($field, $arguments),
        ));
        if ($guarded === []) {
            return;
        }

        // Fail closed — only an explicit stdio transport is exempt.
        $isStdio = $this->_invocationContext !== null
            && $this->_invocationContext->transport === Server::TRANSPORT_STDIO;
        if ($isStdio) {
            return;
        }

        throw new ToolException(
            'users: changing password/email/admin status is not permitted over the HTTP ' .
                'transport; use the stdio transport (elevated-session support over HTTP is ' .
                'not yet available).'
        );
    }

    /**
     * List-mode dispatch. Paginated query with filters, each result
     * PII-redacted per caller.
     *
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     * @throws ToolException
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _list(array $arguments): array
    {
        $this->_assertPermission($arguments);

        $limit = $this->_limit($arguments, self::DEFAULT_LIMIT, self::MAX_LIMIT);
        $offset = $this->_offset($arguments);

        $query = UserElement::find()
            ->status(null)
            ->limit($limit)
            ->offset($offset);

        $this->_applyListFilters($query, $arguments);

        // Pre-pagination total — same query without limit/offset.
        // Mirrors Skill::_list(). Lets the LLM page through
        // confidently without re-running the query just to see
        // whether more rows exist.
        $totalQuery = clone $query;
        $totalQuery->limit(null)->offset(null);
        $total = (int) $totalQuery->count();

        $caller = Craft::$app->getUser()->getIdentity();
        $users = $query->all();
        $serialised = [];
        foreach ($users as $user) {
            // Per-target view gate — quietly skip any user the caller
            // cannot view. Defense in depth against permission drift
            // between filterFor() and per-target authorisation.
            if ($caller !== null && !Craft::$app->getElements()->canView($user, $caller)) {
                continue;
            }
            $serialised[] = $this->_serializeUser($user, $caller);
        }

        return [
            'success' => true,
            'mode' => 'list',
            'users' => $serialised,
            'count' => count($serialised),
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /**
     * Get-mode dispatch. Resolves by id / uid / email / username
     * (first non-empty wins), checks per-target `canView`, returns
     * the PII-redacted envelope.
     *
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     * @throws ToolException
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _get(array $arguments): array
    {
        $this->_assertPermission($arguments);

        $user = $this->_resolveUser($arguments);
        $this->_assertCanView($user);

        $caller = Craft::$app->getUser()->getIdentity();

        return [
            'success' => true,
            'mode' => 'get',
            'user' => $this->_serializeUser($user, $caller),
        ];
    }

    /**
     * Create-mode dispatch. Instantiates a fresh `User`, applies the
     * admin / registerUsers / administrateUsers / assignUserGroup
     * gates, saves. Returns the success envelope or a validation
     * envelope.
     *
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     * @throws ToolException
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _create(array $arguments): array
    {
        // Permission was checked when this response was originally
        // computed; userId is in the cache key — see IdempotencyTrait.
        $cacheHit = $this->_idempotencyCacheHit($arguments);
        if ($cacheHit !== null) {
            return $cacheHit;
        }

        $this->_assertPermission($arguments);

        $caller = Craft::$app->getUser()->getIdentity();

        // Admin-promotion gate first — caller must be admin to create
        // an admin user (matches UsersController::actionSavePermissions
        // line 1288). Refuse before the registerUsers check so the
        // error message is unambiguous and the LLM can recover without
        // also passing the registration gate.
        $adminRequested = ($arguments['admin'] ?? null) === true;
        if ($adminRequested && ($caller === null || !$caller->admin)) {
            throw new ToolException(
                'users: create denied — `admin: true` requires the caller is an admin. ' .
                    'Non-admin callers cannot promote a new user to admin.'
            );
        }

        // Registration permission — `canRegisterUsers()` rolls
        // `registerUsers` + Craft's edition / count check together.
        if ($caller !== null && !$caller->admin && !$caller->canRegisterUsers()) {
            throw new ToolException(
                'users: create denied — caller lacks `registerUsers` permission ' .
                    '(or the install cannot accept additional users).'
            );
        }

        $element = new UserElement();

        // Apply attributes, respecting the administrateUsers gate for
        // active/suspended/pending — silently ignore when missing
        // (per locked decision 19 in docs/plans/gate-8.5.md). Default
        // pending=true matches UsersController::actionSaveUser line
        // 1733.
        $this->_applyCreateAttributes($element, $arguments, $caller);
        $this->_applyFields($element, $arguments);

        if ($adminRequested) {
            $element->admin = true;
        }

        if (!Craft::$app->getElements()->saveElement($element, runValidation: true)) {
            return $this->_validationEnvelope($element, 'create');
        }

        // Group assignment — must happen after the save so the user
        // has an id. Each newly added group requires
        // assignUserGroup:{uid} on the caller.
        if (array_key_exists('groupUids', $arguments)) {
            $this->_applyGroupAssignment($element, $arguments['groupUids'], $caller, []);
        }

        $envelope = $this->_successEnvelope($element, 'create', $caller);
        $this->_cacheIdempotencyEnvelope($arguments, $envelope);

        return $envelope;
    }

    /**
     * Update-mode dispatch. Loads by id / uid (with a trashed-probe
     * fallback for the hint message), enforces the admin-protection
     * gate, the sensitive-field gate, and the group-assignment gate,
     * then saves.
     *
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     * @throws ToolException
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _update(array $arguments): array
    {
        // Permission was checked when this response was originally
        // computed; userId is in the cache key — see IdempotencyTrait.
        $cacheHit = $this->_idempotencyCacheHit($arguments);
        if ($cacheHit !== null) {
            return $cacheHit;
        }

        $this->_assertPermission($arguments);

        $element = $this->_resolveUser($arguments);
        $caller = Craft::$app->getUser()->getIdentity();

        // Per-target canSave (covers register / editUsers / self).
        if ($caller !== null && !Craft::$app->getElements()->canSave($element, $caller)) {
            throw new ToolException(
                'users: update denied — caller cannot save this user.'
            );
        }

        // **Admin-protection gate** — the headline correction from
        // docs/plans/gate-8.5.md locked decision 10. `User::canSave`
        // does NOT enforce "non-admin cannot edit an admin"
        // natively; that protection lives in `canDelete` and ad-hoc
        // in `UsersController` (`requireAdmin(false)` at line 2194).
        // We add the gate here to match Craft's CP posture.
        $isSelf = $caller !== null && $caller->id === $element->id;
        if ($element->admin && $caller !== null && !$caller->admin && !$isSelf) {
            throw new ToolException(
                'users: update denied — non-admin callers cannot edit an admin user. ' .
                    'Even with `administrateUsers`, only an admin may edit another admin ' .
                    '(matches UsersController::actionDeactivateUser line 2194).'
            );
        }

        // Admin promotion / demotion — caller must be admin. Matches
        // UsersController::actionSavePermissions line 1288. Demotion
        // is also caller-admin-only per the safer Cortex posture
        // (locked decision 18).
        if (array_key_exists('admin', $arguments) && is_bool($arguments['admin'])) {
            $newAdmin = $arguments['admin'];
            if ($newAdmin !== $element->admin && ($caller === null || !$caller->admin)) {
                throw new ToolException(
                    'users: update denied — changing the `admin` field requires the ' .
                        'caller is an admin. ' .
                        (
                            $newAdmin
                                ? 'Admin promotion is admin-only.'
                                : 'Admin demotion is also admin-only per Cortex policy.'
                        )
                );
            }
        }

        // Sensitive-field gate — administrateUsers required for non-
        // self mutations on email / username / active / suspended /
        // pending / locked / newPassword. Self-edit carve-outs apply
        // for email / username / newPassword.
        $this->_assertSensitiveFields($element, $arguments, $caller, $isSelf);

        // Apply attributes. Admin / status flags handled separately
        // (above) so they don't double-apply through the generic
        // attribute setter.
        $this->_applyUpdateAttributes($element, $arguments, $caller, $isSelf);
        $this->_applyFields($element, $arguments);

        if (array_key_exists('admin', $arguments) && is_bool($arguments['admin'])) {
            $element->admin = $arguments['admin'];
        }

        if (!Craft::$app->getElements()->saveElement($element, runValidation: true)) {
            return $this->_validationEnvelope($element, 'update');
        }

        // Group assignment — keep-as-is groups do not re-gate; only
        // newly added groups require assignUserGroup:{uid} on the
        // caller. Mirrors UsersController::_saveUserGroups line 2845.
        if (array_key_exists('groupUids', $arguments)) {
            $existingUids = array_values(array_filter(array_map(
                static fn(UserGroup $g): ?string => $g->uid,
                $element->getGroups(),
            )));
            $this->_applyGroupAssignment($element, $arguments['groupUids'], $caller, $existingUids);
        }

        $envelope = $this->_successEnvelope($element, 'update', $caller);
        $this->_cacheIdempotencyEnvelope($arguments, $envelope);

        return $envelope;
    }

    /**
     * Delete-mode dispatch. Soft-deletes by default; `hardDelete:
     * true` removes the row entirely. Optional `transferContentTo`
     * sets `inheritorOnDelete` so the recipient inherits authored
     * entries via `User::afterDelete()`. Per-target `canDelete`
     * covers admin-protection natively at `elements/User.php:1828`.
     *
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     * @throws ToolException
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _delete(array $arguments): array
    {
        $this->_assertPermission($arguments);

        $element = $this->_resolveUser($arguments);
        $caller = Craft::$app->getUser()->getIdentity();

        if ($caller !== null && !Craft::$app->getElements()->canDelete($element, $caller)) {
            throw new ToolException(
                'users: delete denied — caller cannot delete this user. ' .
                    'Non-admin callers cannot delete admin users even with `deleteUsers`.'
            );
        }

        // Resolve transferContentTo recipient. Per locked decision
        // 12, the caller already needs deleteUsers (covered by
        // canDelete above); no extra permission against the
        // recipient.
        $transferContentToId = null;
        if (array_key_exists('transferContentTo', $arguments)) {
            $candidate = $arguments['transferContentTo'];
            if (is_int($candidate) || (is_string($candidate) && ctype_digit($candidate))) {
                $candidate = (int) $candidate;
                $recipient = Craft::$app->getUsers()->getUserById($candidate);
                if (!$recipient instanceof UserElement) {
                    throw new ToolException("users: transferContentTo user id={$candidate} not found.");
                }
                // `inheritorOnDelete` is deprecated in Craft 5.10.0
                // but no successor has shipped yet — Craft itself
                // still uses it (`elements/actions/DeleteUsers.php:171`).
                // Single seam to swap when the replacement lands.
                $element->inheritorOnDelete = $recipient;
                $transferContentToId = $recipient->id !== null ? (int) $recipient->id : null;
            }
        }

        $hardDelete = (bool) ($arguments['hardDelete'] ?? false);

        if (!Craft::$app->getElements()->deleteElement($element, hardDelete: $hardDelete)) {
            throw new ToolException(
                "users: delete failed for id={$element->id}. See Craft logs for details."
            );
        }

        return [
            'success' => true,
            'mode' => 'delete',
            'id' => $element->id !== null ? (int) $element->id : null,
            'uid' => $element->uid,
            'hardDeleted' => $hardDelete,
            'contentTransferredTo' => $transferContentToId,
        ];
    }

    /**
     * Resolve a user from arguments. First non-empty wins among
     * `id` > `uid` > `email` > `username`. Email / username fall
     * back to `getUserByUsernameOrEmail` (case-insensitive on MySQL
     * per the Users service's query). On miss, probes the trashed
     * slot and emits a hint message; throws ToolException either
     * way.
     *
     * @param array<string,mixed> $arguments
     * @throws ToolException
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _resolveUser(array $arguments): UserElement
    {
        $id = $arguments['id'] ?? null;
        $uid = $arguments['uid'] ?? null;
        $email = $arguments['email'] ?? null;
        $username = $arguments['username'] ?? null;

        $hasId = is_int($id) || (is_string($id) && ctype_digit($id));
        $hasUid = is_string($uid) && $uid !== '';
        $hasEmail = is_string($email) && $email !== '';
        $hasUsername = is_string($username) && $username !== '';

        if (!$hasId && !$hasUid && !$hasEmail && !$hasUsername) {
            throw new ToolException('users: one of `id` / `uid` / `email` / `username` is required.');
        }

        if ($hasId) {
            $user = Craft::$app->getUsers()->getUserById((int) $id);
            if ($user instanceof UserElement) {
                return $user;
            }
            $this->_throwResolutionMiss('id', (string) $id, ['id' => (int) $id]);
        }

        if ($hasUid) {
            $user = UserElement::find()->uid($uid)->status(null)->one();
            if ($user instanceof UserElement) {
                return $user;
            }
            $this->_throwResolutionMiss('uid', $uid, ['uid' => $uid]);
        }

        $lookup = $hasEmail ? $email : $username;
        $label = $hasEmail ? 'email' : 'username';
        $user = Craft::$app->getUsers()->getUserByUsernameOrEmail($lookup);
        if ($user instanceof UserElement) {
            return $user;
        }

        $probeFilter = $hasEmail ? ['email' => $email] : ['username' => $username];
        $this->_throwResolutionMiss($label, $lookup, $probeFilter);
    }

    /**
     * Throw a `ToolException` for a missed user lookup. Probes the
     * trashed slot first and emits a hint pointing at the Craft CP
     * user-restore flow when the user exists trashed.
     *
     * @param array<string,mixed> $probeFilter
     * @throws ToolException
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _throwResolutionMiss(string $field, string $value, array $probeFilter): never
    {
        $probe = UserElement::find()
            ->status(null)
            ->trashed(true);
        foreach ($probeFilter as $attr => $val) {
            $probe->{$attr}($val);
        }
        $trashed = $probe->one();
        if ($trashed instanceof UserElement) {
            $probeKey = $trashed->id !== null ? "id={$trashed->id}" : "uid={$trashed->uid}";
            throw new ToolException(
                "users: {$probeKey} is trashed. To remove permanently, use mode=delete " .
                    'with hardDelete=true; to restore, use the Craft CP user-restore flow.'
            );
        }

        throw new ToolException("users: no user found for {$field}=`{$value}`.");
    }

    /**
     * Per-target view check delegated to Craft's `Elements::canView`.
     * Skips the stdio path.
     *
     * @throws ToolException
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _assertCanView(UserElement $user): void
    {
        $caller = Craft::$app->getUser()->getIdentity();
        if ($caller === null) {
            return;
        }

        if (!Craft::$app->getElements()->canView($user, $caller)) {
            throw new ToolException(
                'users: view denied — caller cannot view this user.'
            );
        }
    }

    /**
     * Apply list-mode filters to the query. Pass-through for status /
     * group / admin / can / search / dateCreated. `dateCreated` is
     * normalised through `DateTimeHelper::toDateTime()` when the
     * argument is a plain ISO string so Craft's query can compare
     * cleanly.
     *
     * @param UserQuery<array-key,UserElement> $query
     * @param array<string,mixed> $arguments
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _applyListFilters(UserQuery $query, array $arguments): void
    {
        if (array_key_exists('status', $arguments) && is_string($arguments['status']) && $arguments['status'] !== '') {
            $query->status($arguments['status']);
        }

        if (array_key_exists('group', $arguments)) {
            $group = $arguments['group'];
            if (is_string($group) && $group !== '') {
                $query->group($group);
            } elseif (is_int($group) || (is_string($group) && ctype_digit($group))) {
                $query->groupId((int) $group);
            }
        }

        if (array_key_exists('admin', $arguments) && is_bool($arguments['admin'])) {
            $query->admin($arguments['admin']);
        }

        if (array_key_exists('can', $arguments) && is_string($arguments['can']) && $arguments['can'] !== '') {
            $query->can($arguments['can']);
        }

        if (array_key_exists('search', $arguments) && is_string($arguments['search']) && $arguments['search'] !== '') {
            $query->search($arguments['search']);
        }

        if (array_key_exists('dateCreated', $arguments)) {
            $value = $arguments['dateCreated'];
            if (is_string($value) && $value !== '') {
                $query->dateCreated($value);
            } elseif (is_array($value)) {
                $query->dateCreated($value);
            }
        }
    }

    /**
     * Apply create-time attributes. Honours the administrateUsers
     * gate for active/suspended/pending — silently ignored when the
     * caller lacks it (locked decision 19). Defaults to pending=true
     * per Craft convention (`UsersController::actionSaveUser` line
     * 1733).
     *
     * @param array<string,mixed> $arguments
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _applyCreateAttributes(UserElement $element, array $arguments, ?UserElement $caller): void
    {
        $canAdministrate = $caller === null || $caller->admin || $caller->can('administrateUsers');

        // Email is required on create per Craft's User defineRules.
        if (array_key_exists('email', $arguments) && is_string($arguments['email'])) {
            $element->email = $arguments['email'];
        }

        if (array_key_exists('username', $arguments) && is_string($arguments['username'])) {
            $element->username = $arguments['username'];
        } elseif (is_string($element->email) && $element->email !== '') {
            // Default username to email — Craft does the same when
            // `useEmailAsUsername` is set.
            $element->username = $element->email;
        }

        foreach (['firstName', 'lastName', 'fullName'] as $attr) {
            if (array_key_exists($attr, $arguments) && is_string($arguments[$attr])) {
                $element->{$attr} = $arguments[$attr];
            }
        }

        if ($canAdministrate) {
            foreach (['active', 'suspended', 'pending'] as $flag) {
                if (array_key_exists($flag, $arguments) && is_bool($arguments[$flag])) {
                    $element->{$flag} = $arguments[$flag];
                }
            }
            // Honour `active: true` as Craft's "bypass verification"
            // shortcut. Without explicit `active`, default to
            // pending=true.
            if (!$element->active && !array_key_exists('pending', $arguments)) {
                $element->pending = true;
            }
        } else {
            // Without administrateUsers, silently ignore caller-
            // supplied status fields and default to pending=true per
            // Craft's UsersController::actionSaveUser line 1733.
            $element->pending = true;
        }
    }

    /**
     * Apply update-time attributes. Sensitive fields are already
     * gated by `_assertSensitiveFields()` before this runs — this
     * method only writes the fields, it does not gate them.
     *
     * @param array<string,mixed> $arguments
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _applyUpdateAttributes(UserElement $element, array $arguments, ?UserElement $caller, bool $isSelf): void
    {
        if (array_key_exists('email', $arguments) && is_string($arguments['email'])) {
            $element->email = $arguments['email'];
        }

        if (array_key_exists('username', $arguments) && is_string($arguments['username'])) {
            $element->username = $arguments['username'];
        }

        foreach (['firstName', 'lastName', 'fullName'] as $attr) {
            if (array_key_exists($attr, $arguments) && is_string($arguments[$attr])) {
                $element->{$attr} = $arguments[$attr];
            }
        }

        foreach (['active', 'suspended', 'pending', 'locked'] as $flag) {
            if (array_key_exists($flag, $arguments) && is_bool($arguments[$flag])) {
                $element->{$flag} = $arguments[$flag];
            }
        }

        if (array_key_exists('newPassword', $arguments) && is_string($arguments['newPassword'])) {
            $element->newPassword = $arguments['newPassword'];
        }
    }

    /**
     * Assert the caller may mutate every sensitive field present in
     * `$arguments`. Throws `ToolException` on the first miss. Self-
     * edit carve-outs for `email` / `username` / `newPassword` apply
     * per `SELF_EDIT_CARVE_OUTS`.
     *
     * @param array<string,mixed> $arguments
     * @throws ToolException
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _assertSensitiveFields(UserElement $element, array $arguments, ?UserElement $caller, bool $isSelf): void
    {
        if ($caller === null || $caller->admin) {
            return;
        }

        $canAdministrate = (bool) $caller->can('administrateUsers');

        foreach (self::SENSITIVE_FIELDS as $field) {
            if (!array_key_exists($field, $arguments)) {
                continue;
            }

            // Self-edit carve-outs: email / username / newPassword
            // for self do not require administrateUsers.
            if ($isSelf && in_array($field, self::SELF_EDIT_CARVE_OUTS, true)) {
                continue;
            }

            // For email / username, only refuse when the value would
            // actually change — Craft itself skips when the new
            // email equals the old (UsersController line 1652).
            if (in_array($field, ['email', 'username'], true)) {
                $current = $element->{$field} ?? null;
                if (is_string($arguments[$field]) && $current === $arguments[$field]) {
                    continue;
                }
            }

            if (!$canAdministrate) {
                throw new ToolException(sprintf(
                    'users: update denied — `%s` is a sensitive field requiring `administrateUsers`. ' .
                        'Omit the field to leave it unchanged.',
                    $field,
                ));
            }
        }
    }

    /**
     * Apply a group assignment diff. Each newly added group (not in
     * `$existingGroupUids`) requires `assignUserGroup:{uid}` on the
     * caller. Kept groups do not re-gate. Mirrors
     * `UsersController::_saveUserGroups` semantics from line 2845.
     *
     * @param string[] $existingGroupUids
     * @throws ToolException
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _applyGroupAssignment(UserElement $element, mixed $groupUidsArg, ?UserElement $caller, array $existingGroupUids): void
    {
        if (!is_array($groupUidsArg)) {
            return;
        }

        // Resolve UIDs to UserGroup models and group id list. Reject
        // any UID that doesn't resolve.
        $userGroupsService = Craft::$app->getUserGroups();
        $groupIds = [];
        $resolvedGroups = [];

        foreach ($groupUidsArg as $uid) {
            if (!is_string($uid) || $uid === '') {
                continue;
            }
            $group = $userGroupsService->getGroupByUid($uid);
            if (!$group instanceof UserGroup) {
                throw new ToolException("users: group uid=`{$uid}` not found.");
            }
            $groupIds[] = (int) $group->id;
            $resolvedGroups[] = $group;
        }

        // Permission gate — only newly added groups re-gate. Caller
        // must hold `assignUserGroup:{uid}` on each new UID.
        $existingSet = array_flip($existingGroupUids);
        if ($caller !== null && !$caller->admin) {
            foreach ($resolvedGroups as $group) {
                if (isset($existingSet[$group->uid])) {
                    continue;
                }
                if (!$caller->can("assignUserGroup:{$group->uid}")) {
                    throw new ToolException(sprintf(
                        'users: group assignment denied — caller cannot assign group ' .
                            '`%s` (requires `assignUserGroup:%s`).',
                        $group->name,
                        $group->uid,
                    ));
                }
            }
        }

        if ($element->id === null) {
            return;
        }

        Craft::$app->getUsers()->assignUserToGroups((int) $element->id, $groupIds);
        $element->setGroups($resolvedGroups);
    }

    /**
     * Success envelope shape for get / create / update.
     *
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _successEnvelope(UserElement $element, string $mode, ?UserElement $caller): array
    {
        return [
            'success' => true,
            'mode' => $mode,
            'user' => $this->_serializeUser($element, $caller),
        ];
    }

    /**
     * PII-aware user serialiser. Field visibility tiered per caller:
     *   - Always: id, uid, username, full/first/last name, status
     *     flags (read-only — mutation gates apply on write), date
     *     fields, group uids, address references.
     *   - `editUsers`: email, unverifiedEmail, lockout counters.
     *   - `administrateUsers`: passwordResetRequired.
     *   - Custom fields: only handles in
     *     `Settings::$userCustomFieldAllowlist`. Independent of
     *     caller permission — handles NOT on the allowlist are NEVER
     *     returned, even for admin callers.
     *   - Credentials (password, verificationCode, newPassword,
     *     etc.): NEVER returned.
     *
     * `$caller === null` (stdio) is treated as admin-equivalent for
     * visibility, consistent with the Address tool's stdio posture.
     *
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _serializeUser(UserElement $target, ?UserElement $caller): array
    {
        $canSeeEditUsersFields = $caller === null || $caller->admin || (bool) $caller->can('editUsers');
        $canSeeAdministrateFields = $caller === null || $caller->admin || (bool) $caller->can('administrateUsers');

        $envelope = [
            'id' => $target->id !== null ? (int) $target->id : null,
            'uid' => $target->uid,
            'username' => $target->username,
            'fullName' => $target->fullName,
            'firstName' => $target->firstName,
            'lastName' => $target->lastName,
            'active' => $target->active,
            'suspended' => $target->suspended,
            'pending' => $target->pending,
            'locked' => $target->locked,
            'admin' => $target->admin,
            'lastLoginDate' => $target->lastLoginDate !== null
                ? DateTimeHelper::toIso8601($target->lastLoginDate)
                : null,
            'dateCreated' => $target->dateCreated !== null
                ? DateTimeHelper::toIso8601($target->dateCreated)
                : null,
            'dateUpdated' => $target->dateUpdated !== null
                ? DateTimeHelper::toIso8601($target->dateUpdated)
                : null,
            'groupUids' => array_values(array_filter(array_map(
                static fn(UserGroup $g): ?string => $g->uid,
                $target->getGroups(),
            ))),
            'addresses' => $this->_serializeAddressReferences($target),
        ];

        if ($canSeeEditUsersFields) {
            $envelope['email'] = $target->email;
            $envelope['unverifiedEmail'] = $target->unverifiedEmail;
            $envelope['invalidLoginCount'] = $target->invalidLoginCount;
            $envelope['lastInvalidLoginDate'] = $target->lastInvalidLoginDate !== null
                ? DateTimeHelper::toIso8601($target->lastInvalidLoginDate)
                : null;
            $envelope['lockoutDate'] = $target->lockoutDate !== null
                ? DateTimeHelper::toIso8601($target->lockoutDate)
                : null;
        }

        if ($canSeeAdministrateFields) {
            $envelope['passwordResetRequired'] = $target->passwordResetRequired;
        }

        $envelope['fields'] = $this->_serializeAllowlistedFields($target);

        return $envelope;
    }

    /**
     * Build the `addresses` field — references only (id / uid /
     * ownerType: user). Never inlines address bodies; full address
     * data lives in the Gate 8.4 `address` Pro tool.
     *
     * @return array<int,array<string,mixed>>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _serializeAddressReferences(UserElement $target): array
    {
        if ($target->id === null) {
            return [];
        }

        try {
            $addresses = AddressElement::find()
                ->ownerId((int) $target->id)
                ->status(null)
                ->all();
        } catch (Throwable) {
            return [];
        }

        return array_map(
            static fn(AddressElement $a): array => [
                'id' => $a->id !== null ? (int) $a->id : null,
                'uid' => $a->uid,
                'ownerType' => 'user',
            ],
            $addresses,
        );
    }

    /**
     * Serialise only those custom field values whose handle appears
     * in `Settings::$userCustomFieldAllowlist`. Handles NOT on the
     * allowlist are NEVER returned, regardless of caller permission
     * — defense in depth against Craft's missing per-field-value
     * permission model.
     *
     * Values are normalised through a minimal serialiser:
     *   - scalars / null pass through
     *   - DateTimeInterface → ISO 8601
     *   - everything else stringified via `__toString` when possible
     *     else stubbed
     *
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _serializeAllowlistedFields(UserElement $target): array
    {
        $plugin = Cortex::getInstance();
        $settings = $plugin->getSettings();
        $allowlist = $settings->userCustomFieldAllowlist ?? [];
        if ($allowlist === []) {
            return [];
        }

        $values = [];
        foreach ($allowlist as $handle) {
            if (!is_string($handle) || $handle === '') {
                continue;
            }
            try {
                $value = $target->getFieldValue($handle);
            } catch (Throwable) {
                continue;
            }
            $values[$handle] = $this->_serializeScalarish($value);
        }
        return $values;
    }

    /**
     * Best-effort scalar projection of a custom field value. Used by
     * the allowlist serialiser — every value the LLM sees here was
     * explicitly opted in by the operator, so the serializer
     * defaults to "show me something" rather than the conservative
     * relation stub `ElementSerializer` emits.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _serializeScalarish(mixed $value): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }
        if ($value instanceof DateTimeInterface) {
            return DateTimeHelper::toIso8601($value);
        }
        if (is_array($value)) {
            return array_map(fn(mixed $v): mixed => $this->_serializeScalarish($v), $value);
        }
        if (is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }
        return [
            'type' => 'unserializable',
            'class' => is_object($value) ? $value::class : gettype($value),
        ];
    }
}
