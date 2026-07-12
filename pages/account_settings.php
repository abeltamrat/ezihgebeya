<?php
/** Account settings: self-service data export and account deletion. */
$u = require_login();
$pageTitle = 'Account Settings';
$errors = [];
$hasMarketingPrefs = db_column_exists('users', 'marketing_sms_opt_in')
    && db_column_exists('users', 'marketing_email_opt_in')
    && db_column_exists('users', 'marketing_push_opt_in');
$marketingPrefs = $hasMarketingPrefs
    ? row("SELECT marketing_sms_opt_in, marketing_email_opt_in, marketing_push_opt_in, marketing_updated_at FROM users WHERE id = ?", [$u['id']])
    : ['marketing_sms_opt_in' => 1, 'marketing_email_opt_in' => 1, 'marketing_push_opt_in' => 1, 'marketing_updated_at' => null];
$notificationCategories = function_exists('notification_categories') ? notification_categories() : [
    'inquiries' => 'Inquiries and chat replies',
    'orders' => 'Orders and delivery updates',
    'reviews' => 'Reviews and ratings',
    'promotions' => 'Promotion/subscription reminders',
    'support' => 'Support ticket updates',
];
$hasNotificationPrefs = db_table_exists('user_notification_preferences');
$categoryPrefs = array_fill_keys(array_keys($notificationCategories), true);
if ($hasNotificationPrefs) {
    foreach (rows("SELECT category, enabled FROM user_notification_preferences WHERE user_id = ?", [$u['id']]) as $pref) {
        if (array_key_exists($pref['category'], $categoryPrefs)) {
            $categoryPrefs[$pref['category']] = (bool)$pref['enabled'];
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = $_POST['do'] ?? '';

    if ($do === 'notification_preferences') {
        if (!$hasMarketingPrefs && !$hasNotificationPrefs) {
            $errors[] = 'Notification preference tables are not installed yet. Run the latest database upgrade first.';
        }
        if (!$errors) {
            if ($hasMarketingPrefs) {
                q("UPDATE users SET marketing_sms_opt_in = ?, marketing_email_opt_in = ?, marketing_push_opt_in = ?, marketing_updated_at = NOW() WHERE id = ?", [
                    isset($_POST['marketing_sms_opt_in']) ? 1 : 0,
                    isset($_POST['marketing_email_opt_in']) ? 1 : 0,
                    isset($_POST['marketing_push_opt_in']) ? 1 : 0,
                    $u['id'],
                ]);
            }
            if ($hasNotificationPrefs) {
                $postedCategories = is_array($_POST['notify_categories'] ?? null) ? $_POST['notify_categories'] : [];
                foreach ($notificationCategories as $category => $label) {
                    q("INSERT INTO user_notification_preferences (user_id, category, enabled) VALUES (?,?,?)
                       ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), updated_at = NOW()", [
                        $u['id'],
                        $category,
                        isset($postedCategories[$category]) ? 1 : 0,
                    ]);
                }
            }
            flash('Notification preferences saved.');
            redirect('account/settings');
        }
    }

    if ($do === 'export_data') {
        $profile = [
            'id' => (int)$u['id'], 'full_name' => $u['full_name'], 'phone' => $u['phone'],
            'email' => $u['email'], 'account_type' => $u['account_type'], 'created_at' => $u['created_at'],
        ];
        if ($hasMarketingPrefs) {
            $profile['marketing_preferences'] = [
                'sms_opt_in' => (bool)$marketingPrefs['marketing_sms_opt_in'],
                'email_opt_in' => (bool)$marketingPrefs['marketing_email_opt_in'],
                'push_opt_in' => (bool)$marketingPrefs['marketing_push_opt_in'],
                'updated_at' => $marketingPrefs['marketing_updated_at'],
            ];
        }
        if ($hasNotificationPrefs) {
            $profile['notification_preferences'] = $categoryPrefs;
        }
        $export = [
            'exported_at' => date('c'),
            'profile' => $profile,
            'orders' => rows("SELECT order_number, business_id, status, delivery_option, delivery_address, city, subcity,
                phone, total, payment_method, created_at FROM orders WHERE customer_id = ? ORDER BY created_at", [$u['id']]),
            'inquiries' => rows("SELECT business_id, listing_type, listing_title, message, phone, status, created_at
                FROM inquiries WHERE customer_id = ? ORDER BY created_at", [$u['id']]),
            'reviews' => rows("SELECT business_id, listing_type, rating, title, comment, status, created_at
                FROM reviews WHERE reviewer_id = ? ORDER BY created_at", [$u['id']]),
            'favorites' => rows("SELECT product_id, created_at FROM favorites WHERE user_id = ? ORDER BY created_at", [$u['id']]),
            'notifications' => rows("SELECT type, title, body, read_at, created_at FROM notifications WHERE user_id = ? ORDER BY created_at", [$u['id']]),
        ];
        $json = json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="ezihgebeya-my-data-' . date('Ymd') . '.json"');
        header('Content-Length: ' . strlen($json));
        echo $json;
        exit;
    }

    if ($do === 'delete_account') {
        if (!password_verify($_POST['password'] ?? '', $u['password'])) {
            $errors[] = 'Incorrect password. Enter your current password to confirm deletion.';
        } elseif (($_POST['confirm'] ?? '') !== 'DELETE') {
            $errors[] = 'Type DELETE to confirm.';
        } elseif (is_admin($u)) {
            $errors[] = 'Admin accounts cannot self-delete — ask another super admin to revoke your access first.';
        } else {
            // Anonymize identity everywhere it was captured. Orders keep their stored phone/
            // address/name as-is — financial and delivery records with a legitimate retention
            // need, unlike a free-standing profile or an inquiry's contact details.
            q("UPDATE users SET full_name = 'Deleted user', phone = NULL, email = NULL,
               password = ?, status = 'deleted' WHERE id = ?", [password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT), $u['id']]);
            q("UPDATE inquiries SET name = 'Deleted user', phone = NULL WHERE customer_id = ?", [$u['id']]);
            q("DELETE FROM api_tokens WHERE user_id = ?", [$u['id']]);

            session_unset();
            flash('Your account has been deleted.');
            session_regenerate_id(true);
            redirect('');
        }
    }
}

include __DIR__ . '/../views/layout_top.php';
?>
<div class="container section account-page">
  <h1>Account Settings</h1>
  <p class="muted"><a href="<?= url('account') ?>">← Back to My Account</a></p>

  <?php if ($errors): ?><div role="alert" class="alert alert-error mb-3"><ul class="list-disc list-inside text-sm"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

  <div class="panel section-gap">
    <h3>Notification preferences</h3>
    <p class="muted small">Choose where EzihGebeya may send offers and which normal update categories you want to hear about. Account safety, OTP, payment, verification, and moderation/listing status notices are always sent when needed.</p>
    <?php if (!$hasMarketingPrefs || !$hasNotificationPrefs): ?>
      <div role="alert" class="alert alert-warning mb-3">Run the latest database upgrade to enable all notification preferences.</div>
    <?php endif; ?>
    <form method="post" class="form-2col">
      <?= csrf_field() ?><input type="hidden" name="do" value="notification_preferences">
      <div class="span2">
        <h4>Marketing channels</h4>
        <p class="muted small">Offers, campaigns, newsletters, and promotional recommendations.</p>
      </div>
      <label class="check"><input type="checkbox" name="marketing_sms_opt_in" value="1" <?= !empty($marketingPrefs['marketing_sms_opt_in']) ? 'checked' : '' ?> <?= !$hasMarketingPrefs ? 'disabled' : '' ?>> SMS offers and campaigns</label>
      <label class="check"><input type="checkbox" name="marketing_email_opt_in" value="1" <?= !empty($marketingPrefs['marketing_email_opt_in']) ? 'checked' : '' ?> <?= !$hasMarketingPrefs ? 'disabled' : '' ?>> Email offers and newsletters</label>
      <label class="check span2"><input type="checkbox" name="marketing_push_opt_in" value="1" <?= !empty($marketingPrefs['marketing_push_opt_in']) ? 'checked' : '' ?> <?= !$hasMarketingPrefs ? 'disabled' : '' ?>> Push / in-app promotional alerts</label>
      <div class="span2">
        <h4>Update categories</h4>
        <p class="muted small">Mute non-critical categories when you need less noise. Critical account and safety notices remain mandatory.</p>
      </div>
      <?php foreach ($notificationCategories as $category => $label): ?>
        <label class="check"><input type="checkbox" name="notify_categories[<?= e($category) ?>]" value="1" <?= !empty($categoryPrefs[$category]) ? 'checked' : '' ?> <?= !$hasNotificationPrefs ? 'disabled' : '' ?>> <?= e($label) ?></label>
      <?php endforeach; ?>
      <div class="span2">
        <button class="btn btn-primary" <?= (!$hasMarketingPrefs && !$hasNotificationPrefs) ? 'disabled' : '' ?>>Save preferences</button>
        <?php if (!empty($marketingPrefs['marketing_updated_at'])): ?><span class="muted small ml-2">Last updated <?= e($marketingPrefs['marketing_updated_at']) ?></span><?php endif; ?>
      </div>
    </form>
  </div>

  <div class="panel section-gap">
    <h3>Export your data</h3>
    <p class="muted small">Download a copy of your profile, orders, inquiries, reviews, saved products, and notifications as a JSON file.</p>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="export_data">
      <button class="btn btn-outline">⬇ Download my data</button>
    </form>
  </div>

  <div class="panel section-gap">
    <h3>Delete your account</h3>
    <p class="muted small">This removes your name and contact details from your profile and past inquiries, and signs you out everywhere. Orders remain on record for business purposes, but are no longer linked to a usable account. This cannot be undone.</p>
    <form method="post" class="form-2col" onsubmit="return confirm('Delete your account? This cannot be undone.')">
      <?= csrf_field() ?><input type="hidden" name="do" value="delete_account">
      <label>Current password <input type="password" name="password" required></label>
      <label>Type DELETE to confirm <input name="confirm" placeholder="DELETE" required></label>
      <div class="span2"><button class="btn btn-error">Delete my account</button></div>
    </form>
  </div>
</div>
<?php include __DIR__ . '/../views/layout_bottom.php'; ?>
