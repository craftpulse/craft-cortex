<?php

namespace craftpulse\herald\tests\fixtures;

use Craft;
use craft\base\FieldInterface;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Address;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\GlobalSet;
use craft\elements\Tag;
use craft\elements\User;
use craft\enums\CmsEdition;
use craft\enums\PropagationMethod;
use craft\fieldlayoutelements\assets\AssetTitleField;
use craft\fieldlayoutelements\CustomField;
use craft\fieldlayoutelements\entries\EntryTitleField;
use craft\fields\Assets;
use craft\fields\Date;
use craft\fields\Dropdown;
use craft\fields\Number;
use craft\fields\PlainText;
use craft\fields\Table as TableField;
use craft\fs\Local;
use craft\helpers\FileHelper;
use craft\models\CategoryGroup;
use craft\models\CategoryGroup_SiteSettings;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use craft\models\TagGroup;
use craft\models\Volume;
use craftpulse\herald\Herald;
use DateTime;
use DateTimeZone;

// =============================================================================
// FixtureInstaller
// =============================================================================

/**
 * Installs the content fixtures Herald's test suite asserts against.
 *
 * Herald's suite boots a *surrounding* Craft install and introspects its live
 * API surface. Most tests assert on response shape, but a tier of them needs
 * named content to exist: a `heroes` section, a `minorHeroes` section with at
 * least 150 entries, a `heroImage` Assets field, a `factions` category group,
 * a user called `nfury`, and so on. Historically that content came from the
 * dev playground's Marvel seed, which lives in a different repository, so on
 * a bare CI install 168 tests skipped themselves. This class is Herald's own
 * copy of that contract.
 *
 * ## Invocation
 *
 * Never automatic. Run it explicitly, from the plugin root, with
 * `HERALD_TEST_CRAFT_BASE` pointing at the Craft install to fixture:
 *
 *     HERALD_TEST_CRAFT_BASE=/var/www/html/cms composer test:fixtures
 *     HERALD_TEST_CRAFT_BASE=/var/www/html/cms php tests/fixtures/install.php --report
 *
 * An implicit hook in `tests/Bootstrap.php` would write sections, fields and
 * a volume into whatever install the tests happen to point at, silently, as
 * a side effect of running tests. A plugin console command would ship
 * test-only schema-writing code into every production install. Both were
 * rejected.
 *
 * ## Anti-clobber contract
 *
 * The installer NEVER re-saves, modifies, or deletes an entity that already
 * exists, and NEVER creates a site. Every `_ensure*()` helper returns the
 * existing entity untouched. This is the single most important safety
 * property: the dev playground already carries the Marvel seed, whose `bio`
 * field is a `craft\ckeditor\Field` and whose entries carry real authored
 * content. Running this installer there must leave `config/project/`
 * byte-identical and every row untouched.
 *
 * Two consequences worth stating:
 *
 *   - Entry counts are topped up, never truncated. Missing
 *     `Minor Hero %04d` indices in `1..150` are filled in place rather than
 *     appended at `151+`, because title order is load-bearing for the
 *     suite's `_herald_bulk_first_ids()` helper.
 *   - Assets are only attached to `heroes` entries the fixtures authored,
 *     recognised by the `FIXTURE_MARKER` tagline suffix. An unrelated asset
 *     would be hard-deleted mid-suite by `content_audit`'s
 *     `prune_unused_assets` mode, after which `AssetsTest`'s guard would
 *     start firing non-deterministically, so the relation is mandatory; and
 *     attaching one to an entry somebody else authored would be a
 *     modification, so on such an install the step declines and reports the
 *     gap instead.
 *
 * ## Manifest — the stated contract
 *
 * Handles marked *(named)* are referenced by name in test source. The rest
 * exist for parity with the playground's Marvel seed, so the two datasets
 * cannot diverge in a way that only shows up on one runner.
 *
 * **Sites** — none. `AuditFixModesTest` asserts `repair_propagation` finds
 * zero propagation gaps, which only holds on a single-site install.
 *
 * **Filesystem** — `localImages` (`craft\fs\Local`, `@webroot/uploads`).
 *
 * **Volume** — `images` *(named: `getAllVolumes()[0]`)*.
 *
 * **Fields** (core types only)
 *
 * | Handle             | Type               | Notes                          |
 * | ------------------ | ------------------ | ------------------------------ |
 * | `heroImage`        | Assets             | *(named)* max 1, images only   |
 * | `minorBio`         | PlainText multi    | *(named)* must be non-relational |
 * | `tagline`          | PlainText          | charLimit 200                  |
 * | `alignment`        | Dropdown           | 6 options                      |
 * | `powerLevel`       | Number             | 1 to 100, default 50           |
 * | `firstAppearance`  | Date               | date only                      |
 * | `bio`              | PlainText multi    | **divergence** (see below)     |
 * | `superpowers`      | Table              | name + description columns     |
 * | `teamLogo`         | Assets             | max 1, images only             |
 * | `tier`             | Dropdown           | c / d / e                      |
 * | `directorName`     | PlainText          | global set                     |
 * | `threatLevel`      | Dropdown           | green / yellow / red / code-red |
 * | `currentDirective` | PlainText multi    | global set                     |
 *
 * **Entry types** — `hero` *(named)*, `minorHero` *(named)*, `team`, `about`.
 * `hero` mirrors the seed's required flags on `tagline`, `alignment` and
 * `powerLevel` so a future divergence surfaces as a real signal instead of a
 * masked one.
 *
 * **Sections** — `heroes` *(named)* channel with URLs; `minorHeroes`
 * *(named)* channel without URLs; `teams` structure (maxLevels 3, the
 * documented fallback when `heroes` is absent); `about` single.
 *
 * **Entries** — 150 `minorHeroes` (`Minor Hero %04d`, hard floor `>= 150`),
 * 16 `heroes`, 8 `teams` (3 nested), 1 `about`. Titles and slugs mirror the
 * playground's exactly: the top-up is keyed on slug, so a substituted slug
 * would make the installer add an entry to an install that is already
 * complete.
 *
 * **Category groups** — `factions` *(named)* + 8 categories, `affiliations`
 * + 6, `powers` + 7. All `maxLevels: 3`, because `CategoryTest` needs two
 * distinct child-accepting groups. Two groups is a hard floor.
 *
 * **Tag group** — `infinityStones` + 6 tags.
 *
 * **Global set** — `shieldDirective`, populated. `saveSet()` persists the
 * schema; element-row field values are a separate `saveElement()` call.
 *
 * **Assets** — 2 real PNGs in `@webroot/uploads`, indexed against the
 * `images` volume root folder, each attached to a fixture-created `heroes`
 * entry through `heroImage`.
 *
 * **Users** — `nfury` *(named by username, must be search-indexed)* with one
 * Address, plus `herald_fixture_activity_viewer`: a non-admin holding
 * `herald:view-activity`. The activity viewer is the one entity the Marvel
 * seed has no reason to carry; the playground gains a matching content
 * migration so "zero skips" means the same thing on both runners.
 *
 * ## Deliberate divergence from the playground
 *
 * `bio` is a multiline PlainText here and a `craft\ckeditor\Field` in the
 * playground. Herald must not take a craft-ckeditor dependency, and no test
 * references `bio` by name. On the playground the installer leaves the
 * CKEditor field alone.
 *
 * @author Craftpulse
 * @since  5.0.0
 */
class FixtureInstaller
{
    // =========================================================================
    // Const Properties
    // =========================================================================

    /**
     * Username of the non-admin user granted `herald:view-activity`.
     */
    public const ACTIVITY_VIEWER_USERNAME = 'herald_fixture_activity_viewer';

    /**
     * Number of `minorHeroes` entries the suite's exact-count assertions
     * depend on. `BulkEntriesTest` asserts `total >= 150`.
     */
    public const MINOR_HEROES_COUNT = 150;

    /**
     * Number of assets the fixtures index into the `images` volume. Both are
     * attached to a `heroes` entry, so `prune_unused_assets` leaves them be.
     */
    private const ASSET_COUNT = 2;

    /**
     * Category group handles, mapped to the number of categories each carries.
     */
    private const CATEGORY_GROUPS = [
        'factions' => 8,
        'affiliations' => 6,
        'powers' => 7,
    ];

    /**
     * Entry type handles in the manifest.
     */
    private const ENTRY_TYPE_HANDLES = ['hero', 'team', 'about', 'minorHero'];

    /**
     * Every field handle in the manifest, in creation order.
     */
    private const FIELD_HANDLES = [
        'tagline',
        'alignment',
        'powerLevel',
        'firstAppearance',
        'heroImage',
        'bio',
        'superpowers',
        'teamLogo',
        'minorBio',
        'tier',
        'directorName',
        'threatLevel',
        'currentDirective',
    ];

    /**
     * Marker every fixture-authored `heroes` tagline ends with.
     *
     * This is the installer's ownership proof for entries: an entry carrying
     * it was written by these fixtures and may be added to, one that isn't
     * belongs to whoever authored it and is never touched. The playground's
     * heroes carry real prose, so they never match.
     */
    private const FIXTURE_MARKER = 'Fixture record, not authored copy.';

    /**
     * Handle of the filesystem backing the `images` volume.
     */
    private const FS_HANDLE = 'localImages';

    /**
     * Username of the S.H.I.E.L.D. director the `users` tool tests target.
     */
    private const FURY_USERNAME = 'nfury';

    /**
     * Handle of the global set.
     */
    private const GLOBAL_SET = 'shieldDirective';

    /**
     * A 1x1 transparent PNG. Real bytes on disk, not a metadata-only row:
     * `content_audit`'s `prune_unused_assets` mode hard-deletes, and Craft's
     * `afterDelete` reaches through to the filesystem.
     */
    private const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/'
        . '58BAAX+Av6y1jJ/AAAAAElFTkSuQmCC';

    /**
     * Section shapes, mirroring the playground's Marvel seed. A `null`
     * `uriFormat` means the section has no URLs.
     *
     * @var array<string, array{
     *     name: string,
     *     type: string,
     *     entryType: string,
     *     maxLevels: int|null,
     *     uriFormat: string|null,
     *     template: string|null,
     * }>
     */
    private const SECTION_DEFINITIONS = [
        'heroes' => [
            'name' => 'Heroes',
            'type' => Section::TYPE_CHANNEL,
            'entryType' => 'hero',
            'maxLevels' => null,
            'uriFormat' => 'heroes/{slug}',
            'template' => 'heroes/_entry',
        ],
        'minorHeroes' => [
            'name' => 'Minor Heroes',
            'type' => Section::TYPE_CHANNEL,
            'entryType' => 'minorHero',
            'maxLevels' => null,
            'uriFormat' => null,
            'template' => null,
        ],
        'teams' => [
            'name' => 'Teams',
            'type' => Section::TYPE_STRUCTURE,
            'entryType' => 'team',
            'maxLevels' => 3,
            'uriFormat' => 'teams/{slug}',
            'template' => 'teams/_entry',
        ],
        'about' => [
            'name' => 'About',
            'type' => Section::TYPE_SINGLE,
            'entryType' => 'about',
            'maxLevels' => null,
            'uriFormat' => 'about',
            'template' => 'about',
        ],
    ];

    /**
     * Section handles, mapped to the number of entries each carries.
     */
    private const SECTION_ENTRY_COUNTS = [
        'heroes' => 16,
        'minorHeroes' => self::MINOR_HEROES_COUNT,
        'teams' => 8,
        'about' => 1,
    ];

    /**
     * Number of tags in the tag group.
     */
    private const TAG_COUNT = 6;

    /**
     * Handle of the tag group.
     */
    private const TAG_GROUP = 'infinityStones';

    /**
     * Handle of the asset volume.
     */
    private const VOLUME_HANDLE = 'images';

    // =========================================================================
    // Private Properties
    // =========================================================================

    /**
     * Progress sink. `null` keeps the installer silent, which is what
     * `report()`-only callers want.
     *
     * @var \Closure(string): void|null
     */
    private ?\Closure $_output;

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * @param \Closure(string): void|null $output Progress sink, one line at a time.
     *
     * @author Craftpulse
     */
    public function __construct(?\Closure $output = null)
    {
        $this->_output = $output;
    }

    /**
     * Install everything the report reports as missing.
     *
     * Idempotent and additive: existing entities are returned untouched,
     * entry counts are topped up rather than truncated, and nothing is ever
     * deleted.
     *
     * @throws \RuntimeException if preflight refuses, or if Craft rejects a save.
     * @throws \Throwable from the underlying Craft service calls.
     *
     * @author Craftpulse
     */
    public function install(): void
    {
        $preflight = $this->preflight();

        if ($preflight['errors'] !== []) {
            throw new \RuntimeException(
                "Preflight refused:\n  - " . implode("\n  - ", $preflight['errors']),
            );
        }

        $fs = $this->_ensureFilesystem();
        $volume = $this->_ensureVolume($fs);
        $fields = $this->_ensureFields($volume);
        $entryTypes = $this->_ensureEntryTypes($fields);
        $aboutExisted = $this->_entryCount('about') > 0;
        $sections = $this->_ensureSections($entryTypes);
        $categoryGroups = $this->_ensureCategoryGroups();
        $tagGroup = $this->_ensureTagGroup();
        $globalSet = $this->_ensureGlobalSet($fields);
        $this->_flushProjectConfig();

        $this->_ensureMinorHeroes($sections['minorHeroes'], $entryTypes['minorHero']);
        $createdHeroes = $this->_ensureHeroes($sections['heroes'], $entryTypes['hero']);
        $this->_ensureTeams($sections['teams'], $entryTypes['team']);

        if (!$aboutExisted) {
            $this->_populateAbout($sections['about']);
        }

        $this->_ensureCategories($categoryGroups);
        $this->_ensureTags($tagGroup);
        $this->_populateGlobalSet($globalSet);
        $this->_ensureAssets($volume, $sections['heroes'], $createdHeroes);

        $this->_ensureFury();
        $this->_ensureActivityViewer();

        $this->_flushProjectConfig();
    }

    /**
     * Whether every invariant the suite depends on is already satisfied.
     *
     * @author Craftpulse
     */
    public function isSatisfied(): bool
    {
        return $this->report()['satisfied'];
    }

    /**
     * Refusals and warnings, evaluated before anything is written.
     *
     * Each refusal names the fix. Multi-site is a warning rather than a
     * refusal: the installer never creates or touches a site, so it can
     * still do its job, but `AuditFixModesTest` will fail on an install
     * that already has more than one site.
     *
     * @return array{errors: list<string>, warnings: list<string>}
     *
     * @author Craftpulse
     */
    public function preflight(): array
    {
        $errors = [];
        $warnings = [];

        if (!Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            $errors[] = 'allowAdminChanges is off, so project-config writes (fields, sections, volumes) '
                . 'would be rejected. Set CRAFT_ALLOW_ADMIN_CHANGES=true and re-run.';
        }

        if (Craft::$app->getEdition() === CmsEdition::Solo) {
            $errors[] = 'Craft is on the Solo edition, which permits exactly one user account, so the '
                . 'nfury and activity-viewer users cannot be created. Run: '
                . 'php craft exec \'Craft::$app->setEdition(\craft\enums\CmsEdition::Pro)\'';
        }

        if (Craft::$app->getIsMultiSite()) {
            $warnings[] = 'This install is multi-site. The fixtures never create or touch a site, but '
                . 'AuditFixModesTest asserts repair_propagation finds zero propagation gaps, which only '
                . 'holds on a single-site install. Expect that test to fail here.';
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * Evaluate every invariant the suite depends on, individually. No writes.
     *
     * Individually, deliberately: project config survives a database-only
     * reset, so a single "does `heroes` exist" gate would report a fixtured
     * install against an empty entries table.
     *
     * @return array{
     *     satisfied: bool,
     *     checks: list<array{key: string, label: string, satisfied: bool, detail: string}>,
     *     missing: list<string>,
     * }
     *
     * @author Craftpulse
     */
    public function report(): array
    {
        $checks = [
            ...$this->_schemaChecks(),
            ...$this->_contentChecks(),
            ...$this->_userChecks(),
        ];

        $missing = [];

        foreach ($checks as $check) {
            if (!$check['satisfied']) {
                $missing[] = $check['key'];
            }
        }

        return [
            'satisfied' => $missing === [],
            'checks' => $checks,
            'missing' => $missing,
        ];
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * Ids to rotate `minorHeroes` authors across. Whatever active users the
     * install carries, capped at five; a bare install has only the bootstrap
     * admin, which is fine.
     *
     * @return list<int>
     *
     * @author Craftpulse
     */
    private function _authorIds(): array
    {
        $ids = User::find()
            ->status(User::STATUS_ACTIVE)
            ->limit(5)
            ->ids();

        return array_values(array_map(static fn(mixed $id): int => (int) $id, $ids));
    }

    /**
     * Category titles per group, mirroring the playground's seed.
     *
     * @return array<string, list<string>>
     *
     * @author Craftpulse
     */
    private function _categoryTitles(): array
    {
        return [
            'factions' => [
                'S.H.I.E.L.D.', 'Hydra', 'Kree Empire', 'Skrull Resistance',
                'Avengers Initiative', 'X-Men', 'Inhumans', 'Wakandan Border Tribe',
            ],
            'affiliations' => [
                'Wakanda', 'Asgard', 'Sakaar', 'Knowhere', 'Sokovia (former)',
                'Titan (debris)',
            ],
            'powers' => [
                'Cosmic', 'Mystic', 'Mutant', 'Tech', 'Super-Soldier',
                'Gamma-Enhanced', 'Spider-Bitten',
            ],
        ];
    }

    /**
     * Build one report row.
     *
     * @return array{key: string, label: string, satisfied: bool, detail: string}
     *
     * @author Craftpulse
     */
    private function _check(string $key, string $label, bool $satisfied, string $detail): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'satisfied' => $satisfied,
            'detail' => $detail,
        ];
    }

    /**
     * Content invariants: entry, category, tag and asset counts, plus the
     * global set's element-row values.
     *
     * @return list<array{key: string, label: string, satisfied: bool, detail: string}>
     *
     * @author Craftpulse
     */
    private function _contentChecks(): array
    {
        $rows = [];

        foreach (self::SECTION_ENTRY_COUNTS as $handle => $expected) {
            $count = $this->_entryCount($handle);
            $rows[] = $this->_check(
                "entries.$handle",
                "Entries in `$handle`",
                $count >= $expected,
                "$count of $expected",
            );
        }

        foreach (self::CATEGORY_GROUPS as $handle => $expected) {
            $group = Craft::$app->getCategories()->getGroupByHandle($handle);
            $count = $group !== null
                ? (int) Category::find()->groupId($group->id)->status(null)->count()
                : 0;
            $rows[] = $this->_check(
                "categories.$handle",
                "Categories in `$handle`",
                $count >= $expected,
                "$count of $expected",
            );
        }

        $tagGroup = Craft::$app->getTags()->getTagGroupByHandle(self::TAG_GROUP);
        $tagCount = $tagGroup !== null
            ? (int) Tag::find()->groupId($tagGroup->id)->status(null)->count()
            : 0;
        $rows[] = $this->_check(
            'tags.' . self::TAG_GROUP,
            'Tags in `' . self::TAG_GROUP . '`',
            $tagCount >= self::TAG_COUNT,
            "$tagCount of " . self::TAG_COUNT,
        );

        $globalSet = Craft::$app->getGlobals()->getSetByHandle(self::GLOBAL_SET);
        $directorName = $globalSet !== null && $globalSet->getFieldLayout()->getFieldByHandle('directorName') !== null
            ? $globalSet->getFieldValue('directorName')
            : null;
        $rows[] = $this->_check(
            'globalSetValues.' . self::GLOBAL_SET,
            'Global set `' . self::GLOBAL_SET . '` populated',
            is_string($directorName) && $directorName !== '',
            is_string($directorName) && $directorName !== '' ? 'populated' : 'empty',
        );

        $assetCount = (int) Asset::find()->status(null)->count();
        $rows[] = $this->_check(
            'assets.count',
            'Indexed assets',
            $assetCount >= self::ASSET_COUNT,
            "$assetCount of " . self::ASSET_COUNT,
        );

        $relatedAssets = $this->_relatedAssetCount();
        $rows[] = $this->_check(
            'assets.related',
            'Assets referenced through `heroImage`',
            $relatedAssets >= self::ASSET_COUNT,
            "$relatedAssets of " . self::ASSET_COUNT,
        );

        return $rows;
    }

    /**
     * Ensure a non-admin user holding `herald:view-activity`.
     *
     * The one entity the playground's Marvel seed has no reason to carry:
     * `CpNavItemTest` needs a non-admin who can see the Activity subnav but
     * nothing else. Any non-admin already holding the permission satisfies
     * the invariant, so this only ever adds an account when none does.
     *
     * Herald registers the permission unconditionally, not behind an edition
     * gate, so it survives Craft's orphaned-permission filter on Free too.
     *
     * @throws \RuntimeException if Craft rejects the save.
     *
     * @author Craftpulse
     */
    private function _ensureActivityViewer(): void
    {
        $existing = User::find()
            ->admin(false)
            ->status(null)
            ->collect()
            ->first(fn(User $user): bool => $user->can(Herald::PERMISSION_VIEW_ACTIVITY));

        if ($existing instanceof User) {
            return;
        }

        $user = Craft::$app->getUsers()->getUserByUsernameOrEmail(self::ACTIVITY_VIEWER_USERNAME);

        if ($user === null) {
            $user = new User();
            $user->username = self::ACTIVITY_VIEWER_USERNAME;
            $user->email = 'activity-viewer@herald.test';
            $user->firstName = 'Herald';
            $user->lastName = 'Activity Viewer';
            $user->admin = false;
            $user->active = true;
            $user->pending = false;

            if (!Craft::$app->getElements()->saveElement($user)) {
                throw new \RuntimeException(
                    'Failed to save the activity viewer: ' . json_encode($user->getErrors()),
                );
            }

            $this->_say('created user `' . self::ACTIVITY_VIEWER_USERNAME . '`');
        }

        Craft::$app->getUserPermissions()->saveUserPermissions(
            (int) $user->id,
            [Herald::PERMISSION_VIEW_ACTIVITY],
        );

        $this->_say('granted `' . Herald::PERMISSION_VIEW_ACTIVITY . '` to `' . self::ACTIVITY_VIEWER_USERNAME . '`');
    }

    /**
     * Ensure the two assets, each attached to a `heroes` entry.
     *
     * Only the entries this run created are eligible targets, so on an
     * install that already has `heroes` entries the step declines: attaching
     * a relation to someone else's entry is a modification. Real PNG bytes
     * are written rather than metadata-only rows, because `content_audit`'s
     * `prune_unused_assets` mode hard-deletes, and Craft's `afterDelete`
     * reaches through to the filesystem.
     *
     * @param list<Entry> $createdHeroes
     *
     * @throws \RuntimeException if Craft rejects a save.
     * @throws \yii\base\Exception if the PNG can't be written.
     * @throws \craft\errors\InvalidFieldException if `tagline` leaves the hero layout.
     *
     * @author Craftpulse
     */
    private function _ensureAssets(Volume $volume, Section $heroes, array $createdHeroes): void
    {
        if ((int) Asset::find()->status(null)->count() >= self::ASSET_COUNT) {
            return;
        }

        // Entries created earlier in this same run, plus any left behind by a
        // previous run that got this far and then failed. Both are provably
        // fixture-owned, so attaching a relation to them is completing our
        // own write rather than modifying someone else's entry.
        $targets = $createdHeroes;

        if (count($targets) < self::ASSET_COUNT) {
            $seen = array_map(static fn(Entry $entry): int => (int) $entry->id, $targets);

            foreach ($this->_fixtureOwnedHeroes($heroes) as $hero) {
                if (!in_array((int) $hero->id, $seen, true)) {
                    $targets[] = $hero;
                }
            }
        }

        if (count($targets) < self::ASSET_COUNT) {
            $this->_say(sprintf(
                'skipped the asset fixtures: they need %d fixture-authored `heroes` entries to hang a '
                . '`heroImage` relation off, and an unreferenced asset would be hard-deleted mid-suite '
                . 'by `content_audit`. This install\'s `heroes` entries were authored elsewhere, so '
                . 'seed the assets from its own content migration instead.',
                self::ASSET_COUNT,
            ));

            return;
        }

        $elements = Craft::$app->getElements();
        $folder = Craft::$app->getAssets()->getRootFolderByVolumeId((int) $volume->id);

        if ($folder === null) {
            throw new \RuntimeException("Volume `{$volume->handle}` has no root folder.");
        }

        $uploads = Craft::getAlias('@webroot/uploads');

        if (!is_string($uploads)) {
            throw new \RuntimeException('Could not resolve @webroot/uploads.');
        }

        $bytes = base64_decode(self::PNG_BASE64, true);

        if ($bytes === false) {
            throw new \RuntimeException('The bundled fixture PNG is not valid base64.');
        }

        for ($i = 0; $i < self::ASSET_COUNT; $i++) {
            $filename = sprintf('herald-fixture-%d.png', $i + 1);
            FileHelper::writeToFile($uploads . DIRECTORY_SEPARATOR . $filename, $bytes);

            $asset = new Asset();
            $asset->volumeId = (int) $volume->id;
            $asset->folderId = (int) $folder->id;
            $asset->filename = $filename;
            $asset->title = sprintf('Herald fixture image %d', $i + 1);
            $asset->kind = Asset::KIND_IMAGE;
            $asset->size = strlen($bytes);
            $asset->width = 1;
            $asset->height = 1;
            $asset->setScenario(Asset::SCENARIO_INDEX);

            if (!$elements->saveElement($asset, runValidation: false)) {
                throw new \RuntimeException(
                    "Failed to index asset `$filename`: " . json_encode($asset->getErrors()),
                );
            }

            $hero = $targets[$i];
            $hero->setFieldValue('heroImage', [$asset->id]);

            if (!$elements->saveElement($hero)) {
                throw new \RuntimeException(
                    "Failed to attach `$filename` to `{$hero->title}`: " . json_encode($hero->getErrors()),
                );
            }

            $this->_say("indexed `$filename` and attached it to `{$hero->title}`");
        }
    }

    /**
     * Ensure the categories in every group. Keyed on title, matching the
     * playground's own seed, so an install that already has them is left
     * alone.
     *
     * @param array<string, CategoryGroup> $groups
     *
     * @throws \RuntimeException if Craft rejects a save.
     *
     * @author Craftpulse
     */
    private function _ensureCategories(array $groups): void
    {
        $elements = Craft::$app->getElements();

        foreach ($this->_categoryTitles() as $handle => $titles) {
            $group = $groups[$handle];
            $created = 0;

            foreach ($titles as $title) {
                $exists = Category::find()
                    ->groupId($group->id)
                    ->title($title)
                    ->status(null)
                    ->exists();

                if ($exists) {
                    continue;
                }

                $category = new Category();
                $category->groupId = (int) $group->id;
                $category->title = $title;

                if (!$elements->saveElement($category)) {
                    throw new \RuntimeException(
                        "Failed to save category `$title` in `$handle`: " . json_encode($category->getErrors()),
                    );
                }

                $created++;
            }

            if ($created > 0) {
                $this->_say("created $created categories in `$handle`");
            }
        }
    }

    /**
     * Ensure the three category groups, returning them keyed by handle.
     *
     * All three accept children (`maxLevels: 3`) because `CategoryTest`
     * needs two distinct child-accepting groups to exercise the cross-group
     * parent rejection.
     *
     * @return array<string, CategoryGroup>
     *
     * @throws \RuntimeException if Craft rejects a save.
     *
     * @author Craftpulse
     */
    private function _ensureCategoryGroups(): array
    {
        $service = Craft::$app->getCategories();
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;
        $out = [];

        foreach (array_keys(self::CATEGORY_GROUPS) as $handle) {
            $existing = $service->getGroupByHandle($handle);

            if ($existing !== null) {
                $out[$handle] = $existing;
                continue;
            }

            $group = new CategoryGroup([
                'name' => ucfirst($handle),
                'handle' => $handle,
                'maxLevels' => 3,
                'fieldLayout' => new FieldLayout(['type' => Category::class]),
                'siteSettings' => [
                    new CategoryGroup_SiteSettings([
                        'siteId' => $siteId,
                        'hasUrls' => false,
                    ]),
                ],
            ]);

            if (!$service->saveGroup($group)) {
                throw new \RuntimeException(
                    "Failed to save category group `$handle`: " . json_encode($group->getErrors()),
                );
            }

            $this->_say("created category group `$handle`");
            $out[$handle] = $group;
        }

        return $out;
    }

    /**
     * Ensure the four entry types, returning them keyed by handle.
     *
     * An existing entry type is returned untouched: overwriting the
     * playground's `hero` layout would swap its CKEditor `bio` for the
     * core-field version, which is exactly the clobber this installer
     * exists to avoid.
     *
     * @param array<string, FieldInterface> $fields
     * @return array<string, EntryType>
     *
     * @throws \RuntimeException if Craft rejects a save.
     *
     * @author Craftpulse
     */
    private function _ensureEntryTypes(array $fields): array
    {
        $service = Craft::$app->getEntries();
        $out = [];

        foreach (self::ENTRY_TYPE_HANDLES as $handle) {
            $existing = $service->getEntryTypeByHandle($handle);

            if ($existing !== null) {
                $out[$handle] = $existing;
                continue;
            }

            $entryType = $this->_newEntryType($handle, $fields);

            if (!$service->saveEntryType($entryType)) {
                throw new \RuntimeException(
                    "Failed to save entry type `$handle`: " . json_encode($entryType->getErrors()),
                );
            }

            $this->_say("created entry type `$handle`");
            $out[$handle] = $entryType;
        }

        return $out;
    }

    /**
     * Ensure every manifest field exists, returning them keyed by handle.
     *
     * An existing field is returned as-is, whatever its type: the
     * playground's `bio` is a CKEditor field and must stay one.
     *
     * @return array<string, FieldInterface>
     *
     * @throws \RuntimeException if Craft rejects a save.
     *
     * @author Craftpulse
     */
    private function _ensureFields(Volume $volume): array
    {
        $service = Craft::$app->getFields();
        $out = [];

        foreach (self::FIELD_HANDLES as $handle) {
            $existing = $service->getFieldByHandle($handle);

            if ($existing !== null) {
                $out[$handle] = $existing;
                continue;
            }

            $field = $this->_newField($handle, $volume);

            if (!$service->saveField($field)) {
                throw new \RuntimeException(
                    "Failed to save field `$handle`: " . json_encode($field->getErrors()),
                );
            }

            $this->_say("created field `$handle`");
            $out[$handle] = $field;
        }

        return $out;
    }

    /**
     * Ensure the `localImages` filesystem, and the directory it points at.
     *
     * CI's starter project has no `web/uploads`, and `Local::getFileList()`
     * throws on a missing root, so the directory is created too.
     *
     * @throws \RuntimeException if Craft rejects the save.
     * @throws \yii\base\Exception if the uploads directory can't be created.
     *
     * @author Craftpulse
     */
    private function _ensureFilesystem(): Local
    {
        $uploads = Craft::getAlias('@webroot/uploads');

        if (is_string($uploads) && !is_dir($uploads)) {
            FileHelper::createDirectory($uploads);
            $this->_say("created $uploads");
        }

        $service = Craft::$app->getFs();
        $existing = $service->getFilesystemByHandle(self::FS_HANDLE);

        if ($existing instanceof Local) {
            return $existing;
        }

        if ($existing !== null) {
            throw new \RuntimeException(sprintf(
                'Filesystem `%s` exists but is a %s, not a local filesystem.',
                self::FS_HANDLE,
                $existing::class,
            ));
        }

        $fs = new Local([
            'name' => 'Local images',
            'handle' => self::FS_HANDLE,
            'hasUrls' => true,
            'url' => '@web/uploads',
            'path' => '@webroot/uploads',
        ]);

        if (!$service->saveFilesystem($fs)) {
            throw new \RuntimeException(
                'Failed to save filesystem `' . self::FS_HANDLE . '`: ' . json_encode($fs->getErrors()),
            );
        }

        $this->_say('created filesystem `' . self::FS_HANDLE . '`');

        return $fs;
    }

    /**
     * Ensure the `nfury` user and one address on him.
     *
     * Saved with search indexing left on: the `users` tool's `search` mode
     * goes through `UserQuery::search()`, so the row has to be in the search
     * index, and Craft indexes inline in console requests, which is what this
     * installer runs as.
     *
     * @throws \RuntimeException if Craft rejects a save.
     *
     * @author Craftpulse
     */
    private function _ensureFury(): void
    {
        $elements = Craft::$app->getElements();
        $fury = Craft::$app->getUsers()->getUserByUsernameOrEmail(self::FURY_USERNAME);

        if ($fury === null) {
            $fury = new User();
            $fury->username = self::FURY_USERNAME;
            $fury->email = 'nick.fury@shield.gov';
            $fury->firstName = 'Nicholas';
            $fury->lastName = 'Fury';
            $fury->active = true;
            $fury->pending = false;

            if (!$elements->saveElement($fury)) {
                throw new \RuntimeException(
                    'Failed to save `' . self::FURY_USERNAME . '`: ' . json_encode($fury->getErrors()),
                );
            }

            $this->_say('created user `' . self::FURY_USERNAME . '`');
        }

        if (Address::find()->ownerId($fury->id)->status(null)->exists()) {
            return;
        }

        $address = new Address();
        $address->title = 'S.H.I.E.L.D. HQ';
        $address->countryCode = 'US';
        $address->administrativeArea = 'NY';
        $address->locality = 'New York';
        $address->postalCode = '10018';
        $address->addressLine1 = '1407 Broadway';
        $address->organization = 'S.H.I.E.L.D.';
        $address->firstName = 'Nicholas';
        $address->lastName = 'Fury';
        $address->setPrimaryOwner($fury);
        $address->setOwner($fury);

        if (!$elements->saveElement($address)) {
            throw new \RuntimeException(
                'Failed to save the S.H.I.E.L.D. HQ address: ' . json_encode($address->getErrors()),
            );
        }

        $this->_say('created an address on `' . self::FURY_USERNAME . '`');
    }

    /**
     * Ensure the `shieldDirective` global set (schema only).
     *
     * `saveSet()` writes the schema to project config; the element row's
     * field values are a separate `saveElement()` in `_ensureGlobalValues()`.
     *
     * @param array<string, FieldInterface> $fields
     *
     * @throws \RuntimeException if Craft rejects the save.
     *
     * @author Craftpulse
     */
    private function _ensureGlobalSet(array $fields): GlobalSet
    {
        $service = Craft::$app->getGlobals();
        $existing = $service->getSetByHandle(self::GLOBAL_SET);

        if ($existing !== null) {
            return $existing;
        }

        $set = new GlobalSet();
        $set->name = 'S.H.I.E.L.D. Directive';
        $set->handle = self::GLOBAL_SET;

        $layout = new FieldLayout(['type' => GlobalSet::class]);
        $tab = new FieldLayoutTab(['name' => 'Directive', 'layout' => $layout]);
        $tab->setElements([
            new CustomField($fields['directorName'], ['width' => 50]),
            new CustomField($fields['threatLevel'], ['width' => 50]),
            new CustomField($fields['currentDirective'], ['width' => 100]),
        ]);
        $layout->setTabs([$tab]);
        $set->setFieldLayout($layout);

        if (!$service->saveSet($set)) {
            throw new \RuntimeException(
                'Failed to save global set `' . self::GLOBAL_SET . '`: ' . json_encode($set->getErrors()),
            );
        }

        $this->_say('created global set `' . self::GLOBAL_SET . '`');

        // Re-read rather than returning the instance we just handed to
        // `saveSet()`. That call persists the SCHEMA through project config,
        // and the element row it creates on the way is a different object:
        // setting field values on ours would save happily and land nowhere,
        // which is invisible until a later process reads the set back.
        return $service->getSetByHandle(self::GLOBAL_SET) ?? $set;
    }

    /**
     * Ensure the 16 `heroes` entries, returning only the ones this run
     * created.
     *
     * The return value matters: the asset step attaches a `heroImage`
     * relation, and it may only do that to an entry the fixtures created.
     * Attaching one to a pre-existing entry would be a modification.
     *
     * @return list<Entry>
     *
     * @throws \RuntimeException if Craft rejects a save.
     * @throws \Exception from the DateTime arithmetic.
     *
     * @author Craftpulse
     */
    private function _ensureHeroes(Section $section, EntryType $entryType): array
    {
        $elements = Craft::$app->getElements();
        $existingSlugs = $this->_existingSlugs($section);
        $created = [];
        $postDate = new DateTime('now', new DateTimeZone('UTC'));
        $postDate->modify('-30 days');

        foreach ($this->_heroRoster() as $data) {
            if (isset($existingSlugs[$data['slug']])) {
                continue;
            }

            $entry = new Entry([
                'sectionId' => $section->id,
                'typeId' => $entryType->id,
            ]);
            $entry->title = $data['title'];
            $entry->slug = $data['slug'];
            $entry->postDate = $postDate;
            $entry->setFieldValues([
                'tagline' => $data['tagline'],
                'alignment' => $data['alignment'],
                'powerLevel' => $data['powerLevel'],
                'firstAppearance' => $data['firstAppearance'],
                'bio' => $data['bio'],
                'superpowers' => [
                    ['name' => $data['power'], 'description' => $data['tagline']],
                ],
            ]);

            if (!$elements->saveElement($entry)) {
                throw new \RuntimeException(
                    "Failed to save hero `{$data['title']}`: " . json_encode($entry->getErrors()),
                );
            }

            $created[] = $entry;
        }

        if ($created !== []) {
            $this->_say(sprintf('created %d `heroes` entries', count($created)));
        }

        return $created;
    }

    /**
     * Ensure 150 `minorHeroes` entries.
     *
     * Tops up missing `Minor Hero %04d` indices in place rather than
     * appending at `151+`: the suite's `_herald_bulk_first_ids()` helper
     * takes the first N by title, so index order is load-bearing.
     *
     * @throws \RuntimeException if Craft rejects a save.
     * @throws \Exception from the DateTime arithmetic.
     *
     * @author Craftpulse
     */
    private function _ensureMinorHeroes(Section $section, EntryType $entryType): void
    {
        $elements = Craft::$app->getElements();
        $existingSlugs = $this->_existingSlugs($section);
        $authorIds = $this->_authorIds();
        $tiers = ['c', 'd', 'e'];
        $now = new DateTime('now', new DateTimeZone('UTC'));
        $created = 0;

        for ($i = 1; $i <= self::MINOR_HEROES_COUNT; $i++) {
            $slug = sprintf('minor-hero-%04d', $i);

            if (isset($existingSlugs[$slug])) {
                continue;
            }

            // Jitter across a 90-day window so the date-range filters in
            // `bulk_entries`' query resolver have a spread to bite on.
            $daysAgo = ($i - 1) * 90 / max(1, self::MINOR_HEROES_COUNT - 1);
            $date = (clone $now)->modify(sprintf('-%d minutes', (int) round($daysAgo * 1440)));

            $entry = new Entry([
                'sectionId' => $section->id,
                'typeId' => $entryType->id,
            ]);
            $entry->title = sprintf('Minor Hero %04d', $i);
            $entry->slug = $slug;
            $entry->dateCreated = $date;
            $entry->postDate = $date;

            if ($authorIds !== []) {
                $entry->setAuthorId($authorIds[($i - 1) % count($authorIds)]);
            }

            $entry->setFieldValues([
                'minorBio' => sprintf('Backstory for minor hero %d. Loosely affiliated. Backbench tier.', $i),
                'tier' => $tiers[($i - 1) % count($tiers)],
            ]);

            if (!$elements->saveElement($entry)) {
                throw new \RuntimeException(
                    "Failed to save minor hero $i: " . json_encode($entry->getErrors()),
                );
            }

            $created++;
        }

        if ($created > 0) {
            $this->_say("created $created `minorHeroes` entries");
        }
    }

    /**
     * Ensure the four sections, returning them keyed by handle.
     *
     * No site is ever created: `AuditFixModesTest` asserts
     * `repair_propagation` finds zero propagation gaps, which only holds on
     * a single-site install. Each section is bound to the primary site.
     *
     * @param array<string, EntryType> $entryTypes
     * @return array<string, Section>
     *
     * @throws \RuntimeException if Craft rejects a save.
     *
     * @author Craftpulse
     */
    private function _ensureSections(array $entryTypes): array
    {
        $service = Craft::$app->getEntries();
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;
        $out = [];

        foreach (self::SECTION_DEFINITIONS as $handle => $definition) {
            $existing = $service->getSectionByHandle($handle);

            if ($existing !== null) {
                $out[$handle] = $existing;
                continue;
            }

            $section = new Section([
                'name' => $definition['name'],
                'handle' => $handle,
                'type' => $definition['type'],
                'maxLevels' => $definition['maxLevels'],
                'enableVersioning' => true,
                'propagationMethod' => PropagationMethod::All,
                'siteSettings' => [
                    new Section_SiteSettings([
                        'siteId' => $siteId,
                        'enabledByDefault' => true,
                        'hasUrls' => $definition['uriFormat'] !== null,
                        'uriFormat' => $definition['uriFormat'],
                        'template' => $definition['template'],
                    ]),
                ],
                'entryTypes' => [$entryTypes[$definition['entryType']]],
            ]);

            if (!$service->saveSection($section)) {
                throw new \RuntimeException(
                    "Failed to save section `$handle`: " . json_encode($section->getErrors()),
                );
            }

            $this->_say("created section `$handle`");
            $out[$handle] = $section;
        }

        return $out;
    }

    /**
     * Ensure the `infinityStones` tag group.
     *
     * @throws \RuntimeException if Craft rejects the save.
     *
     * @author Craftpulse
     */
    private function _ensureTagGroup(): TagGroup
    {
        $service = Craft::$app->getTags();
        $existing = $service->getTagGroupByHandle(self::TAG_GROUP);

        if ($existing !== null) {
            return $existing;
        }

        $group = new TagGroup([
            'name' => 'Infinity Stones',
            'handle' => self::TAG_GROUP,
            'fieldLayout' => new FieldLayout(['type' => Tag::class]),
        ]);

        if (!$service->saveTagGroup($group)) {
            throw new \RuntimeException(
                'Failed to save tag group `' . self::TAG_GROUP . '`: ' . json_encode($group->getErrors()),
            );
        }

        $this->_say('created tag group `' . self::TAG_GROUP . '`');

        return $group;
    }

    /**
     * Ensure the six Infinity Stone tags.
     *
     * @throws \RuntimeException if Craft rejects a save.
     *
     * @author Craftpulse
     */
    private function _ensureTags(TagGroup $group): void
    {
        $elements = Craft::$app->getElements();
        $created = 0;

        foreach (['Mind', 'Soul', 'Space', 'Time', 'Reality', 'Power'] as $title) {
            $exists = Tag::find()
                ->groupId($group->id)
                ->title($title)
                ->status(null)
                ->exists();

            if ($exists) {
                continue;
            }

            $tag = new Tag();
            $tag->groupId = (int) $group->id;
            $tag->title = $title;

            if (!$elements->saveElement($tag)) {
                throw new \RuntimeException(
                    "Failed to save tag `$title`: " . json_encode($tag->getErrors()),
                );
            }

            $created++;
        }

        if ($created > 0) {
            $this->_say("created $created tags in `" . self::TAG_GROUP . '`');
        }
    }

    /**
     * Ensure the 8 `teams` entries, three of them nested one level down.
     *
     * @throws \RuntimeException if Craft rejects a save.
     *
     * @author Craftpulse
     */
    private function _ensureTeams(Section $section, EntryType $entryType): void
    {
        $elements = Craft::$app->getElements();
        $existingSlugs = $this->_existingSlugs($section);
        $bySlug = [];
        $created = 0;

        foreach ($this->_teamRoster() as $data) {
            if (isset($existingSlugs[$data['slug']])) {
                continue;
            }

            $entry = new Entry([
                'sectionId' => $section->id,
                'typeId' => $entryType->id,
            ]);
            $entry->title = $data['title'];
            $entry->slug = $data['slug'];

            if ($data['parent'] !== null && isset($bySlug[$data['parent']])) {
                $entry->setParentId($bySlug[$data['parent']]);
            }

            $entry->setFieldValues([
                'tagline' => $data['tagline'],
                'bio' => $data['tagline'],
            ]);

            if (!$elements->saveElement($entry)) {
                throw new \RuntimeException(
                    "Failed to save team `{$data['title']}`: " . json_encode($entry->getErrors()),
                );
            }

            $bySlug[$data['slug']] = (int) $entry->id;
            $created++;
        }

        if ($created > 0) {
            $this->_say("created $created `teams` entries");
        }
    }

    /**
     * Ensure the `images` volume. Several tests reach for
     * `getAllVolumes()[0]`, so any volume satisfies them, but the fixtures
     * own this one so the asset step knows where to index into.
     *
     * @throws \RuntimeException if Craft rejects the save.
     *
     * @author Craftpulse
     */
    private function _ensureVolume(Local $fs): Volume
    {
        $service = Craft::$app->getVolumes();
        $existing = $service->getVolumeByHandle(self::VOLUME_HANDLE);

        if ($existing !== null) {
            return $existing;
        }

        $layout = new FieldLayout(['type' => Asset::class]);
        $tab = new FieldLayoutTab(['name' => 'Content', 'layout' => $layout]);
        $tab->setElements([new AssetTitleField()]);
        $layout->setTabs([$tab]);

        $volume = new Volume([
            'name' => 'Images',
            'handle' => self::VOLUME_HANDLE,
            'fsHandle' => $fs->handle,
            'fieldLayout' => $layout,
        ]);

        if (!$service->saveVolume($volume)) {
            throw new \RuntimeException(
                'Failed to save volume `' . self::VOLUME_HANDLE . '`: ' . json_encode($volume->getErrors()),
            );
        }

        $this->_say('created volume `' . self::VOLUME_HANDLE . '`');

        return $volume;
    }

    /**
     * Count entries in a section, tolerating a section that doesn't exist.
     *
     * @author Craftpulse
     */
    private function _entryCount(string $sectionHandle): int
    {
        $section = Craft::$app->getEntries()->getSectionByHandle($sectionHandle);

        if ($section === null) {
            return 0;
        }

        return (int) Entry::find()->sectionId($section->id)->status(null)->count();
    }

    /**
     * Slugs already present in a section, as a lookup set. One query rather
     * than 150.
     *
     * @return array<string, true>
     *
     * @author Craftpulse
     */
    private function _existingSlugs(Section $section): array
    {
        $slugs = [];

        foreach (Entry::find()->sectionId($section->id)->status(null)->all() as $entry) {
            if ($entry->slug !== null) {
                $slugs[$entry->slug] = true;
            }
        }

        return $slugs;
    }

    /**
     * The `heroes` entries these fixtures authored, identified by the tagline
     * marker. Entries authored by anything else are never returned, so they
     * can never be modified.
     *
     * @return list<Entry>
     *
     * @throws \craft\errors\InvalidFieldException if `tagline` leaves the hero layout.
     *
     * @author Craftpulse
     */
    private function _fixtureOwnedHeroes(Section $section): array
    {
        $out = [];

        foreach (Entry::find()->sectionId($section->id)->status(null)->all() as $entry) {
            if ($entry->getFieldLayout()?->getFieldByHandle('tagline') === null) {
                continue;
            }

            $tagline = $entry->getFieldValue('tagline');

            if (is_string($tagline) && str_ends_with($tagline, self::FIXTURE_MARKER)) {
                $out[] = $entry;
            }
        }

        return $out;
    }

    /**
     * Persist pending project-config changes.
     *
     * Craft hooks `ProjectConfig::flush()` to `Application::EVENT_AFTER_REQUEST`,
     * and this installer runs from a standalone script that boots the console
     * application without ever running a request, so that event never fires.
     * Without an explicit flush, `saveField()` and friends write their DB rows
     * (through the project-config change handlers) while the config store
     * itself is discarded when the process exits: `project.yaml` never
     * updates, and entities with no DB table of their own, filesystems above
     * all, vanish entirely. The next run would then re-create the filesystem
     * over the top of itself, which is exactly the clobber this installer
     * must never perform.
     *
     * @throws \yii\base\ErrorException if the YAML files can't be written.
     *
     * @author Craftpulse
     */
    private function _flushProjectConfig(): void
    {
        Craft::$app->getProjectConfig()->flush();
    }

    /**
     * The 16 `heroes` entries, mirroring the playground's roster titles,
     * slugs and field values. The prose is deliberately terse: no test
     * asserts on entry copy, only on handles, counts and shapes.
     *
     * @return list<array{
     *     title: string,
     *     slug: string,
     *     tagline: string,
     *     alignment: string,
     *     powerLevel: int,
     *     firstAppearance: string,
     *     bio: string,
     *     power: string,
     * }>
     *
     * @author Craftpulse
     */
    private function _heroRoster(): array
    {
        $roster = [
            ['Iron Man', 'iron-man', 'hero', 92, '1963-03-01', 'Powered armour'],
            ['Captain America', 'captain-america', 'hero', 90, '1941-03-01', 'Vibranium shield'],
            ['Thor', 'thor', 'hero', 96, '1962-08-01', 'Lightning control'],
            ['Hulk', 'hulk', 'hero', 99, '1962-05-01', 'Unlimited strength'],
            ['Black Widow', 'black-widow', 'hero', 75, '1964-04-01', 'Espionage'],
            ['Doctor Strange', 'doctor-strange', 'hero', 95, '1963-07-01', 'Mystic arts'],
            ['Scarlet Witch', 'scarlet-witch', 'anti-hero', 97, '1964-03-01', 'Chaos magic'],
            ['J.A.R.V.I.S.', 'jarvis', 'ai-construct', 60, '2008-05-02', 'Suit orchestration'],
            ['F.R.I.D.A.Y.', 'friday', 'ai-construct', 55, '2015-05-01', 'Tactical analysis'],
            ['Spider-Man', 'spider-man', 'hero', 78, '1962-08-01', 'Wall-crawling'],
            ['Wolverine', 'wolverine', 'anti-hero', 85, '1974-10-01', 'Regeneration'],
            ['Deadpool', 'deadpool', 'chaotic-neutral', 72, '1991-02-01', 'Fourth-wall awareness'],
            ['Loki', 'loki', 'anti-hero', 88, '1962-08-01', 'Illusions'],
            ['Thanos', 'thanos', 'villain', 98, '1973-02-01', 'Infinity Gauntlet'],
            ['Doctor Doom', 'doctor-doom', 'villain', 94, '1962-07-01', 'Dark sorcery'],
            ['Hawkeye', 'hawkeye', 'hero', 65, '1964-09-01', 'Perfect marksmanship'],
        ];

        return array_map(static fn(array $row): array => [
            'title' => $row[0],
            'slug' => $row[1],
            'tagline' => sprintf('%s. Fixture record, not authored copy.', $row[0]),
            'alignment' => $row[2],
            'powerLevel' => $row[3],
            'firstAppearance' => $row[4],
            'bio' => sprintf('Herald fixture bio for %s.', $row[0]),
            'power' => $row[5],
        ], $roster);
    }

    /**
     * Build one entry type from scratch, mirroring the playground's Marvel
     * seed layout, including its required flags. Mirroring rather than
     * relaxing them means a future divergence surfaces as a real signal
     * instead of a masked one.
     *
     * @param array<string, FieldInterface> $fields
     *
     * @throws \InvalidArgumentException on an unknown handle.
     *
     * @author Craftpulse
     */
    private function _newEntryType(string $handle, array $fields): EntryType
    {
        [$name, $icon, $color, $elements] = match ($handle) {
            'hero' => ['Hero', 'user-ninja', 'red', [
                new EntryTitleField(),
                new CustomField($fields['tagline'], ['required' => true, 'width' => 100]),
                new CustomField($fields['alignment'], ['required' => true, 'width' => 50]),
                new CustomField($fields['powerLevel'], ['required' => true, 'width' => 50]),
                new CustomField($fields['firstAppearance'], ['width' => 50]),
                new CustomField($fields['heroImage'], ['width' => 50]),
                new CustomField($fields['bio'], ['width' => 100]),
                new CustomField($fields['superpowers'], ['width' => 100]),
            ]],
            'team' => ['Team', 'users', 'blue', [
                new EntryTitleField(),
                new CustomField($fields['tagline'], ['width' => 100]),
                new CustomField($fields['teamLogo'], ['width' => 50]),
                new CustomField($fields['bio'], ['width' => 100]),
            ]],
            'about' => ['About', 'scroll', 'yellow', [
                new EntryTitleField(),
                new CustomField($fields['tagline'], ['width' => 100]),
                new CustomField($fields['heroImage'], ['width' => 50]),
                new CustomField($fields['bio'], ['required' => true, 'width' => 100]),
            ]],
            'minorHero' => ['Minor Hero', 'user', 'gray', [
                new EntryTitleField(),
                new CustomField($fields['minorBio'], ['width' => 100]),
                new CustomField($fields['tier'], ['width' => 50]),
            ]],
            default => throw new \InvalidArgumentException("No fixture definition for entry type `$handle`."),
        };

        $entryType = new EntryType([
            'name' => $name,
            'handle' => $handle,
            'hasTitleField' => true,
            'icon' => $icon,
            'color' => $color,
        ]);

        $layout = new FieldLayout(['type' => Entry::class]);
        $tab = new FieldLayoutTab(['name' => 'Content', 'layout' => $layout]);
        $tab->setElements($elements);
        $layout->setTabs([$tab]);
        $entryType->setFieldLayout($layout);

        return $entryType;
    }

    /**
     * Build one manifest field from scratch, mirroring the playground's
     * Marvel seed settings so the two datasets behave identically.
     *
     * @throws \InvalidArgumentException on an unknown handle.
     *
     * @author Craftpulse
     */
    private function _newField(string $handle, Volume $volume): FieldInterface
    {
        $volumeSource = 'volume:' . $volume->uid;

        return match ($handle) {
            'tagline' => new PlainText([
                'name' => 'Tagline',
                'handle' => 'tagline',
                'charLimit' => 200,
                'uiMode' => 'normal',
            ]),
            'alignment' => new Dropdown([
                'name' => 'Alignment',
                'handle' => 'alignment',
                'options' => [
                    ['label' => 'Hero', 'value' => 'hero', 'default' => ''],
                    ['label' => 'Villain', 'value' => 'villain', 'default' => ''],
                    ['label' => 'Anti-hero', 'value' => 'anti-hero', 'default' => ''],
                    ['label' => 'Chaotic neutral', 'value' => 'chaotic-neutral', 'default' => ''],
                    ['label' => 'AI construct', 'value' => 'ai-construct', 'default' => ''],
                    ['label' => 'Cosmic entity', 'value' => 'cosmic-entity', 'default' => ''],
                ],
            ]),
            'powerLevel' => new Number([
                'name' => 'Power level',
                'handle' => 'powerLevel',
                'min' => 1,
                'max' => 100,
                'decimals' => 0,
                'defaultValue' => 50,
            ]),
            'firstAppearance' => new Date([
                'name' => 'First appearance',
                'handle' => 'firstAppearance',
                'showTime' => false,
            ]),
            'heroImage' => new Assets([
                'name' => 'Hero image',
                'handle' => 'heroImage',
                'sources' => [$volumeSource],
                'defaultUploadLocationSource' => $volumeSource,
                'allowedKinds' => ['image'],
                'maxRelations' => 1,
                'viewMode' => 'large',
            ]),
            // Divergence: CKEditor in the playground. No test references
            // `bio` by name, and Herald must not depend on craft-ckeditor.
            'bio' => new PlainText([
                'name' => 'Bio',
                'handle' => 'bio',
                'multiline' => true,
                'initialRows' => 6,
                'uiMode' => 'normal',
            ]),
            'superpowers' => new TableField([
                'name' => 'Superpowers',
                'handle' => 'superpowers',
                'columns' => [
                    'col1' => [
                        'heading' => 'Name',
                        'handle' => 'name',
                        'type' => 'singleline',
                    ],
                    'col2' => [
                        'heading' => 'Description',
                        'handle' => 'description',
                        'type' => 'multiline',
                    ],
                ],
            ]),
            'teamLogo' => new Assets([
                'name' => 'Team logo',
                'handle' => 'teamLogo',
                'sources' => [$volumeSource],
                'defaultUploadLocationSource' => $volumeSource,
                'allowedKinds' => ['image'],
                'maxRelations' => 1,
                'viewMode' => 'large',
            ]),
            // Must stay non-relational: `BulkEntriesTest`'s `update_fields`
            // cases write a plain string into it.
            'minorBio' => new PlainText([
                'name' => 'Minor Bio',
                'handle' => 'minorBio',
                'multiline' => true,
                'initialRows' => 3,
            ]),
            'tier' => new Dropdown([
                'name' => 'Tier',
                'handle' => 'tier',
                'options' => [
                    ['label' => 'C-list', 'value' => 'c', 'default' => ''],
                    ['label' => 'D-list', 'value' => 'd', 'default' => ''],
                    ['label' => 'E-list', 'value' => 'e', 'default' => ''],
                ],
            ]),
            'directorName' => new PlainText([
                'name' => 'Director name',
                'handle' => 'directorName',
                'charLimit' => 120,
                'uiMode' => 'normal',
            ]),
            'threatLevel' => new Dropdown([
                'name' => 'Threat level',
                'handle' => 'threatLevel',
                'options' => [
                    ['label' => 'Green, nominal', 'value' => 'green', 'default' => ''],
                    ['label' => 'Yellow, elevated', 'value' => 'yellow', 'default' => '1'],
                    ['label' => 'Red, critical', 'value' => 'red', 'default' => ''],
                    ['label' => 'Code Red, multiversal', 'value' => 'code-red', 'default' => ''],
                ],
            ]),
            'currentDirective' => new PlainText([
                'name' => 'Current directive',
                'handle' => 'currentDirective',
                'multiline' => true,
                'initialRows' => 4,
                'charLimit' => 500,
                'uiMode' => 'normal',
            ]),
            default => throw new \InvalidArgumentException("No fixture definition for field `$handle`."),
        };
    }

    /**
     * Give the auto-created `about` single its field values.
     *
     * Only called when this run created the section, so the entry Craft
     * created alongside it is the fixtures' own. `saveSection()` on a single
     * creates the entry with the section's name as its title, so this is a
     * completion of our own write, not a modification of someone else's.
     *
     * @throws \RuntimeException if Craft rejects the save.
     *
     * @author Craftpulse
     */
    private function _populateAbout(Section $section): void
    {
        $entry = Entry::find()->sectionId($section->id)->status(null)->one();

        if (!$entry instanceof Entry) {
            return;
        }

        $entry->title = 'S.H.I.E.L.D. Dossier: Herald Fixtures';
        $entry->setFieldValues([
            'tagline' => 'Classification: DEV/TEST. Do not deploy to production.',
            'bio' => 'Fixture content installed by Herald\'s test harness.',
        ]);

        if (!Craft::$app->getElements()->saveElement($entry)) {
            throw new \RuntimeException(
                'Failed to populate the `about` single: ' . json_encode($entry->getErrors()),
            );
        }

        $this->_say('populated the `about` single');
    }

    /**
     * Give the global set its element-row field values, but only when they
     * are still empty. `saveSet()` persisted the schema; this is the
     * separate element save that populates it.
     *
     * @throws \RuntimeException if Craft rejects the save.
     *
     * @author Craftpulse
     */
    private function _populateGlobalSet(GlobalSet $set): void
    {
        $current = $set->getFieldValue('directorName');

        if (is_string($current) && $current !== '') {
            return;
        }

        $set->setFieldValues([
            'directorName' => 'Nicholas J. Fury',
            'threatLevel' => 'yellow',
            'currentDirective' => 'Maintain surveillance of unregistered enhanced individuals. '
                . 'Coordinate Avengers response to category-3+ incursions.',
        ]);

        if (!Craft::$app->getElements()->saveElement($set)) {
            throw new \RuntimeException(
                'Failed to populate the `' . self::GLOBAL_SET . '` global set: ' . json_encode($set->getErrors()),
            );
        }

        $this->_say('populated the `' . self::GLOBAL_SET . '` global set');
    }

    /**
     * Count assets that are the target of a `heroImage` relation. Raw query
     * rather than `relatedTo`, which needs a concrete source element.
     *
     * @author Craftpulse
     */
    private function _relatedAssetCount(): int
    {
        $heroImage = Craft::$app->getFields()->getFieldByHandle('heroImage');

        if ($heroImage === null) {
            return 0;
        }

        // Distinct targets, not rows: versioning gives every relation a
        // second row on the entry's revision.
        return (int) (new Query())
            ->from(['r' => Table::RELATIONS])
            ->innerJoin(['a' => Table::ASSETS], '[[a.id]] = [[r.targetId]]')
            ->where(['r.fieldId' => (int) $heroImage->id])
            ->count('distinct [[r.targetId]]');
    }

    /**
     * Emit one progress line, if a sink was given.
     *
     * @author Craftpulse
     */
    private function _say(string $line): void
    {
        if ($this->_output !== null) {
            ($this->_output)($line);
        }
    }

    /**
     * Schema invariants: filesystem, volume, fields, entry types, sections,
     * category groups, tag group, global set.
     *
     * @return list<array{key: string, label: string, satisfied: bool, detail: string}>
     *
     * @author Craftpulse
     */
    private function _schemaChecks(): array
    {
        $rows = [];

        $fs = Craft::$app->getFs()->getFilesystemByHandle(self::FS_HANDLE);
        $rows[] = $this->_check(
            'fs.' . self::FS_HANDLE,
            'Filesystem `' . self::FS_HANDLE . '`',
            $fs !== null,
            $fs !== null ? 'present' : 'missing',
        );

        $volume = Craft::$app->getVolumes()->getVolumeByHandle(self::VOLUME_HANDLE);
        $rows[] = $this->_check(
            'volume.' . self::VOLUME_HANDLE,
            'Volume `' . self::VOLUME_HANDLE . '`',
            $volume !== null,
            $volume !== null ? 'present' : 'missing',
        );

        foreach (self::FIELD_HANDLES as $handle) {
            $field = Craft::$app->getFields()->getFieldByHandle($handle);
            $rows[] = $this->_check(
                "field.$handle",
                "Field `$handle`",
                $field !== null,
                $field !== null ? $field::displayName() : 'missing',
            );
        }

        foreach (self::ENTRY_TYPE_HANDLES as $handle) {
            $entryType = Craft::$app->getEntries()->getEntryTypeByHandle($handle);
            $rows[] = $this->_check(
                "entryType.$handle",
                "Entry type `$handle`",
                $entryType !== null,
                $entryType !== null ? 'present' : 'missing',
            );
        }

        foreach (array_keys(self::SECTION_ENTRY_COUNTS) as $handle) {
            $section = Craft::$app->getEntries()->getSectionByHandle($handle);
            $rows[] = $this->_check(
                "section.$handle",
                "Section `$handle`",
                $section !== null,
                $section !== null ? (string) $section->type : 'missing',
            );
        }

        foreach (array_keys(self::CATEGORY_GROUPS) as $handle) {
            $group = Craft::$app->getCategories()->getGroupByHandle($handle);
            $rows[] = $this->_check(
                "categoryGroup.$handle",
                "Category group `$handle`",
                $group !== null,
                $group !== null ? "maxLevels {$group->maxLevels}" : 'missing',
            );
        }

        $tagGroup = Craft::$app->getTags()->getTagGroupByHandle(self::TAG_GROUP);
        $rows[] = $this->_check(
            'tagGroup.' . self::TAG_GROUP,
            'Tag group `' . self::TAG_GROUP . '`',
            $tagGroup !== null,
            $tagGroup !== null ? 'present' : 'missing',
        );

        $globalSet = Craft::$app->getGlobals()->getSetByHandle(self::GLOBAL_SET);
        $rows[] = $this->_check(
            'globalSet.' . self::GLOBAL_SET,
            'Global set `' . self::GLOBAL_SET . '`',
            $globalSet !== null,
            $globalSet !== null ? 'present' : 'missing',
        );

        return $rows;
    }

    /**
     * The 8 `teams` entries, three of them nested one level down. Parents are
     * listed before their children so the parent id is resolvable in one pass.
     *
     * Slugs mirror the playground's Marvel seed exactly. They have to: the
     * top-up is keyed on slug, so a substituted slug would make the installer
     * add an entry to an install that is already complete.
     *
     * @return list<array{title: string, slug: string, tagline: string, parent: string|null}>
     *
     * @author Craftpulse
     */
    private function _teamRoster(): array
    {
        $roster = [
            ['The Avengers', 'the-avengers', null],
            ['The Original Six', 'the-original-six', 'the-avengers'],
            ['New Avengers', 'new-avengers', 'the-avengers'],
            ['Guardians of the Galaxy', 'guardians-of-the-galaxy', null],
            ['X-Men', 'x-men', null],
            ['Original Class', 'original-class', 'x-men'],
            ['Stark Industries R&D', 'stark-industries-rd', null],
            ['Sinister Six', 'sinister-six', null],
        ];

        return array_map(static fn(array $row): array => [
            'title' => $row[0],
            'slug' => $row[1],
            'tagline' => sprintf('%s. Fixture record, not authored copy.', $row[0]),
            'parent' => $row[2],
        ], $roster);
    }

    /**
     * User invariants: Fury (findable and search-indexed) with an address,
     * and the non-admin activity viewer.
     *
     * @return list<array{key: string, label: string, satisfied: bool, detail: string}>
     *
     * @author Craftpulse
     */
    private function _userChecks(): array
    {
        $rows = [];

        $fury = Craft::$app->getUsers()->getUserByUsernameOrEmail(self::FURY_USERNAME);
        $rows[] = $this->_check(
            'user.' . self::FURY_USERNAME,
            'User `' . self::FURY_USERNAME . '`',
            $fury !== null,
            $fury !== null ? 'present' : 'missing',
        );

        // The `users` tool's `search` mode goes through `UserQuery::search()`,
        // so the row has to be in the search index, not merely in the table.
        $indexed = (int) User::find()->search('Fury')->status(null)->count();
        $rows[] = $this->_check(
            'user.' . self::FURY_USERNAME . '.searchable',
            'User `' . self::FURY_USERNAME . '` is search-indexed',
            $indexed >= 1,
            "$indexed hit(s) for `Fury`",
        );

        $addresses = $fury !== null
            ? (int) Address::find()->ownerId($fury->id)->status(null)->count()
            : 0;
        $rows[] = $this->_check(
            'user.' . self::FURY_USERNAME . '.address',
            'Address on `' . self::FURY_USERNAME . '`',
            $addresses >= 1,
            "$addresses address(es)",
        );

        // Mirrors `CpNavItemTest`'s guard exactly: any non-admin holding the
        // permission satisfies it, so an install that already grants it to
        // some other account needs no new user.
        $viewer = User::find()
            ->admin(false)
            ->status(null)
            ->collect()
            ->first(fn(User $user): bool => $user->can(Herald::PERMISSION_VIEW_ACTIVITY));
        $rows[] = $this->_check(
            'user.activityViewer',
            'Non-admin with `' . Herald::PERMISSION_VIEW_ACTIVITY . '`',
            $viewer instanceof User,
            $viewer instanceof User ? (string) $viewer->username : 'missing',
        );

        return $rows;
    }
}
