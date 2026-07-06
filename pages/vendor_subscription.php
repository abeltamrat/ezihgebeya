<?php
$u = require_vendor();
$biz = my_business($u);
if (!$biz) { flash('Create your business profile first.', 'error'); redirect('vendor/business'); }
$pageTitle = 'Subscription';
$plan = current_plan($biz['id']);
$used = listing_count($biz['id']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $newPlan = $_POST['plan'] ?? '';
    $months = max(1, min(12, (int)($_POST['months'] ?? 1)));
    $ref = trim($_POST['reference_number'] ?? '');
    $methods = payment_methods(false);
    $method = array_key_exists($_POST['payment_method'] ?? '', $methods) ? $_POST['payment_method'] : array_key_first($methods);
    if (isset(plans()[$newPlan]) && $newPlan !== 'free') {
        $cost = plans()[$newPlan]['price'] * $months;
        q("INSERT INTO subscriptions (business_id, plan, months, status) VALUES (?,?,?, 'pending')", [$biz['id'], $newPlan, $months]);
        $sid = (int)db()->lastInsertId();
        $proof = upload_image($_FILES['proof_image'] ?? [], 'payments');
        q("INSERT INTO payments (payer_id, business_id, subscription_id, payment_type, amount, payment_method, reference_number, proof_image)
           VALUES (?,?,?, 'subscription_payment', ?,?,?,?)", [$u['id'], $biz['id'], $sid, $cost, $method, $ref ?: null, $proof]);
        flash('Upgrade requested (' . money($cost) . '). Your plan activates once admin confirms payment.');
    }
    redirect('vendor/subscription');
}

$history = rows("SELECT * FROM subscriptions WHERE business_id = ? ORDER BY created_at DESC", [$biz['id']]);
include __DIR__ . '/../views/layout_top.php';
?>
<div class="container section dash-layout">
  <?php include __DIR__ . '/../views/vendor_nav.php'; ?>
  <div class="dash-main">
    <h1>⭐ Subscription</h1>
    <?php $PL = plans(); ?>
    <p>Current plan: <strong><?= $PL[$plan]['label'] ?></strong>
      · Listings used: <strong><?= $used ?><?= $PL[$plan]['listings'] >= 0 ? ' / ' . $PL[$plan]['listings'] : '' ?></strong></p>
    <?php if (payment_instructions()): ?>
      <div class="panel"><h4>How to pay</h4><p class="muted small"><?= nl2br(e(payment_instructions())) ?></p></div>
    <?php endif; ?>

    <div class="stat-grid" style="grid-template-columns:repeat(auto-fill,minmax(220px,1fr))">
      <?php foreach ($PL as $k => $p): ?>
      <div class="stat-card" style="text-align:left; <?= $k === $plan ? 'border-color:var(--brand);border-width:2px' : '' ?>">
        <h3><?= $p['label'] ?> <?= $k === $plan ? '✔' : '' ?></h3>
        <div class="stat-num" style="font-size:1.2rem"><?= $p['price'] ? number_format($p['price']) . ' ' . e(sys('general.currency_label', 'ETB')) . '/mo' : 'Free' ?></div>
        <ul class="small" style="padding-left:18px;color:var(--muted)">
          <li><?= $p['listings'] < 0 ? 'Unlimited' : $p['listings'] ?> listings</li>
          <li><?= $p['videos'] < 0 ? 'Unlimited' : $p['videos'] ?> video<?= $p['videos'] === 1 ? '' : 's' ?></li>
          <?php if ($k === 'pro' || $k === 'premium'): ?><li>Featured profile eligible</li><?php endif; ?>
          <?php if ($k === 'premium'): ?><li>Premium verified badge · AR product support</li><?php endif; ?>
        </ul>
        <?php if ($k !== 'free' && $k !== $plan): ?>
        <details><summary class="btn btn-outline btn-sm">Upgrade</summary>
          <form method="post" enctype="multipart/form-data" style="margin-top:8px">
            <?= csrf_field() ?>
            <input type="hidden" name="plan" value="<?= $k ?>">
            <label>Months <input type="number" name="months" value="1" min="1" max="12"></label>
            <label>Payment method
              <select name="payment_method"><?php foreach (payment_methods(false) as $mk => $ml): ?><option value="<?= $mk ?>"><?= $ml ?></option><?php endforeach; ?></select>
            </label>
            <label>Reference <input name="reference_number"></label>
            <label>Proof <input type="file" name="proof_image" accept="image/*"></label>
            <button class="btn btn-primary btn-sm">Request upgrade</button>
          </form>
        </details>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>

    <?php if ($history): ?>
    <h2 class="section-gap">History</h2>
    <div class="table-wrap"><table class="data-table">
      <tr><th>Plan</th><th>Months</th><th>Status</th><th>Period</th><th>Requested</th></tr>
      <?php foreach ($history as $s): ?>
      <tr>
        <td><?= $PL[$s['plan']]['label'] ?? e($s['plan']) ?></td>
        <td><?= (int)$s['months'] ?></td>
        <td><span class="badge badge-status-<?= e($s['status']) ?>"><?= e($s['status']) ?></span></td>
        <td class="small"><?= $s['starts_at'] ? date('M j, Y', strtotime($s['starts_at'])) . ' – ' . date('M j, Y', strtotime($s['ends_at'])) : '—' ?></td>
        <td class="small"><?= time_ago($s['created_at']) ?></td>
      </tr>
      <?php endforeach; ?>
    </table></div>
    <?php endif; ?>
  </div>
</div>
<?php include __DIR__ . '/../views/layout_bottom.php'; ?>
