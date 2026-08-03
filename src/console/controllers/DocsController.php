<?php

namespace craftpulse\herald\console\controllers;

use Composer\InstalledVersions;
use craft\console\Controller;
use craftpulse\herald\Herald;
use craftpulse\herald\tools\AbstractTool;
use craftpulse\herald\tools\ProToolTrait;
use craftpulse\herald\tools\support\AttributeReader;
use craftpulse\herald\tools\ToolInterface;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Generates human-readable Markdown for herald's tool, prompt, and resource
 * registries.
 *
 * Each action walks the runtime registry and writes a versioned snapshot
 * to `docs/<TYPE>.md` at the repo root. Run after registry changes to
 * keep `docs/` in sync; the generated files are committed alongside
 * code so the repo carries its own reference.
 *
 * Usage:
 *   herald/docs/tools       (writes docs/TOOLS.md)
 *   herald/docs/prompts     (writes docs/PROMPTS.md)
 *   herald/docs/resources   (writes docs/RESOURCES.md)
 *   herald/docs/all         (runs all three)
 *
 * `--out=<dir>` overrides the output directory (default: `docs/`
 * relative to the herald package root). Tests use this to write into
 * a tmp directory and assert the output shape.
 *
 * TOOLS.md is edition-independent, byte for byte. The tool list comes
 * from Tools::getAllUnfiltered(), so it documents every tool the source
 * registers and labels each one Free or Pro rather than only the tools
 * the generating install happens to have registered. The schemas come
 * from AbstractTool::docsInputSchema(), which the four dual-edition
 * tools (content_audit, drafts_and_revisions, import_export,
 * system_diagnostics) override to return their complete mode/type enum:
 * their getInputSchema() still gates that enum on the live edition for
 * the wire, and the generator never reads it.
 *
 * RESOURCES.md is install-independent for the same reason: it renders
 * Resources::getBundled(), the resources the package itself ships, and
 * never the element-authored skills an operator added on the generating
 * install.
 *
 * What all three files do depend on is the version of the bundled skills
 * corpus, because the resource and prompt registries enumerate it and
 * search_skills names its size. Each file states that version in its
 * header, so a count that moved is attributable to the corpus rather than
 * to the machine that ran the generator.
 *
 * @author CraftPulse
 * @since  5.0.0
 */
class DocsController extends Controller
{
    // Constants
    // =========================================================================

    /**
     * Tool names starting with this prefix are internal fixtures, not
     * product surface, and are left out of `TOOLS.md`. The only current
     * member is `_streaming_test`, the env-gated SSE wire fixture in
     * `tools/dev/StreamingFixtureTool`; every shipped tool name is
     * plain snake_case with no leading underscore.
     *
     * Public because this controller owns the convention and the tool
     * architecture test reads it rather than re-declaring the prefix.
     * That test also holds the prefixed set to exactly `_streaming_test`,
     * so the exclusion cannot become a way past the authorization
     * classification.
     *
     * @since 5.0.0
     */
    public const INTERNAL_TOOL_PREFIX = '_';

    /**
     * Composer package that ships the skills corpus the resource and
     * prompt registries enumerate. Read for the provenance line every
     * generated file carries in its header.
     *
     * @since 5.0.0
     */
    public const SKILLS_PACKAGE = 'michtio/craftcms-claude-skills';

    // Public Properties
    // =========================================================================

    /**
     * @var string|null Override the output directory. Defaults to the
     * `docs/` folder inside the herald package root.
     */
    public ?string $out = null;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['out']);
    }

    /**
     * Generate `TOOLS.md`, `PROMPTS.md`, and `RESOURCES.md`.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function actionAll(): int
    {
        // Run all three; report failure if any sub-action failed so CI
        // calling `herald/docs/all` can detect a partial write rather
        // than reading the always-OK exit as success. The later docs
        // still attempt to write even if an earlier one errored — a
        // single IO failure shouldn't suppress the others.
        $tools = $this->actionTools();
        $prompts = $this->actionPrompts();
        $resources = $this->actionResources();

        return ($tools === ExitCode::OK && $prompts === ExitCode::OK && $resources === ExitCode::OK)
            ? ExitCode::OK
            : ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * Generate `TOOLS.md`.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function actionTools(): int
    {
        $md = $this->_renderToolsMarkdown($this->_documentedTools());
        return $this->_writeDoc('TOOLS.md', $md);
    }

    /**
     * Generate `PROMPTS.md`.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function actionPrompts(): int
    {
        $prompts = Herald::getInstance()->prompts->getAll();
        $md = $this->_renderPromptsMarkdown($prompts);
        return $this->_writeDoc('PROMPTS.md', $md);
    }

    /**
     * Generate `RESOURCES.md`.
     *
     * Reads `Resources::getBundled()`, not `getAll()`: the runtime
     * registry also carries a resource per element-authored skill, which
     * is the generating install's own content and has no business in the
     * package's committed documentation. The runtime registry keeps
     * serving those resources; only this generator looks away.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function actionResources(): int
    {
        $resources = Herald::getInstance()->resources->getBundled();
        $md = $this->_renderResourcesMarkdown($resources);
        return $this->_writeDoc('RESOURCES.md', $md);
    }

    // Private Methods
    // =========================================================================

    /**
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _outDir(): string
    {
        if ($this->out !== null && $this->out !== '') {
            return rtrim($this->out, '/');
        }
        // src/console/controllers -> ../../../docs
        return realpath(__DIR__ . '/../../../') . '/docs';
    }

    /**
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _writeDoc(string $filename, string $contents): int
    {
        $dir = $this->_outDir();
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            $this->stderr("Could not create output directory: {$dir}\n", Console::FG_RED);
            return ExitCode::IOERR;
        }

        $path = $dir . '/' . $filename;
        $bytes = file_put_contents($path, $contents);
        if ($bytes === false) {
            $this->stderr("Failed to write {$path}\n", Console::FG_RED);
            return ExitCode::IOERR;
        }

        $this->stdout("Wrote {$path} ({$bytes} bytes)\n", Console::FG_GREEN);
        return ExitCode::OK;
    }

    /**
     * Tools to document: every registration the source carries, in
     * registration order, minus internal fixtures.
     *
     * Reads `Tools::getAllUnfiltered()` rather than `getAll()` so the
     * output does not depend on the generating install's edition. The
     * per-tool `**Edition:**` line carries what `getAll()` would have
     * silently encoded by omission.
     *
     * @return ToolInterface[]
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _documentedTools(): array
    {
        $tools = [];
        $seen = [];

        foreach (Herald::getInstance()->tools->getAllUnfiltered() as $tool) {
            $name = $tool::getName();
            if (str_starts_with($name, self::INTERNAL_TOOL_PREFIX)) {
                continue;
            }
            if (isset($seen[$name])) {
                // First registration wins, same as the registry's own
                // collision guard, so a third-party tool shadowing a
                // bundled name can't emit a duplicate heading.
                continue;
            }
            $seen[$name] = true;
            $tools[] = $tool;
        }

        return $tools;
    }

    /**
     * The input schema to document for a tool: `docsInputSchema()` when
     * the tool extends `AbstractTool`, the static `getInputSchema()`
     * otherwise.
     *
     * The fallback is for third-party tools, which `docs/EXTENDING.md`
     * invites to implement `ToolInterface` directly. `docsInputSchema()`
     * is deliberately not on that interface: it is a documentation
     * concern, and widening the wire contract for it would make every
     * third-party tool implement a method the MCP server never calls.
     *
     * @return array<string,mixed>
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _documentedSchema(ToolInterface $tool): array
    {
        return $tool instanceof AbstractTool
            ? $tool::docsInputSchema()
            : $tool::getInputSchema();
    }

    /**
     * Version of the bundled skills corpus, as Composer resolved it.
     * Every generated file's counts move with that corpus, so each one
     * states the version and the number stops being unattributable.
     *
     * Returns `unknown` when the Composer runtime API cannot answer,
     * which happens in a classmap-only autoload setup.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _skillsCorpusVersion(): string
    {
        if (!class_exists(InstalledVersions::class)) {
            return 'unknown';
        }

        try {
            return InstalledVersions::getPrettyVersion(self::SKILLS_PACKAGE) ?? 'unknown';
        } catch (\OutOfBoundsException) {
            return 'unknown';
        }
    }

    /**
     * Whether a tool only registers on Pro installs, resolved from the
     * `ProToolTrait` opt-in that owns the edition gate. Walks parents so
     * a future intermediate base class that carries the trait still
     * resolves.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _requiresPro(ToolInterface $tool): bool
    {
        $classes = array_merge([$tool::class], array_values(class_parents($tool) ?: []));

        foreach ($classes as $class) {
            if (in_array(ProToolTrait::class, class_uses($class) ?: [], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param ToolInterface[] $tools
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _renderToolsMarkdown(array $tools): string
    {
        $count = count($tools);
        $proCount = count(array_filter($tools, fn(ToolInterface $tool): bool => $this->_requiresPro($tool)));
        $freeCount = $count - $proCount;
        $corpus = $this->_skillsCorpusVersion();
        $generatedAt = date('c');

        $md = <<<MD
        # Herald Tools

        Auto-generated reference for the herald MCP tool surface. Run
        `ddev craft herald/docs/tools` to refresh.

        Every tool herald registers is documented and labelled Free or Pro,
        on any edition. `search_skills` names the size of the bundled skills
        corpus in its description, so that one description moves with the
        corpus version below.

        - **Total tools:** {$count} ({$freeCount} Free, {$proCount} Pro)
        - **Skills corpus:** `{$corpus}` (michtio/craftcms-claude-skills)
        - **Generated:** {$generatedAt}


        MD;

        foreach ($tools as $tool) {
            $name = $tool::getName();
            $description = $tool::getDescription();
            $schema = $this->_documentedSchema($tool);
            $annotations = AttributeReader::annotationsFor($tool);
            $stdioOnly = AttributeReader::isStdioOnly($tool);
            $edition = $this->_requiresPro($tool) ? 'Pro' : 'Free';

            $md .= "## `{$name}`\n\n";
            $md .= "**Edition:** {$edition}\n\n";
            $md .= trim($description) . "\n\n";

            if ($annotations !== [] || $stdioOnly) {
                $md .= "**Annotations:**\n\n";
                foreach ($annotations as $key => $value) {
                    $rendered = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
                    $md .= "- `{$key}`: {$rendered}\n";
                }
                if ($stdioOnly) {
                    $md .= "- `stdioOnly`: true\n";
                }
                $md .= "\n";
            }

            $md .= "**Input schema:**\n\n";
            $json = json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $md .= "```json\n{$json}\n```\n\n";

            $outputSchema = $tool::outputSchema();
            if ($outputSchema !== []) {
                $md .= "**Output schema:**\n\n";
                $json = json_encode($outputSchema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                $md .= "```json\n{$json}\n```\n\n";
            }
        }

        return $md;
    }

    /**
     * @param \craftpulse\herald\prompts\PromptInterface[] $prompts
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _renderPromptsMarkdown(array $prompts): string
    {
        $count = count($prompts);
        $corpus = $this->_skillsCorpusVersion();
        $generatedAt = date('c');

        $md = <<<MD
        # Herald Prompts

        Auto-generated reference for the herald MCP prompt surface. Run
        `ddev craft herald/docs/prompts` to refresh.

        One prompt is registered per bundled skill that herald maps to a
        prompt name, so the total moves with the skills corpus version
        below.

        - **Total prompts:** {$count}
        - **Skills corpus:** `{$corpus}` (michtio/craftcms-claude-skills)
        - **Generated:** {$generatedAt}


        MD;

        foreach ($prompts as $prompt) {
            $name = $prompt->getName();
            $description = $prompt->getDescription();
            $arguments = $prompt->getArguments();

            $md .= "## `{$name}`\n\n";
            $md .= trim($description) . "\n\n";

            if ($arguments !== []) {
                $md .= "**Arguments:**\n\n";
                $json = json_encode($arguments, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                $md .= "```json\n{$json}\n```\n\n";
            }
        }

        return $md;
    }

    /**
     * @param \craftpulse\herald\resources\ResourceInterface[] $resources
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _renderResourcesMarkdown(array $resources): string
    {
        $count = count($resources);
        $corpus = $this->_skillsCorpusVersion();
        $generatedAt = date('c');

        $md = <<<MD
        # Herald Resources

        Auto-generated reference for the herald MCP resource surface. Run
        `ddev craft herald/docs/resources` to refresh.

        These are the resources the package ships: one per document in the
        bundled skills corpus, plus one per bundled agent. The total moves
        with the corpus version below. An install also exposes one resource
        per skill authored as a `herald_skills` element, and those are
        per-install content that this reference never lists.

        - **Total resources:** {$count}
        - **Skills corpus:** `{$corpus}` (michtio/craftcms-claude-skills)
        - **Generated:** {$generatedAt}

        | URI | Name | MIME | Description |
        | --- | ---- | ---- | ----------- |

        MD;

        foreach ($resources as $resource) {
            $uri = $resource->getUri();
            $name = $resource->getName();
            $mime = $resource->getMimeType();
            $description = str_replace('|', '\\|', trim($resource->getDescription()));
            // Collapse newlines so the table row stays on one line.
            $description = preg_replace('/\s+/', ' ', $description) ?? $description;

            $md .= "| `{$uri}` | {$name} | `{$mime}` | {$description} |\n";
        }

        return $md;
    }
}
