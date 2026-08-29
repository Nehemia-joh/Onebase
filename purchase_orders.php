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
$pageTitle = 'Purchase Orders';
$action    = clean($_GET['action'] ?? 'list');
$editId    = cleanInt($_GET['id'] ?? 0);
$u         = currentUser();

$suppliers = $db->query("SELECT id, name FROM suppliers WHERE status='active' ORDER BY name")->fetchAll();
$branches  = $db->query('SELECT id, name FROM branches WHERE is_active=1 ORDER BY name')->fetchAll();
$products  = $db->query('SELECT id, sku, name, barcode, cost_price FROM products WHERE is_active=1 ORDER BY name')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $act = clean($_POST['_act'] ?? '');

    if ($act === 'create') {
        $supplierId  = cleanInt($_POST['supplier_id'] ?? 0);
        $branchId    = cleanInt($_POST['branch_id'] ?? 0);
        $expectedDate= clean($_POST['expected_date'] ?? '');
        $notes       = clean($_POST['notes'] ?? '');
        $taxAmt      = cleanFloat($_POST['tax'] ?? 0);
        $shipping    = cleanFloat($_POST['shipping'] ?? 0);
        $discount    = cleanFloat($_POST['discount'] ?? 0);
        $items       = $_POST['items'] ?? [];

        if (!$supplierId || !$branchId || empty($items)) {
            flash('error', 'Please select supplier, branch, and add items.'); header('Location: ' . url('purchase_orders?action=new')); exit;
        }

        $subtotal = 0;
        $poItems  = [];
        foreach ($items as $item) {
            $pid  = cleanInt($item['product_id'] ?? 0);
            $qty  = cleanInt($item['quantity_ordered'] ?? 0);
            $cost = cleanFloat($item['unit_cost'] ?? 0);
            if (!$pid || $qty <= 0) continue;
            $prod = $db->prepare('SELECT name, sku FROM products WHERE id=?'); $prod->execute([$pid]); $prod = $prod->fetch();
            if (!$prod) continue;
            $line = $qty * $cost;
            $subtotal += $line;
            $poItems[] = ['product_id'=>$pid,'product_name'=>$prod['name'],'sku'=>$prod['sku'],'quantity_ordered'=>$qty,'unit_cost'=>$cost,'total_cost'=>$line];
        }

        if (empty($poItems)) { flash('error','No valid items.'); header('Location: ' . url('purchase_orders?action=new')); exit; }

        $total   = $subtotal + $taxAmt + $shipping - $discount;

        $db->beginTransaction();
        try {
            $db->prepare('INSERT INTO purchase_orders (po_number,supplier_id,branch_id,status,subtotal,tax,shipping,discount,total,notes,expected_date,created_by) VALUES (?,?,?,\'draft\',?,?,?,?,?,?,?,?)')
                ->execute(['TMP-'.bin2hex(random_bytes(8)),$supplierId,$branchId,$subtotal,$taxAmt,$shipping,$discount,$total,$notes,$expectedDate ?: null,$u['id']]);
            $poId  = (int)$db->lastInsertId();
            $poNum = stampDocumentNo('purchase_orders', 'po_number', 'PO', $poId, 4);
            foreach ($poItems as $pi) {
                $db->prepare('INSERT INTO purchase_order_items (po_id,product_id,product_name,sku,quantity_ordered,unit_cost,total_cost) VALUES (?,?,?,?,?,?,?)')->execute([$poId,$pi['product_id'],$pi['product_name'],$pi['sku'],$pi['quantity_ordered'],$pi['unit_cost'],$pi['total_cost']]);
            }
            $db->commit();
            logActivity('po_created', "Created PO: $poNum");
            flash('success', "Purchase Order $poNum created.");
            header('Location: ' . url("purchase_orders?view=$poId")); exit;
        } catch (PDOException $e) {
            $db->rollBack();
            flash('error','Error creating PO: ' . $e->getMessage());
            header('Location: ' . url('purchase_orders?action=new')); exit;
        }
    }

    // Status update
    if ($act === 'update_status') {
        $poId   = cleanInt($_POST['po_id'] ?? 0);
        $status = clean($_POST['status'] ?? '');
        $valid  = ['draft','pending','approved','ordered','received','cancelled'];
        if (!in_array($status, $valid)) { flash('error','Invalid status.'); header('Location: ' . url('purchase_orders')); exit; }

        if ($status === 'approved') {
            $db->prepare('UPDATE purchase_orders SET status=?, approved_by=? WHERE id=?')->execute([$status,$u['id'],$poId]);
        } else {
            $db->prepare('UPDATE purchase_orders SET status=? WHERE id=?')->execute([$status,$poId]);
        }
        logActivity('po_status_updated', "PO ID $poId status changed to $status");
        flash('success', "Status updated to " . ucfirst($status) . ".");
        header('Location: ' . url("purchase_orders?view=$poId")); exit;
    }

    // Receive PO
    if ($act === 'receive') {
        $poId    = cleanInt($_POST['po_id'] ?? 0);
        $branchId= cleanInt($_POST['branch_id'] ?? 0);
        $received= $_POST['received'] ?? [];
        $rcvNotes= clean($_POST['receive_notes'] ?? '');

        $db->beginTransaction();
        try {
            $allReceived = true;
            foreach ($received as $itemId => $qty) {
                $qty = cleanInt($qty);
                if ($qty <= 0) continue;
                $item = $db->prepare('SELECT * FROM purchase_order_items WHERE id=? AND po_id=?'); $item->execute([$itemId,$poId]); $item = $item->fetch();
                if (!$item) continue;

                // Update received qty
                $newRcv = min($item['quantity_ordered'], $item['quantity_received'] + $qty);
                $db->prepare('UPDATE purchase_order_items SET quantity_received=? WHERE id=?')->execute([$newRcv,$itemId]);

                // Add to stock
                $before = (function() use ($db,$item,$branchId) { $s=$db->prepare('SELECT COALESCE(quantity,0) FROM stock WHERE product_id=? AND branch_id=?'); $s->execute([$item['product_id'],$branchId]); return (int)$s->fetchColumn(); })();
                $after  = $before + $qty;
                $db->prepare('INSERT INTO stock (product_id,branch_id,quantity) VALUES (?,?,?) ON DUPLICATE KEY UPDATE quantity=quantity+?')->execute([$item['product_id'],$branchId,$qty,$qty]);
                $db->prepare('INSERT INTO stock_adjustments (product_id,branch_id,adjustment_type,quantity_before,quantity_changed,quantity_after,reason,reference,user_id) VALUES (?,?,\'add\',?,?,?,?,?,?)')->execute([$item['product_id'],$branchId,$before,$qty,$after,'PO Receipt','PO:'.$poId,$u['id']]);
                $db->prepare('INSERT INTO po_receiving_log (po_id,po_item_id,quantity_received,received_by,notes) VALUES (?,?,?,?,?)')->execute([$poId,$itemId,$qty,$u['id'],$rcvNotes]);

                if ($newRcv < $item['quantity_ordered']) $allReceived = false;
            }

            $newStatus = $allReceived ? 'received' : 'ordered';
            $db->prepare('UPDATE purchase_orders SET status=?, received_date=' . ($allReceived ? 'CURDATE()' : 'received_date') . ' WHERE id=?')->execute([$newStatus,$poId]);
            $db->commit();
            logActivity('po_received', "Received items for PO ID $poId");
            flash('success', 'Stock received and updated successfully.');
        } catch (PDOException $e) {
            $db->rollBack();
            flash('error','Error receiving: ' . $e->getMessage());
        }
        header('Location: ' . url("purchase_orders?view=$poId")); exit;
    }
}

// View PO
$viewPO = null;
if (isset($_GET['view'])) {
    $sid = cleanInt($_GET['view']);
    $stmt = $db->prepare('SELECT po.*, s.name as supplier_name, s.phone as supplier_phone, b.name as branch_name, u.full_name as created_by_name, ua.full_name as approved_by_name FROM purchase_orders po JOIN suppliers s ON s.id=po.supplier_id JOIN branches b ON b.id=po.branch_id LEFT JOIN users u ON u.id=po.created_by LEFT JOIN users ua ON ua.id=po.approved_by WHERE po.id=?');
    $stmt->execute([$sid]); $viewPO = $stmt->fetch();
    if ($viewPO) {
        $stmt2 = $db->prepare('SELECT * FROM purchase_order_items WHERE po_id=?'); $stmt2->execute([$sid]);
        $viewPO['items'] = $stmt2->fetchAll();
    }
}

// List
$search = clean($_GET['q'] ?? '');
$filterStatus = clean($_GET['status'] ?? '');
$filterBranch = cleanInt($_GET['branch_id'] ?? 0) ?: ($u['branch_id'] ?? 0);
$where = ['1=1']; $params = [];
if ($search) { $where[] = '(po.po_number LIKE ? OR s.name LIKE ?)'; $params[] = "%$search%"; $params[] = "%$search%"; }
if ($filterStatus) { $where[] = 'po.status = ?'; $params[] = $filterStatus; }
if ($filterBranch) { $where[] = 'po.branch_id = ?'; $params[] = $filterBranch; }
$whereStr = implode(' AND ', $where);
$stmt = $db->prepare("SELECT po.*, s.name as supplier_name, b.name as branch_name FROM purchase_orders po JOIN suppliers s ON s.id=po.supplier_id JOIN branches b ON b.id=po.branch_id WHERE $whereStr ORDER BY po.created_at DESC LIMIT 100");
$stmt->execute($params); $poList = $stmt->fetchAll();

include __DIR__ . '/includes/tailwind.php';
?>

<?php if ($viewPO): ?>
<!-- ── PO Detail ── -->
<?php
$statusColors = ['draft'=>'bg-gray-100 text-gray-600','pending'=>'bg-yellow-100 text-yellow-700','approved'=>'bg-blue-100 text-blue-700','ordered'=>'bg-indigo-100 text-indigo-700','received'=>'bg-green-100 text-green-700','cancelled'=>'bg-red-100 text-red-600'];
$sc = $statusColors[$viewPO['status']] ?? 'bg-gray-100 text-gray-600';
?>
<div class="max-w-3xl mx-auto">
  <div class="flex items-center gap-3 mb-5">
    <a href="<?= url('purchase_orders') ?>" class="text-gray-400 hover:text-gray-600"><i class="fas fa-arrow-left"></i></a>
    <h2 class="text-lg font-semibold"><?= e($viewPO['po_number']) ?></h2>
    <span class="badge <?= $sc ?> capitalize ml-2"><?= e($viewPO['status']) ?></span>
    <button onclick="window.print()" class="ml-auto btn-secondary"><i class="fas fa-print"></i> Print</button>
  </div>

  <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 mb-4">
    <div class="grid sm:grid-cols-3 gap-4 text-sm mb-5">
      <div><p class="text-gray-500">Supplier</p><p class="font-medium"><?= e($viewPO['supplier_name']) ?></p><?= $viewPO['supplier_phone'] ? '<p class="text-xs text-gray-400">' . e($viewPO['supplier_phone']) . '</p>' : '' ?></div>
      <div><p class="text-gray-500">Branch</p><p class="font-medium"><?= e($viewPO['branch_name']) ?></p></div>
      <div><p class="text-gray-500">Created By</p><p class="font-medium"><?= e($viewPO['created_by_name'] ?? '—') ?></p><p class="text-xs text-gray-400"><?= date('d M Y', strtotime($viewPO['created_at'])) ?></p></div>
      <?= $viewPO['expected_date'] ? '<div><p class="text-gray-500">Expected Date</p><p class="font-medium">' . date('d M Y', strtotime($viewPO['expected_date'])) . '</p></div>' : '' ?>
      <?= $viewPO['approved_by_name'] ? '<div><p class="text-gray-500">Approved By</p><p class="font-medium">' . e($viewPO['approved_by_name']) . '</p></div>' : '' ?>
    </div>

    <table class="w-full text-sm mb-4">
      <thead><tr class="border-b-2 border-gray-200">
        <th class="py-2 text-left text-gray-600">Product</th>
        <th class="py-2 text-right text-gray-600">Ordered</th>
        <th class="py-2 text-right text-gray-600">Received</th>
        <th class="py-2 text-right text-gray-600">Unit Cost</th>
        <th class="py-2 text-right text-gray-600">Total</th>
      </tr></thead>
      <tbody>
      <?php foreach ($viewPO['items'] as $pi): ?>
      <tr class="border-b border-gray-100">
        <td class="py-2"><p class="font-medium"><?= e($pi['product_name']) ?></p><p class="text-xs text-gray-400"><?= e($pi['sku']) ?></p></td>
        <td class="py-2 text-right"><?= $pi['quantity_ordered'] ?></td>
        <td class="py-2 text-right">
          <span class="<?= $pi['quantity_received'] >= $pi['quantity_ordered'] ? 'text-green-600 font-semibold' : ($pi['quantity_received'] > 0 ? 'text-yellow-600' : 'text-gray-400') ?>">
            <?= $pi['quantity_received'] ?>
          </span>
        </td>
        <td class="py-2 text-right"><?= formatCurrency((float)$pi['unit_cost']) ?></td>
        <td class="py-2 text-right font-medium"><?= formatCurrency((float)$pi['total_cost']) ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <div class="border-t-2 border-gray-800 pt-4 space-y-1 text-sm max-w-xs ml-auto">
      <div class="flex justify-between text-gray-600"><span>Subtotal</span><span><?= formatCurrency((float)$viewPO['subtotal']) ?></span></div>
      <?php if ($viewPO['tax'] > 0): ?><div class="flex justify-between text-gray-600"><span>Tax</span><span><?= formatCurrency((float)$viewPO['tax']) ?></span></div><?php endif; ?>
      <?php if ($viewPO['shipping'] > 0): ?><div class="flex justify-between text-gray-600"><span>Shipping</span><span><?= formatCurrency((float)$viewPO['shipping']) ?></span></div><?php endif; ?>
      <?php if ($viewPO['discount'] > 0): ?><div class="flex justify-between text-red-600"><span>Discount</span><span>- <?= formatCurrency((float)$viewPO['discount']) ?></span></div><?php endif; ?>
      <div class="flex justify-between font-bold text-lg pt-2 border-t border-gray-200"><span>TOTAL</span><span><?= formatCurrency((float)$viewPO['total']) ?></span></div>
    </div>
    <?php if ($viewPO['notes']): ?><p class="mt-3 text-xs text-gray-500 italic">Notes: <?= e($viewPO['notes']) ?></p><?php endif; ?>
  </div>

  <!-- Status actions -->
  <?php if (!in_array($viewPO['status'], ['received','cancelled'])): ?>
  <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 mb-4">
    <h3 class="text-sm font-semibold text-gray-700 mb-3">Update Status</h3>
    <div class="flex flex-wrap gap-2">
      <?php
      $transitions = [
        'draft'    => ['pending' => ['label'=>'Submit for Approval','color'=>'btn-secondary']],
        'pending'  => ['approved'=>['label'=>'Approve','color'=>'btn-success'],'cancelled'=>['label'=>'Cancel','color'=>'btn-danger']],
        'approved' => ['ordered' =>['label'=>'Mark as Ordered','color'=>'btn-primary'],'cancelled'=>['label'=>'Cancel','color'=>'btn-danger']],
        'ordered'  => [], // handled by receive form below
      ];
      $avail = $transitions[$viewPO['status']] ?? [];
      foreach ($avail as $toStatus => $btn):
      ?>
      <form method="POST" class="inline">
        <?= csrf_field() ?>
        <input type="hidden" name="_act" value="update_status">
        <input type="hidden" name="po_id" value="<?= $viewPO['id'] ?>">
        <input type="hidden" name="status" value="<?= $toStatus ?>">
        <button type="submit" class="<?= $btn['color'] ?>"><?= $btn['label'] ?></button>
      </form>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Receive form -->
  <?php if (in_array($viewPO['status'], ['ordered','approved'])): ?>
  <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
    <h3 class="text-sm font-semibold text-gray-700 mb-3">Receive Items</h3>
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="_act" value="receive">
      <input type="hidden" name="po_id" value="<?= $viewPO['id'] ?>">
      <input type="hidden" name="branch_id" value="<?= $viewPO['branch_id'] ?>">
      <table class="w-full text-sm mb-4">
        <thead><tr class="border-b border-gray-100"><th class="py-2 text-left text-gray-500">Product</th><th class="py-2 text-right text-gray-500">Ordered</th><th class="py-2 text-right text-gray-500">Received</th><th class="py-2 text-right text-gray-500">Receive Now</th></tr></thead>
        <tbody>
        <?php foreach ($viewPO['items'] as $pi): ?>
        <?php $remaining = $pi['quantity_ordered'] - $pi['quantity_received']; ?>
        <tr class="border-b border-gray-50">
          <td class="py-2 font-medium"><?= e($pi['product_name']) ?></td>
          <td class="py-2 text-right"><?= $pi['quantity_ordered'] ?></td>
          <td class="py-2 text-right text-green-600"><?= $pi['quantity_received'] ?></td>
          <td class="py-2 text-right">
            <?php if ($remaining > 0): ?>
            <input type="number" name="received[<?= $pi['id'] ?>]" min="0" max="<?= $remaining ?>" value="<?= $remaining ?>"
              class="w-20 border border-gray-200 rounded px-2 py-1 text-right text-sm">
            <?php else: ?>
            <span class="text-green-500 text-xs"><i class="fas fa-check"></i> Done</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <div class="flex gap-3 items-center">
        <input type="text" name="receive_notes" placeholder="Receiving notes (optional)" class="form-input flex-1">
        <button type="submit" class="btn-success"><i class="fas fa-check"></i> Confirm Receipt</button>
      </div>
    </form>
  </div>
  <?php endif; ?>
</div>

<?php elseif ($action === 'new'): ?>
<!-- ── New PO Form ── -->
<div x-data="poForm()" class="max-w-3xl mx-auto">
  <div class="flex items-center gap-3 mb-5">
    <a href="<?= url('purchase_orders') ?>" class="text-gray-400 hover:text-gray-600"><i class="fas fa-arrow-left"></i></a>
    <h2 class="text-lg font-semibold text-gray-800">New Purchase Order</h2>
  </div>
  <form method="POST">
    <?= csrf_field() ?>
    <input type="hidden" name="_act" value="create">

    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 mb-4">
      <div class="grid sm:grid-cols-3 gap-4">
        <div>
          <label class="form-label">Supplier <span class="text-red-500">*</span></label>
          <select name="supplier_id" required class="form-input searchable">
            <option value="">-- Select Supplier --</option>
            <?php foreach ($suppliers as $s): ?>
            <option value="<?= $s['id'] ?>"><?= e($s['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="form-label">Deliver to Branch <span class="text-red-500">*</span></label>
          <select name="branch_id" required class="form-input">
            <option value="">-- Select Branch --</option>
            <?php foreach ($branches as $b): ?>
            <option value="<?= $b['id'] ?>" <?= ($u['branch_id'] == $b['id']) ? 'selected' : '' ?>><?= e($b['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="form-label">Expected Date</label>
          <input type="date" name="expected_date" class="form-input" min="<?= date('Y-m-d') ?>">
        </div>
      </div>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 mb-4">
      <div class="flex items-center justify-between mb-3">
        <h3 class="text-sm font-semibold text-gray-700">Order Items</h3>
        <button type="button" @click="addItem()" class="btn-secondary text-xs">
          <i class="fas fa-plus"></i> Add Item
        </button>
      </div>
      <table class="w-full text-sm">
        <thead><tr class="border-b border-gray-100 text-xs text-gray-500">
          <th class="py-2 text-left">Product</th>
          <th class="py-2 text-right w-20">Qty</th>
          <th class="py-2 text-right w-32">Unit Cost (TSh)</th>
          <th class="py-2 text-right w-28">Total</th>
          <th class="py-2 w-8"></th>
        </tr></thead>
        <tbody>
        <template x-for="(item,idx) in items" :key="idx">
          <tr class="border-b border-gray-50">
            <td class="py-2 pr-2">
              <div class="relative">
                <input type="hidden" :name="'items['+idx+'][product_id]'" :value="item.productId">
                <input type="text" x-model="item.search" autocomplete="off"
                  placeholder="Type to search product..."
                  class="form-input text-xs"
                  :required="!item.productId"
                  @focus="item.open = true"
                  @input="item.open = true; item.productId = ''"
                  @keydown.enter.prevent="pickFirst(idx)"
                  @keydown.escape="item.open = false"
                  @blur="setTimeout(() => item.open = false, 150)">
                <div x-show="item.open && item.search.trim().length > 0" x-cloak class="searchable-list">
                  <template x-for="p in filterProducts(item.search)" :key="p.id">
                    <div class="searchable-item text-xs" @mousedown.prevent="pickProduct(idx, p)"
                         x-text="p.name + ' (' + p.sku + ')'"></div>
                  </template>
                  <div x-show="filterProducts(item.search).length === 0" class="searchable-empty">No matching product</div>
                </div>
              </div>
            </td>
            <td class="py-2"><input type="number" :name="'items['+idx+'][quantity_ordered]'" x-model.number="item.qty" @input="calcLine(idx)" min="1" required class="w-16 border border-gray-200 rounded px-2 py-1 text-center text-sm"></td>
            <td class="py-2"><input type="number" :name="'items['+idx+'][unit_cost]'" x-model.number="item.cost" @input="calcLine(idx)" min="0" step="0.01" required class="w-28 border border-gray-200 rounded px-2 py-1 text-right text-sm"></td>
            <td class="py-2 text-right font-medium" x-text="formatMoney(item.total)"></td>
            <td class="py-2"><button type="button" @click="items.splice(idx,1);calcTotals()" class="text-red-400 hover:text-red-600"><i class="fas fa-times text-xs"></i></button></td>
          </tr>
        </template>
        <tr x-show="items.length===0"><td colspan="5" class="py-6 text-center text-gray-400 text-sm">No items. Click "Add Item".</td></tr>
        </tbody>
      </table>

      <div class="mt-4 pt-4 border-t border-gray-100 grid sm:grid-cols-3 gap-4 text-sm">
        <div class="flex flex-col gap-1">
          <label class="form-label text-xs">Tax (TSh)</label>
          <input type="number" name="tax" x-model.number="tax" @input="calcTotals()" min="0" step="0.01" class="form-input">
        </div>
        <div class="flex flex-col gap-1">
          <label class="form-label text-xs">Shipping (TSh)</label>
          <input type="number" name="shipping" x-model.number="shipping" @input="calcTotals()" min="0" step="0.01" class="form-input">
        </div>
        <div class="flex flex-col gap-1">
          <label class="form-label text-xs">Discount (TSh)</label>
          <input type="number" name="discount" x-model.number="discount" @input="calcTotals()" min="0" step="0.01" class="form-input">
        </div>
      </div>

      <div class="mt-3 text-right text-sm space-y-1">
        <div class="flex justify-end gap-8 text-gray-500"><span>Subtotal</span><span x-text="formatMoney(subtotal)"></span></div>
        <div class="flex justify-end gap-8 font-bold text-base border-t pt-2"><span>Total</span><span x-text="formatMoney(total)" class="text-indigo-600"></span></div>
      </div>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 mb-4">
      <label class="form-label">Notes</label>
      <textarea name="notes" rows="2" class="form-input" placeholder="Optional notes or instructions"></textarea>
    </div>

    <div class="flex gap-3">
      <button type="submit" class="btn-primary"><i class="fas fa-save"></i> Create Purchase Order</button>
      <a href="<?= url('purchase_orders') ?>" class="btn-secondary"><i class="fas fa-times"></i> Cancel</a>
    </div>
  </form>
</div>
<script>
const PO_PRODUCTS = <?= json_encode(array_map(fn($p) => [
    'id' => (int)$p['id'], 'name' => $p['name'], 'sku' => $p['sku'],
    'barcode' => $p['barcode'], 'cost' => (float)$p['cost_price'],
], $products), JSON_UNESCAPED_UNICODE) ?>;

function poForm() {
  return {
    items: [{ productId:'', search:'', open:false, qty:1, cost:0, total:0 }],
    tax:0, shipping:0, discount:0, subtotal:0, total:0,
    addItem() { this.items.push({ productId:'', search:'', open:false, qty:1, cost:0, total:0 }); },
    filterProducts(q) {
      const t = (q || '').trim().toLowerCase();
      return PO_PRODUCTS.filter(p =>
        p.name.toLowerCase().includes(t) ||
        p.sku.toLowerCase().includes(t) ||
        (p.barcode && String(p.barcode).toLowerCase().includes(t))
      ).slice(0, 30);
    },
    pickProduct(idx, p) {
      const it = this.items[idx];
      it.productId = p.id;
      it.search    = p.name + ' (' + p.sku + ')';
      it.cost      = p.cost;
      it.open      = false;
      this.calcLine(idx);
    },
    pickFirst(idx) {
      const m = this.filterProducts(this.items[idx].search);
      if (m.length) this.pickProduct(idx, m[0]);
    },
    calcLine(idx) { this.items[idx].total = this.items[idx].qty * this.items[idx].cost; this.calcTotals(); },
    calcTotals() {
      this.subtotal = this.items.reduce((s,i)=>s+i.total,0);
      this.total = Math.max(0, this.subtotal + (parseFloat(this.tax)||0) + (parseFloat(this.shipping)||0) - (parseFloat(this.discount)||0));
    },
    formatMoney(v) { return 'TSh ' + parseFloat(v||0).toLocaleString('en-TZ',{minimumFractionDigits:0}); }
  }
}
</script>

<?php else: ?>
<!-- ── PO List ── -->
<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-5">
  <form method="GET" class="flex flex-wrap gap-2">
    <input type="text" name="q" value="<?= e($search) ?>" placeholder="Search POs..." class="form-input w-44">
    <select name="status" onchange="this.form.submit()" class="form-input w-36 text-sm">
      <option value="">All Statuses</option>
      <?php foreach (['draft','pending','approved','ordered','received','cancelled'] as $st): ?>
      <option value="<?= $st ?>" <?= $filterStatus===$st?'selected':'' ?>><?= ucfirst($st) ?></option>
      <?php endforeach; ?>
    </select>
    <?php if (isSuperAdmin()||hasRole('zone_manager')): ?>
    <select name="branch_id" onchange="this.form.submit()" class="form-input w-40 text-sm">
      <option value="">All Branches</option>
      <?php foreach ($branches as $b): ?>
      <option value="<?= $b['id'] ?>" <?= $filterBranch==(int)$b['id']?'selected':'' ?>><?= e($b['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <?php endif; ?>
    <button type="submit" class="btn-secondary"><i class="fas fa-search"></i></button>
    <?php if ($search||$filterStatus): ?><a href="<?= url('purchase_orders') ?>" class="btn-secondary"><i class="fas fa-times"></i></a><?php endif; ?>
  </form>
  <a href="<?= url('purchase_orders') ?>?action=new" class="btn-primary"><i class="fas fa-plus"></i> New PO</a>
</div>

<?php
$statusColors = ['draft'=>'bg-gray-100 text-gray-600','pending'=>'bg-yellow-100 text-yellow-700','approved'=>'bg-blue-100 text-blue-700','ordered'=>'bg-indigo-100 text-indigo-700','received'=>'bg-green-100 text-green-700','cancelled'=>'bg-red-100 text-red-600'];
?>
<div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead class="bg-gray-50 border-b border-gray-100">
        <tr>
          <th class="table-th">PO Number</th>
          <th class="table-th">Supplier</th>
          <th class="table-th">Branch</th>
          <th class="table-th">Status</th>
          <th class="table-th text-right">Total</th>
          <th class="table-th">Date</th>
          <th class="table-th text-right">Actions</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-gray-50">
        <?php if (empty($poList)): ?>
        <tr><td colspan="7" class="py-10 text-center text-gray-400">No purchase orders found</td></tr>
        <?php endif; ?>
        <?php foreach ($poList as $po): ?>
        <tr class="hover:bg-gray-50 transition-colors">
          <td class="table-td font-mono font-medium text-indigo-600"><?= e($po['po_number']) ?></td>
          <td class="table-td text-gray-700"><?= e($po['supplier_name']) ?></td>
          <td class="table-td text-gray-500"><?= e($po['branch_name']) ?></td>
          <td class="table-td">
            <span class="badge <?= $statusColors[$po['status']] ?? 'bg-gray-100 text-gray-600' ?> capitalize"><?= e($po['status']) ?></span>
          </td>
          <td class="table-td text-right font-semibold"><?= formatCurrency((float)$po['total']) ?></td>
          <td class="table-td text-gray-400 text-xs"><?= date('d M Y', strtotime($po['created_at'])) ?></td>
          <td class="table-td text-right">
            <a href="<?= url('purchase_orders') ?>?view=<?= $po['id'] ?>" class="text-indigo-600 hover:text-indigo-800 px-2 py-1 rounded hover:bg-indigo-50"><i class="fas fa-eye"></i></a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
