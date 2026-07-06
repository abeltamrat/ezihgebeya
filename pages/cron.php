<?php
/** Cron endpoint (§21.3): /cron/daily?secret=… — call from cPanel cron or Task Scheduler. */
header('Content-Type: text/plain; charset=utf-8');
if (($_GET['secret'] ?? '') !== CRON_SECRET) { http_response_code(403); exit("Forbidden\n"); }

$log = [];

// 1. expire promotions past end date → unset visibility flags
$expired = rows("SELECT * FROM promotions WHERE status = 'active' AND ends_at IS NOT NULL AND ends_at < NOW()");
foreach ($expired as $p) {
    promotion_apply($p, false);
    q("UPDATE promotions SET status = 'completed', spent = budget WHERE id = ?", [$p['id']]);
}
$log[] = 'promotions expired: ' . count($expired);

// 1b. complete ad campaigns past end date or over budget
$n = q("UPDATE ads SET status = 'completed' WHERE status = 'active'
        AND ((ends_at IS NOT NULL AND ends_at < NOW()) OR (budget > 0 AND spent >= budget))")->rowCount();
$log[] = "ad campaigns completed: $n";

// 2. expire subscriptions past end date
$n = q("UPDATE subscriptions SET status = 'expired' WHERE status = 'active' AND ends_at IS NOT NULL AND ends_at < NOW()")->rowCount();
$log[] = "subscriptions expired: $n";

// 2b. warn vendors whose subscription expires within 3 days (§15 subscription_expiring)
$expiring = rows("SELECT s.*, b.user_id, b.business_name FROM subscriptions s JOIN businesses b ON b.id = s.business_id
    WHERE s.status = 'active' AND s.ends_at BETWEEN NOW() AND NOW() + INTERVAL 3 DAY");
$warned = 0;
foreach ($expiring as $s) {
    $already = val("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'subscription_expiring' AND created_at > NOW() - INTERVAL 3 DAY", [$s['user_id']]);
    if (!$already) {
        notify((int)$s['user_id'], 'subscription_expiring',
            'Your ' . $s['plan'] . ' plan expires ' . date('M j', strtotime($s['ends_at'])) . ' — renew to keep your listings live',
            'vendor/subscription', '', true);
        $warned++;
    }
}
$log[] = "subscription expiry warnings sent: $warned";

// 2c. hygiene: drop stale OTP codes, old login attempts and old notifications
q("DELETE FROM otp_codes WHERE created_at < NOW() - INTERVAL 2 DAY");
q("DELETE FROM login_attempts WHERE created_at < NOW() - INTERVAL 7 DAY");
q("DELETE FROM notifications WHERE read_at IS NOT NULL AND created_at < NOW() - INTERVAL 90 DAY");
$log[] = 'auth/notification tables pruned';

// 3. pause active listings of suspended businesses
foreach (LISTING_TABLES as $t) {
    $n = q("UPDATE `$t` l JOIN businesses b ON b.id = l.business_id SET l.status = 'paused'
            WHERE l.status = 'active' AND b.status IN ('suspended','rejected','deleted')")->rowCount();
    if ($n) $log[] = "$t paused (suspended business): $n";
}

// 4. auto-close stale inquiries (new > 60 days)
$n = q("UPDATE inquiries SET status = 'closed' WHERE status = 'new' AND created_at < NOW() - INTERVAL 60 DAY")->rowCount();
$log[] = "stale inquiries closed: $n";

// 5. daily summary
$log[] = 'summary: users=' . val("SELECT COUNT(*) FROM users")
    . ' businesses=' . val("SELECT COUNT(*) FROM businesses WHERE status='active'")
    . ' active_listings=' . val("SELECT (SELECT COUNT(*) FROM products WHERE status='active')+(SELECT COUNT(*) FROM services WHERE status='active')+(SELECT COUNT(*) FROM supplies WHERE status='active')")
    . ' inquiries_24h=' . val("SELECT COUNT(*) FROM inquiries WHERE created_at > NOW() - INTERVAL 1 DAY")
    . ' orders_24h=' . val("SELECT COUNT(*) FROM orders WHERE created_at > NOW() - INTERVAL 1 DAY");

echo "[" . date('c') . "] cron/daily OK\n" . implode("\n", $log) . "\n";
