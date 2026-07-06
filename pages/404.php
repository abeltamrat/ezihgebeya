<?php
http_response_code(404);
$pageTitle = 'Page not found';
include __DIR__ . '/../views/layout_top.php';
?>
<div class="container section">
  <div class="empty-state">
    <h1>404</h1>
    <p>That page doesn't exist. <a href="<?= url('') ?>">Back to home</a></p>
  </div>
</div>
<?php include __DIR__ . '/../views/layout_bottom.php'; ?>
