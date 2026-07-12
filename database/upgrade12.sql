-- Marketing preference controls for SMS, email, and push/in-app marketing.
-- Transactional messages (OTP, order/payment/moderation/security notices) remain allowed.

ALTER TABLE users ADD COLUMN marketing_sms_opt_in BOOLEAN NOT NULL DEFAULT TRUE AFTER email_verified_at;
ALTER TABLE users ADD COLUMN marketing_email_opt_in BOOLEAN NOT NULL DEFAULT TRUE AFTER marketing_sms_opt_in;
ALTER TABLE users ADD COLUMN marketing_push_opt_in BOOLEAN NOT NULL DEFAULT TRUE AFTER marketing_email_opt_in;
ALTER TABLE users ADD COLUMN marketing_updated_at DATETIME NULL AFTER marketing_push_opt_in;
