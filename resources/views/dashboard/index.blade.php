@extends('hyprpay::dashboard.layout')

@section('title', 'Hyprpay — Payment gateway monitor')

@section('content')
    <h2>Gateways</h2>
    <div class="grid cards">
        @foreach ($gateways as $gateway)
            <div class="panel gw reveal" style="animation-delay: {{ $loop->index * 35 }}ms">
                <div class="row1">
                    <div>
                        <div class="name">{{ $gateway['label'] }}</div>
                        <div class="key">{{ $gateway['key'] }}</div>
                    </div>
                    <span class="stat {{ $gateway['configured'] ? 'on' : 'off' }}"></span>
                </div>
                <div class="badges">
                    <span class="badge {{ $gateway['configured'] ? 'ok' : 'off' }}">{{ $gateway['configured'] ? 'Configured' : 'Not set' }}</span>
                    <span class="badge {{ $gateway['testMode'] ? 'test' : 'live-mode' }}">{{ $gateway['testMode'] ? 'Test' : 'Live' }}</span>
                    @if ($gateway['default'])
                        <span class="badge default">Default</span>
                    @endif
                </div>
            </div>
        @endforeach
    </div>

    <h2>Activity summary</h2>
    <div class="grid kpis">
        <div class="panel kpi reveal"><div class="n">{{ $stats['total'] }}</div><div class="l">Recorded operations</div></div>
        <div class="panel kpi reveal" style="animation-delay: 45ms"><div class="n">{{ $stats['successRate'] }}<span class="u">%</span></div><div class="l">Success rate</div></div>
        <div class="panel kpi reveal" style="animation-delay: 90ms"><div class="n">{{ $stats['successful'] }}</div><div class="l">Successful</div></div>
        <div class="panel kpi reveal" style="animation-delay: 135ms"><div class="n">{{ $stats['total'] - $stats['successful'] }}</div><div class="l">Unsuccessful</div></div>
    </div>

    <h2>Look up a payment by reference</h2>
    <div class="panel reveal">
        <form class="lookup" id="lookup-form">
            <select id="lookup-gateway" name="gateway" aria-label="Gateway">
                @foreach ($gatewayOptions as $option)
                    <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                @endforeach
            </select>
            <input id="lookup-reference" name="reference" placeholder="Merchant order reference — e.g. ORDER-123" autocomplete="off" spellcheck="false">
            <button type="submit">Search gateway</button>
        </form>
        <div id="lookup-result"></div>
    </div>

    <h2>Recent activity</h2>
    <div class="panel scroll reveal">
        <table>
            <thead>
                <tr>
                    <th>When</th><th>Operation</th><th>Gateway</th><th>Status</th>
                    <th>Order</th><th>Transaction</th><th>Amount</th>
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
@endsection

@section('scripts')
    <script>
        window.HYPRPAY = {
            activityUrl: "{{ route('hyprpay.dashboard.activity') }}",
            lookupUrl: "{{ route('hyprpay.dashboard.lookup') }}",
            csrf: "{{ csrf_token() }}",
        };
    </script>
    <script>
        (function () {
            const esc = (v) => (v === null || v === undefined || v === '')
                ? '—'
                : String(v).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

            const pill = (status, tone) => status
                ? '<span class="pill s-' + esc(tone) + '">' + esc(status) + '</span>'
                : '<span class="pill s-none">—</span>';

            const stamp = () => {
                const el = document.getElementById('updated-at');
                if (el) el.textContent = new Date().toLocaleTimeString();
            };

            function renderActivity(rows) {
                const body = document.getElementById('activity-body');
                if (!rows.length) {
                    body.innerHTML = '<tr><td colspan="7" class="empty">No activity recorded yet.</td></tr>';
                    return;
                }
                body.innerHTML = rows.map((r) =>
                    '<tr>'
                    + '<td class="mono muted">' + esc(r.recordedAt) + '</td>'
                    + '<td>' + esc(r.operation) + '</td>'
                    + '<td>' + esc(r.gateway) + '</td>'
                    + '<td>' + pill(r.status, r.tone) + '</td>'
                    + '<td class="mono">' + esc(r.orderReference) + '</td>'
                    + '<td class="mono">' + esc(r.transactionId) + '</td>'
                    + '<td class="mono">' + esc(r.amount) + '</td>'
                    + '</tr>'
                ).join('');
            }

            async function pollActivity() {
                try {
                    const res = await fetch(window.HYPRPAY.activityUrl, { headers: { 'Accept': 'application/json' } });
                    if (res.ok) { renderActivity(await res.json()); stamp(); }
                } catch (e) { /* transient; keep the last render */ }
            }
            stamp();
            setInterval(pollActivity, 10000);

            const form = document.getElementById('lookup-form');
            const out = document.getElementById('lookup-result');
            form.addEventListener('submit', async (e) => {
                e.preventDefault();
                const gateway = document.getElementById('lookup-gateway').value;
                const reference = document.getElementById('lookup-reference').value.trim();
                if (!reference) return;
                out.innerHTML = '<div class="note" style="background:var(--accent-soft);color:var(--accent);border-color:transparent">Querying gateway…</div>';
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
        })();
    </script>
@endsection
