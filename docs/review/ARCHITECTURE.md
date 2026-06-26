# Cortex — Architecture & Design-Quality Review

This is the review the `REVIEW.md` architecture-coherence dimension did **not** deliver. That section verified the code honours its own *locked decisions* (registry pattern, transport-agnostic core, the three-method gating contract). This one asks the harder question: **are those decisions good, are the abstractions right-sized, and is this built well enough to keep building on?** It is grounded in a line-by-line read of the core spine, not inferred from the other dimensions.

**Status reminder:** nothing here is released. This evaluates a branch-resident, unreleased plugin.

---

## Verdict

**Yes — this is built well.** It is materially above the median Craft plugin: the separation of concerns is real (not aspirational), the security boundary is treated as a first-class design constraint rather than bolted on, and the PHPDoc/test discipline is genuinely unusual — the comments explain *why*, cite the decision that produced them, and record rejected alternatives. A new contributor could understand the design intent from the source alone.

The earlier "shipped, just needs a packaging punch-list" framing was wrong about *status*, not about *quality* — but it did paper over the fact that the foundation had never been independently stress-tested for design soundness. Having now done that across two passes: **the core *design* holds, but the second pass found real pre-GA blockers — they are concentrated in the security-critical surfaces (OAuth, the audit sink, the stdio framing edges), not in the core architecture.**

The distinction matters. The load-bearing design decisions — transport-agnostic dispatch, boot-once registry, three-method gating, attribute-driven stdio-only enforcement — are right and cleanly built. What the deep dives exposed is a consistent pattern: **the happy paths are excellent and well-tested; the adversarial and edge paths are where the gaps cluster** — a soft-deleted handle reused, a secret echoed into an unredacted audit sink, a kill switch that covers only one of three HTTP controllers, an unauthenticated DCR endpoint with no throttle, an unbounded stdin read, CSRF on a consent POST. That is precisely the class of defect a coherence-only review and a 1,005-test *happy-path* suite both miss. So: the base is sound to build on, but **the blockers below must close before GA — they are not optional polish.** Revised tally from the full review: **4 blockers, ~10 majors** beyond what `REVIEW.md` originally carried (see *Severity reconciliation* below).

Scope: this review deeply read the **core spine** — the tool model (`ToolInterface`, `AbstractTool`, `ProToolTrait`, `PermissionedToolTrait`), the registry (`services/Tools.php`), the transport boundary (`mcp/Server.php`, `controllers/McpController.php`), edition gating (`Cortex.php`), the streaming bridge (`tools/support/FiberProgressBridge.php`) — **and** the second-pass feature deep-dives below: the OAuth 2.1 layer, the `Skill` element type, the core services, and the stdio `ServeController`.

---

## What is genuinely strong (with evidence)

1. **The transport-agnostic core is real, not a slogan.** `Server` (`src/mcp/Server.php:24`) is explicitly transport-blind; it receives transport context through narrow setters (`setUserId`, `setTokenId`, `setSessionId`, `setRateLimitRemaining`) and never reaches for `$_SERVER` or a request object. Every HTTP-specific concern — bearer/OAuth auth, rate-limit consume, session affinity, SSE framing, TCP-disconnect cooperation — lives in `McpController`. The same `dispatch()` serves stdio and HTTP unchanged. This is the single most important architectural decision in the plugin and it was executed cleanly.

2. **The security boundary is enforced at the right layer.** `craft_exec` stdio-only rejection happens at the dispatcher boundary (`Server.php:807`, keyed off the `#[IsStdioOnly]` attribute) — not inside the tool, not in the controller. Per-user filtering (`filterFor`) and schema rewriting (`inputSchemaFor`) are consulted on every `tools/list`/`tools/call` (`Server.php:585,795`), and `execute()` re-checks permission regardless (`PermissionedToolTrait::_assertPermission`, `:82`) — defence in depth, both layers fail closed. This is the correct shape for an LLM-facing surface.

3. **The HTTP controller is mature, RFC-literate work.** `McpController::beforeAction` (`:162`) runs an explicit, ordered gate sequence (kill-switch → Origin → method → protocol-version → bearer → rate-limit → parent), each step documented with the RFC it satisfies (6750, 9728, 8707 confused-deputy defence). The TCP-disconnect handling (`_streamPost`, `:673`) — `ignore_user_abort(true)` + `connection_aborted()` poll + *drain-don't-break* so the audit row still writes — is the kind of detail most plugins get wrong. It's right here.

4. **The registry is boot-once, collision-safe, and extensible.** `Tools::init` (`services/Tools.php:104`) fires `EVENT_REGISTER_TOOLS`, applies `shouldRegister()` edition/settings gating (`:116`), and uses **first-registration-wins** collision handling with a logged diagnostic (`:123-129`) so a third-party tool can never silently shadow a bundled one. No per-request rebuild. This is the right cost model for the HTTP path.

5. **The gating contract is well-factored.** The static-`shouldRegister` / instance-`filterFor` / instance-`inputSchemaFor` split (`ToolInterface:93,120,145`) cleanly separates the three real concerns — install-wide edition gating, per-user visibility, per-user schema shape — and `AbstractTool` gives every one a sensible default so the common tool stays a few lines. The `ProToolTrait` / `PermissionedToolTrait` extraction (after the "Rule of 3+", `PermissionedToolTrait:13`) is disciplined: traits layer behaviour over `AbstractTool` without a parallel `AbstractProTool` hierarchy, which PHP's single inheritance would otherwise force.

---

## Design tensions & critiques (severity-ordered)

### Major (design) — The Fiber bridge's generality is unproven and its safety is service-specific

`FiberProgressBridge` (`src/tools/support/FiberProgressBridge.php`) is the most ambitious piece of engineering in the plugin, and the place where ambition most exceeds proven need.

- **One consumer.** It is a 227-line, fully generic "stream-around-any-blocking-Craft-call" abstraction (`:30` markets it as reusable for export, search reindex, transform regen, etc.), but it is consumed by **exactly one tool today** (`resave`). That is textbook speculative generality — the abstraction was designed for a future that hasn't arrived, and its generality is therefore untested.
- **Its central safety property is per-service and unpoliced.** The cancellation contract (`:36-46`) requires the caller to pass an exception type the *wrapped service's own catch block* recognises (`QueryAbortedException` for `resaveElements`). The class explicitly "does not police the choice" (`:46`). So the seam's correctness depends on a property no type or test can enforce at the seam — every future consumer must independently verify its service's internal catch semantics or the cancellation silently breaks.
- **A real edge in the cancel path.** After `$fiber->throw($this->_cancelException)` the loop `break`s immediately (`:207-208`) without checking whether the fiber actually terminated. For `resaveElements` this is fine — its catch breaks the loop and the fiber returns. But for any service whose catch path fires another event (and thus `Fiber::suspend()`s again), the fiber is abandoned *suspended*, its `finally`/`Event::off` (`:188-189`) never runs, and the class-level event listener **leaks** for the rest of the process. The "reusable for free" framing (`:30`) is exactly what makes this dangerous: the safety only holds for the one service it was built against.

**Recommendation:** either (a) re-scope the class as `ResaveProgressBridge`, private to the one tool, and drop the generality claims from the docblock; or (b) keep it generic but harden the cancel path (after `throw`, keep resuming until `isTerminated()` while discarding frames, so `finally` always runs) and add a guard/test that fails loudly if a consumer's cancel-exception isn't caught by its service. Today's real risk is low (one well-behaved consumer); the risk is in the *invitation to reuse*. **Effort: M.**

### Major (doc/code contradiction) — Bearer/OAuth fallback: the contract says one thing, the code does another

`McpController::beforeAction` docblock (`:239-242`) states the lookup will "fall back to bearer when the OAuth lookup misses." `_resolveBearer` (`:819-846`) does **not**: when the token contains a dot it probes OAuth and `return null` on a miss (`:826-828`) — it never tries the bearer table. Real-world impact is low (long-lived bearers are 64-char hex with no dots, so they never take the OAuth branch), but the **locked contract and the implementation disagree**, which is precisely the kind of drift that becomes a security bug when someone later changes the token format. **Fix:** make the code match the contract (fall through to the bearer lookup on OAuth miss) or correct the docblock to state OAuth-dotted tokens never fall back. **Effort: S.**

### Minor — The streaming contract is not expressed in the type system

`ToolInterface::execute(): array|\Generator` (`:166`) carries streaming as a return-type union, but the contract around it is convention, not type:

- The terminal value can arrive **two ways** — "as the final yield, or via `Generator::return`, whichever pattern the tool prefers" (`:152-156`). Two ways to do the same thing across ~5 streaming tools is a consistency liability; a single locked pattern would be safer.
- The locked decision "`execute()` drains `stream()`" (`.session-handoff.md` decision 3) implies a second method, `stream()`, that **does not appear on the interface at all**. The streaming half of the contract lives entirely in convention and the canonical test (`BulkEntriesTest`). A reader of `ToolInterface` cannot discover it.

**Recommendation:** either formalise a `StreamingToolInterface extends ToolInterface { public function stream(...): Generator; }` that streaming tools implement, or document the `execute()`/`stream()` relationship directly in `ToolInterface`. Lock the single terminal-value pattern. **Effort: M.**

### Minor — `shouldRegister()` reads mutable global singleton state

`ProToolTrait::shouldRegister()` (`:77-80`) calls `Cortex::getInstance()->is(EDITION_PRO, '>=')` — a static method reaching into a mutable global singleton. This is why the tests need the `muteEvents` + edition-flip gymnastics documented at length in the same file (`:43-58`) and the `cortex_with_pro_registry()` helper. It works in production (edition never flips mid-process) but it makes the boot path globally-stateful and the unit under test impure. It's a defensible mirror of Craft's own static component contracts, but it's the root cause of the test friction and worth naming as a coupling smell rather than treating the test gymnastics as inherent. **Effort: n/a (accept, or inject edition) — note only.**

### Minor — Origin allowlist fails open

`McpController::_passesOrigin` (`:369-393`) treats an **empty** `allowedOrigins` as permissive (allow-all) with only a `Craft::warning` (`:373-380`), even when `httpEnabled=true`. For a DNS-rebinding defence, the safe default is fail-closed outside `devMode`. (Also captured in `REVIEW.md` security findings.) **Fix:** require a non-empty allowlist (or an explicit `allowAnyOrigin` opt-in) when `httpEnabled=true` outside dev. **Effort: S.**

### Nit — Stale `-32002` reference inside the source

`PermissionedToolTrait.php:63` docblock still describes throwing "the Gate 8 standard JSON-RPC `-32002` envelope shape" — but locked decision 2 removed numeric `-32002` codes from tool-error envelopes entirely; the method throws a plain `ToolException`. The comment now lies about the wire shape. (Same class of drift as the `CraftCommand` `-32002` nit in `REVIEW.md`.) **Fix:** delete the `-32002` clause. **Effort: S.**

### Nit — Static/instance duality adds cognitive load

Five `ToolInterface` methods are static (`getName`, `getDescription`, `getInputSchema`, `outputSchema`, `shouldRegister`) and three are instance (`filterFor`, `inputSchemaFor`, `execute`), with `inputSchemaFor` (instance) delegating to `static::getInputSchema()` (`AbstractTool:113-116`). Tools are described as "stateless, registered once" yet are instantiated and stored as instances in the registry. The split is intentional and mirrors Craft, but it's a real bit of friction for a contributor reasoning about when a method can see per-request state. Documented well; flagged only as a comprehension cost, not a defect.

---

## Is the foundation sound enough to build on?

Yes. The load-bearing decisions — transport-agnostic dispatch, boot-once registry with an extension event, three-method gating with defence-in-depth `execute()` re-checks, attribute-driven stdio-only enforcement — are the *right* decisions, cleanly implemented, and well-tested against their own contracts. None of the critiques above is foundational: the Fiber bridge is one re-scope or one hardening commit away from safe; the doc/code contradictions and stale comments are cheap; the gating-state coupling is an accepted Craft-idiom trade-off.

The hardening punch-list in `REVIEW.md` can proceed on this base — the *design* foundation is not cracked. But the second pass (below) found four blockers that must close before GA; they are implementation gaps on the security-critical surfaces, not flaws in the core architecture.

---

## Second pass — feature deep-dives

Each section was a dedicated read-only deep review. Findings are severity-ordered with `file:line`. Where an agent raised a question the agents couldn't answer (the DDEV-container `vendor/` wasn't host-readable), it was independently verified against the container and the result is folded in.

### OAuth 2.1 layer

**Verdict:** genuinely well-built — better than most plugin OAuth implementations. The right foundational call (delegate crypto to `league/oauth2-server`), with the MCP-specific extensions league doesn't cover implemented thoughtfully: RFC 8707 audience binding via a custom `aud`/`cid` claim split, RFC 9728/8414 metadata, RFC 7591 DCR with HTTPS-or-loopback redirect validation. Key material handled correctly (RSA keypair on disk at 0600, never committed; encryption key derived from Craft's `securityKey` via HMAC; plaintext tokens never persisted — only SHA-256 of the `jti`/opaque id). **Verified:** PKCE is genuinely mandatory for public clients — league 9.x defaults `requireCodeChallengeForPublicClients = true` (`vendor/league/oauth2-server/src/Grant/AuthCodeGrant.php:54`) and cortex never calls `disableRequireCodeChallengeForPublicClients()`; the controller additionally rejects `plain` at `OauthController.php:127`. It is **not safe to ship as-is** for two boundary/availability reasons — neither a crypto flaw.

**Strengths:** audience binding correct end-to-end (`AccessTokenEntity.php:147` issues `permittedFor($audience)`; `McpController.php:834` rejects mismatched `aud` — confused-deputy defence done properly); plaintext never stored (`AccessTokenRepository.php:94`); key bootstrap sound (`--force`-guarded rotation with an honest in-flight-invalidation warning); DCR redirect validation strict (`Oauth.php:720`, HTTPS-only except loopback); confidential-client secret check fails closed. **Verified resolved:** `m260515_080000_cortex_oauth_client_fks.php` *does* add the `clientId` CASCADE FKs the plan called for (with orphan-purge first) — deleting a client atomically revokes its codes/tokens. The original "logical-FK-only" nit is closed.

- **Blocker — OAuth and `.well-known` endpoints ignore the `httpEnabled` kill switch.** `OauthController` and `WellKnownController` have no `httpEnabled` check at any layer, while `PluginTrait.php:258-260` comments that "the controller refuses every request when `httpEnabled` is false" — true *only* for `McpController` (`:183`). On a default install (`httpEnabled=false`), `/oauth/authorize|token|register|revoke` and both `.well-known` documents are fully live. The kill switch operators trust to gate the HTTP surface covers one of three controllers. **Fix:** shared `beforeAction()` (trait or `AbstractOauthController`) returning 503 when `!httpEnabled`, on both controllers. **Effort: S.**
- **Blocker — `/oauth/register|token|revoke` are unauthenticated and unthrottled, with no cleanup behind them.** The rate limiter runs only in `McpController::beforeAction` keyed by *authenticated* user id; the OAuth endpoints are anonymous by design, so no throttle applies. `actionRegister` (`OauthController.php:191` → `Oauth.php:373`) unconditionally INSERTs a `cortex_oauth_clients` row (a bcrypt cost per request) with `dcrEnabled=true` by default. **Verified:** `gate-7.md` scopes rate-limiting to authenticated users only — anonymous/IP throttling was never designed, so this is a genuine gap, not a documented deferral. Combined with the kill-switch blocker and the no-prune major below, this is a complete anonymous DoS primitive (unbounded rows + bcrypt CPU). **Fix:** gate behind `httpEnabled`; add an anonymous IP-keyed token bucket (`getUserIP()` + the existing PSR-16 bucket) on register/token/revoke; consider a per-IP live-client cap. **Effort: M.**
- **Major — Expired/revoked OAuth codes and tokens are never pruned; the docblock claims they are.** The `Gc::EVENT_RUN` listener prunes only `cortex_invocations` and `cortex_runtime_overrides` (`PluginTrait.php:119`, verified). `cortex_oauth_codes` (5-min TTL, one row/authorize) and `cortex_oauth_tokens` grow forever, yet `AuthCodeRepository.php:23` states "the gc sweep prunes expired rows" — there is no such sweep. The false docblock masks the gap from a reviewer trusting the comment. **Fix:** extend the gc listener to delete expired codes and expired/revoked tokens (with a grace window exceeding the refresh TTL so rotation chains aren't severed); correct the docblock. **Effort: S.**
- **Major — `actionAuthorize` POST disables CSRF while relying on a same-site session for consent.** `$enableCsrfValidation = false` is global on the controller (`OauthController.php:77`); the consent POST (`:143`) is the one action that is a session-backed form ("approve this client for *your* account"), not a token-proof exchange. The template embeds a CSRF field (`authorize.twig:101`) that is never validated. A cross-site auto-submitting `approve=1` against a logged-in admin could mint an auth code for an attacker's client; PKCE protects the code→token exchange but not the consent grant itself. **Fix:** enable CSRF validation for the authorize POST specifically (the hidden field already round-trips); keep it off for token/register/revoke. *Confirm league's `validateAuthorizationRequest` binds to query params, not session — it does, so the consent decision is the unprotected step.* **Effort: S–M.**
- **Major — No test asserts the kill switch or anonymous throttle on the OAuth surface.** The suite covers the `dcrEnabled` 404 path (a gate that *exists*) but not "endpoints 503 when `httpEnabled` is off" or DCR spam — false confidence: the tested gate exists, the untested one doesn't. Whatever fix lands for the blockers must ship with these tests. **Effort: S.**
- **Minor — tokens issued without `resource=` are silently unusable** (`aud` falls back to client id → rejected at every MCP call). Correct per spec (fail closed) but a silent 401; add a `Craft::info` on audience-mismatch to save integrators debugging time. **Effort: S.**

### Skill element type

**Verdict:** well-executed. The bundled-vs-element coexistence — the hard part — is correct, deterministic, and documented to the locked-decision level; the `source` provenance threads cleanly through `getMergedCorpus` → `SearchSkills` → the `skill` tool; authorization is layered properly (`filterFor` + `_assertPermission` + per-element `canSave/canDelete`). `craftcms` conventions (`addSelect` in `beforePrepare`, `site('*')`, `MemoizableArray` reset on every lifecycle event) are observed. One real data-integrity blocker and a handle-hygiene gap.

- **Blocker — recreating a soft-deleted skill's handle throws a DB integrity violation mid-save.** `validateHandleUnique` (`Skill.php:416-439`) queries with the default `trashed=false`, so it doesn't see soft-deleted skills, but the `UNIQUE(handle)` index still holds the trashed row. `create` passes model validation, `saveElement()` persists the element + `elements_sites` rows, then `afterSave` (`:282-298`) does `$record->save(false)` → `IntegrityException` — leaving a half-saved element (element row exists, no `cortex_skills` row) and no clean envelope. Happy-path tests miss it because soft-delete tests stop at "row still present" and never reuse the handle. **Fix:** probe the trashed slot in `validateHandleUnique` (`->trashed(null)`) and return the restore-or-hard-delete hint the *update* path already emits (`Skill.php:626-635`); failing closed in the validator also removes the only realistic `save(false)` failure path. **Effort: S.**
- **Major — no handle-format validation corrupts the `craft-skills://` URI space.** `defineRules` (`Skill.php:393-401`) validates handle as `required` + `string max 255` + unique only — no charset constraint. A handle like `"foo bar/baz"` is accepted, then interpolated into `craft-skills://foo bar/baz` (`Skills.php:472`) and `name:` frontmatter (`:315`), breaking URI addressability *and* the bundled-override contract (bundled handles are slug-shaped `[a-z0-9-]`, so a non-slug handle can never actually override a bundled skill even when intended). **Fix:** add `craft\validators\HandleValidator` or a slug regex to `defineRules()`. **Effort: S.**
- **Major — Gate 9.6 CP authoring will bypass the tool's handle-immutability invariant.** The `skill` tool refuses handle changes on update (`Skill.php:494-500`, the natural-key invariant), but Gate 9.6 routes CP saves through Craft's built-in `elements/save`, and `handle` is a public property with no immutability rule — so the CP edit screen will let an operator change the handle freely, orphaning every cached `craft-skills://<old>` reference. The invariant lives at the tool layer only; it belongs on the element. **Fix:** enforce in `beforeSave()`/`defineRules()` — reject handle changes when `!$isNew`. Then both the tool and the CP screen inherit it. **Effort: S–M.**
- **Minor — `afterSave` record write is not transactional with the element save** (`Skill.php:282-303`); a `save(false)` failure leaves a row-less element invisible to `Skill::find()` but occupying an `elements`/slug slot. Fixing the blocker (up-front trashed validation) removes the only realistic trigger, but wrapping the record write in the element-save transaction is the durable fix. **Effort: S.**
- **Open decisions (cheap now, expensive post-release):** `isLocalized()=false` (no per-site skill bodies — fine for "skills are policy," but a multilingual agency wanting localized *guidance prose* would need a content migration to reverse; document in user-facing docs); `trackChanges()=false` + the Gate 9.6 full-page editor (no revision history / no undo on a hand-authored body — one bad save overwrites canonical guidance irreversibly; flipping `trackChanges` on later is cheap, the cost of *not* having it is borne until then); and **there is no `ElementExporter`/importer for skills** and `import_export` doesn't handle the type — authored skills are environment-local DB content that do **not** deploy via `craft up`, contradicting the original gate-8.5 "custom ElementExporter" intent. Decide explicitly whether cross-environment skill portability is in scope.

### Core services

**Verdict:** well-engineered and unusually well-documented — the soft-write audit contract, token hashing, CSPRNG session IDs, and an explicitly-acknowledged rate-limiter race are all handled with care. One blocker (the audit-redaction asymmetry, escalated from `REVIEW.md`'s major), everything else minor.

**Strengths:** the soft-write contract is airtight — `Invocations::record()` (`Invocations.php:117-133`) catches `Throwable`, logs under `cortex.audit`, returns null; a DB failure in the audit path cannot break the JSON-RPC response. Token hashing correct (plaintext from `random_bytes(32)`, only SHA-256 stored, `Tokens.php:90-97`; lookup hashes before the DB probe so `like`-injection is structurally impossible). Cache-reset discipline clean across every mutator. Token-swap defence fails closed to 401 (`Sessions.php:141-144`).

- **Blocker — `response_excerpt` persists unredacted tool output; `craft_command` stdout is a live secret vector.** `Server.php:751` (and the streaming twin `:944`) pass raw `json_encode($result)` to `logCall(..., $responsePayload)`; the *arguments* on the same row are redacted, but the response excerpt never is. `craft_command` is **HTTP-reachable** (only `craft_exec` carries `#[IsStdioOnly]`) and returns raw console stdout (`CraftCommand.php:189`); default allowlist entries `mailer/test` and `utils/*` can echo transport/config secrets to stdout → persisted verbatim in `cortex_invocations`, retained forever by default (`auditRetentionDays=null`). The redacted args make the gap easy to miss. This escalates `REVIEW.md`'s "audit-excerpt redaction" finding from **major to blocker**. **Fix:** redact the result before encoding the excerpt at both sites (`SecretRedactor::redactArray($result)`, or a `redactJson()` helper for uniformity); belt-and-suspenders, redact `craft_command`'s `output` at the tool layer as `craft_exec` already does. **Effort: M.**
- **Minor — no session enumeration** (`Sessions` exposes only create/get/touch/terminate). PSR-16 has no key-scan, so a future "currently streaming" view or global kill-switch can't be retrofitted from the cache — it needs a parallel index built now, or an explicit "operator disconnect is out of scope; only token revocation" doc. **Effort: M.**
- **Minor — long-running streams can evict mid-flight under sliding TTL.** TTL resets only on `touch()` (per-request, in `beforeAction`), not during a stream; a `tools/call` streaming longer than `sessionTtl` gets no refresh, so a sibling request mid-stream could miss the session. **Fix:** `touch()` at stream start, or document that `sessionTtl` must exceed max stream wall-clock. **Effort: S/M.**
- **Minor — `Invocations::prune()` double-binds the cutoff** with a hand-rolled `:cutoff` `Expression` where Yii would auto-parameterize (`Invocations.php:156-158`) — fragile if ever composed; let Yii bind it. **Effort: S.**
- **Nit — `RateLimiter::check()` and `consume()` diverge on persist** (`check()` walks the bucket but never persists); the docblock invites using `check()` as a "dry-run gate," which would re-walk the refill arc and inflate available tokens vs `consume()`. Tighten the docblock to "introspection only." **Effort: S.**

### stdio `ServeController`

**Verdict:** architecturally clean and correct in shape — genuinely newline-delimited framing per the MCP stdio spec, dispatcher properly transport-agnostic, one bad message can't kill the loop, trusted-local posture correctly confined to stdio. The standout call is the `StdoutCaptureFilter` stream filter around in-process `runAction()` dispatch — the right solution to the hardest stdio problem (console output corrupting the JSON-RPC channel). Real hardening gaps, none blocking for local dev.

**Strengths:** framing is spec-correct (`ServeController.php:52-84,102-111` — `fgets` to newline, single-line `json_encode` with `JSON_UNESCAPED_*` and no pretty-print, `fflush`); one bad message can't kill the loop (malformed JSON → `-32700` + `continue`; non-object → `-32600`; every `dispatch()` path internally caught); trusted-local posture cannot leak (stdio-only enforcement lives in the dispatcher keyed on `$_transport`, `Server.php:807`, so an HTTP-constructed Server rejects stdio-only tools regardless); generator consumption is wire-safe (`_consumeGenerator` drains intermediate yields, emits only the final value — no progress frames corrupt stdout).

- **Major — no clean shutdown / signal handling** (`ServeController.php:52-88`). The loop only exits on EOF; MCP clients tear down via SIGTERM, so the process dies mid-iteration with no flush, and a closed read-end makes `fwrite` spin instead of exiting. **Fix:** `pcntl_async_signals(true)` + SIGTERM/SIGINT handlers to break cleanly (guard behind `function_exists`); detect `fwrite() === false` and break. **Effort: M.**
- **Major — unbounded line read** (`ServeController.php:52`). `fgets($stdin)` with no length cap reads an entire line regardless of size; a buggy/flooding client OOM-kills the process. The project's own `craft_exec` docblock rejects "trusted user ⇒ trusted input" — the same skepticism should apply to the framing layer. **Fix:** bounded read + reassemble-until-newline, reject over a configurable cap with `-32600`. **Effort: M.**
- **Major — `_silenceLogs()` only caps levels and rests on a now-confirmed-real stdout vector.** `ServeController.php:121-131` lowers log levels and sets `flushInterval=1` but does **not** redirect output. **Verified:** Craft's `MonologTarget` *can* push a `StreamHandler` to `php://stdout` and `php://stderr` (`vendor/craftcms/cms/src/log/MonologTarget.php:195-207`), not only the file target — so if that handler is active during `cortex/serve`, error/warning records (exactly the ones most likely to fire on a failing call) corrupt the JSON-RPC stream, and capping levels keeps them. **Fix:** during the serve loop, *remove or re-point* any log target whose handler streams to stdout (to stderr/file) rather than lowering its level; drop the `flushInterval=1` perf cost if it's only justified by the unbounded-session memory worry. **Effort: S to confirm activation condition + S to fix.**
- **Major — no tests for the framing loop or the capture filter** — the two pieces whose regression silently corrupts every session are the untested ones (only dispatcher-level `ServerTest` exists). **Fix:** extract the loop body to a testable `_handleLine(string): ?string`; assert one-line-in/one-line-out, blank-line skip, `-32700`, notification → no output; add a `StdoutCaptureFilter` suppress/passthrough test. **Effort: M.**
- **Minor — `StdoutCaptureFilter`'s static buffer isn't re-entrancy-safe** (`StdoutCaptureFilter.php:44` + `ConsoleRunner.php:57-82`): a nested `ConsoleRunner::run()` would flip `$capturing=false` and clear the buffer mid-flight on the outer dispatch. Nothing nests today, but the invariant is unenforced. **Fix:** assert non-re-entrancy or save/restore prior state. **Effort: S.**
- **Open question — fatals.** A true PHP fatal mid-dispatch (e.g. from the unbounded read) bypasses every `try/catch`; if the STDERR capture filter is still attached the fatal is swallowed, and the client hangs with no `-32603`. A `register_shutdown_function` emitting a final error envelope would close the gap.

---

## Severity reconciliation with `REVIEW.md`

The deep dives escalate and extend `REVIEW.md`'s original counts (`0 blocker / 8 major / …`). Net new and escalated:

- **New blockers (4):** audit `response_excerpt` unredacted (escalated from major — HTTP-reachable `craft_command` is the live vector); OAuth/`.well-known` ignore `httpEnabled`; OAuth anonymous-DoS (unthrottled DCR + no prune); Skill trashed-handle recreate.
- **New majors (~10):** OAuth no-prune + false docblock; OAuth authorize-POST CSRF; OAuth missing kill-switch/throttle tests; Skill handle-format validation; Skill CP handle-immutability bypass; stdio no-signal-handling; stdio unbounded read; stdio `_silenceLogs` stdout vector; stdio missing framing/filter tests; (plus the spine's `FiberProgressBridge` generality + bearer/OAuth doc-code contradiction from the first pass).
- **Closed/verified-OK:** OAuth `clientId` FK CASCADE (present in `m260515_080000`); PKCE-required-for-public-clients (league default, not disabled).

`REVIEW.md`'s executive-summary counts should be updated to reflect these before the doc is used to gate submission. The order-of-work implication: the OAuth kill-switch + throttle + prune and the audit-redaction fix are now **submission blockers**, joining the punch-list's existing items.

---

## Remediation status (2026-05-31)

**All four blockers are fixed and independently verified** on branch `gate-9-hardening` (`pest` 1023 passed / 0 skipped / 11,989 assertions, PHPStan level 8 clean, ECS clean):

| Blocker | Commit |
|---|---|
| Audit `response_excerpt` + `craft_command` output unredacted | `dd65e99` |
| Skill trashed-handle recreate → `IntegrityException` | `8f75c38` |
| OAuth / `.well-known` ignore `httpEnabled` kill switch | `fc369ac` |
| Anonymous OAuth endpoints unthrottled | `a3e89eb` |
| OAuth codes/tokens never pruned (+ false docblock) | `626b55e` |

Implementation notes: the IP throttle was added as a parallel `RateLimiter::consumeKey(string)` (user-keyed `consume(int)` untouched); the kill switch is now enforced via a shared `AbstractOauthController` base; the gc prune deletes only already-dead rows (`expiresAt < now` OR `dateRevoked` set), so active refresh-rotation chains are never severed, and all OAuth repositories were confirmed fail-closed before pruning.

**Update (2026-06-09): the majors are now also fixed and verified** on `gate-9-hardening` (`pest` 1077 passed / 0 skipped, PHPStan L8 + ECS clean):

| Major | Commit(s) |
|---|---|
| OAuth consent POST CSRF | `49ba6cc` |
| Origin allowlist fails closed outside devMode | `9e94759` |
| Skill handle format validation + post-create immutability | `7d08868`, `735d73e` |
| Elevated-session: refuse password/email/admin over HTTP (real-transport context seam) | `5abb762`, `89bf872` |
| stdio hardening (bounded read, testable framing, fatal-handler envelope, SIGTERM/SIGINT + broken-pipe, stdout-log redirect, capture re-entrancy) | `13cf6d0`, `8a53a4e`, `26abc8e`, `2484cf9`, `56ce9a8` |
| `FiberProgressBridge` cancel-drain (listener-leak fix) | `8e766d1` |
| Doc majors: EXTENDING interface contract, CONFIGURATION shipped-state + `stdioMaxMessageBytes`, tool-count reconcile (33 Free), doc-drift comments | `26e26d5`, `8bef504`, `4b2cebe`, `0a414f0` |

The doc-drift nit (the `m260514` "logical FK" comment vs the hard `ON DELETE CASCADE` from `m260515_080000`) was fixed in `0a414f0`.

**The minor/nit tail is also closed** (`4524d7e`, `42d1143`, `cd7e0b6`):

| Item | Resolution |
|---|---|
| `Invocations::prune()` redundant param bind | Fixed — let Yii bind the cutoff (`4524d7e`) |
| `RateLimiter::check()` docblock overstates use | Fixed — docblock now says introspection-only, never a gate (`42d1143`) |
| Session enumeration / operator-disconnect | Documented out-of-scope — no enumerable surface by design; revocation = next-request block (`cd7e0b6`) |
| Sliding-TTL vs long streams | Verified safe — `sessionTtl` 3600s ≫ the minutes-bounded max stream; invariant documented (`cd7e0b6`) |
| Skill `afterSave` not transactional | **False positive** — verified against `craftcms/cms`: `afterSave()` already runs inside `Elements::_saveElementInternal()`'s transaction. No change. |
| `inputSchemaFor` HTTP field-hiding | **Deferred by design** — needs transport in the `tools/list` path; the execute-level refusal is already the security boundary. |

**Status: the full review set (blockers + majors + minor/nit tail) is remediated on `gate-9-hardening`.**
