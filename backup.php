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

require_once __DIR__ . '/includes/backup/dump.php';
require_once __DIR__ . '/includes/backup/csv_export.php';
require_once __DIR__ . '/includes/backup/xlsx_export.php';
require_once __DIR__ . '/includes/backup/remote.php';

$db = getDB();
ensureBackupTables($db);
$pageTitle = 'Backup & Export';

// ── Manual exports (download, no HTML around it) ───────────────────────────────
$export = clean($_GET['export'] ?? '');
if ($export === 'sql') {
    $result = fullDatabaseSqlDump($db);
    logActivity('backup_exported', "Manual SQL export ({$result['tables']} tables, {$result['rows']} rows)");
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="busybase_backup_' . date('Y-m-d_His') . '.sql"');
    header('Content-Length: ' . strlen($result['sql']));
    echo $result['sql'];
    exit;
}
if ($export === 'csv') {
    $path = exportAllTablesAsZipCsv($db);
    logActivity('backup_exported', 'Manual CSV (zip) export');
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="busybase_backup_' . date('Y-m-d_His') . '.zip"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    @unlink($path);
    exit;
}
if ($export === 'excel') {
    $path = exportAllTablesAsXlsx($db);
    logActivity('backup_exported', 'Manual Excel export');
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="busybase_backup_' . date('Y-m-d_His') . '.xlsx"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    @unlink($path);
    exit;
}

// ── POST: remote backup settings / manual run ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $act = clean($_POST['_act'] ?? '');

    if ($act === 'save_remote') {
        saveSetting('backup_remote_host', clean($_POST['host'] ?? ''));
        saveSetting('backup_remote_port', (string)cleanInt($_POST['port'] ?? 3306) ?: '3306');
        saveSetting('backup_remote_db', clean($_POST['dbname'] ?? ''));
        saveSetting('backup_remote_user', clean($_POST['user'] ?? ''));
        saveSetting('backup_remote_keep', (string)max(1, cleanInt($_POST['keep'] ?? 7)));
        saveSetting('backup_remote_enabled', isset($_POST['enabled']) ? '1' : '0');

        $newPass = $_POST['pass'] ?? '';
        if ($newPass !== '') {
            saveSetting('backup_remote_pass', backupEncrypt($newPass));
        }
        if (!getSetting('backup_cron_token')) {
            saveSetting('backup_cron_token', bin2hex(random_bytes(24)));
        }

        logActivity('backup_settings_updated', 'Remote backup settings updated');
        flash('success', 'Remote backup settings saved.');
        header('Location: ' . url('backup')); exit;
    }

    if ($act === 'regen_token') {
        saveSetting('backup_cron_token', bin2hex(random_bytes(24)));
        flash('success', 'Cron token regenerated — update your scheduled job with the new URL.');
        header('Location: ' . url('backup')); exit;
    }

    if ($act === 'run_now') {
        $result = runRemoteBackup();
        flash($result['success'] ? 'success' : 'error', $result['message']);
        header('Location: ' . url('backup')); exit;
    }
}

if (!getSetting('backup_cron_token')) {
    saveSetting('backup_cron_token', bin2hex(random_bytes(24)));
}

$logs = $db->query('SELECT * FROM backup_logs ORDER BY created_at DESC LIMIT 20')->fetchAll();
$scheme = (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$cronUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'yourdomain.com') . '/cron_backup.php?token=' . getSetting('backup_cron_token');
$cronCli = 'php ' . __DIR__ . '/cron_backup.php';

include __DIR__ . '/includes/tailwind.php';
?>

<div class="max-w-3xl mx-auto space-y-6">

  <!-- Manual export -->
  <div class="page-card p-6">
    <h3 class="font-bold text-slate-800 mb-1"><i class="fas fa-download text-indigo-400 mr-1"></i> Manual Export</h3>
    <p class="text-xs text-slate-400 mb-5">Download the entire database right now, in the format you need.</p>
    <div class="flex flex-wrap gap-3">
      <a href="<?= url('backup') ?>?export=sql" class="btn-primary"><i class="fas fa-database"></i> SQL Dump</a>
      <a href="<?= url('backup') ?>?export=csv" class="btn-secondary"><i class="fas fa-file-csv"></i> CSV (.zip)</a>
      <a href="<?= url('backup') ?>?export=excel" class="btn-secondary"><i class="fas fa-file-excel"></i> Excel (.xlsx)</a>
    </div>
  </div>

  <!-- Remote automated backup -->
  <div class="page-card p-6">
    <h3 class="font-bold text-slate-800 mb-1"><i class="fas fa-cloud-upload-alt text-indigo-400 mr-1"></i> Automated Remote Backup</h3>
    <p class="text-xs text-slate-400 mb-5">On a schedule (cron), a full snapshot is pushed into a database you control elsewhere — kept independent of this server.</p>

    <form method="POST" class="space-y-4">
      <?= csrf_field() ?>
      <input type="hidden" name="_act" value="save_remote">

      <div class="grid sm:grid-cols-2 gap-4">
        <div>
          <label class="form-label">Remote Host</label>
          <input type="text" name="host" placeholder="e.g. db.example.com" value="<?= e(getSetting('backup_remote_host')) ?>" class="form-input">
        </div>
        <div>
          <label class="form-label">Port</label>
          <input type="number" name="port" value="<?= e(getSetting('backup_remote_port', '3306')) ?>" class="form-input">
        </div>
        <div>
          <label class="form-label">Database Name</label>
          <input type="text" name="dbname" value="<?= e(getSetting('backup_remote_db')) ?>" class="form-input">
        </div>
        <div>
          <label class="form-label">Username</label>
          <input type="text" name="user" value="<?= e(getSetting('backup_remote_user')) ?>" class="form-input">
        </div>
        <div>
          <label class="form-label">Password</label>
          <input type="password" name="pass" placeholder="<?= getSetting('backup_remote_pass') ? 'Unchanged — leave blank to keep' : '' ?>" class="form-input">
        </div>
        <div>
          <label class="form-label">Keep Last N Backups</label>
          <input type="number" min="1" name="keep" value="<?= e(getSetting('backup_remote_keep', '7')) ?>" class="form-input">
        </div>
      </div>

      <label class="flex items-center gap-2 text-sm text-slate-600">
        <input type="checkbox" name="enabled" <?= getSetting('backup_remote_enabled') === '1' ? 'checked' : '' ?>>
        Enable automated remote backup
      </label>

      <div class="flex gap-3 pt-2">
        <button type="submit" class="btn-primary"><i class="fas fa-save"></i> Save Settings</button>
      </div>
    </form>

    <form method="POST" class="inline">
      <?= csrf_field() ?>
      <input type="hidden" name="_act" value="run_now">
      <button type="submit" class="btn-success mt-4"><i class="fas fa-play"></i> Run Backup Now</button>
    </form>

    <div class="mt-6 pt-5 border-t border-gray-100">
      <p class="text-xs font-semibold text-slate-500 uppercase mb-2">Schedule this (cPanel &rarr; Cron Jobs)</p>
      <p class="text-xs text-slate-400 mb-1">Command line (preferred):</p>
      <code class="block bg-slate-50 border border-gray-100 rounded-lg p-3 text-xs text-slate-700 mb-3 overflow-x-auto"><?= e($cronCli) ?></code>
      <p class="text-xs text-slate-400 mb-1">Or, if only URL-based cron is available:</p>
      <div class="flex items-center gap-2">
        <code class="flex-1 bg-slate-50 border border-gray-100 rounded-lg p-3 text-xs text-slate-700 overflow-x-auto"><?= e($cronUrl) ?></code>
        <form method="POST">
          <?= csrf_field() ?>
          <input type="hidden" name="_act" value="regen_token">
          <button type="submit" class="btn-secondary text-xs" title="Regenerate token"><i class="fas fa-sync-alt"></i></button>
        </form>
      </div>
    </div>
  </div>

  <!-- History -->
  <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
    <div class="px-5 py-4 border-b border-gray-100">
      <h3 class="text-sm font-semibold text-gray-800">Recent Backup Runs</h3>
    </div>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b border-gray-100"><tr>
          <th class="table-th">When</th>
          <th class="table-th">Status</th>
          <th class="table-th">Details</th>
          <th class="table-th text-right">Tables</th>
          <th class="table-th text-right">Rows</th>
        </tr></thead>
        <tbody class="divide-y divide-gray-50">
          <?php if (empty($logs)): ?>
          <tr><td colspan="5" class="py-10 text-center text-gray-400">No backup runs yet</td></tr>
          <?php endif; ?>
          <?php foreach ($logs as $log): ?>
          <tr>
            <td class="table-td text-gray-500 text-xs"><?= date('d M Y H:i', strtotime($log['created_at'])) ?></td>
            <td class="table-td">
              <span class="badge <?= $log['success'] ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-600' ?>">
                <?= $log['success'] ? 'Success' : 'Failed' ?>
              </span>
            </td>
            <td class="table-td text-gray-500 text-xs"><?= e($log['message']) ?></td>
            <td class="table-td text-right"><?= $log['tables_count'] ?? '—' ?></td>
            <td class="table-td text-right"><?= $log['rows_count'] ?? '—' ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>
