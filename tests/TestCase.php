<?php

namespace craftpulse\herald\tests;

use Craft;
use PHPUnit\Framework\TestCase as PhpUnitTestCase;

// =============================================================================
// TestCase
// =============================================================================

/**
 * Base test case for Herald's suite: wraps every test in a database
 * transaction and rolls it back on teardown.
 *
 * Herald's harness boots a real Craft console application (see
 * `tests/boot-craft.php`), so a test that saves an element, issues a token or
 * grants a permission is doing it against a real database. Without this class
 * every one of those writes commits: the suite is then a data-producing
 * process whose output accumulates run after run, assertions start passing
 * because of what an earlier run left behind, and cleanup code becomes
 * something every test has to remember. The transaction here is the same
 * mechanism `markhuot\craftpest\test\RefreshesDatabase` provides for
 * craft-pest-core suites; Herald does not use craft-pest-core (its
 * `InstallsCraft` Pest plugin boots its own Craft application before this
 * harness gets a turn, and the bootstrap in `tests/boot-craft.php` exists
 * precisely so the surrounding install's copy of it can never hijack this
 * run), so the behaviour is provided here instead.
 *
 * Bound to every test in `tests/` through `uses()` in `tests/Pest.php`.
 *
 * ## What rolls back, and what does not
 *
 *   - **Rows** roll back, including project-config rows. The memoized
 *     `configVersion` is restored alongside them, because rolling back the
 *     `info` row while leaving the in-memory value bumped desynchronises the
 *     two and later project-config writes fail with a stale/busy resource
 *     exception several tests downstream of the cause.
 *   - **DDL does not.** MySQL commits implicitly on `CREATE`/`ALTER TABLE`.
 *     No test should be doing schema work; if one has to, it owns its own
 *     cleanup.
 *   - **Nothing outside the database does.** Files written under the
 *     install's `storage/` or a volume's filesystem, and Craft's caches,
 *     survive the rollback. Project-config YAML is not a concern only because
 *     `tests/boot-craft.php` turns automatic YAML writing off.
 *   - **Memoized service state does not.** A test that writes project config
 *     and rolls back leaves the project-config service's loaded tree ahead of
 *     the database. Tests that need that guarantee have to reset the service
 *     themselves.
 *
 * @author Craftpulse
 * @since  5.0.0
 */
class TestCase extends PhpUnitTestCase
{
    // Private Properties
    // =========================================================================

    /**
     * @var string|null The memoized project-config version as it stood before
     *                  the test, restored after the rollback.
     */
    private ?string $_configVersion = null;

    // Protected Methods
    // =========================================================================

    /**
     * Opens the transaction the test runs in.
     *
     * @return void
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->_configVersion = Craft::$app->getInfo()->configVersion;

        Craft::$app->getDb()->beginTransaction();
    }

    /**
     * Rolls back everything the test wrote.
     *
     * Unwinds the whole transaction stack rather than only the level this
     * class opened: a test that leaves a nested transaction open (an
     * exception thrown inside `Db::transaction()`, say) would otherwise have
     * its outermost level survive, because `yii\db\Transaction::rollBack()`
     * releases a savepoint instead of rolling back when the nesting level is
     * above one. `Connection::getTransaction()` returns null once the level
     * reaches zero, so the loop is bounded by the nesting depth.
     *
     * @return void
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    protected function tearDown(): void
    {
        $db = Craft::$app->getDb();

        while (($transaction = $db->getTransaction()) !== null) {
            $transaction->rollBack();
        }

        if ($this->_configVersion !== null) {
            Craft::$app->getInfo()->configVersion = $this->_configVersion;
        }

        parent::tearDown();
    }
}
