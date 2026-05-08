<?php

namespace craftpulse\cortex\tools\system;

use Craft;
use craft\base\PluginInterface;
use craftpulse\cortex\attributes\IsIdempotent;
use craftpulse\cortex\attributes\IsReadOnly;
use craftpulse\cortex\tools\AbstractTool;

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
 * @since  0.1.0
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
     * @since  0.1.0
     */
    public static function getName(): string
    {
        return 'plugins';
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  0.1.0
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
     * @author Craftpulse
     * @since  0.1.0
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
