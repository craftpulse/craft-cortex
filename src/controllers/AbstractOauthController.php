<?php

namespace craftpulse\herald\controllers;

use craft\web\Controller;
use craftpulse\herald\exceptions\RateLimitExceededException;
use craftpulse\herald\Herald;
use craftpulse\herald\values\RateLimitStatus;
use yii\base\Action;
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
 * Behind the kill switch sits the Pro edition gate (Gate 9.7): OAuth
 * and discovery only exist to serve the Streamable HTTP transport,
 * which is Pro-only (PLANNING.md §4 — Free ships stdio only). On a
 * Free install every action returns 403 with an edition-naming body,
 * mirroring `McpController::beforeAction()`'s Gate 2.
 *
 * The check fires in `beforeAction()` ahead of `parent::beforeAction()`
 * so the 503 short-circuits before Craft's standard pipeline (CSRF
 * exemption, `_enforceAllowAnonymous`) even looks at the request.
 * Subclasses keep their own `$allowAnonymous` / `$enableCsrfValidation`
 * posture; this base only adds the transport gate. A subclass that
 * overrides `beforeAction()` for its own auth/consent flow MUST call
 * `parent::beforeAction()` so the kill switch still runs.
 *
 * The base also carries an IP-keyed throttle for the unauthenticated
 * OAuth endpoints. `OauthController` overrides `_throttledActionIds()`
 * to name `register` / `token` / `revoke` — actions that precede
 * authentication and so cannot key by Craft user id. Each remote IP
 * gets its own token bucket (burst / refill shared with the per-user
 * HTTP limiter) and is 429'd with `Retry-After` once exhausted. The
 * default `_throttledActionIds()` is empty, so `WellKnownController`'s
 * cheap static reads stay unthrottled.
 * =========================================================================
 *
 * @author CraftPulse
 * @since  5.0.0
 */
abstract class AbstractOauthController extends Controller
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * Runs the `httpEnabled` kill switch, then the Pro edition gate,
     * then the IP-keyed throttle for the actions named by
     * `_throttledActionIds()`, before delegating to Craft's standard
     * pipeline. Returns false (with a 503, 403, or 429 populated on
     * the response) when a gate trips; otherwise hands off to
     * `parent::beforeAction()`.
     *
     * @throws \yii\web\BadRequestHttpException From `parent::beforeAction()`.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function beforeAction($action): bool
    {
        if (!Herald::getInstance()->getSettings()->httpEnabled) {
            $this->_httpDisabled();
            return false;
        }

        if (!Herald::getInstance()->is(Herald::EDITION_PRO, '>=')) {
            $this->_proRequired();
            return false;
        }

        if (!$this->_passesIpThrottle($action)) {
            return false;
        }

        return parent::beforeAction($action);
    }

    // Protected Methods
    // =========================================================================

    /**
     * Action ids that get the IP-keyed throttle. Empty on the base —
     * `OauthController` overrides this to name its unauthenticated
     * endpoints. The authenticated `authorize` action is excluded:
     * it's gated by a live Craft session, not throttled by IP.
     *
     * @return string[]
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    protected function _throttledActionIds(): array
    {
        return [];
    }

    // Private Methods
    // =========================================================================

    /**
     * Throttle the request by remote IP when the action is in
     * `_throttledActionIds()`. Consumes one token from the IP's bucket
     * (keyed `oauth:ip:<ip>`); on exhaustion populates a 429 with
     * `Retry-After` and returns false. Actions outside the throttle
     * list, and requests without a resolvable IP, pass through
     * untouched.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _passesIpThrottle(Action $action): bool
    {
        if (!in_array($action->id, $this->_throttledActionIds(), true)) {
            return true;
        }

        $ip = $this->request->getUserIP();
        if (!is_string($ip) || $ip === '') {
            return true;
        }

        try {
            Herald::getInstance()->rateLimiter->consumeKey('oauth:ip:' . $ip);
        } catch (RateLimitExceededException $e) {
            $this->_rateLimited($e->status);
            return false;
        }

        return true;
    }

    /**
     * Populate a 429 response with the canonical `Retry-After: <seconds>`
     * header and a JSON body. Mirrors `McpController::_rateLimited()`.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _rateLimited(RateLimitStatus $status): Response
    {
        $this->response->headers->set('Retry-After', (string) $status->retryAfter);
        $this->response->format = Response::FORMAT_JSON;
        $this->response->setStatusCode(429);
        $this->response->data = [
            'error' => sprintf('Rate limit exceeded; retry after %ds.', $status->retryAfter),
        ];
        return $this->response;
    }

    /**
     * Populate the shared 503 response used when the HTTP transport is
     * disabled. Same shape as `McpController`'s kill-switch reject — a
     * JSON `{"error": "…"}` body naming the `httpEnabled` flag.
     *
     * @author CraftPulse
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

    /**
     * Populate the shared 403 response used on Free installs. OAuth and
     * discovery serve only the Pro-tier HTTP transport — 403, not 503,
     * because the edition is a durable licensing state, not a config
     * switch. Mirrors `McpController::beforeAction()`'s Gate 2.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _proRequired(): Response
    {
        $this->response->format = Response::FORMAT_JSON;
        $this->response->setStatusCode(403);
        $this->response->data = [
            'error' => 'The HTTP transport requires the Herald Pro edition.',
        ];
        return $this->response;
    }
}
