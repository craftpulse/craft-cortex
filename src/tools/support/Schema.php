<?php

namespace craftpulse\cortex\tools\support;

use InvalidArgumentException;

/**
 * =========================================================================
 * Fluent JSON Schema builder for tool input schemas.
 *
 * Tools author their `getInputSchema()` payload through this class
 * instead of hand-rolling array literals. The output is the same JSON
 * Schema shape MCP clients expect — every method maps to a JSON Schema
 * keyword and `toArray()` is the wire-format output.
 *
 * Two layers of API:
 *   - Static entry points (`Schema::string()`, `Schema::object([...])`,
 *     `Schema::any()`, `Schema::anyOf(...)`) return a builder
 *     pre-populated for the requested type.
 *   - Fluent setters (`->description()`, `->enum()`, `->required()`,
 *     `->minimum()`, etc.) modify the builder and return `$this` for
 *     chaining.
 *
 * Property-level `->required()` on an object child is collected by the
 * parent object's `toArray()` into the JSON Schema `required` array.
 * Standalone `required()` (not inside an object) is silently ignored on
 * output — it's a hint, not a constraint on the node itself.
 *
 * Object schemas default to `additionalProperties: false` to match the
 * pre-DSL behaviour. Pass `true` or another `Schema` to override.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */
final class Schema
{
    // Constants
    // =========================================================================

    public const TYPE_STRING = 'string';
    public const TYPE_INTEGER = 'integer';
    public const TYPE_NUMBER = 'number';
    public const TYPE_BOOLEAN = 'boolean';
    public const TYPE_ARRAY = 'array';
    public const TYPE_OBJECT = 'object';
    public const TYPE_NULL = 'null';

    // Properties
    // =========================================================================

    /**
     * @author Craftpulse
     * @since  5.0.0
     */
    private ?string $_type = null;

    /**
     * @author Craftpulse
     * @since  5.0.0
     */
    private ?string $_description = null;

    /**
     * @var list<mixed>|null
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private ?array $_enum = null;

    /**
     * @author Craftpulse
     * @since  5.0.0
     */
    private bool $_required = false;

    /**
     * @author Craftpulse
     * @since  5.0.0
     */
    private mixed $_default = null;

    /**
     * @author Craftpulse
     * @since  5.0.0
     */
    private bool $_hasDefault = false;

    /**
     * @author Craftpulse
     * @since  5.0.0
     */
    private mixed $_const = null;

    /**
     * @author Craftpulse
     * @since  5.0.0
     */
    private bool $_hasConst = false;

    /**
     * @author Craftpulse
     * @since  5.0.0
     */
    private ?string $_format = null;

    /**
     * @author Craftpulse
     * @since  5.0.0
     */
    private ?string $_pattern = null;

    /**
     * @author Craftpulse
     * @since  5.0.0
     */
    private ?int $_minLength = null;

    /**
     * @author Craftpulse
     * @since  5.0.0
     */
    private ?int $_maxLength = null;

    /**
     * @author Craftpulse
     * @since  5.0.0
     */
    private int|float|null $_minimum = null;

    /**
     * @author Craftpulse
     * @since  5.0.0
     */
    private int|float|null $_maximum = null;

    /**
     * @author Craftpulse
     * @since  5.0.0
     */
    private ?int $_minItems = null;

    /**
     * @author Craftpulse
     * @since  5.0.0
     */
    private ?int $_maxItems = null;

    /**
     * @author Craftpulse
     * @since  5.0.0
     */
    private ?bool $_uniqueItems = null;

    /**
     * @author Craftpulse
     * @since  5.0.0
     */
    private ?self $_items = null;

    /**
     * @var array<string,self>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private array $_properties = [];

    /**
     * @author Craftpulse
     * @since  5.0.0
     */
    private bool|self $_additionalProperties = false;

    /**
     * Whether `additionalProperties` was explicitly set. Distinguishes
     * "default false" from "user set false". Object types always emit
     * `additionalProperties` in the output; non-object types only emit
     * if explicitly set.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private bool $_additionalPropertiesExplicit = false;

    /**
     * @var list<mixed>|null
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private ?array $_examples = null;

    /**
     * @var list<self>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private array $_anyOf = [];

    /**
     * @var list<self>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private array $_oneOf = [];

    /**
     * @var list<self>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private array $_allOf = [];

    /**
     * @author Craftpulse
     * @since  5.0.0
     */
    private ?self $_not = null;

    // Static — Entry Points
    // =========================================================================

    /**
     * Build a `string` schema.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public static function string(): self
    {
        return (new self())->_setType(self::TYPE_STRING);
    }

    /**
     * Build an `integer` schema.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public static function integer(): self
    {
        return (new self())->_setType(self::TYPE_INTEGER);
    }

    /**
     * Build a `number` schema (float / int).
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public static function number(): self
    {
        return (new self())->_setType(self::TYPE_NUMBER);
    }

    /**
     * Build a `boolean` schema.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public static function boolean(): self
    {
        return (new self())->_setType(self::TYPE_BOOLEAN);
    }

    /**
     * Build a `null` schema.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public static function null(): self
    {
        return (new self())->_setType(self::TYPE_NULL);
    }

    /**
     * Build an `array` schema, optionally with an item schema.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public static function array(?self $items = null): self
    {
        $schema = (new self())->_setType(self::TYPE_ARRAY);
        if ($items !== null) {
            $schema->_items = $items;
        }
        return $schema;
    }

    /**
     * Build an `object` schema, optionally with a property map. Property
     * children that have `->required()` set bubble up into the object's
     * `required` array.
     *
     * @param array<string,self> $properties
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public static function object(array $properties = []): self
    {
        $schema = (new self())->_setType(self::TYPE_OBJECT);
        foreach ($properties as $name => $property) {
            if (!is_string($name) || $name === '') {
                throw new InvalidArgumentException('Schema::object() property keys must be non-empty strings.');
            }
            if (!$property instanceof self) {
                throw new InvalidArgumentException("Schema::object() property '{$name}' must be a Schema instance.");
            }
            $schema->_properties[$name] = $property;
        }
        return $schema;
    }

    /**
     * Build an untyped schema. Useful for polymorphic Craft query params
     * (`id`, `uid`, `relatedTo`, `section`) where the underlying API
     * accepts mixed shapes (single id, array of ids, hash, …).
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public static function any(): self
    {
        return new self();
    }

    /**
     * Build a `const` schema — value must equal the given literal.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public static function constant(mixed $value): self
    {
        $schema = new self();
        $schema->_const = $value;
        $schema->_hasConst = true;
        return $schema;
    }

    /**
     * Build an `anyOf` composition — value must match at least one of
     * the given schemas.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public static function anyOf(self ...$schemas): self
    {
        if ($schemas === []) {
            throw new InvalidArgumentException('Schema::anyOf() requires at least one schema.');
        }
        $schema = new self();
        $schema->_anyOf = array_values($schemas);
        return $schema;
    }

    /**
     * Build a `oneOf` composition — value must match exactly one of
     * the given schemas.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public static function oneOf(self ...$schemas): self
    {
        if ($schemas === []) {
            throw new InvalidArgumentException('Schema::oneOf() requires at least one schema.');
        }
        $schema = new self();
        $schema->_oneOf = array_values($schemas);
        return $schema;
    }

    /**
     * Build an `allOf` composition — value must match all of the given
     * schemas.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public static function allOf(self ...$schemas): self
    {
        if ($schemas === []) {
            throw new InvalidArgumentException('Schema::allOf() requires at least one schema.');
        }
        $schema = new self();
        $schema->_allOf = array_values($schemas);
        return $schema;
    }

    /**
     * Build a `not` composition — value must NOT match the given schema.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public static function not(self $schema): self
    {
        $node = new self();
        $node->_not = $schema;
        return $node;
    }

    // Public Methods — Fluent Setters
    // =========================================================================

    /**
     * Attach a human-readable description shown in `tools/list`.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function description(string $value): self
    {
        $this->_description = $value;
        return $this;
    }

    /**
     * Mark this schema as required when used as a child property of an
     * object. Standalone, this flag is silently ignored on output.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function required(): self
    {
        $this->_required = true;
        return $this;
    }

    /**
     * Set a default value advertised to clients.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function default(mixed $value): self
    {
        $this->_default = $value;
        $this->_hasDefault = true;
        return $this;
    }

    /**
     * Constrain the value to one of an enum list.
     *
     * @param list<mixed> $values
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function enum(array $values): self
    {
        if ($values === []) {
            throw new InvalidArgumentException('Schema::enum() requires at least one value.');
        }
        $this->_enum = array_values($values);
        return $this;
    }

    /**
     * Set the JSON Schema `format` keyword (e.g. `email`, `uri`,
     * `date-time`).
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function format(string $value): self
    {
        $this->_format = $value;
        return $this;
    }

    /**
     * Set a regex `pattern` constraint (string types).
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function pattern(string $value): self
    {
        $this->_pattern = $value;
        return $this;
    }

    /**
     * Constrain string length to a minimum.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function minLength(int $value): self
    {
        $this->_minLength = $value;
        return $this;
    }

    /**
     * Constrain string length to a maximum.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function maxLength(int $value): self
    {
        $this->_maxLength = $value;
        return $this;
    }

    /**
     * Constrain numeric value to a minimum (inclusive).
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function minimum(int|float $value): self
    {
        $this->_minimum = $value;
        return $this;
    }

    /**
     * Constrain numeric value to a maximum (inclusive).
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function maximum(int|float $value): self
    {
        $this->_maximum = $value;
        return $this;
    }

    /**
     * Constrain array length to a minimum.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function minItems(int $value): self
    {
        $this->_minItems = $value;
        return $this;
    }

    /**
     * Constrain array length to a maximum.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function maxItems(int $value): self
    {
        $this->_maxItems = $value;
        return $this;
    }

    /**
     * Require array entries to be unique.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function uniqueItems(bool $value = true): self
    {
        $this->_uniqueItems = $value;
        return $this;
    }

    /**
     * Set the schema for array items (array types).
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function items(self $items): self
    {
        $this->_items = $items;
        return $this;
    }

    /**
     * Add or replace properties on an object schema.
     *
     * @param array<string,self> $properties
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function properties(array $properties): self
    {
        foreach ($properties as $name => $property) {
            if (!is_string($name) || $name === '') {
                throw new InvalidArgumentException('Schema::properties() keys must be non-empty strings.');
            }
            if (!$property instanceof self) {
                throw new InvalidArgumentException("Schema::properties() value '{$name}' must be a Schema instance.");
            }
            $this->_properties[$name] = $property;
        }
        return $this;
    }

    /**
     * Configure how additional (not declared) object properties are
     * handled. `false` (default) = reject; `true` = allow any; `Schema`
     * = validate against the given schema.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function additionalProperties(bool|self $value): self
    {
        $this->_additionalProperties = $value;
        $this->_additionalPropertiesExplicit = true;
        return $this;
    }

    /**
     * Set the JSON Schema `examples` keyword (a list of valid sample
     * values for this schema). LLMs use these to anchor reasoning about
     * what shapes the tool accepts. Empty list is rejected — pass
     * `null` semantics by simply not calling `examples()`.
     *
     * @param list<mixed> $values
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function examples(array $values): self
    {
        if ($values === []) {
            throw new InvalidArgumentException('Schema::examples() requires at least one value.');
        }
        $this->_examples = array_values($values);
        return $this;
    }

    // Public Methods — Introspection
    // =========================================================================

    /**
     * Whether this schema is marked required at the property level.
     * Used by parent objects to populate their `required` array.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function isRequired(): bool
    {
        return $this->_required;
    }

    // Public Methods — Output
    // =========================================================================

    /**
     * Render the JSON Schema array representation. This is what tools
     * return from `getInputSchema()` and what the dispatcher exposes in
     * `tools/list`.
     *
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function toArray(): array
    {
        $out = [];

        if ($this->_type !== null) {
            $out['type'] = $this->_type;
        }

        if ($this->_description !== null) {
            $out['description'] = $this->_description;
        }

        if ($this->_enum !== null) {
            $out['enum'] = $this->_enum;
        }

        if ($this->_hasConst) {
            $out['const'] = $this->_const;
        }

        if ($this->_hasDefault) {
            $out['default'] = $this->_default;
        }

        if ($this->_format !== null) {
            $out['format'] = $this->_format;
        }

        if ($this->_pattern !== null) {
            $out['pattern'] = $this->_pattern;
        }

        if ($this->_minLength !== null) {
            $out['minLength'] = $this->_minLength;
        }

        if ($this->_maxLength !== null) {
            $out['maxLength'] = $this->_maxLength;
        }

        if ($this->_minimum !== null) {
            $out['minimum'] = $this->_minimum;
        }

        if ($this->_maximum !== null) {
            $out['maximum'] = $this->_maximum;
        }

        if ($this->_minItems !== null) {
            $out['minItems'] = $this->_minItems;
        }

        if ($this->_maxItems !== null) {
            $out['maxItems'] = $this->_maxItems;
        }

        if ($this->_uniqueItems !== null) {
            $out['uniqueItems'] = $this->_uniqueItems;
        }

        if ($this->_items !== null) {
            $out['items'] = $this->_items->toArray();
        }

        if ($this->_type === self::TYPE_OBJECT) {
            // Object types always render `properties` (even if empty)
            // and `additionalProperties` (defaults to false). Required
            // children bubble up into the `required` array.
            $properties = [];
            $required = [];
            foreach ($this->_properties as $name => $property) {
                $properties[$name] = $property->toArray();
                if ($property->isRequired()) {
                    $required[] = $name;
                }
            }
            $out['properties'] = $properties === [] ? (object) [] : $properties;
            if ($required !== []) {
                $out['required'] = $required;
            }
            $out['additionalProperties'] = $this->_additionalProperties instanceof self
                ? $this->_additionalProperties->toArray()
                : $this->_additionalProperties;
        } elseif ($this->_additionalPropertiesExplicit) {
            $out['additionalProperties'] = $this->_additionalProperties instanceof self
                ? $this->_additionalProperties->toArray()
                : $this->_additionalProperties;
        }

        if ($this->_examples !== null) {
            $out['examples'] = $this->_examples;
        }

        if ($this->_anyOf !== []) {
            $out['anyOf'] = array_map(static fn(self $s): array => $s->toArray(), $this->_anyOf);
        }

        if ($this->_oneOf !== []) {
            $out['oneOf'] = array_map(static fn(self $s): array => $s->toArray(), $this->_oneOf);
        }

        if ($this->_allOf !== []) {
            $out['allOf'] = array_map(static fn(self $s): array => $s->toArray(), $this->_allOf);
        }

        if ($this->_not !== null) {
            $out['not'] = $this->_not->toArray();
        }

        return $out;
    }

    // Private Methods
    // =========================================================================

    /**
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _setType(string $type): self
    {
        $this->_type = $type;
        return $this;
    }
}
