<?php
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('');
csrf_check();
$u = require_login();

$type = $_POST['listing_type'] ?? '';
$lid = (int)($_POST['listing_id'] ?? 0) ?: null;
$bid = (int)($_POST['business_id'] ?? 0);
$rating = max(1, min(5, (int)($_POST['rating'] ?? 0)));
$comment = trim($_POST['comment'] ?? '');
$title = trim($_POST['title'] ?? '');

if (!in_array($type, ['product', 'service', 'supply', 'business'], true) || !$bid || $comment === '') {
    flash('Review could not be submitted.', 'error'); redirect('');
}
// one review per user per target
if (val("SELECT COUNT(*) FROM reviews WHERE reviewer_id = ? AND business_id = ? AND listing_type = ? AND (listing_id <=> ?)", [$u['id'], $bid, $type, $lid])) {
    flash('You already reviewed this.', 'error');
} else {
    // §13.2: reviews tied to a completed order get the "verified purchase" badge
    $orderId = val("SELECT id FROM orders WHERE customer_id = ? AND business_id = ? AND status IN ('delivered','completed')
                    ORDER BY created_at DESC LIMIT 1", [$u['id'], $bid]);
    // optional photos with the review (§13.3 images)
    $images = [];
    foreach (($_FILES['images']['name'] ?? []) as $i => $n) {
        if (count($images) >= 3) break;
        $file = ['name' => $n, 'type' => $_FILES['images']['type'][$i], 'tmp_name' => $_FILES['images']['tmp_name'][$i],
                 'error' => $_FILES['images']['error'][$i], 'size' => $_FILES['images']['size'][$i]];
        $path = upload_image($file, 'reviews');
        if ($path) $images[] = $path;
    }
    $rStatus = sys('moderation.auto_approve_reviews') ? 'approved' : 'pending'; // §16.3 policy switch
    q("INSERT INTO reviews (reviewer_id, business_id, listing_type, listing_id, order_id, rating, title, comment, images, is_verified_purchase, status)
       VALUES (?,?,?,?,?,?,?,?,?,?,?)",
      [$u['id'], $bid, $type, $lid, $orderId ?: null, $rating, $title ?: null, $comment,
       $images ? json_encode($images) : null, $orderId ? 1 : 0, $rStatus]);
    if ($rStatus === 'approved') {
        $agg = row("SELECT AVG(rating) a, COUNT(*) c FROM reviews WHERE business_id = ? AND status = 'approved'", [$bid]);
        q("UPDATE businesses SET rating_average = ?, rating_count = ? WHERE id = ?", [round($agg['a'], 2), $agg['c'], $bid]);
        notify_business($bid, 'review_received', 'You received a new ' . $rating . '★ review', 'vendor/reviews');
    }
    flash($rStatus === 'approved' ? 'Thanks! Your review is live.' : 'Thanks! Your review will appear after moderation.');
}
$ref = $_SERVER['HTTP_REFERER'] ?? '';
redirect($ref ? ltrim(substr(parse_url($ref, PHP_URL_PATH), strlen(BASE_URL)), '/') : '');
