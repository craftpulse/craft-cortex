<?php

namespace craftpulse\cortex;

use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\services\Gc;
use craft\web\UrlManager;
use craftpulse\cortex\events\LogCallEvent;
use craftpulse\cortex\generator\Tool as ToolGenerator;
use craftpulse\cortex\models\Settings;
use craftpulse\cortex\services\Allowlist;
use craftpulse\cortex\services\Invocations;
use craftpulse\cortex\services\Oauth;
use craftpulse\cortex\services\Prompts;
use craftpulse\cortex\services\RateLimiter;
use craftpulse\cortex\services\Resources;
use craftpulse\cortex\services\Sessions;
use craftpulse\cortex\services\Tokens;
use craftpulse\cortex\services\Tools;
use craftpulse\cortex\tools\support\InvocationLogger;
use Throwable;
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
 * @since  5.0.0
 *
 * @method static Plugin getInstance()
 * @method Settings getSettings()
 * @property-read Allowlist $allowlist
 * @property-read Invocations $invocations
 * @property-read Oauth $oauth
 * @property-read Prompts $prompts
 * @property-read RateLimiter $rateLimiter
 * @property-read Resources $resources
 * @property-read Sessions $sessions
 * @property-read Tokens $tokens
 * @property-read Tools $tools
 */
class Plugin extends BasePlugin
{
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
                'tokens' => ['class' => Tokens::class],
                'tools' => ['class' => Tools::class],
            ],
        ];
    }

    /**
     * @inheritdoc
     *
     * Returns the edition handles in ascending order — Free first, Pro
     * last. Order matters: Craft's `Plugin::is($edition, '>=')` walks
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
     * Registers the cortex-tool generator with Craft's `make` command if
     * the `craftcms/generator` package is installed (always present in
     * dev / playground installs; not bundled with the production
     * `craftcms/cms` runtime). Class-exists guard keeps cortex bootable
     * on installs that strip dev dependencies.
     *
     * @author Craftpulse
     * @since  5.0.0
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

        // Prune expired runtime-override rows + (when audit retention
        // is configured) old `cortex_invocations` rows during Craft's
        // gc sweep. Both deletes are single indexed deleteAll calls,
        // faster than the overhead of pushing a queue job — and
        // Craft's gc already runs heavier cleanups inline in the same
        // pass. Audit retention defaults to forever
        // (`Settings::$auditRetentionDays = null`); when null the
        // prune call is a no-op.
        Event::on(
            Gc::class,
            Gc::EVENT_RUN,
            static function(): void {
                $plugin = Plugin::getInstance();
                $plugin->allowlist->pruneExpired();
                $plugin->invocations->prune();
            },
        );

        // Wire the Gate 7.5 audit-log writer to the InvocationLogger's
        // structured-entry event. The listener is wrapped in a
        // try/catch so a thrown exception inside the audit-write path
        // cannot break the dispatcher — defense in depth on top of
        // `Invocations::record()`'s internal try/catch. The KV file
        // log line is the secondary audit trail when the DB write
        // fails.
        Event::on(
            InvocationLogger::class,
            InvocationLogger::EVENT_LOG_CALL,
            static function(LogCallEvent $event): void {
                try {
                    Plugin::getInstance()->invocations->record($event->entry);
                } catch (Throwable $e) {
                    \Craft::error(
                        sprintf(
                            'Audit-log listener raised %s: %s',
                            $e::class,
                            $e->getMessage(),
                        ),
                        Invocations::LOG_CATEGORY,
                    );
                }
            },
        );

        // Register the HTTP transport endpoint at /cortex/mcp. The route
        // sits under the site URL rules (front-end-style endpoint, no
        // cpTrigger) because MCP clients hit a stable public URL that
        // doesn't move with `cpTrigger` reconfiguration.
        //
        // POST is the JSON-RPC entry point; GET is reserved for SSE
        // upgrade (lands in sub-gate 7.7); DELETE terminates the
        // session. The controller refuses every request when
        // `Settings::$httpEnabled` is false, so registering the route
        // unconditionally is safe — feature gating happens at the
        // controller layer, not at the route layer.
        //
        // OAuth endpoints sit at /oauth/* (not under /cortex/) for
        // client compatibility — most MCP clients expect bare
        // /oauth/authorize, /oauth/token, etc. The `.well-known/*`
        // discovery endpoints land at the site root per RFC 8414 §3
        // and RFC 9728 §3 — both RFCs explicitly require the
        // well-known paths to be at the root of the issuer URL.
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            static function(RegisterUrlRulesEvent $event): void {
                $event->rules['POST cortex/mcp'] = 'cortex/mcp/index';
                $event->rules['GET cortex/mcp'] = 'cortex/mcp/index';
                $event->rules['DELETE cortex/mcp'] = 'cortex/mcp/index';

                $event->rules['GET oauth/authorize'] = 'cortex/oauth/authorize';
                $event->rules['POST oauth/authorize'] = 'cortex/oauth/authorize';
                $event->rules['POST oauth/token'] = 'cortex/oauth/token';
                $event->rules['POST oauth/register'] = 'cortex/oauth/register';
                $event->rules['POST oauth/revoke'] = 'cortex/oauth/revoke';

                $event->rules['GET .well-known/oauth-authorization-server'] = 'cortex/well-known/authorization-server';
                $event->rules['GET .well-known/oauth-protected-resource'] = 'cortex/well-known/protected-resource';
            },
        );
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
