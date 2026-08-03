<?php

namespace craftpulse\herald\tools\support;

/**
 * =========================================================================
 * JSON Schema validator for tool arguments.
 *
 * Every tool declares an input schema, and until the 2026-08-02
 * remediation the dispatcher checked only that the arguments were an
 * array — so every schema in the plugin was decorative. A tool declaring
 * `mode: enum[list, run]` received whatever the caller sent, and each
 * tool was left to re-derive its own validation (or not). This class is
 * the enforcement point `Server::_validateToolCall()` calls before a
 * tool ever sees an argument.
 *
 * **Scope: the dialect Herald's own `Schema` DSL emits**, which is the
 * only dialect its tools can produce. That is deliberate — a general
 * JSON Schema implementation would mean a new runtime dependency, and
 * validating keywords no schema in the plugin can express buys nothing.
 * Supported keywords:
 *
 *   `type` (single or list), `enum`, `const`, `required`, `properties`,
 *   `additionalProperties` (bool or schema), `items`, `minItems`,
 *   `maxItems`, `uniqueItems`, `minLength`, `maxLength`, `minimum`,
 *   `maximum`, `pattern`, `anyOf`, `oneOf`, `allOf`, `not`.
 *
 * Annotation keywords (`description`, `default`, `examples`, `format`,
 * `title`) carry no constraint and are ignored, as JSON Schema requires.
 * Any keyword outside both lists is also ignored rather than treated as
 * a failure — an unknown keyword must not make a valid document invalid.
 *
 * **Type checking is strict, with exactly one tolerance.** A numeric
 * string satisfies `integer` and `number`. LLM clients stringify numbers
 * constantly, the tools already cast through `_limit()` / `_offset()`,
 * and the tolerance is shaped by type rather than by value — so it
 * cannot carry a payload the declared type would have refused. Every
 * other mismatch is an error, `additionalProperties: false` included:
 * an argument a tool never declared is a caller bug worth surfacing, and
 * on a security product it is also the shape a probe takes.
 *
 * **This does not replace value allowlists.** A schema constrains the
 * type; only an allowlist constrains the value. `orderBy` is declared
 * `string` and the SQL-injection payload that used to reach the database
 * through it was a perfectly valid string. See
 * `AbstractTool::_orderBy()`.
 *
 * Errors accumulate rather than short-circuiting, so a calling agent can
 * fix everything in one round trip instead of one field per retry.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */
final class SchemaValidator
{
    // Constants
    // =========================================================================

    /**
     * Keywords that carry documentation or client hints rather than a
     * constraint. Listed so the reader can tell "deliberately ignored"
     * from "not implemented yet".
     *
     * @var string[]
     *
     * @since 5.0.0
     */
    public const ANNOTATION_KEYWORDS = [
        '$comment',
        '$schema',
        'default',
        'description',
        'examples',
        'format',
        'title',
    ];

    /**
     * Cap on how many errors one validation run reports. A caller that
     * sends a wholly wrong payload against a wide schema would otherwise
     * get an unbounded message, and past the first handful the list stops
     * being actionable.
     *
     * @since 5.0.0
     */
    public const MAX_ERRORS = 20;

    // Public Methods
    // =========================================================================

    /**
     * Validate a decoded JSON value against a schema. Returns a list of
     * human-readable error strings, empty when the value conforms.
     *
     * `$path` is the JSON-pointer-ish prefix used in messages
     * (`arguments.filters[0].mode`). Callers pass the argument-root
     * label; recursion appends.
     *
     * @param array<string,mixed> $schema
     * @return string[]
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public static function validate(array $schema, mixed $value, string $path = 'arguments'): array
    {
        $errors = self::_validate($schema, $value, $path);

        return count($errors) > self::MAX_ERRORS
            ? array_slice($errors, 0, self::MAX_ERRORS)
            : $errors;
    }

    // Private Methods
    // =========================================================================

    /**
     * Recursive worker behind `validate()`. Kept separate so the public
     * entry point owns the error cap and the recursion does not re-apply
     * it at every level.
     *
     * @param array<string,mixed> $schema
     * @return string[]
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private static function _validate(array $schema, mixed $value, string $path): array
    {
        $errors = [];

        if (isset($schema['type'])) {
            $typeErrors = self::_validateType($schema['type'], $value, $path);
            if ($typeErrors !== []) {
                // A wrong type makes every downstream keyword noise —
                // `minLength` on an integer tells the caller nothing.
                return $typeErrors;
            }
        }

        if (array_key_exists('const', $schema) && $value !== $schema['const']) {
            $errors[] = sprintf('%s must equal %s.', $path, self::_render($schema['const']));
        }

        if (isset($schema['enum']) && is_array($schema['enum']) && !in_array($value, $schema['enum'], true)) {
            $errors[] = sprintf(
                '%s must be one of: %s.',
                $path,
                implode(', ', array_map(self::_render(...), $schema['enum'])),
            );
        }

        if (is_string($value)) {
            $errors = array_merge($errors, self::_validateString($schema, $value, $path));
        }

        if (is_int($value) || is_float($value)) {
            $errors = array_merge($errors, self::_validateNumber($schema, $value, $path));
        }

        if (is_array($value)) {
            $errors = array_merge($errors, self::_validateArray($schema, $value, $path));
        }

        return array_merge($errors, self::_validateCompositions($schema, $value, $path));
    }

    /**
     * Enforce the `type` keyword. Accepts either a single type name or a
     * list of alternatives (the JSON Schema union form).
     *
     * @return string[]
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private static function _validateType(mixed $type, mixed $value, string $path): array
    {
        $types = is_array($type) ? $type : [$type];

        foreach ($types as $candidate) {
            if (is_string($candidate) && self::_matchesType($candidate, $value)) {
                return [];
            }
        }

        return [sprintf(
            '%s must be of type %s, %s given.',
            $path,
            implode(' or ', array_map(static fn(mixed $t): string => (string) $t, $types)),
            self::_typeOf($value),
        )];
    }

    /**
     * Whether a decoded JSON value satisfies one JSON Schema type name.
     *
     * `object` and `array` both map onto PHP arrays once `json_decode`
     * has run with associative decoding, so they are told apart by key
     * shape: a list is an array, anything else is an object. An empty
     * array satisfies both, because `[]` and `{}` decode identically and
     * nothing in the wire format distinguishes them.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private static function _matchesType(string $type, mixed $value): bool
    {
        return match ($type) {
            'string' => is_string($value),
            'boolean' => is_bool($value),
            'null' => $value === null,
            'integer' => self::_isInteger($value),
            'number' => is_int($value) || is_float($value) || self::_isNumericString($value),
            'array' => is_array($value) && ($value === [] || array_is_list($value)),
            'object' => is_object($value) || (is_array($value) && ($value === [] || !array_is_list($value))),
            default => true,
        };
    }

    /**
     * Whether the value is an integer for JSON Schema purposes. A float
     * with no fractional part qualifies (`1.0` is an integer per the
     * spec); a numeric string qualifies under the documented tolerance
     * for LLM clients that stringify numbers.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private static function _isInteger(mixed $value): bool
    {
        if (is_int($value)) {
            return true;
        }

        if (is_float($value)) {
            return floor($value) === $value;
        }

        return self::_isNumericString($value);
    }

    /**
     * Whether the value is a string that reads as a number. Booleans and
     * arrays are excluded — `is_numeric()` alone would let `"1e5"` in,
     * which is fine, but nothing non-string may take this path.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private static function _isNumericString(mixed $value): bool
    {
        return is_string($value) && is_numeric(trim($value));
    }

    /**
     * String-specific keywords: `minLength`, `maxLength`, `pattern`.
     *
     * @param array<string,mixed> $schema
     * @return string[]
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private static function _validateString(array $schema, string $value, string $path): array
    {
        $errors = [];
        $length = mb_strlen($value);

        if (isset($schema['minLength']) && is_int($schema['minLength']) && $length < $schema['minLength']) {
            $errors[] = sprintf('%s must be at least %d characters.', $path, $schema['minLength']);
        }

        if (isset($schema['maxLength']) && is_int($schema['maxLength']) && $length > $schema['maxLength']) {
            $errors[] = sprintf('%s must be at most %d characters.', $path, $schema['maxLength']);
        }

        if (isset($schema['pattern']) && is_string($schema['pattern'])) {
            // Delimit with `#` and escape any literal `#` in the pattern
            // so a schema-authored regex cannot terminate the delimiter
            // early. The pattern comes from plugin code, never from a
            // caller, so this is hygiene rather than a boundary.
            $delimited = '#' . str_replace('#', '\#', $schema['pattern']) . '#';
            if (@preg_match($delimited, $value) !== 1) {
                $errors[] = sprintf('%s must match the pattern %s.', $path, $schema['pattern']);
            }
        }

        return $errors;
    }

    /**
     * Numeric keywords: `minimum`, `maximum`.
     *
     * @param array<string,mixed> $schema
     * @return string[]
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private static function _validateNumber(array $schema, int|float $value, string $path): array
    {
        $errors = [];

        if (isset($schema['minimum']) && (is_int($schema['minimum']) || is_float($schema['minimum'])) && $value < $schema['minimum']) {
            $errors[] = sprintf('%s must be at least %s.', $path, self::_render($schema['minimum']));
        }

        if (isset($schema['maximum']) && (is_int($schema['maximum']) || is_float($schema['maximum'])) && $value > $schema['maximum']) {
            $errors[] = sprintf('%s must be at most %s.', $path, self::_render($schema['maximum']));
        }

        return $errors;
    }

    /**
     * Array and object keywords. Which branch applies is decided by key
     * shape, matching `_matchesType()`: a list (or an empty array under
     * an `array` type) walks `items` / `minItems` / `maxItems` /
     * `uniqueItems`; anything else walks `properties` / `required` /
     * `additionalProperties`.
     *
     * An empty array is ambiguous by construction, so it is only treated
     * as a list when the schema says `type: array`. That keeps `{}`
     * against an object schema with a required property reporting the
     * missing property rather than silently passing as an empty list.
     *
     * @param array<int|string,mixed> $value
     * @param array<string,mixed> $schema
     * @return string[]
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private static function _validateArray(array $schema, array $value, string $path): array
    {
        $declaredType = $schema['type'] ?? null;
        $isList = $value === []
            ? $declaredType === 'array'
            : array_is_list($value);

        return $isList
            ? self::_validateList($schema, $value, $path)
            : self::_validateObject($schema, $value, $path);
    }

    /**
     * List keywords: `items`, `minItems`, `maxItems`, `uniqueItems`.
     *
     * @param array<int|string,mixed> $value
     * @param array<string,mixed> $schema
     * @return string[]
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private static function _validateList(array $schema, array $value, string $path): array
    {
        $errors = [];
        $count = count($value);

        if (isset($schema['minItems']) && is_int($schema['minItems']) && $count < $schema['minItems']) {
            $errors[] = sprintf('%s must have at least %d items.', $path, $schema['minItems']);
        }

        if (isset($schema['maxItems']) && is_int($schema['maxItems']) && $count > $schema['maxItems']) {
            $errors[] = sprintf('%s must have at most %d items.', $path, $schema['maxItems']);
        }

        if (($schema['uniqueItems'] ?? false) === true) {
            $encoded = array_map(static fn(mixed $item): string => (string) json_encode($item), $value);
            if (count(array_unique($encoded)) !== $count) {
                $errors[] = sprintf('%s must not contain duplicate items.', $path);
            }
        }

        if (isset($schema['items']) && is_array($schema['items'])) {
            foreach (array_values($value) as $index => $item) {
                $errors = array_merge(
                    $errors,
                    self::_validate($schema['items'], $item, sprintf('%s[%d]', $path, $index)),
                );
            }
        }

        return $errors;
    }

    /**
     * Object keywords: `required`, `properties`, `additionalProperties`.
     *
     * @param array<int|string,mixed> $value
     * @param array<string,mixed> $schema
     * @return string[]
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private static function _validateObject(array $schema, array $value, string $path): array
    {
        $errors = [];
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];

        if (isset($schema['required']) && is_array($schema['required'])) {
            foreach ($schema['required'] as $required) {
                if (is_string($required) && !array_key_exists($required, $value)) {
                    $errors[] = sprintf('%s.%s is required.', $path, $required);
                }
            }
        }

        foreach ($value as $key => $child) {
            $childPath = sprintf('%s.%s', $path, (string) $key);
            $childSchema = $properties[$key] ?? null;

            if (is_array($childSchema)) {
                $errors = array_merge($errors, self::_validate($childSchema, $child, $childPath));
                continue;
            }

            $errors = array_merge(
                $errors,
                self::_validateAdditionalProperty($schema, $child, $childPath, $properties),
            );
        }

        return $errors;
    }

    /**
     * Apply `additionalProperties` to one undeclared property. `false`
     * rejects it, a schema validates it, and anything else (`true`, or
     * the keyword being absent) admits it.
     *
     * @param array<string,mixed> $schema
     * @param array<int|string,mixed> $properties
     * @return string[]
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private static function _validateAdditionalProperty(
        array $schema,
        mixed $value,
        string $path,
        array $properties,
    ): array {
        $additional = $schema['additionalProperties'] ?? true;

        if ($additional === false) {
            $declared = array_keys($properties);

            $accepted = $declared === []
                ? ''
                : ' Accepted: ' . implode(', ', array_map(static fn(mixed $key): string => (string) $key, $declared)) . '.';

            return [sprintf('%s is not a recognised argument.%s', $path, $accepted)];
        }

        if (is_array($additional)) {
            return self::_validate($additional, $value, $path);
        }

        return [];
    }

    /**
     * Boolean compositions: `allOf`, `anyOf`, `oneOf`, `not`.
     *
     * `anyOf` / `oneOf` report a single summary error rather than the
     * union of every branch's failures — a caller told "did not match
     * any of 3 alternatives" plus the branch errors would be reading
     * mostly irrelevant text.
     *
     * @param array<string,mixed> $schema
     * @return string[]
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private static function _validateCompositions(array $schema, mixed $value, string $path): array
    {
        $errors = [];

        if (isset($schema['allOf']) && is_array($schema['allOf'])) {
            foreach ($schema['allOf'] as $branch) {
                if (is_array($branch)) {
                    $errors = array_merge($errors, self::_validate($branch, $value, $path));
                }
            }
        }

        if (isset($schema['anyOf']) && is_array($schema['anyOf']) && self::_matchCount($schema['anyOf'], $value, $path) === 0) {
            $errors[] = sprintf('%s did not match any of the accepted shapes.', $path);
        }

        if (isset($schema['oneOf']) && is_array($schema['oneOf'])) {
            $matches = self::_matchCount($schema['oneOf'], $value, $path);
            if ($matches !== 1) {
                $errors[] = sprintf(
                    '%s must match exactly one of the accepted shapes, matched %d.',
                    $path,
                    $matches,
                );
            }
        }

        if (isset($schema['not']) && is_array($schema['not']) && self::_validate($schema['not'], $value, $path) === []) {
            $errors[] = sprintf('%s matches an excluded shape.', $path);
        }

        return $errors;
    }

    /**
     * How many of the given branch schemas the value satisfies.
     *
     * @param array<int|string,mixed> $branches
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private static function _matchCount(array $branches, mixed $value, string $path): int
    {
        $matches = 0;

        foreach ($branches as $branch) {
            if (is_array($branch) && self::_validate($branch, $value, $path) === []) {
                $matches++;
            }
        }

        return $matches;
    }

    /**
     * The JSON Schema type name for a decoded value, for error copy.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private static function _typeOf(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => 'boolean',
            is_int($value) => 'integer',
            is_float($value) => 'number',
            is_string($value) => 'string',
            is_array($value) => $value === [] || array_is_list($value) ? 'array' : 'object',
            default => 'object',
        };
    }

    /**
     * Render a schema literal for an error message. Strings are quoted
     * so `"1"` is visibly distinct from `1`; everything else round-trips
     * through `json_encode`.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private static function _render(mixed $value): string
    {
        return is_string($value)
            ? sprintf('`%s`', $value)
            : (string) json_encode($value);
    }
}
