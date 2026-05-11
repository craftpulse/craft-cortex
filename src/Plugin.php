<?php

namespace craftpulse\cortex;

use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterComponentTypesEvent;
use craft\services\Gc;
use craftpulse\cortex\generator\Tool as ToolGenerator;
use craftpulse\cortex\models\Settings;
use craftpulse\cortex\services\Allowlist;
use craftpulse\cortex\services\Prompts;
use craftpulse\cortex\services\Resources;
use craftpulse\cortex\services\Tools;
use yii\base\Event;

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
 * src/console/controllers/. Web controllers will land alongside the
 * HTTP transport in a future release.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  0.1.0
 *
 * @method static Plugin getInstance()
 * @method Settings getSettings()
 * @property-read Allowlist $allowlist
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
    public bool $hasCpSettings = true;

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
                'allowlist' => ['class' => Allowlist::class],
                'prompts' => ['class' => Prompts::class],
                'resources' => ['class' => Resources::class],
                'tools' => ['class' => Tools::class],
            ],
        ];
    }

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * Registers the cortex-tool generator with Craft's `make` command if
     * the `craftcms/generator` package is installed (always present in
     * dev / playground installs; not bundled with the production
     * `craftcms/cms` runtime). Class-exists guard keeps cortex bootable
     * on installs that strip dev dependencies.
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public function init(): void
    {
        parent::init();

        if (class_exists(\craft\generator\Command::class)) {
            Event::on(
                \craft\generator\Command::class,
                \craft\generator\Command::EVENT_REGISTER_GENERATORS,
                static function(RegisterComponentTypesEvent $event): void {
                    $event->types[] = ToolGenerator::class;
                },
            );
        }

        // Prune expired runtime-override rows during Craft's gc sweep.
        // The delete is a single indexed deleteAll, faster than the
        // overhead of pushing a queue job — and Craft's gc already
        // runs heavier cleanups inline in the same pass.
        Event::on(
            Gc::class,
            Gc::EVENT_RUN,
            static function(): void {
                Plugin::getInstance()->allowlist->pruneExpired();
            },
        );
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

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    protected function settingsHtml(): ?string
    {
        return \Craft::$app->getView()->renderTemplate('cortex/settings', [
            'plugin' => $this,
            'settings' => $this->getSettings(),
            'overrides' => $this->allowlist->getAllOverrides(includeExpired: true),
        ]);
    }
}
