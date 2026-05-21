<?php

namespace craftpulse\cortex\elements\db;

use craft\elements\db\ElementQuery;
use craft\helpers\Db;
use craftpulse\cortex\db\Table;
use craftpulse\cortex\elements\Skill;

/**
 * =========================================================================
 * Element query for Cortex `Skill` elements.
 *
 * Adds two native filters on top of `ElementQuery`:
 *   - `handle()`       — the natural-key slug (globally unique).
 *   - `description()`  — substring match against the native description
 *                        column.
 *
 * `beforePrepare()` joins the `cortex_skills` table and surfaces the
 * native columns via `addSelect()` so consumers can read `$skill->handle`
 * / `$skill->description` without a second query. The element's body
 * content lives in the PC-stored field layout and is loaded lazily
 * through Craft's standard content-table mechanism — no special handling
 * here.
 *
 * @template TKey of array-key
 * @template TElement of Skill
 * @extends ElementQuery<TKey,TElement>
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */
class SkillQuery extends ElementQuery
{
    // Public Properties
    // =========================================================================

    /**
     * @var mixed The handle(s) the resulting skills must match. Accepts
     *           a string, an array of strings, or a `Db::parseParam`-
     *           shaped negation (e.g. `'not foo'`, `['not', 'foo']`).
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public mixed $handle = null;

    /**
     * @var mixed The description filter. `Db::parseParam`-shaped — pass
     *           a string for substring match, an array for IN-list, or
     *           a `'not'`-prefixed array for negation.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public mixed $description = null;

    // Public Methods
    // =========================================================================

    /**
     * Filter by the skill's natural-key handle.
     *
     * Accepts a single string, an array of strings, or a
     * `Db::parseParam`-shaped negation. Handles are globally unique
     * (DB UNIQUE) so a string lookup is the canonical single-row path.
     *
     * @param mixed $value The handle filter.
     * @return static
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function handle(mixed $value): static
    {
        $this->handle = $value;
        return $this;
    }

    /**
     * Filter by the skill's native description column. Pass a string
     * for substring match, an array for an IN-list, or a `'not'`-
     * prefixed array for negation. The description lives in the
     * `cortex_skills` table (NOT in the content table) so the filter
     * runs against the join surface and stays free of content-table
     * scans.
     *
     * @param mixed $value The description filter.
     * @return static
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function description(mixed $value): static
    {
        $this->description = $value;
        return $this;
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    protected function beforePrepare(): bool
    {
        if (!parent::beforePrepare()) {
            return false;
        }

        $this->joinElementTable(Table::SKILLS);

        // `parent::beforePrepare()` instantiates `$this->query` and
        // `$this->subQuery`; the asserts narrow the type for PHPStan
        // and double as runtime defense-in-depth.
        assert($this->query !== null);
        assert($this->subQuery !== null);

        $this->query->addSelect([
            'cortex_skills.handle',
            'cortex_skills.description',
        ]);

        if ($this->handle !== null) {
            $handleClause = Db::parseParam('cortex_skills.handle', $this->handle);
            if ($handleClause !== null) {
                $this->subQuery->andWhere($handleClause);
            }
        }

        if ($this->description !== null) {
            $descriptionClause = Db::parseParam('cortex_skills.description', $this->description);
            if ($descriptionClause !== null) {
                $this->subQuery->andWhere($descriptionClause);
            }
        }

        return true;
    }
}
