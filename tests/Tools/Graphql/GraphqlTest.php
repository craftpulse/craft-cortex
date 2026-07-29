<?php

/**
 * @author Craftpulse
 * @since  5.0.0
 */

use craftpulse\herald\Herald;
use craftpulse\herald\tools\ToolException;

beforeEach(function() {
    $this->tool = Herald::getInstance()->tools->getByName('graphql');
});

it('throws when mode is missing', function() {
    $this->tool->execute([]);
})->throws(ToolException::class, '`mode` is required');

it('throws on unknown mode', function() {
    $this->tool->execute(['mode' => 'oops']);
})->throws(ToolException::class, 'Unknown mode');

it('lists schemas with id / name / uid / isPublic / scope summary', function() {
    $result = $this->tool->execute(['mode' => 'list_schemas']);

    expect($result)->toHaveKey('mode', 'list_schemas');
    expect($result)->toHaveKey('schemas');
    expect($result)->toHaveKey('count');
    expect($result['schemas'])->toBeArray();
    expect($result['count'])->toBe(count($result['schemas']));

    foreach ($result['schemas'] as $row) {
        expect($row)->toHaveKeys(['id', 'name', 'uid', 'isPublic', 'scopeCount', 'scope']);
        expect($row['name'])->toBeString();
        expect($row['isPublic'])->toBeBool();
        expect($row['scope'])->toBeArray();
        expect($row['scopeCount'])->toBe(count($row['scope']));
    }
});

it('returns SDL for the public schema by default', function() {
    $public = Craft::$app->getGql()->getPublicSchema();
    if ($public === null) {
        $this->markTestSkipped('No public schema configured in this environment.');
    }

    $result = $this->tool->execute(['mode' => 'get_sdl']);

    expect($result)->toHaveKey('mode', 'get_sdl');
    expect($result)->toHaveKey('schema');
    expect($result['schema'])->toHaveKeys(['name', 'uid', 'isPublic']);
    expect($result['schema']['isPublic'])->toBeTrue();
    expect($result)->toHaveKey('sdl');
    expect($result['sdl'])->toBeString();
    expect($result['length'])->toBe(strlen($result['sdl']));
});

it('throws on get_sdl when schema name is unknown', function() {
    $this->tool->execute(['mode' => 'get_sdl', 'name' => '__definitely_not_a_schema__']);
})->throws(ToolException::class, "No GraphQL schema found with name '__definitely_not_a_schema__'");

it('lists tokens with metadata only: never the access-token value', function() {
    $result = $this->tool->execute(['mode' => 'list_tokens']);

    expect($result)->toHaveKey('mode', 'list_tokens');
    expect($result)->toHaveKey('tokens');
    expect($result)->toHaveKey('count');
    expect($result['tokens'])->toBeArray();
    expect($result['count'])->toBe(count($result['tokens']));

    foreach ($result['tokens'] as $row) {
        expect($row)->toHaveKeys([
            'id', 'name', 'uid', 'enabled', 'isPublic', 'isValid', 'isExpired',
            'schemaId', 'schemaName', 'expiryDate', 'lastUsed', 'dateCreated',
            'accessTokenFingerprint',
        ]);

        // Hard rule: access-token value must never leak. Public token has
        // no real value — fingerprint is null. Real tokens have a 12-char
        // hex fingerprint, never the raw token.
        if ($row['accessTokenFingerprint'] !== null) {
            expect($row['accessTokenFingerprint'])->toMatch('/^[0-9a-f]{12}$/');
        }

        expect($row)->not->toHaveKey('accessToken');
    }
});
