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
           AND (subcity IS NULL OR subcity <=> ?)
         ORDER BY id ASC",
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
    if (empty(ad_rotation_settings()[$placement])) {
        $savedOrder = ad_priority_orders()[$placement] ?? [];
        $rank = array_flip($savedOrder);
        $indexes = array_keys($candidates);
        usort($indexes, static function (int $left, int $right) use ($candidates, $weights, $rank): int {
            $leftRank = $rank[(int)$candidates[$left]['id']] ?? PHP_INT_MAX;
            $rightRank = $rank[(int)$candidates[$right]['id']] ?? PHP_INT_MAX;
            if ($leftRank !== $rightRank) return $leftRank <=> $rightRank;
            if ($weights[$left] !== $weights[$right]) return $weights[$right] <=> $weights[$left];
            return (int)$candidates[$left]['id'] <=> (int)$candidates[$right]['id'];
        });
        return $candidates[$indexes[0]];
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
    $tracked = q("UPDATE ads SET impressions_count = impressions_count + 1,
            spent = CASE WHEN budget > 0 THEN LEAST(budget, spent + ?) ELSE spent + ? END,
            status = CASE WHEN budget > 0 AND spent + ? >= budget THEN 'completed' ELSE status END
        WHERE id = ? AND status = 'active' AND (budget <= 0 OR spent < budget)",
        [$spend, $spend, $spend, $ad['id']])->rowCount();
    if ($tracked !== 1) return;
    q("INSERT INTO ad_events (ad_id, event_type, placement, city, session_id, ip) VALUES (?, 'impression', ?, ?, ?, ?)",
      [$ad['id'], $placement, user_location()['city'], session_id(), $_SERVER['REMOTE_ADDR'] ?? null]);
    event_record('ad_impression', [
        'listing_type' => 'ad',
        'listing_id' => (int)$ad['id'],
        'source' => 'ad',
        'city' => user_location()['city'],
        'metadata' => ['placement' => $placement, 'advertiser' => $ad['advertiser_name'] ?? null],
    ]);
}

function ad_click_url(array $ad): string { return url('ads/go/' . $ad['id']); }

/** True when two campaigns can reach at least one identical visitor/slot during overlapping dates. */
function ad_campaigns_overlap(array $a, array $b): bool {
    $dimensionOverlaps = static fn($left, $right, $broad): bool =>
        $left === $broad || $right === $broad || $left === null || $right === null || (string)$left === (string)$right;
    if (!$dimensionOverlaps($a['placement'] ?? 'any', $b['placement'] ?? 'any', 'any')) return false;
    if (!$dimensionOverlaps($a['market_type'] ?? 'any', $b['market_type'] ?? 'any', 'any')) return false;
    if (!$dimensionOverlaps($a['category_id'] ?? null, $b['category_id'] ?? null, null)) return false;
    if (!$dimensionOverlaps($a['city'] ?? null, $b['city'] ?? null, null)) return false;
    if (!$dimensionOverlaps($a['subcity'] ?? null, $b['subcity'] ?? null, null)) return false;

    $aStart = !empty($a['starts_at']) ? strtotime($a['starts_at']) : PHP_INT_MIN;
    $aEnd = !empty($a['ends_at']) ? strtotime($a['ends_at']) : PHP_INT_MAX;
    $bStart = !empty($b['starts_at']) ? strtotime($b['starts_at']) : PHP_INT_MIN;
    $bEnd = !empty($b['ends_at']) ? strtotime($b['ends_at']) : PHP_INT_MAX;
    return $aStart <= $bEnd && $bStart <= $aEnd;
}

function ad_rotation_settings(): array {
    $saved = site_setting_get('ad_rotation_by_placement', []);
    $out = [];
    foreach (array_keys(AD_PLACEMENTS) as $placement) $out[$placement] = !empty($saved[$placement]);
    return $out;
}

/** Saved top-to-bottom serving order for each placement when rotation is disabled. */
function ad_priority_orders(): array {
    $saved = site_setting_get('ad_priority_order_by_placement', []);
    $out = [];
    foreach (array_keys(AD_PLACEMENTS) as $placement) {
        $ids = array_map('intval', is_array($saved[$placement] ?? null) ? $saved[$placement] : []);
        $out[$placement] = array_values(array_unique(array_filter($ids, static fn(int $id): bool => $id > 0)));
    }
    return $out;
}

function ad_inline_frequency_settings(): array {
    $saved = site_setting_get('ad_inline_frequency', []);
    return [
        'max_per_page' => max(0, min(10, (int)($saved['max_per_page'] ?? 1))),
        'listings_between' => max(2, min(30, (int)($saved['listings_between'] ?? 3))),
    ];
}

function ad_shared_placements(array $a, array $b): array {
    $all = array_keys(AD_PLACEMENTS);
    $aPlacements = ($a['placement'] ?? 'any') === 'any' ? $all : [(string)$a['placement']];
    $bPlacements = ($b['placement'] ?? 'any') === 'any' ? $all : [(string)$b['placement']];
    return array_values(array_intersect($aPlacements, $bPlacements));
}

function ad_campaign_is_duplicate(array $a, array $b): bool {
    return trim((string)($a['destination_url'] ?? '')) !== ''
        && trim((string)($a['destination_url'] ?? '')) === trim((string)($b['destination_url'] ?? ''))
        && trim((string)($a['title'] ?? '')) === trim((string)($b['title'] ?? ''))
        && trim((string)($a['body'] ?? '')) === trim((string)($b['body'] ?? ''))
        && (string)($a['placement'] ?? 'any') === (string)($b['placement'] ?? 'any')
        && (string)($a['market_type'] ?? 'any') === (string)($b['market_type'] ?? 'any')
        && (string)($a['category_id'] ?? '') === (string)($b['category_id'] ?? '')
        && (string)($a['city'] ?? '') === (string)($b['city'] ?? '')
        && (string)($a['subcity'] ?? '') === (string)($b['subcity'] ?? '');
}

/** Active/scheduled campaigns that collide with the proposed campaign's inventory. */
function ad_campaign_conflicts(array $candidate, int $excludeId = 0): array {
    $rotation = ad_rotation_settings();
    $active = rows("SELECT * FROM ads WHERE status = 'active' AND id != ?", [$excludeId]);
    return array_values(array_filter($active, static function (array $other) use ($candidate, $rotation): bool {
        if (!ad_campaigns_overlap($candidate, $other)) return false;
        // Rotation never permits an accidental clone of the same creative and target.
        if (ad_campaign_is_duplicate($candidate, $other)) return true;
        foreach (ad_shared_placements($candidate, $other) as $placement) {
            if (empty($rotation[$placement])) return true;
        }
        return false;
    }));
}

/** Render without tracking. Used by the super-admin draft preview. */
function ad_preview_html(array $ad, string $placement): string {
    $img = $ad['image'] ? e(img_url($ad['image'])) : null;
    $title = e($ad['title'] ?: $ad['advertiser_name']);
    $body = e($ad['body'] ?? '');
    if ($placement === 'browse_inline') {
        return '<div class="card ad-native"><div class="card-img">' . ($img ? '<img src="' . $img . '" alt="' . $title . '">' : '<div class="card-placeholder">📣</div>')
            . '<span class="badge ad-label">Sponsored</span></div><div class="card-body"><div class="card-cat">' . e($ad['advertiser_name']) . '</div>'
            . '<h3 class="card-title">' . $title . '</h3>' . ($body ? '<div class="card-meta">' . $body . '</div>' : '')
            . '<div class="card-vendor">Learn more →</div></div></div>';
    }
    if ($placement === 'detail_sidebar') {
        return '<div class="panel ad-side"><span class="ad-label">Sponsored</span>' . ($img ? '<img src="' . $img . '" alt="' . $title . '">' : '')
            . '<strong>' . $title . '</strong>' . ($body ? '<p class="muted small">' . $body . '</p>' : '') . '</div>';
    }
    if ($placement === 'video_slide') {
        return '<section class="tiktok-slide ad-video-slide">' . ($img ? '<img src="' . $img . '" alt="' . $title . '">' : '')
            . '<div class="tiktok-scrim"></div><div class="tiktok-info"><span class="ad-label">Sponsored</span><h3 class="video-title">' . $title . '</h3>'
            . ($body ? '<p>' . $body . '</p>' : '') . '<span class="btn btn-primary">Learn more</span></div></section>';
    }
    return '<div class="ad-banner"><span class="ad-label">Sponsored</span>'
        . ($img ? '<img src="' . $img . '" alt="' . $title . '">' : '<div class="ad-banner-text"><strong>' . $title . '</strong>' . ($body ? '<span>' . $body . '</span>' : '') . '</div>')
        . '</div>';
}

/**
 * Render a slot. Returns '' when nothing to show; banner-style slots fall back to a
 * house ad ("Advertise here") that turns unsold inventory into ad-sales leads.
 */
function ad_slot(string $placement, array $ctx = []): string {
    if (!feature_enabled('ads')) return ''; // admin → Settings → Features
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
