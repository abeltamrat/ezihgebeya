# EzihGebeya - Furniture, Finishing Works, Supplies & Service Marketplace

MVP built from *Development Documentation v1.0* (see the PDF in this folder).
Plain PHP 8 MVC + MySQL — runs on XAMPP now, deploys to any shared host later.

## Run locally

1. Start Apache + MySQL in XAMPP.
2. Open **http://localhost/ezihgebeya/**

### Re-create the database from scratch

```
Get-Content database\setup.sql | C:\xampp\mysql\bin\mysql.exe -u root
Get-Content database\upgrade2.sql | C:\xampp\mysql\bin\mysql.exe -u root
Get-Content database\upgrade3.sql | C:\xampp\mysql\bin\mysql.exe -u root
Get-Content database\upgrade5.sql | C:\xampp\mysql\bin\mysql.exe -u root
C:\xampp\php\php.exe database\seed.php
C:\xampp\php\php.exe database\seed5.php
```

## Demo accounts (phone / password)

| Role | Login | Password | Business |
|---|---|---|---|
| Super Admin | `0911000000` | `admin123` | — |
| Furniture Seller | `0910101010` | `demo123` | Meskerem Furniture (has demo orders/promos) |
| Seller (yours) | `0911111111` | `demo123` | Kings Wood |
| Manufacturer | `0922222222` | `demo123` | Dawit Custom Woodworks |
| Service Provider | `0933333333` | `demo123` | Hana Interiors |
| Supply Vendor | `0944444444` | `demo123` | Yonas Building Materials |
| Customer | `0955555555` | `demo123` | — |

## What's implemented (MVP scope, doc §27.1)

- Registration/login with role selection (customer, seller, manufacturer, importer, service provider, supplier)
- Business registration → **admin approval** workflow, verification levels + verified badges
- Product / Service / Supply listings (full CRUD in vendor dashboard) → **pending review → admin approve/reject**
- Public browsing with search, filters (category, city/subcity, price, condition, verified-only) and sorting
- Detail pages with image gallery, specs, seller panel, phone reveal
- Inquiry / Request-Quote forms (guests + logged-in), rate-limited; vendor inquiry inbox with lead statuses (`new → seen → responded → negotiating → converted / closed / spam`)
- **Watch & Buy video feed**: vendors submit TikTok/YouTube links (official embeds only, no hosting), admin moderation, CTA buttons with click tracking that deep-link to the listing
- Reviews with moderation + business rating aggregation; report-listing flow → admin reports queue
- Favorites (saved products) + customer account page
- Admin panel: dashboard KPI cards, business/listing/video/review moderation, reports, users, categories, lead tracking, featured-listing toggle
- PWA: manifest, 192/512 icons, service worker (installable)
- SEO-ish slugs (`/products/modern-l-shape-sofa-addis-ababa`)

## Phase 3–5 features (added after MVP)

- **Orders & cart (§11)**: session cart for products/supplies (respects min. order qty), checkout splits one order per shop, full order-status flow (`pending → confirmed → deposit_paid → … → completed`), customer *My Orders* with cancel, vendor + admin order management
- **Manual payments (§12.1)**: bank transfer / Telebirr / CBE Birr with reference number + proof screenshot upload; vendor confirms order payments, admin confirms everything in the `admin/payments` queue — confirming a payment auto-activates the linked order/promotion/subscription
- **Promotions (§9)**: featured-in-category/city, top-of-search, homepage banner, video feed boost, profile boost — priced per week, verified businesses only, activates on payment confirmation, expires via cron
- **Subscriptions (§26.2)**: Free/Basic/Pro/Premium plans with enforced listing & video limits; Premium grants the premium-verified badge and AR upload rights
- **AR preview (§7)**: Premium vendors upload `.glb`/`.usdz`; product pages render Google `<model-viewer>` with WebXR/Scene-Viewer/Quick-Look AR modes
- **Vendor analytics (§24.2)**: views, CTA clicks, lead conversion %, leads by source/status, top products, video CTR
- **Cron (§21.3)**: `GET /cron/daily?secret=ezih-cron-2026` — expires promotions/subscriptions, pauses listings of suspended businesses, closes stale inquiries (schedule via Task Scheduler / cPanel cron)
- **SEO (§25)**: `sitemap.xml`, JSON-LD Product/Service structured data, Open Graph tags
- **Telegram Mini App (§3.3)**: telegram-web-app.js loaded; inside Telegram the app expands full-screen and inquiry sources are tagged `telegram_mini_app`

## Structure

```
index.php            front controller + router (pretty URLs via .htaccess)
config.php           DB credentials, cities, enums
app/                 db.php (PDO), helpers.php (auth, csrf, uploads, embeds)
pages/               one file per route (public, vendor, admin)
views/               layout + shared partials
assets/              css / js / PWA icons
uploads/             user-uploaded + seeded images (PHP execution blocked)
database/            setup.sql (schema §17) + seed.php (demo data)
```

## Upgrade 5 (spec-gap closure, 2026-07-06)

- **OTP + password reset (§5.1, §22.1)**: 6-digit SMS codes for phone verification (`/verify`) and password reset (`/forgot-password`); login attempt throttling (8 fails / 15 min per identity or IP) and a 2-hour idle session timeout. With `DEV_MODE` on, codes are flashed on screen and all SMS/email land in `database/outbox.log` — wire a real gateway in `app/notify.php` at launch.
- **Verification workflow (§5.2)**: vendors upload license/TIN/ID/shop-photo documents at `/vendor/verification` → admin queue (`admin/verification`) approves / rejects / requests changes → badge level applied automatically.
- **Messaging (§10)**: two-way chat thread per inquiry (`/inquiries/{id}`) for customer and vendor; vendor replies flip lead status to `responded`.
- **Notifications (§15)**: in-app inbox (`/notifications`) + header bell; fired on inquiries, replies, order/payment status, listing/video moderation, verification results, reviews, subscription expiry (via cron). High-value events also mirror to SMS (outbox in dev).
- **Video analytics (§6.6)**: `video_events` table + feed JS records view/watch-time milestones/share/profile/CTA events; vendor analytics show watch time, deep watches, shares, CTR.
- **Ranking (§6.5, §8.4)**: weighted scores (location, engagement, verification, freshness, rating, promotion, report penalties) drive the video feed and the "Recommended" sort.
- **Search (§8)**: FULLTEXT-backed relevance, global `/search` across listings + businesses + videos + categories, filters for material/brand/color/type/delivery/installation/stock/discount/experience/price-type/thickness/unit/rating, and top-rated / most-inquired / discounts-first sorts.
- **Content pages (§16.2/§19.1)**: DB-backed About/Contact/Terms/Privacy (seeded) with an admin editor (`admin/pages`); footer links added.
- **REST API (§18)**: `/api/*` JSON with bearer tokens — login/register/otp/me/logout, products/services/supplies (+filters/pagination), businesses, videos/feed, inquiries, categories.
- **Admin additions (§16)**: locations manager (country→area hierarchy, seeded), platform analytics with §23.2 risky-listing + suspicious-ad-click detection, audit log of all admin actions, Admins & Roles (super admin), SQL backup download, ad credit adjustments (§9.4).
- **Reviews (§13)**: photos on reviews, verified-purchase badge wired to completed orders, one public vendor reply per review (`/vendor/reviews`), seller response rate on business profiles.
- **Uploads (§22.3)**: images are downscaled to 1600px, recompressed, and get a 400px `.thumb.jpg`.

## ⚙️ System Settings control center (2026-07-06)

Admin → **System Settings** (super admin) makes every system part editable live, stored
in `site_settings` (`app/settings.php`, read with `sys('group.key')`; config.php values
are the factory defaults):

- **General & identity**: site name, tagline, currency label, contact phone/email, default city, registration open/closed, **maintenance mode** (503 for everyone except admins, custom message).
- **Feature modules**: video feed, cart/checkout/orders, promotions, subscriptions, reviews, inquiries, AR preview, ad engine, REST API, location auto-detection — off = hidden from nav and routes blocked.
- **Moderation policy (§16.3)**: auto-approve vs manual review per content type (businesses / listings / videos / reviews).
- **Limits**: max images per listing, inquiry rate limit + window, video feed size, AR model size.
- **Plans & promotions**: price + listing/video limits per plan; price per promotion type.
- **Payments (§12)**: enable/disable each method, payment instructions shown at checkout and upgrade forms, commission % (surfaces "commission owed" in Analytics).
- **Ranking weights (§6.5/§8.4)**: every weight in the listing and video-feed scoring formulas.
- **Auth & security (§22.1)**: OTP required on/off, lockout attempts + window, session timeout, min password length.
- **Notifications**: SMS mirroring on/off, SMS gateway URL (`{phone}`/`{message}` placeholders), email from-address.
- **SEO**: default meta description + a head snippet injected on every page (analytics tags).

"Reset to defaults" restores the factory configuration. All admin saves are audit-logged.

## Still not built (future scale phase, §28 Phase 6)

Automated payment gateway integration (Telebirr API/Chapa), escrow, native video
upload, real-time WebSocket chat (inquiry threads are request/response), delivery
tracking, native mobile apps, AI recommendations — these need VPS/cloud hosting
per the spec. Google/Telegram social login and Fayda ID checks (spec-optional)
also remain.
