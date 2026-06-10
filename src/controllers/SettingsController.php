<?php

namespace craftpulse\cortex\controllers;

use Craft;
use craft\elements\User;
use craft\helpers\AdminTable;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\web\Controller;
use craftpulse\cortex\Cortex;
use craftpulse\cortex\db\InvocationQuery;
use craftpulse\cortex\models\Token;
use craftpulse\cortex\records\RuntimeOverride;
use craftpulse\cortex\tools\support\InvocationLogger;
use yii\base\Exception;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * =========================================================================
 * Web controller — Cortex CP screens + runtime allowlist override
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
 * Permission posture per locked decision 7:
 *   - Settings / Tokens / Connection view actions    — `requireAdmin(false)`.
 *   - Activity view + table-data + row actions        — `requirePermission(Cortex::PERMISSION_VIEW_ACTIVITY)`.
 *     Non-admins are scoped server-side to their own
 *     rows (fail-closed) — see `actionActivityTableData`.
 *   - All mutation actions (`actionSave` + the
 *     pre-existing `actionAddOverride` /
 *     `actionRemoveOverride`)                         — `requirePostRequest`
 *                                                       + `requireAdmin(requireAdminChanges: true)`.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */
class SettingsController extends Controller
{
    // Public Methods
    // =========================================================================

    /**
     * Settings tab — landing page for `settings/plugins/cortex`. Renders
     * the project-config-synced plugin defaults under the tabbed
     * lineage. Runtime allowlist overrides (DB-backed time-bound
     * grants) live on the Allowlist tab — see `actionAllowlist`.
     *
     * View accessible in read-only mode (`requireAdmin(false)`).
     * `actionSave` strictly gates write access.
     *
     * @throws \craft\errors\MissingComponentException if the view component is unavailable.
     * @throws \yii\base\InvalidConfigException        from `Cortex::getInstance()`.
     * @throws \yii\web\ForbiddenHttpException         from `requireAdmin`.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function actionIndex(): Response
    {
        $this->requireAdmin(false);

        $plugin = Cortex::getInstance();

        return $this->renderTemplate('cortex/_cp/settings', [
            'plugin' => $plugin,
            'settings' => $plugin->getSettings(),
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
     * @throws \yii\base\InvalidConfigException        from `Cortex::getInstance()`.
     * @throws \yii\web\ForbiddenHttpException         from `requireAdmin`.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function actionTokens(): Response
    {
        $this->requireAdmin(false);

        return $this->renderTemplate('cortex/_cp/tokens', [
            'settings' => Cortex::getInstance()->getSettings(),
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
     * @throws \yii\base\InvalidConfigException        from `Cortex::getInstance()`.
     * @throws \yii\web\BadRequestHttpException        from `requireAcceptsJson` on non-JSON callers.
     * @throws \yii\web\ForbiddenHttpException         from `requireAdmin`.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function actionTokensTableData(): Response
    {
        $this->requireAcceptsJson();
        $this->requireAdmin(false);

        $page = max(1, (int) $this->request->getParam('page', 1));
        $perPage = (int) $this->request->getParam('per_page', 50);
        $perPage = max(1, min($perPage, 100));
        $search = trim((string) $this->request->getParam('search', ''));
        $sortField = (string) $this->request->getParam('sort.0.field', '');
        $sortDir = $this->request->getParam('sort.0.direction') === 'desc' ? SORT_DESC : SORT_ASC;

        // `getAll()` returns non-deleted tokens, newest first. Map to the
        // serialised row tuple up front so search / sort operate on the
        // shape the table consumes.
        $rows = array_map(
            fn(Token $token): array => $this->_serializeTokenRow($token),
            Cortex::getInstance()->tokens->getAll(),
        );

        if ($search !== '') {
            $needle = mb_strtolower($search);
            $rows = array_values(array_filter(
                $rows,
                static function(array $row) use ($needle): bool {
                    $haystack = mb_strtolower((string) $row['name'] . ' ' . (string) $row['tokenPrefix']);
                    return str_contains($haystack, $needle);
                },
            ));
        }

        // Sort. Default ordering: dateCreated DESC (newest first) — most
        // operators want the row they just issued at the top.
        $orderColumn = match ($sortField) {
            'name' => 'name',
            'expiresAt' => 'expiresAt',
            'lastUsedAt' => 'lastUsedAt',
            'dateCreated' => 'dateCreated',
            default => 'dateCreated',
        };
        $defaultDir = $sortField === '' ? SORT_DESC : $sortDir;
        usort($rows, static function(array $a, array $b) use ($orderColumn, $defaultDir): int {
            $av = (string) ($a[$orderColumn] ?? '');
            $bv = (string) ($b[$orderColumn] ?? '');
            $cmp = strcmp($av, $bv);
            return $defaultDir === SORT_DESC ? -$cmp : $cmp;
        });

        $total = count($rows);
        $offset = ($page - 1) * $perPage;
        $slice = array_slice($rows, $offset, $perPage);

        return $this->asJson([
            'pagination' => AdminTable::paginationLinks($page, $total, $perPage),
            'data' => array_values($slice),
        ]);
    }

    /**
     * Render the "+ New token" slideout body partial. Fetched by
     * `Cortex.openTokenIssuanceSlideout()` and passed into
     * `new Craft.Slideout(html, {...})`. Returns a bare HTML fragment —
     * the slideout container supplies the `<form>` chrome.
     *
     * @throws \craft\errors\MissingComponentException if the view component is unavailable.
     * @throws \yii\base\InvalidConfigException        from `Cortex::getInstance()`.
     * @throws \yii\web\ForbiddenHttpException         from `requireAdmin`.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function actionTokenIssueSlideout(): Response
    {
        $this->requireAdmin(false);

        return $this->renderTemplate('cortex/_cp/_token-issue-slideout', [
            'settings' => Cortex::getInstance()->getSettings(),
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
     * @throws \yii\base\InvalidConfigException  from `Cortex::getInstance()`.
     * @throws \yii\web\BadRequestHttpException  from `requirePostRequest` / `requireAcceptsJson`.
     * @throws \yii\web\ForbiddenHttpException   from `requireAdmin`.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function actionIssueToken(): ?Response
    {
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
            return $this->asFailure(Craft::t('cortex', 'A user is required.'));
        }

        $user = User::find()->id($userId)->status(null)->one();
        if (!$user instanceof User) {
            return $this->asFailure(Craft::t('cortex', 'A user is required.'));
        }

        $name = $request->getBodyParam('name');
        $name = is_string($name) && trim($name) !== ''
            ? trim($name)
            : 'cli-' . DateTimeHelper::now()->getTimestamp();

        $ttl = $request->getBodyParam('ttlSeconds');
        $ttlSeconds = is_numeric($ttl) && (int) $ttl > 0 ? (int) $ttl : null;

        try {
            $issued = Cortex::getInstance()->tokens->issue($userId, $name, $ttlSeconds);
        } catch (Exception $e) {
            Craft::error($e->getMessage(), 'cortex');
            return $this->asFailure(Craft::t('cortex', 'Could not issue token.'));
        }

        return $this->asSuccess(
            message: Craft::t('cortex', 'Token issued.'),
            data: [
                'token' => $issued['token'],
                'model' => $this->_serializeTokenRow($issued['model']),
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
     * @throws \yii\base\InvalidConfigException  from `Cortex::getInstance()`.
     * @throws \yii\web\BadRequestHttpException  from `requirePostRequest` / `requireAcceptsJson` / `getRequiredBodyParam`.
     * @throws \yii\web\ForbiddenHttpException   from `requireAdmin`.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function actionRevokeToken(): ?Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requireAdmin(requireAdminChanges: true);

        $id = (int) $this->request->getRequiredBodyParam('id');

        try {
            $revoked = Cortex::getInstance()->tokens->revoke($id);
        } catch (Exception $e) {
            Craft::error($e->getMessage(), 'cortex');
            return $this->asFailure(Craft::t('cortex', 'Could not revoke token.'));
        }

        if (!$revoked) {
            $this->response->setStatusCode(404);
            return $this->asJson(['message' => Craft::t('cortex', 'Token not found.')]);
        }

        return $this->asSuccess(Craft::t('cortex', 'Token revoked.'));
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
     * @throws \yii\base\InvalidConfigException        from `Cortex::getInstance()`.
     * @throws \yii\web\ForbiddenHttpException         from `requireAdmin`.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function actionAllowlist(): Response
    {
        $this->requireAdmin(false);

        return $this->renderTemplate('cortex/_cp/allowlist', [
            'settings' => Cortex::getInstance()->getSettings(),
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
     * @throws \yii\base\InvalidConfigException            from `Cortex::getInstance()`.
     * @throws \yii\web\BadRequestHttpException            from `requireAcceptsJson` on non-JSON callers.
     * @throws \yii\web\ForbiddenHttpException             from `requireAdmin`.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function actionAllowlistTableData(): Response
    {
        $this->requireAcceptsJson();
        $this->requireAdmin(false);

        $page = max(1, (int) $this->request->getParam('page', 1));
        $perPage = (int) $this->request->getParam('per_page', 50);
        $perPage = max(1, min($perPage, 100));
        $search = trim((string) $this->request->getParam('search', ''));
        $sortField = (string) $this->request->getParam('sort.0.field', '');
        $sortDir = $this->request->getParam('sort.0.direction') === 'desc' ? SORT_DESC : SORT_ASC;

        // Raw rows — already filtered to non-deleted, includes expired
        // so operators can audit/cull expired entries until gc reaps them.
        $rows = Cortex::getInstance()->allowlist->getAllOverrides(includeExpired: true);

        if ($search !== '') {
            $needle = mb_strtolower($search);
            $rows = array_values(array_filter(
                $rows,
                static function(array $row) use ($needle): bool {
                    $haystack = mb_strtolower((string) ($row['pattern'] ?? '') . ' ' . (string) ($row['note'] ?? ''));
                    return str_contains($haystack, $needle);
                },
            ));
        }

        // Sort. Default ordering: dateCreated DESC (newest first) — most
        // operators want the row they just issued at the top. The
        // service already orders by `expiresAt ASC`; we override here
        // when the caller asks, otherwise apply the newest-first default.
        $orderColumn = match ($sortField) {
            'pattern' => 'pattern',
            'expiresAt' => 'expiresAt',
            'dateCreated' => 'dateCreated',
            default => 'dateCreated',
        };
        $defaultDir = $sortField === '' ? SORT_DESC : $sortDir;
        usort($rows, static function(array $a, array $b) use ($orderColumn, $defaultDir): int {
            $av = (string) ($a[$orderColumn] ?? '');
            $bv = (string) ($b[$orderColumn] ?? '');
            $cmp = strcmp($av, $bv);
            return $defaultDir === SORT_DESC ? -$cmp : $cmp;
        });

        $total = count($rows);
        $offset = ($page - 1) * $perPage;
        $slice = array_slice($rows, $offset, $perPage);

        return $this->asJson([
            'pagination' => AdminTable::paginationLinks($page, $total, $perPage),
            'data' => array_map(
                fn(array $row): array => $this->_serializeOverrideRow($row),
                $slice,
            ),
        ]);
    }

    /**
     * Render the "+ New override" slideout body partial. Fetched by
     * `Cortex.openAllowlistOverrideSlideout()` and passed into
     * `new Craft.Slideout(html, {...})`.
     *
     * Returns a bare HTML fragment — no `<html>`/`<body>` chrome, no
     * tab strip — the slideout container supplies that.
     *
     * @throws \craft\errors\MissingComponentException if the view component is unavailable.
     * @throws \yii\base\InvalidConfigException        from `Cortex::getInstance()`.
     * @throws \yii\web\ForbiddenHttpException         from `requireAdmin`.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function actionAllowlistOverrideSlideout(): Response
    {
        $this->requireAdmin(false);

        return $this->renderTemplate('cortex/_cp/_allowlist-override-slideout', [
            'settings' => Cortex::getInstance()->getSettings(),
        ]);
    }

    /**
     * Activity tab — VueAdminTable of the HTTP-transport invocation audit
     * log plus a filter bar (kind / tool / user / date-range) and a
     * row-click Garnish.Slideout showing the already-redacted detail.
     *
     * Permission-gated by `Cortex::PERMISSION_VIEW_ACTIVITY` per locked
     * decision 7 — admins and granted non-admins both reach the tab.
     * The view passes the filter-option lists: the five `kind` enum
     * values from `InvocationLogger`, and the distinct tool names from
     * the DB. The `userId` filter dropdown is rendered only for admins
     * (non-admins are server-side scoped to their own rows, so a user
     * filter would be meaningless and is omitted client-side).
     *
     * @throws \craft\errors\MissingComponentException if the view component is unavailable.
     * @throws \yii\base\InvalidConfigException        from `Cortex::getInstance()`.
     * @throws \yii\web\ForbiddenHttpException         from `requirePermission`.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function actionActivity(): Response
    {
        $this->requirePermission(Cortex::PERMISSION_VIEW_ACTIVITY);

        $identity = Craft::$app->getUser()->getIdentity();
        $isAdmin = $identity instanceof User && $identity->admin;

        return $this->renderTemplate('cortex/_cp/activity', [
            'isAdmin' => $isAdmin,
            'kinds' => [
                InvocationLogger::KIND_SUCCESS,
                InvocationLogger::KIND_TOOL_ERROR,
                InvocationLogger::KIND_INTERNAL_ERROR,
                InvocationLogger::KIND_RATE_LIMITED,
                InvocationLogger::KIND_CANCELLED,
            ],
            'toolNames' => Cortex::getInstance()->invocations->find()->distinctToolNames(),
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
     * non-admin (granted `cortex:viewActivity` but not admin) sees ONLY
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
     * @throws \yii\base\InvalidConfigException        from `Cortex::getInstance()`.
     * @throws \yii\web\BadRequestHttpException        from `requireAcceptsJson` on non-JSON callers.
     * @throws \yii\web\ForbiddenHttpException         from `requirePermission`.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function actionActivityTableData(): Response
    {
        $this->requireAcceptsJson();
        $this->requirePermission(Cortex::PERMISSION_VIEW_ACTIVITY);

        $page = max(1, (int) $this->request->getParam('page', 1));
        $perPage = (int) $this->request->getParam('per_page', 50);
        $perPage = max(1, min($perPage, 100));
        $search = trim((string) $this->request->getParam('search', ''));
        $sortField = (string) $this->request->getParam('sort.0.field', '');
        $sortDir = $this->request->getParam('sort.0.direction') === 'asc' ? SORT_ASC : SORT_DESC;

        $filters = $this->request->getParam('filters', []);
        if (!is_array($filters)) {
            $filters = [];
        }

        $query = Cortex::getInstance()->invocations->find();

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

        $orderColumn = match ($sortField) {
            'tool' => 'toolName',
            'kind' => 'kind',
            'durationMs' => 'durationMs',
            'dateCreated' => 'dateCreated',
            default => 'dateCreated',
        };

        $total = (int) (clone $query)->count();
        $offset = ($page - 1) * $perPage;

        $rows = $query
            ->orderBy([$orderColumn => $sortDir])
            ->offset($offset)
            ->limit($perPage)
            ->all();

        return $this->asJson([
            'pagination' => AdminTable::paginationLinks($page, $total, $perPage),
            'data' => array_map(
                fn(array $row): array => $this->_serializeActivityRow($row),
                $rows,
            ),
        ]);
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
     * @throws \yii\base\InvalidConfigException        from `Cortex::getInstance()`.
     * @throws \yii\web\BadRequestHttpException        from `requireAcceptsJson` on non-JSON callers.
     * @throws \yii\web\ForbiddenHttpException         from `requirePermission`.
     * @throws NotFoundHttpException                   when the row is missing or out of the caller's scope.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function actionActivityRow(): Response
    {
        $this->requireAcceptsJson();
        $this->requirePermission(Cortex::PERMISSION_VIEW_ACTIVITY);

        $id = (int) $this->request->getParam('id');
        if ($id <= 0) {
            throw new NotFoundHttpException('Invocation not found.');
        }

        $query = Cortex::getInstance()->invocations->find()->andWhere(['id' => $id]);
        $this->_scopeActivityQueryToUser($query);

        $row = $query->one();
        if (!is_array($row)) {
            // 404, never 403 — a foreign-row request is indistinguishable
            // from a missing-row request, which kills the enumeration
            // oracle a non-admin could otherwise probe with.
            throw new NotFoundHttpException('Invocation not found.');
        }

        $user = null;
        $userId = $row['userId'] ?? null;
        if (is_int($userId) || (is_string($userId) && ctype_digit($userId))) {
            $boundUser = User::find()->id((int) $userId)->status(null)->one();
            if ($boundUser instanceof User) {
                $user = [
                    'id' => (int) $boundUser->id,
                    'label' => $boundUser->getName(),
                    'cpEditUrl' => $boundUser->getCpEditUrl(),
                ];
            }
        }

        $html = $this->getView()->renderTemplate('cortex/_cp/_activity-detail-slideout', [
            'row' => $row,
            'user' => $user,
        ]);

        return $this->asJson(['html' => $html]);
    }

    /**
     * Connection tab — placeholder for 9.1. Real endpoint-URL display
     * + per-client config snippets land in 9.4.
     *
     * @throws \craft\errors\MissingComponentException if the view component is unavailable.
     * @throws \yii\web\ForbiddenHttpException         from `requireAdmin`.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function actionConnection(): Response
    {
        $this->requireAdmin(false);

        return $this->renderTemplate('cortex/_cp/connection');
    }

    /**
     * Save action for the Settings tab. Replaces Craft's built-in
     * `plugins/save-plugin-settings` route for Cortex per Gate 9
     * locked decision 1.
     *
     * Body-param shape mirrors Craft's standard plugin-settings save —
     * `settings[xxx]` under a single `settings` map per
     * `~/.claude-eng/skills/craftcms/references/cp.md` line 583.
     * `setAttributes($posted, false)` runs without scenario filtering
     * so all settings fields are assignable; `savePluginSettings`
     * persists through project config.
     *
     * On validation failure, sets the fail flash and falls through
     * to a redirect — the Twig form retains posted values via the
     * `$settings` instance held on the plugin.
     *
     * @throws \yii\base\Exception                  on settings-save failure.
     * @throws \yii\base\InvalidConfigException     from `Cortex::getInstance()`.
     * @throws \yii\web\BadRequestHttpException     from `requirePostRequest` on non-POST.
     * @throws \yii\web\ForbiddenHttpException      from `requireAdmin`.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $this->requireAdmin(requireAdminChanges: true);

        $plugin = Cortex::getInstance();
        $settings = $plugin->getSettings();

        $posted = $this->request->getBodyParam('settings', []);
        if (!is_array($posted)) {
            $posted = [];
        }

        // The `editableTableField` macro for `allowedCommands` posts as
        // a 2D array (`settings[allowedCommands][N][pattern]`); the
        // settings model is typed `string[]`. Flatten back to pattern
        // strings before assignment so `setAttributes` accepts the
        // payload and `savePluginSettings` persists it (otherwise
        // shape-mismatch silently falls back to the prior PC value).
        if (isset($posted['allowedCommands']) && is_array($posted['allowedCommands'])) {
            $posted['allowedCommands'] = array_values(array_filter(
                array_map(
                    static fn($row) => is_array($row) ? trim((string) ($row['pattern'] ?? '')) : '',
                    $posted['allowedCommands'],
                ),
                static fn(string $pattern) => $pattern !== '',
            ));
        }

        $settings->setAttributes($posted, false);

        if (!Craft::$app->getPlugins()->savePluginSettings($plugin, $settings->toArray())) {
            Craft::$app->getSession()->setError(
                Craft::t('cortex', "Couldn't save settings."),
            );
            return $this->redirectToPostedUrl();
        }

        Craft::$app->getSession()->setNotice(
            Craft::t('cortex', 'Settings saved.'),
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
     * @throws \yii\base\InvalidConfigException  from `Cortex::getInstance()`.
     * @throws \yii\web\BadRequestHttpException  from `requirePostRequest` / `requireAcceptsJson` / `getRequiredBodyParam`.
     * @throws \yii\web\ForbiddenHttpException   from `requireAdmin`.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function actionAddOverride(): ?Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requireAdmin(requireAdminChanges: true);

        $request = $this->request;

        $pattern = trim((string) $request->getRequiredBodyParam('pattern'));
        $note = $request->getBodyParam('note');
        $note = is_string($note) && $note !== '' ? trim($note) : null;
        $ttl = $request->getBodyParam('ttlSeconds');
        $ttlSeconds = is_numeric($ttl) && (int) $ttl > 0 ? (int) $ttl : null;

        if ($pattern === '') {
            return $this->asFailure(Craft::t('cortex', 'Pattern is required.'));
        }

        $userId = Craft::$app->getUser()->getId();
        try {
            $override = Cortex::getInstance()->allowlist->add(
                pattern: $pattern,
                userId: is_int($userId) ? $userId : null,
                note: $note,
                ttlSeconds: $ttlSeconds,
            );
        } catch (Exception $e) {
            Craft::error($e->getMessage(), 'cortex');
            return $this->asFailure(Craft::t('cortex', 'Could not add override.'));
        }

        return $this->asSuccess(
            message: Craft::t('cortex', 'Override added.'),
            data: ['model' => $this->_serializeOverrideRow($override->toArray())],
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
     * @throws \yii\base\InvalidConfigException  from `Cortex::getInstance()`.
     * @throws \yii\web\BadRequestHttpException  from `requirePostRequest` / `requireAcceptsJson` / `getRequiredBodyParam`.
     * @throws \yii\web\ForbiddenHttpException   from `requireAdmin`.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function actionRemoveOverride(): ?Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requireAdmin(requireAdminChanges: true);

        $id = (int) $this->request->getRequiredBodyParam('id');

        try {
            $removed = Cortex::getInstance()->allowlist->remove($id);
        } catch (Exception $e) {
            Craft::error($e->getMessage(), 'cortex');
            return $this->asFailure(Craft::t('cortex', 'Could not remove override.'));
        }

        if (!$removed) {
            $this->response->setStatusCode(404);
            return $this->asJson(['message' => Craft::t('cortex', 'Override not found.')]);
        }

        return $this->asSuccess(Craft::t('cortex', 'Override removed.'));
    }

    // Private Methods
    // =========================================================================

    /**
     * Whether the current CP user is an admin. Centralised so the
     * Activity scoping logic reads the identity in exactly one place.
     *
     * @author Craftpulse
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
     * @author Craftpulse
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
     * @author Craftpulse
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
     * Serialise a `cortex_invocations` row into the locked Activity
     * VueAdminTable data tuple. Shared by `actionActivityTableData` so the
     * table shape is asserted in exactly one place.
     *
     * Row shape:
     *   - `id`          — int primary key.
     *   - `tool`        — string tool name (column `toolName`).
     *   - `mode`        — string|null tool mode, extracted from the
     *                      redacted args' `mode` key when present.
     *   - `kind`        — string audit kind (status pill colour).
     *   - `user`        — `{id, label, cpEditUrl}` or null (anonymous /
     *                      deleted user).
     *   - `durationMs`  — int wall-clock duration.
     *   - `dateCreated` — string ISO-8601.
     *
     * Never surfaces `argsRedacted` / `responseExcerpt` / error payloads —
     * those live only in the detail slideout (locked decision 11).
     *
     * @param array<string,mixed> $row The raw DB row from `InvocationQuery::all`.
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _serializeActivityRow(array $row): array
    {
        $user = null;
        $userId = $row['userId'] ?? null;
        if (is_int($userId) || (is_string($userId) && ctype_digit($userId))) {
            $boundUser = User::find()->id((int) $userId)->status(null)->one();
            if ($boundUser instanceof User) {
                $user = [
                    'id' => (int) $boundUser->id,
                    'label' => $boundUser->getName(),
                    'cpEditUrl' => $boundUser->getCpEditUrl(),
                ];
            }
        }

        return [
            'id' => (int) ($row['id'] ?? 0),
            'tool' => (string) ($row['toolName'] ?? ''),
            'mode' => $this->_extractMode($row['argsRedacted'] ?? null),
            'kind' => (string) ($row['kind'] ?? ''),
            'user' => $user,
            'durationMs' => (int) ($row['durationMs'] ?? 0),
            'dateCreated' => (string) ($row['dateCreated'] ?? ''),
        ];
    }

    /**
     * Best-effort extraction of the `mode` key from a redacted-args JSON
     * column. Returns null when the column is absent, not JSON, or carries
     * no `mode`. The args are already redacted in the DB — this only reads
     * the (non-sensitive) routing discriminator most Cortex tools carry.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _extractMode(mixed $argsRedacted): ?string
    {
        if (!is_string($argsRedacted) || $argsRedacted === '') {
            return null;
        }
        $decoded = json_decode($argsRedacted, true);
        if (!is_array($decoded)) {
            return null;
        }
        $mode = $decoded['mode'] ?? null;
        return is_string($mode) && $mode !== '' ? $mode : null;
    }

    /**
     * Serialise a `RuntimeOverride` row into the locked VueAdminTable
     * data tuple. Shared by `actionAllowlistTableData` and the success
     * branch of `actionAddOverride` so the row returned by the issue
     * flow has the same shape the table refresh would render.
     *
     * Row shape:
     *   - `id`           — int primary key.
     *   - `pattern`      — string (the fnmatch glob).
     *   - `note`         — string|null (admin freeform).
     *   - `expiresAt`    — string|null ISO-8601, or null = never.
     *   - `createdBy`    — `{id, label, cpEditUrl}` or null when the
     *                       issuing user record is missing.
     *   - `dateCreated`  — string ISO-8601.
     *   - `isExpired`    — bool derived against `now`; the VueAdminTable
     *                       cell renderer styles expired rows muted.
     *
     * @param array<string,mixed> $row The raw DB row from `Allowlist::getAllOverrides`
     *                                 or `RuntimeOverride::toArray()`.
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _serializeOverrideRow(array $row): array
    {
        $expiresAt = $row['expiresAt'] ?? null;
        $isExpired = false;
        if (is_string($expiresAt) && $expiresAt !== '') {
            $exp = DateTimeHelper::toDateTime($expiresAt);
            $isExpired = $exp !== false && $exp < DateTimeHelper::now();
        }

        $createdBy = null;
        $createdByUserId = $row['createdByUserId'] ?? null;
        if (is_int($createdByUserId) || (is_string($createdByUserId) && ctype_digit($createdByUserId))) {
            $user = User::find()->id((int) $createdByUserId)->status(null)->one();
            if ($user instanceof User) {
                $createdBy = [
                    'id' => (int) $user->id,
                    'label' => $user->getName(),
                    'cpEditUrl' => $user->getCpEditUrl(),
                ];
            }
        }

        return [
            'id' => (int) ($row['id'] ?? 0),
            'pattern' => (string) ($row['pattern'] ?? ''),
            'note' => isset($row['note']) && $row['note'] !== '' ? (string) $row['note'] : null,
            'expiresAt' => is_string($expiresAt) && $expiresAt !== '' ? $expiresAt : null,
            'createdBy' => $createdBy,
            'dateCreated' => (string) ($row['dateCreated'] ?? ''),
            'isExpired' => $isExpired,
        ];
    }

    /**
     * Serialise a `Token` model into the locked VueAdminTable data tuple.
     * Shared by `actionTokensTableData` and the success branch of
     * `actionIssueToken` so the row returned by the issue flow has the
     * same shape the table refresh would render.
     *
     * The plaintext is NEVER part of this tuple — only the operator-safe
     * `tokenPrefix` hint surfaces. The plaintext exists exactly once, in
     * `actionIssueToken`'s separate `token` response key.
     *
     * Row shape:
     *   - `id`          — int primary key.
     *   - `name`        — string operator label.
     *   - `tokenPrefix` — string (first 8 chars of the plaintext, a hint).
     *   - `user`        — `{id, label, cpEditUrl}` or null when the bound
     *                      user record is missing.
     *   - `expiresAt`   — string|null ISO-8601, or null = never.
     *   - `lastUsedAt`  — string|null ISO-8601, or null = never used.
     *   - `dateCreated` — string ISO-8601.
     *
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _serializeTokenRow(Token $token): array
    {
        $user = null;
        $boundUser = $token->getUser();
        if ($boundUser instanceof User) {
            $user = [
                'id' => (int) $boundUser->id,
                'label' => $boundUser->getName(),
                'cpEditUrl' => $boundUser->getCpEditUrl(),
            ];
        }

        return [
            'id' => (int) $token->id,
            'name' => $token->name,
            'tokenPrefix' => $token->tokenPrefix,
            'user' => $user,
            'expiresAt' => $token->expiresAt,
            'lastUsedAt' => $token->lastUsedAt,
            'dateCreated' => (string) $token->dateCreated,
        ];
    }
}
