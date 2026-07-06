</main>
<footer class="site-footer">
  <div class="container footer-grid">
    <div>
      <?php $footerUi = system_ui_config(); ?>
      <div class="logo"><span class="logo-mark"><?= e($footerUi['logo_mark']) ?></span> <span><?= e($footerUi['logo_text']) ?></span></div>
      <p class="muted"><?= e(site_tagline()) ?>. Find trusted furniture sellers, finishing professionals and material suppliers near you.</p>
      <?php if (sys('general.contact_phone')): ?><p class="muted small">📞 <a href="tel:<?= e(sys('general.contact_phone')) ?>"><?= e(sys('general.contact_phone')) ?></a></p><?php endif; ?>
    </div>
    <div>
      <h4>Marketplace</h4>
      <a href="<?= url('products') ?>">Furniture & Decor</a>
      <a href="<?= url('services') ?>">Finishing Services</a>
      <a href="<?= url('supplies') ?>">Supplies & Materials</a>
      <?php if (feature_enabled('videos')): ?><a href="<?= url('videos') ?>">Video Feed</a><?php endif; ?>
    </div>
    <div>
      <h4>For Vendors</h4>
      <a href="<?= url('register') ?>">Open a Shop</a>
      <a href="<?= url('vendor') ?>">Vendor Dashboard</a>
      <a href="<?= url('vendor/verification') ?>">Get Verified</a>
    </div>
    <div>
      <h4>Company</h4>
      <a href="<?= url('about') ?>">About</a>
      <a href="<?= url('contact') ?>">Contact</a>
      <a href="<?= url('terms') ?>">Terms of Service</a>
      <a href="<?= url('privacy') ?>">Privacy Policy</a>
    </div>
    <div>
      <h4>Focus Cities</h4>
      <?php foreach (array_slice(array_keys(CITIES), 0, 5) as $c): ?>
        <a href="<?= url('products?city=' . urlencode($c)) ?>"><?= e($c) ?></a>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="container footer-bottom">&copy; <?= date('Y') ?> <?= e(site_name()) ?>. Ethiopia first, then East Africa.</div>
</footer>
<?php
$mnPath = trim(str_replace(BASE_URL, '', parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)), '/');
$mnCur = fn(string $p) => ($p === '' ? $mnPath === '' : str_starts_with($mnPath, $p)) ? 'current' : '';
$mnUser = auth();
$mnAccount = $mnUser ? (is_admin($mnUser) ? 'admin' : (is_vendor($mnUser) ? 'vendor' : 'account')) : 'login';
?>
<nav class="mobile-nav" aria-label="Mobile">
  <a href="<?= url('') ?>" class="<?= $mnCur('') ?>"><span class="mn-icon"><?= system_ui_icon('home', 'Home') ?></span>Home</a>
  <a href="<?= url('products') ?>" class="<?= $mnCur('products') ?>"><span class="mn-icon"><?= system_ui_icon('shop', 'Shop') ?></span>Shop</a>
  <?php if (feature_enabled('videos')): ?><a href="<?= url('videos') ?>" class="<?= $mnCur('videos') ?>"><span class="mn-icon"><?= system_ui_icon('play', 'Videos') ?></span>Videos</a><?php endif; ?>
  <?php if (feature_enabled('cart')): ?><a href="<?= url('cart') ?>" class="<?= $mnCur('cart') ?>"><span class="mn-icon"><?= system_ui_icon('cart', 'Cart') ?></span>Cart<?= cart_count() ? ' (' . cart_count() . ')' : '' ?></a><?php endif; ?>
  <a href="<?= url($mnAccount) ?>" class="<?= $mnCur($mnAccount) ?>"><span class="mn-icon"><?= system_ui_icon('user', 'Account') ?></span>Account</a>
</nav>
<script src="<?= url('assets/js/app.js') ?>"></script>
</body>
</html>
