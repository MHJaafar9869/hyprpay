/**
 * Drop the sticky header out of the way while the reader scrolls down, and bring
 * it straight back on any upward move. The bar stays put over the first screenful
 * (HIDE_AFTER) so it never flickers away on a short page, and moves under DELTA
 * are ignored so pointer jitter and iOS rubber-banding don't toggle it. Nothing
 * in the bar has to stay reachable mid-page — the anchors, the theme toggle and
 * the GitHub link are all one upward flick away — so this runs at every width.
 */
(function () {
  var root = document.documentElement;
  var nav = document.querySelector('.nav');
  if (!nav) return;

  var HIDE_AFTER = 120, DELTA = 6, last = window.scrollY, ticking = false;

  /** Compare against the last settled offset and flip the hidden flag. */
  function update() {
    ticking = false;
    var y = Math.max(0, window.scrollY);
    var moved = y - last;
    if (Math.abs(moved) < DELTA) return;
    last = y;
    root.classList.toggle('nav-hidden', moved > 0 && y > HIDE_AFTER);
  }

  /** Coalesce scroll events into one update per animation frame. */
  function onScroll() { if (!ticking) { ticking = true; requestAnimationFrame(update); } }

  window.addEventListener('scroll', onScroll, { passive: true });
  /** A keyboard user tabbing into the bar has to be able to see it. */
  nav.addEventListener('focusin', function () { root.classList.remove('nav-hidden'); });
})();
