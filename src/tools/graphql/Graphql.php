<?php

namespace craftpulse\cortex\tools\graphql;

use Craft;
use craft\helpers\DateTimeHelper;
use craft\models\GqlSchema;
use craft\models\GqlToken;
use craftpulse\cortex\tools\AbstractTool;
use craftpulse\cortex\tools\ToolException;
use GraphQL\Utils\SchemaPrinter;
use Throwable;

/**
 * =========================================================================
 * `graphql` tool — read-only introspection over Craft's GraphQL surface.
 *
 * Three modes (PLANNING.md 4.5):
 *
 *   - `list_schemas`  — every schema with id / name / uid / isPublic / scope summary.
 *   - `get_sdl`       — printed SDL for a schema (by name) or the public schema.
 *   - `list_tokens`   — token metadata only. **The `accessToken` value is
 *                       never returned**, only a SHA-256 fingerprint and
 *                       enabled/expiry state.
 *
 * Tokens are sensitive: leaking an `accessToken` lets a caller execute
 * arbitrary GraphQL against the bound schema. Treat them like passwords.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  0.1.0
 */
class Graphql extends AbstractTool
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
        return 'graphql';
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public static function getDescription(): string
    {
        return 'Read-only GraphQL introspection. Modes: `list_schemas` (id/name/uid/isPublic/' .
            'scope summary), `get_sdl` (printed SDL — accepts `name` for a specific schema or ' .
            'omits it for the public schema), `list_tokens` (token metadata only — names, ' .
            'expiry, enabled flag, fingerprint; never the access-token value itself).';
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public static function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'mode' => [
                    'type' => 'string',
                    'enum' => ['list_schemas', 'get_sdl', 'list_tokens'],
                    'description' => 'Required.',
                ],
                'name' => [
                    'type' => 'string',
                    'description' => 'For `get_sdl`: schema name. Omit for the public schema.',
                ],
            ],
            'required' => ['mode'],
            'additionalProperties' => false,
        ];
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
            throw new ToolException('`mode` is required (list_schemas / get_sdl / list_tokens).');
        }

        return match ($mode) {
            'list_schemas' => $this->_listSchemas(),
            'get_sdl' => $this->_getSdl($arguments),
            'list_tokens' => $this->_listTokens(),
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
    private function _listSchemas(): array
    {
        $gql = Craft::$app->getGql();
        $schemas = $gql->getSchemas();
        $public = $gql->getPublicSchema();

        $rows = array_map(
            fn (GqlSchema $s): array => $this->_schemaRow($s),
            $schemas,
        );

        // The public schema is not always returned by getSchemas() depending
        // on enabled state, so make sure callers can see it as a row even
        // when it's disabled — that's information the LLM needs to suggest
        // enabling it.
        if ($public !== null && !$this->_schemaInList($public, $schemas)) {
            $rows[] = $this->_schemaRow($public);
        }

        return [
            'mode' => 'list_schemas',
            'schemas' => $rows,
            'count' => count($rows),
        ];
    }

    /**
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     *
     * @throws ToolException
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    private function _getSdl(array $arguments): array
    {
        $gql = Craft::$app->getGql();
        $name = isset($arguments['name']) && is_string($arguments['name']) && $arguments['name'] !== ''
            ? $arguments['name']
            : null;

        if ($name === null) {
            $schema = $gql->getPublicSchema();
            if ($schema === null) {
                throw new ToolException(
                    'No public schema is configured. Pass `name` to target a specific schema.',
                );
            }
        } else {
            $schema = $this->_findSchemaByName($name);
            if ($schema === null) {
                throw new ToolException("No GraphQL schema found with name '{$name}'.");
            }
        }

        try {
            $built = $gql->getSchemaDef($schema, true);
            $sdl = SchemaPrinter::doPrint($built);
        } catch (Throwable $e) {
            throw new ToolException('Failed to build SDL: ' . $e->getMessage());
        }

        return [
            'mode' => 'get_sdl',
            'schema' => [
                'name' => $schema->name,
                'uid' => $schema->uid,
                'isPublic' => $schema->isPublic,
            ],
            'sdl' => $sdl,
            'length' => strlen($sdl),
        ];
    }

    /**
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    private function _listTokens(): array
    {
        $tokens = Craft::$app->getGql()->getTokens();

        $rows = array_map(
            fn (GqlToken $t): array => [
                'id' => $t->id,
                'name' => $t->name,
                'uid' => $t->uid,
                'enabled' => $t->enabled,
                'isPublic' => $t->getIsPublic(),
                'isValid' => $t->getIsValid(),
                'isExpired' => $t->getIsExpired(),
                'schemaId' => $t->schemaId,
                'schemaName' => $t->getSchema()?->name,
                'expiryDate' => $t->expiryDate !== null ? DateTimeHelper::toIso8601($t->expiryDate) : null,
                'lastUsed' => $t->lastUsed !== null ? DateTimeHelper::toIso8601($t->lastUsed) : null,
                'dateCreated' => $t->dateCreated !== null ? DateTimeHelper::toIso8601($t->dateCreated) : null,
                // Fingerprint, not the value — lets an operator correlate
                // a CP-displayed token with the API without leaking it.
                'accessTokenFingerprint' => $this->_fingerprint($t->accessToken),
            ],
            $tokens,
        );

        return [
            'mode' => 'list_tokens',
            'tokens' => $rows,
            'count' => count($rows),
        ];
    }

    /**
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    private function _schemaRow(GqlSchema $schema): array
    {
        // `scope` is a flat list of permission strings. We surface the
        // raw scope plus a count so the AI can decide whether to ask for
        // SDL detail or move on.
        return [
            'id' => $schema->id,
            'name' => $schema->name,
            'uid' => $schema->uid,
            'isPublic' => $schema->isPublic,
            'scopeCount' => count($schema->scope),
            'scope' => $schema->scope,
        ];
    }

    /**
     * @param GqlSchema[] $schemas
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    private function _schemaInList(GqlSchema $needle, array $schemas): bool
    {
        foreach ($schemas as $s) {
            if ($s->uid === $needle->uid) {
                return true;
            }
        }

        return false;
    }

    /**
     * @author Craftpulse
     * @since  0.1.0
     */
    private function _findSchemaByName(string $name): ?GqlSchema
    {
        foreach (Craft::$app->getGql()->getSchemas() as $schema) {
            if ($schema->name === $name) {
                return $schema;
            }
        }

        return null;
    }

    /**
     * SHA-256 fingerprint of a token, truncated to 12 hex chars. Lets an
     * operator correlate a CP-displayed token with API output without
     * leaking the value. `__PUBLIC__` and empty strings get `null` so
     * the LLM doesn't try to use the placeholder as a real token.
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    private function _fingerprint(string $token): ?string
    {
        if ($token === '' || $token === GqlToken::PUBLIC_TOKEN) {
            return null;
        }

        return substr(hash('sha256', $token), 0, 12);
    }
}
