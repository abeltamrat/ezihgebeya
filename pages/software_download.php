<?php

$u = require_login();
if (!is_vendor($u) && (($u['account_type'] ?? '') !== 'super_admin')) {
    require __DIR__ . '/404.php';
    return;
}
if (!software_library_ready()) {
    require __DIR__ . '/404.php';
    return;
}

$item = row("SELECT * FROM software_items WHERE id = ? AND status = 'published'", [$id]);
if (!$item) {
    require __DIR__ . '/404.php';
    return;
}

$deliveryType = $item['file_path'] ? 'file' : 'external';
q("UPDATE software_items SET download_count = download_count + 1 WHERE id = ?", [$id]);
if (db_table_exists('software_downloads')) {
    q(
        "INSERT INTO software_downloads (software_id, user_id, delivery_type) VALUES (?,?,?)",
        [$id, $u['id'], $deliveryType]
    );
}

if ($deliveryType === 'external') {
    $target = software_validate_external_url((string)$item['external_url']);
    if (!$target) {
        flash('This download link is temporarily unavailable.', 'error');
        redirect('app/vendor/software');
    }
    header('Location: ' . $target, true, 302);
    exit;
}

$base = realpath(PROTECTED_UPLOAD_DIR);
$real = realpath(PROTECTED_UPLOAD_DIR . '/' . ltrim((string)$item['file_path'], '/'));
if (!$base || !$real || !str_starts_with($real, $base . DIRECTORY_SEPARATOR) || !is_file($real)) {
    require __DIR__ . '/404.php';
    return;
}

$filename = trim((string)$item['original_filename']) ?: basename($real);
$filename = preg_replace('/[^A-Za-z0-9._ -]+/', '_', $filename);
header('Content-Type: application/octet-stream');
header('Content-Length: ' . filesize($real));
header("Content-Disposition: attachment; filename=\"" . addcslashes($filename, "\\\"") . "\"; filename*=UTF-8''" . rawurlencode($filename));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($real);
exit;
