<?php
/** In-app notification inbox (§15). */
$u = require_login();
if ($_SERVER['REQUEST_METHOD'] === 'GET' && is_admin($u)) redirect('app/account/notifications');
$pageTitle = 'Notifications';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['do'] ?? '') === 'read_all') {
        q("UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL", [$u['id']]);
    }
    redirect('notifications');
}

$list = rows("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 100", [$u['id']]);
$unread = array_filter($list, fn($n) => !$n['read_at']);
$isPartial = ($_GET['partial'] ?? '') === 'list';
if (!$isPartial) include __DIR__ . '/../views/layout_top.php';
?>
<?php if (!$isPartial): ?>
<div class="container section" style="max-width:760px">
<?php endif; ?>
  <div id="notifications-poll"
       hx-get="<?= url('notifications?partial=list') ?>"
       hx-trigger="every 15s"
       hx-swap="outerHTML">
  <div class="section-head">
    <h1>🔔 Notifications<?= $unread ? ' (' . count($unread) . ' new)' : '' ?></h1>
    <?php if ($unread): ?>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="read_all"><button class="btn btn-outline btn-sm">Mark all read</button></form>
    <?php endif; ?>
  </div>
  <?php if (!$list): ?><div class="empty-state">Nothing yet — you'll see replies, approvals and order updates here.</div><?php endif; ?>
  <?php foreach ($list as $n): ?>
    <?php $inner = '<div class="review-head"><strong>' . e($n['title']) . '</strong>'
        . (!$n['read_at'] ? '<span class="pill">new</span>' : '')
        . '<span class="muted">' . time_ago($n['created_at']) . '</span></div>'
        . ($n['body'] ? '<p class="muted small">' . nl2br(e($n['body'])) . '</p>' : ''); ?>
    <?php if ($n['url']): ?>
      <a class="panel" style="display:block;<?= !$n['read_at'] ? 'border-color:var(--brand)' : '' ?>" href="<?= url($n['url']) ?>"><?= $inner ?></a>
    <?php else: ?>
      <div class="panel" style="<?= !$n['read_at'] ? 'border-color:var(--brand)' : '' ?>"><?= $inner ?></div>
    <?php endif; ?>
  <?php endforeach; ?>
  </div>
<?php if (!$isPartial): ?>
</div>
<?php endif; ?>
<?php
// opening the inbox clears the unread badge
q("UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL", [$u['id']]);
if (!$isPartial) include __DIR__ . '/../views/layout_bottom.php'; ?>
