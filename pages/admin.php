<?php
/** Admin panel. Expects $section: dashboard|businesses|products|services|supplies|videos|reviews|reports|inquiries|users|categories */
$u = require_admin();
$sections = ['dashboard' => '📊 Dashboard', 'businesses' => '🏪 Businesses', 'verification' => '🛡 Verification', 'products' => '🛋️ Products', 'services' => '🛠️ Services',
    'supplies' => '📦 Supplies', 'videos' => '▶ Videos', 'reviews' => '⭐ Reviews', 'reports' => '🚩 Reports',
    'inquiries' => '💬 Inquiries', 'orders' => '🧾 Orders', 'payments' => '💳 Payments', 'promotions' => '📣 Promotions',
    'subscriptions' => '🎫 Subscriptions', 'users' => '👥 Users', 'categories' => '🗂 Categories',
    'search_synonyms' => '🔤 Search Synonyms',
    'locations' => '📍 Locations', 'pages' => '📄 Content Pages', 'analytics' => '📈 Analytics', 'audit' => '🧾 Audit Log'];
$sections['support'] = 'Support';
if ($u['account_type'] === 'super_admin') {
    $sections['software'] = 'Software & Plugins';
    $sections['ads'] = 'Ad Manager';
    $sections['ad-placements'] = 'Advertising Placements';
    $sections['settings'] = '⚙️ System Settings';
    $sections['admins'] = 'Admins & Roles';
    $sections['backups'] = 'Backups';
    $sections['system-ui-optimizer'] = 'System UI Optimizer';
}
if (!isset($sections[$section])) $section = 'dashboard';
$pageTitle = 'Admin · ' . strip_tags($sections[$section]);

// ---------- actions ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = $_POST['do'] ?? '';
    $id = (int)($_POST['id'] ?? 0);

    if ($do === 'biz_status' && in_array($_POST['status'], ['active', 'rejected', 'suspended', 'pending'], true)) {
        q("UPDATE businesses SET status = ? WHERE id = ?", [$_POST['status'], $id]);
        if ($_POST['status'] === 'active') notify_business($id, 'verification_approved', 'Your business was approved — start listing!', 'vendor', '', true);
        if ($_POST['status'] === 'rejected') notify_business($id, 'verification_rejected', 'Your business registration was rejected', 'app/vendor/business');
        flash('Business updated.');
    } elseif ($do === 'biz_verify' && in_array($_POST['verification_status'], ['unverified', 'phone_verified', 'document_verified', 'location_verified', 'premium_verified'], true)) {
        $newLevel = $_POST['verification_status'];
        $prevLevel = (string)val("SELECT verification_status FROM businesses WHERE id = ?", [$id]);
        q("UPDATE businesses SET verification_status = ? WHERE id = ?", [$newLevel, $id]);
        // Close out any pending verification_requests for this business so a later vr_review
        // approval on a stale request can't silently overwrite this hand-set value — the two
        // paths write the same column with no other coordination between them.
        q("UPDATE verification_requests SET status = 'rejected', admin_note = 'Superseded by a manual admin verification-level change.', reviewed_by = ?
           WHERE business_id = ? AND status = 'pending'", [$u['id'], $id]);
        // Same user-facing badge change vr_review notifies for — this is just the direct
        // admin path to the same verification_status column, so it must notify too.
        if ($newLevel !== $prevLevel) {
            if ($newLevel === 'unverified') {
                notify_business($id, 'verification_rejected', 'Your business verification badge was removed', 'vendor/verification');
            } else {
                notify_business($id, 'verification_approved', 'Verification updated — ' . str_replace('_', ' ', $newLevel) . ' badge is now active', 'vendor/verification', '', true);
            }
        }
        flash('Verification level updated.');
    } elseif ($do === 'listing_status' && isset(LISTING_TABLES[$_POST['ltype'] ?? '']) ) {
        $t = LISTING_TABLES[$_POST['ltype']];
        $allowed = ['active', 'rejected', 'paused', 'expired', 'sold_out', 'pending_review', 'deleted'];
        if (in_array($_POST['status'], $allowed, true)) {
            $hasRejectMeta = db_column_exists($t, 'rejection_reason');
            if ($hasRejectMeta && $_POST['status'] === 'rejected') {
                $reason = $_POST['rejection_reason'] ?? 'other';
                if (!array_key_exists($reason, listing_rejection_reasons())) $reason = 'other';
                $note = trim($_POST['rejection_note'] ?? '') ?: listing_rejection_instruction($reason);
                q("UPDATE `$t` SET status = ?, rejection_reason = ?, rejection_note = ? WHERE id = ?", [$_POST['status'], $reason, $note, $id]);
            } elseif ($hasRejectMeta && in_array($_POST['status'], ['active', 'pending_review'], true)) {
                q("UPDATE `$t` SET status = ?, rejection_reason = NULL, rejection_note = NULL WHERE id = ?", [$_POST['status'], $id]);
            } else {
                q("UPDATE `$t` SET status = ? WHERE id = ?", [$_POST['status'], $id]);
            }
            $l = row("SELECT business_id, " . listing_title_col($_POST['ltype']) . " t FROM `$t` WHERE id = ?", [$id]);
            if ($l && $_POST['status'] === 'active') notify_business((int)$l['business_id'], 'listing_approved', '"' . $l['t'] . '" was approved and is now live', 'app/vendor/listings/' . $_POST['ltype']);
            if ($l && $_POST['status'] === 'rejected') notify_business((int)$l['business_id'], 'listing_rejected', '"' . $l['t'] . '" was rejected: ' . listing_rejection_instruction($_POST['rejection_reason'] ?? 'other'), 'vendor/listings/' . $_POST['ltype']);
            flash('Listing ' . $_POST['status'] . '.');
        }
    } elseif ($do === 'listing_feature' && isset(LISTING_TABLES[$_POST['ltype'] ?? ''])) {
        // Routed through the same promotions ledger paid promotions use (promotion_apply/
        // promotion_activate), never a direct column hand-toggle: is_featured must always be
        // derived from a promotions row so cron's expiry job and the paid-promotion system see
        // consistent state, per the Revenue model's entitlement-ledger rule. A hand-toggled flag
        // has no starts_at/ends_at, so it never expires and can silently fight a real paid
        // promotion's flag. Un-featuring stops whatever promotion is actually driving the flag
        // (paid or free) via the same cancel semantics promo_stop already uses elsewhere in this
        // file — an admin acting on this button is asserting "this should not be featured", same
        // authority admins already have via promo_stop, not a payment refund decision.
        $ltype = $_POST['ltype'];
        $t = LISTING_TABLES[$ltype];
        $listing = row("SELECT id, business_id, is_featured FROM `$t` WHERE id = ?", [$id]);
        if ($listing) {
            if ($listing['is_featured']) {
                $promo = row("SELECT * FROM promotions WHERE promotable_type = ? AND promotable_id = ? AND status = 'active'
                              ORDER BY id DESC LIMIT 1", [$ltype, $id]);
                if ($promo) {
                    q("UPDATE promotions SET status = 'cancelled' WHERE id = ?", [$promo['id']]);
                    promotion_apply($promo, false);
                } else {
                    // no ledger row (e.g. pre-existing hand-toggled data) — clear directly as a one-time fallback
                    q("UPDATE `$t` SET is_featured = 0 WHERE id = ?", [$id]);
                }
                flash('Featured removed.');
            } else {
                q("INSERT INTO promotions (business_id, promotable_type, promotable_id, promotion_type, duration_weeks, budget, pricing_type, status)
                   VALUES (?,?,?, 'category_featured', 52, 0, 'fixed_weekly', 'pending')",
                  [$listing['business_id'], $ltype, $id]);
                $promo = row("SELECT * FROM promotions WHERE id = ?", [(int)db()->lastInsertId()]);
                if ($promo) promotion_activate($promo);
                flash('Listing featured.');
            }
        }
    } elseif ($do === 'video_status' && in_array($_POST['status'], ['approved', 'rejected', 'disabled', 'pending', 'deleted'], true)) {
        q("UPDATE video_posts SET status = ? WHERE id = ?", [$_POST['status'], $id]);
        $v = row("SELECT business_id, title FROM video_posts WHERE id = ?", [$id]);
        if ($v && $_POST['status'] === 'approved') notify_business((int)$v['business_id'], 'listing_approved', 'Your video was approved for the feed', 'app/vendor/videos');
        if ($v && $_POST['status'] === 'rejected') notify_business((int)$v['business_id'], 'listing_rejected', 'Your video was rejected by moderation', 'vendor/videos');
        flash('Video ' . $_POST['status'] . '.');
    } elseif ($do === 'review_status' && in_array($_POST['status'], ['approved', 'rejected', 'hidden'], true)) {
        $r = row("SELECT business_id, status FROM reviews WHERE id = ?", [$id]);
        $wasApproved = $r && $r['status'] === 'approved';
        q("UPDATE reviews SET status = ? WHERE id = ?", [$_POST['status'], $id]);
        // recompute on every transition into or out of 'approved' — not just when approving —
        // otherwise hiding/rejecting a previously-approved review leaves the business's cached
        // rating_average/rating_count stale (still counting a review that's no longer approved).
        if ($r && ($_POST['status'] === 'approved' || $wasApproved)) {
            $agg = row("SELECT AVG(rating) a, COUNT(*) c FROM reviews WHERE business_id = ? AND status = 'approved'", [$r['business_id']]);
            q("UPDATE businesses SET rating_average = ?, rating_count = ? WHERE id = ?", [round($agg['a'] ?? 0, 2), $agg['c'] ?? 0, $r['business_id']]);
            if ($_POST['status'] === 'approved') notify_business((int)$r['business_id'], 'review_received', 'You received a new review', 'vendor/reviews');
        }
        flash('Review ' . $_POST['status'] . '.');
    } elseif ($do === 'report_status' && in_array($_POST['status'], ['open', 'reviewing', 'resolved', 'dismissed'], true)) {
        q("UPDATE reports SET status = ?, admin_note = ? WHERE id = ?", [$_POST['status'], trim($_POST['admin_note'] ?? '') ?: null, $id]);
        flash('Report updated.');
    } elseif ($do === 'support_update' && db_table_exists('support_tickets')) {
        $status = $_POST['status'] ?? 'open';
        $priority = $_POST['priority'] ?? 'normal';
        if (in_array($status, ['open', 'waiting_user', 'escalated', 'resolved', 'closed'], true)
            && in_array($priority, ['low', 'normal', 'high'], true)) {
            q("UPDATE support_tickets SET status = ?, priority = ?, assigned_admin_id = ?, admin_note = ? WHERE id = ?",
              [$status, $priority, $u['id'], trim($_POST['admin_note'] ?? '') ?: null, $id]);
            $ticket = row("SELECT user_id, subject FROM support_tickets WHERE id = ?", [$id]);
            if ($ticket && in_array($status, ['waiting_user', 'resolved', 'closed'], true)) {
                notify((int)$ticket['user_id'], 'support_update', 'Support ticket #' . $id . ' was updated: ' . $status, 'support', $ticket['subject'] ?? '');
            }
            flash('Support ticket updated.');
        }
    } elseif ($do === 'support_escalate' && db_table_exists('support_tickets')) {
        $ticket = row("SELECT * FROM support_tickets WHERE id = ?", [$id]);
        $reportedType = $_POST['reported_type'] ?? ($ticket['related_type'] ?? '');
        $reportedId = max(0, (int)($_POST['reported_id'] ?? ($ticket['related_id'] ?? 0)));
        $moderatable = ['product', 'service', 'supply', 'business', 'video', 'review', 'user'];
        if ($ticket && in_array($reportedType, $moderatable, true) && $reportedId > 0) {
            $desc = "Escalated from support ticket #{$ticket['id']}: {$ticket['subject']}\n\n{$ticket['message']}";
            $adminNote = trim($_POST['admin_note'] ?? '');
            if ($adminNote !== '') $desc .= "\n\nSupport note: " . $adminNote;
            q("INSERT INTO reports (reporter_id, reported_type, reported_id, reason, description, status, admin_note)
               VALUES (?,?,?,?,?,'open',?)",
              [$ticket['user_id'], $reportedType, $reportedId, 'support_ticket', $desc, $adminNote ?: null]);
            $reportId = (int)db()->lastInsertId();
            q("UPDATE support_tickets SET status = 'escalated', priority = 'high', assigned_admin_id = ?, admin_note = ?, report_id = ? WHERE id = ?",
              [$u['id'], $adminNote ?: ($ticket['admin_note'] ?? null), $reportId, $id]);
            notify((int)$ticket['user_id'], 'support_update', 'Support ticket #' . $id . ' was escalated to moderation', 'support');
            flash('Ticket escalated to moderation report #' . $reportId . '.');
        } else {
            flash('Choose a moderatable target type and ID before escalating.', 'error');
        }
    } elseif ($do === 'user_status' && in_array($_POST['status'], ['active', 'suspended', 'banned'], true)) {
        if ($id !== (int)$u['id']) {
            q("UPDATE users SET status = ? WHERE id = ? AND account_type != 'super_admin'", [$_POST['status'], $id]);
            if ($_POST['status'] === 'active') {
                lift_account_sanctions($id, (int)$u['id'], trim($_POST['appeal_response'] ?? ''));
            } else {
                remembered_login_revoke_user($id);
                create_account_sanction($id, (int)$u['id'], $_POST['status'], trim($_POST['sanction_reason'] ?? 'policy_violation'), trim($_POST['admin_note'] ?? ''));
                notify($id, 'account_sanction', 'Your account was ' . $_POST['status'] . '. You can appeal this decision.', 'appeal', trim($_POST['admin_note'] ?? ''), true);
            }
            flash('User updated.');
        }
    } elseif ($do === 'sanction_appeal' && db_table_exists('account_sanctions')) {
        $sid = (int)($_POST['sanction_id'] ?? 0);
        $decision = $_POST['appeal_status'] ?? '';
        $response = trim($_POST['appeal_response'] ?? '');
        $s = row("SELECT * FROM account_sanctions WHERE id = ?", [$sid]);
        if ($s && in_array($decision, ['approved', 'rejected'], true)) {
            q("UPDATE account_sanctions SET appeal_status = ?, appeal_response = ?, reviewed_by = ?, reviewed_at = NOW(),
               status = IF(? = 'approved', 'lifted', status), lifted_at = IF(? = 'approved', NOW(), lifted_at) WHERE id = ?",
              [$decision, $response ?: null, $u['id'], $decision, $decision, $sid]);
            if ($decision === 'approved') q("UPDATE users SET status = 'active' WHERE id = ? AND status IN ('suspended','banned')", [$s['user_id']]);
            notify((int)$s['user_id'], 'sanction_appeal_' . $decision, 'Your account appeal was ' . $decision, 'appeal', $response, true);
            flash('Appeal reviewed.');
        }
    } elseif ($do === 'payment_confirm' || $do === 'payment_reject') {
        $p = row("SELECT * FROM payments WHERE id = ? AND status = 'pending'", [$id]);
        if ($p && $do === 'payment_confirm' && $p['order_id']) {
            $confirmableOrder = row("SELECT status FROM orders WHERE id = ?", [$p['order_id']]);
            if (!$confirmableOrder || !in_array($confirmableOrder['status'], ['pending', 'confirmed'], true)) {
                flash('This order is not awaiting payment confirmation.', 'error');
                $p = null;
            }
        }
        // The UPDATE itself (not just the SELECT above) carries the "still pending" guard, and
        // only proceeds with the linked-item activation if this request is the one that actually
        // won the race — two near-simultaneous confirm clicks both passing the SELECT before
        // either commits would otherwise both run the activation path.
        $claimed = $p ? q("UPDATE payments SET status = ? WHERE id = ? AND status = 'pending'",
            [$do === 'payment_confirm' ? 'confirmed' : 'rejected', $id])->rowCount() : 0;
        if ($p && $claimed) {
            q("UPDATE payments SET confirmed_by = ? WHERE id = ?", [$u['id'], $id]);
            if ($do === 'payment_confirm') {
                if ($p['order_id']) {
                    $paymentOrder = row("SELECT * FROM orders WHERE id = ?", [$p['order_id']]);
                    if ($paymentOrder && in_array($paymentOrder['status'], ['pending', 'confirmed'], true)) {
                        transition_order_status($paymentOrder, 'deposit_paid', (int)$u['id'], 'Payment confirmed by administrator');
                    }
                } elseif ($p['promotion_id']) {
                    $promo = row("SELECT * FROM promotions WHERE id = ?", [$p['promotion_id']]);
                    if ($promo && $promo['status'] === 'pending') {
                        if (promotion_activate($promo)) {
                            flash('Payment confirmed and promotion activated.');
                        } else {
                            flash('Payment confirmed, but promotion stayed pending because the target is not approved/live yet.', 'warning');
                        }
                    }
                } elseif ($p['subscription_id']) {
                    $sub = row("SELECT * FROM subscriptions WHERE id = ?", [$p['subscription_id']]);
                    if ($sub && $sub['status'] === 'pending') {
                        // Supersedes any prior active same-type plan and extends renewals from the
                        // current expiry (see activate_subscription()); replaces the old inline UPDATE
                        // that reset ends_at to NOW()+months and left stacked active rows behind.
                        activate_subscription($sub);
                    }
                }
                notify((int)$p['payer_id'], 'payment_received', 'Your payment of ' . money($p['amount']) . ' was confirmed', $p['order_id'] ? 'account/orders' : 'vendor', '', true);
                flash('Payment confirmed and linked item processed.');
            } else {
                if ($p['promotion_id']) q("UPDATE promotions SET status = 'rejected' WHERE id = ? AND status = 'pending'", [$p['promotion_id']]);
                if ($p['subscription_id']) q("UPDATE subscriptions SET status = 'rejected' WHERE id = ? AND status = 'pending'", [$p['subscription_id']]);
                notify((int)$p['payer_id'], 'payment_rejected', 'Your payment of ' . money($p['amount']) . ' was rejected — please review and resubmit', $p['order_id'] ? 'account/orders' : 'vendor', '', true);
                flash('Payment rejected.');
            }
        }
    } elseif ($do === 'promo_stop') {
        $promo = row("SELECT * FROM promotions WHERE id = ?", [$id]);
        if ($promo && in_array($promo['status'], ['active', 'pending', 'scheduled', 'paused'], true)) {
            q("UPDATE promotions SET status = 'cancelled' WHERE id = ?", [$id]);
            if ($promo['status'] === 'active') promotion_apply($promo, false);
            flash('Promotion stopped.');
        }
    } elseif ($do === 'promo_update') {
        $promo = row("SELECT * FROM promotions WHERE id = ?", [$id]);
        $status = $_POST['status'] ?? '';
        if ($promo && in_array($status, ['active', 'scheduled', 'paused', 'cancelled'], true)) {
            if ($promo['status'] === 'active' && $status !== 'active') promotion_apply($promo, false);
            if ($status === 'active') {
                if (promotion_activate($promo)) flash('Promotion activated.');
                else flash('Promotion cannot activate until its target is approved/live.', 'error');
            } elseif ($status === 'scheduled') {
                $starts = trim($_POST['starts_at'] ?? '');
                $starts = $starts ? str_replace('T', ' ', substr($starts, 0, 16)) . ':00' : date('Y-m-d H:i:s', time() + 86400);
                q("UPDATE promotions SET status = 'scheduled', starts_at = ?, ends_at = DATE_ADD(?, INTERVAL duration_weeks WEEK) WHERE id = ?", [$starts, $starts, $id]);
                flash('Promotion scheduled.');
            } elseif ($status === 'paused') {
                q("UPDATE promotions SET status = 'paused' WHERE id = ?", [$id]);
                flash('Promotion paused.');
            } elseif ($status === 'cancelled') {
                q("UPDATE promotions SET status = 'cancelled' WHERE id = ?", [$id]);
                flash('Promotion cancelled.');
            }
        }
    } elseif ($do === 'order_status' && in_array($_POST['status'] ?? '', ['pending','confirmed','deposit_paid','processing','ready_for_delivery','out_for_delivery','delivered','completed','cancelled','refunded','disputed'], true)) {
        $o = row("SELECT * FROM orders WHERE id = ?", [$id]);
        if ($o && transition_order_status($o, $_POST['status'], (int)$u['id'], 'Updated by administrator')) {
            notify((int)$o['customer_id'], 'order_status_changed', 'Order ' . $o['order_number'] . ' is now ' . str_replace('_', ' ', $_POST['status']), 'account/orders');
            flash('Order updated.');
        } else {
            flash('That status change is not allowed.', 'error');
        }
    } elseif (str_starts_with($do, 'ad_') && $u['account_type'] !== 'super_admin') {
        flash('Ad Manager is restricted to the super admin.', 'error');
    } elseif ($do === 'ad_rotation_save') {
        $rotation = [];
        foreach (array_keys(AD_PLACEMENTS) as $placement) {
            $rotation[$placement] = !empty($_POST['rotation'][$placement]) ? 1 : 0;
        }
        site_setting_set('ad_rotation_by_placement', $rotation);
        $inlineDelivery = $_POST['delivery']['browse_inline'] ?? [];
        site_setting_set('ad_inline_frequency', [
            'max_per_page' => max(0, min(10, (int)($inlineDelivery['max_per_page'] ?? 1))),
            'listings_between' => max(2, min(30, (int)($inlineDelivery['listings_between'] ?? 3))),
        ]);
        audit('ad_rotation_settings', 'ads', null, json_encode($rotation));
        flash('Advertising spot rotation settings saved.');
    } elseif ($do === 'ad_rotation_toggle') {
        $placement = (string)($_POST['placement'] ?? '');
        if (!array_key_exists($placement, AD_PLACEMENTS)) {
            flash('Unknown advertising spot.', 'error');
        } else {
            $rotation = ad_rotation_settings();
            $rotation[$placement] = !empty($_POST['rotation']) ? 1 : 0;
            site_setting_set('ad_rotation_by_placement', $rotation);
            audit('ad_rotation_toggle', 'ads', null, $placement . ':' . $rotation[$placement]);
            if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') flash('Placement delivery mode updated.');
        }
    } elseif ($do === 'ad_priority_order') {
        $placement = (string)($_POST['placement'] ?? '');
        if (!array_key_exists($placement, AD_PLACEMENTS)) {
            flash('Unknown advertising spot.', 'error');
        } else {
            $requested = array_values(array_unique(array_filter(array_map(
                'intval',
                explode(',', (string)($_POST['order'] ?? ''))
            ))));
            $valid = [];
            if ($requested) {
                $marks = implode(',', array_fill(0, count($requested), '?'));
                $eligible = rows("SELECT id FROM ads WHERE id IN ($marks) AND status != 'archived' AND (placement = 'any' OR placement = ?)", [...$requested, $placement]);
                $allowed = array_flip(array_map('intval', array_column($eligible, 'id')));
                $valid = array_values(array_filter($requested, static fn(int $id): bool => isset($allowed[$id])));
            }
            $orders = ad_priority_orders();
            $orders[$placement] = $valid;
            site_setting_set('ad_priority_order_by_placement', $orders);
            audit('ad_priority_order', 'ads', null, $placement . ':' . implode(',', $valid));
            if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') flash('Campaign priority order saved.');
        }
    } elseif ($do === 'ad_save') {
        $fields = [
            'advertiser_name' => trim($_POST['advertiser_name'] ?? ''),
            'advertiser_phone' => trim($_POST['advertiser_phone'] ?? '') ?: null,
            'title' => trim($_POST['title'] ?? '') ?: null,
            'body' => trim($_POST['body'] ?? '') ?: null,
            'destination_url' => trim($_POST['destination_url'] ?? ''),
            'placement' => array_key_exists($_POST['placement'] ?? '', AD_PLACEMENTS) ? $_POST['placement'] : 'any',
            'market_type' => in_array($_POST['market_type'] ?? '', ['product', 'service', 'supply'], true) ? $_POST['market_type'] : 'any',
            'category_id' => (int)($_POST['category_id'] ?? 0) ?: null,
            'city' => array_key_exists($_POST['city'] ?? '', CITIES) ? $_POST['city'] : null,
            'subcity' => trim($_POST['subcity'] ?? '') ?: null,
            'pricing_type' => array_key_exists($_POST['pricing_type'] ?? '', AD_PRICING) ? $_POST['pricing_type'] : 'cpc',
            'unit_price' => (float)($_POST['unit_price'] ?? 0),
            'budget' => (float)($_POST['budget'] ?? 0),
            'priority' => max(1, min(5, (int)($_POST['priority'] ?? 1))),
            'starts_at' => ($_POST['starts_at'] ?? '') ?: null,
            'ends_at' => ($_POST['ends_at'] ?? '') ?: null,
        ];
        if ($fields['subcity'] && !in_array($fields['subcity'], CITIES[$fields['city'] ?? ''] ?? [], true)) $fields['subcity'] = null;
        if ($fields['starts_at'] && $fields['ends_at'] && strtotime($fields['ends_at']) <= strtotime($fields['starts_at'])) {
            flash('Campaign end time must be later than its start time.', 'error');
        } elseif ($fields['advertiser_name'] === '' || $fields['destination_url'] === '') {
            flash('Advertiser name and destination URL are required.', 'error');
        } elseif ($id && val("SELECT status FROM ads WHERE id = ?", [$id]) === 'active'
            && ($conflicts = ad_campaign_conflicts($fields, $id))) {
            flash('Campaign was not updated because its targeting and schedule overlap active campaign #' . implode(', #', array_column($conflicts, 'id')) . '.', 'error');
        } else {
            $img = upload_image($_FILES['image'] ?? [], 'ads');
            $cols = array_keys($fields); $vals = array_values($fields);
            if ($id) {
                $set = implode(', ', array_map(fn($c) => "`$c` = ?", $cols));
                if ($img) { $set .= ', image = ?'; $vals[] = $img; }
                q("UPDATE ads SET $set WHERE id = ?", [...$vals, $id]);
                flash('Campaign updated.');
            } else {
                if ($img) { $cols[] = 'image'; $vals[] = $img; }
                q("INSERT INTO ads (`" . implode('`,`', $cols) . "`, status) VALUES (" . rtrim(str_repeat('?,', count($vals)), ',') . ", 'draft')", $vals);
                flash('Campaign created as draft — activate it when payment is arranged.');
            }
        }
    } elseif ($do === 'ad_status' && in_array($_POST['status'] ?? '', ['draft', 'active', 'paused', 'completed', 'archived'], true)) {
        if ($_POST['status'] === 'active') {
            $locked = (int)val("SELECT GET_LOCK('ezihgebeya_ad_activation', 5)");
            try {
                $candidate = $locked ? row("SELECT * FROM ads WHERE id = ?", [$id]) : null;
                $conflicts = $candidate ? ad_campaign_conflicts($candidate, $id) : [];
                if (!$locked) {
                    flash('Another campaign is being activated. Please retry in a moment.', 'error');
                } elseif (!$candidate) {
                    flash('Campaign not found.', 'error');
                } elseif ($conflicts) {
                    flash('Cannot activate: this placement, audience, location, and schedule overlap active campaign #' . implode(', #', array_column($conflicts, 'id')) . '. Pause it or change the targeting/dates first.', 'error');
                } else {
                    q("UPDATE ads SET status = 'active' WHERE id = ?", [$id]);
                    flash('Campaign active.');
                }
            } finally {
                if ($locked) val("SELECT RELEASE_LOCK('ezihgebeya_ad_activation')");
            }
        } else {
            q("UPDATE ads SET status = ? WHERE id = ?", [$_POST['status'], $id]);
            flash('Campaign ' . $_POST['status'] . '.');
        }
    } elseif ($do === 'ad_payment' && (float)($_POST['amount'] ?? 0) > 0) {
        $method = in_array($_POST['payment_method'] ?? '', ['bank_transfer', 'telebirr', 'cbe_birr', 'cash'], true) ? $_POST['payment_method'] : 'cash';
        q("INSERT INTO payments (payer_id, ad_id, payment_type, amount, payment_method, reference_number, status, confirmed_by)
           VALUES (?,?, 'ad_payment', ?,?,?, 'confirmed', ?)",
          [$u['id'], $id, (float)$_POST['amount'], $method, trim($_POST['reference_number'] ?? '') ?: null, $u['id']]);
        flash('Payment of ' . money((float)$_POST['amount']) . ' recorded.');
    } elseif (str_starts_with($do, 'system_ui_') && $u['account_type'] !== 'super_admin') {
        flash('System UI Optimizer is restricted to the super admin.', 'error');
    } elseif ($do === 'system_ui_save') {
        $uiInput = $_POST['ui'] ?? [];
        site_setting_set('system_restrictions', sanitize_system_restrictions($_POST['restrictions'] ?? []));
        $heroUpload = upload_image($_FILES['hero_image_upload'] ?? [], 'ui');
        if ($heroUpload) $uiInput['hero_image'] = img_url($heroUpload);
        site_setting_set('system_ui_optimizer', sanitize_system_ui($uiInput));
        flash('System UI and system restrictions updated.');
    } elseif ($do === 'system_ui_save_template') {
        system_ui_save_template($_POST['template_name'] ?? '', $_POST['ui'] ?? system_ui_config());
    } elseif ($do === 'system_ui_apply_template') {
        $templates = system_ui_templates();
        $key = $_POST['template_key'] ?? '';
        if (isset($templates[$key]['config'])) {
            site_setting_set('system_ui_optimizer', sanitize_system_ui($templates[$key]['config']));
            flash('Template applied.');
        } else {
            flash('Template not found.', 'error');
        }
    } elseif ($do === 'system_ui_delete_template') {
        $templates = system_ui_templates();
        $key = $_POST['template_key'] ?? '';
        if (isset($templates[$key])) {
            unset($templates[$key]);
            site_setting_set('system_ui_templates', $templates);
            flash('Template deleted.');
        }
    } elseif ($do === 'system_ui_reset') {
        site_setting_set('system_ui_optimizer', system_ui_defaults());
        flash('System UI reset to the default design kit.');
    } elseif ($do === 'cat_add' && trim($_POST['name'] ?? '') !== '' && in_array($_POST['type'], ['product', 'service', 'supply'], true)) {
        $n = trim($_POST['name']);
        q("INSERT INTO categories (name, slug, type, icon, sort_order) VALUES (?,?,?,?, 99)", [$n, slugify($n, 'categories'), $_POST['type'], trim($_POST['icon'] ?? '') ?: '🗂']);
        flash('Category added.');
    } elseif ($do === 'cat_toggle') {
        q("UPDATE categories SET status = IF(status='active','inactive','active') WHERE id = ?", [$id]);
        flash('Category toggled.');
    } elseif ($do === 'attr_add' && (int)($_POST['category_id'] ?? 0) > 0 && trim($_POST['key_name'] ?? '') !== '') {
        $catId = (int)$_POST['category_id'];
        $key = strtolower(trim(preg_replace('/[^a-z0-9_]+/i', '_', trim($_POST['key_name'])), '_'));
        $type = in_array($_POST['input_type'] ?? '', ['text', 'number', 'select', 'boolean'], true) ? $_POST['input_type'] : 'text';
        $options = null;
        if ($type === 'select') {
            $opts = array_values(array_filter(array_map('trim', explode(',', $_POST['options'] ?? ''))));
            $options = $opts ? json_encode($opts, JSON_UNESCAPED_UNICODE) : null;
        }
        if ($key === '') {
            flash('Attribute key is required.', 'error');
        } else {
            q("INSERT INTO category_attributes (category_id, key_name, label, input_type, options, unit, is_required, is_filterable, sort_order)
               VALUES (?,?,?,?,?,?,?,?,?)
               ON DUPLICATE KEY UPDATE label=VALUES(label), input_type=VALUES(input_type), options=VALUES(options), unit=VALUES(unit),
                 is_required=VALUES(is_required), is_filterable=VALUES(is_filterable), sort_order=VALUES(sort_order)",
              [$catId, $key, trim($_POST['label'] ?? '') ?: ucfirst(str_replace('_', ' ', $key)), $type, $options,
               trim($_POST['unit'] ?? '') ?: null, isset($_POST['is_required']) ? 1 : 0, isset($_POST['is_filterable']) ? 1 : 0, (int)($_POST['sort_order'] ?? 0)]);
            flash('Attribute saved.');
        }
        $redirectQuery = 'cat=' . $catId;
    } elseif ($do === 'attr_delete') {
        $catId = (int)val("SELECT category_id FROM category_attributes WHERE id = ?", [$id]);
        q("DELETE FROM category_attributes WHERE id = ?", [$id]);
        flash('Attribute removed.');
        if ($catId) $redirectQuery = 'cat=' . $catId;
    } elseif ($do === 'synonym_add' && trim($_POST['latin_term'] ?? '') !== '' && trim($_POST['amharic_term'] ?? '') !== '') {
        try {
            q("INSERT INTO search_synonyms (latin_term, amharic_term) VALUES (?,?)",
              [mb_strtolower(trim($_POST['latin_term'])), trim($_POST['amharic_term'])]);
            flash('Synonym added.');
        } catch (Throwable $e) {
            flash('That pair already exists.', 'error');
        }
    } elseif ($do === 'synonym_delete') {
        q("DELETE FROM search_synonyms WHERE id = ?", [$id]);
        flash('Synonym removed.');
    } elseif ($do === 'software_save' && $u['account_type'] === 'super_admin') {
        if (!software_library_ready()) {
            flash('Run database migrations before publishing software.', 'error');
        } else {
            $existing = $id ? row("SELECT * FROM software_items WHERE id = ?", [$id]) : null;
            $newPackage = null;
            try {
                if ($id && !$existing) throw new RuntimeException('Software entry not found.');
                $title = trim((string)($_POST['title'] ?? ''));
                $short = trim((string)($_POST['short_description'] ?? ''));
                $description = trim((string)($_POST['description'] ?? ''));
                $itemType = ($_POST['item_type'] ?? '') === 'plugin' ? 'plugin' : 'software';
                $status = in_array($_POST['software_status'] ?? '', ['draft', 'published', 'archived'], true)
                    ? $_POST['software_status'] : 'draft';
                $downloadMode = ($_POST['download_mode'] ?? '') === 'external' ? 'external' : 'file';
                $externalInput = trim((string)($_POST['external_url'] ?? ''));
                $externalUrl = software_validate_external_url($externalInput);
                $youtubeInput = trim((string)($_POST['youtube_url'] ?? ''));
                $youtubeId = software_youtube_id($youtubeInput);
                if (mb_strlen($title) < 2) throw new RuntimeException('Title must contain at least 2 characters.');
                if (mb_strlen($short) < 10 || mb_strlen($short) > 300) throw new RuntimeException('Short description must contain 10–300 characters.');
                if (mb_strlen($description) < 20) throw new RuntimeException('Full description must contain at least 20 characters.');
                if ($downloadMode === 'external' && ($externalInput === '' || !$externalUrl)) {
                    throw new RuntimeException('Enter a valid HTTP or HTTPS download link.');
                }
                if ($youtubeInput !== '' && !$youtubeId) throw new RuntimeException('Enter a valid YouTube video link.');

                $newPackage = $downloadMode === 'file'
                    ? software_upload_package($_FILES['software_file'] ?? [])
                    : null;
                $filePath = $existing['file_path'] ?? null;
                $originalName = $existing['original_filename'] ?? null;
                $fileSize = $existing['file_size'] ?? null;
                if ($newPackage) {
                    $filePath = $newPackage['path'];
                    $originalName = $newPackage['name'];
                    $fileSize = $newPackage['size'];
                }
                if ($downloadMode === 'external') {
                    $filePath = $originalName = $fileSize = null;
                } else {
                    $externalUrl = null;
                }
                if ($status === 'published' && !$filePath && !$externalUrl) {
                    throw new RuntimeException('Published entries need either an uploaded package or an external download link.');
                }

                $values = [
                    $title, $itemType, $short, $description,
                    trim((string)($_POST['version'] ?? '')) ?: null,
                    trim((string)($_POST['developer'] ?? '')) ?: null,
                    trim((string)($_POST['category'] ?? '')) ?: null,
                    trim((string)($_POST['platforms'] ?? '')) ?: null,
                    trim((string)($_POST['license_type'] ?? '')) ?: null,
                    $filePath, $originalName, $fileSize, $externalUrl,
                    $youtubeInput ?: null, $youtubeId, $status,
                    isset($_POST['is_featured']) ? 1 : 0,
                ];
                if ($existing) {
                    q("UPDATE software_items SET title=?, item_type=?, short_description=?, description=?, version=?, developer=?,
                       category=?, platforms=?, license_type=?, file_path=?, original_filename=?, file_size=?, external_url=?,
                       youtube_url=?, youtube_video_id=?, status=?, is_featured=?,
                       published_at=IF(?='published', COALESCE(published_at,NOW()), published_at) WHERE id=?",
                      [...$values, $status, $id]);
                    $softwareId = $id;
                } else {
                    $slug = slugify($title, 'software_items');
                    q("INSERT INTO software_items
                       (title, slug, item_type, short_description, description, version, developer, category, platforms,
                        license_type, file_path, original_filename, file_size, external_url, youtube_url, youtube_video_id,
                        status, is_featured, created_by, published_at)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, IF(?='published',NOW(),NULL))",
                      [$title, $slug, ...array_slice($values, 1), $u['id'], $status]);
                    $softwareId = (int)db()->lastInsertId();
                }
                if ($newPackage && $existing && $existing['file_path'] && $existing['file_path'] !== $newPackage['path']) {
                    software_delete_private_file($existing['file_path']);
                } elseif ($existing && $downloadMode === 'external' && $existing['file_path']) {
                    software_delete_private_file($existing['file_path']);
                }
                software_add_screenshots($softwareId, $_FILES['screenshots'] ?? []);
                flash(($existing ? 'Updated' : 'Created') . ' software entry.');
                $redirectQuery = 'edit=' . $softwareId;
            } catch (Throwable $e) {
                if ($newPackage) software_delete_private_file($newPackage['path']);
                flash($e->getMessage(), 'error');
                if ($id) $redirectQuery = 'edit=' . $id;
            }
        }
    } elseif ($do === 'software_status' && $u['account_type'] === 'super_admin'
        && in_array($_POST['software_status'] ?? '', ['draft', 'published', 'archived'], true)) {
        $item = row("SELECT file_path, external_url FROM software_items WHERE id = ?", [$id]);
        if (!$item) {
            flash('Software entry not found.', 'error');
        } elseif ($_POST['software_status'] === 'published' && !$item['file_path'] && !$item['external_url']) {
            flash('Add a package or external link before publishing.', 'error');
        } else {
            q("UPDATE software_items SET status=?, published_at=IF(?='published',COALESCE(published_at,NOW()),published_at) WHERE id=?",
              [$_POST['software_status'], $_POST['software_status'], $id]);
            flash('Software status updated.');
        }
    } elseif ($do === 'software_screenshot_delete' && $u['account_type'] === 'super_admin') {
        $shot = row("SELECT image_path, software_id FROM software_screenshots WHERE id = ?", [$id]);
        if ($shot) {
            q("DELETE FROM software_screenshots WHERE id = ?", [$id]);
            software_delete_screenshot_file($shot['image_path']);
            $redirectQuery = 'edit=' . (int)$shot['software_id'];
            flash('Screenshot removed.');
        }
    } else {
        require __DIR__ . '/admin_more_actions.php'; // verification, locations, pages, admins, backups, ad credits
    }
    if ($do !== '') audit($do, $_POST['ltype'] ?? $_POST['reported_type'] ?? $section, $id ?: null,
        implode(' ', array_filter([$_POST['status'] ?? '', $_POST['verification_status'] ?? ''])));
    redirect('admin/' . $section . (!empty($redirectQuery) ? '?' . $redirectQuery : ''));
}

// ---------- data ----------
$statusFilter = $_GET['status'] ?? '';
include __DIR__ . '/../views/layout_top.php';
?>
<div class="dash-layout admin-layout">
  <aside class="dash-nav admin-sidebar">
    <div class="admin-sidebar-logo">
      <span class="logo-mark"><?= e($ui['logo_mark'] ?? 'EG') ?></span>
      <span>Admin tools</span>
    </div>
    <button type="button" class="admin-menu-toggle" aria-expanded="false" aria-controls="admin-menu-links">
      <span><small>Current section</small><strong><?= e(strip_tags($sections[$section] ?? 'Dashboard')) ?></strong></span>
      <span class="admin-menu-toggle-icon" aria-hidden="true">☰</span>
    </button>
    <div class="admin-menu-backdrop" hidden></div>
    <nav class="admin-menu-links" id="admin-menu-links" aria-label="Administration">
      <div class="admin-menu-drawer-head">
        <div><small>EzihGebeya</small><strong>Admin tools</strong></div>
        <button type="button" class="admin-menu-close" aria-label="Close admin menu">×</button>
      </div>
      <h3>Administration</h3>
    <?php
    // Cached rather than a nightly-cron precompute (unlike the suspicious-activity trend
    // below): these drive the moderation-queue sidebar an admin acts on the same day, so a
    // 24h-stale cron result would hide same-day pending items. A short TTL still moves the
    // 11 COUNT(*) queries off nearly every admin page load (they'd otherwise re-run on every
    // section view) while staying fresh enough for real moderation work.
    $pendCounts = cache_remember('admin_pending_counts', 120, fn() => [
        'businesses' => (int)val("SELECT COUNT(*) FROM businesses WHERE status='pending'"),
        'verification' => (int)val("SELECT COUNT(*) FROM verification_requests WHERE status='pending'"),
        'products' => (int)val("SELECT COUNT(*) FROM products WHERE status='pending_review'"),
        'services' => (int)val("SELECT COUNT(*) FROM services WHERE status='pending_review'"),
        'supplies' => (int)val("SELECT COUNT(*) FROM supplies WHERE status='pending_review'"),
        'videos' => (int)val("SELECT COUNT(*) FROM video_posts WHERE status='pending'"),
        'reviews' => (int)val("SELECT COUNT(*) FROM reviews WHERE status='pending'"),
        'reports' => (int)val("SELECT COUNT(*) FROM reports WHERE status='open'"),
        'support' => db_table_exists('support_tickets') ? (int)val("SELECT COUNT(*) FROM support_tickets WHERE status IN ('open','waiting_user')") : 0,
        'orders' => (int)val("SELECT COUNT(*) FROM orders WHERE status='pending'"),
        'payments' => (int)val("SELECT COUNT(*) FROM payments WHERE status='pending'"),
    ]);
    foreach ($sections as $k => $label): $n = $pendCounts[$k] ?? 0; ?>
      <a href="<?= url('admin/' . $k) ?>" class="<?= $k === $section ? 'current' : '' ?>"><?= $label ?><?= $n ? " <span class='pill'>$n</span>" : '' ?></a>
    <?php endforeach; ?>
    </nav>
  </aside>

  <div class="dash-main">
  <?php if ($section === 'dashboard'): ?>
    <h1>Admin Dashboard</h1>
    <?php $dashCounters = cache_remember('admin_dashboard_counters', 300, fn() => [
        'total_users' => (int)val("SELECT COUNT(*) FROM users"),
        'active_businesses' => (int)val("SELECT COUNT(*) FROM businesses WHERE status='active'"),
        'active_listings' => (int)val("SELECT (SELECT COUNT(*) FROM products WHERE status='active') + (SELECT COUNT(*) FROM services WHERE status='active') + (SELECT COUNT(*) FROM supplies WHERE status='active')"),
        'total_inquiries' => (int)val("SELECT COUNT(*) FROM inquiries"),
        'inquiries_7d' => (int)val("SELECT COUNT(*) FROM inquiries WHERE created_at > NOW() - INTERVAL 7 DAY"),
        'approved_videos' => (int)val("SELECT COUNT(*) FROM video_posts WHERE status='approved'"),
        'video_views' => (int)val("SELECT COALESCE(SUM(views_count),0) FROM video_posts"),
        'cta_clicks' => (int)val("SELECT COALESCE(SUM(cta_clicks_count),0) FROM video_posts"),
    ]); ?>
    <?php $cards = [
        'Total users' => $dashCounters['total_users'],
        'Businesses' => $dashCounters['active_businesses'],
        'Pending businesses' => $pendCounts['businesses'],
        'Active listings' => $dashCounters['active_listings'],
        'Pending listings' => $pendCounts['products'] + $pendCounts['services'] + $pendCounts['supplies'],
        'Pending videos' => $pendCounts['videos'],
        'Open reports' => $pendCounts['reports'],
        'Total inquiries' => $dashCounters['total_inquiries'],
        'Inquiries (7 days)' => $dashCounters['inquiries_7d'],
        'Approved videos' => $dashCounters['approved_videos'],
        'Video views' => $dashCounters['video_views'],
        'CTA clicks' => $dashCounters['cta_clicks'],
    ]; ?>
    <div class="stat-grid">
      <?php foreach ($cards as $label => $n): ?>
        <div class="stat-card"><div class="stat-num"><?= number_format((float)$n) ?></div><div class="stat-label"><?= $label ?></div></div>
      <?php endforeach; ?>
    </div>
    <div class="panel">
      <h3>Top categories by active products</h3>
      <?php $top = rows("SELECT c.name, COUNT(*) n FROM products p JOIN categories c ON c.id = p.category_id WHERE p.status='active' GROUP BY c.id ORDER BY n DESC LIMIT 8"); ?>
      <?php if (!$top): ?><p class="muted">No data yet.</p><?php endif; ?>
      <?php foreach ($top as $t): ?><div class="bar-row"><span><?= e($t['name']) ?></span><b><?= $t['n'] ?></b></div><?php endforeach; ?>
    </div>

  <?php elseif ($section === 'businesses'): ?>
    <h1>Businesses</h1>
    <?php $list = rows("SELECT b.*, u.full_name owner, u.phone owner_phone FROM businesses b JOIN users u ON u.id=b.user_id
        WHERE b.status != 'deleted' " . ($statusFilter ? "AND b.status = " . db()->quote($statusFilter) : '') . " ORDER BY (b.status='pending') DESC, b.created_at DESC LIMIT 200"); ?>
    <div class="table-wrap"><table class="data-table">
      <tr><th>Business</th><th>Owner</th><th>Type</th><th>City</th><th>TIN/License</th><th>Status</th><th>Verify</th><th>Actions</th></tr>
      <?php foreach ($list as $b): ?>
      <tr>
        <td><strong><?= e($b['business_name']) ?></strong><?php if ($b['status'] === 'active'): ?><br><a class="small" href="<?= url('businesses/' . e($b['slug'])) ?>">view →</a><?php endif; ?></td>
        <td><?= e($b['owner']) ?><br><span class="muted small"><?= e($b['owner_phone']) ?></span></td>
        <td><?= e($b['business_type']) ?></td>
        <td><?= e($b['city']) ?></td>
        <td class="small"><?= e($b['tin_number'] ?: '—') ?> / <?= e($b['license_number'] ?: '—') ?></td>
        <td><span class="badge badge-status-<?= e($b['status']) ?>"><?= e($b['status']) ?></span></td>
        <td>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="biz_verify"><input type="hidden" name="id" value="<?= $b['id'] ?>">
            <select name="verification_status" onchange="this.form.submit()">
              <?php foreach (['unverified', 'phone_verified', 'document_verified', 'location_verified', 'premium_verified'] as $vs): ?>
                <option value="<?= $vs ?>" <?= $b['verification_status'] === $vs ? 'selected' : '' ?>><?= str_replace('_', ' ', $vs) ?></option>
              <?php endforeach; ?>
            </select>
          </form>
        </td>
        <td class="row-actions">
          <?php foreach ([['active', '✅ Approve'], ['rejected', '❌ Reject'], ['suspended', '⏸ Suspend']] as [$s, $lbl]): if ($b['status'] !== $s): ?>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="biz_status"><input type="hidden" name="id" value="<?= $b['id'] ?>"><input type="hidden" name="status" value="<?= $s ?>"><button><?= $lbl ?></button></form>
          <?php endif; endforeach; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </table></div>

  <?php elseif (in_array($section, ['products', 'services', 'supplies'])): ?>
    <?php
    $lt = ['products' => 'product', 'services' => 'service', 'supplies' => 'supply'][$section];
    $t = LISTING_TABLES[$lt];
    $tc = listing_title_col($lt);
    $hasRejectMeta = db_column_exists($t, 'rejection_reason');
    $rejectReasons = listing_rejection_reasons();
    $list = rows("SELECT l.*, b.business_name FROM `$t` l JOIN businesses b ON b.id=l.business_id
        WHERE l.status != 'deleted' ORDER BY (l.status='pending_review') DESC, l.created_at DESC LIMIT 200");
    ?>
    <h1><?= ucfirst($section) ?></h1>
    <div class="table-wrap"><table class="data-table">
      <tr><th>Title</th><th>Business</th><th>City</th><th>Price</th><th>Status</th><th>Featured</th><th>Actions</th></tr>
      <?php foreach ($list as $l): ?>
      <tr>
        <td><?= e($l[$tc]) ?><?php if ($l['status'] === 'active'): ?><br><a class="small" href="<?= listing_url($lt, $l) ?>">view →</a><?php endif; ?></td>
        <td><?= e($l['business_name']) ?></td>
        <td><?= e($l['city']) ?></td>
        <td><?= money($l['price'] ?? $l['starting_price'] ?? $l['price_per_unit'] ?? null) ?: '—' ?></td>
        <td><span class="badge badge-status-<?= e($l['status']) ?>"><?= e(str_replace('_', ' ', $l['status'])) ?></span>
          <?php if ($hasRejectMeta && $l['status'] === 'rejected' && !empty($l['rejection_reason'])): ?>
            <br><span class="muted small"><?= e(str_replace('_', ' ', $l['rejection_reason'])) ?></span>
          <?php endif; ?>
        </td>
        <td>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="listing_feature"><input type="hidden" name="ltype" value="<?= $lt ?>"><input type="hidden" name="id" value="<?= $l['id'] ?>">
            <button><?= $l['is_featured'] ? '★ Featured' : '☆ Feature' ?></button></form>
        </td>
        <td class="row-actions">
          <?php if ($l['status'] === 'pending_review'): ?>
            <a class="btn btn-outline btn-sm" href="<?= listing_url($lt, $l) ?>" target="_blank" rel="noopener">View details</a>
          <?php endif; ?>
          <?php foreach ([['active', '✅'], ['rejected', '❌'], ['paused', '⏸']] as [$s, $lbl]): if ($l['status'] !== $s): ?>
            <?php if ($s === 'rejected') continue; ?>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="listing_status"><input type="hidden" name="ltype" value="<?= $lt ?>"><input type="hidden" name="id" value="<?= $l['id'] ?>"><input type="hidden" name="status" value="<?= $s ?>"><button title="<?= $s ?>"><?= $lbl ?></button></form>
          <?php endif; endforeach; ?>
          <?php if ($l['status'] !== 'rejected'): ?>
          <details class="reject-popover">
            <summary title="Reject">Reject</summary>
            <form method="post" class="reject-form">
              <?= csrf_field() ?>
              <input type="hidden" name="do" value="listing_status">
              <input type="hidden" name="ltype" value="<?= $lt ?>">
              <input type="hidden" name="id" value="<?= $l['id'] ?>">
              <input type="hidden" name="status" value="rejected">
              <select name="rejection_reason">
                <?php foreach ($rejectReasons as $key => $instruction): ?>
                  <option value="<?= e($key) ?>"><?= e(ucfirst(str_replace('_', ' ', $key))) ?></option>
                <?php endforeach; ?>
              </select>
              <input name="rejection_note" placeholder="Correction note for seller">
              <button>Reject listing</button>
            </form>
          </details>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </table></div>

  <?php elseif ($section === 'videos'): ?>
    <h1>Video Moderation</h1>
    <?php $list = rows("SELECT v.*, b.business_name FROM video_posts v JOIN businesses b ON b.id=v.business_id
        WHERE v.status != 'deleted' ORDER BY (v.status='pending') DESC, v.created_at DESC LIMIT 100"); ?>
    <?php if (!$list): ?><div class="empty-state">No videos submitted.</div><?php endif; ?>
    <div class="admin-video-grid">
      <?php foreach ($list as $vp): ?>
      <div class="panel">
        <div class="video-wrap-sm"><?= video_embed_html($vp) ?></div>
        <strong><?= e($vp['business_name']) ?></strong> · <?= e($vp['platform']) ?> → <?= e($vp['linked_type']) ?>
        <div><span class="badge badge-status-<?= e($vp['status']) ?>"><?= e($vp['status']) ?></span>
          <span class="muted small"><?= time_ago($vp['created_at']) ?> · 👁<?= (int)$vp['views_count'] ?> · CTA <?= (int)$vp['cta_clicks_count'] ?> · 🚩<?= (int)$vp['reports_count'] ?></span></div>
        <div class="row-actions" style="margin-top:8px">
          <?php foreach ([['approved', '✅ Approve'], ['rejected', '❌ Reject'], ['disabled', '⏸ Disable']] as [$s, $lbl]): if ($vp['status'] !== $s): ?>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="video_status"><input type="hidden" name="id" value="<?= $vp['id'] ?>"><input type="hidden" name="status" value="<?= $s ?>"><button><?= $lbl ?></button></form>
          <?php endif; endforeach; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

  <?php elseif ($section === 'reviews'): ?>
    <h1>Review Moderation</h1>
    <?php $list = rows("SELECT r.*, u.full_name, b.business_name FROM reviews r JOIN users u ON u.id=r.reviewer_id JOIN businesses b ON b.id=r.business_id
        ORDER BY (r.status='pending') DESC, r.created_at DESC LIMIT 200"); ?>
    <?php if (!$list): ?><div class="empty-state">No reviews.</div><?php endif; ?>
    <?php foreach ($list as $r): ?>
    <div class="panel">
      <div class="review-head">
        <strong><?= e($r['full_name']) ?></strong> → <?= e($r['business_name']) ?> (<?= e($r['listing_type']) ?>)
        <span class="stars"><?= str_repeat('★', (int)$r['rating']) ?></span>
        <span class="badge badge-status-<?= e($r['status']) ?>"><?= e($r['status']) ?></span>
        <span class="muted"><?= time_ago($r['created_at']) ?></span>
      </div>
      <p><?= nl2br(e($r['comment'])) ?></p>
      <div class="row-actions">
        <?php foreach ([['approved', '✅ Approve'], ['rejected', '❌ Reject'], ['hidden', '🙈 Hide']] as [$s, $lbl]): if ($r['status'] !== $s): ?>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="review_status"><input type="hidden" name="id" value="<?= $r['id'] ?>"><input type="hidden" name="status" value="<?= $s ?>"><button><?= $lbl ?></button></form>
        <?php endif; endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>

  <?php elseif ($section === 'reports'): ?>
    <h1>Reports & Complaints</h1>
    <?php $list = rows("SELECT r.*, u.full_name reporter FROM reports r LEFT JOIN users u ON u.id=r.reporter_id
        ORDER BY (r.status='open') DESC, r.created_at DESC LIMIT 200"); ?>
    <?php if (!$list): ?><div class="empty-state">No reports. 🎉</div><?php endif; ?>
    <?php foreach ($list as $r): ?>
    <div class="panel">
      <div class="review-head">
        <strong>🚩 <?= e($r['reason']) ?></strong> · <?= e($r['reported_type']) ?> #<?= $r['reported_id'] ?>
        <span class="badge badge-status-<?= e($r['status']) ?>"><?= e($r['status']) ?></span>
        <span class="muted">by <?= e($r['reporter'] ?: 'guest') ?> · <?= time_ago($r['created_at']) ?></span>
      </div>
      <?php if ($r['description']): ?><p><?= nl2br(e($r['description'])) ?></p><?php endif; ?>
      <form method="post" class="inq-status-form">
        <?= csrf_field() ?><input type="hidden" name="do" value="report_status"><input type="hidden" name="id" value="<?= $r['id'] ?>">
        <select name="status">
          <?php foreach (['open', 'reviewing', 'resolved', 'dismissed'] as $s): ?><option <?= $r['status'] === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?>
        </select>
        <input name="admin_note" placeholder="Admin note…" value="<?= e($r['admin_note'] ?? '') ?>">
        <button class="btn btn-outline btn-sm">Save</button>
      </form>
    </div>
    <?php endforeach; ?>

  <?php elseif ($section === 'support'): ?>
    <h1>Support queue</h1>
    <?php if (!db_table_exists('support_tickets')): ?>
      <div role="alert" class="alert alert-warning">Run the latest database upgrade to enable support tickets.</div>
    <?php else: ?>
      <?php $list = rows("SELECT st.*, u.full_name, u.phone user_phone, u.email user_email, a.full_name assigned_admin
          FROM support_tickets st
          JOIN users u ON u.id = st.user_id
          LEFT JOIN users a ON a.id = st.assigned_admin_id
          ORDER BY FIELD(st.status, 'open', 'waiting_user', 'escalated', 'resolved', 'closed'), FIELD(st.priority, 'high', 'normal', 'low'), st.created_at DESC
          LIMIT 200"); ?>
      <?php if (!$list): ?><div class="empty-state">No support tickets. 🎉</div><?php endif; ?>
      <?php foreach ($list as $t): ?>
        <div class="panel">
          <div class="review-head">
            <strong>#<?= (int)$t['id'] ?> · <?= e($t['subject']) ?></strong>
            <span class="badge badge-status-<?= e($t['status']) ?>"><?= e(str_replace('_', ' ', $t['status'])) ?></span>
            <span class="badge badge-muted"><?= e($t['priority']) ?></span>
            <span class="muted">by <?= e($t['full_name']) ?> · <?= time_ago($t['created_at']) ?></span>
          </div>
          <p><?= nl2br(e($t['message'])) ?></p>
          <p class="muted small">
            <?= e(str_replace('_', ' ', $t['category'])) ?>
            <?php if ($t['phone']): ?> · callback <?= e($t['phone']) ?><?php endif; ?>
            <?php if ($t['preferred_callback_at']): ?> · preferred <?= e($t['preferred_callback_at']) ?><?php endif; ?>
            <?php if ($t['related_type']): ?> · related <?= e($t['related_type']) ?> #<?= (int)$t['related_id'] ?><?php endif; ?>
            <?php if ($t['assigned_admin']): ?> · assigned <?= e($t['assigned_admin']) ?><?php endif; ?>
            <?php if ($t['report_id']): ?> · <a href="<?= url('admin/reports') ?>">report #<?= (int)$t['report_id'] ?></a><?php endif; ?>
          </p>
          <form method="post" class="inq-status-form">
            <?= csrf_field() ?><input type="hidden" name="do" value="support_update"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
            <select name="status">
              <?php foreach (['open', 'waiting_user', 'escalated', 'resolved', 'closed'] as $s): ?><option value="<?= $s ?>" <?= $t['status'] === $s ? 'selected' : '' ?>><?= str_replace('_', ' ', $s) ?></option><?php endforeach; ?>
            </select>
            <select name="priority">
              <?php foreach (['low', 'normal', 'high'] as $p): ?><option value="<?= $p ?>" <?= $t['priority'] === $p ? 'selected' : '' ?>><?= $p ?></option><?php endforeach; ?>
            </select>
            <input name="admin_note" placeholder="Support note / reply" value="<?= e($t['admin_note'] ?? '') ?>">
            <button class="btn btn-outline btn-sm">Save</button>
          </form>
          <?php if (!$t['report_id']): ?>
            <details class="reject-popover">
              <summary>Escalate to moderation</summary>
              <form method="post" class="reject-form">
                <?= csrf_field() ?><input type="hidden" name="do" value="support_escalate"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                <select name="reported_type">
                  <?php foreach (['product', 'service', 'supply', 'business', 'video', 'review', 'user'] as $rt): ?><option value="<?= $rt ?>" <?= $t['related_type'] === $rt ? 'selected' : '' ?>><?= $rt ?></option><?php endforeach; ?>
                </select>
                <input type="number" name="reported_id" min="1" value="<?= in_array($t['related_type'], ['product','service','supply','business','video','review','user'], true) ? (int)$t['related_id'] : '' ?>" placeholder="Target ID">
                <input name="admin_note" placeholder="Escalation note">
                <button>Escalate</button>
              </form>
            </details>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>

  <?php elseif ($section === 'inquiries'): ?>
    <h1>All Inquiries (lead tracking)</h1>
    <?php $list = rows("SELECT i.*, b.business_name FROM inquiries i JOIN businesses b ON b.id=i.business_id ORDER BY i.created_at DESC LIMIT 300"); ?>
    <div class="table-wrap"><table class="data-table">
      <tr><th>Date</th><th>Customer</th><th>Phone</th><th>Business</th><th>Listing</th><th>Source</th><th>Status</th></tr>
      <?php foreach ($list as $i): ?>
      <tr>
        <td><?= time_ago($i['created_at']) ?></td>
        <td><?= e($i['name'] ?: '—') ?></td>
        <td><?= e($i['phone']) ?></td>
        <td><?= e($i['business_name']) ?></td>
        <td class="truncate"><?= e($i['listing_title'] ?: $i['listing_type']) ?></td>
        <td><?= e(str_replace('_', ' ', $i['source'])) ?></td>
        <td><span class="badge badge-status-<?= e($i['status']) ?>"><?= e($i['status']) ?></span></td>
      </tr>
      <?php endforeach; ?>
    </table></div>

  <?php elseif ($section === 'orders'): ?>
    <h1>Orders</h1>
    <?php $list = rows("SELECT o.*, u.full_name customer, b.business_name FROM orders o
        JOIN users u ON u.id=o.customer_id JOIN businesses b ON b.id=o.business_id
        ORDER BY o.created_at DESC LIMIT 300"); ?>
    <div class="table-wrap"><table class="data-table">
      <tr><th>Order</th><th>Customer</th><th>Business</th><th>Total</th><th>Payment</th><th>Status</th><th>Date</th><th></th></tr>
      <?php foreach ($list as $o): ?>
      <tr>
        <td><strong><?= e($o['order_number']) ?></strong></td>
        <td><?= e($o['customer']) ?><br><span class="muted small"><?= e($o['phone']) ?></span></td>
        <td><?= e($o['business_name']) ?></td>
        <td><?= money($o['total']) ?></td>
        <td class="small"><?= e(str_replace('_', ' ', $o['payment_method'])) ?></td>
        <td><span class="badge badge-status-<?= e($o['status']) ?>"><?= e(str_replace('_', ' ', $o['status'])) ?></span></td>
        <td class="small"><?= time_ago($o['created_at']) ?></td>
        <td>
          <form method="post" class="form-inline">
            <?= csrf_field() ?><input type="hidden" name="do" value="order_status"><input type="hidden" name="id" value="<?= $o['id'] ?>">
            <?php $nextStatuses = order_status_transitions($o['status']); ?>
            <select name="status" <?= !$nextStatuses ? 'disabled' : '' ?>>
              <option value=""><?= $nextStatuses ? 'Choose next status' : 'Final status' ?></option>
              <?php foreach ($nextStatuses as $s): ?>
                <option value="<?= $s ?>"><?= str_replace('_', ' ', $s) ?></option>
              <?php endforeach; ?>
            </select>
            <button class="btn btn-outline btn-sm" <?= !$nextStatuses ? 'disabled' : '' ?>>Set</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </table></div>

  <?php elseif ($section === 'payments'): ?>
    <h1>Payments (manual confirmation — §12.1)</h1>
    <?php $list = rows("SELECT p.*, u.full_name payer, b.business_name, o.order_number
        FROM payments p JOIN users u ON u.id=p.payer_id
        LEFT JOIN businesses b ON b.id=p.business_id LEFT JOIN orders o ON o.id=p.order_id
        ORDER BY (p.status='pending') DESC, p.created_at DESC LIMIT 300"); ?>
    <?php if (!$list): ?><div class="empty-state">No payments recorded.</div><?php endif; ?>
    <div class="table-wrap"><table class="data-table">
      <?php if ($list): ?><tr><th>Type</th><th>Payer</th><th>For</th><th>Amount</th><th>Method / Ref</th><th>Proof</th><th>Status</th><th>Actions</th></tr><?php endif; ?>
      <?php foreach ($list as $p): ?>
      <tr>
        <td class="small"><?= e(str_replace('_', ' ', $p['payment_type'])) ?></td>
        <td><?= e($p['payer']) ?></td>
        <td class="small">
          <?= $p['order_number'] ? 'Order ' . e($p['order_number']) : '' ?>
          <?= $p['promotion_id'] ? 'Promotion #' . $p['promotion_id'] : '' ?>
          <?= $p['subscription_id'] ? 'Subscription #' . $p['subscription_id'] : '' ?>
          <?= $p['business_name'] ? '<br>' . e($p['business_name']) : '' ?>
        </td>
        <td><strong><?= money($p['amount']) ?></strong></td>
        <td class="small"><?= e(str_replace('_', ' ', $p['payment_method'])) ?><br><?= e($p['reference_number'] ?: '—') ?></td>
        <td><?php if ($p['proof_image']): ?><a href="<?= e(url('download/payment/' . $p['id'])) ?>" target="_blank">view</a><?php else: ?>—<?php endif; ?></td>
        <td><span class="badge badge-status-<?= $p['status'] === 'confirmed' ? 'active' : ($p['status'] === 'rejected' ? 'rejected' : 'pending') ?>"><?= e($p['status']) ?></span></td>
        <td class="row-actions">
          <?php if ($p['status'] === 'pending'): ?>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="payment_confirm"><input type="hidden" name="id" value="<?= $p['id'] ?>"><button>✅ Confirm</button></form>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="payment_reject"><input type="hidden" name="id" value="<?= $p['id'] ?>"><button>❌ Reject</button></form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </table></div>

  <?php elseif ($section === 'promotions'): ?>
    <h1>Promotions</h1>
    <?php $list = rows("SELECT p.*, b.business_name FROM promotions p JOIN businesses b ON b.id=p.business_id
        ORDER BY (p.status='pending') DESC, p.created_at DESC LIMIT 200"); ?>
    <?php if (!$list): ?><div class="empty-state">No promotions requested.</div><?php endif; ?>
    <div class="table-wrap"><table class="data-table">
      <?php if ($list): ?><tr><th>Business</th><th>Type</th><th>Target</th><th>Weeks</th><th>Budget</th><th>Status</th><th>Runs</th><th></th></tr><?php endif; ?>
      <?php foreach ($list as $p): ?>
      <tr>
        <td><?= e($p['business_name']) ?></td>
        <td class="small"><?= e(PROMO_TYPES[$p['promotion_type']]['label'] ?? $p['promotion_type']) ?></td>
        <td class="small"><?= e($p['promotable_type']) ?> #<?= $p['promotable_id'] ?></td>
        <td><?= (int)$p['duration_weeks'] ?></td>
        <td><?= money($p['budget']) ?></td>
        <td><span class="badge badge-status-<?= e($p['status']) ?>"><?= e($p['status']) ?></span></td>
        <td class="small"><?= $p['starts_at'] ? date('M j', strtotime($p['starts_at'])) . ' – ' . date('M j', strtotime($p['ends_at'])) : '— (activates on payment confirm)' ?></td>
        <td class="row-actions">
          <?php if (in_array($p['status'], ['pending', 'scheduled', 'active', 'paused'], true)): ?>
            <details class="reject-popover">
              <summary>Manage</summary>
              <form method="post" class="reject-form">
                <?= csrf_field() ?><input type="hidden" name="do" value="promo_update"><input type="hidden" name="id" value="<?= $p['id'] ?>">
                <select name="status">
                  <?php foreach (['active' => 'Activate now', 'scheduled' => 'Schedule', 'paused' => 'Pause', 'cancelled' => 'Cancel'] as $s => $label): ?>
                    <option value="<?= $s ?>"><?= $label ?></option>
                  <?php endforeach; ?>
                </select>
                <input type="datetime-local" name="starts_at" value="<?= $p['starts_at'] ? date('Y-m-d\TH:i', strtotime($p['starts_at'])) : '' ?>">
                <button>Save</button>
              </form>
              <p class="muted small">Activation only works when the target is approved and live.</p>
            </details>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="promo_stop"><input type="hidden" name="id" value="<?= $p['id'] ?>"><button>⏹ Stop</button></form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </table></div>

  <?php elseif ($section === 'subscriptions'): ?>
    <h1>Subscriptions</h1>
    <?php $list = rows("SELECT s.*, b.business_name FROM subscriptions s JOIN businesses b ON b.id=s.business_id
        ORDER BY (s.status='pending') DESC, s.created_at DESC LIMIT 200"); ?>
    <?php if (!$list): ?><div class="empty-state">No subscriptions yet — all businesses are on the Free plan.</div><?php endif; ?>
    <div class="table-wrap"><table class="data-table">
      <?php if ($list): ?><tr><th>Business</th><th>Plan</th><th>Months</th><th>Status</th><th>Period</th><th>Requested</th></tr><?php endif; ?>
      <?php foreach ($list as $s): ?>
      <tr>
        <td><?= e($s['business_name']) ?></td>
        <td><?= PLANS[$s['plan']]['label'] ?? e($s['plan']) ?></td>
        <td><?= (int)$s['months'] ?></td>
        <td><span class="badge badge-status-<?= e($s['status']) ?>"><?= e($s['status']) ?></span></td>
        <td class="small"><?= $s['starts_at'] ? date('M j, Y', strtotime($s['starts_at'])) . ' – ' . date('M j, Y', strtotime($s['ends_at'])) : '— (activates on payment confirm)' ?></td>
        <td class="small"><?= time_ago($s['created_at']) ?></td>
      </tr>
      <?php endforeach; ?>
    </table></div>

  <?php elseif ($section === 'ad-placements' && $u['account_type'] === 'super_admin'): ?>
    <?php
      $rotationSettings = ad_rotation_settings();
      $inlineFrequency = ad_inline_frequency_settings();
      $activePlacementAds = rows("SELECT * FROM ads WHERE status = 'active' ORDER BY priority DESC, id");
      $placementConflicts = [];
      for ($left = 0; $left < count($activePlacementAds); $left++) {
          for ($right = $left + 1; $right < count($activePlacementAds); $right++) {
              $a = $activePlacementAds[$left]; $b = $activePlacementAds[$right];
              if (!ad_campaigns_overlap($a, $b)) continue;
              foreach (ad_shared_placements($a, $b) as $spot) {
                  if (empty($rotationSettings[$spot])) $placementConflicts[$spot][] = [(int)$a['id'], (int)$b['id']];
              }
          }
      }
    ?>
    <div class="section-head">
      <div>
        <p class="eyebrow">Advertising inventory</p>
        <h1>Advertising Placements</h1>
        <p class="muted">Control whether each spot rotates matching campaigns or remains exclusive.</p>
      </div>
      <a class="btn btn-outline" href="<?= url('admin/ads') ?>">Manage campaigns</a>
    </div>
    <?php if ($placementConflicts): ?>
      <div class="alert alert-warning"><strong>Existing exclusive-placement collisions detected.</strong> Rotation has stopped on those spots. Pause or retarget the campaigns identified below.</div>
    <?php endif; ?>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="ad_rotation_save">
      <div class="ad-placement-admin-grid">
        <?php foreach (AD_PLACEMENTS as $placementKey => $placement): ?>
          <?php
            $eligible = array_values(array_filter($activePlacementAds, fn(array $ad): bool => $ad['placement'] === 'any' || $ad['placement'] === $placementKey));
            $rotationOn = !empty($rotationSettings[$placementKey]);
          ?>
          <article class="panel ad-placement-admin-card <?= $rotationOn ? 'is-rotating' : 'is-exclusive' ?>">
            <div class="ad-placement-card-head">
              <span class="ad-placement-symbol" aria-hidden="true"><?= $rotationOn ? '↻' : '1' ?></span>
              <div><h2><?= e($placement['label']) ?></h2><code><?= e($placementKey) ?></code></div>
              <span class="badge <?= $rotationOn ? 'badge-status-active' : 'badge-status-paused' ?>"><?= $rotationOn ? 'Rotation enabled' : 'Exclusive spot' ?></span>
            </div>
            <p><?= e($placement['hint']) ?></p>
            <div class="ad-placement-stats">
              <span><strong><?= count($eligible) ?></strong> active eligible</span>
              <span><strong><?= count($placementConflicts[$placementKey] ?? []) ?></strong> collisions</span>
            </div>
            <?php if (!empty($placementConflicts[$placementKey])): ?>
              <div class="ad-placement-conflicts">
                <?php foreach ($placementConflicts[$placementKey] as [$firstId, $secondId]): ?><span>Campaign #<?= $firstId ?> overlaps #<?= $secondId ?></span><?php endforeach; ?>
              </div>
            <?php endif; ?>
            <label class="ad-rotation-toggle">
              <span><strong>Rotate multiple campaigns</strong><small><?= $rotationOn ? 'Campaigns share this placement by targeting score and priority.' : 'Only one campaign may use the same audience and schedule.' ?></small></span>
              <input type="checkbox" name="rotation[<?= e($placementKey) ?>]" value="1" <?= $rotationOn ? 'checked' : '' ?>>
              <i aria-hidden="true"></i>
            </label>
            <?php if ($placementKey === 'browse_inline'): ?>
              <div class="ad-placement-frequency">
                <label>Maximum ads per results page
                  <input type="number" name="delivery[browse_inline][max_per_page]" min="0" max="10" value="<?= (int)$inlineFrequency['max_per_page'] ?>">
                  <small>Use 0 to hide native ad cards without disabling the whole ad system.</small>
                </label>
                <label>Listings between each ad
                  <input type="number" name="delivery[browse_inline][listings_between]" min="2" max="30" value="<?= (int)$inlineFrequency['listings_between'] ?>">
                  <small>Example: 6 inserts one ad after every six organic listings.</small>
                </label>
              </div>
            <?php endif; ?>
          </article>
        <?php endforeach; ?>
      </div>
      <div class="panel ad-placement-savebar">
        <div><strong>Placement policy applies immediately</strong><p>Disabling rotation blocks future overlapping campaign activations for that spot.</p></div>
        <button class="btn btn-primary">Save placement settings</button>
      </div>
    </form>

  <?php elseif ($section === 'ads' && $u['account_type'] === 'super_admin'): ?>
    <?php $editAd = isset($_GET['edit']) ? row("SELECT * FROM ads WHERE id = ?", [(int)$_GET['edit']]) : null; ?>
    <?php $previewAd = isset($_GET['preview']) ? row("SELECT * FROM ads WHERE id = ?", [(int)$_GET['preview']]) : null; ?>

    <?php if ($previewAd): ?>
      <?php
        $previewPlacements = $previewAd['placement'] === 'any' ? array_keys(AD_PLACEMENTS) : [$previewAd['placement']];
        $previewConflicts = ad_campaign_conflicts($previewAd, (int)$previewAd['id']);
      ?>
      <div class="section-head">
        <div>
          <p class="eyebrow">Safe preview · no impressions, clicks, or spend</p>
          <h1>Campaign #<?= (int)$previewAd['id'] ?> preview</h1>
        </div>
        <div class="btn-row">
          <a class="btn btn-outline" href="<?= url('admin/ads?edit=' . $previewAd['id']) ?>">Edit campaign</a>
          <a class="btn btn-ghost" href="<?= url('admin/ads') ?>">Back to campaigns</a>
        </div>
      </div>
      <?php if ($previewConflicts): ?>
        <div class="alert alert-warning"><strong>Targeting collision:</strong> This campaign overlaps active campaign #<?= e(implode(', #', array_column($previewConflicts, 'id'))) ?> and cannot be activated until the conflict is resolved.</div>
      <?php else: ?>
        <div class="alert alert-success"><strong>Inventory available.</strong> No active campaign currently overlaps this campaign's placement, audience, location, and schedule.</div>
      <?php endif; ?>
      <div class="panel">
        <div class="review-head">
          <span class="badge badge-status-<?= e($previewAd['status']) ?>"><?= e($previewAd['status']) ?></span>
          <strong><?= e($previewAd['advertiser_name']) ?></strong>
          <span class="muted small"><?= e($previewAd['destination_url']) ?></span>
        </div>
        <p class="muted small">Preview uses the saved creative and never calls ad impression/click tracking.</p>
      </div>
      <div class="ad-admin-preview-grid">
        <?php foreach ($previewPlacements as $previewPlacement): ?>
          <section class="panel ad-admin-preview">
            <div class="section-head">
              <div><p class="eyebrow">Placement</p><h2><?= e(AD_PLACEMENTS[$previewPlacement]['label'] ?? $previewPlacement) ?></h2></div>
            </div>
            <div class="ad-admin-preview-stage ad-preview-<?= e($previewPlacement) ?>">
              <?= ad_preview_html($previewAd, $previewPlacement) ?>
            </div>
          </section>
        <?php endforeach; ?>
      </div>

    <?php elseif ($editAd || isset($_GET['new'])):
      $av = fn($k, $d = '') => e($editAd[$k] ?? $d);
      $schedulePlacement = isset(AD_PLACEMENTS[$_GET['placement'] ?? '']) ? $_GET['placement'] : ($editAd['placement'] ?? '');
      $scheduleStarts = $editAd && $editAd['starts_at'] ? date('Y-m-d\TH:i', strtotime($editAd['starts_at'])) : (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $_GET['starts_at'] ?? '') ? $_GET['starts_at'] : '');
      $scheduleEnds = $editAd && $editAd['ends_at'] ? date('Y-m-d\TH:i', strtotime($editAd['ends_at'])) : (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $_GET['ends_at'] ?? '') ? $_GET['ends_at'] : '');
    ?>
      <h1><?= $editAd ? 'Edit campaign #' . $editAd['id'] : 'New ad campaign' ?></h1>
      <form class="panel form-2col" method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="ad_save">
        <input type="hidden" name="id" value="<?= (int)($editAd['id'] ?? 0) ?>">
        <label>Advertiser name * <input name="advertiser_name" required value="<?= $av('advertiser_name') ?>"></label>
        <label>Advertiser phone <input name="advertiser_phone" value="<?= $av('advertiser_phone') ?>"></label>
        <label>Ad title <input name="title" maxlength="150" value="<?= $av('title') ?>"></label>
        <label>Destination URL * <input name="destination_url" required placeholder="https://… or /products/some-slug" value="<?= $av('destination_url') ?>">
          <small class="field-hint">Where a click sends the visitor — an internal path like <code>/products/some-slug</code> or a full external URL.</small>
        </label>
        <label class="span2">Ad text <input name="body" maxlength="300" value="<?= $av('body') ?>"></label>
        <label>Creative image <input type="file" name="image" accept="image/*">
          <?php if ($editAd && $editAd['image']): ?><span class="muted small">current: <a href="<?= e(img_url($editAd['image'])) ?>" target="_blank">view</a></span><?php endif; ?>
        </label>
        <label>Placement
          <select name="placement">
            <option value="any">Any slot</option>
            <?php foreach (AD_PLACEMENTS as $k => $p): ?>
              <option value="<?= $k ?>" <?= $schedulePlacement === $k ? 'selected' : '' ?>><?= $p['label'] ?></option>
            <?php endforeach; ?>
          </select>
          <small class="field-hint">"Any slot" makes this campaign eligible everywhere; a specific slot restricts it to just that spot.</small>
        </label>
        <label>Market type
          <select name="market_type">
            <option value="any">All markets</option>
            <?php foreach (['product' => 'Furniture', 'service' => 'Services', 'supply' => 'Supplies'] as $k => $l): ?>
              <option value="<?= $k ?>" <?= ($editAd['market_type'] ?? '') === $k ? 'selected' : '' ?>><?= $l ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Category
          <select name="category_id">
            <option value="">All categories</option>
            <?php foreach (rows("SELECT id, name, type FROM categories WHERE status='active' ORDER BY type, sort_order") as $c): ?>
              <option value="<?= $c['id'] ?>" <?= (int)($editAd['category_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?> (<?= $c['type'] ?>)</option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>City
          <select name="city" id="city-select">
            <option value="">All cities</option>
            <?php foreach (array_keys(CITIES) as $c): ?><option <?= ($editAd['city'] ?? '') === $c ? 'selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?>
          </select>
        </label>
        <label>Sub-city
          <select name="subcity" id="subcity-select" data-selected="<?= $av('subcity') ?>">
            <option value="">All</option>
            <?php foreach (CITIES[$editAd['city'] ?? ''] ?? [] as $s): ?><option <?= ($editAd['subcity'] ?? '') === $s ? 'selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?>
          </select>
          <small class="field-hint">Market/category/city/sub-city all narrow the audience together (a visitor must match every one you set) — more specific targeting also wins more often when several ads compete for the same slot.</small>
        </label>
        <label>Pricing model
          <select name="pricing_type">
            <?php foreach (AD_PRICING as $k => $l): ?><option value="<?= $k ?>" <?= ($editAd['pricing_type'] ?? 'cpc') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
          </select>
          <small class="field-hint">CPM/CPC auto-add to Spent as the ad shows/gets clicked, until Budget cap is hit. Flat/week is billed manually below and never auto-consumes the budget cap.</small>
        </label>
        <label>Unit price (ETB) <input type="number" step="0.01" name="unit_price" value="<?= $av('unit_price') ?>">
          <small class="field-hint">Price per 1,000 views (CPM), per click (CPC), or per week (flat) — meaning depends on the pricing model above.</small>
        </label>
        <label>Budget cap (ETB, 0 = unlimited) <input type="number" step="0.01" name="budget" value="<?= $av('budget', 0) ?>">
          <small class="field-hint">Campaign auto-completes once Spent reaches this — ignored by the flat/week pricing model, which never auto-spends.</small>
        </label>
        <label>Priority (1–5, weight in rotation) <input type="number" name="priority" min="1" max="5" value="<?= $av('priority', 1) ?>">
          <small class="field-hint">When more than one active campaign matches the same slot and visitor, higher priority wins more often — it doesn't guarantee exclusivity.</small>
        </label>
        <label>Starts <input type="datetime-local" name="starts_at" value="<?= e($scheduleStarts) ?>">
          <small class="field-hint">Blank starts immediately once the campaign is Active.</small>
        </label>
        <label>Ends <input type="datetime-local" name="ends_at" value="<?= e($scheduleEnds) ?>">
          <small class="field-hint">Blank runs indefinitely (until you pause it or the budget cap is hit).</small>
        </label>
        <div class="span2">
          <button class="btn btn-primary"><?= $editAd ? 'Save campaign' : 'Create campaign' ?></button>
          <?php if ($editAd): ?><a class="btn btn-outline" href="<?= url('admin/ads?preview=' . $editAd['id']) ?>">Preview without running</a><?php endif; ?>
          <a class="btn btn-ghost" href="<?= url('admin/ads') ?>">Back to list</a>
        </div>
        <p class="muted small span2">Rate card hints: <?php foreach (AD_PLACEMENTS as $p) echo '<br>· <b>' . $p['label'] . '</b> — ' . $p['hint']; ?></p>
      </form>

      <?php if ($editAd): ?>
      <div class="panel">
        <h3>Record advertiser payment</h3>
        <?php $paid = (float)val("SELECT COALESCE(SUM(amount),0) FROM payments WHERE ad_id = ? AND status = 'confirmed'", [$editAd['id']]); ?>
        <p class="muted small">Paid so far: <b><?= money($paid) ?: '0 ETB' ?></b> · Delivered value (spent): <b><?= money($editAd['spent']) ?: '0 ETB' ?></b></p>
        <form method="post" class="form-inline">
          <?= csrf_field() ?><input type="hidden" name="do" value="ad_payment"><input type="hidden" name="id" value="<?= $editAd['id'] ?>">
          <input type="number" step="0.01" name="amount" placeholder="Amount (ETB)" required>
          <select name="payment_method"><option value="cash">Cash</option><?php foreach (PAYMENT_METHODS as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?></select>
          <input name="reference_number" placeholder="Reference">
          <button class="btn btn-primary btn-sm">Record</button>
        </form>
        <h3 class="section-gap">Credit adjustment (§9.4)</h3>
        <p class="muted small">Refund delivered value back to the campaign — e.g. after suspicious clicks flagged in <a href="<?= url('admin/analytics') ?>">Analytics</a>. Credited so far: <b><?= money($editAd['credited'] ?? 0) ?: '0 ETB' ?></b></p>
        <form method="post" class="form-inline">
          <?= csrf_field() ?><input type="hidden" name="do" value="ad_credit"><input type="hidden" name="id" value="<?= $editAd['id'] ?>">
          <input type="number" step="0.01" name="amount" placeholder="Credit amount (ETB)" required>
          <input name="note" placeholder="Reason (e.g. suspicious clicks 2026-07-06)">
          <button class="btn btn-outline btn-sm">Apply credit</button>
        </form>
      </div>
      <?php endif; ?>

    <?php else: ?>
      <div class="section-head">
        <h1>📣 Ad Manager</h1>
        <div class="btn-row">
          <a class="btn btn-outline" href="<?= url('admin/ad-placements') ?>">Manage placements</a>
          <a class="btn btn-primary" href="<?= url('admin/ads?new=1') ?>">+ New campaign</a>
        </div>
      </div>
      <?php
      $adCards = [
          'Active campaigns' => val("SELECT COUNT(*) FROM ads WHERE status='active'"),
          'Impressions (7d)' => val("SELECT COUNT(*) FROM ad_events WHERE event_type='impression' AND created_at > NOW() - INTERVAL 7 DAY"),
          'Clicks (7d)' => val("SELECT COUNT(*) FROM ad_events WHERE event_type='click' AND created_at > NOW() - INTERVAL 7 DAY"),
          'Ad revenue delivered' => money(val("SELECT COALESCE(SUM(spent),0) FROM ads")) ?: '0 ETB',
          'Payments collected' => money(val("SELECT COALESCE(SUM(amount),0) FROM payments WHERE ad_id IS NOT NULL AND status='confirmed'")) ?: '0 ETB',
      ];
      ?>
      <div class="stat-grid">
        <?php foreach ($adCards as $label => $n): ?>
          <div class="stat-card"><div class="stat-num" style="font-size:1.15rem"><?= is_numeric($n) ? number_format((float)$n) : $n ?></div><div class="stat-label"><?= $label ?></div></div>
        <?php endforeach; ?>
      </div>

      <?php
      $adFilter = [
          'q' => mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100),
          'status' => in_array($_GET['status'] ?? '', ['draft','active','paused','completed','archived','all'], true) ? $_GET['status'] : '',
          'market' => in_array($_GET['market'] ?? '', ['any','product','service','supply'], true) ? $_GET['market'] : '',
          'placement' => (($_GET['placement'] ?? '') === 'any' || isset(AD_PLACEMENTS[$_GET['placement'] ?? ''])) ? $_GET['placement'] : '',
          'city' => isset(CITIES[$_GET['city'] ?? '']) ? $_GET['city'] : '',
          'subcity' => in_array($_GET['subcity'] ?? '', CITIES[$_GET['city'] ?? ''] ?? [], true) ? $_GET['subcity'] : '',
          'pricing' => isset(AD_PRICING[$_GET['pricing'] ?? '']) ? $_GET['pricing'] : '',
          'delivery' => in_array($_GET['delivery'] ?? '', ['live','scheduled','ending','exhausted'], true) ? $_GET['delivery'] : '',
          'group' => in_array($_GET['group'] ?? '', ['none','placement','market','city','status','advertiser'], true) ? $_GET['group'] : 'none',
          'sort' => in_array($_GET['sort'] ?? '', ['newest','priority','spend','impressions','clicks','ctr','ending'], true) ? $_GET['sort'] : 'newest',
      ];
      $adWhere = []; $adParams = [];
      if ($adFilter['status'] === '') $adWhere[] = "a.status != 'archived'";
      elseif ($adFilter['status'] !== 'all') { $adWhere[] = 'a.status = ?'; $adParams[] = $adFilter['status']; }
      if ($adFilter['q'] !== '') {
          $adWhere[] = '(a.advertiser_name LIKE ? OR a.title LIKE ? OR a.destination_url LIKE ? OR a.advertiser_phone LIKE ?)';
          $needle = '%' . $adFilter['q'] . '%'; array_push($adParams, $needle, $needle, $needle, $needle);
      }
      foreach (['market_type' => 'market', 'placement' => 'placement', 'city' => 'city', 'subcity' => 'subcity', 'pricing_type' => 'pricing'] as $column => $key) {
          if ($adFilter[$key] !== '') { $adWhere[] = "a.$column = ?"; $adParams[] = $adFilter[$key]; }
      }
      if ($adFilter['delivery'] === 'live') $adWhere[] = "a.status='active' AND (a.starts_at IS NULL OR a.starts_at <= NOW()) AND (a.ends_at IS NULL OR a.ends_at >= NOW()) AND (a.budget <= 0 OR a.spent < a.budget)";
      elseif ($adFilter['delivery'] === 'scheduled') $adWhere[] = "a.status='active' AND a.starts_at > NOW()";
      elseif ($adFilter['delivery'] === 'ending') $adWhere[] = "a.status='active' AND a.ends_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)";
      elseif ($adFilter['delivery'] === 'exhausted') $adWhere[] = "a.budget > 0 AND a.spent >= a.budget";
      $adSortSql = [
          'newest' => "(a.status='active') DESC, a.created_at DESC", 'priority' => 'a.priority DESC, a.created_at DESC',
          'spend' => 'a.spent DESC, a.created_at DESC', 'impressions' => 'a.impressions_count DESC, a.created_at DESC',
          'clicks' => 'a.clicks_count DESC, a.created_at DESC', 'ctr' => '(a.clicks_count / GREATEST(a.impressions_count,1)) DESC',
          'ending' => 'a.ends_at IS NULL, a.ends_at ASC',
      ][$adFilter['sort']];
      $adsList = rows("SELECT a.*, c.name cat_name FROM ads a LEFT JOIN categories c ON c.id = a.category_id WHERE "
          . ($adWhere ? implode(' AND ', $adWhere) : '1=1') . " ORDER BY $adSortSql LIMIT 500", $adParams);
      $adGroupLabel = fn(array $ad): string => match ($adFilter['group']) {
          'placement' => AD_PLACEMENTS[$ad['placement']]['label'] ?? 'Any advertising spot',
          'market' => $ad['market_type'] === 'any' ? 'All markets' : ucfirst($ad['market_type']),
          'city' => $ad['city'] ?: 'All cities', 'status' => ucfirst($ad['status']),
          'advertiser' => $ad['advertiser_name'], default => 'All campaigns',
      };
      $adsGroups = []; foreach ($adsList as $adRow) $adsGroups[$adGroupLabel($adRow)][] = $adRow;
      ?>
      <form class="panel ad-manager-filters" method="get" action="<?= url('admin/ads') ?>">
        <div class="ad-filter-head"><div><p class="eyebrow">Campaign workspace</p><h2>Filter and organize</h2></div><span><?= count($adsList) ?> result<?= count($adsList) === 1 ? '' : 's' ?></span></div>
        <div class="ad-filter-grid">
          <label class="ad-filter-search">Search<input name="q" value="<?= e($adFilter['q']) ?>" placeholder="Advertiser, title, phone, or URL"></label>
          <label>Status<select name="status"><option value="">Current</option><option value="all" <?= $adFilter['status'] === 'all' ? 'selected' : '' ?>>All statuses</option><?php foreach (['draft','active','paused','completed','archived'] as $value): ?><option value="<?= $value ?>" <?= $adFilter['status'] === $value ? 'selected' : '' ?>><?= ucfirst($value) ?></option><?php endforeach; ?></select></label>
          <label>Market<select name="market"><option value="">All markets</option><?php foreach (['any' => 'Broad / any market','product' => 'Furniture','service' => 'Services','supply' => 'Supplies'] as $value => $label): ?><option value="<?= $value ?>" <?= $adFilter['market'] === $value ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></label>
          <label>Advertising spot<select name="placement"><option value="">All spots</option><option value="any" <?= $adFilter['placement'] === 'any' ? 'selected' : '' ?>>Any-spot campaigns</option><?php foreach (AD_PLACEMENTS as $value => $info): ?><option value="<?= $value ?>" <?= $adFilter['placement'] === $value ? 'selected' : '' ?>><?= e($info['label']) ?></option><?php endforeach; ?></select></label>
          <label>City<select name="city" onchange="this.form.submit()"><option value="">All cities</option><?php foreach (array_keys(CITIES) as $value): ?><option value="<?= e($value) ?>" <?= $adFilter['city'] === $value ? 'selected' : '' ?>><?= e($value) ?></option><?php endforeach; ?></select></label>
          <label>Sub-city<select name="subcity" <?= $adFilter['city'] === '' ? 'disabled' : '' ?>><option value="">All sub-cities</option><?php foreach (CITIES[$adFilter['city']] ?? [] as $value): ?><option value="<?= e($value) ?>" <?= $adFilter['subcity'] === $value ? 'selected' : '' ?>><?= e($value) ?></option><?php endforeach; ?></select></label>
          <label>Pricing<select name="pricing"><option value="">All pricing</option><?php foreach (AD_PRICING as $value => $label): ?><option value="<?= $value ?>" <?= $adFilter['pricing'] === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
          <label>Schedule<select name="delivery"><option value="">Any schedule</option><?php foreach (['live' => 'Live now','scheduled' => 'Scheduled','ending' => 'Ending in 7 days','exhausted' => 'Budget exhausted'] as $value => $label): ?><option value="<?= $value ?>" <?= $adFilter['delivery'] === $value ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></label>
          <label>Group by<select name="group"><?php foreach (['none' => 'No grouping','placement' => 'Advertising spot','market' => 'Market','city' => 'City','status' => 'Status','advertiser' => 'Advertiser'] as $value => $label): ?><option value="<?= $value ?>" <?= $adFilter['group'] === $value ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></label>
          <label>Sort<select name="sort"><?php foreach (['newest' => 'Active, then newest','priority' => 'Highest priority','spend' => 'Highest spend','impressions' => 'Most impressions','clicks' => 'Most clicks','ctr' => 'Highest CTR','ending' => 'Ending soon'] as $value => $label): ?><option value="<?= $value ?>" <?= $adFilter['sort'] === $value ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></label>
        </div>
        <div class="ad-filter-actions"><button class="btn btn-primary">Apply filters</button><a class="btn btn-ghost" href="<?= url('admin/ads') ?>">Reset</a></div>
      </form>

      <?php
      $timelineAds = rows("SELECT a.*, c.name AS timeline_category_name FROM ads a LEFT JOIN categories c ON c.id = a.category_id WHERE a.status != 'archived' ORDER BY a.priority DESC,a.id LIMIT 1000");
      $timelineRotation = ad_rotation_settings();
      $timelineOrders = ad_priority_orders();
      ?>
      <section class="panel ad-schedule-board" id="ad-placement-timeline" data-csrf="<?= e(csrf_token()) ?>">
        <div class="section-head">
          <div><p class="eyebrow">Incremental inventory calendar</p><h2>Placement timeline</h2><p class="muted small">Scroll left or right to load more dates. In Exclusive mode, drag campaigns vertically—the top campaign serves first.</p></div>
          <div class="ad-timeline-head-actions">
            <button class="btn btn-primary btn-sm" type="button" data-timeline-today>Today</button>
            <button class="btn btn-outline btn-sm" type="button" data-timeline-group aria-pressed="false">Group similar targeting</button>
            <a class="btn btn-outline btn-sm" href="<?= url('admin/ad-placements') ?>">Placement policies</a>
          </div>
        </div>
        <div class="ad-timeline-scale-row"><div></div><div class="ad-timeline-scroll ad-timeline-scale-scroll"><div class="ad-timeline-canvas ad-timeline-scale-canvas"></div></div></div>
        <div class="ad-timeline">
          <?php foreach (AD_PLACEMENTS as $spotKey => $spotInfo): ?>
            <?php
              $laneAds = array_values(array_filter($timelineAds, fn($ad) => $ad['placement'] === 'any' || $ad['placement'] === $spotKey));
              $laneRanks = array_flip($timelineOrders[$spotKey] ?? []);
              usort($laneAds, static function ($left, $right) use ($laneRanks) {
                  $lr = $laneRanks[(int)$left['id']] ?? PHP_INT_MAX;
                  $rr = $laneRanks[(int)$right['id']] ?? PHP_INT_MAX;
                  return $lr !== $rr ? $lr <=> $rr : (((int)$right['priority'] <=> (int)$left['priority']) ?: ((int)$left['id'] <=> (int)$right['id']));
              });
              $isRotating = !empty($timelineRotation[$spotKey]);
            ?>
            <div class="ad-timeline-row">
              <div class="ad-timeline-label">
                <strong><?= e($spotInfo['label']) ?></strong><small><?= count($laneAds) ?> campaign<?= count($laneAds) === 1 ? '' : 's' ?></small>
                <form class="ad-timeline-rotation" method="post"><?= csrf_field() ?><input type="hidden" name="do" value="ad_rotation_toggle"><input type="hidden" name="placement" value="<?= e($spotKey) ?>"><label><input type="checkbox" name="rotation" value="1" <?= $isRotating ? 'checked' : '' ?>><span>Rotate</span></label><small><?= $isRotating ? 'Weighted rotation' : 'Exclusive priority' ?></small></form>
                <a href="<?= url('admin/ads?new=1&placement=' . urlencode($spotKey) . '&starts_at=' . date('Y-m-d\TH:i') . '&ends_at=' . date('Y-m-d\TH:i', strtotime('+7 days'))) ?>">+ Schedule here</a>
              </div>
              <div class="ad-timeline-scroll">
                <div class="ad-timeline-canvas ad-timeline-track" data-placement="<?= e($spotKey) ?>" data-rotation="<?= $isRotating ? '1' : '0' ?>" style="height:<?= max(62, count($laneAds) * 30 + 12) ?>px">
                <?php foreach ($laneAds as $rank => $timelineAd): ?>
                  <?php
                    $timelineTitle = $timelineAd['advertiser_name'] . ' · ' . ($timelineAd['starts_at'] ?: 'open start') . ' to ' . ($timelineAd['ends_at'] ?: 'open ended');
                    $left = 0;
                    $width = 100;
                  ?>
                  <a class="ad-timeline-bar status-<?= e($timelineAd['status']) ?>" style="left:<?= round($left, 2) ?>%;width:<?= round($width, 2) ?>%" href="<?= url('admin/ads?edit=' . $timelineAd['id']) ?>" title="<?= e($timelineAd['advertiser_name'] . ' · ' . ($timelineAd['starts_at'] ?: 'now') . ' to ' . ($timelineAd['ends_at'] ?: 'open ended')) ?>"><span>#<?= (int)$timelineAd['id'] ?> <?= e($timelineAd['title'] ?: $timelineAd['advertiser_name']) ?></span></a>
                <?php endforeach; ?>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
        <script type="application/json" class="ad-timeline-data"><?= json_encode(array_map(static fn($ad) => [
          'id' => (int)$ad['id'], 'starts_at' => $ad['starts_at'], 'ends_at' => $ad['ends_at'],
          'placement' => $ad['placement'] ?: 'any',
          'market_type' => $ad['market_type'] ?: 'any', 'category_id' => (int)($ad['category_id'] ?? 0), 'category_name' => $ad['timeline_category_name'] ?: '',
          'city' => $ad['city'] ?: '', 'subcity' => $ad['subcity'] ?: '', 'pricing_type' => $ad['pricing_type'] ?: '',
        ], $timelineAds), JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
      </section>

      <?php if (!$adsList): ?><div class="empty-state">No campaigns yet — create the first one.</div><?php endif; ?>
      <div class="table-wrap"><table class="data-table">
        <?php if ($adsList): ?><tr><th>Advertiser / Title</th><th>Targeting</th><th>Schedule</th><th>Pricing</th><th>Budget</th><th>Impr.</th><th>Clicks</th><th>CTR</th><th>Status</th><th>Actions</th></tr><?php endif; ?>
        <?php foreach ($adsGroups as $groupLabel => $groupAds): ?>
        <?php if ($adFilter['group'] !== 'none'): ?><tr class="ad-table-group"><td colspan="10"><strong><?= e($groupLabel) ?></strong><span><?= count($groupAds) ?> campaign<?= count($groupAds) === 1 ? '' : 's' ?></span></td></tr><?php endif; ?>
        <?php foreach ($groupAds as $a): ?>
        <tr>
          <td><strong><?= e($a['advertiser_name']) ?></strong><br><span class="muted small"><?= e($a['title'] ?: '—') ?></span></td>
          <td class="small">
            <?= AD_PLACEMENTS[$a['placement']]['label'] ?? 'Any slot' ?><br>
            <?= $a['market_type'] === 'any' ? 'all markets' : e($a['market_type']) ?>
            <?= $a['cat_name'] ? ' · ' . e($a['cat_name']) : '' ?>
            <?= $a['city'] ? ' · 📍' . e($a['subcity'] ? $a['subcity'] . ', ' . $a['city'] : $a['city']) : ' · all cities' ?>
          </td>
          <td class="small">
            <strong><?= $a['status'] === 'active' && (!$a['starts_at'] || strtotime($a['starts_at']) <= time()) && (!$a['ends_at'] || strtotime($a['ends_at']) >= time()) ? 'Live now' : (($a['starts_at'] && strtotime($a['starts_at']) > time()) ? 'Scheduled' : ucfirst($a['status'])) ?></strong><br>
            <?= $a['starts_at'] ? date('M j, Y g:i A', strtotime($a['starts_at'])) : 'Immediate' ?><br>
            <span class="muted">to <?= $a['ends_at'] ? date('M j, Y g:i A', strtotime($a['ends_at'])) : 'No end date' ?></span>
          </td>
          <td class="small"><?= strtoupper($a['pricing_type']) ?> <?= money($a['unit_price']) ?><br>P<?= (int)$a['priority'] ?></td>
          <td class="small"><?= money($a['spent']) ?: '0' ?> / <?= $a['budget'] > 0 ? money($a['budget']) : '∞' ?></td>
          <td><?= number_format($a['impressions_count']) ?></td>
          <td><?= number_format($a['clicks_count']) ?></td>
          <td><?= $a['impressions_count'] > 0 ? round($a['clicks_count'] / $a['impressions_count'] * 100, 1) . '%' : '—' ?></td>
          <td><span class="badge badge-status-<?= e($a['status']) ?>"><?= e($a['status']) ?></span></td>
          <td class="row-actions">
            <a href="<?= url('admin/ads?edit=' . $a['id']) ?>" title="Edit">✏️</a>
            <a href="<?= url('admin/ads?preview=' . $a['id']) ?>" title="Preview without tracking">Preview</a>
            <?php $next = $a['status'] === 'active' ? [['paused', '⏸']] : [['active', '▶️']]; $next[] = ['archived', '🗄']; ?>
            <?php foreach ($next as [$s, $lbl]): ?>
              <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="ad_status"><input type="hidden" name="id" value="<?= $a['id'] ?>"><input type="hidden" name="status" value="<?= $s ?>"><button title="<?= $s ?>"><?= $lbl ?></button></form>
          <?php endforeach; ?>
        </td>
        </tr>
        <?php endforeach; ?>
        <?php endforeach; ?>
      </table></div>

      <div class="panel">
        <h3>Last 14 days</h3>
        <?php $daily = rows("SELECT DATE(created_at) d,
              SUM(event_type='impression') impressions, SUM(event_type='click') clicks
            FROM ad_events WHERE created_at > NOW() - INTERVAL 14 DAY GROUP BY DATE(created_at) ORDER BY d DESC"); ?>
        <?php if (!$daily): ?><p class="muted">No ad traffic yet.</p><?php endif; ?>
        <?php foreach ($daily as $d): ?>
          <div class="bar-row"><span><?= date('D, M j', strtotime($d['d'])) ?></span>
            <b><?= number_format($d['impressions']) ?> views · <?= number_format($d['clicks']) ?> clicks</b></div>
        <?php endforeach; ?>
      </div>

      <div class="panel">
        <h3>🕵️ Suspicious click activity (§9.4)</h3>
        <?php $sus = rows("SELECT ip, ad_id, COUNT(*) n FROM ad_events
            WHERE event_type='click' AND created_at > NOW() - INTERVAL 1 DAY AND ip IS NOT NULL
            GROUP BY ip, ad_id HAVING n >= 5 ORDER BY n DESC LIMIT 20"); ?>
        <?php if (!$sus): ?><p class="muted">Nothing suspicious in the last 24h. (Same-session repeat clicks are already never billed.)</p><?php endif; ?>
        <?php foreach ($sus as $sRow): ?>
          <div class="bar-row"><span><?= e($sRow['ip']) ?> → campaign #<?= $sRow['ad_id'] ?></span><b><?= $sRow['n'] ?> clicks/24h</b></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

  <?php elseif ($section === 'system-ui-optimizer' && $u['account_type'] === 'super_admin'): ?>
    <?php
    $ui = system_ui_config();
    $restrictions = system_restrictions_config();
    $templates = system_ui_templates();
    $sectionLabels = [
        'categories' => 'Browse categories',
        'near' => 'Near you listings',
        'featured' => 'Featured furniture',
        'services' => 'Services row',
        'supplies' => 'Supplies row',
        'cta' => 'Vendor call to action',
    ];
    $uv = fn(string $k) => e($ui[$k] ?? '');
    ?>
    <div class="section-head">
      <div>
        <h1>System UI Optimizer</h1>
        <p class="muted">Tune public web components, homepage content, and page layout from one super-admin builder.</p>
      </div>
      <a class="btn btn-outline" href="<?= url('') ?>" target="_blank">Preview site</a>
    </div>

    <div class="ui-template-library">
      <div class="panel ui-template-save">
        <h3>Save Current Template</h3>
        <form method="post" class="form-inline" id="system-ui-template-form">
          <?= csrf_field() ?>
          <input type="hidden" name="do" value="system_ui_save_template">
          <input name="template_name" placeholder="Template name" required>
          <button class="btn btn-primary btn-sm">Save template</button>
        </form>
      </div>
      <div class="panel ui-template-list">
        <h3>Saved Templates</h3>
        <?php if (!$templates): ?><p class="muted small">No saved UI templates yet.</p><?php endif; ?>
        <?php foreach ($templates as $key => $tpl): ?>
          <div class="ui-template-row">
            <div><strong><?= e($tpl['name'] ?? $key) ?></strong><br><span class="muted small"><?= e(isset($tpl['created_at']) ? date('M j, Y H:i', strtotime($tpl['created_at'])) : '') ?></span></div>
            <form method="post" class="row-actions">
              <?= csrf_field() ?>
              <input type="hidden" name="template_key" value="<?= e($key) ?>">
              <button class="btn btn-outline btn-sm" name="do" value="system_ui_apply_template">Apply</button>
              <button class="btn btn-ghost btn-sm" name="do" value="system_ui_delete_template" onclick="return confirm('Delete this template?')">Delete</button>
            </form>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <form method="post" enctype="multipart/form-data" class="ui-builder" id="system-ui-builder">
      <?= csrf_field() ?>
      <input type="hidden" name="do" id="system-ui-action" value="system_ui_save">

      <aside class="ui-live-dock" id="ui-live-dock">
        <div class="ui-live-toolbar">
          <div>
            <strong>Live Preview</strong>
            <span class="muted small">Updates while you edit</span>
          </div>
          <div class="ui-live-tabs" role="group" aria-label="Preview mode">
            <button type="button" class="current" data-preview-mode="desktop">Desktop</button>
            <button type="button" data-preview-mode="mobile">Mobile</button>
          </div>
        </div>
        <div class="ui-live-frame" id="ui-live-frame">
          <div class="live-page" id="live-page">
            <div class="live-announcement" data-live="announcement">New sellers can open a shop for free.</div>
            <div class="live-header">
              <div class="live-logo"><span class="live-logo-mark" data-live="logoMark">EG</span><span data-live="logoText">EzihGebeya</span></div>
              <div class="live-search">Search furniture...</div>
              <div class="live-nav"><span>Furniture</span><span>Services</span><span>Cart</span></div>
            </div>
            <section class="live-hero">
              <div>
                <h2 data-live="heroTitle"><?= e($ui['hero_title']) ?></h2>
                <p data-live="heroSubtitle"><?= e($ui['hero_subtitle']) ?></p>
                <div class="live-hero-search">Search by item or city <button type="button">Search</button></div>
                <div class="live-chip-row"><span>Furniture</span><span>Services</span><span>Supplies</span></div>
              </div>
              <div class="live-hero-art"></div>
            </section>
            <section class="live-section">
              <div class="live-section-head"><h3>Browse by category</h3><a>View all</a></div>
              <div class="live-cat-row"><span>Sofa</span><span>Services</span><span>Supplies</span></div>
            </section>
            <section class="live-grid">
              <article class="live-card">
                <div class="live-card-img"></div>
                <div class="live-card-body">
                  <span class="live-card-cat">Living Room</span>
                  <h3>Modern lounge sofa</h3>
                  <strong>42,000 ETB</strong>
                  <small>Bole, Addis Ababa</small>
                </div>
              </article>
              <article class="live-form-card">
                <label>Contact seller</label>
                <div class="live-input">Your phone</div>
                <button type="button">Send inquiry</button>
              </article>
            </section>
            <div class="live-ad"><span>Sponsored</span><strong>Premium ad placement</strong></div>
            <footer class="live-footer">EzihGebeya - Ethiopia first, then East Africa.</footer>
          </div>
        </div>
      </aside>

      <div class="ui-builder-grid">
        <section class="panel ui-builder-panel">
          <h3>Theme Tokens</h3>
          <div class="ui-token-grid">
            <?php foreach ([
              'brand' => 'Brand',
              'brand_dark' => 'Brand dark',
              'brand_soft' => 'Brand soft',
              'accent' => 'Accent',
              'accent_soft' => 'Accent soft',
              'ink' => 'Headings',
              'text' => 'Body text',
              'bg' => 'Page background',
              'surface' => 'Surface',
            ] as $key => $label): ?>
              <label><?= $label ?>
                <span class="color-control">
                  <input type="color" name="ui[<?= $key ?>]" value="<?= $uv($key) ?>" data-ui-var="<?= str_replace('_', '-', $key) ?>">
                  <input name="ui[<?= $key ?>]" value="<?= $uv($key) ?>" maxlength="7">
                </span>
              </label>
            <?php endforeach; ?>
          </div>
        </section>

        <section class="panel ui-builder-panel">
          <h3>Brand & Announcement</h3>
          <div class="form-2col">
            <label>Logo mark
              <input name="ui[logo_mark]" maxlength="4" value="<?= $uv('logo_mark') ?>">
            </label>
            <label>Logo text
              <input name="ui[logo_text]" maxlength="32" value="<?= $uv('logo_text') ?>">
            </label>
            <label class="check span2"><input type="checkbox" name="ui[announcement_enabled]" value="1" <?= !empty($ui['announcement_enabled']) ? 'checked' : '' ?>> Show announcement bar</label>
            <label class="span2">Announcement text
              <input name="ui[announcement_text]" maxlength="160" value="<?= $uv('announcement_text') ?>">
            </label>
            <label>Announcement link
              <input name="ui[announcement_url]" maxlength="240" placeholder="/ezihgebeya/register" value="<?= $uv('announcement_url') ?>">
            </label>
            <label>Tone
              <select name="ui[announcement_tone]">
                <?php foreach (['brand' => 'Brand', 'accent' => 'Accent', 'dark' => 'Dark', 'light' => 'Light'] as $k => $label): ?>
                  <option value="<?= $k ?>" <?= $ui['announcement_tone'] === $k ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
              </select>
            </label>
          </div>
        </section>

        <section class="panel ui-builder-panel">
          <h3>Components</h3>
          <label>Theme mode
            <select name="ui[theme_mode]">
              <?php foreach (['light' => 'Light', 'soft-dark' => 'Soft dark', 'high-contrast' => 'High contrast'] as $k => $label): ?>
                <option value="<?= $k ?>" <?= $ui['theme_mode'] === $k ? 'selected' : '' ?>><?= $label ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Font family
            <select name="ui[font_family]">
              <?php foreach (['inter' => 'Inter', 'system' => 'System UI', 'rounded' => 'Rounded UI', 'serif' => 'Editorial serif'] as $k => $label): ?>
                <option value="<?= $k ?>" <?= $ui['font_family'] === $k ? 'selected' : '' ?>><?= $label ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Font scale
            <input type="range" name="ui[font_scale]" min="88" max="116" value="<?= (int)$ui['font_scale'] ?>">
          </label>
          <label>Button radius
            <input type="range" name="ui[button_radius]" min="4" max="999" value="<?= (int)$ui['button_radius'] ?>" data-preview-style="buttonRadius">
          </label>
          <label>Card radius
            <input type="range" name="ui[card_radius]" min="6" max="28" value="<?= (int)$ui['card_radius'] ?>" data-preview-style="cardRadius">
          </label>
          <label>Panel radius
            <input type="range" name="ui[panel_radius]" min="6" max="28" value="<?= (int)$ui['panel_radius'] ?>" data-preview-style="panelRadius">
          </label>
          <label>Shadow strength
            <input type="range" name="ui[shadow_strength]" min="0" max="80" value="<?= (int)$ui['shadow_strength'] ?>">
          </label>
          <label>Border width
            <input type="range" name="ui[border_width]" min="0" max="3" value="<?= (int)$ui['border_width'] ?>">
          </label>
          <label>Focus style
            <select name="ui[focus_style]">
              <?php foreach (['ring' => 'Ring', 'underline' => 'Underline', 'glow' => 'Glow'] as $k => $label): ?>
                <option value="<?= $k ?>" <?= $ui['focus_style'] === $k ? 'selected' : '' ?>><?= $label ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Component density
            <select name="ui[component_density]">
              <?php foreach (['compact' => 'Compact', 'comfortable' => 'Comfortable', 'spacious' => 'Spacious'] as $k => $label): ?>
                <option value="<?= $k ?>" <?= $ui['component_density'] === $k ? 'selected' : '' ?>><?= $label ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Icon pack
            <select name="ui[icon_pack]">
              <?php foreach (['line' => 'Line SVG', 'solid' => 'Solid SVG', 'emoji' => 'Symbol / emoji', 'initials' => 'Initial letters'] as $k => $label): ?>
                <option value="<?= $k ?>" <?= $ui['icon_pack'] === $k ? 'selected' : '' ?>><?= $label ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Input radius
            <input type="range" name="ui[input_radius]" min="4" max="24" value="<?= (int)$ui['input_radius'] ?>">
          </label>
          <label>Card image ratio
            <select name="ui[card_image_ratio]">
              <?php foreach (['1/1' => 'Square', '4/3' => 'Marketplace', '3/2' => 'Wide card', '16/9' => 'Cinematic'] as $k => $label): ?>
                <option value="<?= $k ?>" <?= $ui['card_image_ratio'] === $k ? 'selected' : '' ?>><?= $label ?></option>
              <?php endforeach; ?>
            </select>
          </label>
        </section>

        <section class="panel ui-builder-panel">
          <h3>Layout</h3>
          <label>Container width
            <input type="range" name="ui[container_width]" min="960" max="1480" step="20" value="<?= (int)$ui['container_width'] ?>">
          </label>
          <label>Section spacing
            <input type="range" name="ui[section_spacing]" min="18" max="70" value="<?= (int)$ui['section_spacing'] ?>">
          </label>
          <label>Grid card width
            <input type="range" name="ui[grid_min_width]" min="160" max="320" step="5" value="<?= (int)$ui['grid_min_width'] ?>">
          </label>
          <label>Header behavior
            <select name="ui[header_behavior]">
              <?php foreach (['sticky' => 'Sticky', 'static' => 'Static', 'floating' => 'Floating'] as $k => $label): ?>
                <option value="<?= $k ?>" <?= $ui['header_behavior'] === $k ? 'selected' : '' ?>><?= $label ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Footer style
            <select name="ui[footer_style]">
              <?php foreach (['dark' => 'Dark', 'light' => 'Light', 'brand' => 'Brand gradient'] as $k => $label): ?>
                <option value="<?= $k ?>" <?= $ui['footer_style'] === $k ? 'selected' : '' ?>><?= $label ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Mobile nav style
            <select name="ui[mobile_nav_style]">
              <?php foreach (['pill' => 'Pill', 'minimal' => 'Minimal', 'boxed' => 'Floating boxed'] as $k => $label): ?>
                <option value="<?= $k ?>" <?= $ui['mobile_nav_style'] === $k ? 'selected' : '' ?>><?= $label ?></option>
              <?php endforeach; ?>
            </select>
          </label>
        </section>

        <section class="panel ui-builder-panel">
          <h3>Navigation, Forms & Ads</h3>
          <label>Navigation style
            <select name="ui[nav_style]">
              <?php foreach (['glass' => 'Glass header', 'solid' => 'Solid header', 'dark' => 'Dark header'] as $k => $label): ?>
                <option value="<?= $k ?>" <?= $ui['nav_style'] === $k ? 'selected' : '' ?>><?= $label ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Form style
            <select name="ui[form_style]">
              <?php foreach (['soft' => 'Soft', 'outlined' => 'Outlined', 'filled' => 'Filled'] as $k => $label): ?>
                <option value="<?= $k ?>" <?= $ui['form_style'] === $k ? 'selected' : '' ?>><?= $label ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Ad component style
            <select name="ui[ad_style]">
              <?php foreach (['clean' => 'Clean', 'boxed' => 'Boxed sponsor', 'premium' => 'Premium glow'] as $k => $label): ?>
                <option value="<?= $k ?>" <?= $ui['ad_style'] === $k ? 'selected' : '' ?>><?= $label ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Table style
            <select name="ui[table_style]">
              <?php foreach (['soft' => 'Soft', 'striped' => 'Striped', 'compact' => 'Compact'] as $k => $label): ?>
                <option value="<?= $k ?>" <?= $ui['table_style'] === $k ? 'selected' : '' ?>><?= $label ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Badge shape
            <select name="ui[badge_style]">
              <?php foreach (['pill' => 'Pill', 'square' => 'Squared', 'soft' => 'Soft brand'] as $k => $label): ?>
                <option value="<?= $k ?>" <?= $ui['badge_style'] === $k ? 'selected' : '' ?>><?= $label ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Search style
            <select name="ui[search_style]">
              <?php foreach (['rounded' => 'Rounded', 'box' => 'Box', 'underline' => 'Underline'] as $k => $label): ?>
                <option value="<?= $k ?>" <?= $ui['search_style'] === $k ? 'selected' : '' ?>><?= $label ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Hover motion
            <select name="ui[hover_motion]">
              <?php foreach (['lift' => 'Lift', 'soft' => 'Soft shadow', 'none' => 'No motion'] as $k => $label): ?>
                <option value="<?= $k ?>" <?= $ui['hover_motion'] === $k ? 'selected' : '' ?>><?= $label ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Button style
            <select name="ui[button_style]">
              <?php foreach (['solid' => 'Solid', 'gradient' => 'Gradient', 'flat' => 'Flat'] as $k => $label): ?>
                <option value="<?= $k ?>" <?= $ui['button_style'] === $k ? 'selected' : '' ?>><?= $label ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Image treatment
            <select name="ui[image_treatment]">
              <?php foreach (['natural' => 'Natural', 'warm' => 'Warm', 'cool' => 'Cool', 'mono' => 'Mono'] as $k => $label): ?>
                <option value="<?= $k ?>" <?= $ui['image_treatment'] === $k ? 'selected' : '' ?>><?= $label ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Image hover
            <select name="ui[image_hover]">
              <?php foreach (['zoom' => 'Zoom', 'fade' => 'Fade', 'none' => 'None'] as $k => $label): ?>
                <option value="<?= $k ?>" <?= $ui['image_hover'] === $k ? 'selected' : '' ?>><?= $label ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Section heading style
            <select name="ui[section_head_style]">
              <?php foreach (['plain' => 'Plain', 'rule' => 'Rule', 'boxed' => 'Boxed'] as $k => $label): ?>
                <option value="<?= $k ?>" <?= $ui['section_head_style'] === $k ? 'selected' : '' ?>><?= $label ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Browse filters
            <select name="ui[filters_behavior]">
              <?php foreach (['sticky' => 'Sticky', 'static' => 'Static'] as $k => $label): ?>
                <option value="<?= $k ?>" <?= $ui['filters_behavior'] === $k ? 'selected' : '' ?>><?= $label ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Price style
            <select name="ui[price_style]">
              <?php foreach (['standard' => 'Standard', 'brand' => 'Brand', 'accent' => 'Accent', 'dark' => 'Dark'] as $k => $label): ?>
                <option value="<?= $k ?>" <?= $ui['price_style'] === $k ? 'selected' : '' ?>><?= $label ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Empty state style
            <select name="ui[empty_state_style]">
              <?php foreach (['dashed' => 'Dashed', 'soft' => 'Soft brand', 'plain' => 'Plain'] as $k => $label): ?>
                <option value="<?= $k ?>" <?= $ui['empty_state_style'] === $k ? 'selected' : '' ?>><?= $label ?></option>
              <?php endforeach; ?>
            </select>
          </label>
        </section>

        <section class="panel ui-builder-panel">
          <h3>Listing Cards</h3>
          <label>Card style
            <select name="ui[card_style]">
              <?php foreach (['standard' => 'Standard', 'borderless' => 'Borderless', 'outlined' => 'Outlined', 'compact' => 'Compact'] as $k => $label): ?>
                <option value="<?= $k ?>" <?= $ui['card_style'] === $k ? 'selected' : '' ?>><?= $label ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Text alignment
            <select name="ui[card_text_align]">
              <?php foreach (['left' => 'Left', 'center' => 'Center'] as $k => $label): ?>
                <option value="<?= $k ?>" <?= $ui['card_text_align'] === $k ? 'selected' : '' ?>><?= $label ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <?php foreach ([
            'show_card_category' => 'Show category',
            'show_card_price' => 'Show price',
            'show_card_location' => 'Show location',
            'show_card_vendor' => 'Show vendor',
            'show_featured_badge' => 'Show featured badge',
          ] as $key => $label): ?>
            <label class="check"><input type="checkbox" name="ui[<?= $key ?>]" value="1" <?= !empty($ui[$key]) ? 'checked' : '' ?>> <?= $label ?></label>
          <?php endforeach; ?>
        </section>

        <section class="panel ui-builder-panel">
          <h3>Button Badges</h3>
          <label class="check"><input type="checkbox" name="ui[button_badge_enabled]" value="1" <?= !empty($ui['button_badge_enabled']) ? 'checked' : '' ?>> Enable button badge</label>
          <label>Badge text
            <input name="ui[button_badge_text]" maxlength="18" value="<?= $uv('button_badge_text') ?>" data-preview-text="buttonBadge">
          </label>
          <label>Show on
            <select name="ui[button_badge_target]">
              <?php foreach (['join' => 'Sell / Join buttons', 'account' => 'Account/dashboard buttons', 'primary' => 'Primary CTA buttons', 'all' => 'All configured buttons'] as $k => $label): ?>
                <option value="<?= $k ?>" <?= $ui['button_badge_target'] === $k ? 'selected' : '' ?>><?= $label ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Tone
            <select name="ui[button_badge_tone]">
              <?php foreach (['accent' => 'Accent', 'brand' => 'Brand', 'dark' => 'Dark', 'danger' => 'Danger'] as $k => $label): ?>
                <option value="<?= $k ?>" <?= $ui['button_badge_tone'] === $k ? 'selected' : '' ?>><?= $label ?></option>
              <?php endforeach; ?>
            </select>
          </label>
        </section>

        <section class="panel ui-builder-panel">
          <h3>System Restrictions</h3>
          <label>Max image upload size
            <input type="number" min="1" max="100" name="restrictions[max_image_upload_mb]" value="<?= (int)$restrictions['max_image_upload_mb'] ?>">
          </label>
          <p class="muted small">Applies to hero images, listings, ads, business images, and payment proofs. Default is 30 MB. Your PHP server limits must also allow the selected size.</p>
        </section>

        <section class="panel ui-builder-panel span2">
          <h3>Homepage Builder</h3>
          <div class="form-2col">
            <label>Hero headline
              <input name="ui[hero_title]" maxlength="160" value="<?= $uv('hero_title') ?>" data-preview-text="heroTitle">
            </label>
            <label>Hero image URL
              <span class="ui-url-control">
                <input name="ui[hero_image]" placeholder="/ezihgebeya/uploads/products/demo-1.png or https://..." value="<?= $uv('hero_image') ?>">
                <input type="file" name="hero_image_upload" accept="image/jpeg,image/png,image/webp,image/gif" data-hero-image-upload hidden>
                <button type="button" class="btn btn-outline btn-sm" data-hero-image-upload-btn>Upload image</button>
                <button type="button" class="btn btn-outline btn-sm" data-hero-image-link-btn>Use link</button>
                <button type="button" class="btn btn-ghost btn-sm" data-hero-image-clear>Clear</button>
                <span class="muted small ui-upload-name" data-hero-upload-name></span>
              </span>
            </label>
            <label>Hero background
              <select name="ui[hero_background_mode]">
                <?php foreach (['overlay_image' => 'Image with overlay', 'image' => 'Image only', 'gradient' => 'Gradient only'] as $k => $label): ?>
                  <option value="<?= $k ?>" <?= $ui['hero_background_mode'] === $k ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label>Gradient start
              <input type="color" name="ui[hero_gradient_from]" value="<?= $uv('hero_gradient_from') ?>">
            </label>
            <label>Gradient end
              <input type="color" name="ui[hero_gradient_to]" value="<?= $uv('hero_gradient_to') ?>">
            </label>
            <label class="span2">Hero supporting text
              <input name="ui[hero_subtitle]" maxlength="260" value="<?= $uv('hero_subtitle') ?>" data-preview-text="heroSubtitle">
            </label>
            <label>Hero overlay strength
              <input type="range" min="20" max="92" name="ui[hero_overlay]" value="<?= (int)$ui['hero_overlay'] ?>">
            </label>
            <label>Image position
              <select name="ui[hero_image_position]">
                <?php foreach (['center' => 'Center', 'top' => 'Top', 'bottom' => 'Bottom', 'left' => 'Left', 'right' => 'Right'] as $k => $label): ?>
                  <option value="<?= $k ?>" <?= $ui['hero_image_position'] === $k ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label>Hero alignment
              <select name="ui[hero_align]">
                <?php foreach (['left' => 'Left', 'center' => 'Center', 'split' => 'Split visual'] as $k => $label): ?>
                  <option value="<?= $k ?>" <?= $ui['hero_align'] === $k ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label>Hero height
              <select name="ui[hero_height]">
                <?php foreach (['compact' => 'Compact', 'standard' => 'Standard', 'tall' => 'Tall'] as $k => $label): ?>
                  <option value="<?= $k ?>" <?= $ui['hero_height'] === $k ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label class="check"><input type="checkbox" name="ui[hero_search_enabled]" value="1" <?= !empty($ui['hero_search_enabled']) ? 'checked' : '' ?>> Show hero search</label>
            <label class="check"><input type="checkbox" name="ui[hero_links_enabled]" value="1" <?= !empty($ui['hero_links_enabled']) ? 'checked' : '' ?>> Show hero quick links</label>
            <label class="check"><input type="checkbox" name="ui[hero_stats_enabled]" value="1" <?= !empty($ui['hero_stats_enabled']) ? 'checked' : '' ?>> Show hero stats</label>
            <label>Category tile style
              <select name="ui[category_style]">
                <?php foreach (['rail' => 'Compact rail', 'icon' => 'Icon cards', 'minimal' => 'Minimal links', 'banner' => 'Banner tiles'] as $k => $label): ?>
                  <option value="<?= $k ?>" <?= $ui['category_style'] === $k ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label>Categories shown
              <input type="number" min="4" max="16" name="ui[category_display_limit]" value="<?= (int)$ui['category_display_limit'] ?>">
            </label>
            <label>CTA title
              <input name="ui[cta_title]" maxlength="120" value="<?= $uv('cta_title') ?>">
            </label>
            <label>CTA button
              <input name="ui[cta_button]" maxlength="40" value="<?= $uv('cta_button') ?>">
            </label>
            <label class="span2">CTA text
              <input name="ui[cta_text]" maxlength="240" value="<?= $uv('cta_text') ?>">
            </label>
          </div>

          <div class="ui-section-builder">
            <?php foreach ($sectionLabels as $key => $label): ?>
              <?php $pos = array_search($key, $ui['home_sections'], true); ?>
              <div class="ui-section-row">
                <label class="check">
                  <input type="checkbox" name="ui[hidden_sections][]" value="<?= $key ?>" <?= in_array($key, $ui['hidden_sections'], true) ? 'checked' : '' ?>>
                  Hide
                </label>
                <span><?= $label ?></span>
                <label>Order
                  <input type="number" min="1" max="<?= count($sectionLabels) ?>" name="ui_section_order[<?= $key ?>]" value="<?= $pos === false ? 99 : $pos + 1 ?>">
                </label>
              </div>
            <?php endforeach; ?>
          </div>
        </section>

        <section class="panel ui-builder-panel ui-old-preview">
          <h3>Live Component Preview</h3>
          <div class="ui-preview" id="ui-preview">
            <div class="preview-nav">
              <span class="logo-mark">EG</span>
              <span>Furniture</span>
              <span>Services</span>
              <button type="button" class="btn btn-primary btn-sm">Sell / Join</button>
            </div>
            <div class="preview-card">
              <div class="preview-img"></div>
              <div class="preview-copy">
                <span class="badge badge-featured">Featured</span>
                <h3 id="preview-hero-title"><?= e($ui['hero_title']) ?></h3>
                <p class="muted" id="preview-hero-subtitle"><?= e($ui['hero_subtitle']) ?></p>
                <button type="button" class="btn btn-primary">Primary action <span class="btn-badge btn-badge-accent" id="preview-button-badge"><?= e($ui['button_badge_text']) ?></span></button>
                <button type="button" class="btn btn-outline">Secondary</button>
              </div>
            </div>
            <div class="ui-preview-form">
              <label>Sample form control <input placeholder="Customer name"></label>
              <label>Sample select <select><option>Recommended</option></select></label>
            </div>
            <div class="ui-preview-ad"><span class="ad-label">Sponsored</span><strong>Premium ad placement</strong><span class="muted small">Ad cards, labels and sponsor blocks follow the selected ad style.</span></div>
            <div class="ui-preview-listing">
              <span class="card-cat">Living Room</span>
              <h3>Modern lounge sofa</h3>
              <strong>42,000 ETB</strong>
              <span class="muted small">Bole, Addis Ababa</span>
            </div>
          </div>
        </section>

        <section class="panel ui-builder-panel">
          <h3>Advanced CSS</h3>
          <label>Custom CSS
            <textarea name="ui[custom_css]" rows="12" placeholder=".card-title { ... }"><?= e($ui['custom_css']) ?></textarea>
          </label>
          <p class="muted small">Custom CSS is injected after the design tokens. Keep it scoped when possible.</p>
        </section>
      </div>

      <?php foreach ($ui['home_sections'] as $sectionKey): ?>
        <input type="hidden" name="ui[home_sections][]" value="<?= e($sectionKey) ?>" data-section-hidden-order="<?= e($sectionKey) ?>">
      <?php endforeach; ?>

      <div class="ui-builder-actions">
        <button class="btn btn-primary btn-lg" onclick="document.getElementById('system-ui-action').value='system_ui_save'">Save UI system</button>
        <button class="btn btn-outline" type="submit" onclick="document.getElementById('system-ui-action').value='system_ui_reset'; return confirm('Reset the UI system to defaults?')">Reset defaults</button>
      </div>
    </form>

    <script>
    (function () {
      var form = document.getElementById('system-ui-builder');
      var templateForm = document.getElementById('system-ui-template-form');
      var preview = document.getElementById('ui-preview');
      var liveDock = document.getElementById('ui-live-dock');
      var livePage = document.getElementById('live-page');
      var uploadedHeroPreview = '';
      if (!form || !preview) return;

      var field = function (name) { return form.elements[name]; };
      var value = function (name, fallback) {
        var el = field(name);
        if (!el) return fallback || '';
        if (el.type === 'checkbox') return el.checked ? '1' : '';
        return el.value || fallback || '';
      };
      var checked = function (name) {
        var el = field(name);
        return !!(el && el.checked);
      };
      var setText = function (selector, text) {
        var el = liveDock ? liveDock.querySelector(selector) : null;
        if (el) el.textContent = text;
      };
      var setVisible = function (selector, visible) {
        var el = liveDock ? liveDock.querySelector(selector) : null;
        if (el) el.style.display = visible ? '' : 'none';
      };

      var applyLivePreview = function () {
        if (!liveDock || !livePage) return;
        var brand = value('ui[brand]', '#0f766e');
        var brandDark = value('ui[brand_dark]', '#115e59');
        var brandSoft = value('ui[brand_soft]', '#d9f4ef');
        var accent = value('ui[accent]', '#f97316');
        var ink = value('ui[ink]', '#101828');
        var text = value('ui[text]', '#1f2937');
        var bg = value('ui[bg]', '#f6f8fb');
        var surface = value('ui[surface]', '#ffffff');
        var cardRadius = value('ui[card_radius]', '14') + 'px';
        var panelRadius = value('ui[panel_radius]', '14') + 'px';
        var buttonRadius = value('ui[button_radius]', '999') + 'px';
        var inputRadius = value('ui[input_radius]', '10') + 'px';
        var borderWidth = value('ui[border_width]', '1') + 'px';
        var fontScale = parseInt(value('ui[font_scale]', '100'), 10) || 100;
        var overlay = (parseInt(value('ui[hero_overlay]', '72'), 10) || 72) / 100;
        var heroMode = value('ui[hero_background_mode]', 'overlay_image');
        var heroFrom = value('ui[hero_gradient_from]', '#111827');
        var heroTo = value('ui[hero_gradient_to]', '#0f766e');
        var heroImage = (uploadedHeroPreview || value('ui[hero_image]', '')).replace(/["')\r\n]/g, '');
        var heroPosition = value('ui[hero_image_position]', 'center');

        livePage.style.setProperty('--lp-brand', brand);
        livePage.style.setProperty('--lp-brand-dark', brandDark);
        livePage.style.setProperty('--lp-brand-soft', brandSoft);
        livePage.style.setProperty('--lp-accent', accent);
        livePage.style.setProperty('--lp-ink', ink);
        livePage.style.setProperty('--lp-text', text);
        livePage.style.setProperty('--lp-bg', bg);
        livePage.style.setProperty('--lp-surface', surface);
        livePage.style.setProperty('--lp-card-radius', cardRadius);
        livePage.style.setProperty('--lp-panel-radius', panelRadius);
        livePage.style.setProperty('--lp-button-radius', buttonRadius);
        livePage.style.setProperty('--lp-input-radius', inputRadius);
        livePage.style.setProperty('--lp-border-width', borderWidth);
        livePage.style.setProperty('--lp-font-size', (fontScale / 100 * 12) + 'px');
        livePage.style.setProperty('--lp-hero-overlay', overlay);

        livePage.dataset.theme = value('ui[theme_mode]', 'light');
        livePage.dataset.nav = value('ui[nav_style]', 'glass');
        livePage.dataset.header = value('ui[header_behavior]', 'sticky');
        livePage.dataset.button = value('ui[button_style]', 'solid');
        livePage.dataset.card = value('ui[card_style]', 'standard');
        livePage.dataset.ad = value('ui[ad_style]', 'clean');
        livePage.dataset.footer = value('ui[footer_style]', 'dark');
        livePage.dataset.heroAlign = value('ui[hero_align]', 'left');
        livePage.dataset.heroHeight = value('ui[hero_height]', 'standard');
        livePage.dataset.category = value('ui[category_style]', 'rail');
        livePage.dataset.sectionHead = value('ui[section_head_style]', 'plain');
        livePage.dataset.cardAlign = value('ui[card_text_align]', 'left');
        livePage.dataset.price = value('ui[price_style]', 'standard');
        livePage.dataset.image = value('ui[image_treatment]', 'natural');
        livePage.dataset.search = value('ui[search_style]', 'rounded');

        var liveHero = liveDock.querySelector('.live-hero');
        if (liveHero) {
          if (heroImage && heroMode === 'image') {
            liveHero.style.background = 'url("' + heroImage + '") ' + heroPosition + '/cover no-repeat';
          } else if (heroImage && heroMode === 'overlay_image') {
            liveHero.style.background = 'linear-gradient(115deg, rgba(2,6,23,' + overlay + '), rgba(15,118,110,' + Math.max(0.2, overlay - 0.16) + ')), url("' + heroImage + '") ' + heroPosition + '/cover no-repeat';
          } else {
            liveHero.style.background = 'linear-gradient(115deg, ' + heroFrom + ', ' + heroTo + ')';
          }
        }

        setText('[data-live="logoMark"]', value('ui[logo_mark]', 'EG'));
        setText('[data-live="logoText"]', value('ui[logo_text]', 'EzihGebeya'));
        setText('[data-live="announcement"]', value('ui[announcement_text]', 'New sellers can open a shop for free.'));
        setText('[data-live="heroTitle"]', value('ui[hero_title]', 'Furniture marketplace'));
        setText('[data-live="heroSubtitle"]', value('ui[hero_subtitle]', 'Discover verified furniture sellers near you.'));
        setVisible('.live-announcement', checked('ui[announcement_enabled]'));
        setVisible('.live-hero-search', checked('ui[hero_search_enabled]'));
        setVisible('.live-chip-row', checked('ui[hero_links_enabled]'));
        setVisible('.live-card-cat', checked('ui[show_card_category]'));
        setVisible('.live-card-body strong', checked('ui[show_card_price]'));
        setVisible('.live-card-body small', checked('ui[show_card_location]'));
      };

      form.querySelectorAll('input[type=color]').forEach(function (color) {
        color.addEventListener('input', function () {
          var text = color.parentElement.querySelector('input:not([type=color])');
          if (text) text.value = color.value;
          if (color.dataset.uiVar) document.documentElement.style.setProperty('--' + color.dataset.uiVar, color.value);
        });
      });

      form.querySelectorAll('[data-preview-text]').forEach(function (input) {
        input.addEventListener('input', function () {
          var id = input.dataset.previewText === 'heroTitle' ? 'preview-hero-title' : 'preview-hero-subtitle';
          if (input.dataset.previewText === 'buttonBadge') id = 'preview-button-badge';
          var target = document.getElementById(id);
          if (target) target.textContent = input.value;
        });
      });

      form.addEventListener('input', applyLivePreview);
      form.addEventListener('change', applyLivePreview);
      form.querySelectorAll('[data-hero-image-upload-btn]').forEach(function (button) {
        button.addEventListener('click', function () {
          var fileInput = form.querySelector('[data-hero-image-upload]');
          if (fileInput) fileInput.click();
        });
      });
      form.querySelectorAll('[data-hero-image-upload]').forEach(function (fileInput) {
        fileInput.addEventListener('change', function () {
          var file = fileInput.files && fileInput.files[0] ? fileInput.files[0] : null;
          var label = form.querySelector('[data-hero-upload-name]');
          if (!file) return;
          if (uploadedHeroPreview) URL.revokeObjectURL(uploadedHeroPreview);
          uploadedHeroPreview = URL.createObjectURL(file);
          if (label) label.textContent = file.name;
          applyLivePreview();
        });
      });
      form.querySelectorAll('[data-hero-image-link-btn]').forEach(function (button) {
        button.addEventListener('click', function () {
          var input = field('ui[hero_image]');
          var fileInput = form.querySelector('[data-hero-image-upload]');
          var label = form.querySelector('[data-hero-upload-name]');
          if (fileInput) fileInput.value = '';
          if (uploadedHeroPreview) URL.revokeObjectURL(uploadedHeroPreview);
          uploadedHeroPreview = '';
          if (label) label.textContent = '';
          if (input) {
            input.focus();
            input.select();
          }
          applyLivePreview();
        });
      });
      form.querySelectorAll('[data-hero-image-clear]').forEach(function (button) {
        button.addEventListener('click', function () {
          var input = field('ui[hero_image]');
          var fileInput = form.querySelector('[data-hero-image-upload]');
          var label = form.querySelector('[data-hero-upload-name]');
          if (!input) return;
          input.value = '';
          if (fileInput) fileInput.value = '';
          if (uploadedHeroPreview) URL.revokeObjectURL(uploadedHeroPreview);
          uploadedHeroPreview = '';
          if (label) label.textContent = '';
          input.dispatchEvent(new Event('input', { bubbles: true }));
        });
      });
      applyLivePreview();

      if (liveDock) {
        liveDock.querySelectorAll('[data-preview-mode]').forEach(function (button) {
          button.addEventListener('click', function () {
            liveDock.querySelectorAll('[data-preview-mode]').forEach(function (b) { b.classList.remove('current'); });
            button.classList.add('current');
            liveDock.classList.toggle('mobile-preview', button.dataset.previewMode === 'mobile');
          });
        });
      }

      var syncSectionOrder = function (targetForm) {
        var rows = Array.prototype.slice.call(form.querySelectorAll('.ui-section-row'));
        var sorted = rows.map(function (row) {
          return {
            key: row.querySelector('input[type=checkbox]').value,
            order: parseInt(row.querySelector('input[type=number]').value || '99', 10)
          };
        }).sort(function (a, b) { return a.order - b.order; });
        targetForm.querySelectorAll('[data-section-hidden-order]').forEach(function (el) { el.remove(); });
        sorted.forEach(function (item) {
          var input = document.createElement('input');
          input.type = 'hidden';
          input.name = 'ui[home_sections][]';
          input.value = item.key;
          input.setAttribute('data-section-hidden-order', item.key);
          targetForm.appendChild(input);
        });
      };

      form.addEventListener('submit', function () { syncSectionOrder(form); });

      if (templateForm) {
        templateForm.addEventListener('submit', function () {
          templateForm.querySelectorAll('[data-template-copy]').forEach(function (el) { el.remove(); });
          Array.prototype.forEach.call(form.elements, function (field) {
            if (!field.name || field.name === '_token' || field.name === 'do' || field.disabled) return;
            if ((field.type === 'checkbox' || field.type === 'radio') && !field.checked) return;
            if (field.name === 'ui[home_sections][]') return;
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = field.name;
            input.value = field.value;
            input.setAttribute('data-template-copy', '1');
            templateForm.appendChild(input);
          });
          syncSectionOrder(templateForm);
        });
      }
    })();
    </script>

  <?php elseif ($section === 'users'): ?>
    <?php
      $userTypes = ['customer','seller','manufacturer','importer','service_provider','supplier','admin','super_admin'];
      $userStatuses = ['pending','active','suspended','banned','deleted'];
      $userFilter = [
          'q' => trim((string)($_GET['q'] ?? '')),
          'type' => in_array($_GET['type'] ?? '', $userTypes, true) ? $_GET['type'] : '',
          'status' => in_array($_GET['status'] ?? '', $userStatuses, true) ? $_GET['status'] : '',
          'city' => array_key_exists($_GET['city'] ?? '', CITIES) ? $_GET['city'] : '',
          'joined' => in_array($_GET['joined'] ?? '', ['7','30','90','365'], true) ? (int)$_GET['joined'] : 0,
      ];
      $userPage = max(1, (int)($_GET['page'] ?? 1)); $userPerPage = 50;
      $userWhere = []; $userParams = [];
      if ($userFilter['q'] !== '') {
          $needle = '%' . $userFilter['q'] . '%';
          $userWhere[] = '(full_name LIKE ? OR phone LIKE ? OR email LIKE ? OR CAST(id AS CHAR) = ?)';
          array_push($userParams, $needle, $needle, $needle, $userFilter['q']);
      }
      if ($userFilter['type'] !== '') { $userWhere[] = 'account_type = ?'; $userParams[] = $userFilter['type']; }
      if ($userFilter['status'] !== '') { $userWhere[] = 'status = ?'; $userParams[] = $userFilter['status']; }
      if ($userFilter['joined']) $userWhere[] = 'created_at >= DATE_SUB(NOW(), INTERVAL ' . $userFilter['joined'] . ' DAY)';
      if ($userFilter['city'] !== '') {
          $userWhere[] = 'EXISTS (SELECT 1 FROM orders o_city WHERE o_city.customer_id=users.id AND o_city.city=?)';
          $userParams[] = $userFilter['city'];
      }
      $userWhereSql = $userWhere ? implode(' AND ', $userWhere) : '1=1';
      $userTotal = (int)val("SELECT COUNT(*) FROM users WHERE $userWhereSql", $userParams);
      $userPages = max(1, (int)ceil($userTotal / $userPerPage)); $userPage = min($userPage, $userPages);
      $userOffset = ($userPage - 1) * $userPerPage;
      $list = rows("SELECT users.*,
          (SELECT COUNT(*) FROM orders o WHERE o.customer_id=users.id) order_count,
          (SELECT COALESCE(SUM(o.total),0) FROM orders o WHERE o.customer_id=users.id AND o.status NOT IN ('cancelled','refunded')) order_value,
          (SELECT o.city FROM orders o WHERE o.customer_id=users.id AND o.city IS NOT NULL ORDER BY o.created_at DESC LIMIT 1) recent_city
        FROM users WHERE $userWhereSql ORDER BY created_at DESC LIMIT $userPerPage OFFSET $userOffset", $userParams);
      $userSummary = row("SELECT COUNT(*) total, SUM(account_type='customer') customers, SUM(status='active') active_users,
          SUM(status IN ('suspended','banned')) restricted_users, SUM(created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)) new_30d FROM users");
      $detailId = $u['account_type'] === 'super_admin' ? max(0, (int)($_GET['view'] ?? 0)) : 0;
      $detailUser = $detailId ? row("SELECT u.*,
          (SELECT COUNT(*) FROM remembered_login_tokens rt WHERE rt.user_id=u.id AND rt.expires_at>NOW()) remembered_devices
        FROM users u WHERE u.id=?", [$detailId]) : null;
    ?>
    <div class="section-head"><div><p class="eyebrow">Customer operations</p><h1>Users</h1><p class="muted">Search accounts, review customer history, and handle restrictions and appeals.</p></div></div>
    <div class="admin-user-stats">
      <div class="stat-card"><span>All accounts</span><strong><?= number_format((int)$userSummary['total']) ?></strong></div>
      <div class="stat-card"><span>Customers</span><strong><?= number_format((int)$userSummary['customers']) ?></strong></div>
      <div class="stat-card"><span>Active</span><strong><?= number_format((int)$userSummary['active_users']) ?></strong></div>
      <div class="stat-card"><span>Restricted</span><strong><?= number_format((int)$userSummary['restricted_users']) ?></strong></div>
      <div class="stat-card"><span>Joined in 30 days</span><strong><?= number_format((int)$userSummary['new_30d']) ?></strong></div>
    </div>
    <?php if ($detailUser): ?>
      <?php
        $detailOrders = rows("SELECT o.*, b.business_name FROM orders o JOIN businesses b ON b.id=o.business_id WHERE o.customer_id=? ORDER BY o.created_at DESC LIMIT 30", [$detailId]);
        $detailPayments = rows("SELECT * FROM payments WHERE payer_id=? ORDER BY created_at DESC LIMIT 30", [$detailId]);
        $detailInquiries = rows("SELECT i.*, b.business_name FROM inquiries i JOIN businesses b ON b.id=i.business_id WHERE i.customer_id=? ORDER BY i.created_at DESC LIMIT 30", [$detailId]);
        $detailReviews = rows("SELECT r.*, b.business_name FROM reviews r JOIN businesses b ON b.id=r.business_id WHERE r.reviewer_id=? ORDER BY r.created_at DESC LIMIT 30", [$detailId]);
        $detailSanctions = rows("SELECT s.*, a.full_name admin_name FROM account_sanctions s LEFT JOIN users a ON a.id=s.admin_id WHERE s.user_id=? ORDER BY s.created_at DESC", [$detailId]);
        $detailEvents = rows("SELECT * FROM events WHERE user_id=? ORDER BY created_at DESC LIMIT 40", [$detailId]);
      ?>
      <section class="panel admin-user-detail">
        <div class="section-head">
          <div><p class="eyebrow">Customer #<?= $detailId ?></p><h2><?= e($detailUser['full_name']) ?></h2><p class="muted"><?= e($detailUser['phone'] ?: 'No phone') ?> · <?= e($detailUser['email'] ?: 'No email') ?></p></div>
          <a class="btn btn-outline btn-sm" href="<?= url('admin/users') ?>">Close details</a>
        </div>
        <div class="admin-user-profile-grid">
          <div><span>Role</span><strong><?= e(ucwords(str_replace('_', ' ', $detailUser['account_type']))) ?></strong></div>
          <div><span>Status</span><strong><?= e(ucfirst($detailUser['status'])) ?></strong></div>
          <div><span>Phone</span><strong><?= $detailUser['phone_verified_at'] ? 'Verified' : 'Not verified' ?></strong></div>
          <div><span>Last login</span><strong><?= $detailUser['last_login_at'] ? date('M j, Y g:i A', strtotime($detailUser['last_login_at'])) : 'Never' ?></strong></div>
          <div><span>Joined</span><strong><?= date('M j, Y', strtotime($detailUser['created_at'])) ?></strong></div>
          <div><span>Remembered devices</span><strong><?= (int)$detailUser['remembered_devices'] ?></strong></div>
        </div>
        <div class="admin-user-detail-sections">
          <details open><summary>Orders <span><?= count($detailOrders) ?></span></summary><div class="table-wrap"><table class="data-table"><tr><th>Order</th><th>Business</th><th>Total</th><th>Status</th><th>Date</th></tr><?php foreach ($detailOrders as $item): ?><tr><td><?= e($item['order_number']) ?></td><td><?= e($item['business_name']) ?></td><td><?= money($item['total']) ?></td><td><?= e($item['status']) ?></td><td><?= date('M j, Y', strtotime($item['created_at'])) ?></td></tr><?php endforeach; ?></table><?php if (!$detailOrders): ?><p class="muted small">No orders.</p><?php endif; ?></div></details>
          <details><summary>Payments <span><?= count($detailPayments) ?></span></summary><div class="table-wrap"><table class="data-table"><tr><th>Type</th><th>Amount</th><th>Method</th><th>Status</th><th>Date</th></tr><?php foreach ($detailPayments as $item): ?><tr><td><?= e(str_replace('_', ' ', $item['payment_type'])) ?></td><td><?= money($item['amount']) ?></td><td><?= e($item['payment_method']) ?></td><td><?= e($item['status']) ?></td><td><?= date('M j, Y', strtotime($item['created_at'])) ?></td></tr><?php endforeach; ?></table><?php if (!$detailPayments): ?><p class="muted small">No payments.</p><?php endif; ?></div></details>
          <details><summary>Inquiries <span><?= count($detailInquiries) ?></span></summary><div class="table-wrap"><table class="data-table"><tr><th>Listing</th><th>Business</th><th>Type</th><th>Status</th><th>Date</th></tr><?php foreach ($detailInquiries as $item): ?><tr><td><?= e($item['listing_title'] ?: 'General inquiry') ?></td><td><?= e($item['business_name']) ?></td><td><?= e(str_replace('_', ' ', $item['inquiry_type'])) ?></td><td><?= e($item['status']) ?></td><td><?= date('M j, Y', strtotime($item['created_at'])) ?></td></tr><?php endforeach; ?></table><?php if (!$detailInquiries): ?><p class="muted small">No inquiries.</p><?php endif; ?></div></details>
          <details><summary>Reviews <span><?= count($detailReviews) ?></span></summary><div class="table-wrap"><table class="data-table"><tr><th>Business</th><th>Rating</th><th>Comment</th><th>Status</th><th>Date</th></tr><?php foreach ($detailReviews as $item): ?><tr><td><?= e($item['business_name']) ?></td><td><?= (int)$item['rating'] ?>/5</td><td><?= e(mb_strimwidth((string)$item['comment'], 0, 90, '…')) ?></td><td><?= e($item['status']) ?></td><td><?= date('M j, Y', strtotime($item['created_at'])) ?></td></tr><?php endforeach; ?></table><?php if (!$detailReviews): ?><p class="muted small">No reviews.</p><?php endif; ?></div></details>
          <details><summary>Sanction history <span><?= count($detailSanctions) ?></span></summary><div class="table-wrap"><table class="data-table"><tr><th>Level</th><th>Reason</th><th>Administrator</th><th>Appeal</th><th>Status</th><th>Date</th></tr><?php foreach ($detailSanctions as $item): ?><tr><td><?= e($item['level']) ?></td><td><?= e(str_replace('_', ' ', $item['reason'])) ?></td><td><?= e($item['admin_name'] ?: 'System') ?></td><td><?= e($item['appeal_status']) ?></td><td><?= e($item['status']) ?></td><td><?= date('M j, Y', strtotime($item['created_at'])) ?></td></tr><?php endforeach; ?></table><?php if (!$detailSanctions): ?><p class="muted small">No sanctions.</p><?php endif; ?></div></details>
          <details><summary>Recent activity <span><?= count($detailEvents) ?></span></summary><div class="admin-user-events"><?php foreach ($detailEvents as $item): ?><div><strong><?= e(str_replace('_', ' ', $item['event_type'])) ?></strong><span><?= e($item['listing_type'] ?: 'account') ?><?= $item['listing_id'] ? ' #' . (int)$item['listing_id'] : '' ?></span><small><?= date('M j, Y g:i A', strtotime($item['created_at'])) ?><?= $item['city'] ? ' · ' . e($item['city']) : '' ?></small></div><?php endforeach; ?><?php if (!$detailEvents): ?><p class="muted small">No tracked activity.</p><?php endif; ?></div></details>
        </div>
      </section>
    <?php endif; ?>
    <form class="panel admin-user-filters" method="get" action="<?= url('admin/users') ?>">
      <label class="admin-user-search">Search<input name="q" value="<?= e($userFilter['q']) ?>" placeholder="Name, phone, email, or customer ID"></label>
      <label>Account type<select name="type"><option value="">All account types</option><?php foreach ($userTypes as $value): ?><option value="<?= e($value) ?>" <?= $userFilter['type'] === $value ? 'selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', $value))) ?></option><?php endforeach; ?></select></label>
      <label>Status<select name="status"><option value="">All statuses</option><?php foreach ($userStatuses as $value): ?><option value="<?= e($value) ?>" <?= $userFilter['status'] === $value ? 'selected' : '' ?>><?= e(ucfirst($value)) ?></option><?php endforeach; ?></select></label>
      <label>Customer city<select name="city"><option value="">All cities</option><?php foreach (array_keys(CITIES) as $value): ?><option value="<?= e($value) ?>" <?= $userFilter['city'] === $value ? 'selected' : '' ?>><?= e($value) ?></option><?php endforeach; ?></select></label>
      <label>Joined<select name="joined"><option value="">Any time</option><?php foreach ([7=>'Last 7 days',30=>'Last 30 days',90=>'Last 90 days',365=>'Last year'] as $value=>$label): ?><option value="<?= $value ?>" <?= $userFilter['joined'] === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
      <div class="admin-user-filter-actions"><button class="btn btn-primary">Apply</button><a class="btn btn-ghost" href="<?= url('admin/users') ?>">Reset</a></div>
    </form>
    <div class="admin-user-result-head"><strong><?= number_format($userTotal) ?> account<?= $userTotal === 1 ? '' : 's' ?></strong><span>Page <?= $userPage ?> of <?= $userPages ?></span></div>
    <div class="table-wrap"><table class="data-table">
      <tr><th>Name</th><th>Phone</th><th>Email</th><th>Type</th><th>Customer activity</th><th>Status</th><th>Sanction / Appeal</th><th>Joined</th><th>Actions</th></tr>
      <?php foreach ($list as $usr): ?>
      <?php $sanction = active_account_sanction((int)$usr['id']); ?>
      <tr>
        <td><?= e($usr['full_name']) ?></td>
        <td><?= e($usr['phone']) ?></td>
        <td><?= e($usr['email'] ?: '—') ?></td>
        <td><?= e($usr['account_type']) ?></td>
        <td class="small"><strong><?= (int)$usr['order_count'] ?> order<?= (int)$usr['order_count'] === 1 ? '' : 's' ?></strong><br><span class="muted"><?= money($usr['order_value']) ?><?= $usr['recent_city'] ? ' · ' . e($usr['recent_city']) : '' ?></span></td>
        <td><span class="badge badge-status-<?= e($usr['status']) ?>"><?= e($usr['status']) ?></span></td>
        <td class="small">
          <?php if ($sanction): ?>
            <strong><?= e($sanction['level']) ?></strong> · <?= e(str_replace('_', ' ', $sanction['reason'])) ?>
            <?php if ($sanction['appeal_status'] === 'pending'): ?>
              <form method="post" class="sanction-review-form">
                <?= csrf_field() ?>
                <input type="hidden" name="do" value="sanction_appeal">
                <input type="hidden" name="sanction_id" value="<?= $sanction['id'] ?>">
                <textarea name="appeal_response" rows="2" placeholder="Appeal response"><?= e($sanction['appeal_message']) ?></textarea>
                <button name="appeal_status" value="approved">Approve appeal</button>
                <button name="appeal_status" value="rejected">Reject appeal</button>
              </form>
            <?php elseif ($sanction['appeal_status'] !== 'none'): ?>
              <br><span class="badge badge-muted">appeal <?= e($sanction['appeal_status']) ?></span>
            <?php endif; ?>
          <?php else: ?>—<?php endif; ?>
        </td>
        <td><?= date('M j, Y', strtotime($usr['created_at'])) ?></td>
        <td class="row-actions">
          <?php if ($u['account_type'] === 'super_admin'): ?><a href="<?= url('admin/users?view=' . (int)$usr['id']) ?>">View</a><?php endif; ?>
          <?php if ($usr['id'] != $u['id'] && !in_array($usr['account_type'], ['admin', 'super_admin'])): ?>
            <?php if ($usr['status'] !== 'active'): ?>
              <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="user_status"><input type="hidden" name="id" value="<?= $usr['id'] ?>"><input type="hidden" name="status" value="active"><button title="restore">Restore</button></form>
            <?php endif; ?>
            <?php foreach ([['suspended', 'Suspend'], ['banned', 'Ban']] as [$s, $lbl]): if ($usr['status'] !== $s): ?>
              <details class="reject-popover">
                <summary><?= e($lbl) ?></summary>
                <form method="post" class="reject-form">
                  <?= csrf_field() ?><input type="hidden" name="do" value="user_status"><input type="hidden" name="id" value="<?= $usr['id'] ?>"><input type="hidden" name="status" value="<?= $s ?>">
                  <select name="sanction_reason">
                    <?php foreach (['policy_violation' => 'Policy violation', 'fraud' => 'Fraud/scam', 'spam' => 'Spam', 'abuse' => 'Abuse/harassment', 'payment_fraud' => 'Payment fraud', 'repeat_offender' => 'Repeat offender'] as $rk => $rv): ?>
                      <option value="<?= e($rk) ?>"><?= e($rv) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <input name="admin_note" placeholder="Note shown in appeal context">
                  <button><?= e($lbl) ?> user</button>
                </form>
              </details>
            <?php endif; endforeach; ?>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </table></div>
    <?php if ($userPages > 1): ?>
      <?php $pageQuery = $_GET; unset($pageQuery['page'], $pageQuery['view']); ?>
      <nav class="admin-user-pagination">
        <?php if ($userPage > 1): $pageQuery['page'] = $userPage - 1; ?><a class="btn btn-outline btn-sm" href="<?= url('admin/users?' . http_build_query($pageQuery)) ?>">← Previous</a><?php endif; ?>
        <span>Showing <?= $userOffset + 1 ?>–<?= min($userOffset + $userPerPage, $userTotal) ?> of <?= number_format($userTotal) ?></span>
        <?php if ($userPage < $userPages): $pageQuery['page'] = $userPage + 1; ?><a class="btn btn-outline btn-sm" href="<?= url('admin/users?' . http_build_query($pageQuery)) ?>">Next →</a><?php endif; ?>
      </nav>
    <?php endif; ?>
  <?php elseif ($section === 'categories'): ?>
    <h1>Categories</h1>
    <form method="post" class="panel form-inline">
      <?= csrf_field() ?><input type="hidden" name="do" value="cat_add">
      <input name="name" placeholder="Category name" required>
      <select name="type"><option value="product">product</option><option value="service">service</option><option value="supply">supply</option></select>
      <input name="icon" placeholder="Emoji icon" size="6" maxlength="8">
      <button class="btn btn-primary">Add</button>
    </form>
    <?php $list = rows("SELECT * FROM categories ORDER BY type, sort_order"); ?>
    <div class="table-wrap"><table class="data-table">
      <tr><th>Icon</th><th>Name</th><th>Type</th><th>Slug</th><th>Status</th><th></th><th></th></tr>
      <?php foreach ($list as $c): ?>
      <tr>
        <td><?= $c['icon'] ?></td>
        <td><?= e($c['name']) ?></td>
        <td><?= e($c['type']) ?></td>
        <td class="muted"><?= e($c['slug']) ?></td>
        <td><span class="badge badge-status-<?= $c['status'] === 'active' ? 'active' : 'closed' ?>"><?= e($c['status']) ?></span></td>
        <td><form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="cat_toggle"><input type="hidden" name="id" value="<?= $c['id'] ?>"><button><?= $c['status'] === 'active' ? 'Disable' : 'Enable' ?></button></form></td>
        <td><a href="<?= url('admin/categories') ?>?cat=<?= $c['id'] ?>">Attributes</a></td>
      </tr>
      <?php endforeach; ?>
    </table></div>

    <?php if ($attrCat = (int)($_GET['cat'] ?? 0)): $attrCatRow = row("SELECT * FROM categories WHERE id = ?", [$attrCat]); if ($attrCatRow): ?>
    <div class="panel">
      <h3>Attributes for "<?= e($attrCatRow['name']) ?>"</h3>
      <p class="muted small">These drive the extra fields vendors fill in when posting a listing in this category, and the filters shown on its browse page — beyond the fixed fields (material, brand, color, dimensions, ...) every listing already has.</p>
      <?php $attrs = category_attributes($attrCat); ?>
      <?php if ($attrs): ?>
      <div class="table-wrap"><table class="data-table">
        <tr><th>Key</th><th>Label</th><th>Type</th><th>Options</th><th>Unit</th><th>Required</th><th>Filterable</th><th>Order</th><th></th></tr>
        <?php foreach ($attrs as $a): ?>
        <tr>
          <td class="muted"><?= e($a['key_name']) ?></td>
          <td><?= e($a['label']) ?></td>
          <td><?= e($a['input_type']) ?></td>
          <td class="small"><?= e(implode(', ', json_decode($a['options'] ?? '[]', true) ?: [])) ?></td>
          <td><?= e($a['unit'] ?? '') ?></td>
          <td><?= $a['is_required'] ? 'Yes' : 'No' ?></td>
          <td><?= $a['is_filterable'] ? 'Yes' : 'No' ?></td>
          <td><?= (int)$a['sort_order'] ?></td>
          <td><form method="post" onsubmit="return confirm('Remove this attribute? Values already saved on listings are kept but will no longer display.')">
            <?= csrf_field() ?><input type="hidden" name="do" value="attr_delete"><input type="hidden" name="id" value="<?= $a['id'] ?>"><button>Remove</button></form></td>
        </tr>
        <?php endforeach; ?>
      </table></div>
      <?php else: ?><p class="muted">No attributes defined yet for this category.</p><?php endif; ?>

      <form method="post" class="form-2col section-gap">
        <?= csrf_field() ?><input type="hidden" name="do" value="attr_add"><input type="hidden" name="category_id" value="<?= $attrCat ?>">
        <label>Key <input name="key_name" placeholder="seating_capacity" required>
          <small class="field-hint">Internal identifier stored on the listing — letters/numbers/underscores, never shown to visitors.</small>
        </label>
        <label>Label <input name="label" placeholder="Seating capacity">
          <small class="field-hint">What the vendor and shoppers actually see for this field.</small>
        </label>
        <label>Input type
          <select name="input_type" onchange="this.form.querySelector('[name=options]').closest('label').style.display = this.value === 'select' ? '' : 'none'">
            <option value="text">Text</option>
            <option value="number">Number</option>
            <option value="select">Select (one of a list)</option>
            <option value="boolean">Yes / No</option>
          </select>
          <small class="field-hint">"Select" adds a fixed dropdown (define its choices below); "Number" and "Select" values can be used for browse-page filtering.</small>
        </label>
        <label style="display:none">Options (comma-separated) <input name="options" placeholder="2-seater, 3-seater, corner">
          <small class="field-hint">Only used when Input type is "Select" — these become the dropdown's choices, in order.</small>
        </label>
        <label>Unit <input name="unit" placeholder="cm, kg, seats">
          <small class="field-hint">Optional suffix shown after the value (e.g. "180 cm") — leave blank if the label already makes it clear.</small>
        </label>
        <label>Sort order <input type="number" name="sort_order" value="0">
          <small class="field-hint">Lower numbers show first among this category's attributes.</small>
        </label>
        <div class="check-row">
          <label class="check"><input type="checkbox" name="is_required"> Required</label>
          <label class="check"><input type="checkbox" name="is_filterable" checked> Filterable on browse</label>
        </div>
        <p class="muted small span2">Required blocks the vendor from publishing the listing without this field filled in. Filterable adds it as a filter control on this category's browse page.</p>
        <div class="span2"><button class="btn btn-primary">Add / update attribute</button></div>
      </form>
    </div>
    <?php endif; endif; ?>

  <?php elseif ($section === 'search_synonyms'): ?>
    <h1>🔤 Search Synonyms</h1>
    <p class="muted">Latin↔Amharic word pairs so a search for either finds listings written in the other (e.g. "wenber" also finds ወንበር). Curated marketplace vocabulary, not automatic transliteration — add pairs as real customer search terms come up.</p>
    <form method="post" class="panel form-inline">
      <?= csrf_field() ?><input type="hidden" name="do" value="synonym_add">
      <input name="latin_term" placeholder="Latin, e.g. wenber" required>
      <input name="amharic_term" placeholder="Amharic, e.g. ወንበር" required>
      <button class="btn btn-primary">Add pair</button>
    </form>
    <?php $synonyms = rows("SELECT * FROM search_synonyms ORDER BY latin_term"); ?>
    <div class="table-wrap"><table class="data-table">
      <tr><th>Latin</th><th>Amharic</th><th></th></tr>
      <?php foreach ($synonyms as $s): ?>
      <tr>
        <td><?= e($s['latin_term']) ?></td>
        <td><?= e($s['amharic_term']) ?></td>
        <td><form method="post" onsubmit="return confirm('Remove this synonym pair?')"><?= csrf_field() ?><input type="hidden" name="do" value="synonym_delete"><input type="hidden" name="id" value="<?= $s['id'] ?>"><button>Remove</button></form></td>
      </tr>
      <?php endforeach; ?>
    </table></div>

  <?php elseif ($section === 'software' && $u['account_type'] === 'super_admin'): ?>
    <?php include __DIR__ . '/admin_software.php'; ?>

  <?php elseif (in_array($section, ['verification', 'locations', 'pages', 'analytics', 'audit', 'admins', 'backups', 'settings'], true)): ?>
    <?php include __DIR__ . '/admin_more.php'; ?>
  <?php endif; ?>
  </div>
</div>
<script>
(() => {
  const board = document.getElementById('ad-placement-timeline');
  if (!board) return;
  const DAY = 38, CHUNK = 30, ROW = 30, DAY_MS = 86400000;
  const today = new Date(); today.setHours(0, 0, 0, 0);
  let rangeStart = new Date(today.getTime() - CHUNK * DAY_MS);
  let rangeDays = 90, syncing = false, extending = false, dragged = null, grouped = false;
  const scrolls = [...board.querySelectorAll('.ad-timeline-scroll')];
  const tracks = [...board.querySelectorAll('.ad-timeline-track')];
  const adDates = new Map(JSON.parse(board.querySelector('.ad-timeline-data')?.textContent || '[]').map(ad => [String(ad.id), ad]));

  tracks.forEach(track => {
    [...track.querySelectorAll('.ad-timeline-bar')].forEach((bar, index) => {
      const id = bar.href.match(/edit=(\d+)/)?.[1];
      const dates = adDates.get(id);
      if (id) bar.dataset.adId = id;
      bar.dataset.priorityIndex = index;
      if (dates) {
        bar.dataset.start = dates.starts_at || ''; bar.dataset.end = dates.ends_at || '';
        const parts = [
          dates.placement === 'any' ? 'Any spot' : 'This spot',
          dates.market_type === 'any' ? 'All markets' : dates.market_type,
          dates.category_name || 'All categories',
          dates.city || 'All cities',
          dates.subcity || 'All sub-cities',
          (dates.pricing_type || 'pricing').toUpperCase(),
        ];
        bar.dataset.targetGroup = parts.join(' · ');
      }
    });
  });

  const dateAt = days => new Date(rangeStart.getTime() + days * DAY_MS);
  const dayOffset = date => Math.floor((date - rangeStart) / DAY_MS);
  const keepTitlesVisible = () => {
    tracks.forEach(track => {
      const viewportLeft = track.parentElement.scrollLeft;
      track.querySelectorAll('.ad-timeline-group-label').forEach(label => label.style.left = `${viewportLeft + 8}px`);
      [...track.querySelectorAll('.ad-timeline-bar')].forEach(bar => {
        const maxShift = Math.max(0, bar.offsetWidth - Math.min(120, bar.offsetWidth));
        const shift = Math.max(0, Math.min(maxShift, viewportLeft - bar.offsetLeft + 7));
        bar.style.setProperty('--label-shift', `${shift}px`);
      });
    });
  };
  const goToToday = () => {
    const viewport = scrolls.find(el => !el.classList.contains('ad-timeline-scale-scroll'))?.clientWidth || 0;
    align(Math.max(0, dayOffset(today) * DAY - viewport / 2 + DAY / 2));
  };
  const render = () => {
    const width = rangeDays * DAY;
    board.querySelectorAll('.ad-timeline-canvas').forEach(canvas => canvas.style.width = `${width}px`);
    const scale = board.querySelector('.ad-timeline-scale-canvas');
    scale.innerHTML = '';
    for (let day = 0; day <= rangeDays; day += 7) {
      const date = dateAt(day), tick = document.createElement('span');
      tick.style.left = `${day * DAY}px`;
      tick.textContent = date.toDateString() === today.toDateString() ? 'Today' : date.toLocaleDateString(undefined, {month:'short', day:'numeric'});
      scale.appendChild(tick);
    }
    tracks.forEach(track => {
      track.querySelectorAll('.ad-timeline-group-label').forEach(label => label.remove());
      const bars = [...track.querySelectorAll('.ad-timeline-bar')];
      bars.sort((left, right) => grouped
        ? left.dataset.targetGroup.localeCompare(right.dataset.targetGroup) || Number(left.dataset.priorityIndex) - Number(right.dataset.priorityIndex)
        : Number(left.dataset.priorityIndex) - Number(right.dataset.priorityIndex));
      bars.forEach(bar => track.appendChild(bar));
      let top = 6, lastGroup = '';
      bars.forEach(bar => {
        if (grouped && bar.dataset.targetGroup !== lastGroup) {
          const label = document.createElement('div');
          label.className = 'ad-timeline-group-label';
          label.style.top = `${top}px`;
          label.textContent = bar.dataset.targetGroup;
          track.appendChild(label);
          top += 24;
          lastGroup = bar.dataset.targetGroup;
        }
        const start = bar.dataset.start ? new Date(bar.dataset.start.replace(' ', 'T')) : rangeStart;
        const end = bar.dataset.end ? new Date(bar.dataset.end.replace(' ', 'T')) : dateAt(rangeDays);
        const left = Math.max(0, dayOffset(start)) * DAY;
        const right = Math.min(rangeDays, dayOffset(end) + 1) * DAY;
        bar.style.left = `${left}px`; bar.style.width = `${Math.max(DAY, right - left)}px`; bar.style.top = `${top}px`;
        top += ROW;
        const rank = bar.querySelector('.ad-timeline-rank') || document.createElement('b');
        rank.className = 'ad-timeline-rank'; rank.textContent = `#${Number(bar.dataset.priorityIndex) + 1}`;
        if (!rank.parentNode) bar.prepend(rank);
        bar.draggable = !grouped && track.dataset.rotation !== '1';
      });
      bars.forEach((bar, row) => bar.querySelector('.ad-timeline-rank').textContent = `#${Number(bar.dataset.priorityIndex) + 1}`);
      track.style.height = `${Math.max(62, top + 6)}px`;
    });
    keepTitlesVisible();
  };
  const align = value => {
    syncing = true;
    scrolls.forEach(el => { if (el.scrollLeft !== value) el.scrollLeft = value; });
    keepTitlesVisible();
    requestAnimationFrame(() => syncing = false);
  };
  const extend = (direction, source) => {
    if (extending) return;
    extending = true;
    if (direction < 0) { rangeStart = new Date(rangeStart.getTime() - CHUNK * DAY_MS); rangeDays += CHUNK; render(); align(source.scrollLeft + CHUNK * DAY); }
    else { rangeDays += CHUNK; render(); }
    requestAnimationFrame(() => extending = false);
  };
  scrolls.forEach(scroller => scroller.addEventListener('scroll', () => {
    if (syncing || extending) return;
    align(scroller.scrollLeft);
    if (scroller.scrollLeft < 5 * DAY) extend(-1, scroller);
    else if (scroller.scrollLeft + scroller.clientWidth > scroller.scrollWidth - 5 * DAY) extend(1, scroller);
  }, {passive:true}));
  board.querySelector('[data-timeline-today]')?.addEventListener('click', goToToday);
  board.querySelector('[data-timeline-group]')?.addEventListener('click', event => {
    grouped = !grouped;
    board.classList.toggle('is-grouped', grouped);
    event.currentTarget.setAttribute('aria-pressed', grouped ? 'true' : 'false');
    event.currentTarget.textContent = grouped ? 'Show serving priority' : 'Group similar targeting';
    render();
  });

  const post = body => fetch(location.href, {method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body});
  board.querySelectorAll('.ad-timeline-rotation').forEach(form => form.addEventListener('change', async () => {
    const checkbox = form.querySelector('[name=rotation]'), placement = form.querySelector('[name=placement]').value;
    const body = new FormData(form); if (!checkbox.checked) body.set('rotation', '0');
    const track = board.querySelector(`.ad-timeline-track[data-placement="${CSS.escape(placement)}"]`);
    try {
      const response = await post(body); if (!response.ok) throw new Error();
      track.dataset.rotation = checkbox.checked ? '1' : '0';
      form.querySelector('small').textContent = checkbox.checked ? 'Weighted rotation' : 'Exclusive priority';
      render();
    } catch { checkbox.checked = !checkbox.checked; alert('Could not update this placement. Please try again.'); }
  }));

  tracks.forEach(track => {
    track.addEventListener('dragstart', event => {
      if (grouped || track.dataset.rotation === '1') return event.preventDefault();
      dragged = event.target.closest('.ad-timeline-bar');
      if (!dragged) return;
      dragged.classList.add('is-dragging'); event.dataTransfer.effectAllowed = 'move';
    });
    track.addEventListener('dragover', event => {
      if (!dragged || dragged.parentElement !== track) return;
      event.preventDefault();
      const rect = track.getBoundingClientRect();
      const row = Math.max(0, Math.min(track.children.length - 1, Math.floor((event.clientY - rect.top - 6) / ROW)));
      const target = [...track.querySelectorAll('.ad-timeline-bar')].filter(bar => bar !== dragged)[row];
      if (target) track.insertBefore(dragged, target); else track.appendChild(dragged);
      render();
    });
    track.addEventListener('dragend', async () => {
      if (!dragged) return;
      dragged.classList.remove('is-dragging'); dragged = null;
      [...track.querySelectorAll('.ad-timeline-bar')].forEach((bar, index) => bar.dataset.priorityIndex = index);
      render();
      const body = new FormData();
      body.set('_token', board.dataset.csrf); body.set('do', 'ad_priority_order');
      body.set('placement', track.dataset.placement);
      body.set('order', [...track.querySelectorAll('.ad-timeline-bar')].map(bar => bar.dataset.adId).join(','));
      try { const response = await post(body); if (!response.ok) throw new Error(); }
      catch { alert('The priority order could not be saved. Reload to restore the saved order.'); }
    });
    track.addEventListener('click', event => { if (track.querySelector('.is-dragging')) event.preventDefault(); });
  });
  render();
  requestAnimationFrame(goToToday);
})();
</script>
<script>
(() => {
  const sidebar = document.querySelector('.admin-sidebar');
  const toggle = sidebar?.querySelector('.admin-menu-toggle');
  const closeButton = sidebar?.querySelector('.admin-menu-close');
  const backdrop = sidebar?.querySelector('.admin-menu-backdrop');
  if (!sidebar || !toggle || !backdrop) return;
  const setOpen = open => {
    sidebar.classList.toggle('is-open', open);
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    backdrop.hidden = !open;
    document.body.classList.toggle('admin-menu-open', open);
  };
  toggle.addEventListener('click', () => setOpen(!sidebar.classList.contains('is-open')));
  closeButton?.addEventListener('click', () => setOpen(false));
  backdrop.addEventListener('click', () => setOpen(false));
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape') setOpen(false);
  });
  window.addEventListener('resize', () => {
    if (window.innerWidth > 960) setOpen(false);
  });
})();
</script>
<?php include __DIR__ . '/../views/admin_help.php'; ?>
<?php include __DIR__ . '/../views/layout_bottom.php'; ?>
