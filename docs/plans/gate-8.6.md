# Gate 8.6 - Custom skill element type + skill Pro tool

Internal sub-plan for the builder. Parent: docs/plans/gate-8.md section 8.6 (lines 265-299). Resolves the 15 open questions in the 2026-05-15 planner brief. Scope: first Cortex-owned element type (Skill), Pro CRUD tool, SearchSkills merge, manageCortexSkills permission. No CP UI.

Source files consulted (file:line):

- src/tools/system/SearchSkills.php:242-283 - bundled-corpus loader.
- src/resources/SkillResource.php:120-131 - read() returns {uri, mimeType, text}.
- src/prompts/SkillPrompt.php:108-124 - render() returns prompts/get envelope.
- src/services/Resources.php:225-248 - iterates Skills::skillNames + references + agentNames.
- src/services/Prompts.php:51-87, 214-232 - explicit PROMPT_MAP whitelist.
- src/tools/content/Address.php:74-342, 433-578 - canonical Pro-tool shape.
- src/services/Tools.php:297-357 - registry block.
- src/Cortex.php:111-126, 167-261 - config() component map, init() Event::on.
- src/migrations/Install.php:38-71 - install pattern with index + FK.
- src/migrations/m260514_120200_cortex_invocations.php - numbered-migration template.
- src/db/Table.php:17-98 - table-name constants pattern.
- src/models/Settings.php:85-114 - project-config-stored allowlist precedent.
- tests/Architecture/EditionGatingTest.php:30-58 - _cortex_pro_tool_classes registry.
- vendor/craftcms/cms/src/elements/Tag.php:37-96, 257-296 - flat element class.
- vendor/craftcms/cms/src/elements/Address.php:84-192, 913-916 - single-type element with PC field layout.
- vendor/craftcms/cms/src/services/Addresses.php:387-455 - getFieldLayout/saveFieldLayout pattern.
- vendor/craftcms/cms/src/base/ApplicationTrait.php:1755-1757 - onAdd/onUpdate/onRemove PC handler reg.
- vendor/craftcms/cms/src/services/UserPermissions.php:46-128 - EVENT_REGISTER_PERMISSIONS shape.
- vendor/craftcms/cms/src/services/Categories.php:73-130, 477, 646 - MemoizableArray with null-reset.
- vendor/craftcms/cms/src/services/Elements.php:115-122, 3026 - EVENT_REGISTER_ELEMENT_TYPES.
- vendor/craftcms/commerce/src/Plugin.php:966-976 - _registerElementTypes pattern.
- vendor/michtio/craftcms-claude-skills/src/Skills.php:69-235 - loader: filesystem-scanned, frontmatter NOT parsed.
- Sample SKILL.md - YAML frontmatter (name, description) + markdown body.

---

## Locked decisions

### Carried from brief

1. **Storage** - element-type registration via project config (PC), instances in DB. cortex_skills table joined to elements + elements_sites. Per locked decision 15 of gate-8.md.
2. **Coexistence** - SearchSkills returns union of bundled + element-stored; element-stored wins on handle collision; each row carries source: bundled | element. Per locked decision 16 (option ii).
3. **Permission** - manageCortexSkills, global (not per-instance). Registered via the Cortex plugin via EVENT_REGISTER_PERMISSIONS.
4. **Trait stack** - ProToolTrait + PermissionedToolTrait + IdempotencyTrait. Cache prefix `cortex:skill:idem:`.
5. **Filesystem** - bundled skills load from ~/dev/craftcms-claude-skills/ via the bundled Skills helper. Hardcoded path stays.
6. **No CP UI in 8.6** - MCP wire is the only authoring surface.

### Open questions - resolved against source

7. **Bundled skill data shape** (Q1). Skills::content() returns raw bytes - frontmatter NOT parsed by the loader (Skills.php:125-139 does file_get_contents). On-disk format is YAML frontmatter (name, description) + markdown body (verified at skills/craftcms/SKILL.md:1-3). SearchSkills::_buildIndex() indexes full bytes including frontmatter (SearchSkills.php:253). SkillResource::read() returns full bytes (SkillResource.php:120-131). SkillPrompt::render() returns full bytes (SkillPrompt.php:108-124). **Decision**: Skill element mirrors the bundled shape - native attribute handle (slug-derived), Craft built-in title (maps to bundled name), native attribute description (string <= 4096 chars, maps to bundled description), field-layout-driven body (markdown text, maps to file post-frontmatter body). The merge in SearchSkills produces a synthesized markdown document for element-stored skills (frontmatter rebuilt from title/description + body appended) so downstream consumers receive the same byte-shape regardless of source.

8. **Field layout composition** (Q2). At minimum: built-in title (required), native description column, plus a body field added via PC-stored layout. **Body MUST be markdown** (plain text, not CKEditor HTML) because: (a) bundled loader returns raw markdown; (b) merge rebuilds a unified markdown bytestream; (c) HTML would break prompts/get consumers that render as markdown. **Decision**: migration seeds a default field layout - one tab Content with a PlainText field handle=body, multiline=true, initialRows=12. PC path: plugins.cortex.skillFieldLayout (single layout, single UID - same as addresses.fieldLayouts).

9. **Hierarchy** (Q3). Flat. Bundled skills are flat one-file-per-skill; no editor reason to introduce parent/child. **Decision**: Skill does NOT use Crafts native Structure mechanism. No structureId column, no defineNestedSources().

10. **Multi-site** (Q4). Skills are site-agnostic but Crafts element machinery requires per-site elements_sites rows. Two paths: (a) isLocalized() = false (single canonical row) - Address pattern (elements/Address.php does not override isLocalized, defaults to false); (b) isLocalized() = true (per-site copies). **Decision**: (a) - isLocalized() returns false (default). One canonical row per skill; elements_sites row created for primary site only via Crafts propagationMethod = NONE semantics. Element queries: _resolveSkill() uses site(*) to avoid primary-site assumptions in tests; lookups by handle remain unambiguous (handle is globally unique).

11. **Element class surface** (Q5). Required overrides:
    - displayName() = Skill, pluralDisplayName() = Skills, lowercased variants.
    - refHandle() = skill.
    - hasTitles() = true (mirrors bundled name).
    - hasStatuses() = false (single-state; no draft/disabled in 8.6).
    - hasUris() = false, getUriFormat() = null (no front-end URLs).
    - isLocalized() = false (single canonical row, per Q10).
    - trackChanges() = false (drafts/revisions belong to Gate 9).
    - find(): SkillQuery - returns the custom query class.
    - defineSources() - return [[key => *, label => All skills, criteria => []]]. Placeholder for Gate 9 CP UI.
    - defineActions() - [Delete::class, Restore::class].
    - canView/canSave/canDelete/canDuplicate(User user) - returns $user->admin || $user->can(manageCortexSkills).
    - getFieldLayout(): ?FieldLayout - delegates to Cortex::getInstance()->skills->getFieldLayout().
    - afterSave(bool $isNew), afterDelete(), afterRestore() - write/upsert/delete the cortex_skills record + reset Skills::_merged MemoizableArray (Q15).

12. **Element type registration** (Q6). Single hardcoded element type, no SkillType model - there is exactly one Skill kind and no editor reason to allow multiple. Mirrors Address (no AddressType model in Craft). **Decision**: Cortex::init() registers Skill::class via Elements::EVENT_REGISTER_ELEMENT_TYPES (so ElementTypes tool surfaces it, GraphQL discovery works, condition builders see it). Field layout stored at PC path plugins.cortex.skillFieldLayout with single UID, handled by a new Skills service following the Addresses::handleChangedAddressFieldLayout shape (src/services/Addresses.php:432-455).

13. **DB migration** (Q7). Two layers:
    - Extend src/migrations/Install.php with the cortex_skills table - fresh installs pick it up automatically.
    - Add numbered migration m260516_080000_cortex_skills.php that runs the same safeUp() body (idempotent via tableExists guard). Mirrors m260514_120200_cortex_invocations.php exactly.
    Table columns: id int PK + FK to elements(id) ON DELETE CASCADE; handle string(255) NOT NULL UNIQUE; description string(4096) NULL; dateCreated / dateUpdated dateTime NOT NULL; uid uid NOT NULL.
    Indexes: (handle) UNIQUE; (dateCreated).
    Body content does NOT live in cortex_skills - its a custom field value on the field-layout, stored in content / field-specific tables per Craft 5 content storage. Native description IS in cortex_skills because its needed in list-view path without a field-layout join.

14. **SearchSkills merge implementation** (Q8). New Skills service method getMergedCorpus(?string $kindFilter): array returns the rows SearchSkills::_buildIndex() consumes today, with bundled-vs-element dedup-by-handle baked in (element-stored wins). SearchSkills is rewritten so _buildIndex() becomes a single delegate to Cortex::getInstance()->skills->getMergedCorpus($kindFilter). Service-level memoization via MemoizableArray (Q15).

    Merge algorithm:
    1. Load element-stored skills: Skill::find()->status(null)->site(*)->all().
    2. Build a handle -> element map.
    3. Iterate Skills::skillNames() (bundled). For each bundled handle:
       - If present in the element map: skip the bundled, emit the element with source: element.
       - Otherwise: emit the bundled entry with source: bundled.
    4. After bundled pass, iterate element map for any NEW handles (no bundled equivalent) and emit with source: element.
    5. Bundled-only references are also emitted (Skill elements in 8.6 have no nested references - flat body).
    6. Agents are bundled-only (no element-stored agent concept) - pass through unchanged.

15. **Cache invalidation** (Q9). Service-level MemoizableArray<string, array> on a new Skills service, mirroring Categories::_groups (vendor/craftcms/cms/src/services/Categories.php:73-130). The MemoizableArray holds the merged corpus rows keyed by handle. **Reset triggers**: (a) Skill::afterSave(), (b) Skill::afterDelete(), (c) Skill::afterRestore(), (d) field-layout PC change handler. Each calls Cortex::getInstance()->skills->resetMemo(). Per the locked architecture rule MemoizableArray for cached service lookups - always reset on data changes (.claude/rules/architecture.md).

16. **SearchSkills output schema delta** (Q10). Todays row shape from _buildIndex() (line 248-254): {kind, uri, skill, name|null, content}. After merge, each row gains source: bundled | element and the rows kind stays skill / reference / agent. Element-stored skills always have kind: skill (no nested references in 8.6). The uri for element-stored skills uses the SAME craft-skills://{handle} scheme as bundled - clients can resources/read on either uniformly. Wire-shape diff for _search / _topics output rows: add source field. No removed fields. Existing tests assert the keys; update tests/Tools/System/SearchSkillsTest.php to assert source is present.

17. **MCP resources + prompts merge** (Q11). SkillResource::read() calls Skills::content($skill) directly (filesystem). SkillPrompt::render() calls Skills::content($skill) directly. **Decision**: both classes are extended to consult the Skills service first, falling back to the filesystem Skills::content() only when no element-stored skill exists for that handle. Concretely:
    - SkillResource::read() rewrites to: if Cortex::getInstance()->skills->getByHandle($this->_skill) returns a non-null Skill element, synthesize the markdown bytestream (frontmatter rebuilt from title/description + field-layout body), else fall back to Skills::content($this->_skill). References (craft-skills://x/references/y) are bundled-only; fall-through is unchanged.
    - SkillPrompt::render() same.
    - Resources::_buildRegistry() adds a second pass after the bundled loop that iterates Cortex::getInstance()->skills->allHandles() and registers a SkillResource(skill: $handle) for any element-stored handle NOT already covered by the bundled pass.
    - Prompts::_buildRegistry(): element-stored skills are NOT auto-promoted to prompts. Adding a new prompt is intentionally gated by the PROMPT_MAP whitelist (src/services/Prompts.php:51-87). Element-stored skills with handles matching a PROMPT_MAP entry DO override the bundled body (via the modified SkillPrompt::render() above); element-stored handles NOT in PROMPT_MAP surface only as resources, not prompts. Document in Prompts PHPDoc.

18. **skill Pro tool input schema** (Q12).

| Property | Type | Required | Description |
|---|---|---|---|
| mode | string enum (list, get, create, update, delete) | yes | Operation. |
| id | int | get/update/delete (one of) | Skill element id. |
| uid | string | get/update/delete (one of) | Skill element uid. |
| handle | string | get/update/delete (one of); create (yes) | Skill handle (slug, globally unique). Natural-key lookup. |
| title | string | create (yes) | Human label (maps to bundled name). |
| description | string <= 4096 | no | Short description. |
| body | string | no | Markdown body. Stored as field-layout body field value. |
| fields | object | no | Custom-field values; forwarded to setFieldValues(). |
| limit | int 1-200 | no | List only. Default 50. |
| offset | int >= 0 | no | List only. Default 0. |
| search | string | no | List only. Substring filter; pass-through to SkillQuery::search. |
| source | string enum (bundled, element, all) | no | List only. Default all. |
| hardDelete | bool | no | Delete only. Default false. |
| idempotencyKey | string <= 64 | no | Create/update only. Prefix `cortex:skill:idem:`. |

    Notes:
    - On create, handle required and validated unique against merged corpus. If exists as bundled-only, creation succeeds (overrides bundled per locked decision 2). If exists as element-stored, return validation envelope errors.handle = [handle already in use by an element-stored skill; use update mode].
    - On update, handle change is REJECTED - handle is a natural key. Mirror Address ownership-change refusal (Address.php:503-512).
    - list returns merged corpus (per source filter). Bundled-only rows include source: bundled, uri, name=handle. Element rows include element envelope + source: element.

19. **Permission gating + filterFor** (Q13). manageCortexSkills is a single global permission string with no :{uid} suffix.
    - filterFor(?User $user): stdio -> true; admin -> true; else (bool) $user->can(manageCortexSkills).
    - _requiredPermissions(array $arguments): array returns [manageCortexSkills] for create/update/delete; returns [] for list/get (read modes - bundled corpus is public-read by intent, same as SearchSkills Free tool). The traits wildcard-sentinel check at PermissionedToolTrait.php:97-101 does NOT trigger on bare strings without :*.
    - inputSchemaFor(?User $user) returns the static getInputSchema() unconditionally. Mirrors Address pattern.
    - Defense-in-depth: each mutate mode (_create, _update, _delete) calls _assertPermission() then Craft::$app->getElements()->canSave / canDelete($element, $caller) - Skill canSave/canDelete overrides resolve the same permission so this is redundant but matches Address posture.

20. **Element actions** (Q14). defineActions() returns [Delete::class, Restore::class] because (a) Restore is invoked by the future restore path (deferred to Gate 9), and (b) Craft element lifecycle wires action-class authorization to canDelete()/canSave() regardless of CP UI. Required minimum: Delete::class. **Decision**: ship both - zero cost.

21. **Tests for coexistence** (Q15). New test file tests/Tools/System/SearchSkillsCoexistenceTest.php (separate from existing SearchSkillsTest.php). Cases:
    - Bundled-only baseline: SearchSkills topics returns >=1 bundled row with source: bundled.
    - Element-stored override of a bundled handle (create fixture skill with the exact handle of a real bundled skill, assert merged list shows element entry with source: element and the bundled is gone).
    - Delete element - bundled re-appears.
    - Create element-stored with a handle NOT in bundled set - merged list has bundled + new element entry.
    - Trashed element-stored: element query in getMergedCorpus excludes trashed by default. Assert: soft-deleting an override re-surfaces the bundled.
    - source filter on skill list: bundled / element / all returns correct subsets.

---

## File map

**New:**
- src/elements/Skill.php - element class (~250 LoC).
- src/elements/db/SkillQuery.php - element query, handle/description filters, beforePrepare with addSelect for cortex_skills.handle + cortex_skills.description.
- src/records/Skill.php - ActiveRecord.
- src/services/Skills.php - getFieldLayout, saveFieldLayout, handleChangedFieldLayout, getMergedCorpus(?string $kindFilter), getByHandle, allHandles, resetMemo. ~250 LoC.
- src/tools/system/Skill.php - Pro CRUD tool. ~600 LoC including PHPDoc, similar to Address.
- src/migrations/m260516_080000_cortex_skills.php - retrofit table for installed environments.
- tests/Elements/SkillTest.php - element-class round-trip tests.
- tests/Tools/System/SkillTest.php - Pest suite for the tool.
- tests/Tools/System/SearchSkillsCoexistenceTest.php - merge contract tests.

**Modified:**
- src/Cortex.php - add skills => [class => Skills::class] to config(); init() registers (a) Elements::EVENT_REGISTER_ELEMENT_TYPES adding Skill::class, (b) UserPermissions::EVENT_REGISTER_PERMISSIONS adding manageCortexSkills, (c) ProjectConfig onAdd/onUpdate/onRemove for plugins.cortex.skillFieldLayout.
- src/plugin/Services.php (ServicesTrait) - add getSkills(): Skills accessor.
- src/migrations/Install.php - extend safeUp() with cortex_skills table (and safeDown() to drop it).
- src/db/Table.php - add public const SKILLS = "{{%cortex_skills}}";
- src/tools/system/SearchSkills.php - _buildIndex delegates to Cortex::getInstance()->skills->getMergedCorpus($kindFilter). Output rows in _search/_topics gain source field.
- src/resources/SkillResource.php - read() consults the service before falling through to filesystem.
- src/prompts/SkillPrompt.php - render() consults the service before falling through to filesystem.
- src/services/Resources.php - _buildRegistry adds second pass for element-stored handles not in bundled set.
- src/services/Tools.php - append new Skill() to Pro-write block after new Users() (line ~332). Add import.
- tests/Architecture/EditionGatingTest.php - append Skill::class to _cortex_pro_tool_classes(). Add use.
- tests/Tools/System/SearchSkillsTest.php - extend existing assertions to require source field on rows.

**Untouched** (verify, do not modify):
- src/tools/ProToolTrait.php, PermissionedToolTrait.php, IdempotencyTrait.php, AbstractTool.php - consumed as-is.
- src/services/Prompts.php - element-stored skills NOT auto-promoted to prompts. PROMPT_MAP whitelist stays authoritative (PHPDoc updated only).

---

## Element class spec - Skill

| Method | Value | Rationale / Craft parallel |
|---|---|---|
| displayName() | Skill | n/a |
| pluralDisplayName() | Skills | n/a |
| lowerDisplayName / pluralLowerDisplayName | skill / skills | n/a |
| refHandle() | skill | Twig refs |
| hasTitles() | true | Tag.php:77, Address.php:92 |
| hasStatuses() | false | Address.php:100 - single-state |
| hasUris() | false | No front-end URLs |
| isLocalized() | false (default) | Single canonical row |
| trackChanges() | false | Defer drafts/revisions to Gate 9 |
| find() | new SkillQuery(self::class) | Standard |
| defineSources() | [[key => *, label => All skills]] | Placeholder for Gate 9 |
| defineActions() | [Delete::class, Restore::class] | Q20 |
| canView/canSave/canDelete/canDuplicate | $user->admin OR $user->can(manageCortexSkills) | Q11/Q13 |
| getFieldLayout() | Cortex::getInstance()->skills->getFieldLayout() | Address.php:913-916 + Addresses.php:387-404 |
| afterSave/afterDelete/afterRestore | upsert/delete record + skills->resetMemo() | Q15 |
| beforeDelete() | return true (no extra cleanup; FK cascade handles record) | Standard |

Native attributes (mirrored from cortex_skills row, populated via SkillQuery::beforePrepare with addSelect):
- handle: string (unique).
- description: ?string (<= 4096).
- body lives in field layout, not on element - accessed via getFieldValue(body).

---

## Tool spec - skill

### Modes

| Mode | Required args | Optional args | Permission |
|---|---|---|---|
| list | (none) | source, search, limit, offset | filterFor coarse gate only |
| get | one of id/uid/handle | (none) | filterFor coarse gate only |
| create | handle, title | description, body, fields, idempotencyKey | manageCortexSkills |
| update | one of id/uid/handle | title, description, body, fields, idempotencyKey | manageCortexSkills + Elements::canSave |
| delete | one of id/uid/handle | hardDelete | manageCortexSkills + Elements::canDelete |

### Output envelopes

| Mode | Shape |
|---|---|
| list | {success: true, mode: list, skills: [skill, ...], count, limit, offset, source} |
| get | {success: true, mode: get, skill: {...}} |
| create | success: {success: true, mode: create, skill: {...}}; validation fail: {success: false, mode: create, errors: {...}, id: null, uid: null, handle: <attempted>} |
| update | success: {success: true, mode: update, skill: {...}}; validation fail: {success: false, mode: update, errors, id, uid, handle} |
| delete | {success: true, mode: delete, id, uid, handle, hardDeleted} |

### _serializeSkill() row shape

```
{
  source: bundled | element,
  handle: string,
  title: string,                 // bundled name from frontmatter, or element title
  description: string|null,
  body: string,                  // raw markdown body (frontmatter rebuilt above when element)
  uri: craft-skills://<handle>,
  // element-only fields:
  id?: int,
  uid?: string,
  dateCreated?: ISO 8601,
  dateUpdated?: ISO 8601,
}
```

Bundled rows: id, uid, dateCreated, dateUpdated omitted. body for bundled rows is the FULL SKILL.md bytes (including frontmatter), matching todays Skills::content() contract.

Permission denials throw ToolException with -32002. Validation failures return validation envelope.

---

## SearchSkills merge spec

**Entry point**: SearchSkills::_buildIndex(?string $kindFilter) becomes:

```
return Cortex::getInstance()->skills->getMergedCorpus($kindFilter);
```

**Skills::getMergedCorpus(?string $kindFilter) algorithm**:

1. If memoized: return memoized rows filtered by $kindFilter.
2. Otherwise build the array:
   a. Load all element-stored skills: Skill::find()->status(null)->site(*)->all(). Build $elementByHandle: array<string, Skill>.
   b. Iterate Skills::skillNames() (bundled). For each $skill:
      - If isset($elementByHandle[$skill]): emit one row for the element with kind: skill, source: element (skip bundled). References for that bundled skill are STILL emitted from filesystem (locked decision 2 covers SKILL.md only).
      - Else: emit the bundled kind: skill, source: bundled row.
      - Always: emit each Skills::references($skill) ref as kind: reference, source: bundled.
   c. After bundled pass, iterate $elementByHandle for handles NOT in Skills::skillNames(): emit kind: skill, source: element rows.
   d. Iterate Skills::agentNames(): emit kind: agent, source: bundled rows.
3. Memoize the unfiltered list. Filter on return.

**content field synthesis for element-stored rows**:

```
"---\nname: {handle}\ndescription: {description ?? \"\"}\n---\n\n# {title}\n\n{body}"
```

Frontmatter rebuilt deterministically so token-scoring in _search indexes the same shape as bundled rows.

**Memoization** (MemoizableArray<int, array>):
- Field: private ?MemoizableArray $_merged = null; on Skills.
- Reset triggers (all call $this->resetMemo() which assigns null):
  - Skill::afterSave / Skill::afterDelete / Skill::afterRestore.
  - Skills::handleChangedFieldLayout.

**Output row shape diff** (SearchSkills::_search / _topics): each row gains source: bundled | element. No removed fields. Existing snippet/scoring logic is source-agnostic.

---

## Migration spec

**cortex_skills table** (added to both Install.php and new m260516_080000_cortex_skills.php):

```
id              int PK                      -- FK to elements(id) ON DELETE CASCADE
handle          string(255) NOT NULL        -- UNIQUE
description     string(4096) NULL
dateCreated     dateTime NOT NULL
dateUpdated     dateTime NOT NULL
uid             uid NOT NULL
```

Indexes: UNIQUE (handle); (dateCreated).

Foreign keys: id -> elements(id) ON DELETE CASCADE. Standard Craft pattern (mirrors craft\\records\\Tag).

safeDown() drops the table. No content migration needed - fresh table.

Body is NOT in cortex_skills - its a custom field value bound to elements field layout, stored via Crafts content layer.

---

## Project config spec

**PC path**: plugins.cortex.skillFieldLayout - single layout, single UID. Same shape as addresses.fieldLayouts.

Value:
```
{
  <layout-uid>: <FieldLayout::getConfig() output>
}
```

Cortex init() wires handlers via:
```
Craft::$app->getProjectConfig()
    ->onAdd(plugins.cortex.skillFieldLayout, [$skills, handleChangedFieldLayout])
    ->onUpdate(plugins.cortex.skillFieldLayout, [$skills, handleChangedFieldLayout])
    ->onRemove(plugins.cortex.skillFieldLayout, [$skills, handleChangedFieldLayout]);
```

Default field layout seeded on first getFieldLayout() call (if PC has no layout): one tab Content with a single PlainText field handle=body, multiline=true, initialRows=12. Seeding is lazy (in getFieldLayout() itself, mirroring Addresses::getFieldLayout() line 394-401).

---

## Permission registration

In Cortex::init(), after the existing Event::on calls:

```
Event::on(
    UserPermissions::class,
    UserPermissions::EVENT_REGISTER_PERMISSIONS,
    static function (RegisterUserPermissionsEvent $e): void {
        $e->permissions[] = [
            heading => Cortex,
            permissions => [
                manageCortexSkills => [
                    label => Manage Cortex skills,
                    info  => Allows creating, updating, and deleting Cortex skill elements through the MCP server.,
                ],
            ],
        ];
    },
);
```

(Shape verified against vendor/craftcms/cms/src/services/UserPermissions.php:85-96.)

The permission appears under the Cortex heading on the user-permissions screen and on the users get/users list envelope when assigned.

---

## Test scope

### tests/Elements/SkillTest.php

- Save round-trip: new Skill(handle, title, description) + Craft::$app->getElements()->saveElement() -> reload by id -> attributes round-trip.
- Body field round-trip via setFieldValue(body, ...) and getFieldValue(body).
- Handle uniqueness: saving two skills with the same handle returns false + validation error.
- canView/canSave/canDelete returns true for admin, true for user with manageCortexSkills, false for user without.
- Soft-delete + restore round-trips. After save, cortex_skills row exists. After hard-delete, row gone.
- Element query: Skill::find()->handle(foo)->one() returns the saved skill.

### tests/Tools/System/SkillTest.php

Fixture prefix: __cortex_skilltest_<hex>_. afterEach hard-deletes by handle LIKE.

Registration:
- NOT registered on Free.
- shouldRegister() returns true on Pro.

filterFor:
- stdio (null) returns true.
- Admin returns true.
- User with manageCortexSkills returns true.
- User without returns false.

list:
- Admin returns merged corpus with source field on every row.
- source=bundled filter returns only bundled rows.
- source=element filter returns only element rows.
- search=<bundled handle> returns matching rows.
- limit/offset paginate.

get:
- By id, uid, handle - each works.
- handle lookup of bundled-only handle returns the bundled envelope with source: bundled and full SKILL.md as body.
- handle lookup after element-stored override returns element envelope with source: element.
- Missing all keys -> ToolException.
- Non-existent handle -> ToolException.

create:
- Admin: round-trips a new skill. cortex_skills row present.
- Caller with manageCortexSkills (non-admin): same.
- Caller without -> ToolException (-32002).
- Duplicate handle (existing element) -> validation envelope errors.handle.
- Handle matching a bundled-only handle -> SUCCESS (locked decision 2 - override). Verify merged list now shows source: element for that handle.
- Idempotency: same key issued twice returns the cached envelope.

update:
- Admin: mutate title/description/body, round-trips.
- handle change attempt -> ToolException (natural-key invariant).
- Non-existent -> ToolException.
- Trashed skill resolved via probe -> ToolException with restore-hint.
- Caller without manageCortexSkills -> ToolException.

delete:
- Admin soft-deletes. Skill::find()->trashed()->one() finds it. cortex_skills row still present.
- hardDelete: cortex_skills row gone, FK CASCADE wiped elements row too.
- Bundled-override case: delete element override, assert SearchSkills topics shows the bundled handle back with source: bundled.

### tests/Tools/System/SearchSkillsCoexistenceTest.php

Per Q21 - six cases:
- Bundled-only baseline: rows carry source: bundled.
- Element override of a bundled handle (create fixture skill with the exact handle of a real bundled skill; verify override behavior end-to-end).
- Delete the override -> bundled re-appears.
- Element with a non-bundled handle -> both surface.
- Trashed element does NOT override bundled (soft-delete the override and re-run SearchSkills topics).
- kind filter: kind=skill returns both bundled and element-stored skill kind; kind=reference returns bundled-only references; kind=agent returns bundled-only agents.

### tests/Tools/System/SearchSkillsTest.php (extend)

Existing tests assert row shape; extend to require source key on every result row.

### Architecture invariants (in EditionGatingTest.php)

- Append Skill::class to _cortex_pro_tool_classes(). The five existing invariants then auto-apply.

---

## Verification gates

Three layered gates:

1. ddev exec vendor/bin/pest --filter=SkillTest|SearchSkillsCoexistenceTest|SearchSkillsTest|EditionGatingTest - focused suite green.
2. ddev composer phpstan - level 8 clean.
3. ddev composer check-cs - ECS clean.

Final gate (after the three above): full suite ddev exec vendor/bin/pest to confirm no regressions; baseline 724 passing / 1 skipped pre-8.6.

Layered build-verify cadence the builder follows:
- Migration + record: run ddev craft migrate/up against the playground. Verify cortex_skills table exists; migrate/down then migrate/up round-trips.
- Element class + query: write SkillTest.php first three cases (save, reload, query). Verify with --filter=SkillTest.
- Skills service + field-layout PC handler: assert getFieldLayout() seeds + persists; element body round-trips through field layout.
- manageCortexSkills permission registration: verify by extending PermissionsAndGroupsTest.php to assert the permission shows up in list output.
- Tool skeleton + registration: run EditionGatingTest - confirm new tool registers on Pro and not on Free.
- list + get happy paths against bundled corpus only (no element overrides yet): run SkillTest list/get.
- create/update/delete with idempotency: run rest of SkillTest.
- Merge in SearchSkills + cache reset + resource/prompt fallthrough: run SearchSkillsCoexistenceTest.
- Final: full suite + PHPStan + ECS.

---

## Manual verification

After the builder reports done, the dispatcher should:

1. **Free install**: confirm skill is absent from tools/list. SearchSkills still works (rows now carry source: bundled).
2. **Pro install, admin token**: tools/list over HTTP includes skill. tools/call skill mode=list returns merged corpus with source on every row. tools/call skill mode=get handle=craftcms returns the bundled SKILL.md as body.
3. **Pro install, admin**: tools/call skill mode=create with handle=__manual_test_, title=Test, body=# hi - round-trip. cortex_skills row appears in DB. SearchSkills list shows the new entry with source: element.
4. **Pro install, admin**: tools/call skill mode=create with handle=craftcms (matches a real bundled skill), title=Override. Verify SearchSkills topics now shows craftcms with source: element, the bundled is hidden. resources/read craft-skills://craftcms returns the synthesized override markdown, NOT the upstream SKILL.md. Then skill mode=delete handle=craftcms hardDelete=true - verify bundled re-appears in SearchSkills topics and resources/read craft-skills://craftcms returns the original SKILL.md bytes.
5. **Pro install, non-admin token without manageCortexSkills**: tools/list does NOT include skill. tools/call skill mode=create returns -32002 via not-found path.
6. **Pro install, non-admin token WITH manageCortexSkills**: tools/list includes skill. tools/call skill mode=create succeeds.
7. **Pro install, admin**: tools/call users mode=get for user assigned manageCortexSkills - confirm permission appears in response envelopes permissions list.
8. **Pro install, admin**: log into CP, navigate to a users permissions screen, confirm Cortex heading appears with Manage Cortex skills checkbox under it.

---

## Risks / open follow-ups

- **Bundled-skills repo path** (locked decision 5). Hardcoded via the Composer dep michtio/craftcms-claude-skills. The path is resolved by Skills::path() returning dirname(__DIR__) - fine for dev (symlinked) and prod (vendored). No code change needed in 8.6; flag in tool PHPDoc as future portability concern (e.g. a Settings::$skillsCorpusPath override).
- **Reference-document override** is NOT supported in 8.6. Element-stored skills override the bundled SKILL.md only; bundled references/*.md files stay filesystem-sourced. Future work: add a references JSON column or child-element type. Document in tool PHPDoc.
- **Element-stored skills as prompts** are gated by static PROMPT_MAP (Q17). Adding a new prompt requires both the element AND a PROMPT_MAP entry. Acceptable for 8.6 - keeps public MCP surface deliberate. Future: optionally let element-stored skill expose a frontmatter-derived mcpPromptName that auto-registers a prompt.
- **Field-layout migration concerns**. Future changes to body field (rename, type change) require careful PC handling. Flag in tool PHPDoc.
- **isLocalized() = false semantics**. Skills land in a single elements_sites row for primary site. Multi-site installs: skills are NOT per-site. Deliberate design (skills are policy, not content). If a future site needs per-site bodies, swap to true and migrate - major-version concern.
- **Element-stored handle uniqueness vs bundled**. DB UNIQUE constraint enforces element-stored handle uniqueness only. Element-stored handles can match bundled (thats the override mechanism). The merge in getMergedCorpus() is authority on merged-corpus invariant.
- **GraphQL exposure**. Registering Skill::class via EVENT_REGISTER_ELEMENT_TYPES will surface skills in Crafts GraphQL schema if GraphQL is enabled. No tool-layer permission gate runs there - GraphQL has its own schema-component gate (out of scope for 8.6). Document in tool PHPDoc.

---

## Out of scope

- **CP authoring screens** (edit page, index, sources, field layout designer) - Gate 9.
- **Reference-document override / nested skills** - future gate.
- **Prompt auto-registration for element-stored skills** - Q17 keeps static PROMPT_MAP whitelist authoritative.
- **Per-skill ACLs / depth limits** - locked decision 3 keeps the permission global.
- **Bundled-skills corpus portability** (composer vs URL vs filesystem-path setting) - locked decision 5 keeps hardcoded path.
- **Skill drafts / revisions** - trackChanges() returns false; no hasDrafts mechanism. Gate 9 or later.
- **Skill search beyond substring** - list-mode search is pass-through to Craft Search; vectorized/semantic search out of scope.
- **GraphQL custom mutation surface for skills** - Crafts auto-generated element GraphQL is the surface in 8.6.
- **Restore mode on the skill tool** - soft-delete is supported, but restore-via-tool path deferred. Operators restore via Craft CP (when Gate 9 ships UI) or via DB.
