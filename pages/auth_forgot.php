<?php
/** Password reset by phone OTP (§5.1.5). Step 1: request code. Step 2: code + new password. */
if (auth()) redirect('');
$pageTitle = 'Reset password';
$error = null;
$step = ($_SESSION['pw_reset_phone'] ?? '') !== '' ? 2 : 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = $_POST['do'] ?? '';

    if ($do === 'request') {
        $phone = preg_replace('/[^\d+]/', '', $_POST['phone'] ?? '');
        // Always respond the same whether the account exists (don't leak numbers),
        // but only actually send a code to registered phones.
        if (strlen($phone) >= 9 && val("SELECT COUNT(*) FROM users WHERE phone = ? AND status IN ('active','pending')", [$phone])) {
            if (!otp_send($phone, 'reset_password')) {
                flash('Too many codes requested — wait a few minutes.', 'error');
                redirect('forgot-password');
            }
        }
        $_SESSION['pw_reset_phone'] = $phone;
        flash('If that number is registered, a reset code was sent by SMS.');
        redirect('forgot-password');
    }

    if ($do === 'reset') {
        $phone = $_SESSION['pw_reset_phone'] ?? '';
        $pass = $_POST['password'] ?? '';
        if (strlen($pass) < 6) {
            $error = 'Password must be at least 6 characters.';
        } elseif ($phone && otp_verify($phone, 'reset_password', $_POST['code'] ?? '')) {
            q("UPDATE users SET password = ? WHERE phone = ?", [password_hash($pass, PASSWORD_BCRYPT), $phone]);
            unset($_SESSION['pw_reset_phone']);
            flash('Password updated — log in with your new password.');
            redirect('login');
        } else {
            $error = 'Wrong or expired code.';
        }
        $step = 2;
    }

    if ($do === 'restart') { unset($_SESSION['pw_reset_phone']); redirect('forgot-password'); }
}
include __DIR__ . '/../views/layout_top.php';
?>
<div class="container section auth-page">
  <div class="panel auth-panel">
    <h1>Reset password</h1>
    <?php if ($error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?>
    <?php if ($step === 1): ?>
      <p class="muted">Enter the phone number you registered with and we'll SMS you a reset code.</p>
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="do" value="request">
        <label>Phone number <input name="phone" required placeholder="09… or +2519…" value="<?= e($_POST['phone'] ?? '') ?>"></label>
        <button class="btn btn-primary btn-block">Send reset code</button>
      </form>
    <?php else: ?>
      <p class="muted">Enter the 6-digit code sent to <strong><?= e($_SESSION['pw_reset_phone']) ?></strong> and choose a new password.</p>
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="do" value="reset">
        <label>Reset code <input name="code" required inputmode="numeric" autocomplete="one-time-code" maxlength="6"></label>
        <label>New password <input type="password" name="password" required minlength="6"></label>
        <button class="btn btn-primary btn-block">Set new password</button>
      </form>
      <form method="post" class="section-gap">
        <?= csrf_field() ?><input type="hidden" name="do" value="restart">
        <button class="btn btn-ghost btn-block">Use a different number</button>
      </form>
    <?php endif; ?>
    <p class="muted"><a href="<?= url('login') ?>">Back to log in</a></p>
  </div>
</div>
<?php include __DIR__ . '/../views/layout_bottom.php'; ?>
