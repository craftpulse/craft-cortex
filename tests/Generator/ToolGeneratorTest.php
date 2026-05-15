<?php

/**
 * =========================================================================
 * Smoke tests for the cortex-tool generator.
 *
 * Interactive code generation is hard to test end-to-end without a TTY,
 * so this suite asserts only the registration contract: cortex registers
 * the generator on Craft's `make` command, the generator declares the
 * expected CLI name and description, and the class satisfies the
 * `BaseGenerator` parent contract. Manual smoke through
 * `ddev craft make cortex-tool` covers the actual code-output path.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */

use craft\generator\BaseGenerator;
use craft\generator\Command;
use craftpulse\cortex\generator\Tool as ToolGenerator;
use yii\base\Event;

it('extends BaseGenerator', function() {
    expect(is_subclass_of(ToolGenerator::class, BaseGenerator::class))->toBeTrue();
});

it('exposes the cortex-tool CLI name', function() {
    expect(ToolGenerator::name())->toBe('cortex-tool');
});

it('exposes a non-empty description', function() {
    expect(ToolGenerator::description())->toBeString()->not->toBeEmpty();
});

it('is registered on the make command via EVENT_REGISTER_GENERATORS', function() {
    // Cortex::init() fires the registration on boot. The Yii Event class
    // tracks listeners on the class itself so we can verify by
    // simulating the event and checking that ToolGenerator appears in
    // the resulting types list.
    $event = new \craft\events\RegisterComponentTypesEvent();
    Event::trigger(Command::class, Command::EVENT_REGISTER_GENERATORS, $event);

    expect($event->types)->toContain(ToolGenerator::class);
});
