<?php

namespace craftpulse\herald\console\controllers;

use craft\console\Controller;
use craftpulse\herald\Herald;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Prints the active Herald edition.
 *
 * Usage:
 *   herald/edition/show
 *
 * Sanity-check helper for operators and CI scripts. Prints the
 * active edition handle (`free` or `pro`), the full list of
 * declared editions, and the boolean result of
 * `Herald::is(EDITION_PRO)` so a one-shot SSH session can confirm
 * which tier is running without booting a CP session.
 *
 * The edition handle lives in project config at
 * `plugins.herald.edition`. The Plugin Store sets it on purchase;
 * Herald does not maintain a separate license table.
 *
 * @author CraftPulse
 * @since  5.0.0
 */
class EditionController extends Controller
{
    // Public Methods
    // =========================================================================

    /**
     * Print the active edition handle, the full editions list, and
     * the `is(EDITION_PRO)` flag. Exit code is always `OK` (0), because the
     * command is read-only and has no failure surface beyond a
     * misconfigured plugin (which would have failed earlier at
     * `Herald::getInstance()`).
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function actionShow(): int
    {
        $plugin = Herald::getInstance();
        $edition = $plugin->edition;
        $editions = Herald::editions();
        $isPro = $plugin->is(Herald::EDITION_PRO);

        $this->stdout("\n");
        $this->stdout("Herald edition\n", Console::FG_GREEN);
        $this->stdout(str_repeat('=', 40) . "\n\n");

        $this->stdout("  edition:    ", Console::FG_GREY);
        $this->stdout($edition . "\n", $isPro ? Console::FG_CYAN : Console::FG_YELLOW);

        $this->stdout("  editions:   ", Console::FG_GREY);
        $this->stdout('[' . implode(', ', $editions) . "]\n");

        $this->stdout("  is(pro):    ", Console::FG_GREY);
        $this->stdout(($isPro ? 'true' : 'false') . "\n", $isPro ? Console::FG_CYAN : Console::FG_YELLOW);

        $this->stdout("\n");

        return ExitCode::OK;
    }
}
