<?php

/**
 * =========================================================================
 * WS2 elevation service + boundary tests.
 *
 * Covers the `Oauth` elevation marker (mint / check, keyed per Craft
 * user) and the hard exception that elevation does NOT unlock
 * `craft_exec` / any `IsStdioOnly` tool over HTTP — that transport
 * boundary is non-negotiable and independent of elevation state.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */

use craftpulse\herald\Herald;
use craftpulse\herald\mcp\Server;

it('mints an elevation marker bound to a Craft user id', function() {
    $userId = random_int(1_000_000, 9_000_000);
    $oauth = Herald::getInstance()->oauth;

    expect($oauth->isElevated($userId))->toBeFalse();

    $oauth->grantElevation($userId);

    expect($oauth->isElevated($userId))->toBeTrue();

    // Cleanup.
    Craft::$app->getCache()->delete($oauth->elevationCacheKey($userId));
});

it('elevation is bound per-user: a different user id is not elevated', function() {
    $userA = random_int(1_000_000, 4_000_000);
    $userB = random_int(5_000_000, 9_000_000);
    $oauth = Herald::getInstance()->oauth;

    $oauth->grantElevation($userA);

    expect($oauth->isElevated($userA))->toBeTrue()
        ->and($oauth->isElevated($userB))->toBeFalse();

    Craft::$app->getCache()->delete($oauth->elevationCacheKey($userA));
});

it('the elevation cache key is a hash of the user id (no raw id leak)', function() {
    $userId = 1234567;
    $key = Herald::getInstance()->oauth->elevationCacheKey($userId);

    expect($key)->toContain('herald:elevation:')
        ->and($key)->not->toContain((string) $userId);
});

it('elevation does NOT unlock craft_exec over the HTTP transport', function() {
    // The hard exception: an elevated HTTP dispatcher still rejects
    // craft_exec. Elevation compensates for the missing HTTP re-auth on
    // credential / content operations — it never crosses the code-
    // execution transport boundary.
    herald_with_pro_registry(function(): void {
        $server = new Server(Server::TRANSPORT_HTTP);
        $server->setElevated(true);

        $response = $server->dispatch([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'craft_exec',
                'arguments' => ['code' => 'return 1;'],
            ],
        ]);

        expect($response)->toHaveKey('error');
        expect($response['error']['message'])->toContain('stdio-only');
    });
});
