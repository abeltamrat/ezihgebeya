-- Promotion scheduling controls and moderation-safe activation.

ALTER TABLE promotions MODIFY status ENUM('pending','scheduled','active','paused','completed','rejected','cancelled') DEFAULT 'pending';
