<?php
/** Sets the visitor's location from GPS coords or a manual city pick. POST only. */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('');
csrf_check();

$wantsJson = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') || (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch');

if (isset($_POST['lat'], $_POST['lng']) && is_numeric($_POST['lat']) && is_numeric($_POST['lng'])) {
    $lat = (float)$_POST['lat']; $lng = (float)$_POST['lng'];
    $near = nearest_location($lat, $lng);
    $loc = set_user_location($near['city'], $near['subcity'], $lat, $lng, 'gps');
} elseif (isset($_POST['city']) && array_key_exists($_POST['city'], CITIES)) {
    $subcity = trim($_POST['subcity'] ?? '') ?: null;
    if ($subcity && !in_array($subcity, CITIES[$_POST['city']], true)) $subcity = null;
    $loc = set_user_location($_POST['city'], $subcity, null, null, 'manual');
} else {
    if ($wantsJson) { http_response_code(422); echo json_encode(['ok' => false, 'error' => 'invalid location']); exit; }
    flash('Could not update location.', 'error');
    redirect('');
}

if ($wantsJson) { echo json_encode(['ok' => true, 'loc' => $loc]); exit; }
$ref = $_SERVER['HTTP_REFERER'] ?? '';
redirect($ref ? ltrim(substr(parse_url($ref, PHP_URL_PATH), strlen(BASE_URL)), '/') : '');
