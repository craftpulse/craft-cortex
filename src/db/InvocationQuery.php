<?php

namespace craftpulse\cortex\db;

use craft\db\Query;
use craft\helpers\Db;
use DateTimeInterface;

/**
 * =========================================================================
 * Fluent query against the `cortex_invocations` audit-log table.
 *
 * Returns associative-array rows shaped like the `Invocation` Record. CP
 * dashboards (Gate 9) and Plugin Store consumers compose filters with
 * the standard `Query` chain and call `all()` / `one()` / `count()` to
 * execute. Single source of truth for the table name — every caller
 * routes through here so the column list stays uniform.
 *
 * Use `Cortex::getInstance()->invocations->find()` to instantiate. The
 * service is the canonical entry point; direct instantiation works but
 * misses any future memoization / scope decoration the service layer
 * may add.
 *
 * Filters use `Db::parseParam()` so callers can pass scalars, arrays, or
 * the `:empty:` / `not :empty:` sentinels without manual SQL fragments.
 * Defense in depth — keeps the query SQL-injection-free even when
 * callers thread user input through (CP search fields, GraphQL filters).
 * =========================================================================
 *
 * @extends Query<int, array<string, mixed>>
 *
 * @author Craftpulse
 * @since  5.0.0
 */
class InvocationQuery extends Query
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * Binds the query to the `cortex_invocations` table and projects
     * every column. Callers narrow with `select()` when they want a
     * lean payload.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function init(): void
    {
        parent::init();

        $this->from([Table::INVOCATIONS]);
    }

    /**
     * Filter by tool name. Accepts a scalar, an array, or any of the
     * `Db::parseParam()` sentinels (`not foo`, `:empty:`, etc.). Null
     * is a no-op so chained calls stay clean.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function toolName(mixed $value): self
    {
        $this->_applyFilter('toolName', $value);
        return $this;
    }

    /**
     * Filter by audit `kind` — `success` / `tool_error` /
     * `internal_error`. Null is a no-op.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function kind(mixed $value): self
    {
        $this->_applyFilter('kind', $value);
        return $this;
    }

    /**
     * Filter by transport — `http` is the only value the HTTP audit
     * log writes today; the column stays open for forward-compat per
     * locked decision 11. Null is a no-op.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function transport(mixed $value): self
    {
        $this->_applyFilter('transport', $value);
        return $this;
    }

    /**
     * Filter by authenticated user id. Pass `:empty:` to find rows
     * where the user was deleted (FK SET NULL).
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function userId(mixed $value): self
    {
        $this->_applyFilter('userId', $value);
        return $this;
    }

    /**
     * Filter by issuing bearer token id. OAuth-authenticated rows
     * carry `null` here per the migration docblock — pass `:empty:`
     * to find them. Pass `not :empty:` for bearer-authenticated rows
     * only.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function tokenId(mixed $value): self
    {
        $this->_applyFilter('tokenId', $value);
        return $this;
    }

    /**
     * Filter by MCP session id (`Mcp-Session-Id` header).
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function sessionId(mixed $value): self
    {
        $this->_applyFilter('sessionId', $value);
        return $this;
    }

    /**
     * Filter by MCP client name (`clientInfo.name` from initialize).
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function clientName(mixed $value): self
    {
        $this->_applyFilter('clientName', $value);
        return $this;
    }

    /**
     * Filter by JSON-RPC request id propagated from the call site.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function requestId(mixed $value): self
    {
        $this->_applyFilter('requestId', $value);
        return $this;
    }

    /**
     * Restrict to rows created strictly before the given instant.
     * Null is a no-op.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function before(?DateTimeInterface $dt): self
    {
        if ($dt !== null) {
            $this->andWhere(['<', 'dateCreated', Db::prepareDateForDb($dt)]);
        }
        return $this;
    }

    /**
     * Restrict to rows created at or after the given instant. Null is
     * a no-op.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function after(?DateTimeInterface $dt): self
    {
        if ($dt !== null) {
            $this->andWhere(['>=', 'dateCreated', Db::prepareDateForDb($dt)]);
        }
        return $this;
    }

    /**
     * Filter by presence of an error payload. `true` returns only rows
     * carrying an `errorClass`; `false` returns only clean-success
     * rows. Null is a no-op.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function hasError(?bool $value = true): self
    {
        if ($value === true) {
            $this->andWhere(['not', ['errorClass' => null]]);
            return $this;
        }
        if ($value === false) {
            $this->andWhere(['errorClass' => null]);
        }
        return $this;
    }

    /**
     * Restrict to rows whose `durationMs` falls within the given
     * range. Either bound is optional — pass nulls to leave the side
     * unbounded.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function durationMs(?int $min = null, ?int $max = null): self
    {
        if ($min !== null) {
            $this->andWhere(['>=', 'durationMs', $min]);
        }
        if ($max !== null) {
            $this->andWhere(['<=', 'durationMs', $max]);
        }
        return $this;
    }

    /**
     * Return the distinct, alphabetised list of `toolName` values across
     * the whole `cortex_invocations` table. Feeds the Activity tab's tool
     * filter dropdown (Gate 9.3). `toolName` is an indexed column so the
     * `DISTINCT` scan stays cheap; this method is called once per
     * `actionActivity` render.
     *
     * Resets `select`, `distinct`, and `orderBy` on a fresh clone so a
     * caller that has already composed filters on `$this` does not have
     * its column projection mutated as a side effect.
     *
     * Scaling caveat (gate-9.md sub-gate 9.3 risk note): at thousands of
     * distinct tools the dropdown grows unwieldy and should become a
     * typeahead — out of scope here, where the tool set is bounded by the
     * registered tool count (low dozens).
     *
     * @return string[]
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function distinctToolNames(): array
    {
        $values = (new self())
            ->select(['toolName'])
            ->distinct()
            ->orderBy(['toolName' => SORT_ASC])
            ->column();

        return array_values(array_filter(
            array_map(static fn(mixed $v): string => (string) $v, $values),
            static fn(string $v): bool => $v !== '',
        ));
    }

    // Private Methods
    // =========================================================================

    /**
     * Apply a `Db::parseParam()`-based `andWhere()` for a column.
     * Treats `null` as "no filter requested" so the public setters
     * can chain optional UI inputs without the caller branching.
     * `Db::parseParam()` itself returns null when the normalized
     * value collapses to nothing (an empty array, etc.); that branch
     * is also treated as a no-op.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _applyFilter(string $column, mixed $value): void
    {
        if ($value === null) {
            return;
        }
        $clause = Db::parseParam($column, $value);
        if ($clause !== null && $clause !== '') {
            $this->andWhere($clause);
        }
    }
}
