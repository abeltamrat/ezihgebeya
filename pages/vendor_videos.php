<?php
$u = require_vendor();
$biz = my_business($u);
if (!$biz) { flash('Create your business profile first.', 'error'); redirect('vendor/business'); }
$pageTitle = 'My Videos';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'delete') {
    csrf_check();
    q("UPDATE video_posts SET status = 'deleted' WHERE id = ? AND business_id = ?", [(int)$_POST['id'], $biz['id']]);
    flash('Video removed.');
    redirect('vendor/videos');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!can_add_video($biz['id'])) {
        flash('Video limit reached for your ' . plans()[current_plan($biz['id'])]['label'] . ' plan. Upgrade to add more.', 'error');
        redirect('vendor/subscription');
    }
    $platform = $_POST['platform'] ?? '';
    $urlIn = trim($_POST['original_url'] ?? '');
    $linkedType = $_POST['linked_type'] ?? 'business';
    $linkedId = (int)($_POST['linked_id'] ?? 0) ?: null;
    $cta = in_array($_POST['cta_label'] ?? '', CTA_LABELS, true) ? $_POST['cta_label'] : 'Check Now';
    $title = trim($_POST['title'] ?? '');

    $parsed = in_array($platform, ['tiktok', 'youtube'], true) ? parse_video_url($platform, $urlIn) : null;
    if (!$parsed) $errors[] = 'Could not recognize that video link. Paste a TikTok share link (vt.tiktok.com/...) or full TikTok video URL, or a YouTube/Shorts URL.';
    if (!in_array($linkedType, ['product', 'service', 'supply', 'business'], true)) $errors[] = 'Invalid link target.';
    if ($linkedType !== 'business') {
        $t = LISTING_TABLES[$linkedType];
        if (!$linkedId || !val("SELECT COUNT(*) FROM `$t` WHERE id = ? AND business_id = ?", [$linkedId, $biz['id']])) {
            $errors[] = 'Select one of your own listings to link the video to.';
        }
    } else { $linkedId = null; }

    if (!$errors) {
        $vStatus = sys('moderation.auto_approve_videos') ? 'approved' : 'pending'; // §16.3 policy switch
        q("INSERT INTO video_posts (business_id, user_id, platform, original_url, video_id, embed_url, title, linked_type, linked_id, cta_label, city, subcity, status)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)",
          [$biz['id'], $u['id'], $platform, $urlIn, $parsed['video_id'], $parsed['embed_url'], $title ?: null,
           $linkedType, $linkedId, $cta, $biz['city'], $biz['subcity'], $vStatus]);
        flash($vStatus === 'approved' ? 'Video published to the feed!' : 'Video submitted for review. It appears in the feed once approved.');
        redirect('vendor/videos');
    }
}

$videos = rows("SELECT * FROM video_posts WHERE business_id = ? AND status != 'deleted' ORDER BY created_at DESC", [$biz['id']]);
$myProducts = rows("SELECT id, title FROM products WHERE business_id = ? AND status NOT IN ('deleted') ORDER BY title", [$biz['id']]);
$myServices = rows("SELECT id, title FROM services WHERE business_id = ? AND status NOT IN ('deleted') ORDER BY title", [$biz['id']]);
$mySupplies = rows("SELECT id, name AS title FROM supplies WHERE business_id = ? AND status NOT IN ('deleted') ORDER BY name", [$biz['id']]);
include __DIR__ . '/../views/layout_top.php';
?>
<div class="container section dash-layout">
  <?php include __DIR__ . '/../views/vendor_nav.php'; ?>
  <div class="dash-main">
    <h1>My Videos</h1>
    <p class="muted">Paste TikTok or YouTube links — no upload needed. Link each video to a listing so viewers can buy directly.</p>
    <?php if ($errors): ?><div role="alert" class="alert alert-error mb-3"><ul class="list-disc list-inside text-sm"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

    <form class="panel form-2col" method="post">
      <?= csrf_field() ?>
      <label>Platform
        <select name="platform">
          <option value="tiktok">TikTok</option>
          <option value="youtube" <?= ($_POST['platform'] ?? '') === 'youtube' ? 'selected' : '' ?>>YouTube / Shorts</option>
        </select>
      </label>
      <label>Video URL * <input name="original_url" required placeholder="https://vt.tiktok.com/ZSCt6v317/ or https://www.tiktok.com/@shop/video/..." value="<?= e($_POST['original_url'] ?? '') ?>"></label>
      <label>Link to
        <select name="linked_type" id="linked-type">
          <option value="business">My shop page</option>
          <option value="product" <?= ($_POST['linked_type'] ?? '') === 'product' ? 'selected' : '' ?>>A product</option>
          <option value="service" <?= ($_POST['linked_type'] ?? '') === 'service' ? 'selected' : '' ?>>A service</option>
          <option value="supply" <?= ($_POST['linked_type'] ?? '') === 'supply' ? 'selected' : '' ?>>A supply item</option>
        </select>
      </label>
      <label>Linked listing
        <select name="linked_id" id="linked-id">
          <option value="">—</option>
          <?php foreach (['product' => $myProducts, 'service' => $myServices, 'supply' => $mySupplies] as $t => $ls): ?>
            <?php foreach ($ls as $l): ?><option value="<?= $l['id'] ?>" data-type="<?= $t ?>"><?= e($l['title']) ?> (<?= $t ?>)</option><?php endforeach; ?>
          <?php endforeach; ?>
        </select>
      </label>
      <label>CTA button
        <select name="cta_label">
          <?php foreach (CTA_LABELS as $c): ?><option><?= $c ?></option><?php endforeach; ?>
        </select>
      </label>
      <label>Caption (optional) <input name="title" maxlength="255" value="<?= e($_POST['title'] ?? '') ?>"></label>
      <div class="span2"><button class="btn btn-primary">Submit video for review</button></div>
    </form>

    <h2 class="section-gap">Submitted videos (<?= count($videos) ?>)</h2>
    <?php if (!$videos): ?><div class="empty-state">No videos yet.</div><?php endif; ?>
    <div class="table-wrap"><table class="data-table">
      <?php if ($videos): ?><tr><th>Platform</th><th>Caption</th><th>Linked</th><th>Status</th><th>Views</th><th>CTA clicks</th><th></th></tr><?php endif; ?>
      <?php foreach ($videos as $vp): ?>
      <tr>
        <td><?= $vp['platform'] === 'tiktok' ? '🎵 TikTok' : '▶ YouTube' ?></td>
        <td><a href="<?= e($vp['original_url']) ?>" target="_blank" rel="noopener"><?= e($vp['title'] ?: mb_substr($vp['original_url'], 0, 40) . '…') ?></a></td>
        <td><?= e($vp['linked_type']) ?></td>
        <td><span class="badge badge-status-<?= e($vp['status']) ?>"><?= e($vp['status']) ?></span></td>
        <td><?= (int)$vp['views_count'] ?></td>
        <td><?= (int)$vp['cta_clicks_count'] ?></td>
        <td>
          <form method="post" onsubmit="return confirm('Remove this video?')">
            <?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= $vp['id'] ?>">
            <button title="Delete">🗑</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </table></div>
  </div>
</div>
<?php include __DIR__ . '/../views/layout_bottom.php'; ?>
