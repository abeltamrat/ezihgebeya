<?php
$existingUser = auth();
$returnTo = safe_return_path($_POST['return_to'] ?? $_GET['return'] ?? ($_SESSION['return_to'] ?? ''), '');
if ($existingUser) redirect(safe_return_path($returnTo, default_post_login_path($existingUser)));
$pageTitle = 'Create account';
$errors = [];
$accountTypes = [
    'customer' => ['🛒 Customer', 'Browse, save products and contact sellers'],
    'seller' => ['🛋️ Furniture Seller', 'Sell ready-made or used furniture'],
    'manufacturer' => ['🏭 Manufacturer', 'Custom & made-to-order furniture workshop'],
    'importer' => ['🚢 Importer', 'Imported furniture and decor'],
    'service_provider' => ['🛠️ Service Provider', 'Finishing works, interior design, installation'],
    'supplier' => ['📦 Supply Vendor', 'MDF, plywood, hardware, paint, tools'],
];

if (!sys('general.registration_open', 1)) {
    flash('New registrations are temporarily closed.', 'error');
    redirect('login');
}
$minPass = (int)sys('auth.min_password_len', 6);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $name = trim($_POST['full_name'] ?? '');
    $phone = preg_replace('/[^\d+]/', '', $_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $pass = $_POST['password'] ?? '';
    $type = $_POST['account_type'] ?? 'customer';

    if (mb_strlen($name) < 2) $errors[] = 'Please enter your full name.';
    if (strlen($phone) < 9) $errors[] = 'Please enter a valid phone number.';
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email address.';
    if (strlen($pass) < $minPass) $errors[] = "Password must be at least $minPass characters.";
    if (!isset($accountTypes[$type])) $errors[] = 'Invalid account type.';
    if ($phone && val("SELECT COUNT(*) FROM users WHERE phone = ?", [$phone])) $errors[] = 'Phone already registered.';
    if ($email && val("SELECT COUNT(*) FROM users WHERE email = ?", [$email])) $errors[] = 'Email already registered.';

    if (!$errors) {
        $otpRequired = (bool)sys('auth.otp_required', 1);
        q("INSERT INTO users (full_name, phone, email, password, account_type, status, phone_verified_at)
           VALUES (?,?,?,?,?, 'active', ?)",
          [$name, $phone, $email ?: null, password_hash($pass, PASSWORD_BCRYPT), $type,
           $otpRequired ? null : date('Y-m-d H:i:s')]);
        $_SESSION['user_id'] = (int)db()->lastInsertId();
        $_SESSION['last_seen'] = time();
        session_regenerate_id(true);
        flash('Welcome to ' . site_name() . '!');
        if ($otpRequired) {
            otp_send($phone, 'verify_phone'); // §5.1.4 — confirm the number by SMS
            if ($returnTo !== '') $_SESSION['return_to'] = $returnTo;
            redirect('verify' . ($returnTo !== '' ? '?return=' . rawurlencode($returnTo) : ''));
        }
        $default = in_array($type, VENDOR_TYPES, true) ? 'vendor/business' : 'account';
        unset($_SESSION['return_to']);
        redirect(safe_return_path($returnTo, $default));
    }
}
include __DIR__ . '/../views/layout_top.php';
?>
<div class="container section auth-page">
  <form class="panel auth-panel" method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="return_to" value="<?= e($returnTo) ?>">
    <h1>Create your account</h1>
    <?php if ($errors): ?>
      <div role="alert" class="alert alert-error mb-4">
        <ul class="list-disc list-inside text-sm space-y-0.5">
          <?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>
    <label>I am a…</label>
    <div class="type-grid">
      <?php foreach ($accountTypes as $k => [$label, $desc]): ?>
        <label class="type-tile">
          <input type="radio" name="account_type" value="<?= $k ?>" <?= ($_POST['account_type'] ?? 'customer') === $k ? 'checked' : '' ?>>
          <span><strong><?= $label ?></strong><small><?= $desc ?></small></span>
        </label>
      <?php endforeach; ?>
    </div>
    <label>Full name <input name="full_name" required value="<?= e($_POST['full_name'] ?? '') ?>"></label>
    <label>Phone number <input name="phone" required placeholder="09… or +2519…" value="<?= e($_POST['phone'] ?? '') ?>"></label>
    <label>Email (optional) <input type="email" name="email" value="<?= e($_POST['email'] ?? '') ?>"></label>
    <label>Password <input type="password" name="password" required minlength="<?= (int)sys('auth.min_password_len', 6) ?>"></label>
    <button class="btn btn-primary btn-block">Create account</button>
    <p class="muted">Already have an account? <a href="<?= url('login' . ($returnTo !== '' ? '?return=' . rawurlencode($returnTo) : '')) ?>">Log in</a></p>
  </form>
</div>
<?php include __DIR__ . '/../views/layout_bottom.php'; ?>
