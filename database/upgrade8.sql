-- Structured listing rejection reasons and seller-facing correction notes.

ALTER TABLE products
  ADD COLUMN rejection_reason VARCHAR(80) NULL AFTER sold_at,
  ADD COLUMN rejection_note TEXT NULL AFTER rejection_reason;

ALTER TABLE services
  ADD COLUMN rejection_reason VARCHAR(80) NULL AFTER sold_at,
  ADD COLUMN rejection_note TEXT NULL AFTER rejection_reason;

ALTER TABLE supplies
  ADD COLUMN rejection_reason VARCHAR(80) NULL AFTER sold_at,
  ADD COLUMN rejection_note TEXT NULL AFTER rejection_reason;
