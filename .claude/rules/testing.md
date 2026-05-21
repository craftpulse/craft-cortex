<!-- craftcms-claude-skills -->
# Testing

- Write tests alongside each layer, not after. Service tests with the service, controller tests with the controller, tool tests with the tool.
- Pest over PHPUnit. Use `ddev exec vendor/bin/pest` to run, never `vendor/bin/pest` on the host.
- `--filter=ClassName` for targeted runs during development. Full suite before committing.
- `->site('*')` and `->status(null)` in test queries to avoid false negatives from site/status scoping.
- `actingAs($user)->post()` for controller tests. Assert both HTTP status and DB state.
- Test edge cases: empty results, missing entities, expired elements, permission denials.
- Factories for element setup. Never rely on database seeding or fixture ordering.
- Multi-site: test propagation behavior when the plugin touches elements across sites.
- Queue jobs: dispatch and assert the job processed correctly, not just that it was pushed.
- `Craft::$app->getProjectConfig()->muteEvents = true` when tests modify project config directly.

## Cortex-specific

- **Tool tests run against both transports** when behavior could differ. Most tests use a synthetic transport that bypasses stdio/HTTP framing — but transport-specific concerns (auth, rate limits, `craft exec` rejection on HTTP) get dedicated transport-level tests.
- **Edition gating tests:** assert that Pro tools are absent from the Free registry and that loading them raises an explicit error, not a silent no-op.
- **Allowlist tests:** every command-running tool must have a test that proves a non-allowlisted command is rejected before reaching the console runner.
- **`craft exec` tests are stdio-only.** Add an HTTP transport test that asserts `craft exec` is rejected with a 403/forbidden response.
- **No live network in tests.** Mock external HTTP. The MCP server itself can be tested in-process.
- **PC-writing tests are SEQUENTIAL ONLY.** Any test that calls `Fields::saveField()`, `Users::saveLayout()`, or otherwise writes to project config — including via Craft service methods that internally trigger PC sync — must NOT run under parallel test execution. Concurrent reads of the same PC surface (e.g. user field layouts) would race the test's setUp/teardown window and assert on the wrong shape. The current Pest sequential default is safe; if Paratest is ever introduced, PC-writing tests need per-worker `project.yaml` isolation (Craft's test harness doesn't ship this — would require harness work first). Mark such tests with a `**SEQUENTIAL ONLY**` callout in the file's class-level docblock so the constraint is visible at the test file boundary.
- **Pro tools through the dispatcher**: use the `cortex_with_pro_registry()` helper at `tests/Pest.php` to flip edition to Pro AND rebuild the tool registry. `cortex_with_edition('pro', ...)` alone is not enough — `Tools::getByNameFor()` consults a registry built at boot via `shouldRegister()`, so a Pro tool is invisible to the dispatcher until the registry is rebuilt. Production never sees a mid-process edition flip; this helper is test-only.
