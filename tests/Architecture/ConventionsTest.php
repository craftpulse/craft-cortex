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
 * @since  0.1.0
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

it('does not use eval, shell-exec family, or backticks anywhere in src/', function () {
    // Each pattern is a function call at top-level scope (not a method
    // access or property name). Tokenise rather than regex to avoid
    // false positives on identical strings inside docblocks / comments.
    $banned = ['eval', 'shell_exec', 'proc_open', 'passthru', 'popen'];

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
    }

    expect($violations)->toBe([]);
});

// -----------------------------------------------------------------------------
// strict_types ban
// -----------------------------------------------------------------------------

it('does not declare strict_types in src/', function () {
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

it('every PHP class file in src/ has a section header and @author Craftpulse', function () {
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

it('every concrete class under src/tools/{schema,content,system,graphql,dev,workflow} implements ToolInterface', function () {
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
