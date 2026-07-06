<?php
$u = require_login();
$pageTitle = 'My Orders';

// upload payment proof / cancel order
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $oid = (int)($_POST['order_id'] ?? 0);
    $order = row("SELECT * FROM orders WHERE id = ? AND customer_id = ?", [$oid, $u['id']]);
    if ($order && ($_POST['do'] ?? '') === 'cancel' && in_array($order['status'], ['pending', 'confirmed'], true)) {
        q("UPDATE orders SET status = 'cancelled' WHERE id = ?", [$oid]);
        flash('Order cancelled.');
    } elseif ($order && ($_POST['do'] ?? '') === 'pay') {
        $ref = trim($_POST['reference_number'] ?? '');
        $methods = payment_methods(false);
        $method = array_key_exists($_POST['payment_method'] ?? '', $methods) ? $_POST['payment_method'] : array_key_first($methods);
        $proof = upload_image($_FILES['proof_image'] ?? [], 'payments');
        if ($ref === '' && !$proof) {
            flash('Add a transaction reference or a proof screenshot.', 'error');
        } else {
            q("INSERT INTO payments (payer_id, business_id, order_id, payment_type, amount, payment_method, reference_number, proof_image)
               VALUES (?,?,?, 'order_payment', ?,?,?,?)",
              [$u['id'], $order['business_id'], $oid, $order['total'], $method, $ref ?: null, $proof]);
            flash('Payment submitted — waiting for confirmation.');
        }
    }
    redirect('account/orders');
}

$orders = rows("SELECT o.*, b.business_name, b.phone b_phone FROM orders o JOIN businesses b ON b.id = o.business_id
    WHERE o.customer_id = ? ORDER BY o.created_at DESC", [$u['id']]);
include __DIR__ . '/../views/layout_top.php';
?>
<div class="container section">
  <h1>📦 My Orders</h1>
  <p><a href="<?= url('account') ?>">← Back to my account</a></p>
  <?php if (!$orders): ?><div class="empty-state">No orders yet. Add products to your <a href="<?= url('cart') ?>">cart</a> to get started.</div><?php endif; ?>

  <?php foreach ($orders as $o):
    $items = rows("SELECT * FROM order_items WHERE order_id = ?", [$o['id']]);
    $pays = rows("SELECT * FROM payments WHERE order_id = ? ORDER BY created_at DESC", [$o['id']]); ?>
  <div class="panel">
    <div class="inq-head">
      <strong><?= e($o['order_number']) ?></strong>
      <span>🏪 <?= e($o['business_name']) ?></span>
      <span class="badge badge-status-<?= e($o['status']) ?>"><?= e(str_replace('_', ' ', $o['status'])) ?></span>
      <span class="muted"><?= time_ago($o['created_at']) ?> · <?= e(str_replace('_', ' ', $o['payment_method'])) ?> · <?= e($o['delivery_option']) ?></span>
    </div>
    <div class="table-wrap"><table class="data-table">
      <?php foreach ($items as $it): ?>
        <tr><td><?= e($it['title']) ?></td><td><?= money($it['unit_price']) ?> × <?= (float)$it['quantity'] ?></td><td><strong><?= money($it['line_total']) ?></strong></td></tr>
      <?php endforeach; ?>
      <tr><td colspan="2"><strong>Total</strong></td><td><strong><?= money($o['total']) ?></strong></td></tr>
    </table></div>

    <?php foreach ($pays as $p): ?>
      <div class="muted small">💳 Payment <?= money($p['amount']) ?> via <?= e(str_replace('_', ' ', $p['payment_method'])) ?>
        <?= $p['reference_number'] ? '· ref ' . e($p['reference_number']) : '' ?>
        <span class="badge badge-status-<?= $p['status'] === 'confirmed' ? 'active' : ($p['status'] === 'rejected' ? 'rejected' : 'pending') ?>"><?= e($p['status']) ?></span></div>
    <?php endforeach; ?>

    <div class="btn-row" style="margin-top:10px">
      <?php if ($o['payment_method'] !== 'cash_on_delivery' && !array_filter($pays, fn($p) => $p['status'] !== 'rejected') && !in_array($o['status'], ['cancelled', 'completed'])): ?>
        <details>
          <summary class="btn btn-outline btn-sm">💳 Submit payment proof</summary>
          <form method="post" enctype="multipart/form-data" class="panel">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="pay"><input type="hidden" name="order_id" value="<?= $o['id'] ?>">
            <label>Method
              <select name="payment_method">
                <?php foreach (payment_methods(false) as $k => $l): ?><option value="<?= $k ?>" <?= $o['payment_method'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
              </select>
            </label>
            <?php if (payment_instructions()): ?><p class="muted small"><?= nl2br(e(payment_instructions())) ?></p><?php endif; ?>
            <label>Transaction reference <input name="reference_number" placeholder="e.g. FT26XXXX / Telebirr ref"></label>
            <label>Proof screenshot <input type="file" name="proof_image" accept="image/*"></label>
            <button class="btn btn-primary btn-sm">Submit</button>
          </form>
        </details>
      <?php endif; ?>
      <?php if (in_array($o['status'], ['pending', 'confirmed'], true)): ?>
        <form method="post" onsubmit="return confirm('Cancel this order?')">
          <?= csrf_field() ?><input type="hidden" name="do" value="cancel"><input type="hidden" name="order_id" value="<?= $o['id'] ?>">
          <button class="btn btn-ghost btn-sm">Cancel order</button>
        </form>
      <?php endif; ?>
      <a class="btn btn-ghost btn-sm" href="tel:<?= e($o['b_phone']) ?>">📞 Call seller</a>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php include __DIR__ . '/../views/layout_bottom.php'; ?>
