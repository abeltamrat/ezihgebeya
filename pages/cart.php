<?php
$pageTitle = 'My Cart';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = $_POST['do'] ?? 'add';
    $type = $_POST['listing_type'] ?? '';
    $id = (int)($_POST['listing_id'] ?? 0);
    $qty = (float)($_POST['qty'] ?? 1);

    if (in_array($type, ['product', 'supply'], true) && $id) {
        if ($do === 'add') {
            $t = LISTING_TABLES[$type];
            if (val("SELECT COUNT(*) FROM `$t` WHERE id = ? AND status = 'active'", [$id])) {
                cart_add($type, $id, max(1, $qty));
                flash('Added to cart.');
            }
        } elseif ($do === 'update') {
            cart_set($type, $id, $qty);
        } elseif ($do === 'remove') {
            cart_set($type, $id, 0);
            flash('Removed from cart.');
        }
    }
    if (($do === 'add') && ($_POST['back'] ?? '')) {
        $ref = $_SERVER['HTTP_REFERER'] ?? '';
        redirect($ref ? ltrim(substr(parse_url($ref, PHP_URL_PATH), strlen(BASE_URL)), '/') : 'cart');
    }
    redirect('cart');
}

$groups = cart_resolve();
$grand = array_sum(array_column($groups, 'subtotal'));
include __DIR__ . '/../views/layout_top.php';
?>
<div class="container section">
  <h1>🛒 My Cart</h1>
  <?php if (!$groups): ?>
    <div class="empty-state">Your cart is empty. Browse <a href="<?= url('products') ?>">furniture</a> or <a href="<?= url('supplies') ?>">supplies</a>.</div>
  <?php else: ?>
    <?php foreach ($groups as $g): ?>
    <div class="panel">
      <h3>🏪 <?= e($g['business_name']) ?></h3>
      <div class="table-wrap"><table class="data-table">
        <tr><th>Item</th><th>Unit price</th><th>Qty</th><th>Total</th><th></th></tr>
        <?php foreach ($g['items'] as $it): ?>
        <tr>
          <td><a href="<?= url(LISTING_TABLES[$it['type']] . '/' . e($it['slug'])) ?>"><?= e($it['title']) ?></a></td>
          <td><?= money($it['price']) ?> / <?= e($it['unit']) ?></td>
          <td>
            <form method="post" class="form-inline">
              <?= csrf_field() ?>
              <input type="hidden" name="do" value="update">
              <input type="hidden" name="listing_type" value="<?= $it['type'] ?>">
              <input type="hidden" name="listing_id" value="<?= $it['id'] ?>">
              <input type="number" name="qty" value="<?= $it['qty'] ?>" min="0" step="any" style="width:80px">
              <button class="btn btn-ghost btn-sm">↻</button>
            </form>
          </td>
          <td><strong><?= money($it['line']) ?></strong></td>
          <td>
            <form method="post">
              <?= csrf_field() ?>
              <input type="hidden" name="do" value="remove">
              <input type="hidden" name="listing_type" value="<?= $it['type'] ?>">
              <input type="hidden" name="listing_id" value="<?= $it['id'] ?>">
              <button class="btn btn-ghost btn-sm" title="Remove">🗑</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </table></div>
      <p style="text-align:right"><strong>Subtotal: <?= money($g['subtotal']) ?></strong></p>
    </div>
    <?php endforeach; ?>
    <div class="cta-banner">
      <div>
        <h2>Grand total: <?= money($grand) ?></h2>
        <p>Delivery cost is arranged directly with each seller. One order is created per shop.</p>
      </div>
      <a class="btn btn-primary btn-lg" href="<?= url('checkout') ?>">Proceed to checkout →</a>
    </div>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/../views/layout_bottom.php'; ?>
