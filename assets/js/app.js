// EzihGebeya front-end helpers — Alpine.js + HTMX edition
(function () {
  'use strict';

  // Floating notification center
  (function () {
    var center = document.querySelector('.notification-center');
    if (!center) return;
    var trigger = center.querySelector('.nav-notification-trigger');
    var flyout = center.querySelector('.notification-flyout');
    var markAll = center.querySelector('.notification-mark-all');
    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    var apiBase = (window.AK_BASE || '').replace(/\/$/, '') + '/api/v1/account/notifications';

    var setOpen = function (open) {
      if (open) {
        var rect = trigger.getBoundingClientRect();
        flyout.style.setProperty('--notification-flyout-top', Math.round(rect.bottom + 8) + 'px');
      }
      flyout.hidden = !open;
      trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
    };
    var updateCount = function (count) {
      count = Math.max(0, Number(count) || 0);
      var navCount = trigger.querySelector('.notification-nav-count');
      if (count > 0) {
        if (!navCount) {
          navCount = document.createElement('span');
          navCount.className = 'pill notification-nav-count';
          trigger.appendChild(navCount);
        }
        navCount.textContent = String(count);
      } else if (navCount) {
        navCount.remove();
      }
      trigger.setAttribute('aria-label', 'Open notifications' + (count ? ' (' + count + ' unread)' : ''));
      var headCount = flyout.querySelector('.notification-head-count');
      if (headCount) headCount.textContent = count ? '(' + count + ' new)' : '';
      if (markAll) markAll.hidden = count === 0;
    };
    var postRead = function (suffix) {
      return fetch(apiBase + suffix, {
        method: 'POST',
        credentials: 'same-origin',
        keepalive: true,
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-Token': csrfMeta ? csrfMeta.content : ''
        },
        body: '{}'
      }).then(function (response) {
        if (!response.ok) throw new Error('Could not update notifications');
        return response.json();
      });
    };

    trigger.addEventListener('click', function (event) {
      event.preventDefault();
      event.stopPropagation();
      setOpen(flyout.hidden);
    });
    flyout.addEventListener('click', function (event) { event.stopPropagation(); });
    document.addEventListener('click', function () { setOpen(false); });
    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && !flyout.hidden) {
        setOpen(false);
        trigger.focus();
      }
    });
    window.addEventListener('resize', function () {
      if (!flyout.hidden) {
        var rect = trigger.getBoundingClientRect();
        flyout.style.setProperty('--notification-flyout-top', Math.round(rect.bottom + 8) + 'px');
      }
    });

    flyout.querySelectorAll('.notification-flyout-item[data-notification-id]').forEach(function (item) {
      item.addEventListener('click', function () {
        var id = item.dataset.notificationId;
        if (!id || !item.classList.contains('is-unread')) return;
        item.classList.remove('is-unread');
        var dot = item.querySelector('.notification-unread-dot');
        if (dot) dot.remove();
        postRead('/' + encodeURIComponent(id) + '/read')
          .then(function (data) { updateCount(data.unread_count); })
          .catch(function () {});
      });
    });
    if (markAll) {
      markAll.addEventListener('click', function () {
        markAll.disabled = true;
        postRead('/read-all').then(function (data) {
          flyout.querySelectorAll('.notification-flyout-item.is-unread').forEach(function (item) {
            item.classList.remove('is-unread');
            var dot = item.querySelector('.notification-unread-dot');
            if (dot) dot.remove();
          });
          updateCount(data.unread_count);
        }).catch(function () {
          markAll.disabled = false;
        });
      });
    }
  })();

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
      fetch((window.AK_BASE || '') + '/location', {
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
    navigator.serviceWorker.register((window.AK_BASE || '') + '/sw.js', { updateViaCache: 'none' })
      .then(function (registration) {
        if (document.visibilityState === 'visible') registration.update().catch(function () {});
        document.addEventListener('visibilitychange', function () {
          if (document.visibilityState === 'visible') registration.update().catch(function () {});
        });
      })
      .catch(function () {});
  }

  var deferredInstallPrompt = null;
  var installPrompt = document.getElementById('install-prompt');
  var installAccept = document.getElementById('install-prompt-accept');
  var installDismiss = document.getElementById('install-prompt-dismiss');
  var installDismissedUntil = parseInt(localStorage.getItem('eg_install_dismissed_until') || '0', 10);
  window.addEventListener('beforeinstallprompt', function (e) {
    if (!installPrompt || Date.now() < installDismissedUntil) return;
    e.preventDefault();
    deferredInstallPrompt = e;
    installPrompt.hidden = false;
  });
  if (installAccept) installAccept.addEventListener('click', function () {
    if (!deferredInstallPrompt) return;
    installPrompt.hidden = true;
    deferredInstallPrompt.prompt();
    deferredInstallPrompt.userChoice.finally(function () { deferredInstallPrompt = null; });
  });
  if (installDismiss) installDismiss.addEventListener('click', function () {
    localStorage.setItem('eg_install_dismissed_until', String(Date.now() + 7 * 24 * 60 * 60 * 1000));
    if (installPrompt) installPrompt.hidden = true;
    deferredInstallPrompt = null;
  });
  window.addEventListener('appinstalled', function () {
    if (installPrompt) installPrompt.hidden = true;
    localStorage.setItem('eg_install_dismissed_until', String(Date.now() + 365 * 24 * 60 * 60 * 1000));
  });

  function announceConnectivity() {
    if (!navigator.onLine) {
      window.dispatchEvent(new CustomEvent('toast', { detail: { msg: 'You are offline. Browsing may be limited and actions need a connection.', type: 'warning' } }));
      document.body.classList.add('is-offline');
    } else {
      if (document.body.classList.contains('is-offline')) {
        window.dispatchEvent(new CustomEvent('toast', { detail: { msg: 'Back online.', type: 'success' } }));
      }
      document.body.classList.remove('is-offline');
    }
  }
  window.addEventListener('offline', announceConnectivity);
  window.addEventListener('online', announceConnectivity);
  if (!navigator.onLine) announceConnectivity();

  // ── Ripple effect on .btn elements ───────────────────────────────────────
  // Core Web Vitals / field performance telemetry. Native APIs only; no external library.
  (function () {
    if (!('PerformanceObserver' in window)) return;
    var endpoint = (window.AK_BASE || '') + '/web-vitals';
    var tokenEl = document.querySelector('meta[name="csrf-token"]');
    var token = tokenEl ? tokenEl.content : '';
    if (!token) return;
    var pageKey = location.pathname + location.search;
    var sent = {};
    function rating(metric, value) {
      var t = { LCP: [2500, 4000], CLS: [0.1, 0.25], INP: [200, 500], FID: [100, 300], FCP: [1800, 3000], TTFB: [800, 1800] }[metric] || [0, 0];
      return value <= t[0] ? 'good' : (value <= t[1] ? 'needs-improvement' : 'poor');
    }
    function send(metric, value) {
      if (!isFinite(value) || value < 0) return;
      var once = 'eg_vital_' + metric + '_' + pageKey;
      if (sent[metric] || sessionStorage.getItem(once)) return;
      sent[metric] = true;
      sessionStorage.setItem(once, '1');
      var data = new FormData();
      data.append('_token', token);
      data.append('metric', metric);
      data.append('value', metric === 'CLS' ? String(Math.round(value * 10000) / 10000) : String(Math.round(value)));
      data.append('rating', rating(metric, value));
      data.append('path', location.pathname);
      data.append('page_type', document.body ? (document.body.dataset.pageType || document.body.className || '') : '');
      data.append('connection', navigator.connection ? (navigator.connection.effectiveType || '') : '');
      data.append('device_memory', navigator.deviceMemory || '');
      data.append('viewport', window.innerWidth + 'x' + window.innerHeight);
      if (navigator.sendBeacon) navigator.sendBeacon(endpoint, data);
      else fetch(endpoint, { method: 'POST', body: data, credentials: 'same-origin', keepalive: true }).catch(function () {});
    }
    try {
      var nav = performance.getEntriesByType && performance.getEntriesByType('navigation')[0];
      if (nav) setTimeout(function () { send('TTFB', nav.responseStart); }, 0);
    } catch (e) {}
    try {
      new PerformanceObserver(function (list) {
        var entries = list.getEntries();
        var last = entries[entries.length - 1];
        if (last) send('FCP', last.startTime);
      }).observe({ type: 'paint', buffered: true });
    } catch (e) {}
    try {
      new PerformanceObserver(function (list) {
        var entries = list.getEntries();
        var last = entries[entries.length - 1];
        if (last) send('LCP', last.startTime);
      }).observe({ type: 'largest-contentful-paint', buffered: true });
    } catch (e) {}
    try {
      var cls = 0;
      new PerformanceObserver(function (list) {
        list.getEntries().forEach(function (entry) { if (!entry.hadRecentInput) cls += entry.value; });
      }).observe({ type: 'layout-shift', buffered: true });
      addEventListener('visibilitychange', function () { if (document.visibilityState === 'hidden') send('CLS', cls); });
      addEventListener('pagehide', function () { send('CLS', cls); });
    } catch (e) {}
    try {
      new PerformanceObserver(function (list) {
        list.getEntries().forEach(function (entry) { send('INP', entry.processingStart - entry.startTime); });
      }).observe({ type: 'event', buffered: true, durationThreshold: 40 });
    } catch (e) {}
    try {
      new PerformanceObserver(function (list) {
        var first = list.getEntries()[0];
        if (first) send('FID', first.processingStart - first.startTime);
      }).observe({ type: 'first-input', buffered: true });
    } catch (e) {}
  })();

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
  var revealIO = null;
  var observeReveals = function (root) {
    var scope = root && root.querySelectorAll ? root : document;
    var elements = scope.querySelectorAll('.reveal:not([data-reveal-bound])');
    elements.forEach(function (el) {
      el.dataset.revealBound = '1';
      if (revealIO) revealIO.observe(el);
      else el.classList.add('in-view');
    });
  };
  if ('IntersectionObserver' in window) {
    revealIO = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          entry.target.classList.add('in-view');
          revealIO.unobserve(entry.target);
        }
      });
    }, { threshold: 0.12 });
  }
  observeReveals(document);

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
    observeReveals(e.detail.elt);
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
