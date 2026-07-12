-- Cron run history (PLAN.md → Marketplace fundamentals → Monitoring for failed cron runs,
-- SMS/email sends, and webhooks). Lets the admin see whether the daily cron is still firing
-- at all — the most dangerous failure mode, since nothing else notices when a cron job
-- silently stops running.
CREATE TABLE IF NOT EXISTS cron_runs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    job VARCHAR(50) NOT NULL,
    status ENUM('running','ok','failed') NOT NULL DEFAULT 'running',
    summary TEXT NULL,
    started_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    finished_at DATETIME NULL,
    KEY idx_cron_runs (job, started_at)
);
