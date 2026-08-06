// Docs page behaviour: per-gateway scoping + live action tree (scroll-spy).
(function () {
  var GW = {
    cyber:   { name: 'CyberSource UC', enumCase: 'CybersourceUnifiedCheckout', hue: 'var(--cyber)',   sign: 'HMAC HTTP-Signature', ret: 'jwt (capture context)' },
    fawry:   { name: 'Fawry',   enumCase: 'Fawry',   hue: 'var(--fawry)',   sign: 'SHA-256',     ret: 'redirectUrl (hosted page)' },
    paymob:  { name: 'Paymob',  enumCase: 'Paymob',  hue: 'var(--paymob)',  sign: 'HMAC-SHA512', ret: 'redirectUrl (iframe)' },
    paylink: { name: 'PayLink', enumCase: 'Paylink', hue: 'var(--paylink)', sign: 'HMAC-SHA256', ret: 'redirectUrl (invoice)' }
  };
  var opts     = [].slice.call(document.querySelectorAll('.gw-opt'));
  var sections = [].slice.call(document.querySelectorAll('.doc-section'));
  var treeItems= [].slice.call(document.querySelectorAll('.tree li[data-gws]'));
  var variants = [].slice.call(document.querySelectorAll('.gw-variant'));
  var links    = [].slice.call(document.querySelectorAll('.tree a[data-spy]'));
  var docMain  = document.querySelector('.doc');
  var ctx      = document.getElementById('gw-context');

  function supports(el, gw) { var g = el.getAttribute('data-gws'); return !g || g.split(' ').indexOf(gw) !== -1; }
  function setActive(id) { links.forEach(function (l) { l.classList.toggle('active', l.getAttribute('data-spy') === id); }); }

  /* scroll-spy: the active action is the last one whose top has crossed the
     threshold — with a guard so hitting the page bottom always lights the last
     section (short trailing sections can never reach the top on their own). */
  var THRESHOLD = 120, ticking = false, spyLocked = false;
  function updateSpy() {
    ticking = false;
    if (spyLocked) return; // a tree click pins the active item until the next real scroll gesture
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
  function onScroll() { if (!ticking) { ticking = true; requestAnimationFrame(updateSpy); } }

  function select(gw, userInitiated) {
    var info = GW[gw]; if (!info) return;
    opts.forEach(function (o) { o.setAttribute('aria-selected', String(o.getAttribute('data-gw') === gw)); });
    docMain.style.setProperty('--g', info.hue);
    ctx.style.setProperty('--g', info.hue);
    ctx.querySelector('[data-ctx="name"]').textContent = info.name;
    ctx.querySelector('[data-ctx="sign"]').textContent = info.sign;
    ctx.querySelector('[data-ctx="ret"]').textContent  = info.ret;
    var en = docMain.querySelector('[data-gwenum]');
    if (en) en.textContent = info.enumCase;
    sections.forEach(function (s) { s.hidden = !supports(s, gw); });
    treeItems.forEach(function (li) { li.hidden = !supports(li, gw); });
    variants.forEach(function (v) { v.classList.toggle('active', v.getAttribute('data-gw') === gw); });
    // when the user switches gateway, jump to the top of the new action set
    // ('instant' overrides the page's CSS smooth-scroll so it lands immediately)
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
  // A tree click pins that item as active (no flicker while it smooth-scrolls there);
  // a genuine scroll gesture releases the pin and resumes the spy.
  ['wheel', 'touchmove', 'keydown'].forEach(function (evt) {
    window.addEventListener(evt, function () { spyLocked = false; }, { passive: true });
  });
  links.forEach(function (l) {
    l.addEventListener('click', function () { setActive(l.getAttribute('data-spy')); spyLocked = true; });
  });

  select('cyber');
  updateSpy();
})();
