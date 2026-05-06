<?php

namespace craftpulse\cortex\tools\support;

use craft\base\ElementInterface;
use craft\elements\db\ElementQuery;
use craft\elements\ElementCollection;
use DateTimeInterface;

/**
 * =========================================================================
 * Project Craft elements + custom field values into JSON-friendly arrays.
 *
 * Used by every content-reading tool (entries, assets, categories, tags,
 * globals) so the wire shape stays consistent. Three concerns:
 *
 *   1. **Identity headers** — id/uid/title/slug/url/site/status/dates.
 *      Same keys regardless of element type so the AI doesn't need to
 *      switch on `class`.
 *   2. **Custom field values** — converted to scalars where possible:
 *      DateTime → ISO 8601, ElementQuery / ElementCollection → array of
 *      element summaries, scalars pass through, anything else falls
 *      back to a stringified representation.
 *   3. **Eager-load awareness** — when the caller passed `with: [...]`,
 *      relational field values are already loaded into ElementCollections
 *      and we don't trigger fresh queries. Without `with`, a relational
 *      field's `ElementQuery` is left dormant and replaced with a
 *      `{ "type": "relation", "loaded": false }` stub so we never
 *      cause N+1 by serialising it.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  0.1.0
 */
class ElementSerializer
{
    // Public Methods
    // =========================================================================

    /**
     * Serialise an element including its custom field values.
     *
     * @param string[] $eagerHandles Field handles the caller eager-loaded
     *                               via `with: [...]`. Other relational
     *                               fields are stubbed, not queried.
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public function serializeElement(ElementInterface $element, array $eagerHandles = []): array
    {
        return [
            ...$this->_identity($element),
            'fields' => $this->_serializeFieldValues($element, $eagerHandles),
        ];
    }

    /**
     * Serialise just the identity headers — id/uid/title/etc. — without
     * touching custom fields. Used in summarised projections (e.g. the
     * `relatedTo` payload in usage reports).
     *
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public function summarizeElement(ElementInterface $element): array
    {
        return [
            'id' => $element->id,
            'uid' => $element->uid,
            'title' => $element->title ?? null,
            'slug' => $element->slug ?? null,
            'type' => $element::class,
        ];
    }

    // Private Methods
    // =========================================================================

    /**
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    private function _identity(ElementInterface $element): array
    {
        return [
            'id' => $element->id,
            'canonicalId' => $element->getCanonicalId(),
            'uid' => $element->uid,
            'title' => $element->title ?? null,
            'slug' => $element->slug ?? null,
            'url' => $element->getUrl(),
            'siteId' => $element->siteId,
            'status' => $element->getStatus(),
            'enabled' => $element->enabled,
            'archived' => $element->archived,
            'trashed' => $element->trashed,
            'dateCreated' => $element->dateCreated?->format(DateTimeInterface::ATOM),
            'dateUpdated' => $element->dateUpdated?->format(DateTimeInterface::ATOM),
            'level' => $element->level,
            'lft' => $element->lft,
            'rgt' => $element->rgt,
            'type' => $element::class,
        ];
    }

    /**
     * @param string[] $eagerHandles
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    private function _serializeFieldValues(ElementInterface $element, array $eagerHandles): array
    {
        $layout = $element->getFieldLayout();
        if ($layout === null) {
            return [];
        }

        $eagerSet = array_flip($eagerHandles);
        $values = [];

        foreach ($layout->getCustomFields() as $field) {
            $handle = $field->handle;
            if (!is_string($handle) || $handle === '') {
                continue;
            }
            $isEager = isset($eagerSet[$handle]);
            $values[$handle] = $this->_serializeFieldValue(
                $element->getFieldValue($handle),
                $isEager,
            );
        }

        return $values;
    }

    /**
     * @author Craftpulse
     * @since  0.1.0
     */
    private function _serializeFieldValue(mixed $value, bool $isEager): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        if ($value instanceof ElementCollection) {
            return $value->map(fn (ElementInterface $el): array => $this->summarizeElement($el))->all();
        }

        if ($value instanceof ElementQuery) {
            // Without explicit eager loading we refuse to materialise the query
            // — that's the N+1 trap. Caller has to opt in via `with: [...]`.
            if (!$isEager) {
                return [
                    'type' => 'relation',
                    'loaded' => false,
                    'note' => 'Pass `with: ["<fieldHandle>"]` to eager-load.',
                ];
            }

            return array_map(
                fn (ElementInterface $el): array => $this->summarizeElement($el),
                $value->all(),
            );
        }

        if (is_array($value)) {
            return array_map(fn (mixed $v): mixed => $this->_serializeFieldValue($v, $isEager), $value);
        }

        // Last resort — let the value stringify itself if it can.
        if (is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }

        return [
            'type' => 'unserializable',
            'class' => is_object($value) ? $value::class : gettype($value),
        ];
    }
}
