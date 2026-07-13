-- Subscription activation now enforces a single active row per (business, type) and, on
-- renewal, extends from the current expiry instead of NOW() so already-paid time is never
-- discarded (see activate_subscription() in app/helpers.php). A superseding activation retires
-- the prior active row; it is neither time-'expired' nor user-'cancelled', so give it a
-- distinct status to keep admin subscription history truthful.
ALTER TABLE subscriptions
    MODIFY COLUMN status ENUM('pending','active','expired','cancelled','rejected','superseded') DEFAULT 'pending';
