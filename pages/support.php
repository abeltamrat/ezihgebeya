<?php
$u = require_login();
$pageTitle = 'Support';
$errors = [];
$hasSupport = db_table_exists('support_tickets');

$categories = [
    'order' => 'Order help',
    'payment' => 'Payment issue',
    'vendor' => 'Vendor problem',
    'listing' => 'Listing/report concern',
    'account' => 'Account access or safety',
    'callback' => 'Request a callback',
    'other' => 'Other',
];
$relatedTypes = [
    '' => 'Not sure / none',
    'product' => 'Product listing',
    'service' => 'Service listing',
    'supply' => 'Supply listing',
    'business' => 'Business profile',
    'video' => 'Video',
    'review' => 'Review',
    'user' => 'User',
    'order' => 'Order',
    'payment' => 'Payment',
    'inquiry' => 'Inquiry',
    'other' => 'Other',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!$hasSupport) {
        $errors[] = 'Support tickets are not installed yet. Run the latest database upgrade first.';
    } else {
        $category = $_POST['category'] ?? 'other';
        if (!isset($categories[$category])) $category = 'other';
        $subject = trim($_POST['subject'] ?? '');
        $message = trim($_POST['message'] ?? '');
        $phone = trim($_POST['phone'] ?? '') ?: ($u['phone'] ?? null);
        $relatedType = $_POST['related_type'] ?? '';
        $relatedType = isset($relatedTypes[$relatedType]) && $relatedType !== '' ? $relatedType : null;
        $relatedId = max(0, (int)($_POST['related_id'] ?? 0)) ?: null;
        $callbackAt = trim($_POST['preferred_callback_at'] ?? '');
        $callbackAt = $callbackAt ? str_replace('T', ' ', substr($callbackAt, 0, 16)) . ':00' : null;

        if (mb_strlen($subject) < 4) $errors[] = 'Add a short subject so support can triage it.';
        if (mb_strlen($message) < 10) $errors[] = 'Describe the issue in at least 10 characters.';
        if ($category === 'callback' && !$phone) $errors[] = 'Add a phone number for callback requests.';
        if ($relatedType && !$relatedId) $errors[] = 'Add the related ID, or choose "Not sure / none".';

        if (!$errors) {
            q("INSERT INTO support_tickets (user_id, category, subject, message, phone, preferred_callback_at, related_type, related_id)
               VALUES (?,?,?,?,?,?,?,?)", [$u['id'], $category, mb_substr($subject, 0, 180), $message, $phone, $callbackAt, $relatedType, $relatedId]);
            $ticketId = (int)db()->lastInsertId();
            foreach (rows("SELECT id FROM users WHERE account_type IN ('admin','super_admin') AND status='active'") as $admin) {
                notify((int)$admin['id'], 'support_ticket', 'New support ticket #' . $ticketId . ': ' . mb_substr($subject, 0, 80), 'admin/support');
            }
            flash('Support ticket #' . $ticketId . ' created. We will follow up from the support queue.');
            redirect('support');
        }
    }
}

$tickets = $hasSupport ? rows("SELECT * FROM support_tickets WHERE user_id = ? ORDER BY created_at DESC LIMIT 30", [$u['id']]) : [];

include __DIR__ . '/../views/layout_top.php';
?>
<div class="container section account-page">
  <h1>Support</h1>
  <p class="muted"><a href="<?= url('account') ?>">← Back to My Account</a></p>

  <?php if ($errors): ?><div role="alert" class="alert alert-error mb-3"><ul class="list-disc list-inside text-sm"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
  <?php if (!$hasSupport): ?><div role="alert" class="alert alert-warning mb-3">Run the latest database upgrade to enable support tickets.</div><?php endif; ?>

  <div class="panel">
    <h3>Create a support ticket</h3>
    <p class="muted small">Use this for order issues, vendor complaints, payment problems, account safety, or callback requests. Urgent fraud/listing complaints can be escalated by admins into moderation.</p>
    <form method="post" class="form-2col">
      <?= csrf_field() ?>
      <label>Category
        <select name="category">
          <?php foreach ($categories as $k => $label): ?><option value="<?= e($k) ?>"><?= e($label) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label>Phone for callback <input name="phone" value="<?= e($u['phone'] ?? '') ?>" placeholder="09..."></label>
      <label class="span2">Subject <input name="subject" required maxlength="180" placeholder="Example: Seller has not responded after payment"></label>
      <label class="span2">What happened?
        <textarea name="message" rows="5" required placeholder="Share order number, listing link, payment reference, or the best callback time if relevant."></textarea>
      </label>
      <label>Related item type
        <select name="related_type">
          <?php foreach ($relatedTypes as $k => $label): ?><option value="<?= e($k) ?>"><?= e($label) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label>Related ID <input type="number" name="related_id" min="1" placeholder="Optional"></label>
      <label>Preferred callback time <input type="datetime-local" name="preferred_callback_at"></label>
      <div class="span2"><button class="btn btn-primary" <?= !$hasSupport ? 'disabled' : '' ?>>Submit ticket</button></div>
    </form>
  </div>

  <h2 class="section-gap">My support tickets</h2>
  <?php if (!$tickets): ?><div class="empty-state">No support tickets yet.</div><?php endif; ?>
  <?php foreach ($tickets as $t): ?>
    <div class="panel">
      <div class="review-head">
        <strong>#<?= (int)$t['id'] ?> · <?= e($t['subject']) ?></strong>
        <span class="badge badge-status-<?= e($t['status']) ?>"><?= e(str_replace('_', ' ', $t['status'])) ?></span>
        <span class="muted"><?= time_ago($t['created_at']) ?></span>
      </div>
      <p><?= nl2br(e($t['message'])) ?></p>
      <p class="muted small">
        <?= e($categories[$t['category']] ?? $t['category']) ?>
        <?php if ($t['phone']): ?> · callback <?= e($t['phone']) ?><?php endif; ?>
        <?php if ($t['preferred_callback_at']): ?> · preferred <?= e($t['preferred_callback_at']) ?><?php endif; ?>
        <?php if ($t['related_type']): ?> · related <?= e($t['related_type']) ?> #<?= (int)$t['related_id'] ?><?php endif; ?>
      </p>
      <?php if ($t['admin_note']): ?><div class="rejection-note"><strong>Support note:</strong> <?= nl2br(e($t['admin_note'])) ?></div><?php endif; ?>
      <?php if ($t['report_id']): ?><p class="muted small">Escalated to moderation report #<?= (int)$t['report_id'] ?>.</p><?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>
<?php include __DIR__ . '/../views/layout_bottom.php'; ?>
