<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
requireRole('super_admin');
// Role directories are the canonical URLs — forward direct hits there
if (!defined('ENTRY_OK') && isLoggedIn()) {
    $q = $_SERVER['QUERY_STRING'] ?? '';
    header('Location: ' . url(basename(__FILE__, '.php')) . ($q !== '' ? "?$q" : ''));
    exit;
}

$db        = getDB();
$pageTitle = 'Business Settings';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    // ── Logo upload / removal ──
    if (!empty($_POST['remove_logo'])) {
        $old = getSetting('business_logo');
        if ($old && is_file(__DIR__ . '/' . ltrim($old, '/'))) { @unlink(__DIR__ . '/' . ltrim($old, '/')); }
        try { saveSetting('business_logo', ''); } catch (PDOException) {}
    } elseif (!empty($_FILES['logo']['name']) && ($_FILES['logo']['error'] ?? 1) === UPLOAD_ERR_OK) {
        $tmp  = $_FILES['logo']['tmp_name'];
        $info = @getimagesize($tmp);
        $mimeExt = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
        if (!$info || !isset($mimeExt[$info['mime']])) {
            flash('error', 'Logo must be a PNG, JPG, or WEBP image.');
            header('Location: ' . url('settings')); exit;
        }
        if ($_FILES['logo']['size'] > 2 * 1024 * 1024) {
            flash('error', 'Logo must be 2 MB or smaller.');
            header('Location: ' . url('settings')); exit;
        }
        $dir = __DIR__ . '/uploads';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755);
            @file_put_contents($dir . '/.htaccess', "<FilesMatch \"\\.(php|phtml|php[0-9])$\">\nOrder allow,deny\nDeny from all\n</FilesMatch>\n");
        }
        // fixed filename — nothing user-controlled touches the path
        foreach (['png','jpg','webp'] as $e) { @unlink("$dir/logo.$e"); }
        $ext  = $mimeExt[$info['mime']];
        if (!move_uploaded_file($tmp, "$dir/logo.$ext")) {
            flash('error', 'Could not save the logo file (check folder permissions).');
            header('Location: ' . url('settings')); exit;
        }
        try { saveSetting('business_logo', "uploads/logo.$ext"); } catch (PDOException) {}
    }

    $fields = [
        'business_name'    => clean($_POST['business_name'] ?? '') ?: 'OneSystem BMS',
        'business_address' => clean($_POST['business_address'] ?? ''),
        'business_phone'   => clean($_POST['business_phone'] ?? ''),
        'business_email'   => clean($_POST['business_email'] ?? ''),
        'business_tin'     => clean($_POST['business_tin'] ?? ''),
        'business_vrn'     => clean($_POST['business_vrn'] ?? ''),
        'receipt_footer'   => clean($_POST['receipt_footer'] ?? ''),
        'vat_enabled'      => isset($_POST['vat_enabled']) ? '1' : '0',
        'vat_rate'         => (string)min(100, max(0, cleanFloat($_POST['vat_rate'] ?? 18))),
        'vat_mode'         => ($_POST['vat_mode'] ?? '') === 'exclusive' ? 'exclusive' : 'inclusive',
    ];

    try {
        foreach ($fields as $k => $v) { saveSetting($k, $v); }
        logActivity('settings_updated', 'Business settings updated');
        flash('success', 'Settings saved.');
    } catch (PDOException $e) {
        flash('error', 'Could not save settings — has migration_v2.sql been run? (' . $e->getMessage() . ')');
    }
    header('Location: ' . url('settings')); exit;
}

$s = getSettings();

include __DIR__ . '/includes/tailwind.php';
?>

<div class="max-w-2xl mx-auto">
  <form method="POST" enctype="multipart/form-data" class="space-y-5">
    <?= csrf_field() ?>

    <!-- Business identity -->
    <div class="page-card p-6">
      <h3 class="font-bold text-slate-800 mb-1"><i class="fas fa-store text-indigo-400 mr-1"></i> Business Identity</h3>
      <p class="text-xs text-slate-400 mb-5">Shown across the system: sidebar, dashboard, and printed receipts.</p>
      <div class="space-y-4">
        <div>
          <label class="form-label">Business Name <span class="text-red-500">*</span></label>
          <input type="text" name="business_name" required value="<?= e($s['business_name']) ?>" class="form-input">
        </div>
        <div>
          <label class="form-label">Logo</label>
          <div class="flex items-center gap-4">
            <?php if ($logo = businessLogoUrl()): ?>
            <img src="<?= e($logo) ?>" alt="Logo" class="w-14 h-14 rounded-xl object-cover border border-slate-200">
            <?php else: ?>
            <div class="w-14 h-14 rounded-xl bg-slate-100 flex items-center justify-center text-slate-300"><i class="fas fa-image text-xl"></i></div>
            <?php endif; ?>
            <div class="flex-1">
              <input type="file" name="logo" accept="image/png,image/jpeg,image/webp"
                     class="form-input file:mr-3 file:px-3 file:py-1 file:rounded-lg file:border-0 file:bg-indigo-50 file:text-indigo-600 file:text-sm file:font-semibold">
              <p class="text-xs text-slate-400 mt-1">PNG, JPG or WEBP, max 2 MB. Square images look best.</p>
              <?php if ($logo): ?>
              <label class="inline-flex items-center gap-2 text-xs text-red-500 mt-1 cursor-pointer">
                <input type="checkbox" name="remove_logo" value="1" class="rounded border-gray-300"> Remove current logo
              </label>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <div>
          <label class="form-label">Address</label>
          <input type="text" name="business_address" value="<?= e($s['business_address']) ?>" class="form-input" placeholder="Street, City">
        </div>
        <div class="grid sm:grid-cols-2 gap-4">
          <div>
            <label class="form-label">Phone</label>
            <input type="text" name="business_phone" value="<?= e($s['business_phone']) ?>" class="form-input" placeholder="+255 ...">
          </div>
          <div>
            <label class="form-label">Email</label>
            <input type="email" name="business_email" value="<?= e($s['business_email']) ?>" class="form-input">
          </div>
        </div>
        <div class="grid sm:grid-cols-2 gap-4">
          <div>
            <label class="form-label">TIN</label>
            <input type="text" name="business_tin" value="<?= e($s['business_tin']) ?>" class="form-input" placeholder="Tax Identification Number">
          </div>
          <div>
            <label class="form-label">VRN</label>
            <input type="text" name="business_vrn" value="<?= e($s['business_vrn']) ?>" class="form-input" placeholder="VAT Registration Number">
          </div>
        </div>
        <div>
          <label class="form-label">Receipt Footer Message</label>
          <input type="text" name="receipt_footer" value="<?= e($s['receipt_footer']) ?>" class="form-input" placeholder="Thank you for your business!">
        </div>
      </div>
    </div>

    <!-- VAT -->
    <div class="page-card p-6" x-data="{ vatOn: <?= $s['vat_enabled'] === '1' ? 'true' : 'false' ?> }">
      <h3 class="font-bold text-slate-800 mb-1"><i class="fas fa-percent text-indigo-400 mr-1"></i> VAT</h3>
      <p class="text-xs text-slate-400 mb-5">Applied to new sales only; existing invoices are not changed.</p>

      <label class="flex items-center gap-3 cursor-pointer mb-4">
        <input type="checkbox" name="vat_enabled" x-model="vatOn" <?= $s['vat_enabled'] === '1' ? 'checked' : '' ?>
               class="w-5 h-5 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
        <span class="text-sm font-semibold text-slate-700">Enable VAT on sales</span>
      </label>

      <div x-show="vatOn" x-cloak class="grid sm:grid-cols-2 gap-4">
        <div>
          <label class="form-label">VAT Rate (%)</label>
          <input type="number" name="vat_rate" min="0" max="100" step="0.1" value="<?= e($s['vat_rate']) ?>" class="form-input">
          <p class="text-xs text-slate-400 mt-1">Tanzania standard rate: 18%</p>
        </div>
        <div>
          <label class="form-label">Pricing Mode</label>
          <select name="vat_mode" class="form-input">
            <option value="inclusive" <?= $s['vat_mode'] !== 'exclusive' ? 'selected' : '' ?>>Prices include VAT (carve out)</option>
            <option value="exclusive" <?= $s['vat_mode'] === 'exclusive' ? 'selected' : '' ?>>Add VAT on top of prices</option>
          </select>
          <p class="text-xs text-slate-400 mt-1">"Include" keeps totals unchanged and shows the VAT portion; "Add" increases the total.</p>
        </div>
      </div>
    </div>

    <button type="submit" class="btn-primary w-full justify-center py-3"><i class="fas fa-save"></i> Save Settings</button>
  </form>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
