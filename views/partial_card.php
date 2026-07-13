<?php
/** Renders one listing card. Expects: $cardType ('product'|'service'|'supply'), $item (row joined with business b_*, category c_*). */
$img = listing_image($cardType, $item);
$title = $item[listing_title_col($cardType)];
$href = listing_url($cardType, $item);
$price = '';
$oldPrice = '';
$conditionLabel = '';
$discountPercent = 0;
if ($cardType === 'product') {
    $price = $item['discount_price'] > 0 ? money($item['discount_price']) : money($item['price']);
    $oldPrice = $item['discount_price'] > 0 && $item['price'] > 0 ? money($item['price']) : '';
    $conditionLabel = ['new' => 'Brand New', 'used' => 'Used', 'refurbished' => 'Refurbished'][$item['condition_type'] ?? ''] ?? '';
    if (($item['discount_price'] ?? 0) > 0 && ($item['price'] ?? 0) > 0 && $item['discount_price'] < $item['price']) {
        $discountPercent = (int)round(100 - $item['discount_price'] / $item['price'] * 100);
    }
} elseif ($cardType === 'service') {
    $price = $item['price_type'] === 'quote_required' ? 'Quote on request'
        : (PRICE_TYPES[$item['price_type']] ?? '') . ' ' . money($item['starting_price']);
} else {
    $price = money($item['price_per_unit']);
    if ($price) $price .= ' / ' . e($item['unit_of_measurement']);
}
$typeInitial = strtoupper(substr($cardType, 0, 1));
$ui = system_ui_config();
$businessId = (int)($item['business_id'] ?? 0);
$trust = business_trust_snapshot($businessId);
$ratingAvg = $item['b_rating'] ?? ($trust['rating_average'] ?? 0);
$ratingCount = $item['b_rating_count'] ?? ($trust['rating_count'] ?? 0);
$joinedAt = $item['b_joined'] ?? ($trust['created_at'] ?? null);
$responseLabel = response_time_label(business_response_median_minutes($businessId));
$activityLabel = business_recent_activity_label($businessId);
$cardLabel = trim($title . ($price ? ', ' . strip_tags($price) : '') . ', ' . (($item['subcity'] ? $item['subcity'] . ', ' : '') . ($item['city'] ?: 'Ethiopia')) . ', from ' . ($item['b_name'] ?? 'vendor'));
?>
<a class="card reveal" href="<?= e($href) ?>" aria-label="<?= e($cardLabel) ?>">
  <div class="card-img">
    <?php if ($img): ?><img src="<?= e($img) ?>" alt="<?= e($title) ?>" loading="lazy">
    <?php else: ?><div class="card-placeholder" aria-hidden="true"><span class="icon-chip"><?= e($typeInitial) ?></span></div><?php endif; ?>
    <div class="card-media-badges">
      <?php if (!empty($ui['show_featured_badge']) && !empty($item['is_featured'])): ?><?= listing_badge('featured', 'Featured', 'star') ?><?php endif; ?>
      <?php if (!empty($item['is_promoted'])): ?><?= listing_badge('promoted', 'Promoted', 'trend') ?><?php endif; ?>
    </div>
    <?php if ($discountPercent > 0): ?>
      <?= listing_badge('discount', 'Save ' . $discountPercent . '%', 'tag') ?>
    <?php endif; ?>
  </div>
  <div class="card-body">
    <?php if (!empty($ui['show_card_category'])): ?><div class="card-cat"><?= e($item['c_name'] ?? '') ?></div><?php endif; ?>
    <?php if (!empty($ui['show_card_price'])): ?>
    <div class="card-price-row">
      <div class="card-price"><?= $price ?: '<span class="muted" style="font-size:.85rem;font-weight:600">Negotiable</span>' ?></div>
      <?php if ($oldPrice): ?><div class="card-price-old"><?= e($oldPrice) ?></div><?php endif; ?>
    </div>
    <?php endif; ?>
    <h3 class="card-title"<?= content_lang_attr($title) ?>><?= e($title) ?></h3>
    <?php if ($conditionLabel || !empty($item['delivery_available']) || !empty($item['is_negotiable'])): ?>
    <div class="card-attributes" aria-label="Item highlights">
      <?php if ($conditionLabel): ?><?= listing_badge('condition', $conditionLabel, 'box') ?><?php endif; ?>
      <?php if (!empty($item['delivery_available'])): ?><?= listing_badge('delivery', 'Delivery', 'truck') ?><?php endif; ?>
      <?php if (!empty($item['is_negotiable'])): ?><?= listing_badge('negotiable', 'Negotiable', 'offer') ?><?php endif; ?>
    </div>
    <?php endif; ?>
    <?php if (!empty($ui['show_card_location'])): ?>
    <div class="card-meta">
      <span class="card-ico"><?= system_ui_icon('pin', 'Location') ?></span>
      <span><?= e($item['subcity'] ? $item['subcity'] . ', ' : '') . e($item['city'] ?: 'Ethiopia') ?></span>
    </div>
    <?php endif; ?>
    <?php if (!empty($ui['show_card_vendor'])): ?>
    <div class="card-vendor">
      <span><?= e($item['b_name'] ?? '') ?></span> <?= verified_badge($item['b_verification'] ?? null) ?>
    </div>
    <div class="card-trust">
      <?php if ((float)$ratingAvg > 0): ?><span><?= star_rating($ratingAvg, (int)$ratingCount) ?></span><?php endif; ?>
      <?php if (!empty($joinedAt)): ?><span>Member since <?= date('Y', strtotime($joinedAt)) ?></span><?php endif; ?>
      <?php if ($responseLabel): ?><span><?= e($responseLabel) ?></span><?php endif; ?>
      <?php if ($activityLabel): ?><span><?= e($activityLabel) ?></span><?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</a>
