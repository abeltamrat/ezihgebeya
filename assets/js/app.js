// EzihGebeya front-end helpers — Alpine.js + HTMX edition
(function () {
  'use strict';

  // ── City → subcity dependent dropdown ───────────────────────────────────
  var CITIES = {
    'Addis Ababa': ['Bole','Yeka','Kirkos','Arada','Lideta','Gullele','Nifas Silk-Lafto','Kolfe Keranio','Akaky Kaliti','Addis Ketema','Lemi Kura'],
    'Adama': [], 'Hawassa': [], 'Bahir Dar': [], 'Mekelle': [], 'Dire Dawa': [], 'Jimma': []
  };
  var citySel = document.getElementById('city-select');
  var subSel  = document.getElementById('subcity-select');
  if (citySel && subSel) {
    var fillSubs = function () {
      var subs = CITIES[citySel.value] || [];
      var keep = subSel.dataset.selected || '';
      subSel.innerHTML = '<option value="">All / Select...</option>';
      subs.forEach(function (s) {
        var o = document.createElement('option');
        o.textContent = s;
        if (s === keep) o.selected = true;
        subSel.appendChild(o);
      });
    };
    citySel.addEventListener('change', function () { subSel.dataset.selected = ''; fillSubs(); });
    fillSubs();
  }

  // ── Phone reveal (avoid scraping) ───────────────────────────────────────
  document.querySelectorAll('.reveal-phone').forEach(function (a) {
    var shown = false;
    a.addEventListener('click', function (ev) {
      if (!shown) { ev.preventDefault(); a.textContent = 'Call ' + a.dataset.phone; shown = true; }
    });
  });

  // ── Video link target filter (vendor videos form) ────────────────────────
  var lt = document.getElementById('linked-type');
  var li = document.getElementById('linked-id');
  if (lt && li) {
    var filterOpts = function () {
      var t = lt.value;
      Array.prototype.forEach.call(li.options, function (o) {
        o.hidden = o.value !== '' && o.dataset.type !== t;
      });
      if (li.selectedOptions[0] && li.selectedOptions[0].hidden) li.value = '';
      li.closest('label').style.display = (t === 'business') ? 'none' : '';
    };
    lt.addEventListener('change', filterOpts);
    filterOpts();
  }

  // ── Telegram Mini App ────────────────────────────────────────────────────
  try {
    if (window.Telegram && Telegram.WebApp && Telegram.WebApp.initData) {
      Telegram.WebApp.ready();
      Telegram.WebApp.expand();
      document.documentElement.classList.add('tg-app');
      document.querySelectorAll('input[name="source"]').forEach(function (el) { el.value = 'telegram_mini_app'; });
    }
  } catch (e) {}

  // ── Silent GPS location detection ────────────────────────────────────────
  (function () {
    if (!navigator.geolocation) return;
    var source = document.body.dataset.locSource;
    if (source !== 'ip' && source !== 'default') return;
    if (sessionStorage.getItem('ak_geo_asked')) return;
    sessionStorage.setItem('ak_geo_asked', '1');
    var csrf = function () { var m = document.querySelector('meta[name="csrf-token"]'); return m ? m.content : ''; };
    navigator.geolocation.getCurrentPosition(function (pos) {
      fetch((window.AK_BASE || '/ezihgebeya') + '/location', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'fetch', 'Accept': 'application/json' },
        body: '_token=' + encodeURIComponent(csrf()) + '&lat=' + pos.coords.latitude + '&lng=' + pos.coords.longitude
      }).then(function () { location.reload(); }).catch(function () {});
    }, function () {}, { timeout: 8000, maximumAge: 600000 });
  })();

  // ── TikTok-style feed: only the visible slide keeps its iframe ───────────
  var feed = document.querySelector('.tiktok-feed');
  if (feed && 'IntersectionObserver' in window) {
    var slides = feed.querySelectorAll('.tiktok-slide');
    var activeVideoSrc = function (src) {
      if (!src) return '';
      try {
        var u = new URL(src, location.href);
        if (/youtube\.com|youtu\.be/.test(u.hostname)) {
          u.searchParams.set('autoplay', '1'); u.searchParams.set('mute', '1');
          u.searchParams.set('playsinline', '1'); u.searchParams.set('loop', '1');
          u.searchParams.set('controls', '0'); u.searchParams.set('modestbranding', '1');
          u.searchParams.set('rel', '0');
          var match = u.pathname.match(/\/embed\/([^/?]+)/);
          if (match && !u.searchParams.get('playlist')) u.searchParams.set('playlist', match[1]);
        } else if (/tiktok\.com/.test(u.hostname)) {
          u.searchParams.set('autoplay', '1'); u.searchParams.set('muted', '1');
          u.searchParams.set('mute', '1'); u.searchParams.set('playsinline', '1');
          u.searchParams.set('loop', '1'); u.searchParams.set('controls', '0');
          u.searchParams.set('hide_controls', '1');
        }
        return u.toString();
      } catch (e) { return src; }
    };
    slides.forEach(function (s) {
      var f = s.querySelector('.video-frame');
      if (f) f.dataset.src = f.dataset.src || f.getAttribute('src') || f.getAttribute('cite') || '';
    });
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        var f = entry.target.querySelector('.video-frame');
        if (!f) return;
        if (entry.isIntersecting && entry.intersectionRatio > 0.6) {
          entry.target.classList.add('is-active');
          if (f.tagName === 'IFRAME' && f.dataset.src) {
            var nextSrc = activeVideoSrc(f.dataset.src);
            if (f.getAttribute('src') !== nextSrc) f.setAttribute('src', nextSrc);
          }
        } else if (f.getAttribute('src')) {
          entry.target.classList.remove('is-active');
          if (f.tagName === 'IFRAME') f.removeAttribute('src');
        }
      });
    }, { root: feed, threshold: [0, 0.6] });
    slides.forEach(function (s) { io.observe(s); });
  }

  // ── PWA service worker ───────────────────────────────────────────────────
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register((window.AK_BASE || '/ezihgebeya') + '/sw.js').catch(function () {});
  }

  // ── Ripple effect on .btn elements ───────────────────────────────────────
  function addRipple(e) {
    var btn = e.currentTarget;
    var rect = btn.getBoundingClientRect();
    var size = Math.max(rect.width, rect.height) * 1.8;
    var x = e.clientX - rect.left - size / 2;
    var y = e.clientY - rect.top  - size / 2;
    var wave = document.createElement('span');
    wave.className = 'ripple-wave';
    wave.style.cssText = 'width:' + size + 'px;height:' + size + 'px;left:' + x + 'px;top:' + y + 'px';
    btn.appendChild(wave);
    wave.addEventListener('animationend', function () { wave.remove(); });
  }
  function attachRipples(root) {
    (root || document).querySelectorAll('.btn').forEach(function (btn) {
      if (!btn.dataset.ripple) {
        btn.dataset.ripple = '1';
        btn.addEventListener('click', addRipple);
      }
    });
  }
  attachRipples();

  // ── Scroll-reveal with IntersectionObserver ──────────────────────────────
  if ('IntersectionObserver' in window) {
    var revealIO = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          entry.target.classList.add('in-view');
          revealIO.unobserve(entry.target);
        }
      });
    }, { threshold: 0.12 });
    document.querySelectorAll('.reveal').forEach(function (el) { revealIO.observe(el); });
  }

  // ── Page progress bar ────────────────────────────────────────────────────
  var progress = document.getElementById('page-progress');
  var progressTimer;
  function progressStart() {
    if (!progress) return;
    clearTimeout(progressTimer);
    progress.style.width = '0';
    progress.classList.add('loading');
    var w = 0;
    progressTimer = setInterval(function () {
      w = w < 70 ? w + Math.random() * 12 : w < 90 ? w + 1 : w;
      progress.style.width = Math.min(w, 92) + '%';
    }, 180);
  }
  function progressDone() {
    if (!progress) return;
    clearTimeout(progressTimer);
    progress.style.width = '100%';
    setTimeout(function () { progress.classList.remove('loading'); progress.style.width = '0'; }, 340);
  }
  document.addEventListener('htmx:beforeRequest', progressStart);
  document.addEventListener('htmx:afterSwap', progressDone);
  document.addEventListener('htmx:responseError', progressDone);

  // ── Image lightbox ───────────────────────────────────────────────────────
  document.addEventListener('click', function (e) {
    var trigger = e.target.closest('[data-lightbox]');
    if (!trigger) return;
    e.preventDefault();
    var src = trigger.dataset.lightbox || trigger.href || trigger.src;
    if (!src) return;
    var wrap = document.createElement('div');
    wrap.className = 'lightbox-wrap';
    wrap.innerHTML = '<button class="lightbox-close" aria-label="Close">&times;</button>'
      + '<img class="lightbox-img" src="' + src + '" alt="">';
    document.body.appendChild(wrap);
    document.body.style.overflow = 'hidden';
    var close = function () { wrap.remove(); document.body.style.overflow = ''; };
    wrap.querySelector('.lightbox-close').addEventListener('click', close);
    wrap.addEventListener('click', function (ev) { if (ev.target === wrap) close(); });
    document.addEventListener('keydown', function esc(ev) { if (ev.key === 'Escape') { close(); document.removeEventListener('keydown', esc); } });
  });

  // ── HTMX: re-attach ripples after partial swaps ──────────────────────────
  document.addEventListener('htmx:afterSwap', function (e) {
    attachRipples(e.detail.elt);
  });

  // ── HTMX global config ───────────────────────────────────────────────────
  document.addEventListener('htmx:configRequest', function (e) {
    var token = document.querySelector('meta[name="csrf-token"]');
    if (token) e.detail.headers['X-CSRF-Token'] = token.content;
  });

})();

// ── Alpine.js components (registered before Alpine initializes) ──────────────
document.addEventListener('alpine:init', function () {

  // Toast store — window.dispatchEvent(new CustomEvent('toast', {detail:{msg,type}}))
  Alpine.store('toasts', {
    items: [],
    add: function (msg, type) {
      var id = Date.now() + Math.random();
      this.items.push({ id: id, msg: msg, type: type || 'success' });
      var self = this;
      setTimeout(function () { self.remove(id); }, 4200);
    },
    remove: function (id) {
      this.items = this.items.filter(function (t) { return t.id !== id; });
    }
  });

  window.addEventListener('toast', function (e) {
    Alpine.store('toasts').add(e.detail.msg, e.detail.type);
  });

});

// ── Search autocomplete Alpine component ────────────────────────────────────
function searchAc() {
  return {
    open: false,
    results: false,
    close: function () { this.open = false; },
    suggest: function (val) {
      this.results = val.length > 1;
      this.open = this.results;
    },
    focusResult: function (dir) {
      var links = document.querySelectorAll('#ac-results a');
      if (!links.length) return;
      var focused = document.activeElement;
      var idx = Array.from(links).indexOf(focused);
      var next = idx + dir;
      if (next < 0) { this.$refs.input.focus(); return; }
      if (next >= links.length) next = 0;
      links[next].focus();
    },
    submitOrGo: function (e) {
      var focused = document.activeElement;
      if (focused && focused.closest('#ac-results')) { return; }
    }
  };
}
