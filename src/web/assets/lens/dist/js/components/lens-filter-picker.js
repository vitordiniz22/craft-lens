/**
 * Lens Filter Picker
 *
 * Per-filter micro-popovers (desktop) and bottom sheets (mobile) driven by
 * a JSON registry injected from Twig. One active popover at a time.
 *
 * The popover chrome is Craft's `.menu.padded.visible` so it inherits the
 * native look (white bg, shadow, 14px text, `<h6>` section headings, and
 * the `.sel` checkmark on selected items). We only add positioning, a flex
 * body/footer, and the bottom-sheet variant for small viewports.
 */
(function () {
    'use strict';

    window.Lens = window.Lens || {};
    window.Lens.components = window.Lens.components || {};

    var DOM = window.Lens.core.DOM;
    var API = window.Lens.core.API;
    var escapeHtml = window.Lens.utils.escapeHtml;
    var MOBILE_QUERY = '(max-width: 900px)';

    function t(msg) {
        return Craft.t('lens', msg);
    }

    function debounce(fn, wait) {
        var timer = null;
        return function () {
            var ctx = this;
            var args = arguments;
            if (timer) clearTimeout(timer);
            timer = setTimeout(function () {
                timer = null;
                fn.apply(ctx, args);
            }, wait);
        };
    }

    function isMobile() {
        return window.matchMedia(MOBILE_QUERY).matches;
    }

    /**
     * Read a JSON payload serialized into a `data-lens-*` attribute on the
     * search form. The form is the project-wide convention for passing
     * server-side data into JS — no inline `{% js %}` blocks in Twig.
     */
    function readFormData(attrName) {
        var form = document.querySelector('[data-lens-target="search-form"]');
        if (!form) return {};
        var raw = form.getAttribute('data-lens-' + attrName);
        if (!raw) return {};
        try {
            return JSON.parse(raw);
        } catch (e) {
            return {};
        }
    }

    function asArray(value) {
        if (Array.isArray(value)) return value.slice();
        if (value === null || value === undefined || value === '') return [];
        return [value];
    }

    function el(tag, attrs, children) {
        var node = document.createElement(tag);
        if (attrs) {
            Object.keys(attrs).forEach(function (k) {
                if (k === 'class') node.className = attrs[k];
                else if (k === 'html') node.innerHTML = attrs[k];
                else if (k === 'text') node.textContent = attrs[k];
                else if (k.indexOf('on') === 0 && typeof attrs[k] === 'function') node.addEventListener(k.slice(2), attrs[k]);
                else if (attrs[k] === true) node.setAttribute(k, '');
                else if (attrs[k] !== false && attrs[k] != null) node.setAttribute(k, attrs[k]);
            });
        }
        (children || []).forEach(function (child) {
            if (child == null) return;
            if (typeof child === 'string') node.appendChild(document.createTextNode(child));
            else node.appendChild(child);
        });
        return node;
    }

    /**
     * Build a URL from the current one with the given params applied.
     * `null` deletes a param; arrays become repeated `key[]=v` entries.
     * `Lens.utils.stripUrlParam` handles the indexed-array variants PHP
     * emits so we don't leave orphan values behind.
     */
    function buildUrl(updates) {
        var url = new URL(window.location.href);

        Object.keys(updates).forEach(function (key) {
            var value = updates[key];
            Lens.utils.stripUrlParam(url, key);

            if (value === null || value === undefined || value === '') return;

            if (Array.isArray(value)) {
                if (!value.length) return;
                value.forEach(function (v) {
                    url.searchParams.append(key + '[]', v);
                });
            } else {
                url.searchParams.set(key, String(value));
            }
        });

        url.searchParams.delete('offset');
        return url.toString();
    }

    function commit(updates) {
        window.location.assign(buildUrl(updates));
    }

    // ════════════════════════════════════════════════════════════════════
    // Popover / sheet chrome
    // ════════════════════════════════════════════════════════════════════

    var state = {
        container: null,
        trigger: null,
        mobile: false,
        boundOutside: null,
        boundKeydown: null,
    };

    function ensureOverlayRoot() {
        var root = document.querySelector('[data-lens-target="filter-overlay-root"]');
        if (!root) {
            root = document.createElement('div');
            root.dataset.lensTarget = 'filter-overlay-root';
            root.className = 'lens-filter-overlay-root';
            document.body.appendChild(root);
        }
        return root;
    }

    function positionPopover(popover, trigger) {
        var rect = trigger.getBoundingClientRect();
        var gap = 8;

        popover.style.top = (rect.bottom + gap) + 'px';
        popover.style.left = '0px';
        popover.style.visibility = 'hidden';

        var pw = popover.offsetWidth;
        var ph = popover.offsetHeight;
        var vw = document.documentElement.clientWidth;
        var vh = window.innerHeight;

        var left = rect.left;
        if (left + pw > vw - 8) left = vw - pw - 8;
        if (left < 8) left = 8;
        popover.style.left = left + 'px';

        if (rect.bottom + gap + ph > vh - 8) {
            var above = rect.top - ph - gap;
            if (above > 8) popover.style.top = above + 'px';
        }

        popover.style.visibility = '';
    }

    function focusFirst(container) {
        var node = container.querySelector(
            'input:not([type="hidden"]):not([disabled]), select:not([disabled]), ' +
            'textarea:not([disabled]), button:not([disabled]), a[href]'
        );
        if (node) node.focus();
    }

    function closePopover() {
        if (!state.container) return;
        var trigger = state.trigger;
        state.container.remove();
        state.container = null;

        if (state.boundOutside) {
            document.removeEventListener('mousedown', state.boundOutside, true);
            state.boundOutside = null;
        }
        if (state.boundKeydown) {
            document.removeEventListener('keydown', state.boundKeydown, true);
            state.boundKeydown = null;
        }

        if (trigger) {
            trigger.setAttribute('aria-expanded', 'false');
            try { trigger.focus(); } catch (e) { /* ignore */ }
        }
        state.trigger = null;
        state.mobile = false;
        document.body.classList.remove('lens-filter-sheet-open');
    }

    /**
     * Mount a popover. `contentEl` is an `.lens-filter-menu` container (uses
     * Craft's `.menu.padded.visible` under the hood); `title` is only used
     * for the mobile sheet header.
     */
    function openContainer(contentEl, trigger, title) {
        closePopover();

        state.trigger = trigger;
        state.mobile = isMobile();

        var root = ensureOverlayRoot();
        var wrapper;

        if (state.mobile) {
            wrapper = el('div', {
                class: 'lens-filter-sheet-backdrop',
                'data-lens-target': 'filter-backdrop',
            });

            var sheet = el('div', {
                class: 'lens-filter-sheet',
                'data-lens-target': 'filter-sheet',
                role: 'dialog',
                'aria-modal': 'true',
            }, [
                el('span', { class: 'lens-filter-sheet__grip', 'aria-hidden': 'true' }),
                el('div', {
                    class: 'lens-filter-sheet__body',
                    'data-lens-target': 'filter-sheet-body',
                }, [contentEl]),
            ]);

            wrapper.appendChild(sheet);
            document.body.classList.add('lens-filter-sheet-open');
        } else {
            // On desktop the menu floats directly inside the overlay root.
            wrapper = contentEl;
            wrapper.setAttribute('role', 'dialog');
        }

        root.appendChild(wrapper);
        state.container = wrapper;

        if (!state.mobile) positionPopover(wrapper, trigger);

        focusFirst(wrapper);
        if (trigger) trigger.setAttribute('aria-expanded', 'true');

        state.boundOutside = function (e) {
            if (!state.container) return;
            if (state.container.contains(e.target)) return;
            if (trigger && trigger.contains(e.target)) return;
            closePopover();
        };
        state.boundKeydown = function (e) {
            if (e.key === 'Escape') {
                e.stopPropagation();
                closePopover();
            }
        };
        document.addEventListener('mousedown', state.boundOutside, true);
        document.addEventListener('keydown', state.boundKeydown, true);
    }

    /**
     * Build the popover chrome as a Craft `.menu.padded.visible` element
     * with a small titled header (filter name + × close). Every popover
     * gets one: when the user adds a new filter there's no chip yet, so
     * the title is their only context; when editing an existing filter
     * the title confirms which one they're modifying.
     */
    function makeMenu(extraClass, title) {
        var menu = el('div', {
            class: 'menu padded visible lens-filter-menu' + (extraClass ? ' ' + extraClass : ''),
            'data-lens-target': 'filter-menu',
        });

        if (title) {
            menu.appendChild(el('div', { class: 'lens-filter-menu__header' }, [
                el('span', { class: 'lens-filter-menu__title', text: title }),
                el('button', {
                    type: 'button',
                    class: 'lens-filter-menu__close',
                    'data-lens-action': 'close-filter',
                    'aria-label': t('Close'),
                    html: '&times;',
                }),
            ]));
        }

        return menu;
    }

    /**
     * Append a scrollable body and an optional sticky footer to a menu
     * created by `makeMenu()`. The footer is optional — single-value
     * renderers call `optionList` + `menu.appendChild` directly and
     * skip this helper.
     */
    function appendMenuContent(menu, bodyNodes, footerNode) {
        var body = el('div', { class: 'lens-filter-menu__body' }, bodyNodes);
        menu.appendChild(body);
        if (footerNode) menu.appendChild(footerNode);
        return menu;
    }

    function makeFooter(onApply, onClear) {
        var children = [];
        if (onClear) {
            children.push(el('button', {
                type: 'button',
                class: 'btn',
                text: t('Clear'),
                onclick: onClear,
            }));
        }
        children.push(el('button', {
            type: 'button',
            class: 'btn submit',
            text: t('Apply'),
            onclick: onApply,
        }));
        return el('div', { class: 'lens-filter-menu__footer' }, children);
    }

    // ════════════════════════════════════════════════════════════════════
    // Picker list (+ Add filter)
    // ════════════════════════════════════════════════════════════════════

    function renderPickerList() {
        var registry = readFormData('filter-registry');
        var sections = readFormData('filter-sections');
        var active = readFormData('active-filters');

        var menu = makeMenu('lens-filter-menu--picker', t('Add filter'));

        var search = el('input', {
            type: 'text',
            class: 'text fullwidth',
            'data-lens-control': 'picker-search',
            placeholder: t('Search filters…'),
            autocomplete: 'off',
        });
        var searchRow = el('div', { class: 'lens-filter-menu__search' }, [search]);
        menu.appendChild(searchRow);

        var body = el('div', { class: 'lens-filter-menu__body' });
        menu.appendChild(body);

        var filterIds = Object.keys(registry);

        function isActive(filterId) {
            var cfg = registry[filterId] || {};
            var params = cfg.params;
            if (params) {
                return Object.keys(params).some(function (k) {
                    var p = params[k];
                    var v = active[p];
                    return v !== undefined && v !== null && v !== '' && !(Array.isArray(v) && !v.length);
                });
            }
            var v = active[filterId];
            return v !== undefined && v !== null && v !== '' && !(Array.isArray(v) && !v.length);
        }

        function render(query) {
            var q = (query || '').trim().toLowerCase();
            body.innerHTML = '';

            var bySection = {};
            filterIds.forEach(function (id) {
                var cfg = registry[id];
                if (!cfg) return;
                if (q && cfg.label.toLowerCase().indexOf(q) === -1) return;
                var section = cfg.section || 'other';
                (bySection[section] = bySection[section] || []).push(id);
            });

            var order = Object.keys(sections);
            Object.keys(bySection).forEach(function (s) {
                if (order.indexOf(s) === -1) order.push(s);
            });

            var anyVisible = false;
            order.forEach(function (section) {
                var ids = bySection[section];
                if (!ids || !ids.length) return;
                anyVisible = true;

                body.appendChild(el('h6', { text: sections[section] || section }));
                var ul = el('ul');
                ids.forEach(function (id) {
                    var cfg = registry[id];
                    var row = el('button', {
                        type: 'button',
                        class: 'lens-filter-option lens-filter-option--picker',
                        'data-lens-action': 'pick-filter',
                        'data-lens-filter-id': id,
                    }, [
                        el('span', { text: cfg.label }),
                    ]);
                    if (isActive(id)) {
                        row.appendChild(el('span', {
                            class: 'lens-filter-row-badge',
                            text: t('active'),
                        }));
                    }
                    ul.appendChild(el('li', {}, [row]));
                });
                body.appendChild(ul);
            });

            if (!anyVisible) {
                body.appendChild(el('p', {
                    class: 'light',
                    text: t('No filters match.'),
                }));
            }
        }

        render('');

        search.addEventListener('input', function () { render(search.value); });
        search.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                var first = body.querySelector('[data-lens-action="pick-filter"]');
                if (first) { e.preventDefault(); first.click(); }
            }
        });

        return menu;
    }

    function openPicker(trigger) {
        openContainer(renderPickerList(), trigger, t('Add filter'));
    }

    // ════════════════════════════════════════════════════════════════════
    // Value popovers
    // ════════════════════════════════════════════════════════════════════

    function openFilter(filterId, trigger) {
        var registry = readFormData('filter-registry');
        var cfg = registry[filterId];
        if (!cfg) return;

        var active = readFormData('active-filters');
        var renderer = TYPE_RENDERERS[cfg.type];
        if (!renderer) {
            // eslint-disable-next-line no-console
            console.warn('No renderer for filter type', cfg.type);
            return;
        }

        openContainer(renderer(filterId, cfg, active), trigger, cfg.label);
    }

    /** Build a list of options using our own `.lens-filter-option` button so
     * Craft's `.menu ul li a` rules never apply — we don't fight them, we
     * just don't use them. */
    function optionList(options, currentValue, onPick) {
        var ul = el('ul');
        options.forEach(function (opt) {
            var isSel = String(opt.value) === String(currentValue);
            var btn = el('button', {
                type: 'button',
                class: 'lens-filter-option' + (isSel ? ' is-selected' : ''),
                'data-lens-target': 'filter-option',
                'data-lens-value': opt.value,
                text: opt.label,
            });
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                onPick(opt.value);
            });
            ul.appendChild(el('li', {}, [btn]));
        });
        return ul;
    }

    function renderTriState(filterId, cfg, active) {
        var menu = makeMenu('lens-filter-menu--single', cfg.label);
        var current = active[filterId];
        var currentStr = current === true ? '1' : current === false ? '0' : '';
        var labels = cfg.triStateLabels || {};
        var options = [
            { value: '', label: labels.any || t('Any') },
            { value: '1', label: labels.yes || t('Yes') },
            { value: '0', label: labels.no || t('No') },
        ];
        var list = optionList(options, currentStr, function (value) {
            var next = {};
            next[filterId] = value === '' ? null : value;
            commit(next);
        });
        menu.appendChild(el('div', { class: 'lens-filter-menu__body' }, [list]));
        return menu;
    }

    function renderSingleSelect(filterId, cfg, active) {
        var menu = makeMenu('lens-filter-menu--single', cfg.label);
        var current = active[filterId] == null ? '' : String(active[filterId]);
        var list = optionList(cfg.options || [], current, function (value) {
            var next = {};
            next[filterId] = value === '' ? null : value;
            commit(next);
        });
        menu.appendChild(el('div', { class: 'lens-filter-menu__body' }, [list]));
        return menu;
    }

    function renderMultiSelect(filterId, cfg, active) {
        var menu = makeMenu('lens-filter-menu--multi', cfg.label);
        var currentValues = asArray(active[filterId]).map(String);
        var selected = new Set(currentValues);

        var ul = el('ul');
        (cfg.options || []).forEach(function (opt) {
            var strVal = String(opt.value);
            var cb = el('input', { type: 'checkbox' });
            cb.checked = selected.has(strVal);
            var btn = el('button', {
                type: 'button',
                class: 'lens-filter-option lens-filter-option--check'
                    + (selected.has(strVal) ? ' is-selected' : ''),
                'data-lens-target': 'filter-option',
                'data-lens-value': strVal,
            }, [
                cb,
                el('span', { text: opt.label }),
            ]);
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                if (selected.has(strVal)) {
                    selected.delete(strVal);
                    cb.checked = false;
                    btn.classList.remove('is-selected');
                } else {
                    selected.add(strVal);
                    cb.checked = true;
                    btn.classList.add('is-selected');
                }
            });
            ul.appendChild(el('li', {}, [btn]));
        });

        var footer = makeFooter(
            function () {
                var next = {};
                next[filterId] = Array.from(selected);
                commit(next);
            },
            currentValues.length ? function () {
                var next = {};
                next[filterId] = null;
                commit(next);
            } : null
        );

        return appendMenuContent(menu, [ul], footer);
    }

    function renderRange(filterId, cfg, active) {
        var menu = makeMenu(null, cfg.label);
        var minParam = cfg.params.min;
        var maxParam = cfg.params.max;

        var minInput = el('input', {
            type: 'number',
            class: 'text',
            'data-lens-control': 'range-min',
            placeholder: t('Min'),
            min: cfg.min != null ? cfg.min : 0,
            max: cfg.max != null ? cfg.max : 1,
            step: cfg.step || 0.05,
        });
        minInput.value = active[minParam] == null ? '' : String(active[minParam]);

        var maxInput = el('input', {
            type: 'number',
            class: 'text',
            'data-lens-control': 'range-max',
            placeholder: t('Max'),
            min: cfg.min != null ? cfg.min : 0,
            max: cfg.max != null ? cfg.max : 1,
            step: cfg.step || 0.05,
        });
        maxInput.value = active[maxParam] == null ? '' : String(active[maxParam]);

        var range = el('div', { class: 'lens-filter-range' }, [
            minInput,
            el('span', { class: 'light', text: t('to') }),
            maxInput,
        ]);
        var field = el('div', { class: 'field' }, [
            el('div', { class: 'input' }, [range]),
        ]);

        var hasValue = minInput.value !== '' || maxInput.value !== '';

        var footer = makeFooter(
            function () {
                var next = {};
                next[minParam] = minInput.value === '' ? null : minInput.value;
                next[maxParam] = maxInput.value === '' ? null : maxInput.value;
                if (minParam === 'nsfwScoreMin') next.nsfwFlagged = null;
                commit(next);
            },
            hasValue ? function () {
                var next = {};
                next[minParam] = null;
                next[maxParam] = null;
                if (minParam === 'nsfwScoreMin') next.nsfwFlagged = null;
                commit(next);
            } : null
        );

        return appendMenuContent(menu, [field], footer);
    }

    function renderDateRange(filterId, cfg, active) {
        var menu = makeMenu(null, cfg.label);
        var fromParam = cfg.params.from;
        var toParam = cfg.params.to;

        var fromInput = el('input', {
            type: 'text',
            class: 'text',
            'data-lens-control': 'date-from',
            placeholder: t('From'),
            autocomplete: 'off',
        });
        fromInput.value = active[fromParam] || '';
        var toInput = el('input', {
            type: 'text',
            class: 'text',
            'data-lens-control': 'date-to',
            placeholder: t('To'),
            autocomplete: 'off',
        });
        toInput.value = active[toParam] || '';

        var range = el('div', { class: 'lens-filter-range' }, [
            el('div', { class: 'datewrapper' }, [fromInput]),
            el('span', { class: 'light', text: t('to') }),
            el('div', { class: 'datewrapper' }, [toInput]),
        ]);
        var field = el('div', { class: 'field' }, [
            el('div', { class: 'input' }, [range]),
        ]);

        setTimeout(function () {
            if (window.jQuery && window.Craft && Craft.datepickerOptions) {
                jQuery(fromInput).datepicker(Craft.datepickerOptions);
                jQuery(toInput).datepicker(Craft.datepickerOptions);
            }
        }, 0);

        var hasValue = fromInput.value !== '' || toInput.value !== '';

        var footer = makeFooter(
            function () {
                var next = {};
                next[fromParam] = fromInput.value === '' ? null : fromInput.value;
                next[toParam] = toInput.value === '' ? null : toInput.value;
                commit(next);
            },
            hasValue ? function () {
                var next = {};
                next[fromParam] = null;
                next[toParam] = null;
                commit(next);
            } : null
        );

        return appendMenuContent(menu, [field], footer);
    }

    function renderColor(filterId, cfg, active) {
        var menu = makeMenu(null, cfg.label);
        var colorParam = cfg.params.color;
        var tolParam = cfg.params.tolerance;

        var currentColor = active[colorParam] || '';
        var currentTol = String(active[tolParam] || '30');
        var selectedTol = (function () {
            var n = parseInt(currentTol, 10) || 30;
            if (n <= 15) return '10';
            if (n <= 40) return '30';
            if (n <= 65) return '55';
            return '80';
        })();

        var native = el('input', {
            type: 'color',
            'data-lens-control': 'color-native',
            value: /^#[0-9a-f]{6}$/i.test(currentColor) ? currentColor : '#888888',
        });
        var hex = el('input', {
            type: 'text',
            class: 'text',
            'data-lens-control': 'color-hex',
            placeholder: '#RRGGBB',
            maxlength: 7,
        });
        hex.value = currentColor;

        native.addEventListener('input', function () { hex.value = native.value; });
        hex.addEventListener('input', function () {
            if (/^#[0-9a-f]{6}$/i.test(hex.value)) native.value = hex.value;
        });

        var colorField = el('div', { class: 'field' }, [
            el('div', { class: 'heading' }, [el('label', { text: t('Color') })]),
            el('div', { class: 'input' }, [
                el('div', { class: 'lens-filter-color' }, [native, hex]),
            ]),
        ]);

        var tolGroup = el('div', { class: 'btngroup', 'data-lens-target': 'tolerance-group' });
        (cfg.tolerancePresets || []).forEach(function (p) {
            var btn = el('button', {
                type: 'button',
                class: 'btn small' + (p.value === selectedTol ? ' active' : ''),
                'data-lens-action': 'set-tolerance',
                'data-lens-value': p.value,
                text: p.label,
                onclick: function () {
                    selectedTol = p.value;
                    tolGroup.querySelectorAll('[data-lens-action="set-tolerance"]')
                        .forEach(function (b) { b.classList.remove('active'); });
                    btn.classList.add('active');
                },
            });
            tolGroup.appendChild(btn);
        });
        var tolField = el('div', { class: 'field' }, [
            el('div', { class: 'heading' }, [el('label', { text: t('Color match') })]),
            el('div', { class: 'input' }, [tolGroup]),
        ]);

        var footer = makeFooter(
            function () {
                var value = hex.value.trim();
                if (!/^#[0-9a-f]{6}$/i.test(value)) {
                    Craft.cp.displayError(t('Enter a valid hex color like #aabbcc.'));
                    return;
                }
                var next = {};
                next[colorParam] = value;
                next[tolParam] = selectedTol;
                commit(next);
            },
            currentColor ? function () {
                var next = {};
                next[colorParam] = null;
                next[tolParam] = null;
                commit(next);
            } : null
        );

        return appendMenuContent(menu, [colorField, tolField], footer);
    }

    function renderTags(filterId, cfg, active) {
        var menu = makeMenu(null, cfg.label);
        var tagsParam = cfg.params.tags;
        var opParam = cfg.params.operator;

        var tags = asArray(active[tagsParam]).map(String);
        var operator = active[opParam] === 'and' ? 'and' : 'or';

        var chips = el('div', { class: 'lens-filter-tag-chips' });
        function renderChips() {
            chips.innerHTML = '';
            tags.forEach(function (tag) {
                var removeBtn = el('button', {
                    type: 'button',
                    class: 'lens-filter-tag-chip__remove',
                    'aria-label': t('Remove tag'),
                    html: '&times;',
                    onclick: function () {
                        tags = tags.filter(function (x) { return x !== tag; });
                        renderChips();
                    },
                });
                chips.appendChild(el('span', { class: 'lens-filter-tag-chip' }, [
                    el('span', { text: tag }),
                    removeBtn,
                ]));
            });
        }
        renderChips();

        var input = el('input', {
            type: 'text',
            class: 'text fullwidth',
            'data-lens-control': 'tag-input',
            placeholder: t('Type to search tags…'),
            autocomplete: 'off',
        });
        var suggestions = el('div', {
            class: 'lens-tag-suggestions',
            'data-lens-target': 'tag-suggestions',
            hidden: true,
        });
        var inputWrap = el('div', { class: 'lens-filter-tags-input' }, [input, suggestions]);

        function addTag(raw) {
            var tag = (raw || '').trim();
            if (!tag || tags.indexOf(tag) !== -1) return;
            tags.push(tag);
            renderChips();
            input.value = '';
            suggestions.hidden = true;
            suggestions.innerHTML = '';
        }

        var query = debounce(function () {
            var q = input.value.trim();
            if (q.length < 2) {
                suggestions.hidden = true;
                suggestions.innerHTML = '';
                return;
            }
            API.fetchTagSuggestions(q).then(function (response) {
                var data = (response && response.data) || {};
                var items = (data.tags || data.suggestions || data || []).filter(function (item) {
                    var label = typeof item === 'string' ? item : (item.tag || item.label || '');
                    return label && tags.indexOf(label) === -1;
                });
                if (!items.length) {
                    suggestions.hidden = true;
                    suggestions.innerHTML = '';
                    return;
                }
                suggestions.innerHTML = items.map(function (item) {
                    var label = typeof item === 'string' ? item : (item.tag || item.label || '');
                    var count = typeof item === 'object' && item.count ? item.count : null;
                    return '<button type="button" class="lens-tag-suggestion" data-tag="' + escapeHtml(label) + '">' +
                        '<span>' + escapeHtml(label) + '</span>' +
                        (count != null ? '<span class="light">' + count + '</span>' : '') +
                        '</button>';
                }).join('');
                suggestions.hidden = false;
            }).catch(function () {
                suggestions.hidden = true;
                Craft.cp.displayError(t('Could not load tag suggestions.'));
            });
        }, window.Lens.config.POLLING.TAG_AUTOCOMPLETE_DEBOUNCE_MS);

        input.addEventListener('input', query);
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); addTag(input.value); }
        });
        suggestions.addEventListener('mousedown', function (e) {
            var btn = e.target.closest('[data-tag]');
            if (!btn) return;
            e.preventDefault();
            addTag(btn.dataset.tag);
        });

        var inputField = el('div', { class: 'field' }, [
            el('div', { class: 'input' }, [inputWrap]),
        ]);

        var opRow = el('div', { class: 'lens-filter-tags-operator' });
        ['or', 'and'].forEach(function (op) {
            var radio = el('input', {
                type: 'radio',
                name: 'lens-filter-tag-operator',
                value: op,
            });
            if (operator === op) radio.checked = true;
            radio.addEventListener('change', function () {
                if (radio.checked) operator = op;
            });
            opRow.appendChild(el('label', {}, [
                radio,
                ' ' + (op === 'or' ? t('Match any tag') : t('Match all tags')),
            ]));
        });

        var footer = makeFooter(
            function () {
                var next = {};
                next[tagsParam] = tags.slice();
                next[opParam] = tags.length > 1 ? operator : null;
                commit(next);
            },
            tags.length ? function () {
                var next = {};
                next[tagsParam] = null;
                next[opParam] = null;
                commit(next);
            } : null
        );

        return appendMenuContent(menu, [chips, inputField, opRow], footer);
    }

    function renderProvider(filterId, cfg, active) {
        var menu = makeMenu(null, cfg.label);
        var providerParam = cfg.params.provider;
        var modelParam = cfg.params.model;
        var currentProvider = active[providerParam] || '';
        var currentModel = active[modelParam] || '';
        var selectedProvider = currentProvider;
        var selectedModel = currentModel;

        var providerSelect = el('select', {
            class: 'fullwidth',
            'data-lens-control': 'provider-select',
        });
        providerSelect.innerHTML = '<option value="">' + escapeHtml(t('Any')) + '</option>' +
            (cfg.options || []).map(function (o) {
                return '<option value="' + escapeHtml(o.value) + '"' +
                    (o.value === currentProvider ? ' selected' : '') + '>' + escapeHtml(o.label) + '</option>';
            }).join('');

        var providerField = el('div', { class: 'field' }, [
            el('div', { class: 'heading' }, [el('label', { text: cfg.label })]),
            el('div', { class: 'input' }, [
                el('div', { class: 'select fullwidth' }, [providerSelect]),
            ]),
        ]);

        var modelSelect = el('select', {
            class: 'fullwidth',
            'data-lens-control': 'provider-model-select',
        });
        var modelLoading = el('div', {
            class: 'lens-filter-provider-loading',
            text: t('Loading models…'),
            hidden: true,
        });

        var modelField = el('div', { class: 'field' }, [
            el('div', { class: 'heading' }, [el('label', { text: t('Model') })]),
            el('div', { class: 'input' }, [
                el('div', { class: 'select fullwidth' }, [modelSelect]),
                modelLoading,
            ]),
        ]);

        function paintModels(options) {
            modelSelect.innerHTML = '<option value="">' + escapeHtml(t('Any')) + '</option>' +
                options.map(function (o) {
                    return '<option value="' + escapeHtml(o.value) + '"' +
                        (o.value === selectedModel ? ' selected' : '') + '>' + escapeHtml(o.label) + '</option>';
                }).join('');
        }

        paintModels(cfg.modelOptions || []);

        providerSelect.addEventListener('change', function () {
            selectedProvider = providerSelect.value;
            selectedModel = '';
            modelLoading.hidden = false;
            modelSelect.disabled = true;
            API.get(cfg.modelsEndpoint || 'lens/search/provider-models', { provider: selectedProvider })
                .then(function (response) {
                    paintModels((response && response.data && response.data.options) || []);
                })
                .catch(function () {
                    paintModels([]);
                    Craft.cp.displayError(t('Could not load models for this provider.'));
                })
                .then(function () {
                    modelLoading.hidden = true;
                    modelSelect.disabled = false;
                });
        });
        modelSelect.addEventListener('change', function () {
            selectedModel = modelSelect.value;
        });

        var footer = makeFooter(
            function () {
                var next = {};
                next[providerParam] = selectedProvider || null;
                next[modelParam] = selectedModel || null;
                commit(next);
            },
            (currentProvider || currentModel) ? function () {
                var next = {};
                next[providerParam] = null;
                next[modelParam] = null;
                commit(next);
            } : null
        );

        return appendMenuContent(menu, [providerField, modelField], footer);
    }

    var TYPE_RENDERERS = {
        'tri-state': renderTriState,
        'single-select': renderSingleSelect,
        'multi-select': renderMultiSelect,
        // Preset-value filters (Face Count, File Size) reuse the single-select
        // renderer so every enumerated-value filter shares one visual language.
        'preset-buttons': renderSingleSelect,
        'range': renderRange,
        'date-range': renderDateRange,
        'color': renderColor,
        'tags': renderTags,
        'provider': renderProvider,
    };

    // ════════════════════════════════════════════════════════════════════
    // Wiring
    // ════════════════════════════════════════════════════════════════════

    function init() {
        DOM.delegate('[data-lens-action="open-filter-picker"]', 'click', function (e, btn) {
            e.preventDefault();
            if (state.container && state.trigger === btn) {
                closePopover();
                return;
            }
            openPicker(btn);
        });

        DOM.delegate('[data-lens-action="pick-filter"]', 'click', function (e, row) {
            e.preventDefault();
            var filterId = row.dataset.lensFilterId;
            var origin = state.trigger;
            closePopover();
            openFilter(filterId, origin);
        });

        DOM.delegate('[data-lens-action="edit-filter"]', 'click', function (e, btn) {
            e.preventDefault();
            e.stopPropagation();
            var filterId = btn.dataset.lensFilterId;
            openFilter(filterId, btn);
        });

        DOM.delegate('[data-lens-action="close-filter"]', 'click', function (e) {
            e.preventDefault();
            closePopover();
        });

        DOM.delegate('[data-lens-target="filter-backdrop"]', 'click', function (e, el) {
            if (e.target === el) closePopover();
        });

        window.addEventListener('resize', function () {
            if (!state.container) return;
            if (isMobile() !== state.mobile) {
                closePopover();
                return;
            }
            if (!state.mobile && state.trigger) positionPopover(state.container, state.trigger);
        });
    }

    window.Lens.components.FilterPicker = {
        init: init,
        open: openPicker,
        openFilter: openFilter,
        close: closePopover,
    };

    Lens.utils.onReady(init);
})();
