<?php
/**
 * Rendering for the newer admin sections (included from admin.php's section chain):
 * verification queue, locations manager, content pages, platform analytics,
 * audit log, admins & roles, backups. Runs with $u, $section in scope.
 */
$isSuper = $u['account_type'] === 'super_admin';
?>

<?php if ($section === 'verification'): ?>
  <h1>🛡 Verification Requests</h1>
  <p class="muted">Business submits documents → review → approve, reject or request changes (§5.2).</p>
  <?php $reqs = rows("SELECT vr.*, b.business_name, b.slug b_slug, b.verification_status, b.tin_number, b.license_number
        FROM verification_requests vr JOIN businesses b ON b.id = vr.business_id
        ORDER BY (vr.status = 'pending') DESC, vr.created_at DESC LIMIT 100"); ?>
  <?php if (!$reqs): ?><div class="empty-state">No verification requests yet.</div><?php endif; ?>
  <?php foreach ($reqs as $r): $docs = rows("SELECT * FROM verification_documents WHERE request_id = ?", [$r['id']]); ?>
    <div class="panel">
      <div class="inq-head">
        <strong><a href="<?= url('businesses/' . e($r['b_slug'])) ?>" target="_blank"><?= e($r['business_name']) ?></a></strong>
        <span class="badge badge-muted">wants: <?= e(str_replace('_', ' ', $r['requested_level'])) ?></span>
        <span class="badge badge-status-<?= e($r['status']) ?>"><?= e(str_replace('_', ' ', $r['status'])) ?></span>
        <span class="muted"><?= time_ago($r['created_at']) ?></span>
      </div>
      <div class="muted small">Current level: <?= e($r['verification_status']) ?> · TIN: <?= e($r['tin_number'] ?: '—') ?> · License: <?= e($r['license_number'] ?: '—') ?></div>
      <?php if ($r['message']): ?><p class="muted small">📝 <?= e($r['message']) ?></p><?php endif; ?>
      <?php if ($docs): ?>
        <div class="btn-row">
          <?php foreach ($docs as $d): ?>
            <a class="btn btn-ghost btn-sm" target="_blank" href="<?= e(img_url($d['file_url'])) ?>">📄 <?= e(str_replace('_', ' ', $d['doc_type'])) ?></a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <?php if (in_array($r['status'], ['pending', 'changes_requested'], true)): ?>
        <form method="post" class="inq-status-form">
          <?= csrf_field() ?>
          <input type="hidden" name="do" value="vr_review"><input type="hidden" name="id" value="<?= $r['id'] ?>">
          <input name="admin_note" placeholder="Note to the business (required for reject/changes)" style="min-width:280px">
          <button class="btn btn-primary btn-sm" name="status" value="approved">✅ Approve</button>
          <button class="btn btn-outline btn-sm" name="status" value="changes_requested">✏️ Request changes</button>
          <button class="btn btn-outline btn-sm" name="status" value="rejected">🚫 Reject</button>
        </form>
      <?php elseif ($r['admin_note']): ?>
        <p class="muted small">Reviewer note: <?= e($r['admin_note']) ?></p>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>

<?php elseif ($section === 'locations'): ?>
  <h1>📍 Locations</h1>
  <p class="muted">Location hierarchy (§14.1): country → region → city → subcity → woreda/area. Cities and sub-cities added here appear alongside the built-in list.</p>
  <?php $locs = rows("SELECT l.*, p.name parent_name FROM locations l LEFT JOIN locations p ON p.id = l.parent_id
        ORDER BY FIELD(l.level,'country','region','city','subcity','woreda','area'), l.name"); ?>
  <form method="post" class="panel form-inline">
    <?= csrf_field() ?><input type="hidden" name="do" value="loc_add">
    <input name="name" placeholder="Name (e.g. Gondar / Wollo Sefer)" required>
    <select name="level">
      <?php foreach (['country', 'region', 'city', 'subcity', 'woreda', 'area'] as $lv): ?>
        <option <?= $lv === 'city' ? 'selected' : '' ?>><?= $lv ?></option>
      <?php endforeach; ?>
    </select>
    <select name="parent_id">
      <option value="">No parent</option>
      <?php foreach ($locs as $l): ?><option value="<?= $l['id'] ?>"><?= e($l['level'] . ': ' . $l['name']) ?></option><?php endforeach; ?>
    </select>
    <input name="latitude" placeholder="Latitude" size="9">
    <input name="longitude" placeholder="Longitude" size="9">
    <button class="btn btn-primary">Add</button>
  </form>
  <div class="table-wrap"><table class="data-table">
    <tr><th>Name</th><th>Level</th><th>Parent</th><th>Coords</th><th>Status</th><th></th></tr>
    <?php foreach ($locs as $l): ?>
    <tr>
      <td><?= e($l['name']) ?></td>
      <td><?= e($l['level']) ?></td>
      <td class="muted"><?= e($l['parent_name'] ?: '—') ?></td>
      <td class="muted small"><?= $l['latitude'] ? e($l['latitude'] . ', ' . $l['longitude']) : '—' ?></td>
      <td><span class="badge badge-status-<?= $l['status'] === 'active' ? 'active' : 'closed' ?>"><?= e($l['status']) ?></span></td>
      <td><form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="loc_toggle"><input type="hidden" name="id" value="<?= $l['id'] ?>"><button><?= $l['status'] === 'active' ? 'Disable' : 'Enable' ?></button></form></td>
    </tr>
    <?php endforeach; ?>
  </table></div>

<?php elseif ($section === 'pages'): ?>
  <?php $editId = (int)($_GET['edit'] ?? 0);
        $editPage = $editId ? row("SELECT * FROM content_pages WHERE id = ?", [$editId]) : null;
        $pagesList = rows("SELECT * FROM content_pages ORDER BY title"); ?>
  <h1>📄 Content Pages</h1>
  <form method="post" class="panel">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="page_save"><input type="hidden" name="id" value="<?= $editPage['id'] ?? 0 ?>">
    <h3><?= $editPage ? 'Edit: ' . e($editPage['title']) : 'New page' ?></h3>
    <div class="form-inline">
      <label>Title <input name="title" required value="<?= e($editPage['title'] ?? '') ?>"></label>
      <label>Slug <input name="slug" required value="<?= e($editPage['slug'] ?? '') ?>" placeholder="about"></label>
      <label>Status
        <select name="page_status">
          <option value="published" <?= ($editPage['status'] ?? '') !== 'draft' ? 'selected' : '' ?>>published</option>
          <option value="draft" <?= ($editPage['status'] ?? '') === 'draft' ? 'selected' : '' ?>>draft</option>
        </select>
      </label>
    </div>
    <label>Body (plain text/paragraphs; blank lines separate paragraphs)
      <textarea name="body" rows="12"><?= e($editPage['body'] ?? '') ?></textarea>
    </label>
    <div class="btn-row">
      <button class="btn btn-primary"><?= $editPage ? 'Save changes' : 'Create page' ?></button>
      <?php if ($editPage): ?><a class="btn btn-ghost" href="<?= url('admin/pages') ?>">Cancel</a><?php endif; ?>
    </div>
  </form>
  <div class="table-wrap"><table class="data-table">
    <tr><th>Title</th><th>URL</th><th>Status</th><th>Updated</th><th></th></tr>
    <?php foreach ($pagesList as $p): ?>
    <tr>
      <td><?= e($p['title']) ?></td>
      <td><a href="<?= url('page/' . e($p['slug'])) ?>" target="_blank">/page/<?= e($p['slug']) ?></a></td>
      <td><span class="badge badge-status-<?= $p['status'] === 'published' ? 'active' : 'pending' ?>"><?= e($p['status']) ?></span></td>
      <td class="muted"><?= time_ago($p['updated_at']) ?></td>
      <td><a class="btn btn-ghost btn-sm" href="<?= url('admin/pages?edit=' . $p['id']) ?>">Edit</a></td>
    </tr>
    <?php endforeach; ?>
  </table></div>

<?php elseif ($section === 'analytics'): ?>
  <h1>📈 Platform Analytics</h1>
  <?php $cards = [
      'Active users (7d logins)' => val("SELECT COUNT(*) FROM users WHERE last_login_at > NOW() - INTERVAL 7 DAY"),
      'New users (7d)' => val("SELECT COUNT(*) FROM users WHERE created_at > NOW() - INTERVAL 7 DAY"),
      'New businesses (7d)' => val("SELECT COUNT(*) FROM businesses WHERE created_at > NOW() - INTERVAL 7 DAY"),
      'New listings (7d)' => val("SELECT (SELECT COUNT(*) FROM products WHERE created_at > NOW() - INTERVAL 7 DAY) + (SELECT COUNT(*) FROM services WHERE created_at > NOW() - INTERVAL 7 DAY) + (SELECT COUNT(*) FROM supplies WHERE created_at > NOW() - INTERVAL 7 DAY)"),
      'Inquiries (7d)' => val("SELECT COUNT(*) FROM inquiries WHERE created_at > NOW() - INTERVAL 7 DAY"),
      'Orders (30d)' => val("SELECT COUNT(*) FROM orders WHERE created_at > NOW() - INTERVAL 30 DAY"),
      'Revenue this month (confirmed)' => money(val("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='confirmed' AND payment_type != 'order_payment' AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')")) ?: '0 ETB',
      'Video views (30d)' => val("SELECT COUNT(*) FROM video_events WHERE event_type='view' AND created_at > NOW() - INTERVAL 30 DAY"),
      'Video CTA clicks (30d)' => val("SELECT COUNT(*) FROM video_events WHERE event_type='cta_click' AND created_at > NOW() - INTERVAL 30 DAY"),
      'Open reports' => val("SELECT COUNT(*) FROM reports WHERE status='open'"),
  ]; ?>
  <div class="stat-grid">
    <?php foreach ($cards as $label => $n): ?>
      <div class="stat-card"><div class="stat-num"><?= is_numeric($n) ? number_format((float)$n) : $n ?></div><div class="stat-label"><?= $label ?></div></div>
    <?php endforeach; ?>
  </div>

  <div class="panel">
    <h3>Revenue by type (this month, confirmed)</h3>
    <?php $rev = rows("SELECT payment_type, SUM(amount) s FROM payments WHERE status='confirmed' AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01') GROUP BY payment_type ORDER BY s DESC"); ?>
    <?php if (!$rev): ?><p class="muted">No confirmed payments this month.</p><?php endif; ?>
    <?php foreach ($rev as $r): ?><div class="bar-row"><span><?= e(str_replace('_', ' ', $r['payment_type'])) ?></span><b><?= money($r['s']) ?: '0 ETB' ?></b></div><?php endforeach; ?>
  </div>

  <div class="panel">
    <h3>Top vendors by inquiries (30d)</h3>
    <?php foreach (rows("SELECT b.business_name, COUNT(*) n FROM inquiries i JOIN businesses b ON b.id = i.business_id
        WHERE i.created_at > NOW() - INTERVAL 30 DAY GROUP BY b.id ORDER BY n DESC LIMIT 8") as $r): ?>
      <div class="bar-row"><span><?= e($r['business_name']) ?></span><b><?= $r['n'] ?></b></div>
    <?php endforeach; ?>
  </div>

  <div class="panel">
    <h3>Top locations by active listings</h3>
    <?php foreach (rows("SELECT city, COUNT(*) n FROM (
          SELECT city FROM products WHERE status='active' UNION ALL
          SELECT city FROM services WHERE status='active' UNION ALL
          SELECT city FROM supplies WHERE status='active') t WHERE city IS NOT NULL GROUP BY city ORDER BY n DESC LIMIT 8") as $r): ?>
      <div class="bar-row"><span><?= e($r['city']) ?></span><b><?= $r['n'] ?></b></div>
    <?php endforeach; ?>
  </div>

  <div class="panel">
    <h3>🚨 Suspicious activity (§23.2 risky listing detection)</h3>
    <?php
    $flags = [];
    // products priced under 10% of their category average
    foreach (rows("SELECT p.id, p.title, p.price, c.name cat, avg_t.avg_price
        FROM products p JOIN categories c ON c.id = p.category_id
        JOIN (SELECT category_id, AVG(price) avg_price FROM products WHERE status='active' AND price > 0 GROUP BY category_id HAVING COUNT(*) >= 3) avg_t
          ON avg_t.category_id = p.category_id
        WHERE p.status = 'active' AND p.price > 0 AND p.price < avg_t.avg_price * 0.1 LIMIT 10") as $r) {
        $flags[] = 'Price too low: "' . e($r['title']) . '" at ' . money($r['price']) . ' vs category avg ' . money($r['avg_price']) . ' (' . e($r['cat']) . ')';
    }
    // new businesses flooding listings in their first week
    foreach (rows("SELECT b.business_name, COUNT(p.id) n FROM businesses b JOIN products p ON p.business_id = b.id
        WHERE b.created_at > NOW() - INTERVAL 7 DAY GROUP BY b.id HAVING n > 10 LIMIT 10") as $r) {
        $flags[] = 'New seller uploaded ' . $r['n'] . ' products in first week: ' . e($r['business_name']);
    }
    // heavily reported items
    foreach (rows("SELECT reported_type, reported_id, COUNT(*) n FROM reports WHERE status IN ('open','reviewing')
        GROUP BY reported_type, reported_id HAVING n >= 3 LIMIT 10") as $r) {
        $flags[] = ucfirst($r['reported_type']) . ' #' . $r['reported_id'] . ' has ' . $r['n'] . ' open reports';
    }
    // duplicate product titles reused across listings
    foreach (rows("SELECT title, COUNT(*) n FROM products WHERE status='active' GROUP BY title HAVING n >= 4 LIMIT 5") as $r) {
        $flags[] = 'Same product posted ' . $r['n'] . ' times: "' . e($r['title']) . '"';
    }
    // suspicious ad clicks: one IP hammering one ad
    foreach (rows("SELECT ad_id, ip, COUNT(*) n FROM ad_events WHERE event_type='click' AND created_at > NOW() - INTERVAL 1 DAY
        GROUP BY ad_id, ip HAVING n >= 5 LIMIT 10") as $r) {
        $flags[] = 'Ad #' . $r['ad_id'] . ': ' . $r['n'] . ' clicks from ' . e($r['ip']) . ' in 24h (consider a credit adjustment)';
    }
    ?>
    <?php if (!$flags): ?><p class="muted">Nothing suspicious detected right now.</p><?php endif; ?>
    <?php foreach ($flags as $f): ?><div class="bar-row"><span>⚠️ <?= $f ?></span></div><?php endforeach; ?>
  </div>

<?php elseif ($section === 'audit'): ?>
  <h1>🧾 Audit Log</h1>
  <p class="muted">Every admin action is recorded (§22.4.9).</p>
  <?php $logs = rows("SELECT a.*, u2.full_name FROM audit_logs a JOIN users u2 ON u2.id = a.admin_id ORDER BY a.id DESC LIMIT 300"); ?>
  <div class="table-wrap"><table class="data-table">
    <tr><th>When</th><th>Admin</th><th>Action</th><th>Target</th><th>Details</th><th>IP</th></tr>
    <?php foreach ($logs as $l): ?>
    <tr>
      <td class="muted"><?= time_ago($l['created_at']) ?></td>
      <td><?= e($l['full_name']) ?></td>
      <td><b><?= e($l['action']) ?></b></td>
      <td><?= e(trim(($l['target_type'] ?? '') . ' #' . ($l['target_id'] ?? ''), ' #')) ?></td>
      <td class="muted small"><?= e($l['details'] ?: '') ?></td>
      <td class="muted small"><?= e($l['ip'] ?: '') ?></td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$logs): ?><tr><td colspan="6" class="muted">No admin actions logged yet.</td></tr><?php endif; ?>
  </table></div>

<?php elseif ($section === 'settings' && $isSuper): ?>
  <?php $S = system_settings(); $D = system_settings_defaults();
        $chk = fn($path) => sys($path) ? 'checked' : ''; ?>
  <div class="section-head">
    <h1>⚙️ System Settings</h1>
    <form method="post" onsubmit="return confirm('Reset ALL system settings to factory defaults?')">
      <?= csrf_field() ?><input type="hidden" name="do" value="sys_reset">
      <button class="btn btn-outline btn-sm">Reset to defaults</button>
    </form>
  </div>
  <p class="muted">Every switch below controls the live system — pricing, moderation policy, feature modules, ranking weights, security and payments. Changes apply immediately on save.</p>

  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="do" value="sys_save">

    <div class="panel">
      <h3>🏷 General & identity</h3>
      <div class="form-2col">
        <label>Site name <input name="sys[general][site_name]" value="<?= e($S['general']['site_name']) ?>"></label>
        <label>Tagline <input name="sys[general][tagline]" value="<?= e($S['general']['tagline']) ?>"></label>
        <label>Currency label <input name="sys[general][currency_label]" value="<?= e($S['general']['currency_label']) ?>" size="6"></label>
        <label>Default city
          <select name="sys[general][default_city]">
            <?php foreach (array_keys(CITIES) as $c): ?><option <?= $S['general']['default_city'] === $c ? 'selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?>
          </select>
        </label>
        <label>Contact phone <input name="sys[general][contact_phone]" value="<?= e($S['general']['contact_phone']) ?>"></label>
        <label>Contact email <input name="sys[general][contact_email]" value="<?= e($S['general']['contact_email']) ?>"></label>
      </div>
      <div class="check-row">
        <label class="check"><input type="checkbox" name="sys[general][registration_open]" <?= $chk('general.registration_open') ?>> New registrations open</label>
        <label class="check"><input type="checkbox" name="sys[general][maintenance_mode]" <?= $chk('general.maintenance_mode') ?>> 🔧 <b>Maintenance mode</b> (site offline for everyone except admins)</label>
      </div>
      <label>Maintenance message <input name="sys[general][maintenance_message]" value="<?= e($S['general']['maintenance_message']) ?>"></label>
    </div>

    <div class="panel">
      <h3>🧩 Feature modules</h3>
      <p class="muted small">Switch whole modules on/off. Disabled modules disappear from navigation and their routes redirect home.</p>
      <div class="check-row" style="flex-wrap:wrap">
        <?php foreach (['videos' => '▶ Video feed', 'cart' => '🛒 Cart, checkout & orders', 'promotions' => '📣 Vendor promotions',
                        'subscriptions' => '🎫 Subscriptions', 'reviews' => '⭐ Reviews', 'inquiries' => '💬 Inquiries',
                        'ar' => '🪄 AR / 3D preview', 'ads' => '📢 Ad engine', 'api' => '🔌 REST API',
                        'location_detection' => '📍 Location auto-detection'] as $k => $label): ?>
          <label class="check"><input type="checkbox" name="sys[features][<?= $k ?>]" <?= $chk("features.$k") ?>> <?= $label ?></label>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="panel">
      <h3>🛂 Moderation policy (§16.3)</h3>
      <p class="muted small">Unchecked = manual review (spec §30.5 recommends moderating everything at first). Checked = auto-approve and publish instantly.</p>
      <div class="check-row" style="flex-wrap:wrap">
        <label class="check"><input type="checkbox" name="sys[moderation][auto_approve_businesses]" <?= $chk('moderation.auto_approve_businesses') ?>> Auto-approve new businesses</label>
        <label class="check"><input type="checkbox" name="sys[moderation][auto_approve_listings]" <?= $chk('moderation.auto_approve_listings') ?>> Auto-approve listings</label>
        <label class="check"><input type="checkbox" name="sys[moderation][auto_approve_videos]" <?= $chk('moderation.auto_approve_videos') ?>> Auto-approve videos</label>
        <label class="check"><input type="checkbox" name="sys[moderation][auto_approve_reviews]" <?= $chk('moderation.auto_approve_reviews') ?>> Auto-approve reviews</label>
      </div>
    </div>

    <div class="panel">
      <h3>📏 Limits</h3>
      <div class="form-2col">
        <label>Max images per listing <input type="number" name="sys[limits][max_images_per_listing]" min="1" max="20" value="<?= (int)$S['limits']['max_images_per_listing'] ?>"></label>
        <label>Inquiries per visitor per window <input type="number" name="sys[limits][inquiry_rate_max]" min="1" max="50" value="<?= (int)$S['limits']['inquiry_rate_max'] ?>"></label>
        <label>Inquiry rate window (minutes) <input type="number" name="sys[limits][inquiry_rate_window_min]" min="1" max="120" value="<?= (int)$S['limits']['inquiry_rate_window_min'] ?>"></label>
        <label>Video feed size <input type="number" name="sys[limits][video_feed_size]" min="10" max="200" value="<?= (int)$S['limits']['video_feed_size'] ?>"></label>
        <label>AR model max size (MB) <input type="number" name="sys[limits][ar_model_max_mb]" min="1" max="100" value="<?= (int)$S['limits']['ar_model_max_mb'] ?>"></label>
      </div>
    </div>

    <div class="panel">
      <h3>🎫 Subscription plans (§26.2)</h3>
      <div class="table-wrap"><table class="data-table">
        <tr><th>Plan</th><th>Price (ETB/month)</th><th>Listing limit (−1 = unlimited)</th><th>Video limit (−1 = unlimited)</th></tr>
        <?php foreach (PLANS as $k => $p): ?>
        <tr>
          <td><b><?= $p['label'] ?></b></td>
          <td><input type="number" step="0.01" name="sys[plans][<?= $k ?>][price]" value="<?= (float)$S['plans'][$k]['price'] ?>" <?= $k === 'free' ? 'readonly' : '' ?>></td>
          <td><input type="number" name="sys[plans][<?= $k ?>][listings]" min="-1" value="<?= (int)$S['plans'][$k]['listings'] ?>"></td>
          <td><input type="number" name="sys[plans][<?= $k ?>][videos]" min="-1" value="<?= (int)$S['plans'][$k]['videos'] ?>"></td>
        </tr>
        <?php endforeach; ?>
      </table></div>
    </div>

    <div class="panel">
      <h3>📣 Promotion pricing (§9)</h3>
      <div class="table-wrap"><table class="data-table">
        <tr><th>Promotion</th><th>Price (ETB/week)</th></tr>
        <?php foreach (PROMO_TYPES as $k => $p): ?>
        <tr><td><?= $p['label'] ?></td><td><input type="number" step="0.01" name="sys[promos][<?= $k ?>][price]" value="<?= (float)$S['promos'][$k]['price'] ?>"></td></tr>
        <?php endforeach; ?>
      </table></div>
    </div>

    <div class="panel">
      <h3>💳 Payments (§12)</h3>
      <div class="check-row" style="flex-wrap:wrap">
        <label class="check"><input type="checkbox" name="sys[payments][cash_on_delivery]" <?= $chk('payments.cash_on_delivery') ?>> Cash on delivery</label>
        <label class="check"><input type="checkbox" name="sys[payments][bank_transfer]" <?= $chk('payments.bank_transfer') ?>> Bank transfer</label>
        <label class="check"><input type="checkbox" name="sys[payments][telebirr]" <?= $chk('payments.telebirr') ?>> Telebirr</label>
        <label class="check"><input type="checkbox" name="sys[payments][cbe_birr]" <?= $chk('payments.cbe_birr') ?>> CBE Birr</label>
      </div>
      <label>Payment instructions shown to buyers at checkout / upgrade forms
        <textarea name="sys[payments][instructions]" rows="4"><?= e($S['payments']['instructions']) ?></textarea>
      </label>
      <label>Commission per completed order (%) — 0 = commission-free marketplace
        <input type="number" step="0.1" min="0" max="50" name="sys[payments][commission_percent]" value="<?= (float)$S['payments']['commission_percent'] ?>" style="max-width:120px">
      </label>
    </div>

    <div class="panel">
      <h3>🏆 Search ranking weights (§8.4)</h3>
      <p class="muted small">How the "Recommended" sort scores listings. Higher = more influence. Defaults in brackets.</p>
      <div class="form-2col">
        <?php foreach (['city' => 'Same city', 'subcity' => 'Same sub-city', 'keyword' => 'Keyword relevance ×',
                        'verification' => 'Verified seller', 'rating' => 'Rating × (capped at 10)', 'freshness' => 'Freshness (max)',
                        'featured' => 'Featured boost', 'promoted' => 'Promoted boost', 'report_penalty' => 'Penalty per open report'] as $k => $label): ?>
          <label><?= $label ?> [<?= $D['ranking'][$k] ?>] <input type="number" step="0.5" min="0" max="100" name="sys[ranking][<?= $k ?>]" value="<?= (float)$S['ranking'][$k] ?>"></label>
        <?php endforeach; ?>
      </div>
      <h3 class="section-gap">▶ Video feed ranking weights (§6.5)</h3>
      <div class="form-2col">
        <?php foreach (['city' => 'Same city', 'subcity' => 'Same sub-city', 'engagement' => 'Engagement × (log-scaled)',
                        'verification' => 'Verified seller', 'freshness' => 'Freshness (max)', 'rating' => 'Rating × (capped at 10)',
                        'promoted' => 'Promoted boost', 'featured' => 'Featured boost', 'report_penalty' => 'Penalty per report'] as $k => $label): ?>
          <label><?= $label ?> [<?= $D['video_ranking'][$k] ?>] <input type="number" step="0.5" min="0" max="100" name="sys[video_ranking][<?= $k ?>]" value="<?= (float)$S['video_ranking'][$k] ?>"></label>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="panel">
      <h3>🔐 Authentication & security (§22.1)</h3>
      <div class="check-row">
        <label class="check"><input type="checkbox" name="sys[auth][otp_required]" <?= $chk('auth.otp_required') ?>> Require SMS OTP verification after registration</label>
      </div>
      <div class="form-2col">
        <label>Failed logins before lockout <input type="number" min="3" max="50" name="sys[auth][login_max_attempts]" value="<?= (int)$S['auth']['login_max_attempts'] ?>"></label>
        <label>Lockout window (minutes) <input type="number" min="1" max="1440" name="sys[auth][login_lockout_min]" value="<?= (int)$S['auth']['login_lockout_min'] ?>"></label>
        <label>Idle session timeout (minutes) <input type="number" min="10" max="10080" name="sys[auth][session_timeout_min]" value="<?= (int)$S['auth']['session_timeout_min'] ?>"></label>
        <label>Minimum password length <input type="number" min="4" max="64" name="sys[auth][min_password_len]" value="<?= (int)$S['auth']['min_password_len'] ?>"></label>
      </div>
    </div>

    <div class="panel">
      <h3>🔔 Notifications & SMS (§15)</h3>
      <div class="check-row">
        <label class="check"><input type="checkbox" name="sys[notifications][sms_mirror]" <?= $chk('notifications.sms_mirror') ?>> Mirror important notifications to SMS</label>
      </div>
      <label>SMS gateway URL — use <code>{phone}</code> and <code>{message}</code> placeholders; blank = log to <code>database/outbox.log</code> only
        <input name="sys[notifications][sms_gateway_url]" value="<?= e($S['notifications']['sms_gateway_url']) ?>" placeholder="https://sms.example/send?to={phone}&text={message}&token=…">
      </label>
      <label>Email "from" address <input name="sys[notifications][email_from]" value="<?= e($S['notifications']['email_from']) ?>"></label>
      <p class="muted small">DEV mode is <?= DEV_MODE ? '<b>ON</b> — OTP codes are shown on screen and nothing is really sent' : 'off' ?> (toggle via <code>DEV_MODE</code> in config.php).</p>
    </div>

    <div class="panel">
      <h3>🔎 SEO & analytics (§25)</h3>
      <label>Default meta description <input name="sys[seo][meta_description]" value="<?= e($S['seo']['meta_description']) ?>"></label>
      <label>Head snippet (analytics / site-verification tags, injected into every page's <code>&lt;head&gt;</code>)
        <textarea name="sys[seo][head_snippet]" rows="3" placeholder="<script>…</script>"><?= e($S['seo']['head_snippet']) ?></textarea>
      </label>
    </div>

    <button class="btn btn-primary btn-lg">💾 Save all system settings</button>
  </form>

<?php elseif ($section === 'admins' && $isSuper): ?>
  <h1>👮 Admins & Roles</h1>
  <form method="post" class="panel form-inline">
    <?= csrf_field() ?><input type="hidden" name="do" value="admin_create">
    <input name="full_name" placeholder="Full name" required>
    <input name="phone" placeholder="Phone" required>
    <input name="password" type="password" placeholder="Password (min 8)" required minlength="8">
    <select name="role"><option value="admin">admin</option><option value="super_admin">super admin</option></select>
    <button class="btn btn-primary">Create admin</button>
  </form>
  <div class="table-wrap"><table class="data-table">
    <tr><th>Name</th><th>Phone</th><th>Role</th><th>Last login</th><th></th></tr>
    <?php foreach (rows("SELECT * FROM users WHERE account_type IN ('admin','super_admin') ORDER BY account_type DESC, created_at") as $a): ?>
    <tr>
      <td><?= e($a['full_name']) ?></td>
      <td><?= e($a['phone']) ?></td>
      <td><span class="badge badge-muted"><?= e($a['account_type']) ?></span></td>
      <td class="muted"><?= $a['last_login_at'] ? time_ago($a['last_login_at']) : 'never' ?></td>
      <td>
        <?php if ($a['account_type'] === 'admin'): ?>
          <form method="post" onsubmit="return confirm('Revoke admin rights for <?= e($a['full_name']) ?>?')">
            <?= csrf_field() ?><input type="hidden" name="do" value="admin_revoke"><input type="hidden" name="id" value="<?= $a['id'] ?>">
            <button class="btn btn-outline btn-sm">Revoke</button>
          </form>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </table></div>

<?php elseif ($section === 'backups' && $isSuper): ?>
  <h1>Database Toolkit</h1>
  <p class="muted">Create empty databases, heal server schemas after local changes, export backups, and run basic table maintenance.</p>

  <?php
    $tableStats = rows("SELECT table_name t, ROUND((data_length+index_length)/1024) kb, table_rows r
        FROM information_schema.tables WHERE table_schema = ? ORDER BY table_name", [DB_NAME]);
    $totalKb = array_sum(array_map(fn($r) => (float)$r['kb'], $tableStats));
    $migrationRows = [];
    try { $migrationRows = rows("SELECT * FROM db_migrations ORDER BY migration"); } catch (Throwable $e) {}
    $upgradeFiles = array_map('basename', glob(__DIR__ . '/../database/upgrade*.sql') ?: []);
    sort($upgradeFiles, SORT_NATURAL);
  ?>

  <div class="stat-grid">
    <div class="stat-card"><div class="stat-num"><?= count($tableStats) ?></div><div class="stat-label">Tables in <?= e(DB_NAME) ?></div></div>
    <div class="stat-card"><div class="stat-num"><?= number_format($totalKb / 1024, 2) ?> MB</div><div class="stat-label">Database size</div></div>
    <div class="stat-card"><div class="stat-num"><?= count($upgradeFiles) ?></div><div class="stat-label">Migration files</div></div>
    <div class="stat-card"><div class="stat-num"><?= count($migrationRows) ?></div><div class="stat-label">Recorded migration runs</div></div>
  </div>

  <div class="panel">
    <h3>1. Create Empty Database From Credentials</h3>
    <p class="muted small">Creates the database and empty tables from <code>database/setup.sql</code>. Use this for a new server/database. If the target already has tables, this can drop/recreate schema from the setup file.</p>
    <form method="post" class="form-2col" onsubmit="return confirm('Create or replace the target database schema? Make sure you selected the correct database.')">
      <?= csrf_field() ?><input type="hidden" name="do" value="db_install_schema">
      <label>Host <input name="db_host" value="<?= e(DB_HOST) ?>" required></label>
      <label>Database name <input name="db_name" value="<?= e(DB_NAME) ?>" required></label>
      <label>User <input name="db_user" value="<?= e(DB_USER) ?>" required></label>
      <label>Password <input type="password" name="db_pass" placeholder="Database password"></label>
      <label class="span2">Type confirmation
        <input name="confirm_install" placeholder="CREATE EMPTY DATABASE" required>
      </label>
      <div class="span2"><button class="btn btn-outline">Create empty schema</button></div>
    </form>
  </div>

  <div class="panel">
    <h3>2. Heal / Migrate Current Database</h3>
    <p class="muted small">Runs all <code>database/upgrade*.sql</code> files against the current configured database. Already-existing columns/tables are skipped where safe.</p>
    <form method="post" onsubmit="return confirm('Run all database upgrade scripts on <?= e(DB_NAME) ?>? Take a backup first if this is production.')">
      <?= csrf_field() ?><input type="hidden" name="do" value="db_run_migrations">
      <button class="btn btn-primary">Run migrations / heal schema</button>
    </form>
    <div class="section-gap">
      <?php if ($upgradeFiles): ?><p class="muted small">Available files: <?= e(implode(', ', $upgradeFiles)) ?></p><?php endif; ?>
      <?php if ($migrationRows): ?>
        <div class="table-wrap"><table class="data-table">
          <tr><th>Migration</th><th>Status</th><th>Statements</th><th>Skipped</th><th>Applied</th></tr>
          <?php foreach ($migrationRows as $m): ?>
            <tr><td><?= e($m['migration']) ?></td><td><?= e($m['status']) ?></td><td><?= (int)$m['statements_run'] ?></td><td><?= (int)$m['skipped_errors'] ?></td><td class="muted"><?= e($m['applied_at']) ?></td></tr>
          <?php endforeach; ?>
        </table></div>
      <?php endif; ?>
    </div>
  </div>

  <div class="panel">
    <h3>3. Backup Database</h3>
    <p class="muted small">Download a SQL dump. Full backup includes data. Schema-only backup is useful for moving structure without user/listing data.</p>
    <form method="post" class="form-inline">
      <?= csrf_field() ?><input type="hidden" name="do" value="backup_download">
      <select name="backup_mode">
        <option value="full">Full backup with data</option>
        <option value="schema">Schema only, no data</option>
      </select>
      <button class="btn btn-primary">Download SQL backup</button>
    </form>
  </div>

  <div class="panel">
    <h3>4. Repair / Optimize Tables</h3>
    <p class="muted small">Runs MySQL <code>REPAIR TABLE</code> and <code>OPTIMIZE TABLE</code> on every table in the current database.</p>
    <form method="post" onsubmit="return confirm('Run repair/optimize on all tables?')">
      <?= csrf_field() ?><input type="hidden" name="do" value="db_optimize">
      <button class="btn btn-outline">Repair and optimize tables</button>
    </form>
  </div>

  <div class="panel">
    <h3>Database Tables</h3>
    <div class="table-wrap"><table class="data-table">
      <tr><th>Table</th><th>Rows</th><th>Size</th></tr>
      <?php foreach ($tableStats as $r): ?>
        <tr><td><?= e($r['t']) ?></td><td><?= number_format((float)$r['r']) ?></td><td><?= number_format((float)$r['kb']) ?> KB</td></tr>
      <?php endforeach; ?>
    </table></div>
  </div>
  <?php if (false): ?>
  <h1>💾 Backups</h1>
  <div class="panel">
    <p>Download a full SQL dump of the <b><?= DB_NAME ?></b> database. Store it off-server; on cPanel schedule the same via cron:
      <code>mysqldump -u <?= DB_USER ?> <?= DB_NAME ?> &gt; backup-$(date +%F).sql</code></p>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="do" value="backup_download">
      <button class="btn btn-primary">⬇ Download SQL backup now</button>
    </form>
  </div>
  <div class="panel">
    <h3>Database size</h3>
    <?php foreach (rows("SELECT table_name t, ROUND((data_length+index_length)/1024) kb, table_rows r
        FROM information_schema.tables WHERE table_schema = ? ORDER BY (data_length+index_length) DESC LIMIT 12", [DB_NAME]) as $r): ?>
      <div class="bar-row"><span><?= e($r['t']) ?> (<?= number_format((float)$r['r']) ?> rows)</span><b><?= number_format((float)$r['kb']) ?> KB</b></div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php endif; ?>
