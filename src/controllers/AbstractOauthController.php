<?php

namespace craftpulse\cortex\controllers;

use craft\web\Controller;
use craftpulse\cortex\Cortex;
use yii\web\Response;

/**
 * =========================================================================
 * Shared base for the public OAuth / discovery controllers
 * (`OauthController`, `WellKnownController`).
 *
 * Carries the `httpEnabled` kill switch so the entire `/oauth/*` and
 * `/.well-known/*` surface is gated behind the same flag the
 * `McpController` already enforces. With `Settings::$httpEnabled`
 * false (the default), every action on a subclass returns 503 Service
 * Unavailable before any DB work, DCR insert, or token issuance can
 * run — mirroring `McpController::beforeAction()`'s Gate 1.
 *
 * The check fires in `beforeAction()` ahead of `parent::beforeAction()`
 * so the 503 short-circuits before Craft's standard pipeline (CSRF
 * exemption, `_enforceAllowAnonymous`) even looks at the request.
 * Subclasses keep their own `$allowAnonymous` / `$enableCsrfValidation`
 * posture; this base only adds the transport gate. A subclass that
 * overrides `beforeAction()` for its own auth/consent flow MUST call
 * `parent::beforeAction()` so the kill switch still runs.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */
abstract class AbstractOauthController extends Controller
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * Runs the `httpEnabled` kill switch before delegating to Craft's
     * standard pipeline. Returns false (with a 503 populated on the
     * response) when the HTTP transport is disabled; otherwise hands
     * off to `parent::beforeAction()`.
     *
     * @throws \yii\web\BadRequestHttpException From `parent::beforeAction()`.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function beforeAction($action): bool
    {
        if (!Cortex::getInstance()->getSettings()->httpEnabled) {
            $this->_httpDisabled();
            return false;
        }

        return parent::beforeAction($action);
    }

    // Private Methods
    // =========================================================================

    /**
     * Populate the shared 503 response used when the HTTP transport is
     * disabled. Same shape as `McpController`'s kill-switch reject — a
     * JSON `{"error": "…"}` body naming the `httpEnabled` flag.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _httpDisabled(): Response
    {
        $this->response->format = Response::FORMAT_JSON;
        $this->response->setStatusCode(503);
        $this->response->data = [
            'error' => 'HTTP transport is disabled. Set Settings::$httpEnabled = true to enable.',
        ];
        return $this->response;
    }
}
