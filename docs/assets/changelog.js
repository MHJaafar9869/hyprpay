/** Changelog page: read the release manifest + per-release Markdown and render a version timeline. */
(function () {
  var GH_RELEASES = 'https://github.com/MHJaafar9869/hyprpay/releases';
  var listEl   = document.getElementById('log-list');
  var indexEl  = document.getElementById('log-index');
  var latestEl = document.getElementById('log-latest');

  /** Turn a version like "0.3.2" into an element id like "v0-3-2". */
  function slug(v) { return 'v' + String(v).replace(/\./g, '-'); }

  /** Replace the release list with a failure notice that links out to GitHub releases. */
  function fail(msg) {
    listEl.innerHTML =
      '<div class="log-error"><p>' + msg + '</p>' +
      '<p><a class="log-cta" href="' + GH_RELEASES + '" target="_blank" rel="noopener">' +
      'View releases on GitHub&nbsp;↗</a></p></div>';
  }

  /** Build the sticky version index — one anchor per release. */
  function renderIndex(releases) {
    indexEl.innerHTML = releases.map(function (r) {
      return '<li><a href="#' + slug(r.version) + '">' +
        '<span class="iv">v' + r.version + '</span>' +
        '<span class="id">' + r.date + '</span></a></li>';
    }).join('');
  }

  /** One release card: version badge + date + title + rendered Markdown body. */
  function cardHtml(release, bodyHtml) {
    var id = slug(release.version);
    return '<article class="release" id="' + id + '">' +
      '<header class="release-head">' +
        '<a class="release-v" href="#' + id + '">v' + release.version + '</a>' +
        '<time class="release-date">' + release.date + '</time>' +
      '</header>' +
      '<h2 class="release-title">' + release.title + '</h2>' +
      '<div class="release-body">' + bodyHtml + '</div>' +
    '</article>';
  }

  /** Fetch a release's Markdown file and render it to a card (falls back on a fetch error). */
  function loadCard(release) {
    return fetch('./changelog/' + release.file)
      .then(function (res) { if (!res.ok) throw new Error(release.file); return res.text(); })
      .then(function (md) { return cardHtml(release, window.marked.parse(md)); })
      .catch(function () { return cardHtml(release, '<p class="log-missing">Release notes unavailable.</p>'); });
  }

  /** Highlight the index entry for whichever release is currently in view. */
  function wireSpy() {
    if (!('IntersectionObserver' in window)) return;
    var links = {};
    [].forEach.call(indexEl.querySelectorAll('a'), function (l) { links[l.getAttribute('href').slice(1)] = l; });
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) {
        if (!e.isIntersecting) return;
        for (var id in links) links[id].classList.remove('active');
        if (links[e.target.id]) links[e.target.id].classList.add('active');
      });
    }, { rootMargin: '-20% 0px -70% 0px', threshold: 0 });
    [].forEach.call(listEl.querySelectorAll('.release'), function (a) { io.observe(a); });
  }

  /** Render the index and every release card from the manifest's release list. */
  function render(releases) {
    if (!releases.length) { fail('No releases have been published yet.'); return; }
    if (latestEl) latestEl.textContent = 'v' + releases[0].version;
    renderIndex(releases);
    Promise.all(releases.map(loadCard)).then(function (cards) {
      listEl.innerHTML = cards.join('');
      wireSpy();
    });
  }

  /** Persist and apply a dark/light theme flip (shared behaviour with the other pages). */
  function wireTheme() {
    var btn = document.querySelector('.theme-toggle');
    if (!btn) return;
    btn.addEventListener('click', function () {
      var next = document.documentElement.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
      document.documentElement.setAttribute('data-theme', next);
      try { localStorage.setItem('theme', next); } catch (e) {}
    });
  }

  /** Show the back-to-top button past 400px of scroll and smooth-scroll up on click. */
  function wireToTop() {
    var toTop = document.querySelector('.to-top');
    if (!toTop) return;
    window.addEventListener('scroll', function () { toTop.classList.toggle('show', window.scrollY > 400); }, { passive: true });
    toTop.addEventListener('click', function () { window.scrollTo({ top: 0, behavior: 'smooth' }); });
  }

  wireTheme();
  wireToTop();

  if (!window.marked || typeof window.marked.parse !== 'function') {
    fail('The Markdown renderer failed to load.');
    return;
  }

  fetch('./changelog/manifest.json')
    .then(function (res) { if (!res.ok) throw new Error('manifest'); return res.json(); })
    .then(function (data) { render((data && data.releases) || []); })
    .catch(function () { fail('The changelog could not be loaded.'); });
})();
