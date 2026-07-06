<?php
$u = require_login();
$pageTitle = 'Checkout';
$groups = cart_resolve();
if (!$groups) { flash('Your cart is empty.', 'error'); redirect('cart'); }
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $delivery = $_POST['delivery_option'] === 'delivery' ? 'delivery' : 'pickup';
    $address = trim($_POST['delivery_address'] ?? '');
    $city = $_POST['city'] ?? '';
    $subcity = trim($_POST['subcity'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $note = trim($_POST['note'] ?? '');
    $enabledMethods = payment_methods(); // admin → Settings → Payments
    $method = array_key_exists($_POST['payment_method'] ?? '', $enabledMethods) ? $_POST['payment_method'] : array_key_first($enabledMethods);

    if (strlen($phone) < 9) $errors[] = 'Phone number required.';
    if ($delivery === 'delivery' && ($address === '' || !isset(CITIES[$city]))) $errors[] = 'Delivery address and city required.';

    if (!$errors) {
        $orderNums = [];
        foreach ($groups as $g) {
            $num = order_number();
            q("INSERT INTO orders (order_number, customer_id, business_id, status, delivery_option, delivery_address, city, subcity, phone, note, subtotal, total, payment_method)
               VALUES (?,?,?, 'pending', ?,?,?,?,?,?,?,?,?)",
              [$num, $u['id'], $g['business_id'], $delivery, $address ?: null, $city ?: null, $subcity ?: null,
               $phone, $note ?: null, $g['subtotal'], $g['subtotal'], $method]);
            $oid = (int)db()->lastInsertId();
            foreach ($g['items'] as $it) {
                q("INSERT INTO order_items (order_id, listing_type, listing_id, title, unit_price, quantity, line_total)
                   VALUES (?,?,?,?,?,?,?)", [$oid, $it['type'], $it['id'], $it['title'], $it['price'], $it['qty'], $it['line']]);
            }
            $orderNums[] = $num;
            notify_business((int)$g['business_id'], 'order_created',
                'New order ' . $num . ' — ' . money($g['subtotal']), 'vendor/orders', '', true);
        }
        unset($_SESSION['cart']);
        flash('Order' . (count($orderNums) > 1 ? 's' : '') . ' placed: ' . implode(', ', $orderNums) . '. The seller will confirm availability.' .
              ($method !== 'cash_on_delivery' ? ' You can upload your payment proof from My Orders.' : ''));
        redirect('account/orders');
    }
}
$grand = array_sum(array_column($groups, 'subtotal'));
include __DIR__ . '/../views/layout_top.php';
?>
<div class="container section detail-layout">
  <div class="detail-main">
    <h1>Checkout</h1>
    <?php foreach ($errors as $er): ?><div class="flash flash-error"><?= e($er) ?></div><?php endforeach; ?>
    <form class="panel" method="post" id="checkout-form">
      <?= csrf_field() ?>
      <h3>Delivery</h3>
      <label class="check"><input type="radio" name="delivery_option" value="pickup" checked> Pickup from seller</label>
      <label class="check"><input type="radio" name="delivery_option" value="delivery"> Deliver to me (fee arranged with seller)</label>
      <label>Phone * <input name="phone" required value="<?= e($u['phone']) ?>"></label>
      <label>City
        <select name="city" id="city-select">
          <option value="">Select…</option>
          <?php foreach (array_keys(CITIES) as $c): ?><option><?= e($c) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label>Sub-city
        <select name="subcity" id="subcity-select" data-selected=""><option value="">Select…</option></select>
      </label>
      <label>Delivery address <textarea name="delivery_address" rows="2" placeholder="Area, street, building…"></textarea></label>
      <label>Note to seller <textarea name="note" rows="2"></textarea></label>

      <h3>Payment</h3>
      <?php $first = true; foreach (payment_methods() as $k => $label): ?>
        <label class="check"><input type="radio" name="payment_method" value="<?= $k ?>" <?= $first ? 'checked' : '' ?>>
          <?= $label ?><?= $k === 'cash_on_delivery' ? '' : ' (manual confirmation — upload proof after ordering)' ?></label>
      <?php $first = false; endforeach; ?>
      <?php if (payment_instructions()): ?>
        <p class="muted small"><?= nl2br(e(payment_instructions())) ?></p>
      <?php endif; ?>
      <button class="btn btn-primary btn-lg btn-block">Place order<?= count($groups) > 1 ? 's' : '' ?> — <?= money($grand) ?></button>
    </form>
  </div>
  <aside class="detail-side">
    <div class="panel">
      <h3>Order summary</h3>
      <?php foreach ($groups as $g): ?>
        <strong>🏪 <?= e($g['business_name']) ?></strong>
        <?php foreach ($g['items'] as $it): ?>
          <div class="bar-row"><span><?= e($it['title']) ?> × <?= $it['qty'] ?></span><b><?= money($it['line']) ?></b></div>
        <?php endforeach; ?>
      <?php endforeach; ?>
      <div class="bar-row" style="border-top:2px solid var(--line)"><span><strong>Grand total</strong></span><b><?= money($grand) ?></b></div>
      <p class="muted small">Payments stay between you and the seller in this version — the platform records and tracks them.</p>
    </div>
  </aside>
</div>
<?php include __DIR__ . '/../views/layout_bottom.php'; ?>
