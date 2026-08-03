<?php

namespace craftpulse\herald\prompts;

use craftpulse\herald\Herald;
use Michtio\CraftCmsClaudeSkills\Skills;

/**
 * =========================================================================
 * Skill-backed MCP prompt.
 *
 * One instance per bundled skill in `michtio/craftcms-claude-skills`
 * whose handle appears in `Prompts::PROMPT_MAP` (the authoritative
 * whitelist). Element-stored skills with matching handles override the
 * body verbatim via `render()`; element-stored skills NOT in the
 * whitelist surface only as MCP resources, not prompts (locked
 * decision 17 of Gate 8.6).
 *
 * `getName()` returns the public MCP name (e.g. `craftcms_extending`),
 * which differs from the on-disk skill directory name (`craftcms`) —
 * the mapping lives in the `Prompts` service, not here.
 *
 * `render()` returns the full `prompts/get` envelope: a single user
 * message containing the SKILL.md content verbatim. When an element-
 * stored skill exists for this handle, the synthesized override
 * bytestream is returned instead. The skill's references are
 * addressable separately as resources under the
 * `craft-skills://<skill>/<reference>` URI scheme — see `SkillResource`.
 * =========================================================================
 *
 * @author CraftPulse
 * @since  5.0.0
 */
class SkillPrompt extends AbstractPrompt
{
    // Private Properties
    // =========================================================================

    /**
     * @var string Public MCP name (e.g. `craftcms_extending`).
     */
    private string $_name;

    /**
     * @var string Bundled-skills directory name (e.g. `craftcms`).
     */
    private string $_skill;

    /**
     * @var string One-line description shown in `prompts/list`.
     */
    private string $_description;

    // Public Methods
    // =========================================================================

    /**
     * @param string $name        Public MCP prompt name.
     * @param string $skill       Bundled-skills directory name.
     * @param string $description Description shown in `prompts/list`.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function __construct(string $name, string $skill, string $description)
    {
        $this->_name = $name;
        $this->_skill = $skill;
        $this->_description = $description;
    }

    /**
     * @inheritdoc
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function getName(): string
    {
        return $this->_name;
    }

    /**
     * @inheritdoc
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function getDescription(): string
    {
        return $this->_description;
    }

    /**
     * @inheritdoc
     *
     * Returns the `prompts/get` result envelope per MCP spec:
     * `{description, messages: [{role: 'user', content: {type: 'text', text}}]}`.
     * The text is the SKILL.md content verbatim — no post-processing.
     *
     * @throws \InvalidArgumentException If the backing skill cannot be read.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function render(array $arguments): array
    {
        // Consult the Skills service for an element-stored override
        // first; fall through to the bundled filesystem reader when
        // none exists. Same fall-through contract as `SkillResource::read()`.
        $element = Herald::getInstance()->skills->getByHandle($this->_skill);
        $text = $element !== null
            ? Herald::getInstance()->skills->synthesizeContent($element)
            : Skills::content($this->_skill);

        return [
            'description' => $this->_description,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        'type' => 'text',
                        'text' => $text,
                    ],
                ],
            ],
        ];
    }
}
