<?php

namespace craftpulse\cortex;

use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craftpulse\cortex\models\Settings;
use craftpulse\cortex\services\Prompts;
use craftpulse\cortex\services\Resources;
use craftpulse\cortex\services\Tools;

/**
 * =========================================================================
 * Cortex plugin entry point.
 *
 * Registers under handle `cortex` and wires three services so the MCP
 * server (`cortex/serve` console controller) can resolve them:
 *   - `Plugin::getInstance()->tools`     -> tool registry
 *   - `Plugin::getInstance()->prompts`   -> skill-backed prompts
 *   - `Plugin::getInstance()->resources` -> skill-backed resources
 *
 * Console controllers are auto-discovered by Craft from
 * src/console/controllers/. Web controllers will land alongside HTTP
 * transport in Phase 2 per PLANNING.md section 4.7.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  0.1.0
 *
 * @method static Plugin getInstance()
 * @method Settings getSettings()
 * @property-read Prompts $prompts
 * @property-read Resources $resources
 * @property-read Tools $tools
 */
class Plugin extends BasePlugin
{
    // Public Properties
    // =========================================================================

    /**
     * @inheritdoc
     */
    public string $schemaVersion = '0.1.0';

    /**
     * @inheritdoc
     */
    public bool $hasCpSettings = false;

    /**
     * @inheritdoc
     */
    public bool $hasCpSection = false;

    // Static Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public static function config(): array
    {
        return [
            'components' => [
                'prompts' => ['class' => Prompts::class],
                'resources' => ['class' => Resources::class],
                'tools' => ['class' => Tools::class],
            ],
        ];
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }
}
