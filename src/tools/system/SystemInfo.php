<?php

namespace craftpulse\cortex\tools\system;

use Craft;
use craft\enums\CmsEdition;
use craftpulse\cortex\attributes\IsIdempotent;
use craftpulse\cortex\attributes\IsReadOnly;
use craftpulse\cortex\tools\AbstractTool;
use craftpulse\cortex\tools\support\Schema;

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
 * @since  5.0.0
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
     * @since  5.0.0
     */
    public static function getName(): string
    {
        return 'system_info';
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  5.0.0
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
     * Output is a stable nested object — every key is always emitted with
     * the same type, so we advertise an outputSchema so spec-aware clients
     * read the response from `structuredContent` and validate it. Older
     * clients still read the JSON-serialised text block.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public static function outputSchema(): array
    {
        return Schema::object([
            'craft' => Schema::object([
                'version' => Schema::string()->required(),
                'edition' => Schema::string()->required()->description('Solo / Team / Pro / Enterprise.'),
                'editionId' => Schema::integer()->required(),
                'schemaVersion' => Schema::string()->required(),
                'fieldVersion' => Schema::string()->required(),
                'maintenance' => Schema::boolean()->required(),
                'devMode' => Schema::boolean()->required(),
                'environment' => Schema::string()->required(),
                'isInstalled' => Schema::boolean()->required(),
                'systemName' => Schema::string()->required(),
                'systemUid' => Schema::string()->required(),
            ])->required(),
            'php' => Schema::object([
                'version' => Schema::string()->required(),
                'sapi' => Schema::string()->required(),
                'memoryLimit' => Schema::string()->required(),
                'maxExecutionTime' => Schema::string()->required(),
                'timezone' => Schema::string()->required(),
            ])->required(),
            'db' => Schema::object([
                'driver' => Schema::string()->required(),
                'serverVersion' => Schema::string()->required(),
                'isMysql' => Schema::boolean()->required(),
                'isPgsql' => Schema::boolean()->required(),
            ])->required(),
            'sites' => Schema::object([
                'count' => Schema::integer()->required(),
                'primarySiteHandle' => Schema::string()->required(),
            ])->required(),
            'license' => Schema::object([
                'status' => Schema::string()->required(),
            ])->required(),
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
     * @since  5.0.0
     */
    private function _editionName(CmsEdition $edition): string
    {
        return $edition->name;
    }
}
