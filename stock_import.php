<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/currency_functions.php';
require_once __DIR__ . '/includes/xlsx_reader.php';
requireRole('super_admin', 'zone_manager', 'branch_manager', 'stock_controller');
// Role directories are the canonical URLs — forward direct hits there
if (!defined('ENTRY_OK') && isLoggedIn()) {
    $q = $_SERVER['QUERY_STRING'] ?? '';
    header('Location: ' . url(basename(__FILE__, '.php')) . ($q !== '' ? "?$q" : ''));
    exit;
}

$db        = getDB();
$pageTitle = 'Import Stock from Excel';
$u         = currentUser();

$branchLocked = $u['branch_id'] && !isSuperAdmin() && !hasRole('zone_manager');
$branches     = $db->query('SELECT id, name FROM branches WHERE is_active=1 ORDER BY name')->fetchAll();

/**
 * Make sure the product columns the import writes to exist.
 * Adds them on the fly (same DDL as migration_v3.sql); returns
 * a list of columns it could not add.
 */
function ensureImportColumns(PDO $db): array
{
    $need = [
        'category'    => "VARCHAR(100) DEFAULT NULL AFTER `description`",
        'unit'        => "VARCHAR(30) DEFAULT NULL AFTER `category`",
        'ws1_unit'    => "VARCHAR(30) DEFAULT NULL AFTER `wholesale_price`",
        'ws2_price'   => "DECIMAL(15,2) DEFAULT NULL AFTER `ws1_unit`",
        'ws2_unit'    => "VARCHAR(30) DEFAULT NULL AFTER `ws2_price`",
        'ws3_price'   => "DECIMAL(15,2) DEFAULT NULL AFTER `ws2_unit`",
        'ws3_unit'    => "VARCHAR(30) DEFAULT NULL AFTER `ws3_price`",
        'expiry_date' => "DATE DEFAULT NULL AFTER `min_stock_alert`",
        'vat_status'  => "ENUM('INCL','EXCL') DEFAULT NULL AFTER `expiry_date`",
    ];
    $stmt = $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS
                        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products'");
    $have = array_map('strtolower', $stmt->fetchAll(PDO::FETCH_COLUMN));
    $failed = [];
    foreach ($need as $col => $ddl) {
        if (in_array(strtolower($col), $have, true)) continue;
        try {
            $db->exec("ALTER TABLE `products` ADD COLUMN `$col` $ddl");
        } catch (PDOException) {
            $failed[] = $col;
        }
    }
    return $failed;
}

// Spreadsheet layout (0-indexed) — fixed column order per the source sheet
const IMP_BARCODE = 0,  IMP_PRODUCT = 1,  IMP_SUPPLIER = 2, IMP_CATEGORY = 3,
      IMP_UNIT    = 4,  IMP_QTY     = 5,  IMP_MIN      = 6, IMP_COST     = 7,
      IMP_RETAIL  = 8,  /* 9 RET.PROFIT computed */
      IMP_WS1     = 10, IMP_WS1U    = 11, /* 12-13 VAT/PROFIT computed */
      IMP_WS2     = 14, IMP_WS2U    = 15, /* 16-17 */
      IMP_WS3     = 18, IMP_WS3U    = 19, /* 20-21 */
      IMP_EXPIRY  = 22, IMP_VATST   = 23;

function impFloat(?string $v): float   { return (float)str_replace([',', ' '], '', $v ?? ''); }
function impMoney(?string $v): ?float  { $v = trim($v ?? ''); return $v === '' ? null : impFloat($v); }

// ── POST: run the import ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $branchId = $branchLocked ? (int)$u['branch_id'] : cleanInt($_POST['branch_id'] ?? 0);
    $validBranch = array_filter($branches, fn($b) => (int)$b['id'] === $branchId);
    if (!$branchId || !$validBranch) {
        flash('error', 'Please choose the branch this stock belongs to.');
        header('Location: ' . url('stock_import')); exit;
    }

    $file = $_FILES['xlsx'] ?? null;
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        flash('error', 'Upload failed — choose an .xlsx file (check it is under the server upload size limit).');
        header('Location: ' . url('stock_import')); exit;
    }
    if (!preg_match('/\.xlsx$/i', $file['name']) || $file['size'] > 15 * 1024 * 1024) {
        flash('error', 'Only .xlsx files up to 15 MB are accepted.');
        header('Location: ' . url('stock_import')); exit;
    }

    $missingCols = ensureImportColumns($db);
    if ($missingCols) {
        flash('error', 'Database is missing columns (' . implode(', ', $missingCols) . ') and they could not be added automatically — run migration_v3.sql in phpMyAdmin first.');
        header('Location: ' . url('stock_import')); exit;
    }

    try {
        $rows = readXlsxRows($file['tmp_name']);
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
        header('Location: ' . url('stock_import')); exit;
    }

    $batchRef = 'IMPORT:' . date('Ymd-His');
    $report   = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'suppliers_created' => 0,
                 'errors' => [], 'branch' => current($validBranch)['name'], 'file' => $file['name'], 'ref' => $batchRef];

    // Supplier cache: lowercase name → id
    $supplierIds = [];
    foreach ($db->query('SELECT id, name FROM suppliers')->fetchAll() as $s) {
        $supplierIds[mb_strtolower(trim($s['name']))] = (int)$s['id'];
    }

    foreach ($rows as $i => $cells) {
        $rowNo   = $i + 1;
        $barcode = trim($cells[IMP_BARCODE] ?? '');
        $name    = trim($cells[IMP_PRODUCT] ?? '');

        // Header row / blank row
        if ($i === 0 && (strcasecmp($name, 'PRODUCT') === 0 || strcasecmp($barcode, 'BARCODE') === 0)) continue;
        if ($barcode === '' && $name === '' && trim(implode('', $cells)) === '') continue;

        if ($name === '') {
            $report['skipped']++;
            $report['errors'][] = ['row' => $rowNo, 'name' => $barcode ?: '(blank)', 'error' => 'Product name is empty.'];
            continue;
        }

        $db->beginTransaction();
        try {
            // ── Supplier (auto-create) ──
            $supplierId   = null;
            $supplierName = trim($cells[IMP_SUPPLIER] ?? '');
            if ($supplierName !== '') {
                $key = mb_strtolower($supplierName);
                if (!isset($supplierIds[$key])) {
                    $db->prepare("INSERT INTO suppliers (name, status, notes) VALUES (?, 'active', 'Auto-created by stock import')")
                        ->execute([$supplierName]);
                    $supplierIds[$key] = (int)$db->lastInsertId();
                    $report['suppliers_created']++;
                }
                $supplierId = $supplierIds[$key];
            }

            // ── Field extraction ──
            $qty       = (int)round(impFloat($cells[IMP_QTY] ?? '0'));
            $minAlert  = ($cells[IMP_MIN] ?? '') !== '' ? (int)round(impFloat($cells[IMP_MIN])) : 5;
            $cost      = impFloat($cells[IMP_COST] ?? '0');
            $retail    = impFloat($cells[IMP_RETAIL] ?? '0');
            $ws1       = impMoney($cells[IMP_WS1] ?? '') ?? 0.0;   // maps to existing wholesale_price
            $ws1u      = trim($cells[IMP_WS1U] ?? '') ?: null;
            $ws2       = impMoney($cells[IMP_WS2] ?? '');
            $ws2u      = trim($cells[IMP_WS2U] ?? '') ?: null;
            $ws3       = impMoney($cells[IMP_WS3] ?? '');
            $ws3u      = trim($cells[IMP_WS3U] ?? '') ?: null;
            $category  = trim($cells[IMP_CATEGORY] ?? '') ?: null;
            $unit      = trim($cells[IMP_UNIT] ?? '') ?: null;
            $expiry    = xlsxToDate($cells[IMP_EXPIRY] ?? '');
            $vatRaw    = strtoupper(trim($cells[IMP_VATST] ?? ''));
            $vatStatus = in_array($vatRaw, ['INCL','EXCL'], true) ? $vatRaw : null;

            // ── Product lookup: barcode first, exact name fallback ──
            $product = null;
            if ($barcode !== '') {
                $q = $db->prepare("SELECT id FROM products WHERE barcode = ? LIMIT 1");
                $q->execute([$barcode]);
                $product = $q->fetch();
            }
            if (!$product) {
                $q = $db->prepare("SELECT id FROM products WHERE LOWER(name) = LOWER(?) LIMIT 1");
                $q->execute([$name]);
                $product = $q->fetch();
            }

            if ($product) {
                $productId = (int)$product['id'];
                $db->prepare(
                    'UPDATE products SET name=?, barcode=?, category=?, unit=?, cost_price=?,
                            wholesale_price=?, ws1_unit=?, ws2_price=?, ws2_unit=?, ws3_price=?, ws3_unit=?,
                            retail_price=?, min_stock_alert=?, expiry_date=?, vat_status=?, is_active=1
                     WHERE id=?'
                )->execute([$name,$barcode ?: null,$category,$unit,$cost,
                            $ws1,$ws1u,$ws2,$ws2u,$ws3,$ws3u,
                            $retail,$minAlert,$expiry,$vatStatus,$productId]);
                $report['updated']++;
            } else {
                $db->prepare(
                    'INSERT INTO products (sku,name,barcode,category,unit,cost_price,
                            wholesale_price,ws1_unit,ws2_price,ws2_unit,ws3_price,ws3_unit,
                            retail_price,min_stock_alert,expiry_date,vat_status,created_by)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
                )->execute([generateSku(),$name,$barcode ?: null,$category,$unit,$cost,
                            $ws1,$ws1u,$ws2,$ws2u,$ws3,$ws3u,
                            $retail,$minAlert,$expiry,$vatStatus,$u['id']]);
                $productId = (int)$db->lastInsertId();
                $report['created']++;
            }

            // ── Supplier link (upsert, keep cost fresh) ──
            if ($supplierId) {
                $db->prepare(
                    'INSERT INTO product_suppliers (product_id, supplier_id, cost_price, is_preferred)
                     VALUES (?,?,?,1)
                     ON DUPLICATE KEY UPDATE cost_price = VALUES(cost_price)'
                )->execute([$productId, $supplierId, $cost]);
            }

            // ── Stock: SET quantity for the chosen branch + audit trail ──
            $bq = $db->prepare('SELECT quantity FROM stock WHERE product_id=? AND branch_id=?');
            $bq->execute([$productId, $branchId]);
            $before = $bq->fetchColumn();
            $before = $before === false ? 0 : (int)$before;

            $db->prepare('INSERT INTO stock (product_id,branch_id,quantity) VALUES (?,?,?)
                          ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)')
                ->execute([$productId, $branchId, $qty]);
            if ($before !== $qty) {
                $db->prepare(
                    "INSERT INTO stock_adjustments (product_id,branch_id,adjustment_type,quantity_before,quantity_changed,quantity_after,reason,reference,user_id)
                     VALUES (?,?,'set',?,?,?,'Bulk stock import',?,?)"
                )->execute([$productId,$branchId,$before,$qty-$before,$qty,$batchRef,$u['id']]);
            }

            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            $report['skipped']++;
            $report['errors'][] = ['row' => $rowNo, 'name' => $name, 'error' => $e->getMessage()];
        }
    }

    logActivity('stock_imported',
        "Excel import ({$report['file']}) to {$report['branch']}: {$report['created']} created, "
        . "{$report['updated']} updated, {$report['skipped']} skipped");
    $_SESSION['_import_report'] = $report;
    header('Location: ' . url('stock_import')); exit;
}

$report = $_SESSION['_import_report'] ?? null;
unset($_SESSION['_import_report']);

include __DIR__ . '/includes/tailwind.php';
?>

<div class="max-w-3xl mx-auto">
  <div class="flex items-center gap-3 mb-6">
    <a href="<?= url('stock') ?>" class="text-gray-400 hover:text-gray-600"><i class="fas fa-arrow-left"></i></a>
    <h2 class="text-lg font-semibold text-gray-800">Import Stock from Excel</h2>
  </div>

  <?php if ($report): ?>
  <!-- ── Import Report ── -->
  <div class="page-card p-6 mb-6">
    <h3 class="font-bold text-slate-800 mb-1"><i class="fas fa-clipboard-check text-emerald-500 mr-1"></i> Import Report</h3>
    <p class="text-xs text-slate-400 mb-4"><?= e($report['file']) ?> &rarr; <?= e($report['branch']) ?> &bull; batch <?= e($report['ref']) ?></p>
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-4">
      <div class="rounded-xl p-4 bg-emerald-50 border border-emerald-100">
        <p class="text-2xl font-bold text-emerald-700"><?= $report['created'] ?></p>
        <p class="text-xs font-semibold text-emerald-600 uppercase">Created</p>
      </div>
      <div class="rounded-xl p-4 bg-blue-50 border border-blue-100">
        <p class="text-2xl font-bold text-blue-700"><?= $report['updated'] ?></p>
        <p class="text-xs font-semibold text-blue-600 uppercase">Updated</p>
      </div>
      <div class="rounded-xl p-4 bg-amber-50 border border-amber-100">
        <p class="text-2xl font-bold text-amber-700"><?= $report['skipped'] ?></p>
        <p class="text-xs font-semibold text-amber-600 uppercase">Skipped</p>
      </div>
      <div class="rounded-xl p-4 bg-indigo-50 border border-indigo-100">
        <p class="text-2xl font-bold text-indigo-700"><?= $report['suppliers_created'] ?></p>
        <p class="text-xs font-semibold text-indigo-600 uppercase">New Suppliers</p>
      </div>
    </div>
    <?php if ($report['errors']): ?>
    <div class="border border-amber-200 rounded-xl overflow-hidden">
      <div class="bg-amber-50 px-4 py-2 text-xs font-bold text-amber-700 uppercase">Skipped Rows</div>
      <table class="w-full text-sm">
        <thead><tr class="text-xs text-gray-500 border-b border-gray-100">
          <th class="py-2 px-4 text-left w-16">Row</th><th class="py-2 px-4 text-left">Product</th><th class="py-2 px-4 text-left">Problem</th>
        </tr></thead>
        <tbody class="divide-y divide-gray-50">
        <?php foreach ($report['errors'] as $err): ?>
        <tr>
          <td class="py-2 px-4 font-mono text-gray-500"><?= (int)$err['row'] ?></td>
          <td class="py-2 px-4"><?= e($err['name']) ?></td>
          <td class="py-2 px-4 text-red-600 text-xs"><?= e($err['error']) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?>
    <p class="text-sm text-emerald-600"><i class="fas fa-check-circle"></i> All rows imported cleanly.</p>
    <?php endif; ?>
    <div class="flex gap-2 mt-4">
      <a href="<?= url('stock') ?>" class="btn-primary"><i class="fas fa-boxes"></i> View Stock</a>
      <a href="<?= url('stock_import') ?>" class="btn-secondary"><i class="fas fa-redo"></i> Import Another File</a>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── Upload Form ── -->
  <div class="page-card p-6">
    <form method="POST" enctype="multipart/form-data" class="space-y-5">
      <?= csrf_field() ?>

      <div>
        <label class="form-label">Branch receiving this stock <span class="text-red-500">*</span></label>
        <?php if ($branchLocked):
            $mine = current(array_filter($branches, fn($b) => (int)$b['id'] === (int)$u['branch_id'])); ?>
        <input type="text" class="form-input bg-slate-50" value="<?= e($mine['name'] ?? 'My branch') ?>" disabled>
        <p class="text-xs text-slate-400 mt-1">Your account is assigned to this branch.</p>
        <?php else: ?>
        <select name="branch_id" required class="form-input">
          <option value="">-- Select Branch --</option>
          <?php foreach ($branches as $b): ?>
          <option value="<?= $b['id'] ?>"><?= e($b['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <?php endif; ?>
      </div>

      <div>
        <label class="form-label">Excel file (.xlsx) <span class="text-red-500">*</span></label>
        <input type="file" name="xlsx" accept=".xlsx" required
               class="form-input file:mr-3 file:px-3 file:py-1 file:rounded-lg file:border-0 file:bg-indigo-50 file:text-indigo-600 file:text-sm file:font-semibold">
        <p class="text-xs text-slate-400 mt-1">Max 15 MB. The first sheet in the workbook is imported.</p>
      </div>

      <div class="bg-slate-50 border border-slate-100 rounded-xl p-4 text-xs text-slate-500 leading-relaxed">
        <p class="font-bold text-slate-600 mb-1"><i class="fas fa-info-circle text-indigo-400"></i> Expected column order</p>
        BARCODE &bull; PRODUCT &bull; SUPPLIER &bull; CATEGORY &bull; UNIT &bull; TOTAL BALANCE &bull; MIN BALANCE &bull;
        BUYING PRICE &bull; RET. PRICE &bull; RET. PROFIT &bull; WS1 / UNIT / VAT / PROFIT &bull;
        WS2 / UNIT / VAT / PROFIT &bull; WS3 / UNIT / VAT / PROFIT &bull; EXPIRY &bull; VAT STATUS
        <p class="mt-2">Products are matched by <strong>barcode</strong> (falling back to exact name), so re-uploading the
        same file updates rather than duplicates. Profit and per-tier VAT columns are computed in the sheet and ignored.
        Blank or 0000-00-00 expiry means no expiry. Suppliers are created automatically if new.
        <strong>TOTAL BALANCE replaces</strong> the branch's current stock quantity for each product (logged in the adjustments trail).</p>
      </div>

      <button type="submit" class="btn-primary w-full justify-center py-3"
              onclick="this.innerHTML='<i class=\'fas fa-spinner fa-spin\'></i> Importing...'">
        <i class="fas fa-file-import"></i> Upload &amp; Import
      </button>
    </form>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
