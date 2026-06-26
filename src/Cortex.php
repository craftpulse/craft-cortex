<?php

namespace craftpulse\cortex;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\elements\User;
use craft\helpers\UrlHelper;
use craftpulse\cortex\models\Settings;
use craftpulse\cortex\plugin\PluginTrait;
use craftpulse\cortex\plugin\Services as CortexServices;
use craftpulse\cortex\services\Allowlist;
use craftpulse\cortex\services\Invocations;
use craftpulse\cortex\services\Oauth;
use craftpulse\cortex\services\Prompts;
use craftpulse\cortex\services\RateLimiter;
use craftpulse\cortex\services\Resources;
use craftpulse\cortex\services\Scopes;
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

    /**
     * Permission handle gating the Activity tab in the Cortex CP page.
     * Admins implicitly pass; non-admins with this permission see only
     * their own invocations (the `SettingsController::actionActivity*`
     * actions scope the query by `userId` when the caller is not an
     * admin). Registered under the Cortex heading on the user-permissions
     * screen via `PluginTrait::_registerCortexPermissions()`.
     *
     * @since 5.0.0
     */
    public const PERMISSION_VIEW_ACTIVITY = 'cortex:viewActivity';

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
     *
     * Required as soon as `getSettingsResponse()` is overridden — the
     * base `Plugin::init()` only auto-flips this when the default
     * implementation is in use. Without the explicit declaration the
     * CP nav link to Cortex settings disappears when
     * `allowAdminChanges = false`. Reference:
     * `~/.claude-eng/skills/craftcms/references/cp.md` line 516.
     */
    public bool $hasReadOnlyCpSettings = true;

    /**
     * @inheritdoc
     *
     * Cortex owns a top-level CP section (`/cortex`) so its operator
     * surfaces — Settings, Temporary grants, and the Pro Tokens /
     * Activity / Connection screens — live in the global sidebar with a
     * permission- and edition-gated subnav (`getCpNavItem()`), rather
     * than buried behind Settings → Plugins. The plugin Settings page
     * stays reachable from Settings → Plugins → Cortex too
     * (`getSettingsResponse()` is unchanged).
     */
    public bool $hasCpSection = true;

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
                'scopes' => ['class' => Scopes::class],
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

    /**
     * @inheritdoc
     *
     * Redirects every settings entry-point — the CP nav link under
     * Settings → Plugins → Cortex, the click on the plugin row in
     * Settings → Plugins, and anything else hitting Craft's built-in
     * settings response — to the Cortex-owned tabbed page at
     * `settings/plugins/cortex`. The redirect target route is
     * registered in `PluginTrait::_registerUrlRules()` under
     * `EVENT_REGISTER_CP_URL_RULES` and resolves to
     * `cortex/settings/index`.
     *
     * Per Gate 9 locked decision 1 + `cp.md` §"Tabbed Settings
     * Pages" line 432 — `settingsHtml()` cannot host tabs because
     * Craft's `_settings.twig` wrapper does not propagate the `tabs`
     * variable up to `_layouts/cp`. Overriding `getSettingsResponse()`
     * is the only path that keeps the tabbed lineage intact.
     *
     * @throws \yii\base\InvalidConfigException from `Craft::$app->getResponse()`.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function getSettingsResponse(): mixed
    {
        /** @var \craft\web\Response $response */
        $response = Craft::$app->getResponse();
        return $response->redirect(UrlHelper::cpUrl('settings/plugins/cortex'));
    }

    /**
     * @inheritdoc
     *
     * Builds the Cortex CP section's subnav, permission- and
     * edition-gated so each operator only sees the screens they can
     * actually open. The gating here is presentation only — every
     * controller action behind these items re-checks its own
     * `requireAdmin` / `requirePermission` / `_requirePro()` posture, so a
     * hand-typed URL gets the same answer the hidden item implies. The
     * nav never widens access; it only hides what the user can't reach.
     *
     * Subnav map (in display order):
     *   - Settings          — admin (`requireAdmin(false)`), all editions.
     *   - Temporary grants   — admin, all editions (the runtime allowlist
     *                          override surface; route handle stays
     *                          `allowlist`).
     *   - Tokens            — admin + Pro.
     *   - Clients           — admin + Pro (OAuth client approval gate).
     *   - Activity          — `cortex:viewActivity` + Pro (admins pass
     *                          implicitly via `can()`).
     *   - Connection        — admin + Pro.
     *
     * Free installs therefore see only Settings + Temporary grants,
     * matching the Pro-tab gate map the controller enforces.
     *
     * **Performance contract:** this method runs on every CP page render.
     * It only reads the current identity, the edition handle, and
     * permission checks — all sub-millisecond, no queries, no cache
     * needed. Preserve that ceiling if a badge count is ever added.
     *
     * @throws \yii\base\InvalidConfigException from `Craft::$app->getUser()`.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function getCpNavItem(): ?array
    {
        $navItem = parent::getCpNavItem();

        $user = Craft::$app->getUser()->getIdentity();
        if (!$user instanceof User) {
            return null;
        }

        $isPro = $this->is(self::EDITION_PRO, '>=');
        $subnav = [];

        if ($user->admin) {
            $subnav['settings'] = [
                'label' => Craft::t('cortex', 'Settings'),
                'url' => 'cortex/settings',
            ];
            $subnav['grants'] = [
                'label' => Craft::t('cortex', 'Temporary grants'),
                'url' => 'cortex/allowlist',
            ];
        }

        if ($isPro && $user->admin) {
            $subnav['tokens'] = [
                'label' => Craft::t('cortex', 'Tokens'),
                'url' => 'cortex/tokens',
            ];
            $subnav['clients'] = [
                'label' => Craft::t('cortex', 'Clients'),
                'url' => 'cortex/clients',
            ];
        }

        if ($isPro && $user->can(self::PERMISSION_VIEW_ACTIVITY)) {
            $subnav['activity'] = [
                'label' => Craft::t('cortex', 'Activity'),
                'url' => 'cortex/activity',
            ];
        }

        if ($isPro && $user->admin) {
            $subnav['connection'] = [
                'label' => Craft::t('cortex', 'Connection'),
                'url' => 'cortex/connection',
            ];
        }

        if ($subnav === []) {
            return null;
        }

        $navItem['subnav'] = $subnav;
        return $navItem;
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
}
