<?php

namespace craftpulse\herald\tests\Tools\Fixtures;

use craft\console\Controller;
use yii\console\ExitCode;

/**
 * =========================================================================
 * Throwaway console controller used only by the `craft_command`
 * redaction test. Its single action echoes a `KEY=value` secret line to
 * stdout so the test can prove `CraftCommand` runs captured console
 * output through `SecretRedactor::redactString()` before the value
 * reaches either the wire response or the persisted audit excerpt.
 *
 * Registered into `Craft::$app->controllerMap` for the duration of the
 * test under the `herald-test-secret` controller id, then removed.
 * =========================================================================
 *
 * @author CraftPulse
 * @since  5.0.0
 */
class SecretEmittingController extends Controller
{
    // Public Methods
    // =========================================================================

    /**
     * Emit a `DB_PASSWORD=...` line to stdout. The token is recognisable
     * so the test can assert it never survives redaction.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function actionEmit(): int
    {
        $this->stdout("DB_PASSWORD=supersecret\n");
        $this->stdout("token=abc123\n");

        return ExitCode::OK;
    }
}
