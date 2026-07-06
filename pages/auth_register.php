<?php
if (auth()) redirect('');
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
    if (strlen($pass) < 6) $errors[] = 'Password must be at least 6 characters.';
    if (!isset($accountTypes[$type])) $errors[] = 'Invalid account type.';
    if ($phone && val("SELECT COUNT(*) FROM users WHERE phone = ?", [$phone])) $errors[] = 'Phone already registered.';
    if ($email && val("SELECT COUNT(*) FROM users WHERE email = ?", [$email])) $errors[] = 'Email already registered.';

    if (!$errors) {
        q("INSERT INTO users (full_name, phone, email, password, account_type, status)
           VALUES (?,?,?,?,?, 'active')",
          [$name, $phone, $email ?: null, password_hash($pass, PASSWORD_BCRYPT), $type]);
        $_SESSION['user_id'] = (int)db()->lastInsertId();
        $_SESSION['last_seen'] = time();
        session_regenerate_id(true);
        otp_send($phone, 'verify_phone'); // §5.1.4 — confirm the number by SMS
        flash('Welcome to ' . SITE_NAME . '!');
        redirect('verify');
    }
}
include __DIR__ . '/../views/layout_top.php';
?>
<div class="container section auth-page">
  <form class="panel auth-panel" method="post">
    <?= csrf_field() ?>
    <h1>Create your account</h1>
    <?php foreach ($errors as $er): ?><div class="flash flash-error"><?= e($er) ?></div><?php endforeach; ?>
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
    <label>Password <input type="password" name="password" required minlength="6"></label>
    <button class="btn btn-primary btn-block">Create account</button>
    <p class="muted">Already have an account? <a href="<?= url('login') ?>">Log in</a></p>
  </form>
</div>
<?php include __DIR__ . '/../views/layout_bottom.php'; ?>
