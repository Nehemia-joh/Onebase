<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/currency_functions.php';
requireLogin();
// Role directories are the canonical URLs — forward direct hits there
if (!defined('ENTRY_OK') && isLoggedIn()) {
    $q = $_SERVER['QUERY_STRING'] ?? '';
    header('Location: ' . url(basename(__FILE__, '.php')) . ($q !== '' ? "?$q" : ''));
    exit;
}

$db        = getDB();
$pageTitle = 'Returns';
$action    = clean($_GET['action'] ?? 'list');
$u         = currentUser();

$branchLocked = $u['branch_id'] && !isSuperAdmin() && !hasRole('zone_manager');

// ── POST: Create return ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $act = clean($_POST['_act'] ?? '');

    if ($act === 'create_return') {
        $saleId = cleanInt($_POST['sale_id'] ?? 0);
        $reason = clean($_POST['reason'] ?? '');
        $method = in_array($_POST['refund_method'] ?? '', ['cash','card','mobile_money','deduct_balance']) ? $_POST['refund_method'] : 'cash';
        $qtys   = $_POST['return_qty'] ?? [];

        $stmt = $db->prepare('SELECT * FROM sales WHERE id=?');
        $stmt->execute([$saleId]);
        $sale = $stmt->fetch();
        if (!$sale) { flash('error','Sale not found.'); header('Location: ' . url('returns')); exit; }
        if ($branchLocked && (int)$sale['branch_id'] !== (int)$u['branch_id']) {
            flash('error','You cannot process returns for another branch.'); header('Location: ' . url('returns')); exit;
        }

        // Load sale items + how much has already been returned per item
        $itemStmt = $db->prepare(
            'SELECT si.*, COALESCE((SELECT SUM(sri.quantity) FROM sale_return_items sri WHERE sri.sale_item_id = si.id),0) as returned_qty
             FROM sale_items si WHERE si.sale_id=?'
        );
        $itemStmt->execute([$saleId]);
        $saleItems = [];
        foreach ($itemStmt->fetchAll() as $si) { $saleItems[(int)$si['id']] = $si; }

        $returnItems = [];
        $totalRefund = 0.0;
        foreach ($qtys as $itemId => $qty) {
            $itemId = (int)$itemId;
            $qty    = cleanInt($qty);
            if ($qty <= 0 || !isset($saleItems[$itemId])) continue;
            $si  = $saleItems[$itemId];
            $max = (int)$si['quantity'] - (int)$si['returned_qty'];
            $qty = min($qty, max(0, $max));
            if ($qty <= 0) continue;
            $line = round($qty * (float)$si['unit_price'], 2);
            $totalRefund += $line;
            $returnItems[] = [
                'sale_item_id' => $itemId,
                'product_id'   => (int)$si['product_id'],
                'product_name' => $si['product_name'],
                'sku'          => $si['sku'],
                'quantity'     => $qty,
                'unit_price'   => (float)$si['unit_price'],
                'total_price'  => $line,
            ];
        }

        if (empty($returnItems)) { flash('error','Select at least one item and quantity to return.'); header('Location: ' . url("returns?action=new&sale_id=$saleId")); exit; }

        $outstanding = max(0.0, (float)$sale['total'] - (float)$sale['amount_paid']);
        if ($method === 'deduct_balance' && $outstanding <= 0) $method = 'cash';

        $db->beginTransaction();
        try {
            $db->prepare('INSERT INTO sale_returns (return_no,sale_id,branch_id,total_refund,refund_method,reason,created_by) VALUES (?,?,?,?,?,?,?)')
                ->execute(['TMP-'.bin2hex(random_bytes(8)),$saleId,$sale['branch_id'],$totalRefund,$method,$reason,$u['id']]);
            $returnId = (int)$db->lastInsertId();
            $returnNo = stampDocumentNo('sale_returns', 'return_no', 'RET', $returnId, 4);

            foreach ($returnItems as $ri) {
                $db->prepare('INSERT INTO sale_return_items (return_id,sale_item_id,product_id,product_name,sku,quantity,unit_price,total_price) VALUES (?,?,?,?,?,?,?,?)')
                    ->execute([$returnId,$ri['sale_item_id'],$ri['product_id'],$ri['product_name'],$ri['sku'],$ri['quantity'],$ri['unit_price'],$ri['total_price']]);

                // Put stock back
                $before = (function() use ($db,$ri,$sale) {
                    $s=$db->prepare('SELECT COALESCE(quantity,0) FROM stock WHERE product_id=? AND branch_id=?');
                    $s->execute([$ri['product_id'],$sale['branch_id']]); return (int)$s->fetchColumn();
                })();
                $db->prepare('INSERT INTO stock (product_id,branch_id,quantity) VALUES (?,?,?) ON DUPLICATE KEY UPDATE quantity=quantity+?')
                    ->execute([$ri['product_id'],$sale['branch_id'],$ri['quantity'],$ri['quantity']]);
                $db->prepare('INSERT INTO stock_adjustments (product_id,branch_id,adjustment_type,quantity_before,quantity_changed,quantity_after,reason,reference,user_id) VALUES (?,?,\'add\',?,?,?,\'Sale return\',?,?)')
                    ->execute([$ri['product_id'],$sale['branch_id'],$before,$ri['quantity'],$before+$ri['quantity'],'RET:'.$returnNo,$u['id']]);
            }

            // A deduct_balance refund reduces the customer's debt via a return_credit payment
            if ($method === 'deduct_balance') {
                $credit = min($totalRefund, $outstanding);
                if ($credit > 0) {
                    $db->prepare('INSERT INTO sale_payments (sale_id,amount,method,notes,received_by) VALUES (?,?,\'return_credit\',?,?)')
                        ->execute([$saleId,$credit,"Return $returnNo credited against balance",$u['id']]);
                    $newPaid   = (float)$sale['amount_paid'] + $credit;
                    $newStatus = $newPaid >= (float)$sale['total'] ? 'paid' : 'partial';
                    $db->prepare('UPDATE sales SET amount_paid=?, payment_status=? WHERE id=?')->execute([$newPaid,$newStatus,$saleId]);
                }
            }

            $db->commit();
            logActivity('sale_returned', "Return $returnNo on {$sale['invoice_no']} — " . formatCurrency($totalRefund));
            flash('success', "Return $returnNo processed. Stock restored." .
                ($method === 'deduct_balance' ? ' Amount credited against the invoice balance.' : ' Refund: ' . formatCurrency($totalRefund)));
            header('Location: ' . url("returns?view=$returnId")); exit;
        } catch (PDOException $e) {
            $db->rollBack();
            flash('error','Error processing return: ' . $e->getMessage());
            header('Location: ' . url("returns?action=new&sale_id=$saleId")); exit;
        }
    }
}

// ── View return ───────────────────────────────────────────────────────────────
$viewReturn = null;
if (isset($_GET['view'])) {
    $rid  = cleanInt($_GET['view']);
    $stmt = $db->prepare(
        'SELECT r.*, s.invoice_no, s.customer_name, b.name as branch_name, u2.full_name as created_by_name
         FROM sale_returns r
         JOIN sales s ON s.id=r.sale_id
         JOIN branches b ON b.id=r.branch_id
         LEFT JOIN users u2 ON u2.id=r.created_by
         WHERE r.id=?'
    );
    $stmt->execute([$rid]);
    $viewReturn = $stmt->fetch();
    if ($viewReturn) {
        $i = $db->prepare('SELECT * FROM sale_return_items WHERE return_id=?');
        $i->execute([$rid]);
        $viewReturn['items'] = $i->fetchAll();
    }
}

// ── New return form data ──────────────────────────────────────────────────────
$returnSale = null;
if ($action === 'new') {
    $saleId = cleanInt($_GET['sale_id'] ?? 0);
    if ($saleId) {
        $stmt = $db->prepare('SELECT s.*, b.name as branch_name FROM sales s JOIN branches b ON b.id=s.branch_id WHERE s.id=?');
        $stmt->execute([$saleId]);
        $returnSale = $stmt->fetch();
        if ($returnSale && $branchLocked && (int)$returnSale['branch_id'] !== (int)$u['branch_id']) {
            flash('error','That invoice belongs to another branch.'); header('Location: ' . url('returns')); exit;
        }
        if ($returnSale) {
            $itemStmt = $db->prepare(
                'SELECT si.*, COALESCE((SELECT SUM(sri.quantity) FROM sale_return_items sri WHERE sri.sale_item_id = si.id),0) as returned_qty
                 FROM sale_items si WHERE si.sale_id=?'
            );
            $itemStmt->execute([$saleId]);
            $returnSale['items'] = $itemStmt->fetchAll();
            $returnSale['outstanding'] = max(0.0, (float)$returnSale['total'] - (float)$returnSale['amount_paid']);
        }
    }
}

// ── List ──────────────────────────────────────────────────────────────────────
$listWhere  = $branchLocked ? 'WHERE r.branch_id = ' . (int)$u['branch_id'] : '';
$returnsList = $db->query(
    "SELECT r.*, s.invoice_no, s.customer_name, b.name as branch_name, u2.full_name as created_by_name
     FROM sale_returns r
     JOIN sales s ON s.id=r.sale_id
     JOIN branches b ON b.id=r.branch_id
     LEFT JOIN users u2 ON u2.id=r.created_by
     $listWhere
     ORDER BY r.created_at DESC LIMIT 100"
)->fetchAll();

include __DIR__ . '/includes/tailwind.php';
?>

<?php if ($viewReturn): ?>
<!-- ── Return Detail ── -->
<div class="max-w-2xl mx-auto">
  <div class="flex items-center gap-3 mb-6">
    <a href="<?= url('returns') ?>" class="text-gray-400 hover:text-gray-600"><i class="fas fa-arrow-left"></i></a>
    <h2 class="text-lg font-semibold text-gray-800">Return <?= e($viewReturn['return_no']) ?></h2>
    <a href="<?= url('sales') ?>?view=<?= $viewReturn['sale_id'] ?>" class="ml-auto btn-secondary"><i class="fas fa-receipt"></i> View Invoice</a>
  </div>
  <div class="page-card p-6">
    <div class="grid grid-cols-2 gap-4 mb-6 p-4 bg-gray-50 rounded-lg text-sm">
      <div><span class="text-gray-500">Invoice:</span> <a href="<?= url('sales') ?>?view=<?= $viewReturn['sale_id'] ?>" class="font-mono font-medium text-indigo-600"><?= e($viewReturn['invoice_no']) ?></a></div>
      <div><span class="text-gray-500">Customer:</span> <span class="font-medium"><?= e($viewReturn['customer_name']) ?></span></div>
      <div><span class="text-gray-500">Branch:</span> <span class="font-medium"><?= e($viewReturn['branch_name']) ?></span></div>
      <div><span class="text-gray-500">Refund via:</span> <span class="font-medium capitalize"><?= e(str_replace('_',' ',$viewReturn['refund_method'])) ?></span></div>
      <div><span class="text-gray-500">Processed by:</span> <span class="font-medium"><?= e($viewReturn['created_by_name'] ?? '—') ?></span></div>
      <div><span class="text-gray-500">Date:</span> <span class="font-medium"><?= date('d M Y, H:i', strtotime($viewReturn['created_at'])) ?></span></div>
      <?php if ($viewReturn['reason']): ?>
      <div class="col-span-2"><span class="text-gray-500">Reason:</span> <?= e($viewReturn['reason']) ?></div>
      <?php endif; ?>
    </div>
    <table class="w-full text-sm mb-4">
      <thead><tr class="border-b-2 border-gray-200">
        <th class="py-2 text-left text-gray-600">Product</th>
        <th class="py-2 text-right text-gray-600">Qty</th>
        <th class="py-2 text-right text-gray-600">Price</th>
        <th class="py-2 text-right text-gray-600">Refund</th>
      </tr></thead>
      <tbody>
      <?php foreach ($viewReturn['items'] as $ri): ?>
      <tr class="border-b border-gray-100">
        <td class="py-2"><p class="font-medium"><?= e($ri['product_name']) ?></p><p class="text-xs text-gray-400"><?= e($ri['sku']) ?></p></td>
        <td class="py-2 text-right"><?= $ri['quantity'] ?></td>
        <td class="py-2 text-right"><?= formatCurrency((float)$ri['unit_price']) ?></td>
        <td class="py-2 text-right font-medium"><?= formatCurrency((float)$ri['total_price']) ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <div class="flex justify-between text-lg font-bold pt-2 border-t-2 border-gray-800">
      <span>TOTAL REFUND</span><span class="text-red-600"><?= formatCurrency((float)$viewReturn['total_refund']) ?></span>
    </div>
  </div>
</div>

<?php elseif ($action === 'new' && $returnSale): ?>
<!-- ── New Return Form ── -->
<div class="max-w-3xl mx-auto" x-data="returnForm()">
  <div class="flex items-center gap-3 mb-6">
    <a href="<?= url('sales') ?>?view=<?= $returnSale['id'] ?>" class="text-gray-400 hover:text-gray-600"><i class="fas fa-arrow-left"></i></a>
    <h2 class="text-lg font-semibold text-gray-800">Return Items &mdash; <?= e($returnSale['invoice_no']) ?></h2>
  </div>

  <form method="POST">
    <?= csrf_field() ?>
    <input type="hidden" name="_act" value="create_return">
    <input type="hidden" name="sale_id" value="<?= $returnSale['id'] ?>">

    <div class="page-card p-5 mb-5">
      <div class="grid grid-cols-2 gap-4 text-sm mb-4">
        <div><span class="text-gray-500">Customer:</span> <span class="font-medium"><?= e($returnSale['customer_name']) ?></span></div>
        <div><span class="text-gray-500">Branch:</span> <span class="font-medium"><?= e($returnSale['branch_name']) ?></span></div>
        <div><span class="text-gray-500">Sale total:</span> <span class="font-medium"><?= formatCurrency((float)$returnSale['total']) ?></span></div>
        <div><span class="text-gray-500">Balance due:</span> <span class="font-medium <?= $returnSale['outstanding'] > 0 ? 'text-red-600' : '' ?>"><?= formatCurrency((float)$returnSale['outstanding']) ?></span></div>
      </div>

      <table class="w-full text-sm">
        <thead><tr class="border-b border-gray-100 text-xs text-gray-500">
          <th class="py-2 text-left">Product</th>
          <th class="py-2 text-right">Sold</th>
          <th class="py-2 text-right">Already Returned</th>
          <th class="py-2 text-right">Unit Price</th>
          <th class="py-2 text-center w-28">Return Qty</th>
          <th class="py-2 text-right">Refund</th>
        </tr></thead>
        <tbody>
        <?php foreach ($returnSale['items'] as $si):
            $max = (int)$si['quantity'] - (int)$si['returned_qty'];
        ?>
        <tr class="border-b border-gray-50">
          <td class="py-2.5"><p class="font-medium"><?= e($si['product_name']) ?></p><p class="text-xs text-gray-400"><?= e($si['sku']) ?></p></td>
          <td class="py-2.5 text-right"><?= $si['quantity'] ?></td>
          <td class="py-2.5 text-right text-gray-400"><?= $si['returned_qty'] ?></td>
          <td class="py-2.5 text-right"><?= formatCurrency((float)$si['unit_price']) ?></td>
          <td class="py-2.5 text-center">
            <?php if ($max > 0): ?>
            <input type="number" name="return_qty[<?= $si['id'] ?>]" min="0" max="<?= $max ?>" value="0"
              @input="calc()" data-price="<?= $si['unit_price'] ?>"
              class="return-qty w-20 border border-gray-200 rounded px-2 py-1 text-center text-sm focus:ring-1 focus:ring-indigo-400">
            <?php else: ?>
            <span class="text-xs text-gray-400">Fully returned</span>
            <?php endif; ?>
          </td>
          <td class="py-2.5 text-right font-medium refund-cell">—</td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <div class="flex justify-between font-bold text-lg border-t-2 border-gray-200 pt-3 mt-2">
        <span>Total Refund</span><span x-text="fmt(total)" class="text-red-600"></span>
      </div>
    </div>

    <div class="page-card p-5 mb-5 grid sm:grid-cols-2 gap-4">
      <div>
        <label class="form-label">Refund Method</label>
        <select name="refund_method" class="form-input">
          <?php if ($returnSale['outstanding'] > 0): ?>
          <option value="deduct_balance">Deduct from balance due (<?= formatCurrency((float)$returnSale['outstanding']) ?>)</option>
          <?php endif; ?>
          <option value="cash">Cash refund</option>
          <option value="card">Card refund</option>
          <option value="mobile_money">Mobile Money refund</option>
        </select>
      </div>
      <div>
        <label class="form-label">Reason</label>
        <input type="text" name="reason" class="form-input" placeholder="e.g. Damaged, wrong item...">
      </div>
    </div>

    <div class="flex gap-3">
      <button type="submit" class="btn-danger" :disabled="total <= 0"
        onclick="return confirm('Process this return? Stock will be restored to the branch.')">
        <i class="fas fa-undo"></i> Process Return
      </button>
      <a href="<?= url('sales') ?>?view=<?= $returnSale['id'] ?>" class="btn-secondary">Cancel</a>
    </div>
  </form>
</div>

<script>
function returnForm() {
  return {
    total: 0,
    calc() {
      let sum = 0;
      document.querySelectorAll('.return-qty').forEach(inp => {
        const qty  = Math.min(parseInt(inp.value) || 0, parseInt(inp.max));
        const line = qty * parseFloat(inp.dataset.price);
        inp.closest('tr').querySelector('.refund-cell').textContent = line > 0 ? this.fmt(line) : '—';
        sum += line;
      });
      this.total = sum;
    },
    fmt(v) { return 'TSh ' + (v || 0).toLocaleString('en-TZ', { minimumFractionDigits: 0 }); }
  }
}
</script>

<?php elseif ($action === 'new'): ?>
<!-- ── Pick an invoice ── -->
<div class="max-w-xl mx-auto page-card p-8 text-center">
  <i class="fas fa-undo text-4xl text-gray-300 mb-4 block"></i>
  <h2 class="text-lg font-semibold text-gray-800 mb-2">Start a Return</h2>
  <p class="text-sm text-gray-500 mb-4">Open the invoice you want to return items from, then click <strong>Return Items</strong>.</p>
  <a href="<?= url('sales') ?>" class="btn-primary justify-center"><i class="fas fa-receipt"></i> Browse Sales</a>
</div>

<?php else: ?>
<!-- ── Returns List ── -->
<div class="flex items-center justify-between mb-5">
  <p class="text-sm text-gray-500">Showing latest <?= count($returnsList) ?> returns</p>
  <a href="<?= url('returns') ?>?action=new" class="btn-primary"><i class="fas fa-plus"></i> New Return</a>
</div>

<div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead class="bg-gray-50 border-b border-gray-100"><tr>
        <th class="table-th">Return #</th>
        <th class="table-th">Invoice</th>
        <th class="table-th">Customer</th>
        <th class="table-th">Branch</th>
        <th class="table-th">Refund Via</th>
        <th class="table-th text-right">Amount</th>
        <th class="table-th">By</th>
        <th class="table-th">Date</th>
      </tr></thead>
      <tbody class="divide-y divide-gray-50">
        <?php if (empty($returnsList)): ?>
        <tr><td colspan="8" class="py-12 text-center text-gray-400">
          <i class="fas fa-undo text-4xl mb-3 block"></i>No returns processed yet.
        </td></tr>
        <?php endif; ?>
        <?php foreach ($returnsList as $r): ?>
        <tr class="hover:bg-gray-50 transition-colors">
          <td class="table-td"><a href="<?= url('returns') ?>?view=<?= $r['id'] ?>" class="font-mono font-medium text-indigo-600"><?= e($r['return_no']) ?></a></td>
          <td class="table-td"><a href="<?= url('sales') ?>?view=<?= $r['sale_id'] ?>" class="font-mono text-gray-500 hover:text-indigo-600"><?= e($r['invoice_no']) ?></a></td>
          <td class="table-td"><?= e($r['customer_name']) ?></td>
          <td class="table-td text-gray-500"><?= e($r['branch_name']) ?></td>
          <td class="table-td capitalize text-gray-500"><?= e(str_replace('_',' ',$r['refund_method'])) ?></td>
          <td class="table-td text-right font-semibold text-red-600"><?= formatCurrency((float)$r['total_refund']) ?></td>
          <td class="table-td text-gray-500 text-xs"><?= e($r['created_by_name'] ?? '—') ?></td>
          <td class="table-td text-gray-400 text-xs"><?= date('d M Y H:i', strtotime($r['created_at'])) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
