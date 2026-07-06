<?php
$u = require_login();
$pageTitle = 'My Account';
$favs = rows("SELECT l.*, b.business_name b_name, b.verification_status b_verification, c.name c_name, c.icon c_icon
    FROM favorites f JOIN products l ON l.id = f.product_id AND l.status = 'active'
    JOIN businesses b ON b.id = l.business_id JOIN categories c ON c.id = l.category_id
    WHERE f.user_id = ? ORDER BY f.created_at DESC", [$u['id']]);
$inqs = rows("SELECT i.*, b.business_name FROM inquiries i JOIN businesses b ON b.id = i.business_id
    WHERE i.customer_id = ? ORDER BY i.created_at DESC LIMIT 50", [$u['id']]);
$myReviews = rows("SELECT r.*, b.business_name FROM reviews r JOIN businesses b ON b.id = r.business_id
    WHERE r.reviewer_id = ? ORDER BY r.created_at DESC LIMIT 50", [$u['id']]);
include __DIR__ . '/../views/layout_top.php';
?>
<div class="container section">
  <h1>Hi, <?= e($u['full_name']) ?> 👋</h1>
  <p class="muted"><?= ucfirst(str_replace('_', ' ', $u['account_type'])) ?> account · <?= e($u['phone']) ?>
    <?= $u['phone_verified_at'] ? '<span class="badge badge-verified">✔ phone verified</span>' : '' ?></p>
  <?php if (!$u['phone_verified_at']): ?>
    <div class="flash flash-error">Your phone number is not verified yet. <a href="<?= url('verify') ?>">Verify now</a> to secure your account.</div>
  <?php endif; ?>
  <div class="btn-row">
    <a class="btn btn-outline" href="<?= url('account/orders') ?>">📦 My Orders (<?= (int)val("SELECT COUNT(*) FROM orders WHERE customer_id = ?", [$u['id']]) ?>)</a>
    <a class="btn btn-ghost" href="<?= url('cart') ?>">🛒 Cart (<?= cart_count() ?>)</a>
  </div>

  <h2 class="section-gap">Saved products (<?= count($favs) ?>)</h2>
  <?php if (!$favs): ?><p class="muted">Nothing saved yet. Browse <a href="<?= url('products') ?>">furniture</a> and tap 🤍 Save.</p>
  <?php else: ?>
    <div class="grid">
      <?php foreach ($favs as $item) { $cardType = 'product'; include __DIR__ . '/../views/partial_card.php'; } ?>
    </div>
  <?php endif; ?>

  <h2 class="section-gap">My inquiries (<?= count($inqs) ?>)</h2>
  <?php if (!$inqs): ?><p class="muted">No inquiries sent yet.</p>
  <?php else: ?>
  <div class="table-wrap"><table class="data-table">
    <tr><th>Date</th><th>To</th><th>Listing</th><th>Message</th><th>Status</th><th></th></tr>
    <?php foreach ($inqs as $i): ?>
    <tr>
      <td><?= time_ago($i['created_at']) ?></td>
      <td><?= e($i['business_name']) ?></td>
      <td><?= e($i['listing_title'] ?: ucfirst($i['listing_type'])) ?></td>
      <td class="truncate"><?= e(mb_substr($i['message'], 0, 60)) ?></td>
      <td><span class="badge badge-status-<?= e($i['status']) ?>"><?= e($i['status']) ?></span></td>
      <td><a class="btn btn-ghost btn-sm" href="<?= url('inquiries/' . $i['id']) ?>">💬 Chat</a></td>
    </tr>
    <?php endforeach; ?>
  </table></div>
  <?php endif; ?>

  <h2 class="section-gap">My reviews (<?= count($myReviews) ?>)</h2>
  <?php if (!$myReviews): ?><p class="muted">No reviews written yet.</p>
  <?php else: ?>
  <?php foreach ($myReviews as $r): ?>
    <div class="review">
      <div class="review-head">
        <strong><?= e($r['business_name']) ?></strong>
        <span class="stars"><?= str_repeat('★', (int)$r['rating']) ?></span>
        <span class="badge badge-status-<?= e($r['status']) ?>"><?= e($r['status']) ?></span>
        <span class="muted"><?= time_ago($r['created_at']) ?></span>
      </div>
      <p><?= nl2br(e($r['comment'])) ?></p>
    </div>
  <?php endforeach; endif; ?>
</div>
<?php include __DIR__ . '/../views/layout_bottom.php'; ?>
