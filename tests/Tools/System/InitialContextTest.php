<?php

/**
 * =========================================================================
 * Pest tests for the `get_initial_context` orientation tool. The shape
 * is locked to the outputSchema declaration on the tool class, so the
 * assertions here also act as a guardrail against schema drift.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */

use Craft;
use craftpulse\cortex\Cortex;

beforeEach(function() {
    $this->tool = Cortex::getInstance()->tools->getByName('get_initial_context');
});

it('is registered under the get_initial_context name', function() {
    expect($this->tool)->not->toBeNull();
    expect($this->tool::getName())->toBe('get_initial_context');
});

it('appears first in tools/list so fresh agents see it first', function() {
    $payload = Cortex::getInstance()->tools->asListPayload();
    expect($payload[0]['name'])->toBe('get_initial_context');
});

it('returns craft / sites / sections / elementTypes / skillPrompts / exec / allowlist / hints', function() {
    $result = $this->tool->execute([]);

    expect($result)->toHaveKeys([
        'craft', 'sites', 'sections', 'elementTypes',
        'skillPrompts', 'exec', 'allowlist', 'hints',
    ]);
});

it('exposes the running Craft version and primary site handle under craft.*', function() {
    $result = $this->tool->execute([]);

    expect($result['craft'])->toHaveKeys([
        'version', 'edition', 'schemaVersion', 'environment', 'devMode', 'primarySiteHandle',
    ]);
    expect($result['craft']['version'])->toBe(Craft::$app->getVersion());
    expect($result['craft']['edition'])->toBeIn(['Solo', 'Team', 'Pro', 'Enterprise']);
    expect($result['craft']['primarySiteHandle'])
        ->toBe(Craft::$app->getSites()->getPrimarySite()->handle);
});

it('lists every site with handle / name / language / primary', function() {
    $result = $this->tool->execute([]);

    expect($result['sites'])->toBeArray()->not->toBeEmpty();

    $primarySiteHandles = array_filter(
        $result['sites'],
        static fn(array $s): bool => $s['primary'] === true,
    );
    expect($primarySiteHandles)->toHaveCount(1);

    foreach ($result['sites'] as $site) {
        expect($site)->toHaveKeys(['handle', 'name', 'language', 'primary']);
    }
});

it('includes the bundled skill prompts so the LLM knows the moat content exists', function() {
    $result = $this->tool->execute([]);

    expect($result['skillPrompts'])->toBeArray()->not->toBeEmpty();

    foreach ($result['skillPrompts'] as $prompt) {
        expect($prompt)->toHaveKeys(['name', 'description']);
        expect($prompt['name'])->toStartWith('craftcms_');
    }

    // Cross-check: skillPrompts count matches the prompt registry.
    expect($result['skillPrompts'])->toHaveCount(Cortex::getInstance()->prompts->getCount());
});

it('surfaces the craft_exec posture (enabled + dryRunDefault) from settings', function() {
    $result = $this->tool->execute([]);

    expect($result['exec'])->toHaveKeys(['enabled', 'dryRunDefault']);
    expect($result['exec']['enabled'])->toBe(Cortex::getInstance()->getSettings()->execEnabled);
    expect($result['exec']['dryRunDefault'])->toBe(Cortex::getInstance()->getSettings()->execDryRunDefault);
});

it('returns the effective command allowlist (defaults + active runtime overrides)', function() {
    $result = $this->tool->execute([]);

    expect($result['allowlist'])->toBeArray()->not->toBeEmpty();
    expect($result['allowlist'])->toBe(Cortex::getInstance()->allowlist->getEffective());
});

it('declares an outputSchema covering the full payload', function() {
    $schema = $this->tool::outputSchema();

    expect($schema)->toBeArray()->not->toBeEmpty();
    expect($schema['type'])->toBe('object');
    expect($schema['properties'])->toHaveKeys([
        'craft', 'sites', 'sections', 'elementTypes',
        'skillPrompts', 'exec', 'allowlist', 'hints',
    ]);
});

it('lists element types Craft knows about (at minimum entries / assets / users)', function() {
    $result = $this->tool->execute([]);

    expect($result['elementTypes'])->toBeArray()->not->toBeEmpty();

    $classes = array_map(static fn(array $t): string => $t['class'], $result['elementTypes']);
    expect($classes)->toContain(\craft\elements\Entry::class);
    expect($classes)->toContain(\craft\elements\Asset::class);
    expect($classes)->toContain(\craft\elements\User::class);
});

it('emits human-actionable hints so the LLM knows where to look next', function() {
    $result = $this->tool->execute([]);

    expect($result['hints'])->toBeArray()->not->toBeEmpty();
    foreach ($result['hints'] as $hint) {
        expect($hint)->toBeString()->not->toBeEmpty();
    }
});
