<?php

namespace craftpulse\herald\plugin;

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
use yii\base\InvalidConfigException;

/**
 * =========================================================================
 * Herald service-accessor trait.
 *
 * Mirrors the Craft Commerce `plugin/Services` pattern
 * (`vendor/craftcms/commerce/src/plugin/Services.php`). Each typed
 * getter wraps Yii's component resolution so callers see a real
 * return type — no `@property-read` PHPDoc magic on the main plugin
 * class, no drift between the class-level docblock and the actual
 * `config()` map.
 *
 * Components are still declared in `Herald::config()` (Yii's
 * component map is what makes `$this->get('xxx')` resolve). The
 * trait provides the typed surface; `config()` provides the
 * dependency wiring. Both files together are the source of truth —
 * adding a service means editing both.
 *
 * `$plugin->tools` continues to resolve via Yii's `__get()` walking
 * the trait's `getTools()` getter, so existing callers and tests
 * keep working unchanged. The `@property` tags below teach PHPStan
 * about the magic-property resolution — without them, level 8
 * flags every `$plugin->xxx` access as an undefined property.
 *
 * Each getter narrows `Component::get()`'s `?object` return via
 * `assert($component instanceof <Service>)`. PHPStan recognises
 * the assert and narrows the local type; the assert is also
 * defense-in-depth when assertions are enabled (dev installs).
 * =========================================================================
 *
 * @property Allowlist $allowlist the runtime-allowlist override service
 * @property Audit $audit the Audit Kit native-event emitter
 * @property Invocations $invocations the HTTP-transport audit-log writer
 * @property Oauth $oauth the OAuth 2.1 authorization-server orchestrator
 * @property Prompts $prompts the MCP prompts registry
 * @property RateLimiter $rateLimiter the per-user rate limiter
 * @property Resources $resources the MCP resources registry
 * @property Scopes $scopes the OAuth capability-scope vocabulary + tool→scope authority
 * @property Sessions $sessions the HTTP-transport session store
 * @property Skills $skills the Herald skills service — field layout + merged-corpus lookup
 * @property Tokens $tokens the bearer-token issuance / lookup / revoke service
 * @property Tools $tools the MCP tool registry
 *
 * @author CraftPulse
 * @since  5.0.0
 */
trait Services
{
    // Public Methods
    // =========================================================================

    /**
     * Returns the runtime command-allowlist override service.
     *
     * @throws InvalidConfigException When the component is not registered.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function getAllowlist(): Allowlist
    {
        $component = $this->get('allowlist');
        assert($component instanceof Allowlist);
        return $component;
    }

    /**
     * Returns the Audit Kit native-event emitter — registers Herald's
     * `AuditEventType`s and emits `AuditEvent`s onto the kit dispatch bus.
     *
     * @throws InvalidConfigException When the component is not registered.
     *
     * @author CraftPulse
     * @since  5.1.0
     */
    public function getAudit(): Audit
    {
        $component = $this->get('audit');
        assert($component instanceof Audit);
        return $component;
    }

    /**
     * Returns the HTTP-transport audit-log writer.
     *
     * @throws InvalidConfigException When the component is not registered.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function getInvocations(): Invocations
    {
        $component = $this->get('invocations');
        assert($component instanceof Invocations);
        return $component;
    }

    /**
     * Returns the OAuth 2.1 authorization-server orchestrator
     * (`league/oauth2-server` wrapper plus DCR + audience binding).
     *
     * @throws InvalidConfigException When the component is not registered.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function getOauth(): Oauth
    {
        $component = $this->get('oauth');
        assert($component instanceof Oauth);
        return $component;
    }

    /**
     * Returns the MCP prompts registry — one prompt per bundled
     * `michtio/craftcms-claude-skills` skill.
     *
     * @throws InvalidConfigException When the component is not registered.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function getPrompts(): Prompts
    {
        $component = $this->get('prompts');
        assert($component instanceof Prompts);
        return $component;
    }

    /**
     * Returns the per-user token-bucket rate limiter (PSR-16 backed).
     *
     * @throws InvalidConfigException When the component is not registered.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function getRateLimiter(): RateLimiter
    {
        $component = $this->get('rateLimiter');
        assert($component instanceof RateLimiter);
        return $component;
    }

    /**
     * Returns the MCP resources registry — bundled skill + agent
     * resource URIs.
     *
     * @throws InvalidConfigException When the component is not registered.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function getResources(): Resources
    {
        $component = $this->get('resources');
        assert($component instanceof Resources);
        return $component;
    }

    /**
     * Returns the OAuth capability-scope service — the scope
     * vocabulary plus the authoritative tool→scope map.
     *
     * @throws InvalidConfigException When the component is not registered.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function getScopes(): Scopes
    {
        $component = $this->get('scopes');
        assert($component instanceof Scopes);
        return $component;
    }

    /**
     * Returns the HTTP-transport session store (cache-backed
     * sliding-expiry sessions keyed by `Mcp-Session-Id`).
     *
     * @throws InvalidConfigException When the component is not registered.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function getSessions(): Sessions
    {
        $component = $this->get('sessions');
        assert($component instanceof Sessions);
        return $component;
    }

    /**
     * Returns the Herald skills service — field-layout management and
     * merged-corpus lookup combining bundled + element-stored skills.
     *
     * @throws InvalidConfigException When the component is not registered.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function getSkills(): Skills
    {
        $component = $this->get('skills');
        assert($component instanceof Skills);
        return $component;
    }

    /**
     * Returns the bearer-token issuance / lookup / revocation
     * service for the HTTP transport.
     *
     * @throws InvalidConfigException When the component is not registered.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function getTokens(): Tokens
    {
        $component = $this->get('tokens');
        assert($component instanceof Tokens);
        return $component;
    }

    /**
     * Returns the MCP tool registry — the canonical entry point for
     * tool enumeration, lookup, and per-user filtering.
     *
     * @throws InvalidConfigException When the component is not registered.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function getTools(): Tools
    {
        $component = $this->get('tools');
        assert($component instanceof Tools);
        return $component;
    }
}
