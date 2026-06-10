# Cortex — Manual Browser Smoke Test

The pre-submission smoke pass that the automated suite **cannot** cover: rendering `_layouts/cp`, driving the VueAdminTable + Garnish.Slideout JS in the four CP tabs, the OAuth consent screen, and the live MCP protocol surface through a real client. Pest (1,126/0), PHPStan L8, and ECS are all green; this validates the parts that only exist in a browser and on the wire.

**Run this in a fresh session.** It is driven primarily by the **Chrome DevTools MCP** (against the CP and the MCP Inspector) with a few **Bash/curl** steps for the wire-level HTTP transport that a browser can't exercise. Record a PASS/FAIL + evidence for every case; the final go/no-go for Plugin Store submission is the sum of these plus the packaging punch-list (LICENSE file, `src/icon.svg`, `extra.changelogUrl`, release tag).

---

## 0. Environment & preconditions

| Fact | Value |
|---|---|
| Playground site | `https://plugin-playground-v5.ddev.site` |
| Control Panel | `https://plugin-playground-v5.ddev.site/admin` |
| MCP Inspector (add-on) | `https://plugin-playground-v5.ddev.site:6275` |
| Admin login | `michtio` (password is the developer's — ask if unknown) |
| Branch under test | `gate-9-cp` (`dev-gate-9-cp`) |
| In-container project root | `/var/www/html/cms` (craft binary: `/var/www/html/cms/craft`) |
| Web container name | `ddev-plugin-playground-v5-web` |
| Protocol | advertises **2025-11-25**, negotiates **2025-06-18** |
| Settings (verified) | `httpEnabled=true`, `execEnabled=true`, `execDryRunDefault=true`, `dcrEnabled=true`, `devMode=true`, `allowedOrigins` empty (warn-and-allow in dev) |
| Content fixtures | Marvel set — sections/entries, category groups (factions/affiliations/powers), `infinityStones` tags, `shieldDirective` global, users incl. Director Fury |

### 0.1 Flip to the Pro edition (required for the full smoke)

The playground ships on **Free**, so the Pro surfaces — **Tokens** + **Activity** tabs, OAuth-over-HTTP, the nine write tools, the Skill element — are hidden until the edition is Pro. Flip it for the smoke and flip it back after.

```bash
# from ~/dev/craft-plugin-playground/cms_v5
# edit cms/config/project/project.yaml line ~72:  cortex: { edition: free } -> edition: pro
ddev craft up
ddev exec --dir /var/www/html/cms php craft cortex/edition/show   # expect: edition: pro, is(pro): true
```

Reverse when done: set `edition: free`, `ddev craft up`, confirm `cortex/edition/show` reads `free`.

> Run the smoke **twice** where it matters: once on **Free** (Section C tool/prompt/resource counts: 33/10/98; no Tokens/Activity tabs; write tools absent) and once on **Pro** (Tokens/Activity present; 42 tools; OAuth live). Each case below marks which edition it needs.

### 0.2 Conventions for every case

- **Drive the CP & Inspector with Chrome DevTools MCP**: `navigate_page`, `take_snapshot` (prefer over screenshot for structure), `click`, `fill`/`fill_form`, `take_screenshot` (for the record), `list_console_messages`, `list_network_requests`.
- **Measure, don't just pass/fail.** For each case record: the observed result vs Expected, **any console errors**, **any failed/4xx/5xx network requests**, response counts/shape where relevant, and a screenshot filename. A case with red console errors is a FAIL even if the page "looks fine."
- Save screenshots as `smoke-<section><n>-<slug>.png` and reference them in the results table at the bottom.

---

## Section A — CP operator surface (Chrome MCP)

### A1 — Plugin loads, nav present *(Free + Pro)*
1. `navigate_page` to the CP, log in as `michtio` if needed.
2. `navigate_page` to `…/admin/settings/plugins/cortex`.
3. `take_snapshot`.

**Expected:** Settings page renders under `_layouts/cp` with the Cortex tab bar. On **Free**: Settings + Allowlist tabs. On **Pro**: Settings + Allowlist + **Tokens** + **Activity**. No console errors; no failed network requests.
**Measure:** which tabs are present (confirms edition gating in the UI), console + network clean.

### A2 — Settings tab: exec toggles persist to project config *(Free + Pro)*
1. On the Settings tab, toggle `execDryRunDefault` off, **Save**.
2. Reload the page; confirm the toggle stuck.
3. Restore it to on and Save.

**Expected:** Save round-trips (302 → success flash), the value persists across reload, no console errors. (Optional confirm: `grep execDryRunDefault cms/config/project/project.yaml` reflects the change before you restore it.)
**Measure:** the POST returns 200/302, value persists, project-config write observed.

### A3 — Allowlist tab: VueAdminTable + slideout *(Free + Pro)*
1. Open the **Allowlist** tab. `take_snapshot`.
2. Confirm the VueAdminTable renders the effective command patterns.
3. Click **Add override** (or equivalent) → the Garnish slideout opens.
4. Fill a pattern (e.g. `utils/*`) with a short note + a TTL, **Save**.
5. Confirm the new row appears in the table without a full reload.
6. Remove it; confirm it disappears.

**Expected:** table renders, slideout opens/closes, add + remove update the table live (the `actionAllowlistTableData` AJAX path), no console errors, the mutating POSTs are 200.
**Measure:** screenshot the populated table + the open slideout; record the add/remove network calls.

### A4 — Tokens tab: issue → plaintext-once → revoke *(Pro only)*
1. Open the **Tokens** tab. `take_snapshot` — VueAdminTable renders existing tokens (likely empty).
2. Click **Issue token** → slideout. Choose user `michtio`, optional name/TTL, **Issue**.
3. **Capture the plaintext token shown once** — record it (you need it for Section D). Confirm the UI states it won't be shown again.
4. Confirm the new token row appears (showing a masked/hashed identifier, never the plaintext).
5. Reload — confirm the plaintext is **not** recoverable from the row.
6. Revoke the token; confirm the row updates to revoked / disappears.

**Expected:** issue works, plaintext appears exactly once, the table never persists plaintext, revoke is immediate. No console errors.
**Measure:** screenshot the issue slideout + the once-shown plaintext panel (redact in any shared report); confirm the row carries no plaintext.

### A5 — Activity tab: audit rows, filters, detail slideout *(Pro only)*
> Prereq: generate audit rows first by running a few tool calls in **Section C** (or Section D) before this case, so the table has content.
1. Open the **Activity** tab. `take_snapshot` — VueAdminTable lists invocation rows (tool, user, outcome kind, duration, client, timestamp).
2. Apply a filter (by tool name and/or outcome kind); confirm the table narrows (server-side via `actionActivityTableData`).
3. Click a row → detail slideout opens showing redacted arguments + response excerpt.
4. **Secret-redaction check:** if any row is a `config` or `craft_command` call, confirm secret-keyed fields render as `<redacted>` and no plaintext secret is visible.
5. Sort by a column; confirm ordering changes.

**Expected:** rows render, filters + sort hit the server and narrow correctly, the detail slideout shows redacted data, no console errors.
**Measure:** screenshot the table + an open detail slideout; explicitly note whether any secret appears unredacted (must be none).

> **Permission scoping (optional, needs a second non-admin user with the Activity permission):** log in as that user and confirm the Activity table shows **only their own** rows, and that hitting another user's row id (`…/cortex/activity/row?id=<foreign>`) returns **404**, not the row. This is the fail-closed scoping; admins see all rows.

---

## Section B — OAuth & discovery (Chrome MCP)

### B1 — `.well-known` discovery documents *(Pro)*
1. `navigate_page` to `…/.well-known/oauth-authorization-server`. Record the JSON.
2. `navigate_page` to `…/.well-known/oauth-protected-resource`. Record the JSON.

**Expected:** both return valid JSON (RFC 8414 / RFC 9728): issuer, authorization/token/registration endpoints, `scopes_supported` (`read`, `write`), `code_challenge_methods_supported` containing **`S256`** and **not** `plain`. With `httpEnabled=false` these would 503 — confirm they're live (it's true here).
**Measure:** paste the two JSON bodies; confirm S256-only.

### B2 — OAuth consent screen renders *(Pro)*
1. Register a DCR client via Bash:
   ```bash
   curl -sk -X POST https://plugin-playground-v5.ddev.site/oauth/register \
     -H 'Content-Type: application/json' \
     -d '{"client_name":"Smoke Client","redirect_uris":["https://plugin-playground-v5.ddev.site/admin"],"token_endpoint_auth_method":"none"}'
   ```
   Record the returned `client_id`.
2. While logged into the CP, `navigate_page` to:
   `…/oauth/authorize?response_type=code&client_id=<client_id>&redirect_uri=https://plugin-playground-v5.ddev.site/admin&code_challenge=<S256-challenge>&code_challenge_method=S256&scope=read&state=smoke`
   (generate a PKCE verifier/challenge; any 43+ char verifier SHA-256→base64url).
3. `take_snapshot` + screenshot.

**Expected:** the consent screen renders, names the client ("Smoke Client"), shows the requested scope, and presents Approve/Deny. No console errors.

### B3 — Consent-screen XSS regression *(Pro — HIGH VALUE, just fixed)*
1. Register a second DCR client whose **name carries a script payload**:
   ```bash
   curl -sk -X POST https://plugin-playground-v5.ddev.site/oauth/register \
     -H 'Content-Type: application/json' \
     -d '{"client_name":"<script>alert(1)</script>","redirect_uris":["https://plugin-playground-v5.ddev.site/admin"],"token_endpoint_auth_method":"none"}'
   ```
2. `navigate_page` to `…/oauth/authorize?…client_id=<this client_id>…` (as in B2).
3. `take_snapshot`, then **`list_console_messages`** and check for any dialog.

**Expected:** the page renders with the client name **HTML-escaped** — you see the literal text `<script>alert(1)</script>` as content, **no alert dialog fires**, and `list_console_messages` shows no script execution. This is the fix in `authorize.twig:71` (`clientName|e('html')`); a regression would pop the alert.
**Measure:** screenshot showing the escaped literal; explicitly record "no dialog / no script execution."

---

## Section C — MCP protocol surface via the Inspector (Chrome MCP)

Drive the **MCP Inspector** at `https://plugin-playground-v5.ddev.site:6275`. Configure a **STDIO** transport:
- **Command:** `docker`
- **Arguments:** `exec -i ddev-plugin-playground-v5-web php /var/www/html/cms/craft cortex/serve`

### C1 — initialize handshake *(Free + Pro)*
1. In the Inspector, connect. `take_snapshot`.

**Expected:** handshake succeeds; `serverInfo` = `cortex` `5.0.0`; **protocolVersion `2025-11-25`** (the Inspector requests latest). No errors.
**Measure:** record the negotiated protocol version + serverInfo.

### C2 — list endpoints + counts *(both editions — counts differ)*
1. List **Tools**, **Prompts**, **Resources** in the Inspector.

**Expected:** **Free** → 33 tools, 10 prompts, 98 resources. **Pro** → 42 tools (the nine write tools + Skill appear), 10 prompts, 98 (or more, if element-stored skills exist) resources.
**Measure:** the three counts per edition; spot-check that `entry`/`users`/`bulk_entries` are **absent on Free, present on Pro** (edition gating at the protocol layer).

### C3 — tool calls against the fixtures *(Free covers these)*
Call each and **measure the response** (shape, counts, latency feel, errors):
1. `get_initial_context` (no args) → returns Craft version/edition/env, primary site, sites/sections/element-types index, the 10 skill prompts, exec posture, allowlist. Confirm `edition` matches the current flip.
2. `entries` with `{"section":"<a marvel section>","limit":3}` → returns entries; confirm relational fields stub to `{type:"relation",loaded:false}` unless `with` is passed.
3. `entries` again with `{"limit":1,"count":true}` → returns a count, not rows (count mode).
4. `search_skills` with `{"query":"element save lifecycle"}` → ranked results with snippet + `craft-skills://` URIs + `source` field.
5. A schema tool, e.g. `sections` (no args) → lists sections; `fields` → lists fields.
6. `config` with a secret-bearing path (e.g. `{"path":"db"}`) → confirm secret-keyed values render `<redacted>` (SecretRedactor on the wire).

**Expected:** each returns a structured payload; errors come back as `isError:true` envelopes (not protocol errors) if you feed a bad arg — try one deliberately (e.g. unknown section) and confirm the envelope shape.
**Measure:** paste a trimmed response per call; note the redaction in #6 and the relation-stub in #2.

### C4 — `craft_exec` gates *(Free; stdio-only)*
1. `craft_exec` with `{"expression":"1 + 1"}` → **dry-run** (no `confirm`): returns analysis, `evaluated:false`.
2. `craft_exec` with `{"expression":"1 + 1","confirm":true}` → evaluates, `result: 2`.
3. `craft_exec` with `{"expression":"Craft::$app->getElements()->deleteElementById(999999)","confirm":true}` → **blocked**: destructive pattern needs `dangerous:true` too; confirm it refuses with the hint.

**Expected:** the six-gate behaviour — dry-run default, confirm to evaluate, destructive needs `dangerous`. **In Section D you confirm this tool is rejected entirely over HTTP.**
**Measure:** the three response shapes.

### C5 — prompts/get + resources/read *(Free)*
1. `prompts/get` `craftcms_extending` → returns the SKILL.md as a user message (verbatim).
2. `resources/read` a reference URI from a C3 #4 result (e.g. `craft-skills://craftcms/<reference>`) → returns the document content.

**Expected:** both return content; the prompt body is the authored skill, not a stub.
**Measure:** confirm non-empty authored content (first ~200 chars).

---

## Section D — HTTP transport on the wire (Bash/curl) *(Pro)*

Uses the plaintext token from **A4** (`export TOKEN=<plaintext>`). Base: `https://plugin-playground-v5.ddev.site/cortex/mcp`.

### D1 — initialize over HTTP + session
```bash
curl -sk -i -X POST https://plugin-playground-v5.ddev.site/cortex/mcp \
  -H "Authorization: Bearer $TOKEN" \
  -H 'MCP-Protocol-Version: 2025-11-25' \
  -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25","clientInfo":{"name":"smoke","version":"1"}}}'
```
**Expected:** 200, `protocolVersion:"2025-11-25"`, and an `Mcp-Session-Id` response header. **Measure:** capture the session id for subsequent calls.

### D2 — version negotiation
Repeat D1 with header+body `2025-06-18` → expect echo `2025-06-18` (200). Then send header `2024-01-01` → expect **400** unsupported. **Measure:** both outcomes.

### D3 — auth gates
- No `Authorization` header → **401** with `WWW-Authenticate: Bearer`.
- Bad token → **401**.
- Bad `Origin` header (set `Origin: https://evil.test` — note: empty allowlist + devMode warn-allows, so to test the 403 you'd set a non-empty `allowedOrigins` first; otherwise just record the dev warn-allow behaviour).

### D4 — `craft_exec` is rejected over HTTP *(the security boundary)*
```bash
# with a valid session id from D1:
curl -sk -X POST https://plugin-playground-v5.ddev.site/cortex/mcp \
  -H "Authorization: Bearer $TOKEN" -H 'MCP-Protocol-Version: 2025-11-25' \
  -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' \
  -H 'Mcp-Session-Id: <id>' \
  -d '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"craft_exec","arguments":{"expression":"1+1"}}}'
```
**Expected:** a JSON-RPC error envelope stating `craft_exec` is **stdio-only and cannot be invoked over the HTTP transport** (method-not-found code). This is the transport-as-boundary guarantee. **Measure:** the rejection message.

### D5 — a Pro write tool over HTTP, perm-gated + audited
Call `tools/list` over HTTP and confirm the write tools appear (Pro). Call a read like `get_initial_context` over HTTP → 200. Then return to **A5** and confirm these HTTP calls produced **audit rows** with `user`, `clientName`, redacted args.

---

## Results — fill in

| # | Case | Edition | Result | Console/Network clean? | Evidence (screenshot / response) | Notes |
|---|---|---|---|---|---|---|
| A1 | Nav + tabs | Free/Pro | | | | |
| A2 | Settings save | Free/Pro | | | | |
| A3 | Allowlist table+slideout | Free/Pro | | | | |
| A4 | Tokens issue/revoke | Pro | | | | |
| A5 | Activity table/filter/detail/redaction | Pro | | | | |
| B1 | .well-known JSON (S256-only) | Pro | | | | |
| B2 | Consent renders | Pro | | | | |
| B3 | **Consent XSS escaped (no dialog)** | Pro | | | | |
| C1 | initialize 2025-11-25 | Free/Pro | | | | |
| C2 | list counts (33/10/98 · 42 Pro) | both | | | | |
| C3 | tool calls + redaction + relation-stub | Free | | | | |
| C4 | craft_exec gates | Free | | | | |
| C5 | prompts/get + resources/read | Free | | | | |
| D1 | initialize over HTTP + session | Pro | | | | |
| D2 | version negotiation (echo / 400) | Pro | | | | |
| D3 | auth gates (401) | Pro | | | | |
| D4 | **craft_exec rejected over HTTP** | Pro | | | | |
| D5 | write tools listed + audited | Pro | | | | |

**Go / no-go for submission:** ____ (all cases PASS, no unredacted secrets, B3 + D4 confirmed = the two security guarantees most worth proving by hand). Then: restore edition to Free, complete the packaging punch-list (LICENSE, `src/icon.svg`, `extra.changelogUrl`, release tag), tag, submit.
