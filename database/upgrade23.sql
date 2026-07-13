-- Nightly-cron precompute for the admin Trust panel's suspicious-activity trend
-- (PLAN.md "Move admin computation off the request path" + "alert admins when new
-- flags appear"). One row per cron run; admin_more.php reads the latest row instead
-- of recomputing the 5 flag queries on every page load.
CREATE TABLE IF NOT EXISTS admin_suspicious_flags (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    underpriced_flags INT UNSIGNED NOT NULL DEFAULT 0,
    listing_flood_flags INT UNSIGNED NOT NULL DEFAULT 0,
    report_cluster_flags INT UNSIGNED NOT NULL DEFAULT 0,
    duplicate_title_flags INT UNSIGNED NOT NULL DEFAULT 0,
    ad_click_fraud_flags INT UNSIGNED NOT NULL DEFAULT 0,
    computed_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
