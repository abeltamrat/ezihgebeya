<?php
/** Renders one listing card. Expects: $cardType ('product'|'service'|'supply'), $item (row joined with business b_*, category c_*). */
$img = listing_image($cardType, $item);
$title = $item[listing_title_col($cardType)];
$href = listing_url($cardType, $item);
$price = '';
if ($cardType === 'product') {
    $price = $item['discount_price'] > 0 ? money($item['discount_price']) : money($item['price']);
} elseif ($cardType === 'service') {
    $price = $item['price_type'] === 'quote_required' ? 'Quote on request'
        : (PRICE_TYPES[$item['price_type']] ?? '') . ' ' . money($item['starting_price']);
} else {
    $price = money($item['price_per_unit']);
    if ($price) $price .= ' / ' . e($item['unit_of_measurement']);
}
$typeInitial = strtoupper(substr($cardType, 0, 1));
$ui = system_ui_config();
?>
<a class="card reveal" href="<?= $href ?>">
  <div class="card-img">
    <?php if ($img): ?><img src="<?= e($img) ?>" alt="<?= e($title) ?>" loading="lazy">
    <?php else: ?><div class="card-placeholder"><span class="icon-chip"><?= e($typeInitial) ?></span></div><?php endif; ?>
    <?php if (!empty($ui['show_featured_badge']) && !empty($item['is_featured'])): ?><span class="badge badge-featured">Featured</span><?php endif; ?>
    <?php if ($cardType === 'product' && $item['discount_price'] > 0 && $item['price'] > 0): ?>
      <span class="badge badge-discount">-<?= round(100 - $item['discount_price'] / $item['price'] * 100) ?>%</span>
    <?php endif; ?>
  </div>
  <div class="card-body">
    <?php if (!empty($ui['show_card_category'])): ?><div class="card-cat"><?= e($item['c_name'] ?? '') ?></div><?php endif; ?>
    <h3 class="card-title"><?= e($title) ?></h3>
    <?php if (!empty($ui['show_card_price'])): ?>
    <div class="card-price"><?= $price ?: '<span class="muted" style="font-size:.85rem;font-weight:600">Negotiable</span>' ?></div>
    <?php endif; ?>
    <?php if (!empty($ui['show_card_location'])): ?>
    <div class="card-meta">
      <span class="card-ico"><?= system_ui_icon('pin', 'Location') ?></span>
      <span><?= e($item['subcity'] ? $item['subcity'] . ', ' : '') . e($item['city'] ?: 'Ethiopia') ?></span>
    </div>
    <?php endif; ?>
    <?php if (!empty($ui['show_card_vendor'])): ?>
    <div class="card-vendor">
      <?= e($item['b_name'] ?? '') ?> <?= verified_badge($item['b_verification'] ?? null) ?>
    </div>
    <?php endif; ?>
  </div>
</a>
