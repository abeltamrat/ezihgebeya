-- EzihGebeya Phase 3-5 upgrade: orders, payments, promotions, subscriptions
USE ezihgebeya;

CREATE TABLE IF NOT EXISTS orders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_number VARCHAR(30) UNIQUE NOT NULL,
    customer_id BIGINT UNSIGNED NOT NULL,
    business_id BIGINT UNSIGNED NOT NULL,
    status ENUM('pending','confirmed','deposit_paid','processing','ready_for_delivery','out_for_delivery','delivered','completed','cancelled','refunded','disputed') DEFAULT 'pending',
    delivery_option ENUM('pickup','delivery') DEFAULT 'pickup',
    delivery_address TEXT NULL,
    city VARCHAR(100),
    subcity VARCHAR(100),
    phone VARCHAR(30),
    note TEXT NULL,
    subtotal DECIMAL(12,2) DEFAULT 0,
    delivery_fee DECIMAL(12,2) DEFAULT 0,
    total DECIMAL(12,2) DEFAULT 0,
    payment_method ENUM('cash_on_delivery','bank_transfer','telebirr','cbe_birr') DEFAULT 'cash_on_delivery',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES users(id),
    FOREIGN KEY (business_id) REFERENCES businesses(id)
);

CREATE TABLE IF NOT EXISTS order_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT UNSIGNED NOT NULL,
    listing_type ENUM('product','supply') NOT NULL,
    listing_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    unit_price DECIMAL(12,2) NOT NULL,
    quantity DECIMAL(12,2) NOT NULL DEFAULT 1,
    line_total DECIMAL(12,2) NOT NULL,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS subscriptions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    business_id BIGINT UNSIGNED NOT NULL,
    plan ENUM('free','basic','pro','premium') NOT NULL DEFAULT 'free',
    months INT DEFAULT 1,
    starts_at DATETIME NULL,
    ends_at DATETIME NULL,
    status ENUM('pending','active','expired','cancelled','rejected') DEFAULT 'pending',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (business_id) REFERENCES businesses(id)
);

CREATE TABLE IF NOT EXISTS promotions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    business_id BIGINT UNSIGNED NOT NULL,
    promotable_type ENUM('product','service','supply','business','video') NOT NULL,
    promotable_id BIGINT UNSIGNED NOT NULL,
    promotion_type ENUM('homepage_banner','category_featured','city_featured','search_top_result','video_feed_boost','business_profile_boost') NOT NULL,
    duration_weeks INT DEFAULT 1,
    city VARCHAR(100),
    subcity VARCHAR(100),
    budget DECIMAL(12,2) DEFAULT 0,
    spent DECIMAL(12,2) DEFAULT 0,
    pricing_type ENUM('fixed_daily','fixed_weekly','fixed_monthly','cpc','cpl') DEFAULT 'fixed_weekly',
    status ENUM('pending','active','paused','completed','rejected','cancelled') DEFAULT 'pending',
    starts_at DATETIME NULL,
    ends_at DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (business_id) REFERENCES businesses(id)
);

CREATE TABLE IF NOT EXISTS payments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payer_id BIGINT UNSIGNED NOT NULL,
    business_id BIGINT UNSIGNED NULL,
    order_id BIGINT UNSIGNED NULL,
    promotion_id BIGINT UNSIGNED NULL,
    subscription_id BIGINT UNSIGNED NULL,
    payment_type ENUM('order_payment','ad_payment','subscription_payment','featured_listing_payment','commission_payment','refund','wallet_topup') NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    currency VARCHAR(10) DEFAULT 'ETB',
    payment_method ENUM('bank_transfer','telebirr','cbe_birr','cash') DEFAULT 'bank_transfer',
    reference_number VARCHAR(150) NULL,
    proof_image VARCHAR(255) NULL,
    status ENUM('pending','confirmed','rejected') DEFAULT 'pending',
    confirmed_by BIGINT UNSIGNED NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (payer_id) REFERENCES users(id),
    FOREIGN KEY (order_id) REFERENCES orders(id),
    FOREIGN KEY (promotion_id) REFERENCES promotions(id),
    FOREIGN KEY (subscription_id) REFERENCES subscriptions(id)
);
