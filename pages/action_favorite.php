<?php
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('');
csrf_check();
$u = require_login();
$pid = (int)($_POST['product_id'] ?? 0);

if ($pid && val("SELECT COUNT(*) FROM products WHERE id = ? AND status = 'active'", [$pid])) {
    if (val("SELECT COUNT(*) FROM favorites WHERE user_id = ? AND product_id = ?", [$u['id'], $pid])) {
        q("DELETE FROM favorites WHERE user_id = ? AND product_id = ?", [$u['id'], $pid]);
        q("UPDATE products SET favorites_count = GREATEST(favorites_count - 1, 0) WHERE id = ?", [$pid]);
        flash('Removed from saved products.');
    } else {
        q("INSERT INTO favorites (user_id, product_id) VALUES (?,?)", [$u['id'], $pid]);
        q("UPDATE products SET favorites_count = favorites_count + 1 WHERE id = ?", [$pid]);
        $p = row("SELECT business_id, category_id FROM products WHERE id = ?", [$pid]);
        event_record('favorite', [
            'user_id' => $u['id'],
            'listing_type' => 'product',
            'listing_id' => $pid,
            'business_id' => $p['business_id'] ?? null,
            'category_id' => $p['category_id'] ?? null,
            'source' => traffic_source_for_listing('product', $pid),
        ]);
        flash('Saved! Find it under My Account.');
    }
}
$ref = $_SERVER['HTTP_REFERER'] ?? '';
redirect($ref ? ltrim(substr(parse_url($ref, PHP_URL_PATH), strlen(BASE_URL)), '/') : '');
