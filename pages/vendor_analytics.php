<?php
$u = require_vendor();
$biz = my_business($u);
if (!$biz) { flash('Create your business profile first.', 'error'); redirect('vendor/business'); }
$pageTitle = 'Analytics';

$totals = [
    'Product views' => val("SELECT COALESCE(SUM(views_count),0) FROM products WHERE business_id = ?", [$biz['id']]),
    'Service views' => val("SELECT COALESCE(SUM(views_count),0) FROM services WHERE business_id = ?", [$biz['id']]),
    'Supply views' => val("SELECT COALESCE(SUM(views_count),0) FROM supplies WHERE business_id = ?", [$biz['id']]),
    'Video views' => val("SELECT COALESCE(SUM(views_count),0) FROM video_posts WHERE business_id = ?", [$biz['id']]),
    'Video CTA clicks' => val("SELECT COALESCE(SUM(cta_clicks_count),0) FROM video_posts WHERE business_id = ?", [$biz['id']]),
    'Inquiries (30d)' => val("SELECT COUNT(*) FROM inquiries WHERE business_id = ? AND created_at > NOW() - INTERVAL 30 DAY", [$biz['id']]),
    'Orders (30d)' => val("SELECT COUNT(*) FROM orders WHERE business_id = ? AND created_at > NOW() - INTERVAL 30 DAY", [$biz['id']]),
    'Order revenue (30d)' => val("SELECT COALESCE(SUM(total),0) FROM orders WHERE business_id = ? AND status NOT IN ('cancelled','refunded') AND created_at > NOW() - INTERVAL 30 DAY", [$biz['id']]),
];
$totalInq = (int)val("SELECT COUNT(*) FROM inquiries WHERE business_id = ?", [$biz['id']]);
$converted = (int)val("SELECT COUNT(*) FROM inquiries WHERE business_id = ? AND status = 'converted'", [$biz['id']]);

$topProducts = rows("SELECT title, views_count, inquiries_count, favorites_count FROM products
    WHERE business_id = ? AND status = 'active' ORDER BY views_count DESC LIMIT 10", [$biz['id']]);
$bySource = rows("SELECT source, COUNT(*) n FROM inquiries WHERE business_id = ? GROUP BY source ORDER BY n DESC", [$biz['id']]);
$byStatus = rows("SELECT status, COUNT(*) n FROM inquiries WHERE business_id = ? GROUP BY status ORDER BY n DESC", [$biz['id']]);
$topVideos = rows("SELECT v.id, COALESCE(v.title, v.original_url) t, v.views_count, v.cta_clicks_count,
       COALESCE(ev.watch_secs, 0) watch_secs, COALESCE(ev.deep_watches, 0) deep_watches, COALESCE(ev.shares, 0) shares
    FROM video_posts v
    LEFT JOIN (SELECT video_post_id,
                      SUM(CASE WHEN event_type LIKE 'watch%' THEN watched_seconds ELSE 0 END) watch_secs,
                      SUM(event_type = 'watch_50_percent') deep_watches,
                      SUM(event_type = 'share') shares
               FROM video_events GROUP BY video_post_id) ev ON ev.video_post_id = v.id
    WHERE v.business_id = ? AND v.status = 'approved' ORDER BY v.views_count DESC LIMIT 10", [$biz['id']]);
$videoLeads = (int)val("SELECT COUNT(*) FROM inquiries WHERE business_id = ? AND source = 'video_feed'", [$biz['id']]);
include __DIR__ . '/../views/layout_top.php';
?>
<div class="container section dash-layout">
  <?php include __DIR__ . '/../views/vendor_nav.php'; ?>
  <div class="dash-main">
    <h1>📈 Analytics</h1>
    <div class="stat-grid">
      <?php foreach ($totals as $label => $n): ?>
        <div class="stat-card"><div class="stat-num"><?= str_contains($label, 'revenue') ? money($n) ?: '0 ETB' : number_format((float)$n) ?></div><div class="stat-label"><?= $label ?></div></div>
      <?php endforeach; ?>
      <div class="stat-card"><div class="stat-num"><?= $totalInq ? round($converted / $totalInq * 100) . '%' : '—' ?></div><div class="stat-label">Lead conversion</div></div>
    </div>

    <div class="panel">
      <h3>Top products by views</h3>
      <?php if (!$topProducts): ?><p class="muted">No active products.</p><?php endif; ?>
      <div class="table-wrap"><table class="data-table">
        <?php if ($topProducts): ?><tr><th>Product</th><th>Views</th><th>Inquiries</th><th>Saves</th></tr><?php endif; ?>
        <?php foreach ($topProducts as $p): ?>
          <tr><td><?= e($p['title']) ?></td><td><?= (int)$p['views_count'] ?></td><td><?= (int)$p['inquiries_count'] ?></td><td><?= (int)$p['favorites_count'] ?></td></tr>
        <?php endforeach; ?>
      </table></div>
    </div>

    <div class="panel">
      <h3>Leads by source (§30.11 — track every lead source)</h3>
      <?php if (!$bySource): ?><p class="muted">No inquiries yet.</p><?php endif; ?>
      <?php foreach ($bySource as $r): ?><div class="bar-row"><span><?= e(str_replace('_', ' ', $r['source'])) ?></span><b><?= $r['n'] ?></b></div><?php endforeach; ?>
    </div>

    <div class="panel">
      <h3>Leads by status</h3>
      <?php foreach ($byStatus as $r): ?><div class="bar-row"><span><?= e($r['status']) ?></span><b><?= $r['n'] ?></b></div><?php endforeach; ?>
    </div>

    <?php if ($topVideos): ?>
    <div class="panel">
      <h3>Video performance (§6.6)</h3>
      <p class="muted small">Inquiries from the video feed: <b><?= $videoLeads ?></b>. Watch time is measured from time on screen (embeds don't expose exact playback).</p>
      <div class="table-wrap"><table class="data-table">
        <tr><th>Video</th><th>Views</th><th>Watch time</th><th>Deep watches</th><th>Shares</th><th>CTA clicks</th><th>CTR</th></tr>
        <?php foreach ($topVideos as $v): ?>
          <tr>
            <td class="truncate"><?= e(mb_substr($v['t'], 0, 60)) ?></td>
            <td><?= (int)$v['views_count'] ?></td>
            <td><?= $v['watch_secs'] >= 60 ? round($v['watch_secs'] / 60) . ' min' : (int)$v['watch_secs'] . 's' ?></td>
            <td><?= (int)$v['deep_watches'] ?></td>
            <td><?= (int)$v['shares'] ?></td>
            <td><?= (int)$v['cta_clicks_count'] ?></td>
            <td><?= $v['views_count'] > 0 ? round($v['cta_clicks_count'] / $v['views_count'] * 100, 1) . '%' : '—' ?></td>
          </tr>
        <?php endforeach; ?>
      </table></div>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php include __DIR__ . '/../views/layout_bottom.php'; ?>
