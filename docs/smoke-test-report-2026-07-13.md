# EzihGebeya full local smoke-test report

Date: 2026-07-13  
Target: `http://localhost:8899`  
Environment: PHP built-in development server, local MySQL, production Vite artifact

## Result

- User-facing/read smoke assertions: **108 passed, 0 failed**.
- Transactional workflow assertions: **11 passed, 0 failed**.
- Frontend unit/integration tests: **41 passed, 0 failed**.
- Backend regression assertions: **8 passed, 0 failed**.
- PHP syntax: **80 files passed**.
- Frontend lint, TypeScript, Vite build, public Tailwind build: **passed**.
- Local release gate: **9 passed, 0 warnings, 0 failures**.
- Database migrations: **23 applied, 0 partial/missing**.
- Smoke cleanup audit: **0 temporary rows/files remain**.

## Surfaces exercised

### Public, SEO, and PWA

Home; product/service/supply browse; search; video feed; About; Contact; Terms;
Privacy; prohibited-items policy; support auth handoff; login; registration;
password recovery; sanctions appeal; offline page; manifest; service worker; sitemap
index and all five sitemap partitions; public products/categories API.

SEO audit also confirmed titles, descriptions, canonical URLs, Open Graph/Twitter
metadata, and JSON-LD on real product, service, supply, and business detail URLs.

### Browser behavior

Real headless Chromium exercised the HTMX Sofa category transition. The result card
was present, visible, and at opacity 1. Product AR `.glb` media was not emitted as an
`<img>` thumbnail. Public, customer, vendor, and admin passes produced no page-level
JavaScript exceptions.

### Authentication and authorization

Customer, vendor, and temporary super-admin logins; anonymous session bootstrap;
CSRF rejection for missing tokens; anonymous API rejection; customer-to-vendor and
vendor-to-admin role rejection; own/foreign resource ownership behavior; invalid
bearer-token rejection; cron rejection without the header secret; private-download
auth handoff; sensitive path and traversal variants.

### Customer

Account/settings, favorites, inquiries/threads, notifications, reviews/reports,
orders, cart, checkout, favorite add/remove restoration, cart add/remove, inquiry
creation, review submission, report submission, order placement, and manual payment
submission.

### Vendor

Dashboard; business profile; product/service/supply listing lists and metadata;
inquiries; reply and status transition; orders; payment confirmation and order state
transition; TOP/Boost state; video metadata/list; verification state and missing-file
validation; reviews; analytics; product listing create/update/delete; image upload;
video create/delete; Boost subscription/cancellation.

### Admin

React health and monetization screens/APIs plus PHP dashboard, businesses, listings,
videos, reviews, reports, users, orders, payments, verification, analytics, settings,
and backups screens.

## Bugs found and fixed during the run

1. HTMX-swapped listing cards remained at `opacity: 0` because the reveal observer
   only bound on initial page load. Reveal behavior now rebinds after every HTMX swap.
2. Product cards/search/cart could select an AR `.glb` model as an image. All thumbnail
   queries now require `media_type = 'image'`.
3. The development router exposed existing internal files because PHP's built-in server
   ignores `.htaccess`. It now mirrors the sensitive-path deny-list, blocks uploaded PHP,
   guards traversal, applies development security/cache headers, and serves only approved
   static files.
4. Missing `/app/assets/*` requests returned the React HTML shell. Development, PHP
   fallback, and Apache rules now return 404 for missing hashed assets.
5. Stale Vite hashed files accumulated under `app/assets`. `npm run spa:sync` produced a
   clean artifact containing only the current build.

## Environment/coverage limits

The following require external systems or the production host and are not proven by a
local smoke test:

- Yegara domain/base-path mapping, HTTPS certificate, LiteSpeed/Apache headers, and cPanel cron.
- Real SMS, email, Firebase, TikTok outbound behavior, and payment-gateway settlement.
- Representative physical Android devices and genuinely slow/mobile networks.
- A destructive restore drill against the final production backup.
- Valid verification-document submission was not added to the existing vendor history;
  missing-document rejection and protected-download authorization were tested, while a
  prior isolated end-to-end verification upload test remains documented in `PLAN.md`.
- Smoke testing covers user-facing workflows and critical boundaries. It is not branch-level
  unit coverage for every internal PHP helper.

The production-mode release gate correctly remains red locally because `CRON_SECRET` is
empty, local XAMPP uses root with a blank database password, and `APP_BASE_URL` is not
explicit. These must be configured on Yegara rather than patched with fake local values.

The local `0911000000` super-admin password no longer matches the seeded `admin123`
credential documented for a fresh database. The audit did not overwrite that account;
admin smoke tests used a temporary isolated super-admin that was deleted afterward.

## Repeatable command

The non-destructive/reversible route and API suite is retained as:

```powershell
$env:SMOKE_ADMIN_PHONE = 'YOUR_LOCAL_ADMIN_PHONE'
$env:SMOKE_ADMIN_PASSWORD = 'YOUR_LOCAL_ADMIN_PASSWORD'
node tools/smoke_full.mjs
```

The broader transactional pass used one unique marker, removed its rows/files afterward,
and was intentionally not retained as a casually runnable command because an interrupted
run could leave test commerce records behind.
