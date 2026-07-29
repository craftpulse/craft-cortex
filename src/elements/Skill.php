<?php

namespace craftpulse\herald\elements;

use Craft;
use craft\base\Element;
use craft\elements\actions\Delete;
use craft\elements\actions\Restore;
use craft\elements\User;
use craft\models\FieldLayout;
use craftpulse\herald\elements\db\SkillQuery;
use craftpulse\herald\Herald;
use craftpulse\herald\records\Skill as SkillRecord;
use yii\base\InvalidConfigException;

/**
 * =========================================================================
 * Herald custom-skill element.
 *
 * Author-able write-side counterpart to the bundled
 * `michtio/craftcms-claude-skills` corpus. Each element represents one
 * markdown skill addressable by handle (the natural key). When an
 * element's handle collides with a bundled skill, the element overrides
 * the bundled version in `SearchSkills`, `SkillResource::read()`, and
 * `SkillPrompt::render()` — see locked decisions 2 and 17 of the
 * Gate 8.6 plan.
 *
 * Element-class surface:
 *   - `hasTitles() = true` — the human label (maps to bundled `name`
 *     frontmatter field).
 *   - `hasStatuses() = false` — single-state, no draft/disabled.
 *   - `hasUris() = false` — no front-end URLs; surfaced over MCP only.
 *   - `isLocalized() = false` (default) — single canonical row per skill.
 *   - `trackChanges() = false` — drafts/revisions deferred to Gate 9.
 *   - Field layout comes from PC at `plugins.herald.skillFieldLayout`
 *     via `Skills::getFieldLayout()`. The default layout (seeded on
 *     first access) is one tab containing a single PlainText `body`
 *     field; the field layout is editable through the field-layout
 *     designer when Gate 9 ships the CP UI.
 *
 * Permission contract: `herald:manage-skills` is a single global
 * permission registered via Herald's `EVENT_REGISTER_PERMISSIONS`
 * listener. Admins always pass; non-admins pass when granted the
 * permission. There is no per-instance ACL.
 *
 * Cache invalidation: `afterSave`, `afterDelete`, and `afterRestore`
 * each call `Herald::getInstance()->skills->resetMemo()` so the
 * service-level `MemoizableArray` rebuild trigger fires on every
 * lifecycle event.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */
class Skill extends Element
{
    // Constants
    // =========================================================================

    /**
     * The global permission handle that gates create / update / delete
     * operations on Herald skills. Registered through
     * `UserPermissions::EVENT_REGISTER_PERMISSIONS` in `Herald::init()`.
     *
     * @since 5.0.0
     */
    public const PERMISSION_MANAGE = 'herald:manage-skills';

    /**
     * Slug-format constraint on the handle (the skill's natural key).
     * Lowercase letters, digits, and single internal hyphens only — the
     * handle is interpolated into `craft-skills://<handle>` resource
     * URIs and `name:` frontmatter, and must stay URI-safe and match the
     * bundled `michtio/craftcms-claude-skills` lowercase-dash convention
     * (e.g. `craft-php-guidelines`, `ddev`). Craft's `HandleValidator`
     * is deliberately NOT reused: it permits camelCase and underscores,
     * which would break URI addressability and the bundled-override
     * contract.
     *
     * @since 5.0.0
     */
    public const HANDLE_PATTERN = '/^[a-z0-9]+(-[a-z0-9]+)*$/';

    // Public Properties
    // =========================================================================

    /**
     * The skill's natural-key handle (slug). Globally unique across
     * element-stored skills; element-stored handles MAY collide with
     * bundled handles — that collision IS the override mechanism.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public ?string $handle = null;

    /**
     * Short human-readable description of the skill. Stored as a
     * native column on `herald_skills` (not in the field layout) so
     * the list-view path doesn't pay for a content-table join.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public ?string $description = null;

    // Static Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public static function displayName(): string
    {
        return Craft::t('herald', 'Skill');
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public static function lowerDisplayName(): string
    {
        return Craft::t('herald', 'skill');
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public static function pluralDisplayName(): string
    {
        return Craft::t('herald', 'Skills');
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public static function pluralLowerDisplayName(): string
    {
        return Craft::t('herald', 'skills');
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public static function refHandle(): ?string
    {
        return 'skill';
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public static function hasTitles(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public static function hasStatuses(): bool
    {
        return false;
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public static function hasUris(): bool
    {
        return false;
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public static function trackChanges(): bool
    {
        return false;
    }

    /**
     * @inheritdoc
     *
     * @return SkillQuery The newly created `SkillQuery` instance.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public static function find(): SkillQuery
    {
        return new SkillQuery(static::class);
    }

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function canView(User $user): bool
    {
        return $this->_userCanManage($user);
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function canSave(User $user): bool
    {
        return $this->_userCanManage($user);
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function canDuplicate(User $user): bool
    {
        return $this->_userCanManage($user);
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function canDelete(User $user): bool
    {
        return $this->_userCanManage($user);
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function getFieldLayout(): ?FieldLayout
    {
        return Herald::getInstance()->skills->getFieldLayout();
    }

    /**
     * @inheritdoc
     *
     * Writes the herald-specific record row (handle + description)
     * after the element row has been persisted. Skips when propagating
     * — multi-site propagation would duplicate the unique-handle row.
     *
     * Resets the service-level memoized corpus so subsequent
     * `Skills::getMergedCorpus()` calls rebuild against the newly-
     * persisted state.
     *
     * @throws InvalidConfigException If the underlying record cannot
     *                                be loaded.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function afterSave(bool $isNew): void
    {
        if (!$this->propagating) {
            if (!$isNew) {
                $record = SkillRecord::findOne($this->id);
                if (!$record) {
                    throw new InvalidConfigException("Invalid skill ID: {$this->id}");
                }
            } else {
                $record = new SkillRecord();
                $record->id = (int) $this->id;
            }

            $record->handle = (string) $this->handle;
            $record->description = $this->description;
            $record->save(false);
        }

        Herald::getInstance()->skills->resetMemo();

        parent::afterSave($isNew);
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function afterDelete(): void
    {
        Herald::getInstance()->skills->resetMemo();
        parent::afterDelete();
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function afterRestore(): void
    {
        Herald::getInstance()->skills->resetMemo();
        parent::afterRestore();
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * Placeholder source list for Gate 9's CP UI work. Returns one
     * `All skills` entry so the element index loop has something to
     * iterate during Craft's element-type discovery. No CP UI ships
     * in Gate 8.6 — these sources don't surface to editors yet.
     *
     * @return array<int,array<string,mixed>>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    protected static function defineSources(string $context): array
    {
        return [
            [
                'key' => '*',
                'label' => Craft::t('herald', 'All skills'),
                'criteria' => [],
            ],
        ];
    }

    /**
     * @inheritdoc
     *
     * Delete + Restore cover the lifecycle without requiring CP UI.
     * Delete is the canonical action; Restore wires the soft-delete
     * recovery path Craft's element machinery calls when the
     * element is recovered via the trashed-elements index. Other
     * actions land in Gate 9 alongside the index UI.
     *
     * @return array<int,string|array<string,mixed>>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    protected static function defineActions(string $source): array
    {
        return [
            Delete::class,
            Restore::class,
        ];
    }

    /**
     * @inheritdoc
     *
     * Adds the `handle` required + uniqueness rules and the
     * `description` length rule on top of the parent's validation set.
     * Handle uniqueness is also enforced at the DB layer by the
     * UNIQUE index on `herald_skills.handle` — the model rule runs
     * first so consumers get a Yii-shaped error envelope before
     * hitting the integrity-violation surface.
     *
     * @return array<int,mixed>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['handle'], 'required'];
        $rules[] = [['handle'], 'string', 'max' => 255];
        $rules[] = [
            ['handle'],
            'match',
            'pattern' => self::HANDLE_PATTERN,
            'message' => Craft::t('herald', 'Handle must be a lowercase slug: letters, digits, and single hyphens (e.g. “my-skill”).'),
        ];
        $rules[] = [['handle'], 'validateHandleImmutable'];
        $rules[] = [['handle'], 'validateHandleUnique'];
        $rules[] = [['description'], 'string', 'max' => 4096];
        return $rules;
    }

    /**
     * Validates that the handle is not already in use by another
     * skill element — including a soft-deleted (trashed) one. The DB
     * UNIQUE index on `herald_skills.handle` holds the trashed row, so
     * a default (`trashed=false`) probe would let a colliding-with-
     * trashed handle pass model validation and then explode with a raw
     * `IntegrityException` inside `afterSave()`, leaving a half-saved
     * element. Probing the trashed slot too makes the create fail
     * closed with a structured validation error pointing the operator
     * at restore-or-hard-delete — mirroring the UPDATE-path hint in
     * `tools\system\Skill::_resolveSkillElement()`.
     *
     * @param string $attribute The attribute under validation.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function validateHandleUnique(string $attribute): void
    {
        if ($this->handle === null || $this->handle === '') {
            return;
        }

        $query = static::find()
            ->status(null)
            ->site('*')
            ->handle($this->handle);

        if ($this->id !== null) {
            $query->andWhere(['not', ['elements.id' => $this->id]]);
        }

        if ($query->exists()) {
            $this->addError(
                $attribute,
                Craft::t('herald', 'Handle “{value}” is already in use by another skill.', [
                    'value' => $this->handle,
                ]),
            );
            return;
        }

        // The live slot is clear, but the DB UNIQUE index still holds any
        // soft-deleted row with this handle. Catch it here so the save
        // fails closed with a clean validation error instead of throwing
        // an IntegrityException mid-`afterSave()`.
        $trashedQuery = static::find()
            ->status(null)
            ->site('*')
            ->trashed(true)
            ->handle($this->handle);

        if ($this->id !== null) {
            $trashedQuery->andWhere(['not', ['elements.id' => $this->id]]);
        }

        if ($trashedQuery->exists()) {
            $this->addError(
                $attribute,
                Craft::t('herald', 'Handle “{value}” is in use by a trashed skill. Restore it via the Craft CP, or hard-delete the trashed skill (mode=delete with hardDelete=true) to free the handle.', [
                    'value' => $this->handle,
                ]),
            );
        }
    }

    /**
     * Enforces the natural-key invariant: a skill's handle is immutable
     * once the element exists. The handle is interpolated into
     * `craft-skills://<handle>` resource URIs and bundled-override keys,
     * so renaming it would orphan every cached reference. The `skill`
     * tool refuses handle changes at its own layer, but Craft's built-in
     * `elements/save` (the Gate 9.6 CP authoring path) bypasses that
     * check — enforcing it here means both paths inherit the guarantee.
     *
     * Skipped for brand-new elements (no persisted handle to compare
     * against) and when the value is unchanged.
     *
     * @param string $attribute The attribute under validation.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function validateHandleImmutable(string $attribute): void
    {
        if ($this->id === null) {
            return;
        }

        // No persisted record yet → this is the element's first save
        // (the canonical row hasn't been written), so there's no prior
        // handle to hold immutable.
        $record = SkillRecord::findOne($this->id);
        if ($record === null) {
            return;
        }

        if ($this->handle !== $record->handle) {
            $this->addError(
                $attribute,
                Craft::t('herald', 'Handle is immutable; “{old}” cannot be renamed to “{new}”. Create a new skill instead.', [
                    'old' => (string) $record->handle,
                    'new' => (string) $this->handle,
                ]),
            );
        }
    }

    // Private Methods
    // =========================================================================

    /**
     * Resolve whether the given user can manage Herald skills. Admins
     * always pass; non-admins pass when granted the
     * `herald:manage-skills` permission. Centralised here so
     * `canView/canSave/canDelete/canDuplicate` all share a single
     * implementation.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _userCanManage(User $user): bool
    {
        if ($user->admin) {
            return true;
        }
        return (bool) $user->can(self::PERMISSION_MANAGE);
    }
}
