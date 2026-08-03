<?php

/**
 * =========================================================================
 * `Resources::getBundled()` — the package-only half of the resource
 * registry, and the guarantee that `docs/RESOURCES.md` never carries an
 * install's own content.
 *
 * The registry is built in two passes. Pass 1 is what the package ships:
 * one resource per bundled-skill document plus one per bundled agent.
 * Pass 2 appends one resource per element-authored skill handle, which is
 * the operator's own content (tone of voice, brand and copywriting
 * guidance). `resources/list` serves both. The docs generator must read
 * pass 1 only, or regenerating the committed reference on a real install
 * writes that install's database into published documentation.
 *
 * Every test here seeds a `herald_skills` element and rebuilds the
 * registry so the seeded handle is genuinely present in `getAll()` before
 * asserting it is absent from `getBundled()` and from the generated
 * markdown. Without the rebuild the assertions would pass vacuously: the
 * boot-time registry was built before the seed, and the fixtures table is
 * empty.
 *
 * Fixture strategy mirrors `tests/Elements/SkillTest.php`: a slug-shaped
 * `herald-bundledreg-<hex>-` prefix, hard-deleted in `afterEach`. The
 * swapped-in `Resources` component is restored there too.
 * =========================================================================
 *
 * @author CraftPulse
 * @since  5.0.0
 */

use craftpulse\herald\elements\Skill;
use craftpulse\herald\Herald;
use craftpulse\herald\resources\ResourceInterface;
use craftpulse\herald\resources\SkillResource;
use craftpulse\herald\services\Resources;
use Michtio\CraftCmsClaudeSkills\Skills;

// -----------------------------------------------------------------------------
// Setup
// -----------------------------------------------------------------------------

beforeEach(function() {
    $this->tmp = sys_get_temp_dir() . '/herald-bundled-' . uniqid();
    mkdir($this->tmp, 0755, true);

    $this->fixturePrefix = 'herald-bundledreg-' . bin2hex(random_bytes(4)) . '-';
    $this->originalResources = Herald::getInstance()->resources;

    $admin = herald_admin_user();
    expect($admin)->not->toBeNull();
    Craft::$app->getUser()->setIdentity($admin);

    // Seed one element-authored skill, then hand herald a registry built
    // after the seed. `set()` with an object instance replaces the
    // component; `afterEach` puts the boot-time service back.
    $this->elementHandle = $this->fixturePrefix . 'tone-of-voice';
    $skill = new Skill();
    $skill->handle = $this->elementHandle;
    $skill->title = 'Tone of voice';
    $skill->description = 'Per-install copywriting guidance.';
    expect(Craft::$app->getElements()->saveElement($skill))->toBeTrue();

    Herald::getInstance()->skills->resetMemo();
    Herald::getInstance()->set('resources', new Resources());
});

afterEach(function() {
    Herald::getInstance()->set('resources', $this->originalResources);

    $rows = Skill::find()
        ->status(null)
        ->trashed(null)
        ->site('*')
        ->andWhere(['like', 'herald_skills.handle', $this->fixturePrefix . '%', false])
        ->all();
    foreach ($rows as $row) {
        Craft::$app->getElements()->deleteElement($row, hardDelete: true);
    }

    Herald::getInstance()->skills->resetMemo();

    if (is_dir($this->tmp)) {
        foreach (glob($this->tmp . '/*') as $file) {
            unlink($file);
        }
        rmdir($this->tmp);
    }
});

// -----------------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------------

/**
 * URIs of a resource list, for set assertions.
 *
 * @param ResourceInterface[] $resources
 * @return string[]
 */
function herald_bundled_uris(array $resources): array
{
    return array_map(static fn(ResourceInterface $r): string => $r->getUri(), $resources);
}

// -----------------------------------------------------------------------------
// Registry split
// -----------------------------------------------------------------------------

it('serves the element-authored skill from the live registry', function() {
    // Non-vacuity guard for every assertion below. If this fails, the seed
    // never reached the registry and the exclusion assertions prove nothing.
    $uri = sprintf('%s://%s', SkillResource::URI_SCHEME, $this->elementHandle);
    $resources = Herald::getInstance()->resources;

    expect(herald_bundled_uris($resources->getAll()))->toContain($uri);
    expect($resources->getByUri($uri))->toBeInstanceOf(ResourceInterface::class);
});

it('leaves the element-authored skill out of getBundled', function() {
    $uri = sprintf('%s://%s', SkillResource::URI_SCHEME, $this->elementHandle);

    expect(herald_bundled_uris(Herald::getInstance()->resources->getBundled()))->not->toContain($uri);
});

it('returns exactly the package-shipped resources from getBundled', function() {
    $expected = count(Skills::agentNames());
    foreach (Skills::skillNames() as $skill) {
        $expected += 1 + count(Skills::references($skill));
    }

    $resources = Herald::getInstance()->resources;

    expect($resources->getBundled())->toHaveCount($expected);
    expect($resources->getAll())->toHaveCount($expected + 1);
});

it('keeps every bundled router URI in getBundled', function() {
    $uris = herald_bundled_uris(Herald::getInstance()->resources->getBundled());

    foreach (Skills::skillNames() as $skill) {
        expect($uris)->toContain(sprintf('%s://%s', SkillResource::URI_SCHEME, $skill));
    }
});

// -----------------------------------------------------------------------------
// Generator isolation
// -----------------------------------------------------------------------------

it('never writes an element-authored skill into the generated RESOURCES.md', function() {
    Craft::$app->runAction('herald/docs/resources', ['out' => $this->tmp]);

    $contents = file_get_contents($this->tmp . '/RESOURCES.md');

    expect($contents)->not->toContain($this->elementHandle);
    expect($contents)->not->toContain('Tone of voice');
    expect($contents)->not->toContain('Per-install copywriting guidance.');
});

it('counts only the bundled resources in the generated RESOURCES.md', function() {
    Craft::$app->runAction('herald/docs/resources', ['out' => $this->tmp]);

    $contents = file_get_contents($this->tmp . '/RESOURCES.md');
    $resources = Herald::getInstance()->resources;

    expect($contents)->toContain(sprintf('**Total resources:** %d', count($resources->getBundled())));
    expect($contents)->not->toContain(sprintf('**Total resources:** %d', count($resources->getAll())));
});

it('states the skills corpus version the generated RESOURCES.md was built against', function() {
    // The resource and prompt counts move with the bundled corpus, so both
    // references name the version rather than leaving the number
    // unattributable.
    Craft::$app->runAction('herald/docs/all', ['out' => $this->tmp]);

    $version = Composer\InstalledVersions::getPrettyVersion('michtio/craftcms-claude-skills');

    expect(file_get_contents($this->tmp . '/RESOURCES.md'))
        ->toContain(sprintf('**Skills corpus:** `%s`', $version));
    expect(file_get_contents($this->tmp . '/PROMPTS.md'))
        ->toContain(sprintf('**Skills corpus:** `%s`', $version));
});

it('still lists the element-authored skill in the resources/list payload', function() {
    // The generator looks away from element content; the MCP surface does
    // not. This is the runtime behaviour the docs change must not touch.
    $uri = sprintf('%s://%s', SkillResource::URI_SCHEME, $this->elementHandle);

    $uris = array_column(Herald::getInstance()->resources->asListPayload(), 'uri');

    expect($uris)->toContain($uri);
});
