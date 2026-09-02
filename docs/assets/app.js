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

  /**
   * Hero stats: count each figure up to the value already written in the
   * markup, so the numbers still read correctly with JS off. A value with no
   * digits ("max") is left alone, and the authored text is restored verbatim
   * at the end so a suffix like "+" comes back exactly as written.
   */
  var stats = document.querySelector('.hero-stats');
  var figures = stats ? [].slice.call(stats.querySelectorAll('b')).map(function (el) {
    var text = el.textContent, m = text.match(/\d[\d,]*/);
    if (!m) { return null; }
    return {
      el: el, text: text,
      target: parseInt(m[0].replace(/,/g, ''), 10),
      head: text.slice(0, m.index),
      tail: text.slice(m.index + m[0].length)
    };
  }).filter(Boolean) : [];

  if (stats && figures.length && !reduce && 'IntersectionObserver' in window) {
    var countUp = function () {
      figures.forEach(function (f, i) {
        var dur = 1150, delay = 420 + i * 90, t0 = null;
        f.el.textContent = f.head + '0' + f.tail;
        var step = function (now) {
          if (t0 === null) { t0 = now; }
          var p = (now - t0 - delay) / dur;
          if (p < 0) { requestAnimationFrame(step); return; }
          if (p >= 1) { f.el.textContent = f.text; return; }
          f.el.textContent = f.head + Math.round(f.target * (1 - Math.pow(1 - p, 3))) + f.tail;
          requestAnimationFrame(step);
        };
        requestAnimationFrame(step);
      });
    };
    var statsIo = new IntersectionObserver(function (entries) {
      if (!entries[0].isIntersecting) { return; }
      statsIo.disconnect();
      countUp();
    }, { threshold: 0.4 });
    statsIo.observe(stats);
  }

  /**
   * Marquee hover: ease both tracks down to a standstill and back up again.
   * animation-play-state would snap, so the rate is ramped by hand — and both
   * rows ramp together, so hovering either one holds the pair in step.
   */
  var bandRows = document.querySelector('.band-rows');
  var tracks = bandRows ? [].slice.call(bandRows.querySelectorAll('.band-track')) : [];
  if (!reduce && tracks.length && tracks[0].getAnimations) {
    var rate = 1, frame = null;
    var apply = function (r) {
      tracks.forEach(function (t) {
        t.getAnimations().forEach(function (a) { a.playbackRate = r; });
      });
    };
    var rampTo = function (target, ms) {
      if (frame) cancelAnimationFrame(frame);
      var from = rate, start = null;
      var step = function (now) {
        if (start === null) start = now;
        var p = Math.min(1, (now - start) / ms);
        rate = from + (target - from) * (p * (2 - p));
        apply(rate);
        if (p < 1) { frame = requestAnimationFrame(step); }
      };
      frame = requestAnimationFrame(step);
    };
    bandRows.addEventListener('pointerenter', function () { rampTo(0, 480); });
    bandRows.addEventListener('pointerleave', function () { rampTo(1, 620); });
  }

  var themeBtn = document.querySelector('.theme-toggle');
  if (themeBtn) {
    themeBtn.addEventListener('click', function () {
      var next = document.documentElement.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
      window.setTheme(next, this);
    });
  }
})();
