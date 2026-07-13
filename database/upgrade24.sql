-- Firebase Cloud Messaging web push (PLAN.md "Add Firebase Cloud Messaging web push
-- through the existing service worker"). Stores one row per registered browser/device
-- FCM token per user; a user may have several (multiple browsers/devices).
CREATE TABLE IF NOT EXISTS push_subscriptions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    fcm_token VARCHAR(255) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_used_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_fcm_token (fcm_token),
    KEY idx_user (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
