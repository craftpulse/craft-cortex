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
