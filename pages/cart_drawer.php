<?php
/**
 * HTMX cart drawer fragment — returns HTML only (no layout).
 * Loaded by the cart drawer via hx-get="/cart/drawer".
 */
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');

$groups = cart_resolve();
$grand  = array_sum(array_column($groups, 'subtotal'));

if (!$groups): ?>
<div class="drawer-empty">
  <div class="drawer-empty-icon">🛒</div>
  <p>Your cart is empty.</p>
  <a href="<?= url('products') ?>" class="btn btn-primary btn-sm" style="margin-top:12px">Browse furniture</a>
</div>
<?php return; endif; ?>

<?php foreach ($groups as $g): ?>
  <p class="small muted" style="margin-bottom:8px;font-weight:750"><?= e($g['business_name']) ?></p>
  <?php foreach ($g['items'] as $it): ?>
  <div class="drawer-item">
    <?php $imgUrl = null;
    if ($it['type'] === 'product') {
        $m = row("SELECT file_url FROM product_media WHERE product_id = ? ORDER BY is_primary DESC LIMIT 1", [$it['id']]);
        $imgUrl = $m ? thumb_url($m['file_url']) : null;
    }
    ?>
    <?php if ($imgUrl): ?>
      <img class="drawer-item-img" src="<?= e($imgUrl) ?>" alt="">
    <?php else: ?>
      <span class="drawer-item-img" style="background:var(--brand-soft);display:inline-block"></span>
    <?php endif; ?>
    <div class="drawer-item-info">
      <div class="drawer-item-title">
        <a href="<?= url(LISTING_TABLES[$it['type']] . '/' . $it['slug']) ?>"><?= e($it['title']) ?></a>
      </div>
      <div class="drawer-item-meta"><?= number_format($it['qty'], 2, '.', '') ?> × <?= money($it['price']) ?></div>
    </div>
    <div class="drawer-item-price"><?= money($it['line']) ?></div>
  </div>
  <?php endforeach; ?>
  <div style="text-align:right;padding:8px 0 14px;font-size:.82rem;color:var(--text-2)">
    Subtotal: <strong><?= money($g['subtotal']) ?></strong>
  </div>
<?php endforeach; ?>

<div style="border-top:1px solid var(--line);padding-top:12px;text-align:right">
  <span style="font-size:1.05rem;font-weight:850;color:var(--ink)">Grand total: <?= money($grand) ?></span>
</div>
