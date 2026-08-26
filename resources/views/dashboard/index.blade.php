@extends('hyprpay::dashboard.layout')

@section('title', 'Hyprpay — Payment gateway monitor')

@php
    $total = $stats['total'];
    $ok = $stats['successful'];
    $bad = $total - $ok;
    $rate = $stats['successRate'];
    $failRate = $total === 0 ? 0 : 100 - $rate;
    $circ = 326.726;
    $ringOffset = $circ * (1 - $rate / 100);
    $palette = ['#2f590f', '#6fae2e', '#a9c48a', '#d7a13f', '#c96f4f', '#5f7fae', '#9b9b9b', '#d8d8d8'];
    $activeGateways = array_values(array_filter($gateways, static fn (array $g): bool => $g['configured']));
    $gatewayCounts = [];
    foreach ($stats['byGateway'] as $g) {
        $gatewayCounts[$g['label']] = $g['count'];
    }
@endphp

@section('content')
    <header class="top">
        <div>
            <h1 class="pagetitle">Overview</h1>
            <div class="subtitle">Real-time payment gateway telemetry across your integrations.</div>
        </div>
        <div class="updated" id="refresh-note">Auto-refresh is off</div>
    </header>

    <section id="overview">
        <div class="grid kpis">
            <div class="panel kpi reveal">
                <div class="l">Recorded operations <span class="chip neutral" id="kpi-total-chip"></span></div>
                <div class="kpi-row">
                    <div class="n" id="kpi-total">{{ $total }}</div>
                    <svg class="spark" id="spark-total" viewBox="0 0 100 30" preserveAspectRatio="none" aria-hidden="true"></svg>
                </div>
                <div class="sub">across {{ count($activeGateways) }} active gateways</div>
            </div>
            <div class="panel kpi reveal" style="animation-delay: 45ms">
                <div class="l">Success rate <span class="chip neutral" id="kpi-rate-chip"></span></div>
                <div class="kpi-row">
                    <div class="n" id="kpi-rate">{{ $rate }}<span class="u">%</span></div>
                    <svg class="spark" id="spark-rate" viewBox="0 0 100 30" preserveAspectRatio="none" aria-hidden="true"></svg>
                </div>
                <div class="sub">non-declined, non-failed outcomes</div>
            </div>
            <div class="panel kpi reveal" style="animation-delay: 90ms">
                <div class="l">Successful <span class="chip neutral" id="kpi-ok-chip"></span></div>
                <div class="kpi-row">
                    <div class="n" id="kpi-ok">{{ $ok }}</div>
                    <svg class="spark" id="spark-ok" viewBox="0 0 100 30" preserveAspectRatio="none" aria-hidden="true"></svg>
                </div>
                <div class="sub">authorized · captured · settled</div>
            </div>
            <div class="panel kpi reveal" style="animation-delay: 135ms">
                <div class="l">Unsuccessful <span class="chip neutral" id="kpi-bad-chip"></span></div>
                <div class="kpi-row">
                    <div class="n" id="kpi-bad">{{ $bad }}</div>
                    <svg class="spark" id="spark-bad" viewBox="0 0 100 30" preserveAspectRatio="none" aria-hidden="true"></svg>
                </div>
                <div class="sub">declined · failed · unknown</div>
            </div>
        </div>
    </section>

    <div class="grid split" style="margin-top: 15px">
        <div class="panel ops reveal">
            <div class="head"><span class="t">Operations</span><span class="s">Success ratio</span></div>
            <div class="ring-wrap">
                <div class="ring-c">
                    <svg class="ring" viewBox="0 0 120 120" data-circ="{{ $circ }}">
                        <circle class="track" cx="60" cy="60" r="52"/>
                        <circle class="arc" id="ring-arc" cx="60" cy="60" r="52" stroke-dasharray="{{ $circ }}" stroke-dashoffset="{{ $ringOffset }}"/>
                    </svg>
                    <div class="big"><div class="v" id="ring-pct">{{ $rate }}%</div><div class="k">Success</div></div>
                </div>
                <div class="legend">
                    <div class="row"><span class="dot" style="background: var(--ok)"></span><span class="lbl">Successful</span><span class="val" id="lg-ok">{{ $ok }}</span></div>
                    <div class="row"><span class="dot" style="background: var(--bad)"></span><span class="lbl">Unsuccessful</span><span class="val" id="lg-bad">{{ $bad }}</span></div>
                    <div class="row"><span class="dot" style="background: var(--faint)"></span><span class="lbl">Total operations</span><span class="val" id="lg-total">{{ $total }}</span></div>
                </div>
            </div>
        </div>

        <div class="panel reveal" style="animation-delay: 60ms">
            <div class="head"><span class="t">By gateway</span><span class="s">Share</span></div>
            <div id="bd-gateway">
                @include('hyprpay::dashboard.partials.breakdown', ['items' => $stats['byGateway'], 'total' => $total, 'palette' => $palette, 'emptyText' => 'No gateway activity yet'])
            </div>
        </div>

        <div class="panel reveal" style="animation-delay: 120ms">
            <div class="head"><span class="t">By status</span><span class="s">Distribution</span></div>
            <div id="bd-status">
                @include('hyprpay::dashboard.partials.breakdown', ['items' => $stats['byStatus'], 'total' => $total, 'palette' => $palette, 'emptyText' => 'No status data yet'])
            </div>
        </div>
    </div>

    <h2 id="gateways">Active gateways</h2>
    <div class="grid cards">
        @forelse ($activeGateways as $gateway)
            <div class="panel gw reveal" data-gateway="{{ $gateway['label'] }}" style="animation-delay: {{ $loop->index * 35 }}ms">
                <div class="row1">
                    <div>
                        <div class="name">{{ $gateway['label'] }}</div>
                        <div class="key">{{ $gateway['key'] }}</div>
                    </div>
                    <span class="stat on"></span>
                </div>
                <div class="gw-metric">
                    <div class="gw-count"><span class="gw-count-n">{{ $gatewayCounts[$gateway['label']] ?? 0 }}</span><span class="gw-count-u">payments</span></div>
                    <svg class="spark gw-spark" viewBox="0 0 100 30" preserveAspectRatio="none" aria-hidden="true"></svg>
                </div>
                <div class="badges">
                    <span class="badge {{ $gateway['testMode'] ? 'test' : 'live-mode' }}">{{ $gateway['testMode'] ? 'Test' : 'Live' }}</span>
                    @if ($gateway['default'])
                        <span class="badge default">Default</span>
                    @endif
                </div>
            </div>
        @empty
            <div class="panel"><div class="empty">No active gateways. Configure a gateway's shared secret to see it here.</div></div>
        @endforelse
    </div>

    <h2 id="lookup">Look up a payment by reference</h2>
    <div class="panel reveal">
        <form class="lookup" id="lookup-form">
            <select id="lookup-gateway" name="gateway" aria-label="Gateway">
                @foreach ($gatewayOptions as $option)
                    <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                @endforeach
            </select>
            <input id="lookup-reference" name="reference" placeholder="Merchant order reference — e.g. ORDER-123" autocomplete="off" spellcheck="false">
            <button type="submit"><span class="mi">search</span><span>Search</span></button>
        </form>
        <div id="lookup-result"></div>
    </div>

    <h2 id="activity">Recent activity</h2>
    <div class="panel reveal">
        <div class="tablebar">
            <input id="f-search" placeholder="Search order, transaction, operation…" autocomplete="off" spellcheck="false" aria-label="Search activity">
            <select id="f-gateway" aria-label="Filter by gateway">
                <option value="">All gateways</option>
                @foreach ($gatewayOptions as $option)
                    <option value="{{ $option['label'] }}">{{ $option['label'] }}</option>
                @endforeach
            </select>
            <select id="f-status" aria-label="Filter by status">
                <option value="">All statuses</option>
                @foreach (['Captured', 'Authorized', 'Pending', 'Refunded', 'Voided', 'Reversed', 'Declined', 'Failed', 'Unknown'] as $label)
                    <option value="{{ $label }}">{{ $label }}</option>
                @endforeach
            </select>
            <span class="f-count" id="f-count"></span>
        </div>
        <div class="scroll">
            <table>
                <thead>
                    <tr>
                        <th class="sortable" data-sort="recordedAt">When</th>
                        <th class="sortable" data-sort="operation">Operation</th>
                        <th class="sortable" data-sort="gateway">Gateway</th>
                        <th class="sortable" data-sort="status">Status</th>
                        <th class="sortable" data-sort="orderReference">Order</th>
                        <th class="sortable" data-sort="transactionId">Transaction</th>
                        <th class="sortable" data-sort="amount">Amount</th>
                    </tr>
                </thead>
                <tbody id="activity-body">
                    @forelse ($recent as $row)
                        <tr>
                            <td class="mono muted">{{ $row['recordedAt'] }}</td>
                            <td>{{ $row['operation'] }}</td>
                            <td>{{ $row['gateway'] }}</td>
                            <td>
                                @if ($row['status'])
                                    <span class="pill s-{{ $row['tone'] }}">{{ $row['status'] }}</span>
                                @else
                                    <span class="pill s-none">—</span>
                                @endif
                            </td>
                            <td class="mono">{{ $row['orderReference'] ?? '—' }}</td>
                            <td class="mono">{{ $row['transactionId'] ?? '—' }}</td>
                            <td class="mono">{{ $row['amount'] ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="empty">No activity recorded yet. Set <span class="mono">GATEWAY_DASHBOARD_STORE=true</span> to start capturing gateway operations.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <h2 id="logs">Logs</h2>
    <div class="panel scroll reveal">
        <div id="logs-body"><div class="empty">Loading logs…</div></div>
    </div>

    <div class="drawer" id="drawer" aria-hidden="true">
        <div class="drawer-scrim" data-drawer-close></div>
        <aside class="drawer-panel" role="dialog" aria-modal="true" aria-label="Payment lifecycle">
            <div class="drawer-head">
                <div>
                    <div class="ref" id="dw-ref">—</div>
                    <div class="sub"><span id="dw-gateway"></span><span id="dw-status"></span></div>
                </div>
                <button class="drawer-x" type="button" data-drawer-close aria-label="Close"><span class="mi">close</span></button>
            </div>
            <div class="drawer-tabs" role="tablist">
                <button class="dw-tab active" type="button" data-tab="summary" role="tab">Summary</button>
                <button class="dw-tab" type="button" data-tab="history" role="tab">History <span class="dw-tab-ct" id="dw-hist-ct"></span></button>
            </div>
            <div class="drawer-body">
                <div class="dw-pane" id="pane-summary" role="tabpanel"></div>
                <div class="dw-pane" id="pane-history" role="tabpanel" hidden></div>
            </div>
            <div class="drawer-foot">
                <button class="btn-ghost" type="button" id="dw-refresh"><span class="mi">sync</span><span>Refresh from gateway</span></button>
                <span style="flex: 1"></span>
                <button class="btn-ghost" type="button" id="dw-copy"><span class="mi">content_copy</span><span class="lbl">Copy</span></button>
                <button class="btn-primary" type="button" id="dw-aicopy"><span class="mi">auto_awesome</span><span class="lbl">AI copy</span></button>
            </div>
        </aside>
    </div>
@endsection

@section('scripts')
    <script>
        window.HYPRPAY = {
            activityUrl: "{{ route('hyprpay.dashboard.activity') }}",
            logsUrl: "{{ route('hyprpay.dashboard.logs') }}",
            lifecycleUrl: "{{ route('hyprpay.dashboard.lifecycle') }}",
            lookupUrl: "{{ route('hyprpay.dashboard.lookup') }}",
            csrf: "{{ csrf_token() }}",
            palette: @json($palette),
            rows: @json($recent),
        };
    </script>
    <script>
        (function () {
            const P = window.HYPRPAY.palette;
            let rows = Array.isArray(window.HYPRPAY.rows) ? window.HYPRPAY.rows : [];
            let sort = { key: 'recordedAt', dir: 'desc' };

            const esc = (v) => (v === null || v === undefined || v === '')
                ? '—'
                : String(v).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

            const attr = (v) => String(v == null ? '' : v).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

            const humanize = (v) => String(v == null ? '' : v).replace(/([a-z0-9])([A-Z])/g, '$1 $2');

            const pill = (status, tone) => status
                ? '<span class="pill s-' + esc(tone) + '">' + esc(status) + '</span>'
                : '<span class="pill s-none">—</span>';

            const stamp = () => {
                const el = document.getElementById('updated-at');
                if (el) el.textContent = new Date().toLocaleTimeString();
            };

            const isSuccess = (r) => r.statusKey != null && r.statusKey !== 'declined' && r.statusKey !== 'failed';

            const tally = (rows, key) => {
                const counts = {};
                rows.forEach((r) => { const k = key(r); counts[k] = (counts[k] || 0) + 1; });
                return Object.entries(counts)
                    .map(([label, count]) => ({ label, count }))
                    .sort((a, b) => b.count - a.count);
            };

            const setText = (id, text) => { const el = document.getElementById(id); if (el) el.textContent = text; };

            function renderBreakdown(id, items, total, emptyText) {
                const el = document.getElementById(id);
                if (!el) return;
                if (!items.length) { el.innerHTML = '<div class="breakdown empty-list">' + esc(emptyText) + '</div>'; return; }
                const seg = items.map((it, i) => {
                    const pct = total > 0 ? (it.count / total * 100) : 0;
                    return '<span style="width:' + pct + '%;background:' + P[i % P.length] + '"></span>';
                }).join('');
                const rows = items.map((it, i) => {
                    const pct = total > 0 ? Math.round(it.count / total * 100) : 0;
                    return '<div class="row">'
                        + '<span class="dot" style="background:' + P[i % P.length] + '"></span>'
                        + '<span class="lbl">' + esc(it.label) + '</span>'
                        + '<span class="pct">' + pct + '%</span>'
                        + '<span class="cnt">' + it.count + '</span>'
                        + '</div>';
                }).join('');
                el.innerHTML = '<div class="segbar">' + seg + '</div><div class="breakdown">' + rows + '</div>';
            }

            const SPARK = { total: '#6fae2e', rate: '#5aa62a', ok: '#5aa62a', bad: '#d9534f' };
            const sum = (a) => a.reduce((x, y) => x + y, 0);

            function bucketize(chrono, n) {
                const groups = Array.from({ length: n }, () => []);
                const len = chrono.length;
                if (len) chrono.forEach((r, i) => groups[Math.min(n - 1, Math.floor(i / len * n))].push(r));
                return groups;
            }

            function drawSpark(target, series, color) {
                const el = typeof target === 'string' ? document.getElementById(target) : target;
                if (!el) return;
                const n = series.length;
                if (n < 2 || Math.max.apply(null, series) === 0) { el.innerHTML = ''; return; }
                const max = Math.max.apply(null, series), min = Math.min.apply(null, series);
                const range = (max - min) || 1;
                const pts = series.map((v, i) => ((i / (n - 1)) * 100).toFixed(2) + ',' + (28 - ((v - min) / range) * 26).toFixed(2));
                const line = 'M' + pts.join(' L');
                el.innerHTML = '<path d="' + line + ' L100,30 L0,30 Z" fill="' + color + '" opacity="0.13"/>'
                    + '<path d="' + line + '" fill="none" stroke="' + color + '" stroke-width="1.6" vector-effect="non-scaling-stroke" stroke-linejoin="round" stroke-linecap="round"/>';
            }

            function halfDelta(series, average) {
                const n = series.length;
                if (n < 2) return null;
                const mid = Math.floor(n / 2);
                const agg = (a) => average ? (a.length ? sum(a) / a.length : 0) : sum(a);
                const older = agg(series.slice(0, mid)), recent = agg(series.slice(mid));
                if (older === 0) return recent > 0 ? 100 : 0;
                return (recent - older) / older * 100;
            }

            function trendChip(id, deltaPct, goodWhenUp) {
                const el = document.getElementById(id);
                if (!el) return;
                if (deltaPct === null) { el.textContent = ''; el.className = 'chip neutral'; return; }
                const mag = Math.round(Math.abs(deltaPct));
                if (mag === 0) { el.textContent = '0%'; el.className = 'chip neutral'; return; }
                const up = deltaPct > 0;
                el.innerHTML = '<span class="tri">' + (up ? '▲' : '▼') + '</span> ' + mag + '%';
                el.className = 'chip ' + (up === goodWhenUp ? 'up' : 'down');
            }

            function renderStats(rows) {
                const total = rows.length;
                const ok = rows.filter(isSuccess).length;
                const bad = total - ok;
                const rate = total === 0 ? 0 : Math.round(ok / total * 100);

                setText('kpi-total', total);
                setText('kpi-rate', rate);
                const rateU = document.getElementById('kpi-rate'); if (rateU) rateU.innerHTML = rate + '<span class="u">%</span>';
                setText('kpi-ok', ok);
                setText('kpi-bad', bad);
                setText('lg-ok', ok);
                setText('lg-bad', bad);
                setText('lg-total', total);
                setText('nav-ct-activity', total);

                const groups = bucketize(rows.slice().reverse(), 16);
                const totalSeries = groups.map((g) => g.length);
                const okSeries = groups.map((g) => g.filter(isSuccess).length);
                const badSeries = groups.map((g, i) => g.length - okSeries[i]);
                const rateSeries = groups.map((g, i) => g.length ? Math.round(okSeries[i] / g.length * 100) : 0);

                drawSpark('spark-total', totalSeries, SPARK.total);
                drawSpark('spark-rate', rateSeries, SPARK.rate);
                drawSpark('spark-ok', okSeries, SPARK.ok);
                drawSpark('spark-bad', badSeries, SPARK.bad);

                document.querySelectorAll('.gw[data-gateway]').forEach((card) => {
                    const gw = card.dataset.gateway;
                    const series = groups.map((g) => g.filter((r) => r.gateway === gw).length);
                    const countEl = card.querySelector('.gw-count-n');
                    if (countEl) countEl.textContent = series.reduce((a, b) => a + b, 0);
                    const spark = card.querySelector('.gw-spark');
                    if (spark) drawSpark(spark, series, SPARK.total);
                });

                trendChip('kpi-total-chip', halfDelta(totalSeries, false), true);
                trendChip('kpi-rate-chip', halfDelta(rateSeries, true), true);
                trendChip('kpi-ok-chip', halfDelta(okSeries, false), true);
                trendChip('kpi-bad-chip', halfDelta(badSeries, false), false);

                const arc = document.getElementById('ring-arc');
                if (arc) {
                    const circ = parseFloat(arc.closest('.ring').dataset.circ);
                    arc.style.strokeDashoffset = circ * (1 - rate / 100);
                }
                setText('ring-pct', rate + '%');

                renderBreakdown('bd-gateway', tally(rows, (r) => r.gateway), total, 'No gateway activity yet');
                renderBreakdown('bd-status', tally(rows, (r) => r.status || 'Unknown'), total, 'No status data yet');
            }

            const num = (v) => { const n = parseFloat(String(v == null ? '' : v).replace(/[^0-9.\-]/g, '')); return isNaN(n) ? 0 : n; };

            function filteredRows() {
                const q = (document.getElementById('f-search')?.value || '').trim().toLowerCase();
                const g = document.getElementById('f-gateway')?.value || '';
                const s = document.getElementById('f-status')?.value || '';
                return rows.filter((r) => {
                    const status = r.status || 'Unknown';
                    if (g && r.gateway !== g) return false;
                    if (s && status !== s) return false;
                    if (!q) return true;
                    const hay = [r.operation, r.gateway, status, r.orderReference, r.transactionId, r.amount, r.recordedAt]
                        .map((v) => (v == null ? '' : String(v))).join(' ').toLowerCase();
                    return hay.includes(q);
                });
            }

            function sortRows(list) {
                const mul = sort.dir === 'asc' ? 1 : -1;
                return list.slice().sort((a, b) => {
                    let cmp;
                    if (sort.key === 'amount') {
                        cmp = num(a.amount) - num(b.amount);
                    } else {
                        const av = (a[sort.key] == null ? '' : String(a[sort.key])).toLowerCase();
                        const bv = (b[sort.key] == null ? '' : String(b[sort.key])).toLowerCase();
                        cmp = av < bv ? -1 : av > bv ? 1 : 0;
                    }
                    return cmp * mul;
                });
            }

            function renderRows(list) {
                const body = document.getElementById('activity-body');
                if (!body) return;
                if (!list.length) {
                    body.innerHTML = rows.length
                        ? '<tr><td colspan="7" class="empty">No activity matches your filters.</td></tr>'
                        : '<tr><td colspan="7" class="empty">No activity recorded yet. Set <span class="mono">GATEWAY_DASHBOARD_STORE=true</span> to start capturing gateway operations.</td></tr>';
                    return;
                }
                body.innerHTML = list.map((r) => {
                    const ref = r.orderReference || r.transactionId || '';
                    return '<tr' + (ref ? ' class="has-ref" data-ref="' + attr(ref) + '"' : '') + '>'
                        + '<td class="mono muted">' + esc(r.recordedAt) + '</td>'
                        + '<td>' + esc(humanize(r.operation)) + '</td>'
                        + '<td>' + esc(r.gateway) + '</td>'
                        + '<td>' + pill(r.status, r.tone) + '</td>'
                        + '<td class="mono">' + esc(r.orderReference) + '</td>'
                        + '<td class="mono">' + esc(r.transactionId) + '</td>'
                        + '<td class="mono">' + esc(r.amount) + '</td>'
                        + '</tr>';
                }).join('');
            }

            function syncCarets() {
                document.querySelectorAll('th.sortable').forEach((th) => {
                    const active = th.dataset.sort === sort.key;
                    th.classList.toggle('asc', active && sort.dir === 'asc');
                    th.classList.toggle('desc', active && sort.dir === 'desc');
                });
            }

            function renderTable() {
                const view = sortRows(filteredRows());
                renderRows(view);
                syncCarets();
                setText('f-count', view.length === rows.length ? rows.length + ' operations' : view.length + ' of ' + rows.length);
            }

            async function pollActivity() {
                try {
                    const res = await fetch(window.HYPRPAY.activityUrl, { headers: { 'Accept': 'application/json' } });
                    if (res.ok) {
                        const data = await res.json();
                        rows = Array.isArray(data) ? data : [];
                        renderStats(rows);
                        renderTable();
                        stamp();
                    }
                } catch (e) {}
            }

            document.querySelectorAll('th.sortable').forEach((th) => {
                th.addEventListener('click', () => {
                    const key = th.dataset.sort;
                    sort = sort.key === key
                        ? { key, dir: sort.dir === 'asc' ? 'desc' : 'asc' }
                        : { key, dir: (key === 'recordedAt' || key === 'amount') ? 'desc' : 'asc' };
                    renderTable();
                });
            });
            ['f-search', 'f-gateway', 'f-status'].forEach((id) => {
                const el = document.getElementById(id);
                if (!el) return;
                el.addEventListener('input', renderTable);
                el.addEventListener('change', renderTable);
            });

            const prettyDetail = (d) => {
                try { return JSON.stringify(JSON.parse(d), null, 2); } catch (e) { return d; }
            };

            function renderLogs(entries) {
                const body = document.getElementById('logs-body');
                if (!body) return;
                if (!entries.length) {
                    body.innerHTML = '<div class="empty">No log entries yet. The SDK logs to the <span class="mono">hyprpay</span> channel.</div>';
                    return;
                }
                body.innerHTML = entries.map((e) =>
                    '<div class="log-entry' + (e.detail ? ' has-detail' : '') + '">'
                    + '<div class="log-row">'
                    + '<span class="log-level s-' + esc(e.tone) + '">' + esc(e.level) + '</span>'
                    + '<span class="log-time">' + esc(e.time) + '</span>'
                    + '<span class="log-msg">' + esc(e.message) + '</span>'
                    + (e.detail ? '<span class="mi log-chev">expand_more</span>' : '<span></span>')
                    + '</div>'
                    + (e.detail ? '<pre class="log-detail" hidden>' + esc(prettyDetail(e.detail)) + '</pre>' : '')
                    + '</div>'
                ).join('');
            }

            async function pollLogs() {
                try {
                    const res = await fetch(window.HYPRPAY.logsUrl, { headers: { 'Accept': 'application/json' } });
                    if (res.ok) renderLogs(await res.json());
                } catch (e) {}
            }

            const logsBody = document.getElementById('logs-body');
            if (logsBody) {
                logsBody.addEventListener('click', (e) => {
                    const entry = e.target.closest('.log-entry.has-detail');
                    if (!entry) return;
                    const open = entry.classList.toggle('open');
                    const detail = entry.querySelector('.log-detail');
                    if (detail) detail.hidden = !open;
                });
            }

            stamp();
            renderStats(rows);
            renderTable();
            pollLogs();

            let refreshTimer = null;
            const refreshNote = document.getElementById('refresh-note');
            const autoToggle = document.getElementById('auto-refresh');
            if (autoToggle) {
                autoToggle.addEventListener('change', () => {
                    if (autoToggle.checked) {
                        pollActivity();
                        pollLogs();
                        refreshTimer = setInterval(() => { pollActivity(); pollLogs(); }, 10000);
                        if (refreshNote) refreshNote.textContent = 'Auto-refreshing every 10s';
                    } else {
                        if (refreshTimer) { clearInterval(refreshTimer); refreshTimer = null; }
                        if (refreshNote) refreshNote.textContent = 'Auto-refresh is off';
                    }
                });
            }

            const form = document.getElementById('lookup-form');
            const out = document.getElementById('lookup-result');
            form.addEventListener('submit', async (e) => {
                e.preventDefault();
                const gateway = document.getElementById('lookup-gateway').value;
                const reference = document.getElementById('lookup-reference').value.trim();
                if (!reference) return;
                out.innerHTML = '<div class="note" style="background:var(--accent-soft);color:var(--ok);border-color:transparent">Querying gateway…</div>';
                try {
                    const res = await fetch(window.HYPRPAY.lookupUrl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': window.HYPRPAY.csrf,
                        },
                        body: JSON.stringify({ gateway, reference }),
                    });
                    const data = await res.json();
                    if (!res.ok) {
                        out.innerHTML = '<div class="note">' + esc(data.message || 'Lookup failed.') + '</div>';
                        return;
                    }
                    if (!data.supported) {
                        out.innerHTML = '<div class="note">' + esc(data.message || 'Listing is not supported for this gateway yet.') + '</div>';
                        return;
                    }
                    if (!data.transactions.length) {
                        out.innerHTML = '<div class="note" style="background:var(--panel-2);color:var(--muted);border-color:var(--border)">No transactions found for that reference.</div>';
                        return;
                    }
                    out.innerHTML = '<div class="scroll" style="margin-top:15px"><table><thead><tr>'
                        + '<th>Transaction</th><th>Status</th><th>Order</th><th>Amount</th></tr></thead><tbody>'
                        + data.transactions.map((t) =>
                            '<tr><td class="mono">' + esc(t.transactionId) + '</td>'
                            + '<td>' + pill(t.status, t.tone) + '</td>'
                            + '<td class="mono">' + esc(t.orderReference) + '</td>'
                            + '<td class="mono">' + esc(t.amount) + '</td></tr>'
                        ).join('')
                        + '</tbody></table></div>';
                } catch (err) {
                    out.innerHTML = '<div class="note">Lookup failed: ' + esc(String(err)) + '</div>';
                }
            });

            const drawer = document.getElementById('drawer');
            let currentLife = null;

            const openDrawer = () => { drawer.classList.add('open'); drawer.setAttribute('aria-hidden', 'false'); document.body.style.overflow = 'hidden'; };
            const closeDrawer = () => { drawer.classList.remove('open'); drawer.setAttribute('aria-hidden', 'true'); document.body.style.overflow = ''; };

            const metaBits = (r) => {
                const bits = [];
                if (r.transactionId) bits.push('txn <b>' + esc(r.transactionId) + '</b>');
                if (r.orderReference) bits.push('order <b>' + esc(r.orderReference) + '</b>');
                if (r.reference) bits.push('ref <b>' + esc(r.reference) + '</b>');
                if (r.amount) bits.push('amount <b>' + esc(r.amount) + '</b>');
                return bits.map((h) => '<span>' + h + '</span>').join('');
            };

            function showTab(name) {
                drawer.querySelectorAll('.dw-tab').forEach((t) => t.classList.toggle('active', t.dataset.tab === name));
                document.getElementById('pane-summary').hidden = name !== 'summary';
                document.getElementById('pane-history').hidden = name !== 'history';
            }

            function summaryPane(data) {
                const s = data.summary || {};
                const latest = data.events.length ? data.events[data.events.length - 1] : {};
                const cells = [
                    ['Status', s.status || '—'],
                    ['Amount', s.amount || '—'],
                    ['Attempts', String(s.attempts || 0) + (s.successful != null ? ' · ' + s.successful + ' ok' : '')],
                    ['Latest txn', latest.transactionId || '—'],
                    ['First seen', s.firstAt || '—'],
                    ['Last activity', s.lastAt || '—'],
                ];
                return '<div class="drawer-summary">'
                    + cells.map(([k, v]) => '<div class="cell"><span class="k">' + esc(k) + '</span><span class="v">' + esc(v) + '</span></div>').join('')
                    + '</div><div id="dw-live"></div>';
            }

            function historyPane(data) {
                if (!data.found || !data.events.length) {
                    return '<div class="drawer-empty">No recorded events for this reference yet.</div>';
                }
                return data.events.map((r) =>
                    '<div class="attempt">'
                    + '<button class="attempt-head" type="button" aria-expanded="false">'
                    + '<span class="attempt-dot s-' + esc(r.tone) + '"></span>'
                    + '<span class="attempt-op">' + esc(humanize(r.operation)) + '</span>'
                    + '<span style="flex:1"></span>'
                    + '<span class="attempt-status">' + (r.status ? pill(r.status, r.tone) : '') + '</span>'
                    + '<span class="attempt-time">' + esc(r.recordedAt) + '</span>'
                    + '<span class="mi attempt-chev">expand_more</span>'
                    + '</button>'
                    + '<div class="attempt-body" hidden><div class="tl-meta">' + (metaBits(r) || '<span>No further detail recorded.</span>') + '</div></div>'
                    + '</div>'
                ).join('');
            }

            function renderLifecycle(data) {
                currentLife = data;
                const s = data.summary || {};
                setText('dw-ref', data.reference || '—');
                setText('dw-gateway', s.gateway || '');
                document.getElementById('dw-status').innerHTML = s.status ? pill(s.status, s.tone) : '';
                setText('dw-hist-ct', data.events.length ? String(data.events.length) : '');
                document.getElementById('pane-summary').innerHTML = summaryPane(data);
                document.getElementById('pane-history').innerHTML = historyPane(data);
                showTab('summary');
            }

            async function openLifecycle(reference) {
                if (!reference) return;
                openDrawer();
                setText('dw-ref', reference);
                setText('dw-gateway', '');
                setText('dw-hist-ct', '');
                document.getElementById('dw-status').innerHTML = '';
                document.getElementById('pane-history').innerHTML = '';
                document.getElementById('pane-summary').innerHTML = '<div class="drawer-empty">Loading…</div>';
                showTab('summary');
                try {
                    const res = await fetch(window.HYPRPAY.lifecycleUrl + '?reference=' + encodeURIComponent(reference), { headers: { 'Accept': 'application/json' } });
                    if (res.ok) { renderLifecycle(await res.json()); }
                    else { document.getElementById('pane-summary').innerHTML = '<div class="drawer-empty">Could not load the lifecycle.</div>'; }
                } catch (e) {
                    document.getElementById('pane-summary').innerHTML = '<div class="drawer-empty">Could not load the lifecycle.</div>';
                }
            }

            const activityTable = document.getElementById('activity-body');
            if (activityTable) {
                activityTable.addEventListener('click', (e) => {
                    const tr = e.target.closest('tr[data-ref]');
                    if (tr && tr.dataset.ref) openLifecycle(tr.dataset.ref);
                });
            }
            drawer.querySelectorAll('[data-drawer-close]').forEach((el) => el.addEventListener('click', closeDrawer));
            document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && drawer.classList.contains('open')) closeDrawer(); });

            drawer.querySelectorAll('.dw-tab').forEach((tab) => tab.addEventListener('click', () => showTab(tab.dataset.tab)));
            document.getElementById('pane-history').addEventListener('click', (e) => {
                const head = e.target.closest('.attempt-head');
                if (!head) return;
                const item = head.parentElement;
                const open = item.classList.toggle('open');
                head.setAttribute('aria-expanded', open ? 'true' : 'false');
                const detail = item.querySelector('.attempt-body');
                if (detail) detail.hidden = !open;
            });

            document.getElementById('dw-refresh').addEventListener('click', async () => {
                if (!currentLife || !currentLife.summary) return;
                const gateway = currentLife.summary.gatewayKey;
                const reference = currentLife.reference;
                if (!gateway || !reference) return;
                showTab('summary');
                const live = document.getElementById('dw-live');
                if (!live) return;
                live.innerHTML = '<div class="note" style="background:var(--accent-soft);color:var(--ok);border-color:transparent">Querying ' + esc(currentLife.summary.gateway || gateway) + '…</div>';
                try {
                    const res = await fetch(window.HYPRPAY.lookupUrl, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': window.HYPRPAY.csrf },
                        body: JSON.stringify({ gateway, reference }),
                    });
                    const data = await res.json();
                    if (!res.ok) { live.innerHTML = '<div class="note">' + esc(data.message || 'Lookup failed.') + '</div>'; return; }
                    if (!data.supported) { live.innerHTML = '<div class="note">' + esc(data.message || 'Listing is not supported for this gateway yet.') + '</div>'; return; }
                    if (!data.transactions.length) { live.innerHTML = '<div class="note" style="background:var(--panel-2);color:var(--muted);border-color:var(--border)">No transactions found at the gateway for this reference.</div>'; return; }
                    live.innerHTML = '<div class="dw-live-h">Live gateway snapshot</div><div class="scroll"><table><thead><tr><th>Transaction</th><th>Status</th><th>Order</th><th>Amount</th></tr></thead><tbody>'
                        + data.transactions.map((t) =>
                            '<tr><td class="mono">' + esc(t.transactionId) + '</td><td>' + pill(t.status, t.tone) + '</td><td class="mono">' + esc(t.orderReference) + '</td><td class="mono">' + esc(t.amount) + '</td></tr>'
                        ).join('') + '</tbody></table></div>';
                } catch (e) {
                    live.innerHTML = '<div class="note">Gateway query failed.</div>';
                }
            });

            const copyFallback = (text) => {
                try {
                    const ta = document.createElement('textarea');
                    ta.value = text; ta.style.position = 'fixed'; ta.style.top = '-1000px';
                    document.body.appendChild(ta); ta.focus(); ta.select();
                    const ok = document.execCommand('copy');
                    document.body.removeChild(ta);
                    return ok;
                } catch (e) { return false; }
            };
            const copyText = (text, btn) => {
                const lbl = btn.querySelector('.lbl') || btn;
                if (!lbl.dataset.label) lbl.dataset.label = lbl.textContent;
                const flash = (msg) => { lbl.textContent = msg; setTimeout(() => { lbl.textContent = lbl.dataset.label; }, 1400); };
                if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText(text).then(() => flash('Copied ✓')).catch(() => flash(copyFallback(text) ? 'Copied ✓' : 'Copy failed'));
                } else {
                    flash(copyFallback(text) ? 'Copied ✓' : 'Copy failed');
                }
            };

            function lifecycleText(mode) {
                const d = currentLife;
                if (!d) return '';
                const s = d.summary || {};
                const yn = (v) => v === true ? 'yes' : (v === false ? 'no' : '—');
                if (mode === 'ai') {
                    const lines = [
                        'You are a payments engineer. Analyze this PII-safe payment gateway lifecycle: explain what happened, whether it ultimately succeeded, and flag any anomalies or retries.',
                        '',
                        'Reference: ' + (d.reference || '—'),
                        'Gateway: ' + (s.gateway || '—'),
                        'Current status: ' + (s.status || '—'),
                        'Amount: ' + (s.amount || '—'),
                        'Attempts: ' + (s.attempts || 0) + ' (' + (s.successful || 0) + ' successful)',
                        'Window: ' + (s.firstAt || '—') + ' -> ' + (s.lastAt || '—'),
                        '',
                        'Events (oldest first):',
                    ];
                    d.events.forEach((r, i) => {
                        lines.push((i + 1) + '. ' + [r.recordedAt, 'operation=' + r.operation, 'status=' + (r.status || '—'), 'success=' + yn(r.success), 'txn=' + (r.transactionId || '—'), 'ref=' + (r.reference || '—'), 'amount=' + (r.amount || '—')].join(' | '));
                    });
                    return lines.join('\n');
                }
                const out = [
                    'Payment ' + (d.reference || '—') + ' — ' + (s.gateway || '—'),
                    'Status: ' + (s.status || '—') + ' · ' + (s.amount || '—') + ' · ' + (s.attempts || 0) + ' events',
                    (s.firstAt || '—') + ' -> ' + (s.lastAt || '—'),
                    '',
                ];
                d.events.forEach((r) => {
                    out.push([r.recordedAt, r.operation, r.status || '—', r.transactionId || '—', r.amount || '—'].join('  '));
                });
                return out.join('\n');
            }

            document.getElementById('dw-copy').addEventListener('click', (e) => copyText(lifecycleText('normal'), e.currentTarget));
            document.getElementById('dw-aicopy').addEventListener('click', (e) => copyText(lifecycleText('ai'), e.currentTarget));
        })();
    </script>
@endsection
