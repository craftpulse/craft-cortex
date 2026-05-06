<?php

namespace craftpulse\cortex\prompts;

use Michtio\CraftCmsClaudeSkills\Skills;

/**
 * =========================================================================
 * Skill-backed MCP prompt.
 *
 * One instance per bundled skill in `michtio/craftcms-claude-skills`.
 * `getName()` returns the public MCP name (e.g. `craftcms_extending`),
 * which differs from the on-disk skill directory name (`craftcms`) —
 * the mapping lives in the `Prompts` service, not here.
 *
 * `render()` returns the full `prompts/get` envelope: a single user
 * message containing the SKILL.md content verbatim. The skill's
 * references are addressable separately as resources under the
 * `craft-skills://<skill>/<reference>` URI scheme — see `SkillResource`.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  0.1.0
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
     * @author Craftpulse
     * @since  0.1.0
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
     * @author Craftpulse
     * @since  0.1.0
     */
    public function getName(): string
    {
        return $this->_name;
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public function getDescription(): string
    {
        return $this->_description;
    }

    /**
     * The on-disk skill directory name backing this prompt.
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public function getSkill(): string
    {
        return $this->_skill;
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
     * @author Craftpulse
     * @since  0.1.0
     */
    public function render(array $arguments): array
    {
        $text = Skills::content($this->_skill);

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
