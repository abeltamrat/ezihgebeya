<?php
/** Public content page (§19.1): /about, /contact, /terms, /privacy, /page/{slug}. Expects $slug. */
$page = row("SELECT * FROM content_pages WHERE slug = ? AND status = 'published'", [$slug]);
if (!$page) { require __DIR__ . '/404.php'; return; }
$pageTitle = $page['title'];
$pageDesc = mb_substr(trim(preg_replace('/\s+/', ' ', (string)$page['body'])), 0, 155);
include __DIR__ . '/../views/layout_top.php';
?>
<div class="container section" style="max-width:820px">
  <h1><?= e($page['title']) ?></h1>
  <div class="panel">
    <?php foreach (preg_split('/\R{2,}/', (string)$page['body']) as $para): if (trim($para) === '') continue; ?>
      <p><?= nl2br(e(trim($para))) ?></p>
    <?php endforeach; ?>
  </div>
  <p class="muted small">Last updated <?= date('M j, Y', strtotime($page['updated_at'])) ?></p>
</div>
<?php include __DIR__ . '/../views/layout_bottom.php'; ?>
