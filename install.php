<?php
/**
 * Busybase — First-Run Setup Wizard.
 * Self-guarding: refuses to run again once a working install is detected,
 * so it's safe to leave on the server (though deleting it afterward is
 * still fine extra hardening).
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/schema.php';

$envFile = dirname(__DIR__) . '/.envmasterbusybase';

/** Tries to read DB_* creds from an existing env file and connect + confirm a real install. */
function busybaseAlreadyInstalled(string $envFile): bool
{
    if (!is_readable($envFile)) return false;
    $env = [];
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $env[trim($k)] = trim($v, " \t\n\r\0\x0B\"'");
    }
    try {
        $pdo = new PDO(
            'mysql:host=' . ($env['DB_HOST'] ?? 'localhost') . ';dbname=' . ($env['DB_NAME'] ?? '') . ';charset=utf8mb4',
            $env['DB_USER'] ?? 'root',
            $env['DB_PASS'] ?? '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
        );
        return (bool)$pdo->query("SELECT id FROM users WHERE role='super_admin' LIMIT 1")->fetchColumn();
    } catch (Throwable) {
        return false;
    }
}

$alreadyInstalled = busybaseAlreadyInstalled($envFile);
$messages = [];
$errors   = [];
$success  = false;

if (!$alreadyInstalled && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $dbHost  = trim($_POST['db_host'] ?? 'localhost');
    $dbName  = trim($_POST['db_name'] ?? '');
    $dbUser  = trim($_POST['db_user'] ?? '');
    $dbPass  = (string)($_POST['db_pass'] ?? '');

    $bizName = trim($_POST['business_name'] ?? '');

    $adminName  = trim($_POST['admin_name'] ?? '');
    $adminUser  = trim($_POST['admin_username'] ?? '');
    $adminEmail = trim($_POST['admin_email'] ?? '');
    $adminPass  = (string)($_POST['admin_password'] ?? '');
    $adminPass2 = (string)($_POST['admin_password_confirm'] ?? '');

    if ($dbName === '' || $dbUser === '') $errors[] = 'Database name and username are required.';
    if ($bizName === '') $errors[] = 'Business name is required.';
    if ($adminName === '' || $adminUser === '' || !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'A valid admin name, username, and email are required.';
    }
    if (strlen($adminPass) < 8) $errors[] = 'Admin password must be at least 8 characters.';
    if ($adminPass !== $adminPass2) $errors[] = 'Admin password confirmation does not match.';

    $pdo = null;
    if (!$errors) {
        try {
            $pdo = new PDO("mysql:host=$dbHost;charset=utf8mb4", $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE          => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $messages[] = "Connected to MySQL on \"$dbHost\".";
        } catch (Throwable $e) {
            $errors[] = 'Could not connect to MySQL: ' . $e->getMessage();
        }
    }

    if ($pdo && !$errors) {
        try {
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `$dbName`");
            $messages[] = "Database \"$dbName\" ready.";

            $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
            foreach (busybaseSchema() as $table => $createSql) {
                $pdo->exec($createSql);
            }
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
            $messages[] = 'Schema created (' . count(busybaseSchema()) . ' tables).';

            if (!$pdo->query('SELECT id FROM currency_settings LIMIT 1')->fetchColumn()) {
                $pdo->exec(
                    "INSERT INTO currency_settings (currency_code, currency_symbol, currency_name, decimal_places, thousands_separator, decimal_separator, symbol_position)
                     VALUES ('TSh','TSh','Tanzanian Shilling',0,',','.','before')"
                );
            }

            $settingsDefaults = [
                'business_name'  => $bizName,
                'receipt_footer' => 'Thank you for your business!',
                'vat_enabled'    => '0',
                'vat_rate'       => '18',
                'vat_mode'       => 'inclusive',
            ];
            $upsert = $pdo->prepare('INSERT INTO settings (key_name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)');
            foreach ($settingsDefaults as $k => $v) $upsert->execute([$k, $v]);
            $messages[] = 'Business settings initialized.';

            $existingAdmin = $pdo->prepare('SELECT id FROM users WHERE username = ? OR email = ?');
            $existingAdmin->execute([$adminUser, $adminEmail]);
            if (!$existingAdmin->fetchColumn()) {
                $hash = password_hash($adminPass, PASSWORD_BCRYPT, ['cost' => 12]);
                $pdo->prepare(
                    'INSERT INTO users (username, email, password, full_name, role, is_active) VALUES (?,?,?,?,?,1)'
                )->execute([$adminUser, $adminEmail, $hash, $adminName, 'super_admin']);
                $messages[] = "Admin account \"$adminUser\" created.";
            } else {
                $messages[] = "A user named \"$adminUser\" already existed — left untouched.";
            }

            $secureFlag = (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off') ? 'true' : 'false';
            $now        = date('Y-m-d H:i:s');
            $backupKey  = bin2hex(random_bytes(32));
            $envContents = <<<ENV
            # Generated by install.php on $now
            DB_HOST=$dbHost
            DB_NAME=$dbName
            DB_USER=$dbUser
            DB_PASS=$dbPass
            DB_CHARSET=utf8mb4
            APP_TIMEZONE=Africa/Dar_es_Salaam
            SESSION_LIFETIME=3600
            SESSION_SECURE=$secureFlag
            RATE_LIMIT_ATTEMPTS=5
            RATE_LIMIT_WINDOW=900
            BCRYPT_COST=12
            BACKUP_ENC_KEY=$backupKey

            ENV;

            if (@file_put_contents($envFile, $envContents) === false) {
                $errors[] = "Could not write the environment file to: $envFile (check folder permissions).";
            } else {
                @chmod($envFile, 0600);
                $messages[] = 'Environment file written.';
                $success = true;
            }
        } catch (Throwable $e) {
            $errors[] = 'Setup failed: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Busybase — Setup</title>
<script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-gray-100 flex items-center justify-center p-4">
<div class="bg-white rounded-2xl shadow-xl w-full max-w-lg p-8 my-8">
  <div class="flex items-center gap-3 mb-6">
    <div class="w-12 h-12 bg-indigo-100 rounded-xl flex items-center justify-center">
      <i class="fas fa-boxes text-indigo-600 text-xl"></i>
    </div>
    <div>
      <h1 class="text-xl font-bold text-gray-900">Busybase</h1>
      <p class="text-sm text-gray-500">First-Run Setup</p>
    </div>
  </div>

  <?php if ($alreadyInstalled): ?>
    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-5">
      <p class="text-blue-800 font-semibold text-sm">Already installed</p>
      <p class="text-blue-700 text-sm mt-1">A working database and admin account already exist. Setup can't be re-run from here — if you need to reconfigure, edit the environment file directly.</p>
    </div>
    <a href="/login" class="block w-full bg-indigo-600 hover:bg-indigo-700 text-white text-center font-semibold py-3 rounded-lg transition-colors">Go to Login &rarr;</a>

  <?php elseif ($success): ?>
    <div class="space-y-2 mb-5">
      <?php foreach ($messages as $msg): ?>
      <div class="flex items-start gap-2 text-sm text-gray-700 py-1"><i class="fas fa-check-circle text-green-500 mt-0.5"></i><span><?= htmlspecialchars($msg) ?></span></div>
      <?php endforeach; ?>
    </div>
    <div class="bg-green-50 border border-green-200 rounded-lg p-4 mb-5">
      <p class="text-green-800 font-semibold text-sm">🎉 Setup complete!</p>
      <p class="text-green-700 text-sm mt-1">Sign in with the admin account you just created.</p>
    </div>
    <a href="/login" class="block w-full bg-indigo-600 hover:bg-indigo-700 text-white text-center font-semibold py-3 rounded-lg transition-colors">Go to Login &rarr;</a>

  <?php else: ?>
    <?php if ($errors): ?>
    <div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-5">
      <p class="text-red-700 font-semibold text-sm mb-1">Setup failed</p>
      <?php foreach ($errors as $e): ?><p class="text-red-600 text-sm"><?= htmlspecialchars($e) ?></p><?php endforeach; ?>
    </div>
    <?php endif; ?>

    <form method="POST" class="space-y-5">
      <div>
        <h3 class="text-xs font-bold text-gray-500 uppercase tracking-wide mb-2">Database</h3>
        <div class="space-y-2">
          <input type="text" name="db_host" placeholder="DB Host" value="<?= htmlspecialchars($_POST['db_host'] ?? 'localhost') ?>" class="w-full border rounded-lg px-3 py-2 text-sm" required>
          <input type="text" name="db_name" placeholder="DB Name" value="<?= htmlspecialchars($_POST['db_name'] ?? '') ?>" class="w-full border rounded-lg px-3 py-2 text-sm" required>
          <input type="text" name="db_user" placeholder="DB Username" value="<?= htmlspecialchars($_POST['db_user'] ?? '') ?>" class="w-full border rounded-lg px-3 py-2 text-sm" required>
          <input type="password" name="db_pass" placeholder="DB Password" value="<?= htmlspecialchars($_POST['db_pass'] ?? '') ?>" class="w-full border rounded-lg px-3 py-2 text-sm">
        </div>
      </div>

      <div>
        <h3 class="text-xs font-bold text-gray-500 uppercase tracking-wide mb-2">Business</h3>
        <input type="text" name="business_name" placeholder="Business Name" value="<?= htmlspecialchars($_POST['business_name'] ?? '') ?>" class="w-full border rounded-lg px-3 py-2 text-sm" required>
      </div>

      <div>
        <h3 class="text-xs font-bold text-gray-500 uppercase tracking-wide mb-2">Admin Account</h3>
        <div class="space-y-2">
          <input type="text" name="admin_name" placeholder="Full Name" value="<?= htmlspecialchars($_POST['admin_name'] ?? '') ?>" class="w-full border rounded-lg px-3 py-2 text-sm" required>
          <input type="text" name="admin_username" placeholder="Username" value="<?= htmlspecialchars($_POST['admin_username'] ?? '') ?>" class="w-full border rounded-lg px-3 py-2 text-sm" required>
          <input type="email" name="admin_email" placeholder="Email" value="<?= htmlspecialchars($_POST['admin_email'] ?? '') ?>" class="w-full border rounded-lg px-3 py-2 text-sm" required>
          <input type="password" name="admin_password" placeholder="Password (min 8 characters)" class="w-full border rounded-lg px-3 py-2 text-sm" required>
          <input type="password" name="admin_password_confirm" placeholder="Confirm Password" class="w-full border rounded-lg px-3 py-2 text-sm" required>
        </div>
      </div>

      <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-semibold py-3 rounded-lg transition-colors">Set Up Busybase</button>
    </form>
  <?php endif; ?>
</div>
</body>
</html>
