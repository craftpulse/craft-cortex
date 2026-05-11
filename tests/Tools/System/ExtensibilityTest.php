<?php

/**
 * @author Craftpulse
 * @since  5.0.0
 */

use craftpulse\cortex\Plugin;

beforeEach(function() {
    $this->tool = Plugin::getInstance()->tools->getByName('extensibility');
});

it('returns all four sections by default', function() {
    $result = $this->tool->execute([]);

    expect($result)->toHaveKeys(['events', 'twig', 'utilities', 'commands']);
    expect($result['events'])->toBeArray();
    expect($result['utilities'])->toBeArray();
    expect($result['commands'])->toBeArray();
});

it('twig section reports functions, filters, globals, and craft.* methods', function() {
    $result = $this->tool->execute(['mode' => 'twig']);

    expect($result['twig'])->toHaveKeys(['functions', 'filters', 'globals', 'craftVariableMethods']);
    expect($result['twig']['functions'])->toBeArray()->not->toBeEmpty();
    expect($result['twig']['filters'])->toBeArray()->not->toBeEmpty();
});

it('utilities section returns class / id / displayName per utility', function() {
    $result = $this->tool->execute(['mode' => 'utilities']);

    expect($result['utilities'])->toBeArray()->not->toBeEmpty();
    foreach ($result['utilities'] as $u) {
        expect($u)->toHaveKeys(['class', 'id', 'displayName']);
    }
});
