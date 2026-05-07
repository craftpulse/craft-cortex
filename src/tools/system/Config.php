<?php

namespace craftpulse\cortex\tools\system;

use Craft;
use craftpulse\cortex\attributes\IsIdempotent;
use craftpulse\cortex\attributes\IsReadOnly;
use craftpulse\cortex\tools\AbstractTool;
use craftpulse\cortex\tools\support\Schema;
use craftpulse\cortex\tools\ToolException;

/**
 * =========================================================================
 * `config` tool — curated, secrets-redacted config lookup.
 *
 * Multi-mode read tool. Splits five concerns — general/custom/db/email/
 * system_messages — that are otherwise scattered across `getConfig()`,
 * `getProjectConfig()`, and `getSystemMessages()`.
 *
 * Hard rules (PLANNING.md 4.9):
 *   - No secrets in any output. The redaction set covers obvious keys:
 *     password, securityKey, token, secret, key, salt, apiKey,
 *     cookieValidationKey, accessKey, privateKey. Any matching key gets
 *     replaced with the literal string `"<redacted>"`.
 *   - The general-config payload is a curated whitelist, not the full
 *     GeneralConfig dump (which contains arbitrary plugin overrides).
 *   - DB mode never returns user, password, or DSN — host / database
 *     name / driver / port / charset / collation only.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  0.1.0
 */
#[IsReadOnly]
#[IsIdempotent]
class Config extends AbstractTool
{
    // Constants
    // =========================================================================

    private const REDACTED = '<redacted>';

    /**
     * Lower-cased substrings that mark a key as a secret. Match is
     * substring-anywhere so `cookieValidationKey`, `apiSecret`,
     * `private_key`, `awsAccessKey` all redact.
     */
    private const SECRET_NEEDLES = [
        'password', 'securitykey', 'token', 'secret', 'apikey',
        'accesskey', 'privatekey', 'salt', 'cookievalidationkey',
        'webhooksecret', 'jwt', 'oauth',
    ];

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
        return 'config';
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public static function getDescription(): string
    {
        return 'Read curated Craft configuration. Modes: `general` (whitelisted ' .
            'GeneralConfig fields), `custom` (config/custom.php), `db` (host/name/driver/' .
            'port — never user/password), `email` (transport adapter from project config), ' .
            '`system_messages` (system email message keys). All output is secrets-redacted.';
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public static function getInputSchema(): array
    {
        return Schema::object([
            'mode' => Schema::string()
                ->enum(['general', 'custom', 'db', 'email', 'system_messages'])
                ->description('Required.')
                ->required(),
        ])->toArray();
    }

    /**
     * @inheritdoc
     *
     * @throws ToolException
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public function execute(array $arguments): array
    {
        $mode = $this->_mode($arguments);
        if ($mode === null) {
            throw new ToolException('`mode` is required (general / custom / db / email / system_messages).');
        }

        return match ($mode) {
            'general' => $this->_general(),
            'custom' => $this->_custom(),
            'db' => $this->_db(),
            'email' => $this->_email(),
            'system_messages' => $this->_systemMessages(),
            default => throw new ToolException("Unknown mode: '{$mode}'."),
        };
    }

    // Private Methods
    // =========================================================================

    /**
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    private function _general(): array
    {
        $g = Craft::$app->getConfig()->getGeneral();

        // Curated whitelist. The full GeneralConfig has 100+ properties
        // including arbitrary plugin overrides — picking the safe subset
        // keeps output predictable and avoids accidentally surfacing a
        // sensitive plugin setting.
        return [
            'mode' => 'general',
            'allowAdminChanges' => $g->allowAdminChanges,
            'allowSimilarTags' => $g->allowSimilarTags,
            'allowUppercaseInSlug' => $g->allowUppercaseInSlug,
            'autoLoginAfterAccountActivation' => $g->autoLoginAfterAccountActivation,
            'cacheDuration' => $g->cacheDuration,
            'cpHeadTags' => $g->cpHeadTags,
            'cpTrigger' => $g->cpTrigger,
            'defaultCpLanguage' => $g->defaultCpLanguage,
            'defaultDirMode' => $g->defaultDirMode,
            'defaultFileMode' => $g->defaultFileMode,
            'defaultSearchTermOptions' => $g->defaultSearchTermOptions,
            'defaultTokenDuration' => $g->defaultTokenDuration,
            'defaultWeekStartDay' => $g->defaultWeekStartDay,
            'devMode' => $g->devMode,
            'disabledPlugins' => $g->disabledPlugins,
            'elevatedSessionDuration' => $g->elevatedSessionDuration,
            'enableBasicHttpAuth' => $g->enableBasicHttpAuth,
            'enableCsrfCookie' => $g->enableCsrfCookie,
            'enableCsrfProtection' => $g->enableCsrfProtection,
            'enableTemplateCaching' => $g->enableTemplateCaching,
            'errorTemplatePrefix' => $g->errorTemplatePrefix,
            'extraAllowedFileExtensions' => $g->extraAllowedFileExtensions,
            'invalidLoginWindowDuration' => $g->invalidLoginWindowDuration,
            'maxBackups' => $g->maxBackups,
            'maxInvalidLogins' => $g->maxInvalidLogins,
            'maxRevisions' => $g->maxRevisions,
            'maxSlugIncrement' => $g->maxSlugIncrement,
            'maxUploadFileSize' => $g->maxUploadFileSize,
            'omitScriptNameInUrls' => $g->omitScriptNameInUrls,
            'pageTrigger' => $g->pageTrigger,
            'permissionsPolicyHeader' => $g->permissionsPolicyHeader,
            'phpMaxMemoryLimit' => $g->phpMaxMemoryLimit,
            'preserveCmykColorspace' => $g->preserveCmykColorspace,
            'preserveExifData' => $g->preserveExifData,
            'preserveImageColorProfiles' => $g->preserveImageColorProfiles,
            'previewTokenDuration' => $g->previewTokenDuration,
            'rememberUsernameDuration' => $g->rememberUsernameDuration,
            'rememberedUserSessionDuration' => $g->rememberedUserSessionDuration,
            'requireMatchingUserAgentForSession' => $g->requireMatchingUserAgentForSession,
            'runQueueAutomatically' => $g->runQueueAutomatically,
            'sendPoweredByHeader' => $g->sendPoweredByHeader,
            'siteToken' => $g->siteToken,
            'slugWordSeparator' => $g->slugWordSeparator,
            'softDeleteDuration' => $g->softDeleteDuration,
            'storeUserIps' => $g->storeUserIps,
            'timezone' => $g->timezone,
            'translationDebugOutput' => $g->translationDebugOutput,
            'upscaleImages' => $g->upscaleImages,
            'useEmailAsUsername' => $g->useEmailAsUsername,
            'usePathInfo' => $g->usePathInfo,
            'useSecureCookies' => $g->useSecureCookies,
            'verificationCodeDuration' => $g->verificationCodeDuration,
        ];
    }

    /**
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    private function _custom(): array
    {
        $custom = Craft::$app->getConfig()->getCustom();

        // `getCustom()` returns a generic object wrapping the values from
        // config/custom.php. Casting to array gives us the underlying
        // key/value map, which is what we redact.
        $values = $custom instanceof \Closure ? [] : (array) $custom;

        return [
            'mode' => 'custom',
            'values' => $this->_redact($values),
        ];
    }

    /**
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    private function _db(): array
    {
        $db = Craft::$app->getConfig()->getDb();

        return [
            'mode' => 'db',
            'driver' => $db->driver,
            'server' => $db->server,
            'port' => $db->port,
            'database' => $db->database,
            'tablePrefix' => $db->tablePrefix,
            'schema' => $db->schema,
            'unixSocket' => $db->unixSocket !== '' ? '<set>' : '',
            // Intentionally NOT included: user, password, dsn, attributes.
        ];
    }

    /**
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    private function _email(): array
    {
        $projectConfig = Craft::$app->getProjectConfig();
        $email = (array) ($projectConfig->get('email') ?? []);

        return [
            'mode' => 'email',
            'config' => $this->_redact($email),
        ];
    }

    /**
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    private function _systemMessages(): array
    {
        $messages = Craft::$app->getSystemMessages()->getAllMessages();

        return [
            'mode' => 'system_messages',
            'messages' => array_map(
                static fn ($m): array => [
                    'key' => $m->key ?? null,
                    'heading' => $m->heading ?? null,
                    'subject' => $m->subject ?? null,
                ],
                $messages,
            ),
            'count' => count($messages),
        ];
    }

    /**
     * Walk an array recursively and replace any value whose key contains
     * a secret needle with the redacted sentinel. Non-string keys pass
     * through unchanged.
     *
     * @param array<int|string,mixed> $data
     * @return array<int|string,mixed>
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    private function _redact(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && $this->_isSecretKey($key)) {
                $out[$key] = self::REDACTED;
                continue;
            }

            $out[$key] = is_array($value) ? $this->_redact($value) : $value;
        }

        return $out;
    }

    /**
     * @author Craftpulse
     * @since  0.1.0
     */
    private function _isSecretKey(string $key): bool
    {
        $needle = strtolower(str_replace(['_', '-'], '', $key));
        foreach (self::SECRET_NEEDLES as $bad) {
            if (str_contains($needle, $bad)) {
                return true;
            }
        }

        return false;
    }
}
