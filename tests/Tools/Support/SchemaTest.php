<?php

/**
 * =========================================================================
 * Schema DSL builder tests.
 *
 * Verifies the JSON Schema output matches the hand-rolled shapes that
 * the existing 28 tools produce today. Each test pair maps a builder
 * expression to an expected array literal — once retrofit is complete,
 * the tools' `getInputSchema()` returns exactly these shapes.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */

use craftpulse\herald\tools\support\Schema;

// -----------------------------------------------------------------------------
// Type entry points
// -----------------------------------------------------------------------------

it('builds string', function() {
    expect(Schema::string()->toArray())->toBe(['type' => 'string']);
});

it('builds integer', function() {
    expect(Schema::integer()->toArray())->toBe(['type' => 'integer']);
});

it('builds number', function() {
    expect(Schema::number()->toArray())->toBe(['type' => 'number']);
});

it('builds boolean', function() {
    expect(Schema::boolean()->toArray())->toBe(['type' => 'boolean']);
});

it('builds null', function() {
    expect(Schema::null()->toArray())->toBe(['type' => 'null']);
});

it('builds an untyped any() schema', function() {
    expect(Schema::any()->toArray())->toBe([]);
    expect(Schema::any()->description('foo')->toArray())->toBe(['description' => 'foo']);
});

// -----------------------------------------------------------------------------
// String modifiers
// -----------------------------------------------------------------------------

it('attaches description, enum, format, pattern, length bounds, default', function() {
    $schema = Schema::string()
        ->description('hello')
        ->enum(['a', 'b', 'c'])
        ->format('email')
        ->pattern('^[a-z]+$')
        ->minLength(1)
        ->maxLength(64)
        ->default('a');

    expect($schema->toArray())->toBe([
        'type' => 'string',
        'description' => 'hello',
        'enum' => ['a', 'b', 'c'],
        'default' => 'a',
        'format' => 'email',
        'pattern' => '^[a-z]+$',
        'minLength' => 1,
        'maxLength' => 64,
    ]);
});

it('rejects empty enums', function() {
    Schema::string()->enum([]);
})->throws(InvalidArgumentException::class);

// -----------------------------------------------------------------------------
// Numeric bounds
// -----------------------------------------------------------------------------

it('applies numeric minimum and maximum', function() {
    expect(Schema::integer()->minimum(1)->maximum(1000)->toArray())->toBe([
        'type' => 'integer',
        'minimum' => 1,
        'maximum' => 1000,
    ]);

    expect(Schema::number()->minimum(0.5)->maximum(1.5)->toArray())->toBe([
        'type' => 'number',
        'minimum' => 0.5,
        'maximum' => 1.5,
    ]);
});

// -----------------------------------------------------------------------------
// Arrays
// -----------------------------------------------------------------------------

it('builds an array of strings via items', function() {
    expect(Schema::array(Schema::string())->toArray())->toBe([
        'type' => 'array',
        'items' => ['type' => 'string'],
    ]);

    expect(Schema::array()->items(Schema::integer())->toArray())->toBe([
        'type' => 'array',
        'items' => ['type' => 'integer'],
    ]);
});

it('applies array length bounds and uniqueItems', function() {
    $schema = Schema::array(Schema::string())
        ->minItems(1)
        ->maxItems(10)
        ->uniqueItems();

    expect($schema->toArray())->toEqual([
        'type' => 'array',
        'items' => ['type' => 'string'],
        'minItems' => 1,
        'maxItems' => 10,
        'uniqueItems' => true,
    ]);
});

// -----------------------------------------------------------------------------
// Objects + property-level required
// -----------------------------------------------------------------------------

it('builds an empty object with additionalProperties false default', function() {
    expect(Schema::object()->toArray())->toEqual([
        'type' => 'object',
        'properties' => (object) [],
        'additionalProperties' => false,
    ]);
});

it('renders properties and bubbles required children up to the parent', function() {
    $schema = Schema::object([
        'expression' => Schema::string()->required()->description('Required.'),
        'confirm' => Schema::boolean()->description('Optional.'),
    ]);

    expect($schema->toArray())->toBe([
        'type' => 'object',
        'properties' => [
            'expression' => ['type' => 'string', 'description' => 'Required.'],
            'confirm' => ['type' => 'boolean', 'description' => 'Optional.'],
        ],
        'required' => ['expression'],
        'additionalProperties' => false,
    ]);
});

it('does not emit `required` when no children request it', function() {
    $schema = Schema::object([
        'handle' => Schema::string(),
        'count' => Schema::boolean(),
    ]);

    expect($schema->toArray())->not->toHaveKey('required');
});

it('honours additionalProperties true and explicit Schema', function() {
    $allowAny = Schema::object()->additionalProperties(true);
    expect($allowAny->toArray())->toEqual([
        'type' => 'object',
        'properties' => (object) [],
        'additionalProperties' => true,
    ]);

    $allowStrings = Schema::object()->additionalProperties(Schema::string());
    expect($allowStrings->toArray())->toEqual([
        'type' => 'object',
        'properties' => (object) [],
        'additionalProperties' => ['type' => 'string'],
    ]);
});

it('rejects non-string property keys and non-Schema property values', function() {
    expect(fn() => Schema::object(['' => Schema::string()]))->toThrow(InvalidArgumentException::class);
});

// -----------------------------------------------------------------------------
// Compositions
// -----------------------------------------------------------------------------

it('builds anyOf, oneOf, allOf', function() {
    $any = Schema::anyOf(Schema::string(), Schema::integer());
    expect($any->toArray())->toBe([
        'anyOf' => [['type' => 'string'], ['type' => 'integer']],
    ]);

    $one = Schema::oneOf(Schema::string(), Schema::integer());
    expect($one->toArray())->toBe([
        'oneOf' => [['type' => 'string'], ['type' => 'integer']],
    ]);

    $all = Schema::allOf(Schema::object(['a' => Schema::string()]), Schema::object(['b' => Schema::integer()]));
    expect($all->toArray())->toBe([
        'allOf' => [
            [
                'type' => 'object',
                'properties' => ['a' => ['type' => 'string']],
                'additionalProperties' => false,
            ],
            [
                'type' => 'object',
                'properties' => ['b' => ['type' => 'integer']],
                'additionalProperties' => false,
            ],
        ],
    ]);
});

it('builds not', function() {
    expect(Schema::not(Schema::string())->toArray())->toBe([
        'not' => ['type' => 'string'],
    ]);
});

it('rejects empty composition arguments', function() {
    expect(fn() => Schema::anyOf())->toThrow(InvalidArgumentException::class);
    expect(fn() => Schema::oneOf())->toThrow(InvalidArgumentException::class);
    expect(fn() => Schema::allOf())->toThrow(InvalidArgumentException::class);
});

// -----------------------------------------------------------------------------
// const
// -----------------------------------------------------------------------------

it('emits const literals including null', function() {
    expect(Schema::constant('fixed')->toArray())->toBe(['const' => 'fixed']);
    expect(Schema::constant(null)->toArray())->toBe(['const' => null]);
    expect(Schema::constant(42)->toArray())->toBe(['const' => 42]);
});

// -----------------------------------------------------------------------------
// Round-trip equivalence with existing tools' hand-rolled shapes
// -----------------------------------------------------------------------------

it('round-trips the Sites tool input schema', function() {
    $schema = Schema::object([
        'handle' => Schema::string(),
        'count' => Schema::boolean(),
    ]);

    expect($schema->toArray())->toBe([
        'type' => 'object',
        'properties' => [
            'handle' => ['type' => 'string'],
            'count' => ['type' => 'boolean'],
        ],
        'additionalProperties' => false,
    ]);
});

it('round-trips the Diagnostics tool input schema', function() {
    $schema = Schema::object([
        'type' => Schema::string()
            ->enum(['logs', 'last_error', 'deprecations', 'queue', 'project_config_diff'])
            ->description('Required.')
            ->required(),
        'channel' => Schema::string()
            ->description('Log file basename (e.g. "web", "queue"). Defaults to "web".'),
        'minLevel' => Schema::string()
            ->enum(['trace', 'info', 'warning', 'error'])
            ->description('Minimum severity to include. Defaults to "warning".'),
        'limit' => Schema::integer()->minimum(1)->maximum(500),
    ]);

    expect($schema->toArray())->toBe([
        'type' => 'object',
        'properties' => [
            'type' => [
                'type' => 'string',
                'description' => 'Required.',
                'enum' => ['logs', 'last_error', 'deprecations', 'queue', 'project_config_diff'],
            ],
            'channel' => [
                'type' => 'string',
                'description' => 'Log file basename (e.g. "web", "queue"). Defaults to "web".',
            ],
            'minLevel' => [
                'type' => 'string',
                'description' => 'Minimum severity to include. Defaults to "warning".',
                'enum' => ['trace', 'info', 'warning', 'error'],
            ],
            'limit' => [
                'type' => 'integer',
                'minimum' => 1,
                'maximum' => 500,
            ],
        ],
        'required' => ['type'],
        'additionalProperties' => false,
    ]);
});

it('round-trips the CraftExec tool input schema with required at object level', function() {
    $schema = Schema::object([
        'expression' => Schema::string()
            ->required()
            ->description('PHP expression to evaluate. `<?php` prefix optional. Required.'),
        'confirm' => Schema::boolean()
            ->description('Required to actually evaluate (otherwise returns dry-run analysis).'),
        'dangerous' => Schema::boolean()
            ->description('Required in addition to `confirm` for destructive expressions.'),
    ]);

    expect($schema->toArray())->toBe([
        'type' => 'object',
        'properties' => [
            'expression' => [
                'type' => 'string',
                'description' => 'PHP expression to evaluate. `<?php` prefix optional. Required.',
            ],
            'confirm' => [
                'type' => 'boolean',
                'description' => 'Required to actually evaluate (otherwise returns dry-run analysis).',
            ],
            'dangerous' => [
                'type' => 'boolean',
                'description' => 'Required in addition to `confirm` for destructive expressions.',
            ],
        ],
        'required' => ['expression'],
        'additionalProperties' => false,
    ]);
});

it('round-trips the Entries tool input schema with polymorphic any() properties', function() {
    $schema = Schema::object([
        'id' => Schema::any()->description('Single entry id, or array of ids.'),
        'with' => Schema::array(Schema::string())
            ->description('Eager-loaded relational field handles.'),
        'limit' => Schema::integer()->minimum(1)->maximum(1000),
    ]);

    expect($schema->toArray())->toBe([
        'type' => 'object',
        'properties' => [
            'id' => ['description' => 'Single entry id, or array of ids.'],
            'with' => [
                'type' => 'array',
                'description' => 'Eager-loaded relational field handles.',
                'items' => ['type' => 'string'],
            ],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1000],
        ],
        'additionalProperties' => false,
    ]);
});

// -----------------------------------------------------------------------------
// Nested objects
// -----------------------------------------------------------------------------

it('handles nested objects with their own required arrays', function() {
    $schema = Schema::object([
        'outer' => Schema::object([
            'inner' => Schema::string()->required(),
        ])->required(),
    ]);

    expect($schema->toArray())->toBe([
        'type' => 'object',
        'properties' => [
            'outer' => [
                'type' => 'object',
                'properties' => [
                    'inner' => ['type' => 'string'],
                ],
                'required' => ['inner'],
                'additionalProperties' => false,
            ],
        ],
        'required' => ['outer'],
        'additionalProperties' => false,
    ]);
});


// -----------------------------------------------------------------------------
// examples
// -----------------------------------------------------------------------------

it('emits examples in toArray output', function() {
    $schema = Schema::string()->examples(['small', 'medium', 'large']);

    expect($schema->toArray())->toBe([
        'type' => 'string',
        'examples' => ['small', 'medium', 'large'],
    ]);
});

it('rejects an empty examples list', function() {
    Schema::string()->examples([]);
})->throws(InvalidArgumentException::class, 'at least one value');
