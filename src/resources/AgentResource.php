<?php

namespace craftpulse\cortex\resources;

use Michtio\CraftCmsClaudeSkills\Skills;

/**
 * =========================================================================
 * Agent-backed MCP resource.
 *
 * One instance per bundled agent in `michtio/craftcms-claude-skills`'s
 * `agents/` directory. Agent files are markdown with YAML frontmatter
 * declaring `name` and `description` — Claude Code's native shape.
 * Cortex surfaces them as MCP resources under the
 * `craft-skills://agents/<name>` URI scheme so other MCP clients can
 * read the same authored expertise even though they don't natively
 * recognise the agent concept.
 *
 * The URI scheme matches `SkillResource`'s `craft-skills://` prefix —
 * agents are part of the same authored body of work — but uses a
 * fixed first segment (`agents/`) to avoid collision with skill names.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  0.1.0
 */
class AgentResource extends AbstractResource
{
    // Constants
    // =========================================================================

    public const URI_SCHEME = 'craft-skills';
    public const URI_PREFIX = 'agents';

    // Private Properties
    // =========================================================================

    /**
     * @var string Agent file basename without `.md`.
     */
    private string $_agent;

    /**
     * @var string Cached URI built once at construction.
     */
    private string $_uri;

    // Public Methods
    // =========================================================================

    /**
     * @param string $agent Bundled agent file basename without `.md`.
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public function __construct(string $agent)
    {
        $this->_agent = $agent;
        $this->_uri = sprintf('%s://%s/%s', self::URI_SCHEME, self::URI_PREFIX, $agent);
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public function getUri(): string
    {
        return $this->_uri;
    }

    /**
     * @inheritdoc
     *
     * Display label: `agents / <name>` so the resource picker groups
     * agents visually under the skills surface.
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public function getName(): string
    {
        return sprintf('agents / %s', $this->_agent);
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public function getDescription(): string
    {
        return sprintf(
            'Claude Code agent definition `%s` — markdown with YAML frontmatter declaring the agent\'s name and one-line description, plus the systemic prompt body.',
            $this->_agent,
        );
    }

    /**
     * @inheritdoc
     *
     * @throws \InvalidArgumentException If the backing agent cannot be read.
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public function read(): array
    {
        $text = Skills::agentContent($this->_agent);

        return [
            'uri' => $this->_uri,
            'mimeType' => $this->getMimeType(),
            'text' => $text,
        ];
    }
}
