# Cortex — Manual Smoke Test (reusable runbook)

The repeatable pre-release smoke pass covering what the automated suite
**cannot**: rendering `_layouts/cp`, driving the VueAdminTable +
Garnish.Slideout JS in the CP tabs, the OAuth consent screen, the live MCP
protocol surface through a real client, and the edition boundary on the
wire. Run it before every tagged release and after any change to the CP
templates, `cortex.js`, the transport controllers, or edition logic.

**Drivers:** Chrome DevTools MCP (or a hand-driven browser) for the CP and
the MCP Inspector; `curl` for the wire-level HTTP checks; raw stdio via
`docker exec` for protocol counts. Record PASS/FAIL + evidence for every
case in a copy of the results table (archive completed runs under
`docs/smoke-evidence/<date>-results.md`).

---

## 0. Environment & preconditions

| Fact | Value |
|---|---|
| Playground site | `https://plugin-playground-v5.ddev.site` |
| Control Panel | `https://plugin-playground-v5.ddev.site/admin` (login `michtio`) |
| MCP Inspector (add-on) | `https://plugin-playground-v5.ddev.site:6275` |
| In-container project root | `/var/www/html/cms` (craft binary: `/var/www/html/cms/craft`) |
| Web container name | `ddev-plugin-playground-v5-web` |
| Protocol | advertises **2025-11-25**, also negotiates **2025-06-18** |
| Required settings | `httpEnabled=true`, `execEnabled=true`, `execDryRunDefault=true`, `dcrEnabled=true`, `devMode=true`, `allowedOrigins` empty (warn-and-allow in dev) |
| Content fixtures | Marvel set — heroes section, category groups (factions/affiliations/powers), `infinityStones` tags, `shieldDirective` global |

### 0.1 Edition flips

The playground ships on **Free**. Several sections need **Pro**; each case
below is tagged. To flip:

```bash
# from ~/dev/craft-plugin-playground/cms_v5
# edit cms/config/project/project.yaml (~line 72): cortex: { edition: free } <-> pro
ddev craft up --interactive=0
ddev exec --dir /var/www/html/cms php craft cortex/edition/show
```

**Always restore `edition: free` when finished** and confirm with
`cortex/edition/show`.

### 0.2 Known operational pitfalls (hit them once, never again)

- **Do NOT run the Pest suite mid-smoke.** The suite truncates
  `cortex_tokens` and `cortex_invocations` — it revokes any token you
  issued for Section D and wipes the Activity rows you generated for A5.
  Run the suite before or after, never between A4 and D/A5.
- **Clear caches after editing templates or `cortex.js`:**
  `ddev exec "rm -rf /var/www/html/cms/web/cpresources/* /var/www/html/cms/storage/runtime/compiled_templates/*"`.
- **"Complete the Update" interstitial:** after a CLI `ddev craft up`, the
  first CP request may show Craft's pending-update screen and CP action
  requests can 404 until you click *Finish up*. Do that before judging any
  AJAX behaviour.
- **Chrome DevTools MCP profile lock:** if `new_page` errors with "browser
  is already running", kill the orphan once with
  `kill $(pgrep -f 'chrome-devtools-mcp/chrome-profile' | head -1)`. Never
  `pkill -9` the whole profile tree — that takes the MCP server down with
  it for the rest of the session.
- The full suite leaks one empty-pattern `cortex_runtime_overrides` row
  per run (shows up as `""` in `get_initial_context`'s allowlist). Soft-
  delete it if it bothers the run:
  `ddev mysql -e "UPDATE cortex_runtime_overrides SET dateDeleted = NOW() WHERE pattern = '' AND dateDeleted IS NULL;"`

### 0.3 Conventions for every case

- Prefer `take_snapshot` (structure) over screenshots; screenshot for the
  record as `smoke-<section><n>-<slug>.png` in `docs/smoke-evidence/`.
- **Measure, don't just pass/fail:** observed vs expected, any console
  errors, any failed/4xx/5xx requests, counts/shapes, screenshot name. A
  case with red console errors is a FAIL even if the page "looks fine."
- Benign noise you may see and can ignore: Craft core's iframe-resizer
  info line; one Garnish `aria-hidden`-on-focused-element warning when a
  slideout closes; the `label[for]` DevTools issue on the settings form.

### Handy snippets

Authenticated curl session against the CP (when no browser is available):

```bash
JAR=/tmp/cortex-smoke-cookies.txt; rm -f $JAR
CSRF=$(curl -sk -c $JAR https://plugin-playground-v5.ddev.site/admin/login \
  | grep -o 'name="CRAFT_CSRF_TOKEN" value="[^"]*' | cut -d'"' -f4)
curl -sk -b $JAR -c $JAR -X POST 'https://plugin-playground-v5.ddev.site/index.php?p=admin/actions/users/login' \
  -H 'Accept: application/json' -H 'X-Requested-With: XMLHttpRequest' \
  --data-urlencode "CRAFT_CSRF_TOKEN=$CSRF" \
  --data-urlencode 'loginName=michtio' --data-urlencode 'password=<ask>'
```

Raw stdio batch (initialize + whatever follows):

```bash
printf '%s\n' \
  '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"smoke","version":"1"}}}' \
  '{"jsonrpc":"2.0","method":"notifications/initialized"}' \
  '{"jsonrpc":"2.0","id":2,"method":"tools/list"}' \
| docker exec -i ddev-plugin-playground-v5-web php /var/www/html/cms/craft cortex/serve
```

---

## Section A — CP operator surface

### A1 — Plugin loads, tab gating *(Free + Pro)*
1. Log in, open `…/admin/settings/plugins/cortex`, snapshot.
2. Repeat on the other edition.

**Expected:** page renders under `_layouts/cp` with the Cortex tab bar.
**Free:** exactly **Settings + Allowlist** (`tab-tokens` / `tab-activity` /
`tab-connection` absent from the HTML). **Pro:** all five — Settings ·
Tokens · Allowlist · Activity · Connection. No console errors, no failed
requests.
**Measure:** which `id="tab-…"` anchors are present per edition.

### A2 — Settings tab: exec toggles persist to project config *(Free + Pro)*
1. Toggle `execDryRunDefault` off, **Save**; reload; confirm it stuck.
2. Restore it to on and Save.

**Expected:** POST → 302 → reload, value persists both directions,
`grep execDryRunDefault cms/config/project/project.yaml` tracks each save.
**Measure:** POST status, persisted value, PC write.

### A3 — Allowlist tab: VueAdminTable + slideout *(Free + Pro)*
1. Open **Allowlist**; the overrides table renders (rows or the empty
   message), data via `cortex/settings/allowlist-table-data` → 200.
2. **Add override** → Garnish slideout opens. Fill a pattern
   (`utils/*`), note, TTL; **Issue override**.
3. New row appears live (no full reload), expiry rendered.
4. Remove it (confirm dialog) → row disappears live.

**Expected:** table-data 200, slideout HTML 200, `add-override` POST 200,
live reload after add/remove. No console errors.
**Known nit (parked):** the delete confirm shows a literal `{name}`
placeholder.

### A4 — Tokens tab: issue → plaintext-once → revoke *(Pro only)*
1. Open **Tokens** (empty table fine). **Issue token** → slideout.
2. The **user picker opens** (element-select modal — regression guard:
   the slideout response carries `{html, headHtml, bodyHtml}`; a dead
   button means the body delta was dropped). Choose `michtio`, name it,
   **Issue**.
3. **Capture the plaintext** (needed for Section D). Confirm the
   shown-once warning.
4. Row appears with masked `tokenPrefix` only; reload and confirm the
   plaintext is not recoverable from `tokens-table-data`.
5. After Section D: revoke → row gone; the revoked token gets **401** on
   `/cortex/mcp`.

**Expected:** plaintext exactly once; no plaintext at rest or in any list
payload; revoke immediate and effective on the wire.

### A5 — Activity tab: rows, filters, sort, redacted detail *(Pro only)*
> Prereq: run Section D first so audit rows exist (include one `config`
> `mode: db` call and one bad-arg error for variety).
1. Open **Activity**: rows render with `tool / mode` trigger buttons,
   kind pills (SUCCESS / TOOL_ERROR), user links, durations.
2. Filter by tool: the request carries `filters[toolName]=…` and the
   table narrows **server-side**. Repeat for kind.
3. Click a column header: the request carries `sort[0][field]=…` and the
   order changes server-side.
4. Click a row → detail slideout: metadata grid (tool, mode, kind, user,
   duration, transport, client, session, request id, rate-limit
   remaining, when) + **redacted** request/response.
5. **Redaction check:** the `config mode:db` row must show no credentials
   — expect omitted user/password keys and `"unixSocket": "<set>"`-style
   markers. A truncated response excerpt must render as raw text, never
   500.

**Expected:** all server-side, no console errors, nothing unredacted.

---

## Section B — OAuth & discovery *(Pro)*

### B1 — `.well-known` discovery documents
GET both `…/.well-known/oauth-authorization-server` and
`…/oauth-protected-resource`.

**Expected:** valid RFC 8414 / RFC 9728 JSON; `scopes_supported`
`["read","write"]`; `code_challenge_methods_supported` **exactly
`["S256"]`** (no `plain`); registration/token/revocation endpoints named.

### B2 — OAuth consent screen renders
1. Register a DCR client:
   ```bash
   curl -sk -X POST https://plugin-playground-v5.ddev.site/oauth/register \
     -H 'Content-Type: application/json' \
     -d '{"client_name":"Smoke Client","redirect_uris":["https://plugin-playground-v5.ddev.site/admin"],"token_endpoint_auth_method":"none"}'
   ```
2. Generate a PKCE pair (any 43+ char verifier, SHA-256 → base64url).
3. While logged into the CP, open
   `…/oauth/authorize?response_type=code&client_id=<id>&redirect_uri=https://plugin-playground-v5.ddev.site/admin&code_challenge=<S256>&code_challenge_method=S256&scope=read&state=smoke`.

**Expected:** consent screen renders (site request, CP-mode template —
regression guard for the `TemplateLoaderException`), names the client,
shows the scope + description, Authorize/Deny, signed-in username.

### B3 — Consent-screen XSS regression *(HIGH VALUE)*
1. Register a second client with
   `"client_name":"<script>alert(1)</script>"`.
2. Open the authorize URL for it; snapshot + `list_console_messages`.

**Expected:** the name renders as the **literal escaped text** — no
dialog fires, zero inline `<script>` in the body, console silent.
**Measure:** record "no dialog / no script execution" explicitly +
screenshot.

---

## Section C — MCP protocol surface (Inspector / stdio)

Inspector stdio transport: command `docker`, args
`exec -i ddev-plugin-playground-v5-web php /var/www/html/cms/craft cortex/serve`.
The raw-stdio batch in §0 answers the same questions when no browser is
available.

### C1 — initialize handshake *(Free + Pro)*
**Expected:** `protocolVersion: "2025-11-25"`, `serverInfo: cortex 5.0.0`.

### C2 — list counts *(both editions — they differ)*
**Expected (stdio):** **Free → 33 / 10 / 98** (tools/prompts/resources);
**Pro → 42 / 10 / 98**. `entry`, `users`, `bulk_entries`, `skill` absent
on Free, present on Pro. `craft_exec` present on stdio in both editions.
(HTTP `tools/list` differs — see D5.)

### C3 — tool calls against the fixtures *(Free covers these)*
1. `get_initial_context` → craft block, **`cortex.edition` matching the
   current flip**, sites/sections/elementTypes, 10 `skillPrompts`, exec
   posture, allowlist, hints.
2. `entries {"section":"heroes","search":"Thor","limit":1}` → relational
   fields stub to `{type:"relation","loaded":false}` **under `fields`**.
3. `entries {"limit":1,"count":true}` → `{count: N}`, no rows.
4. `search_skills {"query":"element save lifecycle"}` → ranked results
   with `snippet`, `craft-skills://` URIs, `source`.
5. `sections` / `fields` → listings.
6. `config {"mode":"db"}` → **no credentials** (omitted keys / `<set>`
   markers).
7. Deliberate bad arg (e.g. `config {}` — missing mode) → `isError: true`
   envelope, NOT a protocol error.

### C4 — `craft_exec` gates *(Free; stdio-only)*
1. `{"expression":"1 + 1"}` → dry-run: `evaluated:false` + confirm hint.
2. `+ "confirm":true` → `result: 2`.
3. Destructive expression (`…deleteElementById(999999)`) with
   `confirm:true` → `blocked:true`, demands `dangerous:true` as well.

### C5 — prompts/get + resources/read *(Free)*
1. `prompts/get craftcms_extending` → verbatim SKILL.md content.
2. `resources/read craft-skills://craftcms/elements` → the authored doc.

---

## Section D — HTTP transport on the wire (curl) *(Pro)*

Base `https://plugin-playground-v5.ddev.site/cortex/mcp`; bearer = the A4
plaintext.

### D1 — initialize + session
POST initialize with `MCP-Protocol-Version: 2025-11-25` →
**200**, echo `2025-11-25`, `Mcp-Session-Id` response header. Keep the
session id.

### D2 — version negotiation
Header+body `2025-06-18` → 200 echo `2025-06-18`. Header `2024-01-01` →
**400** naming the supported versions.

### D3 — auth gates
- No `Authorization` → **401** + `WWW-Authenticate: Bearer realm="cortex",
  resource_metadata=…`.
- Garbage token → **401**.
- `Origin: https://evil.test` with a valid token → warn-and-allow in dev
  (empty allowlist + devMode); set a non-empty `allowedOrigins` to see the
  403 if you want the strict path.

### D4 — `craft_exec` is rejected over HTTP *(security boundary)*
`tools/call craft_exec` with a valid session →
JSON-RPC error `-32601`, message "stdio-only and cannot be invoked over
the HTTP transport". HTTP status stays 200 (it's an RPC-level error).

### D5 — write tools listed, stdio-only filtered, calls audited
`tools/list` over HTTP → **41 tools**: all nine write tools present,
**`craft_exec` absent** (stdio-only tools are filtered from the HTTP
list; the D4 call-time reject stays the security boundary). Call
`get_initial_context` and `config {"mode":"db"}` over HTTP → 200; these
become the A5 audit rows (user, clientName, redacted args).

---

## Section E — edition enforcement on the wire

### E1 — Free locks every Pro surface *(Free)*
With the playground on Free:

```bash
# transport — expect 403 + "requires the Cortex Pro edition"
curl -sk -w '\n%{http_code}\n' -X POST https://plugin-playground-v5.ddev.site/cortex/mcp \
  -H 'Authorization: Bearer anything' -H 'MCP-Protocol-Version: 2025-11-25' \
  -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25","clientInfo":{"name":"probe","version":"1"}}}'
# discovery + DCR — expect 403 each
curl -sk -o /dev/null -w '%{http_code}\n' https://plugin-playground-v5.ddev.site/.well-known/oauth-authorization-server
curl -sk -o /dev/null -w '%{http_code}\n' https://plugin-playground-v5.ddev.site/.well-known/oauth-protected-resource
curl -sk -o /dev/null -w '%{http_code}\n' -X POST https://plugin-playground-v5.ddev.site/oauth/register \
  -H 'Content-Type: application/json' -d '{"client_name":"probe","redirect_uris":["https://example.test"]}'
```

And with the authenticated CP session: `GET /admin/cortex/tokens`,
`/admin/cortex/activity`, `/admin/cortex/connection` → **403** each;
`POST …/cortex/settings/issue-token` → **403**; `GET
/admin/cortex/allowlist` → **200** (Free surface intact).

**Expected:** every Pro surface answers 403; the Free stdio surface is
untouched (C2 Free counts unchanged); `get_initial_context` reports
`cortex.edition: "free"`.

### E2 — Pro unlocks them *(Pro)*
Flip to Pro and spot-check the positive path: five tabs (A1), token
issuance + HTTP initialize 200 (A4/D1), discovery 200 (B1),
`cortex.edition: "pro"`.

### E3 — kill switch outranks the edition gate *(either edition)*
With `httpEnabled=false`, `/cortex/mcp` answers **503** (not 403) on both
editions — config-off beats not-licensed.

---

## Results — fill in

| # | Case | Edition | Result | Console/Network clean? | Evidence | Notes |
|---|---|---|---|---|---|---|
| A1 | Nav + tab gating | Free/Pro | | | | |
| A2 | Settings save | Free/Pro | | | | |
| A3 | Allowlist table+slideout | Free/Pro | | | | |
| A4 | Tokens issue/plaintext-once/revoke | Pro | | | | |
| A5 | Activity rows/filters/sort/redaction | Pro | | | | |
| B1 | .well-known JSON (S256-only) | Pro | | | | |
| B2 | Consent renders | Pro | | | | |
| B3 | **Consent XSS escaped (no dialog)** | Pro | | | | |
| C1 | initialize 2025-11-25 | Free/Pro | | | | |
| C2 | stdio counts (33/10/98 · 42 Pro) | both | | | | |
| C3 | tool calls + redaction + relation-stub + cortex.edition | Free | | | | |
| C4 | craft_exec gates | Free | | | | |
| C5 | prompts/get + resources/read | Free | | | | |
| D1 | initialize over HTTP + session | Pro | | | | |
| D2 | version negotiation (echo / 400) | Pro | | | | |
| D3 | auth gates (401) | Pro | | | | |
| D4 | **craft_exec rejected over HTTP** | Pro | | | | |
| D5 | 41 HTTP tools (write listed, stdio-only filtered) + audited | Pro | | | | |
| E1 | **Free locks every Pro surface (403)** | Free | | | | |
| E2 | Pro unlocks them | Pro | | | | |
| E3 | kill switch outranks edition (503) | either | | | | |

**Go / no-go:** all cases PASS, no unredacted secrets, B3 + D4 + E1
confirmed by hand. Finish with: edition restored to **Free**, full Pest +
PHPStan + ECS green, evidence archived under
`docs/smoke-evidence/<date>-results.md`.

> Previous runs: [2026-06-10/11](smoke-evidence/2026-06-11-results.md)
> (pre-submission run — found and fixed six CP/OAuth bugs plus the
> edition-enforcement gap);
> [2026-06-11 Section E rerun](smoke-evidence/2026-06-11-section-e-rerun.md)
> (edition enforcement re-confirmed against the packaging commit — GO).
