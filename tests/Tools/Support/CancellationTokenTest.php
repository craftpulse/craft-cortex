<?php

/**
 * =========================================================================
 * CancellationToken tests — verify the cooperative cancellation contract
 * locked in sub-gate 7.1.
 *
 * No wire today; streaming tools opt in via `InvocationContext::
 * getCancellationToken()->isCancelled()` between yields. The shape is
 * pinned now so the wire implementation in sub-gate 7.7 doesn't break
 * the contract.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */

use craftpulse\herald\tools\support\CancellationToken;
use craftpulse\herald\tools\support\InvocationContext;

it('starts unfired', function() {
    $token = new CancellationToken();
    expect($token->isCancelled())->toBeFalse();
});

it('cancel() flips the flag and stays flipped', function() {
    $token = new CancellationToken();
    $token->cancel();
    expect($token->isCancelled())->toBeTrue();

    // Idempotent — second cancel() is a no-op.
    $token->cancel();
    expect($token->isCancelled())->toBeTrue();
});

it('cancel() captures the optional reason and exposes it through getReason()', function() {
    // Sub-gate 7.7.5: the streaming controller's TCP-disconnect path
    // calls `cancel('client disconnected')` so audit / forensic
    // surfaces can distinguish a network drop from a spec-driven
    // `notifications/cancelled` arrival.
    $token = new CancellationToken();
    expect($token->getReason())->toBeNull();

    $token->cancel('client disconnected');
    expect($token->isCancelled())->toBeTrue();
    expect($token->getReason())->toBe('client disconnected');

    // First reason wins — defensive double-cancel leaves the original
    // reason intact rather than overwriting it with a later (and
    // probably less informative) caller.
    $token->cancel('something else');
    expect($token->getReason())->toBe('client disconnected');
});

it('cancel() without a reason leaves getReason() null even after flipping', function() {
    $token = new CancellationToken();
    $token->cancel();
    expect($token->isCancelled())->toBeTrue();
    expect($token->getReason())->toBeNull();
});

it('InvocationContext defaults a fresh unfired token when none is supplied', function() {
    $ctx = new InvocationContext(transport: 'stdio');
    expect($ctx->getCancellationToken())->toBeInstanceOf(CancellationToken::class);
    expect($ctx->getCancellationToken()->isCancelled())->toBeFalse();
});

it('InvocationContext threads through a supplied token', function() {
    $token = new CancellationToken();
    $ctx = new InvocationContext(
        transport: 'http',
        cancellationToken: $token,
    );

    expect($ctx->getCancellationToken())->toBe($token);

    $token->cancel();
    expect($ctx->getCancellationToken()->isCancelled())->toBeTrue();
});
