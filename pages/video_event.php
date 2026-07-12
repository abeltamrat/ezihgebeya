<?php
/** Video engagement event recorder (§6.6). POST: video_id, event, watched (seconds). */
header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('{"ok":false}'); }
// CSRF: accept the meta-tag token via header (sendBeacon can't set headers → allow token in body too)
$token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['_token'] ?? '';
if (!hash_equals(csrf_token(), $token)) { http_response_code(419); exit('{"ok":false}'); }

$vid = (int)($_POST['video_id'] ?? 0);
$event = $_POST['event'] ?? '';
$allowed = ['view', 'watch_3s', 'watch_10s', 'watch_25_percent', 'watch_50_percent', 'watch_75_percent', 'watch_complete', 'cta_click', 'profile_click', 'share', 'save', 'report'];
if (!$vid || !in_array($event, $allowed, true) || !val("SELECT COUNT(*) FROM video_posts WHERE id = ? AND status = 'approved'", [$vid])) {
    exit('{"ok":false}');
}

// de-dupe: one event type per video per session
$key = "ve_{$vid}_{$event}";
if (!empty($_SESSION[$key])) exit('{"ok":true,"dup":true}');
$_SESSION[$key] = 1;

q("INSERT INTO video_events (video_post_id, user_id, session_id, event_type, watched_seconds, ip_address, user_agent)
   VALUES (?,?,?,?,?,?,?)",
  [$vid, auth()['id'] ?? null, session_id(), $event, max(0, (int)($_POST['watched'] ?? 0)),
   $_SERVER['REMOTE_ADDR'] ?? null, mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)]);
$v = row("SELECT business_id, linked_type, linked_id FROM video_posts WHERE id = ?", [$vid]);
event_record($event === 'cta_click' ? 'video_cta_click' : 'video_view', [
    'listing_type' => 'video',
    'listing_id' => $vid,
    'business_id' => $v['business_id'] ?? null,
    'source' => 'video_feed',
    'metadata' => [
        'video_event' => $event,
        'watched_seconds' => max(0, (int)($_POST['watched'] ?? 0)),
        'linked_type' => $v['linked_type'] ?? null,
        'linked_id' => $v['linked_id'] ?? null,
    ],
]);
if ($event === 'view') q("UPDATE video_posts SET views_count = views_count + 1 WHERE id = ?", [$vid]);
echo '{"ok":true}';
