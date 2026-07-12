<?php
/**
 * Dynamic per-category listing attributes (PLAN.md → Marketplace fundamentals →
 * Dynamic category attributes). Definitions live in category_attributes; values are
 * stored as a JSON object on each listing row's `attributes` column, keyed by
 * key_name. Shared by the vendor posting form, browse filters, and (via the same
 * decode_attributes()) the detail-page spec table / Product JSON-LD.
 */

/** Attribute definitions for a category, in display order. */
function category_attributes(int $categoryId): array {
    return rows("SELECT * FROM category_attributes WHERE category_id = ? ORDER BY sort_order, id", [$categoryId]);
}

/** Attribute definitions for every category of a given listing type, indexed by category_id.
 * Used by browse pages that need filter controls across all categories in one query. */
function category_attributes_by_type(string $listingType): array {
    $rowsList = rows("SELECT ca.* FROM category_attributes ca JOIN categories c ON c.id = ca.category_id
        WHERE c.type = ? AND c.status = 'active' ORDER BY ca.sort_order, ca.id", [$listingType]);
    $byCategory = [];
    foreach ($rowsList as $r) $byCategory[(int)$r['category_id']][] = $r;
    return $byCategory;
}

function decode_attributes(?string $json): array {
    if (!$json) return [];
    $data = json_decode($json, true);
    return is_array($data) ? $data : [];
}

function encode_attributes(array $values): ?string {
    return $values ? json_encode($values, JSON_UNESCAPED_UNICODE) : null;
}

/** Read attribute values out of $_POST for the given definitions, validating required
 * fields and select options. Returns [values, errors] — values is ready for encode_attributes(). */
function collect_attribute_input(array $defs, array $post): array {
    $values = [];
    $errors = [];
    foreach ($defs as $def) {
        $key = $def['key_name'];
        $raw = $post['attr'][$key] ?? null;
        if ($def['input_type'] === 'boolean') {
            $values[$key] = isset($post['attr'][$key]) ? true : false;
            continue;
        }
        $raw = is_string($raw) ? trim($raw) : $raw;
        if ($raw === null || $raw === '') {
            if ($def['is_required']) $errors[] = $def['label'] . ' is required.';
            continue;
        }
        if ($def['input_type'] === 'number') {
            if (!is_numeric($raw)) { $errors[] = $def['label'] . ' must be a number.'; continue; }
            $values[$key] = (float)$raw;
        } elseif ($def['input_type'] === 'select') {
            $options = json_decode((string)$def['options'], true) ?: [];
            if (!in_array($raw, $options, true)) { $errors[] = 'Invalid value for ' . $def['label'] . '.'; continue; }
            $values[$key] = $raw;
        } else {
            $values[$key] = $raw;
        }
    }
    return [$values, $errors];
}

/** Render a stored attribute value for display, with its unit if any. */
function attribute_value_display(array $def, $value): string {
    if ($def['input_type'] === 'boolean') return $value ? 'Yes' : 'No';
    $unit = $def['unit'] ? ' ' . $def['unit'] : '';
    return e((string)$value) . e($unit);
}
