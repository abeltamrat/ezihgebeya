<?php
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('');
csrf_check();
$u = auth();

$type = $_POST['listing_type'] ?? '';
$lid = (int)($_POST['listing_id'] ?? 0);
$bid = (int)($_POST['business_id'] ?? 0);
$msg = trim($_POST['message'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$name = trim($_POST['name'] ?? ($u['full_name'] ?? ''));
$itype = $_POST['inquiry_type'] ?? 'product_inquiry';
$contact = $_POST['preferred_contact_method'] ?? 'phone';
$source = $_POST['source'] ?? 'product_detail';
$conversionAction = $_POST['conversion_action'] ?? '';
$allowedSources = ['product_detail', 'video_feed', 'search_result', 'business_profile', 'featured_ad', 'telegram_mini_app', 'pwa'];
if (!in_array($source, $allowedSources, true)) $source = 'product_detail';

if ($conversionAction === 'make_offer') {
    $offerAmount = (float)($_POST['offer_amount'] ?? 0);
    if ($offerAmount > 0 && $msg === '') {
        $msg = 'Make an offer: ' . money($offerAmount) . '. Please confirm if this price is acceptable.';
    }
} elseif ($conversionAction === 'request_callback') {
    $callbackTime = trim($_POST['callback_time'] ?? '');
    if ($msg === '') {
        $msg = 'Request call back' . ($callbackTime !== '' ? ' around ' . $callbackTime : '') . '. Please call me about this listing.';
    }
    $contact = 'phone';
}
$trafficSource = traffic_source_for_listing($type, $lid, $source === 'video_feed' ? 'video_feed' : ($source === 'featured_ad' ? 'ad' : 'organic'));

if (!in_array($type, ['product', 'service', 'supply', 'business', 'video'], true) || !$bid || $msg === '' || strlen($phone) < 9) {
    flash('Please fill in your phone and message.', 'error');
    redirect($_SERVER['HTTP_REFERER'] ? substr(parse_url($_SERVER['HTTP_REFERER'], PHP_URL_PATH), strlen(BASE_URL) + 1) : '');
}
if (!val("SELECT COUNT(*) FROM businesses WHERE id = ? AND status = 'active'", [$bid])) redirect('');

// rate limit per session — thresholds set in admin → Settings → Limits
$rateMax = (int)sys('limits.inquiry_rate_max', 5);
$rateWindow = (int)sys('limits.inquiry_rate_window_min', 10) * 60;
if (rate_limited('inquiry', $rateMax, $rateWindow)) { flash('Too many inquiries — please wait a few minutes.', 'error'); redirect(''); }

$listingTitle = null;
if ($lid && isset(LISTING_TABLES[$type])) {
    $t = LISTING_TABLES[$type];
    $listingTitle = val("SELECT " . listing_title_col($type) . " FROM `$t` WHERE id = ?", [$lid]);
    q("UPDATE `$t` SET inquiries_count = inquiries_count + 1 WHERE id = ?", [$lid]);
}

if (db_column_exists('inquiries', 'traffic_source')) {
    q("INSERT INTO inquiries (customer_id, business_id, listing_type, listing_id, listing_title, inquiry_type, name, message, phone, preferred_contact_method, source, traffic_source)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
      [$u['id'] ?? null, $bid, $type, $lid ?: null, $listingTitle, $itype, $name, $msg, $phone, $contact, $source, $trafficSource]);
} else {
    q("INSERT INTO inquiries (customer_id, business_id, listing_type, listing_id, listing_title, inquiry_type, name, message, phone, preferred_contact_method, source)
       VALUES (?,?,?,?,?,?,?,?,?,?,?)",
      [$u['id'] ?? null, $bid, $type, $lid ?: null, $listingTitle, $itype, $name, $msg, $phone, $contact, $source]);
}
$inqId = (int)db()->lastInsertId();
event_record('inquiry', [
    'user_id' => $u['id'] ?? null,
    'listing_type' => $type,
    'listing_id' => $lid ?: null,
    'business_id' => $bid,
    'source' => $trafficSource,
    'metadata' => ['inquiry_id' => $inqId, 'legacy_source' => $source, 'conversion_action' => $conversionAction ?: null],
]);
notify_business($bid, 'new_inquiry', 'New inquiry' . ($listingTitle ? ' about ' . $listingTitle : '') . ' from ' . ($name ?: $phone),
    'inquiries/' . $inqId, mb_substr($msg, 0, 200), true);

flash('Inquiry sent! The vendor will contact you on ' . $phone . '.');
$ref = $_SERVER['HTTP_REFERER'] ?? '';
redirect($ref ? ltrim(substr(parse_url($ref, PHP_URL_PATH), strlen(BASE_URL)), '/') : '');
