<?php

namespace craftpulse\herald\tools\system;

use Craft;
use craft\base\PluginInterface;
use craftpulse\herald\attributes\IsIdempotent;
use craftpulse\herald\attributes\IsReadOnly;
use craftpulse\herald\tools\AbstractTool;
use craftpulse\herald\tools\support\Schema;

/**
 * =========================================================================
 * `plugins` tool — installed plugins map.
 *
 * Returns every plugin Craft knows about, whether currently enabled or
 * not, including handle, name, version, edition (if applicable),
 * developer, schemaVersion, license status. Useful for the AI to
 * understand what extension surface is available before suggesting an
 * approach (e.g. "is SEOmatic installed?", "is Blitz on?").
 *
 * No parameters.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */
#[IsReadOnly]
#[IsIdempotent]
class Plugins extends AbstractTool
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public static function getName(): string
    {
        return 'plugins';
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public static function getDescription(): string
    {
        return 'List every installed plugin (enabled or not). Returns handle, name, version, ' .
            'edition, developer, schemaVersion, enabled flag, and license status. Use to ' .
            'discover what extension surface the project has before recommending an approach.';
    }

    /**
     * @inheritdoc
     *
     * Stable single-shape return — no list/single/count polymorphism — so
     * the schema can describe every key precisely. Plugin metadata keys
     * Craft cannot resolve come back as `null`, hence the optional
     * (non-required) annotation on most fields.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public static function outputSchema(): array
    {
        $pluginEntry = Schema::object([
            'handle' => Schema::string()->required(),
            'name' => Schema::string(),
            'version' => Schema::string(),
            'schemaVersion' => Schema::string(),
            'edition' => Schema::string(),
            'hasMultipleEditions' => Schema::boolean()->required(),
            'developer' => Schema::string(),
            'developerUrl' => Schema::string(),
            'documentationUrl' => Schema::string(),
            'description' => Schema::string(),
            'isInstalled' => Schema::boolean()->required(),
            'isEnabled' => Schema::boolean()->required(),
            'moduleId' => Schema::string(),
            'licenseKeyStatus' => Schema::string(),
            'licenseIssues' => Schema::array(Schema::any())->required(),
        ]);

        return Schema::object([
            'plugins' => Schema::array($pluginEntry)->required(),
            'count' => Schema::integer()->required(),
            'enabledCount' => Schema::integer()->required(),
        ])->toArray();
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function execute(array $arguments): array
    {
        $service = Craft::$app->getPlugins();
        $allPlugins = $service->getAllPlugins();
        $allInfo = $service->getAllPluginInfo();

        $list = [];
        foreach ($allInfo as $handle => $info) {
            /** @var PluginInterface|null $plugin */
            $plugin = $allPlugins[$handle] ?? null;

            $list[] = [
                'handle' => $handle,
                'name' => $info['name'] ?? null,
                'version' => $info['version'] ?? null,
                'schemaVersion' => $info['schemaVersion'] ?? null,
                'edition' => $info['edition'] ?? null,
                'hasMultipleEditions' => $info['hasMultipleEditions'] ?? false,
                'developer' => $info['developer'] ?? null,
                'developerUrl' => $info['developerUrl'] ?? null,
                'documentationUrl' => $info['documentationUrl'] ?? null,
                'description' => $info['description'] ?? null,
                'isInstalled' => $info['isInstalled'] ?? false,
                'isEnabled' => $info['isEnabled'] ?? false,
                'moduleId' => $plugin?->id,
                'licenseKeyStatus' => $info['licenseKeyStatus'] ?? null,
                'licenseIssues' => $info['licenseIssues'] ?? [],
            ];
        }

        return [
            'plugins' => $list,
            'count' => count($list),
            'enabledCount' => count(array_filter($list, static fn(array $p): bool => $p['isEnabled'] === true)),
        ];
    }
}
