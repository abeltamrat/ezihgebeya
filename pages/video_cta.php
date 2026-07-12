<?php
/** CTA click tracker → redirects to the linked listing. Expects $id */
$v = row("SELECT * FROM video_posts WHERE id = ? AND status = 'approved'", [$id]);
if (!$v) redirect('videos');
q("UPDATE video_posts SET cta_clicks_count = cta_clicks_count + 1 WHERE id = ?", [$id]);
q("INSERT INTO video_events (video_post_id, user_id, session_id, event_type, ip_address, user_agent) VALUES (?,?,?, 'cta_click', ?, ?)",
  [$id, auth()['id'] ?? null, session_id(), $_SERVER['REMOTE_ADDR'] ?? null, mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)]);
event_record('video_cta_click', [
    'listing_type' => 'video',
    'listing_id' => $id,
    'business_id' => $v['business_id'] ?? null,
    'source' => 'video_feed',
    'metadata' => ['linked_type' => $v['linked_type'], 'linked_id' => $v['linked_id']],
]);

$dest = null;
if ($v['linked_id']) {
    $dest = match ($v['linked_type']) {
        'product' => ($s = val("SELECT slug FROM products WHERE id = ?", [$v['linked_id']])) ? 'products/' . $s : null,
        'service' => ($s = val("SELECT slug FROM services WHERE id = ?", [$v['linked_id']])) ? 'services/' . $s : null,
        'supply'  => ($s = val("SELECT slug FROM supplies WHERE id = ?", [$v['linked_id']])) ? 'supplies/' . $s : null,
        default => null,
    };
}
if (!$dest) $dest = 'businesses/' . val("SELECT slug FROM businesses WHERE id = ?", [$v['business_id']]);
redirect($dest);
