<?php
declare(strict_types=1);
/**
 * Standalone entry point for scheduled remote backups — invoke via cPanel
 * Cron Jobs, either directly (`php cron_backup.php`) or, if only URL-based
 * cron is available, as a GET request with the token shown on the Backup
 * & Export page. Not part of the logged-in app: no session, no page chrome.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/backup/remote.php';

$isCli = PHP_SAPI === 'cli';

if (!$isCli) {
    $token = $_GET['token'] ?? '';
    $expected = getSetting('backup_cron_token');
    if ($token === '' || $expected === '' || !hash_equals($expected, $token)) {
        http_response_code(403);
        header('Content-Type: text/plain');
        echo "Forbidden\n";
        exit;
    }
}

$result = runRemoteBackup();

if ($isCli) {
    fwrite(STDOUT, ($result['success'] ? '[OK] ' : '[FAIL] ') . $result['message'] . "\n");
    exit($result['success'] ? 0 : 1);
}

header('Content-Type: text/plain');
http_response_code($result['success'] ? 200 : 500);
echo ($result['success'] ? 'OK: ' : 'FAIL: ') . $result['message'] . "\n";
