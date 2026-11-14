@php
    use App\Http\Controllers\WikiController;
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="description" content="{{ $page['summary'] }}">
    <meta name="color-scheme" content="light dark">
    <title>{{ $page['title'] }} · Wiki StackCard</title>
    {{-- Own styles, no build step, no CDN, no web font: this page is public and self-contained. --}}
    <style>
        :root {
            --bg: #f6f7fb; --surface: #ffffff; --surface-2: #f1f4f9; --line: #dde3ec;
            --ink: #0f172a; --muted: #475569; --accent: #4338ca; --accent-ink: #ffffff;
            --code-bg: #0f172a; --code-ink: #e2e8f0; --code-line: #1e293b;
            /* One colour per brick, used by the text and the diagrams. */
            --agent: #047857; --agent-soft: #e7f7f0;
            --central: #4338ca; --central-soft: #eef0fe;
            --sema: #a34a06; --sema-soft: #fff6e5;
            --srv: #334155; --srv-soft: #eef1f5;
            --cve: #a21caf; --cve-soft: #fbefff;
            --ok: #047857; --ok-soft: #d1fae5; --warn: #92400e; --warn-soft: #fef3c7;
            --danger: #b91c1c; --danger-soft: #fee2e2; --gray: #475569; --gray-soft: #e2e8f0;
            --info: #1d4ed8; --info-soft: #eaf1ff;
            --shadow: 0 1px 2px rgb(15 23 42 / .06), 0 4px 16px rgb(15 23 42 / .05);
            --radius: 14px;
            --font: system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            --mono: ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #0b1020; --surface: #121a2b; --surface-2: #19233a; --line: #2a3650;
                --ink: #e6eaf2; --muted: #a3adbf; --accent: #a5b4fc; --accent-ink: #11173a;
                --code-bg: #070b16; --code-ink: #e2e8f0; --code-line: #1b2438;
                --agent: #6ee7b7; --agent-soft: #0c2a22;
                --central: #a5b4fc; --central-soft: #1b1f45;
                --sema: #fcd34d; --sema-soft: #2e2208;
                --srv: #cbd5e1; --srv-soft: #1c2638;
                --cve: #f0abfc; --cve-soft: #34103a;
                --ok: #6ee7b7; --ok-soft: #0c2a22; --warn: #fcd34d; --warn-soft: #33260a;
                --danger: #fca5a5; --danger-soft: #3a1313; --gray: #cbd5e1; --gray-soft: #283247;
                --info: #93c5fd; --info-soft: #132442;
                --shadow: 0 1px 2px rgb(0 0 0 / .4);
            }
        }

        *, *::before, *::after { box-sizing: border-box; }
        html { -webkit-text-size-adjust: 100%; scroll-padding-top: 5rem; }
        @media (prefers-reduced-motion: no-preference) { html { scroll-behavior: smooth; } }
        body { margin: 0; background: var(--bg); color: var(--ink); font: 16px/1.65 var(--font); overflow-wrap: break-word; }
        a { color: var(--accent); text-underline-offset: .18em; }
        a:hover { text-decoration-thickness: 2px; }
        :focus-visible { outline: 3px solid var(--accent); outline-offset: 2px; border-radius: 4px; }
        .skip { position: absolute; left: -999px; top: .5rem; background: var(--surface); padding: .5rem 1rem; z-index: 10; }
        .skip:focus { left: .5rem; }

        /* Top bar */
        .topbar { position: sticky; top: 0; z-index: 5; display: flex; align-items: center; gap: .75rem; padding: .7rem 1rem;
            background: color-mix(in srgb, var(--surface) 92%, transparent); backdrop-filter: blur(8px); border-bottom: 1px solid var(--line); }
        .brand { display: inline-flex; align-items: center; gap: .55rem; font-weight: 700; color: var(--ink); text-decoration: none; letter-spacing: -.01em; }
        .brand small { font-weight: 500; color: var(--muted); }
        .brand svg { flex: none; }
        .topbar .spacer { flex: 1; }
        .btn { display: inline-flex; align-items: center; gap: .4rem; padding: .45rem .9rem; border-radius: 999px; font-size: .9rem; font-weight: 600;
            text-decoration: none; border: 1px solid var(--line); color: var(--ink); background: var(--surface); cursor: pointer; font-family: inherit; }
        .btn-primary { background: var(--accent); border-color: var(--accent); color: var(--accent-ink); }
        .nav-toggle { display: none; }

        /* Layout */
        .shell { display: grid; grid-template-columns: 15rem minmax(0, 1fr) 13rem; gap: 2.5rem; max-width: 84rem; margin: 0 auto; padding: 2rem 1.5rem 4rem; }
        .sidenav, .toc { position: sticky; top: 5rem; align-self: start; max-height: calc(100vh - 6rem); overflow: auto; font-size: .92rem; }
        .sidenav { grid-column: 1; grid-row: 1; } main { grid-column: 2; grid-row: 1; } .toc { grid-column: 3; grid-row: 1; }
        .sidenav ol, .toc ol { list-style: none; margin: 0; padding: 0; }
        .sidenav h2, .toc h2 { font-size: .75rem; text-transform: uppercase; letter-spacing: .08em; color: var(--muted); margin: 0 0 .6rem; }
        .sidenav a { display: flex; gap: .6rem; align-items: baseline; padding: .45rem .7rem; border-radius: 10px; color: var(--ink); text-decoration: none; }
        .sidenav a:hover { background: var(--surface-2); }
        .sidenav a[aria-current="page"] { background: var(--surface); box-shadow: var(--shadow); font-weight: 650; color: var(--accent); }
        .sidenav .num { font: 600 .75rem var(--mono); color: var(--muted); min-width: 1.2rem; }
        .sidenav .admin-link { margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid var(--line); }
        .toc a { display: block; padding: .25rem 0 .25rem .8rem; border-left: 2px solid var(--line); color: var(--muted); text-decoration: none; }
        .toc a:hover { color: var(--ink); border-color: var(--accent); }

        main { min-width: 0; }
        .toc-inline { display: none; margin: -1rem 0 1.5rem; background: var(--surface); border: 1px solid var(--line); border-radius: 12px; font-size: .95rem; }
        .toc-inline summary { cursor: pointer; padding: .7rem 1rem; font-weight: 650; }
        .toc-inline ol { margin: 0; padding: 0 1rem .8rem 2.2rem; }
        .content { max-width: 72ch; }
        .eyebrow { font: 600 .78rem var(--mono); letter-spacing: .06em; text-transform: uppercase; color: var(--accent); margin: 0 0 .5rem; }
        h1 { font-size: clamp(1.9rem, 4vw, 2.6rem); line-height: 1.15; letter-spacing: -.025em; margin: 0 0 .75rem; }
        .lead { font-size: 1.15rem; color: var(--muted); margin: 0 0 2rem; }
        h2 { font-size: 1.5rem; line-height: 1.25; letter-spacing: -.015em; margin: 3rem 0 .75rem; padding-top: .5rem; }
        h3 { font-size: 1.12rem; margin: 1.8rem 0 .5rem; }
        p, ul, ol { margin: 0 0 1rem; }
        li { margin: .25rem 0; }
        strong { font-weight: 650; }
        code { font: .88em var(--mono); background: var(--surface-2); border: 1px solid var(--line); border-radius: 6px; padding: .05em .35em; }
        .codeblock { position: relative; margin: 0 0 1.25rem; }
        pre { margin: 0; padding: 1rem 1.1rem; background: var(--code-bg); color: var(--code-ink); border-radius: 12px; overflow-x: auto;
            font: .86rem/1.6 var(--mono); border: 1px solid var(--code-line); }
        pre code { background: none; border: 0; padding: 0; font: inherit; color: inherit; white-space: pre; }
        .codeblock pre { padding-right: 4.75rem; }
        .copy { position: absolute; top: .5rem; right: .5rem; font: 600 .75rem var(--font); color: var(--code-ink); background: #1e293b;
            border: 1px solid #334155; border-radius: 8px; padding: .25rem .6rem; cursor: pointer; }
        .copy:hover { background: #334155; }
        table { width: 100%; border-collapse: collapse; margin: 0 0 1.25rem; font-size: .94rem; }
        .table-wrap { overflow-x: auto; margin: 0 0 1.25rem; border: 1px solid var(--line); border-radius: 12px; background: var(--surface); }
        .table-wrap table { margin: 0; }
        th, td { text-align: left; vertical-align: top; padding: .6rem .8rem; border-bottom: 1px solid var(--line); }
        th { font-size: .8rem; text-transform: uppercase; letter-spacing: .05em; color: var(--muted); background: var(--surface-2); }
        tr:last-child td { border-bottom: 0; }

        /* Cards, callouts, steps, badges */
        .cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr)); gap: 1rem; margin: 0 0 1.5rem; }
        .card { background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius); padding: 1.1rem 1.2rem; box-shadow: var(--shadow);
            border-top: 4px solid var(--brick, var(--accent)); }
        .card h3 { margin: 0 0 .35rem; font-size: 1.05rem; }
        .card p { margin: 0; color: var(--muted); font-size: .95rem; }
        .card a.more { display: inline-block; margin-top: .6rem; font-weight: 600; font-size: .92rem; }
        .b-agent { --brick: var(--agent); } .b-central { --brick: var(--central); } .b-sema { --brick: var(--sema); }
        .b-srv { --brick: var(--srv); } .b-cve { --brick: var(--cve); }
        .callout { display: flex; gap: .8rem; margin: 0 0 1.25rem; padding: .9rem 1.1rem; border-radius: 12px; background: var(--info-soft);
            border-left: 4px solid var(--info); }
        .callout > svg { flex: none; margin-top: .2rem; color: var(--info); }
        .callout p:last-child, .callout ul:last-child { margin-bottom: 0; }
        .callout.warn { background: var(--warn-soft); border-color: var(--warn); }
        .callout.warn > svg { color: var(--warn); }
        .callout strong:first-child { display: block; }
        ol.steps { list-style: none; counter-reset: step; padding: 0; margin: 0 0 1.5rem; }
        ol.steps > li { counter-increment: step; position: relative; padding: 0 0 1.1rem 3rem; margin: 0; }
        ol.steps > li::before { content: counter(step); position: absolute; left: 0; top: -.05rem; width: 2rem; height: 2rem; border-radius: 50%;
            display: grid; place-items: center; font: 700 .9rem var(--font); background: var(--accent); color: var(--accent-ink); }
        ol.steps > li::after { content: ""; position: absolute; left: calc(1rem - 1px); top: 2.15rem; bottom: .2rem; width: 2px; background: var(--line); }
        ol.steps > li:last-child::after { display: none; }
        ol.steps > li > strong:first-child { display: block; }
        .badge { display: inline-block; padding: .05rem .55rem; border-radius: 999px; font-size: .82rem; font-weight: 650; white-space: nowrap;
            background: var(--gray-soft); color: var(--gray); }
        .badge.ok { background: var(--ok-soft); color: var(--ok); } .badge.warn { background: var(--warn-soft); color: var(--warn); }
        .badge.danger { background: var(--danger-soft); color: var(--danger); }
        dl.defs { display: grid; grid-template-columns: max-content 1fr; gap: .4rem 1rem; margin: 0 0 1.25rem; }
        dl.defs dt { font-weight: 650; } dl.defs dd { margin: 0; }
        .pager { display: flex; justify-content: space-between; gap: 1rem; margin-top: 3.5rem; padding-top: 1.5rem; border-top: 1px solid var(--line); }
        .pager a { display: block; flex: 1; max-width: 48%; padding: .8rem 1rem; border: 1px solid var(--line); border-radius: 12px; background: var(--surface);
            text-decoration: none; color: var(--ink); }
        .pager a:hover { border-color: var(--accent); }
        .pager small { display: block; color: var(--muted); }
        .pager .next { margin-left: auto; text-align: right; }
        footer.foot { margin-top: 2rem; font-size: .85rem; color: var(--muted); }

        /* Diagrams: inline SVG coloured by the variables above, so they follow the dark mode. */
        figure.diagram { margin: 1.5rem 0 2rem; padding: 1rem; background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius); box-shadow: var(--shadow); }
        figure.diagram svg { display: block; width: 100%; max-width: 46rem; height: auto; margin: 0 auto; }
        figure.diagram figcaption { margin-top: .75rem; font-size: .9rem; color: var(--muted); }
        .dg text { fill: var(--ink); font-family: var(--font); font-size: 15px; }
        .dg .t-title { font-weight: 700; font-size: 16px; }
        .dg .t-sub { fill: var(--muted); font-size: 14px; }
        .dg .t-mono { font-family: var(--mono); font-size: 13px; fill: var(--muted); }
        .dg .t-label { fill: var(--muted); font-size: 13px; font-weight: 600; }
        .dg .t-halo { paint-order: stroke; stroke: var(--surface); stroke-width: 5px; stroke-linejoin: round; }
        .dg .t-center { text-anchor: middle; }
        .dg .group { fill: none; stroke: var(--line); stroke-width: 1.5; stroke-dasharray: 6 5; }
        .dg .box { stroke-width: 2; }
        .dg .k-agent { fill: var(--agent-soft); stroke: var(--agent); }
        .dg .k-central { fill: var(--central-soft); stroke: var(--central); }
        .dg .k-sema { fill: var(--sema-soft); stroke: var(--sema); }
        .dg .k-srv { fill: var(--srv-soft); stroke: var(--srv); }
        .dg .k-cve { fill: var(--cve-soft); stroke: var(--cve); }
        .dg .k-plain { fill: var(--surface); stroke: var(--line); }
        .dg .k-ok { fill: var(--ok-soft); stroke: var(--ok); } .dg .k-warn { fill: var(--warn-soft); stroke: var(--warn); }
        .dg .k-danger { fill: var(--danger-soft); stroke: var(--danger); } .dg .k-gray { fill: var(--gray-soft); stroke: var(--gray); }
        .dg .c-agent { fill: var(--agent); } .dg .c-central { fill: var(--central); } .dg .c-sema { fill: var(--sema); }
        .dg .c-srv { fill: var(--srv); } .dg .c-cve { fill: var(--cve); }
        .dg .c-ok { fill: var(--ok); } .dg .c-warn { fill: var(--warn); } .dg .c-danger { fill: var(--danger); } .dg .c-gray { fill: var(--gray); }
        .dg .num { fill: var(--surface); font-weight: 700; font-size: 14px; text-anchor: middle; }
        .dg .arrow { fill: none; stroke: var(--muted); stroke-width: 2; }
        .dg .arrow.dashed { stroke-dasharray: 6 5; }
        .dg .head { fill: var(--muted); }
        .dg .arrow.agent { stroke: var(--agent); } .dg .head.agent { fill: var(--agent); }
        .dg .arrow.sema { stroke: var(--sema); } .dg .head.sema { fill: var(--sema); }
        .dg .arrow.central { stroke: var(--central); } .dg .head.central { fill: var(--central); }

        /* Tablet: the table of contents moves above the text. */
        @media (max-width: 72rem) {
            .shell { grid-template-columns: 14rem minmax(0, 1fr); }
            .toc { position: static; max-height: none; grid-column: 2; grid-row: 1; padding: 1rem 1.2rem; background: var(--surface); border: 1px solid var(--line); border-radius: 12px; }
            .toc ol { columns: 2; column-gap: 1.5rem; }
            main { grid-column: 2; grid-row: 2; }
            .sidenav { grid-row: 1 / span 2; }
        }
        /* Phone: one column, the menu opens from the top bar. */
        @media (max-width: 48rem) {
            .shell { display: block; padding: 1.25rem 1rem 3rem; }
            .js .nav-toggle { display: inline-flex; }
            .sidenav { position: static; max-height: none; margin-bottom: 1.25rem; padding: .75rem; background: var(--surface); border: 1px solid var(--line); border-radius: 12px; }
            .js .sidenav:not(.open) { display: none; }
            .toc { display: none; }
            .toc-inline { display: block; }
            .topbar .btn-primary span { display: none; }
            .brand small { display: none; }
            figure.diagram { padding: .5rem; margin-left: -.25rem; margin-right: -.25rem; }
            .pager { flex-direction: column; }
            .pager a { max-width: none; }
            dl.defs { grid-template-columns: 1fr; } dl.defs dd { margin-bottom: .5rem; }
        }
        @media print {
            .topbar, .sidenav, .toc, .pager, .copy { display: none !important; }
            .shell { display: block; }
        }
    </style>
    <script>document.documentElement.classList.add('js');</script>
</head>
<body>
<a class="skip" href="#contenu">Aller au contenu</a>

<header class="topbar">
    <button type="button" class="btn nav-toggle" aria-expanded="false" aria-controls="wiki-nav">
        <svg width="18" height="18" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M3 5h14v2H3zm0 4h14v2H3zm0 4h14v2H3z"/></svg>
        Menu
    </button>
    <a class="brand" href="{{ WikiController::url('') }}">
        <svg width="28" height="28" viewBox="0 0 28 28" aria-hidden="true">
            <rect x="3" y="4" width="22" height="6" rx="2" fill="var(--central)"/>
            <rect x="3" y="11" width="22" height="6" rx="2" fill="var(--agent)"/>
            <rect x="3" y="18" width="22" height="6" rx="2" fill="var(--sema)"/>
        </svg>
        StackCard <small>· Wiki</small>
    </a>
    <span class="spacer"></span>
    <a class="btn btn-primary" href="/admin" aria-label="Se connecter à l’admin">
        <svg width="16" height="16" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10 2a4 4 0 0 0-4 4v2H5a1 1 0 0 0-1 1v8a1 1 0 0 0 1 1h10a1 1 0 0 0 1-1V9a1 1 0 0 0-1-1h-1V6a4 4 0 0 0-4-4Zm-2 6V6a2 2 0 1 1 4 0v2H8Z"/></svg>
        <span>Se connecter à l’admin</span>
    </a>
</header>

<div class="shell">
    <nav class="sidenav" id="wiki-nav" aria-label="Pages du wiki">
        <h2>Pages</h2>
        <ol>
            @foreach ($pages as $slug => $item)
                <li>
                    <a href="{{ WikiController::url($slug) }}" @if ($slug === $current) aria-current="page" @endif>
                        <span class="num">{{ str_pad((string) ($loop->index + 1), 2, '0', STR_PAD_LEFT) }}</span>
                        <span>{{ $item['label'] }}</span>
                    </a>
                </li>
            @endforeach
        </ol>
        <div class="admin-link"><a href="/admin">Se connecter à l’admin</a></div>
    </nav>

    <aside class="toc" aria-labelledby="toc-title">
        <h2 id="toc-title">Sur cette page</h2>
        <ol>
            @foreach ($page['toc'] as $anchor => $heading)
                <li><a href="#{{ $anchor }}">{{ $heading }}</a></li>
            @endforeach
        </ol>
    </aside>

    <main id="contenu">
        <article class="content">
            <p class="eyebrow">Wiki StackCard</p>
            <h1>{{ $page['title'] }}</h1>
            <p class="lead">{{ $page['summary'] }}</p>
            <details class="toc-inline">
                <summary>Sur cette page</summary>
                <ol>
                    @foreach ($page['toc'] as $anchor => $heading)
                        <li><a href="#{{ $anchor }}">{{ $heading }}</a></li>
                    @endforeach
                </ol>
            </details>

            @yield('content')

            <nav class="pager" aria-label="Page précédente et suivante">
                @if ($previous !== null)
                    <a href="{{ WikiController::url($previous) }}"><small>← Précédent</small>{{ $pages[$previous]['label'] }}</a>
                @endif
                @if ($next !== null)
                    <a class="next" href="{{ WikiController::url($next) }}"><small>Suivant →</small>{{ $pages[$next]['label'] }}</a>
                @endif
            </nav>
            <footer class="foot">
                Les noms (web01, db01…), adresses (192.0.2.x, central.example.com) et jetons (<code>&lt;jeton&gt;</code>) de ce wiki sont des exemples.
            </footer>
        </article>
    </main>
</div>

<script>
    (function () {
        var toggle = document.querySelector('.nav-toggle'), nav = document.getElementById('wiki-nav');
        toggle.addEventListener('click', function () {
            var open = nav.classList.toggle('open');
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
        document.querySelectorAll('pre > code').forEach(function (code) {
            var pre = code.parentNode, wrap = document.createElement('div'), button = document.createElement('button');
            wrap.className = 'codeblock';
            pre.parentNode.insertBefore(wrap, pre);
            wrap.appendChild(pre);
            button.type = 'button';
            button.className = 'copy';
            button.textContent = 'Copier';
            button.addEventListener('click', function () {
                var done = function (text) { button.textContent = text; setTimeout(function () { button.textContent = 'Copier'; }, 1500); };
                if (navigator.clipboard) {
                    navigator.clipboard.writeText(code.textContent).then(function () { done('Copié'); }, function () { done('Échec'); });
                } else { done('Échec'); }
            });
            wrap.appendChild(button);
        });
    })();
</script>
</body>
</html>
