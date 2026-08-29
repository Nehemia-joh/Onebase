<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if (isLoggedIn()) { header('Location: ' . roleHome()); exit; }

$error   = '';
$success = '';
if (($_GET['msg']    ?? '') === 'logged_out') $success = 'You have been signed out successfully.';
if (($_GET['reason'] ?? '') === 'session')    $error   = 'Session expired. Please sign in again.';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $username = clean($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $ip       = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $rateKey  = 'login:' . $ip;

    if (!$username || !$password) {
        $error = 'Username and password are required.';
    } elseif (!checkRateLimit($rateKey, (int)(getenv('RATE_LIMIT_ATTEMPTS') ?: 5), (int)(getenv('RATE_LIMIT_WINDOW') ?: 900))) {
        $error = 'Too many failed attempts. Please wait 15 minutes.';
    } else {
        try {
            $stmt = getDB()->prepare('SELECT * FROM users WHERE (username=? OR email=?) AND is_active=1 LIMIT 1');
            $stmt->execute([$username, $username]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                clearRateLimit($rateKey);
                session_regenerate_id(true);
                $_SESSION['user_id']   = (int)$user['id'];
                $_SESSION['username']  = $user['username'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['role']      = $user['role'];
                $_SESSION['branch_id'] = $user['branch_id'] ? (int)$user['branch_id'] : null;
                $_SESSION['zone_id']   = $user['zone_id']   ? (int)$user['zone_id']   : null;
                $_SESSION['_ip']       = $ip;
                try { getDB()->prepare('UPDATE users SET last_login=NOW() WHERE id=?')->execute([$user['id']]); } catch (PDOException) {}
                logActivity('login', 'User signed in', (int)$user['id']);
                header('Location: ' . roleHome()); exit;
            } else {
                recordRateAttempt($rateKey);
                try { getDB()->prepare('INSERT INTO failed_logins (username,ip_address) VALUES (?,?)')->execute([$username,$ip]); } catch (PDOException) {}
                $error = 'Invalid username or password.';
            }
        } catch (PDOException $e) {
            error_log('Login DB error: ' . $e->getMessage());
            $error = 'The system database is not ready (tables missing or inaccessible). Please contact the administrator.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sign In &mdash; <?= e(getSetting('business_name', 'OneSystem BMS')) ?></title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.1/dist/cdn.min.js"></script>
<style>
[x-cloak]{display:none!important}
body { background:linear-gradient(135deg,#0f172a 0%,#1e1b4b 50%,#0f172a 100%); }
.orb { position:fixed; border-radius:50%; filter:blur(80px); pointer-events:none; opacity:.4; }
</style>
</head>
<body class="min-h-screen flex items-center justify-center p-4 relative overflow-hidden">

<!-- Background orbs -->
<div class="orb" style="width:400px;height:400px;background:#4f46e5;top:-100px;left:-100px"></div>
<div class="orb" style="width:300px;height:300px;background:#7c3aed;bottom:-80px;right:-80px"></div>
<div class="orb" style="width:200px;height:200px;background:#0ea5e9;top:50%;left:50%;transform:translate(-50%,-50%)"></div>

<div class="w-full max-w-4xl relative z-10">
  <div class="grid md:grid-cols-5 rounded-3xl overflow-hidden shadow-2xl" style="box-shadow:0 25px 60px rgba(0,0,0,.5)">

    <!-- Left panel (brand) -->
    <div class="md:col-span-2 hidden md:flex flex-col justify-between p-10 text-white relative overflow-hidden"
         style="background:linear-gradient(160deg,#4f46e5 0%,#7c3aed 60%,#6d28d9 100%)">
      <div class="absolute inset-0 opacity-10">
        <div style="position:absolute;width:300px;height:300px;border-radius:50%;border:1px solid rgba(255,255,255,.3);top:-80px;left:-80px"></div>
        <div style="position:absolute;width:200px;height:200px;border-radius:50%;border:1px solid rgba(255,255,255,.3);bottom:40px;right:-60px"></div>
        <div style="position:absolute;width:150px;height:150px;border-radius:50%;background:rgba(255,255,255,.05);top:40%;left:30%"></div>
      </div>
      <div class="relative z-10">
        <?php if ($bizLogo = businessLogoUrl()): ?>
        <img src="<?= e($bizLogo) ?>" alt="Logo" class="w-14 h-14 rounded-2xl object-cover mb-6 bg-white">
        <?php else: ?>
        <div class="w-14 h-14 rounded-2xl flex items-center justify-center mb-6"
             style="background:rgba(255,255,255,.2);backdrop-filter:blur(10px)">
          <i class="fas fa-layer-group text-2xl"></i>
        </div>
        <?php endif; ?>
        <h1 class="text-3xl font-black"><?= e(getSetting('business_name', 'OneSystem')) ?></h1>
        <p class="text-indigo-200 text-sm mt-1 font-medium">Business Management System</p>
      </div>
      <div class="relative z-10 space-y-3">
        <?php foreach ([
          ['fa-chart-line','Real-time Analytics'],
          ['fa-boxes','Multi-Branch Inventory'],
          ['fa-cash-register','Smart POS Sales'],
          ['fa-file-invoice','Purchase Management'],
          ['fa-shield-alt','Enterprise Security'],
        ] as [$icon,$label]): ?>
        <div class="flex items-center gap-3 text-sm text-indigo-100">
          <div class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0"
               style="background:rgba(255,255,255,.15)">
            <i class="fas <?= $icon ?> text-xs"></i>
          </div>
          <?= $label ?>
        </div>
        <?php endforeach; ?>
      </div>
      <p class="relative z-10 text-xs text-indigo-300">&copy; <?= date('Y') ?> OneSystem &mdash; All prices in TSh</p>
    </div>

    <!-- Right panel (form) -->
    <div class="md:col-span-3 bg-white flex flex-col justify-center p-8 sm:p-12">
      <div class="mb-8">
        <div class="inline-flex items-center gap-2 bg-indigo-50 text-indigo-600 text-xs font-semibold px-3 py-1.5 rounded-full mb-4">
          <div class="w-1.5 h-1.5 bg-indigo-500 rounded-full animate-pulse"></div>
          Secure Access
        </div>
        <h2 class="text-2xl font-black text-slate-900">Welcome back</h2>
        <p class="text-slate-500 text-sm mt-1">Sign in to manage your business</p>
      </div>

      <?php if ($error): ?>
      <div class="flex items-center gap-3 bg-red-50 border border-red-100 text-red-700 px-4 py-3 rounded-2xl mb-5 text-sm">
        <div class="w-8 h-8 rounded-xl bg-red-100 flex items-center justify-center flex-shrink-0">
          <i class="fas fa-exclamation-triangle text-xs"></i>
        </div>
        <?= e($error) ?>
      </div>
      <?php endif; ?>

      <?php if ($success): ?>
      <div class="flex items-center gap-3 bg-green-50 border border-green-100 text-green-700 px-4 py-3 rounded-2xl mb-5 text-sm">
        <div class="w-8 h-8 rounded-xl bg-green-100 flex items-center justify-center flex-shrink-0">
          <i class="fas fa-check-circle text-xs"></i>
        </div>
        <?= e($success) ?>
      </div>
      <?php endif; ?>

      <form method="POST" autocomplete="off" class="space-y-4" x-data="{ showPw: false }">
        <?= csrf_field() ?>

        <div>
          <label class="block text-sm font-semibold text-slate-700 mb-1.5">Username or Email</label>
          <div class="relative">
            <div class="absolute left-3 top-1/2 -translate-y-1/2 w-8 h-8 rounded-lg bg-slate-100 flex items-center justify-center">
              <i class="fas fa-user text-slate-400 text-xs"></i>
            </div>
            <input type="text" name="username" required autofocus
              value="<?= e($_POST['username'] ?? '') ?>"
              class="w-full pl-13 pr-4 py-3 border-2 border-slate-200 rounded-2xl text-sm focus:outline-none focus:border-indigo-500 transition-colors"
              style="padding-left:3.25rem"
              placeholder="Enter your username">
          </div>
        </div>

        <div>
          <label class="block text-sm font-semibold text-slate-700 mb-1.5">Password</label>
          <div class="relative">
            <div class="absolute left-3 top-1/2 -translate-y-1/2 w-8 h-8 rounded-lg bg-slate-100 flex items-center justify-center">
              <i class="fas fa-lock text-slate-400 text-xs"></i>
            </div>
            <input :type="showPw?'text':'password'" name="password" required
              class="w-full pr-12 py-3 border-2 border-slate-200 rounded-2xl text-sm focus:outline-none focus:border-indigo-500 transition-colors"
              style="padding-left:3.25rem"
              placeholder="Enter your password">
            <button type="button" @click="showPw=!showPw"
              class="absolute right-3 top-1/2 -translate-y-1/2 w-8 h-8 rounded-lg hover:bg-slate-100 flex items-center justify-center transition-colors">
              <i class="fas text-slate-400 text-xs" :class="showPw?'fa-eye-slash':'fa-eye'"></i>
            </button>
          </div>
        </div>

        <button type="submit"
          class="w-full py-3.5 rounded-2xl text-white font-bold text-sm flex items-center justify-center gap-2 mt-2 transition-all hover:opacity-90 active:scale-[.98]"
          style="background:linear-gradient(135deg,#4f46e5,#7c3aed);box-shadow:0 4px 20px rgba(79,70,229,.4)">
          <i class="fas fa-sign-in-alt"></i>
          Sign In to OneSystem
        </button>
      </form>

      <div class="mt-8 pt-6 border-t border-slate-100 flex items-center justify-between">
        <div class="flex items-center gap-2 text-xs text-slate-400">
          <i class="fas fa-shield-alt text-indigo-400"></i>
          256-bit encrypted
        </div>
        <div class="flex items-center gap-2 text-xs text-slate-400">
          <i class="fas fa-lock text-indigo-400"></i>
          Rate limited
        </div>
        <div class="flex items-center gap-2 text-xs text-slate-400">
          <i class="fas fa-eye-slash text-indigo-400"></i>
          Audit logged
        </div>
      </div>
    </div>

  </div>
</div>
</body>
</html>
