-- EzihGebeya upgrade 5: auth hardening, verification workflow, messaging,
-- notifications, video events, content pages, locations, audit log, API tokens,
-- review replies/images (spec §5.1, §5.2, §6.6, §10, §13, §14, §15, §16, §18, §22)
USE ezihgebeya;

-- §5.1 OTP by SMS + password reset codes
CREATE TABLE IF NOT EXISTS otp_codes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    phone VARCHAR(30) NOT NULL,
    purpose ENUM('verify_phone','reset_password') NOT NULL,
    code VARCHAR(10) NOT NULL,
    attempts TINYINT NOT NULL DEFAULT 0,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_otp (phone, purpose, used_at)
);

-- §22.1 login attempt throttling
CREATE TABLE IF NOT EXISTS login_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    identity VARCHAR(150) NOT NULL,
    ip VARCHAR(60) NULL,
    success TINYINT NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_attempts (identity, created_at),
    KEY idx_attempts_ip (ip, created_at)
);

-- §5.2 business verification request workflow with documents
CREATE TABLE IF NOT EXISTS verification_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    business_id BIGINT UNSIGNED NOT NULL,
    requested_level ENUM('document_verified','location_verified','premium_verified') NOT NULL DEFAULT 'document_verified',
    message TEXT NULL,
    status ENUM('pending','approved','rejected','changes_requested') NOT NULL DEFAULT 'pending',
    admin_note TEXT NULL,
    reviewed_by BIGINT UNSIGNED NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (business_id) REFERENCES businesses(id)
);

CREATE TABLE IF NOT EXISTS verification_documents (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id BIGINT UNSIGNED NOT NULL,
    doc_type ENUM('business_license','tin_certificate','national_id','shop_photo','portfolio','other') NOT NULL DEFAULT 'other',
    file_url VARCHAR(255) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (request_id) REFERENCES verification_requests(id) ON DELETE CASCADE
);

-- §10 two-way inquiry conversation (chat thread per inquiry)
CREATE TABLE IF NOT EXISTS inquiry_messages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    inquiry_id BIGINT UNSIGNED NOT NULL,
    sender_id BIGINT UNSIGNED NOT NULL,
    body TEXT NOT NULL,
    read_at DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_thread (inquiry_id, created_at),
    FOREIGN KEY (inquiry_id) REFERENCES inquiries(id) ON DELETE CASCADE,
    FOREIGN KEY (sender_id) REFERENCES users(id)
);

-- §15 in-app notification module
CREATE TABLE IF NOT EXISTS notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    type VARCHAR(50) NOT NULL,
    title VARCHAR(220) NOT NULL,
    body TEXT NULL,
    url VARCHAR(300) NULL,
    read_at DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_notif (user_id, read_at, created_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- §6.6 / spec 17.9 video engagement events
CREATE TABLE IF NOT EXISTS video_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    video_post_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    session_id VARCHAR(150) NULL,
    event_type ENUM('view','watch_3s','watch_10s','watch_25_percent','watch_50_percent','watch_75_percent','watch_complete','cta_click','profile_click','share','save','report') NOT NULL,
    watched_seconds INT DEFAULT 0,
    ip_address VARCHAR(60) NULL,
    user_agent TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_video_events (video_post_id, event_type, created_at),
    FOREIGN KEY (video_post_id) REFERENCES video_posts(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- §16.2 / §19.1 admin-editable content pages (About, Contact, Terms, Privacy…)
CREATE TABLE IF NOT EXISTS content_pages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(120) UNIQUE NOT NULL,
    title VARCHAR(200) NOT NULL,
    body MEDIUMTEXT NULL,
    status ENUM('published','draft') NOT NULL DEFAULT 'published',
    updated_by BIGINT UNSIGNED NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- §14 location hierarchy managed from admin (country > region > city > subcity > woreda/area)
CREATE TABLE IF NOT EXISTS locations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    parent_id BIGINT UNSIGNED NULL,
    name VARCHAR(150) NOT NULL,
    level ENUM('country','region','city','subcity','woreda','area') NOT NULL DEFAULT 'city',
    latitude DECIMAL(10,7) NULL,
    longitude DECIMAL(10,7) NULL,
    sort_order INT DEFAULT 0,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_loc (level, status),
    FOREIGN KEY (parent_id) REFERENCES locations(id)
);

-- §22.4.9 audit log of admin actions
CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    admin_id BIGINT UNSIGNED NOT NULL,
    action VARCHAR(100) NOT NULL,
    target_type VARCHAR(40) NULL,
    target_id BIGINT UNSIGNED NULL,
    details TEXT NULL,
    ip VARCHAR(60) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_audit (admin_id, created_at),
    FOREIGN KEY (admin_id) REFERENCES users(id)
);

-- §18 API bearer tokens
CREATE TABLE IF NOT EXISTS api_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) UNIQUE NOT NULL,
    label VARCHAR(100) NULL,
    last_used_at DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- §13 review images, one vendor reply, order link for verified purchases
ALTER TABLE reviews
    ADD COLUMN IF NOT EXISTS order_id BIGINT UNSIGNED NULL AFTER listing_id,
    ADD COLUMN IF NOT EXISTS images TEXT NULL AFTER comment,
    ADD COLUMN IF NOT EXISTS vendor_reply TEXT NULL AFTER images,
    ADD COLUMN IF NOT EXISTS vendor_replied_at DATETIME NULL AFTER vendor_reply;

-- §9.4 refund/credit adjustments on ad campaigns
ALTER TABLE ads
    ADD COLUMN IF NOT EXISTS credited DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER spent;
