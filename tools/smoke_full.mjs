import playwright from '../frontend/node_modules/playwright/index.js';

const { chromium } = playwright;
const base = (process.env.SMOKE_BASE || 'http://localhost:8899').replace(/\/$/, '');
const results = [];
const failures = [];

function record(area, name, pass, detail = '') {
  const row = { area, name, pass, detail };
  results.push(row);
  if (!pass) failures.push(row);
  process.stdout.write(`${pass ? 'PASS' : 'FAIL'} [${area}] ${name}${detail ? ` — ${detail}` : ''}\n`);
}

async function httpCheck(area, path, expected = 200, contentType = '') {
  try {
    const response = await fetch(base + path, { redirect: 'manual' });
    const type = response.headers.get('content-type') || '';
    const pass = (Array.isArray(expected) ? expected : [expected]).includes(response.status) && (!contentType || type.includes(contentType));
    record(area, path, pass, `HTTP ${response.status}; ${type}`);
    return response;
  } catch (error) {
    record(area, path, false, error.message);
    return null;
  }
}

async function login(browser, phone, password, label) {
  const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  // Authentication does not depend on optional third-party page scripts; waiting
  // for the full load event makes the smoke suite flaky when those hosts are slow.
  await page.goto(base + '/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="identity"]').fill(phone);
  await page.locator('input[name="password"]').fill(password);
  await Promise.all([page.waitForURL(/\/(app|admin)/), page.locator('.auth-panel button').click()]);
  const me = await page.evaluate(baseUrl => fetch(baseUrl + '/api/v1/me').then(r => r.json()), base);
  record('auth', `${label} login`, me.authenticated === true, me.user?.account_type || 'not authenticated');
  return { context, page, me, errors };
}

async function api(page, path, options = {}) {
  return page.evaluate(async ({ baseUrl, path, options }) => {
    const me = await fetch(baseUrl + '/api/v1/me').then(r => r.json());
    const init = { method: options.method || 'GET', headers: { Accept: 'application/json' } };
    if (init.method !== 'GET') init.headers['X-CSRF-Token'] = me.csrf_token;
    if (options.body !== undefined) {
      init.headers['Content-Type'] = 'application/json';
      init.body = JSON.stringify(options.body);
    }
    const response = await fetch(baseUrl + '/api/v1' + path, init);
    let data = null;
    try { data = await response.json(); } catch { data = {}; }
    return { status: response.status, data };
  }, { baseUrl: base, path, options });
}

async function route(page, area, path, forbiddenText = '') {
  try {
    const response = await page.goto(base + path, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(path.startsWith('/app/') ? 250 : 30);
    const body = await page.locator('body').innerText();
    const status = response?.status() || 0;
    const pass = status < 500 && !body.includes('Something went wrong') && (!forbiddenText || !body.includes(forbiddenText));
    record(area, path, pass, `HTTP ${status}`);
  } catch (error) {
    record(area, path, false, error.message);
  }
}

// Public, SEO, PWA, and hardened-path surface.
for (const path of ['/', '/products', '/services', '/supplies', '/search?q=sofa', '/videos', '/about', '/contact', '/terms', '/privacy', '/prohibited-items', '/login', '/register', '/forgot-password', '/appeal', '/offline']) {
  await httpCheck('public', path);
}
await httpCheck('public', '/support', [200, 302]);
await httpCheck('pwa', '/manifest.webmanifest', 200, 'json');
await httpCheck('pwa', '/sw.js', 200, 'javascript');
await httpCheck('seo', '/sitemap.xml', 200, 'xml');
for (const kind of ['static', 'products', 'services', 'supplies', 'businesses']) await httpCheck('seo', `/sitemap-${kind}.xml`, 200, 'xml');
await httpCheck('api', '/api/products', 200, 'json');
await httpCheck('api', '/api/categories', 200, 'json');
await httpCheck('security', '/api/v1/vendor/dashboard', 401, 'json');
await httpCheck('security', '/cron/daily', [401, 403]);
for (const path of ['/.env', '/config.php', '/database/setup.sql', '/protected_uploads/test.jpg', '/frontend/package.json', '/storage/test.log']) await httpCheck('security', path, [403, 404]);

const browser = await chromium.launch({ headless: true });
try {
  // Real browser + HTMX regression.
  const publicPage = await browser.newPage({ viewport: { width: 740, height: 960 } });
  const publicErrors = [];
  publicPage.on('pageerror', e => publicErrors.push(e.message));
  await publicPage.goto(base + '/products');
  await publicPage.locator('.browse-cat-chip', { hasText: 'Sofa' }).click();
  await publicPage.waitForURL(/category=sofa/);
  await publicPage.waitForTimeout(350);
  const card = publicPage.locator('.browse-main .card').first();
  record('htmx', 'category filter reveals result card', await card.count() === 1 && await card.isVisible() && await card.evaluate(el => getComputedStyle(el).opacity) === '1');
  record('media', '3D model is not used as img thumbnail', await card.locator('img[src$=".glb"]').count() === 0);
  record('browser', 'public page JavaScript', publicErrors.length === 0, publicErrors.join('; '));

  publicErrors.length = 0;
  await publicPage.goto(base + '/products/king-size-bed-with-headboard-addis-ababa', { waitUntil: 'networkidle' });
  const galleryImage = publicPage.locator('#gallery-main');
  const galleryLoaded = await galleryImage.count() === 1 && await galleryImage.evaluate(img => img.complete && img.naturalWidth > 0);
  record('media', 'product detail gallery image loads', galleryLoaded);
  record('browser', 'product detail JavaScript', publicErrors.length === 0, publicErrors.join('; '));
  await publicPage.close();

  // Customer workflows and reversible cart/favorite mutations.
  const customer = await login(browser, '0955555555', 'demo123', 'customer');
  for (const path of ['/app/account', '/app/account/settings', '/app/account/notifications', '/app/account/inquiries', '/app/account/favorites', '/app/account/reviews', '/app/account/reports', '/app/account/orders', '/app/cart']) await route(customer.page, 'customer-ui', path, 'Not authorized');
  for (const path of ['/account/settings', '/account/favorites', '/account/inquiries', '/account/notifications', '/account/reviews', '/account/reports', '/account/orders', '/cart']) {
    const response = await api(customer.page, path);
    record('customer-api', path, response.status === 200 && response.data.ok === true, `HTTP ${response.status}`);
  }
  const wrongVendor = await api(customer.page, '/vendor/dashboard');
  record('authorization', 'customer rejected from vendor API', wrongVendor.status === 403, `HTTP ${wrongVendor.status}`);
  const products = await fetch(base + '/api/products').then(r => r.json());
  const productId = products.data?.[0]?.id;
  if (productId) {
    const favorites = await api(customer.page, '/account/favorites');
    const wasSaved = favorites.data.data.some(item => item.id === productId);
    const first = await api(customer.page, `/account/favorites/${productId}`, { method: wasSaved ? 'DELETE' : 'POST' });
    const restore = await api(customer.page, `/account/favorites/${productId}`, { method: wasSaved ? 'POST' : 'DELETE' });
    record('customer-write', 'favorite add/remove restores prior state', first.status === 200 && restore.status === 200);
    const add = await api(customer.page, '/cart', { method: 'POST', body: { do: 'add', listing_type: 'product', listing_id: productId, qty: 1 } });
    const remove = await api(customer.page, '/cart', { method: 'POST', body: { do: 'remove', listing_type: 'product', listing_id: productId } });
    record('customer-write', 'cart add/remove', add.status === 200 && add.data.cart_count >= 1 && remove.status === 200);
  }
  record('browser', 'customer React JavaScript', customer.errors.length === 0, customer.errors.join('; '));
  await customer.context.close();

  // Vendor route/API surface.
  const vendor = await login(browser, '0910101010', 'demo123', 'vendor');
  for (const path of ['/app/vendor', '/app/vendor/business', '/app/vendor/listings/product', '/app/vendor/listings/service', '/app/vendor/listings/supply', '/app/vendor/inquiries', '/app/vendor/orders', '/app/vendor/boost', '/app/vendor/videos', '/app/vendor/verification', '/app/vendor/reviews', '/app/vendor/analytics']) await route(vendor.page, 'vendor-ui', path, 'Not authorized');
  for (const path of ['/vendor/dashboard', '/vendor/business', '/vendor/listings/product/meta', '/vendor/listings/product', '/vendor/listings/service', '/vendor/listings/supply', '/vendor/inquiries', '/vendor/orders', '/vendor/boost', '/vendor/videos/meta', '/vendor/videos', '/vendor/verification', '/vendor/reviews', '/vendor/analytics']) {
    const response = await api(vendor.page, path);
    record('vendor-api', path, response.status === 200 && response.data.ok === true, `HTTP ${response.status}`);
  }
  const wrongAdmin = await api(vendor.page, '/admin/health');
  record('authorization', 'vendor rejected from admin API', wrongAdmin.status === 403, `HTTP ${wrongAdmin.status}`);
  record('browser', 'vendor React JavaScript', vendor.errors.length === 0, vendor.errors.join('; '));
  await vendor.context.close();

  // Admin React capabilities plus legacy PHP control center.
  const admin = await login(browser, process.env.SMOKE_ADMIN_PHONE || '0911000000', process.env.SMOKE_ADMIN_PASSWORD || 'admin123', 'admin');
  for (const path of ['/app/admin/health', '/app/admin/monetization', '/admin', '/admin/businesses', '/admin/listings', '/admin/videos', '/admin/reviews', '/admin/reports', '/admin/users', '/admin/orders', '/admin/payments', '/admin/verification', '/admin/analytics', '/admin/settings', '/admin/backups']) await route(admin.page, 'admin-ui', path, 'Not authorized');
  for (const path of ['/admin/health', '/admin/monetization']) {
    const response = await api(admin.page, path);
    record('admin-api', path, response.status === 200 && response.data.ok === true, `HTTP ${response.status}`);
  }
  record('browser', 'admin JavaScript', admin.errors.length === 0, admin.errors.join('; '));
  await admin.context.close();
} finally {
  await browser.close();
}

const summary = { base, passed: results.filter(r => r.pass).length, failed: failures.length, total: results.length, failures };
process.stdout.write(`SMOKE_SUMMARY ${JSON.stringify(summary)}\n`);
process.exit(failures.length ? 1 : 0);
