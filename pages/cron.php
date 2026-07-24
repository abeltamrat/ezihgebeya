<?php
/** Cron endpoint: call from cPanel cron or Task Scheduler with X-Cron-Secret.
 * Expects $job (route param, currently informational — the body below always runs the same
 * daily routine). Every run is recorded in cron_runs so the admin can see whether cron is
 * still firing at all — the most dangerous failure mode, since nothing else would notice. */
header('Content-Type: text/plain; charset=utf-8');
$providedSecret = $_SERVER['HTTP_X_CRON_SECRET'] ?? '';
if (CRON_SECRET === '' || !hash_equals(CRON_SECRET, $providedSecret)) { http_response_code(403); exit("Forbidden\n"); }

$jobName = $job ?? 'daily';
$runId = null;
try {
    q("INSERT INTO cron_runs (job, status) VALUES (?, 'running')", [$jobName]);
    $runId = (int)db()->lastInsertId();
} catch (Throwable $e) {
    // cron_runs missing (migration not yet applied) must never block the actual cron work
}

$log = [];
try {
    if ($jobName === 'model-conversions') model_conversion_process_pending($log);
    else cron_run_daily_job($log);
    $summary = implode(' | ', $log);
    if ($runId) q("UPDATE cron_runs SET status = 'ok', summary = ?, finished_at = NOW() WHERE id = ?", [mb_substr($summary, 0, 60000), $runId]);
    echo "[" . date('c') . "] cron/$jobName OK\n" . implode("\n", $log) . "\n";
} catch (Throwable $e) {
    if ($runId) q("UPDATE cron_runs SET status = 'failed', summary = ?, finished_at = NOW() WHERE id = ?", [mb_substr($e->getMessage(), 0, 60000), $runId]);
    outbox_log('cron-error', $jobName, $e->getMessage());
    http_response_code(500);
    echo "[" . date('c') . "] cron/$jobName FAILED: " . $e->getMessage() . "\n" . implode("\n", $log) . "\n";
}
exit;

function cron_run_daily_job(array &$log): void {

// Daily remains a fallback dispatcher in case the dedicated five-minute conversion
// cron has not been configured yet.
model_conversion_process_pending($log);

// 1. expire promotions past end date → unset visibility flags
$expired = rows("SELECT * FROM promotions WHERE status = 'active' AND ends_at IS NOT NULL AND ends_at < NOW()");
foreach ($expired as $p) {
    promotion_apply($p, false);
    q("UPDATE promotions SET status = 'completed', spent = budget WHERE id = ?", [$p['id']]);
}
$log[] = 'promotions expired: ' . count($expired);

// 1a. activate scheduled promotions whose target is still approved/live.
$duePromos = rows("SELECT * FROM promotions WHERE status = 'scheduled' AND starts_at IS NOT NULL AND starts_at <= NOW()");
$promoActivated = 0; $promoBlocked = 0;
foreach ($duePromos as $p) {
    if (promotion_activate($p, $p['starts_at'])) $promoActivated++;
    else $promoBlocked++;
}
$log[] = "scheduled promotions activated: $promoActivated" . ($promoBlocked ? " (blocked by moderation: $promoBlocked)" : '');

// 1b. complete ad campaigns past end date or over budget
$n = q("UPDATE ads SET status = 'completed' WHERE status = 'active'
        AND ((ends_at IS NOT NULL AND ends_at < NOW()) OR (budget > 0 AND spent >= budget))")->rowCount();
$log[] = "ad campaigns completed: $n";

// 2. expire subscriptions past end date
$expiredSubs = rows("SELECT * FROM subscriptions WHERE status = 'active' AND ends_at IS NOT NULL AND ends_at < NOW()");
foreach ($expiredSubs as $s) {
    q("UPDATE subscriptions SET status = 'expired' WHERE id = ?", [$s['id']]);
    // Undo the premium_verified badge activation granted, unless the business still has premium
    // backing (another active plan or an independently-approved premium verification). Shared with
    // activate_subscription()'s downgrade path so both routes revert identically.
    if (($s['plan'] ?? '') === 'premium' && ($s['type'] ?? 'listing_plan') === 'listing_plan') {
        revert_premium_badge_if_unbacked((int)$s['business_id']);
    }
}
$log[] = 'subscriptions expired: ' . count($expiredSubs);

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
if (db_table_exists('remembered_login_tokens')) {
    q("DELETE FROM remembered_login_tokens WHERE expires_at <= NOW()");
}
$log[] = 'auth/notification tables pruned';

// 2d. event summaries + raw event retention. Rebuild a short rolling window so late
// events still land in summaries, while old raw rows do not grow forever.
if (db_table_exists('events') && db_table_exists('event_daily_summaries')) {
    $summaryDays = max(1, (int)sys('retention.event_summary_rebuild_days', 14));
    q("DELETE FROM event_daily_summaries WHERE event_date >= CURDATE() - INTERVAL $summaryDays DAY");
    q("INSERT INTO event_daily_summaries (event_date, event_type, listing_type, listing_id, business_id, category_id, source, city, event_count)
       SELECT DATE(created_at), event_type, COALESCE(listing_type, 'none'), COALESCE(listing_id, 0),
              COALESCE(business_id, 0), COALESCE(category_id, 0), source, COALESCE(city, ''), COUNT(*)
       FROM events
       WHERE created_at >= CURDATE() - INTERVAL $summaryDays DAY
       GROUP BY DATE(created_at), event_type, COALESCE(listing_type, 'none'), COALESCE(listing_id, 0),
                COALESCE(business_id, 0), COALESCE(category_id, 0), source, COALESCE(city, '')");
    $eventRetentionDays = max(30, (int)sys('retention.raw_events_days', 180));
    $deletedEvents = q("DELETE FROM events WHERE created_at < NOW() - INTERVAL $eventRetentionDays DAY")->rowCount();
    $log[] = "event summaries rebuilt ({$summaryDays}d); raw events pruned older than {$eventRetentionDays}d: $deletedEvents";
}

// 2e. saved-search alerts: daily digest-style notifications for new listings that
// match user-saved browse filters. Cron-driven, so it works on shared hosting.
saved_searches_run_alerts($log);

// 2f. weekly vendor digest: email/in-app highlights, drop-offs, and a nudge back
// into analytics. Monday-only keeps this shared-hosting cron cheap and predictable.
vendor_weekly_digest_run($log);

// 2d. retention: purge stale verification-document and payment-proof files (sensitive
// PII/financial evidence) once they're no longer needed, keeping the decision/record row so
// audit history and moderation reasoning survive. Windows are admin-configurable in Settings.
$vDocDays = (int)sys('retention.verification_docs_rejected_days', 90);
$purgedDocs = 0;
foreach (rows("SELECT vd.id, vd.file_url FROM verification_documents vd
    JOIN verification_requests vr ON vr.id = vd.request_id
    WHERE vr.status = 'rejected' AND vd.file_url IS NOT NULL
      AND vr.updated_at < NOW() - INTERVAL $vDocDays DAY") as $d) {
    purge_upload_file($d['file_url']);
    q("UPDATE verification_documents SET file_url = NULL WHERE id = ?", [$d['id']]);
    $purgedDocs++;
}
$log[] = "verification documents purged (rejected, older than {$vDocDays}d): $purgedDocs";

$payDays = (int)sys('retention.payment_proofs_days', 180);
$purgedProofs = 0;
foreach (rows("SELECT id, proof_image FROM payments
    WHERE status IN ('confirmed','rejected') AND proof_image IS NOT NULL
      AND updated_at < NOW() - INTERVAL $payDays DAY") as $p) {
    purge_upload_file($p['proof_image']);
    q("UPDATE payments SET proof_image = NULL WHERE id = ?", [$p['id']]);
    $purgedProofs++;
}
$log[] = "payment proofs purged (reconciled, older than {$payDays}d): $purgedProofs";

// 3. pause active listings of suspended businesses
foreach (LISTING_TABLES as $t) {
    $n = q("UPDATE `$t` l JOIN businesses b ON b.id = l.business_id SET l.status = 'paused'
            WHERE l.status = 'active' AND b.status IN ('suspended','rejected','deleted')")->rowCount();
    if ($n) $log[] = "$t paused (suspended business): $n";
}

// 4. expire active listings past their lifecycle window
foreach (LISTING_TABLES as $type => $t) {
    if (!db_column_exists($t, 'expires_at')) continue;
    $titleCol = listing_title_col($type);
    $expiring = rows("SELECT l.id, l.business_id, l.`$titleCol` title FROM `$t` l
        WHERE l.status = 'active' AND l.expires_at IS NOT NULL AND l.expires_at < NOW() LIMIT 200");
    foreach ($expiring as $l) {
        q("UPDATE `$t` SET status = 'expired' WHERE id = ? AND status = 'active'", [$l['id']]);
        notify_business((int)$l['business_id'], 'listing_expired',
            '"' . $l['title'] . '" expired — renew it to make it public again',
            'vendor/listings/' . $type, '', true);
    }
    if ($expiring) $log[] = "$t expired: " . count($expiring);
}

// 5. auto-close stale inquiries (new > 60 days)
$n = q("UPDATE inquiries SET status = 'closed' WHERE status = 'new' AND created_at < NOW() - INTERVAL 60 DAY")->rowCount();
$log[] = "stale inquiries closed: $n";

// 5b. precompute the admin Trust panel's suspicious-activity trend (moves these 5 queries
// off every admin_more.php page load) and alert admins when any category's flag count rises
// versus the previous run, rather than admins only finding out on their next page visit.
if (db_table_exists('admin_suspicious_flags')) {
    $flags = [
        'underpriced_flags' => (int)val("SELECT COUNT(*) FROM products p
            JOIN (SELECT category_id, AVG(price) avg_price FROM products WHERE status='active' AND price > 0 GROUP BY category_id HAVING COUNT(*) >= 3) avg_t
              ON avg_t.category_id = p.category_id
            WHERE p.status = 'active' AND p.price > 0 AND p.price < avg_t.avg_price * 0.1"),
        'listing_flood_flags' => (int)val("SELECT COUNT(*) FROM (
            SELECT b.id FROM businesses b JOIN products p ON p.business_id = b.id
            WHERE b.created_at > NOW() - INTERVAL 7 DAY GROUP BY b.id HAVING COUNT(p.id) > 10
        ) t"),
        'report_cluster_flags' => (int)val("SELECT COUNT(*) FROM (
            SELECT reported_type, reported_id FROM reports WHERE status IN ('open','reviewing')
            GROUP BY reported_type, reported_id HAVING COUNT(*) >= 3
        ) t"),
        'duplicate_title_flags' => (int)val("SELECT COUNT(*) FROM (
            SELECT title FROM products WHERE status='active' GROUP BY title HAVING COUNT(*) >= 4
        ) t"),
        'ad_click_fraud_flags' => (int)val("SELECT COUNT(*) FROM (
            SELECT ad_id, ip FROM ad_events WHERE event_type='click' AND created_at > NOW() - INTERVAL 1 DAY
            GROUP BY ad_id, ip HAVING COUNT(*) >= 5
        ) t"),
    ];
    $previous = row("SELECT * FROM admin_suspicious_flags ORDER BY id DESC LIMIT 1");
    $labels = ['underpriced_flags' => 'under-priced listings', 'listing_flood_flags' => 'new-seller listing floods',
        'report_cluster_flags' => 'report clusters', 'duplicate_title_flags' => 'duplicate-title listings', 'ad_click_fraud_flags' => 'ad-click fraud clusters'];
    $risen = [];
    foreach ($flags as $key => $count) {
        if ($previous && $count > (int)$previous[$key]) $risen[] = $labels[$key] . ' (' . (int)$previous[$key] . ' → ' . $count . ')';
    }
    q("INSERT INTO admin_suspicious_flags (underpriced_flags, listing_flood_flags, report_cluster_flags, duplicate_title_flags, ad_click_fraud_flags)
       VALUES (?,?,?,?,?)", array_values($flags));
    if ($risen) notify_admins('suspicious_activity_flag', 'New suspicious-activity trend: ' . implode(', ', $risen), 'admin/analytics');
    $log[] = 'suspicious-activity flags: ' . array_sum($flags) . ($risen ? ' (' . count($risen) . ' category(ies) rose, admins notified)' : '');
}

// 6. daily summary
$log[] = 'summary: users=' . val("SELECT COUNT(*) FROM users")
    . ' businesses=' . val("SELECT COUNT(*) FROM businesses WHERE status='active'")
    . ' active_listings=' . val("SELECT (SELECT COUNT(*) FROM products WHERE status='active')+(SELECT COUNT(*) FROM services WHERE status='active')+(SELECT COUNT(*) FROM supplies WHERE status='active')")
    . ' inquiries_24h=' . val("SELECT COUNT(*) FROM inquiries WHERE created_at > NOW() - INTERVAL 1 DAY")
    . ' orders_24h=' . val("SELECT COUNT(*) FROM orders WHERE created_at > NOW() - INTERVAL 1 DAY");
}

function vendor_weekly_digest_run(array &$log): void {
    // MySQL DAYOFWEEK(): Sunday=1, Monday=2. If a host runs cron more than daily,
    // the notification guard below prevents normal duplicate in-app sends.
    if ((int)val("SELECT DAYOFWEEK(CURDATE())") !== 2) {
        $log[] = 'vendor weekly digests skipped: not Monday';
        return;
    }
    if (!db_table_exists('businesses') || !db_table_exists('users') || !db_table_exists('notifications')) {
        $log[] = 'vendor weekly digests skipped: required tables missing';
        return;
    }

    $vendors = rows("SELECT b.id, b.user_id, b.business_name, u.email
        FROM businesses b
        JOIN users u ON u.id = b.user_id
        WHERE b.status = 'active'
        ORDER BY b.id
        LIMIT 500");

    $sent = 0;
    foreach ($vendors as $b) {
        $userId = (int)$b['user_id'];
        $businessId = (int)$b['id'];
        $already = (int)val("SELECT COUNT(*) FROM notifications
            WHERE user_id = ? AND type = 'vendor_digest' AND created_at >= CURDATE() - INTERVAL 6 DAY", [$userId]);
        if ($already) continue;

        $stats = vendor_weekly_digest_stats($businessId);
        $hasActivity = ($stats['views'] + $stats['favorites'] + $stats['inquiries'] + $stats['orders'] + $stats['completed_orders']) > 0;
        if (!$hasActivity && (int)$stats['active_listings'] === 0) continue;

        $dropOff = vendor_weekly_digest_dropoff($stats);
        $subject = site_name() . ' weekly vendor digest';
        $body = "Hi " . $b['business_name'] . ",\n\n"
            . "Here is your last 7 days on " . site_name() . ":\n"
            . "- Views: " . number_format((int)$stats['views']) . "\n"
            . "- Favorites: " . number_format((int)$stats['favorites']) . "\n"
            . "- Inquiries: " . number_format((int)$stats['inquiries']) . "\n"
            . "- Orders: " . number_format((int)$stats['orders']) . " (" . number_format((int)$stats['completed_orders']) . " completed)\n"
            . "- Sales value: " . money((float)$stats['revenue']) . "\n"
            . "- Promotion spend: " . money((float)$stats['promotion_spend']) . "\n\n"
            . "Focus for this week: " . $dropOff . "\n";
        if ($stats['top_listing_title']) {
            $body .= "Top listing: " . $stats['top_listing_title'] . " (" . number_format((int)$stats['top_listing_events']) . " interactions).\n";
        }
        $body .= "\nOpen your dashboard for listing-level details: " . url('vendor/analytics') . "\n";

        $emailed = send_marketing_email_to_user($userId, $subject, $body);
        notify($userId, 'vendor_digest',
            'Your weekly vendor digest is ready',
            'vendor/analytics',
            $dropOff,
            false);
        if ($emailed || user_marketing_opted_in($userId, 'push')) $sent++;
    }

    $log[] = "vendor weekly digests sent: $sent";
}

function vendor_weekly_digest_stats(int $businessId): array {
    $stats = [
        'views' => 0,
        'favorites' => 0,
        'inquiries' => 0,
        'orders' => 0,
        'completed_orders' => 0,
        'revenue' => 0.0,
        'promotion_spend' => 0.0,
        'active_listings' => 0,
        'top_listing_title' => '',
        'top_listing_events' => 0,
    ];

    if (db_table_exists('event_daily_summaries')) {
        foreach (rows("SELECT event_type, COALESCE(SUM(event_count),0) n
            FROM event_daily_summaries
            WHERE business_id = ? AND event_date >= CURDATE() - INTERVAL 7 DAY
              AND event_type IN ('view','favorite','inquiry','order')
            GROUP BY event_type", [$businessId]) as $r) {
            $eventKeys = ['view' => 'views', 'favorite' => 'favorites', 'inquiry' => 'inquiries', 'order' => 'orders'];
            $key = $eventKeys[$r['event_type']] ?? '';
            if (isset($stats[$key])) $stats[$key] = (int)$r['n'];
        }

        $top = row("SELECT listing_type, listing_id, SUM(event_count) interactions
            FROM event_daily_summaries
            WHERE business_id = ? AND event_date >= CURDATE() - INTERVAL 7 DAY
              AND listing_id > 0 AND listing_type IN ('product','service','supply')
              AND event_type IN ('view','favorite','inquiry','order')
            GROUP BY listing_type, listing_id
            ORDER BY interactions DESC
            LIMIT 1", [$businessId]);
        if ($top) {
            $stats['top_listing_title'] = vendor_weekly_digest_listing_title($top['listing_type'], (int)$top['listing_id']);
            $stats['top_listing_events'] = (int)$top['interactions'];
        }
    }

    $orders = row("SELECT COUNT(*) orders_count,
            SUM(status IN ('delivered','completed')) completed_count,
            COALESCE(SUM(CASE WHEN status IN ('delivered','completed') THEN total ELSE 0 END),0) revenue
        FROM orders
        WHERE business_id = ? AND created_at >= NOW() - INTERVAL 7 DAY
          AND status NOT IN ('cancelled','refunded','disputed')", [$businessId])
        ?: ['orders_count' => 0, 'completed_count' => 0, 'revenue' => 0];
    $stats['orders'] = max((int)$stats['orders'], (int)$orders['orders_count']);
    $stats['completed_orders'] = (int)$orders['completed_count'];
    $stats['revenue'] = (float)$orders['revenue'];

    if (db_table_exists('payments')) {
        $stats['promotion_spend'] = (float)val("SELECT COALESCE(SUM(amount),0)
            FROM payments
            WHERE business_id = ?
              AND (promotion_id IS NOT NULL OR payment_type IN ('featured_listing_payment','ad_payment'))
              AND status = 'confirmed'
              AND created_at >= NOW() - INTERVAL 7 DAY", [$businessId]);
    }

    $stats['active_listings'] = (int)val("SELECT
        (SELECT COUNT(*) FROM products WHERE business_id = ? AND status = 'active') +
        (SELECT COUNT(*) FROM services WHERE business_id = ? AND status = 'active') +
        (SELECT COUNT(*) FROM supplies WHERE business_id = ? AND status = 'active')",
        [$businessId, $businessId, $businessId]);

    return $stats;
}

function vendor_weekly_digest_listing_title(string $type, int $id): string {
    $map = [
        'product' => ['products', 'title'],
        'service' => ['services', 'title'],
        'supply' => ['supplies', 'name'],
    ];
    if (!isset($map[$type])) return '';
    [$table, $col] = $map[$type];
    return (string)val("SELECT `$col` FROM `$table` WHERE id = ?", [$id]);
}

function vendor_weekly_digest_dropoff(array $stats): string {
    if ((int)$stats['active_listings'] === 0) return 'Post at least one active listing so buyers can discover you.';
    if ((int)$stats['views'] === 0) return 'Improve listing titles, photos, and categories to get more search visibility.';
    if ((int)$stats['inquiries'] === 0) return 'Your listings are getting views but no inquiries yet — try clearer prices, stronger photos, and a faster contact CTA.';
    if ((int)$stats['orders'] === 0) return 'You are getting inquiries; follow up quickly and turn promising chats into orders.';
    if ((int)$stats['completed_orders'] === 0) return 'Orders started but none completed this week — check payment confirmation and delivery follow-up.';
    return 'Keep momentum by renewing strong listings and replying quickly to new inquiries.';
}
