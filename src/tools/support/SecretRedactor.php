<?php

namespace craftpulse\cortex\tools\support;

/**
 * =========================================================================
 * Single source of truth for secret-value redaction across tools.
 *
 * Two surfaces:
 *
 *   - `redactArray()` walks an associative array recursively and
 *     replaces values whose key contains a secret needle. Used by the
 *     `config` tool for project-config / custom.php payloads and by
 *     `craft_exec` for any associative result it materialises.
 *
 *   - `redactString()` runs needle-based pattern substitution on a flat
 *     string — used by `craft_exec` against captured stdout / stderr
 *     and against scalar return values.
 *
 * Needle list is intentionally broad: `password`, `securitykey`,
 * `token`, `secret`, `apikey`, `accesskey`, `privatekey`, `salt`,
 * `cookievalidationkey`, `webhooksecret`, `jwt`, `oauth`, `bearer`.
 * Substring matching is anchored on a normalised (lowercased,
 * dash/underscore-stripped) key so `cookieValidationKey`,
 * `API_SECRET`, `private-key` all match.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */
class SecretRedactor
{
    // Constants
    // =========================================================================

    public const REDACTED = '<redacted>';

    /**
     * Needle list — lowercased, no separators.
     */
    private const NEEDLES = [
        'password', 'securitykey', 'token', 'secret', 'apikey',
        'accesskey', 'privatekey', 'salt', 'cookievalidationkey',
        'webhooksecret', 'jwt', 'oauth', 'bearer',
    ];

    // Public Methods
    // =========================================================================

    /**
     * Whether a key (lookup name, env var, config field) contains a
     * secret needle. Match is substring on the normalised form.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public static function isSecretKey(string $key): bool
    {
        $needle = strtolower(str_replace(['_', '-'], '', $key));
        foreach (self::NEEDLES as $bad) {
            if (str_contains($needle, $bad)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Recursively redact secret-keyed values in an array. Non-array
     * values are returned unchanged. Non-string keys pass through.
     *
     * @param array<int|string,mixed> $data
     * @return array<int|string,mixed>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public static function redactArray(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && self::isSecretKey($key)) {
                $out[$key] = self::REDACTED;
                continue;
            }

            $out[$key] = is_array($value) ? self::redactArray($value) : $value;
        }

        return $out;
    }

    /**
     * Redact `KEY=value` and `KEY: value` patterns in flat text where
     * `KEY` matches a needle. Handles env-style and YAML/JSON dumps
     * surfaced by `var_export` or `print_r`. Conservative: requires
     * the assignment operator so we don't false-redact prose that
     * happens to mention "password".
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public static function redactString(string $output): string
    {
        $needles = self::NEEDLES;
        $pattern = '~([A-Za-z_][A-Za-z0-9_-]*)\s*([=:])\s*([\'"]?)([^\'"\s,;]+)\3~';

        return (string) preg_replace_callback(
            $pattern,
            static function(array $m) use ($needles): string {
                $normalised = strtolower(str_replace(['_', '-'], '', $m[1]));
                foreach ($needles as $needle) {
                    if (str_contains($normalised, $needle)) {
                        return $m[1] . $m[2] . $m[3] . self::REDACTED . $m[3];
                    }
                }
                return $m[0];
            },
            $output,
        );
    }
}
