-- EzihGebeya upgrade 21: close a race condition in "one review per user per target".
-- action_review.php enforces this with a SELECT COUNT(*) check followed by a separate
-- INSERT — two concurrent requests (double-click, two tabs) can both pass the check
-- before either commits, inserting two reviews for the same target. Add a real UNIQUE
-- constraint so the database enforces the invariant atomically; the app-level check
-- stays as a fast path, and the INSERT is wrapped in try/catch to treat the resulting
-- duplicate-key error as "already reviewed" rather than a bug (same pattern already
-- used by synonym_add in pages/admin.php).
--
-- listing_id is NULL for business-type reviews, and MySQL/MariaDB unique indexes treat
-- each NULL as distinct (so a plain UNIQUE KEY on the raw column would not block
-- duplicates there) — a generated column normalizes NULL to 0 so the unique key applies
-- uniformly across listing and business reviews.
ALTER TABLE reviews
    ADD COLUMN IF NOT EXISTS listing_id_key BIGINT UNSIGNED GENERATED ALWAYS AS (COALESCE(listing_id, 0)) STORED AFTER listing_id;

ALTER TABLE reviews
    ADD UNIQUE KEY IF NOT EXISTS uniq_review_target (reviewer_id, business_id, listing_type, listing_id_key);
