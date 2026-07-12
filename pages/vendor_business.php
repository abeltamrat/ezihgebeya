<?php
$u = require_vendor();
$biz = my_business($u);
$pageTitle = $biz ? 'Edit Business Profile' : 'Register Business';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $name = trim($_POST['business_name'] ?? '');
    $desc = trim($_POST['description'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $city = $_POST['city'] ?? '';
    $subcity = trim($_POST['subcity'] ?? '');
    $area = trim($_POST['area_name'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $tin = trim($_POST['tin_number'] ?? '');
    $license = trim($_POST['license_number'] ?? '');

    if (mb_strlen($name) < 2) $errors[] = 'Business name required.';
    if (!isset(CITIES[$city])) $errors[] = 'Select a city.';
    if (strlen($phone) < 9) $errors[] = 'Business phone required.';

    if (!$errors) {
        $logo = upload_image($_FILES['logo'] ?? [], 'businesses') ?? ($biz['logo'] ?? null);
        $cover = upload_image($_FILES['cover_image'] ?? [], 'businesses') ?? ($biz['cover_image'] ?? null);
        if ($biz) {
            q("UPDATE businesses SET business_name=?, description=?, phone=?, city=?, subcity=?, area_name=?, address=?, tin_number=?, license_number=?, logo=?, cover_image=?,
               status = IF(status = 'rejected', 'pending', status) WHERE id=?",
              [$name, $desc, $phone, $city, $subcity, $area, $address, $tin, $license, $logo, $cover, $biz['id']]);
            flash('Business profile updated.');
        } else {
            $bizStatus = sys('moderation.auto_approve_businesses') ? 'active' : 'pending'; // §16.3 policy switch
            q("INSERT INTO businesses (user_id, business_name, slug, business_type, description, phone, city, subcity, area_name, address, tin_number, license_number, logo, cover_image, verification_status, status)
               VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'phone_verified', ?)",
              [$u['id'], $name, slugify($name, 'businesses'), $u['account_type'] === 'customer' ? 'mixed' : $u['account_type'],
               $desc, $phone, $city, $subcity, $area, $address, $tin, $license, $logo, $cover, $bizStatus]);
            flash($bizStatus === 'active' ? 'Business created — you can start listing right away!'
                                          : 'Business submitted! An admin will review and approve it shortly.');
        }
        redirect('vendor');
    }
}
include __DIR__ . '/../views/layout_top.php';
?>
<div class="container section dash-layout">
  <?php include __DIR__ . '/../views/vendor_nav.php'; ?>
  <div class="dash-main">
    <h1><?= e($pageTitle) ?></h1>
    <?php if ($biz): ?>
      <p>Status: <span class="badge badge-status-<?= e($biz['status']) ?>"><?= e($biz['status']) ?></span>
         · Verification: <?= verified_badge($biz['verification_status']) ?: '<span class="badge badge-muted">unverified</span>' ?>
         <?php if ($biz['status'] === 'active'): ?> · <a href="<?= url('businesses/' . e($biz['slug'])) ?>">View public page →</a><?php endif; ?></p>
    <?php endif; ?>
    <?php if ($errors): ?><div role="alert" class="alert alert-error mb-3"><ul class="list-disc list-inside text-sm"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <form class="panel form-2col" method="post" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <label>Business name * <input name="business_name" required value="<?= e($_POST['business_name'] ?? $biz['business_name'] ?? '') ?>"></label>
      <label>Business phone * <input name="phone" required value="<?= e($_POST['phone'] ?? $biz['phone'] ?? $u['phone']) ?>"></label>
      <label>City *
        <select name="city" id="city-select" required>
          <option value="">Select…</option>
          <?php $selCity = $_POST['city'] ?? $biz['city'] ?? ''; foreach (array_keys(CITIES) as $c): ?>
            <option <?= $selCity === $c ? 'selected' : '' ?>><?= e($c) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Sub-city
        <select name="subcity" id="subcity-select" data-selected="<?= e($_POST['subcity'] ?? $biz['subcity'] ?? '') ?>">
          <option value="">Select…</option>
          <?php foreach (CITIES[$selCity] ?? [] as $s): ?><option <?= ($biz['subcity'] ?? '') === $s ? 'selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label>Area / neighborhood <input name="area_name" value="<?= e($_POST['area_name'] ?? $biz['area_name'] ?? '') ?>" placeholder="e.g. Wollo Sefer"></label>
      <label>Street address <input name="address" value="<?= e($_POST['address'] ?? $biz['address'] ?? '') ?>"></label>
      <label class="span2">Description <textarea name="description" rows="4" placeholder="What do you sell or what services do you offer?"><?= e($_POST['description'] ?? $biz['description'] ?? '') ?></textarea></label>
      <label>TIN number <input name="tin_number" value="<?= e($_POST['tin_number'] ?? $biz['tin_number'] ?? '') ?>" placeholder="For verification badge"></label>
      <label>Business license no. <input name="license_number" value="<?= e($_POST['license_number'] ?? $biz['license_number'] ?? '') ?>"></label>
      <label>Logo <input type="file" name="logo" accept="image/*"></label>
      <label>Cover image <input type="file" name="cover_image" accept="image/*"></label>
      <div class="span2"><button class="btn btn-primary"><?= $biz ? 'Save changes' : 'Submit for approval' ?></button></div>
    </form>
    <p class="muted small">Providing TIN and license number makes you eligible for the ✔ Verified badge, higher ranking and promotions.</p>
  </div>
</div>
<?php include __DIR__ . '/../views/layout_bottom.php'; ?>
