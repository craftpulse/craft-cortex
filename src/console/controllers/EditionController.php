<?php

namespace craftpulse\cortex\console\controllers;

use craft\console\Controller;
use craftpulse\cortex\Plugin;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * =========================================================================
 * Console — print the active Cortex edition.
 *
 * Usage:
 *   cortex/edition/show
 *
 * Sanity-check helper for operators and CI scripts. Prints the
 * active edition handle (`free` or `pro`), the full list of
 * declared editions, and the boolean result of
 * `Plugin::is(EDITION_PRO)` so a one-shot SSH session can confirm
 * which tier is running without booting a CP session.
 *
 * The edition handle lives in project config at
 * `plugins.cortex.edition`. The Plugin Store sets it on purchase;
 * Cortex does not maintain a separate license table.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */
class EditionController extends Controller
{
    // Public Methods
    // =========================================================================

    /**
     * Print the active edition handle, the full editions list, and
     * the `is(EDITION_PRO)` flag. Exit code is always `OK` (0) — the
     * command is read-only and has no failure surface beyond a
     * misconfigured plugin (which would have failed earlier at
     * `Plugin::getInstance()`).
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function actionShow(): int
    {
        $plugin = Plugin::getInstance();
        $edition = $plugin->edition;
        $editions = Plugin::editions();
        $isPro = $plugin->is(Plugin::EDITION_PRO);

        $this->stdout("\n");
        $this->stdout("Cortex edition\n", Console::FG_GREEN);
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
