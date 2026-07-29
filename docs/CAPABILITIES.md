# Herald: Capabilities & Architecture

This document is the deep answer to "what is Herald and how is it built?" Every claim cites `src/` (or `tests/`) so it can be verified against the code rather than taken on faith. The companion document [`COMPETITIVE.md`](COMPETITIVE.md) positions these capabilities against the rest of the CMS/MCP field; this one stands alone.

> **Status:** Herald is feature-complete on branches and **not yet released**, so "ships" below means "exists in the codebase and is tested," not "installable from the Plugin Store today." Quality bar at the time of writing: **1,120 Pest tests / 0 skipped (12,441 assertions), PHPStan level 8 clean, ECS clean**, plus tokenizer-based architecture tests described in §4.

---

## 1. What Herald is

Herald is a Model Context Protocol server delivered as a Craft CMS 5 plugin. It runs **in-process inside the customer's own Craft install** (content never transits a vendor's infrastructure) and exposes Craft to AI agents over two transports:

- **stdio** (Free): a console command (`herald/serve`, `console/controllers/ServeController.php`) speaking newline-delimited JSON-RPC to a local MCP client. Trusted-local posture.
- **Streamable HTTP** per MCP spec revision 2025-11-25 (Pro; 2025-06-18 negotiated for older clients, see `Server::SUPPORTED_PROTOCOL_VERSIONS`): a web endpoint (`controllers/McpController.php`) treated as fully untrusted: every request authenticates against a Craft user and is authorized, rate-limited, and audit-logged before a tool runs.

The design thesis is that **the transport is the security boundary**. The same transport-blind dispatcher serves both paths (`src/mcp/Server.php:22-32`, "Knows nothing about stdio or HTTP"); everything trust-sensitive lives in the transport adapters, and the most dangerous capability (`craft_exec`) is rejected at the dispatch boundary on HTTP regardless of caller permissions (`Server.php:821-831`).

Editions: **Free** (the full 33-tool read surface, all prompts/resources, stdio) and **Pro** (HTTP transport, OAuth 2.1, nine write tools, mode unlocks, the custom Skill element type, the CP operator surface). PII is excluded from Free by design.

## 2. The knowledge layer

Herald bundles [`michtio/craftcms-claude-skills`](https://github.com/michtio/craftcms-claude-skills): **98 addressable documents totalling ~30,000 lines of hand-authored Craft expertise**: 10 skills (each a `SKILL.md` router plus reference deep-dives, 82 references in total) and 6 Claude Code agent definitions. This is not a docs scrape. The flagship `craftcms` skill documents reverse-engineered internals that exist nowhere in the public documentation (the 15-step element save lifecycle, the four-layer authorization model, the dual-layer session architecture), and `craft-garnish` is, as far as we can establish, the only written documentation of Craft's CP JavaScript toolkit at all (`src/services/Prompts.php:70-73`).

The corpus is delivered through three MCP surfaces that share one storage and one merge path:

- **Prompts (primary).** Each curated skill is a named MCP prompt (`craftcms_extending`, `craftcms_templates`, `craftcms_cp_javascript`, …) whose `prompts/get` returns the SKILL.md verbatim as a user message (`src/prompts/SkillPrompt.php:117-139`). The prompt names and descriptions are a deliberate whitelist (`Prompts::PROMPT_MAP`, `src/services/Prompts.php:60-107`), so the public prompt surface is curated, never implicit.
- **Resources (secondary).** Every router, every reference, and every agent is individually addressable at a `craft-skills://<skill>[/<reference>]` URI (98 resources on the current corpus), so an agent can pull exactly the deep-dive it needs (`src/services/Resources.php`).
- **Search.** `search_skills` runs ranked keyword retrieval over the merged corpus with length-weighted scoring and word-boundary snippets, returning the resource URI of each hit for follow-up `resources/read` calls (`src/tools/system/SearchSkills.php:156-201, 271-340`). A `topics` mode enumerates the corpus without scoring.

**How an agent actually finds this.** The discovery path is engineered, not hoped-for: `get_initial_context`, deliberately named to match the convention some MCP clients special-case (`src/tools/system/InitialContext.php:37-38`), returns the skill-prompt catalogue inside its one-call bootstrap payload, alongside the Craft version/edition/environment, a thin sites/sections/element-types index, the `craft_exec` posture, and the effective command allowlist (`InitialContext.php:17-42`). A fresh agent's first tool call tells it the moat content exists and how to load it.

Honest limitation, stated plainly: retrieval is **keyword, not semantic**. Vectorized search is Phase-3 roadmap. The bet is that authored depth beats retrieval sophistication for Craft specifically, but on the retrieval mechanism itself, several competitors are ahead today.

## 3. Org-authored skills as Craft content

Pro installs get a custom **Skill element type** (`src/elements/Skill.php`), so teams author their own conventions ("our deploy checklist", "house Twig rules") as first-class Craft content, with the full element lifecycle: a project-config-stored field layout, soft delete and restore, and per-element authorization (`canView`/`canSave`/`canDelete`, `Skill.php:231-264`) layered under the tool-level permission gate.

The hard part, **coexistence with the bundled corpus**, is handled by a deterministic merge (`src/services/Skills.php:364-435`):

- The element's **handle is the natural key**. An element-stored skill whose handle matches a bundled skill **overrides the bundled SKILL.md body** (`Skills.php:385-397`); bundled references still travel with it, so an org can re-voice the router without losing the deep-dives.
- Element-only handles surface as new corpus rows; bundled-only handles fall through to the filesystem reader.
- Element content is **synthesized byte-shaped identically to a bundled SKILL.md** (frontmatter rebuilt from handle + description, body appended, `Skills.php:307-321`), so the prompt, resource, and search consumers process both branches through one code path. Every merged row carries `source: 'bundled' | 'element'` provenance.
- The handle is **format-validated and immutable after creation** (`Skill.php:419-420, 442, 508`) so `craft-skills://` URIs can never dangle, and a trashed handle is detected up front with a restore-or-hard-delete hint instead of a mid-save integrity violation.
- Element-stored skills are **not auto-promoted to prompts**: an override re-bodies an existing whitelisted prompt, but a brand-new handle surfaces as a resource only (`Prompts.php:48-57`, locked decision). New prompts are a curated surface, not an editor side effect.

The merged corpus is memoized per request via `MemoizableArray` and reset on every Skill lifecycle event and field-layout change (`Skills.php:332-336`), so the agent always reads current state without re-querying per call.

## 4. Thick tools, and what is deliberately absent

Herald ships **33 tools in Free, 42 in Pro**: thick, mode-driven tools rather than a sprawling catalogue of thin ones. `list_*`/`get_*` pairs collapse into one tool with an optional `handle`; every list-capable tool has a `count: true` mode; content tools expose the full element-query surface (filters, `relatedTo`, `with: [...]` eager loading, pagination) with relational fields stubbed to `{type: "relation", loaded: false}` unless explicitly materialised, so the LLM can't accidentally trigger an N+1 walk. A smaller `tools/list` means faster tool selection and fewer tokens burned per turn.

The machinery behind the tools:

- **Boot-once, class-per-tool registry.** `services/Tools.php` builds the registry once at service init, applies static `shouldRegister()` edition/settings gating before a gated tool ever reaches `tools/list` (`Tools.php:112-121`), and resolves name collisions **first-registration-wins with a logged diagnostic** so a third-party tool can never silently shadow a bundled one (`Tools.php:123-134`). No per-request rebuild; the right cost model for HTTP.
- **A fluent JSON Schema DSL.** Tools author their input/output schemas through a chainable builder (`Schema::object([...])`, `->enum()`, `->required()`, `->description()`, see `src/tools/support/Schema.php`) instead of hand-rolled array literals; objects default to `additionalProperties: false`.
- **PHP 8 attributes carry MCP `ToolAnnotations`.** `#[IsReadOnly]`, `#[IsDestructive]`, `#[IsIdempotent]`, `#[IsOpenWorld]`, `#[IsStdioOnly]`, `#[Title]` (`src/attributes/`) are read by `AttributeReader` into the wire payload, and `#[IsStdioOnly]` is *load-bearing*: the dispatcher keys its transport rejection off it (`Server.php:821`).
- **Spec-current envelopes.** Tools with declared output schemas dual-emit `structuredContent` alongside the text block per the MCP tool-result spec (`Server.php:1317-1337`); tool failures return as `isError: true` envelopes so the LLM can self-correct, while raw exception detail goes to the `herald` log channel, never the wire (`Server.php:1403-1407`).

**What is deliberately absent** is as much a feature as what's present:

- **No raw-SQL tool.** Element access goes through typed, permission-scoped tools. The security model is "no arbitrary-query primitive," not "arbitrary queries behind a keyword blocklist."
- **No shell.** Console commands dispatch through Craft's in-process console runner with a captured-stdout stream filter (`src/tools/support/ConsoleRunner.php`, `StdoutCaptureFilter.php`), and there is no `Process`, `exec()`, `shell_exec()`, `proc_open()`, `passthru()`, `popen()`, or backtick anywhere in `src/`.
- **One `eval` site, fenced.** The single privileged-execution path is `craft_exec` (§5).

These are not conventions: they are **enforced by a tokenizing architecture test** (`tests/Architecture/ConventionsTest.php:63-154`) that walks PHP's token stream (so it cannot be fooled by comments or strings), bans the shell-exec family and `T_EVAL` across `src/`, and exempts exactly one file by name: `tools/dev/CraftExec.php`.

## 5. The governance stack

This is the load-bearing differentiator: not one mechanism but a stack, each layer failing closed.

**The three-method gating contract + execute-time re-check.** Every tool implements (`src/tools/ToolInterface.php:93-145`):

1. `shouldRegister(): bool`: static, boot-time. Edition/license/settings gating: a Pro tool on a Free install is never even loaded into the registry (`ProToolTrait.php:77`).
2. `filterFor(?User): bool`: per-request, per-user visibility. Consulted on every `tools/list` *and* every `tools/call` (`Tools.php:216-253`); a tool the user can't use is omitted from the list and, if called anyway, fails closed as `"Unknown tool"`, which is **wire-indistinguishable from a tool that doesn't exist** (`Server.php:809-818`).
3. `inputSchemaFor(?User): array`: per-request schema rewrite. Mode-gated tools (`drafts_and_revisions`, `content_audit`, `import_export`, `system_diagnostics`) filter their `mode` enum to what the user may actually do, so the LLM is never offered an option that will be refused.
4. `execute()` re-checks permissions regardless (`PermissionedToolTrait::_assertPermission`, `src/tools/PermissionedToolTrait.php:84-111`). List filtering is tool-selection UX; the execute-time check is the security boundary; both fail closed. Wildcard permission sentinels reaching `execute()` throw rather than silently authorize (`:99-105`).

**The HTTP gate sequence.** `McpController::beforeAction()` runs an explicit, ordered pipeline before any action body sees the request (`src/controllers/McpController.php:162-298`): `httpEnabled` kill switch (503) → Origin allowlist, **failing closed outside devMode when empty** as DNS-rebinding defence (`:377-412`) → method discipline → `MCP-Protocol-Version` → bearer/OAuth resolution with RFC 6750 challenge headers → per-user token-bucket rate limit with a `kind=rate_limited` audit row on exhaustion → Craft's own pipeline with the resolved identity bound. The kill switch covers all three HTTP controllers (MCP, OAuth, `.well-known`) via a shared base.

**OAuth 2.1 with the MCP-specific extensions done properly** (`src/services/Oauth.php:42-66`, crypto delegated to `league/oauth2-server`):

- PKCE mandatory for public clients, **S256 only**, and `plain` is rejected outright.
- **Audience binding (RFC 8707)**: every access token carries an `aud` claim; the MCP controller rejects tokens minted for a different resource, which is the confused-deputy defence the MCP spec calls for.
- **Dynamic Client Registration (RFC 7591)** with HTTPS-or-loopback redirect validation, plus RFC 9728 / RFC 8414 discovery metadata.
- Plaintext tokens are never persisted: long-lived bearers are `random_bytes(32)` rendered as 64-char hex, stored only as SHA-256 (`src/services/Tokens.php:91, 281`); OAuth tokens store only a hash of the `jti`.
- The anonymous endpoints (register/token/revoke) are **IP-throttled** through a parallel keyed rate-limiter surface (`RateLimiter::consumeKey()`, `src/services/RateLimiter.php:171`), and expired/revoked OAuth rows are pruned by the gc sweep.

**Operations refused over HTTP entirely.** Password changes, email changes, and admin-flag mutations are exactly the operations Craft's own CP gates behind an elevated (re-authenticated) session. The MCP HTTP transport has no elevated-session layer, so these are **refused over HTTP and available only over trusted stdio**, via a real-transport context seam (`ContextAwareToolInterface`, where the transport comes from the dispatcher and is never inferred), failing closed to "refuse" when indeterminate (`src/tools/system/Users.php:208-215, 487-495`).

**`craft_exec`: one eval, six gates, stdio only.** The threat model is written into the class header: *an LLM choosing destructive operations because it misread context, not sandbox escape* (`src/tools/dev/CraftExec.php:18-51`). The gates, layered so any failure short-circuits: (1) dry-run by default, so without `confirm: true` the tool returns analysis, never a result; (2) structured, typed output (parse/runtime errors with traces); (3) secret redaction over results *and* captured stdout; (4) a destructive-pattern guard (`delete*`/`drop*`/`truncate*`/`migrate/down`/`project-config/sync`, see `:73-86`) requiring `confirm: true` **and** `dangerous: true`; (5) `#[IsStdioOnly]`, a hard dispatcher-level HTTP rejection regardless of token or permissions; (6) the MCP `destructiveHint` annotation so spec-aware clients warn the user. The `execEnabled` setting is an additional availability switch in front of all six.

**The command allowlist.** `craft_command` dispatches only routes matching an allowlist enforced at the tool layer before the console runner is reached (`src/tools/dev/CraftCommand.php:23-24`). The effective list is project-config defaults + admin-level patterns admitted **only when the host install permits admin changes** (`allowAdminChanges`, `src/services/Allowlist.php:88-100`) + admin-issued, auto-expiring runtime overrides managed in the CP.

**Secret redaction as a single source of truth.** `SecretRedactor` (`src/tools/support/SecretRedactor.php`) normalises keys and matches a broad needle list (`password`, `securitykey`, `token`, `secret`, `apikey`, `privatekey`, `oauth`, `bearer`, … `:41-45`) across associative payloads and `KEY=value` patterns in flat text. It runs in the `config` tool, in `craft_exec` output, and, belt-and-suspenders, over **every persisted audit excerpt at both dispatch sites** (`Server.php:765, 962`). Scope stated precisely: the dispatch-site backstop redacts secret-*keyed* fields; secrets embedded inside flat string values are the tool layer's job, and the two live string-output vectors (`craft_command`, `craft_exec`) both apply `redactString()` there.

**A real audit log.** Every invocation writes one structured line to the `herald` log channel *and* (Pro) one row to the `herald_invocations` table: tool, redacted arguments, redacted response excerpt, outcome kind (`ok` / `tool_error` / `internal_error` / `cancelled` / `rate_limited`), duration, user, client name, session, token correlation, and rate-limit headroom (`src/tools/support/InvocationLogger.php`, context threading at `Server.php:1197-1208`). The audit write is **soft** (a DB failure in the audit path can never break the JSON-RPC response) and the log surfaces in the CP Activity tab (§7). Streamed and non-streamed calls produce forensically identical rows.

## 6. Streaming that actually works in PHP

Streaming over PHP's request model is genuinely hard, and Herald implements the full loop rather than the easy half:

- **SSE progress.** `Server::dispatchStreaming()` yields one `notifications/progress` envelope per tool-emitted frame and a terminal response envelope, which the controller writes as SSE frames (`Server.php:448-518, 870-966`; `src/mcp/transport/SseEmitter.php`). Non-streamable tools degrade to spec-correct single-frame SSE.
- **Cooperative cancellation that survives PHP's process model.** A `notifications/cancelled` arriving on a *different* request can't signal an in-flight call through memory, so the signal goes through a cache slot keyed by session + request id (`Server.php:55-84, 1021-1052`), and the streaming loop polls a lazy `CancellationToken` between yields. Cancelled calls still emit a terminal `notifications/cancelled` envelope and a `kind=cancelled` audit row.
- **Real TCP-disconnect handling.** The controller flips `ignore_user_abort(true)`, polls `connection_aborted()` after each frame, and on a dead socket cancels the in-flight token but **keeps draining the generator** so the dispatcher's cancellation body, including the audit write, runs to completion (`McpController.php:692-760`). Most implementations just die mid-write and lose the forensic row.
- **The Fiber bridge.** `FiberProgressBridge` (`src/tools/support/FiberProgressBridge.php`) runs a blocking Craft service call (e.g. `Elements::resaveElements()`) inside a PHP Fiber and converts its per-row events into yielded progress frames, streaming progress out of an API that was never designed to stream, without forking Craft's loop. Its cancellation path drains the Fiber to termination so the temporary event listener can never leak (`:32-57`).
- **stdio stays clean.** The stdio dispatcher drains generators and emits only the final value, so no progress frames ever corrupt the line-delimited channel (`Server.php:1278-1298`), and the serve loop itself is hardened: bounded reassembling reads capped at `stdioMaxMessageBytes`, SIGTERM/SIGINT handlers, broken-pipe detection, a shutdown-function fatal envelope, and stdout-bound log targets re-pointed for the loop's lifetime (`src/console/controllers/ServeController.php:114, 230, 305, 326-335`).

## 7. The operator surface

Herald is operable from the Craft control panel, not just config files:

- **Settings**: `craft_exec` toggles and transport settings, project-config-backed so they sync across environments (`src/controllers/SettingsController.php:69`).
- **Allowlist**: live editor for command patterns plus admin-issued, auto-expiring runtime overrides (`SettingsController.php:358-501`).
- **Tokens**: issue and revoke user-bound bearer tokens via VueAdminTable + slideout; plaintext shown once at issue (`SettingsController.php:102-356`).
- **Activity**: the invocation audit log with filters and a detail slideout, **permission-scoped fail-closed**: non-admins see only their own rows, and probing a foreign row id returns 404 (`SettingsController.php:503-707`).

(Connection reference and CP skill authoring are scheduled for a Pro point release; skills are fully manageable through the `skill` tool today.)

The install story gets the same care: `herald/install` prints copy-paste config for seven MCP clients, `install/apply` writes it atomically with timestamped backups and idempotent re-runs, `install/detect` scans the host for installed clients, `install/auto` applies interactively, and the detect/auto pair refuses to run inside a container, where host detection would lie (`src/console/controllers/InstallController.php`; README "Connect your MCP client").

## 8. Extensibility

Third-party plugins extend all three registries through typed events: `Tools::EVENT_REGISTER_TOOLS` (`src/services/Tools.php:80`), `Prompts::EVENT_REGISTER_PROMPTS` (`src/services/Prompts.php:38`), `Resources::EVENT_REGISTER_RESOURCES` (`src/services/Resources.php:40`), with first-registration-wins collision logging on every surface, so an extension can never silently shadow a bundled entry. Resource URI templates allow dynamic per-element resources alongside concrete URIs (`src/resources/ResourceTemplateInterface.php`).

A dedicated generator scaffolds new tools into Craft's `make` system: `ddev craft make herald-tool` (`src/generator/Tool.php:37`) emits an `AbstractTool` subclass with attributes, a Schema DSL stub, and the house docblock conventions. The full contract (interfaces, the gating methods, attributes, the Schema DSL, naming rules, collision behaviour, and testing guidance) is documented for third parties in [`EXTENDING.md`](EXTENDING.md).

## 9. Engineering posture: the hand-rolled dispatcher, stated honestly

Herald implements its own MCP dispatcher (`src/mcp/Server.php`, ~1,400 lines) and both transports, with **no MCP SDK dependency**: runtime deps are exactly `craftcms/cms`, `league/oauth2-server`, and the skills package (`composer.json`).

What that buys: a transport-agnostic core whose context enters through narrow setters, never a request object (`Server.php:242-313`); zero third-party MCP code in the security path; adoption timing for spec revisions under Herald's own control; dispatcher behaviour that is directly Pest-tested rather than trusted upstream; and a server-side OAuth surface the official PHP SDK simply does not have yet, since there is no SDK equivalent of Herald's Pro auth layer to inherit.

What it costs, stated with current facts (verified 2026-06-10): when the MCP spec revs, SDK consumers (Drupal, Kirby, Payload) get the new revision by bumping a dependency, while Herald implements it and owns its own conformance testing. That is a real, recurring obligation, but a paid one, not a deferred one. The spec's current revision is **2025-11-25**, and Herald tracks it: `Server::PROTOCOL_VERSION` is `2025-11-25`, with `2025-06-18` negotiated for older clients (`SUPPORTED_PROTOCOL_VERSIONS`, `Server.php:38-76`; `_negotiateProtocolVersion()` echoes the client's requested version when supported, else the latest). Adopting it was sprint-scale, as the trade-off predicts: version negotiation in the handshake and the HTTP `MCP-Protocol-Version` gate, plus confirming the two server-side behavioural deltas were already satisfied: SEP-1303 (tool failures already return `isError` envelopes, not JSON-RPC protocol errors) and HTTP-403-on-bad-Origin (already enforced in `McpController::_passesOrigin`). The optional 2025-11-25 additions (icons, OIDC discovery, CIMD, tasks, elicitation) are capabilities Herald does not advertise, so they are not required to speak the revision; CIMD is queued as a thought-leadership auth follow-up. The **2026-07-28 revision** (RC, finalizing ~July 2026) is the larger event: a stateless-protocol rework that removes sessions and the `initialize` handshake, adds required methods, and deprecates DCR in favor of CIMD. It is scoped as a planned migration gate, not a sprint, with no forced urgency since revisions coexist via version negotiation. The PHP SDK remains experimental pre-1.0 (v0.6.0; pluggable transports now, but unstable API and no server-side OAuth), and because Herald's dispatcher is isolated behind one class with transport adapters on either side, adopting an SDK post-1.0 would stay a contained refactor.

The same posture runs through the rest of the codebase: PHPDoc that records *why* and cites the locked decision it implements, architecture tests that enforce conventions mechanically, and two adversarial review documents in-repo ([`REVIEW.md`](REVIEW.md), [`review/ARCHITECTURE.md`](review/ARCHITECTURE.md)) whose findings (four blockers, the major tail, and the nits) are tracked to their fixing commits. The reviews are kept, not buried: they are the receipts for the quality bar this document claims.
