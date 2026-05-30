<?php

namespace craftpulse\cortex\plugin;

use Craft;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\Elements;
use craft\services\Gc;
use craft\services\UserPermissions;
use craft\web\UrlManager;
use craftpulse\cortex\Cortex;
use craftpulse\cortex\elements\Skill;
use craftpulse\cortex\events\LogCallEvent;
use craftpulse\cortex\generator\Tool as ToolGenerator;
use craftpulse\cortex\services\Invocations;
use craftpulse\cortex\services\Skills;
use craftpulse\cortex\tools\support\InvocationLogger;
use Throwable;
use yii\base\Event;

/**
 * =========================================================================
 * Cortex plugin-boot trait.
 *
 * Centralises every event-listener and project-config handler the
 * plugin wires at `init()` time. Companion to the `Services` trait —
 * `Services` exposes typed accessors for the Yii component map,
 * `PluginTrait` wires the static `Event::on()` listeners and the PC
 * field-layout handlers.
 *
 * `Cortex::init()` parent-boots and then calls `onPluginInit()` once;
 * that method dispatches to the private `_register*()` methods below.
 * Listeners are split per concern so the init flow is greppable and
 * each registration is testable in isolation if a future regression
 * forces it.
 *
 * Per `.claude/rules/architecture.md`, event registrations live here
 * rather than inline in the plugin class — keeping `Cortex.php` to
 * the Plugin Store contract (`config`, `editions`, settings) and the
 * trait composition.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */
trait PluginTrait
{
    // Public Methods
    // =========================================================================

    /**
     * Wires every event listener and project-config handler the
     * plugin needs at boot. Idempotent at the call site — Cortex
     * only invokes this from its own `init()` after `parent::init()`.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function onPluginInit(): void
    {
        $this->_registerGenerator();
        $this->_registerGcListener();
        $this->_registerAuditLogListener();
        $this->_registerSkillElementType();
        $this->_registerCortexPermissions();
        $this->_registerSkillProjectConfigHandlers();
        $this->_registerUrlRules();
    }

    // Private Methods
    // =========================================================================

    /**
     * Registers the `cortex-tool` generator with Craft's `make`
     * command when the generator package is available (dev /
     * playground installs only — production strips the dev
     * dependency). Class-exists guard keeps the plugin bootable
     * on installs without `craftcms/generator`.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _registerGenerator(): void
    {
        if (!class_exists(\craft\generator\Command::class)) {
            return;
        }

        Event::on(
            \craft\generator\Command::class,
            \craft\generator\Command::EVENT_REGISTER_GENERATORS,
            static function(RegisterComponentTypesEvent $event): void {
                $event->types[] = ToolGenerator::class;
            },
        );
    }

    /**
     * Prunes expired runtime-override rows and (when audit retention
     * is configured) old `cortex_invocations` rows during Craft's
     * gc sweep. Both deletes are single indexed `deleteAll` calls,
     * cheaper than the overhead of a queue job. Audit retention
     * defaults to forever (`Settings::$auditRetentionDays = null`);
     * when null the prune call is a no-op.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _registerGcListener(): void
    {
        Event::on(
            Gc::class,
            Gc::EVENT_RUN,
            static function(): void {
                $plugin = Cortex::getInstance();
                $plugin->allowlist->pruneExpired();
                $plugin->invocations->prune();
            },
        );
    }

    /**
     * Wires the Gate 7.5 audit-log writer to the InvocationLogger's
     * structured-entry event. Wrapped in a try/catch so a thrown
     * exception inside the audit-write path cannot break the
     * dispatcher — defense in depth on top of `Invocations::record()`'s
     * internal try/catch. The KV file log line is the secondary
     * audit trail when the DB write fails.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _registerAuditLogListener(): void
    {
        Event::on(
            InvocationLogger::class,
            InvocationLogger::EVENT_LOG_CALL,
            static function(LogCallEvent $event): void {
                try {
                    Cortex::getInstance()->invocations->record($event->entry);
                } catch (Throwable $e) {
                    Craft::error(
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
    }

    /**
     * Registers the Cortex Skill element type so the Elements
     * service includes it in `getAllElementTypes()` /
     * `ElementTypes` tool discovery and so Craft's
     * element-condition / GraphQL surfaces pick it up.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _registerSkillElementType(): void
    {
        Event::on(
            Elements::class,
            Elements::EVENT_REGISTER_ELEMENT_TYPES,
            static function(RegisterComponentTypesEvent $event): void {
                $event->types[] = Skill::class;
            },
        );
    }

    /**
     * Registers every Cortex permission under a shared Cortex
     * heading on the user-permissions screen.
     *
     *   - `Skill::PERMISSION_MANAGE` (`manageCortexSkills`) — global
     *     (no per-instance ACL); the element's `canSave / canDelete /
     *     canView / canDuplicate` overrides consult it directly.
     *   - `Cortex::PERMISSION_VIEW_ACTIVITY` (`cortex:viewActivity`) —
     *     gates the Activity tab (Gate 9.3); the controller scopes
     *     queries to the caller's own rows when the user is non-admin.
     *
     * Shape verified against
     * `vendor/craftcms/cms/src/services/UserPermissions.php:85-96`.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _registerCortexPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            static function(RegisterUserPermissionsEvent $event): void {
                $event->permissions[] = [
                    'heading' => 'Cortex',
                    'permissions' => [
                        Skill::PERMISSION_MANAGE => [
                            'label' => Craft::t('cortex', 'Manage Cortex skills'),
                            'info' => Craft::t(
                                'cortex',
                                'Allows creating, updating, and deleting Cortex skill elements through the MCP server.',
                            ),
                        ],
                        Cortex::PERMISSION_VIEW_ACTIVITY => [
                            'label' => Craft::t('cortex', 'View Cortex activity log'),
                            'info' => Craft::t(
                                'cortex',
                                'Allows viewing the Activity tab in Cortex CP. Non-admins only see their own invocations; admins see every row.',
                            ),
                        ],
                    ],
                ];
            },
        );
    }

    /**
     * Wires the PC field-layout change handlers for the single
     * `plugins.cortex.skillFieldLayout` path. Mirrors Craft's own
     * `ApplicationTrait::_registerConfigListeners()` shape for
     * `PATH_ADDRESS_FIELD_LAYOUTS`. The handler runs on add /
     * update / remove so the field layout stays in sync between
     * PC and the live `Fields` service across environments.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _registerSkillProjectConfigHandlers(): void
    {
        $skillsService = $this->getSkills();
        Craft::$app->getProjectConfig()
            ->onAdd(Skills::CONFIG_FIELDLAYOUT_PATH, [$skillsService, 'handleChangedFieldLayout'])
            ->onUpdate(Skills::CONFIG_FIELDLAYOUT_PATH, [$skillsService, 'handleChangedFieldLayout'])
            ->onRemove(Skills::CONFIG_FIELDLAYOUT_PATH, [$skillsService, 'handleChangedFieldLayout']);
    }

    /**
     * Registers the HTTP transport endpoint at `/cortex/mcp` and the
     * CP-side routes for the tabbed Cortex settings screen.
     *
     * Two events fire — `EVENT_REGISTER_SITE_URL_RULES` for the
     * front-end `cortex/mcp` + OAuth + `.well-known/*` routes, and
     * `EVENT_REGISTER_CP_URL_RULES` for the CP-side tabs introduced
     * in Gate 9. The events fire at different stages of the URL
     * manager bootstrap; mixing them in one handler silently drops
     * half the routes.
     *
     * **Site rules** (POST/GET/DELETE `cortex/mcp`, `oauth/*`,
     * `.well-known/*`): MCP clients hit a stable public URL that
     * doesn't move with cpTrigger reconfiguration. POST is the
     * JSON-RPC entry point; GET is reserved for SSE upgrade; DELETE
     * terminates the session. All three controllers — `McpController`,
     * `OauthController`, and `WellKnownController` — refuse every
     * request with 503 when `Settings::$httpEnabled` is false (the MCP
     * controller in its own `beforeAction()`, the OAuth + well-known
     * controllers via the shared `AbstractOauthController` base), so
     * registering these routes unconditionally is safe — feature
     * gating happens at the controller layer, not at the route layer.
     * OAuth endpoints sit
     * at `/oauth/*` (not under `/cortex/`) for client compatibility;
     * the `.well-known/*` discovery endpoints land at the site root
     * per RFC 8414 §3 and RFC 9728 §3 — both RFCs explicitly require
     * the well-known paths to be at the root of the issuer URL.
     *
     * **CP rules** (Gate 9.1): `settings/plugins/cortex` is the
     * redirect target from `Cortex::getSettingsResponse()` and the
     * Settings → Plugins → Cortex nav link. The `cortex/{tab}` URLs
     * are the per-tab routes (locked decision 2 — per-tab routes
     * over anchor-based tabs for bookmarking, deep links, and
     * independent badge counts). Table-data + mutation routes
     * (token issue/revoke, activity rows) land in 9.2 / 9.3 alongside
     * their respective controller actions.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _registerUrlRules(): void
    {
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

        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            static function(RegisterUrlRulesEvent $event): void {
                // Prepend so the literal `settings/plugins/cortex` pattern beats
                // Craft's wildcard `settings/plugins/<handle>` rule from
                // `vendor/craftcms/cms/src/config/cproutes/common.php:66`. Yii
                // matches rules in array order — the wildcard is loaded first
                // and would otherwise route to `plugins/edit-plugin-settings`,
                // which calls `Cortex::getSettingsResponse()` and redirects
                // back to the same URL (infinite loop).
                $event->rules = array_merge([
                    'settings/plugins/cortex' => 'cortex/settings/index',
                    'cortex/tokens' => 'cortex/settings/tokens',
                    'cortex/allowlist' => 'cortex/settings/allowlist',
                    'cortex/allowlist/table-data' => 'cortex/settings/allowlist-table-data',
                    'cortex/allowlist/override-slideout' => 'cortex/settings/allowlist-override-slideout',
                    'cortex/activity' => 'cortex/settings/activity',
                    'cortex/connection' => 'cortex/settings/connection',
                ], $event->rules);
            },
        );
    }
}
