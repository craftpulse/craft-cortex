<?php

/**
 * =========================================================================
 * Per-prompt rendering: assert the envelope shape MCP requires for
 * `prompts/get` and that the text is the SKILL.md content verbatim.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  0.1.0
 */

use craftpulse\cortex\Plugin;
use Michtio\CraftCmsClaudeSkills\Skills;

it('renders the SKILL.md content verbatim in a single user message', function () {
    $prompt = Plugin::getInstance()->prompts->getByName('craftcms_extending');
    expect($prompt)->not->toBeNull();

    $envelope = $prompt->render([]);

    expect($envelope)
        ->toBeArray()
        ->toHaveKeys(['description', 'messages'])
        ->and($envelope['description'])->toBeString()->not->toBeEmpty();

    expect($envelope['messages'])
        ->toBeArray()
        ->toHaveCount(1);

    $message = $envelope['messages'][0];
    expect($message)
        ->toHaveKeys(['role', 'content'])
        ->and($message['role'])->toBe('user');

    expect($message['content'])
        ->toBeArray()
        ->toHaveKey('type', 'text')
        ->toHaveKey('text');

    // The text should match the bundled SKILL.md byte-for-byte.
    expect($message['content']['text'])->toBe(Skills::content('craftcms'));
});

it('renders every registered prompt without raising', function () {
    foreach (Plugin::getInstance()->prompts->getAll() as $prompt) {
        $envelope = $prompt->render([]);

        expect($envelope)
            ->toHaveKeys(['description', 'messages'])
            ->and($envelope['messages'])->toBeArray()->not->toBeEmpty();

        $text = $envelope['messages'][0]['content']['text'] ?? null;
        expect($text)->toBeString()->not->toBeEmpty();
    }
});

it('renders craftcms_ddev with the ddev SKILL.md content', function () {
    // Pick a different skill than the first test to defend against a
    // stuck-mapping bug where every prompt happens to point at `craftcms`.
    $prompt = Plugin::getInstance()->prompts->getByName('craftcms_ddev');
    expect($prompt)->not->toBeNull();

    $envelope = $prompt->render([]);

    expect($envelope['messages'][0]['content']['text'])->toBe(Skills::content('ddev'));
});
