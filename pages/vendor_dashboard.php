<?php
$u = require_vendor();
$biz = my_business($u);
$pageTitle = 'Vendor Dashboard';
include __DIR__ . '/../views/layout_top.php';
?>
<div class="container section dash-layout">
  <?php include __DIR__ . '/../views/vendor_nav.php'; ?>
  <div class="dash-main">
    <h1>Vendor Dashboard</h1>
    <?php if (!$biz): ?>
      <div class="panel">
        <h2>👋 Welcome! First step: register your business</h2>
        <p>Create your business profile so customers can find and trust you. It will be reviewed by our team before going live.</p>
        <a class="btn btn-primary" href="<?= url('vendor/business') ?>">Create business profile</a>
      </div>
    <?php else: ?>
      <?php if ($biz['status'] === 'pending'): ?>
        <div class="flash flash-info">⏳ Your business "<?= e($biz['business_name']) ?>" is pending admin approval. You can already prepare your listings.</div>
      <?php elseif ($biz['status'] === 'rejected'): ?>
        <div class="flash flash-error">Your business registration was rejected. Please update your profile and it will be re-reviewed.</div>
      <?php endif; ?>
      <?php
      $stats = [
          'Products' => val("SELECT COUNT(*) FROM products WHERE business_id = ? AND status != 'deleted'", [$biz['id']]),
          'Services' => val("SELECT COUNT(*) FROM services WHERE business_id = ? AND status != 'deleted'", [$biz['id']]),
          'Supplies' => val("SELECT COUNT(*) FROM supplies WHERE business_id = ? AND status != 'deleted'", [$biz['id']]),
          'Videos' => val("SELECT COUNT(*) FROM video_posts WHERE business_id = ? AND status != 'deleted'", [$biz['id']]),
          'New orders' => val("SELECT COUNT(*) FROM orders WHERE business_id = ? AND status = 'pending'", [$biz['id']]),
          'New inquiries' => val("SELECT COUNT(*) FROM inquiries WHERE business_id = ? AND status = 'new'", [$biz['id']]),
          'Total inquiries' => val("SELECT COUNT(*) FROM inquiries WHERE business_id = ?", [$biz['id']]),
          'Product views' => val("SELECT COALESCE(SUM(views_count),0) FROM products WHERE business_id = ?", [$biz['id']]),
          'Video CTA clicks' => val("SELECT COALESCE(SUM(cta_clicks_count),0) FROM video_posts WHERE business_id = ?", [$biz['id']]),
      ];
      ?>
      <div class="stat-grid">
        <?php foreach ($stats as $label => $n): ?>
          <div class="stat-card"><div class="stat-num"><?= (int)$n ?></div><div class="stat-label"><?= $label ?></div></div>
        <?php endforeach; ?>
      </div>
      <div class="panel">
        <h3>Quick actions</h3>
        <div class="btn-row">
          <a class="btn btn-primary" href="<?= url('vendor/listings/product/new') ?>">+ Add product</a>
          <a class="btn btn-outline" href="<?= url('vendor/listings/service/new') ?>">+ Add service</a>
          <a class="btn btn-outline" href="<?= url('vendor/listings/supply/new') ?>">+ Add supply</a>
          <a class="btn btn-outline" href="<?= url('vendor/videos') ?>">+ Add video link</a>
        </div>
      </div>
      <?php $recent = rows("SELECT * FROM inquiries WHERE business_id = ? ORDER BY created_at DESC LIMIT 5", [$biz['id']]); ?>
      <div class="panel">
        <h3>Latest inquiries</h3>
        <?php if (!$recent): ?><p class="muted">No inquiries yet. Add listings to start receiving leads.</p><?php endif; ?>
        <?php foreach ($recent as $i): ?>
          <div class="inq-row">
            <strong><?= e($i['name'] ?: 'Customer') ?></strong> · <?= e($i['phone']) ?>
            <span class="badge badge-status-<?= e($i['status']) ?>"><?= e($i['status']) ?></span>
            <div class="muted small"><?= e($i['listing_title'] ?: $i['listing_type']) ?> · <?= time_ago($i['created_at']) ?></div>
            <p><?= e(mb_substr($i['message'], 0, 120)) ?></p>
          </div>
        <?php endforeach; ?>
        <a href="<?= url('vendor/inquiries') ?>">All inquiries →</a>
      </div>
    <?php endif; ?>
  </div>
</div>
<?php include __DIR__ . '/../views/layout_bottom.php'; ?>
