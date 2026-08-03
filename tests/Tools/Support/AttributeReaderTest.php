<?php

/**
 * =========================================================================
 * AttributeReader tests — verify reflection-based annotation extraction
 * matches the MCP `ToolAnnotations` payload shape.
 * =========================================================================
 *
 * @author CraftPulse
 * @since  5.0.0
 */

use craftpulse\herald\attributes\IsDestructive;
use craftpulse\herald\attributes\IsIdempotent;
use craftpulse\herald\attributes\IsOpenWorld;
use craftpulse\herald\attributes\IsReadOnly;
use craftpulse\herald\attributes\IsStdioOnly;
use craftpulse\herald\attributes\Title;
use craftpulse\herald\tools\AbstractTool;
use craftpulse\herald\tools\content\Entries;
use craftpulse\herald\tools\dev\StreamingFixtureTool;
use craftpulse\herald\tools\support\AttributeReader;

// -----------------------------------------------------------------------------
// Test fixtures — minimal anonymous-ish tool classes
// -----------------------------------------------------------------------------

#[IsReadOnly]
#[IsIdempotent]
#[Title('Plain Read Tool')]
class _AttrReaderReadOnlyFixture extends AbstractTool
{
    public static function getName(): string
    {
        return '_attr_reader_readonly_fixture';
    }

    public static function getDescription(): string
    {
        return 'fixture';
    }

    public function execute(array $arguments): array
    {
        return [];
    }
}

#[IsDestructive]
#[IsIdempotent(false)]
#[IsOpenWorld(false)]
#[IsStdioOnly]
#[Title('Dangerous Eval Tool')]
class _AttrReaderDestructiveFixture extends AbstractTool
{
    public static function getName(): string
    {
        return '_attr_reader_destructive_fixture';
    }

    public static function getDescription(): string
    {
        return 'fixture';
    }

    public function execute(array $arguments): array
    {
        return [];
    }
}

class _AttrReaderUnannotatedFixture extends AbstractTool
{
    public static function getName(): string
    {
        return '_attr_reader_unannotated_fixture';
    }

    public static function getDescription(): string
    {
        return 'fixture';
    }

    public function execute(array $arguments): array
    {
        return [];
    }
}

// -----------------------------------------------------------------------------
// annotationsFor()
// -----------------------------------------------------------------------------

it('reads readOnlyHint, idempotentHint, and title from a read-only fixture', function() {
    $annotations = AttributeReader::annotationsFor(_AttrReaderReadOnlyFixture::class);

    expect($annotations)->toBe([
        'readOnlyHint' => true,
        'idempotentHint' => true,
        'title' => 'Plain Read Tool',
    ]);
});

it('reads destructive + explicit-false flags from a destructive fixture', function() {
    $annotations = AttributeReader::annotationsFor(_AttrReaderDestructiveFixture::class);

    expect($annotations)->toBe([
        'destructiveHint' => true,
        'idempotentHint' => false,
        'openWorldHint' => false,
        'title' => 'Dangerous Eval Tool',
    ]);
});

it('returns an empty array when no attributes are declared', function() {
    expect(AttributeReader::annotationsFor(_AttrReaderUnannotatedFixture::class))->toBe([]);
});

it('accepts a tool instance as well as a class string', function() {
    $instance = new _AttrReaderReadOnlyFixture();

    expect(AttributeReader::annotationsFor($instance))
        ->toBe(AttributeReader::annotationsFor(_AttrReaderReadOnlyFixture::class));
});

// -----------------------------------------------------------------------------
// isStdioOnly()
// -----------------------------------------------------------------------------

it('reports stdio-only true when the attribute is present', function() {
    expect(AttributeReader::isStdioOnly(_AttrReaderDestructiveFixture::class))->toBeTrue();
});

it('reports stdio-only false when the attribute is absent', function() {
    expect(AttributeReader::isStdioOnly(_AttrReaderReadOnlyFixture::class))->toBeFalse();
    expect(AttributeReader::isStdioOnly(_AttrReaderUnannotatedFixture::class))->toBeFalse();
});

// -----------------------------------------------------------------------------
// Real tools — verify the migrated dev tools still surface their annotations
// -----------------------------------------------------------------------------

it('reads CraftExec annotations from class-level attributes', function() {
    $annotations = AttributeReader::annotationsFor(\craftpulse\herald\tools\dev\CraftExec::class);

    expect($annotations)->toBe([
        'destructiveHint' => true,
        'idempotentHint' => false,
        'openWorldHint' => false,
        'title' => 'Evaluate Craft Expression',
    ]);
    expect(AttributeReader::isStdioOnly(\craftpulse\herald\tools\dev\CraftExec::class))->toBeTrue();
});

it('reads CraftCommand annotations from class-level attributes', function() {
    $annotations = AttributeReader::annotationsFor(\craftpulse\herald\tools\dev\CraftCommand::class);

    expect($annotations)->toBe([
        'destructiveHint' => true,
        'idempotentHint' => false,
        'openWorldHint' => false,
        'title' => 'Run Craft Command',
    ]);
    expect(AttributeReader::isStdioOnly(\craftpulse\herald\tools\dev\CraftCommand::class))->toBeFalse();
});

it('reads Resave annotations from class-level attributes', function() {
    $annotations = AttributeReader::annotationsFor(\craftpulse\herald\tools\dev\Resave::class);

    expect($annotations)->toBe([
        'destructiveHint' => true,
        'idempotentHint' => true,
        'openWorldHint' => false,
        'title' => 'Resave Elements',
    ]);
});

// -----------------------------------------------------------------------------
// Memoization
// -----------------------------------------------------------------------------

it('memoizes annotation reads per class', function() {
    // `tools/list` is the first request every client makes, and it reads
    // annotations once per tool. Un-memoized that is five
    // `ReflectionClass` constructions plus five attribute
    // `newInstance()` calls per tool per list.
    //
    // Asserted by poisoning the cache rather than by counting
    // reflections: a sentinel that survives the call proves the cache is
    // consulted, not merely populated.
    $cache = new ReflectionProperty(AttributeReader::class, '_annotationsCache');

    /** @var array<class-string,array<string,bool|string>> $original */
    $original = $cache->getValue();

    try {
        $cache->setValue(null, []);

        $first = AttributeReader::annotationsFor(Entries::class);
        expect($first)->toBe(['readOnlyHint' => true, 'idempotentHint' => true]);

        /** @var array<class-string,array<string,bool|string>> $populated */
        $populated = $cache->getValue();
        expect($populated)->toHaveKey(Entries::class);

        $cache->setValue(null, [Entries::class => ['title' => 'sentinel']]);
        expect(AttributeReader::annotationsFor(Entries::class))->toBe(['title' => 'sentinel']);
    } finally {
        $cache->setValue(null, $original);
    }
});

it('memoizes an empty annotation set too', function() {
    // A tool declaring no attributes must stop re-reflecting as well,
    // which needs an `array_key_exists()` cache probe rather than a
    // truthiness check.
    $cache = new ReflectionProperty(AttributeReader::class, '_annotationsCache');

    /** @var array<class-string,array<string,bool|string>> $original */
    $original = $cache->getValue();

    try {
        $cache->setValue(null, []);

        expect(AttributeReader::annotationsFor(StreamingFixtureTool::class))->toBe([]);

        /** @var array<class-string,array<string,bool|string>> $populated */
        $populated = $cache->getValue();
        expect($populated)->toHaveKey(StreamingFixtureTool::class);
    } finally {
        $cache->setValue(null, $original);
    }
});
