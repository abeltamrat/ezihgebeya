</main>

<!-- ── Toast / Snackbar stack ─────────────────────────────────────────── -->
<div x-data
     class="toast-stack"
     aria-live="polite"
     aria-atomic="false">
  <template x-for="toast in $store.toasts.items" :key="toast.id">
    <div class="alert"
         :class="'alert-' + toast.type"
         x-show="true"
         x-transition:enter="toast-enter"
         x-transition:leave="toast-leave">
      <span class="toast-msg" x-text="toast.msg"></span>
      <button class="btn btn-xs btn-circle btn-ghost" @click="$store.toasts.remove(toast.id)" aria-label="Dismiss">&times;</button>
    </div>
  </template>
</div>

<!-- ── Cart drawer ────────────────────────────────────────────────────── -->
<?php if (feature_enabled('cart')): ?>
<div x-data="{ open: false }"
     @open-cart.window="open = true; $nextTick(() => { var b = $el.querySelector('.drawer-body'); if (b && !b.dataset.loaded) { htmx.trigger(b, 'load-cart'); b.dataset.loaded = '1'; } })"
     @keydown.escape.window="open = false">

  <div class="cart-drawer-overlay"
       x-show="open"
       x-cloak
       x-transition:enter="overlay-fade-enter"
       x-transition:leave="overlay-fade-leave"
       @click="open = false">
  </div>

  <div class="cart-drawer"
       x-show="open"
       x-cloak
       x-transition:enter="drawer-slide-enter"
       x-transition:leave="drawer-slide-leave"
       role="dialog"
       aria-modal="true"
       aria-label="Shopping cart">
    <div class="drawer-header">
      <h2><?= system_ui_icon('cart', 'Cart') ?> &nbsp;My Cart</h2>
      <button class="drawer-close" @click="open = false" aria-label="Close cart">&times;</button>
    </div>
    <div class="drawer-body"
         hx-get="<?= url('cart/drawer') ?>"
         hx-trigger="load-cart"
         hx-swap="innerHTML">
      <!-- skeleton while loading -->
      <div class="skeleton-card" style="margin-bottom:12px">
        <div class="skeleton-body">
          <div class="skeleton skeleton-line sk-w-80"></div>
          <div class="skeleton skeleton-line sk-w-55"></div>
          <div class="skeleton skeleton-line sk-w-35"></div>
        </div>
      </div>
      <div class="skeleton-card">
        <div class="skeleton-body">
          <div class="skeleton skeleton-line sk-w-80"></div>
          <div class="skeleton skeleton-line sk-w-55"></div>
        </div>
      </div>
    </div>
    <div class="drawer-footer">
      <a href="<?= url('cart') ?>" class="btn btn-primary btn-block">View full cart &amp; checkout</a>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="install-prompt" id="install-prompt" hidden>
  <div>
    <strong>Install <?= e(site_name()) ?></strong>
    <span>Open faster from your home screen and keep shopping like an app.</span>
  </div>
  <div class="btn-row">
    <button class="btn btn-primary btn-sm" type="button" id="install-prompt-accept">Install</button>
    <button class="btn btn-ghost btn-sm" type="button" id="install-prompt-dismiss">Not now</button>
  </div>
</div>

<footer class="site-footer">
  <div class="container footer-grid">
    <div>
      <?php $footerUi = system_ui_config(); ?>
      <div class="logo"><span class="logo-mark"><?= e($footerUi['logo_mark']) ?></span> <span><?= e($footerUi['logo_text']) ?></span></div>
      <p class="muted"><?= e(site_tagline()) ?>. Find trusted furniture sellers, finishing professionals and material suppliers near you.</p>
      <?php if (sys('general.contact_phone')): ?><p class="muted small footer-phone"><?= system_ui_icon('phone', 'Phone') ?> <a href="tel:<?= e(sys('general.contact_phone')) ?>"><?= e(sys('general.contact_phone')) ?></a></p><?php endif; ?>
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
      <a href="<?= url('prohibited-items') ?>">Prohibited Items</a>
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
$mnSell = !$mnUser ? 'register' : (is_vendor($mnUser) ? 'vendor/listings/product/new' : 'account');
$mnSellLabel = is_vendor($mnUser) ? 'Post' : 'Sell';
?>
<nav class="mobile-nav" aria-label="Mobile">
  <a href="<?= url('') ?>" class="<?= $mnCur('') ?>"><span class="mn-icon"><?= system_ui_icon('home', 'Home') ?></span>Home</a>
  <a href="<?= url('products') ?>" class="<?= $mnCur('products') ?>"><span class="mn-icon"><?= system_ui_icon('shop', 'Shop') ?></span>Shop</a>
  <a href="<?= url($mnSell) ?>" class="mobile-sell <?= $mnCur($mnSell) ?>"><span class="mn-icon"><?= system_ui_icon('plus', 'Sell') ?></span><?= e($mnSellLabel) ?></a>
  <?php if (feature_enabled('videos')): ?><a href="<?= url('videos') ?>" class="<?= $mnCur('videos') ?>"><span class="mn-icon"><?= system_ui_icon('play', 'Videos') ?></span>Videos</a><?php endif; ?>
  <?php if (feature_enabled('cart')): ?><a href="<?= url('cart') ?>" class="<?= $mnCur('cart') ?>"><span class="mn-icon"><?= system_ui_icon('cart', 'Cart') ?></span>Cart<?= cart_count() ? ' (' . cart_count() . ')' : '' ?></a><?php endif; ?>
  <a href="<?= url($mnAccount) ?>" class="<?= $mnCur($mnAccount) ?>"><span class="mn-icon"><?= system_ui_icon('user', 'Account') ?></span>Account</a>
</nav>
<script src="<?= asset_url('assets/js/app.js') ?>"></script>
</body>
</html>
