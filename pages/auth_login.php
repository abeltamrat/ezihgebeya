<?php
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$existingUser = auth();
$returnTo = safe_return_path($_POST['return_to'] ?? $_GET['return'] ?? ($_SESSION['return_to'] ?? ''), '');
if ($existingUser) redirect(safe_return_path($returnTo, default_post_login_path($existingUser)));
$pageTitle = 'Log in';
$error = null;
$quickLoginError = null;
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
        remembered_login_forget(trim((string)($_POST['profile_selector'] ?? '')) ?: null);
        flash('The selected quick-login profile was removed from this device.');
        redirect($loginPath);
    }

    if ($action === 'quick_login') {
        $profileSelector = trim((string)($_POST['profile_selector'] ?? ''));
        $remembered = remembered_login_find($profileSelector);
        $pass = (string)($_POST['password'] ?? '');
        $identity = $remembered ? (string)($remembered['phone'] ?: $remembered['email'] ?: ('user:' . $remembered['user_id'])) : '';
        if (!$remembered) {
            $quickLoginError = 'This saved login expired or was removed. Use your phone or email below.';
        } elseif (login_throttled($identity)) {
            $quickLoginError = 'Too many failed attempts — try again in 15 minutes or reset your password.';
        } else {
            $user = row("SELECT * FROM users WHERE id = ? AND status = 'active'", [(int)$remembered['user_id']]);
            if ($user && password_verify($pass, (string)$user['password'])) {
                login_record($identity, true);
                $rotated = remembered_login_authenticate($profileSelector);
                if ($rotated) $finishLogin(array_merge($user, $rotated));
                $quickLoginError = 'This saved login expired. Use your phone or email below.';
            } else {
                login_record($identity, false);
                $quickLoginError = 'Wrong password for this saved account.';
                sleep(1);
            }
        }
    } else {
        $id = trim($_POST['identity'] ?? '');
        $pass = $_POST['password'] ?? '';
        if (login_throttled($id)) { // §22.1.4
            $error = 'Too many failed attempts — try again in 15 minutes or reset your password.';
        } else {
            $user = row("SELECT * FROM users WHERE (phone = ? OR email = ?) AND status IN ('active','pending')", [$id, $id]);
            if ($user && password_verify($pass, $user['password'])) {
                login_record($id, true);
                // Every eligible account is added to this browser's password-only
                // quick-login profile list. No password is stored on the device.
                if (!remembered_login_create((int)$user['id']) && $user['status'] === 'active') {
                    flash('You are logged in, but this device could not save the quick-login profile.', 'error');
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

$rememberedUsers = remembered_login_find_all();
$requestedProfileSelector = trim((string)($_POST['profile_selector'] ?? ''));
$rememberedUser = null;
foreach ($rememberedUsers as $candidate) {
    if ($requestedProfileSelector !== '' && hash_equals((string)$candidate['selector'], $requestedProfileSelector)) {
        $rememberedUser = $candidate;
        break;
    }
}
$rememberedUser ??= $rememberedUsers[0] ?? null;
$rememberedFirstName = $rememberedUser ? (explode(' ', trim((string)$rememberedUser['full_name']))[0] ?: 'your account') : '';
$quickLoginRoleLabels = [
    'super_admin' => 'Super Admin',
    'admin' => 'Admin',
    'seller' => 'Furniture Seller',
    'manufacturer' => 'Manufacturer',
    'customer' => 'Customer',
    'supplier' => 'Supply Vendor',
    'service_provider' => 'Service Provider',
];
$rememberedRole = $rememberedUser
    ? ($quickLoginRoleLabels[$rememberedUser['account_type']] ?? ucwords(str_replace('_', ' ', $rememberedUser['account_type'])))
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
          <p class="quick-profile-prompt">Choose a saved account</p>
          <div class="quick-profile-carousel">
            <button type="button" class="quick-profile-nav quick-profile-prev" aria-label="Show previous saved accounts">‹</button>
            <div class="quick-profile-list" role="listbox" aria-label="Saved accounts" tabindex="0">
            <?php foreach ($rememberedUsers as $profile):
              $profileFirstName = explode(' ', trim((string)$profile['full_name']))[0] ?: 'Account';
              $isSelected = (string)$profile['selector'] === (string)$rememberedUser['selector'];
              $profileType = (string)$profile['account_type'];
              $profileRole = $quickLoginRoleLabels[$profileType] ?? ucwords(str_replace('_', ' ', $profileType));
            ?>
              <div class="quick-profile-item">
                <button type="button" class="quick-profile quick-profile--<?= e(str_replace('_', '-', $profileType)) ?> <?= $isSelected ? 'is-selected' : '' ?>"
                        role="option" aria-selected="<?= $isSelected ? 'true' : 'false' ?>"
                        aria-label="<?= e('Quick login as ' . $profile['full_name'] . ', ' . $profileRole) ?>"
                        title="<?= e($profileRole) ?>"
                        data-selector="<?= e($profile['selector']) ?>" data-name="<?= e($profileFirstName) ?>"
                        data-full-name="<?= e($profile['full_name']) ?>" data-role="<?= e($profileRole) ?>"
                        data-initial="<?= e(mb_strtoupper(mb_substr($profile['full_name'], 0, 1))) ?>"
                        data-profile-type="<?= e(str_replace('_', '-', $profileType)) ?>">
                  <span class="quick-profile-avatar" aria-hidden="true">
                    <?= e(mb_strtoupper(mb_substr($profile['full_name'], 0, 1))) ?>
                    <span class="quick-profile-role"><?= e($profileRole) ?></span>
                  </span>
                  <span class="quick-profile-name"><?= e($profileFirstName) ?></span>
                </button>
                <button type="button" class="quick-profile-remove"
                        aria-label="<?= e('Remove ' . $profile['full_name'] . ' from quick login') ?>"
                        title="Remove from this device"
                        data-remove-selector="<?= e($profile['selector']) ?>"
                        data-remove-name="<?= e($profileFirstName) ?>">×</button>
              </div>
            <?php endforeach; ?>
            <a class="quick-profile quick-profile-new" href="#password-login" aria-label="Log in with another account">
              <span class="quick-profile-avatar" aria-hidden="true">+<span class="quick-profile-role">New</span></span>
              <span class="quick-profile-name">Other</span>
            </a>
            </div>
            <button type="button" class="quick-profile-nav quick-profile-next" aria-label="Show more saved accounts">›</button>
          </div>

          <div class="remembered-actions">
            <a class="btn btn-ghost btn-sm" href="#password-login">Use another account</a>
          </div>
        </div>
        <p class="remembered-note">
          <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 9v4m0 4h.01M10.3 4.7 2.7 18a2 2 0 0 0 1.7 3h15.2a2 2 0 0 0 1.7-3L13.7 4.7a2 2 0 0 0-3.4 0Z"/></svg>
          This device remembers the account, but the password is still required. Remove quick login on a shared device.
        </p>
      </section>

      <dialog class="quick-login-modal" id="quick-login-modal" aria-labelledby="quick-modal-title">
        <div class="quick-login-modal-card">
          <button type="button" class="quick-modal-close" aria-label="Close quick login">×</button>
          <div class="quick-modal-account">
            <span class="quick-modal-avatar quick-profile--<?= e(str_replace('_', '-', $rememberedUser['account_type'])) ?>" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($rememberedUser['full_name'], 0, 1))) ?></span>
            <div>
              <p class="auth-eyebrow">Quick login</p>
              <h2 id="quick-modal-title">Continue as <?= e($rememberedFirstName) ?></h2>
              <span class="quick-modal-role"><?= e($rememberedRole) ?></span>
            </div>
          </div>
          <form method="post" class="remembered-continue-form">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="quick_login">
            <input type="hidden" name="return_to" value="<?= e($returnTo) ?>">
            <input type="hidden" name="profile_selector" value="<?= e($rememberedUser['selector']) ?>">
            <?php if ($quickLoginError): ?>
              <div role="alert" class="alert alert-error mb-3"><span><?= e($quickLoginError) ?></span></div>
            <?php endif; ?>
            <label>
              <span id="quick-password-label">Password for <?= e($rememberedFirstName) ?></span>
              <input type="password" name="password" autocomplete="current-password" required
                     aria-label="Password for <?= e($rememberedUser['full_name']) ?>">
            </label>
            <button class="btn btn-primary btn-block" type="submit">
              <span class="quick-modal-submit-text">Log in as <?= e($rememberedFirstName) ?></span>
              <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
            </button>
            <a class="quick-modal-forgot" href="<?= url('forgot-password') ?>">Forgot password?</a>
          </form>
        </div>
      </dialog>

      <form method="post" class="quick-remove-form" hidden>
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="forget_device">
        <input type="hidden" name="return_to" value="<?= e($returnTo) ?>">
        <input type="hidden" name="profile_selector" value="">
      </form>

      <dialog class="quick-remove-modal" id="quick-remove-modal" aria-labelledby="quick-remove-title">
        <div class="quick-remove-modal-card">
          <span class="quick-remove-warning" aria-hidden="true">×</span>
          <h2 id="quick-remove-title">Remove saved account?</h2>
          <p><strong class="quick-remove-account-name">This account</strong> will be removed from Quick Login on this device. The account itself will not be deleted.</p>
          <div class="quick-remove-actions">
            <button type="button" class="btn btn-outline quick-remove-cancel">Cancel</button>
            <button type="button" class="btn btn-error quick-remove-confirm">Remove account</button>
          </div>
        </div>
      </dialog>
      <div class="auth-divider"><span>or use another account</span></div>
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
        <div class="auth-quick-save-note">
          <span aria-hidden="true">✓</span>
          <p><strong>Quick login is on</strong><small>This account will be remembered on this device for 30 days. Your password is never stored.</small></p>
        </div>
      <?php endif; ?>
      <button class="btn btn-primary btn-block" type="submit"><?= system_ui_icon('login', 'Log in') ?> Log in</button>
      <p class="muted"><a href="<?= url('forgot-password') ?>">Forgot password?</a></p>
      <p class="muted">Account suspended or banned? <a href="<?= url('appeal') ?>">Submit an appeal</a></p>
      <p class="muted">New here? <a href="<?= url('register' . ($returnTo !== '' ? '?return=' . rawurlencode($returnTo) : '')) ?>">Create an account</a></p>
    </form>
  </div>
</div>
<?php if ($rememberedUser): ?>
<script>
(() => {
  const profiles = document.querySelectorAll('.quick-profile[data-selector]');
  const quickForm = document.querySelector('.remembered-continue-form');
  const removeForm = document.querySelector('.quick-remove-form');
  const modal = document.getElementById('quick-login-modal');
  const removeModal = document.getElementById('quick-remove-modal');
  const profileList = document.querySelector('.quick-profile-list');
  const passwordLabel = document.getElementById('quick-password-label');
  if (!quickForm || !modal || !profiles.length) return;

  const selectProfile = profile => {
    profiles.forEach(item => {
      const selected = item === profile;
      item.classList.toggle('is-selected', selected);
      item.setAttribute('aria-selected', selected ? 'true' : 'false');
    });
    const selector = profile.dataset.selector || '';
    const name = profile.dataset.name || 'this account';
    const fullName = profile.dataset.fullName || name;
    const role = profile.dataset.role || '';
    const initial = profile.dataset.initial || name.charAt(0).toUpperCase();
    const profileType = profile.dataset.profileType || 'customer';
    quickForm.querySelector('[name="profile_selector"]').value = selector;
    if (passwordLabel) passwordLabel.textContent = `Password for ${name}`;
    const password = quickForm.querySelector('[name="password"]');
    password.setAttribute('aria-label', `Password for ${fullName}`);
    password.value = '';
    modal.querySelector('#quick-modal-title').textContent = `Continue as ${name}`;
    modal.querySelector('.quick-modal-role').textContent = role;
    modal.querySelector('.quick-modal-submit-text').textContent = `Log in as ${name}`;
    const avatar = modal.querySelector('.quick-modal-avatar');
    avatar.className = `quick-modal-avatar quick-profile--${profileType}`;
    avatar.textContent = initial;
    if (!modal.open) modal.showModal();
    window.setTimeout(() => password.focus(), 40);
  };

  profiles.forEach(profile => profile.addEventListener('click', () => selectProfile(profile)));
  document.querySelectorAll('.quick-profile-remove').forEach(remove => {
    remove.addEventListener('click', event => {
      event.stopPropagation();
      const name = remove.dataset.removeName || 'this account';
      removeForm.querySelector('[name="profile_selector"]').value = remove.dataset.removeSelector || '';
      removeModal.querySelector('.quick-remove-account-name').textContent = name;
      removeModal.showModal();
    });
  });
  const scrollProfiles = direction => {
    const item = profileList.querySelector('.quick-profile-item, .quick-profile');
    const amount = item ? item.getBoundingClientRect().width * 3 + 28 : 260;
    profileList.scrollBy({ left: direction * amount, behavior: 'smooth' });
  };
  document.querySelector('.quick-profile-prev')?.addEventListener('click', () => scrollProfiles(-1));
  document.querySelector('.quick-profile-next')?.addEventListener('click', () => scrollProfiles(1));
  modal.querySelector('.quick-modal-close').addEventListener('click', () => modal.close());
  modal.addEventListener('click', event => {
    if (event.target === modal) modal.close();
  });
  removeModal.querySelector('.quick-remove-cancel').addEventListener('click', () => removeModal.close());
  removeModal.querySelector('.quick-remove-confirm').addEventListener('click', () => removeForm.requestSubmit());
  removeModal.addEventListener('click', event => {
    if (event.target === removeModal) removeModal.close();
  });
  <?php if ($quickLoginError): ?>
  selectProfile(document.querySelector('.quick-profile.is-selected') || profiles[0]);
  <?php endif; ?>
})();
</script>
<?php endif; ?>
<?php include __DIR__ . '/../views/layout_bottom.php'; ?>
