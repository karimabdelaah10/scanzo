/* =========================================================
   Scanzo brand, applied before the first paint.

   /till and /dashboard are served without auth on purpose, so the server
   does not know which shop is loading and cannot put the colours in the
   HTML. Instead:
     1. at sign-in the resolved brand (from GET /api/me) is cached here,
        under a key that includes the business id;
     2. on every later load this script runs synchronously in <head>,
        reads that cache and sets the custom properties on <html>, so the
        first paint is already in the shop's colours;
     3. the app reconciles with /api/me when it answers (shared/session.js).
   The cache is dropped at sign-out.

   A plain classic script, not part of the Vite bundle: it must run before
   the bundle is even fetched. Bump `scanzo.assets_version` when editing.
   The foregrounds are NEVER computed here: they come from the server
   (App\Support\Brand\Contrast). This file only copies them onto :root.

   Only the properties below are ever written. The state colours (--c-danger,
   --c-warn, --c-success …) are not in the list, so no brand can touch them.
   ========================================================= */
(function () {
  'use strict';

  var CURRENT = 'scanzo.brand.current';
  var PREFIX = 'scanzo.brand.';
  var HEX = /^#[0-9A-Fa-f]{6}$/;

  /* brand field -> the custom property (or properties) it sets.
     `accent` is the contrast-adjusted colour: it becomes the FILL, the
     background of buttons. `primary` is the colour the owner actually picked:
     it stays the decorative --c-accent, as the true orange does in the default
     palette. */
  var PROPS = {
    accent: '--c-accent-fill',
    accent_fg: '--c-on-accent',
    accent_text: '--c-accent-text',
    primary: ['--brand-primary', '--c-accent'],
    side: '--side-bg',
    side_fg: '--side-fg',
    secondary: '--brand-secondary'
  };

  var root = document.documentElement;

  function get(key) {
    try { return localStorage.getItem(key); } catch (e) { return null; }
  }
  function set(key, value) {
    try { localStorage.setItem(key, value); } catch (e) { /* storage blocked: the brand just flashes */ }
  }
  function del(key) {
    try { localStorage.removeItem(key); } catch (e) { /* see set */ }
  }

  /** Put a brand on <html>. A null brand, or a missing field, restores the platform's own colour. */
  function apply(brand) {
    brand = brand || {};
    Object.keys(PROPS).forEach(function (field) {
      var value = brand[field];
      [].concat(PROPS[field]).forEach(function (prop) {
        if (typeof value === 'string' && HEX.test(value)) {
          root.style.setProperty(prop, value);
        } else {
          root.style.removeProperty(prop);
        }
      });
    });

    var meta = document.querySelector('meta[name="theme-color"]');
    if (meta) {
      meta.setAttribute('content', HEX.test(brand.side || '') ? brand.side : (meta.dataset.default || meta.getAttribute('content')));
    }
  }

  function read() {
    var id = get(CURRENT);
    if (!id) return null;
    try { return JSON.parse(get(PREFIX + id) || 'null'); } catch (e) { return null; }
  }

  /** Remember a shop's brand for the next load. Keyed by business id so two shops on one device never bleed. */
  function cache(businessId, brand) {
    if (!businessId) return;
    set(PREFIX + businessId, JSON.stringify(brand || {}));
    set(CURRENT, String(businessId));
  }

  /** Sign-out: forget this device's current shop and its cached brand. */
  function clear() {
    var id = get(CURRENT);
    if (id) del(PREFIX + id);
    del(CURRENT);
    apply(null);
  }

  var meta = document.querySelector('meta[name="theme-color"]');
  if (meta && !meta.dataset.default) meta.dataset.default = meta.getAttribute('content');

  window.ScanzoBrand = { apply: apply, cache: cache, clear: clear, read: read };

  apply(read());
})();
