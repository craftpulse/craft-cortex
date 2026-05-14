<?php

namespace craftpulse\cortex\exceptions;

use craftpulse\cortex\values\RateLimitStatus;
use RuntimeException;

/**
 * =========================================================================
 * Raised by `RateLimiter::consume()` when a user's bucket is exhausted.
 *
 * Carries the post-consume `RateLimitStatus` so the controller can map
 * straight to a 429 + `Retry-After` header without re-querying the
 * cache. The status's `retryAfter` field is the value the controller
 * stamps onto the header; the rest of the shape (remaining=0, the
 * refill timestamp) is forensic context that flows into the
 * `kind=rate_limited` audit row.
 *
 * Extending `RuntimeException` rather than Yii's `UserException` keeps
 * the exception transport-agnostic — the rate limiter is consulted from
 * the controller's `beforeAction()`, not from inside a Yii action, so
 * the exception never needs the framework's UserException machinery. A
 * try/catch around `consume()` in the controller is the only call site.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */
class RateLimitExceededException extends RuntimeException
{
    // Public Methods
    // =========================================================================

    /**
     * @param RateLimitStatus $status Post-consume bucket snapshot. The
     *                                controller reads `$status->retryAfter`
     *                                for the HTTP header and writes the
     *                                full shape onto the audit row.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function __construct(
        public readonly RateLimitStatus $status,
    ) {
        parent::__construct(sprintf(
            'Rate limit exceeded; retry after %ds',
            $status->retryAfter,
        ));
    }
}
