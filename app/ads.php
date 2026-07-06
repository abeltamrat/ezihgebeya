<?php
/**
 * Ad engine (§9): targeting-aware selection, weighted rotation, impression/click
 * tracking with budget accounting and basic click-fraud protection.
 */

/**
 * Pick the best campaign for a slot. Context keys (all optional): market_type,
 * category_id, city, subcity — city/subcity default to the visitor's detected location.
 * Targeted fields must match the context (or be unset = run everywhere); more
 * specific matches score higher, and the winner is a weighted random pick
 * (score × priority) so inventory rotates.
 */
function ad_pick(string $placement, array $ctx = []): ?array {
    $loc = user_location();
    $ctx += ['market_type' => null, 'category_id' => null, 'city' => $loc['city'], 'subcity' => $loc['subcity']];

    $candidates = rows(
        "SELECT * FROM ads WHERE status = 'active'
           AND (placement = 'any' OR placement = ?)
           AND (starts_at IS NULL OR starts_at <= NOW())
           AND (ends_at IS NULL OR ends_at >= NOW())
           AND (budget <= 0 OR spent < budget)
           AND (market_type = 'any' OR market_type <=> ?)
           AND (category_id IS NULL OR category_id <=> ?)
           AND (city IS NULL OR city <=> ?)
           AND (subcity IS NULL OR subcity <=> ?)",
        [$placement, $ctx['market_type'], $ctx['category_id'] ?: null, $ctx['city'], $ctx['subcity']]
    );
    if (!$candidates) return null;

    $weights = [];
    foreach ($candidates as $i => $ad) {
        $score = 1;
        if ($ad['placement'] !== 'any') $score += 2;
        if ($ad['market_type'] !== 'any') $score += 2;
        if ($ad['category_id']) $score += 2;
        if ($ad['city']) $score += 3;
        if ($ad['subcity']) $score += 4;
        $weights[$i] = $score * max(1, min(5, (int)$ad['priority']));
    }
    $ticket = mt_rand(1, array_sum($weights));
    foreach ($weights as $i => $w) {
        if (($ticket -= $w) <= 0) return $candidates[$i];
    }
    return $candidates[array_key_first($candidates)];
}

/** Record an impression: counters, CPM spend, event log, auto-complete on budget exhaustion. */
function ad_track_impression(array $ad, string $placement): void {
    $spend = $ad['pricing_type'] === 'cpm' ? (float)$ad['unit_price'] / 1000 : 0;
    q("UPDATE ads SET impressions_count = impressions_count + 1, spent = spent + ? WHERE id = ?", [$spend, $ad['id']]);
    q("INSERT INTO ad_events (ad_id, event_type, placement, city, session_id, ip) VALUES (?, 'impression', ?, ?, ?, ?)",
      [$ad['id'], $placement, user_location()['city'], session_id(), $_SERVER['REMOTE_ADDR'] ?? null]);
    if ($ad['budget'] > 0 && $ad['spent'] + $spend >= $ad['budget']) {
        q("UPDATE ads SET status = 'completed' WHERE id = ? AND budget > 0 AND spent >= budget", [$ad['id']]);
    }
}

function ad_click_url(array $ad): string { return url('ads/go/' . $ad['id']); }

/**
 * Render a slot. Returns '' when nothing to show; banner-style slots fall back to a
 * house ad ("Advertise here") that turns unsold inventory into ad-sales leads.
 */
function ad_slot(string $placement, array $ctx = []): string {
    $ad = ad_pick($placement, $ctx);
    if (!$ad) {
        return in_array($placement, ['home_hero', 'home_inline', 'browse_top'], true)
            ? '<div class="ad-house"><span class="ad-label">Ad space</span>'
              . '<strong>📣 Advertise here — reach buyers in ' . e($ctx['city'] ?? user_location()['city']) . '</strong>'
              . '<a class="btn btn-outline btn-sm" href="' . e(AD_SALES_CONTACT) . '">Contact sales</a></div>'
            : '';
    }
    ad_track_impression($ad, $placement);
    $href = e(ad_click_url($ad));
    $img = $ad['image'] ? e(img_url($ad['image'])) : null;
    $title = e($ad['title'] ?: $ad['advertiser_name']);
    $body = e($ad['body'] ?? '');

    if ($placement === 'browse_inline') { // native card that sits inside the listing grid
        return '<a class="card ad-native" href="' . $href . '" rel="sponsored">'
            . '<div class="card-img">' . ($img ? '<img src="' . $img . '" alt="' . $title . '" loading="lazy">' : '<div class="card-placeholder">📣</div>')
            . '<span class="badge ad-label">Sponsored</span></div>'
            . '<div class="card-body"><div class="card-cat">' . e($ad['advertiser_name']) . '</div>'
            . '<h3 class="card-title">' . $title . '</h3>'
            . ($body ? '<div class="card-meta">' . $body . '</div>' : '')
            . '<div class="card-vendor">Learn more →</div></div></a>';
    }
    if ($placement === 'detail_sidebar') {
        return '<a class="panel ad-side" href="' . $href . '" rel="sponsored"><span class="ad-label">Sponsored</span>'
            . ($img ? '<img src="' . $img . '" alt="' . $title . '">' : '')
            . '<strong>' . $title . '</strong>' . ($body ? '<p class="muted small">' . $body . '</p>' : '') . '</a>';
    }
    if ($placement === 'video_slide') {
        return '<section class="tiktok-slide ad-video-slide"><a href="' . $href . '" rel="sponsored">'
            . ($img ? '<img src="' . $img . '" alt="' . $title . '">' : '')
            . '<div class="tiktok-scrim"></div>'
            . '<div class="tiktok-info"><span class="ad-label">Sponsored</span>'
            . '<h3 class="video-title">' . $title . '</h3>' . ($body ? '<p>' . $body . '</p>' : '')
            . '<span class="btn btn-primary">Learn more</span></div></a></section>';
    }
    // banner slots: home_hero, home_inline, browse_top
    return '<a class="ad-banner" href="' . $href . '" rel="sponsored"><span class="ad-label">Sponsored</span>'
        . ($img ? '<img src="' . $img . '" alt="' . $title . '">'
                : '<div class="ad-banner-text"><strong>' . $title . '</strong>' . ($body ? '<span>' . $body . '</span>' : '') . '</div>')
        . '</a>';
}
