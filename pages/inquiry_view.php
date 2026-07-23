<?php
/** Inquiry conversation thread (§10): customer ↔ vendor chat on one inquiry. Expects $id. */
$u = require_login();
$inq = row("SELECT i.*, b.business_name, b.slug b_slug, b.user_id owner_id, b.phone b_phone
            FROM inquiries i JOIN businesses b ON b.id = i.business_id WHERE i.id = ?", [$id]);
if (!$inq) { flash('Inquiry not found.', 'error'); redirect('account'); }

$isCustomer = $inq['customer_id'] && (int)$inq['customer_id'] === (int)$u['id'];
$isOwner = (int)$inq['owner_id'] === (int)$u['id'];
if (!$isCustomer && !$isOwner && !is_admin($u)) { flash('Not authorized.', 'error'); redirect(''); }
$pageTitle = 'Inquiry #' . $inq['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $body = trim($_POST['body'] ?? '');
    if ($body === '') { flash('Write a message first.', 'error'); redirect('inquiries/' . $inq['id']); }
    if (!$inq['customer_id'] && !$isOwner) { flash('This guest inquiry has no chat — call the phone number instead.', 'error'); redirect('inquiries/' . $inq['id']); }
    q("INSERT INTO inquiry_messages (inquiry_id, sender_id, body) VALUES (?,?,?)", [$inq['id'], $u['id'], mb_substr($body, 0, 3000)]);
    if ($isOwner) {
        if (in_array($inq['status'], ['new', 'seen'], true)) q("UPDATE inquiries SET status = 'responded' WHERE id = ?", [$inq['id']]);
        notify($inq['customer_id'] ? (int)$inq['customer_id'] : null, 'vendor_reply',
               $inq['business_name'] . ' replied to your inquiry', 'app/account/inquiries/' . $inq['id'], mb_substr($body, 0, 200), true);
    } else {
        notify((int)$inq['owner_id'], 'new_inquiry',
               ($u['full_name'] ?: 'Customer') . ' sent a message on inquiry #' . $inq['id'], 'app/vendor/inquiries/' . $inq['id'], mb_substr($body, 0, 200));
    }
    redirect('inquiries/' . $inq['id']);
}

// mark inquiry seen for the vendor + messages read for the viewer
if ($isOwner && $inq['status'] === 'new') q("UPDATE inquiries SET status = 'seen' WHERE id = ?", [$inq['id']]);
q("UPDATE inquiry_messages SET read_at = NOW() WHERE inquiry_id = ? AND sender_id != ? AND read_at IS NULL", [$inq['id'], $u['id']]);

$msgs = rows("SELECT m.*, u.full_name FROM inquiry_messages m JOIN users u ON u.id = m.sender_id
              WHERE m.inquiry_id = ? ORDER BY m.created_at", [$inq['id']]);
$isPartial = ($_GET['partial'] ?? '') === 'messages';
if (!$isPartial) include __DIR__ . '/../views/layout_top.php';
?>
<?php if (!$isPartial): ?>
<div class="container section" style="max-width:760px">
  <p><a href="<?= url($isOwner ? 'vendor/inquiries' : 'account') ?>">← Back</a></p>
  <h1>💬 <?= e($inq['listing_title'] ?: ucfirst($inq['listing_type']) . ' inquiry') ?></h1>
  <p class="muted"><?= e($inq['business_name']) ?> · <?= e(str_replace('_', ' ', $inq['inquiry_type'])) ?>
     · <span class="badge badge-status-<?= e($inq['status']) ?>"><?= e($inq['status']) ?></span> · <?= time_ago($inq['created_at']) ?></p>

  <div class="panel">
    <div class="review-head"><strong><?= e($inq['name'] ?: 'Customer') ?></strong><span class="muted"><?= time_ago($inq['created_at']) ?></span></div>
    <p><?= nl2br(e($inq['message'])) ?></p>
    <?php if ($isOwner): ?><p class="muted small">📞 <?= e($inq['phone']) ?> · prefers <?= e($inq['preferred_contact_method']) ?></p><?php endif; ?>
  </div>

<?php endif; ?>

  <div id="inquiry-messages-poll"
       hx-get="<?= url('inquiries/' . $inq['id'] . '?partial=messages') ?>"
       hx-trigger="every 10s"
       hx-swap="outerHTML">
  <?php foreach ($msgs as $m): $mine = (int)$m['sender_id'] === (int)$u['id']; ?>
    <div class="panel" style="<?= $mine ? 'margin-left:14%;background:var(--brand-soft)' : 'margin-right:14%' ?>">
      <div class="review-head"><strong><?= $mine ? 'You' : e($m['full_name']) ?></strong><span class="muted"><?= time_ago($m['created_at']) ?></span></div>
      <p><?= nl2br(e($m['body'])) ?></p>
    </div>
  <?php endforeach; ?>
  </div>

<?php if (!$isPartial): ?>
  <?php if ($inq['customer_id'] || $isOwner): ?>
    <?php if (!$inq['customer_id']): ?>
      <p class="muted small">This inquiry was sent by a guest — your reply is stored here, but reach them at <?= e($inq['phone']) ?> to make sure they see it.</p>
    <?php endif; ?>
    <form class="panel" method="post">
      <?= csrf_field() ?>
      <label>Reply <textarea name="body" rows="3" required placeholder="Write your message…"></textarea></label>
      <button class="btn btn-primary">Send reply</button>
    </form>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/../views/layout_bottom.php'; ?>
<?php endif; ?>
