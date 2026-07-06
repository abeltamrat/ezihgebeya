<?php
// ---------- output ----------
function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function url(string $path = ''): string { return BASE_URL . '/' . ltrim($path, '/'); }
function redirect(string $path): never { header('Location: ' . url($path)); exit; }

function money($amount): string {
    if ($amount === null || $amount === '' || (float)$amount == 0.0) return '';
    $cur = function_exists('sys') ? (string)sys('general.currency_label', 'ETB') : 'ETB';
    return number_format((float)$amount) . ' ' . $cur;
}

function time_ago(string $dt): string {
    $diff = time() - strtotime($dt);
    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff / 60) . ' min ago';
    if ($diff < 86400) return floor($diff / 3600) . ' hr ago';
    if ($diff < 2592000) return floor($diff / 86400) . ' days ago';
    return date('M j, Y', strtotime($dt));
}

// ---------- session / auth ----------
function auth(): ?array {
    static $user = false;
    if ($user === false) {
        $user = empty($_SESSION['user_id']) ? null
            : row("SELECT * FROM users WHERE id = ? AND status = 'active'", [$_SESSION['user_id']]);
    }
    return $user;
}

function is_vendor(?array $u): bool { return $u && in_array($u['account_type'], VENDOR_TYPES, true); }
function is_admin(?array $u): bool { return $u && in_array($u['account_type'], ['admin', 'super_admin'], true); }

function require_login(): array {
    $u = auth();
    if (!$u) { flash('Please log in first.', 'error'); redirect('login'); }
    return $u;
}
function require_vendor(): array {
    $u = require_login();
    if (!is_vendor($u)) { flash('Vendor account required.', 'error'); redirect(''); }
    return $u;
}
function require_admin(): array {
    $u = require_login();
    if (!is_admin($u)) { flash('Not authorized.', 'error'); redirect(''); }
    return $u;
}

/** The logged-in vendor's business row (or null). */
function my_business(?array $u = null): ?array {
    $u = $u ?? auth();
    if (!$u) return null;
    return row("SELECT * FROM businesses WHERE user_id = ? AND status != 'deleted'", [$u['id']]);
}

// ---------- flash + csrf ----------
function flash(string $msg, string $type = 'success'): void { $_SESSION['flash'][] = ['msg' => $msg, 'type' => $type]; }
function get_flashes(): array { $f = $_SESSION['flash'] ?? []; unset($_SESSION['flash']); return $f; }

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(20));
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="_token" value="' . csrf_token() . '">'; }
function csrf_check(): void {
    if (!hash_equals(csrf_token(), $_POST['_token'] ?? '')) {
        http_response_code(419);
        exit('Invalid or expired form token. Go back and retry.');
    }
}

// ---------- misc ----------
function upload_limit_mb(): int {
    return (int)system_restrictions_config()['max_image_upload_mb'];
}

function upload_limit_bytes(): int {
    return upload_limit_mb() * 1024 * 1024;
}

function slugify(string $text, string $table, string $col = 'slug'): string {
    $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: $text), '-'));
    if ($slug === '') $slug = 'item';
    $base = $slug; $i = 1;
    while (val("SELECT COUNT(*) FROM `$table` WHERE `$col` = ?", [$slug])) $slug = $base . '-' . (++$i);
    return $slug;
}

function upload_image(array $file, string $subdir): ?string {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $msg = in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
            ? 'Image upload failed because it exceeds the server upload limit.'
            : 'Image upload failed.';
        flash($msg, 'error');
        return null;
    }
    $limitMb = upload_limit_mb();
    if ($file['size'] > upload_limit_bytes()) { flash('Image too large (max ' . $limitMb . ' MB).', 'error'); return null; }
    $info = @getimagesize($file['tmp_name']);
    $ext = match ($info['mime'] ?? '') {
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif', default => null,
    };
    if (!$ext) { flash('Only JPG, PNG, WEBP or GIF images allowed.', 'error'); return null; }
    $dir = UPLOAD_DIR . '/' . $subdir;
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $name = $subdir . '/' . bin2hex(random_bytes(10)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], UPLOAD_DIR . '/' . $name)) { flash('Could not save image.', 'error'); return null; }
    image_optimize(UPLOAD_DIR . '/' . $name, $ext); // §22.3: downscale huge photos + write a thumbnail
    return $name;
}

/** Downscale to IMAGE_MAX_DIMENSION, recompress, and write a .thumb.jpg next to the file. GIFs (animation) are left alone. */
function image_optimize(string $absPath, string $ext): void {
    if ($ext === 'gif' || !function_exists('imagecreatetruecolor')) return;
    try {
        $src = match ($ext) {
            'jpg' => @imagecreatefromjpeg($absPath),
            'png' => @imagecreatefrompng($absPath),
            'webp' => @imagecreatefromwebp($absPath),
            default => false,
        };
        if (!$src) return;
        $w = imagesx($src); $h = imagesy($src);

        // main image: cap the longest side + recompress
        $max = IMAGE_MAX_DIMENSION;
        if ($w > $max || $h > $max) {
            $scale = min($max / $w, $max / $h);
            $nw = (int)round($w * $scale); $nh = (int)round($h * $scale);
            $dst = imagecreatetruecolor($nw, $nh);
            if ($ext === 'png') { imagealphablending($dst, false); imagesavealpha($dst, true); }
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
            imagedestroy($src);
            $src = $dst; $w = $nw; $h = $nh;
        }
        match ($ext) {
            'jpg' => imagejpeg($src, $absPath, 82),
            'png' => imagepng($src, $absPath, 6),
            'webp' => imagewebp($src, $absPath, 82),
        };

        // thumbnail (grid cards / previews)
        $scale = min(THUMB_DIMENSION / $w, THUMB_DIMENSION / $h, 1);
        $tw = max(1, (int)round($w * $scale)); $th = max(1, (int)round($h * $scale));
        $thumb = imagecreatetruecolor($tw, $th);
        imagecopyresampled($thumb, $src, 0, 0, 0, 0, $tw, $th, $w, $h);
        imagejpeg($thumb, $absPath . '.thumb.jpg', 78);
        imagedestroy($thumb);
        imagedestroy($src);
    } catch (Throwable $e) {
        // optimization is best-effort; the original upload already succeeded
    }
}

function img_url(?string $path): ?string { return $path ? UPLOAD_URL . '/' . $path : null; }

/** Thumbnail URL when one exists, else the full image. */
function thumb_url(?string $path): ?string {
    if (!$path) return null;
    return file_exists(UPLOAD_DIR . '/' . $path . '.thumb.jpg') ? UPLOAD_URL . '/' . $path . '.thumb.jpg' : img_url($path);
}

// ---------- video embeds (official embeds only per spec) ----------
function parse_video_url(string $platform, string $urlIn): ?array {
    $urlIn = trim($urlIn);
    if ($platform === 'youtube') {
        if (preg_match('~(?:youtube\.com/(?:watch\?v=|shorts/|embed/)|youtu\.be/)([A-Za-z0-9_-]{6,20})~', $urlIn, $m)) {
            return ['video_id' => $m[1], 'embed_url' => 'https://www.youtube.com/embed/' . $m[1] . '?playsinline=1&rel=0'];
        }
    } elseif ($platform === 'tiktok') {
        $urlToParse = $urlIn;
        if (preg_match('~https?://(?:vt|vm)\.tiktok\.com/[^\\s]+~i', $urlIn)) {
            $urlToParse = resolve_redirect_url($urlIn) ?: $urlIn;
        }
        if (preg_match('~tiktok\.com/@[^/]+/video/(\d+)~', $urlToParse, $m)) {
            return ['video_id' => $m[1], 'embed_url' => 'https://www.tiktok.com/embed/v2/' . $m[1]];
        }
        $oembedId = tiktok_oembed_video_id($urlIn);
        if ($oembedId) {
            return ['video_id' => $oembedId, 'embed_url' => 'https://www.tiktok.com/embed/v2/' . $oembedId];
        }
        if (preg_match('~https?://(?:www\.|m\.|vt\.|vm\.)?tiktok\.com/[^\\s]+~i', $urlIn)) {
            return ['video_id' => 'link-' . substr(sha1($urlIn), 0, 24), 'embed_url' => $urlIn];
        }
    }
    return null;
}

function tiktok_oembed_video_id(string $url): ?string {
    $json = fetch_remote_text('https://www.tiktok.com/oembed?url=' . rawurlencode($url));
    if (!$json) return null;
    $data = json_decode($json, true);
    if (!is_array($data)) return null;
    $haystack = implode(' ', array_map(fn($v) => is_scalar($v) ? (string)$v : '', $data));
    if (preg_match('~(?:/video/|embed/v2/|embed_product_id["\':\s]+)(\d{8,})~', $haystack, $m)) return $m[1];
    return null;
}

function fetch_remote_text(string $url): ?string {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 4,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 EzihGebeya',
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return $status >= 200 && $status < 300 && is_string($body) ? $body : null;
    }
    $body = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 10]]));
    return is_string($body) ? $body : null;
}

function resolve_redirect_url(string $url): ?string {
    if (!filter_var($url, FILTER_VALIDATE_URL)) return null;
    if (function_exists('curl_init')) {
        foreach ([true, false] as $headOnly) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 8,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_NOBODY => $headOnly,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 EzihGebeya',
                CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'],
            ]);
            curl_exec($ch);
            $effective = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            curl_close($ch);
            if ($effective && $effective !== $url) return $effective;
        }
    }
    $headers = @get_headers($url, true);
    if (!is_array($headers) || empty($headers['Location'])) return null;
    $location = is_array($headers['Location']) ? end($headers['Location']) : $headers['Location'];
    return is_string($location) ? $location : null;
}

function video_embed_html(array $v): string {
    $embedUrl = (string)$v['embed_url'];
    if ($v['platform'] === 'tiktok') {
        if (!str_contains($embedUrl, '/embed/v2/')) {
            $src = e($embedUrl);
            return '<blockquote class="tiktok-embed video-frame tiktok-link-embed" cite="' . $src . '" data-src="' . $src . '"><a href="' . $src . '">Watch on TikTok</a></blockquote><script async src="https://www.tiktok.com/embed.js"></script>';
        }
        $src = e(video_embed_autoplay_url($embedUrl, $v['platform'], (string)($v['video_id'] ?? '')));
        return '<iframe class="video-frame tiktok" src="' . $src . '" loading="lazy" scrolling="no" allow="autoplay; encrypted-media; picture-in-picture" allowfullscreen></iframe>';
    }
    $src = e(video_embed_autoplay_url($embedUrl, $v['platform'], (string)($v['video_id'] ?? '')));
    return '<iframe class="video-frame" src="' . $src . '" loading="lazy" scrolling="no" title="' . e($v['title'] ?? 'Video') . '" allow="autoplay; accelerometer; encrypted-media; picture-in-picture" allowfullscreen></iframe>';
}

function video_embed_autoplay_url(string $src, string $platform, string $videoId = ''): string {
    $parts = parse_url($src);
    if (!$parts || empty($parts['scheme']) || empty($parts['host'])) return $src;
    parse_str($parts['query'] ?? '', $query);
    $query['autoplay'] = '1';
    if ($platform === 'youtube') {
        $query['mute'] = '1';
        $query['playsinline'] = '1';
        $query['loop'] = '1';
        $query['controls'] = '0';
        $query['modestbranding'] = '1';
        $query['rel'] = '0';
        if ($videoId !== '') $query['playlist'] = $videoId;
    } elseif ($platform === 'tiktok') {
        $query['muted'] = '1';
        $query['mute'] = '1';
        $query['playsinline'] = '1';
        $query['loop'] = '1';
        $query['controls'] = '0';
        $query['hide_controls'] = '1';
    }
    $path = $parts['path'] ?? '';
    $port = isset($parts['port']) ? ':' . $parts['port'] : '';
    $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';
    return $parts['scheme'] . '://' . $parts['host'] . $port . $path . '?' . http_build_query($query) . $fragment;
}

// ---------- listings ----------
const LISTING_TABLES = ['product' => 'products', 'service' => 'services', 'supply' => 'supplies'];

function listing_title_col(string $type): string { return $type === 'supply' ? 'name' : 'title'; }

function listing_url(string $type, array $item): string {
    return url(['product' => 'products', 'service' => 'services', 'supply' => 'supplies'][$type] . '/' . $item['slug']);
}

/** Primary image URL for a listing row, or null. */
function listing_image(string $type, array $item): ?string {
    if ($type === 'product') {
        $m = row("SELECT file_url FROM product_media WHERE product_id = ? ORDER BY is_primary DESC, sort_order ASC LIMIT 1", [$item['id']]);
        return $m ? img_url($m['file_url']) : null;
    }
    return img_url($item['image'] ?? null);
}

function verified_badge(?string $status): string {
    if (!$status || $status === 'unverified') return '';
    $label = ['phone_verified' => 'Phone Verified', 'document_verified' => 'Verified', 'location_verified' => 'Verified', 'premium_verified' => 'Premium Verified'][$status] ?? 'Verified';
    return '<span class="badge badge-verified" title="' . e($label) . '">✔ ' . e($label) . '</span>';
}

/** Seller response rate (§23.1): share of inquiries the vendor acted on. Null until 3+ inquiries. */
function business_response_rate(int $businessId): ?int {
    $agg = row("SELECT COUNT(*) total, SUM(status NOT IN ('new','seen')) handled FROM inquiries WHERE business_id = ?", [$businessId]);
    if ((int)$agg['total'] < 3) return null;
    return (int)round((int)$agg['handled'] / (int)$agg['total'] * 100);
}

function star_rating($avg, int $count = -1): string {
    $avg = (float)$avg;
    if ($avg <= 0) return '<span class="stars muted">No ratings yet</span>';
    $full = str_repeat('★', (int)round($avg));
    $html = '<span class="stars">' . $full . ' ' . number_format($avg, 1) . '</span>';
    if ($count >= 0) $html .= ' <span class="muted">(' . $count . ')</span>';
    return $html;
}

// ---------- system UI optimizer ----------
function ensure_site_settings_table(): bool {
    static $done = false;
    if ($done) return true;
    try {
        q("CREATE TABLE IF NOT EXISTS site_settings (
            setting_key VARCHAR(100) PRIMARY KEY,
            setting_value MEDIUMTEXT NOT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $done = true;
    } catch (Throwable $e) {
        return false;
    }
    return true;
}

function site_setting_get(string $key, $default = null) {
    if (!ensure_site_settings_table()) return $default;
    try {
        $raw = val("SELECT setting_value FROM site_settings WHERE setting_key = ?", [$key]);
    } catch (Throwable $e) {
        return $default;
    }
    if ($raw === false || $raw === null) return $default;
    $decoded = json_decode((string)$raw, true);
    return json_last_error() === JSON_ERROR_NONE ? $decoded : $raw;
}

function site_setting_set(string $key, $value): void {
    if (!ensure_site_settings_table()) {
        flash('Could not create the settings table. Run database/upgrade4.sql and try again.', 'error');
        return;
    }
    $raw = is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    try {
        q("INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?)
           ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)", [$key, $raw]);
    } catch (Throwable $e) {
        flash('Could not save system UI settings.', 'error');
    }
}

function system_restrictions_config(): array {
    $saved = site_setting_get('system_restrictions', []);
    $saved = is_array($saved) ? $saved : [];
    return [
        'max_image_upload_mb' => max(1, min(100, (int)($saved['max_image_upload_mb'] ?? 30))),
    ];
}

function sanitize_system_restrictions(array $in): array {
    return [
        'max_image_upload_mb' => max(1, min(100, (int)($in['max_image_upload_mb'] ?? 30))),
    ];
}

function system_ui_defaults(): array {
    return [
        'brand' => '#0f766e',
        'brand_dark' => '#115e59',
        'brand_soft' => '#d9f4ef',
        'accent' => '#f97316',
        'accent_soft' => '#fff1e7',
        'ink' => '#101828',
        'text' => '#1f2937',
        'bg' => '#f6f8fb',
        'surface' => '#ffffff',
        'theme_mode' => 'light',
        'font_family' => 'inter',
        'font_scale' => 100,
        'container_width' => 1240,
        'section_spacing' => 34,
        'grid_min_width' => 235,
        'logo_mark' => 'EG',
        'logo_text' => 'EzihGebeya',
        'announcement_enabled' => 0,
        'announcement_text' => 'New sellers can open a shop for free.',
        'announcement_url' => '',
        'announcement_tone' => 'brand',
        'button_radius' => 999,
        'card_radius' => 14,
        'panel_radius' => 14,
        'input_radius' => 10,
        'card_image_ratio' => '4/3',
        'shadow_strength' => 45,
        'border_width' => 1,
        'focus_style' => 'ring',
        'component_density' => 'comfortable',
        'icon_pack' => 'line',
        'nav_style' => 'glass',
        'form_style' => 'soft',
        'ad_style' => 'clean',
        'table_style' => 'soft',
        'badge_style' => 'pill',
        'card_style' => 'standard',
        'header_behavior' => 'sticky',
        'footer_style' => 'dark',
        'mobile_nav_style' => 'pill',
        'search_style' => 'rounded',
        'hover_motion' => 'lift',
        'image_treatment' => 'natural',
        'image_hover' => 'zoom',
        'button_style' => 'solid',
        'section_head_style' => 'plain',
        'filters_behavior' => 'sticky',
        'card_text_align' => 'left',
        'hero_align' => 'left',
        'hero_height' => 'standard',
        'hero_search_enabled' => 1,
        'hero_links_enabled' => 1,
        'hero_stats_enabled' => 1,
        'category_style' => 'rail',
        'category_display_limit' => 8,
        'price_style' => 'standard',
        'empty_state_style' => 'dashed',
        'show_card_category' => 1,
        'show_card_price' => 1,
        'show_card_location' => 1,
        'show_card_vendor' => 1,
        'show_featured_badge' => 1,
        'button_badge_enabled' => 0,
        'button_badge_text' => 'New',
        'button_badge_target' => 'join',
        'button_badge_tone' => 'accent',
        'hero_overlay' => 72,
        'hero_background_mode' => 'overlay_image',
        'hero_gradient_from' => '#111827',
        'hero_gradient_to' => '#0f766e',
        'hero_image_position' => 'center',
        'hero_title' => 'Furniture, finishing and supplies in one trusted marketplace.',
        'hero_subtitle' => 'Discover verified furniture sellers, finishing professionals and material suppliers across Ethiopia by location, price and rating.',
        'hero_image' => '',
        'cta_title' => 'Are you a seller, workshop or service provider?',
        'cta_text' => 'List your products, get discovered by customers near you, and receive direct inquiries. Free to start.',
        'cta_button' => 'Open your shop',
        'home_sections' => ['categories', 'near', 'featured', 'services', 'supplies', 'cta'],
        'hidden_sections' => [],
        'custom_css' => '',
    ];
}

function system_ui_config(): array {
    $saved = site_setting_get('system_ui_optimizer', []);
    return array_replace(system_ui_defaults(), is_array($saved) ? $saved : []);
}

function sanitize_system_ui(array $in): array {
    $defaults = system_ui_defaults();
    $out = $defaults;
    foreach (['brand','brand_dark','brand_soft','accent','accent_soft','ink','text','bg','surface','hero_gradient_from','hero_gradient_to'] as $k) {
        $v = trim((string)($in[$k] ?? $defaults[$k]));
        $out[$k] = preg_match('/^#[0-9a-fA-F]{6}$/', $v) ? $v : $defaults[$k];
    }
    foreach (['button_radius' => [4, 999], 'card_radius' => [6, 28], 'panel_radius' => [6, 28], 'input_radius' => [4, 24], 'shadow_strength' => [0, 80], 'border_width' => [0, 3], 'hero_overlay' => [20, 92], 'font_scale' => [88, 116], 'container_width' => [960, 1480], 'section_spacing' => [18, 70], 'grid_min_width' => [160, 320], 'category_display_limit' => [4, 16]] as $k => [$min, $max]) {
        $out[$k] = max($min, min($max, (int)($in[$k] ?? $defaults[$k])));
    }
    $out['theme_mode'] = in_array($in['theme_mode'] ?? '', ['light', 'soft-dark', 'high-contrast'], true) ? $in['theme_mode'] : 'light';
    $out['font_family'] = in_array($in['font_family'] ?? '', ['inter', 'system', 'serif', 'rounded'], true) ? $in['font_family'] : 'inter';
    $out['logo_mark'] = strtoupper(substr(trim((string)($in['logo_mark'] ?? $defaults['logo_mark'])), 0, 4)) ?: $defaults['logo_mark'];
    $out['logo_text'] = substr(trim((string)($in['logo_text'] ?? $defaults['logo_text'])), 0, 32) ?: $defaults['logo_text'];
    $out['announcement_enabled'] = !empty($in['announcement_enabled']) ? 1 : 0;
    $out['announcement_text'] = substr(trim((string)($in['announcement_text'] ?? $defaults['announcement_text'])), 0, 160);
    $out['announcement_url'] = substr(trim((string)($in['announcement_url'] ?? '')), 0, 240);
    $out['announcement_tone'] = in_array($in['announcement_tone'] ?? '', ['brand', 'accent', 'dark', 'light'], true) ? $in['announcement_tone'] : 'brand';
    $out['component_density'] = in_array($in['component_density'] ?? '', ['compact', 'comfortable', 'spacious'], true) ? $in['component_density'] : 'comfortable';
    $out['icon_pack'] = in_array($in['icon_pack'] ?? '', ['line', 'solid', 'emoji', 'initials'], true) ? $in['icon_pack'] : 'line';
    $out['nav_style'] = in_array($in['nav_style'] ?? '', ['glass', 'solid', 'dark'], true) ? $in['nav_style'] : 'glass';
    $out['form_style'] = in_array($in['form_style'] ?? '', ['soft', 'outlined', 'filled'], true) ? $in['form_style'] : 'soft';
    $out['ad_style'] = in_array($in['ad_style'] ?? '', ['clean', 'boxed', 'premium'], true) ? $in['ad_style'] : 'clean';
    $out['table_style'] = in_array($in['table_style'] ?? '', ['soft', 'striped', 'compact'], true) ? $in['table_style'] : 'soft';
    $out['badge_style'] = in_array($in['badge_style'] ?? '', ['pill', 'square', 'soft'], true) ? $in['badge_style'] : 'pill';
    $out['card_style'] = in_array($in['card_style'] ?? '', ['standard', 'borderless', 'outlined', 'compact'], true) ? $in['card_style'] : 'standard';
    $out['header_behavior'] = in_array($in['header_behavior'] ?? '', ['sticky', 'static', 'floating'], true) ? $in['header_behavior'] : 'sticky';
    $out['footer_style'] = in_array($in['footer_style'] ?? '', ['dark', 'light', 'brand'], true) ? $in['footer_style'] : 'dark';
    $out['mobile_nav_style'] = in_array($in['mobile_nav_style'] ?? '', ['pill', 'minimal', 'boxed'], true) ? $in['mobile_nav_style'] : 'pill';
    $out['search_style'] = in_array($in['search_style'] ?? '', ['rounded', 'box', 'underline'], true) ? $in['search_style'] : 'rounded';
    $out['hover_motion'] = in_array($in['hover_motion'] ?? '', ['lift', 'soft', 'none'], true) ? $in['hover_motion'] : 'lift';
    $out['focus_style'] = in_array($in['focus_style'] ?? '', ['ring', 'underline', 'glow'], true) ? $in['focus_style'] : 'ring';
    $out['image_treatment'] = in_array($in['image_treatment'] ?? '', ['natural', 'warm', 'cool', 'mono'], true) ? $in['image_treatment'] : 'natural';
    $out['image_hover'] = in_array($in['image_hover'] ?? '', ['zoom', 'fade', 'none'], true) ? $in['image_hover'] : 'zoom';
    $out['button_style'] = in_array($in['button_style'] ?? '', ['solid', 'gradient', 'flat'], true) ? $in['button_style'] : 'solid';
    $out['section_head_style'] = in_array($in['section_head_style'] ?? '', ['plain', 'rule', 'boxed'], true) ? $in['section_head_style'] : 'plain';
    $out['filters_behavior'] = in_array($in['filters_behavior'] ?? '', ['sticky', 'static'], true) ? $in['filters_behavior'] : 'sticky';
    $out['card_text_align'] = in_array($in['card_text_align'] ?? '', ['left', 'center'], true) ? $in['card_text_align'] : 'left';
    $out['hero_align'] = in_array($in['hero_align'] ?? '', ['left', 'center', 'split'], true) ? $in['hero_align'] : 'left';
    $out['hero_height'] = in_array($in['hero_height'] ?? '', ['compact', 'standard', 'tall'], true) ? $in['hero_height'] : 'standard';
    $out['category_style'] = in_array($in['category_style'] ?? '', ['icon', 'minimal', 'banner', 'rail'], true) ? $in['category_style'] : 'rail';
    $out['price_style'] = in_array($in['price_style'] ?? '', ['standard', 'brand', 'accent', 'dark'], true) ? $in['price_style'] : 'standard';
    $out['empty_state_style'] = in_array($in['empty_state_style'] ?? '', ['dashed', 'soft', 'plain'], true) ? $in['empty_state_style'] : 'dashed';
    $out['card_image_ratio'] = in_array($in['card_image_ratio'] ?? '', ['1/1', '4/3', '3/2', '16/9'], true) ? $in['card_image_ratio'] : '4/3';
    foreach (['show_card_category','show_card_price','show_card_location','show_card_vendor','show_featured_badge','hero_search_enabled','hero_links_enabled','hero_stats_enabled'] as $k) {
        $out[$k] = !empty($in[$k]) ? 1 : 0;
    }
    $out['button_badge_enabled'] = !empty($in['button_badge_enabled']) ? 1 : 0;
    $out['button_badge_text'] = substr(trim((string)($in['button_badge_text'] ?? $defaults['button_badge_text'])), 0, 18) ?: $defaults['button_badge_text'];
    $out['button_badge_target'] = in_array($in['button_badge_target'] ?? '', ['join', 'account', 'primary', 'all'], true) ? $in['button_badge_target'] : 'join';
    $out['button_badge_tone'] = in_array($in['button_badge_tone'] ?? '', ['accent', 'brand', 'dark', 'danger'], true) ? $in['button_badge_tone'] : 'accent';
    $out['hero_background_mode'] = in_array($in['hero_background_mode'] ?? '', ['gradient', 'image', 'overlay_image'], true) ? $in['hero_background_mode'] : 'overlay_image';
    $out['hero_image_position'] = in_array($in['hero_image_position'] ?? '', ['center', 'top', 'bottom', 'left', 'right'], true) ? $in['hero_image_position'] : 'center';
    $out['hero_title'] = substr(trim((string)($in['hero_title'] ?? $defaults['hero_title'])), 0, 160);
    $out['hero_subtitle'] = substr(trim((string)($in['hero_subtitle'] ?? $defaults['hero_subtitle'])), 0, 260);
    $out['hero_image'] = substr(trim(str_replace(['"', "'", ')', "\n", "\r"], '', (string)($in['hero_image'] ?? ''))), 0, 500);
    $out['cta_title'] = substr(trim((string)($in['cta_title'] ?? $defaults['cta_title'])), 0, 120) ?: $defaults['cta_title'];
    $out['cta_text'] = substr(trim((string)($in['cta_text'] ?? $defaults['cta_text'])), 0, 240) ?: $defaults['cta_text'];
    $out['cta_button'] = substr(trim((string)($in['cta_button'] ?? $defaults['cta_button'])), 0, 40) ?: $defaults['cta_button'];
    $allowedSections = ['categories', 'near', 'featured', 'services', 'supplies', 'cta'];
    $ordered = array_values(array_unique(array_filter((array)($in['home_sections'] ?? $defaults['home_sections']), fn($s) => in_array($s, $allowedSections, true))));
    $out['home_sections'] = $ordered ?: $defaults['home_sections'];
    $out['hidden_sections'] = array_values(array_intersect((array)($in['hidden_sections'] ?? []), $allowedSections));
    $out['custom_css'] = str_ireplace('</style', '', substr((string)($in['custom_css'] ?? ''), 0, 12000));
    return $out;
}

function system_ui_templates(): array {
    $templates = site_setting_get('system_ui_templates', []);
    return is_array($templates) ? $templates : [];
}

function system_ui_save_template(string $name, array $config): void {
    $name = substr(trim($name), 0, 60);
    if ($name === '') {
        flash('Template name is required.', 'error');
        return;
    }
    $templates = system_ui_templates();
    $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $name), '-')) ?: 'template';
    $base = $slug; $i = 1;
    while (isset($templates[$slug])) $slug = $base . '-' . (++$i);
    $templates[$slug] = ['name' => $name, 'config' => sanitize_system_ui($config), 'created_at' => date('c')];
    site_setting_set('system_ui_templates', $templates);
    flash('Template saved.');
}

function system_ui_button_badge(string $target): string {
    $ui = system_ui_config();
    if (empty($ui['button_badge_enabled'])) return '';
    if (!in_array($ui['button_badge_target'], ['all', $target], true)) return '';
    return '<span class="btn-badge btn-badge-' . e($ui['button_badge_tone']) . '">' . e($ui['button_badge_text']) . '</span>';
}

function system_ui_icon(string $name, string $label = ''): string {
    $ui = system_ui_config();
    $pack = $ui['icon_pack'] ?? 'line';
    $label = $label !== '' ? $label : $name;
    $emoji = [
        'home' => '&#8962;', 'shop' => '&#9638;', 'cart' => '&#128722;', 'play' => '&#9654;',
        'user' => '&#9679;', 'admin' => '&#9881;', 'furniture' => '&#9638;', 'services' => '&#9881;',
        'supplies' => '&#9635;', 'pin' => '&#9679;', 'search' => '&#9906;', 'video' => '&#9654;',
        'overview' => '&#9636;', 'business' => '&#9638;', 'orders' => '&#9776;', 'analytics' => '&#9585;',
        'messages' => '&#9993;', 'subscription' => '&#9733;', 'ads' => '&#9673;', 'category' => '&#9638;',
    ];
    if ($pack === 'emoji') {
        return '<span class="ui-icon ui-icon-emoji" aria-hidden="true">' . ($emoji[$name] ?? '&#9679;') . '</span>';
    }
    if ($pack === 'initials') {
        return '<span class="ui-icon ui-icon-initial" aria-hidden="true">' . e(strtoupper(substr($label, 0, 1))) . '</span>';
    }

    $paths = [
        'home' => '<path d="M3 11.5 12 4l9 7.5"/><path d="M5 10.5V20h5v-5h4v5h5v-9.5"/>',
        'shop' => '<path d="M4 10h16l-1-5H5l-1 5Z"/><path d="M6 10v10h12V10"/><path d="M9 20v-6h6v6"/>',
        'cart' => '<path d="M4 5h2l2 10h9l2-7H7"/><circle cx="10" cy="19" r="1.5"/><circle cx="17" cy="19" r="1.5"/>',
        'play' => '<path d="M8 5v14l11-7-11-7Z"/>',
        'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 20c1.8-4 14.2-4 16 0"/>',
        'admin' => '<path d="M12 3 4 6v6c0 5 3.5 8 8 9 4.5-1 8-4 8-9V6l-8-3Z"/><path d="M9 12l2 2 4-5"/>',
        'furniture' => '<path d="M5 12V8c0-1.7 1.3-3 3-3h8c1.7 0 3 1.3 3 3v4"/><path d="M4 12h16v6H4z"/><path d="M6 18v2M18 18v2"/>',
        'services' => '<path d="M14.5 5.5 18 9l-9 9H5.5v-3.5l9-9Z"/><path d="M13 7l4 4"/>',
        'supplies' => '<path d="M4 8 12 4l8 4-8 4-8-4Z"/><path d="M4 8v8l8 4 8-4V8"/><path d="M12 12v8"/>',
        'pin' => '<path d="M12 21s7-5.3 7-11a7 7 0 0 0-14 0c0 5.7 7 11 7 11Z"/><circle cx="12" cy="10" r="2.5"/>',
        'search' => '<circle cx="11" cy="11" r="6"/><path d="m16 16 4 4"/>',
        'video' => '<path d="M5 6h10v12H5z"/><path d="m15 10 5-3v10l-5-3"/>',
        'overview' => '<path d="M4 13h6V4H4zM14 20h6V4h-6zM4 20h6v-3H4z"/>',
        'business' => '<path d="M4 20h16V8L12 4 4 8v12Z"/><path d="M9 20v-6h6v6"/><path d="M8 10h.01M12 10h.01M16 10h.01"/>',
        'orders' => '<path d="M7 4h10l2 4v12H5V8l2-4Z"/><path d="M5 8h14"/><path d="M9 13h6M9 16h4"/>',
        'analytics' => '<path d="M4 19V5"/><path d="M4 19h16"/><path d="M8 15l3-4 3 2 4-7"/>',
        'messages' => '<path d="M4 5h16v11H8l-4 4V5Z"/><path d="M8 9h8M8 12h5"/>',
        'subscription' => '<path d="m12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.1L12 17.2 6.4 20.1 7.5 14 3 9.6l6.2-.9L12 3Z"/>',
        'ads' => '<path d="M4 14h3l9-5V5l4 2v10l-4 2v-4l-9-5H4v4Z"/><path d="M7 14v5"/>',
        'category' => '<path d="M4 4h7v7H4zM13 4h7v7h-7zM4 13h7v7H4zM13 13h7v7h-7z"/>',
    ];
    $body = $paths[$name] ?? $paths['category'];
    $class = $pack === 'solid' ? 'ui-icon ui-icon-solid' : 'ui-icon ui-icon-line';
    return '<span class="' . $class . '" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false">' . $body . '</svg></span>';
}

function system_ui_category_icon(string $categoryName, string $type = 'product'): string {
    $name = strtolower($categoryName);
    if (str_contains($name, 'sofa') || str_contains($name, 'chair') || str_contains($name, 'bed') || str_contains($name, 'table') || str_contains($name, 'furniture')) return system_ui_icon('furniture', $categoryName);
    if (str_contains($name, 'service') || str_contains($name, 'design') || str_contains($name, 'work') || str_contains($name, 'install') || str_contains($name, 'paint')) return system_ui_icon('services', $categoryName);
    if ($type === 'supply' || str_contains($name, 'wood') || str_contains($name, 'board') || str_contains($name, 'paint') || str_contains($name, 'tool')) return system_ui_icon('supplies', $categoryName);
    return system_ui_icon('category', $categoryName);
}

function system_ui_section_enabled(string $section): bool {
    $ui = system_ui_config();
    return !in_array($section, $ui['hidden_sections'] ?? [], true);
}

function system_ui_style_tag(): string {
    $ui = system_ui_config();
    $shadow = (int)$ui['shadow_strength'];
    $density = ['compact' => 34, 'comfortable' => 40, 'spacious' => 46][$ui['component_density']] ?? 40;
    $heroImage = trim((string)$ui['hero_image']);
    $overlay = max(0.2, min(0.92, ((int)$ui['hero_overlay']) / 100));
    $heroMode = $ui['hero_background_mode'] ?? 'overlay_image';
    $heroFrom = $ui['hero_gradient_from'] ?? '#111827';
    $heroTo = $ui['hero_gradient_to'] ?? '#0f766e';
    $heroPosition = $ui['hero_image_position'] ?? 'center';
    $heroBackground = "linear-gradient(115deg,{$heroFrom},{$heroTo})";
    if ($heroImage !== '') {
        $safeHero = str_replace(['"', "'", ')'], '', $heroImage);
        if ($heroMode === 'image') {
            $heroBackground = "url(\"" . e($safeHero) . "\") {$heroPosition}/cover no-repeat";
        } elseif ($heroMode === 'overlay_image') {
            $heroBackground = "linear-gradient(115deg,rgba(2,6,23,{$overlay}),rgba(15,118,110," . max(0.2, $overlay - 0.16) . ")),url(\"" . e($safeHero) . "\") {$heroPosition}/cover no-repeat";
        }
    }
    $fontStack = [
        'inter' => "Inter,'Segoe UI',system-ui,-apple-system,sans-serif",
        'system' => "system-ui,-apple-system,'Segoe UI',sans-serif",
        'serif' => "Georgia,'Times New Roman',serif",
        'rounded' => "'Segoe UI Rounded','Arial Rounded MT Bold',Inter,system-ui,sans-serif",
    ][$ui['font_family']] ?? "Inter,'Segoe UI',system-ui,-apple-system,sans-serif";
    $css = ":root{"
        . "--brand:{$ui['brand']};--brand-dark:{$ui['brand_dark']};--brand-soft:{$ui['brand_soft']};"
        . "--accent:{$ui['accent']};--accent-soft:{$ui['accent_soft']};--ink:{$ui['ink']};--text:{$ui['text']};"
        . "--bg:{$ui['bg']};--surface:{$ui['surface']};--r:" . (int)$ui['card_radius'] . "px;--r-sm:" . max(6, (int)$ui['panel_radius'] - 4) . "px;"
        . "--shadow-sm:0 8px 20px -16px rgba(16,24,40,." . min(90, $shadow) . ");"
        . "--shadow:0 18px 42px -28px rgba(16,24,40,." . min(90, $shadow + 8) . ");"
        . "}"
        . "body{font-family:{$fontStack};font-size:" . ((int)$ui['font_scale'] / 100 * 15.5) . "px}.container{max-width:" . (int)$ui['container_width'] . "px}.section{padding-top:" . (int)$ui['section_spacing'] . "px;padding-bottom:" . (int)$ui['section_spacing'] . "px}.grid{grid-template-columns:repeat(auto-fill,minmax(" . (int)$ui['grid_min_width'] . "px,1fr))}"
        . ".card,.filters,.panel,.dash-nav,.table-wrap,.cat-tile,input,select,textarea{border-width:" . (int)$ui['border_width'] . "px}"
        . ".btn,.header-search input,.header-search button{min-height:{$density}px}.btn,.header-search button,.hero-search .btn{border-radius:" . (int)$ui['button_radius'] . "px}"
        . "input,select,textarea{border-radius:" . (int)$ui['input_radius'] . "px}"
        . ".card-img{aspect-ratio:{$ui['card_image_ratio']}}"
        . ".card,.filters,.panel,.dash-nav,.table-wrap{border-radius:" . (int)$ui['panel_radius'] . "px}"
        . ".hero{background:{$heroBackground}}";
    if ($ui['theme_mode'] === 'soft-dark') $css .= "body{background:#0f172a;color:#dbe4ef}.site-header,.card,.filters,.panel,.table-wrap,.dash-nav,.mobile-nav{background:#172033;color:#dbe4ef;border-color:#26344d}h1,h2,h3,h4,.card-title{color:#f8fafc}.muted,.card-meta,.card-vendor{color:#a9b7ca}input,select,textarea{background:#101827;color:#f8fafc;border-color:#2d3a52}";
    if ($ui['theme_mode'] === 'high-contrast') $css .= "body{background:#fff;color:#000}h1,h2,h3,h4,.card-title{color:#000}.card,.filters,.panel,.table-wrap,.dash-nav,input,select,textarea{border-color:#000!important}.btn-primary{background:#000!important;color:#fff!important}.muted{color:#333}";
    if ($ui['focus_style'] === 'underline') $css .= "input:focus,select:focus,textarea:focus{box-shadow:none;border-color:var(--brand);border-bottom-width:3px}";
    if ($ui['focus_style'] === 'glow') $css .= "input:focus,select:focus,textarea:focus,.btn:focus-visible,a:focus-visible{box-shadow:0 0 0 5px color-mix(in srgb,var(--brand) 22%,transparent),0 0 24px color-mix(in srgb,var(--brand) 30%,transparent);outline:none}";
    if ($ui['button_style'] === 'gradient') $css .= ".btn-primary{background:linear-gradient(135deg,var(--brand),var(--accent))!important}.btn-outline{border-color:var(--brand);background:linear-gradient(135deg,#fff,var(--brand-soft))}";
    if ($ui['button_style'] === 'flat') $css .= ".btn,.btn:hover{box-shadow:none!important;transform:none}.btn-primary{background:var(--brand)!important}";
    if ($ui['header_behavior'] === 'static') $css .= ".site-header{position:relative}";
    if ($ui['header_behavior'] === 'floating') $css .= ".site-header{top:10px;margin:10px auto 0;width:min(calc(100% - 24px)," . (int)$ui['container_width'] . "px);border-radius:18px;border:1px solid var(--line);box-shadow:var(--shadow-sm)}";
    if ($ui['nav_style'] === 'solid') $css .= ".site-header{background:#fff;backdrop-filter:none}.main-nav>a:hover{background:var(--brand-soft)}";
    if ($ui['nav_style'] === 'dark') $css .= ".site-header{background:#0f172a;border-bottom-color:#243148}.site-header .logo,.site-header .main-nav>a{color:#fff}.site-header .main-nav>a:hover{background:rgba(255,255,255,.12)}";
    if ($ui['form_style'] === 'outlined') $css .= "input,select,textarea{background:#fff;border-width:2px}.panel,.filters{background:#fff}";
    if ($ui['form_style'] === 'filled') $css .= "input,select,textarea{background:var(--surface-2);border-color:transparent}.filters label input,.filters label select{background:#fff}";
    if ($ui['ad_style'] === 'boxed') $css .= ".ad-banner,.ad-house{border:1px solid var(--line);box-shadow:var(--shadow);padding:8px}.ad-label{background:var(--brand);color:#fff}";
    if ($ui['ad_style'] === 'premium') $css .= ".ad-banner,.ad-house{border:1px solid rgba(249,115,22,.3);box-shadow:0 20px 48px -30px rgba(249,115,22,.8)}.ad-label{background:var(--accent);color:#fff}";
    if ($ui['table_style'] === 'striped') $css .= ".data-table tr:nth-child(even) td{background:#f8fafc}";
    if ($ui['table_style'] === 'compact') $css .= ".data-table th,.data-table td{padding:7px 10px}.data-table{font-size:.8rem}";
    if ($ui['badge_style'] === 'square') $css .= ".badge,.pill,.btn-badge{border-radius:7px}";
    if ($ui['badge_style'] === 'soft') $css .= ".badge{background:var(--brand-soft);color:var(--brand-dark)}";
    if ($ui['card_style'] === 'borderless') $css .= ".card{border:0}.card-body{padding:16px}";
    if ($ui['card_style'] === 'outlined') $css .= ".card{box-shadow:none;border-width:2px}.card:hover{box-shadow:var(--shadow-sm)}";
    if ($ui['card_style'] === 'compact') $css .= ".card-body{padding:11px 12px}.card-title{min-height:auto;font-size:.9rem}.card-price{font-size:.96rem}";
    if ($ui['hover_motion'] === 'soft') $css .= ".card:hover,.cat-tile:hover,.btn:hover{transform:none;box-shadow:var(--shadow-sm)}";
    if ($ui['hover_motion'] === 'none') $css .= "*,*::before,*::after{transition:none!important;animation:none!important}.card:hover,.cat-tile:hover,.btn:hover{transform:none!important}";
    if ($ui['image_treatment'] === 'warm') $css .= ".card-img img,.gallery img{filter:saturate(1.08) sepia(.08)}";
    if ($ui['image_treatment'] === 'cool') $css .= ".card-img img,.gallery img{filter:saturate(.95) hue-rotate(8deg)}";
    if ($ui['image_treatment'] === 'mono') $css .= ".card-img img,.gallery img{filter:grayscale(1)}";
    if ($ui['image_hover'] === 'fade') $css .= ".card:hover .card-img img{transform:none;opacity:.86}";
    if ($ui['image_hover'] === 'none') $css .= ".card:hover .card-img img{transform:none;opacity:1}";
    if ($ui['section_head_style'] === 'rule') $css .= ".section-head{border-bottom:1px solid var(--line);padding-bottom:10px}";
    if ($ui['section_head_style'] === 'boxed') $css .= ".section-head{background:var(--surface);border:1px solid var(--line);border-radius:var(--r);padding:12px 14px;box-shadow:var(--shadow-xs)}";
    if ($ui['filters_behavior'] === 'static') $css .= ".filters{position:static}";
    if ($ui['card_text_align'] === 'center') $css .= ".card-body{text-align:center;align-items:center}.card-meta{justify-content:center}";
    if ($ui['footer_style'] === 'light') $css .= ".site-footer{background:#fff;color:var(--text-2);border-top:1px solid var(--line)}.site-footer h4,.site-footer .logo{color:var(--ink)}.site-footer a{color:var(--text-2)}.footer-bottom{border-top-color:var(--line);color:var(--muted)}";
    if ($ui['footer_style'] === 'brand') $css .= ".site-footer{background:linear-gradient(135deg,var(--brand-dark),var(--brand));color:#dff8f4}.site-footer a{color:#eafcf9}.footer-bottom{border-top-color:rgba(255,255,255,.18);color:#c7f4ee}";
    if ($ui['mobile_nav_style'] === 'minimal') $css .= ".mobile-nav{background:#fff}.mobile-nav .mn-icon{background:transparent!important;width:auto;height:auto}.mobile-nav a.current{color:var(--brand)}";
    if ($ui['mobile_nav_style'] === 'boxed') $css .= ".mobile-nav{left:10px;right:10px;bottom:10px;border:1px solid var(--line);border-radius:18px;box-shadow:var(--shadow)}";
    if ($ui['search_style'] === 'box') $css .= ".header-search input,.header-search button{border-radius:12px}.hero-search,.hero-search input,.hero-search select,.hero-search .btn{border-radius:12px}";
    if ($ui['search_style'] === 'underline') $css .= ".header-search input{border-width:0 0 2px 0;border-radius:0;background:transparent}.header-search button{border-radius:10px}.header-search::before,.header-search::after{display:none}";
    if ($ui['hero_align'] === 'center') $css .= ".hero{text-align:center}.hero p,.hero-search{margin-left:auto;margin-right:auto}.hero-links,.hero-stats{justify-content:center}";
    if ($ui['hero_align'] === 'split') $css .= ".hero .container{display:grid;grid-template-columns:minmax(0,1fr) minmax(260px,.65fr);gap:28px;align-items:center}.hero .container::after{content:'';min-height:260px;border-radius:var(--r-lg);background:rgba(255,255,255,.13);border:1px solid rgba(255,255,255,.2);box-shadow:var(--shadow)}@media(max-width:760px){.hero .container{display:block}.hero .container::after{display:none}}";
    if ($ui['hero_height'] === 'compact') $css .= ".hero{padding-top:42px;padding-bottom:34px}.hero h1{font-size:clamp(1.8rem,4vw,2.7rem)}";
    if ($ui['hero_height'] === 'tall') $css .= ".hero{padding-top:104px;padding-bottom:92px}";
    if ($ui['category_style'] === 'minimal') $css .= ".cat-tile{box-shadow:none;background:transparent}.cat-icon{display:none}";
    if ($ui['category_style'] === 'banner') $css .= ".cat-grid{grid-template-columns:repeat(auto-fill,minmax(220px,1fr))}.cat-tile{min-height:96px;background:linear-gradient(135deg,var(--brand-soft),#fff)}.cat-icon{background:#fff}";
    if ($ui['category_style'] === 'rail') $css .= ".cat-grid{display:flex;gap:10px;overflow-x:auto;scroll-snap-type:x mandatory;padding:2px 2px 10px}.cat-grid::-webkit-scrollbar{height:6px}.cat-grid::-webkit-scrollbar-thumb{background:var(--line);border-radius:999px}.cat-tile{min-width:max-content;min-height:48px;padding:8px 13px;border-radius:999px;scroll-snap-align:start}.cat-icon{width:30px;height:30px;border-radius:999px}.cat-icon .ui-icon{width:16px;height:16px}";
    if ($ui['price_style'] === 'brand') $css .= ".card-price,.price{color:var(--brand-dark)}";
    if ($ui['price_style'] === 'accent') $css .= ".card-price,.price{color:var(--accent)}";
    if ($ui['price_style'] === 'dark') $css .= ".card-price,.price{color:var(--ink)}";
    if ($ui['empty_state_style'] === 'soft') $css .= ".empty-state{border-style:solid;background:var(--brand-soft);color:var(--brand-dark)}";
    if ($ui['empty_state_style'] === 'plain') $css .= ".empty-state{border-color:transparent;background:transparent;box-shadow:none}";
    if (!empty($ui['announcement_enabled'])) {
        $barBg = $ui['announcement_tone'] === 'accent' ? 'var(--accent)' : ($ui['announcement_tone'] === 'dark' ? 'var(--ink)' : ($ui['announcement_tone'] === 'light' ? '#fff' : 'var(--brand)'));
        $barColor = $ui['announcement_tone'] === 'light' ? 'var(--ink)' : '#fff';
        $css .= ".announcement-bar{display:block;background:{$barBg};color:{$barColor}}";
    }
    if (trim((string)$ui['custom_css']) !== '') $css .= "\n" . $ui['custom_css'];
    return "<style id=\"system-ui-optimizer-css\">\n{$css}\n</style>";
}

// ---------- location detection (GPS permission → IP fallback → default) ----------
function haversine_km(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $r = 6371;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

function nearest_city(float $lat, float $lng): string {
    $best = DEFAULT_CITY; $bestDist = INF;
    foreach (CITY_COORDS as $city => [$clat, $clng]) {
        $d = haversine_km($lat, $lng, $clat, $clng);
        if ($d < $bestDist) { $bestDist = $d; $best = $city; }
    }
    return $best;
}

/** Nearest known neighborhood within a given city, or null if that city has no sub-city map yet. */
function nearest_subcity(string $city, float $lat, float $lng): ?string {
    $options = SUBCITY_COORDS[$city] ?? [];
    if (!$options) return null;
    $best = null; $bestDist = INF;
    foreach ($options as $subcity => [$slat, $slng]) {
        $d = haversine_km($lat, $lng, $slat, $slng);
        if ($d < $bestDist) { $bestDist = $d; $best = $subcity; }
    }
    return $best;
}

/** City + (when available) neighborhood nearest to a precise GPS fix. */
function nearest_location(float $lat, float $lng): array {
    $city = nearest_city($lat, $lng);
    return ['city' => $city, 'subcity' => nearest_subcity($city, $lat, $lng)];
}

function is_private_ip(string $ip): bool {
    return $ip === '' || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
}

/** Best-effort server-side IP geolocation. Returns null on any failure (private IP, no network, bad response). */
function ip_geolocate(): ?array {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (is_private_ip($ip)) return null; // localhost/LAN — nothing a public IP API can resolve

    $url = IP_GEO_API . rawurlencode($ip) . '?fields=status,lat,lon,city';
    $json = null;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 3]);
        $json = curl_exec($ch);
        curl_close($ch);
    } elseif (ini_get('allow_url_fopen')) {
        $json = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 3]]));
    }
    if (!$json) return null;
    $data = json_decode($json, true);
    if (!$data || ($data['status'] ?? '') !== 'success' || !isset($data['lat'], $data['lon'])) return null;
    return ['lat' => (float)$data['lat'], 'lng' => (float)$data['lon']];
}

/** Current visitor location: session (fast) → cookie (returning visit) → IP lookup (once) → default city. */
function user_location(): array {
    static $cached = null;
    if ($cached !== null) return $cached;

    // admin can switch auto-detection off (Settings → Features): everyone gets the default city,
    // and the 'disabled' source stops app.js from requesting GPS permission
    if (function_exists('feature_enabled') && !feature_enabled('location_detection')) {
        $city = (string)sys('general.default_city', DEFAULT_CITY);
        [$lat, $lng] = CITY_COORDS[$city] ?? CITY_COORDS[DEFAULT_CITY];
        return $cached = ['city' => $city, 'subcity' => null, 'lat' => $lat, 'lng' => $lng, 'source' => 'disabled'];
    }

    if (!empty($_SESSION['loc'])) return $cached = $_SESSION['loc'];

    if (!empty($_COOKIE['ak_loc'])) {
        $decoded = json_decode($_COOKIE['ak_loc'], true);
        if (is_array($decoded) && isset($decoded['city'])) {
            $_SESSION['loc'] = $decoded;
            return $cached = $decoded;
        }
    }

    $geo = ip_geolocate();
    if ($geo) {
        $loc = ['city' => nearest_city($geo['lat'], $geo['lng']), 'subcity' => null, 'lat' => $geo['lat'], 'lng' => $geo['lng'], 'source' => 'ip'];
    } else {
        [$lat, $lng] = CITY_COORDS[DEFAULT_CITY];
        $loc = ['city' => DEFAULT_CITY, 'subcity' => null, 'lat' => $lat, 'lng' => $lng, 'source' => 'default'];
    }
    $_SESSION['loc'] = $loc;
    if (!headers_sent()) setcookie('ak_loc', json_encode($loc), time() + 30 * 86400, BASE_URL . '/');
    return $cached = $loc;
}

/** Explicitly set the visitor's location (GPS fix or manual city pick) and persist it. */
function set_user_location(string $city, ?string $subcity, ?float $lat, ?float $lng, string $source): array {
    if ($lat === null || $lng === null) [$lat, $lng] = CITY_COORDS[$city] ?? CITY_COORDS[DEFAULT_CITY];
    $loc = ['city' => $city, 'subcity' => $subcity, 'lat' => $lat, 'lng' => $lng, 'source' => $source];
    $_SESSION['loc'] = $loc;
    if (!headers_sent()) setcookie('ak_loc', json_encode($loc), time() + 30 * 86400, BASE_URL . '/');
    return $loc;
}

// ---------- cart (session) ----------
function cart(): array { return $_SESSION['cart'] ?? []; }

function cart_add(string $type, int $id, float $qty): void {
    $key = "$type:$id";
    $_SESSION['cart'][$key] = max(0.01, ($_SESSION['cart'][$key] ?? 0) + $qty);
}

function cart_set(string $type, int $id, float $qty): void {
    $key = "$type:$id";
    if ($qty <= 0) unset($_SESSION['cart'][$key]);
    else $_SESSION['cart'][$key] = $qty;
}

/** Resolve cart into rows grouped by business: [business_id => ['business'=>row,'items'=>[],'subtotal'=>n]] */
function cart_resolve(): array {
    $groups = [];
    foreach (cart() as $key => $qty) {
        [$type, $id] = explode(':', $key);
        if ($type === 'product') {
            $l = row("SELECT p.*, b.business_name FROM products p JOIN businesses b ON b.id = p.business_id
                      WHERE p.id = ? AND p.status = 'active'", [(int)$id]);
            if (!$l) { cart_set($type, (int)$id, 0); continue; }
            $price = $l['discount_price'] > 0 ? (float)$l['discount_price'] : (float)$l['price'];
            $title = $l['title']; $unit = $l['unit'] ?: 'piece';
        } elseif ($type === 'supply') {
            $l = row("SELECT s.*, b.business_name FROM supplies s JOIN businesses b ON b.id = s.business_id
                      WHERE s.id = ? AND s.status = 'active'", [(int)$id]);
            if (!$l) { cart_set($type, (int)$id, 0); continue; }
            $price = (float)$l['price_per_unit'];
            $qty = max($qty, (float)$l['minimum_order_quantity']);
            $title = $l['name']; $unit = $l['unit_of_measurement'] ?: 'piece';
        } else continue;
        $bid = (int)$l['business_id'];
        $groups[$bid]['business_id'] = $bid;
        $groups[$bid]['business_name'] = $l['business_name'];
        $groups[$bid]['items'][] = ['type' => $type, 'id' => (int)$id, 'title' => $title, 'slug' => $l['slug'],
            'price' => $price, 'qty' => $qty, 'unit' => $unit, 'line' => $price * $qty];
        $groups[$bid]['subtotal'] = ($groups[$bid]['subtotal'] ?? 0) + $price * $qty;
    }
    return $groups;
}

function cart_count(): int { return count(cart()); }

// ---------- subscriptions & limits ----------
function current_plan(int $businessId): string {
    $sub = row("SELECT plan FROM subscriptions WHERE business_id = ? AND status = 'active'
                AND (ends_at IS NULL OR ends_at > NOW()) ORDER BY ends_at DESC LIMIT 1", [$businessId]);
    return $sub['plan'] ?? 'free';
}

function listing_count(int $businessId): int {
    return (int)val("SELECT
        (SELECT COUNT(*) FROM products WHERE business_id = ? AND status NOT IN ('deleted','rejected'))
      + (SELECT COUNT(*) FROM services WHERE business_id = ? AND status NOT IN ('deleted','rejected'))
      + (SELECT COUNT(*) FROM supplies WHERE business_id = ? AND status NOT IN ('deleted','rejected'))",
      [$businessId, $businessId, $businessId]);
}

function can_add_listing(int $businessId): bool {
    $limit = plans()[current_plan($businessId)]['listings'];
    return $limit < 0 || listing_count($businessId) < $limit;
}

function can_add_video(int $businessId): bool {
    $limit = plans()[current_plan($businessId)]['videos'];
    if ($limit < 0) return true;
    return (int)val("SELECT COUNT(*) FROM video_posts WHERE business_id = ? AND status NOT IN ('deleted','rejected')", [$businessId]) < $limit;
}

// ---------- promotions ----------
/** Apply/remove visibility flags for a promotion row. */
function promotion_apply(array $p, bool $on): void {
    $flag = $on ? 1 : 0;
    if ($p['promotable_type'] === 'video') {
        q("UPDATE video_posts SET is_promoted = ? WHERE id = ?", [$flag, $p['promotable_id']]);
    } elseif (isset(LISTING_TABLES[$p['promotable_type']])) {
        $t = LISTING_TABLES[$p['promotable_type']];
        q("UPDATE `$t` SET is_featured = ?, is_promoted = ? WHERE id = ?", [$flag, $flag, $p['promotable_id']]);
    }
}

function order_number(): string {
    return 'EG' . date('ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
}

function upload_model(array $file, string $ext): ?string {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if ($file['error'] !== UPLOAD_ERR_OK) { flash('3D model upload failed.', 'error'); return null; }
    $maxMb = (int)sys('limits.ar_model_max_mb', 10);
    if ($file['size'] > $maxMb * 1024 * 1024) { flash("3D model too large (max $maxMb MB).", 'error'); return null; }
    if (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== $ext) { flash("Model must be a .$ext file.", 'error'); return null; }
    $dir = UPLOAD_DIR . '/ar-models';
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $name = 'ar-models/' . bin2hex(random_bytes(10)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], UPLOAD_DIR . '/' . $name)) { flash('Could not save model.', 'error'); return null; }
    return $name;
}

function paginate(int $total, int $perPage, int $page): array {
    $pages = max(1, (int)ceil($total / $perPage));
    $page = max(1, min($page, $pages));
    return [$page, $pages, ($page - 1) * $perPage];
}
