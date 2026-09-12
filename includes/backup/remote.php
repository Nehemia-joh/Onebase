<?php
declare(strict_types=1);
require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/dump.php';

function ensureBackupTables(PDO $db): void
{
    $db->exec(
        'CREATE TABLE IF NOT EXISTS backup_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            success TINYINT(1) NOT NULL,
            message TEXT,
            tables_count INT DEFAULT NULL,
            rows_count INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
}

function logBackupRun(PDO $db, bool $success, string $message, ?int $tables = null, ?int $rows = null): void
{
    try {
        ensureBackupTables($db);
        $db->prepare('INSERT INTO backup_logs (success, message, tables_count, rows_count) VALUES (?,?,?,?)')
            ->execute([$success ? 1 : 0, $message, $tables, $rows]);
    } catch (PDOException) {
        // logging must never break the backup run itself
    }
}

/**
 * Dumps the local database and pushes it as one archived snapshot into a
 * remote MySQL database configured via the `settings` table. Prunes old
 * snapshots beyond the configured retention count. Never touches local data.
 *
 * @return array{success:bool, message:string}
 */
function runRemoteBackup(): array
{
    $db = getDB();

    if (getSetting('backup_remote_enabled') !== '1') {
        return ['success' => false, 'message' => 'Remote backup is not enabled.'];
    }

    $host = getSetting('backup_remote_host');
    $port = (int)(getSetting('backup_remote_port', '3306') ?: '3306');
    $name = getSetting('backup_remote_db');
    $user = getSetting('backup_remote_user');
    $pass = backupDecrypt(getSetting('backup_remote_pass'));
    $keep = max(1, (int)(getSetting('backup_remote_keep', '7') ?: '7'));

    if ($host === '' || $name === '' || $user === '') {
        $msg = 'Remote backup is not fully configured (host/database/username required).';
        logBackupRun($db, false, $msg);
        return ['success' => false, 'message' => $msg];
    }

    try {
        $result = fullDatabaseSqlDump($db);
    } catch (Throwable $e) {
        $msg = 'Local dump failed: ' . $e->getMessage();
        logBackupRun($db, false, $msg);
        return ['success' => false, 'message' => $msg];
    }

    try {
        $dsn = "mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4";
        $remote = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 15,
        ]);

        $remote->exec(
            'CREATE TABLE IF NOT EXISTS busybase_backup_archive (
                id INT AUTO_INCREMENT PRIMARY KEY,
                source_db VARCHAR(100),
                tables_count INT,
                rows_count INT,
                dump_size INT,
                sql_dump LONGTEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        $remote->prepare(
            'INSERT INTO busybase_backup_archive (source_db, tables_count, rows_count, dump_size, sql_dump) VALUES (?,?,?,?,?)'
        )->execute([
            getenv('DB_NAME') ?: 'busybase',
            $result['tables'],
            $result['rows'],
            strlen($result['sql']),
            $result['sql'],
        ]);

        // Prune to the configured retention count.
        $remote->exec(
            "DELETE FROM busybase_backup_archive WHERE id NOT IN (
                SELECT id FROM (
                    SELECT id FROM busybase_backup_archive ORDER BY created_at DESC LIMIT $keep
                ) keep_ids
            )"
        );
    } catch (Throwable $e) {
        $msg = 'Remote push failed: ' . $e->getMessage();
        logBackupRun($db, false, $msg, $result['tables'], $result['rows']);
        return ['success' => false, 'message' => $msg];
    }

    $msg = "Backed up {$result['tables']} tables, {$result['rows']} rows to remote database.";
    logBackupRun($db, true, $msg, $result['tables'], $result['rows']);
    return ['success' => true, 'message' => $msg];
}
