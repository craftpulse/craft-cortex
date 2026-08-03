<?php

namespace craftpulse\herald\web\assets\cp;

use craft\web\AssetBundle;
use craft\web\assets\admintable\AdminTableAsset;
use craft\web\assets\cp\CpAsset;

/**
 * =========================================================================
 * Herald CP asset bundle.
 *
 * Ships the cosmetic CSS + interactive JS for every Herald CP screen
 * (the Settings, Tokens, Allowlist and Activity tabs).
 *
 * Depends on:
 *   - `CpAsset`        — Craft's base CP chrome (Garnish, jQuery,
 *                        translations, Craft.* JS namespace).
 *   - `AdminTableAsset` — Craft's VueAdminTable Vue component, used by
 *                        the Tokens and Activity tabs.
 *
 * By locked decision the bundle ships ZERO new composer or npm
 * dependencies — pure vanilla JS, raw CSS.
 *
 * Register via `$view->registerAssetBundle(HeraldCpAsset::class)` in
 * `src/templates/_cp/_layout.twig`. Never load globally — Herald CP
 * pages are the only consumers.
 * =========================================================================
 *
 * @author CraftPulse
 * @since  5.0.0
 */
class HeraldCpAsset extends AssetBundle
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';

        $this->depends = [
            CpAsset::class,
            AdminTableAsset::class,
        ];

        $this->css = [
            'herald.css',
        ];

        $this->js = [
            'herald.js',
        ];

        parent::init();
    }
}
