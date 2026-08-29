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
$pageTitle = 'Customers';
$action    = clean($_GET['action'] ?? 'list');
$editId    = cleanInt($_GET['id'] ?? 0);
$u         = currentUser();

// ── POST ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $act = clean($_POST['_act'] ?? '');

    if ($act === 'create' || $act === 'update') {
        $cid   = cleanInt($_POST['customer_id'] ?? 0);
        $name  = clean($_POST['name'] ?? '');
        $phone = clean($_POST['phone'] ?? '');
        $email = filter_var(clean($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL) ?: null;
        $type  = in_array($_POST['type'] ?? '', ['retail','wholesale','regular']) ? $_POST['type'] : 'retail';
        $notes = clean($_POST['notes'] ?? '');

        if (!$name) { flash('error','Customer name is required.'); header('Location: ' . url('customers')); exit; }

        if ($act === 'create') {
            $db->prepare('INSERT INTO customers (name,phone,email,type,notes,created_by) VALUES (?,?,?,?,?,?)')
                ->execute([$name,$phone,$email,$type,$notes,$u['id']]);
            logActivity('customer_created', "Created customer: $name");
            flash('success', "Customer '$name' created.");
        } else {
            $db->prepare('UPDATE customers SET name=?,phone=?,email=?,type=?,notes=? WHERE id=?')
                ->execute([$name,$phone,$email,$type,$notes,$cid]);
            logActivity('customer_updated', "Updated customer: $name");
            flash('success', "Customer '$name' updated.");
        }
        header('Location: ' . url('customers')); exit;
    }

    if ($act === 'delete' && isSuperAdmin()) {
        $cid = cleanInt($_POST['customer_id'] ?? 0);
        $db->prepare('DELETE FROM customers WHERE id=?')->execute([$cid]);
        logActivity('customer_deleted', "Deleted customer ID: $cid");
        flash('success', 'Customer deleted. Their past sales are kept.');
        header('Location: ' . url('customers')); exit;
    }

    if ($act === 'bulk_delete' && isSuperAdmin()) {
        $ids = array_values(array_unique(array_filter(array_map('intval', $_POST['ids'] ?? []))));
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $db->prepare("DELETE FROM customers WHERE id IN ($in)")->execute($ids);
            logActivity('customer_deleted', 'Bulk deleted ' . count($ids) . ' customer(s)');
            flash('success', count($ids) . ' customer(s) deleted. Their past sales are kept.');
        } else {
            flash('error', 'No customers selected.');
        }
        header('Location: ' . url('customers')); exit;
    }
}

// ── View customer ─────────────────────────────────────────────────────────────
$viewCustomer = null;
if (isset($_GET['view'])) {
    $cid  = cleanInt($_GET['view']);
    $stmt = $db->prepare('SELECT * FROM customers WHERE id=?');
    $stmt->execute([$cid]);
    $viewCustomer = $stmt->fetch();
    if ($viewCustomer) {
        $h = $db->prepare(
            'SELECT s.id, s.invoice_no, s.total, s.amount_paid, s.payment_status, s.payment_method, s.created_at, b.name as branch_name
             FROM sales s JOIN branches b ON b.id=s.branch_id
             WHERE s.customer_id=? ORDER BY s.created_at DESC LIMIT 50'
        );
        $h->execute([$cid]);
        $viewCustomer['sales'] = $h->fetchAll();
        $viewCustomer['outstanding'] = array_sum(array_map(
            fn($s) => max(0, (float)$s['total'] - (float)$s['amount_paid']),
            $viewCustomer['sales']
        ));
    }
}

// ── Edit form data ────────────────────────────────────────────────────────────
$editCustomer = null;
if ($action === 'edit' && $editId) {
    $stmt = $db->prepare('SELECT * FROM customers WHERE id=?');
    $stmt->execute([$editId]);
    $editCustomer = $stmt->fetch();
}

// ── List ──────────────────────────────────────────────────────────────────────
$search  = clean($_GET['q'] ?? '');
$debtors = ($_GET['filter'] ?? '') === 'debtors';

$where  = ['1=1'];
$params = [];
if ($search) { $where[] = '(c.name LIKE ? OR c.phone LIKE ? OR c.email LIKE ?)'; $params = ["%$search%","%$search%","%$search%"]; }
$whereStr = implode(' AND ', $where);

$having = $debtors ? 'HAVING outstanding > 0' : '';

$perPage = 25;
$page    = max(1, cleanInt($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

$countSql = "SELECT COUNT(*) FROM (
    SELECT c.id, COALESCE(SUM(GREATEST(s.total - s.amount_paid, 0)),0) as outstanding
    FROM customers c LEFT JOIN sales s ON s.customer_id = c.id
    WHERE $whereStr GROUP BY c.id $having) t";
$countStmt = $db->prepare($countSql);
$countStmt->execute($params);
$totalRows  = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

$listStmt = $db->prepare(
    "SELECT c.*, COUNT(s.id) as sales_count,
            COALESCE(SUM(GREATEST(s.total - s.amount_paid, 0)),0) as outstanding
     FROM customers c
     LEFT JOIN sales s ON s.customer_id = c.id
     WHERE $whereStr
     GROUP BY c.id $having
     ORDER BY c.name LIMIT $perPage OFFSET $offset"
);
$listStmt->execute($params);
$customers = $listStmt->fetchAll();

$totalDebt = (float)$db->query('SELECT COALESCE(SUM(GREATEST(total - amount_paid, 0)),0) FROM sales WHERE customer_id IS NOT NULL')->fetchColumn();

include __DIR__ . '/includes/tailwind.php';
?>

<?php if ($viewCustomer): ?>
<!-- ── Customer Detail ── -->
<div class="max-w-4xl mx-auto">
  <div class="flex items-center gap-3 mb-6">
    <a href="<?= url('customers') ?>" class="text-gray-400 hover:text-gray-600"><i class="fas fa-arrow-left"></i></a>
    <h2 class="text-lg font-semibold text-gray-800"><?= e($viewCustomer['name']) ?></h2>
    <a href="<?= url('customers') ?>?action=edit&id=<?= $viewCustomer['id'] ?>" class="ml-auto btn-secondary"><i class="fas fa-edit"></i> Edit</a>
  </div>

  <div class="grid sm:grid-cols-3 gap-4 mb-6">
    <div class="page-card p-4">
      <p class="text-xs text-slate-400 uppercase font-semibold">Total Purchases</p>
      <p class="text-xl font-bold text-slate-800 mt-1"><?= formatCurrency((float)$viewCustomer['total_purchases']) ?></p>
    </div>
    <div class="page-card p-4">
      <p class="text-xs text-slate-400 uppercase font-semibold">Outstanding Debt</p>
      <p class="text-xl font-bold <?= $viewCustomer['outstanding'] > 0 ? 'text-red-600' : 'text-emerald-600' ?> mt-1"><?= formatCurrency((float)$viewCustomer['outstanding']) ?></p>
    </div>
    <div class="page-card p-4">
      <p class="text-xs text-slate-400 uppercase font-semibold">Last Purchase</p>
      <p class="text-xl font-bold text-slate-800 mt-1"><?= $viewCustomer['last_purchase_date'] ? date('d M Y', strtotime($viewCustomer['last_purchase_date'])) : '—' ?></p>
    </div>
  </div>

  <div class="page-card p-5 mb-6">
    <div class="grid sm:grid-cols-2 gap-3 text-sm">
      <div><span class="text-gray-500">Phone:</span> <span class="font-medium"><?= e($viewCustomer['phone'] ?: '—') ?></span></div>
      <div><span class="text-gray-500">Email:</span> <span class="font-medium"><?= e($viewCustomer['email'] ?: '—') ?></span></div>
      <div><span class="text-gray-500">Type:</span> <span class="badge bg-blue-100 text-blue-600 capitalize"><?= e($viewCustomer['type']) ?></span></div>
      <div><span class="text-gray-500">Customer since:</span> <span class="font-medium"><?= date('d M Y', strtotime($viewCustomer['created_at'])) ?></span></div>
      <?php if ($viewCustomer['notes']): ?>
      <div class="sm:col-span-2"><span class="text-gray-500">Notes:</span> <?= e($viewCustomer['notes']) ?></div>
      <?php endif; ?>
    </div>
  </div>

  <div class="page-card overflow-hidden">
    <div class="px-5 py-4 border-b border-slate-100">
      <h3 class="font-bold text-slate-800">Purchase History</h3>
      <p class="text-xs text-slate-400">Last 50 invoices</p>
    </div>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b border-gray-100"><tr>
          <th class="table-th">Invoice</th><th class="table-th">Branch</th><th class="table-th text-right">Total</th>
          <th class="table-th text-right">Balance</th><th class="table-th">Status</th><th class="table-th">Date</th>
        </tr></thead>
        <tbody class="divide-y divide-gray-50">
          <?php if (empty($viewCustomer['sales'])): ?>
          <tr><td colspan="6" class="py-10 text-center text-gray-400">No purchases yet</td></tr>
          <?php endif; ?>
          <?php foreach ($viewCustomer['sales'] as $s): $due = max(0,(float)$s['total']-(float)$s['amount_paid']); ?>
          <tr class="hover:bg-gray-50">
            <td class="table-td"><a href="<?= url('sales') ?>?view=<?= $s['id'] ?>" class="font-mono text-indigo-600 font-medium"><?= e($s['invoice_no']) ?></a></td>
            <td class="table-td text-gray-500"><?= e($s['branch_name']) ?></td>
            <td class="table-td text-right font-semibold"><?= formatCurrency((float)$s['total']) ?></td>
            <td class="table-td text-right <?= $due > 0 ? 'text-red-600 font-semibold' : 'text-gray-400' ?>"><?= $due > 0 ? formatCurrency($due) : '—' ?></td>
            <td class="table-td"><span class="badge <?= $s['payment_status']==='paid' ? 'bg-green-100 text-green-700' : ($s['payment_status']==='partial' ? 'bg-orange-100 text-orange-700' : 'bg-yellow-100 text-yellow-700') ?> capitalize"><?= e($s['payment_status']) ?></span></td>
            <td class="table-td text-gray-400 text-xs"><?= date('d M Y', strtotime($s['created_at'])) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php elseif ($action === 'new' || ($action === 'edit' && $editCustomer)): ?>
<!-- ── Customer Form ── -->
<div class="max-w-xl mx-auto">
  <div class="flex items-center gap-3 mb-6">
    <a href="<?= url('customers') ?>" class="text-gray-400 hover:text-gray-600"><i class="fas fa-arrow-left"></i></a>
    <h2 class="text-lg font-semibold text-gray-800"><?= $action === 'new' ? 'Add Customer' : 'Edit Customer' ?></h2>
  </div>
  <div class="page-card p-6">
    <form method="POST" class="space-y-4">
      <?= csrf_field() ?>
      <input type="hidden" name="_act" value="<?= $action === 'new' ? 'create' : 'update' ?>">
      <?php if ($editCustomer): ?><input type="hidden" name="customer_id" value="<?= $editCustomer['id'] ?>"><?php endif; ?>
      <div>
        <label class="form-label">Name <span class="text-red-500">*</span></label>
        <input type="text" name="name" required value="<?= e($editCustomer['name'] ?? '') ?>" class="form-input">
      </div>
      <div class="grid sm:grid-cols-2 gap-4">
        <div>
          <label class="form-label">Phone</label>
          <input type="text" name="phone" value="<?= e($editCustomer['phone'] ?? '') ?>" class="form-input" placeholder="+255 ...">
        </div>
        <div>
          <label class="form-label">Email</label>
          <input type="email" name="email" value="<?= e($editCustomer['email'] ?? '') ?>" class="form-input">
        </div>
      </div>
      <div>
        <label class="form-label">Type</label>
        <select name="type" class="form-input">
          <?php foreach (['retail','wholesale','regular'] as $t): ?>
          <option value="<?= $t ?>" <?= ($editCustomer['type'] ?? 'retail') === $t ? 'selected' : '' ?>><?= ucfirst($t) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="form-label">Notes</label>
        <textarea name="notes" rows="3" class="form-input"><?= e($editCustomer['notes'] ?? '') ?></textarea>
      </div>
      <div class="flex gap-3 pt-2">
        <button type="submit" class="btn-primary flex-1 justify-center"><i class="fas fa-save"></i> <?= $action === 'new' ? 'Create Customer' : 'Save Changes' ?></button>
        <a href="<?= url('customers') ?>" class="btn-secondary">Cancel</a>
      </div>
    </form>
  </div>
</div>

<?php else: ?>
<!-- ── Customer List ── -->
<div class="grid grid-cols-2 sm:grid-cols-3 gap-4 mb-5">
  <div class="page-card p-4">
    <p class="text-xs text-slate-400 uppercase font-semibold">Customers</p>
    <p class="text-xl font-bold text-slate-800 mt-1"><?= number_format($totalRows) ?></p>
  </div>
  <div class="page-card p-4">
    <p class="text-xs text-slate-400 uppercase font-semibold">Total Outstanding</p>
    <p class="text-xl font-bold <?= $totalDebt > 0 ? 'text-red-600' : 'text-emerald-600' ?> mt-1"><?= formatCurrency($totalDebt) ?></p>
  </div>
</div>

<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-5">
  <form method="GET" class="flex gap-2">
    <?php if ($debtors): ?><input type="hidden" name="filter" value="debtors"><?php endif; ?>
    <input type="text" name="q" value="<?= e($search) ?>" placeholder="Search name, phone, email..." class="form-input w-56">
    <button type="submit" class="btn-secondary"><i class="fas fa-search"></i></button>
    <a href="<?= url('customers') ?><?= $debtors ? '' : '?filter=debtors' ?>" class="<?= $debtors ? 'btn-primary' : 'btn-secondary' ?>">
      <i class="fas fa-hand-holding-usd"></i> Debtors Only
    </a>
    <?php if ($search): ?><a href="<?= url('customers') ?>" class="btn-secondary"><i class="fas fa-times"></i></a><?php endif; ?>
  </form>
  <a href="<?= url('customers') ?>?action=new" class="btn-primary"><i class="fas fa-user-plus"></i> Add Customer</a>
</div>

<?php if (isSuperAdmin()): ?>
<form method="POST" id="bulkDeleteForm" class="hidden">
  <?= csrf_field() ?>
  <input type="hidden" name="_act" value="bulk_delete">
</form>
<div data-bulk-bar class="hidden items-center gap-3 mb-3 px-4 py-2.5 bg-red-50 border border-red-100 rounded-lg text-sm text-red-700">
  <span><span data-selected-count>0</span> selected</span>
  <button type="button" onclick="confirmBulkDelete('bulkDeleteForm','customer')" class="btn-danger ml-auto"><i class="fas fa-trash-alt"></i> Delete Selected</button>
</div>
<?php endif; ?>
<div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead class="bg-gray-50 border-b border-gray-100"><tr>
        <?php if (isSuperAdmin()): ?><th class="table-th w-8"><input type="checkbox" data-select-all></th><?php endif; ?>
        <th class="table-th">Customer</th>
        <th class="table-th">Contact</th>
        <th class="table-th">Type</th>
        <th class="table-th text-right">Sales</th>
        <th class="table-th text-right">Total Purchases</th>
        <th class="table-th text-right">Outstanding</th>
        <th class="table-th text-right">Actions</th>
      </tr></thead>
      <tbody class="divide-y divide-gray-50">
        <?php if (empty($customers)): ?>
        <tr><td colspan="<?= isSuperAdmin() ? 8 : 7 ?>" class="py-12 text-center text-gray-400">
          <i class="fas fa-user-friends text-4xl mb-3 block"></i>
          <?= $debtors ? 'No customers with outstanding debt. 🎉' : 'No customers found.' ?>
        </td></tr>
        <?php endif; ?>
        <?php foreach ($customers as $c): ?>
        <tr class="hover:bg-gray-50 transition-colors">
          <?php if (isSuperAdmin()): ?><td class="table-td"><input type="checkbox" name="ids[]" value="<?= $c['id'] ?>" form="bulkDeleteForm" data-row-check></td><?php endif; ?>
          <td class="table-td">
            <a href="<?= url('customers') ?>?view=<?= $c['id'] ?>" class="font-semibold text-indigo-600 hover:text-indigo-800"><?= e($c['name']) ?></a>
          </td>
          <td class="table-td text-gray-500 text-xs">
            <?php if ($c['phone']): ?><p><i class="fas fa-phone text-gray-300 mr-1"></i><?= e($c['phone']) ?></p><?php endif; ?>
            <?php if ($c['email']): ?><p><i class="fas fa-envelope text-gray-300 mr-1"></i><?= e($c['email']) ?></p><?php endif; ?>
          </td>
          <td class="table-td"><span class="badge <?= $c['type']==='wholesale' ? 'bg-blue-100 text-blue-600' : 'bg-gray-100 text-gray-500' ?> capitalize"><?= e($c['type']) ?></span></td>
          <td class="table-td text-right text-gray-500"><?= $c['sales_count'] ?></td>
          <td class="table-td text-right font-medium"><?= formatCurrency((float)$c['total_purchases']) ?></td>
          <td class="table-td text-right <?= $c['outstanding'] > 0 ? 'text-red-600 font-bold' : 'text-gray-400' ?>">
            <?= $c['outstanding'] > 0 ? formatCurrency((float)$c['outstanding']) : '—' ?>
          </td>
          <td class="table-td text-right whitespace-nowrap">
            <a href="<?= url('customers') ?>?view=<?= $c['id'] ?>" class="text-indigo-600 hover:text-indigo-800 px-2 py-1 rounded hover:bg-indigo-50" title="View"><i class="fas fa-eye"></i></a>
            <a href="<?= url('customers') ?>?action=edit&id=<?= $c['id'] ?>" class="text-gray-400 hover:text-gray-600 px-2 py-1 rounded hover:bg-gray-100" title="Edit"><i class="fas fa-edit"></i></a>
            <?php if (isSuperAdmin()): ?>
            <form method="POST" class="inline" onsubmit="return confirm('Delete <?= e($c['name']) ?>? Their sales history is kept.')">
              <?= csrf_field() ?>
              <input type="hidden" name="_act" value="delete">
              <input type="hidden" name="customer_id" value="<?= $c['id'] ?>">
              <button type="submit" class="text-red-400 hover:text-red-600 px-2 py-1 rounded hover:bg-red-50" title="Delete"><i class="fas fa-trash-alt"></i></button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($totalPages > 1): ?>
  <div class="flex items-center justify-between px-4 py-3 border-t border-gray-100 text-sm">
    <p class="text-gray-500"><?= number_format($totalRows) ?> customers &bull; page <?= $page ?> of <?= $totalPages ?></p>
    <div class="flex gap-1">
      <?php
      $qs = $_GET; unset($qs['page']);
      $base = url('customers') . '?' . http_build_query($qs);
      $base .= $qs ? '&' : '';
      ?>
      <?php if ($page > 1): ?><a href="<?= e($base) ?>page=<?= $page-1 ?>" class="btn-secondary px-3 py-1.5"><i class="fas fa-chevron-left text-xs"></i></a><?php endif; ?>
      <?php for ($p = max(1,$page-2); $p <= min($totalPages,$page+2); $p++): ?>
      <a href="<?= e($base) ?>page=<?= $p ?>" class="<?= $p===$page ? 'btn-primary' : 'btn-secondary' ?> px-3 py-1.5"><?= $p ?></a>
      <?php endfor; ?>
      <?php if ($page < $totalPages): ?><a href="<?= e($base) ?>page=<?= $page+1 ?>" class="btn-secondary px-3 py-1.5"><i class="fas fa-chevron-right text-xs"></i></a><?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
