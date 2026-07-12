<?php
/** Phone OTP verification (§5.1.4). Logged-in users confirm their number here. */
$u = require_login();
$returnTo = safe_return_path($_POST['return_to'] ?? $_GET['return'] ?? ($_SESSION['return_to'] ?? ''), '');
if ($u['phone_verified_at']) redirect(safe_return_path($returnTo, default_post_login_path($u)));
$pageTitle = 'Verify your phone';
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['do'] ?? '') === 'send') {
        if (otp_send($u['phone'], 'verify_phone')) flash('Code sent to ' . $u['phone'] . '.');
        else flash('Too many codes requested — wait a few minutes.', 'error');
        redirect('verify' . ($returnTo !== '' ? '?return=' . rawurlencode($returnTo) : ''));
    }
    if (otp_verify($u['phone'], 'verify_phone', $_POST['code'] ?? '')) {
        q("UPDATE users SET phone_verified_at = NOW() WHERE id = ?", [$u['id']]);
        // phone-verified badge for the user's business, if it has none yet (§5.2)
        q("UPDATE businesses SET verification_status = 'phone_verified' WHERE user_id = ? AND verification_status = 'unverified'", [$u['id']]);
        flash('Phone verified — thank you!');
        unset($_SESSION['return_to']);
        redirect(safe_return_path($returnTo, default_post_login_path($u)));
    }
    $error = 'Wrong or expired code. Check the SMS and try again.';
}
include __DIR__ . '/../views/layout_top.php';
?>
<div class="container section auth-page">
  <div class="panel auth-panel">
    <h1>Verify your phone</h1>
    <p class="muted">We sent a 6-digit code by SMS to <strong><?= e($u['phone']) ?></strong>. Enter it below to activate the verified badge on your account.</p>
    <?php if ($error): ?><div role="alert" class="alert alert-error mb-3"><span><?= e($error) ?></span></div><?php endif; ?>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="return_to" value="<?= e($returnTo) ?>">
      <label>Verification code <input name="code" required inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="123456"></label>
      <button class="btn btn-primary btn-block">Verify</button>
    </form>
    <form method="post" class="section-gap">
      <?= csrf_field() ?><input type="hidden" name="do" value="send"><input type="hidden" name="return_to" value="<?= e($returnTo) ?>">
      <button class="btn btn-ghost btn-block">Resend code</button>
    </form>
    <p class="muted"><a href="<?= url(safe_return_path($returnTo, default_post_login_path($u))) ?>">Skip for now</a> — you can verify later from your account.</p>
  </div>
</div>
<?php include __DIR__ . '/../views/layout_bottom.php'; ?>
