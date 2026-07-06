<?php
/** Listing detail page. Expects $type, $slug */
$table = LISTING_TABLES[$type];
$titleCol = listing_title_col($type);

$item = row("SELECT l.*, b.business_name b_name, b.slug b_slug, b.verification_status b_verification, b.phone b_phone,
        b.rating_average b_rating, b.rating_count b_rating_count, b.city b_city, b.created_at b_joined,
        c.name c_name, c.icon c_icon
    FROM `$table` l JOIN businesses b ON b.id = l.business_id JOIN categories c ON c.id = l.category_id
    WHERE l.slug = ? AND l.status = 'active' AND b.status = 'active'", [$slug]);

if (!$item) { http_response_code(404); $pageTitle = 'Not found'; include __DIR__ . '/../views/layout_top.php';
    echo '<div class="container section"><div class="empty-state">Listing not found or not yet approved.</div></div>';
    include __DIR__ . '/../views/layout_bottom.php'; return; }

q("UPDATE `$table` SET views_count = views_count + 1 WHERE id = ?", [$item['id']]);
$title = $item[$titleCol];
$pageTitle = $title;
$pageDesc = mb_substr(strip_tags($item['description'] ?? ''), 0, 150);

$allMedia = $type === 'product'
    ? rows("SELECT * FROM product_media WHERE product_id = ? ORDER BY is_primary DESC, sort_order", [$item['id']])
    : (($item['image'] ?? null) ? [['file_url' => $item['image'], 'media_type' => 'image']] : []);
$media = array_values(array_filter($allMedia, fn($m) => ($m['media_type'] ?? 'image') === 'image'));
$glb  = current(array_filter($allMedia, fn($m) => ($m['media_type'] ?? '') === 'model_3d_glb')) ?: null;
$usdz = current(array_filter($allMedia, fn($m) => ($m['media_type'] ?? '') === 'model_3d_usdz')) ?: null;

// SEO structured data (§25.3)
$curPrice = $type === 'product' ? ($item['discount_price'] > 0 ? $item['discount_price'] : $item['price'])
    : ($type === 'supply' ? $item['price_per_unit'] : ($item['starting_price'] ?? null));
$jsonLd = [
    '@context' => 'https://schema.org',
    '@type' => $type === 'service' ? 'Service' : 'Product',
    'name' => $title,
    'description' => mb_substr(strip_tags($item['description'] ?? ''), 0, 300),
];
if ($curPrice > 0) $jsonLd['offers'] = ['@type' => 'Offer', 'price' => (float)$curPrice, 'priceCurrency' => 'ETB',
    'availability' => 'https://schema.org/InStock', 'seller' => ['@type' => 'Organization', 'name' => $item['b_name']]];
if ((float)$item['b_rating'] > 0) $jsonLd['aggregateRating'] = ['@type' => 'AggregateRating',
    'ratingValue' => (float)$item['b_rating'], 'reviewCount' => max(1, (int)$item['b_rating_count'])];
if ($media) $ogImage = 'http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . img_url($media[0]['file_url']);

$reviews = rows("SELECT r.*, u.full_name FROM reviews r JOIN users u ON u.id = r.reviewer_id
    WHERE r.listing_type = ? AND r.listing_id = ? AND r.status = 'approved' ORDER BY r.created_at DESC LIMIT 20", [$type, $item['id']]);

$videos = rows("SELECT * FROM video_posts WHERE linked_type = ? AND linked_id = ? AND status = 'approved'", [$type, $item['id']]);

$u = auth();
$isFav = $u && $type === 'product' && val("SELECT COUNT(*) FROM favorites WHERE user_id = ? AND product_id = ?", [$u['id'], $item['id']]);

$inquiryType = ['product' => 'product_inquiry', 'service' => 'service_quote_request', 'supply' => 'supply_order_request'][$type];
$specs = [];
if ($type === 'product') {
    $specs = ['Type' => PRODUCT_TYPES[$item['product_type']] ?? $item['product_type'], 'Condition' => $item['condition_type'],
        'Material' => $item['material'], 'Brand' => $item['brand'], 'Color' => $item['color'], 'Dimensions' => $item['dimensions'],
        'Warranty' => $item['warranty'], 'Stock' => $item['stock_quantity'] ?: null,
        'Delivery' => $item['delivery_available'] ? 'Available' : null, 'Installation' => $item['installation_available'] ? 'Available' : null,
        'Customization' => $item['customization_available'] ? 'Available' : null];
} elseif ($type === 'service') {
    $specs = ['Experience' => $item['experience_years'] ? $item['experience_years'] . ' years' : null,
        'Pricing' => PRICE_TYPES[$item['price_type']] ?? $item['price_type']];
} else {
    $specs = ['Brand' => $item['brand'], 'Grade' => $item['grade'], 'Size' => $item['size'], 'Thickness' => $item['thickness'],
        'Unit' => $item['unit_of_measurement'], 'Min. order' => $item['minimum_order_quantity'] > 1 ? (float)$item['minimum_order_quantity'] : null,
        'Bulk price' => money($item['bulk_price']), 'In stock' => (float)$item['stock_quantity'] ?: null,
        'Delivery' => $item['delivery_available'] ? 'Available' : null];
}
include __DIR__ . '/../views/layout_top.php';
?>
<div class="container section detail-layout">
  <div class="detail-main">
    <nav class="breadcrumb">
      <a href="<?= url('') ?>">Home</a> › <a href="<?= url($table) ?>"><?= ucfirst($table) ?></a> ›
      <a href="<?= url($table . '?category=' . urlencode(val("SELECT slug FROM categories WHERE id=?", [$item['category_id']]))) ?>"><?= e($item['c_name']) ?></a>
    </nav>

    <div class="gallery">
      <?php if ($media): ?>
        <img id="gallery-main" src="<?= e(img_url($media[0]['file_url'])) ?>" alt="<?= e($title) ?>">
        <?php if (count($media) > 1): ?>
        <div class="thumbs">
          <?php foreach ($media as $m): ?>
            <img src="<?= e(img_url($m['file_url'])) ?>" onclick="document.getElementById('gallery-main').src=this.src" alt="">
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      <?php else: ?>
        <div class="gallery-placeholder"><?= $item['c_icon'] ?></div>
      <?php endif; ?>
    </div>

    <?php if ($glb): ?>
    <div class="panel">
      <h3>🪄 3D & AR Preview <span class="badge badge-featured">AR</span></h3>
      <script type="module" src="https://unpkg.com/@google/model-viewer@3.5.0/dist/model-viewer.min.js"></script>
      <model-viewer src="<?= e(img_url($glb['file_url'])) ?>"
        <?= $usdz ? 'ios-src="' . e(img_url($usdz['file_url'])) . '"' : '' ?>
        alt="<?= e($title) ?> 3D model" ar ar-modes="webxr scene-viewer quick-look"
        camera-controls auto-rotate style="width:100%;height:380px;background:#f2e9df;border-radius:12px">
      </model-viewer>
      <p class="muted small">Rotate with your mouse/finger. On mobile, tap the AR icon to view it in your room.</p>
    </div>
    <?php endif; ?>

    <h1 class="detail-title"><?= e($title) ?></h1>
    <div class="detail-sub muted">📍 <?= e(($item['subcity'] ? $item['subcity'] . ', ' : '') . ($item['city'] ?: 'Ethiopia')) ?>
      · <?= e($item['c_name']) ?> · listed <?= time_ago($item['created_at']) ?> · 👁 <?= (int)$item['views_count'] + 1 ?> views</div>

    <div class="detail-price">
      <?php if ($type === 'product'): ?>
        <?php if ($item['discount_price'] > 0): ?>
          <span class="price"><?= money($item['discount_price']) ?></span>
          <span class="price-old"><?= money($item['price']) ?></span>
        <?php else: ?>
          <span class="price"><?= money($item['price']) ?: 'Negotiable' ?></span>
        <?php endif; ?>
        <?php if ($item['is_negotiable']): ?><span class="badge">Negotiable</span><?php endif; ?>
      <?php elseif ($type === 'service'): ?>
        <span class="price"><?= $item['price_type'] === 'quote_required' ? 'Request a quote' : trim((PRICE_TYPES[$item['price_type']] ?? '') . ' ' . money($item['starting_price'])) ?></span>
      <?php else: ?>
        <span class="price"><?= money($item['price_per_unit']) ?: 'Negotiable' ?><?= $item['price_per_unit'] > 0 ? ' <small>/ ' . e($item['unit_of_measurement']) . '</small>' : '' ?></span>
      <?php endif; ?>
    </div>

    <?php if ($item['description']): ?>
      <h3>Description</h3>
      <p class="detail-desc"><?= nl2br(e($item['description'])) ?></p>
    <?php endif; ?>

    <?php $specs = array_filter($specs, fn($v) => $v !== null && $v !== '' && $v !== false); if ($specs): ?>
      <h3>Details</h3>
      <table class="spec-table">
        <?php foreach ($specs as $k => $v): ?><tr><th><?= e($k) ?></th><td><?= e($v) ?></td></tr><?php endforeach; ?>
      </table>
    <?php endif; ?>

    <?php if ($videos): ?>
      <h3>Videos</h3>
      <div class="detail-videos">
        <?php foreach ($videos as $v): ?><div class="video-wrap-sm"><?= video_embed_html($v) ?></div><?php endforeach; ?>
      </div>
    <?php endif; ?>

    <h3 id="reviews">Reviews <?= $reviews ? '(' . count($reviews) . ')' : '' ?></h3>
    <?php if (!$reviews): ?><p class="muted">No reviews yet.</p><?php endif; ?>
    <?php foreach ($reviews as $r): ?>
      <div class="review">
        <div class="review-head">
          <strong><?= e($r['full_name']) ?></strong>
          <span class="stars"><?= str_repeat('★', (int)$r['rating']) . str_repeat('☆', 5 - (int)$r['rating']) ?></span>
          <?php if (!$r['is_verified_purchase']): ?><span class="badge badge-muted">unverified purchase</span><?php endif; ?>
          <span class="muted"><?= time_ago($r['created_at']) ?></span>
        </div>
        <?php if ($r['title']): ?><strong><?= e($r['title']) ?></strong><?php endif; ?>
        <p><?= nl2br(e($r['comment'])) ?></p>
      </div>
    <?php endforeach; ?>

    <?php if ($u): ?>
    <form class="panel" method="post" action="<?= url('review') ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="listing_type" value="<?= $type ?>">
      <input type="hidden" name="listing_id" value="<?= $item['id'] ?>">
      <input type="hidden" name="business_id" value="<?= $item['business_id'] ?>">
      <h4>Write a review</h4>
      <label>Rating
        <select name="rating" required>
          <?php for ($i = 5; $i >= 1; $i--): ?><option value="<?= $i ?>"><?= str_repeat('★', $i) ?></option><?php endfor; ?>
        </select>
      </label>
      <label>Comment <textarea name="comment" rows="3" required maxlength="2000"></textarea></label>
      <button class="btn btn-primary">Submit review</button>
      <p class="muted small">Reviews appear after moderation.</p>
    </form>
    <?php else: ?>
      <p class="muted"><a href="<?= url('login') ?>">Log in</a> to write a review.</p>
    <?php endif; ?>
  </div>

  <aside class="detail-side">
    <div class="panel vendor-panel">
      <h3><a href="<?= url('businesses/' . e($item['b_slug'])) ?>"><?= e($item['b_name']) ?></a></h3>
      <div><?= verified_badge($item['b_verification']) ?></div>
      <div><?= star_rating($item['b_rating'], (int)$item['b_rating_count']) ?></div>
      <div class="muted small">📍 <?= e($item['b_city'] ?: 'Ethiopia') ?> · joined <?= date('M Y', strtotime($item['b_joined'])) ?></div>
      <?php if ($item['b_phone']): ?>
        <a class="btn btn-outline btn-block reveal-phone" href="tel:<?= e($item['b_phone']) ?>" data-phone="<?= e($item['b_phone']) ?>">📞 Show phone number</a>
      <?php endif; ?>
      <a class="btn btn-ghost btn-block" href="<?= url('businesses/' . e($item['b_slug'])) ?>">Visit shop →</a>
    </div>

    <?php if (($type === 'product' && $item['price'] > 0 && $item['status'] === 'active')
           || ($type === 'supply' && $item['price_per_unit'] > 0)): ?>
    <div class="panel">
      <h3>🛒 Order online</h3>
      <form method="post" action="<?= url('cart') ?>" class="form-inline">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="add">
        <input type="hidden" name="back" value="1">
        <input type="hidden" name="listing_type" value="<?= $type ?>">
        <input type="hidden" name="listing_id" value="<?= $item['id'] ?>">
        <input type="number" name="qty" value="<?= $type === 'supply' ? max(1, (float)$item['minimum_order_quantity']) : 1 ?>" min="1" step="any" style="width:90px">
        <button class="btn btn-primary">Add to cart</button>
      </form>
      <?php if ($type === 'supply' && $item['minimum_order_quantity'] > 1): ?>
        <p class="muted small">Minimum order: <?= (float)$item['minimum_order_quantity'] ?> <?= e($item['unit_of_measurement']) ?></p>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="panel">
      <h3><?= $type === 'service' ? 'Request a quote' : 'Send inquiry' ?></h3>
      <form method="post" action="<?= url('inquiry') ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="listing_type" value="<?= $type ?>">
        <input type="hidden" name="listing_id" value="<?= $item['id'] ?>">
        <input type="hidden" name="business_id" value="<?= $item['business_id'] ?>">
        <input type="hidden" name="inquiry_type" value="<?= $inquiryType ?>">
        <input type="hidden" name="source" value="product_detail">
        <?php if (!$u): ?>
          <label>Your name <input name="name" required maxlength="150"></label>
        <?php endif; ?>
        <label>Phone <input name="phone" required maxlength="30" value="<?= e($u['phone'] ?? '') ?>" placeholder="09…"></label>
        <label>Message <textarea name="message" rows="4" required maxlength="2000" placeholder="<?= $type === 'service' ? 'Describe your project (size, location, timeline)…' : 'I am interested in this listing. Is it available?' ?>"></textarea></label>
        <label>Preferred contact
          <select name="preferred_contact_method">
            <?php foreach (['phone', 'telegram', 'whatsapp', 'email', 'chat'] as $m): ?><option><?= $m ?></option><?php endforeach; ?>
          </select>
        </label>
        <button class="btn btn-primary btn-block"><?= $type === 'service' ? 'Request Quote' : 'Send Inquiry' ?></button>
      </form>
    </div>

    <?php if ($type === 'product' && $u): ?>
    <form method="post" action="<?= url('favorite') ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="product_id" value="<?= $item['id'] ?>">
      <button class="btn btn-ghost btn-block"><?= $isFav ? '❤️ Saved — remove' : '🤍 Save product' ?></button>
    </form>
    <?php endif; ?>

    <?= ad_slot('detail_sidebar', ['market_type' => $type, 'category_id' => (int)$item['category_id'],
        'city' => $item['city'] ?: null, 'subcity' => $item['subcity'] ?: null]) ?>

    <form method="post" action="<?= url('report') ?>" class="report-form">
      <?= csrf_field() ?>
      <input type="hidden" name="reported_type" value="<?= $type ?>">
      <input type="hidden" name="reported_id" value="<?= $item['id'] ?>">
      <details>
        <summary>🚩 Report this listing</summary>
        <label>Reason
          <select name="reason">
            <?php foreach (['Fake or misleading', 'Wrong price', 'Offensive content', 'Duplicate listing', 'Scam suspicion', 'Other'] as $r): ?>
              <option><?= $r ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Details <textarea name="description" rows="2" maxlength="1000"></textarea></label>
        <button class="btn btn-outline btn-sm">Send report</button>
      </details>
    </form>
  </aside>
</div>
<?php include __DIR__ . '/../views/layout_bottom.php'; ?>
