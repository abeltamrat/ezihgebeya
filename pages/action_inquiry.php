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

if (!in_array($type, ['product', 'service', 'supply', 'business', 'video'], true) || !$bid || $msg === '' || strlen($phone) < 9) {
    flash('Please fill in your phone and message.', 'error');
    redirect($_SERVER['HTTP_REFERER'] ? substr(parse_url($_SERVER['HTTP_REFERER'], PHP_URL_PATH), strlen(BASE_URL) + 1) : '');
}
if (!val("SELECT COUNT(*) FROM businesses WHERE id = ? AND status = 'active'", [$bid])) redirect('');

// rate limit per session — thresholds set in admin → Settings → Limits
$rateMax = (int)sys('limits.inquiry_rate_max', 5);
$rateWindow = (int)sys('limits.inquiry_rate_window_min', 10) * 60;
$_SESSION['inq_times'] = array_filter($_SESSION['inq_times'] ?? [], fn($t) => $t > time() - $rateWindow);
if (count($_SESSION['inq_times']) >= $rateMax) { flash('Too many inquiries — please wait a few minutes.', 'error'); redirect(''); }
$_SESSION['inq_times'][] = time();

$listingTitle = null;
if ($lid && isset(LISTING_TABLES[$type])) {
    $t = LISTING_TABLES[$type];
    $listingTitle = val("SELECT " . listing_title_col($type) . " FROM `$t` WHERE id = ?", [$lid]);
    q("UPDATE `$t` SET inquiries_count = inquiries_count + 1 WHERE id = ?", [$lid]);
}

q("INSERT INTO inquiries (customer_id, business_id, listing_type, listing_id, listing_title, inquiry_type, name, message, phone, preferred_contact_method, source)
   VALUES (?,?,?,?,?,?,?,?,?,?,?)",
  [$u['id'] ?? null, $bid, $type, $lid ?: null, $listingTitle, $itype, $name, $msg, $phone, $contact, $source]);
$inqId = (int)db()->lastInsertId();
notify_business($bid, 'new_inquiry', 'New inquiry' . ($listingTitle ? ' about ' . $listingTitle : '') . ' from ' . ($name ?: $phone),
    'inquiries/' . $inqId, mb_substr($msg, 0, 200), true);

flash('Inquiry sent! The vendor will contact you on ' . $phone . '.');
$ref = $_SERVER['HTTP_REFERER'] ?? '';
redirect($ref ? ltrim(substr(parse_url($ref, PHP_URL_PATH), strlen(BASE_URL)), '/') : '');
