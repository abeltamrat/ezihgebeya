<?php
/** Business profile page. Expects $slug */
$biz = row("SELECT * FROM businesses WHERE slug = ? AND status = 'active'", [$slug]);
if (!$biz) { http_response_code(404); $pageTitle = 'Not found'; include __DIR__ . '/../views/layout_top.php';
    echo '<div class="container section"><div class="empty-state">Business not found or pending approval.</div></div>';
    include __DIR__ . '/../views/layout_bottom.php'; return; }

$pageTitle = $biz['business_name'];
$pageDesc = mb_substr(strip_tags($biz['description'] ?? ''), 0, 150);
$canonical = 'businesses/' . $biz['slug'];
$ogType = 'business.business';

$sel = fn($t) => rows("SELECT l.*, b.business_name b_name, b.verification_status b_verification, c.name c_name, c.icon c_icon
    FROM $t l JOIN businesses b ON b.id = l.business_id JOIN categories c ON c.id = l.category_id
    WHERE l.business_id = ? AND l.status = 'active' ORDER BY l.created_at DESC LIMIT 24", [$biz['id']]);
$products = $sel('products');
$services = $sel('services');
$supplies = $sel('supplies');
$videos = rows("SELECT * FROM video_posts WHERE business_id = ? AND status = 'approved' ORDER BY created_at DESC LIMIT 6", [$biz['id']]);
$bizReviews = rows("SELECT r.*, u.full_name FROM reviews r JOIN users u ON u.id = r.reviewer_id
    WHERE r.business_id = ? AND r.status = 'approved' ORDER BY r.created_at DESC LIMIT 10", [$biz['id']]);
$responseLabel = response_time_label(business_response_median_minutes((int)$biz['id']));
$activityLabel = business_recent_activity_label((int)$biz['id']);
$localBusinessJsonLd = [
    '@type' => 'LocalBusiness',
    '@id' => absolute_url(url('businesses/' . $biz['slug'])) . '#business',
    'name' => $biz['business_name'],
    'description' => mb_substr(strip_tags($biz['description'] ?? ''), 0, 300),
    'url' => absolute_url(url('businesses/' . $biz['slug'])),
    'telephone' => $biz['phone'] ?: null,
    'email' => $biz['email'] ?: null,
    'image' => $biz['logo'] ? absolute_url(img_url($biz['logo'])) : null,
    'address' => [
        '@type' => 'PostalAddress',
        'streetAddress' => trim(implode(', ', array_filter([$biz['area_name'], $biz['address']]))),
        'addressLocality' => $biz['subcity'] ?: $biz['city'],
        'addressRegion' => $biz['city'],
        'addressCountry' => 'ET',
    ],
];
if ($biz['latitude'] && $biz['longitude']) {
    $localBusinessJsonLd['geo'] = ['@type' => 'GeoCoordinates', 'latitude' => (float)$biz['latitude'], 'longitude' => (float)$biz['longitude']];
}
if ((float)$biz['rating_average'] > 0) {
    $localBusinessJsonLd['aggregateRating'] = [
        '@type' => 'AggregateRating',
        'ratingValue' => (float)$biz['rating_average'],
        'reviewCount' => max(1, (int)$biz['rating_count']),
    ];
}
$breadcrumbJsonLd = [
    '@type' => 'BreadcrumbList',
    'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => absolute_url(url(''))],
        ['@type' => 'ListItem', 'position' => 2, 'name' => 'Businesses', 'item' => absolute_url(url('businesses/' . $biz['slug']))],
        ['@type' => 'ListItem', 'position' => 3, 'name' => $biz['business_name'], 'item' => absolute_url(url('businesses/' . $biz['slug']))],
    ],
];
$jsonLd = ['@context' => 'https://schema.org', '@graph' => [array_filter($localBusinessJsonLd, fn($v) => $v !== null && $v !== ''), $breadcrumbJsonLd]];
$ogImage = $biz['logo'] ? absolute_url(img_url($biz['logo'])) : ($biz['cover_image'] ? absolute_url(img_url($biz['cover_image'])) : null);
$u = auth();
include __DIR__ . '/../views/layout_top.php';
?>
<div class="biz-cover" <?= $biz['cover_image'] ? 'style="background-image:url(' . e(img_url($biz['cover_image'])) . ')"' : '' ?>></div>
<div class="container section">
  <div class="biz-head">
    <div class="biz-logo"><?php if ($biz['logo']): ?><img src="<?= e(img_url($biz['logo'])) ?>" alt=""><?php else: ?>🏪<?php endif; ?></div>
    <div>
      <h1><?= e($biz['business_name']) ?> <?= verified_badge($biz['verification_status']) ?></h1>
      <div class="muted">📍 <?= e(implode(', ', array_filter([$biz['area_name'], $biz['subcity'], $biz['city']]))) ?>
        · <?= ucfirst(str_replace('_', ' ', $biz['business_type'])) ?> · joined <?= date('M Y', strtotime($biz['created_at'])) ?></div>
      <div><?= star_rating($biz['rating_average'], (int)$biz['rating_count']) ?>
        <?php $rr = business_response_rate((int)$biz['id']); if ($rr !== null): ?>
          <span class="badge badge-muted" title="Share of inquiries this seller responds to">💬 responds to <?= $rr ?>%</span>
        <?php endif; ?>
        <?php if ($responseLabel): ?><span class="badge badge-muted"><?= e($responseLabel) ?></span><?php endif; ?>
        <?php if ($activityLabel): ?><span class="badge badge-muted"><?= e($activityLabel) ?></span><?php endif; ?>
      </div>
    </div>
    <div class="biz-actions">
      <?php if ($biz['phone']): ?><a class="btn btn-primary" href="tel:<?= e($biz['phone']) ?>">📞 Call</a><?php endif; ?>
    </div>
  </div>
  <?php if ($biz['description']): ?><p class="biz-desc"><?= nl2br(e($biz['description'])) ?></p><?php endif; ?>

  <?php foreach ([['product', 'Products', $products], ['service', 'Services', $services], ['supply', 'Supplies', $supplies]] as [$t, $label, $list]): if ($list): ?>
    <h2 class="section-gap"><?= $label ?> (<?= count($list) ?>)</h2>
    <div class="grid">
      <?php foreach ($list as $item) { $cardType = $t; include __DIR__ . '/../views/partial_card.php'; } ?>
    </div>
  <?php endif; endforeach; ?>

  <?php if ($videos): ?>
    <h2 class="section-gap">Videos</h2>
    <div class="detail-videos">
      <?php foreach ($videos as $v): ?><div class="video-wrap-sm"><?= video_embed_html($v) ?></div><?php endforeach; ?>
    </div>
  <?php endif; ?>

  <h2 class="section-gap">Customer reviews</h2>
  <?php if (!$bizReviews): ?><p class="muted">No reviews yet.</p><?php endif; ?>
  <?php foreach ($bizReviews as $r): ?>
    <div class="review">
      <div class="review-head">
        <strong><?= e($r['full_name']) ?></strong>
        <span class="stars"><?= str_repeat('★', (int)$r['rating']) ?></span>
        <?php if ($r['is_verified_purchase']): ?><span class="badge badge-verified">✔ Verified purchase</span>
        <?php else: ?><span class="badge badge-muted" title="Review after inquiry, not a tracked order">unverified purchase</span><?php endif; ?>
        <span class="muted"><?= time_ago($r['created_at']) ?></span>
      </div>
      <p><?= nl2br(e($r['comment'])) ?></p>
      <?php $rImgs = $r['images'] ? (json_decode($r['images'], true) ?: []) : []; ?>
      <?php if ($rImgs): ?>
        <div class="btn-row">
          <?php foreach ($rImgs as $ri): ?>
            <a href="<?= e(img_url($ri)) ?>" target="_blank"><img src="<?= e(img_url($ri)) ?>" alt="review photo" style="width:72px;height:72px;object-fit:cover;border-radius:8px"></a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <?php if ($r['vendor_reply']): ?>
        <div class="panel" style="margin:8px 0 0 20px;background:var(--brand-soft)">
          <div class="review-head"><strong>Reply from <?= e($biz['business_name']) ?></strong><span class="muted"><?= time_ago($r['vendor_replied_at']) ?></span></div>
          <p><?= nl2br(e($r['vendor_reply'])) ?></p>
        </div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>

  <?php if ($u && feature_enabled('reviews')): ?>
  <form class="panel" method="post" action="<?= url('review') ?>" enctype="multipart/form-data" style="max-width:480px">
    <?= csrf_field() ?>
    <input type="hidden" name="listing_type" value="business">
    <input type="hidden" name="business_id" value="<?= $biz['id'] ?>">
    <h4>Review this business</h4>
    <label>Rating
      <select name="rating" required>
        <?php for ($i = 5; $i >= 1; $i--): ?><option value="<?= $i ?>"><?= str_repeat('★', $i) ?></option><?php endfor; ?>
      </select>
    </label>
    <label>Comment <textarea name="comment" rows="3" required></textarea></label>
    <label>Photos (optional, up to 3) <input type="file" name="images[]" accept="image/*" multiple></label>
    <button class="btn btn-primary">Submit</button>
  </form>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/../views/layout_bottom.php'; ?>
