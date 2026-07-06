<?php
require __DIR__ . '/../config.php'; require __DIR__ . '/../app/db.php'; require __DIR__ . '/../app/helpers.php';
foreach ([1, 3, 4] as $bizId) {
    $agg = row("SELECT AVG(rating) a, COUNT(*) c FROM reviews WHERE business_id = ? AND status = 'approved'", [$bizId]);
    q("UPDATE businesses SET rating_average = ?, rating_count = ? WHERE id = ?", [round((float)$agg['a'], 2), (int)$agg['c'], $bizId]);
}
echo "ratings recomputed\n";
