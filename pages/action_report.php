<?php
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('');
csrf_check();
$u = auth();

$type = $_POST['reported_type'] ?? '';
$rid = (int)($_POST['reported_id'] ?? 0);
$reason = trim($_POST['reason'] ?? '');
$desc = trim($_POST['description'] ?? '');

[, $errors] = create_report(isset($u['id']) ? (int)$u['id'] : null, $type, $rid, $reason, $desc);
if (!$errors) {
    flash('Report received. Our team will review it. Thank you for keeping the marketplace safe.');
}
$ref = $_SERVER['HTTP_REFERER'] ?? '';
redirect($ref ? ltrim(substr(parse_url($ref, PHP_URL_PATH), strlen(BASE_URL)), '/') : '');
