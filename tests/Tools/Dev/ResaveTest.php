<?php

/**
 * @author Craftpulse
 * @since  0.1.0
 */

use craftpulse\cortex\Plugin;
use craftpulse\cortex\tools\ToolException;

beforeEach(function () {
    $this->tool = Plugin::getInstance()->tools->getByName('resave');
});

it('throws when type is missing', function () {
    $this->tool->execute([]);
})->throws(ToolException::class, '`type` is required');

it('throws on unknown type', function () {
    $this->tool->execute(['type' => 'sandwiches']);
})->throws(ToolException::class, "Unknown type 'sandwiches'");

it('rejects options that do not apply to the chosen type', function () {
    // `volume` is for assets — passing it with `type: tags` must fail before
    // reaching the controller, so the AI sees a clear validation error.
    $this->tool->execute(['type' => 'tags', 'volume' => 'images']);
})->throws(ToolException::class, "Option(s) not supported for type 'tags'");

it('requires set and to to be provided together', function () {
    $this->tool->execute(['type' => 'entries', 'set' => 'titleField']);
})->throws(ToolException::class, '`set` and `to` must be provided together.');

it('dispatches resave/entries with mapped options and captures output', function () {
    $result = $this->tool->execute([
        'type' => 'entries',
        'section' => '*',
        'limit' => 1,
    ]);

    expect($result)->toHaveKey('type', 'entries');
    expect($result)->toHaveKey('route', 'resave/entries');
    expect($result['options'])->toMatchArray([
        'section' => '*',
        'limit' => 1,
    ]);
    expect($result)->toHaveKey('exitCode');
    expect($result['exitCode'])->toBeIn([0, 1]);

    // Output is a string (possibly empty when the playground has no entries),
    // never a raw resource leak. The captured channel is suppressed from
    // stdout — this is what proves stdout-capture works.
    expect($result)->toHaveKey('output');
    expect($result['output'])->toBeString();
});

it('renames entryType to the controller property `type`', function () {
    // ResaveController binds `--type` to `$type` for entry-type filtering.
    // We accept `entryType` from the AI (since `type` is already taken at
    // the tool level) and translate it.
    $result = $this->tool->execute([
        'type' => 'entries',
        'entryType' => 'noSuchEntryType',
        'limit' => 1,
    ]);

    expect($result['options'])->toHaveKey('type', 'noSuchEntryType');
    expect($result['options'])->not->toHaveKey('entryType');
});

it('exposes destructiveHint and idempotentHint annotations', function () {
    $annotations = \craftpulse\cortex\tools\support\AttributeReader::annotationsFor($this->tool);
    expect($annotations)->toHaveKey('destructiveHint', true);
    expect($annotations)->toHaveKey('idempotentHint', true);
});
