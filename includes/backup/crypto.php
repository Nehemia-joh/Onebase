<?php
declare(strict_types=1);

/**
 * At-rest encryption for the remote-backup DB password, stored in the
 * `settings` table. Key is derived from an app secret that lives outside
 * the database (env), so a leaked DB dump alone doesn't expose it.
 */
function backupEncryptionKey(): string
{
    $secret = getenv('BACKUP_ENC_KEY') ?: getenv('DB_PASS') ?: 'busybase-default-key';
    return hash('sha256', $secret, true);
}

function backupEncrypt(string $plain): string
{
    if ($plain === '') return '';
    $iv = random_bytes(16);
    $cipher = openssl_encrypt($plain, 'aes-256-cbc', backupEncryptionKey(), OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . $cipher);
}

function backupDecrypt(string $encoded): string
{
    if ($encoded === '') return '';
    $raw = base64_decode($encoded, true);
    if ($raw === false || strlen($raw) < 17) return '';
    $iv     = substr($raw, 0, 16);
    $cipher = substr($raw, 16);
    $plain  = openssl_decrypt($cipher, 'aes-256-cbc', backupEncryptionKey(), OPENSSL_RAW_DATA, $iv);
    return $plain !== false ? $plain : '';
}
