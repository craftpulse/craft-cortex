<?php

namespace craftpulse\herald;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\elements\User;
use craft\helpers\UrlHelper;
use craftpulse\auditkit\AuditKit;
use craftpulse\herald\controllers\SettingsController;
use craftpulse\herald\models\Settings;
use craftpulse\herald\plugin\PluginTrait;
use craftpulse\herald\plugin\Services as HeraldServices;
use craftpulse\herald\services\Allowlist;
use craftpulse\herald\services\Audit;
use craftpulse\herald\services\Invocations;
use craftpulse\herald\services\Oauth;
use craftpulse\herald\services\Prompts;
use craftpulse\herald\services\RateLimiter;
use craftpulse\herald\services\Resources;
use craftpulse\herald\services\Scopes;
use craftpulse\herald\services\Sessions;
use craftpulse\herald\services\Skills;
use craftpulse\herald\services\Tokens;
use craftpulse\herald\services\Tools;

/**
 * =========================================================================
 * Herald plugin entry point.
 *
 * Wires the MCP server's services, console controllers (auto-discovered
 * from `src/console/controllers/`), and the Gate 7 HTTP transport
 * (`herald/mcp` plus the OAuth + `.well-known` URL rules registered in
 * `init()`).
 *
 * Service accessors live on the `HeraldServices` trait
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
 * in project config at `plugins.herald.edition`; Craft owns the
 * storage. Herald does not run a license network call.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 *
 * @method static Herald getInstance()
 * @method Settings getSettings()
 */
class Herald extends BasePlugin
{
    use HeraldServices;
    use PluginTrait;

    // Constants
    // =========================================================================

    /**
     * Edition handle for the Free tier — read-only / dev surface, no
     * write tools, no PII, no admin-level command patterns. The default
     * value Craft assigns to `plugins.herald.edition` on a fresh install.
     *
     * @since 5.0.0
     */
    public const EDITION_FREE = 'free';

    /**
     * Edition handle for the Pro tier — adds the content-write tools,
     * the `users` PII surface, mode unlocks on the four Free workflow
     * tools, and streaming enablement on bulk-mutation paths. The
     * Plugin Store sets this handle on purchase; Herald does not run
     * a license network call.
     *
     * @since 5.0.0
     */
    public const EDITION_PRO = 'pro';

    /**
     * Permission handle gating the Activity tab in the Herald CP page.
     * Admins implicitly pass; non-admins with this permission see only
     * their own invocations (the `SettingsController::actionActivity*`
     * actions scope the query by `userId` when the caller is not an
     * admin). Registered under the Herald heading on the user-permissions
     * screen via `PluginTrait::_registerHeraldPermissions()`.
     *
     * @since 5.0.0
     */
    public const PERMISSION_VIEW_ACTIVITY = 'herald:view-activity';

    // Public Properties
    // =========================================================================

    /**
     * @inheritdoc
     */
    public string $schemaVersion = '1.3.0';

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
     * CP nav link to Herald settings disappears when
     * `allowAdminChanges = false`.
     */
    public bool $hasReadOnlyCpSettings = true;

    /**
     * @inheritdoc
     *
     * Herald owns a top-level CP section (`/herald`) so its operator
     * surfaces — Settings, Temporary grants, and the Pro Tokens /
     * Activity / Connection screens — live in the global sidebar with a
     * permission- and edition-gated subnav (`getCpNavItem()`), rather
     * than buried behind Settings → Plugins. The plugin Settings page
     * stays reachable from Settings → Plugins → Herald too
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
                'audit' => ['class' => Audit::class],
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
     * `plugins.herald.edition` and is stored by Craft itself — the
     * Plugin Store sets it on purchase. Herald does not maintain a
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
     * Parent-boots, registers the Audit Kit module, then delegates to
     * `PluginTrait::onPluginInit()` which wires every Herald event
     * listener and project-config handler. Splitting registration into
     * the trait keeps the entry-point class focused on the Plugin Store
     * contract (`config`, `editions`, settings) and the trait
     * composition.
     *
     * Audit Kit 1.1.0 ships as a library-shipped Yii module rather than
     * a Craft plugin, so Craft no longer boots it: nothing constructs
     * the module and the dispatch bus does not exist until a consumer
     * calls `AuditKit::register()`. The call is unconditional and
     * idempotent by design — every consuming plugin makes it, the first
     * one attaches the module and the rest find it already attached. It
     * must not be guarded by a `Craft::$app->getModule()` check of our
     * own, and it must not be deferred: `Audit::_bus()` resolves the bus
     * through `AuditKit::getInstance()`, so a missing or late
     * registration would leave Herald's governance chain silently
     * ungrown on a security product.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function init(): void
    {
        parent::init();

        AuditKit::register();

        $this->onPluginInit();
    }

    /**
     * @inheritdoc
     *
     * Redirects every settings entry-point — the CP nav link under
     * Settings → Plugins → Herald, the click on the plugin row in
     * Settings → Plugins, and anything else hitting Craft's built-in
     * settings response — to the Herald-owned tabbed page at
     * `settings/plugins/herald`. The redirect target route is
     * registered in `PluginTrait::_registerUrlRules()` under
     * `EVENT_REGISTER_CP_URL_RULES` and resolves to
     * `herald/settings/index`.
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
        return $response->redirect(UrlHelper::cpUrl('settings/plugins/herald'));
    }

    /**
     * @inheritdoc
     *
     * Builds the Herald CP section's subnav, permission- and
     * edition-gated so each operator only sees the screens they can
     * actually open. The gating here is presentation only — every
     * controller action behind these items re-checks its own
     * `requireAdmin` / `requirePermission` / `_requirePro()` posture, so a
     * hand-typed URL gets the same answer the hidden item implies. The
     * nav never widens access; it only hides what the user can't reach.
     *
     * Subnav map (in display order):
     *   - Settings          — `herald:manage-settings` permission, all editions.
     *   - Temporary grants   — `herald:manage-grants` permission, all
     *                          editions (the per-user runtime grant surface;
     *                          route handle stays `allowlist`).
     *   - Tokens            — admin + Pro.
     *   - Clients           — admin + Pro (OAuth client approval gate).
     *   - Activity          — `herald:view-activity` + Pro (admins pass
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

        // Settings is gated by the manage-settings permission (delegatable
        // to a non-admin group), matching the controller gate. Admins hold
        // the permission implicitly, so they still see it.
        if ($user->can(SettingsController::PERMISSION_MANAGE_SETTINGS)) {
            $subnav['settings'] = [
                'label' => Craft::t('herald', 'Settings'),
                'url' => 'herald/settings',
            ];
        }

        // Temporary grants is gated by its own manage-grants permission
        // (delegatable independently of settings), matching the controller
        // gate. Admins hold it implicitly.
        if ($user->can(SettingsController::PERMISSION_MANAGE_GRANTS)) {
            $subnav['grants'] = [
                'label' => Craft::t('herald', 'Temporary grants'),
                'url' => 'herald/allowlist',
            ];
        }

        if ($isPro && $user->admin) {
            $subnav['tokens'] = [
                'label' => Craft::t('herald', 'Tokens'),
                'url' => 'herald/tokens',
            ];
            $subnav['clients'] = [
                'label' => Craft::t('herald', 'Clients'),
                'url' => 'herald/clients',
            ];
        }

        if ($isPro && $user->can(self::PERMISSION_VIEW_ACTIVITY)) {
            $subnav['activity'] = [
                'label' => Craft::t('herald', 'Activity'),
                'url' => 'herald/activity',
            ];
        }

        if ($isPro && $user->admin) {
            $subnav['connection'] = [
                'label' => Craft::t('herald', 'Connection'),
                'url' => 'herald/connection',
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
