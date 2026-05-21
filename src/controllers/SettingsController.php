<?php

namespace craftpulse\cortex\controllers;

use Craft;
use craft\web\Controller;
use craftpulse\cortex\Cortex;
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
     * Allowlist tab — placeholder for 9.1. Real Vue admin table of
     * admin-issued runtime overrides + Garnish.Slideout for "Issue
     * override" + row-action revoke land in 9.5. The DB-layer surface
     * (`Allowlist` service, `actionAddOverride`, `actionRemoveOverride`)
     * already exists from pre-Gate-9 work and stays alive between 9.1
     * and 9.5.
     *
     * @throws \craft\errors\MissingComponentException if the view component is unavailable.
     * @throws \yii\web\ForbiddenHttpException         from `requireAdmin`.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function actionAllowlist(): Response
    {
        $this->requireAdmin(false);

        return $this->renderTemplate('cortex/_cp/allowlist');
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
     * Add a runtime allowlist override. Returns to the cortex settings
     * page on success with a flash, or back with errors on failure.
     *
     * @throws \yii\base\InvalidConfigException  from `Cortex::getInstance()`.
     * @throws \yii\web\BadRequestHttpException  from `requirePostRequest` / `getRequiredBodyParam`.
     * @throws \yii\web\ForbiddenHttpException   from `requireAdmin`.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function actionAddOverride(): Response
    {
        $this->requirePostRequest();
        $this->requireAdmin(requireAdminChanges: true);

        $request = $this->request;

        $pattern = trim((string) $request->getRequiredBodyParam('pattern'));
        $note = $request->getBodyParam('note');
        $note = is_string($note) && $note !== '' ? trim($note) : null;
        $ttl = $request->getBodyParam('ttlSeconds');
        $ttlSeconds = is_numeric($ttl) ? (int) $ttl : null;

        if ($pattern === '') {
            Craft::$app->getSession()->setError(
                Craft::t('cortex', 'Pattern is required.'),
            );
            return $this->redirectToPostedUrl();
        }

        $userId = Craft::$app->getUser()->getId();
        try {
            Cortex::getInstance()->allowlist->add(
                pattern: $pattern,
                userId: is_int($userId) ? $userId : null,
                note: $note,
                ttlSeconds: $ttlSeconds,
            );
        } catch (Exception $e) {
            Craft::error($e->getMessage(), 'cortex');
            Craft::$app->getSession()->setError(
                Craft::t('cortex', 'Could not add override.'),
            );
            return $this->redirectToPostedUrl();
        }

        Craft::$app->getSession()->setNotice(
            Craft::t('cortex', 'Override added.'),
        );
        return $this->redirectToPostedUrl();
    }

    /**
     * Soft-delete a runtime allowlist override by id. Returns to the
     * cortex settings page.
     *
     * @throws \yii\base\InvalidConfigException  from `Cortex::getInstance()`.
     * @throws \yii\web\BadRequestHttpException  from `requirePostRequest` / `getRequiredBodyParam`.
     * @throws \yii\web\ForbiddenHttpException   from `requireAdmin`.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function actionRemoveOverride(): Response
    {
        $this->requirePostRequest();
        $this->requireAdmin(requireAdminChanges: true);

        $id = (int) $this->request->getRequiredBodyParam('id');

        try {
            $removed = Cortex::getInstance()->allowlist->remove($id);
        } catch (Exception $e) {
            Craft::error($e->getMessage(), 'cortex');
            Craft::$app->getSession()->setError(
                Craft::t('cortex', 'Could not remove override.'),
            );
            return $this->redirectToPostedUrl();
        }

        if ($removed) {
            Craft::$app->getSession()->setNotice(
                Craft::t('cortex', 'Override removed.'),
            );
        } else {
            Craft::$app->getSession()->setError(
                Craft::t('cortex', 'Override not found.'),
            );
        }

        return $this->redirectToPostedUrl();
    }
}
