<?php
/** Listing detail page. Expects $type, $slug */
$table = LISTING_TABLES[$type];
$titleCol = listing_title_col($type);
$u = auth();

$item = row("SELECT l.*, b.business_name b_name, b.slug b_slug, b.verification_status b_verification, b.phone b_phone,
        b.rating_average b_rating, b.rating_count b_rating_count, b.city b_city, b.created_at b_joined, b.user_id b_user_id,
        c.name c_name, c.icon c_icon
    FROM `$table` l JOIN businesses b ON b.id = l.business_id JOIN categories c ON c.id = l.category_id
    WHERE l.slug = ? AND b.status = 'active'", [$slug]);

$canPreview = $item && $u && ((int)$item['b_user_id'] === (int)$u['id'] || is_admin($u));
$isPendingPreview = $item && $item['status'] === 'pending_review' && $canPreview;
if (!$item || ($item['status'] !== 'active' && !$isPendingPreview)) { http_response_code(404); $pageTitle = 'Not found'; include __DIR__ . '/../views/layout_top.php';
    echo '<div class="container section"><div class="empty-state">Listing not found or not yet approved.</div></div>';
    include __DIR__ . '/../views/layout_bottom.php'; return; }

if (!$isPendingPreview) {
    q("UPDATE `$table` SET views_count = views_count + 1 WHERE id = ?", [$item['id']]);
    event_record('view', [
        'listing_type' => $type,
        'listing_id' => (int)$item['id'],
        'business_id' => (int)$item['business_id'],
        'category_id' => (int)$item['category_id'],
        'source' => traffic_source_for_listing($type, (int)$item['id'], ($_GET['src'] ?? '') === 'ad' ? 'ad' : 'organic'),
        'city' => $item['city'] ?: null,
        'subcity' => $item['subcity'] ?: null,
    ]);
}
$title = $item[$titleCol];
$pageTitle = $title;
$pageDesc = mb_substr(strip_tags($item['description'] ?? ''), 0, 150);
$canonical = ['product' => 'products', 'service' => 'services', 'supply' => 'supplies'][$type] . '/' . $item['slug'];
$ogType = $type === 'product' ? 'product' : 'website';

$allMedia = $type === 'product'
    ? rows("SELECT * FROM product_media WHERE product_id = ? ORDER BY is_primary DESC, sort_order", [$item['id']])
    : (($item['image'] ?? null) ? [['file_url' => $item['image'], 'media_type' => 'image']] : []);
$media = array_values(array_filter($allMedia, fn($m) => ($m['media_type'] ?? 'image') === 'image'));
$glb  = current(array_filter($allMedia, fn($m) => ($m['media_type'] ?? '') === 'model_3d_glb')) ?: null;
$usdz = current(array_filter($allMedia, fn($m) => ($m['media_type'] ?? '') === 'model_3d_usdz')) ?: null;

$attributeDefs = category_attributes((int)$item['category_id']);
$attributeValues = decode_attributes($item['attributes'] ?? null);
$attributeSpecs = [];
$attributeJsonLd = [];
foreach ($attributeDefs as $def) {
    $key = $def['key_name'];
    if (!array_key_exists($key, $attributeValues)) continue;
    $value = $attributeValues[$key];
    if ($value === null || $value === '') continue;

    $display = html_entity_decode(strip_tags(attribute_value_display($def, $value)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if ($display === '') continue;

    $attributeSpecs[$def['label']] = $display;
    $property = ['@type' => 'PropertyValue', 'name' => $def['label'], 'value' => $display];
    if (!empty($def['unit'])) $property['unitText'] = $def['unit'];
    $attributeJsonLd[] = $property;
}

// SEO structured data (§25.3)
$curPrice = $type === 'product' ? ($item['discount_price'] > 0 ? $item['discount_price'] : $item['price'])
    : ($type === 'supply' ? $item['price_per_unit'] : ($item['starting_price'] ?? null));
$listingJsonLd = [
    '@context' => 'https://schema.org',
    '@type' => $type === 'service' ? 'Service' : 'Product',
    'name' => $title,
    'description' => mb_substr(strip_tags($item['description'] ?? ''), 0, 300),
    'url' => absolute_url(listing_url($type, $item)),
    'category' => $item['c_name'] ?? null,
];
if ($media) $listingJsonLd['image'] = absolute_url(img_url($media[0]['file_url']));
if ($curPrice > 0) $listingJsonLd['offers'] = ['@type' => 'Offer', 'price' => (float)$curPrice, 'priceCurrency' => 'ETB',
    'availability' => 'https://schema.org/InStock', 'seller' => ['@type' => 'Organization', 'name' => $item['b_name']]];
if ((float)$item['b_rating'] > 0) $listingJsonLd['aggregateRating'] = ['@type' => 'AggregateRating',
    'ratingValue' => (float)$item['b_rating'], 'reviewCount' => max(1, (int)$item['b_rating_count'])];
if ($attributeJsonLd && $listingJsonLd['@type'] === 'Product') $listingJsonLd['additionalProperty'] = $attributeJsonLd;
if ($media) $ogImage = absolute_url(img_url($media[0]['file_url']));
$breadcrumbJsonLd = [
    '@type' => 'BreadcrumbList',
    'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => absolute_url(url(''))],
        ['@type' => 'ListItem', 'position' => 2, 'name' => ucfirst($table), 'item' => absolute_url(url($table))],
        ['@type' => 'ListItem', 'position' => 3, 'name' => $item['c_name'], 'item' => absolute_url(url($table . '?category=' . urlencode(val("SELECT slug FROM categories WHERE id=?", [$item['category_id']]))))],
        ['@type' => 'ListItem', 'position' => 4, 'name' => $title, 'item' => absolute_url(listing_url($type, $item))],
    ],
];
$jsonLd = ['@context' => 'https://schema.org', '@graph' => [$listingJsonLd, $breadcrumbJsonLd]];

$reviews = rows("SELECT r.*, u.full_name FROM reviews r JOIN users u ON u.id = r.reviewer_id
    WHERE r.listing_type = ? AND r.listing_id = ? AND r.status = 'approved' ORDER BY r.created_at DESC LIMIT 20", [$type, $item['id']]);

$videos = rows("SELECT * FROM video_posts WHERE linked_type = ? AND linked_id = ? AND status = 'approved'", [$type, $item['id']]);

$isFav = $u && $type === 'product' && val("SELECT COUNT(*) FROM favorites WHERE user_id = ? AND product_id = ?", [$u['id'], $item['id']]);

$inquiryType = ['product' => 'product_inquiry', 'service' => 'service_quote_request', 'supply' => 'supply_order_request'][$type];
$postLikePath = is_vendor($u) ? "app/vendor/listings/$type/new?category_id=" . (int)$item['category_id'] : ($u ? 'account' : 'register');
$boostRank = boost_rank_sql('b.id');
$similar = rows("SELECT l.*, b.business_name b_name, b.verification_status b_verification, c.name c_name, c.icon c_icon
    FROM `$table` l JOIN businesses b ON b.id = l.business_id JOIN categories c ON c.id = l.category_id
    WHERE l.status = 'active' AND b.status = 'active' AND l.category_id = ? AND l.id != ?
    ORDER BY $boostRank DESC, l.is_featured DESC, l.is_promoted DESC, (l.city <=> ?) DESC, l.created_at DESC LIMIT 8",
    [$item['category_id'], $item['id'], $item['city'] ?: null]);
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
$specs = array_merge($specs, $attributeSpecs);
include __DIR__ . '/../views/layout_top.php';
?>
<div class="container section detail-layout listing-detail-page">
  <div class="detail-main">
    <?php if ($isPendingPreview): ?>
    <div class="alert alert-warning" role="status">
      <strong>Pending review</strong>
      This is a private preview of your product. Customers cannot see it until an administrator approves it.
    </div>
    <?php endif; ?>
    <nav aria-label="breadcrumb" class="breadcrumbs text-sm mb-4">
      <ul>
        <li><a href="<?= url('') ?>">Home</a></li>
        <li><a href="<?= url($table) ?>"><?= ucfirst($table) ?></a></li>
        <li><a href="<?= url($table . '?category=' . urlencode(val("SELECT slug FROM categories WHERE id=?", [$item['category_id']]))) ?>"><?= e($item['c_name']) ?></a></li>
        <li class="opacity-60"><?= e(mb_strimwidth($title, 0, 40, '…')) ?></li>
      </ul>
    </nav>

    <?php $initialGalleryImage = $media ? img_url($media[0]['file_url']) : ''; ?>
    <div class="gallery" x-data="<?= e(json_encode(['active' => $initialGalleryImage], JSON_UNESCAPED_SLASHES)) ?>">
      <?php if ($media): ?>
        <img id="gallery-main" :src="active" src="<?= e(img_url($media[0]['file_url'])) ?>" alt="<?= e($title) ?>">
        <?php if (count($media) > 1): ?>
        <div class="thumbs">
          <?php foreach ($media as $m): ?>
            <?php $thumbSrc = img_url($m['file_url']); ?>
            <button type="button" class="gallery-thumb-btn" @click="active = <?= e(json_encode($thumbSrc, JSON_UNESCAPED_SLASHES)) ?>" :aria-current="active === <?= e(json_encode($thumbSrc, JSON_UNESCAPED_SLASHES)) ?> ? 'true' : 'false'">
              <img src="<?= e($thumbSrc) ?>" alt="">
            </button>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      <?php else: ?>
        <div class="gallery-placeholder"><?= $item['c_icon'] ?></div>
      <?php endif; ?>
    </div>

    <?php if ($glb && feature_enabled('ar')): ?>
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

    <h1 class="detail-title"<?= content_lang_attr($title) ?>><?= e($title) ?></h1>
    <div class="detail-badges" aria-label="Listing highlights">
      <?php if (!empty($item['is_featured'])): ?><?= listing_badge('featured', 'Featured', 'star') ?><?php endif; ?>
      <?php if (!empty($item['is_promoted'])): ?><?= listing_badge('promoted', 'Promoted', 'trend') ?><?php endif; ?>
      <?php if ($type === 'product' && !empty($item['condition_type'])): ?><?= listing_badge('condition', ['new' => 'Brand New', 'used' => 'Used', 'refurbished' => 'Refurbished'][$item['condition_type']] ?? ucfirst($item['condition_type']), 'box') ?><?php endif; ?>
      <?php if (!empty($item['delivery_available'])): ?><?= listing_badge('delivery', 'Delivery available', 'truck') ?><?php endif; ?>
    </div>
    <div class="detail-sub muted">📍 <?= e(($item['subcity'] ? $item['subcity'] . ', ' : '') . ($item['city'] ?: 'Ethiopia')) ?>
      · <?= e($item['c_name']) ?> · listed <?= time_ago($item['created_at']) ?> · 👁 <?= (int)$item['views_count'] + 1 ?> views</div>

    <div class="detail-price detail-price-row">
      <?php if ($type === 'product'): ?>
        <?php if ($item['discount_price'] > 0): ?>
          <span class="price"><?= money($item['discount_price']) ?></span>
          <span class="price-old"><?= money($item['price']) ?></span>
        <?php else: ?>
          <span class="price"><?= money($item['price']) ?: 'Negotiable' ?></span>
        <?php endif; ?>
        <?php if ($item['discount_price'] > 0 && $item['price'] > 0 && $item['discount_price'] < $item['price']): ?><?= listing_badge('discount', 'Save ' . (int)round(100 - $item['discount_price'] / $item['price'] * 100) . '%', 'tag') ?><?php endif; ?>
        <?php if ($item['is_negotiable']): ?><?= listing_badge('negotiable', 'Negotiable', 'offer') ?><?php endif; ?>
      <?php elseif ($type === 'service'): ?>
        <span class="price"><?= $item['price_type'] === 'quote_required' ? 'Request a quote' : trim((PRICE_TYPES[$item['price_type']] ?? '') . ' ' . money($item['starting_price'])) ?></span>
      <?php else: ?>
        <span class="price"><?= money($item['price_per_unit']) ?: 'Negotiable' ?><?= $item['price_per_unit'] > 0 ? ' <small>/ ' . e($item['unit_of_measurement']) . '</small>' : '' ?></span>
      <?php endif; ?>
    </div>

    <?php
    $specs = array_filter($specs, fn($v) => $v !== null && $v !== '' && $v !== false);
    $hasVideos = !empty($videos);
    $hasReviews = !empty($reviews) || ($u && feature_enabled('reviews')) || feature_enabled('reviews');
    $hasSpecs = !empty($specs);
    ?>
    <div role="tablist" class="tabs tabs-lifted mt-6">

      <!-- Tab 1: Description -->
      <input type="radio" name="detail-tabs" role="tab" class="tab font-semibold" aria-label="Description" checked>
      <div role="tabpanel" class="tab-content bg-base-100 border-base-300 rounded-box p-4">
        <?php if ($item['description']): ?>
          <p class="detail-desc"<?= content_lang_attr($item['description']) ?>><?= nl2br(e($item['description'])) ?></p>
        <?php else: ?>
          <p class="muted">No description provided.</p>
        <?php endif; ?>
      </div>

      <!-- Tab 2: Details / Specs -->
      <?php if ($hasSpecs): ?>
      <input type="radio" name="detail-tabs" role="tab" class="tab font-semibold" aria-label="Details">
      <div role="tabpanel" class="tab-content bg-base-100 border-base-300 rounded-box p-4">
        <div class="overflow-x-auto">
          <table class="spec-table">
            <?php foreach ($specs as $k => $v): ?><tr><th><?= e($k) ?></th><td><?= e($v) ?></td></tr><?php endforeach; ?>
          </table>
        </div>
      </div>
      <?php endif; ?>

      <!-- Tab 3: Reviews -->
      <input type="radio" name="detail-tabs" role="tab" class="tab font-semibold"
             aria-label="Reviews <?= $reviews ? '(' . count($reviews) . ')' : '' ?>">
      <div role="tabpanel" class="tab-content bg-base-100 border-base-300 rounded-box p-4" id="reviews">
        <?php if (!$reviews): ?><p class="muted">No reviews yet.</p><?php endif; ?>
        <?php foreach ($reviews as $r): ?>
          <div class="review">
            <div class="review-head">
              <strong><?= e($r['full_name']) ?></strong>
              <span class="stars"><?= str_repeat('★', (int)$r['rating']) . str_repeat('☆', 5 - (int)$r['rating']) ?></span>
              <?php if (!$r['is_verified_purchase']): ?><span class="badge badge-muted">unverified</span><?php endif; ?>
              <span class="muted"><?= time_ago($r['created_at']) ?></span>
            </div>
            <?php if ($r['title']): ?><strong><?= e($r['title']) ?></strong><?php endif; ?>
            <p><?= nl2br(e($r['comment'])) ?></p>
          </div>
        <?php endforeach; ?>
        <?php if ($u && feature_enabled('reviews')): ?>
        <form class="mt-4" method="post" action="<?= url('review') ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="listing_type" value="<?= $type ?>">
          <input type="hidden" name="listing_id" value="<?= $item['id'] ?>">
          <input type="hidden" name="business_id" value="<?= $item['business_id'] ?>">
          <h4 class="font-bold mb-3">Write a review</h4>
          <label>Rating
            <select name="rating" required>
              <?php for ($i = 5; $i >= 1; $i--): ?><option value="<?= $i ?>"><?= str_repeat('★', $i) ?></option><?php endfor; ?>
            </select>
          </label>
          <label>Comment <textarea name="comment" rows="3" required maxlength="2000"></textarea></label>
          <button class="btn btn-primary btn-sm mt-2">Submit review</button>
          <p class="muted small mt-1">Reviews appear after moderation.</p>
        </form>
        <?php elseif (feature_enabled('reviews')): ?>
          <p class="muted mt-3"><a href="<?= url('login') ?>">Log in</a> to write a review.</p>
        <?php endif; ?>
      </div>

      <!-- Tab 4: Videos (only if any) -->
      <?php if ($hasVideos): ?>
      <input type="radio" name="detail-tabs" role="tab" class="tab font-semibold" aria-label="Videos (<?= count($videos) ?>)">
      <div role="tabpanel" class="tab-content bg-base-100 border-base-300 rounded-box p-4">
        <div class="detail-videos">
          <?php foreach ($videos as $v): ?><div class="video-wrap-sm"><?= video_embed_html($v) ?></div><?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

    </div><!-- /tabs -->

    <?php if ($similar): ?>
    <section class="similar-listings">
      <div class="section-head">
        <div>
          <p class="eyebrow">More like this</p>
          <h2>Similar listings</h2>
        </div>
        <a class="link" href="<?= url($table . '?category=' . urlencode(val("SELECT slug FROM categories WHERE id=?", [$item['category_id']]))) ?>">See all</a>
      </div>
      <div class="similar-strip">
        <?php $detailItem = $item; foreach ($similar as $sim): $item = $sim; $cardType = $type; include __DIR__ . '/../views/partial_card.php'; endforeach; $item = $detailItem; ?>
      </div>
    </section>
    <?php endif; ?>
  </div>

  <aside class="detail-side detail-action-stack">
    <div class="panel vendor-panel">
      <h3><a href="<?= url('businesses/' . e($item['b_slug'])) ?>"><?= e($item['b_name']) ?></a></h3>
      <div><?= verified_badge($item['b_verification']) ?></div>
      <div><?= star_rating($item['b_rating'], (int)$item['b_rating_count']) ?></div>
      <div class="muted small">📍 <?= e($item['b_city'] ?: 'Ethiopia') ?> · joined <?= date('M Y', strtotime($item['b_joined'])) ?></div>
      <?php if ($item['b_phone']): ?>
        <?php if (business_phone_unlocked($u, (int)$item['business_id'])): ?>
        <a class="btn btn-outline btn-block reveal-phone" href="tel:<?= e($item['b_phone']) ?>" data-phone="<?= e($item['b_phone']) ?>" aria-label="Call seller at <?= e($item['b_phone']) ?>"><?= system_ui_icon('phone', 'Phone') ?> Show phone number</a>
        <?php else: ?>
        <a class="btn btn-outline btn-block" href="<?= url('register?return=' . rawurlencode(current_internal_path())) ?>" title="Log in or create an account to show the seller's phone number"><?= system_ui_icon('unlock', 'Login') ?> Login to show phone</a>
        <?php endif; ?>
      <?php endif; ?>
      <a class="btn btn-ghost btn-block" href="<?= url('businesses/' . e($item['b_slug'])) ?>">Visit shop →</a>
    </div>

    <?php if (feature_enabled('cart') && (($type === 'product' && $item['price'] > 0 && $item['status'] === 'active')
           || ($type === 'supply' && $item['price_per_unit'] > 0))): ?>
    <div class="panel">
      <h3>🛒 Order online</h3>
      <form method="post" action="<?= url('cart') ?>" class="form-inline">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="add">
        <input type="hidden" name="back" value="1">
        <input type="hidden" name="listing_type" value="<?= $type ?>">
        <input type="hidden" name="listing_id" value="<?= $item['id'] ?>">
        <input type="hidden" name="traffic_source" value="<?= ($_GET['src'] ?? '') === 'ad' ? 'ad' : traffic_source_for_listing($type, (int)$item['id']) ?>">
        <label class="sr-only" for="detail-cart-qty">Quantity</label>
        <input type="number" id="detail-cart-qty" name="qty" value="<?= $type === 'supply' ? max(1, (float)$item['minimum_order_quantity']) : 1 ?>" min="1" step="any" style="width:90px">
        <button class="btn btn-primary">Add to cart</button>
      </form>
      <?php if ($type === 'supply' && $item['minimum_order_quantity'] > 1): ?>
        <p class="muted small">Minimum order: <?= (float)$item['minimum_order_quantity'] ?> <?= e($item['unit_of_measurement']) ?></p>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if (feature_enabled('inquiries')): ?>
    <div class="panel" id="contact-seller">
      <h3><?= $type === 'service' ? 'Request a quote' : 'Send inquiry' ?></h3>
      <div class="conversion-actions">
        <?php if ($type === 'product' && !empty($item['is_negotiable'])): ?>
        <form method="post" action="<?= url('inquiry') ?>" class="conversion-card">
          <?= csrf_field() ?>
          <input type="hidden" name="listing_type" value="<?= $type ?>">
          <input type="hidden" name="listing_id" value="<?= $item['id'] ?>">
          <input type="hidden" name="business_id" value="<?= $item['business_id'] ?>">
          <input type="hidden" name="inquiry_type" value="<?= $inquiryType ?>">
          <input type="hidden" name="source" value="<?= ($_GET['src'] ?? '') === 'ad' ? 'featured_ad' : 'product_detail' ?>">
          <input type="hidden" name="conversion_action" value="make_offer">
          <input type="hidden" name="preferred_contact_method" value="phone">
          <strong>Make an offer</strong>
          <div class="conversion-row">
            <label class="sr-only" for="offer-amount-<?= (int)$item['id'] ?>">Offer amount in ETB</label>
            <input type="number" id="offer-amount-<?= (int)$item['id'] ?>" name="offer_amount" min="1" step="1" required placeholder="ETB amount">
            <label class="sr-only" for="offer-phone-<?= (int)$item['id'] ?>">Phone number for offer response</label>
            <input id="offer-phone-<?= (int)$item['id'] ?>" name="phone" required maxlength="30" value="<?= e($u['phone'] ?? '') ?>" placeholder="Phone" autocomplete="tel">
          </div>
          <button class="btn btn-outline btn-sm">Send offer</button>
        </form>
        <?php endif; ?>
        <form method="post" action="<?= url('inquiry') ?>" class="conversion-card">
          <?= csrf_field() ?>
          <input type="hidden" name="listing_type" value="<?= $type ?>">
          <input type="hidden" name="listing_id" value="<?= $item['id'] ?>">
          <input type="hidden" name="business_id" value="<?= $item['business_id'] ?>">
          <input type="hidden" name="inquiry_type" value="<?= $inquiryType ?>">
          <input type="hidden" name="source" value="<?= ($_GET['src'] ?? '') === 'ad' ? 'featured_ad' : 'product_detail' ?>">
          <input type="hidden" name="conversion_action" value="request_callback">
          <strong>Request call back</strong>
          <div class="conversion-row">
            <label class="sr-only" for="callback-phone-<?= (int)$item['id'] ?>">Phone number for callback</label>
            <input id="callback-phone-<?= (int)$item['id'] ?>" name="phone" required maxlength="30" value="<?= e($u['phone'] ?? '') ?>" placeholder="Phone" autocomplete="tel">
            <label class="sr-only" for="callback-time-<?= (int)$item['id'] ?>">Best callback time</label>
            <input id="callback-time-<?= (int)$item['id'] ?>" name="callback_time" maxlength="80" placeholder="Best time">
          </div>
          <button class="btn btn-outline btn-sm">Ask seller to call</button>
        </form>
        <a class="conversion-card conversion-link" href="<?= url($postLikePath) ?>">
          <strong>Post a listing like this</strong>
          <span>Start selling in this category</span>
        </a>
      </div>
      <form method="post" action="<?= url('inquiry') ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="listing_type" value="<?= $type ?>">
        <input type="hidden" name="listing_id" value="<?= $item['id'] ?>">
        <input type="hidden" name="business_id" value="<?= $item['business_id'] ?>">
        <input type="hidden" name="inquiry_type" value="<?= $inquiryType ?>">
        <input type="hidden" name="source" value="<?= ($_GET['src'] ?? '') === 'ad' ? 'featured_ad' : 'product_detail' ?>">
        <?php if (!$u): ?>
          <label>Your name <input name="name" required maxlength="150"></label>
        <?php endif; ?>
        <label>Phone <input name="phone" required maxlength="30" value="<?= e($u['phone'] ?? '') ?>" placeholder="09…" autocomplete="tel"></label>
        <label>Message <textarea name="message" rows="4" required maxlength="2000" placeholder="<?= $type === 'service' ? 'Describe your project (size, location, timeline)…' : 'I am interested in this listing. Is it available?' ?>"></textarea></label>
        <label>Preferred contact
          <select name="preferred_contact_method">
            <?php foreach (['phone', 'telegram', 'whatsapp', 'email', 'chat'] as $m): ?><option><?= $m ?></option><?php endforeach; ?>
          </select>
        </label>
        <button class="btn btn-primary btn-block"><?= system_ui_icon('send', 'Send') ?> <?= $type === 'service' ? 'Request Quote' : 'Send Inquiry' ?></button>
      </form>
    </div>
    <?php endif; /* inquiries feature */ ?>

    <div class="panel safety-tips" aria-label="Marketplace safety tips">
      <h3>Safety tips</h3>
      <ul>
        <li>Inspect the item or work quality before paying.</li>
        <li>Meet in a safe public place when possible.</li>
        <li>Use traceable payments and keep your receipt/reference number.</li>
        <li>Report suspicious prices, pressure tactics, or copied photos.</li>
      </ul>
    </div>

    <?php if ($type === 'product' && $u): ?>
    <form method="post" action="<?= url('favorite') ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="product_id" value="<?= $item['id'] ?>">
      <button class="btn btn-ghost btn-block"><?= system_ui_icon($isFav ? 'heart' : 'heart-outline', 'Save') ?> <?= $isFav ? 'Saved — remove' : 'Save product' ?></button>
    </form>
    <?php endif; ?>

    <?= ad_slot('detail_sidebar', ['market_type' => $type, 'category_id' => (int)$item['category_id'],
        'city' => $item['city'] ?: null, 'subcity' => $item['subcity'] ?: null]) ?>

    <form method="post" action="<?= url('report') ?>" class="report-form">
      <?= csrf_field() ?>
      <input type="hidden" name="reported_type" value="<?= $type ?>">
      <input type="hidden" name="reported_id" value="<?= $item['id'] ?>">
      <details>
        <summary><?= system_ui_icon('report', 'Report') ?> Report this listing</summary>
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
<div class="detail-contact-bar" aria-label="Listing contact actions">
  <?php if (feature_enabled('inquiries')): ?>
    <a class="btn btn-primary" href="#contact-seller" aria-label="Go to contact seller form"><?= system_ui_icon('chat', 'Chat') ?> Chat / Send inquiry</a>
  <?php else: ?>
    <a class="btn btn-primary" href="<?= url('businesses/' . e($item['b_slug'])) ?>">Visit shop</a>
  <?php endif; ?>
  <?php if ($item['b_phone']): ?>
    <?php if (business_phone_unlocked($u, (int)$item['business_id'])): ?>
    <a class="btn btn-outline reveal-phone" href="tel:<?= e($item['b_phone']) ?>" data-phone="<?= e($item['b_phone']) ?>" aria-label="Call seller at <?= e($item['b_phone']) ?>"><?= system_ui_icon('phone', 'Phone') ?> Show phone</a>
    <?php else: ?>
    <a class="btn btn-outline" href="<?= url('register?return=' . rawurlencode(current_internal_path())) ?>"><?= system_ui_icon('unlock', 'Login') ?> Login to show phone</a>
    <?php endif; ?>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/../views/layout_bottom.php'; ?>
