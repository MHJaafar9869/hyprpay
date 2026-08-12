/**
 * Animated light/dark theme switch. Exposes window.setTheme(next, btn):
 * a circular reveal expanding from the toggle button via the View Transitions
 * API where supported, a colour cross-fade fallback otherwise, and an instant
 * switch when the user prefers reduced motion. The per-page toggle handlers call this.
 */
(function () {
  var root = document.documentElement;

  /** Commit the theme to the DOM and persist it. */
  function commit(next) {
    root.setAttribute('data-theme', next);
    try { localStorage.setItem('theme', next); } catch (e) {}
  }

  window.setTheme = function (next, btn) {
    var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    if (reduced) {
      commit(next);
      return;
    }

    if (!document.startViewTransition) {
      root.classList.add('theme-anim');
      commit(next);
      window.setTimeout(function () { root.classList.remove('theme-anim'); }, 480);
      return;
    }

    var rect = btn && btn.getBoundingClientRect
      ? btn.getBoundingClientRect()
      : { left: window.innerWidth, top: 0, width: 0, height: 0 };
    var x = rect.left + rect.width / 2;
    var y = rect.top + rect.height / 2;
    var radius = Math.hypot(Math.max(x, window.innerWidth - x), Math.max(y, window.innerHeight - y));

    var transition = document.startViewTransition(function () { commit(next); });

    transition.ready.then(function () {
      root.animate(
        {
          clipPath: [
            'circle(0px at ' + x + 'px ' + y + 'px)',
            'circle(' + radius + 'px at ' + x + 'px ' + y + 'px)',
          ],
        },
        {
          duration: 480,
          easing: 'cubic-bezier(0.16, 1, 0.3, 1)',
          pseudoElement: '::view-transition-new(root)',
        }
      );
    });
  };
})();
