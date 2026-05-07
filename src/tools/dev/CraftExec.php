<?php

namespace craftpulse\cortex\tools\dev;

use craftpulse\cortex\attributes\IsDestructive;
use craftpulse\cortex\attributes\IsIdempotent;
use craftpulse\cortex\attributes\IsOpenWorld;
use craftpulse\cortex\attributes\IsStdioOnly;
use craftpulse\cortex\attributes\Title;
use craftpulse\cortex\Plugin;
use craftpulse\cortex\tools\AbstractTool;
use craftpulse\cortex\tools\support\SecretRedactor;
use craftpulse\cortex\tools\ToolException;
use ParseError;
use Throwable;

/**
 * =========================================================================
 * `craft_exec` tool — wraps Craft's `ExecController` eval pattern under
 * six security gates (PLANNING.md 4.9).
 *
 * Threat model: an LLM choosing destructive operations because it
 * misread context, NOT sandbox escape. "Trusted local user" doesn't
 * apply when the agent is an LLM. The gates are layered in order so a
 * failure at any one short-circuits the rest:
 *
 *   1. **Dry-run default.** Without `confirm: true`, returns the parsed
 *      expression and proposed effect, never the result.
 *   2. **Structured output.** Result JSON-serialisable. Errors typed as
 *      `parse_error` or `runtime_error` with stack trace.
 *   3. **Secret redaction.** Result values + captured stdout run through
 *      `SecretRedactor` before return.
 *   4. **Destructive-op guard.** Patterns matching `delete*`, `drop*`,
 *      `truncate*`, `Elements::deleteElement`, `migrate/down` require
 *      both `confirm: true` AND `dangerous: true` — `confirm` alone is
 *      not enough for a destructive expression.
 *   5. **stdio only.** `#[IsStdioOnly]` attribute on the class; the
 *      dispatcher hard-rejects HTTP requests for this tool regardless
 *      of token scope or permissions.
 *   6. **Tool annotation.** `destructiveHint: true` on the tool so
 *      spec-compliant clients can warn the user before invoking.
 *
 * Gate 7 (settings toggle): when `Settings::$execEnabled = false`, the
 * tool short-circuits with a clear error before any eval is attempted.
 * The registry still surfaces the tool — disabling at registration
 * time would prevent the LLM from getting an explanatory error if it
 * tried to call.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  0.1.0
 */
#[Title('Evaluate Craft Expression')]
#[IsDestructive]
#[IsIdempotent(false)]
#[IsOpenWorld(false)]
#[IsStdioOnly]
class CraftExec extends AbstractTool
{
    // Constants
    // =========================================================================

    /**
     * Destructive-op pattern set. Matched against the (trimmed)
     * expression text before eval. Conservative: false positives are
     * acceptable here — we'd rather warn the LLM than miss a real
     * delete. The list is intentionally surface-level (regex on text);
     * a real semantic analyser would need to parse PHP.
     */
    private const DESTRUCTIVE_PATTERNS = [
        '/->\s*delete[A-Z_]/',        // ->deleteElement, ->delete_user
        '/::\s*delete[A-Z_]/',
        '/->\s*delete\s*\(/',         // bare ->delete(
        '/::\s*delete\s*\(/',
        '/Elements\s*::\s*delete/',
        '/->\s*drop[A-Z_]/',
        '/->\s*dropTable\s*\(/',
        '/->\s*dropColumn\s*\(/',
        '/->\s*truncate[A-Z_]/',
        '/->\s*truncateTable\s*\(/',
        '/runAction\s*\(\s*[\'"]migrate\/down/',
        '/runAction\s*\(\s*[\'"]project-config\/sync/',  // can clobber unsynced changes
    ];

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public static function getName(): string
    {
        return 'craft_exec';
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public static function getDescription(): string
    {
        return 'Evaluate a single PHP expression in the running Craft context — wraps ' .
            'Craft\'s ExecController. **stdio only.** Defaults to dry-run: pass ' .
            '`confirm: true` to actually evaluate. Destructive patterns (delete*, drop*, ' .
            'truncate*, Elements::deleteElement, migrate/down) additionally require ' .
            '`dangerous: true`. Result is JSON-serialised and secrets are redacted before ' .
            'return. Errors come back typed (parse_error / runtime_error) with a stack trace.';
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public static function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'expression' => [
                    'type' => 'string',
                    'description' => 'PHP expression to evaluate. `<?php` prefix optional. Required.',
                ],
                'confirm' => [
                    'type' => 'boolean',
                    'description' => 'Required to actually evaluate (otherwise returns dry-run analysis).',
                ],
                'dangerous' => [
                    'type' => 'boolean',
                    'description' => 'Required in addition to `confirm` for destructive expressions.',
                ],
            ],
            'required' => ['expression'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @inheritdoc
     *
     * @throws ToolException
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public function execute(array $arguments): array
    {
        $settings = Plugin::getInstance()->getSettings();
        if (!$settings->execEnabled) {
            throw new ToolException(
                'craft_exec is disabled by configuration. Enable it via ' .
                '`plugins.cortex.settings.execEnabled` in project config or set ' .
                '`execEnabled => true` in `config/cortex.php`.',
            );
        }

        $expression = isset($arguments['expression']) && is_string($arguments['expression'])
            ? trim($arguments['expression'])
            : '';
        if ($expression === '') {
            throw new ToolException('`expression` is required and must be a non-empty string.');
        }

        // Allow `<?php` prefix; strip it so eval doesn't re-encounter the tag.
        if (str_starts_with($expression, '<?php')) {
            $expression = ltrim(substr($expression, 5));
        }
        // Strip a single trailing semicolon — eval's expression form supplies
        // its own.
        $expression = rtrim($expression, ';');

        $confirm = ($arguments['confirm'] ?? false) === true;
        $dangerous = ($arguments['dangerous'] ?? false) === true;
        $isDestructive = $this->_isDestructive($expression);

        // Gate 4: destructive guard. Even with confirm, a destructive
        // expression needs explicit `dangerous: true`.
        if ($isDestructive && (!$confirm || !$dangerous)) {
            return $this->_dryRun($expression, $isDestructive, blocked: true);
        }

        // Gate 1: dry-run default. Without confirm, never evaluate.
        if (!$confirm) {
            return $this->_dryRun($expression, $isDestructive, blocked: false);
        }

        return $this->_evaluate($expression, $isDestructive);
    }

    // Private Methods
    // =========================================================================

    /**
     * Whether the expression matches any destructive pattern.
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    private function _isDestructive(string $expression): bool
    {
        foreach (self::DESTRUCTIVE_PATTERNS as $pattern) {
            if (preg_match($pattern, $expression) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build the dry-run / blocked envelope. Never evaluates the
     * expression. The `blocked` flag distinguishes "user opted in to
     * dry-run" from "destructive without dangerous: true" so the LLM
     * can produce the right follow-up call.
     *
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    private function _dryRun(string $expression, bool $isDestructive, bool $blocked): array
    {
        $hint = $blocked
            ? 'Destructive expression. Re-call with `confirm: true` AND `dangerous: true` to evaluate.'
            : ($isDestructive
                ? 'Re-call with `confirm: true` AND `dangerous: true` to evaluate (destructive pattern detected).'
                : 'Dry-run by default. Re-call with `confirm: true` to evaluate.');

        return [
            'mode' => 'dry_run',
            'evaluated' => false,
            'blocked' => $blocked,
            'expression' => $expression,
            'isDestructive' => $isDestructive,
            'hint' => $hint,
        ];
    }

    /**
     * Evaluate the expression with output buffering. Mirrors the
     * try-as-expression-then-statement fallback from Craft's
     * `ExecController::actionExec`. Returns a typed envelope on
     * parse / runtime failure.
     *
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    private function _evaluate(string $expression, bool $isDestructive): array
    {
        ob_start();

        $result = null;
        $hasResult = false;
        $error = null;

        try {
            // First try as an expression (returns a value). Eval is the
            // whole point of the tool — gates 1-6 are what makes it safe.
            try {
                eval("\$result = {$expression};");
                $hasResult = true;
            } catch (ParseError) {
                // Re-attempt as a statement (no return value).
                eval("{$expression};");
            }
        } catch (ParseError $e) {
            $error = [
                'type' => 'parse_error',
                'message' => $e->getMessage(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ];
        } catch (Throwable $e) {
            $error = [
                'type' => 'runtime_error',
                'class' => $e::class,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ];
        }

        $output = (string) ob_get_clean();

        if ($error !== null) {
            return [
                'mode' => 'evaluated',
                'evaluated' => true,
                'isDestructive' => $isDestructive,
                'expression' => $expression,
                'output' => SecretRedactor::redactString($output),
                'error' => $error,
            ];
        }

        return [
            'mode' => 'evaluated',
            'evaluated' => true,
            'isDestructive' => $isDestructive,
            'expression' => $expression,
            'hasResult' => $hasResult,
            'result' => $hasResult ? $this->_normaliseResult($result) : null,
            'output' => SecretRedactor::redactString($output),
        ];
    }

    /**
     * Normalise an arbitrary eval result into a JSON-friendly,
     * secrets-redacted shape. Objects with public properties are cast
     * to arrays; closures and resources stringify; arrays are walked
     * through `SecretRedactor::redactArray`.
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    private function _normaliseResult(mixed $value): mixed
    {
        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value)) {
            return SecretRedactor::redactString($value);
        }

        if (is_array($value)) {
            return SecretRedactor::redactArray($this->_recursiveNormalise($value));
        }

        if (is_object($value)) {
            // Try a JSON round-trip first (cheapest path for plain DTOs and
            // anything implementing JsonSerializable). Fall back to a
            // public-property snapshot or stringification.
            $encoded = @json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            if (is_string($encoded) && $encoded !== '' && $encoded !== 'null') {
                $decoded = json_decode($encoded, true);
                if (is_array($decoded)) {
                    return SecretRedactor::redactArray($decoded);
                }
                return $decoded;
            }

            return [
                '__class' => $value::class,
                '__string' => method_exists($value, '__toString') ? (string) $value : null,
            ];
        }

        if (is_resource($value)) {
            return ['__resource' => get_resource_type($value)];
        }

        return ['__type' => gettype($value)];
    }

    /**
     * Walk a nested array applying `_normaliseResult` to non-array
     * leaves. Lets the redactor receive a fully-normalised tree.
     *
     * @param array<int|string,mixed> $value
     * @return array<int|string,mixed>
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    private function _recursiveNormalise(array $value): array
    {
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = is_array($v) ? $this->_recursiveNormalise($v) : $this->_normaliseResult($v);
        }
        return $out;
    }
}
