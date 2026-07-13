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

// -----------------------------------------------------------------------------
// Env-var-aware numeric tunables — resolved getters + range validation
// -----------------------------------------------------------------------------

it('resolves literal and numeric-string tunables through their getters', function() {
    $settings = new Settings();

    $settings->sessionTtl = 7200;
    expect($settings->getSessionTtl())->toBe(7200);

    $settings->rateLimitBurst = '120';
    expect($settings->getRateLimitBurst())->toBe(120);
});

it('resolves an environment-variable reference through a getter', function() {
    putenv('HERALD_TEST_TTL=1800');
    $_ENV['HERALD_TEST_TTL'] = '1800';
    $_SERVER['HERALD_TEST_TTL'] = '1800';

    $settings = new Settings();
    $settings->sessionTtl = '$HERALD_TEST_TTL';
    expect($settings->getSessionTtl())->toBe(1800);

    putenv('HERALD_TEST_TTL');
    unset($_ENV['HERALD_TEST_TTL'], $_SERVER['HERALD_TEST_TTL']);
});

it('treats a null or blank nullable tunable as "no limit"', function() {
    $settings = new Settings();

    $settings->tokenTtlDefault = null;
    expect($settings->getTokenTtlDefault())->toBeNull();

    $settings->tokenTtlDefault = '';
    expect($settings->getTokenTtlDefault())->toBeNull();

    $settings->auditRetentionDays = null;
    expect($settings->getAuditRetentionDays())->toBeNull();
});

it('resolves a set nullable tunable to its integer', function() {
    $settings = new Settings();
    $settings->tokenTtlDefault = '2592000';
    expect($settings->getTokenTtlDefault())->toBe(2592000);
});

it('range-validates a resolved integer tunable', function() {
    $settings = new Settings();
    $settings->rateLimitPerSecond = '0'; // below the min of 1
    $settings->validate(['rateLimitPerSecond']);
    expect($settings->hasErrors('rateLimitPerSecond'))->toBeTrue();
});

it('rejects a non-numeric tunable value', function() {
    $settings = new Settings();
    $settings->sessionTtl = 'not-a-number';
    $settings->validate(['sessionTtl']);
    expect($settings->hasErrors('sessionTtl'))->toBeTrue();
});
