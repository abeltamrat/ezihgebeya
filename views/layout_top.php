<?php
$u = auth();
$loc = user_location();
$pageTitle = $pageTitle ?? SITE_NAME;
$ui = system_ui_config();
$basePath = trim((string)parse_url(BASE_URL, PHP_URL_PATH), '/');
$requestPath = trim((string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');
if ($basePath !== '' && str_starts_with($requestPath, $basePath)) {
    $requestPath = trim(substr($requestPath, strlen($basePath)), '/');
}
$currentRoute = $requestPath === '' ? '' : explode('/', $requestPath)[0];
$navState = function (string $route) use ($currentRoute): string {
    return $currentRoute === $route ? ' class="is-active" aria-current="page"' : '';
};
$sellUrl = !$u ? 'register' : (is_vendor($u) ? 'app/vendor/listings/product/new' : 'account');
$sellLabel = is_vendor($u) ? 'Post listing' : 'Sell / Join';
?>
<!DOCTYPE html>
<html lang="en-ET">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?> - <?= e(site_name()) ?></title>
<meta name="description" content="<?= e($pageDesc ?? sys('seo.meta_description', SITE_TAGLINE)) ?>">
<?php if (!empty($robots)): ?><meta name="robots" content="<?= e($robots) ?>"><?php endif; ?>
<link rel="manifest" href="<?= url('manifest.webmanifest') ?>">
<meta name="theme-color" content="<?= e(system_ui_config()['brand']) ?>">
<link rel="icon" href="<?= url('assets/icons/icon-192.png') ?>">
<link rel="apple-touch-icon" href="<?= url('assets/icons/icon-192.png') ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= url('assets/css/app.css') ?>">
<?= system_ui_style_tag() ?>
<?php $canonicalUrl = absolute_url(url($canonical ?? default_canonical_path())); ?>
<link rel="canonical" href="<?= e($canonicalUrl) ?>">
<meta property="og:title" content="<?= e($pageTitle) ?> - <?= e(site_name()) ?>">
<meta property="og:description" content="<?= e($pageDesc ?? sys('seo.meta_description', SITE_TAGLINE)) ?>">
<meta property="og:type" content="<?= e($ogType ?? 'website') ?>">
<meta property="og:url" content="<?= e($canonicalUrl) ?>">
<?php if (!empty($ogImage)): ?><meta property="og:image" content="<?= e($ogImage) ?>"><?php endif; ?>
<meta name="twitter:card" content="<?= !empty($ogImage) ? 'summary_large_image' : 'summary' ?>">
<meta name="twitter:title" content="<?= e($pageTitle) ?> - <?= e(site_name()) ?>">
<meta name="twitter:description" content="<?= e($pageDesc ?? sys('seo.meta_description', SITE_TAGLINE)) ?>">
<?php if (!empty($ogImage)): ?><meta name="twitter:image" content="<?= e($ogImage) ?>"><?php endif; ?>
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
<a class="skip-link" href="#main-content">Skip to main content</a>
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
        <label class="sr-only" for="header-search-input">Search EzihGebeya listings</label>
        <input type="search" name="q" id="header-search-input"
               placeholder="Search furniture, services, supplies..."
               value="<?= e($_GET['q'] ?? '') ?>"
               aria-label="Search listings"
               aria-autocomplete="list"
               aria-controls="ac-results"
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
           role="listbox"
           aria-label="Search suggestions"
           @mousedown.prevent>
      </div>
    </div>
    <nav class="main-nav" aria-label="Primary">
      <a href="<?= url('products') ?>"<?= $navState('products') ?>><?= system_ui_icon('furniture', 'Furniture') ?> Furniture</a>
      <a href="<?= url('services') ?>"<?= $navState('services') ?>><?= system_ui_icon('services', 'Services') ?> Services</a>
      <a href="<?= url('supplies') ?>"<?= $navState('supplies') ?>><?= system_ui_icon('supplies', 'Supplies') ?> Supplies</a>
      <?php if (feature_enabled('videos')): ?><a href="<?= url('videos') ?>" class="nav-video<?= $currentRoute === 'videos' ? ' is-active' : '' ?>"<?= $currentRoute === 'videos' ? ' aria-current="page"' : '' ?>><?= system_ui_icon('play', 'Video') ?> Video</a><?php endif; ?>
      <?php if (feature_enabled('cart')): ?>
        <button class="btn btn-ghost btn-sm nav-cart-btn" title="Cart"
                aria-label="Open cart"
                @click="$dispatch('open-cart')"
                style="gap:6px;padding:8px 12px">
          <?= system_ui_icon('cart', 'Cart') ?>
          <?php if (cart_count()): ?><span class="pill" id="cart-pill"><?= cart_count() ?></span><?php endif; ?>
        </button>
      <?php endif; ?>
      <?php if ($u): $nUnread = unread_notifications((int)$u['id']); ?>
        <a href="<?= url('notifications') ?>" title="Notifications" aria-label="Notifications<?= $nUnread ? ' (' . (int)$nUnread . ' unread)' : '' ?>">🔔<?= $nUnread ? ' <span class="pill">' . $nUnread . '</span>' : '' ?></a>
      <?php endif; ?>
      <?php if (!$u): ?>
        <a href="<?= url('login') ?>">Login</a>
      <?php else: ?>
        <?php $dashUrl = default_post_login_path($u); ?>
        <a href="<?= url($sellUrl) ?>" class="btn btn-primary btn-sm nav-sell-btn"><?= system_ui_icon('shop', 'Sell') ?> <?= e($sellLabel) ?><?= system_ui_button_badge('join') ?></a>
        <div class="dropdown dropdown-end">
          <div tabindex="0" role="button" class="btn btn-ghost btn-sm gap-2 px-2">
            <div class="avatar placeholder">
              <div class="bg-primary text-primary-content rounded-full w-8 text-xs font-bold">
                <span><?= e(mb_strtoupper(mb_substr($u['full_name'], 0, 1))) ?></span>
              </div>
            </div>
            <span class="hidden sm:inline max-w-[100px] truncate text-sm font-semibold"><?= e(explode(' ', $u['full_name'])[0]) ?></span>
            <svg class="w-3 h-3 opacity-60" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
          </div>
          <ul tabindex="0" class="dropdown-content menu bg-base-100 rounded-box z-50 w-52 p-2 shadow-lg border border-base-300 mt-1">
            <li class="menu-title text-xs opacity-60 pb-1"><?= e($u['full_name']) ?></li>
            <li><a href="<?= url($dashUrl) ?>"><?= system_ui_icon('user', '') ?> <?= is_admin($u) ? 'Admin panel' : (is_vendor($u) ? 'Dashboard' : 'My account') ?><?= system_ui_button_badge('account') ?></a></li>
            <?php if (is_vendor($u)): ?>
            <li><a href="<?= url('app/vendor/listings/product/new') ?>">➕ Add listing</a></li>
            <?php endif; ?>
            <li><a href="<?= url('notifications') ?>"><?= system_ui_icon('bell', '') ?> Notifications<?php $nb = unread_notifications((int)$u['id']); if ($nb): ?><span class="badge badge-primary badge-sm"><?= $nb ?></span><?php endif; ?></a></li>
            <?php if (feature_enabled('cart')): ?>
            <li><a href="<?= url('account/orders') ?>"><?= system_ui_icon('orders', '') ?> My orders</a></li>
            <?php endif; ?>
            <li class="mt-1 border-t border-base-200 pt-1"><a href="<?= url('logout') ?>" class="text-error">🚪 Log out</a></li>
          </ul>
        </div>
      <?php endif; ?>
      <?php if (!$u): ?>
        <a href="<?= url($sellUrl) ?>" class="btn btn-primary btn-sm nav-sell-btn"><?= system_ui_icon('shop', 'Sell') ?> <?= e($sellLabel) ?><?= system_ui_button_badge('join') ?></a>
      <?php endif; ?>
    </nav>
  </div>
</header>
<div id="page-progress"></div>
<main id="main-content" tabindex="-1">
<?php $flashes = get_flashes(); if ($flashes): ?>
<script>
document.addEventListener('alpine:init', function() {
<?php foreach ($flashes as $f): ?>
  window.dispatchEvent(new CustomEvent('toast', {detail:{msg:<?= json_encode($f['msg']) ?>,type:<?= json_encode($f['type']) ?>}}));
<?php endforeach; ?>
});
</script>
<?php endif; ?>
