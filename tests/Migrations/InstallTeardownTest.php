<?php

/**
 * =========================================================================
 * Install migration teardown tests — orphan cleanup on uninstall.
 *
 * `herald_skills` is the extension table of the `Skill` element type, so
 * dropping it is only half the teardown: without an explicit sweep, one
 * row per authored skill stays in `elements` forever, owned by nothing
 * and unreachable by garbage collection (which routes through registered
 * element types, and Herald's registration is gone once the plugin is).
 *
 * Only `removeContent()` is exercised here, never `safeDown()`: the drops
 * are DDL, MySQL commits DDL implicitly, and the per-test transaction
 * therefore could not roll a `safeDown()` run back. Running it would
 * take Herald's schema out from under the rest of the suite.
 * =========================================================================
 *
 * @author CraftPulse
 * @since  5.0.0
 */

use craft\db\Query;
use craft\db\Table as CraftTable;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use craftpulse\herald\elements\Skill;
use craftpulse\herald\migrations\Install;

it('removeContent() deletes the elements rows a dropped herald_skills would orphan', function() {
    $handle = 'herald-teardown-' . bin2hex(random_bytes(4));

    $skill = new Skill();
    $skill->handle = $handle;
    $skill->title = 'Teardown fixture';
    expect(Craft::$app->getElements()->saveElement($skill))->toBeTrue();

    $elementId = (int) $skill->id;
    $elementRow = fn(): int => (int) (new Query())
        ->from(CraftTable::ELEMENTS)
        ->where(['id' => $elementId])
        ->count();

    expect($elementRow())->toBe(1);

    $migration = new Install();
    $migration->compact = true;
    $migration->removeContent();

    // The `elements` row is gone, and the FK cascade took the extension
    // row with it — nothing is left for a dropped table to orphan.
    expect($elementRow())->toBe(0);
    expect((int) (new Query())
        ->from(\craftpulse\herald\db\Table::SKILLS)
        ->where(['id' => $elementId])
        ->count())->toBe(0);
});

it('removeContent() deletes the Skill field layout', function() {
    // Inserted directly rather than through `Fields::saveLayout()`: the
    // Skill layout is project-config-stored, and a PC write here would
    // make the case sequential-only for no gain. What matters is the row
    // an uninstall would leave behind, not how it got there.
    Craft::$app->getDb()->createCommand()
        ->insert(CraftTable::FIELDLAYOUTS, [
            'type' => Skill::class,
            'dateCreated' => Db::prepareDateForDb(new DateTime('now', new DateTimeZone('UTC'))),
            'dateUpdated' => Db::prepareDateForDb(new DateTime('now', new DateTimeZone('UTC'))),
            'uid' => StringHelper::UUID(),
        ])
        ->execute();

    expect((int) (new Query())
        ->from(CraftTable::FIELDLAYOUTS)
        ->where(['type' => Skill::class])
        ->count())->toBeGreaterThanOrEqual(1);

    $migration = new Install();
    $migration->compact = true;
    $migration->removeContent();

    expect((int) (new Query())
        ->from(CraftTable::FIELDLAYOUTS)
        ->where(['type' => Skill::class])
        ->count())->toBe(0);
});
