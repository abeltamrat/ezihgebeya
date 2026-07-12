-- Account sanctions and appeals.

CREATE TABLE IF NOT EXISTS account_sanctions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    admin_id BIGINT UNSIGNED NULL,
    level ENUM('warning','suspension','ban') NOT NULL,
    reason VARCHAR(120) NOT NULL DEFAULT 'policy_violation',
    admin_note TEXT NULL,
    status ENUM('active','lifted') NOT NULL DEFAULT 'active',
    appeal_status ENUM('none','pending','approved','rejected') NOT NULL DEFAULT 'none',
    appeal_message TEXT NULL,
    appeal_response TEXT NULL,
    appealed_at DATETIME NULL,
    reviewed_by BIGINT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    lifted_at DATETIME NULL,
    KEY idx_user_status (user_id, status),
    KEY idx_appeal (appeal_status, appealed_at),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (admin_id) REFERENCES users(id),
    FOREIGN KEY (reviewed_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
