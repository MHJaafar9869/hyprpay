/** Docs page: per-gateway scoping, live action tree (scroll-spy), back-to-top. */
(function () {
  var GW = {
    cyber:   { name: 'CyberSource UC', enumCase: 'CybersourceUnifiedCheckout', hue: 'var(--cyber)',   sign: 'HMAC HTTP-Signature', ret: 'jwt (capture context)' },
    fawry:   { name: 'Fawry',   enumCase: 'Fawry',   hue: 'var(--fawry)',   sign: 'SHA-256',     ret: 'redirectUrl (hosted page)' },
    paymob:  { name: 'Paymob',  enumCase: 'Paymob',  hue: 'var(--paymob)',  sign: 'HMAC-SHA512', ret: 'redirectUrl (iframe)' },
    paylink: { name: 'PayLink', enumCase: 'Paylink', hue: 'var(--paylink)', sign: 'HMAC-SHA256', ret: 'redirectUrl (invoice / iframe)' },
    paytabs: { name: 'PayTabs', enumCase: 'Paytabs', hue: 'var(--paytabs)', sign: 'server key + HMAC-SHA256', ret: 'redirectUrl (hosted page)' },
    paypal:  { name: 'PayPal',  enumCase: 'PayPal',  hue: 'var(--paypal)',  sign: 'OAuth 2.0 client credentials', ret: 'redirectUrl (approval link)' },
    mpgs:    { name: 'Mastercard MPGS', enumCase: 'Mpgs', hue: 'var(--mpgs)', sign: 'HTTP Basic (merchant.{id})', ret: 'reference (session id)' },
    authorizenet: { name: 'Authorize.Net', enumCase: 'AuthorizeNet', hue: 'var(--authorizenet)', sign: 'name + transaction key', ret: 'transactionId (Accept.js charge)' },
    airwallex: { name: 'Airwallex', enumCase: 'Airwallex', hue: 'var(--airwallex)', sign: 'API-access login token', ret: 'reference (intent id) + jwt (client secret)' },
    tamara:  { name: 'Tamara', enumCase: 'Tamara', hue: 'var(--tamara)', sign: 'Bearer API token', ret: 'redirectUrl (hosted BNPL page)' },
    misc:    { name: 'Misc', enumCase: '', hue: 'var(--muted)', sign: '—', ret: '—', meta: false }
  };
  var opts     = [].slice.call(document.querySelectorAll('.gw-opt'));
  var sections = [].slice.call(document.querySelectorAll('.doc-section'));
  var treeItems= [].slice.call(document.querySelectorAll('.tree li[data-gws]'));
  var variants = [].slice.call(document.querySelectorAll('.gw-variant'));
  var links    = [].slice.call(document.querySelectorAll('.tree a[data-spy]'));
  var docMain  = document.querySelector('.doc');
  var ctx      = document.getElementById('gw-context');
  var toTop    = document.querySelector('.to-top');

  /** The gateway named by ?gw= in the URL, falling back to the default when it names none we know. */
  function gatewayFromUrl() {
    var asked = new URLSearchParams(window.location.search).get('gw');
    return GW[asked] ? asked : 'cyber';
  }

  /**
   * Keep ?gw= in step with the selected tab, so the scoped page survives a reload
   * and can be linked to. Wrapped because a file:// preview forbids replaceState —
   * the tab still switches there, the URL just doesn't follow.
   */
  function syncUrl(gw) {
    try {
      var url = new URL(window.location.href);
      url.searchParams.set('gw', gw);
      history.replaceState(null, '', url);
    } catch (e) {}
  }

  /** Whether `el` applies to gateway `gw` (no data-gws attribute means all gateways). */
  function supports(el, gw) { var g = el.getAttribute('data-gws'); return !g || g.split(' ').indexOf(gw) !== -1; }

  /** Highlight the single tree link whose data-spy equals `id`. */
  function setActive(id) {
    links.forEach(function (l) { l.classList.toggle('active', l.getAttribute('data-spy') === id); });
    markActiveGroup();
    revealActiveGroup();
  }

  var THRESHOLD = 120, ticking = false, spyLocked = false;

  /**
   * Scroll handler. Updates back-to-top visibility, then — unless a tree click has
   * pinned the active item — activates the last action whose top has crossed the
   * threshold, with a bottom-of-page guard so a short trailing action still
   * activates at the very end.
   */
  function updateSpy() {
    ticking = false;
    if (toTop) toTop.classList.toggle('show', window.scrollY > 400);
    if (spyLocked) return;
    var current = null, i;
    for (i = 0; i < sections.length; i++) {
      if (sections[i].hidden) continue;
      if (sections[i].getBoundingClientRect().top <= THRESHOLD) current = sections[i].id;
    }
    var atBottom = window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 2;
    if (atBottom) {
      for (i = sections.length - 1; i >= 0; i--) { if (!sections[i].hidden) { current = sections[i].id; break; } }
    }
    if (!current) {
      for (i = 0; i < sections.length; i++) { if (!sections[i].hidden) { current = sections[i].id; break; } }
    }
    if (current) setActive(current);
  }
  /** Coalesce scroll/resize events into one updateSpy per animation frame. */
  function onScroll() { if (!ticking) { ticking = true; requestAnimationFrame(updateSpy); } }

  /**
   * Scope the whole page to gateway `gw`: select its tab, tint the accent, update
   * the context banner and enum, show the matching checkout variant, hide actions
   * the driver doesn't support, and — when user-initiated — jump to the top
   * ('instant' overrides the CSS smooth-scroll so it lands immediately).
   */
  function select(gw, userInitiated) {
    var info = GW[gw]; if (!info) return;
    opts.forEach(function (o) { o.setAttribute('aria-selected', String(o.getAttribute('data-gw') === gw)); });
    docMain.style.setProperty('--g', info.hue);
    ctx.style.setProperty('--g', info.hue);
    ctx.querySelector('[data-ctx="name"]').textContent = info.name;
    ctx.querySelector('[data-ctx="sign"]').textContent = info.sign;
    ctx.querySelector('[data-ctx="ret"]').textContent  = info.ret;
    Array.prototype.forEach.call(ctx.querySelectorAll('.meta'), function (m) { m.hidden = info.meta === false; });
    var en = docMain.querySelector('[data-gwenum]');
    if (en) en.textContent = info.enumCase;
    sections.forEach(function (s) { s.hidden = !supports(s, gw); });
    treeItems.forEach(function (li) { li.hidden = !supports(li, gw); });
    syncGroups();
    variants.forEach(function (v) { v.classList.toggle('active', v.getAttribute('data-gw') === gw); });
    syncUrl(gw);
    if (userInitiated) window.scrollTo({ top: 0, behavior: 'instant' });
    spyLocked = false;
    updateSpy();
  }

  opts.forEach(function (o, i) {
    o.addEventListener('click', function () { select(o.getAttribute('data-gw'), true); });
    o.addEventListener('keydown', function (e) {
      var f = e.key === 'ArrowDown' || e.key === 'ArrowRight';
      var b = e.key === 'ArrowUp' || e.key === 'ArrowLeft';
      if (!f && !b) return;
      e.preventDefault();
      var n = (i + (f ? 1 : -1) + opts.length) % opts.length;
      opts[n].focus(); select(opts[n].getAttribute('data-gw'), true);
    });
  });

  window.addEventListener('scroll', onScroll, { passive: true });
  window.addEventListener('resize', onScroll);
  ['wheel', 'touchmove', 'keydown'].forEach(function (evt) {
    window.addEventListener(evt, function () { spyLocked = false; }, { passive: true });
  });
  links.forEach(function (l) {
    l.addEventListener('click', function () { setActive(l.getAttribute('data-spy')); spyLocked = true; });
  });

  /* ── Grouped action tree (accordion) ──
     One level of labelled groups mirroring the doc-eyebrow headings in the body.
     At most one group is open at a time: opening one closes the rest, so the list
     never grows past a screenful. A group label both opens the group and jumps to
     where that group starts. Groups start collapsed, the open one is remembered,
     and once the visitor starts moving through the page the group holding the active
     action takes over — so the scroll-spy highlight is never stranded in a closed
     group. A fresh load stays fully collapsed. */
  var groups   = [].slice.call(document.querySelectorAll('.tree-group'));
  var STORE    = 'hyprpay:docs:open-group';
  var hasMoved = false;

  /** The visitor's remembered open group, or '' when they left them all closed. */
  function readOpen() {
    try { return window.localStorage.getItem(STORE) || ''; } catch (e) { return ''; }
  }

  /** Persist the open group; a write failure is never worth breaking navigation over. */
  function writeOpen(name) {
    try { window.localStorage.setItem(STORE, name); } catch (e) { /* private mode */ }
  }

  /** Set one group's disclosure state without touching its siblings. */
  function mark(group, expanded) {
    var btn = group.querySelector('.tree-label');
    if (btn) btn.setAttribute('aria-expanded', String(expanded));
  }

  /** Whether a group is currently open. */
  function isOpen(group) {
    var btn = group.querySelector('.tree-label');
    return !!btn && btn.getAttribute('aria-expanded') === 'true';
  }

  /**
   * Accordion: open `group` and close every other, or close them all when `group`
   * is null. `remember` is false for openings the page drives itself (scroll-spy,
   * #hash), so following the page around never overwrites a deliberate choice.
   */
  function openOnly(group, remember) {
    groups.forEach(function (g) { mark(g, g === group); });
    if (remember !== false) writeOpen(group ? group.getAttribute('data-group') : '');
  }

  /** The first action in `group` that the selected gateway actually supports. */
  function firstVisibleItem(group) {
    var items = [].slice.call(group.querySelectorAll('li[data-gws]'));
    for (var i = 0; i < items.length; i++) { if (!items[i].hidden) return items[i]; }
    return null;
  }

  /**
   * Open a group and jump to where it begins — the first action the current gateway
   * supports, which is not always the first one listed. Pins the spy the same way a
   * click on an action link does, so the smooth scroll is not fought on the way there.
   */
  function openAndJump(group) {
    openOnly(group, true);
    var li = firstVisibleItem(group);
    var link = li && li.querySelector('a[data-spy]');
    if (!link) return;
    var id = link.getAttribute('data-spy');
    var section = document.getElementById(id);
    if (!section || section.hidden) return;
    setActive(id);
    spyLocked = true;
    hasMoved = true;
    section.scrollIntoView();
  }

  var remembered = readOpen();
  groups.forEach(function (group) {
    var btn = group.querySelector('.tree-label');
    if (!btn) return;
    if (remembered && group.getAttribute('data-group') === remembered) mark(group, true);
    btn.addEventListener('click', function () { openAndJump(group); });
  });

  /** Hide a group whose every action belongs to other gateways. */
  function syncGroups() {
    groups.forEach(function (group) {
      var items = [].slice.call(group.querySelectorAll('li[data-gws]'));
      group.hidden = items.length > 0 && items.every(function (li) { return li.hidden; });
    });
  }

  /**
   * Flag the group that holds the active action, so the parent reads as current even
   * while it is collapsed. Deliberately separate from the open/closed state: a group
   * can be open without being where you are reading, and vice versa.
   */
  function markActiveGroup() {
    var active = document.querySelector('.tree-group a.active');
    var current = active ? active.closest('.tree-group') : null;
    groups.forEach(function (g) { g.classList.toggle('is-active', g === current); });
  }

  /**
   * Bring the group containing the active action to the front of the accordion.
   * Suppressed until the visitor actually moves, so landing on the page does not
   * silently pop the first group open.
   */
  function revealActiveGroup() {
    if (!hasMoved) return;
    var active = document.querySelector('.tree-group a.active');
    if (!active) return;
    var group = active.closest('.tree-group');
    if (group && !isOpen(group)) openOnly(group, false);
  }

  ['scroll', 'wheel', 'touchmove', 'keydown'].forEach(function (evt) {
    window.addEventListener(evt, function () { hasMoved = true; revealActiveGroup(); }, { passive: true });
  });

  /* Landing on an action via #hash is explicit navigation — open its group. */
  if (window.location.hash) {
    var linked = document.querySelector('.tree-group a[href="' + window.location.hash + '"]');
    var lgroup = linked && linked.closest('.tree-group');
    if (lgroup) { hasMoved = true; openOnly(lgroup, false); }
  }

  if (toTop) toTop.addEventListener('click', function () { window.scrollTo({ top: 0, behavior: 'smooth' }); });

  var themeBtn = document.querySelector('.theme-toggle');
  if (themeBtn) {
    themeBtn.addEventListener('click', function () {
      var next = document.documentElement.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
      window.setTheme(next, this);
    });
  }

  select(gatewayFromUrl());
  updateSpy();
})();
