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

  /** Whether `el` applies to gateway `gw` (no data-gws attribute means all gateways). */
  function supports(el, gw) { var g = el.getAttribute('data-gws'); return !g || g.split(' ').indexOf(gw) !== -1; }

  /** Highlight the single tree link whose data-spy equals `id`. */
  function setActive(id) { links.forEach(function (l) { l.classList.toggle('active', l.getAttribute('data-spy') === id); }); }

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
    variants.forEach(function (v) { v.classList.toggle('active', v.getAttribute('data-gw') === gw); });
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

  if (toTop) toTop.addEventListener('click', function () { window.scrollTo({ top: 0, behavior: 'smooth' }); });

  var themeBtn = document.querySelector('.theme-toggle');
  if (themeBtn) {
    themeBtn.addEventListener('click', function () {
      var next = document.documentElement.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
      window.setTheme(next, this);
    });
  }

  select('cyber');
  updateSpy();
})();
