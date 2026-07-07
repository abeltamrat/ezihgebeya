<?php $u = auth(); $loc = user_location(); $pageTitle = $pageTitle ?? SITE_NAME; $ui = system_ui_config(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?> - <?= e(site_name()) ?></title>
<meta name="description" content="<?= e($pageDesc ?? sys('seo.meta_description', SITE_TAGLINE)) ?>">
<link rel="manifest" href="<?= url('manifest.json') ?>">
<meta name="theme-color" content="#0f766e">
<link rel="icon" href="<?= url('assets/icons/icon-192.png') ?>">
<link rel="apple-touch-icon" href="<?= url('assets/icons/icon-192.png') ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= url('assets/css/app.css') ?>">
<?= system_ui_style_tag() ?>
<meta property="og:title" content="<?= e($pageTitle) ?> - <?= e(site_name()) ?>">
<meta property="og:description" content="<?= e($pageDesc ?? sys('seo.meta_description', SITE_TAGLINE)) ?>">
<meta property="og:type" content="website">
<?php if (!empty($ogImage)): ?><meta property="og:image" content="<?= e($ogImage) ?>"><?php endif; ?>
<?php if (!empty($jsonLd)): ?><script type="application/ld+json"><?= json_encode($jsonLd, JSON_UNESCAPED_SLASHES) ?></script><?php endif; ?>
<script async src="https://telegram.org/js/telegram-web-app.js"></script>
<meta name="csrf-token" content="<?= csrf_token() ?>">
<?= sys('seo.head_snippet', '') /* admin → Settings → SEO: analytics / verification tags */ ?>
<!-- Alpine.js (reactive UI components) -->
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.1/dist/cdn.min.js"></script>
<!-- HTMX (AJAX without writing JS) -->
<script src="https://unpkg.com/htmx.org@1.9.12" crossorigin="anonymous"></script>
<style>[x-cloak]{display:none!important}</style>
</head>
<body data-loc-source="<?= e($loc['source']) ?>" data-loc-city="<?= e($loc['city']) ?>">
<?php if (!empty($ui['announcement_enabled']) && trim((string)$ui['announcement_text']) !== ''): ?>
  <?php if (trim((string)$ui['announcement_url']) !== ''): ?>
    <a class="announcement-bar" href="<?= e($ui['announcement_url']) ?>"><?= e($ui['announcement_text']) ?></a>
  <?php else: ?>
    <div class="announcement-bar"><?= e($ui['announcement_text']) ?></div>
  <?php endif; ?>
<?php endif; ?>
<header class="site-header">
  <div class="container header-row">
    <a class="logo" href="<?= url('') ?>">
      <span class="logo-mark"><?= e($ui['logo_mark']) ?></span>
      <span><?= e($ui['logo_text']) ?></span>
    </a>
    <div class="search-wrap"
         x-data="searchAc()"
         @click.outside="close()"
         @keydown.escape.window="close()">
      <form class="header-search" action="<?= url('search') ?>" method="get" autocomplete="off">
        <input type="search" name="q" id="header-search-input"
               placeholder="Search furniture, services, supplies..."
               value="<?= e($_GET['q'] ?? '') ?>"
               x-ref="input"
               @input.debounce.300ms="suggest($event.target.value)"
               @keydown.arrow-down.prevent="focusResult(1)"
               @keydown.arrow-up.prevent="focusResult(-1)"
               @keydown.enter="submitOrGo($event)"
               hx-get="<?= url('search/suggest') ?>"
               hx-trigger="input delay:300ms changed"
               hx-target="#ac-results"
               hx-swap="innerHTML"
               hx-params="q"
               @htmx:after-swap="open = $el.value.length > 1">
        <button type="submit">Search</button>
      </form>
      <div id="ac-results"
           x-show="open && results"
           x-cloak
           class="autocomplete-drop"
           @mousedown.prevent>
      </div>
    </div>
    <nav class="main-nav" aria-label="Primary">
      <a href="<?= url('products') ?>"><?= system_ui_icon('furniture', 'Furniture') ?> Furniture</a>
      <a href="<?= url('services') ?>"><?= system_ui_icon('services', 'Services') ?> Services</a>
      <a href="<?= url('supplies') ?>"><?= system_ui_icon('supplies', 'Supplies') ?> Supplies</a>
      <?php if (feature_enabled('videos')): ?><a href="<?= url('videos') ?>" class="nav-video"><?= system_ui_icon('play', 'Video') ?> Video</a><?php endif; ?>
      <?php if (feature_enabled('cart')): ?>
        <button class="btn btn-ghost btn-sm nav-cart-btn" title="Cart"
                @click="$dispatch('open-cart')"
                style="gap:6px;padding:8px 12px">
          <?= system_ui_icon('cart', 'Cart') ?>
          <?php if (cart_count()): ?><span class="pill" id="cart-pill"><?= cart_count() ?></span><?php endif; ?>
        </button>
      <?php endif; ?>
      <?php if ($u): $nUnread = unread_notifications((int)$u['id']); ?>
        <a href="<?= url('notifications') ?>" title="Notifications">🔔<?= $nUnread ? ' <span class="pill">' . $nUnread . '</span>' : '' ?></a>
      <?php endif; ?>
      <?php if (!$u): ?>
        <a href="<?= url('login') ?>">Login</a>
        <a href="<?= url('register') ?>" class="btn btn-primary btn-sm">Sell / Join<?= system_ui_button_badge('join') ?></a>
      <?php else: ?>
        <?php if (is_admin($u)): ?><a href="<?= url('admin') ?>" class="btn btn-outline btn-sm">Admin<?= system_ui_button_badge('account') ?></a>
        <?php elseif (is_vendor($u)): ?><a href="<?= url('vendor') ?>" class="btn btn-outline btn-sm">Dashboard<?= system_ui_button_badge('account') ?></a>
        <?php else: ?><a href="<?= url('account') ?>" class="btn btn-outline btn-sm">My Account<?= system_ui_button_badge('account') ?></a><?php endif; ?>
        <a href="<?= url('logout') ?>" title="Log out (<?= e($u['full_name']) ?>)">Logout</a>
      <?php endif; ?>
    </nav>
  </div>
</header>
<div id="page-progress"></div>
<main>
<?php $flashes = get_flashes(); if ($flashes): ?>
<script>
document.addEventListener('alpine:init', function() {
<?php foreach ($flashes as $f): ?>
  window.dispatchEvent(new CustomEvent('toast', {detail:{msg:<?= json_encode($f['msg']) ?>,type:<?= json_encode($f['type']) ?>}}));
<?php endforeach; ?>
});
</script>
<?php endif; ?>
