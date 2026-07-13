/* =========================================================================
   Herald CP JavaScript.

   Single global `Herald` namespace on `window`. 9.2 ships the
   Allowlist-tab slideout wiring; 9.3 / 9.5 extend with Activity-detail
   and Tokens-issuance slideouts respectively.

   Per docs/plans/gate-9.md locked decision 6 + 16: pure vanilla JS,
   no Vue components authored, no TypeScript, no bundler. The file
   ships verbatim through Craft's asset manager.
   ========================================================================= */
(function(window) {
    'use strict';

    if (typeof window.Herald !== 'undefined') {
        return;
    }

    /**
     * Herald CP JavaScript namespace.
     *
     * Populated incrementally across Gate 9 sub-gates:
     *   - 9.2 — openAllowlistOverrideSlideout
     *   - 9.3 — openActivityDetailSlideout
     *   - 9.5 — openTokenIssuanceSlideout, copyToClipboard
     */
    var Herald = {
        /**
         * Plugin handle — used to scope translations and disambiguate
         * any global selectors Herald JS attaches.
         */
        handle: 'herald',

        /**
         * Interval handle for the grant expiry countdowns, created lazily by
         * `startCountdowns()` and left running for the life of the page.
         */
        _countdownTimer: null,

        /**
         * Open the "+ New override" slideout for the Allowlist tab.
         *
         * Fetches the form HTML from the Herald controller, hands it
         * to `Craft.Slideout` with `containerElement: 'form'` so the
         * slideout shell carries the `<form>` chrome we POST through,
         * then wires submit + cancel handlers.
         *
         * On submit:
         *   - POST `herald/settings/add-override` with the form payload.
         *   - On 200 success: close the slideout, reload the table.
         *   - On 400 validation failure: surface the message inline,
         *     keep the slideout open so the operator can fix and retry.
         *
         * @param {object} adminTable - The `Craft.VueAdminTable` instance
         *                              the slideout should reload on
         *                              successful issuance.
         */
        openAllowlistOverrideSlideout: function(adminTable) {
            Craft.sendActionRequest('GET', 'herald/settings/allowlist-override-slideout')
                .then(function(response) {
                    var html = response.data;

                    var slideout = new Craft.Slideout(html, {
                        containerElement: 'form',
                        containerAttributes: {
                            action: '',
                            method: 'post',
                            novalidate: '',
                            class: 'herald-slideout herald-allowlist-slideout',
                        },
                    });

                    Herald._wireAllowlistSlideout(slideout, adminTable);
                    Herald._wireGrantSlideout(slideout);
                })
                .catch(function(error) {
                    Craft.cp.displayError(Craft.t('herald', 'Could not open the grant form.'));
                    if (window.console && console.error) {
                        console.error('Herald slideout load failed:', error);
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

            $container.on('click', '[data-herald-cancel]', function(event) {
                event.preventDefault();
                slideout.close();
            });

            // Form submit — `$container` is the `<form>` itself when
            // `containerElement: 'form'`.
            $container.on('submit', function(event) {
                event.preventDefault();

                var $submit = $container.find('[data-herald-submit]').addClass('loading').attr('disabled', 'disabled');

                // Strip any previous error decoration before retrying.
                $container.find('.field.has-errors').each(function() {
                    var $field = Craft.$(this);
                    $field.removeClass('has-errors');
                    $field.children('.input').removeClass('errors prevalidate');
                    $field.children('ul.errors').remove();
                });

                Craft.sendActionRequest('POST', 'herald/settings/add-override', {
                    data: $container.serialize(),
                })
                    .then(function() {
                        slideout.close();
                        if (adminTable && typeof adminTable.reload === 'function') {
                            adminTable.reload();
                        }
                        Craft.cp.displayNotice(Craft.t('herald', 'Grant issued.'));
                    })
                    .catch(function(error) {
                        var message = Craft.t('herald', 'Could not add grant.');
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
         * Wire the guided grant slideout's command picker: stop the group
         * checkbox from toggling its <details>, cascade a whole-group check
         * onto (and disable) the per-action checkboxes, and live-filter the
         * groups. Native <details> owns expand/collapse, so each group is an
         * independent disclosure with no shared JS state.
         *
         * @private
         */
        _wireGrantSlideout: function(slideout) {
            var container = slideout.$container[0];
            if (!container) {
                return;
            }
            var root = container.querySelector('[data-herald-grant-commands]');
            if (!root) {
                return;
            }

            var groups = root.querySelectorAll('[data-herald-grant-group]');

            Array.prototype.forEach.call(groups, function(group) {
                // The group checkbox lives in the <summary>; stop its click
                // from bubbling to the summary (which would toggle the group).
                var control = group.querySelector('[data-herald-grant-group-control]');
                if (control) {
                    control.addEventListener('click', function(event) {
                        event.stopPropagation();
                    });
                }

                var groupCb = group.querySelector('[data-herald-grant-group-cb]');
                var actionCbs = group.querySelectorAll('[data-herald-grant-action-cb]');
                if (groupCb) {
                    groupCb.addEventListener('change', function() {
                        Array.prototype.forEach.call(actionCbs, function(cb) {
                            // A whole-group grant (group/*) already covers the
                            // exact routes, so disable + clear them to keep the
                            // posted patterns[] minimal.
                            cb.checked = false;
                            cb.disabled = groupCb.checked;
                        });
                    });
                }
            });

            var filter = root.querySelector('[data-herald-grant-filter]');
            if (filter) {
                filter.addEventListener('input', function() {
                    var needle = (filter.value || '').trim().toLowerCase();
                    Array.prototype.forEach.call(groups, function(group) {
                        var handle = (group.getAttribute('data-group-handle') || '').toLowerCase();
                        var handleMatches = needle === '' || handle.indexOf(needle) !== -1;
                        var anyActionMatches = false;

                        var actions = group.querySelectorAll('[data-herald-grant-action]');
                        Array.prototype.forEach.call(actions, function(action) {
                            var routeId = (action.getAttribute('data-route-id') || '').toLowerCase();
                            var match = needle === '' || handleMatches || routeId.indexOf(needle) !== -1;
                            action.hidden = !match;
                            if (match) {
                                anyActionMatches = true;
                            }
                        });

                        var visible = handleMatches || anyActionMatches;
                        group.hidden = !visible;
                        if (needle === '') {
                            group.open = false;
                        } else if (visible) {
                            group.open = true;
                        }
                    });
                });
            }
        },

        /**
         * Start (once) a 30-second interval that refreshes every grant
         * expiry countdown on the page. Safe to call repeatedly — the timer
         * is created only on the first call.
         */
        startCountdowns: function() {
            if (Herald._countdownTimer) {
                Herald.refreshCountdowns();
                return;
            }
            Herald.refreshCountdowns();
            Herald._countdownTimer = window.setInterval(Herald.refreshCountdowns, 30000);
        },

        /**
         * Fill every `[data-herald-countdown]` element with the remaining
         * time until its `data-expires` timestamp. The stored value is a
         * naive UTC datetime (Craft's DB convention), so it is normalised to
         * an explicit UTC instant before the diff — a bare `new Date(...)`
         * would parse it as local time and skew the countdown by the offset.
         */
        refreshCountdowns: function() {
            var nodes = document.querySelectorAll('[data-herald-countdown]');
            var now = Date.now();
            Array.prototype.forEach.call(nodes, function(node) {
                var iso = node.getAttribute('data-expires');
                if (!iso) {
                    node.textContent = '';
                    return;
                }
                var normalised = iso.indexOf('T') === -1 ? iso.replace(' ', 'T') : iso;
                if (!/([zZ]|[+-]\d\d:?\d\d)$/.test(normalised)) {
                    normalised += 'Z';
                }
                var remaining = new Date(normalised).getTime() - now;
                if (isNaN(remaining)) {
                    node.textContent = '';
                    return;
                }
                if (remaining <= 0) {
                    node.textContent = Craft.t('herald', 'expired');
                    node.classList.add('herald-countdown--expired');
                    return;
                }
                node.classList.remove('herald-countdown--expired');
                node.textContent = Herald._humanizeDuration(remaining);
            });
        },

        /**
         * Humanize a millisecond duration into a compact "2d 3h left" /
         * "5h 12m left" / "<1m left" string.
         *
         * @private
         */
        _humanizeDuration: function(ms) {
            var seconds = Math.floor(ms / 1000);
            var days = Math.floor(seconds / 86400);
            seconds -= days * 86400;
            var hours = Math.floor(seconds / 3600);
            seconds -= hours * 3600;
            var minutes = Math.floor(seconds / 60);

            var parts = [];
            if (days) {
                parts.push(days + 'd');
            }
            if (hours) {
                parts.push(hours + 'h');
            }
            if (!days && minutes) {
                parts.push(minutes + 'm');
            }
            if (!days && !hours && !minutes) {
                parts.push('<1m');
            }
            return parts.join(' ') + ' ' + Craft.t('herald', 'left');
        },

        /**
         * Open the "+ New token" slideout for the Tokens tab.
         *
         * Fetches the slideout HTML (issue form + a hidden one-time
         * reveal panel) from the Herald controller, hands it to
         * `Craft.Slideout` with `containerElement: 'form'`, then wires
         * the submit / cancel / copy / done handlers.
         *
         * On submit the issue form POSTs `herald/settings/issue-token`; on 200
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
            Craft.sendActionRequest('GET', 'herald/settings/token-issue-slideout')
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
                            class: 'herald-slideout herald-token-slideout',
                        },
                    });

                    if (data.headHtml) {
                        Craft.appendHeadHtml(data.headHtml);
                    }
                    if (data.bodyHtml) {
                        Craft.appendBodyHtml(data.bodyHtml);
                    }

                    Herald._wireTokenSlideout(slideout, adminTable);
                })
                .catch(function(error) {
                    Craft.cp.displayError(Craft.t('herald', 'Could not open the token form.'));
                    if (window.console && console.error) {
                        console.error('Herald token slideout load failed:', error);
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

            $container.on('click', '[data-herald-cancel]', function(event) {
                event.preventDefault();
                slideout.close();
            });

            // Done button on the reveal panel — closes and reloads the
            // table so the freshly-issued row appears.
            $container.on('click', '[data-herald-done]', function(event) {
                event.preventDefault();
                slideout.close();
                if (adminTable && typeof adminTable.reload === 'function') {
                    adminTable.reload();
                }
            });

            // Copy-to-clipboard on the reveal panel. The plaintext is read
            // straight from the `<code>` node — it is never re-fetched.
            $container.on('click', '[data-herald-copy]', function(event) {
                event.preventDefault();
                var token = $container.find('[data-herald-token]').text();
                Herald.copyToClipboard(token, function() {
                    var $status = $container.find('[data-herald-copy-status]');
                    $status.text(Craft.t('herald', 'Token copied to clipboard.'));
                    Craft.cp.displayNotice(Craft.t('herald', 'Token copied to clipboard.'));
                });
            });

            // Issue form submit.
            $container.on('submit', function(event) {
                event.preventDefault();

                var $submit = $container.find('[data-herald-submit]').addClass('loading').attr('disabled', 'disabled');

                $container.find('.field.has-errors').each(function() {
                    var $field = Craft.$(this);
                    $field.removeClass('has-errors');
                    $field.children('.input').removeClass('errors prevalidate');
                    $field.children('ul.errors').remove();
                });

                Craft.sendActionRequest('POST', 'herald/settings/issue-token', {
                    data: $container.serialize(),
                })
                    .then(function(response) {
                        var token = response && response.data ? response.data.token : '';

                        // Swap from the issue form to the one-time reveal.
                        $container.find('[data-herald-issue-form]').attr('hidden', 'hidden');
                        var $reveal = $container.find('[data-herald-reveal]');
                        $reveal.find('[data-herald-token]').text(token || '');
                        $reveal.removeAttr('hidden');

                        // Move focus to the copy button so keyboard users
                        // land on the primary action of the new state.
                        $reveal.find('[data-herald-copy]').trigger('focus');

                        if (adminTable && typeof adminTable.reload === 'function') {
                            adminTable.reload();
                        }
                    })
                    .catch(function(error) {
                        var message = Craft.t('herald', 'Could not issue token.');
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
         * @param {number} id - The `herald_invocations` row id.
         */
        openActivityDetailSlideout: function(id) {
            Craft.sendActionRequest('GET', 'herald/settings/activity-row', {
                params: { id: id },
            })
                .then(function(response) {
                    var html = response && response.data ? response.data.html : '';

                    var slideout = new Craft.Slideout(html, {
                        containerAttributes: {
                            class: 'herald-slideout herald-activity-slideout',
                        },
                    });

                    slideout.$container.on('click', '[data-herald-done]', function(event) {
                        event.preventDefault();
                        slideout.close();
                    });
                })
                .catch(function(error) {
                    Craft.cp.displayError(Craft.t('herald', 'Could not open the activity detail.'));
                    if (window.console && console.error) {
                        console.error('Herald activity detail load failed:', error);
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
         * dependent UI. Read-only mode (`config/herald.php` override)
         * disables every control server-side, so this wiring is inert there.
         *
         * @param {Element} root - The `[data-herald-command-browser]` element.
         */
        initCommandBrowser: function(root) {
            if (!root || root.getAttribute('aria-disabled') === 'true') {
                return;
            }

            var groups = root.querySelectorAll('[data-herald-command-group]');
            groups.forEach(function(group) {
                Herald._wireCommandGroup(group);
            });

            var filter = root.querySelector('[data-herald-command-filter]');
            if (filter) {
                filter.addEventListener('input', function() {
                    Herald._filterCommandGroups(root, filter.value);
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
            // Expand / collapse is native <details>/<summary> now — the
            // browser owns the disclosure state, so each group toggles
            // independently with zero JS and full keyboard / AT support.
            // The only wiring the summary needs: stop the group lightswitch
            // (which sits inside the summary) from bubbling its click up to
            // the summary, otherwise toggling the switch would also open or
            // close the group. Craft's LightSwitch binds its handler
            // directly to the switch element, so it still fires; we only
            // block the summary's disclosure toggle.
            var switchContainer = group.querySelector('[data-herald-group-switch]');
            if (switchContainer) {
                switchContainer.addEventListener('click', function(event) {
                    event.stopPropagation();
                });
            }

            var groupSwitch = group.querySelector('[data-herald-group-switch] .lightswitch');

            // Craft's `Craft.LightSwitch` is a Garnish.Base widget: it
            // fires `change` via `this.trigger('change')` on the WIDGET
            // object, not as a bubbling DOM event on the `.lightswitch`
            // element. Binding `Craft.$(el).on('change', ...)` would never
            // fire. Resolve the widget instance (Craft stashes it via
            // `$el.data('lightswitch', this)`) and register on it.
            var groupWidget = Herald._lightswitchWidget(groupSwitch);
            if (groupWidget) {
                groupWidget.on('change', function() {
                    Herald._applyGroupSwitchState(group);
                });
            }

            var actionSwitches = group.querySelectorAll('[data-herald-command-action] .lightswitch');
            actionSwitches.forEach(function(sw) {
                var actionWidget = Herald._lightswitchWidget(sw);
                if (actionWidget) {
                    actionWidget.on('change', function() {
                        Herald._updateGroupBadge(group);
                    });
                }
            });

            Herald._applyGroupSwitchState(group);
        },

        /**
         * Reflect the group lightswitch state onto its action switches: ON
         * disables each action switch (covered by `group/*`); OFF re-enables
         * them so exact ids drive persistence. Always refreshes the badge.
         *
         * @private
         */
        _applyGroupSwitchState: function(group) {
            var groupSwitch = group.querySelector('[data-herald-group-switch] .lightswitch');
            var on = !!groupSwitch && groupSwitch.classList.contains('on');
            var badge = group.querySelector('[data-herald-group-badge]');

            var actionSwitches = group.querySelectorAll('[data-herald-command-action] .lightswitch');
            actionSwitches.forEach(function(sw) {
                var widget = Herald._lightswitchWidget(sw);
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
                Herald._updateGroupBadge(group);
            }
        },

        /**
         * Recompute and render a group's "N/total allowed" badge from the
         * current action-switch states.
         *
         * @private
         */
        _updateGroupBadge: function(group) {
            var badge = group.querySelector('[data-herald-group-badge]');
            if (!badge) {
                return;
            }

            var actionSwitches = group.querySelectorAll('[data-herald-command-action] .lightswitch');
            var total = actionSwitches.length;
            var allowed = 0;
            actionSwitches.forEach(function(sw) {
                if (sw.classList.contains('on')) {
                    allowed++;
                }
            });

            badge.textContent = Craft.t('herald', '{count}/{total} allowed', { count: allowed, total: total });
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
            var groups = root.querySelectorAll('[data-herald-command-group]');

            groups.forEach(function(group) {
                var handle = (group.getAttribute('data-group-handle') || '').toLowerCase();
                var handleMatches = needle === '' || handle.indexOf(needle) !== -1;
                var anyActionMatches = false;

                var actions = group.querySelectorAll('[data-herald-command-action]');
                actions.forEach(function(action) {
                    var routeId = (action.getAttribute('data-route-id') || '').toLowerCase();
                    var match = needle === '' || handleMatches || routeId.indexOf(needle) !== -1;
                    action.hidden = !match;
                    if (match) {
                        anyActionMatches = true;
                    }
                });

                var visible = handleMatches || anyActionMatches;
                group.hidden = !visible;

                // With a live filter, auto-open a matching group so its
                // matched actions are actually on screen; restore the
                // collapsed state once the filter is cleared.
                if (needle === '') {
                    group.open = false;
                } else if (visible) {
                    group.open = true;
                }
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
                    Herald._legacyCopy(text, done);
                });
                return;
            }

            Herald._legacyCopy(text, done);
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
                    console.error('Herald clipboard copy failed:', e);
                }
            }
            document.body.removeChild(textarea);
        },
    };

    window.Herald = Herald;
})(window);
