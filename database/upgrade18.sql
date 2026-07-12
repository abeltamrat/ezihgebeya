-- Saved listing searches with daily alert checks.

CREATE TABLE IF NOT EXISTS saved_searches (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    listing_type ENUM('product','service','supply') NOT NULL,
    label VARCHAR(180) NOT NULL,
    query_string TEXT NOT NULL,
    query_hash CHAR(64) NOT NULL,
    alerts_enabled BOOLEAN NOT NULL DEFAULT TRUE,
    last_checked_at DATETIME NULL,
    last_notified_at DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_saved_search_user_hash (user_id, query_hash),
    KEY idx_saved_search_alerts (alerts_enabled, last_checked_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
