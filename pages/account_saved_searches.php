<?php
$u = require_login();
$pageTitle = 'Saved Searches';
$hasSavedSearches = db_table_exists('saved_searches');
$saved = $hasSavedSearches
    ? rows("SELECT * FROM saved_searches WHERE user_id = ? ORDER BY updated_at DESC", [$u['id']])
    : [];

include __DIR__ . '/../views/layout_top.php';
?>
<div class="container section account-page">
  <h1>Saved Searches</h1>
  <p class="muted"><a href="<?= url('account') ?>">← Back to My Account</a></p>

  <div class="panel">
    <h3>Search alerts</h3>
    <p class="muted small">Save a product, service, or supply search and EzihGebeya will notify you when newly posted listings match it. Alerts are checked by cron, so they work on shared hosting without a realtime server.</p>
    <?php if (!$hasSavedSearches): ?>
      <div role="alert" class="alert alert-warning">Run the latest database upgrade to enable saved searches.</div>
    <?php endif; ?>
  </div>

  <?php if ($hasSavedSearches && !$saved): ?>
    <div class="empty-state">No saved searches yet. Browse products, services, or supplies and tap “Save this search”.</div>
  <?php endif; ?>

  <?php foreach ($saved as $s): ?>
    <div class="panel">
      <div class="section-head">
        <div>
          <h3><?= e($s['label']) ?></h3>
          <p class="muted small">
            <?= e(ucfirst($s['listing_type'])) ?> ·
            <?= $s['alerts_enabled'] ? 'Alerts on' : 'Alerts paused' ?>
            <?= $s['last_notified_at'] ? ' · last alert ' . e($s['last_notified_at']) : '' ?>
          </p>
        </div>
        <a class="btn btn-outline btn-sm" href="<?= url(saved_search_url($s)) ?>">View matches</a>
      </div>
      <div class="btn-row">
        <form method="post" action="<?= url('saved-search') ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="do" value="toggle">
          <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
          <input type="hidden" name="return_to" value="account/saved-searches">
          <?php if (!$s['alerts_enabled']): ?><input type="hidden" name="alerts_enabled" value="1"><?php endif; ?>
          <button class="btn btn-ghost btn-sm"><?= $s['alerts_enabled'] ? 'Pause alerts' : 'Resume alerts' ?></button>
        </form>
        <form method="post" action="<?= url('saved-search') ?>" onsubmit="return confirm('Remove this saved search?')">
          <?= csrf_field() ?>
          <input type="hidden" name="do" value="delete">
          <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
          <input type="hidden" name="return_to" value="account/saved-searches">
          <button class="btn btn-ghost btn-sm">Remove</button>
        </form>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<?php include __DIR__ . '/../views/layout_bottom.php'; ?>
