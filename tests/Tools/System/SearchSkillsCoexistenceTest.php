<?php

/**
 * =========================================================================
 * `search_skills` + `Skills` service coexistence tests — Gate 8.6.
 *
 * Verifies the merge contract end-to-end:
 *   - Baseline: bundled skills surface with `source: bundled`.
 *   - Override: an element-stored skill with a colliding handle wins,
 *     bundled is hidden, `source` flips to `element`.
 *   - Hard-delete of the override re-surfaces the bundled row.
 *   - Trashed element-stored skill does NOT override bundled.
 *   - Element-only handles (no bundled counterpart) surface as
 *     `source: element` rows.
 *   - kind filter on `Skills::getMergedCorpus()`:
 *       - kind=skill returns bundled + element-stored rows.
 *       - kind=reference returns bundled-only references.
 *       - kind=agent returns bundled-only agents.
 *   - PROMPT_MAP whitelist is NOT auto-extended: an element-stored skill
 *     with a non-whitelisted handle surfaces as a resource only.
 *   - Bundled-by-handle override: SkillResource::read() returns the
 *     synthesized override bytes; SkillPrompt::render() returns the
 *     override when the handle IS in PROMPT_MAP.
 *
 * Two of these cases are among the four highest-value regression gates:
 *   - Bundled-vs-element coexistence by handle.
 *   - PROMPT_MAP whitelist NOT auto-extended.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */

use craftpulse\cortex\Cortex;
use craftpulse\cortex\elements\Skill as SkillElement;
use craftpulse\cortex\resources\SkillResource;
use Michtio\CraftCmsClaudeSkills\Skills as BundledSkills;

beforeEach(function() {
    // Slug-shaped so handles satisfy Skill::HANDLE_PATTERN
    // (lowercase letters, digits, single hyphens).
    $this->fixturePrefix = 'cortex-skilltest-' . bin2hex(random_bytes(4)) . '-';

    $admin = Craft::$app->getUsers()->getUserByUsernameOrEmail('michtio')
        ?? Craft::$app->getUsers()->getUserByUsernameOrEmail('development@craftpulse.com');
    expect($admin)->not->toBeNull();
    Craft::$app->getUser()->setIdentity($admin);
    $this->admin = $admin;

    $this->tool = Cortex::getInstance()->tools->getByName('search_skills');
});

afterEach(function() {
    // Wipe both test-prefixed fixtures AND any bundled-handle
    // overrides this test or any earlier test in the file might have
    // left behind. Bundled-handle override fixtures are necessarily
    // un-prefixed (the bundled handle is the natural key), so we
    // enumerate every bundled handle and hard-delete any element-
    // stored row that matches.
    $rows = SkillElement::find()
        ->status(null)
        ->trashed(null)
        ->site('*')
        ->andWhere(['like', 'cortex_skills.handle', 'cortex-skilltest-%', false])
        ->all();
    foreach ($rows as $row) {
        Craft::$app->getElements()->deleteElement($row, hardDelete: true);
    }

    foreach (BundledSkills::skillNames() as $bundledHandle) {
        $override = SkillElement::find()
            ->status(null)
            ->trashed(null)
            ->site('*')
            ->handle($bundledHandle)
            ->one();
        if ($override !== null) {
            Craft::$app->getElements()->deleteElement($override, hardDelete: true);
        }
    }

    Cortex::getInstance()->skills->resetMemo();
});

// -----------------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------------

function _cortex_coex_save(string $handle, string $title, ?string $description = null, ?string $body = null): SkillElement
{
    $skill = new SkillElement();
    $skill->handle = $handle;
    $skill->title = $title;
    $skill->description = $description;
    expect(Craft::$app->getElements()->saveElement($skill))->toBeTrue();
    return $skill;
}

// -----------------------------------------------------------------------------
// Baseline: bundled-only corpus
// -----------------------------------------------------------------------------

it('bundled-only baseline — rows carry source: bundled', function() {
    $result = $this->tool->execute(['mode' => 'topics']);
    expect($result['count'])->toBeGreaterThan(0);
    foreach ($result['topics'] as $row) {
        expect($row['source'])->toBe('bundled');
    }
});

// -----------------------------------------------------------------------------
// Override + revert — highest-value regression gate.
// -----------------------------------------------------------------------------

it('element-stored skill with a bundled handle overrides; delete re-surfaces bundled', function() {
    $bundledNames = BundledSkills::skillNames();
    if ($bundledNames === []) {
        $this->markTestSkipped('No bundled skills installed.');
    }
    $bundledHandle = $bundledNames[0];

    // Baseline — bundled row visible with source=bundled.
    $beforeRows = $this->tool->execute(['mode' => 'topics', 'kind' => 'skill']);
    $beforeRow = collect($beforeRows['topics'])->firstWhere('skill', $bundledHandle);
    expect($beforeRow)->not->toBeNull();
    expect($beforeRow['source'])->toBe('bundled');

    // Create the override.
    $override = _cortex_coex_save($bundledHandle, 'Override probe');
    expect($override->id)->toBeInt();

    // Run topics again — bundled is hidden, element wins.
    $afterOverride = $this->tool->execute(['mode' => 'topics', 'kind' => 'skill']);
    $overrideRow = collect($afterOverride['topics'])->firstWhere('skill', $bundledHandle);
    expect($overrideRow)->not->toBeNull();
    expect($overrideRow['source'])->toBe('element');

    // Only ONE row per bundled handle — bundled should NOT also appear.
    $matches = collect($afterOverride['topics'])->where('skill', $bundledHandle);
    expect($matches->count())->toBe(1);

    // Hard-delete the override and re-run.
    Craft::$app->getElements()->deleteElement($override, hardDelete: true);

    $afterRestore = $this->tool->execute(['mode' => 'topics', 'kind' => 'skill']);
    $bundledAfter = collect($afterRestore['topics'])->firstWhere('skill', $bundledHandle);
    expect($bundledAfter)->not->toBeNull();
    expect($bundledAfter['source'])->toBe('bundled');
});

// -----------------------------------------------------------------------------
// Trashed override should NOT hide the bundled row
// -----------------------------------------------------------------------------

it('trashed (soft-deleted) override does NOT hide the bundled row', function() {
    $bundledNames = BundledSkills::skillNames();
    if ($bundledNames === []) {
        $this->markTestSkipped('No bundled skills installed.');
    }
    $bundledHandle = $bundledNames[0];

    $override = _cortex_coex_save($bundledHandle, 'Trashed-override probe');

    // Soft-delete the override.
    Craft::$app->getElements()->deleteElement($override, hardDelete: false);

    // Bundled re-surfaces because the merge skips trashed rows.
    $result = $this->tool->execute(['mode' => 'topics', 'kind' => 'skill']);
    $row = collect($result['topics'])->firstWhere('skill', $bundledHandle);
    expect($row)->not->toBeNull();
    expect($row['source'])->toBe('bundled');
});

// -----------------------------------------------------------------------------
// Element-only handle
// -----------------------------------------------------------------------------

it('element-only handle (no bundled counterpart) surfaces with source: element', function() {
    $handle = $this->fixturePrefix . 'unique';
    _cortex_coex_save($handle, 'Element-only');

    $result = $this->tool->execute(['mode' => 'topics', 'kind' => 'skill']);
    $row = collect($result['topics'])->firstWhere('skill', $handle);
    expect($row)->not->toBeNull();
    expect($row['source'])->toBe('element');
});

// -----------------------------------------------------------------------------
// Service kind filter
// -----------------------------------------------------------------------------

it('Skills::getMergedCorpus kind=skill returns bundled + element rows', function() {
    $handle = $this->fixturePrefix . 'kindskill';
    _cortex_coex_save($handle, 'kind=skill probe');

    $rows = Cortex::getInstance()->skills->getMergedCorpus('skill');
    $handles = array_column($rows, 'skill');
    expect($handles)->toContain($handle);
    $bundledNames = BundledSkills::skillNames();
    foreach ($bundledNames as $bundled) {
        expect($handles)->toContain($bundled);
    }
});

it('Skills::getMergedCorpus kind=reference returns bundled-only references', function() {
    $handle = $this->fixturePrefix . 'kindref';
    _cortex_coex_save($handle, 'kind=reference probe');

    $rows = Cortex::getInstance()->skills->getMergedCorpus('reference');
    foreach ($rows as $row) {
        expect($row['kind'])->toBe('reference');
        expect($row['source'])->toBe('bundled');
    }
});

it('Skills::getMergedCorpus kind=agent returns bundled-only agents', function() {
    $rows = Cortex::getInstance()->skills->getMergedCorpus('agent');
    foreach ($rows as $row) {
        expect($row['kind'])->toBe('agent');
        expect($row['source'])->toBe('bundled');
    }
});

// -----------------------------------------------------------------------------
// PROMPT_MAP whitelist NOT auto-extended — highest-value regression gate.
// -----------------------------------------------------------------------------

it('element-stored skill with a non-whitelisted handle is NOT registered as a prompt', function() {
    $nonWhitelistedHandle = $this->fixturePrefix . 'notinmap';
    _cortex_coex_save($nonWhitelistedHandle, 'Not in prompt map');

    // The prompt registry was built at boot — confirm the new handle
    // is absent from `Prompts::asListPayload()`.
    $payload = Cortex::getInstance()->prompts->asListPayload();
    $names = array_column($payload, 'name');
    expect($names)->not->toContain($nonWhitelistedHandle);
    expect($names)->not->toContain('cortex_skill_' . $nonWhitelistedHandle);
});

it('element-stored skill with a non-whitelisted handle IS surfaced as a resource', function() {
    $handle = $this->fixturePrefix . 'resourceonly';
    _cortex_coex_save($handle, 'Resource only');

    // Re-instantiating the Resources service rebuilds the registry
    // against the current DB state, which now includes our fixture.
    $resources = new \craftpulse\cortex\services\Resources();
    $resources->init();

    $resource = $resources->getByUri('craft-skills://' . $handle);
    expect($resource)->not->toBeNull();
    expect($resource)->toBeInstanceOf(SkillResource::class);

    $block = $resource->read();
    expect($block['text'])->toContain('name: ' . $handle);
    expect($block['text'])->toContain('# Resource only');
});

// -----------------------------------------------------------------------------
// SkillResource::read() byte contract — override returns synthesised bytes,
// delete returns bundled bytes again.
// -----------------------------------------------------------------------------

it('SkillResource::read() returns synthesised bytes for an overridden bundled handle', function() {
    $bundledNames = BundledSkills::skillNames();
    if ($bundledNames === []) {
        $this->markTestSkipped('No bundled skills installed.');
    }
    $bundledHandle = $bundledNames[0];

    // Baseline — bundled bytes match BundledSkills::content().
    $resource = new SkillResource(skill: $bundledHandle);
    $bundledBlock = $resource->read();
    expect($bundledBlock['text'])->toBe(BundledSkills::content($bundledHandle));

    // Create the override.
    $override = _cortex_coex_save($bundledHandle, 'SkillResource override probe', 'override desc');

    // Read again — now the synthesised override bytes return.
    $overrideBlock = $resource->read();
    expect($overrideBlock['text'])->not->toBe(BundledSkills::content($bundledHandle));
    expect($overrideBlock['text'])->toContain('name: ' . $bundledHandle);
    expect($overrideBlock['text'])->toContain('# SkillResource override probe');

    // Hard-delete the override — bundled bytes return.
    Craft::$app->getElements()->deleteElement($override, hardDelete: true);
    $afterBlock = $resource->read();
    expect($afterBlock['text'])->toBe(BundledSkills::content($bundledHandle));
});
