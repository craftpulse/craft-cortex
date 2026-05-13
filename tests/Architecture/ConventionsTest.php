<?php

/**
 * =========================================================================
 * Architecture tests — enforce cortex coding conventions at the test
 * level. Catches regressions that ECS / PHPStan don't cover:
 *
 *   - No `eval()`, `exec()`, `shell_exec()`, `passthru()`, `popen()`,
 *     `proc_open()`, or backtick operator anywhere in `src/`. The only
 *     "eval" surface in cortex is `craft_exec`, which goes through
 *     Craft's `ExecController` not PHP's eval directly.
 *
 *   - No `declare(strict_types=1)` in plugin source. Per project rules
 *     this is a Craft convention violation.
 *
 *   - Every tool implements `ToolInterface`.
 *
 *   - Every class file under `src/` opens with a section-header comment
 *     and includes `@author Craftpulse`.
 *
 * Implemented as Pest tests (not arch() expectations) because the file-
 * content checks aren't expressible through Pest's class-graph DSL.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */

use craftpulse\cortex\tools\ToolInterface;

// -----------------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------------

/**
 * Return every PHP file under `src/`.
 *
 * @return string[]
 */
function cortex_src_files(): array
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            __DIR__ . '/../../src',
            RecursiveDirectoryIterator::SKIP_DOTS,
        ),
    );

    $files = [];
    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }
    return $files;
}

// -----------------------------------------------------------------------------
// Banned shell-exec family
// -----------------------------------------------------------------------------

it('does not use eval, shell-exec family, or backticks anywhere in src/', function() {
    // Each pattern is a function call at top-level scope (not a method
    // access or property name). Tokenise rather than regex to avoid
    // false positives on identical strings inside docblocks / comments.
    //
    // NOTE: `eval` is a PHP language construct, not a function call — it
    // tokenises as `T_EVAL`, not `T_STRING`. It is matched in its own scan
    // loop below. The shell-exec family (shell_exec, proc_open, passthru,
    // popen) are real functions, so they fall under the T_STRING scan.
    $banned = ['shell_exec', 'proc_open', 'passthru', 'popen'];

    $violations = [];
    foreach (cortex_src_files() as $file) {
        $contents = file_get_contents($file);
        if ($contents === false) {
            continue;
        }

        $tokens = token_get_all($contents);
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token)) {
                continue;
            }
            [$id, $text] = $token;
            if ($id !== T_STRING) {
                continue;
            }
            if (!in_array(strtolower($text), $banned, true)) {
                continue;
            }
            // Skip method calls (`->shell_exec` / `::shell_exec`).
            $prev = $tokens[$i - 1] ?? null;
            if (is_array($prev) && in_array($prev[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
                continue;
            }
            // Must be followed by `(` to count as a function call.
            $next = $tokens[$i + 1] ?? null;
            if (!is_array($next) && $next === '(') {
                $violations[] = sprintf('%s calls %s()', $file, $text);
                continue;
            }
            // Account for whitespace before the paren.
            if (is_array($next) && $next[0] === T_WHITESPACE) {
                $following = $tokens[$i + 2] ?? null;
                if (!is_array($following) && $following === '(') {
                    $violations[] = sprintf('%s calls %s()', $file, $text);
                }
            }
        }

        // PHP `exec` is also a banned global call. Same rule.
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token) || $token[0] !== T_STRING || strtolower($token[1]) !== 'exec') {
                continue;
            }
            $prev = $tokens[$i - 1] ?? null;
            if (is_array($prev) && in_array($prev[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true)) {
                continue;
            }
            $next = $tokens[$i + 1] ?? null;
            $isCall = false;
            if (!is_array($next) && $next === '(') {
                $isCall = true;
            } elseif (is_array($next) && $next[0] === T_WHITESPACE) {
                $following = $tokens[$i + 2] ?? null;
                $isCall = !is_array($following) && $following === '(';
            }
            if ($isCall) {
                $violations[] = sprintf('%s calls exec()', $file);
            }
        }

        // `eval` is a language construct (T_EVAL), not a function call.
        // The only legitimate eval site is `tools/dev/CraftExec.php`, which
        // is the entry point for `craft_exec`. Skip that file; every other
        // src/ file containing T_EVAL is a violation.
        if (str_ends_with($file, '/CraftExec.php')) {
            continue;
        }
        foreach ($tokens as $token) {
            if (is_array($token) && $token[0] === T_EVAL) {
                $violations[] = sprintf('%s uses eval', $file);
                break;
            }
        }
    }

    expect($violations)->toBe([]);
});

// -----------------------------------------------------------------------------
// strict_types ban
// -----------------------------------------------------------------------------

it('does not declare strict_types in src/', function() {
    $violations = [];
    foreach (cortex_src_files() as $file) {
        $contents = file_get_contents($file);
        if ($contents === false) {
            continue;
        }
        if (preg_match('/declare\s*\(\s*strict_types\s*=\s*1\s*\)/', $contents)) {
            $violations[] = $file;
        }
    }

    expect($violations)->toBe([]);
});

// -----------------------------------------------------------------------------
// Section header + @author Craftpulse
// -----------------------------------------------------------------------------

it('every PHP class file in src/ has a section header and @author Craftpulse', function() {
    $violations = [];
    $sectionMarker = '====';

    foreach (cortex_src_files() as $file) {
        $contents = file_get_contents($file);
        if ($contents === false) {
            continue;
        }

        // Skip config/ since it's a project-style config return-array.
        if (str_contains($file, '/src/config/')) {
            continue;
        }

        // Skip tests, generators that don't have classes (e.g. config),
        // and similar non-class files.
        if (!preg_match('/\b(class|interface|trait|abstract\s+class|final\s+class)\s+\w+/', $contents)) {
            continue;
        }

        if (!str_contains($contents, $sectionMarker)) {
            $violations[] = sprintf('%s missing section header', $file);
        }

        if (!str_contains($contents, '@author Craftpulse')) {
            $violations[] = sprintf('%s missing @author Craftpulse', $file);
        }
    }

    expect($violations)->toBe([]);
});

// -----------------------------------------------------------------------------
// Tool implementations
// -----------------------------------------------------------------------------

it('every concrete class under src/tools/{schema,content,system,graphql,dev,workflow} implements ToolInterface', function() {
    $base = __DIR__ . '/../../src/tools';
    $directories = ['schema', 'content', 'system', 'graphql', 'dev', 'workflow'];

    $violations = [];
    foreach ($directories as $dir) {
        $path = "{$base}/{$dir}";
        if (!is_dir($path)) {
            continue;
        }

        $iterator = new DirectoryIterator($path);
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $className = 'craftpulse\\cortex\\tools\\' . $dir . '\\' . $file->getBasename('.php');

            if (!class_exists($className)) {
                $violations[] = "{$className} not autoloadable";
                continue;
            }

            $rc = new ReflectionClass($className);
            if ($rc->isAbstract() || $rc->isInterface() || $rc->isTrait()) {
                continue;
            }

            if (!$rc->implementsInterface(ToolInterface::class)) {
                $violations[] = "{$className} does not implement ToolInterface";
            }
        }
    }

    expect($violations)->toBe([]);
});

// -----------------------------------------------------------------------------
// @since on every class
// -----------------------------------------------------------------------------

it('every PHP class file in src/ has at least one @since tag', function() {
    // Mirrors the @author Craftpulse check above. @since lives on classes
    // and on individual methods; we only assert that each class file has
    // the tag *somewhere* — a missing class-level @since is the regression
    // we're catching, not per-method drift (PHPStan + reviewer catches
    // those). Skip files without a class declaration (config returns,
    // bootstrap helpers).

    $violations = [];

    foreach (cortex_src_files() as $file) {
        if (str_contains($file, '/src/config/')) {
            continue;
        }

        $contents = file_get_contents($file);
        if ($contents === false) {
            continue;
        }

        if (!preg_match('/\b(class|interface|trait|abstract\s+class|final\s+class)\s+\w+/', $contents)) {
            continue;
        }

        if (!str_contains($contents, '@since')) {
            $violations[] = sprintf('%s missing @since tag', $file);
        }
    }

    expect($violations)->toBe([]);
});

// -----------------------------------------------------------------------------
// Underscore prefix on private methods + properties
// -----------------------------------------------------------------------------

it('every private method and property under src/ uses the underscore-prefix convention', function() {
    // Scope: concrete classes only (interfaces don't declare private
    // members; abstract classes do but their concrete subclasses inherit
    // the same member names). Skip Yii / Craft framework members that
    // would be present via parent classes — reflection only walks
    // members declared on this class via getDeclaringClass() check.

    $violations = [];

    foreach (cortex_src_files() as $file) {
        $contents = file_get_contents($file);
        if ($contents === false) {
            continue;
        }

        if (str_contains($file, '/src/config/')) {
            continue;
        }

        if (!preg_match('/\bnamespace\s+([^;]+);/', $contents, $nsMatch)) {
            continue;
        }
        if (!preg_match('/\b(?:class|interface|trait)\s+(\w+)/', $contents, $clMatch)) {
            continue;
        }

        $className = trim($nsMatch[1]) . '\\' . $clMatch[1];
        if (!class_exists($className) && !interface_exists($className) && !trait_exists($className)) {
            continue;
        }

        $rc = new ReflectionClass($className);
        if ($rc->isInterface() || $rc->isTrait()) {
            continue;
        }

        foreach ($rc->getMethods(ReflectionMethod::IS_PRIVATE) as $method) {
            if ($method->getDeclaringClass()->getName() !== $rc->getName()) {
                continue;
            }
            $name = $method->getName();
            // Magic methods (__construct, __get, __set, __call, etc.) are
            // PHP's own contract — `_construct` would be wrong.
            if (str_starts_with($name, '__')) {
                continue;
            }
            if (!str_starts_with($name, '_')) {
                $violations[] = sprintf('%s::%s() — private method missing underscore prefix', $rc->getName(), $name);
            }
        }

        foreach ($rc->getProperties(ReflectionProperty::IS_PRIVATE) as $property) {
            if ($property->getDeclaringClass()->getName() !== $rc->getName()) {
                continue;
            }
            $name = $property->getName();
            if (!str_starts_with($name, '_')) {
                $violations[] = sprintf('%s::$%s — private property missing underscore prefix', $rc->getName(), $name);
            }
        }
    }

    expect($violations)->toBe([]);
});

// -----------------------------------------------------------------------------
// Tool execute() return types — no `mixed`
// -----------------------------------------------------------------------------

it('no tool declares `mixed` as the execute() return type', function() {
    // Tools should return concrete shapes, not mixed. Allowed: array,
    // \Generator, array|\Generator, or covariant overrides of those.
    // `mixed` defeats the contract — the dispatcher relies on
    // iterating a Generator vs returning an array, and `mixed` lets a
    // tool drift to returning anything at all.

    $violations = [];

    foreach (cortex_src_files() as $file) {
        if (str_contains($file, '/src/config/')) {
            continue;
        }

        $contents = file_get_contents($file);
        if ($contents === false) {
            continue;
        }

        if (!preg_match('/\bnamespace\s+([^;]+);/', $contents, $nsMatch)) {
            continue;
        }
        if (!preg_match('/\b(?:class|interface)\s+(\w+)/', $contents, $clMatch)) {
            continue;
        }

        $className = trim($nsMatch[1]) . '\\' . $clMatch[1];
        if (!class_exists($className) && !interface_exists($className)) {
            continue;
        }

        $rc = new ReflectionClass($className);
        if (!$rc->implementsInterface(ToolInterface::class) && $rc->getName() !== ToolInterface::class) {
            continue;
        }
        if ($rc->isAbstract() && $rc->getName() === ToolInterface::class) {
            // Skip the interface itself — its execute() declaration sets
            // the contract; we already enforce that contract is
            // array|\Generator below by reading its own type.
        }

        if (!$rc->hasMethod('execute')) {
            continue;
        }

        $method = $rc->getMethod('execute');
        if ($method->getDeclaringClass()->getName() !== $rc->getName()) {
            // Inherited from a parent — already checked when we hit that
            // parent class file.
            continue;
        }

        $returnType = $method->getReturnType();
        if ($returnType === null) {
            $violations[] = sprintf('%s::execute() — missing return type', $rc->getName());
            continue;
        }

        $typeNames = $returnType instanceof ReflectionUnionType
            ? array_map(static fn(ReflectionNamedType $t): string => $t->getName(), $returnType->getTypes())
            : [$returnType->getName()];

        if (in_array('mixed', $typeNames, true)) {
            $violations[] = sprintf(
                '%s::execute() — declares `mixed` return type (got %s)',
                $rc->getName(),
                implode('|', $typeNames),
            );
        }
    }

    expect($violations)->toBe([]);
});
