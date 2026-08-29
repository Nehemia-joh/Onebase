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

$pageTitle = 'Dashboard';
$db        = getDB();
$u         = currentUser();

// ── Stats ─────────────────────────────────────────────────────────────────────
$stats = [];
if (isSuperAdmin()) {
    $stats['users']    = (int)$db->query('SELECT COUNT(*) FROM users WHERE is_active=1')->fetchColumn();
    $stats['zones']    = (int)$db->query('SELECT COUNT(*) FROM zones')->fetchColumn();
    $stats['branches'] = (int)$db->query('SELECT COUNT(*) FROM branches WHERE is_active=1')->fetchColumn();
}
$stats['products'] = (int)$db->query('SELECT COUNT(*) FROM products WHERE is_active=1')->fetchColumn();

$bid = $u['branch_id'] ? 'AND branch_id='.(int)$u['branch_id'] : '';
$stats['today_sales']  = (float)$db->query("SELECT COALESCE(SUM(total),0) FROM sales WHERE DATE(created_at)=CURDATE() $bid")->fetchColumn();
$stats['today_orders'] = (int)$db->query("SELECT COUNT(*) FROM sales WHERE DATE(created_at)=CURDATE() $bid")->fetchColumn();

$lowSql = "SELECT COUNT(DISTINCT s.product_id) FROM stock s JOIN products p ON p.id=s.product_id WHERE s.quantity<=p.min_stock_alert AND p.is_active=1" . ($u['branch_id'] ? ' AND s.branch_id='.(int)$u['branch_id'] : '');
$stats['low_stock']  = (int)$db->query($lowSql)->fetchColumn();
$stats['pending_po'] = (int)$db->query("SELECT COUNT(*) FROM purchase_orders WHERE status IN ('pending','approved','ordered')" . ($u['branch_id'] ? ' AND branch_id='.(int)$u['branch_id'] : ''))->fetchColumn();
$stats['suppliers']  = (int)$db->query("SELECT COUNT(*) FROM suppliers WHERE status='active'")->fetchColumn();
$stats['customers']  = (int)$db->query('SELECT COUNT(*) FROM customers')->fetchColumn();

// ── Month sales total ─────────────────────────────────────────────────────────
$stats['month_sales'] = (float)$db->query("SELECT COALESCE(SUM(total),0) FROM sales WHERE MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE()) $bid")->fetchColumn();

// ── Recent Sales ──────────────────────────────────────────────────────────────
$recentSales = $db->query(
    "SELECT s.invoice_no,s.total,s.payment_method,s.created_at,s.customer_type,
     COALESCE(s.customer_name,'Walk-in') as customer_name, b.name as branch_name
     FROM sales s JOIN branches b ON b.id=s.branch_id
     " . ($u['branch_id'] ? 'WHERE s.branch_id='.(int)$u['branch_id'] : '') . "
     ORDER BY s.created_at DESC LIMIT 8"
)->fetchAll();

// ── Recent Activity ───────────────────────────────────────────────────────────
$recentActivity = $db->query(
    'SELECT al.action,al.description,al.created_at,u.full_name
     FROM activity_logs al LEFT JOIN users u ON u.id=al.user_id
     ORDER BY al.created_at DESC LIMIT 8'
)->fetchAll();

// ── 7-day chart ───────────────────────────────────────────────────────────────
$chartRows = $db->query(
    "SELECT DATE(created_at) as day, SUM(total) as total FROM sales
     WHERE created_at>=DATE_SUB(CURDATE(),INTERVAL 6 DAY)
     " . ($u['branch_id'] ? 'AND branch_id='.(int)$u['branch_id'] : '') . "
     GROUP BY DATE(created_at) ORDER BY day"
)->fetchAll();
$chartData = [];
for ($i=6;$i>=0;$i--) { $chartData[date('Y-m-d',strtotime("-$i days"))]=0; }
foreach ($chartRows as $r) { $chartData[$r['day']]=(float)$r['total']; }

// ── Top products ──────────────────────────────────────────────────────────────
$topProducts = $db->query(
    "SELECT p.name, p.sku, SUM(si.quantity) as units_sold, SUM(si.total_price) as revenue
     FROM sale_items si JOIN products p ON p.id=si.product_id
     WHERE si.created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)
     GROUP BY si.product_id ORDER BY units_sold DESC LIMIT 5"
)->fetchAll();

include __DIR__ . '/includes/tailwind.php';
?>

<!-- ── Welcome Banner ──────────────────────────────────────────────────────── -->
<div class="rounded-2xl p-6 mb-6 text-white relative overflow-hidden"
     style="background:linear-gradient(135deg,#4f46e5 0%,#7c3aed 50%,#6d28d9 100%)">
  <div class="relative z-10">
    <p class="text-indigo-200 text-sm font-medium">Good <?= date('H')<12?'morning':(date('H')<17?'afternoon':'evening') ?> 👋</p>
    <h2 class="text-2xl font-bold mt-0.5"><?= e($u['name']) ?></h2>
    <p class="text-indigo-200 text-sm mt-1">Here's what's happening at <strong><?= e(getSetting('business_name', 'your business')) ?></strong> today.</p>
  </div>
  <!-- Decorative orbs -->
  <div class="absolute -right-8 -top-8 w-40 h-40 rounded-full" style="background:rgba(255,255,255,.07)"></div>
  <div class="absolute -right-4 top-6 w-24 h-24 rounded-full" style="background:rgba(255,255,255,.05)"></div>
  <div class="absolute right-24 -bottom-6 w-20 h-20 rounded-full" style="background:rgba(255,255,255,.06)"></div>
  <!-- Icon -->
  <div class="absolute right-6 top-1/2 -translate-y-1/2 opacity-20 text-7xl hidden md:block">
    <i class="fas fa-chart-line"></i>
  </div>
</div>

<!-- ── Stat Cards ──────────────────────────────────────────────────────────── -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">

  <!-- Today's Sales -->
  <div class="stat-card text-white col-span-2 sm:col-span-1"
       style="background:linear-gradient(135deg,#10b981,#059669)">
    <div class="card-orb-1"></div><div class="card-orb-2"></div>
    <div class="relative z-10">
      <div class="w-9 h-9 rounded-xl bg-white/20 flex items-center justify-center mb-3">
        <i class="fas fa-coins text-sm"></i>
      </div>
      <p class="text-emerald-100 text-xs font-semibold uppercase tracking-wide">Today's Sales</p>
      <p class="text-2xl font-bold mt-1 leading-tight"><?= formatCurrency($stats['today_sales']) ?></p>
      <p class="text-emerald-200 text-xs mt-1.5"><?= $stats['today_orders'] ?> transaction<?= $stats['today_orders']!==1?'s':'' ?></p>
    </div>
  </div>

  <!-- Month Sales -->
  <div class="stat-card text-white"
       style="background:linear-gradient(135deg,#3b82f6,#1d4ed8)">
    <div class="card-orb-1"></div><div class="card-orb-2"></div>
    <div class="relative z-10">
      <div class="w-9 h-9 rounded-xl bg-white/20 flex items-center justify-center mb-3">
        <i class="fas fa-calendar-check text-sm"></i>
      </div>
      <p class="text-blue-100 text-xs font-semibold uppercase tracking-wide">This Month</p>
      <p class="text-2xl font-bold mt-1 leading-tight"><?= formatCurrency($stats['month_sales']) ?></p>
      <p class="text-blue-200 text-xs mt-1.5"><?= date('F Y') ?></p>
    </div>
  </div>

  <!-- Products -->
  <div class="stat-card text-white"
       style="background:linear-gradient(135deg,#8b5cf6,#6d28d9)">
    <div class="card-orb-1"></div><div class="card-orb-2"></div>
    <div class="relative z-10">
      <div class="w-9 h-9 rounded-xl bg-white/20 flex items-center justify-center mb-3">
        <i class="fas fa-boxes text-sm"></i>
      </div>
      <p class="text-violet-100 text-xs font-semibold uppercase tracking-wide">Products</p>
      <p class="text-2xl font-bold mt-1 leading-tight"><?= number_format($stats['products']) ?></p>
      <p class="text-violet-200 text-xs mt-1.5">Active items</p>
    </div>
  </div>

  <!-- Low Stock -->
  <div class="stat-card text-white"
       style="background:<?= $stats['low_stock']>0?'linear-gradient(135deg,#ef4444,#dc2626)':'linear-gradient(135deg,#64748b,#475569)' ?>">
    <div class="card-orb-1"></div><div class="card-orb-2"></div>
    <div class="relative z-10">
      <div class="w-9 h-9 rounded-xl bg-white/20 flex items-center justify-center mb-3">
        <i class="fas fa-exclamation-triangle text-sm"></i>
      </div>
      <p class="text-red-100 text-xs font-semibold uppercase tracking-wide">Low Stock</p>
      <p class="text-2xl font-bold mt-1 leading-tight"><?= $stats['low_stock'] ?></p>
      <p class="text-red-200 text-xs mt-1.5"><?= $stats['low_stock']>0?'Need restocking':'All good!' ?></p>
    </div>
  </div>

</div>

<!-- ── Second row stats ────────────────────────────────────────────────────── -->
<div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">

  <div class="page-card p-4 flex items-center gap-3">
    <div class="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0"
         style="background:linear-gradient(135deg,#f59e0b,#d97706)">
      <i class="fas fa-clipboard-list text-white text-sm"></i>
    </div>
    <div>
      <p class="text-lg font-bold text-slate-800"><?= $stats['pending_po'] ?></p>
      <p class="text-xs text-slate-500">Pending POs</p>
    </div>
  </div>

  <div class="page-card p-4 flex items-center gap-3">
    <div class="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0"
         style="background:linear-gradient(135deg,#06b6d4,#0891b2)">
      <i class="fas fa-truck text-white text-sm"></i>
    </div>
    <div>
      <p class="text-lg font-bold text-slate-800"><?= $stats['suppliers'] ?></p>
      <p class="text-xs text-slate-500">Suppliers</p>
    </div>
  </div>

  <div class="page-card p-4 flex items-center gap-3">
    <div class="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0"
         style="background:linear-gradient(135deg,#ec4899,#db2777)">
      <i class="fas fa-user-friends text-white text-sm"></i>
    </div>
    <div>
      <p class="text-lg font-bold text-slate-800"><?= number_format($stats['customers']) ?></p>
      <p class="text-xs text-slate-500">Customers</p>
    </div>
  </div>

  <?php if (isSuperAdmin()): ?>
  <div class="page-card p-4 flex items-center gap-3">
    <div class="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0"
         style="background:linear-gradient(135deg,#14b8a6,#0f766e)">
      <i class="fas fa-store text-white text-sm"></i>
    </div>
    <div>
      <p class="text-lg font-bold text-slate-800"><?= $stats['branches'] ?></p>
      <p class="text-xs text-slate-500">Branches / <?= $stats['zones'] ?> Zone<?= $stats['zones']!==1?'s':'' ?></p>
    </div>
  </div>
  <?php else: ?>
  <div class="page-card p-4 flex items-center gap-3">
    <div class="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0"
         style="background:linear-gradient(135deg,#6366f1,#4f46e5)">
      <i class="fas fa-users text-white text-sm"></i>
    </div>
    <div>
      <p class="text-lg font-bold text-slate-800"><?= $stats['users'] ?? '—' ?></p>
      <p class="text-xs text-slate-500">Users</p>
    </div>
  </div>
  <?php endif; ?>

</div>

<!-- ── Chart + Quick Actions ───────────────────────────────────────────────── -->
<div class="grid xl:grid-cols-3 gap-5 mb-5">

  <!-- 7-day bar chart -->
  <div class="xl:col-span-2 page-card p-5">
    <div class="flex items-center justify-between mb-5">
      <div>
        <h3 class="font-bold text-slate-800">Sales Overview</h3>
        <p class="text-xs text-slate-400 mt-0.5">Last 7 days revenue</p>
      </div>
      <span class="text-xs bg-indigo-50 text-indigo-600 font-semibold px-3 py-1.5 rounded-full"><?= currencySymbol() ?></span>
    </div>
    <div class="flex items-end gap-2 h-44">
      <?php
      $maxVal = max(array_values($chartData)) ?: 1;
      $days   = array_keys($chartData);
      $colors = ['#6366f1','#8b5cf6','#a78bfa','#6366f1','#818cf8','#4f46e5','#7c3aed'];
      $ci     = 0;
      foreach ($chartData as $day => $total):
        $pct    = max(4, (int)(($total/$maxVal)*100));
        $isToday = $day === date('Y-m-d');
      ?>
      <div class="flex-1 flex flex-col items-center gap-1.5 group">
        <?php if ($total > 0): ?>
        <span class="text-[.6rem] font-semibold text-slate-400 opacity-0 group-hover:opacity-100 transition-opacity whitespace-nowrap">
          <?= formatCurrency($total) ?>
        </span>
        <?php else: ?><span class="text-[.6rem]">&nbsp;</span><?php endif; ?>
        <div class="w-full rounded-t-lg transition-all duration-300 hover:opacity-80 cursor-default relative"
             style="height:<?= $pct ?>%;background:<?= $isToday?'linear-gradient(180deg,#f59e0b,#d97706)':'linear-gradient(180deg,'.$colors[$ci].','.($isToday?'#d97706':'#4338ca').')' ?>">
          <?php if ($isToday): ?>
          <div class="absolute -top-1 left-1/2 -translate-x-1/2 w-2 h-2 bg-amber-400 rounded-full"></div>
          <?php endif; ?>
        </div>
        <span class="text-[.65rem] font-medium <?= $isToday?'text-amber-500':'text-slate-400' ?>">
          <?= date('D', strtotime($day)) ?>
        </span>
      </div>
      <?php $ci=($ci+1)%count($colors); endforeach; ?>
    </div>
    <div class="flex items-center gap-3 mt-3 pt-3 border-t border-slate-100 text-xs text-slate-400">
      <div class="flex items-center gap-1.5"><div class="w-3 h-3 rounded-sm" style="background:#6366f1"></div>Regular days</div>
      <div class="flex items-center gap-1.5"><div class="w-3 h-3 rounded-sm bg-amber-400"></div>Today</div>
    </div>
  </div>

  <!-- Quick Actions -->
  <div class="page-card p-5">
    <h3 class="font-bold text-slate-800 mb-4">Quick Actions</h3>
    <div class="space-y-2.5">
      <a href="<?= url('sales') ?>?action=new"
         class="flex items-center gap-3 p-3 rounded-xl hover:shadow-md transition-all group"
         style="background:linear-gradient(135deg,#ecfdf5,#d1fae5)">
        <div class="w-9 h-9 rounded-lg flex items-center justify-center" style="background:linear-gradient(135deg,#10b981,#059669)">
          <i class="fas fa-plus text-white text-xs"></i>
        </div>
        <div>
          <p class="text-sm font-semibold text-emerald-800">New Sale</p>
          <p class="text-xs text-emerald-600">Create invoice</p>
        </div>
        <i class="fas fa-arrow-right ml-auto text-emerald-400 text-xs opacity-0 group-hover:opacity-100 transition-opacity"></i>
      </a>

      <?php if (canManageStock()): ?>
      <a href="<?= url('stock') ?>?action=new"
         class="flex items-center gap-3 p-3 rounded-xl hover:shadow-md transition-all group"
         style="background:linear-gradient(135deg,#eef2ff,#e0e7ff)">
        <div class="w-9 h-9 rounded-lg flex items-center justify-center" style="background:linear-gradient(135deg,#6366f1,#4f46e5)">
          <i class="fas fa-box-open text-white text-xs"></i>
        </div>
        <div>
          <p class="text-sm font-semibold text-indigo-800">Add Product</p>
          <p class="text-xs text-indigo-600">New inventory item</p>
        </div>
        <i class="fas fa-arrow-right ml-auto text-indigo-400 text-xs opacity-0 group-hover:opacity-100 transition-opacity"></i>
      </a>

      <a href="<?= url('purchase_orders') ?>?action=new"
         class="flex items-center gap-3 p-3 rounded-xl hover:shadow-md transition-all group"
         style="background:linear-gradient(135deg,#fffbeb,#fef3c7)">
        <div class="w-9 h-9 rounded-lg flex items-center justify-center" style="background:linear-gradient(135deg,#f59e0b,#d97706)">
          <i class="fas fa-file-alt text-white text-xs"></i>
        </div>
        <div>
          <p class="text-sm font-semibold text-amber-800">Purchase Order</p>
          <p class="text-xs text-amber-600">Order from supplier</p>
        </div>
        <i class="fas fa-arrow-right ml-auto text-amber-400 text-xs opacity-0 group-hover:opacity-100 transition-opacity"></i>
      </a>
      <?php endif; ?>

      <?php if ($stats['low_stock'] > 0): ?>
      <a href="<?= url('stock') ?>?filter=low"
         class="flex items-center gap-3 p-3 rounded-xl hover:shadow-md transition-all group"
         style="background:linear-gradient(135deg,#fef2f2,#fee2e2)">
        <div class="w-9 h-9 rounded-lg flex items-center justify-center" style="background:linear-gradient(135deg,#ef4444,#dc2626)">
          <i class="fas fa-exclamation text-white text-xs"></i>
        </div>
        <div>
          <p class="text-sm font-semibold text-red-800">Low Stock Alert</p>
          <p class="text-xs text-red-600"><?= $stats['low_stock'] ?> item<?= $stats['low_stock']!==1?'s':''?> need restocking</p>
        </div>
        <i class="fas fa-arrow-right ml-auto text-red-400 text-xs opacity-0 group-hover:opacity-100 transition-opacity"></i>
      </a>
      <?php endif; ?>
    </div>
  </div>

</div>

<!-- ── Recent Sales + Top Products ────────────────────────────────────────── -->
<div class="grid xl:grid-cols-5 gap-5">

  <!-- Recent Sales -->
  <div class="xl:col-span-3 page-card overflow-hidden">
    <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
      <div>
        <h3 class="font-bold text-slate-800">Recent Sales</h3>
        <p class="text-xs text-slate-400 mt-0.5">Latest transactions</p>
      </div>
      <a href="<?= url('sales') ?>" class="text-xs font-semibold text-indigo-600 hover:text-indigo-700 flex items-center gap-1">
        View all <i class="fas fa-arrow-right text-[.6rem]"></i>
      </a>
    </div>
    <?php if (empty($recentSales)): ?>
    <div class="py-14 text-center text-slate-400">
      <div class="w-16 h-16 rounded-2xl bg-slate-100 flex items-center justify-center mx-auto mb-3">
        <i class="fas fa-receipt text-2xl text-slate-300"></i>
      </div>
      <p class="text-sm font-medium">No sales yet</p>
      <a href="<?= url('sales') ?>?action=new" class="text-xs text-indigo-500 hover:underline mt-1 inline-block">Create first sale</a>
    </div>
    <?php else: ?>
    <div class="divide-y divide-slate-50">
      <?php
      $pmIcons = ['cash'=>'fa-money-bill-wave text-emerald-500','card'=>'fa-credit-card text-blue-500','mobile_money'=>'fa-mobile-alt text-purple-500','credit'=>'fa-handshake text-orange-500'];
      foreach ($recentSales as $s):
      ?>
      <div class="px-5 py-3.5 flex items-center gap-3 hover:bg-slate-50 transition-colors">
        <div class="w-9 h-9 rounded-xl bg-indigo-50 flex items-center justify-center flex-shrink-0">
          <i class="fas <?= $pmIcons[$s['payment_method']] ?? 'fa-receipt text-slate-400' ?>"></i>
        </div>
        <div class="flex-1 min-w-0">
          <div class="flex items-center gap-2">
            <p class="text-sm font-semibold text-slate-800"><?= e($s['customer_name']) ?></p>
            <span class="text-[.6rem] px-1.5 py-0.5 rounded-full font-semibold capitalize
              <?= $s['customer_type']==='wholesale'?'bg-blue-100 text-blue-600':'bg-slate-100 text-slate-500' ?>">
              <?= e($s['customer_type']) ?>
            </span>
          </div>
          <p class="text-xs text-slate-400"><?= e($s['invoice_no']) ?> &bull; <?= e($s['branch_name']) ?></p>
        </div>
        <div class="text-right flex-shrink-0">
          <p class="text-sm font-bold text-slate-800"><?= formatCurrency((float)$s['total']) ?></p>
          <p class="text-[.65rem] text-slate-400"><?= date('d M, H:i', strtotime($s['created_at'])) ?></p>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- Top Products + Activity -->
  <div class="xl:col-span-2 space-y-5">

    <!-- Top Products -->
    <div class="page-card overflow-hidden">
      <div class="px-5 py-4 border-b border-slate-100">
        <h3 class="font-bold text-slate-800">Top Products</h3>
        <p class="text-xs text-slate-400 mt-0.5">Best sellers this month</p>
      </div>
      <?php if (empty($topProducts)): ?>
      <div class="py-8 text-center text-slate-400 text-sm">No sales data yet</div>
      <?php else: ?>
      <div class="divide-y divide-slate-50">
        <?php
        $rankColors = ['from-amber-400 to-orange-500','from-slate-400 to-slate-500','from-amber-600 to-yellow-700','from-indigo-400 to-indigo-500','from-purple-400 to-purple-500'];
        foreach ($topProducts as $i => $p):
        ?>
        <div class="px-5 py-3 flex items-center gap-3 hover:bg-slate-50 transition-colors">
          <div class="w-7 h-7 rounded-lg flex items-center justify-center text-white text-xs font-bold flex-shrink-0
               bg-gradient-to-br <?= $rankColors[$i] ?? 'from-slate-400 to-slate-500' ?>">
            <?= $i+1 ?>
          </div>
          <div class="flex-1 min-w-0">
            <p class="text-sm font-semibold text-slate-700 truncate"><?= e($p['name']) ?></p>
            <p class="text-xs text-slate-400"><?= number_format($p['units_sold']) ?> units sold</p>
          </div>
          <p class="text-sm font-bold text-indigo-600 flex-shrink-0"><?= formatCurrency((float)$p['revenue']) ?></p>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- Recent Activity -->
    <div class="page-card overflow-hidden">
      <div class="px-5 py-4 border-b border-slate-100">
        <h3 class="font-bold text-slate-800">Activity</h3>
      </div>
      <?php if (empty($recentActivity)): ?>
      <div class="py-8 text-center text-slate-400 text-sm">No activity yet</div>
      <?php else: ?>
      <div class="divide-y divide-slate-50">
        <?php foreach ($recentActivity as $a): ?>
        <div class="px-5 py-3 flex items-start gap-3 hover:bg-slate-50 transition-colors">
          <div class="w-7 h-7 rounded-full bg-indigo-100 flex items-center justify-center flex-shrink-0 mt-0.5">
            <i class="fas fa-bolt text-indigo-500 text-[.6rem]"></i>
          </div>
          <div class="flex-1 min-w-0">
            <p class="text-xs font-medium text-slate-700 truncate"><?= e($a['description'] ?: $a['action']) ?></p>
            <p class="text-[.65rem] text-slate-400"><?= e($a['full_name'] ?? 'System') ?> &bull; <?= date('d M H:i', strtotime($a['created_at'])) ?></p>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
