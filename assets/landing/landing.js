/* ShootPilot homepage interactions. Vanilla JS, no dependencies. */
(function () {
  'use strict';
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

  /* Theme toggle (shares the "pcrm-theme" key with the CRM app). */
  $$('[data-theme-toggle]').forEach(function (b) {
    b.addEventListener('click', function () {
      var dark = document.documentElement.classList.toggle('dark');
      try { localStorage.setItem('pcrm-theme', dark ? 'dark' : 'light'); } catch (e) {}
    });
  });

  /* Sticky nav border + mobile menu. */
  var nav = $('[data-nav]');
  var onScroll = function () { if (nav) nav.classList.toggle('scrolled', window.scrollY > 8); };
  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll();

  var menuBtn = $('[data-menu-toggle]');
  var menu = $('#mobile-menu');
  var setMenu = function (open) {
    if (!menuBtn || !menu) return;
    menuBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
    menuBtn.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
    menu.hidden = !open;
  };
  if (menuBtn) {
    menuBtn.addEventListener('click', function () { setMenu(menuBtn.getAttribute('aria-expanded') !== 'true'); });
    $$('a', menu).forEach(function (a) { a.addEventListener('click', function () { setMenu(false); }); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') setMenu(false); });
    window.addEventListener('resize', function () { if (window.innerWidth > 960) setMenu(false); });
  }

  /* Active nav link while scrolling. */
  var links = $$('.nav-links a');
  if ('IntersectionObserver' in window && links.length) {
    var map = {};
    links.forEach(function (a) { var id = a.getAttribute('href').slice(1); var el = document.getElementById(id); if (el) map[id] = a; });
    var spy = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) {
        if (en.isIntersecting && map[en.target.id]) {
          links.forEach(function (l) { l.classList.remove('active'); l.removeAttribute('aria-current'); });
          map[en.target.id].classList.add('active');
          map[en.target.id].setAttribute('aria-current', 'true');
        }
      });
    }, { rootMargin: '-45% 0px -50% 0px' });
    Object.keys(map).forEach(function (id) { spy.observe(document.getElementById(id)); });
  }

  /* Product showcase tabs (WAI-ARIA tabs pattern). */
  var tabs = $$('.tabs [role="tab"]');
  var select = function (tab, focus) {
    tabs.forEach(function (t) {
      var on = t === tab;
      t.setAttribute('aria-selected', on ? 'true' : 'false');
      t.tabIndex = on ? 0 : -1;
      var p = document.getElementById(t.getAttribute('aria-controls'));
      if (p) p.hidden = !on;
    });
    if (focus) tab.focus();
  };
  tabs.forEach(function (t, i) {
    t.addEventListener('click', function () { select(t); });
    t.addEventListener('keydown', function (e) {
      var n = null;
      if (e.key === 'ArrowRight') n = tabs[(i + 1) % tabs.length];
      if (e.key === 'ArrowLeft') n = tabs[(i - 1 + tabs.length) % tabs.length];
      if (e.key === 'Home') n = tabs[0];
      if (e.key === 'End') n = tabs[tabs.length - 1];
      if (n) { e.preventDefault(); select(n, true); }
    });
  });

  /* Pricing monthly / yearly toggle. */
  $$('[data-bill]').forEach(function (b) {
    b.addEventListener('click', function () {
      var mode = b.getAttribute('data-bill');
      $$('[data-bill]').forEach(function (x) {
        var on = x === b;
        x.classList.toggle('on', on);
        x.setAttribute('aria-pressed', on ? 'true' : 'false');
      });
      $$('.price [data-' + mode + ']').forEach(function (el) { el.textContent = el.getAttribute('data-' + mode); });
    });
  });

  /* FAQ accordion. */
  $$('.acc-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var open = btn.getAttribute('aria-expanded') === 'true';
      btn.setAttribute('aria-expanded', open ? 'false' : 'true');
      var p = document.getElementById(btn.getAttribute('aria-controls'));
      if (p) p.hidden = open;
    });
  });
  /* Open a FAQ when linked directly, e.g. #faq-whatsapp from the footer. */
  var openHash = function () {
    var h = location.hash && document.getElementById(location.hash.slice(1));
    if (h && h.classList.contains('acc-item')) {
      var btn = $('.acc-btn', h);
      if (btn && btn.getAttribute('aria-expanded') !== 'true') btn.click();
    }
  };
  window.addEventListener('hashchange', openHash);
  openHash();

  /* Gentle reveal on scroll. */
  var rv = $$('.sec-head, .ps, .fc, .step, .who-grid .who, .price, .t-card, .caps-row li, .stage-wrap, .final-card');
  if ('IntersectionObserver' in window && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) { if (en.isIntersecting) { en.target.classList.add('in'); io.unobserve(en.target); } });
    }, { rootMargin: '0px 0px -8% 0px' });
    rv.forEach(function (el) { el.classList.add('rv'); io.observe(el); });
  }
})();
