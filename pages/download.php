<?php
/** Authorized download endpoint for verification documents and payment proofs (§ cross-cutting
 * security checklist: private files must never be directly, publicly reachable). Files live
 * under PROTECTED_UPLOAD_DIR, blocked from direct web access by .htaccess — this is the only
 * way to reach them. 404s (not 403) on any authorization failure, matching this codebase's
 * existing ownership-check convention, so an unauthorized caller can't distinguish "not yours"
 * from "doesn't exist". Expects $kind ('verification'|'payment') and $id.
 */
$u = require_login();

if ($kind === 'verification') {
    $doc = row("SELECT vd.file_url, b.user_id FROM verification_documents vd
                JOIN verification_requests vr ON vr.id = vd.request_id
                JOIN businesses b ON b.id = vr.business_id
                WHERE vd.id = ?", [$id]);
    $authorized = $doc && ($doc['user_id'] == $u['id'] || is_admin($u));
} elseif ($kind === 'payment') {
    $doc = row("SELECT p.proof_image AS file_url, p.payer_id, b.user_id AS business_owner_id
                FROM payments p LEFT JOIN businesses b ON b.id = p.business_id
                WHERE p.id = ?", [$id]);
    $authorized = $doc && ($doc['payer_id'] == $u['id'] || $doc['business_owner_id'] == $u['id'] || is_admin($u));
} else {
    $doc = null;
    $authorized = false;
}

if (!$doc || !$authorized || !$doc['file_url']) { require __DIR__ . '/404.php'; return; }

$abs = PROTECTED_UPLOAD_DIR . '/' . $doc['file_url'];
$real = realpath($abs);
if ($real === false || !str_starts_with($real, realpath(PROTECTED_UPLOAD_DIR)) || !is_file($real)) {
    require __DIR__ . '/404.php'; return;
}

$mime = match (strtolower(pathinfo($real, PATHINFO_EXTENSION))) {
    'jpg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif',
    default => 'application/octet-stream',
};
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($real));
header('Content-Disposition: inline; filename="' . basename($real) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($real);
exit;
