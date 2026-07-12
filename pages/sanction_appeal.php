<?php
/** Public appeal form for suspended/banned accounts. */
$pageTitle = 'Appeal account action';
$submitted = false;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $phone = preg_replace('/[^\d+]/', '', $_POST['phone'] ?? '');
    $message = trim($_POST['message'] ?? '');

    if (strlen($phone) < 9) $errors[] = 'Enter the phone number on your account.';
    if (mb_strlen($message) < 20) $errors[] = 'Please explain your appeal in at least 20 characters.';

    if (!$errors && db_table_exists('account_sanctions')) {
        $user = row("SELECT id FROM users WHERE phone = ? AND status IN ('suspended','banned')", [$phone]);
        if ($user) {
            $sanction = active_account_sanction((int)$user['id']);
            if ($sanction) {
                q("UPDATE account_sanctions
                   SET appeal_status = 'pending', appeal_message = ?, appealed_at = NOW(), appeal_response = NULL, reviewed_by = NULL, reviewed_at = NULL
                   WHERE id = ?", [mb_substr($message, 0, 5000), $sanction['id']]);
                foreach (rows("SELECT id FROM users WHERE account_type IN ('admin','super_admin') AND status = 'active'") as $adm) {
                    notify((int)$adm['id'], 'sanction_appeal', 'Account sanction appeal submitted', 'admin/users', 'Phone: ' . $phone, true);
                }
            }
        }
        $submitted = true; // avoid account/status enumeration
    } elseif (!$errors) {
        $errors[] = 'Appeals are not enabled until the latest database migration is applied.';
    }
}

include __DIR__ . '/../views/layout_top.php';
?>
<div class="container section auth-wrap">
  <div class="auth-panel">
    <h1>Appeal account action</h1>
    <p class="muted">If your account was suspended or banned, submit the phone number on the account and explain why the decision should be reviewed.</p>
    <?php if ($submitted): ?>
      <div role="alert" class="alert alert-success"><span>If the phone number matches a sanctioned account, your appeal has been sent for review.</span></div>
    <?php else: ?>
      <?php if ($errors): ?><div role="alert" class="alert alert-error"><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
      <form method="post">
        <?= csrf_field() ?>
        <label>Account phone <input name="phone" required placeholder="09…" value="<?= e($_POST['phone'] ?? '') ?>"></label>
        <label>Appeal message <textarea name="message" rows="5" required maxlength="5000" placeholder="Explain what happened and what should be corrected."><?= e($_POST['message'] ?? '') ?></textarea></label>
        <button class="btn btn-primary btn-block">Submit appeal</button>
      </form>
    <?php endif; ?>
    <p class="muted small mt-3"><a href="<?= url('login') ?>">Back to login</a></p>
  </div>
</div>
<?php include __DIR__ . '/../views/layout_bottom.php'; ?>
