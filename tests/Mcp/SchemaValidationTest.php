<?php

/**
 * =========================================================================
 * Tests for remediation item 1.1 — tool arguments are validated against
 * their declared JSON Schema at the dispatcher.
 *
 * `Server::_validateToolCall()` checked only `is_array($arguments)`
 * before 2026-08-02, which made every schema in the plugin decorative:
 * a declared enum, a declared type, a declared `required`, and the
 * `additionalProperties: false` the `Schema` DSL applies by default were
 * all unenforced. This is the root cause behind the `orderBy` injection
 * (item 1.2), and every future tool inherited it.
 *
 * Two layers are covered:
 *
 *   - `SchemaValidator` in isolation, keyword by keyword.
 *   - The dispatcher gate: a `tools/call` that violates the schema comes
 *     back as a tool-error envelope (`isError: true`) at the JSON-RPC
 *     success level, per SEP-1303, and the tool never runs.
 * =========================================================================
 *
 * @author CraftPulse
 * @since  5.0.0
 */

use craftpulse\herald\mcp\Server;
use craftpulse\herald\tools\support\Schema;
use craftpulse\herald\tools\support\SchemaValidator;

beforeEach(function() {
    $this->server = new Server();
});

// -----------------------------------------------------------------------------
// SchemaValidator — types
// -----------------------------------------------------------------------------

it('accepts a conforming object', function() {
    $schema = Schema::object([
        'handle' => Schema::string()->required(),
        'count' => Schema::boolean(),
    ])->toArray();

    expect(SchemaValidator::validate($schema, ['handle' => 'news', 'count' => true]))->toBe([]);
});

it('rejects a wrong scalar type', function() {
    $schema = Schema::object(['handle' => Schema::string()])->toArray();

    $errors = SchemaValidator::validate($schema, ['handle' => ['news']]);

    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('arguments.handle')->toContain('string');
});

it('rejects a missing required property', function() {
    $schema = Schema::object(['handle' => Schema::string()->required()])->toArray();

    $errors = SchemaValidator::validate($schema, []);

    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('arguments.handle is required');
});

it('rejects an undeclared property when additionalProperties is false', function() {
    $schema = Schema::object(['handle' => Schema::string()])->toArray();

    $errors = SchemaValidator::validate($schema, ['handle' => 'news', 'evil' => 1]);

    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('arguments.evil is not a recognised argument');
});

it('admits an undeclared property when additionalProperties is true', function() {
    $schema = Schema::object(['options' => Schema::object()->additionalProperties(true)])->toArray();

    expect(SchemaValidator::validate($schema, ['options' => ['anything' => 'goes']]))->toBe([]);
});

it('tolerates a numeric string where an integer is declared', function() {
    // Documented, deliberate: LLM clients stringify numbers, the tools
    // already cast, and the tolerance is type-shaped so it cannot carry
    // a value the declared type would have refused.
    $schema = Schema::object(['limit' => Schema::integer()])->toArray();

    expect(SchemaValidator::validate($schema, ['limit' => '25']))->toBe([]);
});

it('still rejects a non-numeric string where an integer is declared', function() {
    $schema = Schema::object(['limit' => Schema::integer()])->toArray();

    expect(SchemaValidator::validate($schema, ['limit' => 'lots']))->not->toBe([]);
});

// -----------------------------------------------------------------------------
// SchemaValidator — value constraints
// -----------------------------------------------------------------------------

it('enforces an enum', function() {
    $schema = Schema::object(['mode' => Schema::string()->enum(['list', 'run'])])->toArray();

    $errors = SchemaValidator::validate($schema, ['mode' => 'rm-rf']);

    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('must be one of')->toContain('list');
});

it('enforces numeric bounds', function() {
    $schema = Schema::object(['limit' => Schema::integer()->minimum(1)->maximum(1000)])->toArray();

    expect(SchemaValidator::validate($schema, ['limit' => 0]))->not->toBe([]);
    expect(SchemaValidator::validate($schema, ['limit' => 5000]))->not->toBe([]);
    expect(SchemaValidator::validate($schema, ['limit' => 100]))->toBe([]);
});

it('enforces string length and pattern', function() {
    $schema = Schema::object([
        'handle' => Schema::string()->minLength(2)->maxLength(6)->pattern('^[a-z]+$'),
    ])->toArray();

    expect(SchemaValidator::validate($schema, ['handle' => 'a']))->not->toBe([]);
    expect(SchemaValidator::validate($schema, ['handle' => 'abcdefgh']))->not->toBe([]);
    expect(SchemaValidator::validate($schema, ['handle' => 'ABC']))->not->toBe([]);
    expect(SchemaValidator::validate($schema, ['handle' => 'news']))->toBe([]);
});

it('enforces item schemas, bounds and uniqueness on lists', function() {
    $schema = Schema::object([
        'with' => Schema::array(Schema::string())->minItems(1)->maxItems(3)->uniqueItems(),
    ])->toArray();

    expect(SchemaValidator::validate($schema, ['with' => ['a', 'b']]))->toBe([]);
    expect(SchemaValidator::validate($schema, ['with' => []]))->not->toBe([]);
    expect(SchemaValidator::validate($schema, ['with' => ['a', 'a']]))->not->toBe([]);
    expect(SchemaValidator::validate($schema, ['with' => ['a', 1]]))->not->toBe([]);
});

it('validates nested objects down the path', function() {
    $schema = Schema::object([
        'filters' => Schema::object(['status' => Schema::string()->enum(['live'])]),
    ])->toArray();

    $errors = SchemaValidator::validate($schema, ['filters' => ['status' => 'nope']]);

    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('arguments.filters.status');
});

// -----------------------------------------------------------------------------
// SchemaValidator — compositions
// -----------------------------------------------------------------------------

it('enforces anyOf', function() {
    $schema = Schema::object([
        'id' => Schema::anyOf(Schema::integer(), Schema::array(Schema::integer())),
    ])->toArray();

    expect(SchemaValidator::validate($schema, ['id' => 7]))->toBe([]);
    expect(SchemaValidator::validate($schema, ['id' => [7, 8]]))->toBe([]);
    expect(SchemaValidator::validate($schema, ['id' => ['nope' => true]]))->not->toBe([]);
});

it('leaves an untyped any() schema unconstrained', function() {
    $schema = Schema::object(['relatedTo' => Schema::any()])->toArray();

    expect(SchemaValidator::validate($schema, ['relatedTo' => 12]))->toBe([]);
    expect(SchemaValidator::validate($schema, ['relatedTo' => ['targetElement' => 12]]))->toBe([]);
});

it('ignores annotation-only keywords', function() {
    $schema = Schema::object([
        'handle' => Schema::string()->description('A handle.')->default('news')->examples(['news']),
    ])->toArray();

    expect(SchemaValidator::validate($schema, ['handle' => 'blog']))->toBe([]);
});

it('caps how many errors one run reports', function() {
    $properties = [];
    $payload = [];
    for ($i = 0; $i < SchemaValidator::MAX_ERRORS + 5; $i++) {
        $properties["f{$i}"] = Schema::string();
        $payload["f{$i}"] = $i;
    }

    $errors = SchemaValidator::validate(Schema::object($properties)->toArray(), $payload);

    expect($errors)->toHaveCount(SchemaValidator::MAX_ERRORS);
});

// -----------------------------------------------------------------------------
// The dispatcher gate
// -----------------------------------------------------------------------------

it('refuses a tools/call whose arguments violate the declared enum', function() {
    $response = $this->server->dispatch([
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => [
            'name' => 'craft_command',
            'arguments' => ['mode' => 'not-a-mode'],
        ],
    ]);

    expect($response)->toHaveKey('result');
    expect($response['result'])->toBeMcpErrorEnvelope();
    expect($response['result']['content'][0]['text'])
        ->toContain('Invalid arguments for tool `craft_command`')
        ->toContain('must be one of');
});

it('refuses a tools/call carrying an argument the tool never declared', function() {
    $response = $this->server->dispatch([
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => [
            'name' => 'sections',
            'arguments' => ['handle' => 'news', 'dropTables' => true],
        ],
    ]);

    expect($response)->toHaveKey('result');
    expect($response['result'])->toBeMcpErrorEnvelope();
    expect($response['result']['content'][0]['text'])->toContain('dropTables');
});

it('refuses a tools/call whose argument is the wrong type', function() {
    $response = $this->server->dispatch([
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => [
            'name' => 'entries',
            'arguments' => ['limit' => 'all of them'],
        ],
    ]);

    expect($response)->toHaveKey('result');
    expect($response['result'])->toBeMcpErrorEnvelope();
    expect($response['result']['content'][0]['text'])->toContain('arguments.limit');
});

it('still dispatches a conforming tools/call', function() {
    $response = $this->server->dispatch([
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => [
            'name' => 'entries',
            'arguments' => ['limit' => 1, 'count' => true],
        ],
    ]);

    expect($response)->toHaveKey('result');
    expect($response['result'])->toBeMcpSuccessEnvelope();
});
