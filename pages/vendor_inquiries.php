<?php
$u = require_vendor();
$biz = my_business($u);
if (!$biz) { flash('Create your business profile first.', 'error'); redirect('vendor/business'); }
$pageTitle = 'Inquiries';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int)($_POST['id'] ?? 0);
    $status = $_POST['status'] ?? '';
    if (in_array($status, ['new', 'seen', 'responded', 'negotiating', 'converted', 'closed', 'spam'], true)) {
        q("UPDATE inquiries SET status = ? WHERE id = ? AND business_id = ?", [$status, $id, $biz['id']]);
        flash('Inquiry updated.');
    }
    redirect('vendor/inquiries');
}

$filter = $_GET['status'] ?? '';
$where = "business_id = ?"; $params = [$biz['id']];
if ($filter) { $where .= " AND status = ?"; $params[] = $filter; }
$inqs = rows("SELECT * FROM inquiries WHERE $where ORDER BY created_at DESC LIMIT 200", $params);
include __DIR__ . '/../views/layout_top.php';
?>
<div class="container section dash-layout">
  <?php include __DIR__ . '/../views/vendor_nav.php'; ?>
  <div class="dash-main">
    <div class="section-head">
      <h1>Inquiries (<?= count($inqs) ?>)</h1>
      <form method="get">
        <select name="status" onchange="this.form.submit()">
          <option value="">All statuses</option>
          <?php foreach (['new', 'seen', 'responded', 'negotiating', 'converted', 'closed', 'spam'] as $s): ?>
            <option <?= $filter === $s ? 'selected' : '' ?>><?= $s ?></option>
          <?php endforeach; ?>
        </select>
      </form>
    </div>
    <?php if (!$inqs): ?><div class="empty-state">No inquiries<?= $filter ? " with status “" . e($filter) . "”" : '' ?> yet.</div><?php endif; ?>
    <?php foreach ($inqs as $i): ?>
    <div class="panel inq-panel">
      <div class="inq-head">
        <strong><?= e($i['name'] ?: 'Customer') ?></strong>
        <a href="tel:<?= e($i['phone']) ?>">📞 <?= e($i['phone']) ?></a>
        <span class="badge badge-muted"><?= e($i['preferred_contact_method']) ?></span>
        <span class="badge badge-status-<?= e($i['status']) ?>"><?= e($i['status']) ?></span>
        <span class="muted"><?= time_ago($i['created_at']) ?> · via <?= e(str_replace('_', ' ', $i['source'])) ?></span>
      </div>
      <div class="muted small"><?= e(str_replace('_', ' ', $i['inquiry_type'])) ?><?= $i['listing_title'] ? ' · ' . e($i['listing_title']) : '' ?></div>
      <p><?= nl2br(e($i['message'])) ?></p>
      <?php $nMsgs = (int)val("SELECT COUNT(*) FROM inquiry_messages WHERE inquiry_id = ?", [$i['id']]); ?>
      <div class="btn-row">
        <a class="btn btn-primary btn-sm" href="<?= url('inquiries/' . $i['id']) ?>">💬 Reply<?= $nMsgs ? " ($nMsgs)" : '' ?></a>
        <form method="post" class="inq-status-form">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= $i['id'] ?>">
          <select name="status">
            <?php foreach (['new', 'seen', 'responded', 'negotiating', 'converted', 'closed', 'spam'] as $s): ?>
              <option <?= $i['status'] === $s ? 'selected' : '' ?>><?= $s ?></option>
            <?php endforeach; ?>
          </select>
          <button class="btn btn-outline btn-sm">Update</button>
        </form>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php include __DIR__ . '/../views/layout_bottom.php'; ?>
