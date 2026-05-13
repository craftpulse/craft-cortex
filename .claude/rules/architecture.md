<!-- craftcms-claude-skills -->
# Architecture

- Business logic in services. Controllers are thin: validate, delegate, respond.
- Element operations through services, not controllers or helpers.
- Services extend `yii\base\Component`, registered via `static config()`.
- Abstract base classes for shared controller structure. Traits for cross-cutting concerns.
- `MemoizableArray` for cached service lookups — always reset on data changes.
- Project config for entities that sync across environments (command allowlist, edition settings, tool toggles). Runtime data stays in DB only.
- Events for extensibility: fire before/after events on all significant operations.
- `addSelect()` not `select()` in `beforePrepare()` — never wipe Craft's base columns.
- `site('*')` in queue workers — workers run in primary site context.
- Scope element queries by owning context (site, section, owner) — never query globally without filters.

## Cortex-specific

- **Tool registry pattern:** every MCP tool is a class implementing a shared interface, registered through a service. No giant switch statements.
- **Transport-agnostic core:** tool logic must not know whether it was invoked over stdio or HTTP. Transport layers translate to/from a common request/response shape.
- **Edition gating:** Free / Pro / Commerce gates are enforced at registration time, not inside tool handlers. A Pro tool should never be loaded into the Free registry.
- **`craft exec` is stdio-only.** Never expose it through the HTTP transport, regardless of permissions.
- **Console commands dispatched through Craft's console runner**, not via shell. No `Process` or `exec()` calls — that opens a shell injection vector.
- **Command allowlist lives in project config**, version-controlled and shared across team/environments. Override via `config/cortex.php`.
- **No raw SQL tool.** PII is excluded from the Free tier. Security model: Craft's own eval (`craft exec`) over a homebrew blocklist eval.
- **Per-user tool visibility (Gate 7 / HTTP transport — locked architectural decision).** Three-method gating contract on `ToolInterface`:
  - `shouldRegister(): bool` — static, runs once at boot, license/edition/settings gating. Removes whole tools nobody on the install can use (e.g., `craft_exec` when `execEnabled = false`; future Pro-only tools when the edition is Free).
  - `filterFor(?User $user = null): bool` — per-request per-user gating. Default `true`. Pro tools override to hide themselves when the current user lacks the relevant permission (`saveEntries:*`, `editUsers`, etc.). The HTTP dispatcher consults it on every `tools/list` and `tools/call`; stdio passes `null` and gets the default-true behaviour.
  - `inputSchemaFor(?User $user = null): array` — per-request schema rewrite. Default delegates to the static `getInputSchema()`. Tools with mode-gated permissions (`drafts_and_revisions`, `content_audit`, `import_export`, `system_diagnostics`) override to filter the `mode` enum based on the user's permissions. Stdio: `inputSchemaFor(null) === getInputSchema()`.
  - Service-level: `Tools::asListPayloadFor(?User $user)` and `Tools::getByNameFor(string, ?User)` are the HTTP variants. The existing `asListPayload()` and `getByName()` stay for stdio (`null` user is the default-true path).
  - `execute()` performs its own permission check regardless — defense in depth. `tools/list` filtering is for the LLM's tool-selection UX; `execute()` filtering is for security; both fail closed.
  - Rejected alternatives: per-request whole-registry rebuild (loses per-mode filtering), per-mode filtering only (can't hide whole Pro tools from Free users), per-tool only (would hide tools with mixed Free-read / Pro-write modes from read-only users).
