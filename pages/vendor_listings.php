<?php
/** Vendor listing management. Expects $ltype (product|service|supply), $action ('', new, edit, delete), $lid */
$u = require_vendor();
$biz = my_business($u);
if (!$biz) { flash('Create your business profile first.', 'error'); redirect('vendor/business'); }
if (!isset(LISTING_TABLES[$ltype])) redirect('vendor');

$table = LISTING_TABLES[$ltype];
$titleCol = listing_title_col($ltype);
$labels = ['product' => 'Products', 'service' => 'Services', 'supply' => 'Supplies'];
$pageTitle = 'My ' . $labels[$ltype];
$cats = rows("SELECT * FROM categories WHERE type = ? AND status = 'active' ORDER BY sort_order", [$ltype]);

$item = null;
if (in_array($action, ['edit', 'delete'], true)) {
    $item = row("SELECT * FROM `$table` WHERE id = ? AND business_id = ?", [$lid, $biz['id']]);
    if (!$item) redirect("vendor/listings/$ltype");
}

// ----- delete -----
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    q("UPDATE `$table` SET status = 'deleted' WHERE id = ?", [$item['id']]);
    flash('Listing deleted.');
    redirect("vendor/listings/$ltype");
}

// ----- plan limit (§26.2) -----
if ($action === 'new' && !can_add_listing($biz['id'])) {
    flash('Listing limit reached for your ' . plans()[current_plan($biz['id'])]['label'] . ' plan. Upgrade to add more.', 'error');
    redirect('vendor/subscription');
}

// ----- create / update -----
$errors = [];
if (in_array($action, ['new', 'edit'], true) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $title = trim($_POST['title'] ?? '');
    $catId = (int)($_POST['category_id'] ?? 0);
    $desc = trim($_POST['description'] ?? '');
    $city = $_POST['city'] ?? $biz['city'];
    $subcity = trim($_POST['subcity'] ?? '');

    if (mb_strlen($title) < 3) $errors[] = 'Title is required (min 3 characters).';
    if (!in_array($catId, array_column($cats, 'id'))) $errors[] = 'Select a category.';
    if (!isset(CITIES[$city])) $errors[] = 'Select a city.';

    if (!$errors) {
        $slug = $item ? $item['slug'] : slugify($title . '-' . $city, $table);
        if ($ltype === 'product') {
            $data = [$catId, $title, $desc, $_POST['product_type'] ?? 'ready_made', $_POST['condition_type'] ?? 'new',
                (float)($_POST['price'] ?? 0) ?: null, (float)($_POST['discount_price'] ?? 0) ?: null,
                isset($_POST['is_negotiable']) ? 1 : 0, (int)($_POST['stock_quantity'] ?? 0),
                trim($_POST['material'] ?? ''), trim($_POST['brand'] ?? ''), trim($_POST['color'] ?? ''),
                trim($_POST['dimensions'] ?? ''), trim($_POST['warranty'] ?? ''),
                isset($_POST['delivery_available']) ? 1 : 0, isset($_POST['installation_available']) ? 1 : 0,
                isset($_POST['customization_available']) ? 1 : 0, $city, $subcity];
            $cols = "category_id=?, title=?, description=?, product_type=?, condition_type=?, price=?, discount_price=?, is_negotiable=?, stock_quantity=?, material=?, brand=?, color=?, dimensions=?, warranty=?, delivery_available=?, installation_available=?, customization_available=?, city=?, subcity=?";
        } elseif ($ltype === 'service') {
            $data = [$catId, $title, $desc, (int)($_POST['experience_years'] ?? 0),
                $_POST['price_type'] ?? 'quote_required', (float)($_POST['starting_price'] ?? 0) ?: null, $city, $subcity];
            $cols = "category_id=?, title=?, description=?, experience_years=?, price_type=?, starting_price=?, city=?, subcity=?";
        } else {
            $data = [$catId, $title, $desc, trim($_POST['brand'] ?? ''), trim($_POST['grade'] ?? ''),
                trim($_POST['size'] ?? ''), trim($_POST['thickness'] ?? ''), $_POST['unit_of_measurement'] ?? 'piece',
                (float)($_POST['price_per_unit'] ?? 0) ?: null, (float)($_POST['bulk_price'] ?? 0) ?: null,
                (float)($_POST['minimum_order_quantity'] ?? 1) ?: 1, (float)($_POST['stock_quantity'] ?? 0),
                isset($_POST['delivery_available']) ? 1 : 0, $city, $subcity];
            $cols = "category_id=?, name=?, description=?, brand=?, grade=?, size=?, thickness=?, unit_of_measurement=?, price_per_unit=?, bulk_price=?, minimum_order_quantity=?, stock_quantity=?, delivery_available=?, city=?, subcity=?";
        }

        $newStatus = sys('moderation.auto_approve_listings') ? 'active' : 'pending_review'; // §16.3 policy switch
        if ($item) {
            q("UPDATE `$table` SET $cols, status = ? WHERE id = ?", [...$data, $newStatus, $item['id']]);
            $newId = $item['id'];
            flash($newStatus === 'active' ? 'Listing updated and live.' : 'Listing updated — re-submitted for review.');
        } else {
            q("INSERT INTO `$table` SET business_id = " . (int)$biz['id'] . ", slug = " . db()->quote($slug) . ", $cols, status = " . db()->quote($newStatus), $data);
            $newId = (int)db()->lastInsertId();
            flash($newStatus === 'active' ? 'Listing created and live!' : 'Listing created! It will be public after admin approval.');
        }

        // images (max per listing set in admin → Settings → Limits)
        if ($ltype === 'product') {
            foreach (array_slice($_FILES['images']['name'] ?? [], 0, (int)sys('limits.max_images_per_listing', 6), true) as $k => $n) {
                if (!$n) continue;
                $f = ['name' => $n, 'type' => $_FILES['images']['type'][$k], 'tmp_name' => $_FILES['images']['tmp_name'][$k],
                      'error' => $_FILES['images']['error'][$k], 'size' => $_FILES['images']['size'][$k]];
                $path = upload_image($f, 'products');
                if ($path) {
                    $isFirst = !val("SELECT COUNT(*) FROM product_media WHERE product_id = ?", [$newId]);
                    q("INSERT INTO product_media (product_id, file_url, is_primary) VALUES (?,?,?)", [$newId, $path, $isFirst ? 1 : 0]);
                }
            }
            // AR 3D models (§7) — premium plan only, and only while the AR module is enabled
            if (feature_enabled('ar') && current_plan($biz['id']) === 'premium') {
                foreach (['model_glb' => ['glb', 'model_3d_glb'], 'model_usdz' => ['usdz', 'model_3d_usdz']] as $field => [$ext, $mtype]) {
                    $mp = upload_model($_FILES[$field] ?? [], $ext);
                    if ($mp) {
                        q("DELETE FROM product_media WHERE product_id = ? AND media_type = ?", [$newId, $mtype]);
                        q("INSERT INTO product_media (product_id, media_type, file_url) VALUES (?,?,?)", [$newId, $mtype, $mp]);
                    }
                }
            }
        } else {
            $path = upload_image($_FILES['image'] ?? [], $table);
            if ($path) q("UPDATE `$table` SET image = ? WHERE id = ?", [$path, $newId]);
        }
        redirect("vendor/listings/$ltype");
    }
}

$list = ($action === '') ? rows("SELECT l.*, c.name c_name FROM `$table` l JOIN categories c ON c.id = l.category_id
    WHERE l.business_id = ? AND l.status != 'deleted' ORDER BY l.created_at DESC", [$biz['id']]) : [];

include __DIR__ . '/../views/layout_top.php';
?>
<div class="container section dash-layout">
  <?php include __DIR__ . '/../views/vendor_nav.php'; ?>
  <div class="dash-main">

  <?php if ($action === '' ): ?>
    <div class="section-head">
      <h1>My <?= $labels[$ltype] ?> (<?= count($list) ?>)</h1>
      <a class="btn btn-primary" href="<?= url("vendor/listings/$ltype/new") ?>">+ Add <?= rtrim(strtolower($labels[$ltype]), 's') ?></a>
    </div>
    <?php if (!$list): ?><div class="empty-state">No <?= strtolower($labels[$ltype]) ?> yet.</div>
    <?php else: ?>
    <div class="table-wrap"><table class="data-table">
      <tr><th>Title</th><th>Category</th><th>Price</th><th>Status</th><th>Views</th><th>Inquiries</th><th></th></tr>
      <?php foreach ($list as $l): ?>
      <tr>
        <td><?= e($l[$titleCol]) ?></td>
        <td><?= e($l['c_name']) ?></td>
        <td><?= money($l['price'] ?? $l['starting_price'] ?? $l['price_per_unit'] ?? null) ?: '—' ?></td>
        <td><span class="badge badge-status-<?= e($l['status']) ?>"><?= e(str_replace('_', ' ', $l['status'])) ?></span></td>
        <td><?= (int)$l['views_count'] ?></td>
        <td><?= (int)$l['inquiries_count'] ?></td>
        <td class="row-actions">
          <?php if ($l['status'] === 'active'): ?><a href="<?= listing_url($ltype, $l) ?>" title="View">👁</a><?php endif; ?>
          <a href="<?= url("vendor/listings/$ltype/edit/{$l['id']}") ?>" title="Edit">✏️</a>
          <form method="post" action="<?= url("vendor/listings/$ltype/delete/{$l['id']}") ?>" onsubmit="return confirm('Delete this listing?')">
            <?= csrf_field() ?><button title="Delete">🗑</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </table></div>
    <?php endif; ?>

  <?php else: $v = fn($k, $d = '') => e($_POST[$k] ?? $item[$k] ?? $d); ?>
    <h1><?= $item ? 'Edit' : 'New' ?> <?= rtrim($labels[$ltype], 's') ?></h1>
    <?php foreach ($errors as $er): ?><div class="flash flash-error"><?= e($er) ?></div><?php endforeach; ?>
    <form class="panel form-2col" method="post" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <label class="span2">Title * <input name="title" required value="<?= $item ? e($item[$titleCol]) : e($_POST['title'] ?? '') ?>"></label>
      <label>Category *
        <select name="category_id" required>
          <option value="">Select…</option>
          <?php foreach ($cats as $c): ?>
            <option value="<?= $c['id'] ?>" <?= (int)($_POST['category_id'] ?? $item['category_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>City *
        <select name="city" id="city-select" required>
          <?php $selCity = $_POST['city'] ?? $item['city'] ?? $biz['city']; foreach (array_keys(CITIES) as $c): ?>
            <option <?= $selCity === $c ? 'selected' : '' ?>><?= e($c) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Sub-city
        <select name="subcity" id="subcity-select" data-selected="<?= $v('subcity') ?>">
          <option value="">Select…</option>
          <?php foreach (CITIES[$selCity] ?? [] as $s): ?><option <?= ($item['subcity'] ?? '') === $s ? 'selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?>
        </select>
      </label>

      <?php if ($ltype === 'product'): ?>
        <label>Type
          <select name="product_type">
            <?php foreach (PRODUCT_TYPES as $k => $l): ?><option value="<?= $k ?>" <?= ($_POST['product_type'] ?? $item['product_type'] ?? '') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
          </select>
        </label>
        <label>Condition
          <select name="condition_type">
            <?php foreach (['new', 'used', 'refurbished'] as $c): ?><option <?= ($_POST['condition_type'] ?? $item['condition_type'] ?? 'new') === $c ? 'selected' : '' ?>><?= $c ?></option><?php endforeach; ?>
          </select>
        </label>
        <label>Price (ETB) <input type="number" step="0.01" name="price" value="<?= $v('price') ?>"></label>
        <label>Discount price <input type="number" step="0.01" name="discount_price" value="<?= $v('discount_price') ?>"></label>
        <label>Stock quantity <input type="number" name="stock_quantity" value="<?= $v('stock_quantity', 1) ?>"></label>
        <label>Material <input name="material" value="<?= $v('material') ?>"></label>
        <label>Brand <input name="brand" value="<?= $v('brand') ?>"></label>
        <label>Color <input name="color" value="<?= $v('color') ?>"></label>
        <label>Dimensions <input name="dimensions" value="<?= $v('dimensions') ?>" placeholder="e.g. 220×90×85 cm"></label>
        <label>Warranty <input name="warranty" value="<?= $v('warranty') ?>" placeholder="e.g. 1 year"></label>
        <div class="span2 check-row">
          <label class="check"><input type="checkbox" name="is_negotiable" <?= !empty($_POST['is_negotiable'] ?? $item['is_negotiable'] ?? 0) ? 'checked' : '' ?>> Negotiable</label>
          <label class="check"><input type="checkbox" name="delivery_available" <?= !empty($_POST['delivery_available'] ?? $item['delivery_available'] ?? 0) ? 'checked' : '' ?>> Delivery</label>
          <label class="check"><input type="checkbox" name="installation_available" <?= !empty($_POST['installation_available'] ?? $item['installation_available'] ?? 0) ? 'checked' : '' ?>> Installation</label>
          <label class="check"><input type="checkbox" name="customization_available" <?= !empty($_POST['customization_available'] ?? $item['customization_available'] ?? 0) ? 'checked' : '' ?>> Customization</label>
        </div>
        <label class="span2">Images (up to <?= (int)sys('limits.max_images_per_listing', 6) ?>) <input type="file" name="images[]" accept="image/*" multiple></label>
        <?php if (feature_enabled('ar') && current_plan($biz['id']) === 'premium'): ?>
          <label>AR model — .glb (Android/web) <input type="file" name="model_glb" accept=".glb"></label>
          <label>AR model — .usdz (iOS) <input type="file" name="model_usdz" accept=".usdz"></label>
          <p class="muted small span2">Only <strong>.glb</strong> and <strong>.usdz</strong> are supported — plain <strong>.obj</strong> files won't preview in AR.
            If your model is an .obj, convert it first: try this free
            <a href="https://products.aspose.app/3d/conversion/obj-to-glb" target="_blank" rel="noopener">OBJ → GLB converter ↗</a>
            (upload your .obj + .mtl, download the .glb), or in Blender (free): <em>File → Import → Wavefront (.obj)</em>, then <em>File → Export → glTF 2.0 (.glb)</em>.</p>
        <?php else: ?>
          <p class="muted small span2">💎 3D/AR product preview is available on the Premium plan (<a href="<?= url('vendor/subscription') ?>">upgrade</a>).</p>
        <?php endif; ?>

      <?php elseif ($ltype === 'service'): ?>
        <label>Experience (years) <input type="number" name="experience_years" value="<?= $v('experience_years', 0) ?>"></label>
        <label>Pricing type
          <select name="price_type">
            <?php foreach (PRICE_TYPES as $k => $l): ?><option value="<?= $k ?>" <?= ($_POST['price_type'] ?? $item['price_type'] ?? 'quote_required') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
          </select>
        </label>
        <label>Starting price (ETB) <input type="number" step="0.01" name="starting_price" value="<?= $v('starting_price') ?>"></label>
        <label>Cover image <input type="file" name="image" accept="image/*"></label>

      <?php else: ?>
        <label>Brand <input name="brand" value="<?= $v('brand') ?>"></label>
        <label>Grade <input name="grade" value="<?= $v('grade') ?>"></label>
        <label>Size <input name="size" value="<?= $v('size') ?>" placeholder="e.g. 122×244 cm"></label>
        <label>Thickness <input name="thickness" value="<?= $v('thickness') ?>" placeholder="e.g. 18mm"></label>
        <label>Unit
          <select name="unit_of_measurement">
            <?php foreach (SUPPLY_UNITS as $un): ?><option <?= ($_POST['unit_of_measurement'] ?? $item['unit_of_measurement'] ?? 'piece') === $un ? 'selected' : '' ?>><?= $un ?></option><?php endforeach; ?>
          </select>
        </label>
        <label>Price per unit (ETB) <input type="number" step="0.01" name="price_per_unit" value="<?= $v('price_per_unit') ?>"></label>
        <label>Bulk price <input type="number" step="0.01" name="bulk_price" value="<?= $v('bulk_price') ?>"></label>
        <label>Min. order qty <input type="number" step="0.01" name="minimum_order_quantity" value="<?= $v('minimum_order_quantity', 1) ?>"></label>
        <label>Stock quantity <input type="number" step="0.01" name="stock_quantity" value="<?= $v('stock_quantity', 0) ?>"></label>
        <div class="span2"><label class="check"><input type="checkbox" name="delivery_available" <?= !empty($_POST['delivery_available'] ?? $item['delivery_available'] ?? 0) ? 'checked' : '' ?>> Delivery available</label></div>
        <label>Image <input type="file" name="image" accept="image/*"></label>
      <?php endif; ?>

      <label class="span2">Description <textarea name="description" rows="5"><?= $v('description') ?></textarea></label>
      <div class="span2">
        <button class="btn btn-primary"><?= $item ? 'Save changes' : 'Create listing' ?></button>
        <a class="btn btn-ghost" href="<?= url("vendor/listings/$ltype") ?>">Cancel</a>
      </div>
    </form>
  <?php endif; ?>
  </div>
</div>
<?php include __DIR__ . '/../views/layout_bottom.php'; ?>
