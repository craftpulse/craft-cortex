# Gate 8.8 — Mode unlocks on four Free tools (drafts_and_revisions, system_diagnostics, content_audit, import_export)

Internal sub-plan for the builder. Parent: `docs/plans/gate-8.md` section 8.8 (lines 353–378). Scope: extend four existing Free tools with Pro modes per the Gate 8 mode-unlock composition contract (locked decision 6). Each tool stays Free at registration time; the new modes are reachable only when (a) the caller's `inputSchemaFor($user)` exposes them, (b) the caller holds the matching Craft permission, and (c) the in-`execute()` re-check passes. No new tool registrations, no new permissions, no streaming surface (8.9 wraps the per-row loop modes).

Baseline at HEAD `5c1f6cf`: 833 passing / 1 skipped / 10249 assertions, PHPStan + ECS clean.

## Class-name + tool-name reconciliation

Resolved against `src/services/Tools.php:50-52, 39, 360-362`:

- `docs/plans/gate-8.md` writes "`diagnostics`" — actual class is `craftpulse\cortex\tools\system\Diagnostics` (`src/tools/system/Diagnostics.php`), and `getName()` returns `'system_diagnostics'` (`Diagnostics.php:61`). Mode-enum field is `type`, not `mode` (`Diagnostics.php:86`).
- `docs/plans/gate-8.md` writes "`audit`" — actual class is `craftpulse\cortex\tools\workflow\Audit` (`src/tools/workflow/Audit.php`), and `getName()` returns `'content_audit'` (`Audit.php:95`). Mode-enum field is `mode`.
- `DraftsAndRevisions` and `ImportExport` match the spec by both class name and `getName()` (`drafts_and_revisions` / `import_export`).

The plan uses the real class names (`Diagnostics`, `Audit`) and the wire tool names (`system_diagnostics`, `content_audit`) throughout.

## Split recommendation — TAKEN

LoC walk against the four tool surfaces yields **~1970 production+test LoC** for the bundled gate, well over the ~600 LoC ceiling in `gate-8.md:378`. The split is taken in this plan:

| Sub-gate | Tools | Estimated LoC | Rationale |
|---|---|---|---|
| **8.8a** | `drafts_and_revisions` + `system_diagnostics` | ~670 | Simpler single-element / single-action unlocks. No per-row iteration. No 8.9 streaming dependency. |
| **8.8b** | `content_audit` + `import_export` | ~1300 | Per-row loop modes. 8.9 will convert the loop bodies to `Generator` returns. Heavier permission resolution (per-row section UID / volume UID). |

Each sub-gate is a separate commit. 8.8a lands first (mechanical pattern, ships the `inputSchemaFor()` override template the rest of 8.8 reuses), 8.8b after. 8.8b can also reasonably split into 8.8b-i (`content_audit`) and 8.8b-ii (`import_export`) at builder discretion if the per-row loop bodies bloat past ~700 LoC each — but the bundling is justified because both 8.8b tools share the per-row outcome envelope shape that 8.9 streaming will piggyback on.

Source files consulted (file:line):

- `src/services/Tools.php:39, 50-52, 360-362` — Free-registration order; the four tools live in the "Workflow & audit (read modes)" + "System & diagnostics" blocks, untouched by `ProToolTrait`.
- `src/tools/AbstractTool.php:113-116` — default `inputSchemaFor()` delegates to static `getInputSchema()`. Override returns a modified copy.
- `src/tools/AbstractTool.php:300-307` — `_applyFields()` forwards `fields: {handle: value}` to `Element::setFieldValues()`. The pass-through contract Craft normalises at save time. Reusable by `import_export.import`.
- `src/tools/AbstractTool.php:324-333` — `_validationEnvelope()` — `{success: false, mode, id, uid, errors}`. Reusable by `import_export.import` per-item validation failure.
- `src/tools/PermissionedToolTrait.php:82-109` — `_assertPermission()` walks the per-tool `_requiredPermissions($arguments)` list. stdio (`null` user) and admins skip. Wildcard sentinel reaching `_assertPermission()` throws — every fix mode in 8.8 MUST resolve per-resource UID before calling.
- `src/tools/PermissionedToolTrait.php:123-126` — `_buildPermissionDeniedMessage()` default emits the bare permission string; each Pro consumer overrides to emit the rich format `permission denied — mode `X` on section `Y` requires `Z`.`
- `src/tools/ToolException.php:22-24` — `ToolException` is the carrier for permission denial. The dispatcher converts it to a `{content: [{type:text, text:$message}], isError: true}` tool envelope at `src/mcp/Server.php:739-741, 908-910, 1327-1335`. **The `-32002` wording in `gate-8.md:58` is a documentation convention; the on-the-wire shape is `isError: true` with the rich-format message as the text body.** `gate-8.md`-Decision-6 invariant is satisfied by message-text uniformity, not by a numeric JSON-RPC code on the response envelope.
- `src/tools/content/Entry.php:214-235` — canonical `filterFor()` pattern: stdio + admin short-circuits, then walks `Craft::$app->getEntries()->getAllSections()` calling `$user->can("saveEntries:{uid}")` / `deleteEntries:{uid}`. 8.8 mirrors this for `drafts_and_revisions` and `content_audit`.
- `src/tools/content/Entry.php:253-305` — canonical `inputSchemaFor()` pattern: short-circuit on stdio/admin, walk permissions, rebuild the `mode` enum preserving schema order. 8.8 mirrors this for all four tools.
- `src/tools/content/Entry.php:602-653` — canonical `apply_draft` permission resolution: load draft → resolve canonical → `_assertPermission(arguments + ['sectionUid' => $canonical->section->uid])` → `Drafts::applyDraft()`. 8.8a `drafts_and_revisions.apply` delegates to the same pattern (with a duplicate-implementation note — `entry.apply_draft` already exists; see "Open question A" below).
- `src/tools/content/Entry.php:666-677` — canonical `_buildPermissionDeniedMessage()` format: `permission denied — mode `%s` on section `%s` requires `%s`.`. 8.8 mirrors per-tool with the relevant resource (section / volume / job).
- `src/tools/workflow/DraftsAndRevisions.php:87-103` — current Free schema; mode enum is `['list_drafts', 'list_revisions', 'compare']`. 8.8a appends `apply` / `discard`.
- `src/tools/workflow/DraftsAndRevisions.php:113-126` — current `execute()` match — appends `apply` / `discard` arms.
- `src/tools/workflow/Audit.php:119-131` — current Free schema; mode enum is `['relations', 'unused_assets', 'propagation']`. 8.8b appends `fix_relations` / `prune_unused_assets` / `repair_propagation`.
- `src/tools/workflow/Audit.php:141-154` — current `execute()` match — appends the three fix-mode arms.
- `src/tools/workflow/Audit.php:170-203` — `_relations()` produces broken-relation rows with `sourceId`, `targetId`, `fieldId`, `sortOrder`. The fix mode iterates the same query and deletes per-relation rows (or saves canonical with broken-relation field cleared) per the per-row outcome envelope.
- `src/tools/workflow/Audit.php:216-260` — `_unusedAssets()` produces an `Asset[]` list. The fix mode iterates the same query and deletes each asset via `Elements::deleteElement($asset)` after `canDelete()` per row.
- `src/tools/workflow/Audit.php:299-418` — `_propagation()` produces `gaps[]` with `section`, `canonicalId`, `missingSites[]`. The fix mode iterates the gaps and force-resaves the canonical with each missing site appended via `Element::setEnabledForSite()` + `saveElement(propagate: true)`.
- `src/tools/workflow/ImportExport.php:140-153` — current Free schema; mode enum is `['export']`. 8.8b appends `import`.
- `src/tools/workflow/ImportExport.php:236-262` — `_serializeEntry()` is the export envelope shape. `import` mode round-trips this shape — required `uid`, `section`, `type`, `site`, `fields`. Validation matches the shape.
- `src/tools/system/Diagnostics.php:83-97` — current Free schema; type enum is `['logs', 'last_error', 'deprecations', 'queue', 'project_config_diff']`. 8.8a appends `manage_queue`.
- `src/tools/system/Diagnostics.php:107-124` — current `execute()` match — appends the `manage_queue` arm.
- `src/tools/system/Diagnostics.php:236-264` — `_queue()` returns `getJobInfo($limit)` with id, status, error. The `manage_queue` mode reuses this read for the post-action confirmation envelope.
- `vendor/craftcms/cms/src/services/Drafts.php:284-289` — `applyDraft(ElementInterface $draft, array $newAttributes = []): ElementInterface`. No `discardDraft()` method exists.
- `vendor/craftcms/cms/src/controllers/ElementsController.php:2321-2367` — Craft's `actionDeleteDraft()` is the canonical discard path: load draft → `Elements::canDelete($draft, $user)` → `Elements::deleteElement($draft, true /* hardDelete */)`. 8.8a `discard` mirrors this exactly.
- `vendor/craftcms/cms/src/queue/Queue.php:289-294, 354-365` — `retry(string $id): void` and `release(string $id): void`. **No `cancel()` method.** The plan's "retry / cancel / release" sub-mode wording in `gate-8.md:43` is incorrect against Craft's actual API — `release` IS the delete/cancel. 8.8a sub-modes are `retry` (clear the failed state, requeue) and `release` (delete the job row). A `release_all` sub-mode lands as well since it's a one-line wrapper on `releaseAll()` and the LLM ergonomics are obvious.
- `vendor/craftcms/cms/src/utilities/QueueManager.php:34-37` — `QueueManager::id() = 'queue-manager'` → permission string `utility:queue-manager`. Confirmed against `vendor/craftcms/cms/src/services/UserPermissions.php:819` (`utility:%s` template). 8.8a's permission gate.
- `vendor/craftcms/cms/src/services/Elements.php:4754, 4863, 4885` — `canSave()`, `canDelete()`, `canDeleteForSite()` — the per-element auth surface 8.8b's fix modes call per row.
- `vendor/craftcms/cms/src/services/UserPermissions.php:602, 614, 768, 771` — canonical permission strings: `saveEntries:{uid}`, `deleteEntries:{uid}`, `saveAssets:{uid}`, `deleteAssets:{uid}`.
- `vendor/craftcms/cms/src/elements/Asset.php:95` — `$asset->volumeId` exposes the volume id; `$asset->getVolume()->uid` produces the volume UID for the per-row permission resolution in `prune_unused_assets`.
- `tests/Architecture/EditionGatingTest.php:50-63` — the Pro-tool invariant list. **`Audit`, `DraftsAndRevisions`, `ImportExport`, `Diagnostics` are NOT added.** They stay Free-registered; the new modes are mode-gated, not edition-gated. The invariant list is unchanged by 8.8.

---

## Locked decisions

### Carried from gate-8.md (resolved 2026-05-15)

1. **Mode-unlock composition contract (Decision 6).** Each tool overrides `inputSchemaFor(?User $user = null): array` to filter the `mode` / `type` enum based on the user's permissions. stdio (`null`) returns the full enum. Admin returns the full enum. HTTP non-admin walks the permission map and rebuilds the enum. `execute()` re-validates the resolved mode and throws `ToolException` with the rich-format message when the caller invokes a Pro mode without authority.
2. **Permission map (Decision 4).**
   - `drafts_and_revisions.apply` / `.discard` → per-canonical `saveEntries:{sectionUid}` (Craft's own `ElementsController::actionDeleteDraft()` semantics treat draft delete as a save-permission gate against the canonical).
   - `content_audit.fix_relations` → per-source-element `saveEntries:{sectionUid}` (for entries) / `saveCategories:{groupUid}` (for categories) / `saveAssets:{volumeUid}` (for assets); the source element drives the gate.
   - `content_audit.prune_unused_assets` → per-asset `deleteAssets:{volumeUid}`.
   - `content_audit.repair_propagation` → per-canonical `saveEntries:{sectionUid}`.
   - `import_export.import` → per-item `saveEntries:{sectionUid}` for the target section.
   - `system_diagnostics.manage_queue` → `utility:queue-manager`.
3. **Free tools stay Free-registered.** No `ProToolTrait` added. `shouldRegister()` continues to return `true` on Free. The `tests/Architecture/EditionGatingTest.php` Pro-tool list is unchanged.
4. **No streaming in 8.8 (Decision 8).** Fix modes in 8.8b return plain `array` envelopes. 8.9 converts the loop bodies to `Generator` returns. Builder writes the loops so the conversion is mechanical: a single `foreach` over the resolved-and-permission-checked iterable, no closure capture that would block generator conversion. The "seam" is the loop body. See "Open question H" resolution below.
5. **No `allowAdminChanges` interaction (Decision 14).** Verified per tool:
   - `apply` / `discard` mutate canonical content via `saveElement` / `deleteElement` — content-level.
   - `fix_relations` deletes per-element relation rows or clears relation field values — content-level.
   - `prune_unused_assets` deletes assets — content-level.
   - `repair_propagation` calls `saveElement(propagate: true)` on entries — content-level.
   - `import` calls `saveElement` per item — content-level.
   - `manage_queue` retries / releases queue jobs — content/operational-level (the queue runs project-config-apply jobs but the manage-queue action does not itself rewrite project config; `release` on a queued `ProjectConfig::Apply` job just removes the pending application, the project config file is untouched).
   No 8.8 mode requires `allowAdminChanges = true`. 8.10's `AllowAdminChangesBoundaryTest` exercises all five in both states; 8.8 adds no new admin-level surface to it.
6. **Error envelope shape (Decision 6 invariant).** `ToolException` thrown from `execute()` rides the existing `_toolErrorEnvelope($message)` path at `src/mcp/Server.php:1327-1335`. **There is no `-32002` numeric code on the JSON-RPC envelope** — `gate-8.md:58`'s "-32002" reference is a documentation tag the invariant test in `tests/Architecture/ModeErrorShapeTest.php` (8.10) keys on message-text uniformity, not on a numeric code. Each 8.8 tool emits `permission denied — mode `X` on {resource} `Y` requires `Z`.` matching the Entry / Category / Address conventions. This consolidates the wire-shape invariant under message-text uniformity for 8.10's `ModeErrorShapeTest`.
7. **No new permissions registered.** Every gate in 8.8 uses Craft-native permission strings (`saveEntries:*`, `deleteAssets:*`, `utility:queue-manager`).
8. **No idempotency cache on 8.8 modes.** Rationale: `apply` and `discard` are state transitions where the second call is naturally a no-op (the draft is gone or the canonical is already at the draft's state — calling again is detectable via "not found"). `manage_queue` actions are queue-row-keyed; the second call returns "job not found" because the row is gone. `fix_relations` / `prune_unused_assets` / `repair_propagation` are loop-driven over a query that's re-evaluated per call — re-running them simply finds zero broken rows the second time. `import` could benefit from idempotency keys but the dry-run-default-on contract is the safety belt; the LLM previews with `dryRun: true` first. **Adding idempotency to 8.8 is deferred to a future gate if real-world LLM friction emerges.** Builder does NOT use `IdempotencyTrait` in 8.8 — keeps the diff focused on the mode-unlock contract.

### Open technical questions — resolved against source

9. **Q A — Class names.** Resolved at top: `Diagnostics` (tool name `system_diagnostics`), `Audit` (tool name `content_audit`), `DraftsAndRevisions`, `ImportExport`. Cited file:line above.

10. **Q B — `drafts_and_revisions` apply/discard contract.**
    - `apply`: arg surface is `{mode: 'apply', id?: int, uid?: string, siteId?: int, siteHandle?: string}` — `id` or `uid` resolves the draft (with `drafts(true)`), the canonical is read via `$draft->getCanonical(anySite: true)`, permission is `saveEntries:{canonicalSectionUid}`. Calls `Craft::$app->getDrafts()->applyDraft($draft)` (`vendor/craftcms/cms/src/services/Drafts.php:284`). Returns `{success: true, mode: 'apply', appliedDraftId: int, canonicalId: int, canonicalUid: string}`.
    - `discard`: same arg surface. Permission resolution: `saveEntries:{canonicalSectionUid}` matching Craft's own `actionDeleteDraft()` at `vendor/craftcms/cms/src/controllers/ElementsController.php:2321-2367` (which calls `Elements::canDelete($draft, $user)`; the underlying `Entry::canDelete()` defers to `canSave()` on the canonical). Calls `Craft::$app->getElements()->deleteElement($draft, true)` (hard delete; matches Craft's controller). Returns `{success: true, mode: 'discard', discardedDraftId: int, canonicalId: int}`.

11. **Q C — `content_audit` fix-mode input contract.** Resolved: fix modes ride on the SAME query as the matching read mode. Same `volume` / `section` filter args. No two-call confirm-with-token shape — the LLM previews with the read mode, calls the fix mode with the same filters, gets the per-row outcome envelope. Mirrors `bulk_entries` row-outcome shape (`docs/plans/gate-8.7.md` Q9). Per-row envelope:
    ```
    {
      kind: "success" | "failure" | "skipped",
      // shape per fix mode:
      // fix_relations: {sourceId, targetId, fieldId, deletedRelationCount?, reason?}
      // prune_unused_assets: {id, uid, volumeUid, sizeFreed?, reason?}
      // repair_propagation: {canonicalId, sectionUid, sitesPropagated[]?, reason?}
    }
    ```
    `skipped.reason ∈ {"permission_denied" | "trashed" | "missing"}`; `requiredPermission?` echoes the missed permission on permission_denied. Top-level envelope mirrors `bulk_entries`: `{success, mode, total, processed, succeeded, failed, skipped, results}` minus the `cancelled` flag (no cancellation surface in 8.8). 8.9 will add the `cancelled` flag when the loop bodies become generators.

12. **Q D — `import_export.import` input contract.** Resolved against `_serializeEntry()` envelope at `src/tools/workflow/ImportExport.php:236-262`. The import payload is `{mode: 'import', payload: {format, entries: [...]}, dryRun?: bool, siteHandle?: string}` where `payload.entries[]` is the same shape `export` emits. Required per-entry fields: `uid` (the import key — used to resolve existing entries for update), `section`, `type`, `site` (entry-site context), `title`. Optional: `slug`, `enabled`, `enabledForSite`, `authorId`, `parentUid`, `postDate`, `expiryDate`, `fields`. **Default `dryRun: true`** (Decision 14 in gate-8.md). When `dryRun: true`, the tool validates each entry against the resolved section's field layout via `setFieldValues()` + `validate()` but does NOT call `saveElement()`. Top-level return: `{success, mode: 'import', dryRun, total, processed, succeeded, failed, skipped, results}` mirroring `bulk_entries`. Per-item result envelope:
    ```
    {
      kind: "success" | "failure" | "skipped",
      sourceUid: string,    // the uid from the import payload
      // success-only:
      id?: int,             // resolved-or-created entry id
      uid?: string,         // entry uid post-save (matches sourceUid)
      mode: "create" | "update",  // resolved per-item — was there an existing entry by uid?
      dryRun?: bool,
      // failure-only:
      errors: {handle: [messages]},  // Element::getErrors() shape
      // skipped-only:
      reason: "permission_denied" | "missing_section" | "missing_entry_type" | "missing_site",
      requiredPermission?: string,
    }
    ```
    Section/entry-type/site resolution happens per item from the payload's `section` / `type` / `site` handles. Resolution failures are `skipped`, not `failed`, because they're payload-shape problems the LLM can fix; validation failures are `failed` because they're per-field issues the LLM iterates on. The format-version check (`payload.format !== ImportExport::FORMAT_VERSION`) is a top-level `ToolException`, not a per-item skip — refusing a cross-major payload outright matches the export envelope's contract at `src/tools/workflow/ImportExport.php:99-101`.

13. **Q E — `system_diagnostics.manage_queue` input contract.** Resolved against `vendor/craftcms/cms/src/queue/Queue.php:289-376`. Sub-modes via nested `action`:
    - `action: 'retry'`, `jobId: string` → `Craft::$app->getQueue()->retry($jobId)`. Per `Queue::retry()` at `:289-294`, this clears the failed/reserved state and requeues.
    - `action: 'retry_all'` → `Craft::$app->getQueue()->retryAll()`. Bulk-requeues every failed job.
    - `action: 'release'`, `jobId: string` → `Craft::$app->getQueue()->release($jobId)`. Per `Queue::release()` at `:354-365`, deletes the job row entirely. This IS the cancel/delete action — `gate-8.md:43`'s "retry / cancel / release" is incorrect; the resolved sub-modes are `retry / retry_all / release / release_all`.
    - `action: 'release_all'` → `Craft::$app->getQueue()->releaseAll()`. Wipes the channel.
    No `id`/`uid` for the queue manager — queue rows use string ids (e.g. `"42"` for the file queue). Schema models `jobId` as `string`. Output envelope: `{success, mode: 'manage_queue', action, jobId?, affected: int, queueAfter: {waiting, delayed, reserved, failed, total}}`. `affected` is `1` for single-id actions, `n` for bulk actions (post-action `getTotalFailed()` delta). `queueAfter` reuses the existing `_queue()` totals shape so the LLM can confirm the state transition in one round-trip.

14. **Q F — `tools/list` invariants under mode-filtered schemas.** Resolved: the mode-filtered `tools/list` payload for a Free-edition install MUST show only the Free modes in the `inputSchema.properties.mode.enum`. The plugin's `shouldRegister()` is true on Free (these tools stay Free-registered), so the tool appears in `tools/list`; what `inputSchemaFor($user)` returns is the per-user schema. **Subtlety**: on stdio, `inputSchemaFor(null)` must return the FULL enum (every mode — Free and Pro) because stdio is trusted. On HTTP Free-edition with an admin user, `inputSchemaFor($admin)` must STRIP the Pro modes — because the install is Free, the modes don't exist regardless of permission. **This is the only place 8.8 has to consult `Cortex::getInstance()->is(EDITION_PRO)`** — the `inputSchemaFor()` override on each tool short-circuits to the Free enum when the install is Free, regardless of the resolved user.
    Test invariant: `Cortex::EDITION_FREE` + admin user + HTTP → `inputSchemaFor()` returns Free modes only. `Cortex::EDITION_PRO` + non-permitted user + HTTP → `inputSchemaFor()` returns Free modes only. `Cortex::EDITION_PRO` + admin + HTTP → full enum. `null` user (stdio) → full enum on any edition (trusted path).
    Implementation shape: `if (!Cortex::getInstance()->is(Cortex::EDITION_PRO, '>=')) { return $freeSchema; }` THEN the per-user filter walks the user's permissions. Stdio (`null`) returns the edition-gated full enum (full enum on Pro, Free enum on Free — stdio doesn't bypass the edition gate, only the permission gate).

15. **Q G — Mode-error envelope shape.** Resolved: the rich-format message text is the wire-level invariant, NOT a numeric JSON-RPC code on the response envelope. The actual on-the-wire shape is `{content: [{type:'text', text: 'permission denied — mode `apply` on section `posts` requires `saveEntries:{uid}`.'}], isError: true}` at `src/mcp/Server.php:1327-1335`. 8.10's `ModeErrorShapeTest` matches on message-text prefix `permission denied — mode `` and the per-tool resource pattern. Each 8.8 tool overrides `_buildPermissionDeniedMessage()` to emit:
    - `drafts_and_revisions`: `permission denied — mode `%s` on section `%s` requires `%s`.`
    - `content_audit`: `permission denied — mode `%s` on %s `%s` requires `%s`.` where `%s` is `section` | `volume` | `group` (the resource type carrying the UID — `repair_propagation` uses section, `prune_unused_assets` uses volume, `fix_relations` resolves per source element).
    - `import_export`: `permission denied — mode `import` on section `%s` requires `saveEntries:%s`.`
    - `system_diagnostics`: `permission denied — type `manage_queue` requires `utility:queue-manager`.` (no per-resource UID; the queue manager is a single global permission).

16. **Q H — Cancellation cooperation seam for 8.9.** Resolved: the fix-mode loop bodies in 8.8b are written as single `foreach` over a deferred-resolved iterable (`yield from` candidate). The loop bodies do NOT capture state into closures, do NOT accumulate intermediate state outside the `$results[]` array that's returned. Pattern (the seam 8.9 converts to a generator):
    ```php
    private function _fixRelations(array $arguments): array
    {
        $rows = $this->_resolveBrokenRelationsQuery($arguments);
        $results = [];
        $succeeded = 0; $failed = 0; $skipped = 0;
        foreach ($rows as $row) {
            $outcome = $this->_fixOneRelation($row);  // returns {kind, ...}
            $results[] = $outcome;
            match ($outcome['kind']) { /* ... bump counters */ };
        }
        return [/* envelope with $results, $succeeded, $failed, $skipped */];
    }
    ```
    8.9 converts `_fixRelations` to a `Generator` that yields progress frames between rows and returns the same envelope as the terminal value (mirrors `BulkEntries::stream()` shape). The `_fixOneRelation()` helper stays unchanged — it's the per-row mutation the streaming wrap drives. 8.8b builder writes `_fixOne*` helpers as independent methods (not inlined) so 8.9 doesn't have to refactor; the methods return the per-row envelope directly.

### Cross-cutting decisions

17. **`filterFor()` overrides — minimum-permission union check.** Per-tool `filterFor($user)` returns `true` when the user has at least one permission across the union of (Free modes + Pro modes). Because the Free modes are unconditionally accessible (read-only audit / read-only drafts / read-only import-export / read-only diagnostics types), every Free tool's `filterFor()` continues to return `true` for any HTTP user. **8.8 does NOT add a `filterFor()` override that hides the whole tool from non-permitted users** — that would regress the existing Free behaviour. The mode-gating is in `inputSchemaFor()` + `execute()` re-check, NOT in `filterFor()`. Locked.

18. **Permission resolution responsibility.** Each `_requiredPermissions($arguments)` method resolves the per-resource UID from the arguments. For `drafts_and_revisions.apply`/`.discard` this means loading the draft to resolve the canonical section UID — same shape as `entry.apply_draft` at `src/tools/content/Entry.php:602-638`. For `content_audit` fix modes the per-row UID is resolved INSIDE the loop (the per-row helper resolves the source element's section / volume); the top-level `_requiredPermissions()` returns the wildcard sentinel only (`saveEntries:*` / `deleteAssets:*`) for `filterFor()` use, and the in-loop call uses the resolved UID. The sentinel-reaches-`_assertPermission()` guard at `src/tools/PermissionedToolTrait.php:97-103` is honoured: per-tool implementations call `_assertPermission()` ONLY after resolving the per-resource UID.

19. **Audit fix-mode source/target resolution.** `_fixRelations()` reads broken-relation rows from `{{%relations}}`; for each row, the source element is loaded via `Element::find()->id($sourceId)->status(null)->site('*')->one()`. The section / volume / group UID comes from the source element. Per-row outcomes can therefore mix gates (an entry source → `saveEntries:{sectionUid}`; an asset source → `saveAssets:{volumeUid}`; a category source → `saveCategories:{groupUid}`). The fix action itself is consistent: delete the matching row in `{{%relations}}` via `Db::delete(Table::RELATIONS, $rowConditions)` — relation rows are not Craft elements. Field-side validation is bypassed (the source element isn't saved by the fix; only the orphan join row is removed). This mirrors how Craft's own `Elements::deleteRelationsBetween()` works.

20. **Hard caps on fix modes.** Per-mode row caps mirror the read-mode caps in `Audit`:
    - `fix_relations`: cap at `Audit::MAX_LIMIT` (1000) per call. Operator pages through. No `force` escape hatch — this matches the read-mode contract.
    - `prune_unused_assets`: cap at `Audit::MAX_LIMIT` (1000) per call. Same paging behaviour.
    - `repair_propagation`: cap at `Audit::PROPAGATION_GLOBAL_GAP_CAP` (5000) gaps per call. Same `truncatedSections` reporting as the read mode.
    Caps match the read modes intentionally — the LLM's preview produces N rows, the fix mode consumes the same N. No new caps surface.

21. **`import_export.import` dry-run scope.** Dry-run validates each item against the target section's field layout AND resolves section/entry-type/site references AND runs the permission check. It does NOT call `Craft::$app->getElements()->saveElement()`. The response envelope's per-item `kind` reflects what would have happened: `success` (would-save), `failure` (validation), `skipped` (resolution miss or permission denied). Re-running with `dryRun: false` produces the same shape with actual writes. **Idempotency note**: 8.8 does not cache the dry-run result. The LLM is expected to re-run with `dryRun: false` immediately after a clean preview; caching the dry-run preview adds no operational value because the live re-run does the writes regardless.

---

## File map

**New (8.8a):**
- `tests/Tools/Workflow/DraftsAndRevisionsApplyDiscardTest.php` — ~150 LoC, ~8 cases.
- `tests/Tools/System/DiagnosticsManageQueueTest.php` — ~120 LoC, ~6 cases.

**New (8.8b):**
- `tests/Tools/Workflow/AuditFixModesTest.php` — ~250 LoC, ~15 cases.
- `tests/Tools/Workflow/ImportExportImportTest.php` — ~200 LoC, ~12 cases.

**Modified (8.8a):**
- `src/tools/workflow/DraftsAndRevisions.php` — schema enum extension; new `_apply()` / `_discard()` private methods; new `inputSchemaFor()` override; new `_requiredPermissions()` method; `PermissionedToolTrait` use statement; `_buildPermissionDeniedMessage()` override. ~240 LoC delta.
- `src/tools/system/Diagnostics.php` — schema `type` enum extension; new `jobId` / `action` schema fields; new `_manageQueue()` private method; new `inputSchemaFor()` override; new `_requiredPermissions()` method; `PermissionedToolTrait` use statement; `_buildPermissionDeniedMessage()` override. ~170 LoC delta.

**Modified (8.8b):**
- `src/tools/workflow/Audit.php` — schema enum extension; new `_fixRelations()` / `_pruneUnusedAssets()` / `_repairPropagation()` private methods plus `_fixOneRelation()` / `_pruneOneAsset()` / `_repairOnePropagation()` per-row helpers (the seam 8.9 streams); new `inputSchemaFor()` override; new `_requiredPermissions()` method; `PermissionedToolTrait` use statement; `_buildPermissionDeniedMessage()` override. ~530 LoC delta.
- `src/tools/workflow/ImportExport.php` — schema `mode` enum extension; new `payload` / `dryRun` schema fields; new `_import()` private method; new per-item helpers (`_resolveImportTarget`, `_importOneEntry`); new `inputSchemaFor()` override; new `_requiredPermissions()` method; `PermissionedToolTrait` use statement; `_buildPermissionDeniedMessage()` override. ~320 LoC delta.

**Untouched (verify, do not modify):**
- `src/services/Tools.php` — tools stay Free-registered; no new tool wiring.
- `src/tools/AbstractTool.php`, `src/tools/PermissionedToolTrait.php`, `src/tools/ProToolTrait.php` — consumed as-is.
- `src/tools/ToolException.php` — consumed as-is.
- `src/mcp/Server.php`, `src/mcp/transport/*.php`, `src/controllers/McpController.php` — consumed as-is.
- `tests/Architecture/EditionGatingTest.php` — Pro-tool list unchanged (these four tools are NOT Pro tools).
- Existing tests for the four tools — unchanged. The Free-mode behaviour MUST remain bit-identical; the new test files target the new modes only.

---

## Sub-gate 8.8a — `drafts_and_revisions` apply/discard + `system_diagnostics` manage_queue

### Tool spec — `drafts_and_revisions` (new modes)

| Mode | Required args | Optional args | Permission |
|---|---|---|---|
| `apply` (NEW) | mode, id OR uid | siteId / siteHandle | `saveEntries:{canonicalSectionUid}` |
| `discard` (NEW) | mode, id OR uid | siteId / siteHandle | `saveEntries:{canonicalSectionUid}` |

#### Output envelopes

`apply`:
```
{
  success: true,
  mode: "apply",
  appliedDraftId: int,
  canonicalId: int,
  canonicalUid: string,
  siteHandle: string
}
```

`discard`:
```
{
  success: true,
  mode: "discard",
  discardedDraftId: int,
  canonicalId: int,
  canonicalUid: string
}
```

#### `inputSchemaFor()` rewrite logic

```
if !Cortex::is(EDITION_PRO):
    modes = ['list_drafts', 'list_revisions', 'compare']  # Free
elif user === null OR user->admin:
    modes = ['list_drafts', 'list_revisions', 'compare', 'apply', 'discard']
else:
    modes = ['list_drafts', 'list_revisions', 'compare']
    if user has saveEntries on at least one section:
        modes += ['apply', 'discard']
return schema with properties.mode.enum = modes
```

### Tool spec — `system_diagnostics` (new type)

| Type | Required args | Optional args | Permission |
|---|---|---|---|
| `manage_queue` (NEW) | type, action | jobId (string, required for retry / release), limit | `utility:queue-manager` |

#### `manage_queue` sub-actions

| Action | Required args | API call | Returns |
|---|---|---|---|
| `retry` | jobId | `Craft::$app->getQueue()->retry($jobId)` | `{affected: 1}` |
| `retry_all` | — | `Craft::$app->getQueue()->retryAll()` | `{affected: int}` (post-call `getTotalFailed()` delta) |
| `release` | jobId | `Craft::$app->getQueue()->release($jobId)` | `{affected: 1}` |
| `release_all` | — | `Craft::$app->getQueue()->releaseAll()` | `{affected: int}` (post-call `getTotalJobs()` delta) |

#### Output envelope

```
{
  success: true,
  type: "manage_queue",
  action: "retry" | "retry_all" | "release" | "release_all",
  jobId?: string,
  affected: int,
  queueAfter: {waiting, delayed, reserved, failed, total}  # reuse _queue() shape
}
```

#### `inputSchemaFor()` rewrite logic

```
if !Cortex::is(EDITION_PRO):
    types = ['logs', 'last_error', 'deprecations', 'queue', 'project_config_diff']  # Free
elif user === null OR user->admin:
    types = [...Free, 'manage_queue']
else:
    types = [...Free]
    if user can 'utility:queue-manager':
        types += ['manage_queue']
return schema with properties.type.enum = types
```

### Implementation phases (layered build-verify) — 8.8a

#### Phase 1 — `DraftsAndRevisions` scaffolding + inputSchemaFor override

- **Modify** `src/tools/workflow/DraftsAndRevisions.php` — add `use PermissionedToolTrait;`, `use craft\elements\User;`, extend the enum in `getInputSchema()` to `['list_drafts', 'list_revisions', 'compare', 'apply', 'discard']`, add `inputSchemaFor(?User $user = null): array` override per the logic above.
- **Write** test cases (`DraftsAndRevisionsApplyDiscardTest`):
  - `inputSchemaFor(null)` on Free returns Free enum only.
  - `inputSchemaFor(null)` on Pro returns full enum (stdio trusted).
  - `inputSchemaFor($admin)` on Pro returns full enum.
  - `inputSchemaFor($savePostsOnly)` on Pro returns full enum (has at least one save permission).
  - `inputSchemaFor($noSavePerms)` on Pro returns Free enum.
- **Verify gate**: `ddev exec vendor/bin/pest --filter='DraftsAndRevisionsApplyDiscardTest::inputSchemaFor'` green.

#### Phase 2 — `DraftsAndRevisions::_apply()` + `_discard()` + `_requiredPermissions()` + permission-denied message

- **Write** the two new private methods. Both share a `_resolveDraft($arguments): Entry` helper that loads via `Entry::find()->drafts(true)->id|uid()->siteId|*()->one()` and throws `ToolException` on miss. `_apply()` resolves canonical, `_assertPermission()`, then `Craft::$app->getDrafts()->applyDraft($draft)` (`vendor/craftcms/cms/src/services/Drafts.php:284`). `_discard()` resolves canonical, `_assertPermission()`, then `Craft::$app->getElements()->deleteElement($draft, true)` (matches `ElementsController::actionDeleteDraft()` at `vendor/craftcms/cms/src/controllers/ElementsController.php:2321-2367`).
- **Write** `_requiredPermissions($arguments)`. Returns `['saveEntries:*']` sentinel when no draft id/uid is supplied (filterFor probing); when an id/uid is present, loads the draft, resolves canonical, returns `["saveEntries:{$canonicalSection->uid}"]`. Throws `ToolException` if the draft isn't found OR the canonical is sectionless (matches `entry.apply_draft` at `src/tools/content/Entry.php:629-636`).
- **Write** `_buildPermissionDeniedMessage()` override — `permission denied — mode `%s` on section `%s` requires `%s`.`.
- **Modify** `execute()` `match` to add `'apply' => $this->_apply($arguments)` and `'discard' => $this->_discard($arguments)` arms.
- **Write** test cases:
  - `apply` happy path: admin applies a draft, canonical updates, response envelope correct.
  - `apply` permission-denied: non-permitted user gets `ToolException` with rich message; message matches the regex `^permission denied — mode `apply` on section `\w+` requires `saveEntries:\w+`\.$`.
  - `apply` missing draft: `ToolException`.
  - `discard` happy path: admin discards, draft row gone, canonical untouched.
  - `discard` permission-denied: same shape as apply.
  - Free-edition install (Cortex Free): calling `execute(mode: 'apply', ...)` returns `ToolException` (the mode is no longer in the enum, the dispatcher hands the args to `execute()` regardless because Craft can't validate dispatched JSON against an enum). Need a sanity check in `execute()` itself: when `Cortex::is(EDITION_PRO) === false` AND mode is `apply` / `discard`, throw a "mode unavailable on this edition" `ToolException`.
- **Verify gate**: `ddev exec vendor/bin/pest --filter='DraftsAndRevisionsApplyDiscardTest'` green.

#### Phase 3 — `Diagnostics` scaffolding + inputSchemaFor override

- **Modify** `src/tools/system/Diagnostics.php` — add `use PermissionedToolTrait;`, `use craft\elements\User;`, extend the type enum to include `'manage_queue'`, add new schema fields `action: Schema::string()->enum(['retry', 'retry_all', 'release', 'release_all'])` and `jobId: Schema::string()`. Add `inputSchemaFor(?User $user = null): array` override.
- **Write** test cases (`DiagnosticsManageQueueTest`):
  - `inputSchemaFor(null)` on Free returns Free types only.
  - `inputSchemaFor($admin)` on Pro returns full types.
  - `inputSchemaFor($queueManagerUser)` on Pro returns full types (`utility:queue-manager` permission granted).
  - `inputSchemaFor($plainUser)` on Pro returns Free types only.
- **Verify gate**: `ddev exec vendor/bin/pest --filter='DiagnosticsManageQueueTest::inputSchemaFor'` green.

#### Phase 4 — `Diagnostics::_manageQueue()` + `_requiredPermissions()` + permission-denied message

- **Write** `_manageQueue()` private method dispatching on `action`. For `retry` / `release`, require `jobId` (string) and call the matching `Queue` API. For `retry_all` / `release_all`, call the matching bulk API. Capture pre/post counts via `getTotalFailed()` / `getTotalJobs()` for the `affected` delta on bulk actions.
- **Write** `_requiredPermissions($arguments)` returning `['utility:queue-manager']` — single global permission, no per-resource UID.
- **Write** `_buildPermissionDeniedMessage()` override — `permission denied — type `manage_queue` requires `utility:queue-manager`.`.
- **Modify** `execute()` `match` to add `'manage_queue' => $this->_manageQueue($arguments)` arm.
- **Write** test cases:
  - `retry` happy path: a failed job is requeued, `affected: 1`, `queueAfter.failed` decremented.
  - `release` happy path: a queued job is deleted, `affected: 1`.
  - `retry_all` happy path: N failed jobs requeued, `affected: N`.
  - `release_all` happy path: queue wiped, `affected: N`.
  - Missing `jobId` on `retry` → `ToolException` "jobId is required".
  - Permission denied → `ToolException` matching the regex `^permission denied — type `manage_queue` requires `utility:queue-manager`\.$`.
- **Verify gate**: `ddev exec vendor/bin/pest --filter='DiagnosticsManageQueueTest'` green.

#### Phase 5 — 8.8a quality gates

- **Verify**: `ddev exec vendor/bin/pest --filter='DraftsAndRevisionsApplyDiscardTest|DiagnosticsManageQueueTest'` — focused suite green.
- **Verify**: `ddev composer phpstan` clean.
- **Verify**: `ddev composer check-cs` clean.
- **Verify**: `ddev exec vendor/bin/pest --filter='DraftsAndRevisionsTest|DiagnosticsTest'` — existing tests still green (Free behaviour preserved).
- **Commit**: `feat(workflow): Gate 8.8a — drafts_and_revisions apply/discard + system_diagnostics manage_queue`.

---

## Sub-gate 8.8b — `content_audit` fix modes + `import_export.import`

### Tool spec — `content_audit` (new modes)

| Mode | Required args | Optional args | Permission (per-row) |
|---|---|---|---|
| `fix_relations` (NEW) | mode | limit, offset | per-row source-element: `saveEntries:{sectionUid}` / `saveCategories:{groupUid}` / `saveAssets:{volumeUid}` |
| `prune_unused_assets` (NEW) | mode | volume, limit, offset | per-row `deleteAssets:{volumeUid}` |
| `repair_propagation` (NEW) | mode | section, limit, offset | per-row `saveEntries:{sectionUid}` |

#### Output envelope

```
{
  success: true | false,
  mode: "fix_relations" | "prune_unused_assets" | "repair_propagation",
  total: int,            // rows the matching read mode would have returned
  processed: int,        // rows visited
  succeeded: int,
  failed: int,
  skipped: int,
  results: [
    {kind, ...mode-specific shape...}
  ]
}
```

Per-row shapes (mirror gate-8.7.md Q9):

- `fix_relations`: success → `{kind: 'success', sourceId, targetId, fieldId, deletedRelationCount: 1}`. failure → `{kind: 'failure', sourceId, targetId, fieldId, reason}`. skipped → `{kind: 'skipped', sourceId, targetId, fieldId, reason, requiredPermission?}`.
- `prune_unused_assets`: success → `{kind: 'success', id, uid, volumeUid, sizeFreed}`. failure → `{kind: 'failure', id, uid, reason}`. skipped → `{kind: 'skipped', id, uid, volumeUid, reason, requiredPermission?}`.
- `repair_propagation`: success → `{kind: 'success', canonicalId, sectionUid, sitesPropagated: [siteUid, ...]}`. failure → `{kind: 'failure', canonicalId, sectionUid, reason, validationErrors?}`. skipped → `{kind: 'skipped', canonicalId, sectionUid, reason, requiredPermission?}`.

#### `inputSchemaFor()` rewrite logic

```
if !Cortex::is(EDITION_PRO):
    modes = ['relations', 'unused_assets', 'propagation']  # Free
elif user === null OR user->admin:
    modes = [...Free, 'fix_relations', 'prune_unused_assets', 'repair_propagation']
else:
    modes = [...Free]
    if user has saveEntries OR saveCategories OR saveAssets on any:
        modes += ['fix_relations']
    if user has deleteAssets on any volume:
        modes += ['prune_unused_assets']
    if user has saveEntries on any section:
        modes += ['repair_propagation']
return schema with properties.mode.enum = modes
```

### Tool spec — `import_export` (new mode)

| Mode | Required args | Optional args | Permission (per-item) |
|---|---|---|---|
| `import` (NEW) | mode, payload | dryRun (default `true`), siteHandle | per-item `saveEntries:{targetSectionUid}` |

#### Input schema additions

```
payload: Schema::object()->additionalProperties(true)  // {format, entries: [...]}
dryRun: Schema::boolean()->description('Defaults to true. Pass `false` to actually write.')
```

#### Output envelope — see Q D resolution above

#### `inputSchemaFor()` rewrite logic

```
if !Cortex::is(EDITION_PRO):
    modes = ['export']  # Free
elif user === null OR user->admin:
    modes = ['export', 'import']
else:
    modes = ['export']
    if user has saveEntries on any section:
        modes += ['import']
return schema with properties.mode.enum = modes
```

### Implementation phases (layered build-verify) — 8.8b

#### Phase 1 — `Audit` scaffolding + inputSchemaFor override

- **Modify** `src/tools/workflow/Audit.php` — add `use PermissionedToolTrait;`, `use craft\elements\User;`, extend the mode enum to `['relations', 'unused_assets', 'propagation', 'fix_relations', 'prune_unused_assets', 'repair_propagation']`, add `inputSchemaFor(?User $user = null): array` override.
- **Write** test cases (`AuditFixModesTest`):
  - inputSchemaFor matrix per the rules above (5 cases).
- **Verify gate**: `ddev exec vendor/bin/pest --filter='AuditFixModesTest::inputSchemaFor'` green.

#### Phase 2 — `Audit::_fixRelations()` + per-row helper

- **Write** `_fixRelations(array $arguments): array`. Resolves the same broken-relation query as `_relations()` (refactor: extract `_brokenRelationsQuery()` shared between the read and fix mode). Loops via foreach, per row calls `_fixOneRelation(array $row): array` which resolves the source element, runs `_assertPermission()` per-row (with sectionUid/groupUid/volumeUid resolved from the source element type), executes `Db::delete(Table::RELATIONS, ['sourceId' => ..., 'targetId' => ..., 'fieldId' => ..., 'sortOrder' => ...])`, returns the per-row envelope. Top-level aggregates and returns.
- **Write** `_requiredPermissions($arguments)` returning the wildcard sentinel `['saveEntries:*']` for filterFor probing (per-row resolution happens inside the loop).
- **Write** test cases:
  - Admin fix_relations against 5 broken rows → all `kind: 'success'`, `deletedRelationCount: 1` each, relations table shows 0 broken rows after.
  - Permission-skipped: caller with `saveEntries:posts` only against rows mixing posts and news sources → news rows are `kind: 'skipped', reason: 'permission_denied', requiredPermission: 'saveEntries:{newsUid}'`.
  - Source element missing (deleted between query and loop) → `kind: 'skipped', reason: 'missing'`.
  - Idempotent re-run: second call against same query yields 0 results (rows already gone).
- **Verify gate**: `ddev exec vendor/bin/pest --filter='AuditFixModesTest::fix_relations'` green.

#### Phase 3 — `Audit::_pruneUnusedAssets()` + per-row helper

- **Write** `_pruneUnusedAssets(array $arguments): array`. Resolves the same unused-asset query as `_unusedAssets()` (refactor: extract `_unusedAssetsQuery()`). Loops, per row calls `_pruneOneAsset(Asset $asset): array` which calls `Craft::$app->getElements()->canDelete($asset, $caller)` for permission + `_assertPermission(['volumeUid' => $asset->getVolume()->uid, 'mode' => 'prune_unused_assets'])` for the rich-error denial message, then `Craft::$app->getElements()->deleteElement($asset)`. Captures `$asset->size` before delete for `sizeFreed`.
- **Write** test cases:
  - Admin prune against 10 unused assets → all `kind: 'success'`, `sizeFreed` populated.
  - Caller with `deleteAssets:uploads` only against assets in `uploads` + `images` → `images` rows are `kind: 'skipped', requiredPermission: 'deleteAssets:{imagesUid}'`.
  - Volume filter narrows: `volume: 'uploads'` only iterates uploads assets.
  - Asset that became referenced between query and loop → call `canDelete()` check should still pass since the asset is still unrelated to the caller's permissions (but a "used" check is the read mode's responsibility, not the fix mode's). Doc this in the tool PHPDoc: race conditions on the read→fix delay are the caller's responsibility.
- **Verify gate**: `ddev exec vendor/bin/pest --filter='AuditFixModesTest::prune_unused_assets'` green.

#### Phase 4 — `Audit::_repairPropagation()` + per-row helper

- **Write** `_repairPropagation(array $arguments): array`. Reuses the gap-detection logic from `_propagation()` (refactor: extract `_collectPropagationGaps()`). For each gap, loads the canonical entry, runs `_assertPermission(['sectionUid' => $section->uid])`, then for each missing site: `$entry->setEnabledForSite([$siteId => true])` and `Craft::$app->getElements()->saveElement($entry, propagate: true)`. Captures which sites were actually propagated (Craft may collapse propagation if site enablement matches existing rows).
- **Write** test cases:
  - Admin repair_propagation against 3 gaps → all `kind: 'success'`, `sitesPropagated` lists the previously-missing site UIDs.
  - Validation failure on a required custom field absent in the new site → `kind: 'failure', validationErrors: {handle: [messages]}`.
  - Permission-denied per-row → `kind: 'skipped'`.
  - Section filter narrows scope.
  - Idempotent re-run: 0 gaps the second time.
- **Verify gate**: `ddev exec vendor/bin/pest --filter='AuditFixModesTest::repair_propagation'` green.

#### Phase 5 — `Audit::execute()` dispatch + permission-denied message

- **Modify** `execute()` `match` to add the three new arms.
- **Write** `_buildPermissionDeniedMessage()` override — `permission denied — mode `%s` on %s `%s` requires `%s`.` where the resource type is resolved from arguments (section / volume / group).
- **Write** test cases:
  - Full envelope smoke test for each mode.
  - Cross-edition: Free install + admin user + `execute(mode: 'fix_relations')` → `ToolException` "mode unavailable on this edition".
- **Verify gate**: `ddev exec vendor/bin/pest --filter='AuditFixModesTest'` green.

#### Phase 6 — `ImportExport` scaffolding + inputSchemaFor override

- **Modify** `src/tools/workflow/ImportExport.php` — add `use PermissionedToolTrait;`, `use craft\elements\User;`, extend the mode enum to `['export', 'import']`, add new schema fields `payload`, `dryRun`. Add `inputSchemaFor(?User $user = null): array` override.
- **Write** test cases (`ImportExportImportTest`):
  - inputSchemaFor matrix (5 cases).
- **Verify gate**: `ddev exec vendor/bin/pest --filter='ImportExportImportTest::inputSchemaFor'` green.

#### Phase 7 — `ImportExport::_import()` + per-item helpers + permission-denied message

- **Write** `_import(array $arguments): array`. Validates top-level: `payload.format === FORMAT_VERSION` (throw `ToolException` on mismatch), `payload.entries` is array. Default `dryRun: true`. Loops per item via `_importOneEntry(array $item, bool $dryRun): array`:
  - `_resolveImportTarget($item)` resolves section by handle, entry type by handle, site by handle. Returns `null` (skipped) when any resolution fails.
  - `_assertPermission(['sectionUid' => $section->uid, 'mode' => 'import'])`.
  - Resolve existing entry by `uid` (item's `uid`). If found → update mode; else → create mode.
  - Build/load `EntryElement`. Apply attributes (`title`, `slug`, `enabled`, `enabledForSite`, `authorId`, `parentId` resolved from `parentUid`, `postDate`, `expiryDate`). Apply fields via `_applyFields($element, $item)`.
  - If `dryRun`: run `$element->validate()`, return per-item envelope without saving. If validation fails → `kind: 'failure', errors: $element->getErrors()`. Else → `kind: 'success', mode: 'create'|'update', dryRun: true`.
  - If not dryRun: `Craft::$app->getElements()->saveElement($element, runValidation: true)`. False → `kind: 'failure', errors: $element->getErrors()`. True → `kind: 'success', mode: ..., id: $element->id, uid: $element->uid`.
- **Write** `_requiredPermissions($arguments)` returning `['saveEntries:*']` sentinel for filterFor probing (per-item resolution happens inside the loop).
- **Write** `_buildPermissionDeniedMessage()` override — `permission denied — mode `import` on section `%s` requires `saveEntries:%s`.`.
- **Modify** `execute()` `match` to add `'import' => $this->_import($arguments)` arm.
- **Write** test cases:
  - Round-trip: export a section (Free mode), feed the envelope to import (dry-run) → all entries `kind: 'success', dryRun: true`, no DB writes.
  - Round-trip with `dryRun: false`: re-import overwrites by uid (update mode for existing entries).
  - Create mode: an item with a uid not in the DB → new entry created, `kind: 'success', mode: 'create'`.
  - Validation failure: required field absent → `kind: 'failure', errors: {...}`.
  - Permission denied: caller without `saveEntries:{targetSection}` → `kind: 'skipped', reason: 'permission_denied', requiredPermission: 'saveEntries:{uid}'`.
  - Section resolution miss: payload references a section that doesn't exist on this install → `kind: 'skipped', reason: 'missing_section'`.
  - Format-version mismatch: top-level `ToolException` with message naming the expected vs actual format.
  - Free-edition install + admin + `execute(mode: 'import', ...)` → `ToolException` "mode unavailable on this edition".
- **Verify gate**: `ddev exec vendor/bin/pest --filter='ImportExportImportTest'` green.

#### Phase 8 — 8.8b quality gates

- **Verify**: `ddev exec vendor/bin/pest --filter='AuditFixModesTest|ImportExportImportTest'` — focused suite green.
- **Verify**: `ddev composer phpstan` clean.
- **Verify**: `ddev composer check-cs` clean.
- **Verify**: `ddev exec vendor/bin/pest --filter='AuditTest|ImportExportTest'` — existing tests still green (Free behaviour preserved).
- **Verify**: `ddev exec vendor/bin/pest` — full suite green (baseline 833 → 833 + ~41 new = ~874 passing).
- **Commit**: `feat(workflow): Gate 8.8b — content_audit fix modes + import_export import`.

---

## Test scope

### tests/Tools/Workflow/DraftsAndRevisionsApplyDiscardTest.php (~8 cases)

`inputSchemaFor`:
- Free + null → Free enum only.
- Free + admin → Free enum only.
- Pro + null → full enum.
- Pro + admin → full enum.
- Pro + non-permitted → Free enum only.

`apply`:
- Admin happy path: round-trip, response envelope correct, canonical updated.
- Permission denied: rich message matches regex.
- Missing draft → `ToolException`.

`discard`:
- Admin happy path: round-trip, draft row gone via `deleteElement($draft, true)`, canonical untouched.
- Permission denied: rich message matches regex.

Free-edition install + Pro mode dispatch: `ToolException` "mode unavailable on this edition".

### tests/Tools/System/DiagnosticsManageQueueTest.php (~6 cases)

`inputSchemaFor`: matrix (5 cases — Free / Pro+admin / Pro+queueManagerUser / Pro+plainUser / null).

`manage_queue`:
- `retry` happy path against a failed job.
- `release` happy path against a queued job.
- `retry_all` against N failed jobs.
- `release_all` wipes channel.
- Missing `jobId` on `retry` → `ToolException`.
- Permission denied: rich message matches regex.

### tests/Tools/Workflow/AuditFixModesTest.php (~15 cases)

`inputSchemaFor`: matrix (5 cases).

`fix_relations`: happy path (admin, 5 rows), permission-skipped (mixed sources), source missing, idempotent re-run.

`prune_unused_assets`: happy path (admin, 10 assets), permission-skipped (mixed volumes), volume filter narrows scope.

`repair_propagation`: happy path (admin, 3 gaps), validation failure, permission-skipped, section filter, idempotent re-run.

### tests/Tools/Workflow/ImportExportImportTest.php (~12 cases)

`inputSchemaFor`: matrix (5 cases).

`import`:
- Round-trip dry-run (export → import preview).
- Round-trip live (dry-run false, second pass updates by uid).
- Create new entry (uid absent in DB).
- Validation failure.
- Permission denied (per-item skip).
- Section resolution miss.
- Format-version mismatch (top-level `ToolException`).
- Free-edition install + Pro mode dispatch.

---

## Verification gates

Layered build-verify cadence per phase above. Final gate sequence (after each sub-gate):

1. `ddev exec vendor/bin/pest --filter='<sub-gate filter>'` — focused suite green.
2. `ddev composer phpstan` — level 8 clean.
3. `ddev composer check-cs` — ECS clean.
4. `ddev exec vendor/bin/pest` — full suite green (baseline 833).

---

## Manual verification

After the builder reports done on each sub-gate, the operator runs these against a Pro install in the playground:

### After 8.8a

1. **Free install**: `system_diagnostics` schema lists Free types only. `drafts_and_revisions` schema lists Free modes only. Calling `tools/call drafts_and_revisions mode=apply ...` returns `isError: true` with the "mode unavailable on this edition" message.
2. **Pro install, admin, HTTP**: `tools/list` for `drafts_and_revisions` shows the full enum. `tools/call mode=apply id=<draftId>` round-trips; canonical updated. `tools/call mode=discard id=<draftId>` removes the draft.
3. **Pro install, non-permitted user, HTTP**: `tools/list` for `drafts_and_revisions` shows Free modes only. `tools/call mode=apply ...` returns `isError: true` with the rich-format message.
4. **Pro install, admin, HTTP**: `tools/list` for `system_diagnostics` shows `manage_queue` in the type enum. Create a failing test job (`ddev craft queue/run` with a synthetic failure), then `tools/call system_diagnostics type=manage_queue action=retry jobId=<id>` — queue stats reflect the retry.
5. **Pro install, user with `utility:queue-manager`, HTTP**: same as admin path for `manage_queue`.
6. **Pro install, user without `utility:queue-manager`, HTTP**: `type=manage_queue` absent from schema. `tools/call ... type=manage_queue` returns `isError: true`.
7. **stdio (any edition)**: `tools/list` shows the full enum on Pro, Free enum on Free (edition gate still applies). Direct stdio call to `mode=apply` works on Pro.

### After 8.8b

8. **Free install**: `content_audit` and `import_export` schemas list Free modes only. Pro modes return "mode unavailable on this edition" if dispatched directly.
9. **Pro install, admin, HTTP**: `content_audit mode=fix_relations` against the broken-relations report from `mode=relations` — rows fixed, response envelope shows per-row outcomes.
10. **Pro install, admin, HTTP**: `content_audit mode=prune_unused_assets volume=uploads` deletes unreferenced uploads; `sizeFreed` aggregates in the response.
11. **Pro install, admin, HTTP**: `content_audit mode=repair_propagation section=blog` force-resaves missing-site entries; `sitesPropagated` lists per-row sites.
12. **Pro install, admin, HTTP**: `import_export mode=export section=blog` → capture envelope. Repeat with `mode=import payload=<envelope> dryRun=true` → preview. Repeat with `dryRun=false` → updates by uid.
13. **Pro install, restricted user, HTTP**: `content_audit` fix modes show only those reachable per the user's permissions; per-row skips emit the `requiredPermission` field.
14. **Pro install, restricted user, HTTP**: `import_export mode=import` against a section the user can't write → all items `kind: 'skipped', reason: 'permission_denied'`.
15. **Idempotency**: re-run any fix mode immediately after a clean pass — second run returns 0 results (rows already fixed / pruned / propagated).

---

## Risks / open follow-ups

- **`drafts_and_revisions.apply` duplicates `entry.apply_draft`.** The existing `entry` Pro tool already exposes `mode=apply_draft` at `src/tools/content/Entry.php:602-653` with the same semantics. 8.8a's `drafts_and_revisions.apply` is a second surface for the same operation. **Resolution**: ship both. They live under different tool names and the LLM's tool-selection ergonomics differ — `drafts_and_revisions.apply` is the natural choice after listing drafts via `list_drafts`; `entry.apply_draft` is the natural choice after creating a draft via `entry.create` then editing. Documented in both tools' PHPDocs as deliberately overlapping. No code is shared because the two tools live under different namespaces and class hierarchies; extracting to a `DraftApplyHelper` is a follow-up.
- **`gate-8.md:43` lists "retry / cancel / release" sub-modes** but Craft's `Queue` has no `cancel()`. `release` IS the cancel. The plan resolves to `retry / retry_all / release / release_all`. Flagged as a contradiction between gate-8.md spec and Craft source; the source wins. **Builder must NOT add a `cancel` sub-mode.**
- **`gate-8.md:58` references JSON-RPC code `-32002`** but the actual wire shape is `{content: [{type: text, text}], isError: true}` with no numeric code on the response envelope. The invariant becomes message-text uniformity. 8.10's `ModeErrorShapeTest` matches on message-text prefix, not on a numeric code field. **Builder MUST NOT add a numeric code field to the error envelope.** The existing shape at `src/mcp/Server.php:1327-1335` is consumed as-is.
- **`content_audit` race conditions** between read mode and fix mode: the broken-relations rows may be different on the second call if Craft GC ran or another process touched the relations table. The fix mode tolerates missing rows (`kind: 'skipped', reason: 'missing'`). Documented in the tool PHPDoc.
- **`prune_unused_assets` does not re-check usage** at fix time. If an asset became referenced between the read query and the fix loop, it gets deleted anyway. Mitigation: the read mode emits the asset list right before the fix, and the operator confirms — LLMs running the read-then-fix sequence inherit the responsibility. Documented as a known race.
- **`repair_propagation` validation failure on a required field absent in the new site** is a true blocker — the LLM can't fix it from the import surface (the per-site field-layout schema may differ). Per-row `kind: 'failure'` carries the validation errors; the operator addresses by setting site-specific default values manually.
- **`import_export.import` default-true dry-run** is documented in the tool PHPDoc and the input schema description. LLMs reading the schema description SHOULD preview first. The dry-run preview has no idempotency cache, so re-running the live pass is the LLM's responsibility.
- **8.9 streaming dependency.** 8.8b writes the fix-mode loop bodies in a shape 8.9 can convert to `Generator` returns without refactoring helpers. The `_fixOne*` / `_pruneOneAsset` / `_repairOnePropagation` / `_importOneEntry` helpers stay unchanged; only the outer loop method swaps `array` return for `Generator` return + terminal envelope. Builder for 8.8b SHOULD NOT inline the per-row helpers into the outer loop — that breaks 8.9's conversion path.

---

## Out of scope

- **Streaming for the per-row loop modes** — 8.9.
- **Cancellation cooperation in the loop bodies** — 8.9 (cancellation surface lands with the streaming wrap).
- **Idempotency caching on 8.8 modes** — deferred per locked decision 8.
- **CP UI for fix-mode preview-and-confirm** — Gate 9.
- **Bulk queue actions on filtered subsets** (`release` with a filter like "all failed older than 24h") — future gate.
- **Importing other element types** (assets, categories) via `import_export` — 8.8 ships entry imports only; the export tool already only emits entries.
- **Cross-environment import with field-handle remapping** — future gate. 8.8 requires identical handles on both sides.
- **`-32002` numeric code on the JSON-RPC envelope** — the existing `isError: true` shape is the locked wire format. The numeric code is a documentation convention only.
- **A new `cancel` sub-mode on `manage_queue`** — Craft has no `Queue::cancel()`. `release` IS the cancel.
