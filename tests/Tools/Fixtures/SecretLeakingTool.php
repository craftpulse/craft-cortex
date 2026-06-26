<?php

namespace craftpulse\cortex\tests\Tools\Fixtures;

use craftpulse\cortex\tools\AbstractTool;

/**
 * =========================================================================
 * Test fixture — a tool that returns a raw secret-keyed field WITHOUT
 * redacting it itself. Models a future HTTP-reachable tool that forgets
 * to scrub its own result, so the dispatcher-level redaction of the
 * persisted audit excerpt can be proven independent of any tool-layer
 * scrubbing.
 *
 * `getName()` uses the underscore-prefix convention the other Gate 7
 * fixtures follow so it is trivially distinguishable from production
 * tools in the audit log.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */
final class SecretLeakingTool extends AbstractTool
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public static function getName(): string
    {
        return '_secret_leaking_test';
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public static function getDescription(): string
    {
        return 'Test fixture returning an un-redacted secret-keyed field.';
    }

    /**
     * @inheritdoc
     *
     * @param array<string,mixed> $arguments
     * @return array<int|string,mixed>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function execute(array $arguments): array
    {
        return [
            'ok' => true,
            'password' => 'topsecret-value',
            'safe' => 'visible',
        ];
    }
}
