<?php
if (!software_library_ready()):
?>
  <div class="software-admin-hero">
    <div><p class="eyebrow">Vendor resources</p><h1>Software &amp; Plugins</h1><p>Publish trusted tools, installers and plugins for vendor tenants.</p></div>
  </div>
  <div class="alert alert-warning">The software library database tables are missing. Open Backups and run migrations first.</div>
<?php
return;
endif;

$editId = max(0, (int)($_GET['edit'] ?? 0));
$editSoftware = $editId ? row("SELECT * FROM software_items WHERE id = ?", [$editId]) : null;
$softwareRows = rows(
    "SELECT si.*, COUNT(ss.id) screenshot_count
     FROM software_items si LEFT JOIN software_screenshots ss ON ss.software_id = si.id
     GROUP BY si.id ORDER BY si.is_featured DESC, si.updated_at DESC"
);
$editShots = $editSoftware
    ? rows("SELECT * FROM software_screenshots WHERE software_id = ? ORDER BY sort_order, id", [$editSoftware['id']])
    : [];
$counts = [
    'published' => (int)val("SELECT COUNT(*) FROM software_items WHERE status='published'"),
    'drafts' => (int)val("SELECT COUNT(*) FROM software_items WHERE status='draft'"),
    'downloads' => (int)val("SELECT COALESCE(SUM(download_count),0) FROM software_items"),
];
$sv = fn(string $key, string $default = ''): string => e((string)($editSoftware[$key] ?? $default));
$mode = $editSoftware && $editSoftware['external_url'] ? 'external' : 'file';
?>

<div class="software-admin-hero">
  <div>
    <p class="eyebrow">Vendor resources</p>
    <h1>Software &amp; Plugins</h1>
    <p>Curate useful software, plugins and installation resources. Vendors see only published entries.</p>
  </div>
  <a class="btn btn-primary" href="<?= url('admin/software') ?>">＋ Add new</a>
</div>

<div class="software-admin-stats">
  <div><span>Published</span><strong><?= number_format($counts['published']) ?></strong><small>Visible to vendors</small></div>
  <div><span>Drafts</span><strong><?= number_format($counts['drafts']) ?></strong><small>Still being prepared</small></div>
  <div><span>Downloads</span><strong><?= number_format($counts['downloads']) ?></strong><small>Files and outbound links</small></div>
</div>

<div class="software-admin-grid">
  <section class="panel software-editor">
    <div class="software-editor-heading">
      <div><p class="eyebrow"><?= $editSoftware ? 'Editing resource' : 'New resource' ?></p><h2><?= $editSoftware ? e($editSoftware['title']) : 'Publish software or a plugin' ?></h2></div>
      <?php if ($editSoftware): ?><span class="badge badge-status-<?= e($editSoftware['status']) ?>"><?= e($editSoftware['status']) ?></span><?php endif; ?>
    </div>
    <form method="post" enctype="multipart/form-data" class="form-grid software-form">
      <?= csrf_field() ?><input type="hidden" name="do" value="software_save"><input type="hidden" name="id" value="<?= (int)($editSoftware['id'] ?? 0) ?>">

      <label>Title
        <input name="title" maxlength="180" value="<?= $sv('title') ?>" placeholder="SketchUp furniture exporter" required>
        <small>The public name vendors will recognize.</small>
      </label>
      <label>Resource type
        <select name="item_type"><option value="software" <?= ($editSoftware['item_type'] ?? '') !== 'plugin' ? 'selected' : '' ?>>Software</option><option value="plugin" <?= ($editSoftware['item_type'] ?? '') === 'plugin' ? 'selected' : '' ?>>Plugin</option></select>
        <small>Used for vendor filters and badges.</small>
      </label>
      <label class="span2">Short description
        <input name="short_description" minlength="10" maxlength="300" value="<?= $sv('short_description') ?>" placeholder="Export optimized GLB models directly from your design workflow." required>
        <small>One clear sentence displayed on the library card.</small>
      </label>
      <label class="span2">Full description
        <textarea name="description" minlength="20" rows="7" placeholder="Explain what it does, who it is for, installation steps and important requirements." required><?= $sv('description') ?></textarea>
        <small>Plain text is used for safety. Include requirements and installation guidance.</small>
      </label>
      <label>Version
        <input name="version" maxlength="80" value="<?= $sv('version') ?>" placeholder="2.4.1">
        <small>Optional current release number.</small>
      </label>
      <label>Developer / publisher
        <input name="developer" maxlength="150" value="<?= $sv('developer') ?>" placeholder="EzihGebeya Labs">
        <small>Who maintains or publishes this resource.</small>
      </label>
      <label>Category
        <input name="category" maxlength="100" value="<?= $sv('category') ?>" placeholder="3D design">
        <small>Examples: 3D design, accounting, inventory.</small>
      </label>
      <label>Platforms
        <input name="platforms" maxlength="255" value="<?= $sv('platforms') ?>" placeholder="Windows, macOS, Android">
        <small>Separate multiple platforms with commas.</small>
      </label>
      <label>Licence
        <input name="license_type" maxlength="100" value="<?= $sv('license_type') ?>" placeholder="Free, MIT, Commercial">
        <small>State the licence or pricing model accurately.</small>
      </label>
      <label>YouTube demonstration
        <input type="url" name="youtube_url" value="<?= $sv('youtube_url') ?>" placeholder="https://www.youtube.com/watch?v=...">
        <small>YouTube links are converted to a privacy-enhanced embed.</small>
      </label>

      <fieldset class="span2 software-delivery" data-software-delivery>
        <legend>Download delivery</legend>
        <div class="software-delivery-tabs">
          <label><input type="radio" name="download_mode" value="file" <?= $mode === 'file' ? 'checked' : '' ?>> <span>Upload file<small>Stored privately on EzihGebeya</small></span></label>
          <label><input type="radio" name="download_mode" value="external" <?= $mode === 'external' ? 'checked' : '' ?>> <span>External link<small>Send vendors to another website</small></span></label>
        </div>
        <label data-download-file>Package file
          <input type="file" name="software_file" accept="<?= e(implode(',', array_map(fn($ext) => '.' . $ext, software_allowed_extensions()))) ?>">
          <small>Maximum <?= SOFTWARE_UPLOAD_MAX_MB ?> MB. Allowed: <?= e(strtoupper(implode(', ', software_allowed_extensions()))) ?>.</small>
          <?php if ($editSoftware && $editSoftware['file_path']): ?><strong class="software-current-file">Current: <?= e($editSoftware['original_filename']) ?> · <?= number_format(((int)$editSoftware['file_size']) / 1048576, 1) ?> MB</strong><?php endif; ?>
        </label>
        <label data-download-external>External download URL
          <input type="url" name="external_url" value="<?= $sv('external_url') ?>" placeholder="https://developer.example.com/download">
          <small>Only HTTP/HTTPS links are accepted. Downloads still pass through EzihGebeya for counting.</small>
        </label>
      </fieldset>

      <label class="span2 software-shot-picker">Screenshots
        <input type="file" name="screenshots[]" accept="image/jpeg,image/png,image/webp,image/gif" multiple data-software-shots>
        <span><b>＋ Add screenshots</b><small>Up to <?= SOFTWARE_SCREENSHOT_LIMIT ?> total. Show the interface, workflow or result.</small></span>
        <div class="software-shot-preview" data-software-shot-preview></div>
      </label>

      <?php if ($editShots): ?>
        <div class="span2 software-existing-shots">
          <?php foreach ($editShots as $shot): ?>
            <figure>
              <img src="<?= img_url($shot['image_path']) ?>" alt="Screenshot of <?= e($editSoftware['title']) ?>">
              <button type="submit" formaction="<?= url('admin/software') ?>" formnovalidate
                      onclick="if (!confirm('Remove this screenshot?')) return false; this.form.querySelector('[name=do]').value='software_screenshot_delete'; this.form.querySelector('[name=id]').value='<?= (int)$shot['id'] ?>'" aria-label="Remove screenshot">×</button>
            </figure>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <div class="span2 software-publish-row">
        <label>Status
          <select name="software_status">
            <?php foreach (['draft' => 'Draft — admin only','published' => 'Published — visible to vendors','archived' => 'Archived — hidden'] as $value => $label): ?>
              <option value="<?= $value ?>" <?= ($editSoftware['status'] ?? 'draft') === $value ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="check"><input type="checkbox" name="is_featured" value="1" <?= !empty($editSoftware['is_featured']) ? 'checked' : '' ?>> Feature this resource</label>
        <button class="btn btn-primary"><?= $editSoftware ? 'Save changes' : 'Create resource' ?></button>
      </div>
    </form>
  </section>

  <aside class="software-admin-list">
    <div class="software-list-head"><div><p class="eyebrow">Library</p><h2><?= count($softwareRows) ?> resources</h2></div></div>
    <?php if (!$softwareRows): ?><div class="empty-state">No software or plugins yet.</div><?php endif; ?>
    <?php foreach ($softwareRows as $item): $cover = row("SELECT image_path FROM software_screenshots WHERE software_id=? ORDER BY sort_order,id LIMIT 1", [$item['id']]); ?>
      <article class="software-admin-card <?= (int)$item['id'] === $editId ? 'is-selected' : '' ?>">
        <div class="software-admin-card-cover">
          <?php if ($cover): ?><img src="<?= img_url($cover['image_path']) ?>" alt=""><?php else: ?><span><?= $item['item_type'] === 'plugin' ? 'PLG' : 'APP' ?></span><?php endif; ?>
        </div>
        <div class="software-admin-card-copy">
          <div><span class="badge"><?= e(ucfirst($item['item_type'])) ?></span><span class="badge badge-status-<?= e($item['status']) ?>"><?= e($item['status']) ?></span></div>
          <h3><?= e($item['title']) ?></h3>
          <p><?= e($item['short_description']) ?></p>
          <small><?= number_format((int)$item['download_count']) ?> downloads · <?= (int)$item['screenshot_count'] ?> screenshots<?= $item['version'] ? ' · v' . e($item['version']) : '' ?></small>
          <div class="btn-row">
            <a class="btn btn-outline btn-sm" href="<?= url('admin/software?edit=' . (int)$item['id']) ?>">Edit</a>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="software_status"><input type="hidden" name="id" value="<?= (int)$item['id'] ?>">
              <input type="hidden" name="software_status" value="<?= $item['status'] === 'published' ? 'draft' : 'published' ?>">
              <button class="btn btn-sm"><?= $item['status'] === 'published' ? 'Unpublish' : 'Publish' ?></button>
            </form>
          </div>
        </div>
      </article>
    <?php endforeach; ?>
  </aside>
</div>

<script>
(() => {
  const delivery = document.querySelector('[data-software-delivery]');
  const syncDelivery = () => {
    const external = delivery?.querySelector('[name=download_mode]:checked')?.value === 'external';
    delivery?.querySelector('[data-download-file]')?.toggleAttribute('hidden', external);
    delivery?.querySelector('[data-download-external]')?.toggleAttribute('hidden', !external);
  };
  delivery?.querySelectorAll('[name=download_mode]').forEach(input => input.addEventListener('change', syncDelivery));
  syncDelivery();

  const picker = document.querySelector('[data-software-shots]');
  const preview = document.querySelector('[data-software-shot-preview]');
  picker?.addEventListener('change', () => {
    preview.innerHTML = '';
    [...picker.files].slice(0, <?= SOFTWARE_SCREENSHOT_LIMIT ?>).forEach(file => {
      const image = document.createElement('img');
      image.alt = file.name;
      image.src = URL.createObjectURL(file);
      image.onload = () => URL.revokeObjectURL(image.src);
      preview.appendChild(image);
    });
  });
})();
</script>
