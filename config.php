<?php
declare(strict_types=1);

// ── Environment ──────────────────────────────────────────────────────────────
(function () {
    $envFile = dirname(__DIR__) . '/.envmasterbusybase';
    if (!is_readable($envFile)) {
        $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
        $bypass = ['install.php', 'cron_backup.php'];
        if (PHP_SAPI === 'cli' || in_array($script, $bypass, true)) {
            return; // let install.php run to create it, or let a CLI/cron script fail on its own terms
        }
        header('Location: /install.php');
        exit;
    }
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k);
        $v = trim($v, " \t\n\r\0\x0B\"'");
        if (!array_key_exists($k, $_ENV)) {
            putenv("$k=$v");
            $_ENV[$k] = $v;
        }
    }
})();

// ── Timezone ──────────────────────────────────────────────────────────────────
date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'Africa/Dar_es_Salaam');

// ── Security Headers ─────────────────────────────────────────────────────────
if (!headers_sent()) {
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header_remove('X-Powered-By');
}

// ── Session ───────────────────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    $secure   = getenv('SESSION_SECURE') === 'true';
    $lifetime = (int)(getenv('SESSION_LIFETIME') ?: 3600);
    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.gc_maxlifetime', (string)$lifetime);
    session_start();

    // Regenerate session id after login (call this once after auth)
    // IP binding — detect session hijacking
    if (isset($_SESSION['user_id'])) {
        if (!isset($_SESSION['_ip'])) {
            $_SESSION['_ip'] = $_SERVER['REMOTE_ADDR'] ?? '';
        } elseif ($_SESSION['_ip'] !== ($_SERVER['REMOTE_ADDR'] ?? '')) {
            session_destroy();
            header('Location: /login?reason=session');
            exit;
        }
    }
}

// ── Database ──────────────────────────────────────────────────────────────────
function getDB(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $host    = getenv('DB_HOST')    ?: 'localhost';
        $dbname  = getenv('DB_NAME')    ?: 'busybase';
        $user    = getenv('DB_USER')    ?: 'root';
        $pass    = getenv('DB_PASS')    ?: '';
        $charset = getenv('DB_CHARSET') ?: 'utf8mb4';

        $dsn = "mysql:host=$host;dbname=$dbname;charset=$charset";
        try {
            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_FOUND_ROWS   => true,
            ]);
            $pdo->exec("SET time_zone = '+03:00'");
        } catch (PDOException $e) {
            error_log('DB connection failed: ' . $e->getMessage());
            http_response_code(500);
            die('Database connection error. Please contact the administrator.');
        }
    }
    return $pdo;
}

// ── CSRF ──────────────────────────────────────────────────────────────────────
function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . csrf_token() . '">';
}

function csrf_verify(): void
{
    $token = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!hash_equals(csrf_token(), $token)) {
        http_response_code(403);
        if (isAjax()) {
            json_out(['error' => 'Invalid security token. Please refresh and try again.'], 403);
        }
        die('Invalid security token. <a href="javascript:history.back()">Go back</a>');
    }
}

// ── Input Helpers ─────────────────────────────────────────────────────────────
function e(mixed $v): string  { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function clean(mixed $v): string { return trim(strip_tags((string)$v)); }
function cleanInt(mixed $v): int   { return (int)filter_var($v, FILTER_SANITIZE_NUMBER_INT); }
function cleanFloat(mixed $v): float
{
    return (float)filter_var(str_replace(',', '', (string)$v), FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
}

// ── Auth ───────────────────────────────────────────────────────────────────────
function isLoggedIn(): bool
{
    return isset($_SESSION['user_id'], $_SESSION['_ip'])
        && $_SESSION['_ip'] === ($_SERVER['REMOTE_ADDR'] ?? '');
}

/**
 * Role-scoped URL: every role works inside its own directory.
 * url('sales?view=3') for a cashier => /cashier/sales?view=3
 */
function url(string $path = ''): string
{
    $path = ltrim($path, '/');
    $role = $_SESSION['role'] ?? '';
    return ($role !== '' ? "/$role/" : '/') . $path;
}

function roleHome(): string
{
    return url('dashboard');
}

function requireLogin(): void
{
    if (!isLoggedIn()) {
        header('Location: /login');
        exit;
    }
}

function currentUser(): array
{
    return [
        'id'       => $_SESSION['user_id']   ?? 0,
        'name'     => $_SESSION['full_name'] ?? '',
        'username' => $_SESSION['username']  ?? '',
        'role'     => $_SESSION['role']      ?? '',
        'branch_id'=> $_SESSION['branch_id'] ?? null,
        'zone_id'  => $_SESSION['zone_id']   ?? null,
    ];
}

function hasRole(string ...$roles): bool
{
    return in_array($_SESSION['role'] ?? '', $roles, true);
}

function requireRole(string ...$roles): void
{
    requireLogin();
    if (!hasRole(...$roles)) {
        http_response_code(403);
        if (isAjax()) {
            json_out(['error' => 'Access denied.'], 403);
        }
        die('Access denied. <a href="' . roleHome() . '">Go to Dashboard</a>');
    }
}

function isSuperAdmin(): bool { return hasRole('super_admin'); }
function canManageUsers(): bool { return hasRole('super_admin'); }
function canManageBranches(): bool { return hasRole('super_admin','zone_manager'); }
function canManageStock(): bool { return hasRole('super_admin','zone_manager','branch_manager','stock_controller'); }
function canViewReports(): bool { return hasRole('super_admin','zone_manager','branch_manager'); }

// ── Rate Limiting ─────────────────────────────────────────────────────────────
function checkRateLimit(string $key, int $max = 5, int $window = 900): bool
{
    try {
        $db   = getDB();
        $stmt = $db->prepare(
            'SELECT attempts FROM rate_limits
             WHERE key_name = ? AND last_attempt > DATE_SUB(NOW(), INTERVAL ? SECOND)'
        );
        $stmt->execute([$key, $window]);
        $row = $stmt->fetchColumn();
        return ($row === false || (int)$row < $max);
    } catch (PDOException) {
        return true;
    }
}

function recordRateAttempt(string $key): void
{
    try {
        getDB()->prepare(
            'INSERT INTO rate_limits (key_name, attempts, last_attempt) VALUES (?, 1, NOW())
             ON DUPLICATE KEY UPDATE attempts = attempts + 1, last_attempt = NOW()'
        )->execute([$key]);
    } catch (PDOException) {}
}

function clearRateLimit(string $key): void
{
    try {
        getDB()->prepare('DELETE FROM rate_limits WHERE key_name = ?')->execute([$key]);
    } catch (PDOException) {}
}

// ── Activity Logging ──────────────────────────────────────────────────────────
function logActivity(string $action, string $description = '', ?int $userId = null): void
{
    try {
        $uid = $userId ?? ($_SESSION['user_id'] ?? null);
        $ip  = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        getDB()->prepare(
            'INSERT INTO activity_logs (user_id, action, description, ip_address, created_at)
             VALUES (?, ?, ?, ?, NOW())'
        )->execute([$uid, $action, $description, $ip]);
    } catch (PDOException) {}
}

// ── Flash Messages ────────────────────────────────────────────────────────────
function flash(string $type, string $message): void
{
    $_SESSION['_flash'] = compact('type', 'message');
}

function getFlash(): ?array
{
    $f = $_SESSION['_flash'] ?? null;
    unset($_SESSION['_flash']);
    return $f;
}

function renderFlash(): string
{
    $f = getFlash();
    if (!$f) return '';
    $colors = [
        'success' => 'bg-green-100 border-green-400 text-green-800',
        'error'   => 'bg-red-100 border-red-400 text-red-800',
        'warning' => 'bg-yellow-100 border-yellow-400 text-yellow-800',
        'info'    => 'bg-blue-100 border-blue-400 text-blue-800',
    ];
    $cls = $colors[$f['type']] ?? $colors['info'];
    return '<div class="' . $cls . ' border px-4 py-3 rounded-lg mb-4 flex items-center gap-2" role="alert">
        <span>' . e($f['message']) . '</span></div>';
}

// ── Currency ──────────────────────────────────────────────────────────────────
function formatMoney(float $amount): string
{
    $dec = (int)(getenv('CURRENCY_DECIMALS') ?: 0);
    $sym = getenv('CURRENCY_SYMBOL') ?: 'TSh';
    return $sym . ' ' . number_format($amount, $dec);
}

// ── JSON Response ─────────────────────────────────────────────────────────────
function json_out(mixed $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function isAjax(): bool
{
    return ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest'
        || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
}

// ── Business Settings ─────────────────────────────────────────────────────────
function getSettings(): array
{
    static $settings = null;
    if ($settings === null) {
        $settings = [
            'business_name'    => 'OneSystem BMS',
            'business_address' => '',
            'business_phone'   => '',
            'business_email'   => '',
            'business_tin'     => '',
            'business_vrn'     => '',
            'receipt_footer'   => 'Thank you for your business!',
            'vat_enabled'      => '0',
            'vat_rate'         => '18',
            'vat_mode'         => 'inclusive',
        ];
        try {
            foreach (getDB()->query('SELECT key_name, value FROM settings')->fetchAll() as $row) {
                $settings[$row['key_name']] = $row['value'];
            }
        } catch (PDOException) {}
    }
    return $settings;
}

function getSetting(string $key, string $default = ''): string
{
    return getSettings()[$key] ?? $default;
}

function saveSetting(string $key, string $value): void
{
    getDB()->prepare(
        'INSERT INTO settings (key_name, value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE value = VALUES(value)'
    )->execute([$key, $value]);
}

/** Web path of the uploaded business logo (cache-busted), or null if none. */
function businessLogoUrl(): ?string
{
    $rel = getSetting('business_logo');
    if ($rel === '') return null;
    $abs = __DIR__ . '/' . ltrim($rel, '/');
    return is_file($abs) ? '/' . ltrim($rel, '/') . '?v=' . filemtime($abs) : null;
}

// ── VAT ───────────────────────────────────────────────────────────────────────
function vatEnabled(): bool  { return getSetting('vat_enabled') === '1'; }
function vatRate(): float    { return (float)getSetting('vat_rate', '18'); }
function vatMode(): string   { return getSetting('vat_mode', 'inclusive') === 'exclusive' ? 'exclusive' : 'inclusive'; }

/** Returns [tax, total] for a taxable base (subtotal - discount). */
function computeVat(float $base): array
{
    $base = max(0.0, $base);
    if (!vatEnabled() || vatRate() <= 0) return [0.0, $base];
    $rate = vatRate();
    if (vatMode() === 'exclusive') {
        $tax = round($base * $rate / 100, 2);
        return [$tax, $base + $tax];
    }
    // inclusive: total unchanged, tax carved out
    return [round($base * $rate / (100 + $rate), 2), $base];
}

// ── SKU Generator ─────────────────────────────────────────────────────────────
function generateSku(): string
{
    // MAX(id) never reuses deleted ids, unlike COUNT(*)
    $next = (int)getDB()->query('SELECT COALESCE(MAX(id),0)+1 FROM products')->fetchColumn();
    return 'PRD-' . str_pad((string)$next, 5, '0', STR_PAD_LEFT);
}

/**
 * Race-safe document numbering: insert with a unique placeholder,
 * then call this to stamp the real number based on the row id.
 */
function stampDocumentNo(string $table, string $column, string $prefix, int $id, int $pad = 5): string
{
    $no = $prefix . '-' . date('Ymd') . '-' . str_pad((string)$id, $pad, '0', STR_PAD_LEFT);
    getDB()->prepare("UPDATE `$table` SET `$column` = ? WHERE id = ?")->execute([$no, $id]);
    return $no;
}

// ── Branch Scope ──────────────────────────────────────────────────────────────
function userBranchId(): ?int
{
    if (isSuperAdmin() || hasRole('zone_manager')) return null;
    return $_SESSION['branch_id'] ?? null;
}

function branchWhere(string $alias = ''): string
{
    $col = $alias ? "$alias.branch_id" : 'branch_id';
    $bid = userBranchId();
    return $bid !== null ? "$col = $bid" : '1=1';
}
