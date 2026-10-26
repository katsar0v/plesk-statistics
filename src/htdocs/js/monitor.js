/*
 * Live Traffic Monitor: polls the data endpoint (docs/data-contract.md) and renders the dashboard.
 *
 * Plesk pages load prototype.js, which patches built-ins. So this file avoids `$`, Array.from,
 * Array#entries, for...in over arrays and JSON.stringify, and builds all DOM with textContent.
 */
(function () {
    'use strict';

    const root = document.getElementById('ltm-app');
    if (!root) {
        return;
    }

    const STORAGE_PREFIX = 'liveTrafficMonitor.';
    const MAX_BACKOFF_MS = 30000;
    const NO_VALUE = '—';
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

    const numberFormat = new Intl.NumberFormat();
    const rateFormat = new Intl.NumberFormat(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    // --- Setup ---------------------------------------------------------------------------------

    const el = {
        windowGroup: document.getElementById('ltm-window'),
        domain: document.getElementById('ltm-domain'),
        pause: document.getElementById('ltm-pause'),
        status: document.getElementById('ltm-status'),
        statusText: document.getElementById('ltm-status-text'),
        error: document.getElementById('ltm-error'),
        content: document.getElementById('ltm-content'),
        sites: document.getElementById('ltm-sites'),
        statuses: document.getElementById('ltm-statuses'),
        requests: document.getElementById('ltm-requests'),
        footer: document.getElementById('ltm-footer'),
    };

    const config = readConfig();
    if (!config) {
        showError('The page configuration is missing or invalid. Reload the page.');
        return;
    }

    const displayNames = new Map(config.domains.map((d) => [d.name, d.displayName]));
    const cards = {};
    ['total', 'rps', '2xx', '3xx', '4xx', '5xx'].forEach((stat) => {
        const card = root.querySelector('[data-stat="' + stat + '"]');
        cards[stat] = { value: card.querySelector('.ltm-card-value'), sub: card.querySelector('.ltm-card-sub') };
    });
    const siteRows = new Map();

    const state = {
        windowSec: config.defaultWindow,
        domain: 'all',
        paused: false,
        seq: 0,           // bumped whenever pending work is cancelled; stale responses are ignored
        controller: null, // AbortController of the request in flight
        timer: null,
        failures: 0,
        newestTs: null,   // newest row already shown, to highlight what arrived since
        rowsKey: null,    // fingerprint of the rendered rows, to skip identical re-renders
    };

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
    }

    function buildControls() {
        config.windows.forEach((seconds) => {
            const button = h('button', 'ltm-segment', formatWindow(seconds));
            button.type = 'button';
            button.dataset.window = String(seconds);
            el.windowGroup.appendChild(button);
        });

        const options = [new Option('All websites', 'all')];
        config.domains
            .slice()
            .sort((a, b) => a.displayName.localeCompare(b.displayName))
            .forEach((d) => options.push(new Option(d.displayName, d.name)));
        options.forEach((option) => el.domain.appendChild(option));
    }

    function syncControls() {
        el.windowGroup.querySelectorAll('button').forEach((button) => {
            const active = Number(button.dataset.window) === state.windowSec;
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
        el.domain.value = state.domain;
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
        el.domain.addEventListener('change', () => setFilters({ domain: el.domain.value }));
        el.sites.addEventListener('click', (event) => {
            const row = event.target.closest('tr[data-domain]');
            if (row) {
                setFilters({ domain: row.dataset.domain });
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
        const domain = changes.domain !== undefined ? changes.domain : state.domain;
        if (config.windows.indexOf(windowSec) < 0 || (domain !== 'all' && !displayNames.has(domain))) {
            return;
        }
        if (windowSec === state.windowSec && domain === state.domain) {
            return;
        }
        state.windowSec = windowSec;
        state.domain = domain;
        state.failures = 0;
        state.newestTs = null;
        state.rowsKey = null;
        storageSet('window', windowSec);
        storageSet('domain', domain);
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
        renderSites(p);
        renderStatuses(p);
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
        const wanted = [{ domain: 'all', displayName: 'All websites', count: sum, share: null }];
        sites.forEach((site) => {
            wanted.push({
                domain: site.domain,
                displayName: displayNames.get(site.domain) || site.domain,
                count: site.count,
                share: sum ? site.count / sum : 0,
            });
        });

        const ordered = wanted.map((site) => {
            let row = siteRows.get(site.domain);
            if (!row) {
                row = createSiteRow(site.domain);
                siteRows.set(site.domain, row);
            }
            updateSiteRow(row, site);
            return row.tr;
        });
        const keep = new Set(wanted.map((site) => site.domain));
        siteRows.forEach((row, domain) => {
            if (!keep.has(domain)) {
                siteRows.delete(domain);
            }
        });

        const current = el.sites.children;
        const inOrder = current.length === ordered.length && ordered.every((tr, i) => tr === current[i]);
        if (!inOrder) {
            el.sites.replaceChildren(...ordered);
        }
    }

    function createSiteRow(domain) {
        const tr = h('tr');
        tr.dataset.domain = domain;
        const nameCell = h('td', 'ltm-site');
        const button = h('button', 'ltm-link');
        button.type = 'button';
        nameCell.appendChild(button);
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
        return { tr: tr, button: button, countCell: countCell, bar: bar, fill: fill, pct: pct };
    }

    function updateSiteRow(row, site) {
        const selected = site.domain === state.domain;
        row.tr.classList.toggle('is-selected', selected);
        row.button.textContent = site.displayName;
        row.button.title = site.domain === 'all' ? 'Show all websites' : site.domain;
        row.button.setAttribute('aria-pressed', selected ? 'true' : 'false');
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
                cell('ltm-host', displayNames.get(row.domain) || row.domain, row.domain),
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
}());
