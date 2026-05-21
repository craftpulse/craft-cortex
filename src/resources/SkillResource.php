<?php

namespace craftpulse\cortex\resources;

use craftpulse\cortex\Cortex;
use Michtio\CraftCmsClaudeSkills\Skills;

/**
 * =========================================================================
 * Skill-backed MCP resource.
 *
 * One instance per addressable URI in the merged skills corpus:
 *   - `craft-skills://<skill>`               -> SKILL.md router
 *     (or synthesized element bytes when an element-stored skill
 *     exists with the same handle — locked decision 17 of Gate 8.6)
 *   - `craft-skills://<skill>/<reference>`   -> references/<reference>.md
 *     (always bundled — element override is SKILL.md only)
 *
 * Constructed with `(skill, reference?)`. When `reference` is null the
 * resource surfaces the skill's SKILL.md (or its element override);
 * otherwise it surfaces the named reference document. The URI is
 * derived from the constructor arguments — never accept a URI as
 * input here, build it.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */
class SkillResource extends AbstractResource
{
    // Constants
    // =========================================================================

    public const URI_SCHEME = 'craft-skills';

    // Private Properties
    // =========================================================================

    /**
     * @var string Bundled-skills directory name (e.g. `craftcms`).
     */
    private string $_skill;

    /**
     * @var string|null Reference document name without `.md`, or null
     *                  for the SKILL.md router.
     */
    private ?string $_reference;

    /**
     * @var string Cached URI built once at construction.
     */
    private string $_uri;

    // Public Methods
    // =========================================================================

    /**
     * @param string      $skill     Bundled-skills directory name.
     * @param string|null $reference Reference name without `.md`, or
     *                               null for the skill's SKILL.md.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function __construct(string $skill, ?string $reference = null)
    {
        $this->_skill = $skill;
        $this->_reference = $reference;
        $this->_uri = $reference === null
            ? sprintf('%s://%s', self::URI_SCHEME, $skill)
            : sprintf('%s://%s/%s', self::URI_SCHEME, $skill, $reference);
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function getUri(): string
    {
        return $this->_uri;
    }

    /**
     * @inheritdoc
     *
     * Display label: `<skill>` for the router, `<skill> / <reference>`
     * for a deep-dive document. Plain-text label suitable for picker
     * UIs in MCP clients.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function getName(): string
    {
        return $this->_reference === null
            ? $this->_skill
            : sprintf('%s / %s', $this->_skill, $this->_reference);
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function getDescription(): string
    {
        return $this->_reference === null
            ? sprintf('Top-level router for the %s skill (SKILL.md). Lists when to load each reference document and the cross-cutting pitfalls that apply across them.', $this->_skill)
            : sprintf('Deep-dive reference document `%s` from the %s skill.', $this->_reference, $this->_skill);
    }

    /**
     * @inheritdoc
     *
     * @throws \InvalidArgumentException If the backing skill or
     *                                   reference cannot be read.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function read(): array
    {
        if ($this->_reference !== null) {
            // References are bundled-only — locked decision 17 keeps
            // override scope on SKILL.md.
            $text = Skills::referenceContent($this->_skill, $this->_reference);
        } else {
            // SKILL.md path: consult the Skills service first. When an
            // element-stored skill exists for this handle, synthesize
            // the override bytestream. Otherwise fall through to the
            // bundled filesystem reader.
            $element = Cortex::getInstance()->skills->getByHandle($this->_skill);
            $text = $element !== null
                ? Cortex::getInstance()->skills->synthesizeContent($element)
                : Skills::content($this->_skill);
        }

        return [
            'uri' => $this->_uri,
            'mimeType' => $this->getMimeType(),
            'text' => $text,
        ];
    }
}
