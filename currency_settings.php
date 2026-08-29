<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/currency_functions.php';
requireRole('super_admin');
// Role directories are the canonical URLs — forward direct hits there
if (!defined('ENTRY_OK') && isLoggedIn()) {
    $q = $_SERVER['QUERY_STRING'] ?? '';
    header('Location: ' . url(basename(__FILE__, '.php')) . ($q !== '' ? "?$q" : ''));
    exit;
}

$db        = getDB();
$pageTitle = 'Currency Settings';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $code     = clean($_POST['currency_code'] ?? 'TSh');
    $symbol   = clean($_POST['currency_symbol'] ?? 'TSh');
    $name     = clean($_POST['currency_name'] ?? 'Tanzanian Shilling');
    $decimals = cleanInt($_POST['decimal_places'] ?? 0);
    $thousands= clean($_POST['thousands_separator'] ?? ',');
    $decimal  = clean($_POST['decimal_separator'] ?? '.');
    $position = clean($_POST['symbol_position'] ?? 'before');

    $existing = $db->query('SELECT id FROM currency_settings LIMIT 1')->fetchColumn();
    if ($existing) {
        $db->prepare('UPDATE currency_settings SET currency_code=?,currency_symbol=?,currency_name=?,decimal_places=?,thousands_separator=?,decimal_separator=?,symbol_position=? WHERE id=?')
            ->execute([$code,$symbol,$name,$decimals,$thousands,$decimal,$position,$existing]);
    } else {
        $db->prepare('INSERT INTO currency_settings (currency_code,currency_symbol,currency_name,decimal_places,thousands_separator,decimal_separator,symbol_position) VALUES (?,?,?,?,?,?,?)')
            ->execute([$code,$symbol,$name,$decimals,$thousands,$decimal,$position]);
    }
    logActivity('currency_updated', "Currency settings updated: $symbol ($code)");
    flash('success', 'Currency settings saved.');
    header('Location: ' . url('currency_settings')); exit;
}

$settings = getCurrencySettings();
include __DIR__ . '/includes/tailwind.php';
?>

<div class="max-w-lg mx-auto">
  <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
    <div class="flex items-center gap-3 mb-5">
      <div class="w-10 h-10 bg-yellow-100 rounded-lg flex items-center justify-center">
        <i class="fas fa-coins text-yellow-600"></i>
      </div>
      <div>
        <h2 class="font-semibold text-gray-800">Currency Settings</h2>
        <p class="text-xs text-gray-500">Configure how currency is displayed across the system</p>
      </div>
    </div>

    <form method="POST" class="space-y-4">
      <?= csrf_field() ?>
      <div class="grid sm:grid-cols-2 gap-4">
        <div>
          <label class="form-label">Currency Code</label>
          <input type="text" name="currency_code" class="form-input" value="<?= e($settings['currency_code']) ?>" maxlength="10" placeholder="TSh">
        </div>
        <div>
          <label class="form-label">Currency Symbol</label>
          <input type="text" name="currency_symbol" class="form-input" value="<?= e($settings['currency_symbol']) ?>" maxlength="10" placeholder="TSh">
        </div>
        <div class="sm:col-span-2">
          <label class="form-label">Currency Name</label>
          <input type="text" name="currency_name" class="form-input" value="<?= e($settings['currency_name']) ?>" placeholder="Tanzanian Shilling">
        </div>
        <div>
          <label class="form-label">Decimal Places</label>
          <select name="decimal_places" class="form-input">
            <option value="0" <?= (int)$settings['decimal_places']===0?'selected':'' ?>>0 (e.g. 5,000)</option>
            <option value="2" <?= (int)$settings['decimal_places']===2?'selected':'' ?>>2 (e.g. 5,000.00)</option>
          </select>
        </div>
        <div>
          <label class="form-label">Symbol Position</label>
          <select name="symbol_position" class="form-input">
            <option value="before" <?= $settings['symbol_position']==='before'?'selected':'' ?>>Before (TSh 5,000)</option>
            <option value="after"  <?= $settings['symbol_position']==='after' ?'selected':'' ?>>After (5,000 TSh)</option>
          </select>
        </div>
        <div>
          <label class="form-label">Thousands Separator</label>
          <select name="thousands_separator" class="form-input">
            <option value="," <?= $settings['thousands_separator']===','?'selected':'' ?>>, (comma)</option>
            <option value="." <?= $settings['thousands_separator']==='.'?'selected':'' ?>>. (dot)</option>
            <option value=" " <?= $settings['thousands_separator']===' '?'selected':'' ?>>  (space)</option>
          </select>
        </div>
        <div>
          <label class="form-label">Decimal Separator</label>
          <select name="decimal_separator" class="form-input">
            <option value="." <?= $settings['decimal_separator']==='.'?'selected':'' ?>>. (dot)</option>
            <option value="," <?= $settings['decimal_separator']===','?'selected':'' ?>>, (comma)</option>
          </select>
        </div>
      </div>

      <!-- Preview -->
      <div class="p-4 bg-indigo-50 rounded-lg text-sm">
        <p class="text-indigo-700 font-medium mb-1">Preview</p>
        <p class="text-indigo-900 text-2xl font-bold"><?= formatCurrency(1234567.50) ?></p>
      </div>

      <div class="pt-2">
        <button type="submit" class="btn-primary w-full justify-center py-3">
          <i class="fas fa-save"></i> Save Currency Settings
        </button>
      </div>
    </form>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
