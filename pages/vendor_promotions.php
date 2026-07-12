<?php
$u = require_vendor();
$biz = my_business($u);
if (!$biz) { flash('Create your business profile first.', 'error'); redirect('vendor/business'); }
$pageTitle = 'Promotions';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['do'] ?? '') === 'cancel') {
        $p = row("SELECT * FROM promotions WHERE id = ? AND business_id = ?", [(int)$_POST['id'], $biz['id']]);
        if ($p && in_array($p['status'], ['pending', 'scheduled', 'active', 'paused'], true)) {
            q("UPDATE promotions SET status = 'cancelled' WHERE id = ?", [$p['id']]);
            if ($p['status'] === 'active') promotion_apply($p, false);
            flash('Promotion cancelled.');
        }
        redirect('vendor/promotions');
    }

    $ptype = $_POST['promotion_type'] ?? '';
    $weeks = max(1, min(8, (int)($_POST['weeks'] ?? 1)));
    $target = $_POST['target'] ?? ''; // "type:id"
    $ref = trim($_POST['reference_number'] ?? '');
    $methods = payment_methods(false);
    $method = array_key_exists($_POST['payment_method'] ?? '', $methods) ? $_POST['payment_method'] : array_key_first($methods);

    if (!isset(promo_types()[$ptype])) $errors[] = 'Select a promotion type.';
    $promotableType = null; $promotableId = null;
    if (!$errors) {
        $kind = promo_types()[$ptype]['target'];
        if ($kind === 'business') { $promotableType = 'business'; $promotableId = $biz['id']; }
        else {
            [$tt, $tid] = array_pad(explode(':', $target), 2, 0);
            $tid = (int)$tid;
            if ($kind === 'video' && $tt === 'video' && val("SELECT COUNT(*) FROM video_posts WHERE id = ? AND business_id = ?", [$tid, $biz['id']])) {
                $promotableType = 'video'; $promotableId = $tid;
            } elseif ($kind === 'listing' && isset(LISTING_TABLES[$tt]) && val("SELECT COUNT(*) FROM `" . LISTING_TABLES[$tt] . "` WHERE id = ? AND business_id = ?", [$tid, $biz['id']])) {
                $promotableType = $tt; $promotableId = $tid;
            } else $errors[] = 'Select a valid target from your own listings/videos.';
        }
    }
    if (!$errors && $biz['verification_status'] === 'unverified') $errors[] = 'Only verified businesses can buy promotions (§30.6). Submit your TIN/license first.';

    if (!$errors) {
        $cost = promo_types()[$ptype]['price'] * $weeks;
        q("INSERT INTO promotions (business_id, promotable_type, promotable_id, promotion_type, duration_weeks, city, subcity, budget, status)
           VALUES (?,?,?,?,?,?,?,?, 'pending')",
          [$biz['id'], $promotableType, $promotableId, $ptype, $weeks, $biz['city'], $biz['subcity'], $cost]);
        $pid = (int)db()->lastInsertId();
        $proof = upload_image($_FILES['proof_image'] ?? [], 'payments');
        q("INSERT INTO payments (payer_id, business_id, promotion_id, payment_type, amount, payment_method, reference_number, proof_image)
           VALUES (?,?,?, 'ad_payment', ?,?,?,?)", [$u['id'], $biz['id'], $pid, $cost, $method, $ref ?: null, $proof]);
        flash('Promotion request submitted (' . money($cost) . '). It activates once admin confirms your payment.');
        redirect('vendor/promotions');
    }
}

$promos = rows("SELECT * FROM promotions WHERE business_id = ? ORDER BY created_at DESC", [$biz['id']]);
$targets = [];
foreach (['product' => ['products', 'title'], 'service' => ['services', 'title'], 'supply' => ['supplies', 'name']] as $t => [$tb, $tc]) {
    foreach (rows("SELECT id, `$tc` t FROM `$tb` WHERE business_id = ? AND status = 'active'", [$biz['id']]) as $r)
        $targets[] = ['key' => "$t:{$r['id']}", 'label' => $r['t'] . " ($t)", 'kind' => 'listing'];
}
foreach (rows("SELECT id, COALESCE(title, original_url) t FROM video_posts WHERE business_id = ? AND status = 'approved'", [$biz['id']]) as $r)
    $targets[] = ['key' => "video:{$r['id']}", 'label' => mb_substr($r['t'], 0, 50) . ' (video)', 'kind' => 'video'];

include __DIR__ . '/../views/layout_top.php';
?>
<div class="container section dash-layout">
  <?php include __DIR__ . '/../views/vendor_nav.php'; ?>
  <div class="dash-main">
    <h1>📣 Promotions</h1>
    <p class="muted">Boost visibility: featured placement, top of search, homepage banner or video feed boost. Pay via Telebirr/CBE/bank and admin activates after confirming.</p>
    <?php if ($errors): ?><div role="alert" class="alert alert-error mb-3"><ul class="list-disc list-inside text-sm"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

    <form class="panel form-2col" method="post" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <label>Promotion type
        <select name="promotion_type" id="promo-type">
          <?php foreach (promo_types() as $k => $pt): ?>
            <option value="<?= $k ?>" data-price="<?= $pt['price'] ?>" data-target="<?= $pt['target'] ?>"><?= $pt['label'] ?> — <?= number_format($pt['price']) ?> <?= e(sys('general.currency_label', 'ETB')) ?>/week</option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Duration (weeks) <input type="number" name="weeks" id="promo-weeks" min="1" max="8" value="1"></label>
      <label class="span2" id="promo-target-wrap">Target
        <select name="target" id="promo-target">
          <?php foreach ($targets as $t): ?><option value="<?= e($t['key']) ?>" data-kind="<?= $t['kind'] ?>"><?= e($t['label']) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label>Payment method
        <select name="payment_method">
          <?php foreach (payment_methods(false) as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?>
        </select>
      </label>
      <label>Transaction reference <input name="reference_number" placeholder="Payment ref number"></label>
      <label class="span2">Payment proof screenshot <input type="file" name="proof_image" accept="image/*"></label>
      <div class="span2">
        <button class="btn btn-primary">Request promotion — <span id="promo-cost"><?= number_format(PROMO_TYPES['category_featured']['price']) ?></span> ETB</button>
      </div>
    </form>

    <h2 class="section-gap">My promotions</h2>
    <?php if (!$promos): ?><div class="empty-state">No promotions yet.</div><?php endif; ?>
    <div class="table-wrap"><table class="data-table">
      <?php if ($promos): ?><tr><th>Type</th><th>Target</th><th>Weeks</th><th>Cost</th><th>Status</th><th>Runs</th><th></th></tr><?php endif; ?>
      <?php foreach ($promos as $p): ?>
      <tr>
        <td><?= e(PROMO_TYPES[$p['promotion_type']]['label'] ?? $p['promotion_type']) ?></td>
        <td><?= e($p['promotable_type']) ?> #<?= $p['promotable_id'] ?></td>
        <td><?= (int)$p['duration_weeks'] ?></td>
        <td><?= money($p['budget']) ?></td>
        <td><span class="badge badge-status-<?= e($p['status']) ?>"><?= e($p['status']) ?></span></td>
        <td class="small"><?= $p['starts_at'] ? date('M j', strtotime($p['starts_at'])) . ' – ' . date('M j', strtotime($p['ends_at'])) : '—' ?></td>
        <td>
          <?php if (in_array($p['status'], ['pending', 'scheduled', 'active', 'paused'], true)): ?>
          <form method="post" onsubmit="return confirm('Cancel this promotion?')">
            <?= csrf_field() ?><input type="hidden" name="do" value="cancel"><input type="hidden" name="id" value="<?= $p['id'] ?>">
            <button class="btn btn-ghost btn-sm">Cancel</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </table></div>
  </div>
</div>
<script>
(function () {
  var type = document.getElementById('promo-type'), weeks = document.getElementById('promo-weeks'),
      cost = document.getElementById('promo-cost'), target = document.getElementById('promo-target'),
      wrap = document.getElementById('promo-target-wrap');
  function upd() {
    var o = type.selectedOptions[0];
    cost.textContent = (parseInt(o.dataset.price) * Math.max(1, parseInt(weeks.value) || 1)).toLocaleString();
    var kind = o.dataset.target;
    wrap.style.display = kind === 'business' ? 'none' : '';
    Array.prototype.forEach.call(target.options, function (t) { t.hidden = kind !== 'business' && t.dataset.kind !== kind; });
    if (target.selectedOptions[0] && target.selectedOptions[0].hidden) target.value = '';
  }
  type.addEventListener('change', upd); weeks.addEventListener('input', upd); upd();
})();
</script>
<?php include __DIR__ . '/../views/layout_bottom.php'; ?>
