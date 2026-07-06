<?php
$cur = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
$navCurrent = fn(string $p) => ($p === 'vendor' ? $cur === 'vendor' : str_starts_with($cur, $p)) ? 'current' : '';
?>
<aside class="dash-nav">
  <h3>Vendor</h3>
  <a class="<?= $navCurrent('vendor') ?>" href="<?= url('vendor') ?>"><span class="dash-ico"><?= system_ui_icon('overview', 'Overview') ?></span>Overview</a>
  <a class="<?= $navCurrent('vendor/business') ?>" href="<?= url('vendor/business') ?>"><span class="dash-ico"><?= system_ui_icon('business', 'Business') ?></span>Business Profile</a>
  <a class="<?= $navCurrent('vendor/listings/product') ?>" href="<?= url('vendor/listings/product') ?>"><span class="dash-ico"><?= system_ui_icon('furniture', 'Products') ?></span>Products</a>
  <a class="<?= $navCurrent('vendor/listings/service') ?>" href="<?= url('vendor/listings/service') ?>"><span class="dash-ico"><?= system_ui_icon('services', 'Services') ?></span>Services</a>
  <a class="<?= $navCurrent('vendor/listings/supply') ?>" href="<?= url('vendor/listings/supply') ?>"><span class="dash-ico"><?= system_ui_icon('supplies', 'Supplies') ?></span>Supplies</a>
  <a class="<?= $navCurrent('vendor/videos') ?>" href="<?= url('vendor/videos') ?>"><span class="dash-ico"><?= system_ui_icon('video', 'Videos') ?></span>Videos</a>
  <a class="<?= $navCurrent('vendor/inquiries') ?>" href="<?= url('vendor/inquiries') ?>"><span class="dash-ico"><?= system_ui_icon('messages', 'Inquiries') ?></span>Inquiries</a>
  <a class="<?= $navCurrent('vendor/orders') ?>" href="<?= url('vendor/orders') ?>"><span class="dash-ico"><?= system_ui_icon('orders', 'Orders') ?></span>Orders</a>
  <a class="<?= $navCurrent('vendor/reviews') ?>" href="<?= url('vendor/reviews') ?>"><span class="dash-ico"><?= system_ui_icon('subscription', 'Reviews') ?></span>Reviews</a>
  <a class="<?= $navCurrent('vendor/verification') ?>" href="<?= url('vendor/verification') ?>"><span class="dash-ico"><?= system_ui_icon('admin', 'Verification') ?></span>Verification</a>
  <a class="<?= $navCurrent('vendor/promotions') ?>" href="<?= url('vendor/promotions') ?>"><span class="dash-ico"><?= system_ui_icon('ads', 'Promotions') ?></span>Promotions</a>
  <a class="<?= $navCurrent('vendor/subscription') ?>" href="<?= url('vendor/subscription') ?>"><span class="dash-ico"><?= system_ui_icon('subscription', 'Subscription') ?></span>Subscription</a>
  <a class="<?= $navCurrent('vendor/analytics') ?>" href="<?= url('vendor/analytics') ?>"><span class="dash-ico"><?= system_ui_icon('analytics', 'Analytics') ?></span>Analytics</a>
</aside>
