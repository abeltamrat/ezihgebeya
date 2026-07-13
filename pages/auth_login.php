<?php
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$existingUser = auth();
$returnTo = safe_return_path($_POST['return_to'] ?? $_GET['return'] ?? ($_SESSION['return_to'] ?? ''), '');
if ($existingUser) redirect(safe_return_path($returnTo, default_post_login_path($existingUser)));
$pageTitle = 'Log in';
$error = null;
$action = $_POST['do'] ?? 'password_login';

$loginPath = 'login' . ($returnTo !== '' ? '?return=' . rawurlencode($returnTo) : '');
$finishLogin = static function (array $user) use ($returnTo): never {
    // Rotate both the session identifier and the pre-authentication CSRF token at
    // the authentication boundary. The redirected page will create a fresh token.
    session_regenerate_id(true);
    unset($_SESSION['csrf']);
    $_SESSION['user_id'] = (int)($user['user_id'] ?? $user['id']);
    $_SESSION['last_seen'] = time();
    q("UPDATE users SET last_login_at = NOW() WHERE id = ?", [$_SESSION['user_id']]);
    flash('Welcome back, ' . $user['full_name'] . '!');
    unset($_SESSION['return_to']);
    redirect(safe_return_path($returnTo, default_post_login_path($user)));
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if ($action === 'forget_device') {
        remembered_login_forget();
        flash('Quick login was removed from this device.');
        redirect($loginPath);
    }

    if ($action === 'quick_login') {
        $user = remembered_login_authenticate();
        if ($user) $finishLogin($user);
        $error = 'Quick login expired or was removed. Log in with your password to continue.';
    } else {
        $id = trim($_POST['identity'] ?? '');
        $pass = $_POST['password'] ?? '';
        if (login_throttled($id)) { // §22.1.4
            $error = 'Too many failed attempts — try again in 15 minutes or reset your password.';
        } else {
            $user = row("SELECT * FROM users WHERE (phone = ? OR email = ?) AND status IN ('active','pending')", [$id, $id]);
            if ($user && password_verify($pass, $user['password'])) {
                login_record($id, true);
                if (isset($_POST['remember_device'])) {
                    if (!remembered_login_create((int)$user['id'])) {
                        flash('You are logged in, but quick login could not be enabled. Verify your phone or run the latest database upgrade.', 'error');
                    }
                } else {
                    // A different account taking over this browser should not leave
                    // the previous account's trusted credential behind.
                    remembered_login_forget();
                }
                $finishLogin($user);
            }
            $sanctioned = row("SELECT * FROM users WHERE (phone = ? OR email = ?) AND status IN ('suspended','banned')", [$id, $id]);
            if ($sanctioned && password_verify($pass, $sanctioned['password'])) {
                login_record($id, false);
                $error = 'This account is ' . $sanctioned['status'] . '. You can submit an appeal below.';
            } else {
                login_record($id, false);
                $error = 'Wrong phone/email or password.';
            }
            sleep(1); // damper on top of the 15-minute lockout
        }
    }
}

$rememberedUser = remembered_login_find();
$rememberedFirstName = $rememberedUser ? (explode(' ', trim((string)$rememberedUser['full_name']))[0] ?: 'your account') : '';
$rememberedRole = $rememberedUser
    ? (is_vendor($rememberedUser) ? 'Vendor account' : 'Customer account')
    : '';

include __DIR__ . '/../views/layout_top.php';
?>
<div class="container section auth-page">
  <div class="panel auth-panel">
    <?php if ($rememberedUser): ?>
      <section class="remembered-login" aria-labelledby="quick-login-title">
        <p class="auth-eyebrow">Quick login</p>
        <h1 id="quick-login-title">Welcome back</h1>
        <div class="remembered-card">
          <div class="remembered-account">
            <div class="remembered-avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($rememberedUser['full_name'], 0, 1))) ?></div>
            <div class="remembered-copy">
              <strong><?= e($rememberedUser['full_name']) ?></strong>
              <span><?= e($rememberedRole) ?> · trusted <?= e(time_ago($rememberedUser['last_used_at'] ?: $rememberedUser['created_at'])) ?></span>
            </div>
            <svg class="remembered-shield" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 3 5 6v5c0 4.8 2.9 8.1 7 10 4.1-1.9 7-5.2 7-10V6l-7-3Z"/><path d="m9 12 2 2 4-4"/></svg>
          </div>

          <form method="post" class="remembered-continue-form">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="quick_login">
            <input type="hidden" name="return_to" value="<?= e($returnTo) ?>">
            <button class="btn btn-primary btn-block" type="submit">
              Continue as <?= e($rememberedFirstName) ?>
              <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
            </button>
          </form>

          <div class="remembered-actions">
            <a class="btn btn-ghost btn-sm" href="#password-login">Use another account</a>
            <form method="post">
              <?= csrf_field() ?>
              <input type="hidden" name="do" value="forget_device">
              <input type="hidden" name="return_to" value="<?= e($returnTo) ?>">
              <button class="btn btn-ghost btn-sm remembered-remove" type="submit">Remove from device</button>
            </form>
          </div>
        </div>
        <p class="remembered-note">
          <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 9v4m0 4h.01M10.3 4.7 2.7 18a2 2 0 0 0 1.7 3h15.2a2 2 0 0 0 1.7-3L13.7 4.7a2 2 0 0 0-3.4 0Z"/></svg>
          Anyone using this browser can continue as you. Remove quick login on a shared device.
        </p>
      </section>
      <div class="auth-divider"><span>or use your password</span></div>
    <?php endif; ?>

    <form method="post" id="password-login">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="password_login">
      <input type="hidden" name="return_to" value="<?= e($returnTo) ?>">
      <h1><?= $rememberedUser ? 'Log in with another account' : 'Log in' ?></h1>
      <?php if ($error): ?>
        <div role="alert" class="alert alert-error mb-4"><span><?= e($error) ?></span></div>
      <?php endif; ?>
      <label>Phone or email <input name="identity" autocomplete="username" required value="<?= e($_POST['identity'] ?? '') ?>"></label>
      <label>Password <input type="password" name="password" autocomplete="current-password" required></label>
      <?php if (remembered_login_available()): ?>
        <label class="check auth-trust-device">
          <input type="checkbox" name="remember_device" value="1" <?= isset($_POST['remember_device']) ? 'checked' : '' ?>>
          <span>Enable quick login on this personal device<small>No password is stored. Expires after 30 days.</small></span>
        </label>
      <?php endif; ?>
      <button class="btn btn-primary btn-block" type="submit">Log in</button>
      <p class="muted"><a href="<?= url('forgot-password') ?>">Forgot password?</a></p>
      <p class="muted">Account suspended or banned? <a href="<?= url('appeal') ?>">Submit an appeal</a></p>
      <p class="muted">New here? <a href="<?= url('register' . ($returnTo !== '' ? '?return=' . rawurlencode($returnTo) : '')) ?>">Create an account</a></p>
    </form>
  </div>
</div>
<?php include __DIR__ . '/../views/layout_bottom.php'; ?>
