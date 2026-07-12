<?php
$u = require_vendor();
$biz = my_business($u);
if (!$biz) { flash('Create your business profile first.', 'error'); redirect('vendor/business'); }
$pageTitle = 'Analytics';

$eventTable = db_table_exists('events');

function vendor_event_count(int $businessId, string $eventType, int $days = 30, ?string $listingType = null, ?int $listingId = null, ?string $source = null): int {
    if (!db_table_exists('events')) return 0;
    $where = ["business_id = ?", "event_type = ?", "created_at > NOW() - INTERVAL {$days} DAY"];
    $params = [$businessId, $eventType];
    if ($listingType !== null) { $where[] = "listing_type = ?"; $params[] = $listingType; }
    if ($listingId !== null) { $where[] = "listing_id = ?"; $params[] = $listingId; }
    if ($source !== null) { $where[] = "source = ?"; $params[] = $source; }
    return (int)val("SELECT COUNT(*) FROM events WHERE " . implode(' AND ', $where), $params);
}

function vendor_dropoff_label(int $from, int $to): string {
    if ($from <= 0) return '—';
    return round(max(0, ($from - $to) / $from) * 100) . '% drop';
}

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
$completedOrders30 = (int)val("SELECT COUNT(*) FROM orders WHERE business_id = ? AND status = 'completed' AND created_at > NOW() - INTERVAL 30 DAY", [$biz['id']]);
$orderRevenue30 = (float)val("SELECT COALESCE(SUM(total),0) FROM orders WHERE business_id = ? AND status NOT IN ('cancelled','refunded') AND created_at > NOW() - INTERVAL 30 DAY", [$biz['id']]);
$orders30 = (int)$totals['Orders (30d)'];
$aov30 = $orders30 ? $orderRevenue30 / $orders30 : 0;
$promoSpend30 = (float)val("SELECT COALESCE(SUM(COALESCE(spent, budget, 0)),0) FROM promotions WHERE business_id = ? AND created_at > NOW() - INTERVAL 30 DAY AND status IN ('active','completed','scheduled','paused')", [$biz['id']]);
$promotedInquiries30 = (int)val("SELECT COUNT(*) FROM inquiries WHERE business_id = ? AND traffic_source IN ('promoted','ad') AND created_at > NOW() - INTERVAL 30 DAY", [$biz['id']]);
$promotedOrders30 = db_column_exists('orders', 'traffic_source')
    ? (int)val("SELECT COUNT(*) FROM orders WHERE business_id = ? AND traffic_source IN ('promoted','ad') AND created_at > NOW() - INTERVAL 30 DAY", [$biz['id']])
    : 0;

$funnel = [
    'Views' => vendor_event_count((int)$biz['id'], 'view', 30),
    'Favorites' => vendor_event_count((int)$biz['id'], 'favorite', 30),
    'Inquiries' => vendor_event_count((int)$biz['id'], 'inquiry', 30) ?: (int)$totals['Inquiries (30d)'],
    'Orders' => vendor_event_count((int)$biz['id'], 'order', 30) ?: $orders30,
    'Completed orders' => $completedOrders30,
];

$listingRows = rows("
    SELECT * FROM (
      SELECT 'product' listing_type, id, title, status, views_count, favorites_count, inquiries_count, created_at FROM products WHERE business_id = ? AND status != 'deleted'
      UNION ALL
      SELECT 'service' listing_type, id, title, status, views_count, 0 favorites_count, inquiries_count, created_at FROM services WHERE business_id = ? AND status != 'deleted'
      UNION ALL
      SELECT 'supply' listing_type, id, name title, status, views_count, 0 favorites_count, inquiries_count, created_at FROM supplies WHERE business_id = ? AND status != 'deleted'
    ) listings
    ORDER BY views_count DESC, inquiries_count DESC
    LIMIT 25", [$biz['id'], $biz['id'], $biz['id']]);

$listingAnalytics = [];
foreach ($listingRows as $l) {
    $type = $l['listing_type'];
    $id = (int)$l['id'];
    $views30 = vendor_event_count((int)$biz['id'], 'view', 30, $type, $id) ?: (int)$l['views_count'];
    $favorites30 = $type === 'product' ? (vendor_event_count((int)$biz['id'], 'favorite', 30, $type, $id) ?: (int)$l['favorites_count']) : 0;
    $inquiries30 = (int)val("SELECT COUNT(*) FROM inquiries WHERE business_id = ? AND listing_type = ? AND listing_id = ? AND created_at > NOW() - INTERVAL 30 DAY", [$biz['id'], $type, $id]);
    $ordersForListing30 = in_array($type, ['product', 'supply'], true)
        ? (int)val("SELECT COUNT(DISTINCT o.id) FROM orders o JOIN order_items oi ON oi.order_id = o.id WHERE o.business_id = ? AND oi.listing_type = ? AND oi.listing_id = ? AND o.created_at > NOW() - INTERVAL 30 DAY", [$biz['id'], $type, $id])
        : 0;
    $completedForListing30 = in_array($type, ['product', 'supply'], true)
        ? (int)val("SELECT COUNT(DISTINCT o.id) FROM orders o JOIN order_items oi ON oi.order_id = o.id WHERE o.business_id = ? AND oi.listing_type = ? AND oi.listing_id = ? AND o.status = 'completed' AND o.created_at > NOW() - INTERVAL 30 DAY", [$biz['id'], $type, $id])
        : 0;
    $revenue30 = in_array($type, ['product', 'supply'], true)
        ? (float)val("SELECT COALESCE(SUM(oi.line_total),0) FROM orders o JOIN order_items oi ON oi.order_id = o.id WHERE o.business_id = ? AND oi.listing_type = ? AND oi.listing_id = ? AND o.status NOT IN ('cancelled','refunded') AND o.created_at > NOW() - INTERVAL 30 DAY", [$biz['id'], $type, $id])
        : 0.0;
    $views7 = vendor_event_count((int)$biz['id'], 'view', 7, $type, $id);
    $inquiries7 = (int)val("SELECT COUNT(*) FROM inquiries WHERE business_id = ? AND listing_type = ? AND listing_id = ? AND created_at > NOW() - INTERVAL 7 DAY", [$biz['id'], $type, $id]);
    $listingAnalytics[] = $l + [
        'views30' => $views30,
        'favorites30' => $favorites30,
        'inquiries30' => $inquiries30,
        'orders30' => $ordersForListing30,
        'completed30' => $completedForListing30,
        'revenue30' => $revenue30,
        'views7' => $views7,
        'inquiries7' => $inquiries7,
    ];
}

$topProducts = rows("SELECT title, views_count, inquiries_count, favorites_count FROM products
    WHERE business_id = ? AND status = 'active' ORDER BY views_count DESC LIMIT 10", [$biz['id']]);
$bySource = rows("SELECT source, COUNT(*) n FROM inquiries WHERE business_id = ? GROUP BY source ORDER BY n DESC", [$biz['id']]);
$byStatus = rows("SELECT status, COUNT(*) n FROM inquiries WHERE business_id = ? GROUP BY status ORDER BY n DESC", [$biz['id']]);
$revenueByListing = rows("SELECT oi.listing_type, oi.listing_id, MAX(oi.title) title, COUNT(DISTINCT o.id) orders_count, SUM(oi.line_total) revenue
    FROM order_items oi
    JOIN orders o ON o.id = oi.order_id
    WHERE o.business_id = ? AND o.status NOT IN ('cancelled','refunded') AND o.created_at > NOW() - INTERVAL 30 DAY
    GROUP BY oi.listing_type, oi.listing_id
    ORDER BY revenue DESC
    LIMIT 10", [$biz['id']]);
$reviewSummary = row("SELECT COUNT(*) total_reviews, AVG(rating) avg_rating,
        AVG(CASE WHEN created_at > NOW() - INTERVAL 30 DAY THEN rating END) avg_rating_30d,
        SUM(created_at > NOW() - INTERVAL 30 DAY) reviews_30d
    FROM reviews WHERE business_id = ? AND status = 'approved'", [$biz['id']]) ?: [];
$ratingTrend = rows("SELECT DATE_FORMAT(created_at, '%Y-%m') month, COUNT(*) n, AVG(rating) avg_rating
    FROM reviews
    WHERE business_id = ? AND status = 'approved' AND created_at > DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY DATE_FORMAT(created_at, '%Y-%m')
    ORDER BY month", [$biz['id']]);
$responseRows = db_table_exists('inquiry_messages') ? rows(
    "SELECT TIMESTAMPDIFF(MINUTE, i.created_at, MIN(m.created_at)) mins
     FROM inquiries i
     JOIN inquiry_messages m ON m.inquiry_id = i.id AND m.sender_id = ? AND m.created_at >= i.created_at
     WHERE i.business_id = ? AND i.created_at > NOW() - INTERVAL 90 DAY
     GROUP BY i.id
     HAVING mins IS NOT NULL",
    [$biz['user_id'], $biz['id']]
) : [];
$medianResponseMins = median(array_map(fn($r) => max(0, (int)$r['mins']), $responseRows));
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
      <h3>30-day funnel</h3>
      <p class="muted small">Views to favorites to inquiries to orders to completed orders, using the shared analytics events with order-table fallback.</p>
      <div class="table-wrap"><table class="data-table">
        <tr><th>Step</th><th>Count</th><th>Drop-off from previous step</th></tr>
        <?php $prev = null; foreach ($funnel as $label => $count): ?>
          <tr>
            <td><?= e($label) ?></td>
            <td><?= number_format((int)$count) ?></td>
            <td><?= $prev === null ? '—' : vendor_dropoff_label((int)$prev, (int)$count) ?></td>
          </tr>
        <?php $prev = (int)$count; endforeach; ?>
      </table></div>
    </div>

    <div class="panel">
      <h3>Money metrics, last 30 days</h3>
      <div class="stat-grid">
        <div class="stat-card"><div class="stat-num"><?= money($orderRevenue30) ?></div><div class="stat-label">Order value</div></div>
        <div class="stat-card"><div class="stat-num"><?= money($aov30) ?></div><div class="stat-label">Average order value</div></div>
        <div class="stat-card"><div class="stat-num"><?= money($promoSpend30) ?></div><div class="stat-label">Promotion spend</div></div>
        <div class="stat-card"><div class="stat-num"><?= number_format($promotedInquiries30) ?> / <?= number_format($promotedOrders30) ?></div><div class="stat-label">Attributed inquiries / orders</div></div>
      </div>
      <?php if ($revenueByListing): ?>
        <h4>Revenue by listing</h4>
        <div class="table-wrap"><table class="data-table">
          <tr><th>Listing</th><th>Type</th><th>Orders</th><th>Revenue</th></tr>
          <?php foreach ($revenueByListing as $r): ?>
            <tr>
              <td><?= e($r['title']) ?></td>
              <td><?= e($r['listing_type']) ?></td>
              <td><?= number_format((int)$r['orders_count']) ?></td>
              <td><?= money((float)$r['revenue']) ?></td>
            </tr>
          <?php endforeach; ?>
        </table></div>
      <?php else: ?>
        <p class="muted">No listing-level order revenue in the last 30 days yet.</p>
      <?php endif; ?>
    </div>

    <div class="panel">
      <h3>Per-listing drill-down</h3>
      <p class="muted small">Shows each listing's own trend instead of only a top-products table.</p>
      <?php if (!$listingAnalytics): ?><p class="muted">No listings yet.</p><?php endif; ?>
      <?php if ($listingAnalytics): ?>
      <div class="table-wrap"><table class="data-table">
        <tr><th>Listing</th><th>Status</th><th>30d views</th><th>7d views</th><th>30d saves</th><th>30d inquiries</th><th>7d inquiries</th><th>Orders</th><th>Completed</th><th>Revenue</th></tr>
        <?php foreach ($listingAnalytics as $l): ?>
          <tr>
            <td><span class="badge"><?= e($l['listing_type']) ?></span> <?= e(mb_substr($l['title'], 0, 70)) ?></td>
            <td><?= e($l['status']) ?></td>
            <td><?= number_format((int)$l['views30']) ?></td>
            <td><?= number_format((int)$l['views7']) ?></td>
            <td><?= number_format((int)$l['favorites30']) ?></td>
            <td><?= number_format((int)$l['inquiries30']) ?></td>
            <td><?= number_format((int)$l['inquiries7']) ?></td>
            <td><?= number_format((int)$l['orders30']) ?></td>
            <td><?= number_format((int)$l['completed30']) ?></td>
            <td><?= money((float)$l['revenue30']) ?></td>
          </tr>
        <?php endforeach; ?>
      </table></div>
      <?php endif; ?>
    </div>

    <div class="panel">
      <h3>Reviews and response metrics</h3>
      <div class="stat-grid">
        <div class="stat-card"><div class="stat-num"><?= !empty($reviewSummary['avg_rating']) ? number_format((float)$reviewSummary['avg_rating'], 1) . '/5' : '—' ?></div><div class="stat-label">Average rating</div></div>
        <div class="stat-card"><div class="stat-num"><?= number_format((int)($reviewSummary['reviews_30d'] ?? 0)) ?></div><div class="stat-label">Reviews, 30d</div></div>
        <div class="stat-card"><div class="stat-num"><?= !empty($reviewSummary['avg_rating_30d']) ? number_format((float)$reviewSummary['avg_rating_30d'], 1) . '/5' : '—' ?></div><div class="stat-label">Rating trend, 30d</div></div>
        <div class="stat-card"><div class="stat-num"><?= response_time_label($medianResponseMins) ?: '—' ?></div><div class="stat-label">Median inquiry response</div></div>
      </div>
      <?php if ($ratingTrend): ?>
        <h4>Rating trend</h4>
        <?php foreach ($ratingTrend as $r): ?>
          <div class="bar-row"><span><?= e($r['month']) ?></span><b><?= number_format((float)$r['avg_rating'], 1) ?>/5 · <?= number_format((int)$r['n']) ?> review<?= (int)$r['n'] === 1 ? '' : 's' ?></b></div>
        <?php endforeach; ?>
      <?php else: ?>
        <p class="muted">No approved review trend yet.</p>
      <?php endif; ?>
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
