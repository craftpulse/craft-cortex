<?php

/**
 * =========================================================================
 * Validation rules for the Settings model — focused on the OAuth TTL
 * `DateInterval` parseability rule, which guards the values fed
 * straight into `new DateInterval(...)` at authorization-server build.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */

use craftpulse\herald\models\Settings;

it('accepts valid ISO-8601 durations for the OAuth TTL settings', function() {
    $settings = new Settings();
    $settings->oauthAccessTokenTtl = 'PT1H';
    $settings->oauthRefreshTokenTtl = 'P30D';

    expect($settings->validate(['oauthAccessTokenTtl', 'oauthRefreshTokenTtl']))->toBeTrue();
});

it('rejects a malformed OAuth TTL before it can 500 the token endpoint', function() {
    $settings = new Settings();
    // A common operator typo — human-readable, not ISO-8601.
    $settings->oauthAccessTokenTtl = '1h';

    expect($settings->validate(['oauthAccessTokenTtl']))->toBeFalse()
        ->and($settings->getErrors('oauthAccessTokenTtl'))->not->toBeEmpty();
});
