# Release Notes for Herald

## Unreleased

> [!WARNING]
> **Every existing bearer token stops working and must be re-minted.**
> Tokens were issued with an empty `scope` column, and the transport now
> refuses a credential that carries no capability scope: a token minted
> before this release returns `403` on its first request. Re-issue each
> one with the scopes it needs, from **Herald** &rarr; **Tokens** in the
> control panel or with
> `php craft herald/token/issue <user> --scopes=schema:read,content:read`,
> and revoke the old row. Do this before deploying, not after, or the
> first thing an operator sees is an agent that stopped working.
> Pick the scopes deliberately while you are there: every dev action tool
> (`craft_command`, `craft_exec`, `clear_caches`, `resave`) requires
> `system:write`, so a credential minted with `system:read` reads
> diagnostics and nothing else. Each of those tools also requires a Craft
> permission on the user the credential is bound to, which the scope does
> not grant.

> [!WARNING]
> **Admin-level console routes stop being dispatchable while
> `allowAdminChanges` is off, wherever they are configured.**
> `craft_command` classifies the resolved route rather than trusting
> which allowlist matched it, so a pattern such as `migrate/*` or
> `project-config/*` sitting in `allowedCommands` or in a temporary grant
> is refused on an install where Craft itself disallows admin changes.
> Move those patterns to `adminLevelCommands`, author schema on an
> environment where `allowAdminChanges` is `true`, and propagate with
> `php craft up`.

### System

- Added a Model Context Protocol server for Craft CMS, serving 33 tools, 10 prompts, and the bundled skills corpus over a stdio console transport (`php craft herald/serve`).
- Added a Streamable HTTP transport at `POST /herald/mcp`, off by default, authenticated on every request through OAuth 2.1 or a bearer token (Pro).
- Added OAuth 2.1 with Authorization Code and PKCE (S256 only), RFC 7591 Dynamic Client Registration, RFC 7009 revocation, RFC 8414 and RFC 9728 discovery metadata, and RFC 8707 audience binding.
- Added capability scopes on every HTTP credential: `content:read`, `content:write`, `assets:write`, `schema:read`, `system:read`, `system:write`, `users:read`, and `users:write`. A credential carrying no scope authorises nothing.
- Added refresh-token rotation with family-lineage theft detection, so presenting an already-consumed refresh token revokes the whole family and records a security audit row.
- Added an approval gate for self-registered OAuth clients, which start unapproved until an admin approves them on the **Clients** screen, with `dcrAutoApprove` to opt out.
- Added an in-band `/oauth/elevate` re-authentication flow, required over HTTP for password, email, and admin-status changes on the `users` tool, for publication-status changes on `entry` and `bulk_entries`, and for the `delete` mode of every write tool. It never unlocks `craft_exec`.
- Added dispatcher-level validation of tool arguments against each tool's declared JSON Schema, evaluated against `inputSchemaFor()` so per-user mode narrowing is enforced too. Non-conforming calls return an `isError: true` envelope.
- Added account-state re-checking on every authenticated request, so a suspended, locked, pending, or inactive account is refused with `401` rather than trusted from issuance.
- Added per-user rate limiting on the HTTP transport, with a `kind=rate_limited` audit row and a `Retry-After` header on exhaustion.
- Added SSE streaming for `resave`, `bulk_entries`, `scaffold_entries`, `content_audit`, and `import_export`, with cooperative `notifications/cancelled` handling that survives a dropped connection. Every `notifications/progress` frame carries the spec-required `progressToken`, falling back to the request id when the client supplied none.
- Added an `Origin` allowlist that fails closed outside `devMode`, as the MCP spec's DNS-rebinding defence.
- Added `herald_invocations`, one audit row per invocation over HTTP, plus one redacted structured log line per invocation on the `herald` log channel across both transports.
- Added native Audit Kit event emission for every write-tool invocation and every credential lifecycle action, so writes reach a tamper-evident chain when a recorder is installed. With no recorder the bus is a no-op.
- Added `craftpulse\herald\tools\support\SecretRedactor` as the single source of truth for secret redaction, applied to tool arguments, audit excerpts, `craft_exec` output, and the `config` tool.
- Added MCP protocol revision `2025-11-25`, negotiating `2025-06-18` for older clients.
- Added a complete uninstall: removing Herald drops its own tables and the element and field-layout rows behind authored skills, so nothing is left orphaned in Craft's core tables.

### Content Management

- Added the `entries`, `assets`, `categories`, `tags`, and `globals` tools, exposing the element-query surface with filters, `relatedTo`, `with` eager loading, pagination, and a `count` mode. Relational fields stub until explicitly materialised.
- Added the `entry`, `category`, `tag`, `global_set`, `address`, `bulk_entries`, and `scaffold_entries` write tools, which author every change through `craft\services\Elements::saveElement()` and re-check the per-section or per-group permission the resolved arguments imply (Pro).
- Added an `orderBy` allowlist on every list tool: only aliases the tool declares are accepted, and each maps to a fully qualified column.
- Added the `drafts_and_revisions`, `content_audit`, and `import_export` tools, with their write modes gated to Pro and to the caller's permissions.
- Added a `skill` tool and a `craftpulse\herald\elements\Skill` element type, so a team can author its own conventions as Craft content and override a bundled skill by handle (Pro).

### Development

- Added `search_skills`, ranked keyword retrieval over the merged skills corpus, returning the resource URI of each hit for a follow-up `resources/read`.
- Added `get_initial_context`, a one-call bootstrap returning the Craft version, edition, environment, a thin sites and sections index, the skill-prompt catalogue, the `craft_exec` posture, and the effective command allowlist.
- Added the `sections`, `entry_types`, `fields`, `field_types`, `category_groups`, `tag_groups`, `volumes_and_filesystems`, `sites`, `image_transforms`, `element_types`, and `database_schema` tools for reading the content model.
- Added the `system_info`, `config`, `plugins`, `routes`, `system_diagnostics`, `extensibility`, `permissions_and_groups`, and `graphql` tools for reading system state.
- Added `craft_command`, which dispatches allowlisted Craft console routes through Craft's in-process console runner, with no shell involved, behind the `herald:run-commands` permission and the `system:write` scope.
- Added `craft_exec` behind six gates: dry-run by default, structured output, secret redaction, a destructive-op guard requiring two opt-ins, hard stdio-only rejection at the dispatcher, and the MCP `destructiveHint` annotation.
- Added `resave` and `clear_caches`, structured wrappers over Craft's `resave/*` and `clear-caches/*` routes, on the `system:write` scope. `resave` requires the same `herald:run-commands` permission as `craft_command`, since it runs the same route; `clear_caches` requires its own `herald:clear-caches`.
- Added `herald/install`, `herald/install/apply`, `herald/install/detect`, and `herald/install/auto` for wiring MCP clients, with atomic writes, timestamped backups, and idempotent re-runs.
- Added `herald/docs/all` to regenerate the tool, prompt, and resource references from the live registries.

### Administration

- Added a Herald control panel section with Settings, Temporary grants, Tokens, Clients, Activity, and Connection screens, each gated by permission and edition.
- Added the `herald:manage-settings`, `herald:manage-grants`, `herald:manage-skills`, `herald:view-activity`, `herald:run-commands`, and `herald:clear-caches` permissions.
- Added a grouped console-command browser to the Settings screen, listing every route on the install with content-level and admin-level commands in separate sections, and preserving hand-written glob patterns in a per-section table.
- Added temporary command grants: admin-issued, auto-expiring allowlist additions that do not sync to project config, with an "effective allowlist right now" panel.
- Added `auditRetentionDays` and `auditResponseExcerptBytes` to tune the audit table, which is pruned during Craft's garbage-collection sweep at exactly the configured age on any install timezone.
- Added timezone-correct timestamps across the control panel: expiry, last-used, and creation times render in the viewer's own timezone, and a token's "expired" marker flips on the same instant the transport starts refusing the credential.
- Added `userCustomFieldAllowlist`, empty by default, so the `users` tool returns no custom-field value until an operator enumerates the handles. The allowlist is independent of caller permission.
- Added `herald/token/issue`, `herald/token/list`, and `herald/token/revoke` for managing bearer tokens from the console. Issuance requires `--scopes` and the Pro edition.

### Extensibility

- Added `craftpulse\herald\services\Tools::EVENT_REGISTER_TOOLS`, `craftpulse\herald\services\Prompts::EVENT_REGISTER_PROMPTS`, and `craftpulse\herald\services\Resources::EVENT_REGISTER_RESOURCES` for registering third-party tools, prompts, and resources. First registration wins, and every collision is logged.
- Added `craftpulse\herald\tools\support\InvocationLogger::EVENT_LOG_CALL`, carrying the structured invocation entry and the formatted log line, so an integrator can mirror invocations into their own audit surface.
- Added `craftpulse\herald\tools\ToolInterface` with its three-method gating contract (`shouldRegister()`, `filterFor()`, `inputSchemaFor()`), plus `craftpulse\herald\tools\AbstractTool` and a fluent JSON Schema builder at `craftpulse\herald\tools\support\Schema`.
- Added `craftpulse\herald\resources\ResourceTemplateInterface` for RFC 6570 Level-1 URI families alongside concrete resource URIs.
- Added the `#[IsReadOnly]`, `#[IsDestructive]`, `#[IsIdempotent]`, `#[IsOpenWorld]`, `#[IsStdioOnly]`, and `#[Title]` attributes, read into the MCP `ToolAnnotations` payload.
- Added `craftpulse\herald\tools\DualModeToolInterface`, so a tool whose modes span reads and writes is classified per invocation rather than by its class-level annotation.
- Added a `herald-tool` generator to Craft's `make` system, scaffolding a `craftpulse\herald\tools\AbstractTool` subclass with its attributes and a schema stub.
