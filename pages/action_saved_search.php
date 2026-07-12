<?php
/** Save/delete listing browse searches for alert notifications. */
$u = require_login();
csrf_check();

if (!db_table_exists('saved_searches')) {
    flash('Saved searches are not installed yet. Run the latest database upgrade first.', 'error');
    redirect($_POST['return_to'] ?? 'account');
}

$do = $_POST['do'] ?? 'save';
$returnTo = trim((string)($_POST['return_to'] ?? 'account/saved-searches'));

if ($do === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    q("DELETE FROM saved_searches WHERE id = ? AND user_id = ?", [$id, $u['id']]);
    flash('Saved search removed.');
    redirect($returnTo ?: 'account/saved-searches');
}

if ($do === 'toggle') {
    $id = (int)($_POST['id'] ?? 0);
    $enabled = !empty($_POST['alerts_enabled']) ? 1 : 0;
    q("UPDATE saved_searches SET alerts_enabled = ? WHERE id = ? AND user_id = ?", [$enabled, $id, $u['id']]);
    flash($enabled ? 'Saved search alerts enabled.' : 'Saved search alerts paused.');
    redirect($returnTo ?: 'account/saved-searches');
}

$type = $_POST['listing_type'] ?? '';
if (!isset(LISTING_TABLES[$type])) {
    flash('Invalid saved search type.', 'error');
    redirect($returnTo ?: 'account');
}

$query = [];
parse_str((string)($_POST['query_string'] ?? ''), $query);
$queryString = saved_search_normalize_query($query);
$hash = saved_search_hash($type, $queryString);
$label = saved_search_label($type, $query);

q("INSERT INTO saved_searches (user_id, listing_type, label, query_string, query_hash, alerts_enabled, last_checked_at)
   VALUES (?,?,?,?,?,1,NOW())
   ON DUPLICATE KEY UPDATE label = VALUES(label), query_string = VALUES(query_string), alerts_enabled = 1, updated_at = NOW()",
  [$u['id'], $type, $label, $queryString, $hash]);

flash('Saved search alert enabled. We will notify you when new matching listings appear.');
redirect($returnTo ?: saved_search_type_path($type));
