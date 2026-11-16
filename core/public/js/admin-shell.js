/* =========================================================
   Platform panel (/admin): the drawer and the palette switcher.
   Vanilla on purpose: the panel is buildless (decision 14).

   The palette list comes from the layout (window.ScanzoPalettes, from
   config('scanzo.admin_palettes')); the inline script in <head> has already set
   <html data-palette> from localStorage before first paint, so this file only
   handles clicks. Bump scanzo.assets_version when editing.
   ========================================================= */
(function () {
  'use strict';

  var STORE_KEY = 'scanzo:admin-palette';
  var root = document.documentElement;
  var PALETTES = window.ScanzoPalettes || {};

  /* ---------- the navigation drawer (under 980px) ---------- */
  var side = document.getElementById('admin-side');
  var menuBtn = document.querySelector('[data-menu]');
  var scrim = document.querySelector('[data-side-scrim]');

  var asDrawer = window.matchMedia('(max-width: 979.98px)');
  var FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

  function isOpen() { return !!side && side.classList.contains('is-open'); }

  function setDrawer(open) {
    if (!side) return;
    var was = isOpen();
    side.classList.toggle('is-open', open);
    if (scrim) scrim.classList.toggle('is-on', open);
    if (menuBtn) menuBtn.setAttribute('aria-expanded', String(open));
    root.classList.toggle('drawer-open', open);
    if (open && !was) {
      /* a drawer is a dialog while it is open: say so, and move focus into it */
      side.setAttribute('role', 'dialog');
      side.setAttribute('aria-modal', 'true');
      var first = side.querySelector(FOCUSABLE);
      if (first) first.focus();
    } else if (!open && was) {
      side.removeAttribute('role');
      side.removeAttribute('aria-modal');
      if (menuBtn && asDrawer.matches) menuBtn.focus();   /* back to where the person was */
    }
  }
  if (menuBtn) menuBtn.addEventListener('click', function () { setDrawer(!isOpen()); });
  if (scrim) scrim.addEventListener('click', function () { setDrawer(false); });

  /* Tab and Shift+Tab wrap inside the open drawer; they never reach the page behind it. */
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Tab' || !isOpen()) return;
    var items = Array.prototype.filter.call(side.querySelectorAll(FOCUSABLE), function (el) { return el.offsetParent !== null; });
    if (!items.length) return;
    var firstEl = items[0], lastEl = items[items.length - 1];
    if (!side.contains(document.activeElement)) { e.preventDefault(); firstEl.focus(); }
    else if (e.shiftKey && document.activeElement === firstEl) { e.preventDefault(); lastEl.focus(); }
    else if (!e.shiftKey && document.activeElement === lastEl) { e.preventDefault(); firstEl.focus(); }
  });

  /* Widening past the breakpoint turns the drawer into the fixed column: drop the lock. */
  if (asDrawer.addEventListener) asDrawer.addEventListener('change', function (e) { if (!e.matches && isOpen()) setDrawer(false); });

  /* ---------- the palette ---------- */
  function syncControls() {
    document.querySelectorAll('[data-set-palette]').forEach(function (b) {
      b.setAttribute('aria-pressed', String(b.dataset.setPalette === root.dataset.palette));
    });
  }

  function setPalette(id) {
    if (!PALETTES[id]) return;
    root.dataset.palette = id;
    var meta = document.querySelector('meta[name="theme-color"]');
    if (meta) meta.setAttribute('content', PALETTES[id].deep);
    try { localStorage.setItem(STORE_KEY, id); } catch (e) { /* storage blocked: the choice lasts until reload */ }
    syncControls();
    document.dispatchEvent(new CustomEvent('scanzo:palette', { detail: id }));
  }

  document.addEventListener('click', function (e) {
    var p = e.target.closest('[data-set-palette]');
    if (p) setPalette(p.dataset.setPalette);
  });
  syncControls();

  /* ---------- the switcher: popover on desktop, bottom sheet under 1080px ---------- */
  var wrap = document.querySelector('[data-switcher]');
  if (!wrap) return;

  var toggle = wrap.querySelector('[data-switcher-toggle]');
  var panel = wrap.querySelector('[data-switcher-panel]');
  var sheetScrim = document.querySelector('[data-switcher-scrim]');
  var mobile = window.matchMedia('(max-width: 1080px)');

  function open() {
    wrap.classList.add('is-open');
    toggle.setAttribute('aria-expanded', 'true');
  }
  function close() {
    wrap.classList.remove('is-open');
    toggle.setAttribute('aria-expanded', 'false');
    panel.style.removeProperty('--drag');
  }

  toggle.addEventListener('click', function () {
    wrap.classList.contains('is-open') ? close() : open();
  });
  if (sheetScrim) sheetScrim.addEventListener('click', close);
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    close();
    setDrawer(false);
  });
  document.addEventListener('click', function (e) {
    if (!mobile.matches && wrap.classList.contains('is-open') && !wrap.contains(e.target)) close();
  });

  /* drag the sheet down to dismiss it */
  var startY = null, dy = 0;
  panel.addEventListener('pointerdown', function (e) {
    if (!mobile.matches || e.target.closest('button')) return;
    startY = e.clientY; dy = 0;
    wrap.classList.add('is-dragging');
    panel.setPointerCapture(e.pointerId);
  });
  panel.addEventListener('pointermove', function (e) {
    if (startY === null) return;
    dy = Math.max(0, e.clientY - startY);
    panel.style.setProperty('--drag', dy + 'px');
  });
  function end() {
    if (startY === null) return;
    startY = null;
    wrap.classList.remove('is-dragging');
    if (dy > 80) close(); else panel.style.removeProperty('--drag');
  }
  panel.addEventListener('pointerup', end);
  panel.addEventListener('pointercancel', end);
})();
