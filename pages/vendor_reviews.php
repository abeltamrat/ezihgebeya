<?php
/** Vendor reviews inbox (§13.2): see approved/pending reviews, reply once each. */
$u = require_vendor();
$biz = my_business($u);
if (!$biz) { flash('Create your business profile first.', 'error'); redirect('vendor/business'); }
$pageTitle = 'Reviews';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $rid = (int)($_POST['review_id'] ?? 0);
    $reply = trim($_POST['reply'] ?? '');
    $r = row("SELECT * FROM reviews WHERE id = ? AND business_id = ?", [$rid, $biz['id']]);
    if ($r && $reply !== '' && !$r['vendor_reply']) { // one reply per review (§13.2.5)
        q("UPDATE reviews SET vendor_reply = ?, vendor_replied_at = NOW() WHERE id = ?", [mb_substr($reply, 0, 1000), $rid]);
        notify((int)$r['reviewer_id'], 'vendor_reply', $biz['business_name'] . ' replied to your review', 'businesses/' . $biz['slug']);
        flash('Reply published.');
    } elseif ($r && $r['vendor_reply']) {
        flash('You already replied to this review — one reply per review.', 'error');
    }
    redirect('vendor/reviews');
}

$list = rows("SELECT r.*, u2.full_name FROM reviews r JOIN users u2 ON u2.id = r.reviewer_id
    WHERE r.business_id = ? AND r.status IN ('approved','pending') ORDER BY r.created_at DESC LIMIT 100", [$biz['id']]);
include __DIR__ . '/../views/layout_top.php';
?>
<div class="container section dash-layout">
  <?php include __DIR__ . '/../views/vendor_nav.php'; ?>
  <div class="dash-main">
    <h1>⭐ Reviews</h1>
    <p class="muted">Rating: <?= star_rating($biz['rating_average'], (int)$biz['rating_count']) ?>. You can reply once to each review; replies show publicly on your profile.</p>
    <?php if (!$list): ?><div class="empty-state">No reviews yet.</div><?php endif; ?>
    <?php foreach ($list as $r): ?>
      <div class="panel">
        <div class="review-head">
          <strong><?= e($r['full_name']) ?></strong>
          <span class="stars"><?= str_repeat('★', (int)$r['rating']) ?></span>
          <?php if ($r['is_verified_purchase']): ?><span class="badge badge-verified">✔ Verified purchase</span><?php endif; ?>
          <span class="badge badge-status-<?= e($r['status']) ?>"><?= e($r['status']) ?></span>
          <span class="muted"><?= time_ago($r['created_at']) ?></span>
        </div>
        <p><?= nl2br(e($r['comment'])) ?></p>
        <?php if ($r['vendor_reply']): ?>
          <div class="panel" style="background:var(--brand-soft)">
            <div class="review-head"><strong>Your reply</strong><span class="muted"><?= time_ago($r['vendor_replied_at']) ?></span></div>
            <p><?= nl2br(e($r['vendor_reply'])) ?></p>
          </div>
        <?php elseif ($r['status'] === 'approved'): ?>
          <form method="post" class="inq-status-form">
            <?= csrf_field() ?><input type="hidden" name="review_id" value="<?= $r['id'] ?>">
            <input name="reply" placeholder="Write your one public reply…" style="min-width:280px" required>
            <button class="btn btn-outline btn-sm">Reply</button>
          </form>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php include __DIR__ . '/../views/layout_bottom.php'; ?>
