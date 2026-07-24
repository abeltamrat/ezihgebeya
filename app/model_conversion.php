<?php
/**
 * Product source-model conversion queue.
 *
 * PHP stores the original source privately and cron sends it to an administrator-
 * configured worker. The worker converts it outside the web request, then posts a
 * validated GLB (and optionally USDZ) to the signed callback.
 */

function model_conversion_allowed_formats(): array {
    $supported = ['skp', 'blend', 'fbx', 'obj', 'dae', '3ds', 'stl', 'ply', 'gltf', 'zip'];
    $configured = preg_split('/[\s,;]+/', strtolower((string)sys(
        'model_conversion.formats',
        implode(',', $supported)
    )), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $allowed = array_values(array_intersect($supported, array_unique($configured)));
    return $allowed ?: $supported;
}

function model_conversion_enabled(): bool {
    return feature_enabled('ar')
        && (bool)sys('model_conversion.enabled', 0)
        && trim((string)sys('model_conversion.endpoint_url', '')) !== ''
        && trim((string)sys('model_conversion.secret', '')) !== ''
        && db_table_exists('product_model_conversions');
}

function model_conversion_max_source_mb(): int {
    return max(1, min(500, (int)sys('model_conversion.max_source_mb', 100)));
}

function model_conversion_status_for_product(int $productId): ?array {
    if (!db_table_exists('product_model_conversions')) return null;
    return row("SELECT id, source_name, source_format, source_size, status, attempts,
                       error_message, created_at, updated_at, completed_at
                FROM product_model_conversions
                WHERE product_id = ?
                ORDER BY id DESC LIMIT 1", [$productId]) ?: null;
}

function model_conversion_public_out(?array $job): ?array {
    if (!$job) return null;
    return [
        'id' => (int)$job['id'],
        'source_name' => $job['source_name'],
        'source_format' => $job['source_format'],
        'source_size' => (int)$job['source_size'],
        'status' => $job['status'],
        'attempts' => (int)$job['attempts'],
        'error' => $job['status'] === 'failed' ? $job['error_message'] : null,
        'created_at' => $job['created_at'],
        'updated_at' => $job['updated_at'],
        'completed_at' => $job['completed_at'],
    ];
}

/** @return array{path:?string,format:?string,name:?string,size:int,error:?string} */
function store_model_source_upload(array $file): array {
    $failure = static fn(string $message): array => [
        'path' => null, 'format' => null, 'name' => null, 'size' => 0, 'error' => $message,
    ];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return $failure('Select a source 3D file to convert.');
    }
    if (upload_rate_exceeded('model_source')) {
        return $failure('Too many model upload attempts. Please wait and try again.');
    }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $tooLarge = in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
        return $failure($tooLarge
            ? 'The source model exceeds the server upload limit.'
            : 'The source model upload failed.');
    }

    $original = trim((string)($file['name'] ?? ''));
    $format = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    if (!in_array($format, model_conversion_allowed_formats(), true)) {
        return $failure('Unsupported source format. Allowed: ' . implode(', ', model_conversion_allowed_formats()) . '.');
    }
    $size = max(0, (int)($file['size'] ?? 0));
    $maxMb = model_conversion_max_source_mb();
    if ($size < 1 || $size > $maxMb * 1024 * 1024) {
        return $failure("Source model must be smaller than $maxMb MB.");
    }
    if (!model_source_signature_valid((string)$file['tmp_name'], $format, $size)) {
        return $failure("The uploaded file does not look like a valid .$format model.");
    }

    $dir = PROTECTED_UPLOAD_DIR . '/model-sources';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        return $failure('Could not create private model storage.');
    }
    $path = 'model-sources/' . bin2hex(random_bytes(16)) . '.' . $format;
    if (!move_uploaded_file((string)$file['tmp_name'], PROTECTED_UPLOAD_DIR . '/' . $path)) {
        return $failure('Could not save the source model.');
    }
    return [
        'path' => $path,
        'format' => $format,
        'name' => mb_substr(basename($original), 0, 255),
        'size' => $size,
        'error' => null,
    ];
}

function model_source_signature_valid(string $path, string $format, int $size): bool {
    $head = (string)@file_get_contents($path, false, null, 0, min(65536, max(1, $size)));
    if ($head === '') return false;

    return match ($format) {
        'skp' => str_contains(substr($head, 0, 64), 'SketchUp Model'),
        'blend' => str_starts_with($head, 'BLENDER'),
        'fbx' => str_starts_with($head, 'Kaydara FBX Binary')
            || (bool)preg_match('/^\s*[;#].*FBX|FBXHeaderExtension/i', $head),
        'obj' => (bool)preg_match('/^(?:\s*#.*\R)*\s*(?:v|vt|vn|f|o|g|s|mtllib|usemtl)\s+/mi', $head),
        'dae' => stripos($head, '<COLLADA') !== false,
        '3ds' => substr($head, 0, 2) === "\x4D\x4D",
        'stl' => str_starts_with(ltrim($head), 'solid') || ($size >= 84 && model_binary_stl_size_valid($path, $size)),
        'ply' => str_starts_with($head, "ply\n") || str_starts_with($head, "ply\r\n"),
        'gltf' => model_gltf_json_valid($path),
        'zip' => model_zip_source_valid($path),
        default => false,
    };
}

function model_binary_stl_size_valid(string $path, int $size): bool {
    $fh = @fopen($path, 'rb');
    if (!$fh) return false;
    fseek($fh, 80);
    $raw = fread($fh, 4);
    fclose($fh);
    if (strlen((string)$raw) !== 4) return false;
    $count = unpack('Vcount', $raw)['count'] ?? 0;
    return $count > 0 && 84 + ((int)$count * 50) === $size;
}

function model_gltf_json_valid(string $path): bool {
    $json = json_decode((string)@file_get_contents($path), true);
    return is_array($json)
        && is_array($json['asset'] ?? null)
        && isset($json['asset']['version'])
        && isset($json['scenes']);
}

function model_zip_source_valid(string $path): bool {
    if (!class_exists('ZipArchive')) return false;
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::RDONLY) !== true) return false;
    $hasModel = false;
    $totalUncompressed = 0;
    $modelExtensions = ['skp', 'blend', 'fbx', 'obj', 'dae', '3ds', 'stl', 'ply', 'gltf'];
    if ($zip->numFiles < 1 || $zip->numFiles > 500) {
        $zip->close();
        return false;
    }
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        $name = str_replace('\\', '/', (string)($stat['name'] ?? ''));
        if ($name === '' || str_starts_with($name, '/') || preg_match('~(^|/)\.\.(/|$)~', $name)) {
            $zip->close();
            return false;
        }
        $totalUncompressed += max(0, (int)($stat['size'] ?? 0));
        if ($totalUncompressed > 1024 * 1024 * 1024) {
            $zip->close();
            return false;
        }
        if (in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), $modelExtensions, true)) $hasModel = true;
    }
    $zip->close();
    return $hasModel;
}

function queue_model_conversion(int $productId, int $businessId, array $source): array {
    if (!db_table_exists('product_model_conversions')) {
        throw new RuntimeException('Run database upgrade30.sql before uploading source 3D models.');
    }

    // One current conversion per product. Keep history, but make older queued jobs
    // terminal so a slow callback cannot overwrite a newer vendor choice.
    $oldJobs = rows("SELECT id, source_path FROM product_model_conversions
                     WHERE product_id = ? AND status IN ('pending','processing')", [$productId]);
    $pdo = db();
    try {
        $pdo->beginTransaction();
        q("UPDATE product_model_conversions SET status = 'cancelled',
              error_message = 'Replaced by a newer source upload.'
           WHERE product_id = ? AND status IN ('pending','processing')", [$productId]);
        q("INSERT INTO product_model_conversions
           (product_id, business_id, source_path, source_name, source_format, source_size, status)
           VALUES (?,?,?,?,?,?,'pending')", [
            $productId, $businessId, $source['path'], $source['name'], $source['format'], $source['size'],
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    foreach ($oldJobs as $old) purge_upload_file($old['source_path']);
    return model_conversion_status_for_product($productId);
}

function cancel_model_conversions_for_product(int $productId, string $reason): void {
    if (!db_table_exists('product_model_conversions')) return;
    $jobs = rows("SELECT id, source_path FROM product_model_conversions
                  WHERE product_id=? AND status IN ('pending','processing')", [$productId]);
    if (!$jobs) return;
    q("UPDATE product_model_conversions
       SET status='cancelled', error_message=?
       WHERE product_id=? AND status IN ('pending','processing')", [mb_substr($reason, 0, 1000), $productId]);
    foreach ($jobs as $job) purge_upload_file($job['source_path']);
}

function model_conversion_callback_token(int $jobId): string {
    return hash_hmac('sha256', 'model-conversion-callback|' . $jobId, (string)sys('model_conversion.secret', ''));
}

function model_conversion_process_pending(array &$log): void {
    if (!db_table_exists('product_model_conversions')) {
        $log[] = '3D conversions skipped: upgrade30.sql not applied';
        return;
    }
    if (!model_conversion_enabled()) {
        $log[] = '3D conversions skipped: converter not configured';
        return;
    }

    $retryLimit = max(1, min(10, (int)sys('model_conversion.retry_limit', 3)));
    $staleJobs = rows("SELECT * FROM product_model_conversions
                       WHERE status='processing' AND dispatched_at < NOW() - INTERVAL 6 HOUR");
    foreach ($staleJobs as $stale) {
        $terminal = (int)$stale['attempts'] >= $retryLimit;
        q("UPDATE product_model_conversions
           SET status=?, error_message='Converter callback timed out.'
           WHERE id=? AND status='processing'", [$terminal ? 'failed' : 'pending', $stale['id']]);
        if ($terminal) model_conversion_notify_failure($stale, 'Converter callback timed out.');
    }

    $jobs = rows("SELECT * FROM product_model_conversions
                  WHERE status = 'pending' AND attempts < ?
                  ORDER BY created_at ASC LIMIT 3", [$retryLimit]);
    $accepted = 0;
    $failed = 0;
    foreach ($jobs as $job) {
        try {
            model_conversion_dispatch($job);
            $accepted++;
        } catch (Throwable $e) {
            $attempts = (int)$job['attempts'] + 1;
            $terminal = $attempts >= $retryLimit;
            q("UPDATE product_model_conversions
               SET status = ?, attempts = ?, error_message = ?, dispatched_at = NOW()
               WHERE id = ?", [
                $terminal ? 'failed' : 'pending',
                $attempts,
                mb_substr($e->getMessage(), 0, 1000),
                $job['id'],
            ]);
            if ($terminal) model_conversion_notify_failure($job, $e->getMessage());
            $failed++;
        }
    }
    foreach (rows("SELECT id, source_path FROM product_model_conversions
                   WHERE source_path <> '' AND status IN ('failed','cancelled','completed')
                     AND updated_at < NOW() - INTERVAL 14 DAY LIMIT 100") as $expired) {
        purge_upload_file($expired['source_path']);
        q("UPDATE product_model_conversions SET source_path='' WHERE id=?", [$expired['id']]);
    }
    $log[] = "3D conversions dispatched: $accepted" . ($failed ? " ($failed failed/retrying)" : '');
}

function model_conversion_dispatch(array $job): void {
    if (!function_exists('curl_init')) throw new RuntimeException('PHP cURL is required to dispatch model conversions.');
    $source = realpath(PROTECTED_UPLOAD_DIR . '/' . $job['source_path']);
    $privateBase = realpath(PROTECTED_UPLOAD_DIR);
    if (!$source || !$privateBase || !str_starts_with($source, $privateBase) || !is_file($source)) {
        throw new RuntimeException('Private source model is missing.');
    }

    $endpoint = trim((string)sys('model_conversion.endpoint_url', ''));
    if (!filter_var($endpoint, FILTER_VALIDATE_URL)) throw new RuntimeException('Converter endpoint URL is invalid.');
    $secret = (string)sys('model_conversion.secret', '');
    $callback = absolute_url(url('model-conversion/callback'));
    $fields = [
        'job_id' => (string)$job['id'],
        'product_id' => (string)$job['product_id'],
        'source_format' => $job['source_format'],
        'callback_url' => $callback,
        'callback_token' => model_conversion_callback_token((int)$job['id']),
        'model' => new CURLFile($source, 'application/octet-stream', $job['source_name']),
    ];

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $fields,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $secret, 'Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 90,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
    ]);
    $body = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($body === false) throw new RuntimeException('Converter connection failed: ' . $error);
    $json = json_decode((string)$body, true);
    if ($status < 200 || $status >= 300 || !is_array($json) || empty($json['accepted'])) {
        $message = is_array($json) ? ($json['error'] ?? $json['message'] ?? '') : '';
        throw new RuntimeException('Converter rejected the job' . ($message ? ': ' . $message : " (HTTP $status)."));
    }
    q("UPDATE product_model_conversions
       SET status='processing', provider_job_id=?, attempts=attempts+1,
           error_message=NULL, dispatched_at=NOW()
       WHERE id=? AND status='pending'", [
        mb_substr((string)($json['job_id'] ?? ''), 0, 190),
        $job['id'],
    ]);
}

function model_conversion_notify_failure(array $job, string $error): void {
    $userId = (int)val("SELECT user_id FROM businesses WHERE id = ?", [$job['business_id']]);
    if ($userId) {
        notify($userId, 'model_conversion_failed',
            'We could not convert "' . $job['source_name'] . '". Upload GLB directly or try another source file.',
            'vendor/listings/product/' . $job['product_id'] . '/edit',
            mb_substr($error, 0, 240));
    }
}

function model_conversion_complete(array $job, array $glbFile, ?array $usdzFile = null): array {
    if (!in_array($job['status'], ['pending', 'processing'], true)) {
        throw new RuntimeException('This conversion job is no longer active.');
    }
    $glb = store_model_upload($glbFile, 'glb', false);
    if (!$glb['path']) throw new RuntimeException((string)$glb['error']);
    $usdzPath = null;
    if ($usdzFile && ($usdzFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $usdz = store_model_upload($usdzFile, 'usdz', false);
        if (!$usdz['path']) {
            purge_upload_file($glb['path']);
            throw new RuntimeException((string)$usdz['error']);
        }
        $usdzPath = $usdz['path'];
    }

    $productId = (int)$job['product_id'];
    $oldModels = rows("SELECT file_url FROM product_media
                       WHERE product_id=? AND media_type IN ('model_3d_glb','model_3d_usdz')", [$productId]);
    $pdo = db();
    try {
        $pdo->beginTransaction();
        q("DELETE FROM product_media WHERE product_id=? AND media_type IN ('model_3d_glb','model_3d_usdz')", [$productId]);
        q("INSERT INTO product_media (product_id, media_type, file_url) VALUES (?,?,?)",
            [$productId, 'model_3d_glb', $glb['path']]);
        if ($usdzPath) {
            q("INSERT INTO product_media (product_id, media_type, file_url) VALUES (?,?,?)",
                [$productId, 'model_3d_usdz', $usdzPath]);
        }
        q("UPDATE product_model_conversions
           SET status='completed', error_message=NULL, completed_at=NOW()
           WHERE id=?", [$job['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        purge_upload_file($glb['path']);
        purge_upload_file($usdzPath);
        throw $e;
    }

    foreach ($oldModels as $old) purge_upload_file($old['file_url']);
    purge_upload_file($job['source_path']);

    $userId = (int)val("SELECT user_id FROM businesses WHERE id = ?", [$job['business_id']]);
    if ($userId) {
        notify($userId, 'model_conversion_completed',
            'Your 3D model "' . $job['source_name'] . '" is converted and attached to the product.',
            'vendor/listings/product/' . $productId . '/edit');
    }
    return ['glb' => $glb['path'], 'usdz' => $usdzPath];
}
