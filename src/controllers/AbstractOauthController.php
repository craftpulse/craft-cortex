<?php

namespace craftpulse\cortex\controllers;

use craft\web\Controller;
use craftpulse\cortex\Cortex;
use craftpulse\cortex\exceptions\RateLimitExceededException;
use craftpulse\cortex\values\RateLimitStatus;
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
     * Runs the `httpEnabled` kill switch, then the IP-keyed throttle
     * for the actions named by `_throttledActionIds()`, before
     * delegating to Craft's standard pipeline. Returns false (with a
     * 503 or 429 populated on the response) when a gate trips;
     * otherwise hands off to `parent::beforeAction()`.
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
     * @author Craftpulse
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
     * @author Craftpulse
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
            Cortex::getInstance()->rateLimiter->consumeKey('oauth:ip:' . $ip);
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
     * @author Craftpulse
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
