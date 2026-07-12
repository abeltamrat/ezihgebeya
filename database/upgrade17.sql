-- Per-user notification category preferences.
-- Critical account/security/payment/moderation notices remain mandatory.

CREATE TABLE IF NOT EXISTS user_notification_preferences (
    user_id BIGINT UNSIGNED NOT NULL,
    category ENUM('inquiries','orders','reviews','promotions','support') NOT NULL,
    enabled BOOLEAN NOT NULL DEFAULT TRUE,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, category),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
