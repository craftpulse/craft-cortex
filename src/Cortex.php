<?php

namespace craftpulse\cortex;

use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craftpulse\cortex\models\Settings;
use craftpulse\cortex\plugin\PluginTrait;
use craftpulse\cortex\plugin\Services as CortexServices;
use craftpulse\cortex\services\Allowlist;
use craftpulse\cortex\services\Invocations;
use craftpulse\cortex\services\Oauth;
use craftpulse\cortex\services\Prompts;
use craftpulse\cortex\services\RateLimiter;
use craftpulse\cortex\services\Resources;
use craftpulse\cortex\services\Sessions;
use craftpulse\cortex\services\Skills;
use craftpulse\cortex\services\Tokens;
use craftpulse\cortex\services\Tools;

/**
 * =========================================================================
 * Cortex plugin entry point.
 *
 * Wires the MCP server's services, console controllers (auto-discovered
 * from `src/console/controllers/`), and the Gate 7 HTTP transport
 * (`cortex/mcp` plus the OAuth + `.well-known` URL rules registered in
 * `init()`).
 *
 * Service accessors live on the `CortexServices` trait
 * (`src/plugin/Services.php`) — one typed `getXxx(): Xxx` per registered
 * component. The trait is the canonical type contract; `config()`
 * declares the Yii component map that makes `$this->get('xxx')` resolve.
 * Adding a service means editing both, not the class docblock.
 * Property-style access (`$plugin->tools`) continues to work via Yii's
 * `__get()` walking the trait's getter.
 *
 * Event listeners and project-config handlers live on `PluginTrait`
 * (`src/plugin/PluginTrait.php`). `init()` delegates to
 * `onPluginInit()` so each listener is a discrete `_register*()`
 * method instead of an inline closure block.
 *
 * Edition handles (`EDITION_FREE`, `EDITION_PRO`) are declared via
 * `editions()` per the Plugin Store contract. The active edition lives
 * in project config at `plugins.cortex.edition`; Craft owns the
 * storage. Cortex does not run a license network call.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 *
 * @method static Cortex getInstance()
 * @method Settings getSettings()
 */
class Cortex extends BasePlugin
{
    use CortexServices;
    use PluginTrait;

    // Constants
    // =========================================================================

    /**
     * Edition handle for the Free tier — read-only / dev surface, no
     * write tools, no PII, no admin-level command patterns. The default
     * value Craft assigns to `plugins.cortex.edition` on a fresh install.
     *
     * @since 5.0.0
     */
    public const EDITION_FREE = 'free';

    /**
     * Edition handle for the Pro tier — adds the content-write tools,
     * the `users` PII surface, mode unlocks on the four Free workflow
     * tools, and streaming enablement on bulk-mutation paths. The
     * Plugin Store sets this handle on purchase; Cortex does not run
     * a license network call.
     *
     * @since 5.0.0
     */
    public const EDITION_PRO = 'pro';

    // Public Properties
    // =========================================================================

    /**
     * @inheritdoc
     */
    public string $schemaVersion = '1.0.0';

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
     * @since  5.0.0
     */
    public static function config(): array
    {
        return [
            'components' => [
                'allowlist' => ['class' => Allowlist::class],
                'invocations' => ['class' => Invocations::class],
                'oauth' => ['class' => Oauth::class],
                'prompts' => ['class' => Prompts::class],
                'rateLimiter' => ['class' => RateLimiter::class],
                'resources' => ['class' => Resources::class],
                'sessions' => ['class' => Sessions::class],
                'skills' => ['class' => Skills::class],
                'tokens' => ['class' => Tokens::class],
                'tools' => ['class' => Tools::class],
            ],
        ];
    }

    /**
     * @inheritdoc
     *
     * Returns the edition handles in ascending order — Free first, Pro
     * last. Order matters: Craft's `BasePlugin::is($edition, '>=')` walks
     * the array by index, so a Pro install satisfies `is(EDITION_FREE,
     * '>=')` but a Free install does not satisfy `is(EDITION_PRO, '>=')`.
     *
     * The active edition handle lives in project config at
     * `plugins.cortex.edition` and is stored by Craft itself — the
     * Plugin Store sets it on purchase. Cortex does not maintain a
     * separate license table and does not call out to a license
     * server.
     *
     * @return string[]
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public static function editions(): array
    {
        return [self::EDITION_FREE, self::EDITION_PRO];
    }

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * Parent-boots, then delegates to `PluginTrait::onPluginInit()`
     * which wires every Cortex event listener and project-config
     * handler. Splitting registration into the trait keeps the
     * entry-point class focused on the Plugin Store contract
     * (`config`, `editions`, settings) and the trait composition.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function init(): void
    {
        parent::init();

        $this->onPluginInit();
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  5.0.0
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
