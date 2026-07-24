<?php
/**
 * Signed callback used only by the configured 3D conversion worker.
 * The worker returns a required GLB plus an optional USDZ as multipart files.
 */
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') api_error('Method not allowed.', 405);
if (!db_table_exists('product_model_conversions')) api_error('Conversion queue is not installed.', 503);
if (trim((string)sys('model_conversion.secret', '')) === '') api_error('Conversion callback is disabled.', 503);

$jobId = filter_var($_POST['job_id'] ?? null, FILTER_VALIDATE_INT);
$providedToken = trim((string)($_SERVER['HTTP_X_MODEL_CALLBACK_TOKEN'] ?? ($_POST['callback_token'] ?? '')));
if (!$jobId || $providedToken === '' || !hash_equals(model_conversion_callback_token((int)$jobId), $providedToken)) {
    api_error('Invalid conversion callback signature.', 403);
}

$job = row("SELECT * FROM product_model_conversions WHERE id = ?", [(int)$jobId]);
if (!$job) api_error('Conversion job not found.', 404);

$status = strtolower(trim((string)($_POST['status'] ?? 'completed')));
if ($status === 'failed') {
    if (!in_array($job['status'], ['pending', 'processing'], true)) {
        api_out(['ok' => true, 'ignored' => true]);
    }
    $error = mb_substr(trim((string)($_POST['error'] ?? 'The conversion worker could not convert this file.')), 0, 1000);
    q("UPDATE product_model_conversions SET status='failed', error_message=? WHERE id=?", [$error, $job['id']]);
    model_conversion_notify_failure($job, $error);
    api_out(['ok' => true, 'status' => 'failed']);
}

if ($status !== 'completed') api_error('Callback status must be completed or failed.', 422);
if (!in_array($job['status'], ['pending', 'processing'], true)) {
    api_out(['ok' => true, 'ignored' => true, 'status' => $job['status']]);
}
if (($_FILES['model_glb']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
    api_error('A converted .glb file is required.', 422);
}

try {
    $saved = model_conversion_complete($job, $_FILES['model_glb'], $_FILES['model_usdz'] ?? null);
    api_out(['ok' => true, 'status' => 'completed', 'uploaded' => array_keys(array_filter($saved))]);
} catch (Throwable $e) {
    q("UPDATE product_model_conversions SET status='failed', error_message=? WHERE id=?",
        [mb_substr($e->getMessage(), 0, 1000), $job['id']]);
    model_conversion_notify_failure($job, $e->getMessage());
    api_error($e->getMessage(), 422);
}
