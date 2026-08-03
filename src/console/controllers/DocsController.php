<?php

namespace craftpulse\herald\console\controllers;

use craft\console\Controller;
use craftpulse\herald\Herald;
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
 * The tool set is edition-independent on purpose: TOOLS.md documents
 * every tool the source registers and labels each one Free or Pro,
 * rather than only the tools the generating install happens to have
 * registered. What is still edition-scoped is the mode enum inside the
 * four dual-edition tools' input schemas (content_audit,
 * drafts_and_revisions, import_export, system_diagnostics), because
 * their getInputSchema() gates the enum on the live edition by design.
 * Generate the committed reference on a Pro install to capture the
 * complete schema surface; on a Free install the generator says so in
 * the output and on stderr rather than shrinking the file silently.
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
        if (!Herald::getInstance()->is(Herald::EDITION_PRO, '>=')) {
            $this->stderr(
                "Generating on a Free install: every tool is documented, but the mode enums of "
                . "content_audit, drafts_and_revisions, import_export and system_diagnostics omit "
                . "their Pro modes. Regenerate on Pro before committing the reference.\n",
                Console::FG_YELLOW,
            );
        }

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
     * @author CraftPulse
     * @since  5.0.0
     */
    public function actionResources(): int
    {
        $resources = Herald::getInstance()->resources->getAll();
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
        $generatedAt = date('c');

        $md = <<<MD
        # Herald Tools

        Auto-generated reference for the herald MCP tool surface. Run
        `ddev craft herald/docs/tools` to refresh.

        - **Total tools:** {$count} ({$freeCount} Free, {$proCount} Pro)
        - **Generated:** {$generatedAt}


        MD;

        if (!Herald::getInstance()->is(Herald::EDITION_PRO, '>=')) {
            // Stays out of the header on a Pro run, so a Free-generated
            // reference is visible in review as an added line rather than
            // as four quietly shortened enums.
            $md .= "> Generated on a Free install: the mode enums of `content_audit`,\n"
                . "> `drafts_and_revisions`, `import_export` and `system_diagnostics` omit their\n"
                . "> Pro modes. Regenerate on Pro for the complete schema surface.\n\n";
        }

        foreach ($tools as $tool) {
            $name = $tool::getName();
            $description = $tool::getDescription();
            $schema = $tool::getInputSchema();
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
        $generatedAt = date('c');

        $md = <<<MD
        # Herald Prompts

        Auto-generated reference for the herald MCP prompt surface. Run
        `ddev craft herald/docs/prompts` to refresh.

        - **Total prompts:** {$count}
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
        $generatedAt = date('c');

        $md = <<<MD
        # Herald Resources

        Auto-generated reference for the herald MCP resource surface. Run
        `ddev craft herald/docs/resources` to refresh.

        - **Total resources:** {$count}
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
