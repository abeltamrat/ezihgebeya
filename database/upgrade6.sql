-- Dynamic per-category listing attributes (PLAN.md → Marketplace fundamentals →
-- Dynamic category attributes). Two parts:
--   1. category_attributes: the attribute *definitions* an admin manages per category
--      (e.g. Sofa: seating capacity, frame material; MDF: grade, sheet size).
--   2. An `attributes` JSON column added to each listing table to hold the *values*
--      a vendor filled in, keyed by category_attributes.key_name. A single JSON blob
--      per listing avoids an EAV join for what is, at this catalog size, a small,
--      per-listing key/value set — matches the plan's "SQL first, don't over-engineer"
--      principle. Existing fixed columns (material, brand, color, dimensions, ...)
--      are untouched; this layer covers what they can't.

CREATE TABLE IF NOT EXISTS category_attributes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_id BIGINT UNSIGNED NOT NULL,
    key_name VARCHAR(100) NOT NULL,
    label VARCHAR(150) NOT NULL,
    input_type ENUM('text','number','select','boolean') NOT NULL DEFAULT 'text',
    options TEXT NULL,          -- JSON array of strings, only for input_type = 'select'
    unit VARCHAR(30) NULL,      -- e.g. "cm", "kg" — shown next to the value
    is_required BOOLEAN NOT NULL DEFAULT FALSE,
    is_filterable BOOLEAN NOT NULL DEFAULT TRUE,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_cat_attr (category_id, key_name),
    KEY idx_category (category_id),
    FOREIGN KEY (category_id) REFERENCES categories(id)
);

ALTER TABLE products ADD COLUMN attributes LONGTEXT NULL AFTER warranty;
ALTER TABLE services ADD COLUMN attributes LONGTEXT NULL AFTER starting_price;
ALTER TABLE supplies ADD COLUMN attributes LONGTEXT NULL AFTER stock_quantity;
