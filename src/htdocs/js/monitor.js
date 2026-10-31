/*
 * Live Traffic Monitor: polls the data endpoint (docs/data-contract.md) and renders the dashboard.
 *
 * Plesk pages load prototype.js, which patches built-ins. So this file avoids `$`, Array.from,
 * Array#entries, for...in over arrays and JSON.stringify, and builds all DOM with textContent.
 */
(function () {
    'use strict';

    // Plesk adds headScript() files to <head>, so wait until the page body exists.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    function init() {
        const root = document.getElementById('ltm-app');
        if (!root) {
            return;
        }

        const STORAGE_PREFIX = 'liveTrafficMonitor.';
        const MAX_BACKOFF_MS = 30000;
        const NO_VALUE = '—';
        // The filter select holds websites and containers in one list; the prefixes keep their values apart.
        const FILTER_DOMAIN = 'domain:';
        const FILTER_CONTAINER = 'container:';
        // Docker state -> [label, tone]. Tones: ok (green), warn (amber), bad (red), unknown (grey).
        const CONTAINER_STATES = {
            running: ['Running', 'ok'],
            restarting: ['Restarting', 'warn'],
            paused: ['Paused', 'warn'],
            created: ['Created', 'warn'],
            exited: ['Exited', 'bad'],
            dead: ['Dead', 'bad'],
            removing: ['Removing', 'bad'],
            missing: ['Missing', 'bad'],
        };
        const STATUS_TEXT = {
            200: 'OK', 201: 'Created', 202: 'Accepted', 204: 'No Content', 206: 'Partial Content',
            301: 'Moved Permanently', 302: 'Found', 303: 'See Other', 304: 'Not Modified',
            307: 'Temporary Redirect', 308: 'Permanent Redirect',
            400: 'Bad Request', 401: 'Unauthorized', 403: 'Forbidden', 404: 'Not Found',
            405: 'Method Not Allowed', 408: 'Request Timeout', 410: 'Gone', 413: 'Payload Too Large',
            414: 'URI Too Long', 416: 'Range Not Satisfiable', 429: 'Too Many Requests',
            499: 'Client Closed Request',
            500: 'Internal Server Error', 501: 'Not Implemented', 502: 'Bad Gateway',
            503: 'Service Unavailable', 504: 'Gateway Timeout',
        };

        // Charts: the status classes bottom to top, and the colour slots of the website lines.
        const SVG_NS = 'http://www.w3.org/2000/svg';
        const STATUS_CLASSES = ['2xx', '3xx', '4xx', '5xx', 'other'];
        const CLASS_LABELS = {
            '2xx': '2xx Success', '3xx': '3xx Redirect', '4xx': '4xx Client error', '5xx': '5xx Server error', other: 'Other',
        };
        const SITE_SLOTS = 5;

        const numberFormat = new Intl.NumberFormat();
        const rateFormat = new Intl.NumberFormat(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        const axisFormat = new Intl.NumberFormat(undefined, { maximumFractionDigits: 2 });

        // --- Setup ---------------------------------------------------------------------------------

        const el = {
            windowGroup: document.getElementById('ltm-window'),
            filter: document.getElementById('ltm-filter'),
            pause: document.getElementById('ltm-pause'),
            status: document.getElementById('ltm-status'),
            statusText: document.getElementById('ltm-status-text'),
            error: document.getElementById('ltm-error'),
            content: document.getElementById('ltm-content'),
            sites: document.getElementById('ltm-sites'),
            statuses: document.getElementById('ltm-statuses'),
            charts: document.getElementById('ltm-charts'),
            legendRequests: document.getElementById('ltm-legend-requests'),
            legendSites: document.getElementById('ltm-legend-sites'),
            footBandwidth: document.getElementById('ltm-foot-bandwidth'),
            footRt: document.getElementById('ltm-foot-rt'),
            dockerPanel: document.getElementById('ltm-containers-panel'),
            containersNote: document.getElementById('ltm-containers-note'),
            dockerWarning: document.getElementById('ltm-docker-warning'),
            containers: document.getElementById('ltm-containers'),
            requests: document.getElementById('ltm-requests'),
            footer: document.getElementById('ltm-footer'),
        };

        const config = readConfig();
        if (!config) {
            showError('The page configuration is missing or invalid. Reload the page.');
            return;
        }

        const displayNames = new Map(config.domains.map((d) => [d.name, d.displayName]));
        const containerNames = new Set(config.containers.map((c) => c.name));
        const cards = {};
        ['total', 'rps', '2xx', '3xx', '4xx', '5xx'].forEach((stat) => {
            const card = root.querySelector('[data-stat="' + stat + '"]');
            cards[stat] = { value: card.querySelector('.ltm-card-value'), sub: card.querySelector('.ltm-card-sub') };
        });
        const siteRows = new Map();
        const containerRows = new Map();
        const legendRows = new Map();
        const siteSlots = new Map(); // domain -> colour slot of its line; a website keeps its colour while it stays listed
        const charts = {
            requests: createChart(document.getElementById('ltm-chart-requests'), 'stacked', rateUnits),
            bandwidth: createChart(document.getElementById('ltm-chart-bandwidth'), 'area', byteUnits),
            sites: createChart(document.getElementById('ltm-chart-sites'), 'lines', rateUnits),
            rt: createChart(document.getElementById('ltm-chart-rt'), 'lines', durationUnits),
        };

        const state = {
            windowSec: config.defaultWindow,
            domain: 'all',     // Only one of domain and container is not 'all' at a time.
            container: 'all',
            paused: false,
            seq: 0,           // bumped whenever pending work is cancelled; stale responses are ignored
            controller: null, // AbortController of the request in flight
            timer: null,
            failures: 0,
            newestTs: null,   // newest row already shown, to highlight what arrived since
            rowsKey: null,    // fingerprint of the rendered rows, to skip identical re-renders
        };

        // Shown from the start only when there is something to show; each payload then decides.
        el.dockerPanel.hidden = !config.containers.length;
        // Closed unless the administrator opened it last time; the header still says when something is wrong.
        el.dockerPanel.open = storageGet('containersOpen') === '1';

        restoreFilters();
        buildControls();
        syncControls();
        bindEvents();
        if (!document.hidden) {
            poll();
        }

        function readConfig() {
            try {
                const raw = JSON.parse(root.dataset.config);
                const windows = Array.isArray(raw.windows) && raw.windows.length ? raw.windows.map(Number) : [60, 120, 300];
                return {
                    dataUrl: String(raw.dataUrl),
                    domains: Array.isArray(raw.domains) ? raw.domains : [],
                    containers: Array.isArray(raw.containers)
                        ? raw.containers
                            .filter((c) => c && typeof c.name === 'string')
                            .map((c) => ({ name: c.name, domains: Array.isArray(c.domains) ? c.domains.map(String) : [] }))
                        : [],
                    windows: windows,
                    defaultWindow: windows.indexOf(Number(raw.defaultWindow)) >= 0 ? Number(raw.defaultWindow) : windows[0],
                    pollMs: Math.max(1000, Number(raw.pollMs) || 3000),
                };
            } catch (e) {
                return null;
            }
        }

        function storageGet(key) {
            try {
                return window.localStorage.getItem(STORAGE_PREFIX + key);
            } catch (e) {
                return null;
            }
        }

        function storageSet(key, value) {
            try {
                window.localStorage.setItem(STORAGE_PREFIX + key, String(value));
            } catch (e) {
                // Storage is optional (private mode, blocked cookies).
            }
        }

        function restoreFilters() {
            const savedWindow = Number(storageGet('window'));
            if (config.windows.indexOf(savedWindow) >= 0) {
                state.windowSec = savedWindow;
            }
            const savedDomain = storageGet('domain');
            if (savedDomain && displayNames.has(savedDomain)) {
                state.domain = savedDomain;
            }
            const savedContainer = storageGet('container');
            if (state.domain === 'all' && savedContainer && containerNames.has(savedContainer)) {
                state.container = savedContainer;
            }
        }

        function buildControls() {
            config.windows.forEach((seconds) => {
                const button = h('button', 'ltm-segment', formatWindow(seconds));
                button.type = 'button';
                button.dataset.window = String(seconds);
                el.windowGroup.appendChild(button);
            });

            el.filter.appendChild(new Option('All websites', 'all'));
            appendFilterGroup(
                'Websites',
                config.domains
                    .slice()
                    .sort((a, b) => a.displayName.localeCompare(b.displayName))
                    .map((d) => new Option(d.displayName, FILTER_DOMAIN + d.name))
            );
            appendFilterGroup('Docker containers', config.containers.map((c) => new Option(c.name, FILTER_CONTAINER + c.name)));
        }

        function appendFilterGroup(label, options) {
            if (!options.length) {
                return;
            }
            const group = h('optgroup');
            group.label = label;
            options.forEach((option) => group.appendChild(option));
            el.filter.appendChild(group);
        }

        function filterValue() {
            if (state.container !== 'all') {
                return FILTER_CONTAINER + state.container;
            }
            return state.domain !== 'all' ? FILTER_DOMAIN + state.domain : 'all';
        }

        function syncControls() {
            el.windowGroup.querySelectorAll('button').forEach((button) => {
                const active = Number(button.dataset.window) === state.windowSec;
                button.classList.toggle('is-active', active);
                button.setAttribute('aria-pressed', active ? 'true' : 'false');
            });
            el.filter.value = filterValue();
            el.pause.textContent = state.paused ? 'Resume' : 'Pause';
            el.pause.classList.toggle('is-paused', state.paused);
        }

        function bindEvents() {
            el.windowGroup.addEventListener('click', (event) => {
                const button = event.target.closest('button[data-window]');
                if (button) {
                    setFilters({ windowSec: Number(button.dataset.window) });
                }
            });
            el.filter.addEventListener('change', () => {
                const value = el.filter.value;
                if (value.indexOf(FILTER_CONTAINER) === 0) {
                    setFilters({ container: value.slice(FILTER_CONTAINER.length) });
                } else if (value.indexOf(FILTER_DOMAIN) === 0) {
                    setFilters({ domain: value.slice(FILTER_DOMAIN.length) });
                } else {
                    setFilters({ domain: 'all', container: 'all' });
                }
            });
            el.sites.addEventListener('click', (event) => {
                const row = event.target.closest('tr[data-domain]');
                if (!row) {
                    return;
                }
                // The "All websites" row clears the container filter too.
                setFilters(row.dataset.domain === 'all' ? { domain: 'all', container: 'all' } : { domain: row.dataset.domain });
            });
            el.legendSites.addEventListener('click', (event) => {
                const button = event.target.closest('button[data-domain]');
                if (button) {
                    // Clicking the selected website again goes back to all websites.
                    setFilters({ domain: button.dataset.domain === state.domain ? 'all' : button.dataset.domain });
                }
            });
            el.dockerPanel.addEventListener('toggle', () => {
                storageSet('containersOpen', el.dockerPanel.open ? '1' : '0');
            });
            el.containers.addEventListener('click', (event) => {
                const row = event.target.closest('tr[data-container]');
                if (row && !row.classList.contains('is-static')) {
                    // Clicking the selected container again goes back to everything.
                    setFilters({ container: row.dataset.container === state.container ? 'all' : row.dataset.container });
                }
            });
            el.pause.addEventListener('click', () => {
                state.paused = !state.paused;
                syncControls();
                if (state.paused) {
                    cancelPending();
                    setStatus('paused', 'Paused');
                } else {
                    state.failures = 0;
                    poll();
                }
            });
            document.addEventListener('visibilitychange', () => {
                if (document.hidden) {
                    cancelPending();
                } else if (!state.paused) {
                    poll();
                }
            });
        }

        function setFilters(changes) {
            const windowSec = changes.windowSec !== undefined ? changes.windowSec : state.windowSec;
            let domain = changes.domain !== undefined ? changes.domain : state.domain;
            let container = changes.container !== undefined ? changes.container : state.container;
            // One filter at a time: whichever was just chosen replaces the other.
            if (changes.container !== undefined && container !== 'all') {
                domain = 'all';
            } else if (changes.domain !== undefined && domain !== 'all') {
                container = 'all';
            }
            if (
                config.windows.indexOf(windowSec) < 0
                || (domain !== 'all' && !displayNames.has(domain))
                || (container !== 'all' && !containerNames.has(container))
            ) {
                return;
            }
            if (windowSec === state.windowSec && domain === state.domain && container === state.container) {
                return;
            }
            state.windowSec = windowSec;
            state.domain = domain;
            state.container = container;
            state.failures = 0;
            state.newestTs = null;
            state.rowsKey = null;
            storageSet('window', windowSec);
            storageSet('domain', domain);
            storageSet('container', container);
            syncControls();
            poll(); // Also while paused: one fetch so the view matches the controls.
        }

        // --- Polling -------------------------------------------------------------------------------

        function cancelPending() {
            window.clearTimeout(state.timer);
            state.timer = null;
            if (state.controller) {
                state.controller.abort();
                state.controller = null;
            }
            state.seq += 1;
        }

        async function poll() {
            cancelPending();
            const seq = state.seq;
            const controller = new AbortController();
            state.controller = controller;
            let delay = config.pollMs;

            try {
                const payload = await fetchPayload(controller.signal);
                if (seq !== state.seq) {
                    return;
                }
                state.failures = 0;
                render(payload);
                clearError();
                setStatus(state.paused ? 'paused' : 'live', state.paused ? 'Paused' : 'Live');
            } catch (error) {
                if (seq !== state.seq) {
                    return;
                }
                state.failures += 1;
                delay = Math.min(MAX_BACKOFF_MS, config.pollMs * Math.pow(2, state.failures - 1));
                const retrying = !state.paused && !document.hidden;
                showError(error.message + (retrying ? ' Retrying in ' + Math.round(delay / 1000) + ' s.' : ''));
                setStatus('error', 'Connection problem');
            }

            state.controller = null;
            if (!state.paused && !document.hidden) {
                state.timer = window.setTimeout(poll, delay);
            }
        }

        async function fetchPayload(signal) {
            const url = new URL(config.dataUrl, window.location.href);
            url.searchParams.set('window', state.windowSec);
            url.searchParams.set('domain', state.domain);
            url.searchParams.set('container', state.container);

            const headers = { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
            const token = document.querySelector('meta[name="forgery_protection_token"]');
            if (token && token.content) {
                headers['X-Forgery-Protection-Token'] = token.content;
            }

            let response;
            try {
                response = await fetch(url.toString(), { credentials: 'same-origin', headers: headers, signal: signal });
            } catch (error) {
                if (error.name === 'AbortError') {
                    throw error;
                }
                throw new Error('Cannot reach the server.');
            }

            let payload = null;
            try {
                payload = await response.json();
            } catch (e) {
                // Not JSON, handled below.
            }
            if (response.ok && payload && payload.ok === true) {
                return payload;
            }
            if (payload && typeof payload.error === 'string' && payload.error) {
                throw new Error(payload.error);
            }
            if (!response.ok) {
                throw new Error(('HTTP ' + response.status + ' ' + response.statusText).trim());
            }
            throw new Error('Unexpected response from the server. Your session may have expired; reload the page.');
        }

        function setStatus(kind, text) {
            el.status.dataset.state = kind;
            el.statusText.textContent = text;
            el.status.title = kind === 'live' ? 'Refreshes every ' + (config.pollMs / 1000) + ' s' : '';
        }

        function showError(message) {
            el.error.textContent = message;
            el.error.hidden = false;
            el.content.classList.add('is-stale');
        }

        function clearError() {
            el.error.hidden = true;
            el.error.textContent = '';
            el.content.classList.remove('is-stale');
        }

        // --- Rendering -----------------------------------------------------------------------------

        function render(p) {
            (p.byDomain || []).forEach((d) => {
                if (d.displayName) {
                    displayNames.set(d.domain, d.displayName);
                }
            });
            renderSummary(p);
            renderCharts(p);
            renderSites(p);
            renderStatuses(p);
            renderContainers(p);
            renderRequests(p);
            renderFooter(p);
        }

        function renderSummary(p) {
            const classes = p.byStatusClass || {};
            const span = formatWindow(p.window || state.windowSec);
            setCard('total', numberFormat.format(p.total), 'last ' + span);
            setCard('rps', rateFormat.format(p.rps), 'average over ' + span);
            ['2xx', '3xx', '4xx', '5xx'].forEach((name) => {
                const count = classes[name] || 0;
                setCard(name, numberFormat.format(count), formatPercent(count, p.total) + ' of requests');
            });
        }

        function setCard(name, value, sub) {
            cards[name].value.textContent = value;
            cards[name].sub.textContent = sub;
        }

        // Rows are updated in place (not rebuilt) so a click is never lost to a poll landing mid-click.
        function renderSites(p) {
            const sites = (p.byDomain || []).slice().sort((a, b) => b.count - a.count || a.domain.localeCompare(b.domain));
            const sum = sites.reduce((n, site) => n + site.count, 0);
            const links = dockerLinks(p);
            const wanted = [{ domain: 'all', displayName: 'All websites', count: sum, share: null, containers: null }];
            sites.forEach((site) => {
                wanted.push({
                    domain: site.domain,
                    displayName: displayNames.get(site.domain) || site.domain,
                    count: site.count,
                    share: sum ? site.count / sum : 0,
                    containers: links.get(site.domain) || null,
                });
            });
            syncRows(el.sites, siteRows, wanted, 'domain', createSiteRow, updateSiteRow);
        }

        // Which Docker containers each website is proxied to: domain -> sorted container names.
        function dockerLinks(p) {
            const list = Array.isArray(p.byContainer)
                ? p.byContainer.map((c) => ({ name: c.container, domains: c.domains }))
                : config.containers; // The payload doesn't say (yet); the page config does.
            const links = new Map();
            list.forEach((c) => {
                (c.domains || []).forEach((domain) => {
                    const names = links.get(domain) || [];
                    names.push(c.name);
                    links.set(domain, names);
                });
            });
            links.forEach((names) => names.sort());
            return links;
        }

        // Keeps one <tr> per item (keyed by item[keyName]) and only reorders the table when the order changed.
        function syncRows(body, rows, wanted, keyName, create, update) {
            const ordered = wanted.map((item) => {
                let row = rows.get(item[keyName]);
                if (!row) {
                    row = create(item[keyName]);
                    rows.set(item[keyName], row);
                }
                update(row, item);
                return row.tr;
            });
            const keep = new Set(wanted.map((item) => item[keyName]));
            rows.forEach((row, key) => {
                if (!keep.has(key)) {
                    rows.delete(key);
                }
            });

            const current = body.children;
            const inOrder = current.length === ordered.length && ordered.every((tr, i) => tr === current[i]);
            if (!inOrder) {
                body.replaceChildren(...ordered);
            }
        }

        function createSiteRow(domain) {
            const tr = h('tr');
            tr.dataset.domain = domain;
            const nameCell = h('td', 'ltm-site');
            const button = h('button', 'ltm-link');
            button.type = 'button';
            const tag = h('span', 'ltm-tag', 'Docker');
            tag.hidden = true;
            const name = h('div', 'ltm-name');
            name.append(button, tag);
            nameCell.appendChild(name);
            const countCell = h('td', 'ltm-num');
            const fill = h('span');
            const bar = h('span', 'ltm-bar');
            bar.appendChild(fill);
            const pct = h('span', 'ltm-share-pct');
            const share = h('div', 'ltm-share');
            share.append(bar, pct);
            const shareCell = h('td');
            shareCell.appendChild(share);
            tr.append(nameCell, countCell, shareCell);
            return { tr: tr, button: button, tag: tag, countCell: countCell, bar: bar, fill: fill, pct: pct };
        }

        function updateSiteRow(row, site) {
            // "All websites" is the selection only when no container filter is active either.
            const selected = site.domain === 'all' ? state.domain === 'all' && state.container === 'all' : site.domain === state.domain;
            row.tr.classList.toggle('is-selected', selected);
            row.button.textContent = site.displayName;
            row.button.title = site.domain === 'all' ? 'Show all websites' : site.domain;
            row.button.setAttribute('aria-pressed', selected ? 'true' : 'false');
            row.tag.hidden = !site.containers;
            row.tag.title = site.containers
                ? 'Proxied to Docker container' + (site.containers.length === 1 ? ' ' : 's ') + site.containers.join(', ')
                : '';
            row.countCell.textContent = numberFormat.format(site.count);
            row.bar.hidden = site.share === null;
            row.fill.style.width = site.share === null ? '0' : (site.share * 100).toFixed(1) + '%';
            row.pct.textContent = site.share === null ? '' : formatPercent(site.share, 1);
        }

        function renderStatuses(p) {
            const list = document.createDocumentFragment();
            const items = p.byStatus || [];
            if (!items.length) {
                list.appendChild(h('li', 'ltm-empty', 'No requests in this window.'));
            }
            items.forEach((item) => {
                const klass = statusClass(item.status);
                const li = h('li');
                const bar = h('span', 'ltm-bar ltm-bar--' + klass);
                const fill = h('span');
                fill.style.width = (p.total ? Math.min(100, item.count / p.total * 100) : 0).toFixed(1) + '%';
                bar.appendChild(fill);
                li.append(
                    statusBadge(item.status),
                    h('span', 'ltm-status-name', STATUS_TEXT[item.status] || ''),
                    h('span', 'ltm-num', numberFormat.format(item.count)),
                    h('span', 'ltm-share-pct', formatPercent(item.count, p.total)),
                    bar
                );
                list.appendChild(li);
            });
            el.statuses.replaceChildren(list);
        }

        // Hidden while no website has a Docker proxy rule (docker is null). Updated in place like the sites.
        function renderContainers(p) {
            el.dockerPanel.hidden = !p.docker;
            if (!p.docker) {
                return;
            }
            const warning = p.docker.available === false
                ? 'Docker is not reachable' + (p.docker.error ? ': ' + String(p.docker.error).replace(/[.\s]+$/, '') : '') + '. Container states are unknown.'
                : '';
            if (el.dockerWarning.textContent !== warning) {
                el.dockerWarning.textContent = warning;
            }
            el.dockerWarning.hidden = !warning;
            renderContainersNote(p);

            const wanted = (p.byContainer || []).map((c) => ({
                container: c.container,
                image: c.image,
                state: c.state,
                health: c.health,
                status: c.status,
                websites: (c.domains || []).map((domain) => displayNames.get(domain) || domain),
                count: c.count || 0,
                errors: c.errors || 0,
            }));
            syncRows(el.containers, containerRows, wanted, 'container', createContainerRow, updateContainerRow);
        }

        function createContainerRow(name) {
            const tr = h('tr');
            tr.dataset.container = name;
            const button = h('button', 'ltm-link');
            button.type = 'button';
            const nameCell = h('td', 'ltm-site');
            nameCell.appendChild(button);
            const dot = h('span', 'ltm-state-dot');
            dot.setAttribute('aria-hidden', 'true');
            const label = h('span');
            const stateWrap = h('span', 'ltm-state');
            stateWrap.append(dot, label);
            const stateCell = h('td');
            stateCell.appendChild(stateWrap);
            const imageCell = h('td', 'ltm-mono ltm-muted');
            const websitesCell = h('td', 'ltm-domains');
            const countCell = h('td', 'ltm-num');
            const errorsCell = h('td', 'ltm-num');
            tr.append(nameCell, stateCell, imageCell, websitesCell, countCell, errorsCell);
            return {
                tr: tr, button: button, stateWrap: stateWrap, stateCell: stateCell, label: label, imageCell: imageCell,
                websitesCell: websitesCell, countCell: countCell, errorsCell: errorsCell,
            };
        }

        function updateContainerRow(row, c) {
            const selected = c.container === state.container;
            // Only containers from the page config can be used as a filter (the endpoint rejects others).
            const filterable = containerNames.has(c.container);
            row.tr.classList.toggle('is-selected', selected);
            row.tr.classList.toggle('is-static', !filterable);
            row.button.textContent = c.container;
            row.button.disabled = !filterable;
            row.button.title = !filterable ? c.container + ' (no longer has a Docker proxy rule)'
                : selected ? 'Show all requests again' : 'Show requests answered by ' + c.container;
            row.button.setAttribute('aria-pressed', selected ? 'true' : 'false');

            const display = containerState(c.state, c.health);
            row.stateWrap.className = 'ltm-state ltm-state--' + display.tone;
            row.label.textContent = display.label;
            row.stateCell.title = c.status || '';
            row.imageCell.textContent = c.image || NO_VALUE;
            const websites = c.websites.join(', ');
            row.websitesCell.textContent = websites || NO_VALUE;
            row.websitesCell.title = websites;
            row.countCell.textContent = numberFormat.format(c.count);
            row.errorsCell.textContent = numberFormat.format(c.errors);
            row.errorsCell.classList.toggle('ltm-err', c.errors > 0);
        }

        // The closed accordion's header: how many containers, and whether any of them needs a look.
        function renderContainersNote(p) {
            const list = p.byContainer || [];
            const parts = [plural(list.length, 'container')];
            let tone = '';
            if (p.docker.available === false) {
                parts.push('Docker not reachable');
                tone = 'warn';
            } else {
                let stopped = 0;
                let unhealthy = 0;
                list.forEach((c) => {
                    if (c.state !== 'running') {
                        stopped += 1;
                    } else if (c.health === 'unhealthy') {
                        unhealthy += 1;
                    }
                });
                if (stopped) {
                    parts.push(numberFormat.format(stopped) + ' not running');
                }
                if (unhealthy) {
                    parts.push(numberFormat.format(unhealthy) + ' unhealthy');
                }
                tone = stopped || unhealthy ? 'bad' : '';
            }
            el.containersNote.textContent = parts.join(' · ');
            el.containersNote.dataset.tone = tone;
        }

        // Label and colour of a container's state. The label carries the meaning; the dot only echoes it.
        function containerState(dockerState, health) {
            const known = CONTAINER_STATES[dockerState];
            if (!known) {
                return { label: 'Unknown', tone: 'unknown' };
            }
            if (dockerState !== 'running' || !health) {
                return { label: known[0], tone: known[1] };
            }
            if (health === 'healthy') {
                return { label: 'Running · healthy', tone: 'ok' };
            }
            if (health === 'unhealthy') {
                return { label: 'Running · unhealthy', tone: 'bad' };
            }
            return health === 'starting' ? { label: 'Starting', tone: 'warn' } : { label: known[0], tone: known[1] };
        }

        function renderRequests(p) {
            const rows = p.rows || [];
            const first = rows[0];
            const last = rows[rows.length - 1];
            // Newest/oldest row plus the count identify an unchanged list; skipping keeps text selection and hover.
            const key = rows.length ? [rows.length, first.ts, first.path, last.ts, last.path].join('|') : 'empty';
            if (key === state.rowsKey) {
                return;
            }
            state.rowsKey = key;

            const fragment = document.createDocumentFragment();
            if (!rows.length) {
                const td = h('td', 'ltm-empty', 'No requests in this window.');
                td.colSpan = 8;
                const tr = h('tr');
                tr.appendChild(td);
                fragment.appendChild(tr);
            }
            rows.forEach((row) => {
                const tr = h('tr', state.newestTs !== null && row.ts > state.newestTs ? 'is-new' : '');
                tr.append(
                    cell('ltm-time', formatClock(row.ts), formatDateTime(row.ts)),
                    hostCell(row),
                    cell('ltm-mono', row.method || NO_VALUE),
                    cell('ltm-url ltm-mono', row.path || NO_VALUE, fullUrl(row)),
                    statusCell(row.status),
                    cell('ltm-mono', row.ip || NO_VALUE),
                    cell('ltm-num', formatDuration(row.rt)),
                    cell('ltm-num', formatBytes(row.bytes))
                );
                fragment.appendChild(tr);
            });
            if (rows.length) {
                state.newestTs = first.ts;
            }
            el.requests.replaceChildren(fragment);
        }

        function cell(className, text, title) {
            const td = h('td', className, text);
            if (title) {
                td.title = title;
            }
            return td;
        }

        // The website, plus the Docker container that answered when there is one. Only the name gets truncated.
        function hostCell(row) {
            const name = displayNames.get(row.domain) || row.domain;
            if (!row.container) {
                return cell('ltm-host', name, row.domain);
            }
            const tag = h('span', 'ltm-tag', row.container);
            tag.title = 'Answered by Docker container ' + row.container;
            const line = h('div', 'ltm-name');
            line.append(h('span', 'ltm-name-text', name), tag);
            const td = h('td', 'ltm-host');
            td.title = row.domain;
            td.appendChild(line);
            return td;
        }

        function statusCell(status) {
            const td = h('td');
            td.appendChild(statusBadge(status));
            return td;
        }

        function statusBadge(status) {
            const badge = h('span', 'ltm-badge ltm-badge--' + statusClass(status), String(status));
            if (STATUS_TEXT[status]) {
                badge.title = STATUS_TEXT[status];
            }
            return badge;
        }

        function fullUrl(row) {
            return row.scheme ? row.scheme + '://' + row.domain + (row.path || '') : row.path;
        }

        function renderFooter(p) {
            const items = [];
            const shown = (p.rows || []).length;
            if (p.truncated) {
                items.push([null, 'Showing newest ' + numberFormat.format(shown) + ' of ' + numberFormat.format(p.total) + ' requests']);
            }
            items.push([null, 'Updated ' + formatClock(p.generatedAt)]);
            if (p.lagging) {
                items.push(['ltm-flag', 'Catching up with log backlog…']);
            }
            if (p.parseErrors > 0) {
                items.push(['ltm-flag', plural(p.parseErrors, 'log line') + ' could not be parsed']);
            }
            const unreadable = p.files && p.files.unreadable;
            if (unreadable > 0) {
                items.push(['ltm-flag', plural(unreadable, 'log file') + ' could not be read']);
            }
            el.footer.replaceChildren(...items.map((item) => h('span', item[0], item[1])));
        }

        // --- Charts --------------------------------------------------------------------------------

        // The four charts share the window's buckets (docs/data-contract.md, "timeline"). Y values are
        // per second, so a scale means the same in every window.
        function renderCharts(p) {
            const t = p.timeline;
            el.charts.hidden = !t;
            if (!t) {
                return;
            }
            const n = t.bytes.length;
            const bucket = t.bucket;
            const span = formatWindow(n * bucket);
            const totals = new Array(n).fill(0);
            STATUS_CLASSES.forEach((name) => {
                (t.byStatusClass[name] || []).forEach((count, i) => {
                    totals[i] += count;
                });
            });
            const total = sum(totals);
            const noRequests = total ? '' : 'No requests in this window.';
            const range = (i) => formatClock(t.from + i * bucket) + ' – ' + formatClock(t.from + (i + 1) * bucket);

            // Requests per second by status. "Other" (1xx, invalid) only appears when there is some.
            const classes = STATUS_CLASSES
                .filter((name) => name !== 'other' || sum(t.byStatusClass.other || []) > 0)
                .map((name) => ({ key: name, tone: name, label: CLASS_LABELS[name], counts: t.byStatusClass[name] || new Array(n).fill(0) }));
            const peakRate = Math.max.apply(null, totals) / bucket;
            charts.requests.update({
                from: t.from,
                bucket: bucket,
                series: classes.map((c) => ({ tone: c.tone, values: c.counts.map((count) => count / bucket) })),
                empty: noRequests,
                label: 'Requests per second by status over the last ' + span + ': peak ' + rateFormat.format(peakRate)
                    + ', average ' + rateFormat.format(total / (n * bucket)) + '.',
                tooltip: (i) => ({
                    title: range(i),
                    rows: classes.map((c) => ({ tone: c.tone, value: numberFormat.format(c.counts[i]), label: c.label })),
                    foot: plural(totals[i], 'request') + ' · ' + rateFormat.format(totals[i] / bucket) + '/s',
                }),
            });
            syncLegend(el.legendRequests, classes.map((c) => c.key).join(), () => classes.map((c) => legendItem(c.tone, c.label)));

            // Bandwidth: response bytes per second.
            const bytesSent = sum(t.bytes);
            const peakBytes = Math.max.apply(null, t.bytes) / bucket;
            charts.bandwidth.update({
                from: t.from,
                bucket: bucket,
                series: [{ tone: 'accent', values: t.bytes.map((b) => b / bucket) }],
                empty: noRequests,
                label: 'Bandwidth over the last ' + span + ': ' + formatBytes(bytesSent) + ' sent, peak '
                    + formatBytes(Math.round(peakBytes)) + ' per second.',
                tooltip: (i) => ({
                    title: range(i),
                    rows: [{ tone: 'accent', value: formatBytes(Math.round(t.bytes[i] / bucket)) + '/s', label: 'sent' }],
                    foot: formatBytes(t.bytes[i]) + ' in ' + formatWindow(bucket),
                }),
            });
            el.footBandwidth.textContent = total
                ? formatBytes(bytesSent) + ' sent in ' + span + ' · peak ' + formatBytes(Math.round(peakBytes)) + '/s'
                : '';

            // The busiest websites, one line each. A website filter brings its line forward.
            const sites = t.byDomain || [];
            const slots = assignSlots(sites.map((s) => s.domain));
            const listed = sites.some((s) => s.domain === state.domain);
            const lines = sites.map((s) => ({
                domain: s.domain,
                name: displayNames.get(s.domain) || s.domain,
                tone: 's' + slots.get(s.domain),
                counts: s.counts,
                selected: s.domain === state.domain,
            }));
            charts.sites.update({
                from: t.from,
                bucket: bucket,
                series: lines.map((l) => ({
                    tone: l.tone,
                    values: l.counts.map((count) => count / bucket),
                    dim: listed && !l.selected,
                    strong: l.selected,
                })),
                empty: lines.length ? '' : noRequests,
                label: 'Requests per second of the busiest websites over the last ' + span + ': '
                    + lines.map((l) => l.name).join(', ') + '.',
                tooltip: (i) => ({
                    title: range(i),
                    rows: lines.map((l) => ({ tone: l.tone, value: numberFormat.format(l.counts[i]), label: l.name })),
                    foot: 'requests in ' + formatWindow(bucket),
                }),
            });
            syncRows(el.legendSites, legendRows, lines, 'domain', createSiteLegend, updateSiteLegend);

            // Average response time; buckets where nothing was timed are gaps.
            const timed = t.rt.filter((v) => typeof v === 'number');
            charts.rt.update({
                from: t.from,
                bucket: bucket,
                series: [{ tone: 'accent', values: t.rt }],
                empty: timed.length ? ''
                    : 'No response times in this window. Plesk\'s default log format doesn\'t record them.',
                label: timed.length
                    ? 'Average response time over the last ' + span + ', from ' + formatDuration(Math.min.apply(null, timed))
                        + ' to ' + formatDuration(Math.max.apply(null, timed)) + '.'
                    : 'No response times in this window.',
                tooltip: (i) => ({
                    title: range(i),
                    rows: typeof t.rt[i] === 'number' ? [{ tone: 'accent', value: formatDuration(t.rt[i]), label: 'average' }] : [],
                    foot: typeof t.rt[i] === 'number' ? '' : 'No timed requests',
                }),
            });
            el.footRt.textContent = timed.length
                ? 'Averages from ' + formatDuration(Math.min.apply(null, timed)) + ' to ' + formatDuration(Math.max.apply(null, timed))
                : '';
        }

        // Colour slots 1..5 for the listed websites. A website keeps its slot while it stays listed, so a
        // change in the ranking never repaints the others; a newcomer takes the lowest free slot.
        function assignSlots(domains) {
            const result = new Map();
            const taken = new Set();
            domains.forEach((domain) => {
                const slot = siteSlots.get(domain);
                if (slot && !taken.has(slot)) {
                    taken.add(slot);
                    result.set(domain, slot);
                }
            });
            domains.forEach((domain) => {
                if (result.has(domain)) {
                    return;
                }
                let slot = 1;
                while (taken.has(slot) && slot < SITE_SLOTS) {
                    slot += 1;
                }
                taken.add(slot);
                result.set(domain, slot);
                siteSlots.forEach((owned, other) => {
                    if (owned === slot) {
                        siteSlots.delete(other);
                    }
                });
                siteSlots.set(domain, slot);
            });
            return result;
        }

        function legendItem(tone, label) {
            const li = h('li', 'ltm-legend-item');
            li.append(h('span', 'ltm-swatch ltm-tone-' + tone), h('span', 'ltm-legend-label', label));
            return li;
        }

        // Rebuilds a static legend only when its entries change.
        function syncLegend(list, key, build) {
            if (list.dataset.key !== key) {
                list.dataset.key = key;
                list.replaceChildren(...build());
            }
        }

        // The website legend doubles as a filter, so its buttons are updated in place like the table rows.
        function createSiteLegend(domain) {
            const li = h('li', 'ltm-legend-item');
            const button = h('button', 'ltm-link ltm-legend-button');
            button.type = 'button';
            button.dataset.domain = domain;
            const swatch = h('span', 'ltm-swatch ltm-swatch--line');
            const label = h('span', 'ltm-legend-label');
            button.append(swatch, label);
            li.appendChild(button);
            return { tr: li, button: button, swatch: swatch, label: label };
        }

        function updateSiteLegend(row, line) {
            row.swatch.className = 'ltm-swatch ltm-swatch--line ltm-tone-' + line.tone;
            row.label.textContent = line.name;
            row.button.title = line.selected ? 'Show all websites' : 'Show only ' + line.name;
            row.button.setAttribute('aria-pressed', line.selected ? 'true' : 'false');
        }

        // Axis units: the factor ticks are divided by, and the suffix of their labels.
        function rateUnits() {
            return { factor: 1, suffix: '' };
        }

        function byteUnits(max) {
            const units = ['B', 'KB', 'MB', 'GB', 'TB'];
            let i = 0;
            while (max >= Math.pow(1024, i + 1) && i < units.length - 1) {
                i += 1;
            }
            return { factor: Math.pow(1024, i), suffix: ' ' + units[i] };
        }

        function durationUnits(max) {
            return max >= 1 ? { factor: 1, suffix: ' s' } : { factor: 0.001, suffix: ' ms' };
        }

        // One SVG chart over a window's buckets. kind is 'stacked' (columns), 'area' (one series) or
        // 'lines'. update() takes {from, bucket, series: [{tone, values, dim, strong}], empty, label, tooltip(i)}.
        function createChart(host, kind, units) {
            const HEIGHT = 180;
            const PAD = { top: 12, right: 12, bottom: 26 };
            const chart = { data: null, geo: null, hover: null, width: 0, update: update };

            const svg = svgEl('svg', { class: 'ltm-chart-svg', 'aria-hidden': 'true', height: HEIGHT });
            const band = svgEl('rect', { class: 'ltm-chart-band', display: 'none' });
            const grid = svgEl('g', { class: 'ltm-chart-grid' });
            const marks = svgEl('g');
            const guide = svgEl('line', { class: 'ltm-chart-guide', display: 'none' });
            const dots = svgEl('g');
            const axis = svgEl('g', { class: 'ltm-chart-axis' });
            svg.append(band, grid, marks, guide, dots, axis);
            const empty = h('div', 'ltm-chart-empty');
            empty.hidden = true;
            const tip = h('div', 'ltm-tooltip');
            tip.hidden = true;
            host.append(svg, empty, tip);
            host.tabIndex = 0;
            host.setAttribute('role', 'img');

            host.addEventListener('pointermove', (event) => {
                const geo = chart.geo;
                if (!geo) {
                    return;
                }
                const x = event.clientX - svg.getBoundingClientRect().left;
                const i = Math.floor((x - geo.left) / geo.slot);
                setHover(i >= 0 && i < geo.n ? i : null);
            });
            host.addEventListener('pointerleave', () => setHover(null));
            host.addEventListener('focus', () => {
                if (chart.geo && host.matches(':focus-visible')) {
                    setHover(chart.geo.n - 1);
                }
            });
            host.addEventListener('blur', () => setHover(null));
            host.addEventListener('keydown', (event) => {
                const geo = chart.geo;
                const moves = { ArrowLeft: -1, ArrowRight: 1 };
                if (!geo || !(event.key in moves || event.key === 'Home' || event.key === 'End')) {
                    return;
                }
                event.preventDefault();
                const current = chart.hover === null ? geo.n - 1 : chart.hover;
                const next = event.key === 'Home' ? 0 : event.key === 'End' ? geo.n - 1 : current + moves[event.key];
                setHover(Math.max(0, Math.min(geo.n - 1, next)));
            });
            if (typeof ResizeObserver === 'function') {
                new ResizeObserver(() => {
                    if (host.clientWidth !== chart.width) {
                        draw();
                    }
                }).observe(host);
            } else {
                window.addEventListener('resize', draw);
            }
            return chart;

            function update(data) {
                chart.data = data;
                draw();
            }

            function setHover(i) {
                if (i !== chart.hover) {
                    chart.hover = i;
                    drawHover();
                }
            }

            function draw() {
                const data = chart.data;
                const width = host.clientWidth;
                chart.width = width;
                if (!data || !width) {
                    return;
                }
                host.setAttribute('aria-label', data.label);
                empty.textContent = data.empty;
                empty.hidden = !data.empty;

                const n = data.series.length ? data.series[0].values.length : 30;
                let max = 0;
                for (let i = 0; i < n; i++) {
                    let stack = 0;
                    data.series.forEach((s) => {
                        const v = s.values[i];
                        if (typeof v === 'number') {
                            stack = kind === 'stacked' ? stack + v : Math.max(stack, v);
                        }
                    });
                    max = Math.max(max, stack);
                }
                const scale = niceScale(max, units(max));
                const left = Math.ceil(Math.max.apply(null, scale.labels.map(textWidth))) + 14;
                const geo = {
                    n: n,
                    left: left,
                    top: PAD.top,
                    base: HEIGHT - PAD.bottom,
                    plotW: Math.max(1, width - left - PAD.right),
                    plotH: HEIGHT - PAD.top - PAD.bottom,
                    max: scale.max,
                };
                geo.slot = geo.plotW / n;
                geo.y = (v) => geo.base - (v / geo.max) * geo.plotH;
                geo.x = (i) => geo.left + geo.slot * (i + 0.5);
                chart.geo = geo;

                svg.setAttribute('width', width);
                svg.setAttribute('viewBox', '0 0 ' + width + ' ' + HEIGHT);
                drawGrid(geo, scale, data);
                marks.replaceChildren(...(kind === 'stacked' ? columns(geo, data.series) : lines(geo, data.series)));
                drawHover();
            }

            function drawGrid(geo, scale, data) {
                const nodes = [];
                scale.ticks.forEach((value, k) => {
                    const y = Math.round(geo.y(value)) + 0.5;
                    nodes.push(svgEl('line', { class: k === 0 ? 'ltm-chart-base' : '', x1: geo.left, x2: geo.left + geo.plotW, y1: y, y2: y }));
                });
                grid.replaceChildren(...nodes);

                // An empty chart keeps its frame but drops the y labels, whose scale would mean nothing.
                const labels = data.empty ? []
                    : scale.labels.map((label, k) => svgText(label, geo.left - 8, geo.y(scale.ticks[k]) + 4, 'end'));
                const windowSec = geo.n * data.bucket;
                xTicks(windowSec, geo.plotW).forEach((ago) => {
                    const x = geo.left + geo.plotW * (windowSec - ago) / windowSec;
                    const anchor = ago === windowSec ? 'start' : ago === 0 ? 'end' : 'middle';
                    labels.push(svgText(ago === 0 ? 'now' : formatWindow(ago) + ' ago', x, geo.base + 18, anchor));
                });
                axis.replaceChildren(...labels);
            }

            function drawHover() {
                const geo = chart.geo;
                const i = chart.hover;
                if (i === null || !geo || !chart.data || chart.data.empty) {
                    band.setAttribute('display', 'none');
                    guide.setAttribute('display', 'none');
                    dots.replaceChildren();
                    tip.hidden = true;
                    return;
                }
                const x = geo.x(i);
                if (kind === 'stacked') {
                    setAttrs(band, { x: geo.left + geo.slot * i, y: geo.top, width: geo.slot, height: geo.plotH, display: 'inline' });
                } else {
                    setAttrs(guide, { x1: x, x2: x, y1: geo.top, y2: geo.base, display: 'inline' });
                    dots.replaceChildren(...chart.data.series
                        .filter((s) => typeof s.values[i] === 'number' && !s.dim)
                        .map((s) => svgEl('circle', { class: 'ltm-chart-dot ltm-tone-' + s.tone, cx: x, cy: geo.y(s.values[i]), r: 4 })));
                }
                showTooltip(chart.data.tooltip(i), x, geo);
            }

            function showTooltip(content, x, geo) {
                const rows = h('ul', 'ltm-tooltip-rows');
                content.rows.forEach((row) => {
                    const li = h('li');
                    li.append(h('span', 'ltm-key ltm-tone-' + row.tone), h('strong', null, row.value), h('span', 'ltm-tooltip-label', row.label));
                    rows.appendChild(li);
                });
                tip.replaceChildren(h('div', 'ltm-tooltip-title', content.title));
                if (content.rows.length) {
                    tip.appendChild(rows);
                }
                if (content.foot) {
                    tip.appendChild(h('div', 'ltm-tooltip-foot', content.foot));
                }
                tip.hidden = false;
                // Beside the hovered bucket, on whichever side has room.
                const w = tip.offsetWidth;
                let left = x + 14;
                if (left + w > host.clientWidth - 4) {
                    left = x - 14 - w;
                }
                tip.style.left = Math.max(4, left) + 'px';
                tip.style.top = geo.top + 'px';
            }
        }

        // Stacked columns: 4px rounded tops, a 2px gap between segments, and at least 3px for any
        // non-zero segment so a single error stays visible.
        function columns(geo, series) {
            const GAP = 2;
            const width = Math.max(2, Math.min(24, geo.slot * 0.68));
            const nodes = [];
            for (let i = 0; i < geo.n; i++) {
                const x = geo.left + geo.slot * i + (geo.slot - width) / 2;
                const segments = [];
                series.forEach((s) => {
                    if (s.values[i] > 0) {
                        segments.push({ tone: s.tone, height: Math.max(3, s.values[i] / geo.max * geo.plotH) });
                    }
                });
                let bottom = geo.base;
                segments.forEach((seg, k) => {
                    const top = bottom - seg.height;
                    if (k === segments.length - 1) {
                        nodes.push(svgEl('path', { class: 'ltm-col ltm-tone-' + seg.tone, d: roundedTop(x, top, width, seg.height, 4) }));
                    } else {
                        // The gap is the top of this segment, under the next one.
                        const gap = Math.min(GAP, seg.height - 1);
                        nodes.push(svgEl('path', { class: 'ltm-col ltm-tone-' + seg.tone, d: rectPath(x, top + gap, width, seg.height - gap) }));
                    }
                    bottom = top;
                });
            }
            return nodes;
        }

        // Lines (2px) with gaps where a value is null; a lone point between gaps is drawn as a dot. A single
        // series also gets a light area wash. Dimmed series are drawn first, the selected one last.
        function lines(geo, series) {
            const nodes = [];
            const order = series.filter((s) => s.dim).concat(series.filter((s) => !s.dim && !s.strong), series.filter((s) => s.strong));
            order.forEach((s) => {
                const group = svgEl('g', { class: 'ltm-tone-' + s.tone + (s.dim ? ' is-dim' : '') + (s.strong ? ' is-strong' : '') });
                let run = [];
                const runs = [];
                s.values.forEach((v, i) => {
                    if (typeof v === 'number') {
                        run.push([geo.x(i), geo.y(v)]);
                    } else if (run.length) {
                        runs.push(run);
                        run = [];
                    }
                });
                if (run.length) {
                    runs.push(run);
                }
                runs.forEach((points) => {
                    if (points.length === 1) {
                        group.appendChild(svgEl('circle', { class: 'ltm-point', cx: points[0][0], cy: points[0][1], r: 2.5 }));
                        return;
                    }
                    const d = 'M' + points.map((pt) => pt[0].toFixed(1) + ',' + pt[1].toFixed(1)).join('L');
                    if (series.length === 1) {
                        const close = 'L' + points[points.length - 1][0].toFixed(1) + ',' + geo.base + 'L' + points[0][0].toFixed(1) + ',' + geo.base + 'Z';
                        group.appendChild(svgEl('path', { class: 'ltm-area', d: d + close }));
                    }
                    group.appendChild(svgEl('path', { class: 'ltm-line', d: d }));
                });
                nodes.push(group);
            });
            return nodes;
        }

        // A y scale with 3–4 round steps (1, 2, 2.5 or 5 × 10^n in the chosen unit), always at least one step.
        function niceScale(max, unit) {
            const top = max / unit.factor;
            const raw = top > 0 ? top / 3 : 1 / 3;
            const power = Math.pow(10, Math.floor(Math.log10(raw)));
            const f = raw / power;
            const step = (f <= 1 ? 1 : f <= 2 ? 2 : f <= 2.5 ? 2.5 : f <= 5 ? 5 : 10) * power;
            const count = Math.max(1, Math.ceil(top / step - 1e-9));
            const ticks = [];
            const labels = [];
            for (let k = 0; k <= count; k++) {
                ticks.push(k * step * unit.factor);
                labels.push(axisFormat.format(k * step) + unit.suffix);
            }
            return { max: count * step * unit.factor, ticks: ticks, labels: labels };
        }

        // Seconds-ago marks on the x axis, at least ~70px apart; the window's start and "now" always.
        // Windows longer than a minute are marked in whole minutes ("4 min ago", never "270 s ago").
        function xTicks(windowSec, plotW) {
            const steps = windowSec > 60 ? [60, 120, 300] : [15, 30, 60];
            let step = windowSec;
            for (let k = 0; k < steps.length; k++) {
                if (windowSec % steps[k] === 0 && plotW / (windowSec / steps[k]) >= 70) {
                    step = steps[k];
                    break;
                }
            }
            const ticks = [];
            for (let ago = windowSec; ago >= 0; ago -= step) {
                ticks.push(ago);
            }
            return ticks;
        }

        function rectPath(x, y, w, hgt) {
            return 'M' + x + ',' + y + 'h' + w + 'v' + hgt + 'h' + -w + 'Z';
        }

        function roundedTop(x, y, w, hgt, radius) {
            const r = Math.min(radius, w / 2, hgt);
            return 'M' + x + ',' + (y + hgt) + 'V' + (y + r) + 'Q' + x + ',' + y + ' ' + (x + r) + ',' + y
                + 'H' + (x + w - r) + 'Q' + (x + w) + ',' + y + ' ' + (x + w) + ',' + (y + r) + 'V' + (y + hgt) + 'Z';
        }

        function svgEl(tag, attrs) {
            return setAttrs(document.createElementNS(SVG_NS, tag), attrs || {});
        }

        function setAttrs(node, attrs) {
            Object.keys(attrs).forEach((name) => {
                if (attrs[name] !== '') {
                    node.setAttribute(name, attrs[name]);
                }
            });
            return node;
        }

        function svgText(text, x, y, anchor) {
            const node = svgEl('text', { x: x, y: y, 'text-anchor': anchor });
            node.textContent = text;
            return node;
        }

        // Width of an axis label in the page's font, to size the y axis.
        function textWidth(text) {
            if (!textWidth.context) {
                textWidth.context = document.createElement('canvas').getContext('2d');
                textWidth.context.font = '11px ' + window.getComputedStyle(root).fontFamily;
            }
            return textWidth.context.measureText(text).width;
        }

        function sum(values) {
            return values.reduce((total, v) => total + (typeof v === 'number' ? v : 0), 0);
        }

        // --- Helpers -------------------------------------------------------------------------------

        function h(tag, className, text) {
            const node = document.createElement(tag);
            if (className) {
                node.className = className;
            }
            if (text !== undefined && text !== null) {
                node.textContent = text;
            }
            return node;
        }

        function pad(n) {
            return n < 10 ? '0' + n : String(n);
        }

        function formatClock(ts) {
            const d = new Date(ts * 1000);
            return pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds());
        }

        function formatDateTime(ts) {
            const d = new Date(ts * 1000);
            return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + ' ' + formatClock(ts);
        }

        function formatWindow(seconds) {
            return seconds % 60 === 0 ? seconds / 60 + ' min' : seconds + ' s';
        }

        function formatPercent(part, whole) {
            if (!whole) {
                return '0%';
            }
            const value = part / whole * 100;
            return value > 0 && value < 1 ? '<1%' : Math.round(value) + '%';
        }

        function formatBytes(bytes) {
            if (typeof bytes !== 'number') {
                return NO_VALUE;
            }
            if (bytes < 1024) {
                return bytes + ' B';
            }
            const units = ['KB', 'MB', 'GB', 'TB'];
            let value = bytes;
            let unit = -1;
            do {
                value /= 1024;
                unit += 1;
            } while (value >= 1024 && unit < units.length - 1);
            return value.toFixed(value < 10 ? 1 : 0) + ' ' + units[unit];
        }

        function formatDuration(seconds) {
            if (typeof seconds !== 'number') {
                return NO_VALUE;
            }
            const ms = seconds * 1000;
            if (ms < 1) {
                return '<1 ms';
            }
            return ms < 1000 ? Math.round(ms) + ' ms' : (ms / 1000).toFixed(2) + ' s';
        }

        function plural(count, noun) {
            return numberFormat.format(count) + ' ' + noun + (count === 1 ? '' : 's');
        }

        function statusClass(status) {
            const family = Math.floor(status / 100);
            return family >= 2 && family <= 5 ? family + 'xx' : 'other';
        }
    }
}());
