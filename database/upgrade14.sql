-- Unified event tracking and commercial attribution source.
-- Keeps legacy ad_events/video_events/inquiries.source intact while adding one shared analytics table.

CREATE TABLE IF NOT EXISTS events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NULL,
    session_id VARCHAR(128) NULL,
    event_type ENUM('view','favorite','inquiry','cart_add','order','ad_impression','ad_click','video_view','video_cta_click','web_vital','search') NOT NULL,
    listing_type ENUM('product','service','supply','business','video','ad','order') NULL,
    listing_id BIGINT UNSIGNED NULL,
    business_id BIGINT UNSIGNED NULL,
    category_id BIGINT UNSIGNED NULL,
    source ENUM('organic','promoted','video_feed','ad') NOT NULL DEFAULT 'organic',
    city VARCHAR(100) NULL,
    subcity VARCHAR(100) NULL,
    referrer VARCHAR(255) NULL,
    metadata JSON NULL,
    ip VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_events_type_created (event_type, created_at),
    KEY idx_events_listing (listing_type, listing_id, created_at),
    KEY idx_events_business (business_id, created_at),
    KEY idx_events_source (source, created_at),
    FOREIGN KEY (user_id) REFERENCES users(id)
);

ALTER TABLE inquiries ADD COLUMN traffic_source ENUM('organic','promoted','video_feed','ad') NOT NULL DEFAULT 'organic' AFTER source;
ALTER TABLE orders ADD COLUMN traffic_source ENUM('organic','promoted','video_feed','ad') NOT NULL DEFAULT 'organic' AFTER payment_method;
