<?php
/** Saved searches: listing-browse alerts checked by cron. */

function saved_search_type_path(string $type): string {
    return ['product' => 'products', 'service' => 'services', 'supply' => 'supplies'][$type] ?? 'products';
}

function saved_search_normalize_query(array $query): string {
    unset($query['page'], $query['partial'], $query['saved']);
    ksort($query);
    return http_build_query($query);
}

function saved_search_hash(string $type, string $queryString): string {
    return hash('sha256', $type . "\n" . $queryString);
}

function saved_search_label(string $type, array $query): string {
    $parts = [];
    if (trim((string)($query['q'] ?? '')) !== '') $parts[] = '"' . trim((string)$query['q']) . '"';
    if (trim((string)($query['category'] ?? '')) !== '') {
        $name = val("SELECT name FROM categories WHERE slug = ? AND type = ?", [$query['category'], $type]);
        $parts[] = $name ?: str_replace('-', ' ', (string)$query['category']);
    }
    if (trim((string)($query['subcity'] ?? '')) !== '') $parts[] = $query['subcity'];
    elseif (trim((string)($query['city'] ?? '')) !== '') $parts[] = $query['city'];
    if (!$parts) $parts[] = ['product' => 'All products', 'service' => 'All services', 'supply' => 'All supplies'][$type] ?? 'Listings';
    return mb_substr(implode(' · ', $parts), 0, 180);
}

function saved_search_url(array $saved): string {
    $qs = trim((string)$saved['query_string']);
    return saved_search_type_path($saved['listing_type']) . ($qs !== '' ? '?' . $qs : '');
}

function saved_search_build_match(string $type, string $queryString, ?string $since = null): array {
    $query = [];
    parse_str($queryString, $query);
    $table = LISTING_TABLES[$type] ?? 'products';
    $titleCol = listing_title_col($type);
    $priceCol = ['product' => 'l.price', 'service' => 'l.starting_price', 'supply' => 'l.price_per_unit'][$type];
    $where = ["l.status = 'active'", "b.status = 'active'"];
    $params = [];

    $qStr = trim((string)($query['q'] ?? ''));
    if ($qStr !== '') {
        $terms = search_expand_terms($qStr);
        $matchString = implode(' ', $terms);
        [$likeTitle, $likeTitleParams] = search_like_clause("l.`$titleCol`", $terms);
        [$likeDesc, $likeDescParams] = search_like_clause('l.description', $terms);
        [$likeCategory, $likeCategoryParams] = search_like_clause('c.name', $terms);
        $where[] = "(MATCH(l.`$titleCol`, l.description) AGAINST (?) OR $likeTitle OR $likeDesc OR $likeCategory)";
        array_push($params, $matchString, ...$likeTitleParams, ...$likeDescParams, ...$likeCategoryParams);
    }
    $catSlug = trim((string)($query['category'] ?? ''));
    if ($catSlug !== '') { $where[] = "c.slug = ?"; $params[] = $catSlug; }
    $catId = $catSlug !== '' ? (int)val("SELECT id FROM categories WHERE slug = ? AND type = ?", [$catSlug, $type]) : 0;
    $attrFilterIn = is_array($query['attr'] ?? null) ? $query['attr'] : [];
    if ($catId && function_exists('category_attributes')) {
        foreach (array_values(array_filter(category_attributes($catId), fn($a) => (bool)$a['is_filterable'])) as $a) {
            $key = $a['key_name'];
            $path = '$.' . $key;
            $extract = "JSON_UNQUOTE(JSON_EXTRACT(l.attributes, " . db()->quote($path) . "))";
            if ($a['input_type'] === 'boolean') {
                if (!empty($attrFilterIn[$key])) $where[] = "$extract = 'true'";
            } elseif ($a['input_type'] === 'number') {
                $minV = $attrFilterIn[$key]['min'] ?? '';
                $maxV = $attrFilterIn[$key]['max'] ?? '';
                if ($minV !== '' && is_numeric($minV)) { $where[] = "CAST($extract AS DECIMAL(14,2)) >= ?"; $params[] = (float)$minV; }
                if ($maxV !== '' && is_numeric($maxV)) { $where[] = "CAST($extract AS DECIMAL(14,2)) <= ?"; $params[] = (float)$maxV; }
            } else {
                $val = trim((string)($attrFilterIn[$key] ?? ''));
                if ($val !== '') $where[] = $a['input_type'] === 'select' ? "$extract = ?" : "$extract LIKE ?";
                if ($val !== '') $params[] = $a['input_type'] === 'select' ? $val : "%$val%";
            }
        }
    }
    if (trim((string)($query['city'] ?? '')) !== '') { $where[] = "l.city = ?"; $params[] = trim((string)$query['city']); }
    if (trim((string)($query['subcity'] ?? '')) !== '') { $where[] = "l.subcity = ?"; $params[] = trim((string)$query['subcity']); }
    if (!empty($query['verified'])) $where[] = "b.verification_status != 'unverified'";
    if (!empty($query['delivery']) && $type !== 'service') $where[] = "l.delivery_available = 1";
    if (!empty($query['in_stock']) && $type !== 'service') $where[] = "l.stock_quantity > 0";
    if (!empty($query['discount'])) $where[] = $type === 'product' ? "l.discount_price > 0" : ($type === 'supply' ? "l.bulk_price > 0" : "0 = 1");
    if ((float)($query['min_price'] ?? 0) > 0) { $where[] = "$priceCol >= ?"; $params[] = (float)$query['min_price']; }
    if ((float)($query['max_price'] ?? 0) > 0) { $where[] = "$priceCol <= ?"; $params[] = (float)$query['max_price']; }
    if ($type === 'product') {
        if (in_array($query['condition'] ?? '', ['new', 'used', 'refurbished'], true)) { $where[] = "l.condition_type = ?"; $params[] = $query['condition']; }
        if (isset(PRODUCT_TYPES[$query['product_type'] ?? ''])) { $where[] = "l.product_type = ?"; $params[] = $query['product_type']; }
        if (trim((string)($query['material'] ?? '')) !== '') { $where[] = "l.material LIKE ?"; $params[] = '%' . trim((string)$query['material']) . '%'; }
        if (trim((string)($query['brand'] ?? '')) !== '') { $where[] = "l.brand LIKE ?"; $params[] = '%' . trim((string)$query['brand']) . '%'; }
        if (trim((string)($query['color'] ?? '')) !== '') { $where[] = "l.color LIKE ?"; $params[] = '%' . trim((string)$query['color']) . '%'; }
        if (!empty($query['installation'])) $where[] = "l.installation_available = 1";
    } elseif ($type === 'service') {
        if (isset(PRICE_TYPES[$query['price_type'] ?? ''])) { $where[] = "l.price_type = ?"; $params[] = $query['price_type']; }
        if ((int)($query['min_experience'] ?? 0) > 0) { $where[] = "l.experience_years >= ?"; $params[] = (int)$query['min_experience']; }
    } elseif ($type === 'supply') {
        if (trim((string)($query['thickness'] ?? '')) !== '') { $where[] = "l.thickness LIKE ?"; $params[] = '%' . trim((string)$query['thickness']) . '%'; }
        if (trim((string)($query['brand'] ?? '')) !== '') { $where[] = "l.brand LIKE ?"; $params[] = '%' . trim((string)$query['brand']) . '%'; }
        if (in_array($query['unit'] ?? '', SUPPLY_UNITS, true)) { $where[] = "l.unit_of_measurement = ?"; $params[] = $query['unit']; }
    }
    if ((float)($query['min_rating'] ?? 0) > 0) { $where[] = "b.rating_average >= ?"; $params[] = (float)$query['min_rating']; }
    if ($since) { $where[] = "l.created_at > ?"; $params[] = $since; }

    return [$table, $titleCol, implode(' AND ', $where), $params];
}

function saved_search_new_matches(array $saved, int $limit = 3): array {
    $since = $saved['last_checked_at'] ?: $saved['created_at'];
    [$table, $titleCol, $whereSql, $params] = saved_search_build_match($saved['listing_type'], $saved['query_string'], $since);
    return rows("SELECT l.id, l.slug, l.`$titleCol` title, l.created_at, b.business_name
        FROM `$table` l JOIN businesses b ON b.id = l.business_id JOIN categories c ON c.id = l.category_id
        WHERE $whereSql ORDER BY l.created_at DESC LIMIT $limit", $params);
}

function saved_searches_run_alerts(array &$log): void {
    if (!db_table_exists('saved_searches')) return;
    $savedRows = rows("SELECT * FROM saved_searches WHERE alerts_enabled = 1 ORDER BY COALESCE(last_checked_at, created_at) ASC LIMIT 300");
    $sent = 0;
    foreach ($savedRows as $s) {
        $matches = saved_search_new_matches($s, 3);
        if ($matches) {
            $count = count($matches);
            $first = $matches[0];
            notify((int)$s['user_id'], 'saved_search_alert',
                $count === 1 ? 'New match for saved search: ' . $s['label'] : "$count new matches for saved search: " . $s['label'],
                saved_search_url($s),
                $first['title'] . ' from ' . $first['business_name'],
                false);
            q("UPDATE saved_searches SET last_checked_at = NOW(), last_notified_at = NOW() WHERE id = ?", [$s['id']]);
            $sent++;
        } else {
            q("UPDATE saved_searches SET last_checked_at = NOW() WHERE id = ?", [$s['id']]);
        }
    }
    $log[] = "saved search alerts sent: $sent";
}
