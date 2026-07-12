<?php
/**
 * Transliteration-aware search (PLAN.md → Marketplace fundamentals → Localization).
 *
 * A full character-level Ge'ez transliteration engine was considered and deliberately
 * rejected: hand-authoring ~250 Unicode syllable mappings with no automated way to verify
 * correctness in this environment risks silently wrong search results, which is worse than
 * not having the feature. Instead: a curated, admin-extensible Latin<->Amharic dictionary of
 * real marketplace vocabulary (furniture items, materials, colors, rooms) — verifiable,
 * directly useful, and grows from actual customer search terms over time rather than trying
 * to solve general linguistics upfront.
 *
 * Matching is whole-query-string, not per-token: "wenber" expands, "wenber corner sofa" does
 * not. Multi-word tokenized expansion is a reasonable follow-up once real query logs exist to
 * show it's needed.
 */

/** Expand a trimmed search query into itself plus any known Latin<->Amharic synonym, so a
 * query typed in either script also matches listings written in the other. */
function search_expand_terms(string $q): array {
    $q = trim($q);
    if ($q === '') return [];
    $terms = [$q];
    foreach (rows("SELECT latin_term, amharic_term FROM search_synonyms
        WHERE LOWER(latin_term) = LOWER(?) OR amharic_term = ?", [$q, $q]) as $m) {
        $terms[] = $m['latin_term'];
        $terms[] = $m['amharic_term'];
    }
    return array_values(array_unique($terms));
}

/** Build an OR'd LIKE clause across every expanded term for one column.
 * Returns [sql_fragment, params]. */
function search_like_clause(string $col, array $terms): array {
    if (!$terms) return ['0', []];
    $parts = [];
    $params = [];
    foreach ($terms as $t) { $parts[] = "$col LIKE ?"; $params[] = "%$t%"; }
    return ['(' . implode(' OR ', $parts) . ')', $params];
}
