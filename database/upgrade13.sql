-- Basic support workflow: ticket/callback queue with optional escalation to moderation reports.

CREATE TABLE IF NOT EXISTS support_tickets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    category ENUM('order','payment','vendor','listing','account','callback','other') NOT NULL DEFAULT 'other',
    subject VARCHAR(180) NOT NULL,
    message TEXT NOT NULL,
    phone VARCHAR(30) NULL,
    preferred_callback_at DATETIME NULL,
    related_type ENUM('product','service','supply','business','video','review','user','order','payment','inquiry','other') NULL,
    related_id BIGINT UNSIGNED NULL,
    status ENUM('open','waiting_user','escalated','resolved','closed') NOT NULL DEFAULT 'open',
    priority ENUM('low','normal','high') NOT NULL DEFAULT 'normal',
    assigned_admin_id BIGINT UNSIGNED NULL,
    admin_note TEXT NULL,
    report_id BIGINT UNSIGNED NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_support_status (status, created_at),
    KEY idx_support_user (user_id, created_at),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (assigned_admin_id) REFERENCES users(id),
    FOREIGN KEY (report_id) REFERENCES reports(id)
);
