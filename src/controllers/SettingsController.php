<?php

namespace craftpulse\cortex\controllers;

use Craft;
use craft\elements\User;
use craft\helpers\AdminTable;
use craft\helpers\DateTimeHelper;
use craft\web\Controller;
use craftpulse\cortex\Cortex;
use craftpulse\cortex\records\RuntimeOverride;
use yii\base\Exception;
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
 * 9.1 ships the view-action skeleton for the four tabs (Settings,
 * Tokens, Activity, Connection) plus `actionSave` for persisting
 * Settings-tab edits. Tokens, Activity, and Connection tabs render
 * placeholder templates — their real content + table-data /
 * mutation actions land in 9.2 / 9.3 / 9.4 respectively.
 *
 * Permission posture per locked decision 7:
 *   - Settings / Tokens / Connection view actions    — `requireAdmin(false)`.
 *   - Activity view action                            — `requirePermission(Cortex::PERMISSION_VIEW_ACTIVITY)`.
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
     * Tokens tab — placeholder for 9.1. Real Vue admin table +
     * token-issuance slideout land in 9.2.
     *
     * @throws \craft\errors\MissingComponentException if the view component is unavailable.
     * @throws \yii\web\ForbiddenHttpException         from `requireAdmin`.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function actionTokens(): Response
    {
        $this->requireAdmin(false);

        return $this->renderTemplate('cortex/_cp/tokens');
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
     * Activity tab — placeholder for 9.1. Real Vue admin table +
     * filter bar + detail slideout land in 9.3. Permission-gated by
     * `Cortex::PERMISSION_VIEW_ACTIVITY`; non-admins with the
     * permission see only their own rows once 9.3 builds the
     * scoping.
     *
     * @throws \craft\errors\MissingComponentException if the view component is unavailable.
     * @throws \yii\web\ForbiddenHttpException         from `requirePermission`.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function actionActivity(): Response
    {
        $this->requirePermission(Cortex::PERMISSION_VIEW_ACTIVITY);

        return $this->renderTemplate('cortex/_cp/activity');
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
}
