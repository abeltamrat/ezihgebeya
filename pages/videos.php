<?php
/** Shoppable video feed ("Watch & Buy") */
$pageTitle = 'Video Feed — Watch & Buy';
$loc = user_location();
$city = array_key_exists('city', $_GET) ? $_GET['city'] : $loc['city'];
$where = "v.status = 'approved' AND b.status = 'active'";
$params = [];
if ($city) { $where .= " AND v.city = ?"; $params[] = $city; }

// §6.5 Video Score — weights tunable in admin → Settings → ranking
$W = fn(string $k) => (float)sys("video_ranking.$k");
$score = "((v.city <=> ?) * {$W('city')} + (v.subcity <=> ?) * {$W('subcity')}"
    . " + LEAST(15, LOG(1 + v.views_count + v.cta_clicks_count * 5) * {$W('engagement')})"
    . " + (b.verification_status != 'unverified') * {$W('verification')}"
    . " + GREATEST(0, {$W('freshness')} - DATEDIFF(NOW(), v.created_at) / 3)"
    . " + LEAST(10, b.rating_average * {$W('rating')})"
    . " + v.is_promoted * {$W('promoted')} + v.is_featured * {$W('featured')}"
    . " - v.reports_count * {$W('report_penalty')})";
$feedSize = (int)sys('limits.video_feed_size', 50);
$videos = rows("SELECT v.*, b.business_name b_name, b.slug b_slug, b.verification_status b_verification, b.rating_average b_rating
    FROM video_posts v JOIN businesses b ON b.id = v.business_id
    WHERE $where
    ORDER BY $score DESC, v.created_at DESC LIMIT $feedSize", array_merge([$loc['city'], $loc['subcity']], $params));

// resolve linked listing (title, price, url) per video
foreach ($videos as &$v) {
    $v['link_href'] = url('businesses/' . $v['b_slug']);
    $v['link_title'] = $v['title'] ?: $v['b_name'];
    $v['link_price'] = '';
    if ($v['linked_type'] === 'product' && $v['linked_id']) {
        $l = row("SELECT title, slug, price, discount_price FROM products WHERE id = ? AND status = 'active'", [$v['linked_id']]);
        if ($l) { $v['link_href'] = url('products/' . $l['slug']); $v['link_title'] = $l['title']; $v['link_price'] = money($l['discount_price'] > 0 ? $l['discount_price'] : $l['price']); }
    } elseif ($v['linked_type'] === 'service' && $v['linked_id']) {
        $l = row("SELECT title, slug, starting_price, price_type FROM services WHERE id = ? AND status = 'active'", [$v['linked_id']]);
        if ($l) { $v['link_href'] = url('services/' . $l['slug']); $v['link_title'] = $l['title']; $v['link_price'] = $l['price_type'] === 'quote_required' ? 'Request Quote' : money($l['starting_price']); }
    } elseif ($v['linked_type'] === 'supply' && $v['linked_id']) {
        $l = row("SELECT name, slug, price_per_unit, unit_of_measurement FROM supplies WHERE id = ? AND status = 'active'", [$v['linked_id']]);
        if ($l) { $v['link_href'] = url('supplies/' . $l['slug']); $v['link_title'] = $l['name']; $v['link_price'] = money($l['price_per_unit']) . ($l['price_per_unit'] > 0 ? '/' . $l['unit_of_measurement'] : ''); }
    }
    // views are counted by the feed JS via /videos/event when a slide is actually watched (§6.6)
}
unset($v);
include __DIR__ . '/../views/layout_top.php';
?>
<div class="container tiktok-toolbar">
  <h1>▶ Watch & Buy</h1>
  <form method="get">
    <select name="city" onchange="this.form.submit()">
      <option value="">All cities</option>
      <?php foreach (array_keys(CITIES) as $c): ?><option <?= $city === $c ? 'selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?>
    </select>
  </form>
</div>

<?php if (!$videos): ?>
  <div class="container"><div class="empty-state">No videos yet<?= $city ? ' for ' . e($city) : '' ?>. Vendors: add your TikTok/YouTube links from the dashboard!</div></div>
<?php else: ?>
  <div class="tiktok-feed">
    <?php $adSlide = ad_slot('video_slide', ['city' => $city ?: null]); ?>
    <?php foreach ($videos as $vIdx => $v): ?>
    <?php if ($vIdx === 1 && $adSlide) { echo $adSlide; $adSlide = ''; } ?>
    <section class="tiktok-slide" data-video-id="<?= (int)$v['id'] ?>">
      <div class="tiktok-media"><?= video_embed_html($v) ?></div>
      <div class="tiktok-scrim"></div>
      <div class="tiktok-rail">
        <button type="button" class="rail-btn" title="Share" onclick="arkoVideoEvent(<?= (int)$v['id'] ?>, 'share');arkoShare('<?= e($v['link_title']) ?>', '<?= e($v['link_href']) ?>')">🔗</button>
        <a class="rail-btn" title="Visit shop" onclick="arkoVideoEvent(<?= (int)$v['id'] ?>, 'profile_click')" href="<?= url('businesses/' . e($v['b_slug'])) ?>">🏪</a>
        <form method="post" action="<?= url('report') ?>" onsubmit="return confirm('Report this video?')">
          <?= csrf_field() ?>
          <input type="hidden" name="reported_type" value="video">
          <input type="hidden" name="reported_id" value="<?= $v['id'] ?>">
          <input type="hidden" name="reason" value="Reported from video feed">
          <button type="submit" class="rail-btn" title="Report">🚩</button>
        </form>
      </div>
      <div class="tiktok-info">
        <div class="video-vendor">
          <a href="<?= url('businesses/' . e($v['b_slug'])) ?>"><strong><?= e($v['b_name']) ?></strong></a>
          <?= verified_badge($v['b_verification']) ?>
          <?php if ((float)$v['b_rating'] > 0): ?><span class="stars">★ <?= number_format($v['b_rating'], 1) ?></span><?php endif; ?>
        </div>
        <h3 class="video-title"><?= e($v['link_title']) ?></h3>
        <div class="video-meta">
          <?php if ($v['link_price']): ?><span class="price"><?= e($v['link_price']) ?></span><?php endif; ?>
          <?php if ($v['city']): ?><span class="muted">📍 <?= e(($v['subcity'] ? $v['subcity'] . ', ' : '') . $v['city']) ?></span><?php endif; ?>
        </div>
        <div class="video-actions">
          <a class="btn btn-primary" href="<?= url('videos/cta/' . $v['id']) ?>"><?= e($v['cta_label'] ?: 'Check Now') ?></a>
        </div>
        <div class="muted small">👁 <?= (int)$v['views_count'] ?> · <?= time_ago($v['created_at']) ?></div>
      </div>
    </section>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<script>function arkoShare(title, path) {
  var url = location.origin + path;
  if (navigator.share) { navigator.share({ title: title, url: url }).catch(function () {}); }
  else { navigator.clipboard && navigator.clipboard.writeText(url); alert('Link copied:\n' + url); }
}

// §6.6 engagement events: view when a slide fills the screen, watch milestones by
// on-screen time (official embeds expose no playback API, so visible-time is the proxy).
function arkoVideoEvent(id, event, watched) {
  var body = new FormData();
  body.append('video_id', id);
  body.append('event', event);
  body.append('watched', watched || 0);
  body.append('_token', document.querySelector('meta[name="csrf-token"]').content);
  (navigator.sendBeacon && navigator.sendBeacon('<?= url('videos/event') ?>', body))
    || fetch('<?= url('videos/event') ?>', { method: 'POST', body: body, keepalive: true });
}
(function () {
  if (!('IntersectionObserver' in window)) return;
  var timers = {};
  var milestones = [[3, 'watch_3s'], [10, 'watch_10s'], [15, 'watch_25_percent'], [30, 'watch_50_percent'], [45, 'watch_75_percent'], [60, 'watch_complete']];
  var io = new IntersectionObserver(function (entries) {
    entries.forEach(function (en) {
      var id = en.target.dataset.videoId;
      if (!id) return;
      if (en.isIntersecting) {
        en.target.classList.add('is-active');
        arkoVideoEvent(id, 'view');
        var start = Date.now();
        timers[id] = setInterval(function () {
          var secs = Math.round((Date.now() - start) / 1000);
          milestones.forEach(function (m) {
            if (secs >= m[0] && !en.target.dataset['sent' + m[0]]) {
              en.target.dataset['sent' + m[0]] = '1';
              arkoVideoEvent(id, m[1], secs);
            }
          });
          if (secs > 65) { clearInterval(timers[id]); delete timers[id]; }
        }, 1000);
      } else if (timers[id]) {
        en.target.classList.remove('is-active');
        clearInterval(timers[id]);
        delete timers[id];
      } else {
        en.target.classList.remove('is-active');
      }
    });
  }, { threshold: 0.6 });
  document.querySelectorAll('.tiktok-slide[data-video-id]').forEach(function (s) { io.observe(s); });
})();
</script>
<?php include __DIR__ . '/../views/layout_bottom.php'; ?>
