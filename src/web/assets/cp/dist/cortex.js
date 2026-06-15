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
            Craft.sendActionRequest('GET', 'cortex/settings/allowlist-override-slideout')
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
                    Craft.cp.displayError(Craft.t('cortex', 'Could not open the grant form.'));
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
                        Craft.cp.displayNotice(Craft.t('cortex', 'Grant issued.'));
                    })
                    .catch(function(error) {
                        var message = Craft.t('cortex', 'Could not add grant.');
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
         * On submit the issue form POSTs `cortex/settings/issue-token`; on 200
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
            Craft.sendActionRequest('GET', 'cortex/settings/token-issue-slideout')
                .then(function(response) {
                    // The action returns `{html, headHtml, bodyHtml}` —
                    // the body delta carries the element-select init JS
                    // that `forms.elementSelectField` registered through
                    // the view. Append it AFTER mounting the slideout so
                    // the script finds its container in the DOM.
                    var data = response.data || {};

                    var slideout = new Craft.Slideout(data.html || '', {
                        containerElement: 'form',
                        containerAttributes: {
                            action: '',
                            method: 'post',
                            novalidate: '',
                            class: 'cortex-slideout cortex-token-slideout',
                        },
                    });

                    if (data.headHtml) {
                        Craft.appendHeadHtml(data.headHtml);
                    }
                    if (data.bodyHtml) {
                        Craft.appendBodyHtml(data.bodyHtml);
                    }

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

                Craft.sendActionRequest('POST', 'cortex/settings/issue-token', {
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
         * Open the read-only detail slideout for an Activity-log row.
         *
         * Fetches the server-rendered detail HTML for the given invocation
         * id and hands it to `Craft.Slideout`. The slideout body carries
         * ONLY already-redacted columns (decision 11) — there is no
         * pre-redaction surface anywhere in the data path.
         *
         * Foreign-row / missing-id requests resolve to a 404 server-side
         * (fail-closed, decision 7 / 11); the catch surfaces a generic
         * error so a non-admin cannot use the response to enumerate other
         * users' rows.
         *
         * Garnish.Slideout supplies the focus trap + ESC dismissal; the
         * "Done" button closes it.
         *
         * @param {number} id - The `cortex_invocations` row id.
         */
        openActivityDetailSlideout: function(id) {
            Craft.sendActionRequest('GET', 'cortex/settings/activity-row', {
                params: { id: id },
            })
                .then(function(response) {
                    var html = response && response.data ? response.data.html : '';

                    var slideout = new Craft.Slideout(html, {
                        containerAttributes: {
                            class: 'cortex-slideout cortex-activity-slideout',
                        },
                    });

                    slideout.$container.on('click', '[data-cortex-done]', function(event) {
                        event.preventDefault();
                        slideout.close();
                    });
                })
                .catch(function(error) {
                    Craft.cp.displayError(Craft.t('cortex', 'Could not open the activity detail.'));
                    if (window.console && console.error) {
                        console.error('Cortex activity detail load failed:', error);
                    }
                });
        },

        /**
         * Wire the grouped allowed-commands toggle browser on the Settings
         * screen. For each enumerated console-command group:
         *   - The disclosure button expands / collapses the per-action list.
         *   - The group lightswitch, when ON, persists `group/*` and the
         *     per-action switches are disabled (the glob covers them all);
         *     when OFF, the per-action switches drive exact-id persistence.
         *   - A "N/total allowed" badge reflects the partial state and hides
         *     once the group switch is ON (full coverage).
         *   - The filter input live-filters groups and actions by route id.
         *
         * No Garnish subclass — the lightswitches are already initialised by
         * Craft's CP boot; we resolve each `Craft.LightSwitch` widget
         * instance and listen for its Garnish `change` event (the widget
         * fires it on itself, not as a bubbling DOM event) to toggle
         * dependent UI. Read-only mode (`config/cortex.php` override)
         * disables every control server-side, so this wiring is inert there.
         *
         * @param {Element} root - The `[data-cortex-command-browser]` element.
         */
        initCommandBrowser: function(root) {
            if (!root || root.getAttribute('aria-disabled') === 'true') {
                return;
            }

            var groups = root.querySelectorAll('[data-cortex-command-group]');
            groups.forEach(function(group) {
                Cortex._wireCommandGroup(group);
            });

            var filter = root.querySelector('[data-cortex-command-filter]');
            if (filter) {
                filter.addEventListener('input', function() {
                    Cortex._filterCommandGroups(root, filter.value);
                });
            }
        },

        /**
         * Wire a single command group: disclosure toggle, group-switch
         * cascade onto the action switches, and the partial-state badge.
         *
         * @private
         */
        _wireCommandGroup: function(group) {
            var expandBtn = group.querySelector('[data-cortex-group-expand]');
            var actions = group.querySelector('[data-cortex-command-actions]');
            var caret = group.querySelector('.cortex-command-group-caret');
            var groupSwitch = group.querySelector('.cortex-command-group-header > .lightswitch');

            if (expandBtn && actions) {
                expandBtn.addEventListener('click', function() {
                    var expanded = expandBtn.getAttribute('aria-expanded') === 'true';
                    expandBtn.setAttribute('aria-expanded', expanded ? 'false' : 'true');
                    actions.hidden = expanded;
                    if (caret) {
                        caret.textContent = expanded ? '▸' : '▾';
                    }
                });
            }

            // Craft's `Craft.LightSwitch` is a Garnish.Base widget: it
            // fires `change` via `this.trigger('change')` on the WIDGET
            // object, not as a bubbling DOM event on the `.lightswitch`
            // element. Binding `Craft.$(el).on('change', ...)` would never
            // fire. Resolve the widget instance (Craft stashes it via
            // `$el.data('lightswitch', this)`) and register on it.
            var groupWidget = Cortex._lightswitchWidget(groupSwitch);
            if (groupWidget) {
                groupWidget.on('change', function() {
                    Cortex._applyGroupSwitchState(group);
                });
            }

            var actionSwitches = group.querySelectorAll('[data-cortex-command-action] .lightswitch');
            actionSwitches.forEach(function(sw) {
                var actionWidget = Cortex._lightswitchWidget(sw);
                if (actionWidget) {
                    actionWidget.on('change', function() {
                        Cortex._updateGroupBadge(group);
                    });
                }
            });

            Cortex._applyGroupSwitchState(group);
        },

        /**
         * Reflect the group lightswitch state onto its action switches: ON
         * disables each action switch (covered by `group/*`); OFF re-enables
         * them so exact ids drive persistence. Always refreshes the badge.
         *
         * @private
         */
        _applyGroupSwitchState: function(group) {
            var groupSwitch = group.querySelector('.cortex-command-group-header > .lightswitch');
            var on = !!groupSwitch && groupSwitch.classList.contains('on');
            var badge = group.querySelector('[data-cortex-group-badge]');

            var actionSwitches = group.querySelectorAll('[data-cortex-command-action] .lightswitch');
            actionSwitches.forEach(function(sw) {
                var widget = Cortex._lightswitchWidget(sw);
                if (!widget) {
                    return;
                }
                if (on) {
                    widget.disable();
                } else {
                    widget.enable();
                }
            });

            if (badge) {
                badge.hidden = on;
            }
            if (!on) {
                Cortex._updateGroupBadge(group);
            }
        },

        /**
         * Recompute and render a group's "N/total allowed" badge from the
         * current action-switch states.
         *
         * @private
         */
        _updateGroupBadge: function(group) {
            var badge = group.querySelector('[data-cortex-group-badge]');
            if (!badge) {
                return;
            }

            var actionSwitches = group.querySelectorAll('[data-cortex-command-action] .lightswitch');
            var total = actionSwitches.length;
            var allowed = 0;
            actionSwitches.forEach(function(sw) {
                if (sw.classList.contains('on')) {
                    allowed++;
                }
            });

            badge.textContent = Craft.t('cortex', '{count}/{total} allowed', { count: allowed, total: total });
        },

        /**
         * Resolve the `Craft.LightSwitch` widget instance bound to a
         * lightswitch element so we can call its `enable()` / `disable()`
         * API instead of toggling classes by hand (the widget owns the
         * disabled hidden-input state the form posts).
         *
         * @private
         */
        _lightswitchWidget: function(el) {
            if (!el) {
                return null;
            }
            var widget = Craft.$(el).data('lightswitch');
            return widget || null;
        },

        /**
         * Live-filter the command groups + actions by a needle matched
         * against the group handle and each action's route id. A group with
         * any matching action (or a matching handle) stays visible with its
         * non-matching actions hidden; a group with no match is hidden
         * entirely. An empty needle restores everything.
         *
         * @private
         */
        _filterCommandGroups: function(root, needle) {
            needle = (needle || '').trim().toLowerCase();
            var groups = root.querySelectorAll('[data-cortex-command-group]');

            groups.forEach(function(group) {
                var handle = (group.getAttribute('data-group-handle') || '').toLowerCase();
                var handleMatches = needle === '' || handle.indexOf(needle) !== -1;
                var anyActionMatches = false;

                var actions = group.querySelectorAll('[data-cortex-command-action]');
                actions.forEach(function(action) {
                    var routeId = (action.getAttribute('data-route-id') || '').toLowerCase();
                    var match = needle === '' || handleMatches || routeId.indexOf(needle) !== -1;
                    action.hidden = !match;
                    if (match) {
                        anyActionMatches = true;
                    }
                });

                group.hidden = !(handleMatches || anyActionMatches);
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
