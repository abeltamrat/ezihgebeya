<?php
$u = require_vendor();
$biz = my_business($u);
$pageTitle = 'Vendor Dashboard';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'request_vendor_help') {
    csrf_check();
    if (!db_table_exists('support_tickets')) {
        flash('Support tickets are not installed yet. Run the latest database upgrade first.', 'error');
    } else {
        $subject = 'Vendor package guidance request';
        $message = 'Vendor requested a callback for package guidance, listing growth, promotions, or dashboard help.';
        if ($biz) $message .= "\n\nBusiness: {$biz['business_name']} (#{$biz['id']})";
        q("INSERT INTO support_tickets (user_id, category, subject, message, phone, related_type, related_id, priority)
           VALUES (?,?,?,?,?,?,?,'normal')",
          [$u['id'], 'callback', $subject, $message, $u['phone'] ?? null, $biz ? 'business' : 'user', $biz ? $biz['id'] : $u['id']]);
        $ticketId = (int)db()->lastInsertId();
        foreach (rows("SELECT id FROM users WHERE account_type IN ('admin','super_admin') AND status='active'") as $admin) {
            notify((int)$admin['id'], 'support_ticket', 'Vendor requested callback #' . $ticketId, 'admin/support');
        }
        flash('Callback request sent. Our team can use this ticket to guide you by phone.');
    }
    redirect('vendor');
}
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
        <div role="alert" class="alert alert-info mb-4">
          <?= system_ui_icon('bell', '') ?>
          <span>⏳ Your business "<strong><?= e($biz['business_name']) ?></strong>" is pending admin approval. You can already prepare your listings.</span>
        </div>
      <?php elseif ($biz['status'] === 'rejected'): ?>
        <div role="alert" class="alert alert-error mb-4">
          <span>Your business registration was rejected. Please update your profile and it will be re-reviewed.</span>
          <a class="btn btn-sm btn-ghost" href="<?= url('vendor/business') ?>">Update profile →</a>
        </div>
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
      <div class="stats stats-vertical lg:stats-horizontal shadow w-full border border-base-300 rounded-box mb-4">
        <?php foreach ($stats as $label => $n): ?>
          <div class="stat">
            <div class="stat-title text-xs"><?= $label ?></div>
            <div class="stat-value text-2xl text-primary"><?= (int)$n ?></div>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="panel">
        <h3>Quick actions</h3>
        <div class="btn-row">
          <a class="btn btn-primary" href="<?= url('vendor/listings/product/new') ?>">+ Add product</a>
          <a class="btn btn-outline" href="<?= url('vendor/listings/service/new') ?>">+ Add service</a>
          <a class="btn btn-outline" href="<?= url('vendor/listings/supply/new') ?>">+ Add supply</a>
          <a class="btn btn-outline" href="<?= url('vendor/videos') ?>">+ Add video link</a>
          <form method="post" class="form-inline">
            <?= csrf_field() ?><input type="hidden" name="do" value="request_vendor_help">
            <button class="btn btn-ghost">Request help / callback</button>
          </form>
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
