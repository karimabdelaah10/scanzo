@php
  // One line per section; add a row here and it appears in the sidebar and the drawer.
  $nav = [
    ['label' => 'المحلات', 'route' => 'admin.businesses.index', 'active' => 'admin.businesses.*'],
  ];
  $palettes = config('scanzo.admin_palettes');
  $v = config('scanzo.assets_version');
@endphp
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="{{ $palettes['navy']['deep'] }}">
<title>@yield('title', 'لوحة المنصة') — {{ config('app.name') }}</title>
{{-- Runs before the stylesheet paints: restores the saved palette so the panel
     never flashes the default one. Also publishes the palette list to the
     switcher (public/js/admin-shell.js). --}}
<script>
  window.ScanzoPalettes = @json($palettes);
  try {
    var saved = localStorage.getItem('scanzo:admin-palette');
    if (saved && window.ScanzoPalettes[saved]) {
      document.documentElement.dataset.palette = saved;
      document.querySelector('meta[name="theme-color"]').setAttribute('content', window.ScanzoPalettes[saved].deep);
    }
  } catch (e) {}
</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;600;700&family=Reem+Kufi:wght@600&display=swap">
<link rel="stylesheet" href="{{ asset('css/tokens.css') }}?v={{ $v }}">
<link rel="stylesheet" href="{{ asset('css/tables.css') }}?v={{ $v }}">
{{-- Server-rendered and self-contained on purpose: no Vite entry, so nothing
     here needs a build. See decision 14. Colours, type, space and motion come
     from tokens.css; nothing in this block defines one. --}}
<style>
  * { box-sizing: border-box; }
  body { margin:0; background:var(--bg); color:var(--fg); font:var(--fs-sm)/1.6 var(--f-body); }
  h1, h2 { font-family:var(--f-display); font-weight:600; }
  h1 { font-size:var(--fs-xl); margin:0 0 16px; } h2 { font-size:var(--fs-lg); margin:0 0 12px; }
  a { color:var(--accent-text); }
  :focus-visible { outline:3px solid var(--accent-text); outline-offset:2px; }

  /* ---- shell: a fixed column from 980px, a drawer under it ---- */
  /* The drawer grows from the inline-start edge (the right, in this RTL page).
     Its ring is inset, so it never adds a pixel of width; the shadow only shows
     while it is a drawer over the page. The switcher lives at the physical left,
     the far side of the screen from this column. */
  .side { position:fixed; inset-block:0; inset-inline-start:0; z-index:30; width:min(300px,calc(100vw - 72px)); height:100svh; /* 72px of page stays visible: the switcher's 50px circle at the left is never under the drawer */
          background:var(--side-bg); color:var(--side-fg); box-shadow:inset -1px 0 0 var(--side-line), var(--shadow-raised);
          display:flex; flex-direction:column; gap:20px;
          padding:calc(20px + env(safe-area-inset-top,0px)) 16px calc(20px + env(safe-area-inset-bottom,0px));
          padding-inline-start:calc(16px + env(safe-area-inset-right,0px));
          overflow-y:auto; overscroll-behavior:contain;
          transform:translateX(100%); visibility:hidden;
          transition:transform var(--dur-slow) var(--ease-sheet), visibility 0s linear var(--dur-slow); }
  .side.is-open { transform:none; visibility:visible; transition:transform var(--dur-slow) var(--ease-sheet); }
  html.drawer-open { overflow:hidden; } /* the page behind does not scroll while the drawer is up */
  .wordmark { display:flex; align-items:center; gap:10px; min-height:44px; font-family:var(--f-display); font-size:22px; line-height:1; color:inherit; text-decoration:none; }
  .wordmark svg { flex:none; width:32px; height:32px; }
  .wordmark small { display:block; margin-top:5px; font:400 var(--fs-xs)/1 var(--f-body); color:var(--side-fg-muted); }
  .side nav { display:flex; flex-direction:column; gap:4px; flex:1; }
  .side nav a { display:flex; align-items:center; min-height:44px; padding:0 14px; border-radius:var(--radius-sm); color:var(--side-fg-muted); text-decoration:none; font-weight:600;
                transition:background-color var(--dur-fast) ease, color var(--dur-fast) ease, box-shadow var(--dur-base) ease; }
  .side nav a:hover { background:var(--side-hover); color:var(--side-fg); }
  /* The active item: the soft pair and a 2px ring, not the full accent. The
     ring is inset, so selecting an item moves nothing. */
  .side nav a[aria-current="page"] { background:var(--accent-soft); color:var(--accent-soft-fg); box-shadow:inset 0 0 0 2px var(--c-accent-text); }
  .side :focus-visible, .top :focus-visible { outline-color:var(--side-fg); }
  .side-foot { display:flex; flex-direction:column; gap:10px; padding-top:14px; border-top:1px solid var(--side-line); }
  .side-foot .who { display:flex; align-items:center; min-height:44px; font-weight:600; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
  .side-foot .btn { background:transparent; color:var(--side-fg); box-shadow:inset 0 0 0 1px var(--side-line); width:100%; text-align:center; }
  .side-foot .btn:hover { background:var(--side-hover); }

  .top { position:sticky; top:0; z-index:10; display:flex; align-items:center; gap:12px;
         padding:max(8px,env(safe-area-inset-top,0px)) max(var(--gutter),env(safe-area-inset-right,0px)) 8px max(var(--gutter),env(safe-area-inset-left,0px));
         background:var(--side-bg); color:var(--side-fg); box-shadow:inset 0 -1px 0 var(--side-line); }
  .top .menu { width:44px; height:44px; display:grid; place-items:center; border-radius:var(--radius-sm); border:0; box-shadow:inset 0 0 0 1px var(--side-line);
               background:transparent; color:inherit; cursor:pointer; }
  .top .wordmark { font-size:19px; }
  .scrim { position:fixed; inset:0; z-index:20; background:rgba(15,23,36,.5); opacity:0; visibility:hidden;
           transition:opacity var(--dur-slow) ease, visibility 0s linear var(--dur-slow); }
  .scrim.is-on { opacity:1; visibility:visible; transition:opacity var(--dur-slow) ease, visibility 0s; }

  main { max-width:1100px; margin:0 auto; padding:20px var(--gutter) calc(96px + env(safe-area-inset-bottom,0px)); /* clear of the switcher at the bottom-left */ }
  .guest main { max-width:none; }

  @media (min-width:980px) {
    .shell { display:grid; grid-template-columns:272px minmax(0,1fr); align-items:start; }
    .side { position:sticky; top:0; width:auto; z-index:1; transform:none; visibility:visible; transition:none; box-shadow:inset -1px 0 0 var(--side-line); }
    .top, .scrim { display:none; }
    main { width:100%; padding-top:32px; }
  }

  /* ---- content ---- */
  /* A surface is a ring, not a border, and a resting card has no shadow. */
  .card { background:var(--surface); box-shadow:var(--ring); border-radius:var(--radius-md); padding:var(--space-4); margin-bottom:var(--space-4); }
  .row { display:flex; gap:12px; flex-wrap:wrap; align-items:end; }
  .grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:12px; }
  label { display:block; font-size:var(--fs-xs); color:var(--muted); margin-bottom:4px; }
  input, select, textarea { width:100%; min-height:44px; padding:9px 10px; border:1px solid var(--line); border-radius:var(--radius-sm); font:inherit; background:var(--surface); color:var(--fg); }
  input[type="color"] { width:56px; height:44px; padding:2px; cursor:pointer; }
  input[type="checkbox"] { width:auto; }
  textarea { min-height:90px; }
  .btn { display:inline-flex; align-items:center; justify-content:center; min-height:44px; padding:0 18px; border-radius:var(--radius-sm); border:0; background:var(--surface); color:var(--fg); box-shadow:var(--ring); font:inherit; font-weight:600; cursor:pointer; text-decoration:none;
         transition:transform var(--dur-fast) var(--ease-out), background-color var(--dur-fast) ease, box-shadow var(--dur-base) ease; }
  .btn:hover { box-shadow:var(--ring-hover); }
  .btn:active { transform:scale(.97); }
  /* A fill: the contrast-safe orange with its own foreground, never the true one. */
  .btn.primary { background:var(--accent); color:var(--accent-fg); box-shadow:none; }
  .btn.primary:hover { box-shadow:0 14px 30px -14px var(--c-accent); }
  .btn.danger { background:var(--bad); color:#fff; box-shadow:none; }
  .side-foot .btn:hover { box-shadow:inset 0 0 0 1px var(--side-fg-muted); }
  table { width:100%; border-collapse:collapse; background:var(--surface); }
  th, td { padding:10px 12px; border-bottom:1px solid var(--line); text-align:start; white-space:nowrap; }
  th { font-size:var(--fs-xs); color:var(--muted); font-weight:600; }
  tbody tr { transition:background-color var(--dur-fast) ease; }
  tbody tr:hover { background:color-mix(in srgb, var(--c-accent) 8%, var(--surface)); }
  .scroll { overflow-x:auto; box-shadow:var(--ring); border-radius:var(--radius-md); }
  .badge { display:inline-block; padding:2px 10px; border-radius:99px; font-size:var(--fs-xs); background:var(--ok-soft); color:var(--ok); }
  .badge.suspended { background:var(--bad-soft); color:var(--bad); }
  .badge.trial { background:var(--warn-soft); color:var(--warn); }
  .muted { color:var(--muted); } .mono { font-family:var(--f-mono); direction:ltr; unicode-bidi:plaintext; }
  .alert { padding:10px 14px; border-radius:var(--radius-sm); margin-bottom:16px; }
  .alert.ok { background:var(--ok-soft); color:var(--ok); } .alert.bad { background:var(--bad-soft); color:var(--bad); } .alert.warn { background:var(--warn-soft); color:var(--warn); }
  .err { color:var(--bad); font-size:var(--fs-xs); margin-top:4px; }
  .over { color:var(--bad); font-weight:700; }
  .pager { margin-top:12px; } .pager nav svg { width:18px; height:18px; }

  /* ---- brand form + live preview (business page and new business) ---- */
  .brand-preview { display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-top:12px; }
  .brand-preview .pv-side { background:var(--pv-side,var(--side-bg)); color:var(--pv-side-fg,var(--side-fg)); border-radius:var(--radius); padding:12px; display:flex; flex-direction:column; gap:6px; }
  .brand-preview .pv-nav { padding:10px 12px; border-radius:var(--radius-sm); font-weight:600; background:var(--pv-accent,var(--accent)); color:var(--pv-accent-fg,var(--accent-fg)); }
  .brand-preview .pv-nav.plain { background:transparent; opacity:.8; color:inherit; }
  .brand-preview .pv-main { border:1px solid var(--line); border-radius:var(--radius); display:grid; place-items:center; padding:12px; }
  .brand-preview .pv-btn { min-height:44px; padding:0 22px; border-radius:var(--radius-sm); font-weight:700; background:var(--pv-accent,var(--accent)); color:var(--pv-accent-fg,var(--accent-fg)); display:inline-flex; align-items:center; }
  .logo-now { max-height:56px; max-width:160px; background:#fff; border:1px solid var(--line); border-radius:var(--radius-sm); padding:4px; }
  @media (max-width:760px) { .brand-preview { grid-template-columns:1fr; } }

  /* ---- richer motion: this panel is used at a desk, occasionally ---- */
  main > * { animation:rise var(--dur-slow) var(--ease-out) both; }
  main > :nth-child(2) { animation-delay:40ms; } main > :nth-child(3) { animation-delay:80ms; }
  main > :nth-child(4) { animation-delay:120ms; } main > :nth-child(n+5) { animation-delay:160ms; }
  @keyframes rise { from { opacity:0; transform:translateY(8px); } }
  /* Only a card you can act on lifts (a link, or marked .lift); a form card that
     rose under the pointer would be a form moving while you type in it. */
  @media (hover:hover) {
    a.card, .card.lift { transition:transform var(--dur-lift) var(--ease-out), box-shadow var(--dur-lift) var(--ease-out); color:inherit; text-decoration:none; display:block; }
    a.card:hover, .card.lift:hover { transform:translateY(-8px); box-shadow:var(--ring-hover), var(--shadow-lift); }
  }

  /* ---- the palette switcher ---- */
  /* Physical `left` on purpose, not inset-inline-start: in this RTL page the
     start corner is the right, and the switcher belongs on the left. */
  .switcher { position:fixed; z-index:60; left:max(16px,env(safe-area-inset-left,0px)); bottom:calc(16px + env(safe-area-inset-bottom,0px)); color:var(--c-ink); }
  .switcher-toggle { display:inline-flex; align-items:center; gap:8px; min-height:48px; padding:0 18px 0 14px; border:0; border-radius:var(--radius-pill);
                     background:var(--c-surface); color:var(--c-ink); font:600 var(--fs-sm) var(--f-body); cursor:pointer;
                     box-shadow:0 12px 30px -10px rgba(0,0,0,.4), inset 0 0 0 1px var(--c-line); transition:transform var(--dur-slow) var(--ease-out); }
  .switcher-toggle:hover { transform:translateY(-2px); }
  .chip-dots { display:flex; }
  .chip-dots i { width:16px; height:16px; border-radius:50%; border:2px solid var(--c-surface); margin-inline-start:-6px; }
  .chip-dots i:first-child { margin-inline-start:0; background:var(--c-deep); }
  .chip-dots i:last-child { background:var(--c-accent); }
  .switcher-panel { position:absolute; left:0; bottom:calc(100% + 12px); width:min(340px,calc(100vw - 32px)); padding:18px;
                    border-radius:var(--radius-md); background:var(--c-surface); box-shadow:var(--shadow-raised), var(--ring);
                    display:flex; flex-direction:column; gap:14px;
                    transform-origin:bottom left; transform:translateY(10px) scale(.96); opacity:0; visibility:hidden;
                    transition:transform var(--dur-slow) var(--ease-out), opacity var(--dur-base) ease, visibility 0s linear var(--dur-slow); }
  .switcher.is-open .switcher-panel { transform:none; opacity:1; visibility:visible; transition:transform var(--dur-slow) var(--ease-out), opacity var(--dur-base) ease, visibility 0s; }
  .switcher-panel h2 { margin:0; font-size:var(--fs-md); }
  .palette-list { display:flex; flex-direction:column; gap:6px; }
  .palette-option { display:flex; align-items:center; gap:12px; min-height:48px; padding:6px 12px; border:0; border-radius:var(--radius-sm); background:transparent; color:var(--c-ink);
                    font:500 var(--fs-sm) var(--f-body); text-align:start; cursor:pointer; box-shadow:var(--ring); transition:box-shadow var(--dur-base) ease; }
  .palette-option:hover { box-shadow:inset 0 0 0 1px var(--c-muted); }
  .palette-option[aria-pressed="true"] { box-shadow:inset 0 0 0 2px var(--c-ink); }
  .sw { display:flex; flex:none; }
  .sw i { width:22px; height:22px; border-radius:50%; margin-inline-start:-7px; box-shadow:0 0 0 2px var(--c-surface); }
  .sw i:first-child { margin-inline-start:0; }
  .sheet-grab { display:none; }
  .switcher-scrim { position:fixed; inset:0; z-index:58; background:rgba(0,0,0,.45); opacity:0; visibility:hidden; transition:opacity var(--dur-slow) ease, visibility 0s linear var(--dur-slow); }

  @media (max-width:1080px) {
    .switcher-toggle { width:50px; height:50px; min-height:0; padding:0; justify-content:center; border-radius:50%; }
    .switcher-toggle .label { display:none; }
    .switcher-toggle:active { transform:scale(.9); }
    .switcher.is-open ~ .switcher-scrim { opacity:1; visibility:visible; transition:opacity var(--dur-slow) ease, visibility 0s; }
    .switcher-panel { position:fixed; z-index:59; inset-inline:0; bottom:0; width:100%; max-height:80svh; overflow-y:auto; border-radius:26px 26px 0 0;
                      padding:10px max(20px,env(safe-area-inset-right,0px)) calc(28px + env(safe-area-inset-bottom,0px)) max(20px,env(safe-area-inset-left,0px));
                      transform:translateY(105%); opacity:1; touch-action:none;
                      transition:transform .45s var(--ease-sheet), visibility 0s linear .45s; }
    .switcher.is-open .switcher-panel { transform:translateY(var(--drag,0px)); transition:transform .45s var(--ease-sheet), visibility 0s; }
    .switcher.is-dragging .switcher-panel { transition:none; }
    .sheet-grab { display:block; width:44px; height:5px; margin:0 auto 4px; border-radius:99px; background:color-mix(in srgb, var(--c-ink) 22%, transparent); }
    .palette-list { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:10px; }
    .palette-option { flex-direction:column; gap:6px; padding:10px 4px; text-align:center; min-height:76px; font-size:var(--fs-xs); }
    .sw i { width:30px; height:30px; margin-inline-start:-10px; }
  }

  @media (prefers-reduced-motion:reduce) {
    *, *::before, *::after { animation:none !important; transition:none !important; }
  }
</style>
</head>
<body class="{{ auth('admin')->check() ? '' : 'guest' }}">
@auth('admin')
<div class="shell">
  <header class="top">
    <button class="menu" type="button" data-menu aria-label="القايمة" aria-controls="admin-side" aria-expanded="false">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
    </button>
    @include('admin.partials.wordmark')
  </header>
  <div class="scrim" data-side-scrim></div>

  <aside class="side" id="admin-side">
    @include('admin.partials.wordmark', ['sub' => 'لوحة المنصة'])
    <nav aria-label="الأقسام">
      @foreach ($nav as $item)
        <a href="{{ route($item['route']) }}" @if (request()->routeIs($item['active'])) aria-current="page" @endif>{{ $item['label'] }}</a>
      @endforeach
    </nav>
    <div class="side-foot">
      <span class="who">{{ auth('admin')->user()->name }}</span>
      <form method="post" action="{{ route('admin.logout') }}">@csrf <button class="btn" type="submit">خروج</button></form>
    </div>
  </aside>

  <main>
    @if (session('status'))<div class="alert ok">{{ session('status') }}</div>@endif
    @yield('content')
  </main>
</div>

{{-- Karim's own palette picker: the panel only, shops get a brand instead. --}}
<div class="switcher" data-switcher>
  <button class="switcher-toggle" type="button" data-switcher-toggle aria-expanded="false" aria-controls="palette-panel" aria-label="الألوان">
    <span class="chip-dots" aria-hidden="true"><i></i><i></i></span>
    <span class="label">الألوان</span>
  </button>
  <div class="switcher-panel" id="palette-panel" data-switcher-panel role="dialog" aria-label="ألوان اللوحة">
    <div class="sheet-grab" aria-hidden="true"></div>
    <h2>ألوان اللوحة</h2>
    <div class="palette-list" role="group" aria-label="الألوان">
      @foreach ($palettes as $id => $p)
        <button type="button" class="palette-option" data-set-palette="{{ $id }}" aria-pressed="false">
          <span class="sw" aria-hidden="true"><i style="background:{{ $p['deep'] }}"></i><i style="background:{{ $p['accent'] }}"></i></span>
          <span>{{ $p['name'] }}</span>
        </button>
      @endforeach
    </div>
  </div>
  <div class="switcher-scrim" data-switcher-scrim></div>
</div>
<script src="{{ asset('js/admin-shell.js') }}?v={{ $v }}"></script>
@else
<main>
  @if (session('status'))<div class="alert ok">{{ session('status') }}</div>@endif
  @yield('content')
</main>
@endauth
</body>
</html>
