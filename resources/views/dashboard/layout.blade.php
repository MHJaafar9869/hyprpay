<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Hyprpay')</title>
    <style>
        :root {
            --bg: #07090e;
            --bg-2: #0b0f17;
            --panel: rgba(19, 24, 34, .72);
            --panel-2: #10151f;
            --border: rgba(120, 140, 175, .14);
            --border-strong: rgba(120, 140, 175, .28);
            --text: #eef2fb;
            --muted: #7d879b;
            --faint: #545d70;
            --accent: #34e0c8;
            --accent-2: #4d7cff;
            --accent-soft: rgba(52, 224, 200, .12);
            --ok: #3ecf8e; --ok-soft: rgba(62, 207, 142, .13);
            --warn: #ffbf46; --warn-soft: rgba(255, 191, 70, .13);
            --bad: #ff5c72; --bad-soft: rgba(255, 92, 114, .13);
            --mono: ui-monospace, "JetBrains Mono", "SFMono-Regular", "Cascadia Code", Menlo, Consolas, monospace;
            --sans: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            --radius: 14px;
        }
        * { box-sizing: border-box; }
        html { -webkit-text-size-adjust: 100%; }
        body {
            margin: 0; background: var(--bg); color: var(--text);
            font: 14px/1.55 var(--sans); letter-spacing: .1px;
            -webkit-font-smoothing: antialiased; text-rendering: optimizeLegibility;
            min-height: 100vh;
        }
        /* Atmosphere: radial glows + fine grid, fixed behind content */
        body::before {
            content: ""; position: fixed; inset: 0; z-index: -2; pointer-events: none;
            background:
                radial-gradient(720px 420px at 12% -8%, rgba(52, 224, 200, .10), transparent 60%),
                radial-gradient(680px 480px at 100% 0%, rgba(77, 124, 255, .10), transparent 55%),
                linear-gradient(180deg, var(--bg-2), var(--bg) 60%);
        }
        body::after {
            content: ""; position: fixed; inset: 0; z-index: -1; pointer-events: none; opacity: .5;
            background-image:
                linear-gradient(rgba(130, 150, 190, .035) 1px, transparent 1px),
                linear-gradient(90deg, rgba(130, 150, 190, .035) 1px, transparent 1px);
            background-size: 44px 44px;
            mask-image: radial-gradient(circle at 50% 0%, #000 0%, transparent 78%);
            -webkit-mask-image: radial-gradient(circle at 50% 0%, #000 0%, transparent 78%);
        }
        a { color: var(--accent); text-decoration: none; }
        .wrap { max-width: 1200px; margin: 0 auto; padding: 34px 26px 80px; }

        header.top {
            display: flex; align-items: center; justify-content: space-between;
            gap: 16px; flex-wrap: wrap; margin-bottom: 12px;
        }
        .brand { display: flex; align-items: center; gap: 14px; }
        .glyph {
            width: 38px; height: 38px; border-radius: 11px; flex: none;
            background: linear-gradient(150deg, var(--accent), var(--accent-2));
            box-shadow: 0 8px 24px -8px rgba(52, 224, 200, .55), inset 0 0 0 1px rgba(255, 255, 255, .18);
            display: grid; place-items: center; color: #04211d; font: 800 18px/1 var(--mono);
        }
        .brand h1 {
            font: 700 20px/1 var(--mono); margin: 0; letter-spacing: -.4px;
        }
        .brand h1 .accent { color: var(--accent); }
        .brand .tag { display: block; color: var(--muted); font-size: 11.5px; letter-spacing: .14em; text-transform: uppercase; margin-top: 5px; }
        .live {
            display: inline-flex; align-items: center; gap: 8px; font: 600 11.5px/1 var(--mono);
            letter-spacing: .12em; text-transform: uppercase; color: var(--muted);
            border: 1px solid var(--border-strong); border-radius: 999px; padding: 8px 13px;
            background: rgba(19, 24, 34, .5);
        }
        .live .beacon { width: 8px; height: 8px; border-radius: 50%; background: var(--ok); box-shadow: 0 0 0 0 rgba(62, 207, 142, .6); animation: beacon 2.4s ease-out infinite; }
        .live b { color: var(--text); }
        @keyframes beacon {
            0% { box-shadow: 0 0 0 0 rgba(62, 207, 142, .5); }
            70% { box-shadow: 0 0 0 7px rgba(62, 207, 142, 0); }
            100% { box-shadow: 0 0 0 0 rgba(62, 207, 142, 0); }
        }

        .muted { color: var(--muted); }
        .rule { height: 1px; background: linear-gradient(90deg, var(--border-strong), transparent); margin: 26px 0 4px; }
        h2 {
            font: 600 11.5px/1 var(--mono); text-transform: uppercase; letter-spacing: .16em;
            color: var(--faint); margin: 34px 0 15px; display: flex; align-items: center; gap: 10px;
        }
        h2::before { content: ""; width: 5px; height: 5px; border-radius: 50%; background: var(--accent); box-shadow: 0 0 10px var(--accent); }

        .grid { display: grid; gap: 15px; }
        .cards { grid-template-columns: repeat(auto-fill, minmax(224px, 1fr)); }
        .kpis { grid-template-columns: repeat(auto-fill, minmax(168px, 1fr)); }

        .panel {
            position: relative; background: var(--panel); border: 1px solid var(--border);
            border-radius: var(--radius); padding: 17px 19px;
            backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px);
        }

        .kpi { overflow: hidden; }
        .kpi::before { content: ""; position: absolute; inset: 0 0 auto 0; height: 2px; background: linear-gradient(90deg, var(--accent), transparent 70%); opacity: .7; }
        .kpi .n { font: 650 30px/1.05 var(--mono); letter-spacing: -1px; font-variant-numeric: tabular-nums; }
        .kpi .l { color: var(--muted); font-size: 12px; margin-top: 7px; letter-spacing: .02em; }
        .kpi .n .u { font-size: 17px; color: var(--muted); margin-left: 2px; }

        .gw { display: flex; flex-direction: column; gap: 13px; transition: transform .18s ease, border-color .18s ease; }
        .gw:hover { transform: translateY(-2px); border-color: var(--border-strong); }
        .gw .row1 { display: flex; align-items: flex-start; justify-content: space-between; gap: 8px; }
        .gw .name { font-weight: 600; font-size: 14.5px; letter-spacing: -.1px; }
        .gw .key { font: 500 11px/1 var(--mono); color: var(--faint); margin-top: 5px; letter-spacing: .04em; }
        .gw .stat { width: 9px; height: 9px; border-radius: 50%; flex: none; margin-top: 4px; }
        .gw .stat.on { background: var(--ok); box-shadow: 0 0 12px rgba(62, 207, 142, .8); }
        .gw .stat.off { background: var(--faint); }
        .badges { display: flex; gap: 6px; flex-wrap: wrap; }
        .badge { font: 600 10.5px/1 var(--mono); padding: 5px 9px; border-radius: 7px; border: 1px solid var(--border); color: var(--muted); letter-spacing: .04em; text-transform: uppercase; }
        .badge.ok { color: var(--ok); background: var(--ok-soft); border-color: transparent; }
        .badge.off { color: var(--faint); }
        .badge.live-mode { color: var(--warn); background: var(--warn-soft); border-color: transparent; }
        .badge.test { color: var(--accent); background: var(--accent-soft); border-color: transparent; }
        .badge.default { color: var(--text); border-color: var(--border-strong); }

        .scroll { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 12px 14px; border-bottom: 1px solid var(--border); font-variant-numeric: tabular-nums; white-space: nowrap; }
        th { color: var(--faint); font: 600 10.5px/1 var(--mono); text-transform: uppercase; letter-spacing: .1em; }
        tbody tr { transition: background .12s ease; }
        tbody tr:hover { background: rgba(120, 140, 175, .05); }
        tbody tr:last-child td { border-bottom: 0; }
        .mono { font-family: var(--mono); font-size: 12.5px; }
        td.mono { color: #c3ccdd; }

        .pill { font: 600 11px/1 var(--mono); padding: 5px 10px; border-radius: 999px; letter-spacing: .04em; display: inline-block; }
        .pill.s-ok { color: var(--ok); background: var(--ok-soft); box-shadow: inset 0 0 0 1px rgba(62, 207, 142, .22); }
        .pill.s-warn { color: var(--warn); background: var(--warn-soft); box-shadow: inset 0 0 0 1px rgba(255, 191, 70, .22); }
        .pill.s-bad { color: var(--bad); background: var(--bad-soft); box-shadow: inset 0 0 0 1px rgba(255, 92, 114, .22); }
        .pill.s-none { color: var(--faint); background: var(--panel-2); }
        .empty { color: var(--muted); padding: 34px 12px; text-align: center; font-size: 13px; }

        form.lookup { display: flex; gap: 11px; flex-wrap: wrap; align-items: center; }
        select, input, button { font: inherit; color: var(--text); background: var(--panel-2); border: 1px solid var(--border-strong); border-radius: 9px; padding: 11px 13px; transition: border-color .15s ease, box-shadow .15s ease; }
        select:focus, input:focus { outline: 0; border-color: var(--accent); box-shadow: 0 0 0 3px var(--accent-soft); }
        input { flex: 1; min-width: 240px; }
        input::placeholder { color: var(--faint); }
        button { background: linear-gradient(150deg, var(--accent), var(--accent-2)); border-color: transparent; color: #04211d; cursor: pointer; font-weight: 700; letter-spacing: .01em; }
        button:hover { filter: brightness(1.08); }
        button:active { transform: translateY(1px); }
        .note { margin-top: 13px; padding: 11px 15px; border-radius: 9px; background: var(--warn-soft); color: var(--warn); font-size: 13px; border: 1px solid rgba(255, 191, 70, .18); }

        .reveal { opacity: 0; animation: reveal .5s cubic-bezier(.22, 1, .36, 1) forwards; }
        @keyframes reveal { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: none; } }
        @media (prefers-reduced-motion: reduce) {
            .reveal { animation: none; opacity: 1; }
            .live .beacon { animation: none; }
        }
    </style>
    @yield('head')
</head>
<body>
    <div class="wrap">
        <header class="top">
            <div class="brand">
                <span class="glyph">H</span>
                <div>
                    <h1>hyprpay<span class="accent">·</span>monitor</h1>
                    <span class="tag">Payment gateway telemetry</span>
                </div>
            </div>
            <span class="live"><span class="beacon"></span> Live <b id="updated-at">now</b></span>
        </header>
        @yield('content')
    </div>
    @yield('scripts')
</body>
</html>
