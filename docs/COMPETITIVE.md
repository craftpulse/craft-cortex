# Cortex — Competitive Analysis

> **Status: not yet released.** Cortex is feature-complete on branches (`develop-v5` for Free, the Gate-7/8 lineage for Pro, and the security-hardening work on `gate-9-hardening`) but has **no Plugin Store listing and 0 public installs**. Where this document compares Cortex against shipped incumbents, that asymmetry is called out plainly. "Cortex ships X" below means "X exists in the codebase and is tested," never "X is available to buy today."

Cortex (`craftpulse/craft-cortex`) is a Model Context Protocol (MCP) server delivered as a Craft CMS 5 plugin. It runs **inside the customer's own Craft install** — content never leaves their infrastructure — and exposes Craft's internals to AI agents over two transports: **stdio** (Free, local-trust) and **Streamable HTTP per MCP spec 2025-06-18** (Pro, OAuth 2.1-authenticated). Its design thesis is that the *transport is the security boundary*: stdio is the trusted local-developer path; HTTP is treated as fully untrusted and authenticates + authorizes every request against native Craft user permissions before a tool runs.

**A note on licensing, stated once so it is not misread later.** Cortex is a **proprietary, commercially-licensed Craft plugin** (the standard Craft Plugin Store EULA — `composer.json` declares `"license": "proprietary"`). The **Free edition is free of charge but still proprietary-licensed**; the **Pro edition is paid** and license-gated through the Craft Plugin Store. (A separate Commerce plugin is future roadmap, not a Cortex edition.) This is a deliberate **supported-commercial-product posture**, the same model Pixel & Tonic and every other commercial Craft plugin vendor use. It is a maintenance-and-support commitment, **not** an "openness liability." Several competitors are MIT/GPL/source-available; that is a genuine difference in adoption friction and forkability, and this document treats it as such — but it is a trade-off, not a defect, and Cortex is not, anywhere, "MIT-licensed."

---

## 0. What Cortex is — start here

The deep, `src/`-cited version of this section is **[`CAPABILITIES.md`](CAPABILITIES.md)**; what follows is the digest. Cortex's case does not rest on rivals' weaknesses — it rests on seven things that exist in this codebase, are tested, and mostly have no equivalent elsewhere in the field:

1. **The knowledge layer.** 98 addressable documents / ~30,000 lines of hand-authored Craft expertise — 10 skills with 82 reference deep-dives plus 6 agent definitions (`michtio/craftcms-claude-skills`) — delivered as curated MCP **prompts** (primary) and per-document **resources** (secondary), with ranked keyword retrieval (`search_skills`) and an engineered discovery path: `get_initial_context` advertises the skill catalogue in the agent's first tool call. This is reverse-engineered internals (the 15-step element save lifecycle, the only written Garnish documentation in existence), not a docs scrape.
2. **Org-authored skills as Craft content.** A Pro custom **Skill element type**: teams author their own rules as elements; an element whose handle matches a bundled skill overrides its body through a deterministic merge with provenance, byte-shaped so prompts/resources/search share one path; handles are format-validated and immutable so `craft-skills://` URIs never dangle.
3. **Thick tools and deliberate absences.** 33 Free / 42 Pro mode-driven tools over a boot-once class-per-tool registry with a fluent Schema DSL — and **no raw-SQL tool, no `eval`/`tinker` tool, no shell anywhere**, enforced by a tokenizer-based architecture test, not convention. The lone privileged path, `craft_exec`, sits behind six layered gates and is **stdio-only at the dispatcher boundary**.
4. **The governance stack.** A formal three-method per-user gating contract (`shouldRegister` / `filterFor` / `inputSchemaFor`) plus an `execute()`-time re-check — defense in depth, fail closed, with permission-hidden tools wire-indistinguishable from nonexistent ones; OAuth 2.1 with DCR, S256-only PKCE, and RFC 8707 audience binding; password/email/admin mutations **refused over HTTP entirely**; an `allowAdminChanges`-aware command allowlist; secret redaction everywhere including persisted audit excerpts; per-user + anonymous-IP rate limiting; an `httpEnabled` kill switch across every HTTP controller.
5. **A persisted, secret-redacted audit log** (`cortex_invocations`) with a CP Activity viewer — one forensically identical row per call whether streamed or not, soft-written so audit failure can never break a response.
6. **Genuine streaming**: SSE progress, cooperative cancellation that survives PHP's process model (cache-slot signalling), real TCP-disconnect handling that still completes the audit write, and a PHP Fiber bridge that streams progress out of Craft's blocking service APIs.
7. **An operator and extender surface**: CP tabs (Settings / Allowlist / Tokens / Activity), a seven-client install toolkit, typed `EVENT_REGISTER_*` extension events with collision logging, and a `make cortex-tool` generator.

Quality bar: **1,120 Pest tests / 0 skipped, PHPStan level 8 clean, ECS clean**, plus two adversarial in-repo reviews with every finding tracked to its fixing commit.

The honest gaps are also stated up front: Cortex is **unreleased** against shipped incumbents; its knowledge retrieval is **keyword search today** (vectorized retrieval is Phase-3 roadmap, and several rivals ship semantic search now); and it hand-rolls its MCP dispatcher rather than inheriting an SDK's spec maintenance — a real trade-off examined in §5.

---

## 1. The direct competitor — craft-mcp: a source-level audit

[`stimmtdigital/craft-mcp`](https://github.com/stimmtdigital/craft-mcp) (v1.2.2, commit `c1f49ee`, 2026-03-04; MIT; Craft `^5.0`, PHP `^8.3`, `mcp/sdk ^0.4`) is the only other Craft-native MCP server in the public ecosystem, and therefore the comparison that matters most. This section is built from a line-level read of its source at tag v1.2.2, not from its README.

### Verdict

**craft-mcp is competently engineered but deliberately insecure, and it under-implements even its own documented safeguards.** The code itself is clean and modern — `strict_types` throughout, class/method PHPDocs, `final` support classes, `match` over `switch`, early returns, a Pest suite with an ArchTest, Pint/Rector/PHPStan-with-sanmai-rules, CI, and a genuinely polished multi-client installer. The author is also commendably *honest in the docstrings*: `tinker` and `run_query` carry explicit "bypassable, dev-only, NOT a secure sandbox" warnings.

But as a *product* it is stdio-only with **no authentication, no Craft-permission enforcement on any tool**, a **raw-SQL `run_query`** guarded only by a bypassable keyword blocklist, a **`tinker` tool that calls `eval()`** behind a regex blocklist the author admits is bypassable, and write tools (`create_entry`/`update_entry`, GraphQL mutations, `create_backup`) that save elements with **no `canSave`/`canDelete` check** and fall back to **impersonating the first admin** because stdio has no user identity. Most damning: its safety switches — `enableDangerousTools`, `disabledTools`, `allowedIps` — are **defined but never enforced at dispatch**. The SDK's `setDiscovery` auto-registers every annotated tool regardless, and `isToolEnabled()` is consulted only to *report* status in a listing tool, never before a `tools/call`.

Versus Cortex the gap is **categorical, not incremental** — and it is a gap in security/governance design, not in code craftsmanship. craft-mcp is a reasonable **local single-developer dev aid**. It is not a governed, multi-user, remotely-reachable content-operations server, which is exactly Cortex's Pro target.

### Security findings (evidenced in their repo)

| Severity | Finding | Evidence in craft-mcp | Risk | How Cortex handles it |
|---|---|---|---|---|
| **Critical** | `tinker` executes arbitrary PHP via `eval()` | `TinkerTools.php:101` `eval` after PsySH CodeCleaner; only guard is `BLOCKED_PATTERNS:40-58` (literal `exec`/`shell_exec`/`eval`/`file_put_contents`). Docblock `:29-34` admits bypassable via `call_user_func`/variable functions, "NOT a secure sandbox." | RCE-equivalent for any caller of the stdio server; full host compromise. | **No `eval`/`tinker` tool exists.** The one privileged path, `craft_exec`, runs through Craft's own eval the same way `ExecController` does — behind six gates (dry-run default, structured output, secret redaction, destructive-op guard, hard HTTP rejection, `destructiveHint` annotation), **stdio-only**, arch-tested against shell-exec. |
| **Critical** | Safety switches `enableDangerousTools` / `disabledTools` / `allowedIps` never enforced at dispatch | `Mcp::isToolEnabled()` (`Mcp.php:243-268`) is called only by the `listMcpTools` reporter (`:84`), never before a call. `setDiscovery` registers every `#[McpTool]` unconditionally. `allowedIps` (`Settings.php:32`) referenced nowhere. | Disabling a dangerous tool or listing it in `disabledTools` does **not** stop it being called. Fails open; contradicts the docs. | Gating runs **at registration** via static `shouldRegister()` and **per request** via `filterFor`/`inputSchemaFor`, with an `execute()` re-check — fail closed, Pest-verified. A Pro tool is never even loaded into the Free registry. |
| **High** | `run_query` raw-SQL tool behind a bypassable keyword blocklist | `DatabaseTools.php:137-170`: must start with `SELECT`, rejects on `INSERT/UPDATE/DELETE/DROP`, runs `createCommand()->queryAll()` (`:160`). Docblock `:130-134` warns bypassable on some PDO configs. | Unauthenticated full-DB read of PII and `auth` tokens, DoS via heavy SELECT, possible write via stacked statements on MySQL emulated prepares. No scoping. | **No raw-SQL tool ships, by design.** Access is via typed, permission-scoped element tools; PII is gated to Pro. |
| **High** | Write/mutation tools perform no authorization and impersonate an admin | `EntryTools.php:98-188` calls `saveElement` with no `canSave`; `getAuthorId:232-239` returns the first admin when identity is null (always, under stdio). `execute_graphql` (`GraphqlTools.php:107-159`) runs the public schema incl. mutations. `create_backup` dumps SQL on demand. | Any caller writes entries/categories/globals and runs GraphQL mutations as admin, no per-user check, no attribution. | Pro writes enforce Craft permissions via `filterFor` **plus** an `execute()` re-check, refuse password/email/admin mutations over HTTP, and audit-log every call. |
| **Medium** | Config/env tools leak secrets unredacted | `SystemTools::getConfig:37-66` — `get_config db.password` returns the credential; `general` with no setting returns the whole `GeneralConfig` incl. `securityKey`. No redaction layer anywhere. | DB password, security key, and other secrets retrievable via ordinary tool calls and via verbatim exception messages. | A `SecretRedactor` runs **everywhere**, including over persisted audit excerpts; secrets come from `App::env()` and are never echoed. |
| **Low** | Production-default protection is all-or-nothing and easily overridden | `applyProductionDefaults` (`Mcp.php:222-231`) disables only when production **and** no config file; once `config/mcp.php` exists the guard is skipped (`:196-217`). `enableDangerousTools` defaults `true` (`Settings.php:29`). | The safe default vanishes the moment an operator creates the required config file — with dangerous tools on. | Edition + settings gating at registration, plus an `httpEnabled` kill switch and the transport-as-boundary model. |
| **Info** | No audit logging of tool invocations | Only a `FileLogger` writing SDK protocol logs to `storage/logs/mcp-server.log`. No per-call audit, no DB table. | No tamper-evident record of what a destructive call ran, or with what arguments. | `cortex_invocations` persists one redacted row per call (args + response excerpt), surfaced in a CP Activity viewer. |

> Note on exception handling: craft-mcp's `SafeExecution` rethrows raw exception messages verbatim to the client (via `ExceptionFormatterTrait`), which can leak table/column names and SQL; `tinker` bypasses `SafeExecution` entirely, and `bin/mcp-server` prints full traces to STDERR (`:99-100`). Cortex returns tool-execution failures as `{content:[…], isError:true}` envelopes (not protocol errors) so the LLM can self-correct, and routes raw detail to the `cortex` log channel, not the wire.

### Architecture contrast

| Dimension | craft-mcp v1.2.2 | Cortex |
|---|---|---|
| Transport | **stdio only.** `McpServerFactory::createTransport:60-62`. README suggests SSH-tunnel for remote (not recommended for production). No HTTP, so **no network auth layer at all**. | stdio (Free) **and** Streamable HTTP per MCP 2025-06-18 (Pro), with `outputSchema`/`structuredContent` dual-emit. |
| Auth model | **None.** stdio treated as fully trusted; `getIdentity` is null everywhere; runs with full Craft app access as whatever console user launched it. | stdio = local trust; HTTP = OAuth 2.1 (`league/oauth2-server`: DCR/RFC 7591, PKCE S256, audience binding/RFC 8707, AS + protected-resource metadata) **+** long-lived bearer tokens bound to Craft users; `httpEnabled` kill switch; anonymous OAuth endpoints IP-throttled. |
| Tool model | Attribute discovery (`#[McpTool]` + `#[McpToolMeta]`), SDK `setDiscovery` auto-registers every annotated method. Clean registry, no switch — but **registration is unconditional**. | Class-per-tool registry built once at boot through `Tools`; `EVENT_REGISTER_TOOLS` extension hook; first-registration-wins collision logging; edition/settings gating applied at registration. |
| Permission enforcement | **None** anywhere in `src` (grep: zero `requirePermission`/`canSave`/`canDelete`/`canView`). | Native Craft permissions at the tool **and** mode layer, fail-closed, defense-in-depth `execute()` re-check. |
| Privileged execution | `tinker` → `eval`; `run_query` → raw SQL; `create_backup` → SQL dump. Arch tests do **not** forbid `eval`/`exec`/`shell_exec`. | `craft_exec` only, six gates, stdio-only; **no** `Process`/`exec`/`shell` anywhere, enforced by a tokenising architecture test. |
| Extensibility | Good — typed `RegisterTools/Prompts/Resources` events, `ConditionalToolProvider` contract, completion providers. No generator, no schema DSL. | `EVENT_REGISTER_{TOOLS,PROMPTS,RESOURCES}` + a `ddev craft make cortex-tool` generator + a chainable Schema DSL + `EXTENDING.md`. |
| Operator UI | **None** (`hasCpSettings=false`); config via `config/mcp.php` only. | Tabbed CP operator surface — Settings, Allowlist, Tokens, Activity shipped (Connection + Skill-authoring slipped to a point release). |

### What craft-mcp does well (fair credit)

- **Clean, modern PHP**: `strict_types`, full PHPDocs, `final` support classes, `match` over `switch`, early returns.
- **Honest in-code warnings**: `tinker`/`run_query` docstrings explicitly state they are bypassable, dev-only, and not a sandbox — the author is not hiding the risk.
- **Sensible extensibility**: typed register events, a `ToolRegistry` with source grouping + error isolation, a `ConditionalToolProvider` contract, completion providers.
- **MIT-licensed and self-hostable**, fully open, no phone-home — genuinely lower adoption friction than a proprietary plugin.
- **Good tooling and a polished installer** with DDEV-aware detection for Claude Code / Claude Desktop / Cursor.
- **Reasonable production first line**: disables the plugin when no config file exists.
- **Responsive maintenance**: the v1.2.2 `MutexGuard` fix resolved a real project-config deadlock in the long-running `tinker` path.
- **Curated safe surfaces where the author bothered**: `get_environment` whitelists env vars rather than dumping them all.

The summary: craft-mcp is the better-adopted, MIT, install-it-now option for a **single trusted developer on a local box**. Cortex is the governed, audited, multi-user, remote-capable option — and on the security/governance axis the two are not in the same class.

---

## 2. Feature comparison matrix

Legend: ✓ yes · ✗ no · ~ partial/qualified · **!** security-negative. "?" = undocumented/unknown from public sources.

| Feature | Cortex | craft-mcp | Drupal MCP suite | Sanity | Contentful | Statamic MCP | Kirby MCP | Payload | Directus | Laravel Boost |
|---|---|---|---|---|---|---|---|---|---|---|
| MCP-native | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| stdio transport | ✓ | ✓ (only) | ✓ | ~ proxy fallback | ✓ (local) | ✓ (CLI) | ✓ (default) | ✗ | ✓ | ✓ (only) |
| HTTP / remote transport | ✓ (Pro) | ✗ | ✓ | ✓ (primary) | ✓ (remote Beta) | ✓ (default) | ~ (off by default) | ✓ (only) | ✓ | ✗ |
| Auth model | OAuth 2.1 + bound bearer | **none** | OAuth 2.1 / API key | OAuth / bearer | API key / OAuth 2.1 | OAuth 2.1 (custom) + bearer | bearer / JWT (no PKCE/DCR) | bearer API key only | OAuth 2.0 / bearer | none (local) |
| Per-user ACL | ✓ native Craft perms | ✗ | ~ RBAC (delegated) | ~ token-scoped | ~ (remote, delegated) | ✓ token→CP user | ✗ (scope only) | ✓ (delegated) | ✓ (delegated) | ✗ |
| Per-tool / per-mode gating | ✓ 3-method + mode-enum rewrite | **! defined, not enforced** | ✓ (scopes/toolsets) | ~ | ~ (per-env, not per-user) | ✓ (21 scopes) | ~ (scope only) | ✓ (per-key) | ✓ (toolsets, whole-tool) | ~ |
| Write-capable | ✓ (Pro, perm-gated) | ✓ (unauthorized) | ✓ | ✓ | ✓ | ✓ (all free) | ✓ (free) | ✓ | ✓ | ✓ (Tinker/Artisan) |
| Raw-SQL tool | ✗ (by design) | **! `run_query`** | ✗ | ✗ (~GROQ read) | ✗ (~broad CMA) | ✗ | ✗ (flat-file) | ✗ | ✗ | **! Database Query** |
| Secret redaction | ✓ everywhere incl. audit | ✗ | ? | ? | ? | ~ (PII in logs only) | ~ (dump logs only) | ~ DIY hook | ? | ? |
| Audit log | ✓ DB, redacted | ✗ | ~ (mcp_tools only) | ~ (revision history) | ? | ✓ (pluggable) | ✗ | ~ DIY hook | ~ (activity log) | ? |
| Streaming progress | ✓ SSE | ✗ | ? | ? | ? | ? | ✗ | ? | ? | ? |
| Cooperative cancellation | ✓ (Fiber bridge) | ✗ | ? | ? | ? | ? | ✗ | ? | ? | ? |
| TCP-disconnect handling | ✓ (Fiber bridge) | ✗ | ? | ? | ? | ? | ✗ | ? | ? | ? |
| Bundled knowledge corpus | ✓ ~30k lines | ✗ | ✗ | ~ product docs | ✗ | ✗ (discovery tools) | ✓ 216 KB + 15 skills | ✗ | ✗ | ✓ guidelines + skills |
| Vectorized retrieval | ✗ (keyword; Phase 3) | ✗ | ✓ (in AI module, not MCP) | ✓ semantic_search | ✓ semantic_search | ✗ | ✗ (keyword) | ~ (Enterprise RAG, separate) | ✗ | ✓ (17k+ doc chunks) |
| CP / admin UI | ✓ tabbed (Settings/Allowlist/Tokens/Activity) | ✗ | ✓ | ✓ (Studio) | ✓ | ✓ (Vue 3) | ✗ (CLI only) | ✓ | ✓ | ✗ |
| Editions / commercial | Free + paid Pro | single free | all GPL free | Free/Growth/Enterprise + AI credits | free MCP + paid AI Actions | single free | single free | free MCP + Enterprise RAG | core free + paid tiers | single free |
| Self-hosted | ✓ | ✓ | ✓ | ✗ (remote SaaS) | ~ (local server → SaaS) | ✓ | ✓ | ✓ | ✓ | ✓ |
| License | **proprietary (Craft)** | MIT | GPL-2.0 | MIT (Studio) / proprietary SaaS | MIT (local) / proprietary SaaS | MIT | MIT | MIT | MSCL / MIT (local pkg) | MIT |
| Maturity / adoption | **unreleased, 0 installs** | v1.2.2, single-author | AI ~15k sites; MCP server **pre-stable (~2–327 installs)** | GA Dec 2025, mature | repo young; vendor large | v2.6.0, ~29 releases, mature | v1.8.0, ~2,985 installs | v3.85.1, huge parent | GA v11.12+, marketed | v2.4.10, ~3.5k stars, bundled-by-default |

---

## 3. Architecture comparison matrix

| Product | Deployment model | Server / tool architecture | Transport + spec | Security boundary / permission enforcement | Extensibility | Streaming impl | Knowledge delivery |
|---|---|---|---|---|---|---|---|
| **Cortex** | Self-hosted Craft plugin (in-process) | Class-per-tool registry, boot-once, transport-agnostic JSON-RPC dispatcher | stdio + Streamable HTTP, MCP 2025-06-18, `structuredContent` dual-emit | **Transport = boundary**; native Craft per-user perms at tool+mode, fail-closed, `execute()` re-check; craft_exec stdio-only | Register events + generator + Schema DSL | SSE progress + cooperative cancel + TCP-disconnect via PHP Fiber bridge | ~30k hand-authored lines as MCP prompts/resources + Pro Skill element; keyword search |
| **craft-mcp** | Self-hosted; `bin/mcp-server` boots Craft console | Attribute discovery, SDK auto-registers all tools | stdio only | **None** — stdio fully trusted, no identity, no perm checks | Typed register events, ConditionalToolProvider | Partial progress calls; no cancel/disconnect | Thin prompt/resource generators; no corpus |
| **Drupal MCP** | Self-hosted Drupal; fragmented modules | Official PHP MCP SDK + Tool API plugins + config entities | stdio (Drush) + HTTP `/_mcp`; Streamable-HTTP inferred | Per-key/RBAC scopes; OAuth 2.1 (Simple OAuth) | Tool API plugins via CP UI | ? | None in MCP modules (AI Search vectors live in the AI module) |
| **Sanity** | Managed remote SaaS (Content Lake) | Hosted gateway brokering to Content Lake; thin API wrappers | HTTP (primary); stdio via `mcp-remote` proxy | Hosted proxy; token's Content Lake RBAC role; OAuth-default | n/a (managed) | Context pagination; progress ? | Product docs (search_docs) + semantic_search over embeddings |
| **Contentful** | Two servers: local OSS (in-env) + remote hosted SaaS | SDK + mcp-handler; ~52 tools; all funnel through CMA | stdio (local) + HTTP (remote Beta) | PAT perms + `PROTECTED_ENVIRONMENTS` (local); user role + per-env allow-list + read/write toggle (remote) | Custom tools/prompts via config | ? | None; `get_initial_context` = runtime context; semantic_search over content |
| **Statamic MCP** | Self-hosted Statamic 6 / Laravel addon | `BaseStatamicTool` action-routed domain routers (~11) | Streamable HTTP (default) + stdio CLI | HTTP token guard, 21 scopes, custom OAuth 2.1 server; **CLI bypasses auth** | Tool classes w/ attributes | ? | Runtime discovery/schema tools; no corpus |
| **Kirby MCP** | Self-hosted Kirby 5 Composer pkg / CLI binary | Official PHP MCP SDK; boots Kirby runtime; **shells out via symfony/process** | stdio (default) + optional Streamable HTTP `/mcp` | Token-scope only, **not wired to Panel perms**; bearer/JWT, no PKCE/DCR | resource templates | resources/updated notifications only | 216 KB KB + 15 skills; keyword search |
| **Payload** | Self-hosted Next.js app (in-process `/api/mcp`) | Official TS SDK + Vercel mcp-handler; tools auto-derived from collections | HTTP only (no stdio) | **Delegated** to Payload access rules/hooks/multi-tenant per API key | Per-key toggles + custom tools | mcp-handler supports it; undocumented in Payload | None (DIY); Enterprise RAG separate, not MCP-wired |
| **Directus** | Self-hosted/Cloud; built-in core + standalone npm | Coarse "toolsets" → features; single items tool for CRUD | Streamable HTTP `/mcp` + stdio (local npm) | **Delegated** to Directus user RBAC; OAuth 2.0 (DCR/CIMD) or bearer; UI toolset toggles | Tool API / user prompts collection | ? | Generic system-prompt tool + user prompts; no corpus |
| **Laravel Boost** | Local dev-dependency (workstation only) | `laravel/mcp` tool classes; ~15 inspection tools | stdio only (HTTP+OAuth lives in sibling `laravel/mcp`) | **Local trust only** — env-gated to local/testing, no auth | Agent contracts (SupportsGuidelines/Mcp/Skills) | ? | Guidelines + Agent Skills + hosted Documentation API (semantic) |

---

## 4. Per-competitor analysis

### Craft-native

#### craft-mcp (`stimmtdigital/craft-mcp`)

Covered in depth in §1 — the direct competitor and the centerpiece of this analysis. Summary below for matrix-parity.

**Where craft-mcp is ahead of Cortex**
- Shipped, MIT-licensed, and adoptable today; Cortex is unreleased and proprietary.
- Cleaner *code-craft* tooling story in places (Rector, Pint, sanmai PHPStan rules) and a polished installer.
- Honest, candid security docstrings.

**Where Cortex is ahead**
- Every security/governance axis: auth (OAuth 2.1 vs none), permission enforcement (native Craft vs none), no raw-SQL/`eval` tools vs both, enforced gating vs decorative switches, secret redaction, a real audit log, HTTP transport, and a CP operator UI.
- Streaming (SSE + cancel + disconnect) and a 30k-line knowledge corpus craft-mcp has no equivalent of.
- A tested quality bar (1,120 Pest / PHPStan L8 / arch-test against shell-exec).

### Direct framework ancestor — Laravel Boost

[`laravel/boost`](https://github.com/laravel/boost) (MIT, v2.4.10) is the conceptual ancestor of Cortex's design: a tool registry, stdio local-trust, bundled guidelines/skills, and vectorized docs. But it is a **developer-workstation coding aid** installed `--dev` and gated to `local`/`testing`, not an authenticated content-ops server. Its standout is a hosted Documentation API doing **embedding-based semantic search over 17,000+ versioned ecosystem chunks** — a capability Cortex does not yet have.

**Where Boost is ahead of Cortex**
- **Vectorized/semantic retrieval today** over 17k+ always-current doc chunks; Cortex's `search_skills` is keyword-only (Phase-3 roadmap).
- First-party, Laravel-team-maintained, and **bundled by default in new Laravel apps** — instant reach; ~3.5k stars, broad multi-agent IDE support.
- MIT-licensed, frictionless to adopt.
- `Tinker`/`Artisan` as first-class tools give an agent broad live-introspection-and-execution power on the dev box.

**Where Cortex is ahead**
- A real security model vs **local-trust only** — Boost has no auth, no per-user ACL, no audit, no documented secret redaction.
- **No raw-SQL/`eval` escape hatch** — Boost ships a `Database Query` tool and arbitrary-PHP `Tinker` where read-only is convention, not an enforced gate.
- A genuine remote HTTP content-ops transport (Boost is stdio/local only; its HTTP+OAuth story lives in the separate `laravel/mcp` package).
- A CP operator UI; permission-gated content-write tooling; SSE progress + cancellation + disconnect handling; a persisted redacted audit trail and an arch-test against shell-exec.
- Deeper hand-authored Craft expertise (~30k lines) vs Boost's broad-but-shallower guideline files — though Boost's *retrieval* is semantic and Cortex's is keyword.

### Headless SaaS

#### Sanity (AI Assist + Agent Actions + remote MCP)

Sanity's [remote MCP server](https://www.sanity.io/docs/ai/mcp-server) went GA on 2025-12-15: a fully managed endpoint at `mcp.sanity.io` with 40+ tools, OAuth-default auth, and genuine `semantic_search` over embeddings. The local npm server is now deprecated. AI features are paid-plan-gated and metered in AI credits.

**Where Sanity is ahead**
- Shipping and GA with real adoption; Cortex is unreleased.
- **True vectorized semantic retrieval** today.
- Fully managed remote infra (Sanity runs rate limiting, metrics, auto-updates) — zero customer ops.
- First-party AI image generation/transformation tools; zero-config OAuth onboarding; end-to-end AI in one schema-aware, credit-metered platform.

**Where Cortex is ahead**
- **Transport-as-security-boundary** with a stdio-local-trust concept; Cortex keeps `craft_exec`/admin mutations stdio-only and refuses dangerous ops over HTTP. Sanity is remote-only.
- A formal per-tool/per-mode gating contract + `execute()` re-check vs RBAC-on-the-token only.
- A dedicated, secret-redacted **invocation audit log** (Sanity relies on document revision history).
- **Self-hostable** — data never leaves the customer's infra; Sanity is remote-only and its self-host path is deprecated.
- Full OAuth 2.1 spec depth (DCR/PKCE/audience binding/AS metadata) documented; Sanity documents OAuth-default but not this surface.
- A broad read surface free-of-charge with no per-call metering; SSE progress + cooperative cancellation; a 30k-line hand-authored corpus vs product-docs search.
- A deliberate **no arbitrary-query primitive** — Sanity's arbitrary-GROQ tool is a broad read surface bounded only by token role; Cortex excludes PII from Free.

#### Contentful (MCP server + AI Actions)

Contentful ships [two MCP servers](https://www.contentful.com/developers/docs/tools/mcp-server/) — a local MIT OSS server (stdio, PAT auth) and a remote hosted server (HTTP, OAuth 2.1, Beta) — plus AI Actions, a paid no-code automation layer with multi-provider BYOM.

**Where Contentful is ahead**
- Remote hosted HTTP MCP server is live (Beta), vendor-run — zero infra.
- Local server is MIT/open-source.
- `semantic_search` ships today; mature, governed, multi-provider AI Actions (OpenAI/Bedrock/Gemini/Vertex/Azure OpenAI) — no Cortex equivalent.
- Massive enterprise distribution and SaaS operational maturity; per-user session-scope selection at consent.

**Where Cortex is ahead**
- Local server's only model is "whatever the PAT can do" — no per-user/per-tool/per-mode gating. Cortex enforces a 3-method contract + `execute()` re-check even on its trusted stdio path.
- Remote server's read/write gating is **per-environment, applied uniformly to all users** — not per-user mode gating. Cortex gates the tool *and* the mode enum per individual user.
- No documented audit log, secret redaction, streaming progress, cancellation, disconnect handling, or MCP-layer rate limiting; Cortex ships all of these.
- `PROTECTED_ENVIRONMENTS` runs only inside the MCP server and is bypassed by any other client — advisory vs Cortex's transport-as-boundary model.
- A curated thick-tool design with explicit gates vs a very broad CMA write surface governed mainly by token scope; a bundled domain corpus; a published quality bar.

### Other self-hosted

#### Drupal AI + MCP modules (`mcp` / `mcp_server` / `mcp_tools` / `mcp_client`)

Drupal's surface is a **fragmented constellation of GPL contrib modules**, not one product. The flagship AI module is mature (~15,200 sites) but is an *in-Drupal AI layer, not an MCP server*. The actual MCP servers are early-stage: `mcp_server` (~2 installs, no stable release, no security coverage), `mcp` (~327 installs, merging away), the third-party `mcp_tools` (beta7, ~73 installs, **explicitly outside Drupal's security advisory policy**, 222 tools), and `mcp_client` (alpha).

**Where Drupal is ahead**
- Massive ecosystem gravity and a 48+ provider AI integration with an AI Agents framework.
- Built on the **official PHP MCP SDK** (PHP Foundation + Symfony), inheriting upstream spec maintenance; Cortex hand-rolls its dispatcher.
- Ships an MCP **client** too (`mcp_client`) — Drupal can serve *and* consume; Cortex is server-only.
- First-class vectorized retrieval exists in-platform (AI Search over Milvus/Pinecone/etc.); config-driven tool exposure via CP without writing a tool class; raw breadth (222 tools incl. Views/Layout Builder).
- Fully GPL/free across the board.

**Where Cortex is ahead**
- **Maturity & trust** — the official-track Drupal MCP server is pre-stable, low-install, with uneven/absent security coverage; Cortex has 1,120 Pest tests / PHPStan L8 / ECS clean and an arch-test against shell-exec.
- **One coherent product** vs four overlapping, partially-merging modules.
- A stronger security-boundary model (transport-as-boundary; OAuth 2.1 with DCR/PKCE/audience binding; refuses password/email/admin over HTTP) vs per-key/RBAC scopes.
- Uniform secret redaction and a uniform redacted DB audit log vs uneven/undocumented logging.
- SSE progress + cooperative cancellation + TCP-disconnect; a fenced six-gate stdio-only `craft_exec` with no `Process`/`exec`/`shell` anywhere, vs `mcp_dev_tools`' controlled-Drush broad surface.
- A bundled hand-authored corpus; explicit PII-to-Pro gating; explicit Streamable-HTTP 2025-06-18 with dual-emit (only inferred on the Drupal side).

#### Statamic MCP (`cboxdk/statamic-mcp`)

[`cboxdk/statamic-mcp`](https://github.com/cboxdk/statamic-mcp) (MIT, v2.6.0, ~29 releases) is the **most operationally complete CMS MCP server in the public ecosystem** — a Vue 3 CP dashboard (Connect/Tokens/Activity/Settings), 21 RBAC scopes, audit logging with PII redaction, dry-run on all writes, confirmation gating on destructive ops, and a deliberate collapse from 140+ tools to ~11 action-routed domain routers. This is the closest peer to Cortex on operational maturity.

**Where Statamic is ahead**
- **Shipped and mature** (~29 releases, real adoption); Cortex is unreleased.
- MIT, fully free; **full CRUD available to everyone in the single free edition** (Cortex gates every write behind paid Pro).
- **Uniform dry-run-with-diff/patch on every write** + confirmation gating on destructive ops — a first-class universal safety pattern; Cortex relies on per-tool design rather than a documented universal dry-run/confirm contract.
- Native CP dashboard parity already in production (all four tabs shipped) — Cortex's Connection + Skill-authoring slipped to a point release.
- Respects the CMS editorial/revision workflow on writes; OAuth DCR + CIMD resolution.

**Where Cortex is ahead**
- Custom hand-rolled OAuth 2.1 server vs Cortex's **`league/oauth2-server`** foundation, plus documented RFC 8707 audience binding, full AS + protected-resource metadata, and IP-throttled anonymous endpoints — a more standards-complete, auditable surface.
- **Streaming** (SSE progress + cooperative cancellation + real TCP-disconnect via the Fiber bridge); Statamic documents none.
- A **30k-line bundled knowledge corpus** (Statamic offers only runtime discovery/schema tools).
- Transport-as-boundary discipline + the 3-method gating contract + `execute()` re-check + **refusing password/email/admin mutations over HTTP entirely**; Statamic's gating is scope-token-based and its **CLI path bypasses auth wholesale**.
- Secret redaction *everywhere incl. persisted audit excerpts* (Statamic documents PII-in-logs redaction only); PII gated to paid tier; an arch-test against shell-exec; a published test/static-analysis quality bar (Statamic publishes none).

#### Kirby MCP (`bnomei/kirby-mcp`)

[`bnomei/kirby-mcp`](https://github.com/bnomei/kirby-mcp) (MIT, v1.8.0, ~2,985 Packagist installs, ~54 stars) is a CLI-first MCP server for Kirby 5, built on the official PHP MCP SDK. It bundles a 216 KB knowledge base + 15 skills, ships `kirby_eval` (off by default) and shells out via `symfony/process` for CLI commands — a deliberate architectural opposite to Cortex's no-shell rule.

**Where Kirby is ahead**
- **Released and adopted** (~2,985 installs, active monthly releases); Cortex has 0.
- MIT, freely forkable; all write tools and the full HTTP transport are in the single free package (Cortex gates writes/HTTP behind Pro).
- Built-in OAuth authorization-server provider with a configurable consent UI — turnkey for Claude.ai/Desktop custom connectors; IDE-helper generation and a "next tool" recommender; first-mover in its ecosystem.

**Where Cortex is ahead**
- Authorization is **token-scope only and explicitly NOT wired to CMS user permissions**; Cortex enforces native per-user perms at tool+mode with an `execute()` re-check.
- Weaker HTTP auth — **no PKCE, no DCR**; Cortex ships the full OAuth 2.1 profile.
- **No persisted invocation audit log** (Kirby's redaction covers only `mcp_dump()` debug logs); Cortex persists redacted `cortex_invocations`.
- No streaming progress/cancellation, no CP/admin UI (CLI-only); Cortex has both plus a tabbed CP surface.
- Ships `kirby_eval` and **shells out via `symfony/process`**; Cortex forbids all `Process`/`exec`/`shell` and confines `craft_exec` behind six gates, stdio-only.
- Per-user + anonymous-IP rate limiting; PII edition gating; a far larger corpus (~30k lines vs 216 KB — both keyword); a published automated-quality posture.

#### Payload (`@payloadcms/plugin-mcp`)

[`@payloadcms/plugin-mcp`](https://payloadcms.com/docs/plugins/mcp) (MIT, v3.85.1) mounts an MCP HTTP endpoint at `/api/mcp` inside a running Payload app, auto-generating CRUD tools from opted-in collections. Its model is **delegation**: every request carries a user-bound bearer API key, and all operations run through Payload's own access rules, hooks, and multi-tenant scoping.

**Where Payload is ahead**
- Shipped, MIT, inside a huge-install-base parent project.
- Write tools are first-class and **trivially symmetric** — any opted-in collection auto-generates CRUD from the content model; Cortex's writes are hand-built and Pro-gated.
- ACL delegated to the framework's battle-tested access-control/hooks/multi-tenant system — zero duplication, native multi-tenant.
- Built on the official TS SDK + maintained `mcp-handler` (tracks upstream for free); ships a production Enterprise RAG/auto-embedding capability (separate from MCP) — Cortex's vectorized search is only roadmapped.

**Where Cortex is ahead**
- Auth is **static user-bound bearer API keys only** — no OAuth 2.1, no DCR, no PKCE, no audience binding; Cortex ships all of these (the direction the MCP spec is moving for remote servers).
- **No transport-as-security-boundary distinction** — Payload has no stdio mode at all; Cortex separates trusted stdio from untrusted HTTP and forces dangerous ops stdio-only.
- Redaction and audit are **DIY hooks** (`overrideResponse`, `onEvent`) with nothing built in; Cortex redacts by default and ships a real redacted DB audit log.
- No documented rate limiting, streaming progress, or cancellation; Cortex ships per-user + anonymous-IP limiting, SSE progress, cancellation, and disconnect handling.
- No bundled domain corpus; a stronger, explicitly-tested gating + fail-closed re-check posture with an arch-test against shell-exec.
- Payload's RAG and MCP are **unconnected**; Cortex's knowledge is delivered *through* the MCP surface (keyword today).

#### Directus (native MCP)

[Directus](https://directus.com/docs/guides/ai/mcp) ships two MCP surfaces — a built-in HTTP server in core (v11.12+, OAuth 2.0 w/ DCR + CIMD or bearer) and a standalone MIT npm server over stdio. Both **delegate all authorization to the connecting Directus user's RBAC**; coarse "toolsets" toggle whole tool groups. Core is now MSCL (source-available); the npm package is MIT.

**Where Directus is ahead**
- Shipping and GA in production today, two transports, broad client compatibility.
- First-class **write tools available to any permissioned user with no paid gate**; can create/modify **schema** (collections/fields/relations) and trigger automation flows via MCP — a content-architecture write surface Cortex doesn't expose.
- Source-available core with a genuinely free tier + MIT standalone package; database-agnostic, multi-framework reach.

**Where Cortex is ahead**
- **No defense-in-depth gating model** in Directus — authorization is wholly delegated to RBAC with only coarse UI toolset toggles; Cortex adds the 3-method contract + `execute()` re-check.
- No documented OAuth hardening beyond DCR/CIMD — no PKCE/audience-binding/AS metadata documented; Cortex implements the full 2025-06-18 profile.
- No documented secret redaction and **no MCP-specific redacted audit table** (relies on the generic activity log); Cortex persists a dedicated redacted audit.
- No bundled domain corpus (only a generic system-prompt tool + user prompt templates); no documented streaming/cancellation/disconnect handling.
- Weaker explicit security model around destructive/host actions — no-shell-exec, six-gate stdio-only `craft_exec`, per-user + anonymous-IP rate limiting are all Cortex-side and undocumented for Directus; write/schema/delete tools are on by default subject only to RBAC + manual toggles.
- No per-request input-schema rewriting (mode-enum filtering); Directus only enables/disables whole tools.

---

## 5. Cortex's positioning

**Where Cortex wins across the board.** On the **governance and security axis**, Cortex leads or ties the entire field:

- It is the **only** server that combines transport-as-security-boundary, native per-user permission enforcement at *both* the tool and the mode-enum layer, a fail-closed `execute()` re-check, secret redaction *everywhere including persisted audit excerpts*, a dedicated redacted DB audit log, **and** a no-raw-SQL / no-`eval` / no-shell-exec design enforced by an architecture test.
- It is the **only** one with documented genuine streaming end-to-end: SSE progress + cooperative cancellation + real TCP-disconnect handling (the PHP Fiber bridge).
- Its OAuth 2.1 surface is among the most standards-complete in the field — `league/oauth2-server` foundation, DCR/RFC 7591, PKCE S256, audience binding/RFC 8707, AS + protected-resource metadata, IP-throttled anonymous endpoints — matched in spirit only by Statamic (custom server) and partially by Directus/Sanity/Contentful.
- Its bundled **~30,000-line hand-authored Craft corpus** is the deepest domain-expertise payload of any server here (Kirby's 216 KB and Boost's guideline files are the only other bundled corpora; the SaaS players surface product docs, not authored expertise).

**Where each rival wins.**

- **craft-mcp, Statamic, Kirby, Payload, Directus, Boost** — *shipped and adopted*; Cortex is not. **MIT/GPL/source-available** vs Cortex's proprietary license — lower adoption friction and forkability.
- **Statamic** — most operationally mature CMS MCP server; full free CRUD; universal dry-run/confirm pattern; all CP tabs shipped.
- **Boost, Sanity, Contentful, Drupal (AI module), Payload (Enterprise)** — **vectorized/semantic retrieval today**; Cortex is keyword-only.
- **Sanity, Contentful, Directus** — managed/hosted remote infra and broad enterprise distribution; first-party AI authoring and (Sanity/Contentful) multi-provider BYOM automation.
- **Drupal, Payload** — built on the official MCP SDK (upstream spec maintenance for free); Drupal also ships an MCP *client*.
- **Payload, Directus** — auto-generated symmetric write tools / schema-write surface with near-zero authoring cost.

**The honest gaps — framed correctly.**

1. **Unreleased vs incumbents.** This is the single biggest real disadvantage. Most rivals here are shipped, some widely adopted. Cortex's tested-and-complete-on-branches status is not the same as installable-today, and the comparison must keep saying so until a Plugin Store cut lands.
2. **Keyword vs vectorized retrieval.** `search_skills` is keyword today; vectorized `search_docs` is Phase-3 roadmap. Boost, Sanity, Contentful, and Drupal's AI module ship semantic search now. Cortex's counter is *depth and authorship* (~30k reverse-engineered lines of Craft internals delivered as MCP prompts the agent picks up automatically), not retrieval sophistication — but on the retrieval mechanism itself, Cortex is behind.
3. **Proprietary license — a posture, not a flaw.** Cortex is a **supported commercial Craft plugin**, the same model as Pixel & Tonic's first-party plugins and every paid Craft plugin vendor. The Free edition is free-of-charge; Pro is paid. This buys maintenance, support, and a coherent single-product roadmap — the opposite of Drupal's four-module fragmentation. It does mean higher adoption friction than an MIT package and no forking, which is a real trade-off for OSS-only shops. It is **not** an "openness liability," and Cortex is not MIT.
4. **Write-tier gating.** Every write tool (and HTTP transport) is behind paid Pro. Statamic/Kirby/Payload/Directus give writes away free. Cortex's deliberate choice is to keep the **free read surface PII-free and ungated**, and to put governed mutation + the untrusted HTTP boundary behind the paid, supported tier — consistent with the commercial-product posture.

**The hand-rolled dispatcher — a real trade-off, both sides.** Cortex implements its own MCP dispatcher (`src/mcp/Server.php`, ~1,400 lines) and both transports; its runtime dependencies are exactly `craftcms/cms`, `league/oauth2-server`, and the skills package — **no MCP SDK**. Drupal and Kirby build on the official PHP MCP SDK (maintained with the PHP Foundation and Symfony); Payload builds on the official TypeScript SDK plus `mcp-handler`. What the SDK route buys them is genuine and should not be minimised: when the MCP spec revs, they bump a dependency and inherit conformance work done upstream; Cortex pays an engineering sprint per revision and owns its own conformance testing forever. What hand-rolling buys Cortex is equally concrete: a dispatcher that is genuinely transport-agnostic (the official PHP SDK's server couples to its own transport bindings; Cortex's core receives transport context through narrow setters and serves stdio and HTTP unchanged), zero third-party code in the security-critical dispatch path, spec-version control (Cortex targets 2025-06-18 with `structuredContent` dual-emit today, ahead of several SDK consumers), and dispatcher behaviour that is directly covered by its own test suite rather than trusted upstream. Two mitigating facts keep this from being a structural liability: the protocol surface Cortex implements is small and frozen per spec revision (eight methods), and the official PHP SDK is itself **experimental pre-1.0** (v0.6.0 at the time of writing, "experimental until the first major release" per its own README) — so "free upstream maintenance" today also means inheriting an unstable API. Because the dispatcher is isolated behind one class with transport adapters on either side, migrating onto the SDK after it stabilises would be a contained refactor, and remains an explicit option.

**MCP client — assessed and deliberately deferred.** Drupal ships `mcp_client`, letting Drupal *consume* external MCP servers, and it is fair to ask whether Cortex should match it. The assessment (2026-06): no — not in Phase 3. Drupal's client only has a consumer because Drupal ships an in-CMS AI/agents framework that auto-exposes discovered MCP tools as function-call plugins; even there, adoption is nascent (1.0.0-alpha1, ~13 reported installs as of this writing). Craft has no in-CP agent host, so a Craft-side MCP client would have nothing to feed — the agents that talk to Cortex (Claude, Cursor, etc.) already connect to other MCP servers themselves. The one differentiated future shape is **governed egress**: Cortex brokering external MCP servers' tools through its own gating, redaction, and audit so an agency's clients get a single governed surface — but that is a large build (client transports, OAuth *client* flows, credential storage, per-tool permission mapping) on top of an experimental upstream client SDK, for a pattern with single-digit adoption in the ecosystem that pioneered it. Decision: **revisit if/when an in-CP assistant ships or the official PHP SDK client reaches 1.0; not before.** Until then "Drupal also ships an MCP client" stands in the rival-wins column above, with this context.

**Net.** For a single trusted developer who wants free, MIT, install-now tooling on a local box, craft-mcp/Kirby/Boost are reasonable. For a Statamic shop wanting a mature, free, full-CRUD operator console, Statamic MCP is excellent. For managed SaaS with semantic search and turnkey AI authoring, Sanity/Contentful lead. But for a **governed, audited, multi-user, remotely-reachable Craft content-operations server** — where the buyer is an agency handing controlled access to non-developer clients without handing over shell or DB access — Cortex's security/governance design has no peer in the Craft ecosystem and ties or leads the broader field. The work remaining is to ship it.

---

## 6. Sources

**Cortex (this repo)**
- `README.md`, `composer.json`, `docs/CAPABILITIES.md` (the `src/`-cited capability deep-dive this document digests), `docs/REVIEW.md`, `docs/review/ARCHITECTURE.md` (engineering/architecture review with `file:line` evidence and remediation status on `gate-9-hardening`).
- Bundled knowledge corpus: https://github.com/michtio/craftcms-claude-skills (counts verified against the packaged corpus: 10 skills, 82 references, 6 agents — 98 addressable documents, ~30,300 lines)

**MCP SDKs and clients (dispatcher trade-off + client assessment)**
- Official PHP MCP SDK: https://github.com/modelcontextprotocol/php-sdk (v0.6.0, experimental pre-1.0; PHP Foundation + Symfony)
- Drupal MCP Client: https://www.drupal.org/project/mcp_client (1.0.0-alpha1, ~13 reported installs; requires the AI + AI Agents modules)

**craft-mcp (direct competitor — source audit)**
- Repo: https://github.com/stimmtdigital/craft-mcp (tag v1.2.2, commit `c1f49ee`)
- `src/tools/TinkerTools.php`, `src/tools/DatabaseTools.php`, `src/tools/EntryTools.php`, `src/tools/GraphqlTools.php`, `src/tools/SystemTools.php`, `src/tools/DebugTools.php`, `src/Mcp.php`, `src/models/Settings.php`, `src/services/McpServerFactory.php`, `src/services/ToolRegistry.php`, `src/support/SafeExecution.php`, `src/support/ExceptionFormatterTrait.php`, `bin/mcp-server`, `docs/configuration.md`, `tests/ArchTest.php`, `composer.json`, `LICENSE`

**Drupal AI + MCP modules**
- https://www.drupal.org/project/ai · https://www.drupal.org/project/mcp · https://www.drupal.org/project/mcp_server · https://www.drupal.org/project/mcp_tools · https://www.drupal.org/project/mcp_client
- https://drupalmcp.io/ · https://codewheel.ai/blog/mcp-tools-drupal-ai-site-building/ · https://opensenselabs.com/blog/mcp-server · https://mcp-77a54f.pages.drupalcode.org/

**Sanity**
- https://www.sanity.io/docs/ai/mcp-server · https://www.sanity.io/blog/sanity-remote-mcp-server-is-generally-available · https://www.sanity.io/docs/agent-actions/introduction · https://www.sanity.io/docs/http-reference/agent-actions · https://www.sanity.io/docs/platform-management/how-ai-credits-work · https://github.com/sanity-io/sanity-mcp-server

**Contentful**
- https://www.contentful.com/developers/docs/tools/mcp-server/ · https://github.com/contentful/contentful-mcp-server · https://www.npmjs.com/package/@contentful/mcp-server · https://www.contentful.com/products/ai-actions/ · https://www.contentful.com/help/ai-automations/ai-actions/ · https://www.contentful.com/blog/model-context-protocol-introduction/

**Statamic MCP**
- https://github.com/cboxdk/statamic-mcp · https://github.com/cboxdk/statamic-mcp/blob/main/docs/getting-started/quickstart.md · https://github.com/cboxdk/statamic-mcp/blob/main/CHANGELOG.md · https://github.com/cboxdk/statamic-mcp/blob/main/CLAUDE.md · https://statamic.com/addons/cboxdk/statamic-mcp

**Kirby MCP**
- https://github.com/bnomei/kirby-mcp · https://raw.githubusercontent.com/bnomei/kirby-mcp/main/README.md · https://plugins.getkirby.com/bnomei/kirby-mcp · https://packagist.org/packages/bnomei/kirby-mcp · https://mcpservers.org/en/servers/bnomei/kirby-mcp

**Payload**
- https://payloadcms.com/docs/plugins/mcp · https://github.com/payloadcms/payload/blob/main/docs/plugins/mcp.mdx · https://github.com/payloadcms/payload/tree/main/packages/plugin-mcp · https://registry.npmjs.org/@payloadcms/plugin-mcp · https://payloadcms.com/enterprise/ai-framework · https://github.com/vercel/mcp-handler · https://www.npmjs.com/package/mcp-handler

**Directus**
- https://directus.com/docs/guides/ai/mcp · https://directus.com/docs/guides/ai/mcp/installation · https://directus.com/docs/guides/ai/mcp/tools · https://directus.com/docs/guides/ai/mcp/local-mcp · https://directus.io/docs/guides/ai/mcp/local-mcp/tools · https://directus.com/docs/guides/ai/mcp/prompts · https://github.com/directus/mcp · https://directus.io/bsl-faq · https://directus.io/blog/directus-v12-license-change · https://directus.io/pricing/self-hosted

**Laravel Boost**
- https://github.com/laravel/boost · https://laravel.com/docs/13.x/boost · https://laravel.com/ai/boost · https://raw.githubusercontent.com/laravel/boost/main/README.md · https://laravel.com/blog/laravel-ai-sdk-boost-or-mcp-which-tool-do-you-need

**Protocol**
- MCP specification: https://modelcontextprotocol.io/ (transport + auth surface, spec revision 2025-06-18)
