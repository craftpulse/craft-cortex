<?php

namespace craftpulse\herald\controllers;

use Craft;
use craft\elements\User;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\web\Controller;
use craftpulse\herald\db\InvocationQuery;
use craftpulse\herald\Herald;
use craftpulse\herald\models\Token;
use craftpulse\herald\records\OauthClient as OauthClientRecord;
use craftpulse\herald\tools\support\InvocationLogger;
use craftpulse\herald\web\cp\RowSerializer;
use craftpulse\herald\web\cp\TableData;
use craftpulse\herald\web\cp\TableParams;
use yii\base\Exception;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * =========================================================================
 * Web controller — Herald CP screens + runtime allowlist override
 * management.
 *
 * The Gate 9 plan moves the Settings boundary off Craft's built-in
 * `plugins/save-plugin-settings` action onto `actionSave` here so the
 * controller can own the full tabbed-CP flow.
 *
 * Tabs shipped: Settings (project-config defaults + save), Tokens
 * (9.5), Allowlist (9.2), Activity (9.3 — audit-log VueAdminTable +
 * filters + redacted-detail slideout). Connection (9.4) is the
 * remaining placeholder.
 *
 * Permission posture:
 *   - Settings view (`actionIndex`) + save (`actionSave`) — gated by
 *     `requirePermission(PERMISSION_MANAGE_SETTINGS)`, NOT `requireAdmin`,
 *     per the estate settings-permission doctrine; the save additionally
 *     fails closed on `allowAdminChanges` (the write axis, independent of
 *     who holds the permission).
 *   - Tokens / Connection view actions                — `requireAdmin(false)`.
 *   - Activity view + table-data + row actions        — `requirePermission(Herald::PERMISSION_VIEW_ACTIVITY)`.
 *     Non-admins are scoped server-side to their own
 *     rows (fail-closed) — see `actionActivityTableData`.
 *   - Grant / token / client mutation actions         — `requirePostRequest`
 *                                                       + `requireAdmin(requireAdminChanges: true)`.
 *
 * Edition posture per Gate 9.7: Tokens / Activity / Connection are Pro
 * surfaces — every action behind those tabs opens with `_requirePro()`
 * (403 on Free) BEFORE its permission gates. Settings + Allowlist stay
 * Free. The tab map in `_cp/_layout.twig` hides the Pro tabs on Free,
 * but these gates are the enforcement; the tabs are UX.
 * =========================================================================
 *
 * @author CraftPulse
 * @since  5.0.0
 */
class SettingsController extends Controller
{
    // Constants
    // =========================================================================

    /**
     * Permission that grants access to the Herald Settings screen. Per
     * Michael's estate settings-permission doctrine the screen is gated by
     * a dedicated permission, NOT by `requireAdmin`: the permission decides
     * WHO may be on the screen (delegatable to a non-admin group), while
     * `allowAdminChanges` decides only WHETHER writes succeed. Admins hold
     * every permission implicitly, so an admin still reaches the screen.
     *
     * Declared here (the enforcing controller) as the single source of
     * truth and referenced from `beforeAction` / the action gates, the
     * permission registration in `PluginTrait`, and the CP nav gate in
     * `Herald::getCpNavItem()` — a bare literal would drift silently and a
     * typo would pass for admins while denying everyone else.
     *
     * @since 5.0.0
     */
    public const PERMISSION_MANAGE_SETTINGS = 'herald:manage-settings';

    /**
     * Permission that grants access to the Temporary grants screen and the
     * issue / revoke actions behind it. Its own permission (not folded
     * under `manageSettings`) because issuing a grant widens the
     * `craft_command` execution allowlist for a user, a privileged
     * operation an operator may want to delegate independently of settings
     * management. Admin-only by default: the permission defaults to
     * not-granted and admins hold every permission implicitly, so nothing
     * changes until an operator deliberately delegates it.
     *
     * Declared on the enforcing controller as the single source of truth,
     * referenced from `PluginTrait`'s registration, the action gates, and
     * the CP nav gate in `Herald::getCpNavItem()`.
     *
     * @since 5.0.0
     */
    public const PERMISSION_MANAGE_GRANTS = 'herald:manage-grants';

    // Private Properties
    // =========================================================================

    /**
     * @var RowSerializer|null Memoized view-model mapper for the
     * VueAdminTable feeds. Built lazily so the actions that render no table
     * never construct it.
     */
    private ?RowSerializer $_rows = null;

    // Public Methods
    // =========================================================================

    /**
     * Settings tab — landing page for `settings/plugins/herald`. Renders
     * the project-config-synced plugin defaults under the tabbed
     * lineage. Runtime allowlist overrides (DB-backed time-bound
     * grants) live on the Allowlist tab — see `actionAllowlist`.
     *
     * Gated by `PERMISSION_MANAGE_SETTINGS` (not `requireAdmin`) so the
     * screen can be delegated to a non-admin group; it stays viewable in
     * read-only mode because `allowAdminChanges` gates only the save, not
     * the view. `actionSave` re-checks the write axis.
     *
     * @throws \craft\errors\MissingComponentException if the view component is unavailable.
     * @throws \yii\base\InvalidConfigException        from `Herald::getInstance()`.
     * @throws \yii\web\ForbiddenHttpException         from `requirePermission`.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function actionIndex(): Response
    {
        $this->requirePermission(self::PERMISSION_MANAGE_SETTINGS);

        $plugin = Herald::getInstance();
        $settings = $plugin->getSettings();

        // Resolve the saved content-level (`allowedCommands`) and
        // admin-level (`adminLevelCommands`) patterns onto the enumerated
        // console-command groups so the browser can render two toggle
        // sections. Patterns that don't map to a known group-glob or exact
        // action id (power-user globs, since-removed plugins) surface in
        // the per-bucket custom-patterns editable tables — never silently
        // dropped, never moved across buckets.
        $toggleState = $plugin->allowlist->mapPatternsToToggleState(
            $settings->allowedCommands,
            $settings->adminLevelCommands,
        );

        return $this->renderTemplate('herald/_cp/settings', [
            'plugin' => $plugin,
            'settings' => $settings,
            'commandGroups' => $toggleState['groups'],
            'contentCustomPatterns' => $toggleState['contentCustomPatterns'],
            'adminCustomPatterns' => $toggleState['adminCustomPatterns'],
            'allowedCommandsOverridden' => $this->isSettingOverridden('allowedCommands'),
            'adminLevelCommandsOverridden' => $this->isSettingOverridden('adminLevelCommands'),
            'allowAdminChanges' => Craft::$app->getConfig()->getGeneral()->allowAdminChanges,
        ]);
    }

    /**
     * Tokens tab — VueAdminTable listing of live bearer tokens plus the
     * "+ New token" Garnish.Slideout trigger. The data layer
     * (`Tokens` service) ships from Gate 7.2; 9.5 wires the CP surface
     * onto it without touching the service.
     *
     * Admin-only per locked decision 6 — token issuance/revocation is an
     * admin authority. View accessible in read-only mode
     * (`requireAdmin(false)`); `actionIssueToken` / `actionRevokeToken`
     * strictly gate writes.
     *
     * The settings model is passed in for the slideout's TTL placeholder
     * (`settings.tokenTtlDefault`).
     *
     * @throws \craft\errors\MissingComponentException if the view component is unavailable.
     * @throws \yii\base\InvalidConfigException        from `Herald::getInstance()`.
     * @throws \yii\web\ForbiddenHttpException         from `requireAdmin`.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function actionTokens(): Response
    {
        $this->_requirePro();

        $this->requireAdmin(false);

        return $this->renderTemplate('herald/_cp/tokens', [
            'settings' => Herald::getInstance()->getSettings(),
        ]);
    }

    /**
     * VueAdminTable data endpoint for the Tokens tab. Returns the
     * `{pagination, data}` shape Craft's Vue component consumes — see
     * `vendor/craftcms/cms/src/helpers/AdminTable.php::paginationLinks`
     * for the canonical pagination dict. Mirrors
     * `actionAllowlistTableData` (the pattern locked in 9.2).
     *
     * Honours the standard VueAdminTable params:
     *   - `page` (int, default 1)
     *   - `per_page` (int, default 50, capped at 100)
     *   - `search` (string, optional — substring match across `name` + `tokenPrefix`)
     *   - `sort.0.field` / `sort.0.direction` (optional)
     *
     * Pagination, search, and sort are applied in-PHP — token count is
     * bounded by admin issuance (low dozens at most), so the cost is
     * constant. If usage scales we follow up with `Tokens::getPaginated()`
     * per the sub-gate-9.5 risk note.
     *
     * The row shape is locked: `[id, name, tokenPrefix, user, expiresAt, lastUsedAt, dateCreated]`.
     * `tests/Controllers/SettingsControllerTokensTest.php` asserts the
     * tuple exactly — drift = test failure.
     *
     * View access only (`requireAdmin(false)`); the row trash icon
     * routes through `actionRevokeToken` which gates writes via
     * `requireAdmin(requireAdminChanges: true)`.
     *
     * @throws \craft\errors\MissingComponentException if the view component is unavailable.
     * @throws \yii\base\InvalidConfigException        from `Herald::getInstance()`.
     * @throws \yii\web\BadRequestHttpException        from `requireAcceptsJson` on non-JSON callers.
     * @throws \yii\web\ForbiddenHttpException         from `requireAdmin`.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function actionTokensTableData(): Response
    {
        $this->_requirePro();

        $this->requireAcceptsJson();
        $this->requireAdmin(false);

        $params = $this->_tableParams();

        // `getAll()` returns non-deleted tokens, newest first. Map to the
        // serialised row tuple up front so search / sort operate on the
        // shape the table consumes.
        $rows = array_map(
            fn(Token $token): array => $this->_rows()->serializeToken($token),
            Herald::getInstance()->tokens->getAll(),
        );

        $rows = TableData::search($rows, ['name', 'tokenPrefix'], $params->search);
        $rows = TableData::sort($rows, $params, [
            'name' => 'name',
            'expiresAt' => 'expiresAt',
            'lastUsedAt' => 'lastUsedAt',
            'dateCreated' => 'dateCreated',
        ], 'dateCreated');

        return $this->asJson(TableData::page($rows, $params));
    }

    /**
     * Render the "+ New token" slideout body partial. Fetched by
     * `Herald.openTokenIssuanceSlideout()` and passed into
     * `new Craft.Slideout(html, {...})`.
     *
     * Returns `{html, headHtml, bodyHtml}` JSON — NOT a bare fragment.
     * The partial's `forms.elementSelectField` registers its
     * `Craft.BaseElementSelectInput` init through the view, and a plain
     * fragment response would drop it (the user picker arrives dead —
     * caught by the gate-9 browser smoke). The JS side appends the
     * head/body deltas after mounting the slideout so the element
     * select wires up.
     *
     * @throws \craft\errors\MissingComponentException if the view component is unavailable.
     * @throws \yii\base\InvalidConfigException        from `Herald::getInstance()`.
     * @throws \yii\web\ForbiddenHttpException         from `requireAdmin`.
     * @throws \Throwable                              from template rendering.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function actionTokenIssueSlideout(): Response
    {
        $this->_requirePro();

        $this->requireAdmin(false);

        $view = Craft::$app->getView();
        $html = $view->renderTemplate('herald/_cp/_token-issue-slideout', [
            'settings' => Herald::getInstance()->getSettings(),
            'scopeOptions' => $this->_scopeOptions(),
        ]);

        return $this->asJson([
            'html' => $html,
            'headHtml' => $view->getHeadHtml(),
            'bodyHtml' => $view->getBodyHtml(),
        ]);
    }

    /**
     * Issue a new bearer token. JSON-only — wired to the Garnish.Slideout
     * issue form on the Tokens tab.
     *
     * On success returns `200 {token: <plaintext>, model: <row>}`. The
     * plaintext is surfaced exactly once here — the slideout swaps to a
     * copy-now-it-won't-be-shown-again view from this response. It is
     * never persisted to a session flash, never logged, never reloadable
     * (locked decision 4). The CP issue path is NOT an MCP tool call, so
     * it never reaches the audit pipeline — the plaintext touches the
     * controller response and nothing else.
     *
     * Body shape:
     *   - `userId`     (required int)    — Craft user the token authenticates as.
     *   - `name`       (optional string) — defaults to `cli-{timestamp}`.
     *   - `ttlSeconds` (optional int)    — falls back to `Settings::$tokenTtlDefault`.
     *
     * Validation failure → `400 {message}` via `asFailure`; service-layer
     * save failure → the exception is caught + logged with a generic
     * `Could not issue token.` returned to the caller.
     *
     * @throws \yii\base\InvalidConfigException  from `Herald::getInstance()`.
     * @throws \yii\web\BadRequestHttpException  from `requirePostRequest` / `requireAcceptsJson`.
     * @throws \yii\web\ForbiddenHttpException   from `requireAdmin`.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function actionIssueToken(): ?Response
    {
        $this->_requirePro();

        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requireAdmin(requireAdminChanges: true);

        $request = $this->request;

        // Craft's `elementSelectField` macro posts the selection as an
        // array of element ids (`userId[]`); a plain numeric field posts
        // a scalar. Accept both — take the first id from an array.
        $userIdParam = $request->getBodyParam('userId');
        if (is_array($userIdParam)) {
            $userIdParam = reset($userIdParam);
        }
        $userId = (int) $userIdParam;
        if ($userId <= 0) {
            return $this->asFailure(Craft::t('herald', 'A user is required.'));
        }

        $user = User::find()->id($userId)->status(null)->one();
        if (!$user instanceof User) {
            return $this->asFailure(Craft::t('herald', 'A user is required.'));
        }

        $name = $request->getBodyParam('name');
        $name = is_string($name) && trim($name) !== ''
            ? trim($name)
            : 'cli-' . DateTimeHelper::now()->getTimestamp();

        $ttl = $request->getBodyParam('ttlSeconds');
        $ttlSeconds = is_numeric($ttl) && (int) $ttl > 0 ? (int) $ttl : null;

        // Scopes bound the credential's capability and are refused at the
        // transport when empty, so an unscoped token is a dead token.
        // Reject here with something the operator can act on rather than
        // minting one and letting them discover it as a 403.
        $postedScopes = $request->getBodyParam('scopes');
        $scopes = Herald::getInstance()->scopes->filterKnown(is_array($postedScopes) ? $postedScopes : []);
        if ($scopes === []) {
            return $this->asFailure(Craft::t('herald', 'Select at least one scope for the token.'));
        }

        try {
            $issued = Herald::getInstance()->tokens->issue($userId, $name, $ttlSeconds, $scopes);
        } catch (Exception $e) {
            Craft::error($e->getMessage(), 'herald');
            return $this->asFailure(Craft::t('herald', 'Could not issue token.'));
        }

        return $this->asSuccess(
            message: Craft::t('herald', 'Token issued.'),
            data: [
                'token' => $issued['token'],
                'model' => $this->_rows()->serializeToken($issued['model']),
            ],
        );
    }

    /**
     * Soft-delete (revoke) a bearer token by id. JSON-only — wired to
     * `Craft.VueAdminTable`'s `deleteAction` callback.
     *
     * Returns `200 {message}` on a matched revoke, `404 {message}` when
     * no row matches the id, and `400 {message}` on service-layer
     * failure. After revocation a subsequent `Tokens::lookup()` of the
     * matching plaintext fails (the row's `dateDeleted` is non-null).
     *
     * @throws \yii\base\InvalidConfigException  from `Herald::getInstance()`.
     * @throws \yii\web\BadRequestHttpException  from `requirePostRequest` / `requireAcceptsJson` / `getRequiredBodyParam`.
     * @throws \yii\web\ForbiddenHttpException   from `requireAdmin`.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function actionRevokeToken(): ?Response
    {
        $this->_requirePro();

        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requireAdmin(requireAdminChanges: true);

        $id = (int) $this->request->getRequiredBodyParam('id');

        try {
            $revoked = Herald::getInstance()->tokens->revoke($id);
        } catch (Exception $e) {
            Craft::error($e->getMessage(), 'herald');
            return $this->asFailure(Craft::t('herald', 'Could not revoke token.'));
        }

        if (!$revoked) {
            $this->response->setStatusCode(404);
            return $this->asJson(['message' => Craft::t('herald', 'Token not found.')]);
        }

        return $this->asSuccess(Craft::t('herald', 'Token revoked.'));
    }

    /**
     * Allowlist tab — VueAdminTable listing of admin-issued runtime
     * allowlist overrides plus the "+ New override" Garnish.Slideout
     * trigger. The DB-layer surface (`Allowlist` service,
     * `actionAddOverride`, `actionRemoveOverride`) exists from pre-Gate-9
     * work; 9.2 swaps the calling UI without rewiring the data layer.
     *
     * The settings model is passed in for the slideout's TTL placeholder
     * (`settings.runtimeOverrideTtl`).
     *
     * @throws \craft\errors\MissingComponentException if the view component is unavailable.
     * @throws \yii\base\InvalidConfigException        from `Herald::getInstance()`.
     * @throws \yii\web\ForbiddenHttpException         from `requireAdmin`.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function actionAllowlist(): Response
    {
        $this->requirePermission(self::PERMISSION_MANAGE_GRANTS);

        $allowlist = Herald::getInstance()->allowlist;

        // Index active grants by pattern so the effective-allowlist panel
        // can annotate each grant row with its expiry. Base Settings
        // patterns carry no expiry; an active grant whose pattern also
        // appears in Settings is still shown as a grant (expiry wins) since
        // the union dedupes by pattern string.
        $grantExpiryByPattern = [];
        foreach ($allowlist->getActiveOverrides() as $override) {
            $pattern = (string) ($override['pattern'] ?? '');
            $expiresAt = $override['expiresAt'] ?? null;
            if ($pattern !== '' && is_string($expiresAt) && $expiresAt !== '') {
                $grantExpiryByPattern[$pattern] = DateTimeHelper::toDateTime($expiresAt) ?: null;
            }
        }

        // Flag which effective patterns are admin-level so the panel can
        // label them distinctly (their presence in the effective set
        // depends on `allowAdminChanges`). The configured admin-level set
        // is the authority here — a tightened `adminLevelCommands` only
        // contributes its own patterns, but each is still admin-level.
        $adminLevelPatterns = array_fill_keys(
            Herald::getInstance()->getSettings()->adminLevelCommands,
            true,
        );

        return $this->renderTemplate('herald/_cp/allowlist', [
            'settings' => Herald::getInstance()->getSettings(),
            'effective' => $allowlist->getEffective(),
            'grantExpiryByPattern' => $grantExpiryByPattern,
            'adminLevelPatterns' => $adminLevelPatterns,
            'allowAdminChanges' => Craft::$app->getConfig()->getGeneral()->allowAdminChanges,
        ]);
    }

    /**
     * VueAdminTable data endpoint for the Allowlist tab. Returns the
     * `{pagination, data}` shape Craft's Vue component consumes — see
     * `vendor/craftcms/cms/src/helpers/AdminTable.php::paginationLinks`
     * for the canonical pagination dict.
     *
     * Honours the standard VueAdminTable params:
     *   - `page` (int, default 1)
     *   - `per_page` (int, default 50, capped at 100)
     *   - `search` (string, optional — substring match across `pattern` + `note`)
     *   - `sort.0.field` / `sort.0.direction` (optional)
     *
     * Pagination, search, and sort are applied in-PHP — override row
     * count is bounded by admin issuance and expires automatically, so
     * the cost is constant. If usage scales past dozens of overrides we
     * follow up with `Allowlist::getPaginated()` per the sub-gate-9.2
     * risk note.
     *
     * The row shape is locked: `[id, pattern, note, expiresAt, createdBy, dateCreated, isExpired]`.
     * `tests/Controllers/SettingsControllerAllowlistTest.php` asserts
     * the tuple exactly — drift = test failure.
     *
     * View access only (`requireAdmin(false)`); the row trash icon
     * routes through `actionRemoveOverride` which gates writes via
     * `requireAdmin(requireAdminChanges: true)`.
     *
     * @throws \craft\errors\MissingComponentException     if the view component is unavailable.
     * @throws \yii\base\InvalidConfigException            from `Herald::getInstance()`.
     * @throws \yii\web\BadRequestHttpException            from `requireAcceptsJson` on non-JSON callers.
     * @throws \yii\web\ForbiddenHttpException             from `requireAdmin`.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function actionAllowlistTableData(): Response
    {
        $this->requireAcceptsJson();
        $this->requirePermission(self::PERMISSION_MANAGE_GRANTS);

        $params = $this->_tableParams();

        // Raw rows — already filtered to non-deleted, includes expired
        // so operators can audit/cull expired entries until gc reaps them.
        $rows = Herald::getInstance()->allowlist->getAllOverrides(includeExpired: true);

        // Active / history split. The Temporary grants screen renders active
        // grants in the main table and expired grants in a collapsed history
        // section, each hitting this endpoint with `status=active|expired`.
        // Default `all` keeps the legacy single-table contract.
        $status = (string) $this->request->getParam('status', 'all');
        if ($status === 'active' || $status === 'expired') {
            $now = DateTimeHelper::now();
            $rows = array_values(array_filter($rows, static function(array $row) use ($status, $now): bool {
                $expiresAt = $row['expiresAt'] ?? null;
                $isExpired = false;
                if (is_string($expiresAt) && $expiresAt !== '') {
                    $exp = DateTimeHelper::toDateTime($expiresAt);
                    $isExpired = $exp !== false && $exp < $now;
                }
                return $status === 'expired' ? $isExpired : !$isExpired;
            }));
        }

        $rows = TableData::search($rows, ['pattern', 'note'], $params->search);

        // The service already orders by `expiresAt ASC`; the sort below
        // overrides that when the caller asks, and otherwise applies the
        // newest-first default.
        $rows = TableData::sort($rows, $params, [
            'pattern' => 'pattern',
            'expiresAt' => 'expiresAt',
            'dateCreated' => 'dateCreated',
        ], 'dateCreated');

        return $this->asJson(TableData::page(
            $rows,
            $params,
            fn(array $row): array => $this->_rows()->serializeOverride($row),
        ));
    }

    /**
     * Render the "+ New override" slideout body partial. Fetched by
     * `Herald.openAllowlistOverrideSlideout()` and passed into
     * `new Craft.Slideout(html, {...})`.
     *
     * Returns a bare HTML fragment — no `<html>`/`<body>` chrome, no
     * tab strip — the slideout container supplies that.
     *
     * @throws \craft\errors\MissingComponentException if the view component is unavailable.
     * @throws \yii\base\InvalidConfigException        from `Herald::getInstance()`.
     * @throws \yii\web\ForbiddenHttpException         from `requireAdmin`.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function actionAllowlistOverrideSlideout(): Response
    {
        $this->requirePermission(self::PERMISSION_MANAGE_GRANTS);

        return $this->renderTemplate('herald/_cp/_allowlist-override-slideout', [
            'settings' => Herald::getInstance()->getSettings(),
            'commandGroups' => Herald::getInstance()->allowlist->getCommandGroups(),
        ]);
    }

    /**
     * Activity tab — VueAdminTable of the HTTP-transport invocation audit
     * log plus a filter bar (kind / tool / user / date-range) and a
     * row-click Garnish.Slideout showing the already-redacted detail.
     *
     * Permission-gated by `Herald::PERMISSION_VIEW_ACTIVITY` per locked
     * decision 7 — admins and granted non-admins both reach the tab.
     * The view passes the filter-option lists: the five `kind` enum
     * values from `InvocationLogger`, and the distinct tool names from
     * the DB. The `userId` filter dropdown is rendered only for admins
     * (non-admins are server-side scoped to their own rows, so a user
     * filter would be meaningless and is omitted client-side).
     *
     * @throws \craft\errors\MissingComponentException if the view component is unavailable.
     * @throws \yii\base\InvalidConfigException        from `Herald::getInstance()`.
     * @throws \yii\web\ForbiddenHttpException         from `requirePermission`.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function actionActivity(): Response
    {
        $this->_requirePro();

        $this->requirePermission(Herald::PERMISSION_VIEW_ACTIVITY);

        $identity = Craft::$app->getUser()->getIdentity();
        $isAdmin = $identity instanceof User && $identity->admin;

        return $this->renderTemplate('herald/_cp/activity', [
            'isAdmin' => $isAdmin,
            'kinds' => [
                InvocationLogger::KIND_SUCCESS,
                InvocationLogger::KIND_TOOL_ERROR,
                InvocationLogger::KIND_INTERNAL_ERROR,
                InvocationLogger::KIND_RATE_LIMITED,
                InvocationLogger::KIND_CANCELLED,
            ],
            'toolNames' => Herald::getInstance()->invocations->find()->distinctToolNames(),
        ]);
    }

    /**
     * VueAdminTable data endpoint for the Activity tab. Returns the
     * `{pagination, data}` shape Craft's Vue component consumes — see
     * `vendor/craftcms/cms/src/helpers/AdminTable.php::paginationLinks`
     * for the canonical pagination dict.
     *
     * Honours the standard VueAdminTable params (`page`, `per_page`,
     * `search`, `sort.0.field`, `sort.0.direction`) plus the Activity
     * filters: `filters[kind]`, `filters[toolName]`, `filters[userId]`,
     * `filters[from]`, `filters[to]` (a `dateCreated` bracket).
     *
     * **Permission scoping — fail closed.** An admin sees every row; a
     * non-admin (granted `herald:view-activity` but not admin) sees ONLY
     * rows whose `userId` equals their own. The scope is applied
     * unconditionally on the query before any caller-supplied filter, so
     * a non-admin cannot widen it by posting `filters[userId]=<other>` —
     * the forced clause still narrows the result to the caller's own
     * rows. All user input flows through `Db::parseParam` /
     * `Db::parseDateParam` — no raw interpolation.
     *
     * The row shape is locked:
     * `[id, tool, mode, kind, user, durationMs, dateCreated]`.
     * `tests/Controllers/SettingsControllerActivityTest.php` asserts the
     * tuple exactly — drift = test failure.
     *
     * @throws \craft\errors\MissingComponentException if the view component is unavailable.
     * @throws \yii\base\InvalidConfigException        from `Herald::getInstance()`.
     * @throws \yii\web\BadRequestHttpException        from `requireAcceptsJson` on non-JSON callers.
     * @throws \yii\web\ForbiddenHttpException         from `requirePermission`.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function actionActivityTableData(): Response
    {
        $this->_requirePro();

        $this->requireAcceptsJson();
        $this->requirePermission(Herald::PERMISSION_VIEW_ACTIVITY);

        $params = $this->_tableParams();
        $search = $params->search;

        $filters = $this->request->getParam('filters', []);
        if (!is_array($filters)) {
            $filters = [];
        }

        $query = Herald::getInstance()->invocations->find();

        // Fail-closed scope FIRST — a non-admin is pinned to their own
        // userId before any caller filter is applied. `filters[userId]`
        // from a non-admin is ignored: the forced `andWhere` cannot be
        // widened by a second `andWhere` (both must hold).
        $this->_scopeActivityQueryToUser($query);

        $kind = $this->_filterValue($filters, 'kind');
        if ($kind !== null) {
            $query->kind($kind);
        }

        $toolName = $this->_filterValue($filters, 'toolName');
        if ($toolName !== null) {
            $query->toolName($toolName);
        }

        // Admin-only user filter — a non-admin is already pinned above, so
        // skip applying their (ignored) value entirely.
        if ($this->_callerIsAdmin()) {
            $userId = $this->_filterValue($filters, 'userId');
            if ($userId !== null && ctype_digit((string) $userId)) {
                $query->userId((int) $userId);
            }
        }

        $from = $this->_filterValue($filters, 'from');
        if ($from !== null) {
            $fromClause = Db::parseDateParam('dateCreated', $from, '>=');
            if ($fromClause !== null) {
                $query->andWhere($fromClause);
            }
        }

        $to = $this->_filterValue($filters, 'to');
        if ($to !== null) {
            $toClause = Db::parseDateParam('dateCreated', $to, '<=');
            if ($toClause !== null) {
                $query->andWhere($toClause);
            }
        }

        if ($search !== '') {
            $query->andWhere([
                'or',
                Db::parseParam('toolName', '*' . $search . '*'),
                Db::parseParam('clientName', '*' . $search . '*'),
                Db::parseParam('errorMessage', '*' . $search . '*'),
            ]);
        }

        // Unlike the other three tables this one pages in SQL — the audit
        // log is append-only with no upper bound, so the full set is never
        // materialised.
        $orderColumn = match ($params->sortField) {
            'tool' => 'toolName',
            'kind' => 'kind',
            'durationMs' => 'durationMs',
            'dateCreated' => 'dateCreated',
            default => 'dateCreated',
        };

        $total = (int) (clone $query)->count();

        $rows = $query
            ->orderBy([$orderColumn => $params->sortDir(SORT_DESC)])
            ->offset(($params->page - 1) * $params->perPage)
            ->limit($params->perPage)
            ->all();

        return $this->asJson(TableData::envelope(
            array_map(
                fn(array $row): array => $this->_rows()->serializeActivity($row),
                $rows,
            ),
            $total,
            $params,
        ));
    }

    /**
     * Detail endpoint for a single invocation — feeds the row-click
     * Garnish.Slideout on the Activity tab. Returns the slideout HTML
     * (rendered server-side from `_activity-detail-slideout`) wrapped in
     * `asJson(['html' => ...])`.
     *
     * The payload is the ALREADY-REDACTED `argsRedacted` +
     * `responseExcerpt` columns plus error/cancellation context and
     * metadata (transport, session, client, duration, rate-limit
     * remaining). Per locked decision 11 there is no pre-redaction
     * surface — that data was never persisted.
     *
     * **Permission scoping — fail closed.** A non-admin requesting a row
     * that is not theirs gets a 404 (NOT 403 — a 403 would confirm the
     * row exists and leak enumeration signal). Missing ids also 404.
     *
     * @throws \craft\errors\MissingComponentException if the view component is unavailable.
     * @throws \yii\base\InvalidConfigException        from `Herald::getInstance()`.
     * @throws \yii\web\BadRequestHttpException        from `requireAcceptsJson` on non-JSON callers.
     * @throws \yii\web\ForbiddenHttpException         from `requirePermission`.
     * @throws NotFoundHttpException                   when the row is missing or out of the caller's scope.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function actionActivityRow(): Response
    {
        $this->_requirePro();

        $this->requireAcceptsJson();
        $this->requirePermission(Herald::PERMISSION_VIEW_ACTIVITY);

        $id = (int) $this->request->getParam('id');
        if ($id <= 0) {
            throw new NotFoundHttpException('Invocation not found.');
        }

        $query = Herald::getInstance()->invocations->find()->andWhere(['id' => $id]);
        $this->_scopeActivityQueryToUser($query);

        $row = $query->one();
        if (!is_array($row)) {
            // 404, never 403 — a foreign-row request is indistinguishable
            // from a missing-row request, which kills the enumeration
            // oracle a non-admin could otherwise probe with.
            throw new NotFoundHttpException('Invocation not found.');
        }

        // Decode + pretty-print the redacted columns PHP-side. The
        // `responseExcerpt` column is clipped to a fixed length by the
        // logger, so the stored string is frequently NOT valid JSON —
        // Twig's `|json_decode` (`Json::decode`) throws on it instead of
        // falling through (a truncated excerpt 500'd the slideout; caught
        // by the gate-9 browser smoke).
        $rows = $this->_rows();
        $html = $this->getView()->renderTemplate('herald/_cp/_activity-detail-slideout', [
            'row' => $row,
            'user' => $rows->resolveUser($row['userId'] ?? null),
            'mode' => $rows->extractMode($row['argsRedacted'] ?? null),
            'argsPretty' => $rows->prettyJson($row['argsRedacted'] ?? null),
            'responsePretty' => $rows->prettyJson($row['responseExcerpt'] ?? null),
        ]);

        return $this->asJson(['html' => $html]);
    }

    /**
     * Clients tab — VueAdminTable of registered OAuth clients plus
     * inline approve / revoke actions. The DCR approval gate (WS3)
     * lands every self-registered client UNAPPROVED; an admin approves
     * it here before it can connect.
     *
     * Admin-only + Pro per the locked CP posture — `_requirePro()`
     * first, then `requireAdmin(false)` for view access. The
     * approve / revoke mutations strictly gate writes via
     * `requireAdmin(requireAdminChanges: true)`.
     *
     * @throws \craft\errors\MissingComponentException if the view component is unavailable.
     * @throws \yii\base\InvalidConfigException        from `Herald::getInstance()`.
     * @throws \yii\web\ForbiddenHttpException         from `requireAdmin`.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function actionClients(): Response
    {
        $this->_requirePro();

        $this->requireAdmin(false);

        return $this->renderTemplate('herald/_cp/clients', [
            'settings' => Herald::getInstance()->getSettings(),
        ]);
    }

    /**
     * VueAdminTable data endpoint for the Clients tab. Returns the
     * `{pagination, data}` shape Craft's Vue component consumes.
     * Mirrors `actionTokensTableData` — in-PHP pagination / search /
     * sort, bounded by the (low) number of registered clients.
     *
     * The row shape is locked:
     * `[id, clientName, clientId, type, approved, redirectUris, dateCreated]`.
     * `tests/Controllers/SettingsControllerClientsTest.php` asserts the
     * tuple exactly — drift = test failure.
     *
     * @throws \craft\errors\MissingComponentException if the view component is unavailable.
     * @throws \yii\base\InvalidConfigException        from `Herald::getInstance()`.
     * @throws \yii\web\BadRequestHttpException        from `requireAcceptsJson`.
     * @throws \yii\web\ForbiddenHttpException         from `requireAdmin`.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function actionClientsTableData(): Response
    {
        $this->_requirePro();

        $this->requireAcceptsJson();
        $this->requireAdmin(false);

        $params = $this->_tableParams();

        $rows = array_map(
            fn(OauthClientRecord $client): array => $this->_rows()->serializeClient($client),
            Herald::getInstance()->oauth->getAllClients(),
        );

        // No sort: the service already returns clients in a stable order
        // and the Clients table ships no sortable columns.
        $rows = TableData::search($rows, ['clientName', 'clientId'], $params->search);

        return $this->asJson(TableData::page($rows, $params));
    }

    /**
     * Approve a registered OAuth client by id. JSON-only — wired to the
     * Clients tab's row action. Returns `200 {message}` on success,
     * `404 {message}` when the id is unknown.
     *
     * @throws \yii\base\InvalidConfigException  from `Herald::getInstance()`.
     * @throws \yii\web\BadRequestHttpException  from `requirePostRequest` / `requireAcceptsJson` / `getRequiredBodyParam`.
     * @throws \yii\web\ForbiddenHttpException   from `requireAdmin`.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function actionApproveClient(): ?Response
    {
        $this->_requirePro();

        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requireAdmin(requireAdminChanges: true);

        $id = (int) $this->request->getRequiredBodyParam('id');
        $approved = Herald::getInstance()->oauth->approveClient($id);

        if (!$approved) {
            $this->response->setStatusCode(404);
            return $this->asJson(['message' => Craft::t('herald', 'Client not found.')]);
        }

        return $this->asSuccess(Craft::t('herald', 'Client approved.'));
    }

    /**
     * Revoke a registered OAuth client by id: flip it to unapproved and
     * revoke every live token it issued. JSON-only — wired to the
     * Clients tab's row trash action. Returns `200 {message}` on
     * success, `404 {message}` when the id is unknown.
     *
     * @throws \yii\base\InvalidConfigException  from `Herald::getInstance()`.
     * @throws \yii\web\BadRequestHttpException  from `requirePostRequest` / `requireAcceptsJson` / `getRequiredBodyParam`.
     * @throws \yii\web\ForbiddenHttpException   from `requireAdmin`.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function actionRevokeClient(): ?Response
    {
        $this->_requirePro();

        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requireAdmin(requireAdminChanges: true);

        $id = (int) $this->request->getRequiredBodyParam('id');
        $revoked = Herald::getInstance()->oauth->revokeClient($id);

        if (!$revoked) {
            $this->response->setStatusCode(404);
            return $this->asJson(['message' => Craft::t('herald', 'Client not found.')]);
        }

        return $this->asSuccess(Craft::t('herald', 'Client revoked.'));
    }

    /**
     * Connection tab — placeholder for 9.1. Real endpoint-URL display
     * + per-client config snippets land in 9.4.
     *
     * @throws \craft\errors\MissingComponentException if the view component is unavailable.
     * @throws \yii\web\ForbiddenHttpException         from `requireAdmin`.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function actionConnection(): Response
    {
        $this->_requirePro();

        $this->requireAdmin(false);

        return $this->renderTemplate('herald/_cp/connection');
    }

    /**
     * Save action for the Settings tab. Replaces Craft's built-in
     * `plugins/save-plugin-settings` route for Herald per Gate 9
     * locked decision 1.
     *
     * Body-param shape mirrors Craft's standard plugin-settings save —
     * `settings[xxx]` under a single `settings` map.
     * `setAttributes($posted, false)` runs without scenario filtering
     * so all settings fields are assignable; `savePluginSettings`
     * persists through project config.
     *
     * On validation failure, sets the fail flash and falls through
     * to a redirect — the Twig form retains posted values via the
     * `$settings` instance held on the plugin.
     *
     * Gated by `PERMISSION_MANAGE_SETTINGS` (WHO), then an explicit
     * `allowAdminChanges` fail-closed check (WHETHER the write is allowed
     * in this environment) — the two axes stay separate per the estate
     * settings-permission doctrine.
     *
     * @throws \yii\base\Exception                  on settings-save failure.
     * @throws \yii\base\InvalidConfigException     from `Herald::getInstance()`.
     * @throws \yii\web\BadRequestHttpException     from `requirePostRequest` on non-POST.
     * @throws \yii\web\ForbiddenHttpException      from `requirePermission` or the read-only environment guard.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(self::PERMISSION_MANAGE_SETTINGS);

        // Write axis: settings map to project config, so a save is an
        // administrative change. Fail closed when the environment forbids
        // them, independent of who holds the manage-settings permission.
        if (!Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            throw new ForbiddenHttpException('Administrative changes are disallowed in this environment.');
        }

        $plugin = Herald::getInstance();
        $settings = $plugin->getSettings();

        $posted = $this->request->getBodyParam('settings', []);
        if (!is_array($posted)) {
            $posted = [];
        }

        // `allowedCommands` (content-level) and `adminLevelCommands`
        // (admin-level) are each edited through the grouped toggle
        // browser's two sections, not raw editable tables. Each section
        // posts three sibling params:
        //   - `herald[Admin]CommandGroups[<handle>] = '1'` for a fully-toggled group.
        //   - `herald[Admin]CommandActions[<routeId>] = '1'` for an individually-checked action.
        //   - `settings[<setting>][N][pattern]` from the "Custom patterns"
        //     editable table — power-user globs preserved verbatim.
        // Fold each section back into the flat `string[]` its setting
        // persists, with the bucket boundary enforced in the service so a
        // content toggle can never surface an admin route (and vice versa).
        // Skip a setting entirely when it is locked by `config/herald.php`
        // — the file value wins regardless, and that section rendered
        // read-only, so there is nothing meaningful to fold.
        if (!$this->isSettingOverridden('allowedCommands')) {
            $posted['allowedCommands'] = $plugin->allowlist->patternsFromToggleState(
                fullGroups: $this->_postedToggleMap('heraldCommandGroups'),
                actionIds: $this->_postedToggleMap('heraldCommandActions'),
                customPatterns: $this->_postedCustomPatterns('allowedCommands'),
                adminLevel: false,
            );
        } else {
            unset($posted['allowedCommands']);
        }

        if (!$this->isSettingOverridden('adminLevelCommands')) {
            $posted['adminLevelCommands'] = $plugin->allowlist->patternsFromToggleState(
                fullGroups: $this->_postedToggleMap('heraldAdminCommandGroups'),
                actionIds: $this->_postedToggleMap('heraldAdminCommandActions'),
                customPatterns: $this->_postedCustomPatterns('adminLevelCommands'),
                adminLevel: true,
            );
        } else {
            unset($posted['adminLevelCommands']);
        }

        $settings->setAttributes($posted, false);

        if (!Craft::$app->getPlugins()->savePluginSettings($plugin, $settings->toArray())) {
            Craft::$app->getSession()->setError(
                Craft::t('herald', "Couldn't save settings."),
            );
            return $this->redirectToPostedUrl();
        }

        Craft::$app->getSession()->setNotice(
            Craft::t('herald', 'Settings saved.'),
        );

        return $this->redirectToPostedUrl();
    }

    /**
     * Add a runtime allowlist override. JSON-only callers post-Gate-9 —
     * 9.2 stripped the legacy HTML form path along with its template.
     * The VueAdminTable + Garnish.Slideout combo on the Allowlist tab
     * is the only consumer.
     *
     * Returns `200 {model: <serialized-row>}` on success, `400 {message}`
     * on validation failure, and lets the underlying exception bubble
     * (caught + logged) with a generic `Could not add override.` on
     * service-layer save failure.
     *
     * @throws \yii\base\InvalidConfigException  from `Herald::getInstance()`.
     * @throws \yii\web\BadRequestHttpException  from `requirePostRequest` / `requireAcceptsJson` / `getRequiredBodyParam`.
     * @throws \yii\web\ForbiddenHttpException   from `requireAdmin`.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function actionAddOverride(): ?Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission(self::PERMISSION_MANAGE_GRANTS);

        $request = $this->request;

        // Command scope: one or more fnmatch patterns. The guided slideout
        // posts `patterns[]` from the grouped command picker; the legacy
        // single-field path posts `pattern`. Normalise to a de-duped list.
        $patterns = $this->_postedGrantPatterns();
        if ($patterns === []) {
            return $this->asFailure(Craft::t('herald', 'At least one command pattern is required.'));
        }

        // Subject: the user the grant is for. Optional — null issues a
        // global grant (applies to every caller). When present it MUST
        // resolve to a real user; a crafted id fails closed.
        $subjectUserId = null;
        $subjectParam = $request->getBodyParam('subjectUserId');
        if (is_array($subjectParam)) {
            $subjectParam = reset($subjectParam);
        }
        if ($subjectParam !== null && $subjectParam !== '') {
            $candidate = (int) $subjectParam;
            if ($candidate <= 0 || User::find()->id($candidate)->status(null)->one() === null) {
                return $this->asFailure(Craft::t('herald', 'The selected user could not be found.'));
            }
            $subjectUserId = $candidate;
        }

        $note = $request->getBodyParam('note');
        $note = is_string($note) && $note !== '' ? trim($note) : null;
        $ttlSeconds = $this->_resolveGrantTtl();

        $grantorId = Craft::$app->getUser()->getId();
        $grantorId = is_int($grantorId) ? $grantorId : null;

        $created = [];
        try {
            foreach ($patterns as $pattern) {
                $override = Herald::getInstance()->allowlist->add(
                    pattern: $pattern,
                    userId: $grantorId,
                    note: $note,
                    ttlSeconds: $ttlSeconds,
                    subjectUserId: $subjectUserId,
                );
                $created[] = $this->_rows()->serializeOverride($override->toArray());
                $this->_auditGrant('issue', (int) $override->id, $pattern, $subjectUserId, $grantorId);
            }
        } catch (Exception $e) {
            Craft::error($e->getMessage(), 'herald');
            return $this->asFailure(Craft::t('herald', 'Could not add grant.'));
        }

        return $this->asSuccess(
            message: Craft::t('herald', 'Grant issued.'),
            data: [
                'model' => $created[0] ?? null,
                'models' => $created,
            ],
        );
    }

    /**
     * Soft-delete a runtime allowlist override by id. JSON-only — wired
     * to `Craft.VueAdminTable`'s `deleteAction` callback.
     *
     * Returns `200 {message}` on a matched delete, `404 {message}` when
     * no row matches the id (the table refreshes after every mutation
     * so a stale row click is rare, but defense in depth), and
     * `400 {message}` on service-layer failure.
     *
     * @throws \yii\base\InvalidConfigException  from `Herald::getInstance()`.
     * @throws \yii\web\BadRequestHttpException  from `requirePostRequest` / `requireAcceptsJson` / `getRequiredBodyParam`.
     * @throws \yii\web\ForbiddenHttpException   from `requireAdmin`.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function actionRemoveOverride(): ?Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission(self::PERMISSION_MANAGE_GRANTS);

        $id = (int) $this->request->getRequiredBodyParam('id');

        try {
            $removed = Herald::getInstance()->allowlist->remove($id);
        } catch (Exception $e) {
            Craft::error($e->getMessage(), 'herald');
            return $this->asFailure(Craft::t('herald', 'Could not remove override.'));
        }

        if (!$removed) {
            $this->response->setStatusCode(404);
            return $this->asJson(['message' => Craft::t('herald', 'Override not found.')]);
        }

        $actorId = Craft::$app->getUser()->getId();
        $this->_auditGrant('revoke', $id, null, null, is_int($actorId) ? $actorId : null);

        return $this->asSuccess(Craft::t('herald', 'Grant revoked.'));
    }

    // Protected Methods
    // =========================================================================

    /**
     * Whether a plugin-settings attribute is overridden from
     * `config/herald.php`. Craft merges that file's keys over the
     * project-config-stored settings when loading the plugin (see
     * `craft\services\Plugins::createPlugin()` →
     * `getConfig()->getConfigFromFile($handle)`), but exposes no
     * per-attribute "is overridden" flag the way `GeneralConfig` does.
     * Reading the config file back is the canonical detection: a key
     * present there is locked, so the CP renders that control read-only
     * with Craft's standard "defined in config" warning.
     *
     * Protected so the save-path test harness can stub the overridden
     * branch without writing a real `config/herald.php` mid-suite.
     *
     * @throws \yii\base\InvalidConfigException from `Craft::$app->getConfig()`.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    protected function isSettingOverridden(string $attribute): bool
    {
        $fileConfig = Craft::$app->getConfig()->getConfigFromFile('herald');

        return is_array($fileConfig) && array_key_exists($attribute, $fileConfig);
    }

    // Private Methods
    // =========================================================================

    /**
     * Read a posted toggle map (`heraldCommandGroups` /
     * `heraldCommandActions`) and keep only the keys whose lightswitch is
     * on. Craft's lightswitch macro posts a hidden input for every switch
     * — `'1'` when on, an empty string when off — so a raw read would
     * report every group/action as present. Filtering to truthy values
     * leaves only the enabled keys, which is what
     * `Allowlist::patternsFromToggleState()` expects. Non-array payloads
     * (none posted) normalise to an empty map.
     *
     * @return array<string,mixed>
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _postedToggleMap(string $param): array
    {
        $value = $this->request->getBodyParam($param, []);
        if (!is_array($value)) {
            return [];
        }

        return array_filter(
            $value,
            static fn($v): bool => $v === '1' || $v === 1 || $v === true,
        );
    }

    /**
     * Read a "Custom patterns" editable-table payload posted under
     * `settings[<setting>]` and flatten it to trimmed, non-empty pattern
     * strings. The editable-table macro posts a 2D array
     * (`settings[<setting>][N][pattern]`); the toggle browser folds these
     * back in verbatim so power-user globs survive a round-trip. Used for
     * both buckets — `allowedCommands` (content) and `adminLevelCommands`
     * (admin-level).
     *
     * @return string[]
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _postedCustomPatterns(string $setting): array
    {
        $settings = $this->request->getBodyParam('settings', []);
        $rows = is_array($settings) ? ($settings[$setting] ?? []) : [];
        if (!is_array($rows)) {
            return [];
        }

        return array_values(array_filter(
            array_map(
                static fn($row) => is_array($row) ? trim((string) ($row['pattern'] ?? '')) : '',
                $rows,
            ),
            static fn(string $pattern) => $pattern !== '',
        ));
    }

    /**
     * Whether the current CP user is an admin. Centralised so the
     * Activity scoping logic reads the identity in exactly one place.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _callerIsAdmin(): bool
    {
        $identity = Craft::$app->getUser()->getIdentity();
        return $identity instanceof User && $identity->admin;
    }

    /**
     * Apply the fail-closed Activity-log scope to a query. Admins are
     * unscoped (they audit everyone); a non-admin is pinned to their own
     * `userId`. A non-admin with no resolvable identity is pinned to a
     * sentinel `userId` of 0 so the result is empty rather than
     * accidentally global — defense in depth, the permission gate already
     * rejected the anonymous case upstream.
     *
     * The pin is an `andWhere` so it composes with (and cannot be widened
     * by) any caller-supplied `filters[userId]`.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _scopeActivityQueryToUser(InvocationQuery $query): void
    {
        if ($this->_callerIsAdmin()) {
            return;
        }

        $identity = Craft::$app->getUser()->getIdentity();
        $ownId = $identity instanceof User ? (int) $identity->id : 0;
        $query->userId($ownId);
    }

    /**
     * Read a single Activity filter value from the `filters` map,
     * normalising the empty string + missing key to null so the query
     * builder treats them as "no filter requested".
     *
     * @param array<string,mixed> $filters
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _filterValue(array $filters, string $key): ?string
    {
        $value = $filters[$key] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        return (string) $value;
    }

    /**
     * Throw unless the install is Pro. Tokens / Activity / Connection
     * are Pro surfaces (PLANNING.md §4 — they exist to operate the
     * Pro-only HTTP transport); the registry's `shouldRegister()` gate
     * cannot help here because CP actions never pass through the tool
     * dispatcher. Called as the FIRST statement of every Pro action,
     * ahead of the permission gates.
     *
     * 403, not 404 — the fail-closed 404 on `actionActivityRow` guards
     * per-row enumeration; this is a tier boundary and should say so.
     *
     * @throws ForbiddenHttpException on Free installs.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _requirePro(): void
    {
        if (!Herald::getInstance()->is(Herald::EDITION_PRO, '>=')) {
            throw new ForbiddenHttpException('This feature requires the Herald Pro edition.');
        }
    }

    /**
     * Capability-scope options for the token-issuance slideout's checkbox
     * group, in the vocabulary's own display order.
     *
     * The label pairs the scope identifier with its plain-English
     * description because the identifier alone (`assets:write`) does not
     * tell an operator what it unlocks, and the description alone gives
     * them nothing to match against a client's requested scope string.
     * Descriptions come from `Scopes::describe()`, the same source the
     * OAuth consent screen renders, so the two surfaces cannot drift.
     *
     * @return array<int,array{label:string,value:string}>
     * @throws \yii\base\InvalidConfigException from `Herald::getInstance()`.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _scopeOptions(): array
    {
        $scopes = Herald::getInstance()->scopes;

        return array_map(
            static fn(string $scope): array => [
                'label' => sprintf('%s (%s)', $scope, $scopes->describe($scope)),
                'value' => $scope,
            ],
            $scopes->all(),
        );
    }

    /**
     * Resolve the grant TTL (seconds) from the posted duration control. A
     * raw `ttlSeconds` param wins (the API / legacy path and the controller
     * tests post it directly); otherwise the guided slideout's
     * `durationPreset` is read as a second-count, or `custom` plus
     * `customTtlSeconds`. Null falls through to the plugin default in
     * `Allowlist::add()`.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _resolveGrantTtl(): ?int
    {
        $request = $this->request;

        // A raw `ttlSeconds` wins when posted (the API / legacy path and the
        // controller tests use it directly).
        $direct = $request->getBodyParam('ttlSeconds');
        if (is_numeric($direct) && (int) $direct > 0) {
            return (int) $direct;
        }

        // Otherwise resolve the guided slideout's duration control: a preset
        // whose value is a second-count, or `custom` + `customTtlSeconds`.
        $preset = $request->getBodyParam('durationPreset');
        if ($preset === 'custom') {
            $custom = $request->getBodyParam('customTtlSeconds');
            return is_numeric($custom) && (int) $custom > 0 ? (int) $custom : null;
        }
        if (is_numeric($preset) && (int) $preset > 0) {
            return (int) $preset;
        }

        // Null falls through to the plugin default in `Allowlist::add()`.
        return null;
    }

    /**
     * Normalise the posted command scope into a de-duped, order-preserving
     * list of non-empty fnmatch patterns. Accepts either `patterns` (the
     * array the guided slideout's grouped command picker posts) or a single
     * `pattern` string (the legacy field).
     *
     * @return string[]
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _postedGrantPatterns(): array
    {
        $raw = $this->request->getBodyParam('patterns');
        if (!is_array($raw)) {
            $single = $this->request->getBodyParam('pattern');
            $raw = is_string($single) ? [$single] : [];
        }

        $patterns = [];
        foreach ($raw as $value) {
            if (!is_string($value)) {
                continue;
            }
            $trimmed = trim($value);
            if ($trimmed !== '' && !in_array($trimmed, $patterns, true)) {
                $patterns[] = $trimmed;
            }
        }

        return $patterns;
    }

    /**
     * Write a structured audit line for a grant issue / revoke. The MCP
     * invocation-audit pipeline covers tool calls, not CP admin actions,
     * so grant lifecycle events are logged here through Craft's logger
     * under the `herald` category (the same channel `Invocations` uses as
     * its secondary trail), capturing the actor, subject, and scope.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _auditGrant(string $action, int $grantId, ?string $pattern, ?int $subjectUserId, ?int $actorId): void
    {
        Craft::info(sprintf(
            'grant %s: id=%d pattern=%s subjectUserId=%s actorUserId=%s',
            $action,
            $grantId,
            $pattern ?? '-',
            $subjectUserId === null ? 'global' : (string) $subjectUserId,
            $actorId === null ? '-' : (string) $actorId,
        ), 'herald');
    }

    /**
     * The VueAdminTable view-model mapper. Every locked row tuple the CP
     * tables consume is built there, not here, so the controller stays an
     * HTTP boundary and each table shape is asserted in one place.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _rows(): RowSerializer
    {
        return $this->_rows ??= new RowSerializer();
    }

    /**
     * Read the standard `Craft.VueAdminTable` request params off this
     * request. The only place in the controller that touches them, so the
     * page floor and the per-page ceiling cannot drift between the four
     * data endpoints.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _tableParams(): TableParams
    {
        return TableParams::normalise(
            page: $this->request->getParam('page', 1),
            perPage: $this->request->getParam('per_page', TableParams::DEFAULT_PER_PAGE),
            search: $this->request->getParam('search', ''),
            sortField: $this->request->getParam('sort.0.field', ''),
            sortDirection: $this->request->getParam('sort.0.direction', ''),
        );
    }
}
