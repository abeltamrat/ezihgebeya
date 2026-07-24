<?php

const SOFTWARE_UPLOAD_MAX_MB = 250;
const SOFTWARE_SCREENSHOT_LIMIT = 8;

function software_library_ready(): bool {
    return db_table_exists('software_items') && db_table_exists('software_screenshots');
}

function software_allowed_extensions(): array {
    return [
        'zip', '7z', 'rar', 'tar', 'gz',
        'exe', 'msi', 'apk', 'dmg', 'pkg', 'deb', 'rpm',
        'jar', 'whl', 'vsix', 'rbz',
    ];
}

function software_youtube_id(?string $url): ?string {
    $url = trim((string)$url);
    if ($url === '') return null;
    if (!filter_var($url, FILTER_VALIDATE_URL)) return null;
    $parts = parse_url($url);
    $host = strtolower((string)($parts['host'] ?? ''));
    $host = preg_replace('/^www\./', '', $host);
    $id = '';
    if ($host === 'youtu.be') {
        $id = trim((string)($parts['path'] ?? ''), '/');
    } elseif (in_array($host, ['youtube.com', 'm.youtube.com', 'music.youtube.com', 'youtube-nocookie.com'], true)) {
        parse_str((string)($parts['query'] ?? ''), $query);
        $id = (string)($query['v'] ?? '');
        if ($id === '' && preg_match('~^/(?:embed|shorts|live)/([^/?]+)~', (string)($parts['path'] ?? ''), $match)) {
            $id = $match[1];
        }
    }
    return preg_match('/^[A-Za-z0-9_-]{11}$/', $id) ? $id : null;
}

function software_public_item(array $item, bool $includeDescription = true): array {
    $screenshots = rows(
        "SELECT id, image_path, caption, sort_order FROM software_screenshots
         WHERE software_id = ? ORDER BY sort_order, id",
        [$item['id']]
    );
    return [
        'id' => (int)$item['id'],
        'title' => $item['title'],
        'slug' => $item['slug'],
        'item_type' => $item['item_type'],
        'short_description' => $item['short_description'],
        'description' => $includeDescription ? $item['description'] : null,
        'version' => $item['version'],
        'developer' => $item['developer'],
        'category' => $item['category'],
        'platforms' => array_values(array_filter(array_map('trim', explode(',', (string)$item['platforms'])))),
        'license_type' => $item['license_type'],
        'delivery_type' => $item['file_path'] ? 'file' : 'external',
        'file_name' => $item['original_filename'],
        'file_size' => $item['file_size'] !== null ? (int)$item['file_size'] : null,
        'download_url' => url('software/' . (int)$item['id'] . '/download'),
        'youtube_embed_url' => $item['youtube_video_id']
            ? 'https://www.youtube-nocookie.com/embed/' . rawurlencode($item['youtube_video_id'])
            : null,
        'is_featured' => (bool)$item['is_featured'],
        'download_count' => (int)$item['download_count'],
        'published_at' => $item['published_at'],
        'screenshots' => array_map(fn(array $shot): array => [
            'id' => (int)$shot['id'],
            'url' => img_url($shot['image_path']),
            'caption' => $shot['caption'],
        ], $screenshots),
    ];
}

function software_validate_external_url(string $url): ?string {
    $url = trim($url);
    if ($url === '') return null;
    if (!filter_var($url, FILTER_VALIDATE_URL)) return null;
    $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
    return in_array($scheme, ['http', 'https'], true) ? $url : null;
}

function software_upload_package(array $file): ?array {
    $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($error === UPLOAD_ERR_NO_FILE) return null;
    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException(in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
            ? 'The package exceeds the server upload limit.'
            : 'The software package could not be uploaded.');
    }
    if (upload_rate_exceeded('software')) throw new RuntimeException('Too many upload attempts. Try again later.');
    $size = (int)($file['size'] ?? 0);
    if ($size < 1 || $size > SOFTWARE_UPLOAD_MAX_MB * 1024 * 1024) {
        throw new RuntimeException('Software packages must be between 1 byte and ' . SOFTWARE_UPLOAD_MAX_MB . ' MB.');
    }
    $original = trim(basename(str_replace('\\', '/', (string)($file['name'] ?? 'download'))));
    $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    if (!in_array($extension, software_allowed_extensions(), true)) {
        throw new RuntimeException('Unsupported package type. Allowed: ' . implode(', ', software_allowed_extensions()) . '.');
    }
    $directory = PROTECTED_UPLOAD_DIR . '/software';
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Could not create private software storage.');
    }
    $relative = 'software/' . bin2hex(random_bytes(18)) . '.' . $extension;
    if (!move_uploaded_file((string)$file['tmp_name'], PROTECTED_UPLOAD_DIR . '/' . $relative)) {
        throw new RuntimeException('Could not store the software package.');
    }
    return ['path' => $relative, 'name' => $original, 'size' => $size];
}

function software_delete_private_file(?string $relative): void {
    if (!$relative) return;
    $base = realpath(PROTECTED_UPLOAD_DIR);
    $candidate = realpath(PROTECTED_UPLOAD_DIR . '/' . ltrim($relative, '/'));
    if ($base && $candidate && str_starts_with($candidate, $base . DIRECTORY_SEPARATOR) && is_file($candidate)) {
        unlink($candidate);
    }
}

function software_delete_screenshot_file(?string $relative): void {
    if (!$relative) return;
    $base = realpath(UPLOAD_DIR);
    $candidate = realpath(UPLOAD_DIR . '/' . ltrim($relative, '/'));
    if ($base && $candidate && str_starts_with($candidate, $base . DIRECTORY_SEPARATOR) && is_file($candidate)) {
        unlink($candidate);
        $thumb = preg_replace('/(\.[a-z0-9]+)$/i', '.thumb.jpg', $candidate);
        if ($thumb && is_file($thumb)) unlink($thumb);
    }
}

function software_normalize_uploads(array $input): array {
    if (!isset($input['name']) || !is_array($input['name'])) return [];
    $files = [];
    foreach ($input['name'] as $index => $name) {
        $files[] = [
            'name' => $name,
            'type' => $input['type'][$index] ?? '',
            'tmp_name' => $input['tmp_name'][$index] ?? '',
            'error' => $input['error'][$index] ?? UPLOAD_ERR_NO_FILE,
            'size' => $input['size'][$index] ?? 0,
        ];
    }
    return $files;
}

function software_add_screenshots(int $softwareId, array $input): int {
    $existing = (int)val("SELECT COUNT(*) FROM software_screenshots WHERE software_id = ?", [$softwareId]);
    $remaining = max(0, SOFTWARE_SCREENSHOT_LIMIT - $existing);
    $uploaded = 0;
    foreach (array_slice(software_normalize_uploads($input), 0, $remaining) as $file) {
        $path = upload_image($file, 'software-screenshots');
        if (!$path) continue;
        q(
            "INSERT INTO software_screenshots (software_id, image_path, sort_order) VALUES (?,?,?)",
            [$softwareId, $path, $existing + $uploaded]
        );
        $uploaded++;
    }
    return $uploaded;
}
