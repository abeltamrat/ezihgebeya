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
            <a class="btn btn-ghost btn-sm" target="_blank" href="<?= e(url('download/verification/' . $d['id'])) ?>">📄 <?= e(str_replace('_', ' ', $d['doc_type'])) ?></a>
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
  ];
  $commissionPct = (float)sys('payments.commission_percent', 0);
  if ($commissionPct > 0) {
      $completedValue = (float)val("SELECT COALESCE(SUM(total),0) FROM orders WHERE status IN ('delivered','completed') AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')");
      $cards["Commission owed ({$commissionPct}% of completed orders, this month)"] = money($completedValue * $commissionPct / 100) ?: '0 ' . sys('general.currency_label', 'ETB');
  }
  // Moderation SLA (Marketplace fundamentals → "median moderation turnaround < 24h"), computed
  // from audit_logs — the precise per-decision timestamp already recorded for every moderation
  // action — rather than a listing's updated_at, which also changes on unrelated vendor edits.
  $turnaroundHrs = moderation_turnaround_hours(30);
  $medianTurnaround = median($turnaroundHrs);
  $cards['Moderated items (30d)'] = count($turnaroundHrs);
  $cards['Median moderation turnaround (30d)'] = $medianTurnaround === null ? 'No decisions yet' : number_format($medianTurnaround, 1) . ' hrs';
  ?>
  <div class="stat-grid">
    <?php foreach ($cards as $label => $n): ?>
      <div class="stat-card"><div class="stat-num"><?= is_numeric($n) ? number_format((float)$n) : $n ?></div><div class="stat-label"><?= $label ?></div></div>
    <?php endforeach; ?>
  </div>

  <?php
  // Operational monitoring (Marketplace fundamentals → "monitoring for failed cron runs,
  // SMS/email sends, and webhooks"). Cron is the dangerous one: if it silently stops firing,
  // nothing else notices — so flag it loudly rather than as just another stat card.
  $lastCronRun = row("SELECT job, status, started_at, finished_at FROM cron_runs ORDER BY id DESC LIMIT 1");
  $cronStale = !$lastCronRun || strtotime($lastCronRun['started_at']) < strtotime('-26 hours');
  $cronStuck = $lastCronRun && $lastCronRun['status'] === 'running' && strtotime($lastCronRun['started_at']) < strtotime('-1 hour');
  $deliveryFailures = recent_delivery_failures(7);
  ?>
  <div class="panel">
    <h3>🔧 Cron &amp; delivery health</h3>
    <?php if (!$lastCronRun): ?>
      <div role="alert" class="alert alert-warning mb-3">No cron run has ever been recorded. Confirm the cPanel cron job is configured and hitting <code>/cron/daily</code> with the correct <code>X-Cron-Secret</code> header.</div>
    <?php elseif ($lastCronRun['status'] === 'failed'): ?>
      <div role="alert" class="alert alert-error mb-3">Last cron run (<?= e($lastCronRun['job']) ?>, <?= time_ago($lastCronRun['started_at']) ?>) failed: <?= e(mb_substr($lastCronRun['summary'] ?? '', 0, 300)) ?></div>
    <?php elseif ($cronStuck): ?>
      <div role="alert" class="alert alert-error mb-3">Cron run (<?= e($lastCronRun['job']) ?>) has been "running" since <?= time_ago($lastCronRun['started_at']) ?> without finishing — it likely crashed without hitting the failure handler (timeout/memory limit). Check the server error log.</div>
    <?php elseif ($cronStale): ?>
      <div role="alert" class="alert alert-warning mb-3">Last successful cron run was <?= time_ago($lastCronRun['started_at']) ?> — expected at least daily. The scheduled job may have stopped firing.</div>
    <?php else: ?>
      <div role="alert" class="alert alert-success mb-3">Cron is healthy — last run <?= time_ago($lastCronRun['started_at']) ?> (<?= e($lastCronRun['job']) ?>, <?= e($lastCronRun['status']) ?>).</div>
    <?php endif; ?>
    <?php if ($deliveryFailures): ?>
      <p class="muted small">Delivery failures, last 7 days:</p>
      <?php foreach ($deliveryFailures as $channel => $n): ?>
        <div class="bar-row"><span><?= e(str_replace('-error', '', $channel)) ?></span><b><?= $n ?></b></div>
      <?php endforeach; ?>
    <?php else: ?>
      <p class="muted small">No SMS, email, webhook, or cron delivery failures logged in the last 7 days.</p>
    <?php endif; ?>
  </div>

  <div class="panel">
    <h3>Core Web Vitals field data (7d)</h3>
    <p class="muted small">Collected from real browsers on mobile/desktop using the existing events table. Use this to spot slow pages before investing in deeper lab testing.</p>
    <?php
    $vitals = db_table_exists('events') ? rows(
        "SELECT JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.metric')) metric,
                JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.rating')) rating,
                COUNT(*) samples,
                AVG(CAST(JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.value')) AS DECIMAL(12,4))) avg_value,
                MAX(CAST(JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.value')) AS DECIMAL(12,4))) worst_value
         FROM events
         WHERE event_type = 'web_vital' AND created_at > NOW() - INTERVAL 7 DAY
         GROUP BY JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.metric')), JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.rating'))
         ORDER BY metric, FIELD(rating, 'poor', 'needs-improvement', 'good', 'unknown')"
    ) : [];
    ?>
    <?php if (!$vitals): ?>
      <p class="muted">No Web Vitals samples yet. Open the site in a real browser after running the latest migration, then check back after page views.</p>
    <?php else: ?>
      <div class="table-wrap"><table class="data-table">
        <tr><th>Metric</th><th>Rating</th><th>Samples</th><th>Average</th><th>Worst</th></tr>
        <?php foreach ($vitals as $v): ?>
          <tr>
            <td><strong><?= e($v['metric']) ?></strong></td>
            <td><span class="badge badge-status-<?= $v['rating'] === 'good' ? 'active' : ($v['rating'] === 'poor' ? 'rejected' : 'pending') ?>"><?= e($v['rating']) ?></span></td>
            <td><?= number_format((int)$v['samples']) ?></td>
            <td><?= e($v['metric'] === 'CLS' ? number_format((float)$v['avg_value'], 3) : number_format((float)$v['avg_value']) . ' ms') ?></td>
            <td><?= e($v['metric'] === 'CLS' ? number_format((float)$v['worst_value'], 3) : number_format((float)$v['worst_value']) . ' ms') ?></td>
          </tr>
        <?php endforeach; ?>
      </table></div>
    <?php endif; ?>
  </div>

  <div class="panel">
    <h3>Commerce health</h3>
    <p class="muted small">GMV is based on order totals excluding cancelled/refunded/disputed orders. Revenue counts confirmed platform payments, not customer order value.</p>
    <?php
    $commerce30 = row("SELECT
            COUNT(*) orders_count,
            COALESCE(SUM(total),0) gmv,
            COALESCE(AVG(total),0) aov,
            SUM(status IN ('delivered','completed')) completed_orders,
            SUM(status IN ('pending','confirmed','deposit_paid','processing','ready_for_delivery','out_for_delivery')) active_orders
        FROM orders
        WHERE created_at > NOW() - INTERVAL 30 DAY
          AND status NOT IN ('cancelled','refunded','disputed')") ?: ['orders_count' => 0, 'gmv' => 0, 'aov' => 0, 'completed_orders' => 0, 'active_orders' => 0];
    $commerceMonth = row("SELECT
            COUNT(*) orders_count,
            COALESCE(SUM(total),0) gmv,
            COALESCE(AVG(total),0) aov
        FROM orders
        WHERE created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')
          AND status NOT IN ('cancelled','refunded','disputed')") ?: ['orders_count' => 0, 'gmv' => 0, 'aov' => 0];
    $platformRevenue = row("SELECT
            COALESCE(SUM(CASE WHEN payment_type != 'order_payment' THEN amount ELSE 0 END),0) total,
            COALESCE(SUM(CASE WHEN promotion_id IS NOT NULL OR payment_type = 'featured_listing_payment' THEN amount ELSE 0 END),0) promotions,
            COALESCE(SUM(CASE WHEN subscription_id IS NOT NULL OR payment_type = 'subscription_payment' THEN amount ELSE 0 END),0) subscriptions,
            COALESCE(SUM(CASE WHEN ad_id IS NOT NULL OR payment_type = 'ad_payment' THEN amount ELSE 0 END),0) ads,
            COALESCE(SUM(CASE WHEN payment_type = 'commission_payment' THEN amount ELSE 0 END),0) commissions
        FROM payments
        WHERE status = 'confirmed' AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')") ?: ['total' => 0, 'promotions' => 0, 'subscriptions' => 0, 'ads' => 0, 'commissions' => 0];
    $paymentBacklog = row("SELECT COUNT(*) pending_count, COALESCE(SUM(amount),0) pending_value FROM payments WHERE status = 'pending'")
        ?: ['pending_count' => 0, 'pending_value' => 0];
    $commercialBacklog = row("SELECT COUNT(*) pending_count, COALESCE(SUM(amount),0) pending_value FROM payments WHERE status = 'pending' AND payment_type != 'order_payment'")
        ?: ['pending_count' => 0, 'pending_value' => 0];
    ?>
    <div class="stat-grid">
      <div class="stat-card"><div class="stat-num"><?= money($commerce30['gmv']) ?: '0 ETB' ?></div><div class="stat-label">GMV, last 30 days</div></div>
      <div class="stat-card"><div class="stat-num"><?= number_format((int)$commerce30['orders_count']) ?></div><div class="stat-label">Orders, last 30 days</div></div>
      <div class="stat-card"><div class="stat-num"><?= money($commerce30['aov']) ?: '0 ETB' ?></div><div class="stat-label">Average order value, last 30 days</div></div>
      <div class="stat-card"><div class="stat-num"><?= money($platformRevenue['total']) ?: '0 ETB' ?></div><div class="stat-label">Platform revenue, month to date</div></div>
      <div class="stat-card"><div class="stat-num"><?= money($paymentBacklog['pending_value']) ?: '0 ETB' ?></div><div class="stat-label">Payment verification backlog (<?= number_format((int)$paymentBacklog['pending_count']) ?>)</div></div>
      <div class="stat-card"><div class="stat-num"><?= money($commercialBacklog['pending_value']) ?: '0 ETB' ?></div><div class="stat-label">Commercial-payment backlog (<?= number_format((int)$commercialBacklog['pending_count']) ?>)</div></div>
    </div>
    <div class="table-wrap"><table class="data-table">
      <tr><th>Metric</th><th>Month to date</th><th>Last 30 days / detail</th></tr>
      <tr><td>GMV</td><td><?= money($commerceMonth['gmv']) ?: '0 ETB' ?></td><td><?= money($commerce30['gmv']) ?: '0 ETB' ?></td></tr>
      <tr><td>Orders</td><td><?= number_format((int)$commerceMonth['orders_count']) ?></td><td><?= number_format((int)$commerce30['orders_count']) ?> total · <?= number_format((int)$commerce30['active_orders']) ?> active · <?= number_format((int)$commerce30['completed_orders']) ?> completed</td></tr>
      <tr><td>Average order value</td><td><?= money($commerceMonth['aov']) ?: '0 ETB' ?></td><td><?= money($commerce30['aov']) ?: '0 ETB' ?></td></tr>
      <tr><td>Promotion revenue</td><td><?= money($platformRevenue['promotions']) ?: '0 ETB' ?></td><td>Confirmed featured/promotion payments</td></tr>
      <tr><td>Subscription revenue</td><td><?= money($platformRevenue['subscriptions']) ?: '0 ETB' ?></td><td>Confirmed subscription payments</td></tr>
      <tr><td>Ad revenue</td><td><?= money($platformRevenue['ads']) ?: '0 ETB' ?></td><td>Confirmed ad payments</td></tr>
      <tr><td>Commission revenue</td><td><?= money($platformRevenue['commissions']) ?: '0 ETB' ?></td><td>Confirmed commission payments</td></tr>
    </table></div>
    <?php $pendingByType = rows("SELECT payment_type, COUNT(*) n, COALESCE(SUM(amount),0) value FROM payments WHERE status = 'pending' GROUP BY payment_type ORDER BY value DESC"); ?>
    <?php if ($pendingByType): ?>
      <h4>Pending payment verification by type</h4>
      <?php foreach ($pendingByType as $r): ?>
        <div class="bar-row"><span><?= e(str_replace('_', ' ', $r['payment_type'])) ?> <span class="muted small">(<?= number_format((int)$r['n']) ?>)</span></span><b><?= money($r['value']) ?: '0 ETB' ?></b></div>
      <?php endforeach; ?>
    <?php endif; ?>
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
    <h3>Supply health</h3>
    <p class="muted small">Tracks whether new vendors list quickly, whether older vendors are still active, and how much inventory each active vendor contributes.</p>
    <?php
    $supplyTotals = row("SELECT
            COUNT(*) active_vendors,
            COALESCE(SUM(active_listings),0) active_listings,
            COALESCE(AVG(active_listings),0) avg_listings
        FROM (
            SELECT b.id,
                   (SELECT COUNT(*) FROM products p WHERE p.business_id = b.id AND p.status = 'active')
                 + (SELECT COUNT(*) FROM services s WHERE s.business_id = b.id AND s.status = 'active')
                 + (SELECT COUNT(*) FROM supplies sp WHERE sp.business_id = b.id AND sp.status = 'active') active_listings
            FROM businesses b
            WHERE b.status = 'active'
        ) x") ?: ['active_vendors' => 0, 'active_listings' => 0, 'avg_listings' => 0];
    $newVendorCohort = row("SELECT COUNT(*) new_vendors FROM businesses WHERE created_at > NOW() - INTERVAL 30 DAY") ?: ['new_vendors' => 0];
    $activatedNew = (int)val("SELECT COUNT(*) FROM businesses b
        WHERE b.created_at > NOW() - INTERVAL 30 DAY
          AND EXISTS (
              SELECT 1 FROM (
                  SELECT business_id, created_at FROM products
                  UNION ALL SELECT business_id, created_at FROM services
                  UNION ALL SELECT business_id, created_at FROM supplies
              ) l
              WHERE l.business_id = b.id AND l.created_at <= b.created_at + INTERVAL 7 DAY
          )");
    $olderVendorCohort = (int)val("SELECT COUNT(*) FROM businesses WHERE created_at <= NOW() - INTERVAL 90 DAY");
    $retained90 = (int)val("SELECT COUNT(*) FROM businesses b
        WHERE b.created_at <= NOW() - INTERVAL 90 DAY
          AND b.status = 'active'
          AND (
              EXISTS (SELECT 1 FROM products p WHERE p.business_id = b.id AND p.status = 'active')
              OR EXISTS (SELECT 1 FROM services s WHERE s.business_id = b.id AND s.status = 'active')
              OR EXISTS (SELECT 1 FROM supplies sp WHERE sp.business_id = b.id AND sp.status = 'active')
              OR EXISTS (SELECT 1 FROM inquiries i WHERE i.business_id = b.id AND i.created_at > NOW() - INTERVAL 90 DAY)
              OR EXISTS (SELECT 1 FROM orders o WHERE o.business_id = b.id AND o.created_at > NOW() - INTERVAL 90 DAY)
          )");
    $activationRate = (int)$newVendorCohort['new_vendors'] ? $activatedNew / (int)$newVendorCohort['new_vendors'] * 100 : null;
    $retentionRate = $olderVendorCohort ? $retained90 / $olderVendorCohort * 100 : null;
    ?>
    <div class="stat-grid">
      <div class="stat-card"><div class="stat-num"><?= $activationRate === null ? '—' : number_format($activationRate, 1) . '%' ?></div><div class="stat-label">30d vendor activation: listed within first week</div></div>
      <div class="stat-card"><div class="stat-num"><?= $retentionRate === null ? '—' : number_format($retentionRate, 1) . '%' ?></div><div class="stat-label">90d vendor retention</div></div>
      <div class="stat-card"><div class="stat-num"><?= number_format((float)$supplyTotals['avg_listings'], 1) ?></div><div class="stat-label">Active listings per active vendor</div></div>
      <div class="stat-card"><div class="stat-num"><?= number_format((int)$supplyTotals['active_listings']) ?></div><div class="stat-label">Active listings total</div></div>
      <div class="stat-card"><div class="stat-num"><?= number_format((int)$supplyTotals['active_vendors']) ?></div><div class="stat-label">Active vendors</div></div>
      <div class="stat-card"><div class="stat-num"><?= number_format($activatedNew) ?> / <?= number_format((int)$newVendorCohort['new_vendors']) ?></div><div class="stat-label">New vendors activated, last 30d</div></div>
    </div>
    <?php $vendorListingRows = rows("SELECT b.business_name,
              (SELECT COUNT(*) FROM products p WHERE p.business_id = b.id AND p.status = 'active')
            + (SELECT COUNT(*) FROM services s WHERE s.business_id = b.id AND s.status = 'active')
            + (SELECT COUNT(*) FROM supplies sp WHERE sp.business_id = b.id AND sp.status = 'active') active_listings
        FROM businesses b
        WHERE b.status = 'active'
        ORDER BY active_listings DESC, b.created_at DESC LIMIT 8"); ?>
    <?php if ($vendorListingRows): ?>
      <h4>Top active vendors by live inventory</h4>
      <?php foreach ($vendorListingRows as $r): ?>
        <div class="bar-row"><span><?= e($r['business_name']) ?></span><b><?= number_format((int)$r['active_listings']) ?></b></div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <div class="panel">
    <h3>Marketplace liquidity</h3>
    <p class="muted small">Liquidity shows how quickly supply meets demand. The goal is shorter time to first inquiry and fewer older listings with no traction.</p>
    <?php
    $firstInquiryRows = rows("SELECT TIMESTAMPDIFF(HOUR, listings.created_at, first_inquiry.first_at) hrs, listings.listing_type
        FROM (
            SELECT 'product' listing_type, id, created_at FROM products WHERE status IN ('active','sold_out','expired')
            UNION ALL SELECT 'service' listing_type, id, created_at FROM services WHERE status IN ('active','sold_out','expired')
            UNION ALL SELECT 'supply' listing_type, id, created_at FROM supplies WHERE status IN ('active','sold_out','expired','out_of_stock')
        ) listings
        JOIN (
            SELECT listing_type, listing_id, MIN(created_at) first_at
            FROM inquiries
            WHERE listing_id IS NOT NULL AND listing_type IN ('product','service','supply')
            GROUP BY listing_type, listing_id
        ) first_inquiry ON first_inquiry.listing_type = listings.listing_type AND first_inquiry.listing_id = listings.id
        WHERE first_inquiry.first_at >= listings.created_at");
    $liquidityHours = array_values(array_filter(array_map(fn($r) => $r['hrs'] === null ? null : (float)$r['hrs'], $firstInquiryRows), fn($v) => $v !== null));
    $medianFirstInquiry = median($liquidityHours);
    $typeHours = ['product' => [], 'service' => [], 'supply' => []];
    foreach ($firstInquiryRows as $r) {
        if ($r['hrs'] !== null && isset($typeHours[$r['listing_type']])) $typeHours[$r['listing_type']][] = (float)$r['hrs'];
    }
    $staleRows = rows("SELECT listing_type, COUNT(*) total,
              SUM(CASE WHEN views_count = 0 AND inquiries_count = 0 THEN 1 ELSE 0 END) zero_traction
        FROM (
            SELECT 'product' listing_type, views_count, inquiries_count FROM products WHERE status = 'active' AND created_at <= NOW() - INTERVAL 14 DAY
            UNION ALL SELECT 'service' listing_type, views_count, inquiries_count FROM services WHERE status = 'active' AND created_at <= NOW() - INTERVAL 14 DAY
            UNION ALL SELECT 'supply' listing_type, views_count, inquiries_count FROM supplies WHERE status = 'active' AND created_at <= NOW() - INTERVAL 14 DAY
        ) old_listings
        GROUP BY listing_type");
    $staleTotal = 0; $zeroTotal = 0; $staleByType = [];
    foreach ($staleRows as $r) {
        $staleTotal += (int)$r['total'];
        $zeroTotal += (int)$r['zero_traction'];
        $staleByType[$r['listing_type']] = $r;
    }
    $zeroShare = $staleTotal ? $zeroTotal / $staleTotal * 100 : null;
    $slowListings = rows("SELECT listing_type, title, business_name, created_at, views_count, inquiries_count FROM (
            SELECT 'product' listing_type, p.title title, b.business_name, p.created_at, p.views_count, p.inquiries_count
            FROM products p JOIN businesses b ON b.id = p.business_id
            WHERE p.status = 'active' AND p.created_at <= NOW() - INTERVAL 14 DAY AND p.views_count = 0 AND p.inquiries_count = 0
            UNION ALL
            SELECT 'service' listing_type, s.title title, b.business_name, s.created_at, s.views_count, s.inquiries_count
            FROM services s JOIN businesses b ON b.id = s.business_id
            WHERE s.status = 'active' AND s.created_at <= NOW() - INTERVAL 14 DAY AND s.views_count = 0 AND s.inquiries_count = 0
            UNION ALL
            SELECT 'supply' listing_type, sp.name title, b.business_name, sp.created_at, sp.views_count, sp.inquiries_count
            FROM supplies sp JOIN businesses b ON b.id = sp.business_id
            WHERE sp.status = 'active' AND sp.created_at <= NOW() - INTERVAL 14 DAY AND sp.views_count = 0 AND sp.inquiries_count = 0
        ) z ORDER BY created_at ASC LIMIT 8");
    ?>
    <div class="stat-grid">
      <div class="stat-card"><div class="stat-num"><?= $medianFirstInquiry === null ? '—' : number_format($medianFirstInquiry, 1) . ' hrs' ?></div><div class="stat-label">Median time to first inquiry</div></div>
      <div class="stat-card"><div class="stat-num"><?= $zeroShare === null ? '—' : number_format($zeroShare, 1) . '%' ?></div><div class="stat-label">Active listings 14d+ with zero views/inquiries</div></div>
      <div class="stat-card"><div class="stat-num"><?= number_format($zeroTotal) ?> / <?= number_format($staleTotal) ?></div><div class="stat-label">Zero-traction older listings</div></div>
      <div class="stat-card"><div class="stat-num"><?= number_format(count($liquidityHours)) ?></div><div class="stat-label">Listings with at least one inquiry</div></div>
    </div>
    <div class="table-wrap"><table class="data-table">
      <tr><th>Listing type</th><th>Median first inquiry</th><th>Zero-traction 14d+ share</th><th>Zero / total older active</th></tr>
      <?php foreach (['product' => 'Products', 'service' => 'Services', 'supply' => 'Supplies'] as $lt => $label):
        $row = $staleByType[$lt] ?? ['total' => 0, 'zero_traction' => 0];
        $share = (int)$row['total'] ? (int)$row['zero_traction'] / (int)$row['total'] * 100 : null;
        $med = median($typeHours[$lt]);
      ?>
        <tr>
          <td><?= e($label) ?></td>
          <td><?= $med === null ? '—' : number_format($med, 1) . ' hrs' ?></td>
          <td><?= $share === null ? '—' : number_format($share, 1) . '%' ?></td>
          <td><?= number_format((int)$row['zero_traction']) ?> / <?= number_format((int)$row['total']) ?></td>
        </tr>
      <?php endforeach; ?>
    </table></div>
    <?php if ($slowListings): ?>
      <h4>Oldest zero-traction active listings</h4>
      <?php foreach ($slowListings as $r): ?>
        <div class="bar-row"><span><?= e($r['title']) ?> <span class="muted small">/ <?= e($r['business_name']) ?> · <?= e($r['listing_type']) ?> · listed <?= time_ago($r['created_at']) ?></span></span><b>0 / 0</b></div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <div class="panel">
    <h3>Demand signals from search (30d)</h3>
    <p class="muted small">Top searches show current demand; zero-result searches tell you which inventory or categories to recruit next.</p>
    <?php
    $topSearches = db_table_exists('events') ? rows(
        "SELECT LOWER(TRIM(JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.query')))) q,
                JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.scope')) scope,
                COUNT(*) searches,
                SUM(CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.zero_results')) = 'true' THEN 1 ELSE 0 END) zeroes,
                AVG(CAST(JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.result_count')) AS DECIMAL(12,2))) avg_results
         FROM events
         WHERE event_type = 'search' AND created_at > NOW() - INTERVAL 30 DAY
           AND JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.query')) IS NOT NULL
         GROUP BY LOWER(TRIM(JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.query')))), JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.scope'))
         ORDER BY searches DESC LIMIT 12"
    ) : [];
    $zeroSearches = db_table_exists('events') ? rows(
        "SELECT LOWER(TRIM(JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.query')))) q,
                JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.scope')) scope,
                COUNT(*) zeroes,
                MAX(created_at) last_seen
         FROM events
         WHERE event_type = 'search' AND created_at > NOW() - INTERVAL 30 DAY
           AND JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.zero_results')) = 'true'
         GROUP BY LOWER(TRIM(JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.query')))), JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.scope'))
         ORDER BY zeroes DESC, last_seen DESC LIMIT 12"
    ) : [];
    ?>
    <?php if (!$topSearches): ?><p class="muted">No search demand data yet.</p><?php endif; ?>
    <?php if ($topSearches): ?>
      <h4>Top searches</h4>
      <?php foreach ($topSearches as $r): ?>
        <div class="bar-row">
          <span><?= e($r['q']) ?> <span class="muted small">/ <?= e($r['scope'] ?: 'global') ?></span></span>
          <b><?= number_format((int)$r['searches']) ?><?= (int)$r['zeroes'] ? ' · ' . number_format((int)$r['zeroes']) . ' zero' : '' ?></b>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
    <?php if ($zeroSearches): ?>
      <h4>Zero-result searches to recruit for</h4>
      <?php foreach ($zeroSearches as $r): ?>
        <div class="bar-row">
          <span><?= e($r['q']) ?> <span class="muted small">/ <?= e($r['scope'] ?: 'global') ?> · last <?= time_ago($r['last_seen']) ?></span></span>
          <b><?= number_format((int)$r['zeroes']) ?></b>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
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
    <h3>Trust health</h3>
    <p class="muted small">Combines vendor responsiveness, report pressure, moderation speed, and suspicious-activity rules into one marketplace trust view.</p>
    <?php
    $responseRows = db_table_exists('inquiry_messages') ? rows(
        "SELECT TIMESTAMPDIFF(MINUTE, i.created_at, MIN(m.created_at)) mins
         FROM inquiries i
         JOIN businesses b ON b.id = i.business_id
         JOIN inquiry_messages m ON m.inquiry_id = i.id AND m.sender_id = b.user_id AND m.created_at >= i.created_at
         WHERE i.created_at > NOW() - INTERVAL 30 DAY
         GROUP BY i.id
         HAVING mins IS NOT NULL"
    ) : [];
    $responseMins = array_map(fn($r) => max(0, (int)$r['mins']), $responseRows);
    $medianResponseMins = median($responseMins);
    $reportStats = row("SELECT
            COUNT(*) total_30d,
            SUM(created_at > NOW() - INTERVAL 7 DAY) total_7d,
            SUM(status = 'open') open_now,
            SUM(status = 'reviewing') reviewing_now,
            SUM(status IN ('resolved','dismissed')) closed_30d
        FROM reports
        WHERE created_at > NOW() - INTERVAL 30 DAY OR status IN ('open','reviewing')")
        ?: ['total_30d' => 0, 'total_7d' => 0, 'open_now' => 0, 'reviewing_now' => 0, 'closed_30d' => 0];
    $reportByType = rows("SELECT reported_type, COUNT(*) n FROM reports
        WHERE created_at > NOW() - INTERVAL 30 DAY
        GROUP BY reported_type ORDER BY n DESC LIMIT 6");
    // Precomputed nightly by cron (pages/cron.php) into admin_suspicious_flags, so this panel
    // doesn't re-run all 5 flag queries on every page load. Falls back to a live computation
    // only if cron hasn't run yet (migration just applied, or a fresh install) — same
    // degrade-gracefully convention already used elsewhere in this file for new tables.
    $flagsRow = db_table_exists('admin_suspicious_flags') ? row("SELECT * FROM admin_suspicious_flags ORDER BY id DESC LIMIT 1") : null;
    if ($flagsRow) {
        $underpricedFlags = (int)$flagsRow['underpriced_flags'];
        $listingFloodFlags = (int)$flagsRow['listing_flood_flags'];
        $reportClusterFlags = (int)$flagsRow['report_cluster_flags'];
        $duplicateTitleFlags = (int)$flagsRow['duplicate_title_flags'];
        $adClickFraudFlags = (int)$flagsRow['ad_click_fraud_flags'];
        $flagsComputedAt = $flagsRow['computed_at'];
    } else {
        $underpricedFlags = (int)val("SELECT COUNT(*) FROM products p
            JOIN (SELECT category_id, AVG(price) avg_price FROM products WHERE status='active' AND price > 0 GROUP BY category_id HAVING COUNT(*) >= 3) avg_t
              ON avg_t.category_id = p.category_id
            WHERE p.status = 'active' AND p.price > 0 AND p.price < avg_t.avg_price * 0.1");
        $listingFloodFlags = (int)val("SELECT COUNT(*) FROM (
            SELECT b.id FROM businesses b JOIN products p ON p.business_id = b.id
            WHERE b.created_at > NOW() - INTERVAL 7 DAY GROUP BY b.id HAVING COUNT(p.id) > 10
        ) t");
        $reportClusterFlags = (int)val("SELECT COUNT(*) FROM (
            SELECT reported_type, reported_id FROM reports WHERE status IN ('open','reviewing')
            GROUP BY reported_type, reported_id HAVING COUNT(*) >= 3
        ) t");
        $duplicateTitleFlags = (int)val("SELECT COUNT(*) FROM (
            SELECT title FROM products WHERE status='active' GROUP BY title HAVING COUNT(*) >= 4
        ) t");
        $adClickFraudFlags = (int)val("SELECT COUNT(*) FROM (
            SELECT ad_id, ip FROM ad_events WHERE event_type='click' AND created_at > NOW() - INTERVAL 1 DAY
            GROUP BY ad_id, ip HAVING COUNT(*) >= 5
        ) t");
        $flagsComputedAt = null;
    }
    $suspiciousTotal = $underpricedFlags + $listingFloodFlags + $reportClusterFlags + $duplicateTitleFlags + $adClickFraudFlags;
    ?>
    <div class="stat-grid">
      <div class="stat-card"><div class="stat-num"><?= $medianResponseMins === null ? '—' : response_time_label($medianResponseMins) ?></div><div class="stat-label">Median vendor response time, 30d</div></div>
      <div class="stat-card"><div class="stat-num"><?= number_format((int)$reportStats['total_30d']) ?></div><div class="stat-label">Reports, last 30 days</div></div>
      <div class="stat-card"><div class="stat-num"><?= number_format((int)$reportStats['open_now'] + (int)$reportStats['reviewing_now']) ?></div><div class="stat-label">Open / reviewing reports now</div></div>
      <div class="stat-card"><div class="stat-num"><?= $medianTurnaround === null ? '—' : number_format($medianTurnaround, 1) . ' hrs' ?></div><div class="stat-label">Median moderation SLA, 30d</div></div>
      <div class="stat-card"><div class="stat-num"><?= number_format($suspiciousTotal) ?></div><div class="stat-label">Suspicious-activity flags now</div></div>
      <div class="stat-card"><div class="stat-num"><?= number_format(count($responseMins)) ?></div><div class="stat-label">Inquiries with vendor replies, 30d</div></div>
    </div>
    <div class="table-wrap"><table class="data-table">
      <tr><th>Trust signal</th><th>Current value</th><th>Notes</th></tr>
      <tr><td>Report volume</td><td><?= number_format((int)$reportStats['total_30d']) ?> in 30d · <?= number_format((int)$reportStats['total_7d']) ?> in 7d</td><td><?= number_format((int)$reportStats['closed_30d']) ?> resolved/dismissed in the 30d window</td></tr>
      <tr><td>Moderation decisions</td><td><?= number_format(count($turnaroundHrs)) ?></td><td><?= $medianTurnaround === null ? 'No decisions yet' : 'Median turnaround ' . number_format($medianTurnaround, 1) . ' hours' ?></td></tr>
      <tr><td>Suspicious trend</td><td><?= number_format($suspiciousTotal) ?> current flags</td><td><?= number_format($underpricedFlags) ?> underpriced · <?= number_format($listingFloodFlags) ?> floods · <?= number_format($reportClusterFlags) ?> report clusters · <?= number_format($duplicateTitleFlags) ?> duplicates · <?= number_format($adClickFraudFlags) ?> ad-click clusters<?= $flagsComputedAt ? ' · computed ' . time_ago($flagsComputedAt) . ' by cron' : ' · computed live (cron has not run yet)' ?></td></tr>
    </table></div>
    <?php if ($reportByType): ?>
      <h4>Reports by type, 30d</h4>
      <?php foreach ($reportByType as $r): ?>
        <div class="bar-row"><span><?= e(str_replace('_', ' ', $r['reported_type'])) ?></span><b><?= number_format((int)$r['n']) ?></b></div>
      <?php endforeach; ?>
    <?php endif; ?>
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
        <label>Uploads per visitor per window <input type="number" name="sys[limits][upload_rate_max]" min="1" max="200" value="<?= (int)$S['limits']['upload_rate_max'] ?>"></label>
        <label>Upload rate window (minutes) <input type="number" name="sys[limits][upload_rate_window_min]" min="1" max="240" value="<?= (int)$S['limits']['upload_rate_window_min'] ?>"></label>
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
      <h4 class="section-gap">📲 Firebase Cloud Messaging web push</h4>
      <p class="muted small">Blank = push disabled, logged to <code>database/outbox.log</code> only — same as the SMS gateway above. Paste your Firebase project's values below to go live; nothing else in the app needs to change.</p>
      <label>Firebase project ID <input name="sys[notifications][fcm_project_id]" value="<?= e($S['notifications']['fcm_project_id']) ?>" placeholder="my-firebase-project"></label>
      <label>Web app config (client-side, safe to expose — from Firebase console → Project settings → General → Your apps → SDK setup)
        <textarea name="sys[notifications][fcm_web_config]" rows="3" placeholder='{"apiKey":"…","authDomain":"…","projectId":"…","storageBucket":"…","messagingSenderId":"…","appId":"…","vapidKey":"…"}'><?= e($S['notifications']['fcm_web_config']) ?></textarea>
      </label>
      <label>Service account JSON (server-side only, never sent to the browser — from Firebase console → Project settings → Service accounts → Generate new private key)
        <textarea name="sys[notifications][fcm_service_account_json]" rows="3" placeholder='{"type":"service_account","project_id":"…","private_key":"…","client_email":"…", …}'><?= e($S['notifications']['fcm_service_account_json']) ?></textarea>
      </label>
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
    <h3>3b. Restore Database</h3>
    <p class="muted small">Upload a previously downloaded .sql backup. This overwrites any existing tables with the same names — take a fresh backup first if this is production.</p>
    <form method="post" enctype="multipart/form-data" class="form-2col" onsubmit="return confirm('Restore from this file? Existing tables with matching names will be overwritten.')">
      <?= csrf_field() ?><input type="hidden" name="do" value="backup_restore">
      <label class="span2">Backup file (.sql) <input type="file" name="restore_file" accept=".sql" required></label>
      <label class="span2">Type confirmation
        <input name="confirm_restore" placeholder="RESTORE DATABASE" required>
      </label>
      <div class="span2"><button class="btn btn-outline">Restore from backup</button></div>
    </form>
  </div>

  <div class="panel">
    <h3>3c. Backup / Restore Uploaded Media</h3>
    <p class="muted small">Listing photos, business documents, and payment proofs live in <code>uploads/</code> and are not part of the SQL backup above — back them up separately.</p>
    <form method="post" class="form-inline">
      <?= csrf_field() ?><input type="hidden" name="do" value="uploads_backup_download">
      <button class="btn btn-primary">Download uploads .zip</button>
    </form>
    <form method="post" enctype="multipart/form-data" class="form-2col section-gap" onsubmit="return confirm('Restore uploads from this archive? Existing files with matching names will be overwritten.')">
      <?= csrf_field() ?><input type="hidden" name="do" value="uploads_restore">
      <label class="span2">Uploads backup (.zip) <input type="file" name="uploads_zip" accept=".zip" required></label>
      <label class="span2">Type confirmation
        <input name="confirm_uploads_restore" placeholder="RESTORE UPLOADS" required>
      </label>
      <div class="span2"><button class="btn btn-outline">Restore uploads</button></div>
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
