# Gate 8.5 — `users` Pro tool + PII gating

Internal sub-plan for the builder. Parent: [`docs/plans/gate-8.md`](./gate-8.md) §8.5 (lines 234–260). Locks the open questions surfaced in the planner brief on 2026-05-15. Scope is **users tool only** — the custom-skills element type is decoupled and lives in a separate future gate (storage decision resolved: PC for element-type registration, DB for elements). Locked decision 15 of gate-8.md is therefore settled and out of scope here.

Source files consulted (file:line where relevant):
- `src/tools/content/Address.php` — canonical Pro-tool pattern.
- `src/tools/AbstractTool.php`, `src/tools/PermissionedToolTrait.php`, `src/tools/IdempotencyTrait.php`, `src/tools/ProToolTrait.php`.
- `src/services/Tools.php::_buildRegistry()` (line 296+).
- `src/models/Settings.php` lines 92–114 — `$userCustomFieldAllowlist` is already declared (lands in 8.1).
- `tests/Architecture/EditionGatingTest.php` — append `Users::class` to `_cortex_pro_tool_classes()`.
- `tests/Pest.php` — `cortex_with_edition()` helper.
- `tests/Tools/Content/AddressTest.php` — fixture pattern.
- Craft 5: `vendor/craftcms/cms/src/elements/User.php`, `vendor/craftcms/cms/src/services/UserPermissions.php`, `vendor/craftcms/cms/src/controllers/UsersController.php`, `vendor/craftcms/cms/src/elements/db/UserQuery.php`, `vendor/craftcms/cms/src/elements/actions/DeleteUsers.php`.

---

## Locked decisions

### Carried from the brief (8 reaffirmed)

1. **Per-target authorization via `Elements::canSave / canView / canDelete`.** Same dispatch pattern as `Address`. **BUT** — see open question 2 below — `User::canSave` is weaker than the brief claimed. Admin-protection is **not** inherited automatically and must be added at the tool layer for `update` and `delete`.
2. **`administrateUsers` required for sensitive-field mutations on `update`.** Touched fields: `email`, `username` (non-self), `active`, `suspended`, `pending`, `locked`, `newPassword`. Missing permission → `ToolException` naming the offending field. Intra-mode gate, distinct from per-target gating.
3. **`filterFor()` coarse gate.** Returns `true` for stdio (`null`) and admins; otherwise `$user->can('viewUsers')`. Per-target re-check inside `execute()` is the security boundary.
4. **PII redaction via `_serializeUser(User $target, ?User $caller): array`.** Fieldmap below.
5. **Trait stack: `ProToolTrait + PermissionedToolTrait + IdempotencyTrait`.** `IDEMPOTENCY_CACHE_PREFIX = 'cortex:users:idem:'`. Constants on `Users`.
6. **Modes: `list / get / create / update / delete`.** No `restore` (Craft has no idiomatic soft-restore flow for users; reactivation goes through `activate / unsuspend` which are status mutations under `update`).
7. **`EditionGatingTest`**: append `Users::class` to `_cortex_pro_tool_classes()`. The five existing invariants then auto-apply.
8. **Path / namespace**: `src/tools/system/Users.php` (per gate-8.md §8.5).

### Open questions — resolved against source

9. **Group assignment permission (`groupUids` / `groupIds`)**. Craft uses **per-group** permission strings of the form `assignUserGroup:{groupUid}` — not a single global permission. `UserPermissions.php:466` registers one such permission per group. `UsersController::_saveUserGroups()` at line 2849 throws `ForbiddenHttpException` for any group the caller cannot `assignUserGroup:{uid}` on. **Decision**: the `update` and `create` modes accept `groupUids: string[]`; the tool resolves each UID to a group and asserts the caller holds `assignUserGroup:{uid}` on **every** group in the diff (new additions only — keep-as-is doesn't re-gate, matching Craft's `hasNewGroups` semantics at line 2845). Wildcard sentinel for `filterFor()` is `assignUserGroup:*` — but `filterFor()` doesn't need it because `viewUsers` is the coarse gate; group-assignment failures surface at execute-time with `-32002`. NOT folded under `administrateUsers` — Craft treats them as orthogonal axes.

10. **Admin-account creation / promotion (`admin: true`)**. Read `UsersController::actionSavePermissions()` at lines 1287–1298: admin status only changes when **the caller is already an admin** (`if ($currentUser->admin)`) AND an elevated session is required for promotion. **Decision**: the tool refuses `admin: true` on `create` and refuses any `admin` field change on `update` unless `$caller->admin === true`. Refusal is a thrown `ToolException` with explicit message — not a validation envelope, because this is a permission boundary, not a content issue. **Note**: `User::canSave()` does **NOT** enforce admin-protection (verified at `elements/User.php:1792–1803`), so the tool MUST add this gate itself. The brief's locked decision 1 ("`canSave` already enforces non-admin cannot edit an admin") is wrong — that protection lives in `canDelete` (line 1828) and ad-hoc in `UsersController` (`requireAdmin(false)` at lines 2194, 2232). For `update` mode, add an explicit "non-admin caller editing an admin target" refusal mirroring `UsersController::actionDeactivateUser`. For `delete` mode, `canDelete` does cover it natively. **This is the most important correction in the plan.**

11. **Password mutation (`newPassword` on `update`)**. Per `User.php:757` `newPassword` is a public mutable property; Craft routinely sets it from `UsersController::actionSaveUser()` (lines 1700–1707). Self-edit lets the user set their own; non-self requires `administrateUsers` per `UserPermissions.php:495–497` (the description explicitly mentions "resetting passwords"). **Decision**: option **(a)** — expose `newPassword` on `update`, gated behind `administrateUsers` for non-self callers (self-edit is allowed without `administrateUsers`, matching Craft's UserController convention at line 1703). No exposure on `create` — initial password is set via activation flow per Craft convention. No exposure on a separate "reset" mode in 8.5 — keep the API surface narrow. Document the elevated-session caveat in the tool's PHPDoc (the MCP HTTP transport doesn't currently surface elevated sessions; we accept this gap and note it as a follow-up in §9).

12. **`transferContentTo` permission on `delete`**. Reading `UsersController::actionDeleteUser` (line 2243+) and `UserContentSummary` (line 2137: `if ($userId !== currentUser?->id) { $this->requirePermission('deleteUsers'); }`): the **caller** needs `deleteUsers`, no additional permission against the recipient. The pattern is `$user->inheritorOnDelete = $transferContentTo` followed by `deleteElement($user)` — the actual reassignment runs inside `User::afterDelete()` at line 2700. **Note**: `inheritorOnDelete` is deprecated in Craft 5.10.0 but no successor has shipped — Craft itself still uses it internally (`elements/actions/DeleteUsers.php:171`). Use it; flag the deprecation in the PHPDoc as a future migration point.

13. **Self-edit semantics**. `User::canSave()` at `elements/User.php:1798–1800` returns `true` when `$user->id === $this->id`. **Decision**: no special-casing needed at the tool layer beyond two carve-outs: (a) the `administrateUsers` sensitive-field gate (locked decision 2) skips the check when caller-id equals target-id for non-credentials (matching `UsersController::actionSaveUser` line 1650 logic); (b) `newPassword` is allowed for self without `administrateUsers`. Email and password on self still require an elevated session in Craft's CP convention, but per question 11's resolution we accept that gap.

14. **`list` mode query parameters — focused schema**. `UserQuery` exposes many filters; pick the LLM-useful subset. **Decision**: `status` (string: `active`, `pending`, `suspended`, `locked`, `inactive`; pass-through via `->status($value)`), `group` (string handle or `int` id; via `->group()`), `admin` (bool; via `->admin()`), `can` (string permission; via `->can()` — useful for "find users who can saveEntries"), `search` (string; via `->search()`), `dateCreated` (string with `>=`/`<=` operators, pass-through to Craft), `limit` / `offset`. **Dropped**: `assetUploaders`, `authors`, `hasPhoto`, `lastLoginDate`, `affiliatedSiteId` — narrow use cases. The LLM can use `get` with `email` / `username` for known-target lookups.

15. **`list` pagination defaults**. `DEFAULT_LIMIT = 50`, `MAX_LIMIT = 200` — matches `Address`. **Order**: rely on `UserQuery::$defaultOrderBy` (`UserQuery.php:250–254`: `username ASC NULLS LAST`, then `active DESC`, then `pending DESC`). Stable and tied to user-visible ordering in the CP. No tool-level override.

16. **Email validation on `create` / `update`**. Craft's `User::defineRules()` covers email format + uniqueness natively. **Decision**: no extra tool-layer validation. Failures surface via `_validationEnvelope($element, $mode)` exactly like `Address` does — the LLM iterates on `errors.email`.

17. **`get` mode lookup keys**. **Decision**: support all four — `id` (int), `uid` (string), `email` (string), `username` (string). Exactly one required; if multiple supplied, prefer `id` > `uid` > `email` > `username`. Mirrors how `Users::getUserByUsernameOrEmail()` already disambiguates. Reject ambiguous calls only at the schema level if zero are supplied; multi-key calls dispatch on the first non-empty.

18. **`admin` field exposure**. **Decision**: schema exposes `admin` on both `create` and `update`. At execute-time:
    - `create` with `admin: true` requires `$caller->admin === true`. Else `ToolException`.
    - `update` with `admin` field changing requires `$caller->admin === true`. Else `ToolException`. (Matches `UsersController::actionSavePermissions` at line 1288.)
    - `admin: false` (demotion) similarly requires `$caller->admin === true` — Craft permits a non-admin to demote an admin if they hold `administrateUsers`, but tying both branches to "caller is admin" is the safer Cortex posture.

19. **`active` / `suspended` / `pending` on `create`**. Craft's `UsersController::actionSaveUser` defaults new users to `pending = true` (line 1733–1737). **Decision**: schema exposes `active`, `suspended`, `pending` on `create`. Explicit values are accepted **only when** the caller holds `administrateUsers` — matches the locked decision 2 gate. Without `administrateUsers`, the tool ignores any caller-supplied status field and defaults to `pending = true` per Craft's convention. With `administrateUsers`, the caller can set `active: true` to bypass email verification (Craft's `activateUser` path).

20. **Idempotency on `delete`**. **Decision**: skip — only `create` and `update` cache. Rationale: a re-`delete` on a re-created user (same numeric id reuse is unlikely but possible after hard-delete) could surprise. Soft-delete is idempotent at the DB level anyway. Document in tool PHPDoc.

21. **Registry slot in `Tools::_buildRegistry()`**. **Decision**: append `new Users()` to the existing "Content writing (Pro)" block after `new Address()` at line 330. Although Users live in `system/`, the registry block is a logical grouping by edition tier — every Pro write tool sits together. Add a comment on the line: `// system-namespaced but shares the Pro-write registration block`.

---

## File map

**New:**
- `src/tools/system/Users.php` — the tool itself. ~700 LoC including PHPDoc, similar to Address.
- `tests/Tools/System/UsersTest.php` — Pest suite. Lives under `tests/Tools/System/` (new directory — `Users` is the first system-namespaced Pro tool).

**Modified:**
- `src/services/Tools.php` — append `new Users()` to `_buildRegistry()` after the Address row (line ~330). Add the import in the `use` block at the top.
- `tests/Architecture/EditionGatingTest.php` — append `Users::class` to `_cortex_pro_tool_classes()` (line 47–55). Add the corresponding `use` statement.

**Untouched** (verify, do not modify):
- `src/models/Settings.php` — `$userCustomFieldAllowlist` already declared in 8.1.
- `src/tools/ProToolTrait.php`, `src/tools/PermissionedToolTrait.php`, `src/tools/IdempotencyTrait.php`, `src/tools/AbstractTool.php` — consumed as-is.
- `src/tools/support/ElementSerializer.php` — `_serializeUser()` does its own PII-aware serialization; do NOT delegate to `ElementSerializer::serializeElement()` for the user payload (would leak `email`, custom fields, etc.). Use it only for the embedded address-reference list.

---

## Tool spec

### Modes

| Mode | Required args | Optional args | Permission gate |
|---|---|---|---|
| `list` | (none) | `status`, `group`, `admin`, `can`, `search`, `dateCreated`, `limit`, `offset` | `filterFor` (`viewUsers`); per-target `canView` applies to each result |
| `get` | one of `id` / `uid` / `email` / `username` | `with` (eager-load address refs) | `Elements::canView($target, $caller)` |
| `create` | `email` | `username`, `firstName`, `lastName`, `fullName`, `admin`, `active`, `suspended`, `pending`, `groupUids`, `fields`, `idempotencyKey` | `registerUsers` (via `canRegisterUsers()`); `administrateUsers` for `active/suspended/pending`; admin caller for `admin: true`; `assignUserGroup:{uid}` per group |
| `update` | one of `id` / `uid` | any subset of create attrs + `newPassword` | `Elements::canSave`; admin caller to edit an admin target OR set `admin` field; `administrateUsers` for sensitive fields; `assignUserGroup:{uid}` for added groups |
| `delete` | one of `id` / `uid` | `transferContentTo` (int user id), `hardDelete` (bool) | `Elements::canDelete` (covers admin-protection natively) |

### Input schema rows

| Property | Type | Required | Description |
|---|---|---|---|
| `mode` | string enum (list, get, create, update, delete) | yes | Operation. |
| `id` | int | get/update/delete (one of) | User id. |
| `uid` | string | get/update/delete (one of) | User uid. |
| `email` | string | create (yes), get (alt) | RFC-formatted email. |
| `username` | string | get (alt) | Username — falls back to email when `useEmailAsUsername` is set. |
| `firstName` | string | no | Forename. |
| `lastName` | string | no | Surname. |
| `fullName` | string | no | Full name (overrides first/last when set). |
| `admin` | bool | no | Promote/demote. Requires caller is admin. |
| `active` | bool | no | Set on create/update when caller has `administrateUsers`. |
| `suspended` | bool | no | Same. |
| `pending` | bool | no | Same. Defaults to `true` on `create` per Craft convention. |
| `newPassword` | string | no | Update only. Self-edit allowed; non-self requires `administrateUsers`. Never returned. |
| `groupUids` | string[] | no | Replacement list of group UIDs. Each added group requires `assignUserGroup:{uid}` on the caller. |
| `fields` | object (handle to value) | no | Custom field values; forwarded to `setFieldValues()`. |
| `transferContentTo` | int | no | Delete only. Reassigns authored entries via `inheritorOnDelete` per `User::afterDelete()`. |
| `hardDelete` | bool | no | Delete only. Default `false`. |
| `status` / `group` / `can` / `search` / `dateCreated` | string/array | no | List filters; pass-through to `UserQuery`. |
| `limit` / `offset` | int | no | List pagination. Defaults: 50 / 0. Max 200. |
| `idempotencyKey` | string | no | Create/update only. 24h cache prefix `cortex:users:idem:`. Skipped on delete. |

### Output envelopes

| Mode | Shape |
|---|---|
| `list` | `{success: true, mode: list, users: [user, ...], count, limit, offset}` (each user is `_serializeUser()`-redacted per caller) |
| `get` | `{success: true, mode: get, user: {...}}` |
| `create` | success: `{success: true, mode: create, user: {...}}`; validation fail: `{success: false, mode: create, errors: {...}, id: null, uid: null}` |
| `update` | success: `{success: true, mode: update, user: {...}}`; validation fail: `{success: false, mode: update, errors, id, uid}` |
| `delete` | `{success: true, mode: delete, id, uid, hardDeleted, contentTransferredTo: int or null}` |

Permission denials and admin-protection failures are **thrown** `ToolException` (JSON-RPC `-32002`). Field-level validation errors (bad email, uniqueness collision, missing required custom field) **return** the validation envelope. Same convention as Address.

---

## PII gating spec — `_serializeUser($target, ?$caller)`

| Field | Visibility |
|---|---|
| `id`, `uid` | Always |
| `username`, `fullName`, `firstName`, `lastName` | Always |
| `active`, `suspended`, `pending`, `locked`, `admin` | Always (read-only flags; status-mutation gates apply on write) |
| `lastLoginDate`, `dateCreated`, `dateUpdated` | Always |
| `groupUids` (string[]) | Always |
| `addresses` (`[{id, uid, ownerType: user}]`) | Always — references only, never inlined bodies |
| `email`, `unverifiedEmail` | `editUsers` permission |
| `lastLoginAttemptDate`, `invalidLoginCount`, `lastInvalidLoginDate`, `lockoutDate` | `editUsers` permission |
| `passwordResetRequired` | `administrateUsers` permission |
| Custom fields | Only handles in `Settings::$userCustomFieldAllowlist`. **Independent of caller permission** — handles NOT on the allowlist are NEVER returned, even for admin callers. |
| `password`, `currentPassword`, `verificationCode`, `verificationCodeIssuedDate`, `authError`, `newPassword`, `lastLoginAttemptIp` | NEVER returned |

Caller resolution: `$caller === null` (stdio) is treated as admin-equivalent for visibility (consistent with Address). `$caller->admin === true` exposes all permission-gated fields. Otherwise `$caller->can(editUsers)` / `$caller->can(administrateUsers)` gate the respective fieldsets.

---

## Test scope

`tests/Tools/System/UsersTest.php` — Pest suite, mirroring AddressTest structure. Fixture prefix `__cortex_userstest_<hex>_`; afterEach hard-deletes throwaway users by username LIKE. Use Director Fury (`nfury`) as a known-existing read target for get/list assertions; use throwaway users for permission-denial / mutation cases.

### Registration

- `is NOT registered on Free` — `Tools::getByName(users)` returns null; not in `asListPayload()`.
- `shouldRegister() true on Pro, false on Free` (Address pattern).

### Mode validation

- Missing `mode` triggers ToolException.
- Unknown `mode` triggers ToolException.

### filterFor

- `null` (stdio) returns true.
- Admin returns true.
- User with `viewUsers` but not `editUsers` returns true (coarse gate).
- User with no relevant permissions returns false.

### inputSchemaFor

- Returns the full enum for stdio, admin, and `viewUsers`-only callers (no mode filtering for Users — distinct from drafts_and_revisions etc., which gate modes on permissions; for Users every mode requires `viewUsers` at the coarse level and finer gates happen in execute()).

### list mode

- Admin: returns paginated users; respects limit/offset; finds Director Fury when search=Fury.
- `viewUsers`-only caller: each returned user envelope omits `email` (PII regression check).
- `admin: true` filter returns only admins.
- `status=suspended` returns only suspended users.

### get mode

- By id, uid, email, username — each works.
- email lookup is case-insensitive (delegates to `getUserByUsernameOrEmail` semantics).
- Missing all four keys triggers ToolException.
- Non-existent user triggers ToolException ("no user found for ...").
- `viewUsers`-only caller: response has no email / unverifiedEmail / invalidLoginCount etc.
- `editUsers` caller: response has email and other editUsers-gated fields.
- `administrateUsers` caller: response has `passwordResetRequired`.
- Custom field not on `$userCustomFieldAllowlist` is absent from response even for admin.
- Add `phone` to the allowlist via project config muting: response contains `fields.phone`.

### create mode

- Admin: round-trips a new user with email, username, firstName, lastName, pending=true (Craft default).
- Caller with registerUsers but not administrateUsers: active=true in payload is silently ignored, user lands as pending.
- Caller with registerUsers + administrateUsers: active=true works, user is activated.
- Non-admin caller with admin=true in payload triggers ToolException (admin promotion requires admin caller).
- Admin caller with admin=true: user created as admin.
- Duplicate email returns validation envelope with errors.email.
- groupUids for a group the caller cannot assignUserGroup on triggers ToolException.
- Caller without registerUsers triggers ToolException (create denied).
- Idempotency: same key issued twice returns the cached envelope without re-saving.

### update mode

- Admin can update any user — round-trip on Director Fury (mutate firstName, restore in afterEach).
- editUsers caller updating a non-admin target succeeds for non-sensitive fields.
- editUsers caller setting active=true without administrateUsers triggers ToolException.
- Non-admin caller attempting to update an admin target triggers ToolException. **Most important admin-protection regression case.**
- Non-admin caller setting admin=true on a non-admin target triggers ToolException.
- newPassword on self (no administrateUsers) succeeds.
- newPassword on non-self without administrateUsers triggers ToolException.
- groupUids diff: adding a group the caller can assignUserGroup on succeeds. Adding one they cannot triggers ToolException. Keep-as-is groups do not re-gate.
- Trashed user (resolved via trashed() probe) triggers ToolException with hint per the Address trashed-on-update pattern.
- Idempotency: same key issued twice returns the cached envelope.

### delete mode

- Admin: hard-delete a throwaway user.
- Admin: soft-delete a throwaway user, verify trashed() finds it.
- Admin with transferContentTo: assert authored entries are reassigned.
- deleteUsers caller deleting a non-admin target succeeds.
- deleteUsers (non-admin) caller deleting an admin target triggers ToolException (Craft canDelete enforces this at elements/User.php:1828).
- Caller without deleteUsers triggers ToolException.

### Architecture invariants (in EditionGatingTest.php)

- Users::class is in _cortex_pro_tool_classes() — auto-asserted by the five existing invariants.

---

## Verification gates

Three layered gates, matching prior sub-gates:

1. `ddev exec vendor/bin/pest --filter=UsersTest` — full Users suite green.
2. `ddev composer phpstan` — level 8 clean.
3. `ddev composer check-cs` — ECS clean.

Final gate (only after the three above): full suite `ddev exec vendor/bin/pest` to confirm no regressions; baseline was 682 passing / 1 skipped pre-8.5.

Layered build-verify cadence the builder follows:
- Skeleton + registration: run EditionGatingTest to confirm the new tool registers on Pro and not on Free.
- filterFor + inputSchemaFor: run filter tests.
- list + get happy paths: run list/get tests.
- PII serializer (_serializeUser): run PII regression tests against existing users (Fury).
- create + admin/permission gates: run create suite.
- update + admin-protection + sensitive-field gate: run update suite. The admin-protection test is the highest-value regression — surface it early.
- delete + transferContentTo: run delete suite.
- Final: full suite + PHPStan + ECS.

---

## Manual verification

After the builder reports done, the dispatcher should:

1. **Free install**: confirm `users` is absent from the registry.
2. **Pro install, admin token**: tools/list over HTTP includes `users`. tools/call for `users` with mode=list returns Fury record with `email` present.
3. **Pro install, non-admin token (viewUsers only)**: tools/list still includes `users`; tools/call `users mode=get email=nick.fury@shield.gov` returns Fury without the `email` field. PII regression — verify with curl and jq.
4. **Pro install, editUsers non-admin**: `users mode=update id=<admin>` attempting to change the admin firstName returns -32002 with the admin-protection message. Most subtle gate — eyeball the error message wording.
5. **Pro install, admin**: `users mode=update id=<fury> newPassword=...` succeeds. Then `users mode=update id=<fury> active=false` succeeds. Restore both before moving on.
6. **Pro install, admin**: `users mode=delete id=<throwaway> transferContentTo=<other>` — verify in CP that the deleted user entries now show the recipient as author.
7. **userCustomFieldAllowlist**: temporarily set the allowlist via project config to ["phone"], re-run `users get` for a user with a phone field, confirm the field appears. Remove, re-run, confirm it is gone.

---

## Risks / open follow-ups

- **User::canSave admin-protection gap** (locked decision 10 above). The brief claim that canSave enforces "non-admin cannot edit an admin" is wrong; we add the gate at the tool layer. If a future Craft 5.x update tightens canSave to inherit this behaviour, the tool gate becomes redundant but harmless. Worth a comment in the tool PHPDoc.
- **Elevated-session gap on HTTP transport**. Craft CP requires elevated sessions for email / password / admin-promotion mutations (UsersController::actionSaveUser line 1712–1719). The MCP HTTP transport has no equivalent today — bearer tokens are issued with full user scope, no elevation. We accept this for 8.5 and document the gap. **Follow-up gate**: add an elevated-session model to the HTTP transport (per-token elevation flag, refresh-on-sensitive-action) — likely a Gate 9 feature alongside the CP UI.
- **inheritorOnDelete deprecation in Craft 5.10.0**. Use it because Craft itself still does. When/if the successor lands, swap inside _delete() — single seam.
- **Group-assignment elevated session**. UsersController::_saveUserGroups calls requireElevatedSession() when there are new groups. Same gap as above — document, defer to elevated-session work.
- **No password-reset / send-activation tool in 8.5**. Both are reasonable separate Pro tools (users_send_password_reset, users_send_activation_email) — defer to a future gate. The 8.5 tool stays focused on CRUD.
- **Audit log richness**. Per existing audit conventions (Gate 7.5), every users invocation lands in cortex_invocations. The responseExcerpt field will contain redacted user data — confirm SecretRedactor strips email / newPassword / etc. at the audit boundary. If it does not, surface as a follow-up; do not add Users-specific redaction in 8.5.
- **groupUids diff semantics**. The brief locked decision 5 calls out groupUids as a returned field but does not lock the write semantics. We chose "replacement list" (matches Craft assignUserToGroups). Alternative: addGroupUids / removeGroupUids arrays. Replacement is simpler for the LLM; document.

---

## Out of scope

Explicitly NOT shipping in 8.5:

- **Skills element type** — its own future gate (storage decision resolved: PC for type, DB for elements).
- **Address mutations on users** — delegate to the existing `address` Pro tool (Gate 8.4).
- **Per-field-value gating on custom fields beyond the allowlist** — coarse allowlist is the 8.5 surface. Field-level "this user can see phone but not salary" gating is a future gate.
- **CP UI / settings page entries** — no CP screens in 8.5. The userCustomFieldAllowlist is project-config-managed; the existing Cortex Settings CP page (the runtime-overrides one from earlier gates) does not need to expose this allowlist in 8.5.
- **send_activation_email, send_password_reset_email, impersonate** — separate future Pro tools.
- **Photo / avatar mutation** — out of scope; photo upload is a separate flow with a different security surface.
- **Multi-site user propagation** — Users are non-localized in Craft 5; nothing to do.
- **GraphQL exposure of Users via Cortex** — covered by Craft GraphQL surface, exposed through the `graphql` tool. Not an 8.5 concern.
- **Elevated-session model on HTTP transport** — deferred per Risks above.
