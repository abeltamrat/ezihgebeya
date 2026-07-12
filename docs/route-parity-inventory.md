# EzihGebeya route parity inventory

Purpose: this is the guardrail before any legacy PHP screen is removed. Every route should have an explicit decision: keep, migrate, improve, or retire. This inventory reflects the current PHP router in `index.php`, visible navigation in `views/layout_top.php`, `views/layout_bottom.php`, and `views/vendor_nav.php`, plus the current hybrid strategy in `PLAN.md`.

Status legend:

- Keep: remains PHP/server-rendered in the chosen hybrid architecture.
- Migrate: candidate for React authenticated app after the Phase 2 pilot decision.
- Improve: keep the current surface but continue incremental PHP/HTMX/Alpine polish.
- Retire later: remove only after an accepted replacement exists and cutover is safe.
- Utility: route is infrastructure/API/asset support, not a user screen.

## Public marketplace and SEO routes

| Route | Role | Current capability | Decision | Notes |
|---|---|---|---|---|
| `/` | Public | Homepage, hero search, category tiles, local picks, featured marketplace sections | Keep / improve | SEO and first-impression page; keep PHP + System UI Optimizer hooks. |
| `/products` | Public | Product browse, filters, saved-search prompt, pagination/sorting | Keep / improve | Public SEO/discovery route; HTMX filtering/pagination remains a safe improvement path. |
| `/services` | Public | Service browse using shared browse page | Keep / improve | Same public-discovery rule as products. |
| `/supplies` | Public | Supply browse using shared browse page | Keep / improve | Same public-discovery rule as products. |
| `/products/{slug}` | Public | Product detail, media gallery, reviews, seller card, cart/inquiry/favorite/report actions | Keep / improve | Critical SEO/social-preview page; do not replace with SPA. |
| `/services/{slug}` | Public | Service detail, seller card, inquiry/review/report actions | Keep / improve | Critical SEO/social-preview page; do not replace with SPA. |
| `/supplies/{slug}` | Public | Supply detail, seller card, cart/inquiry/favorite/report actions | Keep / improve | Critical SEO/social-preview page; do not replace with SPA. |
| `/businesses/{slug}` | Public | Seller/business storefront, trust signals, reviews, inventory links | Keep / improve | Jiji-style storefront; SEO-sensitive. |
| `/videos` | Public | Watch & Buy video feed, CTA links, reporting, profile/listing links | Keep / improve | Public discovery surface; can remain PHP while event tracking improves. |
| `/videos/cta/{id}` | Public | Tracks video CTA and redirects to listing/business target | Utility | Keep as PHP tracking/redirect endpoint. |
| `/search` | Public | Global search across listings/businesses/videos/categories | Keep / improve | Public discovery route; keep crawlable results where useful. |
| `/search/suggest` | Public partial | HTMX autocomplete suggestions | Keep | Fragment endpoint used by header search. |
| `/page/{slug}` | Public | DB-backed content page | Keep | Content/admin-managed. |
| `/about`, `/contact`, `/terms`, `/privacy`, `/prohibited-items` | Public | Canonical DB-backed content pages | Keep | Legal/trust pages; keep PHP. |
| `/sitemap.xml`, `/sitemap-static.xml`, `/sitemap-products.xml`, `/sitemap-services.xml`, `/sitemap-supplies.xml`, `/sitemap-businesses.xml` | Public crawler | XML sitemaps | Utility | Keep PHP-generated. |
| `/manifest.webmanifest` | Public/PWA | Dynamic web manifest respecting base path | Utility | Keep. |
| `/offline` | Public/PWA | Offline fallback page | Utility | Keep. |

## Authentication and account shell

| Route | Role | Current capability | Decision | Notes |
|---|---|---|---|---|
| `/login` | Guest | PHP login, throttling, validated return URL | Keep | Auth remains PHP to share one session with public pages and APIs. |
| `/register` | Guest | PHP registration with account type and OTP handoff | Keep | Auth/onboarding entry point stays PHP by default. |
| `/verify` | Logged-in user | Phone OTP verification | Keep | Security-sensitive; stays PHP. |
| `/forgot-password` | Guest | Password reset OTP flow | Keep | Security-sensitive; stays PHP. |
| `/logout` | Logged-in user | Session invalidation | Keep | One PHP session for both shells. |
| `/appeal` | Suspended/banned user | Account-sanction appeal submission | Keep | Policy/safety workflow; not a React priority. |
| `/account` | Customer | Account overview, favorites, recent inquiries, order/cart/settings links | Migrate later / keep until accepted | Phase 3 customer vertical candidate; do not remove PHP until React replacement is accepted. |
| `/account/settings` | Customer | Profile settings, privacy export/delete, notification preferences | Migrate later / keep until accepted | Sensitive account/privacy workflow; PHP enforcement remains authoritative. |
| `/account/orders` | Customer | Order list, cancellation/payment-proof flow | Migrate later / keep until accepted | Money/payment route; requires careful acceptance tests. |
| `/account/saved-searches` | Customer | Manage saved searches and alerts | Migrate later / keep until accepted | Can remain PHP if React benefit is low. |
| `/notifications` | Logged-in user | Notification inbox with HTMX polling | Migrate later / keep until accepted | Existing PHP/HTMX works; React migration is optional after pilot decision. |
| `/inquiries/{id}` | Customer or vendor participant | Inquiry thread messaging with HTMX partial refresh | Migrate later / keep until accepted | Ownership checks must remain in PHP. |
| `/support` | Logged-in user | Support/callback ticket creation | Keep / improve | Low-value React target; keep PHP unless account app absorbs it later. |

## Customer/action endpoints

| Route | Role | Current capability | Decision | Notes |
|---|---|---|---|---|
| `/inquiry` | Public/logged-in POST | Creates listing inquiry/request quote | Utility | Keep PHP action; CSRF/rate limits/server validation required. |
| `/review` | Logged-in POST | Creates moderated review with optional photo | Utility | Keep PHP action; rate limits and ownership rules stay server-side. |
| `/report` | Public/logged-in POST | Creates report/moderation queue item | Utility | Keep PHP action. |
| `/favorite` | Logged-in POST | Toggles favorite/saved listing | Utility | May get React consumer later; PHP action remains canonical until API replacement accepted. |
| `/saved-search` | Logged-in POST | Saves/removes saved search alert | Utility | Keep; React can call API later if needed. |
| `/cart` | Customer/session | Cart page and cart mutation endpoint | Migrate later / keep until accepted | Transactional route; keep PHP until a tested React/cart replacement exists. |
| `/cart/drawer` | Customer/session partial | HTMX cart drawer fragment | Keep | Public shell enhancement; not a standalone page. |
| `/checkout` | Customer/session | Checkout and order creation | Migrate later / keep until accepted | Money-critical; requires browser tests before replacement. |
| `/location` | Public POST | Location preference save/redirect | Utility | Keep PHP. |
| `/ads/go/{id}` | Public | Ad click tracking and redirect | Utility | Keep PHP tracking endpoint. |
| `/videos/event` | Public POST | Video engagement event collection | Utility | Keep PHP event endpoint. |
| `/web-vitals` | Public POST | Core Web Vitals event collection | Utility | Keep PHP event endpoint. |

## Vendor routes

| Route | Role | Current capability | Decision | Notes |
|---|---|---|---|---|
| `/vendor` | Vendor | Vendor overview, stats, quick actions, latest inquiries | Migrate later / keep until accepted | React pilot/dashboard may replace some surface; retain PHP until accepted. |
| `/vendor/business` | Vendor | Business profile registration/edit, logo/cover upload | Migrate later / keep until accepted | Phase 3 vendor onboarding candidate. |
| `/vendor/listings/product` | Vendor | Product list management | Migrate later / keep until accepted | React pilot overlap; PHP stays until route parity and cutover approval. |
| `/vendor/listings/product/new` | Vendor | Product creation, validation, media upload | Migrate later / keep until accepted | Keep PHP server rules authoritative. |
| `/vendor/listings/product/edit/{id}` | Vendor | Product edit/media management/status changes | Migrate later / keep until accepted | Cross-account ownership tests required before replacement. |
| `/vendor/listings/product/renew/{id}` | Vendor POST | Renew listing freshness | Utility / migrate with listing vertical | Keep until React listing vertical owns it. |
| `/vendor/listings/product/sold/{id}` | Vendor POST | Mark product sold/unavailable | Utility / migrate with listing vertical | Keep until React listing vertical owns it. |
| `/vendor/listings/product/available/{id}` | Vendor POST | Restore product availability | Utility / migrate with listing vertical | Keep until React listing vertical owns it. |
| `/vendor/listings/product/delete/{id}` | Vendor POST | Soft-delete product | Utility / migrate with listing vertical | Keep until React listing vertical owns it. |
| `/vendor/listings/service...` | Vendor | Same lifecycle for service listings | Migrate later / keep until accepted | Shared `vendor_listings.php`. |
| `/vendor/listings/supply...` | Vendor | Same lifecycle for supply listings | Migrate later / keep until accepted | Shared `vendor_listings.php`. |
| `/vendor/videos` | Vendor | Submit/manage video links | Migrate later / keep until accepted | Public video feed stays PHP. |
| `/vendor/verification` | Vendor | Verification document upload/request tracking | Keep until private-download design exists | Sensitive documents; do not migrate casually. |
| `/vendor/reviews` | Vendor | Review list and vendor replies | Migrate later / keep until accepted | Moderation/response metrics involved. |
| `/vendor/inquiries` | Vendor | Inquiry inbox, status filtering, HTMX refresh | Migrate later / keep until accepted | Current PHP/HTMX is working. |
| `/vendor/orders` | Vendor | Vendor order management and payment confirmation | Migrate later / keep until accepted | Money-critical. |
| `/vendor/promotions` | Vendor | Legacy promotion purchase/manual proof flow | Keep until entitlement ledger redesign | Future monetization plan should replace semantics, not just UI. |
| `/vendor/subscription` | Vendor | Subscription purchase/manual proof flow | Keep until Boost-tier design is accepted | Future monetization gate. |
| `/vendor/analytics` | Vendor | Vendor funnel, money, listing, review/response analytics | Keep / optionally migrate later | Newly expanded PHP analytics; React migration not urgent. |

## Admin and operations routes

| Route | Role | Current capability | Decision | Notes |
|---|---|---|---|---|
| `/admin` | Admin/super admin | Admin dashboard and section router | Keep | Plan explicitly keeps PHP admin as default. |
| `/admin/{section}` | Admin/super admin | Businesses/listings/videos/reviews/reports/users/categories/content/settings/analytics/backups and other admin sections | Keep | Internal-only; React only for future new capabilities with defined APIs. |
| `/cron/{job}` | Cron secret | Daily maintenance, summaries, retention, reminders/digests | Utility | Keep PHP/cPanel-compatible. |

## APIs and app shell

| Route | Role | Current capability | Decision | Notes |
|---|---|---|---|---|
| `/api/v1/*` | Browser SPA/session | Session-cookie JSON API for React pilot | Keep / extend only as needed | Build endpoints per feature vertical only. |
| `/api/*` | External/API clients | Bearer-token REST API | Keep | Non-browser API; do not conflate with session SPA API. |
| `/app/*` | Authenticated React app | Static Vite shell handled by `.htaccess` | Keep for React pilot | Production serves static build only; no Node process. |

## Replacement rules before any PHP route is removed

1. The replacement must cover the route's visible UI and hidden side effects.
2. PHP must still enforce role, ownership, validation, pricing, and state transitions.
3. Direct refresh and logged-out/incorrect-role behavior must be tested.
4. Cross-account ownership attempts must fail closed.
5. Upload, validation, and payment failure states must be tested where applicable.
6. The old route must be redirected or removed from nav only after acceptance.
7. Public SEO routes are not React replacement candidates unless a separate crawl/social-preview solution is approved.

## Current high-risk removal blockers

- Phase 3 React expansion still depends on the pilot continue/narrow/stop decision.
- Private payment-proof and verification-document delivery still needs an authorized download endpoint before public upload folders can be locked down.
- Money-critical routes (`cart`, `checkout`, `account/orders`, `vendor/orders`, promotion/subscription payments) need browser tests before any replacement.
- PHP admin remains the default admin surface.
