-- EzihGebeya Ads module (spec §9): placements, targeting, pricing, tracking
USE ezihgebeya;

CREATE TABLE IF NOT EXISTS ads (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    advertiser_name VARCHAR(200) NOT NULL,
    advertiser_phone VARCHAR(30) NULL,
    business_id BIGINT UNSIGNED NULL,
    title VARCHAR(150) NULL,
    body VARCHAR(300) NULL,
    image VARCHAR(255) NULL,
    destination_url VARCHAR(500) NOT NULL,
    placement ENUM('any','home_hero','home_inline','browse_top','browse_inline','detail_sidebar','video_slide') NOT NULL DEFAULT 'any',
    market_type ENUM('any','product','service','supply') NOT NULL DEFAULT 'any',
    category_id BIGINT UNSIGNED NULL,
    city VARCHAR(100) NULL,
    subcity VARCHAR(100) NULL,
    pricing_type ENUM('cpm','cpc','flat_weekly') NOT NULL DEFAULT 'cpc',
    unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
    budget DECIMAL(12,2) NOT NULL DEFAULT 0,
    spent DECIMAL(12,2) NOT NULL DEFAULT 0,
    priority TINYINT NOT NULL DEFAULT 1,
    status ENUM('draft','active','paused','completed','archived') NOT NULL DEFAULT 'draft',
    starts_at DATETIME NULL,
    ends_at DATETIME NULL,
    impressions_count INT NOT NULL DEFAULT 0,
    clicks_count INT NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (business_id) REFERENCES businesses(id),
    FOREIGN KEY (category_id) REFERENCES categories(id)
);

CREATE TABLE IF NOT EXISTS ad_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ad_id BIGINT UNSIGNED NOT NULL,
    event_type ENUM('impression','click') NOT NULL,
    placement VARCHAR(30) NULL,
    city VARCHAR(100) NULL,
    session_id VARCHAR(64) NULL,
    ip VARCHAR(60) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_ad_events (ad_id, event_type, created_at),
    FOREIGN KEY (ad_id) REFERENCES ads(id) ON DELETE CASCADE
);

ALTER TABLE payments ADD COLUMN ad_id BIGINT UNSIGNED NULL AFTER subscription_id;
