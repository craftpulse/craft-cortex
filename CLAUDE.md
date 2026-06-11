<!-- craftcms-claude-skills v1.4.2 -->
# Cortex — Craft CMS 5 MCP Server Plugin

@.claude/rules/coding-style.md
@.claude/rules/architecture.md
@.claude/rules/git-workflow.md
@.claude/rules/scaffolding.md
@.claude/rules/security.md
@.claude/rules/migrations.md
@.claude/rules/testing.md

## Project

- **Package:** `craftpulse/craft-cortex`
- **Handle:** `cortex`
- **Namespace:** `craftpulse\cortex`
- **Author:** Craftpulse
- **Status:** Free + Pro tiers feature-complete, pre-Plugin-Store-submission (release tag on hold). Free: 33 tools, 10 prompts, 98 resources, stdio transport, project-config + DB allowlist, six `craft_exec` security gates, install command. Pro (on the `gate-9-*` branch stack, PRs #2/#3 → `develop-v5`): Streamable HTTP transport with OAuth 2.1 + bearer tokens, 9 write tools, per-user tool filtering, audit log, custom-skill element type, CP tabs (Settings · Tokens · Allowlist · Activity), edition enforcement (403 gates), MCP spec 2025-11-25. Full Pest + PHPStan L8 + ECS green. The Connection and Skill-authoring CP tabs slipped to a Pro point release.

Cortex is a Model Context Protocol server for Craft CMS. It exposes Craft internals to AI agents over dual transport (stdio for dev, Streamable HTTP for content ops). Editions: Free / Pro (Commerce support is planned as a separate plugin, not an edition). Locked architecture decisions live in this repo's auto-memory and the planning doc (see Paths below).

## General

Be critical. We're equals — push back when something doesn't make sense.

Do not excessively use emojis.

Do not include AI attribution in commits, PR descriptions, PR comments, issue comments, or generated code. All output should be indistinguishable from human-authored work.

Do not include "Test plan" sections in PR descriptions.

## Tools

Use `ddev` shorthand commands: `ddev composer`, `ddev craft`, `ddev npm`. Never run `php`, `composer`, or `npm` on the host — everything goes through DDEV.

Use `gh` for all GitHub operations — it's already authenticated.

## Environment

```bash
ddev composer check-cs               # ECS code style
ddev composer fix-cs                 # ECS auto-fix
ddev composer phpstan                # PHPStan analysis
ddev exec vendor/bin/pest            # Pest tests
ddev craft up                        # Migrations + project config
ddev composer install                # Install deps (auto-runs craft up)
```

These commands run against the test environment in `~/dev/craft-plugin-playground/cms_v5`, where this plugin is symlinked during development.

## Plugin Structure

```
src/
├── Plugin.php                   # Entry point — wires services, registers generator, hooks Gc
├── attributes/                  # PHP 8 tool-annotation attributes (IsReadOnly, IsDestructive, IsIdempotent, IsOpenWorld, IsStdioOnly, Title)
├── config/                      # config/cortex.php reference template
├── console/controllers/         # cortex/serve (stdio MCP), cortex/install, cortex/docs
├── controllers/                 # CP web controllers (Settings — runtime allowlist overrides)
├── db/                          # Table constants
├── events/                      # RegisterToolsEvent, RegisterPromptsEvent, RegisterResourcesEvent
├── generator/                   # ddev craft make cortex-tool scaffolder
├── mcp/                         # JSON-RPC dispatcher (Server.php) — transport-agnostic
├── migrations/                  # Install.php — cortex_runtime_overrides table
├── models/                      # Settings (allowedCommands, execEnabled, execDryRunDefault, runtimeOverrideTtl)
├── prompts/                     # PromptInterface, AbstractPrompt, SkillPrompt
├── records/                     # RuntimeOverride ActiveRecord
├── resources/                   # ResourceInterface, AbstractResource, SkillResource, AgentResource, ResourceTemplateInterface
├── services/                    # Allowlist, Tools, Prompts, Resources (yii\base\Component)
├── templates/                   # CP Twig templates (Settings page)
└── tools/                       # ToolInterface + AbstractTool + ToolException
    ├── content/                 # entries, assets, categories, tags, globals
    ├── dev/                     # craft_command, craft_exec, clear_caches, resave
    ├── graphql/                 # graphql
    ├── schema/                  # sections, entry_types, fields, field_types, category_groups, tag_groups, volumes_and_filesystems, sites, image_transforms, element_types
    ├── support/                 # AttributeReader, ConsoleRunner, ElementSerializer, InvocationContext, InvocationLogger, RegistryLog, Schema (JSON Schema DSL), SecretRedactor, StdoutCaptureFilter
    ├── system/                  # get_initial_context, system_info, config, plugins, routes, system_diagnostics, database_schema, extensibility, permissions_and_groups, search_skills
    └── workflow/                # drafts_and_revisions, content_audit, import_export
```

Not built (deferred or never planned): `enums/`, `jobs/` — gc cleanup runs inline via `Gc::EVENT_RUN` rather than a queue job. Custom skill element type lands in Phase 2 Gate 8.6; `src/elements/` populated then.

## Paths

- **Repo root:** `/Users/michtio/dev/craft-plugins/v5/craft-cortex/`
- **Test environment:** `/Users/michtio/dev/craft-plugin-playground/cms_v5/` — Craft 5 install where this plugin is symlinked. Run `ddev` commands from there.
- **Plan source of truth:** `/Users/michtio/dev/craft-plugin-playground/PLANNING.md` section 4 (4.1–4.14, reworked 2026-05-05 for lean tooling + Phase 0 inspector). Always read before planning Cortex work.
- **Skills repo:** `/Users/michtio/dev/craftcms-claude-skills/` — bundled as MCP prompts (primary) and resources (secondary) in Phase 1.
- **DDEV MCP Inspector add-on:** `/Users/michtio/dev/ddev/ddev-mcp-inspector/` — Phase 0 deliverable. Standalone DDEV add-on (currently v1.0.0-beta.1) used as cortex's Gate-1 verification harness. Marketed independently in DDEV / Craft / Laravel / Drupal / Node / Python communities.
- **Dev root:** `/Users/michtio/dev/` — parent folder. The planner clones public repos into `/Users/michtio/dev/research/` for plugin audits.
- **Research folder:** `/Users/michtio/dev/research/` — ephemeral. Shallow clones (`--depth 1`), cleaned up after use.

## Permissions

`.claude/settings.local.json` pre-approves DDEV, git, and `gh` commands so agents run without permission prompts. This file is gitignored — each developer can adjust it locally. If commands are being blocked, check this file first.

Read access is granted on the connected playground project (`~/dev/craft-plugin-playground/`) so Claude can read PLANNING.md, run plugin code in the test CMS, and tail logs from `cms_v5/storage/logs/`.

## Documentation

- Plugin development: https://craftcms.com/docs/5.x/extend/
- Class reference: https://docs.craftcms.com/api/v5/
- Generator: https://craftcms.com/docs/5.x/extend/generator.html
- MCP spec: https://modelcontextprotocol.io/
- Craft source: `vendor/craftcms/cms/src/` (in the test environment)

## Skills

Load these when working in this repo:

- `craftcms` — plugin/module extend surface (services, controllers, queue jobs, project config, GraphQL, etc.)
- `craft-php-guidelines` — PHP coding standards, PHPDocs, section headers
- `ddev` — for any DDEV command or container troubleshooting

If CP screens with JS or asset bundles get added later, also load `craft-garnish`.
