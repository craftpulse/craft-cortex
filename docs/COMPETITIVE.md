# Cortex in the AI/MCP CMS Landscape

Cortex (`craftpulse/craft-cortex`) is a Model Context Protocol (MCP) server packaged as a first-party Craft CMS 5 plugin. It is designed to install via Composer and run in-process inside the customer's own Craft application, exposing Craft internals — the content model, content itself, configuration, GraphQL schema, and dev/ops commands — to MCP-capable AI clients (Claude Desktop, Claude Code, Cursor, Continue.dev, Cline, Zed, Windsurf). The Free tier provides a stdio transport with 33+ thick, parameter-rich tools, 8 prompts, 77 resources, and ~27,000 lines of authored Craft expertise bundled as MCP prompts and resources. The Pro tier layers an authenticated Streamable HTTP transport with OAuth 2.1, bearer tokens, per-user permission filtering, a DB-backed audit log, rate limiting, write tools, and a Control Panel UI.

**Status (2026-05-30): nothing here is released.** Cortex has never been published to the Craft Plugin Store and has no public installs — the Free tier is feature-complete on `develop-v5`, the Pro tier is feature-complete on the Gate-7/8 lineage, and the CP UI (Gate 9) is mid-build on the `gate-9` branch. Every capability below describes *built, branch-resident, test-covered* functionality, not a generally-available product. Where this document says Cortex "wins" on a dimension, read it as "is designed to win once GA" — the competitive claims are only as real as the eventual release.

This document positions Cortex against the AI/MCP surfaces of the wider CMS field. Three things define Cortex's position: it is **MCP-native** (JSON-RPC 2.0, spec 2025-06-18, `outputSchema`/`structuredContent` dual-emit, MCP tool annotations) rather than a proprietary API bolted onto a REST layer; it is **self-hosted**, running inside the customer's own Craft install so content and tokens never transit a vendor cloud; and it is **Craft-specific**, reusing Craft's own user/permission/group model and element `canView`/`canSave`/`canDelete` authorization rather than a separate AI identity layer. Its security posture is built around an adversarial-LLM threat model: six `craft_exec` gates, no raw-SQL tool, no PII in the Free tier, secret redaction everywhere, and a no-shell-exec rule enforced by an architecture test. Its constraints are the mirror image of those choices: it is single-CMS, the HTTP/write/auth surface is not yet GA, and there is no managed SaaS option.

---

## Direct ancestor: Laravel Boost

Cortex's intellectual lineage runs straight through **Laravel Boost** — the official, first-party Laravel MCP server announced at Laracon US 2025 and shipped as a `--dev` Composer package. Boost is the closest thing to a blueprint that Cortex has, and the resemblance is structural, not superficial.

### What Cortex inherited

Boost's core thesis was: a **framework-native MCP server that runs inside the developer's own project**, exposes framework internals as a tool registry, and ships bundled "skills"/guidelines plus a documentation-search tool so agents "behave like an experienced developer instead of a search engine." Cortex took this wholesale:

- **The tool registry pattern.** Boost exposes 15+ read-oriented MCP tools (Application Info, Search Docs, Tinker, Database Query/Schema, List Routes, List Artisan Commands, Last Error, Read Log Entries). Cortex's architecture — every tool a class implementing a shared interface, registered through a service, no giant switch — is the same idea scaled to ~40 tools.
- **Bundled knowledge as first-class context.** Boost's two surfaces — composable, version-specific **AI Guidelines** written upfront to `CLAUDE.md`/`AGENTS.md`, and on-demand **Agent Skills** (`SKILL.md` modules) — are the direct conceptual parents of Cortex's bundled `michtio/craftcms-claude-skills` corpus served as MCP prompts and resources.
- **The docs-search tool.** Boost's `Search Docs` (backed by a hosted 17,000-chunk semantic documentation API) is the ancestor of Cortex's `search_skills`.
- **Even the install command.** Boost's interactive `boost:install` (which auto-detects IDEs/agents) is mirrored by Cortex's `cortex/install`.

### What Cortex adapted for Craft

Boost is a **pure local-developer-productivity tool** and is explicit about its boundaries: stdio-only, single-developer, **no auth, no permission model, no audit log**, because the transport (stdio) *is* the trust boundary. Its `Tinker` tool runs arbitrary PHP and `Database Query` runs arbitrary SQL — both "safe" only because they assume a trusted local machine. Boost's docs tell users to gitignore generated config and treat all generated code as a reviewable draft.

Cortex inherited the stdio/local-trust model for its **Free tier** verbatim — Free stdio has no per-request authorization, full registry visible to the local user, exactly Boost's posture. But Cortex was built for the **production/team boundary that Boost declines to address**. Where Boost stops, Cortex's Pro tier begins:

- A **Streamable HTTP transport** with OAuth 2.1 (DCR, PKCE S256, audience binding) and bearer tokens bound to individual Craft users.
- The **native Craft permission model** plus a per-tool/per-mode gating contract (`shouldRegister`/`filterFor`/`inputSchemaFor`).
- **SSE streaming** with progress notifications and cooperative cancellation.
- A **DB-backed audit log** (`cortex_invocations`).
- A deliberate **read-in-Free / write-in-Pro** split, where Boost has no read/write distinction at all.

Cortex also notably did **not** inherit Boost's most powerful (and most dangerous) capability: arbitrary-code-execution as a first-class agent tool. Boost's `Tinker` has no analog in Cortex's Free tier; Cortex's nearest equivalent, `craft_exec`, sits behind six layered gates and is stdio-only by hard rule.

### What it gained and lost by being Craft-specific

**Gained:** content-CMS-shaped tools that Boost has no reason to ship (entries, sections, fields, assets, globals, drafts/revisions, content audit, import/export); an editions business model (Free/Pro/Commerce via the Craft Plugin Store); and a governance story — token binding, per-user ACL, audit, cancellation — that turns the MCP-server idea into something safely exposable to remote agents and multiple users.

**Lost:** Boost's breadth of ecosystem coverage (composable guidelines for Livewire, Inertia, Flux, Pest, Tailwind, Folio, and more, *contributable by any third-party package*); the **hosted semantic-embeddings documentation API**, which Cortex has no analog for (its `search_skills` is keyword search over hand-authored internals, not vectorised retrieval — vectorised docs search is only roadmapped for Phase 3); Boost's richer authoring/override story (path- and name-based guideline/skill overriding, multi-agent install across six IDEs); and Boost's universal **free + MIT + first-party-official** positioning. Cortex's Free tier is MIT, but the package ships as `proprietary` and the Pro tier is license-gated.

In one line: **Boost is the local dev-experience ancestor; Cortex is the security-, multi-user-, and commercialization-hardened descendant aimed at content operations rather than pure local code generation.**

Sources: [^boost1] [^boost2] [^boost3]

---

## Headless SaaS

This group is the commercial center of gravity for AI-in-CMS. All five are hosted platforms; their MCP surfaces are mostly remote, write-capable, and metered by usage rather than gated by edition. The recurring contrast with Cortex is **managed-convenience-and-breadth versus self-hosted-control-and-governance**.

### Sanity (AI Assist + Agent Actions)

Sanity has the broadest AI footprint of any competitor: three distinct products — **AI Assist** (in-Studio editor AI), **Agent Actions** (a schema-aware Generate/Transform/Translate/Prompt API), and a genuine **remote MCP server** at `mcp.sanity.io` exposing 25–60+ tools. AI usage is metered in AI Credits ($0.05 each) on every plan.

| Dimension | Sanity |
|---|---|
| Protocol | Real MCP (remote, Streamable HTTP) + proprietary Agent Actions SDK/REST; deprecated local stdio server |
| Auth | OAuth (default, ~7-day sessions) or bearer API tokens inheriting user role |
| Hosting | SaaS/hosted; self-hostable local server deprecated and archived |
| Free vs Paid | AI on all plans but metered via credits, not edition-gated; 100 free credits/mo (500 Enterprise) |
| Read/Write | Write-capable: query + create/patch/publish, releases, schema/Studio deploy, CORS, dataset CRUD |
| Permission model | Native Sanity role + token scope; `groqFilter` security boundary + perspective; no per-tool ACL beyond scope |
| Streaming | Partial — Streamable HTTP; "live AI presence" in Studio; no documented per-tool progress/cancellation |
| Audit logging | None AI-specific; mutations flow through normal content history/revisions |
| Knowledge/Skills | Schema-aware introspection + custom field actions/instruction configs; no reusable skill packs |
| Open source | Mixed — AI Assist MIT, deprecated local server MIT/archived; remote server + Content Lake proprietary |
| Pricing | Free/Growth (~$15/seat)/Enterprise + pay-per-credit ($0.05) |
| Marketplace | MCP added by URL to any client; AI Assist via npm; no first-party AI plugin store |

**vs Cortex:** Both expose real MCP with OAuth/bearer auth and a native-user permission model, but sit at opposite ends of hosting and licensing. Sanity wins on breadth and polish — a fully hosted, auto-updated server, mature agentic content tooling, and in-Studio AI that no Craft plugin ships. Cortex wins on self-hosting (content/tokens never leave the customer's infra), a first-class invocation audit log (`cortex_invocations`) where Sanity has none, per-tool/per-mode ACLs versus token-scope + `groqFilter`, and a deterministic edition model versus cost-scales-with-usage credits.

Sources: [^sanity1] [^sanity2] [^sanity3] [^sanity4] [^sanity5] [^sanity6] [^sanity7] [^sanity8]

### Contentful (AI Actions / AI Studio)

Contentful ships arguably the most complete vendor MCP among the headless majors: an MIT open-source **local server** (`@contentful/mcp-server`, stdio, PAT auth) *and* a remote hosted Beta server with OAuth, exposing 40+ tools across 8 categories provisioned per-environment via a Marketplace app. Separately, **AI Actions** is a polished editor-embedded generative layer (templates, variables, External References, LLM selection, playground).

| Dimension | Contentful |
|---|---|
| Protocol | MCP over CMA; local stdio (npx) + remote HTTP at mcp.contentful.com |
| Auth | Local: Management PAT + Space ID; remote: browser OAuth consent |
| Hosting | Hybrid — local OSS server or remote SaaS Beta; CMS itself SaaS-only |
| Free vs Paid | MCP free/OSS; AI Actions a paid module on specific plans (annual consumption units) |
| Read/Write | Write-capable; tool categories settable read-only or read/write per environment |
| Permission model | Native users + per-environment category read/write scoping; no per-tool ACL finer than category |
| Streaming | Not documented |
| Audit logging | Not an MCP feature; usage tracking + enterprise audit logs only |
| Knowledge/Skills | AI Actions templates (≤20/space, whole-field only); MCP has no skill concept |
| Open source | Local server MIT; platform + AI Actions proprietary |
| Pricing | MCP free; AI Actions paid add-on; CMS Free/Lite-Premium/Enterprise |
| Marketplace | Remote MCP via Marketplace app per env; npm + one-click installers; App Framework |

**vs Cortex:** Contentful is the more mature vendor MCP today — remote OAuth server + MIT local server, per-environment scoping, 40+ write tools. Its AI Actions module is an in-editor content-generation product Cortex doesn't attempt. Cortex wins on transport rigor (true dual transport with SSE streaming, progress, and cancellation — none documented for Contentful), tighter native auth (OAuth 2.1 + bearer bound to individual users, full Craft permissions, per-tool/per-mode ACL versus coarse category scoping), and a documented audit trail versus usage-only tracking.

Sources: [^cf1] [^cf2] [^cf3] [^cf4] [^cf5]

### Storyblok

Storyblok's official MCP server (Labs, "Innovation phase") is **meta-tool-based**: instead of one-tool-per-operation, it ships ~7 tools (`search`, `describe`, `execute_readonly`, `execute_mutating`, `execute_destructive`, `upload_asset`, `upload_asset_finish`) proxying the entire Management API behind three execution tiers. The self-hostable repo was archived March 2026 in favor of a hosted, stateless remote endpoint. A separate in-app AI layer (Translations, Alt Text, SEO, Branding, Ideation Room) is credit-metered with customer-selectable model providers.

| Dimension | Storyblok |
|---|---|
| Protocol | MCP over HTTP (Streamable) at /mcp; underlying Management API REST; ~7 meta-tools |
| Auth | Bearer Personal Access Token in Authorization header; no OAuth 2.1 |
| Hosting | SaaS/hosted, stateless; self-hostable repo archived March 2026 |
| Free vs Paid | MCP not publicly priced (Labs); in-app AI credit-metered by plan |
| Read/Write | Write-capable via three tiers (readonly/mutating/destructive); destructive needs confirmation |
| Permission model | Optional coarse `?role=` preset (none/content_reviewer/content_editor/developer); not full RBAC, not per-tool |
| Streaming | Not documented (stateless HTTP) |
| Audit logging | Not documented for MCP; in-app credit/usage tracking only |
| Knowledge/Skills | No MCP skills; in-app AI Branding + Ideation Room only |
| Open source | Original server OSS but archived/unmaintained; replaced by closed hosted endpoint |
| Pricing | MCP not publicly priced; platform tiered SaaS; AI consumes plan credits |
| Marketplace | No first-party MCP marketplace; hosted Labs endpoint + (archived) GitHub |

**vs Cortex:** Cortex wins on identity (OAuth 2.1 + bearer bound to specific users + per-tool/per-mode ACL versus a single PAT and a fixed four-option `role=` preset), auditability, streaming, bundled skills + a custom Skill element type, and a self-hosted/data-residency story versus a vendor endpoint that has already been deprecated once. Cortex's ~40 discrete typed tools give an LLM a clearer catalog than ~7 meta-tools. Storyblok wins on reach (mature multi-tenant SaaS, full-API access with near-zero setup) and decisively on the broader in-app AI authoring suite Cortex doesn't target.

Sources: [^sb1] [^sb2] [^sb3] [^sb4] [^sb5] [^sb6] [^sb7] [^sb8]

### DatoCMS

DatoCMS takes a distinctive architectural bet: rather than exposing 150+ endpoints as tools, its hosted MCP server (`mcp.datocms.com`) has the AI **author TypeScript programs** that are type-checked and run in a sandbox, layered discovery → safe read → unsafe write. The agent's scripts never see the real API token, network egress is restricted to DatoCMS APIs, and usage is metered by weekly execution-time budget.

| Dimension | DatoCMS |
|---|---|
| Protocol | MCP over Streamable HTTP, remote at mcp.datocms.com; local stdio package deprecated |
| Auth | Native MCP OAuth 2.x via oauth.datocms.com; project-scoped at auth time; scripts never see token |
| Hosting | SaaS/fully hosted, zero local setup |
| Free vs Paid | Metered freemium: Free 20s timeout / ~10 min weekly; Paid 60s / ~90 min; 32 KB output cap |
| Read/Write | Write-capable: safe script (read-only client) vs unsafe script (full CRUD, requires confirmation) |
| Permission model | Native roles via OAuth + project scoping; no per-tool ACL beyond safe/unsafe split |
| Streaming | None — programs return a single ≤32 KB result; per-script timeouts only |
| Audit logging | No MCP audit log; platform audit logs Enterprise-only; security via sandboxing |
| Knowledge/Skills | Docs-aware discovery tools ground the agent; no custom skill surface |
| Open source | Hosted server proprietary; deprecated local package public, no declared license |
| Pricing | Metered against plan; Free / Professional ~€149–199/mo / Enterprise |
| Marketplace | Plugins SDK marketplace extends editing UI, not MCP; server is first-party hosted |

**vs Cortex:** DatoCMS wins on hosting friction (zero-install, native OAuth, sandboxed untrusted agent code) and its TypeScript-script model is more expressive for batched multi-step mutations than discrete tool calls. Cortex wins on Craft-native per-tool/per-mode ACL (versus a binary safe/unsafe split + OAuth project scope), a genuine per-invocation audit log versus Enterprise-only platform logs, SSE streaming with progress/cancellation, and a user-authorable Skill element type versus docs-only grounding.

Sources: [^dato1] [^dato2] [^dato3] [^dato4] [^dato5]

### Prismic

Prismic's AI surface is the most **developer-authoring-oriented** of the SaaS group and the most in flux. Its developer MCP (`@prismicio/mcp-server`, Apache-2.0) for generating slice components was **deprecated 2026-05-14** and replaced by the Prismic CLI plus an agent skill (`prismicio/skills`). A separate read-oriented **content MCP** connects agents to a repository for search/query/retrieve — though Prismic's own docs are thin here and detail surfaces mainly via third-party aggregators.

| Dimension | Prismic |
|---|---|
| Protocol | MCP — deprecated dev server (replaced by CLI + skill) + read-oriented content MCP; REST Content/Repository APIs underneath |
| Auth | CLI session creds; content MCP OAuth + repository access tokens; no per-user bearer model documented |
| Hosting | SaaS; content MCP remote/server-side; deprecated dev MCP ran local stdio |
| Free vs Paid | Free plan exists, "always free for developers"; AI surface not documented as paid-gated |
| Read/Write | Content MCP read-oriented (search/query/fetch); CLI/skill write code/config, not published content via MCP |
| Permission model | No dedicated AI/MCP permission model; inherits account creds/OAuth scopes; standard CMS roles |
| Streaming | Not documented |
| Audit logging | Unknown / not documented |
| Knowledge/Skills | Agent skill (`prismicio/skills`) via `npx skills add`; no custom skill element |
| Open source | Deprecated dev MCP Apache-2.0 (archived); skills repo public; platform + content MCP proprietary |
| Pricing | Per-repository: Free $0 / Starter $10 / Small $25 / Medium $150 / Platinum $675 / Enterprise |
| Marketplace | No first-party AI/MCP marketplace; npm + npx skill; third-party aggregators wrap access |

**vs Cortex:** Cortex and Prismic occupy nearly opposite ends of the MCP space. Cortex wins decisively on self-hosting, security (OAuth 2.1 + user-bound bearer tokens, per-tool/per-mode ACL, audit log, SSE streaming), and breadth of administrative control with write capability in Pro — none of which Prismic's read-oriented content MCP documents. Prismic wins on reach and a polished slice-authoring agent workflow for Next.js/Nuxt/SvelteKit that Cortex doesn't target, plus zero-ops hosting and a genuinely free developer entry point. One caveat: several Prismic dimensions rest partly on third-party aggregator descriptions.

Sources: [^prismic1] [^prismic2] [^prismic3] [^prismic4] [^prismic5] [^prismic6] [^prismic7]

---

## Self-hosted / OSS

This is Cortex's true peer group — servers that run inside the customer's own install. The field has visibly **converged on the same architecture** (OAuth/bearer, native-user permissions, write capability, audit, CP dashboard), with **Statamic's `cboxdk/statamic-mcp` the most striking mirror of Cortex**. The differentiators here are licensing openness, tool-catalog breadth, and depth/correctness of per-user authorization, streaming, and audit.

### Drupal (AI module ecosystem)

Not a product but a **sprawling GPL-2.0 contrib ecosystem**: the flagship `ai` module abstracts ~48 LLM/embedding providers, with an AI Agents framework and *two* MCP server modules (`mcp` and the SDK-based `mcp_server`, now merging), an `mcp_client` (Drupal can also *consume* external MCP servers), and `mcp_tools` (222 tools across 34 submodules). Maturity varies sharply — `mcp_server` reports ~2 sites; `mcp_tools` is outside Drupal's security advisory policy.

| Dimension | Drupal |
|---|---|
| Protocol | Official MCP; `mcp`/`mcp_server` (PHP MCP SDK), HTTP at /_mcp; Tool API plugins; also JSON:API/GraphQL |
| Auth | mcp_server: OAuth 2.1 (Simple OAuth) per-tool Required/Disabled; mcp_client/tools: API keys; STDIO via Drush |
| Hosting | Self-hosted (Composer); no vendor SaaS |
| Free vs Paid | Fully free/OSS (GPL-2.0); cost is LLM API + hosting |
| Read/Write | Write-capable; mcp_tools presets Development/Staging/Production; connection-scoped read/write/admin |
| Permission model | Mixed — OAuth scopes + mode (mcp_server) and connection-scope presets (mcp_tools); per-call native-permission re-check not clearly guaranteed |
| Streaming | Unknown / not documented (SDK supports it; modules don't document exposing it) |
| Audit logging | Partial — AI Logging submodule; mcp_tools "audit trails"; no single canonical invocation table |
| Knowledge/Skills | No skill entity; RAG/semantic search, Automators, Assistants API, prompts |
| Open source | Yes — GPL-2.0 (mcp_tools outside security policy) |
| Pricing | Free; optional agency/Acquia support |
| Marketplace | drupal.org contrib + Composer; no paid marketplace |

**vs Cortex:** Cortex wins on a single coherent security model — OAuth 2.1 + user-bound tokens with native Craft permissions and per-tool/per-mode ACL re-checked on every call (defense in depth) — versus Drupal's split between OAuth scopes, connection-scope presets, and an underlying-permission story the docs don't clearly guarantee per call. Cortex documents SSE streaming and a dedicated audit table; the Drupal MCP modules document neither clearly. Drupal wins on openness and breadth: genuinely GPL-2.0, ~48 swappable providers, an MCP *client*, and a far larger (if younger and fragmented) tool catalog.

Sources: [^drupal1] [^drupal2] [^drupal3] [^drupal4] [^drupal5] [^drupal6] [^drupal7]

### Kirby MCP (bnomei/kirby-mcp)

The **closest direct analog to Cortex in the whole field**: a community MIT plugin (`bnomei/kirby-mcp`, v1.7.0) for the flat-file, Composer-distributed Kirby CMS. It ships 37 tools, 15 read-only resources + 15 dynamic templates, a ~216 KB bundled knowledge base, and 15 bundled skills copied locally to the agent — the same "MCP server + bundled knowledge + skills" shape as Cortex.

| Dimension | Kirby MCP |
|---|---|
| Protocol | MCP; stdio (default) + optional HTTP/Streamable (off by default) |
| Auth | Stdio: none; HTTP: shared-token (loopback), remote-token (SHA256), or OAuth/OIDC with JWT |
| Hosting | Self-hosted only (Composer); no SaaS |
| Free vs Paid | Free, MIT, no paid tier (Kirby core itself is commercial) |
| Read/Write | Write-capable; writes need allowWrite + per-call confirm; `kirby_eval` off by default; CLI allow/deny patterns |
| Permission model | Separate global scope system (read/runtime/write/execute/admin), NOT bound to individual Panel users |
| Streaming | Not documented |
| Audit logging | No central log; `mcp_dump()` JSONL helper with secret redaction (not a true audit trail) |
| Knowledge/Skills | Strong — ~216 KB KB + online fallback; 15 bundled skills; searches Kirby plugin directory |
| Open source | Yes — MIT, community-maintained |
| Pricing | Free (MIT) |
| Marketplace | Official Kirby plugin directory + GitHub + MCP Registry |

**vs Cortex:** Kirby MCP wins on openness (fully MIT, free, no edition gating), a broader developer/runtime inspection surface (37 tools, `kirby_eval`, CLI runner, IDE-helper generation), and more bundled skills (15) plus dynamic resource templates. Cortex wins decisively on the **permission model** — it binds tokens to actual Craft users and enforces native Craft permissions with per-tool/per-mode ACL, where kirby-mcp uses a *global scope system not tied to individual Panel users* and so cannot express "this agent acts as this editor with exactly their permissions." Cortex also has real SSE streaming with progress/cancellation and a genuine audit log versus a redacted JSONL debug-dump helper.

Sources: [^kirby1] [^kirby2] [^kirby3] [^kirby4]

### Statamic (cboxdk/statamic-mcp)

The architectural twin. Statamic core ships no MCP server; the flagship surface is the third-party MIT addon **`cboxdk/statamic-mcp`** (Statamic 6.6+) — 140+ tools across 11 domain routers over Streamable HTTP, with **OAuth 2.1 + scoped bearer tokens, a Vue 3 CP dashboard, and audit logging**. Statamic and Cortex have clearly converged on the same design.

| Dimension | Statamic (cboxdk addon) |
|---|---|
| Protocol | MCP (Laravel MCP package); standard endpoint; 140+ tools / 11 routers |
| Auth | OAuth 2.1 (PKCE + DCR) + scoped bearer (21 permissions) + HTTP Basic alternative |
| Hosting | Self-hosted (Composer); web endpoint at /mcp/statamic by default; no SaaS |
| Free vs Paid | MCP addon free/OSS (MIT); separate AI Assistant writing addon paid ($15/site) |
| Read/Write | Write-capable; full CRUD + bulk ops, merge strategies, asset + blueprint mutations |
| Permission model | Native Statamic RBAC (roles/groups) + addon scoped tokens (21 granular scopes) |
| Streaming | Streamable HTTP + stdio bridge; SSE progress/cancellation NOT documented |
| Audit logging | Yes — audit log of tool calls in the Vue 3 CP dashboard |
| Knowledge/Skills | Two discovery tools (intent discover + schema) + TS/PHP type generation; no skill packs |
| Open source | Addon MIT; Statamic core source-available/commercial; AI Assistant proprietary |
| Pricing | Addon free; Statamic licensed separately; AI Assistant $15/site one-time |
| Marketplace | Official Statamic Marketplace + Composer; `php artisan mcp:statamic:install` |

**vs Cortex:** The two have converged on auth (OAuth 2.1 + bearer), native-user permissions, write capability, audit logging, and a CP dashboard. Statamic's addon wins on openness (MIT, free, no edition gating), ~140 tools versus ~40, the larger Laravel ecosystem, more documented clients, and TS/PHP type generation. Cortex wins on being a **first-class single-vendor Craft product** (Plugin Store, Free/Pro/Commerce, registration-time gating) versus a third-party community addon, true SSE streaming with progress/cancellation (Statamic documents neither), bundled skills + a custom Skill element type versus runtime discovery tools only, and a more deliberate security posture (stdio-only `craft_exec`, six exec gates, PII excluded from Free).

Sources: [^statamic1] [^statamic2] [^statamic3] [^statamic4] [^statamic5]

### Payload CMS

A fully MIT-licensed, code-first TypeScript/Node CMS (Figma-acquired 2025) with a first-party open-source MCP plugin (`@payloadcms/plugin-mcp`) exposing collections/globals as MCP tools over `/api/mcp`. It enforces Payload's own access-control rules and hooks via the authenticated user (`req.payloadAPI === 'MCP'`), and supports custom tools/prompts/resources beyond CRUD. A separate Enterprise AI framework (RAG/auto-embedding) and a community writing/image plugin are distinct surfaces.

| Dimension | Payload |
|---|---|
| Protocol | Official MCP (`@payloadcms/plugin-mcp`), Streamable HTTP at /api/mcp; custom tools/prompts/resources; also REST/GraphQL/local API |
| Auth | Bearer API keys bound to a Payload user (no OAuth); `overrideAuth` for custom strategy |
| Hosting | Self-hosted only (Payload Cloud discontinued for new projects) |
| Free vs Paid | MCP plugin free/MIT; enterprise RAG/AI framework Enterprise-gated; writing assistant a free community plugin |
| Read/Write | Write-capable; find + create/update/delete per collection (globals find+update); per-key/per-collection toggles |
| Permission model | Native Payload users + per-tool ACL: config-enabled ops + per-API-key find/create/update/delete toggles; access rules/hooks at data layer |
| Streaming | Partial/implied — SSE-capable HTTP transport, `maxDuration`; progress/cancellation not documented |
| Audit logging | `onEvent` hook (wire your own sink) + verboseLogs; no first-party persistent audit table |
| Knowledge/Skills | Custom prompts + resources; enterprise RAG/auto-embedding; no skill element |
| Open source | Yes — core + MCP plugin MIT |
| Pricing | Core + MCP free MIT; Enterprise per-seat sales-led; community AI plugin BYO keys |
| Marketplace | npm/pnpm packages + community directory; no curated/reviewed store |

**vs Cortex:** Both are self-hosted, MCP-native, write-capable, and lean on the host CMS's own user/permission model with bearer tokens bound to a user. Cortex diverges on the security boundary (dual stdio/HTTP transport with OAuth 2.1 on HTTP versus Payload's HTTP-only API-key bearer, no OAuth, no stdio) and wins on governance — a persistent audit log with secret redaction versus an `onEvent` callback you must wire yourself, documented SSE progress/cancellation, and a richer curated surface (~40 tools spanning schema/dev/diagnostics/content + per-mode ACL + bundled skills + Skill element). Payload wins on ecosystem reach and AI breadth: truly free MIT with no edition wall on MCP, a much larger Figma-backed project, an enterprise RAG/embedding framework, and a mature community writing/image/translation assistant — plus arbitrary hand-authored TS MCP surfaces.

Sources: [^payload1] [^payload2] [^payload3] [^payload4] [^payload5] [^payload6]

### Directus

A BSL-1.1 source-available data platform with a **fully built-in MCP server** (no add-on, in every edition since v11.12, on both self-hosted and Cloud) served at `/mcp`, plus an in-Studio AI Assistant and LLM-calling Flows. ~24 tools span content CRUD, schema management, asset import, flow triggering, and comments, and the AI acts directly as the authenticated Directus user.

| Dimension | Directus |
|---|---|
| Protocol | Native MCP over HTTP at /mcp (v11.12+); REST + GraphQL underneath; legacy local Node stdio server |
| Auth | OAuth (CIMD or Dynamic Client Registration) recommended; static bearer token fallback |
| Hosting | Self-hosted (Docker/Node) OR Directus Cloud; identical MCP on both |
| Free vs Paid | MCP/AI/Flows free in every edition; self-hosting free unless entity >$5M/yr; Cloud paid |
| Read/Write | Write-capable; ~24 tools incl. create/update/delete item, create/update field, import-file, trigger-flow, upsert-comment |
| Permission model | Native Directus permissions — AI acts as the authenticated user; coarse install-wide `DISABLE_TOOLS`; no per-request per-user tool ACL |
| Streaming | Not documented |
| Audit logging | Yes — built-in activity/revisions log with full attribution; no MCP-specific table |
| Knowledge/Skills | Customizable system prompt + a Prompts collection of reusable templates; no skill element |
| Open source | Source-available BSL 1.1 → GPLv3 after 3 years (not OSI-open) |
| Pricing | Self-hosted free (Enterprise if >$5M/yr); Cloud Starter $15 / Pro $99 / Enterprise |
| Marketplace | Marketplace for Studio extensions; MCP is core, not a listing; prompts stored as data |

**vs Cortex:** Both are self-hosted, MCP-native, and lean on the host's permission model with the AI acting as a real user. Cortex wins on a genuine streaming story (SSE + progress + cancellation, undocumented for Directus), a dedicated AI-call audit log versus reusing the generic activity log, finer per-tool *and* per-mode ACL evaluated per-request per-user versus a blanket install-wide `DISABLE_TOOLS` toggle, a custom Skill element type versus stored-prompts-in-a-collection, and stdio as a trusted local-dev transport. Directus wins on licensing breadth (BSL → eventual GPL), fully free bundled MCP with zero edition gating, more standards-forward OAuth (CIMD/DCR), and one codebase covering both Cloud and self-hosted with a mature Marketplace.

Sources: [^directus1] [^directus2] [^directus3] [^directus4] [^directus5] [^directus6] [^directus7]

---

## Other

### Ghost

**Ghost has no first-party AI or MCP surface.** It is an MIT-licensed publishing platform exposing two REST APIs — a read-only Content API (API-key query param) and a full-CRUD Admin API (short-lived JWT from an integration or staff key). Its public stance is that creators should humanize AI-generated content, not that Ghost provides AI tooling. The only MCP surface comes from a handful of **unofficial third-party community servers** that wrap those REST APIs; none are built, endorsed, or distributed by the Ghost Foundation. There is therefore no native AI/MCP surface to compare against Cortex — only the underlying REST APIs a third party can adapt.

A community MCP wrapper simply inherits whatever a static API key or staff JWT can do: no per-tool gating, no streaming, no audit trail, no skills concept, no AI-bound token model. On the actual axis of comparison — a governed, permission-aware, auditable AI/MCP surface — Cortex is in a different and far more mature category. Where Ghost is stronger is as a *base platform*: it is genuinely MIT open-source (Cortex's package ships proprietary), it is a mature standalone publishing/membership product with both free self-hosting and polished managed SaaS, and its simple API-key model is lower-friction to bolt onto for a quick read-only script.

Sources: [^ghost1] [^ghost2] [^ghost3] [^ghost4] [^ghost5] [^ghost6] [^ghost7] [^ghost8]

---

## Cross-cutting matrix

| Product | Protocol | Auth | Hosting | Free vs Paid | Read/Write | Permission model | Streaming | Audit log | Knowledge/Skills | Open source |
|---|---|---|---|---|---|---|---|---|---|---|
| **Cortex** | Native MCP; stdio (Free) + Streamable HTTP (Pro) | stdio none; HTTP OAuth 2.1 + user-bound bearer | Self-hosted Craft plugin | Free (read) / Pro (write+HTTP) editions | Read (Free) + write (Pro) w/ dry-run + confirm | Native Craft perms + per-tool + per-mode ACL, per-request | SSE progress + cooperative cancellation | DB `cortex_invocations`, redacted | ~27k-line bundled corpus + Skill element (Pro) | Free MIT; Pro proprietary |
| Laravel Boost | MCP, stdio | None (local trust) | Self-hosted dev tool | Entirely free | Read + Tinker/DB-query (effectively write) | None | Not documented | None | Guidelines + Agent Skills + hosted 17k-chunk docs API | MIT |
| Sanity | Real MCP (remote) + Agent Actions SDK | OAuth / bearer (role-inheriting) | SaaS | Metered AI credits | Write-capable | Role + token scope + groqFilter | Partial | None (content history) | Schema-aware + field actions | Mixed |
| Contentful | MCP, local + remote | PAT / OAuth | Hybrid (CMS SaaS) | MCP free; AI Actions paid | Write (per-env category r/w) | Native + category scoping | Not documented | Usage tracking only | AI Actions (≤20/space) | Local MIT; rest proprietary |
| Storyblok | MCP, hosted, ~7 meta-tools | Bearer PAT | SaaS (self-host archived) | MCP unpriced; AI credit-metered | Write (3 tiers) | Coarse `role=` preset | Not documented | Not documented (MCP) | In-app AI only | Archived/closed |
| DatoCMS | MCP, hosted, TS-sandbox | OAuth 2.x, project-scoped | SaaS | Metered weekly budget | Write (safe/unsafe script) | Roles + project scope | None | None (Enterprise platform) | Docs-aware discovery | Hosted proprietary |
| Prismic | MCP (dev deprecated) + content MCP | Session / OAuth / repo tokens | SaaS | Free dev; AI not gated | Content read; code write | Inherited creds; no AI ACL | Not documented | Unknown | Agent skill (npx) | Dev MCP Apache-2.0; rest proprietary |
| Drupal | Official MCP, 2 modules + client | OAuth 2.1 / API keys | Self-hosted | Free (GPL-2.0) | Write (presets/scopes) | OAuth scopes + connection presets; per-call re-check unclear | Unknown | Partial (AI Logging) | RAG + automators + prompts | GPL-2.0 |
| Kirby MCP | MCP, stdio + optional HTTP | stdio none; HTTP token/OAuth | Self-hosted | Free MIT | Write (confirm gates) | Global scopes, NOT per-user | Not documented | JSONL dump (not audit) | ~216 KB KB + 15 skills | MIT |
| Statamic (cboxdk) | MCP, 140+ tools | OAuth 2.1 + scoped bearer | Self-hosted | Free MIT (3rd-party) | Write (full CRUD) | Native RBAC + 21 token scopes | Not documented | Yes (CP dashboard) | Discovery tools only | MIT (addon) |
| Payload | Official MCP, HTTP | Bearer API keys (user-bound) | Self-hosted | Free MIT (RAG Enterprise) | Write (per-key toggles) | Native + per-tool ACL | Partial (implied) | onEvent hook (DIY) | Custom prompts/resources + RAG | MIT |
| Directus | Native MCP (built-in) | OAuth CIMD/DCR + bearer | Self-hosted or Cloud | Free in every edition | Write-capable | Native; install-wide DISABLE_TOOLS | Not documented | Yes (activity/revisions) | System prompt + Prompts collection | BSL 1.1 → GPLv3 |
| Ghost | No official MCP (REST only) | API key / staff JWT | Self-hosted or SaaS | Free self-host; paid SaaS | Admin API write | Token/role scope; no AI ACL | None | None | None | MIT |

---

## Cortex's positioning

### Where Cortex wins

- **Per-user, per-tool, per-mode authorization.** The `shouldRegister`/`filterFor`/`inputSchemaFor` contract, re-checked on every call against native Craft permissions, is finer-grained than anything in the field. Storyblok offers a four-option preset; Directus and Kirby gate install-wide; Drupal's per-call re-check is unclear; Sanity/Dato gate by token scope. Only Statamic and Payload come close, and neither does per-mode enum filtering.
- **Streaming.** True SSE with progress notifications *and* cooperative cancellation. Almost no competitor documents this — most are request/response HTTP. This is a clear, defensible edge.
- **Purpose-built AI audit log.** `cortex_invocations` with a locked field set and secret redaction is a first-class invocation trail. Competitors either have none (Sanity, Storyblok, Dato, Ghost), reuse a generic activity log (Directus), or hand you a DIY hook (Payload).
- **Bundled expertise corpus.** ~27,000 lines of authored Craft internals as MCP prompts/resources is a knowledge surface most competitors lack entirely (Boost's hosted embeddings API is the only richer analog).
- **Security posture.** Adversarial-LLM threat model: six `craft_exec` gates, no raw-SQL tool, no PII in Free, no-shell-exec enforced by an architecture test, transport-as-trust-boundary. More deliberate than any peer.
- **Self-hosted + data residency.** Content and tokens never leave the customer's infra — a hard differentiator against all five SaaS entries.

### Where each competitor wins

- **Laravel Boost:** official/first-party, free MIT, richer authoring/override story, and a hosted 17k-chunk semantic docs API Cortex has no analog for.
- **Sanity:** the broadest, most polished managed agentic surface (in-Studio AI Assist + Agent Actions + remote MCP).
- **Contentful:** most mature vendor MCP (remote OAuth + MIT local server, 40+ tools) plus the AI Actions editor layer.
- **Storyblok:** turnkey hosted full-API agent access + a broad in-app AI authoring suite.
- **DatoCMS:** zero-install hosting and a more expressive sandboxed TypeScript-script execution model.
- **Prismic:** a polished slice-code-authoring agent workflow for Next.js/Nuxt/SvelteKit.
- **Drupal:** genuinely GPL open, ~48 swappable providers, an MCP *client*, and a huge (if fragmented) tool catalog.
- **Kirby MCP:** fully MIT, broader runtime-inspection tools, more bundled skills (15) + dynamic resource templates.
- **Statamic (cboxdk):** MIT, ~140 tools, larger Laravel ecosystem, TS/PHP type generation.
- **Payload:** truly free MIT with no edition wall, Figma-backed momentum, plus an enterprise RAG framework and community writing/image assistant.
- **Directus:** BSL→GPL, free bundled MCP in every edition, standards-forward OAuth (CIMD/DCR), and one codebase for Cloud + self-hosted.
- **Ghost:** genuinely MIT, mature publishing/membership product, low-friction API-key model.

### Prioritized gaps to close before Plugin Store submission

1. **Ship the Pro HTTP/auth/write surface to GA.** It is the entire basis for most of Cortex's claimed wins (OAuth 2.1, per-user ACL, audit log, streaming, writes). Until it is GA, the differentiators are roadmap, not product. **Highest priority.**
2. **Close the licensing-openness perception gap.** Nearly every self-hosted peer (Boost, Kirby, Statamic, Payload, Drupal MIT/GPL; Directus BSL) leads with openness; Cortex ships `proprietary`. Make the Free-MIT / Pro-proprietary split unmistakable in the README and Plugin Store listing so Cortex isn't read as closed.
3. **Narrow the tool-catalog breadth gap.** ~40 tools versus Statamic's ~140 and Drupal's 222 reads as thin at a glance. Either lean hard on the "thick, typed, parameter-rich tools beat thin meta-tools" framing or expand coverage where it's genuinely missing.
4. **Address the missing vectorised-docs / embeddings story.** Boost's hosted semantic docs API and Drupal/Payload RAG are real gaps; `search_skills` is keyword-only. Phase 3 vectorised search should be on the public roadmap so the gap reads as sequenced, not absent.
5. **Lean into streaming + audit + per-user ACL in marketing.** These are the genuine, near-unique edges. They should be the headline of the Plugin Store listing, not buried in docs — most evaluators won't discover them unprompted.
6. **Consider standards-forward OAuth parity.** Directus's CIMD/DCR and Statamic's DCR are cited as more standards-forward. Cortex already does DCR (RFC 7591) — make sure that's surfaced, since it's a strength that's easy to under-communicate.

---

## Sources

### Laravel Boost
[^boost1]: https://github.com/laravel/boost
[^boost2]: https://laravel.com/docs/12.x/boost
[^boost3]: https://laravel.com/blog/announcing-laravel-boost

### Sanity
[^sanity1]: https://www.sanity.io/docs/ai/mcp-server
[^sanity2]: https://www.sanity.io/blog/introducing-sanity-model-context-protocol-server
[^sanity3]: https://github.com/sanity-io/sanity-mcp-server
[^sanity4]: https://www.sanity.io/docs/agent-actions/introduction
[^sanity5]: https://www.sanity.io/agent-actions
[^sanity6]: https://www.sanity.io/docs/ai/agent-context
[^sanity7]: https://www.sanity.io/docs/platform-management/how-ai-credits-work
[^sanity8]: https://www.sanity.io/docs/studio/ai-assist-field-actions

### Contentful
[^cf1]: https://www.contentful.com/developers/docs/tools/mcp-server/
[^cf2]: https://github.com/contentful/contentful-mcp-server
[^cf3]: https://www.contentful.com/help/ai-automations/ai-actions/
[^cf4]: https://www.contentful.com/blog/model-context-protocol-introduction/
[^cf5]: https://www.contentful.com/products/ai-actions/

### Storyblok
[^sb1]: https://www.storyblok.com/mp/mcp-server
[^sb2]: https://www.storyblok.com/lp/mcp-server
[^sb3]: https://github.com/storyblok/mcp-server
[^sb4]: https://mcp.labs.storyblok.com/
[^sb5]: https://mcp.labs.storyblok.com/install
[^sb6]: https://www.storyblok.com/docs/manuals/ai-assistance
[^sb7]: https://www.storyblok.com/mp/ai-features
[^sb8]: https://www.storyblok.com/mp/ideation-room

### DatoCMS
[^dato1]: https://www.datocms.com/docs/mcp-server
[^dato2]: https://github.com/datocms/mcp
[^dato3]: https://www.datocms.com/features
[^dato4]: https://www.datocms.com/marketplace/plugins
[^dato5]: https://www.datocms.com/pricing

### Prismic
[^prismic1]: https://prismic.io/docs/ai
[^prismic2]: https://github.com/prismicio/prismic-mcp-server
[^prismic3]: https://prismic.io/updates/prismic-mcp
[^prismic4]: https://github.com/prismicio
[^prismic5]: https://composio.dev/toolkits/prismic/framework/claude-code
[^prismic6]: https://prismic.io/pricing
[^prismic7]: https://prismic.io/docs/onboard-content-managers

### Drupal
[^drupal1]: https://www.drupal.org/project/ai
[^drupal2]: https://www.drupal.org/project/mcp
[^drupal3]: https://www.drupal.org/project/mcp_server
[^drupal4]: https://www.drupal.org/project/mcp_client
[^drupal5]: https://www.drupal.org/project/mcp_tools
[^drupal6]: https://www.drupal.org/project/ai_agents
[^drupal7]: https://mcp-77a54f.pages.drupalcode.org/

### Kirby MCP
[^kirby1]: https://github.com/bnomei/kirby-mcp
[^kirby2]: https://raw.githubusercontent.com/bnomei/kirby-mcp/main/README.md
[^kirby3]: https://plugins.getkirby.com/bnomei/kirby-mcp
[^kirby4]: https://plugins.getkirby.com/topics/ai

### Statamic
[^statamic1]: https://statamic.com/addons/cboxdk/statamic-mcp
[^statamic2]: https://github.com/cboxdk/statamic-mcp
[^statamic3]: https://github.com/cboxdk/statamic-mcp/blob/main/docs/getting-started/ai-clients.md
[^statamic4]: https://statamic.com/addons/stopa-development/statamic-ai-assistant
[^statamic5]: https://statamic.com/blog/statamic-supports-laravel-13

### Payload CMS
[^payload1]: https://payloadcms.com/docs/plugins/mcp
[^payload2]: https://payloadcms.com/enterprise/ai-framework
[^payload3]: https://payloadcms.com/enterprise/enterprise-ai
[^payload4]: https://github.com/ashbuilds/payload-ai
[^payload5]: https://payloadcms.com/posts/blog/open-source
[^payload6]: https://github.com/payloadcms/payload/blob/main/LICENSE.md

### Directus
[^directus1]: https://directus.io/docs/guides/ai/mcp
[^directus2]: https://directus.io/docs/guides/ai/mcp/installation
[^directus3]: https://directus.io/docs/guides/ai/mcp/local-mcp
[^directus4]: https://directus.io/docs/guides/ai/mcp/local-mcp/tools
[^directus5]: https://directus.io/mcp
[^directus6]: https://directus.io/pricing/self-hosted
[^directus7]: https://directus.io/bsl

### Ghost
[^ghost1]: https://docs.ghost.org/admin-api
[^ghost2]: https://docs.ghost.org/content-api
[^ghost3]: https://ghost.org/pricing/
[^ghost4]: https://github.com/TryGhost/Ghost
[^ghost5]: https://github.com/tryghost/ghost/blob/main/LICENSE
[^ghost6]: https://fanyangmeng.blog/introducing-ghost-mcp-a-model-context-protocol-server-for-ghost-cms/
[^ghost7]: https://github.com/siva-sub/ghost-cms-mcp-server
[^ghost8]: https://forum.ghost.org/t/ghost-and-artificial-intelligence/48457

---

## Architectural comparison — how Cortex is built vs peers

This section compares *construction*, not product/pricing (that's the matrix above). It splits architecturally-comparable self-hosted/installable peers from the vendor-hosted SaaS group (whose server internals are not inspectable). Competitor internals are drawn from public docs/source where available; cells marked *opaque* are vendor-operated.

### A — Architecture & build model

| Product | Deployment | Server & tool architecture | MCP spec & transport | Permission enforcement | 3rd-party extensibility | Streaming | Audit |
|---|---|---|---|---|---|---|---|
| **Cortex** | In-process Craft 5 plugin (Composer), runs inside the customer's own app | Transport-agnostic JSON-RPC dispatcher; **boot-once class-per-tool registry** (~40 thick, mode-driven tools) | 2025-06-18; **stdio + Streamable HTTP**; `outputSchema`/`structuredContent` dual-emit | **Native Craft perms at tool + mode layer** (3-method gating) + `execute()` re-check; transport = security boundary | `EVENT_REGISTER_TOOLS/PROMPTS/RESOURCES` + generator + Schema DSL | **Fiber-bridge + generator-yield over SSE**; cooperative cancel + TCP-disconnect | DB `cortex_invocations`, soft-write, secret-redacted args |
| Laravel Boost | In-process Laravel `--dev` dependency | Tool registry; framework-native | MCP; stdio | None (local trust) | Guidelines/Skills contributable by any package | Not documented | None |
| Kirby MCP (`bnomei`) | Community plugin (Composer), flat-file Kirby | Class/tool model (37 tools, 15 resources + 15 templates) | MCP; stdio + optional HTTP | Global scopes, **NOT per-user** | Kirby plugin layer | Not documented | JSONL dump (not true audit) |
| Statamic (`cboxdk`) | 3rd-party addon (Composer), Statamic 6.6+ | Tool-per-op across 11 domain routers (140+ tools) | MCP; Streamable HTTP | Native RBAC + 21 token scopes | Addon ecosystem | Not documented | Yes (CP dashboard) |
| Drupal (`ai` + `mcp`) | Contrib modules, self-hosted | Two MCP server modules; `mcp_tools` 222 tools/34 submodules; AI Agents framework | MCP; HTTP | OAuth scopes + connection presets; per-call recheck unclear | Module ecosystem | Unknown | Partial (AI Logging) |
| Payload | Official MCP, self-hosted (TS) | HTTP server; per-tool ACL toggles | MCP; HTTP | Native + per-tool ACL, per-key toggles | Config-driven | Partial (implied) | `onEvent` hook (DIY) |
| Directus | Native built-in MCP; self-host or Cloud | Built into the platform core | MCP; HTTP | Native perms; install-wide `DISABLE_TOOLS` | Platform-native | Not documented | Activity/revisions log |
| Headless SaaS (Sanity, Contentful, Storyblok, DatoCMS, Prismic) | Vendor-hosted; no self-host | *Opaque* vendor server; Storyblok/DatoCMS use meta-tool / sandboxed-exec proxies | MCP (mostly remote) / proprietary | Token scope / OAuth; role-based | Vendor-controlled | Partial / none | Usage tracking; mostly no per-invocation audit |

### B — Knowledge / skills delivery (the "owned skills" axis)

| Product | Knowledge surface | Authored / owned by | Delivery mechanism | Retrieval | Updates |
|---|---|---|---|---|---|
| **Cortex** | **~27k lines hand-authored Craft expertise** + org-authored Skill element (Pro) | **Craftpulse — first-party, owned** (`craftcms-claude-skills`) | MCP **prompts** (primary) + **resources** (secondary); custom element for org skills | **Keyword** (`search_skills`) — *not vectorized* | Versioned with the plugin release (remote-fetch = Phase 3) |
| Laravel Boost | Guidelines + Agent Skills + 17k-chunk docs | Laravel (first-party) **+ any package (3rd-party contributable)** | Bundled guidelines/skills + hosted docs API | **Vectorized semantic retrieval** (docs) | Package + hosted API |
| Kirby MCP | ~216 KB KB + 15 skills | Community author (`bnomei`) | Bundled, copied locally to the agent | Bundled lookup | Plugin release |
| Statamic (`cboxdk`) | **Discovery tools only — no bundled corpus** | — | Runtime schema/type discovery | Live introspection | n/a |
| Drupal | RAG corpus + prompts | Site-assembled | `ai` module embeddings + Prompts | **Vectorized (RAG)** | Site-managed |
| Payload | Custom prompts/resources + RAG (Enterprise) | Project-assembled | MCP prompts/resources | RAG (Enterprise) | Project-managed |
| Directus | System prompt + Prompts collection | Project-assembled | Prompts collection | Light prompt injection | Project-managed |
| Headless SaaS | Schema-aware; mostly no portable corpus | Vendor | In-product AI | Schema / vendor | Vendor |

### Architectural positioning

- **Closest architectural twin: Statamic's `cboxdk/statamic-mcp`.** Same governance shape — Streamable HTTP, OAuth 2.1 + scoped bearers, native RBAC, a CP dashboard, audit. The decisive difference is the knowledge axis: cboxdk ships *discovery tools only*; Cortex pairs the same governance with a large, owned, hand-authored skills corpus.
- **Knowledge-ambition peer: Laravel Boost** (the ancestor). Boost matches — and on retrieval *beats* — Cortex with vectorized semantic docs and third-party-contributable skills. But Boost is a *local dev tool*: no auth, no per-user ACL, no audit, not safely exposable to remote or multi-user agents.
- **Cortex's distinct position is the intersection nobody else occupies:** a governed, multi-user, remotely-exposable surface (OAuth 2.1 + per-user/per-mode ACL + audit + cooperative-cancel streaming) **and** a large first-party *owned*, versioned knowledge corpus delivered as MCP prompts/resources. Statamic has the governance without the corpus; Boost has the corpus-ambition without the governance.
- **Where peers beat Cortex architecturally (honest):** Boost and Drupal on **semantic/vectorized retrieval** — Cortex authors *more* knowledge than almost anyone but retrieves it with keyword search, which underuses the corpus (vectorized `search_docs` is roadmapped to Phase 3 and is arguably the single highest-leverage architectural gap); Boost on **third-party-contributable** skills; Directus/Payload on **zero-install** (MCP built into the platform vs a separate plugin); the SaaS group on **zero-ops hosted scaling**.

> Status: nothing here is released. "Built" describes branch-resident, test-covered functionality, not a GA product.
