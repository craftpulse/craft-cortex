# Smoke rerun — Section E only (edition enforcement on the wire)

**Date:** 2026-06-11 (afternoon, same day as the full run)
**Commit under test:** `f671528` (Plugin Store packaging assets: LICENSE.md, src/icon.svg, extra.changelogUrl, CHANGELOG heading fix)
**Scope:** Section E of [SMOKE-TEST.md](../SMOKE-TEST.md) re-run after the packaging commit, per the runbook's "re-run after any CP/transport/edition change" rule. Sections A–D were not re-run (no code paths touched by the packaging commit); E2's positive path incidentally re-covered A1-Pro, the A4 issue/revoke flow, and D1.
**Driver:** curl + raw stdio for the wire; Chrome DevTools MCP for the CP (logged in as a throwaway `smoke-e` admin — created via `craft users/create`, hard-deleted after the run).

## Results

| # | Case | Edition | Result | Console/Network clean? | Evidence | Notes |
|---|---|---|---|---|---|---|
| E1 | **Free locks every Pro surface (403)** | Free | **PASS** | yes | `smoke-e1-free-tabs.png` | Transport 403 `{"error":"The HTTP transport requires the Cortex Pro edition."}`; both `.well-known` docs 403; DCR 403. CP session: `GET tokens/activity/connection` → 403 each, `POST issue-token` → 403 ForbiddenHttpException "This feature requires the Cortex Pro edition.", `GET allowlist` → 200. Tab bar = Settings + Allowlist only. stdio unchanged: 33/10/98, `cortex.edition: "free"`. |
| E2 | Pro unlocks them | Pro | **PASS** | yes | `smoke-e2-pro-tabs.png` | Five tabs render. Discovery 200 (RFC 8414 JSON, `S256`-only). Token issued via slideout (user-picker regression guard OK, plaintext shown once); HTTP initialize → 200 + `Mcp-Session-Id`, echoes `2025-11-25`, `serverInfo cortex 5.0.0`. stdio 42 tools, `cortex.edition: "pro"`. Revoke: row gone live, then wire 401. |
| E3 | kill switch outranks edition (503) | both | **PASS** | yes | — | **Pro:** `httpEnabled=false` → `/cortex/mcp` 503 `{"error":"HTTP transport is disabled…"}`, homepage unaffected, across 3 flip/restore rounds. **Free:** same flip → 503, not 403 — config-off beats not-licensed, as specified. |

**Verdict: GO** — edition enforcement re-confirmed at `f671528`. Finish line met: edition restored to **Free** (`cortex/edition/show` → `is(pro): false`), `httpEnabled` restored to `true` (wire re-probe: Free 403), Pest 1149/0 + PHPStan L8 + ECS green after the run.

## Observations

- **One-time site-wide 500 after the first `httpEnabled` flip (not reproducible, judged environmental).** Immediately after the first `true→false` edit to `config/cortex.php`, every route (home, CP login, `/cortex/mcp`) returned a masked Craft bootstrap error (`UnknownMethodException: yii\web\Request::getIsSiteRequest()` — the signature of a failure before Craft's components register; the root exception never reached the log because the log dispatcher itself was the failure point). Reverting the edit restored the site instantly — but the identical flip then behaved correctly on **four** subsequent attempts (3 Pro rounds + 1 Free round), `php -l` confirmed the in-container file was valid during the broken window, and no Cortex frame appears anywhere in the trace. A separate pre-existing `cookieValidationKey must be configured` error storm in the playground log (12:38–13:03 local, hours before this run) shows this environment already produces no-env bootstrap failures independently. Conclusion: playground/DDEV anomaly, not a plugin defect. **Watch item:** if a config-file edit ever coincides with a persistent site-wide 500 again, capture `storage/logs` + FPM env immediately.
- **Config-file flips take ~2 s to bite** (PHP opcache revalidation): the first request after an edit can still serve the old value (observed as 200-then-503 on disable, and 403-then-503 on Free). Operationally fine — worth knowing when scripting the runbook.
- **Known nit re-confirmed:** the revoke confirm dialog still shows the literal `{name}` placeholder ("Are you sure you want to revoke the token "{name}"?"). Already parked from the first run.
- The CP session expired mid-run (elevated-session timeout while working on the wire); Craft's re-auth modal handled it cleanly — incidental coverage, not a finding.
- Cleanup performed: smoke token revoked (wire 401 verified), `smoke-e` admin hard-deleted, the full-suite's leaked empty-pattern `cortex_runtime_overrides` row soft-deleted (known pitfall §0.2).
