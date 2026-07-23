<?php
/** Ad click tracker → redirects to the campaign's destination. Expects $id. */
$ad = row("SELECT * FROM ads WHERE id = ? AND status IN ('active','completed')", [$id]);
if (!$ad) redirect('');

// Fraud damper: one billable click per session per ad per hour; extra clicks still
// redirect but don't count or spend (§9.4 suspicious click detection).
$key = 'ad_click_' . $ad['id'];
$billable = ($_SESSION[$key] ?? 0) < time() - 3600;
if ($billable && $ad['status'] === 'active') {
    $_SESSION[$key] = time();
    $spend = $ad['pricing_type'] === 'cpc' ? (float)$ad['unit_price'] : 0;
    $tracked = q("UPDATE ads SET clicks_count = clicks_count + 1,
            spent = CASE WHEN budget > 0 THEN LEAST(budget, spent + ?) ELSE spent + ? END,
            status = CASE WHEN budget > 0 AND spent + ? >= budget THEN 'completed' ELSE status END
        WHERE id = ? AND status = 'active' AND (budget <= 0 OR spent < budget)",
        [$spend, $spend, $spend, $ad['id']])->rowCount();
    if ($tracked !== 1) {
        $billable = false;
    }
    if ($billable) {
    q("INSERT INTO ad_events (ad_id, event_type, placement, city, session_id, ip) VALUES (?, 'click', ?, ?, ?, ?)",
      [$ad['id'], $ad['placement'], user_location()['city'], session_id(), $_SERVER['REMOTE_ADDR'] ?? null]);
    event_record('ad_click', [
        'listing_type' => 'ad',
        'listing_id' => (int)$ad['id'],
        'source' => 'ad',
        'city' => user_location()['city'],
        'metadata' => ['placement' => $ad['placement'], 'destination_url' => $ad['destination_url']],
    ]);
    }
}

$dest = trim($ad['destination_url']);
if (preg_match('~^https?://~i', $dest)) { header('Location: ' . $dest); exit; }
$internal = ltrim($dest, '/'); // internal path like /products/some-slug
$internal .= (str_contains($internal, '?') ? '&' : '?') . 'src=ad';
redirect($internal);
