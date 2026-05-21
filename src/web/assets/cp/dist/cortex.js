/* =========================================================================
   Cortex CP JavaScript.

   Single global `Cortex` namespace on `window`. 9.2 ships the
   Allowlist-tab slideout wiring; 9.3 / 9.5 extend with Activity-detail
   and Tokens-issuance slideouts respectively.

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
     *   - 9.2 — openAllowlistOverrideSlideout (this file's only export)
     *   - 9.3 — openActivityDetailSlideout
     *   - 9.5 — openTokenIssuanceSlideout, copyToClipboard
     */
    var Cortex = {
        /**
         * Plugin handle — used to scope translations and disambiguate
         * any global selectors Cortex JS attaches.
         */
        handle: 'cortex',

        /**
         * Open the "+ New override" slideout for the Allowlist tab.
         *
         * Fetches the form HTML from the Cortex controller, hands it
         * to `Craft.Slideout` with `containerElement: 'form'` so the
         * slideout shell carries the `<form>` chrome we POST through,
         * then wires submit + cancel handlers.
         *
         * On submit:
         *   - POST `cortex/settings/add-override` with the form payload.
         *   - On 200 success: close the slideout, reload the table.
         *   - On 400 validation failure: surface the message inline,
         *     keep the slideout open so the operator can fix and retry.
         *
         * @param {object} adminTable - The `Craft.VueAdminTable` instance
         *                              the slideout should reload on
         *                              successful issuance.
         */
        openAllowlistOverrideSlideout: function(adminTable) {
            Craft.sendActionRequest('GET', 'cortex/allowlist/override-slideout')
                .then(function(response) {
                    var html = response.data;

                    var slideout = new Craft.Slideout(html, {
                        containerElement: 'form',
                        containerAttributes: {
                            action: '',
                            method: 'post',
                            novalidate: '',
                            class: 'cortex-slideout cortex-allowlist-slideout',
                        },
                    });

                    Cortex._wireAllowlistSlideout(slideout, adminTable);
                })
                .catch(function(error) {
                    Craft.cp.displayError(Craft.t('cortex', 'Could not open the override form.'));
                    if (window.console && console.error) {
                        console.error('Cortex slideout load failed:', error);
                    }
                });
        },

        /**
         * Wire submit + cancel handlers on a freshly-opened allowlist
         * slideout. Pulled into its own function so the test harness
         * (future Gate 9.7 architecture invariant) can introspect the
         * handler attachment.
         *
         * @private
         */
        _wireAllowlistSlideout: function(slideout, adminTable) {
            var $container = slideout.$container;

            $container.on('click', '[data-cortex-cancel]', function(event) {
                event.preventDefault();
                slideout.close();
            });

            // Form submit — `$container` is the `<form>` itself when
            // `containerElement: 'form'`.
            $container.on('submit', function(event) {
                event.preventDefault();

                var $submit = $container.find('[data-cortex-submit]').addClass('loading').attr('disabled', 'disabled');

                // Strip any previous error decoration before retrying.
                $container.find('.field.has-errors').each(function() {
                    var $field = Craft.$(this);
                    $field.removeClass('has-errors');
                    $field.children('.input').removeClass('errors prevalidate');
                    $field.children('ul.errors').remove();
                });

                Craft.sendActionRequest('POST', 'cortex/settings/add-override', {
                    data: $container.serialize(),
                })
                    .then(function() {
                        slideout.close();
                        if (adminTable && typeof adminTable.reload === 'function') {
                            adminTable.reload();
                        }
                        Craft.cp.displayNotice(Craft.t('cortex', 'Override added.'));
                    })
                    .catch(function(error) {
                        var message = Craft.t('cortex', 'Could not add override.');
                        if (error && error.response && error.response.data && error.response.data.message) {
                            message = error.response.data.message;
                        }
                        Craft.cp.displayError(message);
                    })
                    .finally(function() {
                        $submit.removeClass('loading').removeAttr('disabled');
                    });
            });
        },
    };

    window.Cortex = Cortex;
})(window);
