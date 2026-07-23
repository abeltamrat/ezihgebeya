<?php
$existingUser = auth();
$returnTo = safe_return_path($_POST['return_to'] ?? $_GET['return'] ?? ($_SESSION['return_to'] ?? ''), '');
if ($existingUser) redirect(safe_return_path($returnTo, default_post_login_path($existingUser)));
$pageTitle = 'Create account';
$errors = [];
$accountTypes = [
    'customer' => ['Customer', 'Browse, save products and contact sellers'],
    'seller' => ['Furniture Seller', 'Sell ready-made or used furniture'],
    'manufacturer' => ['Manufacturer', 'Custom and made-to-order furniture workshop'],
    'importer' => ['Importer', 'Imported furniture and decor'],
    'service_provider' => ['Service Provider', 'Finishing works, interior design and installation'],
    'supplier' => ['Supply Vendor', 'MDF, plywood, hardware, paint and tools'],
];

if (!sys('general.registration_open', 1)) {
    flash('New registrations are temporarily closed.', 'error');
    redirect('login');
}

$pending = $_SESSION['pending_registration'] ?? [];
if (($pending['started_at'] ?? 0) < time() - 20 * 60) {
    unset($_SESSION['pending_registration']);
    $pending = [];
}
$step = !empty($pending['verified_at']) ? 'profile' : (!empty($pending['phone']) ? 'otp' : 'phone');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['do'] ?? 'send_otp';

    if ($action === 'restart') {
        unset($_SESSION['pending_registration']);
        redirect('register' . ($returnTo !== '' ? '?return=' . rawurlencode($returnTo) : ''));
    }

    if ($action === 'send_otp' || $action === 'resend_otp') {
        $phone = preg_replace('/[^\d+]/', '', $_POST['phone'] ?? ($pending['phone'] ?? ''));
        if (strlen($phone) < 9) {
            $errors[] = 'Please enter a valid phone number.';
            $step = 'phone';
        } elseif (val("SELECT COUNT(*) FROM users WHERE phone = ? AND status != 'deleted'", [$phone])) {
            $errors[] = 'That phone is already registered. Log in with your password instead.';
            $step = 'phone';
        } elseif (!otp_send($phone, 'verify_phone')) {
            $errors[] = 'Too many codes requested. Wait a few minutes and try again.';
            $step = 'otp';
        } else {
            $_SESSION['pending_registration'] = [
                'phone' => $phone,
                'started_at' => time(),
                'return_to' => $returnTo,
            ];
            $pending = $_SESSION['pending_registration'];
            $step = 'otp';
            flash('We sent a 6-digit OTP to ' . $phone . '.');
        }
    } elseif ($action === 'verify_otp') {
        $phone = (string)($pending['phone'] ?? '');
        if ($phone === '') {
            $errors[] = 'Start by entering your phone number.';
            $step = 'phone';
        } elseif (!otp_verify($phone, 'verify_phone', trim($_POST['code'] ?? ''))) {
            $errors[] = 'Wrong or expired OTP. Check the SMS and try again.';
            $step = 'otp';
        } else {
            $_SESSION['pending_registration']['verified_at'] = time();
            $pending = $_SESSION['pending_registration'];
            $step = 'profile';
        }
    } elseif ($action === 'create_account') {
        $pending = $_SESSION['pending_registration'] ?? [];
        $phone = (string)($pending['phone'] ?? '');
        $verifiedAt = (int)($pending['verified_at'] ?? 0);
        $name = trim($_POST['full_name'] ?? '');
        $pass = (string)($_POST['password'] ?? '');
        $type = (string)($_POST['account_type'] ?? 'customer');

        if ($phone === '' || $verifiedAt < time() - 20 * 60) $errors[] = 'Your phone verification expired. Please start again.';
        if (mb_strlen($name) < 2) $errors[] = 'Please enter your full name.';
        if (mb_strlen($pass) < 4) $errors[] = 'Password must be at least 4 characters.';
        if (!isset($accountTypes[$type])) $errors[] = 'Choose a valid account type.';
        if ($phone && val("SELECT COUNT(*) FROM users WHERE phone = ? AND status != 'deleted'", [$phone])) {
            $errors[] = 'That phone is already registered. Log in instead.';
        }

        if (!$errors) {
            q("INSERT INTO users (full_name, phone, password, account_type, status, phone_verified_at)
               VALUES (?,?,?,?, 'active', NOW())",
              [$name, $phone, password_hash($pass, PASSWORD_BCRYPT), $type]);
            $_SESSION['user_id'] = (int)db()->lastInsertId();
            $_SESSION['last_seen'] = time();
            unset($_SESSION['pending_registration'], $_SESSION['return_to']);
            session_regenerate_id(true);
            flash('Welcome to ' . site_name() . '!');
            $default = in_array($type, VENDOR_TYPES, true) ? 'app/vendor/business' : 'app/account';
            redirect(safe_return_path($returnTo ?: ($pending['return_to'] ?? ''), $default));
        }
        $step = $phone !== '' && $verifiedAt >= time() - 20 * 60 ? 'profile' : 'phone';
    }
}

include __DIR__ . '/../views/layout_top.php';
?>
<div class="container section auth-page">
  <div class="panel auth-panel">
    <input type="hidden" name="return_to" value="<?= e($returnTo) ?>">
    <h1><?= $step === 'phone' ? 'Continue with your phone' : ($step === 'otp' ? 'Enter the OTP' : 'Finish your account') ?></h1>

    <?php if ($errors): ?>
      <div role="alert" class="alert alert-error mb-4">
        <ul class="list-disc list-inside text-sm space-y-0.5">
          <?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <?php if ($step === 'phone'): ?>
      <p class="muted">Enter your phone number. We will send an OTP by SMS to verify that the number belongs to you.</p>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="send_otp">
        <input type="hidden" name="return_to" value="<?= e($returnTo) ?>">
        <label>Phone number
          <input name="phone" required inputmode="tel" autocomplete="tel" placeholder="09… or +2519…" value="<?= e($_POST['phone'] ?? '') ?>">
        </label>
        <button class="btn btn-primary btn-block">Send OTP</button>
      </form>
      <p class="muted">Already registered? <a href="<?= url('login' . ($returnTo !== '' ? '?return=' . rawurlencode($returnTo) : '')) ?>">Log in with your password</a></p>

    <?php elseif ($step === 'otp'): ?>
      <p class="muted">We sent a 6-digit OTP to <strong><?= e($pending['phone']) ?></strong>.</p>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="verify_otp">
        <input type="hidden" name="return_to" value="<?= e($returnTo) ?>">
        <label>OTP
          <input name="code" required inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="[0-9]{6}" placeholder="123456">
        </label>
        <button class="btn btn-primary btn-block">Verify OTP</button>
      </form>
      <div class="btn-row mt-3">
        <form method="post">
          <?= csrf_field() ?><input type="hidden" name="do" value="resend_otp">
          <input type="hidden" name="return_to" value="<?= e($returnTo) ?>">
          <button class="btn btn-ghost btn-sm">Resend OTP</button>
        </form>
        <form method="post">
          <?= csrf_field() ?><input type="hidden" name="do" value="restart">
          <input type="hidden" name="return_to" value="<?= e($returnTo) ?>">
          <button class="btn btn-ghost btn-sm">Change phone</button>
        </form>
      </div>

    <?php else: ?>
      <p class="muted"><strong><?= e($pending['phone']) ?></strong> is verified. Enter your name and choose a password.</p>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="create_account">
        <input type="hidden" name="return_to" value="<?= e($returnTo) ?>">
        <label>Full name
          <input name="full_name" required minlength="2" autocomplete="name" value="<?= e($_POST['full_name'] ?? '') ?>">
        </label>
        <label>Password
          <input type="password" name="password" required minlength="4" autocomplete="new-password">
          <small class="muted">Minimum 4 characters.</small>
        </label>
        <label>I am a…</label>
        <div class="type-grid">
          <?php foreach ($accountTypes as $key => [$label, $description]): ?>
            <label class="type-tile">
              <input type="radio" name="account_type" value="<?= e($key) ?>" <?= ($_POST['account_type'] ?? 'customer') === $key ? 'checked' : '' ?>>
              <span><strong><?= e($label) ?></strong><small><?= e($description) ?></small></span>
            </label>
          <?php endforeach; ?>
        </div>
        <button class="btn btn-primary btn-block">Create account</button>
      </form>
    <?php endif; ?>
  </div>
</div>
<?php include __DIR__ . '/../views/layout_bottom.php'; ?>
