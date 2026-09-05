<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Hyprpay')</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600;700&family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,400,0,0&display=swap">
    <style>
        :root {
            --bg: #0c0c0c;
            --bg-2: #101010;
            --panel: #1b1b1b;
            --panel-2: #161616;
            --panel-3: #131313;
            --border: rgba(255, 255, 255, .09);
            --border-strong: rgba(255, 255, 255, .17);
            --text: #ffffff;
            --muted: #9b9b9b;
            --faint: #6a6a6a;
            --accent: #2f590f;
            --accent-2: #3c7314;
            --accent-ink: #ffffff;
            --accent-soft: rgba(47, 89, 15, .28);
            --ok: #5aa62a; --ok-soft: rgba(90, 166, 42, .16);
            --warn: #d7a13f; --warn-soft: rgba(215, 161, 63, .14);
            --bad: #d9534f; --bad-soft: rgba(217, 83, 79, .14);
            --mono: "JetBrains Mono", ui-monospace, "SF Mono", "SFMono-Regular", Menlo, Consolas, monospace;
            --sans: "IBM Plex Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", system-ui, Roboto, Helvetica, Arial, sans-serif;
            --display: "SF Pro Display", -apple-system, BlinkMacSystemFont, "Segoe UI", system-ui, sans-serif;
            --radius: 5px;
        }
        * { box-sizing: border-box; }
        html { -webkit-text-size-adjust: 100%; }
        body {
            margin: 0; background: var(--bg); color: var(--text);
            font: 14px/1.55 var(--sans);
            -webkit-font-smoothing: antialiased; text-rendering: optimizeLegibility;
            min-height: 100vh;
        }
        /* Atmosphere: restrained green wash + fine grid, fixed behind content */
        body::before {
            content: ""; position: fixed; inset: 0; z-index: -2; pointer-events: none;
            background:
                radial-gradient(760px 480px at 4% -14%, rgba(47, 89, 15, .18), transparent 56%),
                radial-gradient(680px 480px at 112% 6%, rgba(47, 89, 15, .09), transparent 55%),
                linear-gradient(180deg, var(--bg-2), var(--bg) 62%);
        }
        body::after {
            content: ""; position: fixed; inset: 0; z-index: -1; pointer-events: none; opacity: .5;
            background-image:
                linear-gradient(rgba(255, 255, 255, .022) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255, 255, 255, .022) 1px, transparent 1px);
            background-size: 48px 48px;
            mask-image: radial-gradient(circle at 30% 0%, #000 0%, transparent 74%);
            -webkit-mask-image: radial-gradient(circle at 30% 0%, #000 0%, transparent 74%);
        }
        a { color: var(--ok); text-decoration: none; }
        .muted { color: var(--muted); }
        .mi {
            font-family: 'Material Symbols Outlined'; font-weight: normal; font-style: normal; font-size: 20px;
            line-height: 1; letter-spacing: normal; text-transform: none; white-space: nowrap; display: inline-block;
            direction: ltr; -webkit-font-smoothing: antialiased; -webkit-font-feature-settings: 'liga'; font-feature-settings: 'liga';
            font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24; user-select: none;
        }

        /* ---- Top bar + full-width main ---- */
        .topbar {
            position: sticky; top: 0; z-index: 40;
            display: flex; align-items: center; gap: 16px;
            padding: 13px 30px; border-bottom: 1px solid var(--border);
            background: rgba(12, 12, 12, .82); backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px);
        }
        .brand { display: flex; align-items: center; gap: 12px; }
        .glyph { width: 34px; height: 34px; flex: none; display: block; }
        .glyph svg { width: 100%; height: 100%; display: block; }
        .brand .lock { display: flex; flex-direction: column; }
        .brand h1 { font: 700 16px/19px var(--display); margin: 0; letter-spacing: -.2px; }
        .brand h1 .accent { color: var(--ok); }
        .brand .tag { color: var(--faint); font: 600 9px/15px var(--sans); letter-spacing: .16em; text-transform: uppercase; }

        .live {
            display: inline-flex; align-items: center; gap: 9px; font: 600 11px/1 var(--sans);
            letter-spacing: .08em; text-transform: uppercase; color: var(--muted);
            border: 1px solid var(--border-strong); border-radius: 5px; padding: 9px 13px;
            background: rgba(18, 23, 15, .5);
        }
        .live .beacon { width: 7px; height: 7px; border-radius: 50%; background: var(--ok); flex: none; }
        .live b { color: var(--text); font-variant-numeric: tabular-nums; }

        .switch { margin-left: auto; display: inline-flex; align-items: center; gap: 9px; cursor: pointer; user-select: none; }
        .switch input { position: absolute; width: 0; height: 0; opacity: 0; }
        .switch .track { position: relative; width: 34px; height: 19px; flex: none; border-radius: 999px; background: var(--panel-2); border: 1px solid var(--border-strong); transition: background .16s ease, border-color .16s ease; }
        .switch .thumb { position: absolute; top: 2px; left: 2px; width: 13px; height: 13px; border-radius: 50%; background: var(--muted); transition: transform .16s ease, background .16s ease; }
        .switch input:checked + .track { background: var(--accent-soft); border-color: rgba(90, 166, 42, .5); }
        .switch input:checked + .track .thumb { transform: translateX(15px); background: var(--ok); }
        .switch input:focus-visible + .track { box-shadow: 0 0 0 3px var(--accent-soft); }
        .switch .switch-label { font: 600 11px/1 var(--sans); letter-spacing: .08em; text-transform: uppercase; color: var(--muted); }

        /* ---- Main column ---- */
        .main { min-width: 0; padding: 26px 30px 90px; }

        header.top { display: flex; align-items: flex-end; justify-content: space-between; gap: 16px; flex-wrap: wrap; margin-bottom: 22px; }
        header.top .pagetitle { font: 700 27px/1.1 var(--display); letter-spacing: -.5px; margin: 0; }
        header.top .subtitle { color: var(--muted); font-size: 13px; margin-top: 6px; }
        header.top .updated { color: var(--faint); font: 500 11.5px/1 var(--sans); }

        h2 {
            font: 600 11px/1 var(--sans); text-transform: uppercase; letter-spacing: .14em;
            color: var(--faint); margin: 34px 0 15px; display: flex; align-items: center; gap: 10px; scroll-margin-top: 22px;
        }
        h2::before { content: ""; width: 5px; height: 5px; border-radius: 50%; background: var(--accent); }

        .grid { display: grid; gap: 15px; }
        .cards { grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); }
        .kpis { grid-template-columns: repeat(auto-fit, minmax(196px, 1fr)); }
        .split { grid-template-columns: 1.15fr 1fr 1fr; align-items: stretch; }
        @media (max-width: 1080px) { .split { grid-template-columns: 1fr 1fr; } }

        .panel {
            position: relative; background: var(--panel); border: 1px solid var(--border);
            border-radius: var(--radius); padding: 18px 20px;
            backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px);
        }
        .panel > .head { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 14px; }
        .panel > .head .t { font-weight: 600; font-size: 14px; letter-spacing: -.1px; }
        .panel > .head .s { color: var(--faint); font: 600 10.5px/1 var(--sans); letter-spacing: .1em; text-transform: uppercase; }

        /* KPI card */
        .kpi { overflow: hidden; }
        .kpi .l { color: var(--muted); font-size: 12px; display: flex; align-items: center; gap: 8px; }
        .kpi-row { display: flex; align-items: flex-end; justify-content: space-between; gap: 12px; margin-top: 12px; }
        .kpi .n { font: 700 32px/1.05 var(--sans); letter-spacing: -1.2px; font-variant-numeric: tabular-nums; }
        .kpi .n .u { font-size: 17px; color: var(--muted); margin-left: 2px; letter-spacing: 0; }
        .kpi .sub { color: var(--faint); font-size: 11.5px; margin-top: 8px; }
        .spark { width: 94px; height: 30px; flex: none; display: block; }
        .chip .tri { font-size: 8px; vertical-align: 1px; }
        .chip { font: 600 11px/1 var(--sans); padding: 4px 8px; border-radius: 4px; font-variant-numeric: tabular-nums; }
        .chip.up { color: var(--ok); background: var(--ok-soft); }
        .chip.down { color: var(--bad); background: var(--bad-soft); }
        .chip.neutral { color: var(--muted); background: rgba(150, 180, 110, .08); }

        /* Donut ring */
        .panel.ops { display: flex; flex-direction: column; }
        .ring-wrap { display: flex; align-items: center; gap: 20px; flex: 1; }
        .ring { width: 132px; height: 132px; flex: none; transform: rotate(-90deg); }
        .ring .track { fill: none; stroke: rgba(150, 180, 110, .12); stroke-width: 12; }
        .ring .arc { fill: none; stroke: url(#ringgrad); stroke-width: 12; stroke-linecap: round; transition: stroke-dashoffset .7s cubic-bezier(.22, 1, .36, 1); }
        .ring-c { position: relative; display: grid; place-items: center; }
        .ring-c .big { position: absolute; text-align: center; }
        .ring-c .big .v { font: 700 26px/1 var(--sans); letter-spacing: -1px; font-variant-numeric: tabular-nums; }
        .ring-c .big .k { color: var(--faint); font-size: 10px; font-weight: 600; letter-spacing: .12em; text-transform: uppercase; margin-top: 4px; }
        .legend { display: flex; flex-direction: column; gap: 12px; }
        .legend .row { display: flex; align-items: center; gap: 10px; }
        .legend .dot { width: 9px; height: 9px; border-radius: 50%; flex: none; }
        .legend .lbl { color: var(--muted); font-size: 12.5px; }
        .legend .val { margin-left: auto; font: 600 13px/1 var(--sans); font-variant-numeric: tabular-nums; }

        /* Segmented bar + breakdown list (by gateway / status) */
        .segbar { display: flex; height: 9px; border-radius: 3px; overflow: hidden; gap: 2px; background: var(--panel-3); margin-bottom: 16px; }
        .segbar span { display: block; height: 100%; }
        .breakdown { display: flex; flex-direction: column; }
        .breakdown .row { display: flex; align-items: center; gap: 11px; padding: 9px 0; border-bottom: 1px solid var(--border); }
        .breakdown .row:last-child { border-bottom: 0; }
        .breakdown .dot { width: 8px; height: 8px; border-radius: 50%; flex: none; }
        .breakdown .lbl { font-size: 13px; }
        .breakdown .pct { margin-left: auto; color: var(--muted); font: 600 12px/1 var(--sans); font-variant-numeric: tabular-nums; }
        .breakdown .cnt { color: var(--text); font: 600 12.5px/1 var(--sans); min-width: 42px; text-align: right; font-variant-numeric: tabular-nums; }
        .breakdown.empty-list { color: var(--faint); font-size: 12.5px; padding: 20px 0; text-align: center; }

        /* Gateway health cards */
        .gw { display: flex; flex-direction: column; gap: 13px; transition: transform .18s ease, border-color .18s ease; }
        .gw:hover { transform: translateY(-2px); border-color: var(--border-strong); }
        .gw .row1 { display: flex; align-items: flex-start; justify-content: space-between; gap: 8px; }
        .gw .name { font-weight: 600; font-size: 14.5px; letter-spacing: -.1px; }
        .gw .key { font: 500 11px/1 var(--mono); color: var(--faint); margin-top: 5px; letter-spacing: .04em; }
        .gw .stat { width: 9px; height: 9px; border-radius: 50%; flex: none; margin-top: 4px; }
        .gw .stat.on { background: var(--ok); box-shadow: 0 0 0 3px rgba(90, 166, 42, .16); }
        .gw .stat.off { background: var(--faint); }
        .gw-metric { display: flex; align-items: flex-end; justify-content: space-between; gap: 12px; }
        .gw-count { display: flex; align-items: baseline; gap: 5px; }
        .gw-count-n { font: 700 22px/1 var(--sans); font-variant-numeric: tabular-nums; letter-spacing: -.5px; }
        .gw-count-u { color: var(--muted); font-size: 11.5px; }
        .gw-spark { width: 88px; height: 28px; }
        .badges { display: flex; gap: 6px; flex-wrap: wrap; }
        .badge { font: 600 10.5px/1 var(--sans); padding: 5px 9px; border-radius: 4px; border: 1px solid var(--border); color: var(--muted); letter-spacing: .04em; text-transform: uppercase; }
        .badge.ok { color: var(--ok); background: var(--accent-soft); border-color: transparent; }
        .badge.off { color: var(--faint); }
        .badge.live-mode { color: var(--warn); background: var(--warn-soft); border-color: transparent; }
        .badge.test { color: var(--ok); background: var(--ok-soft); border-color: transparent; }
        .badge.default { color: var(--text); border-color: var(--border-strong); }

        /* Tables */
        .scroll { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 13px 14px; border-bottom: 1px solid var(--border); font-variant-numeric: tabular-nums; white-space: nowrap; }
        th { color: var(--faint); font: 600 10.5px/1 var(--sans); text-transform: uppercase; letter-spacing: .09em; }
        tbody tr { transition: background .12s ease; }
        tbody tr:hover { background: rgba(255, 255, 255, .04); }
        tbody tr:last-child td { border-bottom: 0; }
        .mono { font-family: var(--mono); font-size: 12.5px; }
        td.mono { color: #c7c7c7; }

        .pill { font: 600 11px/1 var(--sans); padding: 5px 10px; border-radius: 4px; letter-spacing: .02em; display: inline-block; }
        .pill.s-ok { color: var(--ok); background: var(--ok-soft); box-shadow: inset 0 0 0 1px rgba(74, 222, 128, .22); }
        .pill.s-warn { color: var(--warn); background: var(--warn-soft); box-shadow: inset 0 0 0 1px rgba(255, 191, 70, .22); }
        .pill.s-bad { color: var(--bad); background: var(--bad-soft); box-shadow: inset 0 0 0 1px rgba(255, 92, 114, .22); }
        .pill.s-none { color: var(--faint); background: var(--panel-2); }
        .empty { color: var(--muted); padding: 34px 12px; text-align: center; font-size: 13px; }

        /* Log panel */
        .log-entry { border-bottom: 1px solid var(--border); }
        .log-entry:last-child { border-bottom: 0; }
        .log-row { display: grid; grid-template-columns: 72px 150px 1fr 24px; gap: 14px; align-items: baseline; padding: 9px 2px; }
        .log-entry.has-detail .log-row { cursor: pointer; }
        .log-entry.has-detail .log-row:hover { background: rgba(255, 255, 255, .03); }
        .log-chev { color: var(--faint); font-size: 20px; align-self: center; transition: transform .18s ease; }
        .log-entry.open .log-chev { transform: rotate(180deg); }
        .log-level { font: 700 9.5px/1.7 var(--sans); letter-spacing: .06em; text-transform: uppercase; padding: 2px 0; border-radius: 4px; text-align: center; }
        .log-level.s-bad { color: var(--bad); background: var(--bad-soft); }
        .log-level.s-warn { color: var(--warn); background: var(--warn-soft); }
        .log-level.s-ok { color: var(--ok); background: var(--ok-soft); }
        .log-level.s-none { color: var(--faint); background: var(--panel-2); }
        .log-time { color: var(--faint); font: 500 11.5px/1.6 var(--mono); }
        .log-msg { color: #d7dcd0; font-size: 12.5px; word-break: break-word; }
        .log-detail { margin: 0 2px 12px 88px; padding: 9px 11px; background: var(--panel-2); border-radius: 5px; color: var(--muted); font: 500 11.5px/1.55 var(--mono); white-space: pre-wrap; word-break: break-word; max-height: 260px; overflow: auto; }

        /* Sortable table headers */
        th.sortable { cursor: pointer; user-select: none; transition: color .12s ease; }
        th.sortable:hover { color: var(--muted); }
        th.sortable::after { content: "↕"; margin-left: 6px; font-size: 10px; opacity: .3; }
        th.sortable.asc::after { content: "↑"; opacity: 1; color: var(--ok); }
        th.sortable.desc::after { content: "↓"; opacity: 1; color: var(--ok); }

        /* Table filter bar */
        .tablebar { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-bottom: 15px; }
        .tablebar .f-count { margin-left: auto; color: var(--faint); font: 500 11.5px/1 var(--mono); font-variant-numeric: tabular-nums; white-space: nowrap; }

        /* Lookup form */
        form.lookup { display: flex; gap: 11px; flex-wrap: wrap; align-items: center; }
        select, input, button { font: inherit; color: var(--text); background: var(--panel-2); border: 1px solid var(--border-strong); border-radius: 5px; padding: 11px 13px; transition: border-color .15s ease, box-shadow .15s ease; }
        select:focus, input:focus { outline: 0; border-color: var(--ok); box-shadow: 0 0 0 3px var(--accent-soft); }
        select {
            appearance: none; -webkit-appearance: none; cursor: pointer; padding-right: 36px; min-width: 152px;
            background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%239b9b9b' stroke-width='2.2' stroke-linecap='round' stroke-linejoin='round'><polyline points='6 9 12 15 18 9'/></svg>");
            background-repeat: no-repeat; background-position: right 12px center;
        }
        select:hover { border-color: var(--border-strong); }
        option { background: var(--panel-2); color: var(--text); }
        input { flex: 1; min-width: 240px; }
        input::placeholder { color: var(--faint); }
        .tablebar input { min-width: 200px; }
        .tablebar select { min-width: 148px; padding-top: 9px; padding-bottom: 9px; }
        button { background: var(--accent); border-color: transparent; color: var(--accent-ink); cursor: pointer; font-weight: 700; letter-spacing: .01em; display: inline-flex; align-items: center; justify-content: center; gap: 7px; }
        button:hover { background: var(--accent-2); }
        button:active { transform: translateY(1px); }
        button .mi { font-size: 18px; }
        .note { margin-top: 13px; padding: 11px 15px; border-radius: 5px; background: var(--warn-soft); color: var(--warn); font-size: 13px; border: 1px solid rgba(255, 191, 70, .18); }

        .reveal { opacity: 0; animation: reveal .5s cubic-bezier(.22, 1, .36, 1) forwards; }
        @keyframes reveal { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: none; } }

        /* Lifecycle drawer */
        #activity-body tr.has-ref { cursor: pointer; }
        .drawer { position: fixed; inset: 0; z-index: 60; display: none; }
        .drawer.open { display: block; }
        .drawer-scrim { position: absolute; inset: 0; background: rgba(0, 0, 0, .55); backdrop-filter: blur(2px); -webkit-backdrop-filter: blur(2px); opacity: 0; animation: scrim .2s ease forwards; }
        @keyframes scrim { to { opacity: 1; } }
        .drawer-panel {
            position: absolute; top: 0; right: 0; height: 100%; width: min(560px, 94vw);
            display: flex; flex-direction: column; background: var(--bg-2); border-left: 1px solid var(--border-strong);
            box-shadow: -24px 0 60px -20px rgba(0, 0, 0, .7); transform: translateX(100%); animation: slidein .28s cubic-bezier(.22, 1, .36, 1) forwards;
        }
        @keyframes slidein { to { transform: none; } }
        .drawer-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; padding: 20px 22px 15px; border-bottom: 1px solid var(--border); }
        .drawer-head .ref { font: 700 18px/1.25 var(--display); letter-spacing: -.2px; word-break: break-all; }
        .drawer-head .sub { color: var(--muted); font-size: 12.5px; margin-top: 7px; display: flex; align-items: center; gap: 9px; flex-wrap: wrap; }
        .drawer-x { background: transparent; border: 1px solid var(--border-strong); color: var(--muted); border-radius: 5px; width: 32px; height: 32px; padding: 0; display: grid; place-items: center; cursor: pointer; flex: none; font-size: 14px; }
        .drawer-x:hover { color: var(--text); background: rgba(255, 255, 255, .04); }
        .drawer-tabs { display: flex; gap: 6px; padding: 0 22px; border-bottom: 1px solid var(--border); }
        .dw-tab { background: transparent; border: 0; border-bottom: 2px solid transparent; border-radius: 0; color: var(--muted); padding: 13px 4px; margin-bottom: -1px; font: 600 13px/1 var(--sans); cursor: pointer; }
        .dw-tab:hover { background: transparent; color: var(--text); }
        .dw-tab:active { transform: none; }
        .dw-tab.active { color: var(--text); border-bottom-color: var(--ok); }
        .dw-tab-ct { color: var(--faint); font: 500 11px/1 var(--mono); margin-left: 3px; }
        .drawer-summary { display: flex; flex-wrap: wrap; gap: 16px 26px; }
        .drawer-summary .cell { display: flex; flex-direction: column; gap: 4px; }
        .drawer-summary .k { color: var(--faint); font: 600 10px/1 var(--sans); letter-spacing: .1em; text-transform: uppercase; }
        .drawer-summary .v { font: 600 14px/1.2 var(--sans); font-variant-numeric: tabular-nums; }
        .drawer-body { flex: 1; overflow-y: auto; padding: 20px 22px; }
        .drawer-foot { display: flex; align-items: center; gap: 10px; padding: 14px 22px; border-top: 1px solid var(--border); }
        .drawer-empty { color: var(--muted); text-align: center; padding: 44px 12px; font-size: 13px; }
        .dw-live-h { color: var(--faint); font: 600 10px/1 var(--sans); letter-spacing: .1em; text-transform: uppercase; margin: 2px 0 10px; }
        .dw-live:not(:empty) { margin-top: 20px; padding-top: 18px; border-top: 1px dashed var(--border); }

        /* Collapsible attempt (History tab) */
        .attempt { border-bottom: 1px solid var(--border); }
        .attempt:last-child { border-bottom: 0; }
        .attempt-head { width: 100%; background: transparent; border: 0; border-radius: 0; display: flex; align-items: center; gap: 10px; padding: 13px 2px; cursor: pointer; text-align: left; color: var(--text); }
        .attempt-head:hover { background: rgba(255, 255, 255, .03); }
        .attempt-head:active { transform: none; }
        .attempt-dot { width: 10px; height: 10px; border-radius: 50%; flex: none; background: var(--faint); margin-left: -18px; }
        .attempt-dot.s-ok { background: var(--ok); } .attempt-dot.s-warn { background: var(--warn); } .attempt-dot.s-bad { background: var(--bad); }
        .attempt-op { font-weight: 600; font-size: 13.5px; }
        .attempt-status { display: flex; justify-content: flex-end; flex: none; }
        .attempt-status .pill { min-width: 90px; text-align: center; }
        .attempt-time { color: var(--faint); font: 500 11.5px/1 var(--mono); min-width: 58px; text-align: right; }
        .attempt-chev { color: var(--faint); font-size: 20px; transition: transform .18s ease; }
        .attempt.open .attempt-chev { transform: rotate(180deg); }
        /* the dot hangs into the gutter on -18px, so the head's text starts 4px in;
           match that here and the expanded detail lines up under its own title */
        .attempt-body { padding: 0 2px 15px 4px; }
        .tl-meta { display: flex; gap: 16px; flex-wrap: wrap; color: var(--muted); font: 500 12px/1.6 var(--mono); }
        .tl-meta b { color: #c7c7c7; font-weight: 500; }

        /* recorded gateway API response — status, headers, body, and the request behind it */
        .apires { margin-top: 12px; border: 1px solid var(--border); border-radius: 5px; overflow: hidden; }
        .apires + .apires { margin-top: 8px; }
        .apires-head { display: flex; align-items: center; gap: 9px; flex-wrap: nowrap; padding: 9px 11px; background: var(--panel-2); border-bottom: 1px solid var(--border); font: 600 11px/1.4 var(--mono); }
        .apires-method { flex: none; }
        .apires-method { color: var(--text); letter-spacing: .06em; }
        /* one line always — the status and timing stay put, and the full URL is on the title attribute */
        .apires-url { color: var(--muted); font-weight: 500; flex: 1 1 auto; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; direction: rtl; text-align: left; }
        .apires-url bdi { direction: ltr; }
        /* icon-only, so the row stays compact — the tooltip carries the wording */
        .apires-copy { flex: none; display: inline-flex; align-items: center; justify-content: center; padding: 3px; background: transparent; border: 0; border-radius: 3px; color: var(--faint); cursor: pointer; transition: color .15s ease, background .15s ease; }
        .apires-copy:hover { color: var(--text); background: rgba(255, 255, 255, .07); }
        .apires-copy.ok { color: var(--ok); }
        .apires-copy .mi { font-size: 14px; }
        /* pulled in vertically so it never makes its label row taller than the others */
        .apires-label .apires-copy { margin: -4px 0; }
        .apires-status { flex: none; padding: 1px 6px; border-radius: 3px; background: var(--ok-soft); color: var(--ok); }
        .apires-status.bad { background: var(--bad-soft); color: var(--bad); }
        .apires-ms { flex: none; color: var(--faint); font-weight: 500; }
        .apires-part { border-top: 1px solid var(--border); }
        .apires-part:first-of-type { border-top: 0; }
        .apires-label { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 6px 9px 5px 11px; color: var(--faint); font: 600 9px/1.4 var(--sans); letter-spacing: .16em; text-transform: uppercase; }
        .apires pre { margin: 0; padding: 0 11px 10px; overflow-x: auto; color: #c7c7c7; font: 500 11.5px/1.65 var(--mono); white-space: pre; tab-size: 2; }
        .apires pre.headers { color: var(--muted); }
        .apires .redacted { color: var(--bad); }
        .apires-empty { padding: 0 11px 10px; color: var(--faint); font: 500 11.5px/1.6 var(--mono); }

        .btn-ghost, .btn-primary { display: inline-flex; align-items: center; justify-content: center; gap: 7px; border-radius: 5px; padding: 9px 14px; font: 600 13px/1 var(--sans); cursor: pointer; transition: border-color .15s ease, background .15s ease; }
        .btn-ghost { background: var(--panel-2); border: 1px solid var(--border-strong); color: var(--text); }
        .btn-ghost:hover { border-color: var(--ok); }
        .btn-primary { background: var(--accent); border: 1px solid transparent; color: var(--accent-ink); font-weight: 700; }
        .btn-primary:hover { background: var(--accent-2); }

        @media (max-width: 820px) {
            .topbar { padding: 12px 18px; }
            .split { grid-template-columns: 1fr; }
            .main { padding: 20px 18px 70px; }
        }
        @media (prefers-reduced-motion: reduce) {
            .reveal { animation: none; opacity: 1; }
            .ring .arc { transition: none; }
        }
    </style>
    @yield('head')
</head>
<body>
    <svg width="0" height="0" style="position:absolute" aria-hidden="true">
        <defs>
            <linearGradient id="ringgrad" x1="0" y1="0" x2="1" y2="1">
                <stop offset="0%" stop-color="#2f590f"/>
                <stop offset="100%" stop-color="#6fae2e"/>
            </linearGradient>
        </defs>
    </svg>
    <header class="topbar">
        <div class="brand">
            <span class="glyph">
                <svg viewBox="0 0 100 100" role="img" aria-label="HyprPay">
                    <rect x="1" y="1" width="98" height="98" rx="24" fill="#0b0c0b" stroke="#1b1e25" stroke-width="1.5"/>
                    <g fill="none" stroke-linecap="round" stroke-linejoin="round" stroke-width="12">
                        <polyline points="24,31 46,50 24,69" stroke="#c8f14e"/>
                        <polyline points="49,31 71,50 49,69" stroke="#5e7726"/>
                    </g>
                </svg>
            </span>
            <div class="lock">
                <h1>hyprpay<span class="accent">·</span>monitor</h1>
                <span class="tag">Gateway telemetry</span>
            </div>
        </div>
        <label class="switch" title="Poll for new activity and logs every 10s">
            <input type="checkbox" id="auto-refresh">
            <span class="track"><span class="thumb"></span></span>
            <span class="switch-label">Auto-refresh</span>
        </label>
        <span class="live"><span class="beacon"></span> Live <b id="updated-at">now</b></span>
    </header>
    <main class="main">
        @yield('content')
    </main>
    @yield('scripts')
</body>
</html>
