<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/currency_functions.php';
requireRole('super_admin','zone_manager','branch_manager','stock_controller');
// Role directories are the canonical URLs — forward direct hits there
if (!defined('ENTRY_OK') && isLoggedIn()) {
    $q = $_SERVER['QUERY_STRING'] ?? '';
    header('Location: ' . url(basename(__FILE__, '.php')) . ($q !== '' ? "?$q" : ''));
    exit;
}

$db        = getDB();
$pageTitle = 'Stock Management';
$action    = clean($_GET['action'] ?? 'list');
$tab       = clean($_GET['tab'] ?? 'inventory');
$editId    = cleanInt($_GET['id'] ?? 0);
$filterBranch = cleanInt($_GET['branch_id'] ?? 0);
$filterLow    = ($_GET['filter'] ?? '') === 'low';

$branches = $db->query('SELECT id, name FROM branches WHERE is_active = 1 ORDER BY name')->fetchAll();
$zones    = $db->query('SELECT id, name FROM zones ORDER BY name')->fetchAll();
$u        = currentUser();

// Scope branch for non-admins
if ($u['branch_id'] && !isSuperAdmin() && !hasRole('zone_manager')) {
    $filterBranch = (int)$u['branch_id'];
}

// ── POST Handlers ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $act = clean($_POST['_act'] ?? '');

    // Create product
    if ($act === 'create_product') {
        $name       = clean($_POST['name'] ?? '');
        $sku        = clean($_POST['sku'] ?? '') ?: generateSku();
        $barcode    = clean($_POST['barcode'] ?? '');
        $desc       = clean($_POST['description'] ?? '');
        $costPrice  = cleanFloat($_POST['cost_price'] ?? 0);
        $wholeSale  = cleanFloat($_POST['wholesale_price'] ?? 0);
        $retail     = cleanFloat($_POST['retail_price'] ?? 0);
        $minAlert   = cleanInt($_POST['min_stock_alert'] ?? 5);
        $branchId   = cleanInt($_POST['branch_id'] ?? 0) ?: null;
        $initQty    = cleanInt($_POST['initial_qty'] ?? 0);

        if (!$name) { flash('error', 'Product name is required.'); header('Location: ' . url('stock?action=new')); exit; }

        try {
            $db->prepare(
                'INSERT INTO products (sku,name,barcode,description,cost_price,wholesale_price,retail_price,min_stock_alert,created_by)
                 VALUES (?,?,?,?,?,?,?,?,?)'
            )->execute([$sku,$name,$barcode,$desc,$costPrice,$wholeSale,$retail,$minAlert,$u['id']]);
            $productId = (int)$db->lastInsertId();

            if ($branchId && $initQty > 0) {
                $db->prepare('INSERT INTO stock (product_id,branch_id,quantity) VALUES (?,?,?) ON DUPLICATE KEY UPDATE quantity=quantity+?')
                    ->execute([$productId,$branchId,$initQty,$initQty]);
                $db->prepare('INSERT INTO stock_adjustments (product_id,branch_id,adjustment_type,quantity_before,quantity_changed,quantity_after,reason,user_id) VALUES (?,?,\'add\',0,?,?,\'Initial stock\',?)')
                    ->execute([$productId,$branchId,$initQty,$initQty,$u['id']]);
            }
            logActivity('product_created', "Created product: $name (SKU: $sku)");
            flash('success', "Product '$name' created successfully.");
        } catch (PDOException $e) {
            flash('error', str_contains($e->getMessage(),'Duplicate') ? 'SKU already exists.' : 'Error creating product: ' . $e->getMessage());
        }
        header('Location: ' . url('stock')); exit;
    }

    // Edit product
    if ($act === 'update_product') {
        $pid       = cleanInt($_POST['product_id'] ?? 0);
        $name      = clean($_POST['name'] ?? '');
        $sku       = clean($_POST['sku'] ?? '');
        $barcode   = clean($_POST['barcode'] ?? '');
        $desc      = clean($_POST['description'] ?? '');
        $costPrice = cleanFloat($_POST['cost_price'] ?? 0);
        $wholeSale = cleanFloat($_POST['wholesale_price'] ?? 0);
        $retail    = cleanFloat($_POST['retail_price'] ?? 0);
        $minAlert  = cleanInt($_POST['min_stock_alert'] ?? 5);
        $isActive  = isset($_POST['is_active']) ? 1 : 0;

        $db->prepare('UPDATE products SET name=?,sku=?,barcode=?,description=?,cost_price=?,wholesale_price=?,retail_price=?,min_stock_alert=?,is_active=? WHERE id=?')
            ->execute([$name,$sku,$barcode,$desc,$costPrice,$wholeSale,$retail,$minAlert,$isActive,$pid]);
        logActivity('product_updated', "Updated product: $name");
        flash('success', "Product '$name' updated.");
        header('Location: ' . url('stock')); exit;
    }

    // Add stock to branch
    if ($act === 'add_stock') {
        $pid      = cleanInt($_POST['product_id'] ?? 0);
        $bid      = cleanInt($_POST['branch_id'] ?? 0);
        $qty      = cleanInt($_POST['quantity'] ?? 0);
        $reason   = clean($_POST['reason'] ?? 'Manual stock addition');

        if ($pid && $bid && $qty > 0) {
            $existing = (function() use ($db,$pid,$bid) { $s=$db->prepare('SELECT quantity FROM stock WHERE product_id=? AND branch_id=?'); $s->execute([$pid,$bid]); return $s->fetch(); })();
            $before = (int)($existing['quantity'] ?? 0);
            $after  = $before + $qty;
            $db->prepare('INSERT INTO stock (product_id,branch_id,quantity) VALUES (?,?,?) ON DUPLICATE KEY UPDATE quantity=quantity+?')
                ->execute([$pid,$bid,$qty,$qty]);
            $db->prepare('INSERT INTO stock_adjustments (product_id,branch_id,adjustment_type,quantity_before,quantity_changed,quantity_after,reason,user_id) VALUES (?,?,\'add\',?,?,?,?,?)')
                ->execute([$pid,$bid,$before,$qty,$after,$reason,$u['id']]);
            logActivity('stock_added', "Added $qty units to product ID $pid at branch $bid");
            flash('success', "Stock added successfully.");
        } else {
            flash('error', 'Please fill in all fields and enter a positive quantity.');
        }
        header('Location: ' . url('stock')); exit;
    }

    // Adjust stock
    if ($act === 'adjust_stock') {
        $pid    = cleanInt($_POST['product_id'] ?? 0);
        $bid    = cleanInt($_POST['branch_id'] ?? 0);
        $newQty = cleanInt($_POST['new_quantity'] ?? 0);
        $reason = clean($_POST['reason'] ?? 'Manual adjustment');

        $existing = (function() use ($db,$pid,$bid) { $s=$db->prepare('SELECT quantity FROM stock WHERE product_id=? AND branch_id=?'); $s->execute([$pid,$bid]); return $s->fetch(); })();
        $before = (int)($existing['quantity'] ?? 0);
        $changed = $newQty - $before;
        $db->prepare('INSERT INTO stock (product_id,branch_id,quantity) VALUES (?,?,?) ON DUPLICATE KEY UPDATE quantity=?')
            ->execute([$pid,$bid,$newQty,$newQty]);
        $db->prepare('INSERT INTO stock_adjustments (product_id,branch_id,adjustment_type,quantity_before,quantity_changed,quantity_after,reason,user_id) VALUES (?,?,\'set\',?,?,?,?,?)')
            ->execute([$pid,$bid,$before,$changed,$newQty,$reason,$u['id']]);
        logActivity('stock_adjusted', "Adjusted product ID $pid at branch $bid to $newQty");
        flash('success', 'Stock adjusted successfully.');
        header('Location: ' . url('stock')); exit;
    }

    // Copy to branch
    if ($act === 'copy_branch') {
        $pid    = cleanInt($_POST['product_id'] ?? 0);
        $fromId = cleanInt($_POST['from_branch_id'] ?? 0);
        $toId   = cleanInt($_POST['to_branch_id'] ?? 0);
        $qty    = cleanInt($_POST['quantity'] ?? 0);

        if ($fromId === $toId) { flash('error','Source and destination must be different.'); header('Location: ' . url('stock')); exit; }

        $sourceStmt = $db->prepare('SELECT quantity FROM stock WHERE product_id=? AND branch_id=?');
        $sourceStmt->execute([$pid,$fromId]);
        $sourceQty = (int)($sourceStmt->fetchColumn() ?: 0);

        if ($qty > $sourceQty) { flash('error',"Only $sourceQty units available in source branch."); header('Location: ' . url('stock')); exit; }

        $db->prepare('UPDATE stock SET quantity = quantity - ? WHERE product_id=? AND branch_id=?')->execute([$qty,$pid,$fromId]);
        $db->prepare('INSERT INTO stock (product_id,branch_id,quantity) VALUES (?,?,?) ON DUPLICATE KEY UPDATE quantity=quantity+?')->execute([$pid,$toId,$qty,$qty]);
        logActivity('stock_transferred', "Transferred $qty units of product $pid from branch $fromId to $toId");
        flash('success', "$qty units transferred successfully.");
        header('Location: ' . url('stock')); exit;
    }

    // Delete product
    if ($act === 'delete_product') {
        $pid = cleanInt($_POST['product_id'] ?? 0);
        $stockCount = (function() use ($db,$pid) { $s=$db->prepare('SELECT COALESCE(SUM(quantity),0) FROM stock WHERE product_id=?'); $s->execute([$pid]); return (int)$s->fetchColumn(); })();
        if ($stockCount > 0) {
            flash('error', "Cannot delete: product has $stockCount units in stock. Adjust to 0 first.");
        } else {
            $db->prepare('DELETE FROM products WHERE id = ?')->execute([$pid]);
            logActivity('product_deleted', "Deleted product ID: $pid");
            flash('success', 'Product deleted.');
        }
        header('Location: ' . url('stock')); exit;
    }

    // Bulk delete products
    if ($act === 'bulk_delete') {
        $ids = array_values(array_unique(array_filter(array_map('intval', $_POST['ids'] ?? []))));
        $deleted = 0; $blocked = 0;
        foreach ($ids as $pid) {
            $s = $db->prepare('SELECT COALESCE(SUM(quantity),0) FROM stock WHERE product_id=?');
            $s->execute([$pid]);
            if ((int)$s->fetchColumn() > 0) { $blocked++; continue; }
            $db->prepare('DELETE FROM products WHERE id = ?')->execute([$pid]);
            $deleted++;
        }
        if ($deleted) logActivity('product_deleted', "Bulk deleted $deleted product(s)");
        if ($deleted && !$blocked)      flash('success', "$deleted product(s) deleted.");
        elseif ($deleted && $blocked)   flash('error', "$deleted deleted, $blocked skipped (still have stock).");
        else                            flash('error', $blocked ? "Cannot delete: $blocked product(s) still have stock." : 'No products selected.');
        header('Location: ' . url('stock')); exit;
    }
}

// ── Edit product ──────────────────────────────────────────────────────────────
$editProduct = null;
if ($action === 'edit' && $editId) {
    $s = $db->prepare('SELECT * FROM products WHERE id = ?'); $s->execute([$editId]); $editProduct = $s->fetch();
    if (!$editProduct) { header('Location: ' . url('stock')); exit; }
}

// ── Stock List ────────────────────────────────────────────────────────────────
$search = clean($_GET['q'] ?? '');
$stockWhere = ['p.is_active = 1'];
$stockParams = [];

if ($search) { $stockWhere[] = '(p.name LIKE ? OR p.sku LIKE ? OR p.barcode LIKE ?)'; $stockParams = array_merge($stockParams, ["%$search%","%$search%","%$search%"]); }
if ($filterBranch) { $stockWhere[] = 's.branch_id = ?'; $stockParams[] = $filterBranch; }
if ($filterLow) { $stockWhere[] = 's.quantity <= p.min_stock_alert'; }

$stockWhereStr = implode(' AND ', $stockWhere);

$stockList = $db->prepare(
    "SELECT p.id, p.sku, p.name, p.barcode, p.cost_price, p.wholesale_price, p.retail_price,
            p.min_stock_alert, p.is_active,
            s.quantity, s.branch_id,
            b.name as branch_name,
            COALESCE(s.retail_price_override, p.retail_price) as effective_retail,
            COALESCE(s.cost_price_override, p.cost_price) as effective_cost
     FROM products p
     LEFT JOIN stock s ON s.product_id = p.id " . ($filterBranch ? "AND s.branch_id = $filterBranch" : '') . "
     LEFT JOIN branches b ON b.id = s.branch_id
     WHERE $stockWhereStr
     ORDER BY p.name, b.name"
);
$stockList->execute($stockParams);
$stockItems = $stockList->fetchAll();

// Products for modals
$allProducts = $db->query('SELECT id, sku, name FROM products WHERE is_active=1 ORDER BY name')->fetchAll();

include __DIR__ . '/includes/tailwind.php';
?>

<?php if ($action === 'new'): ?>
<!-- ── Add Product Form ── -->
<div class="max-w-2xl mx-auto">
  <div class="flex items-center gap-3 mb-6">
    <a href="<?= url('stock') ?>" class="text-gray-400 hover:text-gray-600"><i class="fas fa-arrow-left"></i></a>
    <h2 class="text-lg font-semibold text-gray-800">Add New Product</h2>
  </div>
  <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="_act" value="create_product">
      <div class="grid sm:grid-cols-2 gap-4">
        <div class="sm:col-span-2">
          <label class="form-label">Product Name <span class="text-red-500">*</span></label>
          <input type="text" name="name" required class="form-input" placeholder="e.g. Coca-Cola 500ml">
        </div>
        <div>
          <label class="form-label">SKU <span class="text-gray-400 text-xs">(auto-generated if blank)</span></label>
          <input type="text" name="sku" class="form-input" placeholder="PRD-00001">
        </div>
        <div>
          <label class="form-label">Barcode</label>
          <input type="text" name="barcode" class="form-input" placeholder="Optional">
        </div>
        <div>
          <label class="form-label">Cost Price (TSh)</label>
          <input type="number" name="cost_price" class="form-input" value="0" min="0" step="0.01">
        </div>
        <div>
          <label class="form-label">Wholesale Price (TSh)</label>
          <input type="number" name="wholesale_price" class="form-input" value="0" min="0" step="0.01">
        </div>
        <div>
          <label class="form-label">Retail Price (TSh)</label>
          <input type="number" name="retail_price" class="form-input" value="0" min="0" step="0.01">
        </div>
        <div>
          <label class="form-label">Min Stock Alert</label>
          <input type="number" name="min_stock_alert" class="form-input" value="5" min="0">
        </div>
        <div class="sm:col-span-2">
          <label class="form-label">Description</label>
          <textarea name="description" rows="2" class="form-input" placeholder="Optional description"></textarea>
        </div>
        <div class="sm:col-span-2 border-t pt-4 mt-1">
          <h3 class="text-sm font-semibold text-gray-700 mb-3">Initial Stock (Optional)</h3>
          <div class="grid sm:grid-cols-2 gap-4">
            <div>
              <label class="form-label">Branch</label>
              <select name="branch_id" class="form-input">
                <option value="">-- Select Branch --</option>
                <?php foreach ($branches as $b): ?>
                <option value="<?= $b['id'] ?>"><?= e($b['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label class="form-label">Initial Quantity</label>
              <input type="number" name="initial_qty" class="form-input" value="0" min="0">
            </div>
          </div>
        </div>
      </div>
      <div class="flex gap-3 mt-6 pt-4 border-t border-gray-100">
        <button type="submit" class="btn-primary"><i class="fas fa-save"></i> Create Product</button>
        <a href="<?= url('stock') ?>" class="btn-secondary"><i class="fas fa-times"></i> Cancel</a>
      </div>
    </form>
  </div>
</div>

<?php elseif ($action === 'edit' && $editProduct): ?>
<!-- ── Edit Product Form ── -->
<div class="max-w-2xl mx-auto">
  <div class="flex items-center gap-3 mb-6">
    <a href="<?= url('stock') ?>" class="text-gray-400 hover:text-gray-600"><i class="fas fa-arrow-left"></i></a>
    <h2 class="text-lg font-semibold text-gray-800">Edit Product</h2>
  </div>
  <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="_act" value="update_product">
      <input type="hidden" name="product_id" value="<?= $editProduct['id'] ?>">
      <div class="grid sm:grid-cols-2 gap-4">
        <div class="sm:col-span-2">
          <label class="form-label">Product Name <span class="text-red-500">*</span></label>
          <input type="text" name="name" required class="form-input" value="<?= e($editProduct['name']) ?>">
        </div>
        <div>
          <label class="form-label">SKU</label>
          <input type="text" name="sku" required class="form-input" value="<?= e($editProduct['sku']) ?>">
        </div>
        <div>
          <label class="form-label">Barcode</label>
          <input type="text" name="barcode" class="form-input" value="<?= e($editProduct['barcode']) ?>">
        </div>
        <div>
          <label class="form-label">Cost Price (TSh)</label>
          <input type="number" name="cost_price" class="form-input" value="<?= $editProduct['cost_price'] ?>" min="0" step="0.01">
        </div>
        <div>
          <label class="form-label">Wholesale Price (TSh)</label>
          <input type="number" name="wholesale_price" class="form-input" value="<?= $editProduct['wholesale_price'] ?>" min="0" step="0.01">
        </div>
        <div>
          <label class="form-label">Retail Price (TSh)</label>
          <input type="number" name="retail_price" class="form-input" value="<?= $editProduct['retail_price'] ?>" min="0" step="0.01">
        </div>
        <div>
          <label class="form-label">Min Stock Alert</label>
          <input type="number" name="min_stock_alert" class="form-input" value="<?= $editProduct['min_stock_alert'] ?>" min="0">
        </div>
        <div class="sm:col-span-2">
          <label class="form-label">Description</label>
          <textarea name="description" rows="2" class="form-input"><?= e($editProduct['description']) ?></textarea>
        </div>
        <div class="sm:col-span-2 flex items-center gap-2">
          <input type="checkbox" name="is_active" id="is_active" value="1" class="w-4 h-4" <?= $editProduct['is_active'] ? 'checked' : '' ?>>
          <label for="is_active" class="text-sm text-gray-700">Product is active</label>
        </div>
      </div>
      <div class="flex gap-3 mt-6 pt-4 border-t border-gray-100">
        <button type="submit" class="btn-primary"><i class="fas fa-save"></i> Save Changes</button>
        <a href="<?= url('stock') ?>" class="btn-secondary"><i class="fas fa-times"></i> Cancel</a>
      </div>
    </form>
  </div>
</div>

<?php else: ?>
<!-- ── Stock List ── -->
<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-5">
  <form method="GET" class="flex flex-wrap gap-2">
    <input type="text" name="q" value="<?= e($search) ?>" placeholder="Search products..." class="form-input w-48">
    <?php if (isSuperAdmin() || hasRole('zone_manager')): ?>
    <select name="branch_id" class="form-input w-44 text-sm">
      <option value="">All Branches</option>
      <?php foreach ($branches as $b): ?>
      <option value="<?= $b['id'] ?>" <?= $filterBranch === (int)$b['id'] ? 'selected' : '' ?>><?= e($b['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <?php endif; ?>
    <label class="flex items-center gap-1.5 text-sm text-gray-600 bg-white border border-gray-300 rounded-lg px-3 cursor-pointer hover:bg-gray-50">
      <input type="checkbox" name="filter" value="low" <?= $filterLow ? 'checked' : '' ?> onchange="this.form.submit()" class="w-3.5 h-3.5">
      Low stock only
    </label>
    <button type="submit" class="btn-secondary"><i class="fas fa-search"></i></button>
    <?php if ($search || $filterBranch || $filterLow): ?><a href="<?= url('stock') ?>" class="btn-secondary"><i class="fas fa-times"></i></a><?php endif; ?>
  </form>
  <div class="flex gap-2">
    <button onclick="document.getElementById('addStockModal').classList.remove('hidden')" class="btn-secondary">
      <i class="fas fa-plus-square"></i> Add Stock
    </button>
    <a href="<?= url('stock_import') ?>" class="btn-secondary"><i class="fas fa-file-import"></i> Import Excel</a>
    <a href="<?= url('stock') ?>?action=new" class="btn-primary"><i class="fas fa-plus"></i> New Product</a>
  </div>
</div>

<!-- Stats bar -->
<div class="grid grid-cols-3 gap-3 mb-5">
  <?php
  $totalProducts = count(array_unique(array_column($stockItems, 'id')));
  $lowItems  = count(array_filter($stockItems, fn($i) => $i['quantity'] !== null && $i['quantity'] <= $i['min_stock_alert']));
  $totalValue = array_sum(array_map(fn($i) => ($i['quantity'] ?? 0) * $i['effective_cost'], $stockItems));
  ?>
  <div class="bg-white rounded-lg border border-gray-100 p-3 text-center shadow-sm">
    <p class="text-xl font-bold text-gray-800"><?= $totalProducts ?></p>
    <p class="text-xs text-gray-500">Products</p>
  </div>
  <div class="bg-white rounded-lg border border-gray-100 p-3 text-center shadow-sm">
    <p class="text-xl font-bold <?= $lowItems > 0 ? 'text-red-600' : 'text-gray-800' ?>"><?= $lowItems ?></p>
    <p class="text-xs text-gray-500">Low Stock</p>
  </div>
  <div class="bg-white rounded-lg border border-gray-100 p-3 text-center shadow-sm">
    <p class="text-xl font-bold text-gray-800"><?= formatCurrency($totalValue) ?></p>
    <p class="text-xs text-gray-500">Stock Value (Cost)</p>
  </div>
</div>

<div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
  <form method="POST" id="bulkDeleteForm" class="hidden">
    <?= csrf_field() ?>
    <input type="hidden" name="_act" value="bulk_delete">
  </form>
  <div data-bulk-bar class="hidden items-center gap-3 mx-4 mt-4 px-4 py-2.5 bg-red-50 border border-red-100 rounded-lg text-sm text-red-700">
    <span><span data-selected-count>0</span> selected</span>
    <button type="button" onclick="confirmBulkDelete('bulkDeleteForm','product')" class="btn-danger ml-auto"><i class="fas fa-trash-alt"></i> Delete Selected</button>
  </div>
  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead class="bg-gray-50 border-b border-gray-100">
        <tr>
          <th class="table-th w-8"><input type="checkbox" data-select-all></th>
          <th class="table-th">SKU</th>
          <th class="table-th">Product</th>
          <th class="table-th">Branch</th>
          <th class="table-th text-right">Qty</th>
          <th class="table-th text-right">Cost</th>
          <th class="table-th text-right">Retail</th>
          <th class="table-th text-right">Actions</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-gray-50">
        <?php if (empty($stockItems)): ?>
        <tr><td colspan="8" class="py-12 text-center text-gray-400">
          <i class="fas fa-boxes text-4xl mb-3 block"></i>
          No products found. <a href="<?= url('stock') ?>?action=new" class="text-indigo-600 underline">Add one</a>.
        </td></tr>
        <?php endif; ?>
        <?php foreach ($stockItems as $item): ?>
        <?php $isLow = $item['quantity'] !== null && $item['quantity'] <= $item['min_stock_alert']; ?>
        <tr class="hover:bg-gray-50 transition-colors <?= $isLow ? 'bg-red-50' : '' ?>">
          <td class="table-td"><input type="checkbox" name="ids[]" value="<?= $item['id'] ?>" form="bulkDeleteForm" data-row-check></td>
          <td class="table-td font-mono text-xs text-gray-500"><?= e($item['sku']) ?></td>
          <td class="table-td">
            <div class="font-medium text-gray-800"><?= e($item['name']) ?></div>
            <?= $item['barcode'] ? '<div class="text-xs text-gray-400">' . e($item['barcode']) . '</div>' : '' ?>
          </td>
          <td class="table-td text-gray-500"><?= $item['branch_name'] ? e($item['branch_name']) : '<span class="text-gray-300">Not stocked</span>' ?></td>
          <td class="table-td text-right">
            <?php if ($item['quantity'] !== null): ?>
            <span class="font-semibold <?= $isLow ? 'text-red-600' : 'text-gray-800' ?>">
              <?= number_format($item['quantity']) ?>
              <?= $isLow ? '<i class="fas fa-exclamation-triangle text-red-400 text-xs ml-1"></i>' : '' ?>
            </span>
            <?php else: ?>
            <span class="text-gray-300">—</span>
            <?php endif; ?>
          </td>
          <td class="table-td text-right text-gray-600"><?= formatCurrency((float)$item['effective_cost']) ?></td>
          <td class="table-td text-right text-gray-600"><?= formatCurrency((float)$item['effective_retail']) ?></td>
          <td class="table-td text-right">
            <div class="flex justify-end gap-1">
              <a href="<?= url('stock') ?>?action=edit&id=<?= $item['id'] ?>" class="text-indigo-500 hover:text-indigo-700 p-1.5 rounded hover:bg-indigo-50" title="Edit product">
                <i class="fas fa-edit text-xs"></i>
              </a>
              <button onclick="openAdjust(<?= $item['id'] ?>, '<?= e($item['name']) ?>', <?= $item['branch_id'] ?? 0 ?>, <?= $item['quantity'] ?? 0 ?>)"
                class="text-green-500 hover:text-green-700 p-1.5 rounded hover:bg-green-50" title="Adjust stock">
                <i class="fas fa-sliders-h text-xs"></i>
              </button>
              <button onclick="openTransfer(<?= $item['id'] ?>, '<?= e($item['name']) ?>', <?= $item['branch_id'] ?? 0 ?>, <?= $item['quantity'] ?? 0 ?>)"
                class="text-blue-500 hover:text-blue-700 p-1.5 rounded hover:bg-blue-50" title="Transfer stock">
                <i class="fas fa-exchange-alt text-xs"></i>
              </button>
              <form method="POST" class="inline" onsubmit="return confirm('Delete this product?')">
                <?= csrf_field() ?>
                <input type="hidden" name="_act" value="delete_product">
                <input type="hidden" name="product_id" value="<?= $item['id'] ?>">
                <button type="submit" class="text-red-400 hover:text-red-600 p-1.5 rounded hover:bg-red-50" title="Delete">
                  <i class="fas fa-trash text-xs"></i>
                </button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ── Add Stock Modal ── -->
<div id="addStockModal" class="hidden fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
  <div class="bg-white rounded-xl shadow-xl w-full max-w-md">
    <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100">
      <h3 class="font-semibold text-gray-800">Add Stock</h3>
      <button onclick="document.getElementById('addStockModal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST" class="p-5 space-y-4">
      <?= csrf_field() ?>
      <input type="hidden" name="_act" value="add_stock">
      <div>
        <label class="form-label">Product <span class="text-red-500">*</span></label>
        <select name="product_id" required class="form-input searchable">
          <option value="">-- Select Product --</option>
          <?php foreach ($allProducts as $p): ?>
          <option value="<?= $p['id'] ?>"><?= e($p['name']) ?> (<?= e($p['sku']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="form-label">Branch <span class="text-red-500">*</span></label>
        <select name="branch_id" required class="form-input">
          <option value="">-- Select Branch --</option>
          <?php foreach ($branches as $b): ?>
          <option value="<?= $b['id'] ?>" <?= $filterBranch === (int)$b['id'] ? 'selected' : '' ?>><?= e($b['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="form-label">Quantity to Add <span class="text-red-500">*</span></label>
        <input type="number" name="quantity" required class="form-input" min="1" placeholder="0">
      </div>
      <div>
        <label class="form-label">Reason</label>
        <input type="text" name="reason" class="form-input" value="Manual stock addition" placeholder="Reason for adding stock">
      </div>
      <div class="flex gap-3 pt-2">
        <button type="submit" class="btn-primary flex-1"><i class="fas fa-plus"></i> Add Stock</button>
        <button type="button" onclick="document.getElementById('addStockModal').classList.add('hidden')" class="btn-secondary">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- ── Adjust Stock Modal ── -->
<div id="adjustModal" class="hidden fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
  <div class="bg-white rounded-xl shadow-xl w-full max-w-md">
    <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100">
      <h3 class="font-semibold text-gray-800">Adjust Stock: <span id="adjustProductName"></span></h3>
      <button onclick="document.getElementById('adjustModal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST" class="p-5 space-y-4">
      <?= csrf_field() ?>
      <input type="hidden" name="_act" value="adjust_stock">
      <input type="hidden" name="product_id" id="adjustProductId">
      <input type="hidden" name="branch_id" id="adjustBranchId">
      <div>
        <label class="form-label">New Quantity <span class="text-red-500">*</span></label>
        <input type="number" name="new_quantity" id="adjustQty" required class="form-input" min="0">
      </div>
      <div>
        <label class="form-label">Reason <span class="text-red-500">*</span></label>
        <input type="text" name="reason" required class="form-input" placeholder="Reason for adjustment">
      </div>
      <div class="flex gap-3 pt-2">
        <button type="submit" class="btn-primary flex-1"><i class="fas fa-check"></i> Apply Adjustment</button>
        <button type="button" onclick="document.getElementById('adjustModal').classList.add('hidden')" class="btn-secondary">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- ── Transfer Modal ── -->
<div id="transferModal" class="hidden fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
  <div class="bg-white rounded-xl shadow-xl w-full max-w-md">
    <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100">
      <h3 class="font-semibold text-gray-800">Transfer Stock: <span id="transferProductName"></span></h3>
      <button onclick="document.getElementById('transferModal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST" class="p-5 space-y-4">
      <?= csrf_field() ?>
      <input type="hidden" name="_act" value="copy_branch">
      <input type="hidden" name="product_id" id="transferProductId">
      <div>
        <label class="form-label">From Branch</label>
        <select name="from_branch_id" id="transferFromBranch" class="form-input">
          <?php foreach ($branches as $b): ?>
          <option value="<?= $b['id'] ?>"><?= e($b['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <p class="text-xs text-gray-400 mt-1">Available: <span id="transferAvail">—</span> units</p>
      </div>
      <div>
        <label class="form-label">To Branch</label>
        <select name="to_branch_id" class="form-input">
          <?php foreach ($branches as $b): ?>
          <option value="<?= $b['id'] ?>"><?= e($b['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="form-label">Quantity to Transfer</label>
        <input type="number" name="quantity" required class="form-input" min="1" placeholder="0">
      </div>
      <div class="flex gap-3 pt-2">
        <button type="submit" class="btn-primary flex-1"><i class="fas fa-exchange-alt"></i> Transfer</button>
        <button type="button" onclick="document.getElementById('transferModal').classList.add('hidden')" class="btn-secondary">Cancel</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<script>
function openAdjust(productId, productName, branchId, currentQty) {
  document.getElementById('adjustProductId').value = productId;
  document.getElementById('adjustBranchId').value  = branchId;
  document.getElementById('adjustQty').value        = currentQty;
  document.getElementById('adjustProductName').textContent = productName;
  document.getElementById('adjustModal').classList.remove('hidden');
}

function openTransfer(productId, productName, branchId, currentQty) {
  document.getElementById('transferProductId').value = productId;
  document.getElementById('transferProductName').textContent = productName;
  document.getElementById('transferAvail').textContent = currentQty;
  // Set the from branch selector
  const fromSel = document.getElementById('transferFromBranch');
  if (branchId) {
    for (let opt of fromSel.options) { if (opt.value == branchId) { opt.selected = true; break; } }
  }
  document.getElementById('transferModal').classList.remove('hidden');
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
