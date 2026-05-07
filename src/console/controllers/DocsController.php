<?php

namespace craftpulse\cortex\console\controllers;

use craft\console\Controller;
use craftpulse\cortex\Plugin;
use craftpulse\cortex\tools\support\AttributeReader;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * =========================================================================
 * Console — generate human-readable Markdown for cortex's tool, prompt,
 * and resource registries.
 *
 * Each action walks the runtime registry and writes a versioned snapshot
 * to `docs/<TYPE>.md` at the repo root. Run after registry changes to
 * keep `docs/` in sync; the generated files are committed alongside
 * code so the repo carries its own reference.
 *
 * Usage:
 *   cortex/docs/tools       (writes docs/TOOLS.md)
 *   cortex/docs/prompts     (writes docs/PROMPTS.md)
 *   cortex/docs/resources   (writes docs/RESOURCES.md)
 *   cortex/docs/all         (runs all three)
 *
 * `--out=<dir>` overrides the output directory (default: `docs/`
 * relative to the cortex package root). Tests use this to write into
 * a tmp directory and assert the output shape.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  0.1.0
 */
class DocsController extends Controller
{
    // Public Properties
    // =========================================================================

    /**
     * @var string|null Override the output directory. Defaults to the
     *                  `docs/` folder inside the cortex package root.
     */
    public ?string $out = null;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['out']);
    }

    /**
     * Generate `TOOLS.md`, `PROMPTS.md`, and `RESOURCES.md`.
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public function actionAll(): int
    {
        $this->actionTools();
        $this->actionPrompts();
        $this->actionResources();
        return ExitCode::OK;
    }

    /**
     * Generate `TOOLS.md`.
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public function actionTools(): int
    {
        $tools = Plugin::getInstance()->tools->getAll();
        $md = $this->_renderToolsMarkdown($tools);
        return $this->_writeDoc('TOOLS.md', $md);
    }

    /**
     * Generate `PROMPTS.md`.
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public function actionPrompts(): int
    {
        $prompts = Plugin::getInstance()->prompts->getAll();
        $md = $this->_renderPromptsMarkdown($prompts);
        return $this->_writeDoc('PROMPTS.md', $md);
    }

    /**
     * Generate `RESOURCES.md`.
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public function actionResources(): int
    {
        $resources = Plugin::getInstance()->resources->getAll();
        $md = $this->_renderResourcesMarkdown($resources);
        return $this->_writeDoc('RESOURCES.md', $md);
    }

    // Private Methods
    // =========================================================================

    /**
     * @author Craftpulse
     * @since  0.1.0
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
     * @author Craftpulse
     * @since  0.1.0
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
     * @param \craftpulse\cortex\tools\ToolInterface[] $tools
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    private function _renderToolsMarkdown(array $tools): string
    {
        $count = count($tools);
        $generatedAt = date('c');

        $md = <<<MD
        # Cortex Tools

        Auto-generated reference for the cortex MCP tool surface. Run
        `ddev craft cortex/docs/tools` to refresh.

        - **Total tools:** {$count}
        - **Generated:** {$generatedAt}


        MD;

        foreach ($tools as $tool) {
            $name = $tool::getName();
            $description = $tool::getDescription();
            $schema = $tool::getInputSchema();
            $annotations = AttributeReader::annotationsFor($tool);
            $stdioOnly = AttributeReader::isStdioOnly($tool);

            $md .= "## `{$name}`\n\n";
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
        }

        return $md;
    }

    /**
     * @param \craftpulse\cortex\prompts\PromptInterface[] $prompts
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    private function _renderPromptsMarkdown(array $prompts): string
    {
        $count = count($prompts);
        $generatedAt = date('c');

        $md = <<<MD
        # Cortex Prompts

        Auto-generated reference for the cortex MCP prompt surface. Run
        `ddev craft cortex/docs/prompts` to refresh.

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
     * @param \craftpulse\cortex\resources\ResourceInterface[] $resources
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    private function _renderResourcesMarkdown(array $resources): string
    {
        $count = count($resources);
        $generatedAt = date('c');

        $md = <<<MD
        # Cortex Resources

        Auto-generated reference for the cortex MCP resource surface. Run
        `ddev craft cortex/docs/resources` to refresh.

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
