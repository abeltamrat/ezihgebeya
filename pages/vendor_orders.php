<?php
$u = require_vendor();
$biz = my_business($u);
if (!$biz) { flash('Create your business profile first.', 'error'); redirect('vendor/business'); }
$pageTitle = 'Orders';
$flow = ['pending', 'confirmed', 'deposit_paid', 'processing', 'ready_for_delivery', 'out_for_delivery', 'delivered', 'completed', 'cancelled', 'refunded', 'disputed'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $oid = (int)($_POST['order_id'] ?? 0);
    if (($_POST['do'] ?? '') === 'status' && in_array($_POST['status'] ?? '', $flow, true)) {
        $o = row("SELECT * FROM orders WHERE id = ? AND business_id = ?", [$oid, $biz['id']]);
        if ($o && $o['status'] !== $_POST['status']) {
            q("UPDATE orders SET status = ? WHERE id = ?", [$_POST['status'], $oid]);
            notify((int)$o['customer_id'], 'order_status_changed',
                'Order ' . $o['order_number'] . ' is now ' . str_replace('_', ' ', $_POST['status']), 'account/orders', '', true);
        }
        flash('Order updated.');
    } elseif (($_POST['do'] ?? '') === 'confirm_payment') {
        $p = row("SELECT p.* FROM payments p JOIN orders o ON o.id = p.order_id WHERE p.id = ? AND o.business_id = ?", [(int)$_POST['payment_id'], $biz['id']]);
        if ($p && $p['status'] === 'pending') {
            q("UPDATE payments SET status = 'confirmed', confirmed_by = ? WHERE id = ?", [$u['id'], $p['id']]);
            q("UPDATE orders SET status = 'deposit_paid' WHERE id = ? AND status IN ('pending','confirmed')", [$p['order_id']]);
            notify((int)$p['payer_id'], 'payment_received', 'Your payment of ' . money($p['amount']) . ' was confirmed', 'account/orders');
            flash('Payment confirmed.');
        }
    }
    redirect('vendor/orders');
}

$orders = rows("SELECT o.*, u.full_name customer FROM orders o JOIN users u ON u.id = o.customer_id
    WHERE o.business_id = ? ORDER BY (o.status='pending') DESC, o.created_at DESC LIMIT 200", [$biz['id']]);
include __DIR__ . '/../views/layout_top.php';
?>
<div class="container section dash-layout">
  <?php include __DIR__ . '/../views/vendor_nav.php'; ?>
  <div class="dash-main">
    <h1>Orders (<?= count($orders) ?>)</h1>
    <?php if (!$orders): ?><div class="empty-state">No orders yet.</div><?php endif; ?>
    <?php foreach ($orders as $o):
      $items = rows("SELECT * FROM order_items WHERE order_id = ?", [$o['id']]);
      $pays = rows("SELECT * FROM payments WHERE order_id = ?", [$o['id']]); ?>
    <div class="panel">
      <div class="inq-head">
        <strong><?= e($o['order_number']) ?></strong>
        <span><?= e($o['customer']) ?> · <a href="tel:<?= e($o['phone']) ?>">📞 <?= e($o['phone']) ?></a></span>
        <span class="badge badge-status-<?= e($o['status']) ?>"><?= e(str_replace('_', ' ', $o['status'])) ?></span>
        <span class="muted"><?= time_ago($o['created_at']) ?> · <?= e(str_replace('_', ' ', $o['payment_method'])) ?> · <?= e($o['delivery_option']) ?></span>
      </div>
      <?php if ($o['delivery_option'] === 'delivery'): ?>
        <div class="muted small">📍 <?= e(implode(', ', array_filter([$o['delivery_address'], $o['subcity'], $o['city']]))) ?></div>
      <?php endif; ?>
      <?php if ($o['note']): ?><p class="muted small">📝 <?= e($o['note']) ?></p><?php endif; ?>
      <div class="table-wrap"><table class="data-table">
        <?php foreach ($items as $it): ?>
          <tr><td><?= e($it['title']) ?></td><td><?= money($it['unit_price']) ?> × <?= (float)$it['quantity'] ?></td><td><strong><?= money($it['line_total']) ?></strong></td></tr>
        <?php endforeach; ?>
        <tr><td colspan="2"><strong>Total</strong></td><td><strong><?= money($o['total']) ?></strong></td></tr>
      </table></div>
      <?php foreach ($pays as $p): ?>
        <div class="inq-status-form">
          💳 <?= money($p['amount']) ?> via <?= e(str_replace('_', ' ', $p['payment_method'])) ?>
          <?= $p['reference_number'] ? '· ref <b>' . e($p['reference_number']) . '</b>' : '' ?>
          <?php if ($p['proof_image']): ?><a href="<?= e(url('download/payment/' . $p['id'])) ?>" target="_blank">view proof</a><?php endif; ?>
          <span class="badge badge-status-<?= $p['status'] === 'confirmed' ? 'active' : ($p['status'] === 'rejected' ? 'rejected' : 'pending') ?>"><?= e($p['status']) ?></span>
          <?php if ($p['status'] === 'pending'): ?>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="confirm_payment"><input type="hidden" name="payment_id" value="<?= $p['id'] ?>">
              <button class="btn btn-outline btn-sm">✅ Confirm received</button></form>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      <form method="post" class="inq-status-form">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="status"><input type="hidden" name="order_id" value="<?= $o['id'] ?>">
        <select name="status">
          <?php foreach ($flow as $s): ?><option value="<?= $s ?>" <?= $o['status'] === $s ? 'selected' : '' ?>><?= str_replace('_', ' ', $s) ?></option><?php endforeach; ?>
        </select>
        <button class="btn btn-outline btn-sm">Update status</button>
      </form>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php include __DIR__ . '/../views/layout_bottom.php'; ?>
