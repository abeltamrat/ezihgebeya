<?php
$pageTitle = 'Offline';
include __DIR__ . '/../views/layout_top.php';
?>
<div class="container section">
  <div class="empty-state">
    <h1>You are offline</h1>
    <p class="muted">EzihGebeya needs the network for fresh listings, account actions, checkout, inquiries, and payments. Reconnect and try again.</p>
    <div class="btn-row" style="justify-content:center">
      <button class="btn btn-primary" type="button" onclick="location.reload()">Try again</button>
      <a class="btn btn-outline" href="<?= url('') ?>">Go home</a>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../views/layout_bottom.php'; ?>
