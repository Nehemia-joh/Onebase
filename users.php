<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
requireRole('super_admin');
// Role directories are the canonical URLs — forward direct hits there
if (!defined('ENTRY_OK') && isLoggedIn()) {
    $q = $_SERVER['QUERY_STRING'] ?? '';
    header('Location: ' . url(basename(__FILE__, '.php')) . ($q !== '' ? "?$q" : ''));
    exit;
}

$db        = getDB();
$pageTitle = 'User Management';
$action    = clean($_GET['action'] ?? 'list');
$editId    = cleanInt($_GET['id'] ?? 0);

// ── Branches & Zones for selects ──────────────────────────────────────────────
$branches = $db->query('SELECT id, name FROM branches WHERE is_active = 1 ORDER BY name')->fetchAll();
$zones    = $db->query('SELECT id, name FROM zones ORDER BY name')->fetchAll();

$roleLabels = [
    'super_admin'      => 'Super Admin',
    'zone_manager'     => 'Zone Manager',
    'branch_manager'   => 'Branch Manager',
    'cashier'          => 'Cashier',
    'stock_controller' => 'Stock Controller',
];

// ── POST Handlers ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $act = clean($_POST['_act'] ?? '');

    if ($act === 'create' || $act === 'update') {
        $uid       = cleanInt($_POST['user_id'] ?? 0);
        $username  = clean($_POST['username'] ?? '');
        $email     = filter_var(clean($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
        $fullName  = clean($_POST['full_name'] ?? '');
        $phone     = clean($_POST['phone'] ?? '');
        $role      = clean($_POST['role'] ?? '');
        $branchId  = cleanInt($_POST['branch_id'] ?? 0) ?: null;
        $zoneId    = cleanInt($_POST['zone_id'] ?? 0) ?: null;
        $isActive  = isset($_POST['is_active']) ? 1 : 0;
        $password  = $_POST['password'] ?? '';

        if (!$username || !$email || !$fullName || !isset($roleLabels[$role])) {
            flash('error', 'Please fill in all required fields correctly.');
        } else {
            if ($act === 'create') {
                if (!$password) { flash('error', 'Password is required.'); header('Location: ' . url('users?action=new')); exit; }
                $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => (int)(getenv('BCRYPT_COST') ?: 12)]);
                try {
                    $db->prepare(
                        'INSERT INTO users (username,email,password,full_name,phone,role,branch_id,zone_id,is_active)
                         VALUES (?,?,?,?,?,?,?,?,?)'
                    )->execute([$username,$email,$hash,$fullName,$phone,$role,$branchId,$zoneId,$isActive]);
                    logActivity('user_created', "Created user: $username");
                    flash('success', "User '$fullName' created successfully.");
                } catch (PDOException $e) {
                    flash('error', str_contains($e->getMessage(), 'Duplicate') ? 'Username or email already exists.' : 'Error creating user.');
                }
            } else {
                if ($password) {
                    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => (int)(getenv('BCRYPT_COST') ?: 12)]);
                    $db->prepare(
                        'UPDATE users SET username=?,email=?,full_name=?,phone=?,role=?,branch_id=?,zone_id=?,is_active=?,password=? WHERE id=?'
                    )->execute([$username,$email,$fullName,$phone,$role,$branchId,$zoneId,$isActive,$hash,$uid]);
                } else {
                    $db->prepare(
                        'UPDATE users SET username=?,email=?,full_name=?,phone=?,role=?,branch_id=?,zone_id=?,is_active=? WHERE id=?'
                    )->execute([$username,$email,$fullName,$phone,$role,$branchId,$zoneId,$isActive,$uid]);
                }
                logActivity('user_updated', "Updated user: $username");
                flash('success', "User '$fullName' updated successfully.");
            }
        }
        header('Location: ' . url('users'));
        exit;
    }

    if ($act === 'delete') {
        $uid = cleanInt($_POST['user_id'] ?? 0);
        if ($uid === (int)($_SESSION['user_id'] ?? 0)) {
            flash('error', 'You cannot delete your own account.');
        } else {
            $user = $db->prepare('SELECT full_name FROM users WHERE id = ?');
            $user->execute([$uid]);
            $u2 = $user->fetch();
            $db->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$uid]);
            logActivity('user_deleted', "Deactivated user: " . ($u2['full_name'] ?? $uid));
            flash('success', 'User deactivated successfully.');
        }
        header('Location: ' . url('users'));
        exit;
    }

    if ($act === 'bulk_delete') {
        $selfId = (int)($_SESSION['user_id'] ?? 0);
        $raw    = array_map('intval', $_POST['ids'] ?? []);
        $ids    = array_values(array_unique(array_filter(array_diff($raw, [$selfId]))));
        $skippedSelf = in_array($selfId, $raw, true);

        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $db->prepare("UPDATE users SET is_active = 0 WHERE id IN ($in)")->execute($ids);
            logActivity('user_deleted', 'Bulk deactivated ' . count($ids) . ' user(s)');
            flash('success', count($ids) . ' user(s) deactivated.' . ($skippedSelf ? ' (Your own account was skipped.)' : ''));
        } else {
            flash('error', $skippedSelf ? 'You cannot deactivate your own account.' : 'No users selected.');
        }
        header('Location: ' . url('users'));
        exit;
    }
}

// ── Fetch edit record ─────────────────────────────────────────────────────────
$editUser = null;
if ($action === 'edit' && $editId) {
    $stmt = $db->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$editId]);
    $editUser = $stmt->fetch();
    if (!$editUser) { header('Location: ' . url('users')); exit; }
}

// ── List ──────────────────────────────────────────────────────────────────────
$search  = clean($_GET['q'] ?? '');
$roleFilter = clean($_GET['role'] ?? '');
$where  = ['1=1'];
$params = [];
if ($search) { $where[] = '(u.full_name LIKE ? OR u.username LIKE ? OR u.email LIKE ?)'; $params = array_merge($params, ["%$search%","%$search%","%$search%"]); }
if ($roleFilter) { $where[] = 'u.role = ?'; $params[] = $roleFilter; }
$whereStr = implode(' AND ', $where);

$users = $db->prepare(
    "SELECT u.*, b.name as branch_name, z.name as zone_name
     FROM users u
     LEFT JOIN branches b ON b.id = u.branch_id
     LEFT JOIN zones z ON z.id = u.zone_id
     WHERE $whereStr ORDER BY u.full_name"
);
$users->execute($params);
$users = $users->fetchAll();

include __DIR__ . '/includes/tailwind.php';
?>

<?php if ($action === 'new' || $action === 'edit'): ?>
<!-- ── Form ── -->
<div class="max-w-2xl mx-auto">
  <div class="flex items-center gap-3 mb-6">
    <a href="<?= url('users') ?>" class="text-gray-400 hover:text-gray-600"><i class="fas fa-arrow-left"></i></a>
    <h2 class="text-lg font-semibold text-gray-800"><?= $action === 'new' ? 'Add New User' : 'Edit User' ?></h2>
  </div>
  <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
    <form method="POST" autocomplete="off">
      <?= csrf_field() ?>
      <input type="hidden" name="_act" value="<?= $action === 'new' ? 'create' : 'update' ?>">
      <?php if ($editUser): ?><input type="hidden" name="user_id" value="<?= $editUser['id'] ?>"><?php endif; ?>

      <div class="grid sm:grid-cols-2 gap-4">
        <div>
          <label class="form-label">Full Name <span class="text-red-500">*</span></label>
          <input type="text" name="full_name" required class="form-input" value="<?= e($editUser['full_name'] ?? '') ?>">
        </div>
        <div>
          <label class="form-label">Username <span class="text-red-500">*</span></label>
          <input type="text" name="username" required class="form-input" value="<?= e($editUser['username'] ?? '') ?>">
        </div>
        <div>
          <label class="form-label">Email <span class="text-red-500">*</span></label>
          <input type="email" name="email" required class="form-input" value="<?= e($editUser['email'] ?? '') ?>">
        </div>
        <div>
          <label class="form-label">Phone</label>
          <input type="text" name="phone" class="form-input" value="<?= e($editUser['phone'] ?? '') ?>">
        </div>
        <div>
          <label class="form-label">Password <?= $action === 'new' ? '<span class="text-red-500">*</span>' : '<span class="text-gray-400 text-xs">(leave blank to keep)</span>' ?></label>
          <input type="password" name="password" <?= $action === 'new' ? 'required' : '' ?> class="form-input" autocomplete="new-password" placeholder="<?= $action === 'edit' ? 'Enter new password to change' : '' ?>">
        </div>
        <div>
          <label class="form-label">Role <span class="text-red-500">*</span></label>
          <select name="role" required class="form-input">
            <?php foreach ($roleLabels as $val => $label): ?>
            <option value="<?= $val ?>" <?= ($editUser['role'] ?? '') === $val ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="form-label">Assign Branch</label>
          <select name="branch_id" class="form-input">
            <option value="">-- No Branch --</option>
            <?php foreach ($branches as $b): ?>
            <option value="<?= $b['id'] ?>" <?= (int)($editUser['branch_id'] ?? 0) === (int)$b['id'] ? 'selected' : '' ?>><?= e($b['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="form-label">Assign Zone</label>
          <select name="zone_id" class="form-input">
            <option value="">-- No Zone --</option>
            <?php foreach ($zones as $z): ?>
            <option value="<?= $z['id'] ?>" <?= (int)($editUser['zone_id'] ?? 0) === (int)$z['id'] ? 'selected' : '' ?>><?= e($z['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="sm:col-span-2 flex items-center gap-2">
          <input type="checkbox" name="is_active" id="is_active" value="1" class="w-4 h-4 rounded text-indigo-600"
            <?= ($editUser['is_active'] ?? 1) ? 'checked' : '' ?>>
          <label for="is_active" class="text-sm text-gray-700">Account is active</label>
        </div>
      </div>

      <div class="flex gap-3 mt-6 pt-4 border-t border-gray-100">
        <button type="submit" class="btn-primary">
          <i class="fas fa-save"></i> <?= $action === 'new' ? 'Create User' : 'Save Changes' ?>
        </button>
        <a href="<?= url('users') ?>" class="btn-secondary"><i class="fas fa-times"></i> Cancel</a>
      </div>
    </form>
  </div>
</div>

<?php else: ?>
<!-- ── List ── -->
<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
  <form method="GET" class="flex gap-2">
    <input type="text" name="q" value="<?= e($search) ?>" placeholder="Search users..." class="form-input w-52">
    <select name="role" class="form-input w-44">
      <option value="">All Roles</option>
      <?php foreach ($roleLabels as $v => $l): ?>
      <option value="<?= $v ?>" <?= $roleFilter === $v ? 'selected' : '' ?>><?= $l ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn-secondary"><i class="fas fa-search"></i></button>
    <?php if ($search || $roleFilter): ?><a href="<?= url('users') ?>" class="btn-secondary"><i class="fas fa-times"></i></a><?php endif; ?>
  </form>
  <a href="<?= url('users') ?>?action=new" class="btn-primary"><i class="fas fa-plus"></i> Add User</a>
</div>

<form method="POST" id="bulkDeleteForm" class="hidden">
  <?= csrf_field() ?>
  <input type="hidden" name="_act" value="bulk_delete">
</form>
<div data-bulk-bar class="hidden items-center gap-3 mb-3 px-4 py-2.5 bg-red-50 border border-red-100 rounded-lg text-sm text-red-700">
  <span><span data-selected-count>0</span> selected</span>
  <button type="button" onclick="confirmBulkDelete('bulkDeleteForm','user')" class="btn-danger ml-auto"><i class="fas fa-user-slash"></i> Deactivate Selected</button>
</div>

<div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
  <div class="px-5 py-4 border-b border-gray-100">
    <h3 class="text-sm font-semibold text-gray-800"><?= count($users) ?> User<?= count($users) !== 1 ? 's' : '' ?></h3>
  </div>
  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead class="bg-gray-50 border-b border-gray-100">
        <tr>
          <th class="table-th w-8"><input type="checkbox" data-select-all></th>
          <th class="table-th">Name</th>
          <th class="table-th">Username</th>
          <th class="table-th">Role</th>
          <th class="table-th">Branch / Zone</th>
          <th class="table-th">Last Login</th>
          <th class="table-th">Status</th>
          <th class="table-th text-right">Actions</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-gray-50">
        <?php if (empty($users)): ?>
        <tr><td colspan="8" class="py-10 text-center text-gray-400">No users found</td></tr>
        <?php endif; ?>
        <?php foreach ($users as $u2): ?>
        <tr class="hover:bg-gray-50 transition-colors">
          <td class="table-td">
            <?php if ((int)$u2['id'] !== (int)($_SESSION['user_id'] ?? 0)): ?>
            <input type="checkbox" name="ids[]" value="<?= $u2['id'] ?>" form="bulkDeleteForm" data-row-check>
            <?php endif; ?>
          </td>
          <td class="table-td">
            <div class="flex items-center gap-3">
              <div class="w-8 h-8 bg-indigo-100 rounded-full flex items-center justify-center text-indigo-600 font-semibold text-xs flex-shrink-0">
                <?= e(strtoupper(substr($u2['full_name'], 0, 2))) ?>
              </div>
              <div>
                <p class="font-medium text-gray-800"><?= e($u2['full_name']) ?></p>
                <p class="text-xs text-gray-400"><?= e($u2['email']) ?></p>
              </div>
            </div>
          </td>
          <td class="table-td text-gray-500"><?= e($u2['username']) ?></td>
          <td class="table-td">
            <?php
            $roleColors = [
              'super_admin'      => 'bg-purple-100 text-purple-700',
              'zone_manager'     => 'bg-blue-100 text-blue-700',
              'branch_manager'   => 'bg-indigo-100 text-indigo-700',
              'cashier'          => 'bg-green-100 text-green-700',
              'stock_controller' => 'bg-orange-100 text-orange-700',
            ];
            $rc = $roleColors[$u2['role']] ?? 'bg-gray-100 text-gray-700';
            ?>
            <span class="badge <?= $rc ?>"><?= e($roleLabels[$u2['role']] ?? $u2['role']) ?></span>
          </td>
          <td class="table-td text-gray-500">
            <?= $u2['branch_name'] ? e($u2['branch_name']) : '' ?>
            <?= $u2['zone_name'] ? '<span class="text-xs text-gray-400">(' . e($u2['zone_name']) . ')</span>' : '' ?>
            <?= (!$u2['branch_name'] && !$u2['zone_name']) ? '<span class="text-gray-300">—</span>' : '' ?>
          </td>
          <td class="table-td text-gray-400 text-xs">
            <?= $u2['last_login'] ? date('d M Y H:i', strtotime($u2['last_login'])) : 'Never' ?>
          </td>
          <td class="table-td">
            <span class="badge <?= $u2['is_active'] ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-600' ?>">
              <?= $u2['is_active'] ? 'Active' : 'Inactive' ?>
            </span>
          </td>
          <td class="table-td text-right">
            <div class="flex justify-end gap-2">
              <a href="<?= url('users') ?>?action=edit&id=<?= $u2['id'] ?>" class="text-indigo-600 hover:text-indigo-800 px-2 py-1 rounded hover:bg-indigo-50" title="Edit">
                <i class="fas fa-edit"></i>
              </a>
              <?php if ((int)$u2['id'] !== (int)($_SESSION['user_id'] ?? 0)): ?>
              <form method="POST" class="inline" onsubmit="return confirm('Deactivate this user?')">
                <?= csrf_field() ?>
                <input type="hidden" name="_act" value="delete">
                <input type="hidden" name="user_id" value="<?= $u2['id'] ?>">
                <button type="submit" class="text-red-500 hover:text-red-700 px-2 py-1 rounded hover:bg-red-50" title="Deactivate">
                  <i class="fas fa-user-slash"></i>
                </button>
              </form>
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
