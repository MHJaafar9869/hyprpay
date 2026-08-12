/** Keep the fixed bottom-right buttons above the mobile browser toolbar by tracking the visual viewport. */
(function () {
  var vv = window.visualViewport;
  if (!vv) return;
  var root = document.documentElement;

  /** Publish the gap between the layout-viewport bottom and the visible viewport bottom as --vv-bottom. */
  function update() {
    var gap = root.clientHeight - vv.height - vv.offsetTop;
    root.style.setProperty('--vv-bottom', Math.max(0, Math.round(gap)) + 'px');
  }

  vv.addEventListener('resize', update);
  vv.addEventListener('scroll', update);
  update();
})();
