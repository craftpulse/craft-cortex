<?php

/**
 * @author Craftpulse
 * @since  5.0.0
 */

use Craft;
use craftpulse\cortex\Cortex;

beforeEach(function() {
    $this->tool = Cortex::getInstance()->tools->getByName('volumes_and_filesystems');
});

it('returns volumes annotated with filesystems plus orphans plus the type registry', function() {
    $result = $this->tool->execute([]);

    expect($result)->toHaveKeys([
        'volumes', 'orphanFilesystems', 'filesystemTypes',
        'volumeCount', 'filesystemCount',
    ]);
    expect($result['volumes'])->toBeArray();
    expect($result['orphanFilesystems'])->toBeArray();
    expect($result['filesystemTypes'])->toBeArray();

    expect($result['volumeCount'])->toBe(count(Craft::$app->getVolumes()->getAllVolumes()));
    expect($result['filesystemCount'])->toBe(count(Craft::$app->getFs()->getAllFilesystems()));

    foreach ($result['volumes'] as $vol) {
        expect($vol)->toHaveKeys(['id', 'uid', 'name', 'handle', 'fs']);
        if ($vol['fs'] !== null) {
            expect($vol['fs'])->toHaveKeys(['handle', 'name', 'type', 'hasUrls']);
        }
    }
});

it('exposes the registered filesystem-type classes (Local + plugin-provided)', function() {
    $result = $this->tool->execute([]);

    expect($result['filesystemTypes'])->toBeArray()->not->toBeEmpty();

    $classes = array_column($result['filesystemTypes'], 'class');
    // Local is always registered — built into Craft.
    expect($classes)->toContain('craft\\fs\\Local');

    foreach ($result['filesystemTypes'] as $type) {
        expect($type)->toHaveKeys(['class', 'displayName', 'isFirstParty']);
        expect(class_exists($type['class']))->toBeTrue();
    }

    // Find Local and assert its first-party flag.
    foreach ($result['filesystemTypes'] as $type) {
        if ($type['class'] === 'craft\\fs\\Local') {
            expect($type['isFirstParty'])->toBeTrue();
            break;
        }
    }
});

it('lists volumes that are accounted for as referenced (not in orphan list)', function() {
    $result = $this->tool->execute([]);

    $referencedHandles = [];
    foreach ($result['volumes'] as $vol) {
        if ($vol['fs'] !== null) {
            $referencedHandles[] = $vol['fs']['handle'];
        }
    }

    $orphanHandles = array_column($result['orphanFilesystems'], 'handle');

    expect(array_intersect($referencedHandles, $orphanHandles))->toBe([]);
});
