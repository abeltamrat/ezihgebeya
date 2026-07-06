<?php
if (auth()) redirect('');
$pageTitle = 'Log in';
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = trim($_POST['identity'] ?? '');
    $pass = $_POST['password'] ?? '';
    if (login_throttled($id)) { // §22.1.4
        $error = 'Too many failed attempts — try again in 15 minutes or reset your password.';
    } else {
        $user = row("SELECT * FROM users WHERE (phone = ? OR email = ?) AND status IN ('active','pending')", [$id, $id]);
        if ($user && password_verify($pass, $user['password'])) {
            login_record($id, true);
            $_SESSION['user_id'] = (int)$user['id'];
            $_SESSION['last_seen'] = time();
            session_regenerate_id(true);
            q("UPDATE users SET last_login_at = NOW() WHERE id = ?", [$user['id']]);
            flash('Welcome back, ' . $user['full_name'] . '!');
            redirect(is_admin($user) ? 'admin' : (is_vendor($user) ? 'vendor' : ''));
        }
        login_record($id, false);
        $error = 'Wrong phone/email or password.';
        sleep(1); // damper on top of the 15-minute lockout
    }
}
include __DIR__ . '/../views/layout_top.php';
?>
<div class="container section auth-page">
  <form class="panel auth-panel" method="post">
    <?= csrf_field() ?>
    <h1>Log in</h1>
    <?php if ($error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?>
    <label>Phone or email <input name="identity" required value="<?= e($_POST['identity'] ?? '') ?>"></label>
    <label>Password <input type="password" name="password" required></label>
    <button class="btn btn-primary btn-block">Log in</button>
    <p class="muted"><a href="<?= url('forgot-password') ?>">Forgot password?</a></p>
    <p class="muted">New here? <a href="<?= url('register') ?>">Create an account</a></p>
  </form>
</div>
<?php include __DIR__ . '/../views/layout_bottom.php'; ?>
