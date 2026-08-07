/** Landing page: scroll reveals (IntersectionObserver, reduced-motion aware) + copy-install button. */
(function () {
  var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var els = document.querySelectorAll('.reveal');
  if (reduce || !('IntersectionObserver' in window)) {
    els.forEach(function (el) { el.classList.add('in'); });
  } else {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) {
        if (e.isIntersecting) { e.target.classList.add('in'); io.unobserve(e.target); }
      });
    }, { rootMargin: '0px 0px -8% 0px', threshold: 0.08 });
    els.forEach(function (el) { io.observe(el); });
  }

  var btn = document.getElementById('copy-install');
  if (btn) {
    btn.addEventListener('click', function () {
      var text = btn.getAttribute('data-copy');
      var done = function () { btn.classList.add('is-copied'); setTimeout(function () { btn.classList.remove('is-copied'); }, 1600); };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(done).catch(done);
      } else { done(); }
    });
  }

  var deck = document.querySelector('[data-deck]');
  if (deck) {
    var cards = Array.prototype.slice.call(deck.querySelectorAll('.deck-card'));
    var cur = deck.querySelector('[data-deck-cur]');
    var n = cards.length, active = 0;
    var render = function () {
      cards.forEach(function (c, i) {
        var pos = (i - active + n) % n;
        c.setAttribute('data-pos', String(pos));
        c.setAttribute('aria-hidden', pos === 0 ? 'false' : 'true');
      });
      if (cur) cur.textContent = String(active + 1);
    };
    var go = function (d) { active = (active + d + n) % n; render(); };
    var stack = deck.querySelector('.deck-stack');
    stack.addEventListener('click', function () { go(1); });
    render();
  }

  var themeBtn = document.querySelector('.theme-toggle');
  if (themeBtn) {
    themeBtn.addEventListener('click', function () {
      var next = document.documentElement.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
      document.documentElement.setAttribute('data-theme', next);
      try { localStorage.setItem('theme', next); } catch (e) {}
    });
  }
})();
