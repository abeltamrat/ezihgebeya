// EzihGebeya front-end helpers
(function () {
  // city to subcity dependent dropdown
  var CITIES = {
    'Addis Ababa': ['Bole','Yeka','Kirkos','Arada','Lideta','Gullele','Nifas Silk-Lafto','Kolfe Keranio','Akaky Kaliti','Addis Ketema','Lemi Kura'],
    'Adama': [], 'Hawassa': [], 'Bahir Dar': [], 'Mekelle': [], 'Dire Dawa': [], 'Jimma': []
  };
  var citySel = document.getElementById('city-select');
  var subSel = document.getElementById('subcity-select');
  if (citySel && subSel) {
    var fill = function () {
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
    citySel.addEventListener('change', function () { subSel.dataset.selected = ''; fill(); });
    fill();
  }

  // phone reveal (avoid scraping, mimic marketplace behavior)
  document.querySelectorAll('.reveal-phone').forEach(function (a) {
    var shown = false;
    a.addEventListener('click', function (ev) {
      if (!shown) {
        ev.preventDefault();
        a.textContent = 'Call ' + a.dataset.phone;
        shown = true;
      }
    });
  });

  // video link target filter (vendor videos form)
  var lt = document.getElementById('linked-type');
  var li = document.getElementById('linked-id');
  if (lt && li) {
    var filter = function () {
      var t = lt.value;
      Array.prototype.forEach.call(li.options, function (o) {
        o.hidden = o.value !== '' && o.dataset.type !== t;
      });
      if (li.selectedOptions[0] && li.selectedOptions[0].hidden) li.value = '';
      li.closest('label').style.display = (t === 'business') ? 'none' : '';
    };
    lt.addEventListener('change', filter);
    filter();
  }

  // Telegram Mini App compatibility: expand viewport, tag inquiry/order source
  try {
    if (window.Telegram && Telegram.WebApp && Telegram.WebApp.initData) {
      Telegram.WebApp.ready();
      Telegram.WebApp.expand();
      document.documentElement.classList.add('tg-app');
      document.querySelectorAll('input[name="source"]').forEach(function (el) { el.value = 'telegram_mini_app'; });
    }
  } catch (e) { /* not inside Telegram */ }

  // Silent location detection: no header UI, just ask for GPS permission once per tab
  // session so browse/home content can be narrowed down to the visitor's neighborhood.
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
    }, function () { /* denied/unavailable - keep the IP/default guess */ }, { timeout: 8000, maximumAge: 600000 });
  })();

  // TikTok-style feed: only the slide in view keeps its iframe loaded.
  var feed = document.querySelector('.tiktok-feed');
  if (feed && 'IntersectionObserver' in window) {
    var slides = feed.querySelectorAll('.tiktok-slide');
    var activeVideoSrc = function (src) {
      if (!src) return '';
      try {
        var u = new URL(src, location.href);
        if (/youtube\.com|youtu\.be/.test(u.hostname)) {
          u.searchParams.set('autoplay', '1');
          u.searchParams.set('mute', '1');
          u.searchParams.set('playsinline', '1');
          u.searchParams.set('loop', '1');
          u.searchParams.set('controls', '0');
          u.searchParams.set('modestbranding', '1');
          u.searchParams.set('rel', '0');
          var match = u.pathname.match(/\/embed\/([^/?]+)/);
          if (match && !u.searchParams.get('playlist')) u.searchParams.set('playlist', match[1]);
        } else if (/tiktok\.com/.test(u.hostname)) {
          u.searchParams.set('autoplay', '1');
          u.searchParams.set('muted', '1');
          u.searchParams.set('mute', '1');
          u.searchParams.set('playsinline', '1');
          u.searchParams.set('loop', '1');
          u.searchParams.set('controls', '0');
          u.searchParams.set('hide_controls', '1');
        }
        return u.toString();
      } catch (e) {
        return src;
      }
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

  // register PWA service worker
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register((window.AK_BASE || '/ezihgebeya') + '/sw.js').catch(function () {});
  }
})();
