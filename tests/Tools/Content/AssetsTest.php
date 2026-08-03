<?php

/**
 * @author CraftPulse
 * @since  5.0.0
 */

use Craft;
use craft\elements\Asset;
use craftpulse\herald\Herald;
use craftpulse\herald\tools\ToolException;

beforeEach(function() {
    $this->tool = Herald::getInstance()->tools->getByName('assets');
});

it('returns assets list with pagination metadata', function() {
    $result = $this->tool->execute([]);

    expect($result)->toHaveKeys(['assets', 'count', 'totalCount', 'limit', 'offset']);
    expect($result['assets'])->toBeArray();
});

it('returns count as a real integer', function() {
    $result = $this->tool->execute(['count' => true]);

    expect($result)->toHaveKey('count');
    expect($result['count'])->toBeInt();
    expect($result['count'])->toBe((int) Asset::find()->count());
});

it('returns folders as an indexed array (not a keyed dict)', function() {
    $volume = Craft::$app->getVolumes()->getAllVolumes()[0] ?? null;
    if ($volume === null) {
        $this->markTestSkipped('No volumes in playground.');
    }

    $result = $this->tool->execute(['mode' => 'folders', 'volume' => $volume->handle]);

    expect($result['folders'])->toBeArray();
    if ($result['folders'] !== []) {
        // Indexed list: keys must be 0,1,2,…
        expect(array_keys($result['folders']))->toBe(range(0, count($result['folders']) - 1));
    }
});

it('returns a single asset when id is passed', function() {
    $asset = Asset::find()->one();
    if ($asset === null) {
        $this->markTestSkipped('No assets in playground.');
    }

    $result = $this->tool->execute(['id' => $asset->id]);

    expect($result)->toHaveKey('asset');
    expect($result['asset']['id'])->toBe($asset->id);
    expect($result['asset'])->toHaveKeys([
        'filename', 'extension', 'kind', 'mimeType', 'size',
        'width', 'height', 'volumeHandle', 'folderId', 'folderPath',
    ]);
});

it('throws ToolException for unknown asset id', function() {
    $this->tool->execute(['id' => 99999999]);
})->throws(ToolException::class);

it('returns a folder tree when mode: folders is requested with a volume', function() {
    $volume = Craft::$app->getVolumes()->getAllVolumes()[0] ?? null;
    if ($volume === null) {
        $this->markTestSkipped('No volumes in playground.');
    }

    $result = $this->tool->execute(['mode' => 'folders', 'volume' => $volume->handle]);

    expect($result)->toHaveKeys(['volumeHandle', 'folders', 'count']);
    expect($result['volumeHandle'])->toBe($volume->handle);
    expect($result['folders'])->toBeArray();

    foreach ($result['folders'] as $folder) {
        expect($folder)->toHaveKeys(['id', 'uid', 'name', 'path', 'parentId']);
    }
});

it('throws ToolException when mode: folders is requested without volume', function() {
    $this->tool->execute(['mode' => 'folders']);
})->throws(ToolException::class);

it('throws ToolException when mode: folders gets an unknown volume', function() {
    $this->tool->execute(['mode' => 'folders', 'volume' => '__herald_no_such_volume__']);
})->throws(ToolException::class);
