-- Listing lifecycle: expiry, renewal, and sold/unavailable states.
-- Existing rows get a 60-day window from their latest update/creation date.

ALTER TABLE products
  MODIFY status ENUM('draft','pending_review','active','rejected','paused','expired','sold_out','deleted') DEFAULT 'pending_review',
  ADD COLUMN expires_at DATETIME NULL AFTER inquiries_count,
  ADD COLUMN renewed_at DATETIME NULL AFTER expires_at,
  ADD COLUMN sold_at DATETIME NULL AFTER renewed_at;

ALTER TABLE services
  MODIFY status ENUM('draft','pending_review','active','rejected','paused','expired','sold_out','deleted') DEFAULT 'pending_review',
  ADD COLUMN expires_at DATETIME NULL AFTER inquiries_count,
  ADD COLUMN renewed_at DATETIME NULL AFTER expires_at,
  ADD COLUMN sold_at DATETIME NULL AFTER renewed_at;

ALTER TABLE supplies
  MODIFY status ENUM('draft','pending_review','active','rejected','paused','expired','sold_out','out_of_stock','deleted') DEFAULT 'pending_review',
  ADD COLUMN expires_at DATETIME NULL AFTER inquiries_count,
  ADD COLUMN renewed_at DATETIME NULL AFTER expires_at,
  ADD COLUMN sold_at DATETIME NULL AFTER renewed_at;

UPDATE products
  SET expires_at = COALESCE(expires_at, DATE_ADD(COALESCE(updated_at, created_at, NOW()), INTERVAL 60 DAY))
  WHERE status IN ('active','pending_review','paused') AND expires_at IS NULL;

UPDATE services
  SET expires_at = COALESCE(expires_at, DATE_ADD(COALESCE(updated_at, created_at, NOW()), INTERVAL 60 DAY))
  WHERE status IN ('active','pending_review','paused') AND expires_at IS NULL;

UPDATE supplies
  SET expires_at = COALESCE(expires_at, DATE_ADD(COALESCE(updated_at, created_at, NOW()), INTERVAL 60 DAY))
  WHERE status IN ('active','pending_review','paused') AND expires_at IS NULL;
