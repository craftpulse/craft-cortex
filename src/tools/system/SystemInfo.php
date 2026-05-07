<?php

namespace craftpulse\cortex\tools\system;

use Craft;
use craft\enums\CmsEdition;
use craftpulse\cortex\attributes\IsIdempotent;
use craftpulse\cortex\attributes\IsReadOnly;
use craftpulse\cortex\tools\AbstractTool;

/**
 * =========================================================================
 * `system_info` tool — point-in-time snapshot of the Craft install.
 *
 * Returns the metadata an AI agent needs to reason about the runtime
 * environment without grepping vendor/ or guessing — Craft version,
 * edition (Solo/Team/Pro/Enterprise), schema version, environment,
 * devMode, PHP version, DB driver/version, site count, license state.
 *
 * No parameters. The information is small and read together.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  0.1.0
 */
#[IsReadOnly]
#[IsIdempotent]
class SystemInfo extends AbstractTool
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
        return 'system_info';
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public static function getDescription(): string
    {
        return 'Snapshot of the running Craft install: version, edition, schema version, ' .
            'environment, devMode, PHP version, database driver/version, site count, ' .
            'maintenance flag, and license state. No parameters.';
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public function execute(array $arguments): array
    {
        $info = Craft::$app->getInfo();
        $db = Craft::$app->getDb();

        return [
            'craft' => [
                'version' => Craft::$app->getVersion(),
                'edition' => $this->_editionName(Craft::$app->edition),
                'editionId' => Craft::$app->edition->value,
                'schemaVersion' => $info->schemaVersion,
                'fieldVersion' => $info->fieldVersion,
                'maintenance' => $info->maintenance,
                'devMode' => Craft::$app->getConfig()->getGeneral()->devMode,
                'environment' => Craft::$app->env,
                'isInstalled' => Craft::$app->getIsInstalled(),
                'systemName' => Craft::$app->getSystemName(),
                'systemUid' => $info->uid,
            ],
            'php' => [
                'version' => PHP_VERSION,
                'sapi' => PHP_SAPI,
                'memoryLimit' => ini_get('memory_limit'),
                'maxExecutionTime' => ini_get('max_execution_time'),
                'timezone' => date_default_timezone_get(),
            ],
            'db' => [
                'driver' => $db->getDriverName(),
                'serverVersion' => $db->getServerVersion(),
                'isMysql' => $db->getIsMysql(),
                'isPgsql' => $db->getIsPgsql(),
            ],
            'sites' => [
                'count' => count(Craft::$app->getSites()->getAllSites()),
                'primarySiteHandle' => Craft::$app->getSites()->getPrimarySite()->handle,
            ],
            'license' => [
                'status' => Craft::$app->getCache()?->get('licenseKeyStatus') ?: 'unknown',
            ],
        ];
    }

    // Private Methods
    // =========================================================================

    /**
     * @author Craftpulse
     * @since  0.1.0
     */
    private function _editionName(CmsEdition $edition): string
    {
        return $edition->name;
    }
}
