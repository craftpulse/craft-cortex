<?php

/**
 * @author Craftpulse
 * @since  5.0.0
 */

use Craft;
use craftpulse\cortex\Plugin;

beforeEach(function() {
    $this->tool = Plugin::getInstance()->tools->getByName('database_schema');
});

it('returns full schema by default', function() {
    $result = $this->tool->execute([]);

    expect($result)->toHaveKeys(['driver', 'tablePrefix', 'tables', 'count']);
    expect($result['count'])->toBeGreaterThan(0);

    foreach ($result['tables'] as $table) {
        expect($table)->toHaveKeys(['name', 'columns', 'foreignKeys']);
        expect($table['columns'])->toBeArray();
    }
});

it('list mode returns just names', function() {
    $result = $this->tool->execute(['mode' => 'list']);

    expect($result)->toHaveKey('mode', 'list');
    expect($result['tables'])->toBeArray();

    foreach ($result['tables'] as $table) {
        expect($table)->toBe(['name' => $table['name']]);
    }
});

it('honours the tables filter', function() {
    $allTables = Craft::$app->getDb()->getSchema()->getTableNames();
    if ($allTables === []) {
        $this->markTestSkipped('No tables in DB.');
    }

    $sample = $allTables[0];
    $result = $this->tool->execute(['tables' => [$sample]]);

    expect($result['count'])->toBe(1);
    expect($result['tables'][0]['name'])->toBe($sample);
});

it('serializes columns with full metadata', function() {
    $result = $this->tool->execute(['mode' => 'list']);
    if ($result['tables'] === []) {
        $this->markTestSkipped('No tables.');
    }

    $tableName = $result['tables'][0]['name'];
    $detail = $this->tool->execute(['tables' => [$tableName]]);
    $columns = $detail['tables'][0]['columns'];

    expect($columns)->toBeArray()->not->toBeEmpty();
    foreach ($columns as $col) {
        expect($col)->toHaveKeys([
            'name', 'type', 'dbType', 'phpType',
            'allowNull', 'autoIncrement', 'isPrimaryKey',
        ]);
    }
});
