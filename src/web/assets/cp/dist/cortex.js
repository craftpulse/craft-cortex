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
     *   - 9.2 — openAllowlistOverrideSlideout
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

        /**
         * Open the "+ New token" slideout for the Tokens tab.
         *
         * Fetches the slideout HTML (issue form + a hidden one-time
         * reveal panel) from the Cortex controller, hands it to
         * `Craft.Slideout` with `containerElement: 'form'`, then wires
         * the submit / cancel / copy / done handlers.
         *
         * On submit the issue form POSTs `cortex/tokens/issue`; on 200
         * the slideout swaps from the form to the reveal panel and
         * injects the plaintext (carried in the JSON response's `token`
         * key) into the `<code>` block. The plaintext lives ONLY in that
         * DOM node and the JSON response — never in a URL, a flash, or a
         * log. Garnish.Slideout supplies the focus trap + ESC dismissal.
         *
         * @param {object} adminTable - The `Craft.VueAdminTable` instance
         *                              the slideout reloads after a
         *                              successful issuance.
         */
        openTokenIssuanceSlideout: function(adminTable) {
            Craft.sendActionRequest('GET', 'cortex/tokens/issue-slideout')
                .then(function(response) {
                    var slideout = new Craft.Slideout(response.data, {
                        containerElement: 'form',
                        containerAttributes: {
                            action: '',
                            method: 'post',
                            novalidate: '',
                            class: 'cortex-slideout cortex-token-slideout',
                        },
                    });

                    Cortex._wireTokenSlideout(slideout, adminTable);
                })
                .catch(function(error) {
                    Craft.cp.displayError(Craft.t('cortex', 'Could not open the token form.'));
                    if (window.console && console.error) {
                        console.error('Cortex token slideout load failed:', error);
                    }
                });
        },

        /**
         * Wire submit / cancel / copy / done handlers on a freshly-opened
         * token slideout. Pulled into its own function so the handler
         * attachment stays introspectable.
         *
         * @private
         */
        _wireTokenSlideout: function(slideout, adminTable) {
            var $container = slideout.$container;

            $container.on('click', '[data-cortex-cancel]', function(event) {
                event.preventDefault();
                slideout.close();
            });

            // Done button on the reveal panel — closes and reloads the
            // table so the freshly-issued row appears.
            $container.on('click', '[data-cortex-done]', function(event) {
                event.preventDefault();
                slideout.close();
                if (adminTable && typeof adminTable.reload === 'function') {
                    adminTable.reload();
                }
            });

            // Copy-to-clipboard on the reveal panel. The plaintext is read
            // straight from the `<code>` node — it is never re-fetched.
            $container.on('click', '[data-cortex-copy]', function(event) {
                event.preventDefault();
                var token = $container.find('[data-cortex-token]').text();
                Cortex.copyToClipboard(token, function() {
                    var $status = $container.find('[data-cortex-copy-status]');
                    $status.text(Craft.t('cortex', 'Token copied to clipboard.'));
                    Craft.cp.displayNotice(Craft.t('cortex', 'Token copied to clipboard.'));
                });
            });

            // Issue form submit.
            $container.on('submit', function(event) {
                event.preventDefault();

                var $submit = $container.find('[data-cortex-submit]').addClass('loading').attr('disabled', 'disabled');

                $container.find('.field.has-errors').each(function() {
                    var $field = Craft.$(this);
                    $field.removeClass('has-errors');
                    $field.children('.input').removeClass('errors prevalidate');
                    $field.children('ul.errors').remove();
                });

                Craft.sendActionRequest('POST', 'cortex/tokens/issue', {
                    data: $container.serialize(),
                })
                    .then(function(response) {
                        var token = response && response.data ? response.data.token : '';

                        // Swap from the issue form to the one-time reveal.
                        $container.find('[data-cortex-issue-form]').attr('hidden', 'hidden');
                        var $reveal = $container.find('[data-cortex-reveal]');
                        $reveal.find('[data-cortex-token]').text(token || '');
                        $reveal.removeAttr('hidden');

                        // Move focus to the copy button so keyboard users
                        // land on the primary action of the new state.
                        $reveal.find('[data-cortex-copy]').trigger('focus');

                        if (adminTable && typeof adminTable.reload === 'function') {
                            adminTable.reload();
                        }
                    })
                    .catch(function(error) {
                        var message = Craft.t('cortex', 'Could not issue token.');
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

        /**
         * Copy a string to the clipboard, invoking `onSuccess` when the
         * write resolves. Prefers the async Clipboard API; falls back to
         * a hidden-textarea + `execCommand('copy')` on older browsers or
         * insecure contexts where `navigator.clipboard` is unavailable.
         *
         * @param {string}   text      - The value to copy.
         * @param {function} onSuccess - Invoked after a successful copy.
         */
        copyToClipboard: function(text, onSuccess) {
            var done = typeof onSuccess === 'function' ? onSuccess : function() {};

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(done).catch(function() {
                    Cortex._legacyCopy(text, done);
                });
                return;
            }

            Cortex._legacyCopy(text, done);
        },

        /**
         * Clipboard fallback for insecure contexts / older browsers.
         *
         * @private
         */
        _legacyCopy: function(text, done) {
            var textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.setAttribute('readonly', '');
            textarea.style.position = 'absolute';
            textarea.style.left = '-9999px';
            document.body.appendChild(textarea);
            textarea.select();
            try {
                document.execCommand('copy');
                done();
            } catch (e) {
                if (window.console && console.error) {
                    console.error('Cortex clipboard copy failed:', e);
                }
            }
            document.body.removeChild(textarea);
        },
    };

    window.Cortex = Cortex;
})(window);
