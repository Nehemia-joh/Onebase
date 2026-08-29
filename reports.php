<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/currency_functions.php';
requireRole('super_admin', 'zone_manager', 'branch_manager');
// Role directories are the canonical URLs — forward direct hits there
if (!defined('ENTRY_OK') && isLoggedIn()) {
    $q = $_SERVER['QUERY_STRING'] ?? '';
    header('Location: ' . url(basename(__FILE__, '.php')) . ($q !== '' ? "?$q" : ''));
    exit;
}

$db        = getDB();
$pageTitle = 'Reports';
$u         = currentUser();

// ── Filters ───────────────────────────────────────────────────────────────────
$from = clean($_GET['from'] ?? '') ?: date('Y-m-01');
$to   = clean($_GET['to']   ?? '') ?: date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to   = date('Y-m-d');
if ($from > $to) { [$from, $to] = [$to, $from]; }

// Branch managers are locked to their own branch
if (hasRole('branch_manager') && $u['branch_id']) {
    $branchId = (int)$u['branch_id'];
} else {
    $branchId = cleanInt($_GET['branch_id'] ?? 0);
}

$branches = $db->query('SELECT id, name FROM branches WHERE is_active=1 ORDER BY name')->fetchAll();

$saleWhere  = 'DATE(s.created_at) BETWEEN ? AND ?';
$saleParams = [$from, $to];
if ($branchId) { $saleWhere .= ' AND s.branch_id = ?'; $saleParams[] = $branchId; }

// ── CSV Exports ───────────────────────────────────────────────────────────────
$export = clean($_GET['export'] ?? '');
if ($export) {
    $filename = "onesystem_{$export}_{$from}_to_{$to}.csv";
    header('Content-Type: text/csv; charset=UTF-8');
    header("Content-Disposition: attachment; filename=\"$filename\"");
    $out = fopen('php://output', 'w');
    fprintf($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel

    if ($export === 'sales') {
        fputcsv($out, ['Invoice','Date','Branch','Customer','Type','Cashier','Payment Method','Status','Subtotal','Discount','Tax','Total','Amount Paid','Balance']);
        $stmt = $db->prepare(
            "SELECT s.invoice_no, s.created_at, b.name branch, s.customer_name, s.customer_type,
                    u2.full_name cashier, s.payment_method, s.payment_status,
                    s.subtotal, s.discount, s.tax, s.total, s.amount_paid
             FROM sales s JOIN branches b ON b.id=s.branch_id LEFT JOIN users u2 ON u2.id=s.cashier_id
             WHERE $saleWhere ORDER BY s.created_at"
        );
        $stmt->execute($saleParams);
        while ($r = $stmt->fetch()) {
            fputcsv($out, [$r['invoice_no'],$r['created_at'],$r['branch'],$r['customer_name'],$r['customer_type'],
                $r['cashier'],$r['payment_method'],$r['payment_status'],$r['subtotal'],$r['discount'],$r['tax'],
                $r['total'],$r['amount_paid'],(float)$r['total']-(float)$r['amount_paid']]);
        }
    } elseif ($export === 'items') {
        fputcsv($out, ['Invoice','Date','Branch','Product','SKU','Price Type','Qty','Unit Price','Line Total','Unit Cost','Line Profit']);
        $stmt = $db->prepare(
            "SELECT s.invoice_no, s.created_at, b.name branch, si.product_name, si.sku, si.price_type,
                    si.quantity, si.unit_price, si.total_price, COALESCE(p.cost_price,0) cost_price
             FROM sale_items si
             JOIN sales s ON s.id=si.sale_id
             JOIN branches b ON b.id=s.branch_id
             LEFT JOIN products p ON p.id=si.product_id
             WHERE $saleWhere ORDER BY s.created_at"
        );
        $stmt->execute($saleParams);
        while ($r = $stmt->fetch()) {
            $profit = ((float)$r['unit_price'] - (float)$r['cost_price']) * (int)$r['quantity'];
            fputcsv($out, [$r['invoice_no'],$r['created_at'],$r['branch'],$r['product_name'],$r['sku'],$r['price_type'],
                $r['quantity'],$r['unit_price'],$r['total_price'],$r['cost_price'],$profit]);
        }
    } elseif ($export === 'stock') {
        fputcsv($out, ['Branch','Product','SKU','Barcode','Quantity','Unit Cost','Cost Value','Retail Price','Retail Value']);
        $sql = "SELECT b.name branch, p.name product, p.sku, p.barcode, st.quantity,
                       COALESCE(st.cost_price_override, p.cost_price) cost,
                       COALESCE(st.retail_price_override, p.retail_price) retail
                FROM stock st JOIN products p ON p.id=st.product_id JOIN branches b ON b.id=st.branch_id
                WHERE p.is_active=1" . ($branchId ? ' AND st.branch_id=' . $branchId : '') . '
                ORDER BY b.name, p.name';
        foreach ($db->query($sql)->fetchAll() as $r) {
            fputcsv($out, [$r['branch'],$r['product'],$r['sku'],$r['barcode'],$r['quantity'],$r['cost'],
                (float)$r['cost']*(int)$r['quantity'],$r['retail'],(float)$r['retail']*(int)$r['quantity']]);
        }
    }
    fclose($out);
    exit;
}

// ── KPIs ──────────────────────────────────────────────────────────────────────
$kpiStmt = $db->prepare(
    "SELECT COALESCE(SUM(s.total),0) revenue, COUNT(*) orders,
            COALESCE(SUM(s.total - s.amount_paid),0) outstanding,
            COALESCE(SUM(s.tax),0) tax
     FROM sales s WHERE $saleWhere"
);
$kpiStmt->execute($saleParams);
$kpi = $kpiStmt->fetch();

$profitStmt = $db->prepare(
    "SELECT COALESCE(SUM(si.quantity),0) units,
            COALESCE(SUM((si.unit_price - COALESCE(p.cost_price,0)) * si.quantity),0) profit
     FROM sale_items si JOIN sales s ON s.id=si.sale_id LEFT JOIN products p ON p.id=si.product_id
     WHERE $saleWhere"
);
$profitStmt->execute($saleParams);
$profitRow = $profitStmt->fetch();

$refundStmt = $db->prepare(
    'SELECT COALESCE(SUM(r.total_refund),0) FROM sale_returns r
     WHERE DATE(r.created_at) BETWEEN ? AND ?' . ($branchId ? ' AND r.branch_id=' . $branchId : '')
);
$refundStmt->execute([$from, $to]);
$refunds = (float)$refundStmt->fetchColumn();

// ── Breakdown tables ──────────────────────────────────────────────────────────
$byDay = $db->prepare(
    "SELECT DATE(s.created_at) day, COUNT(*) orders, SUM(s.total) revenue
     FROM sales s WHERE $saleWhere GROUP BY DATE(s.created_at) ORDER BY day DESC"
);
$byDay->execute($saleParams);
$byDay = $byDay->fetchAll();

$byMethod = $db->prepare(
    "SELECT s.payment_method, COUNT(*) orders, SUM(s.total) revenue
     FROM sales s WHERE $saleWhere GROUP BY s.payment_method ORDER BY revenue DESC"
);
$byMethod->execute($saleParams);
$byMethod = $byMethod->fetchAll();

$byCashier = $db->prepare(
    "SELECT COALESCE(u2.full_name,'—') cashier, COUNT(*) orders, SUM(s.total) revenue
     FROM sales s LEFT JOIN users u2 ON u2.id=s.cashier_id
     WHERE $saleWhere GROUP BY s.cashier_id ORDER BY revenue DESC"
);
$byCashier->execute($saleParams);
$byCashier = $byCashier->fetchAll();

$topProducts = $db->prepare(
    "SELECT si.product_name, si.sku, SUM(si.quantity) units, SUM(si.total_price) revenue,
            SUM((si.unit_price - COALESCE(p.cost_price,0)) * si.quantity) profit
     FROM sale_items si JOIN sales s ON s.id=si.sale_id LEFT JOIN products p ON p.id=si.product_id
     WHERE $saleWhere GROUP BY si.product_id, si.product_name, si.sku ORDER BY revenue DESC LIMIT 15"
);
$topProducts->execute($saleParams);
$topProducts = $topProducts->fetchAll();

$stockValue = $db->query(
    "SELECT b.name branch, COUNT(DISTINCT st.product_id) products, SUM(st.quantity) units,
            SUM(st.quantity * COALESCE(st.cost_price_override, p.cost_price)) cost_value,
            SUM(st.quantity * COALESCE(st.retail_price_override, p.retail_price)) retail_value
     FROM stock st JOIN products p ON p.id=st.product_id JOIN branches b ON b.id=st.branch_id
     WHERE p.is_active=1" . ($branchId ? ' AND st.branch_id=' . $branchId : '') . '
     GROUP BY st.branch_id, b.name ORDER BY b.name'
)->fetchAll();

$qsBase = http_build_query(['from' => $from, 'to' => $to, 'branch_id' => $branchId ?: null]);

include __DIR__ . '/includes/tailwind.php';
?>

<!-- ── Filters ── -->
<div class="page-card p-4 mb-6">
  <form method="GET" class="flex flex-wrap items-end gap-3">
    <div>
      <label class="form-label">From</label>
      <input type="date" name="from" value="<?= e($from) ?>" class="form-input">
    </div>
    <div>
      <label class="form-label">To</label>
      <input type="date" name="to" value="<?= e($to) ?>" class="form-input">
    </div>
    <?php if (!hasRole('branch_manager')): ?>
    <div>
      <label class="form-label">Branch</label>
      <select name="branch_id" class="form-input">
        <option value="">All Branches</option>
        <?php foreach ($branches as $b): ?>
        <option value="<?= $b['id'] ?>" <?= $branchId === (int)$b['id'] ? 'selected' : '' ?>><?= e($b['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>
    <button type="submit" class="btn-primary"><i class="fas fa-filter"></i> Apply</button>
    <div class="ml-auto flex gap-2">
      <a href="<?= url('reports') ?>?<?= e($qsBase) ?>&export=sales" class="btn-secondary"><i class="fas fa-file-csv"></i> Sales CSV</a>
      <a href="<?= url('reports') ?>?<?= e($qsBase) ?>&export=items" class="btn-secondary"><i class="fas fa-file-csv"></i> Items CSV</a>
      <a href="<?= url('reports') ?>?<?= e($qsBase) ?>&export=stock" class="btn-secondary"><i class="fas fa-file-csv"></i> Stock CSV</a>
    </div>
  </form>
</div>

<!-- ── KPI Cards ── -->
<div class="grid grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-4 mb-6">
  <?php
  $cards = [
    ['Revenue',       formatCurrency((float)$kpi['revenue']),      'fa-coins',        '#10b981,#059669'],
    ['Gross Profit',  formatCurrency((float)$profitRow['profit']), 'fa-chart-line',   '#3b82f6,#1d4ed8'],
    ['Transactions',  number_format((int)$kpi['orders']),          'fa-receipt',      '#8b5cf6,#6d28d9'],
    ['Units Sold',    number_format((int)$profitRow['units']),     'fa-boxes',        '#f59e0b,#d97706'],
    ['Refunds',       formatCurrency($refunds),                    'fa-undo',         '#ef4444,#dc2626'],
    ['Outstanding',   formatCurrency((float)$kpi['outstanding']),  'fa-hand-holding-usd', '#ec4899,#db2777'],
  ];
  foreach ($cards as [$label,$value,$icon,$grad]): ?>
  <div class="page-card p-4">
    <div class="w-9 h-9 rounded-xl flex items-center justify-center mb-2" style="background:linear-gradient(135deg,<?= $grad ?>)">
      <i class="fas <?= $icon ?> text-white text-sm"></i>
    </div>
    <p class="text-xs text-slate-400 uppercase font-semibold"><?= $label ?></p>
    <p class="text-lg font-bold text-slate-800 leading-tight mt-0.5"><?= $value ?></p>
  </div>
  <?php endforeach; ?>
</div>

<?php if (vatEnabled() && (float)$kpi['tax'] > 0): ?>
<div class="bg-indigo-50 border border-indigo-100 text-indigo-700 rounded-xl px-4 py-3 mb-6 text-sm">
  <i class="fas fa-info-circle mr-1"></i>
  VAT collected in this period: <strong><?= formatCurrency((float)$kpi['tax']) ?></strong>
</div>
<?php endif; ?>

<div class="grid xl:grid-cols-2 gap-5 mb-5">
  <!-- Sales by day -->
  <div class="page-card overflow-hidden">
    <div class="px-5 py-4 border-b border-slate-100"><h3 class="font-bold text-slate-800">Sales by Day</h3></div>
    <div class="overflow-x-auto max-h-96 overflow-y-auto">
      <table class="w-full text-sm">
        <thead class="bg-gray-50 sticky top-0"><tr>
          <th class="table-th">Date</th><th class="table-th text-right">Orders</th><th class="table-th text-right">Revenue</th>
        </tr></thead>
        <tbody class="divide-y divide-gray-50">
          <?php if (empty($byDay)): ?><tr><td colspan="3" class="py-8 text-center text-gray-400">No sales in this period</td></tr><?php endif; ?>
          <?php foreach ($byDay as $d): ?>
          <tr><td class="table-td"><?= date('D, d M Y', strtotime($d['day'])) ?></td>
              <td class="table-td text-right"><?= $d['orders'] ?></td>
              <td class="table-td text-right font-semibold"><?= formatCurrency((float)$d['revenue']) ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Payment methods + cashiers -->
  <div class="space-y-5">
    <div class="page-card overflow-hidden">
      <div class="px-5 py-4 border-b border-slate-100"><h3 class="font-bold text-slate-800">By Payment Method</h3></div>
      <table class="w-full text-sm">
        <tbody class="divide-y divide-gray-50">
          <?php if (empty($byMethod)): ?><tr><td class="py-6 text-center text-gray-400">No data</td></tr><?php endif; ?>
          <?php foreach ($byMethod as $m): ?>
          <tr>
            <td class="table-td capitalize"><?= e(str_replace('_',' ',$m['payment_method'])) ?></td>
            <td class="table-td text-right text-gray-500"><?= $m['orders'] ?> orders</td>
            <td class="table-td text-right font-semibold"><?= formatCurrency((float)$m['revenue']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="page-card overflow-hidden">
      <div class="px-5 py-4 border-b border-slate-100"><h3 class="font-bold text-slate-800">By Cashier</h3></div>
      <table class="w-full text-sm">
        <tbody class="divide-y divide-gray-50">
          <?php if (empty($byCashier)): ?><tr><td class="py-6 text-center text-gray-400">No data</td></tr><?php endif; ?>
          <?php foreach ($byCashier as $c): ?>
          <tr>
            <td class="table-td"><?= e($c['cashier']) ?></td>
            <td class="table-td text-right text-gray-500"><?= $c['orders'] ?> orders</td>
            <td class="table-td text-right font-semibold"><?= formatCurrency((float)$c['revenue']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Top products -->
<div class="page-card overflow-hidden mb-5">
  <div class="px-5 py-4 border-b border-slate-100">
    <h3 class="font-bold text-slate-800">Top Products</h3>
    <p class="text-xs text-slate-400">Profit uses each product's current cost price</p>
  </div>
  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead class="bg-gray-50"><tr>
        <th class="table-th">Product</th><th class="table-th text-right">Units</th>
        <th class="table-th text-right">Revenue</th><th class="table-th text-right">Gross Profit</th>
      </tr></thead>
      <tbody class="divide-y divide-gray-50">
        <?php if (empty($topProducts)): ?><tr><td colspan="4" class="py-8 text-center text-gray-400">No sales in this period</td></tr><?php endif; ?>
        <?php foreach ($topProducts as $p): ?>
        <tr>
          <td class="table-td"><p class="font-medium"><?= e($p['product_name']) ?></p><p class="text-xs text-gray-400"><?= e($p['sku']) ?></p></td>
          <td class="table-td text-right"><?= number_format((int)$p['units']) ?></td>
          <td class="table-td text-right font-semibold"><?= formatCurrency((float)$p['revenue']) ?></td>
          <td class="table-td text-right font-semibold <?= (float)$p['profit'] >= 0 ? 'text-emerald-600' : 'text-red-600' ?>"><?= formatCurrency((float)$p['profit']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Stock valuation -->
<div class="page-card overflow-hidden">
  <div class="px-5 py-4 border-b border-slate-100">
    <h3 class="font-bold text-slate-800">Stock Valuation (current)</h3>
    <p class="text-xs text-slate-400">Value of inventory on hand right now, not filtered by date</p>
  </div>
  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead class="bg-gray-50"><tr>
        <th class="table-th">Branch</th><th class="table-th text-right">Products</th><th class="table-th text-right">Units</th>
        <th class="table-th text-right">Cost Value</th><th class="table-th text-right">Retail Value</th><th class="table-th text-right">Potential Margin</th>
      </tr></thead>
      <tbody class="divide-y divide-gray-50">
        <?php if (empty($stockValue)): ?><tr><td colspan="6" class="py-8 text-center text-gray-400">No stock records</td></tr><?php endif; ?>
        <?php foreach ($stockValue as $sv): ?>
        <tr>
          <td class="table-td font-medium"><?= e($sv['branch']) ?></td>
          <td class="table-td text-right"><?= number_format((int)$sv['products']) ?></td>
          <td class="table-td text-right"><?= number_format((int)$sv['units']) ?></td>
          <td class="table-td text-right"><?= formatCurrency((float)$sv['cost_value']) ?></td>
          <td class="table-td text-right font-semibold"><?= formatCurrency((float)$sv['retail_value']) ?></td>
          <td class="table-td text-right text-emerald-600 font-semibold"><?= formatCurrency((float)$sv['retail_value'] - (float)$sv['cost_value']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
