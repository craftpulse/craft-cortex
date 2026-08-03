<?php

/**
 * =========================================================================
 * Architecture tests — every registered tool is deliberately classified
 * on the authorization axis.
 *
 * Herald's authorization surface was guarded by two hand-maintained
 * lists (`Scopes::TOOL_SCOPES` and each tool's own `filterFor()`), and
 * nothing forced a new tool to appear on either. That is how
 * `craft_command` shipped with no gate at all: the tool was added, the
 * lists were not, and every existing test stayed green. The same shape
 * produced a documentation reference that silently described 33 tools
 * instead of the full surface, because the generator read the
 * edition-gated registry rather than the source's own registration.
 *
 * This file is the guard for that shape. It enumerates tools from the
 * registry's own unfiltered registration list rather than from a list
 * written here, so a tool added to `Tools::_buildRegistry()` is
 * classified automatically, and one that is never classified fails.
 *
 * # The classification
 *
 * Every registered tool must land in exactly one of four buckets:
 *
 *   1. **Gated.** An authorization gate is reachable from `execute()`
 *      (or `stream()`), proven by a token scan of the reflected method
 *      bodies. `filterFor()` is deliberately NOT accepted: it controls
 *      `tools/list` visibility, which is tool-selection UX for the
 *      model, and it is not enforcement. Phase 1 added
 *      `CraftCommand::_assertMayRunCommands()` precisely because
 *      list-filtering had been mistaken for a gate.
 *   2. **Transport-gated.** The tool carries `#[IsStdioOnly]`, so
 *      `Server::_validateToolCall()` refuses it over HTTP regardless of
 *      permissions, scope, elevation or settings. The only caller that
 *      can reach it already holds a shell and the `craft` console, which
 *      is the ruled position on stdio.
 *   3. **Reviewed read-only.** The tool appears on the allowlist in this
 *      file AND advertises `readOnlyHint: true` through its
 *      `#[IsReadOnly]` attribute. The attribute requirement is what
 *      stops the allowlist decaying into a place to park a mutating
 *      tool: adding a name is not enough, the tool has to actually be
 *      read-only.
 *   4. **Known ungated mutation.** An exact, named set of tools that
 *      mutate state and carry no gate today. It is asserted as an
 *      equality, not a membership, so it is a ratchet rather than an
 *      escape hatch: adding a tool to it fails the test, and so does
 *      removing one without updating the expectation.
 *
 * A new tool matches none of these until somebody classifies it, and
 * the failure names the tool and lists the four options. That failure is
 * the feature.
 *
 * # Why a token scan
 *
 * A behavioural assertion is preferred and is used where one exists —
 * `tests/Tools/Dev/CraftCommandAuthorizationTest.php` drives a
 * permissionless caller through `craft_command::execute()` and asserts
 * the refusal, and every gated tool's own test file covers its own
 * matrix. What cannot be done behaviourally is the *universal* claim:
 * each tool takes different arguments, needs different fixtures, and
 * half of them are legitimately allowed to succeed for the caller under
 * test. So the universal claim is structural, and the detector itself is
 * asserted behaviourally against a synthetic gated tool and a synthetic
 * ungated one, so a scan that silently stopped detecting anything fails
 * here rather than passing everything.
 *
 * # What this file does not claim
 *
 * The scan proves a gate is reachable on the dispatch path. It does not
 * prove every mode of a multi-mode tool is gated: `content_audit`,
 * `drafts_and_revisions`, `import_export` and `system_diagnostics` gate
 * their write modes and leave their read modes open by design, and
 * per-mode coverage belongs in each tool's own test file.
 *
 * Nor does it distinguish a permission gate from an elevation gate.
 * Elevation proves the caller re-authenticated; it does not prove they
 * are permitted to do the thing. A tool carrying only `_assertElevated()`
 * would therefore pass this classification while enforcing no
 * permission at all. No shipped tool is in that state — every
 * elevation-gated mode sits behind a permission gate as well — and
 * splitting the gate list in two is the fix if one ever appears.
 * =========================================================================
 *
 * @author CraftPulse
 * @since  5.0.0
 */

use craftpulse\herald\console\controllers\DocsController;
use craftpulse\herald\Herald;
use craftpulse\herald\services\Scopes;
use craftpulse\herald\tests\Architecture\Fixtures\GatedTool;
use craftpulse\herald\tests\Architecture\Fixtures\IndirectlyGatedTool;
use craftpulse\herald\tests\Architecture\Fixtures\ListFilteredOnlyTool;
use craftpulse\herald\tools\ElevationGatedToolTrait;
use craftpulse\herald\tools\PermissionedToolTrait;
use craftpulse\herald\tools\StreamableToolInterface;
use craftpulse\herald\tools\support\AttributeReader;
use craftpulse\herald\tools\ToolInterface;

// -----------------------------------------------------------------------------
// Classification
// -----------------------------------------------------------------------------

/**
 * Tools that carry no authorization gate because they read and never
 * write. Each entry is reviewed, and each is cross-checked against the
 * tool's own `#[IsReadOnly]` attribute by
 * `keeps every read-only-allowlisted tool mechanically read-only`, so a
 * name added here without the attribute fails.
 *
 * Reads are open on purpose. The HTTP transport is the security
 * boundary, and it is reached only with an OAuth grant carrying the
 * matching read scope (`schema:read`, `content:read`, `system:read`) or
 * an admin-issued bearer token. A read scope is the narrowest thing an
 * operator can hand out, and narrowing it further per tool would mean a
 * permission matrix for reading a section handle.
 *
 * @return string[]
 */
function herald_read_only_allowlist(): array
{
    return [
        // Schema introspection. Section, entry-type, field, volume, site
        // and transform definitions. Project-config shapes, no content.
        'sections',
        'entry_types',
        'fields',
        'field_types',
        'category_groups',
        'tag_groups',
        'volumes_and_filesystems',
        'sites',
        'image_transforms',
        'element_types',
        'database_schema',

        // Content reads. Element data behind `content:read`. The
        // per-element authorization Craft itself applies still runs
        // inside the element queries these tools issue.
        'entries',
        'assets',
        'categories',
        'tags',
        'globals',

        // System and diagnostic reads behind `system:read`. `config`
        // redacts secrets through `SecretRedactor` before returning, and
        // `permissions_and_groups` returns the permission tree and group
        // definitions, never per-user grants.
        'get_initial_context',
        'system_info',
        'config',
        'plugins',
        'routes',
        'extensibility',
        'permissions_and_groups',
        'search_skills',

        // GraphQL introspection and execution against Craft's own
        // schema, which applies its own scope rules per query.
        'graphql',
    ];
}

/**
 * Tools that mutate state, are reachable over HTTP, and carry no
 * authorization gate inside `execute()` today.
 *
 * Empty, and meant to stay empty. Asserted as an exact set, so this is a
 * ratchet in both directions: a new ungated mutating tool fails, and so
 * does gating one of these without deleting it from here. Neither
 * direction can happen quietly.
 *
 * It held `clear_caches` and `resave` for exactly one day. Both are
 * convenience wrappers over console routes `craft_command` also reaches
 * (`clear-caches/*`, `resave/*`), and `craft_command` has required
 * `CraftCommand::PERMISSION_RUN_COMMANDS` plus the `system:write` scope
 * since the 2026-08-02 remediation, so reaching the same work through
 * the wrapper required neither — a `system:read` token could resave every
 * element in the install, and `resave` advertised `#[IsDestructive]` from
 * a read scope. The 2026-08-03 remediation gated both:
 * `Resave::stream()` on `herald:run-commands` (the same handle, because
 * it is the same console route), `ClearCaches::execute()` on its own
 * narrower `herald:clear-caches`, and both onto `system:write`. See
 * `tests/Tools/Dev/DevActionAuthorizationTest.php` for the behavioural
 * coverage.
 *
 * @return string[]
 */
function herald_known_ungated_mutating_tools(): array
{
    return [];
}

// -----------------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------------

/**
 * Method names that count as an authorization gate when reachable from
 * a tool's `execute()` or `stream()`.
 *
 * The two shared traits contribute their own `_assert*` methods by
 * reflection, so renaming `PermissionedToolTrait::_assertPermission()`
 * or `ElevationGatedToolTrait::_assertElevated()` cannot silently empty
 * the detector. The rest are tool-local gates, named explicitly:
 * `_assertAdmin` is `tag`'s whole-tool admin gate, `_assertMayRunCommands`
 * is `craft_command`'s, and `bulk_entries` splits its gate into a
 * coarse and a per-target half.
 *
 * Adding a name here is a deliberate act. A tool inventing a gate under
 * a name this list does not carry fails the classification until
 * somebody either renames it to a known gate or reviews and records the
 * new one.
 *
 * @return string[]
 *
 * @throws ReflectionException if a trait disappears.
 */
function herald_authorization_gate_methods(): array
{
    $gates = [
        '_assertAdmin',
        '_assertMayRunCommands',
        '_assertCoarsePermission',
        '_assertTargetPermission',
    ];

    foreach ([PermissionedToolTrait::class, ElevationGatedToolTrait::class] as $trait) {
        foreach ((new ReflectionClass($trait))->getMethods() as $method) {
            if (str_starts_with($method->getName(), '_assert')) {
                $gates[] = $method->getName();
            }
        }
    }

    return array_values(array_unique($gates));
}

/**
 * Filtered PHP tokens for a file, memoized per run. Comments, docblocks
 * and whitespace are dropped so a gate named in a docblock cannot
 * satisfy the scan, and line numbers survive so a reflected method's own
 * range can bound the search.
 *
 * @return array<int,array{0:int,1:string,2:int}|string>
 */
function herald_file_tokens(string $file): array
{
    /** @var array<string,array<int,array{0:int,1:string,2:int}|string>> $cache */
    static $cache = [];

    if (!isset($cache[$file])) {
        $cache[$file] = array_values(array_filter(
            token_get_all((string) file_get_contents($file)),
            static fn(array|string $token): bool => !is_array($token)
                || !in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true),
        ));
    }

    return $cache[$file];
}

/**
 * Names of the methods `$method` invokes on `$this`.
 *
 * Bounded by the reflected method's own line range, so moving a method
 * within its file does not break the scan and a call elsewhere in the
 * file cannot satisfy it. A trailing `(` is required, which is what
 * separates `$this->_assertPermission(...)` from the property read
 * `$this->_invocationContext`.
 *
 * @return string[]
 */
function herald_self_calls(ReflectionMethod $method): array
{
    $file = $method->getFileName();

    if ($file === false) {
        return [];
    }

    $tokens = herald_file_tokens($file);
    $start = $method->getStartLine();
    $end = $method->getEndLine();
    $calls = [];

    foreach ($tokens as $i => $token) {
        if (!is_array($token) || $token[0] !== T_VARIABLE || $token[1] !== '$this') {
            continue;
        }

        if ($token[2] < $start || $token[2] > $end) {
            continue;
        }

        $arrow = $tokens[$i + 1] ?? null;
        $name = $tokens[$i + 2] ?? null;
        $paren = $tokens[$i + 3] ?? null;

        if (!is_array($arrow) || !in_array($arrow[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
            continue;
        }

        if (!is_array($name) || $name[0] !== T_STRING || $paren !== '(') {
            continue;
        }

        $calls[] = $name[1];
    }

    return array_values(array_unique($calls));
}

/**
 * The authorization gate reachable from `$class`'s dispatch entry
 * points, or null when none is.
 *
 * Walks `execute()` (plus `stream()` for streaming tools) breadth-first
 * through the tool's own `$this->` calls, so a gate invoked from a
 * private per-mode handler counts. `filterFor()` and `inputSchemaFor()`
 * are unreachable from `execute()` and therefore never contribute,
 * which is the point.
 *
 * @param class-string<ToolInterface> $class
 *
 * @throws ReflectionException if the class cannot be reflected.
 */
function herald_reachable_gate(string $class): ?string
{
    $reflection = new ReflectionClass($class);
    $gates = herald_authorization_gate_methods();

    $methods = [];
    foreach ($reflection->getMethods() as $method) {
        $methods[$method->getName()] = $method;
    }

    $queue = ['execute'];
    if ($reflection->implementsInterface(StreamableToolInterface::class)) {
        $queue[] = 'stream';
    }

    $seen = [];

    while ($queue !== []) {
        $name = array_shift($queue);

        if (isset($seen[$name]) || !isset($methods[$name])) {
            continue;
        }

        $seen[$name] = true;

        foreach (herald_self_calls($methods[$name]) as $callee) {
            if (in_array($callee, $gates, true)) {
                return $callee;
            }

            $queue[] = $callee;
        }
    }

    return null;
}

/**
 * Every tool the source registers, keyed by MCP name, excluding the
 * internal fixtures the underscore prefix marks.
 *
 * Reads `Tools::getAllUnfiltered()`, the seam added for the docs
 * generator, because it is the only enumeration that is independent of
 * the install's edition AND of its settings. The suite runs on a Free
 * install, so `getAll()` would silently omit the nine Pro write tools
 * and `craft_exec`, which is the exact blind spot that produced a
 * 33-tool reference.
 *
 * @return array<string,ToolInterface>
 */
function herald_source_registered_tools(): array
{
    $tools = [];

    foreach (Herald::getInstance()->tools->getAllUnfiltered() as $tool) {
        $name = $tool::getName();

        if (str_starts_with($name, DocsController::INTERNAL_TOOL_PREFIX)) {
            continue;
        }

        $tools[$name] = $tool;
    }

    return $tools;
}

// -----------------------------------------------------------------------------
// The detector is not vacuous
// -----------------------------------------------------------------------------

it('detects a gate reachable from execute()', function() {
    expect(herald_reachable_gate(GatedTool::class))->toBe('_assertPermission');
});

it('detects a gate reachable only through a private mode handler', function() {
    expect(herald_reachable_gate(IndirectlyGatedTool::class))->toBe('_assertPermission');
});

it('reports no gate for a tool that only checks permissions in filterFor()', function() {
    // The regression that shipped `craft_command` ungated: the tool was
    // visible-filtered and looked guarded. `filterFor()` is unreachable
    // from `execute()`, so it must not count.
    expect(herald_reachable_gate(ListFilteredOnlyTool::class))->toBeNull();
});

// -----------------------------------------------------------------------------
// Every registered tool is classified
// -----------------------------------------------------------------------------

it('classifies every registered tool as gated, stdio-only, or reviewed read-only', function() {
    $readOnly = herald_read_only_allowlist();
    $knownUngated = herald_known_ungated_mutating_tools();
    $unclassified = [];

    foreach (herald_source_registered_tools() as $name => $tool) {
        if (herald_reachable_gate($tool::class) !== null) {
            continue;
        }

        if (AttributeReader::isStdioOnly($tool)) {
            continue;
        }

        if (in_array($name, $readOnly, true)) {
            continue;
        }

        if (in_array($name, $knownUngated, true)) {
            continue;
        }

        $unclassified[] = sprintf('%s (%s)', $name, $tool::class);
    }

    expect($unclassified)->toBe([], sprintf(
        "Unclassified tool(s): %s.\n"
        . "Every registered tool must be one of:\n"
        . "  1. gated — call an authorization gate (%s) from execute() or stream();\n"
        . "  2. transport-gated — carry #[IsStdioOnly];\n"
        . "  3. reviewed read-only — carry #[IsReadOnly] and be added to "
        . "herald_read_only_allowlist() with a reason;\n"
        . '  4. a reviewed exception in herald_known_ungated_mutating_tools().',
        implode(', ', $unclassified),
        implode(', ', herald_authorization_gate_methods()),
    ));
});

it('keeps every read-only-allowlisted tool mechanically read-only', function() {
    $registered = herald_source_registered_tools();
    $violations = [];

    foreach (herald_read_only_allowlist() as $name) {
        $tool = $registered[$name] ?? null;

        if ($tool === null) {
            $violations[] = sprintf('%s is allowlisted but not registered', $name);
            continue;
        }

        $annotations = AttributeReader::annotationsFor($tool);

        if (($annotations['readOnlyHint'] ?? false) !== true) {
            $violations[] = sprintf(
                '%s (%s) is on the read-only allowlist without #[IsReadOnly]',
                $name,
                $tool::class,
            );
        }
    }

    expect($violations)->toBe([], implode('; ', $violations));
});

it('holds the known ungated mutating tools to an exact set', function() {
    $known = herald_known_ungated_mutating_tools();
    $actual = [];

    foreach (herald_source_registered_tools() as $name => $tool) {
        if (herald_reachable_gate($tool::class) !== null) {
            continue;
        }

        if (AttributeReader::isStdioOnly($tool)) {
            continue;
        }

        if (in_array($name, herald_read_only_allowlist(), true)) {
            continue;
        }

        $actual[] = $name;
    }

    sort($actual);
    sort($known);

    expect($actual)->toBe($known,
        'The set of ungated mutating tools changed. Gating one of these is '
        . 'welcome — delete it from herald_known_ungated_mutating_tools() in '
        . 'the same commit. Adding one is not: gate it instead.',
    );
});

it('never hides a tool behind the internal-fixture prefix without review', function() {
    // `herald_source_registered_tools()` skips underscore-prefixed
    // names, the same convention `DocsController` uses to keep fixtures
    // out of the reference. That skip must stay a one-item exception, or
    // it becomes a way around the classification above.
    $internal = [];

    foreach (Herald::getInstance()->tools->getAllUnfiltered() as $tool) {
        $name = $tool::getName();

        if (str_starts_with($name, DocsController::INTERNAL_TOOL_PREFIX)) {
            $internal[] = $name;
        }
    }

    sort($internal);

    expect($internal)->toBe(['_streaming_test']);
});

// -----------------------------------------------------------------------------
// Scope mapping
// -----------------------------------------------------------------------------

it('maps every tool the source registers to a deliberate capability scope', function() {
    // `tests/Services/ScopesTest.php` asserts the same invariant over
    // `Tools::getAll()` inside a Pro registry, which covers the edition
    // gate but not the settings gate: a tool whose `shouldRegister()`
    // consults a setting or an env var is absent from `getAll()` even on
    // Pro, so its scope mapping goes unchecked. `getAllUnfiltered()` has
    // neither blind spot.
    //
    // An unmapped tool is not dangerous — `scopeForTool()` returns the
    // ungrantable `NONE` sentinel and `grantsTool()` denies it — but it
    // is silently unreachable over an OAuth grant, which for a tool that
    // should carry `system:write` is a support ticket rather than a
    // breach. Deliberate is the only acceptable state.
    $unmapped = [];

    foreach (herald_source_registered_tools() as $name => $tool) {
        if (!array_key_exists($name, Scopes::TOOL_SCOPES)) {
            $unmapped[] = sprintf('%s (%s)', $name, $tool::class);
        }
    }

    expect($unmapped)->toBe([], sprintf(
        'Tool(s) absent from Scopes::TOOL_SCOPES: %s. They fail closed on the '
        . 'ungrantable NONE scope, so they are unreachable over an OAuth grant '
        . 'until mapped.',
        implode(', ', $unmapped),
    ));
});

it('maps every registered tool to a scope that is actually grantable', function() {
    $scopes = Herald::getInstance()->scopes;
    $violations = [];

    foreach (herald_source_registered_tools() as $name => $tool) {
        $scope = $scopes->scopeForTool($name);

        if (!$scopes->isKnown($scope)) {
            $violations[] = sprintf('%s maps to ungrantable scope `%s`', $name, $scope);
        }
    }

    expect($violations)->toBe([], implode('; ', $violations));
});

// -----------------------------------------------------------------------------
// Nothing ships unregistered
// -----------------------------------------------------------------------------

it('registers every tool class that ships under src/tools', function() {
    // The third shape of the same bug: code that exists, passes review,
    // and is never wired to anything. An unregistered tool is dead
    // weight rather than a hole, but it is dead weight nobody notices,
    // and `_buildRegistry()` is a hand-written list.
    $root = dirname(__DIR__, 2) . '/src/tools';
    $registered = [];

    foreach (Herald::getInstance()->tools->getAllUnfiltered() as $tool) {
        $registered[$tool::class] = true;
    }

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
    $missing = [];

    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $relative = substr($file->getPathname(), strlen($root) + 1, -4);
        $class = 'craftpulse\\herald\\tools\\' . str_replace('/', '\\', $relative);

        if (!class_exists($class)) {
            continue;
        }

        $reflection = new ReflectionClass($class);

        if ($reflection->isAbstract() || !$reflection->implementsInterface(ToolInterface::class)) {
            continue;
        }

        if (!isset($registered[$class])) {
            $missing[] = $class;
        }
    }

    expect($missing)->toBe([], sprintf(
        'Tool class(es) on disk but absent from Tools::_buildRegistry(): %s.',
        implode(', ', $missing),
    ));
});
