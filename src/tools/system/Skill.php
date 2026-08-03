<?php

namespace craftpulse\herald\tools\system;

use Craft;
use craft\elements\User;
use craftpulse\herald\attributes\IsDestructive;
use craftpulse\herald\attributes\IsIdempotent;
use craftpulse\herald\attributes\Title;
use craftpulse\herald\elements\Skill as SkillElement;
use craftpulse\herald\Herald;
use craftpulse\herald\tools\AbstractTool;
use craftpulse\herald\tools\ContextAwareToolInterface;
use craftpulse\herald\tools\ElevationGatedToolTrait;
use craftpulse\herald\tools\IdempotencyTrait;
use craftpulse\herald\tools\PermissionedToolTrait;
use craftpulse\herald\tools\ProToolTrait;
use craftpulse\herald\tools\support\Schema;
use craftpulse\herald\tools\ToolException;
use Michtio\CraftCmsClaudeSkills\Skills as BundledSkills;

/**
 * =========================================================================
 * `skill` Pro tool — list / get / create / update / delete element-stored
 * Herald Skills (Gate 8.6).
 *
 * Five modes dispatched off the `mode` argument:
 *
 *   - `list`   — return the merged corpus (bundled + element-stored).
 *                Filter `source` to `bundled` / `element` / `all`
 *                (default `all`). Optional substring `search` filters
 *                against the row's content. Paginated by `limit / offset`.
 *   - `get`    — return a single skill by `id`, `uid`, or `handle`.
 *                Handle lookup hits the merged corpus (bundled or
 *                element). `id` / `uid` lookups require an element-
 *                stored skill.
 *   - `create` — instantiate a fresh `Skill` element, set
 *                title / description / body / fields, save via
 *                `Elements::saveElement()`. Handle is required and
 *                validated unique against element-stored skills.
 *                Handle collision with a bundled-only skill is allowed
 *                — that IS the override mechanism (locked decision 2).
 *   - `update` — load by id / uid / handle, mutate title / description
 *                / body / fields, save. Handle changes are REJECTED —
 *                handle is the natural key. Mirror the `address`
 *                ownership-change refusal pattern.
 *   - `delete` — soft-delete by default; `hardDelete: true` removes the
 *                row entirely (FK CASCADE wipes `herald_skills`).
 *
 * Permission contract — `herald:manage-skills` (global, no per-instance
 * ACL). Admins always pass; non-admins pass when granted the
 * permission. The element's `canSave / canDelete / canView /
 * canDuplicate` overrides resolve to the same permission; the mode
 * methods call `Elements::canSave / canView / canDelete` for defense-
 * in-depth.
 *
 * Idempotency: `idempotencyKey` is server-side dedup for `create` and
 * `update`. Cache prefix `herald:skill:idem:`. TTL 24h. Skipped on
 * stdio.
 *
 * Bundled-skills repo portability: the bundled corpus loads from
 * `vendor/michtio/craftcms-claude-skills/` via the bundled `Skills`
 * helper. A future Settings field (`$skillsCorpusPath`) could let
 * callers point at a fork; out of scope for Gate 8.6.
 *
 * GraphQL exposure: registering `Skill::class` via
 * `EVENT_REGISTER_ELEMENT_TYPES` surfaces skills in Craft's auto-
 * generated GraphQL schema. GraphQL has its own schema-component
 * gate — out of scope for Gate 8.6 (mention in the tool description).
 * =========================================================================
 *
 * @author CraftPulse
 * @since  5.0.0
 */
#[IsDestructive]
#[IsIdempotent(false)]
#[Title('Skill — list / get / create / update / delete (Herald skills)')]
class Skill extends AbstractTool implements ContextAwareToolInterface
{
    use ElevationGatedToolTrait;
    use IdempotencyTrait;
    use PermissionedToolTrait;
    use ProToolTrait;

    // Constants
    // =========================================================================

    /**
     * Default page size for `list` mode.
     *
     * @since 5.0.0
     */
    public const DEFAULT_LIMIT = 50;

    /**
     * Hard cap for `list` mode page size.
     *
     * @since 5.0.0
     */
    public const MAX_LIMIT = 200;

    /**
     * Idempotency cache key prefix. Consumed by `IdempotencyTrait`.
     *
     * @since 5.0.0
     */
    public const IDEMPOTENCY_CACHE_PREFIX = 'herald:skill:idem:';

    /**
     * Source filter values for `list` mode. `all` is the default.
     *
     * @since 5.0.0
     */
    public const SOURCE_BUNDLED = 'bundled';
    public const SOURCE_ELEMENT = 'element';
    public const SOURCE_ALL = 'all';

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public static function getName(): string
    {
        return 'skill';
    }

    /**
     * @inheritdoc
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public static function getDescription(): string
    {
        return 'Write tool for Herald skill elements. Modes: list / get / create / update / ' .
            'delete. Skills are markdown documents addressable by handle; element-stored ' .
            'skills override bundled `michtio/craftcms-claude-skills` skills on handle ' .
            'collision (see locked decision 2 of Gate 8.6). list returns the merged ' .
            'corpus with a `source: bundled | element` field on every row; filter via ' .
            'source=bundled / element / all (default all). create requires handle + title; ' .
            'update accepts id / uid / handle but rejects handle changes (natural-key ' .
            'invariant). delete soft-deletes by default; hardDelete=true removes the row ' .
            'entirely and cascades the herald_skills FK. Permission: herald:manage-skills ' .
            '(global, no per-instance ACL). idempotencyKey caches the result for 24h.';
    }

    /**
     * @inheritdoc
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public static function getInputSchema(): array
    {
        return Schema::object([
            'mode' => Schema::string()
                ->enum(['list', 'get', 'create', 'update', 'delete'])
                ->required()
                ->description('Operation to perform.'),

            // Element resolution.
            'id' => Schema::integer()->description('Skill element id. get / update / delete (one of id / uid / handle).'),
            'uid' => Schema::string()->description('Skill element uid. get / update / delete (alternative to id).'),
            'handle' => Schema::string()->description('Skill handle (slug, globally unique). Required for create; one of id / uid / handle on get / update / delete.'),

            // Mutation payload.
            'title' => Schema::string()->description('Human label for the skill (maps to the bundled `name` frontmatter field). Required on create.'),
            'description' => Schema::string()
                ->maxLength(4096)
                ->description('Short description. Native column; <= 4096 chars.'),
            'body' => Schema::string()->description('Markdown body content. Stored as the `body` field-layout value when present.'),

            // Custom-field values — pass-through to setFieldValues().
            'fields' => Schema::object()
                ->additionalProperties(true)
                ->description('Custom field values keyed by handle. Forwarded verbatim to `Element::setFieldValues()`; Craft normalises per field type.'),

            // List pagination + filters.
            'limit' => Schema::integer()->minimum(1)->maximum(self::MAX_LIMIT)->description('list mode: page size. Default 50, max 200.'),
            'offset' => Schema::integer()->minimum(0)->description('list mode: offset. Default 0.'),
            'search' => Schema::string()->description('list mode: substring filter applied to handle / title / body bytes.'),
            'source' => Schema::string()
                ->enum([self::SOURCE_BUNDLED, self::SOURCE_ELEMENT, self::SOURCE_ALL])
                ->description('list mode: filter by row provenance. Default `all`.'),

            // Mode-specific flags.
            'hardDelete' => Schema::boolean()->description('delete only: when true, removes the row entirely (FK CASCADE wipes the herald_skills row). Default false.'),

            // Idempotency.
            'idempotencyKey' => Schema::string()
                ->maxLength(self::IDEMPOTENCY_KEY_MAX_LENGTH)
                ->description('create / update only: server-side dedup token. Same key issued twice within 24h returns the cached envelope without re-saving.'),
        ])->toArray();
    }

    /**
     * @inheritdoc
     *
     * stdio is trusted; admins always pass; non-admins need
     * `herald:manage-skills`. Mirrors `Address::filterFor()` shape.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function filterFor(?User $user = null): bool
    {
        if ($user === null) {
            return true;
        }
        if ($user->admin) {
            return true;
        }
        return (bool) $user->can(SkillElement::PERMISSION_MANAGE);
    }

    /**
     * @inheritdoc
     *
     * Uniform mode-enum surface for every caller — mirrors the
     * `address` pattern. Per-mode permission denials happen in
     * `execute()` via the trait's `_assertPermission()` plus per-
     * element re-checks.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function inputSchemaFor(?User $user = null): array
    {
        return static::getInputSchema();
    }

    /**
     * @inheritdoc
     *
     * @throws ToolException
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function execute(array $arguments): array
    {
        $mode = $this->_mode($arguments);
        if ($mode === null) {
            throw new ToolException('skill: `mode` is required.');
        }

        return match ($mode) {
            'list' => $this->_list($arguments),
            'get' => $this->_get($arguments),
            'create' => $this->_create($arguments),
            'update' => $this->_update($arguments),
            'delete' => $this->_delete($arguments),
            default => throw new ToolException(
                "skill: unknown mode `{$mode}`. Allowed: list / get / create / update / delete."
            ),
        };
    }

    // Protected Methods
    // =========================================================================

    /**
     * Permission gate — every mutating mode requires
     * `herald:manage-skills`. Read modes (`list` / `get`) skip the
     * mutation gate but still consult `filterFor()` for whole-tool
     * visibility upstream.
     *
     * @param array<string,mixed> $arguments
     * @return string[]
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    protected function _requiredPermissions(array $arguments): array
    {
        $mode = $this->_mode($arguments);
        if ($mode === 'create' || $mode === 'update' || $mode === 'delete') {
            return [SkillElement::PERMISSION_MANAGE];
        }
        return [];
    }

    /**
     * Override `PermissionedToolTrait::_buildPermissionDeniedMessage()`
     * to emit the skill-specific rich format (mode + missing
     * permission). Mirrors `Address::_buildPermissionDeniedMessage()`.
     *
     * @param array<string,mixed> $arguments
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    protected function _buildPermissionDeniedMessage(string $missingPermission, array $arguments): string
    {
        $mode = $this->_mode($arguments) ?? '?';

        return sprintf(
            'permission denied: mode `%s` requires `%s`.',
            $mode,
            $missingPermission,
        );
    }

    // Private Methods
    // =========================================================================

    /**
     * List-mode dispatch. Returns the `kind: skill` rows from the
     * merged corpus only — references and agents are addressable
     * through `search_skills` / `resources.read`, not here. Filters:
     * `source` (bundled / element / all), `search` (substring),
     * `limit` / `offset` (pagination).
     *
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _list(array $arguments): array
    {
        $source = $this->_source($arguments);
        $rows = Herald::getInstance()->skills->getMergedCorpus('skill');

        if ($source !== self::SOURCE_ALL) {
            $rows = array_values(array_filter(
                $rows,
                static fn(array $row): bool => ($row['source'] ?? null) === $source,
            ));
        }

        $search = $arguments['search'] ?? null;
        if (is_string($search) && $search !== '') {
            $needle = mb_strtolower($search);
            $rows = array_values(array_filter(
                $rows,
                static function(array $row) use ($needle): bool {
                    $haystack = mb_strtolower(
                        (string) ($row['skill'] ?? '')
                        . "\n"
                        . (string) ($row['content'] ?? ''),
                    );
                    return str_contains($haystack, $needle);
                },
            ));
        }

        $limit = $this->_limit($arguments, self::DEFAULT_LIMIT, self::MAX_LIMIT);
        $offset = $this->_offset($arguments);
        $paged = array_slice($rows, $offset, $limit);

        $serialized = array_map([$this, '_serializeRow'], $paged);

        return [
            'success' => true,
            'mode' => 'list',
            'skills' => $serialized,
            'count' => count($serialized),
            'limit' => $limit,
            'offset' => $offset,
            'source' => $source,
            'total' => count($rows),
        ];
    }

    /**
     * Get-mode dispatch. Resolves by id / uid / handle. Handle lookup
     * hits the merged corpus (bundled or element); id / uid lookups
     * require an element-stored skill.
     *
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     * @throws ToolException
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _get(array $arguments): array
    {
        $element = $this->_tryResolveElement($arguments);
        if ($element !== null) {
            return [
                'success' => true,
                'mode' => 'get',
                'skill' => $this->_serializeElement($element),
            ];
        }

        // Handle-only lookup: try the merged corpus (bundled fallback).
        $handle = $this->_handleArg($arguments);
        if ($handle === null) {
            throw new ToolException('skill: `id`, `uid`, or `handle` is required.');
        }

        $rows = Herald::getInstance()->skills->getMergedCorpus('skill');
        foreach ($rows as $row) {
            if (($row['skill'] ?? null) === $handle) {
                return [
                    'success' => true,
                    'mode' => 'get',
                    'skill' => $this->_serializeRow($row),
                ];
            }
        }

        throw new ToolException("skill: no skill found for handle `{$handle}`.");
    }

    /**
     * Create-mode dispatch. Instantiate a `Skill` element, apply
     * payload, save. Handle is required and validated unique against
     * other element-stored skills. Handle collision with a bundled-
     * only skill is ACCEPTED — that's the override mechanism.
     *
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     * @throws ToolException
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _create(array $arguments): array
    {
        // Permission was checked when this response was originally
        // computed; userId is in the cache key — see IdempotencyTrait.
        $cacheHit = $this->_idempotencyCacheHit($arguments);
        if ($cacheHit !== null) {
            return $cacheHit;
        }

        $this->_assertPermission($arguments);

        $handle = $this->_handleArg($arguments);
        if ($handle === null) {
            throw new ToolException('skill: `handle` is required on create.');
        }

        $title = $arguments['title'] ?? null;
        if (!is_string($title) || $title === '') {
            throw new ToolException('skill: `title` is required on create.');
        }

        $element = new SkillElement();
        $element->handle = $handle;
        $element->title = $title;
        if (array_key_exists('description', $arguments)) {
            $element->description = is_string($arguments['description']) ? $arguments['description'] : null;
        }
        $this->_applyBody($element, $arguments);
        $this->_applyFields($element, $arguments);

        $caller = Craft::$app->getUser()->getIdentity();
        if ($caller !== null && !Craft::$app->getElements()->canSave($element, $caller)) {
            throw new ToolException('skill: create denied. Caller cannot save Herald skills.');
        }

        if (!Craft::$app->getElements()->saveElement($element, runValidation: true)) {
            return $this->_validationEnvelope($element, 'create');
        }

        $envelope = $this->_successEnvelope($element, 'create');
        $this->_cacheIdempotencyEnvelope($arguments, $envelope);

        return $envelope;
    }

    /**
     * Update-mode dispatch. Resolves the element by id / uid / handle
     * (excludes trashed; probes trashed slot for a restore-hint
     * message). Refuses handle changes — handle is the natural key.
     *
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     * @throws ToolException
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _update(array $arguments): array
    {
        // Permission was checked when this response was originally
        // computed; userId is in the cache key — see IdempotencyTrait.
        $cacheHit = $this->_idempotencyCacheHit($arguments);
        if ($cacheHit !== null) {
            return $cacheHit;
        }

        $this->_assertPermission($arguments);

        $element = $this->_resolveSkillElement($arguments);

        // Reject handle-change attempts — natural-key invariant.
        if (array_key_exists('handle', $arguments)) {
            $newHandle = $arguments['handle'];
            if (is_string($newHandle) && $newHandle !== '' && $newHandle !== $element->handle) {
                return $this->_handleChangeEnvelope($element, 'update');
            }
        }

        $caller = Craft::$app->getUser()->getIdentity();
        if ($caller !== null && !Craft::$app->getElements()->canSave($element, $caller)) {
            throw new ToolException('skill: update denied. Caller cannot save this skill.');
        }

        if (array_key_exists('title', $arguments) && is_string($arguments['title'])) {
            $element->title = $arguments['title'];
        }
        if (array_key_exists('description', $arguments)) {
            $element->description = is_string($arguments['description']) ? $arguments['description'] : null;
        }
        $this->_applyBody($element, $arguments);
        $this->_applyFields($element, $arguments);

        if (!Craft::$app->getElements()->saveElement($element, runValidation: true)) {
            return $this->_validationEnvelope($element, 'update');
        }

        $envelope = $this->_successEnvelope($element, 'update');
        $this->_cacheIdempotencyEnvelope($arguments, $envelope);

        return $envelope;
    }

    /**
     * Delete-mode dispatch. Soft-deletes by default; `hardDelete: true`
     * removes the row entirely (FK CASCADE wipes `herald_skills`).
     *
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     * @throws ToolException
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _delete(array $arguments): array
    {
        $this->_assertPermission($arguments);
        $this->_assertElevated('deleting a skill');

        $element = $this->_resolveSkillElement($arguments);

        $caller = Craft::$app->getUser()->getIdentity();
        if ($caller !== null && !Craft::$app->getElements()->canDelete($element, $caller)) {
            throw new ToolException('skill: delete denied. Caller cannot delete this skill.');
        }

        $hardDelete = (bool) ($arguments['hardDelete'] ?? false);

        if (!Craft::$app->getElements()->deleteElement($element, hardDelete: $hardDelete)) {
            throw new ToolException(
                "skill: delete failed for id={$element->id}. See Craft logs for details."
            );
        }

        return [
            'success' => true,
            'mode' => 'delete',
            'id' => (int) $element->id,
            'uid' => $element->uid,
            'handle' => $element->handle,
            'hardDeleted' => $hardDelete,
        ];
    }

    /**
     * Apply the body payload to the skill element. When the field
     * layout has no `body` field configured, the call is a no-op —
     * the synthesis path renders an empty body in that case.
     *
     * @param array<string,mixed> $arguments
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _applyBody(SkillElement $element, array $arguments): void
    {
        if (!array_key_exists('body', $arguments)) {
            return;
        }
        $value = $arguments['body'];
        if (!is_string($value) && $value !== null) {
            return;
        }
        $layout = $element->getFieldLayout();
        if ($layout === null || $layout->getFieldByHandle('body') === null) {
            return;
        }
        $element->setFieldValue('body', $value);
    }

    /**
     * Resolve the element by id / uid / handle. Excludes trashed
     * elements; probes trashed slot when the primary lookup misses
     * and emits a restore-hint message mirroring `Address`.
     *
     * @param array<string,mixed> $arguments
     * @throws ToolException
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _resolveSkillElement(array $arguments): SkillElement
    {
        $element = $this->_tryResolveElement($arguments);
        if ($element !== null) {
            return $element;
        }

        // Probe the trashed slot for a restore-hint message.
        $query = SkillElement::find()->status(null)->site('*')->trashed(true);
        $id = $arguments['id'] ?? null;
        $uid = $arguments['uid'] ?? null;
        $handle = $this->_handleArg($arguments);

        if (is_int($id) || (is_string($id) && ctype_digit($id))) {
            $query->id((int) $id);
        } elseif (is_string($uid) && $uid !== '') {
            $query->uid($uid);
        } elseif ($handle !== null) {
            $query->handle($handle);
        } else {
            throw new ToolException('skill: `id`, `uid`, or `handle` is required.');
        }

        $trashed = $query->one();
        if ($trashed instanceof SkillElement) {
            $key = $trashed->handle !== null
                ? "handle={$trashed->handle}"
                : ($trashed->id !== null ? "id={$trashed->id}" : "uid={$trashed->uid}");
            throw new ToolException(
                "skill: {$key} is trashed. To remove permanently, use mode=delete with " .
                    'hardDelete=true; to recover, restore the skill via the Craft CP.'
            );
        }

        $key = (is_int($id) || (is_string($id) && ctype_digit($id)))
            ? "id={$id}"
            : (is_string($uid) && $uid !== ''
                ? "uid={$uid}"
                : ($handle !== null
                    ? "handle={$handle}"
                    : 'unknown identifier'));

        throw new ToolException("skill: no element-stored skill found for {$key}.");
    }

    /**
     * Try to resolve a non-trashed element-stored skill from
     * id / uid / handle. Returns null when the identifier is missing
     * or no match is found — callers fall through to a hint message
     * or merged-corpus lookup.
     *
     * @param array<string,mixed> $arguments
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _tryResolveElement(array $arguments): ?SkillElement
    {
        $query = SkillElement::find()->status(null)->site('*');

        $id = $arguments['id'] ?? null;
        $uid = $arguments['uid'] ?? null;
        $handle = $this->_handleArg($arguments);

        if (is_int($id) || (is_string($id) && ctype_digit($id))) {
            $query->id((int) $id);
        } elseif (is_string($uid) && $uid !== '') {
            $query->uid($uid);
        } elseif ($handle !== null) {
            $query->handle($handle);
        } else {
            return null;
        }

        $element = $query->one();
        return $element instanceof SkillElement ? $element : null;
    }

    /**
     * Return the `handle` argument as a string, or null when absent /
     * empty. Cannot use `AbstractTool::_handle()` because that helper
     * already exists and serves a different purpose (entries / users
     * lookups).
     *
     * @param array<string,mixed> $arguments
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _handleArg(array $arguments): ?string
    {
        $handle = $arguments['handle'] ?? null;
        return is_string($handle) && $handle !== '' ? $handle : null;
    }

    /**
     * Return the resolved `source` filter (defaults to `all`).
     *
     * @param array<string,mixed> $arguments
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _source(array $arguments): string
    {
        $source = $arguments['source'] ?? self::SOURCE_ALL;
        if (!is_string($source)) {
            return self::SOURCE_ALL;
        }
        return in_array($source, [self::SOURCE_BUNDLED, self::SOURCE_ELEMENT, self::SOURCE_ALL], true)
            ? $source
            : self::SOURCE_ALL;
    }

    /**
     * Success envelope shape for get / create / update.
     *
     * @return array<string,mixed>
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _successEnvelope(SkillElement $element, string $mode): array
    {
        return [
            'success' => true,
            'mode' => $mode,
            'skill' => $this->_serializeElement($element),
        ];
    }

    /**
     * Validation envelope returned when a handle-change attempt is
     * caught on update. Mirrors `Address::_ownershipChangeEnvelope()`.
     *
     * @return array<string,mixed>
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _handleChangeEnvelope(SkillElement $element, string $mode): array
    {
        return [
            'success' => false,
            'mode' => $mode,
            'id' => $element->id !== null ? (int) $element->id : null,
            'uid' => $element->uid,
            'handle' => $element->handle,
            'errors' => [
                'handle' => [
                    'Skill handle cannot be changed once the element is created. '
                        . 'Handle is the natural key: delete the skill and create a new one.',
                ],
            ],
        ];
    }

    /**
     * Serialize an element-stored skill to the tool's row-shape.
     *
     * @return array<string,mixed>
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _serializeElement(SkillElement $element): array
    {
        $service = Herald::getInstance()->skills;
        $handle = (string) ($element->handle ?? '');
        return [
            'source' => self::SOURCE_ELEMENT,
            'handle' => $handle,
            'title' => $element->title,
            'description' => $element->description,
            'body' => $service->synthesizeContent($element),
            'uri' => sprintf('craft-skills://%s', $handle),
            'id' => $element->id !== null ? (int) $element->id : null,
            'uid' => $element->uid,
            'dateCreated' => $element->dateCreated?->format(\DateTimeInterface::ATOM),
            'dateUpdated' => $element->dateUpdated?->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * Serialize a merged-corpus row to the tool's row-shape. Element
     * rows are upgraded to the full `_serializeElement()` shape (with
     * id / uid / dates); bundled rows surface the bytestream verbatim.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _serializeRow(array $row): array
    {
        $handle = (string) ($row['skill'] ?? '');

        if (($row['source'] ?? null) === self::SOURCE_ELEMENT) {
            $element = Herald::getInstance()->skills->getByHandle($handle);
            if ($element !== null) {
                return $this->_serializeElement($element);
            }
        }

        // Bundled — derive a title from the bundled bytestream's first
        // `# <title>` heading if present, otherwise fall back to the
        // handle. The body for bundled rows IS the full SKILL.md
        // bytes (including frontmatter) — same contract as today's
        // `Skills::content()`.
        $content = (string) ($row['content'] ?? BundledSkills::content($handle));
        $title = $this->_extractBundledTitle($content) ?? $handle;

        return [
            'source' => self::SOURCE_BUNDLED,
            'handle' => $handle,
            'title' => $title,
            'description' => null,
            'body' => $content,
            'uri' => (string) ($row['uri'] ?? sprintf('craft-skills://%s', $handle)),
        ];
    }

    /**
     * Best-effort extraction of the first `# <title>` markdown heading
     * from a bundled SKILL.md bytestream. Returns null when no heading
     * is present — the bundled row's title falls back to the handle.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _extractBundledTitle(string $content): ?string
    {
        // Strip YAML frontmatter if present (delimited by `---` lines).
        if (str_starts_with($content, '---')) {
            $end = strpos($content, "\n---", 3);
            if ($end !== false) {
                $content = substr($content, $end + 4);
            }
        }
        if (preg_match('/^\s*#\s+(.+)$/m', $content, $matches) === 1) {
            return trim($matches[1]);
        }
        return null;
    }
}
