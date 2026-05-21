/* =========================================================================
   Cortex CP JavaScript.

   Single global `Cortex` namespace on `window`. 9.1 ships only the
   namespace stub — slideout wiring (Garnish.Slideout for token
   issuance and activity-row detail) and copy-to-clipboard helpers land
   in 9.2 (Tokens) and 9.3 (Activity).

   Per docs/plans/gate-9.md locked decision 6 + 16: pure vanilla JS,
   no Vue components authored, no TypeScript, no bundler. The file
   ships verbatim through Craft's asset manager.
   ========================================================================= */
(function(window) {
    'use strict';

    if (typeof window.Cortex !== 'undefined') {
        return;
    }

    /**
     * Cortex CP JavaScript namespace.
     *
     * Populated incrementally across Gate 9 sub-gates:
     *   - 9.2 — openTokenIssuanceSlideout, copyToClipboard
     *   - 9.3 — openActivityDetailSlideout
     */
    window.Cortex = {
        /**
         * Plugin handle — used to scope translations and disambiguate
         * any global selectors Cortex JS attaches.
         */
        handle: 'cortex',
    };
})(window);
