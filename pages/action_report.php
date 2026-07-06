<?php
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('');
csrf_check();
$u = auth();

$type = $_POST['reported_type'] ?? '';
$rid = (int)($_POST['reported_id'] ?? 0);
$reason = trim($_POST['reason'] ?? '');
$desc = trim($_POST['description'] ?? '');

if (in_array($type, ['product', 'service', 'supply', 'business', 'video', 'review', 'user'], true) && $rid && $reason !== '') {
    q("INSERT INTO reports (reporter_id, reported_type, reported_id, reason, description) VALUES (?,?,?,?,?)",
      [$u['id'] ?? null, $type, $rid, $reason, $desc ?: null]);
    if ($type === 'video') q("UPDATE video_posts SET reports_count = reports_count + 1 WHERE id = ?", [$rid]);
    flash('Report received. Our team will review it. Thank you for keeping the marketplace safe.');
}
$ref = $_SERVER['HTTP_REFERER'] ?? '';
redirect($ref ? ltrim(substr(parse_url($ref, PHP_URL_PATH), strlen(BASE_URL)), '/') : '');
