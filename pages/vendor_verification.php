<?php
/** Business verification workflow (§5.2): submit documents → admin review → badge. */
$u = require_vendor();
$biz = my_business($u);
if (!$biz) { flash('Create your business profile first.', 'error'); redirect('vendor/business'); }
$pageTitle = 'Verification';

$docTypes = [
    'business_license' => 'Business license',
    'tin_certificate'  => 'TIN certificate',
    'national_id'      => 'Fayda / National ID',
    'shop_photo'       => 'Shop or workshop photo',
    'portfolio'        => 'Previous work / portfolio',
];
$levels = [
    'document_verified' => 'Document verified — license/TIN checked',
    'location_verified' => 'Location verified — documents + physical address',
];

$open = row("SELECT * FROM verification_requests WHERE business_id = ? AND status IN ('pending','changes_requested')
             ORDER BY id DESC LIMIT 1", [$biz['id']]);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $level = array_key_exists($_POST['requested_level'] ?? '', $levels) ? $_POST['requested_level'] : 'document_verified';
    $msg = trim($_POST['message'] ?? '');

    $files = [];
    foreach ($docTypes as $key => $label) {
        $f = $_FILES['doc_' . $key] ?? null;
        if ($f && ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $path = upload_image($f, 'verification');
            if ($path) $files[] = [$key, $path];
        }
    }

    if ($open && ($_POST['do'] ?? '') === 'update') {
        // resubmission after "changes requested"
        foreach ($files as [$type, $path]) {
            q("INSERT INTO verification_documents (request_id, doc_type, file_url) VALUES (?,?,?)", [$open['id'], $type, $path]);
        }
        q("UPDATE verification_requests SET status = 'pending', message = COALESCE(NULLIF(?,''), message) WHERE id = ?", [$msg, $open['id']]);
        flash('Verification request updated and sent back for review.');
    } elseif (!$open) {
        if (!$files) {
            flash('Attach at least one document (license, TIN, ID or shop photo).', 'error');
            redirect('vendor/verification');
        }
        q("INSERT INTO verification_requests (business_id, requested_level, message) VALUES (?,?,?)",
          [$biz['id'], $level, $msg ?: null]);
        $reqId = (int)db()->lastInsertId();
        foreach ($files as [$type, $path]) {
            q("INSERT INTO verification_documents (request_id, doc_type, file_url) VALUES (?,?,?)", [$reqId, $type, $path]);
        }
        foreach (rows("SELECT id FROM users WHERE account_type IN ('admin','super_admin') AND status = 'active'") as $adm) {
            notify((int)$adm['id'], 'verification_request', $biz['business_name'] . ' submitted a verification request', 'admin/verification');
        }
        flash('Verification request submitted — an admin will review your documents.');
    }
    redirect('vendor/verification');
}

$history = rows("SELECT * FROM verification_requests WHERE business_id = ? ORDER BY id DESC", [$biz['id']]);
$docsByReq = [];
if ($history) {
    $in = implode(',', array_map(fn($r) => (int)$r['id'], $history));
    foreach (rows("SELECT * FROM verification_documents WHERE request_id IN ($in)") as $d) $docsByReq[$d['request_id']][] = $d;
}
include __DIR__ . '/../views/layout_top.php';
?>
<div class="container section dash-layout">
  <?php include __DIR__ . '/../views/vendor_nav.php'; ?>
  <div class="dash-main">
    <h1>🛡 Verification</h1>
    <p class="muted">Current level: <?= verified_badge($biz['verification_status']) ?: '<span class="badge badge-muted">unverified</span>' ?>
      — verified businesses rank higher, earn customer trust and unlock promotions (§5.2).</p>

    <?php if ($open): ?>
      <div class="panel">
        <h3>Request in review <span class="badge badge-status-<?= e($open['status']) ?>"><?= e(str_replace('_',' ',$open['status'])) ?></span></h3>
        <?php if ($open['status'] === 'changes_requested' && $open['admin_note']): ?>
          <div class="flash flash-error">Admin asked for changes: <?= e($open['admin_note']) ?></div>
        <?php endif; ?>
        <p class="muted small">Requested level: <?= e(str_replace('_', ' ', $open['requested_level'])) ?> · sent <?= time_ago($open['created_at']) ?></p>
        <?php if ($open['status'] === 'changes_requested'): ?>
          <form method="post" enctype="multipart/form-data">
            <?= csrf_field() ?><input type="hidden" name="do" value="update">
            <?php foreach ($docTypes as $k => $label): ?>
              <label><?= e($label) ?> <input type="file" name="doc_<?= $k ?>" accept="image/*"></label>
            <?php endforeach; ?>
            <label>Note for the reviewer <textarea name="message" rows="2"></textarea></label>
            <button class="btn btn-primary">Resubmit for review</button>
          </form>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <form class="panel" method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <h3>Submit verification documents</h3>
        <label>Verification level
          <select name="requested_level">
            <?php foreach ($levels as $k => $label): ?><option value="<?= $k ?>"><?= e($label) ?></option><?php endforeach; ?>
          </select>
        </label>
        <p class="muted small">Upload photos/scans (JPG or PNG). At least one document is required; the more you provide, the faster the review.</p>
        <?php foreach ($docTypes as $k => $label): ?>
          <label><?= e($label) ?> <input type="file" name="doc_<?= $k ?>" accept="image/*"></label>
        <?php endforeach; ?>
        <label>Message for the reviewer (optional) <textarea name="message" rows="2" placeholder="Physical address, Google Maps link, social pages…"></textarea></label>
        <button class="btn btn-primary">Submit for verification</button>
      </form>
    <?php endif; ?>

    <?php if ($history): ?>
      <h2 class="section-gap">Request history</h2>
      <?php foreach ($history as $r): ?>
        <div class="panel">
          <div class="review-head">
            <strong><?= e(str_replace('_', ' ', $r['requested_level'])) ?></strong>
            <span class="badge badge-status-<?= e($r['status']) ?>"><?= e(str_replace('_',' ',$r['status'])) ?></span>
            <span class="muted"><?= time_ago($r['created_at']) ?></span>
          </div>
          <?php if ($r['admin_note']): ?><p class="muted small">Admin note: <?= e($r['admin_note']) ?></p><?php endif; ?>
          <?php if (!empty($docsByReq[$r['id']])): ?>
            <div class="btn-row">
              <?php foreach ($docsByReq[$r['id']] as $d): ?>
                <a class="btn btn-ghost btn-sm" target="_blank" href="<?= e(img_url($d['file_url'])) ?>">📄 <?= e(str_replace('_', ' ', $d['doc_type'])) ?></a>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>
<?php include __DIR__ . '/../views/layout_bottom.php'; ?>
