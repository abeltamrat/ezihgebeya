-- Tenant monetization ladder: TOP Pin (Rung 1) + Boost subscriptions (Rung 2).
-- Additive only — reuses the existing promotions/subscriptions tables and their
-- generic admin payment-confirm activation path rather than new infrastructure.

-- `subscriptions.plan` currently mixes only listing-quota plans (free/basic/pro/premium).
-- Adding boost tiers to the same column without a `type` column would make current_plan()
-- (which just takes the latest active subscription row) misread an active Boost purchase
-- as the vendor's listing-quota plan, silently overriding their real listing limits.
ALTER TABLE subscriptions
    ADD COLUMN type ENUM('listing_plan','boost') NOT NULL DEFAULT 'listing_plan' AFTER business_id;

ALTER TABLE subscriptions
    MODIFY COLUMN plan ENUM('free','basic','pro','premium','boost_basic','boost_pro','boost_max') NOT NULL DEFAULT 'free';

-- TOP Pin reuses `promotions` (promotable_type/id already generic); duration_weeks alone
-- can't express exact 7-day vs 30-day packages, so add an optional day-precision override.
ALTER TABLE promotions
    MODIFY COLUMN promotion_type ENUM('homepage_banner','category_featured','city_featured','search_top_result','video_feed_boost','business_profile_boost','top_pin') NOT NULL;

ALTER TABLE promotions
    ADD COLUMN duration_days INT NULL AFTER duration_weeks;
