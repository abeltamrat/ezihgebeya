-- Secure, opt-in quick login for a previously trusted browser. The browser keeps
-- only an opaque selector/validator cookie; the validator itself is stored here
-- only as a SHA-256 hash. Tokens expire, rotate after use, and cascade away when
-- their user account is deleted.
CREATE TABLE IF NOT EXISTS remembered_login_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    selector CHAR(32) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    user_agent VARCHAR(255) NULL,
    expires_at DATETIME NOT NULL,
    last_used_at DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_remembered_selector (selector),
    KEY idx_remembered_user (user_id),
    KEY idx_remembered_expiry (expires_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
