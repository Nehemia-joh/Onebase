<?php requireLogin(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="theme-color" content="#1e3c72">
<title><?= e($pageTitle ?? 'Dashboard') ?> &mdash; <?= e(getSetting('business_name', 'OneSystem BMS')) ?></title>
<script src="https://cdn.tailwindcss.com"></script>
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.1/dist/cdn.min.js"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
:root {
  --primary-color:   #007bff;
  --secondary-color: #6c757d;
  --success-color:   #28a745;
  --danger-color:    #dc3545;
  --warning-color:   #ffc107;
  --info-color:      #17a2b8;
  --light-color:     #f8f9fa;
  --dark-color:      #343a40;

  --text-primary:  #212529;
  --bg-primary:    #f8f9fa;
  --bg-secondary:  #e9ecef;
  --navbar-bg:      linear-gradient(135deg, #1e3c72 0%, #2a5298 50%, #7e8ba3 100%);
  --dropdown-bg:    linear-gradient(135deg, #2a5298 0%, #7e8ba3 100%);
  --card-bg:       #ffffff;
  --hover-bg:      #f1f3f5;
  --border-color:  #dee2e6;

  --border-radius: 0.375rem;
  --box-shadow:    0 0.125rem 0.25rem rgba(0,0,0,0.075);
  --transition:    all 0.15s ease-in-out;
}

[x-cloak]{display:none!important}

body {
  font-family:'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
  font-size:14px;
  color:var(--text-primary);
  background:var(--bg-primary);
}

/* Sidebar (this app's "navbar" — brand surface) */
.sidebar { background:var(--navbar-bg); }
.nav-group-label { font-size:.65rem; font-weight:700; letter-spacing:.1em; color:rgba(255,255,255,.55); text-transform:uppercase; padding:.5rem .75rem .25rem; display:block; }
.nav-item {
  display:flex; align-items:center; gap:.75rem;
  padding:.6rem .75rem; border-radius:var(--border-radius);
  font-size:.875rem; font-weight:500; color:rgba(255,255,255,.85);
  transition:var(--transition); cursor:pointer; text-decoration:none;
}
.nav-item:hover { background:rgba(255,255,255,.15); color:#fff; }
.nav-item.active { background:rgba(255,255,255,.2); color:#fff; font-weight:600; box-shadow:inset 0 0 0 1px rgba(255,255,255,.25); }
.nav-item.active .icon-badge { background:rgba(255,255,255,.25)!important; color:#fff!important; }
.icon-badge {
  width:2rem; height:2rem; border-radius:var(--border-radius); flex-shrink:0;
  display:flex; align-items:center; justify-content:center; font-size:.75rem; transition:var(--transition);
}

/* Dropdown menus (notifications / user menu) — same blue gradient as the sidebar */
.dropdown-panel { background:var(--dropdown-bg); color:#fff; }
.dropdown-panel .dropdown-item { color:#fff; }
.dropdown-panel .dropdown-item:hover { background:rgba(255,255,255,.15); }

/* Cards */
.stat-card { border-radius:1rem; padding:1.25rem; position:relative; overflow:hidden; }
.stat-card .card-orb-1 { position:absolute; width:6rem; height:6rem; border-radius:50%; right:-1.25rem; bottom:-1.25rem; background:rgba(255,255,255,.12); }
.stat-card .card-orb-2 { position:absolute; width:9rem; height:9rem; border-radius:50%; right:-2.5rem; bottom:-2.5rem; background:rgba(255,255,255,.06); }

.dashboard-card {
  background:var(--card-bg); border-radius:var(--border-radius); box-shadow:var(--box-shadow);
  border:1px solid var(--border-color); border-left:4px solid var(--secondary-color);
  padding:1.25rem; transition:var(--transition);
}
.dashboard-card:hover { box-shadow:0 0.5rem 1rem rgba(0,0,0,.1); }
.dashboard-card.success { border-left-color:var(--success-color); }
.dashboard-card.warning { border-left-color:var(--warning-color); }
.dashboard-card.danger  { border-left-color:var(--danger-color); }
.dashboard-card.info    { border-left-color:var(--info-color); }

/* Form helpers */
.form-input { width:100%; border:1.5px solid var(--border-color); border-radius:var(--border-radius); padding:.5rem .75rem; font-size:.875rem; outline:none; transition:var(--transition); background:#fff; }
.form-input:focus { border-color:var(--primary-color); box-shadow:0 0 0 3px rgba(0,123,255,.15); }
.form-label { display:block; font-size:.8125rem; font-weight:600; color:var(--text-primary); margin-bottom:.35rem; }
.table-th { padding:.75rem 1rem; text-align:left; font-size:.7rem; font-weight:700; color:var(--secondary-color); text-transform:uppercase; letter-spacing:.06em; background:var(--bg-secondary); }
.table-td { padding:.75rem 1rem; font-size:.875rem; color:var(--text-primary); }
.badge { display:inline-flex; align-items:center; padding:.2rem .65rem; border-radius:9999px; font-size:.7rem; font-weight:600; }

/* Status dots */
.status-dot { display:inline-block; width:.5rem; height:.5rem; border-radius:50%; }
.status-dot.online  { background:var(--success-color); }
.status-dot.offline { background:var(--danger-color); }
.status-dot.warning  { background:var(--warning-color); }

/* Buttons */
.btn-primary { display:inline-flex; align-items:center; gap:.4rem; background:var(--primary-color); color:#fff; padding:.5rem 1rem; border-radius:var(--border-radius); font-size:.875rem; font-weight:500; border:none; cursor:pointer; transition:var(--transition); box-shadow:var(--box-shadow); }
.btn-primary:hover { filter:brightness(1.1); box-shadow:0 4px 12px rgba(0,123,255,.35); transform:translateY(-1px); }
.btn-secondary { display:inline-flex; align-items:center; gap:.4rem; background:#fff; color:var(--secondary-color); border:1.5px solid var(--border-color); padding:.5rem 1rem; border-radius:var(--border-radius); font-size:.875rem; font-weight:500; cursor:pointer; transition:var(--transition); }
.btn-secondary:hover { background:var(--hover-bg); border-color:#c8ccd0; transform:translateY(-1px); }
.btn-danger { display:inline-flex; align-items:center; gap:.4rem; background:var(--danger-color); color:#fff; padding:.5rem 1rem; border-radius:var(--border-radius); font-size:.875rem; font-weight:500; border:none; cursor:pointer; transition:var(--transition); box-shadow:var(--box-shadow); }
.btn-danger:hover { filter:brightness(1.1); transform:translateY(-1px); }
.btn-success { display:inline-flex; align-items:center; gap:.4rem; background:var(--success-color); color:#fff; padding:.5rem 1rem; border-radius:var(--border-radius); font-size:.875rem; font-weight:500; border:none; cursor:pointer; transition:var(--transition); box-shadow:var(--box-shadow); }
.btn-success:hover { filter:brightness(1.1); transform:translateY(-1px); }

/* Page card */
.page-card { background:var(--card-bg); border-radius:var(--border-radius); box-shadow:var(--box-shadow); border:1px solid var(--border-color); transition:var(--transition); }
.page-card:hover { box-shadow:0 0.5rem 1rem rgba(0,0,0,.1); }

/* Scrollbar — thin, gray thumb, rounded */
::-webkit-scrollbar { width:8px; height:8px; }
::-webkit-scrollbar-track { background:transparent; }
::-webkit-scrollbar-thumb { background:#adb5bd; border-radius:9999px; }
.sidebar ::-webkit-scrollbar-thumb { background:rgba(255,255,255,.3); }

/* Utility animations */
@keyframes fadeIn  { from{opacity:0} to{opacity:1} }
@keyframes slideUp { from{opacity:0; transform:translateY(10px)} to{opacity:1; transform:translateY(0)} }
@keyframes spin    { to{transform:rotate(360deg)} }
.fade-in  { animation:fadeIn .3s ease-in-out; }
.slide-up { animation:slideUp .3s ease-in-out; }
.spinner  { width:2rem; height:2rem; border:3px solid rgba(0,123,255,.2); border-top-color:var(--primary-color); border-radius:50%; animation:spin .7s linear infinite; display:inline-block; }

/* Type-to-search dropdowns */
.searchable-list { position:absolute; z-index:70; left:0; right:0; top:100%; margin-top:.25rem;
  background:#fff; border:1px solid var(--border-color); border-radius:var(--border-radius);
  box-shadow:0 10px 30px rgba(0,0,0,.15); max-height:15rem; overflow-y:auto; }
.searchable-item { padding:.5rem .75rem; font-size:.875rem; cursor:pointer; }
.searchable-item:hover, .searchable-item.active { background:var(--hover-bg); }
.searchable-empty { padding:.75rem; font-size:.8rem; color:var(--secondary-color); text-align:center; }

/* ── Responsive / touch ─────────────────────────────────────────────── */
@media (hover:none) and (pointer:coarse) {
  .nav-item, .btn-primary, .btn-secondary, .btn-danger, .btn-success { min-height:44px; }
}
@media (max-width:768px) {
  input, select, textarea, .form-input { font-size:16px; } /* prevents iOS auto-zoom on focus */
}
@supports (-webkit-touch-callout:none) {
  .flex.h-screen { min-height:-webkit-fill-available; }
}

/* ── Mobile-only dark mode (matches system preference on small screens) ── */
@media (max-width:768px) and (prefers-color-scheme:dark) {
  .page-card, main .bg-white { background:#2d3748 !important; border-color:#4a5568 !important; color:#e2e8f0; }
  .table-th { background:#1f2937; color:#cbd5e1; }
  .table-td { color:#e2e8f0; }
}

/* ── Print ──────────────────────────────────────────────────────────── */
@media print {
  .sidebar, header, .no-print { display:none !important; }
  body { background:#fff; font-size:12px; }
  table, th, td { border:1px solid #000; border-collapse:collapse; }
  .page-card, .shadow-sm, .shadow-xl { box-shadow:none !important; border:1px solid #000; }
}
</style>
</head>
<body class="antialiased" x-data="{ sidebarOpen: true }">

<?php $u = currentUser(); $cp = basename($_SERVER['PHP_SELF']); ?>

<div class="flex h-screen overflow-hidden">

<!-- ══════════════════════════════════════
     SIDEBAR
════════════════════════════════════════ -->
<aside class="sidebar flex flex-col flex-shrink-0 transition-all duration-300 z-40"
       :class="sidebarOpen ? 'w-64' : 'w-[4.5rem]'">

  <!-- Logo -->
  <div class="flex items-center gap-3 px-4 py-5 border-b border-white/[.07]">
    <?php if ($bizLogo = businessLogoUrl()): ?>
    <img src="<?= e($bizLogo) ?>" alt="Logo" class="flex-shrink-0 w-10 h-10 rounded-xl object-cover bg-white">
    <?php else: ?>
    <div class="flex-shrink-0 w-10 h-10 rounded-xl flex items-center justify-center"
         style="background:linear-gradient(135deg,#007bff,#2a5298)">
      <i class="fas fa-layer-group text-white text-sm"></i>
    </div>
    <?php endif; ?>
    <div x-show="sidebarOpen" x-cloak>
      <div class="font-bold text-white text-base leading-tight"><?= e(getSetting('business_name', 'OneSystem')) ?></div>
      <div class="text-[.65rem] text-blue-500 font-medium">Business Management</div>
    </div>
  </div>

  <!-- Navigation -->
  <nav class="flex-1 overflow-y-auto py-3 px-2.5 space-y-0.5">

    <!-- OVERVIEW -->
    <span class="nav-group-label" x-show="sidebarOpen" x-cloak>Overview</span>
    <a href="<?= url('dashboard') ?>" class="nav-item <?= $cp==='dashboard.php'?'active':'' ?>">
      <span class="icon-badge bg-amber-400/15 text-amber-400"><i class="fas fa-th-large"></i></span>
      <span x-show="sidebarOpen" x-cloak class="truncate">Dashboard</span>
    </a>

    <?php if (canViewReports()): ?>
    <a href="<?= url('reports') ?>" class="nav-item <?= $cp==='reports.php'?'active':'' ?>">
      <span class="icon-badge bg-rose-400/15 text-rose-400"><i class="fas fa-chart-pie"></i></span>
      <span x-show="sidebarOpen" x-cloak class="truncate">Reports</span>
    </a>
    <?php endif; ?>

    <!-- MANAGEMENT -->
    <?php if (isSuperAdmin() || canManageBranches()): ?>
    <div class="pt-1" x-show="sidebarOpen" x-cloak></div>
    <span class="nav-group-label" x-show="sidebarOpen" x-cloak>Management</span>
    <?php endif; ?>

    <?php if (canManageUsers()): ?>
    <a href="<?= url('users') ?>" class="nav-item <?= $cp==='users.php'?'active':'' ?>">
      <span class="icon-badge bg-violet-400/15 text-violet-400"><i class="fas fa-users"></i></span>
      <span x-show="sidebarOpen" x-cloak class="truncate">Users</span>
    </a>
    <?php endif; ?>

    <?php if (isSuperAdmin()): ?>
    <a href="<?= url('zones') ?>" class="nav-item <?= $cp==='zones.php'?'active':'' ?>">
      <span class="icon-badge bg-teal-400/15 text-teal-400"><i class="fas fa-globe-africa"></i></span>
      <span x-show="sidebarOpen" x-cloak class="truncate">Zones</span>
    </a>
    <?php endif; ?>

    <?php if (canManageBranches()): ?>
    <a href="<?= url('branches') ?>" class="nav-item <?= $cp==='branches.php'?'active':'' ?>">
      <span class="icon-badge bg-sky-400/15 text-sky-400"><i class="fas fa-store"></i></span>
      <span x-show="sidebarOpen" x-cloak class="truncate">Branches</span>
    </a>
    <?php endif; ?>

    <!-- INVENTORY & SALES -->
    <div class="pt-1" x-show="sidebarOpen" x-cloak></div>
    <span class="nav-group-label" x-show="sidebarOpen" x-cloak>Inventory &amp; Sales</span>

    <?php if (canManageStock()): ?>
    <a href="<?= url('stock') ?>" class="nav-item <?= $cp==='stock.php'?'active':'' ?>">
      <span class="icon-badge bg-emerald-400/15 text-emerald-400"><i class="fas fa-boxes"></i></span>
      <span x-show="sidebarOpen" x-cloak class="truncate">Stock</span>
    </a>
    <?php endif; ?>

    <a href="<?= url('sales') ?>" class="nav-item <?= $cp==='sales.php'?'active':'' ?>">
      <span class="icon-badge bg-orange-400/15 text-orange-400"><i class="fas fa-cash-register"></i></span>
      <span x-show="sidebarOpen" x-cloak class="truncate">Sales</span>
    </a>

    <a href="<?= url('returns') ?>" class="nav-item <?= $cp==='returns.php'?'active':'' ?>">
      <span class="icon-badge bg-red-400/15 text-red-400"><i class="fas fa-undo"></i></span>
      <span x-show="sidebarOpen" x-cloak class="truncate">Returns</span>
    </a>

    <a href="<?= url('customers') ?>" class="nav-item <?= $cp==='customers.php'?'active':'' ?>">
      <span class="icon-badge bg-pink-400/15 text-pink-400"><i class="fas fa-user-friends"></i></span>
      <span x-show="sidebarOpen" x-cloak class="truncate">Customers</span>
    </a>

    <!-- PROCUREMENT -->
    <?php if (hasRole('super_admin','zone_manager','branch_manager','stock_controller')): ?>
    <div class="pt-1" x-show="sidebarOpen" x-cloak></div>
    <span class="nav-group-label" x-show="sidebarOpen" x-cloak>Procurement</span>

    <a href="<?= url('suppliers') ?>" class="nav-item <?= $cp==='suppliers.php'?'active':'' ?>">
      <span class="icon-badge bg-cyan-400/15 text-cyan-400"><i class="fas fa-truck"></i></span>
      <span x-show="sidebarOpen" x-cloak class="truncate">Suppliers</span>
    </a>

    <a href="<?= url('purchase_orders') ?>" class="nav-item <?= $cp==='purchase_orders.php'?'active':'' ?>">
      <span class="icon-badge bg-purple-400/15 text-purple-400"><i class="fas fa-file-invoice"></i></span>
      <span x-show="sidebarOpen" x-cloak class="truncate">Purchase Orders</span>
    </a>
    <?php endif; ?>

    <!-- SETTINGS -->
    <?php if (isSuperAdmin()): ?>
    <div class="pt-1" x-show="sidebarOpen" x-cloak></div>
    <span class="nav-group-label" x-show="sidebarOpen" x-cloak>Settings</span>
    <a href="<?= url('settings') ?>" class="nav-item <?= $cp==='settings.php'?'active':'' ?>">
      <span class="icon-badge bg-lime-400/15 text-lime-400"><i class="fas fa-sliders-h"></i></span>
      <span x-show="sidebarOpen" x-cloak class="truncate">Business &amp; VAT</span>
    </a>
    <a href="<?= url('currency_settings') ?>" class="nav-item <?= $cp==='currency_settings.php'?'active':'' ?>">
      <span class="icon-badge bg-yellow-400/15 text-yellow-400"><i class="fas fa-coins"></i></span>
      <span x-show="sidebarOpen" x-cloak class="truncate">Currency</span>
    </a>
    <a href="<?= url('backup') ?>" class="nav-item <?= $cp==='backup.php'?'active':'' ?>">
      <span class="icon-badge bg-cyan-400/15 text-cyan-400"><i class="fas fa-cloud-upload-alt"></i></span>
      <span x-show="sidebarOpen" x-cloak class="truncate">Backup &amp; Export</span>
    </a>
    <?php endif; ?>

  </nav>

  <!-- User profile footer -->
  <div class="px-2.5 py-3 border-t border-white/[.07]">
    <div class="flex items-center gap-3 px-2 py-2 rounded-xl hover:bg-white/5 transition-colors">
      <div class="flex-shrink-0 w-9 h-9 rounded-xl flex items-center justify-center font-bold text-sm text-white"
           style="background:linear-gradient(135deg,#007bff,#2a5298)">
        <?= strtoupper(substr($u['name'],0,2)) ?>
      </div>
      <div x-show="sidebarOpen" x-cloak class="flex-1 min-w-0">
        <p class="text-sm font-semibold text-white truncate"><?= e($u['name']) ?></p>
        <p class="text-[.65rem] text-slate-400 capitalize truncate"><?= e(str_replace('_',' ',$u['role'])) ?></p>
      </div>
      <a href="/logout" x-show="sidebarOpen" x-cloak
         class="flex-shrink-0 p-1.5 rounded-lg text-slate-500 hover:text-red-400 hover:bg-red-500/10 transition-colors"
         title="Sign out" onclick="return confirm('Sign out?')">
        <i class="fas fa-sign-out-alt text-xs"></i>
      </a>
    </div>

    <!-- Collapse toggle -->
    <button @click="sidebarOpen = !sidebarOpen"
      class="mt-2 w-full flex items-center justify-center gap-2 py-2 rounded-xl text-slate-500 hover:text-slate-300 hover:bg-white/5 text-xs transition-colors">
      <i class="fas transition-transform duration-300" :class="sidebarOpen ? 'fa-chevron-left' : 'fa-chevron-right'"></i>
      <span x-show="sidebarOpen" x-cloak>Collapse</span>
    </button>
  </div>
</aside>

<!-- ══════════════════════════════════════
     MAIN AREA
════════════════════════════════════════ -->
<div class="flex-1 flex flex-col overflow-hidden">

  <!-- Top bar -->
  <header class="bg-white border-b border-slate-100 px-6 py-3 flex items-center justify-between flex-shrink-0 z-30"
          style="box-shadow:0 1px 3px rgba(0,0,0,.06)">
    <div class="flex items-center gap-4">
      <!-- Breadcrumb / title -->
      <div class="flex items-center gap-2 text-sm">
        <span class="text-slate-400"><?= e(getSetting('business_name', 'OneSystem')) ?></span>
        <i class="fas fa-chevron-right text-slate-300 text-xs"></i>
        <span class="font-semibold text-slate-700"><?= e($pageTitle ?? 'Dashboard') ?></span>
      </div>
    </div>

    <div class="flex items-center gap-2">
      <!-- Date chip -->
      <div class="hidden md:flex items-center gap-1.5 bg-slate-50 border border-slate-200 rounded-full px-3 py-1.5 text-xs text-slate-500">
        <i class="fas fa-calendar-alt text-blue-500"></i>
        <?= date('D, d M Y') ?>
      </div>

      <!-- Notification bell -->
      <?php
      $notifs = [];
      try {
          $nBid = $u['branch_id'] && !isSuperAdmin() && !hasRole('zone_manager') ? (int)$u['branch_id'] : 0;
          $nLow = (int)getDB()->query(
              'SELECT COUNT(DISTINCT s.product_id) FROM stock s JOIN products p ON p.id=s.product_id
               WHERE s.quantity <= p.min_stock_alert AND p.is_active=1' . ($nBid ? " AND s.branch_id=$nBid" : '')
          )->fetchColumn();
          if ($nLow > 0) $notifs[] = ['icon'=>'fa-exclamation-triangle','color'=>'text-red-500 bg-red-50','label'=>"$nLow product" . ($nLow>1?'s':'') . ' low on stock','href'=>url('stock?filter=low')];

          $nPo = (int)getDB()->query(
              "SELECT COUNT(*) FROM purchase_orders WHERE status IN ('pending','approved','ordered')" . ($nBid ? " AND branch_id=$nBid" : '')
          )->fetchColumn();
          if ($nPo > 0) $notifs[] = ['icon'=>'fa-clipboard-list','color'=>'text-amber-500 bg-amber-50','label'=>"$nPo purchase order" . ($nPo>1?'s':'') . ' awaiting action','href'=>url('purchase_orders')];

          $nDebt = (int)getDB()->query(
              "SELECT COUNT(*) FROM sales WHERE payment_status IN ('pending','partial')" . ($nBid ? " AND branch_id=$nBid" : '')
          )->fetchColumn();
          if ($nDebt > 0) $notifs[] = ['icon'=>'fa-hand-holding-usd','color'=>'text-pink-500 bg-pink-50','label'=>"$nDebt unpaid invoice" . ($nDebt>1?'s':''),'href'=>url('sales?status=pending')];
      } catch (PDOException) {}
      ?>
      <div class="relative" x-data="{ bellOpen:false }">
        <button @click="bellOpen=!bellOpen" class="relative p-2 rounded-xl text-slate-400 hover:text-slate-600 hover:bg-slate-100 transition-colors">
          <i class="fas fa-bell text-sm"></i>
          <?php if ($notifs): ?>
          <span class="absolute -top-0.5 -right-0.5 h-4 min-w-[1rem] px-1 rounded-full bg-red-500 text-white text-[.6rem] font-bold flex items-center justify-center"><?= count($notifs) ?></span>
          <?php endif; ?>
        </button>
        <div x-show="bellOpen" @click.away="bellOpen=false" x-cloak x-transition
             class="dropdown-panel absolute right-0 mt-2 w-72 rounded-2xl shadow-xl py-2 z-50"
             style="box-shadow:0 8px 30px rgba(0,0,0,.25)">
          <div class="px-4 py-2 border-b border-white/20 text-xs font-bold text-white/70 uppercase">Notifications</div>
          <?php if (!$notifs): ?>
          <div class="px-4 py-6 text-center text-white/70 text-sm"><i class="fas fa-check-circle text-emerald-300 text-xl block mb-2"></i>All caught up!</div>
          <?php else: foreach ($notifs as $n): ?>
          <a href="<?= $n['href'] ?>" class="dropdown-item flex items-center gap-3 px-4 py-2.5 transition-colors">
            <span class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0 <?= $n['color'] ?>"><i class="fas <?= $n['icon'] ?> text-xs"></i></span>
            <span class="text-sm"><?= e($n['label']) ?></span>
          </a>
          <?php endforeach; endif; ?>
        </div>
      </div>

      <!-- Divider -->
      <div class="h-7 w-px bg-slate-200 mx-1"></div>

      <!-- User menu -->
      <div class="relative" x-data="{ open:false }">
        <button @click="open=!open"
          class="flex items-center gap-2.5 pl-1 pr-3 py-1.5 rounded-xl hover:bg-slate-100 transition-colors text-sm">
          <div class="w-8 h-8 rounded-lg flex items-center justify-center font-bold text-white text-xs"
               style="background:linear-gradient(135deg,#007bff,#2a5298)">
            <?= strtoupper(substr($u['name'],0,2)) ?>
          </div>
          <div class="hidden sm:block text-left">
            <p class="font-semibold text-slate-700 leading-tight text-xs"><?= e($u['name']) ?></p>
            <p class="text-[.65rem] text-slate-400 capitalize leading-tight"><?= e(str_replace('_',' ',$u['role'])) ?></p>
          </div>
          <i class="fas fa-chevron-down text-[.6rem] text-slate-400"></i>
        </button>

        <div x-show="open" @click.away="open=false" x-cloak x-transition
          class="dropdown-panel absolute right-0 mt-2 w-52 rounded-2xl shadow-xl py-2 z-50"
          style="box-shadow:0 8px 30px rgba(0,0,0,.25)">
          <div class="px-4 py-2.5 border-b border-white/20">
            <p class="text-sm font-semibold text-white"><?= e($u['name']) ?></p>
            <p class="text-xs text-white/70"><?= e($u['username']) ?></p>
          </div>
          <div class="py-1.5 px-2">
            <a href="/logout" onclick="return confirm('Sign out?')"
               class="dropdown-item flex items-center gap-2.5 px-3 py-2 text-sm rounded-xl transition-colors">
              <i class="fas fa-sign-out-alt text-xs"></i> Sign Out
            </a>
          </div>
        </div>
      </div>
    </div>
  </header>

  <!-- Page content -->
  <main class="flex-1 overflow-auto p-6">
    <?= renderFlash() ?>
