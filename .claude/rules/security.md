<!-- craftcms-claude-skills -->
# Security

- `$this->requirePermission()` on every controller action that accesses protected resources.
- `$this->requirePostRequest()` on every mutating action.
- `$this->requireAdmin(requireAdminChanges: true)` for settings that modify project config.
- All user input through `Db::parseParam()` or `Db::parseDateParam()` — never raw SQL interpolation.
- Sensitive data (API keys, webhook secrets, MCP bearer tokens) via `App::env()`, never hardcoded.
- No secrets in CLAUDE.md, committed files, or log output.
- Per-resource permission scoping: `"cortex:action-name:{$entityUid}"`.
- Element authorization via `canView()`, `canSave()`, `canDelete()` on element classes.

## Cortex-specific

- **Transport boundary is the security boundary.** stdio is trusted (local user). HTTP is untrusted — every request authenticates and authorizes against Craft user permissions before dispatching to a tool.
- **`craft exec` is stdio-only.** Reject it at the HTTP transport layer regardless of caller permissions or token scope.
- **No `Process`, `exec()`, `shell_exec()`, `passthru()`, backticks, or `popen()` anywhere.** Console commands dispatch through Craft's internal console runner.
- **Command allowlist is enforced at the tool layer**, not at the prompt layer. A user disabling allowlist checks in the CP must not be possible without admin permissions.
- **PII tools are Pro/Commerce only** — Free tier must not expose user PII, addresses, order data, or customer lookups.
- **Bearer tokens for HTTP transport are bound to a Craft user.** Token revocation must invalidate all in-flight requests.
- **Rate limit the HTTP transport** at the controller layer. stdio is single-process so it doesn't need it.
- **Audit log every tool invocation over HTTP** — tool name, user, timestamp, arguments redacted of secrets.
