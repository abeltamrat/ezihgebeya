<?php
/** Phone OTP verification (§5.1.4). Logged-in users confirm their number here. */
$u = require_login();
if ($u['phone_verified_at']) redirect('account');
$pageTitle = 'Verify your phone';
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['do'] ?? '') === 'send') {
        if (otp_send($u['phone'], 'verify_phone')) flash('Code sent to ' . $u['phone'] . '.');
        else flash('Too many codes requested — wait a few minutes.', 'error');
        redirect('verify');
    }
    if (otp_verify($u['phone'], 'verify_phone', $_POST['code'] ?? '')) {
        q("UPDATE users SET phone_verified_at = NOW() WHERE id = ?", [$u['id']]);
        // phone-verified badge for the user's business, if it has none yet (§5.2)
        q("UPDATE businesses SET verification_status = 'phone_verified' WHERE user_id = ? AND verification_status = 'unverified'", [$u['id']]);
        flash('Phone verified — thank you!');
        redirect(is_vendor($u) ? 'vendor' : 'account');
    }
    $error = 'Wrong or expired code. Check the SMS and try again.';
}
include __DIR__ . '/../views/layout_top.php';
?>
<div class="container section auth-page">
  <div class="panel auth-panel">
    <h1>Verify your phone</h1>
    <p class="muted">We sent a 6-digit code by SMS to <strong><?= e($u['phone']) ?></strong>. Enter it below to activate the verified badge on your account.</p>
    <?php if ($error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?>
    <form method="post">
      <?= csrf_field() ?>
      <label>Verification code <input name="code" required inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="123456"></label>
      <button class="btn btn-primary btn-block">Verify</button>
    </form>
    <form method="post" class="section-gap">
      <?= csrf_field() ?><input type="hidden" name="do" value="send">
      <button class="btn btn-ghost btn-block">Resend code</button>
    </form>
    <p class="muted"><a href="<?= url(is_vendor($u) ? 'vendor' : 'account') ?>">Skip for now</a> — you can verify later from your account.</p>
  </div>
</div>
<?php include __DIR__ . '/../views/layout_bottom.php'; ?>
