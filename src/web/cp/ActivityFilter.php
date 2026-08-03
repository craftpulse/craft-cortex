<?php

namespace craftpulse\herald\web\cp;

use Craft;
use craft\elements\User;
use craft\helpers\Db;
use craftpulse\herald\db\InvocationQuery;

/**
 * =========================================================================
 * Applies the Activity tab's filter bar to an invocation-audit query, and
 * the fail-closed permission scope that bounds it.
 *
 * **The scope and the filters live in one class on purpose.** They are
 * order-dependent: the caller's own-rows pin has to be on the query before
 * any caller-supplied filter, and `apply()` is the only entry point that
 * guarantees it. Splitting the pin into a separate collaborator would make
 * it possible to filter an unscoped query, which is the one mistake this
 * surface cannot afford.
 *
 * The scope itself: an admin sees every row (they audit everyone); a
 * non-admin holding `herald:view-activity` sees ONLY rows whose `userId`
 * equals their own. Because the pin is an `andWhere`, a non-admin posting
 * `filters[userId]=<someone else>` cannot widen it — both clauses must
 * hold, so the result stays inside their own rows. A non-admin with no
 * resolvable identity is pinned to the sentinel id 0 and sees nothing;
 * the permission gate already rejected the anonymous case upstream, so
 * that branch is defense in depth.
 *
 * Every caller-supplied value goes through `Db::parseParam` /
 * `Db::parseDateParam` or the query class's own typed setters. No raw
 * interpolation.
 * =========================================================================
 *
 * @author CraftPulse
 * @since  5.0.0
 */
final class ActivityFilter
{
    // Public Methods
    // =========================================================================

    /**
     * Apply the fail-closed scope and then the filter bar to a query.
     *
     * Filters honoured: `kind`, `toolName`, `userId` (admins only),
     * `from` / `to` (a `dateCreated` bracket). An empty-string value is
     * treated as "no filter requested" — the filter bar posts every
     * control on every submit, so a cleared dropdown arrives empty and
     * reading it literally would blank the table. A non-array `$filters`
     * payload normalises to no filters at all.
     *
     * `$search` is a substring match across the tool name, the client name
     * and the error message, which is how an operator finds every row that
     * hit the same failure.
     *
     * @param mixed  $filters The raw `filters` request param.
     * @param string $search  The raw (trimmed) search needle.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function apply(InvocationQuery $query, mixed $filters, string $search): void
    {
        // Fail-closed scope FIRST — a non-admin is pinned to their own
        // userId before any caller filter is applied.
        $this->scopeToUser($query);

        if (!is_array($filters)) {
            $filters = [];
        }

        $kind = $this->_value($filters, 'kind');
        if ($kind !== null) {
            $query->kind($kind);
        }

        $toolName = $this->_value($filters, 'toolName');
        if ($toolName !== null) {
            $query->toolName($toolName);
        }

        // Admin-only user filter — a non-admin is already pinned above, so
        // skip applying their (ignored) value entirely.
        if ($this->isCallerAdmin()) {
            $userId = $this->_value($filters, 'userId');
            if ($userId !== null && ctype_digit($userId)) {
                $query->userId((int) $userId);
            }
        }

        $from = $this->_value($filters, 'from');
        if ($from !== null) {
            $fromClause = Db::parseDateParam('dateCreated', $from, '>=');
            if ($fromClause !== null) {
                $query->andWhere($fromClause);
            }
        }

        $to = $this->_value($filters, 'to');
        if ($to !== null) {
            $toClause = Db::parseDateParam('dateCreated', $to, '<=');
            if ($toClause !== null) {
                $query->andWhere($toClause);
            }
        }

        if ($search !== '') {
            $query->andWhere([
                'or',
                Db::parseParam('toolName', '*' . $search . '*'),
                Db::parseParam('clientName', '*' . $search . '*'),
                Db::parseParam('errorMessage', '*' . $search . '*'),
            ]);
        }
    }

    /**
     * Whether the current CP user is an admin. Centralised so the identity
     * is read in exactly one place: the scope, the admin-only user filter,
     * and the view (which hides the user dropdown from non-admins) all
     * agree by construction.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function isCallerAdmin(): bool
    {
        $identity = Craft::$app->getUser()->getIdentity();

        return $identity instanceof User && $identity->admin;
    }

    /**
     * Pin a query to the caller's own rows unless they are an admin. Also
     * called directly by the single-row detail endpoint, where there is no
     * filter bar but the same scope has to hold — an out-of-scope row 404s
     * rather than 403s, so the scope is what makes the 404 correct.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function scopeToUser(InvocationQuery $query): void
    {
        if ($this->isCallerAdmin()) {
            return;
        }

        $identity = Craft::$app->getUser()->getIdentity();
        $query->userId($identity instanceof User ? (int) $identity->id : 0);
    }

    // Private Methods
    // =========================================================================

    /**
     * Read a single filter value, normalising the empty string and the
     * missing key to null so the query builder treats both as "no filter
     * requested".
     *
     * @param array<string,mixed> $filters
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _value(array $filters, string $key): ?string
    {
        $value = $filters[$key] ?? null;
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }
}
