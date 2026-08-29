<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
requireRole('super_admin','zone_manager','branch_manager');
// Role directories are the canonical URLs — forward direct hits there
if (!defined('ENTRY_OK') && isLoggedIn()) {
    $q = $_SERVER['QUERY_STRING'] ?? '';
    header('Location: ' . url(basename(__FILE__, '.php')) . ($q !== '' ? "?$q" : ''));
    exit;
}

$db        = getDB();
$pageTitle = 'Branch Management';
$action    = clean($_GET['action'] ?? 'list');
$editId    = cleanInt($_GET['id'] ?? 0);
$zoneFilter = cleanInt($_GET['zone_id'] ?? 0);

$zones    = $db->query('SELECT id, name FROM zones ORDER BY name')->fetchAll();
$managers = $db->query("SELECT id, full_name FROM users WHERE role IN ('branch_manager','super_admin') AND is_active = 1 ORDER BY full_name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $act = clean($_POST['_act'] ?? '');

    if ($act === 'create' || $act === 'update') {
        $bid       = cleanInt($_POST['branch_id'] ?? 0);
        $name      = clean($_POST['name'] ?? '');
        $zoneId    = cleanInt($_POST['zone_id'] ?? 0) ?: null;
        $managerId = cleanInt($_POST['manager_id'] ?? 0) ?: null;
        $address   = clean($_POST['address'] ?? '');
        $phone     = clean($_POST['phone'] ?? '');
        $email     = filter_var(clean($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL) ?: null;
        $isActive  = isset($_POST['is_active']) ? 1 : 0;

        if (!$name) { flash('error', 'Branch name is required.'); header('Location: ' . url('branches?action=' . ($act === 'create' ? 'new' : "edit&id=$bid"))); exit; }

        if ($act === 'create') {
            $db->prepare('INSERT INTO branches (name,zone_id,manager_id,address,phone,email,is_active) VALUES (?,?,?,?,?,?,?)')->execute([$name,$zoneId,$managerId,$address,$phone,$email,$isActive]);
            logActivity('branch_created', "Created branch: $name");
            flash('success', "Branch '$name' created.");
        } else {
            $db->prepare('UPDATE branches SET name=?,zone_id=?,manager_id=?,address=?,phone=?,email=?,is_active=? WHERE id=?')->execute([$name,$zoneId,$managerId,$address,$phone,$email,$isActive,$bid]);
            logActivity('branch_updated', "Updated branch: $name");
            flash('success', "Branch '$name' updated.");
        }
        header('Location: ' . url('branches')); exit;
    }

    if ($act === 'delete') {
        $bid = cleanInt($_POST['branch_id'] ?? 0);
        $userCount = (function() use ($db, $bid) { $s=$db->prepare('SELECT COUNT(*) FROM users WHERE branch_id=? AND is_active=1'); $s->execute([$bid]); return (int)$s->fetchColumn(); })();
        if ($userCount > 0) {
            flash('error', "Cannot delete: $userCount user(s) are assigned to this branch.");
        } else {
            $db->prepare('DELETE FROM branches WHERE id = ?')->execute([$bid]);
            logActivity('branch_deleted', "Deleted branch ID: $bid");
            flash('success', 'Branch deleted.');
        }
        header('Location: ' . url('branches')); exit;
    }

    if ($act === 'bulk_delete' && isSuperAdmin()) {
        $ids = array_values(array_unique(array_filter(array_map('intval', $_POST['ids'] ?? []))));
        $deleted = 0; $blocked = 0;
        foreach ($ids as $bid) {
            $s = $db->prepare('SELECT COUNT(*) FROM users WHERE branch_id=? AND is_active=1');
            $s->execute([$bid]);
            if ((int)$s->fetchColumn() > 0) { $blocked++; continue; }
            $db->prepare('DELETE FROM branches WHERE id = ?')->execute([$bid]);
            $deleted++;
        }
        if ($deleted) logActivity('branch_deleted', "Bulk deleted $deleted branch(es)");
        if ($deleted && !$blocked)    flash('success', "$deleted branch(es) deleted.");
        elseif ($deleted && $blocked) flash('error', "$deleted deleted, $blocked skipped (still have staff assigned).");
        else                          flash('error', $blocked ? "Cannot delete: $blocked branch(es) still have staff assigned." : 'No branches selected.');
        header('Location: ' . url('branches')); exit;
    }
}

$editBranch = null;
if ($action === 'edit' && $editId) {
    $s = $db->prepare('SELECT * FROM branches WHERE id = ?'); $s->execute([$editId]); $editBranch = $s->fetch();
    if (!$editBranch) { header('Location: ' . url('branches')); exit; }
}

$where  = ['1=1'];
$params = [];
if ($zoneFilter) { $where[] = 'b.zone_id = ?'; $params[] = $zoneFilter; }
if (!isSuperAdmin() && !hasRole('zone_manager')) { $where[] = 'b.id = ?'; $params[] = (int)($_SESSION['branch_id'] ?? 0); }
$whereStr = implode(' AND ', $where);

$branchStmt = $db->prepare(
    "SELECT b.*, z.name as zone_name, u.full_name as manager_name,
     (SELECT COUNT(*) FROM users usr WHERE usr.branch_id = b.id AND usr.is_active = 1) as user_count,
     (SELECT COUNT(*) FROM stock st WHERE st.branch_id = b.id) as product_count
     FROM branches b
     LEFT JOIN zones z ON z.id = b.zone_id
     LEFT JOIN users u ON u.id = b.manager_id
     WHERE $whereStr ORDER BY b.name"
);
$branchStmt->execute($params);
$branches = $branchStmt->fetchAll();

include __DIR__ . '/includes/tailwind.php';
?>

<?php if ($action === 'new' || $action === 'edit'): ?>
<div class="max-w-xl mx-auto">
  <div class="flex items-center gap-3 mb-6">
    <a href="<?= url('branches') ?>" class="text-gray-400 hover:text-gray-600"><i class="fas fa-arrow-left"></i></a>
    <h2 class="text-lg font-semibold text-gray-800"><?= $action === 'new' ? 'Add Branch' : 'Edit Branch' ?></h2>
  </div>
  <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="_act" value="<?= $action === 'new' ? 'create' : 'update' ?>">
      <?php if ($editBranch): ?><input type="hidden" name="branch_id" value="<?= $editBranch['id'] ?>"><?php endif; ?>
      <div class="grid sm:grid-cols-2 gap-4">
        <div class="sm:col-span-2">
          <label class="form-label">Branch Name <span class="text-red-500">*</span></label>
          <input type="text" name="name" required class="form-input" value="<?= e($editBranch['name'] ?? '') ?>">
        </div>
        <div>
          <label class="form-label">Zone</label>
          <select name="zone_id" class="form-input">
            <option value="">-- No Zone --</option>
            <?php foreach ($zones as $z): ?>
            <option value="<?= $z['id'] ?>" <?= (int)($editBranch['zone_id'] ?? 0) === (int)$z['id'] ? 'selected' : '' ?>><?= e($z['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="form-label">Branch Manager</label>
          <select name="manager_id" class="form-input">
            <option value="">-- No Manager --</option>
            <?php foreach ($managers as $m): ?>
            <option value="<?= $m['id'] ?>" <?= (int)($editBranch['manager_id'] ?? 0) === (int)$m['id'] ? 'selected' : '' ?>><?= e($m['full_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="form-label">Phone</label>
          <input type="text" name="phone" class="form-input" value="<?= e($editBranch['phone'] ?? '') ?>">
        </div>
        <div>
          <label class="form-label">Email</label>
          <input type="email" name="email" class="form-input" value="<?= e($editBranch['email'] ?? '') ?>">
        </div>
        <div class="sm:col-span-2">
          <label class="form-label">Address</label>
          <textarea name="address" rows="2" class="form-input"><?= e($editBranch['address'] ?? '') ?></textarea>
        </div>
        <div class="sm:col-span-2 flex items-center gap-2">
          <input type="checkbox" name="is_active" id="is_active" value="1" class="w-4 h-4 rounded text-indigo-600" <?= ($editBranch['is_active'] ?? 1) ? 'checked' : '' ?>>
          <label for="is_active" class="text-sm text-gray-700">Branch is active</label>
        </div>
      </div>
      <div class="flex gap-3 mt-6 pt-4 border-t border-gray-100">
        <button type="submit" class="btn-primary"><i class="fas fa-save"></i> <?= $action === 'new' ? 'Create Branch' : 'Save' ?></button>
        <a href="<?= url('branches') ?>" class="btn-secondary"><i class="fas fa-times"></i> Cancel</a>
      </div>
    </form>
  </div>
</div>

<?php else: ?>
<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
  <div class="flex gap-2">
    <select onchange="window.location='<?= url('branches') ?>?zone_id='+this.value" class="form-input w-44 text-sm">
      <option value="">All Zones</option>
      <?php foreach ($zones as $z): ?>
      <option value="<?= $z['id'] ?>" <?= $zoneFilter === (int)$z['id'] ? 'selected' : '' ?>><?= e($z['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php if (canManageBranches()): ?>
  <a href="<?= url('branches') ?>?action=new" class="btn-primary"><i class="fas fa-plus"></i> Add Branch</a>
  <?php endif; ?>
</div>

<div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
  <div class="px-5 py-4 border-b border-gray-100">
    <h3 class="text-sm font-semibold text-gray-800"><?= count($branches) ?> Branch<?= count($branches) !== 1 ? 'es' : '' ?><?= $zoneFilter ? ' in zone' : '' ?></h3>
  </div>
  <?php if (isSuperAdmin()): ?>
  <form method="POST" id="bulkDeleteForm" class="hidden">
    <?= csrf_field() ?>
    <input type="hidden" name="_act" value="bulk_delete">
  </form>
  <div data-bulk-bar class="hidden items-center gap-3 mx-4 mt-4 px-4 py-2.5 bg-red-50 border border-red-100 rounded-lg text-sm text-red-700">
    <span><span data-selected-count>0</span> selected</span>
    <button type="button" onclick="confirmBulkDelete('bulkDeleteForm','branch')" class="btn-danger ml-auto"><i class="fas fa-trash-alt"></i> Delete Selected</button>
  </div>
  <?php endif; ?>
  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead class="bg-gray-50 border-b border-gray-100">
        <tr>
          <?php if (isSuperAdmin()): ?><th class="table-th w-8"><input type="checkbox" data-select-all></th><?php endif; ?>
          <th class="table-th">Branch</th>
          <th class="table-th">Zone</th>
          <th class="table-th">Manager</th>
          <th class="table-th">Contact</th>
          <th class="table-th text-center">Users</th>
          <th class="table-th text-center">Products</th>
          <th class="table-th">Status</th>
          <th class="table-th text-right">Actions</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-gray-50">
        <?php if (empty($branches)): ?>
        <tr><td colspan="<?= isSuperAdmin() ? 9 : 8 ?>" class="py-10 text-center text-gray-400">No branches found</td></tr>
        <?php endif; ?>
        <?php foreach ($branches as $b): ?>
        <tr class="hover:bg-gray-50 transition-colors">
          <?php if (isSuperAdmin()): ?><td class="table-td"><input type="checkbox" name="ids[]" value="<?= $b['id'] ?>" form="bulkDeleteForm" data-row-check></td><?php endif; ?>
          <td class="table-td">
            <div class="flex items-center gap-3">
              <div class="w-8 h-8 bg-blue-100 rounded-lg flex items-center justify-center flex-shrink-0">
                <i class="fas fa-store text-blue-600 text-xs"></i>
              </div>
              <span class="font-medium text-gray-800"><?= e($b['name']) ?></span>
            </div>
          </td>
          <td class="table-td text-gray-500"><?= $b['zone_name'] ? e($b['zone_name']) : '<span class="text-gray-300">—</span>' ?></td>
          <td class="table-td text-gray-500"><?= $b['manager_name'] ? e($b['manager_name']) : '<span class="text-gray-300">—</span>' ?></td>
          <td class="table-td text-gray-500 text-xs">
            <?= $b['phone'] ? '<div><i class="fas fa-phone mr-1"></i>' . e($b['phone']) . '</div>' : '' ?>
            <?= $b['email'] ? '<div><i class="fas fa-envelope mr-1"></i>' . e($b['email']) . '</div>' : '' ?>
          </td>
          <td class="table-td text-center"><span class="badge bg-purple-100 text-purple-700"><?= $b['user_count'] ?></span></td>
          <td class="table-td text-center"><span class="badge bg-blue-100 text-blue-700"><?= $b['product_count'] ?></span></td>
          <td class="table-td">
            <span class="badge <?= $b['is_active'] ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' ?>">
              <?= $b['is_active'] ? 'Active' : 'Inactive' ?>
            </span>
          </td>
          <td class="table-td text-right">
            <div class="flex justify-end gap-2">
              <?php if (canManageBranches()): ?>
              <a href="<?= url('branches') ?>?action=edit&id=<?= $b['id'] ?>" class="text-indigo-600 hover:text-indigo-800 px-2 py-1 rounded hover:bg-indigo-50">
                <i class="fas fa-edit"></i>
              </a>
              <?php if (isSuperAdmin()): ?>
              <form method="POST" class="inline" onsubmit="return confirm('Delete this branch?')">
                <?= csrf_field() ?>
                <input type="hidden" name="_act" value="delete">
                <input type="hidden" name="branch_id" value="<?= $b['id'] ?>">
                <button type="submit" class="text-red-500 hover:text-red-700 px-2 py-1 rounded hover:bg-red-50"><i class="fas fa-trash"></i></button>
              </form>
              <?php endif; ?>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
