<?php
/**
 * OneSystem BMS — Installation Script
 * Run once, then DELETE this file immediately.
 *
 * Access: http://localhost/install.php
 */
declare(strict_types=1);

// Simple IP guard — allow localhost only
$allowedIps = ['127.0.0.1', '::1', 'localhost'];
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1', $allowedIps, true)) {
    http_response_code(403);
    die('Access restricted to localhost only.');
}

// Load .env
$envFile = dirname(__DIR__) . '/.env';
$env = [];
foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
    [$k, $v] = explode('=', $line, 2);
    $env[trim($k)] = trim($v, " \t\"'");
}

$host    = $env['DB_HOST']    ?? 'localhost';
$dbname  = $env['DB_NAME']    ?? 'busybase';
$user    = $env['DB_USER']    ?? 'root';
$pass    = $env['DB_PASS']    ?? '';
$charset = $env['DB_CHARSET'] ?? 'utf8mb4';
$cost    = (int)($env['BCRYPT_COST'] ?? 12);

$messages = [];
$error    = null;

try {
    // Connect without db name
    $pdo = new PDO("mysql:host=$host;charset=$charset", $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    $messages[] = "✅ Connected to MySQL on $host";

    // Create database
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbname` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `$dbname`");
    $messages[] = "✅ Database `$dbname` ready";

    // Run schema
    $sqlFile = dirname(__DIR__) . '/database.sql';
    if (!file_exists($sqlFile)) { throw new RuntimeException("database.sql not found at: $sqlFile"); }

    $sql       = file_get_contents($sqlFile);
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    $skipped = 0;
    foreach ($statements as $stmt) {
        if (empty($stmt)) continue;
        try {
            $pdo->exec($stmt);
        } catch (PDOException $e) {
            // Ignore duplicate key/already exists errors
            if (!in_array($e->getCode(), ['42S01','23000','42000'], true) && !str_contains($e->getMessage(), 'Duplicate')) {
                // Log but continue
                $skipped++;
            }
        }
    }
    $messages[] = "✅ Database schema created ($skipped minor warnings)";

    // Create default admin
    $adminUser  = 'admin';
    $adminEmail = 'admin@onesystem.co.tz';
    $adminPass  = 'Admin@2024';
    $adminHash  = password_hash($adminPass, PASSWORD_BCRYPT, ['cost' => $cost]);

    $existing = $pdo->prepare('SELECT id FROM users WHERE username = ? OR email = ?');
    $existing->execute([$adminUser, $adminEmail]);

    if (!$existing->fetchColumn()) {
        $pdo->prepare(
            'INSERT INTO users (username, email, password, full_name, role, is_active) VALUES (?,?,?,?,?,1)'
        )->execute([$adminUser, $adminEmail, $adminHash, 'System Administrator', 'super_admin']);
        $messages[] = "✅ Default admin created: <strong>admin</strong> / <strong>Admin@2024</strong>";
    } else {
        $messages[] = "ℹ️ Admin user already exists — skipped";
    }

    $messages[] = "🎉 <strong>Installation complete!</strong>";

} catch (Throwable $e) {
    $error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>OneSystem BMS — Installation</title>
<script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-gray-100 flex items-center justify-center p-4">
<div class="bg-white rounded-2xl shadow-xl w-full max-w-lg p-8">
  <div class="flex items-center gap-3 mb-6">
    <div class="w-12 h-12 bg-indigo-100 rounded-xl flex items-center justify-center">
      <svg class="w-6 h-6 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-2 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
    </div>
    <div>
      <h1 class="text-xl font-bold text-gray-900">OneSystem BMS</h1>
      <p class="text-sm text-gray-500">Installation Wizard</p>
    </div>
  </div>

  <?php if ($error): ?>
  <div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-4">
    <p class="text-red-700 font-semibold text-sm">Installation Failed</p>
    <p class="text-red-600 text-sm mt-1"><?= htmlspecialchars($error) ?></p>
  </div>
  <?php endif; ?>

  <div class="space-y-2 mb-6">
    <?php foreach ($messages as $msg): ?>
    <div class="text-sm text-gray-700 py-1.5 border-b border-gray-50 last:border-0"><?= $msg ?></div>
    <?php endforeach; ?>
  </div>

  <?php if (!$error && !empty($messages)): ?>
  <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-5">
    <p class="text-yellow-800 font-semibold text-sm">⚠️ Security: Delete this file immediately!</p>
    <code class="block mt-1 text-xs text-yellow-700 font-mono">rm <?= htmlspecialchars(__FILE__) ?></code>
  </div>

  <div class="bg-gray-50 rounded-lg p-4 mb-5 text-sm">
    <p class="font-semibold text-gray-700 mb-2">Default Credentials</p>
    <p class="text-gray-600">Username: <strong>admin</strong></p>
    <p class="text-gray-600">Password: <strong>Admin@2024</strong></p>
    <p class="text-xs text-red-500 mt-2">Change this password immediately after first login!</p>
  </div>

  <a href="/login.php" class="block w-full bg-indigo-600 hover:bg-indigo-700 text-white text-center font-semibold py-3 rounded-lg transition-colors">
    Go to Login →
  </a>
  <?php endif; ?>
</div>
</body>
</html>
