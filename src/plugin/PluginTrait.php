<?php

namespace craftpulse\herald\plugin;

use Craft;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\Elements;
use craft\services\Gc;
use craft\services\UserPermissions;
use craft\web\UrlManager;
use craftpulse\auditkit\events\RegisterAuditEventsEvent;
use craftpulse\auditkit\services\EventTypes;
use craftpulse\herald\controllers\SettingsController;
use craftpulse\herald\elements\Skill;
use craftpulse\herald\events\LogCallEvent;
use craftpulse\herald\generator\Tool as ToolGenerator;
use craftpulse\herald\Herald;
use craftpulse\herald\services\Invocations;
use craftpulse\herald\services\Skills;
use craftpulse\herald\tools\dev\ClearCaches;
use craftpulse\herald\tools\dev\CraftCommand;
use craftpulse\herald\tools\support\InvocationLogger;
use Throwable;
use yii\base\Event;

/**
 * =========================================================================
 * Herald plugin-boot trait.
 *
 * Centralises every event-listener and project-config handler the
 * plugin wires at `init()` time. Companion to the `Services` trait —
 * `Services` exposes typed accessors for the Yii component map,
 * `PluginTrait` wires the static `Event::on()` listeners and the PC
 * field-layout handlers.
 *
 * `Herald::init()` parent-boots and then calls `onPluginInit()` once;
 * that method dispatches to the private `_register*()` methods below.
 * Listeners are split per concern so the init flow is greppable and
 * each registration is testable in isolation if a future regression
 * forces it.
 *
 * Event registrations live here rather than inline in the plugin
 * class, keeping `Herald.php` to the Plugin Store contract
 * (`config`, `editions`, settings) and the trait composition.
 * =========================================================================
 *
 * @author CraftPulse
 * @since  5.0.0
 */
trait PluginTrait
{
    // Public Methods
    // =========================================================================

    /**
     * Wires every event listener and project-config handler the
     * plugin needs at boot. Idempotent at the call site — Herald
     * only invokes this from its own `init()` after `parent::init()`.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function onPluginInit(): void
    {
        $this->_registerGenerator();
        $this->_registerGcListener();
        $this->_registerAuditLogListener();
        $this->_registerAuditKitEmission();
        $this->_registerSkillElementType();
        $this->_registerHeraldPermissions();
        $this->_registerSkillProjectConfigHandlers();
        $this->_registerUrlRules();
    }

    // Private Methods
    // =========================================================================

    /**
     * Registers the `herald-tool` generator with Craft's `make`
     * command when the generator package is available (dev /
     * playground installs only — production strips the dev
     * dependency). Class-exists guard keeps the plugin bootable
     * on installs without `craftcms/generator`.
     *
     * @author CraftPulse
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
     * Prunes expired runtime-override rows, old `herald_invocations`
     * rows (when audit retention is configured), and dead OAuth codes /
     * tokens during Craft's gc sweep. Every delete is a single indexed
     * `deleteAll`, cheaper than the overhead of a queue job. Audit
     * retention defaults to forever (`Settings::$auditRetentionDays =
     * null`); when null that prune call is a no-op. The OAuth prune
     * only drops already-expired authorization codes and expired /
     * revoked tokens — fail-closed-safe per `Oauth::pruneExpired()`,
     * and never severs an active refresh-rotation chain.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _registerGcListener(): void
    {
        Event::on(
            Gc::class,
            Gc::EVENT_RUN,
            static function(): void {
                $plugin = Herald::getInstance();
                $plugin->allowlist->pruneExpired();
                $plugin->invocations->prune();
                $plugin->oauth->pruneExpired();
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
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _registerAuditLogListener(): void
    {
        Event::on(
            InvocationLogger::class,
            InvocationLogger::EVENT_LOG_CALL,
            static function(LogCallEvent $event): void {
                try {
                    Herald::getInstance()->invocations->record($event->entry);
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
     * Wires Herald's Audit Kit emission — additive alongside the
     * `herald_invocations` DB writer and the KV file log, never a
     * replacement for either.
     *
     * Two listeners:
     *
     *   - `EventTypes::EVENT_REGISTER_AUDIT_EVENTS` — contributes
     *     Herald's `AuditEventType` definitions (fail-closed detail
     *     allowlists) to the kit's runtime registry so a recorder knows
     *     which detail keys each event may persist.
     *   - `InvocationLogger::EVENT_LOG_CALL` — a second subscriber
     *     alongside `_registerAuditLogListener()`, emitting one
     *     `herald.tool.write_invoked` per WRITE-tool call across both
     *     transports (so stdio writes reach a recorder too, closing the
     *     stdio-not-in-DB gap for writes).
     *
     * Both handlers delegate to the `Audit` service; a missing / disabled
     * Audit Kit resolves to a null bus and every emission is a silent
     * no-op, so registering the listeners unconditionally is safe.
     *
     * The OAuth / bearer-token lifecycle events are emitted from the
     * `Oauth` and `Tokens` service methods directly, not wired here.
     *
     * @author CraftPulse
     * @since  5.1.0
     */
    private function _registerAuditKitEmission(): void
    {
        Event::on(
            EventTypes::class,
            EventTypes::EVENT_REGISTER_AUDIT_EVENTS,
            static function(RegisterAuditEventsEvent $event): void {
                Herald::getInstance()->audit->registerEventTypes($event);
            },
        );

        Event::on(
            InvocationLogger::class,
            InvocationLogger::EVENT_LOG_CALL,
            static function(LogCallEvent $event): void {
                Herald::getInstance()->audit->handleToolInvocation($event);
            },
        );
    }

    /**
     * Registers the Herald Skill element type so the Elements
     * service includes it in `getAllElementTypes()` /
     * `ElementTypes` tool discovery and so Craft's
     * element-condition / GraphQL surfaces pick it up.
     *
     * @author CraftPulse
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
     * Registers every Herald permission under a shared Herald
     * heading on the user-permissions screen.
     *
     *   - `SettingsController::PERMISSION_MANAGE_SETTINGS`
     *     (`herald:manage-settings`) — gates the Settings screen
     *     (`actionIndex` / `actionSave`) per the estate settings-permission
     *     doctrine; `allowAdminChanges` still gates whether a save writes.
     *   - `SettingsController::PERMISSION_MANAGE_GRANTS`
     *     (`herald:manage-grants`) — gates the Temporary grants screen and
     *     its issue / revoke actions. Its own permission because issuing a
     *     grant widens the `craft_command` allowlist for a user.
     *   - `Skill::PERMISSION_MANAGE` (`herald:manage-skills`) — global
     *     (no per-instance ACL); the element's `canSave / canDelete /
     *     canView / canDuplicate` overrides consult it directly.
     *   - `Herald::PERMISSION_VIEW_ACTIVITY` (`herald:view-activity`) —
     *     gates the Activity tab (Gate 9.3); the controller scopes
     *     queries to the caller's own rows when the user is non-admin.
     *   - `CraftCommand::PERMISSION_RUN_COMMANDS` (`herald:run-commands`)
     *     — gates the `craft_command` tool over the HTTP transport, both
     *     its `tools/list` visibility (`filterFor()`) and its dispatch
     *     (`execute()`). Separate from `manage-grants`, which controls
     *     which routes are allowlisted for a user rather than whether
     *     that user may dispatch at all. The `resave` tool reuses it:
     *     `resave` is the `resave/*` console route behind a structured
     *     argument shape, and `resave/*` ships on the content-level
     *     allowlist, so anyone holding this can already do that work
     *     through `craft_command`.
     *   - `ClearCaches::PERMISSION_CLEAR_CACHES` (`herald:clear-caches`)
     *     — gates the `clear_caches` tool. Its own permission rather
     *     than a reuse of `run-commands`, because `clear-caches/*` is
     *     absent from the default allowlist: the two authorities are
     *     genuinely disjoint, and requiring the broader one to flush a
     *     cache after a deploy would push operators into over-granting.
     *     Flat, as Craft's permissions are — neither implies the other.
     *
     * Shape verified against
     * `vendor/craftcms/cms/src/services/UserPermissions.php:85-96`.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _registerHeraldPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            static function(RegisterUserPermissionsEvent $event): void {
                $event->permissions[] = [
                    'heading' => 'Herald',
                    'permissions' => [
                        SettingsController::PERMISSION_MANAGE_SETTINGS => [
                            'label' => Craft::t('herald', 'Manage Herald settings'),
                            'info' => Craft::t(
                                'herald',
                                'Allows viewing and editing the Herald Settings screen. Editing still requires allowAdminChanges to be enabled in the environment.',
                            ),
                        ],
                        SettingsController::PERMISSION_MANAGE_GRANTS => [
                            'label' => Craft::t('herald', 'Manage Herald temporary grants'),
                            'info' => Craft::t(
                                'herald',
                                'Allows issuing and revoking temporary command-execution grants for a user. Issuing a grant widens what the craft_command tool may run for the granted user until the grant expires.',
                            ),
                        ],
                        Skill::PERMISSION_MANAGE => [
                            'label' => Craft::t('herald', 'Manage Herald skills'),
                            'info' => Craft::t(
                                'herald',
                                'Allows creating, updating, and deleting Herald skill elements through the MCP server.',
                            ),
                        ],
                        Herald::PERMISSION_VIEW_ACTIVITY => [
                            'label' => Craft::t('herald', 'View Herald activity log'),
                            'info' => Craft::t(
                                'herald',
                                'Allows viewing the Activity tab in Herald CP. Non-admins only see their own invocations; admins see every row.',
                            ),
                        ],
                        CraftCommand::PERMISSION_RUN_COMMANDS => [
                            'label' => Craft::t('herald', 'Run Craft console commands'),
                            'info' => Craft::t(
                                'herald',
                                'Allows the craft_command and resave tools to dispatch allowlisted Craft console commands for this user over the HTTP transport. The command allowlist and allowAdminChanges still apply.',
                            ),
                        ],
                        ClearCaches::PERMISSION_CLEAR_CACHES => [
                            'label' => Craft::t('herald', 'Clear Craft caches'),
                            'info' => Craft::t(
                                'herald',
                                'Allows the clear_caches tool to flush Craft caches for this user over the HTTP transport. Separate from the console-command permission, which does not grant it.',
                            ),
                        ],
                    ],
                ];
            },
        );
    }

    /**
     * Wires the PC field-layout change handlers for the single
     * `plugins.herald.skillFieldLayout` path. Mirrors Craft's own
     * `ApplicationTrait::_registerConfigListeners()` shape for
     * `PATH_ADDRESS_FIELD_LAYOUTS`. The handler runs on add /
     * update / remove so the field layout stays in sync between
     * PC and the live `Fields` service across environments.
     *
     * @author CraftPulse
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
     * Registers the HTTP transport endpoint at `/herald/mcp` and the
     * CP-side routes for the tabbed Herald settings screen.
     *
     * Two events fire — `EVENT_REGISTER_SITE_URL_RULES` for the
     * front-end `herald/mcp` + OAuth + `.well-known/*` routes, and
     * `EVENT_REGISTER_CP_URL_RULES` for the CP-side tabs introduced
     * in Gate 9. The events fire at different stages of the URL
     * manager bootstrap; mixing them in one handler silently drops
     * half the routes.
     *
     * **Site rules** (POST/GET/DELETE `herald/mcp`, `oauth/*`,
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
     * at `/oauth/*` (not under `/herald/`) for client compatibility;
     * the `.well-known/*` discovery endpoints land at the site root
     * per RFC 8414 §3 and RFC 9728 §3 — both RFCs explicitly require
     * the well-known paths to be at the root of the issuer URL.
     *
     * **CP rules** (Gate 9.1): `settings/plugins/herald` is the
     * redirect target from `Herald::getSettingsResponse()` and the
     * Settings → Plugins → Herald nav link. The `herald/{tab}` URLs
     * are the per-tab routes (locked decision 2 — per-tab routes
     * over anchor-based tabs for bookmarking, deep links, and
     * independent badge counts). Table-data + mutation routes
     * (token issue/revoke, activity rows) land in 9.2 / 9.3 alongside
     * their respective controller actions.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _registerUrlRules(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            static function(RegisterUrlRulesEvent $event): void {
                $event->rules['POST herald/mcp'] = 'herald/mcp/index';
                $event->rules['GET herald/mcp'] = 'herald/mcp/index';
                $event->rules['DELETE herald/mcp'] = 'herald/mcp/index';

                $event->rules['GET oauth/authorize'] = 'herald/oauth/authorize';
                $event->rules['POST oauth/authorize'] = 'herald/oauth/authorize';
                $event->rules['GET oauth/elevate'] = 'herald/oauth/elevate';
                $event->rules['POST oauth/elevate'] = 'herald/oauth/elevate';
                $event->rules['POST oauth/token'] = 'herald/oauth/token';
                $event->rules['POST oauth/register'] = 'herald/oauth/register';
                $event->rules['POST oauth/revoke'] = 'herald/oauth/revoke';

                $event->rules['GET .well-known/oauth-authorization-server'] = 'herald/well-known/authorization-server';
                $event->rules['GET .well-known/oauth-protected-resource'] = 'herald/well-known/protected-resource';
            },
        );

        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            static function(RegisterUrlRulesEvent $event): void {
                // Prepend so the literal `settings/plugins/herald` pattern beats
                // Craft's wildcard `settings/plugins/<handle>` rule from
                // `vendor/craftcms/cms/src/config/cproutes/common.php:66`. Yii
                // matches rules in array order — the wildcard is loaded first
                // and would otherwise route to `plugins/edit-plugin-settings`,
                // which calls `Herald::getSettingsResponse()` and redirects
                // back to the same URL (infinite loop).
                $event->rules = array_merge([
                    'settings/plugins/herald' => 'herald/settings/index',
                    // The CP section root (`/herald`) and the Settings
                    // subnav item both land on the Settings screen. The
                    // section gained a `getCpNavItem()` subnav in the Gate 9
                    // CP rework — clicking the sidebar section lands on
                    // Settings, the first subnav entry.
                    'herald' => 'herald/settings/index',
                    'herald/settings' => 'herald/settings/index',
                    'herald/tokens' => 'herald/settings/tokens',
                    'herald/tokens/table-data' => 'herald/settings/tokens-table-data',
                    'herald/tokens/issue-slideout' => 'herald/settings/token-issue-slideout',
                    'herald/tokens/issue' => 'herald/settings/issue-token',
                    'herald/tokens/revoke' => 'herald/settings/revoke-token',
                    'herald/allowlist' => 'herald/settings/allowlist',
                    'herald/allowlist/table-data' => 'herald/settings/allowlist-table-data',
                    'herald/allowlist/override-slideout' => 'herald/settings/allowlist-override-slideout',
                    'herald/activity' => 'herald/settings/activity',
                    'herald/activity/table-data' => 'herald/settings/activity-table-data',
                    'herald/activity/row' => 'herald/settings/activity-row',
                    'herald/connection' => 'herald/settings/connection',
                    'herald/clients' => 'herald/settings/clients',
                    'herald/clients/table-data' => 'herald/settings/clients-table-data',
                    'herald/clients/approve' => 'herald/settings/approve-client',
                    'herald/clients/revoke' => 'herald/settings/revoke-client',
                ], $event->rules);
            },
        );
    }
}
