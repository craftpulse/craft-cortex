<?php

namespace craftpulse\cortex\controllers;

use Craft;
use craft\web\Controller;
use craftpulse\cortex\Cortex;
use yii\base\Exception;
use yii\web\Response;

/**
 * =========================================================================
 * Web controller — runtime allowlist override management.
 *
 * The standard plugin-settings save action (handled by Craft's built-in
 * `plugins/save-plugin-settings`) covers the `Settings` model fields
 * — `allowedCommands`, `execEnabled`, `execDryRunDefault`,
 * `runtimeOverrideTtl`. They sync via project config.
 *
 * This controller covers the DB-backed runtime overrides — admin-only
 * additions that layer on top of the defaults with auto-expiry. CRUD
 * here doesn't touch project config.
 *
 * Both actions require admin access plus the admin-changes permission
 * because allowlist mutations are a security boundary — the same
 * scrutiny project-config edits get.
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
     * Add a runtime allowlist override. Returns to the cortex settings
     * page on success with a flash, or back with errors on failure.
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
