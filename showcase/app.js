(function () {
  'use strict';
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ---------- top bar: stuck line, reading progress, active chapter ---------- */
  var top = document.getElementById('top');
  var bar = top && top.querySelector('.progress i');
  var links = [].slice.call(document.querySelectorAll('.chapters a'));
  var sections = links.map(function (a) { return document.querySelector(a.getAttribute('href')); });
  var ticking = false;
  function onScroll() {
    if (ticking) return;
    ticking = true;
    requestAnimationFrame(function () {
      ticking = false;
      var y = window.scrollY, h = document.documentElement.scrollHeight - innerHeight;
      top.classList.toggle('is-stuck', y > 8);
      var wa = document.querySelector('.wa-float');
      var cmp = document.getElementById('compare');
      var inCompare = false;
      if (cmp) { var cr = cmp.getBoundingClientRect(); inCompare = cr.top < innerHeight && cr.bottom > 0; }
      if (wa) wa.classList.toggle('is-on', y > innerHeight * 0.8 && y < h - innerHeight * 0.6 && !inCompare);
      if (bar) bar.style.width = (h > 0 ? Math.min(100, (y / h) * 100) : 0) + '%';
      var cur = -1;
      sections.forEach(function (s, i) { if (s && s.getBoundingClientRect().top < innerHeight * 0.35) cur = i; });
      links.forEach(function (a, i) { a.classList.toggle('is-on', i === cur); });
    });
  }
  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll();

  /* ---------- reveal ---------- */
  var rv = [].slice.call(document.querySelectorAll('.rv'));
  if ('IntersectionObserver' in window && !reduce) {
    var io = new IntersectionObserver(function (es) {
      es.forEach(function (e) { if (e.isIntersecting) { e.target.classList.add('in'); io.unobserve(e.target); } });
    }, { rootMargin: '0px 0px -8% 0px', threshold: 0.06 });
    rv.forEach(function (el) { io.observe(el); });
  } else {
    rv.forEach(function (el) { el.classList.add('in'); });
  }

  /* ---------- segmented swaps (desktop / mobile etc.) ---------- */
  document.querySelectorAll('[data-swap]').forEach(function (box) {
    var btns = [].slice.call(box.querySelectorAll('.seg button'));
    var panes = [].filter.call(box.children, function (c) { return !c.classList.contains('seg'); });
    btns.forEach(function (b) {
      b.addEventListener('click', function () {
        var n = +b.dataset.show;
        btns.forEach(function (x) { x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); });
        panes.forEach(function (p, i) { p.hidden = i !== n; });
      });
    });
  });

  /* ---------- tabs (backstage) ---------- */
  document.querySelectorAll('[data-tabs]').forEach(function (box) {
    var tabs = [].slice.call(box.querySelectorAll('[role=tab]'));
    function select(t, focus) {
      tabs.forEach(function (x) {
        var on = x === t;
        x.setAttribute('aria-selected', on ? 'true' : 'false');
        x.tabIndex = on ? 0 : -1;
        document.getElementById(x.getAttribute('aria-controls')).hidden = !on;
      });
      if (focus) t.focus();
    }
    tabs.forEach(function (t, i) {
      t.tabIndex = i === 0 ? 0 : -1;
      t.addEventListener('click', function () { select(t); });
      t.addEventListener('keydown', function (e) {
        var d = { ArrowDown: 1, ArrowRight: -1, ArrowUp: -1, ArrowLeft: 1 }[e.key];
        if (!d) return;
        e.preventDefault();
        select(tabs[(i + d + tabs.length) % tabs.length], true);
      });
    });
  });

  /* ---------- pins <-> legend ---------- */
  document.querySelectorAll('.legend[data-for]').forEach(function (ol) {
    var wrap = document.querySelector('.pinwrap[data-name="' + ol.dataset.for + '"]');
    if (!wrap) return;
    var pins = [].slice.call(wrap.querySelectorAll('.pin'));
    var items = [].slice.call(ol.children);
    function hot(n, on) {
      if (pins[n]) pins[n].classList.toggle('is-hot', on);
      if (items[n]) items[n].classList.toggle('is-hot', on);
    }
    items.forEach(function (li, n) {
      li.addEventListener('mouseenter', function () { hot(n, true); });
      li.addEventListener('mouseleave', function () { hot(n, false); });
    });
    pins.forEach(function (p, n) {
      p.addEventListener('mouseenter', function () { hot(n, true); });
      p.addEventListener('mouseleave', function () { hot(n, false); });
    });
  });

  /* ---------- lightbox ---------- */
  var lb = document.querySelector('dialog.lb');
  if (lb && typeof lb.showModal === 'function') {
    var lbImg = lb.querySelector('img');
    document.addEventListener('click', function (e) {
      var img = e.target.closest('.browser img, .phone img');
      if (!img) return;
      lbImg.src = img.currentSrc || img.src;
      lbImg.alt = img.alt;
      lb.showModal();
    });
    lb.addEventListener('click', function (e) { if (e.target === lb || e.target.tagName === 'BUTTON') lb.close(); });
  }

  /* ---------- speed rings ---------- */
  var C = 2 * Math.PI * 44;
  document.querySelectorAll('.score').forEach(function (s) {
    var fg = s.querySelector('.fg');
    fg.style.strokeDasharray = C;
    fg.style.strokeDashoffset = C;
    var val = +s.dataset.score;
    function go() { fg.style.strokeDashoffset = C * (1 - val / 100); }
    if ('IntersectionObserver' in window && !reduce) {
      var o = new IntersectionObserver(function (es) { if (es[0].isIntersecting) { go(); o.disconnect(); } }, { threshold: 0.4 });
      o.observe(s);
    } else { go(); }
  });

  /* ---------- Shopify comparison ---------- */
  var ROWS = [
    ['חיפוש וסינון מתקדם', 'Searchanise Search & Filter', 19.00, 'searchanise'],
    ['מגה מניו עם תמונות ומוצרים', 'Buddha Mega Menu', 9.95, 'buddha-mega-menu'],
    ['נקנים יחד עם הנחת חבילה', 'Frequently Bought Together', 39.99, 'frequently-bought-together'],
    ['מגירת סל, אפסייל ובר משלוח חינם', 'Upcart', 54.99, 'upcart-cart-builder'],
    ['אפסייל לפני ואחרי הוספה לסל', 'Selleasy', 19.00, 'upsell-cross-sell-kit-1'],
    ['מוצרים דומים', 'Also Bought', 19.99, 'also-bought'],
    ['גלריה לכל צבע וסוואטשים', 'Rubik Variant Images', 50.00, 'rubik-variant-images'],
    ['תאריך משלוח משוער', 'Estimated Delivery Date Plus', 4.99, 'omega-estimated-shipping-date'],
    ['"נותרו X במלאי"', 'Scarcity.AI Low Stock Counter', 4.99, 'scarcity-ai-low-stock-counter'],
    ['טאבים בדף מוצר', 'Tabs Studio', 3.00, 'tabs-by-station'],
    ['כפתור הוספה לסל צמוד', 'STKY Sticky Add To Cart', 6.99, 'sticky-add-to-cart-bar'],
    ['התראה על חזרה למלאי', 'Amp Back in Stock', 19.00, 'back-in-stock'],
    ['כפתור וואטסאפ ויצירת קשר', 'Chaty', 15.00, 'chaty'],
    ['בילדר עמודים', 'GemPages', 29.00, 'gempages'],
    ['עמודי מותג', 'Easy Brand Page', 9.00, 'easy-brand-page'],
    ['דף תודה מותאם', 'ReConvert', 79.99, 'reconvert-upsell-cross-sell'],
    ['חבר מביא חבר', 'ReferralCandy', 39.00, 'referralcandy'],
    ['התחברות ב־SMS וברשתות', 'Simplify My Login', 4.99, 'login-using-otp'],
    ['SEO כולל נתונים מובנים', 'Booster AI SEO', 39.00, 'booster-apps-seo-optimizer'],
    ['הפניות 301 ויומן 404', 'Easy Redirects', 14.99, 'easyredirects'],
    ['דחיסת תמונות ו־WebP', 'TinyIMG', 14.00, 'smart-image-optimizer'],
    ['פיקסלים מהשרת: Meta, GA4, TikTok', 'Analyzify', 145.00, 'analyzify'],
    ['פידים לגוגל ולמטה', 'Simprosys Google Shopping Feed', 4.99, 'google-shopping-feed'],
    ['באנר עוגיות ו־Consent Mode v2', 'Pandectes GDPR', 9.00, 'gdpr-cookie-consent'],
    ['ניקוי קבצים שלא בשימוש', 'Media Cleanup', 14.99, 'media-cleanup']
  ];
  var tbody = document.querySelector('[data-rows]');
  var totalEl = document.querySelector('[data-total]');
  var ph = document.querySelector('[data-ph]');
  var monthly = ROWS.reduce(function (s, r) { return s + r[2]; }, 0);
  var period = 'y';
  var fmt = function (n) { return '$' + n.toLocaleString('en-US', { minimumFractionDigits: n % 1 ? 2 : 0, maximumFractionDigits: 2 }); };
  var tick = '<svg viewBox="0 0 16 16" aria-hidden="true"><path d="M3 8.5l3 3 7-7" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>';

  document.querySelectorAll('[data-total-year]').forEach(function (el) { el.textContent = fmt(Math.round(monthly * 12)); });

  if (tbody) {
    tbody.innerHTML = ROWS.map(function (r) {
      return '<tr class="reveal"><td>' + r[0] + '</td><td class="app"><a href="https://apps.shopify.com/' + r[3] + '" target="_blank" rel="noopener nofollow">' + r[1] + '</a></td><td class="price" data-m="' + r[2] + '"></td><td class="ours"><span class="inc">' + tick + 'כלול</span></td></tr>';
    }).join('');
    var shown = 0;
    function paint(animate) {
      var mult = period === 'y' ? 12 : 1;
      ph.textContent = period === 'y' ? 'לשנה' : 'לחודש';
      tbody.querySelectorAll('td.price').forEach(function (td) { td.textContent = fmt(Math.round(+td.dataset.m * mult * 100) / 100); });
      var target = Math.round(monthly * mult * 100) / 100;
      if (!animate || reduce) { totalEl.textContent = fmt(target); shown = target; return; }
      var from = shown, t0 = performance.now(), dur = 900;
      (function step(t) {
        var k = Math.min(1, (t - t0) / dur), e = 1 - Math.pow(1 - k, 3);
        var v = from + (target - from) * e;
        totalEl.textContent = fmt(k < 1 ? Math.round(v) : target);
        if (k < 1) requestAnimationFrame(step); else shown = target;
      })(t0);
    }
    paint(false);
    totalEl.textContent = '$0'; shown = 0;
    var rows = [].slice.call(tbody.children);
    if ('IntersectionObserver' in window && !reduce) {
      var to = new IntersectionObserver(function (es) {
        if (!es[0].isIntersecting) return;
        to.disconnect();
        rows.forEach(function (tr, i) { setTimeout(function () { tr.classList.add('in'); }, i * 35); });
        setTimeout(function () { paint(true); }, rows.length * 35);
      }, { threshold: 0.15 });
      to.observe(tbody);
    } else {
      rows.forEach(function (tr) { tr.classList.add('in'); });
      paint(false);
    }
    document.querySelectorAll('[data-period] button').forEach(function (b) {
      b.addEventListener('click', function () {
        period = b.dataset.p;
        document.querySelectorAll('[data-period] button').forEach(function (x) { x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); });
        paint(true);
      });
    });
  }
})();
