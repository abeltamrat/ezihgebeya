<?php
/** Core Web Vitals beacon endpoint. POSTed by assets/js/app.js via sendBeacon/fetch. */
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('{"ok":false}'); }

$token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['_token'] ?? '';
if (!hash_equals(csrf_token(), $token)) { http_response_code(419); exit('{"ok":false}'); }
if (!db_table_exists('events')) exit('{"ok":true,"skipped":true}');

$metric = strtoupper(preg_replace('/[^A-Z0-9_]/i', '', (string)($_POST['metric'] ?? '')));
$allowed = ['LCP', 'CLS', 'INP', 'FID', 'FCP', 'TTFB'];
if (!in_array($metric, $allowed, true)) exit('{"ok":false}');

$value = (float)($_POST['value'] ?? 0);
if (!is_finite($value) || $value < 0) exit('{"ok":false}');

$rating = $_POST['rating'] ?? 'unknown';
if (!in_array($rating, ['good', 'needs-improvement', 'poor', 'unknown'], true)) $rating = 'unknown';

$path = parse_url((string)($_POST['path'] ?? ''), PHP_URL_PATH) ?: '/';
$pageType = trim((string)($_POST['page_type'] ?? ''));
event_record('web_vital', [
    'source' => 'organic',
    'metadata' => [
        'metric' => $metric,
        'value' => round($value, $metric === 'CLS' ? 4 : 0),
        'rating' => $rating,
        'path' => mb_substr($path, 0, 180),
        'page_type' => mb_substr($pageType, 0, 80),
        'connection' => mb_substr((string)($_POST['connection'] ?? ''), 0, 40),
        'device_memory' => isset($_POST['device_memory']) ? (float)$_POST['device_memory'] : null,
        'viewport' => mb_substr((string)($_POST['viewport'] ?? ''), 0, 40),
    ],
]);

echo '{"ok":true}';
