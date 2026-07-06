<?php
/** Admin panel. Expects $section: dashboard|businesses|products|services|supplies|videos|reviews|reports|inquiries|users|categories */
$u = require_admin();
$sections = ['dashboard' => '📊 Dashboard', 'businesses' => '🏪 Businesses', 'verification' => '🛡 Verification', 'products' => '🛋️ Products', 'services' => '🛠️ Services',
    'supplies' => '📦 Supplies', 'videos' => '▶ Videos', 'reviews' => '⭐ Reviews', 'reports' => '🚩 Reports',
    'inquiries' => '💬 Inquiries', 'orders' => '🧾 Orders', 'payments' => '💳 Payments', 'promotions' => '📣 Promotions',
    'subscriptions' => '🎫 Subscriptions', 'users' => '👥 Users', 'categories' => '🗂 Categories',
    'locations' => '📍 Locations', 'pages' => '📄 Content Pages', 'analytics' => '📈 Analytics', 'audit' => '🧾 Audit Log'];
if ($u['account_type'] === 'super_admin') {
    $sections['ads'] = 'Ad Manager';
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
        if ($_POST['status'] === 'rejected') notify_business($id, 'verification_rejected', 'Your business registration was rejected', 'vendor/business');
        flash('Business updated.');
    } elseif ($do === 'biz_verify' && in_array($_POST['verification_status'], ['unverified', 'phone_verified', 'document_verified', 'location_verified', 'premium_verified'], true)) {
        q("UPDATE businesses SET verification_status = ? WHERE id = ?", [$_POST['verification_status'], $id]);
        flash('Verification level updated.');
    } elseif ($do === 'listing_status' && isset(LISTING_TABLES[$_POST['ltype'] ?? '']) ) {
        $t = LISTING_TABLES[$_POST['ltype']];
        $allowed = ['active', 'rejected', 'paused', 'pending_review', 'deleted'];
        if (in_array($_POST['status'], $allowed, true)) {
            q("UPDATE `$t` SET status = ? WHERE id = ?", [$_POST['status'], $id]);
            $l = row("SELECT business_id, " . listing_title_col($_POST['ltype']) . " t FROM `$t` WHERE id = ?", [$id]);
            if ($l && $_POST['status'] === 'active') notify_business((int)$l['business_id'], 'listing_approved', '"' . $l['t'] . '" was approved and is now live', 'vendor/listings/' . $_POST['ltype']);
            if ($l && $_POST['status'] === 'rejected') notify_business((int)$l['business_id'], 'listing_rejected', '"' . $l['t'] . '" was rejected by moderation', 'vendor/listings/' . $_POST['ltype']);
            flash('Listing ' . $_POST['status'] . '.');
        }
    } elseif ($do === 'listing_feature' && isset(LISTING_TABLES[$_POST['ltype'] ?? ''])) {
        $t = LISTING_TABLES[$_POST['ltype']];
        q("UPDATE `$t` SET is_featured = 1 - is_featured WHERE id = ?", [$id]);
        flash('Featured toggled.');
    } elseif ($do === 'video_status' && in_array($_POST['status'], ['approved', 'rejected', 'disabled', 'pending', 'deleted'], true)) {
        q("UPDATE video_posts SET status = ? WHERE id = ?", [$_POST['status'], $id]);
        $v = row("SELECT business_id, title FROM video_posts WHERE id = ?", [$id]);
        if ($v && $_POST['status'] === 'approved') notify_business((int)$v['business_id'], 'listing_approved', 'Your video was approved for the feed', 'vendor/videos');
        if ($v && $_POST['status'] === 'rejected') notify_business((int)$v['business_id'], 'listing_rejected', 'Your video was rejected by moderation', 'vendor/videos');
        flash('Video ' . $_POST['status'] . '.');
    } elseif ($do === 'review_status' && in_array($_POST['status'], ['approved', 'rejected', 'hidden'], true)) {
        q("UPDATE reviews SET status = ? WHERE id = ?", [$_POST['status'], $id]);
        if ($_POST['status'] === 'approved') {
            $r = row("SELECT business_id FROM reviews WHERE id = ?", [$id]);
            $agg = row("SELECT AVG(rating) a, COUNT(*) c FROM reviews WHERE business_id = ? AND status = 'approved'", [$r['business_id']]);
            q("UPDATE businesses SET rating_average = ?, rating_count = ? WHERE id = ?", [round($agg['a'], 2), $agg['c'], $r['business_id']]);
            notify_business((int)$r['business_id'], 'review_received', 'You received a new review', 'vendor/reviews');
        }
        flash('Review ' . $_POST['status'] . '.');
    } elseif ($do === 'report_status' && in_array($_POST['status'], ['open', 'reviewing', 'resolved', 'dismissed'], true)) {
        q("UPDATE reports SET status = ?, admin_note = ? WHERE id = ?", [$_POST['status'], trim($_POST['admin_note'] ?? '') ?: null, $id]);
        flash('Report updated.');
    } elseif ($do === 'user_status' && in_array($_POST['status'], ['active', 'suspended', 'banned'], true)) {
        if ($id !== (int)$u['id']) { q("UPDATE users SET status = ? WHERE id = ? AND account_type != 'super_admin'", [$_POST['status'], $id]); flash('User updated.'); }
    } elseif ($do === 'payment_confirm' || $do === 'payment_reject') {
        $p = row("SELECT * FROM payments WHERE id = ? AND status = 'pending'", [$id]);
        if ($p) {
            if ($do === 'payment_confirm') {
                q("UPDATE payments SET status = 'confirmed', confirmed_by = ? WHERE id = ?", [$u['id'], $id]);
                if ($p['order_id']) {
                    q("UPDATE orders SET status = 'deposit_paid' WHERE id = ? AND status IN ('pending','confirmed')", [$p['order_id']]);
                } elseif ($p['promotion_id']) {
                    $promo = row("SELECT * FROM promotions WHERE id = ?", [$p['promotion_id']]);
                    if ($promo && $promo['status'] === 'pending') {
                        q("UPDATE promotions SET status = 'active', starts_at = NOW(), ends_at = NOW() + INTERVAL duration_weeks WEEK WHERE id = ?", [$promo['id']]);
                        promotion_apply($promo, true);
                    }
                } elseif ($p['subscription_id']) {
                    $sub = row("SELECT * FROM subscriptions WHERE id = ?", [$p['subscription_id']]);
                    if ($sub && $sub['status'] === 'pending') {
                        q("UPDATE subscriptions SET status = 'active', starts_at = NOW(), ends_at = NOW() + INTERVAL months MONTH WHERE id = ?", [$sub['id']]);
                        if ($sub['plan'] === 'premium') q("UPDATE businesses SET verification_status = 'premium_verified' WHERE id = ?", [$sub['business_id']]);
                    }
                }
                notify((int)$p['payer_id'], 'payment_received', 'Your payment of ' . money($p['amount']) . ' was confirmed', $p['order_id'] ? 'account/orders' : 'vendor', '', true);
                flash('Payment confirmed and linked item activated.');
            } else {
                q("UPDATE payments SET status = 'rejected', confirmed_by = ? WHERE id = ?", [$u['id'], $id]);
                if ($p['promotion_id']) q("UPDATE promotions SET status = 'rejected' WHERE id = ? AND status = 'pending'", [$p['promotion_id']]);
                if ($p['subscription_id']) q("UPDATE subscriptions SET status = 'rejected' WHERE id = ? AND status = 'pending'", [$p['subscription_id']]);
                flash('Payment rejected.');
            }
        }
    } elseif ($do === 'promo_stop') {
        $promo = row("SELECT * FROM promotions WHERE id = ?", [$id]);
        if ($promo && in_array($promo['status'], ['active', 'pending'], true)) {
            q("UPDATE promotions SET status = 'cancelled' WHERE id = ?", [$id]);
            if ($promo['status'] === 'active') promotion_apply($promo, false);
            flash('Promotion stopped.');
        }
    } elseif ($do === 'order_status' && in_array($_POST['status'] ?? '', ['pending','confirmed','deposit_paid','processing','ready_for_delivery','out_for_delivery','delivered','completed','cancelled','refunded','disputed'], true)) {
        q("UPDATE orders SET status = ? WHERE id = ?", [$_POST['status'], $id]);
        $o = row("SELECT customer_id, order_number FROM orders WHERE id = ?", [$id]);
        if ($o) notify((int)$o['customer_id'], 'order_status_changed', 'Order ' . $o['order_number'] . ' is now ' . str_replace('_', ' ', $_POST['status']), 'account/orders');
        flash('Order updated.');
    } elseif (str_starts_with($do, 'ad_') && $u['account_type'] !== 'super_admin') {
        flash('Ad Manager is restricted to the super admin.', 'error');
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
        if ($fields['advertiser_name'] === '' || $fields['destination_url'] === '') {
            flash('Advertiser name and destination URL are required.', 'error');
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
        q("UPDATE ads SET status = ? WHERE id = ?", [$_POST['status'], $id]);
        flash('Campaign ' . $_POST['status'] . '.');
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
    } else {
        require __DIR__ . '/admin_more_actions.php'; // verification, locations, pages, admins, backups, ad credits
    }
    if ($do !== '') audit($do, $_POST['ltype'] ?? $_POST['reported_type'] ?? $section, $id ?: null,
        implode(' ', array_filter([$_POST['status'] ?? '', $_POST['verification_status'] ?? ''])));
    redirect('admin/' . $section);
}

// ---------- data ----------
$statusFilter = $_GET['status'] ?? '';
include __DIR__ . '/../views/layout_top.php';
?>
<div class="container section dash-layout">
  <aside class="dash-nav">
    <h3>Admin</h3>
    <?php
    $pendCounts = [
        'businesses' => val("SELECT COUNT(*) FROM businesses WHERE status='pending'"),
        'verification' => val("SELECT COUNT(*) FROM verification_requests WHERE status='pending'"),
        'products' => val("SELECT COUNT(*) FROM products WHERE status='pending_review'"),
        'services' => val("SELECT COUNT(*) FROM services WHERE status='pending_review'"),
        'supplies' => val("SELECT COUNT(*) FROM supplies WHERE status='pending_review'"),
        'videos' => val("SELECT COUNT(*) FROM video_posts WHERE status='pending'"),
        'reviews' => val("SELECT COUNT(*) FROM reviews WHERE status='pending'"),
        'reports' => val("SELECT COUNT(*) FROM reports WHERE status='open'"),
        'orders' => val("SELECT COUNT(*) FROM orders WHERE status='pending'"),
        'payments' => val("SELECT COUNT(*) FROM payments WHERE status='pending'"),
    ];
    foreach ($sections as $k => $label): $n = $pendCounts[$k] ?? 0; ?>
      <a href="<?= url('admin/' . $k) ?>" class="<?= $k === $section ? 'current' : '' ?>"><?= $label ?><?= $n ? " <span class='pill'>$n</span>" : '' ?></a>
    <?php endforeach; ?>
  </aside>

  <div class="dash-main">
  <?php if ($section === 'dashboard'): ?>
    <h1>Admin Dashboard</h1>
    <?php $cards = [
        'Total users' => val("SELECT COUNT(*) FROM users"),
        'Businesses' => val("SELECT COUNT(*) FROM businesses WHERE status='active'"),
        'Pending businesses' => $pendCounts['businesses'],
        'Active listings' => val("SELECT (SELECT COUNT(*) FROM products WHERE status='active') + (SELECT COUNT(*) FROM services WHERE status='active') + (SELECT COUNT(*) FROM supplies WHERE status='active')"),
        'Pending listings' => $pendCounts['products'] + $pendCounts['services'] + $pendCounts['supplies'],
        'Pending videos' => $pendCounts['videos'],
        'Open reports' => $pendCounts['reports'],
        'Total inquiries' => val("SELECT COUNT(*) FROM inquiries"),
        'Inquiries (7 days)' => val("SELECT COUNT(*) FROM inquiries WHERE created_at > NOW() - INTERVAL 7 DAY"),
        'Approved videos' => val("SELECT COUNT(*) FROM video_posts WHERE status='approved'"),
        'Video views' => val("SELECT COALESCE(SUM(views_count),0) FROM video_posts"),
        'CTA clicks' => val("SELECT COALESCE(SUM(cta_clicks_count),0) FROM video_posts"),
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
        <td><span class="badge badge-status-<?= e($l['status']) ?>"><?= e(str_replace('_', ' ', $l['status'])) ?></span></td>
        <td>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="listing_feature"><input type="hidden" name="ltype" value="<?= $lt ?>"><input type="hidden" name="id" value="<?= $l['id'] ?>">
            <button><?= $l['is_featured'] ? '★ Featured' : '☆ Feature' ?></button></form>
        </td>
        <td class="row-actions">
          <?php foreach ([['active', '✅'], ['rejected', '❌'], ['paused', '⏸']] as [$s, $lbl]): if ($l['status'] !== $s): ?>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="listing_status"><input type="hidden" name="ltype" value="<?= $lt ?>"><input type="hidden" name="id" value="<?= $l['id'] ?>"><input type="hidden" name="status" value="<?= $s ?>"><button title="<?= $s ?>"><?= $lbl ?></button></form>
          <?php endif; endforeach; ?>
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
            <select name="status">
              <?php foreach (['pending','confirmed','deposit_paid','processing','ready_for_delivery','out_for_delivery','delivered','completed','cancelled','refunded','disputed'] as $s): ?>
                <option value="<?= $s ?>" <?= $o['status'] === $s ? 'selected' : '' ?>><?= str_replace('_', ' ', $s) ?></option>
              <?php endforeach; ?>
            </select>
            <button class="btn btn-outline btn-sm">Set</button>
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
        <td><?php if ($p['proof_image']): ?><a href="<?= e(img_url($p['proof_image'])) ?>" target="_blank">view</a><?php else: ?>—<?php endif; ?></td>
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
          <?php if (in_array($p['status'], ['pending', 'active'], true)): ?>
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

  <?php elseif ($section === 'ads' && $u['account_type'] === 'super_admin'): ?>
    <?php $editAd = isset($_GET['edit']) ? row("SELECT * FROM ads WHERE id = ?", [(int)$_GET['edit']]) : null; ?>

    <?php if ($editAd || isset($_GET['new'])): $av = fn($k, $d = '') => e($editAd[$k] ?? $d); ?>
      <h1><?= $editAd ? 'Edit campaign #' . $editAd['id'] : 'New ad campaign' ?></h1>
      <form class="panel form-2col" method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="ad_save">
        <input type="hidden" name="id" value="<?= (int)($editAd['id'] ?? 0) ?>">
        <label>Advertiser name * <input name="advertiser_name" required value="<?= $av('advertiser_name') ?>"></label>
        <label>Advertiser phone <input name="advertiser_phone" value="<?= $av('advertiser_phone') ?>"></label>
        <label>Ad title <input name="title" maxlength="150" value="<?= $av('title') ?>"></label>
        <label>Destination URL * <input name="destination_url" required placeholder="https://… or /products/some-slug" value="<?= $av('destination_url') ?>"></label>
        <label class="span2">Ad text <input name="body" maxlength="300" value="<?= $av('body') ?>"></label>
        <label>Creative image <input type="file" name="image" accept="image/*">
          <?php if ($editAd && $editAd['image']): ?><span class="muted small">current: <a href="<?= e(img_url($editAd['image'])) ?>" target="_blank">view</a></span><?php endif; ?>
        </label>
        <label>Placement
          <select name="placement">
            <option value="any">Any slot</option>
            <?php foreach (AD_PLACEMENTS as $k => $p): ?>
              <option value="<?= $k ?>" <?= ($editAd['placement'] ?? '') === $k ? 'selected' : '' ?>><?= $p['label'] ?></option>
            <?php endforeach; ?>
          </select>
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
        </label>
        <label>Pricing model
          <select name="pricing_type">
            <?php foreach (AD_PRICING as $k => $l): ?><option value="<?= $k ?>" <?= ($editAd['pricing_type'] ?? 'cpc') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
          </select>
        </label>
        <label>Unit price (ETB) <input type="number" step="0.01" name="unit_price" value="<?= $av('unit_price') ?>"></label>
        <label>Budget cap (ETB, 0 = unlimited) <input type="number" step="0.01" name="budget" value="<?= $av('budget', 0) ?>"></label>
        <label>Priority (1–5, weight in rotation) <input type="number" name="priority" min="1" max="5" value="<?= $av('priority', 1) ?>"></label>
        <label>Starts <input type="datetime-local" name="starts_at" value="<?= $editAd && $editAd['starts_at'] ? date('Y-m-d\TH:i', strtotime($editAd['starts_at'])) : '' ?>"></label>
        <label>Ends <input type="datetime-local" name="ends_at" value="<?= $editAd && $editAd['ends_at'] ? date('Y-m-d\TH:i', strtotime($editAd['ends_at'])) : '' ?>"></label>
        <div class="span2">
          <button class="btn btn-primary"><?= $editAd ? 'Save campaign' : 'Create campaign' ?></button>
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
        <a class="btn btn-primary" href="<?= url('admin/ads?new=1') ?>">+ New campaign</a>
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

      <?php $adsList = rows("SELECT a.*, c.name cat_name FROM ads a LEFT JOIN categories c ON c.id = a.category_id
          WHERE a.status != 'archived' ORDER BY (a.status='active') DESC, a.created_at DESC LIMIT 200"); ?>
      <?php if (!$adsList): ?><div class="empty-state">No campaigns yet — create the first one.</div><?php endif; ?>
      <div class="table-wrap"><table class="data-table">
        <?php if ($adsList): ?><tr><th>Advertiser / Title</th><th>Targeting</th><th>Pricing</th><th>Budget</th><th>Impr.</th><th>Clicks</th><th>CTR</th><th>Status</th><th>Actions</th></tr><?php endif; ?>
        <?php foreach ($adsList as $a): ?>
        <tr>
          <td><strong><?= e($a['advertiser_name']) ?></strong><br><span class="muted small"><?= e($a['title'] ?: '—') ?></span></td>
          <td class="small">
            <?= AD_PLACEMENTS[$a['placement']]['label'] ?? 'Any slot' ?><br>
            <?= $a['market_type'] === 'any' ? 'all markets' : e($a['market_type']) ?>
            <?= $a['cat_name'] ? ' · ' . e($a['cat_name']) : '' ?>
            <?= $a['city'] ? ' · 📍' . e($a['subcity'] ? $a['subcity'] . ', ' . $a['city'] : $a['city']) : ' · all cities' ?>
          </td>
          <td class="small"><?= strtoupper($a['pricing_type']) ?> <?= money($a['unit_price']) ?><br>P<?= (int)$a['priority'] ?></td>
          <td class="small"><?= money($a['spent']) ?: '0' ?> / <?= $a['budget'] > 0 ? money($a['budget']) : '∞' ?></td>
          <td><?= number_format($a['impressions_count']) ?></td>
          <td><?= number_format($a['clicks_count']) ?></td>
          <td><?= $a['impressions_count'] > 0 ? round($a['clicks_count'] / $a['impressions_count'] * 100, 1) . '%' : '—' ?></td>
          <td><span class="badge badge-status-<?= e($a['status']) ?>"><?= e($a['status']) ?></span></td>
          <td class="row-actions">
            <a href="<?= url('admin/ads?edit=' . $a['id']) ?>" title="Edit">✏️</a>
            <?php $next = $a['status'] === 'active' ? [['paused', '⏸']] : [['active', '▶️']]; $next[] = ['archived', '🗄']; ?>
            <?php foreach ($next as [$s, $lbl]): ?>
              <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="ad_status"><input type="hidden" name="id" value="<?= $a['id'] ?>"><input type="hidden" name="status" value="<?= $s ?>"><button title="<?= $s ?>"><?= $lbl ?></button></form>
            <?php endforeach; ?>
          </td>
        </tr>
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
    <h1>Users</h1>
    <?php $list = rows("SELECT * FROM users ORDER BY created_at DESC LIMIT 300"); ?>
    <div class="table-wrap"><table class="data-table">
      <tr><th>Name</th><th>Phone</th><th>Email</th><th>Type</th><th>Status</th><th>Joined</th><th>Actions</th></tr>
      <?php foreach ($list as $usr): ?>
      <tr>
        <td><?= e($usr['full_name']) ?></td>
        <td><?= e($usr['phone']) ?></td>
        <td><?= e($usr['email'] ?: '—') ?></td>
        <td><?= e($usr['account_type']) ?></td>
        <td><span class="badge badge-status-<?= e($usr['status']) ?>"><?= e($usr['status']) ?></span></td>
        <td><?= date('M j, Y', strtotime($usr['created_at'])) ?></td>
        <td class="row-actions">
          <?php if ($usr['id'] != $u['id'] && !in_array($usr['account_type'], ['admin', 'super_admin'])): ?>
            <?php foreach ([['active', '✅'], ['suspended', '⏸'], ['banned', '🚫']] as [$s, $lbl]): if ($usr['status'] !== $s): ?>
              <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="user_status"><input type="hidden" name="id" value="<?= $usr['id'] ?>"><input type="hidden" name="status" value="<?= $s ?>"><button title="<?= $s ?>"><?= $lbl ?></button></form>
            <?php endif; endforeach; ?>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </table></div>

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
      <tr><th>Icon</th><th>Name</th><th>Type</th><th>Slug</th><th>Status</th><th></th></tr>
      <?php foreach ($list as $c): ?>
      <tr>
        <td><?= $c['icon'] ?></td>
        <td><?= e($c['name']) ?></td>
        <td><?= e($c['type']) ?></td>
        <td class="muted"><?= e($c['slug']) ?></td>
        <td><span class="badge badge-status-<?= $c['status'] === 'active' ? 'active' : 'closed' ?>"><?= e($c['status']) ?></span></td>
        <td><form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="cat_toggle"><input type="hidden" name="id" value="<?= $c['id'] ?>"><button><?= $c['status'] === 'active' ? 'Disable' : 'Enable' ?></button></form></td>
      </tr>
      <?php endforeach; ?>
    </table></div>

  <?php elseif (in_array($section, ['verification', 'locations', 'pages', 'analytics', 'audit', 'admins', 'backups', 'settings'], true)): ?>
    <?php include __DIR__ . '/admin_more.php'; ?>
  <?php endif; ?>
  </div>
</div>
<?php include __DIR__ . '/../views/layout_bottom.php'; ?>
