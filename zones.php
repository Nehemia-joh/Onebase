<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
requireRole('super_admin','zone_manager');
// Role directories are the canonical URLs — forward direct hits there
if (!defined('ENTRY_OK') && isLoggedIn()) {
    $q = $_SERVER['QUERY_STRING'] ?? '';
    header('Location: ' . url(basename(__FILE__, '.php')) . ($q !== '' ? "?$q" : ''));
    exit;
}

$db        = getDB();
$pageTitle = 'Zone Management';
$action    = clean($_GET['action'] ?? 'list');
$editId    = cleanInt($_GET['id'] ?? 0);

$managers = $db->query("SELECT id, full_name FROM users WHERE role IN ('super_admin','zone_manager') AND is_active=1 ORDER BY full_name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $act = clean($_POST['_act'] ?? '');

    if ($act === 'create' || $act === 'update') {
        $zid         = cleanInt($_POST['zone_id'] ?? 0);
        $name        = clean($_POST['name'] ?? '');
        $description = clean($_POST['description'] ?? '');
        $managerId   = cleanInt($_POST['manager_id'] ?? 0) ?: null;

        if (!$name) { flash('error', 'Zone name is required.'); header('Location: ' . url('zones?action=' . ($act === 'create' ? 'new' : "edit&id=$zid"))); exit; }

        if ($act === 'create') {
            $db->prepare('INSERT INTO zones (name, description, manager_id) VALUES (?,?,?)')->execute([$name,$description,$managerId]);
            logActivity('zone_created', "Created zone: $name");
            flash('success', "Zone '$name' created.");
        } else {
            $db->prepare('UPDATE zones SET name=?, description=?, manager_id=? WHERE id=?')->execute([$name,$description,$managerId,$zid]);
            logActivity('zone_updated', "Updated zone: $name");
            flash('success', "Zone '$name' updated.");
        }
        header('Location: ' . url('zones')); exit;
    }

    if ($act === 'delete') {
        $zid = cleanInt($_POST['zone_id'] ?? 0);
        $branchCount = (int)$db->prepare('SELECT COUNT(*) FROM branches WHERE zone_id = ?')->execute([$zid]) && false ?: (function() use ($db, $zid) { $s=$db->prepare('SELECT COUNT(*) FROM branches WHERE zone_id=?'); $s->execute([$zid]); return (int)$s->fetchColumn(); })();
        if ($branchCount > 0) {
            flash('error', 'Cannot delete zone: it has branches assigned. Move branches first.');
        } else {
            $db->prepare('DELETE FROM zones WHERE id = ?')->execute([$zid]);
            logActivity('zone_deleted', "Deleted zone ID: $zid");
            flash('success', 'Zone deleted.');
        }
        header('Location: ' . url('zones')); exit;
    }

    if ($act === 'bulk_delete' && isSuperAdmin()) {
        $ids = array_values(array_unique(array_filter(array_map('intval', $_POST['ids'] ?? []))));
        $deleted = 0; $blocked = 0;
        foreach ($ids as $zid) {
            $s = $db->prepare('SELECT COUNT(*) FROM branches WHERE zone_id=?');
            $s->execute([$zid]);
            if ((int)$s->fetchColumn() > 0) { $blocked++; continue; }
            $db->prepare('DELETE FROM zones WHERE id = ?')->execute([$zid]);
            $deleted++;
        }
        if ($deleted) logActivity('zone_deleted', "Bulk deleted $deleted zone(s)");
        if ($deleted && !$blocked)    flash('success', "$deleted zone(s) deleted.");
        elseif ($deleted && $blocked) flash('error', "$deleted deleted, $blocked skipped (have branches assigned).");
        else                          flash('error', $blocked ? "Cannot delete: $blocked zone(s) have branches assigned." : 'No zones selected.');
        header('Location: ' . url('zones')); exit;
    }
}

$editZone = null;
if ($action === 'edit' && $editId) {
    $s = $db->prepare('SELECT * FROM zones WHERE id = ?'); $s->execute([$editId]); $editZone = $s->fetch();
    if (!$editZone) { header('Location: ' . url('zones')); exit; }
}

$zones = $db->query(
    'SELECT z.*, u.full_name as manager_name,
     (SELECT COUNT(*) FROM branches b WHERE b.zone_id = z.id) as branch_count
     FROM zones z
     LEFT JOIN users u ON u.id = z.manager_id
     ORDER BY z.name'
)->fetchAll();

include __DIR__ . '/includes/tailwind.php';
?>

<?php if ($action === 'new' || $action === 'edit'): ?>
<div class="max-w-lg mx-auto">
  <div class="flex items-center gap-3 mb-6">
    <a href="<?= url('zones') ?>" class="text-gray-400 hover:text-gray-600"><i class="fas fa-arrow-left"></i></a>
    <h2 class="text-lg font-semibold text-gray-800"><?= $action === 'new' ? 'Add Zone' : 'Edit Zone' ?></h2>
  </div>
  <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="_act" value="<?= $action === 'new' ? 'create' : 'update' ?>">
      <?php if ($editZone): ?><input type="hidden" name="zone_id" value="<?= $editZone['id'] ?>"><?php endif; ?>
      <div class="space-y-4">
        <div>
          <label class="form-label">Zone Name <span class="text-red-500">*</span></label>
          <input type="text" name="name" required class="form-input" value="<?= e($editZone['name'] ?? '') ?>">
        </div>
        <div>
          <label class="form-label">Description</label>
          <textarea name="description" rows="3" class="form-input"><?= e($editZone['description'] ?? '') ?></textarea>
        </div>
        <div>
          <label class="form-label">Zone Manager</label>
          <select name="manager_id" class="form-input">
            <option value="">-- No Manager --</option>
            <?php foreach ($managers as $m): ?>
            <option value="<?= $m['id'] ?>" <?= (int)($editZone['manager_id'] ?? 0) === (int)$m['id'] ? 'selected' : '' ?>><?= e($m['full_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="flex gap-3 mt-6 pt-4 border-t border-gray-100">
        <button type="submit" class="btn-primary"><i class="fas fa-save"></i> <?= $action === 'new' ? 'Create Zone' : 'Save' ?></button>
        <a href="<?= url('zones') ?>" class="btn-secondary"><i class="fas fa-times"></i> Cancel</a>
      </div>
    </form>
  </div>
</div>

<?php else: ?>
<div class="flex justify-between items-center mb-6">
  <h2 class="text-sm text-gray-500"><?= count($zones) ?> zone<?= count($zones) !== 1 ? 's' : '' ?></h2>
  <?php if (isSuperAdmin()): ?>
  <a href="<?= url('zones') ?>?action=new" class="btn-primary"><i class="fas fa-plus"></i> Add Zone</a>
  <?php endif; ?>
</div>

<?php if (isSuperAdmin()): ?>
<form method="POST" id="bulkDeleteForm" class="hidden">
  <?= csrf_field() ?>
  <input type="hidden" name="_act" value="bulk_delete">
</form>
<div class="flex items-center gap-3 mb-4">
  <label class="flex items-center gap-2 text-xs text-gray-500"><input type="checkbox" data-select-all> Select all</label>
  <div data-bulk-bar class="hidden items-center gap-3 px-4 py-2 bg-red-50 border border-red-100 rounded-lg text-sm text-red-700">
    <span><span data-selected-count>0</span> selected</span>
    <button type="button" onclick="confirmBulkDelete('bulkDeleteForm','zone')" class="btn-danger"><i class="fas fa-trash-alt"></i> Delete Selected</button>
  </div>
</div>
<?php endif; ?>

<div class="grid sm:grid-cols-2 xl:grid-cols-3 gap-5">
  <?php if (empty($zones)): ?>
  <div class="col-span-3 bg-white rounded-xl p-10 text-center text-gray-400 shadow-sm border border-gray-100">
    <i class="fas fa-map-marked-alt text-4xl mb-3 block"></i>
    No zones yet. <a href="<?= url('zones') ?>?action=new" class="text-indigo-600 underline">Create one</a>.
  </div>
  <?php endif; ?>
  <?php foreach ($zones as $z): ?>
  <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 hover:shadow-md transition-shadow">
    <div class="flex items-start justify-between mb-3">
      <?php if (isSuperAdmin()): ?>
      <input type="checkbox" name="ids[]" value="<?= $z['id'] ?>" form="bulkDeleteForm" data-row-check>
      <?php else: ?>
      <div class="w-10 h-10 bg-indigo-100 rounded-lg flex items-center justify-center">
        <i class="fas fa-map-marked-alt text-indigo-600"></i>
      </div>
      <?php endif; ?>
      <?php if (isSuperAdmin()): ?>
      <div class="flex gap-1">
        <a href="<?= url('zones') ?>?action=edit&id=<?= $z['id'] ?>" class="text-gray-400 hover:text-indigo-600 p-1"><i class="fas fa-edit text-sm"></i></a>
        <form method="POST" class="inline" onsubmit="return confirm('Delete this zone?')">
          <?= csrf_field() ?>
          <input type="hidden" name="_act" value="delete">
          <input type="hidden" name="zone_id" value="<?= $z['id'] ?>">
          <button type="submit" class="text-gray-400 hover:text-red-600 p-1"><i class="fas fa-trash text-sm"></i></button>
        </form>
      </div>
      <?php endif; ?>
    </div>
    <h3 class="font-semibold text-gray-800"><?= e($z['name']) ?></h3>
    <?php if ($z['description']): ?><p class="text-xs text-gray-500 mt-1"><?= e($z['description']) ?></p><?php endif; ?>
    <div class="mt-4 pt-3 border-t border-gray-50 flex items-center justify-between text-xs text-gray-500">
      <span><i class="fas fa-store mr-1"></i><?= $z['branch_count'] ?> branch<?= $z['branch_count'] !== 1 ? 'es' : '' ?></span>
      <span><?= $z['manager_name'] ? '<i class="fas fa-user mr-1"></i>' . e($z['manager_name']) : 'No manager' ?></span>
    </div>
    <a href="<?= url('branches') ?>?zone_id=<?= $z['id'] ?>" class="mt-3 text-xs text-indigo-600 hover:underline inline-block">View branches &rarr;</a>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
