<?php
/**
 * Curated multilingual marketplace search.
 *
 * Rows sharing either side form a concept. For example, `bed -> አልጋ` and
 * `alga -> አልጋ` make all three spellings equivalent.
 */

function search_normalize(string $value): string {
    $value = trim($value);
    if ($value === '') return '';
    if (class_exists('Normalizer')) {
        $normalized = Normalizer::normalize($value, Normalizer::FORM_C);
        if ($normalized !== false) $value = $normalized;
    }
    $value = mb_strtolower($value, 'UTF-8');
    $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? $value;
    return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
}

function search_synonym_rows(): array {
    static $pairs;
    if ($pairs === null) {
        $pairs = rows("SELECT latin_term, amharic_term FROM search_synonyms");
    }
    return $pairs;
}

/** Pure expansion helper, separated from the database lookup for testing. */
function search_expand_terms_from_pairs(string $query, array $pairs): array {
    $query = trim($query);
    if ($query === '') return [];

    $aliases = [];
    $edges = [];
    foreach ($pairs as $pair) {
        $left = search_normalize((string)($pair['latin_term'] ?? ''));
        $right = search_normalize((string)($pair['amharic_term'] ?? ''));
        if ($left === '' || $right === '') continue;
        $aliases[$left] = trim((string)$pair['latin_term']);
        $aliases[$right] = trim((string)$pair['amharic_term']);
        $edges[$left][$right] = true;
        $edges[$right][$left] = true;
    }

    // Resolve transitive groups: bed <-> አልጋ <-> alga.
    $components = [];
    $seen = [];
    foreach (array_keys($aliases) as $start) {
        if (isset($seen[$start])) continue;
        $stack = [$start];
        $component = [];
        while ($stack) {
            $node = array_pop($stack);
            if (isset($seen[$node])) continue;
            $seen[$node] = true;
            $component[] = $node;
            foreach (array_keys($edges[$node] ?? []) as $next) {
                if (!isset($seen[$next])) $stack[] = $next;
            }
        }
        foreach ($component as $node) $components[$node] = $component;
    }

    $terms = [$query];
    $normalizedQuery = search_normalize($query);
    if ($normalizedQuery !== '' && $normalizedQuery !== $query) $terms[] = $normalizedQuery;

    // Longer aliases go first so a phrase such as "bet eqa" remains a unit.
    $needles = array_keys($aliases);
    usort($needles, fn(string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
    foreach ($needles as $needle) {
        $pattern = '/(?<![\p{L}\p{N}])' . preg_quote($needle, '/') . '(?![\p{L}\p{N}])/iu';
        if (!preg_match($pattern, $normalizedQuery)) continue;
        foreach ($components[$needle] ?? [$needle] as $alternative) {
            $display = $aliases[$alternative] ?? $alternative;
            $terms[] = $display;
            $replaced = preg_replace($pattern, $display, $normalizedQuery);
            if (is_string($replaced) && trim($replaced) !== '') $terms[] = trim($replaced);
        }
    }

    $unique = [];
    foreach ($terms as $term) {
        $key = search_normalize($term);
        if ($key !== '' && !isset($unique[$key])) $unique[$key] = trim($term);
    }
    return array_values($unique);
}

function search_expand_terms(string $q): array {
    return search_expand_terms_from_pairs($q, search_synonym_rows());
}

function search_like_clause(string $col, array $terms): array {
    if (!$terms) return ['0', []];
    $parts = [];
    $params = [];
    foreach ($terms as $term) {
        $parts[] = "$col LIKE ?";
        $params[] = '%' . $term . '%';
    }
    return ['(' . implode(' OR ', $parts) . ')', $params];
}
