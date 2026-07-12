-- Nightly event summaries so dashboards read precomputed rows, plus raw-event retention support.

CREATE TABLE IF NOT EXISTS event_daily_summaries (
    event_date DATE NOT NULL,
    event_type ENUM('view','favorite','inquiry','cart_add','order','ad_impression','ad_click','video_view','video_cta_click','web_vital','search') NOT NULL,
    listing_type ENUM('none','product','service','supply','business','video','ad','order') NOT NULL DEFAULT 'none',
    listing_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    business_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    category_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    source ENUM('organic','promoted','video_feed','ad') NOT NULL DEFAULT 'organic',
    city VARCHAR(100) NOT NULL DEFAULT '',
    event_count INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (event_date, event_type, listing_type, listing_id, business_id, category_id, source, city),
    KEY idx_event_summary_business (business_id, event_date),
    KEY idx_event_summary_listing (listing_type, listing_id, event_date),
    KEY idx_event_summary_type_source (event_type, source, event_date)
);
